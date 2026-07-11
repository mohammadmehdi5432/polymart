<?php
/**
 * Action Scheduler driver for the bulk auto-translate job.
 *
 * Replaces CLI spawn and HTTP loopback. Each action runs a bounded multi-slice
 * batch; the 1-minute server cron keep-alive runs the AS queue runner inline
 * (no outbound HTTP), which is reliable on shared cPanel hosts.
 *
 * @package PolymartAI
 */

namespace PolymartAI;

defined( 'ABSPATH' ) || exit;

/**
 * Class Job_Action_Scheduler
 */
final class Job_Action_Scheduler {

	const HOOK  = 'polymart_ai_as_translate_slice';
	const GROUP = 'polymart_ai_translation';

	/**
	 * Seconds after which an In-progress AS action is treated as dead.
	 * Must exceed one full AS batch (multi-slice + Elementor partials + retries).
	 */
	const STALE_RUNNING_SEC = 150;

	/** Wall-clock budget for one AS slice callback (shared-host safe). */
	const SLICE_BUDGET_SEC = 90;

	/** Breathing room before the next slice is scheduled after a successful one. */
	const CHAIN_DELAY_SEC = 4;

	/** When another slice is in-flight, defer this action by this many seconds. */
	const CONCURRENCY_DEFER_SEC = 45;

	/** Transient key — only one translation slice may run at a time. */
	const SLICE_MUTEX_KEY = 'polymart_ai_as_slice_mutex';

	/**
	 * Action ID currently executing (for fatal shutdown recovery).
	 *
	 * @var int
	 */
	private static $current_action_id = 0;

	/**
	 * Last action ID seen on action_scheduler_before_execute.
	 *
	 * @var int
	 */
	private static $captured_action_id = 0;

	/**
	 * Whether the current AS callback exited cleanly.
	 *
	 * @var bool
	 */
	private static $handler_clean_exit = false;

	/**
	 * Whether a shutdown recovery callback was registered for this request.
	 *
	 * @var bool
	 */
	private static $shutdown_registered = false;

	/**
	 * Whether Action Scheduler APIs are loaded (normally via WooCommerce).
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_has_scheduled_action' )
			&& function_exists( 'as_unschedule_all_actions' )
			&& class_exists( 'ActionScheduler' );
	}

	/**
	 * Register the AS hook callback.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'handle_slice_action' ) );
		add_action( 'action_scheduler_before_execute', array( __CLASS__, 'capture_before_execute' ), 1, 1 );

		// Prefer WP-Cron / inline queue runs over AS HTTP async loopbacks while
		// a PolyMart job is active (same firewall issue as our old loopback).
		add_filter( 'action_scheduler_allow_async_request_runner', array( __CLASS__, 'filter_async_runner' ) );
	}

	/**
	 * Capture the AS action ID before the callback runs.
	 *
	 * @param int $action_id Action ID.
	 * @return void
	 */
	public static function capture_before_execute( $action_id ) {
		self::$captured_action_id = absint( $action_id );
	}

	/**
	 * Disable AS's own HTTP async runner while our bulk job is running.
	 *
	 * @param bool $allow Current allow flag.
	 * @return bool
	 */
	public static function filter_async_runner( $allow ) {
		if ( Activity_Logger::is_bulk_job_running() ) {
			return false;
		}

		return $allow;
	}

	/**
	 * Enqueue the next slice-batch action.
	 *
	 * @param bool     $force     Recover stale In-progress actions first when true.
	 * @param int|null $delay_sec Seconds before the action is due (0 = run ASAP).
	 * @return int Action ID or 0.
	 */
	public static function enqueue_next( $force = false, $delay_sec = null ) {
		if ( ! Activity_Logger::is_bulk_job_running() ) {
			return 0;
		}

		if ( ! self::is_available() ) {
			return 0;
		}

		if ( $force && ! Activity_Logger::is_bulk_worker_lively( self::STALE_RUNNING_SEC ) ) {
			self::recover_stale_running_actions( self::STALE_RUNNING_SEC );
		}

		if ( self::count_actions_by_status( \ActionScheduler_Store::STATUS_PENDING ) > 0 ) {
			return 0;
		}

		if ( null === $delay_sec ) {
			$delay_sec = self::CHAIN_DELAY_SEC;
		}

		$delay_sec = max( 0, min( 30, absint( $delay_sec ) ) );

		if ( $delay_sec <= 1 && self::count_actions_by_status( \ActionScheduler_Store::STATUS_RUNNING ) > 0 ) {
			return 0;
		}

		do_action( 'polymart_ai_before_enqueue_as_slice' );

		$args = array(
			'n' => sprintf( '%.6F', microtime( true ) ),
		);

		if ( 0 === $delay_sec && function_exists( 'as_enqueue_async_action' ) ) {
			$action_id = as_enqueue_async_action( self::HOOK, $args, self::GROUP, false );
		} elseif ( function_exists( 'as_schedule_single_action' ) ) {
			$action_id = as_schedule_single_action( time() + $delay_sec, self::HOOK, $args, self::GROUP );
		} else {
			$action_id = as_enqueue_async_action( self::HOOK, $args, self::GROUP, false );
		}

		Activity_Logger::log(
			'info',
			sprintf(
				/* translators: %d: Action Scheduler action ID */
				__( 'Action Scheduler: اکشن ترجمه #%d در صف قرار گرفت.', 'polymart-ai' ),
				absint( $action_id )
			),
			array( 'as_action_id' => absint( $action_id ) )
		);

		return absint( $action_id );
	}

	/**
	 * Ensure a pending/running action exists for a live job.
	 *
	 * Also breaks stale In-progress actions and plugin locks so the chain cannot jam.
	 *
	 * @return int Action ID or 0.
	 */
	public static function ensure_scheduled() {
		if ( ! Activity_Logger::is_bulk_job_running() ) {
			return 0;
		}

		if ( ! self::is_available() ) {
			return 0;
		}

		$recovered = 0;

		if ( ! Activity_Logger::is_bulk_worker_lively( self::STALE_RUNNING_SEC ) ) {
			$recovered = self::recover_stale_running_actions( self::STALE_RUNNING_SEC );
			Activity_Logger::release_stale_locks_for_as( self::STALE_RUNNING_SEC );
		}

		if ( $recovered > 0 ) {
			Activity_Logger::log(
				'warning',
				sprintf(
					/* translators: %d: number of recovered actions */
					__( 'Action Scheduler: %d اکشن گیرکرده In-progress آزاد شد — زنجیره از سر گرفته می‌شود.', 'polymart-ai' ),
					$recovered
				)
			);
		}

		// Pending follow-up already waiting — no need to stack another.
		if ( self::count_actions_by_status( \ActionScheduler_Store::STATUS_PENDING ) > 0 ) {
			return 0;
		}

		// A fresh In-progress action (< stale threshold) is actively working.
		$running = self::count_actions_by_status( \ActionScheduler_Store::STATUS_RUNNING );
		if ( $running > 0 && 0 === $recovered ) {
			return 0;
		}

		return self::enqueue_next( false, 0 );
	}

	/**
	 * Whether a pending or in-progress action exists for our hook/group.
	 *
	 * @return bool
	 */
	public static function has_pending_or_running() {
		if ( ! self::is_available() ) {
			return false;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, null, self::GROUP ) ) {
			return true;
		}

		$next = function_exists( 'as_next_scheduled_action' )
			? as_next_scheduled_action( self::HOOK, null, self::GROUP )
			: false;

		return false !== $next && null !== $next;
	}

	/**
	 * Cancel all PolyMart translation AS actions (pause/stop).
	 *
	 * @return void
	 */
	public static function cancel_all() {
		if ( ! self::is_available() ) {
			self::clear_slice_mutex();
			return;
		}

		self::fail_all_running_actions();
		self::clear_slice_mutex();

		as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		as_unschedule_all_actions( self::HOOK, null, self::GROUP );
	}

	/**
	 * AS callback: run one bounded multi-slice batch, then chain the next action.
	 *
	 * @param mixed ...$unused AS may pass action args.
	 * @return void
	 */
	public static function handle_slice_action( ...$unused ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $unused );

		if ( ! Activity_Logger::is_bulk_job_running() ) {
			return;
		}

		Activity_Logger::on_ai_http_heartbeat();

		self::$handler_clean_exit = false;
		self::$current_action_id  = self::resolve_current_action_id();
		self::register_handler_shutdown();

		self::clear_slice_mutex_if_stale();

		if ( ! self::try_claim_slice_mutex( self::$current_action_id ) ) {
			self::defer_slice_action( self::$current_action_id );
			self::$handler_clean_exit = true;

			return;
		}

		$job     = Activity_Logger::get_job_for_as_debug();
		$post_id = absint( $job['current_post_id'] ?? 0 ) ?: absint( $job['partial_post_id'] ?? 0 );
		$queued  = is_array( $job['deferred_queue'] ?? null ) ? count( $job['deferred_queue'] ) : 0;

		Activity_Logger::log(
			'info',
			sprintf(
				/* translators: 1: AS action ID, 2: post ID, 3: queued count */
				__( 'AS #%1$d: شروع batch ترجمه (پست جاری/جزئی: #%2$d، %3$d مورد در صف آماده).', 'polymart-ai' ),
				self::$current_action_id,
				$post_id,
				$queued
			),
			array(
				'as_action_id' => self::$current_action_id,
				'post_id'      => $post_id,
			)
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'[PolyMartAI AS] start action=%d post=%d mem=%s',
				self::$current_action_id,
				$post_id,
				size_format( memory_get_usage( true ) )
			)
		);

		try {
			Activity_Logger::run_action_scheduler_batch( 1, self::SLICE_BUDGET_SEC );
		} catch ( \Throwable $e ) {
			Activity_Logger::log(
				'error',
				sprintf(
					/* translators: 1: AS action ID, 2: error */
					__( 'AS #%1$d: Exception در batch — %2$s', 'polymart-ai' ),
					self::$current_action_id,
					$e->getMessage()
				),
				array(
					'as_action_id' => self::$current_action_id,
					'exception'    => get_class( $e ),
					'file'         => $e->getFile(),
					'line'         => $e->getLine(),
				)
			);

			Activity_Logger::recover_job_item_failure(
				$e->getMessage(),
				$post_id,
				array(
					'source'       => 'as_handle_slice',
					'as_action_id' => self::$current_action_id,
				)
			);
		}

		$job_after  = Activity_Logger::get_job_for_as_debug();
		$post_after = absint( $job_after['current_post_id'] ?? 0 ) ?: absint( $job_after['partial_post_id'] ?? 0 );
		$log_post   = $post_after > 0 ? $post_after : absint( $job_after['last_post_id'] ?? 0 );

		Activity_Logger::log(
			'info',
			sprintf(
				/* translators: 1: AS action ID, 2: post ID, 3: job status */
				__( 'AS #%1$d: پایان batch (پست: #%2$d، وضعیت جاب: %3$s).', 'polymart-ai' ),
				self::$current_action_id,
				$log_post,
				(string) ( $job_after['status'] ?? '' )
			),
			array(
				'as_action_id' => self::$current_action_id,
				'post_id'      => $log_post,
			)
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'[PolyMartAI AS] end action=%d post=%d status=%s mem=%s',
				self::$current_action_id,
				$log_post,
				(string) ( $job_after['status'] ?? '' ),
				size_format( memory_get_usage( true ) )
			)
		);

		self::$handler_clean_exit = true;
		self::release_slice_mutex();
	}

	/**
	 * Fatal/timeout shutdown: fail the AS action, release locks, keep the chain moving.
	 *
	 * @return void
	 */
	public static function handler_shutdown_recovery() {
		self::release_slice_mutex();

		if ( self::$handler_clean_exit ) {
			if ( Activity_Logger::is_bulk_job_running() ) {
				self::enqueue_next( true, self::CHAIN_DELAY_SEC );
			}

			return;
		}

		$action_id = self::$current_action_id;
		$last      = error_get_last();
		$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

		$message = __( 'اکشن Action Scheduler ناگهانی قطع شد (kill/timeout/fatal).', 'polymart-ai' );

		if ( is_array( $last ) && in_array( (int) ( $last['type'] ?? 0 ), $fatal_types, true ) ) {
			$message = sprintf(
				'%s in %s:%s',
				(string) ( $last['message'] ?? 'fatal' ),
				(string) ( $last['file'] ?? '' ),
				(string) ( $last['line'] ?? '' )
			);
		} elseif ( ! is_array( $last ) && $action_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[PolyMartAI AS] shutdown recovery action=%d msg=%s', $action_id, $message ) );

		try {
			if ( $action_id > 0 ) {
				self::mark_action_failed( $action_id );
			}

			$job     = Activity_Logger::get_job_for_as_debug();
			$post_id = absint( $job['current_post_id'] ?? 0 ) ?: absint( $job['partial_post_id'] ?? 0 );

			Activity_Logger::recover_worker_infrastructure_failure(
				$message,
				$post_id,
				array(
					'source'       => 'as_shutdown',
					'as_action_id' => $action_id,
					'fatal'        => 1,
				)
			);

			if ( Activity_Logger::is_bulk_job_running() ) {
				self::enqueue_next( true, 0 );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		self::$current_action_id = 0;
	}

	/**
	 * Register shutdown recovery once per request.
	 *
	 * @return void
	 */
	private static function register_handler_shutdown() {
		if ( self::$shutdown_registered ) {
			return;
		}

		self::$shutdown_registered = true;
		register_shutdown_function( array( __CLASS__, 'handler_shutdown_recovery' ) );
	}

	/**
	 * Best-effort current Action Scheduler action ID.
	 *
	 * @return int
	 */
	private static function resolve_current_action_id() {
		if ( self::$captured_action_id > 0 ) {
			return self::$captured_action_id;
		}

		// Fallback: newest running action for our hook.
		$ids = self::query_running_action_ids( 1 );

		return ! empty( $ids[0] ) ? absint( $ids[0] ) : 0;
	}

	/**
	 * Mark stuck In-progress actions as failed so the chain can continue.
	 *
	 * @param int $older_than_sec Age threshold.
	 * @param int $except_id      Optional action ID to leave alone (current).
	 * @return int Number recovered.
	 */
	public static function recover_stale_running_actions( $older_than_sec = 60, $except_id = 0 ) {
		if ( ! self::is_available() || ! class_exists( 'ActionScheduler' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return 0;
		}

		$older_than_sec = max( 30, absint( $older_than_sec ) );
		$except_id      = absint( $except_id );
		$recovered      = 0;

		// AS "modified" does not tick during long batches — trust the job heartbeat instead.
		if ( Activity_Logger::is_bulk_worker_lively( $older_than_sec ) ) {
			return 0;
		}

		try {
			$store = \ActionScheduler::store();
			$ids   = $store->query_actions(
				array(
					'hook'             => self::HOOK,
					'group'            => self::GROUP,
					'status'           => \ActionScheduler_Store::STATUS_RUNNING,
					'modified'         => time() - $older_than_sec,
					'modified_compare' => '<=',
					'per_page'         => 20,
					'orderby'          => 'none',
				)
			);

			if ( ! is_array( $ids ) ) {
				$ids = array();
			}

			foreach ( $ids as $action_id ) {
				$action_id = absint( $action_id );

				if ( $action_id <= 0 || $action_id === $except_id ) {
					continue;
				}

				self::mark_action_failed( $action_id );
				++$recovered;

				Activity_Logger::log(
					'warning',
					sprintf(
						/* translators: 1: action ID, 2: age seconds */
						__( 'AS #%1$d بیش از %2$dث روی In-progress مانده بود — Failed شد.', 'polymart-ai' ),
						$action_id,
						$older_than_sec
					),
					array( 'as_action_id' => $action_id )
				);
			}
		} catch ( \Throwable $e ) {
			Activity_Logger::log(
				'error',
				sprintf(
					/* translators: %s: error */
					__( 'بازیابی اکشن‌های AS ناموفق: %s', 'polymart-ai' ),
					$e->getMessage()
				)
			);
		}

		return $recovered;
	}

	/**
	 * @param string $status AS status constant.
	 * @return int
	 */
	private static function count_actions_by_status( $status ) {
		if ( ! class_exists( 'ActionScheduler' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return 0;
		}

		try {
			$store = \ActionScheduler::store();
			$count = $store->query_actions(
				array(
					'hook'     => self::HOOK,
					'group'    => self::GROUP,
					'status'   => $status,
					'per_page' => 1,
				),
				'count'
			);

			return absint( $count );
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * @param int $limit Max IDs.
	 * @return array<int, int>
	 */
	private static function query_running_action_ids( $limit = 10 ) {
		if ( ! class_exists( 'ActionScheduler' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return array();
		}

		try {
			$store = \ActionScheduler::store();
			$ids   = $store->query_actions(
				array(
					'hook'     => self::HOOK,
					'group'    => self::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_RUNNING,
					'per_page' => max( 1, absint( $limit ) ),
					'orderby'  => 'date',
					'order'    => 'DESC',
				)
			);

			return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Claim a process-wide mutex so only one slice runs at a time.
	 *
	 * @param int $action_id Current AS action ID.
	 * @return bool
	 */
	private static function try_claim_slice_mutex( $action_id ) {
		$action_id = absint( $action_id );

		if ( $action_id <= 0 ) {
			return true;
		}

		$held = get_transient( self::SLICE_MUTEX_KEY );

		if ( false !== $held ) {
			$held_id = absint( $held );

			if ( $held_id > 0 && $held_id !== $action_id && Activity_Logger::is_bulk_worker_lively( self::SLICE_BUDGET_SEC ) ) {
				return false;
			}
		}

		set_transient( self::SLICE_MUTEX_KEY, $action_id, self::SLICE_BUDGET_SEC + 60 );

		return true;
	}

	/**
	 * @return void
	 */
	private static function release_slice_mutex() {
		delete_transient( self::SLICE_MUTEX_KEY );
	}

	/**
	 * Reschedule this action when another slice is already running.
	 *
	 * @param int $action_id Action ID (for logging).
	 * @return void
	 */
	private static function defer_slice_action( $action_id ) {
		if ( self::count_actions_by_status( \ActionScheduler_Store::STATUS_PENDING ) > 0 ) {
			return;
		}

		$delay = (int) apply_filters( 'polymart_ai_as_concurrency_defer_sec', self::CONCURRENCY_DEFER_SEC );
		$delay = max( 15, min( 120, $delay ) );

		if ( function_exists( 'as_schedule_single_action' ) && Activity_Logger::is_bulk_job_running() ) {
			as_schedule_single_action(
				time() + $delay,
				self::HOOK,
				array( 'n' => sprintf( '%.6F', microtime( true ) ) ),
				self::GROUP
			);
		}

		Activity_Logger::log(
			'info',
			sprintf(
				/* translators: 1: action ID, 2: delay seconds */
				__( 'AS #%1$d: اسلایس دیگری در حال اجراست — این اکشن %2$dث به تعویق افتاد (تک‌رشته‌ای).', 'polymart-ai' ),
				absint( $action_id ),
				$delay
			),
			array( 'as_action_id' => absint( $action_id ) )
		);
	}

	/**
	 * @param int $action_id Action ID.
	 * @return void
	 */
	private static function mark_action_failed( $action_id ) {
		$action_id = absint( $action_id );

		if ( $action_id <= 0 || ! class_exists( 'ActionScheduler' ) ) {
			return;
		}

		try {
			\ActionScheduler::store()->mark_failure( $action_id );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}
	}

	/**
	 * Fail every running action for our hook (used on job stop).
	 *
	 * @return void
	 */
	private static function fail_all_running_actions() {
		foreach ( self::query_running_action_ids( 50 ) as $action_id ) {
			self::mark_action_failed( $action_id );
		}
	}

	/**
	 * Clear the single-flight mutex (bootstrap / stop / stale recovery).
	 *
	 * @return void
	 */
	public static function clear_slice_mutex() {
		delete_transient( self::SLICE_MUTEX_KEY );
	}

	/**
	 * Drop a mutex left by a dead PHP worker.
	 *
	 * @return void
	 */
	public static function clear_slice_mutex_if_stale() {
		if ( ! Activity_Logger::is_bulk_worker_lively( self::SLICE_BUDGET_SEC ) ) {
			self::clear_slice_mutex();
		}
	}

	/**
	 * Run due actions, or force one inline slice when the AS queue is idle.
	 *
	 * @param bool $force_inline When true, run a batch directly if the runner processed nothing.
	 * @return int Actions processed by the AS runner, or 1 when inline fallback ran.
	 */
	public static function run_queue_inline( $force_inline = false ) {
		if ( ! self::is_available() ) {
			return 0;
		}

		self::ensure_scheduled();

		if ( ! class_exists( 'ActionScheduler_QueueRunner' ) ) {
			return 0;
		}

		// Strictly sequential — never process two translation slices in one runner pass.
		$time_limit = (int) apply_filters( 'polymart_ai_as_queue_time_limit', 55 );
		$batch_size = (int) apply_filters( 'polymart_ai_as_queue_batch_size', 1 );

		$time_filter = static function () use ( $time_limit ) {
			return max( 25, min( 70, $time_limit ) );
		};
		$batch_filter = static function () use ( $batch_size ) {
			return max( 1, min( 5, $batch_size ) );
		};

		add_filter( 'action_scheduler_queue_runner_time_limit', $time_filter, 50 );
		add_filter( 'action_scheduler_queue_runner_batch_size', $batch_filter, 50 );

		try {
			$runner = \ActionScheduler_QueueRunner::instance();
			$done   = (int) $runner->run( 'PolymartAI' );
		} catch ( \Throwable $e ) {
			Activity_Logger::log(
				'error',
				sprintf(
					/* translators: %s: error message */
					__( 'Action Scheduler صف ترجمه را اجرا نکرد: %s', 'polymart-ai' ),
					$e->getMessage()
				)
			);
			$done = 0;
		}

		remove_filter( 'action_scheduler_queue_runner_time_limit', $time_filter, 50 );
		remove_filter( 'action_scheduler_queue_runner_batch_size', $batch_filter, 50 );

		if ( $done <= 0 && $force_inline && Activity_Logger::is_bulk_job_running() ) {
			self::clear_slice_mutex_if_stale();
			Activity_Logger::run_action_scheduler_batch( 1, self::SLICE_BUDGET_SEC );

			return 1;
		}

		return $done;
	}

	/**
	 * Human-readable status for the SPA.
	 *
	 * @return array{available: bool, pending: bool, group: string, hook: string}
	 */
	public static function status_payload() {
		return array(
			'available' => self::is_available(),
			'pending'   => self::has_pending_or_running(),
			'group'     => self::GROUP,
			'hook'      => self::HOOK,
		);
	}
}
