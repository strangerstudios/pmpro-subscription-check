=== Paid Memberships Pro - Subscription Check Add On ===
Contributors: strangerstudios
Tags: paid memberships pro, stripe, paypal, subscriptions
Requires at least: 4.0
Tested up to: 5.2.3
Stable tag: .2

Checks whether PayPal/Stripe/Authorize.net subscriptions for PMPro members are still active.

== Description ==

This plugin creates a new page in your WordPress Dashboard where you can run a check against active subscriptions in your site with the gateway.

The check can be run in Live Mode or Test Mode. When you run a subscription check in Test Mode, the admin page will produce a report of all subscriptions analyzed, but it will not cancel any memberships in your WordPress site or active subscriptions at the gateway.

The plugin can also be run in Live Mode. Live Mode will complete the following operations:

1. Check the status of subscriptions at the gateway.
1. Cancel membership in your WordPress site if the gateway subscription was previously cancelled.
1. Cancel the gateway subscription for members that cancelled their membership in your WordPress site.

Live Mode will trigger membership cancellations in your site and subscription cancellations at gateway. This process is irreversible so please use it with caution.

== Installation ==

1. Make sure you have the Paid Memberships Pro plugin installed and activated and that Stripe is your primary gateway.
1. Upload the `pmpro-subscription-check` directory to the `/wp-content/plugins/` directory of your site.
1. Activate the plugin through the 'Plugins' menu in WordPress.

== Frequently Asked Questions ==

= I found a bug in the plugin. =

Please post it in the GitHub issue tracker here: https://github.com/strangerstudios/pmpro-subscription-check/issues

= I need help installing, configuring, or customizing the plugin. =

Please visit our premium support site at https://www.paidmembershipspro.com for more documentation and our support forums.

== Changelog ==
= .2 =
* First version with a readme.
