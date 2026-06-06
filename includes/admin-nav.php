<?php
/**
 * Milieus by Therum — shared admin tab navigation.
 *
 * Renders a horizontal tab bar at the top of every Milieus admin page
 * so users can navigate between sections without the sidebar.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Enqueue admin CSS on every Milieus admin page so the tab nav (and shared
// design tokens) render correctly even on pages that didn't previously
// enqueue the stylesheet.
add_action( 'admin_enqueue_scripts', function() {
	if ( ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'milieus' ) !== 0 ) return;

	$css = MILIEUS_DIR . 'assets/admin.css';
	if ( file_exists( $css ) ) {
		wp_enqueue_style( 'milieus-admin', MILIEUS_URL . 'assets/admin.css', [], filemtime( $css ) );
	}
}, 5 ); // priority 5 → runs before page-specific enqueues (which just re-register harmlessly)

function milieus_render_tab_nav( string $current_slug = '' ): void {
	$pending = function_exists( 'milieus_pending_count' ) ? milieus_pending_count() : 0;

	$tabs = [
		[
			'slug'  => 'milieus-roles',
			'label' => __( 'Member Groups', 'milieus' ),
			'icon'  => 'dashicons-groups',
		],
		[
			'slug'  => 'milieus-all-members',
			'label' => __( 'All Members', 'milieus' ),
			'icon'  => 'dashicons-admin-users',
		],
		[
			'slug'  => 'milieus-approvals',
			'label' => __( 'Approvals', 'milieus' ),
			'icon'  => 'dashicons-yes-alt',
			'badge' => $pending,
		],
		[
			'slug'  => 'milieus-updates',
			'label' => __( 'Updates', 'milieus' ),
			'icon'  => 'dashicons-update',
		],
		[
			'slug'  => 'milieus-settings',
			'label' => __( 'Settings', 'milieus' ),
			'icon'  => 'dashicons-email-alt',
		],
		[
			'slug'  => 'milieus-webhooks',
			'label' => __( 'Webhooks', 'milieus' ),
			'icon'  => 'dashicons-rest-api',
		],
		[
			'slug'  => 'milieus-audit',
			'label' => __( 'Audit log', 'milieus' ),
			'icon'  => 'dashicons-list-view',
		],
	];

	echo '<nav class="milieus-tab-nav" role="tablist">';
	foreach ( $tabs as $tab ) {
		$active = ( $tab['slug'] === $current_slug );
		$url    = admin_url( 'admin.php?page=' . $tab['slug'] );
		$cls    = 'milieus-tab-nav-item' . ( $active ? ' is-active' : '' );

		echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $cls ) . '" role="tab" aria-selected="' . ( $active ? 'true' : 'false' ) . '">';
		echo '<span class="dashicons ' . esc_attr( $tab['icon'] ) . '"></span>';
		echo '<span>' . esc_html( $tab['label'] ) . '</span>';
		if ( ! empty( $tab['badge'] ) ) {
			echo ' <span class="milieus-tab-badge">' . (int) $tab['badge'] . '</span>';
		}
		echo '</a>';
	}
	echo '</nav>';
}
