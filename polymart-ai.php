<?php
/**
 * Plugin Name:       مترجم پلی‌مارت
 * Plugin URI:        https://raisseo.ir
 * Description:       ترجمه دوزبانه هوش مصنوعی و نمایش دو ارزی برای فروشگاه‌های ووکامرس با قالب وودمارت.
 * Version:           1.0.10
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            مهدی شمس
 * Author URI:        https://raisseo.ir
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       polymart-ai
 * Domain Path:       /languages
 *
 * @package PolymartAI
 */

defined( 'ABSPATH' ) || exit;

if ( ! headers_sent() && isset( $_SERVER['REQUEST_URI'] ) ) {
	$polymart_ai_request_uri = (string) $_SERVER['REQUEST_URI'];

	if (
		false !== strpos( $polymart_ai_request_uri, 'admin-ajax.php' )
		|| false !== strpos( $polymart_ai_request_uri, 'wc-ajax=' )
	) {
		ob_start();
	}
}

define( 'POLYMART_AI_VERSION', '1.0.10' );

/*
 * Single-product isolation (set in wp-config.php to override).
 *
 * Nuclear bypass: skip ALL storefront modules on single product pages.
 * Per-module bypass: skip individual modules only on single product pages.
 *
 * Current test build: only Universal_Translator is bypassed.
 */
if ( ! defined( 'POLYMART_AI_NUCLEAR_PRODUCT_BYPASS' ) ) {
	define( 'POLYMART_AI_NUCLEAR_PRODUCT_BYPASS', false );
}

if ( ! defined( 'POLYMART_AI_BYPASS_UNIVERSAL' ) ) {
	define( 'POLYMART_AI_BYPASS_UNIVERSAL', false );
}

if ( ! defined( 'POLYMART_AI_BYPASS_FRONTEND_INTERCEPTOR' ) ) {
	define( 'POLYMART_AI_BYPASS_FRONTEND_INTERCEPTOR', false );
}

if ( ! defined( 'POLYMART_AI_BYPASS_WOOCOMMERCE' ) ) {
	define( 'POLYMART_AI_BYPASS_WOOCOMMERCE', false );
}

if ( ! defined( 'POLYMART_AI_BYPASS_WOODMART' ) ) {
	define( 'POLYMART_AI_BYPASS_WOODMART', false );
}

if ( ! defined( 'POLYMART_AI_BYPASS_OPTION' ) ) {
	define( 'POLYMART_AI_BYPASS_OPTION', false );
}

if ( ! defined( 'POLYMART_AI_BYPASS_COMMENT' ) ) {
	define( 'POLYMART_AI_BYPASS_COMMENT', false );
}

if ( ! defined( 'POLYMART_AI_SHUTDOWN_TRANSLATE' ) ) {
	define( 'POLYMART_AI_SHUTDOWN_TRANSLATE', false );
}

if ( ! defined( 'POLYMART_AI_TRACE_PRODUCT' ) ) {
	define( 'POLYMART_AI_TRACE_PRODUCT', false );
}
define( 'POLYMART_AI_PLUGIN_FILE', __FILE__ );
define( 'POLYMART_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'POLYMART_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'POLYMART_AI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once POLYMART_AI_PLUGIN_DIR . 'includes/class-autoloader.php';

PolymartAI\Autoloader::register();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once POLYMART_AI_PLUGIN_DIR . 'includes/CLI/class-variation-sync-command.php';

	\WP_CLI::add_command(
		'polymart-ai resync-variations',
		'PolymartAI\\CLI\\Variation_Sync_Command'
	);
}

// Optional admin debug tool (not shipped in production builds).
$polymart_ai_production_debug = POLYMART_AI_PLUGIN_DIR . 'tools/production-debug.php';
if ( is_admin() && is_readable( $polymart_ai_production_debug ) ) {
	require_once $polymart_ai_production_debug;
}

register_activation_hook( __FILE__, array( 'PolymartAI\Routing\Url_Router', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PolymartAI\Routing\Url_Router', 'deactivate' ) );
register_activation_hook( __FILE__, array( 'PolymartAI\Frontend\Currency', 'maybe_schedule_cron' ) );
register_deactivation_hook( __FILE__, array( 'PolymartAI\Frontend\Currency', 'unschedule_cron' ) );

/**
 * Returns the main plugin instance.
 *
 * @return PolymartAI\Plugin
 */
function polymart_ai() {
	return PolymartAI\Plugin::instance();
}

polymart_ai();
