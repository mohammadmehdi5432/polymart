<?php
/**
 * Activity_Logger Config State (auto-split).
 *
 * @package PolymartAI\Activity_Logger\Traits
 */

namespace PolymartAI\Activity_Logger\Traits\Config;


defined( 'ABSPATH' ) || exit;

trait Trait_Config_State {

	/**
	 * True while a token-authenticated loopback tick is executing.
	 * @var bool
	 */
	private static $trusted_loopback_tick = false;
	/**
	 * True while the shared worker pipeline is executing.
	 * @var bool
	 */
	private static $trusted_job_tick = false;
	/**
	 * True while an Action Scheduler slice callback is executing.
	 * @var bool
	 */
	private static $trusted_as_tick = false;
	/**
	 * When true, run_inline_worker_tick may execute from admin REST (ensure/kick).
	 * @var bool
	 */
	private static $trusted_admin_worker = false;
	/**
	 * True while the metabox Elementor AS callback is executing.
	 * @var bool
	 */
	private static $trusted_metabox_as_tick = false;
	/**
	 * Prevent nested Elementor burst recursion (run_queue_inline ↔ run_pinned).
	 * @var bool
	 */
	private static $elementor_burst_inflight = false;
	/**
	 * Finish all remaining Elementor API batches for the pinned post in one HTTP request.
	 * @var bool
	 */
	private static $elementor_page_burst = false;
	/**
	 * When true, process_background_step will not re-enqueue (AS handler chains).
	 * @var bool
	 */
	private static $skip_chain_after_tick = false;
}
