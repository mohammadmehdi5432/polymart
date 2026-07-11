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

		// Prefer WP-Cron / inline queue runs over AS HTTP async loopbacks while
		// a PolyMart job is active (same firewall issue as our old loopback).
		add_filter( 'action_scheduler_allow_async_request_runner', array( __CLASS__, 'filter_async_runner' ) );
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
	 * Enqueue the next unique async slice-batch action.
	 *
	 * @param bool $force Enqueue even if one appears pending (still unique-safe).
	 * @return int Action ID or 0.
	 */
	public static function enqueue_next( $force = false ) {
		if ( ! Activity_Logger::is_bulk_job_running() ) {
			return 0;
		}

		if ( ! self::is_available() ) {
			return 0;
		}

		if ( ! $force && self::has_pending_or_running() ) {
			return 0;
		}

		/**
		 * Fires before a translation AS action is enqueued.
		 */
		do_action( 'polymart_ai_before_enqueue_as_slice' );

		$action_id = as_enqueue_async_action( self::HOOK, array(), self::GROUP, true );

		return absint( $action_id );
	}

	/**
	 * Ensure a pending/running action exists for a live job.
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

		if ( self::has_pending_or_running() ) {
			return 0;
		}

		return self::enqueue_next( true );
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
			return;
		}

		as_unschedule_all_actions( self::HOOK, array(), self::GROUP );

		// Also clear null-args variants if any were scheduled.
		as_unschedule_all_actions( self::HOOK, null, self::GROUP );
	}

	/**
	 * AS callback: run one bounded multi-slice batch, then chain the next action.
	 *
	 * @return void
	 */
	public static function handle_slice_action() {
		if ( ! Activity_Logger::is_bulk_job_running() ) {
			return;
		}

		Activity_Logger::run_action_scheduler_batch();

		if ( Activity_Logger::is_bulk_job_running() ) {
			self::enqueue_next( true );
		}
	}

	/**
	 * Run due Action Scheduler actions in this PHP request (cron keep-alive path).
	 *
	 * Does not open outbound HTTP — processes the DB queue inline.
	 *
	 * @return int Number of actions processed, or 0.
	 */
	public static function run_queue_inline() {
		if ( ! self::is_available() ) {
			return 0;
		}

		self::ensure_scheduled();

		if ( ! class_exists( 'ActionScheduler_QueueRunner' ) ) {
			return 0;
		}

		// Give the runner enough room for several AI slices in one cron hit.
		$time_limit = (int) apply_filters( 'polymart_ai_as_queue_time_limit', 50 );
		$batch_size = (int) apply_filters( 'polymart_ai_as_queue_batch_size', 5 );

		$time_filter = static function () use ( $time_limit ) {
			return max( 20, min( 120, $time_limit ) );
		};
		$batch_filter = static function () use ( $batch_size ) {
			return max( 1, min( 25, $batch_size ) );
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
