<?php
/**
 * Smart currency conversion for non-Persian storefront languages.
 *
 * Persian (default) prices stay in Toman; English/Arabic routes display and
 * checkout in USD using a cached BrsApi.ir exchange rate.
 *
 * @package PolymartAI
 */

namespace PolymartAI\Frontend;

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Routing\Url_Router;

defined( 'ABSPATH' ) || exit;

/**
 * Class Currency
 */
final class Currency {

	/**
	 * WP-Cron hook for daily rate refresh.
	 */
	const CRON_HOOK = 'polymart_ai_refresh_usd_rate';

	/**
	 * Transient key for cached rate (24h TTL).
	 */
	const RATE_TRANSIENT_KEY = 'polymart_ai_usd_rate_cache';

	/**
	 * Option key for last successful rate snapshot (persistent fallback).
	 */
	const RATE_OPTION_KEY = 'polymart_ai_usd_rate_snapshot';

	/**
	 * BrsApi.ir gold & currency endpoint (USD rate is parsed from the currency list).
	 */
	const API_URL = 'https://Api.BrsApi.ir/Market/Gold_Currency.php';

	/**
	 * Transient lifetime — 24 hours.
	 */
	const RATE_TTL = DAY_IN_SECONDS;

	/**
	 * In-request memoization for the active conversion rate.
	 *
	 * @var float|null
	 */
	private static $rate_cache = null;

	/**
	 * Guard against recursive price filter calls.
	 *
	 * @var bool
	 */
	private static $converting = false;

	/**
	 * When true, price filters are bypassed (used during meta sync reads).
	 *
	 * @var bool
	 */
	private static $sync_context = false;

	/**
	 * Prevent recursive price HTML rendering.
	 *
	 * @var bool
	 */
	private static $rendering_price_html = false;

	/**
	 * Whether cart line prices were already converted for this request.
	 *
	 * @var bool
	 */
	private static $cart_usd_prices_applied = false;

	/**
	 * Toggle sync context for raw Toman meta reads.
	 *
	 * @param bool $active Active state.
	 * @return void
	 */
	public static function set_sync_context( $active ) {
		self::$sync_context = (bool) $active;
	}

	/**
	 * Whether storefront hooks were registered.
	 *
	 * @var bool
	 */
	private static $hooks_registered = false;

	/**
	 * Bootstrap currency subsystem.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_schedule_cron' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_refresh_rate' ) );
		Currency_Price_Sync::init();

		// PolyMartAI loads before WooCommerce alphabetically; defer hook registration.
		add_action( 'plugins_loaded', array( __CLASS__, 'register_storefront_hooks' ), 20 );
	}

	/**
	 * Schedule daily cron when missing.
	 *
	 * @return void
	 */
	public static function maybe_schedule_cron() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
	}

	/**
	 * Clear scheduled cron (deactivation).
	 *
	 * @return void
	 */
	public static function unschedule_cron() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		Currency_Price_Sync::unschedule_sync_cron();
	}

	/**
	 * Register WooCommerce storefront filters.
	 *
	 * @return void
	 */
	public static function register_storefront_hooks() {
		if ( self::$hooks_registered ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		self::$hooks_registered = true;

		$price_hooks = array(
			'woocommerce_product_get_price'                     => 'price',
			'woocommerce_product_get_regular_price'             => 'regular',
			'woocommerce_product_get_sale_price'                => 'sale',
			'woocommerce_product_variation_get_price'           => 'price',
			'woocommerce_product_variation_get_regular_price'   => 'regular',
			'woocommerce_product_variation_get_sale_price'      => 'sale',
		);

		foreach ( $price_hooks as $hook => $type ) {
			add_filter( $hook, function ( $price, $product ) use ( $type ) {
				return self::filter_product_price_by_type( $price, $product, $type );
			}, 999, 2 );
		}

		add_filter( 'woocommerce_coupon_get_amount', array( __CLASS__, 'filter_coupon_amount' ), 999, 1 );
		add_filter( 'woocommerce_coupon_get_minimum_amount', array( __CLASS__, 'filter_coupon_amount' ), 999, 1 );
		add_filter( 'woocommerce_coupon_get_maximum_amount', array( __CLASS__, 'filter_coupon_amount' ), 999, 1 );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'filter_price_html' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_variation_prices', array( __CLASS__, 'filter_variation_prices' ), 999, 3 );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'filter_available_variation' ), 999, 3 );
		add_filter( 'woocommerce_currency', array( __CLASS__, 'filter_currency_code' ), 999 );
		add_filter( 'woocommerce_currency_symbol', array( __CLASS__, 'filter_currency_symbol' ), 999, 2 );
		add_filter( 'wc_price_args', array( __CLASS__, 'filter_price_args' ), 999 );
		add_filter( 'woocommerce_price_num_decimals', array( __CLASS__, 'filter_price_decimals' ), 999 );
		add_filter( 'woocommerce_shipping_rate_cost', array( __CLASS__, 'filter_coupon_amount' ), 999, 1 );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'filter_package_rates' ), 999, 1 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'filter_variation_prices_hash' ), 999, 1 );

		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'sync_cart_line_prices_with_storefront' ), 9999 );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'recalculate_cart_after_session_load' ), 99 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( __CLASS__, 'prepare_cart_fragments_for_storefront' ), 99998 );
	}

	/**
	 * Whether storefront prices should be converted to USD.
	 *
	 * @return bool
	 */
	public static function should_convert() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		if ( ! class_exists( Url_Router::class ) ) {
			return false;
		}

		$current = Url_Router::get_current_language();
		$default = Language_Registry::get_default_language_code();

		return sanitize_key( $current ) !== sanitize_key( $default );
	}

	/**
	 * Read stored BrsApi.ir API key.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		$settings = wp_parse_args(
			get_option( REST_API::OPTION_KEY, array() ),
			REST_API::get_default_settings()
		);

		return trim( (string) ( $settings['currency']['api_key'] ?? '' ) );
	}

	/**
	 * Get the active USD→Toman rate (Toman per 1 USD) from cache/fallback.
	 *
	 * Never calls the external API during normal page loads.
	 *
	 * @return float
	 */
	public static function get_rate() {
		if ( null !== self::$rate_cache ) {
			return self::$rate_cache;
		}

		$cached = get_transient( self::RATE_TRANSIENT_KEY );

		if ( is_array( $cached ) && ! empty( $cached['rate'] ) ) {
			self::$rate_cache = (float) $cached['rate'];
			return self::$rate_cache;
		}

		$snapshot = get_option( self::RATE_OPTION_KEY, array() );

		if ( is_array( $snapshot ) && ! empty( $snapshot['rate'] ) ) {
			self::$rate_cache = (float) $snapshot['rate'];
			return self::$rate_cache;
		}

		self::$rate_cache = 0.0;

		return self::$rate_cache;
	}

	/**
	 * Public rate status for admin REST responses.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_rate_status() {
		$cached    = get_transient( self::RATE_TRANSIENT_KEY );
		$snapshot  = get_option( self::RATE_OPTION_KEY, array() );
		$rate      = self::get_rate();
		$updated   = '';
		$source    = 'none';
		$cache_hit = is_array( $cached ) && ! empty( $cached['rate'] );

		if ( $cache_hit ) {
			$updated = (string) ( $cached['updated_at'] ?? '' );
			$source  = (string) ( $cached['source'] ?? 'cache' );
		} elseif ( is_array( $snapshot ) && ! empty( $snapshot['rate'] ) ) {
			$updated = (string) ( $snapshot['updated_at'] ?? '' );
			$source  = (string) ( $snapshot['source'] ?? 'fallback' );
		}

		return array(
			'rate'               => $rate > 0 ? $rate : null,
			'rate_formatted'     => $rate > 0 ? number_format_i18n( $rate, 0 ) : null,
			'updated_at'         => $updated,
			'updated_at_human'   => '' !== $updated ? self::format_datetime_human( $updated ) : '',
			'source'             => $source,
			'cache_active'       => $cache_hit,
			'api_key_set'        => '' !== self::get_api_key(),
			'cron_scheduled'     => (bool) wp_next_scheduled( self::CRON_HOOK ),
			'cron_next_run'      => self::get_next_cron_run_iso(),
			'cron_next_run_human'=> self::format_datetime_human( self::get_next_cron_run_iso() ),
			'conversion_active'  => self::should_convert(),
			'sync'               => Currency_Price_Sync::get_stats(),
			'sync_job'           => Currency_Price_Sync::get_job(),
		);
	}

	/**
	 * Force-fetch rate from BrsApi.ir and refresh caches.
	 *
	 * @param bool $force When true, bypass transient and always hit the API.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function refresh_rate( $force = true ) {
		unset( $force );

		$previous_rate   = self::get_rate();
		self::$rate_cache = null;

		$api_key = self::get_api_key();

		if ( '' === $api_key ) {
			return new \WP_Error(
				'polymart_ai_currency_missing_api_key',
				__( 'کلید API BrsApi.ir در تنظیمات نرخ ارز وارد نشده است.', 'polymart-ai' ),
				array( 'status' => 400 )
			);
		}

		$response = wp_remote_get(
			add_query_arg( 'key', $api_key, self::API_URL ),
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::handle_refresh_failure( $response );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$message = 429 === $code
				? __( 'API BrsApi.ir محدودیت تعداد درخواست دارد — چند دقیقه بعد دوباره تلاش کنید.', 'polymart-ai' )
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'API BrsApi.ir با کد HTTP %d پاسخ داد.', 'polymart-ai' ),
					$code
				);

			return self::handle_refresh_failure(
				new \WP_Error(
					'polymart_ai_currency_api_http_error',
					$message,
					array( 'status' => 502 )
				)
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return self::handle_refresh_failure(
				new \WP_Error(
					'polymart_ai_currency_invalid_json',
					__( 'پاسخ API BrsApi.ir نامعتبر است.', 'polymart-ai' ),
					array( 'status' => 502 )
				)
			);
		}

		$rate = self::parse_rate_from_response( $body );

		if ( $rate <= 0 ) {
			return self::handle_refresh_failure(
				new \WP_Error(
					'polymart_ai_currency_missing_rate',
					__( 'نرخ دلار در پاسخ API یافت نشد.', 'polymart-ai' ),
					array( 'status' => 502 )
				)
			);
		}

		$updated_at = self::parse_updated_at_from_response( $body );
		$payload    = array(
			'rate'       => $rate,
			'updated_at' => $updated_at,
			'source'     => 'api',
		);

		update_option( self::RATE_OPTION_KEY, $payload, false );
		set_transient( self::RATE_TRANSIENT_KEY, $payload, self::RATE_TTL );

		self::$rate_cache = $rate;

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->calculate_totals();
		}

		$sync_kickoff = Currency_Price_Sync::kickoff_after_rate_refresh( $previous_rate );

		return array_merge(
			$payload,
			array(
				'rate_formatted'   => number_format_i18n( $rate, 0 ),
				'updated_at_human' => self::format_datetime_human( $updated_at ),
				'from_fallback'    => false,
				'sync_kickoff'     => $sync_kickoff,
			)
		);
	}

	/**
	 * Cron callback — refresh rate daily.
	 *
	 * @return void
	 */
	public static function cron_refresh_rate() {
		$result = self::refresh_rate( true );

		if ( is_wp_error( $result ) ) {
			return;
		}

		// kickoff_after_rate_refresh() runs inside refresh_rate(); process as much as cron allows.
		Currency_Price_Sync::run_background_sync( 55 );
	}

	/**
	 * Sync cart line prices with the active storefront currency before cart math.
	 *
	 * USD conversion uses set_price() because display filters do not affect session
	 * totals. When returning to the default (Toman) language, raw meta prices must
	 * be restored — otherwise USD values set on a prior /en/ visit persist in cart.
	 *
	 * @param \WC_Cart $cart Cart instance.
	 * @return void
	 */
	public static function sync_cart_line_prices_with_storefront( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
			return;
		}

		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		$converting = self::should_convert();

		if ( $converting && self::$cart_usd_prices_applied ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof \WC_Product ) {
				continue;
			}

			$product = $cart_item['data'];

			if ( $converting ) {
				$price = self::get_product_usd_price( $product );
			} else {
				$price = self::get_product_stored_toman_price( $product );
			}

			if ( '' === $price || null === $price || ! is_numeric( $price ) ) {
				continue;
			}

			$product->set_price( (float) $price );
		}

		self::$cart_usd_prices_applied = $converting;
	}

	/**
	 * Recalculate cart totals after session load for every storefront language.
	 *
	 * @param \WC_Cart $cart Cart instance.
	 * @return void
	 */
	public static function recalculate_cart_after_session_load( $cart ) {
		unset( $cart );

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		self::$cart_usd_prices_applied = false;
		WC()->cart->calculate_totals();
	}

	/**
	 * Ensure cart fragments use totals for the active storefront language/currency.
	 *
	 * @param array<string, string> $fragments Cart fragment map.
	 * @return array<string, string>
	 */
	public static function prepare_cart_fragments_for_storefront( $fragments ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $fragments;
		}

		self::$cart_usd_prices_applied = false;
		WC()->cart->calculate_totals();

		return $fragments;
	}

	/**
	 * Read the canonical Toman unit price from product meta (cart reset).
	 *
	 * @param \WC_Product $product Product object.
	 * @return float|string
	 */
	public static function get_product_stored_toman_price( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		$raw = get_post_meta( $product->get_id(), '_price', true );

		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return '';
		}

		return (float) $raw;
	}

	/**
	 * Resolve active USD unit price for a cart/catalog product.
	 *
	 * @param \WC_Product $product Product object.
	 * @return float|string Empty when conversion is unavailable.
	 */
	public static function get_product_usd_price( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		$stored = Currency_Price_Sync::get_stored_usd_price( $product, 'price' );

		if ( null !== $stored ) {
			return $stored;
		}

		self::set_sync_context( true );
		$raw = $product->get_price();
		self::set_sync_context( false );

		return self::convert_toman_to_usd( $raw );
	}

	/**
	 * Convert product price using stored USD meta or live rate.
	 *
	 * @param mixed            $price   Raw price.
	 * @param \WC_Product|null $product Product object.
	 * @param string           $type    price|regular|sale.
	 * @return mixed
	 */
	public static function filter_product_price_by_type( $price, $product, $type = 'price' ) {
		if ( self::$sync_context || self::$converting || ! self::should_convert() ) {
			return $price;
		}

		if ( $product instanceof \WC_Product ) {
			$stored = Currency_Price_Sync::get_stored_usd_price( $product, $type );

			if ( null !== $stored ) {
				return $stored;
			}
		}

		return self::convert_toman_to_usd( $price );
	}

	/**
	 * Convert scalar Toman amounts (coupons, shipping).
	 *
	 * @param mixed $amount Toman amount.
	 * @return mixed
	 */
	public static function filter_coupon_amount( $amount ) {
		if ( self::$sync_context || self::$converting || ! self::should_convert() ) {
			return $amount;
		}

		return self::convert_toman_to_usd( $amount );
	}

	/**
	 * Rebuild price HTML in USD on non-Persian storefronts.
	 *
	 * @param string       $html    Original HTML.
	 * @param \WC_Product  $product Product.
	 * @return string
	 */
	public static function filter_price_html( $html, $product ) {
		if ( self::$sync_context || self::$rendering_price_html || ! self::should_convert() || ! $product instanceof \WC_Product ) {
			return $html;
		}

		$stored_html = Currency_Price_Sync::get_stored_usd_price_html( $product );

		if ( '' !== $stored_html && ! self::price_html_contains_toman( $stored_html ) ) {
			return $stored_html;
		}

		self::$rendering_price_html = true;

		if ( $product->is_type( 'variable' ) ) {
			$prices = $product->get_variation_prices( true );
			$min    = ! empty( $prices['price'] ) ? min( $prices['price'] ) : null;
			$max    = ! empty( $prices['price'] ) ? max( $prices['price'] ) : null;

			if ( self::is_displayable_usd_price( $min ) && self::is_displayable_usd_price( $max ) ) {
				$html = (float) $min === (float) $max ? wc_price( $min ) : wc_format_price_range( $min, $max );
			} else {
				$html = self::get_unavailable_price_html();
			}
		} else {
			$regular = Currency_Price_Sync::get_stored_usd_price( $product, 'regular' );
			$sale    = Currency_Price_Sync::get_stored_usd_price( $product, 'sale' );
			$active  = Currency_Price_Sync::get_stored_usd_price( $product, 'price' );

			if ( null === $regular ) {
				$regular = self::convert_toman_to_usd( get_post_meta( $product->get_id(), '_regular_price', true ) );
			}
			if ( null === $sale ) {
				$sale = self::convert_toman_to_usd( get_post_meta( $product->get_id(), '_sale_price', true ) );
			}
			if ( null === $active ) {
				$active = self::convert_toman_to_usd( get_post_meta( $product->get_id(), '_price', true ) );
			}

			if ( $product->is_on_sale() && self::is_displayable_usd_price( $sale ) && self::is_displayable_usd_price( $regular ) ) {
				$html = wc_format_sale_price( $regular, $sale );
			} elseif ( self::is_displayable_usd_price( $active ) ) {
				$html = wc_price( $active );
			} elseif ( self::is_displayable_usd_price( $regular ) ) {
				$html = wc_price( $regular );
			} else {
				$html = self::get_unavailable_price_html();
			}
		}

		self::$rendering_price_html = false;

		return $html;
	}

	/**
	 * Swap variation price arrays with USD values.
	 *
	 * @param array<string, array<int, float>> $prices   Price arrays.
	 * @param \WC_Product_Variable             $product  Variable product.
	 * @param bool                             $for_display Display context.
	 * @return array<string, array<int, float>>
	 */
	public static function filter_variation_prices( $prices, $product, $for_display ) {
		unset( $product, $for_display );

		if ( self::$sync_context || ! self::should_convert() || ! is_array( $prices ) ) {
			return $prices;
		}

		foreach ( array( 'price', 'regular_price', 'sale_price' ) as $key ) {
			if ( empty( $prices[ $key ] ) || ! is_array( $prices[ $key ] ) ) {
				continue;
			}

			foreach ( $prices[ $key ] as $variation_id => $amount ) {
				$variation = wc_get_product( $variation_id );
				$type      = 'price';

				if ( 'regular_price' === $key ) {
					$type = 'regular';
				} elseif ( 'sale_price' === $key ) {
					$type = 'sale';
				}

				if ( $variation instanceof \WC_Product ) {
					$stored = Currency_Price_Sync::get_stored_usd_price( $variation, $type );

					if ( null !== $stored ) {
						$prices[ $key ][ $variation_id ] = $stored;
						continue;
					}
				}

				$prices[ $key ][ $variation_id ] = (float) self::convert_toman_to_usd( $amount );
			}
		}

		return $prices;
	}

	/**
	 * Ensure variation AJAX payloads expose USD prices on translated storefronts.
	 *
	 * @param array               $data      Variation data.
	 * @param \WC_Product         $product   Parent product.
	 * @param \WC_Product_Variation $variation Variation product.
	 * @return array
	 */
	public static function filter_available_variation( $data, $product, $variation ) {
		unset( $product );

		if ( self::$sync_context || ! self::should_convert() || ! is_array( $data ) || ! $variation instanceof \WC_Product_Variation ) {
			return $data;
		}

		$stored_html = Currency_Price_Sync::get_stored_usd_price_html( $variation );

		if ( '' !== $stored_html && ! self::price_html_contains_toman( $stored_html ) ) {
			$data['price_html'] = $stored_html;
		}

		$price    = Currency_Price_Sync::get_stored_usd_price( $variation, 'price' );
		$regular  = Currency_Price_Sync::get_stored_usd_price( $variation, 'regular' );
		$sale     = Currency_Price_Sync::get_stored_usd_price( $variation, 'sale' );

		if ( null !== $price ) {
			$data['display_price'] = $price;
			$data['price']         = (string) $price;
		}

		if ( null !== $regular ) {
			$data['display_regular_price'] = $regular;
			$data['regular_price']       = (string) $regular;
		}

		if ( null !== $sale ) {
			$data['display_sale_price'] = $sale;
			$data['sale_price']         = (string) $sale;
		}

		return $data;
	}

	/**
	 * Convert a Toman amount to USD for the active storefront language.
	 *
	 * @param mixed $price Raw price from WooCommerce.
	 * @return mixed
	 */
	public static function convert_toman_to_usd( $price ) {
		if ( self::$converting || ! self::should_convert() ) {
			return $price;
		}

		if ( '' === $price || null === $price ) {
			return $price;
		}

		$rate = self::get_rate();

		if ( $rate <= 0 ) {
			// Never leak raw Toman amounts on translated storefronts.
			return '';
		}

		self::$converting = true;

		$converted = round( (float) $price / $rate, 2 );

		self::$converting = false;

		return $converted;
	}

	/**
	 * Placeholder HTML when USD conversion is unavailable on translated storefronts.
	 *
	 * @return string
	 */
	private static function get_unavailable_price_html() {
		return '<span class="polymart-price-unavailable amount">' . esc_html__( 'قیمت در دسترس نیست', 'polymart-ai' ) . '</span>';
	}

	/**
	 * Whether a numeric storefront price is displayable in USD.
	 *
	 * @param mixed $price Candidate price.
	 * @return bool
	 */
	private static function is_displayable_usd_price( $price ) {
		return '' !== $price && null !== $price && is_numeric( $price );
	}

	/**
	 * Back-compat alias.
	 *
	 * @param mixed $price Raw price.
	 * @return mixed
	 */
	public static function filter_product_price( $price ) {
		return self::convert_toman_to_usd( $price );
	}

	/**
	 * Switch WooCommerce currency code on non-Persian storefronts.
	 *
	 * @param string $currency Current currency code.
	 * @return string
	 */
	public static function filter_currency_code( $currency ) {
		if ( ! self::should_convert() ) {
			return $currency;
		}

		return 'USD';
	}

	/**
	 * Display symbol for converted storefront prices.
	 *
	 * @param string $symbol   Currency symbol.
	 * @param string $currency Currency code.
	 * @return string
	 */
	public static function filter_currency_symbol( $symbol, $currency ) {
		unset( $currency );

		if ( ! self::should_convert() ) {
			return $symbol;
		}

		return '$';
	}

	/**
	 * Ensure USD formatting args on converted storefronts.
	 *
	 * @param array<string, mixed> $args Price format args.
	 * @return array<string, mixed>
	 */
	public static function filter_price_args( array $args ) {
		if ( ! self::should_convert() ) {
			return $args;
		}

		$args['currency']           = 'USD';
		$args['decimals']           = 2;
		$args['price_format']       = '%1$s%2$s';
		$args['thousand_separator'] = ',';
		$args['decimal_separator']  = '.';

		return $args;
	}

	/**
	 * Decimal precision for converted prices.
	 *
	 * @param int $decimals Current decimal count.
	 * @return int
	 */
	public static function filter_price_decimals( $decimals ) {
		if ( ! self::should_convert() ) {
			return $decimals;
		}

		return 2;
	}

	/**
	 * Convert shipping package rate costs.
	 *
	 * @param array<string, \WC_Shipping_Rate> $rates Package rates.
	 * @return array<string, \WC_Shipping_Rate>
	 */
	public static function filter_package_rates( $rates ) {
		if ( ! self::should_convert() || ! is_array( $rates ) ) {
			return $rates;
		}

		foreach ( $rates as $rate ) {
			if ( $rate instanceof \WC_Shipping_Rate ) {
				$rate->cost = (float) self::filter_coupon_amount( $rate->cost );
			}
		}

		return $rates;
	}

	/**
	 * Bust variation price caches when language/rate context changes.
	 *
	 * @param array<string, mixed> $hash Variation price hash.
	 * @return array<string, mixed>
	 */
	public static function filter_variation_prices_hash( array $hash ) {
		if ( ! self::should_convert() ) {
			return $hash;
		}

		$hash['polymart_lang'] = Url_Router::get_current_language();
		$hash['polymart_rate'] = (string) self::get_rate();

		return $hash;
	}

	/**
	 * Detect stale Toman markup in cached USD price HTML.
	 *
	 * @param string $html Price HTML.
	 * @return bool
	 */
	private static function price_html_contains_toman( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return false;
		}

		return false !== stripos( $html, 'تومان' )
			|| false !== stripos( $html, 'toman' )
			|| false !== stripos( $html, 'IRT' )
			|| false !== stripos( $html, 'IRR' );
	}

	/**
	 * Parse USD/Toman rate from BrsApi.ir currency list.
	 *
	 * @param array<string, mixed> $body Decoded JSON body.
	 * @return float
	 */
	private static function parse_rate_from_response( array $body ) {
		$usd = self::find_usd_currency_entry( $body );

		if ( null === $usd ) {
			return 0.0;
		}

		$price = $usd['price'] ?? null;

		if ( is_numeric( $price ) && (float) $price > 0 ) {
			return (float) $price;
		}

		return 0.0;
	}

	/**
	 * Parse update timestamp from BrsApi.ir USD entry.
	 *
	 * @param array<string, mixed> $body Decoded JSON body.
	 * @return string ISO-8601 datetime in site timezone.
	 */
	private static function parse_updated_at_from_response( array $body ) {
		$usd = self::find_usd_currency_entry( $body );

		if ( null !== $usd && ! empty( $usd['time_unix'] ) && is_numeric( $usd['time_unix'] ) ) {
			return wp_date( 'c', (int) $usd['time_unix'] );
		}

		return wp_date( 'c' );
	}

	/**
	 * Locate the USD row inside BrsApi.ir currency payload.
	 *
	 * @param array<string, mixed> $body Decoded JSON body.
	 * @return array<string, mixed>|null
	 */
	private static function find_usd_currency_entry( array $body ) {
		if ( empty( $body['currency'] ) || ! is_array( $body['currency'] ) ) {
			return null;
		}

		foreach ( $body['currency'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( 'USD' === strtoupper( (string) ( $item['symbol'] ?? '' ) ) ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Use last successful snapshot when live refresh fails.
	 *
	 * @param \WP_Error $error Original error.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function handle_refresh_failure( \WP_Error $error ) {
		$snapshot = get_option( self::RATE_OPTION_KEY, array() );

		if ( is_array( $snapshot ) && ! empty( $snapshot['rate'] ) ) {
			set_transient( self::RATE_TRANSIENT_KEY, $snapshot, self::RATE_TTL );
			self::$rate_cache = (float) $snapshot['rate'];

			return array_merge(
				$snapshot,
				array(
					'rate_formatted'   => number_format_i18n( (float) $snapshot['rate'], 0 ),
					'updated_at_human' => self::format_datetime_human( (string) ( $snapshot['updated_at'] ?? '' ) ),
					'from_fallback'    => true,
					'warning'          => $error->get_error_message(),
				)
			);
		}

		return $error;
	}

	/**
	 * Human-readable datetime for admin UI.
	 *
	 * @param string $iso ISO datetime string.
	 * @return string
	 */
	private static function format_datetime_human( $iso ) {
		if ( '' === trim( (string) $iso ) ) {
			return '';
		}

		$timestamp = strtotime( (string) $iso );

		if ( false === $timestamp ) {
			return (string) $iso;
		}

		return wp_date( 'Y/m/d H:i', $timestamp );
	}

	/**
	 * Next scheduled cron run as ISO string.
	 *
	 * @return string
	 */
	private static function get_next_cron_run_iso() {
		$next = wp_next_scheduled( self::CRON_HOOK );

		if ( ! $next ) {
			return '';
		}

		return wp_date( 'c', $next );
	}
}
