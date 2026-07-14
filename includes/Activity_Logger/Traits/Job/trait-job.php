<?php
/**
 * Job (auto-split composite).
 *
 * @package PolymartAI\Activity_Logger\Traits
 */

namespace PolymartAI\Activity_Logger\Traits\Job;

defined( 'ABSPATH' ) || exit;

trait Trait_Job {
	use Trait_Job_Api;
	use Trait_Job_Elementor;
	use Trait_Job_Lifecycle;
	use Queue\Trait_Queue;
}
