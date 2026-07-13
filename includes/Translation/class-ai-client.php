<?php
/**
 * HTTP client for ArvanCloud AI (OpenAI-compatible chat completions).
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation;

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
	 * Minimum HTTP timeout for a translation request (seconds).
	 *
	 * Job/AS path typically caps at 45–50s via request options.
	 */
	const REQUEST_TIMEOUT_MIN = 45;

	/**
	 * Maximum HTTP timeout for a translation request (seconds).
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
				__( 'آدرس API آروان‌کلاد پیکربندی نشده است.', 'polymart-ai' )
			);
		}

		if ( '' === $api_key ) {
			return new \WP_Error(
				'polymart_ai_missing_api_key',
				__( 'کلید API آروان‌کلاد پیکربندی نشده است.', 'polymart-ai' )
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

		$payload = self::build_payload( $data_array, $model, $target_lang );

		if ( is_wp_error( $payload ) ) {
			return $payload;
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

		$parsed = self::parse_response( $response, $data_array );

		if ( is_wp_error( $parsed ) && self::should_retry_rate_limited_error( $parsed ) ) {
			return self::retry_after_rate_limit( $endpoint, $api_key, $payload, $data_array, $options, $parsed, 1 );
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

		$parsed = self::parse_response( $response, $data_array );

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

		$timeout = self::request_timeout_for_payload( $payload );

		/**
		 * Filter the HTTP timeout used for ArvanCloud translation requests.
		 *
		 * @param int                   $timeout Request timeout in seconds.
		 * @param array<string, mixed>  $payload Outbound request body.
		 */
		$timeout = (int) apply_filters( 'polymart_ai_request_timeout', $timeout, $payload );

		$floor = ! empty( $options['min_timeout'] )
			? max( 20, absint( $options['min_timeout'] ) )
			: self::REQUEST_TIMEOUT_MIN;
		$ceil  = ! empty( $options['max_timeout'] )
			? max( $floor, absint( $options['max_timeout'] ) )
			: self::REQUEST_TIMEOUT_MAX;

		$timeout = max( $floor, min( $ceil, $timeout ) );

		$max_transport_retries = self::MAX_TRANSPORT_RETRIES;

		// Elementor/AS/metabox slices cap at ~45s — never chain transport retries.
		if ( ! empty( $options['max_timeout'] ) && absint( $options['max_timeout'] ) <= 50 ) {
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
					sprintf(
						/* translators: %s: underlying transport error */
						__( 'درخواست API آروان‌کلاد منقضی شد یا اتصال برقرار نشد: %s', 'polymart-ai' ),
						self::truncate_error_message( $error_message )
					)
				);
			}

			return new \WP_Error(
				'polymart_ai_http_error',
				sprintf(
					/* translators: %s: error message */
					__( 'درخواست API آروان‌کلاد ناموفق بود: %s', 'polymart-ai' ),
					self::truncate_error_message( $error_message )
				)
			);
		}

		return $response;
	}

	/**
	 * Estimate an HTTP timeout from the outbound payload size.
	 *
	 * @param array<string, mixed> $payload Request body.
	 * @return int
	 */
	private static function request_timeout_for_payload( array $payload ) {
		$chars = strlen( (string) wp_json_encode( $payload ) );

		return (int) min(
			self::REQUEST_TIMEOUT_MAX,
			max( self::REQUEST_TIMEOUT_MIN, (int) ceil( $chars / 20 ) + 60 )
		);
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
			return self::merge_partial_translations( $data_array, $result, $api_key, $endpoint, $model, $target_lang, $options );
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
					__( 'هوش مصنوعی آروان‌کلاد هیچ ترجمه قابل استفاده‌ای برنگرداند.', 'polymart-ai' )
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

		return self::validate_keys( $source, $validated, false );
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

		$system_prompt = implode(
			' ',
			array(
				'You are a professional translator specializing in ' . $source_label . ' to ' . $target_label . ' translation for e-commerce and blog content.',
				'Translate each value in the provided JSON object from ' . $source_label . ' to natural, fluent ' . $target_label . '.',
				'Preserve HTML tags, shortcodes, URLs, and placeholders exactly as they appear.',
				'Return ONLY one valid JSON object with the exact same keys as the input.',
				'Every value must be a JSON string. Do not add markdown fences, comments, or extra keys.',
			)
		);

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
	private static function parse_response( $response, array $data_array ) {
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return new \WP_Error(
				'polymart_ai_empty_response',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'API آروان‌کلاد پاسخ خالی برگرداند (HTTP %d).', 'polymart-ai' ),
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
				sprintf(
					/* translators: 1: HTTP status code, 2: error message */
					__( 'API آروان‌کلاد کد HTTP %1$d برگرداند: %2$s', 'polymart-ai' ),
					$status_code,
					self::truncate_error_message( $message )
				),
				array( 'status' => $status_code )
			);
		}

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return new \WP_Error(
				'polymart_ai_invalid_response',
				__( 'API آروان‌کلاد پاسخ JSON نامعتبر برگرداند.', 'polymart-ai' )
			);
		}

		if (
			empty( $decoded['choices'] )
			|| ! is_array( $decoded['choices'] )
			|| ! isset( $decoded['choices'][0]['message']['content'] )
		) {
			return new \WP_Error(
				'polymart_ai_missing_choices',
				__( 'پاسخ هوش مصنوعی آروان‌کلاد شامل محتوای ترجمه نبود.', 'polymart-ai' )
			);
		}

		$content = $decoded['choices'][0]['message']['content'];

		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return new \WP_Error(
				'polymart_ai_empty_response',
				__( 'هوش مصنوعی آروان‌کلاد پاسخ ترجمه خالی برگرداند.', 'polymart-ai' )
			);
		}

		$translations = self::decode_json_content( $content );

		if ( is_wp_error( $translations ) ) {
			return $translations;
		}

		return self::validate_keys( $data_array, $translations );
	}

	/**
	 * Decode model output into an associative array.
	 *
	 * @param string $content Raw model output.
	 * @return array<string, string>|\WP_Error
	 */
	private static function decode_json_content( $content ) {
		$candidates = self::json_content_candidates( $content );

		foreach ( $candidates as $candidate ) {
			$decoded = self::try_decode_json_object( $candidate );

			if ( is_array( $decoded ) ) {
				return self::sanitize_decoded_strings( $decoded );
			}
		}

		return new \WP_Error(
			'polymart_ai_invalid_json',
			__( 'هوش مصنوعی آروان‌کلاد JSON نامعتبر برگرداند.', 'polymart-ai' )
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
	private static function validate_keys( array $source, array $translations, $allow_partial = false ) {
		$validated = array();

		foreach ( array_keys( $source ) as $key ) {
			if ( isset( $translations[ $key ] ) && '' !== trim( $translations[ $key ] ) ) {
				$validated[ $key ] = $translations[ $key ];
			}
		}

		if ( $allow_partial ) {
			return $validated;
		}

		if ( empty( $validated ) ) {
			return new \WP_Error(
				'polymart_ai_no_translations',
				__( 'هوش مصنوعی آروان‌کلاد هیچ ترجمه قابل استفاده‌ای برنگرداند.', 'polymart-ai' )
			);
		}

		foreach ( array_keys( $source ) as $key ) {
			if ( ! isset( $validated[ $key ] ) ) {
				return new \WP_Error(
					'polymart_ai_no_translations',
					__( 'هوش مصنوعی آروان‌کلاد هیچ ترجمه قابل استفاده‌ای برنگرداند.', 'polymart-ai' )
				);
			}
		}

		return $validated;
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
		$model = trim( (string) $model );

		if ( '' !== $model ) {
			return $model;
		}

		if ( preg_match( '#/models/([^/]+)/#i', (string) $endpoint, $matches ) ) {
			return $matches[1];
		}

		return self::DEFAULT_MODEL;
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
