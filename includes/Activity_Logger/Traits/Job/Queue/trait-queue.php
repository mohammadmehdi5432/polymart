<?php
/**
 * Job Queue (auto-split composite).
 *
 * @package PolymartAI\Activity_Logger\Traits
 */

namespace PolymartAI\Activity_Logger\Traits\Job\Queue;

defined( 'ABSPATH' ) || exit;

trait Trait_Queue {
	use Trait_Queue_Pick;
	use Trait_Queue_Run;
	use Trait_Queue_Metrics;
}
