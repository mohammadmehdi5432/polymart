<?php
/**
 * Bridges PolyMartAI language settings with KND Elementor widgets.
 *
 * @package PolymartAI
 */

namespace PolymartAI\Integration;

use PolymartAI\Frontend\Currency;
use PolymartAI\Language_Registry;
use PolymartAI\Routing\Url_Router;

defined( 'ABSPATH' ) || exit;

/**
 * Class Knd_Integration
 */
final class Knd_Integration {

	/**
	 * Schedule integration hooks after KND has bootstrapped.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'register_hooks' ), 20 );
	}

	/**
	 * Register KND integration hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		if ( ! defined( 'KND_EW_VERSION' ) || ! function_exists( 'knd_should_use_persian_digits' ) ) {
			return;
		}

		add_filter( 'knd_use_persian_digits', array( __CLASS__, 'filter_use_persian_digits' ) );
		add_filter( 'knd_current_language_code', array( __CLASS__, 'filter_current_language_code' ) );
		add_filter( 'knd_currency_icon_url', array( __CLASS__, 'filter_currency_icon_url' ) );
		add_filter( 'knd_price_decimals', array( __CLASS__, 'filter_price_decimals' ) );
	}

	/**
	 * Persian digits only on the default (source) language — typically fa.
	 *
	 * @param bool $default Unused default from KND.
	 * @return bool
	 */
	public static function filter_use_persian_digits( $default ) {
		unset( $default );

		return Language_Registry::uses_persian_digits();
	}

	/**
	 * Active storefront language code for JS locale config.
	 *
	 * @param string $code Current code.
	 * @return string
	 */
	public static function filter_current_language_code( $code ) {
		if ( '' !== (string) $code ) {
			return (string) $code;
		}

		if ( ! class_exists( Url_Router::class ) ) {
			return Language_Registry::get_default_language_code();
		}

		return Url_Router::get_current_language();
	}

	/**
	 * Per-language currency icon for non-Persian storefronts (from Languages admin).
	 * Persian/default language keeps KND's inline Toman SVG.
	 *
	 * @param string $url Current icon URL.
	 * @return string
	 */
	public static function filter_currency_icon_url( $url ) {
		if ( Language_Registry::uses_persian_digits() ) {
			return (string) $url;
		}

		$icon_url = Language_Registry::get_cpc_currency_icon_url_for_current_language();

		return '' !== $icon_url ? $icon_url : (string) $url;
	}

	/**
	 * Two decimal places for USD storefronts.
	 *
	 * @param int $decimals Requested decimal count.
	 * @return int
	 */
	public static function filter_price_decimals( $decimals ) {
		if ( class_exists( Currency::class ) && Currency::should_convert() ) {
			return 2;
		}

		return (int) $decimals;
	}
}
