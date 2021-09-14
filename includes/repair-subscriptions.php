<?php 
/**
 * Temp function for testing.
 */
/*
function pmprosc_testing() {
    if ( empty( $_REQUEST['test_uncancel_stripe_subscription'] ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    pmprosc_uncancel_users();
        
    //pmprosc_uncancel_stripe_subscription(1, false, true);
    exit;
}
add_action( 'init', 'pmprosc_testing' );
*/

/**
 * Filter to make sure our updated start date is used.
 * $order->RealProfileStartDate is set in the
 * pmprosc_uncancel_stripe_subscription() function.
 */
function pmprosc_real_profile_start_date( $startdate, $order ) {        
     if ( ! empty( $order->RealProfileStartDate ) ) {
         $startdate = $order->RealProfileStartDate;
     }
     return $startdate;
 }
 add_filter( 'pmpro_profile_start_date', 'pmprosc_real_profile_start_date', 99, 2 );

/**
 * Find users flagged in user meta to uncancel.
 *
 * @param int       $limit      The number of user ids to get at a time.
 * @return array    $user_ids   Array of user ids that have been flagged to uncancel.
 */
function pmprosc_get_user_ids_to_uncancel( $limit = 10 ) {
    global $wpdb;
    
    // NOTE: Using user meta for now, but a user taxonomy would be better.
    $meta_key = 'uncancel_stripe_subscription';    
    $sqlQuery = "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = '" . $meta_key . "' AND meta_value = '1' LIMIT " . intval( $limit );
    $user_ids = $wpdb->get_col( $sqlQuery );
    return $user_ids;
}

/**
 * Uncancel users.
 * Grab 10 users. Uncancel them. And show a link to uncancel the next few.
 */
function pmprosc_uncancel_users() {
    if ( ! empty( $_REQUEST['limit'] ) ) {
        $limit = intval( $_REQUEST['limit'] );
    } else {
        $limit = 10;
    }
    
    if ( isset( $_REQUEST['test'] ) && $_REQUEST['test'] == 0 ) {
        $test = false;
    } else {
        $test = true;
    }
    
    if ( isset( $_REQUEST['debug'] ) && $_REQUEST['debug'] == 0 ) {
        $debug = false;
    } else {
        $debug = true;
    }
    
    $user_ids = pmprosc_get_user_ids_to_uncancel( $limit );
    
    if ( $debug ) {
        echo "<h1>Uncanceling some users.</h1>";
        echo "<p>Found " . count( $user_ids ) . " user(s).</p>";
        echo "<hr />";
    }
    
    foreach( $user_ids as $user_id ) {
        ob_start();
        $result = pmprosc_uncancel_stripe_subscription( $user_id, $test, $debug );
        if ( $debug ) {
            echo "<br />";
        }
        if ( ! $test ) {
            if ( $result === false ) {
                // this was an error              
                update_user_meta( $user_id, 'uncancel_stripe_subscription', 2 );
            } elseif ( $result === null ) {
                // this was another skip
                update_user_meta( $user_id, 'uncancel_stripe_subscription', 3 );
            } else {
                // it worked
                update_user_meta( $user_id, 'uncancel_stripe_subscription', 4 );
            }
        }
        $content = ob_get_contents();
        ob_end_clean();

        echo nl2br( $content );
    }
    
}

/**
 * Try to figure out a user's Stripe Customer ID
 *
 * @param   int      user_id     The user_id of the user to lookup.
 * @return  string   customer_id The user's Stripe customer id or an empty string if not found.
 */
function pmprosc_get_stripe_customer_id( $user_id ) {
    global $wpdb;
    
    // Check user meta first.
    $customer_id = get_user_meta( $user_id, 'pmpro_stripe_customerid', true );
    if ( ! empty( $customer_id ) ) {
        return $customer_id;
    }
    
    // Still no, check by sub id on an old order.
    $gateway = new PMProGateway_stripe();
    $stripe = new Stripe\StripeClient(
      PMProGateway_stripe::get_secretkey()
    );
    $sqlQuery = "SELECT subscription_transaction_id FROM $wpdb->pmpro_membership_orders WHERE user_id = '" . intval($user_id) . "' AND subscription_transaction_id <> '' LIMIT 1";
    $sub_id = $wpdb->get_var( $sqlQuery );        
    if ( ! empty( $sub_id ) ) {
        try {
            $sub = $stripe->subscriptions->retrieve( $sub_id, [] );
        } catch (\Throwable $th) {
            // Probably just couldn't find the sub.            
        } catch (\Exception $e) {
            // Probably just couldn't find the sub.
        }
        
        if ( ! empty( $sub ) ) {            
            $customer_id = $sub->customer;
        }
    }
    
    // Still no, check Stripe by email address.    
    $user = get_userdata( $user_id );
    try {
        $customers = $stripe->customers->all(['email' => $user->user_email,'limit' => 1]);
        if ( ! empty( $customers->data ) ) {
            $customer = $customers->data[0];
            $customer_id = $customer->id;
        }
    } catch (\Throwable $th) {
        // Probably just couldn't find the customer.            
    } catch (\Exception $e) {
        // Probably just couldn't find the customer.
    }
    
    return $customer_id;
}

/**
 * Uncancel a user's subscription at Stripe.
 *
 * @param   int         user_id The user_id of the user uncancel.
 * @return  string|bool sub_id  The sub_id of the sub created, or false if no sub was created.
 */
function pmprosc_uncancel_stripe_subscription( $user_id, $test = false, $debug = false ) {
    $gateway = new PMProGateway_stripe();
    $stripe = new Stripe\StripeClient(
      PMProGateway_stripe::get_secretkey()
    );
    $user = get_userdata( $user_id );
    $customer_id = pmprosc_get_stripe_customer_id( $user_id );
    
    if ( $debug ) {
        echo "Trying to uncancel a subscription for user #" . $user->ID . ", " . $user->user_login . ", " . $user->user_email . ".\n";
    }
    
    // If we couldn't get the customer id, then we can't do this. Bail.
    if ( empty( $customer_id ) ) {
        if ( $debug ) {
            echo "Could not find this user's customer id with Stripe.\n";
        }
        return false;
    }
    
    if ( $debug ) {
        echo "Customer id at Stripe is " . esc_html( $customer_id ) . ".\n";
    }
    
    // If the user has live subs, bail.    
    try {
        $customer = $stripe->customers->retrieve( $customer_id, [] );        
        $subscriptions = $customer->subscriptions;
        
        if ( ! empty( $subscriptions ) && $subscriptions->total_count > 0 ) {
            if ( $debug ) {
                echo "This user has other active subscriptions.\n";
            }
            return false;
        }
    } catch (\Throwable $th) {
        echo $th->getMessage();
    } catch (\Exception $e) {
        echo $e->getMessage();
    }
    
    // Find their most recently canceled subscription.
    try {
        $canceled_subs = $stripe->subscriptions->all(['customer'=>$customer_id, 'status'=>'canceled']);
        if ( ! empty( $canceled_subs ) && ! empty( $canceled_subs->data ) ) {
            // First one should be the most recent.
            $old_sub = $canceled_subs->data[0];
        }
    } catch (\Throwable $th) {
        echo $th->getMessage();
    } catch (\Exception $e) {
        echo $e->getMessage();
    }
    
    // If we couldn't find an old subscription, bail.
    if ( empty( $old_sub ) ) {
        if ( $debug ) {
            echo "Couldn't find an old canceled subscription for this customer.\n";
            return false;
        }
    }
        
    if ( $debug ) {
        echo "Found their last canceled subscription #" . esc_html( $old_sub->id ) . ".\n";
    }
    
    // Find the corresponding order in PMPro.
    $last_order = new MemberOrder();
    $last_order->getLastMemberOrderBySubscriptionTransactionID( $old_sub->id );
    if ( empty( $last_order->id ) ) {
        if ( $debug ) {
            echo "Couldn't find an order in PMPro for the old subscription with id " . esc_html( $old_sub->id ) . ".\n";
            return false;
        }
    }
    
    // Get some values from the old subscription.
    $sub_item = $old_sub->items->data[0];
    $start_date = $old_sub->current_period_end;
    
    // If the start date is in the past, we're too late.
    if ( $start_date < current_time( 'timestamp' ) ) {
        if ( $debug ) {
            echo "The period end from the old subscription (" . esc_html( date('Y-m-d', $start_date ) ) . ") is in the past. It's too late to uncancel this.\n";
        }
        return false;
    }
    
    // Update our order with some properties the subscribe method expects.    
    $sub_item = $old_sub->items->data[0];
    $last_order->getUser();
    $last_order->getMembershipLevel();
    $last_order->Email = $last_order->user->user_email;
    $last_order->membership_name = $last_order->membership_level->name;
    $last_order->InitialPayment = 0;
    $last_order->PaymentAmount = $sub_item->plan->amount/100;
    $last_order->BillingPeriod = $sub_item->plan->interval;
    $last_order->BillingFrequency = $sub_item->plan->interval_count;
    $last_order->ProfileStartDate = date( 'Y-m-d', $start_date ) . 'T0:0:0';
    $last_order->RealProfileStartDate = $last_order->ProfileStartDate;
    
    // Okay. Subscribe.
    if ( ! $test ) {
        if ( $debug ) {
            echo "Creating the new subscription and subscribing.\n";
        }        
        $gateway->subscribe( $last_order );        
    } elseif ( $debug ) {
        echo "TESTING. This is where we would have created the new subscription and subscribed the customer.\n";
    }    
    
    // Was there an error?
    if ( ! empty( $last_order->error ) ) {
        if ( $debug ) {
            echo $last_order->error . "\n";
        }
        return false;
    }
    
    if ( ! $test && $debug ) {
        echo "<strong>New subscription created: " . esc_html( $last_order->subscription_transaction_id ) . "</strong>\n";
    }
    
    // Saving the order updates the status and subscription_transaction_id.    
    if ( ! $test ) {
        $last_order->saveOrder();
        if ( $debug ) {
            echo "Updating the old order to use the new subscription id.\n";
        }
    } elseif ( $debug ) {
        echo "TESTING. This is where we would have updated the old order to use the new subscription id.\n";
    }

    return $last_order->subscription_transaction_id;
}