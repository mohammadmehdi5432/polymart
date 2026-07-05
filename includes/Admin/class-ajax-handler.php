<?php
/**
 * AJAX handler for on-demand AI translation generation.
 *
 * @package PolymartAI\Admin
 */

namespace PolymartAI\Admin;

use PolymartAI\Language_Registry;
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
			wp_send_json_error(
				array( 'message' => $save_result->get_error_message() ),
				500
			);
		}

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

		$language = Language_Registry::get_language( $lang );
		$lang_label = $language ? $language['native_name'] : $lang;

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: language name */
					__( 'ترجمه %s تولید و در دیتابیس ذخیره شد.', 'polymart-ai' ),
					$lang_label
				),
				'fields'  => $meta_fields,
				'lang'    => $lang,
			)
		);
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
		);

		if ( in_array( $error->get_error_code(), $client_errors, true ) ) {
			return 'polymart_ai_forbidden' === $error->get_error_code() ? 403 : 400;
		}

		return 500;
	}
}
