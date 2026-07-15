<?php
/**
 * Mask WordPress shortcodes before AI translation and restore them afterward.
 *
 * @package PolymartAI\Translation\Post_Translator
 */

namespace PolymartAI\Translation\Post_Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Shortcode_Masker
 */
final class Shortcode_Masker {

	const TOKEN_PREFIX = '__SHORTCODE_';

	/**
	 * Whether a string contains at least one WordPress-style shortcode.
	 *
	 * @param mixed $text Text value.
	 * @return bool
	 */
	public static function contains_shortcode( $text ) {
		return (bool) preg_match( self::shortcode_pattern(), (string) $text );
	}

	/**
	 * Extract shortcode literals in document order.
	 *
	 * @param mixed $text Text value.
	 * @return string[]
	 */
	public static function extract_shortcodes( $text ) {
		$text = (string) $text;

		if ( '' === $text || ! self::contains_shortcode( $text ) ) {
			return array();
		}

		if ( preg_match_all( self::shortcode_pattern(), $text, $matches ) && ! empty( $matches[0] ) ) {
			return array_values( array_map( 'strval', $matches[0] ) );
		}

		return array();
	}

	/**
	 * Replace shortcodes with stable non-translatable tokens.
	 *
	 * @param mixed $text Text value.
	 * @return string
	 */
	public static function mask_text( $text ) {
		$text = (string) $text;

		if ( '' === $text || ! self::contains_shortcode( $text ) ) {
			return $text;
		}

		$index = 0;

		return (string) preg_replace_callback(
			self::shortcode_pattern(),
			static function () use ( &$index ) {
				$token = self::TOKEN_PREFIX . $index . '__';
				++$index;

				return $token;
			},
			$text
		);
	}

	/**
	 * Restore shortcodes from the original source into a translated string.
	 *
	 * Handles masked tokens, missing brackets, or reordered shortcodes.
	 *
	 * @param mixed $source     Original source text.
	 * @param mixed $translated AI translation (possibly missing shortcodes).
	 * @return string
	 */
	public static function restore_shortcodes( $source, $translated ) {
		$source     = (string) $source;
		$translated = trim( (string) $translated );
		$shortcodes = self::extract_shortcodes( $source );

		if ( empty( $shortcodes ) ) {
			return self::unmask_known_tokens( $translated, $source );
		}

		if ( '' === $translated ) {
			return $translated;
		}

		$translated = self::unmask_known_tokens( $translated, $source );

		$source_index = 0;

		$translated = (string) preg_replace_callback(
			self::shortcode_pattern(),
			static function () use ( $shortcodes, &$source_index ) {
				if ( isset( $shortcodes[ $source_index ] ) ) {
					$value = $shortcodes[ $source_index ];
					++$source_index;

					return $value;
				}

				return '';
			},
			$translated
		);

		$expected = count( $shortcodes );
		$found    = count( self::extract_shortcodes( $translated ) );

		if ( $found >= $expected ) {
			return $translated;
		}

		// AI dropped every shortcode — append originals from source in order.
		foreach ( $shortcodes as $shortcode ) {
			if ( false === strpos( $translated, $shortcode ) ) {
				if ( '' !== $translated && ! preg_match( '/\s$/u', $translated ) ) {
					$translated .= ' ';
				}

				$translated .= $shortcode;
			}
		}

		return trim( $translated );
	}

	/**
	 * Mask every value in a translation payload.
	 *
	 * @param array<string, string> $payload Field map.
	 * @return array<string, string>
	 */
	public static function mask_payload( array $payload ) {
		$masked = array();

		foreach ( $payload as $key => $value ) {
			$masked[ (string) $key ] = self::mask_text( (string) $value );
		}

		return $masked;
	}

	/**
	 * Restore shortcodes for each translated field using the original source map.
	 *
	 * @param array<string, string> $source      Original field map.
	 * @param array<string, string> $translations Translated field map.
	 * @return array<string, string>
	 */
	public static function restore_payload_shortcodes( array $source, array $translations ) {
		$restored = array();

		foreach ( $translations as $key => $value ) {
			$key = (string) $key;

			if ( isset( $source[ $key ] ) ) {
				$restored[ $key ] = self::restore_shortcodes( $source[ $key ], (string) $value );
			} else {
				$restored[ $key ] = (string) $value;
			}
		}

		return $restored;
	}

	/**
	 * Whether all source shortcodes appear unchanged in the translation.
	 *
	 * @param mixed $source     Source text.
	 * @param mixed $translated Translated text.
	 * @return bool
	 */
	public static function shortcodes_preserved( $source, $translated ) {
		$source_shortcodes = self::extract_shortcodes( $source );

		if ( empty( $source_shortcodes ) ) {
			return true;
		}

		$translated = self::restore_shortcodes( $source, $translated );

		foreach ( $source_shortcodes as $shortcode ) {
			if ( false === strpos( (string) $translated, $shortcode ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return string
	 */
	private static function shortcode_pattern() {
		return '/\[(?:\/)?[a-zA-Z][\w-]*(?:[^\]]*)?\]/';
	}

	/**
	 * Replace __SHORTCODE_N__ tokens using the source shortcode list.
	 *
	 * @param string $translated Translated text.
	 * @param string $source     Source text.
	 * @return string
	 */
	private static function unmask_known_tokens( $translated, $source ) {
		$shortcodes = self::extract_shortcodes( $source );

		if ( empty( $shortcodes ) ) {
			return (string) $translated;
		}

		return (string) preg_replace_callback(
			'/' . preg_quote( self::TOKEN_PREFIX, '/' ) . '(\d+)__/',
			static function ( $matches ) use ( $shortcodes ) {
				$index = absint( $matches[1] ?? 0 );

				return (string) ( $shortcodes[ $index ] ?? $matches[0] );
			},
			(string) $translated
		);
	}
}
