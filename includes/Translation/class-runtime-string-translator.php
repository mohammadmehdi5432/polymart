<?php
/**
 * On-demand string translation with persistent per-language cache.
 *
 * Storefront requests use cache only — never block page render on live AI calls.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Class Runtime_String_Translator
 */
final class Runtime_String_Translator {

	/**
	 * Option prefix for cached translations.
	 */
	const CACHE_OPTION_PREFIX = 'polymart_ai_strings_';

	/**
	 * Option holding strings awaiting background AI translation.
	 */
	const PENDING_OPTION = 'polymart_ai_pending_strings';

	/**
	 * WP-Cron hook that drains the pending string queue.
	 */
	const PENDING_CRON_HOOK = 'polymart_ai_translate_pending_strings';

	/**
	 * Maximum queued entries kept in the pending option.
	 */
	const MAX_PENDING_ENTRIES = 300;

	/**
	 * Strings translated per background cron run.
	 */
	const PENDING_BATCH_SIZE = 25;

	/**
	 * Attempts before a failing entry is dropped from the queue.
	 */
	const MAX_PENDING_ATTEMPTS = 3;

	/**
	 * Maximum cached entries per language bucket.
	 */
	const MAX_CACHE_ENTRIES = 2500;

	/**
	 * Maximum legacy dictionary entries kept in wp_options.
	 */
	const MAX_LEGACY_DICTIONARY_ENTRIES = 400;

	/**
	 * Reject or trim language cache options larger than this (bytes).
	 */
	const MAX_OPTION_BYTES = 524288;

	/**
	 * Per-request memoization.
	 *
	 * @var array<string, string>
	 */
	private static $request_cache = array();

	/**
	 * Loaded persistent buckets keyed by language code.
	 *
	 * @var array<string, array{h?: array<string, string>}>
	 */
	private static $lang_buckets = array();

	/**
	 * Seed dictionary loaded once per request.
	 *
	 * @var array<string, string>|null
	 */
	private static $seed_dictionary = null;

	/**
	 * Language buckets changed during this request and needing persistence.
	 *
	 * @var array<string, bool>
	 */
	private static $dirty_langs = array();

	/**
	 * When true, avoid database reads/writes (e.g. inside option bootstrap).
	 *
	 * @var bool
	 */
	private static $options_locked = false;

	/**
	 * Cached decision for live AI calls during the current request.
	 *
	 * @var bool|null
	 */
	private static $allow_runtime_ai = null;

	/**
	 * Whether the shutdown flush hook is registered.
	 *
	 * @var bool
	 */
	private static $shutdown_registered = false;

	/**
	 * Loaded theme string map for the current request.
	 *
	 * @var array<string, string>|null
	 */
	private static $theme_strings = null;

	/**
	 * Strings queued for background translation during this request.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static $pending_new = array();

	/**
	 * Lock or unlock option persistence while WordPress is loading autoloaded options.
	 *
	 * @param bool $locked Whether option I/O should be skipped.
	 * @return void
	 */
	public static function set_options_locked( $locked ) {
		self::$options_locked = (bool) $locked;
	}

	/**
	 * Trim bloated runtime caches once per hour (prevents 256MB+ option loads).
	 *
	 * @return void
	 */
	public static function maybe_prune_on_boot() {
		if ( get_transient( 'polymart_ai_cache_pruned_v2' ) ) {
			return;
		}

		self::prune_oversized_storage();
		set_transient( 'polymart_ai_cache_pruned_v2', 1, HOUR_IN_SECONDS );
	}

	/**
	 * Delete or truncate oversized Polymart string cache options.
	 *
	 * @return void
	 */
	public static function prune_oversized_storage() {
		global $wpdb;

		if ( ! isset( $wpdb->options ) ) {
			return;
		}

		$like = $wpdb->esc_like( self::CACHE_OPTION_PREFIX ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! isset( $row->option_name ) ) {
					continue;
				}

				$raw = (string) ( $row->option_value ?? '' );

				if ( strlen( $raw ) <= self::MAX_OPTION_BYTES ) {
					continue;
				}

				$decoded = maybe_unserialize( $raw );

				if ( ! is_array( $decoded ) ) {
					delete_option( $row->option_name );
					continue;
				}

				$decoded = self::normalize_bucket( $decoded );
				update_option( $row->option_name, $decoded, false );
			}
		}

		$legacy = get_option( 'polymart_ai_dynamic_dictionary', array() );

		if ( is_array( $legacy ) && count( $legacy ) > self::MAX_LEGACY_DICTIONARY_ENTRIES ) {
			update_option(
				'polymart_ai_dynamic_dictionary',
				array_slice( $legacy, -1 * self::MAX_LEGACY_DICTIONARY_ENTRIES, null, true ),
				false
			);
		}
	}

	/**
	 * Translate a Persian UI string for the storefront.
	 *
	 * @param string $text    Source text.
	 * @param string $lang    Target language code.
	 * @param string $context Optional context key for disambiguation.
	 * @return string
	 */
	public static function translate( $text, $lang, $context = '' ) {
		self::register_shutdown_hook();

		$text = is_string( $text ) ? trim( $text ) : '';

		if ( '' === $text || ! Persian_Detector::contains_persian( $text ) ) {
			return $text;
		}

		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			$lang = 'en';
		}

		$hash = self::cache_hash( $text, $context );
		$key  = $lang . ':' . $hash;

		if ( isset( self::$request_cache[ $key ] ) ) {
			return self::$request_cache[ $key ];
		}

		if ( self::$options_locked ) {
			self::$request_cache[ $key ] = $text;

			return $text;
		}

		$dictionary = self::get_seed_dictionary();

		if ( isset( $dictionary[ $text ] ) && '' !== trim( (string) $dictionary[ $text ] ) ) {
			self::$request_cache[ $key ] = (string) $dictionary[ $text ];

			return self::$request_cache[ $key ];
		}

		// Prefer bulk UI-string translations when the exact msgid was catalogued.
		$from_registry = UI_String_Registry::lookup( $lang, $text, '' );

		if ( null !== $from_registry ) {
			self::$request_cache[ $key ] = $from_registry;

			return $from_registry;
		}

		$cached = self::get_persistent_entry( $lang, $hash, $text );

		if ( null !== $cached ) {
			self::$request_cache[ $key ] = $cached;

			return $cached;
		}

		if ( ! self::allows_runtime_ai() ) {
			// Never block storefront rendering — queue the string so a
			// background cron job translates it and the next visit is served
			// from cache. Without this, uncached strings never reach the AI.
			self::queue_pending( $lang, $text, $context );
			self::$request_cache[ $key ] = $text;

			return $text;
		}

		$settings = Post_Translator::get_translation_settings();

		if ( empty( $settings['api_key'] ) || empty( $settings['api_endpoint'] ) ) {
			self::$request_cache[ $key ] = $text;

			return $text;
		}

		$api_key      = (string) $settings['api_key'];
		$api_endpoint = rtrim( (string) $settings['api_endpoint'], '/' );
		$ai_model     = AI_Client::resolve_model(
			(string) ( $settings['ai_model'] ?? '' ),
			$api_endpoint
		);

		$payload_key = 'text';

		if ( '' !== $context ) {
			$payload_key = 'text_' . substr( md5( (string) $context ), 0, 8 );
		}

		$result = AI_Client::send_translation_request(
			array( $payload_key => $text ),
			$api_key,
			$api_endpoint,
			$ai_model,
			$lang
		);

		if ( is_wp_error( $result ) || empty( $result[ $payload_key ] ) ) {
			self::$request_cache[ $key ] = $text;

			return $text;
		}

		$translated = trim( (string) $result[ $payload_key ] );

		if ( '' === $translated ) {
			self::$request_cache[ $key ] = $text;

			return $text;
		}

		self::remember( $lang, $hash, $translated );
		self::$request_cache[ $key ] = $translated;

		return $translated;
	}

	/**
	 * Build a stable cache hash for a string and optional context.
	 *
	 * @param string $text    Source text.
	 * @param string $context Context key.
	 * @return string
	 */
	public static function cache_hash( $text, $context = '' ) {
		return md5( sanitize_key( (string) $context ) . '|' . $text );
	}

	/**
	 * Remove one cached runtime translation (e.g. after source text changes).
	 *
	 * @param string $text    Original source text.
	 * @param string $lang    Target language code.
	 * @param string $context Storage context key.
	 * @return void
	 */
	public static function forget_translation( $text, $lang, $context = '' ) {
		$text = is_string( $text ) ? trim( $text ) : '';
		$lang = sanitize_key( (string) $lang );

		if ( '' === $text || '' === $lang || self::$options_locked ) {
			return;
		}

		$hash = self::cache_hash( $text, $context );
		$key  = $lang . ':' . $hash;

		unset( self::$request_cache[ $key ] );

		$cache = self::get_lang_bucket( $lang );

		if ( ! isset( $cache['h'][ $hash ] ) ) {
			return;
		}

		unset( $cache['h'][ $hash ] );
		self::$lang_buckets[ $lang ] = $cache;
		self::$dirty_langs[ $lang ]   = true;
		self::register_shutdown_hook();
	}

	/**
	 * Drop runtime cache rows for one taxonomy term (name + description).
	 *
	 * @param int         $term_id Term ID.
	 * @param string|null $name    Optional previous name to forget.
	 * @param string|null $desc    Optional previous description to forget.
	 * @return void
	 */
	public static function invalidate_term_runtime_cache( $term_id, $name = null, $desc = null ) {
		$term_id = absint( $term_id );

		if ( $term_id <= 0 ) {
			return;
		}

		foreach ( \PolymartAI\Language_Registry::get_translation_target_languages() as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $lang ) {
				continue;
			}

			if ( is_string( $name ) && '' !== trim( $name ) ) {
				self::forget_translation( $name, $lang, 'wc_term:' . $term_id . ':name' );
			}

			if ( is_string( $desc ) && '' !== trim( $desc ) ) {
				self::forget_translation( $desc, $lang, 'wc_term:' . $term_id . ':desc' );
			}
		}
	}

	/**
	 * Persist one translated storefront string for later cache-only lookups.
	 *
	 * @param string $source      Original Persian text.
	 * @param string $lang        Target language code.
	 * @param string $context     Storage context key.
	 * @param string $translation Translated text.
	 * @return void
	 */
	public static function store_translation( $source, $lang, $context, $translation ) {
		self::register_shutdown_hook();

		$source      = is_string( $source ) ? trim( $source ) : '';
		$translation = is_string( $translation ) ? trim( $translation ) : '';
		$lang        = sanitize_key( (string) $lang );

		if ( '' === $source || '' === $translation || '' === $lang || $source === $translation ) {
			return;
		}

		$hash = self::cache_hash( $source, $context );
		$key  = $lang . ':' . $hash;

		self::$request_cache[ $key ] = $translation;
		self::remember( $lang, $hash, $translation );
	}

	/**
	 * Whether a storefront string is already cached for a language/context.
	 *
	 * @param string $text    Source text.
	 * @param string $lang    Target language code.
	 * @param string $context Storage context key.
	 * @return bool
	 */
	public static function has_cached_translation( $text, $lang, $context = '' ) {
		$text = is_string( $text ) ? trim( $text ) : '';
		$lang = sanitize_key( (string) $lang );

		if ( '' === $text || '' === $lang ) {
			return false;
		}

		$hash        = self::cache_hash( $text, $context );
		$request_key = $lang . ':' . $hash;

		if ( isset( self::$request_cache[ $request_key ] ) && is_string( self::$request_cache[ $request_key ] ) && '' !== trim( self::$request_cache[ $request_key ] ) ) {
			return true;
		}

		$cached = self::get_persistent_entry( $lang, $hash, $text );

		return is_string( $cached ) && '' !== trim( $cached );
	}

	/**
	 * Read a cached translation without queueing AI or touching the runtime API.
	 *
	 * @param string $text    Source text.
	 * @param string $lang    Target language code.
	 * @param string $context Storage context key.
	 * @return string|null
	 */
	public static function lookup_cached( $text, $lang, $context = '' ) {
		$text = is_string( $text ) ? trim( $text ) : '';
		$lang = sanitize_key( (string) $lang );

		if ( '' === $text || '' === $lang ) {
			return null;
		}

		$hash = self::cache_hash( $text, $context );
		$key  = $lang . ':' . $hash;

		if ( isset( self::$request_cache[ $key ] ) && is_string( self::$request_cache[ $key ] ) && '' !== trim( self::$request_cache[ $key ] ) ) {
			return self::$request_cache[ $key ];
		}

		$cached = self::get_persistent_entry( $lang, $hash, $text );

		return is_string( $cached ) && '' !== trim( $cached ) ? $cached : null;
	}

	/**
	 * Persist pending cache writes at the end of the request.
	 *
	 * @return void
	 */
	public static function flush_pending_cache() {
		if ( self::$options_locked ) {
			return;
		}

		// Public storefront renders must not write large option blobs during shutdown.
		// REST/AJAX admin jobs (auto-translate) must persist or the next step re-picks the same post.
		if ( ! self::allows_cache_persistence() ) {
			self::$dirty_langs = array();
			self::$pending_new = array();

			return;
		}

		foreach ( array_keys( self::$dirty_langs ) as $lang ) {
			if ( ! isset( self::$lang_buckets[ $lang ] ) ) {
				continue;
			}

			update_option( self::CACHE_OPTION_PREFIX . $lang, self::$lang_buckets[ $lang ], false );
		}

		self::$dirty_langs = array();

		self::persist_pending_queue();
	}

	/**
	 * Queue an untranslated storefront string for background AI translation.
	 *
	 * @param string $lang    Target language code.
	 * @param string $text    Source text.
	 * @param string $context Optional context key.
	 * @return void
	 */
	private static function queue_pending( $lang, $text, $context ) {
		if ( self::$options_locked || '' === $text ) {
			return;
		}

		// Never persist or spawn cron during public page renders — cache-only mode
		// may hit thousands of strings per request and exhaust memory at shutdown.
		if ( ! is_admin() && ! wp_doing_cron() && ! self::allows_runtime_ai() ) {
			return;
		}

		if ( strlen( $text ) > 4000 ) {
			return;
		}

		if ( count( self::$pending_new ) >= self::MAX_PENDING_ENTRIES ) {
			return;
		}

		$hash = self::cache_hash( $text, $context );
		$key  = $lang . ':' . $hash;

		if ( isset( self::$pending_new[ $key ] ) ) {
			return;
		}

		self::$pending_new[ $key ] = array(
			'lang'    => (string) $lang,
			'text'    => (string) $text,
			'context' => (string) $context,
		);

		self::register_shutdown_hook();
	}

	/**
	 * Merge newly queued strings into the pending option and schedule the worker.
	 *
	 * @return void
	 */
	private static function persist_pending_queue() {
		if ( empty( self::$pending_new ) || self::$options_locked ) {
			return;
		}

		$queue = get_option( self::PENDING_OPTION, array() );

		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		$changed = false;

		foreach ( self::$pending_new as $key => $entry ) {
			if ( isset( $queue[ $key ] ) ) {
				continue;
			}

			$entry['attempts'] = 0;
			$queue[ $key ]     = $entry;
			$changed           = true;
		}

		self::$pending_new = array();

		if ( ! $changed ) {
			return;
		}

		if ( count( $queue ) > self::MAX_PENDING_ENTRIES ) {
			$queue = array_slice( $queue, -1 * self::MAX_PENDING_ENTRIES, null, true );
		}

		update_option( self::PENDING_OPTION, $queue, false );

		if ( ! wp_next_scheduled( self::PENDING_CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::PENDING_CRON_HOOK );
		}
	}

	/**
	 * Number of storefront strings waiting in the pending translation queue.
	 *
	 * @return int
	 */
	public static function get_pending_queue_count() {
		if ( self::$options_locked ) {
			return 0;
		}

		$queue = get_option( self::PENDING_OPTION, array() );

		return is_array( $queue ) ? count( $queue ) : 0;
	}

	/**
	 * Cron worker: translate a batch of queued storefront strings via AI.
	 *
	 * @return void
	 */
	public static function process_pending_queue() {
		$queue = get_option( self::PENDING_OPTION, array() );

		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}

		$settings = Post_Translator::get_translation_settings();

		if ( empty( $settings['api_key'] ) || empty( $settings['api_endpoint'] ) ) {
			return;
		}

		$api_key      = (string) $settings['api_key'];
		$api_endpoint = rtrim( (string) $settings['api_endpoint'], '/' );
		$ai_model     = AI_Client::resolve_model(
			(string) ( $settings['ai_model'] ?? '' ),
			$api_endpoint
		);

		// Group one batch per language so a single prompt targets one language.
		$batches = array();

		foreach ( $queue as $key => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['text'] ) || empty( $entry['lang'] ) ) {
				unset( $queue[ $key ] );
				continue;
			}

			$lang = (string) $entry['lang'];

			if ( ! isset( $batches[ $lang ] ) ) {
				$batches[ $lang ] = array();
			}

			if ( count( $batches[ $lang ] ) < self::PENDING_BATCH_SIZE ) {
				$batches[ $lang ][ $key ] = $entry;
			}
		}

		foreach ( $batches as $lang => $entries ) {
			$payload = array();

			foreach ( $entries as $key => $entry ) {
				$payload[ $key ] = (string) $entry['text'];
			}

			if ( empty( $payload ) ) {
				continue;
			}

			$result = AI_Client::translate_fields( $payload, $api_key, $api_endpoint, $ai_model, $lang );

			if ( is_wp_error( $result ) ) {
				foreach ( array_keys( $entries ) as $key ) {
					$queue[ $key ]['attempts'] = (int) ( $queue[ $key ]['attempts'] ?? 0 ) + 1;

					if ( $queue[ $key ]['attempts'] >= self::MAX_PENDING_ATTEMPTS ) {
						unset( $queue[ $key ] );
					}
				}
				continue;
			}

			foreach ( $entries as $key => $entry ) {
				$translated = isset( $result[ $key ] ) ? trim( (string) $result[ $key ] ) : '';

				if ( '' !== $translated ) {
					self::remember(
						$lang,
						self::cache_hash( (string) $entry['text'], (string) $entry['context'] ),
						$translated
					);
					unset( $queue[ $key ] );
					continue;
				}

				$queue[ $key ]['attempts'] = (int) ( $queue[ $key ]['attempts'] ?? 0 ) + 1;

				if ( $queue[ $key ]['attempts'] >= self::MAX_PENDING_ATTEMPTS ) {
					unset( $queue[ $key ] );
				}
			}
		}

		// Persist translated buckets immediately (cron has no later shutdown guarantee).
		foreach ( array_keys( self::$dirty_langs ) as $dirty_lang ) {
			if ( isset( self::$lang_buckets[ $dirty_lang ] ) ) {
				update_option( self::CACHE_OPTION_PREFIX . $dirty_lang, self::$lang_buckets[ $dirty_lang ], false );
			}
		}

		self::$dirty_langs = array();

		if ( empty( $queue ) ) {
			delete_option( self::PENDING_OPTION );

			return;
		}

		update_option( self::PENDING_OPTION, $queue, false );

		if ( ! wp_next_scheduled( self::PENDING_CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::PENDING_CRON_HOOK );
		}
	}

	/**
	 * Read a cached translation entry from the in-memory bucket.
	 *
	 * @param string $lang Target language.
	 * @param string $hash Entry hash.
	 * @param string $text Original text for legacy source-key lookup.
	 * @return string|null
	 */
	private static function get_persistent_entry( $lang, $hash, $text ) {
		$cache = self::get_lang_bucket( $lang );

		if ( isset( $cache['h'][ $hash ] ) && is_string( $cache['h'][ $hash ] ) ) {
			return $cache['h'][ $hash ];
		}

		// Legacy buckets stored a secondary source hash map.
		$source_key = 's:' . md5( $text );

		if ( isset( $cache['s'][ $source_key ] ) && is_string( $cache['s'][ $source_key ] ) ) {
			return $cache['s'][ $source_key ];
		}

		return null;
	}

	/**
	 * Queue a translated string for batched persistence.
	 *
	 * @param string $lang       Target language.
	 * @param string $hash       Cache hash.
	 * @param string $translated Translated text.
	 * @return void
	 */
	private static function remember( $lang, $hash, $translated ) {
		if ( self::$options_locked ) {
			return;
		}

		$cache = self::get_lang_bucket( $lang );

		if ( ! isset( $cache['h'] ) || ! is_array( $cache['h'] ) ) {
			$cache['h'] = array();
		}

		$cache['h'][ $hash ] = $translated;

		if ( count( $cache['h'] ) > self::MAX_CACHE_ENTRIES ) {
			$cache['h'] = array_slice( $cache['h'], -1 * self::MAX_CACHE_ENTRIES, null, true );
		}

		unset( $cache['s'] );

		self::$lang_buckets[ $lang ] = $cache;
		self::$dirty_langs[ $lang ]   = true;
	}

	/**
	 * Load or return the in-memory cache bucket for a language.
	 *
	 * @param string $lang Target language.
	 * @return array{h?: array<string, string>, s?: array<string, string>}
	 */
	private static function get_lang_bucket( $lang ) {
		if ( isset( self::$lang_buckets[ $lang ] ) ) {
			return self::$lang_buckets[ $lang ];
		}

		if ( self::$options_locked ) {
			self::$lang_buckets[ $lang ] = array(
				'h' => array(),
			);

			return self::$lang_buckets[ $lang ];
		}

		$option_name = self::CACHE_OPTION_PREFIX . $lang;
		$raw_size    = self::get_option_byte_length( $option_name );

		if ( $raw_size > self::MAX_OPTION_BYTES ) {
			delete_option( $option_name );
			self::$lang_buckets[ $lang ] = array(
				'h' => array(),
			);

			return self::$lang_buckets[ $lang ];
		}

		$cache = get_option( $option_name, array() );

		if ( ! is_array( $cache ) ) {
			$cache = array();
		}

		self::$lang_buckets[ $lang ] = self::normalize_bucket( $cache );

		return self::$lang_buckets[ $lang ];
	}

	/**
	 * Normalize a cache bucket and drop legacy duplicate indexes on read.
	 *
	 * @param array<string, mixed> $cache Raw bucket.
	 * @return array{h: array<string, string>}
	 */
	private static function normalize_bucket( array $cache ) {
		$hash_map = isset( $cache['h'] ) && is_array( $cache['h'] ) ? $cache['h'] : array();

		if ( count( $hash_map ) > self::MAX_CACHE_ENTRIES ) {
			$hash_map = array_slice( $hash_map, -1 * self::MAX_CACHE_ENTRIES, null, true );
		}

		return array(
			'h' => $hash_map,
		);
	}

	/**
	 * Approximate stored option size without fully unserializing large blobs.
	 *
	 * @param string $option_name Option name.
	 * @return int
	 */
	private static function get_option_byte_length( $option_name ) {
		global $wpdb;

		if ( ! isset( $wpdb->options ) ) {
			return 0;
		}

		$length = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT LENGTH(option_value) FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			)
		);

		return max( 0, (int) $length );
	}

	/**
	 * Optional seed dictionary for instant hits before cache lookup.
	 *
	 * @return array<string, string>
	 */
	private static function get_seed_dictionary() {
		if ( self::$options_locked ) {
			return array();
		}

		if ( null !== self::$seed_dictionary ) {
			return self::$seed_dictionary;
		}

		if ( self::get_option_byte_length( 'polymart_ai_dynamic_dictionary' ) > self::MAX_OPTION_BYTES ) {
			self::$seed_dictionary = array();

			return self::$seed_dictionary;
		}

		$dynamic = get_option( 'polymart_ai_dynamic_dictionary', array() );

		if ( ! is_array( $dynamic ) ) {
			$dynamic = array();
		}

		if ( count( $dynamic ) > self::MAX_LEGACY_DICTIONARY_ENTRIES ) {
			$dynamic = array_slice( $dynamic, -1 * self::MAX_LEGACY_DICTIONARY_ENTRIES, null, true );
		}

		/**
		 * Filter seed strings merged before cache lookup.
		 *
		 * @param array<string, string> $dictionary Source => translated map.
		 */
		self::$seed_dictionary = apply_filters( 'polymart_ai_string_dictionary', $dynamic );

		return self::$seed_dictionary;
	}

	/**
	 * Whether storefront rendering should avoid live AI and heavy uncached work.
	 *
	 * @return bool
	 */
	public static function is_cache_only_mode() {
		return ! self::allows_runtime_ai();
	}

	/**
	 * Load manually stored theme/Woo string translations once per request.
	 *
	 * @return array<string, string>
	 */
	public static function get_theme_strings() {
		if ( null !== self::$theme_strings ) {
			return self::$theme_strings;
		}

		if ( self::$options_locked ) {
			self::$theme_strings = array();

			return self::$theme_strings;
		}

		$strings = get_option( 'polymart_ai_theme_strings', array() );

		self::$theme_strings = is_array( $strings ) ? $strings : array();

		return self::$theme_strings;
	}

	/**
	 * Whether uncached strings may trigger a live AI request in this request.
	 *
	 * @return bool
	 */
	private static function allows_runtime_ai() {
		if ( null !== self::$allow_runtime_ai ) {
			return self::$allow_runtime_ai;
		}

		$default = is_admin() || wp_doing_cron();

		if ( wp_doing_ajax() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only action hint.
			$action  = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			$default = 'polymart_generate_translation' === $action;
		}

		/**
		 * Filter whether missing runtime strings may call the AI API during this request.
		 *
		 * Default is false on the public storefront so page loads stay fast.
		 *
		 * @param bool $allowed Whether live AI calls are allowed.
		 */
		self::$allow_runtime_ai = (bool) apply_filters( 'polymart_ai_allow_runtime_ai_translation', $default );

		return self::$allow_runtime_ai;
	}

	/**
	 * Whether this request may write the persistent string cache.
	 *
	 * Auto-translate runs over the REST API where is_admin() is false; without this,
	 * attribute translations stay in request memory and the same product is re-queued.
	 *
	 * @return bool
	 */
	private static function allows_cache_persistence() {
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		return self::allows_runtime_ai();
	}

	/**
	 * Register a single shutdown handler for batched cache writes.
	 *
	 * @return void
	 */
	private static function register_shutdown_hook() {
		if ( self::$shutdown_registered ) {
			return;
		}

		if ( ! self::allows_cache_persistence() ) {
			return;
		}

		self::$shutdown_registered = true;
		add_action( 'shutdown', array( __CLASS__, 'flush_pending_cache' ), 0 );
	}
}
