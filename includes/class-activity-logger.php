<?php
/**
 * Activity logs facade — implementation lives in Activity_Logger/Traits/.
 *
 * @package PolymartAI
 */

namespace PolymartAI;

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

require_once __DIR__ . '/Activity_Logger/trait-loader.php';

defined( 'ABSPATH' ) || exit;

/**
 * Class Activity_Logger
 */
final class Activity_Logger {

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
