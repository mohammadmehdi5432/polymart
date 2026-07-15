<?php
/**
 * Activity_Logger Job Queue_Metrics (auto-split).
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

trait Trait_Queue_Metrics {

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

	private static function normalize_job_for_lightweight_response( array $job, $lang ) {
		if ( ! isset( $job['remaining'] ) ) {
			$job['remaining'] = max( 0, (int) ( $job['total'] ?? 0 ) - (int) ( $job['succeeded'] ?? 0 ) );
		}

		$job['remaining'] = max( 0, (int) $job['remaining'] );
		$job              = self::refresh_elementor_job_progress_snapshot( $job );

		if ( ! isset( $job['needs_work'] ) && isset( $job['live_stats'] ) && is_array( $job['live_stats'] ) ) {
			$job['needs_work'] = (int) ( $job['live_stats']['untranslated'] ?? 0 ) + (int) ( $job['live_stats']['partial'] ?? 0 );
		}

		$job = self::attach_job_metrics( $job, $lang, false );

		if ( '' !== $lang && 'paused' === ( $job['status'] ?? '' ) && ! self::is_job_poll_request() ) {
			$job = self::attach_stalled_job_details( $job, $lang );
		}

		return $job;
	}

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

			$job_lang = sanitize_key( (string) ( $job['lang'] ?? '' ) );
			if ( '' !== $job_lang ) {
				$stubborn = Post_Translator::get_elementor_stubborn_field_diagnostics( $current_post_id, $job_lang );
				if ( ! empty( $stubborn['fields'] ) ) {
					$job['current_post']['stubborn_count'] = (int) ( $stubborn['remaining'] ?? count( $stubborn['fields'] ) );
					$job['current_post']['stubborn_fields'] = array_slice( $stubborn['fields'], 0, 5 );
					$job['current_post']['stubborn_meta_key'] = '_polymart_ai_stubborn_details_' . $job_lang;
				}
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

		$job['cron_disabled']  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$job['cron_pulse']     = (bool) wp_next_scheduled( self::CRON_PULSE_HOOK );
		$job['worker_alive']   = self::is_worker_heartbeat_fresh( $job );

		if ( self::is_job_poll_request() ) {
			return self::attach_job_poll_snapshot_fields( $job, $lang );
		}

		$next_cron = self::get_next_worker_cron();
		$job['cron_scheduled'] = (bool) $next_cron;
		$job['next_cron_at']   = $next_cron ? (int) $next_cron : null;
		$as_status             = Job_Action_Scheduler::status_payload();
		$job['as_available']   = ! empty( $as_status['available'] );
		$job['as_pending']     = ! empty( $as_status['pending'] );
		$job['as_running']     = ! empty( $as_status['running'] );
		$job['as_slice_active'] = ! empty( $as_status['slice_active'] );
		$job['worker_mode']    = ! empty( $as_status['available'] ) ? 'as' : 'cron';
		$job['elementor_priority'] = self::should_prioritize_elementor_partial( $job );
		$job['elementor_progress_stalled'] = self::is_elementor_progress_stalled( $job );
		$gap_post_id = absint( $job['partial_post_id'] ?? 0 ) ?: absint( $job['current_post_id'] ?? 0 );
		$job['elementor_gap_fill_pending'] = self::resolve_elementor_gap_fill_pending_flag( $job, $lang, $gap_post_id );
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

	private static function attach_job_poll_snapshot_fields( array $job, $lang ) {
		$gap_post_id = absint( $job['partial_post_id'] ?? 0 ) ?: absint( $job['current_post_id'] ?? 0 );
		$job         = self::refresh_elementor_job_progress_snapshot( $job, false );

		$job['as_available']               = array_key_exists( 'as_available', $job )
			? (bool) $job['as_available']
			: Job_Action_Scheduler::is_available();
		$job['as_pending']                 = (bool) ( $job['as_pending'] ?? false );
		$job['as_running']                 = (bool) ( $job['as_running'] ?? false );
		$job['as_slice_active']            = (bool) ( $job['as_slice_active'] ?? false );
		$job['worker_mode']                = (string) ( $job['worker_mode'] ?? 'as' );
		$job['elementor_priority']         = (bool) ( $job['elementor_priority'] ?? ( 'elementor' === sanitize_key( (string) ( $job['partial_phase'] ?? '' ) ) ) );
		$job['elementor_progress_stalled'] = (bool) ( $job['elementor_progress_stalled'] ?? false );
		$job['elementor_gap_fill_pending'] = self::resolve_elementor_gap_fill_pending_flag( $job, $lang, $gap_post_id );
		$job['recent_logs']                = array();
		$job['poll_snapshot']              = true;
		$job['cli_alive']                  = false;
		$job['cli_spawn_ok']               = false;
		$job['cron_disabled']              = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		if ( ! isset( $job['cron_scheduled'] ) ) {
			$next_cron             = self::get_next_worker_cron();
			$job['cron_scheduled'] = (bool) $next_cron;
			$job['next_cron_at']   = $next_cron ? (int) $next_cron : null;
		}

		if ( ! isset( $job['cron_pulse'] ) ) {
			$job['cron_pulse'] = (bool) wp_next_scheduled( self::CRON_PULSE_HOOK );
		}

		if ( ! isset( $job['worker_alive'] ) ) {
			$job['worker_alive'] = self::is_worker_heartbeat_fresh( $job );
		}

		$cooldown_remaining = self::get_job_api_cooldown_remaining( $job );
		if ( $cooldown_remaining > 0 ) {
			$job['api_cooldown_remaining'] = $cooldown_remaining;
			$job                           = self::apply_api_cooldown_job_presentation( $job, $cooldown_remaining );
		}

		return $job;
	}

	private static function resolve_elementor_gap_fill_pending_flag( array $job, $lang, $gap_post_id ) {
		$lang        = sanitize_key( (string) $lang );
		$gap_post_id = absint( $gap_post_id );

		if ( $gap_post_id <= 0 || '' === $lang ) {
			return false;
		}

		if ( self::is_job_poll_request() ) {
			if ( Post_Translator::is_elementor_translation_finalized( $gap_post_id, $lang ) ) {
				return false;
			}

			$state = Post_Translator::get_job_partial_state( $gap_post_id, $lang );

			if ( ! empty( $state['elementor_gap_fill'] ) || ! empty( $state['elementor_gap_fill_stubborn_only'] ) ) {
				return true;
			}

			$parsed = self::parse_job_phase_progress( (string) ( $job['partial_progress'] ?? '' ) );

			if (
				is_array( $parsed )
				&& $parsed['total'] > 0
				&& $parsed['done'] >= $parsed['total']
				&& Post_Translator::elementor_job_primary_batches_exhausted( $gap_post_id, $lang )
			) {
				return true;
			}

			return false;
		}

		return Post_Translator::elementor_needs_gap_fill_work( $gap_post_id, $lang );
	}

	private static function get_recent_job_logs( array $job, $limit = 60 ) {
		$limit   = max( 1, min( 100, absint( $limit ) ) );

		if ( self::is_job_poll_request() ) {
			$limit = min( $limit, 25 );
		}

		$started = absint( $job['started_at'] ?? 0 );
		$logs    = self::get_logs( self::is_job_poll_request() ? 80 : 200 );
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
	 * Seconds since the bulk worker last made real progress.
	 *
	 * @return int
	 */
	public static function get_bulk_worker_activity_age() {
		if ( ! self::is_bulk_job_running() ) {
			return 0;
		}

		$job  = self::get_job_raw();
		$last = self::get_worker_real_activity_at( $job );

		return $last > 0 ? max( 0, time() - $last ) : PHP_INT_MAX;
	}

	private static function is_worker_heartbeat_fresh( array $job ) {
		$last = self::get_worker_real_activity_at( $job );

		if ( $last <= 0 ) {
			return false;
		}

		return ( time() - $last ) < self::LOCK_IDLE_ORPHAN_SEC;
	}

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

	private static function parse_job_phase_progress( $progress ) {
		$progress = trim( (string) $progress );

		if ( ! preg_match( '/^(\d+)\/(\d+)/', $progress, $matches ) ) {
			return null;
		}

		return array(
			'done'  => absint( $matches[1] ),
			'total' => absint( $matches[2] ),
		);
	}

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

	private static function job_slice_made_progress( array $job, $current_progress ) {
		$previous = (string) ( $job['partial_progress'] ?? '' );
		$current  = (string) $current_progress;

		return '' !== $current && $current !== $previous;
	}

	private static function job_progress_stuck_should_defer( array &$job, $post_id, $current_progress ) {
		if ( self::job_slice_made_progress( $job, $current_progress ) ) {
			$job['stuck_progress_steps'] = 0;

			return false;
		}

		$job['stuck_progress_steps'] = absint( $job['stuck_progress_steps'] ?? 0 ) + 1;

		$limit = (int) apply_filters( 'polymart_ai_job_stuck_progress_limit', 6, $job, $post_id );

		return absint( $job['stuck_progress_steps'] ?? 0 ) >= max( 3, $limit );
	}

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

	private static function sync_job_remaining( array &$job, $lang, $force = false ) {
		self::sync_job_live_stats( $job, $lang, $force );
		$job['remaining_synced_at'] = time();
	}

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

	private static function increment_job_step( array &$job ) {
		$job['steps']     = (int) ( $job['steps'] ?? $job['processed'] ?? 0 ) + 1;
		$job['processed'] = $job['steps'];
	}

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

	private static function resolve_post_job_status( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		Post_Translator::flush_translation_status_cache( $post_id );

		$status = Post_Translator::get_translation_status( $post_id, $lang );

		if ( 'translated' !== $status ) {
			return $status;
		}

		if ( Post_Translator::uses_elementor_builder( $post_id ) ) {
			Post_Translator::bind_elementor_accepted_paths_context( $post_id, $lang );

			// EN JSON with Persian is never "translated" — even if meta says finalized
			// and remaining_payload is empty after accepted fallbacks (#1021 storefront bug).
			if (
				Post_Translator::stored_elementor_translation_has_persian( $post_id, $lang )
				|| Post_Translator::storefront_would_show_persian_source( $post_id, $lang )
				|| Post_Translator::elementor_needs_gap_fill_work( $post_id, $lang )
				|| Post_Translator::elementor_job_has_stubborn_remaining( $post_id, $lang )
				|| Post_Translator::elementor_job_has_remaining_payload( $post_id, $lang )
			) {
				return 'partial';
			}

			if (
				Post_Translator::is_elementor_translation_finalized( $post_id, $lang )
				&& Post_Translator::elementor_translation_is_storefront_ready( $post_id, $lang )
				&& ! Post_Translator::elementor_job_has_remaining_payload( $post_id, $lang )
			) {
				return 'translated';
			}
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

	private static function track_succeeded_post( array &$job, $post_id ) {
		$post_id = absint( $post_id );
		$ids     = is_array( $job['succeeded_ids'] ?? null ) ? $job['succeeded_ids'] : array();

		if ( $post_id > 0 && ! in_array( $post_id, $ids, true ) ) {
			$ids[] = $post_id;
		}

		$job['succeeded_ids'] = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$job['succeeded']     = count( $job['succeeded_ids'] );
	}

	private static function job_already_counted_success( array $job, $post_id ) {
		$post_id = absint( $post_id );
		$ids     = is_array( $job['succeeded_ids'] ?? null ) ? $job['succeeded_ids'] : array();

		return $post_id > 0 && in_array( $post_id, array_map( 'absint', $ids ), true );
	}

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

			if ( 'translated' === self::resolve_post_job_status( $post_id, $lang )
				&& ! Post_Translator::post_needs_translation_work( $post_id, $lang )
			) {
				$still_ok[] = $post_id;
			} else {
				self::track_partial_post( $job, $post_id );
			}
		}

		$job['succeeded_ids'] = array_values( array_unique( $still_ok ) );
		$job['succeeded']     = count( $job['succeeded_ids'] );
	}

	private static function track_partial_post( array &$job, $post_id ) {
		$post_id     = absint( $post_id );
		$partial_ids = is_array( $job['partial_ids'] ?? null ) ? $job['partial_ids'] : array();

		if ( $post_id > 0 && ! in_array( $post_id, $partial_ids, true ) ) {
			$partial_ids[] = $post_id;
		}

		$job['partial_ids'] = $partial_ids;
		$job['partial']     = count( $partial_ids );
	}

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

	private static function get_job_raw() {
		$job = get_option( self::JOB_OPTION, array() );

		if ( ! is_array( $job ) || empty( $job ) ) {
			return self::empty_job();
		}

		return wp_parse_args( $job, self::empty_job() );
	}

}
