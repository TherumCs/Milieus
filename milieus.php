<?php
/**
 * Plugin Name:       Milieus by Therum
 * Plugin URI:        https://therum.studio/plugins/milieus
 * Description:       Member groups for WordPress. Bundle users into named groups — Friends & Family, VIP, beta testers — each with their own capabilities, optional WooCommerce discount, expiry, custom registration link, and members list.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Therum Creative Studios
 * Author URI:        https://therum.studio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       milieus
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MILIEUS_VERSION', '1.1.0' );
define( 'MILIEUS_FILE', __FILE__ );
define( 'MILIEUS_DIR', plugin_dir_path( __FILE__ ) );
define( 'MILIEUS_URL', plugin_dir_url( __FILE__ ) );

require_once MILIEUS_DIR . 'includes/bundles.php';
require_once MILIEUS_DIR . 'includes/roles-engine.php';
require_once MILIEUS_DIR . 'includes/expiry.php';
require_once MILIEUS_DIR . 'includes/members.php';
require_once MILIEUS_DIR . 'includes/registration.php';
require_once MILIEUS_DIR . 'includes/ajax.php';
require_once MILIEUS_DIR . 'includes/admin-caps-heal.php';
require_once MILIEUS_DIR . 'includes/wc-pricing.php';
require_once MILIEUS_DIR . 'includes/admin-page.php';
require_once MILIEUS_DIR . 'includes/updates.php';

// Activation: schedule daily expiry sweep, flush rewrites for /register/{slug}.
register_activation_hook( __FILE__, function() {
	if ( ! wp_next_scheduled( 'milieus_expire_sweep' ) ) {
		wp_schedule_event( time() + 60, 'daily', 'milieus_expire_sweep' );
	}
	milieus_register_rewrites();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, function() {
	wp_clear_scheduled_hook( 'milieus_expire_sweep' );
	flush_rewrite_rules();
} );
