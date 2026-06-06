<?php
/**
 * Milieus by Therum — WooCommerce auto-group on purchase.
 *
 * Lets a product "carry" a member group. When the order completes, the
 * buyer is automatically assigned to that group with the group's default
 * member duration. When the order is refunded, the assignment is revoked.
 *
 * Stored as product post_meta keys:
 *   _milieus_group          string — group key (custom milieus role)
 *   _milieus_duration_override int — optional override (days). 0 = use group's default
 *
 * Activate by editing a product → Product data → General tab → "Milieus
 * member group" select. Guest checkout: a new WP user is created using the
 * checkout email (WooCommerce already does this if "Allow customers to
 * create an account during checkout" is on; otherwise the assignment is
 * silently skipped and a debug log line is written).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const MILIEUS_PRODUCT_GROUP_META    = '_milieus_group';
const MILIEUS_PRODUCT_DURATION_META = '_milieus_duration_override';

add_action( 'plugins_loaded', function() {
	if ( ! class_exists( 'WooCommerce' ) ) return;

	// Product data panel
	add_action( 'woocommerce_product_options_general_product_data', 'milieus_wc_render_product_fields' );
	add_action( 'woocommerce_admin_process_product_object',         'milieus_wc_save_product_fields' );

	// Order lifecycle
	add_action( 'woocommerce_order_status_completed', 'milieus_wc_on_order_complete' );
	add_action( 'woocommerce_order_status_processing', 'milieus_wc_on_order_complete' ); // also handle processing — many stores ship at processing
	add_action( 'woocommerce_order_status_refunded',  'milieus_wc_on_order_refund' );
	add_action( 'woocommerce_order_status_cancelled', 'milieus_wc_on_order_refund' );
} );

// ── Product data panel ───────────────────────────────────────────────

function milieus_wc_render_product_fields(): void {
	$groups = milieus_get_groups();
	$opts = [ '' => __( '— None —', 'milieus' ) ];
	foreach ( $groups as $k => $g ) $opts[ $k ] = $g['name'] ?? $k;

	woocommerce_wp_select( [
		'id'          => MILIEUS_PRODUCT_GROUP_META,
		'label'       => __( 'Milieus member group', 'milieus' ),
		'options'     => $opts,
		'description' => __( 'Auto-assign the buyer to this Milieus group when the order completes. Refunds revoke the assignment.', 'milieus' ),
		'desc_tip'    => true,
	] );

	woocommerce_wp_text_input( [
		'id'                => MILIEUS_PRODUCT_DURATION_META,
		'label'             => __( 'Override duration (days)', 'milieus' ),
		'type'              => 'number',
		'custom_attributes' => [ 'min' => '0', 'step' => '1' ],
		'description'       => __( 'Optional: override the group\'s default member duration for this product. Use 0 to use the group default.', 'milieus' ),
		'desc_tip'          => true,
	] );
}

function milieus_wc_save_product_fields( $product ): void {
	$group    = isset( $_POST[ MILIEUS_PRODUCT_GROUP_META ] ) ? sanitize_key( $_POST[ MILIEUS_PRODUCT_GROUP_META ] ) : '';
	$override = isset( $_POST[ MILIEUS_PRODUCT_DURATION_META ] ) ? max( 0, (int) $_POST[ MILIEUS_PRODUCT_DURATION_META ] ) : 0;
	$product->update_meta_data( MILIEUS_PRODUCT_GROUP_META, $group );
	$product->update_meta_data( MILIEUS_PRODUCT_DURATION_META, $override );
}

// ── Order lifecycle ──────────────────────────────────────────────────

/**
 * On order complete / processing: iterate line items, assign buyer to any
 * group carried by a purchased product. Idempotent — re-assigning the same
 * user/group is safe; it resets joined-at + expiry.
 */
function milieus_wc_on_order_complete( int $order_id ): void {
	$order = wc_get_order( $order_id );
	if ( ! $order ) return;

	// Avoid double-processing on status flip-flops.
	$flag_key = '_milieus_assignments_done';
	if ( $order->get_meta( $flag_key ) ) return;

	$user_id = (int) $order->get_user_id();
	if ( ! $user_id ) {
		// Guest order — try to find/create a user from the billing email.
		$email = $order->get_billing_email();
		if ( ! $email ) return;
		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			$user_id = $existing->ID;
		} else {
			$user_id = wp_create_user( $email, wp_generate_password( 16, true ), $email );
			if ( is_wp_error( $user_id ) ) return;
			wp_new_user_notification( $user_id, null, 'user' );
		}
	}

	$assigned = [];
	foreach ( $order->get_items() as $item ) {
		/** @var WC_Order_Item_Product $item */
		$product = $item->get_product();
		if ( ! $product ) continue;

		$group_key = (string) $product->get_meta( MILIEUS_PRODUCT_GROUP_META );
		if ( ! $group_key ) continue;
		if ( ! milieus_get_group( $group_key ) ) continue;

		$override_days = (int) $product->get_meta( MILIEUS_PRODUCT_DURATION_META );

		milieus_assign_member( (int) $user_id, $group_key, 'purchase' );

		if ( $override_days > 0 ) {
			update_user_meta(
				$user_id,
				MILIEUS_META_EXPIRES . $group_key,
				time() + $override_days * DAY_IN_SECONDS
			);
		}

		$assigned[] = $group_key;
		do_action( 'milieus_member_purchased', $user_id, $group_key, $order_id, $product->get_id() );
	}

	if ( $assigned ) {
		$order->update_meta_data( $flag_key, time() );
		$order->update_meta_data( '_milieus_assigned_groups', $assigned );
		$order->save();
	}
}

/**
 * On refund / cancel: revoke assignments made by this order.
 */
function milieus_wc_on_order_refund( int $order_id ): void {
	$order = wc_get_order( $order_id );
	if ( ! $order ) return;

	$user_id = (int) $order->get_user_id();
	if ( ! $user_id ) return;

	$groups = (array) $order->get_meta( '_milieus_assigned_groups' );
	foreach ( $groups as $group_key ) {
		if ( ! milieus_get_group( $group_key ) ) continue;
		milieus_revoke_member( $user_id, (string) $group_key );
		do_action( 'milieus_member_refunded', $user_id, $group_key, $order_id );
	}
	$order->delete_meta_data( '_milieus_assigned_groups' );
	$order->delete_meta_data( '_milieus_assignments_done' );
	$order->save();
}
