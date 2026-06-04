<?php
/**
 * Milieus by Therum — capability bundles.
 *
 * A bundle = a named set of caps you can apply to a role with one click.
 * Roles can mix bundles + individual caps freely.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function milieus_capability_bundles(): array {
	$bundles = [
		'read' => [
			'label' => __( 'Read', 'milieus' ),
			'desc'  => __( "View site, see private posts they're assigned to.", 'milieus' ),
			'caps'  => [ 'read', 'level_0' ],
		],
		'write' => [
			'label' => __( 'Write', 'milieus' ),
			'desc'  => __( 'Draft posts. Edit own drafts. Cannot publish.', 'milieus' ),
			'caps'  => [
				'read', 'edit_posts', 'delete_posts', 'level_0', 'level_1',
			],
		],
		'publish' => [
			'label' => __( 'Publish', 'milieus' ),
			'desc'  => __( 'Write + publish their own posts. Upload media.', 'milieus' ),
			'caps'  => [
				'read', 'edit_posts', 'delete_posts', 'publish_posts',
				'edit_published_posts', 'delete_published_posts',
				'upload_files', 'level_0', 'level_1', 'level_2',
			],
		],
		'edit_any' => [
			'label' => __( 'Edit any', 'milieus' ),
			'desc'  => __( "Editor — edit/publish/delete anyone's content.", 'milieus' ),
			'caps'  => [
				'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts',
				'edit_private_posts', 'publish_posts', 'delete_posts',
				'delete_others_posts', 'delete_published_posts', 'delete_private_posts',
				'read_private_posts', 'edit_pages', 'edit_others_pages',
				'edit_published_pages', 'edit_private_pages', 'publish_pages',
				'delete_pages', 'delete_others_pages', 'delete_published_pages',
				'delete_private_pages', 'read_private_pages', 'manage_categories',
				'moderate_comments', 'upload_files', 'unfiltered_html',
				'level_0', 'level_1', 'level_2', 'level_3', 'level_4', 'level_5',
				'level_6', 'level_7',
			],
		],
		'settings' => [
			'label' => __( 'Settings', 'milieus' ),
			'desc'  => __( 'Manage site options, plugins, themes, users.', 'milieus' ),
			'caps'  => [
				'manage_options', 'manage_categories', 'list_users', 'create_users',
				'edit_users', 'delete_users', 'promote_users', 'remove_users',
				'install_plugins', 'activate_plugins', 'edit_plugins', 'delete_plugins',
				'update_plugins', 'install_themes', 'switch_themes', 'edit_themes',
				'delete_themes', 'update_themes', 'update_core', 'export', 'import',
			],
		],
		'shop_customer' => [
			'label' => __( 'Shop customer', 'milieus' ),
			'desc'  => __( 'WooCommerce — buy products, view own orders.', 'milieus' ),
			'caps'  => [ 'read' ],
		],
		'shop_manager' => [
			'label' => __( 'Shop manager', 'milieus' ),
			'desc'  => __( 'WooCommerce — manage products, orders, coupons.', 'milieus' ),
			'caps'  => [
				'read', 'view_admin_dashboard', 'read_private_pages', 'read_private_posts',
				'edit_users', 'edit_posts', 'edit_pages', 'edit_published_posts',
				'edit_published_pages', 'publish_posts', 'publish_pages',
				'delete_posts', 'delete_pages', 'delete_published_posts',
				'delete_published_pages', 'delete_others_posts', 'delete_others_pages',
				'edit_others_posts', 'edit_others_pages', 'manage_categories',
				'manage_links', 'moderate_comments', 'unfiltered_html', 'upload_files',
				'export', 'import', 'list_users', 'manage_woocommerce',
				'view_woocommerce_reports', 'edit_product', 'read_product',
				'delete_product', 'edit_products', 'edit_others_products',
				'publish_products', 'read_private_products', 'delete_products',
				'delete_private_products', 'delete_published_products',
				'delete_others_products', 'edit_private_products',
				'edit_published_products', 'manage_product_terms', 'edit_product_terms',
				'delete_product_terms', 'assign_product_terms',
			],
		],
	];

	return apply_filters( 'milieus_capability_bundles', $bundles );
}
