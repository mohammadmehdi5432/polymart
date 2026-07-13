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
	 * Must stay above Post_Translator::JOB_REQUEST_MAX_TIMEOUT (~50s) plus overhead.
	 */
	const STEP_LOCK_TTL        = 90;
	/**
	 * ensure/kick must not force-unlock during a normal AI call.
	 * Keep slightly above JOB_REQUEST_MAX_TIMEOUT.
	 */
	const LOCK_FORCE_IDLE_SEC  = 70;
	const CRON_HOOK            = 'polymart_ai_translation_job_step';
	/**
	 * Recurring pulse — keep-alive: ensure AS action exists + run queue inline.
	 */
	const CRON_PULSE_HOOK      = 'polymart_ai_translation_job_pulse';
	const CRON_PULSE_SCHEDULE  = 'polymart_ai_every_minute';
	/** @deprecated Kept for schedule_background_worker signature compatibility. */
	const CRON_INTERVAL_SEC    = 0;
	/** Safety reschedule while a tick is mid-flight (fatal recovery). */
	const CRON_SAFETY_SEC      = 120;
	/** Faster AS fallback when inline chaining does not start the next slice. */
	const AS_CRON_SAFETY_SEC   = 3;
	/** Self-rescheduling keep-alive between AS batches (when wp-cron is available). */
	const CRON_FAST_INTERVAL_SEC = 12;
	const CRON_INTERVAL_SERVER_SEC = 1;
	/** Seconds of wall-clock work allowed per Action Scheduler / cron batch. */
	const CRON_STEP_BUDGET_SEC = 55;
	/** Max AI slices per AS action / cron batch (AI call ≤50s). */
	const CRON_MAX_STEPS_PER_TICK = 2;
	/**
	 * If last_cron_at is older than this while the step lock is held with no
	 * current_post_id, treat the lock as orphaned (between-item deadlock).
	 */
	const LOCK_IDLE_ORPHAN_SEC = 30;
	/** Max seconds allowed in catalog pick/scan before force-unlock. */
	const PICK_STALL_SEC         = 75;
	/** Legacy admin-ajax action — now only nudges Action Scheduler. */
	const LOOPBACK_ACTION      = 'polymart_ai_job_loopback';
	const LOOPBACK_TOKEN_OPTION = 'polymart_ai_job_loopback_token';
	const LOOPBACK_GATE_KEY    = 'polymart_ai_job_loopback_gate';
	const LOOPBACK_GATE_SEC    = 1;

	/**
	 * True while a token-authenticated loopback tick is executing.
	 *
	 * @var bool
	 */
	private static $trusted_loopback_tick = false;
	/**
	 * True while the shared worker pipeline is executing.
	 *
	 * @var bool
	 */
	private static $trusted_job_tick = false;
	/**
	 * True while an Action Scheduler slice callback is executing.
	 *
	 * @var bool
	 */
	private static $trusted_as_tick = false;
	/**
	 * When true, run_inline_worker_tick may execute from admin REST (ensure/kick).
	 *
	 * @var bool
	 */
	private static $trusted_admin_worker = false;

	/**
	 * Prevent nested Elementor burst recursion (run_queue_inline ↔ run_pinned).
	 *
	 * @var bool
	 */
	private static $elementor_burst_inflight = false;

	/**
	 * Finish all remaining Elementor API batches for the pinned post in one HTTP request.
	 *
	 * @var bool
	 */
	private static $elementor_page_burst = false;

	/**
	 * When true, process_background_step will not re-enqueue (AS handler chains).
	 *
	 * @var bool
	 */
	private static $skip_chain_after_tick = false;

	/**
	 * Register admin notice display and background job worker.
	 *
	 * @return void
	 */
	public static function init() {
		Job_Action_Scheduler::init();
		Metabox_Action_Scheduler::init();

		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'keep_alive_as_worker' ) );
		add_action( self::CRON_PULSE_HOOK, array( __CLASS__, 'keep_alive_as_worker' ) );
		add_action( 'polymart_ai_before_ai_http', array( __CLASS__, 'on_ai_http_heartbeat' ) );
		add_action( 'polymart_ai_during_ai_http', array( __CLASS__, 'on_ai_http_heartbeat' ) );
		add_action( 'polymart_ai_worker_heartbeat', array( __CLASS__, 'on_ai_http_heartbeat' ) );
		// Legacy endpoint: nudge AS instead of HTTP/CLI chains.
		add_action( 'wp_ajax_' . self::LOOPBACK_ACTION, array( __CLASS__, 'handle_loopback_tick' ) );
		add_action( 'wp_ajax_nopriv_' . self::LOOPBACK_ACTION, array( __CLASS__, 'handle_loopback_tick' ) );

		if ( wp_doing_cron() ) {
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

		$job     = self::get_job_raw();
		$post_id = absint( $job['current_post_id'] ?? 0 ) ?: absint( $job['partial_post_id'] ?? 0 );
		$lang    = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

		if ( $post_id > 0 && '' !== $lang ) {
			Post_Translator::touch_translation_lock( $post_id, $lang );
		}
	}

	/**
	 * Record that the next AS slice was queued (between-batch gap is normal here).
	 *
	 * @param int $action_id Enqueued Action Scheduler action ID.
	 * @return void
	 */
	public static function on_as_chain_enqueued( $action_id ) {
		$action_id = absint( $action_id );

		if ( $action_id <= 0 || ! self::is_bulk_job_running() ) {
			return;
		}

		$job = self::get_job_raw();
		$job['chain_enqueued_at']   = time();
		$job['chain_action_id']     = $action_id;
		$job['last_worker']         = 'as';
		$job['updated_at']          = time();
		update_option( self::JOB_OPTION, $job, false );

		self::schedule_chain_safety_pulse( self::AS_CRON_SAFETY_SEC );
	}

	/**
	 * Wake WP-Cron / AS queue when inline chaining could not run the pending slice.
	 *
	 * @return void
	 */
	public static function nudge_as_queue_runner() {
		if ( ! self::is_bulk_job_running() ) {
			return;
		}

		self::schedule_chain_safety_pulse( self::AS_CRON_SAFETY_SEC );
		self::ping_wp_cron( true );
	}

	/**
	 * Whether the bulk worker is actively translating (lock/heartbeat recently touched).
	 *
	 * AS uses action "modified" which does not tick during long batches — use this
	 * before failing In-progress actions or force-releasing locks.
	 *
	 * @param int|null $max_silent_sec Max seconds without lock/heartbeat refresh.
	 * @return bool
	 */
	public static function is_bulk_worker_lively( $max_silent_sec = null ) {
		if ( ! self::is_bulk_job_running() ) {
			return false;
		}

		$max_silent_sec = null === $max_silent_sec
			? Job_Action_Scheduler::STALE_RUNNING_SEC
			: max( 30, absint( $max_silent_sec ) );

		$lock  = get_transient( self::STEP_LOCK_KEY );
		$claim = absint( get_option( self::STEP_LOCK_CLAIM_KEY, 0 ) );
		$stamp = max( $lock ? absint( $lock ) : 0, $claim );

		if ( $stamp > 0 && ( time() - $stamp ) < $max_silent_sec ) {
			return true;
		}

		$job  = self::get_job_raw();
		$last = max(
			absint( $job['last_cron_at'] ?? 0 ),
			absint( $job['worker_heartbeat_at'] ?? 0 ),
			absint( $job['step_started_at'] ?? 0 )
		);

		return $last > 0 && ( time() - $last ) < $max_silent_sec;
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
	 * Legacy admin-ajax entry — no longer runs AI work over HTTP.
	 *
	 * Wakes the CLI worker (or inline fallback) and exits. Kept so old cron
	 * bookmarks / leftover clients do not 404.
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

		self::$trusted_loopback_tick = true;

		if ( ! self::is_bulk_job_running() ) {
			status_header( 200 );
			exit( 'idle' );
		}

		self::keep_alive_as_worker();

		self::$trusted_loopback_tick = false;

		status_header( 200 );
		exit( 'as' );
	}

	/**
	 * Whether the current request is a trusted auto-translate worker.
	 *
	 * @return bool
	 */
	public static function is_trusted_job_worker() {
		if ( wp_doing_cron() || self::$trusted_loopback_tick || self::$trusted_job_tick || self::$trusted_as_tick ) {
			return true;
		}

		if ( wp_doing_ajax()
			&& isset( $_REQUEST['action'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& self::LOOPBACK_ACTION === sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) )
		) {
			return true;
		}

		return false;
	}

	/**
	 * Ensure pluggable auth exists and impersonate an admin during background jobs.
	 *
	 * Elementor, Rank Math, and other plugins call wp_get_current_user() while we
	 * save translated meta from Action Scheduler where no interactive user exists.
	 *
	 * @return void
	 */
	public static function bootstrap_job_worker_context() {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return;
		}

		if ( get_current_user_id() > 0 ) {
			return;
		}

		if ( ! self::is_trusted_job_worker() && ! self::is_bulk_job_running() ) {
			return;
		}

		$user_id = absint( get_option( 'polymart_ai_job_worker_user_id', 0 ) );

		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			$admins = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				)
			);

			$user_id = ! empty( $admins ) ? absint( $admins[0] ) : 0;

			if ( $user_id > 0 ) {
				update_option( 'polymart_ai_job_worker_user_id', $user_id, false );
			}
		}

		if ( $user_id > 0 ) {
			wp_set_current_user( $user_id );
		}
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
	 * Legacy name — now nudges Action Scheduler (HTTP loopback retired).
	 *
	 * @param bool $force Bypass soft gates when ensuring the next AS action.
	 * @return void
	 */
	public static function spawn_job_loopback( $force = false ) {
		Job_Action_Scheduler::ensure_scheduled();
		if ( $force ) {
			Job_Action_Scheduler::enqueue_next( true );
		}
	}

	/**
	 * After a batch: keep the pulse alive and enqueue the next AS action.
	 *
	 * @param int  $delay_sec Unused (signature compatibility).
	 * @param bool $force     Force enqueue.
	 * @return void
	 */
	private static function continue_job_chain( $delay_sec = null, $force = true ) {
		unset( $delay_sec );

		if ( ! self::is_bulk_job_running() ) {
			self::unschedule_background_worker();
			return;
		}

		self::ensure_recurring_pulse();

		if ( self::$skip_chain_after_tick ) {
			return;
		}

		if ( Job_Action_Scheduler::is_available() ) {
			Job_Action_Scheduler::enqueue_next( $force );
			return;
		}

		// No Action Scheduler (WooCommerce missing): schedule WP-Cron pulse only.
		self::ensure_recurring_pulse();
	}

	/**
	 * Cron keep-alive: ensure an AS action exists and run the queue inline.
	 *
	 * This is the primary production path on shared hosts — no CLI, no HTTP loopback.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function keep_alive_as_worker() {
		if (
			! wp_doing_cron()
			&& ! self::$trusted_loopback_tick
			&& ! self::$trusted_job_tick
			&& ! self::$trusted_as_tick
			&& ! self::$trusted_admin_worker
		) {
			return null;
		}

		$job = self::get_job_raw();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			self::unschedule_background_worker();
			return null;
		}

		self::ensure_recurring_pulse();
		self::maybe_release_stale_step_lock();
		self::force_release_step_lock_if_idle();

		$job = self::get_job_raw();

		if ( self::is_job_api_cooldown_active( $job ) ) {
			self::schedule_chain_safety_pulse( self::CRON_FAST_INTERVAL_SEC );

			$out = self::normalize_job_for_response( $job, false );
			$out['api_cooldown'] = true;

			return $out;
		}

		if ( self::should_prioritize_elementor_partial( $job ) ) {
			self::run_pinned_elementor_work();

			self::schedule_chain_safety_pulse( self::CRON_FAST_INTERVAL_SEC );

			$out = self::normalize_job_for_response( self::get_job_raw(), false );
			$out['as_processed']   = 1;
			$out['worker_mode']    = 'cron';
			$out['elementor_direct'] = true;

			return $out;
		}

		if ( Job_Action_Scheduler::is_available() ) {
			Job_Action_Scheduler::ensure_scheduled();
			$processed = Job_Action_Scheduler::run_queue_inline( true );

			self::schedule_chain_safety_pulse( self::CRON_FAST_INTERVAL_SEC );

			$out = self::normalize_job_for_response( self::get_job_raw(), false );
			$out['as_processed'] = $processed;
			$out['worker_mode']  = 'as';
			return $out;
		}

		// Fallback without WooCommerce/AS: run a bounded batch inside this cron hit.
		return self::run_action_scheduler_batch();
	}

	/**
	 * @deprecated Alias for keep_alive_as_worker.
	 * @return array<string, mixed>|null
	 */
	public static function keep_alive_cli_worker() {
		return self::keep_alive_as_worker();
	}

	/**
	 * Run one bounded worker batch from admin REST, cron, or AS (trusted context).
	 *
	 * @param int|null $max_steps  Optional step cap.
	 * @param int|null $budget_sec Optional wall-clock budget.
	 * @return array<string, mixed>
	 */
	public static function run_inline_worker_tick( $max_steps = null, $budget_sec = null ) {
		self::$trusted_admin_worker = true;
		self::$trusted_as_tick      = true;
		self::$trusted_job_tick     = true;

		try {
			$job = self::get_job_raw();

			if ( self::is_job_api_cooldown_active( $job ) ) {
				$out = self::normalize_job_for_response( $job, false );
				$out['api_cooldown'] = true;

				return $out;
			}

			self::bootstrap_job_worker_context();
			self::force_release_step_lock_if_idle();
			Job_Action_Scheduler::clear_slice_mutex_if_stale();

			$job = self::get_job_raw();

			if ( self::should_prioritize_elementor_partial( $job ) ) {
				return self::run_elementor_page_burst();
			}

			if ( Job_Action_Scheduler::is_available() ) {
				$job        = self::get_job_raw();
				$last_real  = self::get_worker_real_activity_at( $job );
				$force_as   = $last_real <= 0 || ( time() - $last_real ) >= self::get_ensure_inline_idle_sec();
				$processed  = Job_Action_Scheduler::run_queue_inline( $force_as );

				if ( $processed > 0 ) {
					return self::normalize_job_for_response( self::get_job_raw(), false );
				}
			}

			$steps  = null === $max_steps ? 2 : max( 1, absint( $max_steps ) );
			$budget = null === $budget_sec ? self::get_cron_step_budget_sec() : max( 30, absint( $budget_sec ) );

			return self::run_action_scheduler_batch( $steps, $budget );
		} finally {
			self::$trusted_admin_worker = false;
		}
	}

	/**
	 * Bounded multi-slice batch used by Action Scheduler callbacks and cron fallback.
	 *
	 * @param int|null $max_steps_override Optional step cap.
	 * @param int|null $budget_override    Optional wall-clock budget in seconds.
	 * @return array<string, mixed>
	 */
	public static function run_action_scheduler_batch( $max_steps_override = null, $budget_override = null ) {
		self::$trusted_as_tick        = true;
		self::$trusted_job_tick       = true;
		self::$skip_chain_after_tick  = true;

		self::bootstrap_job_worker_context();

		add_filter( 'polymart_ai_job_step_max_elementor_chunks', array( __CLASS__, 'filter_as_elementor_chunk_budget' ) );
		add_filter( 'polymart_ai_job_stuck_progress_limit', array( __CLASS__, 'filter_elementor_stuck_progress_limit' ), 10, 3 );
		add_filter( 'polymart_ai_elementor_inter_request_delay_sec', array( __CLASS__, 'filter_elementor_burst_inter_delay' ) );

		try {
			return self::process_background_step( $max_steps_override, $budget_override );
		} finally {
			remove_filter( 'polymart_ai_job_step_max_elementor_chunks', array( __CLASS__, 'filter_as_elementor_chunk_budget' ) );
			remove_filter( 'polymart_ai_job_stuck_progress_limit', array( __CLASS__, 'filter_elementor_stuck_progress_limit' ), 10 );
			remove_filter( 'polymart_ai_elementor_inter_request_delay_sec', array( __CLASS__, 'filter_elementor_burst_inter_delay' ) );

			self::$skip_chain_after_tick = false;
			self::$trusted_as_tick       = false;
			self::$trusted_job_tick      = false;
		}
	}

	/**
	 * @deprecated CLI worker removed — routes to AS batch for any leftover callers.
	 *
	 * @param array<string, mixed> $args Unused except max_steps/max_seconds mapped loosely.
	 * @return array<string, mixed>
	 */
	public static function run_cli_worker( array $args = array() ) {
		$max_steps = isset( $args['max_steps'] ) ? absint( $args['max_steps'] ) : null;
		if ( $max_steps <= 0 ) {
			$max_steps = null;
		}
		$result = self::run_action_scheduler_batch( $max_steps );
		if ( is_array( $result ) ) {
			$result['worker_mode'] = 'as';
			$result['exit_reason'] = $result['exit_reason'] ?? 'as_batch';
		}
		return is_array( $result ) ? $result : self::normalize_job_for_response( self::get_job_raw(), false );
	}

	/**
	 * Park/defer a failing item, release locks, keep the job running, and log details.
	 *
	 * @param string               $message Error message.
	 * @param int                  $post_id Post ID if known.
	 * @param array<string, mixed> $context Extra log context.
	 * @return array<string, mixed>
	 */
	public static function recover_job_item_failure( $message, $post_id = 0, array $context = array() ) {
		$job     = self::get_job_raw();
		$lang    = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
		$post_id = absint( $post_id );
		$message = is_string( $message ) ? $message : wp_json_encode( $message );

		if ( $post_id <= 0 ) {
			$post_id = absint( $job['current_post_id'] ?? 0 );
		}

		if ( $post_id <= 0 ) {
			$post_id = absint( $job['partial_post_id'] ?? 0 );
		}

		if ( $post_id <= 0 && is_array( $job['last_step'] ?? null ) ) {
			$post_id = absint( $job['last_step']['post_id'] ?? 0 );
		}

		if ( $post_id <= 0 ) {
			return self::recover_worker_infrastructure_failure( $message, $context );
		}

		$title = (string) ( get_the_title( $post_id ) ?: '' );

		self::log(
			'error',
			sprintf(
				/* translators: 1: post ID, 2: title, 3: error */
				__( 'مورد #%1$d «%2$s» خطا داد و کنار گذاشته شد — صف ادامه می‌یابد. خطا: %3$s', 'polymart-ai' ),
				$post_id,
				'' !== $title ? $title : __( '(نامشخص)', 'polymart-ai' ),
				$message
			),
			array_merge(
				array(
					'post_id' => $post_id,
					'lang'    => $lang,
					'cli'     => 1,
				),
				$context
			)
		);

		if ( $post_id > 0 && '' !== $lang ) {
			Post_Translator::release_translation_lock( $post_id, $lang, true );
		}

		self::release_step_lock();

		// Re-load after lock release in case another writer touched state.
		$job = self::get_job_raw();

		if ( $post_id > 0 ) {
			self::park_job_post( $job, $post_id, $lang );
			self::remove_post_from_deferred_queue( $job, $post_id );

			// Don't keep pinning a crashing partial — move forward in the queue.
			if ( absint( $job['partial_post_id'] ?? 0 ) === $post_id ) {
				$job['partial_post_id']  = null;
				$job['partial_phase']    = null;
				$job['partial_progress'] = null;
			}

			$job['failed'] = (int) ( $job['failed'] ?? 0 ) + 1;
			self::set_job_last_step( $job, $post_id, 'failed', $message );
			$job['last_post_id'] = max( absint( $job['last_post_id'] ?? 0 ), $post_id );
			self::increment_job_step( $job );
		}

		// Never leave the bulk job paused because of one bad product (auth/billing still stops).
		$status = (string) ( $job['status'] ?? '' );

		if ( ! in_array( $status, array( 'idle', 'completed' ), true ) ) {
			if ( ! ( 'paused' === $status && self::is_job_pause_auth_critical( $job ) ) ) {
				$job['status']       = 'running';
				$job['pause_reason'] = null;
			}
		}

		$job['current_post_id']        = null;
		$job['step_started_at']        = null;
		$job['consecutive_post_id']    = 0;
		$job['consecutive_post_steps'] = 0;
		$job['stuck_progress_steps']   = 0;
		$job['last_error']             = $message;
		$job['worker_heartbeat_at']    = time();
		$job['last_cron_at']           = time();
		$job['last_worker']            = self::$trusted_as_tick ? 'as' : self::resolve_last_worker_label();

		self::save_job( $job );

		return self::normalize_job_for_response( $job, false );
	}

	/**
	 * Recover from worker/AS infrastructure failures (no specific post to blame).
	 *
	 * @param string               $message Error message.
	 * @param array<string, mixed> $context Extra log context.
	 * @return array<string, mixed>
	 */
	public static function recover_worker_infrastructure_failure( $message, array $context = array() ) {
		$message = is_string( $message ) ? $message : wp_json_encode( $message );

		self::log(
			'warning',
			sprintf(
				/* translators: %s: error message */
				__( 'خطای زیرساخت کارگر ترجمه — صف ادامه می‌یابد. خطا: %s', 'polymart-ai' ),
				$message
			),
			$context
		);

		self::force_unlock_step_now();

		$job = self::get_job_raw();

		if ( 'running' === ( $job['status'] ?? '' ) ) {
			$job['worker_heartbeat_at'] = time();
			$job['last_cron_at']        = time();
			$job['last_worker']         = self::$trusted_as_tick ? 'as' : self::resolve_last_worker_label();
			self::save_job( $job );
		}

		return self::normalize_job_for_response( $job, false );
	}

	/**
	 * Whether a paused job is an auth/billing stop (must not auto-resume).
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return bool
	 */
	private static function is_job_pause_auth_critical( array $job ) {
		if ( 'critical' !== (string) ( $job['pause_reason'] ?? '' ) ) {
			return false;
		}

		$message = strtolower( (string) ( $job['last_error'] ?? '' ) );

		$auth_keywords = array(
			'unauthorized',
			'invalid api',
			'api key',
			'authentication',
			'billing',
			'quota',
			'missing_credentials',
			'missing_api_key',
			'توکن',
			'کلید api',
			'سهمیه',
			'اعتبار',
		);

		foreach ( $auth_keywords as $keyword ) {
			if ( false !== strpos( $message, $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Seconds of silence before ensure may force a keep-alive / spawn.
	 *
	 * @return int
	 */
	private static function get_ensure_inline_idle_sec() {
		$job     = self::get_job_raw();
		$default = self::should_prioritize_elementor_partial( $job ) ? 14 : 12;

		return max( 8, min( 90, (int) apply_filters( 'polymart_ai_job_ensure_inline_idle_sec', $default ) ) );
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
		Job_Action_Scheduler::ensure_scheduled();

		$lang = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
		$last = max(
			absint( $job['last_cron_at'] ?? 0 ),
			absint( $job['worker_heartbeat_at'] ?? 0 )
		);
		$age  = $last > 0 ? ( time() - $last ) : PHP_INT_MAX;

		if ( $age >= self::PICK_STALL_SEC || self::is_step_lock_held_fresh() ) {
			$job = self::get_job_raw();
			if ( self::recover_stalled_job_picker( $job, $lang, false ) ) {
				self::save_job( $job );
			}
		}

		if ( $age >= self::get_ensure_inline_idle_sec() || ! Job_Action_Scheduler::has_pending_or_running() ) {
			self::keep_alive_as_worker();
		}
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

		if ( '' !== $lang && 'paused' === ( $job['status'] ?? '' ) ) {
			$job = self::attach_stalled_job_details( $job, $lang );
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

		if ( '' !== $lang && 'paused' === ( $job['status'] ?? '' ) ) {
			$job = self::attach_stalled_job_details( $job, $lang );
		}

		return $job;
	}

	/**
	 * Attach human-readable details for paused/stalled jobs.
	 *
	 * @param array<string, mixed> $job  Job state.
	 * @param string               $lang Target language code.
	 * @return array<string, mixed>
	 */
	private static function attach_stalled_job_details( array $job, $lang ) {
		$lang               = sanitize_key( (string) $lang );
		$job['stalled_details'] = array();
		$stalled_ids        = is_array( $job['stalled_ids'] ?? null ) ? array_map( 'absint', $job['stalled_ids'] ) : array();

		if ( empty( $stalled_ids ) ) {
			$stalled_ids = array_values(
				array_filter(
					array_map(
						'absint',
						wp_list_pluck(
							Translation_Query::collect_storefront_translation_issues( $lang, 5 ),
							'post_id'
						)
					)
				)
			);
		}

		foreach ( array_slice( $stalled_ids, 0, 5 ) as $stalled_id ) {
			if ( $stalled_id <= 0 ) {
				continue;
			}

			$gaps = Post_Translator::get_translation_gaps( $stalled_id, $lang );

			if ( 'translated' === ( $gaps['status'] ?? '' ) && ! Post_Translator::storefront_would_show_persian_source( $stalled_id, $lang ) ) {
				continue;
			}

			$job['stalled_details'][] = array(
				'post_id' => $stalled_id,
				'title'   => get_the_title( $stalled_id ),
				'status'  => $gaps['status'] ?? 'partial',
				'missing' => $gaps['missing'],
				'fields'  => $gaps['fields'] ?? array(),
				'notes'   => $gaps['notes'] ?? array(),
				'reason'  => Post_Translator::describe_translation_gap( $stalled_id, $lang ),
			);
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
		$as_status             = Job_Action_Scheduler::status_payload();
		$job['as_available']   = ! empty( $as_status['available'] );
		$job['as_pending']     = ! empty( $as_status['pending'] );
		$job['worker_mode']    = ! empty( $as_status['available'] ) ? 'as' : 'cron';
		$job['elementor_progress_stalled'] = self::is_elementor_progress_stalled( $job );
		$job['cli_alive']      = false;
		$job['cli_spawn_ok']   = false;
		$job['recent_logs']    = self::get_recent_job_logs( $job, 60 );

		$cooldown_remaining = self::get_job_api_cooldown_remaining( $job );
		if ( $cooldown_remaining > 0 ) {
			$job['api_cooldown_remaining'] = $cooldown_remaining;
			$job                         = self::apply_api_cooldown_job_presentation( $job, $cooldown_remaining );
		}

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
		// Allow 0 for immediate follow-up when the host's wp-cron.php spawn works.
		return max( 0, min( 5, (int) apply_filters( 'polymart_ai_job_cron_chain_delay_sec', self::CRON_INTERVAL_SEC ) ) );
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

		$job = self::get_job_raw();

		if ( self::is_elementor_progress_stalled( $job ) ) {
			$lang    = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
			$post_id = absint( $job['partial_post_id'] ?? 0 ) ?: absint( $job['current_post_id'] ?? 0 );

			if ( $post_id > 0 && '' !== $lang ) {
				Post_Translator::release_stale_translation_lock( $post_id, $lang );
			}

			self::force_unlock_step_now();

			return true;
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
		$pick_started = absint( $job['pick_started_at'] ?? 0 );
		$pick_stuck     = ( 0 === $current )
			&& $pick_started > 0
			&& ( time() - $pick_started ) >= self::PICK_STALL_SEC;

		$idle_orphan = ( 0 === $current )
			&& $idle_for >= self::LOCK_IDLE_ORPHAN_SEC
			&& $lock_age >= self::LOCK_IDLE_ORPHAN_SEC
			&& ! self::is_bulk_worker_lively( self::LOCK_IDLE_ORPHAN_SEC );

		// Living worker stopped touching the lock for the full TTL.
		$ttl_expired = $lock_age >= self::STEP_LOCK_TTL;

		if ( $pick_stuck ) {
			$lang = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
			self::recover_stalled_job_picker( $job, $lang, false );
		}

		if ( ! $worker_dead && ! $idle_orphan && ! $ttl_expired && ! $pick_stuck ) {
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
		$partial = absint( $job['partial_post_id'] ?? 0 );

		// Elementor burst can run many API calls in one request — allow a longer silence window.
		if ( $partial > 0 && self::should_prioritize_elementor_partial( $job ) ) {
			$idle_sec = max( $idle_sec, (int) apply_filters( 'polymart_ai_elementor_burst_lock_idle_sec', 150 ) );
		}

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

		set_transient( self::STEP_LOCK_KEY, $now, self::STEP_LOCK_TTL );
		update_option( self::STEP_LOCK_CLAIM_KEY, $now, false );
	}

	/**
	 * Lightweight job snapshot for Action Scheduler logging (no expensive sync).
	 *
	 * @return array<string, mixed>
	 */
	public static function get_job_for_as_debug() {
		return self::get_job_raw();
	}

	/**
	 * Immediately release the global step lock (AS fatal / stale recovery).
	 *
	 * @return void
	 */
	public static function force_unlock_step_now() {
		$job = self::get_job_raw();

		// Only clear the global step lock. Per-post translation locks are owner-bound
		// and must not be stolen from a live Elementor slice mid-API-call.
		self::release_step_lock();

		$job['current_post_id'] = null;
		$job['step_started_at'] = null;
		$job['worker_heartbeat_at'] = time();
		self::save_job( $job );
	}

	/**
	 * Break plugin locks when an AS action has been silent longer than $idle_sec.
	 *
	 * @param int $idle_sec Silence threshold (default 60).
	 * @return bool True when something was released.
	 */
	public static function release_stale_locks_for_as( $idle_sec = 60 ) {
		$idle_sec = max( 30, absint( $idle_sec ) );

		if ( self::is_bulk_worker_lively( $idle_sec ) ) {
			return false;
		}

		$job  = self::get_job_raw();
		$last = max(
			absint( $job['last_cron_at'] ?? 0 ),
			absint( $job['worker_heartbeat_at'] ?? 0 ),
			absint( $job['step_started_at'] ?? 0 )
		);
		$age = $last > 0 ? ( time() - $last ) : PHP_INT_MAX;

		$has_lock = (bool) get_transient( self::STEP_LOCK_KEY ) || (bool) get_option( self::STEP_LOCK_CLAIM_KEY );

		if ( $age < $idle_sec && $has_lock ) {
			return self::maybe_release_stale_step_lock();
		}

		if ( $age < $idle_sec && ! $has_lock ) {
			return false;
		}

		self::force_unlock_step_now();

		$job     = self::get_job_raw();
		$lang    = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
		$post_id = absint( $job['partial_post_id'] ?? 0 ) ?: absint( $job['current_post_id'] ?? 0 );

		if ( $post_id > 0 && '' !== $lang ) {
			Post_Translator::release_stale_translation_lock( $post_id, $lang, $idle_sec );
		}

		self::log(
			'warning',
			sprintf(
				/* translators: %d: idle seconds */
				__( 'قفل ترجمه پس از %dث سکوت آزاد شد (بازیابی Action Scheduler).', 'polymart-ai' ),
				$idle_sec
			)
		);

		return true;
	}

	/**
	 * Persist a lightweight heartbeat while a tick is in flight.
	 *
	 * @return void
	 */
	public static function touch_job_worker_heartbeat() {
		self::touch_worker_heartbeat();
	}

	/**
	 * Push live Elementor slice progress to the bulk job option (SPA monitor polls this).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return void
	 */
	public static function sync_elementor_partial_progress( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return;
		}

		$job = self::get_job_raw();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return;
		}

		$marker = Post_Translator::format_elementor_job_progress_marker( $post_id, $lang );

		if ( '' === $marker || ! preg_match( '/^\d+\/\d+$/', $marker ) ) {
			return;
		}

		$job['partial_post_id']  = $post_id;
		$job['partial_phase']    = 'elementor';
		$job['partial_progress'] = $marker;
		$job['step_partial']     = true;
		self::touch_partial_progress_tracker( $job );
		$job['worker_heartbeat_at'] = time();
		$job['last_cron_at']        = time();
		self::save_job( $job );
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
		$job['last_worker']         = self::resolve_last_worker_label();
		// Avoid full merge side-effects during mid-step touches.
		$job['updated_at'] = time();
		update_option( self::JOB_OPTION, $job, false );
		self::touch_step_lock();
	}

	/**
	 * Latest timestamp that reflects real worker progress (not schedule-only nudges).
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return int Unix timestamp.
	 */
	private static function get_worker_real_activity_at( array $job ) {
		$lock  = get_transient( self::STEP_LOCK_KEY );
		$claim = absint( get_option( self::STEP_LOCK_CLAIM_KEY, 0 ) );
		$stamp = max( $lock ? absint( $lock ) : 0, $claim );

		return max(
			$stamp,
			absint( $job['last_cron_at'] ?? 0 ),
			absint( $job['worker_heartbeat_at'] ?? 0 ),
			absint( $job['step_started_at'] ?? 0 ),
			absint( $job['partial_progress_at'] ?? 0 )
		);
	}

	/**
	 * Whether the worker has checked in recently (heartbeat or completed tick).
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return bool
	 */
	private static function is_worker_heartbeat_fresh( array $job ) {
		$last = self::get_worker_real_activity_at( $job );

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
		$message = sanitize_text_field( self::humanize_api_error_message( (string) $message ) );

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

			$progress = self::format_job_partial_progress( $partial, $post_id, $lang );

			if ( '' !== $progress ) {
				$message = trim( $message . ' [' . $phase_label . ' ' . $progress . ']' );
			}
		}

		// Elementor partials already show X/Y — skip the technical "Elementor (JSON)" gap label.
		if ( 'elementor' === $phase ) {
			return $message;
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
	private static function format_job_partial_progress( array $state, $post_id = 0, $lang = '' ) {
		$phase = sanitize_key( (string) ( $state['phase'] ?? '' ) );

		if ( 'elementor' === $phase ) {
			$post_id = absint( $post_id );
			$lang    = sanitize_key( (string) $lang );

			if ( $post_id > 0 && '' !== $lang ) {
				return Post_Translator::format_elementor_job_progress_marker( $post_id, $lang, $state );
			}

			$done  = count( is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array() );
			$total = max( $done, absint( $state['elementor_chunks_total'] ?? 0 ) );

			return $done . '/' . max( 1, $total );
		}

		$index = absint( $state['field_chunk_index'] ?? 0 );

		return $index > 0 ? (string) $index : '';
	}

	/**
	 * Parse a job progress marker like "3/8".
	 *
	 * @param string $progress Progress marker.
	 * @return array{done: int, total: int}|null
	 */
	private static function parse_job_phase_progress( $progress ) {
		$progress = trim( (string) $progress );

		if ( ! preg_match( '/^(\d+)\/(\d+)$/', $progress, $matches ) ) {
			return null;
		}

		return array(
			'done'  => absint( $matches[1] ),
			'total' => absint( $matches[2] ),
		);
	}

	/**
	 * Whether an in-progress Elementor slice still has batches left.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return bool
	 */
	private static function elementor_partial_has_remaining( array $job ) {
		$lang = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

		if ( self::job_has_active_elementor_pin( $job, $lang ) ) {
			return true;
		}

		if ( 'elementor' !== sanitize_key( (string) ( $job['partial_phase'] ?? '' ) ) ) {
			return false;
		}

		$post_id = absint( $job['partial_post_id'] ?? 0 );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		if ( Post_Translator::elementor_job_api_slices_pending( $post_id, $lang ) ) {
			return true;
		}

		return Post_Translator::should_run_elementor_job_slice( $post_id, $lang );
	}

	/**
	 * Whether the job must keep working the pinned post's Elementor JSON to completion.
	 *
	 * @param array<string, mixed> $job  Job state.
	 * @param string               $lang Target language code.
	 * @return bool
	 */
	private static function job_has_active_elementor_pin( array $job, $lang ) {
		$lang    = sanitize_key( (string) $lang );
		$post_id = absint( $job['partial_post_id'] ?? 0 );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$job_phase  = sanitize_key( (string) ( $job['partial_phase'] ?? '' ) );
		$post_state = Post_Translator::get_job_partial_state( $post_id, $lang );
		$post_phase = sanitize_key( (string) ( $post_state['phase'] ?? '' ) );

		if ( 'elementor' !== $job_phase && 'elementor' !== $post_phase ) {
			return false;
		}

		return Post_Translator::elementor_job_api_slices_pending( $post_id, $lang );
	}

	/**
	 * Whether a post still has an in-progress Elementor job slice (post meta state).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	private static function post_has_elementor_job_state( $post_id, $lang ) {
		return Post_Translator::should_run_elementor_job_slice( $post_id, $lang );
	}

	/**
	 * Pin the next deferred Elementor partial when the job lost its inline pin after a defer.
	 *
	 * @param array<string, mixed> $job Job state (by reference).
	 * @return bool True when a post was pinned.
	 */
	private static function maybe_restore_elementor_pin_from_queue( array &$job ) {
		$lang = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

		if ( '' === $lang || self::job_has_active_elementor_pin( $job, $lang ) ) {
			return false;
		}

		$candidates = array();

		foreach ( array( absint( $job['partial_post_id'] ?? 0 ), absint( $job['current_post_id'] ?? 0 ) ) as $candidate_id ) {
			if ( $candidate_id > 0 ) {
				$candidates[] = $candidate_id;
			}
		}

		foreach ( self::get_actionable_deferred_queue( $job, $lang ) as $deferred_id ) {
			$candidates[] = absint( $deferred_id );
		}

		foreach ( array_unique( array_filter( $candidates ) ) as $post_id ) {
			if ( ! self::post_has_elementor_job_state( $post_id, $lang ) ) {
				continue;
			}

			self::pin_job_for_elementor_post( $job, $post_id, $lang );

			return true;
		}

		return false;
	}

	/**
	 * Pin the bulk job to one post until Elementor slices finish.
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Target language code.
	 * @return void
	 */
	private static function pin_job_for_elementor_post( array &$job, $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return;
		}

		if ( ! Post_Translator::should_run_elementor_job_slice( $post_id, $lang ) ) {
			return;
		}

		$state = Post_Translator::get_job_partial_state( $post_id, $lang );
		$state = Post_Translator::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
		Post_Translator::save_job_partial_state( $post_id, $lang, $state );

		$job['partial_post_id']  = $post_id;
		$job['partial_phase']    = 'elementor';
		$job['partial_progress'] = Post_Translator::format_elementor_job_progress_marker( $post_id, $lang, $state );
		self::touch_partial_progress_tracker( $job );
		$job['step_partial']     = true;
		$job['current_post_id']  = null;
		$job['step_started_at']  = null;
	}

	/**
	 * Whether the job should keep working the pinned Elementor post to completion.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return bool
	 */
	public static function should_prioritize_elementor_partial( array $job ) {
		$lang = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

		if ( self::job_has_active_elementor_pin( $job, $lang ) ) {
			return true;
		}

		if ( self::elementor_partial_has_remaining( $job ) ) {
			return true;
		}

		$active_id = absint( $job['current_post_id'] ?? 0 ) ?: absint( $job['partial_post_id'] ?? 0 );

		if ( $active_id > 0 && Post_Translator::elementor_job_api_slices_pending( $active_id, $lang ) ) {
			return true;
		}

		$deferred = self::get_actionable_deferred_queue( $job, $lang );

		if ( ! empty( $deferred[0] ) && Post_Translator::elementor_job_api_slices_pending( (int) $deferred[0], $lang ) ) {
			return true;
		}

		return false;
	}

	/**
	 * How many job steps an AS batch may run while an Elementor page is mid-slice.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return int
	 */
	public static function get_elementor_partial_step_cap( array $job ) {
		if ( ! self::should_prioritize_elementor_partial( $job ) ) {
			return 1;
		}

		$parsed = self::parse_job_phase_progress( (string) ( $job['partial_progress'] ?? '' ) );
		$left   = is_array( $parsed ) && $parsed['total'] > 0
			? max( 1, $parsed['total'] - $parsed['done'] )
			: 8;
		$max    = self::is_elementor_progress_stalled( $job ) ? 4 : 2;

		return max( 1, min( $max, $left ) );
	}

	/**
	 * Queue a fast follow-up tick while an Elementor page is mid-slice.
	 *
	 * @param int|null $delay_sec Seconds until the next pulse/action.
	 * @return void
	 */
	public static function schedule_elementor_partial_follow_up( $delay_sec = null ) {
		if ( ! self::is_bulk_job_running() ) {
			return;
		}

		$job = self::get_job_raw();

		if ( ! self::should_prioritize_elementor_partial( $job ) ) {
			return;
		}

		$delay_sec = null === $delay_sec
			? max( 5, (int) apply_filters( 'polymart_ai_elementor_follow_up_delay_sec', 8 ) )
			: max( 0, absint( $delay_sec ) );

		self::schedule_chain_safety_pulse( $delay_sec );

		if ( Job_Action_Scheduler::is_available() ) {
			Job_Action_Scheduler::enqueue_next( true, $delay_sec );
		}
	}

	/**
	 * Step/budget limits for inline worker ticks (admin REST, start/resume, recovery).
	 *
	 * Elementor slices need one full Arvan HTTP round-trip (~50s) plus persist overhead.
	 *
	 * @param array<string, mixed>|null $job Job state.
	 * @return array{steps: int, budget: int}
	 */
	public static function get_inline_worker_tick_limits( $job = null ) {
		$job = is_array( $job ) ? $job : self::get_job_raw();

		if ( self::should_prioritize_elementor_partial( $job ) ) {
			$cap = self::get_elementor_partial_step_cap( $job );

			return array(
				'steps'  => max( 1, min( 2, $cap ) ),
				'budget' => max( 150, min( 300, $cap * 80 ) ),
			);
		}

		return array(
			'steps'  => 1,
			'budget' => 60,
		);
	}

	/**
	 * More Elementor batches per step when Action Scheduler is driving the job.
	 *
	 * @return int
	 */
	public static function filter_as_elementor_chunk_budget() {
		if ( self::$elementor_page_burst ) {
			return 25;
		}

		return 1;
	}

	/**
	 * Shorter gap between Elementor API calls during a single-page burst.
	 *
	 * @param int $delay Default delay seconds.
	 * @return int
	 */
	public static function filter_elementor_burst_inter_delay( $delay ) {
		if ( self::$elementor_page_burst ) {
			return 2;
		}

		return (int) $delay;
	}

	/**
	 * Whether the worker is finishing an entire Elementor page in one request.
	 *
	 * @return bool
	 */
	public static function is_elementor_page_burst() {
		return self::$elementor_page_burst;
	}

	/**
	 * Run every remaining Elementor batch for the pinned post in one admin/cron request.
	 *
	 * Root fix for Local Sites / no-AS hosts: no idle gap between chunks 9→10→11.
	 *
	 * @param int|null $budget_sec Wall-clock budget.
	 * @return array<string, mixed>
	 */
	public static function run_elementor_page_burst( $budget_sec = null ) {
		$job = self::get_job_raw();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return self::normalize_job_for_response( $job, false );
		}

		if ( self::is_job_api_cooldown_active( $job ) ) {
			$out = self::normalize_job_for_response( $job, false );
			$out['api_cooldown'] = true;

			return $out;
		}

		$post_id = absint( $job['partial_post_id'] ?? 0 );
		$lang    = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

		if ( $post_id <= 0 || '' === $lang ) {
			return self::normalize_job_for_response( $job, false );
		}

		self::$trusted_admin_worker = true;
		self::$trusted_as_tick      = true;
		self::$trusted_job_tick     = true;
		self::$elementor_page_burst = true;

		try {
			self::bootstrap_job_worker_context();
			self::touch_job_worker_heartbeat();
			self::force_release_step_lock_if_idle( 45 );
			Job_Action_Scheduler::clear_slice_mutex_if_stale();
			Post_Translator::release_stale_translation_lock( $post_id, $lang );

			$budget = null === $budget_sec
				? max( 180, (int) apply_filters( 'polymart_ai_elementor_page_burst_budget_sec', 300 ) )
				: max( 120, absint( $budget_sec ) );

			$result = self::run_action_scheduler_batch( 1, $budget );

			$job = self::get_job_raw();
			$job['worker_direct_tick'] = true;
			$job['elementor_burst']  = true;
			self::save_job( $job );

			if ( is_array( $result ) ) {
				$result['worker_direct_tick'] = true;
				$result['elementor_burst']    = true;
			}

			if (
				! Post_Translator::elementor_job_api_slices_pending( $post_id, $lang )
				&& ! Post_Translator::should_run_elementor_job_slice( $post_id, $lang )
			) {
				return is_array( $result ) ? $result : self::normalize_job_for_response( self::get_job_raw(), false );
			}

			self::schedule_elementor_partial_follow_up( 4 );

			return is_array( $result ) ? $result : self::normalize_job_for_response( self::get_job_raw(), false );
		} finally {
			self::$elementor_page_burst = false;
			self::$trusted_admin_worker = false;
		}
	}

	/**
	 * Whether a pinned Elementor post stopped advancing (AS queue not running).
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return bool
	 */
	public static function is_elementor_progress_stalled( array $job ) {
		if ( ! self::should_prioritize_elementor_partial( $job ) ) {
			return false;
		}

		$now          = time();
		$progress_at  = absint( $job['partial_progress_at'] ?? 0 );
		$last_cron    = absint( $job['last_cron_at'] ?? 0 );
		$step_started = absint( $job['step_started_at'] ?? 0 );
		$stall_sec    = (int) apply_filters( 'polymart_ai_elementor_progress_stall_sec', 75, $job );

		if ( $step_started > 0 && ( $now - $step_started ) < 150 ) {
			return false;
		}

		if ( $progress_at > 0 && ( $now - $progress_at ) < max( 60, $stall_sec ) ) {
			return false;
		}

		if ( $last_cron > 0 && ( $now - $last_cron ) < max( 60, $stall_sec ) ) {
			return false;
		}

		$heartbeat = absint( $job['worker_heartbeat_at'] ?? 0 );

		if ( $heartbeat > 0 && ( $now - $heartbeat ) < max( 60, $stall_sec ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Run Elementor slice batches inline (cron/UI path — bypasses AS queue runner).
	 *
	 * @param bool $log_stalled When true, log when progress was silent before this burst.
	 * @return bool True when work ran.
	 */
	public static function run_pinned_elementor_work( $log_stalled = false ) {
		if ( self::$elementor_burst_inflight ) {
			return false;
		}

		$job = self::get_job_raw();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return false;
		}

		if ( self::maybe_restore_elementor_pin_from_queue( $job ) ) {
			self::save_job( $job );
		}

		if ( ! self::should_prioritize_elementor_partial( $job ) ) {
			return false;
		}

		if ( self::is_job_api_cooldown_active( $job ) ) {
			return false;
		}

		$post_id = absint( $job['partial_post_id'] ?? 0 );
		$lang    = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
		$stalled = $log_stalled || self::is_elementor_progress_stalled( $job );
		$before_progress = (string) ( $job['partial_progress'] ?? '' );
		$before_cron     = absint( $job['last_cron_at'] ?? 0 );

		if ( $post_id > 0 && '' !== $lang ) {
			Post_Translator::release_stale_translation_lock( $post_id, $lang );
		}

		if ( $stalled ) {
			self::force_release_step_lock_if_idle( 45 );
		}

		$before  = $before_progress;

		if ( $stalled ) {
			self::log(
				'warning',
				sprintf(
					/* translators: 1: post ID, 2: progress marker */
					__( 'اجرای مستقیم Elementor (صف AS اجرا نشد) — #%1$d (%2$s)', 'polymart-ai' ),
					$post_id,
					'' !== $before ? $before : '…'
				),
				array( 'post_id' => $post_id )
			);
		}

		self::$elementor_burst_inflight = true;
		self::log(
			'info',
			sprintf(
				/* translators: 1: post ID, 2: progress marker */
				__( 'Elementor burst — تکمیل یک‌درخواستی #%1$d (%2$s)', 'polymart-ai' ),
				$post_id,
				'' !== $before ? $before : '…'
			),
			array( 'post_id' => $post_id )
		);

		try {
			$result = self::run_elementor_page_burst( 300 );
			$worked = is_array( $result )
				&& (
					! empty( $result['elementor_burst'] )
					|| (string) ( $result['partial_progress'] ?? '' ) !== $before_progress
					|| absint( $result['last_cron_at'] ?? 0 ) > $before_cron
				);
		} finally {
			self::$elementor_burst_inflight = false;
		}

		return $worked;
	}

	/**
	 * Run translation work inline when Action Scheduler only queued but never executed.
	 *
	 * @return bool True when a direct burst ran.
	 */
	public static function run_direct_elementor_burst() {
		return self::run_pinned_elementor_work( true );
	}

	/**
	 * Record when Elementor slice progress last advanced.
	 *
	 * @param array<string, mixed> $job Job state (by reference).
	 * @return void
	 */
	private static function touch_partial_progress_tracker( array &$job ) {
		$progress = (string) ( $job['partial_progress'] ?? '' );

		if ( '' === $progress ) {
			return;
		}

		$tracked = (string) ( $job['partial_progress_marker'] ?? '' );

		if ( $progress !== $tracked ) {
			self::log_partial_progress_saved( $job, $tracked, $progress );

			$job['partial_progress_marker'] = $progress;
			$job['partial_progress_at']     = time();
		}
	}

	/**
	 * Write a live activity-log line when durable partial progress advances.
	 *
	 * @param array<string, mixed> $job      Job state.
	 * @param string               $previous Previous progress marker.
	 * @param string               $current  New progress marker.
	 * @return void
	 */
	private static function log_partial_progress_saved( array $job, $previous, $current ) {
		$post_id = absint( $job['partial_post_id'] ?? 0 );
		$phase   = sanitize_key( (string) ( $job['partial_phase'] ?? '' ) );
		$parsed  = self::parse_job_phase_progress( $current );

		if ( $post_id <= 0 || ! is_array( $parsed ) ) {
			return;
		}

		$parsed_prev = self::parse_job_phase_progress( $previous );

		if (
			is_array( $parsed_prev )
			&& $parsed['done'] <= $parsed_prev['done']
			&& $parsed['total'] === $parsed_prev['total']
		) {
			return;
		}

		$title = get_the_title( $post_id );

		if ( '' === $title ) {
			$title = sprintf( '#%d', $post_id );
		} else {
			$title = sprintf( '#%d «%s»', $post_id, $title );
		}

		if ( 'elementor' === $phase ) {
			self::log(
				'success',
				sprintf(
					/* translators: 1: post label, 2: completed chunks, 3: total chunks */
					__( 'Elementor — %1$s: بخش %2$d از %3$d ترجمه و ذخیره شد', 'polymart-ai' ),
					$title,
					$parsed['done'],
					$parsed['total']
				),
				array( 'post_id' => $post_id, 'lang' => (string) ( $job['lang'] ?? '' ) )
			);

			return;
		}

		$phase_label = $phase;

		if ( 'variations' === $phase ) {
			$phase_label = __( 'تنوع‌های محصول', 'polymart-ai' );
		} elseif ( 'commerce' === $phase ) {
			$phase_label = __( 'ویژگی‌ها و دسته‌ها', 'polymart-ai' );
		} elseif ( 'core' === $phase ) {
			$phase_label = __( 'محتوای اصلی', 'polymart-ai' );
		} elseif ( 'fields' === $phase ) {
			$phase_label = __( 'فیلدهای متنی', 'polymart-ai' );
		}

		self::log(
			'success',
			sprintf(
				/* translators: 1: phase label, 2: post label, 3: progress marker */
				__( '%1$s — %2$s: دسته %3$s ذخیره شد', 'polymart-ai' ),
				$phase_label,
				$title,
				$current
			),
			array( 'post_id' => $post_id, 'lang' => (string) ( $job['lang'] ?? '' ) )
		);
	}

	/**
	 * Give Elementor partials more retries before rotating away.
	 *
	 * @param int                  $limit   Default stuck limit.
	 * @param array<string, mixed> $job     Job state.
	 * @param int                  $post_id Post ID.
	 * @return int
	 */
	public static function filter_elementor_stuck_progress_limit( $limit, $job, $post_id ) {
		unset( $post_id );

		if (
			'elementor' === sanitize_key( (string) ( $job['partial_phase'] ?? '' ) )
			|| self::elementor_partial_has_remaining( $job )
		) {
			return 20;
		}

		return (int) $limit;
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
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$phase   = sanitize_key( (string) $phase );

		if (
			'elementor' === $phase
			&& $post_id > 0
			&& (
				Post_Translator::elementor_job_has_remaining_payload( $post_id, $lang )
				|| Post_Translator::post_needs_elementor_job_work( $post_id, $lang )
			)
		) {
			self::pin_job_for_elementor_post( $job, $post_id, $lang );
			$job['stuck_progress_steps']   = 0;
			$job['consecutive_post_id']    = $post_id;
			$job['consecutive_post_steps'] = 0;
			$job['current_post_id']        = null;
			$job['step_started_at']        = null;
			self::increment_job_step( $job );
			self::set_job_last_step(
				$job,
				$post_id,
				'partial',
				sprintf(
					/* translators: %s: elementor progress marker */
					__( 'Elementor — ادامه بخش‌ها (%s)', 'polymart-ai' ),
					(string) ( $job['partial_progress'] ?? $progress )
				)
			);

			return;
		}

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

		/**
		 * Fires before a potentially long stats recompute during background jobs.
		 */
		do_action( 'polymart_ai_worker_heartbeat' );

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
		Post_Translator::release_translation_lock( $post_id, $lang, true );

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
		$existing_status = (string) ( $existing['status'] ?? 'idle' );

		if ( in_array( $existing_status, array( 'running', 'paused' ), true ) ) {
			if ( 'running' === $existing_status && self::is_bulk_worker_lively( 90 ) ) {
				return new \WP_Error(
					'polymart_ai_job_running',
					__( 'یک فرآیند ترجمه خودکار در حال اجراست. ابتدا آن را متوقف یا از سرگیری کنید.', 'polymart-ai' )
				);
			}

			if ( 'running' === $existing_status ) {
				self::log(
					'warning',
					__( 'اجرای قبلی بدون فعالیت رها شده بود — قبل از شروع جدید پاک شد.', 'polymart-ai' )
				);
			}

			self::unschedule_background_worker();
			self::release_step_lock();
			if ( Job_Action_Scheduler::is_available() ) {
				Job_Action_Scheduler::cancel_all();
				Job_Action_Scheduler::clear_slice_mutex();
			}
			self::release_all_job_translation_locks( $existing );
			self::save_job( self::empty_job() );
		}

		// Drop leftover locks from a previous paused/completed run (keep post partial meta).
		self::unschedule_background_worker();
		self::release_step_lock();

		if ( Job_Action_Scheduler::is_available() ) {
			Job_Action_Scheduler::cancel_all();
			Job_Action_Scheduler::clear_slice_mutex();
		}

		self::release_all_job_translation_locks( $existing );

		Post_Translator::flush_translation_status_cache();
		REST_API::invalidate_stats_cache();

		// Fast bootstrap: indexed stats + light storefront probe only (heavy reconcile runs on worker ticks).
		$issues     = Translation_Query::collect_storefront_translation_issues( $lang, 8, true );
		$issue_ids  = array_values( array_filter( array_map( 'absint', wp_list_pluck( $issues, 'post_id' ) ) ) );
		$stats      = Translation_Query::compute_translation_stats( $lang, false );
		$menu_needs = Menu_Translator::count_untranslated( $lang );
		$needs_work = (int) $stats['untranslated'] + (int) $stats['partial'] + $menu_needs;
		$total      = $needs_work;
		$probe_ids  = $issue_ids;

		if ( $total <= 0 && ! empty( $probe_ids ) ) {
			$needs_work = max( count( $probe_ids ), $menu_needs );
			$total      = $needs_work;
		}

		if ( $total <= 0 ) {
			$probe_ids = Translation_Query::probe_priority_unfinished_post_ids( $lang, 8, true );

			if ( ! empty( $probe_ids ) ) {
				$stats      = Translation_Query::compute_translation_stats( $lang, false );
				$needs_work = max(
					count( $probe_ids ),
					(int) $stats['untranslated'] + (int) $stats['partial'],
					$menu_needs
				);
				$total      = $needs_work;
			}
		}

		if ( $total <= 0 ) {
			$probe_post = Translation_Query::find_next_actionable_post_id( $lang, 0 );

			if ( $probe_post <= 0 && $menu_needs <= 0 ) {
				return new \WP_Error(
					'polymart_ai_no_queue',
					self::format_no_queue_diagnostic( $lang, $issues ),
					array( 'issues' => $issues )
				);
			}

			$needs_work = max( 1, $menu_needs );
			$total      = $needs_work;
		}

		$language   = Language_Registry::get_language( $lang );
		$lang_label = $language ? $language['native_name'] : $lang;

		$seed_limit = min( 8, max( 3, $needs_work ) );
		$seed_ids   = Translation_Query::seed_actionable_post_ids( $lang, $seed_limit );

		if ( ! empty( $probe_ids ) ) {
			$seed_ids = array_values(
				array_unique(
					array_merge( $probe_ids, $seed_ids )
				)
			);
			$seed_ids = array_slice( $seed_ids, 0, max( $seed_limit, count( $probe_ids ) ) );
		}

		if ( empty( $seed_ids ) && ! empty( $issue_ids ) ) {
			$seed_ids = array_values( array_unique( array_map( 'absint', $issue_ids ) ) );
		}

		if ( empty( $seed_ids ) ) {
			$seed_ids = Translation_Query::probe_priority_unfinished_post_ids( $lang, $seed_limit, true );
		}

		if ( empty( $seed_ids ) ) {
			$fallback_id = Translation_Query::find_next_actionable_post_id( $lang, 0 );

			if ( $fallback_id > 0 ) {
				$seed_ids = array( $fallback_id );
			}
		}

		$seed_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $seed_ids ),
					static function ( $post_id ) {
						return $post_id > 0;
					}
				)
			)
		);

		if ( empty( $seed_ids ) && ! empty( $issue_ids ) ) {
			$seed_ids = array_values(
				array_unique(
					array_filter(
						array_map( 'absint', $issue_ids ),
						static function ( $post_id ) {
							return $post_id > 0;
						}
					)
				)
			);
		}

		if ( empty( $seed_ids ) && ! empty( $probe_ids ) ) {
			$seed_ids = array_values(
				array_unique(
					array_filter(
						array_map( 'absint', $probe_ids ),
						static function ( $post_id ) {
							return $post_id > 0;
						}
					)
				)
			);
		}

		if ( empty( $seed_ids ) && $menu_needs <= 0 ) {
			return new \WP_Error(
				'polymart_ai_no_queue',
				self::format_no_queue_diagnostic( $lang, $issues ),
				array( 'issues' => $issues )
			);
		}

		if ( ! empty( $issues ) ) {
			$preview = array();

			foreach ( array_slice( $issues, 0, 3 ) as $issue ) {
				$preview[] = sprintf(
					'#%1$d %2$s',
					(int) ( $issue['post_id'] ?? 0 ),
					(string) ( $issue['title'] ?? '' )
				);
			}

			self::log(
				'info',
				sprintf(
					/* translators: 1: count, 2: sample list */
					__( 'پیش‌بررسی: %1$d مورد ناقص شناسایی شد (%2$s).', 'polymart-ai' ),
					count( $issues ),
					implode( '، ', $preview )
				),
				array( 'issues' => $issues )
			);
		}

		$job = array(
			'status'             => 'running',
			'lang'               => $lang,
			'lang_label'         => $lang_label,
			'last_post_id'       => 0,
			'queue'              => array(),
			'retry_queue'        => array(),
			'deferred_queue'     => $seed_ids,
			'retry_attempts'     => array(),
			'remaining'          => $total,
			'total'              => $total,
			'initial_total'      => $total,
			'initial_needs_work' => $needs_work,
			'needs_work'         => $needs_work,
			'site_resolved'      => 0,
			'api_cooldown_until' => null,
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

		foreach ( $seed_ids as $seed_post_id ) {
			Post_Translator::release_translation_lock( absint( $seed_post_id ), $lang, true );
		}

		Job_Action_Scheduler::cancel_all();
		self::bootstrap_background_worker( false );
		self::ping_wp_cron( false );
		self::log(
			'info',
			sprintf(
				/* translators: 1: language name, 2: count */
				__( 'ترجمه خودکار به %1$s شروع شد — %2$d مورد در صف (Action Scheduler).', 'polymart-ai' ),
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
					Post_Translator::release_translation_lock( $post_id, $lang, true );
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
			$lang = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

			$job['status']          = 'running';
			$job['last_error']      = null;
			$job['pause_reason']    = null;
			$job['current_post_id'] = null;
			$job['step_started_at'] = null;
			$job['pick_started_at'] = null;

			self::release_step_lock();

			foreach (
				array_unique(
					array_filter(
						array(
							absint( $job['partial_post_id'] ?? 0 ),
							absint( $job['current_post_id'] ?? 0 ),
						)
					)
				) as $locked_post_id
			) {
				Post_Translator::release_stale_translation_lock( $locked_post_id, $lang, 30 );
			}

			self::recover_stalled_job_picker( $job, $lang, true );

			self::save_job( $job );
			self::bootstrap_background_worker( false );
			self::ping_wp_cron( true );
			self::log( 'info', __( 'ترجمه خودکار از سر گرفته شد (Action Scheduler).', 'polymart-ai' ) );
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

		// Always tear down workers/locks first so Start cannot hit a stale "running" worker.
		self::unschedule_background_worker();
		self::release_step_lock();

		if ( Job_Action_Scheduler::is_available() ) {
			Job_Action_Scheduler::cancel_all();
			Job_Action_Scheduler::clear_slice_mutex();
		}

		$lang = sanitize_key( (string) ( $job['lang'] ?? '' ) );

		if ( '' !== $lang ) {
			self::release_all_job_translation_locks( $job );
		} else {
			self::release_step_lock();
		}

		if ( 'idle' !== ( $job['status'] ?? '' ) ) {
			self::log( 'warning', __( 'ترجمه خودکار توسط کاربر متوقف شد.', 'polymart-ai' ) );
		}

		$cleared = self::empty_job();
		$cleared['updated_at'] = time();

		return self::save_job( $cleared );
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
		Post_Translator::release_translation_lock( $post_id, $lang, true );
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
		$lang = sanitize_key( (string) ( $job['lang'] ?? '' ) );

		foreach ( self::collect_job_post_ids_for_lock_cleanup( $job ) as $post_id ) {
			if ( $post_id <= 0 || '' === $lang ) {
				continue;
			}

			if ( $clear_partial ) {
				Post_Translator::clear_job_partial_state( $post_id, $lang );
			}

			Post_Translator::release_translation_lock( $post_id, $lang, true );
		}

		self::release_step_lock();
	}

	/**
	 * Post IDs that may hold translation locks during a job run.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return int[]
	 */
	private static function collect_job_post_ids_for_lock_cleanup( array $job ) {
		$ids = array_merge(
			is_array( $job['deferred_queue'] ?? null ) ? $job['deferred_queue'] : array(),
			is_array( $job['partial_ids'] ?? null ) ? $job['partial_ids'] : array(),
			is_array( $job['retry_queue'] ?? null ) ? $job['retry_queue'] : array(),
			is_array( $job['parked_ids'] ?? null ) ? $job['parked_ids'] : array(),
			array(
				absint( $job['partial_post_id'] ?? 0 ),
				absint( $job['current_post_id'] ?? 0 ),
				absint( $job['last_post_id'] ?? 0 ),
			)
		);

		if ( is_array( $job['last_step'] ?? null ) ) {
			$ids[] = absint( $job['last_step']['post_id'] ?? 0 );
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $ids ),
					static function ( $post_id ) {
						return $post_id > 0;
					}
				)
			)
		);
	}

	/**
	 * Release per-post translation locks without wiping resume/progress meta.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	private static function release_all_job_translation_locks( array $job ) {
		$lang = sanitize_key( (string) ( $job['lang'] ?? '' ) );

		if ( '' === $lang ) {
			self::release_step_lock();
			return;
		}

		foreach ( self::collect_job_post_ids_for_lock_cleanup( $job ) as $post_id ) {
			Post_Translator::release_translation_lock( $post_id, $lang, true );
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
	 * Schedule the soonest keep-alive tick without pushing an already-soon event later.
	 *
	 * @param int|null $delay_sec Seconds until CRON_HOOK should fire.
	 * @return void
	 */
	private static function schedule_chain_safety_pulse( $delay_sec = null ) {
		if ( ! self::is_bulk_job_running() ) {
			return;
		}

		$delay_sec = null === $delay_sec
			? ( self::$trusted_as_tick ? self::AS_CRON_SAFETY_SEC : self::CRON_SAFETY_SEC )
			: max( 2, absint( $delay_sec ) );

		$due_at = time() + $delay_sec;
		$next   = wp_next_scheduled( self::CRON_HOOK );

		if ( $next && $next <= $due_at ) {
			return;
		}

		if ( $next ) {
			wp_unschedule_event( (int) $next, self::CRON_HOOK );
		}

		wp_schedule_single_event( $due_at, self::CRON_HOOK );
	}

	/**
	 * Ensure the keep-alive pulse exists and enqueue the next AS action.
	 *
	 * @param int $delay_sec Unused (signature kept for callers).
	 * @return void
	 */
	public static function schedule_background_worker( $delay_sec = null ) {
		unset( $delay_sec );

		self::ensure_recurring_pulse();
		Job_Action_Scheduler::ensure_scheduled();
	}

	/**
	 * Nudge WP-Cron so the keep-alive pulse can fire (when DISABLE_WP_CRON is off).
	 *
	 * @param bool $force Bypass the rate limit.
	 * @return void
	 */
	public static function ping_wp_cron( $force = false ) {
		if ( ! $force ) {
			$gate = get_transient( 'polymart_ai_cron_ping_gate' );

			if ( $gate ) {
				return;
			}

			$gate_ttl = self::is_bulk_job_running() ? 15 : 45;
			set_transient( 'polymart_ai_cron_ping_gate', 1, $gate_ttl );
		}

		if ( self::is_bulk_job_running() ) {
			Job_Action_Scheduler::ensure_scheduled();
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return;
		}

		if ( wp_doing_cron() ) {
			return;
		}

		spawn_cron();
	}

	/**
	 * @deprecated HTTP cron spawn retired — Action Scheduler + pulse keep-alive replace it.
	 *
	 * @param bool $force Unused.
	 * @return void
	 */
	private static function spawn_cron_request( $force = false ) {
		unset( $force );

		if ( self::is_bulk_job_running() ) {
			Job_Action_Scheduler::ensure_scheduled();
		}
	}

	/**
	 * Mark the job as scheduled and enqueue Action Scheduler work.
	 *
	 * Enqueues one AS action and runs the queue inline in this request so Start
	 * shows immediate progress without stacking a parallel inline batch.
	 *
	 * @param bool $run_inline When false, only enqueue AS work (fast HTTP start/resume).
	 * @return array<string, mixed>
	 */
	public static function bootstrap_background_worker( $run_inline = true ) {
		$job = self::get_job_raw();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return self::normalize_job_for_response( $job, false );
		}

		self::force_release_step_lock_if_idle();
		self::clear_chain_events();
		self::ensure_recurring_pulse();

		$as_ok = Job_Action_Scheduler::is_available();

		if ( $as_ok ) {
			Job_Action_Scheduler::clear_slice_mutex();
			Job_Action_Scheduler::enqueue_next( true, 0 );

			if ( $run_inline ) {
				Job_Action_Scheduler::run_queue_inline( true );
			}
		} elseif ( $run_inline ) {
			self::run_action_scheduler_batch( 2 );
		}

		$job = self::get_job_raw();
		$job['worker_scheduled_at'] = time();
		$job['worker_heartbeat_at'] = time();
		$job['next_cron_at']        = self::get_next_worker_cron() ?: null;
		$job['worker_mode']         = $as_ok ? 'as' : 'cron';
		self::save_job( $job );

		$result = self::normalize_job_for_response( self::get_job_raw(), false );
		$result['worker_bootstrapped'] = true;
		$result['as_enqueued']         = $as_ok;
		$result['worker_mode']         = $as_ok ? 'as' : 'cron';
		$result['cli_spawned']         = false;
		$result['loopback_spawned']    = false;

		return $result;
	}

	/**
	 * Ensure Action Scheduler has work queued (SPA monitor / recovery).
	 *
	 * @return array<string, mixed>
	 */
	public static function ensure_background_worker() {
		$job = self::get_job_raw();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return self::normalize_job_for_response( $job, false );
		}

		if ( self::is_job_api_cooldown_active( $job ) ) {
			$job['worker_ensured']           = true;
			$job['api_cooldown']             = true;
			$job['api_cooldown_remaining']   = self::get_job_api_cooldown_remaining( $job );
			$job['worker_scheduled_at']      = time();
			self::save_job( $job );

			return self::normalize_job_for_response( $job, false );
		}

		if ( self::should_prioritize_elementor_partial( $job ) ) {
			$last = self::get_worker_real_activity_at( $job );
			$age  = $last > 0 ? ( time() - $last ) : PHP_INT_MAX;

			if ( $age >= self::get_ensure_inline_idle_sec() ) {
				$direct_ran = self::run_pinned_elementor_work( self::is_elementor_progress_stalled( $job ) );

				if ( $direct_ran ) {
					$job = self::get_job_raw();
					$job['worker_direct_tick'] = true;
					self::save_job( $job );
				}
			}
		} elseif ( self::is_elementor_progress_stalled( $job ) ) {
			if ( self::run_direct_elementor_burst() ) {
				$job = self::get_job_raw();
				$job['worker_direct_tick'] = true;
				self::save_job( $job );
			}
		}

		$released_stale_lock = self::force_release_step_lock_if_idle();
		$released_stale_lock = self::maybe_release_stale_step_lock() || $released_stale_lock;

		$job  = self::get_job_raw();
		$lang = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
		$last = self::get_worker_real_activity_at( $job );
		$age  = $last > 0 ? ( time() - $last ) : PHP_INT_MAX;

		if ( $released_stale_lock || ( $age >= self::PICK_STALL_SEC && ! self::should_prioritize_elementor_partial( $job ) ) ) {
			if ( self::recover_stalled_job_picker( $job, $lang, $released_stale_lock ) ) {
				self::save_job( $job );
			}
		}

		self::ensure_recurring_pulse();

		$job  = self::get_job_raw();
		$last = self::get_worker_real_activity_at( $job );
		$age  = $last > 0 ? ( time() - $last ) : PHP_INT_MAX;
		$busy = self::is_step_lock_held_fresh();
		$stale_sec = Job_Action_Scheduler::STALE_RUNNING_SEC;

		if ( $busy && $age >= self::LOCK_FORCE_IDLE_SEC && ! self::is_bulk_worker_lively( $stale_sec ) ) {
			$job     = self::get_job_raw();
			$lang    = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
			$post_id = absint( $job['partial_post_id'] ?? 0 ) ?: absint( $job['current_post_id'] ?? 0 );

			if ( $post_id > 0 && '' !== $lang ) {
				Post_Translator::release_stale_translation_lock( $post_id, $lang, self::LOCK_FORCE_IDLE_SEC );
			}

			self::release_step_lock();
			$busy                = false;
			$released_stale_lock = true;
		}

		Job_Action_Scheduler::ensure_scheduled();

		$as_pending = Job_Action_Scheduler::has_pending_or_running();
		$worker_dead = ! self::is_bulk_worker_lively( $stale_sec );
		$should_tick = $worker_dead || $as_pending || $age >= self::get_ensure_inline_idle_sec();

		if ( $should_tick && self::should_prioritize_elementor_partial( self::get_job_raw() ) ) {
			if ( self::run_pinned_elementor_work( self::is_elementor_progress_stalled( self::get_job_raw() ) ) ) {
				$job = self::get_job_raw();
				$job['worker_inline_tick'] = true;
				self::save_job( $job );
			}
		} elseif ( $should_tick ) {
			$inline_steps = $worker_dead || $age >= self::PICK_STALL_SEC ? 2 : 1;
			$inline_budget = $worker_dead || $age >= self::PICK_STALL_SEC ? 90 : 60;
			self::run_inline_worker_tick( $inline_steps, $inline_budget );
			$job = self::get_job_raw();
			$job['worker_inline_tick'] = true;
			self::save_job( $job );
		}

		$next = self::get_next_worker_cron();
		$job  = self::get_job_raw();
		$as   = Job_Action_Scheduler::status_payload();
		$job['next_cron_at']        = $next ? (int) $next : null;
		$job['cron_scheduled']      = (bool) $next;
		$job['worker_ensured']      = true;
		$job['worker_lock']         = (bool) get_transient( self::STEP_LOCK_KEY ) || (bool) get_option( self::STEP_LOCK_CLAIM_KEY );
		$job['loopback_spawned']    = false;
		$job['cli_spawned']         = false;
		$job['as_pending']          = ! empty( $as['pending'] );
		$job['as_available']        = ! empty( $as['available'] );
		$job['worker_mode']         = ! empty( $as['available'] ) ? 'as' : 'cron';

		if ( $released_stale_lock ) {
			$job['lock_recovered'] = true;
			self::log( 'warning', __( 'قفل مرحلهٔ گیرکرده آزاد شد — صف Action Scheduler دوباره شروع می‌کند.', 'polymart-ai' ) );
		}

		self::save_job( $job );

		return self::normalize_job_for_response( $job, false );
	}

	/**
	 * Cancel pending background job steps (pulse + Action Scheduler actions).
	 *
	 * @return void
	 */
	public static function unschedule_background_worker() {
		self::clear_chain_events();
		wp_clear_scheduled_hook( self::CRON_PULSE_HOOK );
		Job_Action_Scheduler::cancel_all();
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
		 * @param int $seconds Default CRON_STEP_BUDGET_SEC.
		 */
		return max( 20, min( 360, (int) apply_filters( 'polymart_ai_job_cron_step_budget_sec', self::CRON_STEP_BUDGET_SEC ) ) );
	}

	/**
	 * Label for which driver ran the last tick.
	 *
	 * @return string
	 */
	private static function resolve_last_worker_label() {
		if ( self::$trusted_as_tick ) {
			return 'as';
		}

		if ( wp_doing_cron() ) {
			return 'cron';
		}

		return 'admin';
	}

	/**
	 * Shared worker tick used by WP-Cron, AJAX loopback, AND the admin UI kick.
	 *
	 * One code path for both drivers so browser and cron stay on the same job state.
	 * Schedules the next event BEFORE work so fatals/timeouts do not orphan the chain.
	 *
	 * @param int|null $max_steps_override Optional bounded step count for REST recovery.
	 * @param int|null $budget_override    Optional wall-clock budget override (AS slices).
	 * @return array<string, mixed> Normalized job after the tick.
	 */
	public static function process_background_step( $max_steps_override = null, $budget_override = null ) {
		$job = self::get_job_raw();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			self::unschedule_background_worker();

			return self::normalize_job_for_response( $job, false );
		}

		self::$trusted_job_tick = true;

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		$worker_label = self::resolve_last_worker_label();

		// Heartbeat BEFORE work so the monitor knows the worker is alive during long AI calls.
		$job['worker_heartbeat_at'] = time();
		$job['last_worker']         = $worker_label;
		self::save_job( $job );

		// Fatal-recovery chain only — never clear the recurring pulse here.
		self::ensure_recurring_pulse();
		// Do not ping here — mid-tick ping spawned nested cron workers and froze the site.

		$started   = time();
		$job_tick  = self::get_job_raw();
		$elementor_burst = self::$trusted_as_tick && self::should_prioritize_elementor_partial( $job_tick );
		$budget    = null !== $budget_override
			? max(
				30,
				min(
					$elementor_burst ? 300 : 120,
					absint( $budget_override )
				)
			)
			: self::get_cron_step_budget_sec();
		$max_steps = (int) apply_filters( 'polymart_ai_job_cron_max_steps_per_tick', self::CRON_MAX_STEPS_PER_TICK );
		$max_steps = max( 1, min( 40, $max_steps ) );
		if ( null !== $max_steps_override ) {
			// AS / Elementor bursts pass an explicit cap — do not clamp to CRON_MAX_STEPS_PER_TICK.
			$max_steps = max( 1, min( 40, absint( $max_steps_override ) ) );
		}
		$steps_run = 0;
		$last_result = null;

		if ( function_exists( 'set_time_limit' ) ) {
			$limit = $elementor_burst ? 330 : ( self::$trusted_as_tick ? 210 : max( 300, $budget + 180 ) );
			@set_time_limit( $limit );
		}

		while ( $steps_run < $max_steps ) {
			if ( ( time() - $started ) >= $budget ) {
				break;
			}

			self::touch_worker_heartbeat();
			$job_before   = self::get_job_raw();
			$steps_before = absint( $job_before['steps'] ?? 0 );
			$result       = self::process_job_step();
			$last_result  = $result;
			$steps_after  = absint( self::get_job_raw()['steps'] ?? 0 );

			if ( $steps_after <= $steps_before && is_array( $result ) && empty( $result['step_deferred'] ) ) {
				$result['idle_tick'] = true;
				$last_result         = $result;
			}

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
				self::$trusted_job_tick = false;

				return is_array( $result )
					? $result
					: self::normalize_job_for_response( $job, false );
			}

			if ( is_array( $result ) && ! empty( $result['idle_tick'] ) ) {
				break;
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

		// Enqueue next Action Scheduler action (or pulse-only if AS unavailable).
		if ( 'running' === ( self::get_job_raw()['status'] ?? '' ) ) {
			if ( self::$skip_chain_after_tick ) {
				self::schedule_chain_safety_pulse( self::AS_CRON_SAFETY_SEC );
			} else {
				self::clear_chain_events();
				self::continue_job_chain( 0, ! $end_deferred_busy );
			}
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
			self::$trusted_job_tick = false;

			if (
				'running' === ( $last_result['status'] ?? '' )
				&& self::should_prioritize_elementor_partial( $last_result )
				&& ! self::$elementor_page_burst
				&& Post_Translator::elementor_job_api_slices_pending(
					absint( $last_result['partial_post_id'] ?? 0 ),
					sanitize_key( (string) ( $last_result['lang'] ?? 'en' ) )
				)
			) {
				self::schedule_elementor_partial_follow_up();
			}

			return $last_result;
		}

		self::$trusted_job_tick = false;
		return self::normalize_job_for_response( self::get_job_raw(), false );
	}

	/**
	 * Admin/UI entry: force keep-alive / CLI spawn (no inline multi-step AI).
	 *
	 * @return array<string, mixed>
	 */
	public static function kick_worker() {
		$job = self::get_job_raw();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return self::normalize_job_for_response( $job, false );
		}

		self::force_release_step_lock_if_idle();
		self::ensure_recurring_pulse();
		Job_Action_Scheduler::clear_slice_mutex_if_stale();

		if ( self::should_prioritize_elementor_partial( $job ) ) {
			$result = self::run_elementor_page_burst( 300 );

			if ( is_array( $result ) ) {
				$result['worker_kicked'] = true;
				$result['worker_mode']   = Job_Action_Scheduler::is_available() ? 'as' : 'cron';

				return $result;
			}
		} else {
			Job_Action_Scheduler::enqueue_next( true, 0 );
		}

		$result = self::run_inline_worker_tick();

		if ( is_array( $result ) ) {
			$result['worker_kicked'] = true;
			$result['worker_mode']   = Job_Action_Scheduler::is_available() ? 'as' : 'cron';

			return $result;
		}

		$out = self::normalize_job_for_response( self::get_job_raw(), false );
		$out['worker_kicked'] = true;
		$out['as_pending']    = Job_Action_Scheduler::has_pending_or_running();
		$out['worker_mode']   = Job_Action_Scheduler::is_available() ? 'as' : 'cron';

		return $out;
	}

	/**
	 * Release the global job step lock.
	 *
	 * @return void
	 */
	public static function release_step_lock() {
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
			$limit = self::$trusted_as_tick ? 120 : 1200;
			@set_time_limit( $limit );
		}

		$job = self::get_job_raw();

		if ( ! in_array( $job['status'] ?? '', array( 'running' ), true ) ) {
			return self::normalize_job_for_response( $job, false );
		}

		$cooldown_remaining = self::get_job_api_cooldown_remaining( $job );

		if ( $cooldown_remaining > 0 ) {
			$job['step_deferred']         = true;
			$job['step_deferred_reason']  = 'api_cooldown';
			$job['step_deferred_message'] = sprintf(
				/* translators: %d: seconds remaining */
				__( 'API موقتاً محدود است — %d ثانیه تا تلاش بعدی', 'polymart-ai' ),
				$cooldown_remaining
			);
			$job['api_cooldown_remaining'] = $cooldown_remaining;

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
			// One bad product must not pause/kill the whole bulk job.
			$live    = self::get_job_raw();
			$post_id = absint( $live['current_post_id'] ?? 0 )
				?: absint( $live['partial_post_id'] ?? 0 );

			return self::recover_job_item_failure(
				$e->getMessage(),
				$post_id,
				array(
					'exception' => get_class( $e ),
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
					'source'    => 'process_job_step',
				)
			);
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

		self::touch_worker_heartbeat();
		self::reconcile_stuck_job_queues( $job, $lang );
		self::touch_worker_heartbeat();

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
				Post_Translator::release_translation_lock( $partial_id, $lang, true );
				$job['partial_post_id'] = null;
				$partial_id             = 0;
			}
		}

		if ( $partial_id > 0 ) {
			$post_id    = $partial_id;
			$from_retry = true;
		} else {
			$job['pick_started_at'] = time();
			self::save_job( $job );

			$pick = self::pick_next_job_post_id( $job, $lang, $cursor );

			if ( ! $pick['post_id'] ) {
				// Cursor may have walked past posts that only look done in-memory.
				// Reset and pull a fresh actionable set while site work remains.
				self::touch_worker_heartbeat();
				self::sync_job_live_stats( $job, $lang, false );
				self::touch_worker_heartbeat();
				self::reconcile_succeeded_posts( $job, $lang );

				$needs_work = (int) ( $job['needs_work'] ?? 0 );

				if ( $needs_work > 0 ) {
					self::touch_worker_heartbeat();
					$seed_limit = min( 25, max( 5, $needs_work + 3 ) );
					$actionable = Translation_Query::seed_actionable_post_ids(
						$lang,
						$seed_limit,
						self::get_exhausted_job_post_ids( $job )
					);
					self::touch_worker_heartbeat();

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
				self::recover_stalled_job_picker( $job, $lang, false );
				self::reconcile_stuck_job_queues( $job, $lang );
				$pick = self::pick_next_job_post_id( $job, $lang, $cursor );

				if ( ! $pick['post_id'] && $cursor > 0 ) {
					$job['last_post_id'] = 0;
					$pick                = self::pick_next_job_post_id( $job, $lang, 0 );
				}
			}

			unset( $job['pick_started_at'] );

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
			unset( $job['pick_started_at'] );

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

		if ( Post_Translator::should_run_elementor_job_slice( $post_id, $lang ) ) {
			self::pin_job_for_elementor_post( $job, $post_id, $lang );
			self::save_job( $job );
		}

		$slice = Post_Translator::process_job_translation_slice( $post_id, $lang );

		if ( is_wp_error( $slice ) && 'polymart_ai_translation_in_progress' === $slice->get_error_code() ) {
			if ( Post_Translator::release_stale_translation_lock( $post_id, $lang ) ) {
				self::log(
					'warning',
					sprintf(
						/* translators: 1: post ID */
						__( 'قفل ترجمهٔ گیرکرده #%1$d آزاد شد — تلاش مجدد برای ادامه Elementor.', 'polymart-ai' ),
						$post_id
					),
					array( 'post_id' => $post_id, 'lang' => $lang )
				);
				$slice = Post_Translator::process_job_translation_slice( $post_id, $lang );
			}
		}

		if ( is_wp_error( $slice ) && 'polymart_ai_translation_in_progress' === $slice->get_error_code() ) {
			$job['current_post_id']       = null;
			$job['step_started_at']       = null;
			$job['step_deferred']         = true;
			$job['step_deferred_reason']  = 'translation_in_progress';
			$job['step_deferred_message'] = $slice->get_error_message();
			self::save_job( $job );

			self::log(
				'info',
				sprintf(
					/* translators: 1: post ID */
					__( 'ترجمه #%1$d — کارگر دیگری در حال اجراست؛ تیک بعدی دوباره تلاش می‌کند.', 'polymart-ai' ),
					$post_id
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);

			return self::normalize_job_for_response( $job, false );
		}

		if ( is_wp_error( $slice ) ) {
			$partial_state = Post_Translator::get_job_partial_state( $post_id, $lang );

			if (
				Post_Translator::is_recoverable_job_slice_error( $slice )
				&& Post_Translator::job_partial_state_has_progress( $post_id, $lang, $partial_state )
			) {
				$partial_progress = self::format_job_partial_progress( $partial_state, $post_id, $lang );
				$phase            = (string) ( $partial_state['phase'] ?? 'elementor' );
				$parsed_progress  = self::parse_job_phase_progress( $partial_progress );
				$elementor_remaining = 'elementor' === $phase
					&& (
						! is_array( $parsed_progress )
						|| $parsed_progress['done'] < $parsed_progress['total']
					);

				if (
					! $elementor_remaining
					&& self::job_progress_stuck_should_defer( $job, $post_id, $partial_progress )
				) {
					self::defer_job_post_stuck_slice( $job, $post_id, $lang, $phase, $partial_progress );
					self::save_job( $job );

					return self::normalize_job_for_response( $job, false );
				}

				$job['partial_post_id']   = $post_id;
				$job['partial_phase']     = $phase;
				$job['partial_progress']  = $partial_progress;
				$job['current_post_id']   = null;
				$job['step_started_at']   = null;
				self::maybe_apply_api_throttle_cooldown( $slice );
				$job = self::get_job_raw();

				if ( self::is_job_api_cooldown_active( $job ) ) {
					$remaining                      = self::get_job_api_cooldown_remaining( $job );
					$job['step_deferred']           = true;
					$job['step_deferred_reason']    = 'api_cooldown';
					$job['step_deferred_message']   = self::format_api_cooldown_message( $remaining );
					$job['api_cooldown_remaining']  = $remaining;
					$job['last_error']              = self::format_api_cooldown_message( $remaining );
					self::increment_job_step( $job );
					self::set_job_last_step(
						$job,
						$post_id,
						'partial',
						self::format_api_cooldown_message( $remaining )
					);
					self::save_job( $job );

					return self::normalize_job_for_response( $job, false );
				}

				$job['last_error'] = self::humanize_api_error_message( $slice->get_error_message() );
				self::increment_job_step( $job );
				self::set_job_last_step(
					$job,
					$post_id,
					'partial',
					$job['last_error']
				);
				self::save_job( $job );

				return self::normalize_job_for_response( $job, false );
			}

			Post_Translator::clear_job_partial_state( $post_id, $lang );
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
			$phase_key         = (string) ( $slice['phase'] ?? '' );
			$parsed_progress   = self::parse_job_phase_progress( $current_progress );
			$elementor_remaining = 'elementor' === $phase_key
				&& (
					Post_Translator::elementor_job_has_remaining_payload( $post_id, $lang )
					|| ! is_array( $parsed_progress )
					|| $parsed_progress['done'] < $parsed_progress['total']
				);

			if ( $elementor_remaining || 'elementor' === $phase_key ) {
				$stuck_should_defer = false;
			}

			if (
				! $elementor_remaining
				&& 'elementor' !== $phase_key
				&& (
					$stuck_should_defer
					|| (
						! $made_progress
						&& (
							absint( $job['consecutive_post_steps'] ?? 0 ) >= max( 1, $max_consecutive )
							|| $steps_on_post >= max( 1, $max_steps_per_run )
						)
					)
				)
			) {
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
			self::touch_partial_progress_tracker( $job );

			if ( ! empty( $slice['recoverable'] ) ) {
				$job['recoverable'] = true;
				$err_msg            = (string) ( $slice['message'] ?? '' );

				if ( '' !== $err_msg && ! self::is_synthetic_api_throttle_message( $err_msg ) ) {
					self::maybe_apply_api_throttle_cooldown(
						new \WP_Error( 'polymart_ai_api_error', $err_msg )
					);
				}

				$job = self::get_job_raw();

				if ( self::is_job_api_cooldown_active( $job ) ) {
					$remaining                      = self::get_job_api_cooldown_remaining( $job );
					$job['step_deferred']           = true;
					$job['step_deferred_reason']    = 'api_cooldown';
					$job['step_deferred_message']   = self::format_api_cooldown_message( $remaining );
					$job['api_cooldown_remaining']  = $remaining;
					$job['current_post_id']         = null;
					$job['step_started_at']         = null;
					self::increment_job_step( $job );
					self::set_job_last_step(
						$job,
						$post_id,
						'partial',
						self::format_api_cooldown_message( $remaining )
					);
					self::save_job( $job );

					return self::normalize_job_for_response( $job, false );
				}
			} else {
				unset( $job['recoverable'] );
			}

			if ( $made_progress ) {
				$job['stuck_progress_steps'] = 0;

				if ( isset( $job['defer_rounds'][ $post_id ] ) ) {
					unset( $job['defer_rounds'][ $post_id ] );
				}

				// Keep consecutive count growing on variable products so fairness rotation can fire.
				if ( 'variations' !== $phase_key ) {
					$job['consecutive_post_steps'] = 1;
				}
			}

			$fairness_threshold = max( 12, (int) floor( max( 1, $max_consecutive ) / 4 ) );
			$should_rotate_fair = $made_progress
				&& 'variations' === $phase_key
				&& absint( $job['consecutive_post_steps'] ?? 0 ) >= $fairness_threshold;

			if ( $should_rotate_fair ) {
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
				self::humanize_api_error_message( (string) ( $slice['message'] ?? __( 'ادامه در مرحله بعد…', 'polymart-ai' ) ) )
			);
			self::save_job( $job );

			return self::normalize_job_for_response( $job, false );
		}

		if (
			! empty( $slice['done'] )
			&& 'elementor' !== sanitize_key( (string) ( $slice['phase'] ?? '' ) )
			&& Post_Translator::post_needs_elementor_job_work( $post_id, $lang )
		) {
			self::pin_job_for_elementor_post( $job, $post_id, $lang );
			self::increment_job_step( $job );
			self::set_job_last_step(
				$job,
				$post_id,
				'partial',
				sprintf(
					/* translators: %s: elementor progress marker */
					__( 'Elementor — ادامه بخش‌ها (%s)', 'polymart-ai' ),
					'' !== (string) ( $job['partial_progress'] ?? '' ) ? (string) $job['partial_progress'] : '…'
				)
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
	 * Pick the next post to translate: pinned Elementor partials first, then deferred, then forward scan.
	 *
	 * @param array<string, mixed> $job    Current job state.
	 * @param string               $lang   Target language code.
	 * @param int                  $cursor Last processed post ID.
	 * @return array{post_id: int, from_retry: bool}
	 */
	private static function pick_next_job_post_id( array &$job, $lang, $cursor ) {
		$lang       = sanitize_key( (string) $lang );
		$partial_id = absint( $job['partial_post_id'] ?? 0 );

		if ( $partial_id > 0 && self::job_has_active_elementor_pin( $job, $lang ) ) {
			if ( ! in_array( $partial_id, self::get_parked_ids( $job ), true ) ) {
				return array(
					'post_id'    => $partial_id,
					'from_retry' => true,
				);
			}
		}

		if ( $partial_id > 0 && self::elementor_partial_has_remaining( $job ) ) {
			if ( ! in_array( $partial_id, self::get_parked_ids( $job ), true ) ) {
				return array(
					'post_id'    => $partial_id,
					'from_retry' => true,
				);
			}
		}

		$exclude_ids = self::get_forward_scan_exclude_ids( $job );

		self::touch_worker_heartbeat();
		$deferred_queue = self::get_actionable_deferred_queue( $job, $lang );
		self::touch_worker_heartbeat();

		if ( ! empty( $deferred_queue ) ) {
			$post_id               = (int) array_shift( $deferred_queue );
			$job['deferred_queue'] = $deferred_queue;

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

		self::touch_worker_heartbeat();
		$post_id = Translation_Query::find_next_actionable_post_id( $lang, $cursor, $exclude_ids );
		self::touch_worker_heartbeat();

		if ( ! $post_id && $cursor > 0 ) {
			self::touch_worker_heartbeat();
			$post_id = Translation_Query::find_next_actionable_post_id( $lang, 0, $exclude_ids );
			self::touch_worker_heartbeat();
		}

		if ( ! $post_id ) {
			self::touch_worker_heartbeat();
			$post_id = Translation_Query::find_next_untranslated_post_id( $lang, $cursor, $exclude_ids );
			self::touch_worker_heartbeat();

			if ( ! $post_id && $cursor > 0 ) {
				$post_id = Translation_Query::find_next_untranslated_post_id( $lang, 0, $exclude_ids );
				self::touch_worker_heartbeat();
			}
		}

		if ( $post_id ) {
			return array(
				'post_id'    => $post_id,
				'from_retry' => false,
			);
		}

		self::touch_worker_heartbeat();
		$actionable = self::get_actionable_remaining_ids( $lang, $job, 10 );
		self::touch_worker_heartbeat();

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
	 * Unstick picker deadlocks: release lock, re-seed queue, switch to menu phase when needed.
	 *
	 * @param array<string, mixed> $job  Job state (by reference).
	 * @param string               $lang Target language code.
	 * @param bool                 $log  Whether to write an activity log line.
	 * @return bool True when job state was changed.
	 */
	private static function recover_stalled_job_picker( array &$job, $lang, $log = false ) {
		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			return false;
		}

		$changed               = false;
		$prioritize_elementor  = self::should_prioritize_elementor_partial( $job );

		if ( get_transient( self::STEP_LOCK_KEY ) || get_option( self::STEP_LOCK_CLAIM_KEY ) ) {
			self::release_step_lock();
			$changed = true;
		}

		if ( absint( $job['current_post_id'] ?? 0 ) > 0 || ! empty( $job['step_started_at'] ) || ! empty( $job['pick_started_at'] ) ) {
			$job['current_post_id'] = null;
			$job['step_started_at']  = null;
			$job['pick_started_at']  = null;
			$changed                 = true;
		}

		if ( $prioritize_elementor ) {
			if ( in_array( (string) ( $job['pause_reason'] ?? '' ), array( 'pick_stalled', 'stalled' ), true ) ) {
				$job['status']       = 'running';
				$job['pause_reason'] = null;
				$job['last_error']   = null;
				$changed             = true;
			}

			if ( $log && $changed ) {
				self::log(
					'warning',
					__( 'قفل کارگر آزاد شد — ادامه ترجمه Elementor در همان مورد.', 'polymart-ai' )
				);
			}

			return $changed;
		}

		$parked = self::get_parked_ids( $job );
		$unpark = array();

		foreach ( $parked as $post_id ) {
			$post_id = absint( $post_id );

			if ( $post_id <= 0 ) {
				continue;
			}

			if ( 'translated' !== Post_Translator::get_translation_status( $post_id, $lang ) ) {
				$unpark[] = $post_id;
			}
		}

		if ( ! empty( $unpark ) ) {
			$job['parked_ids'] = array_values( array_diff( $parked, $unpark ) );
			$job['deferred_queue'] = array_values(
				array_unique(
					array_merge( self::get_deferred_queue( $job ), $unpark )
				)
			);
			$changed = true;
		}

		$needs      = max( 1, (int) ( $job['needs_work'] ?? $job['remaining'] ?? 5 ) );
		$seed_limit = min( 25, max( 5, $needs + 3 ) );
		$seed_ids   = Translation_Query::seed_actionable_post_ids(
			$lang,
			$seed_limit,
			self::get_exhausted_job_post_ids( $job )
		);

		if ( ! empty( $seed_ids ) ) {
			$job['deferred_queue'] = array_values(
				array_unique(
					array_merge( self::get_deferred_queue( $job ), array_map( 'absint', $seed_ids ) )
				)
			);
			$job['last_post_id'] = 0;
			$changed             = true;
		}

		$menu_left = Menu_Translator::count_untranslated( $lang );

		if ( $menu_left > 0 && empty( self::get_actionable_deferred_queue( $job, $lang ) ) ) {
			$job['phase']        = 'menus';
			$job['last_menu_id'] = 0;
			$changed             = true;
		} elseif ( 'menus' !== ( $job['phase'] ?? '' ) || empty( self::get_deferred_queue( $job ) ) ) {
			if ( $menu_left <= 0 ) {
				$job['phase'] = 'posts';
			}
		}

		if ( in_array( (string) ( $job['pause_reason'] ?? '' ), array( 'pick_stalled', 'stalled' ), true ) ) {
			$job['status']       = 'running';
			$job['pause_reason'] = null;
			$job['last_error']   = null;
			$changed             = true;
		}

		if ( $log && $changed ) {
			self::log(
				'warning',
				__( 'صف گیرکرده بازیابی شد — انتخاب مورد بعدی و منوها دوباره ادامه می‌یابد.', 'polymart-ai' )
			);
		}

		return $changed;
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

			if ( '' !== $lang && ! Post_Translator::post_is_actionable_for_job( $post_id, $lang ) ) {
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

		if (
			absint( $job['partial_post_id'] ?? 0 ) === $post_id
			&& self::job_has_active_elementor_pin( $job, $lang )
		) {
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
	 * Top up deferred_queue after a success so pick_next never stalls on an empty buffer.
	 *
	 * @param array<string, mixed> $job  Job state (by reference).
	 * @param string               $lang Target language code.
	 * @return void
	 */
	private static function replenish_deferred_queue_if_low( array &$job, $lang ) {
		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			return;
		}

		$queue = self::get_deferred_queue( $job );

		if ( count( $queue ) >= 8 ) {
			return;
		}

		$seed_limit = min( 10, max( 3, 8 - count( $queue ) ) );
		$new_ids    = Translation_Query::seed_actionable_post_ids(
			$lang,
			$seed_limit,
			self::get_exhausted_job_post_ids( $job )
		);

		if ( empty( $new_ids ) ) {
			return;
		}

		$job['deferred_queue'] = array_values(
			array_unique(
				array_merge( $queue, array_map( 'absint', $new_ids ) )
			)
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
		self::sync_job_live_stats( $job, $lang, true );
		self::sync_job_remaining( $job, $lang, true );

		$needs_work = (int) ( $job['needs_work'] ?? $job['remaining'] ?? 0 );

		if ( $needs_work > 0 ) {
			self::recover_stalled_job_picker( $job, $lang, false );

			if (
				'running' === ( $job['status'] ?? '' )
				&& (
					! empty( self::get_deferred_queue( $job ) )
					|| 'menus' === ( $job['phase'] ?? '' )
					|| Menu_Translator::count_untranslated( $lang ) > 0
				)
			) {
				$job['status']       = 'running';
				$job['pause_reason'] = null;
				self::save_job( $job );

				return;
			}
		}

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
			$menu_left = Menu_Translator::count_untranslated( $lang );

			if ( $menu_left > 0 ) {
				$job['status']       = 'running';
				$job['phase']        = 'menus';
				$job['pause_reason'] = null;
				$job['last_menu_id'] = 0;
				$job['last_error']   = null;

				return;
			}

			$stalled_ids = Translation_Query::seed_actionable_post_ids(
				$lang,
				10,
				self::get_exhausted_job_post_ids( $job )
			);

			if ( empty( $stalled_ids ) ) {
				$stalled_ids = Translation_Query::collect_remaining_post_ids( $lang, 10 );
			}

			if ( empty( $stalled_ids ) ) {
				$stalled_ids = array_values(
					array_filter(
						array_map(
							'absint',
							wp_list_pluck(
								Translation_Query::collect_storefront_translation_issues( $lang, 8 ),
								'post_id'
							)
						)
					)
				);
			}

			if ( ! empty( $stalled_ids ) ) {
				$job['deferred_queue'] = array_values(
					array_unique(
						array_merge( self::get_deferred_queue( $job ), $stalled_ids )
					)
				);
				$job['status']       = 'running';
				$job['pause_reason'] = null;
				$job['last_error']   = null;
				self::save_job( $job );

				return;
			}

			$job['status']       = 'paused';
			$job['pause_reason'] = 'stalled';
			$job['stalled_ids']  = $stalled_ids;
			$job['last_error']   = self::format_stalled_job_error( $lang, $stalled_ids );

			return;
		}

		if ( $needs_work > 0 ) {
			$job['status']       = 'running';
			$job['pause_reason'] = null;
			$job['last_error']   = sprintf(
				/* translators: %d: remaining work count */
				__( '%d مورد هنوز ناقص است — «ادامه» را بزنید.', 'polymart-ai' ),
				$needs_work
			);
			self::recover_stalled_job_picker( $job, $lang, false );
			self::save_job( $job );

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
	 * Explain why auto-translate cannot start yet.
	 *
	 * @param string                             $lang   Target language code.
	 * @param array<int, array<string, mixed>> $issues Preflight issues.
	 * @return string
	 */
	private static function format_no_queue_diagnostic( $lang, array $issues = array() ) {
		$lang = sanitize_key( (string) $lang );

		if ( empty( $issues ) ) {
			$issues = Translation_Query::collect_storefront_translation_issues( $lang, 8 );
		}

		if ( ! empty( $issues ) ) {
			$lines = array();

			foreach ( array_slice( $issues, 0, 5 ) as $issue ) {
				$lines[] = sprintf(
					'#%1$d %2$s (%3$s): %4$s',
					(int) ( $issue['post_id'] ?? 0 ),
					(string) ( $issue['title'] ?? '' ),
					(string) ( $issue['type'] ?? '' ),
					(string) ( $issue['reason'] ?? '' )
				);
			}

			return sprintf(
				/* translators: 1: count, 2: details */
				__( 'پیش‌بررسی %1$d مورد ناقص پیدا کرد اما در صف index نیست — «توقف کامل» بزنید و دوباره «شروع» کنید: %2$s', 'polymart-ai' ),
				count( $issues ),
				implode( ' | ', $lines )
			);
		}

		return __(
			'مورد ترجمه‌نشده‌ای در index یافت نشد. اگر صفحه اصلی/فوتر/بلوک HTML هنوز فارسی است، احتمالاً متن در ویجت هدر وودمارت (خارج از پست) است — تب «رشته‌های UI» را اسکن کنید، یا صفحه/بلوک cms_block را در ویرایشگر باز کنید.',
			'polymart-ai'
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
			$template = __( 'ترجمه متوقف شد — %1$d مورد ناقص: %2$s. «ادامه» / «شروع» مجدد بزنید یا فیلدها را دستی تکمیل کنید.', 'polymart-ai' );
		}

		$summary = implode( ' | ', $details );

		if ( '' === trim( $summary ) && ! empty( $remaining_ids ) ) {
			foreach ( array_slice( $remaining_ids, 0, 5 ) as $post_id ) {
				$details[] = sprintf(
					'#%1$d: %2$s',
					$post_id,
					get_the_title( $post_id ) ?: __( '(بدون عنوان)', 'polymart-ai' )
				);
			}
			$summary = implode( ' | ', $details );
		}

		if ( count( $remaining_ids ) > count( $details ) ) {
			$summary .= sprintf(
				/* translators: %d: additional incomplete post count */
				__( ' … +%d مورد دیگر', 'polymart-ai' ),
				count( $remaining_ids ) - count( $details )
			);
		}

		if ( '' === trim( $summary ) ) {
			$issues = Translation_Query::collect_storefront_translation_issues( $lang, 5 );

			foreach ( $issues as $issue ) {
				$details[] = sprintf(
					'#%1$d: %2$s — %3$s',
					(int) ( $issue['post_id'] ?? 0 ),
					(string) ( $issue['title'] ?? '' ),
					(string) ( $issue['reason'] ?? '' )
				);
			}

			$summary = implode( ' | ', $details );
		}

		if ( '' === trim( $summary ) ) {
			$summary = __( 'هیچ مورد قابل اجرا در صف پیدا نشد. صفحه اصلی، بلوک HTML (cms_block) یا ویجت هدر را در ویرایشگر باز کنید و دوباره «شروع» بزنید.', 'polymart-ai' );
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

			if (
				Post_Translator::post_needs_elementor_job_work( $post_id, $lang )
				|| Post_Translator::elementor_job_has_remaining_payload( $post_id, $lang )
			) {
				self::pin_job_for_elementor_post( $job, $post_id, $lang );

				self::log(
					'info',
					sprintf(
						/* translators: 1: post title, 2: progress marker */
						__( '«%1$s» — Elementor بخش‌بندی شده؛ ادامه همان مورد (%2$s) تا تکمیل.', 'polymart-ai' ),
						$title_source,
						'' !== (string) ( $job['partial_progress'] ?? '' ) ? (string) $job['partial_progress'] : '…'
					),
					array( 'post_id' => $post_id, 'lang' => $lang )
				);
			} else {
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

		if ( 'translated' === $status ) {
			self::replenish_deferred_queue_if_low( $job, $lang );
		}

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
	 * Whether an error message indicates an explicit Arvan/WAF IP block (not a generic HTTP failure).
	 *
	 * @param string $message Raw or lowercased error message.
	 * @return bool
	 */
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

	/**
	 * Whether an API error looks like rate limiting or bot/WAF challenge.
	 *
	 * @param \WP_Error $error Error object.
	 * @return bool
	 */
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

	/**
	 * Shorten noisy gateway/WAF error bodies for UI and logs.
	 *
	 * @param string $message Raw error message.
	 * @param int    $max     Max characters.
	 * @return string
	 */
	public static function truncate_api_error_message( $message, $max = 160 ) {
		$message = trim( wp_strip_all_tags( (string) $message ) );
		$message = preg_replace( '/\s+/', ' ', $message );
		$max     = max( 40, absint( $max ) );

		if ( mb_strlen( $message ) <= $max ) {
			return $message;
		}

		return mb_substr( $message, 0, $max - 1 ) . '…';
	}

	/**
	 * User-facing API error text (Arvan/WAF aware).
	 *
	 * @param string $message Raw error message.
	 * @return string
	 */
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

	/**
	 * Replace noisy API error text in job payloads while cooldown is active.
	 *
	 * @param array<string, mixed> $job                Job state.
	 * @param int                  $cooldown_remaining Seconds remaining.
	 * @return array<string, mixed>
	 */
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

	/**
	 * Respect Arvan rate limits by spacing bulk Elementor API calls.
	 *
	 * @return void
	 */
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

	/**
	 * Sleep without letting the job monitor think the worker died mid-batch.
	 *
	 * @param int $seconds Seconds to sleep.
	 * @return void
	 */
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

	/**
	 * Record the last Arvan API attempt for spacing follow-up calls.
	 *
	 * @return void
	 */
	public static function touch_arvan_api_attempt() {
		$job                      = self::get_job_raw();
		$job['last_arvan_api_at'] = time();
		self::save_job( $job );
	}

	/**
	 * @param int $remaining_seconds Seconds until retry.
	 * @return string
	 */
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

	/**
	 * Seconds remaining on the API throttle cooldown, if any.
	 *
	 * @param array<string, mixed>|null $job Optional job state.
	 * @return int
	 */
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

	/**
	 * Whether the bulk job must pause AI calls due to rate limit / IP block.
	 *
	 * @param array<string, mixed>|null $job Optional job state.
	 * @return bool
	 */
	public static function is_job_api_cooldown_active( $job = null ) {
		return self::get_job_api_cooldown_remaining( $job ) > 0;
	}

	/**
	 * Whether an error message is our own cooldown/deferred text (not a live API response).
	 *
	 * @param string $message Error or status message.
	 * @return bool
	 */
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

	/**
	 * Clear an active Arvan cooldown after a confirmed live API success.
	 *
	 * @param bool $kick_worker When true, run one inline worker tick for a running job.
	 * @return bool True when cooldown state was cleared.
	 */
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

	/**
	 * Clear Arvan block streak after a successful API response.
	 *
	 * @return void
	 */
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

	/**
	 * Apply a cooldown after Arvan/WAF throttling so we do not burn tokens retrying.
	 *
	 * @param \WP_Error $error       API error.
	 * @param int|null  $cooldown_sec Optional override in seconds.
	 * @return bool True when a cooldown was set.
	 */
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