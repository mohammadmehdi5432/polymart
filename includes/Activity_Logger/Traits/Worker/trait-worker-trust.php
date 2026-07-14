<?php
/**
 * Activity_Logger Worker_Trust (auto-split).
 *
 * @package PolymartAI\Activity_Logger\Traits
 */

namespace PolymartAI\Activity_Logger\Traits\Worker;

use PolymartAI\Activity_Logger\Job_Action_Scheduler;
use PolymartAI\Activity_Logger\Metabox_Action_Scheduler;
use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\Content\Menu_Translator;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Pipeline\Translation_Query;


defined( 'ABSPATH' ) || exit;

trait Trait_Worker_Trust {

	public static function is_trusted_job_worker() {
		if (
			wp_doing_cron()
			|| self::$trusted_loopback_tick
			|| self::$trusted_job_tick
			|| self::$trusted_as_tick
			|| self::$trusted_metabox_as_tick
		) {
			return true;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( wp_doing_ajax()
			&& isset( $_REQUEST['action'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& self::LOOPBACK_ACTION === sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) )
		) {
			return true;
		}

		return false;
	}

	/**
	 * True while an Action Scheduler slice (or equivalent bounded worker tick) is running.
	 */
	public static function is_trusted_as_tick() {
		return (bool) self::$trusted_as_tick;
	}

	public static function should_bypass_browser_auth_checks() {
		return self::is_trusted_job_worker();
	}

	public static function begin_metabox_as_worker( $post_id, $from_as_callback = false ) {
		if ( ! $from_as_callback ) {
			return;
		}

		self::$trusted_metabox_as_tick = true;
		self::$trusted_as_tick         = true;
		self::$trusted_job_tick        = true;

		self::impersonate_job_worker_user( absint( $post_id ) );
	}

	public static function end_metabox_as_worker() {
		self::$trusted_metabox_as_tick = false;
		self::$trusted_as_tick         = false;
		self::$trusted_job_tick        = false;
	}

	public static function bootstrap_job_worker_context( $post_id = 0 ) {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return;
		}

		if ( get_current_user_id() > 0 ) {
			return;
		}

		if ( ! self::is_trusted_job_worker() && ! self::is_bulk_job_running() ) {
			return;
		}

		self::impersonate_job_worker_user( absint( $post_id ) );
	}

	private static function impersonate_job_worker_user( $post_id = 0 ) {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		if ( ! function_exists( 'wp_get_current_user' ) || get_current_user_id() > 0 ) {
			return;
		}

		$user_id = self::resolve_job_worker_user_id( $post_id );

		if ( $user_id > 0 ) {
			wp_set_current_user( $user_id );
		}
	}

	private static function resolve_job_worker_user_id( $post_id = 0 ) {
		$post_id = absint( $post_id );

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );

			if ( $post instanceof \WP_Post ) {
				$author_id = absint( $post->post_author );

				if ( $author_id > 0 && user_can( $author_id, 'edit_post', $post_id ) ) {
					return $author_id;
				}
			}
		}

		$user_id = absint( get_option( 'polymart_ai_job_worker_user_id', 0 ) );

		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			$admins = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				)
			);

			$user_id = ! empty( $admins ) ? absint( $admins[0] ) : 0;

			if ( $user_id > 0 ) {
				update_option( 'polymart_ai_job_worker_user_id', $user_id, false );
			}
		}

		return $user_id;
	}

}
