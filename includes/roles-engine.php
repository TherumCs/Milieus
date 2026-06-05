<?php
/**
 * Milieus by Therum — custom groups store + cap resolver.
 *
 * Internally these are still WordPress roles (we use add_role/remove_role,
 * etc.); the user-facing label is "group" because that matches how people
 * actually think about them — Friends & Family, VIP, beta testers.
 *
 * Stored shape (per group, under option MILIEUS_GROUPS_OPTION):
 *   key              string  WP role key
 *   name             string  display name
 *   bundles          array   bundle keys applied
 *   caps             array   resolved cap names (from bundles + individual)
 *   discount         float   WC % discount (0 = off)
 *   expires_at       int     unix ts the group itself auto-deletes (0 = forever)
 *   member_duration  array { value:int, unit:string } default duration each
 *                            new member stays in the group (value=0 = permanent)
 *   reg              array { slug, enabled, logo, heading, lede, brand, color,
 *                            button, extras[], redirect, approval, max_signups,
 *                            bg_kind, bg_solid, bg_grad_1, bg_grad_2, bg_grad_dir,
 *                            bg_image, bg_dim, bg_blur }
 *   updated          int     unix ts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Backward-compat: v1.0.0 stored at 'milieus_custom_roles'. Keep reading it.
const MILIEUS_ROLES_OPTION  = 'milieus_custom_roles';
const MILIEUS_GROUPS_OPTION = 'milieus_custom_roles';

function milieus_get_custom_roles(): array {
	return (array) get_option( MILIEUS_GROUPS_OPTION, [] );
}

// Aliases for the new vocabulary — same data, friendlier name.
function milieus_get_groups(): array {
	return milieus_get_custom_roles();
}

function milieus_get_group( string $key ): ?array {
	$g = milieus_get_groups();
	return $g[ $key ] ?? null;
}

function milieus_save_custom_role( string $key, array $data ): void {
	$roles = milieus_get_custom_roles();
	// Normalize: ensure new fields exist with defaults.
	$data = wp_parse_args( $data, milieus_group_defaults() );
	$roles[ $key ] = $data;
	update_option( MILIEUS_GROUPS_OPTION, $roles );
}

function milieus_save_group( string $key, array $data ): void {
	milieus_save_custom_role( $key, $data );
}

function milieus_delete_custom_role_meta( string $key ): void {
	$roles = milieus_get_custom_roles();
	unset( $roles[ $key ] );
	update_option( MILIEUS_GROUPS_OPTION, $roles );
}

/**
 * Default shape for a group record. Used to migrate v1.0.0 records on read,
 * and to seed new groups on save.
 */
function milieus_group_defaults(): array {
	return [
		'key'             => '',
		'name'            => '',
		'bundles'         => [],
		'caps'            => [],
		'discount'        => 0.0,
		'expires_at'      => 0,
		'member_duration' => [ 'value' => 0, 'unit' => 'days' ], // 0 = permanent
		'reg'             => milieus_reg_defaults(),
		'updated'         => 0,
	];
}

/**
 * Registration-link defaults. Empty/disabled by default; a group becomes a
 * public landing page only when reg.enabled = true and reg.slug is set.
 */
function milieus_reg_defaults(): array {
	return [
		'slug'         => '',
		'enabled'      => false,
		'logo'         => '',
		'heading'      => '',
		'lede'         => '',
		'brand'        => '',  // small uppercase brand mark (defaults to site title)
		'color'        => '',  // hex; empty = use site/default accent
		'button'       => '',  // submit text; empty = "Create account →"
		'extras'       => [],  // array of: name, company, phone, referral, how-heard
		'redirect'     => '',  // post-signup URL
		'approval'     => false,
		'max_signups'  => 0,   // 0 = unlimited
		'signup_count' => 0,
		'bg_kind'      => 'solid', // solid | gradient | image
		'bg_solid'     => '#fafaf9',
		'bg_grad_1'    => '#fde68a',
		'bg_grad_2'    => '#fca5a5',
		'bg_grad_dir'  => '135deg',
		'bg_image'     => '',
		'bg_dim'       => true,
		'bg_blur'      => false,
	];
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
 * Groups (formerly: roles) we never allow the user to overwrite or delete.
 */
function milieus_reserved_roles(): array {
	return apply_filters( 'milieus_reserved_roles', [
		'administrator', 'editor', 'author', 'contributor', 'subscriber',
		'customer', 'shop_manager',
	] );
}

/**
 * Look up a group by its public registration slug. Returns null if no group
 * has that slug enabled.
 */
function milieus_group_by_reg_slug( string $slug ): ?array {
	$slug = sanitize_title( $slug );
	if ( ! $slug ) return null;
	foreach ( milieus_get_groups() as $g ) {
		$reg = $g['reg'] ?? [];
		if ( ! empty( $reg['enabled'] ) && ( $reg['slug'] ?? '' ) === $slug ) {
			return $g;
		}
	}
	return null;
}
