<?php
/**
 * Plugin Name:       Milieus by Therum
 * Plugin URI:        https://therum.studio/plugins/milieus
 * Description:       Custom user roles built from capability bundles plus individual caps. Optional WooCommerce role-based pricing — apply a discount % to any role, automatically deducted at checkout.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Therum Creative Studios
 * Author URI:        https://therum.studio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       milieus
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MILIEUS_VERSION', '1.0.0' );
define( 'MILIEUS_FILE', __FILE__ );
define( 'MILIEUS_DIR', plugin_dir_path( __FILE__ ) );
define( 'MILIEUS_URL', plugin_dir_url( __FILE__ ) );

require_once MILIEUS_DIR . 'includes/bundles.php';
require_once MILIEUS_DIR . 'includes/roles-engine.php';
require_once MILIEUS_DIR . 'includes/ajax.php';
require_once MILIEUS_DIR . 'includes/admin-caps-heal.php';
require_once MILIEUS_DIR . 'includes/wc-pricing.php';
require_once MILIEUS_DIR . 'includes/admin-page.php';
