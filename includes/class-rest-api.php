<?php
/**
 * REST API route registration for the React admin panel.
 *
 * @package PolymartAI
 */

namespace PolymartAI;

use PolymartAI\Language_Registry;
use PolymartAI\Frontend\Currency;
use PolymartAI\Frontend\Currency_Price_Sync;
use PolymartAI\Translation\AI_Client;
use PolymartAI\Translation\Async_Translator;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Translation_Query;
use PolymartAI\Translation\UI_String_Bulk_Job;
use PolymartAI\Translation\UI_String_Registry;
use PolymartAI\Translation\UI_String_Scanner;
use PolymartAI\Translation\UI_String_Source_Scanner;
use PolymartAI\Translation\Variation_Translation_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Class REST_API
 */
final class REST_API {

	/**
	 * Option key for plugin settings.
	 */
	const OPTION_KEY = 'polymart_ai_settings';

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'polymart/v1';

	/**
	 * Maximum post IDs returned per untranslated backlog request.
	 */
	const MAX_UNTRANSLATED_BATCH = 500;

	/**
	 * Default page size for translation manager list.
	 */
	const TRANSLATIONS_PER_PAGE = 20;

	/**
	 * Transient key for cached translation stats.
	 */
	const STATS_CACHE_KEY = 'polymart_ai_stats_cache_v4';

	/**
	 * Stats cache lifetime in seconds.
	 */
	const STATS_CACHE_TTL = 300;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => $this->get_settings_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/untranslated',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_untranslated_posts' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bulk-translate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_translate_post' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value ) && (int) $value > 0;
						},
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/translations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_translations_list' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/translations/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_translation_item' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_translation_item' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/languages',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_languages' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_languages' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/languages/presets',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_language_presets' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/dashboard',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_dashboard' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings/test-connection',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/currency/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_currency_status' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/currency/refresh',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refresh_currency_rate' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/currency/sync-job',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_currency_sync_job' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_currency_sync_job' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/currency/sync-job/step',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'process_currency_sync_step' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/logs',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_logs' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_logs' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/translation-report',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_translation_report' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/translation-job',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_translation_job' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_translation_job' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/translation-job/step',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'process_translation_job_step' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/translation-job/test-api',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_translation_api' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/rest-nonce',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_rest_nonce' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/translations/(?P<id>\d+)/generate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_translation_item' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ui-strings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ui_strings_stats' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ui-strings/scan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan_ui_strings' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ui-strings/job',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_ui_strings_job' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_ui_strings_job' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ui-strings/job/step',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'process_ui_strings_job_step' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ui-strings/sandbox-test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sandbox_test_ui_string_file' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'file_path' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( $this, 'sanitize_sandbox_file_path_param' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/system/process-queues',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'process_background_queues' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'passes' => array(
						'type'              => 'integer',
						'default'           => 15,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/system/resync-variation-translations',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resync_variation_translations' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'dry_run' => array(
						'type'              => 'boolean',
						'default'           => false,
					),
					'lang'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'limit'   => array(
						'type'              => 'integer',
						'default'           => 200,
						'minimum'           => 1,
						'maximum'           => 500,
						'sanitize_callback' => 'absint',
					),
					'offset'  => array(
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
					'all'     => array(
						'type'              => 'boolean',
						'default'           => true,
					),
				),
			)
		);
	}

	/**
	 * Permission callback for admin REST routes.
	 *
	 * @return bool
	 */
	public function check_permissions() {
		return current_user_can( self::required_admin_capability() );
	}

	/**
	 * Capability required for plugin admin REST routes and settings page.
	 *
	 * @return string
	 */
	public static function required_admin_capability() {
		return 'manage_options';
	}

	/**
	 * Get plugin settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );
		$merged   = wp_parse_args( $settings, self::get_default_settings() );

		$merged['translation'] = self::normalize_translation_settings( $merged['translation'] );
		$merged['translation'] = self::mask_translation_settings_for_response( $merged['translation'] );
		$merged['currency']    = self::normalize_currency_settings( $merged['currency'] ?? array() );
		$merged['currency']    = self::mask_currency_settings_for_response( $merged['currency'] );
		$merged['currency']['status'] = Currency::get_rate_status();

		return rest_ensure_response( $merged );
	}

	/**
	 * Dashboard overview for the admin SPA.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_dashboard() {
		$translation = self::get_stored_translation_settings();

		return rest_ensure_response(
			array(
				'stats'           => $this->get_translation_stats(),
				'ai_configured'   => self::is_ai_configured(),
				'api_key_set'     => '' !== trim( $translation['api_key'] ),
				'endpoint_set'    => '' !== trim( $translation['api_endpoint'] ),
				'ai_model'        => AI_Client::resolve_model(
					$translation['ai_model'],
					$translation['api_endpoint']
				),
				'english_url'     => Language_Registry::get_language_home_url( 'en' ),
				'site_url'        => home_url( '/' ),
				'languages'       => Language_Registry::format_for_api(),
				'woo_active'      => class_exists( 'WooCommerce' ),
				'woodmart_active' => defined( 'WOODMART_THEME_VERSION' ) || function_exists( 'woodmart_get_opt' ),
				'plugin_version'  => defined( 'POLYMART_AI_VERSION' ) ? POLYMART_AI_VERSION : '1.0.0',
				'shortcode'       => '[polymart_language_switcher]',
				'cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'cron_url'        => site_url( 'wp-cron.php' ),
				'queue'           => Async_Translator::get_queue_status(),
			)
		);
	}

	/**
	 * List configured languages.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_languages() {
		return rest_ensure_response(
			array(
				'languages' => Language_Registry::format_for_api(),
				'presets'   => Language_Registry::get_presets(),
			)
		);
	}

	/**
	 * Available preset languages.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_language_presets() {
		return rest_ensure_response(
			array(
				'presets' => Language_Registry::get_presets(),
			)
		);
	}

	/**
	 * Save language configuration.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_languages( $request ) {
		$incoming = $request->get_json_params();

		if ( ! is_array( $incoming ) || empty( $incoming['languages'] ) || ! is_array( $incoming['languages'] ) ) {
			return new \WP_Error(
				'polymart_ai_invalid_payload',
				__( 'داده زبان‌ها نامعتبر است.', 'polymart-ai' ),
				array( 'status' => 400 )
			);
		}

		$validation = Language_Registry::validate_languages( $incoming['languages'] );

		if ( is_wp_error( $validation ) ) {
			return new \WP_Error(
				$validation->get_error_code(),
				$validation->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$saved = Language_Registry::save_languages( $incoming['languages'] );

		return rest_ensure_response(
			array(
				'message'   => __( 'زبان‌ها با موفقیت ذخیره شدند.', 'polymart-ai' ),
				'languages' => $saved,
			)
		);
	}

	/**
	 * Test AI API credentials.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_connection( $request ) {
		$current  = wp_parse_args( get_option( self::OPTION_KEY, array() ), self::get_default_settings() );
		$current  = self::normalize_translation_settings( $current['translation'] );
		$incoming = $request->get_json_params();

		if ( ! is_array( $incoming ) ) {
			$incoming = array();
		}

		$api_key  = sanitize_text_field( (string) ( $incoming['api_key'] ?? '' ) );
		$endpoint = self::sanitize_gateway_endpoint( (string) ( $incoming['api_endpoint'] ?? '' ) );
		$model    = sanitize_text_field( (string) ( $incoming['ai_model'] ?? '' ) );

		if ( '' === $api_key ) {
			$api_key = $current['api_key'];
		}

		if ( '' === $endpoint && '' !== trim( (string) ( $incoming['api_endpoint'] ?? '' ) ) ) {
			return new \WP_Error(
				'polymart_ai_invalid_gateway_endpoint',
				__( 'آدرس AI Gateway نامعتبر است. آدرس کامل را از پنل آروان کپی کنید — باید با https:// شروع شود.', 'polymart-ai' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $endpoint ) {
			$endpoint = $current['api_endpoint'];
		}

		if ( '' === $model ) {
			$model = $current['ai_model'];
		}

		$result = AI_Client::test_connection( $api_key, $endpoint, $model );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				'polymart_ai_connection_failed',
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'اتصال به API با موفقیت برقرار شد.', 'polymart-ai' ),
			)
		);
	}

	/**
	 * Update plugin settings.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_settings( $request ) {
		$current  = wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			self::get_default_settings()
		);
		$current['currency'] = self::normalize_currency_settings( $current['currency'] ?? array() );
		$incoming = $request->get_json_params();

		if ( ! is_array( $incoming ) ) {
			return new \WP_Error(
				'polymart_ai_invalid_payload',
				__( 'داده تنظیمات نامعتبر است.', 'polymart-ai' ),
				array( 'status' => 400 )
			);
		}

		$translation = $this->sanitize_translation_settings(
			$incoming['translation'] ?? array(),
			$current['translation']
		);

		if ( is_wp_error( $translation ) ) {
			return $translation;
		}

		$updated = array(
			'translation' => $translation,
			'currency'    => $this->sanitize_currency_settings(
				$incoming['currency'] ?? array(),
				$current['currency']
			),
		);

		update_option( self::OPTION_KEY, $updated );
		self::invalidate_stats_cache();

		$previous_key = trim( (string) ( $current['currency']['api_key'] ?? '' ) );
		$new_key      = trim( (string) ( $updated['currency']['api_key'] ?? '' ) );

		if ( '' !== $new_key && $new_key !== $previous_key && Currency::get_rate() <= 0 ) {
			Currency::refresh_rate( true );
		}

		$updated['translation'] = self::normalize_translation_settings( $updated['translation'] );
		$updated['translation'] = self::mask_translation_settings_for_response( $updated['translation'] );
		$updated['currency']    = self::normalize_currency_settings( $updated['currency'] );
		$updated['currency']    = self::mask_currency_settings_for_response( $updated['currency'] );
		$updated['currency']['status'] = Currency::get_rate_status();

		return rest_ensure_response( $updated );
	}

	/**
	 * Return published posts/products missing English title meta.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_untranslated_posts( $request = null ) {
		$lang  = 'en';
		$after = 0;

		if ( $request instanceof \WP_REST_Request ) {
			$lang  = $this->resolve_target_language( $request->get_param( 'lang' ) );
			$after = absint( $request->get_param( 'after_id' ) );
		}

		$result = Translation_Query::collect_untranslated_post_ids(
			$lang,
			self::MAX_UNTRANSLATED_BATCH,
			$after
		);

		$total = $result['total'];

		if ( 0 === $after ) {
			$total = Translation_Query::count_untranslated_persian_posts( $lang );
		}

		$stats = $this->get_translation_stats( $lang );

		return rest_ensure_response(
			array(
				'total'           => $total,
				'post_ids'        => $result['post_ids'],
				'truncated'       => $result['truncated'],
				'lang'            => $lang,
				'after_id'        => $after,
				'scanned_through' => $result['scanned_through'],
				'stats'           => $stats,
				'needs_work'      => (int) $stats['untranslated'] + (int) $stats['partial'],
			)
		);
	}

	/**
	 * Resolve a valid target language code for translation operations.
	 *
	 * @param mixed $lang Requested language code.
	 * @return string
	 */
	private function resolve_target_language( $lang ) {
		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			$lang = 'en';
		}

		if ( Language_Registry::get_default_language_code() === $lang ) {
			return 'en';
		}

		$language = Language_Registry::get_language( $lang );

		if ( ! $language || empty( $language['enabled'] ) || ! empty( $language['is_default'] ) ) {
			return 'en';
		}

		return $lang;
	}

	/**
	 * Translate a single post via AI and persist English meta fields.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk_translate_post( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$lang    = $this->resolve_target_language( $request->get_param( 'lang' ) );

		if ( ! $post_id ) {
			return new \WP_Error(
				'polymart_ai_invalid_post_id',
				__( 'شناسه مطلب معتبر الزامی است.', 'polymart-ai' ),
				array( 'status' => 400 )
			);
		}

		$result = Post_Translator::request_ai_translation( $post_id, $lang );

		if ( is_wp_error( $result ) ) {
			return $this->translation_error_to_rest_response( $result );
		}

		Post_Translator::save_ai_translations( $post_id, $result['translations'], $lang );
		self::invalidate_stats_cache();
		$this->record_translation_report( $post_id, $lang, 'bulk' );

		return rest_ensure_response(
			array(
				'post_id'   => $post_id,
				'title'     => get_the_title( $post_id ),
				'post_type' => $result['post']->post_type,
				'message'   => __( 'ترجمه با موفقیت ذخیره شد.', 'polymart-ai' ),
			)
		);
	}

	/**
	 * List translatable posts for the translation manager.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_translations_list( $request ) {
		$status   = sanitize_key( (string) $request->get_param( 'status' ) );
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ) ?: self::TRANSLATIONS_PER_PAGE ) );
		$lang     = $this->resolve_target_language( $request->get_param( 'lang' ) );

		$result = Translation_Query::get_translations_page(
			array(
				'lang'      => $lang,
				'status'    => $status,
				'post_type' => $post_type,
				'search'    => $search,
				'page'      => $page,
				'per_page'  => $per_page,
			)
		);

		return rest_ensure_response(
			array(
				'items'    => $result['items'],
				'total'    => $result['total'],
				'page'     => $result['page'],
				'per_page' => $result['per_page'],
				'pages'    => $result['pages'],
				'lang'     => $lang,
				'stats'    => $this->get_translation_stats( $lang ),
			)
		);
	}

	/**
	 * Return aggregate translation stats for the manager dashboard.
	 *
	 * @return array<string, int>
	 */
	private function get_translation_stats( $lang = 'en' ) {
		$lang  = $this->resolve_target_language( $lang );
		$cache = get_transient( self::STATS_CACHE_KEY . '_' . $lang );

		if ( is_array( $cache ) ) {
			return $cache;
		}

		$stats = Translation_Query::compute_translation_stats( $lang );

		set_transient( self::STATS_CACHE_KEY . '_' . $lang, $stats, self::STATS_CACHE_TTL );

		return $stats;
	}

	/**
	 * Clear cached translation statistics.
	 *
	 * @return void
	 */
	public static function invalidate_stats_cache() {
		delete_transient( self::STATS_CACHE_KEY );

		foreach ( Language_Registry::get_languages() as $language ) {
			delete_transient( self::STATS_CACHE_KEY . '_' . $language['code'] );
		}
	}

	/**
	 * Get a single translation record.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_translation_item( $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, Post_Translator::get_supported_post_types(), true ) ) {
			return new \WP_Error(
				'polymart_ai_invalid_post',
				__( 'مورد یافت نشد.', 'polymart-ai' ),
				array( 'status' => 404 )
			);
		}

		if ( ! Post_Translator::has_persian_content( $post_id ) ) {
			return new \WP_Error(
				'polymart_ai_no_persian_content',
				__( 'این مورد محتوای فارسی ندارد و نیازی به ترجمه ندارد.', 'polymart-ai' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( Post_Translator::format_translation_record( $post, $this->resolve_target_language( $request->get_param( 'lang' ) ) ) );
	}

	/**
	 * Save manual English translations from the manager UI.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_translation_item( $request ) {
		$post_id  = absint( $request->get_param( 'id' ) );
		$incoming = $request->get_json_params();

		if ( ! is_array( $incoming ) ) {
			return new \WP_Error(
				'polymart_ai_invalid_payload',
				__( 'داده ترجمه نامعتبر است.', 'polymart-ai' ),
				array( 'status' => 400 )
			);
		}

		$lang   = $this->resolve_target_language( $request->get_param( 'lang' ) );
		$result = Post_Translator::save_manual_translations_from_api( $post_id, $incoming, $lang );

		if ( is_wp_error( $result ) ) {
			return $this->translation_error_to_rest_response( $result );
		}

		self::invalidate_stats_cache();

		$this->record_translation_report( $post_id, $lang, 'manual' );

		$post = get_post( $post_id );

		return rest_ensure_response(
			array(
				'message' => __( 'ترجمه دستی با موفقیت ذخیره شد.', 'polymart-ai' ),
				'item'    => $post instanceof \WP_Post ? Post_Translator::format_translation_record( $post, $lang ) : null,
			)
		);
	}

	/**
	 * Generate AI translations for a single item.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate_translation_item( $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$lang    = $this->resolve_target_language( $request->get_param( 'lang' ) );

		$result = Post_Translator::request_ai_translation( $post_id, $lang );

		if ( is_wp_error( $result ) ) {
			return $this->translation_error_to_rest_response( $result );
		}

		Post_Translator::save_ai_translations( $post_id, $result['translations'], $lang );
		self::invalidate_stats_cache();
		$this->record_translation_report( $post_id, $lang, 'ai' );

		$post = get_post( $post_id );

		return rest_ensure_response(
			array(
				'message' => __( 'ترجمه هوش مصنوعی با موفقیت تولید و ذخیره شد.', 'polymart-ai' ),
				'item'    => $post instanceof \WP_Post ? Post_Translator::format_translation_record( $post, $lang ) : null,
			)
		);
	}

	/**
	 * Map a translation WP_Error to an appropriate REST response.
	 *
	 * @param \WP_Error $error Translation error.
	 * @return \WP_Error
	 */
	private function translation_error_to_rest_response( \WP_Error $error ) {
		$code    = $error->get_error_code();
		$status  = 500;
		$client_errors = array(
			'polymart_ai_forbidden',
			'polymart_ai_invalid_post',
			'polymart_ai_invalid_post_id',
			'polymart_ai_missing_credentials',
			'polymart_ai_empty_source',
		);

		if ( in_array( $code, $client_errors, true ) ) {
			$status = 'polymart_ai_forbidden' === $code ? 403 : 400;
		}

		return new \WP_Error(
			'polymart_ai_translation_failed',
			$error->get_error_message(),
			array( 'status' => $status )
		);
	}

	/**
	 * Default settings structure.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_default_settings() {
		return array(
			'translation' => array(
				'api_key'      => '',
				'api_endpoint' => '',
				'ai_model'     => AI_Client::DEFAULT_MODEL,
			),
			'currency'    => array(
				'api_key' => '',
			),
		);
	}

	/**
	 * Read normalized translation settings from the database.
	 *
	 * @return array{api_key: string, api_endpoint: string, ai_model: string}
	 */
	public static function get_stored_translation_settings() {
		$settings = wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			self::get_default_settings()
		);

		return self::normalize_translation_settings( $settings['translation'] ?? array() );
	}

	/**
	 * Whether AI translation credentials are stored and usable.
	 *
	 * @return bool
	 */
	public static function is_ai_configured() {
		$translation = self::get_stored_translation_settings();

		return '' !== trim( $translation['api_key'] ) && '' !== trim( $translation['api_endpoint'] );
	}

	/**
	 * REST argument schema for settings updates.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_settings_args() {
		return array(
			'translation' => array(
				'type'       => 'object',
				'properties' => array(
					'api_key'      => array( 'type' => 'string' ),
					'api_endpoint' => array( 'type' => 'string' ),
					'ai_model'     => array( 'type' => 'string' ),
				),
			),
			'currency'    => array(
				'type'       => 'object',
				'properties' => array(
					'api_key' => array( 'type' => 'string' ),
				),
			),
		);
	}

	/**
	 * Sanitize translation settings.
	 *
	 * @param array<string, mixed> $incoming Incoming values.
	 * @param array<string, mixed> $current  Current stored values.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function sanitize_translation_settings( array $incoming, array $current ) {
		$current = self::normalize_translation_settings( $current );

		$incoming_key = isset( $incoming['api_key'] ) ? (string) $incoming['api_key'] : '';

		if ( '' !== trim( $incoming_key ) ) {
			$api_key = sanitize_text_field( $incoming_key );
		} else {
			$api_key = $current['api_key'];
		}

		$endpoint_raw = isset( $incoming['api_endpoint'] ) ? (string) $incoming['api_endpoint'] : '';
		$endpoint     = self::sanitize_gateway_endpoint( $endpoint_raw );

		if ( '' !== trim( $endpoint_raw ) && '' === $endpoint ) {
			return new \WP_Error(
				'polymart_ai_invalid_gateway_endpoint',
				__( 'آدرس AI Gateway نامعتبر است. آدرس کامل را از پنل آروان کپی کنید — باید با https:// شروع شود.', 'polymart-ai' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $endpoint ) {
			$endpoint = $current['api_endpoint'];
		}

		return array(
			'api_key'      => $api_key,
			'api_endpoint' => $endpoint,
			'ai_model'     => sanitize_text_field( $incoming['ai_model'] ?? $current['ai_model'] ),
		);
	}

	/**
	 * Remove sensitive API key from REST responses.
	 *
	 * @param array<string, mixed> $translation Translation settings.
	 * @return array<string, mixed>
	 */
	private static function mask_translation_settings_for_response( array $translation ) {
		$translation['api_key_set'] = '' !== trim( (string) ( $translation['api_key'] ?? '' ) );
		unset( $translation['api_key'] );

		return $translation;
	}

	/**
	 * Normalize translation settings and migrate legacy field names.
	 *
	 * @param array<string, mixed> $translation Translation settings.
	 * @return array<string, mixed>
	 */
	private static function normalize_translation_settings( array $translation ) {
		if ( empty( $translation['api_key'] ) && ! empty( $translation['arvan_api_key'] ) ) {
			$translation['api_key'] = $translation['arvan_api_key'];
		}

		if ( empty( $translation['api_endpoint'] ) && ! empty( $translation['arvan_api_endpoint'] ) ) {
			$translation['api_endpoint'] = $translation['arvan_api_endpoint'];
		}

		if ( empty( $translation['ai_model'] ) && ! empty( $translation['arvan_model'] ) ) {
			$translation['ai_model'] = $translation['arvan_model'];
		}

		return array(
			'api_key'      => (string) ( $translation['api_key'] ?? '' ),
			'api_endpoint' => (string) ( $translation['api_endpoint'] ?? '' ),
			'ai_model'     => (string) ( $translation['ai_model'] ?? '' ),
		);
	}

	/**
	 * Sanitize an AI Gateway base URL (ArvanCloud OpenAI-compatible endpoint).
	 *
	 * WordPress esc_url_raw() can reject long gateway paths; this keeps valid https URLs intact.
	 *
	 * @param string $raw Raw URL from admin input.
	 * @return string Sanitized URL or empty string when invalid.
	 */
	public static function sanitize_gateway_endpoint( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		$raw = trim( $raw );
		$raw = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}\x{061C}\x{202A}-\x{202E}]/u', '', $raw );
		$raw = str_replace( array( '…', '...' ), '', $raw );
		$raw = preg_replace( '/\s+/u', '', $raw );

		if ( '' === $raw ) {
			return '';
		}

		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			$raw = 'https://' . ltrim( $raw, '/' );
		}

		$sanitized = esc_url_raw( $raw, array( 'http', 'https' ) );

		if ( '' !== $sanitized ) {
			return $sanitized;
		}

		if ( preg_match( '#^https?://[^\s<>"\'`\[\]{}\\\\^]+#i', $raw, $matches ) ) {
			$candidate = rtrim( $matches[0], ".,;)" );
			$sanitized = esc_url_raw( $candidate, array( 'http', 'https' ) );

			return '' !== $sanitized ? $sanitized : $candidate;
		}

		return '';
	}

	/**
	 * Sanitize currency settings.
	 *
	 * @param array<string, mixed> $incoming Incoming values.
	 * @param array<string, mixed> $current  Current stored values.
	 * @return array<string, mixed>
	 */
	private function sanitize_currency_settings( array $incoming, array $current ) {
		$current = self::normalize_currency_settings( $current );

		$incoming_key = isset( $incoming['api_key'] ) ? (string) $incoming['api_key'] : '';

		if ( '' !== trim( $incoming_key ) ) {
			$api_key = sanitize_text_field( $incoming_key );
		} else {
			$api_key = $current['api_key'];
		}

		return array(
			'api_key' => $api_key,
		);
	}

	/**
	 * Normalize legacy currency settings keys.
	 *
	 * @param array<string, mixed> $currency Currency settings.
	 * @return array<string, mixed>
	 */
	public static function normalize_currency_settings( array $currency ) {
		return array(
			'api_key' => (string) ( $currency['api_key'] ?? '' ),
		);
	}

	/**
	 * Remove sensitive API key from REST responses.
	 *
	 * @param array<string, mixed> $currency Currency settings.
	 * @return array<string, mixed>
	 */
	private static function mask_currency_settings_for_response( array $currency ) {
		$currency['api_key_set'] = '' !== trim( (string) ( $currency['api_key'] ?? '' ) );
		unset( $currency['api_key'] );

		return $currency;
	}

	/**
	 * Currency rate status for admin panel.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_currency_status() {
		return rest_ensure_response(
			array_merge(
				Currency::get_rate_status(),
				array(
					'sync'     => Currency_Price_Sync::get_stats(),
					'sync_job' => Currency_Price_Sync::get_job(),
				)
			)
		);
	}

	/**
	 * Get currency price sync job state.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_currency_sync_job() {
		return rest_ensure_response(
			array(
				'job'  => Currency_Price_Sync::get_job(),
				'stats'=> Currency_Price_Sync::get_stats(),
				'rate' => Currency::get_rate_status(),
			)
		);
	}

	/**
	 * Control currency price sync job.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_currency_sync_job( $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );

		switch ( $action ) {
			case 'start':
				$result = Currency_Price_Sync::start_job();
				break;
			case 'pause':
				$result = Currency_Price_Sync::pause_job();
				break;
			case 'resume':
				$result = Currency_Price_Sync::resume_job();
				break;
			case 'reset':
				$result = Currency_Price_Sync::reset_job();
				break;
			default:
				return new \WP_Error(
					'polymart_ai_invalid_action',
					__( 'عملیات نامعتبر است.', 'polymart-ai' ),
					array( 'status' => 400 )
				);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'job'  => $result,
				'stats'=> Currency_Price_Sync::get_stats(),
			)
		);
	}

	/**
	 * Process one batch of currency price sync.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function process_currency_sync_step() {
		$result = Currency_Price_Sync::process_step();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'job'  => $result,
				'stats'=> Currency_Price_Sync::get_stats(),
			)
		);
	}

	/**
	 * Force refresh USD rate from BrsApi.ir.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function refresh_currency_rate() {
		Currency::maybe_schedule_cron();

		$had_rate_before = Currency::get_rate() > 0;
		$result          = Currency::refresh_rate( true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$sync_kickoff = is_array( $result['sync_kickoff'] ?? null ) ? $result['sync_kickoff'] : array();
		$sync_started = ! empty( $sync_kickoff['started'] );

		return rest_ensure_response(
			array(
				'success'      => true,
				'sync_started' => $sync_started,
				'sync_kickoff' => $sync_kickoff,
				'message'      => ! empty( $result['from_fallback'] )
					? sprintf(
						/* translators: %s: warning message from API */
						__( 'نرخ از آخرین مقدار ذخیره‌شده استفاده شد. (%s)', 'polymart-ai' ),
						(string) ( $result['warning'] ?? '' )
					)
					: ( $sync_started
						? __( 'نرخ دلار به‌روزرسانی شد و تبدیل قیمت‌ها آغاز شد.', 'polymart-ai' )
						: ( $had_rate_before
							? __( 'نرخ دلار با موفقیت به‌روزرسانی شد.', 'polymart-ai' )
							: __( 'نرخ دلار دریافت، در دیتابیس ذخیره شد و کرون روزانه فعال شد.', 'polymart-ai' ) ) ),
				'status'       => Currency::get_rate_status(),
				'result'       => $result,
				'job'          => Currency_Price_Sync::get_job(),
			)
		);
	}

	/**
	 * Get activity logs.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function get_logs( $request ) {
		$limit  = min( 100, max( 1, absint( $request->get_param( 'limit' ) ) ?: 25 ) );
		$page   = max( 1, absint( $request->get_param( 'page' ) ) ?: 1 );
		$level  = sanitize_key( (string) $request->get_param( 'level' ) );
		$offset = ( $page - 1 ) * $limit;
		$result = Activity_Logger::get_logs_page( $limit, $level, $offset );

		return rest_ensure_response(
			array(
				'items' => $result['items'],
				'total' => $result['total'],
				'page'  => $page,
				'pages' => (int) max( 1, ceil( $result['total'] / $limit ) ),
			)
		);
	}

	/**
	 * Clear activity logs.
	 *
	 * @return \WP_REST_Response
	 */
	public function clear_logs() {
		Activity_Logger::clear_logs();

		return rest_ensure_response(
			array(
				'message' => __( 'لاگ‌ها پاک شدند.', 'polymart-ai' ),
			)
		);
	}

	/**
	 * Get translation report entries.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function get_translation_report( $request ) {
		$limit  = min( 100, max( 1, absint( $request->get_param( 'limit' ) ) ?: 25 ) );
		$page   = max( 1, absint( $request->get_param( 'page' ) ) ?: 1 );
		$lang   = sanitize_key( (string) $request->get_param( 'lang' ) );
		$offset = ( $page - 1 ) * $limit;
		$result = Activity_Logger::get_reports_page( $limit, $lang, $offset );

		return rest_ensure_response(
			array(
				'items' => $result['items'],
				'total' => $result['total'],
				'page'  => $page,
				'pages' => (int) max( 1, ceil( $result['total'] / $limit ) ),
			)
		);
	}

	/**
	 * Issue a fresh wp_rest nonce for long-lived admin SPA sessions.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_rest_nonce() {
		return rest_ensure_response(
			array(
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Get current auto-translation job state.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_translation_job() {
		$job = Activity_Logger::get_job( false );

		return rest_ensure_response( $job );
	}

	/**
	 * Control auto-translation job (start, pause, resume, stop).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_translation_job( $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$lang   = $this->resolve_target_language( $request->get_param( 'lang' ) );

		switch ( $action ) {
			case 'start':
				$result = Activity_Logger::start_job( $lang );
				break;
			case 'pause':
				$result = Activity_Logger::pause_job();
				break;
			case 'resume':
				$result = Activity_Logger::resume_job();
				break;
			case 'stop':
				$result = Activity_Logger::stop_job();
				break;
			case 'refresh_stats':
				$result = Activity_Logger::refresh_job_stats( $lang );
				break;
			case 'skip':
				$post_id = absint( $request->get_param( 'post_id' ) );
				$result  = Activity_Logger::skip_current_job_post( $post_id );
				break;
			case 'kick':
				// Heavy tick — prefer ensure from the SPA; kick is recovery/debug.
				$result = Activity_Logger::kick_worker();
				break;
			case 'ensure':
				// Schedule + nudge cron only — browser never owns translation work.
				$result = Activity_Logger::ensure_background_worker();
				break;
			default:
				return new \WP_Error(
					'polymart_ai_invalid_action',
					__( 'عملیات نامعتبر است.', 'polymart-ai' ),
					array( 'status' => 400 )
				);
		}

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Run a short sample translation through ArvanCloud (same path as bulk jobs).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_translation_api( $request ) {
		$incoming = $request->get_json_params();

		if ( ! is_array( $incoming ) ) {
			$incoming = array();
		}

		$text = sanitize_text_field( (string) ( $incoming['text'] ?? 'سلام دنیا' ) );
		$lang = sanitize_key( (string) ( $incoming['lang'] ?? 'en' ) );

		if ( '' === $lang ) {
			$lang = 'en';
		}

		$current     = wp_parse_args( get_option( self::OPTION_KEY, array() ), self::get_default_settings() );
		$translation = self::normalize_translation_settings( $current['translation'] );

		$api_key  = trim( (string) ( $translation['api_key'] ?? '' ) );
		$endpoint = trim( (string) ( $translation['api_endpoint'] ?? '' ) );
		$model    = trim( (string) ( $translation['ai_model'] ?? '' ) );

		if ( '' === $api_key ) {
			return new \WP_Error(
				'polymart_ai_missing_api_key',
				__( 'کلید API در تنظیمات ترجمه تنظیم نشده است.', 'polymart-ai' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $endpoint ) {
			return new \WP_Error(
				'polymart_ai_missing_endpoint',
				__( 'آدرس AI Gateway در تنظیمات ترجمه تنظیم نشده است.', 'polymart-ai' ),
				array( 'status' => 400 )
			);
		}

		$result = AI_Client::test_sample_translation( $text, $lang, $api_key, $endpoint, $model );

		if ( ! empty( $result['success'] ) ) {
			$result['cooldown_cleared'] = Activity_Logger::clear_job_api_cooldown( true );

			if ( ! empty( $result['cooldown_cleared'] ) ) {
				Activity_Logger::log(
					'info',
					__( 'تست API موفق — توقف خودکار آروان برداشته شد و کار ادامه می‌یابد.', 'polymart-ai' )
				);
			}
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Process the auto-translation job worker tick (same path as WP-Cron).
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function process_translation_job_step() {
		try {
			// Unified with cron: multi-step budget, not a separate single-step driver.
			$job = Activity_Logger::kick_worker();
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'polymart_ai_step_crashed',
				sprintf(
					/* translators: %s: exception message */
					__( 'مرحله ترجمه با خطای سرور متوقف شد: %s', 'polymart-ai' ),
					$e->getMessage()
				),
				array( 'status' => 500 )
			);
		}

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		return rest_ensure_response( $job );
	}

	/**
	 * UI string registry stats for the admin panel.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function get_ui_strings_stats( $request ) {
		$lang = $this->resolve_target_language( $request->get_param( 'lang' ) );

		return rest_ensure_response(
			array_merge(
				UI_String_Registry::get_stats( $lang ),
				array(
					'ai_configured' => REST_API::is_ai_configured(),
				)
			)
		);
	}

	/**
	 * Scan companion plugin .pot files and rebuild the UI string catalog.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function scan_ui_strings() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 );
		}

		try {
			$result = UI_String_Scanner::scan_and_save();
		} catch ( \Throwable $exception ) {
			return new \WP_Error(
				'polymart_ai_ui_scan_exception',
				$exception->getMessage(),
				array(
					'status'     => 500,
					'debug_info' => array(
						'exception'     => $exception->getMessage(),
						'wp_plugin_dir' => wp_normalize_path( WP_PLUGIN_DIR ),
					),
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$debug_info = is_array( $error_data ) && isset( $error_data['debug_info'] )
				? $error_data['debug_info']
				: array();

			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array(
					'status'     => is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 400,
					'debug_info' => $debug_info,
				)
			);
		}

		return rest_ensure_response(
			array_merge(
				$result,
				array(
					'ai_configured' => self::is_ai_configured(),
				)
			)
		);
	}

	/**
	 * Sandbox: analyze a single plugin source file for i18n calls.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sandbox_test_ui_string_file( $request ) {
		$params    = $request->get_json_params();
		$file_path = is_array( $params ) && array_key_exists( 'file_path', $params )
			? $this->sanitize_sandbox_file_path_param( $params['file_path'] )
			: '';

		if ( '' === $file_path ) {
			$file_path = $this->sanitize_sandbox_file_path_param( $request->get_param( 'file_path' ) );
		}

		if ( '' === $file_path ) {
			$params = $request->get_body_params();
			$file_path = is_array( $params ) && array_key_exists( 'file_path', $params )
				? $this->sanitize_sandbox_file_path_param( $params['file_path'] )
				: '';
		}

		if ( '' === $file_path ) {
			return new \WP_Error(
				'polymart_ai_sandbox_empty_path',
				__( 'مسیر فایل الزامی است.', 'polymart-ai' ),
				array( 'status' => 400 )
			);
		}

		try {
			$result = UI_String_Source_Scanner::analyze_file( $file_path );
		} catch ( \Throwable $exception ) {
			return new \WP_Error(
				'polymart_ai_sandbox_exception',
				$exception->getMessage(),
				array(
					'status'     => 500,
					'input_path' => $file_path,
				)
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Sanitize a sandbox path request value without stripping filesystem separators.
	 *
	 * @param mixed            $value   Raw request value.
	 * @param \WP_REST_Request $request Optional REST request.
	 * @param string           $param   Optional parameter name.
	 * @return string
	 */
	public function sanitize_sandbox_file_path_param( $value, $request = null, $param = '' ) {
		unset( $request, $param );

		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
		$value = str_replace( "\0", '', (string) $value );

		return trim( $value );
	}

	/**
	 * Get UI string bulk job state.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_ui_strings_job() {
		return rest_ensure_response( UI_String_Bulk_Job::get_job() );
	}

	/**
	 * Control UI string bulk job (start, pause, resume, stop).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_ui_strings_job( $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$lang   = $this->resolve_target_language( $request->get_param( 'lang' ) );

		switch ( $action ) {
			case 'start':
				$result = UI_String_Bulk_Job::start_job( $lang );
				break;
			case 'pause':
				$result = UI_String_Bulk_Job::pause_job();
				break;
			case 'resume':
				$result = UI_String_Bulk_Job::resume_job();
				break;
			case 'stop':
				$result = UI_String_Bulk_Job::stop_job();
				break;
			default:
				return new \WP_Error(
					'polymart_ai_invalid_action',
					__( 'عملیات نامعتبر است.', 'polymart-ai' ),
					array( 'status' => 400 )
				);
		}

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array_merge(
				UI_String_Registry::get_stats( $lang ),
				array( 'job' => $result )
			)
		);
	}

	/**
	 * Process one batch of the UI string bulk job.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function process_ui_strings_job_step() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 );
		}

		try {
			$result = UI_String_Bulk_Job::process_step();
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'polymart_ai_ui_step_crashed',
				sprintf(
					/* translators: %s: exception message */
					__( 'مرحله ترجمه رشته‌های UI با خطای سرور متوقف شد: %s', 'polymart-ai' ),
					$e->getMessage()
				),
				array( 'status' => 500 )
			);
		}

		$lang = sanitize_key( (string) ( $result['lang'] ?? 'en' ) );

		return rest_ensure_response(
			array(
				'translated'   => UI_String_Registry::count_translated( $lang ),
				'untranslated' => UI_String_Registry::count_untranslated( $lang ),
				'string_count' => (int) ( UI_String_Registry::get_registry()['string_count'] ?? 0 ),
				'job'          => $result,
			)
		);
	}

	/**
	 * Manually drain pending runtime strings and due async translation jobs.
	 *
	 * Useful for warm-up before launch or when DISABLE_WP_CRON is enabled.
	 *
	 * @return \WP_REST_Response
	 */
	public function process_background_queues( $request ) {
		$passes    = absint( $request->get_param( 'passes' ) );
		$processed = Async_Translator::process_due_queue_events( $passes > 0 ? $passes : 15 );
		$queue     = Async_Translator::get_queue_status();

		$remaining = (int) ( $queue['pending_strings'] ?? 0 )
			+ (int) ( $queue['scheduled_posts'] ?? 0 )
			+ (int) ( $queue['scheduled_terms'] ?? 0 );

		$message = $remaining > 0
			? sprintf(
				/* translators: %d: remaining queued jobs */
				__( 'یک دور پردازش انجام شد. %d مورد هنوز در صف است — در صورت نیاز دوباره بزنید.', 'polymart-ai' ),
				$remaining
			)
			: __( 'صف‌های ترجمه پس‌زمینه خالی شدند.', 'polymart-ai' );

		return rest_ensure_response(
			array(
				'success'       => true,
				'processed'     => $processed,
				'queue'         => $queue,
				'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'message'       => $message,
			)
		);
	}

	/**
	 * Align WVE variation translation meta keys with the canonical storage layout.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function resync_variation_translations( $request ) {
		$args = array(
			'dry_run' => rest_sanitize_boolean( $request->get_param( 'dry_run' ) ),
			'lang'    => sanitize_key( (string) $request->get_param( 'lang' ) ),
			'limit'   => absint( $request->get_param( 'limit' ) ),
			'offset'  => absint( $request->get_param( 'offset' ) ),
		);

		if ( rest_sanitize_boolean( $request->get_param( 'all' ) ) ) {
			$result = Variation_Translation_Sync::run_all( $args );
		} else {
			$result = Variation_Translation_Sync::run( $args );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Record a translation in the report log.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @param string $source  Source type.
	 * @return void
	 */
	private function record_translation_report( $post_id, $lang, $source ) {
		$title_source     = get_the_title( $post_id );
		$title_translated = (string) get_post_meta( $post_id, Post_Translator::get_meta_key( 'title', $lang ), true );

		if ( '' === trim( $title_translated ) ) {
			return;
		}

		Activity_Logger::log_report( $post_id, $lang, $title_source, $title_translated, $source );
	}
}
