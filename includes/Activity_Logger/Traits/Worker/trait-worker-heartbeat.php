<?php
/**
 * Activity_Logger Worker_Heartbeat (auto-split).
 *
 * @package PolymartAI\Activity_Logger\Traits
 */

namespace PolymartAI\Activity_Logger\Traits\Worker;

use PolymartAI\Activity_Logger\Job_Action_Scheduler;
use PolymartAI\Activity_Logger\Metabox_Action_Scheduler;
use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\Content\Menu_Translator;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Pipeline\Translation_Query;


defined( 'ABSPATH' ) || exit;

trait Trait_Worker_Heartbeat {

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

	public static function is_bulk_worker_lively( $max_silent_sec = null ) {
		if ( ! self::is_bulk_job_running() ) {
			return false;
		}

		$max_silent_sec = null === $max_silent_sec
			? Job_Action_Scheduler::STALE_RUNNING_SEC
			: max( 30, absint( $max_silent_sec ) );

		$job  = self::get_job_raw();
		$last = max(
			absint( $job['last_cron_at'] ?? 0 ),
			absint( $job['worker_heartbeat_at'] ?? 0 ),
			absint( $job['step_started_at'] ?? 0 )
		);
		$heartbeat_age = $last > 0 ? ( time() - $last ) : PHP_INT_MAX;

		$lock  = get_transient( self::STEP_LOCK_KEY );
		$claim = absint( get_option( self::STEP_LOCK_CLAIM_KEY, 0 ) );
		$stamp = max( $lock ? absint( $lock ) : 0, $claim );

		if ( $stamp > 0 ) {
			$lock_age  = time() - $stamp;
			$lock_ttl  = min( 95, max( 30, (int) apply_filters( 'polymart_ai_step_lock_lively_sec', 90 ) ) );
			$hb_window = min( $max_silent_sec, 120 );

			// A step lock alone must not mask a dead worker for the full stale window.
			if ( $lock_age < $lock_ttl && $heartbeat_age < $hb_window ) {
				return true;
			}
		}

		return $last > 0 && $heartbeat_age < $max_silent_sec;
	}

	public static function touch_job_worker_heartbeat() {
		self::touch_worker_heartbeat();
	}

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

		// After seal/leave-queue, never re-pin Elementor library templates (caused
		// «تماس باما در ساری» to stay pinned with no AS work → recovery thrash).
		if ( self::elementor_companion_is_bulk_complete( $post_id, $lang ) ) {
			if ( absint( $job['partial_post_id'] ?? 0 ) === $post_id ) {
				$job['partial_post_id']  = null;
				$job['partial_phase']    = null;
				$job['partial_progress'] = null;
				unset( $job['step_partial'] );
				$job['worker_heartbeat_at'] = time();
				$job['last_cron_at']        = time();
				self::save_job( $job );
			}

			return;
		}

		$marker = Post_Translator::format_elementor_job_progress_marker( $post_id, $lang );

		if ( '' === $marker || ! preg_match( '/^\d+\/\d+/', $marker ) ) {
			return;
		}

		// Keep only the primary API slice for the bulk-job option (before any gap-fill suffix).
		if ( preg_match( '/^(\d+\/\d+)/', $marker, $primary_matches ) ) {
			$marker = (string) $primary_matches[1];
		}

		$current    = (string) ( $job['partial_progress'] ?? '' );
		$new_parsed = self::parse_job_phase_progress( $marker );
		$old_parsed = self::parse_job_phase_progress( $current );

		if (
			is_array( $new_parsed )
			&& is_array( $old_parsed )
			&& $old_parsed['done'] > $new_parsed['done']
		) {
			if ( ( $old_parsed['done'] - $new_parsed['done'] ) >= 2 ) {
				$marker = $new_parsed['done'] . '/' . max( $old_parsed['total'], $new_parsed['total'] );
			} else {
				$marker = $old_parsed['done'] . '/' . max( $old_parsed['total'], $new_parsed['total'] );
			}
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
	 * Whether Elementor work for this post is finished for the active bulk language.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	private static function elementor_companion_is_bulk_complete( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! Post_Translator::uses_elementor_builder( $post_id ) ) {
			return false;
		}

		if ( ! Post_Translator::is_elementor_translation_finalized( $post_id, $lang ) ) {
			return false;
		}

		if ( Post_Translator::elementor_job_has_remaining_payload( $post_id, $lang ) ) {
			return false;
		}

		if ( Post_Translator::stored_elementor_translation_has_persian( $post_id, $lang ) ) {
			return false;
		}

		if ( ! Post_Translator::elementor_translation_is_storefront_ready( $post_id, $lang ) ) {
			return false;
		}

		return ! Post_Translator::has_remaining_field_gaps_for_job( $post_id, $lang );
	}

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

}
