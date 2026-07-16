<?php

/**

 * Activity logs facade — implementation lives in Activity_Logger/Traits/.

 *

 * @package PolymartAI

 */



namespace PolymartAI;



use PolymartAI\Activity_Logger\Config_Constants as Activity_Logger_Config;

use PolymartAI\Activity_Logger\Traits\Admin\Trait_Admin;

use PolymartAI\Activity_Logger\Traits\Config\Trait_Config;

use PolymartAI\Activity_Logger\Traits\Init\Trait_Init;

use PolymartAI\Activity_Logger\Traits\Job\Trait_Job;

use PolymartAI\Activity_Logger\Traits\Logging\Trait_Logging;

use PolymartAI\Activity_Logger\Traits\Reports\Trait_Reports;

use PolymartAI\Activity_Logger\Traits\Worker\Trait_Worker_Heartbeat;

use PolymartAI\Activity_Logger\Traits\Worker\Trait_Worker_Lock;

use PolymartAI\Activity_Logger\Traits\Worker\Trait_Worker_Recovery;

use PolymartAI\Activity_Logger\Traits\Worker\Trait_Worker_Runner;

use PolymartAI\Activity_Logger\Traits\Worker\Trait_Worker_Trust;



require_once __DIR__ . '/Activity_Logger/class-config-constants.php';

require_once __DIR__ . '/Activity_Logger/trait-loader.php';



defined( 'ABSPATH' ) || exit;



/**

 * Class Activity_Logger

 */

final class Activity_Logger {



	const LOG_OPTION              = Activity_Logger_Config::LOG_OPTION;

	const REPORT_OPTION           = Activity_Logger_Config::REPORT_OPTION;

	const JOB_OPTION              = Activity_Logger_Config::JOB_OPTION;

	const NOTICE_TRANSIENT        = Activity_Logger_Config::NOTICE_TRANSIENT;

	const MAX_LOG_ENTRIES         = Activity_Logger_Config::MAX_LOG_ENTRIES;

	const MAX_REPORT_ENTRIES      = Activity_Logger_Config::MAX_REPORT_ENTRIES;

	const MAX_JOB_RETRIES         = Activity_Logger_Config::MAX_JOB_RETRIES;

	const STEP_LOCK_KEY           = Activity_Logger_Config::STEP_LOCK_KEY;

	const STEP_LOCK_CLAIM_KEY     = Activity_Logger_Config::STEP_LOCK_CLAIM_KEY;

	const STEP_LOCK_TTL           = Activity_Logger_Config::STEP_LOCK_TTL;

	const LOCK_FORCE_IDLE_SEC     = Activity_Logger_Config::LOCK_FORCE_IDLE_SEC;

	const CRON_HOOK               = Activity_Logger_Config::CRON_HOOK;

	const CRON_PULSE_HOOK         = Activity_Logger_Config::CRON_PULSE_HOOK;

	const CRON_PULSE_SCHEDULE     = Activity_Logger_Config::CRON_PULSE_SCHEDULE;

	const CRON_INTERVAL_SEC       = Activity_Logger_Config::CRON_INTERVAL_SEC;

	const CRON_SAFETY_SEC         = Activity_Logger_Config::CRON_SAFETY_SEC;

	const AS_CRON_SAFETY_SEC      = Activity_Logger_Config::AS_CRON_SAFETY_SEC;

	const CRON_FAST_INTERVAL_SEC  = Activity_Logger_Config::CRON_FAST_INTERVAL_SEC;
	const CRON_HEAL_STALE_SEC     = Activity_Logger_Config::CRON_HEAL_STALE_SEC;
	const WORKER_FORCE_RECOVER_SEC = Activity_Logger_Config::WORKER_FORCE_RECOVER_SEC;
	const WORKER_ABANDON_CANCEL_SEC = Activity_Logger_Config::WORKER_ABANDON_CANCEL_SEC;
	const WORKER_ABANDON_GRACE_SEC  = Activity_Logger_Config::WORKER_ABANDON_GRACE_SEC;

	const CRON_INTERVAL_SERVER_SEC = Activity_Logger_Config::CRON_INTERVAL_SERVER_SEC;

	const CRON_STEP_BUDGET_SEC    = Activity_Logger_Config::CRON_STEP_BUDGET_SEC;

	const CRON_MAX_STEPS_PER_TICK = Activity_Logger_Config::CRON_MAX_STEPS_PER_TICK;

	const LOCK_IDLE_ORPHAN_SEC    = Activity_Logger_Config::LOCK_IDLE_ORPHAN_SEC;

	const PICK_STALL_SEC          = Activity_Logger_Config::PICK_STALL_SEC;

	const LOOPBACK_ACTION         = Activity_Logger_Config::LOOPBACK_ACTION;

	const LOOPBACK_TOKEN_OPTION   = Activity_Logger_Config::LOOPBACK_TOKEN_OPTION;

	const LOOPBACK_GATE_KEY       = Activity_Logger_Config::LOOPBACK_GATE_KEY;

	const LOOPBACK_GATE_SEC       = Activity_Logger_Config::LOOPBACK_GATE_SEC;



	use Trait_Admin;

	use Trait_Config;

	use Trait_Init;

	use Trait_Job;

	use Trait_Logging;

	use Trait_Reports;

	use Trait_Worker_Heartbeat;

	use Trait_Worker_Lock;

	use Trait_Worker_Recovery;

	use Trait_Worker_Runner;

	use Trait_Worker_Trust;



}

