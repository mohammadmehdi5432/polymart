<?php
/**
 * Post_Translator Elementor Job_Chunk (auto-split).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\Elementor\Job;

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\AI_Client;
use PolymartAI\Translation\Persian_Detector;
use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Post_Translator\Persistence_Guard;
use PolymartAI\Translation\Post_Translator\Storefront_Resolver;
use PolymartAI\Translation\Post_Translator\Text_Normalizer;
use PolymartAI\Translation\Post_Translator\Translation_Lock;

defined( 'ABSPATH' ) || exit;

trait Trait_Job_Chunk {

	private static function elementor_chunk_is_satisfied( array $chunk, array $map, array $source_payload ) {
		if ( empty( $chunk ) ) {
			return false;
		}

		foreach ( $chunk as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( preg_match( '/^(.+)::__part\d+$/', $path, $matches ) ) {
				$base        = $matches[1];
				$source_text = (string) ( $source_payload[ $base ] ?? $text );

				if ( isset( $map[ $path ] ) && '' !== trim( (string) $map[ $path ] ) && ! Persian_Detector::contains_persian( (string) $map[ $path ] ) ) {
					continue;
				}

				if ( self::elementor_field_translation_complete( $base, $source_text, $map ) ) {
					continue;
				}

				return false;
			}

			$source_text = (string) ( $source_payload[ $path ] ?? $text );

			if ( ! self::elementor_field_translation_complete( $path, $source_text, $map ) ) {
				return false;
			}
		}

		return true;
	}

	private static function elementor_chunk_paths_translated( array $chunk, array $map, array $source_payload = array() ) {
		foreach ( $chunk as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( preg_match( '/^(.+)::__part\d+$/', $path, $matches ) ) {
				$base = $matches[1];

				if ( isset( $map[ $path ] ) && self::elementor_map_value_is_valid_translation( $path, $text, (string) $map[ $path ] ) ) {
					continue;
				}

				$base_text = (string) ( $source_payload[ $base ] ?? $chunk[ $base ] ?? $text );

				if ( ! self::elementor_field_translation_complete( $base, $base_text, $map ) ) {
					return false;
				}

				continue;
			}

			if ( preg_match( '/^(.+)::__seg\d+$/', $path, $matches ) ) {
				$base = $matches[1];

				if ( isset( $map[ $path ] ) && self::elementor_map_value_is_valid_translation( $path, $text, (string) $map[ $path ] ) ) {
					continue;
				}

				$base_text = (string) ( $source_payload[ $base ] ?? $chunk[ $base ] ?? '' );

				if ( '' === $base_text ) {
					return false;
				}

				if ( ! self::elementor_field_translation_complete( $base, $base_text, $map ) ) {
					return false;
				}

				continue;
			}

			if ( ! self::elementor_field_translation_complete( $path, $text, $map ) ) {
				return false;
			}
		}

		return ! empty( $chunk );
	}

	private static function compute_elementor_api_batch_progress( array $source_data, array $map, array $state = array() ) {
		$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$payload   = self::collect_elementor_translation_payload( $source_data );
		$remaining = self::filter_remaining_elementor_payload( $payload, $map, $skipped );
		$collapsed_all       = self::collapse_duplicate_elementor_payload( $payload )['payload'];
		$collapsed_remaining = self::collapse_duplicate_elementor_payload( $remaining )['payload'];
		$all_chunks          = self::chunk_elementor_payload_for_job( $collapsed_all );
		$left_chunks         = self::chunk_elementor_payload_for_job( $collapsed_remaining );

		return array(
			'done'  => max( 0, count( $all_chunks ) - count( $left_chunks ) ),
			'total' => max( 1, count( $all_chunks ) ),
		);
	}

	private static function build_elementor_job_chunk_queue( $post_id, $lang, array $source_data, array $state ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		$source_payload = self::collect_elementor_translation_payload( $source_data );
		$collapsed      = self::collapse_duplicate_elementor_payload( $source_payload );
		$all_chunks     = self::chunk_elementor_payload_for_job( $collapsed['payload'] );
		$total          = max( 1, count( $all_chunks ) );

		// Primary 24/24 is immutable — never re-queue base chunks during gap-fill/stubborn.
		if ( self::elementor_primary_schedule_locked( $post_id, $lang, $state ) ) {
			$stored_total  = max(
				$total,
				self::read_elementor_slice_cursor_total( $post_id, $lang ),
				absint( $state['elementor_primary_batches_done'] ?? 0 ),
				absint( $state['elementor_chunks_total'] ?? 0 ),
				1
			);
			$stored_cursor = max(
				$stored_total,
				self::read_elementor_slice_cursor( $post_id, $lang ),
				absint( $state['elementor_primary_batches_done'] ?? 0 )
			);

			return array(
				'all'     => $all_chunks,
				'pending' => array(),
				'total'   => $stored_total,
				'cursor'  => $stored_cursor,
				'mirrors' => $collapsed['mirrors'],
			);
		}

		$map     = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$map     = self::merge_elementor_path_map( $post_id, $lang, $source_data, $map );
		$skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$skipped_lookup = array_flip( array_map( 'strval', $skipped ) );

		$stored_cursor  = self::read_elementor_slice_cursor( $post_id, $lang );
		$pending        = array();

		foreach ( $all_chunks as $idx => $chunk ) {
			if ( ! is_array( $chunk ) || empty( $chunk ) ) {
				continue;
			}

			if ( $idx < $stored_cursor ) {
				continue;
			}

			$needs_work = false;

			foreach ( $chunk as $path => $text ) {
				$path = (string) $path;

				if ( isset( $skipped_lookup[ $path ] ) ) {
					continue;
				}

				if ( ! self::elementor_field_translation_complete( $path, (string) $text, $map ) ) {
					$needs_work = true;
					break;
				}
			}

			if ( $needs_work ) {
				$pending[] = $chunk;
			}
		}

		$computed = max( 0, $total - count( $pending ) );
		$cursor   = max( $stored_cursor, $computed );

		return array(
			'all'     => $all_chunks,
			'pending' => $pending,
			'total'   => $total,
			'cursor'  => $cursor,
			'mirrors' => $collapsed['mirrors'],
		);
	}

	private static function rebuild_elementor_map_from_saved_translation( $post_id, $lang, array $source_data ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$raw     = get_post_meta( $post_id, self::get_elementor_meta_key( $lang ), true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$saved = json_decode( $raw, true );

		if ( ! is_array( $saved ) ) {
			return array();
		}

		$source_payload = self::collect_elementor_translation_payload( $source_data );
		$map            = array();

		foreach ( $source_payload as $path => $source_text ) {
			$source_text = (string) $source_text;
			$source_raw  = self::get_elementor_value_at_path( $source_data, $path );

			if ( ! is_string( $source_raw ) || '' === trim( $source_raw ) ) {
				$source_raw = $source_text;
			}

			$translated = self::get_elementor_value_at_path( $saved, $path );

			if ( ! is_string( $translated ) || '' === trim( $translated ) ) {
				continue;
			}

			$translated = trim( $translated );

			// Still the Persian source — not a completed translation.
			if ( $translated === trim( (string) $source_raw ) ) {
				continue;
			}

			if ( Persian_Detector::contains_persian( $translated ) ) {
				continue;
			}

			$map[ $path ] = $translated;
		}

		return $map;
	}

	private static function get_elementor_value_at_path( array $node, $path ) {
		$parts = explode( '.', (string) $path );
		array_shift( $parts );

		$current = $node;

		foreach ( $parts as $part ) {
			if ( ! is_array( $current ) ) {
				return null;
			}

			if ( array_key_exists( $part, $current ) ) {
				$current = $current[ $part ];
				continue;
			}

			if ( ctype_digit( (string) $part ) && array_key_exists( (int) $part, $current ) ) {
				$current = $current[ (int) $part ];
				continue;
			}

			return null;
		}

		return is_string( $current ) ? $current : null;
	}

}
