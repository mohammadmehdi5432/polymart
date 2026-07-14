<?php
/**
 * Persistent catalog and per-language translations for bulk UI strings.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation\UI_String;

use PolymartAI\Translation\AI\Persian_Detector;

defined( 'ABSPATH' ) || exit;

/**
 * Class UI_String_Registry
 */
final class UI_String_Registry {

	/**
	 * Source string catalog (msgid registry from .pot scans).
	 */
	const REGISTRY_OPTION = 'polymart_ai_ui_strings';

	/**
	 * Prefix for per-language translation buckets: polymart_ai_ui_translations_{lang}.
	 */
	const TRANSLATIONS_OPTION_PREFIX = 'polymart_ai_ui_translations_';

	/**
	 * Bulk translation job state.
	 */
	const JOB_OPTION = 'polymart_ai_ui_strings_job';

	/**
	 * Registry schema version.
	 */
	const REGISTRY_VERSION = 1;

	/**
	 * Per-request memoization for gettext lookups.
	 *
	 * @var array<string, string|null>
	 */
	private static $request_lookup = array();

	/**
	 * Loaded translation buckets keyed by language code.
	 *
	 * @var array<string, array{h: array<string, string>}>|null
	 */
	private static $lang_buckets = null;

	/**
	 * Cached allowed domain list for the current request.
	 *
	 * @var string[]|null
	 */
	private static $allowed_domains_cache = null;

	/**
	 * Text domains eligible for bulk UI string translation on the storefront.
	 *
	 * @return string[]
	 */
	public static function get_allowed_domains() {
		if ( null !== self::$allowed_domains_cache ) {
			return self::$allowed_domains_cache;
		}

		/*
		 * Companion plugins commonly use their folder slug (lowercased) as the
		 * text domain. Only allowing 'polymart-ai' silently dropped every string
		 * those plugins register, both at scan time and at gettext time.
		 */
		$defaults = array(
			'polymart-ai',
			'woocommerce',
			'woodmart',
			'woodmart-core',
			'xts-theme',
		);

		foreach ( self::get_allowed_plugin_slugs() as $slug ) {
			$defaults[] = strtolower( $slug );

			$declared = UI_String_Scanner::read_plugin_text_domain( $slug );

			if ( '' !== $declared ) {
				$defaults[] = $declared;
			}
		}

		/**
		 * Filter gettext text domains processed by PolyMartAI UI string translation.
		 *
		 * @param string[] $domains Allowed text domain slugs.
		 */
		self::$allowed_domains_cache = array_values(
			array_unique(
				array_map(
					'sanitize_key',
					(array) apply_filters(
						'polymart_ai_ui_string_domains',
						$defaults
					)
				)
			)
		);

		return self::$allowed_domains_cache;
	}

	/**
	 * Plugin directory slugs whose catalogs/sources are scanned for UI strings.
	 *
	 * @return string[]
	 */
	public static function get_allowed_plugin_slugs() {
		$defaults = array(
			'woocommerce',
			'woodmart-core',
			'persian-woocommerce',
			'show-category',
			'JetCheckout',
			'Advanced-Product-Description',
			'woodmart-variable-enhancer',
			'knd-elementor-widgets',
			'custom-product-cards',
			'Customer-portal',
			'ghateino',
			'gold-tools-for-arash-final',
			'knd-panel-integration',
			'novin-delivery-time',
			'pardis-exchange-widgets',
			'sepidar-api-integration',
			'torob-pulse-connector',
			'wordpress-checkout-management',
			'z-knd',
		);

		/**
		 * Filter plugin directory slugs scanned for UI strings.
		 *
		 * @param string[] $slugs Plugin folder names under wp-content/plugins/.
		 */
		$slugs = (array) apply_filters( 'polymart_ai_ui_string_plugin_slugs', $defaults );

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $slug ) {
							$slug = trim( (string) $slug );
							$slug = str_replace( array( '..', '/', '\\', "\0" ), '', $slug );

							return trim( $slug );
						},
						$slugs
					)
				)
			)
		);
	}

	/**
	 * All plugin slugs to scan (delegates to scanner list builder).
	 *
	 * @return string[]
	 */
	public static function get_scannable_plugin_slugs() {
		return UI_String_Scanner::list_scannable_plugin_slugs();
	}

	/**
	 * Whether a gettext domain should be processed.
	 *
	 * @param string $domain Text domain.
	 * @return bool
	 */
	public static function is_allowed_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );

		if ( '' === $domain ) {
			return false;
		}

		foreach ( self::get_allowed_domains() as $allowed ) {
			if ( strtolower( (string) $allowed ) === $domain ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a stable hash for a msgid and optional context.
	 *
	 * @param string $msgid   Source string.
	 * @param string $context Optional msgctxt.
	 * @return string
	 */
	public static function string_hash( $msgid, $context = '' ) {
		return md5( (string) $context . '|' . (string) $msgid );
	}

	/**
	 * Load the string catalog.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_registry() {
		$registry = get_option( self::REGISTRY_OPTION, array() );

		if ( ! is_array( $registry ) ) {
			return self::empty_registry();
		}

		if ( ! isset( $registry['entries'] ) || ! is_array( $registry['entries'] ) ) {
			$registry['entries'] = array();
		}

		return $registry;
	}

	/**
	 * Persist the string catalog.
	 *
	 * @param array<string, mixed> $registry Registry payload.
	 * @return void
	 */
	public static function save_registry( array $registry ) {
		$registry['version']       = self::REGISTRY_VERSION;
		$registry['string_count']  = isset( $registry['entries'] ) && is_array( $registry['entries'] )
			? count( $registry['entries'] )
			: 0;
		$registry['scanned_at']    = time();
		$registry['allowed_domains'] = self::get_allowed_domains();

		update_option( self::REGISTRY_OPTION, $registry, false );
	}

	/**
	 * Default empty registry shape.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty_registry() {
		return array(
			'version'      => self::REGISTRY_VERSION,
			'scanned_at'   => 0,
			'string_count' => 0,
			'plugins'      => array(),
			'entries'      => array(),
		);
	}

	/**
	 * Lookup a pre-translated UI string for the storefront (DB only).
	 *
	 * @param string $lang    Target language code.
	 * @param string $msgid   Source msgid.
	 * @param string $context Optional msgctxt.
	 * @return string|null Translated string or null when not stored.
	 */
	public static function lookup( $lang, $msgid, $context = '' ) {
		$msgid = is_string( $msgid ) ? $msgid : '';

		if ( '' === trim( $msgid ) ) {
			return null;
		}

		$lang = sanitize_key( (string) $lang );
		$hash = self::string_hash( $msgid, $context );
		$key  = $lang . ':' . $hash;

		if ( array_key_exists( $key, self::$request_lookup ) ) {
			return self::$request_lookup[ $key ];
		}

		$bucket = self::get_lang_bucket( $lang );
		$hit    = isset( $bucket['h'][ $hash ] ) && is_string( $bucket['h'][ $hash ] ) && '' !== trim( $bucket['h'][ $hash ] )
			? $bucket['h'][ $hash ]
			: null;

		if (
			null !== $hit
			&& 'en' === $lang
			&& Persian_Detector::contains_persian( $hit )
			&& ! Persian_Detector::contains_persian( $msgid )
		) {
			$hit = null;
		}

		self::$request_lookup[ $key ] = $hit;

		return $hit;
	}

	/**
	 * Build Jed/wp.i18n locale data for a language from the UI string registry.
	 *
	 * @param string $lang Target language code.
	 * @return array<string, array<int, string>|array<string, string>>
	 */
	public static function export_locale_data( $lang, $for_script = true ) {
		$lang     = sanitize_key( (string) $lang );
		$registry = self::get_registry();
		$entries  = isset( $registry['entries'] ) && is_array( $registry['entries'] ) ? $registry['entries'] : array();
		$bucket   = self::get_lang_bucket( $lang );

		$locale = array(
			'' => array(
				'domain'       => 'polymart-ai',
				'lang'         => $lang,
				'plural-forms' => 'nplurals=2; plural=(n != 1);',
			),
		);

		if ( empty( $entries ) || empty( $bucket['h'] ) ) {
			return $locale;
		}

		$exported = 0;

		foreach ( $entries as $hash => $entry ) {
			if ( ! is_array( $entry ) || empty( $bucket['h'][ $hash ] ) || ! is_string( $bucket['h'][ $hash ] ) ) {
				continue;
			}

			$msgid = isset( $entry['msgid'] ) ? (string) $entry['msgid'] : '';

			if ( '' === trim( $msgid ) ) {
				continue;
			}

			$translation = trim( $bucket['h'][ $hash ] );

			if ( '' === $translation ) {
				continue;
			}

			if ( $for_script && ! self::is_js_locale_domain( $entry['domain'] ?? '' ) ) {
				continue;
			}

			if (
				'en' === $lang
				&& Persian_Detector::contains_persian( $translation )
				&& ! Persian_Detector::contains_persian( $msgid )
			) {
				continue;
			}

			if ( $for_script && strlen( $msgid ) > 500 ) {
				continue;
			}

			$context = isset( $entry['context'] ) ? (string) $entry['context'] : '';
			$key     = '' !== $context ? $context . "\x04" . $msgid : $msgid;

			$locale[ $key ] = array( $translation );
			++$exported;

			if ( $for_script && $exported >= 800 ) {
				break;
			}
		}

		return $locale;
	}

	/**
	 * Text domains that are passed to wp.i18n on the storefront.
	 *
	 * WooCommerce/Woodmart PHP gettext strings must not be injected into JS locale
	 * data — large payloads can break inline scripts and storefront JS.
	 *
	 * @param string $domain Entry text domain.
	 * @return bool
	 */
	private static function is_js_locale_domain( $domain ) {
		$domain = strtolower( sanitize_key( (string) $domain ) );

		if ( '' === $domain ) {
			return false;
		}

		$defaults = array(
			'polymart-ai',
			'customer-portal',
		);

		/**
		 * Filter text domains exported into wp.i18n locale data for JS bundles.
		 *
		 * @param string[] $domains Allowed JS locale domains.
		 */
		$allowed = (array) apply_filters( 'polymart_ai_js_locale_domains', $defaults );
		$allowed = array_map(
			static function ( $item ) {
				return strtolower( sanitize_key( (string) $item ) );
			},
			$allowed
		);

		return in_array( $domain, $allowed, true );
	}

	/**
	 * Store translated strings for a language bucket.
	 *
	 * @param string                $lang         Target language code.
	 * @param array<string, string> $translations Hash => translated text map.
	 * @return void
	 */
	public static function save_translations( $lang, array $translations ) {
		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang || empty( $translations ) ) {
			return;
		}

		$bucket = self::get_lang_bucket( $lang );

		foreach ( $translations as $hash => $text ) {
			$hash = (string) $hash;
			$text = is_string( $text ) ? trim( $text ) : '';

			if ( '' === $hash || '' === $text ) {
				continue;
			}

			$bucket['h'][ $hash ] = $text;
			self::$request_lookup[ $lang . ':' . $hash ] = $text;
		}

		self::persist_lang_bucket( $lang, $bucket );
	}

	/**
	 * Mark strings that cannot be bulk-translated so the job can continue.
	 *
	 * English msgids without Persian script are auto-filled as passthrough.
	 *
	 * @param string   $lang   Target language code.
	 * @param string[] $hashes Entry hashes to skip.
	 * @return void
	 */
	public static function mark_bulk_skipped( $lang, array $hashes ) {
		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang || empty( $hashes ) ) {
			return;
		}

		$bucket  = self::get_lang_bucket( $lang );
		$skipped = isset( $bucket['skipped'] ) && is_array( $bucket['skipped'] ) ? $bucket['skipped'] : array();
		$passthrough = array();

		foreach ( $hashes as $hash ) {
			$hash = (string) $hash;

			if ( '' === $hash ) {
				continue;
			}

			$skipped[ $hash ] = time();

			if ( 'en' !== $lang ) {
				continue;
			}

			$entry = self::get_entry( $hash );
			$msgid = isset( $entry['msgid'] ) ? (string) $entry['msgid'] : '';

			if ( '' !== trim( $msgid ) && ! Persian_Detector::contains_persian( $msgid ) ) {
				$passthrough[ $hash ] = $msgid;
			}
		}

		$bucket['skipped'] = $skipped;

		if ( ! empty( $passthrough ) ) {
			foreach ( $passthrough as $hash => $text ) {
				$bucket['h'][ $hash ] = $text;
				self::$request_lookup[ $lang . ':' . $hash ] = $text;
			}
		}

		self::persist_lang_bucket( $lang, $bucket );
	}

	/**
	 * Count registry entries missing a translation for a language.
	 *
	 * @param string $lang Target language code.
	 * @return int
	 */
	public static function count_untranslated( $lang ) {
		$lang     = sanitize_key( (string) $lang );
		$registry = self::get_registry();
		$entries  = isset( $registry['entries'] ) && is_array( $registry['entries'] ) ? $registry['entries'] : array();

		if ( empty( $entries ) ) {
			return 0;
		}

		$bucket  = self::get_lang_bucket( $lang );
		$skipped = isset( $bucket['skipped'] ) && is_array( $bucket['skipped'] ) ? $bucket['skipped'] : array();
		$count   = 0;

		foreach ( array_keys( $entries ) as $hash ) {
			if ( empty( $bucket['h'][ $hash ] ) && ! isset( $skipped[ $hash ] ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Count entries that already have a stored translation.
	 *
	 * @param string $lang Target language code.
	 * @return int
	 */
	public static function count_translated( $lang ) {
		$lang     = sanitize_key( (string) $lang );
		$registry = self::get_registry();
		$entries  = isset( $registry['entries'] ) && is_array( $registry['entries'] ) ? $registry['entries'] : array();

		if ( empty( $entries ) ) {
			return 0;
		}

		$bucket = self::get_lang_bucket( $lang );
		$count  = 0;

		foreach ( array_keys( $entries ) as $hash ) {
			if ( ! empty( $bucket['h'][ $hash ] ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Collect untranslated entry hashes in catalog order.
	 *
	 * @param string $lang   Target language code.
	 * @param int    $limit  Maximum hashes to return.
	 * @param string $after  Resume after this hash (exclusive).
	 * @return string[]
	 */
	public static function collect_untranslated_hashes( $lang, $limit = 20, $after = '' ) {
		$lang  = sanitize_key( (string) $lang );
		$limit = max( 1, absint( $limit ) );
		$after = (string) $after;

		$results = self::collect_untranslated_hashes_after( $lang, $limit, $after );

		if ( empty( $results ) && '' !== $after && self::count_untranslated( $lang ) > 0 ) {
			$results = self::collect_untranslated_hashes_after( $lang, $limit, '' );
		}

		return $results;
	}

	/**
	 * Collect untranslated hashes after an optional cursor hash.
	 *
	 * @param string $lang   Target language code.
	 * @param int    $limit  Maximum hashes to return.
	 * @param string $after  Resume after this hash (exclusive).
	 * @return string[]
	 */
	private static function collect_untranslated_hashes_after( $lang, $limit, $after ) {
		$after    = (string) $after;
		$registry = self::get_registry();
		$entries  = isset( $registry['entries'] ) && is_array( $registry['entries'] ) ? $registry['entries'] : array();
		$bucket   = self::get_lang_bucket( $lang );
		$hashes   = array_keys( $entries );
		sort( $hashes, SORT_STRING );

		$found_after = '' === $after;
		$results     = array();

		$skipped = isset( $bucket['skipped'] ) && is_array( $bucket['skipped'] ) ? $bucket['skipped'] : array();

		foreach ( $hashes as $hash ) {
			if ( ! $found_after ) {
				if ( $hash === $after ) {
					$found_after = true;
				}
				continue;
			}

			if ( ! empty( $bucket['h'][ $hash ] ) || isset( $skipped[ $hash ] ) ) {
				continue;
			}

			$results[] = $hash;

			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Resolve registry entry data for a hash.
	 *
	 * @param string $hash Entry hash.
	 * @return array<string, mixed>|null
	 */
	public static function get_entry( $hash ) {
		$registry = self::get_registry();
		$entries  = isset( $registry['entries'] ) && is_array( $registry['entries'] ) ? $registry['entries'] : array();

		return isset( $entries[ $hash ] ) && is_array( $entries[ $hash ] ) ? $entries[ $hash ] : null;
	}

	/**
	 * Summary payload for admin REST responses.
	 *
	 * @param string $lang Optional language filter for counts.
	 * @return array<string, mixed>
	 */
	public static function get_stats( $lang = 'en', $include_job = true ) {
		$lang     = sanitize_key( (string) $lang );
		$registry = self::get_registry();

		$stats = array(
			'string_count'    => (int) ( $registry['string_count'] ?? 0 ),
			'scanned_at'      => (int) ( $registry['scanned_at'] ?? 0 ),
			'plugins'         => isset( $registry['plugins'] ) && is_array( $registry['plugins'] ) ? $registry['plugins'] : array(),
			'allowed_domains' => self::get_allowed_domains(),
			'plugin_slugs'    => self::get_allowed_plugin_slugs(),
			'translated'      => self::count_translated( $lang ),
			'untranslated'    => self::count_untranslated( $lang ),
		);

		if ( $include_job ) {
			$stats['job'] = UI_String_Bulk_Job::get_job();
		}

		return $stats;
	}

	/**
	 * Load or return the in-memory translation bucket for a language.
	 *
	 * @param string $lang Target language code.
	 * @return array{h: array<string, string>}
	 */
	private static function get_lang_bucket( $lang ) {
		if ( null === self::$lang_buckets ) {
			self::$lang_buckets = array();
		}

		if ( isset( self::$lang_buckets[ $lang ] ) ) {
			return self::$lang_buckets[ $lang ];
		}

		$raw = get_option( self::TRANSLATIONS_OPTION_PREFIX . $lang, array() );

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$hash_map = isset( $raw['h'] ) && is_array( $raw['h'] ) ? $raw['h'] : array();
		$skipped  = isset( $raw['skipped'] ) && is_array( $raw['skipped'] ) ? $raw['skipped'] : array();

		self::$lang_buckets[ $lang ] = array(
			'h'       => $hash_map,
			'skipped' => $skipped,
		);

		return self::$lang_buckets[ $lang ];
	}

	/**
	 * Persist a language translation bucket to the database.
	 *
	 * @param string               $lang   Target language code.
	 * @param array<string, mixed> $bucket Bucket payload.
	 * @return void
	 */
	private static function persist_lang_bucket( $lang, array $bucket ) {
		if ( count( $bucket['h'] ) > 15000 ) {
			$bucket['h'] = array_slice( $bucket['h'], -15000, null, true );
		}

		self::$lang_buckets[ $lang ] = $bucket;
		update_option( self::TRANSLATIONS_OPTION_PREFIX . $lang, $bucket, false );
	}
}
