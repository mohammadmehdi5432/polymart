<?php
/**
 * Shared storefront gettext resolution for WooCommerce, Woodmart, and UI strings.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation\Storefront;

use PolymartAI\Language_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Storefront_Gettext_Resolver
 */
final class Storefront_Gettext_Resolver {

	/**
	 * Resolve a gettext string for the active translated storefront language.
	 *
	 * @param string $translation Current translation from loaded .mo files.
	 * @param string $text        Source msgid.
	 * @param string $context     Optional msgctxt.
	 * @param string $lang        Active language code.
	 * @param string $storage_prefix Cache key prefix (e.g. wc_gettext:).
	 * @param string $cache_key   Optional full cache key override.
	 * @return string
	 */
	public static function resolve( $translation, $text, $context, $lang, $storage_prefix, $cache_key = '' ) {
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return is_string( $translation ) ? $translation : (string) $text;
		}

		$translation = is_string( $translation ) ? $translation : $text;
		$context     = is_string( $context ) ? $context : '';
		$lang        = sanitize_key( (string) $lang );
		$default     = Language_Registry::get_default_language_code();

		if ( '' === $cache_key ) {
			$cache_key = $storage_prefix . md5( $context . '|' . $text );
		}

		$stored = UI_String_Registry::lookup( $lang, $text, $context );

		if ( null !== $stored ) {
			return $stored;
		}

		if ( Persian_Detector::contains_persian( $text ) ) {
			return Runtime_String_Translator::translate( $text, $lang, $cache_key );
		}

		/*
		 * WooCommerce/Woodmart msgids are English while fa_IR .mo may supply a Persian
		 * $translation. English URLs should show the msgid; other languages translate
		 * from that Persian string when present.
		 */
		if ( Persian_Detector::contains_persian( $translation ) ) {
			if ( 'en' === $lang ) {
				return $text;
			}

			return Runtime_String_Translator::translate( $translation, $lang, $cache_key );
		}

		/*
		 * English msgid + English (or identical) translation on a non-default storefront
		 * (e.g. /ar/) — translate the Latin source into the target language.
		 */
		if ( $lang !== $default && 'en' !== $lang ) {
			$runtime = Runtime_String_Translator::translate( $text, $lang, $cache_key );

			if ( $runtime !== $text ) {
				return $runtime;
			}
		}

		return $translation;
	}
}
