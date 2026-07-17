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
	 * Whether needle looks like Arabic/Persian script (needs PHP-side scan).
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function has_arabic_script( $text ) {
		return (bool) preg_match( '/[\x{0600}-\x{06FF}]/u', (string) $text );
	}

	/**
	 * Fold Arabic/Persian lookalikes and cosmetic marks for comparison.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = (string) $text;

		if ( '' === $text ) {
			return '';
		}

		// Strip bidi / zero-width marks that break substring search.
		$text = preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text );
		$text = str_replace( "\xC2\xA0", ' ', $text ); // NBSP

		// Unify common FA/AR letter variants (ی/ي، ک/ك، الف، ة).
		$map = array(
			"\xD9\x8A" => "\xDB\x8C", // ي → ی
			"\xD9\x89" => "\xDB\x8C", // ى → ی
			"\xD9\x83" => "\xDA\xA9", // ك → ک
			"\xD8\xA3" => "\xD8\xA7", // أ → ا
			"\xD8\xA5" => "\xD8\xA7", // إ → ا
			"\xD8\xA2" => "\xD8\xA7", // آ → ا
			"\xD8\xA9" => "\xD9\x87", // ة → ه
			"\xDB\x81" => "\xD9\x87", // ھ → ه
		);

		$text = strtr( $text, $map );

		// Collapse whitespace.
		$text = preg_replace( '/\s+/u', ' ', $text );

		return is_string( $text ) ? trim( $text ) : '';
	}

	/**
	 * SQL LIKE needles (raw + folded + flexible wildcards + JSON \u escapes).
	 *
	 * @param string $find Find string.
	 * @return string[]
	 */
	public static function sql_like_needles( $find ) {
		$find = is_string( $find ) ? $find : '';

		if ( '' === $find ) {
			return array();
		}

		$needles = array( $find );

		$folded_fa = self::normalize( $find );
		if ( '' !== $folded_fa && $folded_fa !== $find ) {
			$needles[] = $folded_fa;
		}

		// Alternate Arabic yeh/kaf forms (inverse of normalize).
		$alt = strtr(
			$find,
			array(
				"\xDB\x8C" => "\xD9\x8A", // ی → ي
				"\xDA\xA9" => "\xD9\x83", // ک → ك
			)
		);
		if ( $alt !== $find ) {
			$needles[] = $alt;
		}

		// Do NOT inject MySQL "_" wildcards here: $wpdb->esc_like() escapes them.

		foreach ( array_values( $needles ) as $n ) {
			$escaped = self::json_unicode_escape( $n );
			if ( '' !== $escaped && $escaped !== $n ) {
				$needles[] = $escaped;
			}
		}

		$out = array();
		foreach ( $needles as $n ) {
			$n = (string) $n;
			if ( '' !== $n ) {
				$out[ $n ] = $n;
			}
		}

		return array_values( $out );
	}

	/**
	 * Escape a UTF-8 string as JSON \uXXXX sequences (Elementor sometimes stores this).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function json_unicode_escape( $text ) {
		$text = (string) $text;
		$len  = self::utf8_strlen( $text );
		$out  = '';

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = self::utf8_substr( $text, $i, 1 );
			$ord = self::utf8_ord( $ch );

			if ( $ord < 0 ) {
				$out .= $ch;
				continue;
			}

			if ( $ord < 0x80 && ! in_array( $ch, array( '\\', '"' ), true ) ) {
				$out .= $ch;
				continue;
			}

			$out .= sprintf( '\\u%04x', $ord );
		}

		return $out;
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
			if ( $haystack === $needle ) {
				return true;
			}

			return self::normalize( $haystack ) === self::normalize( $needle );
		}

		if ( $word_boundary && self::is_mostly_latin( $needle ) ) {
			$pattern = '/(?<![\p{L}\p{N}_])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}_])/iu';

			return (bool) preg_match( $pattern, $haystack );
		}

		if ( false !== mb_strpos( $haystack, $needle, 0, 'UTF-8' ) ) {
			return true;
		}

		$hay_n = self::normalize( $haystack );
		$nee_n = self::normalize( $needle );

		if ( '' === $nee_n ) {
			return false;
		}

		return false !== mb_strpos( $hay_n, $nee_n, 0, 'UTF-8' );
	}

	/**
	 * Replace needle in haystack (supports FA/AR letter variants).
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
			if ( $haystack === $needle || self::normalize( $haystack ) === self::normalize( $needle ) ) {
				return $replacement;
			}

			return $haystack;
		}

		if ( $word_boundary && self::is_mostly_latin( $needle ) ) {
			$pattern = '/(?<![\p{L}\p{N}_])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}_])/iu';
			$result  = preg_replace( $pattern, $replacement, $haystack );

			return is_string( $result ) ? $result : $haystack;
		}

		if ( false !== mb_strpos( $haystack, $needle, 0, 'UTF-8' ) ) {
			return str_replace( $needle, $replacement, $haystack );
		}

		$pattern = self::flexible_needle_regex( $needle );

		if ( '' === $pattern ) {
			return $haystack;
		}

		$result = preg_replace( '/' . $pattern . '/u', $replacement, $haystack );

		return is_string( $result ) ? $result : $haystack;
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
		$len = '' !== $needle ? self::utf8_strlen( $needle ) : 0;

		if ( false === $pos && '' !== $needle ) {
			$range = self::find_flexible_range( $haystack, $needle );
			if ( is_array( $range ) ) {
				$pos = $range[0];
				$len = $range[1];
			}
		}

		if ( false === $pos ) {
			$plain = wp_strip_all_tags( $haystack );
			$plen  = self::utf8_strlen( $plain );

			return $plen > ( $radius * 2 )
				? self::utf8_substr( $plain, 0, $radius * 2 ) . '…'
				: $plain;
		}

		$start  = max( 0, $pos - $radius );
		$length = $len + ( $radius * 2 );
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
			$pattern = '/(?<![\p{L}\p{N}_])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}_])/iu';

			return (int) preg_match_all( $pattern, $haystack );
		}

		if ( false !== mb_strpos( $haystack, $needle, 0, 'UTF-8' ) ) {
			return substr_count( $haystack, $needle );
		}

		$pattern = self::flexible_needle_regex( $needle );

		if ( '' === $pattern ) {
			return 1;
		}

		return (int) preg_match_all( '/' . $pattern . '/u', $haystack );
	}

	/**
	 * Regex fragment matching needle with FA/AR letter flexibility.
	 *
	 * @param string $needle Needle.
	 * @return string
	 */
	private static function flexible_needle_regex( $needle ) {
		$needle = (string) $needle;
		$len    = self::utf8_strlen( $needle );

		if ( $len < 1 ) {
			return '';
		}

		$parts = array();

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = self::utf8_substr( $needle, $i, 1 );

			if ( preg_match( '/\s/u', $ch ) ) {
				$parts[] = '\s+';
				continue;
			}

			if ( in_array( $ch, array( 'ی', 'ي', 'ى' ), true ) ) {
				$parts[] = '[یيى]';
				continue;
			}

			if ( in_array( $ch, array( 'ک', 'ك' ), true ) ) {
				$parts[] = '[کك]';
				continue;
			}

			if ( in_array( $ch, array( 'ا', 'أ', 'إ', 'آ' ), true ) ) {
				$parts[] = '[اأإآ]';
				continue;
			}

			if ( in_array( $ch, array( 'ه', 'ة', 'ھ' ), true ) ) {
				$parts[] = '[هةھ]';
				continue;
			}

			$parts[] = preg_quote( $ch, '/' );
		}

		return implode( '', $parts );
	}

	/**
	 * Locate flexible match range in haystack (char offset + length).
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return array{0:int,1:int}|null
	 */
	private static function find_flexible_range( $haystack, $needle ) {
		$pattern = self::flexible_needle_regex( $needle );

		if ( '' === $pattern || ! preg_match( '/' . $pattern . '/u', $haystack, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$byte_pos = isset( $m[0][1] ) ? (int) $m[0][1] : 0;
		$matched  = isset( $m[0][0] ) ? (string) $m[0][0] : '';

		// Convert byte offset to UTF-8 char offset.
		$prefix = substr( $haystack, 0, $byte_pos );
		$pos    = self::utf8_strlen( $prefix );

		return array( $pos, self::utf8_strlen( $matched ) );
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

	/**
	 * @param string $char Single UTF-8 char.
	 * @return int
	 */
	private static function utf8_ord( $char ) {
		if ( function_exists( 'mb_ord' ) ) {
			$ord = mb_ord( (string) $char, 'UTF-8' );

			return false === $ord ? -1 : (int) $ord;
		}

		$char = (string) $char;
		$u    = unpack( 'N', mb_convert_encoding( $char, 'UCS-4BE', 'UTF-8' ) );

		return is_array( $u ) && isset( $u[1] ) ? (int) $u[1] : -1;
	}
}
