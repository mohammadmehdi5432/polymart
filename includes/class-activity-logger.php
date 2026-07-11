<?php
/**
 * Activity logs, translation reports, and resumable batch job state.
 *
 * @package PolymartAI
 */

namespace PolymartAI;

use PolymartAI\Translation\Menu_Translator;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Translation_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Class Activity_Logger
 */
final class Activity_Logger {

	const LOG_OPTION           = 'polymart_ai_logs';
	const REPORT_OPTION        = 'polymart_ai_translation_report';
	const JOB_OPTION           = 'polymart_ai_translation_job';
	const NOTICE_TRANSIENT     = 'polymart_ai_admin_notice';
	const MAX_LOG_ENTRIES      = 500;
	const MAX_REPORT_ENTRIES   = 1000;
	const MAX_JOB_RETRIES      = 3;
	const STEP_LOCK_KEY        = 'polymart_ai_job_step_lock';
	/** Atomic claim option paired with the step-lock transient. */
	const STEP_LOCK_CLAIM_KEY  = 'polymart_ai_job_step_lock_claim';
	/**
	 * Absolute orphan ceiling. Living ticks refresh the lock before/during AI HTTP.
	 * Must stay above Post_Translator::JOB_REQUEST_MAX_TIMEOUT (165s).
	 */
	const STEP_LOCK_TTL        = 210;
	/**
	 * ensure/kick must not force-unlock during a normal long AI call.
	 * Keep above JOB_REQUEST_MAX_TIMEOUT so monitors cannot start a second worker.
	 */
	const LOCK_FORCE_IDLE_SEC  = 200;
	const CRON_HOOK            = 'polymart_ai_translation_job_step';
	/**
	 * Recurring pulse — backup only. Fast progress comes from the AJAX loopback chain.
	 */
	const CRON_PULSE_HOOK      = 'polymart_ai_translation_job_pulse';
	const CRON_PULSE_SCHEDULE  = 'polymart_ai_every_minute';
	/** Seconds before the next self-chained tick after work finishes. */
	const CRON_INTERVAL_SEC    = 1;
	/** Safety reschedule while a tick is mid-flight (fatal recovery). */
	const CRON_SAFETY_SEC      = 120;
	const CRON_INTERVAL_SERVER_SEC = 1;
	/** Seconds of wall-clock work allowed per WP-Cron invocation (multi-step). */
	const CRON_STEP_BUDGET_SEC = 150;
	/** Max steps inside one cron tick (safety cap). */
	const CRON_MAX_STEPS_PER_TICK = 25;
	/**
	 * If last_cron_at is older than this while the step lock is held with no
	 * current_post_id, treat the lock as orphaned (between-item deadlock).
	 */
	const LOCK_IDLE_ORPHAN_SEC = 90;
	/** admin-ajax action for the self-driving worker chain (works with DISABLE_WP_CRON). */
	const LOOPBACK_ACTION      = 'polymart_ai_job_loopback';
	const LOOPBACK_TOKEN_OPTION = 'polymart_ai_job_loopback_token';
	const LOOPBACK_GATE_KEY    = 'polymart_ai_job_loopback_gate';
	/** Min seconds between non-forced loopback spawns. */
	const LOOPBACK_GATE_SEC    = 1;

	/**
	 * Register admin notice display and background job worker.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_background_step' ) );
		add_action( self::CRON_PULSE_HOOK, array( __CLASS__, 'process_background_step' ) );
		add_action( 'polymart_ai_before_ai_http', array( __CLASS__, 'on_ai_http_heartbeat' ) );
		add_action( 'polymart_ai_during_ai_http', array( __CLASS__, 'on_ai_http_heartbeat' ) );
		add_action( 'wp_ajax_' . self::LOOPBACK_ACTION, array( __CLASS__, 'handle_loopback_tick' ) );
		add_action( 'wp_ajax_nopriv_' . self::LOOPBACK_ACTION, array( __CLASS__, 'handle_loopback_tick' ) );
		// Heal ONLY inside cron/REST — never on storefront/admin HTML loads
		// (pinging cron on every page was spawning heavy AI work and freezing the site).
		if ( wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() ) {
			self::maybe_heal_background_worker();
		} else {
			self::maybe_ensure_pulse_quietly();
		}
	}

	/**
	 * Register a one-minute schedule for the job pulse.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function register_cron_schedules( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		$schedules[ self::CRON_PULSE_SCHEDULE ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'هر دقیقه (کارگر ترجمه پلی‌مارت)', 'polymart-ai' ),
		);

		return $schedules;
	}

	/**
	 * Keep the step lock / heartbeat fresh during long Arvan HTTP calls.
	 *
	 * @return void
	 */
	public static function on_ai_http_heartbeat() {
		if ( ! self::is_bulk_job_running() ) {
			return;
		}

		self::touch_worker_heartbeat();
	}

	/**
	 * Shared secret for the async job loopback endpoint.
	 *
	 * @return string
	 */
	private static function get_loopback_token() {
		$token = get_option( self::LOOPBACK_TOKEN_OPTION, '' );

		if ( ! is_string( $token ) || strlen( $token ) < 32 ) {
			$token = wp_generate_password( 43, false, false );
			update_option( self::LOOPBACK_TOKEN_OPTION, $token, false );
		}

		return $token;
	}

	/**
	 * admin-ajax entry for the self-driving worker (Action Scheduler-style).
	 *
	 * Independent of WP-Cron / DISABLE_WP_CRON — this is what keeps the queue
	 * moving every few seconds instead of waiting for a 1-minute crontab.
	 *
	 * @return void
	 */
	public static function handle_loopback_tick() {
		$token = isset( $_REQUEST['token'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_text_field( wp_unslash( (string) $_REQUEST['token'] ) )
			: '';

		if ( ! hash_equals( self::get_loopback_token(), $token ) ) {
			status_header( 403 );
			exit( 'forbidden' );
		}

		if ( ! self::is_bulk_job_running() ) {
			status_header( 200 );
			exit( 'idle' );
		}

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		// Another worker already owns the lock — exit quietly; it will chain itself.
		if ( self::is_step_lock_held_fresh() ) {
			status_header( 200 );
			exit( 'busy' );
		}

		self::process_background_step();

		status_header( 200 );
		exit( 'ok' );
	}

	/**
	 * Whether the global step lock is currently held by a living worker.
	 *
	 * @return bool
	 */
	private static function is_step_lock_held_fresh() {
		$lock  = get_transient( self::STEP_LOCK_KEY );
		$claim = absint( get_option( self::STEP_LOCK_CLAIM_KEY, 0 ) );
		$stamp = max( $lock ? absint( $lock ) : 0, $claim );

		if ( $stamp <= 0 ) {
			return false;
		}

		return ( time() - $stamp ) < self::STEP_LOCK_TTL;
	}

	/**
	 * Fire a non-blocking admin-ajax request that runs the next job tick.
	 *
	 * This is the primary fast path. WP-Cron pulse remains a backup only.
	 * Many hosts drop ultra-short 0.01s loopbacks — we fire two transports.
	 *
	 * @param bool $force Bypass the short spawn gate.
	 * @return void
	 */
	public static function spawn_job_loopback( $force = false ) {
		if ( ! self::is_bulk_job_running() ) {
			return;
		}

		// Never stack while a living tick holds the lock.
		if ( self::is_step_lock_held_fresh() ) {
			return;
		}

		if ( ! $force && get_transient( self::LOOPBACK_GATE_KEY ) ) {
			return;
		}

		set_transient( self::LOOPBACK_GATE_KEY, 1, max( 1, self::LOOPBACK_GATE_SEC ) );

		$url  = admin_url( 'admin-ajax.php' );
		$body = array(
			'action' => self::LOOPBACK_ACTION,
			'token'  => self::get_loopback_token(),
		);
		$args = array(
			'blocking'  => false,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'headers'   => array(
				'Connection' => 'Close',
			),
			'body'      => $body,
		);

		// Attempt 1: classic non-blocking micro-timeout.
		wp_remote_post(
			$url,
			array_merge(
				$args,
				array(
					'timeout' => 0.01,
				)
			)
		);

		// Attempt 2: slightly longer non-blocking timeout — accepted by more hosts
		// that discard 0.01s requests before PHP boots.
		wp_remote_post(
			$url,
			array_merge(
				$args,
				array(
					'timeout' => 3,
				)
			)
		);
	}

	/**
	 * Seconds of silence before ensure runs an inline worker tick.
	 *
	 * Loopback HTTP is unreliable on many production hosts; inline ensure is the
	 * reliable path while an admin is monitoring the job.
	 *
	 * @return int
	 */
	private static function get_ensure_inline_idle_sec() {
		return max( 8, min( 60, (int) apply_filters( 'polymart_ai_job_ensure_inline_idle_sec', 12 ) ) );
	}

	/**
	 * Ensure the recurring pulse exists without spawning cron (safe on page loads).
	 *
	 * @return void
	 */
	private static function maybe_ensure_pulse_quietly() {
		$job = get_option( self::JOB_OPTION, array() );

		if ( ! is_array( $job ) || 'running' !== ( $job['status'] ?? '' ) ) {
			return;
		}

		if ( wp_next_scheduled( self::CRON_PULSE_HOOK ) ) {
			return;
		}

		// Schedule only — never ping/spawn from HTML traffic.
		wp_schedule_event( time() + 30, self::CRON_PULSE_SCHEDULE, self::CRON_PULSE_HOOK );
	}

	/**
	 * Re-schedule the worker if a job is running but the cron event was orphaned.
	 *
	 * @return void
	 */
	public static function maybe_heal_background_worker() {
		$job = get_option( self::JOB_OPTION, array() );

		if ( ! is_array( $job ) || 'running' !== ( $job['status'] ?? '' ) ) {
			return;
		}

		self::ensure_recurring_pulse();

		if ( wp_next_scheduled( self::CRON_HOOK ) || wp_next_scheduled( self::CRON_PULSE_HOOK ) ) {
			self::ping_wp_cron();

			return;
		}

		self::schedule_background_worker( 1 );
	}

	/**
	 * Show stored critical notices in wp-admin.
	 *
	 * @return void
	 */
	public static function render_admin_notices() {
		if ( ! current_user_can( REST_API::required_admin_capability() ) ) {
			return;
		}

		$notice = get_transient( self::NOTICE_TRANSIENT );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		$type = in_array( $notice['type'] ?? '', array( 'error', 'warning', 'success', 'info' ), true )
			? $notice['type']
			: 'error';

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s:</strong> %3$s</p></div>',
			esc_attr( $type ),
			esc_html__( 'مترجم پلی‌مارت', 'polymart-ai' ),
			esc_html( $notice['message'] )
		);

		delete_transient( self::NOTICE_TRANSIENT );
	}

	/**
	 * Store a dismissible wp-admin notice.
	 *
	 * @param string $message Notice text.
	 * @param string $type    error|warning|success|info.
	 * @return void
	 */
	public static function set_admin_notice( $message, $type = 'error' ) {
		set_transient(
			self::NOTICE_TRANSIENT,
			array(
				'message' => (string) $message,
				'type'    => $type,
				'time'    => time(),
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * Append an activity log entry.
	 *
	 * @param string               $level   info|success|warning|error.
	 * @param string               $message Persian log message.
	 * @param array<string, mixed> $context Optional context.
	 * @return void
	 */
	public static function log( $level, $message, array $context = array() ) {
		$message = sanitize_text_field( (string) $message );
		$level   = sanitize_key( (string) $level );
		$logs    = get_option( self::LOG_OPTION, array() );
		$logs    = is_array( $logs ) ? $logs : array();

		// Suppress duplicate success/info lines within 45s (concurrent workers).
		$post_id = absint( $context['post_id'] ?? 0 );
		$now     = time();
		$count   = count( $logs );

		for ( $i = $count - 1; $i >= max( 0, $count - 15 ); $i-- ) {
			$entry = $logs[ $i ];

			if ( ! is_array( $entry ) ) {
				continue;
			}

			$entry_time = absint( $entry['time'] ?? 0 );

			if ( $entry_time > 0 && ( $now - $entry_time ) > 45 ) {
				break;
			}

			$same_message = (string) ( $entry['message'] ?? '' ) === $message;
			$same_post    = $post_id <= 0 || absint( $entry['context']['post_id'] ?? 0 ) === $post_id;

			if ( $same_message && $same_post ) {
				return;
			}
		}

		$logs[] = array(
			'id'      => uniqid( 'log_', true ),
			'level'   => $level,
			'message' => $message,
			'context' => $context,
			'time'    => $now,
		);

		if ( count( $logs ) > self::MAX_LOG_ENTRIES ) {
			$logs = array_slice( $logs, -self::MAX_LOG_ENTRIES );
		}

		update_option( self::LOG_OPTION, $logs, false );

		if ( 'error' === $level ) {
			self::set_admin_notice( $message, 'error' );
		}
	}

	/**
	 * Get activity logs.
	 *
	 * @param int    $limit Max entries.
	 * @param string $level Optional level filter.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_logs( $limit = 100, $level = '', $offset = 0 ) {
		$logs = self::filter_logs( $level );

		return array_slice( $logs, max( 0, absint( $offset ) ), max( 1, absint( $limit ) ) );
	}

	/**
	 * Get paginated activity logs.
	 *
	 * @param int    $limit  Max entries per page.
	 * @param string $level  Optional level filter.
	 * @param int    $offset Offset for pagination.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function get_logs_page( $limit = 25, $level = '', $offset = 0 ) {
		$logs = self::filter_logs( $level );

		return array(
			'items' => array_slice( $logs, max( 0, absint( $offset ) ), max( 1, absint( $limit ) ) ),
			'total' => count( $logs ),
		);
	}

	/**
	 * Filter stored logs by level.
	 *
	 * @param string $level Optional level filter.
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_logs( $level = '' ) {
		$logs = get_option( self::LOG_OPTION, array() );
		$logs = is_array( $logs ) ? array_reverse( $logs ) : array();

		if ( '' === $level ) {
			return $logs;
		}

		return array_values(
			array_filter(
				$logs,
				static function ( $entry ) use ( $level ) {
					return is_array( $entry ) && ( $entry['level'] ?? '' ) === $level;
				}
			)
		);
	}

	/**
	 * Clear all activity logs.
	 *
	 * @return void
	 */
	public static function clear_logs() {
		delete_option( self::LOG_OPTION );
	}

	/**
	 * Record a successful translation in the report.
	 *
	 * @param int    $post_id         Post ID.
	 * @param string $lang            Target language code.
	 * @param string $title_source    Source title.
	 * @param string $title_translated Translated title.
	 * @param string $source          manual|ai|bulk|auto.
	 * @return void
	 */
	public static function log_report( $post_id, $lang, $title_source, $title_translated, $source = 'ai' ) {
		$language = Language_Registry::get_language( $lang );
		$reports  = get_option( self::REPORT_OPTION, array() );
		$reports  = is_array( $reports ) ? $reports : array();

		$post = get_post( absint( $post_id ) );

		$reports[] = array(
			'id'               => uniqid( 'rep_', true ),
			'post_id'          => (int) $post_id,
			'post_type'        => $post instanceof \WP_Post ? $post->post_type : '',
			'post_type_label'  => $post instanceof \WP_Post ? Post_Translator::get_post_type_label( $post->post_type ) : '',
			'lang'             => sanitize_key( $lang ),
			'lang_label'       => $language ? $language['native_name'] : $lang,
			'title_source'     => sanitize_text_field( (string) $title_source ),
			'title_translated' => sanitize_text_field( (string) $title_translated ),
			'source'           => sanitize_key( (string) $source ),
			'time'             => time(),
			'edit_url'         => get_edit_post_link( $post_id, 'raw' ),
		);

		if ( count( $reports ) > self::MAX_REPORT_ENTRIES ) {
			$reports = array_slice( $reports, -self::MAX_REPORT_ENTRIES );
		}

		update_option( self::REPORT_OPTION, $reports, false );
	}

	/**
	 * Get translation report entries.
	 *
	 * @param int    $limit Max entries.
	 * @param string $lang  Optional language filter.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_reports( $limit = 100, $lang = '', $offset = 0 ) {
		$reports = self::filter_reports( $lang );

		return array_slice( $reports, max( 0, absint( $offset ) ), max( 1, absint( $limit ) ) );
	}

	/**
	 * Get paginated translation report entries.
	 *
	 * @param int    $limit  Max entries per page.
	 * @param string $lang   Optional language filter.
	 * @param int    $offset Offset for pagination.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function get_reports_page( $limit = 25, $lang = '', $offset = 0 ) {
		$reports = self::filter_reports( $lang );

		return array(
			'items' => array_slice( $reports, max( 0, absint( $offset ) ), max( 1, absint( $limit ) ) ),
			'total' => count( $reports ),
		);
	}

	/**
	 * Filter stored reports by language.
	 *
	 * @param string $lang Optional language filter.
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_reports( $lang = '' ) {
		$reports = get_option( self::REPORT_OPTION, array() );
		$reports = is_array( $reports ) ? array_reverse( $reports ) : array();

		if ( '' === $lang ) {
			return $reports;
		}

		$lang = sanitize_key( $lang );

		return array_values(
			array_filter(
				$reports,
				static function ( $entry ) use ( $lang ) {
					return is_array( $entry ) && ( $entry['lang'] ?? '' ) === $lang;
				}
			)
		);
	}

	/**
	 * Default empty job structure.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty_job() {
		return array(
			'status'          => 'idle',
			'lang'            => 'en',
			'last_post_id'    => 0,
			'queue'           => array(),
			'retry_queue'     => array(),
			'deferred_queue'  => array(),
			'parked_ids'      => array(),
			'defer_rounds'    => array(),
			'post_step_counts' => array(),
			'retry_attempts'  => array(),
			'remaining'       => 0,
			'total'           => 0,
			'processed'       => 0,
			'steps'           => 0,
			'succeeded'       => 0,
			'succeeded_ids'   => array(),
			'partial'         => 0,
			'partial_ids'     => array(),
			'exhausted_ids'   => array(),
			'failed'          => 0,
			'skipped'         => 0,
			'current_post_id' => null,
			'step_started_at' => null,
			'last_error'      => null,
			'started_at'      => null,
			'updated_at'      => null,
			'phase'           => 'posts',
			'last_menu_id'    => 0,
			'partial_post_id' => null,
			'partial_phase'   => null,
			'partial_progress' => null,
			'consecutive_post_id'    => 0,
			'consecutive_post_steps' => 0,
			'stuck_progress_steps'   => 0,
			'last_cron_at'           => null,
			'last_cron_steps'        => 0,
			'last_worker'            => null,
		);
	}

	/**
	 * Get the current translation job.
	 *
	 * @param bool $deep_sync When true, refresh live site-wide stats (expensive).
	 * @return array<string, mixed>
	 */
	public static function get_job( $deep_sync = false ) {
		$job = get_option( self::JOB_OPTION, array() );

		if ( ! is_array( $job ) || empty( $job ) ) {
			return self::empty_job();
		}

		$job = wp_parse_args( $job, self::empty_job() );

		if ( 'running' === ( $job['status'] ?? '' ) && ! self::get_next_worker_cron() ) {
			self::schedule_background_worker();
		}

		return self::normalize_job_for_response( $job, $deep_sync );
	}

	/**
	 * Ensure legacy and cursor-based job payloads expose a consistent shape.
	 *
	 * @param array<string, mixed> $job       Job data.
	 * @param bool                 $deep_sync When false, avoid full-site scans (polling reads).
	 * @return array<string, mixed>
	 */
	private static function normalize_job_for_response( array $job, $deep_sync = false ) {
		if ( ! empty( $job['queue'] ) && is_array( $job['queue'] ) && ! isset( $job['remaining'] ) ) {
			$job['remaining'] = count( $job['queue'] );
		}

		$lang = sanitize_key( (string) ( $job['lang'] ?? '' ) );

		if ( ! $deep_sync ) {
			return self::normalize_job_for_lightweight_response( $job, $lang );
		}

		if ( '' !== $lang && 'paused' === ( $job['status'] ?? '' ) ) {
			$job = self::reconcile_stale_job_state( $job, $lang );
		} elseif ( '' !== $lang && in_array( (string) ( $job['status'] ?? '' ), array( 'running', 'idle' ), true ) ) {
			$step_in_progress = absint( $job['current_post_id'] ?? 0 ) > 0
				&& (int) ( $job['step_started_at'] ?? 0 ) > 0
				&& ( time() - (int) $job['step_started_at'] ) < 600;

			if ( $step_in_progress ) {
				// Avoid full-site scans while a step is mid-flight (prevents lockups).
				$job = self::attach_job_metrics( $job, $lang, false );
			} else {
				Post_Translator::flush_translation_status_cache();
				$job = self::attach_job_metrics( $job, $lang );

				if ( in_array( (string) ( $job['status'] ?? '' ), array( 'running' ), true ) ) {
					$synced_at = (int) ( $job['remaining_synced_at'] ?? 0 );

					if ( time() - $synced_at >= 10 ) {
						self::sync_job_remaining( $job, $lang );
					}
				}
			}
		} elseif ( ! isset( $job['remaining'] ) ) {
			$job['remaining'] = max( 0, (int) ( $job['total'] ?? 0 ) - (int) ( $job['succeeded'] ?? 0 ) );
		}

		$job['remaining'] = max( 0, (int) $job['remaining'] );
		$job              = self::attach_job_metrics( $job, $lang );

		if ( '' !== $lang && 'completed' === ( $job['status'] ?? '' ) ) {
			$validated_at = (int) ( $job['validated_at'] ?? 0 );

			if ( time() - $validated_at < 30 && isset( $job['needs_work'] ) ) {
				return $job;
			}

			$live_remaining    = Translation_Query::count_untranslated_persian_posts( $lang ) + Menu_Translator::count_untranslated( $lang );
			$job['remaining']  = $live_remaining;
			$job['needs_work'] = $live_remaining;
			$job['validated_at'] = time();

			if ( $live_remaining > 0 ) {
				$job['outdated']   = true;
				$job['status']     = 'paused';
				$job['last_error'] = sprintf(
					/* translators: %d: remaining items */
					__( 'اجرای قبلی کامل نبود — %d مورد هنوز نیاز به ترجمه دارد. «شروع ترجمه خودکار» را بزنید.', 'polymart-ai' ),
					$live_remaining
				);
				self::save_job( $job );
			}
		}

		if ( '' !== $lang && 'paused' === ( $job['status'] ?? '' ) && ! empty( $job['stalled_ids'] ) && is_array( $job['stalled_ids'] ) ) {
			$job['stalled_details'] = array();

			foreach ( array_slice( array_map( 'absint', $job['stalled_ids'] ), 0, 5 ) as $stalled_id ) {
				if ( $stalled_id <= 0 ) {
					continue;
				}

				$gaps = Post_Translator::get_translation_gaps( $stalled_id, $lang );

				if ( 'translated' === ( $gaps['status'] ?? '' ) ) {
					continue;
				}

				$job['stalled_details'][] = array(
					'post_id' => $stalled_id,
					'title'   => get_the_title( $stalled_id ),
					'status'  => $gaps['status'] ?? 'partial',
					'missing' => $gaps['missing'],
					'fields'  => $gaps['fields'] ?? array(),
					'notes'   => $gaps['notes'] ?? array(),
				);
			}
		}

		return $job;
	}

	/**
	 * Shape job data for lightweight polling without full-site scans.
	 *
	 * @param array<string, mixed> $job  Job data.
	 * @param string               $lang Target language code.
	 * @return array<string, mixed>
	 */
	private static function normalize_job_for_lightweight_response( array $job, $lang ) {
		if ( ! isset( $job['remaining'] ) ) {
			$job['remaining'] = max( 0, (int) ( $job['total'] ?? 0 ) - (int) ( $job['succeeded'] ?? 0 ) );
		}

		$job['remaining'] = max( 0, (int) $job['remaining'] );

		if ( ! isset( $job['needs_work'] ) && isset( $job['live_stats'] ) && is_array( $job['live_stats'] ) ) {
			$job['needs_work'] = (int) ( $job['live_stats']['untranslated'] ?? 0 ) + (int) ( $job['live_stats']['partial'] ?? 0 );
		}

		$job = self::attach_job_metrics( $job, $lang, false );

		if ( '' !== $lang && 'paused' === ( $job['status'] ?? '' ) && ! empty( $job['stalled_ids'] ) && is_array( $job['stalled_ids'] ) ) {
			$job['stalled_details'] = array();

			foreach ( array_slice( array_map( 'absint', $job['stalled_ids'] ), 0, 5 ) as $stalled_id ) {
				if ( $stalled_id <= 0 ) {
					continue;
				}

				$gaps = Post_Translator::get_translation_gaps( $stalled_id, $lang );

				if ( 'translated' === ( $gaps['status'] ?? '' ) ) {
					continue;
				}

				$job['stalled_details'][] = array(
					'post_id' => $stalled_id,
					'title'   => get_the_title( $stalled_id ),
					'status'  => $gaps['status'] ?? 'partial',
					'missing' => $gaps['missing'],
					'fields'  => $gaps['fields'] ?? array(),
					'notes'   => $gaps['notes'] ?? array(),
				);
			}
		}

		return $job;
	}

	/**
	 * Drop stale pause state when live data shows nothing left to translate.
	 *
	 * @param array<string, mixed> $job  Job state.
	 * @param string               $lang Target language code.
	 * @return array<string, mixed>
	 */
	private static function reconcile_stale_job_state( array $job, $lang ) {
		Post_Translator::flush_translation_status_cache();

		$live_ids       = Translation_Query::collect_remaining_post_ids( $lang, 100 );
		$live_remaining = Translation_Query::count_untranslated_persian_posts( $lang );
		$stored_partial_ids    = is_array( $job['partial_ids'] ?? null ) ? array_map( 'absint', $job['partial_ids'] ) : array();
		$job['remaining']      = $live_remaining;
		$job['remaining_synced_at'] = time();
		$job['stalled_ids']    = array_values(
			array_filter(
				array_map( 'absint', $live_ids ),
				static function ( $post_id ) use ( $lang ) {
					return $post_id > 0 && 'translated' !== Post_Translator::get_translation_status( $post_id, $lang );
				}
			)
		);
		$job['partial_ids']    = array_values(
			array_filter(
				array_map( 'absint', $stored_partial_ids ),
				static function ( $post_id ) use ( $lang ) {
					return $post_id > 0 && 'translated' !== Post_Translator::get_translation_status( $post_id, $lang );
				}
			)
		);
		$job['retry_queue']    = array_values(
			array_filter(
				self::get_retry_queue( $job ),
				static function ( $post_id ) use ( $lang ) {
					return $post_id > 0 && 'translated' !== Post_Translator::get_translation_status( $post_id, $lang );
				}
			)
		);
		$job['deferred_queue'] = array_values(
			array_filter(
				self::get_deferred_queue( $job ),
				static function ( $post_id ) use ( $lang ) {
					return $post_id > 0 && 'translated' !== Post_Translator::get_translation_status( $post_id, $lang );
				}
			)
		);

		if ( $live_remaining <= 0 ) {
			$job['stalled_ids']   = array();
			$job['partial_ids']   = array();
			$job['retry_queue']   = array();
			$job['retry_attempts'] = array();
			$job['exhausted_ids'] = array();
			$job['remaining']     = 0;

			if ( 'paused' === ( $job['status'] ?? '' ) && 'critical' !== ( $job['pause_reason'] ?? '' ) ) {
				$job['status']       = 'completed';
				$job['last_error']    = null;
				$job['pause_reason']  = null;
				self::save_job( $job );
				self::log( 'success', __( 'ترجمه خودکار با موفقیت به پایان رسید.', 'polymart-ai' ) );
			}

			return $job;
		}

		if ( 'paused' === ( $job['status'] ?? '' ) ) {
			if ( empty( $job['stalled_ids'] ) ) {
				$job['last_error']   = null;
				$job['pause_reason'] = null;
				$job['status']       = $live_remaining > 0 ? 'idle' : 'completed';

				if ( 'completed' === $job['status'] ) {
					$job['remaining'] = 0;
				}
			} else {
				$job['last_error'] = self::format_stalled_job_error( $lang, $job['stalled_ids'] );
			}

			self::save_job( $job );
		}

		return $job;
	}

	/**
	 * Derive consistent progress counters for the admin UI.
	 *
	 * @param array<string, mixed> $job        Job data.
	 * @param string               $lang       Target language.
	 * @param bool                 $sync_live  Whether to refresh site-wide live stats.
	 * @return array<string, mixed>
	 */
	private static function attach_job_metrics( array $job, $lang = '', $sync_live = true ) {
		$lang      = sanitize_key( (string) $lang );
		$steps     = max( (int) ( $job['steps'] ?? 0 ), (int) ( $job['processed'] ?? 0 ) );
		$total     = max( 0, (int) ( $job['initial_total'] ?? $job['total'] ?? 0 ) );
		$failed    = max( 0, (int) ( $job['failed'] ?? 0 ) );
		$exhausted = count( self::get_exhausted_job_post_ids( $job ) );
		$remaining = max( 0, (int) ( $job['remaining'] ?? 0 ) );

		if ( '' !== $lang && $sync_live ) {
			self::sync_job_live_stats( $job, $lang );

			$reconciled_at = (int) ( $job['succeeded_reconciled_steps'] ?? -1 );

			if ( $reconciled_at !== $steps ) {
				$succeeded_count = count( is_array( $job['succeeded_ids'] ?? null ) ? $job['succeeded_ids'] : array() );

				// Full per-post audit on every succeeded ID is too slow on large catalogs.
				if ( $succeeded_count <= 50 || 0 === $steps % 10 ) {
					self::reconcile_succeeded_posts( $job, $lang );
				}

				$job['succeeded_reconciled_steps'] = $steps;
			}

			if ( in_array( (string) ( $job['status'] ?? '' ), array( 'running', 'paused' ), true ) ) {
				$job['remaining'] = (int) ( $job['needs_work'] ?? 0 );
			}

			// Keep the run budget open while the site still has actionable work.
			$needs_work = (int) ( $job['needs_work'] ?? 0 );
			if ( $needs_work > 0 && in_array( (string) ( $job['status'] ?? '' ), array( 'running', 'paused' ), true ) ) {
				$succeeded_now = max( 0, (int) ( $job['succeeded'] ?? 0 ) );
				$min_total     = $succeeded_now + $exhausted + $needs_work;
				if ( $min_total > $total ) {
					$total                = $min_total;
					$job['total']         = $total;
					$job['initial_total'] = max( (int) ( $job['initial_total'] ?? 0 ), $total );
				}
			}
		} elseif ( '' === $lang ) {
			$job['needs_work'] = $remaining;
		}

		$partial_ids = is_array( $job['partial_ids'] ?? null ) ? $job['partial_ids'] : array();
		$partial_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $partial_ids ),
					static function ( $post_id ) {
						return $post_id > 0;
					}
				)
			)
		);

		if ( '' !== $lang && $sync_live ) {
			$partial_ids = array_values(
				array_filter(
					$partial_ids,
					static function ( $post_id ) use ( $lang ) {
						return 'translated' !== Post_Translator::get_translation_status( $post_id, $lang );
					}
				)
			);
		}

		$succeeded = max( 0, (int) ( $job['succeeded'] ?? 0 ) );
		$partial   = count( $partial_ids );

		$retry_queue     = self::get_retry_queue( $job );
		$deferred_queue  = self::get_deferred_queue( $job );
		$exhausted_map   = array_fill_keys( self::get_exhausted_job_post_ids( $job ), true );
		$retry_pending   = count(
			array_filter(
				$retry_queue,
				static function ( $queued_id ) use ( $exhausted_map ) {
					return ! isset( $exhausted_map[ (int) $queued_id ] );
				}
			)
		);
		$deferred_pending = count(
			array_filter(
				$deferred_queue,
				static function ( $queued_id ) use ( $exhausted_map ) {
					return ! isset( $exhausted_map[ (int) $queued_id ] );
				}
			)
		);
		$run_pending = $retry_pending + $deferred_pending + $partial;

		$job['partial_ids']   = $partial_ids;
		$job['partial']       = $partial;
		$job['steps']         = $steps;
		$job['processed']     = $steps;
		$job['total']         = $total;
		$job['run_pending']   = $run_pending;
		$job['retry_pending'] = $retry_pending;
		$job['deferred_pending'] = $deferred_pending;

		$run_done = min( $total, $succeeded + $exhausted );
		$job['run_done']  = $run_done;
		$job['completed'] = $run_done;

		if ( $total > 0 ) {
			$job['progress_pct'] = min( 100, (int) round( ( $run_done / $total ) * 100 ) );
		} else {
			$job['progress_pct'] = 0;
		}

		$job['run_remaining'] = max( 0, $total - $run_done );

		// Site-wide progress (canonical monitor numbers).
		$initial_needs = max( 0, (int) ( $job['initial_needs_work'] ?? $total ) );
		$needs_work_now = max( 0, (int) ( $job['needs_work'] ?? $remaining ) );
		$site_resolved  = max( 0, (int) ( $job['site_resolved'] ?? max( 0, $initial_needs - $needs_work_now ) ) );
		$job['initial_needs_work'] = $initial_needs;
		$job['site_resolved']      = $site_resolved;
		$job['site_progress_pct']  = $initial_needs > 0
			? min( 100, (int) round( ( $site_resolved / $initial_needs ) * 100 ) )
			: ( $needs_work_now > 0 ? 0 : 100 );

		$parked_pending = count( self::get_parked_ids( $job ) );
		$pinned_partial = absint( $job['partial_post_id'] ?? 0 ) > 0 ? 1 : 0;
		$job['queue_backlog'] = $deferred_pending + $parked_pending + $retry_pending + $pinned_partial;
		$job['parked_pending'] = $parked_pending;

		$current_post_id = absint( $job['current_post_id'] ?? 0 );

		if ( $current_post_id <= 0 ) {
			$current_post_id = absint( $job['partial_post_id'] ?? 0 );
		}

		if ( $current_post_id > 0 ) {
			$job['current_post'] = array(
				'post_id' => $current_post_id,
				'title'   => get_the_title( $current_post_id ),
			);

			if ( ! empty( $job['partial_phase'] ) ) {
				$job['current_post']['partial_phase']    = (string) $job['partial_phase'];
				$job['current_post']['partial_progress'] = (string) ( $job['partial_progress'] ?? '' );
			}

			if ( is_array( $job['last_step'] ?? null ) && absint( $job['last_step']['post_id'] ?? 0 ) === $current_post_id ) {
				$job['current_post']['step_status']  = (string) ( $job['last_step']['status'] ?? '' );
				$job['current_post']['step_message'] = (string) ( $job['last_step']['message'] ?? '' );
			}
		} elseif ( is_array( $job['last_step'] ?? null ) && absint( $job['last_step']['post_id'] ?? 0 ) > 0 ) {
			// Between steps, surface the last completed/partial item so the monitor isn't blank.
			$job['current_post'] = array(
				'post_id'       => absint( $job['last_step']['post_id'] ),
				'title'         => (string) ( $job['last_step']['title'] ?? get_the_title( absint( $job['last_step']['post_id'] ) ) ),
				'step_status'   => (string) ( $job['last_step']['status'] ?? '' ),
				'step_message'  => (string) ( $job['last_step']['message'] ?? '' ),
				'from_last_step'=> true,
			);

			if ( ! empty( $job['partial_phase'] ) ) {
				$job['current_post']['partial_phase']    = (string) $job['partial_phase'];
				$job['current_post']['partial_progress'] = (string) ( $job['partial_progress'] ?? '' );
			}
		} else {
			$job['current_post'] = null;
		}

		$lock  = get_transient( self::STEP_LOCK_KEY );
		$claim = absint( get_option( self::STEP_LOCK_CLAIM_KEY, 0 ) );
		$stamp = $lock ? absint( $lock ) : $claim;
		$job['worker_lock']     = $stamp > 0;
		$job['worker_lock_age'] = $stamp > 0 ? max( 0, time() - $stamp ) : null;

		$live_current = absint( $job['current_post_id'] ?? 0 );
		if ( $job['worker_lock'] && $live_current > 0 ) {
			$job['worker_phase'] = 'translating';
		} elseif ( $job['worker_lock'] ) {
			// Lock held while scanning/picking the next queue item — normal, not stuck.
			$job['worker_phase'] = 'picking';
			if ( is_array( $job['current_post'] ?? null ) && ! empty( $job['current_post']['from_last_step'] ) ) {
				$job['current_post']['picking_next'] = true;
			}
		} elseif ( 'running' === ( $job['status'] ?? '' ) ) {
			$job['worker_phase'] = 'between';
		} else {
			$job['worker_phase'] = 'idle';
		}

		$next_cron = self::get_next_worker_cron();
		$job['cron_scheduled'] = (bool) $next_cron;
		$job['next_cron_at']   = $next_cron ? (int) $next_cron : null;
		$job['cron_disabled']  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$job['cron_pulse']     = (bool) wp_next_scheduled( self::CRON_PULSE_HOOK );
		$job['worker_alive']   = self::is_worker_heartbeat_fresh( $job );
		$job['recent_logs']    = self::get_recent_job_logs( $job, 60 );

		return $job;
	}

	/**
	 * Recent activity-log lines for the current job run (newest first).
	 *
	 * @param array<string, mixed> $job   Job state.
	 * @param int                  $limit Max entries.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_recent_job_logs( array $job, $limit = 60 ) {
		$limit   = max( 1, min( 100, absint( $limit ) ) );
		$started = absint( $job['started_at'] ?? 0 );
		$logs    = self::get_logs( 200 );
		$out     = array();

		foreach ( $logs as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$time = absint( $entry['time'] ?? 0 );

			if ( $started > 0 && $time > 0 && $time < ( $started - 30 ) ) {
				break;
			}

			$out[] = array(
				'id'      => (string) ( $entry['id'] ?? uniqid( 'log_', true ) ),
				'level'   => (string) ( $entry['level'] ?? 'info' ),
				'message' => (string) ( $entry['message'] ?? '' ),
				'time'    => $time,
			);

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Chain delay before the next worker event after a tick finishes.
	 * Keep this short — the step lock prevents overlap, not a long delay.
	 *
	 * @return int
	 */
	private static function get_cron_chain_delay_sec() {
		return max( 1, min( 5, (int) apply_filters( 'polymart_ai_job_cron_chain_delay_sec', self::CRON_INTERVAL_SEC ) ) );
	}

	/**
	 * Drop an orphaned step lock left by a killed/timed-out PHP process.
	 *
	 * IMPORTANT: Do NOT treat heartbeat silence alone as death while a post is
	 * actively translating — a single AI call can take up to ~165s. A lock with
	 * no current_post_id after a completed item is a between-tick deadlock and
	 * must be cleared sooner so cron/ensure can continue.
	 *
	 * @return bool True when a stale lock was cleared.
	 */
	private static function maybe_release_stale_step_lock() {
		$lock  = get_transient( self::STEP_LOCK_KEY );
		$claim = absint( get_option( self::STEP_LOCK_CLAIM_KEY, 0 ) );

		if ( ! $lock && $claim <= 0 ) {
			return false;
		}

		$lock_stamp = $lock ? absint( $lock ) : $claim;
		$lock_age   = time() - $lock_stamp;
		$job        = self::get_job_raw();
		$current    = absint( $job['current_post_id'] ?? 0 );
		$last       = max(
			absint( $job['last_cron_at'] ?? 0 ),
			absint( $job['worker_heartbeat_at'] ?? 0 ),
			$lock_stamp
		);
		$idle_for = $last > 0 ? ( time() - $last ) : PHP_INT_MAX;

		// Hard stop: no heartbeat/tick for a full lock TTL means the worker is dead,
		// even if current_post_id is still set (killed mid-request).
		$worker_dead = $idle_for >= self::STEP_LOCK_TTL;

		// Between items: lock held, no active post, and no completed tick recently.
		$idle_orphan = ( 0 === $current )
			&& $idle_for >= self::LOCK_IDLE_ORPHAN_SEC
			&& $lock_age >= self::LOCK_IDLE_ORPHAN_SEC;

		// Living worker stopped touching the lock for the full TTL.
		$ttl_expired = $lock_age >= self::STEP_LOCK_TTL;

		if ( ! $worker_dead && ! $idle_orphan && ! $ttl_expired ) {
			return false;
		}

		self::release_step_lock();

		$job['step_started_at'] = null;
		$job['current_post_id'] = null;
		self::save_job( $job );

		return true;
	}

	/**
	 * Force-clear the step lock when the job has been silent too long.
	 *
	 * Used by ensure/kick recovery so a stuck object-cache lock cannot block
	 * the queue for many minutes. Threshold stays above one max AI call so a
	 * healthy long translation is never stolen by the monitor.
	 *
	 * @param int|null $idle_sec Seconds of silence before forcing unlock.
	 * @return bool
	 */
	private static function force_release_step_lock_if_idle( $idle_sec = null ) {
		$idle_sec = null === $idle_sec
			? self::LOCK_FORCE_IDLE_SEC
			: max( self::LOCK_FORCE_IDLE_SEC, absint( $idle_sec ) );

		$job     = self::get_job_raw();
		$current = absint( $job['current_post_id'] ?? 0 );

		// Actively translating a known post — never force-unlock before full TTL.
		if ( $current > 0 ) {
			$idle_sec = max( $idle_sec, self::STEP_LOCK_TTL );
		}

		$last = max(
			absint( $job['last_cron_at'] ?? 0 ),
			absint( $job['worker_heartbeat_at'] ?? 0 )
		);
		$idle_for = $last > 0 ? ( time() - $last ) : PHP_INT_MAX;

		if ( $idle_for < $idle_sec && ( get_transient( self::STEP_LOCK_KEY ) || get_option( self::STEP_LOCK_CLAIM_KEY ) ) ) {
			// Still recent activity — only use normal stale logic.
			return self::maybe_release_stale_step_lock();
		}

		if ( $idle_for < $idle_sec ) {
			return false;
		}

		$had_lock = (bool) get_transient( self::STEP_LOCK_KEY ) || (bool) get_option( self::STEP_LOCK_CLAIM_KEY );
		self::release_step_lock();

		if ( $had_lock || $current > 0 ) {
			$job['step_started_at'] = null;
			$job['current_post_id'] = null;
			self::save_job( $job );

			return true;
		}

		return false;
	}

	/**
	 * Refresh the step lock timestamp so long AI calls do not look orphaned.
	 *
	 * @return void
	 */
	private static function touch_step_lock() {
		$now = time();

		if ( ! get_transient( self::STEP_LOCK_KEY ) && ! get_option( self::STEP_LOCK_CLAIM_KEY ) ) {
			return;
		}

		update_option( self::STEP_LOCK_CLAIM_KEY, (string) $now, false );
		set_transient( self::STEP_LOCK_KEY, $now, self::STEP_LOCK_TTL );
	}

	/**
	 * Persist a lightweight heartbeat while a tick is in flight.
	 *
	 * @return void
	 */
	private static function touch_worker_heartbeat() {
		$job = self::get_job_raw();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return;
		}

		$job['worker_heartbeat_at'] = time();
		$job['last_worker']         = wp_doing_cron() ? 'cron' : 'admin';
		// Avoid full merge side-effects during mid-step touches.
		$job['updated_at'] = time();
		update_option( self::JOB_OPTION, $job, false );
		self::touch_step_lock();
	}

	/**
	 * Whether the worker has checked in recently (heartbeat or completed tick).
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return bool
	 */
	private static function is_worker_heartbeat_fresh( array $job ) {
		$lock  = get_transient( self::STEP_LOCK_KEY );
		$claim = absint( get_option( self::STEP_LOCK_CLAIM_KEY, 0 ) );
		$stamp = max( $lock ? absint( $lock ) : 0, $claim );

		if ( $stamp > 0 ) {
			return ( time() - $stamp ) < self::STEP_LOCK_TTL;
		}

		$last = max(
			absint( $job['last_cron_at'] ?? 0 ),
			absint( $job['worker_heartbeat_at'] ?? 0 ),
			absint( $job['worker_scheduled_at'] ?? 0 )
		);

		if ( $last <= 0 ) {
			return false;
		}

		return ( time() - $last ) < self::LOCK_IDLE_ORPHAN_SEC;
	}

	/**
	 * Store the latest step summary for the admin UI.
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Post ID.
	 * @param string               $status  Step status slug.
	 * @param string               $message Human-readable message.
	 * @return void
	 */
	private static function set_job_last_step( array &$job, $post_id, $status, $message = '' ) {
		$post_id = absint( $post_id );
		$status  = sanitize_key( (string) $status );
		$message = sanitize_text_field( (string) $message );

		if ( 'partial' === $status && $post_id > 0 && '' !== (string) ( $job['lang'] ?? '' ) ) {
			$message = self::enrich_partial_step_message( $post_id, (string) $job['lang'], $message );
		}

		$job['last_step'] = array(
			'post_id' => $post_id,
			'title'   => $post_id > 0 ? get_the_title( $post_id ) : '',
			'status'  => $status,
			'message' => $message,
			'time'    => time(),
		);
	}

	/**
	 * Append gap details to partial auto-translate step messages.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @param string $message Base message.
	 * @return string
	 */
	private static function enrich_partial_step_message( $post_id, $lang, $message ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$message = trim( (string) $message );

		if ( $post_id <= 0 || '' === $lang ) {
			return $message;
		}

		$partial = Post_Translator::get_job_partial_state( $post_id, $lang );
		$phase   = sanitize_key( (string) ( $partial['phase'] ?? '' ) );

		if ( '' !== $phase && 'complete' !== $phase ) {
			$phase_label = $phase;

			if ( 'elementor' === $phase ) {
				$phase_label = 'Elementor';
			}

			$progress = self::format_job_partial_progress( $partial );

			if ( '' !== $progress ) {
				$message = trim( $message . ' [' . $phase_label . ' ' . $progress . ']' );
			}
		}

		$gaps    = Post_Translator::get_translation_gaps( $post_id, $lang );
		$missing = array();

		foreach ( $gaps['fields'] ?? array() as $field ) {
			if ( empty( $field['translated'] ) ) {
				$missing[] = (string) ( $field['label'] ?? '' );
			}
		}

		if ( empty( $missing ) && ! empty( $gaps['missing'] ) ) {
			$missing = array_map( 'strval', (array) $gaps['missing'] );
		}

		$missing = array_values( array_filter( array_unique( $missing ) ) );

		if ( ! empty( $missing ) ) {
			$message = trim(
				$message . ' — ' . sprintf(
					/* translators: %s: comma-separated missing field labels */
					__( 'باقی‌مانده: %s', 'polymart-ai' ),
					implode( ', ', array_slice( $missing, 0, 4 ) )
				)
			);
		}

		return $message;
	}

	/**
	 * Human-readable progress string from durable post partial state.
	 *
	 * @param array<string, mixed> $state Partial state.
	 * @return string
	 */
	private static function format_job_partial_progress( array $state ) {
		$phase = sanitize_key( (string) ( $state['phase'] ?? '' ) );

		if ( 'elementor' === $phase ) {
			$done  = count( is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array() );
			$total = max( $done, absint( $state['elementor_chunks_total'] ?? 0 ) );

			return $done . '/' . max( 1, $total );
		}

		$index = absint( $state['field_chunk_index'] ?? 0 );

		return $index > 0 ? (string) $index : '';
	}

	/**
	 * Whether a slice advanced durable progress for the current post.
	 *
	 * @param array<string, mixed> $job              Job state.
	 * @param string               $current_progress Progress marker from the slice.
	 * @return bool
	 */
	private static function job_slice_made_progress( array $job, $current_progress ) {
		$previous = (string) ( $job['partial_progress'] ?? '' );
		$current  = (string) $current_progress;

		return '' !== $current && $current !== $previous;
	}

	/**
	 * Track repeated attempts at the same progress marker and decide when to defer.
	 *
	 * @param array<string, mixed> $job              Job state (by reference).
	 * @param int                    $post_id          Post ID.
	 * @param string                 $current_progress Progress marker from the slice.
	 * @return bool True when the post should be deferred.
	 */
	private static function job_progress_stuck_should_defer( array &$job, $post_id, $current_progress ) {
		if ( self::job_slice_made_progress( $job, $current_progress ) ) {
			$job['stuck_progress_steps'] = 0;

			return false;
		}

		$job['stuck_progress_steps'] = absint( $job['stuck_progress_steps'] ?? 0 ) + 1;

		$limit = (int) apply_filters( 'polymart_ai_job_stuck_progress_limit', 6, $job, $post_id );

		return absint( $job['stuck_progress_steps'] ?? 0 ) >= max( 3, $limit );
	}

	/**
	 * Defer a post that keeps failing at the same partial progress.
	 *
	 * @param array<string, mixed> $job      Job state (by reference).
	 * @param int                  $post_id  Post ID.
	 * @param string               $lang     Language code.
	 * @param string               $phase    Phase key.
	 * @param string               $progress Progress marker.
	 * @return void
	 */
	private static function defer_job_post_stuck_slice( array &$job, $post_id, $lang, $phase, $progress ) {
		self::defer_job_post( $job, $post_id, $lang );
		$job['partial_post_id']        = null;
		$job['partial_phase']          = null;
		$job['partial_progress']       = null;
		$job['consecutive_post_id']    = 0;
		$job['consecutive_post_steps'] = 0;
		$job['stuck_progress_steps']   = 0;
		$job['current_post_id']        = null;
		$job['step_started_at']        = null;
		self::increment_job_step( $job );
		self::set_job_last_step(
			$job,
			$post_id,
			'deferred',
			sprintf(
				/* translators: 1: post title, 2: phase label, 3: progress marker */
				__( '«%1$s» موقتاً کنار گذاشته شد (%2$s %3$s) — ادامه بقیه صف', 'polymart-ai' ),
				get_the_title( $post_id ),
				$phase,
				$progress
			)
		);
	}

	/**
	 * Sync remaining count from the database after a step.
	 *
	 * @param array<string, mixed> $job  Job state (by reference).
	 * @param string               $lang Target language code.
	 * @return void
	 */
	private static function sync_job_remaining( array &$job, $lang, $force = false ) {
		self::sync_job_live_stats( $job, $lang, $force );
		$job['remaining_synced_at'] = time();
	}

	/**
	 * Refresh site-wide translation stats stored on the job for the admin UI.
	 *
	 * @param array<string, mixed> $job       Job state (by reference).
	 * @param string               $lang      Target language code.
	 * @param bool                 $force     Skip throttling and recompute immediately.
	 * @param bool                 $force_full Run a full per-post audit (refresh_stats only).
	 * @return void
	 */
	private static function sync_job_live_stats( array &$job, $lang, $force = false, $force_full = false ) {
		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			return;
		}

		$steps        = max( (int) ( $job['steps'] ?? 0 ), (int) ( $job['processed'] ?? 0 ) );
		$synced_at    = (int) ( $job['live_stats_at'] ?? 0 );
		$synced_steps = (int) ( $job['live_stats_steps'] ?? -1 );

		if ( ! $force && $synced_steps === $steps && ( time() - $synced_at ) < 15 ) {
			return;
		}

		if ( $force ) {
			Post_Translator::flush_translation_status_cache();
			REST_API::invalidate_stats_cache();
		}

		$stats = Translation_Query::compute_translation_stats( $lang, $force_full );

		$job['live_stats'] = array(
			'total'        => (int) $stats['total'],
			'untranslated' => (int) $stats['untranslated'],
			'partial'      => (int) $stats['partial'],
			'translated'   => (int) $stats['translated'],
		);
		$job['live_stats_at']      = time();
		$job['live_stats_steps']   = $steps;
		$job['needs_work']         = (int) $stats['untranslated'] + (int) $stats['partial'] + Menu_Translator::count_untranslated( $lang );

		if ( ! isset( $job['initial_needs_work'] ) || (int) $job['initial_needs_work'] <= 0 ) {
			$job['initial_needs_work'] = max( (int) ( $job['initial_total'] ?? 0 ), (int) $job['needs_work'] );
		}

		$job['site_resolved'] = max( 0, (int) $job['initial_needs_work'] - (int) $job['needs_work'] );
	}

	/**
	 * Increment step counter for one translation attempt.
	 *
	 * @param array<string, mixed> $job Job state (by reference).
	 * @return void
	 */
	private static function increment_job_step( array &$job ) {
		$job['steps']     = (int) ( $job['steps'] ?? $job['processed'] ?? 0 ) + 1;
		$job['processed'] = $job['steps'];
	}

	/**
	 * Whether the job exceeded its retry budget for this run.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return bool
	 */
	private static function job_exceeded_step_budget( array $job ) {
		if ( absint( $job['partial_post_id'] ?? 0 ) > 0 ) {
			return false;
		}

		$needs_work = (int) ( $job['needs_work'] ?? $job['remaining'] ?? 0 );

		if ( $needs_work > 0 ) {
			return false;
		}

		$total  = max( 1, (int) ( $job['total'] ?? 0 ) );
		$steps  = max( (int) ( $job['steps'] ?? 0 ), (int) ( $job['processed'] ?? 0 ) );
		$budget = (int) apply_filters(
			'polymart_ai_job_step_budget',
			( $total * 8 ) + 100,
			$job
		);

		return $steps >= max( 1, $budget );
	}

	/**
	 * Max retry attempts before a post is skipped for this job run.
	 *
	 * Variable products need many HTTP slices; give them a higher budget than pages.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return int
	 */
	private static function get_max_job_retries_for_post( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$max     = self::MAX_JOB_RETRIES;

		if ( $post_id <= 0 ) {
			return $max;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return (int) apply_filters( 'polymart_ai_job_max_retries', $max, $post_id, $lang );
		}

		$product = wc_get_product( $post_id );

		if ( ! $product instanceof \WC_Product ) {
			return (int) apply_filters( 'polymart_ai_job_max_retries', $max, $post_id, $lang );
		}

		if ( $product->is_type( 'variable' ) ) {
			$variation_count = count( $product->get_children() );
			$max             = max( $max, 3 + (int) ceil( $variation_count / 4 ) );
		}

		/**
		 * Filter per-post retry budget for auto-translate jobs.
		 *
		 * @param int    $max     Computed retry cap.
		 * @param int    $post_id Post ID.
		 * @param string $lang    Target language code.
		 */
		return (int) apply_filters( 'polymart_ai_job_max_retries', $max, $post_id, $lang );
	}

	/**
	 * Durable completeness check used by the auto-translate job.
	 *
	 * Combines audit status with a fresh collect of pending source fields so
	 * in-memory-only caches cannot inflate the succeeded counter.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return string translated|partial|untranslated
	 */
	private static function resolve_post_job_status( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		Post_Translator::flush_translation_status_cache( $post_id );

		$status = Post_Translator::get_translation_status( $post_id, $lang );

		if ( 'translated' !== $status ) {
			return $status;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return 'translated';
		}

		$pending = Post_Translator::collect_persian_fields( $post, $lang );

		if ( ! empty( $pending ) ) {
			return 'partial';
		}

		return 'translated';
	}

	/**
	 * Remember a post that is durably fully translated in this run.
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Post ID.
	 * @return void
	 */
	private static function track_succeeded_post( array &$job, $post_id ) {
		$post_id = absint( $post_id );
		$ids     = is_array( $job['succeeded_ids'] ?? null ) ? $job['succeeded_ids'] : array();

		if ( $post_id > 0 && ! in_array( $post_id, $ids, true ) ) {
			$ids[] = $post_id;
		}

		$job['succeeded_ids'] = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$job['succeeded']     = count( $job['succeeded_ids'] );
	}

	/**
	 * Whether this post was already counted as fully translated in the current run.
	 *
	 * @param array<string, mixed> $job     Job state.
	 * @param int                  $post_id Post ID.
	 * @return bool
	 */
	private static function job_already_counted_success( array $job, $post_id ) {
		$post_id = absint( $post_id );
		$ids     = is_array( $job['succeeded_ids'] ?? null ) ? $job['succeeded_ids'] : array();

		return $post_id > 0 && in_array( $post_id, array_map( 'absint', $ids ), true );
	}

	/**
	 * Skip AI work when the post is already durably translated; advance the cursor.
	 *
	 * @param array<string, mixed> $job     Job state.
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @return array<string, mixed>
	 */
	private static function advance_past_already_translated_post( array $job, $post_id, $lang ) {
		$post_id = absint( $post_id );
		$title   = get_the_title( $post_id ) ?: ( '#' . $post_id );

		Post_Translator::clear_job_partial_state( $post_id, $lang );
		Post_Translator::release_translation_lock( $post_id, $lang );

		$was_new = ! self::job_already_counted_success( $job, $post_id );
		self::track_succeeded_post( $job, $post_id );
		self::clear_partial_post( $job, $post_id );

		$job['partial_post_id']   = null;
		$job['partial_phase']     = null;
		$job['partial_progress']  = null;
		$job['current_post_id']   = null;
		$job['step_started_at']   = null;
		$job['last_post_id']      = max( (int) ( $job['last_post_id'] ?? 0 ), $post_id );
		$job['last_error']        = null;

		self::increment_job_step( $job );

		if ( $was_new ) {
			self::log(
				'success',
				sprintf(
					/* translators: 1: post title, 2: language label */
					__( '«%1$s» به %2$s ترجمه شد.', 'polymart-ai' ),
					$title,
					$job['lang_label'] ?? $lang
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);
			self::set_job_last_step(
				$job,
				$post_id,
				'translated',
				sprintf(
					/* translators: 1: post title, 2: language label */
					__( '«%1$s» به %2$s ترجمه شد.', 'polymart-ai' ),
					$title,
					$job['lang_label'] ?? $lang
				)
			);
		} else {
			self::set_job_last_step(
				$job,
				$post_id,
				'skipped',
				sprintf(
					/* translators: %s: post title */
					__( '«%s» از قبل در موفق‌های این اجرا بود — رفتیم سراغ بعدی.', 'polymart-ai' ),
					$title
				)
			);
		}

		self::sync_job_remaining( $job, $lang );
		self::save_job( $job );

		return $job;
	}

	/**
	 * Remove a post from the durable success list.
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Post ID.
	 * @return void
	 */
	private static function untrack_succeeded_post( array &$job, $post_id ) {
		$post_id = absint( $post_id );

		$job['succeeded_ids'] = array_values(
			array_filter(
				is_array( $job['succeeded_ids'] ?? null ) ? $job['succeeded_ids'] : array(),
				static function ( $queued_id ) use ( $post_id ) {
					return (int) $queued_id !== $post_id;
				}
			)
		);
		$job['succeeded'] = count( $job['succeeded_ids'] );
	}

	/**
	 * Re-check previously succeeded posts against durable storage.
	 *
	 * @param array<string, mixed> $job  Job state (by reference).
	 * @param string               $lang Target language code.
	 * @return void
	 */
	private static function reconcile_succeeded_posts( array &$job, $lang ) {
		$lang = sanitize_key( (string) $lang );
		$ids  = is_array( $job['succeeded_ids'] ?? null ) ? $job['succeeded_ids'] : array();

		// Legacy jobs only stored a counter — trust live site progress instead.
		if ( empty( $ids ) && (int) ( $job['succeeded'] ?? 0 ) > 0 ) {
			$site_resolved = max( 0, (int) ( $job['site_resolved'] ?? 0 ) );
			$job['succeeded'] = min( (int) $job['succeeded'], max( $site_resolved, 0 ) );

			return;
		}

		$still_ok = array();

		foreach ( $ids as $post_id ) {
			$post_id = absint( $post_id );

			if ( $post_id <= 0 ) {
				continue;
			}

			if ( 'translated' === self::resolve_post_job_status( $post_id, $lang ) ) {
				$still_ok[] = $post_id;
			} else {
				self::track_partial_post( $job, $post_id );
			}
		}

		$job['succeeded_ids'] = array_values( array_unique( $still_ok ) );
		$job['succeeded']     = count( $job['succeeded_ids'] );
	}

	/**
	 * Track a post that finished partial in this job run.
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Post ID.
	 * @return void
	 */
	private static function track_partial_post( array &$job, $post_id ) {
		$post_id     = absint( $post_id );
		$partial_ids = is_array( $job['partial_ids'] ?? null ) ? $job['partial_ids'] : array();

		if ( $post_id > 0 && ! in_array( $post_id, $partial_ids, true ) ) {
			$partial_ids[] = $post_id;
		}

		$job['partial_ids'] = $partial_ids;
		$job['partial']     = count( $partial_ids );
	}

	/**
	 * Remove a post from the partial tracker after a full translation.
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Post ID.
	 * @return void
	 */
	private static function clear_partial_post( array &$job, $post_id ) {
		$post_id = absint( $post_id );

		$job['partial_ids'] = array_values(
			array_filter(
				is_array( $job['partial_ids'] ?? null ) ? $job['partial_ids'] : array(),
				static function ( $queued_id ) use ( $post_id ) {
					return (int) $queued_id !== $post_id;
				}
			)
		);
		$job['partial'] = count( $job['partial_ids'] );
		self::remove_post_from_deferred_queue( $job, $post_id );
		$parked = self::get_parked_ids( $job );
		$job['parked_ids'] = array_values(
			array_filter(
				$parked,
				static function ( $queued_id ) use ( $post_id ) {
					return (int) $queued_id !== $post_id;
				}
			)
		);
	}

	/**
	 * Remove a post from the deferred (heavy/partial) queue.
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Post ID.
	 * @return void
	 */
	private static function remove_post_from_deferred_queue( array &$job, $post_id ) {
		$post_id = absint( $post_id );

		$job['deferred_queue'] = array_values(
			array_filter(
				self::get_deferred_queue( $job ),
				static function ( $queued_id ) use ( $post_id ) {
					return (int) $queued_id !== $post_id;
				}
			)
		);
	}

	/**
	 * Persist translation job state.
	 *
	 * @param array<string, mixed> $job Job data.
	 * @return array<string, mixed>
	 */
	public static function save_job( array $job ) {
		$fresh = get_option( self::JOB_OPTION, array() );

		// Same run: merge durable progress so concurrent ticks cannot wipe each other.
		if (
			is_array( $fresh )
			&& ! empty( $fresh )
			&& absint( $job['started_at'] ?? 0 ) > 0
			&& absint( $job['started_at'] ?? 0 ) === absint( $fresh['started_at'] ?? 0 )
			&& 'idle' !== ( $job['status'] ?? '' )
		) {
			$job_ids   = is_array( $job['succeeded_ids'] ?? null ) ? $job['succeeded_ids'] : array();
			$fresh_ids = is_array( $fresh['succeeded_ids'] ?? null ) ? $fresh['succeeded_ids'] : array();
			$merged    = array_values(
				array_unique(
					array_filter(
						array_map( 'absint', array_merge( $fresh_ids, $job_ids ) )
					)
				)
			);
			$job['succeeded_ids'] = $merged;
			$job['succeeded']     = count( $merged );
			$job['steps']         = max( (int) ( $job['steps'] ?? 0 ), (int) ( $fresh['steps'] ?? 0 ) );
			$job['processed']     = max( (int) ( $job['processed'] ?? 0 ), (int) ( $fresh['processed'] ?? 0 ), (int) $job['steps'] );

			foreach ( array( 'exhausted_ids', 'partial_ids' ) as $list_key ) {
				$job_list   = is_array( $job[ $list_key ] ?? null ) ? $job[ $list_key ] : array();
				$fresh_list = is_array( $fresh[ $list_key ] ?? null ) ? $fresh[ $list_key ] : array();
				$job[ $list_key ] = array_values(
					array_unique(
						array_filter(
							array_map( 'absint', array_merge( $fresh_list, $job_list ) )
						)
					)
				);
			}

			// Prefer the more advanced writer's queues so removals are not resurrected.
			$job_steps   = (int) ( $job['steps'] ?? 0 );
			$fresh_steps = (int) ( $fresh['steps'] ?? 0 );

			if ( $fresh_steps > $job_steps ) {
				foreach ( array( 'deferred_queue', 'retry_queue' ) as $queue_key ) {
					if ( is_array( $fresh[ $queue_key ] ?? null ) ) {
						$job[ $queue_key ] = array_values(
							array_unique(
								array_filter( array_map( 'absint', $fresh[ $queue_key ] ) )
							)
						);
					}
				}

				if ( empty( $job['partial_post_id'] ) && absint( $fresh['partial_post_id'] ?? 0 ) > 0 ) {
					$job['partial_post_id'] = absint( $fresh['partial_post_id'] );
				}
			} else {
				foreach ( array( 'deferred_queue', 'retry_queue' ) as $queue_key ) {
					if ( is_array( $job[ $queue_key ] ?? null ) ) {
						$job[ $queue_key ] = array_values(
							array_unique(
								array_filter( array_map( 'absint', $job[ $queue_key ] ) )
							)
						);
					}
				}
			}

			$job_attempts   = is_array( $job['retry_attempts'] ?? null ) ? $job['retry_attempts'] : array();
			$fresh_attempts = is_array( $fresh['retry_attempts'] ?? null ) ? $fresh['retry_attempts'] : array();
			$merged_attempts = $fresh_attempts;

			foreach ( $job_attempts as $post_key => $attempts ) {
				$post_key = (string) absint( $post_key );
				$merged_attempts[ $post_key ] = max(
					absint( $merged_attempts[ $post_key ] ?? 0 ),
					absint( $attempts )
				);
			}

			$job['retry_attempts'] = $merged_attempts;
		}

		$job['updated_at'] = time();
		update_option( self::JOB_OPTION, $job, false );

		return $job;
	}

	/**
	 * Start a new auto-translation job.
	 *
	 * @param string $lang Target language code.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function start_job( $lang ) {
		$lang = sanitize_key( (string) $lang );

		if ( ! REST_API::is_ai_configured() ) {
			return new \WP_Error(
				'polymart_ai_missing_credentials',
				__( 'کلید API یا آدرس AI Gateway در تنظیمات ترجمه تنظیم نشده است.', 'polymart-ai' )
			);
		}

		$language = Language_Registry::get_language( $lang );

		if ( ! $language || empty( $language['enabled'] ) ) {
			return new \WP_Error(
				'polymart_ai_invalid_language',
				__( 'زبان مقصد انتخاب‌شده فعال نیست.', 'polymart-ai' )
			);
		}

		$existing = self::get_job( false );

		if ( 'running' === ( $existing['status'] ?? '' ) ) {
			return new \WP_Error(
				'polymart_ai_job_running',
				__( 'یک فرآیند ترجمه خودکار در حال اجراست. ابتدا آن را متوقف یا از سرگیری کنید.', 'polymart-ai' )
			);
		}

		// Drop leftover locks / partial meta from a previous paused/completed run.
		self::cleanup_job_translation_locks( $existing );

		Post_Translator::flush_translation_status_cache();
		REST_API::invalidate_stats_cache();

		$stats      = Translation_Query::compute_translation_stats( $lang, false );
		$menu_needs = Menu_Translator::count_untranslated( $lang );
		$needs_work = (int) $stats['untranslated'] + (int) $stats['partial'] + $menu_needs;
		$total      = $needs_work;

		if ( $total <= 0 ) {
			$probe_post = Translation_Query::find_next_untranslated_post_id( $lang, 0 );

			if ( $probe_post <= 0 && $menu_needs <= 0 ) {
				return new \WP_Error(
					'polymart_ai_no_queue',
					__( 'مورد ترجمه‌نشده‌ای برای این زبان یافت نشد.', 'polymart-ai' )
				);
			}

			$needs_work = max( 1, $menu_needs );
			$total      = $needs_work;
		}

		$language   = Language_Registry::get_language( $lang );
		$lang_label = $language ? $language['native_name'] : $lang;

		$job = array(
			'status'             => 'running',
			'lang'               => $lang,
			'lang_label'         => $lang_label,
			'last_post_id'       => 0,
			'queue'              => array(),
			'retry_queue'        => array(),
			'deferred_queue'     => array(),
			'retry_attempts'     => array(),
			'remaining'          => $total,
			'total'              => $total,
			'initial_total'      => $total,
			'initial_needs_work' => $needs_work,
			'needs_work'         => $needs_work,
			'site_resolved'      => 0,
			'live_stats'         => array(
				'total'        => (int) $stats['total'],
				'untranslated' => (int) $stats['untranslated'],
				'partial'      => (int) $stats['partial'],
				'translated'   => (int) $stats['translated'],
			),
			'live_stats_at'      => time(),
			'live_stats_steps'   => 0,
			'processed'       => 0,
			'steps'           => 0,
			'succeeded'       => 0,
			'succeeded_ids'   => array(),
			'partial'         => 0,
			'partial_ids'     => array(),
			'exhausted_ids'   => array(),
			'failed'          => 0,
			'skipped'         => 0,
			'current_post_id' => null,
			'last_error'      => null,
			'started_at'      => time(),
			'updated_at'      => time(),
			'phase'           => 'posts',
			'last_menu_id'    => 0,
			'menu_remaining'  => $menu_needs,
		);

		self::save_job( $job );
		self::bootstrap_background_worker();
		self::log(
			'info',
			sprintf(
				/* translators: 1: language name, 2: count */
				__( 'ترجمه خودکار به %1$s شروع شد — %2$d مورد در صف (کارگر کرون).', 'polymart-ai' ),
				$lang_label,
				$total
			),
			array( 'lang' => $lang, 'total' => $total )
		);

		return self::normalize_job_for_response( self::get_job_raw(), false );
	}

	/**
	 * Pause the running job.
	 *
	 * @return array<string, mixed>
	 */
	public static function pause_job() {
		$job = self::get_job_raw();

		if ( 'running' === ( $job['status'] ?? '' ) ) {
			$lang       = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
			$partial_id = absint( $job['partial_post_id'] ?? 0 );
			$current_id = absint( $job['current_post_id'] ?? 0 );

			// Release locks so manual translate / next resume is not blocked,
			// but keep partial meta so resume can continue mid-slice.
			foreach ( array_unique( array_filter( array( $partial_id, $current_id ) ) ) as $post_id ) {
				if ( $post_id > 0 && '' !== $lang ) {
					Post_Translator::release_translation_lock( $post_id, $lang );
				}
			}

			$job['status']          = 'paused';
			$job['current_post_id'] = null;
			$job['step_started_at'] = null;
			self::save_job( $job );
			self::release_step_lock();
			self::unschedule_background_worker();
			self::log( 'warning', __( 'ترجمه خودکار متوقف موقت شد.', 'polymart-ai' ) );
		}

		return self::normalize_job_for_response( $job, false );
	}

	/**
	 * Resume a paused job.
	 *
	 * @return array<string, mixed>
	 */
	public static function resume_job() {
		$job = self::get_job_raw();
		$job = wp_parse_args( $job, self::empty_job() );

		if ( in_array( $job['status'], array( 'paused', 'running' ), true ) ) {
			$job['status']          = 'running';
			$job['last_error']      = null;
			$job['pause_reason']    = null;
			$job['current_post_id'] = null;
			$job['step_started_at'] = null;

			self::save_job( $job );
			self::bootstrap_background_worker();
			self::log( 'info', __( 'ترجمه خودکار از سر گرفته شد (کارگر کرون).', 'polymart-ai' ) );
		}

		return self::normalize_job_for_response( self::get_job_raw(), false );
	}

	/**
	 * Stop and clear the job.
	 *
	 * @return array<string, mixed>
	 */
	public static function stop_job() {
		$job = self::get_job_raw();

		if ( 'idle' !== ( $job['status'] ?? '' ) ) {
			self::cleanup_job_translation_locks( $job, true );
			self::log( 'warning', __( 'ترجمه خودکار توسط کاربر متوقف شد.', 'polymart-ai' ) );
		} else {
			self::release_step_lock();
		}

		self::unschedule_background_worker();

		return self::save_job( self::empty_job() );
	}

	/**
	 * Skip the current (or specified) post and continue with the next queue item.
	 *
	 * @param int $post_id Optional post ID; falls back to partial/current/last step.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function skip_current_job_post( $post_id = 0 ) {
		$job  = self::get_job_raw();
		$lang = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

		if ( '' === $lang ) {
			$lang = 'en';
		}

		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			$post_id = absint( $job['partial_post_id'] ?? 0 );
		}

		if ( $post_id <= 0 ) {
			$post_id = absint( $job['current_post_id'] ?? 0 );
		}

		if ( $post_id <= 0 && is_array( $job['last_step'] ?? null ) ) {
			$post_id = absint( $job['last_step']['post_id'] ?? 0 );
		}

		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'polymart_ai_no_skip_target',
				__( 'موردی برای رد کردن مشخص نیست.', 'polymart-ai' )
			);
		}

		Post_Translator::clear_job_partial_state( $post_id, $lang );
		Post_Translator::release_translation_lock( $post_id, $lang );
		self::release_step_lock();

		$exhausted = self::get_exhausted_job_post_ids( $job );

		if ( ! in_array( $post_id, $exhausted, true ) ) {
			$exhausted[] = $post_id;
		}

		$job['exhausted_ids'] = array_values( array_unique( array_map( 'absint', $exhausted ) ) );

		self::remove_post_from_deferred_queue( $job, $post_id );

		$job['retry_queue'] = array_values(
			array_filter(
				self::get_retry_queue( $job ),
				static function ( $queued_id ) use ( $post_id ) {
					return (int) $queued_id !== $post_id;
				}
			)
		);

		$job['parked_ids'] = array_values(
			array_filter(
				self::get_parked_ids( $job ),
				static function ( $queued_id ) use ( $post_id ) {
					return (int) $queued_id !== $post_id;
				}
			)
		);

		$job['partial_ids'] = array_values(
			array_filter(
				is_array( $job['partial_ids'] ?? null ) ? $job['partial_ids'] : array(),
				static function ( $queued_id ) use ( $post_id ) {
					return (int) $queued_id !== $post_id;
				}
			)
		);
		$job['partial'] = count( $job['partial_ids'] );

		if ( isset( $job['retry_attempts'][ $post_id ] ) ) {
			unset( $job['retry_attempts'][ $post_id ] );
		}

		if ( isset( $job['post_step_counts'][ $post_id ] ) ) {
			unset( $job['post_step_counts'][ $post_id ] );
		}

		$job['last_post_id']           = $post_id;
		$job['partial_post_id']        = null;
		$job['partial_phase']          = null;
		$job['partial_progress']       = null;
		$job['current_post_id']        = null;
		$job['step_started_at']        = null;
		$job['consecutive_post_id']    = 0;
		$job['consecutive_post_steps'] = 0;
		$job['stuck_progress_steps']   = 0;
		$job['last_error']             = null;
		$job['pause_reason']           = null;
		$job['skipped']                = absint( $job['skipped'] ?? 0 ) + 1;

		if ( in_array( $job['status'] ?? '', array( 'paused' ), true ) ) {
			$job['status'] = 'running';
		}

		self::set_job_last_step(
			$job,
			$post_id,
			'skipped',
			__( 'این مورد رد شد — ادامه با مورد بعدی.', 'polymart-ai' )
		);

		self::sync_job_remaining( $job, $lang );
		self::save_job( $job );

		if ( 'running' === ( $job['status'] ?? '' ) ) {
			self::schedule_background_worker();
		}

		self::log(
			'warning',
			sprintf(
				/* translators: 1: post ID, 2: post title */
				__( 'مورد #%1$d «%2$s» رد شد — ادامه با مورد بعدی.', 'polymart-ai' ),
				$post_id,
				get_the_title( $post_id ) ?: '#' . $post_id
			),
			array( 'post_id' => $post_id, 'lang' => $lang )
		);

		return self::normalize_job_for_response( $job, false );
	}

	/**
	 * Force a live recomputation of site-wide translation stats for the admin UI.
	 *
	 * @param string $lang Target language code.
	 * @return array<string, mixed>
	 */
	public static function refresh_job_stats( $lang = '' ) {
		$lang = sanitize_key( (string) $lang );

		Post_Translator::flush_translation_status_cache();
		REST_API::invalidate_stats_cache();

		$job = wp_parse_args( self::get_job_raw(), self::empty_job() );

		if ( '' === $lang ) {
			$lang = sanitize_key( (string) ( $job['lang'] ?? '' ) );
		}

		if ( '' === $lang ) {
			$lang = 'en';
		}

		Post_Translator::reconcile_all_flagged_translation_indexes( $lang );
		self::sync_job_live_stats( $job, $lang, true, true );

		if ( sanitize_key( (string) ( $job['lang'] ?? '' ) ) === $lang ) {
			$job = self::reconcile_stale_job_state( $job, $lang );
		}

		self::save_job( $job );

		return self::normalize_job_for_response( $job, true );
	}

	/**
	 * Release per-post locks and optional partial meta for a job snapshot.
	 *
	 * @param array<string, mixed> $job          Job state.
	 * @param bool                 $clear_partial Whether to clear resume meta.
	 * @return void
	 */
	private static function cleanup_job_translation_locks( array $job, $clear_partial = true ) {
		$lang       = sanitize_key( (string) ( $job['lang'] ?? '' ) );
		$partial_id = absint( $job['partial_post_id'] ?? 0 );
		$current_id = absint( $job['current_post_id'] ?? 0 );

		foreach ( array_unique( array_filter( array( $partial_id, $current_id ) ) ) as $post_id ) {
			if ( $post_id <= 0 || '' === $lang ) {
				continue;
			}

			if ( $clear_partial ) {
				Post_Translator::clear_job_partial_state( $post_id, $lang );
			}

			Post_Translator::release_translation_lock( $post_id, $lang );
		}

		self::release_step_lock();
	}

	/**
	 * Acquire a global lock so only one job step runs at a time.
	 *
	 * Uses an atomic add_option claim so two cron/REST workers cannot both win
	 * under load or object-cache replication lag.
	 *
	 * @return bool
	 */
	private static function acquire_step_lock() {
		self::maybe_release_stale_step_lock();

		$now    = time();
		$claim  = absint( get_option( self::STEP_LOCK_CLAIM_KEY, 0 ) );
		$lock   = get_transient( self::STEP_LOCK_KEY );
		$stamp  = max( $claim, $lock ? absint( $lock ) : 0 );

		if ( $stamp > 0 && ( $now - $stamp ) < self::STEP_LOCK_TTL ) {
			// Mirror transient for UI if claim exists alone.
			if ( ! $lock && $claim > 0 ) {
				set_transient( self::STEP_LOCK_KEY, $claim, self::STEP_LOCK_TTL );
			}

			return false;
		}

		if ( $stamp > 0 ) {
			delete_option( self::STEP_LOCK_CLAIM_KEY );
			delete_transient( self::STEP_LOCK_KEY );
		}

		// add_option is atomic in MySQL — only one worker wins.
		if ( ! add_option( self::STEP_LOCK_CLAIM_KEY, (string) $now, '', 'no' ) ) {
			return false;
		}

		set_transient( self::STEP_LOCK_KEY, $now, self::STEP_LOCK_TTL );

		return true;
	}

	/**
	 * Next scheduled worker timestamp (chain or recurring pulse).
	 *
	 * @return int|false
	 */
	private static function get_next_worker_cron() {
		$times = array_filter(
			array(
				wp_next_scheduled( self::CRON_HOOK ),
				wp_next_scheduled( self::CRON_PULSE_HOOK ),
			)
		);

		return empty( $times ) ? false : min( $times );
	}

	/**
	 * Keep a recurring one-minute pulse alive for the whole job run.
	 *
	 * Independent of the admin UI and of the fast single-event chain.
	 *
	 * @return void
	 */
	private static function ensure_recurring_pulse() {
		if ( ! self::is_bulk_job_running() ) {
			return;
		}

		if ( wp_next_scheduled( self::CRON_PULSE_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + 5, self::CRON_PULSE_SCHEDULE, self::CRON_PULSE_HOOK );
	}

	/**
	 * Clear only the fast single-event chain (never the recurring pulse).
	 *
	 * @return void
	 */
	private static function clear_chain_events() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Schedule the next server-side job step (survives closed browser tabs).
	 *
	 * Always maintains the recurring pulse; optionally adds a near-term single event
	 * so work continues within seconds when loopback/crontab cooperates.
	 *
	 * @param int $delay_sec Optional delay before the next tick.
	 * @return void
	 */
	public static function schedule_background_worker( $delay_sec = null ) {
		self::ensure_recurring_pulse();

		$next  = wp_next_scheduled( self::CRON_HOOK );
		$delay = null === $delay_sec ? self::CRON_INTERVAL_SEC : max( 0, absint( $delay_sec ) );
		$want  = time() + $delay;

		if ( $next ) {
			if ( $next <= time() ) {
				self::clear_chain_events();
			} elseif ( $next <= $want + 15 && $next >= $want - 5 ) {
				self::ping_wp_cron();
				self::spawn_job_loopback();

				return;
			} elseif ( $next <= time() + 30 && $delay >= self::CRON_SAFETY_SEC ) {
				self::ping_wp_cron();
				self::spawn_job_loopback();

				return;
			} else {
				self::clear_chain_events();
			}
		}

		wp_schedule_single_event( $want, self::CRON_HOOK );
		self::ping_wp_cron();
		self::spawn_job_loopback( 0 === $delay );
	}

	/**
	 * Nudge WP-Cron so the job worker starts without waiting for page traffic.
	 *
	 * Rate-limited: spawning wp-cron on every admin/REST poll was stacking
	 * concurrent AI workers and freezing the whole site (~60s page loads).
	 *
	 * @param bool $force Bypass the rate limit (Start/Resume/Kick only).
	 * @return void
	 */
	public static function ping_wp_cron( $force = false ) {
		if ( ! $force ) {
			$gate = get_transient( 'polymart_ai_cron_ping_gate' );

			if ( $gate ) {
				return;
			}

			// Keep the gate short while a job runs so the chain stays snappy.
			$gate_ttl = self::is_bulk_job_running() ? 3 : 45;
			set_transient( 'polymart_ai_cron_ping_gate', 1, $gate_ttl );
		}

		if ( wp_doing_cron() ) {
			static $shutdown_ping_registered = false;

			if ( ! $shutdown_ping_registered ) {
				$shutdown_ping_registered = true;
				register_shutdown_function(
					static function () {
						if ( ! self::is_bulk_job_running() ) {
							return;
						}

						self::spawn_cron_request();
						self::spawn_job_loopback( true );
					}
				);
			}

			return;
		}

		self::spawn_cron_request();
		self::spawn_job_loopback( $force );
	}

	/**
	 * Fire a non-blocking request that makes WP-Cron process due events.
	 *
	 * @return void
	 */
	private static function spawn_cron_request() {
		// Prefer real server crontab when DISABLE_WP_CRON is on — but still allow
		// loopback when the job needs another tick soon (crontab alone is ~60s).
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$allow_loopback = (bool) apply_filters( 'polymart_ai_job_cron_loopback', false );

			if ( ! $allow_loopback ) {
				$job  = self::get_job_raw();
				$last = max(
					absint( $job['last_cron_at'] ?? 0 ),
					absint( $job['worker_heartbeat_at'] ?? 0 )
				);
				$age = $last > 0 ? ( time() - $last ) : PHP_INT_MAX;

				// Chain continuation: spawn wp-cron.php once the previous tick is idle.
				if ( 'running' === ( $job['status'] ?? '' ) && $age >= 8 ) {
					$allow_loopback = true;
				}
			}

			if ( ! $allow_loopback ) {
				return;
			}

			$cron_url = add_query_arg(
				'doing_wp_cron',
				sprintf( '%.22F', microtime( true ) ),
				site_url( 'wp-cron.php' )
			);

			wp_remote_post(
				$cron_url,
				array(
					'timeout'   => 0.01,
					'blocking'  => false,
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				)
			);

			return;
		}

		spawn_cron();
	}

	/**
	 * Mark the job as scheduled and run the first worker tick immediately.
	 *
	 * With DISABLE_WP_CRON, HTTP loopback often fails and the real crontab may
	 * take up to a minute — so Start/Resume always bootstraps one tick here.
	 * Follow-up ticks continue via cron self-chain + server crontab.
	 *
	 * @return array<string, mixed>
	 */
	public static function bootstrap_background_worker() {
		$job = self::get_job_raw();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return self::normalize_job_for_response( $job, false );
		}

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		self::force_release_step_lock_if_idle();
		self::clear_chain_events();
		self::schedule_background_worker( 0 );
		self::ping_wp_cron( true );

		$job = self::get_job_raw();
		$job['worker_scheduled_at'] = time();
		$job['worker_heartbeat_at'] = time();
		$job['next_cron_at']        = self::get_next_worker_cron() ?: null;
		self::save_job( $job );

		return self::process_background_step();
	}

	/**
	 * Ensure the background worker is alive.
	 *
	 * 1) Unlock stale locks + schedule cron/pulse + spawn loopback.
	 * 2) If the job has been idle too long, run one real tick INLINE.
	 *    (Many hosts silently drop non-blocking loopback HTTP — that caused
	 *    multi-minute "کارگر کند شد" stalls while the monitor only called ensure.)
	 *
	 * @return array<string, mixed>
	 */
	public static function ensure_background_worker() {
		$job = self::get_job_raw();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return self::normalize_job_for_response( $job, false );
		}

		$released_stale_lock = self::force_release_step_lock_if_idle();
		$released_stale_lock = self::maybe_release_stale_step_lock() || $released_stale_lock;

		self::ensure_recurring_pulse();

		$job  = self::get_job_raw();
		$last = max(
			absint( $job['last_cron_at'] ?? 0 ),
			absint( $job['worker_heartbeat_at'] ?? 0 )
		);
		$age  = $last > 0 ? ( time() - $last ) : PHP_INT_MAX;
		$busy = self::is_step_lock_held_fresh();

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::schedule_background_worker( 0 );
		} else {
			self::ping_wp_cron();
		}

		if ( ! $busy ) {
			self::spawn_job_loopback( true );
		}

		$inline_idle = self::get_ensure_inline_idle_sec();

		// Dead lock claim that still looks "busy" but heartbeat is ancient — clear and run.
		if ( $busy && $age >= self::LOCK_FORCE_IDLE_SEC ) {
			self::release_step_lock();
			$busy                = false;
			$released_stale_lock = true;
		}

		if ( ! $busy && $age >= $inline_idle ) {
			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}

			$result = self::process_background_step();

			if ( ! is_array( $result ) ) {
				$result = self::normalize_job_for_response( self::get_job_raw(), false );
			}

			$result['worker_ensured']     = true;
			$result['worker_inline_tick'] = true;
			$result['loopback_spawned']   = true;

			if ( $released_stale_lock ) {
				$result['lock_recovered'] = true;
			}

			return $result;
		}

		$next = self::get_next_worker_cron();
		$job  = self::get_job_raw();
		$job['worker_scheduled_at'] = time();
		$job['next_cron_at']        = $next ? (int) $next : null;
		$job['cron_scheduled']      = (bool) $next;
		$job['worker_ensured']      = true;
		$job['worker_lock']         = (bool) get_transient( self::STEP_LOCK_KEY ) || (bool) get_option( self::STEP_LOCK_CLAIM_KEY );
		$job['loopback_spawned']    = ! $busy;

		if ( $released_stale_lock ) {
			$job['lock_recovered'] = true;
			self::log( 'warning', __( 'قفل مرحلهٔ گیرکرده آزاد شد — کارگر دوباره شروع می‌کند.', 'polymart-ai' ) );
		}

		self::save_job( $job );

		return self::normalize_job_for_response( $job, false );
	}

	/**
	 * Cancel pending background job steps (chain + recurring pulse).
	 *
	 * @return void
	 */
	public static function unschedule_background_worker() {
		self::clear_chain_events();
		wp_clear_scheduled_hook( self::CRON_PULSE_HOOK );
	}

	/**
	 * Whether a bulk auto-translate job is currently running.
	 *
	 * @return bool
	 */
	public static function is_bulk_job_running() {
		$job = get_option( self::JOB_OPTION, array() );

		return is_array( $job ) && 'running' === ( $job['status'] ?? '' );
	}

	/**
	 * Wall-clock budget for multi-step cron ticks.
	 *
	 * @return int
	 */
	private static function get_cron_step_budget_sec() {
		/**
		 * Filter seconds of work allowed per translation-job cron invocation.
		 *
		 * @param int $seconds Default 75.
		 */
		return max( 20, min( 240, (int) apply_filters( 'polymart_ai_job_cron_step_budget_sec', self::CRON_STEP_BUDGET_SEC ) ) );
	}

	/**
	 * Label for which driver ran the last tick.
	 *
	 * @return string
	 */
	private static function resolve_last_worker_label() {
		if ( wp_doing_cron() ) {
			return 'cron';
		}

		if ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && self::LOOPBACK_ACTION === (string) $_REQUEST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 'loopback';
		}

		return 'admin';
	}

	/**
	 * Shared worker tick used by WP-Cron, AJAX loopback, AND the admin UI kick.
	 *
	 * One code path for both drivers so browser and cron stay on the same job state.
	 * Schedules the next event BEFORE work so fatals/timeouts do not orphan the chain.
	 *
	 * @return array<string, mixed> Normalized job after the tick.
	 */
	public static function process_background_step() {
		$job = self::get_job_raw();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			self::unschedule_background_worker();

			return self::normalize_job_for_response( $job, false );
		}

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		$worker_label = self::resolve_last_worker_label();

		// Heartbeat BEFORE work so the monitor knows the worker is alive during long AI calls.
		$job['worker_heartbeat_at'] = time();
		$job['last_worker']         = $worker_label;
		self::save_job( $job );

		// Fatal-recovery chain only — never clear the recurring pulse here.
		// Real fast chain is scheduled AFTER work; pulse covers closed-tab gaps.
		self::clear_chain_events();
		self::ensure_recurring_pulse();
		// Do not ping here — mid-tick ping spawned nested cron workers and froze the site.
		wp_schedule_single_event( time() + self::CRON_SAFETY_SEC, self::CRON_HOOK );

		$started   = time();
		$budget    = self::get_cron_step_budget_sec();
		$max_steps = (int) apply_filters( 'polymart_ai_job_cron_max_steps_per_tick', self::CRON_MAX_STEPS_PER_TICK );
		$max_steps = max( 1, min( 40, $max_steps ) );
		$steps_run = 0;
		$last_result = null;

		if ( function_exists( 'set_time_limit' ) ) {
			// One tick may include a full AI call (up to ~165s) plus several slices.
			@set_time_limit( max( 300, $budget + 180 ) );
		}

		while ( $steps_run < $max_steps ) {
			if ( ( time() - $started ) >= $budget ) {
				break;
			}

			self::touch_worker_heartbeat();
			$result = self::process_job_step();
			$last_result = $result;

			// Never clear another worker's lock on contention; only clear after our own step.
			$deferred_busy = is_array( $result )
				&& ! empty( $result['step_deferred'] )
				&& 'step_lock_busy' === (string) ( $result['step_deferred_reason'] ?? '' );

			if ( ! $deferred_busy ) {
				self::release_step_lock();
			}

			self::touch_worker_heartbeat();

			++$steps_run;

			$job = self::get_job_raw();
			$job['last_cron_at']         = time();
			$job['worker_heartbeat_at']  = time();
			$job['last_cron_steps']     = $steps_run;
			$job['last_worker']         = $worker_label;
			self::save_job( $job );

			if ( 'running' !== ( $job['status'] ?? '' ) ) {
				self::unschedule_background_worker();

				return is_array( $result )
					? $result
					: self::normalize_job_for_response( $job, false );
			}

			if ( is_array( $result ) && ! empty( $result['step_deferred'] ) ) {
				// Lock contention or in-progress translation — wait for next tick.
				break;
			}

			if ( is_array( $result ) && ! empty( $result['pause_reason'] ) && 'running' !== ( $result['status'] ?? '' ) ) {
				break;
			}
		}

		$end_deferred_busy = is_array( $last_result )
			&& ! empty( $last_result['step_deferred'] )
			&& 'step_lock_busy' === (string) ( $last_result['step_deferred_reason'] ?? '' );

		if ( ! $end_deferred_busy ) {
			self::release_step_lock();
		}

		// Immediate follow-up: AJAX loopback is the fast path (not the 1-min pulse).
		// Release lock first so spawn_job_loopback is not blocked by our own claim.
		self::clear_chain_events();
		if ( 'running' === ( self::get_job_raw()['status'] ?? '' ) ) {
			$delay = $end_deferred_busy ? 5 : self::get_cron_chain_delay_sec();
			self::schedule_background_worker( $delay );
			self::spawn_job_loopback( ! $end_deferred_busy );
		} else {
			self::unschedule_background_worker();
		}

		if ( is_array( $last_result ) ) {
			$last_result['last_cron_at']        = time();
			$last_result['worker_heartbeat_at'] = time();
			$last_result['last_cron_steps']     = $steps_run;
			$last_result['last_worker']         = $worker_label;
			$last_result['cron_scheduled']      = (bool) self::get_next_worker_cron();
			$last_result['next_cron_at']        = self::get_next_worker_cron() ?: null;
			$last_result['worker_lock']         = (bool) get_transient( self::STEP_LOCK_KEY ) || (bool) get_option( self::STEP_LOCK_CLAIM_KEY );

			return $last_result;
		}

		return self::normalize_job_for_response( self::get_job_raw(), false );
	}

	/**
	 * Admin/UI entry: run the same worker tick as cron (unified pipeline).
	 *
	 * Prefer ensure_background_worker() from the SPA — kick is kept for recovery/debug.
	 *
	 * @return array<string, mixed>
	 */
	public static function kick_worker() {
		$job = self::get_job_raw();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return self::normalize_job_for_response( $job, false );
		}

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		self::force_release_step_lock_if_idle();
		self::schedule_background_worker( 1 );
		self::ping_wp_cron( true );

		return self::process_background_step();
	}

	/**
	 * Release the global job step lock.
	 *
	 * @return void
	 */
	private static function release_step_lock() {
		delete_transient( self::STEP_LOCK_KEY );
		delete_option( self::STEP_LOCK_CLAIM_KEY );
	}

	/**
	 * Process the next item in the job queue.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function process_job_step() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 1200 );
		}

		$job = self::get_job_raw();

		if ( ! in_array( $job['status'] ?? '', array( 'running' ), true ) ) {
			return self::normalize_job_for_response( $job, false );
		}

		if ( ! self::acquire_step_lock() ) {
			$job['step_deferred']         = true;
			$job['step_deferred_reason']  = 'step_lock_busy';
			$job['step_deferred_message'] = __( 'مرحله دیگری در حال اجراست — چند لحظه صبر کنید.', 'polymart-ai' );

			return self::normalize_job_for_response( $job, false );
		}

		self::touch_worker_heartbeat();

		try {
			return self::run_job_step( $job );
		} catch ( \Throwable $e ) {
			$job = self::get_job_raw();
			$job['status']          = 'paused';
			$job['pause_reason']    = 'critical';
			$job['current_post_id'] = null;
			$job['step_started_at'] = null;
			$job['last_error']      = sprintf(
				/* translators: %s: exception message */
				__( 'مرحله ترجمه با خطای سرور متوقف شد: %s', 'polymart-ai' ),
				$e->getMessage()
			);
			self::save_job( $job );
			self::log( 'error', $job['last_error'] );

			return self::normalize_job_for_response( $job, false );
		} finally {
			self::release_step_lock();
		}
	}

	/**
	 * Load job option without expensive live-stat side effects.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_job_raw() {
		$job = get_option( self::JOB_OPTION, array() );

		if ( ! is_array( $job ) || empty( $job ) ) {
			return self::empty_job();
		}

		return wp_parse_args( $job, self::empty_job() );
	}

	/**
	 * Execute one translation step for a running job.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return array<string, mixed>
	 */
	private static function run_job_step( array $job ) {
		$lang = (string) $job['lang'];

		if ( 'menus' === (string) ( $job['phase'] ?? 'posts' ) ) {
			return self::run_menu_job_step( $job );
		}

		$cursor = (int) ( $job['last_post_id'] ?? 0 );

		self::reconcile_stuck_job_queues( $job, $lang );

		if ( self::job_exceeded_step_budget( $job ) && (int) ( $job['needs_work'] ?? $job['remaining'] ?? 0 ) > 0 ) {
			$remaining_ids       = Translation_Query::collect_remaining_post_ids( $lang, 10 );
			$job['status']       = 'paused';
			$job['pause_reason'] = 'stalled';
			$job['stalled_ids']  = $remaining_ids;
			$job['step_started_at'] = null;
			self::sync_job_remaining( $job, $lang, false );
			$job['last_error'] = self::format_stalled_job_error( $lang, $remaining_ids );
			self::save_job( $job );

			return self::normalize_job_for_response( $job, false );
		}

		$post_id    = 0;
		$from_retry = false;
		$partial_id = absint( $job['partial_post_id'] ?? 0 );

		if ( $partial_id > 0 ) {
			if ( in_array( $partial_id, self::get_parked_ids( $job ), true ) ) {
				Post_Translator::release_translation_lock( $partial_id, $lang );
				$job['partial_post_id'] = null;
				$partial_id             = 0;
			}
		}

		if ( $partial_id > 0 ) {
			$post_id    = $partial_id;
			$from_retry = true;
		} else {
			$pick = self::pick_next_job_post_id( $job, $lang, $cursor );

			if ( ! $pick['post_id'] ) {
				// Cursor may have walked past posts that only look done in-memory.
				// Reset and pull a fresh actionable set while site work remains.
				self::sync_job_live_stats( $job, $lang, true );
				self::reconcile_succeeded_posts( $job, $lang );

				$needs_work = (int) ( $job['needs_work'] ?? 0 );

				if ( $needs_work > 0 ) {
					$actionable = self::get_actionable_remaining_ids( $lang, $job, min( 50, max( 1, $needs_work ) ) );

					if ( ! empty( $actionable ) ) {
						$job['last_post_id'] = 0;
						$job['deferred_queue'] = array_values(
							array_unique(
								array_merge( self::get_deferred_queue( $job ), $actionable )
							)
						);
						$job['total']         = max( (int) ( $job['total'] ?? 0 ), (int) ( $job['succeeded'] ?? 0 ) + $needs_work );
						$job['initial_total'] = max( (int) ( $job['initial_total'] ?? 0 ), (int) $job['total'] );
						self::save_job( $job );

						$pick = self::pick_next_job_post_id( $job, $lang, 0 );
					}
				}
			}

			if ( ! $pick['post_id'] ) {
				self::reconcile_stuck_job_queues( $job, $lang );
				$pick = self::pick_next_job_post_id( $job, $lang, $cursor );

				if ( ! $pick['post_id'] && $cursor > 0 ) {
					$job['last_post_id'] = 0;
					$pick                = self::pick_next_job_post_id( $job, $lang, 0 );
				}
			}

			if ( ! $pick['post_id'] ) {
				if ( Menu_Translator::count_untranslated( $lang ) > 0 ) {
					$job['phase']        = 'menus';
					$job['last_menu_id'] = 0;
					self::save_job( $job );

					return self::run_menu_job_step( $job );
				}

				$job['step_started_at'] = null;
				$job['current_post_id'] = null;
				self::finalize_job_if_queue_exhausted( $job, $lang );
				self::sync_job_remaining( $job, $lang );

				if ( 'running' === ( $job['status'] ?? '' ) && (int) ( $job['needs_work'] ?? $job['remaining'] ?? 0 ) > 0 ) {
					$job['status']       = 'paused';
					$job['pause_reason'] = 'pick_stalled';
					$job['last_error']   = __( 'صف ترجمه گیر کرد — مورد فعلی را رد کنید یا «ادامه» / شروع مجدد بزنید.', 'polymart-ai' );
					self::set_job_last_step( $job, 0, 'failed', $job['last_error'] );
				}

				self::save_job( $job );

				if ( 'completed' === ( $job['status'] ?? '' ) ) {
					self::log( 'success', __( 'ترجمه خودکار با موفقیت به پایان رسید.', 'polymart-ai' ) );
				} elseif ( ! empty( $job['last_error'] ) ) {
					self::log( 'warning', (string) $job['last_error'] );
				}

				return self::normalize_job_for_response( $job, false );
			}

			$post_id    = $pick['post_id'];
			$from_retry = $pick['from_retry'];
			$job['partial_post_id'] = $post_id;

			if ( absint( $job['consecutive_post_id'] ?? 0 ) !== $post_id ) {
				$job['consecutive_post_id']    = 0;
				$job['consecutive_post_steps'] = 0;
				$job['stuck_progress_steps']   = 0;
			}
		}

		$job['current_post_id'] = $post_id;
		$job['step_started_at'] = time();

		// Already done for this language — do not burn an AI call or spam "ترجمه شد".
		if (
			self::job_already_counted_success( $job, $post_id )
			|| 'translated' === self::resolve_post_job_status( $post_id, $lang )
		) {
			return self::normalize_job_for_response(
				self::advance_past_already_translated_post( $job, $post_id, $lang ),
				false
			);
		}

		$step_counts = is_array( $job['post_step_counts'] ?? null ) ? $job['post_step_counts'] : array();
		$step_counts[ $post_id ] = absint( $step_counts[ $post_id ] ?? 0 ) + 1;
		$job['post_step_counts'] = $step_counts;

		self::save_job( $job );

		$slice = Post_Translator::process_job_translation_slice( $post_id, $lang );

		if ( is_wp_error( $slice ) && 'polymart_ai_translation_in_progress' === $slice->get_error_code() ) {
			$job['current_post_id']       = null;
			$job['step_started_at']       = null;
			$job['step_deferred']         = true;
			$job['step_deferred_reason']  = 'translation_in_progress';
			$job['step_deferred_message'] = $slice->get_error_message();
			self::save_job( $job );

			return self::normalize_job_for_response( $job, false );
		}

		if ( is_wp_error( $slice ) ) {
			$partial_state = Post_Translator::get_job_partial_state( $post_id, $lang );

			if (
				Post_Translator::is_recoverable_job_slice_error( $slice )
				&& Post_Translator::job_partial_state_has_progress( $post_id, $lang, $partial_state )
			) {
				$partial_progress = self::format_job_partial_progress( $partial_state );
				$phase            = (string) ( $partial_state['phase'] ?? 'elementor' );

				if ( self::job_progress_stuck_should_defer( $job, $post_id, $partial_progress ) ) {
					Post_Translator::release_translation_lock( $post_id, $lang );
					self::defer_job_post_stuck_slice( $job, $post_id, $lang, $phase, $partial_progress );
					self::save_job( $job );

					return self::normalize_job_for_response( $job, false );
				}

				Post_Translator::release_translation_lock( $post_id, $lang );
				$job['partial_post_id']   = $post_id;
				$job['partial_phase']     = $phase;
				$job['partial_progress']  = $partial_progress;
				$job['current_post_id']   = null;
				$job['step_started_at']   = null;
				$job['last_error']        = $slice->get_error_message();
				self::increment_job_step( $job );
				self::set_job_last_step(
					$job,
					$post_id,
					'partial',
					$slice->get_error_message()
				);
				self::save_job( $job );

				return self::normalize_job_for_response( $job, false );
			}

			Post_Translator::clear_job_partial_state( $post_id, $lang );
			Post_Translator::release_translation_lock( $post_id, $lang );
			$job['partial_post_id']   = null;
			$job['partial_phase']     = null;
			$job['partial_progress']  = null;

			return self::normalize_job_for_response(
				self::handle_job_translation_failure( $job, $post_id, $lang, $slice, $from_retry ),
				false
			);
		}

		if ( empty( $slice['done'] ) ) {
			$consecutive_id = absint( $job['consecutive_post_id'] ?? 0 );

			if ( $consecutive_id === $post_id ) {
				$job['consecutive_post_steps'] = absint( $job['consecutive_post_steps'] ?? 0 ) + 1;
			} else {
				$job['consecutive_post_id']    = $post_id;
				$job['consecutive_post_steps'] = 1;
			}

			$limits = Post_Translator::get_job_step_limits_for_post(
				$post_id,
				(string) ( $slice['phase'] ?? '' )
			);
			$max_consecutive   = (int) ( $limits['max_consecutive'] ?? 8 );
			$max_steps_per_run = (int) ( $limits['max_per_run'] ?? 24 );
			$steps_on_post     = absint( $job['post_step_counts'][ $post_id ] ?? 0 );
			$current_progress  = (string) ( $slice['phase_progress'] ?? '' );
			$made_progress     = self::job_slice_made_progress( $job, $current_progress );
			$stuck_should_defer = ! $made_progress && self::job_progress_stuck_should_defer( $job, $post_id, $current_progress );

			if (
				$stuck_should_defer
				|| (
					! $made_progress
					&& (
						absint( $job['consecutive_post_steps'] ?? 0 ) >= max( 1, $max_consecutive )
						|| $steps_on_post >= max( 1, $max_steps_per_run )
					)
				)
			) {
				Post_Translator::release_translation_lock( $post_id, $lang );
				self::defer_job_post_stuck_slice(
					$job,
					$post_id,
					$lang,
					(string) ( $slice['phase'] ?? '' ),
					$current_progress
				);
				self::save_job( $job );

				return self::normalize_job_for_response( $job, false );
			}

			$job['step_partial']      = true;
			$job['partial_post_id']   = $post_id;
			$job['partial_phase']     = (string) ( $slice['phase'] ?? '' );
			$job['partial_progress']  = (string) ( $slice['phase_progress'] ?? '' );

			if ( ! empty( $slice['recoverable'] ) ) {
				$job['recoverable'] = true;
			} else {
				unset( $job['recoverable'] );
			}

			$phase_key = (string) ( $slice['phase'] ?? '' );

			if ( $made_progress ) {
				$job['stuck_progress_steps'] = 0;

				if ( isset( $job['defer_rounds'][ $post_id ] ) ) {
					unset( $job['defer_rounds'][ $post_id ] );
				}

				// Keep consecutive count growing on heavy phases so fairness rotation can fire.
				if ( ! in_array( $phase_key, array( 'variations', 'elementor' ), true ) ) {
					$job['consecutive_post_steps'] = 1;
				}
			}

			$fairness_threshold = max( 12, (int) floor( max( 1, $max_consecutive ) / 4 ) );
			$should_rotate_fair = $made_progress
				&& in_array( $phase_key, array( 'variations', 'elementor' ), true )
				&& absint( $job['consecutive_post_steps'] ?? 0 ) >= $fairness_threshold;

			if ( $should_rotate_fair ) {
				Post_Translator::release_translation_lock( $post_id, $lang );
				self::rotate_job_post_for_fairness( $job, $post_id, $lang, $phase_key, $current_progress );
				self::increment_job_step( $job );
				self::set_job_last_step(
					$job,
					$post_id,
					'deferred',
					sprintf(
						/* translators: 1: phase key, 2: progress marker */
						__( 'چرخش صف (%1$s — %2$s) — بقیه سایت ادامه می‌یابد', 'polymart-ai' ),
						$phase_key,
						'' !== $current_progress ? $current_progress : '…'
					)
				);
				self::save_job( $job );

				return self::normalize_job_for_response( $job, false );
			}

			$job['current_post_id']   = null;
			$job['step_started_at']   = null;
			self::increment_job_step( $job );
			self::set_job_last_step(
				$job,
				$post_id,
				'partial',
				(string) ( $slice['message'] ?? __( 'ادامه در مرحله بعد…', 'polymart-ai' ) )
			);
			self::save_job( $job );

			return self::normalize_job_for_response( $job, false );
		}

		$job['consecutive_post_id']    = 0;
		$job['consecutive_post_steps'] = 0;
		$job['partial_post_id']  = null;
		$job['partial_phase']    = null;
		$job['partial_progress'] = null;
		unset( $job['step_partial'] );

		return self::normalize_job_for_response(
			self::handle_job_translation_success( $job, $post_id, $lang, array( 'translations' => array() ), true ),
			false
		);
	}

	/**
	 * Pick the next post to translate: forward scan first, then deferred partials, then hard retries.
	 *
	 * Heavy Elementor pages stay in deferred_queue so the rest of the site keeps moving.
	 *
	 * @param array<string, mixed> $job    Current job state.
	 * @param string               $lang   Target language code.
	 * @param int                  $cursor Last processed post ID.
	 * @return array{post_id: int, from_retry: bool}
	 */
	private static function pick_next_job_post_id( array &$job, $lang, $cursor ) {
		$exclude_ids = self::get_forward_scan_exclude_ids( $job );

		$post_id = Translation_Query::find_next_untranslated_post_id( $lang, $cursor, $exclude_ids );

		if ( ! $post_id && $cursor > 0 ) {
			$post_id = Translation_Query::find_next_untranslated_post_id( $lang, 0, $exclude_ids );
		}

		if ( $post_id ) {
			return array(
				'post_id'    => $post_id,
				'from_retry' => false,
			);
		}

		$deferred_queue = self::get_actionable_deferred_queue( $job, $lang );

		if ( ! empty( $deferred_queue ) ) {
			$post_id               = (int) array_shift( $deferred_queue );
			$job['deferred_queue'] = $deferred_queue;

			return array(
				'post_id'    => $post_id,
				'from_retry' => true,
			);
		}

		$parked = self::get_parked_ids( $job );

		if ( ! empty( $parked ) ) {
			$post_id = (int) array_shift( $parked );
			self::remove_post_from_deferred_queue( $job, $post_id );
			$job['parked_ids'] = $parked;

			return array(
				'post_id'    => $post_id,
				'from_retry' => true,
			);
		}

		$exhausted_ids = self::get_exhausted_job_post_ids( $job );
		$retry_queue   = array_values(
			array_filter(
				self::get_retry_queue( $job ),
				static function ( $queued_id ) use ( $exhausted_ids ) {
					return ! in_array( (int) $queued_id, $exhausted_ids, true );
				}
			)
		);

		if ( ! empty( $retry_queue ) ) {
			$post_id            = (int) array_shift( $retry_queue );
			$job['retry_queue'] = $retry_queue;

			return array(
				'post_id'    => $post_id,
				'from_retry' => true,
			);
		}

		$actionable = self::get_actionable_remaining_ids( $lang, $job, 50 );

		if ( ! empty( $actionable ) ) {
			$post_id               = (int) $actionable[0];
			$job['deferred_queue'] = array_slice( $actionable, 1 );
			$job['last_post_id']   = 0;

			return array(
				'post_id'    => $post_id,
				'from_retry' => true,
			);
		}

		return array(
			'post_id'    => 0,
			'from_retry' => false,
		);
	}

	/**
	 * Post IDs the forward scanner must skip (deferred, parked, exhausted, done).
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return int[]
	 */
	private static function get_forward_scan_exclude_ids( array $job ) {
		$deferred = array_map( 'absint', self::get_deferred_queue( $job ) );
		$partial  = absint( $job['partial_post_id'] ?? 0 );

		return array_values(
			array_unique(
				array_filter(
					array_merge(
						self::get_exhausted_job_post_ids( $job ),
						self::get_parked_ids( $job ),
						$deferred,
						$partial > 0 ? array( $partial ) : array(),
						array_map( 'absint', is_array( $job['succeeded_ids'] ?? null ) ? $job['succeeded_ids'] : array() )
					)
				)
			)
		);
	}

	/**
	 * Heal queue deadlocks (orphaned deferred/parked state) before picking the next post.
	 *
	 * @param array<string, mixed> $job  Job state (by reference).
	 * @param string               $lang Language code.
	 * @return void
	 */
	private static function reconcile_stuck_job_queues( array &$job, $lang ) {
		$lang = sanitize_key( (string) $lang );

		$deferred = self::get_deferred_queue( $job );
		$rounds   = is_array( $job['defer_rounds'] ?? null ) ? $job['defer_rounds'] : array();

		foreach ( $deferred as $post_id ) {
			$post_id = absint( $post_id );

			if ( $post_id <= 0 ) {
				continue;
			}

			$max_rounds = (int) apply_filters( 'polymart_ai_job_max_defer_rounds_per_post', 10, $job, $post_id );

			if ( (int) ( $rounds[ $post_id ] ?? 0 ) >= max( 1, $max_rounds ) ) {
				self::park_job_post( $job, $post_id, $lang );
			}
		}

		// Drop translated IDs from auxiliary queues.
		foreach ( array( 'deferred_queue', 'retry_queue', 'parked_ids' ) as $queue_key ) {
			$ids = is_array( $job[ $queue_key ] ?? null ) ? $job[ $queue_key ] : array();

			if ( 'deferred_queue' === $queue_key ) {
				$ids = self::get_deferred_queue( $job );
			} elseif ( 'retry_queue' === $queue_key ) {
				$ids = self::get_retry_queue( $job );
			} else {
				$ids = self::get_parked_ids( $job );
			}

			$job[ $queue_key ] = array_values(
				array_filter(
					array_map( 'absint', $ids ),
					static function ( $post_id ) use ( $lang ) {
						return $post_id > 0 && ( '' === $lang || 'translated' !== Post_Translator::get_translation_status( $post_id, $lang ) );
					}
				)
			);
		}
	}

	/**
	 * @param array<string, mixed> $job Job state.
	 * @return int[]
	 */
	private static function get_parked_ids( array $job ) {
		$ids = $job['parked_ids'] ?? array();

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'absint', $ids ),
				static function ( $post_id ) {
					return $post_id > 0;
				}
			)
		);
	}

	/**
	 * Deferred queue minus posts parked for this run.
	 *
	 * @param array<string, mixed> $job  Job state.
	 * @param string               $lang Language code.
	 * @return int[]
	 */
	private static function get_actionable_deferred_queue( array $job, $lang ) {
		$lang     = sanitize_key( (string) $lang );
		$parked   = self::get_parked_ids( $job );
		$deferred = array();

		foreach ( self::get_deferred_queue( $job ) as $post_id ) {
			$post_id = absint( $post_id );

			if ( $post_id <= 0 || in_array( $post_id, $parked, true ) ) {
				continue;
			}

			if ( '' !== $lang && 'translated' === Post_Translator::get_translation_status( $post_id, $lang ) ) {
				continue;
			}

			$deferred[] = $post_id;
		}

		return $deferred;
	}

	/**
	 * Defer a post: rotate to tail, advance cursor, park after too many rounds.
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @return void
	 */
	private static function defer_job_post( array &$job, $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 ) {
			return;
		}

		$rounds            = is_array( $job['defer_rounds'] ?? null ) ? $job['defer_rounds'] : array();
		$rounds[ $post_id ] = (int) ( $rounds[ $post_id ] ?? 0 ) + 1;
		$job['defer_rounds'] = $rounds;
		$job['last_post_id'] = $post_id;

		$max_rounds = (int) apply_filters( 'polymart_ai_job_max_defer_rounds_per_post', 10, $job, $post_id );

		if ( $rounds[ $post_id ] >= max( 1, $max_rounds ) ) {
			self::park_job_post( $job, $post_id, $lang );

			return;
		}

		self::enqueue_job_defer( $job, $post_id, true );
	}

	/**
	 * Rotate a heavy in-progress post without burning park defer rounds.
	 *
	 * Keeps partial meta on the post; clears pinning so the forward scan can move on.
	 *
	 * @param array<string, mixed> $job      Job state.
	 * @param int                  $post_id  Post ID.
	 * @param string               $lang     Language code.
	 * @param string               $phase    Phase key.
	 * @param string               $progress Progress marker.
	 * @return void
	 */
	private static function rotate_job_post_for_fairness( array &$job, $post_id, $lang, $phase, $progress ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		$job['partial_post_id']        = null;
		$job['partial_phase']          = $phase;
		$job['partial_progress']       = $progress;
		$job['current_post_id']        = null;
		$job['step_started_at']        = null;
		$job['consecutive_post_id']    = 0;
		$job['consecutive_post_steps'] = 0;
		$job['stuck_progress_steps']   = 0;
		$job['last_post_id']           = $post_id;
		$job['step_deferred']          = true;
		$job['step_deferred_reason']   = 'fairness_rotation';

		self::track_partial_post( $job, $post_id );
		self::enqueue_job_defer( $job, $post_id, true );
	}

	/**
	 * Skip a heavy post for the rest of this job run (progress kept in post meta).
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @return void
	 */
	private static function park_job_post( array &$job, $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 ) {
			return;
		}

		self::remove_post_from_deferred_queue( $job, $post_id );

		$parked = self::get_parked_ids( $job );

		if ( ! in_array( $post_id, $parked, true ) ) {
			$parked[] = $post_id;
		}

		$job['parked_ids'] = array_values( array_unique( array_map( 'absint', $parked ) ) );

		self::log(
			'warning',
			sprintf(
				/* translators: 1: post ID, 2: post title */
				__( 'مورد #%1$d «%2$s» فعلاً کنار گذاشته شد — بقیه صف ادامه می‌یابد.', 'polymart-ai' ),
				$post_id,
				get_the_title( $post_id ) ?: __( '(بدون عنوان)', 'polymart-ai' )
			),
			array( 'post_id' => $post_id, 'lang' => $lang )
		);
	}

	/**
	 * Normalize deferred queue from job state.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return int[]
	 */
	private static function get_deferred_queue( array $job ) {
		$queue = $job['deferred_queue'] ?? array();

		if ( ! is_array( $queue ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'absint', $queue ),
				static function ( $post_id ) {
					return $post_id > 0;
				}
			)
		);
	}

	/**
	 * Queue a heavy or still-incomplete post for later (does not count as a hard failure).
	 *
	 * @param array<string, mixed> $job      Job state (by reference).
	 * @param int                  $post_id  Post ID.
	 * @param bool                 $rotate   Move existing entry to tail for round-robin.
	 * @return void
	 */
	private static function enqueue_job_defer( array &$job, $post_id, $rotate = false ) {
		$post_id = absint( $post_id );
		$queue   = self::get_deferred_queue( $job );

		if ( $post_id <= 0 ) {
			return;
		}

		if ( in_array( $post_id, $queue, true ) ) {
			if ( ! $rotate ) {
				return;
			}

			$queue = array_values(
				array_filter(
					$queue,
					static function ( $queued_id ) use ( $post_id ) {
						return (int) $queued_id !== $post_id;
					}
				)
			);
		}

		$queue[]               = $post_id;
		$job['deferred_queue'] = $queue;
	}

	/**
	 * Normalize retry queue from job state.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return int[]
	 */
	private static function get_retry_queue( array $job ) {
		$queue = $job['retry_queue'] ?? array();

		if ( ! is_array( $queue ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'absint', $queue ),
				static function ( $post_id ) {
					return $post_id > 0;
				}
			)
		);
	}

	/**
	 * Whether the job has more work after the current cursor.
	 *
	 * @param string $lang   Target language code.
	 * @param int    $cursor Last processed post ID.
	 * @param int[]  $retry_queue Pending retry post IDs.
	 * @return bool
	 */
	private static function job_has_pending_work( $lang, $cursor, array $retry_queue, array $job = array() ) {
		$exclude_ids = self::get_exhausted_job_post_ids( $job );

		if ( ! empty( self::get_actionable_deferred_queue( $job, $lang ) ) ) {
			return true;
		}

		if ( ! empty( self::get_parked_ids( $job ) ) ) {
			return true;
		}

		foreach ( $retry_queue as $queued_id ) {
			if ( ! in_array( (int) $queued_id, $exclude_ids, true ) ) {
				return true;
			}
		}

		$post_id = Translation_Query::find_next_untranslated_post_id(
			$lang,
			$cursor,
			self::get_forward_scan_exclude_ids( $job )
		);

		if ( $post_id ) {
			return true;
		}

		if ( $cursor > 0 ) {
			return (bool) Translation_Query::find_next_untranslated_post_id(
				$lang,
				0,
				self::get_forward_scan_exclude_ids( $job )
			);
		}

		if ( Menu_Translator::find_next_untranslated_id( $lang, 0, $exclude_ids ) > 0 ) {
			return true;
		}

		return ! empty( self::get_actionable_remaining_ids( $lang, $job, 1 ) );
	}

	/**
	 * Post IDs that exceeded the retry limit and should be skipped.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return int[]
	 */
	private static function get_exhausted_job_post_ids( array $job ) {
		$exhausted = is_array( $job['exhausted_ids'] ?? null ) ? array_map( 'absint', $job['exhausted_ids'] ) : array();

		return array_values( array_unique( array_filter( $exhausted ) ) );
	}

	/**
	 * Remaining post IDs that are not skipped after retry exhaustion.
	 *
	 * @param string               $lang Target language code.
	 * @param array<string, mixed> $job  Job state.
	 * @param int                  $limit Maximum IDs to return.
	 * @return int[]
	 */
	private static function get_actionable_remaining_ids( $lang, array $job, $limit = 50 ) {
		$exclude_lookup = array_fill_keys(
			array_merge(
				self::get_forward_scan_exclude_ids( $job ),
				self::get_parked_ids( $job )
			),
			true
		);
		$actionable     = array();

		foreach ( Translation_Query::collect_remaining_post_ids( $lang, max( 1, absint( $limit ) ) ) as $post_id ) {
			$post_id = absint( $post_id );

			if ( $post_id <= 0 || isset( $exclude_lookup[ $post_id ] ) ) {
				continue;
			}

			$actionable[] = $post_id;
		}

		return $actionable;
	}

	/**
	 * Mark the job completed or paused when no actionable queue items remain.
	 *
	 * @param array<string, mixed> $job  Job state (by reference).
	 * @param string               $lang Target language code.
	 * @return void
	 */
	private static function finalize_job_if_queue_exhausted( array &$job, $lang ) {
		self::sync_job_remaining( $job, $lang );

		if ( ! empty( self::get_actionable_remaining_ids( $lang, $job, 1 ) ) ) {
			return;
		}

		$remaining_ids = Translation_Query::collect_all_remaining_post_ids( $lang );
		$exhausted     = self::get_exhausted_job_post_ids( $job );
		$parked        = self::get_parked_ids( $job );
		$fresh_retry   = array_values(
			array_filter(
				array_map( 'absint', $remaining_ids ),
				static function ( $post_id ) use ( $exhausted, $parked, $job ) {
					if ( $post_id <= 0 || in_array( $post_id, $exhausted, true ) || in_array( $post_id, $parked, true ) ) {
						return false;
					}

					return ! in_array( $post_id, self::get_deferred_queue( $job ), true );
				}
			)
		);

		if ( ! empty( $fresh_retry ) ) {
			$job['deferred_queue'] = array_values(
				array_unique(
					array_merge( self::get_deferred_queue( $job ), $fresh_retry )
				)
			);
			self::save_job( $job );

			return;
		}

		$skipped_ids = array_values(
			array_unique(
				array_merge(
					array_intersect(
						array_map( 'absint', $remaining_ids ),
						self::get_exhausted_job_post_ids( $job )
					),
					$parked
				)
			)
		);

		if ( (int) ( $job['remaining'] ?? 0 ) > 0 && ! empty( $skipped_ids ) ) {
			$job['status']       = 'completed';
			$job['pause_reason'] = null;
			$job['skipped_ids']  = $skipped_ids;
			$job['last_error']   = self::format_skipped_job_notice( $lang, $skipped_ids );

			return;
		}

		if ( (int) ( $job['remaining'] ?? 0 ) > 0 ) {
			$stalled_ids         = Translation_Query::collect_remaining_post_ids( $lang, 10 );
			$job['status']       = 'paused';
			$job['pause_reason'] = 'stalled';
			$job['stalled_ids']  = $stalled_ids;
			$job['last_error']   = self::format_stalled_job_error( $lang, $stalled_ids );

			return;
		}

		$job['status']       = 'completed';
		$job['pause_reason'] = null;
		$job['remaining']    = 0;
		$job['last_error']   = null;
	}

	/**
	 * Build a completion notice when some posts were skipped after retry exhaustion.
	 *
	 * @param string $lang        Target language code.
	 * @param int[]  $skipped_ids Post IDs skipped in this run.
	 * @return string
	 */
	private static function format_skipped_job_notice( $lang, array $skipped_ids ) {
		return self::format_stalled_job_error(
			$lang,
			$skipped_ids,
			__( 'ترجمه خودکار تمام شد — %1$d مورد پس از چند تلاش رد شد و بقیه انجام شد: %2$s', 'polymart-ai' )
		);
	}

	/**
	 * Build a user-facing pause message for stalled posts.
	 *
	 * @param string $lang          Target language code.
	 * @param int[]  $remaining_ids Post IDs still incomplete.
	 * @param string $template      Optional sprintf template.
	 * @return string
	 */
	private static function format_stalled_job_error( $lang, array $remaining_ids, $template = '' ) {
		$lang          = sanitize_key( (string) $lang );
		$remaining_ids = array_values( array_filter( array_map( 'absint', $remaining_ids ) ) );
		$details       = array();

		foreach ( array_slice( $remaining_ids, 0, 5 ) as $post_id ) {
			$gaps    = Post_Translator::get_translation_gaps( $post_id, $lang );
			$parts   = array();

			foreach ( $gaps['fields'] ?? array() as $field ) {
				if ( empty( $field['translated'] ) ) {
					$parts[] = sprintf(
						'%s [%s]',
						(string) ( $field['label'] ?? '' ),
						(string) ( $field['meta_key'] ?? '' )
					);
				}
			}

			if ( empty( $parts ) && ! empty( $gaps['missing'] ) ) {
				$parts = $gaps['missing'];
			}

			if ( empty( $parts ) && ! empty( $gaps['notes'] ) ) {
				$parts = $gaps['notes'];
			}

			$details[] = sprintf(
				'#%1$d: %2$s',
				$post_id,
				get_the_title( $post_id ) ?: __( '(بدون عنوان)', 'polymart-ai' )
			) . ( ! empty( $parts ) ? ' — ' . implode( ', ', $parts ) : '' );
		}

		if ( '' === $template ) {
			$template = __( 'ترجمه متوقف شد — %1$d مورد ناقص: %2$s. «توقف کامل» و «شروع» مجدد بزنید یا فیلدها را دستی تکمیل کنید.', 'polymart-ai' );
		}

		$summary = implode( ' | ', $details );

		if ( count( $remaining_ids ) > count( $details ) ) {
			$summary .= sprintf(
				/* translators: %d: additional incomplete post count */
				__( ' … +%d مورد دیگر', 'polymart-ai' ),
				count( $remaining_ids ) - count( $details )
			);
		}

		$total_incomplete = max(
			count( $remaining_ids ),
			Translation_Query::count_untranslated_persian_posts( $lang )
		);

		return sprintf(
			$template,
			$total_incomplete,
			$summary
		);
	}

	/**
	 * Queue a failed post for retry when attempts remain.
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Failed post ID.
	 * @return void
	 */
	private static function enqueue_job_retry( array &$job, $post_id ) {
		$post_id        = absint( $post_id );
		$lang           = sanitize_key( (string) ( $job['lang'] ?? '' ) );
		$retry_queue    = self::get_retry_queue( $job );
		$retry_attempts = is_array( $job['retry_attempts'] ?? null ) ? $job['retry_attempts'] : array();
		$attempts       = (int) ( $retry_attempts[ $post_id ] ?? 0 ) + 1;
		$max_attempts   = self::get_max_job_retries_for_post( $post_id, $lang );

		$retry_attempts[ $post_id ] = $attempts;
		$job['retry_attempts']      = $retry_attempts;

		if ( $attempts >= $max_attempts ) {
			$partial = Post_Translator::get_job_partial_state( $post_id, $lang );
			$phase   = sanitize_key( (string) ( $partial['phase'] ?? '' ) );

			if ( '' !== $phase && in_array( $phase, array( 'core', 'commerce', 'variations', 'elementor', 'fields' ), true ) ) {
				if ( ! in_array( $post_id, $retry_queue, true ) ) {
					$retry_queue[] = $post_id;
				}

				$job['retry_queue'] = $retry_queue;

				return;
			}

			$pending = array();
			$post    = get_post( $post_id );

			if ( $post instanceof \WP_Post ) {
				$pending = Post_Translator::collect_persian_fields( $post, $lang );
			}

			if ( ! empty( $pending ) ) {
				if ( ! in_array( $post_id, $retry_queue, true ) ) {
					$retry_queue[] = $post_id;
				}

				$job['retry_queue'] = $retry_queue;

				return;
			}

			$exhausted = is_array( $job['exhausted_ids'] ?? null ) ? $job['exhausted_ids'] : array();

			if ( ! in_array( $post_id, $exhausted, true ) ) {
				$exhausted[] = $post_id;
			}

			$job['exhausted_ids'] = array_values( array_map( 'absint', $exhausted ) );
			$job['retry_queue']   = array_values(
				array_filter(
					self::get_retry_queue( $job ),
					static function ( $queued_id ) use ( $post_id ) {
						return (int) $queued_id !== (int) $post_id;
					}
				)
			);

			return;
		}

		if ( ! in_array( $post_id, $retry_queue, true ) ) {
			$retry_queue[] = $post_id;
		}

		$job['retry_queue'] = $retry_queue;
	}

	/**
	 * Handle a failed translation step.
	 *
	 * @param array<string, mixed> $job        Job state.
	 * @param int                  $post_id    Post ID.
	 * @param string               $lang       Target language code.
	 * @param \WP_Error            $result     Translation error.
	 * @param bool                 $from_retry Whether this post came from the retry queue.
	 * @return array<string, mixed>
	 */
	private static function handle_job_translation_failure( array $job, $post_id, $lang, \WP_Error $result, $from_retry ) {
		$error_message = $result->get_error_message();
		$error_code    = sanitize_key( (string) $result->get_error_code() );

		if ( '' !== $error_code && false === stripos( $error_message, $error_code ) ) {
			$error_message = sprintf( '[%s] %s', $error_code, $error_message );
		}

		$job['last_error']      = $error_message;
		self::increment_job_step( $job );
		$job['current_post_id'] = null;
		$job['step_started_at'] = null;
		self::set_job_last_step( $job, $post_id, 'failed', $error_message );

		if ( ! $from_retry ) {
			$job['last_post_id'] = $post_id;
		}

		self::log(
			'error',
			sprintf(
				/* translators: 1: post ID, 2: error message */
				__( 'خطا در ترجمه مورد #%1$d: %2$s', 'polymart-ai' ),
				$post_id,
				$error_message
			),
			array( 'post_id' => $post_id, 'lang' => $lang )
		);

		if ( self::is_critical_api_error( $result ) ) {
			$job['failed']++;
			$job['status']       = 'paused';
			$job['pause_reason'] = 'critical';
			self::save_job( $job );
			self::set_admin_notice(
				sprintf(
					/* translators: %s: error message */
					__( 'ترجمه خودکار متوقف شد: %s', 'polymart-ai' ),
					$error_message
				),
				'error'
			);
			return $job;
		}

		self::enqueue_job_retry( $job, $post_id );

		if ( in_array( $post_id, self::get_exhausted_job_post_ids( $job ), true ) ) {
			$job['failed']++;
		}

		$retry_queue = self::get_retry_queue( $job );

		if ( ! self::job_has_pending_work( $lang, (int) $job['last_post_id'], $retry_queue, $job ) ) {
			self::finalize_job_if_queue_exhausted( $job, $lang );
		} else {
			self::sync_job_remaining( $job, $lang );
		}

		self::save_job( $job );

		return $job;
	}

	/**
	 * Handle a successful translation step.
	 *
	 * @param array<string, mixed>              $job    Job state.
	 * @param int                                 $post_id Post ID.
	 * @param string                              $lang   Target language code.
	 * @param array{translations: array<string, string>} $result Translation result.
	 * @return array<string, mixed>
	 */
	private static function handle_job_translation_success( array $job, $post_id, $lang, array $result, $already_saved = false ) {
		if ( ! $already_saved ) {
			$save_result = Post_Translator::save_ai_translations( $post_id, $result['translations'], $lang );

			if ( is_wp_error( $save_result ) ) {
				return self::handle_job_translation_failure( $job, $post_id, $lang, $save_result, false );
			}
		}

		REST_API::invalidate_stats_cache();
		clean_post_cache( $post_id );
		Post_Translator::flush_translation_status_cache( $post_id );

		$title_source     = get_the_title( $post_id );
		$title_translated = (string) get_post_meta( $post_id, Post_Translator::get_meta_key( 'title', $lang ), true );

		self::log_report( $post_id, $lang, $title_source, $title_translated, 'auto' );

		$status = self::resolve_post_job_status( $post_id, $lang );
		$already_counted = self::job_already_counted_success( $job, $post_id );

		if ( 'translated' === $status ) {
			self::track_succeeded_post( $job, $post_id );
			self::clear_partial_post( $job, $post_id );
		} else {
			self::untrack_succeeded_post( $job, $post_id );
			self::track_partial_post( $job, $post_id );
			self::defer_job_post( $job, $post_id, $lang );

			$gaps     = Post_Translator::get_translation_gaps( $post_id, $lang );
			$attempts = (int) ( $job['retry_attempts'][ $post_id ] ?? 0 );
			$missing  = ! empty( $gaps['missing'] ) ? implode( ', ', $gaps['missing'] ) : __( 'نامشخص', 'polymart-ai' );

			self::log(
				'warning',
				sprintf(
					/* translators: 1: post title, 2: missing fields */
					__( '«%1$s» هنوز ناقص است — به صف بعدی منتقل شد. باقی‌مانده: %2$s', 'polymart-ai' ),
					$title_source,
					$missing
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);
		}

		self::increment_job_step( $job );
		$job['last_post_id']    = $post_id;
		$job['current_post_id'] = null;
		$job['step_started_at'] = null;
		$job['last_error']      = null;

		if ( 'translated' === $status ) {
			$retry_attempts = is_array( $job['retry_attempts'] ?? null ) ? $job['retry_attempts'] : array();
			unset( $retry_attempts[ $post_id ] );
			$job['retry_attempts'] = $retry_attempts;
		}

		// Keep the current post in the retry queue only while it is still incomplete.
		$retry_queue = array_values(
			array_filter(
				self::get_retry_queue( $job ),
				static function ( $queued_id ) use ( $post_id, $status ) {
					if ( (int) $queued_id !== (int) $post_id ) {
						return true;
					}

					return 'translated' !== $status;
				}
			)
		);
		$job['retry_queue'] = $retry_queue;

		if ( 'translated' === $status && ! $already_counted ) {
			self::log(
				'success',
				sprintf(
					/* translators: 1: post title, 2: language label */
					__( '«%1$s» به %2$s ترجمه شد.', 'polymart-ai' ),
					$title_source,
					$job['lang_label'] ?? $lang
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);
		}

		if ( ! self::job_has_pending_work( $lang, $post_id, $retry_queue, $job ) ) {
			self::finalize_job_if_queue_exhausted( $job, $lang );

			if ( 'completed' === ( $job['status'] ?? '' ) && empty( $job['last_error'] ) ) {
				self::log( 'success', __( 'ترجمه خودکار با موفقیت به پایان رسید.', 'polymart-ai' ) );
			} elseif ( ! empty( $job['last_error'] ) && 'completed' === ( $job['status'] ?? '' ) ) {
				self::log( 'warning', (string) $job['last_error'] );
			}
		} else {
			self::sync_job_remaining( $job, $lang );
		}

		if ( 'translated' === $status ) {
			self::set_job_last_step(
				$job,
				$post_id,
				$already_counted ? 'skipped' : 'translated',
				$already_counted
					? sprintf(
						/* translators: %s: post title */
						__( '«%s» از قبل موفق ثبت شده بود — مورد بعدی.', 'polymart-ai' ),
						$title_source
					)
					: sprintf(
						/* translators: 1: post title, 2: language label */
						__( '«%1$s» به %2$s ترجمه شد.', 'polymart-ai' ),
						$title_source,
						$job['lang_label'] ?? $lang
					)
			);
		} else {
			self::set_job_last_step(
				$job,
				$post_id,
				'partial',
				sprintf(
					/* translators: 1: post title, 2: missing fields */
					__( '«%1$s» ناقص ماند — %2$s', 'polymart-ai' ),
					$title_source,
					! empty( $gaps['missing'] ) ? implode( ', ', $gaps['missing'] ) : __( 'نامشخص', 'polymart-ai' )
				)
			);
		}

		// Full-site scans are expensive; refresh at most every few seconds.
		$live_age = time() - (int) ( $job['live_stats_at'] ?? 0 );
		self::sync_job_live_stats( $job, $lang, $live_age >= 5 );
		self::save_job( $job );

		return $job;
	}

	/**
	 * Execute one menu translation step after post content is complete.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return array<string, mixed>
	 */
	private static function run_menu_job_step( array $job ) {
		$lang       = (string) $job['lang'];
		$cursor     = (int) ( $job['last_menu_id'] ?? 0 );
		$exclude    = self::get_exhausted_job_post_ids( $job );
		$item_id    = Menu_Translator::find_next_untranslated_id( $lang, $cursor, $exclude );

		if ( $item_id <= 0 ) {
			$job['phase']           = 'posts';
			$job['step_started_at'] = null;
			$job['current_post_id'] = null;
			$job['menu_remaining']  = 0;
			self::finalize_job_if_queue_exhausted( $job, $lang );
			self::save_job( $job );

			if ( 'completed' === ( $job['status'] ?? '' ) ) {
				self::log( 'success', __( 'ترجمه خودکار با موفقیت به پایان رسید.', 'polymart-ai' ) );
			}

			return self::normalize_job_for_response( $job, false );
		}

		$job['current_post_id'] = $item_id;
		$job['step_started_at'] = time();
		self::save_job( $job );

		$result = Menu_Translator::request_ai_translation( $item_id, $lang );

		if ( is_wp_error( $result ) ) {
			$error_message = $result->get_error_message();
			$attempts      = (int) ( $job['retry_attempts'][ $item_id ] ?? 0 ) + 1;
			$job['retry_attempts'][ $item_id ] = $attempts;

			if ( self::is_critical_api_error( $result ) ) {
				$job['status']          = 'paused';
				$job['pause_reason']    = 'critical';
				$job['current_post_id'] = null;
				$job['step_started_at'] = null;
				$job['last_error']      = $error_message;
				self::save_job( $job );
				self::log( 'error', $error_message );

				return self::normalize_job_for_response( $job, false );
			}

			if ( $attempts >= self::MAX_JOB_RETRIES ) {
				$exhausted   = is_array( $job['exhausted_ids'] ?? null ) ? $job['exhausted_ids'] : array();
				$exhausted[] = $item_id;
				$job['exhausted_ids'] = array_values( array_unique( array_map( 'absint', $exhausted ) ) );
				$job['failed']++;
			} else {
				$job['retry_queue'] = array_values(
					array_unique(
						array_merge( self::get_retry_queue( $job ), array( $item_id ) )
					)
				);
			}

			self::increment_job_step( $job );
			$job['last_menu_id']    = $item_id;
			$job['current_post_id'] = null;
			$job['step_started_at'] = null;
			$job['last_error']      = $error_message;
			$job['menu_remaining']  = Menu_Translator::count_untranslated( $lang );
			self::sync_job_remaining( $job, $lang );
			self::set_job_last_step(
				$job,
				$item_id,
				'failed',
				sprintf(
					/* translators: 1: menu label, 2: error message */
					__( 'منو «%1$s» — %2$s', 'polymart-ai' ),
					get_the_title( $item_id ),
					$error_message
				)
			);
			self::save_job( $job );

			return self::normalize_job_for_response( $job, false );
		}

		self::increment_job_step( $job );
		$job['last_menu_id']    = $item_id;
		$job['current_post_id'] = null;
		$job['step_started_at'] = null;
		$job['last_error']      = null;
		$job['menu_remaining']  = Menu_Translator::count_untranslated( $lang );
		$job['succeeded']       = (int) ( $job['succeeded'] ?? 0 ) + 1;

		unset( $job['retry_attempts'][ $item_id ] );
		$job['retry_queue'] = array_values(
			array_filter(
				self::get_retry_queue( $job ),
				static function ( $queued_id ) use ( $item_id ) {
					return (int) $queued_id !== (int) $item_id;
				}
			)
		);

		self::log(
			'success',
			sprintf(
				/* translators: 1: Persian menu label, 2: translated label, 3: language */
				__( 'منو «%1$s» → «%2$s» (%3$s)', 'polymart-ai' ),
				$result['source'],
				$result['title'],
				$job['lang_label'] ?? $lang
			),
			array( 'post_id' => $item_id, 'lang' => $lang )
		);

		self::set_job_last_step(
			$job,
			$item_id,
			'translated',
			sprintf(
				/* translators: 1: Persian menu label, 2: translated label */
				__( 'منو «%1$s» → «%2$s»', 'polymart-ai' ),
				$result['source'],
				$result['title']
			)
		);

		self::sync_job_remaining( $job, $lang );
		self::save_job( $job );

		return self::normalize_job_for_response( $job, false );
	}

	/**
	 * Whether an API error should pause the job and alert admin.
	 *
	 * @param \WP_Error $error Error object.
	 * @return bool
	 */
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

	/**
	 * Whether an API error looks like rate limiting or bot/WAF challenge.
	 *
	 * @param \WP_Error $error Error object.
	 * @return bool
	 */
	public static function is_rate_limit_or_bot_error( \WP_Error $error ) {
		$message = strtolower( $error->get_error_message() );
		$data    = $error->get_error_data();
		$status  = is_array( $data ) ? absint( $data['status'] ?? 0 ) : 0;

		if ( 429 === $status ) {
			return true;
		}

		$keywords = array(
			'429',
			'too many requests',
			'rate limit',
			'rate-limit',
			'bot',
			'captcha',
			'cf-ray',
			'cloudflare',
			'ربات',
			'تشخیص',
		);

		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $message, $keyword ) ) {
				return true;
			}
		}

		return false;
	}
}
