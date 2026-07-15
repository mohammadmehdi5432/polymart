<?php
/**
 * Post_Translator Elementor_Storage (auto-split).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\Elementor;

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\AI\AI_Client;
use PolymartAI\Translation\AI\Persian_Detector;
use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Post_Translator\Persistence_Guard;
use PolymartAI\Translation\Post_Translator\Text_Normalizer;
use PolymartAI\Translation\Post_Translator\Translation_Lock;


defined( 'ABSPATH' ) || exit;

trait Trait_Storage {

	public static function get_elementor_meta_key( $lang ) {
		return '_elementor_data_' . sanitize_key( (string) $lang );
	}

	public static function get_elementor_source_hash_meta_key( $lang ) {
		return '_polymart_ai_elementor_src_hash_' . sanitize_key( (string) $lang );
	}

	public static function get_elementor_finalized_meta_key( $lang ) {
		return '_polymart_ai_elementor_finalized_' . sanitize_key( (string) $lang );
	}

	public static function get_elementor_accepted_paths_meta_key( $lang ) {
		return '_polymart_ai_elementor_accepted_' . sanitize_key( (string) $lang );
	}

	public static function is_elementor_translation_finalized( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$partial = self::get_job_partial_state( $post_id, $lang );

		if (
			! empty( $partial['elementor_gap_fill'] )
			|| ! empty( $partial['elementor_gap_fill_stubborn_only'] )
		) {
			return false;
		}

		$finalized = get_post_meta( $post_id, self::get_elementor_finalized_meta_key( $lang ), true );

		if ( is_numeric( $finalized ) && absint( $finalized ) > 0 ) {
			if ( self::elementor_job_has_remaining_payload( $post_id, $lang ) ) {
				return false;
			}

			return true;
		}

		// Poll requests must stay fast — never decode Elementor JSON on GET /translation-job.
		if ( \PolymartAI\Activity_Logger::is_job_poll_request() ) {
			return false;
		}

		return self::maybe_backfill_elementor_finalized_meta( $post_id, $lang );
	}

	public static function mark_elementor_translation_finalized( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return;
		}

		update_post_meta( $post_id, self::get_elementor_finalized_meta_key( $lang ), time() );
		self::reset_elementor_runtime_caches();
	}

	private static function clear_elementor_translation_finalized( $post_id, $lang ) {
		delete_post_meta( absint( $post_id ), self::get_elementor_finalized_meta_key( sanitize_key( (string) $lang ) ) );
	}

	public static function get_elementor_accepted_paths( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return array();
		}

		$raw = get_post_meta( $post_id, self::get_elementor_accepted_paths_meta_key( $lang ), true );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'strval', $raw ) ) ) );
	}

	public static function save_elementor_accepted_paths( $post_id, $lang, array $paths ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$paths   = array_values( array_unique( array_filter( array_map( 'strval', $paths ) ) ) );

		if ( $post_id <= 0 || '' === $lang || empty( $paths ) ) {
			return;
		}

		$existing = self::get_elementor_accepted_paths( $post_id, $lang );
		$merged   = array_values( array_unique( array_merge( $existing, $paths ) ) );

		update_post_meta( $post_id, self::get_elementor_accepted_paths_meta_key( $lang ), $merged );
	}

	public static function is_elementor_path_accepted( $post_id, $lang, $path ) {
		$path = (string) $path;

		if ( '' === $path ) {
			return false;
		}

		return in_array( $path, self::get_elementor_accepted_paths( $post_id, $lang ), true );
	}

	private static function maybe_backfill_elementor_finalized_meta( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return false;
		}

		$stored_hash = trim( (string) get_post_meta( $post_id, self::get_elementor_source_hash_meta_key( $lang ), true ) );

		if ( '' === $stored_hash ) {
			return false;
		}

		if ( ! hash_equals( $stored_hash, self::compute_elementor_source_hash( $post_id ) ) ) {
			return false;
		}

		$progress = trim( (string) get_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ), true ) );

		if ( '' !== $progress ) {
			return false;
		}

		$error = trim( (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true ) );

		if ( '' !== $error && ! self::is_elementor_progress_message( $error ) ) {
			return false;
		}

		if ( ! is_string( self::get_stored_elementor_json( $post_id, $lang ) ) ) {
			return false;
		}

		if ( self::stored_elementor_translation_has_persian( $post_id, $lang ) ) {
			return false;
		}

		if ( self::elementor_job_has_durable_partial_state( $post_id, $lang ) ) {
			return false;
		}

		self::mark_elementor_translation_finalized( $post_id, $lang );

		return true;
	}

	private static function elementor_job_has_durable_partial_state( $post_id, $lang ) {
		$state = self::get_job_partial_state( $post_id, $lang );

		if ( ! is_array( $state ) || empty( $state['phase'] ) ) {
			return false;
		}

		return 'elementor' === sanitize_key( (string) $state['phase'] )
			&& self::elementor_job_partial_state_is_durable( $post_id, $lang );
	}

	public static function repair_completed_elementor_job_meta( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return false;
		}

		if ( ! is_string( self::get_stored_elementor_json( $post_id, $lang ) ) ) {
			return false;
		}

		if ( self::stored_elementor_translation_has_persian( $post_id, $lang ) ) {
			return false;
		}

		self::bind_elementor_accepted_paths_context( $post_id, $lang );

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return false;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return false;
		}

		$map       = self::merge_elementor_job_path_map( $post_id, $lang, $data, array() );
		$remaining = self::filter_remaining_elementor_payload(
			self::collect_elementor_translation_payload( $data ),
			$map,
			array()
		);

		if ( ! empty( $remaining ) ) {
			return false;
		}

		self::clear_job_partial_state( $post_id, $lang, true );
		self::clear_elementor_slice_cursor( $post_id, $lang );
		self::clear_elementor_primary_batches_lock( $post_id, $lang );
		delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );
		delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
		self::save_elementor_source_hash( $post_id, $lang );
		self::mark_elementor_translation_finalized( $post_id, $lang );
		self::flush_translation_status_cache( $post_id );

		return true;
	}

	public static function compute_elementor_source_hash( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return '';
		}

		if ( array_key_exists( $post_id, self::$elementor_source_hash_cache ) ) {
			return self::$elementor_source_hash_cache[ $post_id ];
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			self::$elementor_source_hash_cache[ $post_id ] = '';

			return '';
		}

		self::$elementor_source_hash_cache[ $post_id ] = md5( $raw );

		return self::$elementor_source_hash_cache[ $post_id ];
	}

	public static function is_elementor_translation_current( $post_id, $lang, $stored_json = null ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		if ( self::is_commerce_product_post( $post_id ) ) {
			return true;
		}

		$cache_key = $post_id . ':' . $lang;

		if ( array_key_exists( $cache_key, self::$elementor_current_cache ) ) {
			return self::$elementor_current_cache[ $cache_key ];
		}

		if ( null === $stored_json ) {
			$stored_json = get_post_meta( $post_id, self::get_elementor_meta_key( $lang ), true );
		}

		if ( ! is_string( $stored_json ) ) {
			self::$elementor_current_cache[ $cache_key ] = false;

			return false;
		}

		$stored_json = ltrim( $stored_json );

		if ( '' === $stored_json || ( '[' !== $stored_json[0] && '{' !== $stored_json[0] ) ) {
			self::$elementor_current_cache[ $cache_key ] = false;

			return false;
		}

		$current_hash = self::compute_elementor_source_hash( $post_id );

		if ( '' === $current_hash ) {
			self::$elementor_current_cache[ $cache_key ] = false;

			return false;
		}

		$stored_hash = (string) get_post_meta( $post_id, self::get_elementor_source_hash_meta_key( $lang ), true );

		if ( '' === trim( $stored_hash ) ) {
			self::$elementor_current_cache[ $cache_key ] = false;

			return false;
		}

		$is_current = hash_equals( $stored_hash, $current_hash );
		self::$elementor_current_cache[ $cache_key ] = $is_current;

		return $is_current;
	}

	public static function get_stored_elementor_json( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$key     = $post_id . ':' . $lang;

		if ( array_key_exists( $key, self::$stored_elementor_json_cache ) ) {
			return self::$stored_elementor_json_cache[ $key ];
		}

		$stored = get_post_meta( $post_id, self::get_elementor_meta_key( $lang ), true );

		self::$stored_elementor_json_cache[ $key ] = is_string( $stored ) && '' !== ltrim( $stored ) ? $stored : false;

		return self::$stored_elementor_json_cache[ $key ];
	}

	public static function can_serve_stored_elementor_json_on_storefront( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$key     = 'serve:' . $post_id . ':' . $lang;

		if ( array_key_exists( $key, self::$elementor_storefront_serve_cache ) ) {
			return self::$elementor_storefront_serve_cache[ $key ];
		}

		if ( $post_id <= 0 || '' === $lang || self::is_commerce_product_post( $post_id ) ) {
			self::$elementor_storefront_serve_cache[ $key ] = false;

			return false;
		}

		$stored = self::get_stored_elementor_json( $post_id, $lang );

		if ( ! is_string( $stored ) ) {
			self::$elementor_storefront_serve_cache[ $key ] = false;

			return false;
		}

		$stored = ltrim( $stored );

		if ( '' === $stored || ( '[' !== $stored[0] && '{' !== $stored[0] ) || strlen( $stored ) <= 2 ) {
			self::$elementor_storefront_serve_cache[ $key ] = false;

			return false;
		}

		if ( strlen( $stored ) > self::get_max_storefront_elementor_json_bytes() ) {
			self::$elementor_storefront_serve_cache[ $key ] = false;

			return false;
		}

		$elementor_error = (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true );

		if ( '' !== trim( $elementor_error ) && ! self::is_elementor_progress_message( $elementor_error ) ) {
			self::$elementor_storefront_serve_cache[ $key ] = false;

			return false;
		}

		$progress = trim( (string) get_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ), true ) );

		if ( '' !== $progress ) {
			// Finalized jobs sometimes keep a human-readable progress marker (e.g. 24/24).
			if (
				! self::is_elementor_progress_message( $progress )
				|| ! self::is_elementor_translation_finalized( $post_id, $lang )
				|| ! self::is_elementor_translation_current( $post_id, $lang )
				|| self::stored_elementor_translation_has_persian( $post_id, $lang )
			) {
				self::$elementor_storefront_serve_cache[ $key ] = false;

				return false;
			}

			delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );
		}

		$decoded = json_decode( $stored, true );

		if ( ! is_array( $decoded ) ) {
			self::$elementor_storefront_serve_cache[ $key ] = false;

			return false;
		}

		if ( ! self::is_elementor_translation_finalized( $post_id, $lang ) ) {
			self::$elementor_storefront_serve_cache[ $key ] = false;

			return false;
		}

		self::$elementor_storefront_serve_cache[ $key ] = self::is_elementor_translation_current( $post_id, $lang, $stored );

		return self::$elementor_storefront_serve_cache[ $key ];
	}

	public static function get_max_storefront_elementor_json_bytes() {
		/**
		 * Filter the maximum `_elementor_data_{lang}` payload served on the storefront.
		 *
		 * @param int $bytes Default 2 MiB.
		 */
		return max( 65536, (int) apply_filters( 'polymart_ai_max_elementor_json_bytes', self::MAX_STOREFRONT_ELEMENTOR_JSON_BYTES ) );
	}

	public static function save_elementor_source_hash( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$hash    = self::compute_elementor_source_hash( $post_id );

		if ( $post_id <= 0 || '' === $lang || '' === $hash ) {
			return;
		}

		update_post_meta( $post_id, self::get_elementor_source_hash_meta_key( $lang ), $hash );
	}

	public static function invalidate_elementor_translations( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return;
		}

		foreach ( \PolymartAI\Language_Registry::get_translation_target_languages() as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $lang ) {
				continue;
			}

			delete_post_meta( $post_id, self::get_elementor_meta_key( $lang ) );
			delete_post_meta( $post_id, self::get_elementor_source_hash_meta_key( $lang ) );
			delete_post_meta( $post_id, self::get_elementor_finalized_meta_key( $lang ) );
			delete_post_meta( $post_id, self::get_elementor_accepted_paths_meta_key( $lang ) );
			delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
		}

		self::flush_translation_status_cache( $post_id );
	}

	public static function uses_elementor_builder( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return false;
		}

		return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
	}

	public static function should_require_elementor_translation( $post_id, $post = null ) {
		if ( ! self::uses_elementor_builder( $post_id ) ) {
			return false;
		}

		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( absint( $post_id ) );
		}

		if ( self::is_commerce_product_post( $post_id, $post ) ) {
			/**
			 * Filter whether Elementor JSON counts toward product translation completeness.
			 *
			 * @param bool          $required Default false for WooCommerce products.
			 * @param int           $post_id  Post ID.
			 * @param \WP_Post|null $post     Post object.
			 */
			return (bool) apply_filters( 'polymart_ai_require_elementor_for_product', false, $post_id, $post );
		}

		return true;
	}

	public function capture_elementor_data_prev_value( $check, $post_id, $meta_key, $meta_value, $prev_value ) {
		unset( $check, $meta_value );

		if ( '_elementor_data' !== $meta_key ) {
			return $check;
		}

		self::$elementor_data_prev_values[ absint( $post_id ) ] = $prev_value;

		return $check;
	}

	public function maybe_invalidate_elementor_on_source_change( $meta_id, $post_id, $meta_key, $meta_value ) {
		unset( $meta_id );

		if ( '_elementor_data' !== $meta_key ) {
			return;
		}

		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return;
		}

		if ( self::is_persisting_translations() ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$new_raw  = is_string( $meta_value ) ? $meta_value : '';
		$new_hash = '' !== $new_raw ? md5( $new_raw ) : '';

		$had_capture = array_key_exists( $post_id, self::$elementor_data_prev_values );
		$prev_raw    = $had_capture ? self::$elementor_data_prev_values[ $post_id ] : null;

		unset( self::$elementor_data_prev_values[ $post_id ] );

		$prev_hash = is_string( $prev_raw ) && '' !== $prev_raw ? md5( $prev_raw ) : '';

		if ( '' !== $new_hash && '' !== $prev_hash && hash_equals( $prev_hash, $new_hash ) ) {
			return;
		}

		if ( '' === $new_hash && '' === $prev_hash ) {
			return;
		}

		self::$elementor_source_hash_cache[ $post_id ] = $new_hash;
		self::reset_elementor_runtime_caches();

		self::invalidate_elementor_translations( $post_id );
	}

	public static function stored_elementor_translation_has_persian( $post_id, $lang ) {
		return '' !== self::collect_stored_elementor_persian_plain_text( $post_id, $lang );
	}

	public static function collect_stored_elementor_persian_plain_text( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$key     = $post_id . ':' . $lang;

		if ( array_key_exists( $key, self::$stored_elementor_persian_cache ) ) {
			return self::$stored_elementor_persian_cache[ $key ];
		}

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			self::$stored_elementor_persian_cache[ $key ] = '';

			return '';
		}

		$stored = self::get_stored_elementor_json( $post_id, $lang );

		if ( ! is_string( $stored ) || '' === trim( $stored ) ) {
			self::$stored_elementor_persian_cache[ $key ] = '';

			return '';
		}

		$data = json_decode( $stored, true );

		if ( ! is_array( $data ) ) {
			self::$stored_elementor_persian_cache[ $key ] = '';

			return '';
		}

		$payload = self::collect_elementor_translation_payload( $data );
		$accepted = self::get_elementor_accepted_paths( $post_id, $lang );

		if ( ! empty( $accepted ) ) {
			foreach ( $accepted as $path ) {
				unset( $payload[ $path ] );
			}
		}

		self::$stored_elementor_persian_cache[ $key ] = implode(
			"\n\n",
			array_unique( array_filter( array_values( $payload ) ) )
		);

		return self::$stored_elementor_persian_cache[ $key ];
	}

	private static function allows_elementor_audit_decode() {
		if (
			is_admin()
			|| wp_doing_cron()
			|| wp_doing_ajax()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		) {
			return true;
		}

		return \PolymartAI\Activity_Logger::is_trusted_job_worker();
	}

	private static function has_elementor_persian_content( $post_id ) {
		$post_id = absint( $post_id );

		if ( isset( self::$elementor_persian_cache[ $post_id ] ) ) {
			return self::$elementor_persian_cache[ $post_id ];
		}

		self::$elementor_persian_cache[ $post_id ] = '' !== self::collect_elementor_persian_plain_text( $post_id );

		return self::$elementor_persian_cache[ $post_id ];
	}

	public static function elementor_translation_is_storefront_ready( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return true;
		}

		if ( ! self::should_require_elementor_translation( $post_id ) ) {
			return true;
		}

		if ( ! self::has_elementor_persian_content( $post_id ) ) {
			return true;
		}

		return self::can_serve_stored_elementor_json_on_storefront( $post_id, $lang );
	}

	private static function has_stored_elementor_translation( $post_id, $lang ) {
		return self::elementor_translation_is_storefront_ready( $post_id, $lang );
	}

	/**
	 * Drop stale source-hash / finalized markers from partial Elementor runs (pre-finalize bug).
	 *
	 * @return bool True when stale completion meta was cleared.
	 */
	public static function repair_stale_elementor_completion_meta( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return false;
		}

		if ( ! self::should_require_elementor_translation( $post_id ) || ! self::has_elementor_persian_content( $post_id ) ) {
			return false;
		}

		if ( self::elementor_job_has_durable_partial_state( $post_id, $lang ) ) {
			return false;
		}

		// A legitimate finalize clears partial state; do not strip the marker when API work is done.
		if (
			self::is_elementor_translation_finalized( $post_id, $lang )
			&& ! self::elementor_job_has_remaining_payload( $post_id, $lang )
		) {
			return false;
		}

		if ( self::elementor_translation_is_storefront_ready( $post_id, $lang ) ) {
			return false;
		}

		$stored_hash = trim( (string) get_post_meta( $post_id, self::get_elementor_source_hash_meta_key( $lang ), true ) );
		$finalized   = get_post_meta( $post_id, self::get_elementor_finalized_meta_key( $lang ), true );
		$progress    = trim( (string) get_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ), true ) );
		$cleared     = false;

		if ( '' !== $progress ) {
			delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );
			$cleared = true;
		}

		if ( '' === $stored_hash && ! is_numeric( $finalized ) && ! $cleared ) {
			return false;
		}

		if ( '' !== $stored_hash ) {
			delete_post_meta( $post_id, self::get_elementor_source_hash_meta_key( $lang ) );
			$cleared = true;
		}

		if ( is_numeric( $finalized ) ) {
			delete_post_meta( $post_id, self::get_elementor_finalized_meta_key( $lang ) );
			$cleared = true;
		}

		if ( ! $cleared ) {
			return false;
		}

		self::reset_elementor_runtime_caches();
		self::flush_translation_status_cache( $post_id );
		self::repair_completed_elementor_job_meta( $post_id, $lang );

		return true;
	}

}
