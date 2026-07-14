<?php
/**
 * Post_Translator Elementor Job_State (auto-split).
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

trait Trait_Job_State {

	public static function elementor_job_partial_state_is_durable( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$state = self::get_job_partial_state( $post_id, $lang );

		if ( ! is_array( $state ) || 'elementor' !== sanitize_key( (string) ( $state['phase'] ?? '' ) ) ) {
			return false;
		}

		$persist = is_array( $state['elementor_persist_map'] ?? null ) ? $state['elementor_persist_map'] : array();

		if ( ! empty( $persist ) ) {
			return true;
		}

		if ( ! empty( $state['elementor_gap_fill'] ) ) {
			return true;
		}

		if (
			! empty( $state['elementor_gap_fill_finished_keys'] )
			|| ! empty( $state['elementor_gap_fill_scheduled_keys'] )
		) {
			return true;
		}

		$primary_done = absint( $state['elementor_primary_batches_done'] ?? 0 );
		$chunk_total  = max( absint( $state['elementor_chunks_total'] ?? 0 ), $primary_done );

		if ( $primary_done > 0 && $chunk_total > 0 && $primary_done >= $chunk_total ) {
			return true;
		}

		return absint( $state['elementor_chunk_index'] ?? 0 ) > 0;
	}

	public static function should_run_elementor_job_slice( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		if (
			self::is_elementor_translation_finalized( $post_id, $lang )
			&& ! self::elementor_job_has_remaining_payload( $post_id, $lang )
		) {
			return false;
		}

		if ( self::elementor_needs_gap_fill_work( $post_id, $lang ) ) {
			return true;
		}

		if ( self::elementor_job_api_schedule_complete( $post_id, $lang ) ) {
			return false;
		}

		if (
			! self::post_needs_elementor_job_work( $post_id, $lang )
			&& ! self::elementor_job_has_remaining_payload( $post_id, $lang )
		) {
			return false;
		}

		$state = self::get_job_partial_state( $post_id, $lang );
		$phase = sanitize_key( (string) ( $state['phase'] ?? '' ) );

		if ( 'elementor' === $phase ) {
			return true;
		}

		if ( in_array( $phase, array( 'core', 'commerce', 'variations', 'fields' ), true ) ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		foreach ( self::get_job_field_phases() as $field_phase ) {
			if ( ! empty( self::collect_persian_fields_for_job_phase( $post, $lang, $field_phase ) ) ) {
				return false;
			}
		}

		return true;
	}

	public static function hydrate_elementor_job_partial_state( $post_id, $lang, array $state = array() ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		$defaults = array(
			'phase'                          => 'elementor',
			'elementor_map'                  => array(),
			'elementor_persist_map'          => array(),
			'elementor_seg_map'              => array(),
			'elementor_gap_fill_finished_keys' => array(),
			'elementor_gap_fill_scheduled_keys' => array(),
			'elementor_primary_batches_done'  => 0,
			'elementor_chunk_index'          => 0,
			'elementor_chunks_total'      => 0,
			'elementor_slices_completed'  => 0,
			'elementor_failures'          => array(),
			'elementor_skipped'           => array(),
			'elementor_segment_failures'  => array(),
			'elementor_segment_passthrough' => array(),
			'elementor_field_passthrough' => array(),
			'elementor_stubborn_ghost_ticks' => 0,
		);

		$state = array_merge( $defaults, $state );
		$state['phase'] = 'elementor';
		self::bind_elementor_segment_passthrough_context( $state );

		if ( $post_id <= 0 || '' === $lang ) {
			return $state;
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return $state;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return $state;
		}

		$state_map = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$map       = self::merge_elementor_job_path_map( $post_id, $lang, $data, $state, $state_map );

		$state['elementor_map'] = $map;
		$chunk_progress         = self::get_elementor_chunk_progress( $post_id, $lang, $state );
		$state['elementor_chunk_index']      = (int) $chunk_progress['done'];
		$state['elementor_chunks_total']     = (int) $chunk_progress['total'];
		$state['elementor_slices_completed'] = (int) $chunk_progress['done'];

		return $state;
	}

	public static function elementor_job_api_slices_pending( $post_id, $lang, array $state = array() ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		if (
			self::is_elementor_translation_finalized( $post_id, $lang )
			&& ! self::elementor_job_has_remaining_payload( $post_id, $lang )
		) {
			return false;
		}

		if ( empty( $state ) ) {
			$state = self::get_job_partial_state( $post_id, $lang );
		}

		if (
			( ! empty( $state['elementor_gap_fill'] ) || ! empty( $state['elementor_gap_fill_stubborn_only'] ) )
			&& ! self::is_elementor_translation_finalized( $post_id, $lang )
		) {
			return true;
		}

		if ( self::elementor_job_api_schedule_complete( $post_id, $lang ) ) {
			return false;
		}

		$state = self::hydrate_elementor_job_partial_state(
			$post_id,
			$lang,
			$state
		);

		$progress = self::get_elementor_chunk_progress( $post_id, $lang, $state );
		$done     = self::resolve_elementor_done_count( $state, $progress['done'], $post_id, $lang );

		return $done < max( 1, (int) $progress['total'] );
	}

	public static function elementor_job_api_schedule_complete( $post_id, $lang, $done_count = null, $total_chunks = null ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		self::bind_elementor_accepted_paths_context( $post_id, $lang );

		if (
			self::is_elementor_translation_finalized( $post_id, $lang )
			&& self::is_elementor_translation_current( $post_id, $lang )
			&& self::elementor_translation_is_storefront_ready( $post_id, $lang )
			&& ! self::stored_elementor_translation_has_persian( $post_id, $lang )
		) {
			return true;
		}

		if (
			self::is_elementor_translation_finalized( $post_id, $lang )
			&& self::is_elementor_translation_current( $post_id, $lang )
			&& ! self::elementor_job_has_remaining_payload( $post_id, $lang )
		) {
			return true;
		}

		$stored = get_post_meta( $post_id, self::get_elementor_slice_cursor_meta_key( $lang ), true );
		$cursor = is_array( $stored ) ? absint( $stored['cursor'] ?? 0 ) : absint( $stored );
		$total  = is_array( $stored ) ? absint( $stored['total'] ?? 0 ) : 0;

		if ( null !== $done_count ) {
			$cursor = max( $cursor, absint( $done_count ) );
		}

		if ( null !== $total_chunks ) {
			$total = max( $total, absint( $total_chunks ) );
		}

		if ( $total <= 0 || $cursor < $total ) {
			return false;
		}

		// A stale cursor can read "complete" while Persian source fields are still pending.
		if ( self::uses_elementor_builder( $post_id ) ) {
			$raw = get_post_meta( $post_id, '_elementor_data', true );

			if ( is_string( $raw ) && '' !== trim( $raw ) ) {
				$data = json_decode( $raw, true );

				if ( is_array( $data ) ) {
					$state = self::hydrate_elementor_job_partial_state(
						$post_id,
						$lang,
						self::get_job_partial_state( $post_id, $lang )
					);
					$state_map = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
					$map       = array_merge(
						self::rebuild_elementor_map_from_saved_translation( $post_id, $lang, $data ),
						$state_map
					);
					$skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
					$remaining = self::filter_remaining_elementor_payload(
						self::collect_elementor_translation_payload( $data ),
						$map,
						$skipped
					);

					if ( ! empty( $remaining ) ) {
						return false;
					}
				}
			}
		}

		if (
			! self::elementor_translation_is_storefront_ready( $post_id, $lang )
			|| self::storefront_would_show_persian_source( $post_id, $lang )
		) {
			return false;
		}

		return true;
	}

	public static function elementor_job_primary_batches_exhausted( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$stored = get_post_meta( $post_id, self::get_elementor_slice_cursor_meta_key( $lang ), true );
		$cursor = is_array( $stored ) ? absint( $stored['cursor'] ?? 0 ) : absint( $stored );
		$total  = is_array( $stored ) ? absint( $stored['total'] ?? 0 ) : 0;

		if ( $total > 0 && $cursor >= $total ) {
			return true;
		}

		$lock = get_post_meta( $post_id, self::get_elementor_primary_done_meta_key( $lang ), true );

		if ( is_array( $lock ) ) {
			$lock_done  = absint( $lock['done'] ?? 0 );
			$lock_total = absint( $lock['total'] ?? 0 );

			if ( $lock_done > 0 && $lock_total > 0 && $lock_done >= $lock_total ) {
				return true;
			}
		}

		// Durable hand-off flag survives accidental cursor clears during gap-fill/stubborn.
		$state        = self::get_job_partial_state( $post_id, $lang );
		$primary_done = is_array( $state ) ? absint( $state['elementor_primary_batches_done'] ?? 0 ) : 0;
		$chunk_total  = is_array( $state ) ? absint( $state['elementor_chunks_total'] ?? 0 ) : 0;
		$chunk_total  = max( $chunk_total, $total, $primary_done );

		return $primary_done > 0 && $chunk_total > 0 && $primary_done >= $chunk_total;
	}

	private static function elementor_primary_schedule_locked( $post_id, $lang, array $state = array() ) {
		if ( self::elementor_job_primary_batches_exhausted( $post_id, $lang ) ) {
			return true;
		}

		$primary_done = absint( $state['elementor_primary_batches_done'] ?? 0 );
		$chunk_total  = max(
			absint( $state['elementor_chunks_total'] ?? 0 ),
			self::read_elementor_slice_cursor_total( $post_id, $lang ),
			$primary_done,
			1
		);

		return $primary_done > 0 && $primary_done >= $chunk_total;
	}

	private static function elementor_job_map_has_no_remaining( array $source_payload, array $map, array $skipped = array() ) {
		return empty( self::filter_remaining_elementor_payload( $source_payload, $map, $skipped ) );
	}

	private static function elementor_in_gap_fill_handoff( $post_id, $lang, array $state = array() ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( ! empty( $state['elementor_gap_fill'] ) || ! empty( $state['elementor_gap_fill_stubborn_only'] ) ) {
			return true;
		}

		return self::elementor_primary_schedule_locked( $post_id, $lang, $state );
	}

	public static function elementor_needs_gap_fill_work( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$state = self::get_job_partial_state( $post_id, $lang );

		if (
			! empty( $state['elementor_gap_fill'] )
			|| ! empty( $state['elementor_gap_fill_stubborn_only'] )
		) {
			return ! self::is_elementor_translation_finalized( $post_id, $lang );
		}

		if ( ! self::elementor_job_primary_batches_exhausted( $post_id, $lang ) ) {
			return false;
		}

		return self::elementor_job_has_remaining_payload( $post_id, $lang );
	}

	public static function format_elementor_job_progress_marker( $post_id, $lang, array $state = array() ) {
		$state    = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
		$progress = self::get_elementor_chunk_progress( $post_id, $lang, $state );
		$done     = (int) $progress['done'];
		$total    = max( 1, (int) $progress['total'] );

		$cursor       = self::read_elementor_slice_cursor( $post_id, $lang );
		$stored_total = self::read_elementor_slice_cursor_total( $post_id, $lang );

		if ( $stored_total > 0 ) {
			$total = max( $total, $stored_total );
		}

		$state_done = max(
			absint( $state['elementor_slices_completed'] ?? 0 ),
			absint( $state['elementor_chunk_index'] ?? 0 )
		);

		$done = min( $total, max( $done, $cursor, $state_done ) );

		$marker = $done . '/' . $total;

		if ( ! empty( $state['elementor_gap_fill'] ) ) {
			$gap_done  = absint( $state['elementor_gap_fill_done'] ?? 0 );
			$gap_total = max( 1, absint( $state['elementor_gap_fill_total'] ?? 0 ), $gap_done );

			if ( ! empty( $state['elementor_gap_fill_stubborn_only'] ) ) {
				$stubborn_left = 0;
				$raw           = get_post_meta( $post_id, '_elementor_data', true );

				if ( is_string( $raw ) && '' !== trim( $raw ) ) {
					$data = json_decode( $raw, true );

					if ( is_array( $data ) ) {
						$source_payload = self::collect_elementor_translation_payload( $data );
						$skipped        = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
						$remaining      = self::filter_remaining_elementor_payload( $source_payload, $state['elementor_map'] ?? array(), $skipped );

						foreach ( $remaining as $path => $text ) {
							if ( self::stubborn_elementor_field_is_long( (string) $path, (string) $text ) ) {
								++$stubborn_left;
							}
						}
					}
				}

				$marker .= ' · ' . sprintf(
					/* translators: 1: completed short gap-fill batches, 2: total short gap-fill batches, 3: stubborn field count */
					__( 'تکمیل %1$d/%2$d · stubborn: %3$d فیلد', 'polymart-ai' ),
					min( $gap_done, $gap_total ),
					$gap_total,
					$stubborn_left
				);
			} else {
				$marker .= ' · ' . sprintf(
					/* translators: 1: completed gap-fill batches, 2: total gap-fill batches */
					__( 'تکمیل %1$d/%2$d', 'polymart-ai' ),
					min( $gap_done, $gap_total ),
					$gap_total
				);
			}
		}

		return $marker;
	}

	private static function format_elementor_job_slice_status_message( $post_id, $lang, array $state = array() ) {
		$overlay_map     = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$overlay_skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$state           = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );

		if ( ! empty( $overlay_map ) || ! empty( $overlay_skipped ) ) {
			$raw = get_post_meta( absint( $post_id ), '_elementor_data', true );

			if ( is_string( $raw ) && '' !== trim( $raw ) ) {
				$data = json_decode( $raw, true );

				if ( is_array( $data ) ) {
					$source_payload = self::collect_elementor_translation_payload( $data );

					if ( ! empty( $overlay_map ) ) {
						$state['elementor_map'] = array_merge(
							is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array(),
							self::sanitize_elementor_translation_map( $overlay_map, $source_payload )
						);
					}
				}
			}
		}

		if ( ! empty( $overlay_skipped ) ) {
			$state['elementor_skipped'] = $overlay_skipped;
		}

		$marker = self::format_elementor_job_progress_marker( $post_id, $lang, $state );
		$parts  = explode( '/', $marker );
		$done   = isset( $parts[0] ) ? absint( $parts[0] ) : 0;
		$total  = isset( $parts[1] ) ? max( 1, absint( $parts[1] ) ) : 1;

		return sprintf(
			/* translators: 1: completed batches, 2: total batches */
			__( 'Elementor — %1$d از %2$d بخش ذخیره شد', 'polymart-ai' ),
			$done,
			$total
		);
	}

	public static function repair_stale_elementor_job_state( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return false;
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return false;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return false;
		}

		self::bind_elementor_accepted_paths_context( $post_id, $lang );

		if ( self::repair_completed_elementor_job_meta( $post_id, $lang ) ) {
			return true;
		}

		$state     = self::get_job_partial_state( $post_id, $lang );
		$state     = is_array( $state ) ? $state : array();

		if ( self::elementor_primary_schedule_locked( $post_id, $lang, $state ) ) {
			return false;
		}

		$map       = self::merge_elementor_job_path_map( $post_id, $lang, $data, $state );
		$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$source    = self::collect_elementor_translation_payload( $data );
		$remaining = self::filter_remaining_elementor_payload( $source, $map, $skipped );
		$all_chunks = self::chunk_elementor_payload_for_job( $source );

		if ( empty( $remaining ) ) {
			return false;
		}

		$translated_count = max( 0, count( $source ) - count( $remaining ) );
		$hydrated_state   = array_merge( $state, array( 'elementor_map' => $map ) );
		$progress_done    = (int) self::get_elementor_chunk_progress( $post_id, $lang, $hydrated_state )['done'];
		$stored_total     = self::read_elementor_slice_cursor_total( $post_id, $lang );
		$chunk_total      = max( 1, count( $all_chunks ) );
		$cursor           = self::resolve_elementor_slice_cursor( $post_id, $lang, $data, $hydrated_state );
		$pending_chunks   = count( array_slice( $all_chunks, $cursor ) );
		$cursor_at_end    = $stored_total > 0 && $cursor >= $stored_total;

		// Primary schedule finished but stubborn/gap-fill fields remain — not stale.
		if ( $cursor_at_end && count( $remaining ) > 0 ) {
			return false;
		}

		$stale = $progress_done > $translated_count
			|| $stored_total > $chunk_total
			|| ( 0 === $pending_chunks && count( $remaining ) > 0 && ! $cursor_at_end );

		if ( ! $stale ) {
			return false;
		}

		self::clear_elementor_slice_cursor( $post_id, $lang );
		delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );

		$batch_progress = self::compute_elementor_api_batch_progress( $data, $map, $state );

		$state['phase']                      = 'elementor';
		$state['elementor_map']              = $map;
		$state['elementor_chunk_index']      = (int) $batch_progress['done'];
		$state['elementor_slices_completed'] = (int) $batch_progress['done'];
		$state['elementor_chunks_total']     = (int) $batch_progress['total'];

		if ( $translated_count <= 0 ) {
			unset( $state['elementor_failures'], $state['elementor_skipped'] );
		}

		self::save_job_partial_state( $post_id, $lang, $state );

		return true;
	}

	public static function unblock_elementor_chunk_queue( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return false;
		}

		if ( self::repair_stale_elementor_job_state( $post_id, $lang ) ) {
			return true;
		}

		$state = self::hydrate_elementor_job_partial_state(
			$post_id,
			$lang,
			self::get_job_partial_state( $post_id, $lang )
		);

		// Primary API schedule finished — gap-fill/stubborn runs without wiping cursor/progress.
		if ( self::elementor_primary_schedule_locked( $post_id, $lang, $state ) ) {
			return false;
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return false;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return false;
		}

		$map       = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$source    = self::collect_elementor_translation_payload( $data );
		$remaining = self::filter_remaining_elementor_payload( $source, $map, $skipped );

		if ( empty( $remaining ) ) {
			return false;
		}

		$chunk_queue = self::build_elementor_job_chunk_queue( $post_id, $lang, $data, $state );

		if ( ! empty( $chunk_queue['pending'] ) ) {
			return false;
		}

		self::clear_elementor_slice_cursor( $post_id, $lang );
		delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );

		$batch_progress = self::compute_elementor_api_batch_progress( $data, $map, $state );
		$state['phase']                      = 'elementor';
		$state['elementor_map']              = $map;
		$state['elementor_chunk_index']      = (int) $batch_progress['done'];
		$state['elementor_slices_completed'] = (int) $batch_progress['done'];
		$state['elementor_chunks_total']     = (int) $batch_progress['total'];
		unset( $state['elementor_failures'] );

		self::save_job_partial_state( $post_id, $lang, $state );

		\PolymartAI\Activity_Logger::log(
			'info',
			sprintf(
				/* translators: 1: post ID, 2: remaining field count */
				__( 'Elementor — #%1$d: صف API بازسازی شد — %2$d فیلد در انتظار', 'polymart-ai' ),
				$post_id,
				count( $remaining )
			),
			array( 'post_id' => $post_id, 'lang' => $lang )
		);

		return true;
	}

	public static function get_elementor_scan_diagnostics( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$empty   = array(
			'active'                 => false,
			'source_field_count'     => 0,
			'remaining_field_count'  => 0,
			'translated_field_count' => 0,
			'chunk_done'             => 0,
			'chunk_total'            => 0,
			'chunk_progress'         => '',
			'partial_phase'          => '',
			'has_saved_json'         => false,
			'saved_json_has_persian' => false,
			'remaining_samples'      => array(),
			'lock'                   => self::get_translation_lock_status( $post_id, $lang ),
			'bulk_job_running'       => \PolymartAI\Activity_Logger::is_bulk_job_running(),
			'bulk_job_on_post'       => false,
		);

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return $empty;
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array_merge(
				$empty,
				array(
					'active' => true,
					'error'  => __( 'داده Elementor (_elementor_data) خالی است.', 'polymart-ai' ),
				)
			);
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return array_merge(
				$empty,
				array(
					'active' => true,
					'error'  => __( 'JSON Elementor نامعتبر است.', 'polymart-ai' ),
				)
			);
		}

		$state     = self::get_job_partial_state( $post_id, $lang );
		$state     = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
		$map       = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$source    = self::collect_elementor_translation_payload( $data );
		$remaining = self::filter_remaining_elementor_payload( $source, $map, $skipped );
		$chunk_queue = self::build_elementor_job_chunk_queue( $post_id, $lang, $data, $state );
		$progress    = self::get_elementor_chunk_progress( $post_id, $lang, $state );
		$samples     = array();

		foreach ( array_slice( $remaining, 0, 8, true ) as $path => $text ) {
			$samples[] = array(
				'path'    => (string) $path,
				'preview' => wp_trim_words( wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 14, '…' ),
			);
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

		$saved_raw = get_post_meta( $post_id, self::get_elementor_meta_key( $lang ), true );
		$state_map_count = 0;

		foreach ( $map as $value ) {
			$value = trim( (string) $value );

			if ( '' !== $value && ! Persian_Detector::contains_persian( $value ) ) {
				++$state_map_count;
			}
		}

		$api_cooldown_remaining = \PolymartAI\Activity_Logger::get_job_api_cooldown_remaining();
		$stale_cursor           = (int) $progress['done'] > max( 0, count( $source ) - count( $remaining ) ) && count( $remaining ) > 0;
		$collapsed              = self::collapse_duplicate_elementor_payload( $source );
		$api_batches            = self::chunk_elementor_payload_for_job( $collapsed['payload'] );
		$long_field_count       = 0;
		$long_remaining_count   = 0;

		foreach ( $source as $text ) {
			if ( mb_strlen( (string) $text ) > 300 ) {
				++$long_field_count;
			}
		}

		foreach ( $remaining as $text ) {
			if ( mb_strlen( (string) $text ) > 300 ) {
				++$long_remaining_count;
			}
		}

		$filtered_probe         = self::collect_elementor_filtered_out_payload( $data );
		$api_payload_samples    = array();
		$sample_index           = 0;

		foreach ( array_slice( $collapsed['payload'], 0, 4, true ) as $path => $text ) {
			$api_payload_samples[] = array(
				'key'     => 'el_' . $sample_index,
				'preview' => wp_trim_words( wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 16, '…' ),
			);
			++$sample_index;
		}

		return array(
			'active'                 => true,
			'source_field_count'     => count( $source ),
			'unique_text_count'      => count( $collapsed['payload'] ),
			'duplicate_field_count'  => max( 0, count( $source ) - count( $collapsed['payload'] ) ),
			'api_batch_count'        => max( 1, count( $api_batches ) ),
			'api_payload_samples'    => $api_payload_samples,
			'remaining_field_count'  => count( $remaining ),
			'translated_field_count' => max( 0, count( $source ) - count( $remaining ) ),
			'state_map_field_count'  => $state_map_count,
			'chunk_done'             => (int) $progress['done'],
			'chunk_total'            => max( 1, (int) $progress['total'], (int) $chunk_queue['total'] ),
			'chunk_progress'         => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
			'partial_phase'          => (string) ( $state['phase'] ?? '' ),
			'pending_api_chunks'     => count( $chunk_queue['pending'] ),
			'has_saved_json'         => is_string( $saved_raw ) && '' !== trim( $saved_raw ),
			'saved_json_has_persian' => self::stored_elementor_translation_has_persian( $post_id, $lang ),
			'remaining_samples'      => $samples,
			'long_field_count'       => $long_field_count,
			'long_remaining_count'   => $long_remaining_count,
			'filtered_out_count'     => count( $filtered_probe ),
			'filtered_out_samples'   => array_slice(
				array_map(
					static function ( $item ) {
						return array(
							'path'    => (string) ( $item['path'] ?? '' ),
							'preview' => wp_trim_words( wp_strip_all_tags( html_entity_decode( (string) ( $item['preview'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 12, '…' ),
						);
					},
					array_values( $filtered_probe )
				),
				0,
				4
			),
			'stale_api_cursor'       => $stale_cursor,
			'api_cooldown_active'    => $api_cooldown_remaining > 0,
			'api_cooldown_remaining' => $api_cooldown_remaining,
			'lock'                   => self::get_translation_lock_status( $post_id, $lang ),
			'bulk_job_running'       => \PolymartAI\Activity_Logger::is_bulk_job_running(),
			'bulk_job_on_post'       => $bulk_on_post,
			'error'                  => (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true ),
		);
	}

	public static function elementor_job_slice_is_truly_complete( $post_id, $lang, array $state = array() ) {
		unset( $state );

		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return true;
		}

		if ( self::stored_elementor_translation_has_persian( $post_id, $lang ) ) {
			return false;
		}

		return ! self::elementor_job_has_remaining_payload( $post_id, $lang );
	}

	public static function elementor_job_has_remaining_payload( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return false;
		}

		self::bind_elementor_accepted_paths_context( $post_id, $lang );

		if ( self::elementor_job_api_schedule_complete( $post_id, $lang ) ) {
			return false;
		}

		if ( ! self::should_require_elementor_translation( $post_id ) ) {
			return false;
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return false;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return false;
		}

		$state     = self::get_job_partial_state( $post_id, $lang );
		$map       = self::merge_elementor_job_path_map( $post_id, $lang, $data, $state );
		$skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();

		$remaining = self::filter_remaining_elementor_payload(
			self::collect_elementor_translation_payload( $data ),
			$map,
			$skipped
		);

		return ! empty( $remaining );
	}

	public static function post_needs_elementor_job_work( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return false;
		}

		self::bind_elementor_accepted_paths_context( $post_id, $lang );
		self::repair_stale_elementor_completion_meta( $post_id, $lang );

		if ( self::elementor_job_api_schedule_complete( $post_id, $lang ) ) {
			if (
				self::elementor_translation_is_storefront_ready( $post_id, $lang )
				&& ! self::storefront_would_show_persian_source( $post_id, $lang )
			) {
				return false;
			}
		}

		if (
			self::is_elementor_translation_finalized( $post_id, $lang )
			&& self::is_elementor_translation_current( $post_id, $lang )
			&& self::elementor_translation_is_storefront_ready( $post_id, $lang )
			&& ! self::stored_elementor_translation_has_persian( $post_id, $lang )
		) {
			return false;
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return false;
		}

		if ( ! self::should_require_elementor_translation( $post_id ) ) {
			return false;
		}

		if ( ! self::has_elementor_persian_content( $post_id ) ) {
			return false;
		}

		if ( self::is_elementor_translation_current( $post_id, $lang ) ) {
			if ( ! self::is_elementor_translation_finalized( $post_id, $lang ) ) {
				return true;
			}

			if ( self::stored_elementor_translation_has_persian( $post_id, $lang ) ) {
				return true;
			}

			if ( self::elementor_job_has_remaining_payload( $post_id, $lang ) ) {
				return true;
			}

			$previous_error = (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true );

			if ( self::is_elementor_progress_message( $previous_error ) ) {
				delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
				delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );

				return self::elementor_job_has_remaining_payload( $post_id, $lang );
			}

			if ( '' !== trim( $previous_error ) ) {
				return true;
			}

			if ( ! self::elementor_translation_is_storefront_ready( $post_id, $lang ) ) {
				return true;
			}

			return false;
		}

		return true;
	}

}
