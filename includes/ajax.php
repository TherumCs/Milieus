<?php
/**
 * Milieus by Therum — AJAX handlers.
 *
 * Save / delete custom groups; set default group. Member-tab AJAX lives in
 * members.php so each concern stays in its own file.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_ajax_milieus_role_save', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'milieus_role', 'nonce' );

	$key      = sanitize_key( $_POST['key'] ?? '' );
	$name     = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	$bundles  = isset( $_POST['bundles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['bundles'] ) ) : [];
	$caps     = isset( $_POST['caps'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['caps'] ) ) : [];
	$discount = isset( $_POST['discount'] ) ? max( 0, min( 100, (float) $_POST['discount'] ) ) : 0;
	$is_new   = isset( $_POST['is_new'] ) && $_POST['is_new'] === '1';

	if ( ! $name ) wp_send_json_error( 'name is required' );

	if ( $is_new || ! $key ) {
		$key = sanitize_key( $name );
		if ( ! $key ) wp_send_json_error( 'invalid name' );

		if ( in_array( $key, milieus_reserved_roles(), true ) ) {
			wp_send_json_error( 'cannot overwrite built-in group: ' . $key );
		}

		global $wp_roles;
		if ( ! $wp_roles ) $wp_roles = wp_roles();
		$base = $key;
		$i = 2;
		while ( isset( $wp_roles->roles[ $key ] ) ) {
			$key = $base . '_' . $i;
			$i++;
		}
	}

	$cap_set = milieus_resolve_caps( $bundles, $caps );

	global $wp_roles;
	if ( ! $wp_roles ) $wp_roles = wp_roles();
	if ( isset( $wp_roles->roles[ $key ] ) ) {
		remove_role( $key );
	}

	add_role( $key, $name, $cap_set );

	// ─── New fields: expiry, member duration, registration link ────────
	$expires_at = (int) ( $_POST['expires_at'] ?? 0 );
	$dur_value  = max( 0, (int) ( $_POST['duration_value'] ?? 0 ) );
	$dur_unit   = in_array( $_POST['duration_unit'] ?? 'days', [ 'days', 'weeks', 'months', 'years' ], true )
		? $_POST['duration_unit'] : 'days';

	// Preserve existing reg config if not all fields posted (partial saves OK).
	$existing = milieus_get_group( $key ) ?? [];
	$reg = wp_parse_args( $existing['reg'] ?? [], milieus_reg_defaults() );

	if ( isset( $_POST['reg'] ) && is_array( $_POST['reg'] ) ) {
		$r = wp_unslash( $_POST['reg'] );
		$reg['enabled']      = ! empty( $r['enabled'] );
		$reg['slug']         = sanitize_title( $r['slug'] ?? '' );
		$reg['logo']         = esc_url_raw( $r['logo'] ?? '' );
		$reg['heading']      = sanitize_text_field( $r['heading'] ?? '' );
		$reg['lede']         = sanitize_textarea_field( $r['lede'] ?? '' );
		$reg['brand']        = sanitize_text_field( $r['brand'] ?? '' );
		$reg['color']        = milieus_sanitize_hex( $r['color'] ?? '' );
		$reg['button']       = sanitize_text_field( $r['button'] ?? '' );
		$reg['extras']       = array_values( array_intersect(
			[ 'name', 'company', 'phone', 'referral', 'how-heard' ],
			array_map( 'sanitize_key', (array) ( $r['extras'] ?? [] ) )
		) );
		$reg['redirect']     = esc_url_raw( $r['redirect'] ?? '' );
		$reg['approval']     = ! empty( $r['approval'] );
		$reg['max_signups']  = max( 0, (int) ( $r['max_signups'] ?? 0 ) );
		$reg['bg_kind']      = in_array( $r['bg_kind'] ?? 'solid', [ 'solid', 'gradient', 'image' ], true ) ? $r['bg_kind'] : 'solid';
		$reg['bg_solid']     = milieus_sanitize_hex( $r['bg_solid'] ?? '#fafaf9' ) ?: '#fafaf9';
		$reg['bg_grad_1']    = milieus_sanitize_hex( $r['bg_grad_1'] ?? '#fde68a' ) ?: '#fde68a';
		$reg['bg_grad_2']    = milieus_sanitize_hex( $r['bg_grad_2'] ?? '#fca5a5' ) ?: '#fca5a5';
		$reg['bg_grad_dir']  = sanitize_text_field( $r['bg_grad_dir'] ?? '135deg' );
		$reg['bg_image']     = esc_url_raw( $r['bg_image'] ?? '' );
		$reg['bg_dim']       = ! empty( $r['bg_dim'] );
		$reg['bg_blur']      = ! empty( $r['bg_blur'] );
		$reg['welcome_enabled'] = ! empty( $r['welcome_enabled'] );
		$reg['welcome_heading'] = sanitize_text_field( $r['welcome_heading'] ?? '' );
		$reg['welcome_body']    = wp_kses_post( $r['welcome_body'] ?? '' );
		$reg['welcome_cta']     = sanitize_text_field( $r['welcome_cta'] ?? '' );
	}

	$color = milieus_sanitize_hex( $_POST['color'] ?? '' ) ?: ( $existing['color'] ?? '#2563eb' );

	milieus_save_group( $key, [
		'key'             => $key,
		'name'            => $name,
		'color'           => $color,
		'bundles'         => $bundles,
		'caps'            => array_keys( $cap_set ),
		'discount'        => $discount,
		'expires_at'      => $expires_at,
		'member_duration' => [ 'value' => $dur_value, 'unit' => $dur_unit ],
		'reg'             => $reg,
		'updated'         => time(),
	] );

	// If the slug or enabled state changed, refresh rewrites.
	if ( ( $existing['reg']['slug'] ?? '' ) !== $reg['slug'] || ( $existing['reg']['enabled'] ?? false ) !== $reg['enabled'] ) {
		flush_rewrite_rules();
	}

	wp_send_json_success( [
		'key'  => $key,
		'name' => $name,
		'msg'  => $is_new ? __( 'Group created', 'milieus' ) : __( 'Group updated', 'milieus' ),
	] );
} );

add_action( 'wp_ajax_milieus_role_delete', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'milieus_role', 'nonce' );

	$key = sanitize_key( $_POST['key'] ?? '' );
	if ( ! $key ) wp_send_json_error( 'no key' );

	if ( in_array( $key, milieus_reserved_roles(), true ) ) {
		wp_send_json_error( 'cannot delete built-in group' );
	}

	$default = get_option( 'default_role', 'subscriber' );
	$users = get_users( [ 'role' => $key, 'fields' => 'ID' ] );
	foreach ( $users as $uid ) {
		milieus_revoke_member( (int) $uid, $key, $default );
	}

	remove_role( $key );
	milieus_delete_custom_role_meta( $key );
	flush_rewrite_rules();

	wp_send_json_success( [
		'msg' => sprintf(
			/* translators: 1: user count, 2: default group slug. */
			__( 'Group deleted. %1$d member(s) reassigned to %2$s.', 'milieus' ),
			count( $users ),
			$default
		),
	] );
} );

add_action( 'wp_ajax_milieus_default_role_save', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'milieus_role', 'nonce' );

	$value = sanitize_key( wp_unslash( $_POST['value'] ?? '' ) );
	if ( ! $value ) wp_send_json_error( 'no value' );

	global $wp_roles;
	if ( ! $wp_roles ) $wp_roles = wp_roles();
	if ( ! isset( $wp_roles->roles[ $value ] ) ) wp_send_json_error( 'unknown group' );

	update_option( 'default_role', $value );
	wp_send_json_success( [ 'msg' => __( 'Saved', 'milieus' ) ] );
} );

/** Sanitize a hex color (#abc or #aabbcc). Returns '' if invalid. */
function milieus_sanitize_hex( string $hex ): string {
	$hex = trim( $hex );
	return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $hex ) ? $hex : '';
}
