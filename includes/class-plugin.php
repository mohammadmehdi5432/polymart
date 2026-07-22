<?php
/**
 * Main plugin bootstrap class.
 *
 * @package PolymartAI
 */

namespace PolymartAI;

use PolymartAI\Admin\Ajax_Handler;
use PolymartAI\Admin\Meta_Box;
use PolymartAI\Frontend\Cart_Fragments_Compat;
use PolymartAI\Frontend\Currency;
use PolymartAI\Frontend\Language_Switcher;
use PolymartAI\Frontend\Storefront_Language_Persistence;
use PolymartAI\Frontend\Storefront_Script_Guard;
use PolymartAI\Frontend\Woodmart_Translator;
use PolymartAI\Integration\Apd_Integration;
use PolymartAI\Integration\Cpc_Integration;
use PolymartAI\Integration\Customer_Portal_Integration;
use PolymartAI\Integration\Elementor_Image_Translation;
use PolymartAI\Integration\Jet_Checkout_Integration;
use PolymartAI\Integration\Woodmart_Header_Integration;
use PolymartAI\Integration\Knd_Integration;
use PolymartAI\Integration\Kndpi_Integration;
use PolymartAI\Integration\Wve_Integration;
use PolymartAI\Routing\Url_Router;
use PolymartAI\Translation\AI\AI_Client;
use PolymartAI\Translation\Pipeline\Async_Translator;
use PolymartAI\Translation\Content\Comment_Translator;
use PolymartAI\Translation\Storefront\Frontend_Interceptor;
use PolymartAI\Translation\Storefront\Layout_Guard;
use PolymartAI\Translation\Content\Option_Translator;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\WooCommerce\Product_Diagnostics;
use PolymartAI\Translation\Storefront\Runtime_String_Translator;
use PolymartAI\Translation\Pipeline\Universal_Translator;
use PolymartAI\Translation\WooCommerce\WooCommerce_Translator;


defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Admin handler.
	 *
	 * @var Admin|null
	 */
	private $admin = null;

	/**
	 * REST API handler.
	 *
	 * @var REST_API|null
	 */
	private $rest_api = null;

	/**
	 * URL router.
	 *
	 * @var Url_Router|null
	 */
	private $url_router = null;

	/**
	 * Post translator.
	 *
	 * @var Post_Translator|null
	 */
	private $post_translator = null;

	/**
	 * Background auto-translator for content changes.
	 *
	 * @var Async_Translator|null
	 */
	private $async_translator = null;

	/**
	 * Frontend interceptor.
	 *
	 * @var Frontend_Interceptor|null
	 */
	private $frontend_interceptor = null;

	/**
	 * Universal string and Elementor translator.
	 *
	 * @var Universal_Translator|null
	 */
	private $universal_translator = null;

	/**
	 * WooCommerce review translator.
	 *
	 * @var Comment_Translator|null
	 */
	private $comment_translator = null;

	/**
	 * Theme option translator (storefront).
	 *
	 * @var Option_Translator|null
	 */
	private $option_translator = null;

	/**
	 * Whether storefront translators were booted for this request.
	 *
	 * @var bool
	 */
	private $storefront_translators_booted = false;

	/**
	 * Woodmart/header/footer translator.
	 *
	 * @var Woodmart_Translator|null
	 */
	private $woodmart_translator = null;

	/**
	 * WooCommerce product and surface translator.
	 *
	 * @var WooCommerce_Translator|null
	 */
	private $woocommerce_translator = null;

	/**
	 * Language switcher shortcode.
	 *
	 * @var Language_Switcher|null
	 */
	private $language_switcher = null;

	/**
	 * English translation meta box.
	 *
	 * @var Meta_Box|null
	 */
	private $meta_box = null;

	/**
	 * AJAX translation handler.
	 *
	 * @var Ajax_Handler|null
	 */
	private $ajax_handler = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_textdomain();
		$this->init_hooks();
	}

	/**
	 * Load plugin text domain.
	 *
	 * @return void
	 */
	private function load_textdomain() {
		add_action(
			'init',
			static function () {
				load_plugin_textdomain(
					'polymart-ai',
					false,
					dirname( POLYMART_AI_PLUGIN_BASENAME ) . '/languages'
				);
			}
		);
	}

	/**
	 * Register core hooks and subsystems.
	 *
	 * @return void
	 */
	private function init_hooks() {
		Language_Registry::maybe_seed_defaults();
		Activity_Logger::init();
		AI_Client::init();
		Cpc_Integration::init();
		Customer_Portal_Integration::init();
		Jet_Checkout_Integration::init();
		Woodmart_Header_Integration::init();
		Apd_Integration::init();
		Knd_Integration::init();
		Kndpi_Integration::init();
		Wve_Integration::init();
		Elementor_Image_Translation::init();

		if ( ! Admin_Request_Guard::should_isolate() ) {
			add_action( 'init', array( Runtime_String_Translator::class, 'maybe_prune_on_boot' ), 1 );
		}
		Layout_Guard::init();
		Cart_Fragments_Compat::init();
		Currency::init();
		Storefront_Language_Persistence::init();
		Storefront_Script_Guard::init();

		$this->url_router       = new Url_Router();
		$this->post_translator  = new Post_Translator();
		$this->async_translator = new Async_Translator();
		$this->language_switcher = new Language_Switcher();

		// Background worker for storefront strings queued during cache-only renders.
		add_action(
			Runtime_String_Translator::PENDING_CRON_HOOK,
			array( Runtime_String_Translator::class, 'process_pending_queue' )
		);

		// Must register outside `wp` — WP-Cron never fires `wp`, so review jobs would be dropped.
		add_action(
			Comment_Translator::CRON_HOOK,
			array( Comment_Translator::class, 'process_scheduled_translation' ),
			10,
			1
		);
		Comment_Translator::register_lifecycle_hooks();

		/*
		 * Boot on `init` (not only `wp`): Elementor often reads `_elementor_data`
		 * during `wp` / CSS print. Waiting until `wp` left /en/ serving Persian
		 * source even when `_elementor_data_en` was clean and finalized.
		 *
		 * admin-ajax.php never fires `wp` either — same early boot covers AJAX
		 * fragments. `wp` remains a fallback for any path that skipped `init`.
		 */
		if ( ! is_admin() || wp_doing_ajax() ) {
			add_action( 'init', array( $this, 'maybe_boot_storefront_translators' ), 20 );
		}

		add_action( 'wp', array( $this, 'maybe_boot_storefront_translators' ), 0 );

		// Translated storefront: register the Elementor companion swap immediately
		// (plugins_loaded) so Theme Builder / CSS never cache FA `_elementor_data`.
		if ( ( ! is_admin() || wp_doing_ajax() ) && Url_Router::is_translated_request() ) {
			$this->maybe_boot_storefront_translators();
		}

		if ( is_admin() ) {
			$this->admin        = new Admin();
			$this->meta_box     = new Meta_Box();
			$this->ajax_handler = new Ajax_Handler();
		}

		$this->rest_api = new REST_API();
	}

	/**
	 * Boot storefront translation modules (idempotent).
	 *
	 * Prefer `init` so Elementor meta swap is registered before `wp`. Per-module
	 * bypass constants (POLYMART_AI_BYPASS_*) allow selective isolation on single
	 * product pages. Nuclear bypass skips every module.
	 *
	 * @return void
	 */
	public function maybe_boot_storefront_translators() {
		if ( $this->storefront_translators_booted || Admin_Request_Guard::should_isolate() ) {
			return;
		}

		$this->storefront_translators_booted = true;
		Product_Diagnostics::log_boot_decision();

		if ( null === $this->frontend_interceptor && ! Product_Diagnostics::should_skip_module( 'frontend_interceptor' ) ) {
			$this->frontend_interceptor = new Frontend_Interceptor();
		}

		if ( null === $this->universal_translator && ! Product_Diagnostics::should_skip_module( 'universal' ) ) {
			$this->universal_translator = new Universal_Translator();
		}

		if ( null === $this->woocommerce_translator && ! Product_Diagnostics::should_skip_module( 'woocommerce' ) ) {
			$this->woocommerce_translator = new WooCommerce_Translator();
		}

		if ( null === $this->woodmart_translator && ! Product_Diagnostics::should_skip_module( 'woodmart' ) ) {
			$this->woodmart_translator = new Woodmart_Translator();
		}

		if ( null === $this->option_translator && ! Product_Diagnostics::should_skip_module( 'option' ) ) {
			$this->option_translator = new Option_Translator();
		}

		if ( null === $this->comment_translator && ! Product_Diagnostics::should_skip_module( 'comment' ) ) {
			$this->comment_translator = new Comment_Translator();
		}
	}

	/**
	 * Prevent cloning.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @return void
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}
}
