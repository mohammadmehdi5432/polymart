<?php
/**
 * Post_Translator Job_Slice (auto-split).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\JobSlice;

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\AI\AI_Client;
use PolymartAI\Translation\AI\Persian_Detector;
use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Post_Translator\Persistence_Guard;
use PolymartAI\Translation\Post_Translator\Text_Normalizer;
use PolymartAI\Translation\Post_Translator\Translation_Lock;


defined( 'ABSPATH' ) || exit;

trait Trait_Job_Slice {

	public static function collect_persian_fields_for_job_phase( \WP_Post $post, $lang, $phase ) {
		$phase = sanitize_key( (string) $phase );

		switch ( $phase ) {
			case 'commerce':
				$fields = self::collect_commerce_persian_fields( $post, $lang );
				break;
			case 'variations':
				$fields = self::collect_variation_title_fields( $post, $lang );
				break;
			case 'core':
			default:
				$fields = self::collect_core_persian_fields( $post, $lang );
				break;
		}

		/**
		 * Filter Persian source fields before AI translation.
		 *
		 * @param array<string, string> $fields Source field values.
		 * @param \WP_Post              $post   Post object.
		 * @param string                $phase  Job slice phase (core|commerce|variations).
		 */
		return apply_filters( 'polymart_ai_translatable_fields', $fields, $post, $phase );
	}

	public static function collect_persian_fields( \WP_Post $post, $lang = '' ) {
		$fields = array_merge(
			self::collect_core_persian_fields( $post, $lang ),
			self::collect_commerce_persian_fields( $post, $lang ),
			self::collect_variation_title_fields( $post, $lang )
		);

		/**
		 * Filter Persian source fields before AI translation.
		 *
		 * @param array<string, string> $fields Source field values.
		 * @param \WP_Post              $post   Post object.
		 */
		return apply_filters( 'polymart_ai_translatable_fields', $fields, $post );
	}

	public static function chunk_payload_for_ai( array $payload ) {
		return self::chunk_payload_with_limits(
			$payload,
			self::AI_FIELD_CHUNK_SIZE,
			self::AI_MAX_CHUNK_CHARS
		);
	}

	private static function chunk_payload_for_job_phase( array $payload, $phase, $post_id = 0 ) {
		$phase   = sanitize_key( (string) $phase );
		$post_id = absint( $post_id );

		switch ( $phase ) {
			case 'variations':
				$max_fields = self::VARIATION_AI_FIELD_CHUNK_SIZE;
				$max_chars  = self::VARIATION_AI_MAX_CHUNK_CHARS;

				if ( $post_id > 0 && function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( $post_id );

					if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
						$variation_count = count( $product->get_children() );

						if ( $variation_count > 80 ) {
							$max_fields = 3;
							$max_chars  = 1800;
						} elseif ( $variation_count > 30 ) {
							$max_fields = 2;
							$max_chars  = 1500;
						}
					}
				}

				return self::chunk_payload_with_limits(
					$payload,
					$max_fields,
					$max_chars
				);
			case 'commerce':
				// Keep related WooCommerce fields in one request when possible.
				// Fewer sequential gateway calls materially shortens each product.
				$max_fields = self::COMMERCE_AI_FIELD_CHUNK_SIZE;
				$max_chars  = self::COMMERCE_AI_MAX_CHUNK_CHARS;

				if ( $post_id > 0 && function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( $post_id );

					if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
						$max_fields = 1;
						$max_chars  = 1500;
					}
				}

				return self::chunk_payload_with_limits(
					$payload,
					$max_fields,
					$max_chars
				);
			case 'core':
			default:
				return self::chunk_payload_with_limits(
					$payload,
					self::JOB_CORE_FIELD_CHUNK_SIZE,
					self::AI_MAX_CHUNK_CHARS
				);
		}
	}

	public static function get_job_ai_request_options( $post_id = 0, $phase = '' ) {
		unset( $post_id, $phase );

		$options = array(
			'min_timeout' => self::JOB_REQUEST_MIN_TIMEOUT,
			'max_timeout' => self::JOB_REQUEST_MAX_TIMEOUT,
		);

		/**
		 * Filter HTTP timeout bounds for auto-translate job AI requests.
		 *
		 * @param array<string, int> $options min_timeout / max_timeout in seconds.
		 * @param int                $post_id Post ID.
		 * @param string             $phase   Job phase key.
		 */
		return apply_filters( 'polymart_ai_job_ai_request_options', $options, absint( $post_id ), sanitize_key( (string) $phase ) );
	}

	private static function chunk_payload_with_limits( array $payload, $max_fields, $max_chars, $expand = true ) {
		if ( $expand ) {
			$payload = self::expand_payload_for_ai( $payload );
		}

		$chunks        = array();
		$current       = array();
		$current_chars = 0;
		$max_fields    = max( 1, absint( $max_fields ) );
		$max_chars     = max( 500, absint( $max_chars ) );

		foreach ( $payload as $key => $value ) {
			$value = (string) $value;
			$size  = self::utf8_strlen( (string) $key ) + self::utf8_strlen( $value );

			if (
				! empty( $current )
				&& (
					$current_chars + $size > $max_chars
					|| count( $current ) >= $max_fields
				)
			) {
				$chunks[]      = $current;
				$current       = array();
				$current_chars = 0;
			}

			$current[ $key ] = $value;
			$current_chars  += $size;
		}

		if ( ! empty( $current ) ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	public static function expand_payload_for_ai( array $payload ) {
		$expanded = array();

		foreach ( $payload as $key => $value ) {
			$value = (string) $value;

			if ( self::utf8_strlen( $value ) <= self::AI_MAX_SINGLE_FIELD_CHARS ) {
				$expanded[ $key ] = $value;
				continue;
			}

			$offset = 0;
			$part   = 1;
			$length = self::utf8_strlen( $value );

			while ( $offset < $length ) {
				$slice = self::utf8_substr( $value, $offset, self::AI_MAX_SINGLE_FIELD_CHARS );
				$expanded[ $key . '::__part' . $part ] = $slice;
				$offset += self::utf8_strlen( $slice );
				++$part;
			}
		}

		return $expanded;
	}

	public static function collapse_payload_parts( array $translations ) {
		$merged = array();
		$parts  = array();

		foreach ( $translations as $key => $value ) {
			$key = (string) $key;

			if ( preg_match( '/^(.+)::__(part|seg)(\d+)$/', $key, $matches ) ) {
				$base  = (string) $matches[1];
				$index = absint( $matches[3] );

				if ( $index <= 0 ) {
					$index = 1;
				}

				if ( ! isset( $parts[ $base ] ) ) {
					$parts[ $base ] = array();
				}

				$parts[ $base ][ $index ] = (string) $value;
				continue;
			}

			$merged[ $key ] = (string) $value;
		}

		foreach ( $parts as $base => $chunk_parts ) {
			ksort( $chunk_parts );
			$merged[ $base ] = implode( '', array_values( $chunk_parts ) );
		}

		return $merged;
	}

	public static function get_job_step_max_field_chunks() {
		/**
		 * Filter how many AI field batches one auto-translate step may run.
		 *
		 * @param int $chunks Default 2.
		 */
		return max( 1, (int) apply_filters( 'polymart_ai_job_step_max_field_chunks', 1 ) );
	}

	public static function get_job_step_max_field_chunks_for_phase( $phase, $post_id = 0 ) {
		$phase   = sanitize_key( (string) $phase );
		$post_id = absint( $post_id );
		$chunks  = self::get_job_step_max_field_chunks();

		if ( 'elementor' === $phase ) {
			$chunks = 1;
		} elseif ( 'commerce' === $phase ) {
			$chunks = 1;
		} elseif ( 'variations' === $phase ) {
			$chunks = 1;

			if ( $post_id > 0 && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $post_id );

				if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
					$variation_count = count( $product->get_children() );

					if ( $variation_count > 80 ) {
						$chunks = 2;
					}
				}
			}
		}

		/**
		 * Filter per-phase chunk budget for auto-translate job steps.
		 *
		 * @param int    $chunks  Batch count for this step.
		 * @param string $phase   Phase key.
		 * @param int    $post_id Post ID.
		 */
		return max( 1, (int) apply_filters( 'polymart_ai_job_step_max_field_chunks_for_phase', $chunks, $phase, $post_id ) );
	}

	public static function get_job_step_max_elementor_chunks() {
		$default = \PolymartAI\Activity_Logger::is_bulk_job_running() ? 1 : 4;

		/**
		 * Filter how many Elementor AI batches one auto-translate step may run.
		 *
		 * @param int $chunks Default 1 for bulk jobs, 4 for manual metabox runs.
		 */
		return max( 1, (int) apply_filters( 'polymart_ai_job_step_max_elementor_chunks', $default ) );
	}

	public static function get_job_step_limits_for_post( $post_id, $phase ) {
		$post_id          = absint( $post_id );
		$phase            = sanitize_key( (string) $phase );
		$max_consecutive  = 8;
		$max_per_run      = 24;

		if ( 'elementor' === $phase ) {
			$max_consecutive = 40;
			$max_per_run     = 80;
		} elseif ( 'commerce' === $phase ) {
			$max_consecutive = 20;
			$max_per_run     = 50;
		} elseif ( 'variations' === $phase && $post_id > 0 && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );

			if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
				$variation_count = count( $product->get_children() );
				$fields_per_var  = 2;
				$chunk_fields    = self::VARIATION_AI_FIELD_CHUNK_SIZE;
				$chunks_per_step = max( 1, self::get_job_step_max_field_chunks_for_phase( 'variations', $post_id ) );

				if ( $variation_count > 80 ) {
					$chunk_fields = 5;
				} elseif ( $variation_count > 30 ) {
					$chunk_fields = 4;
				}

				$fields_per_step = max( 1, $chunk_fields * $chunks_per_step );
				$estimated_steps = max( 4, (int) ceil( ( $variation_count * $fields_per_var ) / $fields_per_step ) );
				$max_consecutive = min( 80, max( 20, $estimated_steps + 5 ) );
				$max_per_run     = min( 120, $max_consecutive + 20 );
			}
		}

		/**
		 * Filter consecutive step limits for one post during an auto-translate job.
		 *
		 * @param array{max_consecutive: int, max_per_run: int} $limits  Computed limits.
		 * @param int                                           $post_id Post ID.
		 * @param string                                        $phase   Phase key.
		 */
		$limits = apply_filters(
			'polymart_ai_job_step_limits',
			array(
				'max_consecutive' => $max_consecutive,
				'max_per_run'     => $max_per_run,
			),
			$post_id,
			$phase
		);

		return array(
			'max_consecutive' => max( 1, (int) ( $limits['max_consecutive'] ?? $max_consecutive ) ),
			'max_per_run'     => max( 1, (int) ( $limits['max_per_run'] ?? $max_per_run ) ),
		);
	}

	public static function build_initial_job_partial_state( $post_id, $lang, $post = null ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$base    = array(
			'field_chunk_index'      => 0,
			'elementor_map'          => array(),
			'elementor_chunk_index'  => 0,
			'elementor_failures'     => array(),
			'elementor_chunks_total' => 0,
		);

		if ( $post_id <= 0 || '' === $lang ) {
			return array_merge( $base, array( 'phase' => 'core' ) );
		}

		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post instanceof \WP_Post ) {
			return array_merge( $base, array( 'phase' => 'core' ) );
		}

		foreach ( self::get_job_field_phases() as $phase ) {
			$fields = self::collect_persian_fields_for_job_phase( $post, $lang, $phase );

			if ( ! empty( $fields ) ) {
				return array_merge( $base, array( 'phase' => $phase ) );
			}
		}

		if (
			self::post_needs_elementor_job_work( $post_id, $lang )
			|| self::elementor_job_has_remaining_payload( $post_id, $lang )
		) {
			return self::hydrate_elementor_job_partial_state(
				$post_id,
				$lang,
				array_merge( $base, array( 'phase' => 'elementor' ) )
			);
		}

		return array_merge( $base, array( 'phase' => 'complete' ) );
	}

	public static function is_recoverable_job_slice_error( $error ) {
		if ( ! $error instanceof \WP_Error ) {
			return false;
		}

		$recoverable_codes = array(
			'polymart_ai_timeout',
			'polymart_ai_http_error',
			'polymart_ai_api_error',
			'polymart_ai_rate_limited',
			'polymart_ai_invalid_json',
			'polymart_ai_invalid_response',
			'polymart_ai_missing_choices',
			'polymart_ai_empty_response',
			'polymart_ai_no_translations',
			'polymart_ai_chunk_empty',
			'polymart_ai_job_slice_failed',
		);

		if ( in_array( $error->get_error_code(), $recoverable_codes, true ) ) {
			return true;
		}

		$message = strtolower( $error->get_error_message() );

		return false !== strpos( $message, 'timeout' )
			|| false !== strpos( $message, 'timed out' )
			|| false !== strpos( $message, 'منقضی' )
			|| false !== strpos( $message, 'gateway' )
			|| false !== strpos( $message, 'rate limit' )
			|| false !== strpos( $message, '429' )
			|| false !== strpos( $message, '404' )
			|| false !== strpos( $message, 'blocked' )
			|| false !== strpos( $message, 'arvancloud' )
			|| false !== strpos( $message, 'مسدود' )
			|| false !== strpos( $message, 'ربات' );
	}

	public static function job_partial_state_has_progress( $post_id, $lang, array $state = array() ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( empty( $state ) ) {
			$state = self::get_job_partial_state( $post_id, $lang );
		}

		if ( empty( $state ) || ! is_array( $state ) ) {
			return (bool) get_post_meta( $post_id, self::get_elementor_meta_key( $lang ), true );
		}

		$phase = sanitize_key( (string) ( $state['phase'] ?? '' ) );

		if ( 'elementor' === $phase ) {
			if ( absint( $state['elementor_chunk_index'] ?? 0 ) > 0 ) {
				return true;
			}

			$persist = is_array( $state['elementor_persist_map'] ?? null ) ? $state['elementor_persist_map'] : array();

			if ( ! empty( $persist ) ) {
				return true;
			}

			if ( ! empty( $state['elementor_gap_fill'] ) ) {
				return true;
			}

			return (bool) get_post_meta( $post_id, self::get_elementor_meta_key( $lang ), true );
		}

		if ( in_array( $phase, array( 'core', 'commerce', 'variations', 'fields' ), true ) ) {
			return absint( $state['field_chunk_index'] ?? 0 ) > 0;
		}

		return false;
	}

	public static function make_recoverable_partial_slice_response( $phase, $progress, $message ) {
		return array(
			'done'           => false,
			'phase'          => sanitize_key( (string) $phase ),
			'phase_progress' => (string) $progress,
			'message'        => (string) $message,
			'recoverable'    => true,
		);
	}

	private static function get_job_partial_meta_key( $lang ) {
		return self::JOB_PARTIAL_META_PREFIX . sanitize_key( (string) $lang );
	}

	public static function get_job_partial_state( $post_id, $lang ) {
		$stored = get_post_meta( absint( $post_id ), self::get_job_partial_meta_key( $lang ), true );

		return is_array( $stored ) ? $stored : array();
	}

	public static function save_job_partial_state( $post_id, $lang, array $state ) {
		$persist = $state;
		$post_id = absint( $post_id );

		// Translations live in `_elementor_data_{lang}` — keep partial meta lightweight.
		if ( 'elementor' === sanitize_key( (string) ( $persist['phase'] ?? '' ) ) ) {
			$map_for_persist = is_array( $persist['elementor_map'] ?? null ) ? $persist['elementor_map'] : array();

			if ( $post_id > 0 && ! empty( $map_for_persist ) ) {
				$raw = get_post_meta( $post_id, '_elementor_data', true );

				if ( is_string( $raw ) && '' !== trim( $raw ) ) {
					$data = json_decode( $raw, true );

					if ( is_array( $data ) ) {
						$source_payload = self::collect_elementor_translation_payload( $data );
						$map_for_persist = self::prune_complete_elementor_segment_map_entries(
							$map_for_persist,
							$source_payload
						);
						$persist_entries = self::extract_elementor_persist_map_entries( $map_for_persist, $source_payload );
						$legacy_seg      = is_array( $persist['elementor_seg_map'] ?? null ) ? $persist['elementor_seg_map'] : array();
						$existing        = is_array( $persist['elementor_persist_map'] ?? null ) ? $persist['elementor_persist_map'] : array();

						$persist['elementor_persist_map'] = array_merge( $existing, $legacy_seg, $persist_entries );
					}
				}
			} elseif ( is_array( $persist['elementor_persist_map'] ?? null ) || is_array( $persist['elementor_seg_map'] ?? null ) ) {
				$persist['elementor_persist_map'] = array_merge(
					is_array( $persist['elementor_persist_map'] ?? null ) ? $persist['elementor_persist_map'] : array(),
					is_array( $persist['elementor_seg_map'] ?? null ) ? $persist['elementor_seg_map'] : array()
				);
			}

			unset( $persist['elementor_map'], $persist['elementor_seg_map'] );
		}

		update_post_meta( $post_id, self::get_job_partial_meta_key( $lang ), $persist );
	}

	public static function clear_job_partial_state( $post_id, $lang, $force = false ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( ! $force && self::elementor_job_partial_state_is_durable( $post_id, $lang ) ) {
			return;
		}

		delete_post_meta( $post_id, self::get_job_partial_meta_key( $lang ) );
	}

	private static function get_job_field_phases() {
		return array( 'core', 'commerce', 'variations' );
	}

	private static function get_next_job_field_phase( $phase ) {
		$phases = self::get_job_field_phases();
		$index  = array_search( sanitize_key( (string) $phase ), $phases, true );

		if ( false === $index || $index >= count( $phases ) - 1 ) {
			return null;
		}

		return $phases[ $index + 1 ];
	}

	private static function get_job_field_phase_label( $phase ) {
		switch ( sanitize_key( (string) $phase ) ) {
			case 'commerce':
				return __( 'ویژگی‌ها و دسته‌ها', 'polymart-ai' );
			case 'variations':
				return __( 'تنوع‌های محصول', 'polymart-ai' );
			case 'core':
			default:
				return __( 'محتوای اصلی', 'polymart-ai' );
		}
	}

	private static function run_job_field_phase_batch( $post_id, \WP_Post $post, $lang, array &$state, $phase, $api_key, $api_endpoint, $ai_model ) {
		$payload     = self::collect_persian_fields_for_job_phase( $post, $lang, $phase );
		$chunks      = empty( $payload ) ? array() : self::chunk_payload_for_job_phase( $payload, $phase, $post->ID );
		$total       = count( $chunks );
		$label       = self::get_job_field_phase_label( $phase );
		$pending     = count( $payload );
		$ai_options  = self::get_job_ai_request_options( $post->ID, $phase );

		if ( 0 === $total ) {
			return array(
				'partial'        => false,
				'phase_complete' => true,
				'phase_progress' => '0/0',
				'message'        => '',
			);
		}

		// Remaining work is re-collected each HTTP step — always start from the first batch.
		if ( in_array( $phase, array( 'variations', 'commerce' ), true ) ) {
			$state['field_chunk_index'] = 0;
		}

		$index  = max( 0, (int) ( $state['field_chunk_index'] ?? 0 ) );

		if ( $index >= $total ) {
			$index                      = 0;
			$state['field_chunk_index'] = 0;
		}

		$budget = self::get_job_step_max_field_chunks_for_phase( $phase, $post->ID );

		while ( $index < $total && $budget > 0 ) {
			$chunk        = $chunks[ $index ];
			$chunk_result = AI_Client::translate_fields(
				$chunk,
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang,
				$ai_options
			);

			if ( is_wp_error( $chunk_result ) ) {
				$fallback_limit = max( 1, count( $chunk ) );

				$recovered = self::translate_job_chunk_with_single_field_fallback(
					$chunk,
					$api_key,
					$api_endpoint,
					$ai_model,
					$lang,
					$fallback_limit,
					$ai_options
				);

				if ( is_wp_error( $recovered ) ) {
					return $recovered;
				}

				$chunk_result = $recovered;
			}

			$batch = self::collapse_payload_parts( $chunk_result );

			if ( ! empty( $batch ) ) {
				$save_result = self::save_ai_translations(
					$post_id,
					$batch,
					$lang,
					array(
						'skip_elementor' => true,
						'keep_lock'      => true,
					)
				);

				if ( is_wp_error( $save_result ) ) {
					return $save_result;
				}

				self::sync_translation_index_meta( $post_id, $lang );
			}

			++$index;
			--$budget;
		}

		$state['field_chunk_index'] = $index;

		if ( $index < $total ) {
			return array(
				'partial'        => true,
				'phase_complete' => false,
				'phase_progress' => $index . '/' . $total,
				'message'        => sprintf(
					/* translators: 1: phase label, 2: completed batches, 3: total batches, 4: pending field count */
					__( '%1$s — بخش %2$d از %3$d (%4$d فیلد باقی‌مانده)', 'polymart-ai' ),
					$label,
					$index,
					$total,
					$pending
				),
			);
		}

		return array(
			'partial'        => false,
			'phase_complete' => true,
			'phase_progress' => $total . '/' . $total,
			'message'        => sprintf(
				/* translators: %s: phase label */
				__( '%s — تکمیل شد', 'polymart-ai' ),
				$label
			),
		);
	}

	private static function translate_job_chunk_with_single_field_fallback( array $chunk, $api_key, $api_endpoint, $ai_model, $lang, $max_fallbacks = 2, array $options = array() ) {
		$merged     = array();
		$last_error = null;
		$attempts   = 0;

		foreach ( $chunk as $key => $text ) {
			if ( $attempts >= max( 1, absint( $max_fallbacks ) ) ) {
				break;
			}

			++$attempts;

			$single = AI_Client::translate_fields(
				array( (string) $key => (string) $text ),
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang,
				$options
			);

			if ( is_wp_error( $single ) ) {
				$last_error = $single;
				continue;
			}

			$merged = array_merge( $merged, $single );
		}

		if ( ! empty( $merged ) ) {
			return $merged;
		}

		if ( $last_error instanceof \WP_Error ) {
			return $last_error;
		}

		return new \WP_Error(
			'polymart_ai_chunk_empty',
			__( 'هیچ فیلدی از این بخش ترجمه نشد.', 'polymart-ai' )
		);
	}

	private static function finalize_job_field_phases( $post_id, $lang, array &$state ) {
		unset( $state['field_translations'] );

		if (
			self::post_needs_elementor_job_work( $post_id, $lang )
			|| self::elementor_job_has_remaining_payload( $post_id, $lang )
		) {
			$state = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
			self::save_job_partial_state( $post_id, $lang, $state );

			return array(
				'done'           => false,
				'phase'          => 'elementor',
				'phase_progress' => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
				'message'        => __( 'فیلدها ذخیره شد — ترجمه Elementor در مراحل بعدی', 'polymart-ai' ),
			);
		}

		self::clear_job_partial_state( $post_id, $lang, true );

		return array(
			'done'           => true,
			'phase'          => 'complete',
			'phase_progress' => '',
			'message'        => __( 'ترجمه این مورد تکمیل شد.', 'polymart-ai' ),
		);
	}

	public static function refresh_translation_lock( $post_id, $lang ) {
		return self::acquire_translation_lock( $post_id, $lang );
	}

	public static function touch_translation_lock( $post_id, $lang ) {
		return Translation_Lock::touch_translation_lock( $post_id, $lang );
	}

	private static function ensure_translation_lock_for_persist( $post_id, $lang, $force_claim = false ) {
		if ( $force_claim ) {
			return self::force_claim_translation_lock( $post_id, $lang );
		}

		if ( self::owns_translation_lock( $post_id, $lang ) ) {
			return self::touch_translation_lock( $post_id, $lang );
		}

		return self::acquire_translation_lock( $post_id, $lang );
	}

	public static function release_stale_translation_lock( $post_id, $lang, $max_silent_sec = null ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$keys  = Translation_Lock::get_translation_lock_keys( $post_id, $lang );
		$claim = absint( get_option( $keys['claim'], 0 ) );
		$lock  = get_transient( $keys['key'] );

		if ( ! $claim && ! $lock ) {
			return false;
		}

		if ( self::owns_translation_lock( $post_id, $lang ) ) {
			return false;
		}

		$max_silent_sec = null === $max_silent_sec
			? max( 75, self::ELEMENTOR_JOB_REQUEST_TIMEOUT + 25 )
			: max( 45, absint( $max_silent_sec ) );

		$lock_stamp = max( $claim, $lock ? absint( $lock ) : 0 );
		$lock_age   = $lock_stamp > 0 ? ( time() - $lock_stamp ) : PHP_INT_MAX;

		// Fresh lock: only steal when the whole worker is silent (not after recovery heartbeats).
		if ( $lock_age < $max_silent_sec && \PolymartAI\Activity_Logger::is_bulk_worker_lively( $max_silent_sec ) ) {
			return false;
		}

		if ( $lock_age < $max_silent_sec ) {
			return false;
		}

		self::release_translation_lock( $post_id, $lang, true );

		return true;
	}

	public static function get_translation_lock_status( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return array(
				'held'          => false,
				'age_sec'       => 0,
				'owned_by_self' => false,
			);
		}

		$keys  = Translation_Lock::get_translation_lock_keys( $post_id, $lang );
		$claim = absint( get_option( $keys['claim'], 0 ) );
		$lock  = get_transient( $keys['key'] );
		$stamp = max( $claim, $lock ? absint( $lock ) : 0 );
		$age   = $stamp > 0 ? max( 0, time() - $stamp ) : 0;

		return array(
			'held'          => $stamp > 0 && $age < self::TRANSLATION_LOCK_TTL,
			'age_sec'       => $age,
			'owned_by_self' => self::owns_translation_lock( $post_id, $lang ),
		);
	}

	public static function prepare_admin_metabox_translation_lock( $post_id, $lang, $force_unlock = false ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		if ( $force_unlock ) {
			self::release_translation_lock( $post_id, $lang, true );

			return true;
		}

		if ( ! \PolymartAI\Activity_Logger::is_bulk_job_running() ) {
			self::release_stale_translation_lock( $post_id, $lang, 90 );
		}

		$status = self::get_translation_lock_status( $post_id, $lang );

		if ( ! $status['held'] || $status['owned_by_self'] ) {
			return true;
		}

		$bulk_on_post = false;

		if ( \PolymartAI\Activity_Logger::is_bulk_job_running() ) {
			$job    = \PolymartAI\Activity_Logger::get_job( false );
			$pinned = absint( $job['partial_post_id'] ?? 0 );

			if ( $pinned <= 0 ) {
				$pinned = absint( $job['current_post_id'] ?? 0 );
			}

			$bulk_on_post = ( $pinned === $post_id );
		}

		if (
			$bulk_on_post
			&& (int) $status['age_sec'] < 30
			&& \PolymartAI\Activity_Logger::is_bulk_worker_lively( 30 )
		) {
			return false;
		}

		self::release_translation_lock( $post_id, $lang, true );

		return true;
	}

	public static function can_translate_post( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return false;
		}

		// Cron + token-authenticated AJAX loopback have no interactive user.
		if ( \PolymartAI\Activity_Logger::is_trusted_job_worker() ) {
			return true;
		}

		if ( ! function_exists( 'wp_get_current_user' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && function_exists( 'current_user_can' ) && current_user_can( REST_API::required_admin_capability() ) ) {
			return true;
		}

		// Site admins running the bulk job may translate any queued post,
		// even when they lack per-post edit caps (custom types / other authors).
		if (
			function_exists( 'current_user_can' )
			&& current_user_can( REST_API::required_admin_capability() )
			&& \PolymartAI\Activity_Logger::is_bulk_job_running()
		) {
			return true;
		}

		if ( ! function_exists( 'current_user_can' ) ) {
			return \PolymartAI\Activity_Logger::is_bulk_job_running();
		}

		return current_user_can( 'edit_post', $post_id );
	}

	public static function process_job_translation_slice( $post_id, $lang, $manage_lock = true ) {
		$post_id     = absint( $post_id );
		$lang        = sanitize_key( (string) $lang );
		$manage_lock = (bool) $manage_lock;

		// Halt (Stop) kills every worker including metabox. Bulk Pause is enforced
		// in AS enqueue / handle_slice / process_background_step — not here —
		// so metabox can still run while the bulk job option is idle or paused.
		if ( \PolymartAI\Activity_Logger\Translation_Scheduler_Coordinator::is_halted() ) {
			return new \WP_Error(
				'polymart_ai_job_aborted',
				__( 'ترجمه توسط کاربر متوقف شد.', 'polymart-ai' )
			);
		}

		if ( \PolymartAI\Activity_Logger::is_job_post_skipped( $post_id ) ) {
			return new \WP_Error(
				'polymart_ai_job_post_skipped',
				__( 'این مورد از صف رد شد.', 'polymart-ai' )
			);
		}

		if ( ! $post_id || ! self::can_translate_post( $post_id ) ) {
			$message = __( 'شما اجازه ترجمه این مورد را ندارید.', 'polymart-ai' );

			if ( \PolymartAI\Activity_Logger::should_bypass_browser_auth_checks() ) {
				$message = sprintf(
					/* translators: 1: current user ID */
					__( 'Worker پس‌زمینه نتوانست دسترسی ترجمه را تأیید کند (User ID: %1$d).', 'polymart-ai' ),
					get_current_user_id()
				);
			}

			return new \WP_Error(
				'polymart_ai_forbidden',
				$message
			);
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return new \WP_Error(
				'polymart_ai_invalid_post',
				__( 'نوع مطلب برای ترجمه نامعتبر است.', 'polymart-ai' )
			);
		}

		$settings = self::get_translation_settings();

		if ( empty( $settings['api_key'] ) || empty( $settings['api_endpoint'] ) ) {
			return new \WP_Error(
				'polymart_ai_missing_credentials',
				__( 'اعتبارنامه API آروان‌کلاد پیکربندی نشده است.', 'polymart-ai' )
			);
		}

		if ( $manage_lock && ! self::acquire_translation_lock( $post_id, $lang ) ) {
			return new \WP_Error(
				'polymart_ai_translation_in_progress',
				__( 'این مورد در حال ترجمه است. لطفاً چند لحظه صبر کنید.', 'polymart-ai' )
			);
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		try {
			return self::run_job_translation_slice_body( $post_id, $lang, $post, $settings );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'polymart_ai_job_slice_failed',
				$e->getMessage()
			);
		} finally {
			if ( $manage_lock ) {
				self::release_translation_lock( $post_id, $lang );
			}
		}
	}

	private static function run_job_translation_slice_body( $post_id, $lang, $post, array $settings ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		$state = self::get_job_partial_state( $post_id, $lang );

		if ( empty( $state ) || empty( $state['phase'] ) ) {
			$state = self::build_initial_job_partial_state( $post_id, $lang, $post );
		}

		if ( 'complete' === (string) ( $state['phase'] ?? '' ) ) {
			self::clear_job_partial_state( $post_id, $lang, true );

			return array(
				'done'           => true,
				'phase'          => 'complete',
				'phase_progress' => '',
				'message'        => __( 'ترجمه این مورد تکمیل شد.', 'polymart-ai' ),
			);
		}

		$api_key      = (string) $settings['api_key'];
		$api_endpoint = rtrim( (string) $settings['api_endpoint'], '/' );
		$ai_model     = AI_Client::resolve_model(
			(string) ( $settings['ai_model'] ?? '' ),
			$api_endpoint
		);

		if ( 'fields' === (string) $state['phase'] ) {
				$payload    = self::collect_persian_fields( $post, $lang );
				$chunks     = empty( $payload ) ? array() : self::chunk_payload_for_job_phase( $payload, 'core', $post_id );
				$total      = count( $chunks );
				$index      = max( 0, (int) ( $state['field_chunk_index'] ?? 0 ) );
				$budget     = 1;
				$ai_options = self::get_job_ai_request_options( $post_id, 'fields' );

				if ( $total <= 0 || $index >= $total ) {
					return self::finalize_job_field_phases( $post_id, $lang, $state );
				}

				while ( $index < $total && $budget > 0 ) {
					$chunk_result = AI_Client::translate_fields(
						$chunks[ $index ],
						$api_key,
						$api_endpoint,
						$ai_model,
						$lang,
						$ai_options
					);

					if ( is_wp_error( $chunk_result ) ) {
						return $chunk_result;
					}

					$batch = self::collapse_payload_parts( $chunk_result );

					if ( ! empty( $batch ) ) {
						$save_result = self::save_ai_translations(
							$post_id,
							$batch,
							$lang,
							array(
								'skip_elementor' => true,
								'keep_lock'      => true,
							)
						);

						if ( is_wp_error( $save_result ) ) {
							return $save_result;
						}
					}

					++$index;
					--$budget;
				}

				$state['field_chunk_index'] = $index;

				if ( $index < $total ) {
					self::save_job_partial_state( $post_id, $lang, $state );

					return array(
						'done'           => false,
						'phase'          => 'fields',
						'phase_progress' => $index . '/' . $total,
						'message'        => sprintf(
							/* translators: 1: completed batches, 2: total batches */
							__( 'فیلدهای متنی — بخش %1$d از %2$d', 'polymart-ai' ),
							$index,
							$total
						),
					);
				}

				return self::finalize_job_field_phases( $post_id, $lang, $state );
			}

			if ( in_array( (string) $state['phase'], self::get_job_field_phases(), true ) ) {
				$phase = (string) $state['phase'];

				$batch_result = self::run_job_field_phase_batch(
					$post_id,
					$post,
					$lang,
					$state,
					$phase,
					$api_key,
					$api_endpoint,
					$ai_model
				);

				if ( is_wp_error( $batch_result ) ) {
					return $batch_result;
				}

				if ( ! empty( $batch_result['partial'] ) ) {
					self::save_job_partial_state( $post_id, $lang, $state );

					return array(
						'done'           => false,
						'phase'          => $phase,
						'phase_progress' => (string) ( $batch_result['phase_progress'] ?? '' ),
						'message'        => (string) ( $batch_result['message'] ?? '' ),
					);
				}

				$next_phase = self::get_next_job_field_phase( $phase );

				if ( null === $next_phase ) {
					return self::finalize_job_field_phases( $post_id, $lang, $state );
				}

				while ( null !== $next_phase ) {
					$next_fields = self::collect_persian_fields_for_job_phase( $post, $lang, $next_phase );

					if ( ! empty( $next_fields ) ) {
						$state['phase']             = $next_phase;
						$state['field_chunk_index'] = 0;
						self::save_job_partial_state( $post_id, $lang, $state );

						return array(
							'done'           => false,
							'phase'          => $next_phase,
							'phase_progress' => '0/…',
							'message'        => sprintf(
								/* translators: %s: next phase label */
								__( 'ادامه در %s…', 'polymart-ai' ),
								self::get_job_field_phase_label( $next_phase )
							),
						);
					}

					$next_phase = self::get_next_job_field_phase( $next_phase );
				}

				return self::finalize_job_field_phases( $post_id, $lang, $state );
			}

			if ( 'elementor' === (string) $state['phase'] ) {
				$state = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
				self::save_job_partial_state( $post_id, $lang, $state );

				$elementor_slice = self::process_elementor_job_slice(
					$post_id,
					$lang,
					$state,
					$api_key,
					$api_endpoint,
					$ai_model
				);

				if (
					is_wp_error( $elementor_slice )
					&& in_array(
						$elementor_slice->get_error_code(),
						array(
							'polymart_ai_elementor_source_missing',
							'polymart_ai_elementor_source_invalid',
						),
						true
					)
				) {
					delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
					delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );
					self::clear_job_partial_state( $post_id, $lang, true );

					return array(
						'done'           => true,
						'phase'          => 'complete',
						'phase_progress' => '',
						'message'        => __( 'داده Elementor یافت نشد — این مورد از فیلدهای متنی/محتوا بسنده شد.', 'polymart-ai' ),
					);
				}

				if ( is_wp_error( $elementor_slice ) ) {
					return $elementor_slice;
				}

				if ( empty( $elementor_slice['done'] ) ) {
					return $elementor_slice;
				}

				self::clear_elementor_slice_cursor( $post_id, $lang );
				delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );
				delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
				self::clear_job_partial_state( $post_id, $lang, true );

				return array(
					'done'           => true,
					'phase'          => 'complete',
					'phase_progress' => '',
					'message'        => __( 'ترجمه Elementor این مورد تکمیل شد.', 'polymart-ai' ),
				);
			}

		self::clear_job_partial_state( $post_id, $lang, true );

		return array(
			'done'           => true,
			'phase'          => 'complete',
			'phase_progress' => '',
			'message'        => __( 'ترجمه این مورد تکمیل شد.', 'polymart-ai' ),
		);
	}

	public static function is_api_transport_timeout_error( $error ) {
		if ( ! $error instanceof \WP_Error ) {
			return false;
		}

		if ( 'polymart_ai_timeout' === $error->get_error_code() ) {
			return true;
		}

		if ( 'http_request_failed' !== $error->get_error_code() ) {
			return false;
		}

		$message = strtolower( $error->get_error_message() );

		return false !== strpos( $message, 'curl error 28' )
			|| false !== strpos( $message, 'timed out' )
			|| false !== strpos( $message, 'timeout' )
			|| false !== strpos( $message, 'operation timed out' );
	}

	public static function storefront_would_show_persian_source( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return false;
		}

		if ( ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return false;
		}

		if ( self::uses_elementor_builder( $post_id ) && ! self::is_commerce_product_post( $post_id, $post ) ) {
			if ( self::has_elementor_persian_content( $post_id ) ) {
				if ( ! self::can_serve_stored_elementor_json_on_storefront( $post_id, $lang ) ) {
					return true;
				}

				if ( self::stored_elementor_translation_has_persian( $post_id, $lang ) ) {
					return true;
				}

				return false;
			}
		}

		foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $source_key ) {
			if ( 'post_content' === $source_key && self::uses_elementor_builder( $post_id ) ) {
				continue;
			}

			$source = self::get_field_source_text( $post, $source_key );

			if ( '' === trim( $source ) || ! Persian_Detector::contains_persian( $source ) ) {
				continue;
			}

			if ( ! self::should_serve_stored_translation( $post_id, $source_key, $lang, $post ) ) {
				return true;
			}
		}

		return false;
	}

	public static function describe_translation_gap( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$notes   = array();

		if ( self::uses_elementor_builder( $post_id ) && self::has_elementor_persian_content( $post_id ) ) {
			if ( ! self::can_serve_stored_elementor_json_on_storefront( $post_id, $lang ) ) {
				$explain = self::explain_elementor_storefront_serve_blockers( $post_id, $lang, false );

				foreach ( (array) ( $explain['messages'] ?? array() ) as $serve_message ) {
					$serve_message = trim( (string) $serve_message );

					if ( '' !== $serve_message ) {
						$notes[] = $serve_message;
					}
				}

				$error = trim( (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true ) );

				if ( '' !== $error && ! self::is_elementor_progress_message( $error ) ) {
					$notes[] = sprintf(
						/* translators: %s: Elementor error message */
						__( 'Elementor: %s', 'polymart-ai' ),
						$error
					);
				} elseif ( empty( $explain['messages'] ) ) {
					$notes[] = __( 'ترجمه Elementor روی URL انگلیسی اعمال نمی‌شود', 'polymart-ai' );
				}
			} elseif ( self::stored_elementor_translation_has_persian( $post_id, $lang ) ) {
				$notes[] = __( 'JSON ترجمه Elementor هنوز متن فارسی دارد', 'polymart-ai' );
			}
		}

		$gaps = self::get_translation_gaps( $post_id, $lang );

		foreach ( $gaps['fields'] ?? array() as $field ) {
			if ( empty( $field['translated'] ) && ! empty( $field['label'] ) ) {
				$notes[] = (string) $field['label'];
			}
		}

		if ( empty( $notes ) && ! empty( $gaps['missing'] ) ) {
			$notes = array_map( 'strval', $gaps['missing'] );
		}

		if ( empty( $notes ) && ! empty( $gaps['notes'] ) ) {
			$notes = array_map( 'strval', $gaps['notes'] );
		}

		if ( empty( $notes ) && self::storefront_would_show_persian_source( $post_id, $lang ) ) {
			$notes[] = __( 'فروشگاه هنوز متن فارسی نشان می‌دهد', 'polymart-ai' );
		}

		$front = absint( get_option( 'page_on_front' ) );

		if ( $front === $post_id && empty( array_filter( $notes, static function ( $note ) {
			return false !== stripos( (string) $note, 'Elementor' );
		} ) ) ) {
			$notes[] = __( 'اگر فقط هدر/فوتر فارسی است، آن‌ها در Header Builder وودمارت هستند — تب «رشته‌های UI» را دوباره اسکن کنید.', 'polymart-ai' );
		}

		if ( empty( $notes ) ) {
			$notes[] = __( 'وضعیت ترجمه نامشخص — بررسی دستی لازم است', 'polymart-ai' );
		}

		return implode( '؛ ', array_unique( array_filter( $notes ) ) );
	}

	public static function post_needs_translation_work( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return false;
		}

		if ( ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return false;
		}

		// Never repair/decode Elementor trees here — Start/probes call this on dozens of posts
		// and the old repair+remaining_payload path timed out the REST start request (503 / «در حال شروع…»).
		if ( self::uses_elementor_builder( $post_id ) ) {
			if (
				self::is_elementor_translation_finalized( $post_id, $lang )
				&& self::is_elementor_translation_current( $post_id, $lang )
			) {
				// Elementor companion sealed — only title/core gaps can still need work below.
			} elseif ( self::elementor_job_has_durable_partial_state( $post_id, $lang ) ) {
				return true;
			} elseif (
				self::should_require_elementor_translation( $post_id )
				&& self::has_elementor_persian_content( $post_id )
				&& ! self::is_elementor_translation_finalized( $post_id, $lang )
			) {
				return true;
			} elseif (
				self::should_require_elementor_translation( $post_id )
				&& self::has_elementor_persian_content( $post_id )
				&& ! self::is_elementor_translation_current( $post_id, $lang )
			) {
				return true;
			}
		}

		if ( ! self::post_has_persian_content( $post ) ) {
			return false;
		}

		self::flush_translation_status_cache( $post_id );

		if ( 'translated' !== self::get_translation_status( $post_id, $lang ) ) {
			return true;
		}

		return ! empty( self::collect_persian_fields( $post, $lang ) );
	}

	public static function post_is_actionable_for_job( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		if ( self::post_needs_translation_work( $post_id, $lang ) ) {
			return true;
		}

		if ( self::elementor_job_has_remaining_payload( $post_id, $lang ) ) {
			return true;
		}

		if ( self::should_run_elementor_job_slice( $post_id, $lang ) ) {
			return true;
		}

		$state = self::get_job_partial_state( $post_id, $lang );

		return 'elementor' === sanitize_key( (string) ( $state['phase'] ?? '' ) )
			&& self::job_partial_state_has_progress( $post_id, $lang, $state );
	}

}
