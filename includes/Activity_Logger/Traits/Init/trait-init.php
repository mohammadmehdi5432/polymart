<?php
/**
 * Activity_Logger Init (auto-split).
 *
 * @package PolymartAI\Activity_Logger\Traits
 */

namespace PolymartAI\Activity_Logger\Traits\Init;

use PolymartAI\Activity_Logger\Job_Action_Scheduler;
use PolymartAI\Activity_Logger\Metabox_Action_Scheduler;
use PolymartAI\Activity_Logger\Translation_Scheduler_Coordinator;
use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\Content\Menu_Translator;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Pipeline\Translation_Query;


defined( 'ABSPATH' ) || exit;

trait Trait_Init {

	public static function init() {
		Job_Action_Scheduler::init();
		Metabox_Action_Scheduler::init();

		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'maybe_heal_background_worker' ) );
		add_action( self::CRON_PULSE_HOOK, array( __CLASS__, 'maybe_heal_background_worker' ) );
		add_action( 'action_scheduler_run_queue', array( __CLASS__, 'maybe_heal_on_as_queue' ), 999 );
		add_action( 'polymart_ai_before_ai_http', array( __CLASS__, 'on_ai_http_heartbeat' ) );
		add_action( 'polymart_ai_during_ai_http', array( __CLASS__, 'on_ai_http_heartbeat' ) );
		add_action( 'polymart_ai_worker_heartbeat', array( __CLASS__, 'on_ai_http_heartbeat' ) );
		// Legacy endpoint: nudge AS instead of HTTP/CLI chains.
		add_action( 'wp_ajax_' . self::LOOPBACK_ACTION, array( __CLASS__, 'handle_loopback_tick' ) );
		add_action( 'wp_ajax_nopriv_' . self::LOOPBACK_ACTION, array( __CLASS__, 'handle_loopback_tick' ) );

		if ( wp_doing_cron() ) {
			self::maybe_heal_background_worker();
		} else {
			self::maybe_ensure_pulse_quietly();
		}
	}

	/**
	 * Heal stalled bulk jobs whenever Action Scheduler runs (wp-cron / CLI / admin).
	 *
	 * @return void
	 */
	public static function maybe_heal_on_as_queue() {
		if ( Translation_Scheduler_Coordinator::is_halted() || ! self::is_bulk_job_running() ) {
			return;
		}

		if ( self::maybe_auto_cancel_abandoned_bulk_job() ) {
			return;
		}

		$age = self::get_bulk_worker_activity_age();

		if ( $age < self::WORKER_FORCE_RECOVER_SEC ) {
			return;
		}

		self::$trusted_as_tick = true;

		try {
			// AS queue context may run heavy recovery; do not also kick (double Elementor burst).
			self::force_recover_stalled_bulk_worker( 'as_queue', true );
		} finally {
			self::$trusted_as_tick = false;
		}
	}

}
