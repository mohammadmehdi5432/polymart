<?php
/**
 * Activity_Logger Worker_Lock (auto-split).
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

trait Trait_Worker_Lock {

	private static function get_loopback_token() {
		$token = get_option( self::LOOPBACK_TOKEN_OPTION, '' );

		if ( ! is_string( $token ) || strlen( $token ) < 32 ) {
			$token = wp_generate_password( 43, false, false );
			update_option( self::LOOPBACK_TOKEN_OPTION, $token, false );
		}

		return $token;
	}

	private static function is_step_lock_held_fresh() {
		$lock  = get_transient( self::STEP_LOCK_KEY );
		$claim = absint( get_option( self::STEP_LOCK_CLAIM_KEY, 0 ) );
		$stamp = max( $lock ? absint( $lock ) : 0, $claim );

		if ( $stamp <= 0 ) {
			return false;
		}

		return ( time() - $stamp ) < self::STEP_LOCK_TTL;
	}

	private static function maybe_release_stale_step_lock() {
		$lock  = get_transient( self::STEP_LOCK_KEY );
		$claim = absint( get_option( self::STEP_LOCK_CLAIM_KEY, 0 ) );

		if ( ! $lock && $claim <= 0 ) {
			return false;
		}

		$job = self::get_job_raw();

		if ( self::is_elementor_progress_stalled( $job ) ) {
			$lang    = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
			$post_id = absint( $job['partial_post_id'] ?? 0 ) ?: absint( $job['current_post_id'] ?? 0 );

			if ( $post_id > 0 && '' !== $lang ) {
				Post_Translator::release_stale_translation_lock( $post_id, $lang );
			}

			self::force_unlock_step_now();

			return true;
		}

		$lock_stamp = $lock ? absint( $lock ) : $claim;
		$lock_age   = time() - $lock_stamp;
		$job        = self::get_job_raw();
		$current    = absint( $job['current_post_id'] ?? 0 );
		$last       = max(
			absint( $job['last_cron_at'] ?? 0 ),
			absint( $job['worker_heartbeat_at'] ?? 0 ),
			$lock_stamp
		);
		$idle_for = $last > 0 ? ( time() - $last ) : PHP_INT_MAX;

		// Hard stop: no heartbeat/tick for a full lock TTL means the worker is dead,
		// even if current_post_id is still set (killed mid-request).
		$worker_dead = $idle_for >= self::STEP_LOCK_TTL;

		// Between items: lock held, no active post, and no completed tick recently.
		$pick_started = absint( $job['pick_started_at'] ?? 0 );
		$pick_stuck     = ( 0 === $current )
			&& $pick_started > 0
			&& ( time() - $pick_started ) >= self::PICK_STALL_SEC;

		$idle_orphan = ( 0 === $current )
			&& $idle_for >= self::LOCK_IDLE_ORPHAN_SEC
			&& $lock_age >= self::LOCK_IDLE_ORPHAN_SEC
			&& ! self::is_bulk_worker_lively( self::LOCK_IDLE_ORPHAN_SEC );

		// Living worker stopped touching the lock for the full TTL.
		$ttl_expired = $lock_age >= self::STEP_LOCK_TTL;

		if ( $pick_stuck ) {
			$lang = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
			self::recover_stalled_job_picker( $job, $lang, false );
		}

		if ( ! $worker_dead && ! $idle_orphan && ! $ttl_expired && ! $pick_stuck ) {
			return false;
		}

		self::release_step_lock();

		$job['step_started_at'] = null;
		$job['current_post_id'] = null;
		self::save_job( $job );

		return true;
	}

	private static function force_release_step_lock_if_idle( $idle_sec = null ) {
		$idle_sec = null === $idle_sec
			? self::LOCK_FORCE_IDLE_SEC
			: max( self::LOCK_FORCE_IDLE_SEC, absint( $idle_sec ) );

		$job     = self::get_job_raw();
		$current = absint( $job['current_post_id'] ?? 0 );
		$partial = absint( $job['partial_post_id'] ?? 0 );

		// Elementor burst can run many API calls in one request — allow a longer silence window.
		if ( $partial > 0 && self::should_prioritize_elementor_partial( $job ) ) {
			$idle_sec = max( $idle_sec, (int) apply_filters( 'polymart_ai_elementor_burst_lock_idle_sec', 150 ) );
		}

		// Actively translating a known post — never force-unlock before full TTL.
		if ( $current > 0 ) {
			$idle_sec = max( $idle_sec, self::STEP_LOCK_TTL );
		}

		$last = max(
			absint( $job['last_cron_at'] ?? 0 ),
			absint( $job['worker_heartbeat_at'] ?? 0 )
		);
		$idle_for = $last > 0 ? ( time() - $last ) : PHP_INT_MAX;

		if ( $idle_for < $idle_sec && ( get_transient( self::STEP_LOCK_KEY ) || get_option( self::STEP_LOCK_CLAIM_KEY ) ) ) {
			// Still recent activity — only use normal stale logic.
			return self::maybe_release_stale_step_lock();
		}

		if ( $idle_for < $idle_sec ) {
			return false;
		}

		$had_lock = (bool) get_transient( self::STEP_LOCK_KEY ) || (bool) get_option( self::STEP_LOCK_CLAIM_KEY );
		self::release_step_lock();

		if ( $had_lock || $current > 0 ) {
			$job['step_started_at'] = null;
			$job['current_post_id'] = null;
			self::save_job( $job );

			return true;
		}

		return false;
	}

	private static function touch_step_lock() {
		$now = time();

		if ( ! get_transient( self::STEP_LOCK_KEY ) && ! get_option( self::STEP_LOCK_CLAIM_KEY ) ) {
			return;
		}

		set_transient( self::STEP_LOCK_KEY, $now, self::STEP_LOCK_TTL );
		update_option( self::STEP_LOCK_CLAIM_KEY, $now, false );
	}

	public static function get_job_for_as_debug() {
		return self::get_job_raw();
	}

	public static function force_unlock_step_now() {
		$job = self::get_job_raw();

		// Only clear the global step lock. Per-post translation locks are owner-bound
		// and must not be stolen from a live Elementor slice mid-API-call.
		self::release_step_lock();

		$job['current_post_id'] = null;
		$job['step_started_at'] = null;
		$job['worker_heartbeat_at'] = time();
		self::save_job( $job );
	}

	public static function release_stale_locks_for_as( $idle_sec = 60 ) {
		$idle_sec = max( 30, absint( $idle_sec ) );

		if ( self::is_bulk_worker_lively( $idle_sec ) ) {
			return false;
		}

		$job  = self::get_job_raw();
		$last = max(
			absint( $job['last_cron_at'] ?? 0 ),
			absint( $job['worker_heartbeat_at'] ?? 0 ),
			absint( $job['step_started_at'] ?? 0 )
		);
		$age = $last > 0 ? ( time() - $last ) : PHP_INT_MAX;

		$has_lock = (bool) get_transient( self::STEP_LOCK_KEY ) || (bool) get_option( self::STEP_LOCK_CLAIM_KEY );

		if ( $age < $idle_sec && $has_lock ) {
			return self::maybe_release_stale_step_lock();
		}

		if ( $age < $idle_sec && ! $has_lock ) {
			return false;
		}

		self::force_unlock_step_now();

		$job     = self::get_job_raw();
		$lang    = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
		$post_id = absint( $job['partial_post_id'] ?? 0 ) ?: absint( $job['current_post_id'] ?? 0 );

		if ( $post_id > 0 && '' !== $lang ) {
			Post_Translator::release_stale_translation_lock( $post_id, $lang, $idle_sec );
		}

		self::log(
			'warning',
			sprintf(
				/* translators: %d: idle seconds */
				__( 'قفل ترجمه پس از %dث سکوت آزاد شد (بازیابی Action Scheduler).', 'polymart-ai' ),
				$idle_sec
			)
		);

		return true;
	}

	private static function acquire_step_lock() {
		self::maybe_release_stale_step_lock();

		$now    = time();
		$claim  = absint( get_option( self::STEP_LOCK_CLAIM_KEY, 0 ) );
		$lock   = get_transient( self::STEP_LOCK_KEY );
		$stamp  = max( $claim, $lock ? absint( $lock ) : 0 );

		if ( $stamp > 0 && ( $now - $stamp ) < self::STEP_LOCK_TTL ) {
			// Mirror transient for UI if claim exists alone.
			if ( ! $lock && $claim > 0 ) {
				set_transient( self::STEP_LOCK_KEY, $claim, self::STEP_LOCK_TTL );
			}

			return false;
		}

		if ( $stamp > 0 ) {
			delete_option( self::STEP_LOCK_CLAIM_KEY );
			delete_transient( self::STEP_LOCK_KEY );
		}

		// add_option is atomic in MySQL — only one worker wins.
		if ( ! add_option( self::STEP_LOCK_CLAIM_KEY, (string) $now, '', 'no' ) ) {
			return false;
		}

		set_transient( self::STEP_LOCK_KEY, $now, self::STEP_LOCK_TTL );

		return true;
	}

	public static function release_step_lock() {
		delete_transient( self::STEP_LOCK_KEY );
		delete_option( self::STEP_LOCK_CLAIM_KEY );
	}

}
