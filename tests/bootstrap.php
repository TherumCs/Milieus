<?php
/**
 * Milieus test bootstrap.
 *
 * We don't load the full WP test suite — instead Brain Monkey stubs the
 * WP functions our code calls. Tests focus on pure data-layer logic
 * (cap resolution, duration math, group defaults, etc.) so they run fast
 * and don't need a database.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) )   define( 'ABSPATH', '/tmp/' );
if ( ! defined( 'WPINC' ) )     define( 'WPINC', 'wp-includes' );
if ( ! defined( 'DAY_IN_SECONDS' ) )   define( 'DAY_IN_SECONDS',   86400 );
if ( ! defined( 'WEEK_IN_SECONDS' ) )  define( 'WEEK_IN_SECONDS',  604800 );
if ( ! defined( 'MONTH_IN_SECONDS' ) ) define( 'MONTH_IN_SECONDS', 2592000 );
if ( ! defined( 'YEAR_IN_SECONDS' ) )  define( 'YEAR_IN_SECONDS',  31536000 );
if ( ! defined( 'HOUR_IN_SECONDS' ) )  define( 'HOUR_IN_SECONDS',  3600 );
if ( ! defined( 'MINUTE_IN_SECONDS' ) )define( 'MINUTE_IN_SECONDS',60 );
if ( ! defined( 'MB_IN_BYTES' ) )      define( 'MB_IN_BYTES', 1024 * 1024 );

if ( ! defined( 'MILIEUS_VERSION' ) )  define( 'MILIEUS_VERSION', '0.0.0-test' );
if ( ! defined( 'MILIEUS_FILE' ) )     define( 'MILIEUS_FILE', __DIR__ . '/../milieus.php' );
if ( ! defined( 'MILIEUS_DIR' ) )      define( 'MILIEUS_DIR', __DIR__ . '/../' );
if ( ! defined( 'MILIEUS_URL' ) )      define( 'MILIEUS_URL', 'https://example.com/milieus/' );

// Set up Brain Monkey for each test
\Brain\Monkey\setUp();

// Stub the wp_parse_args / apply_filters / add_filter / etc. before include.
require_once __DIR__ . '/wp-stubs.php';

// Now safe to include the pure data files.
require_once __DIR__ . '/../includes/bundles.php';
require_once __DIR__ . '/../includes/roles-engine.php';
require_once __DIR__ . '/../includes/expiry.php';
