<?php
/**
 * HTTP client for ArvanCloud AI (OpenAI-compatible chat completions).
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation\AI;

use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Post_Translator\Shortcode_Masker;

defined( 'ABSPATH' ) || exit;

/**
 * Class AI_Client
 */
final class AI_Client {

	/**
	 * OpenAI-compatible chat completions path (appended when base URL has no /v1 suffix).
	 */
	const CHAT_COMPLETIONS_PATH = '/v1/chat/completions';

	/**
	 * Relative chat completions path when the gateway base URL already ends with /v1.
	 */
	const CHAT_COMPLETIONS_SUFFIX = '/chat/completions';

	/**
	 * Maximum characters included in surfaced API error messages.
	 */
	const ERROR_MESSAGE_MAX_LENGTH = 500;

	/**
	 * Default ArvanCloud AI model when none is configured.
	 */
	const DEFAULT_MODEL = 'DeepSeek-V3-2-g6zde';

	/**
	 * GapGPT OpenAI-compatible API base URL.
	 */
	const GAPGPT_DEFAULT_ENDPOINT = 'https://api.gapgpt.app/v1';

	/**
	 * Default GapGPT model when none is configured.
	 */
	const GAPGPT_DEFAULT_MODEL = 'gapgpt-qwen-3.6';

	/**
	 * Supported AI provider identifiers.
	 */
	const PROVIDER_ARVAN  = 'arvan';
	const PROVIDER_GAPGPT = 'gapgpt';

	/**
	 * Minimum HTTP timeout for a translation request (seconds).
	 *
	 * Job/AS path typically caps at 45–50s via request options.
	 */
	const REQUEST_TIMEOUT_MIN = 20;

	/**
	 * Default HTTP timeout ceiling when caller does not pass max_timeout (seconds).
	 */
	const REQUEST_TIMEOUT_MAX = 90;

	/**
	 * Maximum automatic retries after transport failures.
	 */
	const MAX_TRANSPORT_RETRIES = 3;

	/**
	 * Maximum retries for HTTP 429 / 5xx rate-limit style responses.
	 */
	const MAX_RATE_LIMIT_RETRIES = 4;

	/**
	 * cURL timeout (seconds) for the in-flight translation HTTP request.
	 *
	 * @var int|null
	 */
	private static $active_curl_timeout = null;

	/**
	 * Register transport hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'http_api_curl', array( __CLASS__, 'enforce_active_curl_timeout' ), 10, 1 );
	}

	/**
	 * Force cURL to honor the computed translation timeout (some hosts default to 30s).
	 * Also emits mid-request heartbeats so the bulk job lock stays fresh during long AI calls.
	 *
	 * @param resource|\CurlHandle $handle cURL handle.
	 * @return void
	 */
	public static function enforce_active_curl_timeout( $handle ) {
		if ( null === self::$active_curl_timeout ) {
			return;
		}

		$timeout = (int) self::$active_curl_timeout;
		$timeout_ms = max( 1000, $timeout * 1000 );

		curl_setopt( $handle, CURLOPT_TIMEOUT, $timeout );
		// Millisecond-level hard stop (prevents some keep-alive edge cases).
		if ( defined( 'CURLOPT_TIMEOUT_MS' ) ) {
			curl_setopt( $handle, CURLOPT_TIMEOUT_MS, $timeout_ms );
		}
		curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, min( 15, max( 5, (int) ceil( $timeout / 3 ) ) ) );
		if ( defined( 'CURLOPT_CONNECTTIMEOUT_MS' ) ) {
			curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT_MS, min( 15000, max( 3000, (int) ceil( $timeout_ms / 3 ) ) ) );
		}

		// Never let persistent sockets keep the request alive beyond our timeout.
		if ( defined( 'CURLOPT_FORBID_REUSE' ) ) {
			curl_setopt( $handle, CURLOPT_FORBID_REUSE, true );
		}
		if ( defined( 'CURLOPT_FRESH_CONNECT' ) ) {
			curl_setopt( $handle, CURLOPT_FRESH_CONNECT, true );
		}

		// Keep the auto-translate worker heartbeat alive while Arvan is thinking.
		if ( $timeout >= 20 && function_exists( 'curl_setopt' ) ) {
			curl_setopt( $handle, CURLOPT_NOPROGRESS, false );
			curl_setopt(
				$handle,
				CURLOPT_PROGRESSFUNCTION,
				static function () {
					static $last_beat = 0;

					$now = time();

					if ( $now - $last_beat >= 15 ) {
						$last_beat = $now;
						/**
						 * Fires periodically during a long ArvanCloud HTTP request.
						 */
						do_action( 'polymart_ai_during_ai_http' );
					}

					return 0;
				}
			);
		}
	}

	/**
	 * Verify API credentials with a minimal chat completion request.
	 *
	 * @param string $api_key  ArvanCloud API key.
	 * @param string $endpoint Base URL assigned in the ArvanCloud dashboard.
	 * @param string $model    Model identifier.
	 * @return true|\WP_Error
	 */
	public static function test_connection( $api_key, $endpoint, $model ) {
		$endpoint = rtrim( (string) $endpoint, '/' );
		$api_key  = (string) $api_key;
		$model    = (string) $model;

		if ( '' === $endpoint ) {
			return new \WP_Error(
				'polymart_ai_missing_endpoint',
				__( 'آدرس API پیکربندی نشده است.', 'polymart-ai' )
			);
		}

		if ( '' === $api_key ) {
			return new \WP_Error(
				'polymart_ai_missing_api_key',
				__( 'کلید API پیکربندی نشده است.', 'polymart-ai' )
			);
		}

		if ( '' === $model ) {
			$model = self::resolve_model( '', $endpoint );
		}

		$request_url = self::resolve_chat_completions_url( $endpoint );

		$payload = wp_json_encode(
			array(
				'model'       => $model,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => 'Reply with exactly: OK',
					),
				),
				'max_tokens'  => 5,
				'temperature' => 0,
			)
		);

		if ( false === $payload ) {
			return new \WP_Error(
				'polymart_ai_encode_error',
				__( 'رمزگذاری درخواست تست اتصال ناموفق بود.', 'polymart-ai' )
			);
		}

		$response = wp_remote_post(
			$request_url,
			array(
				'timeout' => 30,
				'headers' => self::build_request_headers( $api_key ),
				'body'    => $payload,
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_code = $response->get_error_code();

			if ( 'http_request_failed' === $error_code ) {
				return new \WP_Error(
					'polymart_ai_timeout',
					__( 'اتصال برقرار نشد یا درخواست منقضی شد.', 'polymart-ai' )
				);
			}

			return new \WP_Error(
				'polymart_ai_http_error',
				sprintf(
					/* translators: %s: error message */
					__( 'درخواست تست اتصال ناموفق بود: %s', 'polymart-ai' ),
					self::truncate_error_message( $response->get_error_message() )
				)
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$decoded = json_decode( $body, true );
			$message = is_array( $decoded ) && isset( $decoded['error']['message'] )
				? $decoded['error']['message']
				: $body;

			return new \WP_Error(
				'polymart_ai_api_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: error message */
					__( 'API کد HTTP %1$d برگرداند: %2$s', 'polymart-ai' ),
					$status_code,
					self::truncate_error_message( $message )
				)
			);
		}

		return true;
	}

	/**
	 * Send a short sample through the same translation path as bulk jobs (for diagnostics).
	 *
	 * @param string $text        Source text (Persian).
	 * @param string $target_lang Target language code.
	 * @param string $api_key     ArvanCloud API key.
	 * @param string $endpoint    Gateway base URL.
	 * @param string $model       Model identifier.
	 * @return array<string, mixed>
	 */
	public static function test_sample_translation( $text, $target_lang, $api_key, $endpoint, $model ) {
		$text        = sanitize_text_field( (string) $text );
		$target_lang = sanitize_key( (string) $target_lang );

		if ( '' === $text ) {
			$text = 'سلام دنیا';
		}

		if ( '' === $target_lang ) {
			$target_lang = 'en';
		}

		$started      = microtime( true );
		$translations = self::send_translation_request(
			array( 'test_line' => $text ),
			(string) $api_key,
			(string) $endpoint,
			(string) $model,
			$target_lang,
			array(
				'min_timeout' => 60,
				'max_timeout' => 120,
			)
		);
		$elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $translations ) ) {
			return array(
				'success'    => false,
				'elapsed_ms' => $elapsed_ms,
				'source'     => $text,
				'target_lang' => $target_lang,
				'error'      => $translations->get_error_message(),
				'error_code' => $translations->get_error_code(),
			);
		}

		return array(
			'success'     => true,
			'elapsed_ms'  => $elapsed_ms,
			'source'      => $text,
			'target_lang' => $target_lang,
			'model'       => self::resolve_model( (string) $model, (string) $endpoint ),
			'provider'    => self::is_gapgpt_endpoint( $endpoint ) ? self::PROVIDER_GAPGPT : self::PROVIDER_ARVAN,
			'provider_label' => self::provider_label( $endpoint ),
			'translated'  => (string) ( $translations['test_line'] ?? '' ),
		);
	}

	/**
	 * Send a batch translation request to ArvanCloud AI.
	 *
	 * @param array<string, string> $data_array Field key => source text.
	 * @param string                $api_key    ArvanCloud API key.
	 * @param string                $endpoint   Base URL assigned in the ArvanCloud dashboard.
	 * @param string                $model      Model identifier.
	 * @return array<string, string>|\WP_Error Decoded translations keyed like the input.
	 */
	public static function send_translation_request( array $data_array, $api_key, $endpoint, $model, $target_lang = 'en', array $options = array() ) {
		$endpoint = rtrim( (string) $endpoint, '/' );
		$api_key  = (string) $api_key;
		$model    = (string) $model;

		if ( '' === $endpoint ) {
			return new \WP_Error(
				'polymart_ai_missing_endpoint',
				self::provider_message(
					AI_Client::PROVIDER_ARVAN,
					/* translators: %s: AI provider name */
					__( 'آدرس API %s پیکربندی نشده است.', 'polymart-ai' )
				)
			);
		}

		if ( '' === $api_key ) {
			return new \WP_Error(
				'polymart_ai_missing_api_key',
				self::provider_message(
					$endpoint,
					/* translators: %s: AI provider name */
					__( 'کلید API %s پیکربندی نشده است.', 'polymart-ai' )
				)
			);
		}

		if ( empty( $data_array ) ) {
			return new \WP_Error(
				'polymart_ai_empty_payload',
				__( 'محتوایی برای ترجمه ارسال نشده است.', 'polymart-ai' )
			);
		}

		if ( '' === $model ) {
			$model = self::resolve_model( '', $endpoint );
		}

		$masked_payload = Shortcode_Masker::mask_payload( $data_array );

		$payload = self::build_payload( $masked_payload, $model, $target_lang );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( isset( $options['temperature'] ) && is_numeric( $options['temperature'] ) ) {
			$payload['temperature'] = max( 0, min( 1, (float) $options['temperature'] ) );
		}

		/**
		 * Filter the outbound request payload before it is sent to ArvanCloud AI.
		 *
		 * @param array<string, mixed>  $payload     OpenAI-compatible request body.
		 * @param array<string, string> $data_array  Original field values.
		 */
		$payload = apply_filters( 'polymart_ai_arvan_request_payload', $payload, $data_array );

		$response = self::post_chat_completion( $endpoint, $api_key, $payload, 1, $options );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code >= 400 && isset( $payload['response_format'] ) ) {
			unset( $payload['response_format'] );
			$response = self::post_chat_completion( $endpoint, $api_key, $payload, 1, $options );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = self::parse_response( $response, $data_array, $endpoint );

		if ( is_wp_error( $parsed ) && self::is_transient_parse_response_error( $parsed ) ) {
			$parsed = self::retry_after_transient_parse_error( $endpoint, $api_key, $payload, $data_array, $options, $parsed, 1 );
		}

		if ( is_wp_error( $parsed ) && self::should_retry_rate_limited_error( $parsed ) ) {
			$parsed = self::retry_after_rate_limit( $endpoint, $api_key, $payload, $data_array, $options, $parsed, 1 );
		}

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		return Shortcode_Masker::restore_payload_shortcodes( $data_array, $parsed );
	}

	/**
	 * @param array<string, string> $payload Field map.
	 * @return bool
	 */
	private static function payload_contains_shortcode_tokens( array $payload ) {
		foreach ( $payload as $value ) {
			if ( false !== strpos( (string) $value, Shortcode_Masker::TOKEN_PREFIX ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Empty/malformed model payloads that are safe to retry (not rate limits).
	 *
	 * @param \WP_Error $error Error object.
	 * @return bool
	 */
	public static function is_transient_parse_response_error( \WP_Error $error ) {
		return in_array(
			$error->get_error_code(),
			array(
				'polymart_ai_missing_choices',
				'polymart_ai_empty_response',
				'polymart_ai_invalid_json',
				'polymart_ai_no_translations',
				'polymart_ai_chunk_empty',
			),
			true
		);
	}

	/**
	 * Retry once when the gateway returns HTTP 200 without usable translation content.
	 *
	 * @param string               $endpoint   API base.
	 * @param string               $api_key    API key.
	 * @param array<string, mixed> $payload    Request body.
	 * @param array<string, string> $data_array Original fields.
	 * @param array<string, mixed> $options    Request options.
	 * @param \WP_Error            $last_error Last error.
	 * @param int                  $attempt    Retry attempt (1-based).
	 * @return array<string, string>|\WP_Error
	 */
	private static function retry_after_transient_parse_error( $endpoint, $api_key, array $payload, array $data_array, array $options, \WP_Error $last_error, $attempt ) {
		if ( $attempt > 2 ) {
			return $last_error;
		}

		sleep( min( 4, $attempt + 1 ) );

		$retry_payload = $payload;

		if ( isset( $retry_payload['response_format'] ) ) {
			unset( $retry_payload['response_format'] );
		}

		$response = self::post_chat_completion( $endpoint, $api_key, $retry_payload, 1, $options );

		if ( is_wp_error( $response ) ) {
			return $last_error;
		}

		$parsed = self::parse_response( $response, $data_array, $endpoint );

		if ( is_wp_error( $parsed ) && self::is_transient_parse_response_error( $parsed ) ) {
			return self::retry_after_transient_parse_error( $endpoint, $api_key, $retry_payload, $data_array, $options, $parsed, $attempt + 1 );
		}

		return $parsed;
	}

	/**
	 * Whether an API error should be retried with exponential backoff.
	 *
	 * @param \WP_Error $error Error object.
	 * @return bool
	 */
	private static function should_retry_rate_limited_error( \WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? absint( $data['status'] ?? 0 ) : 0;
		$message = strtolower( $error->get_error_message() );

		// Firewall / IP blocks — never retry; each attempt worsens the ban.
		foreach ( array( 'blocked', 'arvan', 'firewall', 'مسدود', 'آروان', 'captcha', 'forbidden' ) as $keyword ) {
			if ( false !== strpos( $message, $keyword ) ) {
				return false;
			}
		}

		if ( in_array( $status, array( 403, 404 ), true ) ) {
			return false;
		}

		if ( in_array( $status, array( 429, 502, 503, 504 ), true ) ) {
			return true;
		}

		foreach ( array( '429', 'too many requests', 'rate limit' ) as $keyword ) {
			if ( false !== strpos( $message, $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Retry a chat completion after a rate-limit / WAF challenge response.
	 *
	 * @param string               $endpoint   API base.
	 * @param string               $api_key    API key.
	 * @param array<string, mixed> $payload    Request body.
	 * @param array<string, string> $data_array Original fields.
	 * @param array<string, mixed> $options    Request options.
	 * @param \WP_Error            $last_error Last error.
	 * @param int                  $attempt    Retry attempt (1-based).
	 * @return array<string, string>|\WP_Error
	 */
	private static function retry_after_rate_limit( $endpoint, $api_key, array $payload, array $data_array, array $options, \WP_Error $last_error, $attempt ) {
		if ( $attempt > self::MAX_RATE_LIMIT_RETRIES ) {
			return $last_error;
		}

		$sleep = min( 45, (int) pow( 2, $attempt ) + wp_rand( 1, 3 ) );

		/**
		 * Filter backoff seconds before retrying a rate-limited ArvanCloud request.
		 *
		 * @param int       $sleep   Seconds to wait.
		 * @param int       $attempt Retry attempt number.
		 * @param \WP_Error $error   Last error.
		 */
		$sleep = max( 1, (int) apply_filters( 'polymart_ai_rate_limit_backoff_sec', $sleep, $attempt, $last_error ) );

		sleep( $sleep );

		$response = self::post_chat_completion( $endpoint, $api_key, $payload, 1, $options );

		if ( is_wp_error( $response ) ) {
			if ( self::should_retry_rate_limited_error( $response ) ) {
				return self::retry_after_rate_limit( $endpoint, $api_key, $payload, $data_array, $options, $response, $attempt + 1 );
			}

			return $response;
		}

		$parsed = self::parse_response( $response, $data_array, $endpoint );

		if ( is_wp_error( $parsed ) && self::should_retry_rate_limited_error( $parsed ) ) {
			return self::retry_after_rate_limit( $endpoint, $api_key, $payload, $data_array, $options, $parsed, $attempt + 1 );
		}

		return $parsed;
	}

	/**
	 * POST an OpenAI-compatible chat completion payload.
	 *
	 * @param string               $endpoint API base URL.
	 * @param string               $api_key  API key.
	 * @param array<string, mixed> $payload  Request body.
	 * @param array<string, mixed> $options  Optional request options (max_timeout).
	 * @return array|\WP_Error
	 */
	private static function post_chat_completion( $endpoint, $api_key, array $payload, $attempt = 1, array $options = array() ) {
		$encoded_body = wp_json_encode( $payload );

		if ( false === $encoded_body ) {
			return new \WP_Error(
				'polymart_ai_encode_error',
				__( 'رمزگذاری داده درخواست ترجمه ناموفق بود.', 'polymart-ai' )
			);
		}

		$timeout = self::request_timeout_for_payload( $payload, $options );

		/**
		 * Filter the HTTP timeout used for ArvanCloud translation requests.
		 *
		 * @param int                   $timeout Request timeout in seconds.
		 * @param array<string, mixed>  $payload Outbound request body.
		 */
		$timeout = (int) apply_filters( 'polymart_ai_request_timeout', $timeout, $payload, $options );

		$floor = ! empty( $options['min_timeout'] )
			? max( 20, absint( $options['min_timeout'] ) )
			: self::REQUEST_TIMEOUT_MIN;
		$ceil  = ! empty( $options['max_timeout'] )
			? max( $floor, absint( $options['max_timeout'] ) )
			: self::REQUEST_TIMEOUT_MAX;

		$timeout = max( $floor, min( $ceil, $timeout ) );

		$max_transport_retries = self::MAX_TRANSPORT_RETRIES;

		// Elementor/AS/metabox slices cap transport retries — never chain multi-minute hangs.
		if ( ! empty( $options['max_timeout'] ) && absint( $options['max_timeout'] ) <= 95 ) {
			$max_transport_retries = 0;
		}

		self::$active_curl_timeout = $timeout;

		/**
		 * Fires immediately before an ArvanCloud HTTP request.
		 * Used by the bulk job worker to refresh locks during long calls.
		 */
		do_action( 'polymart_ai_before_ai_http' );

		$response = wp_remote_post(
			self::resolve_chat_completions_url( $endpoint ),
			array(
				'timeout'     => $timeout,
				'connect_timeout' => min( 15, max( 5, (int) ceil( $timeout / 3 ) ) ),
				'redirection' => 0,
				'httpversion' => '1.1',
				'headers'     => array_merge(
					self::build_request_headers( $api_key ),
					array( 'Connection' => 'close' )
				),
				'body'        => $encoded_body,
			)
		);

		self::$active_curl_timeout = null;

		if ( is_wp_error( $response ) ) {
			$error_code    = $response->get_error_code();
			$error_message = $response->get_error_message();

			if ( 'http_request_failed' === $error_code && $max_transport_retries > 0 && $attempt < $max_transport_retries ) {
				$retry_options = $options;
				$cap           = ! empty( $options['max_timeout'] )
					? max( 20, absint( $options['max_timeout'] ) )
					: self::REQUEST_TIMEOUT_MAX;

				// Never inflate retries past the job/AS timeout ceiling (avoids 2–3 minute hangs).
				$retry_options['min_timeout'] = min(
					$cap,
					max(
						(int) ( $retry_options['min_timeout'] ?? 0 ),
						min( $cap, $timeout )
					)
				);
				$retry_options['max_timeout'] = $cap;

				sleep( min( 3, $attempt ) );

				return self::post_chat_completion( $endpoint, $api_key, $payload, $attempt + 1, $retry_options );
			}

			if ( 'http_request_failed' === $error_code ) {
				return new \WP_Error(
					'polymart_ai_timeout',
					self::provider_message(
						$endpoint,
						/* translators: 1: AI provider name, 2: underlying transport error */
						__( 'درخواست API %1$s منقضی شد یا اتصال برقرار نشد: %2$s', 'polymart-ai' ),
						self::truncate_error_message( $error_message )
					)
				);
			}

			return new \WP_Error(
				'polymart_ai_http_error',
				self::provider_message(
					$endpoint,
					/* translators: 1: AI provider name, 2: error message */
					__( 'درخواست API %1$s ناموفق بود: %2$s', 'polymart-ai' ),
					self::truncate_error_message( $error_message )
				)
			);
		}

		return $response;
	}

	/**
	 * Estimate an HTTP timeout from the outbound payload size.
	 *
	 * The final bound is still clamped in post_chat_completion() via min_timeout/max_timeout.
	 *
	 * @param array<string, mixed> $payload Request body.
	 * @param array<string, mixed> $options Request options (max_timeout).
	 * @return int
	 */
	private static function request_timeout_for_payload( array $payload, array $options = array() ) {
		$chars      = strlen( (string) wp_json_encode( $payload ) );
		$calculated = max( self::REQUEST_TIMEOUT_MIN, (int) ceil( $chars / 20 ) + 60 );
		$ceiling    = self::REQUEST_TIMEOUT_MAX;

		if ( ! empty( $options['max_timeout'] ) ) {
			$ceiling = max( $ceiling, absint( $options['max_timeout'] ) );
		}

		return (int) min( $ceiling, $calculated );
	}

	/**
	 * Count characters in a translation field map.
	 *
	 * @param array<string, string> $data_array Field map.
	 * @return int
	 */
	private static function payload_char_count( array $data_array ) {
		$chars = 0;

		foreach ( $data_array as $key => $value ) {
			$chars += strlen( (string) $key ) + strlen( (string) $value );
		}

		return $chars;
	}

	/**
	 * Translate fields with automatic fallback to smaller batches on JSON errors.
	 *
	 * @param array<string, string> $data_array Field key => source text.
	 * @param string                $api_key    ArvanCloud API key.
	 * @param string                $endpoint   Base URL.
	 * @param string                $model      Model identifier.
	 * @param string                $model      Model identifier.
	 * @param string                $target_lang Target language code.
	 * @param array<string, mixed>  $options    Optional: max_timeout (int seconds).
	 * @return array<string, string>|\WP_Error
	 */
	public static function translate_fields( array $data_array, $api_key, $endpoint, $model, $target_lang = 'en', array $options = array() ) {
		if ( empty( $data_array ) ) {
			return new \WP_Error(
				'polymart_ai_empty_payload',
				__( 'محتوایی برای ترجمه ارسال نشده است.', 'polymart-ai' )
			);
		}

		$result = self::send_translation_request( $data_array, $api_key, $endpoint, $model, $target_lang, $options );

		if ( ! is_wp_error( $result ) ) {
			$merged = self::merge_partial_translations( $data_array, $result, $api_key, $endpoint, $model, $target_lang, $options );

			if ( ! is_wp_error( $merged ) && empty( $options['skip_echo_retry'] ) ) {
				$echoed = self::collect_echoed_source_keys( $data_array, $merged, $target_lang );

				// Arabic titles often come back as copied Farsi at temperature 0 — one harder retry.
				if ( ! empty( $echoed ) && in_array( sanitize_key( (string) $target_lang ), array( 'ar', 'en' ), true ) ) {
					$retry_payload = array_intersect_key( $data_array, array_flip( $echoed ) );
					$retry_opts    = array_merge(
						$options,
						array(
							'skip_echo_retry' => true,
							'temperature'     => 0.4,
						)
					);
					$retry = self::send_translation_request( $retry_payload, $api_key, $endpoint, $model, $target_lang, $retry_opts );

					if ( ! is_wp_error( $retry ) && is_array( $retry ) ) {
						foreach ( $retry as $key => $value ) {
							if ( ! is_string( $key ) || ! is_string( $value ) || '' === trim( $value ) ) {
								continue;
							}

							if ( self::translation_echoes_source( (string) ( $data_array[ $key ] ?? '' ), $value, $target_lang ) ) {
								continue;
							}

							$merged[ $key ] = $value;
						}
					}
				}

				// Never hand echoed source copies to persistence (would stay «ناقص — عنوان»).
				foreach ( self::collect_echoed_source_keys( $data_array, $merged, $target_lang ) as $echo_key ) {
					unset( $merged[ $echo_key ] );
				}
			}

			return $merged;
		}

		if ( count( $data_array ) <= 1 ) {
			return $result;
		}

		if ( ! self::is_recoverable_translation_error( $result ) ) {
			return $result;
		}

		$halves   = array_chunk( $data_array, (int) ceil( count( $data_array ) / 2 ), true );
		$combined = array();

		foreach ( $halves as $half ) {
			if ( empty( $half ) ) {
				continue;
			}

			$half_result = self::translate_fields( $half, $api_key, $endpoint, $model, $target_lang, $options );

			if ( is_wp_error( $half_result ) ) {
				return $half_result;
			}

			$combined = array_merge( $combined, $half_result );
		}

		return $combined;
	}

	/**
	 * Whether a failed translation can be retried with smaller payloads.
	 *
	 * @param \WP_Error $error Error object.
	 * @return bool
	 */
	private static function is_recoverable_translation_error( $error ) {
		if ( ! $error instanceof \WP_Error ) {
			return false;
		}

		return in_array(
			$error->get_error_code(),
			array(
				'polymart_ai_invalid_json',
				'polymart_ai_invalid_response',
				'polymart_ai_missing_choices',
				'polymart_ai_empty_response',
				'polymart_ai_no_translations',
				'polymart_ai_timeout',
				'polymart_ai_http_error',
				'polymart_ai_api_error',
				'polymart_ai_rate_limited',
			),
			true
		);
	}

	/**
	 * Fill missing keys via single-field requests, then validate the full set.
	 *
	 * @param array<string, string> $source       Original input.
	 * @param array<string, string> $translations Parsed translations so far.
	 * @param string                $api_key      API key.
	 * @param string                $endpoint     Endpoint URL.
	 * @param string                $model        Model name.
	 * @param string                $target_lang  Target language.
	 * @return array<string, string>|\WP_Error
	 */
	private static function merge_partial_translations( array $source, array $translations, $api_key = '', $endpoint = '', $model = '', $target_lang = 'en', array $options = array() ) {
		$validated = self::validate_keys( $source, $translations, true );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$missing = array();

		foreach ( array_keys( $source ) as $key ) {
			if ( ! isset( $validated[ $key ] ) ) {
				$missing[ $key ] = $source[ $key ];
			}
		}

		if ( empty( $missing ) || '' === $api_key || '' === $endpoint ) {
			return empty( $validated )
				? new \WP_Error(
					'polymart_ai_no_translations',
					self::provider_message(
						$endpoint,
						/* translators: %s: AI provider name */
						__( 'هوش مصنوعی %s هیچ ترجمه قابل استفاده‌ای برنگرداند.', 'polymart-ai' )
					)
				)
				: $validated;
		}

		foreach ( $missing as $key => $value ) {
			$parts = Post_Translator::expand_payload_for_ai( array( $key => $value ) );

			$single = self::translate_fields(
				$parts,
				$api_key,
				$endpoint,
				$model,
				$target_lang,
				$options
			);

			if ( is_wp_error( $single ) ) {
				continue;
			}

			$single = Post_Translator::collapse_payload_parts( $single );

			if ( isset( $single[ $key ] ) && '' !== trim( $single[ $key ] ) ) {
				$validated[ $key ] = $single[ $key ];
			}
		}

		return self::validate_keys( $source, $validated, false, $endpoint );
	}

	/**
	 * Build the OpenAI-compatible chat completions payload.
	 *
	 * @param array<string, string> $data_array Field key => source text.
	 * @param string                $model       Model identifier.
	 * @param string                $target_lang Target language code.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function build_payload( array $data_array, $model, $target_lang = 'en' ) {
		$target_label = \PolymartAI\Language_Registry::get_language_label_for_ai( $target_lang );
		$source_label = \PolymartAI\Language_Registry::get_language_label_for_ai(
			\PolymartAI\Language_Registry::get_default_language_code()
		);

		$system_rules = array(
			'You are a professional translator specializing in ' . $source_label . ' to ' . $target_label . ' translation for e-commerce and blog content.',
			'Translate each value in the provided JSON object from ' . $source_label . ' to natural, fluent ' . $target_label . '.',
			'Preserve HTML tags, shortcodes, URLs, and placeholders exactly as they appear.',
			'Return ONLY one valid JSON object with the exact same keys as the input.',
			'Every value must be a JSON string. Do not add markdown fences, comments, or extra keys.',
		);

		$target_lang = sanitize_key( (string) $target_lang );

		if ( 'ar' === $target_lang ) {
			$system_rules[] = 'Critical: the source language is Persian (Farsi), which shares a script with Arabic but is a different language. You MUST output Modern Standard Arabic (MSA), never copy Persian phrasing.';
			$system_rules[] = 'Do not return the same string as the input. Rewrite into natural Arabic (example: «حساب کاربری من» → «حسابي» or «حسابي الشخصي»; «مقالات» → «المقالات»).';
			$system_rules[] = 'Avoid Persian-only letters (پ چ ژ گ) unless they appear inside an untranslatable brand name.';
		} elseif ( 'en' === $target_lang ) {
			$system_rules[] = 'Critical: output English only. Never leave Persian/Arabic-script wording in the translated values.';
		}

		$has_segment_keys = false;

		foreach ( array_keys( $data_array ) as $field_key ) {
			if ( false !== strpos( (string) $field_key, '__seg' ) ) {
				$has_segment_keys = true;
				break;
			}
		}

		if ( $has_segment_keys ) {
			$system_rules[] = 'Some keys end with __segN — these are HTML segment identifiers. Return every key unchanged, including the __seg suffix, and translate only the string values.';
			$system_rules[] = 'Do not merge, rename, or drop __segN keys. Do not strip or rewrite HTML tag structure inside segment values.';
		}

		if ( self::payload_contains_shortcode_tokens( $data_array ) ) {
			$system_rules[] = 'Some values contain tokens like __SHORTCODE_0__ — copy them exactly; never translate, rename, or remove these tokens.';
		}

		$system_prompt = implode( ' ', $system_rules );

		$user_content = wp_json_encode(
			$data_array,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		if ( false === $user_content ) {
			return new \WP_Error(
				'polymart_ai_encode_error',
				__( 'رمزگذاری محتوای منبع برای ترجمه ناموفق بود.', 'polymart-ai' )
			);
		}

		$payload = array(
			'model'       => $model,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $user_content,
				),
			),
			'temperature' => 0,
			'max_tokens'  => self::estimate_max_tokens( $data_array ),
		);

		if ( self::payload_char_count( $data_array ) <= 3000 ) {
			$payload['response_format'] = array( 'type' => 'json_object' );
		}

		return $payload;
	}

	/**
	 * Estimate a safe max_tokens budget from the outbound field payload.
	 *
	 * @param array<string, string> $data_array Source fields.
	 * @return int
	 */
	private static function estimate_max_tokens( array $data_array ) {
		$chars = 0;

		foreach ( $data_array as $key => $value ) {
			$chars += strlen( (string) $key ) + strlen( (string) $value );
		}

		return min( 8192, max( 512, (int) ceil( $chars * 1.4 ) + 256 ) );
	}

	/**
	 * Parse the HTTP response into a validated translations array.
	 *
	 * @param array|\WP_Error       $response   HTTP response.
	 * @param array<string, string> $data_array Original input keys.
	 * @return array<string, string>|\WP_Error
	 */
	private static function parse_response( $response, array $data_array, $endpoint = '' ) {
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return new \WP_Error(
				'polymart_ai_empty_response',
				self::provider_message(
					$endpoint,
					/* translators: 1: AI provider name, 2: HTTP status code */
					__( 'API %1$s پاسخ خالی برگرداند (HTTP %2$d).', 'polymart-ai' ),
					$status_code
				)
			);
		}

		$decoded = json_decode( $body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = is_array( $decoded ) && isset( $decoded['error']['message'] )
				? $decoded['error']['message']
				: $body;

			$error_code = in_array( $status_code, array( 429, 502, 503, 504 ), true )
				? 'polymart_ai_rate_limited'
				: 'polymart_ai_api_error';

			return new \WP_Error(
				$error_code,
				self::provider_message(
					$endpoint,
					/* translators: 1: AI provider name, 2: HTTP status code, 3: error message */
					__( 'API %1$s کد HTTP %2$d برگرداند: %3$s', 'polymart-ai' ),
					$status_code,
					self::truncate_error_message( $message )
				),
				array( 'status' => $status_code )
			);
		}

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return new \WP_Error(
				'polymart_ai_invalid_response',
				self::provider_message(
					$endpoint,
					/* translators: %s: AI provider name */
					__( 'API %s پاسخ JSON نامعتبر برگرداند.', 'polymart-ai' )
				)
			);
		}

		$content = self::extract_chat_completion_content( $decoded );

		if ( null === $content ) {
			$finish = '';

			if ( ! empty( $decoded['choices'][0]['finish_reason'] ) ) {
				$finish = (string) $decoded['choices'][0]['finish_reason'];
			}

			$message = self::provider_message(
				$endpoint,
				/* translators: %s: AI provider name */
				__( 'پاسخ هوش مصنوعی %s شامل محتوای ترجمه نبود.', 'polymart-ai' )
			);

			if ( '' !== $finish ) {
				$message .= ' (finish_reason: ' . $finish . ')';
			}

			return new \WP_Error(
				'polymart_ai_missing_choices',
				$message,
				array(
					'status'       => $status_code,
					'body_excerpt' => self::truncate_error_message( $body ),
				)
			);
		}

		$translations = self::decode_json_content( $content, $endpoint );

		if ( is_wp_error( $translations ) ) {
			return $translations;
		}

		return self::validate_keys( $data_array, $translations, false, $endpoint );
	}

	/**
	 * Extract assistant text from OpenAI-compatible or legacy completion payloads.
	 *
	 * @param array<string, mixed> $decoded Response JSON.
	 * @return string|null
	 */
	private static function extract_chat_completion_content( array $decoded ) {
		if ( ! empty( $decoded['choices'] ) && is_array( $decoded['choices'] ) ) {
			foreach ( $decoded['choices'] as $choice ) {
				if ( ! is_array( $choice ) ) {
					continue;
				}

				if ( isset( $choice['message'] ) && is_array( $choice['message'] ) ) {
					$text = self::normalize_message_content_value( $choice['message']['content'] ?? null );

					if ( null !== $text ) {
						return $text;
					}

					$text = self::normalize_message_content_value( $choice['message']['text'] ?? null );

					if ( null !== $text ) {
						return $text;
					}

					if ( isset( $choice['message']['parsed'] ) ) {
						$text = self::normalize_message_content_value( $choice['message']['parsed'] );

						if ( null !== $text ) {
							return $text;
						}
					}

					if ( ! empty( $choice['message']['tool_calls'] ) && is_array( $choice['message']['tool_calls'] ) ) {
						foreach ( $choice['message']['tool_calls'] as $tool_call ) {
							if ( ! is_array( $tool_call ) ) {
								continue;
							}

							$arguments = $tool_call['function']['arguments'] ?? null;
							$text      = self::normalize_message_content_value( $arguments );

							if ( null !== $text ) {
								return $text;
							}
						}
					}

					$text = self::normalize_message_content_value( $choice['message']['reasoning_content'] ?? null );

					if ( null !== $text ) {
						return $text;
					}
				}

				if ( isset( $choice['text'] ) ) {
					$text = self::normalize_message_content_value( $choice['text'] );

					if ( null !== $text ) {
						return $text;
					}
				}

				if ( isset( $choice['delta'] ) && is_array( $choice['delta'] ) ) {
					$text = self::normalize_message_content_value( $choice['delta']['content'] ?? null );

					if ( null !== $text ) {
						return $text;
					}
				}
			}
		}

		foreach ( array( 'output', 'response', 'content', 'result' ) as $key ) {
			if ( ! isset( $decoded[ $key ] ) ) {
				continue;
			}

			$text = self::normalize_message_content_value( $decoded[ $key ] );

			if ( null !== $text ) {
				return $text;
			}
		}

		return null;
	}

	/**
	 * @param mixed $value Raw content value from API JSON.
	 * @return string|null
	 */
	private static function normalize_message_content_value( $value ) {
		if ( is_string( $value ) ) {
			$value = trim( $value );

			return '' !== $value ? $value : null;
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		if ( self::is_string_map_array( $value ) ) {
			$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			return false !== $encoded ? $encoded : null;
		}

		$parts = array();

		foreach ( $value as $part ) {
			if ( is_string( $part ) && '' !== trim( $part ) ) {
				$parts[] = trim( $part );
				continue;
			}

			if ( ! is_array( $part ) ) {
				continue;
			}

			if ( self::is_string_map_array( $part ) ) {
				$encoded = wp_json_encode( $part, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

				if ( false !== $encoded ) {
					$parts[] = $encoded;
				}
				continue;
			}

			if ( isset( $part['text'] ) && is_string( $part['text'] ) && '' !== trim( $part['text'] ) ) {
				$parts[] = trim( $part['text'] );
				continue;
			}

			if ( isset( $part['content'] ) && is_string( $part['content'] ) && '' !== trim( $part['content'] ) ) {
				$parts[] = trim( $part['content'] );
			}
		}

		if ( empty( $parts ) ) {
			return null;
		}

		return implode( "\n", $parts );
	}

	/**
	 * @param array<mixed> $value Candidate decoded JSON object.
	 * @return bool
	 */
	private static function is_string_map_array( array $value ) {
		if ( empty( $value ) ) {
			return false;
		}

		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) ) {
				return false;
			}

			if ( ! is_string( $item ) && ! is_numeric( $item ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Decode model output into an associative array.
	 *
	 * @param string $content Raw model output.
	 * @return array<string, string>|\WP_Error
	 */
	private static function decode_json_content( $content, $endpoint = '' ) {
		$candidates = self::json_content_candidates( $content );

		foreach ( $candidates as $candidate ) {
			$decoded = self::try_decode_json_object( $candidate );

			if ( is_array( $decoded ) ) {
				return self::sanitize_decoded_strings( $decoded );
			}
		}

		return new \WP_Error(
			'polymart_ai_invalid_json',
			self::provider_message(
				$endpoint,
				/* translators: %s: AI provider name */
				__( 'هوش مصنوعی %s JSON نامعتبر برگرداند.', 'polymart-ai' )
			)
		);
	}

	/**
	 * Build likely JSON substrings from raw model output.
	 *
	 * @param string $content Raw model output.
	 * @return string[]
	 */
	private static function json_content_candidates( $content ) {
		$content = trim( (string) $content );
		$candidates = array();

		if ( '' === $content ) {
			return $candidates;
		}

		$candidates[] = $content;

		if ( preg_match( '/```(?:json)?\s*(.*?)\s*```/s', $content, $matches ) ) {
			$candidates[] = trim( $matches[1] );
		}

		if ( preg_match( '/\{[\s\S]*\}/', $content, $matches ) ) {
			$candidates[] = trim( $matches[0] );
		}

		$normalized = array();

		foreach ( $candidates as $candidate ) {
			$candidate = trim( $candidate );

			if ( '' === $candidate ) {
				continue;
			}

			$normalized[] = $candidate;
			$normalized[] = preg_replace( '/,\s*([\]}])/', '$1', $candidate );
			$normalized[] = preg_replace( '/[“”]/u', '"', $candidate );
		}

		return array_values( array_unique( array_filter( $normalized ) ) );
	}

	/**
	 * Attempt to decode a JSON object from a string.
	 *
	 * @param string $content Candidate JSON.
	 * @return array<string, mixed>|null
	 */
	private static function try_decode_json_object( $content ) {
		$decoded = json_decode( $content, true );

		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return $decoded;
		}

		return null;
	}

	/**
	 * Keep only string values from a decoded JSON object.
	 *
	 * @param array<string, mixed> $decoded Decoded JSON.
	 * @return array<string, string>
	 */
	private static function sanitize_decoded_strings( array $decoded ) {
		$sanitized = array();

		foreach ( $decoded as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) ) {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Keep only expected keys with non-empty translated values.
	 *
	 * @param array<string, string> $source       Original input.
	 * @param array<string, string> $translations Parsed AI output.
	 * @return array<string, string>|\WP_Error
	 */
	private static function validate_keys( array $source, array $translations, $allow_partial = false, $endpoint = '' ) {
		$validated = array();

		foreach ( array_keys( $source ) as $key ) {
			if ( isset( $translations[ $key ] ) && '' !== trim( $translations[ $key ] ) ) {
				$validated[ $key ] = $translations[ $key ];
			}
		}

		if ( $allow_partial ) {
			return $validated;
		}

		$no_translation_message = self::provider_message(
			$endpoint,
			/* translators: %s: AI provider name */
			__( 'هوش مصنوعی %s هیچ ترجمه قابل استفاده‌ای برنگرداند.', 'polymart-ai' )
		);

		if ( empty( $validated ) ) {
			return new \WP_Error(
				'polymart_ai_no_translations',
				$no_translation_message
			);
		}

		foreach ( array_keys( $source ) as $key ) {
			if ( ! isset( $validated[ $key ] ) ) {
				return new \WP_Error(
					'polymart_ai_no_translations',
					$no_translation_message
				);
			}
		}

		return $validated;
	}

	/**
	 * Whether AI output is an unusable copy of the source text.
	 *
	 * @param string $source      Source text.
	 * @param string $translated  AI output.
	 * @param string $target_lang Target language (unused; kept for call-site clarity).
	 * @return bool
	 */
	private static function translation_echoes_source( $source, $translated, $target_lang = '' ) {
		unset( $target_lang );

		$source     = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( (string) $source, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
		$translated = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( (string) $translated, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );

		if ( '' === $source || '' === $translated ) {
			return false;
		}

		return hash_equals( $source, $translated );
	}

	/**
	 * Keys whose AI output still matches the source.
	 *
	 * @param array<string, string> $source       Source payload.
	 * @param array<string, string> $translations AI output.
	 * @param string                $target_lang  Target language.
	 * @return array<int, string>
	 */
	private static function collect_echoed_source_keys( array $source, array $translations, $target_lang ) {
		$echoed = array();

		foreach ( $source as $key => $text ) {
			if ( ! is_string( $key ) || ! isset( $translations[ $key ] ) || ! is_string( $translations[ $key ] ) ) {
				continue;
			}

			if ( self::translation_echoes_source( (string) $text, (string) $translations[ $key ], $target_lang ) ) {
				$echoed[] = $key;
			}
		}

		return $echoed;
	}

	/**
	 * Build the full chat-completions URL from an ArvanCloud gateway base or legacy endpoint.
	 *
	 * ArvanCloud AI gateways end with `/v1`; OpenAI-compatible calls use `/chat/completions`.
	 *
	 * @param string $endpoint Configured API base URL.
	 * @return string
	 */
	public static function resolve_chat_completions_url( $endpoint ) {
		$endpoint = rtrim( (string) $endpoint, '/' );

		if ( preg_match( '#/chat/completions$#i', $endpoint ) ) {
			return $endpoint;
		}

		if ( preg_match( '#/v1$#i', $endpoint ) ) {
			return $endpoint . self::CHAT_COMPLETIONS_SUFFIX;
		}

		return $endpoint . self::CHAT_COMPLETIONS_PATH;
	}

	/**
	 * Resolve the model identifier, inferring from ArvanCloud gateway URLs when empty.
	 *
	 * @param string $model    Configured model name.
	 * @param string $endpoint API base URL.
	 * @return string
	 */
	public static function resolve_model( $model, $endpoint ) {
		$model    = trim( (string) $model );
		$endpoint = (string) $endpoint;

		if ( '' !== $model ) {
			return $model;
		}

		if ( self::is_gapgpt_endpoint( $endpoint ) ) {
			return self::GAPGPT_DEFAULT_MODEL;
		}

		if ( preg_match( '#/models/([^/]+)/#i', $endpoint, $matches ) ) {
			return $matches[1];
		}

		return self::DEFAULT_MODEL;
	}

	/**
	 * Whether the endpoint belongs to GapGPT.
	 *
	 * @param string $endpoint API base URL.
	 * @return bool
	 */
	public static function is_gapgpt_endpoint( $endpoint ) {
		return (bool) preg_match( '#gapgpt\.app#i', (string) $endpoint );
	}

	/**
	 * Human-readable provider label for admin logs.
	 *
	 * @param string $provider Provider id or endpoint URL.
	 * @return string
	 */
	public static function provider_label( $provider = '' ) {
		$provider = strtolower( trim( (string) $provider ) );

		if ( self::PROVIDER_GAPGPT === $provider || self::is_gapgpt_endpoint( $provider ) ) {
			return 'GapGPT';
		}

		return __( 'آروان‌کلاد', 'polymart-ai' );
	}

	/**
	 * Build a user-facing message with the active AI provider name inserted.
	 *
	 * @param string $endpoint Provider id or API base URL.
	 * @param string $format   vsprintf format — first placeholder is always the provider label.
	 * @param mixed  ...$args  Additional vsprintf arguments.
	 * @return string
	 */
	private static function provider_message( $endpoint, $format, ...$args ) {
		array_unshift( $args, self::provider_label( $endpoint ) );

		return vsprintf( $format, $args );
	}

	/**
	 * HTTP headers for OpenAI-compatible ArvanCloud requests.
	 *
	 * @param string $api_key Machine-user access key.
	 * @return array<string, string>
	 */
	private static function build_request_headers( $api_key ) {
		return array(
			'Authorization' => 'Bearer ' . (string) $api_key,
			'Content-Type'  => 'application/json; charset=utf-8',
		);
	}

	/**
	 * Normalize and truncate error text before it is shown in admin UI.
	 *
	 * @param mixed $message Raw error message.
	 * @return string
	 */
	private static function truncate_error_message( $message ) {
		$message = is_string( $message ) ? wp_strip_all_tags( $message ) : __( 'خطای ناشناخته', 'polymart-ai' );

		if ( strlen( $message ) > self::ERROR_MESSAGE_MAX_LENGTH ) {
			return substr( $message, 0, self::ERROR_MESSAGE_MAX_LENGTH ) . '…';
		}

		return $message;
	}
}
