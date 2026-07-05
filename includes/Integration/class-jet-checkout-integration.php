<?php
/**
 * PolyMartAI ↔ JetCheckout integration (multilingual checkout + currency).
 *
 * @package PolymartAI
 */

namespace PolymartAI\Integration;

use PolymartAI\Frontend\Currency;
use PolymartAI\Language_Registry;
use PolymartAI\Plugin;
use PolymartAI\Routing\Url_Router;

defined( 'ABSPATH' ) || exit;

/**
 * Class Jet_Checkout_Integration
 */
final class Jet_Checkout_Integration {

	/**
	 * JetCheckout REST namespace slug.
	 */
	const REST_NAMESPACE = 'jet-checkout/v1';

	/**
	 * Schedule integration hooks after JetCheckout has bootstrapped.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'register_hooks' ), 25 );
	}

	/**
	 * Register integration hooks when JetCheckout is active.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		if ( ! class_exists( 'Jet_Checkout' ) ) {
			return;
		}

		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'bootstrap_rest_context' ), 1, 3 );
		add_filter( 'jet_checkout_frontend_payload', array( __CLASS__, 'filter_frontend_payload' ), 10, 2 );
		add_filter( 'jet_checkout_cart_data', array( __CLASS__, 'filter_cart_data' ), 10, 1 );
		add_action( 'jet_checkout_after_order_created', array( __CLASS__, 'stamp_order_language' ), 10, 1 );
		add_filter( 'jet_checkout_order_received_redirect', array( __CLASS__, 'prefix_order_received_url' ), 10, 2 );
	}

	/**
	 * Whether the current HTTP request targets JetCheckout REST routes.
	 *
	 * @return bool
	 */
	private static function is_jet_checkout_rest_request() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] );

		return false !== strpos( $uri, '/wp-json/' . self::REST_NAMESPACE );
	}

	/**
	 * Bootstrap language + storefront translators before JetCheckout REST handlers run.
	 *
	 * @param mixed            $result  Response to replace.
	 * @param \WP_REST_Server  $server  REST server instance.
	 * @param \WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public static function bootstrap_rest_context( $result, $server, $request ) {
		unset( $server );

		if ( ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		$route = (string) $request->get_route();

		if ( 0 !== strpos( $route, '/' . self::REST_NAMESPACE ) ) {
			return $result;
		}

		Url_Router::bootstrap_current_language_from_request();
		Plugin::instance()->maybe_boot_storefront_translators();

		return $result;
	}

	/**
	 * Enrich JetCheckout frontend payload with PolyMart storefront context.
	 *
	 * @param array<string, mixed> $payload   Existing payload.
	 * @param string               $page_type Page type slug.
	 * @return array<string, mixed>
	 */
	public static function filter_frontend_payload( array $payload, $page_type ) {
		unset( $page_type );

		$lang = sanitize_key( Url_Router::get_current_language() );
		$language = Language_Registry::get_language( $lang );
		$direction = 'ltr' === ( $language['direction'] ?? 'rtl' ) ? 'ltr' : 'rtl';
		$locale = Language_Registry::get_locale_for_language( $lang );
		$is_default = $lang === Language_Registry::get_default_language_code();

		$payload['store'] = array(
			'lang'                  => $lang,
			'locale'                => $locale,
			'direction'             => $direction,
			'uses_persian_digits'   => Language_Registry::uses_persian_digits( $lang ),
			'require_persian_names' => $is_default,
			'is_translated'         => Url_Router::is_translated_request(),
			'currency'              => self::get_currency_context(),
		);

		foreach ( array( 'shop_url', 'checkout_url', 'cart_url' ) as $url_key ) {
			if ( ! empty( $payload[ $url_key ] ) && is_string( $payload[ $url_key ] ) ) {
				$payload[ $url_key ] = Url_Router::add_language_prefix_to_url( $payload[ $url_key ] );
			}
		}

		if ( isset( $payload['checkout'] ) && is_array( $payload['checkout'] ) ) {
			foreach ( array( 'checkout_url', 'cart_url' ) as $url_key ) {
				if ( ! empty( $payload['checkout'][ $url_key ] ) && is_string( $payload['checkout'][ $url_key ] ) ) {
					$payload['checkout'][ $url_key ] = Url_Router::add_language_prefix_to_url( $payload['checkout'][ $url_key ] );
				}
			}
		}

		if ( isset( $payload['cart']['items'] ) && is_array( $payload['cart']['items'] ) ) {
			$payload['cart']['items'] = self::prefix_cart_item_permalinks( $payload['cart']['items'] );
		}

		return $payload;
	}

	/**
	 * Prefix permalinks inside live cart REST payloads.
	 *
	 * @param array<string, mixed> $cart_data Cart payload.
	 * @return array<string, mixed>
	 */
	public static function filter_cart_data( array $cart_data ) {
		if ( isset( $cart_data['items'] ) && is_array( $cart_data['items'] ) ) {
			$cart_data['items'] = self::prefix_cart_item_permalinks( $cart_data['items'] );
		}

		return $cart_data;
	}

	/**
	 * Build WooCommerce currency context for the React checkout app.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_currency_context() {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 0;

		return array(
			'code'                => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'symbol'              => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '',
			'decimals'            => $decimals,
			'decimal_separator'   => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
			'thousand_separator'  => function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',',
			'converted'           => Currency::should_convert(),
			'number_locale'       => Language_Registry::uses_persian_digits() ? 'fa-IR' : 'en-US',
		);
	}

	/**
	 * Prefix product permalinks inside cart payload items.
	 *
	 * @param array<int, array<string, mixed>> $items Cart items.
	 * @return array<int, array<string, mixed>>
	 */
	private static function prefix_cart_item_permalinks( array $items ) {
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || empty( $item['permalink'] ) || ! is_string( $item['permalink'] ) ) {
				continue;
			}

			$items[ $index ]['permalink'] = Url_Router::add_language_prefix_to_url( $item['permalink'] );
		}

		return $items;
	}

	/**
	 * Persist storefront language on orders placed via JetCheckout.
	 *
	 * @param \WC_Order $order Order object.
	 * @return void
	 */
	public static function stamp_order_language( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$lang = sanitize_key( Url_Router::get_current_language() );

		if ( '' === $lang ) {
			return;
		}

		$order->update_meta_data( '_polymart_ai_lang', $lang );
	}

	/**
	 * Keep thank-you redirects on the same language prefix as checkout.
	 *
	 * @param string    $url   Redirect URL.
	 * @param \WC_Order $order Order object.
	 * @return string
	 */
	public static function prefix_order_received_url( $url, $order ) {
		unset( $order );

		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		return Url_Router::add_language_prefix_to_url( $url );
	}
}
