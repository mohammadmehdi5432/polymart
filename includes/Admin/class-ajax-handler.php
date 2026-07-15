<?php
/**
 * AJAX handler for on-demand AI translation generation.
 *
 * @package PolymartAI\Admin
 */

namespace PolymartAI\Admin;

use PolymartAI\Language_Registry;
use PolymartAI\Activity_Logger\Metabox_Action_Scheduler;
use PolymartAI\Translation\Post_Translator;


defined( 'ABSPATH' ) || exit;

/**
 * Class Ajax_Handler
 */
final class Ajax_Handler {

	/**
	 * Nonce action for AJAX translation requests.
	 */
	const NONCE_ACTION = 'polymart_generate_translation';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_polymart_generate_translation', array( $this, 'handle_generate_translation' ) );
		add_action( 'wp_ajax_polymart_retranslate_product', array( $this, 'handle_retranslate_product' ) );
		add_action( 'wp_ajax_polymart_scan_translation_gaps', array( $this, 'handle_scan_translation_gaps' ) );
		add_action( 'wp_ajax_polymart_translate_post_complete', array( $this, 'handle_translate_post_complete' ) );
		add_action( 'wp_ajax_polymart_metabox_translation_status', array( $this, 'handle_metabox_translation_status' ) );
		add_action( 'wp_ajax_polymart_release_translation_lock', array( $this, 'handle_release_translation_lock' ) );
		add_action( 'wp_ajax_polymart_debug_elementor_toxic', array( $this, 'handle_debug_elementor_toxic' ) );
	}

	/**
	 * Generate English translations for a post via ArvanCloud AI.
	 *
	 * @return void
	 */
	public function handle_generate_translation() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$lang    = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : 'en';

		if ( ! $post_id ) {
			wp_send_json_error(
				array( 'message' => __( 'شناسه مطلب معتبر الزامی است.', 'polymart-ai' ) ),
				400
			);
		}

		if ( ! self::is_valid_target_language( $lang ) ) {
			wp_send_json_error(
				array( 'message' => __( 'زبان مقصد نامعتبر است.', 'polymart-ai' ) ),
				400
			);
		}

		$result = Post_Translator::request_ai_translation( $post_id, $lang );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				$this->error_status_code( $result )
			);
		}

		$save_result = Post_Translator::save_ai_translations( $post_id, $result['translations'], $lang );

		if ( is_wp_error( $save_result ) ) {
			if ( 'polymart_ai_elementor_partial' === $save_result->get_error_code() ) {
				wp_send_json_success( $this->build_generate_translation_payload( $post_id, $lang, true, $save_result->get_error_message() ) );
			}

			wp_send_json_error(
				array( 'message' => $save_result->get_error_message() ),
				$this->error_status_code( $save_result )
			);
		}

		wp_send_json_success( $this->build_generate_translation_payload( $post_id, $lang ) );
	}

	/**
	 * Force a full AI re-translation for every enabled target language on one post.
	 *
	 * @return void
	 */
	public function handle_retranslate_product() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error(
				array( 'message' => __( 'شناسه مطلب معتبر الزامی است.', 'polymart-ai' ) ),
				400
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'شما اجازه ترجمه این مورد را ندارید.', 'polymart-ai' ) ),
				403
			);
		}

		$languages = Language_Registry::get_translation_target_languages();
		$lang_codes = array();

		foreach ( $languages as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $lang || ! self::is_valid_target_language( $lang ) ) {
				continue;
			}

			$lang_codes[] = $lang;
		}

		if ( Post_Translator::uses_elementor_builder( $post_id ) && Metabox_Action_Scheduler::is_available() ) {
			Metabox_Action_Scheduler::force_unlock_post( $post_id );

			$queued = Metabox_Action_Scheduler::start_batch_translations(
				$post_id,
				$lang_codes,
				array(
					'force'  => true,
					'unlock' => true,
				)
			);

			if ( is_wp_error( $queued ) ) {
				wp_send_json_error(
					array( 'message' => $queued->get_error_message() ),
					500
				);
			}

			wp_send_json_success(
				array(
					'queued'  => true,
					'done'    => false,
					'message' => __( 'ترجمه مجدد همه زبان‌ها در پس‌زمینه شروع شد — پیشرفت را در همین صفحه می‌بینید.', 'polymart-ai' ),
					'scan'    => self::build_scan_response( $post_id, sanitize_key( (string) ( $lang_codes[0] ?? 'en' ) ) ),
					'batch'   => get_post_meta( $post_id, Metabox_Action_Scheduler::BATCH_META_KEY, true ),
				)
			);
		}

		$fields    = array();
		$errors    = array();
		$successes = array();

		foreach ( $languages as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $lang || ! self::is_valid_target_language( $lang ) ) {
				continue;
			}

			$result = Post_Translator::retranslate_post( $post_id, $lang );

			if ( is_wp_error( $result ) ) {
				$label    = ! empty( $language['native_name'] ) ? (string) $language['native_name'] : $lang;
				$errors[] = $label . ': ' . $result->get_error_message();
				continue;
			}

			$lang_label = ! empty( $language['native_name'] ) ? (string) $language['native_name'] : $lang;
			$successes[] = $lang_label;

			foreach ( array( 'title', 'excerpt', 'content' ) as $field ) {
				$meta_key            = Post_Translator::get_meta_key( $field, $lang );
				$fields[ Post_Translator::get_form_field_name( $meta_key ) ] = (string) get_post_meta( $post_id, $meta_key, true );
			}

			foreach ( Post_Translator::CUSTOM_META_KEYS as $meta_key ) {
				$translated_key = Post_Translator::get_custom_meta_key( $meta_key, $lang );
				$fields[ Post_Translator::get_form_field_name( $translated_key ) ] = (string) get_post_meta( $post_id, $translated_key, true );
			}
		}

		if ( empty( $successes ) ) {
			wp_send_json_error(
				array(
					'message' => ! empty( $errors )
						? implode( ' ', $errors )
						: __( 'ترجمه مجدد ناموفق بود.', 'polymart-ai' ),
				),
				500
			);
		}

		$message = sprintf(
			/* translators: %s: comma-separated language names */
			__( 'ترجمه مجدد و ذخیره برای این زبان‌ها انجام شد: %s', 'polymart-ai' ),
			implode( '، ', $successes )
		);

		if ( ! empty( $errors ) ) {
			$message .= ' ' . implode( ' ', $errors );
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'fields'  => $fields,
			)
		);
	}

	/**
	 * Scan translatable fields and report gaps for one post and language.
	 *
	 * @return void
	 */
	public function handle_scan_translation_gaps() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$lang    = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : 'en';

		if ( ! $post_id ) {
			wp_send_json_error(
				array( 'message' => __( 'شناسه مطلب معتبر الزامی است.', 'polymart-ai' ) ),
				400
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'شما اجازه بررسی این مورد را ندارید.', 'polymart-ai' ) ),
				403
			);
		}

		if ( ! self::is_valid_target_language( $lang ) ) {
			wp_send_json_error(
				array( 'message' => __( 'زبان مقصد نامعتبر است.', 'polymart-ai' ) ),
				400
			);
		}

		Post_Translator::repair_stale_elementor_job_state( $post_id, $lang );
		Post_Translator::unblock_elementor_chunk_queue( $post_id, $lang );

		wp_send_json_success( self::build_scan_response( $post_id, $lang ) );
	}

	/**
	 * Run chunked translation slices until complete or the request budget expires.
	 *
	 * Uses the same job-slice pipeline as bulk auto-translate (Elementor-safe).
	 *
	 * @return void
	 */
	public function handle_translate_post_complete() {
		if ( ! headers_sent() && function_exists( 'ob_start' ) ) {
			@ob_start();
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$lang    = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : 'en';
		$force    = ! empty( $_POST['force'] );
		$continue = ! empty( $_POST['continue_job'] );
		$unlock   = ! empty( $_POST['unlock'] ) || $force || $continue;

		if ( ! $post_id ) {
			if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
				@ob_clean();
			}
			wp_send_json_error(
				array( 'message' => __( 'شناسه مطلب معتبر الزامی است.', 'polymart-ai' ) ),
				400
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
				@ob_clean();
			}
			wp_send_json_error(
				array( 'message' => __( 'شما اجازه ترجمه این مورد را ندارید.', 'polymart-ai' ) ),
				403
			);
		}

		if ( ! self::is_valid_target_language( $lang ) ) {
			if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
				@ob_clean();
			}
			wp_send_json_error(
				array( 'message' => __( 'زبان مقصد نامعتبر است.', 'polymart-ai' ) ),
				400
			);
		}

		if ( $unlock ) {
			Metabox_Action_Scheduler::force_unlock_post( $post_id, $lang );
		}

		Post_Translator::repair_stale_elementor_job_state( $post_id, $lang );
		Post_Translator::unblock_elementor_chunk_queue( $post_id, $lang );

		if ( Post_Translator::uses_elementor_builder( $post_id ) && Metabox_Action_Scheduler::is_available() ) {
			if ( $force ) {
				Post_Translator::clear_post_language_translations( $post_id, $lang );
				Post_Translator::clear_job_partial_state( $post_id, $lang );
				delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
				delete_post_meta( $post_id, Metabox_Action_Scheduler::BATCH_META_KEY );
			}

			if ( ! Post_Translator::prepare_admin_metabox_translation_lock( $post_id, $lang, $unlock ) ) {
				if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
					@ob_clean();
				}
				wp_send_json_error(
					array(
						'message' => self::format_lock_blocked_message( $post_id, $lang ),
						'scan'    => self::build_scan_response( $post_id, $lang ),
						'locked'  => true,
					),
					409
				);
			}

			Post_Translator::release_translation_lock( $post_id, $lang, true );

			$queued = Metabox_Action_Scheduler::start_translation(
				$post_id,
				$lang,
				array(
					'force'  => $force,
					'unlock' => $unlock,
				)
			);

			if ( is_wp_error( $queued ) ) {
				if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
					@ob_clean();
				}
				wp_send_json_error(
					array(
						'message' => $queued->get_error_message(),
						'scan'    => self::build_scan_response( $post_id, $lang ),
					),
					500
				);
			}

			$action_id = absint( $queued );

			if ( $action_id <= 0 ) {
				$message = __( 'خطا در ثبت صف Action Scheduler — کار در پس‌زمینه شروع نشد.', 'polymart-ai' );
				update_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, $message );

				if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
					@ob_clean();
				}
				wp_send_json_error(
					array(
						'message' => $message,
						'scan'    => self::build_scan_response( $post_id, $lang ),
					),
					500
				);
			}

			$poll = Metabox_Action_Scheduler::build_poll_response( $post_id, $lang );
			$scan = self::build_scan_response( $post_id, $lang );

			if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
				@ob_clean();
			}
			wp_send_json_success(
				array(
					'queued'         => true,
					'action_id'      => $action_id,
					'done'           => false,
					'background'     => true,
					'phase'          => (string) ( $poll['phase'] ?? '' ),
					'phase_progress' => (string) ( $poll['phase_progress'] ?? '' ),
					'message'        => __( 'ترجمه Elementor در پس‌زمینه شروع شد — وضعیت هر چند ثانیه به‌روز می‌شود.', 'polymart-ai' ),
					'scan'           => $scan,
					'fields'         => self::collect_meta_fields_for_response( $post_id, $lang ),
				)
			);
		}

		if ( $force ) {
			Post_Translator::clear_post_language_translations( $post_id, $lang );
			Post_Translator::clear_job_partial_state( $post_id, $lang );
			delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
		}

		if ( ! \PolymartAI\Activity_Logger::is_bulk_job_running() ) {
			\PolymartAI\Activity_Logger::clear_job_api_cooldown( false );
		}

		if ( ! Post_Translator::prepare_admin_metabox_translation_lock( $post_id, $lang, $unlock ) ) {
			if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
				@ob_clean();
			}
			wp_send_json_error(
				array(
					'message' => self::format_lock_blocked_message( $post_id, $lang ),
					'scan'    => self::build_scan_response( $post_id, $lang ),
					'locked'  => true,
				),
				409
			);
		}

		$result = self::run_translation_slices_until_done( $post_id, $lang, $unlock );

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
				@ob_clean();
			}
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'scan'    => is_array( $error_data ) && ! empty( $error_data['scan'] )
						? $error_data['scan']
						: self::build_scan_response( $post_id, $lang ),
					'locked'  => is_array( $error_data ) && ! empty( $error_data['locked'] ),
				),
				$this->error_status_code( $result )
			);
		}

		$scan = self::build_scan_response( $post_id, $lang );

		if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
			@ob_clean();
		}
		wp_send_json_success(
			array_merge(
				$result,
				array(
					'fields'  => self::collect_meta_fields_for_response( $post_id, $lang ),
					'scan'    => $scan,
					'message' => self::format_metabox_translate_response_message( $post_id, $lang, $result, $scan ),
				)
			)
		);
	}

	/**
	 * Lightweight metabox progress poll (reads post meta only — no AI work).
	 *
	 * @return void
	 */
	public function handle_metabox_translation_status() {
		if ( ! headers_sent() && function_exists( 'ob_start' ) ) {
			@ob_start();
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$lang    = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : 'en';
		$unlock  = ! empty( $_POST['unlock'] );

		if ( ! $post_id ) {
			if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
				@ob_clean();
			}
			wp_send_json_error(
				array( 'message' => __( 'شناسه مطلب معتبر الزامی است.', 'polymart-ai' ) ),
				400
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
				@ob_clean();
			}
			wp_send_json_error(
				array( 'message' => __( 'شما اجازه بررسی این مورد را ندارید.', 'polymart-ai' ) ),
				403
			);
		}

		if ( ! self::is_valid_target_language( $lang ) ) {
			if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
				@ob_clean();
			}
			wp_send_json_error(
				array( 'message' => __( 'زبان مقصد نامعتبر است.', 'polymart-ai' ) ),
				400
			);
		}

		if ( $unlock ) {
			Post_Translator::release_stale_translation_lock( $post_id, $lang, 90 );
		}

		if ( Post_Translator::uses_elementor_builder( $post_id ) && Metabox_Action_Scheduler::is_available() ) {
			Metabox_Action_Scheduler::run_poll_recovery( $post_id, $lang );
		}

		$poll = Metabox_Action_Scheduler::build_poll_response( $post_id, $lang );
		$scan = self::build_scan_response( $post_id, $lang );

		if ( ! empty( $poll['failed'] ) ) {
			if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
				@ob_clean();
			}
			wp_send_json_error(
				array(
					'message' => ! empty( $poll['error'] )
						? (string) $poll['error']
						: (string) ( $poll['message'] ?? __( 'ترجمه در پس‌زمینه ناموفق بود.', 'polymart-ai' ) ),
					'scan'    => $scan,
					'done'    => false,
					'queued'  => false,
				),
				500
			);
		}

		if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
			@ob_clean();
		}
		wp_send_json_success(
			array(
				'done'           => ! empty( $poll['done'] ),
				'queued'         => ! empty( $poll['queued'] ),
				'background'     => true,
				'phase'          => (string) ( $poll['phase'] ?? '' ),
				'phase_progress' => (string) ( $poll['phase_progress'] ?? '' ),
				'message'        => self::format_metabox_translate_response_message(
					$post_id,
					$lang,
					array(
						'done'           => ! empty( $poll['done'] ),
						'phase_progress' => (string) ( $poll['phase_progress'] ?? '' ),
						'message'        => (string) ( $poll['message'] ?? '' ),
					),
					$scan
				),
				'scan'           => $scan,
				'fields'         => self::collect_meta_fields_for_response( $post_id, $lang ),
				'batch'          => $poll['batch'] ?? null,
				'updated_at'     => absint( $poll['updated_at'] ?? 0 ),
				'worker_mode'    => (string) ( $poll['worker_mode'] ?? 'as' ),
			)
		);
	}

	/**
	 * Expose Elementor toxic payload + last error meta (admin-only).
	 *
	 * @return void
	 */
	public function handle_debug_elementor_toxic() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$lang    = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : 'en';

		if ( ! $post_id || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'شما اجازه این عملیات را ندارید.', 'polymart-ai' ) ), 403 );
		}

		$toxic = get_post_meta( $post_id, \PolymartAI\Activity_Logger\Metabox_Action_Scheduler::TOXIC_META_PREFIX . $lang, true );
		$error = (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true );

		wp_send_json_success(
			array(
				'post_id' => $post_id,
				'lang'    => $lang,
				'toxic'   => $toxic,
				'error'   => $error,
			)
		);
	}

	/**
	 * Force-release a stale per-post translation lock from the edit screen.
	 *
	 * @return void
	 */
	public function handle_release_translation_lock() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$lang    = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : 'en';

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'شما اجازه این عملیات را ندارید.', 'polymart-ai' ) ),
				403
			);
		}

		if ( ! self::is_valid_target_language( $lang ) ) {
			wp_send_json_error(
				array( 'message' => __( 'زبان مقصد نامعتبر است.', 'polymart-ai' ) ),
				400
			);
		}

		Post_Translator::prepare_admin_metabox_translation_lock( $post_id, $lang, true );
		Metabox_Action_Scheduler::force_unlock_post( $post_id, $lang );
		Metabox_Action_Scheduler::cancel_pending( $post_id, $lang );
		Metabox_Action_Scheduler::clear_status( $post_id, $lang );

		wp_send_json_success(
			array(
				'message' => __( 'قفل ترجمه آزاد شد — دوباره «ترجمه و تکمیل» را بزنید.', 'polymart-ai' ),
				'scan'    => self::build_scan_response( $post_id, $lang ),
			)
		);
	}

	/**
	 * Execute bounded translation slices for one post/language pair.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @param bool   $unlock  Whether stale locks were already cleared.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function run_translation_slices_until_done( $post_id, $lang, $unlock = false ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		\PolymartAI\Activity_Logger::bootstrap_job_worker_context();

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 );
		}

		$uses_elementor = Post_Translator::uses_elementor_builder( $post_id );
		$started_at     = microtime( true );
		$budget_sec     = (int) apply_filters(
			'polymart_ai_metabox_translate_budget_sec',
			$uses_elementor ? 150 : 90,
			$post_id,
			$lang
		);
		$max_slices     = (int) apply_filters(
			'polymart_ai_metabox_translate_max_slices',
			$uses_elementor ? 12 : 8,
			$post_id,
			$lang
		);
		$slices_run   = 0;
		$last_slice   = array();
		$lock_retried = $unlock;
		$lock_held    = false;

		try {
			if ( ! Post_Translator::acquire_translation_lock( $post_id, $lang ) ) {
				if ( Post_Translator::prepare_admin_metabox_translation_lock( $post_id, $lang, true )
					&& Post_Translator::acquire_translation_lock( $post_id, $lang )
				) {
					$lock_held = true;
				} else {
					return new \WP_Error(
						'polymart_ai_translation_in_progress',
						self::format_lock_blocked_message( $post_id, $lang ),
						array(
							'locked' => true,
							'scan'   => self::build_scan_response( $post_id, $lang ),
						)
					);
				}
			} else {
				$lock_held = true;
			}

			while ( $slices_run < $max_slices && ( microtime( true ) - $started_at ) < max( 30, $budget_sec ) ) {
				Post_Translator::touch_translation_lock( $post_id, $lang );
				Post_Translator::unblock_elementor_chunk_queue( $post_id, $lang );

				\PolymartAI\Activity_Logger::log(
					'info',
					sprintf(
						/* translators: 1: post ID, 2: language code, 3: slice number */
						__( 'متاباکس — #%1$d (%2$s): شروع slice ترجمه #%3$d…', 'polymart-ai' ),
						$post_id,
						$lang,
						$slices_run + 1
					),
					array( 'post_id' => $post_id, 'lang' => $lang, 'source' => 'metabox' )
				);

				$slice = Post_Translator::process_job_translation_slice( $post_id, $lang, false );
				++$slices_run;
				$last_slice = is_array( $slice ) ? $slice : array();

				if (
					is_wp_error( $slice )
					&& 'polymart_ai_translation_in_progress' === $slice->get_error_code()
					&& ! $lock_retried
				) {
					Post_Translator::prepare_admin_metabox_translation_lock( $post_id, $lang, true );
					$lock_retried = true;
					$slice        = Post_Translator::process_job_translation_slice( $post_id, $lang, false );
					++$slices_run;
					$last_slice   = is_array( $slice ) ? $slice : array();
				}

				if ( is_wp_error( $slice ) ) {
					if ( 'polymart_ai_translation_in_progress' === $slice->get_error_code() ) {
						return new \WP_Error(
							'polymart_ai_translation_in_progress',
							self::format_lock_blocked_message( $post_id, $lang ),
							array(
								'locked' => true,
								'scan'   => self::build_scan_response( $post_id, $lang ),
							)
						);
					}

					if (
						Post_Translator::is_recoverable_job_slice_error( $slice )
						&& Post_Translator::job_partial_state_has_progress( $post_id, $lang )
					) {
						return array(
							'done'           => false,
							'phase'          => (string) ( $last_slice['phase'] ?? '' ),
							'phase_progress' => (string) ( $last_slice['phase_progress'] ?? '' ),
							'message'        => $slice->get_error_message(),
							'status'         => Post_Translator::get_translation_status( $post_id, $lang ),
							'slices_run'     => $slices_run,
							'recoverable'    => true,
						);
					}

					return $slice;
				}

				if ( ! empty( $slice['done'] ) ) {
					return array(
						'done'           => true,
						'phase'          => (string) ( $slice['phase'] ?? 'complete' ),
						'phase_progress' => (string) ( $slice['phase_progress'] ?? '' ),
						'message'        => (string) ( $slice['message'] ?? '' ),
						'status'         => Post_Translator::get_translation_status( $post_id, $lang ),
						'slices_run'     => $slices_run,
					);
				}
			}

			return array(
				'done'           => false,
				'phase'          => (string) ( $last_slice['phase'] ?? '' ),
				'phase_progress' => (string) ( $last_slice['phase_progress'] ?? '' ),
				'message'        => ! empty( $last_slice['message'] )
					? (string) $last_slice['message']
					: __( 'ترجمه در حال انجام است — درخواست بعدی ادامه می‌دهد.', 'polymart-ai' ),
				'status'         => Post_Translator::get_translation_status( $post_id, $lang ),
				'slices_run'     => $slices_run,
				'scan'           => self::build_scan_response( $post_id, $lang ),
			);
		} finally {
			if ( $lock_held ) {
				Post_Translator::release_translation_lock( $post_id, $lang );
			}
		}
	}

	/**
	 * Keep metabox progress text aligned with the scan panel (same counters).
	 *
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Target language code.
	 * @param array<string, mixed> $result  Slice runner result.
	 * @param array<string, mixed> $scan    Scan payload.
	 * @return string
	 */
	private static function format_metabox_translate_response_message( $post_id, $lang, array $result, array $scan ) {
		unset( $post_id );

		$language   = Language_Registry::get_language( $lang );
		$lang_label = $language ? $language['native_name'] : $lang;

		if ( ! empty( $result['done'] ) ) {
			return sprintf(
				/* translators: %s: language name */
				__( 'ترجمه %s تکمیل و ذخیره شد.', 'polymart-ai' ),
				$lang_label
			);
		}

		$elementor = is_array( $scan['elementor'] ?? null ) ? $scan['elementor'] : array();

		if ( ! empty( $elementor['active'] ) && ! empty( $elementor['chunk_progress'] ) ) {
			$parts = explode( '/', (string) $elementor['chunk_progress'] );
			$done  = isset( $parts[0] ) ? absint( $parts[0] ) : 0;
			$total = isset( $parts[1] ) ? max( 1, absint( $parts[1] ) ) : 1;

			$message = sprintf(
				/* translators: 1: completed batches, 2: total batches */
				__( 'Elementor — %1$d از %2$d بخش ذخیره شد', 'polymart-ai' ),
				$done,
				$total
			);

			$remaining = absint( $elementor['remaining_field_count'] ?? 0 );

			if ( $remaining > 0 ) {
				$message .= sprintf(
					/* translators: %d: remaining JSON field count */
					__( ' — %d فیلد JSON مانده', 'polymart-ai' ),
					$remaining
				);
			}

			return $message;
		}

		return (string) ( $result['message'] ?? __( 'ترجمه در حال انجام است…', 'polymart-ai' ) );
	}

	/**
	 * Build scan payload for the meta box UI.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return array<string, mixed>
	 */
	private static function build_scan_response( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$gaps    = Post_Translator::get_translation_gaps( $post_id, $lang );
		$status  = (string) ( $gaps['status'] ?? Post_Translator::get_translation_status( $post_id, $lang ) );
		$fields  = array();

		foreach ( (array) ( $gaps['fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$fields[] = array(
				'key'        => (string) ( $field['key'] ?? '' ),
				'label'      => (string) ( $field['label'] ?? '' ),
				'translated' => ! empty( $field['translated'] ),
				'meta_key'   => (string) ( $field['meta_key'] ?? '' ),
			);
		}

		$language   = Language_Registry::get_language( $lang );
		$lang_label = $language ? $language['native_name'] : $lang;
		$progress   = '';

		if ( Post_Translator::uses_elementor_builder( $post_id ) ) {
			$state = Post_Translator::get_job_partial_state( $post_id, $lang );

			if ( ! empty( $state['phase'] ) && 'elementor' === (string) $state['phase'] ) {
				$progress = Post_Translator::format_elementor_job_progress_marker( $post_id, $lang, $state );
			}
		}

		$elementor = Post_Translator::get_elementor_scan_diagnostics( $post_id, $lang );
		$lock      = Post_Translator::get_translation_lock_status( $post_id, $lang );

		return array(
			'lang'               => $lang,
			'lang_label'         => $lang_label,
			'status'             => $status,
			'status_label'       => self::get_status_label_for_scan( $status ),
			'fields'             => $fields,
			'missing'            => array_values( array_map( 'strval', (array) ( $gaps['missing'] ?? array() ) ) ),
			'notes'              => array_values( array_map( 'strval', (array) ( $gaps['notes'] ?? array() ) ) ),
			'uses_elementor'     => Post_Translator::uses_elementor_builder( $post_id ),
			'elementor_error'    => (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true ),
			'elementor_progress' => $progress,
			'elementor'          => $elementor,
			'lock'               => $lock,
			'needs_work'         => 'translated' !== $status || ! empty( $gaps['missing'] ),
		);
	}

	/**
	 * Explain why a metabox translate action is blocked by a lock.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return string
	 */
	private static function format_lock_blocked_message( $post_id, $lang ) {
		$diag = Post_Translator::get_elementor_scan_diagnostics( $post_id, $lang );

		if ( ! empty( $diag['bulk_job_on_post'] ) ) {
			return __( 'ترجمه خودکار همین برگه را روی سرور در حال پردازش است — چند لحظه صبر کنید یا ترجمه خودکار را موقتاً متوقف کنید.', 'polymart-ai' );
		}

		if ( ! empty( $diag['bulk_job_running'] ) ) {
			return __( 'یک ترجمه خودکار در حال اجراست و قفل این برگه را نگه داشته — «آزاد کردن قفل» را بزنید یا ترجمه خودکار را متوقف کنید.', 'polymart-ai' );
		}

		return __( 'قفل ترجمه گیر کرده — دکمه «آزاد کردن قفل» را بزنید و دوباره تلاش کنید.', 'polymart-ai' );
	}

	/**
	 * @param string $status Status slug.
	 * @return string
	 */
	private static function get_status_label_for_scan( $status ) {
		$labels = array(
			'translated'   => __( 'کامل', 'polymart-ai' ),
			'partial'      => __( 'ناقص', 'polymart-ai' ),
			'untranslated' => __( 'ترجمه‌نشده', 'polymart-ai' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Collect metabox form field values after translation.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return array<string, string>
	 */
	private static function collect_meta_fields_for_response( $post_id, $lang ) {
		$post_id     = absint( $post_id );
		$lang        = sanitize_key( (string) $lang );
		$meta_fields = array();

		foreach ( array( 'title', 'excerpt', 'content' ) as $field ) {
			$meta_key = Post_Translator::get_meta_key( $field, $lang );
			$meta_fields[ Post_Translator::get_form_field_name( $meta_key ) ] = (string) get_post_meta( $post_id, $meta_key, true );
		}

		foreach ( Post_Translator::CUSTOM_META_KEYS as $meta_key ) {
			$translated_key = Post_Translator::get_custom_meta_key( $meta_key, $lang );
			$meta_fields[ Post_Translator::get_form_field_name( $translated_key ) ] = (string) get_post_meta( $post_id, $translated_key, true );
		}

		foreach ( array_keys( Post_Translator::collect_discovered_meta_fields( $post_id ) ) as $meta_key ) {
			$translated_key = Post_Translator::get_custom_meta_key( $meta_key, $lang );
			$meta_fields[ Post_Translator::get_form_field_name( $translated_key ) ] = (string) get_post_meta( $post_id, $translated_key, true );
		}

		return $meta_fields;
	}

	/**
	 * Whether a language code is an enabled translation target.
	 *
	 * @param string $lang Language code.
	 * @return bool
	 */
	private static function is_valid_target_language( $lang ) {
		foreach ( Language_Registry::get_translation_target_languages() as $language ) {
			if ( $language['code'] === $lang ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Map translation errors to HTTP status codes for JSON responses.
	 *
	 * @param \WP_Error $error Translation error.
	 * @return int
	 */
	private function error_status_code( \WP_Error $error ) {
		$client_errors = array(
			'polymart_ai_forbidden',
			'polymart_ai_invalid_post',
			'polymart_ai_missing_credentials',
			'polymart_ai_empty_source',
			'polymart_ai_translation_in_progress',
			'polymart_ai_elementor_partial',
			'polymart_ai_elementor_no_translations',
		);

		if ( in_array( $error->get_error_code(), $client_errors, true ) ) {
			if ( 'polymart_ai_forbidden' === $error->get_error_code() ) {
				return 403;
			}

			if ( 'polymart_ai_translation_in_progress' === $error->get_error_code() ) {
				return 409;
			}

			if (
				in_array(
					$error->get_error_code(),
					array( 'polymart_ai_elementor_partial', 'polymart_ai_elementor_no_translations' ),
					true
				)
			) {
				return 422;
			}

			return 400;
		}

		return 500;
	}

	/**
	 * Build metabox generate-translation JSON payload.
	 *
	 * @param int    $post_id           Post ID.
	 * @param string $lang              Target language code.
	 * @param bool   $elementor_partial Whether Elementor persistence was partial.
	 * @param string $detail_message    Optional detail message.
	 * @return array<string, mixed>
	 */
	private function build_generate_translation_payload( $post_id, $lang, $elementor_partial = false, $detail_message = '' ) {
		$lang     = sanitize_key( (string) $lang );
		$language = Language_Registry::get_language( $lang );
		$lang_label = $language ? $language['native_name'] : $lang;
		$status     = Post_Translator::get_translation_status( $post_id, $lang );
		$meta_fields = array();

		foreach ( array( 'title', 'excerpt', 'content' ) as $field ) {
			$meta_key = Post_Translator::get_meta_key( $field, $lang );
			$meta_fields[ Post_Translator::get_form_field_name( $meta_key ) ] = (string) get_post_meta( $post_id, $meta_key, true );
		}

		foreach ( Post_Translator::CUSTOM_META_KEYS as $meta_key ) {
			$translated_key = Post_Translator::get_custom_meta_key( $meta_key, $lang );
			$meta_fields[ Post_Translator::get_form_field_name( $translated_key ) ] = (string) get_post_meta( $post_id, $translated_key, true );
		}

		foreach ( array_keys( Post_Translator::collect_discovered_meta_fields( $post_id ) ) as $meta_key ) {
			$translated_key = Post_Translator::get_custom_meta_key( $meta_key, $lang );
			$meta_fields[ Post_Translator::get_form_field_name( $translated_key ) ] = (string) get_post_meta( $post_id, $translated_key, true );
		}

		if ( 'translated' === $status ) {
			$message = sprintf(
				/* translators: %s: language name */
				__( 'ترجمه %s تولید و در دیتابیس ذخیره شد.', 'polymart-ai' ),
				$lang_label
			);
		} elseif ( $elementor_partial && '' !== $detail_message ) {
			$message = $detail_message;
		} else {
			$message = sprintf(
				/* translators: 1: language name, 2: status */
				__( 'ترجمه %1$s ذخیره شد اما وضعیت «%2$s» است — ممکن است Elementor یا فیلدهای دیگر ناقص باشند.', 'polymart-ai' ),
				$lang_label,
				$status
			);
		}

		return array(
			'message'           => $message,
			'fields'            => $meta_fields,
			'lang'              => $lang,
			'status'            => $status,
			'elementor_partial' => (bool) $elementor_partial,
		);
	}
}
