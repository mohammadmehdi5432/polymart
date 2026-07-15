<?php
/**
 * Post_Translator configuration constants (PHP 8.1-safe; not in a trait).
 *
 * @package PolymartAI\Translation\Post_Translator
 */

namespace PolymartAI\Translation\Post_Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Config_Constants
 */
final class Config_Constants {

	const META_BOX_NONCE_ACTION = Persistence_Guard::META_BOX_NONCE_ACTION;
	const META_BOX_NONCE_NAME     = Persistence_Guard::META_BOX_NONCE_NAME;

	const TRANSLATION_LOCK_PREFIX       = Translation_Lock::TRANSLATION_LOCK_PREFIX;
	const TRANSLATION_LOCK_TTL          = Translation_Lock::TRANSLATION_LOCK_TTL;
	const TRANSLATION_LOCK_OWNER_SUFFIX = Translation_Lock::TRANSLATION_LOCK_OWNER_SUFFIX;

	const PERSIAN_CONTENT_FLAG_META = Meta_Keys::PERSIAN_CONTENT_FLAG_META;
	const STATUS_INDEX_META_PREFIX  = Meta_Keys::STATUS_INDEX_META_PREFIX;

	/** Maximum `_elementor_data_{lang}` payload served on the storefront (bytes). */
	const MAX_STOREFRONT_ELEMENTOR_JSON_BYTES = 2097152;

	const META_KEY_TITLE_EN   = Meta_Keys::META_KEY_TITLE_EN;
	const META_KEY_CONTENT_EN = Meta_Keys::META_KEY_CONTENT_EN;
	const META_KEY_EXCERPT_EN = Meta_Keys::META_KEY_EXCERPT_EN;

	/** Default ArvanCloud AI model when none is configured. */
	const DEFAULT_AI_MODEL = 'DeepSeek-V3-2-g6zde';

	/** @var string[] */
	const CUSTOM_META_KEYS = Meta_Keys::CUSTOM_META_KEYS;

	const WVE_VARIATION_CUSTOM_TITLE_META       = Meta_Keys::WVE_VARIATION_CUSTOM_TITLE_META;
	const WVE_VARIATION_CUSTOM_DESCRIPTION_META = Meta_Keys::WVE_VARIATION_CUSTOM_DESCRIPTION_META;

	/** @var string[] */
	const SUPPORTED_POST_TYPES = Meta_Keys::SUPPORTED_POST_TYPES;

	/** Maximum string fields sent to AI per request (large posts are chunked). */
	const AI_FIELD_CHUNK_SIZE = 6;
	/** Core field batches per auto-translate job slice (smaller than manual translate). */
	const JOB_CORE_FIELD_CHUNK_SIZE = 3;
	/** Maximum combined characters per AI request batch. */
	const AI_MAX_CHUNK_CHARS = 3500;
	/** Maximum characters for a single field before it is split across requests. */
	const AI_MAX_SINGLE_FIELD_CHARS = 2800;
	/** Smaller Elementor JSON batches — paths are long and widgets nest deeply. */
	const ELEMENTOR_AI_FIELD_CHUNK_SIZE = 8;
	/** Maximum combined characters per Elementor AI batch. */
	const ELEMENTOR_AI_MAX_CHUNK_CHARS = 4500;
	/** Elementor job batches for manual metabox / inline translate. */
	const ELEMENTOR_JOB_FIELD_CHUNK_SIZE = 16;
	/** Elementor bulk job batches — group short fields; still capped by ELEMENTOR_JOB_MAX_CHUNK_CHARS. */
	const ELEMENTOR_BULK_JOB_FIELD_CHUNK_SIZE = 6;
	/** Maximum characters per Elementor job-step API call. */
	const ELEMENTOR_JOB_MAX_CHUNK_CHARS = 4500;
	/** Split very long Elementor text fields before calling the AI. */
	const ELEMENTOR_LONG_FIELD_SEGMENT_CHARS = 1000;
	/** Smaller segments during bulk jobs — faster Arvan responses, fewer 30s timeouts. */
	const ELEMENTOR_BULK_LONG_FIELD_SEGMENT_CHARS = 400;
	/** Max API attempts per Elementor __segN key before source-text fallback. */
	const ELEMENTOR_SEGMENT_MAX_RETRIES = 5;
	/**
	 * Fast-fail for a stuck primary/gap chunk: after this many hard failures
	 * (timeout / rate-limit / filter reject) fallback + advance the cursor.
	 */
	const ELEMENTOR_CHUNK_FAST_FAIL_RETRIES = 2;
	/** Empty stubborn hand-off ticks before force-saving with source-text fallback. */
	const ELEMENTOR_STUBBORN_GHOST_LOOP_LIMIT = 3;
	/**
	 * How many force-finalize deferrals a long HTML/__segN field may survive
	 * before it is accepted with partial segments / source fallback and the job closes.
	 */
	const ELEMENTOR_LONG_GAP_FORCE_ATTEMPT_LIMIT = 3;
	/**
	 * When a single long HTML widget expands to more segments than this,
	 * API packs are capped at ELEMENTOR_HUGE_FIELD_SEGMENT_BATCH_SIZE.
	 */
	const ELEMENTOR_HUGE_FIELD_SEGMENT_THRESHOLD = 10;
	/** Max __segN pieces from one widget sent in a single AI request. */
	const ELEMENTOR_HUGE_FIELD_SEGMENT_BATCH_SIZE = 8;
	/** Base HTTP timeout (seconds) for a short Elementor job-step AI call. */
	const ELEMENTOR_JOB_REQUEST_TIMEOUT = 45;
	/** Upper HTTP timeout (seconds) for heavy HTML / __segN Elementor slices. */
	const ELEMENTOR_JOB_REQUEST_TIMEOUT_MAX = 90;
	/** Minimum HTTP timeout (seconds) for auto-translate job AI calls. */
	const JOB_REQUEST_MIN_TIMEOUT = 45;
	/** Maximum HTTP timeout (seconds) for a single auto-translate job AI call. */
	const JOB_REQUEST_MAX_TIMEOUT = 90;
	/** Variation title batches — variable products may have 100+ rows. */
	const VARIATION_AI_FIELD_CHUNK_SIZE = 2;
	/** Maximum combined characters per variation AI batch. */
	const VARIATION_AI_MAX_CHUNK_CHARS = 1800;
	/** Commerce attribute/term batches. */
	const COMMERCE_AI_FIELD_CHUNK_SIZE = 2;
	/** Maximum combined characters per commerce AI batch. */
	const COMMERCE_AI_MAX_CHUNK_CHARS = 2800;
	/** Maximum characters per discovered meta value sent to AI. */
	const MAX_META_VALUE_LENGTH = 12000;

	/** @var string[] */
	const FEATURED_IMAGE_POST_TYPES = Meta_Keys::FEATURED_IMAGE_POST_TYPES;

	/** @var string[] */
	const SUPPORTED_TAXONOMIES = Meta_Keys::SUPPORTED_TAXONOMIES;

	const STOREFRONT_TITLE_MAX_LENGTH      = Storefront_Resolver::STOREFRONT_TITLE_MAX_LENGTH;
	const DERIVED_TITLE_MAX_LENGTH         = Storefront_Resolver::DERIVED_TITLE_MAX_LENGTH;
	const STOREFRONT_TITLE_HARD_MAX_LENGTH = Storefront_Resolver::STOREFRONT_TITLE_HARD_MAX_LENGTH;

	/** Post meta prefix for in-progress auto-translate job slices (per post + language). */
	const JOB_PARTIAL_META_PREFIX = '_polymart_ai_job_partial_';
}
