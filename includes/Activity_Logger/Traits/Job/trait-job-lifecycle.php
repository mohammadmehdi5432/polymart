<?php
/**
 * Activity_Logger Job_Lifecycle (auto-split).
 *
 * @package PolymartAI\Activity_Logger\Traits
 */

namespace PolymartAI\Activity_Logger\Traits\Job;

use PolymartAI\Activity_Logger\Job_Action_Scheduler;
use PolymartAI\Activity_Logger\Metabox_Action_Scheduler;
use PolymartAI\Activity_Logger\Translation_Scheduler_Coordinator;
use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\Content\Menu_Translator;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Pipeline\Translation_Query;


defined( 'ABSPATH' ) || exit;

trait Trait_Job_Lifecycle {

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

	public static function get_job( $deep_sync = false ) {
		$job = get_option( self::JOB_OPTION, array() );

		if ( ! is_array( $job ) || empty( $job ) ) {
			return self::empty_job();
		}

		$job = wp_parse_args( $job, self::empty_job() );

		if ( 'running' === ( $job['status'] ?? '' ) && ! self::$job_poll_request ) {
			Job_Action_Scheduler::maybe_heal_ghost_actions_on_poll();

			if ( ! self::get_next_worker_cron() ) {
				self::schedule_background_worker();
			}
		}

		return self::normalize_job_for_response( $job, $deep_sync );
	}

	public static function mark_job_poll_request() {
		self::$job_poll_request = true;
	}

	public static function is_job_poll_request() {
		return self::$job_poll_request;
	}

	public static function get_job_for_poll() {
		self::mark_job_poll_request();

		try {
			return self::get_job( false );
		} finally {
			self::$job_poll_request = false;
		}
	}

	private static function get_cron_chain_delay_sec() {
		// Allow 0 for immediate follow-up when the host's wp-cron.php spawn works.
		return max( 0, min( 5, (int) apply_filters( 'polymart_ai_job_cron_chain_delay_sec', self::CRON_INTERVAL_SEC ) ) );
	}

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
					$fresh_pin = absint( $fresh['partial_post_id'] );
					$pin_lang  = sanitize_key( (string) ( $job['lang'] ?? $fresh['lang'] ?? '' ) );

					// Do not resurrect Elementor pins after seal/leave-queue cleared them.
					if ( '' === $pin_lang || ! self::elementor_companion_is_bulk_complete( $fresh_pin, $pin_lang ) ) {
						$job['partial_post_id'] = $fresh_pin;
					}
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

		Translation_Scheduler_Coordinator::clear_halt();

		// Fast AS purge only — do NOT page Metabox failed/canceled rows here (that hung Start after Stop).
		if ( Job_Action_Scheduler::is_available() ) {
			Job_Action_Scheduler::cancel_all();
			Job_Action_Scheduler::clear_slice_mutex();
		}

		if ( function_exists( 'as_unschedule_all_actions' ) && class_exists( '\PolymartAI\Activity_Logger\Metabox_Action_Scheduler' ) ) {
			as_unschedule_all_actions( Metabox_Action_Scheduler::HOOK, array(), Metabox_Action_Scheduler::GROUP );
			as_unschedule_all_actions( Metabox_Action_Scheduler::HOOK, null, Metabox_Action_Scheduler::GROUP );
		}

		Translation_Scheduler_Coordinator::try_claim_global_worker( Translation_Scheduler_Coordinator::OWNER_BULK );

		$existing        = wp_parse_args( self::get_job_raw(), self::empty_job() );
		$existing_status = (string) ( $existing['status'] ?? 'idle' );

		if ( in_array( $existing_status, array( 'running', 'paused' ), true ) ) {
			if ( 'running' === $existing_status && self::is_bulk_worker_lively( 180 ) ) {
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

		// Ultra-light Start: index stats + SQL seed only.
		// Do NOT call collect_storefront_translation_issues / post_needs_translation_work
		// across hundreds of Elementor posts — that was the «در حال شروع…» hang.
		$front      = absint( get_option( 'page_on_front' ) );
		$stats      = Translation_Query::compute_translation_stats( $lang, false );
		$menu_needs = Menu_Translator::count_untranslated( $lang );
		$needs_work = (int) $stats['untranslated'] + (int) $stats['partial'] + $menu_needs;
		$total      = $needs_work;
		$issues     = array();
		$issue_ids  = array();
		$probe_ids  = array();

		if ( $front > 0 ) {
			$front_status = (string) get_post_meta( $front, Post_Translator::get_status_index_meta_key( $lang ), true );

			if ( 'translated' !== $front_status ) {
				$probe_ids[] = $front;
				$needs_work  = max( $needs_work, 1 );
				$total       = max( $total, $needs_work );
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

			if ( $probe_post > 0 ) {
				$probe_ids[] = $probe_post;
			}

			$needs_work = max( 1, $menu_needs, count( $probe_ids ) );
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

		// Prefer index status for homepage — avoid Elementor decode on Start.
		if ( $front > 0 ) {
			$front_status = (string) get_post_meta( $front, Post_Translator::get_status_index_meta_key( $lang ), true );

			if ( 'translated' !== $front_status ) {
				$seed_ids = array_values(
					array_unique(
						array_merge( array( $front ), $seed_ids )
					)
				);
				$needs_work = max( $needs_work, 1 );
				$total      = max( $total, $needs_work );
			}
		}

		if ( empty( $seed_ids ) && $menu_needs <= 0 ) {
			$seed_ids = self::scan_start_seed_post_ids( $lang, min( 8, max( 3, $needs_work ) ) );
		}

		if ( empty( $seed_ids ) && $menu_needs <= 0 ) {
			$indexed_work = (int) $stats['untranslated'] + (int) $stats['partial'];

			if ( $indexed_work > 0 ) {
				$seed_ids = Translation_Query::seed_actionable_post_ids(
					$lang,
					min( 8, max( 1, $indexed_work ) )
				);
			}
		}

		if ( empty( $seed_ids ) && $menu_needs <= 0 ) {
			return new \WP_Error(
				'polymart_ai_no_queue',
				self::format_no_queue_diagnostic( $lang, $issues ),
				array( 'issues' => $issues )
			);
		}

		if ( ! empty( $seed_ids ) ) {
			$needs_work = max( $needs_work, count( $seed_ids ), 1 );
			$total      = max( $total, $needs_work );
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
		// Run the first tick inline so progress does not wait for SPA poll / cron.
		self::bootstrap_background_worker( true );
		self::spawn_job_loopback( false );
		self::ping_wp_cron( true );
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
			// Kill pending/running AS rows immediately — otherwise zombies keep chaining.
			self::unschedule_background_worker();
			Translation_Scheduler_Coordinator::cancel_all_plugin_actions();
			self::log( 'warning', __( 'ترجمه خودکار متوقف موقت شد.', 'polymart-ai' ) );
		}

		return self::normalize_job_for_response( $job, false );
	}

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
			self::bootstrap_background_worker( true );
			self::spawn_job_loopback( false );
			self::ping_wp_cron( true );
			self::log( 'info', __( 'ترجمه خودکار از سر گرفته شد (Action Scheduler).', 'polymart-ai' ) );
		}

		return self::normalize_job_for_response( self::get_job_raw(), false );
	}

	public static function stop_job() {
		$job = self::get_job_raw();

		// Nuclear stop: purge bulk + metabox AS rows and block zombie enqueue/recovery.
		Translation_Scheduler_Coordinator::halt_all_schedulers();

		// Always tear down workers/locks first so Start cannot hit a stale "running" worker.
		self::unschedule_background_worker();
		self::release_step_lock();
		Translation_Scheduler_Coordinator::release_global_worker( null );

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
		$cleared['api_cooldown_until'] = null;

		$result = self::save_job( $cleared );

		// Keep halt armed until the next intentional start_job()/metabox start.
		// Clearing halt here let zombie AS ticks re-enqueue after Stop.
		Translation_Scheduler_Coordinator::cancel_all_plugin_actions();

		// Race-guard: an in-flight AS tick may have re-saved "running" after our empty_job.
		$again = self::get_job_raw();
		if ( is_array( $again ) && 'running' === ( $again['status'] ?? '' ) ) {
			$cleared['updated_at'] = time();
			$result = self::save_job( $cleared );
			Translation_Scheduler_Coordinator::cancel_all_plugin_actions();
		}

		return $result;
	}

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

		// Drop every in-flight AS row for this (and any) post so the skipped item
		// cannot keep consuming ticks for another 10–15 minutes.
		Job_Action_Scheduler::cancel_all();
		Job_Action_Scheduler::clear_slice_mutex();

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
	 * Pin a remaining post as the next / current Elementor target for the bulk job.
	 *
	 * Used by Auto-Translate UI «الان این را ترجمه کن» so the user can jump
	 * between backlog pages without waiting for the natural queue order.
	 *
	 * @param int $post_id Post ID to focus.
	 * @return array|\WP_Error Normalized job or error.
	 */
	public static function focus_job_on_post( $post_id ) {
		$job     = self::get_job_raw();
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
		$status  = (string) ( $job['status'] ?? 'idle' );

		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'polymart_ai_no_focus_target',
				__( 'موردی برای اولویت‌بندی مشخص نیست.', 'polymart-ai' )
			);
		}

		if ( ! in_array( $status, array( 'running', 'paused' ), true ) ) {
			return new \WP_Error(
				'polymart_ai_focus_needs_job',
				__( 'ابتدا ترجمه خودکار را Start یا Resume کنید، سپس صفحه را اولویت دهید.', 'polymart-ai' )
			);
		}

		if ( '' === $lang ) {
			$lang = 'en';
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'polymart_ai_invalid_focus_post',
				__( 'پست انتخاب‌شده معتبر نیست.', 'polymart-ai' )
			);
		}

		// Pull out of Skip / park lists so the picker can see it again.
		$exhausted = self::get_exhausted_job_post_ids( $job );
		$job['exhausted_ids'] = array_values(
			array_filter(
				$exhausted,
				static function ( $id ) use ( $post_id ) {
					return (int) $id !== $post_id;
				}
			)
		);

		$job['parked_ids'] = array_values(
			array_filter(
				self::get_parked_ids( $job ),
				static function ( $id ) use ( $post_id ) {
					return (int) $id !== $post_id;
				}
			)
		);

		$prev_partial = absint( $job['partial_post_id'] ?? 0 );
		$prev_current = absint( $job['current_post_id'] ?? 0 );

		foreach ( array_unique( array_filter( array( $prev_partial, $prev_current ) ) ) as $locked_id ) {
			if ( $locked_id !== $post_id && '' !== $lang ) {
				Post_Translator::release_translation_lock( $locked_id, $lang, true );
			}
		}

		self::release_step_lock();
		self::remove_post_from_deferred_queue( $job, $post_id );

		$deferred   = self::get_deferred_queue( $job );
		array_unshift( $deferred, $post_id );
		$job['deferred_queue'] = array_values( array_unique( array_map( 'absint', $deferred ) ) );

		$job['current_post_id']        = null;
		$job['step_started_at']        = null;
		$job['consecutive_post_id']    = 0;
		$job['consecutive_post_steps'] = 0;
		$job['stuck_progress_steps']   = 0;
		$job['last_error']             = null;
		$job['pause_reason']           = null;
		$job['step_deferred']          = false;
		$job['step_deferred_reason']   = null;

		if ( Post_Translator::uses_elementor_builder( $post_id ) ) {
			self::pin_job_for_elementor_post( $job, $post_id, $lang );
		} else {
			$job['partial_post_id']  = $post_id;
			$job['partial_phase']    = 'core';
			$job['partial_progress'] = null;
		}

		self::set_job_last_step(
			$job,
			$post_id,
			'focused',
			sprintf(
				/* translators: 1: post ID, 2: title */
				__( 'اولویت‌بندی شد — #%1$d «%2$s» بعدی ترجمه می‌شود.', 'polymart-ai' ),
				$post_id,
				get_the_title( $post_id ) ?: '#' . $post_id
			)
		);

		self::sync_job_remaining( $job, $lang );
		self::save_job( $job );

		// Drop zombie AS for previous pin and spawn a fresh tick for the focused post.
		Job_Action_Scheduler::cancel_all();
		Job_Action_Scheduler::clear_slice_mutex();

		if ( 'running' === ( $job['status'] ?? '' ) ) {
			self::schedule_background_worker();
		}

		self::log(
			'info',
			sprintf(
				/* translators: 1: post ID, 2: title */
				__( 'اولویت کاربر: #%1$d «%2$s» به سر صف ترجمه منتقل شد.', 'polymart-ai' ),
				$post_id,
				get_the_title( $post_id ) ?: '#' . $post_id
			),
			array( 'post_id' => $post_id, 'lang' => $lang )
		);

		return self::normalize_job_for_response( $job, false );
	}

	/**
	 * Whether a post was Skip'd (in exhausted_ids) for the active bulk job.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_job_post_skipped( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return false;
		}

		$job       = self::get_job_raw();
		$exhausted = is_array( $job['exhausted_ids'] ?? null ) ? array_map( 'absint', $job['exhausted_ids'] ) : array();

		return in_array( $post_id, $exhausted, true );
	}

	/**
	 * Whether Stop pressed (halt armed). Safe for metabox + bulk.
	 *
	 * @return bool
	 */
	public static function should_abort_background_work() {
		return Translation_Scheduler_Coordinator::is_halted();
	}

	/**
	 * Whether the bulk job is explicitly paused (not idle / stopped).
	 *
	 * @return bool
	 */
	public static function is_bulk_job_paused() {
		$job = get_option( self::JOB_OPTION, array() );

		return is_array( $job ) && 'paused' === (string) ( $job['status'] ?? '' );
	}

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

	public static function is_bulk_job_running() {
		$job = get_option( self::JOB_OPTION, array() );

		return is_array( $job ) && 'running' === ( $job['status'] ?? '' );
	}

	/**
	 * Repair stale Elementor completion meta for posts the start probe flagged.
	 *
	 * @param string $lang     Target language.
	 * @param int[]  $post_ids Candidate post IDs.
	 * @return void
	 */
	private static function reconcile_start_probe_posts( $lang, array $post_ids ) {
		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang || empty( $post_ids ) ) {
			return;
		}

		foreach ( array_slice( array_values( array_unique( array_map( 'absint', $post_ids ) ) ), 0, 12 ) as $post_id ) {
			if ( $post_id <= 0 ) {
				continue;
			}

			// Huge Elementor trees (#21870) — full repair at Start timed out / 503'd the REST call.
			// Leave repair to AS worker ticks; Start must return quickly.
			$elementor_bytes = strlen( (string) get_post_meta( $post_id, '_elementor_data', true ) );

			if ( $elementor_bytes > 350000 ) {
				continue;
			}

			try {
				Post_Translator::repair_stale_elementor_completion_meta( $post_id, $lang );
				Post_Translator::repair_completed_elementor_job_meta( $post_id, $lang );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Never let probe repair kill start_job().
			}
		}
	}

	/**
	 * Last-resort scan when indexed seeding returns nothing but the site still has work.
	 *
	 * @param string $lang  Target language.
	 * @param int    $limit Maximum IDs.
	 * @return int[]
	 */
	private static function scan_start_seed_post_ids( $lang, $limit = 8 ) {
		$lang   = sanitize_key( (string) $lang );
		$limit  = max( 1, min( 12, absint( $limit ) ) );
		$found  = array();
		$cursor = 0;

		for ( $attempt = 0; $attempt < 16 && count( $found ) < $limit; $attempt++ ) {
			$next_id = Translation_Query::find_next_actionable_post_id( $lang, $cursor );

			if ( $next_id <= 0 ) {
				break;
			}

			$found[] = $next_id;
			$cursor  = $next_id;
		}

		return array_values( array_unique( array_map( 'absint', $found ) ) );
	}

}
