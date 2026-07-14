<?php
/**
 * Translate Persian wp_options on translated storefront URLs.
 *
 * Uses per-option filters registered after bootstrap to avoid alloptions recursion.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation\Content;

use PolymartAI\Routing\Url_Router;

use PolymartAI\Translation\AI\Persian_Detector;
use PolymartAI\Translation\Storefront\Runtime_String_Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Option_Translator
 */
final class Option_Translator {

	/**
	 * Options that must never be auto-translated.
	 *
	 * @var string[]
	 */
	private static $skipped_exact = array(
		'active_plugins',
		'cron',
		'rewrite_rules',
		'wp_user_roles',
		'wp_attachment_pages_enabled',
		'WPLANG',
		'blog_charset',
		'db_version',
		'home',
		'siteurl',
		'admin_email',
		'upload_path',
		'upload_url_path',
		'recently_edited',
		'secret_key',
		'auth_key',
		'auth_salt',
		'logged_in_key',
		'logged_in_salt',
		'nonce_key',
		'nonce_salt',
		'permalink_structure',
		'category_base',
		'tag_base',
	);

	/**
	 * Whether storefront option filters are registered.
	 *
	 * @var bool
	 */
	private static $hooks_registered = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'maybe_register_option_filters' ), 20 );
	}

	/**
	 * Register per-option filters only on translated storefront requests.
	 *
	 * @return void
	 */
	public function maybe_register_option_filters() {
		if ( self::$hooks_registered || ! $this->should_intercept() ) {
			return;
		}

		self::$hooks_registered = true;

		add_filter( 'option_blogname', array( $this, 'filter_option_value' ), 20, 1 );
		add_filter( 'option_blogdescription', array( $this, 'filter_option_value' ), 20, 1 );
	}

	/**
	 * Translate a single option value when Persian text is detected.
	 *
	 * @param mixed $value Option value.
	 * @return mixed
	 */
	public function filter_option_value( $value ) {
		if ( ! is_string( $value ) || ! Persian_Detector::contains_persian( $value ) ) {
			return $value;
		}

		return Runtime_String_Translator::translate(
			$value,
			Url_Router::get_current_language(),
			'option'
		);
	}

	/**
	 * Whether an option name should be excluded from translation.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	public static function should_skip_option( $option ) {
		if ( in_array( $option, self::$skipped_exact, true ) ) {
			return true;
		}

		if ( 0 === strpos( $option, '_transient_' ) || 0 === strpos( $option, '_site_transient_' ) ) {
			return true;
		}

		if ( 0 === strpos( $option, 'polymart_ai_' ) ) {
			return true;
		}

		if ( 0 === strpos( $option, 'widget_' ) && substr( $option, -8 ) === '_sidebar' ) {
			return true;
		}

		/**
		 * Filter whether a wp_option should be excluded from runtime translation.
		 *
		 * @param bool   $skip   Whether to skip.
		 * @param string $option Option name.
		 */
		return (bool) apply_filters( 'polymart_ai_skip_option_translation', false, $option );
	}

	/**
	 * Whether option filters should run.
	 *
	 * @return bool
	 */
	private function should_intercept() {
		if ( $this->is_system_request_uri() || is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		return Url_Router::is_translated_request();
	}

	/**
	 * Detect admin, REST, and AJAX requests from the URI before WP constants exist.
	 *
	 * @return bool
	 */
	private function is_system_request_uri() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$uri = wp_unslash( $_SERVER['REQUEST_URI'] );

		return false !== strpos( $uri, '/wp-json/' )
			|| false !== strpos( $uri, '/wp-admin/' )
			|| false !== strpos( $uri, 'admin-ajax.php' );
	}
}
