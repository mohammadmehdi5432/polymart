<?php
/**
 * Detect wp-admin infrastructure requests that must stay untouched by storefront hooks.
 *
 * @package PolymartAI
 */

namespace PolymartAI;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin_Request_Guard
 */
final class Admin_Request_Guard {

	/**
	 * WordPress admin AJAX actions used by the media library / uploads.
	 *
	 * @var string[]
	 */
	private const MEDIA_AJAX_ACTIONS = array(
		'bulk-edit-attachment',
		'get-attachment',
		'get-attachment-compat',
		'get-media-item',
		'get-post-thumbnail-html',
		'heartbeat',
		'image-editor',
		'parse-media-shortcode',
		'query-attachments',
		'save-attachment',
		'save-attachment-compat',
		'save-attachment-order',
		'send-attachment-to-editor',
		'send-link-to-editor',
		'set-attachment-thumbnail',
		'set-post-thumbnail',
		'upload-attachment',
		'wp-compression-test',
	);

	/**
	 * Whether this request is a core admin asset loader (load-scripts.php, etc.).
	 *
	 * @return bool
	 */
	public static function is_infrastructure_request() {
		if ( empty( $_SERVER['SCRIPT_NAME'] ) ) {
			return false;
		}

		$script = basename( wp_unslash( (string) $_SERVER['SCRIPT_NAME'] ) );

		return in_array(
			$script,
			array(
				'async-upload.php',
				'load-scripts.php',
				'load-styles.php',
			),
			true
		);
	}

	/**
	 * Whether the current admin-ajax request is a core media/system action.
	 *
	 * @return bool
	 */
	public static function is_admin_system_ajax() {
		if ( ! is_admin() || ! wp_doing_ajax() ) {
			return false;
		}

		$action = self::get_admin_ajax_action();

		if ( '' === $action ) {
			return true;
		}

		return in_array( $action, self::MEDIA_AJAX_ACTIONS, true );
	}

	/**
	 * Whether the current request is a REST media endpoint.
	 *
	 * @return bool
	 */
	public static function is_rest_media_request() {
		if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] )
			? (string) $GLOBALS['wp']->query_vars['rest_route']
			: '';

		if ( '' === $route && isset( $_SERVER['REQUEST_URI'] ) ) {
			$path = wp_parse_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
			$route = is_string( $path ) ? $path : '';
		}

		return false !== strpos( $route, '/wp/v2/media' );
	}

	/**
	 * Storefront/locale hooks must not run on these requests.
	 *
	 * @return bool
	 */
	public static function should_isolate() {
		return self::is_infrastructure_request()
			|| self::is_admin_system_ajax()
			|| self::is_rest_media_request();
	}

	/**
	 * Current admin-ajax action.
	 *
	 * @return string
	 */
	private static function get_admin_ajax_action() {
		if ( ! isset( $_REQUEST['action'] ) ) {
			return '';
		}

		return sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) );
	}
}
