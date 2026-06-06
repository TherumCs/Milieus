<?php
/**
 * Milieus by Therum — WooCommerce compatibility declarations.
 *
 * Declares HPOS (High-Performance Order Storage) compatibility so WC stops
 * showing the "incompatible plugins" admin notice. All our WC code uses
 * wc_get_order() + order method calls (no direct wp_posts access), so we're
 * already HPOS-safe — this just announces it.
 *
 * Also declares cart/checkout block compatibility (WC's modern checkout
 * uses blocks; pricing hooks we use are block-safe).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'before_woocommerce_init', function() {
	if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) return;
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables',     MILIEUS_FILE, true );
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks',    MILIEUS_FILE, true );
} );
