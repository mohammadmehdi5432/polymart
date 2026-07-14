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
		\PolymartAI\Activity_Logger::bootstrap_job_worker_context();
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
	const TRANSLATION_LOCK_TTL = 180;

	/**
	 * Option suffix storing the worker token that owns a per-post translation lock.
	 */
	const TRANSLATION_LOCK_OWNER_SUFFIX = '_owner';

	/**
	 * Per-request token identifying the current translation worker.
	 *
	 * @var string|null
	 */
	private static $translation_lock_token = null;

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
	 * Previous `_elementor_data` value captured before meta updates (hash compare).
	 *
	 * @var array<int, mixed>
	 */
	private static $elementor_data_prev_values = array();

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
	 * Per-request cache for Persian text left in stored Elementor companions.
	 *
	 * @var array<string, string>
	 */
	private static $stored_elementor_persian_cache = array();

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
		'story_text'          => true,
		'button_text'         => true,
		'customer_label'      => true,
		'partner_label'       => true,
		'search_placeholder'  => true,
		'search_brand_name'   => true,
		'no_results_message'  => true,
		'title'               => true,
		'editor'              => true,
		'text'                => true,
		'heading'             => true,
		'subtitle'            => true,
		'btn_text'            => true,
		'link_text'           => true,
		'placeholder'         => true,
		'html'                => true,
		'label_description'   => true,
		'label_attributes'    => true,
		'label_video'         => true,
		'label_customer_home' => true,
		'label_satisfaction'  => true,
		'content'             => true,
		'description'         => true,
		'message'             => true,
		'caption'             => true,
		'alt'                 => true,
		'alt_text'            => true,
		'image_alt'           => true,
		'image_url'           => true,
		'image_link'          => true,
		'tab_title'           => true,
		'tab_content'         => true,
		'tab_description'     => true,
		'accordion_title'     => true,
		'accordion_content'   => true,
		'accordion_description' => true,
		'list_title'          => true,
		'list_content'        => true,
		'item_title'          => true,
		'item_description'    => true,
		'item_text'           => true,
		'banner_title'        => true,
		'banner_subtitle'     => true,
		'banner_content'      => true,
		'banner_text'         => true,
		'label'               => true,
		'prefix'              => true,
		'suffix'              => true,
		'after_text'          => true,
		'before_text'         => true,
		'button_label'        => true,
		'read_more_text'      => true,
		'view_more_text'      => true,
		'price_text'          => true,
		'sale_text'           => true,
		'hotspot_label'       => true,
		'tooltip_content'     => true,
		'popup_title'         => true,
		'popup_content'       => true,
		'form_title'          => true,
		'form_description'    => true,
		'field_label'         => true,
		'field_placeholder'   => true,
		'counter_title'       => true,
		'counter_text'        => true,
		'testimonial_content' => true,
		'testimonial_name'    => true,
		'testimonial_title'   => true,
		'team_member_name'    => true,
		'team_member_position' => true,
		'team_member_bio'     => true,
		'icon_text'           => true,
		'info_box_title'      => true,
		'info_box_description' => true,
		'wd_title'            => true,
		'wd_subtitle'         => true,
		'wd_text'             => true,
		'text_content'        => true,
		'button_text_closed'  => true,
		'button_text_open'    => true,
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
	 * Core field batches per auto-translate job slice (smaller than manual translate).
	 */
	const JOB_CORE_FIELD_CHUNK_SIZE = 3;

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
	 * Elementor job batches: 1 field for bulk (timeout-safe), more for manual metabox.
	 */
	const ELEMENTOR_JOB_FIELD_CHUNK_SIZE = 6;

	/**
	 * Maximum characters per Elementor job-step API call.
	 */
	const ELEMENTOR_JOB_MAX_CHUNK_CHARS = 1500;

	/**
	 * Split very long Elementor text fields before calling the AI.
	 *
	 * Helps avoid gateway timeouts on long HTML-heavy content.
	 */
	const ELEMENTOR_LONG_FIELD_SEGMENT_CHARS = 1000;

	/**
	 * HTTP timeout cap (seconds) for a single Elementor job-step AI call.
	 * Keep under shared-host / AS runner limits so actions cannot hang In-progress.
	 */
	const ELEMENTOR_JOB_REQUEST_TIMEOUT = 30;

	/**
	 * Minimum HTTP timeout (seconds) for auto-translate job AI calls.
	 */
	const JOB_REQUEST_MIN_TIMEOUT = 20;

	/**
	 * Maximum HTTP timeout (seconds) for a single auto-translate job AI call.
	 */
	const JOB_REQUEST_MAX_TIMEOUT = 30;

	/**
	 * Variation title batches — variable products may have 100+ rows.
	 */
	const VARIATION_AI_FIELD_CHUNK_SIZE = 2;

	/**
	 * Maximum combined characters per variation AI batch.
	 */
	const VARIATION_AI_MAX_CHUNK_CHARS = 1800;

	/**
	 * Commerce attribute/term batches.
	 */
	const COMMERCE_AI_FIELD_CHUNK_SIZE = 2;

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
		add_filter( 'update_post_metadata', array( $this, 'capture_elementor_data_prev_value' ), 10, 5 );
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
	 * Remember the previous `_elementor_data` payload before WordPress writes the new row.
	 *
	 * @param mixed  $check      Short-circuit return value.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New meta value.
	 * @param mixed  $prev_value Previous meta value.
	 * @return mixed
	 */
	public function capture_elementor_data_prev_value( $check, $post_id, $meta_key, $meta_value, $prev_value ) {
		unset( $check, $meta_value );

		if ( '_elementor_data' !== $meta_key ) {
			return $check;
		}

		self::$elementor_data_prev_values[ absint( $post_id ) ] = $prev_value;

		return $check;
	}

	/**
	 * Drop stored Elementor companions when the Persian source document changes.
	 *
	 * Only runs when the MD5 fingerprint of `_elementor_data` actually changed so
	 * Elementor autosaves with identical JSON do not wipe in-progress translations.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New meta value.
	 * @return void
	 */
	public function maybe_invalidate_elementor_on_source_change( $meta_id, $post_id, $meta_key, $meta_value ) {
		unset( $meta_id );

		if ( '_elementor_data' !== $meta_key ) {
			return;
		}

		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return;
		}

		if ( self::is_persisting_translations() ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$new_raw  = is_string( $meta_value ) ? $meta_value : '';
		$new_hash = '' !== $new_raw ? md5( $new_raw ) : '';

		$had_capture = array_key_exists( $post_id, self::$elementor_data_prev_values );
		$prev_raw    = $had_capture ? self::$elementor_data_prev_values[ $post_id ] : null;

		unset( self::$elementor_data_prev_values[ $post_id ] );

		$prev_hash = is_string( $prev_raw ) && '' !== $prev_raw ? md5( $prev_raw ) : '';

		if ( '' !== $new_hash && '' !== $prev_hash && hash_equals( $prev_hash, $new_hash ) ) {
			return;
		}

		if ( '' === $new_hash && '' === $prev_hash ) {
			return;
		}

		self::$elementor_source_hash_cache[ $post_id ] = $new_hash;
		unset( self::$elementor_current_cache );

		self::invalidate_elementor_translations( $post_id );
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
							$max_fields = 3;
							$max_chars  = 1800;
						} elseif ( $variation_count > 30 ) {
							$max_fields = 2;
							$max_chars  = 1500;
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
					self::JOB_CORE_FIELD_CHUNK_SIZE,
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
	private static function chunk_payload_with_limits( array $payload, $max_fields, $max_chars, $expand = true ) {
		if ( $expand ) {
			$payload = self::expand_payload_for_ai( $payload );
		}

		$chunks        = array();
		$current       = array();
		$current_chars = 0;
		$max_fields    = max( 1, absint( $max_fields ) );
		$max_chars     = max( 500, absint( $max_chars ) );

		foreach ( $payload as $key => $value ) {
			$value = (string) $value;
			$size  = self::utf8_strlen( (string) $key ) + self::utf8_strlen( $value );

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
	 * Multibyte-safe string length for Elementor / Persian text payloads.
	 *
	 * @param mixed $text Text value.
	 * @return int
	 */
	private static function utf8_strlen( $text ) {
		$text = (string) $text;

		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}

		return strlen( $text );
	}

	/**
	 * Multibyte-safe substring for Elementor / Persian text payloads.
	 *
	 * @param mixed $text   Text value.
	 * @param int   $start  Start offset (characters).
	 * @param int   $length Segment length (characters).
	 * @return string
	 */
	private static function utf8_substr( $text, $start, $length ) {
		$text = (string) $text;

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, $start, $length, 'UTF-8' );
		}

		return substr( $text, $start, $length );
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

			if ( self::utf8_strlen( $value ) <= self::AI_MAX_SINGLE_FIELD_CHARS ) {
				$expanded[ $key ] = $value;
				continue;
			}

			$offset = 0;
			$part   = 1;
			$length = self::utf8_strlen( $value );

			while ( $offset < $length ) {
				$slice = self::utf8_substr( $value, $offset, self::AI_MAX_SINGLE_FIELD_CHARS );
				$expanded[ $key . '::__part' . $part ] = $slice;
				$offset += self::utf8_strlen( $slice );
				++$part;
			}
		}

		return $expanded;
	}

	/**
	 * Split a long Elementor text value into smaller, paragraph-aware segments.
	 *
	 * Segments are keyed as "{$path}::__seg1", "{$path}::__seg2", ...
	 *
	 * @param string $path Base Elementor path.
	 * @param string $text Source text.
	 * @param int    $max_chars Segment size cap (characters).
	 * @return array<string, string> Segment map.
	 */
	private static function split_elementor_text_into_segments( $path, $text, $max_chars ) {
		$path      = (string) $path;
		$text      = (string) $text;
		$max_chars = max( 200, absint( $max_chars ) );

		// Prefer splitting on paragraph boundaries.
		$pieces = array();

		if ( false !== stripos( $text, '<p' ) || false !== stripos( $text, '</p>' ) ) {
			// Split after closing </p> while keeping the delimiter.
			$raw = preg_split( '/(<\/p>\s*)/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
			if ( is_array( $raw ) ) {
				for ( $i = 0; $i < count( $raw ); $i += 2 ) {
					$chunk = (string) ( $raw[ $i ] ?? '' );
					$delim = (string) ( $raw[ $i + 1 ] ?? '' );
					$piece = $chunk . $delim;
					if ( '' !== trim( $piece ) ) {
						$pieces[] = $piece;
					}
				}
			}
		}

		if ( empty( $pieces ) ) {
			$raw = preg_split( "/\n\s*\n/u", $text );
			if ( is_array( $raw ) ) {
				foreach ( $raw as $p ) {
					$p = (string) $p;
					if ( '' !== trim( $p ) ) {
						$pieces[] = $p . "\n\n";
					}
				}
			}
		}

		if ( empty( $pieces ) ) {
			$pieces = array( $text );
		}

		$segments = array();
		$buffer   = '';
		$seg      = 1;

		foreach ( $pieces as $piece ) {
			$piece = (string) $piece;

			if ( '' === $buffer ) {
				$buffer = $piece;
			} elseif ( self::utf8_strlen( $buffer . $piece ) <= $max_chars ) {
				$buffer .= $piece;
			} else {
				$segments[ $path . '::__seg' . $seg ] = $buffer;
				++$seg;
				$buffer = $piece;
			}

			// Extremely long single piece (e.g. one giant <p>) — hard cut.
			while ( self::utf8_strlen( $buffer ) > $max_chars ) {
				$segments[ $path . '::__seg' . $seg ] = self::utf8_substr( $buffer, 0, $max_chars );
				++$seg;
				$buffer = self::utf8_substr( $buffer, $max_chars, self::utf8_strlen( $buffer ) - $max_chars );
			}
		}

		if ( '' !== $buffer ) {
			$segments[ $path . '::__seg' . $seg ] = $buffer;
		}

		// If we failed to actually split, keep original.
		if ( count( $segments ) <= 1 ) {
			return array( $path => $text );
		}

		return $segments;
	}

	/**
	 * Expand Elementor payload to segment long fields before chunking.
	 *
	 * @param array<string, string> $payload Path => text.
	 * @return array<string, string>
	 */
	private static function expand_elementor_payload_for_ai( array $payload ) {
		$expanded = array();

		foreach ( $payload as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( '' === $path || '' === trim( $text ) ) {
				continue;
			}

			if ( self::utf8_strlen( $text ) <= self::ELEMENTOR_LONG_FIELD_SEGMENT_CHARS ) {
				$expanded[ $path ] = $text;
				continue;
			}

			foreach ( self::split_elementor_text_into_segments( $path, $text, self::ELEMENTOR_LONG_FIELD_SEGMENT_CHARS ) as $seg_key => $seg_text ) {
				$expanded[ (string) $seg_key ] = (string) $seg_text;
			}
		}

		return $expanded;
	}

	/**
	 * Compute expected segment keys for a long Elementor field.
	 *
	 * @param string $path Base path.
	 * @param string $text Source text.
	 * @return array<int, string> List of segment keys in order.
	 */
	private static function get_elementor_segment_keys( $path, $text ) {
		$path = (string) $path;
		$text = (string) $text;

		if ( self::utf8_strlen( $text ) <= self::ELEMENTOR_LONG_FIELD_SEGMENT_CHARS ) {
			return array();
		}

		$segments = self::split_elementor_text_into_segments( $path, $text, self::ELEMENTOR_LONG_FIELD_SEGMENT_CHARS );
		$keys     = array();

		foreach ( array_keys( $segments ) as $k ) {
			$k = (string) $k;
			if ( $k !== $path ) {
				$keys[] = $k;
			}
		}

		return $keys;
	}

	/**
	 * Map segment key => segment source text for a long Elementor field.
	 *
	 * @param string $path Base path.
	 * @param string $text Full source text.
	 * @return array<string, string>
	 */
	private static function get_elementor_segment_source_lookup( $path, $text ) {
		$path = (string) $path;
		$text = (string) $text;

		if ( self::utf8_strlen( $text ) <= self::ELEMENTOR_LONG_FIELD_SEGMENT_CHARS ) {
			return array();
		}

		$segments = self::split_elementor_text_into_segments( $path, $text, self::ELEMENTOR_LONG_FIELD_SEGMENT_CHARS );
		$lookup   = array();

		foreach ( $segments as $k => $v ) {
			$k = (string) $k;
			$v = (string) $v;
			if ( $k === $path ) {
				continue;
			}
			$lookup[ $k ] = $v;
		}

		return $lookup;
	}

	/**
	 * Merge translated payload parts back into their original field keys.
	 *
	 * @param array<string, string> $translations Translated field map.
	 * @return array<string, string>
	 */
	public static function collapse_payload_parts( array $translations ) {
		$merged = array();
		$parts  = array();

		foreach ( $translations as $key => $value ) {
			$key = (string) $key;

			if ( preg_match( '/^(.+)::__(part|seg)(\d+)$/', $key, $matches ) ) {
				$base  = (string) $matches[1];
				$index = absint( $matches[3] );

				if ( $index <= 0 ) {
					$index = 1;
				}

				if ( ! isset( $parts[ $base ] ) ) {
					$parts[ $base ] = array();
				}

				$parts[ $base ][ $index ] = (string) $value;
				continue;
			}

			$merged[ $key ] = (string) $value;
		}

		foreach ( $parts as $base => $chunk_parts ) {
			ksort( $chunk_parts );
			$merged[ $base ] = implode( '', array_values( $chunk_parts ) );
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
		return max( 1, (int) apply_filters( 'polymart_ai_job_step_max_field_chunks', 1 ) );
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
			$chunks = 1;

			if ( $post_id > 0 && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $post_id );

				if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
					$variation_count = count( $product->get_children() );

					if ( $variation_count > 80 ) {
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
		$default = \PolymartAI\Activity_Logger::is_bulk_job_running() ? 1 : 4;

		/**
		 * Filter how many Elementor AI batches one auto-translate step may run.
		 *
		 * @param int $chunks Default 1 for bulk jobs, 4 for manual metabox runs.
		 */
		return max( 1, (int) apply_filters( 'polymart_ai_job_step_max_elementor_chunks', $default ) );
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

		if (
			self::post_needs_elementor_job_work( $post_id, $lang )
			|| self::elementor_job_has_remaining_payload( $post_id, $lang )
		) {
			return self::hydrate_elementor_job_partial_state(
				$post_id,
				$lang,
				array_merge( $base, array( 'phase' => 'elementor' ) )
			);
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
			|| false !== strpos( $message, '404' )
			|| false !== strpos( $message, 'blocked' )
			|| false !== strpos( $message, 'arvancloud' )
			|| false !== strpos( $message, 'مسدود' )
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
			if ( absint( $state['elementor_chunk_index'] ?? 0 ) > 0 ) {
				return true;
			}

			return (bool) get_post_meta( $post_id, self::get_elementor_meta_key( $lang ), true );
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
		$persist = $state;
		$post_id = absint( $post_id );

		// Translations live in `_elementor_data_{lang}` — keep partial meta lightweight.
		if ( 'elementor' === sanitize_key( (string) ( $persist['phase'] ?? '' ) ) ) {
			$map_for_persist = is_array( $persist['elementor_map'] ?? null ) ? $persist['elementor_map'] : array();

			if ( $post_id > 0 && ! empty( $map_for_persist ) ) {
				$raw = get_post_meta( $post_id, '_elementor_data', true );

				if ( is_string( $raw ) && '' !== trim( $raw ) ) {
					$data = json_decode( $raw, true );

					if ( is_array( $data ) ) {
						$source_payload = self::collect_elementor_translation_payload( $data );
						$map_for_persist = self::prune_complete_elementor_segment_map_entries(
							$map_for_persist,
							$source_payload
						);
						$persist_entries = self::extract_elementor_persist_map_entries( $map_for_persist, $source_payload );
						$legacy_seg      = is_array( $persist['elementor_seg_map'] ?? null ) ? $persist['elementor_seg_map'] : array();
						$existing        = is_array( $persist['elementor_persist_map'] ?? null ) ? $persist['elementor_persist_map'] : array();

						$persist['elementor_persist_map'] = array_merge( $existing, $legacy_seg, $persist_entries );
					}
				}
			} elseif ( is_array( $persist['elementor_persist_map'] ?? null ) || is_array( $persist['elementor_seg_map'] ?? null ) ) {
				$persist['elementor_persist_map'] = array_merge(
					is_array( $persist['elementor_persist_map'] ?? null ) ? $persist['elementor_persist_map'] : array(),
					is_array( $persist['elementor_seg_map'] ?? null ) ? $persist['elementor_seg_map'] : array()
				);
			}

			unset( $persist['elementor_map'], $persist['elementor_seg_map'] );
		}

		update_post_meta( $post_id, self::get_job_partial_meta_key( $lang ), $persist );
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
	 * Whether the auto-translate job should run Elementor slices now (field phases first).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	public static function should_run_elementor_job_slice( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		if ( self::elementor_job_api_schedule_complete( $post_id, $lang ) ) {
			return false;
		}

		if (
			! self::post_needs_elementor_job_work( $post_id, $lang )
			&& ! self::elementor_job_has_remaining_payload( $post_id, $lang )
		) {
			return false;
		}

		$state = self::get_job_partial_state( $post_id, $lang );
		$phase = sanitize_key( (string) ( $state['phase'] ?? '' ) );

		if ( 'elementor' === $phase ) {
			return true;
		}

		if ( in_array( $phase, array( 'core', 'commerce', 'variations', 'fields' ), true ) ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		foreach ( self::get_job_field_phases() as $field_phase ) {
			if ( ! empty( self::collect_persian_fields_for_job_phase( $post, $lang, $field_phase ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Restore Elementor partial counters/map from saved JSON without wiping progress.
	 *
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @param array<string, mixed> $state   Existing partial state.
	 * @return array<string, mixed>
	 */
	public static function hydrate_elementor_job_partial_state( $post_id, $lang, array $state = array() ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		$defaults = array(
			'phase'                          => 'elementor',
			'elementor_map'                  => array(),
			'elementor_persist_map'          => array(),
			'elementor_seg_map'              => array(),
			'elementor_gap_fill_finished_keys' => array(),
			'elementor_gap_fill_scheduled_keys' => array(),
			'elementor_chunk_index'          => 0,
			'elementor_chunks_total'      => 0,
			'elementor_slices_completed'  => 0,
			'elementor_failures'          => array(),
			'elementor_skipped'           => array(),
		);

		$state = array_merge( $defaults, $state );
		$state['phase'] = 'elementor';

		if ( $post_id <= 0 || '' === $lang ) {
			return $state;
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return $state;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return $state;
		}

		$state_map = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$map       = self::merge_elementor_job_path_map( $post_id, $lang, $data, $state, $state_map );

		$state['elementor_map'] = $map;
		$chunk_progress         = self::get_elementor_chunk_progress( $post_id, $lang, $state );
		$state['elementor_chunk_index']      = (int) $chunk_progress['done'];
		$state['elementor_chunks_total']     = (int) $chunk_progress['total'];
		$state['elementor_slices_completed'] = (int) $chunk_progress['done'];

		return $state;
	}

	/**
	 * Monotonic Elementor slice counter (API batches), including partial multi-part fields.
	 *
	 * @param array<string, mixed> $state         Partial state.
	 * @param int                  $computed_done Field/chunk completion estimate.
	 * @return int
	 */
	private static function resolve_elementor_done_count( array $state, $computed_done, $post_id = 0, $lang = '' ) {
		unset( $state, $post_id, $lang );

		return max( 0, absint( $computed_done ) );
	}

	/**
	 * Post meta key for monotonic Elementor API slice cursor (survives partial-state clears).
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	private static function get_elementor_slice_cursor_meta_key( $lang ) {
		return '_polymart_ai_elementor_slice_cursor_' . sanitize_key( (string) $lang );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return int
	 */
	private static function read_elementor_slice_cursor( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return 0;
		}

		$stored = get_post_meta( $post_id, self::get_elementor_slice_cursor_meta_key( $lang ), true );
		$cursor = is_array( $stored ) ? absint( $stored['cursor'] ?? 0 ) : absint( $stored );

		if ( $cursor <= 0 ) {
			$progress = (string) get_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ), true );

			if ( preg_match( '/(\d+)\s*\/\s*(\d+)/', $progress, $matches ) ) {
				$cursor = absint( $matches[1] );
			}
		}

		return max( 0, $cursor );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return int
	 */
	private static function read_elementor_slice_cursor_total( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return 0;
		}

		$stored = get_post_meta( $post_id, self::get_elementor_slice_cursor_meta_key( $lang ), true );

		return is_array( $stored ) ? absint( $stored['total'] ?? 0 ) : 0;
	}

	/**
	 * Apply one API batch to saved Elementor JSON and sync live job progress.
	 *
	 * @param int                   $post_id      Post ID.
	 * @param string                $lang         Language code.
	 * @param array<string, mixed>  $source_data  Source Elementor tree.
	 * @param array<string, mixed>  $state        Partial state (by reference).
	 * @param array<string, string> $map          Translated path map.
	 * @param int                   $done_count   Completed API batches.
	 * @param int                   $total_chunks Total API batches.
	 * @return true|\WP_Error
	 */
	private static function commit_elementor_chunk_progress_to_site( $post_id, $lang, array $source_data, array &$state, array $map, $done_count, $total_chunks, $gap_fill = false ) {
		$post_id      = absint( $post_id );
		$lang         = sanitize_key( (string) $lang );
		$done_count   = max( 0, absint( $done_count ) );
		$total_chunks = max( 1, absint( $total_chunks ) );
		$gap_fill     = $gap_fill || ! empty( $state['elementor_gap_fill'] );

		if ( $post_id <= 0 || '' === $lang ) {
			return true;
		}

		if ( empty( $map ) && $done_count <= 0 ) {
			return true;
		}

		if ( $gap_fill ) {
			$primary_total = max(
				$total_chunks,
				absint( $state['elementor_primary_batches_done'] ?? 0 ),
				self::read_elementor_slice_cursor_total( $post_id, $lang )
			);
			$total_chunks = max( 1, $primary_total );
			$done_count   = min( $total_chunks, max( $done_count, $total_chunks ) );
		} else {
			$batch_progress = self::compute_elementor_api_batch_progress( $source_data, $map, $state );
			$done_count     = max(
				(int) $batch_progress['done'],
				absint( $done_count ),
				absint( $state['elementor_slices_completed'] ?? 0 ),
				absint( $state['elementor_chunk_index'] ?? 0 )
			);
			$total_chunks = max( 1, (int) $batch_progress['total'], absint( $total_chunks ) );
		}

		if ( $done_count <= 0 && empty( $map ) ) {
			return true;
		}

		$state['elementor_chunk_index']      = $done_count;
		$state['elementor_slices_completed'] = $done_count;
		$state['elementor_chunks_total']     = $total_chunks;

		$persisted = self::persist_elementor_job_progress(
			$post_id,
			$lang,
			$source_data,
			$map,
			$done_count,
			$total_chunks,
			false
		);

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		if ( $done_count > 0 ) {
			self::write_elementor_slice_cursor( $post_id, $lang, $done_count, $total_chunks );
		}

		self::save_job_partial_state( $post_id, $lang, $state );
		\PolymartAI\Activity_Logger::sync_elementor_partial_progress( $post_id, $lang );

		return true;
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @param int    $cursor  Completed API batches (monotonic).
	 * @param int    $total   Total API batches.
	 * @return void
	 */
	private static function write_elementor_slice_cursor( $post_id, $lang, $cursor, $total = 0 ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$cursor  = absint( $cursor );

		if ( $post_id <= 0 || '' === $lang || $cursor <= 0 ) {
			return;
		}

		$key     = self::get_elementor_slice_cursor_meta_key( $lang );
		$stored  = get_post_meta( $post_id, $key, true );
		$current = is_array( $stored ) ? absint( $stored['cursor'] ?? 0 ) : absint( $stored );
		$cursor  = max( $current, $cursor );
		$total   = max( absint( $total ), absint( is_array( $stored ) ? ( $stored['total'] ?? 0 ) : 0 ), 1 );

		if ( $cursor > $total ) {
			$cursor = $total;
		}

		update_post_meta(
			$post_id,
			$key,
			array(
				'cursor' => $cursor,
				'total'  => $total,
			)
		);
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return void
	 */
	private static function clear_elementor_slice_cursor( $post_id, $lang ) {
		delete_post_meta( absint( $post_id ), self::get_elementor_slice_cursor_meta_key( sanitize_key( (string) $lang ) ) );
	}

	/**
	 * Whether every field in an expanded API chunk is already present in the saved map.
	 *
	 * @param array<string, string>  $chunk           Expanded chunk payload.
	 * @param array<string, string>  $map             Path map including saved JSON.
	 * @param array<string, string>  $source_payload  Full source field map.
	 * @return bool
	 */
	private static function elementor_chunk_is_satisfied( array $chunk, array $map, array $source_payload ) {
		if ( empty( $chunk ) ) {
			return false;
		}

		foreach ( $chunk as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( preg_match( '/^(.+)::__part\d+$/', $path, $matches ) ) {
				$base        = $matches[1];
				$source_text = (string) ( $source_payload[ $base ] ?? $text );

				if ( isset( $map[ $path ] ) && '' !== trim( (string) $map[ $path ] ) && ! Persian_Detector::contains_persian( (string) $map[ $path ] ) ) {
					continue;
				}

				if ( self::elementor_field_translation_complete( $base, $source_text, $map ) ) {
					continue;
				}

				return false;
			}

			$source_text = (string) ( $source_payload[ $path ] ?? $text );

			if ( ! self::elementor_field_translation_complete( $path, $source_text, $map ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether every path in an API chunk has a non-Persian translation in the map.
	 *
	 * @param array<string, string> $chunk Path => source text.
	 * @param array<string, string> $map   Path map.
	 * @return bool
	 */
	private static function elementor_chunk_paths_translated( array $chunk, array $map, array $source_payload = array() ) {
		foreach ( $chunk as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( preg_match( '/^(.+)::__part\d+$/', $path, $matches ) ) {
				$base = $matches[1];

				if ( isset( $map[ $path ] ) && self::elementor_map_value_is_valid_translation( $path, $text, (string) $map[ $path ] ) ) {
					continue;
				}

				$base_text = (string) ( $source_payload[ $base ] ?? $chunk[ $base ] ?? $text );

				if ( ! self::elementor_field_translation_complete( $base, $base_text, $map ) ) {
					return false;
				}

				continue;
			}

			if ( preg_match( '/^(.+)::__seg\d+$/', $path, $matches ) ) {
				$base = $matches[1];

				if ( isset( $map[ $path ] ) && self::elementor_map_value_is_valid_translation( $path, $text, (string) $map[ $path ] ) ) {
					continue;
				}

				$base_text = (string) ( $source_payload[ $base ] ?? $chunk[ $base ] ?? '' );

				if ( '' === $base_text ) {
					return false;
				}

				if ( ! self::elementor_field_translation_complete( $base, $base_text, $map ) ) {
					return false;
				}

				continue;
			}

			if ( ! self::elementor_field_translation_complete( $path, $text, $map ) ) {
				return false;
			}
		}

		return ! empty( $chunk );
	}

	/**
	 * Completed Elementor API batches derived from remaining unique texts (not cursor meta).
	 *
	 * @param array<string, mixed>  $source_data Source Elementor tree.
	 * @param array<string, string> $map         Translation path map.
	 * @param array<string, mixed>  $state       Partial state.
	 * @return array{done: int, total: int}
	 */
	private static function compute_elementor_api_batch_progress( array $source_data, array $map, array $state = array() ) {
		$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$payload   = self::collect_elementor_translation_payload( $source_data );
		$remaining = self::filter_remaining_elementor_payload( $payload, $map, $skipped );
		$collapsed_all       = self::collapse_duplicate_elementor_payload( $payload )['payload'];
		$collapsed_remaining = self::collapse_duplicate_elementor_payload( $remaining )['payload'];
		$all_chunks          = self::chunk_elementor_payload_for_job( $collapsed_all );
		$left_chunks         = self::chunk_elementor_payload_for_job( $collapsed_remaining );

		return array(
			'done'  => max( 0, count( $all_chunks ) - count( $left_chunks ) ),
			'total' => max( 1, count( $all_chunks ) ),
		);
	}

	/**
	 * First API batch index that still needs work, inferred from saved Elementor JSON.
	 *
	 * @param int                    $post_id         Post ID.
	 * @param string                 $lang            Language code.
	 * @param array<string, mixed>   $source_data     Source tree.
	 * @param array<string, string>  $map             Translation path map.
	 * @return int
	 */
	private static function infer_elementor_slice_cursor_from_saved( $post_id, $lang, array $source_data, array $map ) {
		unset( $post_id, $lang );

		$source_payload = self::collect_elementor_translation_payload( $source_data );
		$all_chunks     = self::chunk_elementor_payload_for_job(
			self::collapse_duplicate_elementor_payload( $source_payload )['payload']
		);

		foreach ( $all_chunks as $index => $chunk ) {
			if ( ! self::elementor_chunk_is_satisfied( $chunk, $map, $source_payload ) ) {
				return absint( $index );
			}
		}

		return count( $all_chunks );
	}

	/**
	 * Drop a stale Elementor API cursor when it outran saved translations.
	 *
	 * @param int                    $post_id     Post ID.
	 * @param string                 $lang        Language code.
	 * @param array<string, mixed>   $source_data Source Elementor tree.
	 * @param array<string, string>  $map         Translation path map.
	 * @return int Reconciled completed batch index.
	 */
	private static function reconcile_elementor_slice_cursor( $post_id, $lang, array $source_data, array $map ) {
		$inferred = self::infer_elementor_slice_cursor_from_saved( $post_id, $lang, $source_data, $map );
		$stored   = self::read_elementor_slice_cursor( $post_id, $lang );

		if ( $stored <= 0 ) {
			return $inferred;
		}

		// During bulk jobs the in-memory map advances before _elementor_data_{lang} is fully
		// readable — never roll the API batch cursor backward (that re-translates chunk 1–3).
		if ( \PolymartAI\Activity_Logger::is_bulk_job_running() ) {
			return max( $stored, $inferred );
		}

		$translated_paths = 0;

		foreach ( $map as $value ) {
			$value = trim( (string) $value );

			if ( '' !== $value && ! Persian_Detector::contains_persian( $value ) ) {
				++$translated_paths;
			}
		}

		if ( $stored > $inferred && $stored > $translated_paths ) {
			self::clear_elementor_slice_cursor( $post_id, $lang );
			delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );

			$state = self::get_job_partial_state( $post_id, $lang );

			if ( is_array( $state ) ) {
				$state['elementor_chunk_index']      = $inferred;
				$state['elementor_slices_completed'] = $inferred;
				self::save_job_partial_state( $post_id, $lang, $state );
			}

			return $inferred;
		}

		return max( $stored, $inferred );
	}

	/**
	 * Resolve the next Elementor API batch index (never rolls backward).
	 *
	 * @param int                  $post_id     Post ID.
	 * @param string               $lang        Language code.
	 * @param array<string, mixed> $source_data Source tree.
	 * @param array<string, mixed> $state       Hydrated partial state.
	 * @return int
	 */
	private static function resolve_elementor_slice_cursor( $post_id, $lang, array $source_data, array $state ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		$map = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$map = self::merge_elementor_path_map( $post_id, $lang, $source_data, $map );
		self::reconcile_elementor_slice_cursor( $post_id, $lang, $source_data, $map );

		$batch_progress = self::compute_elementor_api_batch_progress( $source_data, $map, $state );

		return min( (int) $batch_progress['done'], (int) $batch_progress['total'] );
	}

	/**
	 * Whether scheduled Elementor API batches are still pending for a job post.
	 *
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @param array<string, mixed> $state   Optional hydrated partial state.
	 * @return bool
	 */
	public static function elementor_job_api_slices_pending( $post_id, $lang, array $state = array() ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		if ( self::elementor_job_api_schedule_complete( $post_id, $lang ) ) {
			return false;
		}

		if ( empty( $state ) ) {
			$state = self::hydrate_elementor_job_partial_state(
				$post_id,
				$lang,
				self::get_job_partial_state( $post_id, $lang )
			);
		}

		$progress = self::get_elementor_chunk_progress( $post_id, $lang, $state );
		$done     = self::resolve_elementor_done_count( $state, $progress['done'], $post_id, $lang );

		return $done < max( 1, (int) $progress['total'] );
	}

	/**
	 * Whether every scheduled Elementor API batch for this post has been processed.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $lang         Language code.
	 * @param int    $done_count   Optional completed batch count.
	 * @param int    $total_chunks Optional total batch count.
	 * @return bool
	 */
	public static function elementor_job_api_schedule_complete( $post_id, $lang, $done_count = null, $total_chunks = null ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$stored = get_post_meta( $post_id, self::get_elementor_slice_cursor_meta_key( $lang ), true );
		$cursor = is_array( $stored ) ? absint( $stored['cursor'] ?? 0 ) : absint( $stored );
		$total  = is_array( $stored ) ? absint( $stored['total'] ?? 0 ) : 0;

		if ( null !== $done_count ) {
			$cursor = max( $cursor, absint( $done_count ) );
		}

		if ( null !== $total_chunks ) {
			$total = max( $total, absint( $total_chunks ) );
		}

		if ( $total <= 0 || $cursor < $total ) {
			return false;
		}

		// A stale cursor can read "complete" while Persian source fields are still pending.
		if ( self::uses_elementor_builder( $post_id ) ) {
			$raw = get_post_meta( $post_id, '_elementor_data', true );

			if ( is_string( $raw ) && '' !== trim( $raw ) ) {
				$data = json_decode( $raw, true );

				if ( is_array( $data ) ) {
					$state = self::hydrate_elementor_job_partial_state(
						$post_id,
						$lang,
						self::get_job_partial_state( $post_id, $lang )
					);
					$state_map = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
					$map       = array_merge(
						self::rebuild_elementor_map_from_saved_translation( $post_id, $lang, $data ),
						$state_map
					);
					$skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
					$remaining = self::filter_remaining_elementor_payload(
						self::collect_elementor_translation_payload( $data ),
						$map,
						$skipped
					);

					if ( ! empty( $remaining ) ) {
						return false;
					}
				}
			}
		}

		return true;
	}

	/**
	 * Whether every scheduled primary Elementor API batch index has been consumed.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	public static function elementor_job_primary_batches_exhausted( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$stored = get_post_meta( $post_id, self::get_elementor_slice_cursor_meta_key( $lang ), true );
		$cursor = is_array( $stored ) ? absint( $stored['cursor'] ?? 0 ) : absint( $stored );
		$total  = is_array( $stored ) ? absint( $stored['total'] ?? 0 ) : 0;

		return $total > 0 && $cursor >= $total;
	}

	/**
	 * Primary API batches are done but Persian Elementor fields still need translation.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	public static function elementor_needs_gap_fill_work( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		if ( ! self::elementor_job_primary_batches_exhausted( $post_id, $lang ) ) {
			return false;
		}

		return self::elementor_job_has_remaining_payload( $post_id, $lang );
	}

	/**
	 * Reasons Elementor job finalization must not mark the post complete yet.
	 *
	 * @param int                   $post_id     Post ID.
	 * @param string                $lang        Language code.
	 * @param array<string, mixed>  $source_data Source Elementor tree.
	 * @param array<string, string> $map         Translation path map.
	 * @param array<string, mixed>  $state       Partial state.
	 * @return array<string, mixed>|null Blocker details, or null when safe to finalize.
	 */
	private static function get_elementor_job_finalize_blockers( $post_id, $lang, array $source_data, array $map, array $state ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$skipped = is_array( $state['elementor_skipped'] ?? null )
			? array_values( array_filter( array_map( 'strval', $state['elementor_skipped'] ) ) )
			: array();

		if ( ! empty( $skipped ) ) {
			return array(
				'code'    => 'skipped_fields',
				'message' => sprintf(
					/* translators: 1: post ID, 2: skipped field count */
					__( 'Elementor — #%1$d: %2$d فیلد پس از خطای API رد شد — ترجمه ناقص است.', 'polymart-ai' ),
					$post_id,
					count( $skipped )
				),
				'skipped' => $skipped,
			);
		}

		$remaining = self::filter_remaining_elementor_payload(
			self::collect_elementor_translation_payload( $source_data ),
			$map,
			array()
		);

		if ( ! empty( $remaining ) ) {
			return array(
				'code'      => 'untranslated_fields',
				'message'   => sprintf(
					/* translators: 1: post ID, 2: remaining field count */
					__( 'Elementor — #%1$d: %2$d فیلد هنوز ترجمه نشده — نهایی‌سازی متوقف شد.', 'polymart-ai' ),
					$post_id,
					count( $remaining )
				),
				'remaining' => count( $remaining ),
			);
		}

		$preview_tree     = self::apply_elementor_translation_payload( $source_data, $map );
		$preview_persian  = self::collect_elementor_translation_payload( $preview_tree );

		if ( ! empty( $preview_persian ) ) {
			return array(
				'code'    => 'stored_persian',
				'message' => sprintf(
					/* translators: 1: post ID, 2: remaining Persian field count */
					__( 'Elementor — #%1$d: %2$d فیلد هنوز فارسی در JSON نهایی دارد — نهایی‌سازی متوقف شد.', 'polymart-ai' ),
					$post_id,
					count( $preview_persian )
				),
			);
		}

		return null;
	}

	/**
	 * Persist partial Elementor progress and keep the job resumable after a blocked finalize.
	 *
	 * @param int                   $post_id      Post ID.
	 * @param string                $lang         Language code.
	 * @param array<string, mixed>  $source_data  Source Elementor tree.
	 * @param array<string, string> $map          Translation path map.
	 * @param array<string, mixed>  $state        Partial state (by reference).
	 * @param int                   $done_count   Completed batches.
	 * @param int                   $total_chunks Total batches.
	 * @param array<string, mixed>  $blocker      Blocker payload from get_elementor_job_finalize_blockers().
	 * @return array{done: bool, phase: string, phase_progress: string, message: string, recoverable: bool}|\WP_Error
	 */
	private static function respond_elementor_blocked_finalize( $post_id, $lang, array $source_data, array $map, array &$state, $done_count, $total_chunks, array $blocker ) {
		$post_id      = absint( $post_id );
		$lang         = sanitize_key( (string) $lang );
		$done_count   = max( 0, absint( $done_count ) );
		$total_chunks = max( 1, absint( $total_chunks ) );
		$message      = (string) ( $blocker['message'] ?? __( 'ترجمه Elementor ناقص است.', 'polymart-ai' ) );

		\PolymartAI\Activity_Logger::log(
			'warning',
			$message,
			array(
				'post_id' => $post_id,
				'lang'    => $lang,
				'code'    => (string) ( $blocker['code'] ?? 'elementor_incomplete' ),
			)
		);

		update_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, $message );

		$batch_progress = self::compute_elementor_api_batch_progress( $source_data, $map, $state );
		$done_count     = max( $done_count, (int) $batch_progress['done'] );
		$total_chunks   = max( $total_chunks, (int) $batch_progress['total'], 1 );

		$persisted = self::persist_elementor_job_progress( $post_id, $lang, $source_data, $map, $done_count, $total_chunks );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		unset( $state['elementor_skipped'], $state['elementor_failures'] );
		$state['phase'] = 'elementor';
		self::save_job_partial_state( $post_id, $lang, $state );
		self::flush_translation_status_cache( $post_id );

		return array(
			'done'           => false,
			'phase'          => 'elementor',
			'phase_progress' => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
			'message'        => $message,
			'recoverable'    => true,
		);
	}

	/**
	 * Finalize Elementor job slice after all API batches (best-effort when minor fields remain).
	 *
	 * @param int                   $post_id      Post ID.
	 * @param string                $lang         Language code.
	 * @param array<string, mixed>  $source_data  Source Elementor tree.
	 * @param array<string, string> $map          Translated path map.
	 * @param array<string, mixed>  $state        Partial state (by reference).
	 * @param int                   $done_count   Completed batches.
	 * @param int                   $total_chunks Total batches.
	 * @param array<string, string> $remaining    Optional remaining source payload.
	 * @return array{done: bool, phase: string, phase_progress: string, message: string}|\WP_Error
	 */
	private static function finalize_elementor_job_slice( $post_id, $lang, array $source_data, array $map, array &$state, $done_count, $total_chunks, array $remaining = array() ) {
		$post_id      = absint( $post_id );
		$lang         = sanitize_key( (string) $lang );
		$done_count   = max( 0, absint( $done_count ) );
		$total_chunks = max( 1, absint( $total_chunks ) );

		if ( empty( $remaining ) ) {
			$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
			$remaining = self::filter_remaining_elementor_payload(
				self::collect_elementor_translation_payload( $source_data ),
				$map,
				$skipped
			);
		}

		if ( ! empty( $remaining ) ) {
			if ( self::elementor_job_primary_batches_exhausted( $post_id, $lang ) ) {
				$stored_total  = self::read_elementor_slice_cursor_total( $post_id, $lang );
				$stored_cursor = self::read_elementor_slice_cursor( $post_id, $lang );
				$done_count    = max( $done_count, $stored_cursor );
				$total_chunks  = max( $total_chunks, $stored_total, 1 );

				return array(
					'done'           => false,
					'phase'          => 'elementor',
					'phase_progress' => min( $done_count, $total_chunks ) . '/' . $total_chunks,
					'message'        => self::format_elementor_gap_fill_remaining_message( $post_id, $remaining, $map ),
					'recoverable'    => true,
				);
			}

			$batch_progress = self::compute_elementor_api_batch_progress( $source_data, $map, $state );
			$done_count     = (int) $batch_progress['done'];
			$total_chunks   = max( 1, (int) $batch_progress['total'], $total_chunks );
			$persisted      = self::persist_elementor_job_progress( $post_id, $lang, $source_data, $map, $done_count, $total_chunks );

			if ( is_wp_error( $persisted ) ) {
				return $persisted;
			}

			self::save_job_partial_state( $post_id, $lang, $state );

			return array(
				'done'           => false,
				'phase'          => 'elementor',
				'phase_progress' => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
				'message'        => self::format_elementor_job_slice_status_message( $post_id, $lang, $state ),
			);
		}

		$blocker = self::get_elementor_job_finalize_blockers( $post_id, $lang, $source_data, $map, $state );

		if ( null !== $blocker ) {
			return self::respond_elementor_blocked_finalize( $post_id, $lang, $source_data, $map, $state, $done_count, $total_chunks, $blocker );
		}

		\PolymartAI\Activity_Logger::log(
			'info',
			sprintf(
				/* translators: 1: post ID, 2: completed batches, 3: total batches */
				__( 'Elementor — #%1$d: همه %2$d از %3$d بخش ذخیره شد — نهایی‌سازی.', 'polymart-ai' ),
				$post_id,
				$done_count,
				$total_chunks
			),
			array( 'post_id' => $post_id, 'lang' => $lang )
		);

		$persisted = self::persist_elementor_job_progress( $post_id, $lang, $source_data, $map, $done_count, $total_chunks, true );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		self::clear_elementor_slice_cursor( $post_id, $lang );
		self::clear_job_partial_state( $post_id, $lang );
		self::flush_translation_status_cache( $post_id );

		return array(
			'done'           => true,
			'phase'          => 'elementor',
			'phase_progress' => '',
			'message'        => __( 'ترجمه Elementor تکمیل شد.', 'polymart-ai' ),
		);
	}

	/**
	 * Chunk-based Elementor progress (remaining payload → done/total).
	 *
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @param array<string, mixed> $state   Hydrated partial state.
	 * @return array{done: int, total: int}
	 */
	private static function get_elementor_chunk_progress( $post_id, $lang, array $state ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$done    = 0;
		$total   = 1;

		if ( $post_id > 0 && '' !== $lang ) {
			$raw = get_post_meta( $post_id, '_elementor_data', true );

			if ( is_string( $raw ) && '' !== trim( $raw ) ) {
				$data = json_decode( $raw, true );

				if ( is_array( $data ) ) {
					$map = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
					$map = self::merge_elementor_path_map( $post_id, $lang, $data, $map );
					self::reconcile_elementor_slice_cursor( $post_id, $lang, $data, $map );

					$batch_progress = self::compute_elementor_api_batch_progress( $data, $map, $state );
					$done           = (int) $batch_progress['done'];
					$total          = (int) $batch_progress['total'];
				}
			}
		}

		return array(
			'done'  => $done,
			'total' => $total,
		);
	}

	/**
	 * Count durable Elementor slice progress for UI markers.
	 *
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @param array<string, mixed> $state   Hydrated partial state.
	 * @return int
	 */
	private static function count_elementor_job_progress_done( $post_id, $lang, array $state ) {
		return self::get_elementor_chunk_progress( $post_id, $lang, $state )['done'];
	}

	/**
	 * Human-readable Elementor job progress marker (e.g. 3/8).
	 *
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @param array<string, mixed> $state   Partial state.
	 * @return string
	 */
	public static function format_elementor_job_progress_marker( $post_id, $lang, array $state = array() ) {
		$state    = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
		$progress = self::get_elementor_chunk_progress( $post_id, $lang, $state );
		$done     = (int) $progress['done'];
		$total    = max( 1, (int) $progress['total'] );

		$cursor       = self::read_elementor_slice_cursor( $post_id, $lang );
		$stored_total = self::read_elementor_slice_cursor_total( $post_id, $lang );

		if ( $stored_total > 0 ) {
			$total = max( $total, $stored_total );
		}

		$state_done = max(
			absint( $state['elementor_slices_completed'] ?? 0 ),
			absint( $state['elementor_chunk_index'] ?? 0 )
		);

		$done = min( $total, max( $done, $cursor, $state_done ) );

		$marker = $done . '/' . $total;

		if ( ! empty( $state['elementor_gap_fill'] ) ) {
			$gap_done  = absint( $state['elementor_gap_fill_done'] ?? 0 );
			$gap_total = max( 1, absint( $state['elementor_gap_fill_total'] ?? 0 ), $gap_done );

			if ( ! empty( $state['elementor_gap_fill_stubborn_only'] ) ) {
				$stubborn_left = 0;
				$raw           = get_post_meta( $post_id, '_elementor_data', true );

				if ( is_string( $raw ) && '' !== trim( $raw ) ) {
					$data = json_decode( $raw, true );

					if ( is_array( $data ) ) {
						$source_payload = self::collect_elementor_translation_payload( $data );
						$skipped        = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
						$remaining      = self::filter_remaining_elementor_payload( $source_payload, $state['elementor_map'] ?? array(), $skipped );

						foreach ( $remaining as $path => $text ) {
							if ( self::stubborn_elementor_field_is_long( (string) $path, (string) $text ) ) {
								++$stubborn_left;
							}
						}
					}
				}

				$marker .= ' · ' . sprintf(
					/* translators: 1: completed short gap-fill batches, 2: total short gap-fill batches, 3: stubborn field count */
					__( 'تکمیل %1$d/%2$d · stubborn: %3$d فیلد', 'polymart-ai' ),
					min( $gap_done, $gap_total ),
					$gap_total,
					$stubborn_left
				);
			} else {
				$marker .= ' · ' . sprintf(
					/* translators: 1: completed gap-fill batches, 2: total gap-fill batches */
					__( 'تکمیل %1$d/%2$d', 'polymart-ai' ),
					min( $gap_done, $gap_total ),
					$gap_total
				);
			}
		}

		return $marker;
	}

	/**
	 * Human-readable Elementor slice status using durable chunk/cursor progress.
	 *
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @param array<string, mixed> $state   Optional partial state.
	 * @return string
	 */
	private static function format_elementor_job_slice_status_message( $post_id, $lang, array $state = array() ) {
		$overlay_map     = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$overlay_skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$state           = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );

		if ( ! empty( $overlay_map ) || ! empty( $overlay_skipped ) ) {
			$raw = get_post_meta( absint( $post_id ), '_elementor_data', true );

			if ( is_string( $raw ) && '' !== trim( $raw ) ) {
				$data = json_decode( $raw, true );

				if ( is_array( $data ) ) {
					$source_payload = self::collect_elementor_translation_payload( $data );

					if ( ! empty( $overlay_map ) ) {
						$state['elementor_map'] = array_merge(
							is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array(),
							self::sanitize_elementor_translation_map( $overlay_map, $source_payload )
						);
					}
				}
			}
		}

		if ( ! empty( $overlay_skipped ) ) {
			$state['elementor_skipped'] = $overlay_skipped;
		}

		$marker = self::format_elementor_job_progress_marker( $post_id, $lang, $state );
		$parts  = explode( '/', $marker );
		$done   = isset( $parts[0] ) ? absint( $parts[0] ) : 0;
		$total  = isset( $parts[1] ) ? max( 1, absint( $parts[1] ) ) : 1;

		return sprintf(
			/* translators: 1: completed batches, 2: total batches */
			__( 'Elementor — %1$d از %2$d بخش ذخیره شد', 'polymart-ai' ),
			$done,
			$total
		);
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
	 * Retry individual Elementor fields from a batch when the group response was partial.
	 *
	 * @param array<string, string>  $chunk         Source paths in this API batch.
	 * @param array<string, string>  $mapped_chunk  Accepted translations so far.
	 * @param string                 $api_key       API key.
	 * @param string                 $api_endpoint  API endpoint.
	 * @param string                 $ai_model      Model name.
	 * @param string                 $lang          Target language.
	 * @param array<string, mixed>   $ai_options    HTTP options.
	 * @param array<string, int>     $failures      Failure counts (by reference).
	 * @param array<string, mixed>   $state         Partial state (by reference).
	 * @param int                    $max_retries   Max single-field API calls.
	 * @return array<string, string>
	 */
	private static function retry_untranslated_elementor_chunk_fields(
		array $chunk,
		array $mapped_chunk,
		$api_key,
		$api_endpoint,
		$ai_model,
		$lang,
		array $ai_options,
		array &$failures,
		array &$state,
		$max_retries = 4
	) {
		$max_retries = max( 1, absint( $max_retries ) );
		$attempts    = 0;

		foreach ( $chunk as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( '' === $path ) {
				continue;
			}

			if ( self::elementor_field_translation_complete( $path, $text, $mapped_chunk ) ) {
				continue;
			}

			if ( $attempts >= $max_retries ) {
				break;
			}

			++$attempts;

			$field_payload = self::expand_payload_for_ai( array( $path => $text ) );
			list( $aliased_field, $field_alias_map ) = self::alias_elementor_payload_keys( $field_payload );

			if ( empty( $aliased_field ) ) {
				continue;
			}

			\PolymartAI\Activity_Logger::wait_for_arvan_api_gap();

			$single = AI_Client::translate_fields(
				$aliased_field,
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang,
				$ai_options
			);

			\PolymartAI\Activity_Logger::touch_arvan_api_attempt();

			if ( is_wp_error( $single ) ) {
				$failures[ $path ] = absint( $failures[ $path ] ?? 0 ) + 1;
				continue;
			}

			$single_map = self::unmap_elementor_aliases(
				self::collapse_payload_parts( $single ),
				$field_alias_map
			);

			foreach ( $single_map as $single_path => $translated ) {
				$single_path  = (string) $single_path;
				$translated   = trim( (string) $translated );
				$source_text  = (string) ( $field_payload[ $single_path ] ?? $text );

				if ( preg_match( '/^(.+)::__part\d+$/', $single_path, $matches ) ) {
					$source_text = (string) ( $field_payload[ $matches[1] ] ?? $text );
				}

				if ( ! self::elementor_map_value_is_valid_translation( $single_path, $source_text, $translated ) ) {
					$failures[ $path ] = absint( $failures[ $path ] ?? 0 ) + 1;

					if ( $failures[ $path ] >= 2 ) {
						$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
						$skipped[] = $path;
						$state['elementor_skipped'] = array_values( array_unique( array_map( 'strval', $skipped ) ) );
						unset( $failures[ $path ] );
					}

					continue;
				}

				$mapped_chunk[ $single_path ] = $translated;
			}
		}

		return $mapped_chunk;
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

		if (
			self::post_needs_elementor_job_work( $post_id, $lang )
			|| self::elementor_job_has_remaining_payload( $post_id, $lang )
		) {
			$state = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
			self::save_job_partial_state( $post_id, $lang, $state );

			return array(
				'done'           => false,
				'phase'          => 'elementor',
				'phase_progress' => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
				'message'        => __( 'فیلدها ذخیره شد — ترجمه Elementor در مراحل بعدی', 'polymart-ai' ),
			);
		}

		self::clear_job_partial_state( $post_id, $lang );

		return array(
			'done'           => true,
			'phase'          => 'complete',
			'phase_progress' => '',
			'message'        => __( 'ترجمه این مورد تکمیل شد.', 'polymart-ai' ),
		);
	}

	/**
	 * Refresh the per-post translation lock TTL only when this worker already owns it.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	public static function refresh_translation_lock( $post_id, $lang ) {
		return self::acquire_translation_lock( $post_id, $lang );
	}

	/**
	 * Extend TTL on a per-post lock already owned by this worker.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	public static function touch_translation_lock( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::owns_translation_lock( $post_id, $lang ) ) {
			return false;
		}

		$keys = self::get_translation_lock_keys( $post_id, $lang );
		$now  = time();

		update_option( $keys['claim'], (string) $now, false );
		set_transient( $keys['key'], $now, self::TRANSLATION_LOCK_TTL );

		return true;
	}

	/**
	 * Keep or re-acquire the per-post lock immediately before persisting Elementor JSON.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	private static function ensure_translation_lock_for_persist( $post_id, $lang ) {
		if ( self::owns_translation_lock( $post_id, $lang ) ) {
			return self::touch_translation_lock( $post_id, $lang );
		}

		return self::acquire_translation_lock( $post_id, $lang );
	}

	/**
	 * Drop a per-post translation lock left by a killed PHP worker.
	 *
	 * @param int      $post_id        Post ID.
	 * @param string   $lang           Language code.
	 * @param int|null $max_silent_sec Seconds without worker heartbeat before forcing unlock.
	 * @return bool True when a stale lock was cleared.
	 */
	public static function release_stale_translation_lock( $post_id, $lang, $max_silent_sec = null ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$keys  = self::get_translation_lock_keys( $post_id, $lang );
		$claim = absint( get_option( $keys['claim'], 0 ) );
		$lock  = get_transient( $keys['key'] );

		if ( ! $claim && ! $lock ) {
			return false;
		}

		if ( self::owns_translation_lock( $post_id, $lang ) ) {
			return false;
		}

		$max_silent_sec = null === $max_silent_sec
			? max( 75, self::ELEMENTOR_JOB_REQUEST_TIMEOUT + 25 )
			: max( 45, absint( $max_silent_sec ) );

		$lock_stamp = max( $claim, $lock ? absint( $lock ) : 0 );
		$lock_age   = $lock_stamp > 0 ? ( time() - $lock_stamp ) : PHP_INT_MAX;

		// Fresh lock: only steal when the whole worker is silent (not after recovery heartbeats).
		if ( $lock_age < $max_silent_sec && \PolymartAI\Activity_Logger::is_bulk_worker_lively( $max_silent_sec ) ) {
			return false;
		}

		if ( $lock_age < $max_silent_sec ) {
			return false;
		}

		self::release_translation_lock( $post_id, $lang, true );

		return true;
	}

	/**
	 * Read whether a per-post translation lock is currently held.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return array{held: bool, age_sec: int, owned_by_self: bool}
	 */
	public static function get_translation_lock_status( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return array(
				'held'          => false,
				'age_sec'       => 0,
				'owned_by_self' => false,
			);
		}

		$keys  = self::get_translation_lock_keys( $post_id, $lang );
		$claim = absint( get_option( $keys['claim'], 0 ) );
		$lock  = get_transient( $keys['key'] );
		$stamp = max( $claim, $lock ? absint( $lock ) : 0 );
		$age   = $stamp > 0 ? max( 0, time() - $stamp ) : 0;

		return array(
			'held'          => $stamp > 0 && $age < self::TRANSLATION_LOCK_TTL,
			'age_sec'       => $age,
			'owned_by_self' => self::owns_translation_lock( $post_id, $lang ),
		);
	}

	/**
	 * Prepare the per-post lock for an explicit admin metabox translate action.
	 *
	 * Metabox requests may take over stale locks; they must not fight a live bulk
	 * worker that is actively translating the same post.
	 *
	 * @param int  $post_id      Post ID.
	 * @param string $lang       Language code.
	 * @param bool $force_unlock When true, always clear the lock.
	 * @return bool True when the metabox may acquire the lock.
	 */
	public static function prepare_admin_metabox_translation_lock( $post_id, $lang, $force_unlock = false ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		if ( $force_unlock ) {
			self::release_translation_lock( $post_id, $lang, true );

			return true;
		}

		if ( ! \PolymartAI\Activity_Logger::is_bulk_job_running() ) {
			self::release_stale_translation_lock( $post_id, $lang, 90 );
		}

		$status = self::get_translation_lock_status( $post_id, $lang );

		if ( ! $status['held'] || $status['owned_by_self'] ) {
			return true;
		}

		$bulk_on_post = false;

		if ( \PolymartAI\Activity_Logger::is_bulk_job_running() ) {
			$job    = \PolymartAI\Activity_Logger::get_job( false );
			$pinned = absint( $job['partial_post_id'] ?? 0 );

			if ( $pinned <= 0 ) {
				$pinned = absint( $job['current_post_id'] ?? 0 );
			}

			$bulk_on_post = ( $pinned === $post_id );
		}

		if (
			$bulk_on_post
			&& (int) $status['age_sec'] < 30
			&& \PolymartAI\Activity_Logger::is_bulk_worker_lively( 30 )
		) {
			return false;
		}

		self::release_translation_lock( $post_id, $lang, true );

		return true;
	}

	/**
	 * Reset Elementor API cursor/progress when it no longer matches saved translations.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return bool True when stale progress was repaired.
	 */
	public static function repair_stale_elementor_job_state( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return false;
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return false;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return false;
		}

		$state     = self::get_job_partial_state( $post_id, $lang );
		$state     = is_array( $state ) ? $state : array();
		$map       = self::merge_elementor_job_path_map( $post_id, $lang, $data, $state );
		$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$source    = self::collect_elementor_translation_payload( $data );
		$remaining = self::filter_remaining_elementor_payload( $source, $map, $skipped );
		$all_chunks = self::chunk_elementor_payload_for_job( $source );

		if ( empty( $remaining ) ) {
			return false;
		}

		$translated_count = max( 0, count( $source ) - count( $remaining ) );
		$hydrated_state   = array_merge( $state, array( 'elementor_map' => $map ) );
		$progress_done    = (int) self::get_elementor_chunk_progress( $post_id, $lang, $hydrated_state )['done'];
		$stored_total     = self::read_elementor_slice_cursor_total( $post_id, $lang );
		$chunk_total      = max( 1, count( $all_chunks ) );
		$cursor           = self::resolve_elementor_slice_cursor( $post_id, $lang, $data, $hydrated_state );
		$pending_chunks   = count( array_slice( $all_chunks, $cursor ) );

		$stale = $progress_done > $translated_count
			|| $stored_total > $chunk_total
			|| ( 0 === $pending_chunks && count( $remaining ) > 0 );

		if ( ! $stale ) {
			return false;
		}

		self::clear_elementor_slice_cursor( $post_id, $lang );
		delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );

		$batch_progress = self::compute_elementor_api_batch_progress( $data, $map, $state );

		$state['phase']                      = 'elementor';
		$state['elementor_map']              = $map;
		$state['elementor_chunk_index']      = (int) $batch_progress['done'];
		$state['elementor_slices_completed'] = (int) $batch_progress['done'];
		$state['elementor_chunks_total']     = (int) $batch_progress['total'];

		if ( $translated_count <= 0 ) {
			unset( $state['elementor_failures'], $state['elementor_skipped'] );
		}

		self::save_job_partial_state( $post_id, $lang, $state );

		return true;
	}

	/**
	 * Rebuild the Elementor API queue when the cursor overshot real progress.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool True when the queue was unblocked.
	 */
	public static function unblock_elementor_chunk_queue( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return false;
		}

		if ( self::repair_stale_elementor_job_state( $post_id, $lang ) ) {
			return true;
		}

		// Primary API schedule finished — gap-fill runs without wiping cursor/progress.
		if ( self::elementor_job_primary_batches_exhausted( $post_id, $lang ) ) {
			return false;
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return false;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return false;
		}

		$state = self::hydrate_elementor_job_partial_state(
			$post_id,
			$lang,
			self::get_job_partial_state( $post_id, $lang )
		);
		$map       = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$source    = self::collect_elementor_translation_payload( $data );
		$remaining = self::filter_remaining_elementor_payload( $source, $map, $skipped );

		if ( empty( $remaining ) ) {
			return false;
		}

		$chunk_queue = self::build_elementor_job_chunk_queue( $post_id, $lang, $data, $state );

		if ( ! empty( $chunk_queue['pending'] ) ) {
			return false;
		}

		self::clear_elementor_slice_cursor( $post_id, $lang );
		delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );

		$batch_progress = self::compute_elementor_api_batch_progress( $data, $map, $state );
		$state['phase']                      = 'elementor';
		$state['elementor_map']              = $map;
		$state['elementor_chunk_index']      = (int) $batch_progress['done'];
		$state['elementor_slices_completed'] = (int) $batch_progress['done'];
		$state['elementor_chunks_total']     = (int) $batch_progress['total'];
		unset( $state['elementor_failures'] );

		self::save_job_partial_state( $post_id, $lang, $state );

		\PolymartAI\Activity_Logger::log(
			'info',
			sprintf(
				/* translators: 1: post ID, 2: remaining field count */
				__( 'Elementor — #%1$d: صف API بازسازی شد — %2$d فیلد در انتظار', 'polymart-ai' ),
				$post_id,
				count( $remaining )
			),
			array( 'post_id' => $post_id, 'lang' => $lang )
		);

		return true;
	}

	/**
	 * Elementor diagnostics for the page edit metabox scan panel.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return array<string, mixed>
	 */
	public static function get_elementor_scan_diagnostics( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$empty   = array(
			'active'                 => false,
			'source_field_count'     => 0,
			'remaining_field_count'  => 0,
			'translated_field_count' => 0,
			'chunk_done'             => 0,
			'chunk_total'            => 0,
			'chunk_progress'         => '',
			'partial_phase'          => '',
			'has_saved_json'         => false,
			'saved_json_has_persian' => false,
			'remaining_samples'      => array(),
			'lock'                   => self::get_translation_lock_status( $post_id, $lang ),
			'bulk_job_running'       => \PolymartAI\Activity_Logger::is_bulk_job_running(),
			'bulk_job_on_post'       => false,
		);

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return $empty;
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array_merge(
				$empty,
				array(
					'active' => true,
					'error'  => __( 'داده Elementor (_elementor_data) خالی است.', 'polymart-ai' ),
				)
			);
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return array_merge(
				$empty,
				array(
					'active' => true,
					'error'  => __( 'JSON Elementor نامعتبر است.', 'polymart-ai' ),
				)
			);
		}

		$state     = self::get_job_partial_state( $post_id, $lang );
		$state     = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
		$map       = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$source    = self::collect_elementor_translation_payload( $data );
		$remaining = self::filter_remaining_elementor_payload( $source, $map, $skipped );
		$chunk_queue = self::build_elementor_job_chunk_queue( $post_id, $lang, $data, $state );
		$progress    = self::get_elementor_chunk_progress( $post_id, $lang, $state );
		$samples     = array();

		foreach ( array_slice( $remaining, 0, 8, true ) as $path => $text ) {
			$samples[] = array(
				'path'    => (string) $path,
				'preview' => wp_trim_words( wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 14, '…' ),
			);
		}

		$bulk_on_post = false;

		if ( \PolymartAI\Activity_Logger::is_bulk_job_running() ) {
			$job    = \PolymartAI\Activity_Logger::get_job( false );
			$pinned = absint( $job['partial_post_id'] ?? 0 );

			if ( $pinned <= 0 ) {
				$pinned = absint( $job['current_post_id'] ?? 0 );
			}

			$bulk_on_post = ( $pinned === $post_id );
		}

		$saved_raw = get_post_meta( $post_id, self::get_elementor_meta_key( $lang ), true );
		$state_map_count = 0;

		foreach ( $map as $value ) {
			$value = trim( (string) $value );

			if ( '' !== $value && ! Persian_Detector::contains_persian( $value ) ) {
				++$state_map_count;
			}
		}

		$api_cooldown_remaining = \PolymartAI\Activity_Logger::get_job_api_cooldown_remaining();
		$stale_cursor           = (int) $progress['done'] > max( 0, count( $source ) - count( $remaining ) ) && count( $remaining ) > 0;
		$collapsed              = self::collapse_duplicate_elementor_payload( $source );
		$api_batches            = self::chunk_elementor_payload_for_job( $collapsed['payload'] );
		$long_field_count       = 0;
		$long_remaining_count   = 0;

		foreach ( $source as $text ) {
			if ( mb_strlen( (string) $text ) > 300 ) {
				++$long_field_count;
			}
		}

		foreach ( $remaining as $text ) {
			if ( mb_strlen( (string) $text ) > 300 ) {
				++$long_remaining_count;
			}
		}

		$filtered_probe         = self::collect_elementor_filtered_out_payload( $data );
		$api_payload_samples    = array();
		$sample_index           = 0;

		foreach ( array_slice( $collapsed['payload'], 0, 4, true ) as $path => $text ) {
			$api_payload_samples[] = array(
				'key'     => 'el_' . $sample_index,
				'preview' => wp_trim_words( wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 16, '…' ),
			);
			++$sample_index;
		}

		return array(
			'active'                 => true,
			'source_field_count'     => count( $source ),
			'unique_text_count'      => count( $collapsed['payload'] ),
			'duplicate_field_count'  => max( 0, count( $source ) - count( $collapsed['payload'] ) ),
			'api_batch_count'        => max( 1, count( $api_batches ) ),
			'api_payload_samples'    => $api_payload_samples,
			'remaining_field_count'  => count( $remaining ),
			'translated_field_count' => max( 0, count( $source ) - count( $remaining ) ),
			'state_map_field_count'  => $state_map_count,
			'chunk_done'             => (int) $progress['done'],
			'chunk_total'            => max( 1, (int) $progress['total'], (int) $chunk_queue['total'] ),
			'chunk_progress'         => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
			'partial_phase'          => (string) ( $state['phase'] ?? '' ),
			'pending_api_chunks'     => count( $chunk_queue['pending'] ),
			'has_saved_json'         => is_string( $saved_raw ) && '' !== trim( $saved_raw ),
			'saved_json_has_persian' => self::stored_elementor_translation_has_persian( $post_id, $lang ),
			'remaining_samples'      => $samples,
			'long_field_count'       => $long_field_count,
			'long_remaining_count'   => $long_remaining_count,
			'filtered_out_count'     => count( $filtered_probe ),
			'filtered_out_samples'   => array_slice(
				array_map(
					static function ( $item ) {
						return array(
							'path'    => (string) ( $item['path'] ?? '' ),
							'preview' => wp_trim_words( wp_strip_all_tags( html_entity_decode( (string) ( $item['preview'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 12, '…' ),
						);
					},
					array_values( $filtered_probe )
				),
				0,
				4
			),
			'stale_api_cursor'       => $stale_cursor,
			'api_cooldown_active'    => $api_cooldown_remaining > 0,
			'api_cooldown_remaining' => $api_cooldown_remaining,
			'lock'                   => self::get_translation_lock_status( $post_id, $lang ),
			'bulk_job_running'       => \PolymartAI\Activity_Logger::is_bulk_job_running(),
			'bulk_job_on_post'       => $bulk_on_post,
			'error'                  => (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true ),
		);
	}

	/**
	 * Whether every Elementor field is translated and persisted for a job slice.
	 *
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @param array<string, mixed> $state   Optional partial state.
	 * @return bool
	 */
	public static function elementor_job_slice_is_truly_complete( $post_id, $lang, array $state = array() ) {
		unset( $state );

		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return true;
		}

		if ( self::stored_elementor_translation_has_persian( $post_id, $lang ) ) {
			return false;
		}

		return ! self::elementor_job_has_remaining_payload( $post_id, $lang );
	}

	/**
	 * Whether Elementor JSON still has untranslated fields for a job resume.
	 *
	 * Uses the same path map logic as job slices — partial saves with a current
	 * source hash must still return true until every Persian field is covered.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	public static function elementor_job_has_remaining_payload( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return false;
		}

		if ( self::elementor_job_api_schedule_complete( $post_id, $lang ) ) {
			return false;
		}

		if ( ! self::should_require_elementor_translation( $post_id ) ) {
			return false;
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return false;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return false;
		}

		$state     = self::get_job_partial_state( $post_id, $lang );
		$map       = self::merge_elementor_job_path_map( $post_id, $lang, $data, $state );
		$skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();

		$remaining = self::filter_remaining_elementor_payload(
			self::collect_elementor_translation_payload( $data ),
			$map,
			$skipped
		);

		return ! empty( $remaining );
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

		if ( self::elementor_job_api_schedule_complete( $post_id, $lang ) ) {
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
			if ( self::stored_elementor_translation_has_persian( $post_id, $lang ) ) {
				return true;
			}

			if ( self::elementor_job_has_remaining_payload( $post_id, $lang ) ) {
				return true;
			}

			$previous_error = (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true );

			if ( self::is_elementor_progress_message( $previous_error ) ) {
				delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
				delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );

				return self::elementor_job_has_remaining_payload( $post_id, $lang );
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

		if ( ! function_exists( 'wp_get_current_user' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && function_exists( 'current_user_can' ) && current_user_can( REST_API::required_admin_capability() ) ) {
			return true;
		}

		// Site admins running the bulk job may translate any queued post,
		// even when they lack per-post edit caps (custom types / other authors).
		if (
			function_exists( 'current_user_can' )
			&& current_user_can( REST_API::required_admin_capability() )
			&& \PolymartAI\Activity_Logger::is_bulk_job_running()
		) {
			return true;
		}

		if ( ! function_exists( 'current_user_can' ) ) {
			return \PolymartAI\Activity_Logger::is_bulk_job_running();
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
	public static function process_job_translation_slice( $post_id, $lang, $manage_lock = true ) {
		$post_id     = absint( $post_id );
		$lang        = sanitize_key( (string) $lang );
		$manage_lock = (bool) $manage_lock;

		if ( ! $post_id || ! self::can_translate_post( $post_id ) ) {
			$message = __( 'شما اجازه ترجمه این مورد را ندارید.', 'polymart-ai' );

			if ( \PolymartAI\Activity_Logger::should_bypass_browser_auth_checks() ) {
				$message = sprintf(
					/* translators: 1: current user ID */
					__( 'Worker پس‌زمینه نتوانست دسترسی ترجمه را تأیید کند (User ID: %1$d).', 'polymart-ai' ),
					get_current_user_id()
				);
			}

			return new \WP_Error(
				'polymart_ai_forbidden',
				$message
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

		if ( $manage_lock && ! self::acquire_translation_lock( $post_id, $lang ) ) {
			return new \WP_Error(
				'polymart_ai_translation_in_progress',
				__( 'این مورد در حال ترجمه است. لطفاً چند لحظه صبر کنید.', 'polymart-ai' )
			);
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		try {
			return self::run_job_translation_slice_body( $post_id, $lang, $post, $settings );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'polymart_ai_job_slice_failed',
				$e->getMessage()
			);
		} finally {
			if ( $manage_lock ) {
				self::release_translation_lock( $post_id, $lang );
			}
		}
	}

	/**
	 * Core auto-translate slice body (caller owns acquire/release).
	 *
	 * @param int                  $post_id  Post ID.
	 * @param string               $lang     Target language code.
	 * @param \WP_Post             $post     Post object.
	 * @param array<string, mixed> $settings Translation settings.
	 * @return array{done: bool, phase: string, phase_progress: string, message: string}|\WP_Error
	 */
	private static function run_job_translation_slice_body( $post_id, $lang, $post, array $settings ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		$state = self::get_job_partial_state( $post_id, $lang );

		if ( empty( $state ) || empty( $state['phase'] ) ) {
			$state = self::build_initial_job_partial_state( $post_id, $lang, $post );
		}

		if ( 'complete' === (string) ( $state['phase'] ?? '' ) ) {
			self::clear_job_partial_state( $post_id, $lang );

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
				$state = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
				self::save_job_partial_state( $post_id, $lang, $state );

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

				self::clear_elementor_slice_cursor( $post_id, $lang );
				delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );
				delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
				self::clear_job_partial_state( $post_id, $lang );

				return array(
					'done'           => true,
					'phase'          => 'complete',
					'phase_progress' => '',
					'message'        => __( 'ترجمه Elementor این مورد تکمیل شد.', 'polymart-ai' ),
				);
			}

		self::clear_job_partial_state( $post_id, $lang );

		return array(
			'done'           => true,
			'phase'          => 'complete',
			'phase_progress' => '',
			'message'        => __( 'ترجمه این مورد تکمیل شد.', 'polymart-ai' ),
		);
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
		$state   = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$data_early = json_decode( $raw, true );

			if ( is_array( $data_early ) ) {
				$source_payload_early = self::collect_elementor_translation_payload( $data_early );
				$state['elementor_map'] = self::sanitize_elementor_translation_map(
					self::merge_elementor_path_map(
						$post_id,
						$lang,
						$data_early,
						is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array()
					),
					$source_payload_early
				);
			}
		}

		if ( ! self::elementor_job_primary_batches_exhausted( $post_id, $lang ) ) {
			self::unblock_elementor_chunk_queue( $post_id, $lang );
			$state = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
		}

		if ( \PolymartAI\Activity_Logger::is_bulk_job_running() && \PolymartAI\Activity_Logger::is_job_api_cooldown_active() ) {
			return self::make_recoverable_partial_slice_response(
				'elementor',
				self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
				\PolymartAI\Activity_Logger::format_api_cooldown_message(
					\PolymartAI\Activity_Logger::get_job_api_cooldown_remaining()
				)
			);
		}

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
		$map = self::merge_elementor_path_map( $post_id, $lang, $data, $map );

		$payload = self::filter_remaining_elementor_payload(
			self::collect_elementor_translation_payload( $data ),
			$map,
			is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array()
		);
		$source_payload = self::collect_elementor_translation_payload( $data );
		$map            = self::sanitize_elementor_translation_map( $map, $source_payload );
		$text_mirrors   = self::get_elementor_text_mirror_paths( $source_payload );
		$map            = self::expand_elementor_map_mirrors( $map, $text_mirrors );
		$state['elementor_map'] = $map;

		$primary_exhausted = self::elementor_job_primary_batches_exhausted( $post_id, $lang );
		$chunks            = array();
		$slice_cursor      = self::read_elementor_slice_cursor( $post_id, $lang );
		$progress_total    = max( 1, self::read_elementor_slice_cursor_total( $post_id, $lang ), 1 );

		if ( $primary_exhausted ) {
			if ( self::read_elementor_slice_cursor_total( $post_id, $lang ) > 0 ) {
				$progress_total = self::read_elementor_slice_cursor_total( $post_id, $lang );
				self::write_elementor_slice_cursor( $post_id, $lang, $progress_total, $progress_total );
				$slice_cursor = $progress_total;
			}
		} else {
			$chunk_queue                     = self::build_elementor_job_chunk_queue( $post_id, $lang, $data, $state );
			$chunks                          = $chunk_queue['pending'];
			$slice_cursor                    = $chunk_queue['cursor'];
			$progress_total                  = max( $chunk_queue['total'], 1 );
			$state['elementor_chunks_total'] = $progress_total;
		}

		if ( $primary_exhausted && ! empty( $payload ) ) {
			$map     = self::merge_elementor_path_map( $post_id, $lang, $data, $map );
			$map     = self::sanitize_elementor_translation_map( $map, $source_payload );
			$skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
			$gap_chunks    = self::build_elementor_gap_fill_chunks( $data, $map, $skipped );
			$remaining_all = self::filter_remaining_elementor_payload( $source_payload, $map, $skipped );
			$stubborn_only_pending = count( $remaining_all ) - count(
				self::filter_elementor_gap_fill_batch_remaining( $remaining_all )
			);
			$enter_gap_fill = ! empty( $gap_chunks )
				|| $stubborn_only_pending > 0
				|| ! empty( $state['elementor_gap_fill'] );

			if ( $enter_gap_fill && ! empty( $remaining_all ) ) {
				$chunks                 = ! empty( $gap_chunks ) ? $gap_chunks : array();
				$state['elementor_map'] = $map;
				$was_gap_fill           = ! empty( $state['elementor_gap_fill'] );
				$stored_total           = self::read_elementor_slice_cursor_total( $post_id, $lang );
				$stored_cursor          = self::read_elementor_slice_cursor( $post_id, $lang );

				if ( $stored_total > 0 ) {
					$progress_total                          = $stored_total;
					$state['elementor_chunks_total']         = $stored_total;
					$state['elementor_primary_batches_done'] = $stored_total;
					$slice_cursor                            = min( $stored_total, max( $stored_cursor, $stored_total ) );
					self::write_elementor_slice_cursor( $post_id, $lang, $slice_cursor, $stored_total );
				} else {
					$state['elementor_primary_batches_done'] = $progress_total;
					$slice_cursor                          = $progress_total;
				}

				if ( ! $was_gap_fill ) {
					$resuming_gap_fill = $stored_total > 0 && $stored_cursor >= $stored_total;

					if ( $resuming_gap_fill ) {
						self::resolve_elementor_gap_fill_totals( $state, $gap_chunks );
					} else {
						$state['elementor_gap_fill_done']           = 0;
						$state['elementor_gap_fill_finished_keys']  = array();
						$state['elementor_gap_fill_scheduled_keys'] = array();
						self::schedule_elementor_gap_fill_chunks( $state, $gap_chunks );

						$remaining_count = count(
							self::filter_remaining_elementor_payload( $source_payload, $map, $skipped )
						);
						$stubborn_count  = count(
							array_filter(
								self::filter_remaining_elementor_payload( $source_payload, $map, $skipped ),
								static function ( $text, $path ) {
									return self::stubborn_elementor_field_is_long( (string) $path, (string) $text );
								},
								ARRAY_FILTER_USE_BOTH
							)
						);

						\PolymartAI\Activity_Logger::log(
							'info',
							sprintf(
								/* translators: 1: post ID, 2: remaining fields, 3: gap batches, 4: stubborn field count */
								__( 'Elementor — #%1$d: تکمیل نهایی — %2$d فیلد (%3$d بخش کوتاه + %4$d بلند/stubborn)', 'polymart-ai' ),
								$post_id,
								$remaining_count,
								count( $gap_chunks ),
								$stubborn_count
							),
							array( 'post_id' => $post_id, 'lang' => $lang )
						);

						self::log_elementor_remaining_field_diagnostics(
							$post_id,
							$lang,
							$source_payload,
							$map,
							$skipped,
							'gap-fill-start'
						);
					}
				} else {
					self::resolve_elementor_gap_fill_totals( $state, $gap_chunks );
				}

				$state['elementor_gap_fill'] = true;

				$stubborn_remaining = $stubborn_only_pending;

				if ( empty( $gap_chunks ) && $stubborn_remaining > 0 ) {
					$chunks                                    = array();
					$state['elementor_gap_fill_stubborn_only'] = true;
					$state['elementor_gap_fill_total']         = max(
						absint( $state['elementor_gap_fill_done'] ?? 0 ),
						absint( $state['elementor_gap_fill_total'] ?? 0 )
					);
				} elseif ( self::elementor_gap_fill_remaining_is_long_tail_only( $source_payload, $map, $skipped ) ) {
					$chunks                                  = array();
					$state['elementor_gap_fill_stubborn_only'] = true;
				} else {
					unset( $state['elementor_gap_fill_stubborn_only'] );
				}

				self::save_job_partial_state( $post_id, $lang, $state );
			}
		}

		if ( empty( $chunks ) ) {
			if ( ! empty( $payload ) ) {
				self::repair_stale_elementor_job_state( $post_id, $lang );
				$state       = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
				$chunk_queue = self::build_elementor_job_chunk_queue( $post_id, $lang, $data, $state );
				$chunks       = $chunk_queue['pending'];
				$slice_cursor = $chunk_queue['cursor'];
				$progress_total                  = max( $chunk_queue['total'], 1 );
				$state['elementor_chunks_total'] = $progress_total;
			}

			if ( empty( $chunks ) ) {
				if ( ! self::elementor_job_slice_is_truly_complete( $post_id, $lang, $state ) ) {
					\PolymartAI\Activity_Logger::log(
						'warning',
						sprintf(
							/* translators: 1: post ID, 2: progress marker */
							__( 'Elementor — #%1$d: صف بخش‌ها خالی است ولی ترجمه کامل نشده (%2$s) — بازسازی وضعیت…', 'polymart-ai' ),
							$post_id,
							self::format_elementor_job_progress_marker( $post_id, $lang, $state )
						),
						array( 'post_id' => $post_id, 'lang' => $lang )
					);

					$state['elementor_skipped'] = array();
					$state                      = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
					$chunk_queue                = self::build_elementor_job_chunk_queue( $post_id, $lang, $data, $state );
					$chunks                     = $chunk_queue['pending'];
					$slice_cursor               = $chunk_queue['cursor'];
					$progress_total             = max( $chunk_queue['total'], absint( $state['elementor_chunks_total'] ?? 0 ), 1 );
					$state['elementor_chunks_total'] = $progress_total;
				}

				if ( empty( $chunks ) ) {
					$done_count_pre      = self::resolve_elementor_done_count(
						$state,
						self::get_elementor_chunk_progress( $post_id, $lang, $state )['done'],
						$post_id,
						$lang
					);
					$api_slices_complete = $done_count_pre >= $progress_total;

					if ( $api_slices_complete ) {
						// All API batches done — fall through to finalization below.
						$chunks = array();
					} elseif (
						! self::elementor_job_slice_is_truly_complete( $post_id, $lang, $state )
						&& ! self::elementor_job_primary_batches_exhausted( $post_id, $lang )
					) {
						self::unblock_elementor_chunk_queue( $post_id, $lang );
						$state       = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
						$chunk_queue = self::build_elementor_job_chunk_queue( $post_id, $lang, $data, $state );
						$chunks      = $chunk_queue['pending'];

						if ( ! empty( $chunks ) ) {
							$slice_cursor   = $chunk_queue['cursor'];
							$progress_total = max( $chunk_queue['total'], absint( $state['elementor_chunks_total'] ?? 0 ), 1 );
							$state['elementor_chunks_total'] = $progress_total;
							// Fall through to the API batch loop below.
						} else {
							self::save_job_partial_state( $post_id, $lang, $state );

							return array(
								'done'           => false,
								'phase'          => 'elementor',
								'phase_progress' => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
								'message'        => self::format_elementor_job_slice_status_message(
									$post_id,
									$lang,
									array_merge( $state, array( 'elementor_map' => $map ) )
								),
								'recoverable'    => true,
							);
						}
					} else {
						self::save_elementor_source_hash( $post_id, $lang );
						delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
						self::clear_elementor_slice_cursor( $post_id, $lang );
						self::clear_job_partial_state( $post_id, $lang );

						return array(
							'done'           => true,
							'phase'          => 'elementor',
							'phase_progress' => '',
							'message'        => __( 'ترجمه Elementor تکمیل شد.', 'polymart-ai' ),
						);
					}
				}
			}
		}

		if ( empty( $chunks ) ) {
			$map             = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : $map;
			$chunk_progress  = self::get_elementor_chunk_progress( $post_id, $lang, array_merge( $state, array( 'elementor_map' => $map ) ) );
			$progress_total  = max( $progress_total, (int) $chunk_progress['total'], 1 );
			$done_count      = self::resolve_elementor_done_count( $state, $chunk_progress['done'], $post_id, $lang );
			$skipped_list    = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
			$remaining       = self::filter_remaining_elementor_payload(
				self::collect_elementor_translation_payload( $data ),
				$map,
				$skipped_list
			);
			$truly_complete  = self::elementor_job_slice_is_truly_complete( $post_id, $lang, $state );
			$api_schedule_complete = self::elementor_job_api_schedule_complete( $post_id, $lang, $done_count, $progress_total );

			if ( ! $truly_complete && $api_schedule_complete ) {
				return self::finalize_elementor_job_slice( $post_id, $lang, $data, $map, $state, $done_count, $progress_total, $remaining );
			}

			if ( ! $truly_complete ) {
				if ( ! empty( $remaining ) && $done_count >= $progress_total ) {
					\PolymartAI\Activity_Logger::log(
						'info',
						sprintf(
							/* translators: 1: post ID, 2: completed batches, 3: total batches */
							__( 'Elementor — #%1$d: همه %2$d از %3$d بخش API انجام شد — در حال نهایی‌سازی…', 'polymart-ai' ),
							$post_id,
							$done_count,
							$progress_total
						),
						array( 'post_id' => $post_id, 'lang' => $lang )
					);
				}

				$persisted = self::persist_elementor_job_progress( $post_id, $lang, $data, $map, $done_count, $progress_total );

				if ( is_wp_error( $persisted ) ) {
					return $persisted;
				}

				$state['elementor_map']              = $map;
				$state['elementor_chunk_index']      = $done_count;
				$state['elementor_slices_completed'] = $done_count;
				$state['elementor_chunks_total']     = $progress_total;
				self::save_job_partial_state( $post_id, $lang, $state );

				return array(
					'done'           => false,
					'phase'          => 'elementor',
					'phase_progress' => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
					'message'        => self::format_elementor_job_slice_status_message( $post_id, $lang, $state ),
				);
			}

			$blocker = self::get_elementor_job_finalize_blockers( $post_id, $lang, $data, $map, $state );

			if ( null !== $blocker ) {
				return self::respond_elementor_blocked_finalize( $post_id, $lang, $data, $map, $state, $done_count, $progress_total, $blocker );
			}

			$persisted = self::persist_elementor_job_progress( $post_id, $lang, $data, $map, $done_count, $progress_total, true );

			if ( is_wp_error( $persisted ) ) {
				return $persisted;
			}

			self::clear_elementor_slice_cursor( $post_id, $lang );
			self::clear_job_partial_state( $post_id, $lang );

			return array(
				'done'           => true,
				'phase'          => 'elementor',
				'phase_progress' => '',
				'message'        => __( 'ترجمه Elementor تکمیل شد.', 'polymart-ai' ),
			);
		}

		$source_total    = max( 1, absint( $state['elementor_chunks_total'] ?? 0 ) );
		$budget          = self::get_job_step_max_elementor_chunks();
		$ai_options      = array( 'max_timeout' => self::ELEMENTOR_JOB_REQUEST_TIMEOUT );
		$processed       = 0;
		$failures        = is_array( $state['elementor_failures'] ?? null ) ? $state['elementor_failures'] : array();
		$skipped_list    = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$chunk_progress  = self::get_elementor_chunk_progress( $post_id, $lang, $state );
		$gap_fill_mode   = ! empty( $state['elementor_gap_fill'] );
		$gap_fill_done   = absint( $state['elementor_gap_fill_done'] ?? 0 );
		$gap_fill_total  = absint( $state['elementor_gap_fill_total'] ?? 0 );

		if ( $gap_fill_mode ) {
			$progress_total = max(
				$progress_total,
				absint( $state['elementor_primary_batches_done'] ?? 0 ),
				self::read_elementor_slice_cursor_total( $post_id, $lang )
			);
			$slice_cursor = min( $slice_cursor, $progress_total );
			$ai_options   = array( 'max_timeout' => 45 );
			$budget       = min( $budget, 3 );

			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( max( 120, $budget * 50 ) );
			}

			$gap_schedule    = self::resolve_elementor_gap_fill_totals( $state, $chunks );
			$gap_fill_done   = (int) $gap_schedule['done'];
			$gap_fill_total  = (int) $gap_schedule['total'];

			if ( empty( $chunks ) && ! empty( $state['elementor_gap_fill_stubborn_only'] ) ) {
				$state['elementor_gap_fill_stubborn_only'] = true;
			} elseif ( empty( $chunks ) && self::elementor_gap_fill_remaining_is_long_tail_only( $source_payload, $map, $skipped_list ) ) {
				$state['elementor_gap_fill_stubborn_only'] = true;
			}
		}

		while ( $processed < $budget && ! empty( $chunks ) ) {
			$chunk = array_shift( $chunks );
			$first_key = (string) array_key_first( $chunk );

			if ( '' !== $first_key && absint( $failures[ $first_key ] ?? 0 ) >= 3 ) {
				$skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
				$skipped[] = $first_key;
				$state['elementor_skipped'] = array_values( array_unique( array_map( 'strval', $skipped ) ) );
				unset( $failures[ $first_key ] );
				++$processed;
				$state['elementor_map'] = $map;
				continue;
			}

			if ( self::elementor_chunk_paths_translated( $chunk, $map, $source_payload ) ) {
				++$processed;
				$state['elementor_map'] = $map;

				if ( $gap_fill_mode ) {
					self::register_elementor_gap_fill_chunk_done( $state, $chunk );
					$gap_fill_done = absint( $state['elementor_gap_fill_done'] ?? 0 );
					self::sync_elementor_persist_map_state( $state, $map, $source_payload );
					$commit_done   = $progress_total;
				} else {
					++$slice_cursor;
					$commit_done = $slice_cursor;
				}

				$committed = self::commit_elementor_chunk_progress_to_site(
					$post_id,
					$lang,
					$data,
					$state,
					$map,
					$commit_done,
					$progress_total,
					$gap_fill_mode
				);

				if ( is_wp_error( $committed ) ) {
					return $committed;
				}

				if ( ! $gap_fill_mode ) {
					self::write_elementor_slice_cursor( $post_id, $lang, $slice_cursor, $progress_total );
					$state['elementor_chunk_index']      = $slice_cursor;
					$state['elementor_slices_completed'] = $slice_cursor;
				}

				self::save_job_partial_state( $post_id, $lang, $state );
				\PolymartAI\Activity_Logger::sync_elementor_partial_progress( $post_id, $lang );

				continue;
			}

			list( $aliased_payload, $alias_to_path ) = self::alias_elementor_payload_keys( $chunk );

			if ( $gap_fill_mode ) {
				$attempt_index = $gap_fill_done + 1;
				$attempt_total = max( 1, $gap_fill_total );
			} else {
				$attempt_index = $slice_cursor + 1;
				$attempt_total = $progress_total;
			}

			$title         = get_the_title( $post_id );
			$post_label    = '' !== $title
				? sprintf( '#%d «%s»', $post_id, $title )
				: sprintf( '#%d', $post_id );

			if ( $gap_fill_mode ) {
				$remaining_fields = count(
					self::filter_remaining_elementor_payload( $source_payload, $map, $skipped_list )
				);
				$stubborn_fields  = count(
					array_filter(
						self::filter_remaining_elementor_payload( $source_payload, $map, $skipped_list ),
						static function ( $text, $path ) {
							return self::stubborn_elementor_field_is_long( (string) $path, (string) $text );
						},
						ARRAY_FILTER_USE_BOTH
					)
				);

				if ( ! empty( $state['elementor_gap_fill_stubborn_only'] ) ) {
					\PolymartAI\Activity_Logger::log(
						'info',
						sprintf(
							/* translators: 1: post label, 2: remaining fields, 3: stubborn field count */
							__( 'Elementor — %1$s: stubborn — %2$d فیلد باقی (%3$d بلند)…', 'polymart-ai' ),
							$post_label,
							$remaining_fields,
							$stubborn_fields
						),
						array( 'post_id' => $post_id, 'lang' => $lang )
					);
				} else {
					\PolymartAI\Activity_Logger::log(
						'info',
						sprintf(
							/* translators: 1: post label, 2: gap chunk index, 3: gap chunk total, 4: remaining fields, 5: stubborn field count */
							__( 'Elementor — %1$s: تکمیل نهایی — ارسال بخش %2$d از %3$d (فیلدهای کوتاه) — %4$d فیلد باقی (%5$d بلند/stubborn)…', 'polymart-ai' ),
							$post_label,
							$attempt_index,
							$attempt_total,
							$remaining_fields,
							$stubborn_fields
						),
						array( 'post_id' => $post_id, 'lang' => $lang )
					);
				}
			} else {
				\PolymartAI\Activity_Logger::log(
					'info',
					sprintf(
						/* translators: 1: post label, 2: chunk index, 3: total chunks */
						__( 'Elementor — %1$s: ارسال بخش %2$d از %3$d به API آروان…', 'polymart-ai' ),
						$post_label,
						$attempt_index,
						$attempt_total
					),
					array( 'post_id' => $post_id, 'lang' => $lang )
				);
			}

			\PolymartAI\Activity_Logger::wait_for_arvan_api_gap();

			// Toxic payload logging (metabox/inline debugging): store a small, safe preview of what we send.
			// This helps identify which Elementor paths trigger long hangs / transport timeouts.
			if ( 1 === $attempt_index ) {
				$toxic_preview = array();
				$preview_i     = 0;
				foreach ( $aliased_payload as $k => $v ) {
					$k = (string) $k;
					$v = (string) $v;
					$toxic_preview[] = array(
						'key'   => $k,
						'chars' => function_exists( 'mb_strlen' ) ? mb_strlen( $v, 'UTF-8' ) : strlen( $v ),
						'preview' => wp_trim_words( wp_strip_all_tags( html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 28, '…' ),
					);
					++$preview_i;
					if ( $preview_i >= 6 ) {
						break;
					}
				}
				update_post_meta(
					$post_id,
					\PolymartAI\Metabox_Action_Scheduler::TOXIC_META_PREFIX . $lang,
					array(
						'at'     => time(),
						'chunk'  => $attempt_index,
						'total'  => $progress_total,
						'count'  => count( $aliased_payload ),
						'sample' => $toxic_preview,
					)
				);
			}

			$chunk_result = AI_Client::translate_fields(
				$aliased_payload,
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang,
				$ai_options
			);

			\PolymartAI\Activity_Logger::touch_arvan_api_attempt();

			if ( is_wp_error( $chunk_result ) ) {
				// Capture the exact transport error and which fields were in-flight.
				$failed_paths = array_keys( $chunk );
				update_post_meta(
					$post_id,
					'_polymart_ai_elementor_error_' . $lang,
					sprintf(
						/* translators: 1: chunk index, 2: total chunks, 3: error */
						__( 'Elementor toxic probe — chunk %1$d/%2$d failed: %3$s', 'polymart-ai' ),
						$attempt_index,
						$progress_total,
						\PolymartAI\Activity_Logger::humanize_api_error_message( $chunk_result->get_error_message() )
					)
				);

				$state['elementor_failures'] = $failures;

				\PolymartAI\Activity_Logger::log(
					'warning',
					sprintf(
						/* translators: 1: post ID, 2: chunk index, 3: error message */
						__( 'Elementor — #%1$d: خطای API در بخش %2$d — %3$s', 'polymart-ai' ),
						$post_id,
						$attempt_index,
						\PolymartAI\Activity_Logger::humanize_api_error_message( $chunk_result->get_error_message() )
					),
					array( 'post_id' => $post_id, 'lang' => $lang )
				);

				return self::handle_elementor_job_slice_failure(
					$post_id,
					$lang,
					$data,
					$state,
					$map,
					$progress_total,
					$chunk_result,
					$failed_paths
				);
			}

			$raw_mapped = self::unmap_elementor_aliases(
				is_array( $chunk_result ) ? $chunk_result : array(),
				$alias_to_path
			);
			$mapped_chunk = self::merge_elementor_api_translations_into_map( $raw_mapped, $source_payload );

			if ( empty( $mapped_chunk ) && ! empty( $aliased_payload ) ) {
				\PolymartAI\Activity_Logger::wait_for_arvan_api_gap();

				$recovered = self::translate_job_chunk_with_single_field_fallback(
					$aliased_payload,
					$api_key,
					$api_endpoint,
					$ai_model,
					$lang,
					count( $aliased_payload ),
					$ai_options
				);

				\PolymartAI\Activity_Logger::touch_arvan_api_attempt();

				if ( ! is_wp_error( $recovered ) ) {
					$raw_recovered = self::unmap_elementor_aliases(
						is_array( $recovered ) ? $recovered : array(),
						$alias_to_path
					);
					$mapped_chunk = self::merge_elementor_api_translations_into_map( $raw_recovered, $source_payload );
				}
			}

			foreach ( $mapped_chunk as $path => $translated ) {
				$translated = trim( (string) $translated );
				$path       = (string) $path;

				if ( '' === $translated || Persian_Detector::contains_persian( $translated ) ) {
					if ( $gap_fill_mode ) {
						\PolymartAI\Activity_Logger::log(
							'warning',
							sprintf(
								/* translators: 1: post ID, 2: field path, 3: reject reason */
								__( 'Elementor — #%1$d: رد پاسخ API برای %2$s — %3$s', 'polymart-ai' ),
								$post_id,
								$path,
								'' === $translated ? 'empty' : 'persian'
							),
							array( 'post_id' => $post_id, 'lang' => $lang, 'path' => $path )
						);
					}

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

			$mapped_chunk = self::retry_untranslated_elementor_chunk_fields(
				$chunk,
				$mapped_chunk,
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang,
				$ai_options,
				$failures,
				$state,
				max( 2, count( $chunk ) )
			);

			if ( empty( $mapped_chunk ) && ! empty( $chunk ) ) {
				$first_path = (string) array_key_first( $chunk );

				\PolymartAI\Activity_Logger::log(
					'warning',
					sprintf(
						/* translators: 1: post ID, 2: chunk index, 3: field path */
						__( 'Elementor — #%1$d: بخش %2$d پاسخ خالی یا فارسی از API (%3$s)', 'polymart-ai' ),
						$post_id,
						$attempt_index,
						'' !== $first_path ? $first_path : '…'
					),
					array( 'post_id' => $post_id, 'lang' => $lang )
				);

				if ( '' !== $first_path ) {
					$failures[ $first_path ] = absint( $failures[ $first_path ] ?? 0 ) + 1;

					if ( $failures[ $first_path ] >= 2 ) {
						$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
						$skipped[] = $first_path;
						$state['elementor_skipped'] = array_values( array_unique( array_map( 'strval', $skipped ) ) );
						unset( $failures[ $first_path ] );
					}
				}
			}

			$map = array_merge( $map, $mapped_chunk );
			$map = self::expand_elementor_map_mirrors( $map, $text_mirrors );
			$map = self::merge_elementor_path_map( $post_id, $lang, $data, $map );
			$map = self::sanitize_elementor_translation_map( $map, $source_payload );
			$state['elementor_map'] = $map;
			++$processed;

			$chunk_satisfied = self::elementor_chunk_paths_translated( $chunk, $map, $source_payload );

			if ( $gap_fill_mode ) {
				if ( $chunk_satisfied ) {
					self::register_elementor_gap_fill_chunk_done( $state, $chunk );
					$gap_fill_done = absint( $state['elementor_gap_fill_done'] ?? 0 );
				}
				self::sync_elementor_persist_map_state( $state, $map, $source_payload );
				$commit_done = $progress_total;
			} elseif ( $chunk_satisfied ) {
				++$slice_cursor;
				$commit_done = $slice_cursor;
			} else {
				$commit_done = $slice_cursor;
			}

			$committed = self::commit_elementor_chunk_progress_to_site(
				$post_id,
				$lang,
				$data,
				$state,
				$map,
				$commit_done,
				$progress_total,
				$gap_fill_mode
			);

			if ( is_wp_error( $committed ) ) {
				return $committed;
			}

			self::touch_translation_lock( $post_id, $lang );

			if ( $chunk_satisfied ) {
				if ( ! $gap_fill_mode ) {
					self::write_elementor_slice_cursor( $post_id, $lang, $slice_cursor, $progress_total );
					$state['elementor_chunk_index']      = $slice_cursor;
					$state['elementor_slices_completed'] = $slice_cursor;
				}

				$state['elementor_chunks_total'] = $progress_total;
				self::save_job_partial_state( $post_id, $lang, $state );
				\PolymartAI\Activity_Logger::sync_elementor_partial_progress( $post_id, $lang );

				if ( ! empty( $mapped_chunk ) ) {
					\PolymartAI\Activity_Logger::touch_successful_api_call();

					if ( $gap_fill_mode ) {
						\PolymartAI\Activity_Logger::log(
							'info',
							sprintf(
								/* translators: 1: post label, 2: gap chunk index, 3: gap total, 4: field count */
								__( 'Elementor — %1$s: تکمیل نهایی — بخش %2$d از %3$d ذخیره شد — %4$d فیلد', 'polymart-ai' ),
								$post_label,
								$attempt_index,
								max( 1, $gap_fill_total ),
								count( $mapped_chunk )
							),
							array( 'post_id' => $post_id, 'lang' => $lang )
						);
					} else {
						\PolymartAI\Activity_Logger::log(
							'info',
							sprintf(
								/* translators: 1: post label, 2: chunk index, 3: field count */
								__( 'Elementor — %1$s: بخش %2$d از %3$d ذخیره شد — %4$d فیلد', 'polymart-ai' ),
								$post_label,
								$attempt_index,
								$progress_total,
								count( $mapped_chunk )
							),
							array( 'post_id' => $post_id, 'lang' => $lang )
						);
					}
				}
			} elseif ( ! empty( $mapped_chunk ) ) {
				\PolymartAI\Activity_Logger::touch_successful_api_call();
				\PolymartAI\Activity_Logger::log(
					'info',
					sprintf(
						/* translators: 1: post label, 2: chunk index, 3: field count */
						__( 'Elementor — %1$s: بخش %2$d — %3$d فیلد ذخیره شد (تکمیل بخش در تیک بعد)', 'polymart-ai' ),
						$post_label,
						$attempt_index,
						count( $mapped_chunk )
					),
					array( 'post_id' => $post_id, 'lang' => $lang )
				);

				if ( $gap_fill_mode ) {
					self::log_elementor_remaining_field_diagnostics(
						$post_id,
						$lang,
						$source_payload,
						$map,
						is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array(),
						sprintf( 'chunk-%d-partial', $attempt_index )
					);
				}
			}

			if ( $processed < $budget && ! empty( $chunks ) ) {
				$delay = \PolymartAI\Activity_Logger::is_bulk_job_running()
					? (int) apply_filters( 'polymart_ai_elementor_inter_request_delay_sec', 12 )
					: (int) apply_filters( 'polymart_ai_metabox_elementor_inter_request_delay_sec', 0 );

				if ( $delay > 0 ) {
					\PolymartAI\Activity_Logger::sleep_with_worker_heartbeat( max( 1, min( 20, $delay ) ) );
				}
			}
		}

		if ( $gap_fill_mode ) {
			$stubborn_skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
			$stubborn_left    = count(
				self::filter_remaining_elementor_payload( $source_payload, $map, $stubborn_skipped )
			);

			if ( $stubborn_left > 0 && $stubborn_left <= 12 ) {
				$stubborn_result = self::translate_elementor_gap_fill_stubborn_fields(
					$post_id,
					$lang,
					$data,
					$state,
					$map,
					$source_payload,
					$text_mirrors,
					$api_key,
					$api_endpoint,
					$ai_model,
					min( 4, $stubborn_left )
				);

				if ( ! empty( $stubborn_result['completed'] ) || ! empty( $stubborn_result['touched'] ) ) {
					self::sync_elementor_persist_map_state( $state, $map, $source_payload );
					$state['elementor_map'] = $map;

					$committed = self::commit_elementor_chunk_progress_to_site(
						$post_id,
						$lang,
						$data,
						$state,
						$map,
						$progress_total,
						$progress_total,
						true
					);

					if ( is_wp_error( $committed ) ) {
						return $committed;
					}

					self::touch_translation_lock( $post_id, $lang );
				}
			}
		}

		$map                    = self::expand_elementor_map_mirrors( $map, $text_mirrors );
		$state['elementor_map'] = $map;
		$chunk_progress         = self::get_elementor_chunk_progress(
			$post_id,
			$lang,
			array_merge(
				$state,
				array( 'elementor_map' => $map )
			)
		);
		$gap_fill_mode          = ! empty( $state['elementor_gap_fill'] );

		if ( $gap_fill_mode ) {
			$primary_total  = max(
				absint( $state['elementor_primary_batches_done'] ?? 0 ),
				self::read_elementor_slice_cursor_total( $post_id, $lang ),
				absint( $progress_total )
			);
			$progress_total = max( 1, $primary_total );
			$done_count     = $progress_total;
		} else {
			$progress_total = max( 1, absint( $progress_total ), (int) $chunk_progress['total'] );
			$done_count     = min(
				$progress_total,
				max(
					(int) $chunk_progress['done'],
					absint( $slice_cursor ),
					self::read_elementor_slice_cursor( $post_id, $lang ),
					absint( $state['elementor_slices_completed'] ?? 0 )
				)
			);
		}

		$state['elementor_chunk_index']      = $done_count;
		$state['elementor_chunks_total']     = $progress_total;
		$state['elementor_slices_completed'] = $done_count;
		$skipped_list                        = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$remaining                           = self::filter_remaining_elementor_payload(
			self::collect_elementor_translation_payload( $data ),
			$map,
			$skipped_list
		);
		$state['elementor_failures']         = $failures;

		if ( $gap_fill_mode && empty( $remaining ) && self::elementor_job_slice_is_truly_complete( $post_id, $lang, $state ) ) {
			unset(
				$state['elementor_gap_fill'],
				$state['elementor_gap_fill_done'],
				$state['elementor_gap_fill_total'],
				$state['elementor_gap_fill_finished_keys'],
				$state['elementor_gap_fill_scheduled_keys'],
				$state['elementor_gap_fill_stubborn_only'],
				$state['elementor_primary_batches_done'],
				$state['elementor_persist_map'],
				$state['elementor_seg_map'],
				$state['elementor_stubborn_long_index']
			);
		}

		$truly_complete        = self::elementor_job_slice_is_truly_complete( $post_id, $lang, $state );
		$api_schedule_complete = self::elementor_job_api_schedule_complete( $post_id, $lang, $done_count, $progress_total )
			|| $done_count >= $progress_total;

		if ( ! $truly_complete && $api_schedule_complete && ! $gap_fill_mode ) {
			return self::finalize_elementor_job_slice( $post_id, $lang, $data, $map, $state, $done_count, $progress_total, $remaining );
		}

		if ( ! $truly_complete && $gap_fill_mode && ! empty( $remaining ) ) {
			self::log_elementor_remaining_field_diagnostics(
				$post_id,
				$lang,
				self::collect_elementor_translation_payload( $data ),
				$map,
				$skipped_list,
				'gap-fill-tick-end'
			);

			$persisted = self::persist_elementor_job_progress( $post_id, $lang, $data, $map, $done_count, $progress_total );

			if ( is_wp_error( $persisted ) ) {
				return $persisted;
			}

			self::save_job_partial_state( $post_id, $lang, $state );

			return array(
				'done'           => false,
				'phase'          => 'elementor',
				'phase_progress' => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
				'message'        => self::format_elementor_gap_fill_remaining_message( $post_id, $remaining, $map ),
				'recoverable'    => true,
			);
		}

		if ( ! $truly_complete ) {
			$persisted = self::persist_elementor_job_progress( $post_id, $lang, $data, $map, $done_count, $progress_total );

			if ( is_wp_error( $persisted ) ) {
				return $persisted;
			}

			self::save_job_partial_state( $post_id, $lang, $state );

			return array(
				'done'           => false,
				'phase'          => 'elementor',
				'phase_progress' => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
				'message'        => self::format_elementor_job_slice_status_message( $post_id, $lang, $state ),
			);
		}

		if ( ! empty( $remaining ) ) {
			\PolymartAI\Activity_Logger::log(
				'info',
				sprintf(
					/* translators: 1: post ID, 2: completed batches, 3: total batches */
					__( 'Elementor — #%1$d: همه %2$d از %3$d بخش API انجام شد — در حال نهایی‌سازی…', 'polymart-ai' ),
					$post_id,
					$done_count,
					$progress_total
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);
		}

		$blocker = self::get_elementor_job_finalize_blockers( $post_id, $lang, $data, $map, $state );

		if ( null !== $blocker ) {
			return self::respond_elementor_blocked_finalize( $post_id, $lang, $data, $map, $state, $done_count, $progress_total, $blocker );
		}

		$persisted = self::persist_elementor_job_progress( $post_id, $lang, $data, $map, $done_count, $progress_total, true );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		self::clear_elementor_slice_cursor( $post_id, $lang );
		self::clear_job_partial_state( $post_id, $lang );

		return array(
			'done'           => true,
			'phase'          => 'elementor',
			'phase_progress' => '',
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
		if ( self::is_api_transport_timeout_error( $error ) && ! empty( $failed_keys ) ) {
			return self::skip_elementor_chunk_on_api_timeout(
				$post_id,
				$lang,
				$source_data,
				$state,
				$map,
				$source_total,
				$failed_keys,
				$error
			);
		}

		\PolymartAI\Activity_Logger::maybe_apply_api_throttle_cooldown( $error );

		if ( \PolymartAI\Activity_Logger::is_job_api_cooldown_active() ) {
			$short_error = \PolymartAI\Activity_Logger::format_api_cooldown_message(
				\PolymartAI\Activity_Logger::get_job_api_cooldown_remaining()
			);
		} else {
			$short_error = \PolymartAI\Activity_Logger::humanize_api_error_message( $error->get_error_message() );
		}

		$state['elementor_map'] = $map;
		$state                  = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
		$progress               = self::format_elementor_job_progress_marker( $post_id, $lang, $state );
		$progress_total         = max( 1, absint( $state['elementor_chunks_total'] ?? $source_total ) );
		$failures               = is_array( $state['elementor_failures'] ?? null ) ? $state['elementor_failures'] : array();

		foreach ( $failed_keys as $failed_key ) {
			$failed_key = (string) $failed_key;

			if ( '' === $failed_key ) {
				continue;
			}

			$failures[ $failed_key ] = absint( $failures[ $failed_key ] ?? 0 ) + 1;
		}

		$state['elementor_failures'] = $failures;

		if ( ! empty( $map ) ) {
			$chunk_progress = self::get_elementor_chunk_progress( $post_id, $lang, $state );
			$done_count     = self::resolve_elementor_done_count( $state, $chunk_progress['done'], $post_id, $lang );
			$persisted      = self::persist_elementor_job_progress( $post_id, $lang, $source_data, $map, $done_count, $progress_total );

			if ( ! is_wp_error( $persisted ) ) {
				self::save_job_partial_state( $post_id, $lang, $state );
				\PolymartAI\Activity_Logger::sync_elementor_partial_progress( $post_id, $lang );

				return self::make_recoverable_partial_slice_response(
					'elementor',
					$progress,
					sprintf(
						/* translators: 1: saved batch count, 2: total batches, 3: error hint */
						__( 'Elementor — %1$d از %2$d بخش ذخیره شد. %3$s', 'polymart-ai' ),
						$done_count,
						$progress_total,
						$short_error
					)
				);
			}
		}

		if ( self::is_recoverable_job_slice_error( $error ) ) {
			self::save_job_partial_state( $post_id, $lang, $state );

			return self::make_recoverable_partial_slice_response(
				'elementor',
				$progress,
				$short_error
			);
		}

		return $error;
	}

	/**
	 * Whether an API error is a hard transport timeout (cURL 28 / http_request_failed).
	 *
	 * @param \WP_Error $error Error object.
	 * @return bool
	 */
	public static function is_api_transport_timeout_error( $error ) {
		if ( ! $error instanceof \WP_Error ) {
			return false;
		}

		if ( 'polymart_ai_timeout' === $error->get_error_code() ) {
			return true;
		}

		if ( 'http_request_failed' !== $error->get_error_code() ) {
			return false;
		}

		$message = strtolower( $error->get_error_message() );

		return false !== strpos( $message, 'curl error 28' )
			|| false !== strpos( $message, 'timed out' )
			|| false !== strpos( $message, 'timeout' )
			|| false !== strpos( $message, 'operation timed out' );
	}

	/**
	 * Skip an Elementor API chunk after a transport timeout and advance the job queue.
	 *
	 * @param int                   $post_id      Post ID.
	 * @param string                $lang         Language code.
	 * @param array<string, mixed>  $source_data  Source Elementor tree.
	 * @param array<string, mixed>  $state        Partial state (by reference).
	 * @param array<string, string> $map          Translation path map so far.
	 * @param int                   $source_total Total API batches.
	 * @param string[]              $failed_keys  Paths in the timed-out chunk.
	 * @param \WP_Error             $error        Transport error.
	 * @return array{done: bool, phase: string, phase_progress: string, message: string, recoverable: bool}
	 */
	private static function skip_elementor_chunk_on_api_timeout( $post_id, $lang, array $source_data, array &$state, array $map, $source_total, array $failed_keys, \WP_Error $error ) {
		$post_id        = absint( $post_id );
		$lang           = sanitize_key( (string) $lang );
		$source_total   = max( 1, absint( $source_total ) );
		$skipped        = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$failures       = is_array( $state['elementor_failures'] ?? null ) ? $state['elementor_failures'] : array();
		$skipped_paths  = array();

		foreach ( $failed_keys as $failed_key ) {
			$failed_key = (string) $failed_key;

			if ( '' === $failed_key ) {
				continue;
			}

			$skipped[]         = $failed_key;
			$skipped_paths[]   = $failed_key;
			unset( $failures[ $failed_key ] );
		}

		$state['elementor_skipped']  = array_values( array_unique( array_map( 'strval', $skipped ) ) );
		$state['elementor_failures'] = $failures;
		$state['elementor_map']      = $map;
		$state                       = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );

		$chunk_progress = self::get_elementor_chunk_progress( $post_id, $lang, $state );
		$done_count     = self::resolve_elementor_done_count( $state, $chunk_progress['done'], $post_id, $lang );
		$progress_total = max( $source_total, (int) $chunk_progress['total'], absint( $state['elementor_chunks_total'] ?? 0 ), 1 );
		$state['elementor_chunks_total'] = $progress_total;

		if ( ! empty( $map ) ) {
			self::persist_elementor_job_progress( $post_id, $lang, $source_data, $map, $done_count, $progress_total );
		}

		self::save_job_partial_state( $post_id, $lang, $state );

		$progress   = self::format_elementor_job_progress_marker( $post_id, $lang, $state );
		$short_error = \PolymartAI\Activity_Logger::humanize_api_error_message( $error->get_error_message() );

		update_post_meta(
			$post_id,
			'_polymart_ai_elementor_error_' . $lang,
			sprintf(
				/* translators: 1: skipped path count, 2: error detail */
				__( 'timeout API — %1$d فیلد رد شد (%2$s)', 'polymart-ai' ),
				count( $skipped_paths ),
				$short_error
			)
		);

		\PolymartAI\Activity_Logger::log(
			'warning',
			sprintf(
				/* translators: 1: post ID, 2: progress marker, 3: skipped count, 4: error */
				__( 'Elementor — #%1$d: timeout API در %2$s — %3$d فیلد به skipped منتقل شد (%4$s) — ادامه با بخش بعدی.', 'polymart-ai' ),
				$post_id,
				$progress,
				count( $skipped_paths ),
				$short_error
			),
			array(
				'post_id' => $post_id,
				'lang'    => $lang,
				'skipped' => $skipped_paths,
			)
		);

		return self::make_recoverable_partial_slice_response(
			'elementor',
			$progress,
			sprintf(
				/* translators: 1: progress marker, 2: skipped field count */
				__( 'Elementor — %1$s: بخش API به‌خاطر timeout رد شد (%2$d فیلد) — ادامه…', 'polymart-ai' ),
				$progress,
				count( $skipped_paths )
			)
		);
	}

	/**
	 * Skip the next pending Elementor API chunk (metabox Action Scheduler recovery).
	 *
	 * When the gateway repeatedly times out or returns structural errors, we prefer
	 * to mark the entire next chunk as skipped so the job can continue to 13/13.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @param string $reason  Debug hint.
	 * @return array{done: bool, phase: string, phase_progress: string, message: string, recoverable: bool}|\WP_Error
	 */
	public static function skip_next_elementor_pending_chunk( $post_id, $lang, $reason = '' ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$reason  = sanitize_key( (string) $reason );

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return new \WP_Error( 'polymart_ai_invalid_request', __( 'درخواست نامعتبر است.', 'polymart-ai' ) );
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return new \WP_Error( 'polymart_ai_elementor_source_missing', __( 'داده Elementor برای این صفحه یافت نشد.', 'polymart-ai' ) );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'polymart_ai_elementor_source_invalid', __( 'JSON Elementor نامعتبر است.', 'polymart-ai' ) );
		}

		$state = self::get_job_partial_state( $post_id, $lang );
		$state = is_array( $state ) ? $state : array();
		$state = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );

		$chunk_queue    = self::build_elementor_job_chunk_queue( $post_id, $lang, $data, $state );
		$pending_chunks = is_array( $chunk_queue['pending'] ?? null ) ? $chunk_queue['pending'] : array();

		if ( empty( $pending_chunks ) ) {
			return self::make_recoverable_partial_slice_response(
				'elementor',
				self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
				__( 'Elementor: چیزی برای رد کردن باقی نمانده است.', 'polymart-ai' )
			);
		}

		$chunk = reset( $pending_chunks );
		if ( ! is_array( $chunk ) || empty( $chunk ) ) {
			return self::make_recoverable_partial_slice_response(
				'elementor',
				self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
				__( 'Elementor: بخش معلق خالی بود — ادامه…', 'polymart-ai' )
			);
		}

		$paths   = array_values( array_filter( array_map( 'strval', array_keys( $chunk ) ) ) );
		$skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$skipped = array_values( array_unique( array_merge( array_map( 'strval', $skipped ), $paths ) ) );
		$state['elementor_skipped'] = $skipped;

		// Persist progress so UI sees 0/13 -> 1/13 etc even when a chunk is skipped.
		$map            = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$progress_total  = max( 1, absint( $chunk_queue['total'] ?? 1 ) );
		$chunk_progress  = self::get_elementor_chunk_progress(
			$post_id,
			$lang,
			array(
				'elementor_map'          => $map,
				'elementor_skipped'      => $skipped,
				'elementor_chunks_total' => $progress_total,
			)
		);
		$done_count = self::resolve_elementor_done_count( $state, (int) $chunk_progress['done'], $post_id, $lang );

		self::save_job_partial_state( $post_id, $lang, $state );
		self::persist_elementor_job_progress( $post_id, $lang, $data, $map, $done_count, $progress_total );

		$progress = self::format_elementor_job_progress_marker( $post_id, $lang, $state );

		update_post_meta(
			$post_id,
			'_polymart_ai_elementor_error_' . $lang,
			sprintf(
				/* translators: 1: skipped field count, 2: reason */
				__( 'Elementor — chunk رد شد (%1$d فیلد) — %2$s', 'polymart-ai' ),
				count( $paths ),
				'' !== $reason ? $reason : 'skipped'
			)
		);

		return self::make_recoverable_partial_slice_response(
			'elementor',
			$progress,
			sprintf(
				/* translators: 1: progress marker, 2: skipped field count */
				__( 'Elementor — %1$s: یک بخش API رد شد (%2$d فیلد) — ادامه…', 'polymart-ai' ),
				$progress,
				count( $paths )
			)
		);
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
	 * Human-readable reason a single Elementor path is still untranslated.
	 *
	 * @param string               $path Field path.
	 * @param string               $text Source text.
	 * @param array<string, string> $map Completed translations keyed by path.
	 * @return array{path: string, reason: string, detail: string, chars: int}
	 */
	private static function describe_elementor_field_translation_blocker( $path, $text, array $map ) {
		$path = (string) $path;
		$text = (string) $text;
		$chars = self::utf8_strlen( $text );
		$detail = '';
		$reason = 'unknown';

		$seg_lookup = self::get_elementor_segment_source_lookup( $path, $text );
		if ( ! empty( $seg_lookup ) ) {
			if (
				isset( $map[ $path ] )
				&& self::elementor_map_value_is_valid_translation( $path, $text, (string) $map[ $path ] )
			) {
				return array(
					'path'   => $path,
					'reason' => 'collapsed_base_ok',
					'detail' => 'full field in map but segments listed separately',
					'chars'  => $chars,
				);
			}

			$missing = array();
			$bad     = array();

			foreach ( $seg_lookup as $seg_key => $seg_source ) {
				if ( ! isset( $map[ $seg_key ] ) ) {
					$missing[] = $seg_key;
					continue;
				}

				$translated = trim( (string) $map[ $seg_key ] );
				if ( '' === $translated ) {
					$bad[] = $seg_key . ':empty';
				} elseif ( Persian_Detector::contains_persian( $translated ) ) {
					$bad[] = $seg_key . ':persian';
				} elseif ( $translated === trim( (string) $seg_source ) ) {
					$bad[] = $seg_key . ':unchanged';
				}
			}

			if ( ! empty( $missing ) ) {
				$reason = 'missing_segments';
				$detail = implode( ', ', array_slice( $missing, 0, 4 ) );
			} elseif ( ! empty( $bad ) ) {
				$reason = 'invalid_segments';
				$detail = implode( ', ', array_slice( $bad, 0, 4 ) );
			} elseif (
				isset( $map[ $path ] )
				&& self::elementor_map_value_is_valid_translation( $path, $text, (string) $map[ $path ] )
			) {
				$reason = 'logic_mismatch';
				$detail = 'base_ok_segments_ok_but_still_remaining';
			} else {
				$reason = 'collapsed_base_missing';
				$detail = 'needs_full_field_or_all_segments';
			}

			return compact( 'path', 'reason', 'detail', 'chars' );
		}

		if ( ! isset( $map[ $path ] ) ) {
			$reason = 'not_in_map';
		} else {
			$translated = trim( (string) $map[ $path ] );

			if ( '' === $translated ) {
				$reason = 'empty_translation';
			} elseif ( Persian_Detector::contains_persian( $translated ) ) {
				$reason = 'persian_translation';
			} elseif ( $translated === trim( $text ) ) {
				$reason = 'unchanged_from_source';
			} elseif ( self::utf8_strlen( $text ) > self::AI_MAX_SINGLE_FIELD_CHARS ) {
				$reason = 'long_field_parts_incomplete';
				$parts  = (int) ceil( self::utf8_strlen( $text ) / self::AI_MAX_SINGLE_FIELD_CHARS );
				$have   = 0;

				for ( $part = 1; $part <= $parts; $part++ ) {
					$part_key = $path . '::__part' . $part;
					if (
						isset( $map[ $part_key ] )
						&& self::elementor_map_value_is_valid_translation( $path, $text, (string) $map[ $part_key ] )
					) {
						++$have;
					}
				}

				$detail = sprintf( '%d/%d parts', $have, $parts );
			} else {
				$reason = 'invalid_translation';
			}
		}

		if ( '' === $detail ) {
			$detail = wp_trim_words(
				wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
				10,
				'…'
			);
		}

		return compact( 'path', 'reason', 'detail', 'chars' );
	}

	/**
	 * User-facing gap-fill status with the first remaining field blocker.
	 *
	 * @param int                   $post_id   Post ID.
	 * @param array<string, string> $remaining Remaining source payload.
	 * @param array<string, string> $map       Translation map.
	 * @return string
	 */
	private static function format_elementor_gap_fill_remaining_message( $post_id, array $remaining, array $map ) {
		$post_id = absint( $post_id );
		$count   = count( $remaining );

		if ( $post_id <= 0 || empty( $remaining ) ) {
			return sprintf(
				/* translators: 1: post ID, 2: remaining field count */
				__( 'Elementor — #%1$d: %2$d فیلد باقی‌مانده — تکمیل نهایی…', 'polymart-ai' ),
				$post_id,
				$count
			);
		}

		$first_path = (string) array_key_first( $remaining );
		$first_text = (string) ( $remaining[ $first_path ] ?? '' );
		$blocker    = self::describe_elementor_field_translation_blocker( $first_path, $first_text, $map );
		$detail     = (string) ( $blocker['detail'] ?? '' );

		if ( '' !== $detail ) {
			return sprintf(
				/* translators: 1: post ID, 2: remaining count, 3: field path, 4: blocker reason, 5: blocker detail */
				__( 'Elementor — #%1$d: %2$d فیلد باقی‌مانده — تکمیل نهایی… [%3$s: %4$s — %5$s]', 'polymart-ai' ),
				$post_id,
				$count,
				(string) ( $blocker['path'] ?? $first_path ),
				(string) ( $blocker['reason'] ?? 'unknown' ),
				$detail
			);
		}

		return sprintf(
			/* translators: 1: post ID, 2: remaining count, 3: field path, 4: blocker reason */
			__( 'Elementor — #%1$d: %2$d فیلد باقی‌مانده — تکمیل نهایی… [%3$s: %4$s]', 'polymart-ai' ),
			$post_id,
			$count,
			(string) ( $blocker['path'] ?? $first_path ),
			(string) ( $blocker['reason'] ?? 'unknown' )
		);
	}

	/**
	 * Log why Elementor gap-fill fields remain after API batches.
	 *
	 * @param int                   $post_id        Post ID.
	 * @param string                $lang           Language code.
	 * @param array<string, string> $source_payload Source field map.
	 * @param array<string, string> $map            Translation map.
	 * @param array<int, string>    $skipped        Skipped paths.
	 * @param string                $context        Short context label.
	 * @return void
	 */
	private static function log_elementor_remaining_field_diagnostics( $post_id, $lang, array $source_payload, array $map, array $skipped, $context ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$context = sanitize_text_field( (string) $context );

		if ( $post_id <= 0 || '' === $lang ) {
			return;
		}

		$remaining = self::filter_remaining_elementor_payload( $source_payload, $map, $skipped );

		if ( empty( $remaining ) ) {
			return;
		}

		$lines = array();
		$max   = 8;

		foreach ( $remaining as $path => $text ) {
			if ( count( $lines ) >= $max ) {
				break;
			}

			$blocker = self::describe_elementor_field_translation_blocker( $path, $text, $map );
			$lines[] = sprintf(
				'%s [%s: %s, %d chars]',
				$blocker['path'],
				$blocker['reason'],
				$blocker['detail'],
				(int) $blocker['chars']
			);
		}

		$extra = count( $remaining ) > $max ? sprintf( ' +%d more', count( $remaining ) - $max ) : '';

		\PolymartAI\Activity_Logger::log(
			'warning',
			sprintf(
				/* translators: 1: post ID, 2: remaining count, 3: context, 4: field diagnostics */
				__( 'Elementor — #%1$d: تشخیص %2$d فیلد باقی (%3$s) — %4$s%5$s', 'polymart-ai' ),
				$post_id,
				count( $remaining ),
				$context,
				implode( ' | ', $lines ),
				$extra
			),
			array(
				'post_id'   => $post_id,
				'lang'      => $lang,
				'remaining' => array_keys( $remaining ),
				'context'   => $context,
			)
		);
	}

	/**
	 * Whether an Elementor field (including oversized multi-part values) is fully translated.
	 *
	 * @param string               $path Field path.
	 * @param string               $text Source text.
	 * @param array<string, string> $map Completed translations keyed by path.
	 * @return bool
	 */
	private static function elementor_map_value_is_valid_translation( $path, $source_text, $translated ) {
		$path          = (string) $path;
		$source_text   = trim( (string) $source_text );
		$translated    = trim( (string) $translated );

		if ( '' === $translated || Persian_Detector::contains_persian( $translated ) ) {
			return false;
		}

		if ( '' !== $source_text && $translated === $source_text ) {
			return false;
		}

		unset( $path );

		return true;
	}

	/**
	 * Drop empty, Persian, or unchanged values from an Elementor path map.
	 *
	 * @param array<string, string> $map            Path map.
	 * @param array<string, string> $source_payload Source field map.
	 * @return array<string, string>
	 */
	private static function sanitize_elementor_translation_map( array $map, array $source_payload ) {
		$clean = array();

		foreach ( $map as $path => $value ) {
			$path = (string) $path;

			if ( '' === $path ) {
				continue;
			}

			$source_text = (string) ( $source_payload[ $path ] ?? '' );

			if ( preg_match( '/^(.+)::__part\d+$/', $path, $matches ) ) {
				$source_text = (string) ( $source_payload[ $matches[1] ] ?? $source_text );
			} elseif ( preg_match( '/^(.+)::__seg\d+$/', $path, $matches ) ) {
				$base       = (string) $matches[1];
				$base_text  = (string) ( $source_payload[ $base ] ?? '' );
				$seg_lookup = self::get_elementor_segment_source_lookup( $base, $base_text );
				$source_text = (string) ( $seg_lookup[ $path ] ?? $base_text );
			}

			if ( self::elementor_map_value_is_valid_translation( $path, $source_text, $value ) ) {
				$clean[ $path ] = trim( (string) $value );
			}
		}

		return $clean;
	}

	private static function extract_elementor_segment_map_entries( array $map ) {
		$segment_map = array();

		foreach ( $map as $path => $value ) {
			$path = (string) $path;

			if ( preg_match( '/::__(?:part|seg)\d+$/', $path ) ) {
				$segment_map[ $path ] = (string) $value;
			}
		}

		return $segment_map;
	}

	/**
	 * Lightweight map entries that must survive between Action Scheduler ticks.
	 *
	 * - Segment/part keys persist independently of parent collapse.
	 * - Complete short base paths persist so done fields are never re-queued.
	 *
	 * @param array<string, string> $map            Translation path map.
	 * @param array<string, string> $source_payload Source field map.
	 * @return array<string, string>
	 */
	private static function extract_elementor_persist_map_entries( array $map, array $source_payload ) {
		$persist = array();
		$clean   = self::sanitize_elementor_translation_map( $map, $source_payload );

		foreach ( $clean as $path => $value ) {
			$path = (string) $path;

			if ( preg_match( '/::__(?:part|seg)\d+$/', $path ) ) {
				$persist[ $path ] = (string) $value;
				continue;
			}

			$source = (string) ( $source_payload[ $path ] ?? '' );

			if ( '' === $source ) {
				continue;
			}

			if ( self::elementor_field_translation_complete( $path, $source, $clean ) ) {
				$persist[ $path ] = (string) $value;
			}
		}

		return $persist;
	}

	/**
	 * Merge durable map progress into partial job state.
	 *
	 * @param array<string, mixed>  $state          Partial state (by reference).
	 * @param array<string, string> $map            Current translation map.
	 * @param array<string, string> $source_payload Source field map.
	 * @return void
	 */
	private static function sync_elementor_persist_map_state( array &$state, array $map, array $source_payload ) {
		$existing = is_array( $state['elementor_persist_map'] ?? null ) ? $state['elementor_persist_map'] : array();

		if ( is_array( $state['elementor_seg_map'] ?? null ) ) {
			$existing = array_merge( $state['elementor_seg_map'], $existing );
			unset( $state['elementor_seg_map'] );
		}

		$state['elementor_persist_map'] = array_merge(
			$existing,
			self::extract_elementor_persist_map_entries( $map, $source_payload )
		);
	}

	/**
	 * Stable fingerprint for a gap-fill API chunk (dedupes queue + counters).
	 *
	 * @param array<string, string> $chunk Chunk payload.
	 * @return string
	 */
	private static function elementor_chunk_fingerprint( array $chunk ) {
		$keys = array_map( 'strval', array_keys( $chunk ) );
		sort( $keys, SORT_STRING );

		return implode( '|', $keys );
	}

	/**
	 * Register unique gap-fill chunk keys without inflating totals on duplicates.
	 *
	 * @param array<string, mixed>              $state  Partial state (by reference).
	 * @param array<int, array<string, string>> $chunks Pending chunks this tick.
	 * @return void
	 */
	private static function schedule_elementor_gap_fill_chunks( array &$state, array $chunks ) {
		$scheduled = is_array( $state['elementor_gap_fill_scheduled_keys'] ?? null )
			? array_values( array_unique( array_map( 'strval', $state['elementor_gap_fill_scheduled_keys'] ) ) )
			: array();
		$finished  = is_array( $state['elementor_gap_fill_finished_keys'] ?? null )
			? array_values( array_unique( array_map( 'strval', $state['elementor_gap_fill_finished_keys'] ) ) )
			: array();

		foreach ( $chunks as $chunk ) {
			if ( ! is_array( $chunk ) || empty( $chunk ) ) {
				continue;
			}

			$fingerprint = self::elementor_chunk_fingerprint( $chunk );

			if ( in_array( $fingerprint, $scheduled, true ) || in_array( $fingerprint, $finished, true ) ) {
				continue;
			}

			$scheduled[] = $fingerprint;
		}

		$state['elementor_gap_fill_scheduled_keys'] = $scheduled;
		$state['elementor_gap_fill_finished_keys']  = $finished;
		$state['elementor_gap_fill_done']           = count( $finished );
		$state['elementor_gap_fill_total']          = max( 1, count( $scheduled ) );
	}

	/**
	 * Mark a gap-fill chunk as finished (idempotent).
	 *
	 * @param array<string, mixed>  $state Partial state (by reference).
	 * @param array<string, string> $chunk Chunk payload.
	 * @return void
	 */
	private static function register_elementor_gap_fill_chunk_done( array &$state, array $chunk ) {
		if ( empty( $chunk ) ) {
			return;
		}

		$fingerprint = self::elementor_chunk_fingerprint( $chunk );
		$finished    = is_array( $state['elementor_gap_fill_finished_keys'] ?? null )
			? array_values( array_unique( array_map( 'strval', $state['elementor_gap_fill_finished_keys'] ) ) )
			: array();

		if ( in_array( $fingerprint, $finished, true ) ) {
			$state['elementor_gap_fill_done'] = count( $finished );
			return;
		}

		$finished[] = $fingerprint;
		$state['elementor_gap_fill_finished_keys'] = $finished;
		$state['elementor_gap_fill_done']        = count( $finished );
	}

	/**
	 * Drop gap-fill chunks whose paths are already translated.
	 *
	 * @param array<int, array<string, string>> $chunks         Candidate chunks.
	 * @param array<string, string>             $map            Translation map.
	 * @param array<string, string>             $source_payload Source field map.
	 * @return array<int, array<string, string>>
	 */
	private static function filter_elementor_gap_fill_pending_chunks( array $chunks, array $map, array $source_payload ) {
		$pending = array();

		foreach ( $chunks as $chunk ) {
			if ( ! is_array( $chunk ) || empty( $chunk ) ) {
				continue;
			}

			if ( self::elementor_chunk_paths_translated( $chunk, $map, $source_payload ) ) {
				continue;
			}

			$pending[] = $chunk;
		}

		return $pending;
	}

	/**
	 * Pending segment batches for one long Elementor field (missing __segN only).
	 *
	 * @param string                $path           Base field path.
	 * @param string                $text           Source text.
	 * @param array<string, string> $map            Translation map.
	 * @param array<string, string> $source_payload Source field map.
	 * @return array<int, array<string, string>>
	 */
	private static function build_elementor_stubborn_segment_batches( $path, $text, array $map, array $source_payload ) {
		$path = (string) $path;
		$text = (string) $text;

		if ( '' === $path || '' === $text ) {
			return array();
		}

		$field_keys = self::expand_elementor_payload_for_ai( array( $path => $text ) );
		$batches    = self::chunk_payload_with_limits( $field_keys, 2, self::ELEMENTOR_JOB_MAX_CHUNK_CHARS, false );
		$pending    = array();

		foreach ( $batches as $batch ) {
			if ( ! self::elementor_chunk_paths_translated( $batch, $map, $source_payload ) ) {
				$pending[] = $batch;
			}
		}

		return $pending;
	}

	/**
	 * Drop segment/part keys once their base Elementor field is fully translated.
	 *
	 * @param array<string, string> $map            Translation path map.
	 * @param array<string, string> $source_payload Source field map.
	 * @return array<string, string>
	 */
	private static function prune_complete_elementor_segment_map_entries( array $map, array $source_payload ) {
		$pruned = $map;

		foreach ( $source_payload as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( ! self::elementor_field_translation_complete( $path, $text, $pruned ) ) {
				continue;
			}

			foreach ( self::get_elementor_segment_keys( $path, $text ) as $seg_key ) {
				unset( $pruned[ $seg_key ] );
			}

			$parts = (int) ceil( self::utf8_strlen( $text ) / self::AI_MAX_SINGLE_FIELD_CHARS );

			for ( $part = 1; $part <= $parts; $part++ ) {
				unset( $pruned[ $path . '::__part' . $part ] );
			}
		}

		return $pruned;
	}

	/**
	 * Merge completed __seg/__part entries into base paths for Elementor JSON persistence.
	 *
	 * @param array<string, string> $map            Translation path map.
	 * @param array<string, string> $source_payload Source field map.
	 * @return array<string, string>
	 */
	private static function prepare_elementor_map_for_persist( array $map, array $source_payload ) {
		$prepared = $map;

		foreach ( $source_payload as $path => $text ) {
			$path     = (string) $path;
			$text     = (string) $text;
			$seg_keys = self::get_elementor_segment_keys( $path, $text );

			if ( empty( $seg_keys ) ) {
				continue;
			}

			$seg_lookup = self::get_elementor_segment_source_lookup( $path, $text );
			$parts      = array();

			foreach ( $seg_keys as $seg_key ) {
				$seg_source = (string) ( $seg_lookup[ $seg_key ] ?? '' );

				if (
					! isset( $prepared[ $seg_key ] )
					|| ! self::elementor_map_value_is_valid_translation( $seg_key, $seg_source, (string) $prepared[ $seg_key ] )
				) {
					$parts = array();
					break;
				}

				$parts[] = (string) $prepared[ $seg_key ];
			}

			if ( empty( $parts ) ) {
				continue;
			}

			$prepared[ $path ] = implode( '', $parts );

			foreach ( $seg_keys as $seg_key ) {
				unset( $prepared[ $seg_key ] );
			}
		}

		return $prepared;
	}

	/**
	 * Whether every expected segment for a long Elementor field is present in a raw API map.
	 *
	 * @param string               $base         Base field path.
	 * @param string               $source       Source text.
	 * @param array<string, string> $raw_mapped Raw API response map.
	 * @return bool
	 */
	private static function elementor_all_segments_present_in_map( $base, $source, array $raw_mapped ) {
		$base       = (string) $base;
		$source     = (string) $source;
		$seg_lookup = self::get_elementor_segment_source_lookup( $base, $source );

		if ( empty( $seg_lookup ) ) {
			return false;
		}

		foreach ( $seg_lookup as $seg_key => $seg_source ) {
			if (
				! isset( $raw_mapped[ $seg_key ] )
				|| ! self::elementor_map_value_is_valid_translation( $seg_key, (string) $seg_source, (string) $raw_mapped[ $seg_key ] )
			) {
				return false;
			}
		}

		return true;
	}

	private static function elementor_collapsed_base_translation_complete( $path, $text, array $map ) {
		$path = (string) $path;
		$text = (string) $text;

		// Partial segment collapse must never satisfy completion for long fields.
		if ( ! empty( self::get_elementor_segment_keys( $path, $text ) ) ) {
			return false;
		}

		if ( ! isset( $map[ $path ] ) ) {
			return false;
		}

		$translated = trim( (string) $map[ $path ] );

		if ( ! self::elementor_map_value_is_valid_translation( $path, $text, $translated ) ) {
			return false;
		}

		return self::utf8_strlen( $translated ) >= (int) floor( self::utf8_strlen( $text ) * 0.85 );
	}

	private static function merge_elementor_api_translations_into_map( array $raw_mapped, array $source_payload ) {
		$mapped = $raw_mapped;

		foreach ( self::collapse_payload_parts( $raw_mapped ) as $base => $value ) {
			$base = (string) $base;

			if ( '' === $base || preg_match( '/::__(?:part|seg)\d+$/', $base ) ) {
				continue;
			}

			$source = (string) ( $source_payload[ $base ] ?? '' );

			if ( self::elementor_all_segments_present_in_map( $base, $source, $raw_mapped ) ) {
				$mapped[ $base ] = (string) $value;
				continue;
			}

			if ( self::elementor_collapsed_base_translation_complete( $base, $source, array( $base => (string) $value ) ) ) {
				$mapped[ $base ] = (string) $value;
			}
		}

		return $mapped;
	}

	private static function elementor_field_translation_complete( $path, $text, array $map ) {
		$path = (string) $path;
		$text = (string) $text;

		// For very long Elementor fields, require all __segN pieces (or one collapsed base value).
		$seg_lookup = self::get_elementor_segment_source_lookup( $path, $text );
		if ( ! empty( $seg_lookup ) ) {
			$all_segs_ok = true;

			foreach ( $seg_lookup as $seg_key => $seg_source ) {
				if (
					! isset( $map[ $seg_key ] )
					|| ! self::elementor_map_value_is_valid_translation( $seg_key, (string) $seg_source, (string) $map[ $seg_key ] )
				) {
					$all_segs_ok = false;
					break;
				}
			}

			if ( $all_segs_ok ) {
				return true;
			}

			return false;
		}

		if ( self::utf8_strlen( $text ) <= self::AI_MAX_SINGLE_FIELD_CHARS ) {
			return isset( $map[ $path ] )
				&& self::elementor_map_value_is_valid_translation( $path, $text, (string) $map[ $path ] );
		}

		if ( isset( $map[ $path ] ) && '' !== trim( (string) $map[ $path ] ) ) {
			$translated = (string) $map[ $path ];

			if (
				self::elementor_map_value_is_valid_translation( $path, $text, $translated )
				&& self::utf8_strlen( $translated ) >= (int) floor( self::utf8_strlen( $text ) * 0.85 )
			) {
				return true;
			}
		}

		$parts = (int) ceil( self::utf8_strlen( $text ) / self::AI_MAX_SINGLE_FIELD_CHARS );

		for ( $part = 1; $part <= $parts; $part++ ) {
			$part_key = $path . '::__part' . $part;

			if (
				! isset( $map[ $part_key ] )
				|| ! self::elementor_map_value_is_valid_translation( $path, $text, (string) $map[ $part_key ] )
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Smaller Elementor batches for auto-translate job steps.
	 *
	 * @param array<string, string> $payload Field map.
	 * @return array<int, array<string, string>>
	 */
	private static function chunk_elementor_payload_for_job( array $payload ) {
		$chunk_size = \PolymartAI\Activity_Logger::is_bulk_job_running()
			? 1
			: self::ELEMENTOR_JOB_FIELD_CHUNK_SIZE;

		/**
		 * Filter Elementor fields per API batch in job slices.
		 *
		 * @param int                   $chunk_size Fields per batch.
		 * @param array<string, string> $payload    Source field map.
		 */
		$chunk_size = max( 1, (int) apply_filters( 'polymart_ai_elementor_job_field_chunk_size', $chunk_size, $payload ) );

		// Sub-chunk long Elementor fields (paragraph-aware) before batching.
		$payload = self::expand_elementor_payload_for_ai( $payload );

		return self::chunk_payload_with_limits(
			$payload,
			$chunk_size,
			self::ELEMENTOR_JOB_MAX_CHUNK_CHARS
		);
	}

	/**
	 * Collapse Elementor fields that share identical Persian source text.
	 *
	 * @param array<string, string> $payload Path => source text.
	 * @return array{payload: array<string, string>, mirrors: array<string, string[]>}
	 */
	private static function collapse_duplicate_elementor_payload( array $payload ) {
		$text_paths = array();

		foreach ( $payload as $path => $text ) {
			$path = (string) $path;
			$text = trim( (string) $text );

			if ( '' === $path || '' === $text ) {
				continue;
			}

			if ( ! isset( $text_paths[ $text ] ) ) {
				$text_paths[ $text ] = array();
			}

			$text_paths[ $text ][] = $path;
		}

		$collapsed = array();
		$mirrors   = array();

		foreach ( $text_paths as $text => $paths ) {
			$canonical = (string) $paths[0];

			$collapsed[ $canonical ] = $text;

			if ( count( $paths ) > 1 ) {
				$mirrors[ $canonical ] = array_map( 'strval', array_slice( $paths, 1 ) );
			}
		}

		return array(
			'payload' => $collapsed,
			'mirrors' => $mirrors,
		);
	}

	/**
	 * Copy canonical Elementor translations onto duplicate source paths.
	 *
	 * @param array<string, string> $map     Path map.
	 * @param array<string, string[]> $mirrors Canonical path => duplicate paths.
	 * @return array<string, string>
	 */
	private static function expand_elementor_map_mirrors( array $map, array $mirrors ) {
		foreach ( $mirrors as $canonical => $paths ) {
			$canonical = (string) $canonical;

			if ( ! isset( $map[ $canonical ] ) || '' === trim( (string) $map[ $canonical ] ) ) {
				continue;
			}

			$value = (string) $map[ $canonical ];

			foreach ( (array) $paths as $path ) {
				$path = (string) $path;

				if ( '' !== $path ) {
					$map[ $path ] = $value;
				}
			}
		}

		return $map;
	}

	/**
	 * Build mirror lookup for identical Elementor source strings.
	 *
	 * @param array<string, string> $payload Full source field map.
	 * @return array<string, string[]>
	 */
	private static function get_elementor_text_mirror_paths( array $payload ) {
		return self::collapse_duplicate_elementor_payload( $payload )['mirrors'];
	}

	/**
	 * Pending Elementor API chunks using the monotonic slice cursor.
	 *
	 * Uses the full source payload (not filter_remaining alone) so recovery can
	 * continue after slice 4/11 even when the remaining-field map is stale.
	 *
	 * @param int                  $post_id     Post ID.
	 * @param string               $lang        Language code.
	 * @param array<string, mixed> $source_data Source Elementor tree.
	 * @param array<string, mixed> $state       Hydrated partial state.
	 * @return array<int, array<string, string>>
	 */
	private static function build_elementor_gap_fill_chunks( array $source_data, array $map, array $skipped ) {
		$remaining = self::filter_remaining_elementor_payload(
			self::collect_elementor_translation_payload( $source_data ),
			$map,
			$skipped
		);

		if ( empty( $remaining ) ) {
			return array();
		}

		$remaining = self::filter_elementor_gap_fill_batch_remaining( $remaining );

		if ( empty( $remaining ) ) {
			return array();
		}

		$remaining = self::sort_elementor_gap_fill_remaining( $remaining );

		return self::filter_elementor_gap_fill_pending_chunks(
			self::chunk_elementor_gap_fill_payload( $remaining ),
			$map,
			self::collect_elementor_translation_payload( $source_data )
		);
	}

	/**
	 * Gap-fill API batches only cover short fields — long/segmented paths use stubborn passes.
	 *
	 * @param array<string, string> $remaining Path => source text.
	 * @return array<string, string>
	 */
	private static function filter_elementor_gap_fill_batch_remaining( array $remaining ) {
		$batch_remaining = array();

		foreach ( $remaining as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( self::stubborn_elementor_field_is_long( $path, $text ) ) {
				continue;
			}

			$batch_remaining[ $path ] = $text;
		}

		return $batch_remaining;
	}

	/**
	 * Keep gap-fill batch totals monotonic — never re-sum done + pending every tick.
	 *
	 * @param array<string, mixed>        $state  Partial state (by reference).
	 * @param array<int, array<string, string>> $chunks Pending gap-fill chunks this tick.
	 * @return array{done: int, total: int, pending: int}
	 */
	private static function resolve_elementor_gap_fill_totals( array &$state, array $chunks ) {
		self::schedule_elementor_gap_fill_chunks( $state, $chunks );

		return array(
			'done'    => absint( $state['elementor_gap_fill_done'] ?? 0 ),
			'total'   => max( 1, absint( $state['elementor_gap_fill_total'] ?? 0 ) ),
			'pending' => count( $chunks ),
		);
	}

	/**
	 * Short UI strings first — they unblock gap-fill faster than long HTML blocks.
	 *
	 * @param array<string, string> $remaining Path => source text.
	 * @return array<string, string>
	 */
	private static function sort_elementor_gap_fill_remaining( array $remaining ) {
		uasort(
			$remaining,
			static function ( $a, $b ) {
				$left  = self::utf8_strlen( (string) $a );
				$right = self::utf8_strlen( (string) $b );

				if ( $left === $right ) {
					return 0;
				}

				return $left <=> $right;
			}
		);

		return $remaining;
	}

	/**
	 * Direct single-field API pass for stubborn gap-fill paths (not_in_map / missing_segments).
	 *
	 * @param int                   $post_id        Post ID.
	 * @param string                $lang           Language code.
	 * @param array<string, mixed>  $source_data    Source Elementor tree.
	 * @param array<string, mixed>  $state          Partial state (by reference).
	 * @param array<string, string> $map            Translation map (by reference).
	 * @param array<string, string> $source_payload Source field map.
	 * @param array<string, string[]> $text_mirrors Mirror lookup.
	 * @param string                $api_key        API key.
	 * @param string                $api_endpoint   API endpoint.
	 * @param string                $ai_model       Model id.
	 * @param int                   $max_fields     Max base fields per tick.
	 * @return int Number of base fields completed.
	 */
	private static function translate_elementor_gap_fill_stubborn_fields(
		$post_id,
		$lang,
		array $source_data,
		array &$state,
		array &$map,
		array $source_payload,
		array $text_mirrors,
		$api_key,
		$api_endpoint,
		$ai_model,
		$max_fields = 2
	) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return array(
				'completed' => 0,
				'touched'   => false,
			);
		}

		$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$remaining = self::sort_elementor_gap_fill_remaining(
			self::filter_remaining_elementor_payload( $source_payload, $map, $skipped )
		);

		if ( empty( $remaining ) ) {
			return array(
				'completed' => 0,
				'touched'   => false,
			);
		}

		$failures    = is_array( $state['elementor_failures'] ?? null ) ? $state['elementor_failures'] : array();
		$done        = 0;
		$touched     = false;
		$short       = array();
		$long        = array();
		$persist_map = is_array( $state['elementor_persist_map'] ?? null ) ? $state['elementor_persist_map'] : array();
		$api_budget  = max( 2, min( 8, absint( $max_fields ) * 2 ) );
		$spent       = 0;

		foreach ( $remaining as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( isset( $persist_map[ $path ] ) && self::elementor_field_translation_complete( $path, $text, $persist_map ) ) {
				$map[ $path ] = (string) $persist_map[ $path ];
				++$done;
				continue;
			}

			if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
				++$done;
				continue;
			}

			if ( self::stubborn_elementor_field_is_long( $path, $text ) ) {
				$long[ $path ] = $text;
			} else {
				$short[ $path ] = $text;
			}
		}

		// Priority 1: long fields — send missing __segN batches first.
		foreach ( $long as $path => $text ) {
			if ( $spent >= $api_budget ) {
				break;
			}

			if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
				++$done;
				continue;
			}

			$pending_batches = self::build_elementor_stubborn_segment_batches( $path, $text, $map, $source_payload );
			$batch_total     = max( 1, count( $pending_batches ) );

			foreach ( $pending_batches as $batch_index => $batch ) {
				if ( $spent >= $api_budget ) {
					break;
				}

				$seg_keys = implode( ', ', array_keys( $batch ) );
				\PolymartAI\Activity_Logger::log(
					'info',
					sprintf(
						/* translators: 1: post ID, 2: base path, 3: segment keys */
						__( 'Elementor — #%1$d: stubborn segment — %2$s → %3$s', 'polymart-ai' ),
						$post_id,
						$path,
						$seg_keys
					),
					array( 'post_id' => $post_id, 'lang' => $lang, 'path' => $path )
				);

				$progress = self::translate_elementor_stubborn_field_batch(
					$post_id,
					$lang,
					$path,
					$text,
					$batch,
					$source_data,
					$source_payload,
					$text_mirrors,
					$state,
					$map,
					$failures,
					$api_key,
					$api_endpoint,
					$ai_model,
					$batch_index + 1,
					$batch_total
				);

				++$spent;

				if ( ! empty( $progress['touched'] ) ) {
					$touched = true;
					self::sync_elementor_persist_map_state( $state, $map, $source_payload );
				}
			}

			if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
				++$done;
				\PolymartAI\Activity_Logger::log(
					'info',
					sprintf(
						/* translators: 1: post ID, 2: field path */
						__( 'Elementor — #%1$d: stubborn تکمیل شد — %2$s', 'polymart-ai' ),
						$post_id,
						$path
					),
					array( 'post_id' => $post_id, 'lang' => $lang, 'path' => $path )
				);
			} elseif ( $touched ) {
				$blocker = self::describe_elementor_field_translation_blocker( $path, $text, $map );
				self::log_elementor_remaining_field_diagnostics(
					$post_id,
					$lang,
					$source_payload,
					$map,
					$skipped,
					sprintf(
						'stubborn-long-partial-%s-%s',
						$path,
						(string) ( $blocker['reason'] ?? 'partial' )
					)
				);
			}
		}

		// Priority 2: short fields — only if budget remains and not already persisted.
		foreach ( array_slice( $short, 0, 2, true ) as $path => $text ) {
			if ( $spent >= $api_budget ) {
				break;
			}

			if ( isset( $persist_map[ $path ] ) && self::elementor_field_translation_complete( $path, $text, $persist_map ) ) {
				$map[ $path ] = (string) $persist_map[ $path ];
				++$done;
				continue;
			}

			if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
				++$done;
				continue;
			}

			$progress = self::translate_elementor_stubborn_field_batch(
				$post_id,
				$lang,
				$path,
				$text,
				array( $path => $text ),
				$source_data,
				$source_payload,
				$text_mirrors,
				$state,
				$map,
				$failures,
				$api_key,
				$api_endpoint,
				$ai_model,
				1,
				1
			);

			++$spent;

			if ( ! empty( $progress['touched'] ) ) {
				$touched = true;
				self::sync_elementor_persist_map_state( $state, $map, $source_payload );
			}

			if ( ! empty( $progress['completed'] ) ) {
				++$done;
			}
		}

		$state['elementor_failures'] = $failures;
		$state['elementor_map']      = $map;

		return array(
			'completed' => $done,
			'touched'   => $touched,
		);
	}

	/**
	 * @param string               $path        Base Elementor path.
	 * @param string               $text        Base source text.
	 * @return bool
	 */
	private static function stubborn_elementor_field_is_long( $path, $text ) {
		$path = (string) $path;
		$text = (string) $text;

		return ! empty( self::get_elementor_segment_source_lookup( $path, $text ) )
			|| self::utf8_strlen( $text ) > self::ELEMENTOR_LONG_FIELD_SEGMENT_CHARS;
	}

	/**
	 * Run one stubborn API batch and merge results into the Elementor map.
	 *
	 * @param int                   $post_id        Post ID.
	 * @param string                $lang           Language code.
	 * @param string                $path           Base field path.
	 * @param string                $text           Base source text.
	 * @param array<string, string> $batch          API payload batch.
	 * @param array<string, mixed>  $source_data    Source tree.
	 * @param array<string, string> $source_payload Source field map.
	 * @param array<string, string[]> $text_mirrors Mirror lookup.
	 * @param array<string, mixed>  $state          Partial state (by reference).
	 * @param array<string, string> $map            Translation map (by reference).
	 * @param array<string, int>    $failures       Failure counts (by reference).
	 * @param string                $api_key        API key.
	 * @param string                $api_endpoint   API endpoint.
	 * @param string                $ai_model       Model id.
	 * @param int                   $batch_index    Batch index for logs.
	 * @param int                   $batch_total    Total batches for logs.
	 * @return array{completed: bool, touched: bool}
	 */
	private static function translate_elementor_stubborn_field_batch(
		$post_id,
		$lang,
		$path,
		$text,
		array $batch,
		array $source_data,
		array $source_payload,
		array $text_mirrors,
		array &$state,
		array &$map,
		array &$failures,
		$api_key,
		$api_endpoint,
		$ai_model,
		$batch_index,
		$batch_total
	) {
		$path        = (string) $path;
		$text        = (string) $text;
		$batch_index = max( 1, absint( $batch_index ) );
		$batch_total = max( 1, absint( $batch_total ) );
		$touched     = false;
		$completed   = false;

		if ( '' === $path || empty( $batch ) ) {
			return compact( 'completed', 'touched' );
		}

		if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
			return array(
				'completed' => true,
				'touched'   => false,
			);
		}

		list( $aliased, $alias_map ) = self::alias_elementor_payload_keys( $batch );

		if ( empty( $aliased ) ) {
			return compact( 'completed', 'touched' );
		}

		$part_count = count( $aliased );
		$log_label  = 1 === $batch_total && 1 === $part_count
			? sprintf(
				/* translators: 1: post ID, 2: field path, 3: char count */
				__( 'Elementor — #%1$d: ترجمه مستقیم فیلد stubborn — %2$s (%3$d نویسه)', 'polymart-ai' ),
				absint( $post_id ),
				$path,
				self::utf8_strlen( $text )
			)
			: sprintf(
				/* translators: 1: post ID, 2: field path, 3: batch index, 4: batch total, 5: part count */
				__( 'Elementor — #%1$d: stubborn %2$s — بخش %3$d از %4$d (%5$d part)', 'polymart-ai' ),
				absint( $post_id ),
				$path,
				$batch_index,
				$batch_total,
				$part_count
			);

		\PolymartAI\Activity_Logger::log(
			'info',
			$log_label,
			array( 'post_id' => $post_id, 'lang' => $lang, 'path' => $path )
		);

		\PolymartAI\Activity_Logger::wait_for_arvan_api_gap();
		\PolymartAI\Activity_Logger::touch_job_worker_heartbeat();

		$result = AI_Client::translate_fields(
			$aliased,
			$api_key,
			$api_endpoint,
			$ai_model,
			$lang,
			array( 'max_timeout' => 45 )
		);

		\PolymartAI\Activity_Logger::touch_arvan_api_attempt();
		\PolymartAI\Activity_Logger::touch_job_worker_heartbeat();

		if ( is_wp_error( $result ) ) {
			\PolymartAI\Activity_Logger::log(
				'warning',
				sprintf(
					/* translators: 1: post ID, 2: field path, 3: error */
					__( 'Elementor — #%1$d: stubborn %2$s — خطای API: %3$s', 'polymart-ai' ),
					absint( $post_id ),
					$path,
					\PolymartAI\Activity_Logger::humanize_api_error_message( $result->get_error_message() )
				),
				array( 'post_id' => $post_id, 'lang' => $lang, 'path' => $path )
			);

			$failures[ $path ] = absint( $failures[ $path ] ?? 0 ) + 1;

			return compact( 'completed', 'touched' );
		}

		$raw_mapped = self::unmap_elementor_aliases(
			is_array( $result ) ? $result : array(),
			$alias_map
		);
		$field_map = self::merge_elementor_api_translations_into_map( $raw_mapped, $source_payload );

		foreach ( $field_map as $map_path => $translated ) {
			$map_path   = (string) $map_path;
			$translated = trim( (string) $translated );

			if ( '' === $translated || Persian_Detector::contains_persian( $translated ) ) {
				\PolymartAI\Activity_Logger::log(
					'warning',
					sprintf(
						/* translators: 1: post ID, 2: map path, 3: reason */
						__( 'Elementor — #%1$d: stubborn رد شد — %2$s (%3$s)', 'polymart-ai' ),
						absint( $post_id ),
						$map_path,
						'' === $translated ? 'empty' : 'persian'
					),
					array( 'post_id' => $post_id, 'lang' => $lang, 'path' => $map_path )
				);
				$failures[ $path ] = absint( $failures[ $path ] ?? 0 ) + 1;
				continue;
			}

			$source_snippet = (string) ( $batch[ $map_path ] ?? $source_payload[ $map_path ] ?? $text );
			if ( preg_match( '/^(.+)::__(?:part|seg)\d+$/', $map_path, $matches ) ) {
				$source_snippet = (string) ( $batch[ $map_path ] ?? $source_payload[ $matches[1] ] ?? $text );
			}

			if ( ! self::elementor_map_value_is_valid_translation( $map_path, $source_snippet, $translated ) ) {
				\PolymartAI\Activity_Logger::log(
					'warning',
					sprintf(
						/* translators: 1: post ID, 2: map path */
						__( 'Elementor — #%1$d: stubborn رد شد — %2$s (unchanged/invalid)', 'polymart-ai' ),
						absint( $post_id ),
						$map_path
					),
					array( 'post_id' => $post_id, 'lang' => $lang, 'path' => $map_path )
				);
				continue;
			}

			$map[ $map_path ] = $translated;
			$touched          = true;
		}

		$map                    = self::expand_elementor_map_mirrors( $map, $text_mirrors );
		$map                    = self::merge_elementor_path_map( $post_id, $lang, $source_data, $map );
		$map                    = self::sanitize_elementor_translation_map( $map, $source_payload );
		$state['elementor_map'] = $map;

		if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
			$completed = true;
			\PolymartAI\Activity_Logger::log(
				'info',
				sprintf(
					/* translators: 1: post ID, 2: field path */
					__( 'Elementor — #%1$d: stubborn تکمیل شد — %2$s', 'polymart-ai' ),
					absint( $post_id ),
					$path
				),
				array( 'post_id' => $post_id, 'lang' => $lang, 'path' => $path )
			);
		}

		return compact( 'completed', 'touched' );
	}

	/**
	 * Fewer, larger API batches for post-21/21 gap-fill (avoid 1-field-per-call).
	 *
	 * @param array<string, string> $payload Remaining field map.
	 * @return array<int, array<string, string>>
	 */
	private static function chunk_elementor_gap_fill_payload( array $payload ) {
		$collapsed = self::collapse_duplicate_elementor_payload( $payload );
		$expanded  = self::expand_elementor_payload_for_ai( $collapsed['payload'] );

		if ( empty( $expanded ) ) {
			return array();
		}

		// Small batches only — one translate_fields() call must stay a single HTTP round-trip.
		return self::chunk_payload_with_limits(
			$expanded,
			3,
			self::ELEMENTOR_JOB_MAX_CHUNK_CHARS,
			false
		);
	}

	/**
	 * @param int                  $post_id     Post ID.
	 * @param string               $lang        Language code.
	 * @param array<string, mixed> $source_data Source Elementor tree.
	 * @param array<string, mixed> $state       Hydrated partial state.
	 * @return array{all: array<int, array<string, string>>, pending: array<int, array<string, string>>, total: int, cursor: int}
	 */
	private static function build_elementor_job_chunk_queue( $post_id, $lang, array $source_data, array $state ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		$source_payload = self::collect_elementor_translation_payload( $source_data );
		$collapsed      = self::collapse_duplicate_elementor_payload( $source_payload );
		$all_chunks     = self::chunk_elementor_payload_for_job( $collapsed['payload'] );
		$total          = max( 1, count( $all_chunks ) );

		$map     = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$map     = self::merge_elementor_path_map( $post_id, $lang, $source_data, $map );
		$skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$skipped_lookup = array_flip( array_map( 'strval', $skipped ) );

		$stored_cursor  = self::read_elementor_slice_cursor( $post_id, $lang );
		$pending        = array();

		foreach ( $all_chunks as $idx => $chunk ) {
			if ( ! is_array( $chunk ) || empty( $chunk ) ) {
				continue;
			}

			if ( $idx < $stored_cursor ) {
				continue;
			}

			$needs_work = false;

			foreach ( $chunk as $path => $text ) {
				$path = (string) $path;

				if ( isset( $skipped_lookup[ $path ] ) ) {
					continue;
				}

				if ( ! self::elementor_field_translation_complete( $path, (string) $text, $map ) ) {
					$needs_work = true;
					break;
				}
			}

			if ( $needs_work ) {
				$pending[] = $chunk;
			}
		}

		$computed = max( 0, $total - count( $pending ) );
		$cursor   = max( $stored_cursor, $computed );

		return array(
			'all'     => $all_chunks,
			'pending' => $pending,
			'total'   => $total,
			'cursor'  => $cursor,
			'mirrors' => $collapsed['mirrors'],
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
	private static function merge_elementor_job_path_map( $post_id, $lang, array $source_data, array $state = array(), array $overlay_map = array() ) {
		$state_map   = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$persist_map = is_array( $state['elementor_persist_map'] ?? null ) ? $state['elementor_persist_map'] : array();
		$seg_map     = is_array( $state['elementor_seg_map'] ?? null ) ? $state['elementor_seg_map'] : array();
		$rebuilt     = self::rebuild_elementor_map_from_saved_translation( $post_id, $lang, $source_data );

		return array_merge( $rebuilt, $persist_map, $seg_map, $state_map, $overlay_map );
	}

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
			$source_text = (string) $source_text;
			$source_raw  = self::get_elementor_value_at_path( $source_data, $path );

			if ( ! is_string( $source_raw ) || '' === trim( $source_raw ) ) {
				$source_raw = $source_text;
			}

			$translated = self::get_elementor_value_at_path( $saved, $path );

			if ( ! is_string( $translated ) || '' === trim( $translated ) ) {
				continue;
			}

			$translated = trim( $translated );

			// Still the Persian source — not a completed translation.
			if ( $translated === trim( (string) $source_raw ) ) {
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
		\PolymartAI\Activity_Logger::bootstrap_job_worker_context();
		\PolymartAI\Activity_Logger::touch_job_worker_heartbeat();

		if ( ! self::ensure_translation_lock_for_persist( $post_id, $lang ) ) {
			\PolymartAI\Activity_Logger::log(
				'warning',
				sprintf(
					/* translators: 1: post ID, 2: done count, 3: total chunks */
					__( 'Elementor — #%1$d: ذخیره بخش %2$d/%3$d رد شد — قفل ترجمه در اختیار کارگر دیگر است.', 'polymart-ai' ),
					absint( $post_id ),
					absint( $done_count ),
					absint( $total_chunks )
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);

			return new \WP_Error(
				'polymart_ai_translation_in_progress',
				__( 'این مورد در حال ترجمه است. لطفاً چند لحظه صبر کنید.', 'polymart-ai' )
			);
		}

		$partial_state = self::get_job_partial_state( $post_id, $lang );
		$source_payload = self::collect_elementor_translation_payload( $source_data );
		$map            = self::sanitize_elementor_translation_map(
			self::merge_elementor_path_map( $post_id, $lang, $source_data, $map ),
			$source_payload
		);
		$map            = self::prepare_elementor_map_for_persist( $map, $source_payload );
		$chunk_progress = self::get_elementor_chunk_progress(
			$post_id,
			$lang,
			array(
				'elementor_map'          => $map,
				'elementor_skipped'      => is_array( $partial_state['elementor_skipped'] ?? null ) ? $partial_state['elementor_skipped'] : array(),
				'elementor_chunks_total' => max( 1, absint( $total_chunks ) ),
			)
		);
		$total_chunks = max( absint( $total_chunks ), (int) $chunk_progress['total'], 1 );
		$done_count   = min(
			$total_chunks,
			max(
				(int) $chunk_progress['done'],
				absint( $done_count ),
				self::read_elementor_slice_cursor( $post_id, $lang )
			)
		);

		$tree = self::apply_elementor_translation_payload( $source_data, $map );
		\PolymartAI\Activity_Logger::touch_job_worker_heartbeat();
		$json = wp_json_encode( $tree );

		if ( false === $json || '' === $json ) {
			\PolymartAI\Activity_Logger::log(
				'error',
				sprintf(
					/* translators: 1: post ID */
					__( 'Elementor — #%1$d: wp_json_encode برای ذخیره ترجمه ناموفق بود.', 'polymart-ai' ),
					absint( $post_id )
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);

			return new \WP_Error(
				'polymart_ai_elementor_save_failed',
				__( 'ذخیره ترجمه Elementor ناموفق بود.', 'polymart-ai' )
			);
		}

		update_post_meta( $post_id, self::get_elementor_meta_key( $lang ), $json );
		\PolymartAI\Activity_Logger::touch_job_worker_heartbeat();

		if ( $complete ) {
			self::save_elementor_source_hash( $post_id, $lang );
			delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
			delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );
			self::clear_elementor_slice_cursor( $post_id, $lang );
			self::flush_translation_status_cache( $post_id );

			return true;
		}

		if ( '' === trim( (string) get_post_meta( $post_id, self::get_elementor_source_hash_meta_key( $lang ), true ) ) ) {
			self::save_elementor_source_hash( $post_id, $lang );
		}

		self::write_elementor_slice_cursor( $post_id, $lang, $done_count, $total_chunks );

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
		$payload       = self::expand_payload_for_ai( $payload );
		$chunks        = array();
		$current       = array();
		$current_chars = 0;

		foreach ( $payload as $key => $value ) {
			$value = (string) $value;
			$size  = self::utf8_strlen( (string) $key ) + self::utf8_strlen( $value );

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
	 * Persian Elementor fields excluded by safety filters (shown in scan diagnostics).
	 *
	 * @param array<string|int, mixed> $node Elementor JSON root.
	 * @return array<int, array{path: string, preview: string}>
	 */
	private static function collect_elementor_filtered_out_payload( array $node ) {
		$filtered = array();
		self::walk_elementor_filtered_out_payload( $node, $filtered, 'root' );

		return $filtered;
	}

	/**
	 * @param array<string|int, mixed>                             $node     Elementor node.
	 * @param array<int, array{path: string, preview: string}>     $filtered Output list.
	 * @param string                                               $path     Current path.
	 * @return void
	 */
	private static function walk_elementor_filtered_out_payload( array $node, array &$filtered, $path ) {
		if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
			self::walk_elementor_filtered_out_settings( $node['settings'], $filtered, $path . '.settings' );
		}

		foreach ( $node as $key => $item ) {
			if ( 'settings' === $key ) {
				continue;
			}

			$child_path = $path . '.' . (string) $key;

			if ( is_string( $key ) && isset( self::$elementor_text_keys[ $key ] ) && is_string( $item ) ) {
				$value = trim( (string) $item );

				if ( '' !== $value && Persian_Detector::contains_persian( $value ) && self::should_skip_elementor_setting_key( $key, $value ) ) {
					$filtered[] = array(
						'path'    => $child_path,
						'preview' => $value,
					);
				}
				continue;
			}

			if ( is_array( $item ) ) {
				self::walk_elementor_filtered_out_payload( $item, $filtered, $child_path );
			}
		}
	}

	/**
	 * @param array<string|int, mixed>                             $settings Settings node.
	 * @param array<int, array{path: string, preview: string}>     $filtered Output list.
	 * @param string                                               $path     Current path.
	 * @return void
	 */
	private static function walk_elementor_filtered_out_settings( array $settings, array &$filtered, $path ) {
		foreach ( $settings as $key => $item ) {
			$child_path = $path . '.' . (string) $key;

			if ( is_string( $item ) ) {
				$value = trim( (string) $item );

				if ( '' !== $value && Persian_Detector::contains_persian( $value ) && self::should_skip_elementor_setting_key( (string) $key, $value ) ) {
					$filtered[] = array(
						'path'    => $child_path,
						'preview' => $value,
					);
				}
				continue;
			}

			if ( is_array( $item ) ) {
				self::walk_elementor_filtered_out_settings( $item, $filtered, $child_path );
			}
		}
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
				if ( ! self::should_collect_elementor_setting_for_translation( (string) $key, $item ) ) {
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
	 * Whether a widget settings key is user-visible copy (not internal Elementor config).
	 *
	 * Elementor stores on-page text in settings.* — this whitelist keeps buttons, titles,
	 * editor HTML, accordion labels, etc. and skips CSS/animation/ID-style keys.
	 *
	 * @param string $key   Setting key.
	 * @param string $value Setting value.
	 * @return bool
	 */
	private static function should_collect_elementor_setting_for_translation( $key, $value ) {
		$key = (string) $key;

		if ( self::should_skip_elementor_setting_key( $key, $value ) ) {
			return false;
		}

		if ( self::is_elementor_user_text_setting_key( $key ) ) {
			return true;
		}

		/**
		 * Include extra visible Elementor setting keys for this site.
		 *
		 * @param bool   $include Default false — only whitelisted user-text keys are translated.
		 * @param string $key     Setting key.
		 * @param string $value   Setting value.
		 */
		return (bool) apply_filters( 'polymart_ai_elementor_include_setting_key', false, $key, (string) $value );
	}

	private static function elementor_gap_fill_remaining_is_long_tail_only( array $source_payload, array $map, array $skipped ) {
		$remaining = self::filter_remaining_elementor_payload( $source_payload, $map, $skipped );

		if ( empty( $remaining ) || count( $remaining ) > 3 ) {
			return false;
		}

		foreach ( $remaining as $path => $text ) {
			if ( ! self::stubborn_elementor_field_is_long( (string) $path, (string) $text ) ) {
				return false;
			}
		}

		return true;
	}

	private static function is_elementor_user_text_setting_key( $key ) {
		return isset( self::$elementor_text_keys[ (string) $key ] );
	}

	/**
	 * Whether a URL-like Elementor setting should be translated (Persian slug/path in media URLs).
	 *
	 * @param string $key   Setting key.
	 * @param string $value Setting value.
	 * @return bool
	 */
	private static function is_elementor_translatable_url_key( $key, $value ) {
		$key = strtolower( (string) $key );

		if ( self::is_elementor_user_text_setting_key( $key ) ) {
			return false;
		}

		if ( ! Persian_Detector::contains_persian( $value ) ) {
			return false;
		}

		if ( preg_match( '/(?:^|_)(?:image|img|photo|banner|gallery|media|thumb)(?:_)?(?:url|link|src|path|file)$/i', $key ) ) {
			return true;
		}

		return in_array( $key, array( 'image_url', 'image_link', 'image_src', 'image_path', 'image_file' ), true );
	}

	/**
	 * Whether an Elementor setting key/value pair should be excluded from AI translation.
	 *
	 * @param string $key   Setting key.
	 * @param string $value Setting value.
	 * @return bool
	 */
	private static function should_skip_elementor_setting_key( $key, $value ) {
		$key          = (string) $key;
		$value        = trim( (string) $value );
		$is_user_text = self::is_elementor_user_text_setting_key( $key );

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
			'href'       => true,
		);

		if ( isset( $blocked_keys[ $key ] ) && ! self::is_elementor_translatable_url_key( $key, $value ) ) {
			return true;
		}

		if (
			preg_match( '/(_url|_link|_id|_css|_class|_icon|_image|_img|_media|_src|_size|_color|_width|_height|_align|typography|animation|custom_css|z_index|motion_fx|background|overlay|mask|_json|editor_data|html_cache)/i', $key )
			&& ! self::is_elementor_translatable_url_key( $key, $value )
			&& ! $is_user_text
		) {
			return true;
		}

		// Embedded JSON/config blobs — not human-readable text.
		$trimmed = ltrim( $value );
		if ( ( '{' === $trimmed || '[' === $trimmed ) && ! $is_user_text ) {
			return true;
		}

		// Skip raw markup/config blobs, but allow rich Elementor widget HTML to pass through.
		if ( ! $is_user_text ) {
			if ( preg_match( '/^\s*<(?:style|script|svg|!DOCTYPE)/i', $value ) ) {
				return true;
			}
		}

		// Long serialized JSON-like strings.
		if ( mb_strlen( $value ) > 500 && preg_match( '/["\']\s*:\s*["\[\{]/', $value ) ) {
			return true;
		}

		// URLs, data-URIs, hex colors, pure numbers/units, SVG/path junk.
		if ( preg_match( '/^(https?:\/\/|\/\/|data:|#|[0-9a-f]{3,8}$|\d+(\.\d+)?(px|em|rem|%|vh|vw)?)$/i', $value ) ) {
			return true;
		}

		if ( preg_match( '/^(data:image\/|data:application\/|blob:)/i', $value ) ) {
			return true;
		}

		// Mostly media/path content without Persian text.
		if ( preg_match( '/\.(jpe?g|png|gif|webp|svg|mp4|webm|pdf)(\?|$)/i', $value ) && ! Persian_Detector::contains_persian( $value ) ) {
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
		if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
			$node['settings'] = self::apply_elementor_settings_payload( $node['settings'], $translated_map, $path . '.settings' );
		}

		foreach ( $node as $key => $item ) {
			if ( 'settings' === $key ) {
				continue;
			}

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
	 * Apply translated values onto Elementor widget settings nodes.
	 *
	 * @param array<string|int, mixed> $settings      Settings node.
	 * @param array<string, string>    $translated_map Path => translated text.
	 * @param string                   $path           Current path.
	 * @return array<string|int, mixed>
	 */
	private static function apply_elementor_settings_payload( array $settings, array $translated_map, $path ) {
		foreach ( $settings as $key => $item ) {
			$child_path = $path . '.' . (string) $key;

			if ( is_string( $item ) && isset( $translated_map[ $child_path ] ) ) {
				$settings[ $key ] = $translated_map[ $child_path ];
				continue;
			}

			if ( is_array( $item ) ) {
				$settings[ $key ] = self::apply_elementor_settings_payload( $item, $translated_map, $child_path );
			}
		}

		return $settings;
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
		if ( ! self::allows_elementor_audit_decode() ) {
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
	 * Whether a stored Elementor companion still contains Persian widget text.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return bool
	 */
	public static function stored_elementor_translation_has_persian( $post_id, $lang ) {
		return '' !== self::collect_stored_elementor_persian_plain_text( $post_id, $lang );
	}

	/**
	 * Extract Persian plain text from a stored Elementor companion JSON document.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return string
	 */
	public static function collect_stored_elementor_persian_plain_text( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$key     = $post_id . ':' . $lang;

		if ( array_key_exists( $key, self::$stored_elementor_persian_cache ) ) {
			return self::$stored_elementor_persian_cache[ $key ];
		}

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			self::$stored_elementor_persian_cache[ $key ] = '';

			return '';
		}

		$stored = self::get_stored_elementor_json( $post_id, $lang );

		if ( ! is_string( $stored ) || '' === trim( $stored ) ) {
			self::$stored_elementor_persian_cache[ $key ] = '';

			return '';
		}

		$data = json_decode( $stored, true );

		if ( ! is_array( $data ) ) {
			self::$stored_elementor_persian_cache[ $key ] = '';

			return '';
		}

		$payload = self::collect_elementor_translation_payload( $data );

		self::$stored_elementor_persian_cache[ $key ] = implode(
			"\n\n",
			array_unique( array_filter( array_values( $payload ) ) )
		);

		return self::$stored_elementor_persian_cache[ $key ];
	}

	/**
	 * Whether the current request may decode Elementor JSON for audits/jobs.
	 *
	 * @return bool
	 */
	private static function allows_elementor_audit_decode() {
		if (
			is_admin()
			|| wp_doing_cron()
			|| wp_doing_ajax()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		) {
			return true;
		}

		return \PolymartAI\Activity_Logger::is_trusted_job_worker();
	}

	/**
	 * Whether an English storefront URL would still render Persian source content.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return bool
	 */
	public static function storefront_would_show_persian_source( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return false;
		}

		if ( ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return false;
		}

		if ( self::uses_elementor_builder( $post_id ) && ! self::is_commerce_product_post( $post_id, $post ) ) {
			if ( self::has_elementor_persian_content( $post_id ) ) {
				if ( ! self::can_serve_stored_elementor_json_on_storefront( $post_id, $lang ) ) {
					return true;
				}

				if ( self::stored_elementor_translation_has_persian( $post_id, $lang ) ) {
					return true;
				}

				return false;
			}
		}

		foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $source_key ) {
			if ( 'post_content' === $source_key && self::uses_elementor_builder( $post_id ) ) {
				continue;
			}

			$source = self::get_field_source_text( $post, $source_key );

			if ( '' === trim( $source ) || ! Persian_Detector::contains_persian( $source ) ) {
				continue;
			}

			if ( ! self::should_serve_stored_translation( $post_id, $source_key, $lang, $post ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Human-readable reason a post still needs translation work.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return string
	 */
	public static function describe_translation_gap( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$notes   = array();

		if ( self::uses_elementor_builder( $post_id ) && self::has_elementor_persian_content( $post_id ) ) {
			if ( ! self::can_serve_stored_elementor_json_on_storefront( $post_id, $lang ) ) {
				$error = trim( (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true ) );

				if ( '' !== $error ) {
					$notes[] = sprintf(
						/* translators: %s: Elementor error message */
						__( 'Elementor: %s', 'polymart-ai' ),
						$error
					);
				} else {
					$notes[] = __( 'ترجمه Elementor روی URL انگلیسی اعمال نمی‌شود', 'polymart-ai' );
				}
			} elseif ( self::stored_elementor_translation_has_persian( $post_id, $lang ) ) {
				$notes[] = __( 'JSON ترجمه Elementor هنوز متن فارسی دارد', 'polymart-ai' );
			}
		}

		$gaps = self::get_translation_gaps( $post_id, $lang );

		foreach ( $gaps['fields'] ?? array() as $field ) {
			if ( empty( $field['translated'] ) && ! empty( $field['label'] ) ) {
				$notes[] = (string) $field['label'];
			}
		}

		if ( empty( $notes ) && ! empty( $gaps['missing'] ) ) {
			$notes = array_map( 'strval', $gaps['missing'] );
		}

		if ( empty( $notes ) && ! empty( $gaps['notes'] ) ) {
			$notes = array_map( 'strval', $gaps['notes'] );
		}

		if ( empty( $notes ) && self::storefront_would_show_persian_source( $post_id, $lang ) ) {
			$notes[] = __( 'فروشگاه هنوز متن فارسی نشان می‌دهد', 'polymart-ai' );
		}

		$front = absint( get_option( 'page_on_front' ) );

		if ( $front === $post_id && empty( array_filter( $notes, static function ( $note ) {
			return false !== stripos( (string) $note, 'Elementor' );
		} ) ) ) {
			$notes[] = __( 'اگر فقط هدر/فوتر فارسی است، آن‌ها در Header Builder وودمارت هستند — تب «رشته‌های UI» را دوباره اسکن کنید.', 'polymart-ai' );
		}

		if ( empty( $notes ) ) {
			$notes[] = __( 'وضعیت ترجمه نامشخص — بررسی دستی لازم است', 'polymart-ai' );
		}

		return implode( '؛ ', array_unique( array_filter( $notes ) ) );
	}

	/**
	 * Whether a post still needs bulk translation work for a language.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return bool
	 */
	public static function post_needs_translation_work( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return false;
		}

		if ( ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return false;
		}

		if ( self::storefront_would_show_persian_source( $post_id, $lang ) ) {
			return true;
		}

		if (
			self::post_needs_elementor_job_work( $post_id, $lang )
			|| self::elementor_job_has_remaining_payload( $post_id, $lang )
		) {
			return true;
		}

		if ( ! self::post_has_persian_content( $post ) ) {
			return false;
		}

		self::flush_translation_status_cache( $post_id );

		if ( 'translated' !== self::get_translation_status( $post_id, $lang ) ) {
			return true;
		}

		return ! empty( self::collect_persian_fields( $post, $lang ) );
	}

	/**
	 * Whether a post should be enqueued when starting/resuming a bulk job.
	 *
	 * Broader than post_needs_translation_work — includes Elementor partials the index may mark done.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return bool
	 */
	public static function post_is_actionable_for_job( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		if ( self::post_needs_translation_work( $post_id, $lang ) ) {
			return true;
		}

		if ( self::elementor_job_has_remaining_payload( $post_id, $lang ) ) {
			return true;
		}

		if ( self::should_run_elementor_job_slice( $post_id, $lang ) ) {
			return true;
		}

		$state = self::get_job_partial_state( $post_id, $lang );

		return 'elementor' === sanitize_key( (string) ( $state['phase'] ?? '' ) )
			&& self::job_partial_state_has_progress( $post_id, $lang, $state );
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
			$message = __( 'شما اجازه ترجمه این مورد را ندارید.', 'polymart-ai' );

			if ( \PolymartAI\Activity_Logger::should_bypass_browser_auth_checks() ) {
				$message = sprintf(
					/* translators: 1: current user ID */
					__( 'Worker پس‌زمینه نتوانست دسترسی ترجمه را تأیید کند (User ID: %1$d).', 'polymart-ai' ),
					get_current_user_id()
				);
			}

			return new \WP_Error(
				'polymart_ai_forbidden',
				$message
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
	 * Unique token for the current PHP worker (per-request).
	 *
	 * @return string
	 */
	private static function get_translation_lock_token() {
		if ( null === self::$translation_lock_token ) {
			self::$translation_lock_token = function_exists( 'wp_generate_uuid4' )
				? wp_generate_uuid4()
				: uniqid( 'pm_lock_', true );
		}

		return self::$translation_lock_token;
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return array{key: string, claim: string, owner: string}
	 */
	private static function get_translation_lock_keys( $post_id, $lang ) {
		$key = self::TRANSLATION_LOCK_PREFIX . absint( $post_id ) . '_' . sanitize_key( (string) $lang );

		return array(
			'key'   => $key,
			'claim' => $key . '_claim',
			'owner' => $key . self::TRANSLATION_LOCK_OWNER_SUFFIX,
		);
	}

	/**
	 * Whether this worker currently owns the per-post translation lock.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	public static function owns_translation_lock( $post_id, $lang ) {
		$keys = self::get_translation_lock_keys( $post_id, $lang );

		return (string) get_option( $keys['owner'], '' ) === self::get_translation_lock_token();
	}

	/**
	 * Merge a local Elementor path map with translations already stored in post meta.
	 *
	 * @param int                   $post_id     Post ID.
	 * @param string                $lang        Language code.
	 * @param array<string, mixed>  $source_data Source Elementor tree.
	 * @param array<string, string> $map         Local path map for this slice.
	 * @return array<string, string>
	 */
	private static function merge_elementor_path_map( $post_id, $lang, array $source_data, array $map ) {
		$rebuilt = self::rebuild_elementor_map_from_saved_translation( $post_id, $lang, $source_data );

		return array_merge( $rebuilt, $map );
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

		$keys  = self::get_translation_lock_keys( $post_id, $lang );
		$token = self::get_translation_lock_token();
		$now   = time();

		$existing_owner = (string) get_option( $keys['owner'], '' );
		$existing_claim = absint( get_option( $keys['claim'], 0 ) );
		$existing_lock  = get_transient( $keys['key'] );
		$stamp          = max( $existing_claim, $existing_lock ? absint( $existing_lock ) : 0 );

		if ( $stamp > 0 && ( $now - $stamp ) < self::TRANSLATION_LOCK_TTL ) {
			if ( $existing_owner === $token ) {
				update_option( $keys['claim'], (string) $now, false );
				set_transient( $keys['key'], $now, self::TRANSLATION_LOCK_TTL );

				return true;
			}

			if ( ! $existing_lock && $existing_claim > 0 ) {
				set_transient( $keys['key'], $existing_claim, self::TRANSLATION_LOCK_TTL );
			}

			return false;
		}

		if ( $stamp > 0 ) {
			delete_option( $keys['claim'] );
			delete_option( $keys['owner'] );
			delete_transient( $keys['key'] );
		}

		if ( ! add_option( $keys['claim'], (string) $now, '', 'no' ) ) {
			$existing_claim = absint( get_option( $keys['claim'], 0 ) );

			if ( $existing_claim > 0 && ( $now - $existing_claim ) < self::TRANSLATION_LOCK_TTL ) {
				return false;
			}

			update_option( $keys['claim'], (string) $now, false );
		}

		update_option( $keys['owner'], $token, false );
		set_transient( $keys['key'], $now, self::TRANSLATION_LOCK_TTL );

		return true;
	}

	/**
	 * Release a per-post translation lock.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @param bool   $force   When true, clear the lock regardless of owner.
	 * @return void
	 */
	public static function release_translation_lock( $post_id, $lang, $force = false ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return;
		}

		$keys           = self::get_translation_lock_keys( $post_id, $lang );
		$existing_owner = (string) get_option( $keys['owner'], '' );

		if ( ! $force && '' !== $existing_owner && $existing_owner !== self::get_translation_lock_token() ) {
			return;
		}

		delete_transient( $keys['key'] );
		delete_option( $keys['claim'] );
		delete_option( $keys['owner'] );
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
			} elseif ( self::stored_elementor_translation_has_persian( $post_id, $lang ) ) {
				$status = 'partial';
			} elseif ( self::storefront_would_show_persian_source( $post_id, $lang ) ) {
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
					'label'      => __( 'بخش‌های Elementor', 'polymart-ai' ),
					'meta_key'   => self::get_elementor_meta_key( $lang ),
					'has_source' => true,
					'translated' => self::has_stored_elementor_translation( $post_id, $lang )
						&& ! self::stored_elementor_translation_has_persian( $post_id, $lang )
						&& ! self::elementor_job_has_remaining_payload( $post_id, $lang ),
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

			foreach ( array_keys( self::$stored_elementor_persian_cache ) as $cache_key ) {
				if ( 0 === strpos( $cache_key, $post_id . ':' ) ) {
					unset( self::$stored_elementor_persian_cache[ $cache_key ] );
				}
			}

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
