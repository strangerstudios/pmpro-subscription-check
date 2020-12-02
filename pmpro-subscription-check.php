<?php
/*
Plugin Name: Paid Memberships Pro - Subscription Check Add On
Plugin URI: https://www.paidmembershipspro.com/add-ons/subscription-check/
Description: Checks whether PayPal/Stripe/Authorize.net subscriptions for PMPro members are still active.
Version: .2
Author: Paid Memberships Pro
Author URI: https://www.paidmembershipspro.com/
*/

use Stripe\Invoice as Stripe_Invoice;

/*
Add the Subscription Check admin page
*/
function pmproppsc_admin_menu() {
		if ( ! defined( 'PMPRO_VERSION' ) ) {
			return;
		}
		if( version_compare( PMPRO_VERSION, '2.0' ) >= 0 ) {
			$parent_page = 'pmpro-dashboard';
		} else {
			$parent_page = 'pmpro-membershiplevels';
		}
    add_submenu_page( $parent_page, __('Subscription Check', 'pmproppsc'), __('Subscription Check', 'pmproppsc'), 'manage_options', 'pmproppsc', 'pmproppsc_admin_page');
}
add_action('admin_menu', 'pmproppsc_admin_menu', 15);

/*
Content of the Subscription Check admin page
*/
function pmproppsc_admin_page()
{
	require_once(PMPRO_DIR . "/adminpages/admin_header.php");
?>
<div class="wrap">
<h2>Subscription Check</h2>
<?php
	if(!empty($_REQUEST['pmpro_subscription_check']) && (current_user_can("manage_options")))
	{
		global $wpdb;

		//get members
		if(!empty($_REQUEST['limit']))
			$limit = intval($_REQUEST['limit']);
		else
			$limit = 20;
		if(!empty($_REQUEST['start']))
			$start = intval($_REQUEST['start']);
		else
			$start = 0;

		//when using auto refresh get the last user id
		if(!empty($_REQUEST['autorefresh']))
			$last_user_id = get_option("pmpro_subscription_check_last_user_id", 0);
		else
			$last_user_id = 0;		
		
		//get gateway
		$gateway = sanitize_text_field( $_REQUEST['gateway'] );
		if(!in_array($gateway, array_keys(pmpro_gateways())))
			wp_die('Invalid gateway selection.');
				
		$sqlQuery = "SELECT DISTINCT(user_id) FROM $wpdb->pmpro_membership_orders WHERE gateway = '" . esc_sql($gateway) . "' ";
		if(!empty($last_user_id))
			$sqlQuery .= "AND user_id > '" . $last_user_id . "' ";
		$sqlQuery .= "ORDER BY user_id LIMIT $start, $limit";
				
		$user_ids = $wpdb->get_col($sqlQuery);
		$totalrows = $wpdb->get_var("SELECT COUNT(DISTINCT(user_id)) FROM $wpdb->pmpro_membership_orders WHERE gateway = '" . esc_sql($gateway) . "'");
		
		if(empty($_REQUEST['autorefresh']))
		{
			echo "<p>";
			echo "Checking users for orders " . $start . " to " . min(intval($start + $limit), $totalrows) . ". ";			
			if($totalrows > intval($start+$limit))
			{
				$url = "?page=pmproppsc&pmpro_subscription_check=1&start=" . ($start+$limit) . "&limit=" . $limit . "&gateway=" . $gateway;
				echo '<a class="button button-primary" href="' . $url . '">Next ' . $limit . ' Results</a>';
			}
			else
				echo "All done.";
			echo "</p>";

			echo "<hr /><p>";
		}

		$allmembers = "";
		$requiresaction = "";

		foreach($user_ids as $user_id)
		{
			$user = get_userdata($user_id);			
			
			if(empty($user))
				$s = "User #" . $user_id . " has been deleted. ";
			else			
				$s = "User <a href='" . get_edit_user_link($user->ID) . "'>#" . $user->ID . "</a> (" . $user->user_email . ")";
			
			$level = pmpro_getMembershipLevelForUser($user_id);
			if(!empty($level) && empty($user))
			{
				$s .= " Had Membership Level: " . $level->name . " (ID: " . $level->id . "). ";
				$level_id = $level->id;
			}
			elseif(!empty($level))
			{
				$s .= " has Membership Level: " . $level->name . " (ID: " . $level->id . "). ";
				$level_id = $level->id;
			}
			else
			{
				$s .= " Does not have a level. ";
				$level_id = "";
			}
			
			$order = new MemberOrder();
			$order->getLastMemberOrder($user_id, "");
			
			if( ! empty( $order ) && ! empty( $order->id ) ) {
				$s .= "<br />- Last order was <a href='admin.php?page=pmpro-orders&order=" . $order->id . "'>#" . $order->id . "</a>.";
				if(!empty($order->status)) {
					$s .= " Order status: " . $order->status . ". ";
				}

				// Check for missing recurring orders.
				if ( ! empty( $order->subscription_transaction_id ) ) {
					// Get the first order too.
					$first_order = $order->get_original_subscription_order();
					if ( ! empty( $first_order ) ) {
						$s .= "<br />- First order in this subscription was <a href='admin.php?page=pmpro-orders&order=" . $first_order->id . "'>#" . $first_order->id . "</a>.";
					}
					
					// Get the # of orders for this subscription.
					$sub_orders = $wpdb->get_col( "SELECT payment_transaction_id FROM $wpdb->pmpro_membership_orders WHERE subscription_transaction_id = '" . esc_sql( $first_order->subscription_transaction_id ) . "'" );
					$num_orders = count( $sub_orders );
					if ( $num_orders == 1 ) {
						$s .= "<br />- There is 1 order in the local DB for this subscription.";
					} else {
						$s .= "<br />- There are " . esc_html( $num_orders ) . " orders in the local DB for this subscription.";
					}
					
					// Guess how many orders you would expect.
					$now = current_time( 'timestamp' );
					$days = floor( ( $now - $first_order->timestamp ) / 86400 );
					
					if ( $level->cycle_period == 'Day' ) {
						$pnum_orders = 1 + $days;
					} elseif ( $level->cycle_period == 'Week' ) {
						$pnum_orders = 1 + floor( $days / 7 );
					} elseif ( $level->cycle_period == 'Month' ) {
						$pnum_orders = 1 + floor( $days / 30 );
					} elseif ( $level->cycle_period == 'Year' ) {
						$pnum_orders = 1 + floor( $days / 365 );
					}
					
					// If orders are missing, try to sync them from the gateway.
					if ( $pnum_orders == $num_orders ) {
						$s .= " Just like we'd expect. Good.";
					} else {
						$s .= " <span style='color: red;'><strong>But we're expecting " . esc_html( $pnum_orders ) . "</span>.";

						if ( $first_order->gateway == 'stripe' ) {
							$stripe = new PMProGateway_stripe();
							$stripe->getCustomer( $first_order );							
							$invoices = Stripe_Invoice::all(['customer' => $stripe->customer->id]);					
							foreach( $invoices as $invoice ) {								
								// not this sub
								if ( $invoice->subscription != $first_order->subscription_transaction_id ) {
									continue;
								}
								
								// no charge
								if ( empty( $invoice->charge ) ) {
									continue;
								}
								
								// already local
								if ( in_array( $invoice->charge, $sub_orders ) || in_array( $invoice->id, $sub_orders ) ) {
									continue;
								}
								
								// new orders!
								$s .= "<br />- We don't have this order (" . esc_html( $invoice->charge ) . ") yet.";
								
								// create the order if not testing
								if( ! empty( $_REQUEST['mode'] ) ) {
									//alright. create a new order/invoice
									$morder = new MemberOrder();
									$morder->user_id = $first_order->user_id;
									$morder->membership_id = $first_order->membership_id;
									$morder->timestamp = $invoice->created;
									
									global $pmpro_currency;
									global $pmpro_currencies;
									
									$currency_unit_multiplier = 100; // 100 cents / USD

									//account for zero-decimal currencies like the Japanese Yen
									if(is_array($pmpro_currencies[$pmpro_currency]) && isset($pmpro_currencies[$pmpro_currency]['decimals']) && $pmpro_currencies[$pmpro_currency]['decimals'] == 0)
										$currency_unit_multiplier = 1;
									
									if(isset($invoice->amount))
									{
										$morder->subtotal = $invoice->amount / $currency_unit_multiplier;
										$morder->tax = 0;
									}
									elseif(isset($invoice->subtotal))
									{
										$morder->subtotal = (! empty( $invoice->subtotal ) ? $invoice->subtotal / $currency_unit_multiplier : 0);
										$morder->tax = (! empty($invoice->tax) ? $invoice->tax / $currency_unit_multiplier : 0);
										$morder->total = (! empty($invoice->total) ? $invoice->total / $currency_unit_multiplier : 0);
									}

									$morder->payment_transaction_id = $invoice->id;
									$morder->subscription_transaction_id = $invoice->subscription;

									$morder->gateway = $first_order->gateway;
									$morder->gateway_environment = $first_order->gateway_environment;

									$morder->FirstName = $first_order->FirstName;
									$morder->LastName = $first_order->LastName;
									$morder->Email = $wpdb->get_var("SELECT user_email FROM $wpdb->users WHERE ID = '" . $old_order->user_id . "' LIMIT 1");
									$morder->Address1 = $first_order->Address1;
									$morder->City = $first_order->billing->city;
									$morder->State = $first_order->billing->state;
									//$morder->CountryCode = $old_order->billing->city;
									$morder->Zip = $first_order->billing->zip;
									$morder->PhoneNumber = $first_order->billing->phone;

									$morder->billing = new stdClass();
									
									$morder->billing->name = $morder->FirstName . " " . $morder->LastName;
									$morder->billing->street = $first_order->billing->street;
									$morder->billing->city = $first_order->billing->city;
									$morder->billing->state = $first_order->billing->state;
									$morder->billing->zip = $first_order->billing->zip;
									$morder->billing->country = $first_order->billing->country;
									$morder->billing->phone = $first_order->billing->phone;

									//get CC info that is on file
									$morder->cardtype = get_user_meta($first_order->user_id, "pmpro_CardType", true);
									$morder->accountnumber = hideCardNumber(get_user_meta($first_order->user_id, "pmpro_AccountNumber", true), false);
									$morder->expirationmonth = get_user_meta($first_order->user_id, "pmpro_ExpirationMonth", true);
									$morder->expirationyear = get_user_meta($first_order->user_id, "pmpro_ExpirationYear", true);
									$morder->ExpirationDate = $morder->expirationmonth . $morder->expirationyear;
									$morder->ExpirationDate_YdashM = $morder->expirationyear . "-" . $morder->expirationmonth;

									//save
									$morder->status = "success";
									$morder->saveOrder();
									
									$s .= " <span style='color: green;'>Created new order with ID <a href='admin.php?page=pmpro-orders&order=" . $morder->id . "'>#" . $morder->id . "</a></span>";
								}
							}
						}
					}
				}
					
				// Check status of order at gateway.
				$details = $order->getGatewaySubscriptionStatus($order);

				if(empty($details))
				{
					$s .= "<br />- Couldn't get status from gateway. ";
					echo "<span style='color: gray;'>" . $s . "</span>";
					$allmembers .= "<span style='color: gray;'>" . $s . "</span>";
				}
				else
				{
					if(!is_array($details))
						$details = array('STATUS'=>$details);

					$s .= "<br />- Gateway Status is " . $details['STATUS'] . ". ";
					
					//if gateway status is active, but local user has no level, cancel the gateway subscription
					if(strtolower($details['STATUS']) == 'active' && $level_id != $order->membership_id)
					{
						$s .= " But this user has level #" . $level_id . " and order is for #" . $order->membership_id . ". Will try to cancel at the gateway. ";
						if(!empty($_REQUEST['mode']))
						{
							if($order->cancel())
							{
								$s .= " Order cancelled.";
								echo "<span style='color: green;'><strong>" . $s . "</strong></span>";
							}						
							else
							{
								$s .= " Error cancelling order.";
								echo "<span style='color: red;'><strong>" . $s . "</strong></span>";
							}
						}
						else
						{
							echo "<span style='color: black;'><strong>" . $s . "</strong></span>";
							$allmembers .=  "<span style='color: black;'><strong>" . $s . "</strong></span>";
							$requiresaction .=  "<span style='color: black;'><strong>" . $s . "</strong></span><br />\n";
						}
					}
					elseif(!empty($user) && strtolower($details['STATUS']) != 'active' && $level_id == $order->membership_id)
					{
						//if gateway status is not active, but local user has the level associated with this order, cancel the local membership
						$s .= "Still has a #" . $level_id . " membership here. ";

						if(!empty($_REQUEST['mode']))
						{							
							//check if membership has an enddate
							if(empty($level->enddate)) 
							{
								if(pmpro_changeMembershipLevel(0, $user->ID))
								{
									$s .= " Membership cancelled.";
									echo "<span style='color: green;'><strong>" . $s . "</strong></span>";
								}						
								else
								{
									$s .= " Error cancelling membership.";
									echo "<span style='color: red;'><strong>" . $s . "</strong></span>";
								}
							}
							else
							{
								$s .= " Membership is set to end on " . date(get_option('date_format'), $level->enddate) . ". Looks good!";
								echo "<span style='color: gray;'><strong>" . $s . "</strong></span>";
							}
						}
						else
						{
							echo "<span style='color: black;'><strong>" . $s . "</strong></span>";
							$allmembers .=  "<span style='color: black;'><strong>" . $s . "</strong></span>";
							$requiresaction .=  "<span style='color: black;'><strong>" . $s . "</strong></span><br />\n";
						}
					}
					elseif(empty($user))
					{
						$s .= "User was deleted so has no membership. Looks good! ";
						echo "<span style='color: gray;'><strong>" . $s . "</strong></span>";	
						$allmembers .=  "<span style='color: gray;'><strong>" . $s . "</strong></span>";	
					}
					else
					{
						$s .= "Order is for level #" . $order->membership_id . ". ";
						if(!empty($level_id))
							$s .= "User has level #" . $level_id . ". ";
						$s .= "Looks good! ";
						echo "<span style='color: gray;'><strong>" . $s . "</strong></span>";	
						$allmembers .=  "<span style='color: gray;'><strong>" . $s . "</strong></span>";						
					}
				}
				
			}
			else
			{
				$s .= " Does not have an order. ";
				echo "<span style='color: gray;'><strong>" . $s . "</strong></span>";	
				$allmembers .=  "<span style='color: gray;'><strong>" . $s . "</strong></span>";			
			}
			
			echo "</p><p>";
			$allmembers .=  "</p>\n";
			
			$last_user_id = $user_id;
		}

		//update last user id if using auto refresh
		if(!empty($_REQUEST['autorefresh']))
		{
			update_option("pmpro_subscription_check_last_user_id", $last_user_id);

			if(!empty($allmembers))
			{
				//file
				$loghandle = fopen(dirname(__FILE__) . "/logs/allmembers.html", "a");	
				fwrite($loghandle, $allmembers);
				fclose($loghandle);
			}
			
			if(!empty($requiresaction))
			{
				//file
				$loghandle = fopen(dirname(__FILE__) . "/logs/requiresaction.html", "a");
				fwrite($loghandle, $requiresaction);
				fclose($loghandle);
			}
			
			if($totalrows > intval($start)+intval($limit))
			{
				echo "Continuing in 2 seconds...";
				?>
				<script>
					setTimeout(function() {location.reload();}, 2000);
				</script>
				<?php
			}
			else
				echo "All done.";

		}

		echo '<hr /><a class="button button-primary" href="?page=pmproppsc">Start Over</a>';
	}
	else
	{
	?>
	<form method = "get">
		<div class="error"><p><strong><?php _e('IMPORTANT NOTE', 'pmproppsc'); ?>:</strong> <?php _e('Running this code could cancel subscriptions in your WordPress site or at your gateway. Use at your own risk.', 'pmproppsc'); ?></p></div>
		<p><?php _e('Test Mode will check the status of subscriptions, but will not cancel any memberships/subscriptions in your WordPress site or at the gateway.', 'pmproppsc'); ?></p>
		<p><?php _e('Live Mode will (1) check the status of subscriptions, (2) cancel membership in your WordPress site if the gateway subscription was previously cancelled, and (3) cancel the gateway subscription for members that cancelled their membership in your WordPress site.', 'pmproppsc'); ?></p>
		<hr />
		<input type="hidden" name="page" value ="pmproppsc" />
		<input type="hidden" name="pmpro_subscription_check" value="1">		
		<select name="mode">
			<option value="0">Test Only</option>
			<option value="1">Live Mode</option>
		</select>
		<select name="gateway">
			<option value="paypalexpress">PayPal Express</option>
			<option value="paypalstandard">PayPal Standard</option>
			<option value="paypal">PayPal (WPP Legacy)</option>
			<option value="stripe">Stripe</option>
			<option value="authorizenet">Authorize.net</option>
		</select>
		<select name="autorefresh">
			<option value="0">Manual Refresh</option>
			<option value="1">Auto Refresh</option>
		</select>
		<select name="limit">
			<option value="5">5 at a time</option>
			<option value="10">10 at a time</option>
			<option value="20" selected="selected">20 at a time</option>
			<option value="30">30 at a time</option>
			<option value="40">40 at a time</option>
			<option value="50">50 at a time</option>
			<option value="100">100 at a time</option>
		</select>
		<input class="button button-primary" type="submit" value="Start Script" />
	</form>
	<?php
	}
?>
</div>
<?php
}

/*
Function to add links to the plugin action links
*/
function pmproppsc_add_action_links($links) {
	
	$new_links = array(
			'<a href="' . get_admin_url(NULL, 'admin.php?page=pmproppsc') . '">Check Subscriptions</a>',
	);
	return array_merge($new_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'pmproppsc_add_action_links');

/*
Function to add links to the plugin row meta
*/
function pmproppsc_plugin_row_meta($links, $file) {
	if(strpos($file, 'pmpro-subscription-check.php') !== false)
	{
		$new_links = array(
			'<a href="' . esc_url('https://www.paidmembershipspro.com/add-ons/subscription-check/')  . '" title="' . esc_attr( __( 'View Documentation', 'pmpro' ) ) . '">' . __( 'Docs', 'pmpro' ) . '</a>',
			'<a href="' . esc_url('https://paidmembershipspro.com/support/') . '" title="' . esc_attr( __( 'Visit Customer Support Forum', 'pmpro' ) ) . '">' . __( 'Support', 'pmpro' ) . '</a>',
		);
		$links = array_merge($links, $new_links);
	}
	return $links;
}
add_filter('plugin_row_meta', 'pmproppsc_plugin_row_meta', 10, 2);
