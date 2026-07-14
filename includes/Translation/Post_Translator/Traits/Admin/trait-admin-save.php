<?php
/**
 * Post_Translator Admin_Save (auto-split).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\Admin;

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\AI\AI_Client;
use PolymartAI\Translation\AI\Persian_Detector;
use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Post_Translator\Persistence_Guard;
use PolymartAI\Translation\Post_Translator\Text_Normalizer;
use PolymartAI\Translation\Post_Translator\Translation_Lock;


defined( 'ABSPATH' ) || exit;

trait Trait_Admin_Save {

	public function __construct() {
		add_action( 'pre_post_update', array( $this, 'maybe_begin_admin_translation_persist' ), 1, 1 );
		add_action( 'save_post', array( $this, 'save_manual_translations' ), 99, 3 );
		add_action( 'save_post', array( $this, 'sync_translation_index_on_save' ), 99, 1 );
		add_filter( 'update_post_metadata', array( $this, 'capture_elementor_data_prev_value' ), 10, 5 );
		add_action( 'updated_post_meta', array( $this, 'maybe_invalidate_elementor_on_source_change' ), 10, 4 );
		add_action( 'shutdown', array( __CLASS__, 'end_persisting_translations' ), 999 );
	}

	public function maybe_begin_admin_translation_persist( $post_id ) {
		unset( $post_id );

		if ( self::is_admin_translation_save_request() ) {
			self::begin_persisting_translations();
		}
	}

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

	private function save_thumbnail_field( $post_id, $lang = 'en' ) {
		$meta_key = self::get_thumbnail_meta_key( $lang );

		if ( ! isset( $_POST[ $meta_key ] ) ) {
			return;
		}

		$this->save_meta_field( $post_id, $meta_key, 'attachment_id' );
	}

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
