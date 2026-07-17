<?php
/**
 * Batch apply job for translation corrections.
 *
 * @package PolymartAI\Translation\Correction
 */

namespace PolymartAI\Translation\Correction;

use PolymartAI\Activity_Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class Correction_Job
 */
final class Correction_Job {

	/**
	 * Option key.
	 */
	const OPTION = 'polymart_ai_correction_job';

	/**
	 * Default items per step.
	 */
	const DEFAULT_STEP = 15;

	/**
	 * Max items per step.
	 */
	const MAX_STEP = 25;

	/**
	 * Max queued matches.
	 */
	const MAX_QUEUE = 500;

	/**
	 * Get current job state.
	 *
	 * @param bool $public When true, omit the heavy queue payload.
	 * @return array<string, mixed>
	 */
	public static function get( $public = false ) {
		$raw = get_option( self::OPTION, array() );

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		return self::normalize_state( $raw, (bool) $public );
	}

	/**
	 * Clear job.
	 *
	 * @return array<string, mixed>
	 */
	public static function clear() {
		delete_option( self::OPTION );

		return self::normalize_state( array(), true );
	}

	/**
	 * Start a new apply job from preview matches.
	 *
	 * @param array<string, mixed> $args Args.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function start( array $args ) {
		$lang          = sanitize_key( (string) ( $args['lang'] ?? '' ) );
		$find          = is_string( $args['find'] ?? null ) ? (string) $args['find'] : '';
		$replace       = is_string( $args['replace'] ?? null ) ? (string) $args['replace'] : '';
		$mode          = isset( $args['mode'] ) && 'contains' === $args['mode'] ? 'contains' : 'exact';
		$word_boundary = ! empty( $args['word_boundary'] );
		$save_glossary = ! isset( $args['save_glossary'] ) || ! empty( $args['save_glossary'] );
		$matches       = isset( $args['matches'] ) && is_array( $args['matches'] ) ? $args['matches'] : array();

		$valid = Correction_Text::validate_pair( $find, $replace, $mode, $word_boundary );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( '' === $lang ) {
			return new \WP_Error(
				'polymart_ai_correction_lang',
				__( 'زبان مقصد را انتخاب کنید.', 'polymart-ai' )
			);
		}

		if ( empty( $matches ) ) {
			return new \WP_Error(
				'polymart_ai_correction_no_matches',
				__( 'موردی برای اعمال انتخاب نشده است. ابتدا پیش‌نمایش بگیرید.', 'polymart-ai' )
			);
		}

		$queue = array();

		foreach ( $matches as $match ) {
			if ( ! is_array( $match ) || empty( $match['id'] ) || empty( $match['scope'] ) ) {
				continue;
			}

			$match['lang'] = $lang;
			$queue[]       = $match;

			if ( count( $queue ) >= self::MAX_QUEUE ) {
				break;
			}
		}

		if ( empty( $queue ) ) {
			return new \WP_Error(
				'polymart_ai_correction_no_matches',
				__( 'مورد معتبری برای اعمال وجود ندارد.', 'polymart-ai' )
			);
		}

		if ( $save_glossary ) {
			Correction_Glossary::upsert( $lang, $find, $replace, $mode );
		}

		$state = array(
			'status'         => 'running',
			'lang'           => $lang,
			'find'           => $find,
			'replace'        => $replace,
			'mode'           => $mode,
			'word_boundary'  => (bool) $word_boundary,
			'save_glossary'  => (bool) $save_glossary,
			'queue'          => $queue,
			'cursor'         => 0,
			'total'          => count( $queue ),
			'done'           => 0,
			'replacements'   => 0,
			'errors'         => array(),
			'started_at'     => time(),
			'updated_at'     => time(),
		);

		update_option( self::OPTION, $state, false );

		Activity_Logger::log(
			'info',
			sprintf(
				/* translators: 1: count, 2: language */
				__( 'تصحیح ترجمه شروع شد — %1$d مورد (%2$s)', 'polymart-ai' ),
				count( $queue ),
				$lang
			),
			array(
				'lang'   => $lang,
				'source' => 'correction',
			)
		);

		return self::normalize_state( $state, true );
	}

	/**
	 * Process the next batch.
	 *
	 * @param int $batch Batch size.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function step( $batch = self::DEFAULT_STEP ) {
		$state = self::get( false );

		if ( 'idle' === $state['status'] || empty( $state['queue'] ) ) {
			$state['status'] = 'idle';
			$state['queue']  = array();
			update_option( self::OPTION, $state, false );

			return self::normalize_state( $state, true );
		}

		if ( 'completed' === $state['status'] || 'failed' === $state['status'] ) {
			return self::normalize_state( $state, true );
		}

		$batch  = min( self::MAX_STEP, max( 1, absint( $batch ) ) );
		$cursor = absint( $state['cursor'] );
		$queue  = is_array( $state['queue'] ) ? $state['queue'] : array();
		$total  = count( $queue );
		$end    = min( $total, $cursor + $batch );

		for ( $i = $cursor; $i < $end; $i++ ) {
			$match = isset( $queue[ $i ] ) && is_array( $queue[ $i ] ) ? $queue[ $i ] : null;

			if ( null === $match ) {
				continue;
			}

			$result = Correction_Applier::apply_match(
				$match,
				(string) $state['find'],
				(string) $state['replace'],
				(string) $state['mode'],
				! empty( $state['word_boundary'] )
			);

			if ( is_wp_error( $result ) ) {
				$errors   = is_array( $state['errors'] ) ? $state['errors'] : array();
				$errors[] = array(
					'id'      => (string) ( $match['id'] ?? '' ),
					'message' => $result->get_error_message(),
				);
				$state['errors'] = array_slice( $errors, -30 );
				continue;
			}

			$state['done']         = absint( $state['done'] ) + 1;
			$state['replacements'] = absint( $state['replacements'] ) + absint( $result['replacements'] ?? 0 );
		}

		$state['cursor']     = $end;
		$state['updated_at'] = time();
		$state['status']     = $end >= $total ? 'completed' : 'running';
		$state['queue']      = $queue;

		if ( 'completed' === $state['status'] ) {
			Activity_Logger::log(
				'success',
				sprintf(
					/* translators: 1: done count, 2: replacements */
					__( 'تصحیح ترجمه تمام شد — %1$d مورد، %2$d جایگزینی', 'polymart-ai' ),
					absint( $state['done'] ),
					absint( $state['replacements'] )
				),
				array(
					'lang'   => (string) $state['lang'],
					'source' => 'correction',
				)
			);
		}

		update_option( self::OPTION, $state, false );

		return self::normalize_state( $state, true );
	}

	/**
	 * @param array<string, mixed> $state  Raw.
	 * @param bool                 $public Strip queue.
	 * @return array<string, mixed>
	 */
	private static function normalize_state( array $state, $public = false ) {
		$status = sanitize_key( (string) ( $state['status'] ?? 'idle' ) );

		if ( ! in_array( $status, array( 'idle', 'running', 'completed', 'failed' ), true ) ) {
			$status = 'idle';
		}

		$queue  = isset( $state['queue'] ) && is_array( $state['queue'] ) ? $state['queue'] : array();
		$total  = isset( $state['total'] ) ? absint( $state['total'] ) : count( $queue );
		$done   = absint( $state['done'] ?? 0 );
		$cursor = absint( $state['cursor'] ?? 0 );

		$out = array(
			'status'          => $status,
			'lang'            => sanitize_key( (string) ( $state['lang'] ?? '' ) ),
			'find'            => is_string( $state['find'] ?? null ) ? (string) $state['find'] : '',
			'replace'         => is_string( $state['replace'] ?? null ) ? (string) $state['replace'] : '',
			'mode'            => isset( $state['mode'] ) && 'contains' === $state['mode'] ? 'contains' : 'exact',
			'word_boundary'   => ! empty( $state['word_boundary'] ),
			'save_glossary'   => ! empty( $state['save_glossary'] ),
			'total'           => $total,
			'done'            => $done,
			'cursor'          => $cursor,
			'replacements'    => absint( $state['replacements'] ?? 0 ),
			'errors'          => isset( $state['errors'] ) && is_array( $state['errors'] ) ? array_values( $state['errors'] ) : array(),
			'progress'        => $total > 0 ? min( 100, (int) round( ( $cursor / $total ) * 100 ) ) : 0,
			'started_at'      => absint( $state['started_at'] ?? 0 ),
			'updated_at'      => absint( $state['updated_at'] ?? 0 ),
			'queue_remaining' => max( 0, $total - $cursor ),
			'has_queue'       => $total > 0,
		);

		if ( ! $public ) {
			$out['queue'] = $queue;
		}

		return $out;
	}
}
