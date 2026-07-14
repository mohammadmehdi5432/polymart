<?php
/**
 * WooCommerce frontend translation hooks for English URLs.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation\WooCommerce;

use PolymartAI\Language_Registry;
use PolymartAI\Routing\Url_Router;

defined( 'ABSPATH' ) || exit;

/**
 * Class WooCommerce_Translator
 */
final class WooCommerce_Translator {

	/**
	 * When true, skip storefront filters while APD captures raw product HTML.
	 *
	 * @var bool
	 */
	private static $apd_snapshot_active = false;

	/**
	 * Cached intercept flag.
	 *
	 * @var bool|null
	 */
	private $intercept_cache = null;

	/**
	 * Re-entrancy guard for gettext filters.
	 *
	 * @var int
	 */
	private $gettext_depth = 0;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// This class is instantiated on `wp` (long after plugins_loaded), so a
		// deferred plugins_loaded registration would never fire and every
		// WooCommerce product filter would silently stay unregistered.
		if ( did_action( 'plugins_loaded' ) ) {
			$this->register_hooks();
		} else {
			add_action( 'plugins_loaded', array( $this, 'register_hooks' ), 20 );
		}
	}

	/**
	 * Register WooCommerce-specific filters.
	 *
	 * @return void
	 */
	public function register_hooks() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$product_filters = array(
			'woocommerce_product_get_name',
			'woocommerce_product_get_title',
			'woocommerce_product_get_short_description',
			'woocommerce_product_get_description',
			'woocommerce_product_title',
			'woocommerce_short_description',
			'woocommerce_product_description',
		);

		foreach ( $product_filters as $filter ) {
			add_filter( $filter, array( $this, 'filter_product_text' ), 10, 2 );
		}

		add_filter( 'woocommerce_product_get_permalink', array( $this, 'filter_product_permalink' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_permalink', array( $this, 'filter_cart_item_permalink' ), 20, 3 );
		add_filter( 'woocommerce_get_cart_url', array( $this, 'filter_storefront_url' ), 20, 1 );
		add_filter( 'woocommerce_get_checkout_url', array( $this, 'filter_storefront_url' ), 20, 1 );
		add_filter( 'woocommerce_shipping_rate_label', array( $this, 'filter_shipping_rate_label' ), 20, 2 );
		add_filter( 'woocommerce_page_title', array( $this, 'filter_page_title' ), 10, 1 );
		add_filter( 'document_title_parts', array( $this, 'filter_document_title_parts' ), 25, 1 );
		add_filter( 'single_term_title', array( $this, 'filter_term_title' ), 10, 1 );
		add_filter( 'get_term', array( $this, 'filter_term_object' ), 10, 2 );
		add_filter( 'get_the_terms', array( $this, 'filter_post_terms' ), 10, 3 );
		add_filter( 'get_terms', array( $this, 'filter_terms_list' ), 10, 4 );
		add_filter( 'woocommerce_attribute_label', array( $this, 'filter_attribute_label' ), 10, 3 );
		add_filter( 'woocommerce_breadcrumb_defaults', array( $this, 'filter_breadcrumb_defaults' ) );
		add_filter( 'woocommerce_get_breadcrumb', array( $this, 'filter_breadcrumbs' ), 10, 2 );
		add_filter( 'woocommerce_product_tabs', array( $this, 'filter_product_tabs' ), 20, 1 );

		add_filter( 'woocommerce_available_variation', array( $this, 'filter_available_variation' ), 50, 3 );
		add_filter( 'woocommerce_variation_option_name', array( $this, 'filter_variation_option_name' ), 10, 4 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'filter_cart_item_data' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_name', array( $this, 'filter_cart_item_name' ), 20, 3 );
		add_filter( 'woocommerce_product_variation_title', array( $this, 'filter_variation_title' ), 20, 4 );
		add_filter( 'woocommerce_get_product_terms', array( $this, 'filter_product_terms' ), 10, 4 );
		add_filter( 'woocommerce_attribute', array( $this, 'filter_product_attribute_value' ), 10, 3 );
		add_filter( 'woocommerce_display_product_attributes', array( $this, 'filter_display_product_attributes' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_name', array( $this, 'filter_product_text' ), 10, 2 );
		add_filter( 'get_the_excerpt', array( $this, 'filter_product_excerpt' ), 10, 2 );

		add_filter( 'gettext', array( $this, 'filter_gettext' ), 25, 3 );
		add_filter( 'gettext_with_context', array( $this, 'filter_gettext_with_context' ), 25, 4 );
		add_filter( 'ngettext', array( $this, 'filter_ngettext' ), 25, 5 );
	}

	/**
	 * Swap product text fields with stored English meta.
	 *
	 * Template filters such as `woocommerce_short_description` and
	 * `woocommerce_product_description` (and some third-party plugins) apply
	 * this hook with only the text argument, so $product must stay optional.
	 *
	 * @param string              $value   Current value.
	 * @param \WC_Product|int|null $product Product object or ID when provided by the filter.
	 * @return string
	 */
	public function filter_product_text( $value, $product = null ) {
		if ( ! $this->should_intercept() || ! is_string( $value ) ) {
			return $value;
		}

		$product = $this->normalize_product( $product );

		if ( ! $product ) {
			return $value;
		}

		$post_id = $product->get_id();
		$filter  = current_filter();

		if ( $product->is_type( 'variation' ) ) {
			$variation_id = $post_id;
			$parent_id    = $product->get_parent_id();

			if ( false !== strpos( $filter, 'short_description' ) ) {
				$translated = $this->get_meta_or_original(
					$variation_id,
					Post_Translator::get_meta_key( 'excerpt', $this->get_active_lang() ),
					$this->get_meta_or_original(
						$parent_id > 0 ? $parent_id : $variation_id,
						Post_Translator::get_meta_key( 'excerpt', $this->get_active_lang() ),
						$value
					)
				);

				return $this->maybe_format_product_short_description( $translated, $filter );
			}

			if ( false !== strpos( $filter, 'description' ) && false === strpos( $filter, 'short' ) ) {
				return $this->get_meta_or_original(
					$variation_id,
					Post_Translator::get_meta_key( 'content', $this->get_active_lang() ),
					$this->get_meta_or_original(
						$parent_id > 0 ? $parent_id : $variation_id,
						Post_Translator::get_meta_key( 'content', $this->get_active_lang() ),
						$value
					)
				);
			}

			return $this->resolve_variation_display_name( $product, $value );
		}

		if ( false !== strpos( $filter, 'short_description' ) ) {
			$translated = $this->get_meta_or_original(
				$post_id,
				Post_Translator::get_meta_key( 'excerpt', $this->get_active_lang() ),
				$value
			);

			return $this->maybe_format_product_short_description( $translated, $filter );
		}

		if ( false !== strpos( $filter, 'description' ) && false === strpos( $filter, 'short' ) ) {
			if ( Post_Translator::uses_elementor_builder( $post_id ) ) {
				return $value;
			}

			return $this->get_meta_or_original( $post_id, Post_Translator::get_meta_key( 'content', $this->get_active_lang() ), $value );
		}

		return $this->get_meta_or_original( $post_id, Post_Translator::get_meta_key( 'title', $this->get_active_lang() ), $value );
	}

	/**
	 * Prefix product permalinks with the active language segment.
	 *
	 * @param string      $permalink Product URL.
	 * @param \WC_Product $product   Product object.
	 * @return string
	 */
	public function filter_product_permalink( $permalink, $product = null ) {
		unset( $product );

		if ( ! $this->should_intercept() || ! is_string( $permalink ) || '' === $permalink ) {
			return $permalink;
		}

		return Url_Router::add_language_prefix_to_url( $permalink );
	}

	/**
	 * Keep cart and mini-cart product links on the active translated storefront URL.
	 *
	 * Mini-cart HTML is often rendered during wc-ajax=get_refreshed_fragments where
	 * post_link prefixing was previously skipped.
	 *
	 * @param string              $permalink     Product URL.
	 * @param array<string,mixed> $cart_item     Cart line.
	 * @param string              $cart_item_key Cart item key.
	 * @return string
	 */
	public function filter_cart_item_permalink( $permalink, $cart_item, $cart_item_key ) {
		unset( $cart_item, $cart_item_key );

		if ( ! $this->should_intercept() || ! is_string( $permalink ) || '' === $permalink ) {
			return $permalink;
		}

		return Url_Router::add_language_prefix_to_url( $permalink );
	}

	/**
	 * Keep cart and checkout URLs on the active translated storefront.
	 *
	 * page_link prefixing is skipped during admin-ajax / wc-ajax fragment renders,
	 * so WoodMart mini-cart buttons would otherwise link to the default-language cart.
	 *
	 * @param string $url Cart or checkout URL.
	 * @return string
	 */
	public function filter_storefront_url( $url ) {
		if ( ! Url_Router::is_translated_request() || ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		return Url_Router::add_language_prefix_to_url( $url );
	}

	/**
	 * Translate custom shipping method titles configured in Persian.
	 *
	 * @param string           $label Shipping rate label.
	 * @param \WC_Shipping_Rate $rate  Shipping rate object.
	 * @return string
	 */
	public function filter_shipping_rate_label( $label, $rate ) {
		if ( ! $this->should_intercept() ) {
			return is_string( $label ) ? $label : '';
		}

		$rate_obj = $rate instanceof \WC_Shipping_Rate ? $rate : null;
		$resolved = self::translate_shipping_rate_label( $label, $rate_obj );

		if ( '' !== trim( $resolved ) ) {
			return $resolved;
		}

		return is_string( $label ) ? $label : '';
	}

	/**
	 * Translate a WooCommerce shipping rate label for the active storefront language.
	 *
	 * @param string                 $label Raw label from WooCommerce.
	 * @param \WC_Shipping_Rate|null $rate  Optional shipping rate object.
	 * @param string                 $lang  Optional language override.
	 * @return string Never returns empty when a Persian source label exists.
	 */
	public static function translate_shipping_rate_label( $label, $rate = null, $lang = '' ) {
		$source = is_string( $label ) ? trim( wp_strip_all_tags( html_entity_decode( $label, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) : '';

		if ( '' === $source && $rate instanceof \WC_Shipping_Rate ) {
			$source = self::resolve_shipping_rate_source_label( $rate );
		}

		if ( '' === $source ) {
			return is_string( $label ) ? trim( $label ) : '';
		}

		$context = self::shipping_rate_storage_context( $rate, $source );

		return self::translate_storefront_config_string( $source, $context, $lang );
	}

	/**
	 * Translate a JetCheckout / WooCommerce shipping method description.
	 *
	 * @param string $description Raw description from zone instance settings.
	 * @param string $rate_id     Shipping rate id (e.g. flat_rate:1).
	 * @param string $lang        Optional language override.
	 * @return string
	 */
	public static function translate_shipping_rate_description( $description, $rate_id = '', $lang = '' ) {
		$source = is_string( $description ) ? trim( wp_strip_all_tags( html_entity_decode( $description, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) : '';

		if ( '' === $source ) {
			return is_string( $description ) ? trim( $description ) : '';
		}

		$rate_id = is_string( $rate_id ) ? trim( $rate_id ) : '';
		$context = '' !== $rate_id
			? 'wc_shipping_desc:' . sanitize_key( str_replace( ':', '_', $rate_id ) )
			: 'wc_shipping_desc:' . sanitize_title( $source );

		return self::translate_storefront_config_string( $source, $context, $lang );
	}

	/**
	 * Translate a WooCommerce payment gateway title.
	 *
	 * @param string $title      Gateway title from settings.
	 * @param string $gateway_id Gateway id (e.g. bacs, cod).
	 * @param string $lang       Optional language override.
	 * @return string
	 */
	public static function translate_payment_gateway_title( $title, $gateway_id = '', $lang = '' ) {
		$source = is_string( $title ) ? trim( wp_strip_all_tags( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) : '';

		if ( '' === $source ) {
			$source = self::resolve_payment_gateway_source_text( $gateway_id, 'title' );
		}

		if ( '' === $source ) {
			return is_string( $title ) ? trim( $title ) : '';
		}

		$context = self::payment_gateway_storage_context( $gateway_id, 'title', $source );

		return self::translate_storefront_config_string( $source, $context, $lang );
	}

	/**
	 * Translate a WooCommerce payment gateway description/instructions.
	 *
	 * @param string $description Gateway description from settings.
	 * @param string $gateway_id  Gateway id (e.g. bacs, cod).
	 * @param string $lang        Optional language override.
	 * @return string
	 */
	public static function translate_payment_gateway_description( $description, $gateway_id = '', $lang = '' ) {
		$source = is_string( $description ) ? trim( wp_strip_all_tags( html_entity_decode( $description, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) : '';

		if ( '' === $source ) {
			$source = self::resolve_payment_gateway_source_text( $gateway_id, 'description' );
		}

		if ( '' === $source ) {
			return is_string( $description ) ? trim( $description ) : '';
		}

		$context = self::payment_gateway_storage_context( $gateway_id, 'description', $source );

		return self::translate_storefront_config_string( $source, $context, $lang );
	}

	/**
	 * Shared lookup for Persian WooCommerce checkout configuration strings.
	 *
	 * @param string $source  Normalized Persian source text.
	 * @param string $context Stable storage context.
	 * @param string $lang    Optional language override.
	 * @return string
	 */
	public static function translate_storefront_config_string( $source, $context, $lang = '' ) {
		$source = is_string( $source ) ? trim( $source ) : '';

		if ( '' === $source ) {
			return '';
		}

		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			$lang = sanitize_key( Url_Router::get_current_language() );
		}

		if ( '' === $lang || $lang === Language_Registry::get_default_language_code() ) {
			return $source;
		}

		$context = is_string( $context ) ? trim( $context ) : '';

		$stored = UI_String_Registry::lookup( $lang, $source, '' );

		if ( null !== $stored && '' !== trim( $stored ) ) {
			return $stored;
		}

		if ( '' !== $context ) {
			$stored = UI_String_Registry::lookup( $lang, $source, $context );

			if ( null !== $stored && '' !== trim( $stored ) ) {
				return $stored;
			}
		}

		$strings = Runtime_String_Translator::get_theme_strings();

		if ( '' !== $context && isset( $strings[ $context ] ) && is_string( $strings[ $context ] ) && '' !== trim( $strings[ $context ] ) ) {
			return $strings[ $context ];
		}

		if ( Persian_Detector::contains_persian( $source ) ) {
			$translated = Runtime_String_Translator::translate( $source, $lang, $context );

			if ( is_string( $translated ) && '' !== trim( $translated ) ) {
				return $translated;
			}
		}

		return $source;
	}

	/**
	 * Read the raw Persian payment gateway field configured in wp_options.
	 *
	 * @param string $gateway_id Gateway id.
	 * @param string $field        title|description.
	 * @return string
	 */
	private static function resolve_payment_gateway_source_text( $gateway_id, $field ) {
		$gateway_id = sanitize_key( (string) $gateway_id );

		if ( '' === $gateway_id ) {
			return '';
		}

		$settings = get_option( 'woocommerce_' . $gateway_id . '_settings', array() );

		if ( ! is_array( $settings ) ) {
			return '';
		}

		$key = 'description' === $field ? 'description' : 'title';

		if ( empty( $settings[ $key ] ) || ! is_string( $settings[ $key ] ) ) {
			return '';
		}

		return trim( wp_strip_all_tags( $settings[ $key ] ) );
	}

	/**
	 * Build a stable cache key for one payment gateway field.
	 *
	 * @param string $gateway_id Gateway id.
	 * @param string $field      title|description.
	 * @param string $source     Source text fallback slug.
	 * @return string
	 */
	private static function payment_gateway_storage_context( $gateway_id, $field, $source ) {
		$gateway_id = sanitize_key( (string) $gateway_id );
		$field      = 'description' === $field ? 'description' : 'title';

		if ( '' !== $gateway_id ) {
			return 'wc_payment_gateway:' . $gateway_id . ':' . $field;
		}

		$slug = sanitize_title( $source );

		return '' !== $slug ? 'wc_payment_gateway:' . $field . ':' . $slug : 'wc_payment_gateway:unknown';
	}

	/**
	 * Read the raw Persian shipping method title configured in WooCommerce zones.
	 *
	 * @param \WC_Shipping_Rate $rate Shipping rate.
	 * @return string
	 */
	private static function resolve_shipping_rate_source_label( \WC_Shipping_Rate $rate ) {
		$method_id   = (string) $rate->get_method_id();
		$instance_id = (int) $rate->get_instance_id();

		if ( '' !== $method_id && $instance_id > 0 ) {
			$settings = get_option( 'woocommerce_' . $method_id . '_' . $instance_id . '_settings', array() );

			if ( is_array( $settings ) && ! empty( $settings['title'] ) && is_string( $settings['title'] ) ) {
				$title = trim( wp_strip_all_tags( $settings['title'] ) );

				if ( '' !== $title ) {
					return $title;
				}
			}
		}

		if ( is_array( $rate->get_meta_data() ) ) {
			foreach ( $rate->get_meta_data() as $meta ) {
				if ( ! is_object( $meta ) || ! method_exists( $meta, 'get_key' ) ) {
					continue;
				}

				if ( in_array( (string) $meta->get_key(), array( 'Items', 'item' ), true ) ) {
					continue;
				}

				$value = method_exists( $meta, 'get_value' ) ? $meta->get_value() : '';

				if ( is_string( $value ) && '' !== trim( $value ) && Persian_Detector::contains_persian( $value ) ) {
					return trim( wp_strip_all_tags( $value ) );
				}
			}
		}

		$data = $rate->get_data();

		if ( isset( $data['label'] ) && is_string( $data['label'] ) ) {
			return trim( wp_strip_all_tags( $data['label'] ) );
		}

		return '';
	}

	/**
	 * Build a stable cache key for one shipping rate label.
	 *
	 * @param \WC_Shipping_Rate|null $rate   Shipping rate.
	 * @param string                 $source Source label text.
	 * @return string
	 */
	private static function shipping_rate_storage_context( $rate, $source ) {
		if ( $rate instanceof \WC_Shipping_Rate ) {
			$rate_id = (string) $rate->get_id();

			if ( '' !== $rate_id ) {
				return 'wc_shipping_rate:' . sanitize_key( $rate_id );
			}
		}

		$slug = sanitize_title( $source );

		return '' !== $slug ? 'wc_shipping_rate:' . $slug : 'wc_shipping_rate:unknown';
	}

	/**
	 * Translate shop and archive page titles when a companion exists.
	 *
	 * @param string $title Page title.
	 * @return string
	 */
	public function filter_page_title( $title ) {
		if ( ! $this->should_intercept() || ! is_string( $title ) ) {
			return $title;
		}

		return $this->lookup_theme_string( $title, 'wc_page_title:' . sanitize_title( $title ) );
	}

	/**
	 * Swap single-product document titles with stored storefront translations.
	 *
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public function filter_document_title_parts( $parts ) {
		if ( ! $this->should_intercept() || ! is_array( $parts ) || ! function_exists( 'is_product' ) || ! is_product() ) {
			return $parts;
		}

		$product_id = get_queried_object_id();

		if ( $product_id <= 0 ) {
			return $parts;
		}

		$translated = Post_Translator::resolve_storefront_core_field(
			$product_id,
			'post_title',
			$this->get_active_lang()
		);

		if ( '' === $translated ) {
			return $parts;
		}

		if ( isset( $parts['title'] ) && is_string( $parts['title'] ) ) {
			$parts['title'] = $translated;
		}

		return $parts;
	}

	/**
	 * Translate category/tag titles on English URLs.
	 *
	 * @param string $title Term title.
	 * @return string
	 */
	public function filter_term_title( $title ) {
		if ( ! $this->should_intercept() || ! is_string( $title ) ) {
			return $title;
		}

		$queried = get_queried_object();

		if ( $queried instanceof \WP_Term ) {
			$translated = $this->resolve_term_field( $queried, 'name' );

			if ( '' !== $translated ) {
				return $translated;
			}
		}

		return $this->lookup_theme_string( $title, 'term_title:' . sanitize_title( $title ) );
	}

	/**
	 * Swap term name/description when English meta exists.
	 *
	 * @param \WP_Term|\WP_Error|null $term     Term object.
	 * @param string                  $taxonomy Taxonomy slug.
	 * @return \WP_Term|\WP_Error|null
	 */
	public function filter_term_object( $term, $taxonomy ) {
		unset( $taxonomy );

		if ( ! $this->should_intercept() || ! $term instanceof \WP_Term ) {
			return $term;
		}

		$name_val = $this->resolve_term_field( $term, 'name' );

		if ( '' !== $name_val ) {
			$term->name = $name_val;
		}

		$desc_val = $this->resolve_term_field( $term, 'desc' );

		if ( '' !== $desc_val ) {
			$term->description = $desc_val;
		}

		return $term;
	}

	/**
	 * Translate terms attached to a post/product.
	 *
	 * @param \WP_Term[]|\WP_Error|false $terms    Terms.
	 * @param int                        $post_id  Post ID.
	 * @param string                     $taxonomy Taxonomy.
	 * @return \WP_Term[]|\WP_Error|false
	 */
	public function filter_post_terms( $terms, $post_id, $taxonomy ) {
		unset( $post_id, $taxonomy );

		if ( ! $this->should_intercept() || is_wp_error( $terms ) || empty( $terms ) || ! is_array( $terms ) ) {
			return $terms;
		}

		foreach ( $terms as $index => $term ) {
			if ( $term instanceof \WP_Term ) {
				$terms[ $index ] = $this->filter_term_object( clone $term, $term->taxonomy );
			}
		}

		return $terms;
	}

	/**
	 * Translate term lists returned by get_terms().
	 *
	 * @param \WP_Term[]|int[]|string[]|\WP_Error $terms      Terms.
	 * @param array|string                        $taxonomies Taxonomies.
	 * @param array                               $args       Query args.
	 * @param \WP_Term_Query                     $term_query Term query.
	 * @return mixed
	 */
	public function filter_terms_list( $terms, $taxonomies, $args, $term_query ) {
		unset( $taxonomies, $args, $term_query );

		if ( ! $this->should_intercept() || is_wp_error( $terms ) || empty( $terms ) || ! is_array( $terms ) ) {
			return $terms;
		}

		foreach ( $terms as $index => $term ) {
			if ( $term instanceof \WP_Term ) {
				$terms[ $index ] = $this->filter_term_object( clone $term, $term->taxonomy );
			}
		}

		return $terms;
	}

	/**
	 * Translate attribute labels when stored.
	 *
	 * @param string $label     Attribute label.
	 * @param string $name      Attribute name.
	 * @param mixed  $product   Product object.
	 * @return string
	 */
	public function filter_attribute_label( $label, $name = '', $product = null ) {
		unset( $product );

		if ( ! $this->should_intercept() || ! is_string( $label ) ) {
			return $label;
		}

		$runtime_label = $this->translate_attribute_option_text( $label, (string) $name, 'wc_attr_label:' );

		if ( $runtime_label !== $label ) {
			return $runtime_label;
		}

		$storage = 'wc_attribute:' . sanitize_title( (string) $name );
		$stored  = UI_String_Registry::lookup( $this->get_active_lang(), $label, '' );

		if ( null !== $stored ) {
			return $stored;
		}

		if ( is_string( $name ) && 0 === strpos( $name, 'pa_' ) && taxonomy_exists( $name ) ) {
			$taxonomy_label = $this->lookup_global_attribute_label( $name, $label );

			if ( '' !== $taxonomy_label ) {
				return $taxonomy_label;
			}
		}

		return $this->lookup_theme_string( $label, $storage );
	}

	/**
	 * Translate terms returned by wc_get_product_terms().
	 *
	 * WooCommerce caches attribute terms separately from get_terms(), so the
	 * generic get_terms filter alone misses variation dropdowns and the APD
	 * attributes table.
	 *
	 * @param \WP_Term[] $terms      Product terms.
	 * @param int        $product_id Product ID.
	 * @param string     $taxonomy   Taxonomy slug.
	 * @param array      $args       Query args.
	 * @return \WP_Term[]
	 */
	public function filter_product_terms( $terms, $product_id, $taxonomy, $args ) {
		unset( $product_id, $taxonomy, $args );

		return $this->filter_post_terms( $terms, 0, '' );
	}

	/**
	 * Translate custom (non-taxonomy) attribute values in the specs table.
	 *
	 * @param string         $value     Rendered attribute HTML.
	 * @param \WC_Product_Attribute $attribute Attribute object.
	 * @param string[]       $values    Raw option values.
	 * @return string
	 */
	public function filter_product_attribute_value( $value, $attribute, $values ) {
		if ( ! $this->should_intercept() || ! is_object( $attribute ) || ! is_string( $value ) ) {
			return $value;
		}

		$attr_name = method_exists( $attribute, 'get_name' ) ? (string) $attribute->get_name() : '';
		$rendered  = $this->translate_attribute_value_fragments( $values, $attr_name, $attribute );

		if ( ! empty( $rendered ) ) {
			return wpautop( wptexturize( implode( ', ', $rendered ) ) );
		}

		$plain = trim( html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $plain ) {
			return $value;
		}

		return wpautop(
			wptexturize(
				esc_html( $this->translate_attribute_option_text( $plain, $attr_name ) )
			)
		);
	}

	/**
	 * Translate attribute table labels (including weight/dimensions rows).
	 *
	 * @param array<string, array{label:string, value:string}> $product_attributes Attribute rows.
	 * @param \WC_Product                                      $product            Product object.
	 * @return array<string, array{label:string, value:string}>
	 */
	public function filter_display_product_attributes( $product_attributes, $product ) {
		unset( $product );

		if ( ! $this->should_intercept() || ! is_array( $product_attributes ) ) {
			return $product_attributes;
		}

		foreach ( $product_attributes as $key => $row ) {
			if ( ! empty( $row['label'] ) && is_string( $row['label'] ) ) {
				$product_attributes[ $key ]['label'] = $this->translate_attribute_option_text(
					$row['label'],
					(string) $key,
					'wc_attr_label:'
				);
			}

			if ( ! empty( $row['value'] ) && is_string( $row['value'] ) ) {
				$product_attributes[ $key ]['value'] = $this->translate_attribute_table_html(
					$row['value'],
					(string) $key
				);
			}
		}

		return $product_attributes;
	}

	/**
	 * Translate variation JSON payloads embedded in the product form.
	 *
	 * @param array<string, mixed> $data      Variation data.
	 * @param \WC_Product          $product   Parent product.
	 * @param \WC_Product_Variation  $variation Variation product.
	 * @return array<string, mixed>
	 */
	public function filter_available_variation( $data, $product, $variation ) {
		if ( ! $this->should_intercept() || ! is_array( $data ) || ! $variation instanceof \WC_Product_Variation ) {
			return $data;
		}

		$variation_id = $variation->get_id();
		$parent_id    = $product instanceof \WC_Product ? $product->get_id() : 0;

		foreach ( $this->get_variation_translatable_fields() as $field ) {
			if ( empty( $data[ $field ] ) || ! is_string( $data[ $field ] ) ) {
				continue;
			}

			$data[ $field ] = $this->translate_variation_field(
				$field,
				$data[ $field ],
				$variation_id,
				$parent_id
			);
		}

		return $data;
	}

	/**
	 * Translate attribute option labels in variation dropdowns.
	 *
	 * @param string              $option_label Display label.
	 * @param \WP_Term|string|null $term         Term object or custom value.
	 * @param string              $taxonomy     Taxonomy slug or attribute name.
	 * @param \WC_Product         $product      Product object.
	 * @return string
	 */
	public function filter_variation_option_name( $option_label, $term = null, $taxonomy = '', $product = null ) {
		if ( ! $this->should_intercept() || ! is_string( $option_label ) || '' === trim( $option_label ) ) {
			return $option_label;
		}

		$product_id = 0;

		if ( $product instanceof \WC_Product ) {
			$product_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		}

		if ( $product_id <= 0 ) {
			$product_id = $this->resolve_current_product_id();
		}

		if ( $term instanceof \WP_Term ) {
			$translated = $this->resolve_term_field( $term, 'name' );

			if ( '' !== $translated ) {
				return $translated;
			}
		}

		if ( is_string( $taxonomy ) && taxonomy_exists( $taxonomy ) && is_string( $term ) && '' !== $term ) {
			$resolved = get_term_by( 'slug', $term, $taxonomy );

			if ( $resolved instanceof \WP_Term ) {
				$translated = $this->resolve_term_field( $resolved, 'name' );

				if ( '' !== $translated ) {
					return $translated;
				}
			}
		}

		if ( $product_id > 0 ) {
			$stored = Post_Translator::get_stored_product_attribute_translation(
				$product_id,
				$option_label,
				$this->get_active_lang(),
				'wc_attr_opt:' . $this->attribute_field_group( (string) $taxonomy )
			);

			if ( '' !== $stored ) {
				return $stored;
			}
		}

		$translated = $this->translate_attribute_option_text( $option_label, (string) $taxonomy, 'wc_attr_opt:', $product_id );

		if ( $translated !== $option_label ) {
			return $translated;
		}

		// Product-page swatches call this filter without a taxonomy context.
		if ( $product_id > 0 ) {
			$stored = Post_Translator::get_stored_product_attribute_translation(
				$product_id,
				$option_label,
				$this->get_active_lang(),
				'wc_attr_opt:' . $this->attribute_field_group( '' )
			);

			if ( '' !== $stored ) {
				return $stored;
			}
		}

		return $this->translate_attribute_option_text( $option_label, '', 'wc_attr_opt:', $product_id );
	}

	/**
	 * Translate variation titles used by cart line items and order summaries.
	 *
	 * @param string              $title        Full variation title.
	 * @param \WC_Product         $product        Variation product.
	 * @param string              $title_base     Parent product title.
	 * @param string              $title_suffix   Comma-separated attribute values.
	 * @return string
	 */
	public function filter_variation_title( $title, $product, $title_base, $title_suffix ) {
		unset( $title_base, $title_suffix );

		if ( ! $this->should_intercept() || ! $product instanceof \WC_Product || ! $product->is_type( 'variation' ) ) {
			return is_string( $title ) ? $title : '';
		}

		$resolved = $this->resolve_variation_display_name(
			$product,
			is_string( $title ) ? $title : $product->get_name()
		);

		return '' !== trim( $resolved ) ? $resolved : ( is_string( $title ) ? $title : $product->get_name() );
	}

	/**
	 * Translate cart / mini-cart line item titles for variable products.
	 *
	 * WoodMart prints $_product->get_name() in the side cart. Variation titles
	 * embed Persian attribute values that wc_get_formatted_cart_item_data() omits
	 * when the value is already part of the product name.
	 *
	 * @param string              $name          Product name.
	 * @param array<string,mixed> $cart_item     Cart line.
	 * @param string              $cart_item_key Cart item key.
	 * @return string
	 */
	public function filter_cart_item_name( $name, $cart_item, $cart_item_key ) {
		unset( $cart_item_key );

		if ( ! $this->should_intercept() || ! is_string( $name ) || '' === trim( $name ) ) {
			return $name;
		}

		$product = isset( $cart_item['data'] ) && $cart_item['data'] instanceof \WC_Product
			? $cart_item['data']
			: null;

		if ( ! $product ) {
			return $name;
		}

		if ( $product->is_type( 'variation' ) ) {
			$resolved = $this->resolve_variation_display_name( $product, $name );

			return '' !== trim( $resolved ) ? $resolved : $name;
		}

		$post_id = (int) $product->get_id();

		if ( $post_id <= 0 ) {
			return $name;
		}

		$translated = Post_Translator::resolve_storefront_title( $post_id, $this->get_active_lang(), false );

		return '' !== trim( $translated ) ? $translated : $name;
	}

	/**
	 * Translate variation attribute rows shown in cart and mini-cart widgets.
	 *
	 * wc_get_formatted_cart_item_data() assigns taxonomy term names directly and
	 * skips woocommerce_variation_option_name, so /en/ pages keep Persian values
	 * such as "2 کیلو" even when the product page already shows "2kg".
	 *
	 * @param array<int, array<string, mixed>> $item_data Cart item meta rows.
	 * @param array<string, mixed>             $cart_item Cart line item.
	 * @return array<int, array<string, mixed>>
	 */
	public function filter_cart_item_data( $item_data, $cart_item ) {
		if ( ! $this->should_intercept() || ! is_array( $item_data ) || empty( $item_data ) ) {
			return $item_data;
		}

		$variation_map = $this->get_cart_variation_attribute_map( $cart_item );
		$product       = isset( $cart_item['data'] ) && $cart_item['data'] instanceof \WC_Product
			? $cart_item['data']
			: null;

		foreach ( $item_data as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$label = isset( $row['key'] ) && is_string( $row['key'] ) ? $row['key'] : '';
			$value = isset( $row['value'] ) && is_string( $row['value'] ) ? $row['value'] : '';

			if ( '' === trim( $value ) ) {
				continue;
			}

			$context = $variation_map[ $value ] ?? null;

			if ( ! is_array( $context ) && '' !== $label ) {
				$context = $variation_map[ $label ] ?? null;
			}

			if ( is_array( $context ) && '' !== $label ) {
				$item_data[ $index ]['key'] = $this->filter_attribute_label(
					$label,
					(string) ( $context['taxonomy'] ?? '' ),
					$product
				);
			}

			$translated_value = is_array( $context )
				? $this->translate_cart_variation_value( $value, $context, $product )
				: $this->translate_attribute_option_text(
					$value,
					'',
					'wc_attr_opt:',
					$product instanceof \WC_Product
						? ( $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id() )
						: 0
				);

			if ( $translated_value !== $value ) {
				$item_data[ $index ]['value']   = $translated_value;
				$item_data[ $index ]['display'] = $translated_value;
			}
		}

		return $item_data;
	}

	/**
	 * Build a lookup map from displayed variation values to attribute context.
	 *
	 * @param array<string, mixed> $cart_item Cart line item.
	 * @return array<string, array{taxonomy:string, attr_name:string, raw:string, is_taxonomy:bool}>
	 */
	private function get_cart_variation_attribute_map( $cart_item ) {
		$map = array();

		if ( empty( $cart_item['variation'] ) || ! is_array( $cart_item['variation'] ) ) {
			return $map;
		}

		foreach ( $cart_item['variation'] as $attr_key => $raw_value ) {
			$decoded_key = urldecode( (string) $attr_key );
			$decoded_val = urldecode( (string) $raw_value );
			$slug        = str_replace( array( 'attribute_pa_', 'attribute_' ), '', $decoded_key );
			$taxonomy    = function_exists( 'wc_attribute_taxonomy_name' )
				? wc_attribute_taxonomy_name( $slug )
				: 'pa_' . $slug;
			$is_taxonomy = taxonomy_exists( $taxonomy );

			$context = array(
				'taxonomy'    => $is_taxonomy ? $taxonomy : $slug,
				'attr_name'   => $slug,
				'raw'         => $decoded_val,
				'is_taxonomy' => $is_taxonomy,
			);

			$map[ $decoded_val ] = $context;

			if ( $is_taxonomy ) {
				$term = get_term_by( 'slug', $decoded_val, $taxonomy );

				if ( ! $term && is_numeric( $decoded_val ) ) {
					$term = get_term( (int) $decoded_val, $taxonomy );
				}

				if ( $term instanceof \WP_Term ) {
					$map[ $term->name ] = $context;
				}
			}
		}

		return $map;
	}

	/**
	 * Translate one variation value row for cart / mini-cart display.
	 *
	 * @param string               $display_value Rendered value.
	 * @param array<string, mixed> $context       Attribute context.
	 * @param \WC_Product|null     $product       Cart product.
	 * @return string
	 */
	private function translate_cart_variation_value( $display_value, array $context, $product = null ) {
		$product_id = 0;
		$attr_name  = isset( $context['attr_name'] ) ? (string) $context['attr_name'] : '';

		if ( $product instanceof \WC_Product ) {
			$product_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		}

		$plain = $this->translate_attribute_option_text( $display_value, $attr_name, 'wc_attr_opt:', $product_id );

		if ( $plain !== $display_value ) {
			return $plain;
		}

		if ( ! empty( $context['is_taxonomy'] ) && ! empty( $context['taxonomy'] ) && is_string( $context['raw'] ) ) {
			$taxonomy = (string) $context['taxonomy'];
			$term     = get_term_by( 'slug', $context['raw'], $taxonomy );

			if ( ! $term && is_numeric( $context['raw'] ) ) {
				$term = get_term( (int) $context['raw'], $taxonomy );
			}

			if ( $term instanceof \WP_Term ) {
				$translated = $this->resolve_term_field( $term, 'name' );

				if ( '' !== $translated ) {
					return $translated;
				}

				return $this->filter_variation_option_name(
					$display_value,
					$term,
					$taxonomy,
					$product
				);
			}
		}

		return $this->filter_variation_option_name(
			$display_value,
			null,
			$attr_name,
			$product
		);
	}

	/**
	 * Resolve a variation title for storefront display.
	 *
	 * Prefers stored variation meta, then custom titles, then a rebuilt
	 * parent-name + attribute suffix.
	 *
	 * @param \WC_Product $product       Variation product.
	 * @param string      $fallback_name Original variation title.
	 * @return string
	 */
	private function resolve_variation_display_name( $product, $fallback_name = '' ) {
		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variation' ) ) {
			return is_string( $fallback_name ) ? $fallback_name : '';
		}

		$variation_id  = $product->get_id();
		$fallback      = is_string( $fallback_name ) && '' !== trim( $fallback_name ) ? $fallback_name : $product->get_name();
		$source_key    = '' !== trim( get_post_meta( $variation_id, Post_Translator::WVE_VARIATION_CUSTOM_TITLE_META, true ) )
			? Post_Translator::WVE_VARIATION_CUSTOM_TITLE_META
			: 'post_title';
		$stored        = Post_Translator::resolve_storefront_field( $variation_id, $source_key, $this->get_active_lang() );

		if ( is_string( $stored ) && '' !== trim( $stored ) ) {
			return $stored;
		}

		$custom_title  = Post_Translator::get_variation_custom_title( $variation_id );

		if ( '' !== $custom_title && Persian_Detector::contains_persian( $custom_title ) ) {
			$runtime = $this->lookup_theme_string(
				$custom_title,
				Post_Translator::WVE_VARIATION_CUSTOM_TITLE_META === $source_key
					? 'wc_variation_custom_title:' . $variation_id
					: 'wc_variation_title:' . $variation_id
			);

			if ( '' !== trim( $runtime ) && $runtime !== $custom_title ) {
				return $runtime;
			}
		}

		$rebuilt = $this->build_translated_variation_name( $product, $fallback );

		if ( '' !== trim( $rebuilt ) && $rebuilt !== $fallback ) {
			return $rebuilt;
		}

		if ( Persian_Detector::contains_persian( $fallback ) ) {
			return $this->replace_persian_attribute_values_in_text( $fallback, $product );
		}

		return $fallback;
	}

	/**
	 * Whether a variation keeps a custom admin title instead of the auto label.
	 *
	 * @param \WC_Product $product      Variation product.
	 * @param string      $source_title Variation post title.
	 * @return bool
	 */
	private function variation_uses_custom_title( $product, $source_title = '' ) {
		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variation' ) ) {
			return false;
		}

		$source_title = trim( (string) $source_title );

		if ( '' === $source_title ) {
			$source_title = trim( (string) get_post_field( 'post_title', $product->get_id() ) );
		}

		if ( '' === $source_title ) {
			return false;
		}

		$parent_id = $product->get_parent_id();

		if ( $parent_id <= 0 ) {
			return true;
		}

		$parent_title = trim( (string) get_post_field( 'post_title', $parent_id ) );

		if ( '' === $parent_title ) {
			return true;
		}

		return 0 !== strpos( $source_title, $parent_title );
	}

	/**
	 * Resolve the parent product ID for variation dropdown translation context.
	 *
	 * @return int
	 */
	private function resolve_current_product_id() {
		$product = $this->normalize_product( null );

		if ( ! $product instanceof \WC_Product ) {
			return 0;
		}

		return $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
	}

	/**
	 * Rebuild a variation title with translated parent name and attribute values.
	 *
	 * @param \WC_Product $product       Variation product.
	 * @param string      $fallback_name Original variation title.
	 * @return string
	 */
	private function build_translated_variation_name( $product, $fallback_name = '' ) {
		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variation' ) ) {
			return is_string( $fallback_name ) ? $fallback_name : '';
		}

		$parent_id = $product->get_parent_id();
		$base      = '';

		if ( $parent_id > 0 ) {
			$base = $this->get_meta_or_original(
				$parent_id,
				Post_Translator::get_meta_key( 'title', $this->get_active_lang() ),
				(string) get_post_field( 'post_title', $parent_id )
			);
		}

		$suffix_parts = array();

		foreach ( (array) $product->get_attributes() as $attr_name => $attr_value ) {
			if ( is_scalar( $attr_value ) && '' === trim( (string) $attr_value ) ) {
				continue;
			}

			$translated = $this->resolve_variation_attribute_display(
				(string) $attr_name,
				(string) $attr_value,
				$product
			);

			if ( '' !== trim( $translated ) ) {
				$suffix_parts[] = $translated;
			}
		}

		if ( empty( $suffix_parts ) ) {
			return $this->replace_persian_attribute_values_in_text(
				is_string( $fallback_name ) ? $fallback_name : $product->get_name(),
				$product
			);
		}

		$separator = apply_filters( 'woocommerce_product_variation_title_attributes_separator', ' - ', $product );
		$suffix    = implode( ', ', $suffix_parts );

		return '' !== trim( $base ) ? $base . $separator . $suffix : $suffix;
	}

	/**
	 * Resolve one variation attribute value for storefront display.
	 *
	 * @param string              $attr_name Attribute slug/taxonomy.
	 * @param string              $raw_value Stored attribute value.
	 * @param \WC_Product|null    $product   Variation product.
	 * @return string
	 */
	private function resolve_variation_attribute_display( $attr_name, $raw_value, $product = null ) {
		$decoded = urldecode( (string) $raw_value );
		$display = $decoded;
		$taxonomy = taxonomy_exists( $attr_name ) ? $attr_name : '';
		$parent_id = 0;

		if ( $product instanceof \WC_Product && $product->is_type( 'variation' ) ) {
			$parent_id = (int) $product->get_parent_id();
		} elseif ( $product instanceof \WC_Product ) {
			$parent_id = (int) $product->get_id();
		}

		if ( $parent_id <= 0 ) {
			$parent_id = $this->resolve_current_product_id();
		}

		if ( '' === $taxonomy && function_exists( 'wc_attribute_taxonomy_name' ) ) {
			$candidate = wc_attribute_taxonomy_name( $attr_name );

			if ( taxonomy_exists( $candidate ) ) {
				$taxonomy = $candidate;
			}
		}

		$term = null;

		if ( '' !== $taxonomy ) {
			$term = get_term_by( 'slug', $decoded, $taxonomy );

			if ( ! $term && is_numeric( $decoded ) ) {
				$term = get_term( (int) $decoded, $taxonomy );
			}

			if ( $term instanceof \WP_Term ) {
				$display = (string) $term->name;
			}
		}

		$translated = $this->translate_attribute_option_text(
			$display,
			(string) $attr_name,
			'wc_attr_opt:',
			$parent_id
		);

		if ( $translated !== $display ) {
			return $translated;
		}

		if ( $term instanceof \WP_Term ) {
			$from_term = $this->resolve_term_field( $term, 'name' );

			if ( '' !== $from_term ) {
				return $from_term;
			}
		}

		return $this->filter_variation_option_name(
			$display,
			$term,
			'' !== $taxonomy ? $taxonomy : (string) $attr_name,
			$product
		);
	}

	/**
	 * Replace known Persian variation values inside an already formatted title.
	 *
	 * @param string              $text    Source text.
	 * @param \WC_Product|null    $product Variation product.
	 * @return string
	 */
	private function replace_persian_attribute_values_in_text( $text, $product = null ) {
		if ( ! is_string( $text ) || '' === trim( $text ) || ! $product instanceof \WC_Product ) {
			return is_string( $text ) ? $text : '';
		}

		$updated = $text;

		foreach ( (array) $product->get_attributes() as $attr_name => $attr_value ) {
			if ( is_scalar( $attr_value ) && '' === trim( (string) $attr_value ) ) {
				continue;
			}

			$source = $this->resolve_variation_attribute_source_label( (string) $attr_name, (string) $attr_value );

			if ( '' === $source ) {
				continue;
			}

			$translated = $this->resolve_variation_attribute_display( (string) $attr_name, (string) $attr_value, $product );

			if ( '' !== $translated && $translated !== $source && false !== strpos( $updated, $source ) ) {
				$updated = str_replace( $source, $translated, $updated );
			}
		}

		return $updated;
	}

	/**
	 * Resolve the Persian storefront label for one variation attribute value.
	 *
	 * @param string $attr_name Attribute slug/taxonomy.
	 * @param string $raw_value Stored attribute value.
	 * @return string
	 */
	private function resolve_variation_attribute_source_label( $attr_name, $raw_value ) {
		$decoded = urldecode( (string) $raw_value );
		$taxonomy = taxonomy_exists( $attr_name ) ? $attr_name : '';

		if ( '' === $taxonomy && function_exists( 'wc_attribute_taxonomy_name' ) ) {
			$candidate = wc_attribute_taxonomy_name( $attr_name );

			if ( taxonomy_exists( $candidate ) ) {
				$taxonomy = $candidate;
			}
		}

		if ( '' !== $taxonomy ) {
			$term = get_term_by( 'slug', $decoded, $taxonomy );

			if ( ! $term && is_numeric( $decoded ) ) {
				$term = get_term( (int) $decoded, $taxonomy );
			}

			if ( $term instanceof \WP_Term && is_string( $term->name ) && '' !== trim( $term->name ) ) {
				return $term->name;
			}
		}

		return $decoded;
	}

	/**
	 * Translate product excerpts when templates bypass WooCommerce product filters.
	 *
	 * @param string          $excerpt Post excerpt.
	 * @param int|\WP_Post|null $post  Post object or ID.
	 * @return string
	 */
	public function filter_product_excerpt( $excerpt, $post = null ) {
		if ( ! $this->should_intercept() || ! is_string( $excerpt ) ) {
			return $excerpt;
		}

		$post = get_post( $post );

		if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
			return $excerpt;
		}

		return $this->get_meta_or_original(
			$post->ID,
			Post_Translator::get_meta_key( 'excerpt', $this->get_active_lang() ),
			$excerpt
		);
	}

	/**
	 * Translate WooCommerce gettext strings from the UI string registry.
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Source msgid.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filter_gettext( $translation, $text, $domain ) {
		return $this->filter_registry_gettext( $translation, $text, $domain, '' );
	}

	/**
	 * Translate contextual WooCommerce gettext strings.
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Source msgid.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filter_gettext_with_context( $translation, $text, $context, $domain ) {
		return $this->filter_registry_gettext(
			$translation,
			$text,
			$domain,
			is_string( $context ) ? $context : ''
		);
	}

	/**
	 * Translate plural WooCommerce gettext strings.
	 *
	 * @param string $translation Current translation.
	 * @param string $single      Singular form.
	 * @param string $plural      Plural form.
	 * @param int    $number      Count.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filter_ngettext( $translation, $single, $plural, $number, $domain ) {
		$form = ( 1 === (int) $number ) ? $single : $plural;

		return $this->filter_registry_gettext( $translation, $form, $domain, '' );
	}

	/**
	 * Translate breadcrumb home label.
	 *
	 * @param array<string, mixed> $defaults Breadcrumb defaults.
	 * @return array<string, mixed>
	 */
	public function filter_breadcrumb_defaults( $defaults ) {
		if ( ! $this->should_intercept() || ! is_array( $defaults ) || empty( $defaults['home'] ) ) {
			return $defaults;
		}

		$defaults['home'] = $this->lookup_theme_string( (string) $defaults['home'], 'wc_breadcrumb_home' );

		return $defaults;
	}

	/**
	 * Translate WooCommerce breadcrumb labels when term translations exist.
	 *
	 * @param array<int, array{0:string, 1:string}> $crumbs Breadcrumb rows.
	 * @param object                                $breadcrumb Breadcrumb object.
	 * @return array<int, array{0:string, 1:string}>
	 */
	public function filter_breadcrumbs( $crumbs, $breadcrumb = null ) {
		unset( $breadcrumb );

		if ( ! $this->should_intercept() || ! is_array( $crumbs ) ) {
			return $crumbs;
		}

		foreach ( $crumbs as $index => $crumb ) {
			if ( empty( $crumb[0] ) || ! is_string( $crumb[0] ) ) {
				continue;
			}

			$term = $this->find_term_by_label( $crumb[0] );

			if ( $term instanceof \WP_Term ) {
				$translated = $this->resolve_term_field( $term, 'name' );

				if ( '' !== $translated ) {
					$crumbs[ $index ][0] = $translated;
				}
			}
		}

		return $crumbs;
	}

	/**
	 * Translate custom product tab titles.
	 *
	 * @param array<string, array<string, mixed>> $tabs Product tabs.
	 * @return array<string, array<string, mixed>>
	 */
	public function filter_product_tabs( $tabs ) {
		if ( ! $this->should_intercept() || ! is_array( $tabs ) ) {
			return $tabs;
		}

		foreach ( $tabs as $key => $tab ) {
			if ( empty( $tab['title'] ) || ! is_string( $tab['title'] ) ) {
				continue;
			}

			$tabs[ $key ]['title'] = $this->lookup_theme_string( $tab['title'], 'wc_tab:' . $key );
		}

		return $tabs;
	}

	/**
	 * Return English meta when available.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param string $original Original value.
	 * @return string
	 */
	private function get_meta_or_original( $post_id, $meta_key, $original ) {
		$lang       = $this->get_active_lang();
		$source_key = Post_Translator::resolve_source_key_for_translated_meta( $meta_key, $lang, $post_id );

		if ( 'post_title' === $source_key ) {
			$resolved = Post_Translator::resolve_storefront_title( $post_id, $lang, false );

			return '' !== $resolved ? $resolved : $original;
		}

		if ( '' !== $source_key ) {
			$resolved = Post_Translator::resolve_storefront_field( $post_id, $source_key, $lang );

			if ( '' !== $resolved ) {
				return $resolved;
			}
		}

		$stored = get_post_meta( $post_id, $meta_key, true );

		if ( is_string( $stored ) && Post_Translator::is_usable_storefront_translation( $stored, $this->get_active_lang() ) ) {
			return $stored;
		}

		return $original;
	}

	/**
	 * Re-apply WooCommerce paragraph/line-break formatting after translation.
	 *
	 * PolyMart runs on `woocommerce_short_description` after WooCommerce `wpautop`,
	 * so translated plain-text excerpts must be formatted again for display.
	 *
	 * @param string $text   Translated excerpt.
	 * @param string $filter Current filter name.
	 * @return string
	 */
	private function maybe_format_product_short_description( $text, $filter ) {
		if ( 'woocommerce_short_description' !== $filter || ! is_string( $text ) || '' === trim( $text ) ) {
			return $text;
		}

		if ( preg_match( '/<(p|br|div|ul|ol|li|table)\b/i', $text ) ) {
			return $text;
		}

		return wpautop( wptexturize( $text ) );
	}

	/**
	 * Look up a theme/Woo string translation from options.
	 *
	 * @param string $value   Original value.
	 * @param string $storage Storage key.
	 * @return string
	 */
	private function lookup_theme_string( $value, $storage ) {
		$value = is_string( $value ) ? $value : '';

		if ( '' === trim( $value ) ) {
			return $value;
		}

		$stored = UI_String_Registry::lookup( $this->get_active_lang(), $value, '' );

		if ( null !== $stored && '' !== trim( $stored ) ) {
			return $stored;
		}

		$strings = Runtime_String_Translator::get_theme_strings();

		if ( isset( $strings[ $storage ] ) && is_string( $strings[ $storage ] ) && '' !== trim( $strings[ $storage ] ) ) {
			return $strings[ $storage ];
		}

		if ( Persian_Detector::contains_persian( $value ) ) {
			$translated = Runtime_String_Translator::translate( $value, $this->get_active_lang(), $storage );

			if ( is_string( $translated ) && '' !== trim( $translated ) ) {
				return $translated;
			}
		}

		return $value;
	}

	/**
	 * Normalize attribute slug/name for stable runtime cache keys.
	 *
	 * @param string $attr_name Attribute slug or label.
	 * @return string
	 */
	private function attribute_field_group( $attr_name ) {
		$name = sanitize_title( str_replace( 'pa_', '', (string) $attr_name ) );

		return '' !== $name ? $name : 'custom';
	}

	/**
	 * Translate one attribute option/label using a stable cache context.
	 *
	 * @param string $text        Source text.
	 * @param string $attr_name   Attribute slug/name.
	 * @param string $prefix      Storage prefix.
	 * @return string
	 */
	private function translate_attribute_option_text( $text, $attr_name, $prefix = 'wc_attr_opt:', $product_id = 0 ) {
		$text = trim( wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) );

		if ( '' === $text ) {
			return $text;
		}

		$product_id = absint( $product_id );
		$context    = $prefix . $this->attribute_field_group( $attr_name );

		if ( $product_id > 0 ) {
			$stored = Post_Translator::get_stored_product_attribute_translation(
				$product_id,
				$text,
				$this->get_active_lang(),
				$context
			);

			if ( '' !== $stored ) {
				return $stored;
			}
		}

		if ( ! Persian_Detector::contains_persian( $text ) ) {
			return $text;
		}

		$translated = $this->lookup_theme_string( $text, $context );

		if ( $translated !== $text ) {
			return $translated;
		}

		if ( is_string( $attr_name ) && 0 === strpos( $attr_name, 'pa_' ) ) {
			$legacy = $this->lookup_theme_string( $text, $prefix . sanitize_title( $attr_name ) );

			if ( $legacy !== $text ) {
				return $legacy;
			}
		}

		return $text;
	}

	/**
	 * Translate attribute table/spec HTML while preserving links when present.
	 *
	 * @param string $html    Rendered attribute HTML.
	 * @param string $row_key Attribute row key.
	 * @return string
	 */
	private function translate_attribute_table_html( $html, $row_key ) {
		if ( ! Persian_Detector::contains_persian( $html ) ) {
			return $html;
		}

		$group = $this->attribute_field_group( $row_key );

		if ( preg_match_all( '/<a\b[^>]*href=(["\'])([^"\']+)\1[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$plain = trim( html_entity_decode( wp_strip_all_tags( (string) ( $match[3] ?? '' ) ), ENT_QUOTES, 'UTF-8' ) );

				if ( '' === $plain ) {
					continue;
				}

				$translated = esc_html( $this->translate_attribute_option_text( $plain, $group ) );
				$replacement = '<a href="' . esc_url( (string) ( $match[2] ?? '' ) ) . '" rel="tag">' . $translated . '</a>';
				$html        = str_replace( (string) $match[0], $replacement, $html );
			}

			return $html;
		}

		$plain = trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $plain ) {
			return $html;
		}

		$translated = $this->translate_attribute_option_text( $plain, $group );

		if ( $translated === $plain ) {
			return $html;
		}

		if ( false !== strpos( $html, $plain ) ) {
			return str_replace( $plain, esc_html( $translated ), $html );
		}

		return esc_html( $translated );
	}

	/**
	 * Rebuild attribute value fragments (taxonomy + custom) for translated display.
	 *
	 * @param mixed                   $values    Raw values from WooCommerce.
	 * @param string                  $attr_name Attribute slug/name.
	 * @param \WC_Product_Attribute|null $attribute Attribute object.
	 * @return string[]
	 */
	private function translate_attribute_value_fragments( $values, $attr_name, $attribute ) {
		$rendered = array();

		if ( is_object( $attribute ) && method_exists( $attribute, 'get_options' ) ) {
			foreach ( (array) $attribute->get_options() as $option ) {
				if ( is_numeric( $option ) ) {
					$term = get_term( (int) $option );

					if ( $term instanceof \WP_Term ) {
						$rendered[] = esc_html( $this->resolve_term_field( $term, 'name' ) ?: $term->name );
						continue;
					}
				}

				$plain = trim( (string) $option );

				if ( '' !== $plain ) {
					$rendered[] = esc_html( $this->translate_attribute_option_text( $plain, $attr_name ) );
				}
			}
		}

		if ( ! empty( $rendered ) ) {
			return $rendered;
		}

		if ( ! is_array( $values ) ) {
			return $rendered;
		}

		foreach ( $values as $raw ) {
			if ( $raw instanceof \WP_Term ) {
				$rendered[] = esc_html( $this->resolve_term_field( $raw, 'name' ) ?: $raw->name );
				continue;
			}

			$raw_string = (string) $raw;
			$plain      = trim( html_entity_decode( wp_strip_all_tags( $raw_string ), ENT_QUOTES, 'UTF-8' ) );

			if ( '' === $plain ) {
				continue;
			}

			$translated = esc_html( $this->translate_attribute_option_text( $plain, $attr_name ) );

			if ( preg_match( '/href=(["\'])([^"\']+)\1/', $raw_string, $href_match ) ) {
				$rendered[] = '<a href="' . esc_url( (string) ( $href_match[2] ?? '' ) ) . '" rel="tag">' . $translated . '</a>';
			} else {
				$rendered[] = $translated;
			}
		}

		return $rendered;
	}

	/**
	 * Variation JSON keys that may contain user-facing text.
	 *
	 * Attribute slugs are intentionally excluded — they must stay stable for JS matching.
	 *
	 * @return string[]
	 */
	private function get_variation_translatable_fields() {
		return array(
			'variation_description',
			'price_html',
			'availability_html',
			'dimensions_html',
			'name',
			'variation_title',
			'display_name',
			'custom_title',
			'custom_description',
		);
	}

	/**
	 * Translate one variation payload string field.
	 *
	 * @param string $field        Payload key.
	 * @param string $value        Original value.
	 * @param int    $variation_id Variation post ID.
	 * @param int    $parent_id    Parent product ID.
	 * @return string
	 */
	private function translate_variation_field( $field, $value, $variation_id, $parent_id ) {
		if ( 'custom_title' === $field ) {
			return $this->translate_variation_custom_field(
				$variation_id,
				Post_Translator::WVE_VARIATION_CUSTOM_TITLE_META,
				$value,
				'title',
				'wc_variation_custom_title:'
			);
		}

		if ( 'custom_description' === $field ) {
			return $this->translate_variation_custom_field(
				$variation_id,
				Post_Translator::WVE_VARIATION_CUSTOM_DESCRIPTION_META,
				$value,
				'excerpt',
				'wc_variation_custom_description:'
			);
		}

		if ( 'variation_description' === $field ) {
			$translated = $this->get_meta_or_original(
				$variation_id,
				Post_Translator::get_meta_key( 'content', $this->get_active_lang() ),
				''
			);

			if ( '' === trim( $translated ) ) {
				$translated = $this->get_meta_or_original(
					$variation_id,
					Post_Translator::get_meta_key( 'excerpt', $this->get_active_lang() ),
					''
				);
			}

			if ( '' !== trim( $translated ) ) {
				return wp_kses_post( $translated );
			}
		}

		if ( in_array( $field, array( 'name', 'variation_title', 'display_name' ), true ) ) {
			$variation = wc_get_product( $variation_id );

			if ( $variation instanceof \WC_Product_Variation ) {
				$rebuilt = $this->resolve_variation_display_name( $variation, $value );

				if ( '' !== trim( $rebuilt ) && $rebuilt !== $value ) {
					return $rebuilt;
				}
			}

			$translated = $this->get_meta_or_original(
				$variation_id,
				Post_Translator::get_meta_key( 'title', $this->get_active_lang() ),
				''
			);

			if ( '' === trim( $translated ) && $parent_id > 0 ) {
				$translated = $this->get_meta_or_original(
					$parent_id,
					Post_Translator::get_meta_key( 'title', $this->get_active_lang() ),
					''
				);
			}

			if ( '' !== trim( $translated ) ) {
				return $translated;
			}
		}

		return $this->lookup_theme_string( $value, 'wc_variation_field:' . sanitize_key( $field ) . ':' . md5( wp_strip_all_tags( $value ) ) );
	}

	/**
	 * Translate one WoodMart Variable Enhancer custom variation field.
	 *
	 * @param int    $variation_id Variation post ID.
	 * @param string $source_key   Persian source field identifier.
	 * @param string $value        Original storefront value.
	 * @param string $meta_field   title|excerpt PolyMart meta bucket.
	 * @param string $runtime_prefix Runtime cache prefix.
	 * @return string
	 */
	private function translate_variation_custom_field( $variation_id, $source_key, $value, $meta_field, $runtime_prefix ) {
		$variation_id = absint( $variation_id );
		$value        = is_string( $value ) ? $value : '';

		if ( $variation_id <= 0 || '' === trim( $value ) ) {
			return $value;
		}

		$translated = Post_Translator::resolve_storefront_field(
			$variation_id,
			$source_key,
			$this->get_active_lang()
		);

		if ( '' !== trim( $translated ) ) {
			return 'excerpt' === $meta_field ? wp_kses_post( $translated ) : $translated;
		}

		return $this->lookup_theme_string( $value, $runtime_prefix . $variation_id );
	}

	/**
	 * Resolve a translated global attribute taxonomy label.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $label    Original label.
	 * @return string
	 */
	private function lookup_global_attribute_label( $taxonomy, $label ) {
		$stored = UI_String_Registry::lookup( $this->get_active_lang(), $label, '' );

		if ( null !== $stored ) {
			return $stored;
		}

		$runtime_label = $this->translate_attribute_option_text( $label, (string) $taxonomy, 'wc_attr_label:' );

		if ( $runtime_label !== $label ) {
			return $runtime_label;
		}

		if ( function_exists( 'wc_get_attribute' ) && 0 === strpos( $taxonomy, 'pa_' ) ) {
			$attribute_name = function_exists( 'wc_sanitize_taxonomy_name' )
				? wc_sanitize_taxonomy_name( str_replace( 'pa_', '', $taxonomy ) )
				: sanitize_title( str_replace( 'pa_', '', $taxonomy ) );
			$attribute_id   = wc_attribute_taxonomy_id_by_name( $attribute_name );

			if ( $attribute_id ) {
				$attribute = wc_get_attribute( $attribute_id );

				if ( is_object( $attribute ) && ! empty( $attribute->name ) ) {
					$translated = $this->lookup_theme_string( (string) $attribute->name, 'wc_attribute_tax:' . $taxonomy );

					if ( $translated !== (string) $attribute->name ) {
						return $translated;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Shared WooCommerce gettext lookup from the UI string registry.
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Source msgid.
	 * @param string $domain      Text domain.
	 * @param string $context     Optional context.
	 * @return string
	 */
	private function filter_registry_gettext( $translation, $text, $domain, $context ) {
		if ( $this->gettext_depth > 0 || ! $this->should_intercept() || 'woocommerce' !== $domain ) {
			return $translation;
		}

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return $translation;
		}

		++$this->gettext_depth;

		try {
			return Storefront_Gettext_Resolver::resolve(
				$translation,
				$text,
				$context,
				$this->get_active_lang(),
				'wc_gettext:'
			);
		} finally {
			--$this->gettext_depth;
		}
	}

	/**
	 * Return a stored term translation for the active language.
	 *
	 * @param \WP_Term $term  Term object.
	 * @param string   $field name|desc.
	 * @return string
	 */
	private function get_term_translation( \WP_Term $term, $field ) {
		$field = 'desc' === $field ? 'desc' : 'name';
		$value = get_term_meta( $term->term_id, Post_Translator::get_term_meta_key( $field, $this->get_active_lang() ), true );

		return is_string( $value ) && '' !== trim( $value ) ? $value : '';
	}

	/**
	 * Resolve a term field from stored meta, then runtime cache / pending queue.
	 *
	 * @param \WP_Term $term  Term object.
	 * @param string   $field name|desc.
	 * @return string
	 */
	private function resolve_term_field( \WP_Term $term, $field ) {
		$stored = $this->get_term_translation( $term, $field );

		if ( '' !== $stored ) {
			return $stored;
		}

		$field    = 'desc' === $field ? 'desc' : 'name';
		$original = 'desc' === $field ? (string) $term->description : (string) $term->name;

		if ( '' === trim( $original ) ) {
			return '';
		}

		$registry = UI_String_Registry::lookup( $this->get_active_lang(), $original, '' );

		if ( null !== $registry && '' !== trim( $registry ) ) {
			return $registry;
		}

		if ( ! Persian_Detector::contains_persian( $original ) ) {
			return '';
		}

		return $this->lookup_theme_string(
			$original,
			'wc_term:' . $term->term_id . ':' . $field
		);
	}

	/**
	 * Find a public translated taxonomy term by its current label.
	 *
	 * @param string $label Breadcrumb label.
	 * @return \WP_Term|null
	 */
	private function find_term_by_label( $label ) {
		$label = trim( wp_strip_all_tags( (string) $label ) );

		if ( '' === $label ) {
			return null;
		}

		foreach ( Post_Translator::get_translatable_taxonomies() as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term = get_term_by( 'name', $label, $taxonomy );

			if ( $term instanceof \WP_Term ) {
				return $term;
			}
		}

		return null;
	}

	/**
	 * Normalize a product argument to WC_Product.
	 *
	 * Falls back to the global/loop product when the calling filter did not
	 * pass one (single-arg template filters).
	 *
	 * @param mixed $product Product, ID, or null.
	 * @return \WC_Product|null
	 */
	private function normalize_product( $product ) {
		if ( $product instanceof \WC_Product ) {
			return $product;
		}

		if ( is_numeric( $product ) && (int) $product > 0 ) {
			$resolved = wc_get_product( (int) $product );

			return $resolved instanceof \WC_Product ? $resolved : null;
		}

		// The parameter shadows the WooCommerce loop global, so read it via $GLOBALS.
		if ( isset( $GLOBALS['product'] ) && $GLOBALS['product'] instanceof \WC_Product ) {
			return $GLOBALS['product'];
		}

		$post_id = get_the_ID();

		if ( $post_id && 'product' === get_post_type( $post_id ) && function_exists( 'wc_get_product' ) ) {
			$resolved = wc_get_product( $post_id );

			return $resolved instanceof \WC_Product ? $resolved : null;
		}

		return null;
	}

	/**
	 * Active translated language for the current request.
	 *
	 * @return string
	 */
	private function get_active_lang() {
		return Url_Router::get_current_language();
	}

	/**
	 * Whether WooCommerce filters should run.
	 *
	 * @return bool
	 */
	private function should_intercept() {
		if ( self::$apd_snapshot_active ) {
			return false;
		}

		if ( null !== $this->intercept_cache ) {
			return $this->intercept_cache;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$this->intercept_cache = Url_Router::is_translated_request();
			return $this->intercept_cache;
		}

		if ( wp_doing_cron() ) {
			$this->intercept_cache = false;
			return false;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			$this->intercept_cache = false;
			return false;
		}

		/*
		 * Frontend AJAX (wc-ajax=get_variation, cart fragments, admin-ajax with a
		 * storefront referer) must keep translating, otherwise variation names and
		 * refreshed fragments revert to the default language. Url_Router resolves
		 * the language from the referer for those sub-requests; admin-originated
		 * AJAX resolves to the default language and is left untouched.
		 */
		$this->intercept_cache = Url_Router::is_translated_request();

		return $this->intercept_cache;
	}

	/**
	 * Whether APD (or similar) is capturing raw Persian product HTML for React tabs.
	 *
	 * @return bool
	 */
	public static function is_apd_snapshot_active() {
		return self::$apd_snapshot_active;
	}

	/**
	 * Toggle raw-capture mode for companion product tab widgets.
	 *
	 * @param bool $active Whether snapshot capture is active.
	 * @return void
	 */
	public static function set_apd_snapshot_active( $active ) {
		self::$apd_snapshot_active = (bool) $active;
	}

}
