<?php
/**
 * Post_Translator Elementor Job_Cursor (auto-split).
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

trait Trait_Job_Cursor {

	private static function get_elementor_primary_done_meta_key( $lang ) {
		return '_polymart_ai_elementor_primary_done_' . sanitize_key( (string) $lang );
	}

	private static function resolve_elementor_done_count( array $state, $computed_done, $post_id = 0, $lang = '' ) {
		unset( $state, $post_id, $lang );

		return max( 0, absint( $computed_done ) );
	}

	private static function get_elementor_slice_cursor_meta_key( $lang ) {
		return '_polymart_ai_elementor_slice_cursor_' . sanitize_key( (string) $lang );
	}

	private static function read_elementor_slice_cursor( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return 0;
		}

		$stored = get_post_meta( $post_id, self::get_elementor_slice_cursor_meta_key( $lang ), true );
		$cursor = is_array( $stored ) ? absint( $stored['cursor'] ?? 0 ) : absint( $stored );

		if ( $cursor <= 0 ) {
			$progress = (string) get_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ), true );

			if ( preg_match( '/(\d+)\s*\/\s*(\d+)/', $progress, $matches ) ) {
				$cursor = absint( $matches[1] );
			}
		}

		return max( 0, $cursor );
	}

	private static function read_elementor_slice_cursor_total( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return 0;
		}

		$stored = get_post_meta( $post_id, self::get_elementor_slice_cursor_meta_key( $lang ), true );

		return is_array( $stored ) ? absint( $stored['total'] ?? 0 ) : 0;
	}

	private static function commit_elementor_chunk_progress_to_site( $post_id, $lang, array $source_data, array &$state, array $map, $done_count, $total_chunks, $gap_fill = false ) {
		$post_id      = absint( $post_id );
		$lang         = sanitize_key( (string) $lang );
		$done_count   = max( 0, absint( $done_count ) );
		$total_chunks = max( 1, absint( $total_chunks ) );
		$gap_fill     = $gap_fill || ! empty( $state['elementor_gap_fill'] );

		if ( $post_id <= 0 || '' === $lang ) {
			return true;
		}

		if ( empty( $map ) && $done_count <= 0 ) {
			return true;
		}

		if ( $gap_fill ) {
			$primary_total = max(
				$total_chunks,
				absint( $state['elementor_primary_batches_done'] ?? 0 ),
				self::read_elementor_slice_cursor_total( $post_id, $lang )
			);
			$total_chunks = max( 1, $primary_total );
			$done_count   = min( $total_chunks, max( $done_count, $total_chunks ) );
		} else {
			$batch_progress = self::compute_elementor_api_batch_progress( $source_data, $map, $state );
			$done_count     = max(
				(int) $batch_progress['done'],
				absint( $done_count ),
				absint( $state['elementor_slices_completed'] ?? 0 ),
				absint( $state['elementor_chunk_index'] ?? 0 )
			);
			$total_chunks = max( 1, (int) $batch_progress['total'], absint( $total_chunks ) );
		}

		if ( $done_count <= 0 && empty( $map ) ) {
			return true;
		}

		$state['elementor_chunk_index']      = $done_count;
		$state['elementor_slices_completed'] = $done_count;
		$state['elementor_chunks_total']     = $total_chunks;

		$persisted = self::persist_elementor_job_progress(
			$post_id,
			$lang,
			$source_data,
			$map,
			$done_count,
			$total_chunks,
			false
		);

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		if ( $done_count > 0 ) {
			self::write_elementor_slice_cursor( $post_id, $lang, $done_count, $total_chunks );
		}

		self::save_job_partial_state( $post_id, $lang, $state );
		\PolymartAI\Activity_Logger::sync_elementor_partial_progress( $post_id, $lang );

		return true;
	}

	private static function write_elementor_slice_cursor( $post_id, $lang, $cursor, $total = 0 ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$cursor  = absint( $cursor );

		if ( $post_id <= 0 || '' === $lang || $cursor <= 0 ) {
			return;
		}

		$key     = self::get_elementor_slice_cursor_meta_key( $lang );
		$stored  = get_post_meta( $post_id, $key, true );
		$current = is_array( $stored ) ? absint( $stored['cursor'] ?? 0 ) : absint( $stored );
		$cursor  = max( $current, $cursor );
		$total   = max( absint( $total ), absint( is_array( $stored ) ? ( $stored['total'] ?? 0 ) : 0 ), 1 );

		if ( $cursor > $total ) {
			$cursor = $total;
		}

		update_post_meta(
			$post_id,
			$key,
			array(
				'cursor' => $cursor,
				'total'  => $total,
			)
		);

		if ( $total > 0 && $cursor >= $total ) {
			self::persist_elementor_primary_batches_done( $post_id, $lang, $total );
		}
	}

	private static function persist_elementor_primary_batches_done( $post_id, $lang, $total ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$total   = max( 1, absint( $total ) );

		if ( $post_id <= 0 || '' === $lang ) {
			return;
		}

		$partial = self::get_job_partial_state( $post_id, $lang );
		$partial = is_array( $partial ) ? $partial : array();

		$partial['phase']                          = 'elementor';
		$partial['elementor_primary_batches_done'] = $total;
		$partial['elementor_chunks_total']         = max( absint( $partial['elementor_chunks_total'] ?? 0 ), $total );
		$partial['elementor_chunk_index']          = max( absint( $partial['elementor_chunk_index'] ?? 0 ), $total );
		$partial['elementor_slices_completed']     = max( absint( $partial['elementor_slices_completed'] ?? 0 ), $total );

		self::save_job_partial_state( $post_id, $lang, $partial );

		update_post_meta(
			$post_id,
			self::get_elementor_primary_done_meta_key( $lang ),
			array(
				'done'  => $total,
				'total' => max( $total, absint( $partial['elementor_chunks_total'] ?? 0 ) ),
			)
		);
	}

	private static function clear_elementor_primary_batches_lock( $post_id, $lang ) {
		delete_post_meta( absint( $post_id ), self::get_elementor_primary_done_meta_key( sanitize_key( (string) $lang ) ) );
	}

	private static function clear_elementor_slice_cursor( $post_id, $lang ) {
		delete_post_meta( absint( $post_id ), self::get_elementor_slice_cursor_meta_key( sanitize_key( (string) $lang ) ) );
	}

	private static function infer_elementor_slice_cursor_from_saved( $post_id, $lang, array $source_data, array $map ) {
		unset( $post_id, $lang );

		$source_payload = self::collect_elementor_translation_payload( $source_data );
		$all_chunks     = self::chunk_elementor_payload_for_job(
			self::collapse_duplicate_elementor_payload( $source_payload )['payload']
		);

		foreach ( $all_chunks as $index => $chunk ) {
			if ( ! self::elementor_chunk_is_satisfied( $chunk, $map, $source_payload ) ) {
				return absint( $index );
			}
		}

		return count( $all_chunks );
	}

	private static function reconcile_elementor_slice_cursor( $post_id, $lang, array $source_data, array $map ) {
		$inferred = self::infer_elementor_slice_cursor_from_saved( $post_id, $lang, $source_data, $map );
		$stored   = self::read_elementor_slice_cursor( $post_id, $lang );

		if ( $stored <= 0 ) {
			return $inferred;
		}

		// During bulk jobs the in-memory map advances before _elementor_data_{lang} is fully
		// readable — never roll the API batch cursor backward (that re-translates chunk 1–3).
		if ( \PolymartAI\Activity_Logger::is_bulk_job_running() ) {
			return max( $stored, $inferred );
		}

		$translated_paths = 0;

		foreach ( $map as $value ) {
			$value = trim( (string) $value );

			if ( '' !== $value && ! Persian_Detector::contains_persian( $value ) ) {
				++$translated_paths;
			}
		}

		if ( $stored > $inferred && $stored > $translated_paths ) {
			self::clear_elementor_slice_cursor( $post_id, $lang );
			delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );

			$state = self::get_job_partial_state( $post_id, $lang );

			if ( is_array( $state ) ) {
				$state['elementor_chunk_index']      = $inferred;
				$state['elementor_slices_completed'] = $inferred;
				self::save_job_partial_state( $post_id, $lang, $state );
			}

			return $inferred;
		}

		return max( $stored, $inferred );
	}

	private static function resolve_elementor_slice_cursor( $post_id, $lang, array $source_data, array $state ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		$map = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$map = self::merge_elementor_path_map( $post_id, $lang, $source_data, $map );
		self::reconcile_elementor_slice_cursor( $post_id, $lang, $source_data, $map );

		$batch_progress = self::compute_elementor_api_batch_progress( $source_data, $map, $state );

		return min( (int) $batch_progress['done'], (int) $batch_progress['total'] );
	}

	private static function get_elementor_chunk_progress( $post_id, $lang, array $state ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$done    = 0;
		$total   = 1;

		if ( $post_id > 0 && '' !== $lang ) {
			$raw = get_post_meta( $post_id, '_elementor_data', true );

			if ( is_string( $raw ) && '' !== trim( $raw ) ) {
				$data = json_decode( $raw, true );

				if ( is_array( $data ) ) {
					$map = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
					$map = self::merge_elementor_path_map( $post_id, $lang, $data, $map );
					self::reconcile_elementor_slice_cursor( $post_id, $lang, $data, $map );

					$batch_progress = self::compute_elementor_api_batch_progress( $data, $map, $state );
					$done           = (int) $batch_progress['done'];
					$total          = (int) $batch_progress['total'];
				}
			}
		}

		return array(
			'done'  => $done,
			'total' => $total,
		);
	}

	private static function count_elementor_job_progress_done( $post_id, $lang, array $state ) {
		return self::get_elementor_chunk_progress( $post_id, $lang, $state )['done'];
	}

	private static function get_elementor_progress_meta_key( $lang ) {
		return '_polymart_ai_elementor_progress_' . sanitize_key( (string) $lang );
	}

	private static function is_elementor_progress_message( $message ) {
		$message = trim( (string) $message );

		if ( '' === $message ) {
			return false;
		}

		return false !== strpos( $message, 'ترجمه Elementor در حال انجام' )
			|| false !== strpos( $message, 'Elementor —' );
	}

}
