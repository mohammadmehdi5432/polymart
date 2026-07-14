<?php
/**
 * Bridges PolyMartAI language settings with WoodMart Variable Product Enhancer.
 *
 * @package PolymartAI
 */

namespace PolymartAI\Integration;

use PolymartAI\Language_Registry;


defined( 'ABSPATH' ) || exit;

/**
 * Class Wve_Integration
 */
final class Wve_Integration {

	/**
	 * Schedule integration hooks after WVE has bootstrapped.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'register_hooks' ), 20 );
	}

	/**
	 * Register WVE integration hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		if ( ! defined( 'WVE_VERSION' ) ) {
			return;
		}

		add_filter( 'wve_product_placeholder_img_src', array( __CLASS__, 'filter_product_placeholder_img_src' ) );
	}

	/**
	 * Per-language product placeholder from Languages admin.
	 *
	 * @param string $default Default placeholder URL from WVE.
	 * @return string
	 */
	public static function filter_product_placeholder_img_src( $default ) {
		$url = Language_Registry::get_product_placeholder_url_for_current_language();

		return '' !== $url ? $url : (string) $default;
	}
}
