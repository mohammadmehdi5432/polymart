<?php
/**
 * Guards translation persistence from async invalidation during saves.
 *
 * @package PolymartAI\Translation\Post_Translator
 */

namespace PolymartAI\Translation\Post_Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Persistence_Guard
 */
final class Persistence_Guard {

	/**
	 * Nonce action for saving meta box fields.
	 */
	const META_BOX_NONCE_ACTION = 'polymart_ai_save_translations';

	/**
	 * Nonce field name for the meta box form.
	 */
	const META_BOX_NONCE_NAME = 'polymart_ai_meta_box_nonce';

	/**
	 * While true, async invalidation hooks must not delete stored translations.
	 *
	 * @var bool
	 */
	private static $is_persisting_translations = false;

	/**
	 * Whether translation persistence is in progress (manual save or AI write).
	 *
	 * @return bool
	 */
	public static function is_persisting_translations() {
		return self::$is_persisting_translations;
	}

	/**
	 * Whether the current admin POST is saving the PolyMart translation meta box.
	 *
	 * @return bool
	 */
	public static function is_admin_translation_save_request() {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( ! isset( $_POST[ self::META_BOX_NONCE_NAME ] ) ) {
			return false;
		}

		return wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST[ self::META_BOX_NONCE_NAME ] ) ),
			self::META_BOX_NONCE_ACTION
		);
	}

	/**
	 * Whether async invalidation/clear hooks should be skipped for this request.
	 *
	 * @return bool
	 */
	public static function should_skip_translation_invalidation() {
		return self::$is_persisting_translations || self::is_admin_translation_save_request();
	}

	/**
	 * Begin guarded translation persistence (blocks async invalidation).
	 *
	 * @return void
	 */
	public static function begin_persisting_translations() {
		\PolymartAI\Activity_Logger::bootstrap_job_worker_context();
		self::$is_persisting_translations = true;
	}

	/**
	 * End guarded translation persistence.
	 *
	 * @return void
	 */
	public static function end_persisting_translations() {
		self::$is_persisting_translations = false;
	}
}
