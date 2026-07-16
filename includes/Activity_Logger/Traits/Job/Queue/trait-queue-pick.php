<?php
/**
 * Activity_Logger Job Queue_Pick (auto-split).
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

trait Trait_Queue_Pick {

	private static function pick_next_job_post_id( array &$job, $lang, $cursor ) {
		$lang       = sanitize_key( (string) $lang );
		$partial_id = absint( $job['partial_post_id'] ?? 0 );

		if ( $partial_id > 0 && self::job_has_active_elementor_pin( $job, $lang ) ) {
			if ( ! in_array( $partial_id, self::get_parked_ids( $job ), true ) ) {
				return array(
					'post_id'    => $partial_id,
					'from_retry' => true,
				);
			}
		}

		if ( $partial_id > 0 && self::elementor_partial_has_remaining( $job ) ) {
			if ( ! in_array( $partial_id, self::get_parked_ids( $job ), true ) ) {
				return array(
					'post_id'    => $partial_id,
					'from_retry' => true,
				);
			}
		}

		$exclude_ids = self::get_forward_scan_exclude_ids( $job );

		self::touch_worker_heartbeat();
		$deferred_queue = self::get_actionable_deferred_queue( $job, $lang );
		self::touch_worker_heartbeat();

		if ( ! empty( $deferred_queue ) ) {
			$post_id               = (int) array_shift( $deferred_queue );
			$job['deferred_queue'] = $deferred_queue;

			return array(
				'post_id'    => $post_id,
				'from_retry' => true,
			);
		}

		// Drop stale deferred seeds that failed the actionable filter so they cannot pin the picker.
		if ( ! empty( self::get_deferred_queue( $job ) ) ) {
			$job['deferred_queue'] = array();
		}

		$exhausted_ids = self::get_exhausted_job_post_ids( $job );
		$retry_queue   = array_values(
			array_filter(
				self::get_retry_queue( $job ),
				static function ( $queued_id ) use ( $exhausted_ids ) {
					return ! in_array( (int) $queued_id, $exhausted_ids, true );
				}
			)
		);

		if ( ! empty( $retry_queue ) ) {
			$post_id            = (int) array_shift( $retry_queue );
			$job['retry_queue'] = $retry_queue;

			return array(
				'post_id'    => $post_id,
				'from_retry' => true,
			);
		}

		$parked = self::get_parked_ids( $job );

		if ( ! empty( $parked ) ) {
			$post_id = (int) array_shift( $parked );
			self::remove_post_from_deferred_queue( $job, $post_id );
			$job['parked_ids'] = $parked;

			return array(
				'post_id'    => $post_id,
				'from_retry' => true,
			);
		}

		self::touch_worker_heartbeat();
		$post_id = Translation_Query::find_next_actionable_post_id( $lang, $cursor, $exclude_ids );
		self::touch_worker_heartbeat();

		if ( ! $post_id && $cursor > 0 ) {
			self::touch_worker_heartbeat();
			$post_id = Translation_Query::find_next_actionable_post_id( $lang, 0, $exclude_ids );
			self::touch_worker_heartbeat();
		}

		if ( ! $post_id ) {
			self::touch_worker_heartbeat();
			$post_id = Translation_Query::find_next_untranslated_post_id( $lang, $cursor, $exclude_ids );
			self::touch_worker_heartbeat();

			if ( ! $post_id && $cursor > 0 ) {
				$post_id = Translation_Query::find_next_untranslated_post_id( $lang, 0, $exclude_ids );
				self::touch_worker_heartbeat();
			}
		}

		if ( $post_id ) {
			return array(
				'post_id'    => $post_id,
				'from_retry' => false,
			);
		}

		self::touch_worker_heartbeat();
		$actionable = self::get_actionable_remaining_ids( $lang, $job, 10 );
		self::touch_worker_heartbeat();

		if ( ! empty( $actionable ) ) {
			$post_id               = (int) $actionable[0];
			$job['deferred_queue'] = array_slice( $actionable, 1 );
			$job['last_post_id']   = 0;

			return array(
				'post_id'    => $post_id,
				'from_retry' => true,
			);
		}

		return array(
			'post_id'    => 0,
			'from_retry' => false,
		);
	}

	private static function recover_stalled_job_picker( array &$job, $lang, $log = false ) {
		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			return false;
		}

		$changed               = false;
		$prioritize_elementor  = self::should_prioritize_elementor_partial( $job );

		if ( get_transient( self::STEP_LOCK_KEY ) || get_option( self::STEP_LOCK_CLAIM_KEY ) ) {
			self::release_step_lock();
			$changed = true;
		}

		if ( absint( $job['current_post_id'] ?? 0 ) > 0 || ! empty( $job['step_started_at'] ) || ! empty( $job['pick_started_at'] ) ) {
			$job['current_post_id'] = null;
			$job['step_started_at']  = null;
			$job['pick_started_at']  = null;
			$changed                 = true;
		}

		if ( $prioritize_elementor ) {
			if ( in_array( (string) ( $job['pause_reason'] ?? '' ), array( 'pick_stalled', 'stalled' ), true ) ) {
				$job['status']       = 'running';
				$job['pause_reason'] = null;
				$job['last_error']   = null;
				$changed             = true;
			}

			if ( $log && $changed ) {
				self::log(
					'warning',
					__( 'قفل کارگر آزاد شد — ادامه ترجمه Elementor در همان مورد.', 'polymart-ai' )
				);
			}

			return $changed;
		}

		$parked = self::get_parked_ids( $job );
		$unpark = array();

		foreach ( $parked as $post_id ) {
			$post_id = absint( $post_id );

			if ( $post_id <= 0 ) {
				continue;
			}

			if ( 'translated' !== Post_Translator::get_translation_status( $post_id, $lang ) ) {
				$unpark[] = $post_id;
			}
		}

		if ( ! empty( $unpark ) ) {
			$job['parked_ids'] = array_values( array_diff( $parked, $unpark ) );
			$job['deferred_queue'] = array_values(
				array_unique(
					array_merge( self::get_deferred_queue( $job ), $unpark )
				)
			);
			$changed = true;
		}

		$needs      = max( 1, (int) ( $job['needs_work'] ?? $job['remaining'] ?? 5 ) );
		$seed_limit = min( 25, max( 5, $needs + 3 ) );
		$seed_ids   = Translation_Query::seed_actionable_post_ids(
			$lang,
			$seed_limit,
			self::get_exhausted_job_post_ids( $job )
		);

		if ( ! empty( $seed_ids ) ) {
			$job['deferred_queue'] = array_values(
				array_unique(
					array_merge( self::get_deferred_queue( $job ), array_map( 'absint', $seed_ids ) )
				)
			);
			$job['last_post_id'] = 0;
			$changed             = true;
		}

		$menu_left = Menu_Translator::count_untranslated( $lang );

		if ( $menu_left > 0 && empty( self::get_actionable_deferred_queue( $job, $lang ) ) ) {
			$job['phase']        = 'menus';
			$job['last_menu_id'] = 0;
			$changed             = true;
		} elseif ( 'menus' !== ( $job['phase'] ?? '' ) || empty( self::get_deferred_queue( $job ) ) ) {
			if ( $menu_left <= 0 ) {
				$job['phase'] = 'posts';
			}
		}

		if ( in_array( (string) ( $job['pause_reason'] ?? '' ), array( 'pick_stalled', 'stalled' ), true ) ) {
			$job['status']       = 'running';
			$job['pause_reason'] = null;
			$job['last_error']   = null;
			$changed             = true;
		}

		if ( $log && $changed ) {
			self::log(
				'warning',
				__( 'صف گیرکرده بازیابی شد — انتخاب مورد بعدی و منوها دوباره ادامه می‌یابد.', 'polymart-ai' )
			);
		}

		return $changed;
	}

	private static function get_forward_scan_exclude_ids( array $job ) {
		// Only exclude IDs we are actively holding. Do NOT exclude the full deferred_queue —
		// non-actionable seeds left in deferred were blocking find_next forever (empty picker).
		$partial = absint( $job['partial_post_id'] ?? 0 );

		return array_values(
			array_unique(
				array_filter(
					array_merge(
						self::get_exhausted_job_post_ids( $job ),
						self::get_parked_ids( $job ),
						$partial > 0 ? array( $partial ) : array(),
						array_map( 'absint', is_array( $job['succeeded_ids'] ?? null ) ? $job['succeeded_ids'] : array() )
					)
				)
			)
		);
	}

	private static function reconcile_stuck_job_queues( array &$job, $lang ) {
		$lang = sanitize_key( (string) $lang );

		$deferred = self::get_deferred_queue( $job );
		$rounds   = is_array( $job['defer_rounds'] ?? null ) ? $job['defer_rounds'] : array();

		foreach ( $deferred as $post_id ) {
			$post_id = absint( $post_id );

			if ( $post_id <= 0 ) {
				continue;
			}

			$max_rounds = (int) apply_filters( 'polymart_ai_job_max_defer_rounds_per_post', 10, $job, $post_id );

			if ( (int) ( $rounds[ $post_id ] ?? 0 ) >= max( 1, $max_rounds ) ) {
				self::park_job_post( $job, $post_id, $lang );
			}
		}

		// Drop translated IDs from auxiliary queues.
		foreach ( array( 'deferred_queue', 'retry_queue', 'parked_ids' ) as $queue_key ) {
			$ids = is_array( $job[ $queue_key ] ?? null ) ? $job[ $queue_key ] : array();

			if ( 'deferred_queue' === $queue_key ) {
				$ids = self::get_deferred_queue( $job );
			} elseif ( 'retry_queue' === $queue_key ) {
				$ids = self::get_retry_queue( $job );
			} else {
				$ids = self::get_parked_ids( $job );
			}

			$job[ $queue_key ] = array_values(
				array_filter(
					array_map( 'absint', $ids ),
					static function ( $post_id ) use ( $lang ) {
						return $post_id > 0 && ( '' === $lang || 'translated' !== Post_Translator::get_translation_status( $post_id, $lang ) );
					}
				)
			);
		}
	}

	private static function get_parked_ids( array $job ) {
		$ids = $job['parked_ids'] ?? array();

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'absint', $ids ),
				static function ( $post_id ) {
					return $post_id > 0;
				}
			)
		);
	}

	private static function get_actionable_deferred_queue( array $job, $lang ) {
		$lang     = sanitize_key( (string) $lang );
		$parked   = self::get_parked_ids( $job );
		$deferred = array();

		foreach ( self::get_deferred_queue( $job ) as $post_id ) {
			$post_id = absint( $post_id );

			if ( $post_id <= 0 || in_array( $post_id, $parked, true ) ) {
				continue;
			}

			if ( '' !== $lang && ! Post_Translator::post_is_actionable_for_job( $post_id, $lang ) ) {
				continue;
			}

			$deferred[] = $post_id;
		}

		return $deferred;
	}

	private static function defer_job_post( array &$job, $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 ) {
			return;
		}

		if (
			absint( $job['partial_post_id'] ?? 0 ) === $post_id
			&& self::job_has_active_elementor_pin( $job, $lang )
		) {
			return;
		}

		$rounds            = is_array( $job['defer_rounds'] ?? null ) ? $job['defer_rounds'] : array();
		$rounds[ $post_id ] = (int) ( $rounds[ $post_id ] ?? 0 ) + 1;
		$job['defer_rounds'] = $rounds;
		$job['last_post_id'] = $post_id;

		$max_rounds = (int) apply_filters( 'polymart_ai_job_max_defer_rounds_per_post', 10, $job, $post_id );

		if ( $rounds[ $post_id ] >= max( 1, $max_rounds ) ) {
			self::park_job_post( $job, $post_id, $lang );

			return;
		}

		self::enqueue_job_defer( $job, $post_id, true );
	}

	private static function rotate_job_post_for_fairness( array &$job, $post_id, $lang, $phase, $progress ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		$job['partial_post_id']        = null;
		$job['partial_phase']          = $phase;
		$job['partial_progress']       = $progress;
		$job['current_post_id']        = null;
		$job['step_started_at']        = null;
		$job['consecutive_post_id']    = 0;
		$job['consecutive_post_steps'] = 0;
		$job['stuck_progress_steps']   = 0;
		$job['last_post_id']           = $post_id;
		$job['step_deferred']          = true;
		$job['step_deferred_reason']   = 'fairness_rotation';

		self::track_partial_post( $job, $post_id );
		self::enqueue_job_defer( $job, $post_id, true );
	}

	private static function park_job_post( array &$job, $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 ) {
			return;
		}

		self::remove_post_from_deferred_queue( $job, $post_id );

		$parked = self::get_parked_ids( $job );

		if ( ! in_array( $post_id, $parked, true ) ) {
			$parked[] = $post_id;
		}

		$job['parked_ids'] = array_values( array_unique( array_map( 'absint', $parked ) ) );

		self::log(
			'warning',
			sprintf(
				/* translators: 1: post ID, 2: post title */
				__( 'مورد #%1$d «%2$s» فعلاً کنار گذاشته شد — بقیه صف ادامه می‌یابد.', 'polymart-ai' ),
				$post_id,
				get_the_title( $post_id ) ?: __( '(بدون عنوان)', 'polymart-ai' )
			),
			array( 'post_id' => $post_id, 'lang' => $lang )
		);
	}

	private static function replenish_deferred_queue_if_low( array &$job, $lang ) {
		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			return;
		}

		$queue = self::get_deferred_queue( $job );

		if ( count( $queue ) >= 8 ) {
			return;
		}

		$seed_limit = min( 10, max( 3, 8 - count( $queue ) ) );
		$new_ids    = Translation_Query::seed_actionable_post_ids(
			$lang,
			$seed_limit,
			self::get_exhausted_job_post_ids( $job )
		);

		if ( empty( $new_ids ) ) {
			return;
		}

		$job['deferred_queue'] = array_values(
			array_unique(
				array_merge( $queue, array_map( 'absint', $new_ids ) )
			)
		);
	}

	private static function get_deferred_queue( array $job ) {
		$queue = $job['deferred_queue'] ?? array();

		if ( ! is_array( $queue ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'absint', $queue ),
				static function ( $post_id ) {
					return $post_id > 0;
				}
			)
		);
	}

	private static function enqueue_job_defer( array &$job, $post_id, $rotate = false ) {
		$post_id = absint( $post_id );
		$queue   = self::get_deferred_queue( $job );

		if ( $post_id <= 0 ) {
			return;
		}

		if ( in_array( $post_id, $queue, true ) ) {
			if ( ! $rotate ) {
				return;
			}

			$queue = array_values(
				array_filter(
					$queue,
					static function ( $queued_id ) use ( $post_id ) {
						return (int) $queued_id !== $post_id;
					}
				)
			);
		}

		$queue[]               = $post_id;
		$job['deferred_queue'] = $queue;
	}

	private static function get_retry_queue( array $job ) {
		$queue = $job['retry_queue'] ?? array();

		if ( ! is_array( $queue ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'absint', $queue ),
				static function ( $post_id ) {
					return $post_id > 0;
				}
			)
		);
	}

	private static function job_has_pending_work( $lang, $cursor, array $retry_queue, array $job = array() ) {
		$exclude_ids = self::get_exhausted_job_post_ids( $job );

		if ( ! empty( self::get_actionable_deferred_queue( $job, $lang ) ) ) {
			return true;
		}

		if ( ! empty( self::get_parked_ids( $job ) ) ) {
			return true;
		}

		foreach ( $retry_queue as $queued_id ) {
			if ( ! in_array( (int) $queued_id, $exclude_ids, true ) ) {
				return true;
			}
		}

		$post_id = Translation_Query::find_next_untranslated_post_id(
			$lang,
			$cursor,
			self::get_forward_scan_exclude_ids( $job )
		);

		if ( $post_id ) {
			return true;
		}

		if ( $cursor > 0 ) {
			return (bool) Translation_Query::find_next_untranslated_post_id(
				$lang,
				0,
				self::get_forward_scan_exclude_ids( $job )
			);
		}

		if ( Menu_Translator::find_next_untranslated_id( $lang, 0, $exclude_ids ) > 0 ) {
			return true;
		}

		return ! empty( self::get_actionable_remaining_ids( $lang, $job, 1 ) );
	}

	private static function get_exhausted_job_post_ids( array $job ) {
		$exhausted = is_array( $job['exhausted_ids'] ?? null ) ? array_map( 'absint', $job['exhausted_ids'] ) : array();

		return array_values( array_unique( array_filter( $exhausted ) ) );
	}

	private static function get_actionable_remaining_ids( $lang, array $job, $limit = 50 ) {
		$exclude_lookup = array_fill_keys(
			array_merge(
				self::get_forward_scan_exclude_ids( $job ),
				self::get_parked_ids( $job )
			),
			true
		);
		$actionable     = array();

		foreach ( Translation_Query::collect_remaining_post_ids( $lang, max( 1, absint( $limit ) ) ) as $post_id ) {
			$post_id = absint( $post_id );

			if ( $post_id <= 0 || isset( $exclude_lookup[ $post_id ] ) ) {
				continue;
			}

			$actionable[] = $post_id;
		}

		return $actionable;
	}

	private static function format_skipped_job_notice( $lang, array $skipped_ids ) {
		return self::format_stalled_job_error(
			$lang,
			$skipped_ids,
			__( 'ترجمه خودکار تمام شد — %1$d مورد پس از چند تلاش رد شد و بقیه انجام شد: %2$s', 'polymart-ai' )
		);
	}

	private static function format_no_queue_diagnostic( $lang, array $issues = array() ) {
		$lang = sanitize_key( (string) $lang );

		if ( empty( $issues ) ) {
			$issues = Translation_Query::collect_storefront_translation_issues( $lang, 8 );
		}

		if ( ! empty( $issues ) ) {
			$lines = array();

			foreach ( array_slice( $issues, 0, 5 ) as $issue ) {
				$lines[] = sprintf(
					'#%1$d %2$s (%3$s): %4$s',
					(int) ( $issue['post_id'] ?? 0 ),
					(string) ( $issue['title'] ?? '' ),
					(string) ( $issue['type'] ?? '' ),
					(string) ( $issue['reason'] ?? '' )
				);
			}

			return sprintf(
				/* translators: 1: count, 2: details */
				__( 'پیش‌بررسی %1$d مورد ناقص پیدا کرد اما در صف index نیست — «توقف کامل» بزنید و دوباره «شروع» کنید: %2$s', 'polymart-ai' ),
				count( $issues ),
				implode( ' | ', $lines )
			);
		}

		return __(
			'مورد ترجمه‌نشده‌ای در index یافت نشد. اگر صفحه اصلی/فوتر/بلوک HTML هنوز فارسی است، احتمالاً متن در ویجت هدر وودمارت (خارج از پست) است — تب «رشته‌های UI» را اسکن کنید، یا صفحه/بلوک cms_block را در ویرایشگر باز کنید.',
			'polymart-ai'
		);
	}

	private static function format_stalled_job_error( $lang, array $remaining_ids, $template = '' ) {
		$lang          = sanitize_key( (string) $lang );
		$remaining_ids = array_values( array_filter( array_map( 'absint', $remaining_ids ) ) );
		$details       = array();

		foreach ( array_slice( $remaining_ids, 0, 5 ) as $post_id ) {
			$gaps    = Post_Translator::get_translation_gaps( $post_id, $lang );
			$parts   = array();

			foreach ( $gaps['fields'] ?? array() as $field ) {
				if ( empty( $field['translated'] ) ) {
					$parts[] = sprintf(
						'%s [%s]',
						(string) ( $field['label'] ?? '' ),
						(string) ( $field['meta_key'] ?? '' )
					);
				}
			}

			if ( empty( $parts ) && ! empty( $gaps['missing'] ) ) {
				$parts = $gaps['missing'];
			}

			if ( empty( $parts ) && ! empty( $gaps['notes'] ) ) {
				$parts = $gaps['notes'];
			}

			$details[] = sprintf(
				'#%1$d: %2$s',
				$post_id,
				get_the_title( $post_id ) ?: __( '(بدون عنوان)', 'polymart-ai' )
			) . ( ! empty( $parts ) ? ' — ' . implode( ', ', $parts ) : '' );
		}

		if ( '' === $template ) {
			$template = __( 'ترجمه متوقف شد — %1$d مورد ناقص: %2$s. «ادامه» / «شروع» مجدد بزنید یا فیلدها را دستی تکمیل کنید.', 'polymart-ai' );
		}

		$summary = implode( ' | ', $details );

		if ( '' === trim( $summary ) && ! empty( $remaining_ids ) ) {
			foreach ( array_slice( $remaining_ids, 0, 5 ) as $post_id ) {
				$details[] = sprintf(
					'#%1$d: %2$s',
					$post_id,
					get_the_title( $post_id ) ?: __( '(بدون عنوان)', 'polymart-ai' )
				);
			}
			$summary = implode( ' | ', $details );
		}

		if ( count( $remaining_ids ) > count( $details ) ) {
			$summary .= sprintf(
				/* translators: %d: additional incomplete post count */
				__( ' … +%d مورد دیگر', 'polymart-ai' ),
				count( $remaining_ids ) - count( $details )
			);
		}

		if ( '' === trim( $summary ) ) {
			$issues = Translation_Query::collect_storefront_translation_issues( $lang, 5 );

			foreach ( $issues as $issue ) {
				$details[] = sprintf(
					'#%1$d: %2$s — %3$s',
					(int) ( $issue['post_id'] ?? 0 ),
					(string) ( $issue['title'] ?? '' ),
					(string) ( $issue['reason'] ?? '' )
				);
			}

			$summary = implode( ' | ', $details );
		}

		if ( '' === trim( $summary ) ) {
			$summary = __( 'هیچ مورد قابل اجرا در صف پیدا نشد. صفحه اصلی، بلوک HTML (cms_block) یا ویجت هدر را در ویرایشگر باز کنید و دوباره «شروع» بزنید.', 'polymart-ai' );
		}

		$total_incomplete = max(
			count( $remaining_ids ),
			Translation_Query::count_untranslated_persian_posts( $lang )
		);

		return sprintf(
			$template,
			$total_incomplete,
			$summary
		);
	}

	private static function enqueue_job_retry( array &$job, $post_id ) {
		$post_id        = absint( $post_id );
		$lang           = sanitize_key( (string) ( $job['lang'] ?? '' ) );
		$retry_queue    = self::get_retry_queue( $job );
		$retry_attempts = is_array( $job['retry_attempts'] ?? null ) ? $job['retry_attempts'] : array();
		$attempts       = (int) ( $retry_attempts[ $post_id ] ?? 0 ) + 1;
		$max_attempts   = self::get_max_job_retries_for_post( $post_id, $lang );

		$retry_attempts[ $post_id ] = $attempts;
		$job['retry_attempts']      = $retry_attempts;

		if ( $attempts >= $max_attempts ) {
			$partial = Post_Translator::get_job_partial_state( $post_id, $lang );
			$phase   = sanitize_key( (string) ( $partial['phase'] ?? '' ) );

			if ( '' !== $phase && in_array( $phase, array( 'core', 'commerce', 'variations', 'elementor', 'fields' ), true ) ) {
				if ( ! in_array( $post_id, $retry_queue, true ) ) {
					$retry_queue[] = $post_id;
				}

				$job['retry_queue'] = $retry_queue;

				return;
			}

			$pending = array();
			$post    = get_post( $post_id );

			if ( $post instanceof \WP_Post ) {
				$pending = Post_Translator::collect_persian_fields( $post, $lang );
			}

			if ( ! empty( $pending ) ) {
				if ( ! in_array( $post_id, $retry_queue, true ) ) {
					$retry_queue[] = $post_id;
				}

				$job['retry_queue'] = $retry_queue;

				return;
			}

			$exhausted = is_array( $job['exhausted_ids'] ?? null ) ? $job['exhausted_ids'] : array();

			if ( ! in_array( $post_id, $exhausted, true ) ) {
				$exhausted[] = $post_id;
			}

			$job['exhausted_ids'] = array_values( array_map( 'absint', $exhausted ) );
			$job['retry_queue']   = array_values(
				array_filter(
					self::get_retry_queue( $job ),
					static function ( $queued_id ) use ( $post_id ) {
						return (int) $queued_id !== (int) $post_id;
					}
				)
			);

			return;
		}

		if ( ! in_array( $post_id, $retry_queue, true ) ) {
			$retry_queue[] = $post_id;
		}

		$job['retry_queue'] = $retry_queue;
	}

}
