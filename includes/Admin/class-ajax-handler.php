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

		$meta_fields = Post_Translator::map_ai_response_to_meta_fields( $result['translations'], $lang );

		$language = Language_Registry::get_language( $lang );
		$lang_label = $language ? $language['native_name'] : $lang;

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: language name */
					__( 'ترجمه %s با موفقیت تولید شد.', 'polymart-ai' ),
					$lang_label
				),
				'fields'  => $meta_fields,
				'lang'    => $lang,
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
