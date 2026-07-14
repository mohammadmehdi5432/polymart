<?php
/**
 * Post translation facade — implementation lives in Post_Translator/Traits/.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation;

use PolymartAI\Translation\Post_Translator\Config_Constants as Post_Translator_Config;
use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Post_Translator\Persistence_Guard;
use PolymartAI\Translation\Post_Translator\Text_Normalizer;
use PolymartAI\Translation\Post_Translator\Translation_Lock;
use PolymartAI\Translation\Post_Translator\Traits\Admin\Trait_Admin_Save;
use PolymartAI\Translation\Post_Translator\Traits\AiPersistence\Trait_Ai_Persistence;
use PolymartAI\Translation\Post_Translator\Traits\Config\Trait_Config;
use PolymartAI\Translation\Post_Translator\Traits\Core\Trait_Core;
use PolymartAI\Translation\Post_Translator\Traits\Elementor\Trait_Elementor;
use PolymartAI\Translation\Post_Translator\Traits\Invalidation\Trait_Invalidation;
use PolymartAI\Translation\Post_Translator\Traits\JobSlice\Trait_Job_Slice;
use PolymartAI\Translation\Post_Translator\Traits\MetaDiscovery\Trait_Meta_Discovery;
use PolymartAI\Translation\Post_Translator\Traits\Storefront\Trait_Storefront;
use PolymartAI\Translation\Post_Translator\Traits\Term\Trait_Term;
use PolymartAI\Translation\Post_Translator\Traits\TranslationStatus\Trait_Translation_Status;
use PolymartAI\Translation\Post_Translator\Traits\Variation\Trait_Variation;

require_once __DIR__ . '/Post_Translator/class-config-constants.php';
require_once __DIR__ . '/Post_Translator/trait-loader.php';

defined( 'ABSPATH' ) || exit;

/**
 * Class Post_Translator
 */
final class Post_Translator {

	const META_BOX_NONCE_ACTION = Post_Translator_Config::META_BOX_NONCE_ACTION;
	const META_BOX_NONCE_NAME     = Post_Translator_Config::META_BOX_NONCE_NAME;
	const TRANSLATION_LOCK_PREFIX       = Post_Translator_Config::TRANSLATION_LOCK_PREFIX;
	const TRANSLATION_LOCK_TTL          = Post_Translator_Config::TRANSLATION_LOCK_TTL;
	const TRANSLATION_LOCK_OWNER_SUFFIX = Post_Translator_Config::TRANSLATION_LOCK_OWNER_SUFFIX;
	const PERSIAN_CONTENT_FLAG_META = Post_Translator_Config::PERSIAN_CONTENT_FLAG_META;
	const STATUS_INDEX_META_PREFIX  = Post_Translator_Config::STATUS_INDEX_META_PREFIX;
	const MAX_STOREFRONT_ELEMENTOR_JSON_BYTES = Post_Translator_Config::MAX_STOREFRONT_ELEMENTOR_JSON_BYTES;
	const META_KEY_TITLE_EN   = Post_Translator_Config::META_KEY_TITLE_EN;
	const META_KEY_CONTENT_EN = Post_Translator_Config::META_KEY_CONTENT_EN;
	const META_KEY_EXCERPT_EN = Post_Translator_Config::META_KEY_EXCERPT_EN;
	const DEFAULT_AI_MODEL = Post_Translator_Config::DEFAULT_AI_MODEL;
	const CUSTOM_META_KEYS = Post_Translator_Config::CUSTOM_META_KEYS;
	const WVE_VARIATION_CUSTOM_TITLE_META       = Post_Translator_Config::WVE_VARIATION_CUSTOM_TITLE_META;
	const WVE_VARIATION_CUSTOM_DESCRIPTION_META = Post_Translator_Config::WVE_VARIATION_CUSTOM_DESCRIPTION_META;
	const SUPPORTED_POST_TYPES = Post_Translator_Config::SUPPORTED_POST_TYPES;
	const AI_FIELD_CHUNK_SIZE = Post_Translator_Config::AI_FIELD_CHUNK_SIZE;
	const JOB_CORE_FIELD_CHUNK_SIZE = Post_Translator_Config::JOB_CORE_FIELD_CHUNK_SIZE;
	const AI_MAX_CHUNK_CHARS = Post_Translator_Config::AI_MAX_CHUNK_CHARS;
	const AI_MAX_SINGLE_FIELD_CHARS = Post_Translator_Config::AI_MAX_SINGLE_FIELD_CHARS;
	const ELEMENTOR_AI_FIELD_CHUNK_SIZE = Post_Translator_Config::ELEMENTOR_AI_FIELD_CHUNK_SIZE;
	const ELEMENTOR_AI_MAX_CHUNK_CHARS = Post_Translator_Config::ELEMENTOR_AI_MAX_CHUNK_CHARS;
	const ELEMENTOR_JOB_FIELD_CHUNK_SIZE = Post_Translator_Config::ELEMENTOR_JOB_FIELD_CHUNK_SIZE;
	const ELEMENTOR_BULK_JOB_FIELD_CHUNK_SIZE = Post_Translator_Config::ELEMENTOR_BULK_JOB_FIELD_CHUNK_SIZE;
	const ELEMENTOR_JOB_MAX_CHUNK_CHARS = Post_Translator_Config::ELEMENTOR_JOB_MAX_CHUNK_CHARS;
	const ELEMENTOR_LONG_FIELD_SEGMENT_CHARS = Post_Translator_Config::ELEMENTOR_LONG_FIELD_SEGMENT_CHARS;
	const ELEMENTOR_BULK_LONG_FIELD_SEGMENT_CHARS = Post_Translator_Config::ELEMENTOR_BULK_LONG_FIELD_SEGMENT_CHARS;
	const ELEMENTOR_SEGMENT_MAX_RETRIES = Post_Translator_Config::ELEMENTOR_SEGMENT_MAX_RETRIES;
	const ELEMENTOR_STUBBORN_GHOST_LOOP_LIMIT = Post_Translator_Config::ELEMENTOR_STUBBORN_GHOST_LOOP_LIMIT;
	const ELEMENTOR_JOB_REQUEST_TIMEOUT = Post_Translator_Config::ELEMENTOR_JOB_REQUEST_TIMEOUT;
	const ELEMENTOR_JOB_REQUEST_TIMEOUT_MAX = Post_Translator_Config::ELEMENTOR_JOB_REQUEST_TIMEOUT_MAX;
	const JOB_REQUEST_MIN_TIMEOUT = Post_Translator_Config::JOB_REQUEST_MIN_TIMEOUT;
	const JOB_REQUEST_MAX_TIMEOUT = Post_Translator_Config::JOB_REQUEST_MAX_TIMEOUT;
	const VARIATION_AI_FIELD_CHUNK_SIZE = Post_Translator_Config::VARIATION_AI_FIELD_CHUNK_SIZE;
	const VARIATION_AI_MAX_CHUNK_CHARS = Post_Translator_Config::VARIATION_AI_MAX_CHUNK_CHARS;
	const COMMERCE_AI_FIELD_CHUNK_SIZE = Post_Translator_Config::COMMERCE_AI_FIELD_CHUNK_SIZE;
	const COMMERCE_AI_MAX_CHUNK_CHARS = Post_Translator_Config::COMMERCE_AI_MAX_CHUNK_CHARS;
	const MAX_META_VALUE_LENGTH = Post_Translator_Config::MAX_META_VALUE_LENGTH;
	const FEATURED_IMAGE_POST_TYPES = Post_Translator_Config::FEATURED_IMAGE_POST_TYPES;
	const SUPPORTED_TAXONOMIES = Post_Translator_Config::SUPPORTED_TAXONOMIES;
	const STOREFRONT_TITLE_MAX_LENGTH      = Post_Translator_Config::STOREFRONT_TITLE_MAX_LENGTH;
	const DERIVED_TITLE_MAX_LENGTH         = Post_Translator_Config::DERIVED_TITLE_MAX_LENGTH;
	const STOREFRONT_TITLE_HARD_MAX_LENGTH = Post_Translator_Config::STOREFRONT_TITLE_HARD_MAX_LENGTH;
	const JOB_PARTIAL_META_PREFIX = Post_Translator_Config::JOB_PARTIAL_META_PREFIX;

	use Trait_Admin_Save;
	use Trait_Ai_Persistence;
	use Trait_Config;
	use Trait_Core;
	use Trait_Elementor;
	use Trait_Invalidation;
	use Trait_Job_Slice;
	use Trait_Meta_Discovery;
	use Trait_Storefront;
	use Trait_Term;
	use Trait_Translation_Status;
	use Trait_Variation;


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

	public static function is_persisting_translations() {
		return Persistence_Guard::is_persisting_translations();
	}

	public static function is_admin_translation_save_request() {
		return Persistence_Guard::is_admin_translation_save_request();
	}

	public static function should_skip_translation_invalidation() {
		return Persistence_Guard::should_skip_translation_invalidation();
	}

	public static function begin_persisting_translations() {
		Persistence_Guard::begin_persisting_translations();
	}

	public static function end_persisting_translations() {
		Persistence_Guard::end_persisting_translations();
	}

	public static function field_source_text_meaningfully_changed( $before, $after ) {
		return Text_Normalizer::field_source_text_meaningfully_changed( $before, $after );
	}

	public static function get_supported_post_types() {
		return Meta_Keys::get_supported_post_types();
	}

	public static function get_skipped_meta_prefixes() {
		return Meta_Keys::get_skipped_meta_prefixes();
	}

	public static function is_translatable_meta_key( $meta_key, $lang = '' ) {
		return Meta_Keys::is_translatable_meta_key( $meta_key, $lang );
	}

	public static function is_custom_meta_key( $meta_key ) {
		return Meta_Keys::is_custom_meta_key( $meta_key );
	}

	public static function get_translatable_taxonomies() {
		return Meta_Keys::get_translatable_taxonomies();
	}

	public static function get_meta_key( $field, $lang ) {
		return Meta_Keys::get_meta_key( $field, $lang );
	}

	public static function get_form_field_name( $meta_key ) {
		return Meta_Keys::get_form_field_name( $meta_key );
	}

	public static function map_meta_keys_to_form_fields( array $fields ) {
		return Meta_Keys::map_meta_keys_to_form_fields( $fields );
	}

	public static function get_custom_meta_key( $source_key, $lang ) {
		return Meta_Keys::get_custom_meta_key( $source_key, $lang );
	}

	public static function get_thumbnail_meta_key( $lang ) {
		return Meta_Keys::get_thumbnail_meta_key( $lang );
	}

	public static function get_field_source_hash_meta_key( $source_key, $lang ) {
		return Meta_Keys::get_field_source_hash_meta_key( $source_key, $lang );
	}

	public static function get_term_source_hash_meta_key( $field, $lang ) {
		return Meta_Keys::get_term_source_hash_meta_key( $field, $lang );
	}

	public static function has_meaningful_translation( $value ) {
		return Text_Normalizer::has_meaningful_translation( $value );
	}

	public static function is_usable_storefront_translation( $value, $lang ) {
		return Text_Normalizer::is_usable_storefront_translation( $value, $lang );
	}

	public static function get_term_meta_key( $field, $lang ) {
		return Meta_Keys::get_term_meta_key( $field, $lang );
	}

	public static function get_menu_title_meta_key( $lang ) {
		return Meta_Keys::get_menu_title_meta_key( $lang );
	}

	public static function owns_translation_lock( $post_id, $lang ) {
		return Translation_Lock::owns_translation_lock( $post_id, $lang );
	}

	public static function acquire_translation_lock( $post_id, $lang ) {
		return Translation_Lock::acquire_translation_lock( $post_id, $lang );
	}

	public static function release_translation_lock( $post_id, $lang, $force = false ) {
		Translation_Lock::release_translation_lock( $post_id, $lang, $force );
	}

}
