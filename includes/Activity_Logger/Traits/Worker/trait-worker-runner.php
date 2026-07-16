<?php
/**
 * Activity_Logger Worker_Runner (auto-split).
 *
 * @package PolymartAI\Activity_Logger\Traits
 */

namespace PolymartAI\Activity_Logger\Traits\Worker;

use PolymartAI\Activity_Logger\Job_Action_Scheduler;
use PolymartAI\Activity_Logger\Metabox_Action_Scheduler;
use PolymartAI\Activity_Logger\Translation_Scheduler_Coordinator;
use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\Content\Menu_Translator;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Pipeline\Translation_Query;


defined( 'ABSPATH' ) || exit;

trait Trait_Worker_Runner {

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

		// Soft wake only — never spawn loopback on every enqueue (503 stampede).
		self::schedule_chain_safety_pulse( self::AS_CRON_SAFETY_SEC );
		self::ping_wp_cron( true );
	}

	public static function nudge_as_queue_runner() {
		if ( ! self::is_bulk_job_running() ) {
			return;
		}

		self::schedule_chain_safety_pulse( self::AS_CRON_SAFETY_SEC );
		self::ping_wp_cron( true );
	}

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
			self::$trusted_loopback_tick = false;
			status_header( 200 );
			exit( 'idle' );
		}

		try {
			self::keep_alive_as_worker();
		} finally {
			self::$trusted_loopback_tick = false;
		}

		// Hand off to wp-cron — do not immediately spawn another loopback (FPM flood).
		if ( self::is_bulk_job_running() && ! Translation_Scheduler_Coordinator::is_halted() ) {
			self::schedule_chain_safety_pulse( self::AS_CRON_SAFETY_SEC );
			self::ping_wp_cron( true );
		}

		status_header( 200 );
		exit( 'as' );
	}

	/**
	 * Emergency admin-ajax wake when the worker is truly idle (not a per-slice chain).
	 *
	 * @param bool $force Also enqueue a pending slice first.
	 * @return bool True when a loopback request was dispatched.
	 */
	public static function spawn_job_loopback( $force = false ) {
		if ( Translation_Scheduler_Coordinator::is_halted() || ! self::is_bulk_job_running() ) {
			return false;
		}

		// Nested spawn from inside an active loopback tick would recurse forever.
		if ( self::$trusted_loopback_tick || self::$trusted_as_tick || wp_doing_cron() ) {
			return false;
		}

		// Only wake when the job is actually stalled — not after every enqueue.
		$age     = self::get_bulk_worker_activity_age();
		$min_age = $force ? 20 : 35;

		if ( $age < $min_age ) {
			return false;
		}

		$gate_sec = max( 15, (int) self::LOOPBACK_GATE_SEC );

		if ( get_transient( self::LOOPBACK_GATE_KEY ) ) {
			return false;
		}

		set_transient( self::LOOPBACK_GATE_KEY, time(), $gate_sec );

		Job_Action_Scheduler::ensure_scheduled();

		if ( $force ) {
			Job_Action_Scheduler::enqueue_next( true, 0 );
		}

		$url = add_query_arg(
			array(
				'action' => self::LOOPBACK_ACTION,
				'token'  => self::get_loopback_token(),
			),
			admin_url( 'admin-ajax.php' )
		);

		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'cookies'   => array(),
			)
		);

		return true;
	}

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

		if ( Job_Action_Scheduler::is_available() ) {
			Job_Action_Scheduler::ensure_scheduled();
			$processed = Job_Action_Scheduler::run_queue_inline( true );

			self::schedule_chain_safety_pulse( self::CRON_FAST_INTERVAL_SEC );

			$out = self::normalize_job_for_response( self::get_job_raw(), false );
			$out['as_processed']   = $processed;
			$out['worker_mode']    = 'as';

			return $out;
		}

		// Fallback without WooCommerce/AS: run a bounded batch inside this cron hit.
		return self::run_action_scheduler_batch();
	}

	public static function keep_alive_cli_worker() {
		return self::keep_alive_as_worker();
	}

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

			if ( ! Job_Action_Scheduler::is_slice_execution_active() ) {
				self::force_release_step_lock_if_idle();
			}

			Job_Action_Scheduler::clear_slice_mutex_if_stale();

			if ( Job_Action_Scheduler::is_available() ) {
				Job_Action_Scheduler::ensure_scheduled();
				$slice_active = Job_Action_Scheduler::is_slice_execution_active();
				$processed    = Job_Action_Scheduler::run_queue_inline( ! $slice_active );

				if ( $processed > 0 || $slice_active ) {
					return self::normalize_job_for_response( self::get_job_raw(), false );
				}
			}

			if ( Job_Action_Scheduler::is_slice_execution_active() ) {
				return self::normalize_job_for_response( self::get_job_raw(), false );
			}

			$limits = self::get_inline_worker_tick_limits( self::get_job_raw() );

			$result = self::run_action_scheduler_batch( $limits['steps'], $limits['budget'] );

			$job = self::get_job_raw();

			if (
				self::should_prioritize_elementor_partial( $job )
				&& self::is_elementor_progress_stalled( $job )
				&& ! self::is_job_api_cooldown_active( $job )
			) {
				self::reconcile_elementor_bulk_pin( $job );

				if ( self::run_pinned_elementor_work( false ) ) {
					$result = self::normalize_job_for_response( self::get_job_raw(), false );
					$result['worker_direct_tick'] = true;
				}
			}

			return $result;
		} finally {
			self::$trusted_admin_worker = false;
		}
	}

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

	private static function get_ensure_inline_idle_sec() {
		$job     = self::get_job_raw();
		$default = self::should_prioritize_elementor_partial( $job ) ? 14 : 12;

		return max( 8, min( 90, (int) apply_filters( 'polymart_ai_job_ensure_inline_idle_sec', $default ) ) );
	}

	private static function maybe_ensure_pulse_quietly() {
		if ( Translation_Scheduler_Coordinator::is_halted() ) {
			return;
		}

		$job = get_option( self::JOB_OPTION, array() );

		if ( ! is_array( $job ) || 'running' !== ( $job['status'] ?? '' ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_PULSE_HOOK ) ) {
			wp_schedule_event( time() + 5, self::CRON_PULSE_SCHEDULE, self::CRON_PULSE_HOOK );
		}

		// Soft revive only — never spawn loopback from every HTML/REST hit.
		$age = self::get_bulk_worker_activity_age();

		if ( $age >= self::WORKER_FORCE_RECOVER_SEC ) {
			Job_Action_Scheduler::ensure_scheduled();
			self::schedule_chain_safety_pulse( 2 );
			self::ping_wp_cron( true );
		}
	}

	public static function maybe_heal_background_worker() {
		if ( Translation_Scheduler_Coordinator::is_halted() ) {
			return null;
		}

		if (
			! wp_doing_cron()
			&& ! self::$trusted_loopback_tick
			&& ! self::$trusted_job_tick
			&& ! self::$trusted_as_tick
			&& ! self::$trusted_admin_worker
		) {
			return null;
		}

		$job = get_option( self::JOB_OPTION, array() );

		if ( ! is_array( $job ) || 'running' !== ( $job['status'] ?? '' ) ) {
			self::unschedule_background_worker();
			return null;
		}

		if ( self::maybe_auto_cancel_abandoned_bulk_job() ) {
			return self::normalize_job_for_response( self::get_job_raw(), false );
		}

		self::ensure_recurring_pulse();
		Job_Action_Scheduler::ensure_scheduled();

		$job  = self::get_job_raw();
		$lang = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
		$last = self::get_worker_real_activity_at( $job );
		$age  = $last > 0 ? ( time() - $last ) : PHP_INT_MAX;

		$stale_sec = max(
			25,
			min(
				90,
				(int) apply_filters( 'polymart_ai_cron_heal_stale_sec', self::CRON_HEAL_STALE_SEC )
			)
		);

		if ( $age >= self::PICK_STALL_SEC || self::is_step_lock_held_fresh() ) {
			if ( self::recover_stalled_job_picker( $job, $lang, false ) ) {
				self::save_job( $job );
			}
		}

		// Stalled worker: force recovery then run the full ensure/kick path.
		if ( $age >= $stale_sec ) {
			self::force_recover_stalled_bulk_worker( 'cron_heal', true );

			return self::kick_worker();
		}

		if ( $age >= self::get_ensure_inline_idle_sec() || ! Job_Action_Scheduler::has_pending_or_running() ) {
			return self::keep_alive_as_worker();
		}

		return self::normalize_job_for_response( $job, false );
	}

	private static function get_next_worker_cron() {
		$times = array_filter(
			array(
				wp_next_scheduled( self::CRON_HOOK ),
				wp_next_scheduled( self::CRON_PULSE_HOOK ),
			)
		);

		return empty( $times ) ? false : min( $times );
	}

	private static function ensure_recurring_pulse() {
		if ( ! self::is_bulk_job_running() ) {
			return;
		}

		$next = wp_next_scheduled( self::CRON_PULSE_HOOK );

		if ( ! $next ) {
			wp_schedule_event( time() + 5, self::CRON_PULSE_SCHEDULE, self::CRON_PULSE_HOOK );
			return;
		}

		// Recurring pulse missed beats — schedule an immediate safety tick on wp-cron.
		if ( ( (int) $next - time() ) > 90 && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 2, self::CRON_HOOK );
		}
	}

	private static function clear_chain_events() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

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

	public static function schedule_background_worker( $delay_sec = null ) {
		unset( $delay_sec );

		self::ensure_recurring_pulse();
		Job_Action_Scheduler::ensure_scheduled();
	}

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

	private static function spawn_cron_request( $force = false ) {
		if ( self::is_bulk_job_running() ) {
			Job_Action_Scheduler::ensure_scheduled();
		}

		self::ping_wp_cron( (bool) $force );
	}

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

		self::schedule_chain_safety_pulse( self::AS_CRON_SAFETY_SEC );
		self::ping_wp_cron( true );

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

	public static function ensure_background_worker() {
		// After Stop, never revive a zombie "running" job via monitor ensure/kick.
		if ( Translation_Scheduler_Coordinator::is_halted() ) {
			$job = self::get_job_raw();

			if ( is_array( $job ) && 'running' === ( $job['status'] ?? '' ) ) {
				$cleared = self::empty_job();
				$cleared['updated_at'] = time();
				self::save_job( $cleared );
				Translation_Scheduler_Coordinator::cancel_all_plugin_actions();
				$job = $cleared;
			}

			$job = is_array( $job ) ? $job : self::empty_job();
			$job['halted']         = true;
			$job['worker_ensured'] = false;

			return self::normalize_job_for_response( $job, false );
		}

		if ( self::maybe_auto_cancel_abandoned_bulk_job() ) {
			$job = self::get_job_raw();
			$job['worker_ensured'] = false;
			$job['abandoned']     = true;

			return self::normalize_job_for_response( $job, false );
		}

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

			if ( Job_Action_Scheduler::is_available() ) {
				Job_Action_Scheduler::schedule_cooldown_wakeup( $job['api_cooldown_remaining'] );
			}

			return self::normalize_job_for_response( $job, false );
		}

		// Light path only: unlock + rechain. Never run AI / Elementor inline from ensure
		// (SPA poll/refresh was doing that → HTTP 500 / site freeze).
		self::soft_revive_stalled_bulk_worker( 'ensure' );

		if ( ! Job_Action_Scheduler::is_slice_execution_active() ) {
			$released_stale_lock = self::force_release_step_lock_if_idle();
			$released_stale_lock = self::maybe_release_stale_step_lock() || $released_stale_lock;
		} else {
			$released_stale_lock = false;
		}

		$job  = self::get_job_raw();
		$lang = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
		$age  = self::get_bulk_worker_activity_age();

		if ( $released_stale_lock || $age >= self::PICK_STALL_SEC ) {
			if ( self::recover_stalled_job_picker( $job, $lang, $released_stale_lock ) ) {
				self::save_job( $job );
			}
		}

		self::ensure_recurring_pulse();
		Job_Action_Scheduler::ensure_scheduled();

		if ( Job_Action_Scheduler::is_available() && ! Job_Action_Scheduler::is_slice_execution_active() ) {
			Job_Action_Scheduler::enqueue_next( true, 0 );
		}

		self::schedule_chain_safety_pulse( self::AS_CRON_SAFETY_SEC );
		self::ping_wp_cron( true );

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
		$job['worker_soft']         = true;

		if ( $released_stale_lock ) {
			$job['lock_recovered'] = true;
			self::log( 'warning', __( 'قفل مرحلهٔ گیرکرده آزاد شد — صف Action Scheduler دوباره شروع می‌کند.', 'polymart-ai' ) );
		}

		self::save_job( $job );

		return self::normalize_job_for_response( $job, false );
	}

	public static function unschedule_background_worker() {
		self::clear_chain_events();
		wp_clear_scheduled_hook( self::CRON_PULSE_HOOK );
		Job_Action_Scheduler::cancel_all();
	}

	private static function get_cron_step_budget_sec() {
		/**
		 * Filter seconds of work allowed per translation-job cron invocation.
		 *
		 * @param int $seconds Default CRON_STEP_BUDGET_SEC.
		 */
		return max( 20, min( 360, (int) apply_filters( 'polymart_ai_job_cron_step_budget_sec', self::CRON_STEP_BUDGET_SEC ) ) );
	}

	private static function resolve_last_worker_label() {
		if ( self::$trusted_as_tick ) {
			return 'as';
		}

		if ( wp_doing_cron() ) {
			return 'cron';
		}

		return 'admin';
	}

}
