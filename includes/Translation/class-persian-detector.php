<?php
/**
 * Detect Persian (Farsi) text vs Latin/English content.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Class Persian_Detector
 */
final class Persian_Detector {

	/**
	 * Unicode ranges covering Arabic-script Persian text.
	 */
	const PERSIAN_PATTERN = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u';

	/**
	 * Check whether a string contains Arabic-script characters (Persian or Arabic).
	 *
	 * @param mixed $text Text to inspect.
	 * @return bool
	 */
	public static function contains_persian( $text ) {
		return self::contains_arabic_script( $text );
	}

	/**
	 * Whether text uses the shared Arabic Unicode block (fa, ar, ur, etc.).
	 *
	 * @param mixed $text Text to inspect.
	 * @return bool
	 */
	public static function contains_arabic_script( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return false;
		}

		// Match on the raw string — avoid wp_strip_all_tags() which copies large
		// Elementor/HTML payloads and can exhaust memory on translated pages.
		return (bool) preg_match( self::PERSIAN_PATTERN, $text );
	}

	/**
	 * Letters that are specific to Persian and do not appear in standard Arabic.
	 *
	 * @param mixed $text Text to inspect.
	 * @return bool
	 */
	public static function contains_persian_specific_characters( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return false;
		}

		return (bool) preg_match( '/[\x{067E}\x{0686}\x{0698}\x{06AF}]/u', $text );
	}

	/**
	 * Whether a stored translation looks usable for a target storefront language.
	 *
	 * @param mixed  $text Stored translation.
	 * @param string $lang Target language code.
	 * @return bool
	 */
	public static function is_acceptable_translation_for_language( $text, $lang ) {
		if ( ! is_string( $text ) && ! is_numeric( $text ) ) {
			return false;
		}

		$plain = trim( wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

		if ( '' === $plain ) {
			return false;
		}

		$lang = sanitize_key( (string) $lang );

		if ( 'fa' === $lang ) {
			return true;
		}

		if ( 'ar' === $lang ) {
			// Arabic shares the Unicode block with Persian. Brand names such as «تیپاکس»
			// may legitimately keep Persian letters (e.g. پ) inside otherwise Arabic copy.
			return self::contains_arabic_script( $plain ) || self::is_latin_text( $plain );
		}

		if ( 'en' === $lang ) {
			return self::is_latin_text( $plain );
		}

		// Latin and other non-RTL targets should not keep Arabic-script storefront copy.
		return ! self::contains_arabic_script( $plain );
	}

	/**
	 * Check whether a string is primarily Latin/English (no Persian script).
	 *
	 * @param mixed $text Text to inspect.
	 * @return bool
	 */
	public static function is_latin_text( $text ) {
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return false;
		}

		$plain = trim( wp_strip_all_tags( $text ) );

		if ( '' === $plain ) {
			return false;
		}

		if ( self::contains_persian( $plain ) ) {
			return false;
		}

		return (bool) preg_match( '/[A-Za-z]/', $plain );
	}

	/**
	 * Return the value only when it contains Persian; otherwise empty string.
	 *
	 * @param mixed $text Source text.
	 * @return string
	 */
	public static function only_persian_value( $text ) {
		if ( ! is_string( $text ) || ! self::contains_persian( $text ) ) {
			return '';
		}

		return $text;
	}
}
