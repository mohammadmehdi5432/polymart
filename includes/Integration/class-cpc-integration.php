<?php
/**
 * Bridges PolyMartAI language settings with Custom Product Cards (CPC).
 *
 * @package PolymartAI
 */

namespace PolymartAI\Integration;

use PolymartAI\Frontend\Currency;
use PolymartAI\Language_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cpc_Integration
 */
final class Cpc_Integration {

	/**
	 * Schedule integration hooks after CPC has bootstrapped.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'register_hooks' ), 25 );
	}

	/**
	 * Register CPC integration hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		if ( ! defined( 'CPC_VERSION' ) ) {
			return;
		}

		add_filter( 'cpc_use_persian_digits', array( __CLASS__, 'filter_use_persian_digits' ) );
		add_filter( 'cpc_toman_svg_external', array( __CLASS__, 'filter_currency_icon_url' ), 10, 4 );
		add_filter( 'cpc_use_toman_svg_for_currency', array( __CLASS__, 'filter_use_toman_svg_for_currency' ), 10, 3 );
		add_filter( 'cpc_bypass_price_digit_conversion', array( __CLASS__, 'filter_bypass_price_digit_conversion' ), 10, 3 );
	}

	/**
	 * Persian digits only on the default (source) language — typically fa.
	 *
	 * @param bool $default Unused default from CPC.
	 * @return bool
	 */
	public static function filter_use_persian_digits( $default ) {
		unset( $default );

		return Language_Registry::uses_persian_digits();
	}

	/**
	 * Per-language currency icon for CPC price markup (e.g. $ icon for en/ar).
	 *
	 * @param string|null       $external Current external icon URL/markup.
	 * @param string            $price_html Price HTML.
	 * @param \WC_Product|mixed $product Product object.
	 * @param string            $currency Currency code.
	 * @return string|null
	 */
	public static function filter_currency_icon_url( $external, $price_html, $product, $currency ) {
		unset( $price_html, $product, $currency );

		$url = Language_Registry::get_cpc_currency_icon_url_for_current_language();

		return '' !== $url ? $url : $external;
	}

	/**
	 * Enable CPC icon injection when a per-language icon is configured.
	 *
	 * CPC only injects icons when this returns true. For en/ar we force true so
	 * cpc_toman_svg_external can swap the symbol with the admin-uploaded $ icon.
	 * Toman SVG fallback is avoided because cpc_use_persian_digits is false there.
	 *
	 * @param bool              $is_ir_currency Whether CPC treats currency as Iranian.
	 * @param string            $currency       WooCommerce currency code.
	 * @param \WC_Product|mixed $product        Product object.
	 * @return bool
	 */
	public static function filter_use_toman_svg_for_currency( $is_ir_currency, $currency, $product ) {
		unset( $currency, $product );

		if ( Currency::should_convert() ) {
			return '' !== Language_Registry::get_cpc_currency_icon_url_for_current_language();
		}

		return (bool) $is_ir_currency;
	}

	/**
	 * Keep Latin digits for USD prices.
	 *
	 * @param bool              $bypass    Current bypass flag.
	 * @param \WC_Product|mixed $product   Product object.
	 * @param string            $price_html Price HTML.
	 * @return bool
	 */
	public static function filter_bypass_price_digit_conversion( $bypass, $product, $price_html ) {
		unset( $product, $price_html );

		if ( Currency::should_convert() ) {
			return true;
		}

		return (bool) $bypass;
	}
}
