<?php
/**
 * Activity_Logger configuration constants (PHP 8.1-safe; not in a trait).
 *
 * @package PolymartAI\Activity_Logger
 */

namespace PolymartAI\Activity_Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class Config_Constants
 */
final class Config_Constants {

	const LOG_OPTION           = 'polymart_ai_logs';
	const REPORT_OPTION        = 'polymart_ai_translation_report';
	const JOB_OPTION           = 'polymart_ai_translation_job';
	const NOTICE_TRANSIENT     = 'polymart_ai_admin_notice';
	const MAX_LOG_ENTRIES      = 500;
	const MAX_REPORT_ENTRIES   = 1000;
	const MAX_JOB_RETRIES      = 3;
	const STEP_LOCK_KEY        = 'polymart_ai_job_step_lock';
	/** Atomic claim option paired with the step-lock transient. */
	const STEP_LOCK_CLAIM_KEY  = 'polymart_ai_job_step_lock_claim';
	/**
	 * Absolute orphan ceiling. Living ticks refresh the lock before/during AI HTTP.
	 * Must stay above Post_Translator::JOB_REQUEST_MAX_TIMEOUT (~50s) plus overhead.
	 */
	const STEP_LOCK_TTL        = 90;
	/**
	 * ensure/kick must not force-unlock during a normal AI call.
	 * Keep slightly above JOB_REQUEST_MAX_TIMEOUT.
	 */
	const LOCK_FORCE_IDLE_SEC  = 70;
	const CRON_HOOK            = 'polymart_ai_translation_job_step';
	/**
	 * Recurring pulse — keep-alive: ensure AS action exists + run queue inline.
	 */
	const CRON_PULSE_HOOK      = 'polymart_ai_translation_job_pulse';
	const CRON_PULSE_SCHEDULE  = 'polymart_ai_every_minute';
	/** @deprecated Kept for schedule_background_worker signature compatibility. */
	const CRON_INTERVAL_SEC    = 0;
	/** Safety reschedule while a tick is mid-flight (fatal recovery). */
	const CRON_SAFETY_SEC      = 120;
	/** Faster AS fallback when inline chaining does not start the next slice. */
	const AS_CRON_SAFETY_SEC   = 3;
	/** Self-rescheduling keep-alive between AS batches (when wp-cron is available). */
	const CRON_FAST_INTERVAL_SEC = 12;
	const CRON_INTERVAL_SERVER_SEC = 1;
	/** Seconds of wall-clock work allowed per Action Scheduler / cron batch. */
	const CRON_STEP_BUDGET_SEC = 55;
	/** Max AI slices per AS action / cron batch (AI call ≤50s). */
	const CRON_MAX_STEPS_PER_TICK = 2;
	/**
	 * If last_cron_at is older than this while the step lock is held with no
	 * current_post_id, treat the lock as orphaned (between-item deadlock).
	 */
	const LOCK_IDLE_ORPHAN_SEC = 30;
	/** Max seconds allowed in catalog pick/scan before force-unlock. */
	const PICK_STALL_SEC         = 75;
	/** Legacy admin-ajax action — now only nudges Action Scheduler. */
	const LOOPBACK_ACTION      = 'polymart_ai_job_loopback';
	const LOOPBACK_TOKEN_OPTION = 'polymart_ai_job_loopback_token';
	const LOOPBACK_GATE_KEY    = 'polymart_ai_job_loopback_gate';
	const LOOPBACK_GATE_SEC    = 1;
}
