<?php
/**
 * Bulk translation job for UI string registry entries.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation\UI_String;

use PolymartAI\Translation\AI\AI_Client;
use PolymartAI\Translation\Post_Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Class UI_String_Bulk_Job
 */
final class UI_String_Bulk_Job {

	/**
	 * Strings per AI request.
	 */
	const BATCH_SIZE = 3;

	/**
	 * Skip msgids longer than this in bulk (runtime gettext handles them).
	 */
	const MAX_MSGID_CHARS = 600;

	/**
	 * Maximum combined msgid length per batch.
	 */
	const MAX_BATCH_CHARS = 1500;

	/**
	 * Load persisted job state.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_job() {
		$job = get_option( UI_String_Registry::JOB_OPTION, array() );

		return is_array( $job ) ? self::normalize_job( $job ) : self::empty_job();
	}

	/**
	 * Start a new bulk UI string translation job.
	 *
	 * @param string $lang Target language code.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function start_job( $lang ) {
		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			return new \WP_Error(
				'polymart_ai_invalid_lang',
				__( 'زبان هدف نامعتبر است.', 'polymart-ai' )
			);
		}

		$credentials = self::resolve_ai_credentials();

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$existing = self::get_job();

		if ( 'running' === ( $existing['status'] ?? '' ) && sanitize_key( (string) ( $existing['lang'] ?? '' ) ) === $lang ) {
			$existing['last_error'] = '';
			$existing['updated_at'] = time();
			self::save_job( $existing );

			return self::normalize_job( $existing );
		}

		if ( 'paused' === ( $existing['status'] ?? '' ) && sanitize_key( (string) ( $existing['lang'] ?? '' ) ) === $lang ) {
			return self::resume_job();
		}

		$remaining = UI_String_Registry::count_untranslated( $lang );

		if ( $remaining <= 0 ) {
			return new \WP_Error(
				'polymart_ai_nothing_to_translate',
				__( 'همه رشته‌های UI قبلاً ترجمه شده‌اند.', 'polymart-ai' )
			);
		}

		$job = array(
			'status'        => 'running',
			'lang'          => $lang,
			'total'         => $remaining,
			'initial_total' => $remaining,
			'run_done'      => 0,
			'succeeded'     => 0,
			'failed'        => 0,
			'skipped'       => 0,
			'remaining'     => $remaining,
			'last_hash'     => '',
			'started_at'    => time(),
			'updated_at'    => time(),
			'last_error'    => '',
		);

		self::save_job( $job );

		return self::normalize_job( $job );
	}

	/**
	 * Pause the active job.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function pause_job() {
		$job = self::get_job();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return new \WP_Error(
				'polymart_ai_job_not_running',
				__( 'هیچ ترجمه گروهی فعالی برای توقف وجود ندارد.', 'polymart-ai' )
			);
		}

		$job['status']     = 'paused';
		$job['updated_at'] = time();
		self::save_job( $job );

		return self::normalize_job( $job );
	}

	/**
	 * Resume a paused job.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function resume_job() {
		$job = self::get_job();

		if ( 'paused' !== ( $job['status'] ?? '' ) ) {
			return new \WP_Error(
				'polymart_ai_job_not_paused',
				__( 'هیچ ترجمه گروهی متوقف‌شده‌ای برای ادامه وجود ندارد.', 'polymart-ai' )
			);
		}

		$lang = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

		$job['status']     = 'running';
		$job['remaining']  = UI_String_Registry::count_untranslated( $lang );
		$job['total']      = max( (int) ( $job['total'] ?? 0 ), (int) $job['remaining'] );
		$job['last_error'] = '';
		$job['updated_at'] = time();
		self::save_job( $job );

		return self::normalize_job( $job );
	}

	/**
	 * Stop and clear the active job.
	 *
	 * @return array<string, mixed>
	 */
	public static function stop_job() {
		$job = self::empty_job();
		self::save_job( $job );

		return $job;
	}

	/**
	 * Process one batch of UI strings.
	 *
	 * Returns job state even when a batch fails (paused + last_error) so the
	 * admin UI can resume instead of aborting on HTTP 400.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function process_step() {
		// Increase timeout for bulk translation steps
		set_time_limit( 180 );

		$job = self::ensure_running_job();

		if ( is_wp_error( $job ) ) {
			return self::normalize_job(
				array_merge(
					self::empty_job(),
					array(
						'last_error'  => $job->get_error_message(),
						'updated_at'  => time(),
						'batch_saved' => 0,
					)
				)
			);
		}

		$lang = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

		if ( '' === $lang ) {
			$job['status']     = 'failed';
			$job['last_error'] = __( 'زبان هدف در job نامعتبر است.', 'polymart-ai' );
			self::save_job( $job );

			return self::normalize_job( $job );
		}

		$credentials = self::resolve_ai_credentials();

		if ( is_wp_error( $credentials ) ) {
			$job['status']     = 'paused';
			$job['last_error'] = $credentials->get_error_message();
			$job['updated_at'] = time();
			self::save_job( $job );

			return self::normalize_job( $job );
		}

		$api_key  = $credentials['api_key'];
		$endpoint = $credentials['api_endpoint'];
		$model    = $credentials['ai_model'];

		$hashes = UI_String_Registry::collect_untranslated_hashes(
			$lang,
			self::BATCH_SIZE * 3,
			(string) ( $job['last_hash'] ?? '' )
		);

		if ( empty( $hashes ) ) {
			$remaining = UI_String_Registry::count_untranslated( $lang );

			if ( $remaining > 0 && '' !== (string) ( $job['last_hash'] ?? '' ) ) {
				$job['last_hash']  = '';
				$job['updated_at'] = time();
				self::save_job( $job );

				return self::process_step();
			}

			$job['status']     = 'completed';
			$job['remaining']  = 0;
			$job['updated_at'] = time();
			$job['last_error'] = '';
			$job['batch_saved'] = 0;
			self::save_job( $job );

			return self::normalize_job( $job );
		}

		$built = self::build_batch_payload( $hashes );

		if ( ! empty( $built['skipped'] ) ) {
			UI_String_Registry::mark_bulk_skipped( $lang, $built['skipped'] );
			$job['skipped'] = (int) ( $job['skipped'] ?? 0 ) + count( $built['skipped'] );
		}

		$payload  = $built['payload'];
		$hash_map = $built['hash_map'];

		if ( empty( $payload ) ) {
			$job['last_hash']   = end( $hashes );
			$job['remaining']   = UI_String_Registry::count_untranslated( $lang );
			$job['updated_at']  = time();
			$job['batch_saved'] = 0;
			$job['batch_skipped'] = count( $built['skipped'] ?? array() );

			if ( $job['remaining'] <= 0 ) {
				$job['status'] = 'completed';
			}

			self::save_job( $job );

			return self::normalize_job( $job );
		}

		$result = self::translate_batch_with_fallback(
			$payload,
			$hash_map,
			$api_key,
			$endpoint,
			$model,
			$lang
		);

		$failed_hashes = isset( $result['failed'] ) && is_array( $result['failed'] ) ? $result['failed'] : array();
		$translations  = isset( $result['translations'] ) && is_array( $result['translations'] ) ? $result['translations'] : array();

		if ( ! empty( $failed_hashes ) ) {
			UI_String_Registry::mark_bulk_skipped( $lang, $failed_hashes );
			$job['skipped'] = (int) ( $job['skipped'] ?? 0 ) + count( $failed_hashes );
			$job['failed']  = (int) ( $job['failed'] ?? 0 ) + count( $failed_hashes );
		}

		$batch_saved = 0;

		if ( ! empty( $translations ) ) {
			UI_String_Registry::save_translations( $lang, $translations );
			$batch_saved      = count( $translations );
			$job['succeeded'] = (int) ( $job['succeeded'] ?? 0 ) + $batch_saved;
		}

		$processed_hashes = array_keys( $hash_map );
		$job['last_hash']  = ! empty( $processed_hashes ) ? end( $processed_hashes ) : (string) ( $job['last_hash'] ?? '' );
		$job['remaining']  = UI_String_Registry::count_untranslated( $lang );
		$job['updated_at'] = time();
		$job['batch_saved'] = $batch_saved;
		$job['batch_skipped'] = count( $failed_hashes ) + count( $built['skipped'] ?? array() );

		if ( empty( $translations ) && ! empty( $failed_hashes ) ) {
			$job['last_error'] = isset( $result['error_message'] ) && is_string( $result['error_message'] )
				? $result['error_message']
				: __( 'ترجمه این دسته ناموفق بود؛ رشته‌های مشکل‌دار رد شدند.', 'polymart-ai' );
		} else {
			$job['last_error'] = '';
		}

		if ( $job['remaining'] <= 0 ) {
			$job['status']     = 'completed';
			$job['last_error'] = '';
		}

		self::save_job( $job );

		return self::normalize_job( $job );
	}

	/**
	 * Build an AI payload from registry hashes, skipping oversized msgids.
	 *
	 * @param string[] $hashes Candidate entry hashes.
	 * @return array{payload: array<string, string>, hash_map: array<string, string>, skipped: string[]}
	 */
	private static function build_batch_payload( array $hashes ) {
		$payload   = array();
		$hash_map  = array();
		$skipped   = array();
		$char_total = 0;

		foreach ( $hashes as $hash ) {
			if ( count( $payload ) >= self::BATCH_SIZE ) {
				break;
			}

			$entry = UI_String_Registry::get_entry( $hash );

			if ( ! is_array( $entry ) ) {
				$skipped[] = $hash;
				continue;
			}

			$msgid = isset( $entry['msgid'] ) ? trim( (string) $entry['msgid'] ) : '';

			if ( '' === $msgid ) {
				$skipped[] = $hash;
				continue;
			}

			if ( strlen( $msgid ) > self::get_max_msgid_chars_for_entry( $entry ) ) {
				$skipped[] = $hash;
				continue;
			}

			if ( $char_total + strlen( $msgid ) > self::MAX_BATCH_CHARS && ! empty( $payload ) ) {
				break;
			}

			$key              = 's_' . count( $payload );
			$payload[ $key ]  = $msgid;
			$hash_map[ $key ] = $hash;
			$char_total      += strlen( $msgid );
		}

		return array(
			'payload'  => $payload,
			'hash_map' => $hash_map,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Allow longer WooCommerce checkout config strings (shipping/payment descriptions).
	 *
	 * @param array<string, mixed> $entry Registry entry.
	 * @return int
	 */
	private static function get_max_msgid_chars_for_entry( array $entry ) {
		$context = isset( $entry['context'] ) ? (string) $entry['context'] : '';

		if (
			0 === strpos( $context, 'wc_shipping_desc:' )
			|| 0 === strpos( $context, 'wc_payment_gateway:' )
		) {
			return 4000;
		}

		return self::MAX_MSGID_CHARS;
	}

	/**
	 * Translate a batch, splitting and retrying per-string on recoverable failures.
	 *
	 * @param array<string, string> $payload   AI payload keys.
	 * @param array<string, string> $hash_map  AI key => registry hash.
	 * @param string                $api_key   API key.
	 * @param string                $endpoint  Endpoint URL.
	 * @param string                $model     Model name.
	 * @param string                $lang      Target language.
	 * @return array{translations: array<string, string>, failed: string[], error_message: string}
	 */
	private static function translate_batch_with_fallback( array $payload, array $hash_map, $api_key, $endpoint, $model, $lang ) {
		$batch_result = AI_Client::translate_fields( $payload, $api_key, $endpoint, $model, $lang );

		if ( ! is_wp_error( $batch_result ) ) {
			return array(
				'translations'  => self::map_ai_result_to_hashes( $batch_result, $hash_map ),
				'failed'        => array(),
				'error_message' => '',
			);
		}

		$error_message = $batch_result->get_error_message();
		$translations  = array();
		$failed        = array();

		foreach ( $payload as $key => $msgid ) {
			$hash = isset( $hash_map[ $key ] ) ? $hash_map[ $key ] : '';

			if ( '' === $hash ) {
				continue;
			}

			$single = AI_Client::translate_fields(
				array( $key => $msgid ),
				$api_key,
				$endpoint,
				$model,
				$lang
			);

			if ( is_wp_error( $single ) ) {
				$error_message = $single->get_error_message();
				$failed[]      = $hash;
				continue;
			}

			$mapped = self::map_ai_result_to_hashes( $single, array( $key => $hash ) );

			if ( empty( $mapped ) ) {
				$failed[] = $hash;
				continue;
			}

			$translations = array_merge( $translations, $mapped );
		}

		return array(
			'translations'  => $translations,
			'failed'        => $failed,
			'error_message' => $error_message,
		);
	}

	/**
	 * Map AI response keys back to registry hashes.
	 *
	 * @param array<string, string> $ai_result Translated values keyed like payload.
	 * @param array<string, string> $hash_map  Payload key => hash.
	 * @return array<string, string>
	 */
	private static function map_ai_result_to_hashes( array $ai_result, array $hash_map ) {
		$out = array();

		foreach ( $hash_map as $key => $hash ) {
			if ( ! isset( $ai_result[ $key ] ) || ! is_string( $ai_result[ $key ] ) ) {
				continue;
			}

			$text = trim( $ai_result[ $key ] );

			if ( '' === $text ) {
				continue;
			}

			$out[ $hash ] = $text;
		}

		return $out;
	}

	/**
	 * Load ArvanCloud credentials from the same settings bucket as post translation.
	 *
	 * @return array{api_key: string, api_endpoint: string, ai_model: string}|\WP_Error
	 */
	private static function resolve_ai_credentials() {
		$settings = Post_Translator::get_translation_settings();
		$api_key  = trim( (string) ( $settings['api_key'] ?? '' ) );
		$endpoint = trim( (string) ( $settings['api_endpoint'] ?? '' ) );
		$provider = sanitize_key( (string) ( $settings['ai_provider'] ?? AI_Client::PROVIDER_ARVAN ) );

		if ( '' === $api_key ) {
			return new \WP_Error(
				'polymart_ai_missing_credentials',
				__( 'کلید API سرویس ترجمه پیکربندی نشده است.', 'polymart-ai' )
			);
		}

		if ( AI_Client::PROVIDER_GAPGPT !== $provider && '' === $endpoint ) {
			return new \WP_Error(
				'polymart_ai_missing_credentials',
				__( 'آدرس AI Gateway آروان‌کلاد پیکربندی نشده است.', 'polymart-ai' )
			);
		}

		return array(
			'api_key'      => $api_key,
			'api_endpoint' => rtrim( $endpoint, '/' ),
			'ai_model'     => AI_Client::resolve_model(
				(string) ( $settings['ai_model'] ?? '' ),
				$endpoint
			),
		);
	}

	/**
	 * Ensure a running job exists before processing a batch.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function ensure_running_job() {
		$job    = self::get_job();
		$status = sanitize_key( (string) ( $job['status'] ?? 'idle' ) );

		if ( 'running' === $status ) {
			return $job;
		}

		$lang = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

		if ( '' === $lang ) {
			$lang = 'en';
		}

		if ( UI_String_Registry::count_untranslated( $lang ) <= 0 ) {
			return new \WP_Error(
				'polymart_ai_nothing_to_translate',
				__( 'همه رشته‌های UI قبلاً ترجمه شده‌اند.', 'polymart-ai' )
			);
		}

		if ( 'paused' === $status ) {
			$resumed = self::resume_job();

			return is_wp_error( $resumed ) ? $resumed : $resumed;
		}

		$started = self::start_job( $lang );

		return is_wp_error( $started ) ? $started : $started;
	}

	/**
	 * Persist job state.
	 *
	 * @param array<string, mixed> $job Job payload.
	 * @return void
	 */
	private static function save_job( array $job ) {
		update_option( UI_String_Registry::JOB_OPTION, self::normalize_job( $job ), false );
	}

	/**
	 * Default empty job structure.
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_job() {
		return array(
			'status'        => 'idle',
			'lang'          => '',
			'total'         => 0,
			'initial_total' => 0,
			'run_done'      => 0,
			'succeeded'     => 0,
			'failed'        => 0,
			'skipped'       => 0,
			'remaining'     => 0,
			'last_hash'     => '',
			'started_at'    => 0,
			'updated_at'    => 0,
			'last_error'    => '',
			'batch_saved'   => 0,
			'batch_skipped' => 0,
		);
	}

	/**
	 * Normalize job counters and derived fields.
	 *
	 * @param array<string, mixed> $job Raw job.
	 * @return array<string, mixed>
	 */
	private static function normalize_job( array $job ) {
		$normalized = wp_parse_args( $job, self::empty_job() );

		$normalized['status']        = sanitize_key( (string) $normalized['status'] );
		$normalized['lang']          = sanitize_key( (string) $normalized['lang'] );
		$normalized['total']         = max( 0, (int) $normalized['total'] );
		$normalized['initial_total'] = max( 0, (int) $normalized['initial_total'] );
		$normalized['run_done']      = max( 0, (int) $normalized['run_done'] );
		$normalized['succeeded']     = max( 0, (int) $normalized['succeeded'] );
		$normalized['failed']        = max( 0, (int) $normalized['failed'] );
		$normalized['skipped']       = max( 0, (int) $normalized['skipped'] );
		$normalized['remaining']     = max( 0, (int) $normalized['remaining'] );
		$normalized['last_hash']     = (string) $normalized['last_hash'];
		$normalized['started_at']    = max( 0, (int) $normalized['started_at'] );
		$normalized['updated_at']    = max( 0, (int) $normalized['updated_at'] );
		$normalized['last_error']    = (string) $normalized['last_error'];
		$normalized['batch_saved']   = max( 0, (int) ( $normalized['batch_saved'] ?? 0 ) );
		$normalized['batch_skipped'] = max( 0, (int) ( $normalized['batch_skipped'] ?? 0 ) );

		if ( '' !== $normalized['lang'] ) {
			$remaining = UI_String_Registry::count_untranslated( $normalized['lang'] );
			$normalized['remaining'] = $remaining;

			if ( $normalized['initial_total'] > 0 && 'idle' !== $normalized['status'] ) {
				$normalized['run_done']        = max( 0, min( $normalized['initial_total'], $normalized['initial_total'] - $remaining ) );
				$normalized['run_remaining']   = $remaining;
				$normalized['progress_percent'] = min(
					100,
					(int) round( ( $normalized['run_done'] / max( 1, $normalized['initial_total'] ) ) * 100 )
				);
			} else {
				$normalized['run_remaining']   = $remaining;
				$normalized['progress_percent'] = 0;
			}

			if ( $remaining <= 0 && in_array( $normalized['status'], array( 'running', 'paused' ), true ) ) {
				$normalized['status']          = 'completed';
				$normalized['run_done']        = max( $normalized['run_done'], $normalized['initial_total'] );
				$normalized['run_remaining']   = 0;
				$normalized['progress_percent'] = 100;
			}
		}

		return $normalized;
	}
}
