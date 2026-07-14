<?php
/**
 * Simulate plugin boot from repo (no WordPress). Run: php tools/simulate-boot.php
 */
$root = dirname( __DIR__ );

define( 'ABSPATH', $root . '/fake-wp/' );
define( 'POLYMART_AI_PLUGIN_DIR', $root . '/' );
define( 'POLYMART_AI_PLUGIN_FILE', $root . '/polymart-ai.php' );
define( 'POLYMART_AI_PLUGIN_BASENAME', 'PolyMartAI/polymart-ai.php' );
define( 'POLYMART_AI_VERSION', '1.0.10' );

// Minimal WP stubs used at boot.
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'http://localhost/wp-content/plugins/PolyMartAI/'; }
function plugin_basename( $file ) { return 'PolyMartAI/polymart-ai.php'; }
function polymart_ai_is_admin() { return true; }
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() { return true; }
}
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function register_deactivation_hook( ...$args ) {}
function load_plugin_textdomain( ...$args ) {}
function wp_doing_ajax() { return false; }

set_error_handler( static function ( $severity, $message, $file, $line ) {
	throw new ErrorException( $message, 0, $severity, $file, $line );
} );

try {
	require $root . '/includes/class-autoloader.php';
	PolymartAI\Autoloader::register();

	$boot = array(
		'PolymartAI\\Plugin',
		'PolymartAI\\Activity_Logger',
		'PolymartAI\\Translation\\Post_Translator',
		'PolymartAI\\Translation\\Pipeline\\Async_Translator',
		'PolymartAI\\Routing\\Url_Router',
		'PolymartAI\\Translation\\AI\\AI_Client',
		'PolymartAI\\Activity_Logger\\Job_Action_Scheduler',
		'PolymartAI\\Translation\\Pipeline\\Universal_Translator',
		'PolymartAI\\Translation\\Storefront\\Layout_Guard',
	);

	foreach ( $boot as $class ) {
		if ( ! class_exists( $class, true ) ) {
			echo "FAIL: class not loaded: {$class}\n";
			exit( 1 );
		}
		echo "OK: {$class}\n";
	}

	// Instantiate plugin (loads all hooks).
	require $root . '/includes/class-plugin.php';
	PolymartAI\Plugin::instance();
	echo "BOOT OK\n";
} catch ( Throwable $e ) {
	echo "FATAL: " . $e->getMessage() . "\n";
	echo $e->getFile() . ':' . $e->getLine() . "\n";
	exit( 1 );
}
