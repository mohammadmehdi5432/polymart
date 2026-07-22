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

		// Stale kill/timeout errors must not block recovery UI / storefront serve gates.
		$error_meta = (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true );

		if ( '' !== trim( $error_meta ) && false !== strpos( $error_meta, 'Action Scheduler' ) ) {
			delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
		}

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

		if ( $ghost_ticks >= 2 && ! empty( $remaining ) ) {
			foreach ( $remaining as $path => $text ) {
				$path = (string) $path;
				$text = (string) $text;

				if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
					continue;
				}

				self::apply_elementor_field_source_fallback( $path, $text, $map, $state );
			}

			$map = self::sanitize_elementor_translation_map( $map, $source_payload );
			$map = self::prepare_elementor_map_for_persist( $map, $source_payload );
			$state['elementor_map'] = $map;
			self::save_job_partial_state( $post_id, $lang, $state );
			$remaining = self::filter_remaining_elementor_payload( $source_payload, $map, $skipped );
		}

		$ghost_limit = max(
			self::ELEMENTOR_STUBBORN_GHOST_LOOP_LIMIT,
			min( 8, self::resolve_elementor_long_gap_force_attempt_limit( $post_id, $lang, $state ) )
		);

		if ( $ghost_ticks >= $ghost_limit ) {
			self::log_elementor_remaining_field_diagnostics(
				$post_id,
				$lang,
				$source_payload,
				$map,
				$skipped,
				'force-finalize-ghost-limit',
				$state
			);

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
			self::log_elementor_remaining_field_diagnostics(
				$post_id,
				$lang,
				$source_payload,
				$map,
				$skipped,
				'stubborn-handoff',
				$state
			);

			$stubborn_field_cap = min( 4, $stubborn_left );

			if ( \PolymartAI\Activity_Logger::is_trusted_as_tick() ) {
				// One GapGPT call per AS action — avoids 90–120s batch kills mid-stubborn.
				$stubborn_field_cap = 1;
			}

			\PolymartAI\Activity_Logger::log(
				'info',
				sprintf(
					/* translators: 1: post ID, 2: remaining field count, 3: ghost tick count */
					__( 'Elementor — #%1$d: stubborn dispatch — %2$d فیلد باقی (تلاش %3$d/%4$d)', 'polymart-ai' ),
					$post_id,
					$stubborn_left,
					$ghost_ticks + 1,
					$ghost_limit
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
				$stubborn_field_cap
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
			absint( $state['elementor_stubborn_ghost_ticks'] ?? 0 ) >= 2
			&& ! empty( $remaining_after )
			&& self::elementor_map_has_persistable_translations( $map, $source_payload )
		) {
			self::log_elementor_remaining_field_diagnostics(
				$post_id,
				$lang,
				$source_payload,
				$map,
				$skipped,
				'force-finalize-partial-persist',
				$state
			);

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

		if (
			absint( $state['elementor_stubborn_ghost_ticks'] ?? 0 ) >= $ghost_limit
			&& ! empty( $remaining_after )
		) {
			self::log_elementor_remaining_field_diagnostics(
				$post_id,
				$lang,
				$source_payload,
				$map,
				$skipped,
				'force-finalize-tick-limit',
				$state
			);

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

		if ( self::maybe_force_finalize_elementor_tail_in_pipeline( $post_id, $lang, 'pipeline-stubborn-handoff' ) ) {
			return self::pipeline_force_finalize_if_storefront_ready( $post_id, $lang, 'pipeline-stubborn-handoff' );
		}

		\PolymartAI\Activity_Logger::schedule_elementor_partial_follow_up( 0 );

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
		$map = self::prepare_elementor_map_for_persist( $map, $source_payload );
		$state['elementor_map'] = $map;

		if ( ! self::elementor_job_map_has_no_remaining( $source_payload, $map, $skipped ) ) {
			$remaining_blocked = self::filter_remaining_elementor_payload( $source_payload, $map, $skipped );

			if (
				1 === count( $remaining_blocked )
				&& self::elementor_field_translation_complete(
					(string) array_key_first( $remaining_blocked ),
					(string) reset( $remaining_blocked ),
					$map
				)
			) {
				$remaining_blocked = array();
			}

			if ( ! empty( $remaining_blocked ) ) {
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

		self::force_claim_translation_lock( $post_id, $lang );

		$persisted = self::persist_elementor_job_progress(
			$post_id,
			$lang,
			$source_data,
			$map,
			$progress_total,
			$progress_total,
			true,
			true
		);

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
			$state['elementor_long_gap_force_attempts'],
			$state['elementor_segment_passthrough'],
			$state['elementor_field_passthrough']
		);

		self::clear_elementor_primary_batches_lock( $post_id, $lang );
		self::clear_elementor_slice_cursor( $post_id, $lang );
		self::clear_job_partial_state( $post_id, $lang, true );
		self::clear_elementor_stubborn_field_diagnostics( $post_id, $lang );
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
		$forced_long    = 0;
		$accepted_paths = array();

		// Recovery / force-finalize must own the lock or the emergency save loops forever.
		self::force_claim_translation_lock( $post_id, $lang );

		if ( ! empty( $remaining ) ) {
			self::log_elementor_remaining_field_diagnostics(
				$post_id,
				$lang,
				$source_payload,
				$map,
				$skipped,
				'force-finalize-fallback',
				$state
			);
		}

		$ghost         = absint( $state['elementor_stubborn_ghost_ticks'] ?? 0 );
		$long_attempts = absint( $state['elementor_long_gap_force_attempts'] ?? 0 );
		$long_limit    = self::resolve_elementor_long_gap_force_attempt_limit( $post_id, $lang, $state );
		$reopens       = absint( $state['elementor_force_persian_reopens'] ?? 0 );
		$stored_clean  = ! self::stored_elementor_translation_has_persian( $post_id, $lang );
		$chunk_progress = self::get_elementor_chunk_progress( $post_id, $lang, $state );
		$done_ratio_ok  = (int) ( $chunk_progress['done'] ?? 0 ) >= max( 1, (int) floor( max( 1, (int) ( $chunk_progress['total'] ?? 1 ) ) * 0.8 ) );
		// Prefer real AI retries first; after the attempt/ghost ceiling, accept long HTML
		// with whatever English segments we already have (+ source only for gaps).
		$accept_long_fallback = (
			$ghost >= max( self::ELEMENTOR_STUBBORN_GHOST_LOOP_LIMIT, min( 8, $long_limit ) )
			|| $long_attempts >= $long_limit
			|| $reopens >= 2
			|| (
				1 === count( $remaining )
				&& $stored_clean
				&& ( $ghost >= 1 || $reopens >= 1 || $long_attempts >= 1 || $done_ratio_ok )
			)
			|| (
				// Single stubborn leftover with missing __segN after primary N/N — don't pin forever.
				1 === count( $remaining )
				&& ( $ghost >= 1 || $reopens >= 1 || $long_attempts >= 1 )
			)
		);

		$long_remaining = 0;

		foreach ( $remaining as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
				continue;
			}

			$is_long = self::stubborn_elementor_field_is_long( $path, $text );

			if ( $is_long && ! $accept_long_fallback ) {
				++$long_remaining;
				continue;
			}

			// Prefer collapsing already-translated __segN pieces; only fill missing with source.
			if ( $is_long ) {
				$partial = self::accept_elementor_long_field_with_partial_fallback( $path, $text, $map, $state );
				$map     = self::prepare_elementor_map_for_persist( $map, array( $path => $text ), true );
				$accepted_paths[] = $path;
				++$forced_long;

				\PolymartAI\Activity_Logger::log(
					'info',
					sprintf(
						/* translators: 1: post ID, 2: path, 3: kept, 4: fallback */
						__( 'Elementor — #%1$d: long fallback %2$s — kept=%3$d fallback=%4$d', 'polymart-ai' ),
						$post_id,
						$path,
						(int) ( $partial['kept'] ?? 0 ),
						(int) ( $partial['fallback'] ?? 0 )
					),
					array( 'post_id' => $post_id, 'lang' => $lang, 'path' => $path )
				);
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
		$map = self::prepare_elementor_map_for_persist( $map, $source_payload, true );
		$state['elementor_map'] = $map;

		self::sync_elementor_persist_map_state( $state, $map, $source_payload );

		if ( $long_remaining > 0 ) {
			$state['elementor_gap_fill']               = true;
			$state['elementor_gap_fill_stubborn_only']  = true;
			$state['elementor_long_gap_force_attempts'] = $long_attempts + 1;
			// Do NOT reset ghost ticks — that erased progress and caused infinite recovery.

			$persisted = self::persist_elementor_job_progress(
				$post_id,
				$lang,
				$source_data,
				$map,
				$progress_total,
				$progress_total,
				false,
				true
			);

			if ( is_wp_error( $persisted ) ) {
				return $persisted;
			}

			self::save_job_partial_state( $post_id, $lang, $state );
			self::flush_translation_status_cache( $post_id );

			\PolymartAI\Activity_Logger::log(
				'warning',
				sprintf(
					/* translators: 1: post ID, 2: short forced count, 3: long remaining, 4: attempts, 5: limit */
					__( 'Elementor — #%1$d: %2$d فیلد کوتاه force شد؛ %3$d فیلد بلند HTML همچنان در gap-fill می‌ماند (تلاش %4$d/%5$d).', 'polymart-ai' ),
					$post_id,
					$forced,
					$long_remaining,
					(int) $state['elementor_long_gap_force_attempts'],
					$long_limit
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);

			return array(
				'done'           => false,
				'phase'          => 'elementor',
				'phase_progress' => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
				'message'        => sprintf(
					/* translators: 1: long remaining count */
					__( 'در حال تکمیل نهایی فیلدهای HTML سرسخت (%1$d مورد)', 'polymart-ai' ),
					$long_remaining
				),
			);
		}

		\PolymartAI\Activity_Logger::log(
			'warning',
			sprintf(
				/* translators: 1: post ID, 2: forced short count, 3: forced long count */
				__( 'Elementor — #%1$d: تکمیل اجباری — %2$d فیلد کوتاه و %3$d فیلد بلند HTML با fallback ذخیره شد و کار بسته شد.', 'polymart-ai' ),
				$post_id,
				$forced,
				$forced_long
			),
			array(
				'post_id'     => $post_id,
				'lang'        => $lang,
				'forced'      => $forced,
				'forced_long' => $forced_long,
			)
		);

		$persisted = self::persist_elementor_job_progress(
			$post_id,
			$lang,
			$source_data,
			$map,
			$progress_total,
			$progress_total,
			true,
			true
		);

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
			$state['elementor_long_gap_force_attempts'],
			$state['elementor_segment_passthrough'],
			$state['elementor_field_passthrough']
		);

		self::clear_elementor_primary_batches_lock( $post_id, $lang );
		self::clear_elementor_slice_cursor( $post_id, $lang );
		self::clear_job_partial_state( $post_id, $lang, true );
		self::flush_translation_status_cache( $post_id );

		$still_persian   = self::stored_elementor_translation_has_persian( $post_id, $lang );
		$visible_persian = self::stored_elementor_translation_has_visible_persian( $post_id, $lang );

		if ( $still_persian ) {
			$reopens = absint( $state['elementor_force_persian_reopens'] ?? 0 ) + 1;

			// After repeated reopen loops, force-accept leftover long fields and finalize.
			if ( $reopens >= 3 && ! empty( $remaining ) && count( $remaining ) <= 2 ) {
				$accepted_escape = array();

				foreach ( array_keys( $remaining ) as $skip_path ) {
					$skip_path = (string) $skip_path;
					$text      = (string) ( $remaining[ $skip_path ] ?? '' );

					if ( '' === $text ) {
						continue;
					}

					self::accept_elementor_long_field_with_partial_fallback( $skip_path, $text, $map, $state );
					$accepted_escape[] = $skip_path;
				}

				if ( ! empty( $accepted_escape ) ) {
					self::save_elementor_accepted_paths( $post_id, $lang, $accepted_escape );
					self::bind_elementor_accepted_paths_context( $post_id, $lang );
				}

				$map = self::prepare_elementor_map_for_persist( $map, $source_payload, true );

				$persisted = self::persist_elementor_job_progress(
					$post_id,
					$lang,
					$source_data,
					$map,
					$progress_total,
					$progress_total,
					true,
					true
				);

				if ( ! is_wp_error( $persisted ) ) {
					delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
					self::clear_elementor_primary_batches_lock( $post_id, $lang );
					self::clear_elementor_slice_cursor( $post_id, $lang );
					self::clear_job_partial_state( $post_id, $lang, true );
					self::flush_translation_status_cache( $post_id );
					self::reset_elementor_runtime_caches();

					$hash = self::compute_elementor_source_hash( $post_id );

					if ( '' !== $hash ) {
						update_post_meta( $post_id, self::get_elementor_source_hash_meta_key( $lang ), $hash );
					}

					update_post_meta( $post_id, self::get_elementor_finalized_meta_key( $lang ), time() );
					update_post_meta( $post_id, '_polymart_ai_translated_at_' . $lang, time() );
					update_post_meta( $post_id, '_polymart_ai_translated_at', time() );

					// Accepted FA leftovers still render on storefront — never stamp "translated".
					if ( self::stored_elementor_translation_has_visible_persian( $post_id, $lang ) ) {
						self::mark_elementor_stubborn_exhausted( $post_id, $lang );
						update_post_meta( $post_id, self::get_status_index_meta_key( $lang ), 'partial' );
					} else {
						self::clear_elementor_stubborn_exhausted( $post_id, $lang );
						update_post_meta( $post_id, self::get_status_index_meta_key( $lang ), 'translated' );
					}

					\PolymartAI\Activity_Logger::log(
						'warning',
						sprintf(
							/* translators: 1: post ID, 2: reopen count, 3: skipped field count */
							__( 'Elementor — #%1$d: پس از %2$d reopen، %3$d فیلد سرسخت accept و finalize شد.', 'polymart-ai' ),
							$post_id,
							$reopens,
							count( $accepted_escape )
						),
						array( 'post_id' => $post_id, 'lang' => $lang )
					);

					return array(
						'done'           => true,
						'phase'          => 'elementor',
						'phase_progress' => '',
						'message'        => __( 'ترجمه Elementor با accept فیلد سرسخت تکمیل شد.', 'polymart-ai' ),
					);
				}
			}

			// Do NOT stamp status=translated / done=true — queue must keep the pin
			// and gap-fill English, otherwise UI shows N/N then «ناقص ماند Elementor».
			self::invalidate_elementor_job_success_markers( $post_id, $lang );

			// Keep accepted paths after repeated reopens so the next force can finalize.
			if ( $reopens < 2 ) {
				delete_post_meta( $post_id, self::get_elementor_accepted_paths_meta_key( $lang ) );
				self::$current_elementor_accepted_paths = array();
			}

			\PolymartAI\Activity_Logger::log(
				'warning',
				sprintf(
					/* translators: 1: post ID, 2: reopen count */
					__( 'Elementor — #%1$d: force-finalize هنوز Persian در EN JSON دارد — از صف خارج نشد؛ reopen gap-fill (%2$d).', 'polymart-ai' ),
					$post_id,
					$reopens
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);

			$reopen = self::hydrate_elementor_job_partial_state( $post_id, $lang, array() );
			$reopen['elementor_gap_fill']               = true;
			$reopen['elementor_gap_fill_stubborn_only'] = true;
			$reopen['elementor_map']                    = $map;
			$reopen['elementor_force_persian_reopens']  = $reopens;

			if ( $reopens >= 2 ) {
				// Keep pressure high so the next tick force-accepts instead of resetting forever.
				$reopen['elementor_stubborn_ghost_ticks']    = max( self::ELEMENTOR_STUBBORN_GHOST_LOOP_LIMIT, 2 );
				$reopen['elementor_long_gap_force_attempts'] = max( 1, absint( $state['elementor_long_gap_force_attempts'] ?? 0 ) + 1 );
			} else {
				// Reset counters so stubborn gets fresh AI attempts before next force.
				$reopen['elementor_stubborn_ghost_ticks']    = 0;
				$reopen['elementor_long_gap_force_attempts'] = 0;
			}

			// Stale kill/timeout error blocks storefront serve and confuses recovery UI.
			delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );

			self::save_job_partial_state( $post_id, $lang, $reopen );

			return array(
				'done'           => false,
				'phase'          => 'elementor',
				'phase_progress' => self::format_elementor_job_progress_marker( $post_id, $lang, $reopen ),
				'message'        => __( 'فیلدهای Elementor هنوز فارسی‌اند — ادامه ترجمه همین مورد.', 'polymart-ai' ),
				'recoverable'    => true,
			);
		}

		update_post_meta( $post_id, '_polymart_ai_translated_at_' . $lang, time() );
		update_post_meta( $post_id, '_polymart_ai_translated_at', time() );

		if ( $visible_persian ) {
			self::mark_elementor_stubborn_exhausted( $post_id, $lang );
			update_post_meta( $post_id, self::get_status_index_meta_key( $lang ), 'partial' );
		} else {
			self::clear_elementor_stubborn_exhausted( $post_id, $lang );
			update_post_meta( $post_id, self::get_status_index_meta_key( $lang ), 'translated' );
		}

		return array(
			'done'           => true,
			'phase'          => 'elementor',
			'phase_progress' => '',
			'message'        => sprintf(
				/* translators: 1: forced field count */
				__( 'ترجمه Elementor با تکمیل اجباری ذخیره شد (%1$d فیلد با fallback).', 'polymart-ai' ),
				$forced + $forced_long
			),
		);
	}

	private static function pipeline_force_finalize_success_response( $context ) {
		// Caller must only invoke this after storefront-ready English is verified.
		// Returning done=true blindly was marking #1021 successful while EN still had FA.
		return array(
			'done'           => true,
			'phase'          => 'elementor',
			'phase_progress' => '',
			'message'        => sprintf(
				/* translators: 1: pipeline context tag */
				__( 'ترجمه Elementor با تکمیل اجباری ذخیره شد (%1$s).', 'polymart-ai' ),
				sanitize_text_field( (string) $context )
			),
		);
	}

	/**
	 * Only emit a done=true pipeline response when EN Elementor JSON is clean English.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Lang.
	 * @param string $context Tag.
	 * @return array Slice response.
	 */
	private static function pipeline_force_finalize_if_storefront_ready( $post_id, $lang, $context ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return array(
				'done'           => false,
				'phase'          => 'elementor',
				'phase_progress' => '',
				'message'        => __( 'Elementor هنوز فارسی دارد — تکمیل اجباری رد شد؛ ادامه gap-fill.', 'polymart-ai' ),
				'recoverable'    => true,
			);
		}

		// Force path may have written English JSON but left not_finalized / stale hash.
		// Seal markers when the companion is already clean so the pin can release.
		if (
			! self::stored_elementor_translation_has_persian( $post_id, $lang )
			&& is_string( self::get_stored_elementor_json( $post_id, $lang ) )
		) {
			self::seal_clean_elementor_companion( $post_id, $lang );

			if ( self::elementor_translation_is_storefront_ready( $post_id, $lang ) ) {
				return self::pipeline_force_finalize_success_response( $context );
			}
		}

		if (
			! self::stored_elementor_translation_has_persian( $post_id, $lang )
			&& self::elementor_translation_is_storefront_ready( $post_id, $lang )
		) {
			return self::pipeline_force_finalize_success_response( $context );
		}

		$blockers = self::explain_elementor_storefront_serve_blockers( $post_id, $lang, false );
		$codes    = implode( ',', array_map( 'strval', (array) ( $blockers['codes'] ?? array() ) ) );

		return array(
			'done'           => false,
			'phase'          => 'elementor',
			'phase_progress' => self::format_elementor_job_progress_marker(
				$post_id,
				$lang,
				self::get_job_partial_state( $post_id, $lang )
			),
			'message'        => sprintf(
				/* translators: 1: blocker codes */
				__( 'تکمیل اجباری Elementor رد شد (%1$s) — ادامه gap-fill.', 'polymart-ai' ),
				'' !== $codes ? $codes : 'not_ready'
			),
			'recoverable'    => true,
		);
	}

	/**
	 * Stamp finalized + source hash and clear durable partials when EN JSON is clean.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	public static function seal_clean_elementor_companion( $post_id, $lang ) {
		static $inflight = array();

		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$guard   = $post_id . ':' . $lang;

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return false;
		}

		if ( isset( $inflight[ $guard ] ) ) {
			return false;
		}

		$inflight[ $guard ] = true;

		try {
			if ( ! is_string( self::get_stored_elementor_json( $post_id, $lang ) ) ) {
				return false;
			}

			if ( self::stored_elementor_translation_has_persian( $post_id, $lang ) ) {
				return false;
			}

			self::force_claim_translation_lock( $post_id, $lang );

			// Stored EN JSON is already clean — do not re-apply segment source fallbacks
			// (that would reintroduce Persian into a good companion). Only stamp readiness.
			self::clear_job_partial_state( $post_id, $lang, true );
			self::clear_elementor_slice_cursor( $post_id, $lang );
			self::clear_elementor_primary_batches_lock( $post_id, $lang );
			delete_post_meta( $post_id, self::get_elementor_progress_meta_key( $lang ) );
			delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
			delete_post_meta( $post_id, self::get_stubborn_details_meta_key( $lang ) );
			self::save_elementor_source_hash( $post_id, $lang );
			self::mark_elementor_translation_finalized( $post_id, $lang );
			update_post_meta( $post_id, '_polymart_ai_translated_at_' . $lang, time() );
			update_post_meta( $post_id, '_polymart_ai_translated_at', time() );
			// Never force status=translated — title/meta gaps must keep the index honest.
			self::reset_elementor_runtime_caches();
			self::flush_translation_status_cache( $post_id );
			self::sync_translation_index_meta( $post_id, $lang );
			self::release_translation_lock( $post_id, $lang, true );

			if ( \PolymartAI\Activity_Logger::is_bulk_job_running() ) {
				\PolymartAI\Activity_Logger::sync_bulk_job_after_elementor_finalize( $post_id, $lang );
			}

			$lang_label = \PolymartAI\Language_Registry::get_language_label_for_ai( $lang );

			\PolymartAI\Activity_Logger::log(
				'info',
				sprintf(
					/* translators: 1: post ID, 2: language label */
					__( 'Elementor — #%1$d: companion %2$s تمیز seal شد (finalized + hash).', 'polymart-ai' ),
					$post_id,
					$lang_label
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);

			return true;
		} catch ( \Throwable $e ) {
			\PolymartAI\Activity_Logger::log(
				'error',
				sprintf(
					/* translators: 1: post ID, 2: error */
					__( 'Elementor — #%1$d: seal ناموفق (%2$s)', 'polymart-ai' ),
					$post_id,
					$e->getMessage()
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);

			return false;
		} finally {
			unset( $inflight[ $guard ] );
		}
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
		$blocker_code = sanitize_key( (string) ( $blocker['code'] ?? '' ) );

		if (
			in_array( $blocker_code, array( 'untranslated_fields', 'stored_persian' ), true )
			&& self::elementor_primary_schedule_locked( $post_id, $lang, $state )
		) {
			$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
			$remaining = self::filter_remaining_elementor_payload(
				self::collect_elementor_translation_payload( $source_data ),
				$map,
				$skipped
			);

			return self::finalize_elementor_job_slice(
				$post_id,
				$lang,
				$source_data,
				$map,
				$state,
				$done_count,
				$total_chunks,
				$remaining
			);
		}

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
				if ( self::maybe_force_finalize_elementor_tail_in_pipeline( $post_id, $lang, 'pipeline-slice-primary-done' ) ) {
					return self::pipeline_force_finalize_if_storefront_ready( $post_id, $lang, 'pipeline-slice-primary-done' );
				}

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

	private static function describe_elementor_field_translation_blocker( $path, $text, array $map, array $state = array() ) {
		$path = (string) $path;
		$text = (string) $text;
		$chars = self::utf8_strlen( $text );
		$detail = '';
		$reason = 'unknown';
		$content = self::analyze_elementor_field_content( $text );
		$api_attempts = absint( $state['elementor_failures'][ $path ] ?? 0 );
		$segment_failures = is_array( $state['elementor_segment_failures'] ?? null ) ? $state['elementor_segment_failures'] : array();
		$is_stubborn_long = self::stubborn_elementor_field_is_long( $path, $text );

		if ( $api_attempts >= self::ELEMENTOR_SEGMENT_MAX_RETRIES ) {
			$reason = 'api_retries_exhausted';
			$detail = sprintf( '%d/%d attempts', $api_attempts, self::ELEMENTOR_SEGMENT_MAX_RETRIES );
		}

		$seg_lookup = self::get_elementor_segment_source_lookup( $path, $text );
		if ( ! empty( $seg_lookup ) && 'api_retries_exhausted' !== $reason ) {
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
				} elseif ( self::elementor_rejects_translated_text( $translated ) ) {
					$bad[] = $seg_key . ':wrong_script';
				} elseif ( $translated === trim( (string) $seg_source ) ) {
					$bad[] = $seg_key . ':unchanged';
				}
			}

			if ( ! empty( $missing ) ) {
				$reason = 'missing_segments';
				$labels = array();

				foreach ( array_slice( $missing, 0, 4 ) as $seg_key ) {
					if ( preg_match( '/::__seg(\d+)$/', (string) $seg_key, $matches ) ) {
						$labels[] = '__seg' . $matches[1];
					} else {
						$labels[] = (string) $seg_key;
					}
				}

				$detail = implode( ', ', $labels );
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

			return array_merge(
				compact( 'path', 'reason', 'detail', 'chars' ),
				array(
					'content'          => $content,
					'is_stubborn_long' => $is_stubborn_long,
					'api_attempts'     => $api_attempts,
					'segment_count'    => count( $seg_lookup ),
				)
			);
		}

		if ( 'api_retries_exhausted' !== $reason ) {
			if ( ! isset( $map[ $path ] ) ) {
				$reason = 'not_in_map';
			} else {
				$translated = trim( (string) $map[ $path ] );

				if ( '' === $translated ) {
					$reason = 'empty_translation';
				} elseif ( self::elementor_rejects_translated_text( $translated ) ) {
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
		}

		if ( '' === $detail ) {
			$detail = (string) ( $content['preview'] ?? '' );
		}

		return array_merge(
			compact( 'path', 'reason', 'detail', 'chars' ),
			array(
				'content'          => $content,
				'is_stubborn_long' => $is_stubborn_long,
				'api_attempts'     => $api_attempts,
				'segment_count'    => 0,
			)
		);
	}

	private static function analyze_elementor_field_content( $text ) {
		$text = (string) $text;

		if ( '' === $text ) {
			return array(
				'preview'       => '',
				'char_count'    => 0,
				'has_shortcode' => false,
				'has_html'      => false,
				'has_embed'     => false,
				'has_script'    => false,
				'has_persian'   => false,
			);
		}

		$plain = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return array(
			'preview'       => mb_substr( wp_strip_all_tags( $plain ), 0, 180 ),
			'char_count'    => self::utf8_strlen( $text ),
			'has_shortcode' => (bool) preg_match( '/\[[\w-]+/', $plain ),
			'has_html'      => (bool) preg_match( '/<(?:div|p|span|section|table|ul|ol|h[1-6]|a|img)\b/i', $plain ),
			'has_embed'     => (bool) preg_match( '/<(?:iframe|embed|object|video|audio)\b/i', $plain ),
			'has_script'    => false !== stripos( $plain, '<script' ) || false !== stripos( $plain, 'javascript:' ),
			'has_persian'   => Persian_Detector::contains_persian( $plain ),
		);
	}

	private static function get_stubborn_details_meta_key( $lang ) {
		return '_polymart_ai_stubborn_details_' . sanitize_key( (string) $lang );
	}

	public static function get_elementor_stubborn_field_diagnostics( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return array();
		}

		$raw = get_post_meta( $post_id, self::get_stubborn_details_meta_key( $lang ), true );

		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );

			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		if ( is_array( $raw ) ) {
			return $raw;
		}

		return array();
	}

	public static function clear_elementor_stubborn_field_diagnostics( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return;
		}

		delete_post_meta( $post_id, self::get_stubborn_details_meta_key( $lang ) );
	}

	private static function build_elementor_stubborn_field_report( $post_id, $lang, array $source_payload, array $map, array $skipped, array $state, $context ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$fields  = array();

		$remaining = self::filter_remaining_elementor_payload( $source_payload, $map, $skipped );

		foreach ( $remaining as $path => $text ) {
			if ( count( $fields ) >= 12 ) {
				break;
			}

			$blocker = self::describe_elementor_field_translation_blocker( $path, $text, $map, $state );
			$content = is_array( $blocker['content'] ?? null ) ? $blocker['content'] : array();

			$fields[] = array(
				'path'             => (string) ( $blocker['path'] ?? $path ),
				'reason'           => (string) ( $blocker['reason'] ?? 'unknown' ),
				'reason_label'     => self::format_stubborn_reason_label( (string) ( $blocker['reason'] ?? 'unknown' ) ),
				'detail'           => (string) ( $blocker['detail'] ?? '' ),
				'chars'            => (int) ( $blocker['chars'] ?? 0 ),
				'is_stubborn_long' => ! empty( $blocker['is_stubborn_long'] ),
				'api_attempts'     => (int) ( $blocker['api_attempts'] ?? 0 ),
				'segment_count'    => (int) ( $blocker['segment_count'] ?? 0 ),
				'content'          => $content,
			);
		}

		return array(
			'post_id'        => $post_id,
			'lang'           => $lang,
			'context'        => sanitize_text_field( (string) $context ),
			'updated_at'     => time(),
			'remaining'      => count( $remaining ),
			'fields'         => $fields,
			'ghost_ticks'    => absint( $state['elementor_stubborn_ghost_ticks'] ?? 0 ),
			'gap_fill_mode'  => ! empty( $state['elementor_gap_fill'] ),
			'stubborn_only'  => ! empty( $state['elementor_gap_fill_stubborn_only'] ),
		);
	}

	private static function format_stubborn_reason_label( $reason ) {
		$labels = array(
			'missing_segments'        => __( 'سگمنت‌های ترجمه ناقص', 'polymart-ai' ),
			'invalid_segments'        => __( 'پاسخ AI برای سگمنت نامعتبر', 'polymart-ai' ),
			'collapsed_base_missing'    => __( 'فیلد کامل یا همه سگمنت‌ها لازم است', 'polymart-ai' ),
			'logic_mismatch'          => __( 'ناسازگاری منطق تشخیص تکمیل', 'polymart-ai' ),
			'not_in_map'              => __( 'به AI ارسال نشده یا در map نیست', 'polymart-ai' ),
			'empty_translation'       => __( 'AI پاسخ خالی داد', 'polymart-ai' ),
			'persian_translation'     => __( 'AI همان فارسی را برگرداند / اسکریپت نامعتبر برای زبان هدف', 'polymart-ai' ),
			'unchanged_from_source'   => __( 'ترجمه بدون تغییر از منبع', 'polymart-ai' ),
			'long_field_parts_incomplete' => __( 'بخش‌های فیلد بلند ناقص', 'polymart-ai' ),
			'invalid_translation'     => __( 'ترجمه نامعتبر', 'polymart-ai' ),
			'api_retries_exhausted'   => __( 'تلاش‌های API تمام شد', 'polymart-ai' ),
		);

		return $labels[ $reason ] ?? $reason;
	}

	private static function format_stubborn_content_flags_label( array $content ) {
		$flags = array();

		if ( ! empty( $content['has_shortcode'] ) ) {
			$flags[] = __( 'شورت‌کد', 'polymart-ai' );
		}
		if ( ! empty( $content['has_html'] ) ) {
			$flags[] = __( 'HTML', 'polymart-ai' );
		}
		if ( ! empty( $content['has_embed'] ) ) {
			$flags[] = __( 'امبد/iframe', 'polymart-ai' );
		}
		if ( ! empty( $content['has_script'] ) ) {
			$flags[] = __( 'اسکریپت', 'polymart-ai' );
		}

		return empty( $flags ) ? __( 'متن ساده', 'polymart-ai' ) : implode( ' + ', $flags );
	}

	private static function persist_elementor_stubborn_field_diagnostics( array $report ) {
		$post_id = absint( $report['post_id'] ?? 0 );
		$lang    = sanitize_key( (string) ( $report['lang'] ?? '' ) );

		if ( $post_id <= 0 || '' === $lang || empty( $report['fields'] ) ) {
			return;
		}

		$json = wp_json_encode( $report );

		if ( false === $json || '' === $json ) {
			return;
		}

		update_post_meta( $post_id, self::get_stubborn_details_meta_key( $lang ), $json );
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

	private static function log_elementor_remaining_field_diagnostics( $post_id, $lang, array $source_payload, array $map, array $skipped, $context, array $state = array() ) {
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

		$report = self::build_elementor_stubborn_field_report(
			$post_id,
			$lang,
			$source_payload,
			$map,
			$skipped,
			$state,
			$context
		);

		self::persist_elementor_stubborn_field_diagnostics( $report );

		$compact = array();

		foreach ( $report['fields'] as $field ) {
			$compact[] = array(
				'path'   => (string) ( $field['path'] ?? '' ),
				'reason' => (string) ( $field['reason'] ?? '' ),
				'detail' => (string) ( $field['detail'] ?? '' ),
			);
		}

		$json_line = wp_json_encode( $compact );

		if ( false !== $json_line && '' !== $json_line ) {
			\PolymartAI\Activity_Logger::log(
				'warning',
				sprintf(
					/* translators: 1: post ID, 2: JSON field diagnostics */
					__( 'Stubborn JSON — #%1$d: %2$s', 'polymart-ai' ),
					$post_id,
					$json_line
				),
				array(
					'post_id' => $post_id,
					'lang'    => $lang,
					'context' => $context,
				)
			);
		}

		foreach ( $report['fields'] as $field ) {
			$content = is_array( $field['content'] ?? null ) ? $field['content'] : array();
			$flags   = self::format_stubborn_content_flags_label( $content );
			$preview = (string) ( $content['preview'] ?? '' );

			if ( '' !== $preview ) {
				$preview = mb_substr( $preview, 0, 120 );
			}

			\PolymartAI\Activity_Logger::log(
				'warning',
				sprintf(
					/* translators: 1: post ID, 2: field path, 3: reason label, 4: detail, 5: content flags, 6: preview */
					__( 'Stubborn — #%1$d: %2$s — %3$s (%4$s) · %5$s · «%6$s»', 'polymart-ai' ),
					$post_id,
					(string) ( $field['path'] ?? '' ),
					(string) ( $field['reason_label'] ?? ( $field['reason'] ?? 'unknown' ) ),
					(string) ( $field['detail'] ?? '' ),
					$flags,
					$preview
				),
				array(
					'post_id' => $post_id,
					'lang'    => $lang,
					'path'    => (string) ( $field['path'] ?? '' ),
					'reason'  => (string) ( $field['reason'] ?? '' ),
					'context' => $context,
				)
			);
		}

		$extra = (int) ( $report['remaining'] ?? count( $remaining ) ) > count( $report['fields'] )
			? sprintf( ' +%d more', (int) $report['remaining'] - count( $report['fields'] ) )
			: '';

		\PolymartAI\Activity_Logger::log(
			'warning',
			sprintf(
				/* translators: 1: post ID, 2: remaining count, 3: context, 4: meta key hint */
				__( 'Elementor — #%1$d: %2$d فیلد stubborn — جزئیات در %3$s و متا %4$s', 'polymart-ai' ),
				$post_id,
				(int) ( $report['remaining'] ?? count( $remaining ) ),
				$context,
				self::get_stubborn_details_meta_key( $lang ) . $extra
			),
			array(
				'post_id'   => $post_id,
				'lang'      => $lang,
				'remaining' => array_keys( $remaining ),
				'context'   => $context,
				'meta_key'  => self::get_stubborn_details_meta_key( $lang ),
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
		$api_budget  = self::resolve_elementor_stubborn_api_budget( $max_fields );
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

		// On AS ticks the budget is often 1. Prefer short not_in_map labels first so
		// long __segN widgets cannot starve button/title/search strings forever.
		$short_target = 0;

		if ( ! empty( $short ) ) {
			$short_target = empty( $long ) ? $api_budget : min( 2, max( 1, (int) ceil( $api_budget / 2 ) ) );
		}

		foreach ( array_slice( $short, 0, 4, true ) as $path => $text ) {
			if ( $spent >= $short_target ) {
				break;
			}

			if ( isset( $persist_map[ $path ] ) && self::elementor_field_translation_complete( $path, $text, $persist_map ) ) {
				$map[ $path ] = (string) $persist_map[ $path ];
				unset( $short[ $path ] );
				++$done;
				continue;
			}

			if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
				unset( $short[ $path ] );
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
			unset( $short[ $path ] );

			if ( ! empty( $progress['touched'] ) ) {
				$touched = true;
			}

			if ( ! empty( $progress['completed'] ) ) {
				++$done;
			}
		}

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
					),
					$state
				);
			}
		}

		// Leftover AS budget → more short fields.
		foreach ( array_slice( $short, 0, 4, true ) as $path => $text ) {
			if ( $spent >= $api_budget ) {
				break;
			}

			if ( isset( $persist_map[ $path ] ) && self::elementor_field_translation_complete( $path, $text, $persist_map ) ) {
				$map[ $path ] = (string) $persist_map[ $path ];
				unset( $short[ $path ] );
				++$done;
				continue;
			}

			if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
				unset( $short[ $path ] );
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
			unset( $short[ $path ] );

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

				if ( absint( $seg_failures[ $batch_path ] ?? 0 ) >= self::ELEMENTOR_SEGMENT_MAX_RETRIES ) {
					self::apply_elementor_segment_source_fallback( $batch_path, $batch_text, $map, $state );
					$touched = true;
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

		// On Action Scheduler ticks we only try once per HTTP call, but must NOT
		// source-fallback missing segs after that single miss — next tick retries.
		$http_attempts = \PolymartAI\Activity_Logger::is_trusted_as_tick()
			? 1
			: self::ELEMENTOR_SEGMENT_MAX_RETRIES;
		$request_timeout = self::resolve_elementor_stubborn_request_timeout( $pending );

		for ( $attempt = 1; $attempt <= $http_attempts && ! empty( $pending ); $attempt++ ) {
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
						$http_attempts
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
				array( 'max_timeout' => $request_timeout )
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

				if ( '' === $translated || self::elementor_rejects_translated_text( $translated ) ) {
					\PolymartAI\Activity_Logger::log(
						'warning',
						sprintf(
							/* translators: 1: post ID, 2: map path, 3: reason */
							__( 'Elementor — #%1$d: stubborn رد شد — %2$s (%3$s)', 'polymart-ai' ),
							absint( $post_id ),
							$map_path,
							'' === $translated ? 'empty' : 'wrong_script'
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
				if ( preg_match( '/^(.+)::__seg\d+$/', $map_path, $matches ) ) {
					$base_text      = (string) ( $source_payload[ (string) $matches[1] ] ?? $text );
					$seg_lookup     = self::get_elementor_segment_source_lookup( (string) $matches[1], $base_text );
					$source_snippet = (string) ( $pending[ $map_path ] ?? $batch[ $map_path ] ?? $seg_lookup[ $map_path ] ?? $source_snippet );
				} elseif ( preg_match( '/^(.+)::__part\d+$/', $map_path, $matches ) ) {
					$source_snippet = (string) ( $pending[ $map_path ] ?? $batch[ $map_path ] ?? $source_payload[ (string) $matches[1] ] ?? $text );
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

		// Only exhaustively source-fallback after the hard segment retry ceiling.
		// Never equate "1 failed HTTP on this AS tick" with "give up on English".
		foreach ( $pending as $pending_key => $pending_text ) {
			$pending_key  = (string) $pending_key;
			$pending_text = (string) $pending_text;

			if ( ! self::elementor_path_is_segment( $pending_key ) ) {
				continue;
			}

			if ( absint( $seg_failures[ $pending_key ] ?? 0 ) >= self::ELEMENTOR_SEGMENT_MAX_RETRIES ) {
				self::apply_elementor_segment_source_fallback( $pending_key, $pending_text, $map, $state );
				$touched = true;
				unset( $pending[ $pending_key ], $seg_failures[ $pending_key ] );
			}
		}

		$state['elementor_segment_failures'] = $seg_failures;

		$map                    = self::expand_elementor_map_mirrors( $map, $text_mirrors );
		$map                    = self::merge_elementor_path_map( $post_id, $lang, $source_data, $map );
		$map                    = self::sanitize_elementor_translation_map( $map, $source_payload );
		// Keep partial English __segN; allow assemble to fill only unresolved gaps later.
		$map                    = self::prepare_elementor_map_for_persist( $map, $source_payload, true );
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

	/**
	 * Force-save partial Elementor progress during kick/recovery (no chunk-queue rebuild).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @param string $context Recovery context tag.
	 * @return bool True when finalize ran.
	 */
	public static function force_finalize_elementor_job_from_recovery( $post_id, $lang, $context = 'kick-recovery' ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$context = sanitize_text_field( (string) $context );

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return false;
		}

		// Clean EN companion stuck on not_finalized / stale hash — only when map is nearly empty.
		// Sealing every "clean" companion (even with many remaining map paths) caused ensure/start
		// timeouts on huge Elementor posts when persian-scan + remaining-count ran back-to-back.
		$remaining_count = self::count_elementor_remaining_fields( $post_id, $lang );

		if ( $remaining_count <= 1
			&& ! self::stored_elementor_translation_has_persian( $post_id, $lang )
			&& is_string( self::get_stored_elementor_json( $post_id, $lang ) )
		) {
			if ( self::seal_clean_elementor_companion( $post_id, $lang ) ) {
				return true;
			}
		}

		if ( $remaining_count <= 0 ) {
			return false;
		}

		// Bulk-pin / kick recovery runs on a different PHP worker than the stuck AS tick.
		// Steal the lock before any emergency write so persist cannot soft-reject.
		self::force_claim_translation_lock( $post_id, $lang );

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return false;
		}

		$source_data = json_decode( $raw, true );

		if ( ! is_array( $source_data ) ) {
			return false;
		}

		self::bind_elementor_accepted_paths_context( $post_id, $lang );

		$state          = self::hydrate_elementor_job_partial_state(
			$post_id,
			$lang,
			self::get_job_partial_state( $post_id, $lang )
		);
		$source_payload = self::collect_elementor_translation_payload( $source_data );
		$map            = self::merge_elementor_job_path_map(
			$post_id,
			$lang,
			$source_data,
			is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array()
		);
		$text_mirrors   = self::get_elementor_text_mirror_paths( $source_payload );
		$map            = self::expand_elementor_map_mirrors( $map, $text_mirrors );
		$chunk_progress = self::get_elementor_chunk_progress( $post_id, $lang, $state );
		$progress_total = max( 1, (int) ( $chunk_progress['total'] ?? 1 ) );

		self::bind_elementor_segment_passthrough_context( $state );

		$remaining = self::count_elementor_remaining_fields( $post_id, $lang );

		\PolymartAI\Activity_Logger::log(
			'warning',
			sprintf(
				/* translators: 1: post ID, 2: remaining fields, 3: context */
				__( 'Elementor — #%1$d: تکمیل اجباری از بازیابی — %2$d فیلد stubborn باقی (%3$s)', 'polymart-ai' ),
				$post_id,
				$remaining,
				$context
			),
			array( 'post_id' => $post_id, 'lang' => $lang, 'context' => $context )
		);

		$result = self::force_finalize_elementor_job_with_fallback(
			$post_id,
			$lang,
			$source_data,
			$state,
			$map,
			$source_payload,
			$text_mirrors,
			$progress_total
		);

		return ! is_wp_error( $result ) && is_array( $result ) && ! empty( $result['done'] );
	}

	private static function resolve_elementor_stubborn_api_budget( $max_fields ) {
		if ( \PolymartAI\Activity_Logger::is_trusted_as_tick() ) {
			return 1;
		}

		if ( wp_doing_cron() ) {
			return 2;
		}

		return max( 2, min( 8, absint( $max_fields ) * 2 ) );
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
