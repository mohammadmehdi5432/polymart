<?php
/**
 * Activity_Logger Reports (auto-split).
 *
 * @package PolymartAI\Activity_Logger\Traits
 */

namespace PolymartAI\Activity_Logger\Traits\Reports;

use PolymartAI\Activity_Logger\Job_Action_Scheduler;
use PolymartAI\Activity_Logger\Metabox_Action_Scheduler;
use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\Content\Menu_Translator;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Pipeline\Translation_Query;


defined( 'ABSPATH' ) || exit;

trait Trait_Reports {

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

	public static function get_reports( $limit = 100, $lang = '', $offset = 0 ) {
		$reports = self::filter_reports( $lang );

		return array_slice( $reports, max( 0, absint( $offset ) ), max( 1, absint( $limit ) ) );
	}

	public static function get_reports_page( $limit = 25, $lang = '', $offset = 0 ) {
		$reports = self::filter_reports( $lang );

		return array(
			'items' => array_slice( $reports, max( 0, absint( $offset ) ), max( 1, absint( $limit ) ) ),
			'total' => count( $reports ),
		);
	}

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

}
