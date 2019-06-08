<?php
/*
Plugin Name: Paid Memberships Pro - Subscription Check Add On
Plugin URI: http://www.paidmembershipspro.com/wp/pmpro-subscription-check/
Description: Checks whether PayPal/Stripe/Authorize.net subscriptions for PMPro members are still active.
Version: .2
Author: Stranger Studios
Author URI: http://www.strangerstudios.com
*/

/*
 * Add settings page
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

//the page
function pmproppsc_admin_page()
{
?>
<div class="wrap">
<h2>PMPro Subscription Check</h2>
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
		if(!empty($_REQUEST['autorefresh']) && empty($_REQUEST['restart']))
			$last_user_id = get_option("pmpro_subscription_check_last_user_id", 0);
		else
			$last_user_id = 0;

		//get gateway
		$gateway = $_REQUEST['gateway'];
		if(!in_array($gateway, array_keys(pmpro_gateways())))
			wp_die('Invalid gateway selection.');
		
		//when using auto refresh get the last user id
		if(!empty($_REQUEST['autorefresh']) && empty($_REQUEST['restart']))
			$last_user_id = get_option("pmpro_stripe_subscription_check_last_user_id", 0);
		else
			$last_user_id = 0;

		$sqlQuery = "SELECT DISTINCT(user_id) FROM $wpdb->pmpro_membership_orders WHERE gateway = '" . esc_sql($gateway) . "' ";
		if(!empty($last_user_id))
			$sqlQuery .= "AND user_id > '" . $last_user_id . "' ";
		$sqlQuery .= "ORDER BY user_id LIMIT $start, $limit";
				
		$user_ids = $wpdb->get_col($sqlQuery);
		$totalrows = $wpdb->get_var("SELECT COUNT(DISTINCT(user_id)) FROM $wpdb->pmpro_membership_orders WHERE gateway = '" . esc_sql($gateway) . "'");
		
		if(empty($_REQUEST['autorefresh']))
		{
			echo "Checking users for orders " . $start . " to " . min(intval($start + $limit), $totalrows) . ". ";			
			if($totalrows > intval($start+$limit))
			{
				$url = "?page=pmproppsc&pmpro_subscription_check=1&start=" . ($start+$limit) . "&limit=" . $limit . "&gateway=" . $gateway;
				echo '<a href="' . $url . '">Next ' . $limit . ' Results</a>';
			}
			else
				echo "All done.";

			echo "<hr />";
		}

		$allmembers = "";
		$requiresaction = "";

		foreach($user_ids as $user_id)
		{
			$user = get_userdata($user_id);			
			
			if(empty($user))
				$s = "User #" . $user_id . " has been deleted. ";
			else			
				$s = "User #" . $user->ID . "(" . $user->user_email . ", " . $user->first_name . " " . $user->lat_name . ") ";
			
			$level = pmpro_getMembershipLevelForUser($user_id);
			if(!empty($level) && empty($user))
			{
				$s .= " Had level #" . $level->id . ". ";
				$level_id = $level->id;
			}
			elseif(!empty($level))
			{
				$s .= " Has level #" . $level->id . ". ";
				$level_id = $level->id;
			}
			else
			{
				$s .= " Does not have a level. ";
				$level_id = "";
			}
			
			$order = new MemberOrder();
			$order->getLastMemberOrder($user_id, "");						
			
			if(!empty($order->id))
			{
				$s .= " Last order was #" . $order->id . ", status " . $order->status . ". ";
				
				//check status of order at gateway
				$details = $order->getGatewaySubscriptionStatus($order);

				if(empty($details))
				{
					$s .= " Couldn't get status from gateway. ";
					echo "<span style='color: gray;'>" . $s . "</span>";
					$allmembers .= "<span style='color: gray;'>" . $s . "</span>";
				}
				else
				{
					if(!is_array($details))
						$details = array('STATUS'=>$details);

					$s .= "Gateway Status is " . $details['STATUS'] . ". ";
					
					//if gateway status is active, but local user has no level, cancel the gateway subscription
					if(strtolower($details['STATUS']) == 'active' && $level_id != $order->membership_id)
					{
						$s .= " But this user has level #" . $user_level_id . " and order is for #" . $order->membership_id . ". Will try to cancel at the gateway. ";
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
						$s .= "Order is for level #" . $order->membership_id . ". User has level #" . $level_id . ". Looks good! ";
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
			
			echo "<br />\n";
			$allmembers .=  "<br />\n";
			
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

		echo '<hr /><a href="?page=pmproppsc">&laquo; return to Subscription Check home</a>';
	}
	else
	{
	?>
	<form method = "get">
		<p><strong>WARNING: Running this code could cancel subscriptions on the WP side or at your gateway. Use at your own risk.</strong></p>
		<p>Test mode will check the status of subscriptions, but won't cancel any memberships locally or cancel any subscription at the gateway. Live Mode will check the status of subscriptions and also cancel any membership locally if the gateway subscription was perviously cancelled and will cancel any gateway subscription for members that cancelled their membership in PMPro.</p>
		<input type="hidden" name="page" value ="pmproppsc" />
		<input type="hidden" name="pmpro_subscription_check" value="1">
		<input type="hidden" name="restart" value="1" />
		<select name="mode">
			<option value="0">Test Only</option>
			<option value="1">Live Mode</option>
		</select>
		<select name="gateway">
			<!-- I added these gateways in the following order rather than have only one option for all PayPal's flavours -->
			<option value="stripe">Stripe</option>
			<option value="paypalexpress">PayPal Express</option>
			<option value="paypal">PayPal Website Payments Pro</option>
			<option value="payflowpro">PayPal Payflow Pro/PayPal Pro</option>
			<option value="paypalstandard">PayPal Standard</option>
			<option value="authorizenet">Authorize.net</option>
			<option value="braintree">Braintree Payments</option>
			<option value="twocheckout">2Checkout</option>
			<option value="cybersource">Cybersource</option>
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
		<input type="submit" value="Start Script" />
	</form>
	<?php
	}
?>
</div>
<?php
}
