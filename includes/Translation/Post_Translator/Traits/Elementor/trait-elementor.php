<?php
/**
 * Elementor (auto-split composite).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\Elementor;

defined( 'ABSPATH' ) || exit;

trait Trait_Elementor {
	use Trait_Storage;
	use Trait_Chunking;
	use Job\Trait_Job;
	use Trait_Walk;
}
