<?php
/**
 * Text normalization helpers for post translation storage and comparison.
 *
 * @package PolymartAI\Translation\Post_Translator
 */

namespace PolymartAI\Translation\Post_Translator;

use PolymartAI\Translation\AI\Persian_Detector;

defined( 'ABSPATH' ) || exit;

/**
 * Class Text_Normalizer
 */
final class Text_Normalizer {

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
	 * Whether a stored translation contains non-empty text.
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
	public static function normalize_ai_translation_value( $value ) {
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
	public static function prepare_stored_meta_value( $value, $field_kind ) {
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
	public static function normalize_translation_plaintext( $value ) {
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
	public static function is_clean_target_language_translation( $value, $lang ) {
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
	 * Multibyte-safe string length for Elementor / Persian text payloads.
	 *
	 * @param mixed $text Text value.
	 * @return int
	 */
	public static function utf8_strlen( $text ) {
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
	public static function utf8_substr( $text, $start, $length ) {
		$text = (string) $text;

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, $start, $length, 'UTF-8' );
		}

		return substr( $text, $start, $length );
	}
}
