<?php
/**
 * Milieus by Therum — uninstall cleanup.
 *
 * Removes every artifact the plugin creates so uninstalling leaves no orphan
 * data behind. Built-in WordPress + WooCommerce roles are never touched.
 *
 * Cleanup order:
 *   1. Reassign custom-role users to the site default, remove roles
 *   2. Drop the audit table
 *   3. Delete every user_meta key the plugin sets (assigned/expires/source/
 *      pending/welcome flags), per role
 *   4. Delete every option Milieus writes
 *   5. Clear the daily expiry-sweep cron
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

$reserved = [
	'administrator', 'editor', 'author', 'contributor', 'subscriber',
	'customer', 'shop_manager',
];

$default = get_option( 'default_role', 'subscriber' );
$custom  = (array) get_option( 'milieus_custom_roles', [] );
$role_keys = array_diff( array_keys( $custom ), $reserved );

// 1. Reassign users + remove custom roles ──────────────────────────────
foreach ( $role_keys as $role_key ) {
	$users = get_users( [ 'role' => $role_key, 'fields' => 'ID' ] );
	foreach ( $users as $uid ) {
		$user = get_userdata( $uid );
		if ( $user ) {
			$user->remove_role( $role_key );
			$user->add_role( $default );
		}
	}
	remove_role( $role_key );
}

// 2. Drop audit table ──────────────────────────────────────────────────
$audit_table = $wpdb->prefix . 'milieus_audit';
$wpdb->query( "DROP TABLE IF EXISTS {$audit_table}" );

// 3. Delete user meta — per-role assigned/expires/source/welcome plus
//    the single pending-group key. LIKE escaped — these prefixes are
//    fixed strings but esc_like is correct hygiene.
$meta_prefixes = [
	'_milieus_assigned_',
	'_milieus_expires_',
	'_milieus_source_',
	'_milieus_welcome_pending_',
];
foreach ( $meta_prefixes as $prefix ) {
	$like = $wpdb->esc_like( $prefix ) . '%';
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$like
	) );
}
delete_metadata( 'user', 0, '_milieus_pending_group', '', true );

// Also any extras the registration form captured (milieus_name, milieus_phone, etc.)
$extras_like = $wpdb->esc_like( 'milieus_' ) . '%';
$wpdb->query( $wpdb->prepare(
	"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
	$extras_like
) );

// 4. Delete options ────────────────────────────────────────────────────
$options = [
	'milieus_custom_roles',
	'milieus_webhooks',
	'milieus_notifications',
	'milieus_onboarded',
	'milieus_audit_install_failed',
	'milieus_audit_table_version',
];
foreach ( $options as $opt ) {
	delete_option( $opt );
}

// Delete cached transients (release-cache, login/signup rate-limits)
$transient_like = $wpdb->esc_like( '_transient_milieus_' ) . '%';
$wpdb->query( $wpdb->prepare(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
	$transient_like
) );
$timeout_like = $wpdb->esc_like( '_transient_timeout_milieus_' ) . '%';
$wpdb->query( $wpdb->prepare(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
	$timeout_like
) );

// 5. Clear scheduled cron ──────────────────────────────────────────────
wp_clear_scheduled_hook( 'milieus_expire_sweep' );
