<?php
/**
 * Post translation facade — implementation lives in Post_Translator/Traits/.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation;

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

require_once __DIR__ . '/Post_Translator/trait-loader.php';

defined( 'ABSPATH' ) || exit;

/**
 * Class Post_Translator
 */
final class Post_Translator {

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
