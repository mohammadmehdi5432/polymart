<?php
/**
 * Persist storefront language for AJAX sub-requests (cart fragments, wc-ajax).
 *
 * @package PolymartAI\Frontend
 */

namespace PolymartAI\Frontend;

use PolymartAI\Routing\Url_Router;


defined( 'ABSPATH' ) || exit;

/**
 * Class Storefront_Language_Persistence
 */
final class Storefront_Language_Persistence {

	/**
	 * Cookie name for the active storefront language code.
	 */
	const COOKIE_NAME = 'polymart_ai_lang';

	/**
	 * HTTP header mirrored by storefront JavaScript on AJAX calls.
	 */
	const HEADER_NAME = 'X-Polymart-Lang';

	/**
	 * Cookie lifetime (one year).
	 */
	const COOKIE_TTL = YEAR_IN_SECONDS;

	/**
	 * Register persistence hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'send_headers', array( __CLASS__, 'maybe_set_language_cookie' ), 0 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_ajax_language_script' ), 1 );
		add_filter( 'woocommerce_ajax_get_endpoint', array( __CLASS__, 'append_language_query_arg' ), 15, 2 );
	}

	/**
	 * Mirror the resolved storefront language into a first-party cookie.
	 *
	 * @return void
	 */
	public static function maybe_set_language_cookie() {
		if ( headers_sent() ) {
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$lang = sanitize_key( Url_Router::get_current_language() );

		if ( '' === $lang || ! Url_Router::is_valid_language_code( $lang ) ) {
			return;
		}

		$current = isset( $_COOKIE[ self::COOKIE_NAME ] )
			? sanitize_key( wp_unslash( (string) $_COOKIE[ self::COOKIE_NAME ] ) )
			: '';

		if ( $current === $lang ) {
			return;
		}

		$path = (string) apply_filters( 'polymart_ai_language_cookie_path', COOKIEPATH ?: '/' );

		$secure = is_ssl();

		/**
		 * Filter cookie attributes for the storefront language persistence cookie.
		 *
		 * @param array<string, mixed> $options setcookie() options.
		 * @param string               $lang    Active language code.
		 */
		$options = apply_filters(
			'polymart_ai_language_cookie_options',
			array(
				'expires'  => time() + (int) self::COOKIE_TTL,
				'path'     => $path,
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => $secure,
				'httponly' => false,
				'samesite' => 'Lax',
			),
			$lang
		);

		setcookie( self::COOKIE_NAME, $lang, $options );

		// Same-request reads (wc-ajax bootstrapped in the same HTTP round-trip).
		$_COOKIE[ self::COOKIE_NAME ] = $lang;
	}

	/**
	 * Inject language context into wc-ajax / admin-ajax requests from the browser.
	 *
	 * @return void
	 */
	public static function enqueue_ajax_language_script() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$script_path = POLYMART_AI_PLUGIN_DIR . 'assets/storefront/language-context.js';

		if ( ! is_readable( $script_path ) ) {
			return;
		}

		wp_enqueue_script(
			'polymart-ai-language-context',
			POLYMART_AI_PLUGIN_URL . 'assets/storefront/language-context.js',
			array(),
			(string) filemtime( $script_path ),
			false
		);

		wp_localize_script(
			'polymart-ai-language-context',
			'polymartAiLang',
			array(
				'lang'         => Url_Router::get_current_language(),
				'headerName'   => self::HEADER_NAME,
				'cookieName'   => self::COOKIE_NAME,
				'queryVar'     => Url_Router::QUERY_VAR,
				'isTranslated' => Url_Router::is_translated_request(),
			)
		);
	}

	/**
	 * Append polymart_lang to WooCommerce AJAX endpoints as a routing fallback.
	 *
	 * @param string $endpoint Endpoint URL.
	 * @param string $request  Endpoint slug.
	 * @return string
	 */
	public static function append_language_query_arg( $endpoint, $request ) {
		unset( $request );

		if ( ! is_string( $endpoint ) || '' === $endpoint ) {
			return $endpoint;
		}

		$lang = sanitize_key( Url_Router::get_current_language() );

		if ( '' === $lang || ! Url_Router::is_translated_request() ) {
			return $endpoint;
		}

		return add_query_arg( Url_Router::QUERY_VAR, $lang, $endpoint );
	}
}
