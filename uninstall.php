<?php
/**
 * Milieus by Therum — uninstall cleanup.
 *
 * Removes every custom role created through Milieus, reassigning their users
 * to the current default role first, then deletes the stored role metadata.
 * Built-in WordPress + WooCommerce roles are never touched.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

const MILIEUS_ROLES_OPTION = 'milieus_custom_roles';

$reserved = [
	'administrator', 'editor', 'author', 'contributor', 'subscriber',
	'customer', 'shop_manager',
];

$default = get_option( 'default_role', 'subscriber' );
$custom  = (array) get_option( MILIEUS_ROLES_OPTION, [] );

foreach ( array_keys( $custom ) as $role_key ) {
	if ( in_array( $role_key, $reserved, true ) ) continue;

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

delete_option( MILIEUS_ROLES_OPTION );
