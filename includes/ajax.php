<?php
/**
 * Milieus by Therum — AJAX handlers (save / delete role, set default role).
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
			wp_send_json_error( 'cannot overwrite built-in role: ' . $key );
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

	milieus_save_custom_role( $key, [
		'key'      => $key,
		'name'     => $name,
		'bundles'  => $bundles,
		'caps'     => array_keys( $cap_set ),
		'discount' => $discount,
		'updated'  => time(),
	] );

	wp_send_json_success( [
		'key'  => $key,
		'name' => $name,
		'msg'  => $is_new ? __( 'Role created', 'milieus' ) : __( 'Role updated', 'milieus' ),
	] );
} );

add_action( 'wp_ajax_milieus_role_delete', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'milieus_role', 'nonce' );

	$key = sanitize_key( $_POST['key'] ?? '' );
	if ( ! $key ) wp_send_json_error( 'no key' );

	if ( in_array( $key, milieus_reserved_roles(), true ) ) {
		wp_send_json_error( 'cannot delete built-in role' );
	}

	$default = get_option( 'default_role', 'subscriber' );
	$users = get_users( [ 'role' => $key, 'fields' => 'ID' ] );
	foreach ( $users as $uid ) {
		$user = get_userdata( $uid );
		if ( $user ) {
			$user->remove_role( $key );
			$user->add_role( $default );
		}
	}

	remove_role( $key );
	milieus_delete_custom_role_meta( $key );

	wp_send_json_success( [
		'msg' => sprintf(
			/* translators: 1: user count, 2: default role slug. */
			__( 'Role deleted. %1$d user(s) reassigned to %2$s.', 'milieus' ),
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
	if ( ! isset( $wp_roles->roles[ $value ] ) ) wp_send_json_error( 'unknown role' );

	update_option( 'default_role', $value );
	wp_send_json_success( [ 'msg' => __( 'Saved', 'milieus' ) ] );
} );
