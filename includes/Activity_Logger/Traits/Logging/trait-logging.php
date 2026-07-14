<?php
/**
 * Activity_Logger Logging (auto-split).
 *
 * @package PolymartAI\Activity_Logger\Traits
 */

namespace PolymartAI\Activity_Logger\Traits\Logging;

use PolymartAI\Activity_Logger\Job_Action_Scheduler;
use PolymartAI\Activity_Logger\Metabox_Action_Scheduler;
use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\Content\Menu_Translator;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Pipeline\Translation_Query;


defined( 'ABSPATH' ) || exit;

trait Trait_Logging {

	public static function log( $level, $message, array $context = array() ) {
		$message = sanitize_text_field( (string) $message );
		$level   = sanitize_key( (string) $level );
		$logs    = get_option( self::LOG_OPTION, array() );
		$logs    = is_array( $logs ) ? $logs : array();

		// Suppress duplicate success/info lines within 45s (concurrent workers).
		$post_id = absint( $context['post_id'] ?? 0 );
		$now     = time();
		$count   = count( $logs );

		for ( $i = $count - 1; $i >= max( 0, $count - 15 ); $i-- ) {
			$entry = $logs[ $i ];

			if ( ! is_array( $entry ) ) {
				continue;
			}

			$entry_time = absint( $entry['time'] ?? 0 );

			if ( $entry_time > 0 && ( $now - $entry_time ) > 45 ) {
				break;
			}

			$same_message = (string) ( $entry['message'] ?? '' ) === $message;
			$same_post    = $post_id <= 0 || absint( $entry['context']['post_id'] ?? 0 ) === $post_id;

			if ( $same_message && $same_post ) {
				return;
			}
		}

		$logs[] = array(
			'id'      => uniqid( 'log_', true ),
			'level'   => $level,
			'message' => $message,
			'context' => $context,
			'time'    => $now,
		);

		if ( count( $logs ) > self::MAX_LOG_ENTRIES ) {
			$logs = array_slice( $logs, -self::MAX_LOG_ENTRIES );
		}

		update_option( self::LOG_OPTION, $logs, false );

		if ( 'error' === $level ) {
			self::set_admin_notice( $message, 'error' );
		}
	}

	public static function get_logs( $limit = 100, $level = '', $offset = 0 ) {
		$logs = self::filter_logs( $level );

		return array_slice( $logs, max( 0, absint( $offset ) ), max( 1, absint( $limit ) ) );
	}

	public static function get_logs_page( $limit = 25, $level = '', $offset = 0 ) {
		$logs = self::filter_logs( $level );

		return array(
			'items' => array_slice( $logs, max( 0, absint( $offset ) ), max( 1, absint( $limit ) ) ),
			'total' => count( $logs ),
		);
	}

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

	public static function clear_logs() {
		delete_option( self::LOG_OPTION );
	}

}
