<?php
/**
 * Action Scheduler driver for Elementor metabox translation (single post / language).
 *
 * AJAX only queues work and returns immediately; heavy AI slices run in AS callbacks
 * so admin-ajax never hits the web-server timeout.
 *
 * @package PolymartAI\Activity_Logger
 */

namespace PolymartAI\Activity_Logger;

use PolymartAI\Translation\Post_Translator;

use PolymartAI\Language_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Metabox_Action_Scheduler
 */
final class Metabox_Action_Scheduler {

	const HOOK  = 'polymart_ai_as_metabox_translate';
	const GROUP = 'polymart_ai_metabox';

	const REQUEST_BUDGET_SEC = 120;
	const MAX_SLICES         = 3;
	const STALE_RUNNING_SEC  = 90;
	// IMPORTANT: metabox translation relies on the inline queue runner to keep
	// progressing even when WP-Cron/AS runners are not reliably firing on the site.
	// If we schedule the next slice in the future (even +1s), the queue runner that
	// is already executing inside this request will not pick it up, and progress
	// may stall indefinitely.
	const CHAIN_DELAY_SEC    = 0;

	const STATUS_META_PREFIX = '_polymart_ai_metabox_as_';
	const BATCH_META_KEY     = '_polymart_ai_metabox_as_batch';
	const FALLBACK_IDLE_SEC  = 20;
	const TOXIC_META_PREFIX  = '_polymart_ai_elementor_toxic_payload_';
	const LIVE_RUNNING_MAX_AGE_SEC   = 75;
	const POLL_NUDGE_COOLDOWN_SEC    = 25;
	const POLL_FALLBACK_COOLDOWN_SEC = 60;
	const POLL_RECOVERY_LOCK_TTL     = 120;
	const POLL_QUEUE_LITE_SEC        = 15;

	/**
	 * @var int
	 */
	private static $current_action_id = 0;

	/**
	 * @var bool
	 */
	private static $handler_clean_exit = false;

	/**
	 * @var array{post_id: int, lang: string}|null
	 */
	private static $active_job = null;

	/**
	 * Last auth/permission failure captured for shutdown logging.
	 *
	 * @var string
	 */
	private static $last_auth_failure_message = '';

	/**
	 * Register the metabox AS hook.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'handle_metabox_action' ), 10, 3 );
	}

	/**
	 * One Elementor API batch per metabox AS slice (avoids multi-minute hangs).
	 *
	 * @param int $chunks Requested chunk budget.
	 * @return int
	 */
	public static function filter_metabox_elementor_chunk_budget( $chunks ) {
		return 1;
	}

	/**
	 * Recover stale in-progress metabox AS actions and nudge the queue.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return int Number of recovered actions.
	 */
	public static function recover_stale_and_nudge( $post_id, $lang ) {
		$post_id   = absint( $post_id );
		$lang      = sanitize_key( (string) $lang );
		$recovered = self::recover_stale_running_actions( $post_id, $lang, self::STALE_RUNNING_SEC );

		if ( $recovered > 0 ) {
			self::chain_next( $post_id, $lang );
		}

		return $recovered;
	}

	/**
	 * Aggressively kill stale running metabox actions for a post/lang pair.
	 *
	 * Used by the metabox polling loop when Action Scheduler is jammed and actions
	 * stay In-progress for minutes.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @param int    $max_age_sec Maximum age for "running" (seconds).
	 * @return int Number of killed actions.
	 */
	public static function kill_stale_running_now( $post_id, $lang, $max_age_sec = 75 ) {
		$post_id     = absint( $post_id );
		$lang        = sanitize_key( (string) $lang );
		$max_age_sec = max( 30, absint( $max_age_sec ) );

		if ( $post_id <= 0 || '' === $lang || ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return 0;
		}

		$store  = \ActionScheduler_Store::instance();
		$killed = 0;
		$now    = time();

		$running = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_RUNNING,
				'per_page' => 20,
			)
		);

		foreach ( $running as $action ) {
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) || ! method_exists( $action, 'get_id' ) ) {
				continue;
			}

			$args = $action->get_args();
			if ( absint( $args['post_id'] ?? 0 ) !== $post_id || sanitize_key( (string) ( $args['lang'] ?? '' ) ) !== $lang ) {
				continue;
			}

			$action_id = absint( $action->get_id() );
			if ( $action_id <= 0 ) {
				continue;
			}

			// We cannot reliably read "started_at" from AS, so use scheduled date as a floor,
			// and also consider our own status heartbeat.
			$due = 0;
			try {
				$date = $store->get_date( $action_id );
				$due  = $date ? absint( $date->getTimestamp() ) : 0;
			} catch ( \Throwable $e ) {
				$due = 0;
			}

			$status = self::get_status( $post_id, $lang );
			$beat   = absint( $status['updated_at'] ?? 0 );
			$stamp  = max( $due, $beat );

			if ( $stamp > 0 && ( $now - $stamp ) < $max_age_sec ) {
				continue;
			}

			try {
				$store->mark_failure( $action_id );
				++$killed;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}

		if ( $killed > 0 ) {
			Post_Translator::release_translation_lock( $post_id, $lang, true );
			self::update_status(
				$post_id,
				$lang,
				array(
					'status'  => 'running',
					'phase'   => 'elementor',
					'message' => __( 'اکشن پس‌زمینه بیش از حد طول کشید — لغو شد. ادامه با Polling…', 'polymart-ai' ),
					'error'   => '',
					'fallback' => 'stale_killed',
				)
			);
		}

		return $killed;
	}

	/**
	 * @return bool
	 */
	public static function is_available() {
		return Job_Action_Scheduler::is_available();
	}

	/**
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function get_status_meta_key( $lang ) {
		return self::STATUS_META_PREFIX . sanitize_key( (string) $lang );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return array<string, mixed>
	 */
	public static function get_status( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$stored  = get_post_meta( $post_id, self::get_status_meta_key( $lang ), true );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @param array<string, mixed> $patch   Status fields to merge.
	 * @return array<string, mixed>
	 */
	public static function update_status( $post_id, $lang, array $patch ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return array();
		}

		$status              = self::get_status( $post_id, $lang );
		$status              = array_merge( $status, $patch );
		$status['updated_at'] = time();

		update_post_meta( $post_id, self::get_status_meta_key( $lang ), $status );

		return $status;
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return void
	 */
	public static function clear_status( $post_id, $lang ) {
		delete_post_meta( absint( $post_id ), self::get_status_meta_key( $lang ) );
	}

	/**
	 * @param int         $post_id Post ID.
	 * @param string|null $lang    Optional language; null clears every target language.
	 * @return void
	 */
	public static function force_unlock_post( $post_id, $lang = null ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return;
		}

		if ( null !== $lang && '' !== sanitize_key( (string) $lang ) ) {
			Post_Translator::release_translation_lock( $post_id, sanitize_key( (string) $lang ), true );
			Post_Translator::release_stale_translation_lock( $post_id, sanitize_key( (string) $lang ), 0 );

			return;
		}

		foreach ( Language_Registry::get_translation_target_languages() as $language ) {
			$code = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $code ) {
				continue;
			}

			Post_Translator::release_translation_lock( $post_id, $code, true );
			Post_Translator::release_stale_translation_lock( $post_id, $code, 0 );
		}
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return void
	 */
	public static function cancel_pending( $post_id, $lang ) {
		self::purge_all_queue_actions( $post_id, $lang, 0 );
	}

	/**
	 * Cancel every metabox AS action site-wide (Stop / bulk takeover).
	 *
	 * @return int Number of rows cleared.
	 */
	public static function cancel_all_plugin_actions() {
		if ( ! self::is_available() ) {
			return 0;
		}

		$removed = 0;

		self::fail_all_running_plugin_actions();

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
			as_unschedule_all_actions( self::HOOK, null, self::GROUP );
		}

		// Cap pagination — scanning every failed/canceled row hung Start/Stop after long Elementor runs.
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return $removed;
		}

		$store    = \ActionScheduler_Store::instance();
		$statuses = array(
			\ActionScheduler_Store::STATUS_PENDING,
			\ActionScheduler_Store::STATUS_FAILED,
		);

		foreach ( $statuses as $store_status ) {
			$page  = 1;
			$pages = 0;

			do {
				$actions = as_get_scheduled_actions(
					array(
						'hook'     => self::HOOK,
						'group'    => self::GROUP,
						'status'   => $store_status,
						'per_page' => 50,
						'page'     => $page,
					)
				);

				if ( empty( $actions ) ) {
					break;
				}

				foreach ( $actions as $action ) {
					if ( ! is_object( $action ) || ! method_exists( $action, 'get_id' ) ) {
						continue;
					}

					$action_id = absint( $action->get_id() );

					if ( $action_id <= 0 ) {
						continue;
					}

					try {
						$store->cancel_action( $action_id );
						++$removed;
					} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					}
				}

				++$page;
				++$pages;
			} while ( count( $actions ) >= 50 && $pages < 4 );
		}

		return $removed;
	}

	/**
	 * Whether any metabox AS row is pending or actively running (any post/lang).
	 *
	 * @return bool
	 */
	public static function has_any_live_as_actions() {
		if ( ! self::is_available() || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		foreach ( array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ) as $store_status ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK,
					'group'    => self::GROUP,
					'status'   => $store_status,
					'per_page' => 1,
				)
			);

			if ( ! empty( $actions ) ) {
				if ( \ActionScheduler_Store::STATUS_RUNNING === $store_status ) {
					$action = reset( $actions );

					if ( is_object( $action ) && method_exists( $action, 'get_args' ) && method_exists( $action, 'get_id' ) ) {
						$args = $action->get_args();

						if (
							self::is_metabox_as_running_action_live(
								absint( $action->get_id() ),
								absint( $args['post_id'] ?? 0 ),
								sanitize_key( (string) ( $args['lang'] ?? '' ) )
							)
						) {
							return true;
						}

						continue;
					}
				}

				return true;
			}
		}

		return false;
	}

	/**
	 * @return int
	 */
	private static function fail_all_running_plugin_actions() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return 0;
		}

		$store   = \ActionScheduler_Store::instance();
		$failed  = 0;
		$running = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_RUNNING,
				'per_page' => 50,
			)
		);

		foreach ( (array) $running as $action ) {
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_id' ) ) {
				continue;
			}

			$action_id = absint( $action->get_id() );

			if ( $action_id <= 0 ) {
				continue;
			}

			try {
				$store->mark_failure( $action_id );
				++$failed;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}

		return $failed;
	}

	/**
	 * Remove every metabox AS row for one post/language (pending, running, failed).
	 *
	 * Prevents ghost jobs where stale rows block as_schedule_single_action silently.
	 *
	 * @param int $post_id            Post ID.
	 * @param string $lang            Language code.
	 * @param int $preserve_action_id Skip this action ID (current in-flight slice).
	 * @return int Number of rows cleared.
	 */
	public static function purge_all_queue_actions( $post_id, $lang, $preserve_action_id = 0 ) {
		if ( ! self::is_available() ) {
			return 0;
		}

		$post_id            = absint( $post_id );
		$lang               = sanitize_key( (string) $lang );
		$preserve_action_id = absint( $preserve_action_id );

		if ( $post_id <= 0 || '' === $lang || ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return 0;
		}

		$store    = \ActionScheduler_Store::instance();
		$removed  = 0;
		$statuses = array(
			\ActionScheduler_Store::STATUS_PENDING,
			\ActionScheduler_Store::STATUS_RUNNING,
			\ActionScheduler_Store::STATUS_FAILED,
			\ActionScheduler_Store::STATUS_CANCELED,
		);

		foreach ( $statuses as $store_status ) {
			$page = 1;

			do {
				$actions = as_get_scheduled_actions(
					array(
						'hook'     => self::HOOK,
						'group'    => self::GROUP,
						'status'   => $store_status,
						'per_page' => 50,
						'page'     => $page,
					)
				);

				if ( empty( $actions ) ) {
					break;
				}

				foreach ( $actions as $action ) {
					if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) || ! method_exists( $action, 'get_id' ) ) {
						continue;
					}

					$args      = $action->get_args();
					$action_id = absint( $action->get_id() );

					if ( absint( $args['post_id'] ?? 0 ) !== $post_id || sanitize_key( (string) ( $args['lang'] ?? '' ) ) !== $lang ) {
						continue;
					}

					if ( $preserve_action_id > 0 && $action_id === $preserve_action_id ) {
						continue;
					}

					try {
						if ( \ActionScheduler_Store::STATUS_RUNNING === $store_status ) {
							$store->mark_failure( $action_id );
						} else {
							$store->cancel_action( $action_id );
						}

						++$removed;
					} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					}
				}

				++$page;
			} while ( count( $actions ) >= 50 );
		}

		if ( $preserve_action_id <= 0 ) {
			Post_Translator::release_translation_lock( $post_id, $lang, true );
		}

		return $removed;
	}

	/**
	 * Whether a metabox AS action in "running" status is still actively executing.
	 *
	 * Zombie rows stay In-progress after PHP/worker death — treat them as not live
	 * so poll fallback can take over.
	 *
	 * @param int    $action_id   Action Scheduler action ID.
	 * @param int    $post_id     Post ID.
	 * @param string $lang        Language code.
	 * @param int    $max_age_sec Maximum age before the row is considered stale.
	 * @return bool
	 */
	private static function is_metabox_as_running_action_live( $action_id, $post_id, $lang, $max_age_sec = null ) {
		$action_id   = absint( $action_id );
		$post_id     = absint( $post_id );
		$lang        = sanitize_key( (string) $lang );
		$max_age_sec = null === $max_age_sec ? self::LIVE_RUNNING_MAX_AGE_SEC : max( 30, absint( $max_age_sec ) );

		if ( $action_id <= 0 || $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$due = 0;

		if ( class_exists( 'ActionScheduler_Store' ) ) {
			try {
				$date = \ActionScheduler_Store::instance()->get_date( $action_id );
				$due  = $date ? absint( $date->getTimestamp() ) : 0;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				$due = 0;
			}
		}

		$status = self::get_status( $post_id, $lang );
		$beat   = absint( $status['updated_at'] ?? 0 );
		$stamp  = max( $due, $beat );

		if ( $stamp <= 0 ) {
			return false;
		}

		return ( time() - $stamp ) < $max_age_sec;
	}

	/**
	 * Whether Action Scheduler has a live pending or in-progress metabox action for this post/lang.
	 *
	 * In-progress rows older than {@see LIVE_RUNNING_MAX_AGE_SEC} are ignored (zombie workers).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	public static function has_live_as_actions( $post_id, $lang ) {
		if ( ! self::is_available() || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		foreach ( array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ) as $store_status ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK,
					'group'    => self::GROUP,
					'status'   => $store_status,
					'per_page' => 20,
				)
			);

			foreach ( $actions as $action ) {
				if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
					continue;
				}

				$args = $action->get_args();

				if ( absint( $args['post_id'] ?? 0 ) !== $post_id || sanitize_key( (string) ( $args['lang'] ?? '' ) ) !== $lang ) {
					continue;
				}

				if ( \ActionScheduler_Store::STATUS_RUNNING === $store_status ) {
					$action_id = method_exists( $action, 'get_id' ) ? absint( $action->get_id() ) : 0;

					if ( ! self::is_metabox_as_running_action_live( $action_id, $post_id, $lang ) ) {
						continue;
					}
				}

				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the metabox job meta says "running" but AS has no live queue rows (ghost/stalled).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	public static function is_job_stalled( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::is_available() ) {
			return false;
		}

		if ( self::has_live_as_actions( $post_id, $lang ) ) {
			return false;
		}

		$status     = self::get_status( $post_id, $lang );
		$meta_state = (string) ( $status['status'] ?? '' );

		if ( 'running' !== $meta_state ) {
			return false;
		}

		$gaps = Post_Translator::get_translation_gaps( $post_id, $lang );

		if ( 'translated' === (string) ( $gaps['status'] ?? '' ) && empty( $gaps['missing'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Release locks and mark a ghost/stalled job so poll-driven fallback can resume work.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return void
	 */
	public static function resolve_ghost_job( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return;
		}

		Post_Translator::release_translation_lock( $post_id, $lang, true );
		Post_Translator::release_stale_translation_lock( $post_id, $lang, 0 );

		self::update_status(
			$post_id,
			$lang,
			array(
				'status'   => 'running',
				'message'  => __( 'صف Action Scheduler یافت نشد — ادامه با Polling…', 'polymart-ai' ),
				'error'    => '',
				'fallback' => 'ghost_recovery',
			)
		);

		\PolymartAI\Activity_Logger::log(
			'warning',
			sprintf(
				/* translators: 1: post ID, 2: language code */
				__( 'مatabox poll — #%1$d (%2$s): وضعیت Ghost/Stalled — قفل آزاد شد، Fallback فعال شد.', 'polymart-ai' ),
				$post_id,
				$lang
			),
			array( 'post_id' => $post_id, 'lang' => $lang, 'source' => 'metabox_poll' )
		);
	}

	/**
	 * Self-healing steps invoked by the metabox status poll (kill zombies, nudge AS, inline fallback).
	 *
	 * Lightweight on most polls — heavy AI/AS work is throttled to avoid admin-ajax stampedes.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return void
	 */
	public static function run_poll_recovery( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::is_available() ) {
			return;
		}

		if ( Translation_Scheduler_Coordinator::should_defer_metabox_work() ) {
			return;
		}

		if ( ! Post_Translator::uses_elementor_builder( $post_id ) ) {
			return;
		}

		if ( self::is_poll_recovery_locked( $post_id, $lang ) ) {
			return;
		}

		$status     = self::get_status( $post_id, $lang );
		$meta_state = (string) ( $status['status'] ?? '' );
		$updated_at = absint( $status['updated_at'] ?? 0 );
		$now        = time();

		if ( self::has_stale_running_action( $post_id, $lang ) ) {
			self::kill_stale_running_now( $post_id, $lang, self::LIVE_RUNNING_MAX_AGE_SEC );
		}

		if ( self::has_live_as_actions( $post_id, $lang ) ) {
			if ( 'running' === $meta_state && $updated_at > 0 && ( $now - $updated_at ) >= 30 ) {
				self::maybe_nudge_queue_lite( $post_id, $lang );
			}

			return;
		}

		if ( 'running' !== $meta_state || ! self::is_job_stalled( $post_id, $lang ) ) {
			return;
		}

		if ( self::should_run_poll_fallback( $post_id, $lang ) ) {
			self::set_poll_recovery_lock( $post_id, $lang );

			try {
				self::resolve_ghost_job( $post_id, $lang );
				self::run_inline_fallback_slice( $post_id, $lang, 'poll_stall' );
				self::touch_poll_fallback_cooldown( $post_id, $lang );
			} finally {
				self::clear_poll_recovery_lock( $post_id, $lang );
			}

			return;
		}

		self::maybe_nudge_stalled_job( $post_id, $lang );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	private static function is_poll_recovery_locked( $post_id, $lang ) {
		return (bool) get_transient( self::get_poll_recovery_lock_key( $post_id, $lang ) );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return void
	 */
	private static function set_poll_recovery_lock( $post_id, $lang ) {
		set_transient(
			self::get_poll_recovery_lock_key( $post_id, $lang ),
			1,
			self::POLL_RECOVERY_LOCK_TTL
		);
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return void
	 */
	private static function clear_poll_recovery_lock( $post_id, $lang ) {
		delete_transient( self::get_poll_recovery_lock_key( $post_id, $lang ) );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return string
	 */
	private static function get_poll_recovery_lock_key( $post_id, $lang ) {
		return 'polymart_ai_mas_poll_busy_' . absint( $post_id ) . '_' . sanitize_key( (string) $lang );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	private static function should_run_poll_fallback( $post_id, $lang ) {
		$status = self::get_status( $post_id, $lang );
		$last   = absint( $status['last_fallback_at'] ?? 0 );

		return $last <= 0 || ( time() - $last ) >= self::POLL_FALLBACK_COOLDOWN_SEC;
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return void
	 */
	private static function touch_poll_fallback_cooldown( $post_id, $lang ) {
		self::update_status(
			$post_id,
			$lang,
			array(
				'last_fallback_at' => time(),
			)
		);
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	private static function should_nudge_queue( $post_id, $lang ) {
		$status = self::get_status( $post_id, $lang );
		$last   = absint( $status['last_nudge_at'] ?? 0 );

		return $last <= 0 || ( time() - $last ) >= self::POLL_NUDGE_COOLDOWN_SEC;
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return void
	 */
	private static function touch_nudge_cooldown( $post_id, $lang ) {
		self::update_status(
			$post_id,
			$lang,
			array(
				'last_nudge_at' => time(),
			)
		);
	}

	/**
	 * Whether any in-progress metabox AS row for this post/lang is past the live age threshold.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	private static function has_stale_running_action( $post_id, $lang ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		$running = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_RUNNING,
				'per_page' => 10,
			)
		);

		foreach ( $running as $action ) {
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) || ! method_exists( $action, 'get_id' ) ) {
				continue;
			}

			$args = $action->get_args();

			if ( absint( $args['post_id'] ?? 0 ) !== $post_id || sanitize_key( (string) ( $args['lang'] ?? '' ) ) !== $lang ) {
				continue;
			}

			if ( ! self::is_metabox_as_running_action_live( absint( $action->get_id() ), $post_id, $lang ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Bounded AS queue nudge for poll requests (no direct AI slice).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return void
	 */
	private static function maybe_nudge_queue_lite( $post_id, $lang ) {
		if ( ! self::should_nudge_queue( $post_id, $lang ) ) {
			return;
		}

		self::touch_nudge_cooldown( $post_id, $lang );

		try {
			self::run_queue_inline_lite();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}
	}

	/**
	 * Re-enqueue and nudge when stalled but heavy fallback is on cooldown.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return void
	 */
	private static function maybe_nudge_stalled_job( $post_id, $lang ) {
		if ( ! self::should_nudge_queue( $post_id, $lang ) ) {
			return;
		}

		self::touch_nudge_cooldown( $post_id, $lang );

		$action_id = self::schedule_slice( $post_id, $lang, 0 );

		if ( $action_id <= 0 ) {
			return;
		}

		try {
			self::run_queue_inline_lite();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}
	}

	/**
	 * Short AS runner pass suitable for admin-ajax poll (avoids 90s queue runner blocks).
	 *
	 * @return int Actions processed.
	 */
	private static function run_queue_inline_lite() {
		if ( ! self::is_available() || ! class_exists( 'ActionScheduler_QueueRunner' ) ) {
			return 0;
		}

		$time_limit = (int) apply_filters( 'polymart_ai_metabox_poll_queue_time_limit', self::POLL_QUEUE_LITE_SEC );

		$time_filter = static function () use ( $time_limit ) {
			return max( 8, min( 30, $time_limit ) );
		};
		$batch_filter = static function () {
			return 1;
		};

		add_filter( 'action_scheduler_queue_runner_time_limit', $time_filter, 50 );
		add_filter( 'action_scheduler_queue_runner_batch_size', $batch_filter, 50 );

		try {
			$runner = \ActionScheduler_QueueRunner::instance();

			return (int) $runner->run( 'PolymartAI-Metabox-Poll' );
		} catch ( \Throwable $e ) {
			return 0;
		} finally {
			remove_filter( 'action_scheduler_queue_runner_time_limit', $time_filter, 50 );
			remove_filter( 'action_scheduler_queue_runner_batch_size', $batch_filter, 50 );
		}
	}

	/**
	 * Whether a metabox translation is actively queued (live AS rows or poll-driven inline work).
	 *
	 * Does not treat orphaned "running" meta without AS rows as queued — that is a stalled/ghost job.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	public static function has_pending_or_running( $post_id, $lang ) {
		if ( ! self::is_available() ) {
			return false;
		}

		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		if ( self::has_live_as_actions( $post_id, $lang ) ) {
			return true;
		}

		$status = self::get_status( $post_id, $lang );

		if ( 'running' === (string) ( $status['status'] ?? '' ) ) {
			if ( self::is_job_stalled( $post_id, $lang ) ) {
				return false;
			}

			return ! empty( $status['fallback'] );
		}

		return false;
	}

	/**
	 * Queue background translation for one post/language pair.
	 *
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @param array<string, mixed> $options force, unlock.
	 * @return int|\WP_Error Action Scheduler action ID on success.
	 */
	public static function start_translation( $post_id, $lang, array $options = array() ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$force   = ! empty( $options['force'] );
		$unlock  = ! empty( $options['unlock'] ) || $force;

		if ( $post_id <= 0 || '' === $lang ) {
			return new \WP_Error( 'polymart_ai_invalid_request', __( 'شناسه مطلب یا زبان نامعتبر است.', 'polymart-ai' ) );
		}

		if ( ! self::is_available() ) {
			return new \WP_Error(
				'polymart_ai_as_unavailable',
				__( 'Action Scheduler در دسترس نیست — WooCommerce را فعال کنید.', 'polymart-ai' )
			);
		}

		// Always clear Stop-halt on intentional metabox start (even if a stale
		// bulk "running" option somehow remains — interactive work must proceed).
		Translation_Scheduler_Coordinator::clear_halt();

		if ( Translation_Scheduler_Coordinator::is_halted() ) {
			return new \WP_Error(
				'polymart_ai_scheduler_halted',
				__( 'ترجمه متوقف شده است — دوباره «ترجمه و تکمیل» را بزنید.', 'polymart-ai' ),
				array( 'halted' => true )
			);
		}

		if ( $unlock ) {
			self::force_unlock_post( $post_id, $lang );
		}

		self::purge_all_queue_actions( $post_id, $lang, 0 );

		if ( $force ) {
			Post_Translator::clear_post_language_translations( $post_id, $lang );
			Post_Translator::clear_job_partial_state( $post_id, $lang );
			delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
		}

		if ( ! \PolymartAI\Activity_Logger::is_bulk_job_running() ) {
			\PolymartAI\Activity_Logger::clear_job_api_cooldown( false );
		}

		Post_Translator::repair_stale_elementor_job_state( $post_id, $lang );
		Post_Translator::unblock_elementor_chunk_queue( $post_id, $lang );

		// If Action Scheduler (or WP-Cron) is jammed, break stale plugin locks before enqueue.
		try {
			\PolymartAI\Activity_Logger::release_stale_locks_for_as( 60 );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		self::update_status(
			$post_id,
			$lang,
			array(
				'status'     => 'running',
				'phase'      => '',
				'message'    => __( 'ترجمه در صف پس‌زمینه قرار گرفت…', 'polymart-ai' ),
				'started_at' => time(),
				'error'      => '',
			)
		);

		$action_id = self::schedule_slice( $post_id, $lang, 0, 0 );

		if ( $action_id <= 0 || ! self::verify_scheduled_action( $action_id, $post_id, $lang ) ) {
			return self::fail_enqueue( $post_id, $lang );
		}

		try {
			self::run_queue_inline( false );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		\PolymartAI\Activity_Logger::log(
			'info',
			sprintf(
				/* translators: 1: post ID, 2: language code, 3: AS action ID */
				__( 'متاباکس — #%1$d (%2$s): ترجمه Elementor در Action Scheduler #%3$d قرار گرفت.', 'polymart-ai' ),
				$post_id,
				$lang,
				$action_id
			),
			array( 'post_id' => $post_id, 'lang' => $lang, 'source' => 'metabox_as' )
		);

		return $action_id;
	}

	/**
	 * Record a hard enqueue failure and return a WP_Error for AJAX callers.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return \WP_Error
	 */
	private static function fail_enqueue( $post_id, $lang ) {
		$message = __( 'خطا در ثبت صف Action Scheduler — کار در پس‌زمینه شروع نشد.', 'polymart-ai' );

		update_post_meta( $post_id, '_polymart_ai_elementor_error_' . sanitize_key( (string) $lang ), $message );

		Post_Translator::release_translation_lock( $post_id, $lang, true );

		self::update_status(
			$post_id,
			$lang,
			array(
				'status'  => 'failed',
				'message' => $message,
				'error'   => $message,
			)
		);

		\PolymartAI\Activity_Logger::log(
			'error',
			sprintf(
				/* translators: 1: post ID, 2: language code */
				__( 'مatabox AS — #%1$d (%2$s): ثبت صف Action Scheduler ناموفق بود.', 'polymart-ai' ),
				absint( $post_id ),
				sanitize_key( (string) $lang )
			),
			array(
				'post_id' => absint( $post_id ),
				'lang'    => sanitize_key( (string) $lang ),
				'source'  => 'metabox_as_enqueue',
			)
		);

		return new \WP_Error( 'polymart_ai_as_enqueue_failed', $message );
	}

	/**
	 * Confirm an Action Scheduler row exists for the expected metabox job.
	 *
	 * @param int    $action_id Scheduled action ID.
	 * @param int    $post_id   Post ID.
	 * @param string $lang      Language code.
	 * @return bool
	 */
	private static function verify_scheduled_action( $action_id, $post_id, $lang ) {
		$action_id = absint( $action_id );
		$post_id   = absint( $post_id );
		$lang      = sanitize_key( (string) $lang );

		if ( $action_id <= 0 || $post_id <= 0 || '' === $lang || ! class_exists( 'ActionScheduler_Store' ) ) {
			return false;
		}

		try {
			$store  = \ActionScheduler_Store::instance();
			$action = $store->fetch_action( $action_id );

			if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
				return false;
			}

			$args = $action->get_args();

			return absint( $args['post_id'] ?? 0 ) === $post_id
				&& sanitize_key( (string) ( $args['lang'] ?? '' ) ) === $lang;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Best-effort queue + inline worker fallback for jammed Action Scheduler environments.
	 *
	 * Runs at most one bounded slice, updates metabox status meta, and (when possible)
	 * schedules the next slice.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @param string $reason  Debug hint.
	 * @return array<string, mixed>|true|\WP_Error
	 */
	public static function run_inline_fallback_slice( $post_id, $lang, $reason = '' ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$reason  = sanitize_key( (string) $reason );

		if ( $post_id <= 0 || '' === $lang ) {
			return new \WP_Error( 'polymart_ai_invalid_request', __( 'شناسه مطلب یا زبان نامعتبر است.', 'polymart-ai' ) );
		}

		if ( Translation_Scheduler_Coordinator::should_defer_metabox_work() ) {
			return new \WP_Error(
				'polymart_ai_bulk_job_active',
				__( 'ترجمه خودکار در حال اجراست — Polling متاباکس تا پایان Bulk Job متوقف است.', 'polymart-ai' ),
				array( 'deferred' => true )
			);
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 'poll_stall' === $reason ? 90 : 200 );
		}

		// First try: let AS queue runner process due actions; if it processes nothing,
		// force one direct pending metabox action inline.
		try {
			if ( 'poll_stall' === $reason ) {
				self::run_queue_inline_lite();

				if ( self::has_live_as_actions( $post_id, $lang ) ) {
					return array(
						'done'        => false,
						'phase'       => 'elementor',
						'message'     => __( 'صف پس‌زمینه فعال شد — ادامه در Action Scheduler…', 'polymart-ai' ),
						'recoverable' => true,
					);
				}

				self::run_metabox_batch_inline_fallback();
			} else {
				self::run_queue_inline( true );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		// Second try: if still stuck, run one job slice directly (browser session keeps admin caps).
		$slice = Post_Translator::process_job_translation_slice( $post_id, $lang, true );

		if ( is_wp_error( $slice ) ) {
			// For metabox polling, never hard-fail on transport timeouts/recoverable slice errors.
			// Let the next poll attempt continue and/or skip poisoned chunks.
			if (
				Post_Translator::is_recoverable_job_slice_error( $slice )
				|| Post_Translator::is_api_transport_timeout_error( $slice )
			) {
				self::update_status(
					$post_id,
					$lang,
					array(
						'status'   => 'running',
						'phase'    => 'elementor',
						'message'  => $slice->get_error_message(),
						'error'    => '',
						'fallback' => $reason ? $reason : 'inline',
					)
				);

				return array(
					'done'        => false,
					'phase'       => 'elementor',
					'message'     => $slice->get_error_message(),
					'recoverable' => true,
				);
			}

			if ( 'polymart_ai_translation_in_progress' === $slice->get_error_code() ) {
				self::update_status(
					$post_id,
					$lang,
					array(
						'status'  => 'running',
						'phase'   => 'elementor',
						'message' => $slice->get_error_message(),
						'error'   => '',
					)
				);
				return $slice;
			}

			self::mark_failed( $post_id, $lang, $slice->get_error_message() );
			return $slice;
		}

		if ( is_array( $slice ) && ! empty( $slice['done'] ) ) {
			self::update_status(
				$post_id,
				$lang,
				array(
					'status'       => 'complete',
					'phase'        => (string) ( $slice['phase'] ?? 'complete' ),
					'message'      => (string) ( $slice['message'] ?? __( 'ترجمه تکمیل شد.', 'polymart-ai' ) ),
					'completed_at' => time(),
					'error'        => '',
				)
			);
			return $slice;
		}

		// Keep UI progress alive even when AS cannot persist its own status.
		self::update_status(
			$post_id,
			$lang,
			array(
				'status'         => 'running',
				'phase'          => (string) ( is_array( $slice ) ? ( $slice['phase'] ?? 'elementor' ) : 'elementor' ),
				'phase_progress' => (string) ( is_array( $slice ) ? ( $slice['phase_progress'] ?? '' ) : '' ),
				'message'        => (string) (
					is_array( $slice ) && ! empty( $slice['message'] )
						? $slice['message']
						: __( 'Action Scheduler فعال نیست — اجرای مستقیم توسط Polling…', 'polymart-ai' )
				),
				'error'          => '',
				'fallback'       => $reason ? $reason : 'inline',
			)
		);

		// Try to keep the AS chain alive if DB accepts inserts again.
		$action_id = self::schedule_slice( $post_id, $lang, 0 );
		if ( $action_id > 0 ) {
			try {
				self::run_queue_inline( false );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}

		return is_array( $slice ) ? $slice : true;
	}

	/**
	 * Inspect pending/running state for one post/lang pair.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return array{pending: int, running: int}
	 */
	public static function get_queue_counts( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return array( 'pending' => 0, 'running' => 0 );
		}

		$pending = 0;
		$running = 0;

		foreach ( array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ) as $store_status ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK,
					'group'    => self::GROUP,
					'status'   => $store_status,
					'per_page' => 20,
				)
			);

			foreach ( $actions as $action ) {
				if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
					continue;
				}

				$args = $action->get_args();

				if ( absint( $args['post_id'] ?? 0 ) !== $post_id || sanitize_key( (string) ( $args['lang'] ?? '' ) ) !== $lang ) {
					continue;
				}

				if ( \ActionScheduler_Store::STATUS_PENDING === $store_status ) {
					++$pending;
				} else {
					++$running;
				}
			}
		}

		return array(
			'pending' => $pending,
			'running' => $running,
		);
	}

	/**
	 * Queue sequential background translations for multiple languages on one post.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<int, string>   $langs   Language codes.
	 * @param array<string, mixed> $options force, unlock.
	 * @return true|\WP_Error
	 */
	public static function start_batch_translations( $post_id, array $langs, array $options = array() ) {
		$post_id = absint( $post_id );
		$langs   = array_values(
			array_filter(
				array_map(
					static function ( $code ) {
						return sanitize_key( (string) $code );
					},
					$langs
				)
			)
		);

		if ( $post_id <= 0 || empty( $langs ) ) {
			return new \WP_Error( 'polymart_ai_invalid_request', __( 'زبان مقصد برای ترجمه مجدد یافت نشد.', 'polymart-ai' ) );
		}

		$force  = ! empty( $options['force'] );
		$unlock = ! empty( $options['unlock'] ) || $force;

		if ( $unlock ) {
			self::force_unlock_post( $post_id );
		}

		update_post_meta(
			$post_id,
			self::BATCH_META_KEY,
			array(
				'status'     => 'running',
				'langs'      => $langs,
				'index'      => 0,
				'force'      => $force,
				'started_at' => time(),
			)
		);

		return self::start_translation(
			$post_id,
			$langs[0],
			array(
				'force'  => $force,
				'unlock' => $unlock,
			)
		);
	}

	/**
	 * @param int    $post_id   Post ID.
	 * @param string $lang      Language code.
	 * @param int    $delay_sec          Delay before run.
	 * @param int    $preserve_action_id Skip purging this in-flight action when chaining.
	 * @return int Action ID or 0.
	 */
	public static function schedule_slice( $post_id, $lang, $delay_sec = 0, $preserve_action_id = 0 ) {
		if ( ! self::is_available() || ! function_exists( 'as_schedule_single_action' ) ) {
			return 0;
		}

		if (
			Translation_Scheduler_Coordinator::is_halted()
			|| Translation_Scheduler_Coordinator::should_defer_metabox_work()
		) {
			return 0;
		}

		$post_id            = absint( $post_id );
		$lang               = sanitize_key( (string) $lang );
		$delay_sec          = max( 0, min( 30, absint( $delay_sec ) ) );
		$preserve_action_id = absint( $preserve_action_id );

		if ( $post_id <= 0 || '' === $lang ) {
			return 0;
		}

		// Idempotency: if a live action already exists (and we are not preserving
		// the current in-flight row), return it instead of spawning siblings.
		if ( $preserve_action_id <= 0 && self::has_pending_or_running( $post_id, $lang ) ) {
			return 0;
		}

		self::purge_all_queue_actions( $post_id, $lang, $preserve_action_id );

		if ( Translation_Scheduler_Coordinator::is_halted() ) {
			return 0;
		}

		$args = array(
			'post_id' => $post_id,
			'lang'    => $lang,
			'n'       => sprintf( '%.6F', microtime( true ) ),
		);

		$action_id = as_schedule_single_action( time() + $delay_sec, self::HOOK, $args, self::GROUP );

		return absint( $action_id );
	}

	/**
	 * AS callback for one bounded metabox translation batch.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @param string $token   Uniqueness token.
	 * @return void
	 */
	public static function handle_metabox_action( $post_id, $lang, $token = '' ) {
		unset( $token );

		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return;
		}

		if ( Translation_Scheduler_Coordinator::is_halted() || Translation_Scheduler_Coordinator::should_defer_metabox_work() ) {
			$halt_message = Translation_Scheduler_Coordinator::is_halted()
				? __( 'ترجمه متاباکس توسط کاربر متوقف شد.', 'polymart-ai' )
				: __( 'ترجمه متاباکس به‌دلیل اجرای Bulk Job لغو شد.', 'polymart-ai' );

			self::purge_all_queue_actions( $post_id, $lang, self::resolve_current_action_id() );
			self::update_status(
				$post_id,
				$lang,
				array(
					'status'  => 'failed',
					'message' => $halt_message,
					'error'   => '',
				)
			);
			Post_Translator::release_translation_lock( $post_id, $lang, true );
			Translation_Scheduler_Coordinator::release_global_worker( Translation_Scheduler_Coordinator::OWNER_METABOX );

			return;
		}

		if ( ! Translation_Scheduler_Coordinator::try_claim_global_worker( Translation_Scheduler_Coordinator::OWNER_METABOX ) ) {
			// Always re-queue — do not leave metabox stranded at 0/N when bulk held the lock.
			self::chain_next( $post_id, $lang );
			self::update_status(
				$post_id,
				$lang,
				array(
					'status'  => 'running',
					'message' => __( 'منتظر آزاد شدن قفل کارگر… لحظه‌ای دیگر ادامه می‌دهد.', 'polymart-ai' ),
				)
			);

			return;
		}

		self::$handler_clean_exit       = false;
		self::$last_auth_failure_message = '';
		self::$current_action_id        = self::resolve_current_action_id();
		self::$active_job               = array(
			'post_id' => $post_id,
			'lang'    => $lang,
		);

		// wp_die()/fatal errors skip PHP finally — register shutdown recovery first.
		register_shutdown_function( array( __CLASS__, 'handler_shutdown_recovery' ) );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 200 );
		}

		// Impersonate post author or admin before lock checks / meta saves (User ID 0 otherwise).
		\PolymartAI\Activity_Logger::begin_metabox_as_worker( $post_id, true );

		$should_chain   = true;
		$completed      = false;
		$lock_acquired  = false;

		// AS worker must own the per-post lock (avoid self-blocking during persist).
		Post_Translator::prepare_admin_metabox_translation_lock( $post_id, $lang, true );

		if ( ! Post_Translator::acquire_translation_lock( $post_id, $lang ) ) {
			\PolymartAI\Activity_Logger::log(
				'warning',
				sprintf(
					/* translators: 1: post ID, 2: language code */
					__( 'مatabox AS — #%1$d (%2$s): acquire قفل ناموفق — slice رد شد (کارگر دیگر یا bulk job).', 'polymart-ai' ),
					$post_id,
					$lang
				),
				array( 'post_id' => $post_id, 'lang' => $lang, 'source' => 'metabox_as' )
			);

			self::update_status(
				$post_id,
				$lang,
				array(
					'status'  => 'running',
					'message' => __( 'قفل ترجمه موقتاً در اختیار کارگر دیگر است — slice بعدی زمان‌بندی می‌شود.', 'polymart-ai' ),
					'error'   => '',
				)
			);

			self::$handler_clean_exit = true;
		} else {
			$lock_acquired = true;

			try {
				\PolymartAI\Activity_Logger::on_ai_http_heartbeat();

				if ( ! \PolymartAI\Activity_Logger::is_bulk_job_running() ) {
					\PolymartAI\Activity_Logger::clear_job_api_cooldown( false );
				}

				$recovered = self::recover_stale_running_actions( $post_id, $lang, self::STALE_RUNNING_SEC );

				if ( $recovered > 0 ) {
					\PolymartAI\Activity_Logger::log(
						'warning',
						sprintf(
							/* translators: 1: post ID, 2: language code, 3: recovered count */
							__( 'مatabox AS — #%1$d (%2$s): %3$d اکشن In-progress قدیمی آزاد شد — ادامه صف.', 'polymart-ai' ),
							$post_id,
							$lang,
							$recovered
						),
						array( 'post_id' => $post_id, 'lang' => $lang, 'source' => 'metabox_as' )
					);
				}

				Post_Translator::unblock_elementor_chunk_queue( $post_id, $lang );

				$result = self::run_metabox_batch( $post_id, $lang );

				if ( is_wp_error( $result ) ) {
					self::log_auth_failure_if_applicable( $post_id, $lang, $result->get_error_message() );

					// Never stall: skip the next pending Elementor chunk and keep chaining.
					$skipped = Post_Translator::skip_next_elementor_pending_chunk(
						$post_id,
						$lang,
						$result->get_error_code()
					);

					if ( is_array( $skipped ) ) {
						self::update_status_from_slice( $post_id, $lang, $skipped );
					} else {
						self::update_status_from_slice(
							$post_id,
							$lang,
							array(
								'done'    => false,
								'message' => $result->get_error_message(),
								'phase'   => 'elementor',
							)
						);
					}

					self::$handler_clean_exit = true;
					return;
				}

				if ( ! empty( $result['done'] ) ) {
					self::mark_complete( $post_id, $lang, $result );
					self::maybe_advance_batch( $post_id );
					$completed    = true;
					$should_chain = false;
					self::$handler_clean_exit = true;

					return;
				}

				self::update_status_from_slice( $post_id, $lang, $result );
				self::$handler_clean_exit = true;
			} catch ( \Throwable $e ) {
				self::log_auth_failure_if_applicable( $post_id, $lang, $e->getMessage() );

				// On any throwable, skip one chunk and keep going.
				$skipped = Post_Translator::skip_next_elementor_pending_chunk( $post_id, $lang, 'throwable' );
				if ( is_array( $skipped ) ) {
					self::update_status_from_slice( $post_id, $lang, $skipped );
				} else {
					self::update_status_from_slice(
						$post_id,
						$lang,
						array(
							'done'    => false,
							'message' => $e->getMessage(),
							'phase'   => 'elementor',
						)
					);
				}
				self::$handler_clean_exit = true;
			} finally {
				if ( $lock_acquired && ( $completed || $should_chain ) ) {
					Post_Translator::release_translation_lock( $post_id, $lang, $completed );
				}

				if ( $should_chain && ! $completed && ! Translation_Scheduler_Coordinator::should_defer_metabox_work() ) {
					self::chain_next( $post_id, $lang );
				}

				Translation_Scheduler_Coordinator::release_global_worker( Translation_Scheduler_Coordinator::OWNER_METABOX );
				\PolymartAI\Activity_Logger::end_metabox_as_worker();
			}

			return;
		}

		if ( $should_chain && ! $completed ) {
			if ( ! Translation_Scheduler_Coordinator::should_defer_metabox_work() ) {
				self::chain_next( $post_id, $lang );
			}
		}

		Translation_Scheduler_Coordinator::release_global_worker( Translation_Scheduler_Coordinator::OWNER_METABOX );
		\PolymartAI\Activity_Logger::end_metabox_as_worker();
	}

	/**
	 * Run bounded translation slices for one metabox AS request.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function run_metabox_batch( $post_id, $lang ) {
		$started_at = microtime( true );
		$max_slices = (int) apply_filters( 'polymart_ai_metabox_as_max_slices', self::MAX_SLICES, $post_id, $lang );
		$budget_sec = (int) apply_filters(
			'polymart_ai_metabox_as_budget_sec',
			self::REQUEST_BUDGET_SEC,
			$post_id,
			$lang
		);
		$slices_run = 0;
		$last_slice = array();

		add_filter( 'polymart_ai_job_step_max_elementor_chunks', array( __CLASS__, 'filter_metabox_elementor_chunk_budget' ), 100 );

		try {
			while ( $slices_run < max( 1, $max_slices ) && ( microtime( true ) - $started_at ) < max( 30, $budget_sec ) ) {
			Post_Translator::unblock_elementor_chunk_queue( $post_id, $lang );

			\PolymartAI\Activity_Logger::log(
				'info',
				sprintf(
					/* translators: 1: post ID, 2: language code, 3: slice number */
					__( 'متاباکس AS — #%1$d (%2$s): slice ترجمه #%3$d…', 'polymart-ai' ),
					$post_id,
					$lang,
					$slices_run + 1
				),
				array( 'post_id' => $post_id, 'lang' => $lang, 'source' => 'metabox_as' )
			);

			// Lock is owned by the AS worker; avoid self-blocking lock checks.
			$slice = Post_Translator::process_job_translation_slice( $post_id, $lang, false );
			++$slices_run;
			$last_slice = is_array( $slice ) ? $slice : array();

			if ( is_array( $slice ) && ! empty( $slice['recoverable'] ) ) {
				self::update_status_from_slice( $post_id, $lang, $last_slice );

				return $last_slice;
			}

			if ( is_wp_error( $slice ) ) {
				self::log_auth_failure_if_applicable( $post_id, $lang, $slice->get_error_message() );

				if (
					Post_Translator::is_recoverable_job_slice_error( $slice )
					|| Post_Translator::is_api_transport_timeout_error( $slice )
				) {
					self::update_status_from_slice(
						$post_id,
						$lang,
						array_merge(
							$last_slice,
							array(
								'done'        => false,
								'message'     => $slice->get_error_message(),
								'recoverable' => true,
							)
						)
					);

					return array(
						'done'             => false,
						'phase'            => (string) ( $last_slice['phase'] ?? 'elementor' ),
						'phase_progress'   => (string) ( $last_slice['phase_progress'] ?? '' ),
						'message'          => $slice->get_error_message(),
						'recoverable'      => true,
					);
				}

				return $slice;
			}

			self::update_status_from_slice( $post_id, $lang, $last_slice );

			if ( ! empty( $slice['done'] ) ) {
				return $last_slice;
			}
		}
		} finally {
			remove_filter( 'polymart_ai_job_step_max_elementor_chunks', array( __CLASS__, 'filter_metabox_elementor_chunk_budget' ), 100 );
		}

		return ! empty( $last_slice )
			? $last_slice
			: array(
				'done'    => false,
				'phase'   => (string) ( Post_Translator::get_job_partial_state( $post_id, $lang )['phase'] ?? 'elementor' ),
				'message' => __( 'ترجمه در حال انجام است…', 'polymart-ai' ),
			);
	}

	/**
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @param array<string, mixed> $slice   Slice payload.
	 * @return void
	 */
	private static function update_status_from_slice( $post_id, $lang, array $slice ) {
		self::update_status(
			$post_id,
			$lang,
			array(
				'status'         => 'running',
				'phase'          => (string) ( $slice['phase'] ?? '' ),
				'phase_progress' => (string) ( $slice['phase_progress'] ?? '' ),
				'message'        => (string) ( $slice['message'] ?? __( 'ترجمه در حال انجام است…', 'polymart-ai' ) ),
				'error'          => '',
			)
		);
	}

	/**
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @param array<string, mixed> $slice   Final slice payload.
	 * @return void
	 */
	private static function mark_complete( $post_id, $lang, array $slice ) {
		Post_Translator::release_translation_lock( $post_id, $lang, true );

		self::update_status(
			$post_id,
			$lang,
			array(
				'status'         => 'complete',
				'phase'          => (string) ( $slice['phase'] ?? 'complete' ),
				'phase_progress' => '',
				'message'        => (string) ( $slice['message'] ?? __( 'ترجمه تکمیل شد.', 'polymart-ai' ) ),
				'completed_at'   => time(),
				'error'          => '',
			)
		);

		\PolymartAI\Activity_Logger::log(
			'info',
			sprintf(
				/* translators: 1: post ID, 2: language code */
				__( 'متاباکس AS — #%1$d (%2$s): ترجمه تکمیل شد.', 'polymart-ai' ),
				$post_id,
				$lang
			),
			array( 'post_id' => $post_id, 'lang' => $lang, 'source' => 'metabox_as' )
		);
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @param string $message Error message.
	 * @return void
	 */
	private static function mark_failed( $post_id, $lang, $message ) {
		Post_Translator::release_translation_lock( $post_id, $lang, true );

		self::update_status(
			$post_id,
			$lang,
			array(
				'status'  => 'failed',
				'message' => $message,
				'error'   => $message,
			)
		);

		\PolymartAI\Activity_Logger::log(
			'error',
			sprintf(
				/* translators: 1: post ID, 2: language code, 3: error message */
				__( 'متاباکس AS — #%1$d (%2$s): خطا — %3$s', 'polymart-ai' ),
				$post_id,
				$lang,
				$message
			),
			array( 'post_id' => $post_id, 'lang' => $lang, 'source' => 'metabox_as' )
		);
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return void
	 */
	private static function chain_next( $post_id, $lang ) {
		if (
			Translation_Scheduler_Coordinator::is_halted()
			|| Translation_Scheduler_Coordinator::should_defer_metabox_work()
		) {
			return;
		}

		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		// Don't chain a skipped/already-finished post.
		if ( $post_id > 0 && \PolymartAI\Activity_Logger::is_job_post_skipped( $post_id ) ) {
			return;
		}

		if ( self::has_pending_or_running( $post_id, $lang ) ) {
			return;
		}

		$action_id = self::schedule_slice( $post_id, $lang, self::CHAIN_DELAY_SEC, self::$current_action_id );

		if ( $action_id > 0 ) {
			self::run_queue_inline( false );
		} else {
			$message = __( 'خطا در ثبت صف Action Scheduler — زنجیره slice متوقف شد.', 'polymart-ai' );
			update_post_meta( $post_id, '_polymart_ai_elementor_error_' . sanitize_key( (string) $lang ), $message );
			self::update_status(
				$post_id,
				$lang,
				array(
					'status'  => 'failed',
					'message' => $message,
					'error'   => $message,
				)
			);
		}
	}

	/**
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function maybe_advance_batch( $post_id ) {
		$post_id = absint( $post_id );
		$batch   = get_post_meta( $post_id, self::BATCH_META_KEY, true );

		if ( ! is_array( $batch ) || empty( $batch['langs'] ) || 'running' !== (string) ( $batch['status'] ?? '' ) ) {
			return;
		}

		$langs = array_values( array_map( 'strval', (array) $batch['langs'] ) );
		$index = absint( $batch['index'] ?? 0 ) + 1;

		if ( $index >= count( $langs ) ) {
			delete_post_meta( $post_id, self::BATCH_META_KEY );

			return;
		}

		$batch['index'] = $index;
		update_post_meta( $post_id, self::BATCH_META_KEY, $batch );

		self::start_translation(
			$post_id,
			sanitize_key( $langs[ $index ] ),
			array(
				'force'  => ! empty( $batch['force'] ),
				'unlock' => true,
			)
		);
	}

	/**
	 * Build a lightweight polling payload for the metabox UI.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return array<string, mixed>
	 */
	public static function build_poll_response( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$status      = self::get_status( $post_id, $lang );
		$worker_mode = ! empty( $status['fallback'] ) ? 'poll_inline' : 'as';
		$state       = (string) ( $status['status'] ?? 'idle' );
		$running     = self::has_pending_or_running( $post_id, $lang );
		$meta_active = 'running' === $state;

		$gaps = Post_Translator::get_translation_gaps( $post_id, $lang );
		$done = 'complete' === $state
			|| ( ! $running && ! $meta_active && 'translated' === (string) ( $gaps['status'] ?? '' ) && empty( $gaps['missing'] ) );

		if ( 'failed' === $state ) {
			$done = false;
		}

		$partial = Post_Translator::get_job_partial_state( $post_id, $lang );
		$phase   = (string) ( $status['phase'] ?? ( $partial['phase'] ?? '' ) );
		$progress = (string) ( $status['phase_progress'] ?? '' );

		if ( '' === $progress && 'elementor' === $phase ) {
			$progress = Post_Translator::format_elementor_job_progress_marker( $post_id, $lang, is_array( $partial ) ? $partial : array() );
		}

		return array(
			'queued'         => $running,
			'done'           => $done,
			'failed'         => 'failed' === $state,
			'status'         => $state,
			'worker_mode'    => $worker_mode,
			'phase'          => $phase,
			'phase_progress' => $progress,
			'message'        => (string) ( $status['message'] ?? '' ),
			'error'          => (string) ( $status['error'] ?? '' ),
			'updated_at'     => absint( $status['updated_at'] ?? 0 ),
			'batch'          => get_post_meta( $post_id, self::BATCH_META_KEY, true ),
		);
	}

	/**
	 * Kick the AS queue runner for metabox actions.
	 *
	 * @param bool $force_inline Whether to nudge even when no actions processed.
	 * @return int
	 */
	public static function run_queue_inline( $force_inline = false ) {
		if ( ! self::is_available() || ! class_exists( 'ActionScheduler_QueueRunner' ) ) {
			return 0;
		}

		$time_limit = (int) apply_filters( 'polymart_ai_metabox_as_queue_time_limit', 90 );
		$batch_size = (int) apply_filters( 'polymart_ai_metabox_as_queue_batch_size', 1 );

		$time_filter = static function () use ( $time_limit ) {
			return max( 25, min( 120, $time_limit ) );
		};
		$batch_filter = static function () use ( $batch_size ) {
			return max( 1, min( 3, $batch_size ) );
		};

		add_filter( 'action_scheduler_queue_runner_time_limit', $time_filter, 50 );
		add_filter( 'action_scheduler_queue_runner_batch_size', $batch_filter, 50 );

		try {
			$runner = \ActionScheduler_QueueRunner::instance();
			$done   = (int) $runner->run( 'PolymartAI-Metabox' );
		} catch ( \Throwable $e ) {
			$done = 0;
		}

		remove_filter( 'action_scheduler_queue_runner_time_limit', $time_filter, 50 );
		remove_filter( 'action_scheduler_queue_runner_batch_size', $batch_filter, 50 );

		if ( $done <= 0 && $force_inline ) {
			self::run_metabox_batch_inline_fallback();
		}

		return $done;
	}

	/**
	 * Direct fallback when the AS HTTP runner does not pick up pending metabox actions.
	 *
	 * @return void
	 */
	private static function run_metabox_batch_inline_fallback() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return;
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
				'orderby'  => 'date',
				'order'    => 'ASC',
			)
		);

		if ( empty( $actions ) ) {
			return;
		}

		$action = reset( $actions );

		if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
			return;
		}

		$args = $action->get_args();

		self::handle_metabox_action(
			absint( $args['post_id'] ?? 0 ),
			sanitize_key( (string) ( $args['lang'] ?? '' ) ),
			(string) ( $args['n'] ?? '' )
		);
	}

	/**
	 * @return int
	 */
	private static function resolve_current_action_id() {
		if ( self::$current_action_id > 0 ) {
			return self::$current_action_id;
		}

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$running = as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK,
					'group'    => self::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_RUNNING,
					'per_page' => 1,
				)
			);

			if ( ! empty( $running ) ) {
				$action = reset( $running );

				if ( is_object( $action ) && method_exists( $action, 'get_id' ) ) {
					return absint( $action->get_id() );
				}
			}
		}

		return 0;
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @param int    $max_age Max running age in seconds.
	 * @return int
	 */
	private static function recover_stale_running_actions( $post_id, $lang, $max_age ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return 0;
		}

		$recovered = 0;
		$actions   = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_RUNNING,
				'per_page' => 20,
			)
		);

		foreach ( $actions as $action ) {
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) || ! method_exists( $action, 'get_id' ) ) {
				continue;
			}

			$args = $action->get_args();

			if ( absint( $args['post_id'] ?? 0 ) !== absint( $post_id ) || sanitize_key( (string) ( $args['lang'] ?? '' ) ) !== sanitize_key( (string) $lang ) ) {
				continue;
			}

			$store = \ActionScheduler_Store::instance();
			$date  = $store->get_date( $action->get_id() );

			if ( ! $date || ( time() - $date->getTimestamp() ) < max( 30, absint( $max_age ) ) ) {
				continue;
			}

			try {
				$store->mark_failure( $action->get_id() );
				++$recovered;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}

		if ( $recovered > 0 ) {
			Post_Translator::release_translation_lock( $post_id, $lang, true );
			self::chain_next( $post_id, $lang );
		}

		return $recovered;
	}

	/**
	 * Mark the in-flight AS action failed when PHP exits without finishing the callback.
	 *
	 * @return void
	 */
	private static function finalize_interrupted_action() {
		if ( self::$current_action_id <= 0 ) {
			self::$current_action_id = self::resolve_current_action_id();
		}

		if ( self::$current_action_id <= 0 || ! class_exists( 'ActionScheduler_Store' ) ) {
			return;
		}

		try {
			\ActionScheduler_Store::instance()->mark_failure( self::$current_action_id );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}
	}

	/**
	 * Release locks and re-queue when the AS callback is interrupted.
	 *
	 * @return void
	 */
	public static function handler_shutdown_recovery() {
		if ( self::$handler_clean_exit || ! is_array( self::$active_job ) ) {
			return;
		}

		$post_id = absint( self::$active_job['post_id'] ?? 0 );
		$lang    = sanitize_key( (string) ( self::$active_job['lang'] ?? '' ) );

		if ( $post_id <= 0 || '' === $lang ) {
			return;
		}

		if ( Translation_Scheduler_Coordinator::is_halted() || Translation_Scheduler_Coordinator::should_defer_metabox_work() ) {
			Post_Translator::release_translation_lock( $post_id, $lang, true );
			self::finalize_interrupted_action();
			Translation_Scheduler_Coordinator::release_global_worker( Translation_Scheduler_Coordinator::OWNER_METABOX );
			\PolymartAI\Activity_Logger::end_metabox_as_worker();

			return;
		}

		$failure_message = self::$last_auth_failure_message;

		if ( '' === $failure_message && function_exists( 'error_get_last' ) ) {
			$last_error = error_get_last();

			if ( is_array( $last_error ) && ! empty( $last_error['message'] ) ) {
				$failure_message = (string) $last_error['message'];
			}
		}

		self::log_auth_failure_if_applicable( $post_id, $lang, $failure_message );

		Post_Translator::release_translation_lock( $post_id, $lang, true );
		self::finalize_interrupted_action();

		$status_message = __( 'پردازش قبلی قطع شد — ادامه با بخش بعدی…', 'polymart-ai' );

		if ( '' !== $failure_message && self::is_auth_related_message( $failure_message ) ) {
			$status_message = $failure_message;
		}

		self::update_status(
			$post_id,
			$lang,
			array(
				'status'  => 'running',
				'message' => $status_message,
				'error'   => self::is_auth_related_message( $failure_message ) ? $failure_message : '',
			)
		);

		self::chain_next( $post_id, $lang );

		Translation_Scheduler_Coordinator::release_global_worker( Translation_Scheduler_Coordinator::OWNER_METABOX );
		\PolymartAI\Activity_Logger::end_metabox_as_worker();
	}

	/**
	 * Whether a message looks like a permission, nonce, or capability failure.
	 *
	 * @param string $message Error text.
	 * @return bool
	 */
	private static function is_auth_related_message( $message ) {
		$message = trim( (string) $message );

		if ( '' === $message ) {
			return false;
		}

		return (bool) preg_match(
			'/permission|nonce|forbidden|unauthorized|capabilit|access denied|not allowed|اجازه|دسترسی|مجوز|امنیت/i',
			$message
		);
	}

	/**
	 * Persist auth/security failures on the post for metabox polling diagnostics.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @param string $message Error message.
	 * @return void
	 */
	private static function log_auth_failure_if_applicable( $post_id, $lang, $message ) {
		$message = trim( (string) $message );

		if ( '' === $message || ! self::is_auth_related_message( $message ) ) {
			return;
		}

		self::$last_auth_failure_message = $message;

		update_post_meta( $post_id, '_polymart_ai_elementor_error_' . sanitize_key( (string) $lang ), $message );

		\PolymartAI\Activity_Logger::log(
			'error',
			sprintf(
				/* translators: 1: post ID, 2: language code, 3: error message */
				__( 'مatabox AS — #%1$d (%2$s): خطای دسترسی Worker — %3$s', 'polymart-ai' ),
				absint( $post_id ),
				sanitize_key( (string) $lang ),
				$message
			),
			array(
				'post_id' => absint( $post_id ),
				'lang'    => sanitize_key( (string) $lang ),
				'source'  => 'metabox_as_auth',
			)
		);
	}
}
