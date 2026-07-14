<?php
/**
 * Post_Translator Elementor Job_Runner (auto-split).
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

trait Trait_Job_Runner {

	private static function process_elementor_job_slice( $post_id, $lang, array &$state, $api_key, $api_endpoint, $ai_model ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$state   = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
		self::bind_elementor_accepted_paths_context( $post_id, $lang );
		self::repair_completed_elementor_job_meta( $post_id, $lang );

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$data_early = json_decode( $raw, true );

			if ( is_array( $data_early ) ) {
				$source_payload_early = self::collect_elementor_translation_payload( $data_early );
				$state['elementor_map'] = self::sanitize_elementor_translation_map(
					self::merge_elementor_path_map(
						$post_id,
						$lang,
						$data_early,
						is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array()
					),
					$source_payload_early
				);
			}
		}

		if ( ! self::elementor_primary_schedule_locked( $post_id, $lang, $state ) ) {
			self::unblock_elementor_chunk_queue( $post_id, $lang );
			$state = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
		}

		if ( \PolymartAI\Activity_Logger::is_bulk_job_running() && \PolymartAI\Activity_Logger::is_job_api_cooldown_active() ) {
			return self::make_recoverable_partial_slice_response(
				'elementor',
				self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
				\PolymartAI\Activity_Logger::format_api_cooldown_message(
					\PolymartAI\Activity_Logger::get_job_api_cooldown_remaining()
				)
			);
		}

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

		$map = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$map = self::merge_elementor_path_map( $post_id, $lang, $data, $map );

		$payload = self::filter_remaining_elementor_payload(
			self::collect_elementor_translation_payload( $data ),
			$map,
			is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array()
		);
		$source_payload = self::collect_elementor_translation_payload( $data );
		$map            = self::sanitize_elementor_translation_map( $map, $source_payload );
		$text_mirrors   = self::get_elementor_text_mirror_paths( $source_payload );
		$map            = self::expand_elementor_map_mirrors( $map, $text_mirrors );
		$state['elementor_map'] = $map;

		$primary_exhausted = self::elementor_primary_schedule_locked( $post_id, $lang, $state );
		$chunks            = array();
		$slice_cursor      = self::read_elementor_slice_cursor( $post_id, $lang );
		$progress_total    = max( 1, self::read_elementor_slice_cursor_total( $post_id, $lang ), 1 );

		if ( $primary_exhausted ) {
			if ( self::read_elementor_slice_cursor_total( $post_id, $lang ) > 0 ) {
				$progress_total = self::read_elementor_slice_cursor_total( $post_id, $lang );
				self::write_elementor_slice_cursor( $post_id, $lang, $progress_total, $progress_total );
				$slice_cursor = $progress_total;
			}
		} else {
			$chunk_queue                     = self::build_elementor_job_chunk_queue( $post_id, $lang, $data, $state );
			$chunks                          = $chunk_queue['pending'];
			$slice_cursor                    = $chunk_queue['cursor'];
			$progress_total                  = max( $chunk_queue['total'], 1 );
			$state['elementor_chunks_total'] = $progress_total;
		}

		if ( $primary_exhausted && ! empty( $payload ) ) {
			$map     = self::merge_elementor_path_map( $post_id, $lang, $data, $map );
			$map     = self::sanitize_elementor_translation_map( $map, $source_payload );
			$skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
			$gap_chunks    = self::build_elementor_gap_fill_chunks( $data, $map, $skipped );
			$remaining_all = self::filter_remaining_elementor_payload( $source_payload, $map, $skipped );
			$stubborn_only_pending = count( $remaining_all ) - count(
				self::filter_elementor_gap_fill_batch_remaining( $remaining_all )
			);
			$enter_gap_fill = ! empty( $gap_chunks )
				|| $stubborn_only_pending > 0
				|| ! empty( $state['elementor_gap_fill'] );

			if ( $enter_gap_fill && ! empty( $remaining_all ) ) {
				$chunks                 = ! empty( $gap_chunks ) ? $gap_chunks : array();
				$state['elementor_map'] = $map;
				$was_gap_fill           = ! empty( $state['elementor_gap_fill'] );
				$stored_total           = self::read_elementor_slice_cursor_total( $post_id, $lang );
				$stored_cursor          = self::read_elementor_slice_cursor( $post_id, $lang );

				if ( $stored_total > 0 ) {
					$progress_total                          = $stored_total;
					$state['elementor_chunks_total']         = $stored_total;
					$state['elementor_primary_batches_done'] = $stored_total;
					$slice_cursor                            = min( $stored_total, max( $stored_cursor, $stored_total ) );
					self::write_elementor_slice_cursor( $post_id, $lang, $slice_cursor, $stored_total );
				} else {
					$state['elementor_primary_batches_done'] = $progress_total;
					$slice_cursor                          = $progress_total;
				}

				if ( ! $was_gap_fill ) {
					$resuming_gap_fill = $stored_total > 0 && $stored_cursor >= $stored_total;

					if ( $resuming_gap_fill ) {
						self::resolve_elementor_gap_fill_totals( $state, $gap_chunks );
					} else {
						$state['elementor_gap_fill_done']           = 0;
						$state['elementor_gap_fill_finished_keys']  = array();
						$state['elementor_gap_fill_scheduled_keys'] = array();
						self::schedule_elementor_gap_fill_chunks( $state, $gap_chunks );

						$remaining_count = count(
							self::filter_remaining_elementor_payload( $source_payload, $map, $skipped )
						);
						$stubborn_count  = count(
							array_filter(
								self::filter_remaining_elementor_payload( $source_payload, $map, $skipped ),
								static function ( $text, $path ) {
									return self::stubborn_elementor_field_is_long( (string) $path, (string) $text );
								},
								ARRAY_FILTER_USE_BOTH
							)
						);

						\PolymartAI\Activity_Logger::log(
							'info',
							sprintf(
								/* translators: 1: post ID, 2: remaining fields, 3: gap batches, 4: stubborn field count */
								__( 'Elementor — #%1$d: تکمیل نهایی — %2$d فیلد (%3$d بخش کوتاه + %4$d بلند/stubborn)', 'polymart-ai' ),
								$post_id,
								$remaining_count,
								count( $gap_chunks ),
								$stubborn_count
							),
							array( 'post_id' => $post_id, 'lang' => $lang )
						);

						self::log_elementor_remaining_field_diagnostics(
							$post_id,
							$lang,
							$source_payload,
							$map,
							$skipped,
							'gap-fill-start'
						);
					}
				} else {
					self::resolve_elementor_gap_fill_totals( $state, $gap_chunks );
				}

				$state['elementor_gap_fill'] = true;

				$stubborn_remaining = $stubborn_only_pending;

				if ( empty( $gap_chunks ) && $stubborn_remaining > 0 ) {
					$chunks                                    = array();
					$state['elementor_gap_fill_stubborn_only'] = true;
					$state['elementor_gap_fill_total']         = max(
						absint( $state['elementor_gap_fill_done'] ?? 0 ),
						absint( $state['elementor_gap_fill_total'] ?? 0 )
					);
				} elseif ( self::elementor_gap_fill_remaining_is_long_tail_only( $source_payload, $map, $skipped ) ) {
					$chunks                                  = array();
					$state['elementor_gap_fill_stubborn_only'] = true;
				} else {
					unset( $state['elementor_gap_fill_stubborn_only'] );
				}

				self::save_job_partial_state( $post_id, $lang, $state );
			}
		}

		if ( empty( $chunks ) ) {
			$in_handoff = self::elementor_in_gap_fill_handoff( $post_id, $lang, $state );

			if ( ! $in_handoff && ! empty( $payload ) ) {
				self::repair_stale_elementor_job_state( $post_id, $lang );
				$state       = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
				$chunk_queue = self::build_elementor_job_chunk_queue( $post_id, $lang, $data, $state );
				$chunks       = $chunk_queue['pending'];
				$slice_cursor = $chunk_queue['cursor'];
				$progress_total                  = max( $chunk_queue['total'], 1 );
				$state['elementor_chunks_total'] = $progress_total;
			}

			if ( empty( $chunks ) && $in_handoff ) {
				$progress_total = max(
					absint( $state['elementor_primary_batches_done'] ?? 0 ),
					self::read_elementor_slice_cursor_total( $post_id, $lang ),
					absint( $state['elementor_chunks_total'] ?? 0 ),
					absint( $progress_total ),
					1
				);

				return self::run_elementor_stubborn_handoff_tick(
					$post_id,
					$lang,
					$data,
					$state,
					$map,
					$source_payload,
					$text_mirrors,
					$api_key,
					$api_endpoint,
					$ai_model,
					$progress_total
				);
			}

			if ( empty( $chunks ) && ! $in_handoff ) {
				$primary_locked = self::elementor_primary_schedule_locked( $post_id, $lang, $state );
				$skipped_guard  = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();

				if ( $primary_locked ) {
					$progress_total = max(
						absint( $state['elementor_primary_batches_done'] ?? 0 ),
						self::read_elementor_slice_cursor_total( $post_id, $lang ),
						absint( $state['elementor_chunks_total'] ?? 0 ),
						absint( $progress_total ),
						1
					);

					if ( self::elementor_job_map_has_no_remaining( $source_payload, $map, $skipped_guard ) ) {
						return self::finalize_elementor_stubborn_handoff_success(
							$post_id,
							$lang,
							$data,
							$state,
							$map,
							$source_payload,
							$text_mirrors,
							$progress_total
						);
					}

					return self::run_elementor_stubborn_handoff_tick(
						$post_id,
						$lang,
						$data,
						$state,
						$map,
						$source_payload,
						$text_mirrors,
						$api_key,
						$api_endpoint,
						$ai_model,
						$progress_total
					);
				}

				if ( ! self::elementor_job_slice_is_truly_complete( $post_id, $lang, $state ) ) {
					\PolymartAI\Activity_Logger::log(
						'warning',
						sprintf(
							/* translators: 1: post ID, 2: progress marker */
							__( 'Elementor — #%1$d: صف بخش‌ها خالی است ولی ترجمه کامل نشده (%2$s) — بازسازی وضعیت…', 'polymart-ai' ),
							$post_id,
							self::format_elementor_job_progress_marker( $post_id, $lang, $state )
						),
						array( 'post_id' => $post_id, 'lang' => $lang )
					);

					// Never wipe durable segment progress — only clear stale base-path skip markers.
					$skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
					$state['elementor_skipped'] = array_values(
						array_filter(
							array_map( 'strval', $skipped ),
							static function ( $path ) {
								return self::elementor_path_is_segment( $path );
							}
						)
					);
					$state                      = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );

					if ( self::elementor_primary_schedule_locked( $post_id, $lang, $state ) ) {
						$progress_total = max(
							absint( $state['elementor_primary_batches_done'] ?? 0 ),
							self::read_elementor_slice_cursor_total( $post_id, $lang ),
							absint( $state['elementor_chunks_total'] ?? 0 ),
							absint( $progress_total ),
							1
						);

						if ( self::elementor_job_map_has_no_remaining( $source_payload, $map, $skipped ) ) {
							return self::finalize_elementor_stubborn_handoff_success(
								$post_id,
								$lang,
								$data,
								$state,
								$map,
								$source_payload,
								$text_mirrors,
								$progress_total
							);
						}

						return self::run_elementor_stubborn_handoff_tick(
							$post_id,
							$lang,
							$data,
							$state,
							$map,
							$source_payload,
							$text_mirrors,
							$api_key,
							$api_endpoint,
							$ai_model,
							$progress_total
						);
					}

					$chunk_queue                = self::build_elementor_job_chunk_queue( $post_id, $lang, $data, $state );
					$chunks                     = $chunk_queue['pending'];
					$slice_cursor               = $chunk_queue['cursor'];
					$progress_total             = max( $chunk_queue['total'], absint( $state['elementor_chunks_total'] ?? 0 ), 1 );
					$state['elementor_chunks_total'] = $progress_total;
				}

				if ( empty( $chunks ) ) {
					$done_count_pre      = self::resolve_elementor_done_count(
						$state,
						self::get_elementor_chunk_progress( $post_id, $lang, $state )['done'],
						$post_id,
						$lang
					);
					$api_slices_complete = $done_count_pre >= $progress_total;

					if ( $api_slices_complete ) {
						// All API batches done — fall through to finalization below.
						$chunks = array();
					} elseif (
						! self::elementor_job_slice_is_truly_complete( $post_id, $lang, $state )
						&& ! self::elementor_primary_schedule_locked( $post_id, $lang, $state )
					) {
						self::unblock_elementor_chunk_queue( $post_id, $lang );
						$state       = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
						$chunk_queue = self::build_elementor_job_chunk_queue( $post_id, $lang, $data, $state );
						$chunks      = $chunk_queue['pending'];

						if ( ! empty( $chunks ) ) {
							$slice_cursor   = $chunk_queue['cursor'];
							$progress_total = max( $chunk_queue['total'], absint( $state['elementor_chunks_total'] ?? 0 ), 1 );
							$state['elementor_chunks_total'] = $progress_total;
							// Fall through to the API batch loop below.
						} else {
							self::save_job_partial_state( $post_id, $lang, $state );

							return array(
								'done'           => false,
								'phase'          => 'elementor',
								'phase_progress' => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
								'message'        => self::format_elementor_job_slice_status_message(
									$post_id,
									$lang,
									array_merge( $state, array( 'elementor_map' => $map ) )
								),
								'recoverable'    => true,
							);
						}
					} else {
						$done_count_pre = self::resolve_elementor_done_count(
							$state,
							self::get_elementor_chunk_progress( $post_id, $lang, $state )['done'],
							$post_id,
							$lang
						);
						$blocker = self::get_elementor_job_finalize_blockers( $post_id, $lang, $data, $map, $state );

						if ( null !== $blocker ) {
							return self::respond_elementor_blocked_finalize(
								$post_id,
								$lang,
								$data,
								$map,
								$state,
								max( $done_count_pre, $progress_total ),
								$progress_total,
								$blocker
							);
						}

						$persisted = self::persist_elementor_job_progress(
							$post_id,
							$lang,
							$data,
							$map,
							max( $done_count_pre, $progress_total ),
							$progress_total,
							true
						);

						if ( is_wp_error( $persisted ) ) {
							return $persisted;
						}

						self::clear_job_partial_state( $post_id, $lang, true );

						return array(
							'done'           => true,
							'phase'          => 'elementor',
							'phase_progress' => '',
							'message'        => __( 'ترجمه Elementor تکمیل شد.', 'polymart-ai' ),
						);
					}
				}
			}
		}

		if ( empty( $chunks ) && ! self::elementor_in_gap_fill_handoff( $post_id, $lang, $state ) ) {
			$chunk_progress  = self::get_elementor_chunk_progress( $post_id, $lang, array_merge( $state, array( 'elementor_map' => $map ) ) );
			$progress_total  = max( $progress_total, (int) $chunk_progress['total'], 1 );
			$done_count      = self::resolve_elementor_done_count( $state, $chunk_progress['done'], $post_id, $lang );
			$skipped_list    = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
			$remaining       = self::filter_remaining_elementor_payload(
				self::collect_elementor_translation_payload( $data ),
				$map,
				$skipped_list
			);
			$truly_complete  = self::elementor_job_slice_is_truly_complete( $post_id, $lang, $state );
			$api_schedule_complete = self::elementor_job_api_schedule_complete( $post_id, $lang, $done_count, $progress_total );

			if ( ! $truly_complete && $api_schedule_complete ) {
				return self::finalize_elementor_job_slice( $post_id, $lang, $data, $map, $state, $done_count, $progress_total, $remaining );
			}

			if ( ! $truly_complete ) {
				if ( ! empty( $remaining ) && $done_count >= $progress_total ) {
					\PolymartAI\Activity_Logger::log(
						'info',
						sprintf(
							/* translators: 1: post ID, 2: completed batches, 3: total batches */
							__( 'Elementor — #%1$d: همه %2$d از %3$d بخش API انجام شد — در حال نهایی‌سازی…', 'polymart-ai' ),
							$post_id,
							$done_count,
							$progress_total
						),
						array( 'post_id' => $post_id, 'lang' => $lang )
					);
				}

				$persisted = self::persist_elementor_job_progress( $post_id, $lang, $data, $map, $done_count, $progress_total );

				if ( is_wp_error( $persisted ) ) {
					return $persisted;
				}

				$state['elementor_map']              = $map;
				$state['elementor_chunk_index']      = $done_count;
				$state['elementor_slices_completed'] = $done_count;
				$state['elementor_chunks_total']     = $progress_total;
				self::save_job_partial_state( $post_id, $lang, $state );

				return array(
					'done'           => false,
					'phase'          => 'elementor',
					'phase_progress' => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
					'message'        => self::format_elementor_job_slice_status_message( $post_id, $lang, $state ),
				);
			}

			$blocker = self::get_elementor_job_finalize_blockers( $post_id, $lang, $data, $map, $state );

			if ( null !== $blocker ) {
				return self::respond_elementor_blocked_finalize( $post_id, $lang, $data, $map, $state, $done_count, $progress_total, $blocker );
			}

			$persisted = self::persist_elementor_job_progress( $post_id, $lang, $data, $map, $done_count, $progress_total, true );

			if ( is_wp_error( $persisted ) ) {
				return $persisted;
			}

			self::clear_elementor_slice_cursor( $post_id, $lang );
			self::clear_job_partial_state( $post_id, $lang, true );

			return array(
				'done'           => true,
				'phase'          => 'elementor',
				'phase_progress' => '',
				'message'        => __( 'ترجمه Elementor تکمیل شد.', 'polymart-ai' ),
			);
		}

		$source_total    = max( 1, absint( $state['elementor_chunks_total'] ?? 0 ) );
		$budget          = self::get_job_step_max_elementor_chunks();
		$ai_options      = array(
			'min_timeout' => self::ELEMENTOR_JOB_REQUEST_TIMEOUT,
			'max_timeout' => self::ELEMENTOR_JOB_REQUEST_TIMEOUT,
		);
		$processed       = 0;
		$failures        = is_array( $state['elementor_failures'] ?? null ) ? $state['elementor_failures'] : array();
		$skipped_list    = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$chunk_progress  = self::get_elementor_chunk_progress( $post_id, $lang, $state );
		$gap_fill_mode   = ! empty( $state['elementor_gap_fill'] );
		$gap_fill_done   = absint( $state['elementor_gap_fill_done'] ?? 0 );
		$gap_fill_total  = absint( $state['elementor_gap_fill_total'] ?? 0 );

		if ( $gap_fill_mode ) {
			$progress_total = max(
				$progress_total,
				absint( $state['elementor_primary_batches_done'] ?? 0 ),
				self::read_elementor_slice_cursor_total( $post_id, $lang )
			);
			$slice_cursor = min( $slice_cursor, $progress_total );
			$ai_options   = array(
				'min_timeout' => self::ELEMENTOR_JOB_REQUEST_TIMEOUT,
				'max_timeout' => self::ELEMENTOR_JOB_REQUEST_TIMEOUT_MAX,
			);
			$budget       = min( $budget, 3 );

			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( max( 120, $budget * 50 ) );
			}

			$gap_schedule    = self::resolve_elementor_gap_fill_totals( $state, $chunks );
			$gap_fill_done   = (int) $gap_schedule['done'];
			$gap_fill_total  = (int) $gap_schedule['total'];

			if ( empty( $chunks ) && ! empty( $state['elementor_gap_fill_stubborn_only'] ) ) {
				$state['elementor_gap_fill_stubborn_only'] = true;
			} elseif ( empty( $chunks ) && self::elementor_gap_fill_remaining_is_long_tail_only( $source_payload, $map, $skipped_list ) ) {
				$state['elementor_gap_fill_stubborn_only'] = true;
			}
		}

		while ( $processed < $budget && ! empty( $chunks ) ) {
			$chunk = array_shift( $chunks );
			$first_key = (string) array_key_first( $chunk );

			if ( '' !== $first_key && absint( $failures[ $first_key ] ?? 0 ) >= self::ELEMENTOR_SEGMENT_MAX_RETRIES ) {
				if ( ! self::elementor_path_is_segment( $first_key ) ) {
					$skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
					$skipped[] = $first_key;
					$state['elementor_skipped'] = array_values( array_unique( array_map( 'strval', $skipped ) ) );
				}

				unset( $failures[ $first_key ] );
				++$processed;
				$state['elementor_map'] = $map;
				continue;
			}

			if ( self::elementor_chunk_paths_translated( $chunk, $map, $source_payload ) ) {
				++$processed;
				$state['elementor_map'] = $map;

				if ( $gap_fill_mode ) {
					self::register_elementor_gap_fill_chunk_done( $state, $chunk );
					$gap_fill_done = absint( $state['elementor_gap_fill_done'] ?? 0 );
					self::sync_elementor_persist_map_state( $state, $map, $source_payload );
					$commit_done   = $progress_total;
				} else {
					++$slice_cursor;
					$commit_done = $slice_cursor;
				}

				$committed = self::commit_elementor_chunk_progress_to_site(
					$post_id,
					$lang,
					$data,
					$state,
					$map,
					$commit_done,
					$progress_total,
					$gap_fill_mode
				);

				if ( is_wp_error( $committed ) ) {
					return $committed;
				}

				if ( ! $gap_fill_mode ) {
					self::write_elementor_slice_cursor( $post_id, $lang, $slice_cursor, $progress_total );
					$state['elementor_chunk_index']      = $slice_cursor;
					$state['elementor_slices_completed'] = $slice_cursor;
				}

				self::save_job_partial_state( $post_id, $lang, $state );
				\PolymartAI\Activity_Logger::sync_elementor_partial_progress( $post_id, $lang );

				continue;
			}

			list( $aliased_payload, $alias_to_path ) = self::alias_elementor_payload_keys( $chunk );
			$ai_options = self::build_elementor_chunk_ai_options( $chunk );

			if ( $gap_fill_mode ) {
				$ai_options['max_timeout'] = max(
					$ai_options['max_timeout'],
					self::ELEMENTOR_JOB_REQUEST_TIMEOUT_MAX
				);
				$ai_options['min_timeout'] = $ai_options['max_timeout'];
			}

			if ( $gap_fill_mode ) {
				$attempt_index = $gap_fill_done + 1;
				$attempt_total = max( 1, $gap_fill_total );
			} else {
				$attempt_index = $slice_cursor + 1;
				$attempt_total = $progress_total;
			}

			$title         = get_the_title( $post_id );
			$post_label    = '' !== $title
				? sprintf( '#%d «%s»', $post_id, $title )
				: sprintf( '#%d', $post_id );

			if ( $gap_fill_mode ) {
				$remaining_fields = count(
					self::filter_remaining_elementor_payload( $source_payload, $map, $skipped_list )
				);
				$stubborn_fields  = count(
					array_filter(
						self::filter_remaining_elementor_payload( $source_payload, $map, $skipped_list ),
						static function ( $text, $path ) {
							return self::stubborn_elementor_field_is_long( (string) $path, (string) $text );
						},
						ARRAY_FILTER_USE_BOTH
					)
				);

				if ( ! empty( $state['elementor_gap_fill_stubborn_only'] ) ) {
					\PolymartAI\Activity_Logger::log(
						'info',
						sprintf(
							/* translators: 1: post label, 2: remaining fields, 3: stubborn field count */
							__( 'Elementor — %1$s: stubborn — %2$d فیلد باقی (%3$d بلند)…', 'polymart-ai' ),
							$post_label,
							$remaining_fields,
							$stubborn_fields
						),
						array( 'post_id' => $post_id, 'lang' => $lang )
					);
				} else {
					\PolymartAI\Activity_Logger::log(
						'info',
						sprintf(
							/* translators: 1: post label, 2: gap chunk index, 3: gap chunk total, 4: remaining fields, 5: stubborn field count */
							__( 'Elementor — %1$s: تکمیل نهایی — ارسال بخش %2$d از %3$d (فیلدهای کوتاه) — %4$d فیلد باقی (%5$d بلند/stubborn)…', 'polymart-ai' ),
							$post_label,
							$attempt_index,
							$attempt_total,
							$remaining_fields,
							$stubborn_fields
						),
						array( 'post_id' => $post_id, 'lang' => $lang )
					);
				}
			} else {
				\PolymartAI\Activity_Logger::log(
					'info',
					sprintf(
						/* translators: 1: post label, 2: chunk index, 3: total chunks, 4: AI provider label */
						__( 'Elementor — %1$s: ارسال بخش %2$d از %3$d به API %4$s…', 'polymart-ai' ),
						$post_label,
						$attempt_index,
						$attempt_total,
						\PolymartAI\Translation\AI\AI_Client::provider_label( $api_endpoint )
					),
					array( 'post_id' => $post_id, 'lang' => $lang )
				);
			}

			\PolymartAI\Activity_Logger::wait_for_arvan_api_gap();

			// Toxic payload logging (metabox/inline debugging): store a small, safe preview of what we send.
			// This helps identify which Elementor paths trigger long hangs / transport timeouts.
			if ( 1 === $attempt_index ) {
				$toxic_preview = array();
				$preview_i     = 0;
				foreach ( $aliased_payload as $k => $v ) {
					$k = (string) $k;
					$v = (string) $v;
					$toxic_preview[] = array(
						'key'   => $k,
						'chars' => function_exists( 'mb_strlen' ) ? mb_strlen( $v, 'UTF-8' ) : strlen( $v ),
						'preview' => wp_trim_words( wp_strip_all_tags( html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 28, '…' ),
					);
					++$preview_i;
					if ( $preview_i >= 6 ) {
						break;
					}
				}
				update_post_meta(
					$post_id,
					\PolymartAI\Activity_Logger\Metabox_Action_Scheduler::TOXIC_META_PREFIX . $lang,
					array(
						'at'     => time(),
						'chunk'  => $attempt_index,
						'total'  => $progress_total,
						'count'  => count( $aliased_payload ),
						'sample' => $toxic_preview,
					)
				);
			}

			$chunk_result = AI_Client::translate_fields(
				$aliased_payload,
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang,
				$ai_options
			);

			\PolymartAI\Activity_Logger::touch_arvan_api_attempt();

			if ( is_wp_error( $chunk_result ) ) {
				if ( ! empty( $aliased_payload ) && AI_Client::is_transient_parse_response_error( $chunk_result ) ) {
					$error_data = $chunk_result->get_error_data();

					if ( is_array( $error_data ) && ! empty( $error_data['body_excerpt'] ) ) {
						\PolymartAI\Activity_Logger::log(
							'warning',
							sprintf(
								/* translators: 1: post ID, 2: chunk index, 3: API body excerpt */
								__( 'Elementor — #%1$d: پاسخ API بخش %2$d قابل پارس نبود — %3$s', 'polymart-ai' ),
								$post_id,
								$attempt_index,
								\PolymartAI\Activity_Logger::truncate_api_error_message( (string) $error_data['body_excerpt'], 220 )
							),
							array( 'post_id' => $post_id, 'lang' => $lang )
						);
					}

					\PolymartAI\Activity_Logger::wait_for_arvan_api_gap();

					$recovered = self::translate_job_chunk_with_single_field_fallback(
						$aliased_payload,
						$api_key,
						$api_endpoint,
						$ai_model,
						$lang,
						count( $aliased_payload ),
						$ai_options
					);

					\PolymartAI\Activity_Logger::touch_arvan_api_attempt();

					if ( ! is_wp_error( $recovered ) ) {
						\PolymartAI\Activity_Logger::log(
							'info',
							sprintf(
								/* translators: 1: post ID, 2: chunk index */
								__( 'Elementor — #%1$d: بازیابی بخش %2$d با ترجمه تک‌فیلدی', 'polymart-ai' ),
								$post_id,
								$attempt_index
							),
							array( 'post_id' => $post_id, 'lang' => $lang )
						);
						$chunk_result = $recovered;
					}
				}

				if ( is_wp_error( $chunk_result ) ) {
					// Capture the exact transport error and which fields were in-flight.
					$failed_paths = array_keys( $chunk );
					update_post_meta(
						$post_id,
						'_polymart_ai_elementor_error_' . $lang,
						sprintf(
							/* translators: 1: chunk index, 2: total chunks, 3: error */
							__( 'Elementor — بخش %1$d/%2$d ناموفق: %3$s', 'polymart-ai' ),
							$attempt_index,
							$progress_total,
							\PolymartAI\Activity_Logger::humanize_api_error_message( $chunk_result->get_error_message() )
						)
					);

					$state['elementor_failures'] = $failures;

					\PolymartAI\Activity_Logger::log(
						'warning',
						sprintf(
							/* translators: 1: post ID, 2: chunk index, 3: error message */
							__( 'Elementor — #%1$d: خطای API در بخش %2$d — %3$s', 'polymart-ai' ),
							$post_id,
							$attempt_index,
							\PolymartAI\Activity_Logger::humanize_api_error_message( $chunk_result->get_error_message() )
						),
						array( 'post_id' => $post_id, 'lang' => $lang )
					);

					return self::handle_elementor_job_slice_failure(
						$post_id,
						$lang,
						$data,
						$state,
						$map,
						$progress_total,
						$chunk_result,
						$failed_paths
					);
				}
			}

			$raw_mapped = self::unmap_elementor_aliases(
				is_array( $chunk_result ) ? $chunk_result : array(),
				$alias_to_path
			);
			$mapped_chunk = self::merge_elementor_api_translations_into_map( $raw_mapped, $source_payload );

			if ( empty( $mapped_chunk ) && ! empty( $aliased_payload ) ) {
				\PolymartAI\Activity_Logger::wait_for_arvan_api_gap();

				$recovered = self::translate_job_chunk_with_single_field_fallback(
					$aliased_payload,
					$api_key,
					$api_endpoint,
					$ai_model,
					$lang,
					count( $aliased_payload ),
					$ai_options
				);

				\PolymartAI\Activity_Logger::touch_arvan_api_attempt();

				if ( ! is_wp_error( $recovered ) ) {
					$raw_recovered = self::unmap_elementor_aliases(
						is_array( $recovered ) ? $recovered : array(),
						$alias_to_path
					);
					$mapped_chunk = self::merge_elementor_api_translations_into_map( $raw_recovered, $source_payload );
				}
			}

			foreach ( $mapped_chunk as $path => $translated ) {
				$translated = trim( (string) $translated );
				$path       = (string) $path;

				if ( '' === $translated || Persian_Detector::contains_persian( $translated ) ) {
					if ( $gap_fill_mode ) {
						\PolymartAI\Activity_Logger::log(
							'warning',
							sprintf(
								/* translators: 1: post ID, 2: field path, 3: reject reason */
								__( 'Elementor — #%1$d: رد پاسخ API برای %2$s — %3$s', 'polymart-ai' ),
								$post_id,
								$path,
								'' === $translated ? 'empty' : 'persian'
							),
							array( 'post_id' => $post_id, 'lang' => $lang, 'path' => $path )
						);
					}

					if ( self::elementor_path_is_segment( $path ) ) {
						$seg_failures = is_array( $state['elementor_segment_failures'] ?? null ) ? $state['elementor_segment_failures'] : array();
						$seg_failures[ $path ] = absint( $seg_failures[ $path ] ?? 0 ) + 1;
						$state['elementor_segment_failures'] = $seg_failures;

						if ( $seg_failures[ $path ] >= self::ELEMENTOR_SEGMENT_MAX_RETRIES ) {
							$base      = preg_replace( '/::__(?:part|seg)\d+$/', '', $path );
							$base_text = (string) ( $source_payload[ $base ] ?? '' );
							$seg_lookup = self::get_elementor_segment_source_lookup( $base, $base_text );
							$seg_source = (string) ( $seg_lookup[ $path ] ?? (string) ( $chunk[ $path ] ?? '' ) );

							if ( '' !== $seg_source ) {
								self::apply_elementor_segment_source_fallback( $path, $seg_source, $map, $state );
								$mapped_chunk[ $path ] = $seg_source;
								unset( $seg_failures[ $path ] );
								$state['elementor_segment_failures'] = $seg_failures;
								continue;
							}
						}
					} else {
						$failures[ $path ] = absint( $failures[ $path ] ?? 0 ) + 1;

						if ( $failures[ $path ] >= self::ELEMENTOR_SEGMENT_MAX_RETRIES ) {
							$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
							$skipped[] = $path;
							$state['elementor_skipped'] = array_values( array_unique( array_map( 'strval', $skipped ) ) );
							unset( $failures[ $path ] );
						}
					}

					unset( $mapped_chunk[ $path ] );
				}
			}

			$mapped_chunk = self::retry_untranslated_elementor_chunk_fields(
				$chunk,
				$mapped_chunk,
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang,
				$ai_options,
				$failures,
				$state,
				max( 2, count( $chunk ) )
			);

			if ( empty( $mapped_chunk ) && ! empty( $chunk ) ) {
				$first_path = (string) array_key_first( $chunk );

				\PolymartAI\Activity_Logger::log(
					'warning',
					sprintf(
						/* translators: 1: post ID, 2: chunk index, 3: field path */
						__( 'Elementor — #%1$d: بخش %2$d پاسخ خالی یا فارسی از API (%3$s)', 'polymart-ai' ),
						$post_id,
						$attempt_index,
						'' !== $first_path ? $first_path : '…'
					),
					array( 'post_id' => $post_id, 'lang' => $lang )
				);

				if ( '' !== $first_path ) {
					if ( self::elementor_path_is_segment( $first_path ) ) {
						$seg_failures = is_array( $state['elementor_segment_failures'] ?? null ) ? $state['elementor_segment_failures'] : array();
						$seg_failures[ $first_path ] = absint( $seg_failures[ $first_path ] ?? 0 ) + 1;
						$state['elementor_segment_failures'] = $seg_failures;
					} else {
						$failures[ $first_path ] = absint( $failures[ $first_path ] ?? 0 ) + 1;

						if ( $failures[ $first_path ] >= self::ELEMENTOR_SEGMENT_MAX_RETRIES ) {
							$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
							$skipped[] = $first_path;
							$state['elementor_skipped'] = array_values( array_unique( array_map( 'strval', $skipped ) ) );
							unset( $failures[ $first_path ] );
						}
					}
				}
			}

			$map = array_merge( $map, $mapped_chunk );
			$map = self::expand_elementor_map_mirrors( $map, $text_mirrors );
			$map = self::merge_elementor_path_map( $post_id, $lang, $data, $map );
			$map = self::sanitize_elementor_translation_map( $map, $source_payload );
			$state['elementor_map'] = $map;
			++$processed;

			$chunk_satisfied = self::elementor_chunk_paths_translated( $chunk, $map, $source_payload );

			if ( $gap_fill_mode ) {
				if ( $chunk_satisfied ) {
					self::register_elementor_gap_fill_chunk_done( $state, $chunk );
					$gap_fill_done = absint( $state['elementor_gap_fill_done'] ?? 0 );
				}

				if ( ! empty( $mapped_chunk ) ) {
					self::remember_elementor_segment_progress( $post_id, $lang, $state, $map, $source_payload );
				} else {
					self::sync_elementor_persist_map_state( $state, $map, $source_payload );
					$state['elementor_map'] = $map;
				}

				$commit_done = $progress_total;
			} elseif ( $chunk_satisfied ) {
				++$slice_cursor;
				$commit_done = $slice_cursor;
			} else {
				$commit_done = $slice_cursor;
			}

			$committed = self::commit_elementor_chunk_progress_to_site(
				$post_id,
				$lang,
				$data,
				$state,
				$map,
				$commit_done,
				$progress_total,
				$gap_fill_mode
			);

			if ( is_wp_error( $committed ) ) {
				return $committed;
			}

			self::touch_translation_lock( $post_id, $lang );

			if ( $chunk_satisfied ) {
				if ( ! $gap_fill_mode ) {
					self::write_elementor_slice_cursor( $post_id, $lang, $slice_cursor, $progress_total );
					$state['elementor_chunk_index']      = $slice_cursor;
					$state['elementor_slices_completed'] = $slice_cursor;
				}

				$state['elementor_chunks_total'] = $progress_total;
				self::save_job_partial_state( $post_id, $lang, $state );
				\PolymartAI\Activity_Logger::sync_elementor_partial_progress( $post_id, $lang );

				if ( ! empty( $mapped_chunk ) ) {
					\PolymartAI\Activity_Logger::touch_successful_api_call();

					if ( $gap_fill_mode ) {
						\PolymartAI\Activity_Logger::log(
							'info',
							sprintf(
								/* translators: 1: post label, 2: gap chunk index, 3: gap total, 4: field count */
								__( 'Elementor — %1$s: تکمیل نهایی — بخش %2$d از %3$d ذخیره شد — %4$d فیلد', 'polymart-ai' ),
								$post_label,
								$attempt_index,
								max( 1, $gap_fill_total ),
								count( $mapped_chunk )
							),
							array( 'post_id' => $post_id, 'lang' => $lang )
						);
					} else {
						\PolymartAI\Activity_Logger::log(
							'info',
							sprintf(
								/* translators: 1: post label, 2: chunk index, 3: field count */
								__( 'Elementor — %1$s: بخش %2$d از %3$d ذخیره شد — %4$d فیلد', 'polymart-ai' ),
								$post_label,
								$attempt_index,
								$progress_total,
								count( $mapped_chunk )
							),
							array( 'post_id' => $post_id, 'lang' => $lang )
						);
					}
				}
			} elseif ( ! empty( $mapped_chunk ) ) {
				\PolymartAI\Activity_Logger::touch_successful_api_call();
				\PolymartAI\Activity_Logger::log(
					'info',
					sprintf(
						/* translators: 1: post label, 2: chunk index, 3: field count */
						__( 'Elementor — %1$s: بخش %2$d — %3$d فیلد ذخیره شد (تکمیل بخش در تیک بعد)', 'polymart-ai' ),
						$post_label,
						$attempt_index,
						count( $mapped_chunk )
					),
					array( 'post_id' => $post_id, 'lang' => $lang )
				);

				if ( $gap_fill_mode ) {
					self::log_elementor_remaining_field_diagnostics(
						$post_id,
						$lang,
						$source_payload,
						$map,
						is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array(),
						sprintf( 'chunk-%d-partial', $attempt_index )
					);
				}
			}

			if ( $processed < $budget && ! empty( $chunks ) ) {
				$delay = \PolymartAI\Activity_Logger::is_bulk_job_running()
					? (int) apply_filters( 'polymart_ai_elementor_inter_request_delay_sec', 12 )
					: (int) apply_filters( 'polymart_ai_metabox_elementor_inter_request_delay_sec', 0 );

				if ( $delay > 0 ) {
					\PolymartAI\Activity_Logger::sleep_with_worker_heartbeat( max( 1, min( 20, $delay ) ) );
				}
			}
		}

		if ( $gap_fill_mode ) {
			$stubborn_skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
			$stubborn_left    = count(
				self::filter_remaining_elementor_payload( $source_payload, $map, $stubborn_skipped )
			);

			if ( $stubborn_left > 0 ) {
				$stubborn_result = self::translate_elementor_gap_fill_stubborn_fields(
					$post_id,
					$lang,
					$data,
					$state,
					$map,
					$source_payload,
					$text_mirrors,
					$api_key,
					$api_endpoint,
					$ai_model,
					min( 4, $stubborn_left )
				);

				if ( ! empty( $stubborn_result['completed'] ) || ! empty( $stubborn_result['touched'] ) ) {
					self::sync_elementor_persist_map_state( $state, $map, $source_payload );
					$state['elementor_map'] = $map;

					$committed = self::commit_elementor_chunk_progress_to_site(
						$post_id,
						$lang,
						$data,
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
				}
			}
		}

		$map                    = self::expand_elementor_map_mirrors( $map, $text_mirrors );
		$state['elementor_map'] = $map;
		$chunk_progress         = self::get_elementor_chunk_progress(
			$post_id,
			$lang,
			array_merge(
				$state,
				array( 'elementor_map' => $map )
			)
		);
		$gap_fill_mode          = ! empty( $state['elementor_gap_fill'] );

		if ( $gap_fill_mode ) {
			$primary_total  = max(
				absint( $state['elementor_primary_batches_done'] ?? 0 ),
				self::read_elementor_slice_cursor_total( $post_id, $lang ),
				absint( $progress_total )
			);
			$progress_total = max( 1, $primary_total );
			$done_count     = $progress_total;
		} else {
			$progress_total = max( 1, absint( $progress_total ), (int) $chunk_progress['total'] );
			$done_count     = min(
				$progress_total,
				max(
					(int) $chunk_progress['done'],
					absint( $slice_cursor ),
					self::read_elementor_slice_cursor( $post_id, $lang ),
					absint( $state['elementor_slices_completed'] ?? 0 )
				)
			);
		}

		$state['elementor_chunk_index']      = $done_count;
		$state['elementor_chunks_total']     = $progress_total;
		$state['elementor_slices_completed'] = $done_count;
		$skipped_list                        = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$remaining                           = self::filter_remaining_elementor_payload(
			self::collect_elementor_translation_payload( $data ),
			$map,
			$skipped_list
		);
		$state['elementor_failures']         = $failures;

		if ( $gap_fill_mode && empty( $remaining ) ) {
			return self::finalize_elementor_stubborn_handoff_success(
				$post_id,
				$lang,
				$data,
				$state,
				$map,
				self::collect_elementor_translation_payload( $data ),
				$text_mirrors,
				$progress_total
			);
		}

		$truly_complete        = self::elementor_job_slice_is_truly_complete( $post_id, $lang, $state );
		$api_schedule_complete = self::elementor_job_api_schedule_complete( $post_id, $lang, $done_count, $progress_total )
			|| $done_count >= $progress_total;

		if ( ! $truly_complete && $api_schedule_complete && ! $gap_fill_mode ) {
			return self::finalize_elementor_job_slice( $post_id, $lang, $data, $map, $state, $done_count, $progress_total, $remaining );
		}

		if ( ! $truly_complete && $gap_fill_mode && ! empty( $remaining ) ) {
			self::log_elementor_remaining_field_diagnostics(
				$post_id,
				$lang,
				self::collect_elementor_translation_payload( $data ),
				$map,
				$skipped_list,
				'gap-fill-tick-end'
			);

			$persisted = self::persist_elementor_job_progress( $post_id, $lang, $data, $map, $done_count, $progress_total );

			if ( is_wp_error( $persisted ) ) {
				return $persisted;
			}

			self::save_job_partial_state( $post_id, $lang, $state );

			return array(
				'done'           => false,
				'phase'          => 'elementor',
				'phase_progress' => self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
				'message'        => self::format_elementor_gap_fill_remaining_message( $post_id, $remaining, $map ),
				'recoverable'    => true,
			);
		}

		if ( ! $truly_complete ) {
			$persisted = self::persist_elementor_job_progress( $post_id, $lang, $data, $map, $done_count, $progress_total );

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

		if ( ! empty( $remaining ) ) {
			\PolymartAI\Activity_Logger::log(
				'info',
				sprintf(
					/* translators: 1: post ID, 2: completed batches, 3: total batches */
					__( 'Elementor — #%1$d: همه %2$d از %3$d بخش API انجام شد — در حال نهایی‌سازی…', 'polymart-ai' ),
					$post_id,
					$done_count,
					$progress_total
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);
		}

		$blocker = self::get_elementor_job_finalize_blockers( $post_id, $lang, $data, $map, $state );

		if ( null !== $blocker ) {
			return self::respond_elementor_blocked_finalize( $post_id, $lang, $data, $map, $state, $done_count, $progress_total, $blocker );
		}

		$persisted = self::persist_elementor_job_progress( $post_id, $lang, $data, $map, $done_count, $progress_total, true );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		self::clear_elementor_slice_cursor( $post_id, $lang );
		self::clear_job_partial_state( $post_id, $lang, true );

		return array(
			'done'           => true,
			'phase'          => 'elementor',
			'phase_progress' => '',
			'message'        => __( 'ترجمه Elementor تکمیل شد.', 'polymart-ai' ),
		);
	}

	private static function handle_elementor_job_slice_failure( $post_id, $lang, array $source_data, array &$state, array $map, $source_total, \WP_Error $error, array $failed_keys = array() ) {
		if ( self::is_api_transport_timeout_error( $error ) && ! empty( $failed_keys ) ) {
			return self::skip_elementor_chunk_on_api_timeout(
				$post_id,
				$lang,
				$source_data,
				$state,
				$map,
				$source_total,
				$failed_keys,
				$error
			);
		}

		$non_throttle_error = AI_Client::is_transient_parse_response_error( $error );

		if ( $non_throttle_error ) {
			\PolymartAI\Activity_Logger::maybe_clear_stale_api_cooldown( $error );
		} else {
			\PolymartAI\Activity_Logger::maybe_apply_api_throttle_cooldown( $error );
		}

		if (
			\PolymartAI\Activity_Logger::is_job_api_cooldown_active()
			&& ! $non_throttle_error
		) {
			$short_error = \PolymartAI\Activity_Logger::format_api_cooldown_message(
				\PolymartAI\Activity_Logger::get_job_api_cooldown_remaining()
			);
		} else {
			$short_error = \PolymartAI\Activity_Logger::humanize_api_error_message( $error->get_error_message() );
		}

		$state['elementor_map'] = $map;
		$source_payload         = self::collect_elementor_translation_payload( $source_data );
		self::sync_elementor_persist_map_state( $state, $map, $source_payload );
		$state                  = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
		$progress               = self::format_elementor_job_progress_marker( $post_id, $lang, $state );
		$progress_total         = max( 1, absint( $state['elementor_chunks_total'] ?? $source_total ) );
		$failures               = is_array( $state['elementor_failures'] ?? null ) ? $state['elementor_failures'] : array();

		foreach ( $failed_keys as $failed_key ) {
			$failed_key = (string) $failed_key;

			if ( '' === $failed_key ) {
				continue;
			}

			$failures[ $failed_key ] = absint( $failures[ $failed_key ] ?? 0 ) + 1;
		}

		$state['elementor_failures'] = $failures;

		update_post_meta(
			$post_id,
			'_polymart_ai_elementor_error_' . $lang,
			sprintf(
				/* translators: 1: chunk progress marker, 2: error detail */
				__( 'Elementor — %1$s: %2$s', 'polymart-ai' ),
				$progress,
				$short_error
			)
		);

		if ( ! empty( $map ) ) {
			$chunk_progress = self::get_elementor_chunk_progress( $post_id, $lang, $state );
			$done_count     = self::resolve_elementor_done_count( $state, $chunk_progress['done'], $post_id, $lang );
			$persisted      = self::persist_elementor_job_progress( $post_id, $lang, $source_data, $map, $done_count, $progress_total );

			if ( ! is_wp_error( $persisted ) ) {
				self::save_job_partial_state( $post_id, $lang, $state );
				\PolymartAI\Activity_Logger::sync_elementor_partial_progress( $post_id, $lang );

				return self::make_recoverable_partial_slice_response(
					'elementor',
					$progress,
					sprintf(
						/* translators: 1: saved batch count, 2: total batches, 3: error hint */
						__( 'Elementor — %1$d از %2$d بخش ذخیره شد. %3$s', 'polymart-ai' ),
						$done_count,
						$progress_total,
						$short_error
					)
				);
			}
		}

		if ( self::is_recoverable_job_slice_error( $error ) ) {
			self::save_job_partial_state( $post_id, $lang, $state );

			return self::make_recoverable_partial_slice_response(
				'elementor',
				$progress,
				$short_error
			);
		}

		return $error;
	}

	private static function skip_elementor_chunk_on_api_timeout( $post_id, $lang, array $source_data, array &$state, array $map, $source_total, array $failed_keys, \WP_Error $error ) {
		$post_id      = absint( $post_id );
		$lang         = sanitize_key( (string) $lang );
		$source_total = max( 1, absint( $source_total ) );
		$source_payload = self::collect_elementor_translation_payload( $source_data );
		$seg_failures   = is_array( $state['elementor_segment_failures'] ?? null ) ? $state['elementor_segment_failures'] : array();
		$skipped        = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$failures       = is_array( $state['elementor_failures'] ?? null ) ? $state['elementor_failures'] : array();
		$timed_out      = array();

		self::sync_elementor_persist_map_state( $state, $map, $source_payload );
		$state['elementor_map'] = $map;

		foreach ( $failed_keys as $failed_key ) {
			$failed_key = (string) $failed_key;

			if ( '' === $failed_key ) {
				continue;
			}

			$timed_out[] = $failed_key;

			if ( self::elementor_path_is_segment( $failed_key ) ) {
				$attempts = absint( $seg_failures[ $failed_key ] ?? 0 ) + 1;
				$seg_failures[ $failed_key ] = $attempts;

				if ( $attempts >= self::ELEMENTOR_SEGMENT_MAX_RETRIES ) {
					$base      = preg_replace( '/::__(?:part|seg)\d+$/', '', $failed_key );
					$base_text = (string) ( $source_payload[ $base ] ?? '' );
					$seg_lookup = self::get_elementor_segment_source_lookup( $base, $base_text );
					$seg_source = (string) ( $seg_lookup[ $failed_key ] ?? '' );

					if ( '' !== $seg_source ) {
						self::apply_elementor_segment_source_fallback( $failed_key, $seg_source, $map, $state );
					}
				}

				continue;
			}

			$failures[ $failed_key ] = absint( $failures[ $failed_key ] ?? 0 ) + 1;

			if ( $failures[ $failed_key ] >= self::ELEMENTOR_SEGMENT_MAX_RETRIES ) {
				$skipped[] = $failed_key;
				unset( $failures[ $failed_key ] );
			}
		}

		$state['elementor_segment_failures'] = $seg_failures;
		$state['elementor_skipped']          = array_values( array_unique( array_map( 'strval', $skipped ) ) );
		$state['elementor_failures']         = $failures;
		self::sync_elementor_persist_map_state( $state, $map, $source_payload );
		$state['elementor_map']              = $map;
		$state                               = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );

		$chunk_progress = self::get_elementor_chunk_progress( $post_id, $lang, $state );
		$done_count     = self::resolve_elementor_done_count( $state, $chunk_progress['done'], $post_id, $lang );
		$progress_total = max( $source_total, (int) $chunk_progress['total'], absint( $state['elementor_chunks_total'] ?? 0 ), 1 );
		$state['elementor_chunks_total'] = $progress_total;

		self::persist_elementor_job_progress( $post_id, $lang, $source_data, $map, $done_count, $progress_total );
		self::save_job_partial_state( $post_id, $lang, $state );

		$progress    = self::format_elementor_job_progress_marker( $post_id, $lang, $state );
		$short_error = \PolymartAI\Activity_Logger::humanize_api_error_message( $error->get_error_message() );

		update_post_meta(
			$post_id,
			'_polymart_ai_elementor_error_' . $lang,
			sprintf(
				/* translators: 1: timed-out path count, 2: error detail */
				__( 'timeout API — %1$d مسیر معلق (%2$s)', 'polymart-ai' ),
				count( $timed_out ),
				$short_error
			)
		);

		\PolymartAI\Activity_Logger::log(
			'warning',
			sprintf(
				/* translators: 1: post ID, 2: progress marker, 3: timed-out count, 4: error */
				__( 'Elementor — #%1$d: timeout API در %2$s — %3$d مسیر معلق (%4$s) — پیشرفت سگمنت‌ها حفظ شد.', 'polymart-ai' ),
				$post_id,
				$progress,
				count( $timed_out ),
				$short_error
			),
			array(
				'post_id' => $post_id,
				'lang'    => $lang,
				'timed_out' => $timed_out,
			)
		);

		\PolymartAI\Activity_Logger::touch_successful_api_call();

		return self::make_recoverable_partial_slice_response(
			'elementor',
			$progress,
			sprintf(
				/* translators: 1: progress marker, 2: timed-out path count */
				__( 'Elementor — %1$s: timeout API — %2$d مسیر معلق — تلاش مجدد همان بخش…', 'polymart-ai' ),
				$progress,
				count( $timed_out )
			)
		);
	}

	public static function skip_next_elementor_pending_chunk( $post_id, $lang, $reason = '' ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$reason  = sanitize_key( (string) $reason );

		if ( $post_id <= 0 || '' === $lang || ! self::uses_elementor_builder( $post_id ) ) {
			return new \WP_Error( 'polymart_ai_invalid_request', __( 'درخواست نامعتبر است.', 'polymart-ai' ) );
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return new \WP_Error( 'polymart_ai_elementor_source_missing', __( 'داده Elementor برای این صفحه یافت نشد.', 'polymart-ai' ) );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'polymart_ai_elementor_source_invalid', __( 'JSON Elementor نامعتبر است.', 'polymart-ai' ) );
		}

		$state = self::get_job_partial_state( $post_id, $lang );
		$state = is_array( $state ) ? $state : array();
		$state = self::hydrate_elementor_job_partial_state( $post_id, $lang, $state );

		$chunk_queue    = self::build_elementor_job_chunk_queue( $post_id, $lang, $data, $state );
		$pending_chunks = is_array( $chunk_queue['pending'] ?? null ) ? $chunk_queue['pending'] : array();

		if ( empty( $pending_chunks ) ) {
			return self::make_recoverable_partial_slice_response(
				'elementor',
				self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
				__( 'Elementor: چیزی برای رد کردن باقی نمانده است.', 'polymart-ai' )
			);
		}

		$chunk = reset( $pending_chunks );
		if ( ! is_array( $chunk ) || empty( $chunk ) ) {
			return self::make_recoverable_partial_slice_response(
				'elementor',
				self::format_elementor_job_progress_marker( $post_id, $lang, $state ),
				__( 'Elementor: بخش معلق خالی بود — ادامه…', 'polymart-ai' )
			);
		}

		$paths   = array_values( array_filter( array_map( 'strval', array_keys( $chunk ) ) ) );
		$skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$skipped = array_values( array_unique( array_merge( array_map( 'strval', $skipped ), $paths ) ) );
		$state['elementor_skipped'] = $skipped;

		// Persist progress so UI sees 0/13 -> 1/13 etc even when a chunk is skipped.
		$map            = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$progress_total  = max( 1, absint( $chunk_queue['total'] ?? 1 ) );
		$chunk_progress  = self::get_elementor_chunk_progress(
			$post_id,
			$lang,
			array(
				'elementor_map'          => $map,
				'elementor_skipped'      => $skipped,
				'elementor_chunks_total' => $progress_total,
			)
		);
		$done_count = self::resolve_elementor_done_count( $state, (int) $chunk_progress['done'], $post_id, $lang );

		self::save_job_partial_state( $post_id, $lang, $state );
		self::persist_elementor_job_progress( $post_id, $lang, $data, $map, $done_count, $progress_total );

		$progress = self::format_elementor_job_progress_marker( $post_id, $lang, $state );

		update_post_meta(
			$post_id,
			'_polymart_ai_elementor_error_' . $lang,
			sprintf(
				/* translators: 1: skipped field count, 2: reason */
				__( 'Elementor — chunk رد شد (%1$d فیلد) — %2$s', 'polymart-ai' ),
				count( $paths ),
				'' !== $reason ? $reason : 'skipped'
			)
		);

		return self::make_recoverable_partial_slice_response(
			'elementor',
			$progress,
			sprintf(
				/* translators: 1: progress marker, 2: skipped field count */
				__( 'Elementor — %1$s: یک بخش API رد شد (%2$d فیلد) — ادامه…', 'polymart-ai' ),
				$progress,
				count( $paths )
			)
		);
	}

}
