<?php
/**
 * Production diagnostic tool for PolyMartAI translation issues.
 *
 * Usage: Access via /wp-admin/admin.php?page=polymart-production-debug
 * Or run via WP-CLI: wp eval-file tools/production-debug.php
 *
 * @package PolymartAI
 */

namespace PolymartAI;

defined( 'ABSPATH' ) || exit;

/**
 * Production debug helper.
 */
class Production_Debug {

	/**
	 * Register admin menu.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_debug_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_debug_actions' ) );
	}

	/**
	 * Add debug menu item.
	 */
	public static function add_debug_menu() {
		add_submenu_page(
			'polymart-ai',
			__( 'Production Debug', 'polymart-ai' ),
			__( 'Production Debug', 'polymart-ai' ),
			'manage_options',
			'polymart-production-debug',
			array( __CLASS__, 'render_debug_page' )
		);
	}

	/**
	 * Handle debug actions.
	 */
	public static function handle_debug_actions() {
		if ( ! isset( $_GET['page'] ) || 'polymart-production-debug' !== $_GET['page'] ) {
			return;
		}

		if ( ! isset( $_GET['action'] ) || ! check_admin_referer( 'polymart_debug_action' ) ) {
			return;
		}

		$action = sanitize_key( $_GET['action'] );

		switch ( $action ) {
			case 'clear_cron':
				self::clear_stuck_cron_jobs();
				break;
			case 'reset_ui_job':
				self::reset_ui_translation_job();
				break;
			case 'clear_pending_queue':
				self::clear_pending_queue();
				break;
			case 'test_api':
				self::test_api_connection();
				break;
		}

		wp_redirect( admin_url( 'admin.php?page=polymart-production-debug' ) );
		exit;
	}

	/**
	 * Clear stuck WP-Cron jobs.
	 */
	private static function clear_stuck_cron_jobs() {
		wp_clear_scheduled_hook( 'polymart_ai_async_translate_post' );
		wp_clear_scheduled_hook( 'polymart_ai_async_translate_term' );
		wp_clear_scheduled_hook( 'polymart_ai_translate_pending_strings' );
		
		update_option( 'polymart_debug_last_action', 'Cron jobs cleared at ' . current_time( 'mysql' ) );
	}

	/**
	 * Reset UI translation job.
	 */
	private static function reset_ui_translation_job() {
		delete_option( 'polymart_ai_ui_string_job' );
		update_option( 'polymart_debug_last_action', 'UI translation job reset at ' . current_time( 'mysql' ) );
	}

	/**
	 * Clear pending queue.
	 */
	private static function clear_pending_queue() {
		delete_option( 'polymart_ai_pending_strings' );
		update_option( 'polymart_debug_last_action', 'Pending queue cleared at ' . current_time( 'mysql' ) );
	}

	/**
	 * Test API connection.
	 */
	private static function test_api_connection() {
		$settings = get_option( 'polymart_ai_settings', array() );
		$api_key = $settings['translation']['api_key'] ?? '';
		$endpoint = $settings['translation']['api_endpoint'] ?? '';
		$model = $settings['translation']['ai_model'] ?? '';

		if ( empty( $api_key ) || empty( $endpoint ) ) {
			update_option( 'polymart_debug_last_action', 'API test failed: Missing credentials' );
			return;
		}

		$result = \PolymartAI\Translation\AI_Client::test_connection( $api_key, $endpoint, $model );

		if ( is_wp_error( $result ) ) {
			update_option( 'polymart_debug_last_action', 'API test failed: ' . $result->get_error_message() );
		} else {
			update_option( 'polymart_debug_last_action', 'API test successful at ' . current_time( 'mysql' ) );
		}
	}

	/**
	 * Render debug page.
	 */
	public static function render_debug_page() {
		$cron_status = self::get_cron_status();
		$ui_job = get_option( 'polymart_ai_ui_string_job', array() );
		$pending_count = \PolymartAI\Translation\Runtime_String_Translator::get_pending_queue_count();
		$queue_status = \PolymartAI\Translation\Async_Translator::get_queue_status();
		$last_action = get_option( 'polymart_debug_last_action', '' );
		$settings = get_option( 'polymart_ai_settings', array() );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PolyMartAI Production Debug', 'polymart-ai' ); ?></h1>
			
			<?php if ( $last_action ) : ?>
				<div class="notice notice-info is-dismissible">
					<p><?php echo esc_html( $last_action ); ?></p>
				</div>
			<?php endif; ?>

			<div class="card" style="max-width: 800px; margin-top: 20px;">
				<h2><?php esc_html_e( 'System Status', 'polymart-ai' ); ?></h2>
				<table class="widefat">
					<tr>
						<th><?php esc_html_e( 'WP-Cron Disabled', 'polymart-ai' ); ?></th>
						<td><?php echo defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? '<span style="color: orange;">Yes (Manual cron required)</span>' : '<span style="color: green;">No</span>'; ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'PHP Max Execution Time', 'polymart-ai' ); ?></th>
						<td><?php echo esc_html( ini_get( 'max_execution_time' ) ); ?>s</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'API Key Configured', 'polymart-ai' ); ?></th>
						<td><?php echo ! empty( $settings['translation']['api_key'] ) ? '<span style="color: green;">Yes</span>' : '<span style="color: red;">No</span>'; ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'API Endpoint Configured', 'polymart-ai' ); ?></th>
						<td><?php echo ! empty( $settings['translation']['api_endpoint'] ) ? '<span style="color: green;">Yes</span>' : '<span style="color: red;">No</span>'; ?></td>
					</tr>
				</table>
			</div>

			<div class="card" style="max-width: 800px; margin-top: 20px;">
				<h2><?php esc_html_e( 'Translation Queue Status', 'polymart-ai' ); ?></h2>
				<table class="widefat">
					<tr>
						<th><?php esc_html_e( 'Scheduled Posts', 'polymart-ai' ); ?></th>
						<td><?php echo esc_html( $queue_status['scheduled_posts'] ?? 0 ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Scheduled Terms', 'polymart-ai' ); ?></th>
						<td><?php echo esc_html( $queue_status['scheduled_terms'] ?? 0 ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Pending Runtime Strings', 'polymart-ai' ); ?></th>
						<td><?php echo esc_html( $queue_status['pending_strings'] ?? 0 ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Pending Queue Count', 'polymart-ai' ); ?></th>
						<td><?php echo esc_html( $pending_count ); ?></td>
					</tr>
				</table>
			</div>

			<div class="card" style="max-width: 800px; margin-top: 20px;">
				<h2><?php esc_html_e( 'UI Translation Job', 'polymart-ai' ); ?></h2>
				<table class="widefat">
					<tr>
						<th><?php esc_html_e( 'Status', 'polymart-ai' ); ?></th>
						<td><?php echo esc_html( $ui_job['status'] ?? 'idle' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Language', 'polymart-ai' ); ?></th>
						<td><?php echo esc_html( $ui_job['lang'] ?? '-' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Total', 'polymart-ai' ); ?></th>
						<td><?php echo esc_html( $ui_job['total'] ?? 0 ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Remaining', 'polymart-ai' ); ?></th>
						<td><?php echo esc_html( $ui_job['remaining'] ?? 0 ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Succeeded', 'polymart-ai' ); ?></th>
						<td><?php echo esc_html( $ui_job['succeeded'] ?? 0 ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Failed', 'polymart-ai' ); ?></th>
						<td><?php echo esc_html( $ui_job['failed'] ?? 0 ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last Error', 'polymart-ai' ); ?></th>
						<td><?php echo esc_html( $ui_job['last_error'] ?? '-' ); ?></td>
					</tr>
				</table>
			</div>

			<div class="card" style="max-width: 800px; margin-top: 20px;">
				<h2><?php esc_html_e( 'Debug Actions', 'polymart-ai' ); ?></h2>
				<p><?php esc_html_e( 'Use these actions to reset stuck translation processes.', 'polymart-ai' ); ?></p>
				
				<form method="get" style="display: inline-block; margin-right: 10px;">
					<input type="hidden" name="page" value="polymart-production-debug">
					<input type="hidden" name="action" value="clear_cron">
					<?php wp_nonce_field( 'polymart_debug_action' ); ?>
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Clear Stuck Cron Jobs', 'polymart-ai' ); ?></button>
				</form>

				<form method="get" style="display: inline-block; margin-right: 10px;">
					<input type="hidden" name="page" value="polymart-production-debug">
					<input type="hidden" name="action" value="reset_ui_job">
					<?php wp_nonce_field( 'polymart_debug_action' ); ?>
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Reset UI Translation Job', 'polymart-ai' ); ?></button>
				</form>

				<form method="get" style="display: inline-block; margin-right: 10px;">
					<input type="hidden" name="page" value="polymart-production-debug">
					<input type="hidden" name="action" value="clear_pending_queue">
					<?php wp_nonce_field( 'polymart_debug_action' ); ?>
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Clear Pending Queue', 'polymart-ai' ); ?></button>
				</form>

				<form method="get" style="display: inline-block; margin-right: 10px;">
					<input type="hidden" name="page" value="polymart-production-debug">
					<input type="hidden" name="action" value="test_api">
					<?php wp_nonce_field( 'polymart_debug_action' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Test API Connection', 'polymart-ai' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Get cron status.
	 */
	private static function get_cron_status() {
		$cron = _get_cron_array();
		
		return array(
			'post_jobs' => isset( $cron['polymart_ai_async_translate_post'] ) ? count( $cron['polymart_ai_async_translate_post'] ) : 0,
			'term_jobs' => isset( $cron['polymart_ai_async_translate_term'] ) ? count( $cron['polymart_ai_async_translate_term'] ) : 0,
			'pending_jobs' => isset( $cron['polymart_ai_translate_pending_strings'] ) ? count( $cron['polymart_ai_translate_pending_strings'] ) : 0,
		);
	}
}

// Initialize if in admin
if ( is_admin() ) {
	Production_Debug::init();
}
