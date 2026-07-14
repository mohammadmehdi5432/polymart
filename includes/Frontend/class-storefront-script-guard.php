<?php
/**
 * Protect WooCommerce variation scripts from PolyMart storefront JS side effects.
 *
 * @package PolymartAI\Frontend
 */

namespace PolymartAI\Frontend;

use PolymartAI\Routing\Url_Router;


defined( 'ABSPATH' ) || exit;

/**
 * Class Storefront_Script_Guard
 */
final class Storefront_Script_Guard {

	/**
	 * Script handles that may clobber window._ when loaded before WooCommerce variations.
	 *
	 * @var string[]
	 */
	private static $underscore_clobber_handles = array(
		'customer-portal-react',
		'lodash',
		'elementor-frontend',
		'elementor-common',
	);

	/**
	 * Register storefront script compatibility hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'ensure_cart_fragments_for_side_cart' ), 9998 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'guard_wc_variation_scripts' ), 9999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'ensure_wp_i18n_before_consumers' ), 10000 );
		add_action( 'wp_footer', array( __CLASS__, 'inject_mini_cart_fragment_guards' ), 1 );
	}

	/**
	 * Whether the current request is a WooCommerce single product page.
	 *
	 * @return bool
	 */
	public static function is_product_page() {
		return function_exists( 'is_product' ) && is_product();
	}

	/**
	 * Script handles that consume PolyMart wp.i18n locale data on the storefront.
	 *
	 * @return string[]
	 */
	public static function get_i18n_consumer_handles() {
		/**
		 * Filter script handles that require PolyMart wp.i18n locale injection.
		 *
		 * @param string[] $handles Registered script handles.
		 */
		return (array) apply_filters(
			'polymart_ai_i18n_consumer_handles',
			array(
				'customer-portal-react',
				'apd-tabs-script',
				'wcc-script',
			)
		);
	}

	/**
	 * Whether PolyMart should inject wp.i18n locale data on this request.
	 *
	 * @return bool
	 */
	public static function should_inject_i18n_locale_data() {
		if ( is_admin() || ! Url_Router::is_translated_request() ) {
			return false;
		}

		foreach ( self::get_i18n_consumer_handles() as $handle ) {
			if ( wp_script_is( $handle, 'enqueued' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Guarantee wp-i18n is queued before React bundles that read it at parse time.
	 *
	 * @return void
	 */
	public static function ensure_wp_i18n_before_consumers() {
		if ( is_admin() ) {
			return;
		}

		$needs_i18n = false;

		foreach ( self::get_i18n_consumer_handles() as $handle ) {
			if ( wp_script_is( $handle, 'enqueued' ) || wp_script_is( $handle, 'to_do' ) ) {
				$needs_i18n = true;
				break;
			}
		}

		if ( ! $needs_i18n ) {
			return;
		}

		if ( wp_script_is( 'wp-hooks', 'registered' ) ) {
			wp_enqueue_script( 'wp-hooks' );
		}

		if ( wp_script_is( 'wp-i18n', 'registered' ) ) {
			wp_enqueue_script( 'wp-i18n' );
		}

		if ( wp_script_is( 'wcc-script', 'enqueued' ) ) {
			foreach ( array( 'react', 'react-dom', 'react-jsx-runtime', 'wp-element', 'wcc-i18n-shim' ) as $handle ) {
				if ( wp_script_is( $handle, 'registered' ) ) {
					wp_enqueue_script( $handle );
				}
			}
		}

		global $wp_scripts;

		if ( ! $wp_scripts instanceof \WP_Scripts ) {
			return;
		}

		foreach ( self::get_i18n_consumer_handles() as $handle ) {
			if ( ! isset( $wp_scripts->registered[ $handle ] ) ) {
				continue;
			}

			$extra_deps = array( 'wp-i18n', 'wp-hooks' );

			if ( 'wcc-script' === $handle ) {
				$extra_deps = array_merge(
					$extra_deps,
					array( 'react', 'react-dom', 'react-jsx-runtime', 'wp-element', 'wcc-i18n-shim' )
				);
			} elseif ( wp_script_is( 'wcc-i18n-shim', 'registered' ) ) {
				$extra_deps[] = 'wcc-i18n-shim';
			}

			$wp_scripts->registered[ $handle ]->deps = array_values(
				array_unique(
					array_merge(
						$extra_deps,
						(array) $wp_scripts->registered[ $handle ]->deps
					)
				)
			);
		}
	}

	/**
	 * Ensure WooCommerce cart fragment scripts load when WoodMart side cart is active.
	 *
	 * @return void
	 */
	public static function ensure_cart_fragments_for_side_cart() {
		if ( is_admin() || ! function_exists( 'WC' ) || is_cart() || is_checkout() ) {
			return;
		}

		if ( function_exists( 'whb_is_side_cart' ) && whb_is_side_cart() && wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
			wp_enqueue_script( 'wc-cart-fragments' );
		}

		if ( wp_script_is( 'wd-update-cart-fragments-fix', 'registered' ) ) {
			wp_enqueue_script( 'wd-update-cart-fragments-fix' );
		}
	}

	/**
	 * Recover WoodMart side cart when stale/empty fragment cache leaves the widget blank.
	 *
	 * @return void
	 */
	public static function inject_mini_cart_fragment_guards() {
		if ( is_admin() || ! function_exists( 'WC' ) || is_cart() || is_checkout() ) {
			return;
		}

		$handle = 'wc-cart-fragments';

		if ( ! wp_script_is( $handle, 'registered' ) || wp_script_is( $handle, 'done' ) ) {
			return;
		}

		if ( ! wp_script_is( $handle, 'enqueued' ) ) {
			wp_enqueue_script( $handle );
		}
	}

	/**
	 * Keep Underscore's _.template intact for wc-add-to-cart-variation.
	 *
	 * PolyMart locale injection and third-party React bundles can enqueue scripts
	 * that overwrite window._ after underscore loads. WooCommerce variations call
	 * _.template at runtime and crash when lodash/React builds replace _.
	 *
	 * @return void
	 */
	public static function guard_wc_variation_scripts() {
		if ( ! self::is_product_page() ) {
			return;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_queried_object_id() ) : null;

		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			return;
		}

		if ( ! wp_script_is( 'wc-add-to-cart-variation', 'enqueued' ) && ! wp_script_is( 'wc-add-to-cart-variation', 'registered' ) ) {
			return;
		}

		global $wp_scripts;

		if ( ! $wp_scripts instanceof \WP_Scripts ) {
			return;
		}

		$wc_handle = 'wc-add-to-cart-variation';

		if ( isset( $wp_scripts->registered[ $wc_handle ] ) ) {
			$wp_scripts->registered[ $wc_handle ]->deps = array_values(
				array_unique(
					array_merge(
						array( 'jquery', 'underscore', 'wp-util' ),
						(array) $wp_scripts->registered[ $wc_handle ]->deps
					)
				)
			);
		}

		self::move_script_after_dependencies( $wp_scripts, $wc_handle, array( 'underscore', 'wp-util' ) );

		if ( wp_script_is( 'underscore', 'registered' ) ) {
			wp_add_inline_script(
				'underscore',
				'window.polymartWcUnderscore=window._;',
				'after'
			);
		}

		if ( wp_script_is( $wc_handle, 'registered' ) ) {
			wp_add_inline_script(
				$wc_handle,
				'(function(){if(window.polymartWcUnderscore&&window.polymartWcUnderscore.template){window._=window.polymartWcUnderscore;}})();',
				'before'
			);
		}
	}

	/**
	 * Move one queued script to immediately after its anchor dependency.
	 *
	 * @param \WP_Scripts        $wp_scripts Script registry.
	 * @param string             $handle     Script to move.
	 * @param string[]           $anchors    Dependency handles (first match wins).
	 * @return void
	 */
	private static function move_script_after_dependencies( \WP_Scripts $wp_scripts, $handle, array $anchors ) {
		if ( ! in_array( $handle, $wp_scripts->queue, true ) ) {
			return;
		}

		$queue = array_values( array_diff( $wp_scripts->queue, array( $handle ) ) );
		$insert = count( $queue );

		foreach ( $anchors as $anchor ) {
			$position = array_search( $anchor, $queue, true );

			if ( false !== $position ) {
				$insert = $position + 1;
				break;
			}
		}

		array_splice( $queue, $insert, 0, array( $handle ) );
		$wp_scripts->queue = $queue;

		foreach ( self::$underscore_clobber_handles as $clobber_handle ) {
			if ( ! in_array( $clobber_handle, $wp_scripts->queue, true ) ) {
				continue;
			}

			$queue = array_values( array_diff( $wp_scripts->queue, array( $clobber_handle ) ) );
			$queue[] = $clobber_handle;
			$wp_scripts->queue = $queue;
		}
	}
}
