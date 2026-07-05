<?php
/**
 * Absolute guardrails for Woodmart/Elementor structural layout post types.
 *
 * PolyMartAI must never intercept `_elementor_data`, post_content, or related
 * meta on builder shells (woodmart_layout, elementor_library, etc.). Woodmart
 * single-product pages render those CPTs via Elementor while the global $product
 * must remain the WooCommerce product being viewed.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Class Layout_Guard
 */
final class Layout_Guard {

	/**
	 * Post types that are theme/builder shells — never translate or intercept.
	 *
	 * @var string[]
	 */
	private static $structural_post_types = array(
		'woodmart_layout',
		'elementor_library',
		'wd_popup',
		'shop_order',
	);

	/**
	 * Nested Elementor builder-content renders (layout inside layout).
	 *
	 * @var int
	 */
	private static $suspend_depth = 0;

	/**
	 * Snapshot of global $product before layout render.
	 *
	 * @var \WC_Product|null
	 */
	private static $product_snapshot = null;

	/**
	 * Snapshot of global $post before layout render.
	 *
	 * @var \WP_Post|null
	 */
	private static $post_snapshot = null;

	/**
	 * Per-request post-type cache keyed by post ID.
	 *
	 * @var array<int, string>
	 */
	private static $post_type_cache = array();

	/**
	 * Register Elementor isolation hooks.
	 *
	 * @return void
	 */
	public static function init() {
		Product_Diagnostics::init();

		add_action( 'elementor/frontend/before_get_builder_content', array( __CLASS__, 'before_elementor_builder_content' ), 0, 2 );
		add_action( 'elementor/frontend/get_builder_content', array( __CLASS__, 'after_elementor_builder_content' ), 999, 3 );
		add_action( 'shutdown', array( __CLASS__, 'force_resume_all' ), 0 );
	}

	/**
	 * Whether the main request is a WooCommerce single product page.
	 *
	 * Elementor + Woodmart builder pipelines must never be intercepted here.
	 *
	 * @return bool
	 */
	public static function is_single_product_context() {
		return function_exists( 'is_product' ) && is_product();
	}

	/**
	 * Whether PolyMartAI must not touch Elementor document metadata at all.
	 *
	 * @param int $object_id Post ID being read.
	 * @return bool
	 */
	public static function should_preserve_elementor_metadata( $object_id ) {
		return self::should_preserve_post_data( $object_id );
	}

	/**
	 * Whether translation hooks must leave this post's data untouched.
	 *
	 * Only structural builder shells (woodmart_layout, elementor_library, etc.).
	 * Products and regular content must keep translating even while Elementor
	 * renders a Woodmart layout on single-product pages.
	 *
	 * @param int|\WP_Post|string|null $post_id_or_type Post reference.
	 * @return bool
	 */
	public static function should_preserve_post_data( $post_id_or_type ) {
		return self::is_structural_layout_post( $post_id_or_type );
	}

	/**
	 * Force-clear any stuck suspend depth at the end of the request.
	 *
	 * @return void
	 */
	public static function force_resume_all() {
		while ( self::$suspend_depth > 0 ) {
			self::resume();
		}
	}

	/**
	 * Structural layout post type slugs.
	 *
	 * @return string[]
	 */
	public static function get_structural_post_types() {
		/**
		 * Filter structural builder post types that PolyMartAI must never mutate.
		 *
		 * @param string[] $post_types Post type slugs.
		 */
		return apply_filters( 'polymart_ai_structural_layout_post_types', self::$structural_post_types );
	}

	/**
	 * Whether a post ID, post object, or post-type slug is a structural layout shell.
	 *
	 * @param int|\WP_Post|string|null $post_id_or_type Post ID, object, or type slug.
	 * @return bool
	 */
	public static function is_structural_layout_post( $post_id_or_type ) {
		$post_type = self::resolve_post_type( $post_id_or_type );
		$result    = '' !== $post_type && in_array( $post_type, self::get_structural_post_types(), true );

		Product_Diagnostics::trace_structural_check( $post_id_or_type, $result, 'is_structural_layout_post' );

		return $result;
	}

	/**
	 * Whether storefront body filters must leave this post untouched.
	 *
	 * @param int|\WP_Post|null $post Post ID or object.
	 * @return bool
	 */
	public static function is_storefront_body_exempt( $post ) {
		if ( $post instanceof \WP_Post ) {
			if ( self::is_structural_layout_post( $post ) ) {
				return true;
			}

			if ( 'product' === $post->post_type ) {
				return true;
			}

			return Post_Translator::uses_elementor_builder( $post->ID );
		}

		$post_id = absint( $post );

		if ( $post_id <= 0 ) {
			return false;
		}

		return self::is_storefront_body_exempt( get_post( $post_id ) );
	}

	/**
	 * Whether translation/metadata filters are temporarily suspended.
	 *
	 * @return bool
	 */
	public static function are_filters_suspended() {
		return self::$suspend_depth > 0;
	}

	/**
	 * Expose suspend depth for diagnostic tracing.
	 *
	 * @return int
	 */
	public static function get_suspend_depth_public() {
		return self::$suspend_depth;
	}

	/**
	 * Expose post-type resolution for diagnostic tracing.
	 *
	 * @param int|\WP_Post|string|null $post_id_or_type Post reference.
	 * @return string
	 */
	public static function resolve_post_type_public( $post_id_or_type ) {
		return self::resolve_post_type( $post_id_or_type );
	}

	/**
	 * Suspend PolyMart translation filters and preserve WooCommerce globals.
	 *
	 * Suspend only snapshots/restores $product and $post. Translation hooks
	 * must use should_preserve_post_data( $id ) — not are_filters_suspended().
	 *
	 * @return void
	 */
	public static function suspend() {
		if ( 0 === self::$suspend_depth ) {
			global $product, $post;

			self::$product_snapshot = ( isset( $product ) && $product instanceof \WC_Product ) ? $product : null;
			self::$post_snapshot    = ( isset( $post ) && $post instanceof \WP_Post ) ? $post : null;
		}

		++self::$suspend_depth;

		Product_Diagnostics::trace(
			'layout_guard_suspend',
			array(
				'suspend_depth' => self::$suspend_depth,
				'has_product'   => self::$product_snapshot instanceof \WC_Product,
				'has_post'      => self::$post_snapshot instanceof \WP_Post,
			)
		);
	}

	/**
	 * Resume translation filters and restore preserved globals.
	 *
	 * @return void
	 */
	public static function resume() {
		if ( self::$suspend_depth <= 0 ) {
			return;
		}

		--self::$suspend_depth;

		if ( self::$suspend_depth > 0 ) {
			return;
		}

		global $product, $post;

		if ( self::$product_snapshot instanceof \WC_Product ) {
			$product = self::$product_snapshot;
		}

		if ( self::$post_snapshot instanceof \WP_Post ) {
			$post = self::$post_snapshot;
		}

		self::$product_snapshot = null;
		self::$post_snapshot    = null;

		Product_Diagnostics::trace(
			'layout_guard_resume',
			array(
				'suspend_depth' => self::$suspend_depth,
			)
		);
	}

	/**
	 * Suspend filters while Elementor prints a structural layout document.
	 *
	 * @param mixed $document Elementor document instance.
	 * @param bool  $is_excerpt Whether this is an excerpt render.
	 * @return void
	 */
	public static function before_elementor_builder_content( $document, $is_excerpt ) {
		unset( $is_excerpt );

		$post_id = self::resolve_document_post_id( $document );

		if ( $post_id <= 0 || ! self::is_structural_layout_post( $post_id ) ) {
			Product_Diagnostics::trace(
				'elementor_before_builder_skip',
				array(
					'post_id'   => $post_id,
					'post_type' => self::resolve_post_type( $post_id ),
				)
			);

			return;
		}

		Product_Diagnostics::trace(
			'elementor_before_builder_suspend',
			array(
				'post_id'   => $post_id,
				'post_type' => self::resolve_post_type( $post_id ),
			)
		);

		self::suspend();
	}

	/**
	 * Resume filters after Elementor finishes printing builder content.
	 *
	 * @param mixed $document   Elementor document instance.
	 * @param bool  $is_excerpt Whether this was an excerpt render.
	 * @param bool  $with_css   Whether CSS was inlined.
	 * @return void
	 */
	public static function after_elementor_builder_content( $document, $is_excerpt, $with_css ) {
		unset( $document, $is_excerpt, $with_css );

		if ( ! self::are_filters_suspended() ) {
			return;
		}

		self::resume();
	}

	/**
	 * Resolve a post-type slug from mixed post references.
	 *
	 * @param int|\WP_Post|string|null $post_id_or_type Post reference.
	 * @return string
	 */
	private static function resolve_post_type( $post_id_or_type ) {
		if ( $post_id_or_type instanceof \WP_Post ) {
			$post_id = (int) $post_id_or_type->ID;

			if ( $post_id > 0 ) {
				self::$post_type_cache[ $post_id ] = (string) $post_id_or_type->post_type;
			}

			return (string) $post_id_or_type->post_type;
		}

		if ( is_numeric( $post_id_or_type ) ) {
			$post_id = absint( $post_id_or_type );

			if ( $post_id <= 0 ) {
				return '';
			}

			if ( isset( self::$post_type_cache[ $post_id ] ) ) {
				return self::$post_type_cache[ $post_id ];
			}

			$post = get_post( $post_id );
			$type = $post instanceof \WP_Post ? (string) $post->post_type : '';

			self::$post_type_cache[ $post_id ] = $type;

			return $type;
		}

		if ( is_string( $post_id_or_type ) ) {
			return sanitize_key( $post_id_or_type );
		}

		return '';
	}

	/**
	 * Resolve post ID from an Elementor document object.
	 *
	 * @param mixed $document Document instance.
	 * @return int
	 */
	private static function resolve_document_post_id( $document ) {
		if ( ! is_object( $document ) ) {
			return 0;
		}

		if ( method_exists( $document, 'get_main_id' ) ) {
			return absint( $document->get_main_id() );
		}

		if ( method_exists( $document, 'get_post' ) ) {
			$post = $document->get_post();

			if ( $post instanceof \WP_Post ) {
				return (int) $post->ID;
			}
		}

		return 0;
	}
}
