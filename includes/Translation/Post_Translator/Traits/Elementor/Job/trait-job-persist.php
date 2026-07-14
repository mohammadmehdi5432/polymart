<?php
/**
 * Post_Translator Elementor Job_Persist (auto-split).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\Elementor\Job;

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\AI\AI_Client;
use PolymartAI\Translation\AI\Persian_Detector;
use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Post_Translator\Persistence_Guard;
use PolymartAI\Translation\Post_Translator\Text_Normalizer;
use PolymartAI\Translation\Post_Translator\Translation_Lock;

defined( 'ABSPATH' ) || exit;

trait Trait_Job_Persist {

	private static function persist_elementor_job_progress( $post_id, $lang, array $source_data, array $map, $done_count, $total_chunks, $complete = false ) {
		\PolymartAI\Activity_Logger::bootstrap_job_worker_context();
		\PolymartAI\Activity_Logger::touch_job_worker_heartbeat();

		if ( ! self::ensure_translation_lock_for_persist( $post_id, $lang ) ) {
			\PolymartAI\Activity_Logger::log(
				'warning',
				sprintf(
					/* translators: 1: post ID, 2: done count, 3: total chunks */
					__( 'Elementor — #%1$d: ذخیره بخش %2$d/%3$d رد شد — قفل ترجمه در اختیار کارگر دیگر است.', 'polymart-ai' ),
					absint( $post_id ),
					absint( $done_count ),
					absint( $total_chunks )
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);

			return new \WP_Error(
				'polymart_ai_translation_in_progress',
				__( 'این مورد در حال ترجمه است. لطفاً چند لحظه صبر کنید.', 'polymart-ai' )
			);
		}

		$partial_state = self::get_job_partial_state( $post_id, $lang );
		$source_payload = self::collect_elementor_translation_payload( $source_data );
		$map            = self::sanitize_elementor_translation_map(
			self::merge_elementor_path_map( $post_id, $lang, $source_data, $map ),
			$source_payload
		);
		$map            = self::prepare_elementor_map_for_persist( $map, $source_payload );
		$chunk_progress = self::get_elementor_chunk_progress(
			$post_id,
			$lang,
			array(
				'elementor_map'          => $map,
				'elementor_skipped'      => is_array( $partial_state['elementor_skipped'] ?? null ) ? $partial_state['elementor_skipped'] : array(),
				'elementor_chunks_total' => max( 1, absint( $total_chunks ) ),
			)
		);
		$total_chunks = max( absint( $total_chunks ), (int) $chunk_progress['total'], 1 );
		$done_count   = min(
			$total_chunks,
			max(
				(int) $chunk_progress['done'],
				absint( $done_count ),
				self::read_elementor_slice_cursor( $post_id, $lang )
			)
		);

		$tree = self::apply_elementor_translation_payload( $source_data, $map );
		\PolymartAI\Activity_Logger::touch_job_worker_heartbeat();
		$json = wp_json_encode( $tree );

		if ( false === $json || '' === $json ) {
			\PolymartAI\Activity_Logger::log(
				'error',
				sprintf(
					/* translators: 1: post ID */
					__( 'Elementor — #%1$d: wp_json_encode برای ذخیره ترجمه ناموفق بود.', 'polymart-ai' ),
					absint( $post_id )
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);

			return new \WP_Error(
				'polymart_ai_elementor_save_failed',
				__( 'ذخیره ترجمه Elementor ناموفق بود.', 'polymart-ai' )
			);
		}

		update_post_meta( $post_id, self::get_elementor_meta_key( $lang ), $json );
		\PolymartAI\Activity_Logger::touch_job_worker_heartbeat();

		if ( $complete ) {
			self::save_elementor_source_hash( $post_id, $lang );
			delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
			delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );
			self::clear_elementor_primary_batches_lock( $post_id, $lang );
			self::clear_elementor_slice_cursor( $post_id, $lang );
			self::flush_translation_status_cache( $post_id );

			return true;
		}

		if ( '' === trim( (string) get_post_meta( $post_id, self::get_elementor_source_hash_meta_key( $lang ), true ) ) ) {
			self::save_elementor_source_hash( $post_id, $lang );
		}

		self::write_elementor_slice_cursor( $post_id, $lang, $done_count, $total_chunks );

		$progress_message = sprintf(
			/* translators: 1: completed batches, 2: total batches */
			__( 'ترجمه Elementor در حال انجام (%1$d/%2$d)', 'polymart-ai' ),
			min( $total_chunks, $done_count ),
			$total_chunks
		);

		update_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ), $progress_message );
		delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
		self::flush_translation_status_cache( $post_id );

		return true;
	}

	public static function persist_elementor_translation( $post_id, $lang ) {
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

		if ( self::is_elementor_translation_current( $post_id, $lang ) ) {
			$previous_error = (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true );

			if ( '' === trim( $previous_error ) ) {
				return true;
			}

			delete_post_meta( $post_id, self::get_elementor_meta_key( $lang ) );
			delete_post_meta( $post_id, self::get_elementor_source_hash_meta_key( $lang ) );
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return new \WP_Error(
				'polymart_ai_elementor_source_missing',
				__( 'داده Elementor برای این صفحه یافت نشد.', 'polymart-ai' )
			);
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'polymart_ai_elementor_source_invalid',
				__( 'JSON Elementor نامعتبر است.', 'polymart-ai' )
			);
		}

		$payload = self::collect_elementor_translation_payload( $data );

		if ( empty( $payload ) ) {
			return new \WP_Error(
				'polymart_ai_elementor_empty_payload',
				__( 'متن فارسی قابل ترجمه در Elementor پیدا نشد.', 'polymart-ai' )
			);
		}

		$settings = self::get_translation_settings();

		if ( empty( $settings['api_key'] ) || empty( $settings['api_endpoint'] ) ) {
			return new \WP_Error(
				'polymart_ai_missing_credentials',
				__( 'اعتبارنامه API آروان‌کلاد پیکربندی نشده است.', 'polymart-ai' )
			);
		}

		$api_key      = (string) $settings['api_key'];
		$api_endpoint = rtrim( (string) $settings['api_endpoint'], '/' );
		$ai_model     = AI_Client::resolve_model(
			(string) ( $settings['ai_model'] ?? '' ),
			$api_endpoint
		);

		$translated_map = self::translate_elementor_payload_map(
			$payload,
			$lang,
			$api_key,
			$api_endpoint,
			$ai_model
		);

		if ( is_wp_error( $translated_map ) ) {
			return $translated_map;
		}

		$missing = array();

		foreach ( array_keys( $payload ) as $path ) {
			if ( empty( $translated_map[ $path ] ) || '' === trim( (string) $translated_map[ $path ] ) ) {
				$missing[] = $path;
			}
		}

		$tree = self::apply_elementor_translation_payload( $data, $translated_map );
		$json = wp_json_encode( $tree );

		if ( false === $json || '' === $json ) {
			return new \WP_Error(
				'polymart_ai_elementor_save_failed',
				__( 'ذخیره ترجمه Elementor ناموفق بود.', 'polymart-ai' )
			);
		}

		update_post_meta( $post_id, self::get_elementor_meta_key( $lang ), $json );
		self::save_elementor_source_hash( $post_id, $lang );

		if ( ! empty( $missing ) ) {
			update_post_meta(
				$post_id,
				'_polymart_ai_elementor_error_' . $lang,
				sprintf(
					/* translators: 1: translated count, 2: total count */
					__( 'ترجمه Elementor بخشی ذخیره شد (%1$d از %2$d فیلد).', 'polymart-ai' ),
					count( $payload ) - count( $missing ),
					count( $payload )
				)
			);

			return true;
		}

		delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );

		return true;
	}

	private static function translate_elementor_payload_map( array $payload, $lang, $api_key, $api_endpoint, $ai_model ) {
		list( $aliased_payload, $alias_to_path ) = self::alias_elementor_payload_keys( $payload );
		$translated_map                          = array();
		$last_error                              = null;
		// Cap single-field fallbacks so one bad page cannot hang the auto-translate job.
		$single_fallback_budget = 8;

		foreach ( self::chunk_elementor_payload_for_ai( $aliased_payload ) as $chunk ) {
			$chunk_result = AI_Client::translate_fields(
				$chunk,
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang
			);

			if ( is_wp_error( $chunk_result ) ) {
				$last_error = $chunk_result;

				foreach ( $chunk as $alias => $text ) {
					if ( $single_fallback_budget <= 0 ) {
						break 2;
					}

					--$single_fallback_budget;

					$single = AI_Client::translate_fields(
						array( $alias => $text ),
						$api_key,
						$api_endpoint,
						$ai_model,
						$lang
					);

					if ( is_wp_error( $single ) ) {
						$last_error = $single;
						continue;
					}

					$translated_map = array_merge( $translated_map, $single );
				}
				continue;
			}

			$translated_map = array_merge( $translated_map, $chunk_result );
		}

		$translated_map = self::unmap_elementor_aliases( $translated_map, $alias_to_path );
		$translated_map = self::collapse_payload_parts( $translated_map );

		foreach ( $payload as $path => $text ) {
			if ( isset( $translated_map[ $path ] ) && '' !== trim( (string) $translated_map[ $path ] ) ) {
				continue;
			}

			if ( $single_fallback_budget <= 0 ) {
				break;
			}

			--$single_fallback_budget;

			$alias  = array_search( $path, $alias_to_path, true );
			$single = AI_Client::translate_fields(
				array(
					false !== $alias ? (string) $alias : $path => $text,
				),
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang
			);

			if ( is_wp_error( $single ) ) {
				$last_error = $single;
				continue;
			}

			if ( false !== $alias && isset( $single[ $alias ] ) ) {
				$translated_map[ $path ] = $single[ $alias ];
			} elseif ( isset( $single[ $path ] ) ) {
				$translated_map[ $path ] = $single[ $path ];
			}
		}

		if ( empty( $translated_map ) ) {
			if ( $last_error instanceof \WP_Error ) {
				return $last_error;
			}

			return new \WP_Error(
				'polymart_ai_elementor_no_translations',
				__( 'ترجمه Elementor ناموفق بود — هیچ فیلدی از AI برنگشت.', 'polymart-ai' )
			);
		}

		return $translated_map;
	}

}
