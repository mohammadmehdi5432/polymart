<?php
/**
 * WooCommerce / WoodMart cart fragment compatibility for multilingual storefronts.
 *
 * @package PolymartAI\Frontend
 */

namespace PolymartAI\Frontend;

use PolymartAI\Plugin;
use PolymartAI\Routing\Url_Router;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cart_Fragments_Compat
 */
final class Cart_Fragments_Compat {

	/**
	 * Fragment selector used by WooCommerce cart-fragments.js.
	 */
	const MINI_CART_FRAGMENT_KEY = 'div.widget_shopping_cart_content';

	/**
	 * Register compatibility hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'bootstrap_storefront_cart_ajax_context' ), 1 );
		add_action( 'init', array( __CLASS__, 'boot_translators_for_storefront_cart_ajax' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_early_fragment_cache_guard' ), 9997 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_storefront_cart_script' ), 10001 );

		add_action( 'wp_ajax_polymart_refresh_mini_cart', array( __CLASS__, 'ajax_refresh_mini_cart' ) );
		add_action( 'wp_ajax_nopriv_polymart_refresh_mini_cart', array( __CLASS__, 'ajax_refresh_mini_cart' ) );

		add_filter( 'woocommerce_ajax_get_endpoint', array( __CLASS__, 'prefix_wc_ajax_endpoint' ), 20, 2 );

		foreach ( self::get_fragment_ajax_actions() as $action ) {
			add_action( $action, array( __CLASS__, 'clean_output_buffers' ), -999 );
		}

		add_filter( 'woocommerce_add_to_cart_fragments', array( __CLASS__, 'ensure_mini_cart_fragments' ), 99999 );
		add_filter( 'woocommerce_cart_hash', array( __CLASS__, 'add_language_to_cart_hash' ), 999 );
	}

	/**
	 * AJAX actions that return cart fragment JSON.
	 *
	 * @return string[]
	 */
	private static function get_fragment_ajax_actions() {
		$actions = array(
			'wc_ajax_get_refreshed_fragments',
			'wp_ajax_woocommerce_get_refreshed_fragments',
			'wp_ajax_nopriv_woocommerce_get_refreshed_fragments',
			'wc_ajax_add_to_cart',
			'wp_ajax_woocommerce_add_to_cart',
			'wp_ajax_nopriv_woocommerce_add_to_cart',
			'wc_ajax_remove_from_cart',
			'wp_ajax_woocommerce_remove_from_cart',
			'wp_ajax_nopriv_woocommerce_remove_from_cart',
			'wp_ajax_woodmart_ajax_add_to_cart',
			'wp_ajax_nopriv_woodmart_ajax_add_to_cart',
		);

		/**
		 * Filter WooCommerce AJAX actions that should discard accidental output before JSON.
		 *
		 * @param string[] $actions Hook names.
		 */
		return (array) apply_filters( 'polymart_ai_wc_fragment_ajax_actions', $actions );
	}

	/**
	 * Seed language context before locale / translator boot on cart AJAX sub-requests.
	 *
	 * @return void
	 */
	public static function bootstrap_storefront_cart_ajax_context() {
		if ( ! self::is_storefront_cart_ajax_request() ) {
			return;
		}

		Url_Router::bootstrap_current_language_from_request();
	}

	/**
	 * Boot storefront translators before WooCommerce / WoodMart render cart fragments.
	 *
	 * @return void
	 */
	public static function boot_translators_for_storefront_cart_ajax() {
		if ( ! self::is_storefront_cart_ajax_request() ) {
			return;
		}

		Plugin::instance()->maybe_boot_storefront_translators();
	}

	/**
	 * Clear stale WooCommerce fragment cache before wc-cart-fragments.js mutates the DOM.
	 *
	 * @return void
	 */
	public static function register_early_fragment_cache_guard() {
		if ( is_admin() || ! function_exists( 'WC' ) || is_cart() || is_checkout() ) {
			return;
		}

		if ( ! wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
			return;
		}

		if ( ! wp_script_is( 'wc-cart-fragments', 'enqueued' ) ) {
			wp_enqueue_script( 'wc-cart-fragments' );
		}

		wp_add_inline_script(
			'wc-cart-fragments',
			self::get_early_fragment_cache_guard_js(),
			'before'
		);
	}

	/**
	 * Bust WooCommerce fragment cache when the storefront language changes.
	 *
	 * @param string $hash Cart hash from WooCommerce.
	 * @return string
	 */
	public static function add_language_to_cart_hash( $hash ) {
		if ( ! is_string( $hash ) || '' === $hash ) {
			return $hash;
		}

		$lang = sanitize_key( Url_Router::get_current_language() );

		if ( '' === $lang ) {
			return $hash;
		}

		return md5( $hash . '|polymart_lang:' . $lang );
	}

	/**
	 * Inline JS executed before WooCommerce cart-fragments.js.
	 *
	 * @return string
	 */
	private static function get_early_fragment_cache_guard_js() {
		$lang     = esc_js( sanitize_key( Url_Router::get_current_language() ) );
		$lang_key = esc_js( 'polymart_cart_frag_lang' );

		return <<<JS
(function () {
	try {
		var params = window.wc_cart_fragments_params || window.wd_cart_fragments_params;
		var cfg = window.polymartAiLang || {};
		var pageLang = typeof cfg.lang === 'string' ? cfg.lang.trim() : '{$lang}';
		var langKey = '{$lang_key}';
		var match = document.cookie.match(/(?:^|; )woocommerce_items_in_cart=(\\d+)/);
		var count = match ? parseInt(match[1], 10) : 0;

		if (!params || !window.sessionStorage) {
			return;
		}

		var storedLang = sessionStorage.getItem(langKey);

		if (storedLang && pageLang && storedLang !== pageLang) {
			sessionStorage.removeItem(params.fragment_name);

			if (params.cart_hash_key) {
				sessionStorage.removeItem(params.cart_hash_key);
			}

			sessionStorage.removeItem('wc_cart_created');
		}

		if (pageLang) {
			sessionStorage.setItem(langKey, pageLang);
		}

		if (count <= 0) {
			return;
		}

		var raw = sessionStorage.getItem(params.fragment_name);

		if (!raw) {
			return;
		}

		var frags = JSON.parse(raw);
		var html = frags && frags['div.widget_shopping_cart_content'];

		if (typeof html !== 'string') {
			sessionStorage.removeItem(params.fragment_name);
			return;
		}

		var markers = ['shopping-cart-widget-body','woocommerce-mini-cart','wd-empty-mini-cart','woocommerce-mini-cart__empty-message','cart-list','mini_cart_item'];
		var valid = false;

		for (var i = 0; i < markers.length; i++) {
			if (html.indexOf(markers[i]) !== -1) {
				valid = true;
				break;
			}
		}

		var stale = !valid
			|| html.indexOf('wd-empty-mini-cart') !== -1
			|| html.indexOf('woocommerce-mini-cart__empty-message') !== -1
			|| (count > 0 && html.indexOf('mini_cart_item') === -1);

		if (stale) {
			sessionStorage.removeItem(params.fragment_name);

			if (params.cart_hash_key) {
				sessionStorage.removeItem(params.cart_hash_key);
			}
		}
	} catch (e) {
		// Ignore malformed sessionStorage payloads.
	}
})();
JS;
	}

	/**
	 * Enqueue WoodMart side-cart compatibility script on the storefront.
	 *
	 * @return void
	 */
	public static function enqueue_storefront_cart_script() {
		if ( is_admin() || ! function_exists( 'WC' ) ) {
			return;
		}

		$script_path = POLYMART_AI_PLUGIN_DIR . 'assets/storefront/cart-compat.js';

		if ( ! is_readable( $script_path ) ) {
			return;
		}

		$deps = array( 'jquery' );

		if ( wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
			$deps[] = 'wc-cart-fragments';
		}

		if ( wp_script_is( 'woodmart-theme', 'registered' ) ) {
			$deps[] = 'woodmart-theme';
		}

		wp_enqueue_script(
			'polymart-ai-cart-compat',
			POLYMART_AI_PLUGIN_URL . 'assets/storefront/cart-compat.js',
			$deps,
			(string) filemtime( $script_path ),
			true
		);

		wp_localize_script(
			'polymart-ai-cart-compat',
			'polymartAiCartCompat',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => 'polymart_refresh_mini_cart',
				'nonce'   => wp_create_nonce( 'polymart_refresh_mini_cart' ),
			)
		);
	}

	/**
	 * Return translated mini-cart fragments via admin-ajax (same transport as WoodMart add-to-cart).
	 *
	 * @return void
	 */
	public static function ajax_refresh_mini_cart() {
		check_ajax_referer( 'polymart_refresh_mini_cart' );

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error(
				array(
					'message' => 'Cart unavailable.',
				),
				500
			);
		}

		self::clean_output_buffers();

		Url_Router::bootstrap_current_language_from_request();
		Plugin::instance()->maybe_boot_storefront_translators();

		$fragments = self::ensure_mini_cart_fragments(
			array(
				self::MINI_CART_FRAGMENT_KEY => self::render_mini_cart_fragment_wrapper(),
			)
		);

		wp_send_json_success(
			array(
				'fragments' => $fragments,
				'cart_hash' => WC()->cart->get_cart_hash(),
			)
		);
	}

	/**
	 * Prefix WooCommerce AJAX endpoints with the active language segment on translated URLs.
	 *
	 * Ensures wc-ajax=get_refreshed_fragments carries /en/ in the request URI so
	 * Url_Router does not depend solely on the HTTP referer.
	 *
	 * @param string $endpoint Endpoint URL.
	 * @param string $request  Endpoint slug.
	 * @return string
	 */
	public static function prefix_wc_ajax_endpoint( $endpoint, $request ) {
		unset( $request );

		if ( ! is_string( $endpoint ) || '' === $endpoint || ! Url_Router::is_translated_request() ) {
			return $endpoint;
		}

		return Url_Router::add_language_prefix_to_url( $endpoint );
	}

	/**
	 * Discard accidental output (BOM, notices) before WooCommerce sends JSON fragments.
	 *
	 * @return void
	 */
	public static function clean_output_buffers() {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	/**
	 * Guarantee mini-cart and WoodMart fragment keys are never blank on translated requests.
	 *
	 * @param array<string, string> $fragments Cart fragment map.
	 * @return array<string, string>
	 */
	public static function ensure_mini_cart_fragments( $fragments ) {
		if ( ! is_array( $fragments ) ) {
			$fragments = array();
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $fragments;
		}

		$fragments = self::ensure_woodmart_fragments( $fragments );

		$key = self::MINI_CART_FRAGMENT_KEY;

		if ( self::mini_cart_fragment_needs_render( $fragments, $key ) ) {
			$fragments[ $key ] = self::render_mini_cart_fragment_wrapper();
			$fragments           = self::ensure_woodmart_fragments( $fragments );
		}

		return $fragments;
	}

	/**
	 * Whether the mini-cart fragment HTML is missing or structurally empty.
	 *
	 * @param array<string, string> $fragments Fragment map.
	 * @param string                $key       Mini-cart selector.
	 * @return bool
	 */
	private static function mini_cart_fragment_needs_render( array $fragments, $key ) {
		if ( ! isset( $fragments[ $key ] ) || ! is_string( $fragments[ $key ] ) ) {
			return true;
		}

		$html = $fragments[ $key ];

		if ( self::mini_cart_html_is_structurally_valid( $html ) ) {
			return false;
		}

		// Empty cart still renders WoodMart's empty-state wrapper; a bare div is invalid.
		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			return true;
		}

		return ! WC()->cart->is_empty();
	}

	/**
	 * Detect valid WoodMart / WooCommerce mini-cart markup inside a fragment.
	 *
	 * @param string $html Fragment HTML.
	 * @return bool
	 */
	private static function mini_cart_html_is_structurally_valid( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return false;
		}

		$markers = array(
			'shopping-cart-widget-body',
			'woocommerce-mini-cart',
			'wd-empty-mini-cart',
			'woocommerce-mini-cart__empty-message',
			'cart-list',
			'mini_cart_item',
		);

		foreach ( $markers as $marker ) {
			if ( false !== strpos( $html, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render the standard WooCommerce mini-cart fragment wrapper.
	 *
	 * @return string
	 */
	private static function render_mini_cart_fragment_wrapper() {
		if ( function_exists( 'woodmart_cart_data' ) && apply_filters( 'woodmart_update_fragments_fix', true ) ) {
			$woodmart_fragments = woodmart_cart_data( array() );

			if (
				is_array( $woodmart_fragments )
				&& isset( $woodmart_fragments[ self::MINI_CART_FRAGMENT_KEY ] )
				&& is_string( $woodmart_fragments[ self::MINI_CART_FRAGMENT_KEY ] )
				&& self::mini_cart_html_is_structurally_valid( $woodmart_fragments[ self::MINI_CART_FRAGMENT_KEY ] )
			) {
				return $woodmart_fragments[ self::MINI_CART_FRAGMENT_KEY ];
			}
		}

		if ( ! function_exists( 'woocommerce_mini_cart' ) ) {
			return '<div class="widget_shopping_cart_content"></div>';
		}

		ob_start();
		woocommerce_mini_cart();
		$mini_cart = ob_get_clean();

		if ( ! is_string( $mini_cart ) ) {
			$mini_cart = '';
		}

		return '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>';
	}

	/**
	 * Restore WoodMart cart count/subtotal fragment keys when another filter dropped them.
	 *
	 * @param array<string, string> $fragments Fragment map.
	 * @return array<string, string>
	 */
	private static function ensure_woodmart_fragments( array $fragments ) {
		if ( ! function_exists( 'woodmart_cart_data' ) || ! apply_filters( 'woodmart_update_fragments_fix', true ) ) {
			return $fragments;
		}

		$needs_woodmart = ! isset( $fragments['span.wd-cart-number_wd'] )
			|| ! isset( $fragments['span.wd-cart-subtotal_wd'] );

		if ( ! $needs_woodmart ) {
			return $fragments;
		}

		$patched = woodmart_cart_data( $fragments );

		return is_array( $patched ) ? $patched : $fragments;
	}

	/**
	 * Whether the current request updates the public mini-cart (wc-ajax or WoodMart admin-ajax).
	 *
	 * @return bool
	 */
	private static function is_storefront_cart_ajax_request() {
		if ( self::is_wc_ajax_request() ) {
			return true;
		}

		if ( ! wp_doing_ajax() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing hint.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';

		return in_array(
			$action,
			array(
				'polymart_refresh_mini_cart',
				'woodmart_ajax_add_to_cart',
				'woocommerce_add_to_cart',
				'woocommerce_get_refreshed_fragments',
				'woocommerce_remove_from_cart',
			),
			true
		);
	}

	/**
	 * Whether the current request is a WooCommerce wc-ajax sub-request.
	 *
	 * @return bool
	 */
	private static function is_wc_ajax_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing hint.
		if ( isset( $_GET['wc-ajax'] ) && is_string( $_GET['wc-ajax'] ) && '' !== $_GET['wc-ajax'] ) {
			return true;
		}

		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		return false !== strpos( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), 'wc-ajax=' );
	}
}
