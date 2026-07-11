<?php
/**
 * Post meta persistence for manual English translations.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation;

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;

defined( 'ABSPATH' ) || exit;

/**
 * Class Post_Translator
 */
final class Post_Translator {

	/**
	 * Nonce action for saving meta box fields.
	 */
	const META_BOX_NONCE_ACTION = 'polymart_ai_save_translations';

	/**
	 * Nonce field name for the meta box form.
	 */
	const META_BOX_NONCE_NAME = 'polymart_ai_meta_box_nonce';

	/**
	 * While true, async invalidation hooks must not delete stored translations.
	 *
	 * @var bool
	 */
	private static $is_persisting_translations = false;

	/**
	 * Whether translation persistence is in progress (manual save or AI write).
	 *
	 * @return bool
	 */
	public static function is_persisting_translations() {
		return self::$is_persisting_translations;
	}

	/**
	 * Whether the current admin POST is saving the PolyMart translation meta box.
	 *
	 * @return bool
	 */
	public static function is_admin_translation_save_request() {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( ! isset( $_POST[ self::META_BOX_NONCE_NAME ] ) ) {
			return false;
		}

		return wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST[ self::META_BOX_NONCE_NAME ] ) ),
			self::META_BOX_NONCE_ACTION
		);
	}

	/**
	 * Whether async invalidation/clear hooks should be skipped for this request.
	 *
	 * @return bool
	 */
	public static function should_skip_translation_invalidation() {
		return self::$is_persisting_translations || self::is_admin_translation_save_request();
	}

	/**
	 * Begin guarded translation persistence (blocks async invalidation).
	 *
	 * @return void
	 */
	public static function begin_persisting_translations() {
		self::$is_persisting_translations = true;
	}

	/**
	 * End guarded translation persistence.
	 *
	 * @return void
	 */
	public static function end_persisting_translations() {
		self::$is_persisting_translations = false;
	}

	/**
	 * Whether the Persian source snippet for a field actually changed.
	 *
	 * Ignores cosmetic HTML/whitespace churn from WooCommerce re-saves.
	 *
	 * @param string $before Previous source text.
	 * @param string $after  Current source text.
	 * @return bool
	 */
	public static function field_source_text_meaningfully_changed( $before, $after ) {
		$before_norm = self::normalize_translation_plaintext( (string) $before );
		$after_norm  = self::normalize_translation_plaintext( (string) $after );

		return $before_norm !== $after_norm;
	}

	/**
	 * Transient prefix for in-flight AI translation locks.
	 */
	const TRANSLATION_LOCK_PREFIX = 'polymart_ai_post_translate_';

	/**
	 * TTL for per-post translation locks (seconds).
	 * Must stay above one AI call, but not so long that a killed PHP worker
	 * blocks the queue for a quarter hour.
	 */
	const TRANSLATION_LOCK_TTL = 240;

	/**
	 * Post meta flag: this post has Persian source content worth translating.
	 */
	const PERSIAN_CONTENT_FLAG_META = '_polymart_ai_has_persian';

	/**
	 * Prefix for per-language translation status index meta.
	 */
	const STATUS_INDEX_META_PREFIX = '_polymart_ai_status_';

	/**
	 * Per-request cache for translation status lookups.
	 *
	 * @var array<string, string>
	 */
	private static $translation_status_cache = array();

	/**
	 * Per-request cache for discovered meta scans.
	 *
	 * @var array<int, array<string, string>>
	 */
	private static $discovered_meta_cache = array();

	/**
	 * Per-request cache for Elementor Persian text probes.
	 *
	 * @var array<int, bool>
	 */
	private static $elementor_persian_cache = array();

	/**
	 * Per-request cache for Elementor source hashes keyed by post ID.
	 *
	 * @var array<int, string>
	 */
	private static $elementor_source_hash_cache = array();

	/**
	 * Per-request cache for whether a stored Elementor translation is current.
	 *
	 * @var array<string, bool>
	 */
	private static $elementor_current_cache = array();

	/**
	 * Per-request cache for extracted Elementor plain text.
	 *
	 * @var array<int, string>
	 */
	private static $elementor_plain_text_cache = array();

	/**
	 * Per-request cache for stored `_elementor_data_{lang}` companions.
	 *
	 * @var array<string, string|false>
	 */
	private static $stored_elementor_json_cache = array();

	/**
	 * Per-request cache for storefront Elementor swap eligibility.
	 *
	 * @var array<string, bool>
	 */
	private static $elementor_storefront_serve_cache = array();

	/**
	 * Per-request memoization for should_serve_stored_translation().
	 *
	 * @var array<string, bool>
	 */
	private static $should_serve_cache = array();

	/**
	 * Maximum `_elementor_data_{lang}` payload served on the storefront (bytes).
	 */
	const MAX_STOREFRONT_ELEMENTOR_JSON_BYTES = 2097152;

	/**
	 * Meta keys for translated core post fields.
	 */
	const META_KEY_TITLE_EN   = '_polymart_ai_title_en';
	const META_KEY_CONTENT_EN = '_polymart_ai_content_en';
	const META_KEY_EXCERPT_EN = '_polymart_ai_excerpt_en';

	/**
	 * Default ArvanCloud AI model when none is configured.
	 */
	const DEFAULT_AI_MODEL = 'DeepSeek-V3-2-g6zde';

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
	);

	/**
	 * Elementor JSON keys that may contain user-facing Persian text.
	 *
	 * @var array<string, true>
	 */
	private static $elementor_text_keys = array(
		'story_text'         => true,
		'button_text'        => true,
		'customer_label'     => true,
		'partner_label'      => true,
		'search_placeholder' => true,
		'search_brand_name'  => true,
		'no_results_message' => true,
		'title'              => true,
		'editor'             => true,
		'text'               => true,
		'heading'            => true,
		'subtitle'           => true,
		'btn_text'           => true,
		'link_text'          => true,
		'placeholder'        => true,
		'html'               => true,
		'label_description'  => true,
		'label_attributes'   => true,
		'label_video'        => true,
		'label_customer_home' => true,
		'label_satisfaction' => true,
	);

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
	 * Maximum string fields sent to AI per request (large posts are chunked).
	 */
	const AI_FIELD_CHUNK_SIZE = 6;

	/**
	 * Maximum combined characters per AI request batch.
	 */
	const AI_MAX_CHUNK_CHARS = 3500;

	/**
	 * Maximum characters for a single field before it is split across requests.
	 */
	const AI_MAX_SINGLE_FIELD_CHARS = 2800;

	/**
	 * Smaller Elementor JSON batches — paths are long and widgets nest deeply.
	 */
	const ELEMENTOR_AI_FIELD_CHUNK_SIZE = 3;

	/**
	 * Maximum combined characters per Elementor AI batch.
	 */
	const ELEMENTOR_AI_MAX_CHUNK_CHARS = 2500;

	/**
	 * One widget/field per job step — avoids ArvanCloud gateway timeouts on heavy pages.
	 */
	const ELEMENTOR_JOB_FIELD_CHUNK_SIZE = 1;

	/**
	 * Maximum characters per Elementor job-step API call.
	 */
	const ELEMENTOR_JOB_MAX_CHUNK_CHARS = 1000;

	/**
	 * HTTP timeout cap (seconds) for a single Elementor job-step AI call.
	 */
	const ELEMENTOR_JOB_REQUEST_TIMEOUT = 120;

	/**
	 * Minimum HTTP timeout (seconds) for auto-translate job AI calls.
	 *
	 * Must stay below the admin SPA step timeout (240s) including PHP overhead.
	 */
	const JOB_REQUEST_MIN_TIMEOUT = 120;

	/**
	 * Maximum HTTP timeout (seconds) for a single auto-translate job AI call.
	 */
	const JOB_REQUEST_MAX_TIMEOUT = 165;

	/**
	 * Variation title batches — variable products may have 100+ rows.
	 */
	const VARIATION_AI_FIELD_CHUNK_SIZE = 3;

	/**
	 * Maximum combined characters per variation AI batch.
	 */
	const VARIATION_AI_MAX_CHUNK_CHARS = 1800;

	/**
	 * Commerce attribute/term batches.
	 */
	const COMMERCE_AI_FIELD_CHUNK_SIZE = 4;

	/**
	 * Maximum combined characters per commerce AI batch.
	 */
	const COMMERCE_AI_MAX_CHUNK_CHARS = 2800;

	/**
	 * Maximum characters per discovered meta value sent to AI.
	 */
	const MAX_META_VALUE_LENGTH = 12000;

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
	 * Scan post meta for additional Persian fields (metaboxes, ACF-like keys, etc.).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    When set, skip fields that already have a translation.
	 * @return array<string, string>
	 */
	public static function collect_discovered_meta_fields( $post_id, $lang = '' ) {
		$post_id  = absint( $post_id );
		$lang     = sanitize_key( (string) $lang );
		$all_meta = get_post_meta( $post_id );
		$fields   = array();

		if ( ! is_array( $all_meta ) ) {
			return $fields;
		}

		foreach ( $all_meta as $meta_key => $values ) {
			if ( ! self::is_translatable_meta_key( $meta_key, $lang ) ) {
				continue;
			}

			if ( in_array( $meta_key, self::CUSTOM_META_KEYS, true ) ) {
				continue;
			}

			if ( '' !== $lang ) {
				$existing = (string) get_post_meta( $post_id, self::get_custom_meta_key( $meta_key, $lang ), true );

				if ( self::has_meaningful_translation( $existing ) ) {
					$value = is_array( $values ) ? reset( $values ) : $values;
					$persian_probe = is_string( $value ) ? Persian_Detector::only_persian_value( $value ) : '';

					if ( '' !== $persian_probe && self::is_field_translation_current( $post_id, $meta_key, $lang, $persian_probe ) ) {
						continue;
					}
				}
			}

			$value = is_array( $values ) ? reset( $values ) : $values;

			if ( ! is_string( $value ) ) {
				continue;
			}

			if ( strlen( $value ) > self::MAX_META_VALUE_LENGTH ) {
				$value = substr( $value, 0, self::MAX_META_VALUE_LENGTH );
			}

			$persian = Persian_Detector::only_persian_value( $value );

			if ( '' !== $persian ) {
				$fields[ $meta_key ] = $persian;
			}
		}

		return $fields;
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
	 * All translatable taxonomies, including WooCommerce attribute taxonomies.
	 *
	 * Attribute taxonomies (pa_color, pa_size, …) power variation dropdowns and
	 * the product attributes table; their term names must be translated into
	 * term meta like categories/tags so the frontend swap works everywhere.
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
	 * WordPress/WooCommerce often omit underscore-prefixed keys from $_POST on save.
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
	 * MD5 fingerprint of the raw `_elementor_data` meta value.
	 *
	 * @param int $post_id Post ID.
	 * @return string Empty when no usable source JSON exists.
	 */
	public static function compute_elementor_source_hash( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return '';
		}

		if ( array_key_exists( $post_id, self::$elementor_source_hash_cache ) ) {
			return self::$elementor_source_hash_cache[ $post_id ];
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			self::$elementor_source_hash_cache[ $post_id ] = '';

			return '';
		}

		self::$elementor_source_hash_cache[ $post_id ] = md5( $raw );

		return self::$elementor_source_hash_cache[ $post_id ];
	}

	/**
	 * Whether stored Elementor JSON still matches the current Persian source document.
	 *
	 * Avoids full json_decode of multi-megabyte documents; callers that already
	 * loaded the companion meta may pass it as $stored_json to skip a second read.
	 *
	 * @param int         $post_id      Post ID.
	 * @param string      $lang         Language code.
	 * @param string|null $stored_json  Optional preloaded `_elementor_data_{lang}` value.
	 * @return bool
	 */
	public static function is_elementor_translation_current( $post_id, $lang, $stored_json = null ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		if ( self::is_commerce_product_post( $post_id ) ) {
			return true;
		}

		$cache_key = $post_id . ':' . $lang;

		if ( array_key_exists( $cache_key, self::$elementor_current_cache ) ) {
			return self::$elementor_current_cache[ $cache_key ];
		}

		if ( null === $stored_json ) {
			$stored_json = get_post_meta( $post_id, self::get_elementor_meta_key( $lang ), true );
		}

		if ( ! is_string( $stored_json ) ) {
			self::$elementor_current_cache[ $cache_key ] = false;

			return false;
		}

		$stored_json = ltrim( $stored_json );

		if ( '' === $stored_json || ( '[' !== $stored_json[0] && '{' !== $stored_json[0] ) ) {
			self::$elementor_current_cache[ $cache_key ] = false;

			return false;
		}

		$current_hash = self::compute_elementor_source_hash( $post_id );

		if ( '' === $current_hash ) {
			self::$elementor_current_cache[ $cache_key ] = false;

			return false;
		}

		$stored_hash = (string) get_post_meta( $post_id, self::get_elementor_source_hash_meta_key( $lang ), true );

		if ( '' === trim( $stored_hash ) ) {
			// Legacy rows saved before source fingerprints — backfill and serve.
			self::save_elementor_source_hash( $post_id, $lang );
			self::$elementor_current_cache[ $cache_key ] = true;

			return true;
		}

		$is_current = hash_equals( $stored_hash, $current_hash );
		self::$elementor_current_cache[ $cache_key ] = $is_current;

		return $is_current;
	}

	/**
	 * Read a stored `_elementor_data_{lang}` companion once per request.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return string|false
	 */
	public static function get_stored_elementor_json( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$key     = $post_id . ':' . $lang;

		if ( array_key_exists( $key, self::$stored_elementor_json_cache ) ) {
			return self::$stored_elementor_json_cache[ $key ];
		}

		$stored = get_post_meta( $post_id, self::get_elementor_meta_key( $lang ), true );

		self::$stored_elementor_json_cache[ $key ] = is_string( $stored ) && '' !== ltrim( $stored ) ? $stored : false;

		return self::$stored_elementor_json_cache[ $key ];
	}

	/**
	 * Whether the storefront may swap `_elementor_data` for a stored companion.
	 *
	 * Never loads or json_decodes the Persian source document on the frontend.
	 * Relies on the source-hash meta written during admin translation; that hash
	 * is cleared when `_elementor_data` changes (see updated_post_meta hook).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	public static function can_serve_stored_elementor_json_on_storefront( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$key     = 'serve:' . $post_id . ':' . $lang;

		if ( array_key_exists( $key, self::$elementor_storefront_serve_cache ) ) {
			return self::$elementor_storefront_serve_cache[ $key ];
		}

		if ( $post_id <= 0 || '' === $lang || self::is_commerce_product_post( $post_id ) ) {
			self::$elementor_storefront_serve_cache[ $key ] = false;

			return false;
		}

		$stored = self::get_stored_elementor_json( $post_id, $lang );

		if ( ! is_string( $stored ) ) {
			self::$elementor_storefront_serve_cache[ $key ] = false;

			return false;
		}

		$stored = ltrim( $stored );

		if ( '' === $stored || ( '[' !== $stored[0] && '{' !== $stored[0] ) || strlen( $stored ) <= 2 ) {
			self::$elementor_storefront_serve_cache[ $key ] = false;

			return false;
		}

		if ( strlen( $stored ) > self::get_max_storefront_elementor_json_bytes() ) {
			self::$elementor_storefront_serve_cache[ $key ] = false;

			return false;
		}

		// Partial Elementor saves must not block serving a usable companion JSON.
		$elementor_error = (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true );

		if ( '' !== trim( $elementor_error ) && ! self::is_elementor_progress_message( $elementor_error ) ) {
			self::$elementor_storefront_serve_cache[ $key ] = false;

			return false;
		}

		$decoded = json_decode( $stored, true );

		if ( ! is_array( $decoded ) ) {
			self::$elementor_storefront_serve_cache[ $key ] = false;

			return false;
		}

		self::$elementor_storefront_serve_cache[ $key ] = self::is_elementor_translation_current( $post_id, $lang, $stored );

		return self::$elementor_storefront_serve_cache[ $key ];
	}

	/**
	 * Maximum Elementor companion JSON size allowed on public URLs.
	 *
	 * @return int
	 */
	public static function get_max_storefront_elementor_json_bytes() {
		/**
		 * Filter the maximum `_elementor_data_{lang}` payload served on the storefront.
		 *
		 * @param int $bytes Default 2 MiB.
		 */
		return max( 65536, (int) apply_filters( 'polymart_ai_max_elementor_json_bytes', self::MAX_STOREFRONT_ELEMENTOR_JSON_BYTES ) );
	}

	/**
	 * Persist the Elementor source fingerprint after saving translated JSON.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return void
	 */
	public static function save_elementor_source_hash( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$hash    = self::compute_elementor_source_hash( $post_id );

		if ( $post_id <= 0 || '' === $lang || '' === $hash ) {
			return;
		}

		update_post_meta( $post_id, self::get_elementor_source_hash_meta_key( $lang ), $hash );
	}

	/**
	 * Drop stored Elementor translations for every target language.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function invalidate_elementor_translations( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return;
		}

		foreach ( \PolymartAI\Language_Registry::get_translation_target_languages() as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $lang ) {
				continue;
			}

			delete_post_meta( $post_id, self::get_elementor_meta_key( $lang ) );
			delete_post_meta( $post_id, self::get_elementor_source_hash_meta_key( $lang ) );
			delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
		}

		self::flush_translation_status_cache( $post_id );
	}

	/**
	 * Drop stored translations for one source field across all target languages.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $source_key Source field identifier (post_title, custom meta key, …).
	 * @return void
	 */
	public static function invalidate_field_translation( $post_id, $source_key ) {
		$post_id    = absint( $post_id );
		$source_key = (string) $source_key;

		if ( $post_id <= 0 || '' === $source_key ) {
			return;
		}

		foreach ( Language_Registry::get_translation_target_languages() as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $lang ) {
				continue;
			}

			$translated_key = self::resolve_translated_meta_key_for_source( $source_key, $lang );

			if ( '' !== $translated_key ) {
				delete_post_meta( $post_id, $translated_key );
			}

			delete_post_meta( $post_id, self::get_field_source_hash_meta_key( $source_key, $lang ) );
		}

		self::flush_translation_status_cache( $post_id );
	}

	/**
	 * Remove every PolyMart translation meta row for one post.
	 *
	 * Used when source content no longer contains Persian text worth translating.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function clear_all_post_translations( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return;
		}

		self::invalidate_elementor_translations( $post_id );

		$all_meta = get_post_meta( $post_id );

		if ( ! is_array( $all_meta ) ) {
			return;
		}

		$lang_suffixes = array();

		foreach ( Language_Registry::get_translation_target_languages() as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' !== $lang ) {
				$lang_suffixes[] = '_' . $lang;
			}
		}

		foreach ( array_keys( $all_meta ) as $meta_key ) {
			if ( ! is_string( $meta_key ) || '' === $meta_key ) {
				continue;
			}

			if ( 0 === strpos( $meta_key, '_polymart_ai_' ) ) {
				delete_post_meta( $post_id, $meta_key );
				continue;
			}

			foreach ( $lang_suffixes as $suffix ) {
				if ( strlen( $meta_key ) > strlen( $suffix ) && substr( $meta_key, -strlen( $suffix ) ) === $suffix ) {
					delete_post_meta( $post_id, $meta_key );
					break;
				}
			}
		}

		delete_post_meta( $post_id, '_polymart_ai_translated_at' );
		self::flush_translation_status_cache( $post_id );
	}

	/**
	 * Refresh source fingerprints after a save without deleting stored translations.
	 *
	 * Manual and AI translations are only removed via explicit retranslate/clear APIs.
	 * Automatic invalidation on WooCommerce re-saves was wiping valid title rows.
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post_after  Post after save.
	 * @param \WP_Post|null $post_before Post before save, when available.
	 * @return void
	 */
	public static function reconcile_post_translations_after_save( $post_id, \WP_Post $post_after, $post_before = null ) {
		unset( $post_before );

		$post_id = absint( $post_id );

		if ( $post_id <= 0 || ! $post_after instanceof \WP_Post ) {
			return;
		}

		foreach ( Language_Registry::get_translation_target_languages() as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $lang ) {
				continue;
			}

			self::sync_field_source_hashes_for_post( $post_id, $lang );
		}
	}

	/**
	 * Resolve the current Persian source snippet for a translatable field.
	 *
	 * @param \WP_Post $post       Post object.
	 * @param string   $source_key Source field identifier.
	 * @return string
	 */
	public static function get_field_source_text( \WP_Post $post, $source_key ) {
		$source_key = (string) $source_key;

		switch ( $source_key ) {
			case 'post_title':
				return Persian_Detector::only_persian_value( $post->post_title );
			case self::WVE_VARIATION_CUSTOM_TITLE_META:
				$value = get_post_meta( $post->ID, self::WVE_VARIATION_CUSTOM_TITLE_META, true );

				return is_string( $value ) ? Persian_Detector::only_persian_value( $value ) : '';
			case self::WVE_VARIATION_CUSTOM_DESCRIPTION_META:
				$value = get_post_meta( $post->ID, self::WVE_VARIATION_CUSTOM_DESCRIPTION_META, true );

				return is_string( $value ) ? Persian_Detector::only_persian_value( $value ) : '';
			case 'post_content':
				if ( self::uses_elementor_builder( $post->ID ) && ! self::is_commerce_product_post( $post->ID, $post ) ) {
					$plain = self::collect_elementor_persian_plain_text( $post->ID );

					if ( '' !== $plain ) {
						return $plain;
					}

					return self::extract_elementor_html_persian_excerpt( $post->post_content );
				}

				return Persian_Detector::only_persian_value( $post->post_content );
			case 'post_excerpt':
				return Persian_Detector::only_persian_value( $post->post_excerpt );
		}

		if ( self::is_custom_meta_key( $source_key ) ) {
			$value = get_post_meta( $post->ID, $source_key, true );

			return is_string( $value ) ? Persian_Detector::only_persian_value( $value ) : '';
		}

		return '';
	}

	/**
	 * Map a stored translation meta key back to its Persian source field identifier.
	 *
	 * @param string $meta_key Stored translation meta key.
	 * @param string $lang     Target language code.
	 * @param int    $post_id  Optional post ID for variation-specific source mapping.
	 * @return string
	 */
	public static function resolve_source_key_for_translated_meta( $meta_key, $lang, $post_id = 0 ) {
		$meta_key = (string) $meta_key;
		$lang     = sanitize_key( (string) $lang );
		$post_id  = absint( $post_id );

		if ( '' === $meta_key || '' === $lang ) {
			return '';
		}

		$core_map = array(
			self::get_meta_key( 'title', $lang )   => 'post_title',
			self::get_meta_key( 'content', $lang ) => 'post_content',
			self::get_meta_key( 'excerpt', $lang )  => 'post_excerpt',
		);

		if ( isset( $core_map[ $meta_key ] ) ) {
			if ( $post_id > 0 && 'product_variation' === get_post_type( $post_id ) ) {
				if ( 'post_title' === $core_map[ $meta_key ] ) {
					$custom = get_post_meta( $post_id, self::WVE_VARIATION_CUSTOM_TITLE_META, true );

					if ( is_string( $custom ) && '' !== trim( $custom ) ) {
						return self::WVE_VARIATION_CUSTOM_TITLE_META;
					}
				}

				if ( 'post_excerpt' === $core_map[ $meta_key ] ) {
					$custom = get_post_meta( $post_id, self::WVE_VARIATION_CUSTOM_DESCRIPTION_META, true );

					if ( is_string( $custom ) && '' !== trim( $custom ) ) {
						return self::WVE_VARIATION_CUSTOM_DESCRIPTION_META;
					}
				}
			}

			return $core_map[ $meta_key ];
		}

		foreach ( self::CUSTOM_META_KEYS as $source_key ) {
			if ( self::get_custom_meta_key( $source_key, $lang ) === $meta_key ) {
				return $source_key;
			}
		}

		return '';
	}

	/**
	 * Whether the storefront may swap in a stored translation for one field.
	 *
	 * @param int           $post_id    Post ID.
	 * @param string        $source_key Source field identifier.
	 * @param string        $lang       Target language code.
	 * @param \WP_Post|null $post       Optional post object.
	 * @return bool
	 */
	public static function should_serve_stored_translation( $post_id, $source_key, $lang, $post = null ) {
		$post_id    = absint( $post_id );
		$source_key = (string) $source_key;
		$lang       = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $source_key || '' === $lang ) {
			return false;
		}

		$cache_key = $post_id . ':' . $source_key . ':' . $lang;

		if ( array_key_exists( $cache_key, self::$should_serve_cache ) ) {
			return self::$should_serve_cache[ $cache_key ];
		}

		// Elementor pages render from `_elementor_data`; walking/decoding the tree here
		// on every filter call exhausted memory on translated URLs.
		if ( 'post_content' === $source_key && self::uses_elementor_builder( $post_id ) && ! is_admin() ) {
			self::$should_serve_cache[ $cache_key ] = false;

			return false;
		}

		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			self::$should_serve_cache[ $cache_key ] = false;

			return false;
		}

		$source = self::get_field_source_text( $post, $source_key );

		if ( '' === trim( $source ) ) {
			self::$should_serve_cache[ $cache_key ] = false;

			return false;
		}

		$result = self::is_field_translation_current( $post_id, $source_key, $lang, $source );
		self::$should_serve_cache[ $cache_key ] = $result;

		return $result;
	}

	/**
	 * Whether a post meta translation still matches its Persian source value.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $source_key Source field identifier.
	 * @param string $lang       Language code.
	 * @param string $source     Current Persian source text.
	 * @return bool
	 */
	private static function is_field_translation_current( $post_id, $source_key, $lang, $source ) {
		$translated_key = self::resolve_translated_meta_key_for_source( $source_key, $lang );

		if ( '' === $translated_key ) {
			return false;
		}

		if ( ! self::has_meaningful_translation( get_post_meta( absint( $post_id ), $translated_key, true ) ) ) {
			$stored_hash = (string) get_post_meta( absint( $post_id ), self::get_field_source_hash_meta_key( $source_key, $lang ), true );

			// Fingerprints without stored meta block auto-translate retries — drop stale rows.
			if ( '' !== trim( $stored_hash ) ) {
				delete_post_meta( absint( $post_id ), self::get_field_source_hash_meta_key( $source_key, $lang ) );
			}

			return false;
		}

		$stored_value = (string) get_post_meta( absint( $post_id ), $translated_key, true );

		if ( ! self::is_clean_target_language_translation( $stored_value, $lang ) ) {
			return false;
		}

		if ( 'fa' !== $lang ) {
			$normalized_source  = self::normalize_translation_plaintext( $source );
			$normalized_stored  = self::normalize_translation_plaintext( $stored_value );

			if ( '' !== $normalized_source && hash_equals( $normalized_source, $normalized_stored ) ) {
				return false;
			}
		}

		$stored_hash = (string) get_post_meta( absint( $post_id ), self::get_field_source_hash_meta_key( $source_key, $lang ), true );

		if ( '' === trim( $stored_hash ) ) {
			// Legacy rows saved before source fingerprints — backfill and serve.
			self::store_field_source_hash( $post_id, $source_key, $lang, $source );

			return true;
		}

		return hash_equals( $stored_hash, md5( (string) $source ) );
	}

	/**
	 * Whether a taxonomy translation still matches its Persian source value.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $field   name|desc.
	 * @param string $lang    Language code.
	 * @param string $source  Current Persian source text.
	 * @return bool
	 */
	private static function is_term_translation_current( $term_id, $field, $lang, $source ) {
		$meta_key = self::get_term_meta_key( $field, $lang );

		if ( ! self::has_meaningful_translation( get_term_meta( absint( $term_id ), $meta_key, true ) ) ) {
			return false;
		}

		$stored_hash = (string) get_term_meta( absint( $term_id ), self::get_term_source_hash_meta_key( $field, $lang ), true );

		if ( '' === trim( $stored_hash ) ) {
			// Legacy rows saved before source fingerprints — backfill and serve.
			update_term_meta( absint( $term_id ), self::get_term_source_hash_meta_key( $field, $lang ), md5( (string) $source ) );

			return true;
		}

		return hash_equals( $stored_hash, md5( (string) $source ) );
	}

	/**
	 * Store the source fingerprint for one translated post field.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $source_key Source field identifier.
	 * @param string $lang       Language code.
	 * @param string $source     Persian source text.
	 * @return void
	 */
	private static function store_field_source_hash( $post_id, $source_key, $lang, $source ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$source  = (string) $source;

		if ( $post_id <= 0 || '' === $lang || '' === trim( $source ) ) {
			return;
		}

		update_post_meta( $post_id, self::get_field_source_hash_meta_key( $source_key, $lang ), md5( $source ) );
	}

	/**
	 * Resolve the stored meta key for a Persian source field identifier.
	 *
	 * @param string $source_key Source field identifier.
	 * @param string $lang       Language code.
	 * @return string
	 */
	private static function resolve_translated_meta_key_for_source( $source_key, $lang ) {
		$core_map = array(
			'post_title'          => self::get_meta_key( 'title', $lang ),
			'post_content'        => self::get_meta_key( 'content', $lang ),
			'post_excerpt'        => self::get_meta_key( 'excerpt', $lang ),
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
	 * Persist source fingerprints for fields saved from an AI response.
	 *
	 * @param int                   $post_id      Post ID.
	 * @param array<string, string> $translations Saved translations keyed by source field.
	 * @param string                $lang         Target language code.
	 * @param \WP_Post              $post         Post object.
	 * @return void
	 */
	private static function persist_field_source_hashes( $post_id, array $translations, $lang, \WP_Post $post ) {
		unset( $translations, $post );

		self::sync_field_source_hashes_for_post( $post_id, $lang );
	}

	/**
	 * Store source fingerprints for every translated field on a post.
	 *
	 * Called after AI saves and after manual meta-box / REST edits so stale
	 * detection works when Persian source text changes later.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return void
	 */
	public static function sync_field_source_hashes_for_post( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$post    = get_post( $post_id );

		if ( $post_id <= 0 || '' === $lang || ! $post instanceof \WP_Post ) {
			return;
		}

		$sources = self::collect_persian_fields( $post, '' );

		foreach ( $sources as $source_key => $source ) {
			if ( ! is_string( $source_key ) || ! is_string( $source ) ) {
				continue;
			}

			if ( self::is_term_payload_key( $source_key ) || 0 === strpos( $source_key, 'product_attr_' ) || self::is_variation_title_payload_key( $source_key ) ) {
				continue;
			}

			$translated_key = self::resolve_translated_meta_key_for_source( $source_key, $lang );

			if ( '' === $translated_key ) {
				continue;
			}

			if ( ! self::has_meaningful_translation( get_post_meta( $post_id, $translated_key, true ) ) ) {
				continue;
			}

			self::store_field_source_hash( $post_id, $source_key, $lang, $source );
		}
	}

	/**
	 * Runtime cache entries for custom WooCommerce attribute labels/values.
	 *
	 * @param int $post_id Product ID.
	 * @return array<int, array{text: string, context: string}>
	 */
	public static function get_product_attribute_runtime_cache_entries( $post_id ) {
		$post = get_post( absint( $post_id ) );

		if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
			return array();
		}

		$entries = array();

		foreach ( self::collect_product_attribute_fields( $post, '' ) as $key => $text ) {
			if ( ! is_string( $text ) || '' === trim( $text ) ) {
				continue;
			}

			if ( 0 === strpos( $key, 'product_attr_value:' ) ) {
				$group     = substr( $key, strlen( 'product_attr_value:' ) );
				$group     = preg_replace( '/:[a-f0-9]{32}$/', '', (string) $group );
				$entries[] = array(
					'text'    => $text,
					'context' => 'wc_attr_opt:' . sanitize_key( (string) $group ),
				);
				continue;
			}

			if ( 0 === strpos( $key, 'product_attr_label:' ) ) {
				$group     = substr( $key, strlen( 'product_attr_label:' ) );
				$entries[] = array(
					'text'    => $text,
					'context' => 'wc_attr_label:' . sanitize_key( (string) $group ),
				);
			}
		}

		return $entries;
	}

	/**
	 * Clear cached attribute label/value translations for one product.
	 *
	 * @param int $post_id Product ID.
	 * @return void
	 */
	public static function invalidate_product_attribute_runtime_cache( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return;
		}

		$snapshot = get_post_meta( $post_id, '_polymart_ai_attr_cache_snapshot', true );

		if ( ! is_array( $snapshot ) ) {
			$snapshot = array();
		}

		$current = self::get_product_attribute_runtime_cache_entries( $post_id );
		$entries = array_merge( $snapshot, $current );

		foreach ( \PolymartAI\Language_Registry::get_translation_target_languages() as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $lang ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['text'] ) || empty( $entry['context'] ) ) {
					continue;
				}

				Runtime_String_Translator::forget_translation(
					(string) $entry['text'],
					$lang,
					(string) $entry['context']
				);
			}
		}

		update_post_meta( $post_id, '_polymart_ai_attr_cache_snapshot', $current );
		update_post_meta( $post_id, '_polymart_ai_attr_fingerprint', md5( wp_json_encode( $current ) ) );
	}

	/**
	 * Snapshot of variation custom titles for change detection on variable products.
	 *
	 * @param int $product_id Parent product ID.
	 * @return array<int, array{variation_id:int, title:string}>
	 */
	public static function get_product_variation_title_snapshot( $product_id ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			return array();
		}

		$snapshot = array();

		foreach ( $product->get_children() as $variation_id ) {
			$variation_id = absint( $variation_id );

			if ( $variation_id <= 0 ) {
				continue;
			}

			$custom_title = self::get_variation_custom_title( $variation_id );
			$description  = self::get_variation_custom_description( $variation_id );

			if ( '' === $custom_title && '' === $description ) {
				continue;
			}

			$snapshot[] = array(
				'variation_id'       => $variation_id,
				'custom_title'       => $custom_title,
				'custom_description' => $description,
			);
		}

		return $snapshot;
	}

	/**
	 * Read the raw WVE `_custom_title` meta for one variation.
	 *
	 * @param int $variation_id Variation post ID.
	 * @return string
	 */
	public static function get_variation_custom_title_meta_raw( $variation_id ) {
		$variation_id = absint( $variation_id );

		if ( $variation_id <= 0 ) {
			return '';
		}

		$custom = get_post_meta( $variation_id, self::WVE_VARIATION_CUSTOM_TITLE_META, true );

		return is_string( $custom ) ? trim( $custom ) : '';
	}

	/**
	 * Read the WoodMart Variable Enhancer custom title for one variation.
	 *
	 * Falls back to the variation post title when no custom meta is stored.
	 *
	 * @param int $variation_id Variation post ID.
	 * @return string
	 */
	public static function get_variation_custom_title( $variation_id ) {
		$variation_id = absint( $variation_id );

		if ( $variation_id <= 0 ) {
			return '';
		}

		$custom = self::get_variation_custom_title_meta_raw( $variation_id );

		if ( '' !== $custom ) {
			return $custom;
		}

		return trim( (string) get_post_field( 'post_title', $variation_id ) );
	}

	/**
	 * Read the WoodMart Variable Enhancer custom short description for one variation.
	 *
	 * @param int $variation_id Variation post ID.
	 * @return string
	 */
	public static function get_variation_custom_description( $variation_id ) {
		$variation_id = absint( $variation_id );

		if ( $variation_id <= 0 ) {
			return '';
		}

		$custom = get_post_meta( $variation_id, self::WVE_VARIATION_CUSTOM_DESCRIPTION_META, true );

		return is_string( $custom ) ? trim( $custom ) : '';
	}

	/**
	 * Fingerprint for variation custom titles on a variable product.
	 *
	 * @param int $product_id Parent product ID.
	 * @return string
	 */
	public static function compute_product_variation_titles_fingerprint( $product_id ) {
		$snapshot = self::get_product_variation_title_snapshot( $product_id );

		return md5( wp_json_encode( $snapshot ) );
	}

	/**
	 * Persist the variation-title snapshot used by async change detection.
	 *
	 * @param int $product_id Parent product ID.
	 * @return void
	 */
	public static function refresh_product_variation_titles_fingerprint( $product_id ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return;
		}

		$snapshot = self::get_product_variation_title_snapshot( $product_id );

		update_post_meta( $product_id, '_polymart_ai_variation_titles_snapshot', $snapshot );
		update_post_meta( $product_id, '_polymart_ai_variation_titles_fingerprint', md5( wp_json_encode( $snapshot ) ) );
	}

	/**
	 * Drop cached variation-title runtime strings and refresh the parent snapshot.
	 *
	 * @param int                                        $product_id Parent product ID.
	 * @param array<int, array{variation_id:int, title:string}>|null $previous_snapshot Optional prior snapshot.
	 * @return void
	 */
	public static function invalidate_product_variation_title_runtime_cache( $product_id, $previous_snapshot = null ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return;
		}

		if ( null === $previous_snapshot ) {
			$previous_snapshot = get_post_meta( $product_id, '_polymart_ai_variation_titles_snapshot', true );
		}

		if ( ! is_array( $previous_snapshot ) ) {
			$previous_snapshot = array();
		}

		$entries = array_merge( $previous_snapshot, self::get_product_variation_title_snapshot( $product_id ) );
		$seen    = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['variation_id'] ) ) {
				continue;
			}

			$variation_id = absint( $entry['variation_id'] );
			$texts        = array(
				array(
					'text'    => (string) ( $entry['custom_title'] ?? $entry['title'] ?? '' ),
					'context' => 'wc_variation_custom_title:' . $variation_id,
				),
				array(
					'text'    => (string) ( $entry['custom_description'] ?? '' ),
					'context' => 'wc_variation_custom_description:' . $variation_id,
				),
			);

			foreach ( $texts as $text_entry ) {
				$text = trim( (string) ( $text_entry['text'] ?? '' ) );

				if ( '' === $text ) {
					continue;
				}

				$cache_key = $variation_id . "\0" . $text_entry['context'] . "\0" . $text;

				if ( isset( $seen[ $cache_key ] ) ) {
					continue;
				}

				$seen[ $cache_key ] = true;

				foreach ( Language_Registry::get_translation_target_languages() as $language ) {
					$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

					if ( '' === $lang ) {
						continue;
					}

					Runtime_String_Translator::forget_translation(
						$text,
						$lang,
						(string) $text_entry['context']
					);
				}
			}
		}

		self::refresh_product_variation_titles_fingerprint( $product_id );
	}

	/**
	 * Invalidate variation title translations when the Persian source changes.
	 *
	 * @param int           $variation_id Variation post ID.
	 * @param \WP_Post      $post_after   Variation after save.
	 * @param \WP_Post|null $post_before  Variation before save.
	 * @return void
	 */
	public static function reconcile_variation_translation_after_save( $variation_id, \WP_Post $post_after, $post_before = null ) {
		$variation_id = absint( $variation_id );

		if ( $variation_id <= 0 || ! $post_after instanceof \WP_Post || 'product_variation' !== $post_after->post_type ) {
			return;
		}

		if ( $post_before instanceof \WP_Post && $post_before->post_title !== $post_after->post_title ) {
			self::invalidate_field_translation( $variation_id, 'post_title' );
		}

		if ( '' === trim( Persian_Detector::only_persian_value( $post_after->post_title ) ) ) {
			self::invalidate_field_translation( $variation_id, 'post_title' );
		}
	}

	/**
	 * Invalidate WVE custom variation fields when their Persian source changes.
	 *
	 * @param int    $variation_id Variation post ID.
	 * @param string $meta_key     _custom_title or _custom_description.
	 * @param mixed  $prev_value   Previous meta value.
	 * @return void
	 */
	public static function reconcile_variation_custom_meta_after_save( $variation_id, $meta_key, $prev_value = null ) {
		$variation_id = absint( $variation_id );
		$meta_key     = (string) $meta_key;

		if ( $variation_id <= 0 ) {
			return;
		}

		if ( ! in_array( $meta_key, array( self::WVE_VARIATION_CUSTOM_TITLE_META, self::WVE_VARIATION_CUSTOM_DESCRIPTION_META ), true ) ) {
			return;
		}

		self::invalidate_field_translation( $variation_id, $meta_key );

		$previous = is_string( $prev_value ) ? trim( $prev_value ) : '';

		if ( '' !== $previous ) {
			$context = self::WVE_VARIATION_CUSTOM_TITLE_META === $meta_key
				? 'wc_variation_custom_title:' . $variation_id
				: 'wc_variation_custom_description:' . $variation_id;

			foreach ( Language_Registry::get_translation_target_languages() as $language ) {
				$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

				if ( '' === $lang ) {
					continue;
				}

				Runtime_String_Translator::forget_translation( $previous, $lang, $context );
			}
		}

		$current = get_post_meta( $variation_id, $meta_key, true );
		$current = is_string( $current ) ? trim( $current ) : '';

		if ( '' === trim( Persian_Detector::only_persian_value( $current ) ) ) {
			self::invalidate_field_translation( $variation_id, $meta_key );
		}
	}

	/**
	 * Whether a taxonomy term has Persian fields worth translating.
	 *
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	public static function term_has_persian_content( $term_id ) {
		return ! empty( self::collect_term_persian_fields( $term_id ) );
	}

	/**
	 * Collect Persian term fields for AI translation.
	 *
	 * @param int|\WP_Term $term Term ID or object.
	 * @return array<string, string>
	 */
	public static function collect_term_persian_fields( $term ) {
		if ( is_numeric( $term ) ) {
			$term = get_term( absint( $term ) );
		}

		if ( ! $term instanceof \WP_Term || ! in_array( $term->taxonomy, self::get_translatable_taxonomies(), true ) ) {
			return array();
		}

		$fields = array();

		$name = Persian_Detector::only_persian_value( $term->name );

		if ( '' !== $name ) {
			$fields[ self::build_term_payload_key( $term->term_id, 'name' ) ] = $name;
		}

		$description = Persian_Detector::only_persian_value( $term->description );

		if ( '' !== $description ) {
			$fields[ self::build_term_payload_key( $term->term_id, 'desc' ) ] = $description;
		}

		return $fields;
	}

	/**
	 * Drop stored term translations for every target language.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public static function invalidate_term_translations( $term_id ) {
		$term_id = absint( $term_id );

		if ( $term_id <= 0 ) {
			return;
		}

		foreach ( \PolymartAI\Language_Registry::get_translation_target_languages() as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $lang ) {
				continue;
			}

			delete_term_meta( $term_id, self::get_term_meta_key( 'name', $lang ) );
			delete_term_meta( $term_id, self::get_term_meta_key( 'desc', $lang ) );
			delete_term_meta( $term_id, self::get_term_source_hash_meta_key( 'name', $lang ) );
			delete_term_meta( $term_id, self::get_term_source_hash_meta_key( 'desc', $lang ) );
		}
	}

	/**
	 * Request AI translations for one taxonomy term.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $lang    Target language code.
	 * @return array{term: \WP_Term, translations: array<string, string>}|\WP_Error
	 */
	public static function request_ai_term_translation( $term_id, $lang = 'en' ) {
		$term_id = absint( $term_id );

		if (
			! $term_id
			|| (
				! \PolymartAI\Activity_Logger::is_trusted_job_worker()
				&& ! current_user_can( 'manage_categories' )
			)
		) {
			return new \WP_Error(
				'polymart_ai_forbidden',
				__( 'شما اجازه ترجمه این برچسب را ندارید.', 'polymart-ai' )
			);
		}

		$term = get_term( $term_id );

		if ( ! $term instanceof \WP_Term || ! in_array( $term->taxonomy, self::get_translatable_taxonomies(), true ) ) {
			return new \WP_Error(
				'polymart_ai_invalid_term',
				__( 'برچسب برای ترجمه نامعتبر است.', 'polymart-ai' )
			);
		}

		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			$lang = 'en';
		}

		$payload = self::collect_term_persian_fields( $term );

		if ( empty( $payload ) ) {
			return new \WP_Error(
				'polymart_ai_empty_source',
				__( 'متن فارسی برای ترجمه این برچسب یافت نشد.', 'polymart-ai' )
			);
		}

		$settings = self::get_translation_settings();

		if ( empty( $settings['api_key'] ) || empty( $settings['api_endpoint'] ) ) {
			return new \WP_Error(
				'polymart_ai_missing_credentials',
				__( 'اعتبارنامه API آروان‌کلود پیکربندی نشده است.', 'polymart-ai' )
			);
		}

		$api_key      = (string) $settings['api_key'];
		$api_endpoint = rtrim( (string) $settings['api_endpoint'], '/' );
		$ai_model     = AI_Client::resolve_model(
			(string) ( $settings['ai_model'] ?? '' ),
			$api_endpoint
		);

		$translated = array();

		foreach ( self::chunk_payload_for_ai( $payload ) as $chunk ) {
			$chunk_result = AI_Client::translate_fields(
				$chunk,
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang
			);

			if ( is_wp_error( $chunk_result ) ) {
				return $chunk_result;
			}

			$translated = array_merge( $translated, $chunk_result );
		}

		$translated = self::collapse_payload_parts( $translated );

		return array(
			'term'         => $term,
			'translations' => $translated,
		);
	}

	/**
	 * Persist AI-generated translations for one taxonomy term.
	 *
	 * @param int                   $term_id      Term ID.
	 * @param array<string, string> $translations AI response keyed by term payload.
	 * @param string                $lang         Target language code.
	 * @return true|\WP_Error
	 */
	public static function save_ai_term_translations( $term_id, array $translations, $lang = 'en' ) {
		unset( $term_id );

		self::save_term_translations( $translations, $lang );

		return true;
	}

	/**
	 * Whether a post type supports translated featured images.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function supports_featured_image_translation( $post_type ) {
		return in_array( $post_type, self::FEATURED_IMAGE_POST_TYPES, true );
	}

	/**
	 * Whether a post was built with the Elementor page builder.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function uses_elementor_builder( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return false;
		}

		return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
	}

	/**
	 * Whether translated Elementor JSON is required for translation completeness.
	 *
	 * WooCommerce products store storefront copy in title/content/terms/attributes.
	 * Requiring `_elementor_data_{lang}` on products blocks auto-translate jobs even
	 * when all commerce fields are already translated (Woodmart layouts live elsewhere).
	 *
	 * @param int            $post_id Post ID.
	 * @param \WP_Post|null  $post    Optional post object.
	 * @return bool
	 */
	public static function should_require_elementor_translation( $post_id, $post = null ) {
		if ( ! self::uses_elementor_builder( $post_id ) ) {
			return false;
		}

		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( absint( $post_id ) );
		}

		if ( self::is_commerce_product_post( $post_id, $post ) ) {
			/**
			 * Filter whether Elementor JSON counts toward product translation completeness.
			 *
			 * @param bool          $required Default false for WooCommerce products.
			 * @param int           $post_id  Post ID.
			 * @param \WP_Post|null $post     Post object.
			 */
			return (bool) apply_filters( 'polymart_ai_require_elementor_for_product', false, $post_id, $post );
		}

		return true;
	}

	/**
	 * Whether a post represents a WooCommerce product or variation.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Optional post object.
	 * @return bool
	 */
	public static function is_commerce_product_post( $post_id, $post = null ) {
		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( absint( $post_id ) );
		}

		if ( $post instanceof \WP_Post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return true;
		}

		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( absint( $post_id ) );

			return $product instanceof \WC_Product;
		}

		return false;
	}

	/**
	 * Whether a stored translation value is non-empty after normalization.
	 *
	 * @param mixed $value Stored translation.
	 * @return bool
	 */
	public static function has_meaningful_translation( $value ) {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return false;
		}

		$text = trim( wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

		return '' !== $text;
	}

	/**
	 * Normalize raw AI output before persisting to post meta.
	 *
	 * Strips markdown fences, zero-width characters, and decoded entities so
	 * sanitize_text_field does not wipe valid Arabic/Latin titles.
	 *
	 * @param mixed $value Raw AI field value.
	 * @return string
	 */
	private static function normalize_ai_translation_value( $value ) {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '';
		}

		$text = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text );
		$text = trim( $text );

		if ( preg_match( '/^`{1,3}(?:json)?\s*(.+?)\s*`{1,3}$/is', $text, $matches ) ) {
			$text = trim( $matches[1] );
		}

		return trim( $text );
	}

	/**
	 * Prepare one AI translation value for durable post meta storage.
	 *
	 * @param mixed  $value      Raw AI field value.
	 * @param string $field_kind title|excerpt|content|default.
	 * @return string
	 */
	private static function prepare_stored_meta_value( $value, $field_kind ) {
		$value = self::normalize_ai_translation_value( $value );

		switch ( $field_kind ) {
			case 'content':
				return wp_kses_post( $value );
			case 'excerpt':
				return false !== strpos( $value, '<' )
					? wp_kses_post( $value )
					: sanitize_textarea_field( $value );
			case 'title':
				return sanitize_textarea_field( $value );
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Normalize translated/plain text for duplicate-source comparisons.
	 *
	 * @param mixed $value Text value.
	 * @return string
	 */
	private static function normalize_translation_plaintext( $value ) {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '';
		}

		$text = wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Whether a stored translation is usable on a non-Persian storefront.
	 *
	 * Rejects hybrid strings left by partial runtime/dictionary attempts.
	 *
	 * @param mixed  $value Stored translation.
	 * @param string $lang  Target language code.
	 * @return bool
	 */
	private static function is_clean_target_language_translation( $value, $lang ) {
		return Persian_Detector::is_acceptable_translation_for_language( $value, $lang );
	}

	/**
	 * Whether stored translation meta is safe to serve on a translated storefront URL.
	 *
	 * @param mixed  $value Stored translation.
	 * @param string $lang  Target language code.
	 * @return bool
	 */
	public static function is_usable_storefront_translation( $value, $lang ) {
		return self::is_clean_target_language_translation( $value, $lang );
	}

	/**
	 * Maximum plain-text length for a derived/companion product title on the storefront.
	 */
	const STOREFRONT_TITLE_MAX_LENGTH = 120;

	/**
	 * Maximum plain-text length when deriving a title from translated content.
	 */
	const DERIVED_TITLE_MAX_LENGTH = 80;

	/**
	 * Absolute maximum plain-text length for something to be treated as title meta.
	 */
	const STOREFRONT_TITLE_HARD_MAX_LENGTH = 500;

	/**
	 * Resolve a stored core-field translation for storefront display.
	 *
	 * Serves admin-saved meta even when the source fingerprint gate fails.
	 *
	 * @param int           $post_id    Post ID.
	 * @param string        $source_key post_title|post_excerpt|post_content|WVE meta keys.
	 * @param string        $lang       Target language code.
	 * @param \WP_Post|null $post       Optional post object.
	 * @return string Translated value, or empty string when unavailable.
	 */
	public static function resolve_storefront_core_field( $post_id, $source_key, $lang, $post = null ) {
		if ( 'post_title' === (string) $source_key ) {
			return self::resolve_storefront_title( $post_id, $lang, true );
		}

		return self::resolve_storefront_field( $post_id, $source_key, $lang, $post );
	}

	/**
	 * Resolve a translated product/post title for storefront display.
	 *
	 * WooCommerce cart lines and product names must never fall back to long-form
	 * description copy — only dedicated title meta (or a very short derived heading).
	 *
	 * @param int    $post_id                 Post ID.
	 * @param string $lang                    Target language code.
	 * @param bool   $allow_content_fallback  Whether missing title meta may derive from content.
	 * @return string
	 */
	public static function resolve_storefront_title( $post_id, $lang, $allow_content_fallback = false ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return '';
		}

		$meta_key = self::get_meta_key( 'title', $lang );
		$stored   = get_post_meta( $post_id, $meta_key, true );

		if ( is_string( $stored ) && self::has_meaningful_translation( $stored ) ) {
			$formatted = self::format_storefront_title( $stored, self::STOREFRONT_TITLE_MAX_LENGTH );

			if ( '' !== $formatted ) {
				return $formatted;
			}
		}

		if ( ! $allow_content_fallback ) {
			return '';
		}

		return self::derive_storefront_title_fallback( $post_id, $lang );
	}

	/**
	 * Normalize and cap a stored title translation for storefront display.
	 *
	 * Admin-saved title meta is always kept — only obvious description blobs are rejected.
	 *
	 * @param mixed $value      Raw title value.
	 * @param int   $max_length Soft display cap (longer values are trimmed, not dropped).
	 * @return string
	 */
	private static function format_storefront_title( $value, $max_length = 120 ) {
		$plain = self::normalize_translation_plaintext( $value );

		if ( '' === $plain ) {
			return '';
		}

		$max_length = max( 40, absint( $max_length ) );

		if ( mb_strlen( $plain ) > self::STOREFRONT_TITLE_HARD_MAX_LENGTH ) {
			return '';
		}

		if ( mb_strlen( $plain ) <= $max_length ) {
			return $plain;
		}

		$cut        = mb_substr( $plain, 0, $max_length );
		$last_space = mb_strrpos( $cut, ' ' );

		if ( false !== $last_space && $last_space > (int) ( $max_length * 0.6 ) ) {
			$cut = mb_substr( $cut, 0, $last_space );
		}

		return trim( $cut );
	}

	/**
	 * Resolve a stored translation for any mapped storefront source field.
	 *
	 * @param int           $post_id    Post ID.
	 * @param string        $source_key Source field identifier.
	 * @param string        $lang       Target language code.
	 * @param \WP_Post|null $post       Optional post object.
	 * @return string Translated value, or empty string when unavailable.
	 */
	public static function resolve_storefront_field( $post_id, $source_key, $lang, $post = null ) {
		$post_id    = absint( $post_id );
		$source_key = (string) $source_key;
		$lang       = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $source_key || '' === $lang ) {
			return '';
		}

		$meta_key = self::resolve_translated_meta_key_for_source( $source_key, $lang );

		if ( '' === $meta_key ) {
			return '';
		}

		$stored = get_post_meta( $post_id, $meta_key, true );

		if ( 'post_title' === $source_key ) {
			return self::resolve_storefront_title( $post_id, $lang, true );
		}

		// Admin-saved meta is authoritative on the storefront, even when source hashes lag.
		if ( is_string( $stored ) && self::has_meaningful_translation( $stored ) && self::is_clean_target_language_translation( $stored, $lang ) ) {
			return $stored;
		}

		// Manual meta-box edits for WVE fields may still live in {key}_{lang}.
		if (
			in_array( $source_key, array( self::WVE_VARIATION_CUSTOM_TITLE_META, self::WVE_VARIATION_CUSTOM_DESCRIPTION_META ), true )
			&& self::is_custom_meta_key( $source_key )
		) {
			$legacy = get_post_meta( $post_id, self::get_custom_meta_key( $source_key, $lang ), true );

			if ( is_string( $legacy ) && self::is_clean_target_language_translation( $legacy, $lang ) ) {
				return $legacy;
			}
		}

		return '';
	}

	/**
	 * Preview the product/post title that the storefront will resolve for one language.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return string
	 */
	public static function peek_storefront_title( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return '';
		}

		$resolved = self::resolve_storefront_title( $post_id, $lang, true );

		if ( '' !== trim( $resolved ) ) {
			return $resolved;
		}

		$post = get_post( $post_id );

		return $post instanceof \WP_Post ? (string) $post->post_title : '';
	}

	/**
	 * Whether the storefront title is derived from content because title meta is empty.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return bool
	 */
	public static function storefront_title_uses_content_fallback( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$title_meta = get_post_meta( $post_id, self::get_meta_key( 'title', $lang ), true );

		if ( self::has_meaningful_translation( $title_meta ) && self::is_clean_target_language_translation( $title_meta, $lang ) ) {
			return false;
		}

		return '' !== trim( self::derive_storefront_title_fallback( $post_id, $lang ) );
	}

	/**
	 * Clear every PolyMart translation row for one post and one target language.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return void
	 */
	public static function clear_post_language_translations( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return;
		}

		$suffix = '_' . $lang;

		foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $meta_key ) {
			if ( ! is_string( $meta_key ) || '' === $meta_key ) {
				continue;
			}

			$is_polymart = 0 === strpos( $meta_key, '_polymart_ai_' );
			$is_langged  = strlen( $meta_key ) > strlen( $suffix ) && substr( $meta_key, -strlen( $suffix ) ) === $suffix;

			if ( $is_polymart || $is_langged ) {
				delete_post_meta( $post_id, $meta_key );
			}
		}

		delete_post_meta( $post_id, '_polymart_ai_translated_at_' . $lang );
		self::flush_translation_status_cache( $post_id );
	}

	/**
	 * Force a full AI re-translation for one post and language (clears stale rows first).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return true|\WP_Error
	 */
	public static function retranslate_post( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return new \WP_Error(
				'polymart_ai_invalid_post',
				__( 'شناسه مطلب یا زبان نامعتبر است.', 'polymart-ai' )
			);
		}

		self::clear_post_language_translations( $post_id, $lang );

		$result = self::request_ai_translation( $post_id, $lang );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::save_ai_translations( $post_id, $result['translations'], $lang );
	}

	/**
	 * Resolve a missing title from stored content when it looks like a real product name.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return string
	 */
	private static function derive_storefront_title_fallback( $post_id, $lang ) {
		$candidate = self::derive_storefront_title_fallback_from_content( $post_id, $lang );

		if ( '' === trim( $candidate ) || self::is_unsuitable_derived_product_title( $candidate, $post_id ) ) {
			return '';
		}

		return $candidate;
	}

	/**
	 * Reject alert/notice snippets that must never become a product title.
	 *
	 * @param string $candidate Derived title candidate.
	 * @param int    $post_id   Post ID.
	 * @return bool
	 */
	private static function is_unsuitable_derived_product_title( $candidate, $post_id ) {
		$candidate = trim( wp_strip_all_tags( html_entity_decode( (string) $candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

		if ( '' === $candidate ) {
			return true;
		}

		$post = get_post( absint( $post_id ) );

		if ( ! $post instanceof \WP_Post ) {
			return true;
		}

		$source_title = trim( (string) $post->post_title );
		$source_len   = mb_strlen( $source_title );
		$candidate_len = mb_strlen( $candidate );

		$notice_patterns = array(
			'/^(attention|notice|warning|caution|important|alert)\s*[!.:,\-–—]*$/iu',
			'/^(توجه|تنبيه|تنبيه|هشدار|اخطار|تذكير)\s*[!.:،\-–—]*$/u',
		);

		foreach ( $notice_patterns as $pattern ) {
			if ( preg_match( $pattern, $candidate ) ) {
				return true;
			}
		}

		if ( $source_len >= 20 && $candidate_len <= 16 ) {
			return true;
		}

		if ( $candidate_len > self::DERIVED_TITLE_MAX_LENGTH ) {
			return true;
		}

		return false;
	}

	/**
	 * Use stored content translation as a title when the title meta row is missing.
	 *
	 * Some products only have `_polymart_ai_content_{lang}` saved (partial auto-translate).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return string
	 */
	private static function derive_storefront_title_fallback_from_content( $post_id, $lang ) {
		$content = get_post_meta( absint( $post_id ), self::get_meta_key( 'content', $lang ), true );

		if ( ! is_string( $content ) || ! self::has_meaningful_translation( $content ) ) {
			return '';
		}

		if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $content, $matches ) ) {
			$heading = trim( wp_strip_all_tags( html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

			if ( self::has_meaningful_translation( $heading ) && mb_strlen( $heading ) <= self::DERIVED_TITLE_MAX_LENGTH ) {
				return $heading;
			}
		}

		$plain = trim( wp_strip_all_tags( html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

		if ( ! self::has_meaningful_translation( $plain ) ) {
			return '';
		}

		$first_line = trim( (string) preg_split( '/\r\n|\r|\n/', $plain, 2 )[0] );

		if ( '' === $first_line ) {
			return '';
		}

		if ( mb_strlen( $first_line ) > self::DERIVED_TITLE_MAX_LENGTH ) {
			return '';
		}

		return $first_line;
	}

	/**
	 * Database meta key that stores the translated companion for one source field.
	 *
	 * WVE variation custom titles map to `_polymart_ai_title_{lang}`, not `_custom_title_{lang}`.
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
	 * Get the stored translated featured image attachment ID.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return int
	 */
	public static function get_translated_thumbnail_id( $post_id, $lang ) {
		$attachment_id = (int) get_post_meta(
			absint( $post_id ),
			self::get_thumbnail_meta_key( $lang ),
			true
		);

		if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
			return $attachment_id;
		}

		return 0;
	}

	/**
	 * Copy the source featured image for languages that do not need a separate media asset.
	 *
	 * Auto-translate cannot AI-translate image binaries; without this fallback, slides
	 * that only (or also) need a thumbnail stay permanently incomplete in the job queue.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return int Attachment ID stored (0 on failure).
	 */
	public static function ensure_translated_thumbnail_fallback( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return 0;
		}

		$existing = self::get_translated_thumbnail_id( $post_id, $lang );

		if ( $existing > 0 ) {
			return $existing;
		}

		$source_id = (int) get_post_thumbnail_id( $post_id );

		if ( $source_id <= 0 || 'attachment' !== get_post_type( $source_id ) ) {
			return 0;
		}

		update_post_meta( $post_id, self::get_thumbnail_meta_key( $lang ), $source_id );

		return $source_id;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'pre_post_update', array( $this, 'maybe_begin_admin_translation_persist' ), 1, 1 );
		add_action( 'save_post', array( $this, 'save_manual_translations' ), 99, 3 );
		add_action( 'save_post', array( $this, 'sync_translation_index_on_save' ), 99, 1 );
		add_action( 'updated_post_meta', array( $this, 'maybe_invalidate_elementor_on_source_change' ), 10, 4 );
		add_action( 'shutdown', array( __CLASS__, 'end_persisting_translations' ), 999 );
	}

	/**
	 * Block async invalidation before post_updated runs during admin saves.
	 *
	 * post_updated fires inside wp_update_post(), before save_post — the meta box
	 * save handler has not run yet, so we must arm the guard here when the edit
	 * form includes our nonce.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function maybe_begin_admin_translation_persist( $post_id ) {
		unset( $post_id );

		if ( self::is_admin_translation_save_request() ) {
			self::begin_persisting_translations();
		}
	}

	/**
	 * Drop stored Elementor companions when the Persian source document changes.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New meta value.
	 * @return void
	 */
	public function maybe_invalidate_elementor_on_source_change( $meta_id, $post_id, $meta_key, $meta_value ) {
		unset( $meta_id, $meta_value );

		if ( '_elementor_data' !== $meta_key ) {
			return;
		}

		self::invalidate_elementor_translations( absint( $post_id ) );
	}

	/**
	 * Save manual English translation fields submitted from the meta box.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 * @return void
	 */
	public function save_manual_translations( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::META_BOX_NONCE_NAME ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::META_BOX_NONCE_NAME ] ) ), self::META_BOX_NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		self::begin_persisting_translations();

		$languages = Language_Registry::get_translation_target_languages();

		try {
			foreach ( $languages as $language ) {
			$lang = sanitize_key( (string) $language['code'] );

			if ( '' === $lang ) {
				continue;
			}

			$this->save_meta_field( $post_id, self::get_meta_key( 'title', $lang ), 'text' );
			$this->save_meta_field( $post_id, self::get_meta_key( 'excerpt', $lang ), 'textarea' );
			$this->save_meta_field( $post_id, self::get_meta_key( 'content', $lang ), 'html' );

			foreach ( self::CUSTOM_META_KEYS as $meta_key ) {
				$this->save_meta_field( $post_id, self::get_custom_meta_key( $meta_key, $lang ), 'text' );
			}

			foreach ( array_keys( self::collect_discovered_meta_fields( $post_id ) ) as $meta_key ) {
				$this->save_meta_field( $post_id, self::get_custom_meta_key( $meta_key, $lang ), 'textarea' );
			}

			if ( self::supports_featured_image_translation( $post->post_type ) ) {
				$this->save_thumbnail_field( $post_id, $lang );
			}

			self::sync_field_source_hashes_for_post( $post_id, $lang );
		}

		self::flush_translation_status_cache( $post_id );

		foreach ( $languages as $language ) {
			$lang = sanitize_key( (string) $language['code'] );

			if ( '' === $lang ) {
				continue;
			}

			if ( 'translated' === self::get_translation_status( $post_id, $lang ) ) {
				update_post_meta( $post_id, '_polymart_ai_translated_at_' . $lang, time() );
			} else {
				delete_post_meta( $post_id, '_polymart_ai_translated_at_' . $lang );
			}
		}

		if ( 'translated' === self::get_translation_status( $post_id ) ) {
			update_post_meta( $post_id, '_polymart_ai_translated_at', time() );
		} else {
			delete_post_meta( $post_id, '_polymart_ai_translated_at' );
		}

		self::sync_translation_index_meta( $post_id );
		} finally {
			self::end_persisting_translations();
		}
	}

	/**
	 * Keep translation index meta in sync after any supported post save.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function sync_translation_index_on_save( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return;
		}

		self::sync_translation_index_meta( $post_id );
	}

	/**
	 * Collect core post fields (title, content, excerpt, meta) for AI translation.
	 *
	 * Excludes taxonomy terms, product attributes, and variation titles so large
	 * variable products can be translated in separate job slices.
	 *
	 * @param \WP_Post $post Post object.
	 * @param string   $lang Target language code.
	 * @return array<string, string>
	 */
	private static function collect_core_persian_fields( \WP_Post $post, $lang = '' ) {
		$fields = array();
		$lang   = sanitize_key( (string) $lang );

		$title = Persian_Detector::only_persian_value( $post->post_title );

		if ( '' !== $title && ( '' === $lang || ! self::is_field_translation_current( $post->ID, 'post_title', $lang, $title ) ) ) {
			$fields['post_title'] = $title;
		}

		if ( self::uses_elementor_builder( $post->ID ) && ! self::is_commerce_product_post( $post->ID, $post ) ) {
			if ( '' !== self::collect_elementor_persian_plain_text( $post->ID ) ) {
				// Translated via persist_elementor_translation().
			} else {
				$content = self::extract_elementor_html_persian_excerpt( $post->post_content );

				if ( '' !== $content && ( '' === $lang || ! self::is_field_translation_current( $post->ID, 'post_content', $lang, $content ) ) ) {
					$fields['post_content'] = $content;
				}
			}
		} else {
			$content = Persian_Detector::only_persian_value( $post->post_content );

			if ( '' !== $content && ( '' === $lang || ! self::is_field_translation_current( $post->ID, 'post_content', $lang, $content ) ) ) {
				$fields['post_content'] = $content;
			}
		}

		$excerpt = Persian_Detector::only_persian_value( $post->post_excerpt );

		if ( '' !== $excerpt && ( '' === $lang || ! self::is_field_translation_current( $post->ID, 'post_excerpt', $lang, $excerpt ) ) ) {
			$fields['post_excerpt'] = $excerpt;
		}

		foreach ( self::CUSTOM_META_KEYS as $meta_key ) {
			$value = get_post_meta( $post->ID, $meta_key, true );
			$value = Persian_Detector::only_persian_value( $value );

			if ( '' === $value ) {
				continue;
			}

			if ( '' !== $lang && self::is_field_translation_current( $post->ID, $meta_key, $lang, $value ) ) {
				continue;
			}

			$fields[ $meta_key ] = $value;
		}

		foreach ( self::collect_discovered_meta_fields( $post->ID, $lang ) as $meta_key => $value ) {
			$fields[ $meta_key ] = $value;
		}

		return $fields;
	}

	/**
	 * Collect taxonomy terms and WooCommerce attribute labels/options.
	 *
	 * @param \WP_Post $post Post object.
	 * @param string   $lang Target language code.
	 * @return array<string, string>
	 */
	private static function collect_commerce_persian_fields( \WP_Post $post, $lang = '' ) {
		$fields = array();
		$lang   = sanitize_key( (string) $lang );

		foreach ( self::collect_translatable_terms( $post->ID ) as $term ) {
			$name = Persian_Detector::only_persian_value( $term->name );

			if ( '' !== $name && ( '' === $lang || ! self::is_term_translation_current( $term->term_id, 'name', $lang, $name ) ) ) {
				$fields[ self::build_term_payload_key( $term->term_id, 'name' ) ] = $name;
			}

			$description = Persian_Detector::only_persian_value( $term->description );

			if ( '' !== $description && ( '' === $lang || ! self::is_term_translation_current( $term->term_id, 'desc', $lang, $description ) ) ) {
				$fields[ self::build_term_payload_key( $term->term_id, 'desc' ) ] = $description;
			}
		}

		foreach ( self::collect_product_attribute_fields( $post, $lang ) as $key => $value ) {
			$fields[ $key ] = $value;
		}

		return $fields;
	}

	/**
	 * Collect Persian source fields for one auto-translate job slice phase.
	 *
	 * @param \WP_Post $post  Post object.
	 * @param string   $lang  Target language code.
	 * @param string   $phase core|commerce|variations
	 * @return array<string, string>
	 */
	public static function collect_persian_fields_for_job_phase( \WP_Post $post, $lang, $phase ) {
		$phase = sanitize_key( (string) $phase );

		switch ( $phase ) {
			case 'commerce':
				$fields = self::collect_commerce_persian_fields( $post, $lang );
				break;
			case 'variations':
				$fields = self::collect_variation_title_fields( $post, $lang );
				break;
			case 'core':
			default:
				$fields = self::collect_core_persian_fields( $post, $lang );
				break;
		}

		/**
		 * Filter Persian source fields before AI translation.
		 *
		 * @param array<string, string> $fields Source field values.
		 * @param \WP_Post              $post   Post object.
		 * @param string                $phase  Job slice phase (core|commerce|variations).
		 */
		return apply_filters( 'polymart_ai_translatable_fields', $fields, $post, $phase );
	}

	/**
	 * Collect Persian source content for AI translation.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<string, string>
	 */
	public static function collect_persian_fields( \WP_Post $post, $lang = '' ) {
		$fields = array_merge(
			self::collect_core_persian_fields( $post, $lang ),
			self::collect_commerce_persian_fields( $post, $lang ),
			self::collect_variation_title_fields( $post, $lang )
		);

		/**
		 * Filter Persian source fields before AI translation.
		 *
		 * @param array<string, string> $fields Source field values.
		 * @param \WP_Post              $post   Post object.
		 */
		return apply_filters( 'polymart_ai_translatable_fields', $fields, $post );
	}

	/**
	 * Split a translation payload into API-friendly batches.
	 *
	 * @param array<string, string> $payload Field map.
	 * @return array<int, array<string, string>>
	 */
	public static function chunk_payload_for_ai( array $payload ) {
		return self::chunk_payload_with_limits(
			$payload,
			self::AI_FIELD_CHUNK_SIZE,
			self::AI_MAX_CHUNK_CHARS
		);
	}

	/**
	 * Split a translation payload using phase-aware batch sizes.
	 *
	 * @param array<string, string> $payload Field map.
	 * @param string                $phase   core|commerce|variations.
	 * @return array<int, array<string, string>>
	 */
	private static function chunk_payload_for_job_phase( array $payload, $phase, $post_id = 0 ) {
		$phase   = sanitize_key( (string) $phase );
		$post_id = absint( $post_id );

		switch ( $phase ) {
			case 'variations':
				$max_fields = self::VARIATION_AI_FIELD_CHUNK_SIZE;
				$max_chars  = self::VARIATION_AI_MAX_CHUNK_CHARS;

				if ( $post_id > 0 && function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( $post_id );

					if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
						$variation_count = count( $product->get_children() );

						if ( $variation_count > 80 ) {
							$max_fields = 5;
							$max_chars  = 2500;
						} elseif ( $variation_count > 30 ) {
							$max_fields = 4;
							$max_chars  = 2200;
						}
					}
				}

				return self::chunk_payload_with_limits(
					$payload,
					$max_fields,
					$max_chars
				);
			case 'commerce':
				// Keep related WooCommerce fields in one request when possible.
				// Fewer sequential gateway calls materially shortens each product.
				$max_fields = self::COMMERCE_AI_FIELD_CHUNK_SIZE;
				$max_chars  = self::COMMERCE_AI_MAX_CHUNK_CHARS;

				if ( $post_id > 0 && function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( $post_id );

					if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
						$max_fields = 1;
						$max_chars  = 1500;
					}
				}

				return self::chunk_payload_with_limits(
					$payload,
					$max_fields,
					$max_chars
				);
			case 'core':
			default:
				return self::chunk_payload_with_limits(
					$payload,
					self::AI_FIELD_CHUNK_SIZE,
					self::AI_MAX_CHUNK_CHARS
				);
		}
	}

	/**
	 * HTTP options for AI calls made during auto-translate job slices.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $phase   Job phase key.
	 * @return array<string, int>
	 */
	public static function get_job_ai_request_options( $post_id = 0, $phase = '' ) {
		unset( $post_id, $phase );

		$options = array(
			'min_timeout' => self::JOB_REQUEST_MIN_TIMEOUT,
			'max_timeout' => self::JOB_REQUEST_MAX_TIMEOUT,
		);

		/**
		 * Filter HTTP timeout bounds for auto-translate job AI requests.
		 *
		 * @param array<string, int> $options min_timeout / max_timeout in seconds.
		 * @param int                $post_id Post ID.
		 * @param string             $phase   Job phase key.
		 */
		return apply_filters( 'polymart_ai_job_ai_request_options', $options, absint( $post_id ), sanitize_key( (string) $phase ) );
	}

	/**
	 * Split a translation payload into API-friendly batches with explicit limits.
	 *
	 * @param array<string, string> $payload    Field map.
	 * @param int                   $max_fields Max fields per batch.
	 * @param int                   $max_chars  Max combined characters per batch.
	 * @return array<int, array<string, string>>
	 */
	private static function chunk_payload_with_limits( array $payload, $max_fields, $max_chars ) {
		$payload       = self::expand_payload_for_ai( $payload );
		$chunks        = array();
		$current       = array();
		$current_chars = 0;
		$max_fields    = max( 1, absint( $max_fields ) );
		$max_chars     = max( 500, absint( $max_chars ) );

		foreach ( $payload as $key => $value ) {
			$value = (string) $value;
			$size  = strlen( (string) $key ) + strlen( $value );

			if (
				! empty( $current )
				&& (
					$current_chars + $size > $max_chars
					|| count( $current ) >= $max_fields
				)
			) {
				$chunks[]      = $current;
				$current       = array();
				$current_chars = 0;
			}

			$current[ $key ] = $value;
			$current_chars  += $size;
		}

		if ( ! empty( $current ) ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Split oversized field values so each API request stays within safe limits.
	 *
	 * @param array<string, string> $payload Field map.
	 * @return array<string, string>
	 */
	public static function expand_payload_for_ai( array $payload ) {
		$expanded = array();

		foreach ( $payload as $key => $value ) {
			$value = (string) $value;

			if ( strlen( $value ) <= self::AI_MAX_SINGLE_FIELD_CHARS ) {
				$expanded[ $key ] = $value;
				continue;
			}

			$offset = 0;
			$part   = 1;

			while ( $offset < strlen( $value ) ) {
				$slice = substr( $value, $offset, self::AI_MAX_SINGLE_FIELD_CHARS );
				$expanded[ $key . '::__part' . $part ] = $slice;
				$offset += strlen( $slice );
				++$part;
			}
		}

		return $expanded;
	}

	/**
	 * Merge translated payload parts back into their original field keys.
	 *
	 * @param array<string, string> $translations Translated field map.
	 * @return array<string, string>
	 */
	public static function collapse_payload_parts( array $translations ) {
		$merged = array();

		foreach ( $translations as $key => $value ) {
			if ( preg_match( '/^(.+)::__part\d+$/', (string) $key, $matches ) ) {
				$base = $matches[1];

				if ( ! isset( $merged[ $base ] ) ) {
					$merged[ $base ] = '';
				}

				$merged[ $base ] .= (string) $value;
				continue;
			}

			$merged[ $key ] = $value;
		}

		return $merged;
	}

	/**
	 * Post meta prefix for in-progress auto-translate job slices (per post + language).
	 */
	const JOB_PARTIAL_META_PREFIX = '_polymart_ai_job_partial_';

	/**
	 * Max field batches processed per auto-translate HTTP step.
	 *
	 * @return int
	 */
	public static function get_job_step_max_field_chunks() {
		/**
		 * Filter how many AI field batches one auto-translate step may run.
		 *
		 * @param int $chunks Default 2.
		 */
		return max( 1, (int) apply_filters( 'polymart_ai_job_step_max_field_chunks', 2 ) );
	}

	/**
	 * Per-phase batch budget for one auto-translate HTTP step.
	 *
	 * @param string $phase   core|commerce|variations|elementor.
	 * @param int    $post_id Post ID.
	 * @return int
	 */
	public static function get_job_step_max_field_chunks_for_phase( $phase, $post_id = 0 ) {
		$phase   = sanitize_key( (string) $phase );
		$post_id = absint( $post_id );
		$chunks  = self::get_job_step_max_field_chunks();

		if ( 'elementor' === $phase ) {
			$chunks = 1;
		} elseif ( 'commerce' === $phase ) {
			$chunks = 1;
		} elseif ( 'variations' === $phase ) {
			$chunks = 2;

			if ( $post_id > 0 && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $post_id );

				if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
					$variation_count = count( $product->get_children() );

					if ( $variation_count > 80 ) {
						$chunks = 3;
					} elseif ( $variation_count > 30 ) {
						$chunks = 2;
					}
				}
			}
		}

		/**
		 * Filter per-phase chunk budget for auto-translate job steps.
		 *
		 * @param int    $chunks  Batch count for this step.
		 * @param string $phase   Phase key.
		 * @param int    $post_id Post ID.
		 */
		return max( 1, (int) apply_filters( 'polymart_ai_job_step_max_field_chunks_for_phase', $chunks, $phase, $post_id ) );
	}

	/**
	 * Max Elementor batches processed per auto-translate HTTP step.
	 *
	 * @return int
	 */
	public static function get_job_step_max_elementor_chunks() {
		/**
		 * Filter how many Elementor AI batches one auto-translate step may run.
		 *
		 * @param int $chunks Default 1 (keeps heavy pages under typical proxy timeouts).
		 */
		return max( 1, (int) apply_filters( 'polymart_ai_job_step_max_elementor_chunks', 1 ) );
	}

	/**
	 * How many consecutive HTTP steps one post may take before rotation/defer.
	 *
	 * Variable products and Elementor pages need many slices — limits scale with workload.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $phase   Job slice phase.
	 * @return array{max_consecutive: int, max_per_run: int}
	 */
	public static function get_job_step_limits_for_post( $post_id, $phase ) {
		$post_id          = absint( $post_id );
		$phase            = sanitize_key( (string) $phase );
		$max_consecutive  = 8;
		$max_per_run      = 24;

		if ( 'elementor' === $phase ) {
			$max_consecutive = 40;
			$max_per_run     = 80;
		} elseif ( 'commerce' === $phase ) {
			$max_consecutive = 20;
			$max_per_run     = 50;
		} elseif ( 'variations' === $phase && $post_id > 0 && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );

			if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
				$variation_count = count( $product->get_children() );
				$fields_per_var  = 2;
				$chunk_fields    = self::VARIATION_AI_FIELD_CHUNK_SIZE;
				$chunks_per_step = max( 1, self::get_job_step_max_field_chunks_for_phase( 'variations', $post_id ) );

				if ( $variation_count > 80 ) {
					$chunk_fields = 5;
				} elseif ( $variation_count > 30 ) {
					$chunk_fields = 4;
				}

				$fields_per_step = max( 1, $chunk_fields * $chunks_per_step );
				$estimated_steps = max( 4, (int) ceil( ( $variation_count * $fields_per_var ) / $fields_per_step ) );
				$max_consecutive = min( 80, max( 20, $estimated_steps + 5 ) );
				$max_per_run     = min( 120, $max_consecutive + 20 );
			}
		}

		/**
		 * Filter consecutive step limits for one post during an auto-translate job.
		 *
		 * @param array{max_consecutive: int, max_per_run: int} $limits  Computed limits.
		 * @param int                                           $post_id Post ID.
		 * @param string                                        $phase   Phase key.
		 */
		$limits = apply_filters(
			'polymart_ai_job_step_limits',
			array(
				'max_consecutive' => $max_consecutive,
				'max_per_run'     => $max_per_run,
			),
			$post_id,
			$phase
		);

		return array(
			'max_consecutive' => max( 1, (int) ( $limits['max_consecutive'] ?? $max_consecutive ) ),
			'max_per_run'     => max( 1, (int) ( $limits['max_per_run'] ?? $max_per_run ) ),
		);
	}

	/**
	 * Build durable partial state starting at the first phase that still needs work.
	 *
	 * @param int           $post_id Post ID.
	 * @param string        $lang    Target language code.
	 * @param \WP_Post|null $post    Optional post object.
	 * @return array<string, mixed>
	 */
	public static function build_initial_job_partial_state( $post_id, $lang, $post = null ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$base    = array(
			'field_chunk_index'      => 0,
			'elementor_map'          => array(),
			'elementor_chunk_index'  => 0,
			'elementor_failures'     => array(),
			'elementor_chunks_total' => 0,
		);

		if ( $post_id <= 0 || '' === $lang ) {
			return array_merge( $base, array( 'phase' => 'core' ) );
		}

		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post instanceof \WP_Post ) {
			return array_merge( $base, array( 'phase' => 'core' ) );
		}

		foreach ( self::get_job_field_phases() as $phase ) {
			$fields = self::collect_persian_fields_for_job_phase( $post, $lang, $phase );

			if ( ! empty( $fields ) ) {
				return array_merge( $base, array( 'phase' => $phase ) );
			}
		}

		if ( self::post_needs_elementor_job_work( $post_id, $lang ) ) {
			$state = array_merge( $base, array( 'phase' => 'elementor' ) );
			$raw   = get_post_meta( $post_id, '_elementor_data', true );

			if ( is_string( $raw ) && '' !== $raw ) {
				$source_data = json_decode( $raw, true );

				if ( is_array( $source_data ) ) {
					$restored_map = self::rebuild_elementor_map_from_saved_translation( $post_id, $lang, $source_data );

					if ( ! empty( $restored_map ) ) {
						$state['elementor_map'] = $restored_map;
					}
				}
			}

			return $state;
		}

		return array_merge( $base, array( 'phase' => 'complete' ) );
	}

	/**
	 * Whether a job slice error should preserve partial progress and retry later.
	 *
	 * @param \WP_Error $error Error object.
	 * @return bool
	 */
	public static function is_recoverable_job_slice_error( $error ) {
		if ( ! $error instanceof \WP_Error ) {
			return false;
		}

		$recoverable_codes = array(
			'polymart_ai_timeout',
			'polymart_ai_http_error',
			'polymart_ai_api_error',
			'polymart_ai_rate_limited',
			'polymart_ai_invalid_json',
			'polymart_ai_invalid_response',
			'polymart_ai_missing_choices',
			'polymart_ai_empty_response',
			'polymart_ai_no_translations',
			'polymart_ai_chunk_empty',
			'polymart_ai_job_slice_failed',
		);

		if ( in_array( $error->get_error_code(), $recoverable_codes, true ) ) {
			return true;
		}

		$message = strtolower( $error->get_error_message() );

		return false !== strpos( $message, 'timeout' )
			|| false !== strpos( $message, 'timed out' )
			|| false !== strpos( $message, 'منقضی' )
			|| false !== strpos( $message, 'gateway' )
			|| false !== strpos( $message, 'rate limit' )
			|| false !== strpos( $message, '429' )
			|| false !== strpos( $message, 'ربات' );
	}

	/**
	 * Whether durable partial state exists for a resumable job slice.
	 *
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @param array<string, mixed> $state   Partial state.
	 * @return bool
	 */
	public static function job_partial_state_has_progress( $post_id, $lang, array $state = array() ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( empty( $state ) ) {
			$state = self::get_job_partial_state( $post_id, $lang );
		}

		if ( empty( $state ) || ! is_array( $state ) ) {
			return (bool) get_post_meta( $post_id, self::get_elementor_meta_key( $lang ), true );
		}

		$phase = sanitize_key( (string) ( $state['phase'] ?? '' ) );

		if ( 'elementor' === $phase ) {
			$map = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();

			return ! empty( $map ) || absint( $state['elementor_chunk_index'] ?? 0 ) > 0;
		}

		if ( in_array( $phase, array( 'core', 'commerce', 'variations', 'fields' ), true ) ) {
			return absint( $state['field_chunk_index'] ?? 0 ) > 0;
		}

		return false;
	}

	/**
	 * Build a resumable partial slice response after a recoverable API failure.
	 *
	 * @param string               $phase    Phase key.
	 * @param string               $progress Progress label.
	 * @param string               $message  Human-readable message.
	 * @return array{done: bool, phase: string, phase_progress: string, message: string, recoverable: bool}
	 */
	public static function make_recoverable_partial_slice_response( $phase, $progress, $message ) {
		return array(
			'done'           => false,
			'phase'          => sanitize_key( (string) $phase ),
			'phase_progress' => (string) $progress,
			'message'        => (string) $message,
			'recoverable'    => true,
		);
	}

	/**
	 * @param string $lang Language code.
	 * @return string
	 */
	private static function get_job_partial_meta_key( $lang ) {
		return self::JOB_PARTIAL_META_PREFIX . sanitize_key( (string) $lang );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return array<string, mixed>
	 */
	public static function get_job_partial_state( $post_id, $lang ) {
		$stored = get_post_meta( absint( $post_id ), self::get_job_partial_meta_key( $lang ), true );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @param array<string, mixed> $state   Partial job state.
	 * @return void
	 */
	public static function save_job_partial_state( $post_id, $lang, array $state ) {
		update_post_meta( absint( $post_id ), self::get_job_partial_meta_key( $lang ), $state );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return void
	 */
	public static function clear_job_partial_state( $post_id, $lang ) {
		delete_post_meta( absint( $post_id ), self::get_job_partial_meta_key( $lang ) );
	}

	/**
	 * Ordered field phases for auto-translate job slices.
	 *
	 * @return string[]
	 */
	private static function get_job_field_phases() {
		return array( 'core', 'commerce', 'variations' );
	}

	/**
	 * @param string $phase Current field phase.
	 * @return string|null Next phase or null when complete.
	 */
	private static function get_next_job_field_phase( $phase ) {
		$phases = self::get_job_field_phases();
		$index  = array_search( sanitize_key( (string) $phase ), $phases, true );

		if ( false === $index || $index >= count( $phases ) - 1 ) {
			return null;
		}

		return $phases[ $index + 1 ];
	}

	/**
	 * Human-readable label for a job field phase progress message.
	 *
	 * @param string $phase Phase key.
	 * @return string
	 */
	private static function get_job_field_phase_label( $phase ) {
		switch ( sanitize_key( (string) $phase ) ) {
			case 'commerce':
				return __( 'ویژگی‌ها و دسته‌ها', 'polymart-ai' );
			case 'variations':
				return __( 'تنوع‌های محصول', 'polymart-ai' );
			case 'core':
			default:
				return __( 'محتوای اصلی', 'polymart-ai' );
		}
	}

	/**
	 * Translate and persist the next batch(es) for one job field phase.
	 *
	 * Each batch is saved immediately so variable products keep progress even
	 * when the site-wide job pauses mid-product.
	 *
	 * @param int                  $post_id      Post ID.
	 * @param \WP_Post             $post         Post object.
	 * @param string               $lang         Language code.
	 * @param array<string, mixed> $state        Partial state (by reference).
	 * @param string               $phase        core|commerce|variations
	 * @param string               $api_key      API key.
	 * @param string               $api_endpoint API endpoint.
	 * @param string               $ai_model     Model name.
	 * @return array{partial: bool, phase_complete: bool, phase_progress: string, message: string}|\WP_Error
	 */
	private static function run_job_field_phase_batch( $post_id, \WP_Post $post, $lang, array &$state, $phase, $api_key, $api_endpoint, $ai_model ) {
		$payload     = self::collect_persian_fields_for_job_phase( $post, $lang, $phase );
		$chunks      = empty( $payload ) ? array() : self::chunk_payload_for_job_phase( $payload, $phase, $post->ID );
		$total       = count( $chunks );
		$label       = self::get_job_field_phase_label( $phase );
		$pending     = count( $payload );
		$ai_options  = self::get_job_ai_request_options( $post->ID, $phase );

		if ( 0 === $total ) {
			return array(
				'partial'        => false,
				'phase_complete' => true,
				'phase_progress' => '0/0',
				'message'        => '',
			);
		}

		// Remaining work is re-collected each HTTP step — always start from the first batch.
		if ( in_array( $phase, array( 'variations', 'commerce' ), true ) ) {
			$state['field_chunk_index'] = 0;
		}

		$index  = max( 0, (int) ( $state['field_chunk_index'] ?? 0 ) );

		if ( $index >= $total ) {
			$index                      = 0;
			$state['field_chunk_index'] = 0;
		}

		$budget = self::get_job_step_max_field_chunks_for_phase( $phase, $post->ID );

		while ( $index < $total && $budget > 0 ) {
			$chunk        = $chunks[ $index ];
			$chunk_result = AI_Client::translate_fields(
				$chunk,
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang,
				$ai_options
			);

			if ( is_wp_error( $chunk_result ) ) {
				$fallback_limit = max( 1, count( $chunk ) );

				$recovered = self::translate_job_chunk_with_single_field_fallback(
					$chunk,
					$api_key,
					$api_endpoint,
					$ai_model,
					$lang,
					$fallback_limit,
					$ai_options
				);

				if ( is_wp_error( $recovered ) ) {
					return $recovered;
				}

				$chunk_result = $recovered;
			}

			$batch = self::collapse_payload_parts( $chunk_result );

			if ( ! empty( $batch ) ) {
				$save_result = self::save_ai_translations(
					$post_id,
					$batch,
					$lang,
					array( 'skip_elementor' => true )
				);

				if ( is_wp_error( $save_result ) ) {
					return $save_result;
				}

				self::sync_translation_index_meta( $post_id, $lang );
			}

			++$index;
			--$budget;
		}

		$state['field_chunk_index'] = $index;

		if ( $index < $total ) {
			return array(
				'partial'        => true,
				'phase_complete' => false,
				'phase_progress' => $index . '/' . $total,
				'message'        => sprintf(
					/* translators: 1: phase label, 2: completed batches, 3: total batches, 4: pending field count */
					__( '%1$s — بخش %2$d از %3$d (%4$d فیلد باقی‌مانده)', 'polymart-ai' ),
					$label,
					$index,
					$total,
					$pending
				),
			);
		}

		return array(
			'partial'        => false,
			'phase_complete' => true,
			'phase_progress' => $total . '/' . $total,
			'message'        => sprintf(
				/* translators: %s: phase label */
				__( '%s — تکمیل شد', 'polymart-ai' ),
				$label
			),
		);
	}

	/**
	 * Retry failed AI batches one field at a time (bounded).
	 *
	 * @param array<string, string> $chunk         Source fields.
	 * @param string                $api_key       API key.
	 * @param string                $api_endpoint  API endpoint.
	 * @param string                $ai_model      Model name.
	 * @param string                $lang          Target language.
	 * @param int                   $max_fallbacks Max single-field attempts.
	 * @return array<string, string>|\WP_Error
	 */
	private static function translate_job_chunk_with_single_field_fallback( array $chunk, $api_key, $api_endpoint, $ai_model, $lang, $max_fallbacks = 2, array $options = array() ) {
		$merged     = array();
		$last_error = null;
		$attempts   = 0;

		foreach ( $chunk as $key => $text ) {
			if ( $attempts >= max( 1, absint( $max_fallbacks ) ) ) {
				break;
			}

			++$attempts;

			$single = AI_Client::translate_fields(
				array( (string) $key => (string) $text ),
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang,
				$options
			);

			if ( is_wp_error( $single ) ) {
				$last_error = $single;
				continue;
			}

			$merged = array_merge( $merged, $single );
		}

		if ( ! empty( $merged ) ) {
			return $merged;
		}

		if ( $last_error instanceof \WP_Error ) {
			return $last_error;
		}

		return new \WP_Error(
			'polymart_ai_chunk_empty',
			__( 'هیچ فیلدی از این بخش ترجمه نشد.', 'polymart-ai' )
		);
	}

	/**
	 * Transition job partial state from field phases to Elementor or clear it.
	 *
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @param array<string, mixed> $state   Partial state (by reference).
	 * @return array{done: bool, phase: string, phase_progress: string, message: string}|null Null to continue elementor in caller.
	 */
	private static function finalize_job_field_phases( $post_id, $lang, array &$state ) {
		unset( $state['field_translations'] );

		if ( self::post_needs_elementor_job_work( $post_id, $lang ) ) {
			$state['phase']                  = 'elementor';
			$state['elementor_map']          = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
			$state['elementor_chunk_index']  = 0;
			$state['elementor_chunks_total'] = 0;
			self::save_job_partial_state( $post_id, $lang, $state );

			return array(
				'done'           => false,
				'phase'          => 'elementor',
				'phase_progress' => '0/…',
				'message'        => __( 'فیلدها ذخیره شد — ترجمه Elementor در مراحل بعدی', 'polymart-ai' ),
			);
		}

		self::clear_job_partial_state( $post_id, $lang );
		self::release_translation_lock( $post_id, $lang );

		return array(
			'done'           => true,
			'phase'          => 'complete',
			'phase_progress' => '',
			'message'        => __( 'ترجمه این مورد تکمیل شد.', 'polymart-ai' ),
		);
	}

	/**
	 * Refresh the per-post translation lock TTL while a multi-step job slice runs.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	public static function refresh_translation_lock( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$key     = self::TRANSLATION_LOCK_PREFIX . $post_id . '_' . $lang;
		$claim   = $key . '_claim';
		$now     = time();

		if ( get_transient( $key ) || get_option( $claim ) ) {
			update_option( $claim, (string) $now, false );
			set_transient( $key, $now, self::TRANSLATION_LOCK_TTL );

			return true;
		}

		return self::acquire_translation_lock( $post_id, $lang );
	}

	/**
	 * Whether Elementor JSON still needs work during an auto-translate job.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	public static function post_needs_elementor_job_work( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return false;
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return false;
		}

		if ( ! self::should_require_elementor_translation( $post_id ) ) {
			return false;
		}

		if ( ! self::has_elementor_persian_content( $post_id ) ) {
			return false;
		}

		if ( self::is_elementor_translation_current( $post_id, $lang ) ) {
			$previous_error = (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true );

			if ( self::is_elementor_progress_message( $previous_error ) ) {
				delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
				delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );

				return false;
			}

			if ( '' !== trim( $previous_error ) ) {
				return true;
			}

			return false;
		}

		return true;
	}

	/**
	 * Whether the current request may translate a post (REST bulk job, cron, loopback, or edit_post).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function can_translate_post( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return false;
		}

		// Cron + token-authenticated AJAX loopback have no interactive user.
		if ( \PolymartAI\Activity_Logger::is_trusted_job_worker() ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( REST_API::required_admin_capability() ) ) {
			return true;
		}

		// Site admins running the bulk job may translate any queued post,
		// even when they lack per-post edit caps (custom types / other authors).
		if (
			current_user_can( REST_API::required_admin_capability() )
			&& \PolymartAI\Activity_Logger::is_bulk_job_running()
		) {
			return true;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Process one bounded slice of an auto-translate job step for a single post.
	 *
	 * Heavy Elementor pages are split across many HTTP steps so proxies/PHP limits
	 * do not abort the whole site-wide run.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return array{done: bool, phase: string, phase_progress: string, message: string}|\WP_Error
	 */
	public static function process_job_translation_slice( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( ! $post_id || ! self::can_translate_post( $post_id ) ) {
			return new \WP_Error(
				'polymart_ai_forbidden',
				__( 'شما اجازه ترجمه این مورد را ندارید.', 'polymart-ai' )
			);
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return new \WP_Error(
				'polymart_ai_invalid_post',
				__( 'نوع مطلب برای ترجمه نامعتبر است.', 'polymart-ai' )
			);
		}

		$settings = self::get_translation_settings();

		if ( empty( $settings['api_key'] ) || empty( $settings['api_endpoint'] ) ) {
			return new \WP_Error(
				'polymart_ai_missing_credentials',
				__( 'اعتبارنامه API آروان‌کلاد پیکربندی نشده است.', 'polymart-ai' )
			);
		}

		if ( ! self::refresh_translation_lock( $post_id, $lang ) ) {
			return new \WP_Error(
				'polymart_ai_translation_in_progress',
				__( 'این مورد در حال ترجمه است. لطفاً چند لحظه صبر کنید.', 'polymart-ai' )
			);
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		$state = self::get_job_partial_state( $post_id, $lang );

		if ( empty( $state ) || empty( $state['phase'] ) ) {
			$state = self::build_initial_job_partial_state( $post_id, $lang, $post );
		}

		if ( 'complete' === (string) ( $state['phase'] ?? '' ) ) {
			self::clear_job_partial_state( $post_id, $lang );
			self::release_translation_lock( $post_id, $lang );

			return array(
				'done'           => true,
				'phase'          => 'complete',
				'phase_progress' => '',
				'message'        => __( 'ترجمه این مورد تکمیل شد.', 'polymart-ai' ),
			);
		}

		$api_key      = (string) $settings['api_key'];
		$api_endpoint = rtrim( (string) $settings['api_endpoint'], '/' );
		$ai_model     = AI_Client::resolve_model(
			(string) ( $settings['ai_model'] ?? '' ),
			$api_endpoint
		);

		try {
			if ( 'fields' === (string) $state['phase'] ) {
				$payload    = self::collect_persian_fields( $post, $lang );
				$chunks     = empty( $payload ) ? array() : self::chunk_payload_for_job_phase( $payload, 'core', $post_id );
				$total      = count( $chunks );
				$index      = max( 0, (int) ( $state['field_chunk_index'] ?? 0 ) );
				$budget     = 1;
				$ai_options = self::get_job_ai_request_options( $post_id, 'fields' );

				if ( $total <= 0 || $index >= $total ) {
					return self::finalize_job_field_phases( $post_id, $lang, $state );
				}

				while ( $index < $total && $budget > 0 ) {
					$chunk_result = AI_Client::translate_fields(
						$chunks[ $index ],
						$api_key,
						$api_endpoint,
						$ai_model,
						$lang,
						$ai_options
					);

					if ( is_wp_error( $chunk_result ) ) {
						return $chunk_result;
					}

					$batch = self::collapse_payload_parts( $chunk_result );

					if ( ! empty( $batch ) ) {
						$save_result = self::save_ai_translations(
							$post_id,
							$batch,
							$lang,
							array( 'skip_elementor' => true )
						);

						if ( is_wp_error( $save_result ) ) {
							return $save_result;
						}
					}

					++$index;
					--$budget;
				}

				$state['field_chunk_index'] = $index;

				if ( $index < $total ) {
					self::save_job_partial_state( $post_id, $lang, $state );

					return array(
						'done'           => false,
						'phase'          => 'fields',
						'phase_progress' => $index . '/' . $total,
						'message'        => sprintf(
							/* translators: 1: completed batches, 2: total batches */
							__( 'فیلدهای متنی — بخش %1$d از %2$d', 'polymart-ai' ),
							$index,
							$total
						),
					);
				}

				return self::finalize_job_field_phases( $post_id, $lang, $state );
			}

			if ( in_array( (string) $state['phase'], self::get_job_field_phases(), true ) ) {
				$phase = (string) $state['phase'];

				$batch_result = self::run_job_field_phase_batch(
					$post_id,
					$post,
					$lang,
					$state,
					$phase,
					$api_key,
					$api_endpoint,
					$ai_model
				);

				if ( is_wp_error( $batch_result ) ) {
					return $batch_result;
				}

				if ( ! empty( $batch_result['partial'] ) ) {
					self::save_job_partial_state( $post_id, $lang, $state );

					return array(
						'done'           => false,
						'phase'          => $phase,
						'phase_progress' => (string) ( $batch_result['phase_progress'] ?? '' ),
						'message'        => (string) ( $batch_result['message'] ?? '' ),
					);
				}

				$next_phase = self::get_next_job_field_phase( $phase );

				if ( null === $next_phase ) {
					return self::finalize_job_field_phases( $post_id, $lang, $state );
				}

				while ( null !== $next_phase ) {
					$next_fields = self::collect_persian_fields_for_job_phase( $post, $lang, $next_phase );

					if ( ! empty( $next_fields ) ) {
						$state['phase']             = $next_phase;
						$state['field_chunk_index'] = 0;
						self::save_job_partial_state( $post_id, $lang, $state );

						return array(
							'done'           => false,
							'phase'          => $next_phase,
							'phase_progress' => '0/…',
							'message'        => sprintf(
								/* translators: %s: next phase label */
								__( 'ادامه در %s…', 'polymart-ai' ),
								self::get_job_field_phase_label( $next_phase )
							),
						);
					}

					$next_phase = self::get_next_job_field_phase( $next_phase );
				}

				return self::finalize_job_field_phases( $post_id, $lang, $state );
			}

			if ( 'elementor' === (string) $state['phase'] ) {
				$elementor_slice = self::process_elementor_job_slice(
					$post_id,
					$lang,
					$state,
					$api_key,
					$api_endpoint,
					$ai_model
				);

				if (
					is_wp_error( $elementor_slice )
					&& in_array(
						$elementor_slice->get_error_code(),
						array(
							'polymart_ai_elementor_source_missing',
							'polymart_ai_elementor_source_invalid',
						),
						true
					)
				) {
					delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
					delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );
					self::clear_job_partial_state( $post_id, $lang );
					self::release_translation_lock( $post_id, $lang );

					return array(
						'done'           => true,
						'phase'          => 'complete',
						'phase_progress' => '',
						'message'        => __( 'داده Elementor یافت نشد — این مورد از فیلدهای متنی/محتوا بسنده شد.', 'polymart-ai' ),
					);
				}

				if ( is_wp_error( $elementor_slice ) ) {
					return $elementor_slice;
				}

				if ( empty( $elementor_slice['done'] ) ) {
					return $elementor_slice;
				}

				self::clear_job_partial_state( $post_id, $lang );
				self::release_translation_lock( $post_id, $lang );

				return array(
					'done'           => true,
					'phase'          => 'complete',
					'phase_progress' => '',
					'message'        => __( 'ترجمه Elementor این مورد تکمیل شد.', 'polymart-ai' ),
				);
			}

			self::clear_job_partial_state( $post_id, $lang );
			self::release_translation_lock( $post_id, $lang );

			return array(
				'done'           => true,
				'phase'          => 'complete',
				'phase_progress' => '',
				'message'        => __( 'ترجمه این مورد تکمیل شد.', 'polymart-ai' ),
			);
		} catch ( \Throwable $e ) {
			self::release_translation_lock( $post_id, $lang );

			return new \WP_Error(
				'polymart_ai_job_slice_failed',
				$e->getMessage()
			);
		}
	}

	/**
	 * Translate the next Elementor batch(es) for an auto-translate job slice.
	 *
	 * @param int                  $post_id      Post ID.
	 * @param string               $lang         Language code.
	 * @param array<string, mixed> $state        Partial state (by reference).
	 * @param string               $api_key      API key.
	 * @param string               $api_endpoint API endpoint.
	 * @param string               $ai_model     Model name.
	 * @return array{done: bool, phase: string, phase_progress: string, message: string}|\WP_Error
	 */
	private static function process_elementor_job_slice( $post_id, $lang, array &$state, $api_key, $api_endpoint, $ai_model ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$raw     = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return new \WP_Error(
				'polymart_ai_elementor_source_missing',
				__( 'داده Elementor برای این صفحه یافت نشد.', 'polymart-ai' )
			);
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'polymart_ai_elementor_source_invalid',
				__( 'JSON Elementor نامعتبر است.', 'polymart-ai' )
			);
		}

		$map = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();

		if ( empty( $map ) ) {
			$map = self::rebuild_elementor_map_from_saved_translation( $post_id, $lang, $data );
		}

		$payload = self::filter_remaining_elementor_payload(
			self::collect_elementor_translation_payload( $data ),
			$map,
			is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array()
		);

		if ( empty( $payload ) ) {
			self::save_elementor_source_hash( $post_id, $lang );
			delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );

			return array(
				'done'           => true,
				'phase'          => 'elementor',
				'phase_progress' => '',
				'message'        => __( 'ترجمه Elementor تکمیل شد.', 'polymart-ai' ),
			);
		}

		$source_total = count( self::collect_elementor_translation_payload( $data ) );
		$chunks       = self::chunk_elementor_payload_for_job( $payload );
		$budget       = self::get_job_step_max_elementor_chunks();
		$ai_options   = array( 'max_timeout' => self::ELEMENTOR_JOB_REQUEST_TIMEOUT );
		$processed    = 0;
		$failures     = is_array( $state['elementor_failures'] ?? null ) ? $state['elementor_failures'] : array();

		while ( $processed < $budget && ! empty( $chunks ) ) {
			$chunk = array_shift( $chunks );
			$first_key = (string) array_key_first( $chunk );

			if ( '' !== $first_key && absint( $failures[ $first_key ] ?? 0 ) >= 3 ) {
				$skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
				$skipped[] = $first_key;
				$state['elementor_skipped'] = array_values( array_unique( array_map( 'strval', $skipped ) ) );
				unset( $failures[ $first_key ] );
				++$processed;
				continue;
			}

			list( $aliased_payload, $alias_to_path ) = self::alias_elementor_payload_keys( $chunk );

			$chunk_result = AI_Client::translate_fields(
				$aliased_payload,
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang,
				$ai_options
			);

			if ( is_wp_error( $chunk_result ) ) {
				$recovered = self::translate_job_chunk_with_single_field_fallback(
					$aliased_payload,
					$api_key,
					$api_endpoint,
					$ai_model,
					$lang,
					1,
					$ai_options
				);

				if ( is_wp_error( $recovered ) ) {
					$state['elementor_failures'] = $failures;

					return self::handle_elementor_job_slice_failure(
						$post_id,
						$lang,
						$data,
						$state,
						$map,
						$source_total,
						$recovered,
						array_keys( $chunk )
					);
				}

				$chunk_result = $recovered;
			}

			$mapped_chunk = self::unmap_elementor_aliases(
				self::collapse_payload_parts( $chunk_result ),
				$alias_to_path
			);

			foreach ( $mapped_chunk as $path => $translated ) {
				$translated = trim( (string) $translated );
				$path       = (string) $path;

				if ( '' === $translated || Persian_Detector::contains_persian( $translated ) ) {
					$failures[ $path ] = absint( $failures[ $path ] ?? 0 ) + 1;

					if ( $failures[ $path ] >= 2 ) {
						$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
						$skipped[] = $path;
						$state['elementor_skipped'] = array_values( array_unique( array_map( 'strval', $skipped ) ) );
						unset( $failures[ $path ] );
					}

					unset( $mapped_chunk[ $path ] );
				}
			}

			$map = array_merge( $map, $mapped_chunk );
			++$processed;
		}

		$state['elementor_map']          = $map;
		$state['elementor_chunk_index']  = count( $map );
		$state['elementor_chunks_total'] = max( 1, $source_total );
		$state['elementor_failures']     = $failures;

		$remaining = self::filter_remaining_elementor_payload(
			self::collect_elementor_translation_payload( $data ),
			$map,
			is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array()
		);

		if ( ! empty( $remaining ) ) {
			$persisted = self::persist_elementor_job_progress( $post_id, $lang, $data, $map, count( $map ), max( 1, $source_total ) );

			if ( is_wp_error( $persisted ) ) {
				return $persisted;
			}

			self::save_job_partial_state( $post_id, $lang, $state );

			return array(
				'done'           => false,
				'phase'          => 'elementor',
				'phase_progress' => count( $map ) . '/' . max( 1, $source_total ),
				'message'        => sprintf(
					/* translators: 1: completed widgets, 2: total widgets */
					__( 'Elementor — %1$d از %2$d بخش ذخیره شد', 'polymart-ai' ),
					count( $map ),
					max( 1, $source_total )
				),
			);
		}

		$persisted = self::persist_elementor_job_progress( $post_id, $lang, $data, $map, count( $map ), max( 1, $source_total ), true );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		return array(
			'done'           => true,
			'phase'          => 'elementor',
			'phase_progress' => count( $map ) . '/' . max( 1, $source_total ),
			'message'        => __( 'ترجمه Elementor تکمیل شد.', 'polymart-ai' ),
		);
	}

	/**
	 * Keep Elementor job progress after a recoverable API failure.
	 *
	 * @param int                   $post_id     Post ID.
	 * @param string                $lang        Language code.
	 * @param array<string, mixed>  $source_data Source Elementor tree.
	 * @param array<string, mixed>  $state       Partial state (by reference).
	 * @param array<string, string> $map         Translated path map so far.
	 * @param int                   $source_total Total translatable widgets.
	 * @param \WP_Error             $error       API error.
	 * @param string[]              $failed_keys Optional field keys that failed in this chunk.
	 * @return array{done: bool, phase: string, phase_progress: string, message: string, recoverable: bool}|\WP_Error
	 */
	private static function handle_elementor_job_slice_failure( $post_id, $lang, array $source_data, array &$state, array $map, $source_total, \WP_Error $error, array $failed_keys = array() ) {
		$state['elementor_map']          = $map;
		$state['elementor_chunk_index']  = count( $map );
		$state['elementor_chunks_total'] = max( 1, $source_total );
		$progress                        = count( $map ) . '/' . max( 1, $source_total );
		$failures                        = is_array( $state['elementor_failures'] ?? null ) ? $state['elementor_failures'] : array();

		foreach ( $failed_keys as $failed_key ) {
			$failed_key = (string) $failed_key;

			if ( '' === $failed_key ) {
				continue;
			}

			$failures[ $failed_key ] = absint( $failures[ $failed_key ] ?? 0 ) + 1;
		}

		$state['elementor_failures'] = $failures;

		if ( ! empty( $map ) ) {
			$persisted = self::persist_elementor_job_progress( $post_id, $lang, $source_data, $map, count( $map ), max( 1, $source_total ) );

			if ( ! is_wp_error( $persisted ) ) {
				self::save_job_partial_state( $post_id, $lang, $state );

				return self::make_recoverable_partial_slice_response(
					'elementor',
					$progress,
					sprintf(
						/* translators: 1: saved widget count, 2: error message */
						__( 'Elementor — %1$d بخش ذخیره شد. API موقتاً قطع شد: %2$s', 'polymart-ai' ),
						count( $map ),
						$error->get_error_message()
					)
				);
			}
		}

		if ( self::is_recoverable_job_slice_error( $error ) ) {
			self::save_job_partial_state( $post_id, $lang, $state );

			return self::make_recoverable_partial_slice_response(
				'elementor',
				$progress,
				sprintf(
					/* translators: %s: error message */
					__( 'Elementor — API موقتاً قطع شد (ادامه در مرحله بعد): %s', 'polymart-ai' ),
					$error->get_error_message()
				)
			);
		}

		return $error;
	}

	/**
	 * @param array<string, string> $payload Source Elementor field map.
	 * @param array<string, string> $map     Completed translations keyed by path.
	 * @return array<string, string>
	 */
	private static function filter_remaining_elementor_payload( array $payload, array $map, array $skipped = array() ) {
		$remaining      = array();
		$skipped_lookup = array_flip( array_map( 'strval', $skipped ) );

		foreach ( $payload as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( isset( $skipped_lookup[ $path ] ) ) {
				continue;
			}

			if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
				continue;
			}

			$remaining[ $path ] = $text;
		}

		return $remaining;
	}

	/**
	 * Whether an Elementor field (including oversized multi-part values) is fully translated.
	 *
	 * @param string               $path Field path.
	 * @param string               $text Source text.
	 * @param array<string, string> $map Completed translations keyed by path.
	 * @return bool
	 */
	private static function elementor_field_translation_complete( $path, $text, array $map ) {
		$path = (string) $path;
		$text = (string) $text;

		if ( strlen( $text ) <= self::AI_MAX_SINGLE_FIELD_CHARS ) {
			return isset( $map[ $path ] ) && '' !== trim( (string) $map[ $path ] );
		}

		if ( ! isset( $map[ $path ] ) || '' === trim( (string) $map[ $path ] ) ) {
			return false;
		}

		$translated = (string) $map[ $path ];

		return strlen( $translated ) >= (int) floor( strlen( $text ) * 0.85 );
	}

	/**
	 * Smaller Elementor batches for auto-translate job steps.
	 *
	 * @param array<string, string> $payload Field map.
	 * @return array<int, array<string, string>>
	 */
	private static function chunk_elementor_payload_for_job( array $payload ) {
		return self::chunk_payload_with_limits(
			$payload,
			self::ELEMENTOR_JOB_FIELD_CHUNK_SIZE,
			self::ELEMENTOR_JOB_MAX_CHUNK_CHARS
		);
	}

	/**
	 * Rebuild a path map from partially saved Elementor JSON.
	 *
	 * @param int                  $post_id     Post ID.
	 * @param string               $lang        Language code.
	 * @param array<string, mixed> $source_data Source Elementor tree.
	 * @return array<string, string>
	 */
	private static function rebuild_elementor_map_from_saved_translation( $post_id, $lang, array $source_data ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$raw     = get_post_meta( $post_id, self::get_elementor_meta_key( $lang ), true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$saved = json_decode( $raw, true );

		if ( ! is_array( $saved ) ) {
			return array();
		}

		$source_payload = self::collect_elementor_translation_payload( $source_data );
		$map            = array();

		foreach ( $source_payload as $path => $source_text ) {
			$translated = self::get_elementor_value_at_path( $saved, $path );

			if ( ! is_string( $translated ) || '' === trim( $translated ) ) {
				continue;
			}

			if ( Persian_Detector::contains_persian( $translated ) ) {
				continue;
			}

			$map[ $path ] = $translated;
		}

		return $map;
	}

	/**
	 * @param array<string, mixed> $node Elementor JSON root.
	 * @param string               $path Dotted path (root.0.settings.title).
	 * @return string|null
	 */
	private static function get_elementor_value_at_path( array $node, $path ) {
		$parts = explode( '.', (string) $path );
		array_shift( $parts );

		$current = $node;

		foreach ( $parts as $part ) {
			if ( ! is_array( $current ) ) {
				return null;
			}

			if ( array_key_exists( $part, $current ) ) {
				$current = $current[ $part ];
				continue;
			}

			if ( ctype_digit( (string) $part ) && array_key_exists( (int) $part, $current ) ) {
				$current = $current[ (int) $part ];
				continue;
			}

			return null;
		}

		return is_string( $current ) ? $current : null;
	}

	/**
	 * Persist partial or complete Elementor JSON for a job slice.
	 *
	 * @param int                   $post_id      Post ID.
	 * @param string                $lang         Language code.
	 * @param array<string, mixed>  $source_data  Source tree.
	 * @param array<string, string> $map          Translated path map.
	 * @param int                   $done_count   Completed chunks/widgets.
	 * @param int                   $total_chunks Total chunks/widgets.
	 * @param bool                  $complete     Whether translation finished.
	 * @return true|\WP_Error
	 */
	private static function persist_elementor_job_progress( $post_id, $lang, array $source_data, array $map, $done_count, $total_chunks, $complete = false ) {
		$tree = self::apply_elementor_translation_payload( $source_data, $map );
		$json = wp_json_encode( $tree );

		if ( false === $json || '' === $json ) {
			return new \WP_Error(
				'polymart_ai_elementor_save_failed',
				__( 'ذخیره ترجمه Elementor ناموفق بود.', 'polymart-ai' )
			);
		}

		update_post_meta( $post_id, self::get_elementor_meta_key( $lang ), $json );

		if ( $complete ) {
			self::save_elementor_source_hash( $post_id, $lang );
			delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
			delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );
			self::flush_translation_status_cache( $post_id );

			return true;
		}

		$progress_message = sprintf(
			/* translators: 1: completed batches, 2: total batches */
			__( 'ترجمه Elementor در حال انجام (%1$d/%2$d)', 'polymart-ai' ),
			min( $total_chunks, $done_count ),
			$total_chunks
		);

		update_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ), $progress_message );
		delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
		self::flush_translation_status_cache( $post_id );

		return true;
	}

	/**
	 * Meta key for in-progress Elementor job slices (not a hard failure).
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	private static function get_elementor_progress_meta_key( $lang ) {
		return '_polymart_ai_elementor_progress_' . sanitize_key( (string) $lang );
	}

	/**
	 * Whether an Elementor error meta value is only a legacy progress marker.
	 *
	 * @param string $message Stored meta value.
	 * @return bool
	 */
	private static function is_elementor_progress_message( $message ) {
		$message = trim( (string) $message );

		if ( '' === $message ) {
			return false;
		}

		return false !== strpos( $message, 'ترجمه Elementor در حال انجام' )
			|| false !== strpos( $message, 'Elementor —' );
	}

	/**
	 * Build and store a translated Elementor JSON tree for a language.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return true|\WP_Error
	 */
	public static function persist_elementor_translation( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return true;
		}

		if ( ! self::should_require_elementor_translation( $post_id ) ) {
			return true;
		}

		if ( ! self::has_elementor_persian_content( $post_id ) ) {
			return true;
		}

		if ( self::is_elementor_translation_current( $post_id, $lang ) ) {
			$previous_error = (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true );

			if ( '' === trim( $previous_error ) ) {
				return true;
			}

			delete_post_meta( $post_id, self::get_elementor_meta_key( $lang ) );
			delete_post_meta( $post_id, self::get_elementor_source_hash_meta_key( $lang ) );
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return new \WP_Error(
				'polymart_ai_elementor_source_missing',
				__( 'داده Elementor برای این صفحه یافت نشد.', 'polymart-ai' )
			);
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'polymart_ai_elementor_source_invalid',
				__( 'JSON Elementor نامعتبر است.', 'polymart-ai' )
			);
		}

		$payload = self::collect_elementor_translation_payload( $data );

		if ( empty( $payload ) ) {
			return new \WP_Error(
				'polymart_ai_elementor_empty_payload',
				__( 'متن فارسی قابل ترجمه در Elementor پیدا نشد.', 'polymart-ai' )
			);
		}

		$settings = self::get_translation_settings();

		if ( empty( $settings['api_key'] ) || empty( $settings['api_endpoint'] ) ) {
			return new \WP_Error(
				'polymart_ai_missing_credentials',
				__( 'اعتبارنامه API آروان‌کلاد پیکربندی نشده است.', 'polymart-ai' )
			);
		}

		$api_key      = (string) $settings['api_key'];
		$api_endpoint = rtrim( (string) $settings['api_endpoint'], '/' );
		$ai_model     = AI_Client::resolve_model(
			(string) ( $settings['ai_model'] ?? '' ),
			$api_endpoint
		);

		$translated_map = self::translate_elementor_payload_map(
			$payload,
			$lang,
			$api_key,
			$api_endpoint,
			$ai_model
		);

		if ( is_wp_error( $translated_map ) ) {
			return $translated_map;
		}

		$missing = array();

		foreach ( array_keys( $payload ) as $path ) {
			if ( empty( $translated_map[ $path ] ) || '' === trim( (string) $translated_map[ $path ] ) ) {
				$missing[] = $path;
			}
		}

		$tree = self::apply_elementor_translation_payload( $data, $translated_map );
		$json = wp_json_encode( $tree );

		if ( false === $json || '' === $json ) {
			return new \WP_Error(
				'polymart_ai_elementor_save_failed',
				__( 'ذخیره ترجمه Elementor ناموفق بود.', 'polymart-ai' )
			);
		}

		update_post_meta( $post_id, self::get_elementor_meta_key( $lang ), $json );
		self::save_elementor_source_hash( $post_id, $lang );

		if ( ! empty( $missing ) ) {
			update_post_meta(
				$post_id,
				'_polymart_ai_elementor_error_' . $lang,
				sprintf(
					/* translators: 1: translated count, 2: total count */
					__( 'ترجمه Elementor بخشی ذخیره شد (%1$d از %2$d فیلد).', 'polymart-ai' ),
					count( $payload ) - count( $missing ),
					count( $payload )
				)
			);

			return true;
		}

		delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );

		return true;
	}

	/**
	 * Translate Elementor JSON field map with smaller batches and per-field fallback.
	 *
	 * @param array<string, string> $payload      Path => source text.
	 * @param string                $lang         Target language.
	 * @param string                $api_key      API key.
	 * @param string                $api_endpoint API endpoint.
	 * @param string                $ai_model     Model name.
	 * @return array<string, string>|\WP_Error
	 */
	private static function translate_elementor_payload_map( array $payload, $lang, $api_key, $api_endpoint, $ai_model ) {
		list( $aliased_payload, $alias_to_path ) = self::alias_elementor_payload_keys( $payload );
		$translated_map                          = array();
		$last_error                              = null;
		// Cap single-field fallbacks so one bad page cannot hang the auto-translate job.
		$single_fallback_budget = 8;

		foreach ( self::chunk_elementor_payload_for_ai( $aliased_payload ) as $chunk ) {
			$chunk_result = AI_Client::translate_fields(
				$chunk,
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang
			);

			if ( is_wp_error( $chunk_result ) ) {
				$last_error = $chunk_result;

				foreach ( $chunk as $alias => $text ) {
					if ( $single_fallback_budget <= 0 ) {
						break 2;
					}

					--$single_fallback_budget;

					$single = AI_Client::translate_fields(
						array( $alias => $text ),
						$api_key,
						$api_endpoint,
						$ai_model,
						$lang
					);

					if ( is_wp_error( $single ) ) {
						$last_error = $single;
						continue;
					}

					$translated_map = array_merge( $translated_map, $single );
				}
				continue;
			}

			$translated_map = array_merge( $translated_map, $chunk_result );
		}

		$translated_map = self::unmap_elementor_aliases( $translated_map, $alias_to_path );
		$translated_map = self::collapse_payload_parts( $translated_map );

		foreach ( $payload as $path => $text ) {
			if ( isset( $translated_map[ $path ] ) && '' !== trim( (string) $translated_map[ $path ] ) ) {
				continue;
			}

			if ( $single_fallback_budget <= 0 ) {
				break;
			}

			--$single_fallback_budget;

			$alias  = array_search( $path, $alias_to_path, true );
			$single = AI_Client::translate_fields(
				array(
					false !== $alias ? (string) $alias : $path => $text,
				),
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang
			);

			if ( is_wp_error( $single ) ) {
				$last_error = $single;
				continue;
			}

			if ( false !== $alias && isset( $single[ $alias ] ) ) {
				$translated_map[ $path ] = $single[ $alias ];
			} elseif ( isset( $single[ $path ] ) ) {
				$translated_map[ $path ] = $single[ $path ];
			}
		}

		if ( empty( $translated_map ) ) {
			if ( $last_error instanceof \WP_Error ) {
				return $last_error;
			}

			return new \WP_Error(
				'polymart_ai_elementor_no_translations',
				__( 'ترجمه Elementor ناموفق بود — هیچ فیلدی از AI برنگشت.', 'polymart-ai' )
			);
		}

		return $translated_map;
	}

	/**
	 * Split Elementor path payloads into smaller API batches.
	 *
	 * @param array<string, string> $payload Field map.
	 * @return array<int, array<string, string>>
	 */
	private static function chunk_elementor_payload_for_ai( array $payload ) {
		$chunks        = array();
		$current       = array();
		$current_chars = 0;

		foreach ( $payload as $key => $value ) {
			$value = (string) $value;
			$size  = strlen( (string) $key ) + strlen( $value );

			if (
				! empty( $current )
				&& (
					$current_chars + $size > self::ELEMENTOR_AI_MAX_CHUNK_CHARS
					|| count( $current ) >= self::ELEMENTOR_AI_FIELD_CHUNK_SIZE
				)
			) {
				$chunks[]      = $current;
				$current       = array();
				$current_chars = 0;
			}

			$current[ $key ] = $value;
			$current_chars  += $size;
		}

		if ( ! empty( $current ) ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Replace long Elementor JSON paths with short AI-safe aliases.
	 *
	 * @param array<string, string> $payload Path => source text.
	 * @return array{0: array<string, string>, 1: array<string, string>}
	 */
	private static function alias_elementor_payload_keys( array $payload ) {
		$aliased       = array();
		$alias_to_path = array();
		$index         = 0;

		foreach ( $payload as $path => $text ) {
			$alias = 'el_' . $index;
			++$index;

			$aliased[ $alias ]       = (string) $text;
			$alias_to_path[ $alias ] = (string) $path;
		}

		return array( $aliased, $alias_to_path );
	}

	/**
	 * Map aliased AI response keys back to Elementor JSON paths.
	 *
	 * @param array<string, string> $translated_map Alias => translated text.
	 * @param array<string, string> $alias_to_path  Alias => original path.
	 * @return array<string, string>
	 */
	private static function unmap_elementor_aliases( array $translated_map, array $alias_to_path ) {
		$mapped = array();

		foreach ( $translated_map as $key => $value ) {
			if ( isset( $alias_to_path[ $key ] ) ) {
				$mapped[ $alias_to_path[ $key ] ] = $value;
				continue;
			}

			$mapped[ $key ] = $value;
		}

		return $mapped;
	}

	/**
	 * Collect flat Elementor field payload keyed by stable node paths.
	 *
	 * @param array<string|int, mixed> $node Elementor JSON root.
	 * @return array<string, string>
	 */
	private static function collect_elementor_translation_payload( array $node ) {
		$payload = array();
		self::walk_elementor_translation_payload( $node, $payload, 'root' );

		return $payload;
	}

	/**
	 * @param array<string|int, mixed> $node    Elementor node.
	 * @param array<string, string>  $payload Output payload.
	 * @param string                 $path    Current path.
	 * @return void
	 */
	private static function walk_elementor_translation_payload( array $node, array &$payload, $path ) {
		if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
			self::walk_elementor_settings_payload( $node['settings'], $payload, $path . '.settings' );
		}

		foreach ( $node as $key => $item ) {
			if ( 'settings' === $key ) {
				continue;
			}

			$child_path = $path . '.' . (string) $key;

			if ( is_string( $key ) && isset( self::$elementor_text_keys[ $key ] ) && is_string( $item ) ) {
				$value = Persian_Detector::only_persian_value( $item );

				if ( '' !== $value ) {
					$payload[ $child_path ] = $value;
				}
				continue;
			}

			if ( is_array( $item ) ) {
				self::walk_elementor_translation_payload( $item, $payload, $child_path );
			}
		}
	}

	/**
	 * Collect any Persian widget setting strings from Elementor JSON.
	 *
	 * @param array<string|int, mixed> $settings Settings node.
	 * @param array<string, string>    $payload  Output payload.
	 * @param string                   $path     Current path.
	 * @return void
	 */
	private static function walk_elementor_settings_payload( array $settings, array &$payload, $path ) {
		foreach ( $settings as $key => $item ) {
			$child_path = $path . '.' . (string) $key;

			if ( is_string( $item ) ) {
				if ( self::should_skip_elementor_setting_key( (string) $key, $item ) ) {
					continue;
				}

				$value = Persian_Detector::only_persian_value( $item );

				if ( '' !== $value ) {
					$payload[ $child_path ] = $value;
				}
				continue;
			}

			if ( is_array( $item ) ) {
				self::walk_elementor_settings_payload( $item, $payload, $child_path );
			}
		}
	}

	/**
	 * Whether an Elementor setting key/value pair should be excluded from AI translation.
	 *
	 * @param string $key   Setting key.
	 * @param string $value Setting value.
	 * @return bool
	 */
	private static function should_skip_elementor_setting_key( $key, $value ) {
		$key   = (string) $key;
		$value = trim( (string) $value );

		if ( '' === $value || ! Persian_Detector::contains_persian( $value ) ) {
			return true;
		}

		static $blocked_keys = array(
			'_id'        => true,
			'id'         => true,
			'elType'     => true,
			'widgetType' => true,
			'isInner'    => true,
			'url'        => true,
			'link'       => true,
			'src'        => true,
			'href'      => true,
		);

		if ( isset( $blocked_keys[ $key ] ) ) {
			return true;
		}

		if ( preg_match( '/(_url|_link|_id|_css|_class|_icon|_image|_img|_media|_src|_size|_color|_width|_height|_align|typography|animation|custom_css|z_index|motion_fx|background|overlay|mask)/i', $key ) ) {
			return true;
		}

		// URLs, data-URIs, hex colors, pure numbers/units, SVG/path junk.
		if ( preg_match( '/^(https?:\/\/|\/\/|data:|#|[0-9a-f]{3,8}$|\d+(\.\d+)?(px|em|rem|%|vh|vw)?)$/i', $value ) ) {
			return true;
		}

		if ( preg_match( '/^(data:image\/|data:application\/|blob:)/i', $value ) ) {
			return true;
		}

		// Mostly media/path content with only incidental Persian.
		if ( preg_match( '/\.(jpe?g|png|gif|webp|svg|mp4|webm|pdf)(\?|$)/i', $value ) ) {
			return true;
		}

		// Extremely short non-prose tokens.
		if ( mb_strlen( $value ) <= 2 ) {
			return true;
		}

		return false;
	}

	/**
	 * Apply translated Elementor payload values back onto the JSON tree.
	 *
	 * @param array<string|int, mixed> $node          Elementor node.
	 * @param array<string, string>    $translated_map Path => translated text.
	 * @param string                   $path           Current path.
	 * @return array<string|int, mixed>
	 */
	private static function apply_elementor_translation_payload( array $node, array $translated_map, $path = 'root' ) {
		foreach ( $node as $key => $item ) {
			$child_path = $path . '.' . (string) $key;

			if ( is_string( $item ) && isset( $translated_map[ $child_path ] ) ) {
				$node[ $key ] = $translated_map[ $child_path ];
				continue;
			}

			if ( is_array( $item ) ) {
				$node[ $key ] = self::apply_elementor_translation_payload( $item, $translated_map, $child_path );
			}
		}

		return $node;
	}

	/**
	 * Extract Persian plain text from Elementor JSON for AI translation and status checks.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function collect_elementor_persian_plain_text( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return '';
		}

		if ( array_key_exists( $post_id, self::$elementor_plain_text_cache ) ) {
			return self::$elementor_plain_text_cache[ $post_id ];
		}

		// Never decode multi-megabyte Elementor JSON during public page renders.
		if (
			! is_admin()
			&& ! wp_doing_cron()
			&& ! wp_doing_ajax()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		) {
			self::$elementor_plain_text_cache[ $post_id ] = '';

			return '';
		}

		if ( ! self::uses_elementor_builder( $post_id ) ) {
			self::$elementor_plain_text_cache[ $post_id ] = '';

			return '';
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			self::$elementor_plain_text_cache[ $post_id ] = '';

			return '';
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			self::$elementor_plain_text_cache[ $post_id ] = '';

			return '';
		}

		$payload = self::collect_elementor_translation_payload( $data );

		self::$elementor_plain_text_cache[ $post_id ] = implode(
			"\n\n",
			array_unique( array_filter( array_values( $payload ) ) )
		);

		return self::$elementor_plain_text_cache[ $post_id ];
	}

	/**
	 * Recursively collect Persian strings from Elementor widget settings.
	 *
	 * @param array<string|int, mixed> $node    Elementor JSON node.
	 * @param string[]                 $chunks  Collected text fragments.
	 * @return void
	 */
	private static function walk_elementor_persian_text( array $node, array &$chunks ) {
		foreach ( $node as $key => $item ) {
			if ( is_string( $key ) && isset( self::$elementor_text_keys[ $key ] ) && is_string( $item ) ) {
				$value = Persian_Detector::only_persian_value( $item );

				if ( '' !== $value ) {
					$chunks[] = $value;
				}
				continue;
			}

			if ( is_array( $item ) ) {
				self::walk_elementor_persian_text( $item, $chunks );
			}
		}
	}

	/**
	 * Whether a post/product has Persian source content worth translating.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function has_persian_content( $post_id ) {
		$post = get_post( absint( $post_id ) );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return self::post_has_persian_content( $post );
	}

	/**
	 * Whether a post object has Persian source content worth translating.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	public static function post_has_persian_content( \WP_Post $post ) {
		if ( ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return false;
		}

		if ( 'woodmart_slide' === $post->post_type && get_post_thumbnail_id( $post->ID ) ) {
			return true;
		}

		if ( ! empty( self::collect_persian_fields( $post ) ) ) {
			return true;
		}

		if ( self::uses_elementor_builder( $post->ID ) ) {
			if ( '' !== self::collect_elementor_persian_plain_text( $post->ID ) ) {
				return true;
			}

			if ( '' !== self::extract_elementor_html_persian_excerpt( $post->post_content ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract Persian text from Elementor's rendered HTML cache in post_content.
	 *
	 * @param string $html Raw post_content HTML.
	 * @return string
	 */
	public static function extract_elementor_html_persian_excerpt( $html ) {
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return '';
		}

		$plain = wp_strip_all_tags( $html );
		$plain = html_entity_decode( $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$plain = trim( preg_replace( '/\s+/u', ' ', $plain ) );

		if ( '' === $plain ) {
			return '';
		}

		$persian = Persian_Detector::only_persian_value( $plain );

		if ( '' !== $persian ) {
			return $persian;
		}

		if ( Persian_Detector::contains_persian( $plain ) ) {
			return function_exists( 'mb_substr' ) ? mb_substr( $plain, 0, 4000, 'UTF-8' ) : substr( $plain, 0, 4000 );
		}

		return '';
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
	 * Validate a post, call the AI API, and return translations.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return array{post: \WP_Post, translations: array<string, string>}|\\WP_Error
	 */
	public static function request_ai_translation( $post_id, $lang = 'en' ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || ! self::can_translate_post( $post_id ) ) {
			return new \WP_Error(
				'polymart_ai_forbidden',
				__( 'شما اجازه ترجمه این مورد را ندارید.', 'polymart-ai' )
			);
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return new \WP_Error(
				'polymart_ai_invalid_post',
				__( 'نوع مطلب برای ترجمه نامعتبر است.', 'polymart-ai' )
			);
		}

		$settings = self::get_translation_settings();

		if ( empty( $settings['api_key'] ) || empty( $settings['api_endpoint'] ) ) {
			return new \WP_Error(
				'polymart_ai_missing_credentials',
				__( 'اعتبارنامه API آروان‌کلاد پیکربندی نشده است.', 'polymart-ai' )
			);
		}

		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			$lang = 'en';
		}

		$payload      = self::collect_persian_fields( $post, $lang );
		$is_elementor = self::uses_elementor_builder( $post_id );

		if ( empty( $payload ) && ! $is_elementor ) {
			return new \WP_Error(
				'polymart_ai_empty_source',
				__( 'محتوای فارسی برای ترجمه یافت نشد.', 'polymart-ai' )
			);
		}

		if ( empty( $payload ) && '' === self::collect_elementor_persian_plain_text( $post_id ) ) {
			return new \WP_Error(
				'polymart_ai_empty_source',
				__( 'محتوای فارسی برای ترجمه یافت نشد.', 'polymart-ai' )
			);
		}

		if ( ! self::acquire_translation_lock( $post_id, $lang ) ) {
			return new \WP_Error(
				'polymart_ai_translation_in_progress',
				__( 'این مورد در حال ترجمه است. لطفاً چند لحظه صبر کنید.', 'polymart-ai' )
			);
		}

		try {
			if ( function_exists( 'set_time_limit' ) ) {
				$chunk_count = empty( $payload ) ? 0 : count( self::chunk_payload_for_ai( $payload ) );

				@set_time_limit( min( 1200, 90 + ( $chunk_count + ( $is_elementor ? 3 : 0 ) ) * 45 ) );
			}

			if ( ! empty( $payload ) ) {
				/**
				 * Fires before an AI translation request is sent.
				 *
				 * @param int                  $post_id Post ID.
				 * @param \WP_Post             $post    Post object.
				 * @param array<string,string> $payload Persian source fields.
				 * @param string               $lang    Target language code.
				 */
				do_action( 'polymart_ai_before_translate_post', $post_id, $post, $payload, $lang );
			}

			$api_key      = (string) $settings['api_key'];
			$api_endpoint = rtrim( (string) $settings['api_endpoint'], '/' );
			$ai_model     = AI_Client::resolve_model(
				(string) ( $settings['ai_model'] ?? '' ),
				$api_endpoint
			);

			$translated = array();

			if ( ! empty( $payload ) ) {
				foreach ( self::chunk_payload_for_ai( $payload ) as $chunk ) {
					$chunk_result = AI_Client::translate_fields(
						$chunk,
						$api_key,
						$api_endpoint,
						$ai_model,
						$lang
					);

					if ( is_wp_error( $chunk_result ) ) {
						return $chunk_result;
					}

					$translated = array_merge( $translated, $chunk_result );
				}

				$translated = self::collapse_payload_parts( $translated );
			}

			return array(
				'post'         => $post,
				'translations' => $translated,
			);
		} finally {
			self::release_translation_lock( $post_id, $lang );
		}
	}

	/**
	 * Acquire a per-post lock so duplicate AI requests are rejected.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return bool
	 */
	public static function acquire_translation_lock( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$key   = self::TRANSLATION_LOCK_PREFIX . $post_id . '_' . $lang;
		$claim = $key . '_claim';
		$now   = time();

		$existing_claim = absint( get_option( $claim, 0 ) );
		$existing_lock  = get_transient( $key );
		$stamp          = max( $existing_claim, $existing_lock ? absint( $existing_lock ) : 0 );

		if ( $stamp > 0 && ( $now - $stamp ) < self::TRANSLATION_LOCK_TTL ) {
			if ( ! $existing_lock && $existing_claim > 0 ) {
				set_transient( $key, $existing_claim, self::TRANSLATION_LOCK_TTL );
			}

			return false;
		}

		if ( $stamp > 0 ) {
			delete_option( $claim );
			delete_transient( $key );
		}

		if ( ! add_option( $claim, (string) $now, '', 'no' ) ) {
			return false;
		}

		set_transient( $key, $now, self::TRANSLATION_LOCK_TTL );

		return true;
	}

	/**
	 * Release a per-post translation lock.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return void
	 */
	public static function release_translation_lock( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return;
		}

		$key = self::TRANSLATION_LOCK_PREFIX . $post_id . '_' . $lang;
		delete_transient( $key );
		delete_option( $key . '_claim' );
	}

	/**
	 * Map AI response keys to wp_postmeta field names for the meta box UI.
	 *
	 * @param array<string, string> $translations AI response keyed by source field name.
	 * @param string                $lang         Target language code.
	 * @return array<string, string>
	 */
	public static function map_ai_response_to_meta_fields( array $translations, $lang = 'en' ) {
		$lang = sanitize_key( (string) $lang );

		$core_map = array(
			'post_title'   => self::get_meta_key( 'title', $lang ),
			'post_content' => self::get_meta_key( 'content', $lang ),
			'post_excerpt' => self::get_meta_key( 'excerpt', $lang ),
		);

		$meta_fields = array();

		foreach ( $translations as $source_key => $value ) {
			if ( self::is_term_payload_key( $source_key ) || self::is_variation_title_payload_key( $source_key ) ) {
				continue;
			}

			if ( isset( $core_map[ $source_key ] ) ) {
				$meta_fields[ $core_map[ $source_key ] ] = $value;
				continue;
			}

			if ( self::is_custom_meta_key( $source_key ) ) {
				$meta_fields[ self::get_custom_meta_key( $source_key, $lang ) ] = $value;
			}
		}

		return $meta_fields;
	}

	/**
	 * Persist AI-generated translations to post meta (optional direct save).
	 *
	 * @param int                   $post_id      Post ID.
	 * @param array<string, string> $translations AI response keyed by source field name.
	 * @param string                $lang         Target language code.
	 * @return true|\WP_Error
	 */
	public static function save_ai_translations( $post_id, array $translations, $lang = 'en', array $options = array() ) {
		$lang = sanitize_key( (string) $lang );

		self::begin_persisting_translations();

		try {
			return self::save_ai_translations_internal( $post_id, $translations, $lang, $options );
		} finally {
			self::end_persisting_translations();
		}
	}

	/**
	 * Internal AI translation persistence (called within the persistence guard).
	 *
	 * @param int                   $post_id      Post ID.
	 * @param array<string, string> $translations AI response keyed by source field name.
	 * @param string                $lang         Target language code.
	 * @param array<string, bool>   $options      Optional flags (skip_elementor).
	 * @return true|\WP_Error
	 */
	private static function save_ai_translations_internal( $post_id, array $translations, $lang = 'en', array $options = array() ) {
		$lang = sanitize_key( (string) $lang );

		foreach ( $translations as $source_key => $value ) {
			if ( is_string( $value ) || is_numeric( $value ) ) {
				$translations[ $source_key ] = self::normalize_ai_translation_value( $value );
			}
		}

		$meta_fields     = self::map_ai_response_to_meta_fields( $translations, $lang );
		$content_key     = self::get_meta_key( 'content', $lang );
		$excerpt_key     = self::get_meta_key( 'excerpt', $lang );
		$title_key       = self::get_meta_key( 'title', $lang );
		$ai_analysis_key = self::get_custom_meta_key( '_apd_ai_analysis', $lang );
		$prepared_core   = array();

		foreach ( $meta_fields as $meta_key => $value ) {
			if ( $content_key === $meta_key ) {
				$stored = self::prepare_stored_meta_value( $value, 'content' );
			} elseif ( $excerpt_key === $meta_key ) {
				$stored = self::prepare_stored_meta_value( $value, 'excerpt' );
			} elseif ( $title_key === $meta_key ) {
				$stored = self::prepare_stored_meta_value( $value, 'title' );
			} elseif ( $ai_analysis_key === $meta_key ) {
				$stored = self::prepare_stored_meta_value( $value, 'excerpt' );
			} else {
				$stored = self::prepare_stored_meta_value( $value, 'default' );
			}

			update_post_meta( $post_id, $meta_key, $stored );

			if ( $title_key === $meta_key ) {
				$prepared_core['post_title'] = $stored;
			} elseif ( $excerpt_key === $meta_key ) {
				$prepared_core['post_excerpt'] = $stored;
			} elseif ( $content_key === $meta_key ) {
				$prepared_core['post_content'] = $stored;
			}
		}

		self::save_term_translations( $translations, $lang );
		self::save_variation_title_translations( $translations, $lang );
		self::persist_attribute_runtime_cache( $post_id, $translations, $lang );

		$core_sources = array(
			'post_title'   => 'title',
			'post_excerpt' => 'excerpt',
			'post_content' => 'content',
		);
		$core_error   = null;

		foreach ( $core_sources as $source_key => $field ) {
			if ( ! isset( $translations[ $source_key ] ) || ! is_string( $translations[ $source_key ] ) ) {
				continue;
			}

			if ( '' === trim( $translations[ $source_key ] ) ) {
				continue;
			}

			$stored = isset( $prepared_core[ $source_key ] )
				? $prepared_core[ $source_key ]
				: get_post_meta( $post_id, self::get_meta_key( $field, $lang ), true );

			if ( ! self::has_meaningful_translation( $stored ) ) {
				$core_error = new \WP_Error(
					'polymart_ai_core_meta_not_saved',
					sprintf(
						/* translators: %s: source field key */
						__( 'ترجمه «%s» پس از ذخیره در meta دیده نشد.', 'polymart-ai' ),
						$source_key
					),
					array(
						'post_id'    => $post_id,
						'lang'       => $lang,
						'source_key' => $source_key,
					)
				);
				break;
			}
		}

		if ( $core_error instanceof \WP_Error ) {
			self::flush_translation_status_cache( $post_id );
			self::sync_translation_index_meta( $post_id, $lang );
			REST_API::invalidate_stats_cache();

			return $core_error;
		}

		$post = get_post( $post_id );

		if ( $post instanceof \WP_Post ) {
			self::persist_field_source_hashes( $post_id, $translations, $lang, $post );

			if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
				self::refresh_product_variation_titles_fingerprint( $post_id );
			}

			self::persist_cms_block_runtime_cache( $post_id, $lang, $post );
		}

		if ( empty( $options['skip_elementor'] ) && self::should_require_elementor_translation( $post_id ) ) {
			$elementor_result = self::persist_elementor_translation( $post_id, $lang );

			if ( is_wp_error( $elementor_result ) ) {
				update_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, $elementor_result->get_error_message() );
			} else {
				delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
			}
		} else {
			delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
		}

		self::flush_translation_status_cache( $post_id );

		if ( 'translated' === self::get_translation_status( $post_id, $lang ) ) {
			update_post_meta( $post_id, '_polymart_ai_translated_at_' . $lang, time() );
			update_post_meta( $post_id, '_polymart_ai_translated_at', time() );
		} else {
			delete_post_meta( $post_id, '_polymart_ai_translated_at_' . $lang );
		}

		/**
		 * Fires after AI translations have been saved to post meta.
		 *
		 * @param int                   $post_id      Post ID.
		 * @param array<string, string> $translations Saved translations keyed by source field.
		 */
		do_action( 'polymart_ai_after_save_translations', $post_id, $translations );

		self::sync_translation_index_meta( $post_id, $lang );
		REST_API::invalidate_stats_cache();

		return true;
	}

	/**
	 * Collect assigned terms that can appear on the frontend.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Term[]
	 */
	private static function collect_translatable_terms( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return array();
		}

		$terms = array();

		foreach ( self::get_translatable_taxonomies() as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$assigned = get_the_terms( $post_id, $taxonomy );

			if ( is_wp_error( $assigned ) || empty( $assigned ) ) {
				if ( 'product' === get_post_type( $post_id ) && function_exists( 'wc_get_product_terms' ) && 0 === strpos( $taxonomy, 'pa_' ) ) {
					$assigned = wc_get_product_terms( $post_id, $taxonomy, array( 'fields' => 'all' ) );
				}
			}

			if ( is_wp_error( $assigned ) || empty( $assigned ) ) {
				continue;
			}

			foreach ( $assigned as $term ) {
				if ( $term instanceof \WP_Term ) {
					$terms[ $term->term_id ] = $term;
				}
			}
		}

		if ( 'product' === get_post_type( $post_id ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );

			if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$variation = wc_get_product( $child_id );

					if ( ! $variation instanceof \WC_Product_Variation ) {
						continue;
					}

					foreach ( $variation->get_attributes() as $taxonomy => $slug ) {
						if ( ! is_string( $taxonomy ) || ! taxonomy_exists( $taxonomy ) || ! is_string( $slug ) || '' === $slug ) {
							continue;
						}

						$term = get_term_by( 'slug', $slug, $taxonomy );

						if ( $term instanceof \WP_Term ) {
							$terms[ $term->term_id ] = $term;
						}
					}
				}
			}
		}

		return array_values( $terms );
	}

	/**
	 * Collect WooCommerce attribute labels and custom option values for AI translation.
	 *
	 * Global attribute terms are handled via collect_translatable_terms(); this
	 * covers custom text attributes and explicit attribute labels on products.
	 *
	 * @param \WP_Post $post Post object.
	 * @param string   $lang Target language code.
	 * @return array<string, string>
	 */
	private static function collect_product_attribute_fields( \WP_Post $post, $lang ) {
		$lang = sanitize_key( (string) $lang );

		if ( 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$product = wc_get_product( $post->ID );

		if ( ! $product instanceof \WC_Product ) {
			return array();
		}

		$fields = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}

			$attr_name = $attribute->get_name();
			$label     = function_exists( 'wc_attribute_label' )
				? (string) wc_attribute_label( $attr_name, $product )
				: (string) $attr_name;
			$label     = Persian_Detector::only_persian_value( $label );

			if ( '' !== $label ) {
				$fields[ 'product_attr_label:' . self::attribute_field_group( (string) $attr_name ) ] = $label;
			}

			if ( $attribute->is_taxonomy() ) {
				continue;
			}

			foreach ( $attribute->get_options() as $option ) {
				$value = Persian_Detector::only_persian_value( (string) $option );

				if ( '' === $value ) {
					continue;
				}

				$fields[ 'product_attr_value:' . self::attribute_field_group( (string) $attr_name ) . ':' . md5( $value ) ] = $value;
			}
		}

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );

				if ( ! $variation instanceof \WC_Product_Variation ) {
					continue;
				}

				foreach ( $variation->get_attributes() as $attr_name => $attr_value ) {
					if ( ! is_string( $attr_name ) || taxonomy_exists( $attr_name ) ) {
						continue;
					}

					$value = Persian_Detector::only_persian_value( (string) $attr_value );

					if ( '' === $value ) {
						continue;
					}

					$fields[ 'product_attr_value:' . self::attribute_field_group( $attr_name ) . ':' . md5( $value ) ] = $value;
				}
			}
		}

		if ( '' === $lang ) {
			return $fields;
		}

		$pending = array();

		foreach ( $fields as $key => $source ) {
			$context = self::attribute_runtime_context_for_key( $key );

			if ( '' === $context ) {
				continue;
			}

			if ( self::has_product_attribute_translation( $post->ID, $source, $lang, $context ) ) {
				continue;
			}

			// Eastern digits only (e.g. «۴») — map to Western digits without an API call.
			if ( Persian_Detector::is_eastern_digit_string( $source ) ) {
				$western = Persian_Detector::westernize_digits( $source );

				if ( '' !== $western && Persian_Detector::is_acceptable_translation_for_language( $western, $lang ) ) {
					self::store_product_attribute_translation( $post->ID, $source, $lang, $context, $western );
					continue;
				}
			}

			$pending[ $key ] = $source;
		}

		return $pending;
	}

	/**
	 * Persist one durable product attribute translation.
	 *
	 * @param int    $post_id    Product ID.
	 * @param string $source     Persian source text.
	 * @param string $lang       Target language code.
	 * @param string $context    Runtime context.
	 * @param string $translated Translated text.
	 * @return void
	 */
	private static function store_product_attribute_translation( $post_id, $source, $lang, $context, $translated ) {
		$post_id    = absint( $post_id );
		$source     = is_string( $source ) ? trim( $source ) : '';
		$lang       = sanitize_key( (string) $lang );
		$context    = (string) $context;
		$translated = is_string( $translated ) ? trim( $translated ) : '';

		if ( $post_id <= 0 || '' === $source || '' === $lang || '' === $context || '' === $translated ) {
			return;
		}

		Runtime_String_Translator::store_translation( $source, $lang, $context, $translated );

		$meta_key = self::get_attribute_translations_meta_key( $lang );
		$durable  = get_post_meta( $post_id, $meta_key, true );
		$durable  = is_array( $durable ) ? $durable : array();

		$durable[ self::attribute_translation_map_key( $source, $context ) ] = $translated;

		update_post_meta( $post_id, $meta_key, $durable );
		update_post_meta(
			$post_id,
			'_polymart_ai_attr_cache_snapshot',
			self::get_product_attribute_runtime_cache_entries( $post_id )
		);
	}

	/**
	 * Collect Persian custom titles from variable-product variations.
	 *
	 * Variation posts are not in the auto-translate post-type scan, so their
	 * post_title values are bundled with the parent product translation job.
	 *
	 * @param \WP_Post $post Post object.
	 * @param string   $lang Target language code.
	 * @return array<string, string>
	 */
	private static function collect_variation_title_fields( \WP_Post $post, $lang ) {
		$lang = sanitize_key( (string) $lang );

		if ( 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$product = wc_get_product( $post->ID );

		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			return array();
		}

		$fields = array();

		foreach ( $product->get_children() as $variation_id ) {
			$variation_id = absint( $variation_id );

			if ( $variation_id <= 0 ) {
				continue;
			}

			$custom_title = Persian_Detector::only_persian_value( self::get_variation_custom_title_meta_raw( $variation_id ) );

			if ( '' !== $custom_title ) {
				if ( '' === $lang || ! self::is_field_translation_current( $variation_id, self::WVE_VARIATION_CUSTOM_TITLE_META, $lang, $custom_title ) ) {
					$fields[ self::build_variation_custom_title_payload_key( $variation_id ) ] = $custom_title;
				}
			} else {
				$title = Persian_Detector::only_persian_value( (string) get_post_field( 'post_title', $variation_id ) );

				if ( '' !== $title && ( '' === $lang || ! self::is_field_translation_current( $variation_id, 'post_title', $lang, $title ) ) ) {
					$fields[ self::build_variation_title_payload_key( $variation_id ) ] = $title;
				}
			}

			$description = Persian_Detector::only_persian_value( self::get_variation_custom_description( $variation_id ) );

			if ( '' === $description ) {
				continue;
			}

			if ( '' !== $lang && self::is_field_translation_current( $variation_id, self::WVE_VARIATION_CUSTOM_DESCRIPTION_META, $lang, $description ) ) {
				continue;
			}

			$fields[ self::build_variation_custom_description_payload_key( $variation_id ) ] = $description;
		}

		return $fields;
	}

	/**
	 * Runtime cache context for a product attribute payload key.
	 *
	 * @param string $key Payload key from collect_product_attribute_fields().
	 * @return string
	 */
	private static function attribute_runtime_context_for_key( $key ) {
		if ( ! is_string( $key ) ) {
			return '';
		}

		if ( 0 === strpos( $key, 'product_attr_value:' ) ) {
			$group = substr( $key, strlen( 'product_attr_value:' ) );
			$group = preg_replace( '/:[a-f0-9]{32}$/', '', (string) $group );

			return 'wc_attr_opt:' . sanitize_key( (string) $group );
		}

		if ( 0 === strpos( $key, 'product_attr_label:' ) ) {
			$group = substr( $key, strlen( 'product_attr_label:' ) );

			return 'wc_attr_label:' . sanitize_key( (string) $group );
		}

		return '';
	}

	/**
	 * Normalize a WooCommerce attribute slug/name for stable storage keys.
	 *
	 * @param string $attr_name Attribute slug or label.
	 * @return string
	 */
	private static function attribute_field_group( $attr_name ) {
		$name = sanitize_title( str_replace( 'pa_', '', (string) $attr_name ) );

		return '' !== $name ? $name : 'custom';
	}

	/**
	 * Seed runtime cache for cms_block HTML used in Woodmart header/footer shortcodes.
	 *
	 * @param int      $post_id Post ID.
	 * @param string   $lang    Target language code.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	private static function persist_cms_block_runtime_cache( $post_id, $lang, \WP_Post $post ) {
		if ( 'cms_block' !== $post->post_type ) {
			return;
		}

		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			return;
		}

		$source = trim( (string) $post->post_content );

		if ( '' === $source || ! Persian_Detector::contains_persian( $source ) ) {
			return;
		}

		$translated = get_post_meta( $post_id, self::get_meta_key( 'content', $lang ), true );

		if ( ! is_string( $translated ) || '' === trim( $translated ) ) {
			return;
		}

		Runtime_String_Translator::store_translation(
			$source,
			$lang,
			'cms_block:' . $post_id,
			$translated
		);

		$hash_key = 'html_block:' . md5( wp_strip_all_tags( $source ) );
		$strings  = get_option( 'polymart_ai_theme_strings', array() );

		if ( ! is_array( $strings ) ) {
			$strings = array();
		}

		$strings[ $hash_key ] = $translated;
		update_option( 'polymart_ai_theme_strings', $strings, false );
	}

	/**
	 * Seed runtime cache for attribute labels/values returned by bulk AI translation.
	 *
	 * @param int                   $post_id      Product ID.
	 * @param array<string, string> $translations AI response keyed like collect_persian_fields().
	 * @param string                $lang         Target language code.
	 * @return void
	 */
	private static function persist_attribute_runtime_cache( $post_id, array $translations, $lang ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( ! $post instanceof \WP_Post || '' === $lang ) {
			return;
		}

		// Use unfiltered sources so we persist every attribute key returned by AI,
		// not only fields still pending after other meta was saved in this request.
		$sources = self::collect_product_attribute_fields( $post, '' );
		$meta_key = self::get_attribute_translations_meta_key( $lang );
		$durable  = get_post_meta( $post_id, $meta_key, true );
		$durable  = is_array( $durable ) ? $durable : array();

		foreach ( $sources as $key => $source ) {
			if ( ! is_string( $key ) || ! is_string( $source ) || ! isset( $translations[ $key ] ) ) {
				continue;
			}

			$translated = trim( (string) $translations[ $key ] );
			$context    = self::attribute_runtime_context_for_key( $key );

			if ( '' === $translated || '' === $context ) {
				continue;
			}

			Runtime_String_Translator::store_translation( $source, $lang, $context, $translated );
			$durable[ self::attribute_translation_map_key( $source, $context ) ] = $translated;
		}

		// Persist immediately so the next job step / live stats see durable data.
		Runtime_String_Translator::flush_pending_cache();

		update_post_meta( $post_id, $meta_key, $durable );
		update_post_meta(
			$post_id,
			'_polymart_ai_attr_cache_snapshot',
			self::get_product_attribute_runtime_cache_entries( $post_id )
		);
	}

	/**
	 * Post meta key for durable product attribute translations.
	 *
	 * @param string $lang Target language code.
	 * @return string
	 */
	private static function get_attribute_translations_meta_key( $lang ) {
		return '_polymart_ai_attr_i18n_' . sanitize_key( (string) $lang );
	}

	/**
	 * Map key for one attribute source/context pair.
	 *
	 * @param string $source  Persian source text.
	 * @param string $context Runtime context.
	 * @return string
	 */
	private static function attribute_translation_map_key( $source, $context ) {
		return md5( (string) $context . "\0" . (string) $source );
	}

	/**
	 * Read a durable product attribute translation saved during auto-translate.
	 *
	 * @param int    $post_id Product ID.
	 * @param string $source  Persian source text.
	 * @param string $lang    Target language code.
	 * @param string $context Runtime context (wc_attr_opt:* / wc_attr_label:*).
	 * @return string
	 */
	public static function get_stored_product_attribute_translation( $post_id, $source, $lang, $context ) {
		$source  = is_string( $source ) ? trim( $source ) : '';
		$lang    = sanitize_key( (string) $lang );
		$context = (string) $context;
		$post_id = absint( $post_id );

		if ( '' === $source || '' === $lang || '' === $context || $post_id <= 0 ) {
			return '';
		}

		$cached = Runtime_String_Translator::lookup_cached( $source, $lang, $context );

		if ( is_string( $cached ) && '' !== trim( $cached ) ) {
			return $cached;
		}

		$durable = get_post_meta( $post_id, self::get_attribute_translations_meta_key( $lang ), true );

		if ( ! is_array( $durable ) ) {
			return '';
		}

		$map_key    = self::attribute_translation_map_key( $source, $context );
		$translated = isset( $durable[ $map_key ] ) ? trim( (string) $durable[ $map_key ] ) : '';

		if ( '' === $translated ) {
			return '';
		}

		Runtime_String_Translator::store_translation( $source, $lang, $context, $translated );

		return $translated;
	}

	/**
	 * Whether a product attribute string is translated for a language.
	 *
	 * Checks the runtime cache first, then durable per-product post meta (REST jobs
	 * previously only warmed request memory and re-queued the same product).
	 *
	 * @param int    $post_id Product ID.
	 * @param string $source  Persian source text.
	 * @param string $lang    Target language code.
	 * @param string $context Runtime context.
	 * @return bool
	 */
	public static function has_product_attribute_translation( $post_id, $source, $lang, $context ) {
		$source  = is_string( $source ) ? trim( $source ) : '';
		$lang    = sanitize_key( (string) $lang );
		$context = (string) $context;
		$post_id = absint( $post_id );

		if ( '' === $source || '' === $lang || '' === $context ) {
			return false;
		}

		if ( $post_id <= 0 ) {
			return false;
		}

		$durable = get_post_meta( $post_id, self::get_attribute_translations_meta_key( $lang ), true );

		if ( ! is_array( $durable ) ) {
			return false;
		}

		$map_key    = self::attribute_translation_map_key( $source, $context );
		$translated = isset( $durable[ $map_key ] ) ? trim( (string) $durable[ $map_key ] ) : '';

		if ( '' === $translated || ! self::is_clean_target_language_translation( $translated, $lang ) ) {
			return false;
		}

		// Re-hydrate the runtime cache for storefront lookups.
		Runtime_String_Translator::store_translation( $source, $lang, $context, $translated );

		return true;
	}

	/**
	 * Build an AI payload key for taxonomy terms.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $field   name|desc.
	 * @return string
	 */
	private static function build_term_payload_key( $term_id, $field ) {
		return 'term:' . absint( $term_id ) . ':' . sanitize_key( (string) $field );
	}

	/**
	 * Whether a response key targets translated term meta.
	 *
	 * @param string $key AI response key.
	 * @return bool
	 */
	private static function is_term_payload_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^term:\d+:(name|desc)$/', $key );
	}

	/**
	 * Build an AI payload key for a variation custom title.
	 *
	 * @param int $variation_id Variation post ID.
	 * @return string
	 */
	private static function build_variation_title_payload_key( $variation_id ) {
		return 'variation_title:' . absint( $variation_id );
	}

	/**
	 * Build an AI payload key for a WVE custom variation title.
	 *
	 * @param int $variation_id Variation post ID.
	 * @return string
	 */
	private static function build_variation_custom_title_payload_key( $variation_id ) {
		return 'variation_custom_title:' . absint( $variation_id );
	}

	/**
	 * Build an AI payload key for a WVE custom variation description.
	 *
	 * @param int $variation_id Variation post ID.
	 * @return string
	 */
	private static function build_variation_custom_description_payload_key( $variation_id ) {
		return 'variation_custom_description:' . absint( $variation_id );
	}

	/**
	 * Whether a response key targets a variation custom field.
	 *
	 * @param string $key AI response key.
	 * @return bool
	 */
	private static function is_variation_title_payload_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^variation_(custom_)?(title|description):\d+$/', $key );
	}

	/**
	 * Persist translated variation titles from an AI response.
	 *
	 * @param array<string, string> $translations AI response keyed by source field.
	 * @param string                $lang         Target language code.
	 * @return void
	 */
	private static function save_variation_title_translations( array $translations, $lang ) {
		$lang              = sanitize_key( (string) $lang );
		$parents_refreshed = array();

		foreach ( $translations as $source_key => $value ) {
			if ( ! is_string( $source_key ) || ! is_string( $value ) ) {
				continue;
			}

			$field       = '';
			$source_hash = '';
			$meta_field  = 'title';
			$variation_id = 0;

			if ( 1 === preg_match( '/^variation_custom_title:(\d+)$/', $source_key, $matches ) ) {
				$variation_id = absint( $matches[1] );
				$field        = self::WVE_VARIATION_CUSTOM_TITLE_META;
				$source_hash  = self::get_variation_custom_title( $variation_id );
				$meta_field   = 'title';
			} elseif ( 1 === preg_match( '/^variation_custom_description:(\d+)$/', $source_key, $matches ) ) {
				$variation_id = absint( $matches[1] );
				$field        = self::WVE_VARIATION_CUSTOM_DESCRIPTION_META;
				$source_hash  = self::get_variation_custom_description( $variation_id );
				$meta_field   = 'excerpt';
			} elseif ( 1 === preg_match( '/^variation_title:(\d+)$/', $source_key, $matches ) ) {
				$variation_id = absint( $matches[1] );
				$field        = 'post_title';
				$variation    = get_post( $variation_id );
				$source_hash  = $variation instanceof \WP_Post ? $variation->post_title : '';
				$meta_field   = 'title';
			} else {
				continue;
			}

			$variation = get_post( $variation_id );

			if ( $variation_id <= 0 || ! ( $variation instanceof \WP_Post ) || 'product_variation' !== $variation->post_type ) {
				continue;
			}

			$clean = 'excerpt' === $meta_field ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );

			if ( '' === trim( $clean ) ) {
				continue;
			}

			update_post_meta( $variation_id, self::get_meta_key( $meta_field, $lang ), $clean );

			if ( in_array( $field, array( self::WVE_VARIATION_CUSTOM_TITLE_META, self::WVE_VARIATION_CUSTOM_DESCRIPTION_META ), true ) ) {
				update_post_meta( $variation_id, self::get_custom_meta_key( $field, $lang ), $clean );
			}

			self::store_field_source_hash( $variation_id, $field, $lang, (string) $source_hash );
			self::flush_translation_status_cache( $variation_id );

			$parent_id = wp_get_post_parent_id( $variation_id );

			if ( $parent_id > 0 ) {
				$parents_refreshed[ $parent_id ] = true;
			}
		}

		foreach ( array_keys( $parents_refreshed ) as $parent_id ) {
			self::refresh_product_variation_titles_fingerprint( absint( $parent_id ) );
		}
	}

	/**
	 * Persist translated taxonomy term fields from AI response.
	 *
	 * @param array<string, string> $translations AI response keyed by source field.
	 * @param string                $lang         Target language code.
	 * @return void
	 */
	private static function save_term_translations( array $translations, $lang ) {
		$lang = sanitize_key( (string) $lang );

		foreach ( $translations as $source_key => $value ) {
			if ( ! is_string( $source_key ) || ! is_string( $value ) ) {
				continue;
			}

			if ( 1 !== preg_match( '/^term:(\d+):(name|desc)$/', $source_key, $matches ) ) {
				continue;
			}

			$term_id = absint( $matches[1] );
			$field   = 'desc' === $matches[2] ? 'desc' : 'name';
			$term    = get_term( $term_id );

			if ( $term_id <= 0 || ! ( $term instanceof \WP_Term ) ) {
				continue;
			}

			$meta_key = self::get_term_meta_key( $field, $lang );
			$clean    = 'desc' === $field ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );

			if ( '' !== trim( $clean ) ) {
				update_term_meta( $term_id, $meta_key, $clean );

				$source = 'desc' === $field ? $term->description : $term->name;
				update_term_meta( $term_id, self::get_term_source_hash_meta_key( $field, $lang ), md5( (string) $source ) );
			}
		}
	}

	/**
	 * Load translation settings from wp_options.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_translation_settings() {
		$settings = wp_parse_args(
			get_option( REST_API::OPTION_KEY, array() ),
			REST_API::get_default_settings()
		);

		$translation = $settings['translation'] ?? array();

		if ( empty( $translation['api_key'] ) && ! empty( $translation['arvan_api_key'] ) ) {
			$translation['api_key'] = $translation['arvan_api_key'];
		}

		if ( empty( $translation['api_endpoint'] ) && ! empty( $translation['arvan_api_endpoint'] ) ) {
			$translation['api_endpoint'] = $translation['arvan_api_endpoint'];
		}

		if ( empty( $translation['ai_model'] ) && ! empty( $translation['arvan_model'] ) ) {
			$translation['ai_model'] = $translation['arvan_model'];
		}

		if ( empty( $translation['ai_model'] ) ) {
			$translation['ai_model'] = AI_Client::DEFAULT_MODEL;
		}

		return $translation;
	}

	/**
	 * Sanitize and save a single meta field from POST data.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $meta_key  Meta key.
	 * @param string $sanitize  Sanitization strategy: text, textarea, html.
	 * @return void
	 */
	private function save_meta_field( $post_id, $meta_key, $sanitize ) {
		$form_key = self::get_form_field_name( $meta_key );

		if ( isset( $_POST[ $form_key ] ) ) {
			$raw = wp_unslash( $_POST[ $form_key ] );
		} elseif ( isset( $_POST[ $meta_key ] ) ) {
			$raw = wp_unslash( $_POST[ $meta_key ] );
		} else {
			return;
		}

		switch ( $sanitize ) {
			case 'html':
				$value = wp_kses_post( $raw );
				break;
			case 'textarea':
				$value = sanitize_textarea_field( $raw );
				break;
			case 'attachment_id':
				$value = absint( $raw );
				break;
			default:
				$value = sanitize_text_field( $raw );
				break;
		}

		if ( 'attachment_id' === $sanitize ) {
			if ( $value > 0 && 'attachment' === get_post_type( $value ) ) {
				update_post_meta( $post_id, $meta_key, $value );
			}

			return;
		}

		// Inactive language tabs and TinyMCE editors often submit empty strings even when
		// translations were saved via AJAX — never wipe stored meta on blank POST values.
		if ( 'html' === $sanitize ) {
			if ( '' === trim( wp_strip_all_tags( (string) $value ) ) ) {
				return;
			}
		} elseif ( '' === trim( (string) $value ) ) {
			return;
		}

		update_post_meta( $post_id, $meta_key, $value );
	}

	/**
	 * Save translated featured image ID from the meta box.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return void
	 */
	private function save_thumbnail_field( $post_id, $lang = 'en' ) {
		$meta_key = self::get_thumbnail_meta_key( $lang );

		if ( ! isset( $_POST[ $meta_key ] ) ) {
			return;
		}

		$this->save_meta_field( $post_id, $meta_key, 'attachment_id' );
	}

	/**
	 * Return a Persian label for a custom meta key.
	 *
	 * @param string $meta_key Meta key name.
	 * @return string
	 */
	public static function get_custom_meta_label( $meta_key, $lang = 'en' ) {
		$lang_label = \PolymartAI\Language_Registry::get_language_label_for_ai( $lang );

		$labels = array(
			'custom_card_subtitle' => sprintf(
				/* translators: %s: language name */
				__( 'زیرعنوان کارت (%s)', 'polymart-ai' ),
				$lang_label
			),
			'custom_card_btn_text' => sprintf(
				/* translators: %s: language name */
				__( 'متن دکمه کارت (%s)', 'polymart-ai' ),
				$lang_label
			),
			'_apd_ai_analysis'     => sprintf(
				/* translators: %s: language name */
				__( 'تحلیل هوش مصنوعی (%s)', 'polymart-ai' ),
				$lang_label
			),
		);

		if ( isset( $labels[ $meta_key ] ) ) {
			return $labels[ $meta_key ];
		}

		return ucwords( str_replace( array( '_', '-' ), ' ', $meta_key ) ) . ' (' . $lang . ')';
	}

	/**
	 * Determine translation completeness for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return string untranslated|partial|translated
	 */
	public static function get_translation_status( $post_id, $lang = 'en' ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( ! $post_id ) {
			return 'untranslated';
		}

		$cache_key = $post_id . ':' . $lang;

		if ( isset( self::$translation_status_cache[ $cache_key ] ) && is_string( self::$translation_status_cache[ $cache_key ] ) ) {
			return self::$translation_status_cache[ $cache_key ];
		}

		$audit = self::sanitize_translation_audit( self::compute_translation_audit( $post_id, $lang ), $post_id );

		$status = (string) ( $audit['status'] ?? 'untranslated' );

		if ( 'translated' === $status ) {
			$post = get_post( $post_id );

			if ( $post instanceof \WP_Post && ! empty( self::collect_persian_fields( $post, $lang ) ) ) {
				$status = 'partial';
			}
		}

		self::$translation_status_cache[ $cache_key ] = $status;

		$index_key     = self::get_status_index_meta_key( $lang );
		$stored_status = sanitize_key( (string) get_post_meta( $post_id, $index_key, true ) );

		if ( $stored_status !== $status ) {
			update_post_meta( $post_id, $index_key, $status );
		}

		return $status;
	}

	/**
	 * List required translation fields and those still missing for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return array{required: string[], missing: string[], labels: array<string, bool>, fields: array<int, array<string, mixed>>, status: string, notes: string[]}
	 */
	public static function get_translation_gaps( $post_id, $lang = 'en' ) {
		self::flush_translation_status_cache( $post_id );

		$audit = self::sanitize_translation_audit( self::compute_translation_audit( $post_id, $lang ), $post_id );

		return array(
			'required' => array_column( $audit['fields'], 'label' ),
			'missing'  => $audit['missing'],
			'labels'   => $audit['labels'],
			'fields'   => $audit['fields'],
			'status'   => $audit['status'],
			'notes'    => $audit['notes'],
		);
	}

	/**
	 * Detailed audit of what a post still needs translated.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return array{status: string, fields: array<int, array<string, mixed>>, missing: string[], labels: array<string, bool>, notes: string[]}
	 */
	private static function compute_translation_audit( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$post    = get_post( $post_id );
		$fields  = array();
		$notes   = array();

		if ( ! $post instanceof \WP_Post ) {
			return array(
				'status'  => 'translated',
				'fields'  => array(),
				'missing' => array(),
				'labels'  => array(),
				'notes'   => array( __( 'پست یافت نشد.', 'polymart-ai' ) ),
			);
		}

		if ( 'woodmart_slide' === $post->post_type && get_post_thumbnail_id( $post_id ) ) {
			self::ensure_translated_thumbnail_fallback( $post_id, $lang );

			$fields[] = array(
				'key'        => 'thumbnail',
				'label'      => __( 'تصویر شاخص', 'polymart-ai' ),
				'meta_key'   => self::get_thumbnail_meta_key( $lang ),
				'has_source' => true,
				'translated' => self::get_translated_thumbnail_id( $post_id, $lang ) > 0,
			);
		}

		$title_source = Persian_Detector::only_persian_value( $post->post_title );

		if ( '' !== $title_source ) {
			$fields[] = array(
				'key'        => 'post_title',
				'label'      => __( 'عنوان', 'polymart-ai' ),
				'meta_key'   => self::get_meta_key( 'title', $lang ),
				'has_source' => true,
				'translated' => self::is_field_translation_current( $post_id, 'post_title', $lang, $title_source ),
			);
		}

		if ( ! self::is_commerce_product_post( $post_id, $post ) && self::should_require_elementor_translation( $post_id, $post ) ) {
			if ( self::has_elementor_persian_content( $post_id ) ) {
				$fields[] = array(
					'key'        => 'elementor_json',
					'label'      => __( 'Elementor (JSON)', 'polymart-ai' ),
					'meta_key'   => self::get_elementor_meta_key( $lang ),
					'has_source' => true,
					'translated' => self::has_stored_elementor_translation( $post_id, $lang ),
				);
			} else {
				$html_source = self::extract_elementor_html_persian_excerpt( $post->post_content );

				if ( '' !== $html_source ) {
					$fields[] = array(
						'key'        => 'post_content_html',
						'label'      => __( 'محتوای HTML (کش Elementor)', 'polymart-ai' ),
						'meta_key'   => self::get_meta_key( 'content', $lang ),
						'has_source' => true,
						'translated' => self::is_field_translation_current( $post_id, 'post_content', $lang, $html_source ),
					);
				}
			}
		} else {
			$content_source = Persian_Detector::only_persian_value( $post->post_content );

			if ( '' !== $content_source ) {
				$fields[] = array(
					'key'        => 'post_content',
					'label'      => __( 'محتوا', 'polymart-ai' ),
					'meta_key'   => self::get_meta_key( 'content', $lang ),
					'has_source' => true,
					'translated' => self::is_field_translation_current( $post_id, 'post_content', $lang, $content_source ),
				);
			}
		}

		$excerpt_source = Persian_Detector::only_persian_value( $post->post_excerpt );

		if ( '' !== $excerpt_source ) {
			$fields[] = array(
				'key'        => 'post_excerpt',
				'label'      => __( 'خلاصه', 'polymart-ai' ),
				'meta_key'   => self::get_meta_key( 'excerpt', $lang ),
				'has_source' => true,
				'translated' => self::is_field_translation_current( $post_id, 'post_excerpt', $lang, $excerpt_source ),
			);
		}

		foreach ( self::CUSTOM_META_KEYS as $meta_key ) {
			$source = get_post_meta( $post_id, $meta_key, true );
			$source = is_string( $source ) ? Persian_Detector::only_persian_value( $source ) : '';

			if ( '' === $source ) {
				continue;
			}

			$fields[] = array(
				'key'        => $meta_key,
				'label'      => self::get_custom_meta_label( $meta_key, $lang ),
				'meta_key'   => self::get_custom_meta_key( $meta_key, $lang ),
				'has_source' => true,
				'translated' => self::is_field_translation_current( $post_id, $meta_key, $lang, $source ),
			);
		}

		foreach ( self::get_discovered_meta_fields_cached( $post_id ) as $meta_key => $source ) {
			if ( self::is_commerce_product_post( $post_id, $post ) ) {
				break;
			}

			if ( '' === trim( $source ) ) {
				continue;
			}

			$fields[] = array(
				'key'        => $meta_key,
				'label'      => self::get_custom_meta_label( $meta_key, $lang ),
				'meta_key'   => self::get_custom_meta_key( $meta_key, $lang ),
				'has_source' => true,
				'translated' => self::is_field_translation_current( $post_id, $meta_key, $lang, $source ),
			);
		}

		foreach ( self::collect_translatable_terms( $post_id ) as $term ) {
			$name_source = Persian_Detector::only_persian_value( $term->name );

			if ( '' !== $name_source ) {
				$fields[] = array(
					'key'        => 'term_name_' . $term->term_id,
					'label'      => sprintf(
						/* translators: 1: taxonomy term name */
						__( 'برچسب: %s', 'polymart-ai' ),
						$term->name
					),
					'meta_key'   => self::get_term_meta_key( 'name', $lang ) . ' #' . $term->term_id,
					'has_source' => true,
					'translated' => self::is_term_translation_current( $term->term_id, 'name', $lang, $name_source ),
				);
			}

			$desc_source = Persian_Detector::only_persian_value( $term->description );

			if ( '' !== $desc_source ) {
				$fields[] = array(
					'key'        => 'term_desc_' . $term->term_id,
					'label'      => sprintf(
						/* translators: 1: taxonomy term name */
						__( 'توضیح برچسب: %s', 'polymart-ai' ),
						$term->name
					),
					'meta_key'   => self::get_term_meta_key( 'desc', $lang ) . ' #' . $term->term_id,
					'has_source' => true,
					'translated' => self::is_term_translation_current( $term->term_id, 'desc', $lang, $desc_source ),
				);
			}
		}

		if ( $post instanceof \WP_Post && 'product' === $post->post_type ) {
			foreach ( self::collect_product_attribute_fields( $post, $lang ) as $key => $source ) {
				if ( 0 === strpos( $key, 'product_attr_value:' ) ) {
					$group   = substr( $key, strlen( 'product_attr_value:' ) );
					$group   = preg_replace( '/:[a-f0-9]{32}$/', '', (string) $group );
					$context = 'wc_attr_opt:' . sanitize_key( (string) $group );
					$label   = sprintf(
						/* translators: 1: attribute option value */
						__( 'مقدار ویژگی: %s', 'polymart-ai' ),
						$source
					);
				} elseif ( 0 === strpos( $key, 'product_attr_label:' ) ) {
					$group   = substr( $key, strlen( 'product_attr_label:' ) );
					$context = 'wc_attr_label:' . sanitize_key( (string) $group );
					$label   = sprintf(
						/* translators: 1: attribute label */
						__( 'برچسب ویژگی: %s', 'polymart-ai' ),
						$source
					);
				} else {
					continue;
				}

				$fields[] = array(
					'key'        => $key,
					'label'      => $label,
					'meta_key'   => $context,
					'has_source' => true,
					'translated' => self::has_product_attribute_translation( $post_id, $source, $lang, $context ),
				);
			}

			foreach ( self::collect_variation_title_fields( $post, $lang ) as $key => $source ) {
				if ( 1 === preg_match( '/^variation_custom_title:(\d+)$/', $key, $matches ) ) {
					$variation_id = absint( $matches[1] );
					$source_key   = self::WVE_VARIATION_CUSTOM_TITLE_META;
					$label        = sprintf(
						/* translators: 1: WVE custom variation title */
						__( 'عنوان سفارشی متغیر: %s', 'polymart-ai' ),
						$source
					);
					$meta_key     = self::get_meta_key( 'title', $lang ) . ' #' . $variation_id;
				} elseif ( 1 === preg_match( '/^variation_custom_description:(\d+)$/', $key, $matches ) ) {
					$variation_id = absint( $matches[1] );
					$source_key   = self::WVE_VARIATION_CUSTOM_DESCRIPTION_META;
					$label        = sprintf(
						/* translators: 1: WVE custom variation description */
						__( 'توضیح سفارشی متغیر: %s', 'polymart-ai' ),
						$source
					);
					$meta_key     = self::get_meta_key( 'excerpt', $lang ) . ' #' . $variation_id;
				} elseif ( 1 === preg_match( '/^variation_title:(\d+)$/', $key, $matches ) ) {
					$variation_id = absint( $matches[1] );
					$source_key   = 'post_title';
					$label        = sprintf(
						/* translators: 1: variation title */
						__( 'عنوان متغیر: %s', 'polymart-ai' ),
						$source
					);
					$meta_key     = self::get_meta_key( 'title', $lang ) . ' #' . $variation_id;
				} else {
					continue;
				}

				$fields[] = array(
					'key'        => $key,
					'label'      => $label,
					'meta_key'   => $meta_key,
					'has_source' => true,
					'translated' => self::is_field_translation_current( $variation_id, $source_key, $lang, $source ),
				);
			}
		}

		if ( empty( $fields ) && self::post_has_persian_content( $post ) ) {
			$notes[] = __( 'محتوای فارسی شناسایی شد اما فیلد دقیق مشخص نشد — post_content یا meta سفارشی را دستی بررسی کنید.', 'polymart-ai' );
		}

		return self::finalize_translation_audit( $fields, $notes, $post_id );
	}

	/**
	 * Remove product-incompatible audit rows and recompute completeness.
	 *
	 * WooCommerce products may carry legacy Elementor builder meta from experiments
	 * or Woodmart imports. Those JSON blobs are not storefront copy and must not
	 * block auto-translate jobs.
	 *
	 * @param array<string, mixed> $audit   Raw audit payload.
	 * @param int                  $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private static function sanitize_translation_audit( array $audit, $post_id ) {
		if ( ! self::is_commerce_product_post( $post_id ) ) {
			return $audit;
		}

		$fields = array();

		foreach ( (array) ( $audit['fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$key      = (string) ( $field['key'] ?? '' );
			$meta_key = (string) ( $field['meta_key'] ?? '' );

			if (
				'elementor_json' === $key
				|| 0 === strpos( $meta_key, '_elementor_' )
				|| 0 === strpos( $key, '_elementor_' )
			) {
				continue;
			}

			$fields[] = $field;
		}

		$notes = is_array( $audit['notes'] ?? null ) ? $audit['notes'] : array();

		return self::finalize_translation_audit( $fields, $notes, $post_id );
	}

	/**
	 * @param array<int, array<string, mixed>> $fields  Field audit rows.
	 * @param string[]                         $notes   Extra notes.
	 * @param int                              $post_id Post ID for empty-field status.
	 * @return array{status: string, fields: array<int, array<string, mixed>>, missing: string[], labels: array<string, bool>, notes: string[]}
	 */
	private static function finalize_translation_audit( array $fields, array $notes, $post_id = 0 ) {
		$labels  = array();
		$missing = array();

		foreach ( $fields as $field ) {
			$label            = (string) ( $field['label'] ?? '' );
			$labels[ $label ] = ! empty( $field['translated'] );

			if ( empty( $field['translated'] ) ) {
				$missing[] = $label;
			}
		}

		if ( empty( $fields ) ) {
			if ( $post_id > 0 && self::has_persian_content( $post_id ) ) {
				$status = 'untranslated';
			} elseif ( empty( $notes ) ) {
				$status = 'translated';
			} else {
				$status = 'partial';
			}
		} elseif ( empty( $missing ) ) {
			$status = 'translated';
		} elseif ( count( $missing ) === count( $fields ) ) {
			$status = 'untranslated';
		} else {
			$status = 'partial';
		}

		if ( 'partial' === $status && empty( $missing ) && ! empty( $notes ) ) {
			$missing[] = __( 'نیاز به بررسی دستی', 'polymart-ai' );
		}

		return array(
			'status'  => $status,
			'fields'  => $fields,
			'missing' => $missing,
			'labels'  => $labels,
			'notes'   => $notes,
		);
	}

	/**
	 * Cached wrapper around discovered meta scans (stats/jobs call this heavily).
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string>
	 */
	private static function get_discovered_meta_fields_cached( $post_id ) {
		$post_id = absint( $post_id );

		if ( isset( self::$discovered_meta_cache[ $post_id ] ) ) {
			return self::$discovered_meta_cache[ $post_id ];
		}

		self::$discovered_meta_cache[ $post_id ] = self::collect_discovered_meta_fields( $post_id );

		return self::$discovered_meta_cache[ $post_id ];
	}

	/**
	 * Whether Elementor JSON on a post contains Persian text worth translating.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function has_elementor_persian_content( $post_id ) {
		$post_id = absint( $post_id );

		if ( isset( self::$elementor_persian_cache[ $post_id ] ) ) {
			return self::$elementor_persian_cache[ $post_id ];
		}

		self::$elementor_persian_cache[ $post_id ] = '' !== self::collect_elementor_persian_plain_text( $post_id );

		return self::$elementor_persian_cache[ $post_id ];
	}

	/**
	 * Whether a stored translated Elementor JSON payload exists for a language.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	private static function has_stored_elementor_translation( $post_id, $lang ) {
		return self::is_elementor_translation_current( $post_id, $lang );
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
	 * Recompute translation status for every Persian-flagged post.
	 *
	 * @param string $lang Target language code.
	 * @return int Number of posts checked.
	 */
	public static function reconcile_all_flagged_translation_indexes( $lang ) {
		global $wpdb;

		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			return 0;
		}

		$post_types = array_values(
			array_filter(
				array_map( 'sanitize_key', self::get_supported_post_types() )
			)
		);

		if ( empty( $post_types ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$type_clause  = " AND p.post_type IN ( {$placeholders} ) ";
		$checked      = 0;

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_flag
					ON p.ID = pm_flag.post_id
					AND pm_flag.meta_key = %s
					AND pm_flag.meta_value = '1'
				WHERE p.post_status = 'publish'
					{$type_clause}
				ORDER BY p.ID ASC",
				array_merge( array( self::PERSIAN_CONTENT_FLAG_META ), $post_types )
			)
		);

		if ( ! is_array( $post_ids ) ) {
			return 0;
		}

		foreach ( array_map( 'absint', $post_ids ) as $post_id ) {
			if ( $post_id <= 0 ) {
				continue;
			}

			self::flush_translation_status_cache( $post_id );
			self::get_translation_status( $post_id, $lang );
			++$checked;
		}

		return $checked;
	}

	/**
	 * Fix posts whose index says "translated" but live audit disagrees.
	 *
	 * @param string $lang        Target language code.
	 * @param int    $batch_limit Maximum posts to reconcile per call.
	 * @return int Number of posts reconciled.
	 */
	public static function reconcile_stale_translation_indexes( $lang, $batch_limit = 500 ) {
		global $wpdb;

		$lang        = sanitize_key( (string) $lang );
		$batch_limit = max( 1, absint( $batch_limit ) );

		if ( '' === $lang ) {
			return 0;
		}

		$post_types = self::get_supported_post_types();

		if ( empty( $post_types ) ) {
			return 0;
		}

		$status_key = self::get_status_index_meta_key( $lang );
		$title_key  = self::get_meta_key( 'title', $lang );
		$post_types = array_values(
			array_filter(
				array_map( 'sanitize_key', $post_types )
			)
		);
		$reconciled = 0;

		if ( empty( $post_types ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$type_clause  = " AND p.post_type IN ( {$placeholders} ) ";

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_flag
					ON p.ID = pm_flag.post_id
					AND pm_flag.meta_key = %s
					AND pm_flag.meta_value = '1'
				INNER JOIN {$wpdb->postmeta} pm_status
					ON p.ID = pm_status.post_id
					AND pm_status.meta_key = %s
					AND pm_status.meta_value = 'translated'
				LEFT JOIN {$wpdb->postmeta} pm_title
					ON p.ID = pm_title.post_id
					AND pm_title.meta_key = %s
				WHERE p.post_status = 'publish'
					{$type_clause}
				ORDER BY ( pm_title.meta_id IS NULL OR pm_title.meta_value = '' OR pm_title.meta_value IS NULL ) DESC, p.ID ASC
				LIMIT %d",
				array_merge(
					array( self::PERSIAN_CONTENT_FLAG_META, $status_key, $title_key ),
					$post_types,
					array( $batch_limit )
				)
			)
		);

		if ( ! is_array( $post_ids ) ) {
			return 0;
		}

		foreach ( array_map( 'absint', $post_ids ) as $post_id ) {
			if ( $post_id <= 0 ) {
				continue;
			}

			self::flush_translation_status_cache( $post_id );
			$stored_status = sanitize_key( (string) get_post_meta( $post_id, $status_key, true ) );
			$live_status   = self::get_translation_status( $post_id, $lang );

			if ( $stored_status !== $live_status ) {
				++$reconciled;
			}
		}

		return $reconciled;
	}

	/**
	 * Persist fast lookup meta used by admin stats and list pagination.
	 *
	 * @param int         $post_id Post ID.
	 * @param string|null $lang    Optional single language; defaults to all enabled targets.
	 * @return void
	 */
	public static function sync_translation_index_meta( $post_id, $lang = null ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			delete_post_meta( $post_id, self::PERSIAN_CONTENT_FLAG_META );

			foreach ( Language_Registry::get_translation_target_languages() as $language ) {
				$code = sanitize_key( (string) ( $language['code'] ?? '' ) );

				if ( '' !== $code ) {
					delete_post_meta( $post_id, self::get_status_index_meta_key( $code ) );
				}
			}

			return;
		}

		if ( ! self::post_has_persian_content( $post ) ) {
			delete_post_meta( $post_id, self::PERSIAN_CONTENT_FLAG_META );

			foreach ( Language_Registry::get_translation_target_languages() as $language ) {
				$code = sanitize_key( (string) ( $language['code'] ?? '' ) );

				if ( '' !== $code ) {
					delete_post_meta( $post_id, self::get_status_index_meta_key( $code ) );
				}
			}

			return;
		}

		update_post_meta( $post_id, self::PERSIAN_CONTENT_FLAG_META, '1' );

		$languages = array();

		if ( is_string( $lang ) && '' !== sanitize_key( $lang ) ) {
			$languages[] = sanitize_key( $lang );
		} else {
			foreach ( Language_Registry::get_translation_target_languages() as $language ) {
				$code = sanitize_key( (string) ( $language['code'] ?? '' ) );

				if ( '' !== $code ) {
					$languages[] = $code;
				}
			}
		}

		foreach ( $languages as $target_lang ) {
			update_post_meta(
				$post_id,
				self::get_status_index_meta_key( $target_lang ),
				self::get_translation_status( $post_id, $target_lang )
			);
		}
	}

	/**
	 * Clear per-request translation status memoization after saves.
	 *
	 * @param int $post_id Optional post ID to clear.
	 * @return void
	 */
	public static function flush_translation_status_cache( $post_id = 0 ) {
		$post_id = absint( $post_id );

		if ( $post_id > 0 ) {
			unset(
				self::$discovered_meta_cache[ $post_id ],
				self::$elementor_persian_cache[ $post_id ],
				self::$elementor_source_hash_cache[ $post_id ],
				self::$elementor_plain_text_cache[ $post_id ]
			);

			foreach ( array_keys( self::$translation_status_cache ) as $cache_key ) {
				if ( 0 === strpos( $cache_key, $post_id . ':' ) ) {
					unset( self::$translation_status_cache[ $cache_key ] );
				}
			}

			foreach ( array_keys( self::$elementor_current_cache ) as $cache_key ) {
				if ( 0 === strpos( $cache_key, $post_id . ':' ) ) {
					unset( self::$elementor_current_cache[ $cache_key ] );
				}
			}

			foreach ( array_keys( self::$stored_elementor_json_cache ) as $cache_key ) {
				if ( 0 === strpos( $cache_key, $post_id . ':' ) ) {
					unset( self::$stored_elementor_json_cache[ $cache_key ] );
				}
			}

			foreach ( array_keys( self::$elementor_storefront_serve_cache ) as $cache_key ) {
				if ( 0 === strpos( $cache_key, 'serve:' . $post_id . ':' ) ) {
					unset( self::$elementor_storefront_serve_cache[ $cache_key ] );
				}
			}

			foreach ( array_keys( self::$should_serve_cache ) as $cache_key ) {
				if ( 0 === strpos( $cache_key, $post_id . ':' ) ) {
					unset( self::$should_serve_cache[ $cache_key ] );
				}
			}

			return;
		}

		self::$translation_status_cache        = array();
		self::$discovered_meta_cache           = array();
		self::$elementor_persian_cache         = array();
		self::$elementor_source_hash_cache     = array();
		self::$elementor_current_cache         = array();
		self::$elementor_plain_text_cache      = array();
		self::$stored_elementor_json_cache     = array();
		self::$elementor_storefront_serve_cache = array();
		self::$should_serve_cache              = array();
	}

	/**
	 * Build a translation record for the admin manager UI.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	public static function format_translation_record( \WP_Post $post, $lang = 'en' ) {
		$post_id = (int) $post->ID;
		$lang    = sanitize_key( (string) $lang );
		$status  = self::get_translation_status( $post_id, $lang );
		$type    = $post->post_type;

		$custom_fields = array();

		foreach ( self::CUSTOM_META_KEYS as $meta_key ) {
			$value_fa = get_post_meta( $post_id, $meta_key, true );

			if ( ! is_string( $value_fa ) || ! Persian_Detector::contains_persian( $value_fa ) ) {
				continue;
			}

			$translated_key = self::get_custom_meta_key( $meta_key, $lang );

			$custom_fields[] = array(
				'key'      => $meta_key,
				'meta_key' => $translated_key,
				'label'    => self::get_custom_meta_label( $meta_key, $lang ),
				'value_fa' => $value_fa,
				'value_en' => (string) get_post_meta( $post_id, $translated_key, true ),
			);
		}

		foreach ( self::collect_discovered_meta_fields( $post_id ) as $meta_key => $value_fa ) {
			$translated_key = self::get_custom_meta_key( $meta_key, $lang );

			$custom_fields[] = array(
				'key'      => $meta_key,
				'meta_key' => $translated_key,
				'label'    => self::get_custom_meta_label( $meta_key, $lang ),
				'value_fa' => $value_fa,
				'value_en' => (string) get_post_meta( $post_id, $translated_key, true ),
			);
		}

		$translated_at = (int) get_post_meta( $post_id, '_polymart_ai_translated_at_' . $lang, true );

		if ( $translated_at <= 0 ) {
			$translated_at = (int) get_post_meta( $post_id, '_polymart_ai_translated_at', true );
		}

		$language = \PolymartAI\Language_Registry::get_language( $lang );
		$prefix   = $language && ! empty( $language['url_prefix'] ) ? $language['url_prefix'] : 'en';

		return array(
			'post_id'              => $post_id,
			'post_type'            => $type,
			'post_type_label'      => self::get_post_type_label( $type ),
			'lang'                 => $lang,
			'lang_label'           => $language ? $language['native_name'] : $lang,
			'status'               => $status,
			'has_persian_content'  => self::has_persian_content( $post_id ),
			'title_fa'             => Persian_Detector::contains_persian( $post->post_title ) ? $post->post_title : '',
			'title_en'             => (string) get_post_meta( $post_id, self::get_meta_key( 'title', $lang ), true ),
			'excerpt_fa'           => Persian_Detector::contains_persian( $post->post_excerpt ) ? $post->post_excerpt : '',
			'excerpt_en'           => (string) get_post_meta( $post_id, self::get_meta_key( 'excerpt', $lang ), true ),
			'content_fa'           => Persian_Detector::contains_persian( $post->post_content ) ? $post->post_content : '',
			'content_en'           => (string) get_post_meta( $post_id, self::get_meta_key( 'content', $lang ), true ),
			'custom_fields'        => $custom_fields,
			'translated_at'        => $translated_at > 0 ? $translated_at : null,
			'edit_url'             => get_edit_post_link( $post_id, 'raw' ),
			'view_url_fa'          => get_permalink( $post_id ),
			'view_url_en'          => '' !== $prefix
				? home_url( '/' . $prefix . '/' . trim( str_replace( home_url(), '', get_permalink( $post_id ) ), '/' ) . '/' )
				: get_permalink( $post_id ),
		);
	}

	/**
	 * Return a localized label for supported post types.
	 *
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	public static function get_post_type_label( $post_type ) {
		$labels = array(
			'product'         => __( 'محصول', 'polymart-ai' ),
			'post'            => __( 'نوشته', 'polymart-ai' ),
			'page'            => __( 'برگه', 'polymart-ai' ),
			'woodmart_slide'  => __( 'اسلاید وودمارت', 'polymart-ai' ),
			'cms_block'       => __( 'بلوک CMS', 'polymart-ai' ),
			'woodmart_layout' => __( 'چیدمان وودمارت', 'polymart-ai' ),
		);

		return $labels[ $post_type ] ?? $post_type;
	}

	/**
	 * Persist manual English fields from the translation manager API.
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, mixed>  $fields  Submitted English field values.
	 * @return true|\WP_Error
	 */
	public static function save_manual_translations_from_api( $post_id, array $fields, $lang = 'en' ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'polymart_ai_forbidden',
				__( 'شما اجازه ویرایش این مورد را ندارید.', 'polymart-ai' )
			);
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return new \WP_Error(
				'polymart_ai_invalid_post',
				__( 'نوع مطلب برای ترجمه نامعتبر است.', 'polymart-ai' )
			);
		}

		if ( array_key_exists( 'title_en', $fields ) ) {
			update_post_meta( $post_id, self::get_meta_key( 'title', $lang ), sanitize_text_field( (string) $fields['title_en'] ) );
		}

		if ( array_key_exists( 'excerpt_en', $fields ) ) {
			update_post_meta( $post_id, self::get_meta_key( 'excerpt', $lang ), sanitize_textarea_field( (string) $fields['excerpt_en'] ) );
		}

		if ( array_key_exists( 'content_en', $fields ) ) {
			update_post_meta( $post_id, self::get_meta_key( 'content', $lang ), wp_kses_post( (string) $fields['content_en'] ) );
		}

		if ( ! empty( $fields['custom_fields'] ) && is_array( $fields['custom_fields'] ) ) {
			foreach ( $fields['custom_fields'] as $meta_key => $value ) {
				if ( ! is_string( $meta_key ) ) {
					continue;
				}

				$source_key = preg_replace( '/_' . preg_quote( $lang, '/' ) . '$/', '', $meta_key );

				if ( ! self::is_custom_meta_key( $source_key ) ) {
					$maybe_source = str_replace( '_' . $lang, '', $meta_key );

					if ( ! self::is_custom_meta_key( $maybe_source ) ) {
						continue;
					}

					$source_key = $maybe_source;
				}

				$target_key = self::get_custom_meta_key( $source_key, $lang );
				update_post_meta( $post_id, $target_key, sanitize_text_field( (string) $value ) );
			}
		}

		update_post_meta( $post_id, '_polymart_ai_translated_at_' . $lang, time() );
		update_post_meta( $post_id, '_polymart_ai_translated_at', time() );

		self::sync_field_source_hashes_for_post( $post_id, $lang );

		self::sync_translation_index_meta( $post_id, $lang );
		REST_API::invalidate_stats_cache();

		return true;
	}
}
