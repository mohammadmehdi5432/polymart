<?php
/**
 * Activity logs, translation reports, and resumable batch job state.
 *
 * @package PolymartAI
 */

namespace PolymartAI;

use PolymartAI\Translation\Menu_Translator;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Translation_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Class Activity_Logger
 */
final class Activity_Logger {

	const LOG_OPTION           = 'polymart_ai_logs';
	const REPORT_OPTION        = 'polymart_ai_translation_report';
	const JOB_OPTION           = 'polymart_ai_translation_job';
	const NOTICE_TRANSIENT     = 'polymart_ai_admin_notice';
	const MAX_LOG_ENTRIES      = 500;
	const MAX_REPORT_ENTRIES   = 1000;
	const MAX_JOB_RETRIES      = 3;
	const STEP_LOCK_KEY        = 'polymart_ai_job_step_lock';
	const STEP_LOCK_TTL        = 1800;

	/**
	 * Register admin notice display.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
	}

	/**
	 * Show stored critical notices in wp-admin.
	 *
	 * @return void
	 */
	public static function render_admin_notices() {
		if ( ! current_user_can( REST_API::required_admin_capability() ) ) {
			return;
		}

		$notice = get_transient( self::NOTICE_TRANSIENT );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		$type = in_array( $notice['type'] ?? '', array( 'error', 'warning', 'success', 'info' ), true )
			? $notice['type']
			: 'error';

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s:</strong> %3$s</p></div>',
			esc_attr( $type ),
			esc_html__( 'مترجم پلی‌مارت', 'polymart-ai' ),
			esc_html( $notice['message'] )
		);

		delete_transient( self::NOTICE_TRANSIENT );
	}

	/**
	 * Store a dismissible wp-admin notice.
	 *
	 * @param string $message Notice text.
	 * @param string $type    error|warning|success|info.
	 * @return void
	 */
	public static function set_admin_notice( $message, $type = 'error' ) {
		set_transient(
			self::NOTICE_TRANSIENT,
			array(
				'message' => (string) $message,
				'type'    => $type,
				'time'    => time(),
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * Append an activity log entry.
	 *
	 * @param string               $level   info|success|warning|error.
	 * @param string               $message Persian log message.
	 * @param array<string, mixed> $context Optional context.
	 * @return void
	 */
	public static function log( $level, $message, array $context = array() ) {
		$logs   = get_option( self::LOG_OPTION, array() );
		$logs   = is_array( $logs ) ? $logs : array();
		$logs[] = array(
			'id'      => uniqid( 'log_', true ),
			'level'   => sanitize_key( (string) $level ),
			'message' => sanitize_text_field( (string) $message ),
			'context' => $context,
			'time'    => time(),
		);

		if ( count( $logs ) > self::MAX_LOG_ENTRIES ) {
			$logs = array_slice( $logs, -self::MAX_LOG_ENTRIES );
		}

		update_option( self::LOG_OPTION, $logs, false );

		if ( 'error' === $level ) {
			self::set_admin_notice( $message, 'error' );
		}
	}

	/**
	 * Get activity logs.
	 *
	 * @param int    $limit Max entries.
	 * @param string $level Optional level filter.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_logs( $limit = 100, $level = '', $offset = 0 ) {
		$logs = self::filter_logs( $level );

		return array_slice( $logs, max( 0, absint( $offset ) ), max( 1, absint( $limit ) ) );
	}

	/**
	 * Get paginated activity logs.
	 *
	 * @param int    $limit  Max entries per page.
	 * @param string $level  Optional level filter.
	 * @param int    $offset Offset for pagination.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function get_logs_page( $limit = 25, $level = '', $offset = 0 ) {
		$logs = self::filter_logs( $level );

		return array(
			'items' => array_slice( $logs, max( 0, absint( $offset ) ), max( 1, absint( $limit ) ) ),
			'total' => count( $logs ),
		);
	}

	/**
	 * Filter stored logs by level.
	 *
	 * @param string $level Optional level filter.
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_logs( $level = '' ) {
		$logs = get_option( self::LOG_OPTION, array() );
		$logs = is_array( $logs ) ? array_reverse( $logs ) : array();

		if ( '' === $level ) {
			return $logs;
		}

		return array_values(
			array_filter(
				$logs,
				static function ( $entry ) use ( $level ) {
					return is_array( $entry ) && ( $entry['level'] ?? '' ) === $level;
				}
			)
		);
	}

	/**
	 * Clear all activity logs.
	 *
	 * @return void
	 */
	public static function clear_logs() {
		delete_option( self::LOG_OPTION );
	}

	/**
	 * Record a successful translation in the report.
	 *
	 * @param int    $post_id         Post ID.
	 * @param string $lang            Target language code.
	 * @param string $title_source    Source title.
	 * @param string $title_translated Translated title.
	 * @param string $source          manual|ai|bulk|auto.
	 * @return void
	 */
	public static function log_report( $post_id, $lang, $title_source, $title_translated, $source = 'ai' ) {
		$language = Language_Registry::get_language( $lang );
		$reports  = get_option( self::REPORT_OPTION, array() );
		$reports  = is_array( $reports ) ? $reports : array();

		$post = get_post( absint( $post_id ) );

		$reports[] = array(
			'id'               => uniqid( 'rep_', true ),
			'post_id'          => (int) $post_id,
			'post_type'        => $post instanceof \WP_Post ? $post->post_type : '',
			'post_type_label'  => $post instanceof \WP_Post ? Post_Translator::get_post_type_label( $post->post_type ) : '',
			'lang'             => sanitize_key( $lang ),
			'lang_label'       => $language ? $language['native_name'] : $lang,
			'title_source'     => sanitize_text_field( (string) $title_source ),
			'title_translated' => sanitize_text_field( (string) $title_translated ),
			'source'           => sanitize_key( (string) $source ),
			'time'             => time(),
			'edit_url'         => get_edit_post_link( $post_id, 'raw' ),
		);

		if ( count( $reports ) > self::MAX_REPORT_ENTRIES ) {
			$reports = array_slice( $reports, -self::MAX_REPORT_ENTRIES );
		}

		update_option( self::REPORT_OPTION, $reports, false );
	}

	/**
	 * Get translation report entries.
	 *
	 * @param int    $limit Max entries.
	 * @param string $lang  Optional language filter.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_reports( $limit = 100, $lang = '', $offset = 0 ) {
		$reports = self::filter_reports( $lang );

		return array_slice( $reports, max( 0, absint( $offset ) ), max( 1, absint( $limit ) ) );
	}

	/**
	 * Get paginated translation report entries.
	 *
	 * @param int    $limit  Max entries per page.
	 * @param string $lang   Optional language filter.
	 * @param int    $offset Offset for pagination.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function get_reports_page( $limit = 25, $lang = '', $offset = 0 ) {
		$reports = self::filter_reports( $lang );

		return array(
			'items' => array_slice( $reports, max( 0, absint( $offset ) ), max( 1, absint( $limit ) ) ),
			'total' => count( $reports ),
		);
	}

	/**
	 * Filter stored reports by language.
	 *
	 * @param string $lang Optional language filter.
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_reports( $lang = '' ) {
		$reports = get_option( self::REPORT_OPTION, array() );
		$reports = is_array( $reports ) ? array_reverse( $reports ) : array();

		if ( '' === $lang ) {
			return $reports;
		}

		$lang = sanitize_key( $lang );

		return array_values(
			array_filter(
				$reports,
				static function ( $entry ) use ( $lang ) {
					return is_array( $entry ) && ( $entry['lang'] ?? '' ) === $lang;
				}
			)
		);
	}

	/**
	 * Default empty job structure.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty_job() {
		return array(
			'status'          => 'idle',
			'lang'            => 'en',
			'last_post_id'    => 0,
			'queue'           => array(),
			'retry_queue'     => array(),
			'deferred_queue'  => array(),
			'parked_ids'      => array(),
			'defer_rounds'    => array(),
			'post_step_counts' => array(),
			'retry_attempts'  => array(),
			'remaining'       => 0,
			'total'           => 0,
			'processed'       => 0,
			'steps'           => 0,
			'succeeded'       => 0,
			'succeeded_ids'   => array(),
			'partial'         => 0,
			'partial_ids'     => array(),
			'exhausted_ids'   => array(),
			'failed'          => 0,
			'current_post_id' => null,
			'step_started_at' => null,
			'last_error'      => null,
			'started_at'      => null,
			'updated_at'      => null,
			'phase'           => 'posts',
			'last_menu_id'    => 0,
			'partial_post_id' => null,
			'partial_phase'   => null,
			'partial_progress' => null,
			'consecutive_post_id'   => 0,
			'consecutive_post_steps' => 0,
		);
	}

	/**
	 * Get the current translation job.
	 *
	 * @param bool $deep_sync When true, refresh live site-wide stats (expensive).
	 * @return array<string, mixed>
	 */
	public static function get_job( $deep_sync = false ) {
		$job = get_option( self::JOB_OPTION, array() );

		if ( ! is_array( $job ) || empty( $job ) ) {
			return self::empty_job();
		}

		$job = wp_parse_args( $job, self::empty_job() );

		return self::normalize_job_for_response( $job, $deep_sync );
	}

	/**
	 * Ensure legacy and cursor-based job payloads expose a consistent shape.
	 *
	 * @param array<string, mixed> $job       Job data.
	 * @param bool                 $deep_sync When false, avoid full-site scans (polling reads).
	 * @return array<string, mixed>
	 */
	private static function normalize_job_for_response( array $job, $deep_sync = false ) {
		if ( ! empty( $job['queue'] ) && is_array( $job['queue'] ) && ! isset( $job['remaining'] ) ) {
			$job['remaining'] = count( $job['queue'] );
		}

		$lang = sanitize_key( (string) ( $job['lang'] ?? '' ) );

		if ( ! $deep_sync ) {
			return self::normalize_job_for_lightweight_response( $job, $lang );
		}

		if ( '' !== $lang && 'paused' === ( $job['status'] ?? '' ) ) {
			$job = self::reconcile_stale_job_state( $job, $lang );
		} elseif ( '' !== $lang && in_array( (string) ( $job['status'] ?? '' ), array( 'running', 'idle' ), true ) ) {
			$step_in_progress = absint( $job['current_post_id'] ?? 0 ) > 0
				&& (int) ( $job['step_started_at'] ?? 0 ) > 0
				&& ( time() - (int) $job['step_started_at'] ) < 600;

			if ( $step_in_progress ) {
				// Avoid full-site scans while a step is mid-flight (prevents lockups).
				$job = self::attach_job_metrics( $job, $lang, false );
			} else {
				Post_Translator::flush_translation_status_cache();
				$job = self::attach_job_metrics( $job, $lang );

				if ( in_array( (string) ( $job['status'] ?? '' ), array( 'running' ), true ) ) {
					$synced_at = (int) ( $job['remaining_synced_at'] ?? 0 );

					if ( time() - $synced_at >= 10 ) {
						self::sync_job_remaining( $job, $lang );
					}
				}
			}
		} elseif ( ! isset( $job['remaining'] ) ) {
			$job['remaining'] = max( 0, (int) ( $job['total'] ?? 0 ) - (int) ( $job['succeeded'] ?? 0 ) );
		}

		$job['remaining'] = max( 0, (int) $job['remaining'] );
		$job              = self::attach_job_metrics( $job, $lang );

		if ( '' !== $lang && 'completed' === ( $job['status'] ?? '' ) ) {
			$validated_at = (int) ( $job['validated_at'] ?? 0 );

			if ( time() - $validated_at < 30 && isset( $job['needs_work'] ) ) {
				return $job;
			}

			$live_remaining    = Translation_Query::count_untranslated_persian_posts( $lang ) + Menu_Translator::count_untranslated( $lang );
			$job['remaining']  = $live_remaining;
			$job['needs_work'] = $live_remaining;
			$job['validated_at'] = time();

			if ( $live_remaining > 0 ) {
				$job['outdated']   = true;
				$job['status']     = 'paused';
				$job['last_error'] = sprintf(
					/* translators: %d: remaining items */
					__( 'اجرای قبلی کامل نبود — %d مورد هنوز نیاز به ترجمه دارد. «شروع ترجمه خودکار» را بزنید.', 'polymart-ai' ),
					$live_remaining
				);
				self::save_job( $job );
			}
		}

		if ( '' !== $lang && 'paused' === ( $job['status'] ?? '' ) && ! empty( $job['stalled_ids'] ) && is_array( $job['stalled_ids'] ) ) {
			$job['stalled_details'] = array();

			foreach ( array_slice( array_map( 'absint', $job['stalled_ids'] ), 0, 5 ) as $stalled_id ) {
				if ( $stalled_id <= 0 ) {
					continue;
				}

				$gaps = Post_Translator::get_translation_gaps( $stalled_id, $lang );

				if ( 'translated' === ( $gaps['status'] ?? '' ) ) {
					continue;
				}

				$job['stalled_details'][] = array(
					'post_id' => $stalled_id,
					'title'   => get_the_title( $stalled_id ),
					'status'  => $gaps['status'] ?? 'partial',
					'missing' => $gaps['missing'],
					'fields'  => $gaps['fields'] ?? array(),
					'notes'   => $gaps['notes'] ?? array(),
				);
			}
		}

		return $job;
	}

	/**
	 * Shape job data for lightweight polling without full-site scans.
	 *
	 * @param array<string, mixed> $job  Job data.
	 * @param string               $lang Target language code.
	 * @return array<string, mixed>
	 */
	private static function normalize_job_for_lightweight_response( array $job, $lang ) {
		if ( ! isset( $job['remaining'] ) ) {
			$job['remaining'] = max( 0, (int) ( $job['total'] ?? 0 ) - (int) ( $job['succeeded'] ?? 0 ) );
		}

		$job['remaining'] = max( 0, (int) $job['remaining'] );

		if ( ! isset( $job['needs_work'] ) && isset( $job['live_stats'] ) && is_array( $job['live_stats'] ) ) {
			$job['needs_work'] = (int) ( $job['live_stats']['untranslated'] ?? 0 ) + (int) ( $job['live_stats']['partial'] ?? 0 );
		}

		$job = self::attach_job_metrics( $job, $lang, false );

		if ( '' !== $lang && 'paused' === ( $job['status'] ?? '' ) && ! empty( $job['stalled_ids'] ) && is_array( $job['stalled_ids'] ) ) {
			$job['stalled_details'] = array();

			foreach ( array_slice( array_map( 'absint', $job['stalled_ids'] ), 0, 5 ) as $stalled_id ) {
				if ( $stalled_id <= 0 ) {
					continue;
				}

				$gaps = Post_Translator::get_translation_gaps( $stalled_id, $lang );

				if ( 'translated' === ( $gaps['status'] ?? '' ) ) {
					continue;
				}

				$job['stalled_details'][] = array(
					'post_id' => $stalled_id,
					'title'   => get_the_title( $stalled_id ),
					'status'  => $gaps['status'] ?? 'partial',
					'missing' => $gaps['missing'],
					'fields'  => $gaps['fields'] ?? array(),
					'notes'   => $gaps['notes'] ?? array(),
				);
			}
		}

		return $job;
	}

	/**
	 * Drop stale pause state when live data shows nothing left to translate.
	 *
	 * @param array<string, mixed> $job  Job state.
	 * @param string               $lang Target language code.
	 * @return array<string, mixed>
	 */
	private static function reconcile_stale_job_state( array $job, $lang ) {
		Post_Translator::flush_translation_status_cache();

		$live_ids       = Translation_Query::collect_remaining_post_ids( $lang, 100 );
		$live_remaining = Translation_Query::count_untranslated_persian_posts( $lang );
		$stored_partial_ids    = is_array( $job['partial_ids'] ?? null ) ? array_map( 'absint', $job['partial_ids'] ) : array();
		$job['remaining']      = $live_remaining;
		$job['remaining_synced_at'] = time();
		$job['stalled_ids']    = array_values(
			array_filter(
				array_map( 'absint', $live_ids ),
				static function ( $post_id ) use ( $lang ) {
					return $post_id > 0 && 'translated' !== Post_Translator::get_translation_status( $post_id, $lang );
				}
			)
		);
		$job['partial_ids']    = array_values(
			array_filter(
				array_map( 'absint', $stored_partial_ids ),
				static function ( $post_id ) use ( $lang ) {
					return $post_id > 0 && 'translated' !== Post_Translator::get_translation_status( $post_id, $lang );
				}
			)
		);
		$job['retry_queue']    = array_values(
			array_filter(
				self::get_retry_queue( $job ),
				static function ( $post_id ) use ( $lang ) {
					return $post_id > 0 && 'translated' !== Post_Translator::get_translation_status( $post_id, $lang );
				}
			)
		);
		$job['deferred_queue'] = array_values(
			array_filter(
				self::get_deferred_queue( $job ),
				static function ( $post_id ) use ( $lang ) {
					return $post_id > 0 && 'translated' !== Post_Translator::get_translation_status( $post_id, $lang );
				}
			)
		);

		if ( $live_remaining <= 0 ) {
			$job['stalled_ids']   = array();
			$job['partial_ids']   = array();
			$job['retry_queue']   = array();
			$job['retry_attempts'] = array();
			$job['exhausted_ids'] = array();
			$job['remaining']     = 0;

			if ( 'paused' === ( $job['status'] ?? '' ) && 'critical' !== ( $job['pause_reason'] ?? '' ) ) {
				$job['status']       = 'completed';
				$job['last_error']    = null;
				$job['pause_reason']  = null;
				self::save_job( $job );
				self::log( 'success', __( 'ترجمه خودکار با موفقیت به پایان رسید.', 'polymart-ai' ) );
			}

			return $job;
		}

		if ( 'paused' === ( $job['status'] ?? '' ) ) {
			if ( empty( $job['stalled_ids'] ) ) {
				$job['last_error']   = null;
				$job['pause_reason'] = null;
				$job['status']       = $live_remaining > 0 ? 'idle' : 'completed';

				if ( 'completed' === $job['status'] ) {
					$job['remaining'] = 0;
				}
			} else {
				$job['last_error'] = self::format_stalled_job_error( $lang, $job['stalled_ids'] );
			}

			self::save_job( $job );
		}

		return $job;
	}

	/**
	 * Derive consistent progress counters for the admin UI.
	 *
	 * @param array<string, mixed> $job        Job data.
	 * @param string               $lang       Target language.
	 * @param bool                 $sync_live  Whether to refresh site-wide live stats.
	 * @return array<string, mixed>
	 */
	private static function attach_job_metrics( array $job, $lang = '', $sync_live = true ) {
		$lang      = sanitize_key( (string) $lang );
		$steps     = max( (int) ( $job['steps'] ?? 0 ), (int) ( $job['processed'] ?? 0 ) );
		$total     = max( 0, (int) ( $job['initial_total'] ?? $job['total'] ?? 0 ) );
		$failed    = max( 0, (int) ( $job['failed'] ?? 0 ) );
		$exhausted = count( self::get_exhausted_job_post_ids( $job ) );
		$remaining = max( 0, (int) ( $job['remaining'] ?? 0 ) );

		if ( '' !== $lang && $sync_live ) {
			self::sync_job_live_stats( $job, $lang );

			$reconciled_at = (int) ( $job['succeeded_reconciled_steps'] ?? -1 );

			if ( $reconciled_at !== $steps ) {
				$succeeded_count = count( is_array( $job['succeeded_ids'] ?? null ) ? $job['succeeded_ids'] : array() );

				// Full per-post audit on every succeeded ID is too slow on large catalogs.
				if ( $succeeded_count <= 50 || 0 === $steps % 10 ) {
					self::reconcile_succeeded_posts( $job, $lang );
				}

				$job['succeeded_reconciled_steps'] = $steps;
			}

			if ( in_array( (string) ( $job['status'] ?? '' ), array( 'running', 'paused' ), true ) ) {
				$job['remaining'] = (int) ( $job['needs_work'] ?? 0 );
			}

			// Keep the run budget open while the site still has actionable work.
			$needs_work = (int) ( $job['needs_work'] ?? 0 );
			if ( $needs_work > 0 && in_array( (string) ( $job['status'] ?? '' ), array( 'running', 'paused' ), true ) ) {
				$succeeded_now = max( 0, (int) ( $job['succeeded'] ?? 0 ) );
				$min_total     = $succeeded_now + $exhausted + $needs_work;
				if ( $min_total > $total ) {
					$total                = $min_total;
					$job['total']         = $total;
					$job['initial_total'] = max( (int) ( $job['initial_total'] ?? 0 ), $total );
				}
			}
		} elseif ( '' === $lang ) {
			$job['needs_work'] = $remaining;
		}

		$partial_ids = is_array( $job['partial_ids'] ?? null ) ? $job['partial_ids'] : array();
		$partial_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $partial_ids ),
					static function ( $post_id ) {
						return $post_id > 0;
					}
				)
			)
		);

		if ( '' !== $lang && $sync_live ) {
			$partial_ids = array_values(
				array_filter(
					$partial_ids,
					static function ( $post_id ) use ( $lang ) {
						return 'translated' !== Post_Translator::get_translation_status( $post_id, $lang );
					}
				)
			);
		}

		$succeeded = max( 0, (int) ( $job['succeeded'] ?? 0 ) );
		$partial   = count( $partial_ids );

		$retry_queue     = self::get_retry_queue( $job );
		$deferred_queue  = self::get_deferred_queue( $job );
		$exhausted_map   = array_fill_keys( self::get_exhausted_job_post_ids( $job ), true );
		$retry_pending   = count(
			array_filter(
				$retry_queue,
				static function ( $queued_id ) use ( $exhausted_map ) {
					return ! isset( $exhausted_map[ (int) $queued_id ] );
				}
			)
		);
		$deferred_pending = count(
			array_filter(
				$deferred_queue,
				static function ( $queued_id ) use ( $exhausted_map ) {
					return ! isset( $exhausted_map[ (int) $queued_id ] );
				}
			)
		);
		$run_pending = $retry_pending + $deferred_pending + $partial;

		$job['partial_ids']   = $partial_ids;
		$job['partial']       = $partial;
		$job['steps']         = $steps;
		$job['processed']     = $steps;
		$job['total']         = $total;
		$job['run_pending']   = $run_pending;
		$job['retry_pending'] = $retry_pending;
		$job['deferred_pending'] = $deferred_pending;

		$run_done = min( $total, $succeeded + $exhausted );
		$job['run_done']  = $run_done;
		$job['completed'] = $run_done;

		if ( $total > 0 ) {
			$job['progress_pct'] = min( 100, (int) round( ( $run_done / $total ) * 100 ) );
		} else {
			$job['progress_pct'] = 0;
		}

		$job['run_remaining'] = max( 0, $total - $run_done );

		$current_post_id = absint( $job['current_post_id'] ?? 0 );

		if ( $current_post_id <= 0 ) {
			$current_post_id = absint( $job['partial_post_id'] ?? 0 );
		}

		if ( $current_post_id > 0 ) {
			$job['current_post'] = array(
				'post_id' => $current_post_id,
				'title'   => get_the_title( $current_post_id ),
			);

			if ( ! empty( $job['partial_phase'] ) ) {
				$job['current_post']['partial_phase']    = (string) $job['partial_phase'];
				$job['current_post']['partial_progress'] = (string) ( $job['partial_progress'] ?? '' );
			}
		} else {
			$job['current_post'] = null;
		}

		return $job;
	}

	/**
	 * Store the latest step summary for the admin UI.
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Post ID.
	 * @param string               $status  Step status slug.
	 * @param string               $message Human-readable message.
	 * @return void
	 */
	private static function set_job_last_step( array &$job, $post_id, $status, $message = '' ) {
		$post_id = absint( $post_id );

		$job['last_step'] = array(
			'post_id' => $post_id,
			'title'   => $post_id > 0 ? get_the_title( $post_id ) : '',
			'status'  => sanitize_key( (string) $status ),
			'message' => sanitize_text_field( (string) $message ),
			'time'    => time(),
		);
	}

	/**
	 * Sync remaining count from the database after a step.
	 *
	 * @param array<string, mixed> $job  Job state (by reference).
	 * @param string               $lang Target language code.
	 * @return void
	 */
	private static function sync_job_remaining( array &$job, $lang, $force = false ) {
		self::sync_job_live_stats( $job, $lang, $force );
		$job['remaining_synced_at'] = time();
	}

	/**
	 * Refresh site-wide translation stats stored on the job for the admin UI.
	 *
	 * @param array<string, mixed> $job       Job state (by reference).
	 * @param string               $lang      Target language code.
	 * @param bool                 $force     Skip throttling and recompute immediately.
	 * @param bool                 $force_full Run a full per-post audit (refresh_stats only).
	 * @return void
	 */
	private static function sync_job_live_stats( array &$job, $lang, $force = false, $force_full = false ) {
		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			return;
		}

		$steps        = max( (int) ( $job['steps'] ?? 0 ), (int) ( $job['processed'] ?? 0 ) );
		$synced_at    = (int) ( $job['live_stats_at'] ?? 0 );
		$synced_steps = (int) ( $job['live_stats_steps'] ?? -1 );

		if ( ! $force && $synced_steps === $steps && ( time() - $synced_at ) < 15 ) {
			return;
		}

		if ( $force ) {
			Post_Translator::flush_translation_status_cache();
			REST_API::invalidate_stats_cache();
		}

		$stats = Translation_Query::compute_translation_stats( $lang, $force_full );

		$job['live_stats'] = array(
			'total'        => (int) $stats['total'],
			'untranslated' => (int) $stats['untranslated'],
			'partial'      => (int) $stats['partial'],
			'translated'   => (int) $stats['translated'],
		);
		$job['live_stats_at']      = time();
		$job['live_stats_steps']   = $steps;
		$job['needs_work']         = (int) $stats['untranslated'] + (int) $stats['partial'] + Menu_Translator::count_untranslated( $lang );

		if ( ! isset( $job['initial_needs_work'] ) || (int) $job['initial_needs_work'] <= 0 ) {
			$job['initial_needs_work'] = max( (int) ( $job['initial_total'] ?? 0 ), (int) $job['needs_work'] );
		}

		$job['site_resolved'] = max( 0, (int) $job['initial_needs_work'] - (int) $job['needs_work'] );
	}

	/**
	 * Increment step counter for one translation attempt.
	 *
	 * @param array<string, mixed> $job Job state (by reference).
	 * @return void
	 */
	private static function increment_job_step( array &$job ) {
		$job['steps']     = (int) ( $job['steps'] ?? $job['processed'] ?? 0 ) + 1;
		$job['processed'] = $job['steps'];
	}

	/**
	 * Whether the job exceeded its retry budget for this run.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return bool
	 */
	private static function job_exceeded_step_budget( array $job ) {
		if ( absint( $job['partial_post_id'] ?? 0 ) > 0 ) {
			return false;
		}

		$needs_work = (int) ( $job['needs_work'] ?? $job['remaining'] ?? 0 );

		if ( $needs_work > 0 ) {
			return false;
		}

		$total  = max( 1, (int) ( $job['total'] ?? 0 ) );
		$steps  = max( (int) ( $job['steps'] ?? 0 ), (int) ( $job['processed'] ?? 0 ) );
		$budget = (int) apply_filters(
			'polymart_ai_job_step_budget',
			( $total * 8 ) + 100,
			$job
		);

		return $steps >= max( 1, $budget );
	}

	/**
	 * Max retry attempts before a post is skipped for this job run.
	 *
	 * Variable products need many HTTP slices; give them a higher budget than pages.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return int
	 */
	private static function get_max_job_retries_for_post( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$max     = self::MAX_JOB_RETRIES;

		if ( $post_id <= 0 ) {
			return $max;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return (int) apply_filters( 'polymart_ai_job_max_retries', $max, $post_id, $lang );
		}

		$product = wc_get_product( $post_id );

		if ( ! $product instanceof \WC_Product ) {
			return (int) apply_filters( 'polymart_ai_job_max_retries', $max, $post_id, $lang );
		}

		if ( $product->is_type( 'variable' ) ) {
			$variation_count = count( $product->get_children() );
			$max             = max( $max, 3 + (int) ceil( $variation_count / 4 ) );
		}

		/**
		 * Filter per-post retry budget for auto-translate jobs.
		 *
		 * @param int    $max     Computed retry cap.
		 * @param int    $post_id Post ID.
		 * @param string $lang    Target language code.
		 */
		return (int) apply_filters( 'polymart_ai_job_max_retries', $max, $post_id, $lang );
	}

	/**
	 * Durable completeness check used by the auto-translate job.
	 *
	 * Combines audit status with a fresh collect of pending source fields so
	 * in-memory-only caches cannot inflate the succeeded counter.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return string translated|partial|untranslated
	 */
	private static function resolve_post_job_status( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		Post_Translator::flush_translation_status_cache( $post_id );

		$status = Post_Translator::get_translation_status( $post_id, $lang );

		if ( 'translated' !== $status ) {
			return $status;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return 'translated';
		}

		$pending = Post_Translator::collect_persian_fields( $post, $lang );

		if ( ! empty( $pending ) ) {
			return 'partial';
		}

		return 'translated';
	}

	/**
	 * Remember a post that is durably fully translated in this run.
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Post ID.
	 * @return void
	 */
	private static function track_succeeded_post( array &$job, $post_id ) {
		$post_id = absint( $post_id );
		$ids     = is_array( $job['succeeded_ids'] ?? null ) ? $job['succeeded_ids'] : array();

		if ( $post_id > 0 && ! in_array( $post_id, $ids, true ) ) {
			$ids[] = $post_id;
		}

		$job['succeeded_ids'] = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$job['succeeded']     = count( $job['succeeded_ids'] );
	}

	/**
	 * Remove a post from the durable success list.
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Post ID.
	 * @return void
	 */
	private static function untrack_succeeded_post( array &$job, $post_id ) {
		$post_id = absint( $post_id );

		$job['succeeded_ids'] = array_values(
			array_filter(
				is_array( $job['succeeded_ids'] ?? null ) ? $job['succeeded_ids'] : array(),
				static function ( $queued_id ) use ( $post_id ) {
					return (int) $queued_id !== $post_id;
				}
			)
		);
		$job['succeeded'] = count( $job['succeeded_ids'] );
	}

	/**
	 * Re-check previously succeeded posts against durable storage.
	 *
	 * @param array<string, mixed> $job  Job state (by reference).
	 * @param string               $lang Target language code.
	 * @return void
	 */
	private static function reconcile_succeeded_posts( array &$job, $lang ) {
		$lang = sanitize_key( (string) $lang );
		$ids  = is_array( $job['succeeded_ids'] ?? null ) ? $job['succeeded_ids'] : array();

		// Legacy jobs only stored a counter — trust live site progress instead.
		if ( empty( $ids ) && (int) ( $job['succeeded'] ?? 0 ) > 0 ) {
			$site_resolved = max( 0, (int) ( $job['site_resolved'] ?? 0 ) );
			$job['succeeded'] = min( (int) $job['succeeded'], max( $site_resolved, 0 ) );

			return;
		}

		$still_ok = array();

		foreach ( $ids as $post_id ) {
			$post_id = absint( $post_id );

			if ( $post_id <= 0 ) {
				continue;
			}

			if ( 'translated' === self::resolve_post_job_status( $post_id, $lang ) ) {
				$still_ok[] = $post_id;
			} else {
				self::track_partial_post( $job, $post_id );
			}
		}

		$job['succeeded_ids'] = array_values( array_unique( $still_ok ) );
		$job['succeeded']     = count( $job['succeeded_ids'] );
	}

	/**
	 * Track a post that finished partial in this job run.
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Post ID.
	 * @return void
	 */
	private static function track_partial_post( array &$job, $post_id ) {
		$post_id     = absint( $post_id );
		$partial_ids = is_array( $job['partial_ids'] ?? null ) ? $job['partial_ids'] : array();

		if ( $post_id > 0 && ! in_array( $post_id, $partial_ids, true ) ) {
			$partial_ids[] = $post_id;
		}

		$job['partial_ids'] = $partial_ids;
		$job['partial']     = count( $partial_ids );
	}

	/**
	 * Remove a post from the partial tracker after a full translation.
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Post ID.
	 * @return void
	 */
	private static function clear_partial_post( array &$job, $post_id ) {
		$post_id = absint( $post_id );

		$job['partial_ids'] = array_values(
			array_filter(
				is_array( $job['partial_ids'] ?? null ) ? $job['partial_ids'] : array(),
				static function ( $queued_id ) use ( $post_id ) {
					return (int) $queued_id !== $post_id;
				}
			)
		);
		$job['partial'] = count( $job['partial_ids'] );
		self::remove_post_from_deferred_queue( $job, $post_id );
		$parked = self::get_parked_ids( $job );
		$job['parked_ids'] = array_values(
			array_filter(
				$parked,
				static function ( $queued_id ) use ( $post_id ) {
					return (int) $queued_id !== $post_id;
				}
			)
		);
	}

	/**
	 * Remove a post from the deferred (heavy/partial) queue.
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Post ID.
	 * @return void
	 */
	private static function remove_post_from_deferred_queue( array &$job, $post_id ) {
		$post_id = absint( $post_id );

		$job['deferred_queue'] = array_values(
			array_filter(
				self::get_deferred_queue( $job ),
				static function ( $queued_id ) use ( $post_id ) {
					return (int) $queued_id !== $post_id;
				}
			)
		);
	}

	/**
	 * Persist translation job state.
	 *
	 * @param array<string, mixed> $job Job data.
	 * @return array<string, mixed>
	 */
	public static function save_job( array $job ) {
		$job['updated_at'] = time();
		update_option( self::JOB_OPTION, $job, false );

		return $job;
	}

	/**
	 * Start a new auto-translation job.
	 *
	 * @param string $lang Target language code.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function start_job( $lang ) {
		$lang = sanitize_key( (string) $lang );

		$existing = self::get_job( false );

		if ( 'running' === ( $existing['status'] ?? '' ) ) {
			return new \WP_Error(
				'polymart_ai_job_running',
				__( 'یک فرآیند ترجمه خودکار در حال اجراست. ابتدا آن را متوقف یا از سرگیری کنید.', 'polymart-ai' )
			);
		}

		Post_Translator::flush_translation_status_cache();
		REST_API::invalidate_stats_cache();

		$stats      = Translation_Query::compute_translation_stats( $lang, false );
		$menu_needs = Menu_Translator::count_untranslated( $lang );
		$needs_work = (int) $stats['untranslated'] + (int) $stats['partial'] + $menu_needs;
		$total      = $needs_work;

		if ( $total <= 0 ) {
			$probe_post = Translation_Query::find_next_untranslated_post_id( $lang, 0 );

			if ( $probe_post <= 0 && $menu_needs <= 0 ) {
				return new \WP_Error(
					'polymart_ai_no_queue',
					__( 'مورد ترجمه‌نشده‌ای برای این زبان یافت نشد.', 'polymart-ai' )
				);
			}

			$needs_work = max( 1, $menu_needs );
			$total      = $needs_work;
		}

		$language   = Language_Registry::get_language( $lang );
		$lang_label = $language ? $language['native_name'] : $lang;

		$job = array(
			'status'             => 'running',
			'lang'               => $lang,
			'lang_label'         => $lang_label,
			'last_post_id'       => 0,
			'queue'              => array(),
			'retry_queue'        => array(),
			'deferred_queue'     => array(),
			'retry_attempts'     => array(),
			'remaining'          => $total,
			'total'              => $total,
			'initial_total'      => $total,
			'initial_needs_work' => $needs_work,
			'needs_work'         => $needs_work,
			'site_resolved'      => 0,
			'live_stats'         => array(
				'total'        => (int) $stats['total'],
				'untranslated' => (int) $stats['untranslated'],
				'partial'      => (int) $stats['partial'],
				'translated'   => (int) $stats['translated'],
			),
			'live_stats_at'      => time(),
			'live_stats_steps'   => 0,
			'processed'       => 0,
			'steps'           => 0,
			'succeeded'       => 0,
			'succeeded_ids'   => array(),
			'partial'         => 0,
			'partial_ids'     => array(),
			'exhausted_ids'   => array(),
			'failed'          => 0,
			'current_post_id' => null,
			'last_error'      => null,
			'started_at'      => time(),
			'updated_at'      => time(),
			'phase'           => 'posts',
			'last_menu_id'    => 0,
			'menu_remaining'  => $menu_needs,
		);

		self::save_job( $job );
		self::log(
			'info',
			sprintf(
				/* translators: 1: language name, 2: count */
				__( 'ترجمه خودکار به %1$s شروع شد — %2$d مورد در صف.', 'polymart-ai' ),
				$lang_label,
				$total
			),
			array( 'lang' => $lang, 'total' => $total )
		);

		return self::normalize_job_for_response( $job, false );
	}

	/**
	 * Pause the running job.
	 *
	 * @return array<string, mixed>
	 */
	public static function pause_job() {
		$job = self::get_job_raw();

		if ( 'running' === ( $job['status'] ?? '' ) ) {
			$job['status']          = 'paused';
			$job['current_post_id'] = null;
			$job['step_started_at'] = null;
			self::save_job( $job );
			self::log( 'warning', __( 'ترجمه خودکار متوقف موقت شد.', 'polymart-ai' ) );
		}

		return self::normalize_job_for_response( $job, false );
	}

	/**
	 * Resume a paused job.
	 *
	 * @return array<string, mixed>
	 */
	public static function resume_job() {
		$job = self::get_job_raw();
		$job = wp_parse_args( $job, self::empty_job() );

		if ( in_array( $job['status'], array( 'paused', 'running' ), true ) ) {
			$lang = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );

			$job['status']          = 'running';
			$job['last_error']      = null;
			$job['pause_reason']    = null;
			$job['last_post_id']    = 0;
			$job['current_post_id'] = null;
			$job['step_started_at'] = null;
			$job['retry_attempts']  = array();
			$job['exhausted_ids']   = array();
			$job['retry_queue']     = array();
			$job['deferred_queue']  = array();
			$job['parked_ids']      = array();
			$job['defer_rounds']    = array();
			$job['post_step_counts'] = array();

			self::save_job( $job );
			self::log( 'info', __( 'ترجمه خودکار از سر گرفته شد.', 'polymart-ai' ) );
		}

		return self::normalize_job_for_response( $job, false );
	}

	/**
	 * Stop and clear the job.
	 *
	 * @return array<string, mixed>
	 */
	public static function stop_job() {
		$job = self::get_job_raw();

		if ( 'idle' !== ( $job['status'] ?? '' ) ) {
			$lang       = sanitize_key( (string) ( $job['lang'] ?? 'en' ) );
			$partial_id = absint( $job['partial_post_id'] ?? 0 );
			$current_id = absint( $job['current_post_id'] ?? 0 );

			foreach ( array_unique( array_filter( array( $partial_id, $current_id ) ) ) as $post_id ) {
				if ( $post_id > 0 && '' !== $lang ) {
					Post_Translator::clear_job_partial_state( $post_id, $lang );
					Post_Translator::release_translation_lock( $post_id, $lang );
				}
			}

			self::log( 'warning', __( 'ترجمه خودکار توسط کاربر متوقف شد.', 'polymart-ai' ) );
		}

		self::release_step_lock();

		return self::save_job( self::empty_job() );
	}

	/**
	 * Force a live recomputation of site-wide translation stats for the admin UI.
	 *
	 * @param string $lang Target language code.
	 * @return array<string, mixed>
	 */
	public static function refresh_job_stats( $lang = '' ) {
		$lang = sanitize_key( (string) $lang );

		Post_Translator::flush_translation_status_cache();
		REST_API::invalidate_stats_cache();

		$job = wp_parse_args( self::get_job_raw(), self::empty_job() );

		if ( '' === $lang ) {
			$lang = sanitize_key( (string) ( $job['lang'] ?? '' ) );
		}

		if ( '' === $lang ) {
			$lang = 'en';
		}

		Post_Translator::reconcile_all_flagged_translation_indexes( $lang );
		self::sync_job_live_stats( $job, $lang, true, true );

		if ( sanitize_key( (string) ( $job['lang'] ?? '' ) ) === $lang ) {
			$job = self::reconcile_stale_job_state( $job, $lang );
		}

		self::save_job( $job );

		return self::normalize_job_for_response( $job, true );
	}

	/**
	 * Acquire a global lock so only one job step runs at a time.
	 *
	 * @return bool
	 */
	private static function acquire_step_lock() {
		if ( get_transient( self::STEP_LOCK_KEY ) ) {
			return false;
		}

		set_transient( self::STEP_LOCK_KEY, time(), self::STEP_LOCK_TTL );

		return true;
	}

	/**
	 * Release the global job step lock.
	 *
	 * @return void
	 */
	private static function release_step_lock() {
		delete_transient( self::STEP_LOCK_KEY );
	}

	/**
	 * Process the next item in the job queue.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function process_job_step() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 1200 );
		}

		$job = self::get_job_raw();

		if ( ! in_array( $job['status'] ?? '', array( 'running' ), true ) ) {
			return self::normalize_job_for_response( $job, false );
		}

		if ( ! self::acquire_step_lock() ) {
			$job['step_deferred']         = true;
			$job['step_deferred_reason']  = 'step_lock_busy';
			$job['step_deferred_message'] = __( 'مرحله دیگری در حال اجراست — چند لحظه صبر کنید.', 'polymart-ai' );

			return self::normalize_job_for_response( $job, false );
		}

		try {
			return self::run_job_step( $job );
		} catch ( \Throwable $e ) {
			$job = self::get_job_raw();
			$job['status']          = 'paused';
			$job['pause_reason']    = 'critical';
			$job['current_post_id'] = null;
			$job['step_started_at'] = null;
			$job['last_error']      = sprintf(
				/* translators: %s: exception message */
				__( 'مرحله ترجمه با خطای سرور متوقف شد: %s', 'polymart-ai' ),
				$e->getMessage()
			);
			self::save_job( $job );
			self::log( 'error', $job['last_error'] );

			return self::normalize_job_for_response( $job, false );
		} finally {
			self::release_step_lock();
		}
	}

	/**
	 * Load job option without expensive live-stat side effects.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_job_raw() {
		$job = get_option( self::JOB_OPTION, array() );

		if ( ! is_array( $job ) || empty( $job ) ) {
			return self::empty_job();
		}

		return wp_parse_args( $job, self::empty_job() );
	}

	/**
	 * Execute one translation step for a running job.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return array<string, mixed>
	 */
	private static function run_job_step( array $job ) {
		$lang = (string) $job['lang'];

		if ( 'menus' === (string) ( $job['phase'] ?? 'posts' ) ) {
			return self::run_menu_job_step( $job );
		}

		$cursor = (int) ( $job['last_post_id'] ?? 0 );

		self::reconcile_stuck_job_queues( $job, $lang );

		if ( self::job_exceeded_step_budget( $job ) && (int) ( $job['needs_work'] ?? $job['remaining'] ?? 0 ) > 0 ) {
			$remaining_ids       = Translation_Query::collect_remaining_post_ids( $lang, 10 );
			$job['status']       = 'paused';
			$job['pause_reason'] = 'stalled';
			$job['stalled_ids']  = $remaining_ids;
			$job['step_started_at'] = null;
			self::sync_job_remaining( $job, $lang, false );
			$job['last_error'] = self::format_stalled_job_error( $lang, $remaining_ids );
			self::save_job( $job );

			return self::normalize_job_for_response( $job, false );
		}

		$post_id    = 0;
		$from_retry = false;
		$partial_id = absint( $job['partial_post_id'] ?? 0 );

		if ( $partial_id > 0 ) {
			if ( in_array( $partial_id, self::get_parked_ids( $job ), true ) ) {
				Post_Translator::release_translation_lock( $partial_id, $lang );
				$job['partial_post_id'] = null;
				$partial_id             = 0;
			}
		}

		if ( $partial_id > 0 ) {
			$post_id    = $partial_id;
			$from_retry = true;
		} else {
			$pick = self::pick_next_job_post_id( $job, $lang, $cursor );

			if ( ! $pick['post_id'] ) {
				// Cursor may have walked past posts that only look done in-memory.
				// Reset and pull a fresh actionable set while site work remains.
				self::sync_job_live_stats( $job, $lang, true );
				self::reconcile_succeeded_posts( $job, $lang );

				$needs_work = (int) ( $job['needs_work'] ?? 0 );

				if ( $needs_work > 0 ) {
					$actionable = self::get_actionable_remaining_ids( $lang, $job, min( 50, max( 1, $needs_work ) ) );

					if ( ! empty( $actionable ) ) {
						$job['last_post_id'] = 0;
						$job['deferred_queue'] = array_values(
							array_unique(
								array_merge( self::get_deferred_queue( $job ), $actionable )
							)
						);
						$job['total']         = max( (int) ( $job['total'] ?? 0 ), (int) ( $job['succeeded'] ?? 0 ) + $needs_work );
						$job['initial_total'] = max( (int) ( $job['initial_total'] ?? 0 ), (int) $job['total'] );
						self::save_job( $job );

						$pick = self::pick_next_job_post_id( $job, $lang, 0 );
					}
				}
			}

			if ( ! $pick['post_id'] ) {
				self::reconcile_stuck_job_queues( $job, $lang );
				$pick = self::pick_next_job_post_id( $job, $lang, $cursor );

				if ( ! $pick['post_id'] && $cursor > 0 ) {
					$job['last_post_id'] = 0;
					$pick                = self::pick_next_job_post_id( $job, $lang, 0 );
				}
			}

			if ( ! $pick['post_id'] ) {
				if ( Menu_Translator::count_untranslated( $lang ) > 0 ) {
					$job['phase']        = 'menus';
					$job['last_menu_id'] = 0;
					self::save_job( $job );

					return self::run_menu_job_step( $job );
				}

				$job['step_started_at'] = null;
				$job['current_post_id'] = null;
				self::finalize_job_if_queue_exhausted( $job, $lang );
				self::sync_job_remaining( $job, $lang );

				if ( 'running' === ( $job['status'] ?? '' ) && (int) ( $job['needs_work'] ?? $job['remaining'] ?? 0 ) > 0 ) {
					$job['status']       = 'paused';
					$job['pause_reason'] = 'pick_stalled';
					$job['last_error']   = __( 'صف ترجمه گیر کرد — «ادامه» را بزنید یا «توقف کامل» و دوباره «شروع».', 'polymart-ai' );
					self::set_job_last_step( $job, 0, 'failed', $job['last_error'] );
				}

				self::save_job( $job );

				if ( 'completed' === ( $job['status'] ?? '' ) ) {
					self::log( 'success', __( 'ترجمه خودکار با موفقیت به پایان رسید.', 'polymart-ai' ) );
				} elseif ( ! empty( $job['last_error'] ) ) {
					self::log( 'warning', (string) $job['last_error'] );
				}

				return self::normalize_job_for_response( $job, false );
			}

			$post_id    = $pick['post_id'];
			$from_retry = $pick['from_retry'];
			$job['partial_post_id'] = $post_id;

			if ( absint( $job['consecutive_post_id'] ?? 0 ) !== $post_id ) {
				$job['consecutive_post_id']    = 0;
				$job['consecutive_post_steps'] = 0;
			}
		}

		$job['current_post_id'] = $post_id;
		$job['step_started_at'] = time();

		$step_counts = is_array( $job['post_step_counts'] ?? null ) ? $job['post_step_counts'] : array();
		$step_counts[ $post_id ] = absint( $step_counts[ $post_id ] ?? 0 ) + 1;
		$job['post_step_counts'] = $step_counts;

		self::save_job( $job );

		$slice = Post_Translator::process_job_translation_slice( $post_id, $lang );

		if ( is_wp_error( $slice ) && 'polymart_ai_translation_in_progress' === $slice->get_error_code() ) {
			$job['current_post_id']       = null;
			$job['step_started_at']       = null;
			$job['step_deferred']         = true;
			$job['step_deferred_reason']  = 'translation_in_progress';
			$job['step_deferred_message'] = $slice->get_error_message();
			self::save_job( $job );

			return self::normalize_job_for_response( $job, false );
		}

		if ( is_wp_error( $slice ) ) {
			Post_Translator::clear_job_partial_state( $post_id, $lang );
			Post_Translator::release_translation_lock( $post_id, $lang );
			$job['partial_post_id']   = null;
			$job['partial_phase']     = null;
			$job['partial_progress']  = null;

			return self::normalize_job_for_response(
				self::handle_job_translation_failure( $job, $post_id, $lang, $slice, $from_retry ),
				false
			);
		}

		if ( empty( $slice['done'] ) ) {
			$consecutive_id = absint( $job['consecutive_post_id'] ?? 0 );

			if ( $consecutive_id === $post_id ) {
				$job['consecutive_post_steps'] = absint( $job['consecutive_post_steps'] ?? 0 ) + 1;
			} else {
				$job['consecutive_post_id']    = $post_id;
				$job['consecutive_post_steps'] = 1;
			}

			$limits = Post_Translator::get_job_step_limits_for_post(
				$post_id,
				(string) ( $slice['phase'] ?? '' )
			);
			$max_consecutive = (int) ( $limits['max_consecutive'] ?? 8 );
			$max_steps_per_run = (int) ( $limits['max_per_run'] ?? 24 );
			$steps_on_post      = absint( $job['post_step_counts'][ $post_id ] ?? 0 );
			$previous_progress  = (string) ( $job['partial_progress'] ?? '' );
			$current_progress   = (string) ( $slice['phase_progress'] ?? '' );
			$made_progress      = '' !== $current_progress && $current_progress !== $previous_progress;

			if (
				! $made_progress
				&& (
					absint( $job['consecutive_post_steps'] ?? 0 ) >= max( 1, $max_consecutive )
					|| $steps_on_post >= max( 1, $max_steps_per_run )
				)
			) {
				Post_Translator::release_translation_lock( $post_id, $lang );
				self::defer_job_post( $job, $post_id, $lang );
				$job['partial_post_id']        = null;
				$job['partial_phase']          = null;
				$job['partial_progress']       = null;
				$job['consecutive_post_id']    = 0;
				$job['consecutive_post_steps'] = 0;
				$job['current_post_id']        = null;
				$job['step_started_at']        = null;
				self::increment_job_step( $job );
				self::set_job_last_step(
					$job,
					$post_id,
					'deferred',
					sprintf(
						/* translators: 1: post ID, 2: phase label */
						__( 'مورد #%1$d کنار گذاشته شد — ادامه بقیه صف. (%2$s)', 'polymart-ai' ),
						$post_id,
						(string) ( $slice['phase'] ?? '' )
					)
				);
				self::save_job( $job );

				return self::normalize_job_for_response( $job, false );
			}

			$job['step_partial']      = true;
			$job['partial_post_id']   = $post_id;
			$job['partial_phase']     = (string) ( $slice['phase'] ?? '' );
			$job['partial_progress']  = (string) ( $slice['phase_progress'] ?? '' );

			if ( $made_progress ) {
				$job['consecutive_post_steps'] = 1;
			}

			$job['current_post_id']   = null;
			$job['step_started_at']   = null;
			self::increment_job_step( $job );
			self::set_job_last_step(
				$job,
				$post_id,
				'partial',
				(string) ( $slice['message'] ?? __( 'ادامه در مرحله بعد…', 'polymart-ai' ) )
			);
			self::save_job( $job );

			return self::normalize_job_for_response( $job, false );
		}

		$job['consecutive_post_id']    = 0;
		$job['consecutive_post_steps'] = 0;
		$job['partial_post_id']  = null;
		$job['partial_phase']    = null;
		$job['partial_progress'] = null;
		unset( $job['step_partial'] );

		return self::normalize_job_for_response(
			self::handle_job_translation_success( $job, $post_id, $lang, array( 'translations' => array() ), true ),
			false
		);
	}

	/**
	 * Pick the next post to translate: forward scan first, then deferred partials, then hard retries.
	 *
	 * Heavy Elementor pages stay in deferred_queue so the rest of the site keeps moving.
	 *
	 * @param array<string, mixed> $job    Current job state.
	 * @param string               $lang   Target language code.
	 * @param int                  $cursor Last processed post ID.
	 * @return array{post_id: int, from_retry: bool}
	 */
	private static function pick_next_job_post_id( array &$job, $lang, $cursor ) {
		$exclude_ids = self::get_forward_scan_exclude_ids( $job );

		$post_id = Translation_Query::find_next_untranslated_post_id( $lang, $cursor, $exclude_ids );

		if ( ! $post_id && $cursor > 0 ) {
			$post_id = Translation_Query::find_next_untranslated_post_id( $lang, 0, $exclude_ids );
		}

		if ( $post_id ) {
			return array(
				'post_id'    => $post_id,
				'from_retry' => false,
			);
		}

		$deferred_queue = self::get_actionable_deferred_queue( $job, $lang );

		if ( ! empty( $deferred_queue ) ) {
			$post_id               = (int) array_shift( $deferred_queue );
			$job['deferred_queue'] = $deferred_queue;

			return array(
				'post_id'    => $post_id,
				'from_retry' => true,
			);
		}

		$parked = self::get_parked_ids( $job );

		if ( ! empty( $parked ) ) {
			$post_id = (int) array_shift( $parked );
			self::remove_post_from_deferred_queue( $job, $post_id );
			$job['parked_ids'] = $parked;

			return array(
				'post_id'    => $post_id,
				'from_retry' => true,
			);
		}

		$exhausted_ids = self::get_exhausted_job_post_ids( $job );
		$retry_queue   = array_values(
			array_filter(
				self::get_retry_queue( $job ),
				static function ( $queued_id ) use ( $exhausted_ids ) {
					return ! in_array( (int) $queued_id, $exhausted_ids, true );
				}
			)
		);

		if ( ! empty( $retry_queue ) ) {
			$post_id            = (int) array_shift( $retry_queue );
			$job['retry_queue'] = $retry_queue;

			return array(
				'post_id'    => $post_id,
				'from_retry' => true,
			);
		}

		$actionable = self::get_actionable_remaining_ids( $lang, $job, 50 );

		if ( ! empty( $actionable ) ) {
			$post_id               = (int) $actionable[0];
			$job['deferred_queue'] = array_slice( $actionable, 1 );
			$job['last_post_id']   = 0;

			return array(
				'post_id'    => $post_id,
				'from_retry' => true,
			);
		}

		return array(
			'post_id'    => 0,
			'from_retry' => false,
		);
	}

	/**
	 * Post IDs the forward scanner must skip (deferred, parked, exhausted, done).
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return int[]
	 */
	private static function get_forward_scan_exclude_ids( array $job ) {
		return array_values(
			array_unique(
				array_merge(
					self::get_exhausted_job_post_ids( $job ),
					self::get_parked_ids( $job ),
					array_map( 'absint', is_array( $job['succeeded_ids'] ?? null ) ? $job['succeeded_ids'] : array() )
				)
			)
		);
	}

	/**
	 * Heal queue deadlocks (orphaned deferred/parked state) before picking the next post.
	 *
	 * @param array<string, mixed> $job  Job state (by reference).
	 * @param string               $lang Language code.
	 * @return void
	 */
	private static function reconcile_stuck_job_queues( array &$job, $lang ) {
		$lang = sanitize_key( (string) $lang );

		$deferred = self::get_deferred_queue( $job );
		$rounds   = is_array( $job['defer_rounds'] ?? null ) ? $job['defer_rounds'] : array();

		foreach ( $deferred as $post_id ) {
			$post_id = absint( $post_id );

			if ( $post_id <= 0 ) {
				continue;
			}

			$max_rounds = (int) apply_filters( 'polymart_ai_job_max_defer_rounds_per_post', 10, $job, $post_id );

			if ( (int) ( $rounds[ $post_id ] ?? 0 ) >= max( 1, $max_rounds ) ) {
				self::park_job_post( $job, $post_id, $lang );
			}
		}

		// Drop translated IDs from auxiliary queues.
		foreach ( array( 'deferred_queue', 'retry_queue', 'parked_ids' ) as $queue_key ) {
			$ids = is_array( $job[ $queue_key ] ?? null ) ? $job[ $queue_key ] : array();

			if ( 'deferred_queue' === $queue_key ) {
				$ids = self::get_deferred_queue( $job );
			} elseif ( 'retry_queue' === $queue_key ) {
				$ids = self::get_retry_queue( $job );
			} else {
				$ids = self::get_parked_ids( $job );
			}

			$job[ $queue_key ] = array_values(
				array_filter(
					array_map( 'absint', $ids ),
					static function ( $post_id ) use ( $lang ) {
						return $post_id > 0 && ( '' === $lang || 'translated' !== Post_Translator::get_translation_status( $post_id, $lang ) );
					}
				)
			);
		}
	}

	/**
	 * @param array<string, mixed> $job Job state.
	 * @return int[]
	 */
	private static function get_parked_ids( array $job ) {
		$ids = $job['parked_ids'] ?? array();

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'absint', $ids ),
				static function ( $post_id ) {
					return $post_id > 0;
				}
			)
		);
	}

	/**
	 * Deferred queue minus posts parked for this run.
	 *
	 * @param array<string, mixed> $job  Job state.
	 * @param string               $lang Language code.
	 * @return int[]
	 */
	private static function get_actionable_deferred_queue( array $job, $lang ) {
		$lang     = sanitize_key( (string) $lang );
		$parked   = self::get_parked_ids( $job );
		$deferred = array();

		foreach ( self::get_deferred_queue( $job ) as $post_id ) {
			$post_id = absint( $post_id );

			if ( $post_id <= 0 || in_array( $post_id, $parked, true ) ) {
				continue;
			}

			if ( '' !== $lang && 'translated' === Post_Translator::get_translation_status( $post_id, $lang ) ) {
				continue;
			}

			$deferred[] = $post_id;
		}

		return $deferred;
	}

	/**
	 * Defer a post: rotate to tail, advance cursor, park after too many rounds.
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @return void
	 */
	private static function defer_job_post( array &$job, $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 ) {
			return;
		}

		$rounds            = is_array( $job['defer_rounds'] ?? null ) ? $job['defer_rounds'] : array();
		$rounds[ $post_id ] = (int) ( $rounds[ $post_id ] ?? 0 ) + 1;
		$job['defer_rounds'] = $rounds;
		$job['last_post_id'] = $post_id;

		$max_rounds = (int) apply_filters( 'polymart_ai_job_max_defer_rounds_per_post', 10, $job, $post_id );

		if ( $rounds[ $post_id ] >= max( 1, $max_rounds ) ) {
			self::park_job_post( $job, $post_id, $lang );

			return;
		}

		self::enqueue_job_defer( $job, $post_id, true );
	}

	/**
	 * Skip a heavy post for the rest of this job run (progress kept in post meta).
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @return void
	 */
	private static function park_job_post( array &$job, $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 ) {
			return;
		}

		self::remove_post_from_deferred_queue( $job, $post_id );

		$parked = self::get_parked_ids( $job );

		if ( ! in_array( $post_id, $parked, true ) ) {
			$parked[] = $post_id;
		}

		$job['parked_ids'] = array_values( array_unique( array_map( 'absint', $parked ) ) );

		self::log(
			'warning',
			sprintf(
				/* translators: 1: post ID, 2: post title */
				__( 'مورد #%1$d «%2$s» فعلاً کنار گذاشته شد — بقیه صف ادامه می‌یابد.', 'polymart-ai' ),
				$post_id,
				get_the_title( $post_id ) ?: __( '(بدون عنوان)', 'polymart-ai' )
			),
			array( 'post_id' => $post_id, 'lang' => $lang )
		);
	}

	/**
	 * Normalize deferred queue from job state.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return int[]
	 */
	private static function get_deferred_queue( array $job ) {
		$queue = $job['deferred_queue'] ?? array();

		if ( ! is_array( $queue ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'absint', $queue ),
				static function ( $post_id ) {
					return $post_id > 0;
				}
			)
		);
	}

	/**
	 * Queue a heavy or still-incomplete post for later (does not count as a hard failure).
	 *
	 * @param array<string, mixed> $job      Job state (by reference).
	 * @param int                  $post_id  Post ID.
	 * @param bool                 $rotate   Move existing entry to tail for round-robin.
	 * @return void
	 */
	private static function enqueue_job_defer( array &$job, $post_id, $rotate = false ) {
		$post_id = absint( $post_id );
		$queue   = self::get_deferred_queue( $job );

		if ( $post_id <= 0 ) {
			return;
		}

		if ( in_array( $post_id, $queue, true ) ) {
			if ( ! $rotate ) {
				return;
			}

			$queue = array_values(
				array_filter(
					$queue,
					static function ( $queued_id ) use ( $post_id ) {
						return (int) $queued_id !== $post_id;
					}
				)
			);
		}

		$queue[]               = $post_id;
		$job['deferred_queue'] = $queue;
	}

	/**
	 * Normalize retry queue from job state.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return int[]
	 */
	private static function get_retry_queue( array $job ) {
		$queue = $job['retry_queue'] ?? array();

		if ( ! is_array( $queue ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'absint', $queue ),
				static function ( $post_id ) {
					return $post_id > 0;
				}
			)
		);
	}

	/**
	 * Whether the job has more work after the current cursor.
	 *
	 * @param string $lang   Target language code.
	 * @param int    $cursor Last processed post ID.
	 * @param int[]  $retry_queue Pending retry post IDs.
	 * @return bool
	 */
	private static function job_has_pending_work( $lang, $cursor, array $retry_queue, array $job = array() ) {
		$exclude_ids = self::get_exhausted_job_post_ids( $job );

		if ( ! empty( self::get_actionable_deferred_queue( $job, $lang ) ) ) {
			return true;
		}

		if ( ! empty( self::get_parked_ids( $job ) ) ) {
			return true;
		}

		foreach ( $retry_queue as $queued_id ) {
			if ( ! in_array( (int) $queued_id, $exclude_ids, true ) ) {
				return true;
			}
		}

		$post_id = Translation_Query::find_next_untranslated_post_id(
			$lang,
			$cursor,
			self::get_forward_scan_exclude_ids( $job )
		);

		if ( $post_id ) {
			return true;
		}

		if ( $cursor > 0 ) {
			return (bool) Translation_Query::find_next_untranslated_post_id(
				$lang,
				0,
				self::get_forward_scan_exclude_ids( $job )
			);
		}

		if ( Menu_Translator::find_next_untranslated_id( $lang, 0, $exclude_ids ) > 0 ) {
			return true;
		}

		return ! empty( self::get_actionable_remaining_ids( $lang, $job, 1 ) );
	}

	/**
	 * Post IDs that exceeded the retry limit and should be skipped.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return int[]
	 */
	private static function get_exhausted_job_post_ids( array $job ) {
		$exhausted = is_array( $job['exhausted_ids'] ?? null ) ? array_map( 'absint', $job['exhausted_ids'] ) : array();

		return array_values( array_unique( array_filter( $exhausted ) ) );
	}

	/**
	 * Remaining post IDs that are not skipped after retry exhaustion.
	 *
	 * @param string               $lang Target language code.
	 * @param array<string, mixed> $job  Job state.
	 * @param int                  $limit Maximum IDs to return.
	 * @return int[]
	 */
	private static function get_actionable_remaining_ids( $lang, array $job, $limit = 50 ) {
		$exclude_lookup = array_fill_keys(
			array_merge(
				self::get_forward_scan_exclude_ids( $job ),
				self::get_parked_ids( $job )
			),
			true
		);
		$actionable     = array();

		foreach ( Translation_Query::collect_remaining_post_ids( $lang, max( 1, absint( $limit ) ) ) as $post_id ) {
			$post_id = absint( $post_id );

			if ( $post_id <= 0 || isset( $exclude_lookup[ $post_id ] ) ) {
				continue;
			}

			$actionable[] = $post_id;
		}

		return $actionable;
	}

	/**
	 * Mark the job completed or paused when no actionable queue items remain.
	 *
	 * @param array<string, mixed> $job  Job state (by reference).
	 * @param string               $lang Target language code.
	 * @return void
	 */
	private static function finalize_job_if_queue_exhausted( array &$job, $lang ) {
		self::sync_job_remaining( $job, $lang );

		if ( ! empty( self::get_actionable_remaining_ids( $lang, $job, 1 ) ) ) {
			return;
		}

		$remaining_ids = Translation_Query::collect_all_remaining_post_ids( $lang );
		$exhausted     = self::get_exhausted_job_post_ids( $job );
		$parked        = self::get_parked_ids( $job );
		$fresh_retry   = array_values(
			array_filter(
				array_map( 'absint', $remaining_ids ),
				static function ( $post_id ) use ( $exhausted, $parked, $job ) {
					if ( $post_id <= 0 || in_array( $post_id, $exhausted, true ) || in_array( $post_id, $parked, true ) ) {
						return false;
					}

					return ! in_array( $post_id, self::get_deferred_queue( $job ), true );
				}
			)
		);

		if ( ! empty( $fresh_retry ) ) {
			$job['deferred_queue'] = array_values(
				array_unique(
					array_merge( self::get_deferred_queue( $job ), $fresh_retry )
				)
			);
			self::save_job( $job );

			return;
		}

		$skipped_ids = array_values(
			array_unique(
				array_merge(
					array_intersect(
						array_map( 'absint', $remaining_ids ),
						self::get_exhausted_job_post_ids( $job )
					),
					$parked
				)
			)
		);

		if ( (int) ( $job['remaining'] ?? 0 ) > 0 && ! empty( $skipped_ids ) ) {
			$job['status']       = 'completed';
			$job['pause_reason'] = null;
			$job['skipped_ids']  = $skipped_ids;
			$job['last_error']   = self::format_skipped_job_notice( $lang, $skipped_ids );

			return;
		}

		if ( (int) ( $job['remaining'] ?? 0 ) > 0 ) {
			$stalled_ids         = Translation_Query::collect_remaining_post_ids( $lang, 10 );
			$job['status']       = 'paused';
			$job['pause_reason'] = 'stalled';
			$job['stalled_ids']  = $stalled_ids;
			$job['last_error']   = self::format_stalled_job_error( $lang, $stalled_ids );

			return;
		}

		$job['status']       = 'completed';
		$job['pause_reason'] = null;
		$job['remaining']    = 0;
		$job['last_error']   = null;
	}

	/**
	 * Build a completion notice when some posts were skipped after retry exhaustion.
	 *
	 * @param string $lang        Target language code.
	 * @param int[]  $skipped_ids Post IDs skipped in this run.
	 * @return string
	 */
	private static function format_skipped_job_notice( $lang, array $skipped_ids ) {
		return self::format_stalled_job_error(
			$lang,
			$skipped_ids,
			__( 'ترجمه خودکار تمام شد — %1$d مورد پس از چند تلاش رد شد و بقیه انجام شد: %2$s', 'polymart-ai' )
		);
	}

	/**
	 * Build a user-facing pause message for stalled posts.
	 *
	 * @param string $lang          Target language code.
	 * @param int[]  $remaining_ids Post IDs still incomplete.
	 * @param string $template      Optional sprintf template.
	 * @return string
	 */
	private static function format_stalled_job_error( $lang, array $remaining_ids, $template = '' ) {
		$lang          = sanitize_key( (string) $lang );
		$remaining_ids = array_values( array_filter( array_map( 'absint', $remaining_ids ) ) );
		$details       = array();

		foreach ( array_slice( $remaining_ids, 0, 5 ) as $post_id ) {
			$gaps    = Post_Translator::get_translation_gaps( $post_id, $lang );
			$parts   = array();

			foreach ( $gaps['fields'] ?? array() as $field ) {
				if ( empty( $field['translated'] ) ) {
					$parts[] = sprintf(
						'%s [%s]',
						(string) ( $field['label'] ?? '' ),
						(string) ( $field['meta_key'] ?? '' )
					);
				}
			}

			if ( empty( $parts ) && ! empty( $gaps['missing'] ) ) {
				$parts = $gaps['missing'];
			}

			if ( empty( $parts ) && ! empty( $gaps['notes'] ) ) {
				$parts = $gaps['notes'];
			}

			$details[] = sprintf(
				'#%1$d: %2$s',
				$post_id,
				get_the_title( $post_id ) ?: __( '(بدون عنوان)', 'polymart-ai' )
			) . ( ! empty( $parts ) ? ' — ' . implode( ', ', $parts ) : '' );
		}

		if ( '' === $template ) {
			$template = __( 'ترجمه متوقف شد — %1$d مورد ناقص: %2$s. «توقف کامل» و «شروع» مجدد بزنید یا فیلدها را دستی تکمیل کنید.', 'polymart-ai' );
		}

		$summary = implode( ' | ', $details );

		if ( count( $remaining_ids ) > count( $details ) ) {
			$summary .= sprintf(
				/* translators: %d: additional incomplete post count */
				__( ' … +%d مورد دیگر', 'polymart-ai' ),
				count( $remaining_ids ) - count( $details )
			);
		}

		$total_incomplete = max(
			count( $remaining_ids ),
			Translation_Query::count_untranslated_persian_posts( $lang )
		);

		return sprintf(
			$template,
			$total_incomplete,
			$summary
		);
	}

	/**
	 * Queue a failed post for retry when attempts remain.
	 *
	 * @param array<string, mixed> $job     Job state (by reference).
	 * @param int                  $post_id Failed post ID.
	 * @return void
	 */
	private static function enqueue_job_retry( array &$job, $post_id ) {
		$post_id        = absint( $post_id );
		$lang           = sanitize_key( (string) ( $job['lang'] ?? '' ) );
		$retry_queue    = self::get_retry_queue( $job );
		$retry_attempts = is_array( $job['retry_attempts'] ?? null ) ? $job['retry_attempts'] : array();
		$attempts       = (int) ( $retry_attempts[ $post_id ] ?? 0 ) + 1;
		$max_attempts   = self::get_max_job_retries_for_post( $post_id, $lang );

		$retry_attempts[ $post_id ] = $attempts;
		$job['retry_attempts']      = $retry_attempts;

		if ( $attempts >= $max_attempts ) {
			$partial = Post_Translator::get_job_partial_state( $post_id, $lang );
			$phase   = sanitize_key( (string) ( $partial['phase'] ?? '' ) );

			if ( '' !== $phase && in_array( $phase, array( 'core', 'commerce', 'variations', 'elementor', 'fields' ), true ) ) {
				if ( ! in_array( $post_id, $retry_queue, true ) ) {
					$retry_queue[] = $post_id;
				}

				$job['retry_queue'] = $retry_queue;

				return;
			}

			$pending = array();
			$post    = get_post( $post_id );

			if ( $post instanceof \WP_Post ) {
				$pending = Post_Translator::collect_persian_fields( $post, $lang );
			}

			if ( ! empty( $pending ) ) {
				if ( ! in_array( $post_id, $retry_queue, true ) ) {
					$retry_queue[] = $post_id;
				}

				$job['retry_queue'] = $retry_queue;

				return;
			}

			$exhausted = is_array( $job['exhausted_ids'] ?? null ) ? $job['exhausted_ids'] : array();

			if ( ! in_array( $post_id, $exhausted, true ) ) {
				$exhausted[] = $post_id;
			}

			$job['exhausted_ids'] = array_values( array_map( 'absint', $exhausted ) );
			$job['retry_queue']   = array_values(
				array_filter(
					self::get_retry_queue( $job ),
					static function ( $queued_id ) use ( $post_id ) {
						return (int) $queued_id !== (int) $post_id;
					}
				)
			);

			return;
		}

		if ( ! in_array( $post_id, $retry_queue, true ) ) {
			$retry_queue[] = $post_id;
		}

		$job['retry_queue'] = $retry_queue;
	}

	/**
	 * Handle a failed translation step.
	 *
	 * @param array<string, mixed> $job        Job state.
	 * @param int                  $post_id    Post ID.
	 * @param string               $lang       Target language code.
	 * @param \WP_Error            $result     Translation error.
	 * @param bool                 $from_retry Whether this post came from the retry queue.
	 * @return array<string, mixed>
	 */
	private static function handle_job_translation_failure( array $job, $post_id, $lang, \WP_Error $result, $from_retry ) {
		$error_message          = $result->get_error_message();
		$job['last_error']      = $error_message;
		self::increment_job_step( $job );
		$job['current_post_id'] = null;
		$job['step_started_at'] = null;
		self::set_job_last_step( $job, $post_id, 'failed', $error_message );

		if ( ! $from_retry ) {
			$job['last_post_id'] = $post_id;
		}

		self::log(
			'error',
			sprintf(
				/* translators: 1: post ID, 2: error message */
				__( 'خطا در ترجمه مورد #%1$d: %2$s', 'polymart-ai' ),
				$post_id,
				$error_message
			),
			array( 'post_id' => $post_id, 'lang' => $lang )
		);

		if ( self::is_critical_api_error( $result ) ) {
			$job['failed']++;
			$job['status']       = 'paused';
			$job['pause_reason'] = 'critical';
			self::save_job( $job );
			self::set_admin_notice(
				sprintf(
					/* translators: %s: error message */
					__( 'ترجمه خودکار متوقف شد: %s', 'polymart-ai' ),
					$error_message
				),
				'error'
			);
			return $job;
		}

		self::enqueue_job_retry( $job, $post_id );

		if ( in_array( $post_id, self::get_exhausted_job_post_ids( $job ), true ) ) {
			$job['failed']++;
		}

		$retry_queue = self::get_retry_queue( $job );

		if ( ! self::job_has_pending_work( $lang, (int) $job['last_post_id'], $retry_queue, $job ) ) {
			self::finalize_job_if_queue_exhausted( $job, $lang );
		} else {
			self::sync_job_remaining( $job, $lang );
		}

		self::save_job( $job );

		return $job;
	}

	/**
	 * Handle a successful translation step.
	 *
	 * @param array<string, mixed>              $job    Job state.
	 * @param int                                 $post_id Post ID.
	 * @param string                              $lang   Target language code.
	 * @param array{translations: array<string, string>} $result Translation result.
	 * @return array<string, mixed>
	 */
	private static function handle_job_translation_success( array $job, $post_id, $lang, array $result, $already_saved = false ) {
		if ( ! $already_saved ) {
			$save_result = Post_Translator::save_ai_translations( $post_id, $result['translations'], $lang );

			if ( is_wp_error( $save_result ) ) {
				return self::handle_job_translation_failure( $job, $post_id, $lang, $save_result, false );
			}
		}

		REST_API::invalidate_stats_cache();
		clean_post_cache( $post_id );
		Post_Translator::flush_translation_status_cache( $post_id );

		$title_source     = get_the_title( $post_id );
		$title_translated = (string) get_post_meta( $post_id, Post_Translator::get_meta_key( 'title', $lang ), true );

		self::log_report( $post_id, $lang, $title_source, $title_translated, 'auto' );

		$status = self::resolve_post_job_status( $post_id, $lang );

		if ( 'translated' === $status ) {
			self::track_succeeded_post( $job, $post_id );
			self::clear_partial_post( $job, $post_id );
		} else {
			self::untrack_succeeded_post( $job, $post_id );
			self::track_partial_post( $job, $post_id );
			self::defer_job_post( $job, $post_id, $lang );

			$gaps     = Post_Translator::get_translation_gaps( $post_id, $lang );
			$attempts = (int) ( $job['retry_attempts'][ $post_id ] ?? 0 );
			$missing  = ! empty( $gaps['missing'] ) ? implode( ', ', $gaps['missing'] ) : __( 'نامشخص', 'polymart-ai' );

			self::log(
				'warning',
				sprintf(
					/* translators: 1: post title, 2: missing fields */
					__( '«%1$s» هنوز ناقص است — به صف بعدی منتقل شد. باقی‌مانده: %2$s', 'polymart-ai' ),
					$title_source,
					$missing
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);
		}

		self::increment_job_step( $job );
		$job['last_post_id']    = $post_id;
		$job['current_post_id'] = null;
		$job['step_started_at'] = null;
		$job['last_error']      = null;

		if ( 'translated' === $status ) {
			$retry_attempts = is_array( $job['retry_attempts'] ?? null ) ? $job['retry_attempts'] : array();
			unset( $retry_attempts[ $post_id ] );
			$job['retry_attempts'] = $retry_attempts;
		}

		// Keep the current post in the retry queue only while it is still incomplete.
		// (Previous logic was inverted and dropped partial posts immediately.)
		$retry_queue = array_values(
			array_filter(
				self::get_retry_queue( $job ),
				static function ( $queued_id ) use ( $post_id, $status ) {
					if ( (int) $queued_id !== (int) $post_id ) {
						return true;
					}

					return 'translated' !== $status;
				}
			)
		);
		$job['retry_queue'] = $retry_queue;

		if ( 'translated' === $status ) {
			self::log(
				'success',
				sprintf(
					/* translators: 1: post title, 2: language label */
					__( '«%1$s» به %2$s ترجمه شد.', 'polymart-ai' ),
					$title_source,
					$job['lang_label'] ?? $lang
				),
				array( 'post_id' => $post_id, 'lang' => $lang )
			);
		}

		if ( ! self::job_has_pending_work( $lang, $post_id, $retry_queue, $job ) ) {
			self::finalize_job_if_queue_exhausted( $job, $lang );

			if ( 'completed' === ( $job['status'] ?? '' ) && empty( $job['last_error'] ) ) {
				self::log( 'success', __( 'ترجمه خودکار با موفقیت به پایان رسید.', 'polymart-ai' ) );
			} elseif ( ! empty( $job['last_error'] ) && 'completed' === ( $job['status'] ?? '' ) ) {
				self::log( 'warning', (string) $job['last_error'] );
			}
		} else {
			self::sync_job_remaining( $job, $lang );
		}

		if ( 'translated' === $status ) {
			self::set_job_last_step(
				$job,
				$post_id,
				'translated',
				sprintf(
					/* translators: 1: post title, 2: language label */
					__( '«%1$s» به %2$s ترجمه شد.', 'polymart-ai' ),
					$title_source,
					$job['lang_label'] ?? $lang
				)
			);
		} else {
			self::set_job_last_step(
				$job,
				$post_id,
				'partial',
				sprintf(
					/* translators: 1: post title, 2: missing fields */
					__( '«%1$s» ناقص ماند — %2$s', 'polymart-ai' ),
					$title_source,
					! empty( $gaps['missing'] ) ? implode( ', ', $gaps['missing'] ) : __( 'نامشخص', 'polymart-ai' )
				)
			);
		}

		// Full-site scans are expensive; refresh at most every few seconds.
		$live_age = time() - (int) ( $job['live_stats_at'] ?? 0 );
		self::sync_job_live_stats( $job, $lang, $live_age >= 5 );
		self::save_job( $job );

		return $job;
	}

	/**
	 * Execute one menu translation step after post content is complete.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return array<string, mixed>
	 */
	private static function run_menu_job_step( array $job ) {
		$lang       = (string) $job['lang'];
		$cursor     = (int) ( $job['last_menu_id'] ?? 0 );
		$exclude    = self::get_exhausted_job_post_ids( $job );
		$item_id    = Menu_Translator::find_next_untranslated_id( $lang, $cursor, $exclude );

		if ( $item_id <= 0 ) {
			$job['phase']           = 'posts';
			$job['step_started_at'] = null;
			$job['current_post_id'] = null;
			$job['menu_remaining']  = 0;
			self::finalize_job_if_queue_exhausted( $job, $lang );
			self::save_job( $job );

			if ( 'completed' === ( $job['status'] ?? '' ) ) {
				self::log( 'success', __( 'ترجمه خودکار با موفقیت به پایان رسید.', 'polymart-ai' ) );
			}

			return self::normalize_job_for_response( $job, false );
		}

		$job['current_post_id'] = $item_id;
		$job['step_started_at'] = time();
		self::save_job( $job );

		$result = Menu_Translator::request_ai_translation( $item_id, $lang );

		if ( is_wp_error( $result ) ) {
			$error_message = $result->get_error_message();
			$attempts      = (int) ( $job['retry_attempts'][ $item_id ] ?? 0 ) + 1;
			$job['retry_attempts'][ $item_id ] = $attempts;

			if ( self::is_critical_api_error( $result ) ) {
				$job['status']          = 'paused';
				$job['pause_reason']    = 'critical';
				$job['current_post_id'] = null;
				$job['step_started_at'] = null;
				$job['last_error']      = $error_message;
				self::save_job( $job );
				self::log( 'error', $error_message );

				return self::normalize_job_for_response( $job, false );
			}

			if ( $attempts >= self::MAX_JOB_RETRIES ) {
				$exhausted   = is_array( $job['exhausted_ids'] ?? null ) ? $job['exhausted_ids'] : array();
				$exhausted[] = $item_id;
				$job['exhausted_ids'] = array_values( array_unique( array_map( 'absint', $exhausted ) ) );
				$job['failed']++;
			} else {
				$job['retry_queue'] = array_values(
					array_unique(
						array_merge( self::get_retry_queue( $job ), array( $item_id ) )
					)
				);
			}

			self::increment_job_step( $job );
			$job['last_menu_id']    = $item_id;
			$job['current_post_id'] = null;
			$job['step_started_at'] = null;
			$job['last_error']      = $error_message;
			$job['menu_remaining']  = Menu_Translator::count_untranslated( $lang );
			self::sync_job_remaining( $job, $lang );
			self::set_job_last_step(
				$job,
				$item_id,
				'failed',
				sprintf(
					/* translators: 1: menu label, 2: error message */
					__( 'منو «%1$s» — %2$s', 'polymart-ai' ),
					get_the_title( $item_id ),
					$error_message
				)
			);
			self::save_job( $job );

			return self::normalize_job_for_response( $job, false );
		}

		self::increment_job_step( $job );
		$job['last_menu_id']    = $item_id;
		$job['current_post_id'] = null;
		$job['step_started_at'] = null;
		$job['last_error']      = null;
		$job['menu_remaining']  = Menu_Translator::count_untranslated( $lang );
		$job['succeeded']       = (int) ( $job['succeeded'] ?? 0 ) + 1;

		unset( $job['retry_attempts'][ $item_id ] );
		$job['retry_queue'] = array_values(
			array_filter(
				self::get_retry_queue( $job ),
				static function ( $queued_id ) use ( $item_id ) {
					return (int) $queued_id !== (int) $item_id;
				}
			)
		);

		self::log(
			'success',
			sprintf(
				/* translators: 1: Persian menu label, 2: translated label, 3: language */
				__( 'منو «%1$s» → «%2$s» (%3$s)', 'polymart-ai' ),
				$result['source'],
				$result['title'],
				$job['lang_label'] ?? $lang
			),
			array( 'post_id' => $item_id, 'lang' => $lang )
		);

		self::set_job_last_step(
			$job,
			$item_id,
			'translated',
			sprintf(
				/* translators: 1: Persian menu label, 2: translated label */
				__( 'منو «%1$s» → «%2$s»', 'polymart-ai' ),
				$result['source'],
				$result['title']
			)
		);

		self::sync_job_remaining( $job, $lang );
		self::save_job( $job );

		return self::normalize_job_for_response( $job, false );
	}

	/**
	 * Whether an API error should pause the job and alert admin.
	 *
	 * @param \WP_Error $error Error object.
	 * @return bool
	 */
	public static function is_critical_api_error( \WP_Error $error ) {
		$code    = $error->get_error_code();
		$message = strtolower( $error->get_error_message() );

		$critical_codes = array(
			'polymart_ai_missing_credentials',
			'polymart_ai_missing_api_key',
			'polymart_ai_missing_endpoint',
		);

		if ( in_array( $code, $critical_codes, true ) ) {
			return true;
		}

		$critical_keywords = array(
			'quota',
			'rate limit',
			'insufficient',
			'unauthorized',
			'invalid api key',
			'authentication',
			'billing',
			'توکن',
			'اعتبار',
			'سهمیه',
			'کلید api',
		);

		foreach ( $critical_keywords as $keyword ) {
			if ( false !== strpos( $message, $keyword ) ) {
				return true;
			}
		}

		return false;
	}
}
