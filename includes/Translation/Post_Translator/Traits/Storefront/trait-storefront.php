<?php
/**
 * Post_Translator Storefront (auto-split).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\Storefront;

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\AI_Client;
use PolymartAI\Translation\Persian_Detector;
use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Post_Translator\Persistence_Guard;
use PolymartAI\Translation\Post_Translator\Storefront_Resolver;
use PolymartAI\Translation\Post_Translator\Text_Normalizer;
use PolymartAI\Translation\Post_Translator\Translation_Lock;

defined( 'ABSPATH' ) || exit;

trait Trait_Storefront {

	public static function supports_featured_image_translation( $post_type ) {
		return in_array( $post_type, self::FEATURED_IMAGE_POST_TYPES, true );
	}

	public static function resolve_storefront_core_field( $post_id, $source_key, $lang, $post = null ) {
		if ( 'post_title' === (string) $source_key ) {
			return self::resolve_storefront_title( $post_id, $lang, true );
		}

		return self::resolve_storefront_field( $post_id, $source_key, $lang, $post );
	}

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

	private static function derive_storefront_title_fallback( $post_id, $lang ) {
		$candidate = self::derive_storefront_title_fallback_from_content( $post_id, $lang );

		if ( '' === trim( $candidate ) || self::is_unsuitable_derived_product_title( $candidate, $post_id ) ) {
			return '';
		}

		return $candidate;
	}

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

	public static function get_storefront_companion_meta_key( $post_id, $source_key, $lang ) {
		unset( $post_id );

		$source_key = (string) $source_key;
		$lang       = sanitize_key( (string) $lang );

		if ( '' === $source_key || '' === $lang ) {
			return '';
		}

		return self::resolve_translated_meta_key_for_source( $source_key, $lang );
	}

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

}
