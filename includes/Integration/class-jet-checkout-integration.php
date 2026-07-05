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
use PolymartAI\Translation\Persian_Detector;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Runtime_String_Translator;
use PolymartAI\Translation\UI_String_Registry;
use PolymartAI\Translation\WooCommerce_Translator;

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
	 * Remaining on-demand AI slots for one JetCheckout REST response.
	 *
	 * @var int
	 */
	private static $checkout_sync_budget = 0;

	/**
	 * Whether the current translation call may use a checkout sync AI slot.
	 *
	 * @var bool
	 */
	private static $force_checkout_sync_ai = false;

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
		if ( ! class_exists( '\Jet_Checkout' ) ) {
			return;
		}

		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'bootstrap_rest_context' ), 1, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'filter_rest_response' ), 10, 3 );
		add_filter( 'jet_checkout_frontend_payload', array( __CLASS__, 'filter_frontend_payload' ), 10, 2 );
		add_filter( 'jet_checkout_cart_data', array( __CLASS__, 'filter_cart_data' ), 10, 1 );
		add_action( 'jet_checkout_after_order_created', array( __CLASS__, 'stamp_order_language' ), 10, 1 );
		add_filter( 'jet_checkout_order_received_redirect', array( __CLASS__, 'prefix_order_received_url' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_bootstrap_language_from_order' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_prefixed_order_url' ), 2 );
		add_filter( 'polymart_ai_allow_pending_string_queue', array( __CLASS__, 'allow_checkout_pending_queue' ) );
		add_filter( 'polymart_ai_allow_runtime_ai_translation', array( __CLASS__, 'filter_force_checkout_sync_ai' ) );
		add_filter( 'polymart_ai_ui_string_scan_extra_entries', array( __CLASS__, 'collect_ui_string_entries' ) );
		add_filter( 'jet_checkout_uses_iran_address_profile', array( __CLASS__, 'uses_iran_address_profile' ) );
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

		if ( false !== strpos( $route, '/order-received' ) ) {
			self::bootstrap_language_from_order_request( $request );
		}

		return $result;
	}

	/**
	 * Translate JetCheckout REST payloads after handlers run (shipping labels, etc.).
	 *
	 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response Response object.
	 * @param \WP_REST_Server                                     $server  REST server instance.
	 * @param \WP_REST_Request                                    $request Request object.
	 * @return \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed
	 */
	public static function filter_rest_response( $response, $server, $request ) {
		unset( $server );

		if ( ! $request instanceof \WP_REST_Request || ! $response instanceof \WP_REST_Response ) {
			return $response;
		}

		$route = (string) $request->get_route();

		if ( 0 !== strpos( $route, '/' . self::REST_NAMESPACE ) ) {
			return $response;
		}

		if ( false !== strpos( $route, '/order-received' ) ) {
			self::bootstrap_language_from_order_request( $request );
		} else {
			Url_Router::bootstrap_current_language_from_request();
		}

		if ( ! Url_Router::is_translated_request() ) {
			return $response;
		}

		try {
			$data = $response->get_data();

			if ( ! is_array( $data ) ) {
				return $response;
			}

			if ( ! empty( $data['order'] ) && is_array( $data['order'] ) && false !== strpos( $route, '/order-received' ) ) {
				$lang = sanitize_key( Url_Router::get_current_language() );
				$data['order'] = self::localize_order_received_payload( $data['order'], $lang );
			}

			if ( ! empty( $data['methods'] ) && is_array( $data['methods'] ) ) {
				self::$checkout_sync_budget = 12;
				$data['methods'] = self::localize_checkout_methods( $data['methods'], $route );
				$data['methods'] = self::normalize_shipping_methods_for_json( $data['methods'], $route );
			}

			if ( ! empty( $data['cart'] ) && is_array( $data['cart'] ) ) {
				if ( self::$checkout_sync_budget <= 0 ) {
					self::$checkout_sync_budget = 2;
				}
				$data['cart'] = self::localize_cart_payload( $data['cart'] );
			}

			$response->set_data( $data );
		} catch ( \Throwable $e ) {
			// Never break JetCheckout JSON responses if localization fails.
			unset( $e );
		}

		return $response;
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

		Url_Router::bootstrap_current_language_from_request();
		Plugin::instance()->maybe_boot_storefront_translators();

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
			'address_profile'       => $is_default ? 'iran' : 'international',
		);

		if ( ! $is_default && class_exists( '\Jet_Checkout_International' ) ) {
			$payload['neshan']                          = array( 'enabled' => false );
			$payload['store']['countries']              = \Jet_Checkout_International::get_country_options();
			$payload['store']['phone_dial_codes']       = \Jet_Checkout_International::get_phone_dial_options();
		} elseif ( isset( $payload['neshan'] ) && is_array( $payload['neshan'] ) && $is_default ) {
			// Keep Neshan config from JetCheckout for the default Persian storefront only.
		} elseif ( $is_default ) {
			$payload['store']['address_profile'] = 'iran';
		}

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

		if ( isset( $payload['cart'] ) && is_array( $payload['cart'] ) && Url_Router::is_translated_request() ) {
			self::$checkout_sync_budget = max( self::$checkout_sync_budget, 2 );
			$payload['cart'] = self::localize_cart_payload( $payload['cart'] );
		}

		if (
			! $is_default
			&& Url_Router::is_translated_request()
			&& ! empty( $payload['delivery_calendar'] )
			&& is_array( $payload['delivery_calendar'] )
		) {
			$payload['delivery_calendar'] = self::localize_delivery_calendar( $payload['delivery_calendar'], $lang );
		}

		if ( ! empty( $payload['i18n'] ) && is_array( $payload['i18n'] ) && Url_Router::is_translated_request() ) {
			$payload['i18n'] = self::localize_checkout_i18n( $payload['i18n'], $lang );
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
		if ( ! Url_Router::is_translated_request() ) {
			return $cart_data;
		}

		return self::localize_cart_payload( $cart_data );
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
	 * Resolve a cart line title for the active storefront language without booting
	 * the full WooCommerce translator stack (REST cart refreshes must stay lightweight).
	 *
	 * @param array<string, mixed>      $item      Cart payload row from JetCheckout.
	 * @param array<string, mixed>|null $cart_item Matching WooCommerce cart line when available.
	 * @param string                    $lang      Target language code.
	 * @return string
	 */
	private static function resolve_cart_item_display_name( array $item, $cart_item, $lang ) {
		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang || $lang === Language_Registry::get_default_language_code() ) {
			return isset( $item['name'] ) && is_string( $item['name'] ) ? (string) $item['name'] : '';
		}

		$name = isset( $item['name'] ) && is_string( $item['name'] ) ? trim( $item['name'] ) : '';

		$product = null;

		if ( is_array( $cart_item ) && isset( $cart_item['data'] ) && $cart_item['data'] instanceof \WC_Product ) {
			$product = $cart_item['data'];
		} elseif ( ! empty( $item['product_id'] ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( (int) $item['product_id'] );
		}

		if ( ! $name && $product instanceof \WC_Product ) {
			$name = $product->get_name();
		}

		if ( '' === trim( $name ) ) {
			return '';
		}

		$key = isset( $item['key'] ) ? (string) $item['key'] : '';

		if ( has_filter( 'woocommerce_cart_item_name' ) && is_array( $cart_item ) && '' !== $key ) {
			$filtered = apply_filters( 'woocommerce_cart_item_name', $name, $cart_item, $key );

			if ( is_string( $filtered ) && '' !== trim( $filtered ) && $filtered !== $name ) {
				return $filtered;
			}
		}

		if ( ! $product instanceof \WC_Product ) {
			if ( ! empty( $item['product_id'] ) ) {
				$translated = Post_Translator::resolve_storefront_title( (int) $item['product_id'], $lang, false );

				return '' !== trim( $translated ) ? $translated : $name;
			}

			return $name;
		}

		$post_id = (int) $product->get_id();

		if ( $product->is_type( 'variation' ) ) {
			$translated = Post_Translator::resolve_storefront_title( $post_id, $lang, false );

			if ( '' !== trim( $translated ) ) {
				return $translated;
			}

			$parent_id = (int) $product->get_parent_id();

			if ( $parent_id > 0 ) {
				$translated = Post_Translator::resolve_storefront_title( $parent_id, $lang, false );

				if ( '' !== trim( $translated ) ) {
					return $translated;
				}
			}

			return $name;
		}

		if ( $post_id > 0 ) {
			$translated = Post_Translator::resolve_storefront_title( $post_id, $lang, false );

			if ( '' !== trim( $translated ) ) {
				return $translated;
			}
		}

		return $name;
	}

	/**
	 * Translate JetCheckout cart payload fields for the active storefront language.
	 *
	 * JetCheckout reads product names directly from WC_Product and caches shipping
	 * labels in the customer session, bypassing normal cart template filters.
	 *
	 * @param array<string, mixed> $cart_data Cart payload.
	 * @return array<string, mixed>
	 */
	private static function localize_cart_payload( array $cart_data ) {
		if ( isset( $cart_data['items'] ) && is_array( $cart_data['items'] ) ) {
			$cart_contents = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart() : array();
			$lang          = sanitize_key( Url_Router::get_current_language() );

			foreach ( $cart_data['items'] as $index => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$key       = isset( $item['key'] ) ? (string) $item['key'] : '';
				$cart_item = ( '' !== $key && isset( $cart_contents[ $key ] ) && is_array( $cart_contents[ $key ] ) )
					? $cart_contents[ $key ]
					: null;

				$display_name = self::resolve_cart_item_display_name( $item, $cart_item, $lang );

				if ( '' !== $display_name ) {
					$cart_data['items'][ $index ]['name'] = $display_name;
				}

				if ( ! empty( $item['permalink'] ) && is_string( $item['permalink'] ) ) {
					$cart_data['items'][ $index ]['permalink'] = Url_Router::add_language_prefix_to_url( $item['permalink'] );
				}
			}
		}

		if ( ! empty( $cart_data['shipping_method_label'] ) && is_string( $cart_data['shipping_method_label'] ) ) {
			$chosen_rate = null;
			$chosen_id   = '';

			if ( function_exists( 'WC' ) && WC()->session ) {
				$chosen = WC()->session->get( 'chosen_shipping_methods' );
				$chosen_id = is_array( $chosen ) && isset( $chosen[0] ) ? (string) $chosen[0] : '';

				if ( '' !== $chosen_id ) {
					$chosen_rate = self::resolve_shipping_rate_by_id( $chosen_id );
				}
			}

			$source_label = $cart_data['shipping_method_label'];

			if ( '' !== $chosen_id ) {
				$settings_label = self::resolve_shipping_label_from_rate_id( $chosen_id );

				if ( '' !== $settings_label ) {
					$source_label = $settings_label;
				}
			}

			$cart_data['shipping_method_label'] = self::translate_checkout_config_text(
				static function () use ( $source_label, $chosen_rate ) {
					return WooCommerce_Translator::translate_shipping_rate_label(
						$source_label,
						$chosen_rate
					);
				}
			);
		}

		return $cart_data;
	}

	/**
	 * Translate JetCheckout shipping/payment method REST rows.
	 *
	 * @param array<int, array<string, mixed>> $methods Checkout methods payload.
	 * @param string                           $route   REST route.
	 * @return array<int, array<string, mixed>>
	 */
	private static function localize_checkout_methods( array $methods, $route ) {
		$is_payment = false !== strpos( (string) $route, '/checkout/payment-methods' );

		foreach ( $methods as $index => $method ) {
			if ( ! is_array( $method ) ) {
				continue;
			}

			if ( $is_payment ) {
				$gateway_id = isset( $method['id'] ) ? (string) $method['id'] : '';

				if ( ! empty( $method['title'] ) && is_string( $method['title'] ) ) {
					$methods[ $index ]['title'] = self::translate_checkout_config_text(
						static function () use ( $method, $gateway_id ) {
							return WooCommerce_Translator::translate_payment_gateway_title(
								$method['title'],
								$gateway_id
							);
						}
					);
				}

				if ( ! empty( $method['description'] ) && is_string( $method['description'] ) ) {
					$methods[ $index ]['description'] = self::translate_checkout_config_text(
						static function () use ( $method, $gateway_id ) {
							return WooCommerce_Translator::translate_payment_gateway_description(
								$method['description'],
								$gateway_id
							);
						}
					);
				}

				continue;
			}

			$rate_id = isset( $method['id'] ) ? (string) $method['id'] : '';
			$rate    = self::resolve_shipping_rate_by_id( $rate_id );

			if ( ! empty( $method['label'] ) && is_string( $method['label'] ) ) {
				$source_label = self::resolve_shipping_label_from_rate_id( $rate_id );
				$label        = '' !== $source_label ? $source_label : $method['label'];

				$methods[ $index ]['label'] = self::translate_checkout_config_text(
					static function () use ( $label, $rate ) {
						return WooCommerce_Translator::translate_shipping_rate_label( $label, $rate );
					}
				);
			}

			if ( ! empty( $method['description'] ) && is_string( $method['description'] ) ) {
				$methods[ $index ]['description'] = self::translate_checkout_config_text(
					static function () use ( $method, $rate_id ) {
						return WooCommerce_Translator::translate_shipping_rate_description(
							$method['description'],
							$rate_id
						);
					}
				);
			}
		}

		return $methods;
	}

	/**
	 * Ensure shipping method rows are safe for wp_json_encode in REST responses.
	 *
	 * @param array<int, array<string, mixed>> $methods Checkout methods payload.
	 * @param string                           $route   REST route.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_shipping_methods_for_json( array $methods, $route ) {
		if ( false !== strpos( (string) $route, '/checkout/payment-methods' ) ) {
			return $methods;
		}

		foreach ( $methods as $index => $method ) {
			if ( ! is_array( $method ) ) {
				continue;
			}

			if ( isset( $method['taxes'] ) ) {
				$methods[ $index ]['taxes'] = array_map( 'floatval', (array) $method['taxes'] );
			}

			if ( isset( $method['cost'] ) ) {
				$methods[ $index ]['cost'] = (float) $method['cost'];
			}

			foreach ( array( 'label', 'description', 'id' ) as $string_key ) {
				if ( isset( $method[ $string_key ] ) && ! is_string( $method[ $string_key ] ) && ! is_numeric( $method[ $string_key ] ) ) {
					$methods[ $index ][ $string_key ] = '';
				}
			}
		}

		return $methods;
	}

	/**
	 * Read the configured Persian shipping method title from zone instance settings.
	 *
	 * @param string $rate_id Rate id (e.g. flat_rate:1).
	 * @return string
	 */
	private static function resolve_shipping_label_from_rate_id( $rate_id ) {
		$rate_id = is_string( $rate_id ) ? trim( $rate_id ) : '';

		if ( '' === $rate_id || ! preg_match( '/^([a-z0-9_]+):(\d+)$/i', $rate_id, $matches ) ) {
			return '';
		}

		$settings = get_option( 'woocommerce_' . sanitize_key( $matches[1] ) . '_' . (int) $matches[2] . '_settings', array() );

		if ( ! is_array( $settings ) || empty( $settings['title'] ) || ! is_string( $settings['title'] ) ) {
			return '';
		}

		return trim( wp_strip_all_tags( $settings['title'] ) );
	}

	/**
	 * Resolve a cached WooCommerce shipping rate from its composite id.
	 *
	 * @param string $rate_id Rate id (e.g. flat_rate:1).
	 * @return \WC_Shipping_Rate|null
	 */
	private static function resolve_shipping_rate_by_id( $rate_id ) {
		$rate_id = is_string( $rate_id ) ? trim( $rate_id ) : '';

		if ( '' === $rate_id || ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
			return null;
		}

		$packages = WC()->cart->get_shipping_packages();

		foreach ( array_keys( $packages ) as $package_key ) {
			$package_rates = WC()->session->get( 'shipping_for_package_' . $package_key );

			if ( ! is_array( $package_rates ) || empty( $package_rates['rates'] ) ) {
				continue;
			}

			foreach ( $package_rates['rates'] as $rate ) {
				if ( $rate instanceof \WC_Shipping_Rate && $rate->get_id() === $rate_id ) {
					return $rate;
				}
			}

			break;
		}

		return null;
	}

	/**
	 * Convert JetCheckout delivery calendar rows to Gregorian display for non-Persian storefronts.
	 *
	 * Internal order meta still uses date_jalali; only presentation fields change.
	 *
	 * @param array<int, array<string, mixed>> $calendar Delivery calendar rows.
	 * @param string                           $lang     Active language code.
	 * @param string                           $locale   WordPress locale for the language.
	 * @return array<int, array<string, mixed>>
	 */
	private static function localize_delivery_calendar( array $calendar, $lang ) {
		if ( ! class_exists( '\Jet_Checkout_Jalali' ) ) {
			return $calendar;
		}

		foreach ( $calendar as $index => $day ) {
			if ( ! is_array( $day ) || empty( $day['date_jalali'] ) || ! is_string( $day['date_jalali'] ) ) {
				continue;
			}

			$parts = array_map( 'intval', explode( '-', $day['date_jalali'] ) );

			if ( count( $parts ) < 3 ) {
				continue;
			}

			list( $gy, $gm, $gd ) = \Jet_Checkout_Jalali::jalali_to_gregorian( $parts[0], $parts[1], $parts[2] );

			if ( $gy <= 0 || $gm <= 0 || $gd <= 0 ) {
				continue;
			}

			$formatted = self::format_gregorian_delivery_display( $gy, $gm, $gd, $lang );

			$calendar[ $index ]['date_gregorian'] = sprintf( '%04d-%02d-%02d', $gy, $gm, $gd );
			$calendar[ $index ]['date_display']   = $formatted['date_display'];
			$calendar[ $index ]['day_name']       = $formatted['day_name'];
			$calendar[ $index ]['weekday']        = (int) gmdate( 'w', gmmktime( 12, 0, 0, $gm, $gd, $gy ) );
		}

		return $calendar;
	}

	/**
	 * Format a Gregorian delivery date without wp_date (Persian plugins hijack it to Jalali).
	 *
	 * @param int    $gy   Gregorian year.
	 * @param int    $gm   Gregorian month.
	 * @param int    $gd   Gregorian day.
	 * @param string $lang PolyMart language code.
	 * @return array{day_name: string, date_display: string}
	 */
	private static function format_gregorian_delivery_display( $gy, $gm, $gd, $lang ) {
		$gy   = (int) $gy;
		$gm   = (int) $gm;
		$gd   = (int) $gd;
		$lang = sanitize_key( (string) $lang );
		$ts   = gmmktime( 12, 0, 0, $gm, $gd, $gy );

		if ( class_exists( '\IntlDateFormatter' ) ) {
			$intl_locale = self::get_gregorian_intl_locale( $lang );

			$day_formatter = new \IntlDateFormatter(
				$intl_locale,
				\IntlDateFormatter::NONE,
				\IntlDateFormatter::NONE,
				'UTC',
				\IntlDateFormatter::GREGORIAN,
				'EEEE'
			);
			$full_formatter = new \IntlDateFormatter(
				$intl_locale,
				\IntlDateFormatter::LONG,
				\IntlDateFormatter::NONE,
				'UTC',
				\IntlDateFormatter::GREGORIAN
			);

			$day_name     = $day_formatter->format( $ts );
			$date_display = $full_formatter->format( $ts );

			if ( is_string( $day_name ) && '' !== trim( $day_name ) && is_string( $date_display ) && '' !== trim( $date_display ) ) {
				return array(
					'day_name'     => $day_name,
					'date_display' => $date_display,
				);
			}
		}

		return self::format_gregorian_delivery_display_fallback( $ts, $lang );
	}

	/**
	 * Resolve an Intl locale that forces the Gregorian calendar.
	 *
	 * @param string $lang PolyMart language code.
	 * @return string
	 */
	private static function get_gregorian_intl_locale( $lang ) {
		$map = array(
			'en' => 'en_US',
			'ar' => 'ar_EG',
			'tr' => 'tr_TR',
			'de' => 'de_DE',
			'fr' => 'fr_FR',
			'es' => 'es_ES',
			'ru' => 'ru_RU',
			'zh' => 'zh_CN',
			'ja' => 'ja_JP',
			'ko' => 'ko_KR',
			'it' => 'it_IT',
		);

		$lang = sanitize_key( (string) $lang );

		if ( isset( $map[ $lang ] ) ) {
			return $map[ $lang ];
		}

		$locale = Language_Registry::get_locale_for_language( $lang );

		return '' !== $locale ? $locale : 'en_US';
	}

	/**
	 * English/Arabic fallback when the intl extension is unavailable.
	 *
	 * @param int    $timestamp Unix timestamp (UTC).
	 * @param string $lang      PolyMart language code.
	 * @return array{day_name: string, date_display: string}
	 */
	private static function format_gregorian_delivery_display_fallback( $timestamp, $lang ) {
		$lang = sanitize_key( (string) $lang );
		$w    = (int) gmdate( 'w', $timestamp );
		$gm   = (int) gmdate( 'n', $timestamp );
		$gd   = (int) gmdate( 'j', $timestamp );
		$gy   = (int) gmdate( 'Y', $timestamp );

		if ( 'ar' === $lang ) {
			$days = array( 'الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت' );
			$months = array(
				1  => 'يناير',
				2  => 'فبراير',
				3  => 'مارس',
				4  => 'أبريل',
				5  => 'مايو',
				6  => 'يونيو',
				7  => 'يوليو',
				8  => 'أغسطس',
				9  => 'سبتمبر',
				10 => 'أكتوبر',
				11 => 'نوفمبر',
				12 => 'ديسمبر',
			);
		} else {
			$days = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
			$months = array(
				1  => 'January',
				2  => 'February',
				3  => 'March',
				4  => 'April',
				5  => 'May',
				6  => 'June',
				7  => 'July',
				8  => 'August',
				9  => 'September',
				10 => 'October',
				11 => 'November',
				12 => 'December',
			);
		}

		$day_name = $days[ $w ] ?? gmdate( 'l', $timestamp );
		$month    = $months[ $gm ] ?? gmdate( 'F', $timestamp );

		return array(
			'day_name'     => $day_name,
			'date_display' => sprintf( '%s, %s %d, %d', $day_name, $month, $gd, $gy ),
		);
	}

	/**
	 * Allow background queue writes for JetCheckout (never live AI on page load).
	 *
	 * @param bool $allowed Current decision.
	 * @return bool
	 */
	public static function allow_checkout_pending_queue( $allowed ) {
		if ( $allowed ) {
			return true;
		}

		Url_Router::bootstrap_current_language_from_request();

		if ( ! Url_Router::is_translated_request() ) {
			return false;
		}

		return self::is_jet_checkout_context();
	}

	/**
	 * Permit a bounded number of sync AI calls while localizing JetCheckout REST payloads.
	 *
	 * @param bool $allowed Current decision.
	 * @return bool
	 */
	public static function filter_force_checkout_sync_ai( $allowed ) {
		if ( $allowed ) {
			return true;
		}

		return self::$force_checkout_sync_ai;
	}

	/**
	 * Translate one checkout config field with cache lookup, then optional sync AI.
	 *
	 * @param callable $translator Callback returning the translated string.
	 * @return string
	 */
	private static function translate_checkout_config_text( callable $translator ) {
		Runtime_String_Translator::reset_runtime_ai_gate();

		$use_sync = self::$checkout_sync_budget > 0;

		if ( $use_sync ) {
			self::$checkout_sync_budget--;
			self::$force_checkout_sync_ai = true;
		}

		try {
			$result = (string) $translator();
		} finally {
			if ( $use_sync ) {
				self::$force_checkout_sync_ai = false;
			}
		}

		return $result;
	}

	/**
	 * JetCheckout Iran address profile (Neshan map, plaque/unit) only on the default storefront language.
	 *
	 * @param bool $default Filter default.
	 * @return bool
	 */
	public static function uses_iran_address_profile( $default ) {
		unset( $default );

		Url_Router::bootstrap_current_language_from_request();

		return sanitize_key( Url_Router::get_current_language() ) === Language_Registry::get_default_language_code();
	}

	/**
	 * Whether the current request is serving JetCheckout cart/checkout/thankyou or its REST API.
	 *
	 * @return bool
	 */
	private static function is_jet_checkout_context() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$route = '';

			if ( isset( $_SERVER['REQUEST_URI'] ) ) {
				$route = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
			}

			return false !== strpos( $route, '/wp-json/' . self::REST_NAMESPACE );
		}

		if ( is_admin() || ! function_exists( 'is_page' ) ) {
			return false;
		}

		$post_id = get_queried_object_id();

		if ( $post_id <= 0 ) {
			return false;
		}

		$template = get_page_template_slug( $post_id );

		return is_string( $template ) && false !== strpos( $template, 'jet-checkout' );
	}

	/**
	 * Register WooCommerce shipping/payment configuration strings for UI bulk translation scans.
	 *
	 * @param array<int, array<string, string>> $entries Existing extra entries.
	 * @return array<int, array<string, string>>
	 */
	public static function collect_ui_string_entries( array $entries ) {
		global $wpdb;

		if ( ! isset( $wpdb->options ) ) {
			return $entries;
		}

		$seen = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['msgid'] ) ) {
				continue;
			}

			$context = isset( $entry['context'] ) ? (string) $entry['context'] : '';
			$seen[ md5( $context . '|' . (string) $entry['msgid'] ) ] = true;
		}

		$append = static function ( $msgid, $context ) use ( &$entries, &$seen ) {
			$msgid = is_string( $msgid ) ? trim( wp_strip_all_tags( $msgid ) ) : '';

			if ( '' === $msgid || ! Persian_Detector::contains_persian( $msgid ) ) {
				return;
			}

			$context = is_string( $context ) ? trim( $context ) : '';
			$key     = md5( $context . '|' . $msgid );

			if ( isset( $seen[ $key ] ) ) {
				return;
			}

			$seen[ $key ] = true;
			$entries[]    = array(
				'msgid'   => $msgid,
				'context' => $context,
				'domain'  => 'polymart-ai',
				'plugin'  => 'JetCheckout',
			);
		};

		// Read WooCommerce gateway/shipping settings directly — avoid booting WC during admin scans.
		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce\\_%\\_settings' LIMIT 300"
		);

		if ( ! is_array( $rows ) ) {
			return $entries;
		}

		foreach ( $rows as $row ) {
			if ( ! isset( $row->option_name, $row->option_value ) ) {
				continue;
			}

			$name = (string) $row->option_name;

			if ( preg_match( '/^woocommerce_([a-z0-9_]+)_(\d+)_settings$/', $name, $matches ) ) {
				$method_id   = sanitize_key( $matches[1] );
				$instance_id = (int) $matches[2];
				$rate_id     = $method_id . ':' . $instance_id;
				$settings    = maybe_unserialize( $row->option_value );

				if ( ! is_array( $settings ) ) {
					continue;
				}

				$title = isset( $settings['title'] ) && is_string( $settings['title'] ) ? $settings['title'] : '';
				$desc  = isset( $settings['description'] ) && is_string( $settings['description'] ) ? $settings['description'] : '';

				$append( $title, 'wc_shipping_rate:' . sanitize_key( $rate_id ) );
				$append( $desc, 'wc_shipping_desc:' . sanitize_key( str_replace( ':', '_', $rate_id ) ) );
				continue;
			}

			if ( ! preg_match( '/^woocommerce_([a-z0-9_]+)_settings$/', $name, $matches ) ) {
				continue;
			}

			$gateway_id = sanitize_key( $matches[1] );
			$settings   = maybe_unserialize( $row->option_value );

			if ( ! is_array( $settings ) ) {
				continue;
			}

			$title = isset( $settings['title'] ) && is_string( $settings['title'] ) ? $settings['title'] : '';
			$desc  = isset( $settings['description'] ) && is_string( $settings['description'] ) ? $settings['description'] : '';

			$append( $title, 'wc_payment_gateway:' . $gateway_id . ':title' );
			$append( $desc, 'wc_payment_gateway:' . $gateway_id . ':description' );
		}

		if ( class_exists( '\Jet_Checkout' ) && method_exists( '\Jet_Checkout', 'get_i18n_catalog' ) ) {
			foreach ( \Jet_Checkout::get_i18n_catalog() as $key => $msgid ) {
				$key = sanitize_key( (string) $key );
				$append( (string) $msgid, 'jet_checkout_i18n:' . $key );
			}
		}

		$intl_errors = array(
			'کشور در آدرس تحویل اجباری است.'       => 'jet_checkout_addr:country_required',
			'کشور انتخاب‌شده معتبر نیست.'          => 'jet_checkout_addr:invalid_country',
			'پیش‌شماره تلفن اجباری است.'           => 'jet_checkout_addr:phone_dial_required',
			'شماره تلفن معتبر نیست.'               => 'jet_checkout_addr:invalid_phone',
			'کد پستی معتبر نیست.'                  => 'jet_checkout_addr:invalid_postcode',
		);

		foreach ( $intl_errors as $msgid => $context ) {
			$append( (string) $msgid, (string) $context );
		}

		return $entries;
	}

	/**
	 * Apply stored UI-string translations to the JetCheckout React i18n payload.
	 *
	 * @param array<string, string> $i18n JetCheckout i18n map.
	 * @param string                $lang Target language code.
	 * @return array<string, string>
	 */
	private static function localize_checkout_i18n( array $i18n, $lang ) {
		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang || $lang === Language_Registry::get_default_language_code() ) {
			return $i18n;
		}

		$catalog = class_exists( '\Jet_Checkout' ) && method_exists( '\Jet_Checkout', 'get_i18n_catalog' )
			? \Jet_Checkout::get_i18n_catalog()
			: array();

		foreach ( $i18n as $key => $value ) {
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}

			$source = isset( $catalog[ $key ] ) && is_string( $catalog[ $key ] ) ? $catalog[ $key ] : $value;
			$stored = UI_String_Registry::lookup( $lang, $source, 'jet_checkout_i18n:' . sanitize_key( (string) $key ) );

			if ( null === $stored || '' === trim( $stored ) ) {
				$stored = UI_String_Registry::lookup( $lang, $source, '' );
			}

			if ( is_string( $stored ) && '' !== trim( $stored ) ) {
				$i18n[ $key ] = $stored;
			}
		}

		return $i18n;
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
	 * Bootstrap storefront language for order-received REST from stamped order meta.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return void
	 */
	private static function bootstrap_language_from_order_request( \WP_REST_Request $request ) {
		$order = self::resolve_jet_checkout_order_from_rest_request( $request );

		if ( ! $order ) {
			return;
		}

		$lang = self::get_order_language_code( $order );

		if ( '' === $lang ) {
			return;
		}

		Url_Router::set_current_language( $lang );
		Plugin::instance()->maybe_boot_storefront_translators();
	}

	/**
	 * Localize thank-you order payload (product titles, status label).
	 *
	 * @param array<string, mixed> $order Order payload from JetCheckout REST.
	 * @param string               $lang  Active storefront language.
	 * @return array<string, mixed>
	 */
	private static function localize_order_received_payload( array $order, $lang ) {
		if ( '' === $lang || $lang === Language_Registry::get_default_language_code() ) {
			return $order;
		}

		if ( ! empty( $order['items'] ) && is_array( $order['items'] ) ) {
			foreach ( $order['items'] as $index => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$translated = self::resolve_order_item_display_name( $item, $lang );

				if ( '' !== $translated ) {
					$order['items'][ $index ]['name'] = $translated;
				}
			}
		}

		if ( ! empty( $order['status'] ) && function_exists( 'wc_get_order_status_name' ) ) {
			$order['status_label'] = wc_get_order_status_name( (string) $order['status'] );
		}

		return $order;
	}

	/**
	 * Resolve a translated storefront title for one order line item.
	 *
	 * @param array<string, mixed> $item Line item payload.
	 * @param string               $lang Active storefront language.
	 * @return string
	 */
	private static function resolve_order_item_display_name( array $item, $lang ) {
		$name         = isset( $item['name'] ) && is_string( $item['name'] ) ? trim( $item['name'] ) : '';
		$variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
		$product_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
		$post_id      = $variation_id > 0 ? $variation_id : $product_id;

		if ( $post_id <= 0 ) {
			return $name;
		}

		$translated = Post_Translator::resolve_storefront_title( $post_id, $lang, false );

		if ( '' !== trim( $translated ) ) {
			return $translated;
		}

		if ( $variation_id > 0 && $product_id > 0 && $product_id !== $variation_id ) {
			$parent_title = Post_Translator::resolve_storefront_title( $product_id, $lang, false );

			if ( '' !== trim( $parent_title ) ) {
				return $parent_title;
			}
		}

		return $name;
	}

	/**
	 * Load a JetCheckout order from order-received REST query args.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WC_Order|null
	 */
	private static function resolve_jet_checkout_order_from_rest_request( \WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order_id = (int) $request->get_param( 'order_id' );
		$key      = is_string( $request->get_param( 'key' ) ) ? trim( $request->get_param( 'key' ) ) : '';

		if ( $order_id <= 0 || $key === '' ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		if ( ! hash_equals( $order->get_order_key(), $key ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * Keep thank-you redirects on the same language prefix as checkout.
	 *
	 * @param string    $url   Redirect URL.
	 * @param \WC_Order $order Order object.
	 * @return string
	 */
	public static function prefix_order_received_url( $url, $order ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		$lang = self::get_order_language_code( $order );

		if ( '' !== $lang ) {
			return Url_Router::add_language_prefix_for_code( $url, $lang );
		}

		return Url_Router::add_language_prefix_to_url( $url );
	}

	/**
	 * Restore storefront language on JetCheckout payment handoff and thank-you pages.
	 *
	 * Unprefixed URLs map to the default language unless the order carries a stamped language.
	 *
	 * @return void
	 */
	public static function maybe_bootstrap_language_from_order() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( '' !== self::detect_language_from_request_uri() ) {
			return;
		}

		if ( ! self::is_jet_checkout_order_context_request() ) {
			return;
		}

		$order = self::resolve_jet_checkout_order_from_request();

		if ( ! $order ) {
			return;
		}

		$lang = self::get_order_language_code( $order );

		if ( '' === $lang || $lang === Language_Registry::get_default_language_code() ) {
			return;
		}

		Url_Router::set_current_language( $lang );
		Plugin::instance()->maybe_boot_storefront_translators();
	}

	/**
	 * Redirect unprefixed JetCheckout order URLs to the stamped language prefix.
	 *
	 * @return void
	 */
	public static function maybe_redirect_prefixed_order_url() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( '' !== self::detect_language_from_request_uri() ) {
			return;
		}

		if ( ! self::is_jet_checkout_order_context_request() ) {
			return;
		}

		$order = self::resolve_jet_checkout_order_from_request();

		if ( ! $order ) {
			return;
		}

		$lang = self::get_order_language_code( $order );

		if ( '' === $lang || $lang === Language_Registry::get_default_language_code() ) {
			return;
		}

		$current_url = self::get_current_request_url();
		$target_url  = Url_Router::add_language_prefix_for_code( $current_url, $lang );

		if ( $target_url === $current_url || '' === $target_url ) {
			return;
		}

		wp_safe_redirect( $target_url );
		exit;
	}

	/**
	 * Whether the current request is a JetCheckout thank-you or payment handoff URL.
	 *
	 * @return bool
	 */
	private static function is_jet_checkout_order_context_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing hint.
		if ( ! empty( $_GET['jet_checkout_pay'] ) ) {
			return true;
		}

		return self::is_jet_checkout_thankyou_request();
	}

	/**
	 * Whether the current page is the JetCheckout thank-you template with order query args.
	 *
	 * @return bool
	 */
	private static function is_jet_checkout_thankyou_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing hint.
		if ( empty( $_GET['order_id'] ) || empty( $_GET['key'] ) ) {
			return false;
		}

		if ( ! function_exists( 'get_queried_object_id' ) ) {
			return false;
		}

		$post_id = (int) get_queried_object_id();

		if ( $post_id <= 0 ) {
			return false;
		}

		$template = get_page_template_slug( $post_id );

		return is_string( $template ) && false !== strpos( $template, 'jet-checkout-thankyou' );
	}

	/**
	 * Load a JetCheckout order from order_id + key query args.
	 *
	 * @return \WC_Order|null
	 */
	private static function resolve_jet_checkout_order_from_request() {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- order key is the capability check.
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- order key is the capability check.
		$key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';

		if ( $order_id <= 0 || $key === '' ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		if ( ! hash_equals( $order->get_order_key(), $key ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * Read the stamped PolyMart storefront language from an order.
	 *
	 * @param mixed $order Order object or order ID.
	 * @return string
	 */
	private static function get_order_language_code( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			if ( is_numeric( $order ) && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( (int) $order );
			}
		}

		if ( ! $order instanceof \WC_Order ) {
			return '';
		}

		return sanitize_key( (string) $order->get_meta( '_polymart_ai_lang', true ) );
	}

	/**
	 * Match the request URI against configured language prefixes.
	 *
	 * @return string
	 */
	private static function detect_language_from_request_uri() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$path = wp_parse_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return '';
		}

		foreach ( Language_Registry::get_routed_languages() as $language ) {
			$prefix = sanitize_key( (string) ( $language['url_prefix'] ?? '' ) );
			$code   = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $prefix || '' === $code ) {
				continue;
			}

			if ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) ) {
				return $code;
			}
		}

		return '';
	}

	/**
	 * Build the absolute URL for the current HTTP request.
	 *
	 * @return string
	 */
	private static function get_current_request_url() {
		if ( empty( $_SERVER['HTTP_HOST'] ) ) {
			return '';
		}

		$scheme = is_ssl() ? 'https://' : 'http://';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

		return $scheme . (string) $_SERVER['HTTP_HOST'] . $uri;
	}
}
