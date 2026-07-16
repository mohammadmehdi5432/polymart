<?php
/**
 * WordPress admin integration and React asset enqueueing.
 *
 * @package PolymartAI
 */

namespace PolymartAI;

use PolymartAI\Translation\AI\AI_Client;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
final class Admin {

	/**
	 * Registered admin page hooks keyed by page identifier.
	 *
	 * @var array<string, string>
	 */
	private $page_hooks = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_cron_notice' ) );
	}

	/**
	 * Register the PolyMart translator menu and submenu pages.
	 *
	 * @return void
	 */
	public function register_menu_page() {
		$menu_title = __( 'مترجم پلی‌مارت', 'polymart-ai' );

		$this->page_hooks['settings'] = add_menu_page(
			$menu_title,
			$menu_title,
			REST_API::required_admin_capability(),
			'polymart-ai',
			array( $this, 'render_settings_page' ),
			'dashicons-translation',
			58
		);

		add_submenu_page(
			'polymart-ai',
			__( 'تنظیمات', 'polymart-ai' ),
			__( 'تنظیمات', 'polymart-ai' ),
			REST_API::required_admin_capability(),
			'polymart-ai',
			array( $this, 'render_settings_page' )
		);

		$this->page_hooks['languages'] = add_submenu_page(
			'polymart-ai',
			__( 'زبان‌ها', 'polymart-ai' ),
			__( 'زبان‌ها', 'polymart-ai' ),
			REST_API::required_admin_capability(),
			'polymart-ai-languages',
			array( $this, 'render_languages_page' )
		);

		$this->page_hooks['currency'] = add_submenu_page(
			'polymart-ai',
			__( 'نرخ ارز', 'polymart-ai' ),
			__( 'نرخ ارز', 'polymart-ai' ),
			REST_API::required_admin_capability(),
			'polymart-ai-currency',
			array( $this, 'render_currency_page' )
		);

		$this->page_hooks['auto-translate'] = add_submenu_page(
			'polymart-ai',
			__( 'ترجمه خودکار', 'polymart-ai' ),
			__( 'ترجمه خودکار', 'polymart-ai' ),
			REST_API::required_admin_capability(),
			'polymart-ai-auto-translate',
			array( $this, 'render_auto_translate_page' )
		);

		$this->page_hooks['translations'] = add_submenu_page(
			'polymart-ai',
			__( 'مدیریت ترجمه', 'polymart-ai' ),
			__( 'مدیریت ترجمه', 'polymart-ai' ),
			REST_API::required_admin_capability(),
			'polymart-ai-translations',
			array( $this, 'render_translations_page' )
		);

		$this->page_hooks['report'] = add_submenu_page(
			'polymart-ai',
			__( 'گزارش ترجمه', 'polymart-ai' ),
			__( 'گزارش ترجمه', 'polymart-ai' ),
			REST_API::required_admin_capability(),
			'polymart-ai-report',
			array( $this, 'render_report_page' )
		);

		$this->page_hooks['logs'] = add_submenu_page(
			'polymart-ai',
			__( 'لاگ‌ها', 'polymart-ai' ),
			__( 'لاگ‌ها', 'polymart-ai' ),
			REST_API::required_admin_capability(),
			'polymart-ai-logs',
			array( $this, 'render_logs_page' )
		);

		// Must register after parent `polymart-ai` exists — otherwise WP orphan menus 404.
		if ( function_exists( 'polymart_ai_render_troubleshoot_page' ) ) {
			$this->page_hooks['troubleshoot'] = add_submenu_page(
				'polymart-ai',
				__( 'رفع اشکال', 'polymart-ai' ),
				__( 'رفع اشکال', 'polymart-ai' ),
				REST_API::required_admin_capability(),
				'polymart-ai-troubleshoot',
				'polymart_ai_render_troubleshoot_page'
			);
		}
	}

	/**
	 * Render the settings React mount point.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$this->render_admin_page( 'settings' );
	}

	/**
	 * Render the translation manager React mount point.
	 *
	 * @return void
	 */
	public function render_translations_page() {
		$this->render_admin_page( 'translations' );
	}

	/**
	 * Render the languages management React mount point.
	 *
	 * @return void
	 */
	public function render_languages_page() {
		$this->render_admin_page( 'languages' );
	}

	/**
	 * Render the currency rate management React mount point.
	 *
	 * @return void
	 */
	public function render_currency_page() {
		$this->render_admin_page( 'currency' );
	}

	/**
	 * Render the auto-translation React mount point.
	 *
	 * @return void
	 */
	public function render_auto_translate_page() {
		$this->render_admin_page( 'auto-translate' );
	}

	/**
	 * Render the translation report React mount point.
	 *
	 * @return void
	 */
	public function render_report_page() {
		$this->render_admin_page( 'report' );
	}

	/**
	 * Render the logs React mount point.
	 *
	 * @return void
	 */
	public function render_logs_page() {
		$this->render_admin_page( 'logs' );
	}

	/**
	 * Render a plugin admin page shell.
	 *
	 * @param string $page Page identifier passed to React.
	 * @return void
	 */
	private function render_admin_page( $page ) {
		if ( ! current_user_can( REST_API::required_admin_capability() ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'polymart-ai' ) );
		}

		echo '<div class="wrap polymart-ai-admin-wrap" dir="rtl">';
		printf(
			'<div id="polymart-ai-root" data-admin-page="%s"><div id="polymart-ai-app"></div></div>',
			esc_attr( $page )
		);
		echo '</div>';
	}

	/**
	 * Enqueue compiled React assets on plugin admin pages.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$admin_page = array_search( $hook_suffix, $this->page_hooks, true );

		if ( false === $admin_page ) {
			return;
		}

		$asset_file = POLYMART_AI_PLUGIN_DIR . 'build/assets/index.js';

		if ( ! file_exists( $asset_file ) ) {
			add_action(
				'admin_notices',
				static function () {
					printf(
						'<div class="notice notice-warning"><p>%s</p></div>',
						esc_html__(
							'مترجم پلی‌مارت: فایل‌های مدیریت یافت نشد. در پوشه پلاگین دستورات «npm install» و «npm run build» را اجرا کنید.',
							'polymart-ai'
						)
					);
				}
			);
			return;
		}

		$version = POLYMART_AI_VERSION . '.' . (string) filemtime( $asset_file );
		$active_ai = REST_API::get_active_translation_credentials();

		wp_enqueue_style(
			'polymart-ai-admin',
			POLYMART_AI_PLUGIN_URL . 'build/assets/index.css',
			array(),
			$version
		);

		$script_deps = array( 'jquery' );

		// Media library is only needed on the Languages screen.
		if ( 'languages' === $admin_page ) {
			wp_enqueue_media();
			$script_deps = array(
				'jquery',
				'media-models',
				'media-views',
				'media-editor',
				'media-upload',
				'wp-api-fetch',
			);
		}

		wp_enqueue_script(
			'polymart-ai-admin',
			POLYMART_AI_PLUGIN_URL . 'build/assets/index.js',
			$script_deps,
			$version,
			true
		);

		wp_localize_script(
			'polymart-ai-admin',
			'polymartAiSettings',
			array(
				'apiUrl'         => esc_url_raw( rest_url( 'polymart/v1' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'pluginUrl'      => POLYMART_AI_PLUGIN_URL,
				'version'        => POLYMART_AI_VERSION,
				'locale'         => determine_locale(),
				'adminPage'      => $admin_page,
				'wooActive'      => class_exists( 'WooCommerce' ),
				'woodmartActive' => defined( 'WOODMART_THEME_VERSION' ) || function_exists( 'woodmart_get_opt' ),
				'adminUrls'      => array(
					'settings'      => admin_url( 'admin.php?page=polymart-ai' ),
					'languages'     => admin_url( 'admin.php?page=polymart-ai-languages' ),
					'currency'      => admin_url( 'admin.php?page=polymart-ai-currency' ),
					'autoTranslate' => admin_url( 'admin.php?page=polymart-ai-auto-translate' ),
					'translations'  => admin_url( 'admin.php?page=polymart-ai-translations' ),
					'report'        => admin_url( 'admin.php?page=polymart-ai-report' ),
					'logs'          => admin_url( 'admin.php?page=polymart-ai-logs' ),
				),
				'aiConfigured'    => REST_API::is_ai_configured(),
				'aiProvider'      => $active_ai['ai_provider'] ?? AI_Client::PROVIDER_ARVAN,
				'aiProviderLabel' => AI_Client::provider_label( $active_ai['ai_provider'] ?? AI_Client::PROVIDER_ARVAN ),
				'cronDisabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'devMode'        => defined( 'WP_DEBUG' ) && WP_DEBUG,
			)
		);
	}

	/**
	 * Warn admins when WP-Cron spawning is disabled on the host.
	 *
	 * @return void
	 */
	public function maybe_render_cron_notice() {
		if ( ! current_user_can( REST_API::required_admin_capability() ) ) {
			return;
		}

		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || false === strpos( (string) $screen->id, 'polymart-ai' ) ) {
			return;
		}

		// React dashboard already surfaces cron guidance — avoid duplicate notices.
		return;
	}
}
