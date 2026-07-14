<?php
/**
 * Activity_Logger Job_Api (auto-split).
 *
 * @package PolymartAI\Activity_Logger\Traits
 */

namespace PolymartAI\Activity_Logger\Traits\Job;

use PolymartAI\Activity_Logger\Job_Action_Scheduler;
use PolymartAI\Activity_Logger\Metabox_Action_Scheduler;
use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\Content\Menu_Translator;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Pipeline\Translation_Query;

defined( 'ABSPATH' ) || exit;

trait Trait_Job_Api {

	public static function is_critical_api_error( \WP_Error $error ) {
		$code    = $error->get_error_code();
		$message = strtolower( $error->get_error_message() );
		$data    = $error->get_error_data();
		$status  = is_array( $data ) ? absint( $data['status'] ?? 0 ) : 0;

		// Rate limits / bot challenges are recoverable with backoff — do not kill the job.
		if ( 429 === $status || self::is_rate_limit_or_bot_error( $error ) ) {
			return false;
		}

		if ( in_array( $status, array( 401, 403 ), true ) ) {
			return true;
		}

		$critical_codes = array(
			'polymart_ai_missing_credentials',
			'polymart_ai_missing_api_key',
			'polymart_ai_missing_endpoint',
		);

		if ( in_array( $code, $critical_codes, true ) ) {
			return true;
		}

		$critical_keywords = array(
			'quota',
			'insufficient',
			'unauthorized',
			'invalid api key',
			'authentication',
			'billing',
			'توکن',
			'اعتبار',
			'سهمیه',
			'کلید api',
		);

		foreach ( $critical_keywords as $keyword ) {
			if ( false !== strpos( $message, $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	public static function is_explicit_arvan_ip_block_message( $message ) {
		$message = strtolower( (string) $message );

		if ( '' === $message ) {
			return false;
		}

		foreach ( array(
			'blocked from your ip',
			'blocked your ip',
			'has blocked your ip',
			'ip has been blocked',
			'access denied',
		) as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}

		// 404 only when the body clearly describes a WAF block, not a missing model/route.
		if (
			false !== strpos( $message, '404' )
			&& (
				false !== strpos( $message, 'blocked' )
				|| false !== strpos( $message, 'forbidden' )
				|| false !== strpos( $message, 'firewall' )
				|| false !== strpos( $message, 'برگرداند' )
			)
		) {
			return true;
		}

		return false;
	}

	public static function is_rate_limit_or_bot_error( \WP_Error $error ) {
		$message = strtolower( $error->get_error_message() );

		if ( self::is_synthetic_api_throttle_message( $message ) ) {
			return false;
		}

		$data   = $error->get_error_data();
		$status = is_array( $data ) ? absint( $data['status'] ?? 0 ) : 0;

		if ( 429 === $status ) {
			return true;
		}

		if ( self::is_explicit_arvan_ip_block_message( $message ) ) {
			return true;
		}

		foreach ( array( 'too many requests', 'rate limit', 'rate-limit' ) as $keyword ) {
			if ( false !== strpos( $message, $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	public static function truncate_api_error_message( $message, $max = 160 ) {
		$message = trim( wp_strip_all_tags( (string) $message ) );
		$message = preg_replace( '/\s+/', ' ', $message );
		$max     = max( 40, absint( $max ) );

		if ( mb_strlen( $message ) <= $max ) {
			return $message;
		}

		return mb_substr( $message, 0, $max - 1 ) . '…';
	}

	public static function humanize_api_error_message( $message ) {
		$message = (string) $message;
		$lower   = strtolower( $message );

		if ( self::is_explicit_arvan_ip_block_message( $lower ) ) {
			return __( 'IP موقتاً توسط فایروال آروان مسدود شد — چند دقیقه صبر کنید', 'polymart-ai' );
		}

		if (
			false !== strpos( $lower, '429' )
			|| false !== strpos( $lower, 'rate limit' )
			|| false !== strpos( $lower, 'too many requests' )
		) {
			return __( 'محدودیت نرخ API — چند دقیقه صبر کنید', 'polymart-ai' );
		}

		return self::truncate_api_error_message( $message, 100 );
	}

	private static function apply_api_cooldown_job_presentation( array $job, $cooldown_remaining ) {
		$cooldown_message = self::format_api_cooldown_message( $cooldown_remaining );

		$job['step_deferred']         = true;
		$job['step_deferred_reason']  = 'api_cooldown';
		$job['step_deferred_message'] = $cooldown_message;
		$job['last_error']            = $cooldown_message;

		if ( is_array( $job['last_step'] ?? null ) ) {
			$job['last_step']['message'] = $cooldown_message;
		}

		if ( is_array( $job['current_post'] ?? null ) ) {
			$job['current_post']['step_message'] = $cooldown_message;
		}

		return $job;
	}

	public static function wait_for_arvan_api_gap() {
		$job = self::get_job_raw();

		if ( ! self::is_bulk_job_running() ) {
			$min_gap = max( 0, (int) apply_filters( 'polymart_ai_metabox_arvan_min_api_gap_sec', 1 ) );
			$last_at = absint( $job['last_arvan_api_at'] ?? 0 );

			if ( $last_at <= 0 || $min_gap <= 0 ) {
				return;
			}

			$wait = $min_gap - ( time() - $last_at );

			if ( $wait > 0 ) {
				sleep( min( 3, $wait ) );
			}

			return;
		}

		$default = self::is_elementor_progress_stalled( $job ) ? 6 : 18;
		$min_gap = max( 4, (int) apply_filters( 'polymart_ai_arvan_min_api_gap_sec', $default ) );
		$last_at = absint( $job['last_arvan_api_at'] ?? 0 );

		if ( $last_at <= 0 ) {
			return;
		}

		$wait = $min_gap - ( time() - $last_at );

		if ( $wait > 0 ) {
			self::sleep_with_worker_heartbeat( min( 45, $wait ) );
		}
	}

	public static function sleep_with_worker_heartbeat( $seconds ) {
		$seconds = max( 0, absint( $seconds ) );

		while ( $seconds > 0 ) {
			$slice = min( 5, $seconds );
			sleep( $slice );
			$seconds -= $slice;

			if ( self::is_bulk_job_running() ) {
				self::touch_worker_heartbeat();
			}
		}
	}

	public static function touch_arvan_api_attempt() {
		$job                      = self::get_job_raw();
		$job['last_arvan_api_at'] = time();
		self::save_job( $job );
	}

	public static function format_api_cooldown_message( $remaining_seconds ) {
		$remaining_seconds = max( 0, absint( $remaining_seconds ) );

		if ( $remaining_seconds <= 0 ) {
			return __( 'API آماده تلاش مجدد است', 'polymart-ai' );
		}

		if ( $remaining_seconds >= 60 ) {
			return sprintf(
				/* translators: %d: minutes remaining */
				__( 'API آروان محدود — %d دقیقه تا ادامه (بدون مصرف توکن)', 'polymart-ai' ),
				(int) ceil( $remaining_seconds / 60 )
			);
		}

		return sprintf(
			/* translators: %d: seconds remaining */
			__( 'API آروان محدود — %d ثانیه تا ادامه', 'polymart-ai' ),
			$remaining_seconds
		);
	}

	public static function get_job_api_cooldown_remaining( $job = null ) {
		if ( ! is_array( $job ) ) {
			$job = self::get_job_raw();
		}

		$until = absint( $job['api_cooldown_until'] ?? 0 );

		if ( $until <= time() ) {
			return 0;
		}

		$remaining  = $until - time();
		$max_remain = (int) apply_filters( 'polymart_ai_arvan_api_cooldown_sec', 600 );

		// Older jobs may still carry a 30-minute cooldown from before the 10-minute policy.
		if ( $remaining > $max_remain ) {
			$until                     = time() + $max_remain;
			$job['api_cooldown_until'] = $until;
			self::save_job( $job );
			$remaining = $max_remain;
		}

		return $remaining;
	}

	public static function is_job_api_cooldown_active( $job = null ) {
		return self::get_job_api_cooldown_remaining( $job ) > 0;
	}

	public static function is_synthetic_api_throttle_message( $message ) {
		$message = strtolower( (string) $message );

		if ( '' === $message ) {
			return false;
		}

		return false !== strpos( $message, 'تا ادامه' )
			|| false !== strpos( $message, 'بدون مصرف توکن' )
			|| false !== strpos( $message, 'api آماده تلاش مجدد' )
			|| false !== strpos( $message, 'api موقتاً محدود است' );
	}

	public static function clear_job_api_cooldown( $kick_worker = false ) {
		$job = self::get_job_raw();

		$had_cooldown = self::is_job_api_cooldown_active( $job )
			|| absint( $job['api_block_streak'] ?? 0 ) > 0
			|| 'api_cooldown' === (string) ( $job['step_deferred_reason'] ?? '' );

		if ( ! $had_cooldown ) {
			return false;
		}

		$job['api_cooldown_until'] = null;
		$job['api_block_streak']   = 0;

		if ( self::is_synthetic_api_throttle_message( (string) ( $job['last_error'] ?? '' ) ) ) {
			$job['last_error'] = null;
		}

		if ( 'api_cooldown' === (string) ( $job['step_deferred_reason'] ?? '' ) ) {
			$job['step_deferred']         = false;
			$job['step_deferred_reason']  = null;
			$job['step_deferred_message'] = null;
		}

		self::save_job( $job );

		if ( $kick_worker && 'running' === ( $job['status'] ?? '' ) ) {
			self::run_inline_worker_tick( 1, 60 );
		}

		return true;
	}

	public static function touch_successful_api_call() {
		$job     = self::get_job_raw();
		$changed = false;

		if ( absint( $job['api_block_streak'] ?? 0 ) > 0 ) {
			$job['api_block_streak'] = 0;
			$changed                 = true;
		}

		if ( absint( $job['api_cooldown_until'] ?? 0 ) > time() ) {
			$job['api_cooldown_until'] = null;

			if ( self::is_synthetic_api_throttle_message( (string) ( $job['last_error'] ?? '' ) ) ) {
				$job['last_error'] = null;
			}

			if ( 'api_cooldown' === (string) ( $job['step_deferred_reason'] ?? '' ) ) {
				$job['step_deferred']         = false;
				$job['step_deferred_reason']  = null;
				$job['step_deferred_message'] = null;
			}

			$changed = true;
		}

		if ( $changed ) {
			self::save_job( $job );
		}
	}

	public static function maybe_apply_api_throttle_cooldown( \WP_Error $error, $cooldown_sec = null ) {
		if ( self::is_synthetic_api_throttle_message( $error->get_error_message() ) ) {
			return false;
		}

		if ( ! self::is_rate_limit_or_bot_error( $error ) ) {
			return false;
		}

		$data   = $error->get_error_data();
		$status = is_array( $data ) ? absint( $data['status'] ?? 0 ) : 0;
		$msg    = strtolower( $error->get_error_message() );
		$job    = self::get_job_raw();

		// Do not push the cooldown window forward while one is already active.
		if ( self::is_job_api_cooldown_active( $job ) ) {
			return true;
		}

		if ( null === $cooldown_sec ) {
			$cooldown_sec = 120;

			if ( self::is_explicit_arvan_ip_block_message( $msg ) ) {
				$streak       = absint( $job['api_block_streak'] ?? 0 ) + 1;
				$cooldown_sec = (int) apply_filters( 'polymart_ai_arvan_api_cooldown_sec', 600 );

				// Arvan WAF usually lifts after ~10 minutes; only stretch on repeated blocks in one job.
				if ( $streak >= 3 ) {
					$cooldown_sec = (int) apply_filters( 'polymart_ai_arvan_api_cooldown_repeat_sec', 900 );
				}
			} elseif ( 429 === $status ) {
				$cooldown_sec = 240;
			}
		}

		$cooldown_sec = max( 60, min( 900, absint( $cooldown_sec ) ) );
		$until        = time() + $cooldown_sec;

		if ( absint( $job['api_cooldown_until'] ?? 0 ) >= $until ) {
			return true;
		}

		if ( self::is_explicit_arvan_ip_block_message( $msg ) ) {
			$job['api_block_streak'] = absint( $job['api_block_streak'] ?? 0 ) + 1;
		}

		$short = self::humanize_api_error_message( $error->get_error_message() );

		$job['api_cooldown_until'] = $until;
		$job['last_error']         = self::format_api_cooldown_message( $cooldown_sec );
		self::save_job( $job );

		self::log(
			'warning',
			sprintf(
				/* translators: 1: cooldown minutes, 2: error excerpt */
				__( 'API محدود/مسدود شد — %1$d دقیقه توقف خودکار (بدون مصرف توکن). %2$s', 'polymart-ai' ),
				(int) ceil( $cooldown_sec / 60 ),
				$short
			)
		);

		self::schedule_chain_safety_pulse( max( self::CRON_FAST_INTERVAL_SEC, min( 180, (int) ceil( $cooldown_sec / 3 ) ) ) );

		return true;
	}

}
