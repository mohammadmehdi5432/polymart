<?php
/**
 * Action Scheduler driver for Elementor metabox translation (single post / language).
 *
 * AJAX only queues work and returns immediately; heavy AI slices run in AS callbacks
 * so admin-ajax never hits the web-server timeout.
 *
 * @package PolymartAI
 */

namespace PolymartAI;

use PolymartAI\Translation\Post_Translator;

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
		if ( ! self::is_available() ) {
			return;
		}

		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return;
		}

		self::recover_stale_running_actions( $post_id, $lang, self::STALE_RUNNING_SEC );

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return;
		}

		$store = \ActionScheduler_Store::instance();

		foreach ( array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ) as $store_status ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK,
					'group'    => self::GROUP,
					'status'   => $store_status,
					'per_page' => 50,
				)
			);

			foreach ( $actions as $action ) {
				if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) || ! method_exists( $action, 'get_id' ) ) {
					continue;
				}

				$args = $action->get_args();

				if ( absint( $args['post_id'] ?? 0 ) !== $post_id || sanitize_key( (string) ( $args['lang'] ?? '' ) ) !== $lang ) {
					continue;
				}

				try {
					$store->cancel_action( $action->get_id() );
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				}
			}
		}
	}

	/**
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

		$status = (string) ( self::get_status( $post_id, $lang )['status'] ?? '' );

		if ( 'running' === $status ) {
			return true;
		}

		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
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

				if ( absint( $args['post_id'] ?? 0 ) === $post_id && sanitize_key( (string) ( $args['lang'] ?? '' ) ) === $lang ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Queue background translation for one post/language pair.
	 *
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @param array<string, mixed> $options force, unlock.
	 * @return true|\WP_Error
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

		if ( $unlock ) {
			self::force_unlock_post( $post_id, $lang );
		}

		if ( ! $force && self::has_pending_or_running( $post_id, $lang ) ) {
			return true;
		}

		if ( $force ) {
			self::cancel_pending( $post_id, $lang );
		}

		if ( $force ) {
			Post_Translator::clear_post_language_translations( $post_id, $lang );
			Post_Translator::clear_job_partial_state( $post_id, $lang );
			delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
		}

		if ( ! Activity_Logger::is_bulk_job_running() ) {
			Activity_Logger::clear_job_api_cooldown( false );
		}

		Post_Translator::repair_stale_elementor_job_state( $post_id, $lang );
		Post_Translator::unblock_elementor_chunk_queue( $post_id, $lang );

		// If Action Scheduler (or WP-Cron) is jammed, break stale plugin locks before enqueue.
		try {
			Activity_Logger::release_stale_locks_for_as( 60 );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		// Best-effort queue nudge: if AS runners are stuck behind migration/runner locks,
		// attempt to run due actions (and one inline fallback) before enqueueing another row.
		try {
			self::run_queue_inline( true );
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

		$action_id = self::schedule_slice( $post_id, $lang, 0 );

		// DB insertion verification: if AS could not create a row (table locked / migration),
		// immediately fall back to an inline slice so the metabox poll loop can act as the worker.
		if ( $action_id <= 0 ) {
			$inline = self::run_inline_fallback_slice( $post_id, $lang, 'enqueue_failed' );

			if ( is_wp_error( $inline ) ) {
				return $inline;
			}

			return true;
		}

		Activity_Logger::log(
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

		return true;
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

		// First try: let AS queue runner process due actions; if it processes nothing,
		// force one direct pending metabox action inline.
		try {
			self::run_queue_inline( true );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		// Second try: if still stuck, run one job slice directly.
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
	 * @param int    $delay_sec Delay before run.
	 * @return int Action ID or 0.
	 */
	public static function schedule_slice( $post_id, $lang, $delay_sec = 0 ) {
		if ( ! self::is_available() || ! function_exists( 'as_schedule_single_action' ) ) {
			return 0;
		}

		$post_id   = absint( $post_id );
		$lang      = sanitize_key( (string) $lang );
		$delay_sec = max( 0, min( 30, absint( $delay_sec ) ) );

		if ( $post_id <= 0 || '' === $lang ) {
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

		self::$handler_clean_exit = false;
		self::$current_action_id  = self::resolve_current_action_id();
		self::$active_job         = array(
			'post_id' => $post_id,
			'lang'    => $lang,
		);

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 200 );
		}

		register_shutdown_function( array( __CLASS__, 'handler_shutdown_recovery' ) );

		try {
			Activity_Logger::bootstrap_job_worker_context();
			Activity_Logger::on_ai_http_heartbeat();

			if ( ! Activity_Logger::is_bulk_job_running() ) {
				Activity_Logger::clear_job_api_cooldown( false );
			}

			$recovered = self::recover_stale_running_actions( $post_id, $lang, self::STALE_RUNNING_SEC );

			if ( $recovered > 0 ) {
				Activity_Logger::log(
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
				if (
					Post_Translator::is_recoverable_job_slice_error( $result )
					|| Post_Translator::is_api_transport_timeout_error( $result )
				) {
					self::update_status_from_slice(
						$post_id,
						$lang,
						array(
							'done'    => false,
							'message' => $result->get_error_message(),
							'phase'   => 'elementor',
						)
					);
					self::chain_next( $post_id, $lang );
					self::$handler_clean_exit = true;

					return;
				}

				self::mark_failed( $post_id, $lang, $result->get_error_message() );
				self::$handler_clean_exit = true;

				return;
			}

			if ( ! empty( $result['done'] ) ) {
				self::mark_complete( $post_id, $lang, $result );
				self::maybe_advance_batch( $post_id );
				self::$handler_clean_exit = true;

				return;
			}

			self::update_status_from_slice( $post_id, $lang, $result );
			self::chain_next( $post_id, $lang );
			self::$handler_clean_exit = true;
		} catch ( \Throwable $e ) {
			self::mark_failed( $post_id, $lang, $e->getMessage() );
			Post_Translator::release_translation_lock( $post_id, $lang, true );
			self::chain_next( $post_id, $lang );
			self::$handler_clean_exit = true;
		}
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

			Activity_Logger::log(
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

			$slice = Post_Translator::process_job_translation_slice( $post_id, $lang, true );
			++$slices_run;
			$last_slice = is_array( $slice ) ? $slice : array();

			if ( is_array( $slice ) && ! empty( $slice['recoverable'] ) ) {
				self::update_status_from_slice( $post_id, $lang, $last_slice );

				return $last_slice;
			}

			if ( is_wp_error( $slice ) ) {
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

		Activity_Logger::log(
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

		Activity_Logger::log(
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
		$action_id = self::schedule_slice( $post_id, $lang, self::CHAIN_DELAY_SEC );

		if ( $action_id > 0 ) {
			self::run_queue_inline( false );
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
		$status  = self::get_status( $post_id, $lang );
		$worker_mode = ! empty( $status['fallback'] ) ? 'poll_inline' : 'as';
		$state   = (string) ( $status['status'] ?? 'idle' );
		$running = self::has_pending_or_running( $post_id, $lang ) || 'running' === $state;

		$gaps = Post_Translator::get_translation_gaps( $post_id, $lang );
		$done = 'complete' === $state
			|| ( ! $running && 'translated' === (string) ( $gaps['status'] ?? '' ) && empty( $gaps['missing'] ) );

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

		Post_Translator::release_translation_lock( $post_id, $lang, true );
		self::finalize_interrupted_action();

		self::update_status(
			$post_id,
			$lang,
			array(
				'status'  => 'running',
				'message' => __( 'پردازش قبلی قطع شد — ادامه با بخش بعدی…', 'polymart-ai' ),
				'error'   => '',
			)
		);

		self::chain_next( $post_id, $lang );
	}
}
