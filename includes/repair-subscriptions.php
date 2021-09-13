<?php 
/**
 * Temp function for testing.
 */
/*
function pmprosc_testing() {
    if ( empty( $_REQUEST['test_uncancel_stripe_subscription'] ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }
        
    pmprosc_uncancel_stripe_subscription(1, true, true);
    exit;
}
add_action( 'init', 'pmprosc_testing' );
*/

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
    $last_order->BillingPeriod = 'notathing';//$sub_item->plan->interval;
    $last_order->BillingFrequency = $sub_item->plan->interval_count;
    $last_order->ProfileStartDate = date( 'Y-m-d', $start_date ) . 'T0:0:0';
        
    // We need to use the filter because the subscribe method overwrites this.    
    add_filter('pmpro_profile_start_date', function($startdate, $order) use ($last_order) { return $last_order->ProfileStartDate;   }, 10, 2);
    
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