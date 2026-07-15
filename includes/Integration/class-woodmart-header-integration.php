<?php
/**
 * Woodmart Header Builder (WHB) Persian strings for UI scan and storefront runtime.
 *
 * Custom header labels / text-html widgets live in whb_* options, not theme PHP,
 * so the default UI string catalog never finds them.
 *
 * @package PolymartAI\Integration
 */

namespace PolymartAI\Integration;

use PolymartAI\Routing\Url_Router;
use PolymartAI\Translation\AI\AI_Client;
use PolymartAI\Translation\AI\Persian_Detector;
use PolymartAI\Translation\Post_Translator;
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
	 * Whether storefront option filters are registered.
	 *
	 * @var bool
	 */
	private static $option_filters_registered = false;

	/**
	 * Recursion guard while filtering option trees.
	 *
	 * @var int
	 */
	private static $filter_depth = 0;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'polymart_ai_ui_string_scan_extra_entries', array( __CLASS__, 'collect_ui_string_entries' ) );
		add_action( 'init', array( __CLASS__, 'maybe_register_storefront_option_filters' ), 25 );
		add_action( 'polymart_ai_ui_strings_after_scan', array( __CLASS__, 'ensure_registry_entries_after_scan' ), 10, 0 );
	}

	/**
	 * Force WHB Persian strings into the UI registry so the UI bulk job can translate them.
	 *
	 * @return int Number of newly registered msgids.
	 */
	public static function ensure_registry_entries_after_scan() {
		$entries = self::collect_ui_string_entries( array() );
		$registry = UI_String_Registry::get_registry();
		$bucket   = isset( $registry['entries'] ) && is_array( $registry['entries'] ) ? $registry['entries'] : array();
		$added    = 0;

		foreach ( $entries as $entry ) {
			$msgid   = isset( $entry['msgid'] ) ? (string) $entry['msgid'] : '';
			$context = isset( $entry['context'] ) ? (string) $entry['context'] : self::UI_CONTEXT;

			if ( '' === trim( $msgid ) ) {
				continue;
			}

			$hash = UI_String_Registry::string_hash( $msgid, $context );

			if ( isset( $bucket[ $hash ] ) ) {
				continue;
			}

			$bucket[ $hash ] = array(
				'msgid'   => $msgid,
				'context' => $context,
				'domain'  => isset( $entry['domain'] ) ? (string) $entry['domain'] : 'woodmart',
				'plugin'  => isset( $entry['plugin'] ) ? (string) $entry['plugin'] : 'WoodmartHeader',
				'plural'  => '',
			);
			++$added;
		}

		if ( $added > 0 ) {
			$registry['entries'] = $bucket;
			UI_String_Registry::save_registry( $registry );
		}

		return $added;
	}

	/**
	 * Synchronously translate WHB text/html strings for a language (admin / jobs / CLI tools).
	 *
	 * @param string $lang Target language.
	 * @return array{translated:int, failed:int, skipped:int}
	 */
	public static function translate_strings_for_language( $lang ) {
		$lang = sanitize_key( (string) $lang );
		$stats = array(
			'translated' => 0,
			'failed'     => 0,
			'skipped'    => 0,
		);

		if ( '' === $lang || 'fa' === $lang ) {
			return $stats;
		}

		$items   = self::collect_whb_persian_strings();
		$payload = array();
		$map     = array();

		foreach ( $items as $item ) {
			$text = isset( $item['text'] ) ? trim( (string) $item['text'] ) : '';

			if ( '' === $text || ! Persian_Detector::contains_persian( $text ) ) {
				++$stats['skipped'];
				continue;
			}

			$existing = self::resolve_storefront_text( $text, $lang );

			if ( is_string( $existing ) && '' !== trim( $existing ) && $existing !== $text && ! Persian_Detector::contains_persian( $existing ) ) {
				++$stats['skipped'];
				continue;
			}

			$key           = 'whb_' . substr( md5( $text ), 0, 12 );
			$payload[ $key ] = $text;
			$map[ $key ]     = $text;
		}

		if ( empty( $payload ) ) {
			return $stats;
		}

		$settings = Post_Translator::get_translation_settings();

		if ( empty( $settings['api_key'] ) || empty( $settings['api_endpoint'] ) ) {
			$stats['failed'] = count( $payload );

			return $stats;
		}

		$api_key      = (string) $settings['api_key'];
		$api_endpoint = rtrim( (string) $settings['api_endpoint'], '/' );
		$ai_model     = AI_Client::resolve_model(
			(string) ( $settings['ai_model'] ?? '' ),
			$api_endpoint
		);

		$result = AI_Client::translate_fields( $payload, $api_key, $api_endpoint, $ai_model, $lang );

		if ( is_wp_error( $result ) ) {
			$stats['failed'] = count( $payload );

			return $stats;
		}

		$ui_bucket = array();

		foreach ( $map as $key => $source ) {
			$translated = isset( $result[ $key ] ) ? trim( (string) $result[ $key ] ) : '';

			if ( '' === $translated || $translated === $source || Persian_Detector::contains_persian( $translated ) ) {
				++$stats['failed'];
				continue;
			}

			$hash               = UI_String_Registry::string_hash( $source, self::UI_CONTEXT );
			$ui_bucket[ $hash ] = $translated;
			Runtime_String_Translator::store_translation( $source, $lang, 'whb:' . md5( $source ), $translated );
			++$stats['translated'];
		}

		if ( ! empty( $ui_bucket ) ) {
			UI_String_Registry::save_translations( $lang, $ui_bucket );
		}

		Runtime_String_Translator::flush_pending_cache();

		return $stats;
	}

	/**
	 * Register option_* filters so WHB text/html widgets render translated copy on /en/.
	 *
	 * @return void
	 */
	public static function maybe_register_storefront_option_filters() {
		if ( self::$option_filters_registered || is_admin() || ! Url_Router::is_translated_request() ) {
			return;
		}

		self::$option_filters_registered = true;

		foreach ( self::list_whb_option_names() as $option_name ) {
			add_filter( 'option_' . $option_name, array( __CLASS__, 'filter_whb_option_value' ), 20 );
		}
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
				'msgid'     => $msgid,
				'context'   => $context,
				'domain'    => 'woodmart',
				'plugin'    => 'WoodmartHeader',
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
		$strings = array();
		$seen    = array();

		foreach ( self::load_whb_option_rows() as $option_name => $value ) {
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
	 * Translate Persian leaves inside a WHB option payload on the storefront.
	 *
	 * @param mixed $value Option value.
	 * @return mixed
	 */
	public static function filter_whb_option_value( $value ) {
		if ( self::$filter_depth > 0 || null === $value || false === $value ) {
			return $value;
		}

		$lang = sanitize_key( (string) Url_Router::get_current_language() );

		if ( '' === $lang || 'fa' === $lang ) {
			return $value;
		}

		++self::$filter_depth;

		try {
			return self::translate_whb_tree( $value, $lang );
		} finally {
			--self::$filter_depth;
		}
	}

	/**
	 * List WHB option names that may contain customer-facing copy.
	 *
	 * @return string[]
	 */
	private static function list_whb_option_names() {
		return array_keys( self::load_whb_option_rows() );
	}

	/**
	 * Load WHB option rows from the database.
	 *
	 * @return array<string, mixed>
	 */
	private static function load_whb_option_rows() {
		global $wpdb;

		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$cache = array();

		if ( ! isset( $wpdb->options ) ) {
			return $cache;
		}

		$rows = $wpdb->get_results(
			"SELECT option_name, option_value
			FROM {$wpdb->options}
			WHERE (
				option_name LIKE 'whb\\_header\\_%'
				OR option_name LIKE '\\_woodmart\\_whb\\_%'
				OR option_name LIKE 'woodmart\\_whb\\_%'
				OR option_name IN ( 'whb_main_header', 'whb_default_header', 'whb_headers' )
			)
			AND option_name NOT LIKE '%css%'
			LIMIT 160"
		);

		if ( ! is_array( $rows ) ) {
			return $cache;
		}

		foreach ( $rows as $row ) {
			if ( ! isset( $row->option_name, $row->option_value ) ) {
				continue;
			}

			$option_name = (string) $row->option_name;
			$cache[ $option_name ] = maybe_unserialize( $row->option_value );
		}

		return $cache;
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
			$plain = self::normalize_plain_text( $value );

			if ( '' === $plain || ! Persian_Detector::contains_persian( $plain ) ) {
				return;
			}

			$key = md5( self::UI_CONTEXT . '|' . $plain );

			if ( isset( $seen[ $key ] ) ) {
				return;
			}

			$seen[ $key ] = true;
			$strings[]    = array(
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
	 * Walk a WHB tree and replace Persian user-facing leaves.
	 *
	 * @param mixed  $value Option node.
	 * @param string $lang  Target language.
	 * @return mixed
	 */
	private static function translate_whb_tree( $value, $lang ) {
		if ( is_string( $value ) ) {
			return self::translate_whb_leaf( $value, $lang );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $child ) {
			if ( is_string( $key ) && self::should_skip_whb_key( (string) $key ) ) {
				continue;
			}

			$value[ $key ] = self::translate_whb_tree( $child, $lang );
		}

		return $value;
	}

	/**
	 * Translate one WHB string leaf (plain text or text/html widget content).
	 *
	 * @param string $value Original value.
	 * @param string $lang  Target language.
	 * @return string
	 */
	private static function translate_whb_leaf( $value, $lang ) {
		if ( ! Persian_Detector::contains_persian( $value ) ) {
			return $value;
		}

		$plain = self::normalize_plain_text( $value );

		if ( '' === $plain ) {
			return $value;
		}

		$resolved = self::resolve_storefront_text( $plain, $lang );

		if ( null === $resolved || '' === trim( $resolved ) || $resolved === $plain ) {
			$resolved = Runtime_String_Translator::translate( $plain, $lang, 'whb:' . md5( $plain ) );
		}

		if ( ! is_string( $resolved ) || '' === trim( $resolved ) || $resolved === $plain ) {
			return $value;
		}

		$stripped = wp_strip_all_tags( $value );

		if ( $value === $stripped ) {
			return $resolved;
		}

		// Exact substring swap when the normalized plain text still appears as-is.
		if ( false !== strpos( $value, $plain ) ) {
			return str_replace( $plain, $resolved, $value );
		}

		// WHB text/html widgets often store the same copy with extra newlines/nbsp.
		$flexible = '/' . preg_replace( '/\s+/u', '\\s+', preg_quote( $plain, '/' ) ) . '/u';
		$replaced = preg_replace( $flexible, $resolved, $value, 1 );

		if ( is_string( $replaced ) && $replaced !== $value && ! Persian_Detector::contains_persian( $replaced ) ) {
			return $replaced;
		}

		return nl2br( esc_html( $resolved ) );
	}

	/**
	 * Normalize HTML/text widget content to a comparable plain string.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function normalize_plain_text( $value ) {
		$decoded = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$decoded = str_replace( array( '<br>', '<br/>', '<br />' ), "\n", $decoded );
		$plain   = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $decoded ) ) );

		return is_string( $plain ) ? $plain : '';
	}

	/**
	 * Skip WHB structural keys that are never customer-facing copy.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	private static function should_skip_whb_key( $key ) {
		static $blocked = array(
			'id'              => true,
			'type'            => true,
			'elType'          => true,
			'params'          => false,
			'content'         => false,
			'content_html'    => false,
			'html'            => false,
			'text'            => false,
			'title'           => false,
			'label'           => false,
			'block_id'        => true,
			'html_block_id'   => true,
			'url'             => true,
			'link'            => true,
			'icon'            => true,
			'image'           => true,
			'image_url'       => true,
			'custom_icon'     => true,
			'css'             => true,
			'background'      => true,
			'color'           => true,
			'width'           => true,
			'height'          => true,
			'align'           => true,
			'sticky'          => true,
			'hide_on_desktop' => true,
			'hide_on_mobile'  => true,
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
