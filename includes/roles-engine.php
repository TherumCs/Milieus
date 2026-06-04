<?php
/**
 * Milieus by Therum — custom roles store + cap resolver.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const MILIEUS_ROLES_OPTION = 'milieus_custom_roles';

function milieus_get_custom_roles(): array {
	return (array) get_option( MILIEUS_ROLES_OPTION, [] );
}

function milieus_save_custom_role( string $key, array $data ): void {
	$roles = milieus_get_custom_roles();
	$roles[ $key ] = $data;
	update_option( MILIEUS_ROLES_OPTION, $roles );
}

function milieus_delete_custom_role_meta( string $key ): void {
	$roles = milieus_get_custom_roles();
	unset( $roles[ $key ] );
	update_option( MILIEUS_ROLES_OPTION, $roles );
}

/**
 * Resolve a final capability set from selected bundles + individual caps.
 * Returns an associative array of cap_name => true.
 */
function milieus_resolve_caps( array $bundles, array $individual_caps ): array {
	$cap_set = [];
	$bundles_def = milieus_capability_bundles();
	foreach ( $bundles as $b ) {
		if ( isset( $bundles_def[ $b ] ) ) {
			foreach ( $bundles_def[ $b ]['caps'] as $c ) $cap_set[ $c ] = true;
		}
	}
	foreach ( $individual_caps as $c ) {
		if ( $c ) $cap_set[ $c ] = true;
	}
	return $cap_set;
}

/**
 * Roles we never allow the user to overwrite or delete.
 */
function milieus_reserved_roles(): array {
	return apply_filters( 'milieus_reserved_roles', [
		'administrator', 'editor', 'author', 'contributor', 'subscriber',
		'customer', 'shop_manager',
	] );
}
