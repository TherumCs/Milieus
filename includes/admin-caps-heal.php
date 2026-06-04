<?php
/**
 * Milieus by Therum — administrator capability self-heal.
 *
 * Some plugins (security plugins, role editors, page builders, third-party
 * customization) can silently strip capabilities from the administrator role.
 * That breaks admin pages that gate on caps like `manage_options`.
 *
 * This module restores any missing core admin caps on every admin page load.
 * Cheap — just an array read; only writes when something is actually missing.
 *
 * Disable by defining MILIEUS_DISABLE_CAPS_HEAL = true before the plugin loads.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( defined( 'MILIEUS_DISABLE_CAPS_HEAL' ) && MILIEUS_DISABLE_CAPS_HEAL ) return;

function milieus_admin_required_caps(): array {
	// Source: wp-admin/includes/schema.php — full default administrator set.
	return [
		'switch_themes', 'edit_themes', 'activate_plugins', 'edit_plugins',
		'edit_users', 'edit_files', 'manage_options', 'moderate_comments',
		'manage_categories', 'manage_links', 'upload_files', 'import',
		'unfiltered_html', 'edit_posts', 'edit_others_posts', 'edit_published_posts',
		'publish_posts', 'edit_pages', 'read', 'level_10', 'level_9', 'level_8',
		'level_7', 'level_6', 'level_5', 'level_4', 'level_3', 'level_2',
		'level_1', 'level_0', 'edit_others_pages', 'edit_published_pages',
		'publish_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages',
		'delete_posts', 'delete_others_posts', 'delete_published_posts',
		'delete_private_posts', 'edit_private_posts', 'read_private_posts',
		'delete_private_pages', 'edit_private_pages', 'read_private_pages',
		'delete_users', 'create_users', 'unfiltered_upload', 'edit_dashboard',
		'update_plugins', 'delete_plugins', 'install_plugins', 'update_themes',
		'install_themes', 'update_core', 'list_users', 'remove_users',
		'promote_users', 'edit_theme_options', 'delete_themes', 'export',
	];
}

function milieus_heal_admin_caps(): void {
	$role = get_role( 'administrator' );
	if ( ! $role ) {
		add_role( 'administrator', 'Administrator', array_fill_keys( milieus_admin_required_caps(), true ) );
		$role = get_role( 'administrator' );
		if ( ! $role ) return;
	}

	$missing = [];
	foreach ( milieus_admin_required_caps() as $cap ) {
		if ( ! $role->has_cap( $cap ) ) {
			$role->add_cap( $cap );
			$missing[] = $cap;
		}
	}

	if ( in_array( 'manage_options', $missing, true ) && function_exists( 'error_log' ) ) {
		error_log( '[Milieus] Restored missing administrator capabilities: ' . implode( ', ', $missing ) );
	}
}

// Priority 5 so this fires before most other admin_init hooks.
add_action( 'admin_init', 'milieus_heal_admin_caps', 5 );
add_action( 'wp_login',   'milieus_heal_admin_caps' );
