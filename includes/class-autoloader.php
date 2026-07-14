<?php
/**
 * PSR-4-style autoloader for plugin classes.
 *
 * Maps:
 * - PolymartAI\Plugin              → includes/class-plugin.php
 * - PolymartAI\Routing\Url_Router  → includes/Routing/class-url-router.php
 * - PolymartAI\Translation\AI\AI_Client → includes/Translation/AI/class-ai-client.php
 * - PolymartAI\Activity_Logger\Job_Action_Scheduler → includes/Activity_Logger/class-job-action-scheduler.php
 *
 * @package PolymartAI
 */

namespace PolymartAI;

defined( 'ABSPATH' ) || exit;

/**
 * Class Autoloader
 */
final class Autoloader {

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Autoload classes within the PolymartAI namespace.
	 *
	 * @param string $class Fully qualified class name.
	 * @return void
	 */
	public static function autoload( $class ) {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$parts          = explode( '\\', $relative_class );
		$class_name     = array_pop( $parts );
		$file_name      = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

		if ( ! empty( $parts ) ) {
			$subdir = implode( '/', $parts );
			$file   = POLYMART_AI_PLUGIN_DIR . 'includes/' . $subdir . '/' . $file_name;
		} else {
			$file = POLYMART_AI_PLUGIN_DIR . 'includes/' . $file_name;
		}

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
