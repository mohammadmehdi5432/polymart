<?php
/**
 * Temporary single-product diagnostic tracing and nuclear translator bypass.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation\WooCommerce;

use PolymartAI\Routing\Url_Router;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product_Diagnostics
 */
final class Product_Diagnostics {

	/**
	 * Per-request trace lines written to debug.log.
	 *
	 * @var string[]
	 */
	private static $trace_buffer = array();

	/**
	 * Whether boot decision was logged.
	 *
	 * @var bool
	 */
	private static $boot_logged = false;

	/**
	 * Register lifecycle trace hooks on product requests.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! self::should_attach_runtime_traces() ) {
			return;
		}

		add_action( 'wp', array( __CLASS__, 'trace_wp_boot' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'trace_template_redirect' ), 0 );
		add_action( 'woocommerce_before_single_product', array( __CLASS__, 'trace_before_single_product' ), 0 );
		add_action( 'woocommerce_after_single_product', array( __CLASS__, 'trace_after_single_product' ), 999 );
		add_action( 'shutdown', array( __CLASS__, 'flush_trace_summary' ), 999 );
	}

	/**
	 * Whether diagnostic tracing is enabled.
	 *
	 * @return bool
	 */
	public static function is_trace_enabled() {
		// Opt-in only — auto-enabling from WP_DEBUG flooded memory on product pages
		// (every get_post_meta structural check wrote a trace line).
		return defined( 'POLYMART_AI_TRACE_PRODUCT' ) && POLYMART_AI_TRACE_PRODUCT;
	}

	/**
	 * Storefront translator module slugs used for selective isolation.
	 *
	 * @return string[]
	 */
	public static function get_storefront_modules() {
		return array(
			'universal',
			'frontend_interceptor',
			'woocommerce',
			'woodmart',
			'option',
			'comment',
		);
	}

	/**
	 * Nuclear test: skip all storefront translator modules on single product pages.
	 *
	 * @return bool
	 */
	public static function is_nuclear_bypass_active() {
		if ( ! defined( 'POLYMART_AI_NUCLEAR_PRODUCT_BYPASS' ) || ! POLYMART_AI_NUCLEAR_PRODUCT_BYPASS ) {
			return false;
		}

		return self::is_product_bypass_context();
	}

	/**
	 * Whether selective or nuclear bypass applies on this single-product request.
	 *
	 * @return bool
	 */
	public static function is_product_bypass_context() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		if ( self::is_single_product_context() ) {
			return true;
		}

		return self::is_probable_single_product_uri();
	}

	/**
	 * Whether one storefront module should not boot on this request.
	 *
	 * @param string $module Module slug from get_storefront_modules().
	 * @return bool
	 */
	public static function should_skip_module( $module ) {
		$module = sanitize_key( (string) $module );
		$skip   = self::is_module_bypassed( $module );

		/**
		 * Filter whether a PolyMart storefront module is skipped for this request.
		 *
		 * @param bool   $skip   Default bypass decision.
		 * @param string $module Module slug.
		 */
		return (bool) apply_filters( 'polymart_ai_skip_storefront_module', $skip, $module );
	}

	/**
	 * Resolve per-module bypass for the current request.
	 *
	 * @param string $module Module slug.
	 * @return bool
	 */
	public static function is_module_bypassed( $module ) {
		$module = sanitize_key( (string) $module );

		if ( ! in_array( $module, self::get_storefront_modules(), true ) ) {
			return false;
		}

		if ( self::is_nuclear_bypass_active() ) {
			return true;
		}

		if ( ! self::is_product_bypass_context() ) {
			return false;
		}

		$constant = self::get_module_constant_name( $module );

		return defined( $constant ) && (bool) constant( $constant );
	}

	/**
	 * Map module slug to its bypass constant name.
	 *
	 * @param string $module Module slug.
	 * @return string
	 */
	public static function get_module_constant_name( $module ) {
		$map = array(
			'universal'            => 'POLYMART_AI_BYPASS_UNIVERSAL',
			'frontend_interceptor' => 'POLYMART_AI_BYPASS_FRONTEND_INTERCEPTOR',
			'woocommerce'          => 'POLYMART_AI_BYPASS_WOOCOMMERCE',
			'woodmart'             => 'POLYMART_AI_BYPASS_WOODMART',
			'option'               => 'POLYMART_AI_BYPASS_OPTION',
			'comment'              => 'POLYMART_AI_BYPASS_COMMENT',
		);

		return $map[ $module ] ?? '';
	}

	/**
	 * Per-module bypass state for diagnostics.
	 *
	 * @return array<string, bool>
	 */
	public static function get_module_bypass_map() {
		$map = array();

		foreach ( self::get_storefront_modules() as $module ) {
			$map[ $module ] = self::should_skip_module( $module );
		}

		return $map;
	}

	/**
	 * Whether any storefront module is bypassed on this request.
	 *
	 * @return bool
	 */
	public static function has_any_module_bypass() {
		if ( self::is_nuclear_bypass_active() ) {
			return true;
		}

		foreach ( self::get_storefront_modules() as $module ) {
			if ( self::is_module_bypassed( $module ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether every storefront module is bypassed on this request.
	 *
	 * @return bool
	 */
	public static function should_skip_storefront_translators() {
		foreach ( self::get_storefront_modules() as $module ) {
			if ( ! self::should_skip_module( $module ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether runtime trace hooks should attach.
	 *
	 * @return bool
	 */
	public static function should_attach_runtime_traces() {
		return self::is_trace_enabled() || self::has_any_module_bypass();
	}

	/**
	 * Whether the main query is a WooCommerce single product page.
	 *
	 * @return bool
	 */
	public static function is_single_product_context() {
		return function_exists( 'is_product' ) && is_product();
	}

	/**
	 * Best-effort product URL detection before the main query exists.
	 *
	 * @return bool
	 */
	public static function is_probable_single_product_uri() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return false;
		}

		// Strip language prefix segments such as /en/product/slug/.
		if ( preg_match( '#^(?:[a-z]{2})/product/[^/]+$#', $path ) ) {
			return true;
		}

		return (bool) preg_match( '#^product/[^/]+$#', $path );
	}

	/**
	 * Append one diagnostic line to the in-memory buffer and debug.log.
	 *
	 * @param string               $event   Event slug.
	 * @param array<string, mixed> $context Optional context payload.
	 * @return void
	 */
	public static function trace( $event, array $context = array() ) {
		if ( ! self::should_attach_runtime_traces() ) {
			return;
		}

		$line = '[PolyMartAI][product-trace] ' . sanitize_key( (string) $event );

		if ( ! empty( $context ) ) {
			$json = wp_json_encode( $context );

			if ( is_string( $json ) ) {
				$line .= ' ' . $json;
			}
		}

		self::$trace_buffer[] = $line;
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Log storefront translator boot decision once per request.
	 *
	 * @return void
	 */
	public static function log_boot_decision() {
		if ( self::$boot_logged ) {
			return;
		}

		self::$boot_logged = true;

		self::trace(
			'storefront_translator_boot',
			array(
				'skip_all_modules'   => self::should_skip_storefront_translators(),
				'nuclear_bypass'     => self::is_nuclear_bypass_active(),
				'module_bypass_map'  => self::get_module_bypass_map(),
				'is_product'         => self::is_single_product_context(),
				'probable_uri'       => self::is_probable_single_product_uri(),
				'translated_url'     => Url_Router::is_translated_request(),
				'current_language'   => Url_Router::get_current_language(),
				'request_uri'        => isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '',
			)
		);
	}

	/**
	 * Trace main query state at wp.
	 *
	 * @return void
	 */
	public static function trace_wp_boot() {
		global $wp_query, $post, $product;

		self::trace(
			'wp_boot',
			array(
				'is_product'        => self::is_single_product_context(),
				'is_singular'       => is_singular(),
				'queried_post_type' => is_object( $wp_query ) ? (string) $wp_query->get( 'post_type' ) : '',
				'queried_id'        => function_exists( 'get_queried_object_id' ) ? get_queried_object_id() : 0,
				'global_post_id'    => $post instanceof \WP_Post ? $post->ID : 0,
				'global_post_type'  => $post instanceof \WP_Post ? $post->post_type : '',
				'has_wc_product'    => isset( $product ) && $product instanceof \WC_Product,
				'suspend_depth'     => self::get_suspend_depth(),
			)
		);
	}

	/**
	 * Trace template routing.
	 *
	 * @return void
	 */
	public static function trace_template_redirect() {
		self::trace(
			'template_redirect',
			array(
				'is_product'   => self::is_single_product_context(),
				'template'     => function_exists( 'get_page_template_slug' ) ? (string) get_page_template_slug() : '',
				'woodmart_layout' => self::resolve_woodmart_single_product_layout_id(),
			)
		);
	}

	/**
	 * Trace globals before WooCommerce single product markup.
	 *
	 * @return void
	 */
	public static function trace_before_single_product() {
		global $post, $product;

		self::trace(
			'woocommerce_before_single_product',
			self::snapshot_runtime_state( $post, $product )
		);
	}

	/**
	 * Trace globals after WooCommerce single product markup.
	 *
	 * @return void
	 */
	public static function trace_after_single_product() {
		global $post, $product;

		self::trace(
			'woocommerce_after_single_product',
			self::snapshot_runtime_state( $post, $product )
		);
	}

	/**
	 * Write a compact shutdown summary.
	 *
	 * @return void
	 */
	public static function flush_trace_summary() {
		if ( ! self::should_attach_runtime_traces() || empty( self::$trace_buffer ) ) {
			return;
		}

		self::trace(
			'shutdown_summary',
			array(
				'events_logged' => count( self::$trace_buffer ),
				'last_error'    => self::get_last_php_error(),
			)
		);
	}

	/**
	 * Trace structural layout checks (called from Layout_Guard).
	 *
	 * @param int|string|\WP_Post|null $post_id_or_type Post reference.
	 * @param bool                     $result          Guard result.
	 * @param string                   $source          Caller label.
	 * @return void
	 */
	public static function trace_structural_check( $post_id_or_type, $result, $source ) {
		if ( ! self::is_trace_enabled() ) {
			return;
		}

		if ( ! self::should_attach_runtime_traces() ) {
			return;
		}

		$post_id   = is_numeric( $post_id_or_type ) ? absint( $post_id_or_type ) : ( $post_id_or_type instanceof \WP_Post ? $post_id_or_type->ID : 0 );
		$post_type = Layout_Guard::resolve_post_type_public( $post_id_or_type );

		self::trace(
			'structural_layout_check',
			array(
				'source'    => $source,
				'post_id'   => $post_id,
				'post_type' => $post_type,
				'result'    => (bool) $result,
				'is_product_context' => self::is_single_product_context(),
			)
		);
	}

	/**
	 * Trace Elementor metadata filter decisions.
	 *
	 * @param string               $decision Short decision code.
	 * @param int                  $post_id  Post ID.
	 * @param array<string, mixed> $extra    Extra context.
	 * @return void
	 */
	public static function trace_elementor_metadata( $decision, $post_id, array $extra = array() ) {
		if ( ! self::should_attach_runtime_traces() ) {
			return;
		}

		self::trace(
			'elementor_metadata_filter',
			array_merge(
				array(
					'decision'  => $decision,
					'post_id'   => (int) $post_id,
					'post_type' => Layout_Guard::resolve_post_type_public( $post_id ),
				),
				$extra
			)
		);
	}

	/**
	 * @return int
	 */
	private static function get_suspend_depth() {
		return Layout_Guard::get_suspend_depth_public();
	}

	/**
	 * @param mixed $post    Global post.
	 * @param mixed $product Global WC product.
	 * @return array<string, mixed>
	 */
	private static function snapshot_runtime_state( $post, $product ) {
		return array(
			'global_post_id'   => $post instanceof \WP_Post ? $post->ID : 0,
			'global_post_type' => $post instanceof \WP_Post ? $post->post_type : '',
			'product_id'       => $product instanceof \WC_Product ? $product->get_id() : 0,
			'suspend_depth'    => self::get_suspend_depth(),
		);
	}

	/**
	 * @return int
	 */
	private static function resolve_woodmart_single_product_layout_id() {
		if ( ! class_exists( '\XTS\Modules\Layouts\Main' ) ) {
			return 0;
		}

		$main = \XTS\Modules\Layouts\Main::get_instance();

		if ( ! is_object( $main ) || ! method_exists( $main, 'has_custom_layout' ) ) {
			return 0;
		}

		if ( ! $main->has_custom_layout( 'single_product' ) ) {
			return 0;
		}

		return method_exists( $main, 'get_layout_id' ) ? absint( $main->get_layout_id( 'single_product' ) ) : 0;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function get_last_php_error() {
		$error = error_get_last();

		if ( ! is_array( $error ) ) {
			return null;
		}

		return array(
			'type'    => $error['type'] ?? 0,
			'message' => $error['message'] ?? '',
			'file'    => $error['file'] ?? '',
			'line'    => $error['line'] ?? 0,
		);
	}
}
