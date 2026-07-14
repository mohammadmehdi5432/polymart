<?php
/**
 * Post_Translator Elementor Job_Gap_Fill (auto-split).
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

trait Trait_Job_Gap_Fill {

	private static function ensure_elementor_stubborn_handoff( $post_id, $lang, array &$state, array $source_payload, array $map, $progress_total ) {
		$post_id        = absint( $post_id );
		$lang           = sanitize_key( (string) $lang );
		$progress_total = max( 1, absint( $progress_total ) );
		$skipped        = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$remaining      = self::filter_remaining_elementor_payload( $source_payload, $map, $skipped );

		if ( empty( $remaining ) ) {
			return;
		}

		$state['elementor_gap_fill']               = true;
		$state['elementor_primary_batches_done']   = $progress_total;
		$state['elementor_chunks_total']           = $progress_total;
		$state['elementor_chunk_index']            = $progress_total;
		$state['elementor_slices_completed']       = $progress_total;
		$state['elementor_gap_fill_stubborn_only'] = true;
		$state['elementor_gap_fill_total']         = max(
			1,
			absint( $state['elementor_gap_fill_total'] ?? 0 ),
			absint( $state['elementor_gap_fill_done'] ?? 0 )
		);

		self::write_elementor_slice_cursor( $post_id, $lang, $progress_total, $progress_total );
		self::persist_elementor_primary_batches_done( $post_id, $lang, $progress_total );
	}

	private static function run_elementor_stubborn_handoff_tick(
		$post_id,
		$lang,
		array $source_data,
		array &$state,
		array &$map,
		array $source_payload,
		array $text_mirrors,
		$api_key,
		$api_endpoint,
		$ai_model,
		$progress_total
	) {
		$post_id        = absint( $post_id );
		$lang           = sanitize_key( (string) $lang );
		$progress_total = max( 1, absint( $progress_total ) );

		self::bind_elementor_segment_passthrough_context( $state );
		self::ensure_elementor_stubborn_handoff( $post_id, $lang, $state, $source_payload, $map, $progress_total );

		$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$remaining = self::filter_remaining_elementor_payload( $source_payload, $map, $skipped );

		if ( empty( $remaining ) ) {
			unset( $state['elementor_stubborn_ghost_ticks'] );

			return self::finalize_elementor_stubborn_handoff_success(
				$post_id,
				$lang,
				$source_data,
				$state,
				$map,
				$source_payload,
				$text_mirrors,
				$progress_total
			);
		}

		$ghost_ticks = absint( $state['elementor_stubborn_ghost_ticks'] ?? 0 );

		if ( $ghost_ticks >= self::ELEMENTOR_STUBBORN_GHOST_LOOP_LIMIT ) {
			return self::force_finalize_elementor_job_with_fallback(
				$post_id,
				$lang,
				$source_data,
				$state,
				$map,
				$source_payload,
				$text_mirrors,
				$progress_total
			);
		}

		$stubborn_left = count( $remaining );
		$stubborn_result = array(
			'completed' => 0,
			'touched'   => false,
		);

		if ( $stubborn_left > 0 ) {
			\PolymartAI\Activity_Logger::log(
				'info',
				sprintf(
					/* translators: 1: post ID, 2: remaining field count, 3: ghost tick count */
					__( 'Elementor — #%1$d: stubborn dispatch — %2$d فیلد باقی (تلاش %3$d/%4$d)', 'polymart-ai' ),
					$post_id,
					$stubborn_left,
					$ghost_ticks + 1,
					self::ELEMENTOR_STUBBORN_GHOST_LOOP_LIMIT
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);

			$stubborn_result = self::translate_elementor_gap_fill_stubborn_fields(
				$post_id,
				$lang,
				$source_data,
				$state,
				$map,
				$source_payload,
				$text_mirrors,
				$api_key,
				$api_endpoint,
				$ai_model,
				min( 4, $stubborn_left )
			);
		}

		$map = self::expand_elementor_map_mirrors( $map, $text_mirrors );
		$map = self::merge_elementor_job_path_map( $post_id, $lang, $source_data, $state, $map );
		$map = self::sanitize_elementor_translation_map( $map, $source_payload );
		$map = self::prepare_elementor_map_for_persist( $map, $source_payload );
		$state['elementor_map'] = $map;

		if ( empty( $stubborn_result['touched'] ) && empty( $stubborn_result['completed'] ) ) {
			$state['elementor_stubborn_ghost_ticks'] = $ghost_ticks + 1;
		} else {
			unset( $state['elementor_stubborn_ghost_ticks'] );
		}

		self::sync_elementor_persist_map_state( $state, $map, $source_payload );

		$committed = self::commit_elementor_chunk_progress_to_site(
			$post_id,
			$lang,
			$source_data,
			$state,
			$map,
			$progress_total,
			$progress_total,
			true
		);

		if ( is_wp_error( $committed ) ) {
			return $committed;
		}

		self::touch_translation_lock( $post_id, $lang );
		self::save_job_partial_state( $post_id, $lang, $state );

		$remaining_after = self::filter_remaining_elementor_payload( $source_payload, $map, $skipped );

		if (
			absint( $state['elementor_stubborn_ghost_ticks'] ?? 0 ) >= self::ELEMENTOR_STUBBORN_GHOST_LOOP_LIMIT
			&& ! empty( $remaining_after )
		) {
			return self::force_finalize_elementor_job_with_fallback(
				$post_id,
				$lang,
				$source_data,
				$state,
				$map,
				$source_payload,
				$text_mirrors,
				$progress_total
			);
		}

		if ( empty( $remaining_after ) ) {
			return self::finalize_elementor_stubborn_handoff_success(
				$post_id,
				$lang,
				$source_data,
				$state,
				$map,
				$source_payload,
				$text_mirrors,
				$progress_total
			);
		}

		\PolymartAI\Activity_Logger::schedule_elementor_partial_follow_up( 4 );

		return array(
			'done'           => false,
			'phase'          => 'elementor',
			'phase_progress' => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
			'message'        => self::format_elementor_gap_fill_remaining_message( $post_id, $remaining_after, $map ),
			'recoverable'    => true,
		);
	}

	private static function finalize_elementor_stubborn_handoff_success(
		$post_id,
		$lang,
		array $source_data,
		array &$state,
		array &$map,
		array $source_payload,
		array $text_mirrors,
		$progress_total
	) {
		$post_id        = absint( $post_id );
		$lang           = sanitize_key( (string) $lang );
		$progress_total = max( 1, absint( $progress_total ));
		$skipped        = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();

		self::bind_elementor_segment_passthrough_context( $state );

		$map = self::expand_elementor_map_mirrors( $map, $text_mirrors );
		$map = self::merge_elementor_path_map( $post_id, $lang, $source_data, $map );
		$map = self::sanitize_elementor_translation_map( $map, $source_payload );
		$state['elementor_map'] = $map;

		if ( ! self::elementor_job_map_has_no_remaining( $source_payload, $map, $skipped ) ) {
			return array(
				'done'           => false,
				'phase'          => 'elementor',
				'phase_progress' => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
				'message'        => self::format_elementor_gap_fill_remaining_message(
					$post_id,
					self::filter_remaining_elementor_payload( $source_payload, $map, $skipped ),
					$map
				),
				'recoverable'    => true,
			);
		}

		\PolymartAI\Activity_Logger::log(
			'info',
			sprintf(
				/* translators: 1: post ID */
				__( 'Elementor — #%1$d: تکمیل نهایی — همه فیلدها ترجمه شد و JSON روی سایت ذخیره شد.', 'polymart-ai' ),
				$post_id
			),
			array( 'post_id' => $post_id, 'lang' => $lang )
		);

		$persisted = self::persist_elementor_job_progress( $post_id, $lang, $source_data, $map, $progress_total, $progress_total, true );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		unset(
			$state['elementor_gap_fill'],
			$state['elementor_gap_fill_done'],
			$state['elementor_gap_fill_total'],
			$state['elementor_gap_fill_finished_keys'],
			$state['elementor_gap_fill_scheduled_keys'],
			$state['elementor_gap_fill_stubborn_only'],
			$state['elementor_primary_batches_done'],
			$state['elementor_persist_map'],
			$state['elementor_seg_map'],
			$state['elementor_stubborn_ghost_ticks'],
			$state['elementor_stubborn_long_index'],
			$state['elementor_segment_passthrough'],
			$state['elementor_field_passthrough']
		);

		self::clear_elementor_primary_batches_lock( $post_id, $lang );
		self::clear_elementor_slice_cursor( $post_id, $lang );
		self::clear_job_partial_state( $post_id, $lang, true );
		self::flush_translation_status_cache( $post_id );

		return array(
			'done'           => true,
			'phase'          => 'elementor',
			'phase_progress' => '',
			'message'        => __( 'ترجمه Elementor تکمیل شد.', 'polymart-ai' ),
		);
	}

	private static function force_finalize_elementor_job_with_fallback(
		$post_id,
		$lang,
		array $source_data,
		array &$state,
		array &$map,
		array $source_payload,
		array $text_mirrors,
		$progress_total
	) {
		$post_id        = absint( $post_id );
		$lang           = sanitize_key( (string) $lang );
		$progress_total = max( 1, absint( $progress_total ) );
		$skipped        = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$remaining      = self::filter_remaining_elementor_payload( $source_payload, $map, $skipped );
		$forced         = 0;
		$accepted_paths = array();

		foreach ( $remaining as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
				continue;
			}

			self::apply_elementor_field_source_fallback( $path, $text, $map, $state );
			$accepted_paths[] = $path;
			++$forced;
		}

		if ( ! empty( $accepted_paths ) ) {
			self::save_elementor_accepted_paths( $post_id, $lang, $accepted_paths );
			self::bind_elementor_accepted_paths_context( $post_id, $lang );
		}

		$map = self::expand_elementor_map_mirrors( $map, $text_mirrors );
		$map = self::merge_elementor_path_map( $post_id, $lang, $source_data, $map );
		$map = self::sanitize_elementor_translation_map( $map, $source_payload );
		$state['elementor_map'] = $map;

		self::sync_elementor_persist_map_state( $state, $map, $source_payload );

		\PolymartAI\Activity_Logger::log(
			'warning',
			sprintf(
				/* translators: 1: post ID, 2: forced field count */
				__( 'Elementor — #%1$d: تکمیل اجباری — %2$d فیلد سرسخت با متن اصلی ذخیره شد و ترجمه روی سایت اعمال شد.', 'polymart-ai' ),
				$post_id,
				$forced
			),
			array( 'post_id' => $post_id, 'lang' => $lang, 'forced' => $forced )
		);

		$persisted = self::persist_elementor_job_progress( $post_id, $lang, $source_data, $map, $progress_total, $progress_total, true );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		unset(
			$state['elementor_gap_fill'],
			$state['elementor_gap_fill_done'],
			$state['elementor_gap_fill_total'],
			$state['elementor_gap_fill_finished_keys'],
			$state['elementor_gap_fill_scheduled_keys'],
			$state['elementor_gap_fill_stubborn_only'],
			$state['elementor_primary_batches_done'],
			$state['elementor_persist_map'],
			$state['elementor_seg_map'],
			$state['elementor_stubborn_ghost_ticks'],
			$state['elementor_stubborn_long_index'],
			$state['elementor_segment_passthrough'],
			$state['elementor_field_passthrough']
		);

		self::clear_elementor_primary_batches_lock( $post_id, $lang );
		self::clear_elementor_slice_cursor( $post_id, $lang );
		self::clear_job_partial_state( $post_id, $lang, true );
		self::flush_translation_status_cache( $post_id );

		return array(
			'done'           => true,
			'phase'          => 'elementor',
			'phase_progress' => '',
			'message'        => sprintf(
				/* translators: 1: forced field count */
				__( 'ترجمه Elementor با تکمیل اجباری ذخیره شد (%1$d فیلد با متن اصلی).', 'polymart-ai' ),
				$forced
			),
		);
	}

	private static function get_elementor_job_finalize_blockers( $post_id, $lang, array $source_data, array $map, array $state ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$skipped = is_array( $state['elementor_skipped'] ?? null )
			? array_values( array_filter( array_map( 'strval', $state['elementor_skipped'] ) ) )
			: array();
		$skipped = self::filter_elementor_finalize_blocker_skipped( $skipped, $source_data, $map, $state );

		if ( ! empty( $skipped ) ) {
			return array(
				'code'    => 'skipped_fields',
				'message' => sprintf(
					/* translators: 1: post ID, 2: skipped field count */
					__( 'Elementor — #%1$d: %2$d فیلد پس از خطای API رد شد — ترجمه ناقص است.', 'polymart-ai' ),
					$post_id,
					count( $skipped )
				),
				'skipped' => $skipped,
			);
		}

		$remaining = self::filter_remaining_elementor_payload(
			self::collect_elementor_translation_payload( $source_data ),
			$map,
			array()
		);

		if ( ! empty( $remaining ) ) {
			return array(
				'code'      => 'untranslated_fields',
				'message'   => sprintf(
					/* translators: 1: post ID, 2: remaining field count */
					__( 'Elementor — #%1$d: %2$d فیلد هنوز ترجمه نشده — نهایی‌سازی متوقف شد.', 'polymart-ai' ),
					$post_id,
					count( $remaining )
				),
				'remaining' => count( $remaining ),
			);
		}

		$preview_tree     = self::apply_elementor_translation_payload( $source_data, $map );
		$preview_persian  = self::collect_elementor_translation_payload( $preview_tree );

		if ( ! empty( $preview_persian ) ) {
			return array(
				'code'    => 'stored_persian',
				'message' => sprintf(
					/* translators: 1: post ID, 2: remaining Persian field count */
					__( 'Elementor — #%1$d: %2$d فیلد هنوز فارسی در JSON نهایی دارد — نهایی‌سازی متوقف شد.', 'polymart-ai' ),
					$post_id,
					count( $preview_persian )
				),
			);
		}

		return null;
	}

	private static function respond_elementor_blocked_finalize( $post_id, $lang, array $source_data, array $map, array &$state, $done_count, $total_chunks, array $blocker ) {
		$post_id      = absint( $post_id );
		$lang         = sanitize_key( (string) $lang );
		$done_count   = max( 0, absint( $done_count ) );
		$total_chunks = max( 1, absint( $total_chunks ) );
		$message      = (string) ( $blocker['message'] ?? __( 'ترجمه Elementor ناقص است.', 'polymart-ai' ) );

		\PolymartAI\Activity_Logger::log(
			'warning',
			$message,
			array(
				'post_id' => $post_id,
				'lang'    => $lang,
				'code'    => (string) ( $blocker['code'] ?? 'elementor_incomplete' ),
			)
		);

		update_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, $message );

		$batch_progress = self::compute_elementor_api_batch_progress( $source_data, $map, $state );
		$done_count     = max( $done_count, (int) $batch_progress['done'] );
		$total_chunks   = max( $total_chunks, (int) $batch_progress['total'], 1 );

		$source_payload = self::collect_elementor_translation_payload( $source_data );
		self::sync_elementor_persist_map_state( $state, $map, $source_payload );
		$state['elementor_map'] = $map;

		$persisted = self::persist_elementor_job_progress( $post_id, $lang, $source_data, $map, $done_count, $total_chunks );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		// Keep durable segment progress; only clear base-path skip markers so gap-fill can resume.
		$skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$state['elementor_skipped'] = array_values(
			array_filter(
				array_map( 'strval', $skipped ),
				static function ( $path ) {
					return self::elementor_path_is_segment( $path );
				}
			)
		);
		unset( $state['elementor_failures'] );
		$state['phase'] = 'elementor';
		self::save_job_partial_state( $post_id, $lang, $state );
		self::flush_translation_status_cache( $post_id );

		return array(
			'done'           => false,
			'phase'          => 'elementor',
			'phase_progress' => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
			'message'        => $message,
			'recoverable'    => true,
		);
	}

	private static function finalize_elementor_job_slice( $post_id, $lang, array $source_data, array $map, array &$state, $done_count, $total_chunks, array $remaining = array() ) {
		$post_id      = absint( $post_id );
		$lang         = sanitize_key( (string) $lang );
		$done_count   = max( 0, absint( $done_count ) );
		$total_chunks = max( 1, absint( $total_chunks ) );

		if ( empty( $remaining ) ) {
			$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
			$remaining = self::filter_remaining_elementor_payload(
				self::collect_elementor_translation_payload( $source_data ),
				$map,
				$skipped
			);
		}

		if ( ! empty( $remaining ) ) {
			if ( self::elementor_job_primary_batches_exhausted( $post_id, $lang ) ) {
				$stored_total  = self::read_elementor_slice_cursor_total( $post_id, $lang );
				$stored_cursor = self::read_elementor_slice_cursor( $post_id, $lang );
				$done_count    = max( $done_count, $stored_cursor );
				$total_chunks  = max( $total_chunks, $stored_total, 1 );

				return array(
					'done'           => false,
					'phase'          => 'elementor',
					'phase_progress' => min( $done_count, $total_chunks ) . '/' . $total_chunks,
					'message'        => self::format_elementor_gap_fill_remaining_message( $post_id, $remaining, $map ),
					'recoverable'    => true,
				);
			}

			$batch_progress = self::compute_elementor_api_batch_progress( $source_data, $map, $state );
			$done_count     = (int) $batch_progress['done'];
			$total_chunks   = max( 1, (int) $batch_progress['total'], $total_chunks );
			$persisted      = self::persist_elementor_job_progress( $post_id, $lang, $source_data, $map, $done_count, $total_chunks );

			if ( is_wp_error( $persisted ) ) {
				return $persisted;
			}

			self::save_job_partial_state( $post_id, $lang, $state );

			return array(
				'done'           => false,
				'phase'          => 'elementor',
				'phase_progress' => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
				'message'        => self::format_elementor_job_slice_status_message( $post_id, $lang, $state ),
			);
		}

		$blocker = self::get_elementor_job_finalize_blockers( $post_id, $lang, $source_data, $map, $state );

		if ( null !== $blocker ) {
			return self::respond_elementor_blocked_finalize( $post_id, $lang, $source_data, $map, $state, $done_count, $total_chunks, $blocker );
		}

		\PolymartAI\Activity_Logger::log(
			'info',
			sprintf(
				/* translators: 1: post ID, 2: completed batches, 3: total batches */
				__( 'Elementor — #%1$d: همه %2$d از %3$d بخش ذخیره شد — نهایی‌سازی.', 'polymart-ai' ),
				$post_id,
				$done_count,
				$total_chunks
			),
			array( 'post_id' => $post_id, 'lang' => $lang )
		);

		$persisted = self::persist_elementor_job_progress( $post_id, $lang, $source_data, $map, $done_count, $total_chunks, true );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		self::clear_elementor_slice_cursor( $post_id, $lang );
		self::clear_job_partial_state( $post_id, $lang, true );
		self::flush_translation_status_cache( $post_id );

		return array(
			'done'           => true,
			'phase'          => 'elementor',
			'phase_progress' => '',
			'message'        => __( 'ترجمه Elementor تکمیل شد.', 'polymart-ai' ),
		);
	}

	private static function describe_elementor_field_translation_blocker( $path, $text, array $map ) {
		$path = (string) $path;
		$text = (string) $text;
		$chars = self::utf8_strlen( $text );
		$detail = '';
		$reason = 'unknown';

		$seg_lookup = self::get_elementor_segment_source_lookup( $path, $text );
		if ( ! empty( $seg_lookup ) ) {
			if (
				isset( $map[ $path ] )
				&& self::elementor_map_value_is_valid_translation( $path, $text, (string) $map[ $path ] )
			) {
				return array(
					'path'   => $path,
					'reason' => 'collapsed_base_ok',
					'detail' => 'full field in map but segments listed separately',
					'chars'  => $chars,
				);
			}

			$missing = array();
			$bad     = array();

			foreach ( $seg_lookup as $seg_key => $seg_source ) {
				if ( ! isset( $map[ $seg_key ] ) ) {
					$missing[] = $seg_key;
					continue;
				}

				$translated = trim( (string) $map[ $seg_key ] );
				if ( '' === $translated ) {
					$bad[] = $seg_key . ':empty';
				} elseif ( Persian_Detector::contains_persian( $translated ) ) {
					$bad[] = $seg_key . ':persian';
				} elseif ( $translated === trim( (string) $seg_source ) ) {
					$bad[] = $seg_key . ':unchanged';
				}
			}

			if ( ! empty( $missing ) ) {
				$reason = 'missing_segments';
				$detail = implode( ', ', array_slice( $missing, 0, 4 ) );
			} elseif ( ! empty( $bad ) ) {
				$reason = 'invalid_segments';
				$detail = implode( ', ', array_slice( $bad, 0, 4 ) );
			} elseif (
				isset( $map[ $path ] )
				&& self::elementor_map_value_is_valid_translation( $path, $text, (string) $map[ $path ] )
			) {
				$reason = 'logic_mismatch';
				$detail = 'base_ok_segments_ok_but_still_remaining';
			} else {
				$reason = 'collapsed_base_missing';
				$detail = 'needs_full_field_or_all_segments';
			}

			return compact( 'path', 'reason', 'detail', 'chars' );
		}

		if ( ! isset( $map[ $path ] ) ) {
			$reason = 'not_in_map';
		} else {
			$translated = trim( (string) $map[ $path ] );

			if ( '' === $translated ) {
				$reason = 'empty_translation';
			} elseif ( Persian_Detector::contains_persian( $translated ) ) {
				$reason = 'persian_translation';
			} elseif ( $translated === trim( $text ) ) {
				$reason = 'unchanged_from_source';
			} elseif ( self::utf8_strlen( $text ) > self::AI_MAX_SINGLE_FIELD_CHARS ) {
				$reason = 'long_field_parts_incomplete';
				$parts  = (int) ceil( self::utf8_strlen( $text ) / self::AI_MAX_SINGLE_FIELD_CHARS );
				$have   = 0;

				for ( $part = 1; $part <= $parts; $part++ ) {
					$part_key = $path . '::__part' . $part;
					if (
						isset( $map[ $part_key ] )
						&& self::elementor_map_value_is_valid_translation( $path, $text, (string) $map[ $part_key ] )
					) {
						++$have;
					}
				}

				$detail = sprintf( '%d/%d parts', $have, $parts );
			} else {
				$reason = 'invalid_translation';
			}
		}

		if ( '' === $detail ) {
			$detail = wp_trim_words(
				wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
				10,
				'…'
			);
		}

		return compact( 'path', 'reason', 'detail', 'chars' );
	}

	private static function format_elementor_gap_fill_remaining_message( $post_id, array $remaining, array $map ) {
		$post_id = absint( $post_id );
		$count   = count( $remaining );

		if ( $post_id <= 0 || empty( $remaining ) ) {
			return sprintf(
				/* translators: 1: post ID, 2: remaining field count */
				__( 'Elementor — #%1$d: %2$d فیلد باقی‌مانده — تکمیل نهایی…', 'polymart-ai' ),
				$post_id,
				$count
			);
		}

		$first_path = (string) array_key_first( $remaining );
		$first_text = (string) ( $remaining[ $first_path ] ?? '' );
		$blocker    = self::describe_elementor_field_translation_blocker( $first_path, $first_text, $map );
		$detail     = (string) ( $blocker['detail'] ?? '' );

		if ( '' !== $detail ) {
			return sprintf(
				/* translators: 1: post ID, 2: remaining count, 3: field path, 4: blocker reason, 5: blocker detail */
				__( 'Elementor — #%1$d: %2$d فیلد باقی‌مانده — تکمیل نهایی… [%3$s: %4$s — %5$s]', 'polymart-ai' ),
				$post_id,
				$count,
				(string) ( $blocker['path'] ?? $first_path ),
				(string) ( $blocker['reason'] ?? 'unknown' ),
				$detail
			);
		}

		return sprintf(
			/* translators: 1: post ID, 2: remaining count, 3: field path, 4: blocker reason */
			__( 'Elementor — #%1$d: %2$d فیلد باقی‌مانده — تکمیل نهایی… [%3$s: %4$s]', 'polymart-ai' ),
			$post_id,
			$count,
			(string) ( $blocker['path'] ?? $first_path ),
			(string) ( $blocker['reason'] ?? 'unknown' )
		);
	}

	private static function log_elementor_remaining_field_diagnostics( $post_id, $lang, array $source_payload, array $map, array $skipped, $context ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$context = sanitize_text_field( (string) $context );

		if ( $post_id <= 0 || '' === $lang ) {
			return;
		}

		$remaining = self::filter_remaining_elementor_payload( $source_payload, $map, $skipped );

		if ( empty( $remaining ) ) {
			return;
		}

		$lines = array();
		$max   = 8;

		foreach ( $remaining as $path => $text ) {
			if ( count( $lines ) >= $max ) {
				break;
			}

			$blocker = self::describe_elementor_field_translation_blocker( $path, $text, $map );
			$lines[] = sprintf(
				'%s [%s: %s, %d chars]',
				$blocker['path'],
				$blocker['reason'],
				$blocker['detail'],
				(int) $blocker['chars']
			);
		}

		$extra = count( $remaining ) > $max ? sprintf( ' +%d more', count( $remaining ) - $max ) : '';

		\PolymartAI\Activity_Logger::log(
			'warning',
			sprintf(
				/* translators: 1: post ID, 2: remaining count, 3: context, 4: field diagnostics */
				__( 'Elementor — #%1$d: تشخیص %2$d فیلد باقی (%3$s) — %4$s%5$s', 'polymart-ai' ),
				$post_id,
				count( $remaining ),
				$context,
				implode( ' | ', $lines ),
				$extra
			),
			array(
				'post_id'   => $post_id,
				'lang'      => $lang,
				'remaining' => array_keys( $remaining ),
				'context'   => $context,
			)
		);
	}

	private static function schedule_elementor_gap_fill_chunks( array &$state, array $chunks ) {
		$scheduled = is_array( $state['elementor_gap_fill_scheduled_keys'] ?? null )
			? array_values( array_unique( array_map( 'strval', $state['elementor_gap_fill_scheduled_keys'] ) ) )
			: array();
		$finished  = is_array( $state['elementor_gap_fill_finished_keys'] ?? null )
			? array_values( array_unique( array_map( 'strval', $state['elementor_gap_fill_finished_keys'] ) ) )
			: array();

		foreach ( $chunks as $chunk ) {
			if ( ! is_array( $chunk ) || empty( $chunk ) ) {
				continue;
			}

			$fingerprint = self::elementor_chunk_fingerprint( $chunk );

			if ( in_array( $fingerprint, $scheduled, true ) || in_array( $fingerprint, $finished, true ) ) {
				continue;
			}

			$scheduled[] = $fingerprint;
		}

		$state['elementor_gap_fill_scheduled_keys'] = $scheduled;
		$state['elementor_gap_fill_finished_keys']  = $finished;
		$state['elementor_gap_fill_done']           = count( $finished );
		$state['elementor_gap_fill_total']          = max( 1, count( $scheduled ) );
	}

	private static function register_elementor_gap_fill_chunk_done( array &$state, array $chunk ) {
		if ( empty( $chunk ) ) {
			return;
		}

		$fingerprint = self::elementor_chunk_fingerprint( $chunk );
		$finished    = is_array( $state['elementor_gap_fill_finished_keys'] ?? null )
			? array_values( array_unique( array_map( 'strval', $state['elementor_gap_fill_finished_keys'] ) ) )
			: array();

		if ( in_array( $fingerprint, $finished, true ) ) {
			$state['elementor_gap_fill_done'] = count( $finished );
			return;
		}

		$finished[] = $fingerprint;
		$state['elementor_gap_fill_finished_keys'] = $finished;
		$state['elementor_gap_fill_done']        = count( $finished );
	}

	private static function build_elementor_gap_fill_chunks( array $source_data, array $map, array $skipped ) {
		$remaining = self::filter_remaining_elementor_payload(
			self::collect_elementor_translation_payload( $source_data ),
			$map,
			$skipped
		);

		if ( empty( $remaining ) ) {
			return array();
		}

		$remaining = self::filter_elementor_gap_fill_batch_remaining( $remaining );

		if ( empty( $remaining ) ) {
			return array();
		}

		$remaining = self::sort_elementor_gap_fill_remaining( $remaining );

		return self::filter_elementor_gap_fill_pending_chunks(
			self::chunk_elementor_gap_fill_payload( $remaining ),
			$map,
			self::collect_elementor_translation_payload( $source_data )
		);
	}

	private static function resolve_elementor_gap_fill_totals( array &$state, array $chunks ) {
		self::schedule_elementor_gap_fill_chunks( $state, $chunks );

		return array(
			'done'    => absint( $state['elementor_gap_fill_done'] ?? 0 ),
			'total'   => max( 1, absint( $state['elementor_gap_fill_total'] ?? 0 ) ),
			'pending' => count( $chunks ),
		);
	}

	private static function sort_elementor_gap_fill_remaining( array $remaining ) {
		uasort(
			$remaining,
			static function ( $a, $b ) {
				$left  = self::utf8_strlen( (string) $a );
				$right = self::utf8_strlen( (string) $b );

				if ( $left === $right ) {
					return 0;
				}

				return $left <=> $right;
			}
		);

		return $remaining;
	}

	private static function translate_elementor_gap_fill_stubborn_fields(
		$post_id,
		$lang,
		array $source_data,
		array &$state,
		array &$map,
		array $source_payload,
		array $text_mirrors,
		$api_key,
		$api_endpoint,
		$ai_model,
		$max_fields = 2
	) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return array(
				'completed' => 0,
				'touched'   => false,
			);
		}

		$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$remaining = self::sort_elementor_gap_fill_remaining(
			self::filter_remaining_elementor_payload( $source_payload, $map, $skipped )
		);

		if ( empty( $remaining ) ) {
			return array(
				'completed' => 0,
				'touched'   => false,
			);
		}

		$failures    = is_array( $state['elementor_failures'] ?? null ) ? $state['elementor_failures'] : array();
		$done        = 0;
		$touched     = false;
		$short       = array();
		$long        = array();
		$persist_map = is_array( $state['elementor_persist_map'] ?? null ) ? $state['elementor_persist_map'] : array();
		$api_budget  = max( 2, min( 8, absint( $max_fields ) * 2 ) );
		$spent       = 0;

		foreach ( $remaining as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( isset( $persist_map[ $path ] ) && self::elementor_field_translation_complete( $path, $text, $persist_map ) ) {
				$map[ $path ] = (string) $persist_map[ $path ];
				++$done;
				continue;
			}

			if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
				++$done;
				continue;
			}

			if ( self::stubborn_elementor_field_is_long( $path, $text ) ) {
				$long[ $path ] = $text;
			} else {
				$short[ $path ] = $text;
			}
		}

		// Priority 1: long fields — send missing __segN batches first.
		foreach ( $long as $path => $text ) {
			if ( $spent >= $api_budget ) {
				break;
			}

			if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
				++$done;
				continue;
			}

			$pending_batches = self::build_elementor_stubborn_segment_batches( $path, $text, $map, $source_payload );
			$batch_total     = max( 1, count( $pending_batches ) );

			foreach ( $pending_batches as $batch_index => $batch ) {
				if ( $spent >= $api_budget ) {
					break;
				}

				$seg_keys = implode( ', ', array_keys( $batch ) );
				\PolymartAI\Activity_Logger::log(
					'info',
					sprintf(
						/* translators: 1: post ID, 2: base path, 3: segment keys */
						__( 'Elementor — #%1$d: stubborn segment — %2$s → %3$s', 'polymart-ai' ),
						$post_id,
						$path,
						$seg_keys
					),
					array( 'post_id' => $post_id, 'lang' => $lang, 'path' => $path )
				);

				$progress = self::translate_elementor_stubborn_field_batch(
					$post_id,
					$lang,
					$path,
					$text,
					$batch,
					$source_data,
					$source_payload,
					$text_mirrors,
					$state,
					$map,
					$failures,
					$api_key,
					$api_endpoint,
					$ai_model,
					$batch_index + 1,
					$batch_total
				);

				++$spent;

				if ( ! empty( $progress['touched'] ) ) {
					$touched = true;
				}
			}

			if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
				++$done;
			} elseif ( $touched ) {
				$blocker = self::describe_elementor_field_translation_blocker( $path, $text, $map );
				self::log_elementor_remaining_field_diagnostics(
					$post_id,
					$lang,
					$source_payload,
					$map,
					$skipped,
					sprintf(
						'stubborn-long-partial-%s-%s',
						$path,
						(string) ( $blocker['reason'] ?? 'partial' )
					)
				);
			}
		}

		// Priority 2: short fields — only if budget remains and not already persisted.
		foreach ( array_slice( $short, 0, 2, true ) as $path => $text ) {
			if ( $spent >= $api_budget ) {
				break;
			}

			if ( isset( $persist_map[ $path ] ) && self::elementor_field_translation_complete( $path, $text, $persist_map ) ) {
				$map[ $path ] = (string) $persist_map[ $path ];
				++$done;
				continue;
			}

			if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
				++$done;
				continue;
			}

			$progress = self::translate_elementor_stubborn_field_batch(
				$post_id,
				$lang,
				$path,
				$text,
				array( $path => $text ),
				$source_data,
				$source_payload,
				$text_mirrors,
				$state,
				$map,
				$failures,
				$api_key,
				$api_endpoint,
				$ai_model,
				1,
				1
			);

			++$spent;

			if ( ! empty( $progress['touched'] ) ) {
				$touched = true;
			}

			if ( ! empty( $progress['completed'] ) ) {
				++$done;
			}
		}

		$state['elementor_failures'] = $failures;
		$state['elementor_map']      = $map;

		return array(
			'completed' => $done,
			'touched'   => $touched,
		);
	}

	private static function translate_elementor_stubborn_field_batch(
		$post_id,
		$lang,
		$path,
		$text,
		array $batch,
		array $source_data,
		array $source_payload,
		array $text_mirrors,
		array &$state,
		array &$map,
		array &$failures,
		$api_key,
		$api_endpoint,
		$ai_model,
		$batch_index,
		$batch_total
	) {
		$path        = (string) $path;
		$text        = (string) $text;
		$batch_index = max( 1, absint( $batch_index ) );
		$batch_total = max( 1, absint( $batch_total ) );
		$touched     = false;
		$completed   = false;

		if ( '' === $path || empty( $batch ) ) {
			return compact( 'completed', 'touched' );
		}

		if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
			return array(
				'completed' => true,
				'touched'   => false,
			);
		}

		list( $aliased, $alias_map ) = self::alias_elementor_payload_keys( $batch );

		if ( empty( $aliased ) ) {
			return compact( 'completed', 'touched' );
		}

		$part_count = count( $aliased );
		$log_label  = 1 === $batch_total && 1 === $part_count
			? sprintf(
				/* translators: 1: post ID, 2: field path, 3: char count */
				__( 'Elementor — #%1$d: ترجمه مستقیم فیلد stubborn — %2$s (%3$d نویسه)', 'polymart-ai' ),
				absint( $post_id ),
				$path,
				self::utf8_strlen( $text )
			)
			: sprintf(
				/* translators: 1: post ID, 2: field path, 3: batch index, 4: batch total, 5: part count */
				__( 'Elementor — #%1$d: stubborn %2$s — بخش %3$d از %4$d (%5$d part)', 'polymart-ai' ),
				absint( $post_id ),
				$path,
				$batch_index,
				$batch_total,
				$part_count
			);

		\PolymartAI\Activity_Logger::log(
			'info',
			$log_label,
			array( 'post_id' => $post_id, 'lang' => $lang, 'path' => $path )
		);

		\PolymartAI\Activity_Logger::wait_for_arvan_api_gap();
		\PolymartAI\Activity_Logger::touch_job_worker_heartbeat();

		$seg_failures = is_array( $state['elementor_segment_failures'] ?? null ) ? $state['elementor_segment_failures'] : array();
		$pending      = array();

		foreach ( $batch as $batch_path => $batch_text ) {
			$batch_path = (string) $batch_path;
			$batch_text = (string) $batch_text;

			if ( self::elementor_path_is_segment( $batch_path ) ) {
				if ( self::elementor_segment_is_resolved( $batch_path, $batch_text, $map ) ) {
					continue;
				}
			} elseif ( isset( $map[ $batch_path ] ) && self::elementor_map_value_is_valid_translation( $batch_path, $batch_text, (string) $map[ $batch_path ] ) ) {
				continue;
			}

			$pending[ $batch_path ] = $batch_text;
		}

		if ( empty( $pending ) ) {
			$completed = self::elementor_field_translation_complete( $path, $text, $map );

			return compact( 'completed', 'touched' );
		}

		$max_attempts = self::ELEMENTOR_SEGMENT_MAX_RETRIES;

		for ( $attempt = 1; $attempt <= $max_attempts && ! empty( $pending ); $attempt++ ) {
			list( $aliased_attempt, $alias_map_attempt ) = self::alias_elementor_payload_keys( $pending );

			if ( empty( $aliased_attempt ) ) {
				break;
			}

			if ( $attempt > 1 ) {
				\PolymartAI\Activity_Logger::log(
					'info',
					sprintf(
						/* translators: 1: post ID, 2: field path, 3: attempt number */
						__( 'Elementor — #%1$d: stubborn %2$s — تلاش مجدد سگمنت %3$d/%4$d', 'polymart-ai' ),
						absint( $post_id ),
						$path,
						$attempt,
						$max_attempts
					),
					array( 'post_id' => $post_id, 'lang' => $lang, 'path' => $path )
				);
				\PolymartAI\Activity_Logger::wait_for_arvan_api_gap();
			}

			$result = AI_Client::translate_fields(
				$aliased_attempt,
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang,
				array( 'max_timeout' => 45 )
			);

			\PolymartAI\Activity_Logger::touch_arvan_api_attempt();
			\PolymartAI\Activity_Logger::touch_job_worker_heartbeat();

			if ( is_wp_error( $result ) ) {
				\PolymartAI\Activity_Logger::log(
					'warning',
					sprintf(
						/* translators: 1: post ID, 2: field path, 3: attempt, 4: error */
						__( 'Elementor — #%1$d: stubborn %2$s — خطای API (تلاش %3$d): %4$s', 'polymart-ai' ),
						absint( $post_id ),
						$path,
						$attempt,
						\PolymartAI\Activity_Logger::humanize_api_error_message( $result->get_error_message() )
					),
					array( 'post_id' => $post_id, 'lang' => $lang, 'path' => $path )
				);

				\PolymartAI\Activity_Logger::maybe_apply_api_throttle_cooldown( $result );

				if ( \PolymartAI\Activity_Logger::is_job_api_cooldown_active() ) {
					$state['elementor_segment_failures'] = $seg_failures;

					return compact( 'completed', 'touched' );
				}

				foreach ( array_keys( $pending ) as $pending_key ) {
					$pending_key = (string) $pending_key;

					if ( self::elementor_path_is_segment( $pending_key ) ) {
						$seg_failures[ $pending_key ] = absint( $seg_failures[ $pending_key ] ?? 0 ) + 1;
					} else {
						$failures[ $path ] = absint( $failures[ $path ] ?? 0 ) + 1;
					}
				}

				continue;
			}

			$raw_mapped = self::unmap_elementor_aliases(
				is_array( $result ) ? $result : array(),
				$alias_map_attempt
			);
			$field_map = self::merge_elementor_api_translations_into_map( $raw_mapped, $source_payload );

			foreach ( $field_map as $map_path => $translated ) {
				$map_path   = (string) $map_path;
				$translated = trim( (string) $translated );

				if ( '' === $translated || Persian_Detector::contains_persian( $translated ) ) {
					\PolymartAI\Activity_Logger::log(
						'warning',
						sprintf(
							/* translators: 1: post ID, 2: map path, 3: reason */
							__( 'Elementor — #%1$d: stubborn رد شد — %2$s (%3$s)', 'polymart-ai' ),
							absint( $post_id ),
							$map_path,
							'' === $translated ? 'empty' : 'persian'
						),
						array( 'post_id' => $post_id, 'lang' => $lang, 'path' => $map_path )
					);

					if ( self::elementor_path_is_segment( $map_path ) ) {
						$seg_failures[ $map_path ] = absint( $seg_failures[ $map_path ] ?? 0 ) + 1;
					} else {
						$failures[ $path ] = absint( $failures[ $path ] ?? 0 ) + 1;
					}
					continue;
				}

				$source_snippet = (string) ( $pending[ $map_path ] ?? $batch[ $map_path ] ?? $source_payload[ $map_path ] ?? $text );
				if ( preg_match( '/^(.+)::__(?:part|seg)\d+$/', $map_path, $matches ) ) {
					$source_snippet = (string) ( $pending[ $map_path ] ?? $batch[ $map_path ] ?? $source_payload[ $matches[1] ] ?? $text );
				}

				if ( ! self::elementor_map_value_is_valid_translation( $map_path, $source_snippet, $translated ) ) {
					\PolymartAI\Activity_Logger::log(
						'warning',
						sprintf(
							/* translators: 1: post ID, 2: map path */
							__( 'Elementor — #%1$d: stubborn رد شد — %2$s (unchanged/invalid)', 'polymart-ai' ),
							absint( $post_id ),
							$map_path
						),
						array( 'post_id' => $post_id, 'lang' => $lang, 'path' => $map_path )
					);

					if ( self::elementor_path_is_segment( $map_path ) ) {
						$seg_failures[ $map_path ] = absint( $seg_failures[ $map_path ] ?? 0 ) + 1;
					}
					continue;
				}

				$map[ $map_path ] = $translated;
				$touched          = true;
				unset( $pending[ $map_path ], $seg_failures[ $map_path ] );
			}
		}

		foreach ( $pending as $pending_key => $pending_text ) {
			$pending_key  = (string) $pending_key;
			$pending_text = (string) $pending_text;

			if ( ! self::elementor_path_is_segment( $pending_key ) ) {
				continue;
			}

			if ( absint( $seg_failures[ $pending_key ] ?? 0 ) >= $max_attempts ) {
				self::apply_elementor_segment_source_fallback( $pending_key, $pending_text, $map, $state );
				$touched = true;
				unset( $pending[ $pending_key ], $seg_failures[ $pending_key ] );
			}
		}

		$state['elementor_segment_failures'] = $seg_failures;

		$map                    = self::expand_elementor_map_mirrors( $map, $text_mirrors );
		$map                    = self::merge_elementor_path_map( $post_id, $lang, $source_data, $map );
		$map                    = self::sanitize_elementor_translation_map( $map, $source_payload );
		$map                    = self::prepare_elementor_map_for_persist( $map, $source_payload );
		$state['elementor_map'] = $map;

		if ( $touched ) {
			\PolymartAI\Activity_Logger::touch_successful_api_call();
			self::remember_elementor_segment_progress( $post_id, $lang, $state, $map, $source_payload );
		}

		if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
			$completed = true;
			\PolymartAI\Activity_Logger::log(
				'info',
				sprintf(
					/* translators: 1: post ID, 2: field path */
					__( 'Elementor — #%1$d: stubborn تکمیل شد — %2$s', 'polymart-ai' ),
					absint( $post_id ),
					$path
				),
				array( 'post_id' => $post_id, 'lang' => $lang, 'path' => $path )
			);
		}

		return compact( 'completed', 'touched' );
	}

	private static function elementor_gap_fill_remaining_is_long_tail_only( array $source_payload, array $map, array $skipped ) {
		$remaining = self::filter_remaining_elementor_payload( $source_payload, $map, $skipped );

		if ( empty( $remaining ) || count( $remaining ) > 3 ) {
			return false;
		}

		foreach ( $remaining as $path => $text ) {
			if ( ! self::stubborn_elementor_field_is_long( (string) $path, (string) $text ) ) {
				return false;
			}
		}

		return true;
	}

}
