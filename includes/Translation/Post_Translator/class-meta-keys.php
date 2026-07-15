<?php
/**
 * Meta key naming, discovery rules, and source-to-storage mapping.
 *
 * @package PolymartAI\Translation\Post_Translator
 */

namespace PolymartAI\Translation\Post_Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Meta_Keys
 */
final class Meta_Keys {

	/**
	 * Meta keys for translated core post fields.
	 */
	const META_KEY_TITLE_EN   = '_polymart_ai_title_en';
	const META_KEY_CONTENT_EN = '_polymart_ai_content_en';
	const META_KEY_EXCERPT_EN = '_polymart_ai_excerpt_en';

	/**
	 * Custom meta keys from companion plugins. English values stored as {key}_en.
	 *
	 * @var string[]
	 */
	const CUSTOM_META_KEYS = array(
		'custom_card_subtitle',
		'custom_card_btn_text',
		'_apd_ai_analysis',
		'_custom_title',
		'_custom_description',
	);

	/**
	 * WoodMart Variable Enhancer: custom variation title meta key.
	 */
	const WVE_VARIATION_CUSTOM_TITLE_META = '_custom_title';

	/**
	 * WoodMart Variable Enhancer: custom variation short description meta key.
	 */
	const WVE_VARIATION_CUSTOM_DESCRIPTION_META = '_custom_description';

	/**
	 * Post types that support English translation.
	 *
	 * @var string[]
	 */
	const SUPPORTED_POST_TYPES = array(
		'product',
		'post',
		'page',
		'woodmart_slide',
		'cms_block',
		'woodmart_layout',
		'elementor_library',
	);

	/**
	 * Post types that support a translated featured image / banner.
	 *
	 * @var string[]
	 */
	const FEATURED_IMAGE_POST_TYPES = array(
		'product',
		'woodmart_slide',
	);

	/**
	 * Taxonomies whose public labels should be translated alongside content.
	 *
	 * @var string[]
	 */
	const SUPPORTED_TAXONOMIES = array(
		'product_cat',
		'product_tag',
		'category',
		'post_tag',
	);

	/**
	 * Post meta flag: this post has Persian source content worth translating.
	 */
	const PERSIAN_CONTENT_FLAG_META = '_polymart_ai_has_persian';

	/**
	 * Prefix for per-language translation status index meta.
	 */
	const STATUS_INDEX_META_PREFIX = '_polymart_ai_status_';

	/**
	 * Supported post types, filterable for site-specific CPTs.
	 *
	 * @return string[]
	 */
	public static function get_supported_post_types() {
		/**
		 * Filter which post types appear in translation scans and jobs.
		 *
		 * @param string[] $post_types Post type slugs.
		 */
		return apply_filters( 'polymart_ai_supported_post_types', self::SUPPORTED_POST_TYPES );
	}

	/**
	 * Meta key prefixes that should never be auto-translated.
	 *
	 * @return string[]
	 */
	public static function get_skipped_meta_prefixes() {
		$prefixes = array(
			'_polymart_ai_',
			'_elementor_',
			'_edit_',
			'_wp_',
			'_oembed_',
			'_wc_',
			'_woocommerce_',
			'saswp_',
			'_saswp_',
		);

		/**
		 * Filter meta key prefixes excluded from automatic discovery.
		 *
		 * @param string[] $prefixes Prefixes to skip.
		 */
		return apply_filters( 'polymart_ai_skipped_meta_prefixes', $prefixes );
	}

	/**
	 * Whether a post meta key can be auto-discovered and translated.
	 *
	 * @param string $meta_key Meta key.
	 * @param string $lang     Target language (to skip existing translation keys).
	 * @return bool
	 */
	public static function is_translatable_meta_key( $meta_key, $lang = '' ) {
		if ( ! is_string( $meta_key ) || '' === $meta_key ) {
			return false;
		}

		$lang = sanitize_key( (string) $lang );

		if ( '' !== $lang && preg_match( '/_' . preg_quote( $lang, '/' ) . '$/', $meta_key ) ) {
			return false;
		}

		if ( in_array( $meta_key, self::CUSTOM_META_KEYS, true ) ) {
			return true;
		}

		foreach ( self::get_skipped_meta_prefixes() as $prefix ) {
			if ( 0 === strpos( $meta_key, $prefix ) ) {
				return false;
			}
		}

		if ( 0 === strpos( $meta_key, '_' ) ) {
			return false;
		}

		/**
		 * Filter whether a post meta key should be included in AI translation scans.
		 *
		 * @param bool   $allowed  Default decision.
		 * @param string $meta_key Meta key.
		 * @param string $lang     Target language code.
		 */
		return (bool) apply_filters( 'polymart_ai_is_translatable_meta_key', true, $meta_key, $lang );
	}

	/**
	 * Whether a meta key is handled as a custom translated field.
	 *
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	public static function is_custom_meta_key( $meta_key ) {
		return in_array( $meta_key, self::CUSTOM_META_KEYS, true )
			|| self::is_translatable_meta_key( $meta_key );
	}

	/**
	 * All translatable taxonomies, including WooCommerce attribute taxonomies.
	 *
	 * @return string[]
	 */
	public static function get_translatable_taxonomies() {
		$taxonomies = self::SUPPORTED_TAXONOMIES;

		if ( function_exists( 'wc_get_attribute_taxonomy_names' ) ) {
			$attribute_taxonomies = wc_get_attribute_taxonomy_names();

			if ( is_array( $attribute_taxonomies ) ) {
				$taxonomies = array_merge( $taxonomies, $attribute_taxonomies );
			}
		}

		/**
		 * Filter taxonomies whose terms are translated with post content.
		 *
		 * @param string[] $taxonomies Taxonomy slugs.
		 */
		return apply_filters(
			'polymart_ai_translatable_taxonomies',
			array_values( array_unique( array_filter( $taxonomies ) ) )
		);
	}

	/**
	 * Build a post meta key for a translated field and language code.
	 *
	 * @param string $field Core field: title, content, excerpt.
	 * @param string $lang  Language code.
	 * @return string
	 */
	public static function get_meta_key( $field, $lang ) {
		$lang = sanitize_key( (string) $lang );

		return '_polymart_ai_' . sanitize_key( (string) $field ) . '_' . $lang;
	}

	/**
	 * HTML form `name` for a stored meta key (no leading underscore).
	 *
	 * @param string $meta_key Database meta key.
	 * @return string
	 */
	public static function get_form_field_name( $meta_key ) {
		$meta_key = (string) $meta_key;

		if ( '' !== $meta_key && '_' === $meta_key[0] ) {
			return substr( $meta_key, 1 );
		}

		return $meta_key;
	}

	/**
	 * Map database meta keys to HTML form field names for admin JS payloads.
	 *
	 * @param array<string, string> $fields Meta-keyed values.
	 * @return array<string, string>
	 */
	public static function map_meta_keys_to_form_fields( array $fields ) {
		$mapped = array();

		foreach ( $fields as $meta_key => $value ) {
			if ( ! is_string( $meta_key ) ) {
				continue;
			}

			$mapped[ self::get_form_field_name( $meta_key ) ] = is_string( $value ) ? $value : (string) $value;
		}

		return $mapped;
	}

	/**
	 * Build a custom meta key for a translated companion field.
	 *
	 * @param string $source_key Source meta key.
	 * @param string $lang       Language code.
	 * @return string
	 */
	public static function get_custom_meta_key( $source_key, $lang ) {
		return $source_key . '_' . sanitize_key( (string) $lang );
	}

	/**
	 * Meta key for a translated featured image attachment ID.
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function get_thumbnail_meta_key( $lang ) {
		return '_thumbnail_id_' . sanitize_key( (string) $lang );
	}

	/**
	 * Meta key for a full translated Elementor JSON payload.
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function get_elementor_meta_key( $lang ) {
		return '_elementor_data_' . sanitize_key( (string) $lang );
	}

	/**
	 * Meta key storing the source hash for a translated Elementor JSON payload.
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function get_elementor_source_hash_meta_key( $lang ) {
		return '_polymart_ai_elementor_src_hash_' . sanitize_key( (string) $lang );
	}

	/**
	 * Durable meta key: primary Elementor API batches finished.
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function get_elementor_primary_done_meta_key( $lang ) {
		return '_polymart_ai_elementor_primary_done_' . sanitize_key( (string) $lang );
	}

	/**
	 * Meta key storing the source hash for a translated post meta field.
	 *
	 * @param string $source_key Source field identifier.
	 * @param string $lang       Language code.
	 * @return string
	 */
	public static function get_field_source_hash_meta_key( $source_key, $lang ) {
		return '_polymart_ai_src_' . md5( (string) $source_key ) . '_' . sanitize_key( (string) $lang );
	}

	/**
	 * Term meta key storing the source hash for a translated taxonomy field.
	 *
	 * @param string $field name|desc.
	 * @param string $lang  Language code.
	 * @return string
	 */
	public static function get_term_source_hash_meta_key( $field, $lang ) {
		return '_polymart_ai_term_src_' . sanitize_key( (string) $field ) . '_' . sanitize_key( (string) $lang );
	}

	/**
	 * Build a term meta key for a translated taxonomy field.
	 *
	 * @param string $field name|desc.
	 * @param string $lang  Language code.
	 * @return string
	 */
	public static function get_term_meta_key( $field, $lang ) {
		return '_polymart_ai_term_' . sanitize_key( (string) $field ) . '_' . sanitize_key( (string) $lang );
	}

	/**
	 * Build a nav menu item meta key for a translated title.
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function get_menu_title_meta_key( $lang ) {
		return '_polymart_ai_menu_title_' . sanitize_key( (string) $lang );
	}

	/**
	 * Build the denormalized translation-status meta key for a language.
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function get_status_index_meta_key( $lang ) {
		return self::STATUS_INDEX_META_PREFIX . sanitize_key( (string) $lang );
	}

	/**
	 * Resolve the stored meta key for a Persian source field identifier.
	 *
	 * @param string $source_key Source field identifier.
	 * @param string $lang       Language code.
	 * @return string
	 */
	public static function resolve_translated_meta_key_for_source( $source_key, $lang ) {
		$core_map = array(
			'post_title'                                => self::get_meta_key( 'title', $lang ),
			'post_content'                              => self::get_meta_key( 'content', $lang ),
			'post_excerpt'                              => self::get_meta_key( 'excerpt', $lang ),
			self::WVE_VARIATION_CUSTOM_TITLE_META       => self::get_meta_key( 'title', $lang ),
			self::WVE_VARIATION_CUSTOM_DESCRIPTION_META => self::get_meta_key( 'excerpt', $lang ),
		);

		if ( isset( $core_map[ $source_key ] ) ) {
			return $core_map[ $source_key ];
		}

		if ( self::is_custom_meta_key( $source_key ) ) {
			return self::get_custom_meta_key( $source_key, $lang );
		}

		return '';
	}

	/**
	 * Database meta key that stores the translated companion for one source field.
	 *
	 * @param int    $post_id    Post ID (reserved for future per-post mapping).
	 * @param string $source_key Source meta key or core field identifier.
	 * @param string $lang       Target language code.
	 * @return string
	 */
	public static function get_storefront_companion_meta_key( $post_id, $source_key, $lang ) {
		unset( $post_id );

		$source_key = (string) $source_key;
		$lang       = sanitize_key( (string) $lang );

		if ( '' === $source_key || '' === $lang ) {
			return '';
		}

		return self::resolve_translated_meta_key_for_source( $source_key, $lang );
	}

	/**
	 * Whether a meta key stores an AI/manual translation (not Persian source content).
	 *
	 * Used by async hooks to avoid re-scheduling translation when persistence writes
	 * companion meta such as custom_card_subtitle_en or _polymart_ai_title_en.
	 *
	 * @param string $meta_key Post meta key.
	 * @return bool
	 */
	public static function is_translation_storage_meta_key( $meta_key ) {
		if ( ! is_string( $meta_key ) || '' === $meta_key ) {
			return false;
		}

		foreach ( self::get_skipped_meta_prefixes() as $prefix ) {
			if ( 0 === strpos( $meta_key, $prefix ) ) {
				return true;
			}
		}

		if ( ! class_exists( '\PolymartAI\Language_Registry' ) ) {
			return false;
		}

		foreach ( \PolymartAI\Language_Registry::get_translation_target_languages() as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $lang || ! preg_match( '/_' . preg_quote( $lang, '/' ) . '$/', $meta_key ) ) {
				continue;
			}

			$source_key = substr( $meta_key, 0, - ( strlen( $lang ) + 1 ) );

			if ( in_array( $source_key, self::CUSTOM_META_KEYS, true ) ) {
				return true;
			}

			foreach ( array( 'title', 'content', 'excerpt' ) as $field ) {
				if ( self::get_meta_key( $field, $lang ) === $meta_key ) {
					return true;
				}
			}

			if (
				self::get_thumbnail_meta_key( $lang ) === $meta_key
				|| self::get_elementor_meta_key( $lang ) === $meta_key
			) {
				return true;
			}
		}

		/**
		 * Filter whether a post meta key is translation storage (companion output).
		 *
		 * @param bool   $is_storage Whether the key stores translated output.
		 * @param string $meta_key   Meta key being inspected.
		 */
		return (bool) apply_filters( 'polymart_ai_is_translation_storage_meta_key', false, $meta_key );
	}
}
