<?php
/**
 * Bridges PolyMartAI language routing with the KND Panel chat widget.
 *
 * @package PolymartAI
 */

namespace PolymartAI\Integration;

use PolymartAI\Language_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Kndpi_Integration
 */
final class Kndpi_Integration {

	/**
	 * Schedule integration hooks after KND Panel Integration has bootstrapped.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'register_hooks' ), 20 );
	}

	/**
	 * Register KND Panel chat widget hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		if ( ! defined( 'KNDPI_VERSION' ) ) {
			return;
		}

		add_filter( 'kndpi_should_show_widget', array( __CLASS__, 'filter_should_show_widget' ) );
	}

	/**
	 * Chat bot only understands the default (source) language — hide widget elsewhere.
	 *
	 * @param bool $show Whether the widget should render.
	 * @return bool
	 */
	public static function filter_should_show_widget( $show ) {
		if ( ! $show ) {
			return false;
		}

		return Language_Registry::uses_persian_digits();
	}
}
