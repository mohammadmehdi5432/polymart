<?php
/**
 * Elementor Job (auto-split composite).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\Elementor\Job;

defined( 'ABSPATH' ) || exit;

trait Trait_Job {
	use Trait_Job_Cursor;
	use Trait_Job_State;
	use Trait_Job_Chunk;
	use Trait_Job_Runner;
	use Trait_Job_Gap_Fill;
	use Trait_Job_Persist;
}
