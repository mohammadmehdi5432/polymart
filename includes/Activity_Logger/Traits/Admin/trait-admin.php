<?php
/**
 * Activity_Logger Admin (auto-split).
 *
 * @package PolymartAI\Activity_Logger\Traits
 */

namespace PolymartAI\Activity_Logger\Traits\Admin;

use PolymartAI\Activity_Logger\Job_Action_Scheduler;
use PolymartAI\Activity_Logger\Metabox_Action_Scheduler;
use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\Content\Menu_Translator;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Pipeline\Translation_Query;


defined( 'ABSPATH' ) || exit;

trait Trait_Admin {

	public static function register_cron_schedules( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		$schedules[ self::CRON_PULSE_SCHEDULE ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'هر دقیقه (کارگر ترجمه پلی‌مارت)', 'polymart-ai' ),
		);

		return $schedules;
	}

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

}
