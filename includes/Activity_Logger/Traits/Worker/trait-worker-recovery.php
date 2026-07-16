<?php
/**
 * Activity_Logger Worker_Recovery (auto-split).
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

trait Trait_Worker_Recovery {

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

	public static function recover_worker_infrastructure_failure( $message, array $context = array() ) {
		if ( Translation_Scheduler_Coordinator::is_halted() ) {
			return self::normalize_job_for_response( self::get_job_raw(), false );
		}

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

		$job     = self::get_job_raw();
		$lang    = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
		$post_id = absint( $context['post_id'] ?? 0 );

		if ( $post_id <= 0 ) {
			$post_id = absint( $job['partial_post_id'] ?? 0 ) ?: absint( $job['current_post_id'] ?? 0 );
		}

		$force_release = ! empty( $context['fatal'] )
			|| in_array(
				(string) ( $context['source'] ?? '' ),
				array( 'as_shutdown', 'as_fatal', 'as_elementor_burst' ),
				true
			);

		if ( $force_release ) {
			Job_Action_Scheduler::clear_slice_mutex();

			if ( $post_id > 0 && '' !== $lang ) {
				Post_Translator::release_translation_lock( $post_id, $lang, true );
			}
		}

		self::force_unlock_step_now();

		$job = self::get_job_raw();

		if ( 'running' === ( $job['status'] ?? '' ) ) {
			if ( $force_release ) {
				unset( $job['step_deferred'], $job['step_deferred_reason'], $job['step_deferred_message'] );
			}

			$job['worker_heartbeat_at'] = time();
			$job['last_cron_at']        = time();
			$job['last_worker']         = self::$trusted_as_tick ? 'as' : self::resolve_last_worker_label();
			self::save_job( $job );
		}

		if (
			$force_release
			&& ! Translation_Scheduler_Coordinator::is_halted()
			&& self::is_bulk_job_running()
			&& Job_Action_Scheduler::is_available()
		) {
			Job_Action_Scheduler::chain_next_after_recovery();
		}

		return self::normalize_job_for_response( $job, false );
	}

	/**
	 * Break AS/step-lock deadlocks after prolonged silence (cron + admin ensure).
	 *
	 * @param string $reason Short diagnostic tag.
	 * @return bool True when recovery actions ran.
	 */
	public static function force_recover_stalled_bulk_worker( $reason = '' ) {
		if ( Translation_Scheduler_Coordinator::is_halted() || ! self::is_bulk_job_running() ) {
			return false;
		}

		$mutex_key = 'polymart_ai_worker_recover_mux';
		if ( get_transient( $mutex_key ) ) {
			return false;
		}
		set_transient( $mutex_key, time(), 45 );

		$job  = self::get_job_raw();
		$age  = self::get_bulk_worker_activity_age();
		$need = max(
			45,
			min(
				120,
				(int) apply_filters( 'polymart_ai_worker_force_recover_sec', self::WORKER_FORCE_RECOVER_SEC )
			)
		);

		if ( $age < $need ) {
			delete_transient( $mutex_key );
			return false;
		}

		$last_recover = absint( $job['worker_recovered_at'] ?? 0 );
		if ( $last_recover > 0 && ( time() - $last_recover ) < min( 55, $need ) ) {
			delete_transient( $mutex_key );
			return false;
		}

		$lang              = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
		$post_id           = absint( $job['partial_post_id'] ?? 0 ) ?: absint( $job['current_post_id'] ?? 0 );
		$before_progress   = (string) ( $job['partial_progress'] ?? '' );
		$before_progress_at = absint( $job['partial_progress_at'] ?? 0 );

		if ( self::should_prioritize_elementor_partial( $job ) ) {
			$pin_post_id = absint( $job['partial_post_id'] ?? 0 ) ?: absint( $job['current_post_id'] ?? 0 );
			$pin_lang    = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

			if ( $pin_post_id > 0 && '' !== $pin_lang ) {
				if ( Post_Translator::elementor_recovery_should_force_finalize( $pin_post_id, $pin_lang, $job ) ) {
					Post_Translator::force_finalize_elementor_job_from_recovery( $pin_post_id, $pin_lang, 'kick-recovery' );
					$job = self::get_job_raw();
				} elseif ( ! Post_Translator::elementor_recovery_should_skip_queue_repair( $pin_post_id, $pin_lang, $job ) ) {
					self::reconcile_elementor_bulk_pin( $job );
					$job = self::get_job_raw();
				} else {
					Post_Translator::note_elementor_queue_repair_attempt( $pin_post_id, $pin_lang );
				}
			}
		}

		Job_Action_Scheduler::fail_all_running_bulk_actions();
		Job_Action_Scheduler::recover_stale_running_actions( max( 30, min( $age, Job_Action_Scheduler::STALE_RUNNING_SEC ) ) );
		Job_Action_Scheduler::clear_slice_mutex();
		self::release_stale_locks_for_as( min( $age, Job_Action_Scheduler::STALE_RUNNING_SEC ) );
		self::force_unlock_step_now();

		if ( $post_id > 0 && '' !== $lang ) {
			Post_Translator::release_stale_translation_lock( $post_id, $lang, min( $age, 90 ) );
			Post_Translator::release_translation_lock( $post_id, $lang, true );
		}

		$job = self::get_job_raw();
		self::recover_stalled_job_picker( $job, $lang, true );
		unset( $job['step_deferred'], $job['step_deferred_reason'], $job['step_deferred_message'] );
		$job['worker_recover_reason'] = sanitize_key( (string) $reason );
		self::save_job( $job );

		self::ensure_recurring_pulse();

		$burst_ran   = false;
		$as_enqueued = 0;
		$as_ran      = 0;

		if ( Job_Action_Scheduler::is_available() ) {
			$as_enqueued = Job_Action_Scheduler::enqueue_next( true, 0 );
			$as_ran      = Job_Action_Scheduler::run_queue_inline( true );

			// Zombie clear + enqueue can still leave the runner idle; force one direct batch.
			if ( $as_ran <= 0 && ! Job_Action_Scheduler::is_slice_execution_active() ) {
				$job           = self::get_job_raw();
				$steps_before  = absint( $job['steps'] ?? 0 );
				$progress_at_b = absint( $job['partial_progress_at'] ?? 0 );

				if ( self::should_prioritize_elementor_partial( $job ) ) {
					$cap = self::get_elementor_partial_step_cap( $job );
					self::run_action_scheduler_batch( max( 1, $cap ), Job_Action_Scheduler::SLICE_BUDGET_SEC );
				} else {
					self::run_action_scheduler_batch( 1, Job_Action_Scheduler::SLICE_BUDGET_SEC );
				}

				$job_after_batch = self::get_job_raw();
				if (
					absint( $job_after_batch['steps'] ?? 0 ) > $steps_before
					|| absint( $job_after_batch['partial_progress_at'] ?? 0 ) > $progress_at_b
				) {
					$as_ran = 1;
				}
			}
		}

		$job = self::get_job_raw();

		if (
			self::should_prioritize_elementor_partial( $job )
			&& $post_id > 0
			&& '' !== $lang
			&& ! Post_Translator::elementor_recovery_should_skip_queue_repair( $post_id, $lang, $job )
			&& ! self::is_job_api_cooldown_active( $job )
			&& (
				self::is_elementor_progress_stalled( $job )
				|| Post_Translator::elementor_needs_gap_fill_work( $post_id, $lang )
				|| Post_Translator::elementor_job_api_slices_pending( $post_id, $lang )
				|| ( ! $as_enqueued && $as_ran <= 0 )
			)
		) {
			$burst_ran = self::run_pinned_elementor_work( true );
		}

		$job            = self::get_job_raw();
		$after_progress = (string) ( $job['partial_progress'] ?? '' );
		$progress_moved = $before_progress !== $after_progress
			|| absint( $job['partial_progress_at'] ?? 0 ) > $before_progress_at;

		if (
			! $progress_moved
			&& ! $burst_ran
			&& $post_id > 0
			&& '' !== $lang
			&& self::should_prioritize_elementor_partial( $job )
		) {
			Post_Translator::note_elementor_queue_repair_attempt( $post_id, $lang );

			if ( Post_Translator::elementor_recovery_should_force_finalize( $post_id, $lang, $job ) ) {
				Post_Translator::force_finalize_elementor_job_from_recovery( $post_id, $lang, 'kick-stale-progress' );
				$job = self::get_job_raw();
				$progress_moved = true;
			}
		}

		$did_work = $progress_moved || $burst_ran || $as_ran > 0 || $as_enqueued > 0;

		$job = self::get_job_raw();
		$job['worker_recovered_at']   = time();
		$job['worker_recover_reason'] = sanitize_key( (string) $reason );

		if ( $did_work ) {
			$job['worker_heartbeat_at'] = time();
			$job['last_cron_at']        = time();
			$job['last_worker']         = wp_doing_cron() ? 'cron' : self::resolve_last_worker_label();
		}

		self::save_job( $job );

		self::log(
			'warning',
			sprintf(
				/* translators: 1: idle seconds, 2: reason tag */
				__( 'کارگر ترجمه پس از %1$dث سکوت بازیابی شد (%2$s) — صف دوباره زنجیره شد.', 'polymart-ai' ),
				$age,
				'' !== $reason ? $reason : 'stale'
			)
		);

		delete_transient( $mutex_key );

		return $did_work;
	}

}
