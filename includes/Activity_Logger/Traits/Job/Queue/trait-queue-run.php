<?php
/**
 * Activity_Logger Job Queue_Run (auto-split).
 *
 * @package PolymartAI\Activity_Logger\Traits
 */

namespace PolymartAI\Activity_Logger\Traits\Job\Queue;

use PolymartAI\Activity_Logger\Job_Action_Scheduler;
use PolymartAI\Activity_Logger\Metabox_Action_Scheduler;
use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\Content\Menu_Translator;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Pipeline\Translation_Query;


defined( 'ABSPATH' ) || exit;

trait Trait_Queue_Run {

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

	public static function kick_worker() {
		$job = self::get_job_raw();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return self::normalize_job_for_response( $job, false );
		}

		$before_progress = (string) ( $job['partial_progress'] ?? '' );
		$did_recover     = self::force_recover_stalled_bulk_worker( 'kick' );

		if ( ! $did_recover && self::should_prioritize_elementor_partial( $job ) ) {
			$pin_post_id = absint( $job['partial_post_id'] ?? 0 ) ?: absint( $job['current_post_id'] ?? 0 );
			$pin_lang    = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

			if (
				$pin_post_id > 0
				&& '' !== $pin_lang
				&& ! Post_Translator::elementor_recovery_should_skip_queue_repair( $pin_post_id, $pin_lang, $job )
			) {
				self::reconcile_elementor_bulk_pin( $job );
				$job = self::get_job_raw();
			}
		}

		if ( ! Job_Action_Scheduler::is_slice_execution_active() ) {
			self::force_release_step_lock_if_idle();
		}

		self::ensure_recurring_pulse();
		Job_Action_Scheduler::clear_slice_mutex_if_stale();

		if ( self::maybe_restore_elementor_pin_from_queue( $job ) ) {
			self::save_job( $job );
		}

		if ( Job_Action_Scheduler::is_available() && ! Job_Action_Scheduler::is_slice_execution_active() ) {
			Job_Action_Scheduler::enqueue_next( true, 0 );
		}

		$result = self::run_inline_worker_tick();

		if ( is_array( $result ) ) {
			$result['worker_mode'] = Job_Action_Scheduler::is_available() ? 'as' : 'cron';

			$post_id = absint( $result['partial_post_id'] ?? 0 ) ?: absint( $result['current_post_id'] ?? 0 );
			$lang    = sanitize_key( (string) ( $result['lang'] ?? 'en' ) );

			if (
				$post_id > 0
				&& '' !== $lang
				&& Post_Translator::elementor_needs_gap_fill_work( $post_id, $lang )
				&& self::is_elementor_progress_stalled( $result )
				&& ! self::is_job_api_cooldown_active( $result )
			) {
				self::run_pinned_elementor_work( true );
				$result = self::normalize_job_for_response( self::get_job_raw(), false );
				$result['worker_direct_tick'] = true;
				$result['worker_mode']        = Job_Action_Scheduler::is_available() ? 'as' : 'cron';
			}

			$result['worker_kicked'] = self::kick_worker_moved_queue(
				$before_progress,
				$result,
				$did_recover,
				(bool) ( $result['worker_direct_tick'] ?? false ),
				(bool) ( $result['worker_inline_tick'] ?? false )
			);

			return $result;
		}

		$out = self::normalize_job_for_response( self::get_job_raw(), false );
		$out['worker_kicked'] = self::kick_worker_moved_queue( $before_progress, $out, $did_recover, false, false );
		$out['as_pending']    = Job_Action_Scheduler::has_pending_or_running();
		$out['worker_mode']   = Job_Action_Scheduler::is_available() ? 'as' : 'cron';

		return $out;
	}

	/**
	 * True when kick actually advanced or recovered the queue (not a no-op poll).
	 *
	 * @param string               $before_progress Progress before kick.
	 * @param array<string, mixed> $after           Job snapshot after kick.
	 * @param bool                 $did_recover     force_recover ran.
	 * @param bool                 $direct_tick     Pinned Elementor burst ran.
	 * @param bool                 $inline_tick     Inline worker tick processed work.
	 */
	private static function kick_worker_moved_queue( $before_progress, $after, $did_recover, $direct_tick, $inline_tick ) {
		if ( $did_recover || $direct_tick || $inline_tick ) {
			return true;
		}

		$after_progress = (string) ( $after['partial_progress'] ?? '' );

		return '' !== $after_progress && $before_progress !== $after_progress;
	}

	public static function process_job_step() {
		if ( function_exists( 'set_time_limit' ) ) {
			if ( self::$elementor_page_burst ) {
				$limit = 330;
			} elseif ( self::$trusted_as_tick ) {
				$limit = 120;
			} else {
				$limit = 1200;
			}
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

		if ( Post_Translator::uses_elementor_builder( $post_id ) ) {
			Post_Translator::invalidate_elementor_job_success_markers( $post_id, $lang );

			if (
				Post_Translator::elementor_needs_gap_fill_work( $post_id, $lang )
				|| Post_Translator::elementor_job_has_stubborn_remaining( $post_id, $lang )
			) {
				self::untrack_succeeded_post( $job, $post_id );
			}
		}

		// Already done for this language — do not burn an AI call or spam "ترجمه شد".
		if ( Post_Translator::post_needs_translation_work( $post_id, $lang ) ) {
			if ( self::job_already_counted_success( $job, $post_id ) ) {
				self::untrack_succeeded_post( $job, $post_id );
			}
		} elseif (
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
			$stale_sec = self::$trusted_as_tick ? 30 : null;

			if ( Post_Translator::release_stale_translation_lock( $post_id, $lang, $stale_sec ) ) {
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
				if ( ! Post_Translator::is_api_transport_timeout_error( $slice ) ) {
					if ( self::is_non_throttle_recoverable_slice_error( $slice ) ) {
						self::maybe_clear_stale_api_cooldown( $slice );
					} else {
						self::maybe_apply_api_throttle_cooldown( $slice );
					}
				} else {
					self::touch_successful_api_call();
				}
				$job = self::get_job_raw();

				if (
					self::is_job_api_cooldown_active( $job )
					&& ! self::is_non_throttle_recoverable_slice_error( $slice )
				) {
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
				$non_throttle_slice = self::is_non_throttle_recoverable_slice_error( $err_msg );

				if ( $non_throttle_slice ) {
					self::touch_successful_api_call();
				} elseif ( '' !== $err_msg && ! self::is_synthetic_api_throttle_message( $err_msg ) ) {
					self::maybe_apply_api_throttle_cooldown(
						new \WP_Error( 'polymart_ai_api_error', $err_msg )
					);
				}

				$job = self::get_job_raw();

				if ( self::is_job_api_cooldown_active( $job ) && ! $non_throttle_slice ) {
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

			$parsed_done = self::parse_job_phase_progress( (string) ( $slice['phase_progress'] ?? '' ) );

			if (
				'elementor' === sanitize_key( (string) ( $slice['phase'] ?? '' ) )
				&& is_array( $parsed_done )
				&& $parsed_done['total'] > 0
				&& $parsed_done['done'] >= $parsed_done['total']
			) {
				self::schedule_elementor_partial_follow_up( 0 );
			} elseif (
				'elementor' === sanitize_key( (string) ( $slice['phase'] ?? '' ) )
				&& Post_Translator::elementor_needs_gap_fill_work( $post_id, $lang )
			) {
				self::schedule_elementor_partial_follow_up( 0 );
			} elseif (
				'elementor' === sanitize_key( (string) ( $slice['phase'] ?? '' ) )
				&& ! empty( $slice['recoverable'] )
			) {
				// Timeout / recoverable API errors must chain the next slice immediately.
				self::schedule_elementor_partial_follow_up( 0 );
			}

			return self::normalize_job_for_response( $job, false );
		}

		if (
			! empty( $slice['done'] )
			&& 'complete' !== sanitize_key( (string) ( $slice['phase'] ?? '' ) )
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
			'polymart_ai_elementor_partial' === $error_code ? 'warning' : 'error',
			sprintf(
				/* translators: 1: post ID, 2: error message */
				__( 'خطا در ترجمه مورد #%1$d: %2$s', 'polymart-ai' ),
				$post_id,
				$error_message
			),
			array( 'post_id' => $post_id, 'lang' => $lang )
		);

		if (
			'polymart_ai_elementor_partial' === $error_code
			&& ! self::is_critical_api_error( $result )
		) {
			self::track_partial_post( $job, $post_id );
			self::untrack_succeeded_post( $job, $post_id );
			self::pin_job_for_elementor_post( $job, $post_id, $lang );
			self::enqueue_job_retry( $job, $post_id );
			self::sync_job_remaining( $job, $lang );
			self::save_job( $job );

			return $job;
		}

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

		if (
			Post_Translator::uses_elementor_builder( $post_id )
			&& Post_Translator::maybe_force_finalize_elementor_tail_in_pipeline( $post_id, $lang, 'pipeline-bulk-success' )
		) {
			Post_Translator::flush_translation_status_cache( $post_id );
			clean_post_cache( $post_id );
		}

		$status = self::resolve_post_job_status( $post_id, $lang );
		$already_counted = self::job_already_counted_success( $job, $post_id );
		$gaps            = Post_Translator::get_translation_gaps( $post_id, $lang );
		$elementor_done  = Post_Translator::is_elementor_translation_finalized( $post_id, $lang )
			&& ! Post_Translator::elementor_job_has_remaining_payload( $post_id, $lang );

		if ( 'translated' === $status ) {
			self::track_succeeded_post( $job, $post_id );
			self::clear_partial_post( $job, $post_id );
		} else {
			self::untrack_succeeded_post( $job, $post_id );
			self::track_partial_post( $job, $post_id );

			$partial_state    = Post_Translator::get_job_partial_state( $post_id, $lang );
			$gap_fill_pending = ! empty( $partial_state['elementor_gap_fill'] )
				|| ! empty( $partial_state['elementor_gap_fill_stubborn_only'] );

			$elementor_needs_continuation = $gap_fill_pending
				|| Post_Translator::post_needs_elementor_job_work( $post_id, $lang )
				|| Post_Translator::elementor_job_has_remaining_payload( $post_id, $lang )
				|| Post_Translator::elementor_job_api_slices_pending( $post_id, $lang )
				|| Post_Translator::should_run_elementor_job_slice( $post_id, $lang );

			if ( $elementor_needs_continuation ) {
				self::pin_job_for_elementor_post( $job, $post_id, $lang );

				self::log(
					'info',
					sprintf(
						/* translators: 1: post title, 2: progress marker */
						__( '«%1$s» — Elementor هنوز ناقص است؛ ادامه همان مورد (%2$s) تا تکمیل persist و gap-fill.', 'polymart-ai' ),
						$title_source,
						'' !== (string) ( $job['partial_progress'] ?? '' ) ? (string) $job['partial_progress'] : '…'
					),
					array( 'post_id' => $post_id, 'lang' => $lang )
				);
			} elseif ( $elementor_done ) {
				$job['partial_post_id']  = null;
				$job['partial_phase']    = null;
				$job['partial_progress'] = null;
				unset( $job['step_partial'] );

				self::log(
					'info',
					sprintf(
						/* translators: 1: post title */
						__( '«%1$s» — Elementor به‌طور کامل ذخیره شد؛ ادامه با مورد بعدی صف.', 'polymart-ai' ),
						$title_source
					),
					array( 'post_id' => $post_id, 'lang' => $lang )
				);
			} else {
				self::defer_job_post( $job, $post_id, $lang );

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

}
