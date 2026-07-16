<?php
/**
 * Activity_Logger Job_Elementor (auto-split).
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

trait Trait_Job_Elementor {

	/**
	 * Merge bulk-job progress with live Elementor partial state (allow resync after restart).
	 */
	private static function resolve_elementor_bulk_progress_marker( $current, $marker ) {
		$marker = trim( (string) $marker );

		if ( preg_match( '/^(\d+\/\d+)/', $marker, $matches ) ) {
			$marker = (string) $matches[1];
		}

		$new_parsed = self::parse_job_phase_progress( $marker );
		$old_parsed = self::parse_job_phase_progress( (string) $current );

		if (
			is_array( $new_parsed )
			&& is_array( $old_parsed )
			&& $old_parsed['done'] > $new_parsed['done']
		) {
			if ( ( $old_parsed['done'] - $new_parsed['done'] ) >= 2 ) {
				return $new_parsed['done'] . '/' . max( $old_parsed['total'], $new_parsed['total'] );
			}

			return $old_parsed['done'] . '/' . max( $old_parsed['total'], $new_parsed['total'] );
		}

		return $marker;
	}

	/**
	 * Repair pinned Elementor bulk work and resync progress before ensure/kick/burst.
	 *
	 * @param array<string, mixed>|null $job Optional job snapshot.
	 * @return bool True when repair/finalize/resync ran.
	 */
	public static function reconcile_elementor_bulk_pin( $job = null ) {
		$job = is_array( $job ) ? $job : self::get_job_raw();

		if ( 'running' !== ( $job['status'] ?? '' ) || ! self::should_prioritize_elementor_partial( $job ) ) {
			return false;
		}

		$post_id = absint( $job['partial_post_id'] ?? 0 ) ?: absint( $job['current_post_id'] ?? 0 );
		$lang    = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		// #21870-style: EN JSON clean but pin stuck on not_finalized / missing __seg map.
		// Keep this narrow — never seal mid-job posts that still need AI work.
		// Skip heavy decode paths on ensure when Elementor source is huge (avoids 503).
		$elementor_bytes = strlen( (string) get_post_meta( $post_id, '_elementor_data', true ) );
		$allow_heavy     = $elementor_bytes <= 350000
			|| self::is_trusted_job_worker()
			|| wp_doing_cron()
			|| ( defined( 'WP_CLI' ) && WP_CLI );

		try {
			if (
				$allow_heavy
				&& ! Post_Translator::stored_elementor_translation_has_persian( $post_id, $lang )
				&& is_string( Post_Translator::get_stored_elementor_json( $post_id, $lang ) )
				&& ! Post_Translator::elementor_translation_is_storefront_ready( $post_id, $lang )
			) {
				$remaining = Post_Translator::count_elementor_remaining_fields( $post_id, $lang );

				if ( $remaining <= 1 && Post_Translator::seal_clean_elementor_companion( $post_id, $lang ) ) {
					return true;
				}
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Never let seal fatals kill start/ensure requests.
		}

		if ( $allow_heavy && Post_Translator::elementor_recovery_should_force_finalize( $post_id, $lang, $job ) ) {
			try {
				if ( Post_Translator::force_finalize_elementor_job_from_recovery( $post_id, $lang, 'bulk-pin-recovery' ) ) {
					return true;
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}

		if ( Post_Translator::elementor_recovery_should_skip_queue_repair( $post_id, $lang, $job ) ) {
			return false;
		}

		$changed = false;

		if (
			Post_Translator::is_elementor_translation_finalized( $post_id, $lang )
			&& ! Post_Translator::elementor_job_has_remaining_payload( $post_id, $lang )
			&& Post_Translator::elementor_translation_is_storefront_ready( $post_id, $lang )
			&& ! Post_Translator::stored_elementor_translation_has_persian( $post_id, $lang )
		) {
			self::sync_bulk_job_after_elementor_finalize( $post_id, $lang );

			return true;
		}

		Post_Translator::repair_stale_elementor_completion_meta( $post_id, $lang );

		if ( Post_Translator::repair_elementor_stalled_chunk_queue( $post_id, $lang ) ) {
			$changed = true;
		}

		if ( Post_Translator::unblock_elementor_chunk_queue( $post_id, $lang ) ) {
			$changed = true;
		}

		$marker = Post_Translator::format_elementor_job_progress_marker( $post_id, $lang );

		if ( '' !== $marker && preg_match( '/^(\d+\/\d+)/', $marker, $matches ) ) {
			$resolved = self::resolve_elementor_bulk_progress_marker(
				(string) ( $job['partial_progress'] ?? '' ),
				(string) $matches[1]
			);

			if ( $resolved !== (string) ( $job['partial_progress'] ?? '' ) ) {
				$job = self::get_job_raw();
				$job['partial_post_id']  = $post_id;
				$job['partial_phase']    = 'elementor';
				$job['partial_progress'] = $resolved;
				self::touch_partial_progress_tracker( $job );
				self::save_job( $job );
				$changed = true;
			}
		}

		return $changed;
	}

	private static function refresh_elementor_job_progress_snapshot( array $job, $persist = true ) {
		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return $job;
		}

		$poll_only = self::is_job_poll_request();
		if ( $poll_only ) {
			$persist = false;
		}

		$post_id = absint( $job['partial_post_id'] ?? 0 );
		$lang    = sanitize_key( (string) ( $job['lang'] ?? '' ) );

		if ( $post_id <= 0 || '' === $lang || 'elementor' !== sanitize_key( (string) ( $job['partial_phase'] ?? '' ) ) ) {
			return $job;
		}

		$marker = Post_Translator::format_elementor_job_progress_marker( $post_id, $lang );

		if ( '' === $marker || ! preg_match( '/^\d+\/\d+/', $marker ) ) {
			return $job;
		}

		$current = (string) ( $job['partial_progress'] ?? '' );
		$marker  = self::resolve_elementor_bulk_progress_marker( $current, $marker );

		if ( $current === $marker ) {
			return $job;
		}

		$job['partial_progress'] = $marker;

		if ( ! $persist ) {
			return $job;
		}

		self::touch_partial_progress_tracker( $job );
		update_option( self::JOB_OPTION, $job, false );

		return $job;
	}

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

	private static function post_has_elementor_job_state( $post_id, $lang ) {
		return Post_Translator::should_run_elementor_job_slice( $post_id, $lang );
	}

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

	private static function pin_job_for_elementor_post( array &$job, $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return;
		}

		if ( ! Post_Translator::should_run_elementor_job_slice( $post_id, $lang ) ) {
			return;
		}

		// Allow re-pin when EN JSON still has Persian even if a soft finalize
		// marked the companion "finalized" with empty remaining_payload.
		if (
			Post_Translator::is_elementor_translation_finalized( $post_id, $lang )
			&& ! Post_Translator::elementor_job_has_remaining_payload( $post_id, $lang )
			&& Post_Translator::elementor_translation_is_storefront_ready( $post_id, $lang )
			&& ! Post_Translator::stored_elementor_translation_has_persian( $post_id, $lang )
		) {
			return;
		}

		$state = Post_Translator::get_job_partial_state( $post_id, $lang );
		$state = Post_Translator::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
		Post_Translator::save_job_partial_state( $post_id, $lang, $state );

		$marker   = Post_Translator::format_elementor_job_progress_marker( $post_id, $lang, $state );
		$existing = (string) ( $job['partial_progress'] ?? '' );
		$new_p    = self::parse_job_phase_progress( $marker );
		$old_p    = self::parse_job_phase_progress( $existing );

		if (
			absint( $job['partial_post_id'] ?? 0 ) === $post_id
			&& is_array( $new_p )
			&& is_array( $old_p )
			&& $old_p['done'] > $new_p['done']
		) {
			$marker = $old_p['done'] . '/' . max( $old_p['total'], $new_p['total'] );
		}

		$job['partial_post_id']  = $post_id;
		$job['partial_phase']    = 'elementor';
		$job['partial_progress'] = $marker;
		self::touch_partial_progress_tracker( $job );
		$job['step_partial']     = true;
		$job['current_post_id']  = null;
		$job['step_started_at']  = null;
	}

	public static function should_prioritize_elementor_partial( array $job ) {
		$lang = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

		if ( self::job_has_active_elementor_pin( $job, $lang ) ) {
			return true;
		}

		if ( self::elementor_partial_has_remaining( $job ) ) {
			return true;
		}

		$active_id = absint( $job['current_post_id'] ?? 0 ) ?: absint( $job['partial_post_id'] ?? 0 );

		// Only bypass the normal queue when Elementor slices are actually runnable now
		// (field phases done). API-slice counters alone are true while title/body still need work.
		if ( $active_id > 0 && Post_Translator::should_run_elementor_job_slice( $active_id, $lang ) ) {
			return true;
		}

		$deferred = self::get_actionable_deferred_queue( $job, $lang );

		if ( ! empty( $deferred[0] ) && Post_Translator::should_run_elementor_job_slice( (int) $deferred[0], $lang ) ) {
			return true;
		}

		return false;
	}

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

		$post_id = absint( $job['partial_post_id'] ?? 0 ) ?: absint( $job['current_post_id'] ?? 0 );
		$lang    = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

		if ( ! Job_Action_Scheduler::is_available() ) {
			return;
		}

		Job_Action_Scheduler::recover_stale_running_actions( Job_Action_Scheduler::STALE_RUNNING_SEC );
		Job_Action_Scheduler::clear_slice_mutex_if_stale();

		$force_inline = ! self::is_bulk_worker_lively( 45 );

		if ( ! Job_Action_Scheduler::has_pending_or_running() ) {
			Job_Action_Scheduler::enqueue_next( false, 0 );
		}

		Job_Action_Scheduler::run_queue_inline( $force_inline );

		if (
			$post_id > 0
			&& '' !== $lang
			&& Post_Translator::elementor_needs_gap_fill_work( $post_id, $lang )
			&& ! self::is_bulk_worker_lively( 25 )
		) {
			self::run_pinned_elementor_work( false );
		}
	}

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

	public static function filter_as_elementor_chunk_budget() {
		if ( self::$elementor_page_burst ) {
			return 25;
		}

		if ( self::$trusted_as_tick && self::should_prioritize_elementor_partial( self::get_job_raw() ) ) {
			$job     = self::get_job_raw();
			$post_id = absint( $job['partial_post_id'] ?? 0 ) ?: absint( $job['current_post_id'] ?? 0 );
			$lang    = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

			if ( $post_id > 0 && '' !== $lang && Post_Translator::elementor_needs_gap_fill_work( $post_id, $lang ) ) {
				return 3;
			}

			return 3;
		}

		return 1;
	}

	public static function filter_elementor_burst_inter_delay( $delay ) {
		if ( self::$elementor_page_burst || self::$trusted_as_tick ) {
			return 2;
		}

		return (int) $delay;
	}

	public static function is_elementor_page_burst() {
		return self::$elementor_page_burst;
	}

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

		if ( self::maybe_restore_elementor_pin_from_queue( $job ) ) {
			self::save_job( $job );
			$job = self::get_job_raw();
		}

		$post_id = absint( $job['partial_post_id'] ?? 0 );
		$lang    = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

		if ( $post_id <= 0 || '' === $lang ) {
			return self::normalize_job_for_response( $job, false );
		}

		Metabox_Action_Scheduler::cancel_pending( $post_id, $lang );

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
				$job = self::get_job_raw();

				if ( absint( $job['partial_post_id'] ?? 0 ) === $post_id ) {
					$job['partial_post_id']  = null;
					$job['partial_phase']    = null;
					$job['partial_progress'] = null;
					unset( $job['step_partial'] );
				}

				$job['last_cron_at']        = time();
				$job['worker_heartbeat_at'] = time();
				self::save_job( $job );

				self::continue_job_chain( 0, true );

				if ( is_array( $result ) ) {
					$result['elementor_page_complete'] = true;
				}

				return is_array( $result ) ? $result : self::normalize_job_for_response( self::get_job_raw(), false );
			}

			self::schedule_elementor_partial_follow_up( 4 );

			return is_array( $result ) ? $result : self::normalize_job_for_response( self::get_job_raw(), false );
		} finally {
			self::$elementor_page_burst = false;
			self::$trusted_admin_worker = false;
		}
	}

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

	public static function sync_bulk_job_after_elementor_finalize( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::is_bulk_job_running() ) {
			return;
		}

		if (
			Post_Translator::elementor_job_has_remaining_payload( $post_id, $lang )
			|| Post_Translator::stored_elementor_translation_has_persian( $post_id, $lang )
			|| ! Post_Translator::elementor_translation_is_storefront_ready( $post_id, $lang )
		) {
			return;
		}

		$job = self::get_job_raw();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return;
		}

		// Elementor-ready alone is not enough — Arabic jobs were leaving the queue
		// while title/meta still showed «ناقص» and then thrashing recovery every 60s.
		if ( Post_Translator::has_remaining_field_gaps_for_job( $post_id, $lang ) ) {
			$state = Post_Translator::reopen_job_partial_for_remaining_fields( $post_id, $lang );

			self::untrack_succeeded_post( $job, $post_id );
			self::track_partial_post( $job, $post_id );

			$phase = sanitize_key( (string) ( is_array( $state ) ? ( $state['phase'] ?? 'core' ) : 'core' ) );
			$job['partial_post_id']  = $post_id;
			$job['partial_phase']    = $phase;
			$job['partial_progress'] = is_array( $state )
				? (string) ( $state['phase_progress'] ?? '0/1' )
				: '0/1';
			$job['current_post_id']  = null;
			$job['step_started_at']  = null;

			Post_Translator::flush_translation_status_cache( $post_id );
			REST_API::invalidate_stats_cache();
			self::save_job( $job );

			$title = get_the_title( $post_id ) ?: ( '#' . $post_id );
			$gaps  = Post_Translator::get_translation_gaps( $post_id, $lang );
			$missing = ! empty( $gaps['missing'] ) ? implode( ', ', $gaps['missing'] ) : __( 'فیلدهای متنی', 'polymart-ai' );

			self::log(
				'warning',
				sprintf(
					/* translators: 1: post title, 2: missing labels */
					__( '«%1$s» — Elementor تمام شد ولی هنوز ناقص است (%2$s)؛ برای تکمیل فیلدها در صف می‌ماند.', 'polymart-ai' ),
					$title,
					$missing
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);

			return;
		}

		$was_new = ! self::job_already_counted_success( $job, $post_id );
		self::track_succeeded_post( $job, $post_id );
		self::clear_partial_post( $job, $post_id );

		if ( absint( $job['partial_post_id'] ?? 0 ) === $post_id ) {
			$job['partial_post_id']  = null;
			$job['partial_phase']    = null;
			$job['partial_progress'] = null;
			unset( $job['step_partial'] );
		}

		if ( absint( $job['current_post_id'] ?? 0 ) === $post_id ) {
			$job['current_post_id'] = null;
			$job['step_started_at'] = null;
		}

		$job['consecutive_post_id']    = 0;
		$job['consecutive_post_steps'] = 0;
		$job['stuck_progress_steps']   = 0;
		$job['last_post_id']           = max( (int) ( $job['last_post_id'] ?? 0 ), $post_id );

		Post_Translator::release_translation_lock( $post_id, $lang, true );
		Post_Translator::flush_translation_status_cache( $post_id );
		REST_API::invalidate_stats_cache();
		self::save_job( $job );

		if ( $was_new ) {
			$title = get_the_title( $post_id ) ?: ( '#' . $post_id );

			self::log(
				'success',
				sprintf(
					/* translators: 1: post title, 2: language label */
					__( '«%1$s» — Elementor کامل شد؛ از صف bulk خارج شد (%2$s).', 'polymart-ai' ),
					$title,
					$job['lang_label'] ?? $lang
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);
		}
	}

	public static function run_direct_elementor_burst() {
		return self::run_pinned_elementor_work( true );
	}

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

}
