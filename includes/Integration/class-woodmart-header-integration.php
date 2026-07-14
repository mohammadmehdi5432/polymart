<?php
/**
 * Woodmart Header Builder (WHB) Persian strings for UI scan and storefront runtime.
 *
 * Custom header labels (e.g. «دسته بندی ها») live in whb_* options, not theme PHP,
 * so the default UI string catalog never finds them.
 *
 * @package PolymartAI\Integration
 */

namespace PolymartAI\Integration;

use PolymartAI\Translation\AI\Persian_Detector;
use PolymartAI\Translation\Storefront\Runtime_String_Translator;
use PolymartAI\Translation\UI_String\UI_String_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Woodmart_Header_Integration
 */
final class Woodmart_Header_Integration {

	/**
	 * UI-string context for WHB custom labels.
	 */
	const UI_CONTEXT = 'whb';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'polymart_ai_ui_string_scan_extra_entries', array( __CLASS__, 'collect_ui_string_entries' ) );
	}

	/**
	 * Resolve a WHB/header label for the English storefront.
	 *
	 * @param string $text Original Persian text.
	 * @param string $lang Target language code.
	 * @return string|null
	 */
	public static function resolve_storefront_text( $text, $lang ) {
		$text = is_string( $text ) ? trim( wp_strip_all_tags( $text ) ) : '';
		$lang = sanitize_key( (string) $lang );

		if ( '' === $text || '' === $lang || ! Persian_Detector::contains_persian( $text ) ) {
			return null;
		}

		foreach ( array( self::UI_CONTEXT, '', 'woodmart' ) as $context ) {
			$stored = UI_String_Registry::lookup( $lang, $text, $context );

			if ( null !== $stored && '' !== trim( $stored ) && $stored !== $text ) {
				return $stored;
			}
		}

		$cached = Runtime_String_Translator::lookup_cached( $text, $lang, 'whb:' . md5( $text ) );

		if ( is_string( $cached ) && '' !== trim( $cached ) && $cached !== $text ) {
			return $cached;
		}

		return null;
	}

	/**
	 * Register Persian strings saved inside Woodmart header builder options.
	 *
	 * @param array<int, array<string, string>> $entries Existing scan entries.
	 * @return array<int, array<string, string>>
	 */
	public static function collect_ui_string_entries( array $entries ) {
		global $wpdb;

		if ( ! isset( $wpdb->options ) ) {
			return $entries;
		}

		$seen = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['msgid'] ) ) {
				continue;
			}

			$context = isset( $entry['context'] ) ? (string) $entry['context'] : '';
			$seen[ md5( $context . '|' . (string) $entry['msgid'] ) ] = true;
		}

		foreach ( self::collect_whb_persian_strings() as $item ) {
			$msgid   = (string) ( $item['text'] ?? '' );
			$context = (string) ( $item['context'] ?? self::UI_CONTEXT );
			$key     = md5( $context . '|' . $msgid );

			if ( '' === $msgid || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$entries[]    = array(
				'msgid'   => $msgid,
				'context' => $context,
				'domain'  => 'woodmart',
				'plugin'  => 'WoodmartHeader',
				'reference' => (string) ( $item['reference'] ?? '' ),
			);
		}

		return $entries;
	}

	/**
	 * Extract Persian user-facing strings from WHB option rows.
	 *
	 * @return array<int, array{text: string, context: string, reference: string}>
	 */
	public static function collect_whb_persian_strings() {
		global $wpdb;

		if ( ! isset( $wpdb->options ) ) {
			return array();
		}

		$rows = $wpdb->get_results(
			"SELECT option_name, option_value
			FROM {$wpdb->options}
			WHERE (
				option_name LIKE 'whb\\_header\\_%'
				OR option_name IN ( 'whb_main_header', 'whb_default_header', 'whb_headers' )
			)
			AND option_name NOT LIKE '%css%'
			LIMIT 120"
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$strings = array();
		$seen    = array();

		foreach ( $rows as $row ) {
			if ( ! isset( $row->option_name, $row->option_value ) ) {
				continue;
			}

			$option_name = (string) $row->option_name;
			$value       = maybe_unserialize( $row->option_value );

			self::walk_whb_value(
				$value,
				$strings,
				$seen,
				$option_name,
				$option_name
			);
		}

		return $strings;
	}

	/**
	 * Recursively collect Persian strings from a WHB option payload.
	 *
	 * @param mixed                              $value       Option node.
	 * @param array<int, array<string, string>> $strings     Output list.
	 * @param array<string, bool>                $seen        Dedupe map.
	 * @param string                             $reference   Option name / path.
	 * @param string                             $path        Current JSON path.
	 * @return void
	 */
	private static function walk_whb_value( $value, array &$strings, array &$seen, $reference, $path ) {
		if ( is_string( $value ) ) {
			$plain = trim( wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

			if ( '' === $plain || ! Persian_Detector::contains_persian( $plain ) ) {
				return;
			}

			$key = md5( self::UI_CONTEXT . '|' . $plain );

			if ( isset( $seen[ $key ] ) ) {
				return;
			}

			$seen[ $key ]  = true;
			$strings[]     = array(
				'text'      => $plain,
				'context'   => self::UI_CONTEXT,
				'reference' => $reference . ( $path !== $reference ? ' → ' . $path : '' ),
			);

			return;
		}

		if ( ! is_array( $value ) ) {
			return;
		}

		foreach ( $value as $key => $child ) {
			$child_path = is_string( $key ) || is_int( $key ) ? $path . '.' . (string) $key : $path;

			if ( is_string( $key ) && self::should_skip_whb_key( (string) $key ) ) {
				continue;
			}

			self::walk_whb_value( $child, $strings, $seen, $reference, $child_path );
		}
	}

	/**
	 * Skip WHB structural keys that are never customer-facing copy.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	private static function should_skip_whb_key( $key ) {
		static $blocked = array(
			'id'             => true,
			'type'           => true,
			'elType'         => true,
			'params'         => false,
			'content'        => false,
			'title'          => false,
			'text'           => false,
			'label'          => false,
			'url'            => true,
			'link'           => true,
			'icon'           => true,
			'image'          => true,
			'image_url'      => true,
			'custom_icon'    => true,
			'css'            => true,
			'background'     => true,
			'color'          => true,
			'width'          => true,
			'height'         => true,
			'align'          => true,
			'sticky'         => true,
			'hide_on_desktop' => true,
			'hide_on_mobile' => true,
		);

		if ( isset( $blocked[ $key ] ) ) {
			return (bool) $blocked[ $key ];
		}

		if ( preg_match( '/(_id|_url|_link|_icon|_image|_css|_class|_color|_width|_height|_align|sticky|visibility)$/i', $key ) ) {
			return true;
		}

		return false;
	}
}
