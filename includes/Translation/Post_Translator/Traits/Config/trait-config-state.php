<?php
/**
 * Post_Translator Config State (auto-split).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\Config;


defined( 'ABSPATH' ) || exit;

trait Trait_Config_State {

	/**
	 * While true, async invalidation hooks must not delete stored translations.
	 * @var bool
	 */
	private static $is_persisting_translations = false;
	/**
	 * Per-request token identifying the current translation worker.
	 * @var string|null
	 */
	private static $translation_lock_token = null;
	/**
	 * Per-request cache for translation status lookups.
	 * @var array<string, string>
	 */
	private static $translation_status_cache = array();
	/**
	 * Per-request cache for discovered meta scans.
	 * @var array<int, array<string, string>>
	 */
	private static $discovered_meta_cache = array();
	/**
	 * Per-request cache for Elementor Persian text probes.
	 * @var array<int, bool>
	 */
	private static $elementor_persian_cache = array();
	/**
	 * Per-request cache for Elementor source hashes keyed by post ID.
	 * @var array<int, string>
	 */
	private static $elementor_source_hash_cache = array();
	/**
	 * Previous `_elementor_data` value captured before meta updates (hash compare).
	 * @var array<int, mixed>
	 */
	private static $elementor_data_prev_values = array();
	/**
	 * Per-request cache for whether a stored Elementor translation is current.
	 * @var array<string, bool>
	 */
	private static $elementor_current_cache = array();
	/**
	 * Per-request cache for extracted Elementor plain text.
	 * @var array<int, string>
	 */
	private static $elementor_plain_text_cache = array();
	/**
	 * Per-request cache for Persian text left in stored Elementor companions.
	 * @var array<string, string>
	 */
	private static $stored_elementor_persian_cache = array();
	/**
	 * Per-request cache for stored `_elementor_data_{lang}` companions.
	 * @var array<string, string|false>
	 */
	private static $stored_elementor_json_cache = array();
	/**
	 * Per-request cache for storefront Elementor swap eligibility.
	 * @var array<string, bool>
	 */
	private static $elementor_storefront_serve_cache = array();
	/**
	 * Per-request memoization for should_serve_stored_translation().
	 * @var array<string, bool>
	 */
	private static $should_serve_cache = array();
	/**
	 * Elementor JSON keys that may contain user-facing Persian text.
	 * @var array<string, true>
	 */
	private static $elementor_text_keys = array(
		'story_text'          => true,
		'button_text'         => true,
		'customer_label'      => true,
		'partner_label'       => true,
		'search_placeholder'  => true,
		'search_brand_name'   => true,
		'no_results_message'  => true,
		'title'               => true,
		'editor'              => true,
		'text'                => true,
		'heading'             => true,
		'subtitle'            => true,
		'btn_text'            => true,
		'link_text'           => true,
		'placeholder'         => true,
		'html'                => true,
		'label_description'   => true,
		'label_attributes'    => true,
		'label_video'         => true,
		'label_customer_home' => true,
		'label_satisfaction'  => true,
		'content'             => true,
		'description'         => true,
		'message'             => true,
		'caption'             => true,
		'alt'                 => true,
		'alt_text'            => true,
		'image_alt'           => true,
		'image_url'           => true,
		'image_link'          => true,
		'tab_title'           => true,
		'tab_content'         => true,
		'tab_description'     => true,
		'accordion_title'     => true,
		'accordion_content'   => true,
		'accordion_description' => true,
		'list_title'          => true,
		'list_content'        => true,
		'item_title'          => true,
		'item_description'    => true,
		'item_text'           => true,
		'banner_title'        => true,
		'banner_subtitle'     => true,
		'banner_content'      => true,
		'banner_text'         => true,
		'label'               => true,
		'prefix'              => true,
		'suffix'              => true,
		'after_text'          => true,
		'before_text'         => true,
		'button_label'        => true,
		'read_more_text'      => true,
		'view_more_text'      => true,
		'price_text'          => true,
		'sale_text'           => true,
		'hotspot_label'       => true,
		'tooltip_content'     => true,
		'popup_title'         => true,
		'popup_content'       => true,
		'form_title'          => true,
		'form_description'    => true,
		'field_label'         => true,
		'field_placeholder'   => true,
		'counter_title'       => true,
		'counter_text'        => true,
		'testimonial_content' => true,
		'testimonial_name'    => true,
		'testimonial_title'   => true,
		'team_member_name'    => true,
		'team_member_position' => true,
		'team_member_bio'     => true,
		'icon_text'           => true,
		'info_box_title'      => true,
		'info_box_description' => true,
		'wd_title'            => true,
		'wd_subtitle'         => true,
		'wd_text'             => true,
		'text_content'        => true,
		'button_text_closed'  => true,
		'button_text_open'    => true,
	);
	/**
	 * Segment passthrough keys for the active Elementor slice (from partial state).
	 * @var string[]
	 */
	private static $current_elementor_segment_passthrough = array();
	/**
	 * Base field passthrough keys (source-text fallback) for the active slice.
	 * @var string[]
	 */
	private static $current_elementor_field_passthrough = array();
}
