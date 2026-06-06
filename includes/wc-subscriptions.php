<?php
/**
 * Milieus by Therum — recurring memberships via WooCommerce Subscriptions.
 *
 * Same product meta as one-shot purchases (_milieus_group) — when a product
 * also happens to be a subscription, the group is tied to the subscription's
 * lifecycle instead of one-shot order completion.
 *
 *   active / processing       → member is in the group
 *   on-hold / cancelled       → member is removed
 *   expired                   → member is removed
 *   pending-cancel            → stay in until period end (WCS handles the
 *                               transition to cancelled)
 *
 * Works alongside includes/wc-auto-group.php — that file's order-complete
 * hook handles the initial assignment when the first invoice processes;
 * this file handles ongoing subscription state changes.
 *
 * Detects WC Subscriptions automatically; no-ops if not installed. A small
 * admin notice surfaces if you configure a Milieus group on a subscription
 * product but WCS isn't active.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'plugins_loaded', function() {
	if ( ! class_exists( 'WC_Subscriptions' ) ) return;

	// Status transitions — every WCS status change fires this.
	add_action( 'woocommerce_subscription_status_updated', 'milieus_subs_on_status_change', 10, 3 );

	// Hook the manual cancel/expire paths too (some configs don't always
	// go through status_updated for these).
	add_action( 'woocommerce_subscription_status_active',    'milieus_subs_apply' );
	add_action( 'woocommerce_subscription_status_on-hold',   'milieus_subs_revoke' );
	add_action( 'woocommerce_subscription_status_cancelled', 'milieus_subs_revoke' );
	add_action( 'woocommerce_subscription_status_expired',   'milieus_subs_revoke' );
} );

/**
 * Generic status transition handler — figures out direction from new_status.
 */
function milieus_subs_on_status_change( $subscription, $new_status, $old_status ): void {
	$activeStates = [ 'active', 'processing', 'pending-cancel' ];
	$endStates    = [ 'cancelled', 'expired', 'on-hold', 'switched' ];
	if ( in_array( $new_status, $activeStates, true ) ) {
		milieus_subs_apply( $subscription );
	} elseif ( in_array( $new_status, $endStates, true ) ) {
		milieus_subs_revoke( $subscription );
	}
}

/**
 * Assign the buyer to every Milieus group attached to the subscription's
 * products. Idempotent — re-firing on each renewal is fine; milieus_assign_member
 * just resets joined/expiry.
 */
function milieus_subs_apply( $subscription ): void {
	$sub = is_object( $subscription ) ? $subscription : wcs_get_subscription( (int) $subscription );
	if ( ! $sub ) return;

	$user_id = (int) $sub->get_user_id();
	if ( ! $user_id ) return;

	foreach ( $sub->get_items() as $item ) {
		$product = $item->get_product();
		if ( ! $product ) continue;
		$group_key = (string) $product->get_meta( MILIEUS_PRODUCT_GROUP_META );
		if ( ! $group_key || ! milieus_get_group( $group_key ) ) continue;

		milieus_assign_member( $user_id, $group_key, 'subscription' );

		// Bind expiry to the subscription's next-payment date so the cron
		// sweep won't yank them mid-period.
		$next = (int) ( method_exists( $sub, 'get_time' ) ? $sub->get_time( 'next_payment' ) : 0 );
		if ( $next > 0 ) {
			update_user_meta( $user_id, MILIEUS_META_EXPIRES . $group_key, $next + DAY_IN_SECONDS );
		} else {
			// No next payment scheduled (eg. trial only); fall back to end date.
			$end = (int) ( method_exists( $sub, 'get_time' ) ? $sub->get_time( 'end' ) : 0 );
			if ( $end > 0 ) update_user_meta( $user_id, MILIEUS_META_EXPIRES . $group_key, $end );
		}

		do_action( 'milieus_subscription_active', $user_id, $group_key, $sub->get_id() );
	}
}

function milieus_subs_revoke( $subscription ): void {
	$sub = is_object( $subscription ) ? $subscription : wcs_get_subscription( (int) $subscription );
	if ( ! $sub ) return;
	$user_id = (int) $sub->get_user_id();
	if ( ! $user_id ) return;

	foreach ( $sub->get_items() as $item ) {
		$product = $item->get_product();
		if ( ! $product ) continue;
		$group_key = (string) $product->get_meta( MILIEUS_PRODUCT_GROUP_META );
		if ( ! $group_key ) continue;
		milieus_revoke_member( $user_id, $group_key );
		do_action( 'milieus_subscription_ended', $user_id, $group_key, $sub->get_id() );
	}
}

/**
 * Admin notice: if a subscription product has a Milieus group attached but
 * WC Subscriptions isn't active, surface that so the merchant doesn't think
 * recurring is silently working.
 */
add_action( 'admin_notices', function() {
	if ( ! class_exists( 'WooCommerce' ) ) return;
	if ( class_exists( 'WC_Subscriptions' ) ) return;
	if ( ! current_user_can( 'manage_woocommerce' ) ) return;

	// Cheap check — only on Milieus + WC product screens
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, [ 'product', 'edit-product', 'toplevel_page_milieus-roles' ], true ) ) return;
	?>
	<div class="notice notice-info is-dismissible">
		<p><strong>Milieus:</strong> for recurring memberships (auto-revoke on cancellation, expiry tied to next payment), install <a href="https://woocommerce.com/products/woocommerce-subscriptions/" target="_blank" rel="noopener">WooCommerce Subscriptions</a>. Without it, products grant the group on first purchase only.</p>
	</div>
	<?php
} );
