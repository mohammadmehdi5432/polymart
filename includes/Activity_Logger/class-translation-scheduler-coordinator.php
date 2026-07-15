<?php
/**
 * Global coordination for bulk + metabox Action Scheduler workers.
 *
 * Prevents concurrent API workers and ensures Stop purges all queued AS actions.
 *
 * @package PolymartAI\Activity_Logger
 */

namespace PolymartAI\Activity_Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class Translation_Scheduler_Coordinator
 */
final class Translation_Scheduler_Coordinator {

	const HALT_OPTION       = 'polymart_ai_translation_scheduler_halt';
	const GLOBAL_LOCK_KEY   = 'polymart_ai_global_translation_worker';
	const GLOBAL_LOCK_TTL   = 300;
	const OWNER_BULK        = 'bulk';
	const OWNER_METABOX     = 'metabox';

	/**
	 * User pressed Stop — block every enqueue/chain/recovery path.
	 *
	 * @return void
	 */
	public static function halt_all_schedulers() {
		update_option( self::HALT_OPTION, time(), false );
		delete_transient( self::GLOBAL_LOCK_KEY );
		self::cancel_all_plugin_actions();
	}

	/**
	 * Clear halt flag when a new bulk/metabox job is intentionally started.
	 *
	 * @return void
	 */
	public static function clear_halt() {
		delete_option( self::HALT_OPTION );
	}

	/**
	 * @return bool
	 */
	public static function is_halted() {
		return absint( get_option( self::HALT_OPTION, 0 ) ) > 0;
	}

	/**
	 * Hard abort signal for every enqueue / AS callback / mid-batch loop.
	 * True when Stop was pressed (halt) OR bulk job is no longer running (paused/idle/stopped).
	 *
	 * @return bool
	 */
	public static function should_abort_bulk_work() {
		if ( self::is_halted() ) {
			return true;
		}

		return ! \PolymartAI\Activity_Logger::is_bulk_job_running();
	}

	/**
	 * Cancel every PolyMart translation AS action (bulk + metabox) and cron pulses.
	 *
	 * @return void
	 */
	public static function cancel_all_plugin_actions() {
		if ( Job_Action_Scheduler::is_available() ) {
			Job_Action_Scheduler::cancel_all();
			Job_Action_Scheduler::clear_slice_mutex();
		}

		Metabox_Action_Scheduler::cancel_all_plugin_actions();

		wp_clear_scheduled_hook( Config_Constants::CRON_HOOK );
		wp_clear_scheduled_hook( Config_Constants::CRON_PULSE_HOOK );
	}

	/**
	 * Metabox / poll / inline fallback must not run while bulk owns the worker.
	 *
	 * @return bool
	 */
	public static function should_defer_metabox_work() {
		if ( self::is_halted() ) {
			return true;
		}

		// Interactive metabox already queued — do NOT kill/block it for Bulk.
		// Bulk waits via should_defer_bulk_work() while metabox AS is live.
		if ( Metabox_Action_Scheduler::has_any_live_as_actions() ) {
			return false;
		}

		if ( \PolymartAI\Activity_Logger::is_bulk_job_running() ) {
			return true;
		}

		if ( Job_Action_Scheduler::is_slice_execution_active() ) {
			return true;
		}

		if (
			Job_Action_Scheduler::has_pending_or_running()
			&& self::global_worker_owner_matches( self::OWNER_BULK )
		) {
			return true;
		}

		return false;
	}

	/**
	 * Bulk AS slices must not run while a metabox AS worker is live.
	 *
	 * @return bool
	 */
	public static function should_defer_bulk_work() {
		if ( self::is_halted() ) {
			return true;
		}

		if ( Metabox_Action_Scheduler::has_any_live_as_actions() ) {
			return true;
		}

		if ( self::global_worker_owner_matches( self::OWNER_METABOX ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @param string $owner {@see OWNER_BULK} or {@see OWNER_METABOX}.
	 * @return bool
	 */
	public static function try_claim_global_worker( $owner ) {
		$owner = sanitize_key( (string) $owner );

		if ( '' === $owner ) {
			return false;
		}

		$held = get_transient( self::GLOBAL_LOCK_KEY );

		if ( false !== $held && (string) $held !== $owner ) {
			// Metabox outranks bulk so footer/template translates don't sit at 0/N forever.
			if ( self::OWNER_METABOX === $owner && self::OWNER_BULK === (string) $held ) {
				delete_transient( self::GLOBAL_LOCK_KEY );
			} else {
				return false;
			}
		}

		set_transient( self::GLOBAL_LOCK_KEY, $owner, self::GLOBAL_LOCK_TTL );

		return true;
	}

	/**
	 * @param string|null $owner Optional owner to release; null releases unconditionally.
	 * @return void
	 */
	public static function release_global_worker( $owner = null ) {
		if ( null === $owner ) {
			delete_transient( self::GLOBAL_LOCK_KEY );

			return;
		}

		$owner = sanitize_key( (string) $owner );
		$held  = get_transient( self::GLOBAL_LOCK_KEY );

		if ( false !== $held && (string) $held === $owner ) {
			delete_transient( self::GLOBAL_LOCK_KEY );
		}
	}

	/**
	 * @param string $owner Expected owner.
	 * @return bool
	 */
	private static function global_worker_owner_matches( $owner ) {
		$held = get_transient( self::GLOBAL_LOCK_KEY );

		return false !== $held && (string) $held === sanitize_key( (string) $owner );
	}
}
