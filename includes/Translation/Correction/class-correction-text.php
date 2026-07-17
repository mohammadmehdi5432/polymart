<?php
/**
 * Shared find/replace helpers for translation corrections.
 *
 * @package PolymartAI\Translation\Correction
 */

namespace PolymartAI\Translation\Correction;

defined( 'ABSPATH' ) || exit;

/**
 * Class Correction_Text
 */
final class Correction_Text {

	/**
	 * Minimum needle length for contains mode.
	 */
	const MIN_CONTAINS_LENGTH = 3;

	/**
	 * Validate find/replace input.
	 *
	 * @param string $find           Needle.
	 * @param string $replace        Replacement.
	 * @param string $mode           exact|contains.
	 * @param bool   $word_boundary  Latin word boundary.
	 * @return true|\WP_Error
	 */
	public static function validate_pair( $find, $replace, $mode = 'exact', $word_boundary = false ) {
		unset( $word_boundary );

		$find    = is_string( $find ) ? $find : '';
		$replace = is_string( $replace ) ? $replace : '';
		$mode    = 'contains' === $mode ? 'contains' : 'exact';

		if ( '' === trim( $find ) ) {
			return new \WP_Error(
				'polymart_ai_correction_empty_find',
				__( 'متن اشتباه را وارد کنید.', 'polymart-ai' )
			);
		}

		if ( $find === $replace ) {
			return new \WP_Error(
				'polymart_ai_correction_same',
				__( 'متن اشتباه و متن صحیح یکسان هستند.', 'polymart-ai' )
			);
		}

		if ( 'contains' === $mode && self::utf8_strlen( trim( $find ) ) < self::MIN_CONTAINS_LENGTH ) {
			return new \WP_Error(
				'polymart_ai_correction_short_find',
				sprintf(
					/* translators: %d: minimum length */
					__( 'برای حالت «شامل»، متن اشتباه باید حداقل %d نویسه باشد.', 'polymart-ai' ),
					self::MIN_CONTAINS_LENGTH
				)
			);
		}

		return true;
	}

	/**
	 * Whether haystack matches the needle under the given mode.
	 *
	 * @param string $haystack      Text to search.
	 * @param string $needle        Find string.
	 * @param string $mode          exact|contains.
	 * @param bool   $word_boundary Word boundary for Latin.
	 * @return bool
	 */
	public static function matches( $haystack, $needle, $mode = 'exact', $word_boundary = false ) {
		if ( ! is_string( $haystack ) || ! is_string( $needle ) || '' === $needle ) {
			return false;
		}

		$mode = 'contains' === $mode ? 'contains' : 'exact';

		if ( 'exact' === $mode ) {
			return $haystack === $needle;
		}

		if ( $word_boundary && self::is_mostly_latin( $needle ) ) {
			$pattern = '/(?<![\p{L}\p{N}_])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}_])/u';

			return (bool) preg_match( $pattern, $haystack );
		}

		return false !== mb_strpos( $haystack, $needle, 0, 'UTF-8' );
	}

	/**
	 * Replace needle in haystack.
	 *
	 * @param string $haystack      Source.
	 * @param string $needle        Find.
	 * @param string $replacement   Replace with.
	 * @param string $mode          exact|contains.
	 * @param bool   $word_boundary Word boundary.
	 * @return string
	 */
	public static function replace( $haystack, $needle, $replacement, $mode = 'exact', $word_boundary = false ) {
		if ( ! is_string( $haystack ) || ! is_string( $needle ) || '' === $needle ) {
			return is_string( $haystack ) ? $haystack : '';
		}

		$replacement = is_string( $replacement ) ? $replacement : '';
		$mode        = 'contains' === $mode ? 'contains' : 'exact';

		if ( 'exact' === $mode ) {
			return $haystack === $needle ? $replacement : $haystack;
		}

		if ( $word_boundary && self::is_mostly_latin( $needle ) ) {
			$pattern = '/(?<![\p{L}\p{N}_])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}_])/u';
			$result  = preg_replace( $pattern, $replacement, $haystack );

			return is_string( $result ) ? $result : $haystack;
		}

		return str_replace( $needle, $replacement, $haystack );
	}

	/**
	 * Build a short preview snippet around the first match.
	 *
	 * @param string $haystack Text.
	 * @param string $needle   Find.
	 * @param int    $radius   Chars around match.
	 * @return string
	 */
	public static function snippet( $haystack, $needle, $radius = 60 ) {
		$haystack = is_string( $haystack ) ? $haystack : '';
		$needle   = is_string( $needle ) ? $needle : '';
		$radius   = max( 20, absint( $radius ) );

		if ( '' === $haystack ) {
			return '';
		}

		$pos = '' !== $needle ? mb_strpos( $haystack, $needle, 0, 'UTF-8' ) : false;

		if ( false === $pos ) {
			$plain = wp_strip_all_tags( $haystack );
			$len   = self::utf8_strlen( $plain );

			return $len > ( $radius * 2 )
				? self::utf8_substr( $plain, 0, $radius * 2 ) . '…'
				: $plain;
		}

		$start  = max( 0, $pos - $radius );
		$length = self::utf8_strlen( $needle ) + ( $radius * 2 );
		$slice  = self::utf8_substr( $haystack, $start, $length );
		$prefix = $start > 0 ? '…' : '';
		$suffix = ( $start + $length ) < self::utf8_strlen( $haystack ) ? '…' : '';

		return $prefix . wp_strip_all_tags( $slice ) . $suffix;
	}

	/**
	 * Count how many times needle appears (contains) or 1/0 for exact.
	 *
	 * @param string $haystack Text.
	 * @param string $needle   Find.
	 * @param string $mode     Mode.
	 * @param bool   $word_boundary Boundary.
	 * @return int
	 */
	public static function match_count( $haystack, $needle, $mode = 'exact', $word_boundary = false ) {
		if ( ! self::matches( $haystack, $needle, $mode, $word_boundary ) ) {
			return 0;
		}

		if ( 'exact' === $mode ) {
			return 1;
		}

		if ( $word_boundary && self::is_mostly_latin( $needle ) ) {
			$pattern = '/(?<![\p{L}\p{N}_])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}_])/u';

			return (int) preg_match_all( $pattern, $haystack );
		}

		return substr_count( $haystack, $needle );
	}

	/**
	 * @param string $text Text.
	 * @return bool
	 */
	private static function is_mostly_latin( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return false;
		}

		return (bool) preg_match( '/^[A-Za-z0-9\s\-_.&]+$/', $text );
	}

	/**
	 * @param string $text Text.
	 * @return int
	 */
	public static function utf8_strlen( $text ) {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}

	/**
	 * @param string $text   Text.
	 * @param int    $start  Start.
	 * @param int    $length Length.
	 * @return string
	 */
	public static function utf8_substr( $text, $start, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( (string) $text, $start, $length, 'UTF-8' );
		}

		return (string) substr( (string) $text, $start, $length );
	}
}
