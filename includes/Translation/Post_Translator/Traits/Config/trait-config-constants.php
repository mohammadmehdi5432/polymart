<?php
/**
 * Post_Translator Config Constants (auto-split).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\Config;


defined( 'ABSPATH' ) || exit;

trait Trait_Config_Constants {

	/**
	 * Nonce action for saving meta box fields.
	 */
	const META_BOX_NONCE_ACTION = 'polymart_ai_save_translations';
	/**
	 * Nonce field name for the meta box form.
	 */
	const META_BOX_NONCE_NAME = 'polymart_ai_meta_box_nonce';
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
	 * Post meta flag: this post has Persian source content worth translating.
	 */
	const PERSIAN_CONTENT_FLAG_META = '_polymart_ai_has_persian';
	/**
	 * Prefix for per-language translation status index meta.
	 */
	const STATUS_INDEX_META_PREFIX = '_polymart_ai_status_';
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
	 * Helps avoid gateway timeouts on long HTML-heavy content.
	 */
	const ELEMENTOR_LONG_FIELD_SEGMENT_CHARS = 1000;
	/**
	 * Max API attempts per Elementor __segN key before source-text fallback.
	 */
	const ELEMENTOR_SEGMENT_MAX_RETRIES = 3;
	/**
	 * Empty stubborn hand-off ticks before force-saving with source-text fallback.
	 */
	const ELEMENTOR_STUBBORN_GHOST_LOOP_LIMIT = 5;
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
	 * Post types that support a translated featured image / banner.
	 * @var string[]
	 */
	const FEATURED_IMAGE_POST_TYPES = array(
		'product',
		'woodmart_slide',
	);
	/**
	 * Taxonomies whose public labels should be translated alongside content.
	 * @var string[]
	 */
	const SUPPORTED_TAXONOMIES = array(
		'product_cat',
		'product_tag',
		'category',
		'post_tag',
	);
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
	 * Post meta prefix for in-progress auto-translate job slices (per post + language).
	 */
	const JOB_PARTIAL_META_PREFIX = '_polymart_ai_job_partial_';
}
