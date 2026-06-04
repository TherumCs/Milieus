<?php
/**
 * Milieus by Therum — WooCommerce role-based pricing.
 *
 * Discounts are stored as a percentage on each custom role record. At cart
 * calculation time, we look up the current user's roles, take the largest
 * discount %, and apply it as a negative cart fee.
 *
 * If WooCommerce isn't active, none of these hooks ever fire — WC isn't
 * listed as a hard dependency.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'woocommerce_cart_calculate_fees', function( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
	if ( ! is_user_logged_in() ) return;

	$user = wp_get_current_user();
	if ( empty( $user->roles ) ) return;

	$custom        = milieus_get_custom_roles();
	$max_discount  = 0.0;
	$discount_role = '';

	foreach ( $user->roles as $role_key ) {
		if ( ! isset( $custom[ $role_key ] ) ) continue;
		$pct = (float) ( $custom[ $role_key ]['discount'] ?? 0 );
		if ( $pct > $max_discount ) {
			$max_discount  = $pct;
			$discount_role = $custom[ $role_key ]['name'] ?? $role_key;
		}
	}

	if ( $max_discount <= 0 ) return;

	$subtotal = (float) $cart->get_subtotal();
	if ( $subtotal <= 0 ) return;

	$discount = round( $subtotal * ( $max_discount / 100 ), 2 );
	$label    = sprintf(
		/* translators: 1: role name, 2: discount percentage. */
		__( '%1$s discount (%2$s%%)', 'milieus' ),
		$discount_role,
		rtrim( rtrim( number_format( $max_discount, 2 ), '0' ), '.' )
	);

	$cart->add_fee( $label, -$discount, false );
}, 20, 1 );

// Informational notice on the WC My Account dashboard.
add_action( 'woocommerce_account_dashboard', function() {
	$user = wp_get_current_user();
	if ( empty( $user->roles ) ) return;

	$custom    = milieus_get_custom_roles();
	$discount  = 0.0;
	$role_name = '';
	foreach ( $user->roles as $r ) {
		if ( isset( $custom[ $r ] ) && (float) ( $custom[ $r ]['discount'] ?? 0 ) > $discount ) {
			$discount  = (float) $custom[ $r ]['discount'];
			$role_name = $custom[ $r ]['name'];
		}
	}
	if ( $discount > 0 ) {
		$pretty = rtrim( rtrim( number_format( $discount, 2 ), '0' ), '.' );
		echo '<div class="milieus-wc-notice" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;margin:14px 0;">';
		echo '<strong>' . esc_html( $role_name ) . ':</strong> ';
		echo esc_html( sprintf(
			/* translators: %s: discount percentage. */
			__( 'You receive %s%% off all orders automatically at checkout.', 'milieus' ),
			$pretty
		) );
		echo '</div>';
	}
} );
