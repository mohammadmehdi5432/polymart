<?php
/**
 * Storefront translation resolution for posts and products.
 *
 * @package PolymartAI\Translation\Post_Translator
 */

namespace PolymartAI\Translation\Post_Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Storefront_Resolver
 */
final class Storefront_Resolver {

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
	 * @param int    $post_id                Post ID.
	 * @param string $lang                   Target language code.
	 * @param bool   $allow_content_fallback Whether missing title meta may derive from content.
	 * @return string
	 */
	public static function resolve_storefront_title( $post_id, $lang, $allow_content_fallback = false ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return '';
		}

		$meta_key = Meta_Keys::get_meta_key( 'title', $lang );
		$stored   = get_post_meta( $post_id, $meta_key, true );

		if ( is_string( $stored ) && Text_Normalizer::has_meaningful_translation( $stored ) ) {
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
	 * Resolve a stored translation for any mapped storefront source field.
	 *
	 * @param int           $post_id    Post ID.
	 * @param string        $source_key Source field identifier.
	 * @param string        $lang       Target language code.
	 * @param \WP_Post|null $post       Optional post object.
	 * @return string Translated value, or empty string when unavailable.
	 */
	public static function resolve_storefront_field( $post_id, $source_key, $lang, $post = null ) {
		unset( $post );

		$post_id    = absint( $post_id );
		$source_key = (string) $source_key;
		$lang       = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $source_key || '' === $lang ) {
			return '';
		}

		$meta_key = Meta_Keys::resolve_translated_meta_key_for_source( $source_key, $lang );

		if ( '' === $meta_key ) {
			return '';
		}

		$stored = get_post_meta( $post_id, $meta_key, true );

		if ( 'post_title' === $source_key ) {
			return self::resolve_storefront_title( $post_id, $lang, true );
		}

		if ( is_string( $stored ) && Text_Normalizer::has_meaningful_translation( $stored ) && Text_Normalizer::is_clean_target_language_translation( $stored, $lang ) ) {
			return $stored;
		}

		if (
			in_array( $source_key, array( Meta_Keys::WVE_VARIATION_CUSTOM_TITLE_META, Meta_Keys::WVE_VARIATION_CUSTOM_DESCRIPTION_META ), true )
			&& Meta_Keys::is_custom_meta_key( $source_key )
		) {
			$legacy = get_post_meta( $post_id, Meta_Keys::get_custom_meta_key( $source_key, $lang ), true );

			if ( is_string( $legacy ) && Text_Normalizer::is_clean_target_language_translation( $legacy, $lang ) ) {
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

		$title_meta = get_post_meta( $post_id, Meta_Keys::get_meta_key( 'title', $lang ), true );

		if ( Text_Normalizer::has_meaningful_translation( $title_meta ) && Text_Normalizer::is_clean_target_language_translation( $title_meta, $lang ) ) {
			return false;
		}

		return '' !== trim( self::derive_storefront_title_fallback( $post_id, $lang ) );
	}

	/**
	 * Normalize and cap a stored title translation for storefront display.
	 *
	 * @param mixed $value      Raw title value.
	 * @param int   $max_length Soft display cap (longer values are trimmed, not dropped).
	 * @return string
	 */
	private static function format_storefront_title( $value, $max_length = 120 ) {
		$plain = Text_Normalizer::normalize_translation_plaintext( $value );

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

		$source_title  = trim( (string) $post->post_title );
		$source_len    = mb_strlen( $source_title );
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
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return string
	 */
	private static function derive_storefront_title_fallback_from_content( $post_id, $lang ) {
		$content = get_post_meta( absint( $post_id ), Meta_Keys::get_meta_key( 'content', $lang ), true );

		if ( ! is_string( $content ) || ! Text_Normalizer::has_meaningful_translation( $content ) ) {
			return '';
		}

		if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $content, $matches ) ) {
			$heading = trim( wp_strip_all_tags( html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

			if ( Text_Normalizer::has_meaningful_translation( $heading ) && mb_strlen( $heading ) <= self::DERIVED_TITLE_MAX_LENGTH ) {
				return $heading;
			}
		}

		$plain = trim( wp_strip_all_tags( html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

		if ( ! Text_Normalizer::has_meaningful_translation( $plain ) ) {
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
	 * Whether a post type supports a translated featured image / banner.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function supports_featured_image_translation( $post_type ) {
		return in_array( $post_type, Meta_Keys::FEATURED_IMAGE_POST_TYPES, true );
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
		return Meta_Keys::get_storefront_companion_meta_key( $post_id, $source_key, $lang );
	}

	/**
	 * Resolve the translated featured image attachment ID for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return int Attachment ID, or 0 when unavailable.
	 */
	public static function get_translated_thumbnail_id( $post_id, $lang ) {
		$attachment_id = (int) get_post_meta(
			absint( $post_id ),
			Meta_Keys::get_thumbnail_meta_key( $lang ),
			true
		);

		if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
			return $attachment_id;
		}

		return 0;
	}

	/**
	 * Ensure a translated thumbnail exists, falling back to the source featured image.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return int Attachment ID, or 0 when unavailable.
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

		update_post_meta( $post_id, Meta_Keys::get_thumbnail_meta_key( $lang ), $source_id );

		return $source_id;
	}
}
