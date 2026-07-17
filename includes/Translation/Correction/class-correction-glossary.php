<?php
/**
 * Persistent preferred-term glossary for translation corrections.
 *
 * @package PolymartAI\Translation\Correction
 */

namespace PolymartAI\Translation\Correction;

defined( 'ABSPATH' ) || exit;

/**
 * Class Correction_Glossary
 */
final class Correction_Glossary {

	/**
	 * Option key.
	 */
	const OPTION = 'polymart_ai_correction_glossary';

	/**
	 * Max entries per language.
	 */
	const MAX_PER_LANG = 200;

	/**
	 * Get full glossary map.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function get_all() {
		$raw = get_option( self::OPTION, array() );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();

		foreach ( $raw as $lang => $entries ) {
			$lang = sanitize_key( (string) $lang );

			if ( '' === $lang || ! is_array( $entries ) ) {
				continue;
			}

			$clean = array();

			foreach ( $entries as $entry ) {
				$normalized = self::normalize_entry( $entry );

				if ( null !== $normalized ) {
					$clean[] = $normalized;
				}
			}

			if ( ! empty( $clean ) ) {
				$out[ $lang ] = array_slice( $clean, 0, self::MAX_PER_LANG );
			}
		}

		return $out;
	}

	/**
	 * Entries for one language.
	 *
	 * @param string $lang Language code.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_lang( $lang ) {
		$lang = sanitize_key( (string) $lang );
		$all  = self::get_all();

		return isset( $all[ $lang ] ) && is_array( $all[ $lang ] ) ? $all[ $lang ] : array();
	}

	/**
	 * Replace entire glossary.
	 *
	 * @param array<string, mixed> $data Glossary payload.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function save_all( array $data ) {
		$clean = array();

		foreach ( $data as $lang => $entries ) {
			$lang = sanitize_key( (string) $lang );

			if ( '' === $lang || ! is_array( $entries ) ) {
				continue;
			}

			$lang_entries = array();
			$seen         = array();

			foreach ( $entries as $entry ) {
				$normalized = self::normalize_entry( $entry );

				if ( null === $normalized ) {
					continue;
				}

				$key = mb_strtolower( $normalized['wrong'], 'UTF-8' );

				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ]   = true;
				$lang_entries[] = $normalized;
			}

			if ( ! empty( $lang_entries ) ) {
				$clean[ $lang ] = array_slice( $lang_entries, 0, self::MAX_PER_LANG );
			}
		}

		update_option( self::OPTION, $clean, false );

		return $clean;
	}

	/**
	 * Insert or update one glossary pair.
	 *
	 * @param string $lang       Language.
	 * @param string $wrong      Wrong form.
	 * @param string $preferred  Preferred form.
	 * @param string $match      exact|contains.
	 * @return array<int, array<string, mixed>>
	 */
	public static function upsert( $lang, $wrong, $preferred, $match = 'exact' ) {
		$lang      = sanitize_key( (string) $lang );
		$entry     = self::normalize_entry(
			array(
				'wrong'     => $wrong,
				'preferred' => $preferred,
				'match'     => $match,
			)
		);
		$all       = self::get_all();
		$entries   = isset( $all[ $lang ] ) ? $all[ $lang ] : array();
		$key       = null !== $entry ? mb_strtolower( $entry['wrong'], 'UTF-8' ) : '';
		$replaced  = false;

		if ( null === $entry || '' === $lang ) {
			return $entries;
		}

		foreach ( $entries as $index => $existing ) {
			if ( mb_strtolower( (string) ( $existing['wrong'] ?? '' ), 'UTF-8' ) === $key ) {
				$entries[ $index ] = $entry;
				$replaced          = true;
				break;
			}
		}

		if ( ! $replaced ) {
			array_unshift( $entries, $entry );
		}

		$all[ $lang ] = array_slice( $entries, 0, self::MAX_PER_LANG );
		update_option( self::OPTION, $all, false );

		return $all[ $lang ];
	}

	/**
	 * Remove one glossary pair.
	 *
	 * @param string $lang  Language.
	 * @param string $wrong Wrong form.
	 * @return array<int, array<string, mixed>>
	 */
	public static function delete( $lang, $wrong ) {
		$lang  = sanitize_key( (string) $lang );
		$wrong = is_string( $wrong ) ? trim( $wrong ) : '';
		$all   = self::get_all();

		if ( '' === $lang || '' === $wrong || empty( $all[ $lang ] ) ) {
			return isset( $all[ $lang ] ) ? $all[ $lang ] : array();
		}

		$key = mb_strtolower( $wrong, 'UTF-8' );
		$out = array();

		foreach ( $all[ $lang ] as $entry ) {
			if ( mb_strtolower( (string) ( $entry['wrong'] ?? '' ), 'UTF-8' ) === $key ) {
				continue;
			}

			$out[] = $entry;
		}

		if ( empty( $out ) ) {
			unset( $all[ $lang ] );
		} else {
			$all[ $lang ] = $out;
		}

		update_option( self::OPTION, $all, false );

		return $out;
	}

	/**
	 * Apply glossary replacements to an outgoing storefront/AI string.
	 *
	 * @param string $text Text.
	 * @param string $lang Language.
	 * @return string
	 */
	public static function apply_to_text( $text, $lang ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return is_string( $text ) ? $text : '';
		}

		$entries = self::get_for_lang( $lang );

		if ( empty( $entries ) ) {
			return $text;
		}

		// Exact first, then contains (longer needles first).
		$exact    = array();
		$contains = array();

		foreach ( $entries as $entry ) {
			if ( 'contains' === ( $entry['match'] ?? 'exact' ) ) {
				$contains[] = $entry;
			} else {
				$exact[] = $entry;
			}
		}

		usort(
			$contains,
			static function ( $a, $b ) {
				return Correction_Text::utf8_strlen( (string) ( $b['wrong'] ?? '' ) )
					- Correction_Text::utf8_strlen( (string) ( $a['wrong'] ?? '' ) );
			}
		);

		foreach ( $exact as $entry ) {
			if ( $text === (string) $entry['wrong'] ) {
				return (string) $entry['preferred'];
			}
		}

		foreach ( $contains as $entry ) {
			$wrong = (string) $entry['wrong'];

			if ( '' === $wrong || false === mb_strpos( $text, $wrong, 0, 'UTF-8' ) ) {
				continue;
			}

			$text = str_replace( $wrong, (string) $entry['preferred'], $text );
		}

		return $text;
	}

	/**
	 * Prompt fragment listing preferred forms for a language.
	 *
	 * @param string $lang Language.
	 * @return string
	 */
	public static function format_for_ai_prompt( $lang ) {
		$entries = self::get_for_lang( $lang );

		if ( empty( $entries ) ) {
			return '';
		}

		$lines = array( 'Always use these preferred forms (never the wrong variants):' );
		$count = 0;

		foreach ( $entries as $entry ) {
			$wrong     = (string) ( $entry['wrong'] ?? '' );
			$preferred = (string) ( $entry['preferred'] ?? '' );

			if ( '' === $wrong || '' === $preferred ) {
				continue;
			}

			$lines[] = '- Wrong: «' . $wrong . '» → Preferred: «' . $preferred . '»';
			++$count;

			if ( $count >= 40 ) {
				break;
			}
		}

		return $count > 0 ? implode( ' ', $lines ) : '';
	}

	/**
	 * Merge exact glossary pairs into the seed dictionary filter.
	 *
	 * @param array<string, string> $dictionary Existing dictionary.
	 * @return array<string, string>
	 */
	public static function merge_into_dictionary( $dictionary ) {
		if ( ! is_array( $dictionary ) ) {
			$dictionary = array();
		}

		foreach ( self::get_all() as $entries ) {
			foreach ( $entries as $entry ) {
				if ( 'exact' !== ( $entry['match'] ?? 'exact' ) ) {
					continue;
				}

				$wrong     = (string) ( $entry['wrong'] ?? '' );
				$preferred = (string) ( $entry['preferred'] ?? '' );

				if ( '' !== $wrong && '' !== $preferred ) {
					$dictionary[ $wrong ] = $preferred;
				}
			}
		}

		return $dictionary;
	}

	/**
	 * @param mixed $entry Raw entry.
	 * @return array{wrong:string,preferred:string,match:string}|null
	 */
	private static function normalize_entry( $entry ) {
		if ( ! is_array( $entry ) ) {
			return null;
		}

		$wrong     = isset( $entry['wrong'] ) ? trim( (string) $entry['wrong'] ) : '';
		$preferred = isset( $entry['preferred'] ) ? trim( (string) $entry['preferred'] ) : '';
		$match     = isset( $entry['match'] ) && 'contains' === $entry['match'] ? 'contains' : 'exact';

		if ( '' === $wrong || '' === $preferred || $wrong === $preferred ) {
			return null;
		}

		if ( 'contains' === $match && Correction_Text::utf8_strlen( $wrong ) < Correction_Text::MIN_CONTAINS_LENGTH ) {
			$match = 'exact';
		}

		return array(
			'wrong'     => $wrong,
			'preferred' => $preferred,
			'match'     => $match,
		);
	}
}
