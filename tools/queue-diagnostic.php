<?php
/**
 * Admin queue / translation-start diagnostic (read-only).
 *
 * Links (replace YOUR-SITE with your domain):
 *   Summary:   /wp-admin/admin.php?page=polymart-ai-queue-debug
 *   Homepage:  /wp-admin/admin.php?page=polymart-ai-queue-debug&view=homepage
 *   Queue sim: /wp-admin/admin.php?page=polymart-ai-queue-debug&view=queue
 *   Remaining: /wp-admin/admin.php?page=polymart-ai-queue-debug&view=remaining
 *   Mismatch:  /wp-admin/admin.php?page=polymart-ai-queue-debug&view=mismatch
 *   Post:      /wp-admin/admin.php?page=polymart-ai-queue-debug&view=post&post_id=1021&lang=en&format=json
 *   Troubleshoot UI: /wp-admin/admin.php?page=polymart-ai-troubleshoot&post_id=21870&lang=en
 *   Troubleshoot JSON: append &format=json to troubleshoot URL
 *   JSON:      append &format=json  OR  admin-ajax.php?action=polymart_ai_queue_debug&view=summary&lang=en
 *   Compact:   add &compact=1 (summary JSON only — short)
 *
 * @package PolymartAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extend runtime for admin diagnostics (avoid blank timeout pages).
 *
 * @return void
 */
function polymart_ai_queue_diag_bootstrap() {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	if ( function_exists( 'wp_raise_memory_limit' ) ) {
		wp_raise_memory_limit( 'admin' );
	}
}

add_action(
	'admin_menu',
	static function () {
		// Hidden raw diagnostic (troubleshoot UI is registered from Admin after parent menu).
		add_submenu_page(
			null,
			'PolyMart Queue Debug',
			'Queue Debug',
			'manage_options',
			'polymart-ai-queue-debug',
			'polymart_ai_render_queue_diagnostic_page'
		);
	},
	99
);

/**
 * Send pure JSON before WordPress admin HTML wrapper (load-* runs pre-header).
 *
 * @return void
 */
function polymart_ai_queue_diag_maybe_send_json_early() {
	if ( ! is_admin() ) {
		return;
	}

	$cap = class_exists( '\PolymartAI\REST_API' )
		? \PolymartAI\REST_API::required_admin_capability()
		: 'manage_options';

	if ( ! current_user_can( $cap ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$page = sanitize_key( (string) ( $_GET['page'] ?? '' ) );

	if ( ! in_array( $page, array( 'polymart-ai-queue-debug', 'polymart-ai-troubleshoot' ), true ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$format = sanitize_key( (string) ( $_GET['format'] ?? '' ) );

	if ( 'json' !== $format ) {
		return;
	}

	polymart_ai_queue_diag_send_json_response();
}

add_action( 'load-admin_page_polymart-ai-queue-debug', 'polymart_ai_queue_diag_maybe_send_json_early' );
add_action( 'load-polymart-ai_page_polymart-ai-troubleshoot', 'polymart_ai_queue_diag_maybe_send_json_early' );
add_action( 'load-toplevel_page_polymart-ai-troubleshoot', 'polymart_ai_queue_diag_maybe_send_json_early' );
// Fallback before capability die when menu hook name mismatches.
add_action( 'admin_init', 'polymart_ai_queue_diag_maybe_send_json_early', 0 );

/**
 * AJAX endpoint — pure JSON, no admin HTML chrome.
 *
 * @return void
 */
function polymart_ai_queue_diag_ajax_handler() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	polymart_ai_queue_diag_send_json_response();
}

add_action( 'wp_ajax_polymart_ai_queue_debug', 'polymart_ai_queue_diag_ajax_handler' );

/**
 * Collect and output JSON, then exit.
 *
 * @return void
 */
function polymart_ai_queue_diag_send_json_response() {
	polymart_ai_queue_diag_bootstrap();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$lang    = sanitize_key( (string) ( $_GET['lang'] ?? 'en' ) );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$page    = sanitize_key( (string) ( $_GET['page'] ?? 'polymart-ai-queue-debug' ) );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$view    = sanitize_key( (string) ( $_GET['view'] ?? '' ) );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$compact = ! empty( $_GET['compact'] );

	if ( 'polymart-ai-troubleshoot' === $page && '' === $view ) {
		$view = 'troubleshoot';
	}

	if ( '' === $view ) {
		$view = 'summary';
	}

	try {
		$data = polymart_ai_queue_diag_collect( $view, $lang, $compact );
	} catch ( \Throwable $exception ) {
		$data = array(
			'error'   => $exception->getMessage(),
			'view'    => $view,
			'lang'    => $lang,
			'compact' => $compact,
		);
	}

	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}

	nocache_headers();
	status_header( 200 );
	header( 'Content-Type: application/json; charset=utf-8' );

	echo wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	exit;
}

/**
 * @return string
 */
function polymart_ai_queue_diag_base_url( $page = 'polymart-ai-queue-debug' ) {
	$page = sanitize_key( (string) $page );

	if ( 'polymart-ai-troubleshoot' === $page ) {
		return admin_url( 'admin.php?page=polymart-ai-troubleshoot' );
	}

	return admin_url( 'admin.php?page=polymart-ai-queue-debug' );
}

/**
 * @param string               $view   View slug.
 * @param array<string, mixed> $extra  Extra query args.
 * @param string               $page   Admin page slug.
 * @return string
 */
function polymart_ai_queue_diag_url( $view = 'summary', array $extra = array(), $page = 'polymart-ai-queue-debug' ) {
	$page = sanitize_key( (string) $page );

	if ( 'troubleshoot' === sanitize_key( (string) $view ) ) {
		$page = 'polymart-ai-troubleshoot';
	}

	$args = array_merge(
		array(
			'page' => $page,
		),
		$extra
	);

	if ( 'polymart-ai-queue-debug' === $page ) {
		$args['view'] = sanitize_key( (string) $view );
	}

	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * @param mixed $value Value to stringify.
 * @return string
 */
function polymart_ai_queue_diag_str( $value ) {
	if ( is_bool( $value ) ) {
		return $value ? 'true' : 'false';
	}

	if ( null === $value || '' === $value ) {
		return '(empty)';
	}

	if ( is_array( $value ) || is_object( $value ) ) {
		return wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}

	return (string) $value;
}

/**
 * @param int    $post_id Post ID.
 * @param string $lang    Language code.
 * @return array<string, mixed>
 */
/**
 * Meta-only post snapshot — safe for huge Elementor posts (avoids 503).
 *
 * @param int    $post_id Post ID.
 * @param string $lang    Language.
 * @return array<string, mixed>
 */
function polymart_ai_queue_diag_post_snapshot_light( $post_id, $lang ) {
	$post_id = absint( $post_id );
	$lang    = sanitize_key( (string) $lang );
	$post    = get_post( $post_id );

	if ( ! $post instanceof \WP_Post ) {
		return array(
			'post_id' => $post_id,
			'error'   => 'post_not_found',
		);
	}

	$status_key    = \PolymartAI\Translation\Post_Translator::get_status_index_meta_key( $lang );
	$elementor_key = method_exists( '\PolymartAI\Translation\Post_Translator', 'get_elementor_meta_key' )
		? \PolymartAI\Translation\Post_Translator::get_elementor_meta_key( $lang )
		: '_elementor_data_' . $lang;
	$source_bytes  = strlen( (string) get_post_meta( $post_id, '_elementor_data', true ) );
	$en_bytes      = strlen( (string) get_post_meta( $post_id, $elementor_key, true ) );
	$partial_raw   = get_post_meta( $post_id, '_polymart_ai_job_partial_' . $lang, true );
	$partial_bytes = is_string( $partial_raw ) ? strlen( $partial_raw ) : ( is_array( $partial_raw ) ? strlen( (string) wp_json_encode( $partial_raw ) ) : 0 );

	return array(
		'post_id'     => $post_id,
		'title'       => get_the_title( $post_id ),
		'post_type'   => $post->post_type,
		'post_status' => $post->post_status,
		'edit_url'    => get_edit_post_link( $post_id, 'raw' ),
		'view_url_fa' => get_permalink( $post_id ),
		'index_status'=> (string) get_post_meta( $post_id, $status_key, true ),
		'persian_flag'=> (string) get_post_meta( $post_id, \PolymartAI\Translation\Post_Translator::PERSIAN_CONTENT_FLAG_META, true ),
		'uses_elementor' => \PolymartAI\Translation\Post_Translator::uses_elementor_builder( $post_id ),
		'light'       => true,
		'meta'        => array(
			'_elementor_edit_mode'                              => (string) get_post_meta( $post_id, '_elementor_edit_mode', true ),
			'_elementor_data_bytes'                             => $source_bytes,
			$elementor_key . '_bytes'                           => $en_bytes,
			'_polymart_ai_job_partial_' . $lang . '_bytes'      => $partial_bytes,
			'_polymart_ai_elementor_finalized_' . $lang => (string) get_post_meta( $post_id, '_polymart_ai_elementor_finalized_' . $lang, true ),
			'source_hash'                              => (string) get_post_meta(
				$post_id,
				\PolymartAI\Translation\Post_Translator::get_elementor_source_hash_meta_key( $lang ),
				true
			),
			'_polymart_ai_elementor_progress_' . $lang  => (string) get_post_meta( $post_id, '_polymart_ai_elementor_progress_' . $lang, true ),
			'_polymart_ai_elementor_error_' . $lang     => (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true ),
			'title_fa'                                          => (string) $post->post_title,
			'title_en'                                          => (string) get_post_meta( $post_id, \PolymartAI\Translation\Post_Translator::get_meta_key( 'title', 'en' ), true ),
			'title_' . $lang                                    => (string) get_post_meta( $post_id, \PolymartAI\Translation\Post_Translator::get_meta_key( 'title', $lang ), true ),
			'title_meta_key_' . $lang                           => \PolymartAI\Translation\Post_Translator::get_meta_key( 'title', $lang ),
		),
	);
}

function polymart_ai_queue_diag_post_snapshot( $post_id, $lang ) {
	$post_id = absint( $post_id );
	$lang    = sanitize_key( (string) $lang );
	$post    = get_post( $post_id );

	if ( ! $post instanceof \WP_Post ) {
		return array(
			'post_id' => $post_id,
			'error'   => 'post_not_found',
		);
	}

	$status_key = \PolymartAI\Translation\Post_Translator::get_status_index_meta_key( $lang );

	\PolymartAI\Translation\Post_Translator::flush_translation_status_cache( $post_id );

	// Never mutate/repair inside diagnostics — that decoded huge Elementor trees and caused 503.
	$elementor_key = method_exists( '\PolymartAI\Translation\Post_Translator', 'get_elementor_meta_key' )
		? \PolymartAI\Translation\Post_Translator::get_elementor_meta_key( $lang )
		: '_elementor_data_' . $lang;

	$serve = array( 'ok' => null, 'codes' => array(), 'messages' => array(), 'meta' => array() );
	$scan  = null;
	$ready = null;
	$stored_persian = null;

	try {
		if ( method_exists( '\PolymartAI\Translation\Post_Translator', 'explain_elementor_storefront_serve_blockers' ) ) {
			$serve = \PolymartAI\Translation\Post_Translator::explain_elementor_storefront_serve_blockers( $post_id, $lang, false );
		}
	} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		$serve = array( 'ok' => null, 'codes' => array( 'snapshot_error' ), 'messages' => array( $e->getMessage() ), 'meta' => array() );
	}

	try {
		$scan = method_exists( '\PolymartAI\Translation\Post_Translator', 'get_elementor_scan_diagnostics' )
			? \PolymartAI\Translation\Post_Translator::get_elementor_scan_diagnostics( $post_id, $lang )
			: null;
	} catch ( \Throwable $e ) {
		$scan = array( 'active' => true, 'error' => $e->getMessage() );
	}

	try {
		$ready = method_exists( '\PolymartAI\Translation\Post_Translator', 'elementor_translation_is_storefront_ready' )
			? \PolymartAI\Translation\Post_Translator::elementor_translation_is_storefront_ready( $post_id, $lang )
			: null;
		$stored_persian = \PolymartAI\Translation\Post_Translator::stored_elementor_translation_has_persian( $post_id, $lang );
	} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
	}

	return array(
		'post_id'              => $post_id,
		'title'                => get_the_title( $post_id ),
		'post_type'            => $post->post_type,
		'post_status'          => $post->post_status,
		'edit_url'             => get_edit_post_link( $post_id, 'raw' ),
		'view_url_fa'          => get_permalink( $post_id ),
		'index_status'         => (string) get_post_meta( $post_id, $status_key, true ),
		'persian_flag'         => (string) get_post_meta( $post_id, \PolymartAI\Translation\Post_Translator::PERSIAN_CONTENT_FLAG_META, true ),
		'live_status'          => \PolymartAI\Translation\Post_Translator::get_translation_status( $post_id, $lang ),
		'needs_work'           => \PolymartAI\Translation\Post_Translator::post_needs_translation_work( $post_id, $lang ),
		'actionable_for_job'   => \PolymartAI\Translation\Post_Translator::post_is_actionable_for_job( $post_id, $lang ),
		'storefront_persian'   => \PolymartAI\Translation\Post_Translator::storefront_would_show_persian_source( $post_id, $lang ),
		'has_persian_content'  => \PolymartAI\Translation\Post_Translator::post_has_persian_content( $post ),
		'uses_elementor'       => \PolymartAI\Translation\Post_Translator::uses_elementor_builder( $post_id ),
		'can_serve_elementor'  => ! empty( $serve['ok'] ),
		'can_serve_details'    => $serve,
		'elementor_scan'       => $scan,
		'elementor_storefront_ready' => $ready,
		'stored_elementor_persian' => $stored_persian,
		'needs_elementor_job'  => \PolymartAI\Translation\Post_Translator::post_needs_elementor_job_work( $post_id, $lang ),
		'gap_reason'           => \PolymartAI\Translation\Post_Translator::describe_translation_gap( $post_id, $lang ),
		'gaps_missing'         => array_values( array_map( 'strval', (array) ( \PolymartAI\Translation\Post_Translator::get_translation_gaps( $post_id, $lang )['missing'] ?? array() ) ) ),
		'meta'                 => array(
			'_elementor_edit_mode'                  => (string) get_post_meta( $post_id, '_elementor_edit_mode', true ),
			$elementor_key . '_bytes'               => strlen( (string) get_post_meta( $post_id, $elementor_key, true ) ),
			'_polymart_ai_elementor_finalized_' . $lang => (string) get_post_meta( $post_id, '_polymart_ai_elementor_finalized_' . $lang, true ),
			'_polymart_ai_elementor_progress_' . $lang  => (string) get_post_meta( $post_id, '_polymart_ai_elementor_progress_' . $lang, true ),
			'_polymart_ai_elementor_error_' . $lang       => (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true ),
			'title_fa'                              => (string) $post->post_title,
			'title_en'                              => (string) get_post_meta( $post_id, \PolymartAI\Translation\Post_Translator::get_meta_key( 'title', 'en' ), true ),
			'title_' . $lang                        => (string) get_post_meta( $post_id, \PolymartAI\Translation\Post_Translator::get_meta_key( 'title', $lang ), true ),
			'title_meta_key_' . $lang               => \PolymartAI\Translation\Post_Translator::get_meta_key( 'title', $lang ),
		),
	);
}

/**
 * Simulate start_job() queue math without mutating state.
 *
 * @param string $lang Language code.
 * @return array<string, mixed>
 */
function polymart_ai_queue_diag_simulate_start( $lang ) {
	$lang  = sanitize_key( (string) $lang );
	$front = absint( get_option( 'page_on_front' ) );

	$stats_index    = \PolymartAI\Translation\Pipeline\Translation_Query::compute_translation_stats( $lang, false );
	$menu_needs     = \PolymartAI\Translation\Content\Menu_Translator::count_untranslated( $lang );
	$priority_probe = \PolymartAI\Translation\Pipeline\Translation_Query::probe_priority_unfinished_post_ids( $lang, 12, true );
	$issue_ids      = array();

	if ( $front > 0 && \PolymartAI\Translation\Post_Translator::post_needs_translation_work( $front, $lang ) ) {
		$issue_ids[] = $front;
	}

	foreach ( $priority_probe as $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id > 0 ) {
			$issue_ids[] = $post_id;
		}
	}

	$issue_ids = array_values( array_unique( $issue_ids ) );

	$needs_work = (int) $stats_index['untranslated'] + (int) $stats_index['partial'] + $menu_needs;
	$total      = $needs_work;
	$probe_ids  = $issue_ids;

	if ( ! empty( $issue_ids ) ) {
		$needs_work = max( $needs_work, count( $issue_ids ) );
		$total      = max( $total, $needs_work );
	}

	if ( $total <= 0 && ! empty( $probe_ids ) ) {
		$needs_work = max( count( $probe_ids ), $menu_needs );
		$total      = $needs_work;
	}

	$probe_post = 0;

	if ( $total <= 0 ) {
		$probe_post = \PolymartAI\Translation\Pipeline\Translation_Query::find_next_actionable_post_id( $lang, 0 );

		if ( $probe_post > 0 || $menu_needs > 0 ) {
			$needs_work = max( 1, $menu_needs, $probe_post > 0 ? 1 : 0 );
			$total      = $needs_work;
		}
	}

	$seed_limit = min( 8, max( 3, max( 1, $needs_work ) ) );
	$seed_ids   = \PolymartAI\Translation\Pipeline\Translation_Query::seed_actionable_post_ids( $lang, $seed_limit );

	if ( ! empty( $probe_ids ) ) {
		$seed_ids = array_values(
			array_unique(
				array_merge( $probe_ids, $seed_ids )
			)
		);
		$seed_ids = array_slice( $seed_ids, 0, max( $seed_limit, count( $probe_ids ) ) );
	}

	if ( empty( $seed_ids ) ) {
		$fallback_id = \PolymartAI\Translation\Pipeline\Translation_Query::find_next_actionable_post_id( $lang, 0 );

		if ( $fallback_id > 0 ) {
			$seed_ids = array( $fallback_id );
		}
	}

	$seed_ids = array_values(
		array_unique(
			array_filter(
				array_map( 'absint', $seed_ids ),
				static function ( $post_id ) {
					return $post_id > 0;
				}
			)
		)
	);

	if ( ! empty( $seed_ids ) ) {
		$needs_work = max( $needs_work, count( $seed_ids ), 1 );
		$total      = max( $total, $needs_work );
	}

	$would_error = empty( $seed_ids ) && $menu_needs <= 0;

	return array(
		'lang'                   => $lang,
		'mode'                   => 'light',
		'page_on_front'          => $front,
		'stats_index'            => $stats_index,
		'menu_needs'             => $menu_needs,
		'priority_probe'         => $priority_probe,
		'issue_ids'              => $issue_ids,
		'find_next_actionable'   => \PolymartAI\Translation\Pipeline\Translation_Query::find_next_actionable_post_id( $lang, 0 ),
		'find_next_untranslated' => \PolymartAI\Translation\Pipeline\Translation_Query::find_next_untranslated_post_id( $lang, 0 ),
		'seed_actionable'        => $seed_ids,
		'simulation'             => array(
			'needs_work'  => $needs_work,
			'total'       => $total,
			'seed_ids'    => $seed_ids,
			'probe_ids'   => $probe_ids,
			'probe_post'  => $probe_post,
			'would_error' => $would_error,
			'would_log'   => sprintf( 'ترجمه خودکار شروع شد — %d مورد در صف', $total ),
		),
		'ai_configured'          => \PolymartAI\REST_API::is_ai_configured(),
	);
}

/**
 * @param string $lang Language code.
 * @return array<string, mixed>
 */
function polymart_ai_queue_diag_summary( $lang, $compact = false ) {
	$lang = sanitize_key( (string) $lang );
	$job  = \PolymartAI\Activity_Logger::get_job( false );
	$front = absint( get_option( 'page_on_front' ) );

	$as_available = class_exists( '\PolymartAI\Activity_Logger\Job_Action_Scheduler' )
		&& \PolymartAI\Activity_Logger\Job_Action_Scheduler::is_available();

	$as_pending = $as_available && \PolymartAI\Activity_Logger\Job_Action_Scheduler::has_pending_or_running();

	$remaining = polymart_ai_queue_diag_remaining_light( $lang );

	if ( $compact && ! empty( $remaining['items'] ) ) {
		$remaining['items'] = array_slice( $remaining['items'], 0, 5 );
		$remaining['truncated'] = true;
	}

	$homepage = $front > 0 ? polymart_ai_queue_diag_post_snapshot( $front, $lang ) : null;

	if ( $compact && is_array( $homepage ) ) {
		unset( $homepage['meta'], $homepage['gaps_missing'], $homepage['edit_url'], $homepage['view_url_fa'] );
	}

	return array(
		'site'            => home_url(),
		'plugin_version'  => defined( 'POLYMART_AI_VERSION' ) ? POLYMART_AI_VERSION : '',
		'lang'            => $lang,
		'page_on_front'   => $front,
		'page_on_front_title' => $front > 0 ? get_the_title( $front ) : '',
		'ai_configured'   => \PolymartAI\REST_API::is_ai_configured(),
		'worker_lively'   => \PolymartAI\Activity_Logger::is_bulk_worker_lively( 180 ),
		'action_scheduler'=> array(
			'available' => $as_available,
			'pending_or_running' => $as_pending,
		),
		'current_job'     => array(
			'status'          => (string) ( $job['status'] ?? 'idle' ),
			'total'           => (int) ( $job['total'] ?? 0 ),
			'remaining'       => (int) ( $job['remaining'] ?? 0 ),
			'needs_work'      => (int) ( $job['needs_work'] ?? 0 ),
			'deferred_queue'  => array_values( array_map( 'absint', (array) ( $job['deferred_queue'] ?? array() ) ) ),
			'queue'           => array_values( array_map( 'absint', (array) ( $job['queue'] ?? array() ) ) ),
			'partial_post_id' => (int) ( $job['partial_post_id'] ?? 0 ),
			'lang'            => (string) ( $job['lang'] ?? '' ),
		),
		'stats_index'     => \PolymartAI\Translation\Pipeline\Translation_Query::compute_translation_stats( $lang, false ),
		'remaining_light' => $remaining,
		'homepage_snapshot' => $homepage,
		'start_simulation'  => polymart_ai_queue_diag_simulate_start( $lang ),
	);
}

/**
 * Fast remaining-work probe (homepage + priority posts only).
 *
 * @param string $lang Language code.
 * @return array<string, mixed>
 */
function polymart_ai_queue_diag_remaining_light( $lang ) {
	$lang    = sanitize_key( (string) $lang );
	$items   = array();
	$seen    = array();
	$front   = absint( get_option( 'page_on_front' ) );
	$check   = array( $front );

	$check = array_merge(
		$check,
		\PolymartAI\Translation\Pipeline\Translation_Query::probe_priority_unfinished_post_ids( $lang, 20, true ),
		\PolymartAI\Translation\Pipeline\Translation_Query::seed_actionable_post_ids( $lang, 12 )
	);

	foreach ( array_values( array_unique( array_filter( array_map( 'absint', $check ) ) ) ) as $post_id ) {
		if ( $post_id <= 0 || isset( $seen[ $post_id ] ) ) {
			continue;
		}

		$seen[ $post_id ] = true;

		if ( ! \PolymartAI\Translation\Post_Translator::post_needs_translation_work( $post_id, $lang ) ) {
			continue;
		}

		$items[] = array(
			'post_id'            => $post_id,
			'title'              => get_the_title( $post_id ),
			'index_status'       => (string) get_post_meta( $post_id, \PolymartAI\Translation\Post_Translator::get_status_index_meta_key( $lang ), true ),
			'live_status'        => \PolymartAI\Translation\Post_Translator::get_translation_status( $post_id, $lang ),
			'needs_work'         => true,
			'storefront_persian' => \PolymartAI\Translation\Post_Translator::storefront_would_show_persian_source( $post_id, $lang ),
			'gap_reason'         => \PolymartAI\Translation\Post_Translator::describe_translation_gap( $post_id, $lang ),
		);
	}

	return array(
		'mode'    => 'light',
		'total'   => count( $items ),
		'items'   => $items,
		'note'    => 'Fast probe only — full site scan skipped to avoid timeout.',
	);
}

/**
 * @param string $lang  Language code.
 * @param int    $limit Max rows.
 * @return array<int, array<string, mixed>>
 */
function polymart_ai_queue_diag_mismatches( $lang, $limit = 40 ) {
	global $wpdb;

	$lang       = sanitize_key( (string) $lang );
	$limit      = max( 1, min( 100, absint( $limit ) ) );
	$status_key = \PolymartAI\Translation\Post_Translator::get_status_index_meta_key( $lang );
	$post_types = \PolymartAI\Translation\Post_Translator::get_supported_post_types();
	$rows       = array();

	if ( empty( $post_types ) ) {
		return $rows;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

	$post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm_flag
				ON p.ID = pm_flag.post_id
				AND pm_flag.meta_key = %s
				AND pm_flag.meta_value = '1'
			INNER JOIN {$wpdb->postmeta} pm_status
				ON p.ID = pm_status.post_id
				AND pm_status.meta_key = %s
				AND pm_status.meta_value = 'translated'
			WHERE p.post_status = 'publish'
				AND p.post_type IN ( {$placeholders} )
			ORDER BY p.ID ASC
			LIMIT %d",
			array_merge(
				array( \PolymartAI\Translation\Post_Translator::PERSIAN_CONTENT_FLAG_META, $status_key ),
				$post_types,
				array( min( 40, $limit * 3 ) )
			)
		)
	);

	if ( ! is_array( $post_ids ) ) {
		return $rows;
	}

	foreach ( array_map( 'absint', $post_ids ) as $post_id ) {
		if ( $post_id <= 0 ) {
			continue;
		}

		if ( ! \PolymartAI\Translation\Post_Translator::post_needs_translation_work( $post_id, $lang ) ) {
			continue;
		}

		$rows[] = polymart_ai_queue_diag_post_snapshot( $post_id, $lang );

		if ( count( $rows ) >= $limit ) {
			break;
		}
	}

	return $rows;
}

/**
 * Trim heavy Elementor map blobs for JSON export.
 *
 * @param array<string, mixed> $state Partial job state.
 * @return array<string, mixed>
 */
function polymart_ai_queue_diag_summarize_partial_state( array $state ) {
	$summary = $state;

	foreach ( array( 'elementor_map', 'elementor_persist_map', 'elementor_seg_map' ) as $map_key ) {
		if ( ! isset( $summary[ $map_key ] ) || ! is_array( $summary[ $map_key ] ) ) {
			continue;
		}

		$keys = array_keys( $summary[ $map_key ] );
		$summary[ $map_key . '_count' ]       = count( $keys );
		$summary[ $map_key . '_sample_keys' ] = array_slice( array_map( 'strval', $keys ), 0, 16 );
		unset( $summary[ $map_key ] );
	}

	return $summary;
}

/**
 * List Action Scheduler rows for one post/language.
 *
 * @param int    $post_id Post ID.
 * @param string $lang    Language code.
 * @return array<int, array<string, mixed>>
 */
function polymart_ai_queue_diag_list_as_actions( $post_id, $lang ) {
	$post_id = absint( $post_id );
	$lang    = sanitize_key( (string) $lang );
	$rows    = array();

	if ( $post_id <= 0 || '' === $lang || ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
		return $rows;
	}

	$hooks = array(
		array(
			'label' => 'bulk_slice',
			'hook'  => \PolymartAI\Activity_Logger\Job_Action_Scheduler::HOOK,
			'group' => \PolymartAI\Activity_Logger\Job_Action_Scheduler::GROUP,
		),
		array(
			'label' => 'metabox_slice',
			'hook'  => \PolymartAI\Activity_Logger\Metabox_Action_Scheduler::HOOK,
			'group' => \PolymartAI\Activity_Logger\Metabox_Action_Scheduler::GROUP,
		),
	);

	$statuses = array(
		\ActionScheduler_Store::STATUS_PENDING,
		\ActionScheduler_Store::STATUS_RUNNING,
		\ActionScheduler_Store::STATUS_FAILED,
	);

	foreach ( $hooks as $hook_row ) {
		foreach ( $statuses as $status ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => (string) $hook_row['hook'],
					'group'    => (string) $hook_row['group'],
					'status'   => $status,
					'per_page' => 20,
				)
			);

			if ( empty( $actions ) ) {
				continue;
			}

			foreach ( $actions as $action ) {
				if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
					continue;
				}

				$args = $action->get_args();

				if ( absint( $args['post_id'] ?? 0 ) !== $post_id || sanitize_key( (string) ( $args['lang'] ?? '' ) ) !== $lang ) {
					continue;
				}

				$schedule = method_exists( $action, 'get_schedule' ) ? $action->get_schedule() : null;
				$when     = ( $schedule && method_exists( $schedule, 'get_date' ) ) ? $schedule->get_date() : null;

				$rows[] = array(
					'label'     => (string) $hook_row['label'],
					'hook'      => (string) $hook_row['hook'],
					'action_id' => method_exists( $action, 'get_id' ) ? absint( $action->get_id() ) : 0,
					'status'    => (string) $status,
					'scheduled' => ( $when && method_exists( $when, 'format' ) ) ? $when->format( 'c' ) : '',
					'args'      => $args,
				);
			}
		}
	}

	return $rows;
}

/**
 * Build human-readable hints from troubleshoot payload.
 *
 * @param array<string, mixed> $report Troubleshoot report.
 * @return string[]
 */
function polymart_ai_queue_diag_build_recommendations( array $report ) {
	$hints    = array();
	$job      = (array) ( $report['bulk_job'] ?? array() );
	$recovery = (array) ( $report['recovery'] ?? array() );
	$scan     = (array) ( $report['elementor_scan'] ?? array() );
	$stubborn = (array) ( $report['stubborn_details'] ?? array() );
	$lock     = (array) ( $report['lock'] ?? array() );

	if ( ! empty( $job['pinned_on_post'] ) && 'running' === (string) ( $job['status'] ?? '' ) ) {
		$hints[] = 'این پست الان روی ترجمه خودکار قفل است — اگر بیش از ۵ دقیقه پیشرفت نکرد، خروجی JSON را بفرست.';
	}

	if ( empty( $report['action_scheduler_events'] ) && ! empty( $job['pinned_on_post'] ) && ! empty( $job['worker_lively'] ) ) {
		$hints[] = 'کارگر زنده است ولی هیچ event اکشن‌اسکژولر برای این پست نیست — احتمال گیر روی stubborn بدون AS؛ Stop سپس Start یا Shift+ترجمه در متاباکس.';
	}

	$reopens = absint( $report['job_partial_state']['elementor_force_persian_reopens'] ?? 0 );

	if ( $reopens >= 3 ) {
		$hints[] = 'force-finalize بیش از ۳ بار reopen شده — بعد دیپلوی فیکس، یک بار Stop/Start ترجمه خودکار کافی است تا فیلد سرسخت accept و آزاد شود.';
	}

	if ( ! empty( $recovery['should_force_finalize'] ) ) {
		$hints[] = 'سیستم آماده force-finalize است — یک بار Stop ترجمه خودکار بزن و دوباره Start کن، یا متاباکس همان پست را با Shift+ترجمه ادامه بده.';
	}

	if ( ! empty( $stubborn['fields'] ) ) {
		$first = (array) ( $stubborn['fields'][0] ?? array() );
		$reason = (string) ( $first['reason'] ?? '' );

		if ( 'missing_segments' === $reason ) {
			$hints[] = 'فیلد سرسخت سگمنت‌های __segN ناقص دارد — معمولاً با ۲–۳ tick دیگر AS یا یک بار Shift+ترجمه در متاباکس حل می‌شود.';
		} elseif ( 'api_retries_exhausted' === $reason ) {
			$hints[] = 'تلاش‌های API برای این فیلد تمام شده — JSON را بفرست تا مسیر دستی/force بررسی شود.';
		} elseif ( 'logic_mismatch' === $reason ) {
			$hints[] = 'فیلد در map کامل به نظر می‌رسد ولی هنوز remaining است — احتمال باگ assemble؛ JSON کامل لازم است.';
		}
	}

	if ( ! empty( $scan['api_cooldown_active'] ) ) {
		$hints[] = 'API cooldown فعال است — چند دقیقه صبر کن یا cooldown را در لاگ‌ها چک کن.';
	}

	if ( ! empty( $lock['locked'] ) && empty( $report['action_scheduler_events'] ) ) {
		$hints[] = 'قفل فعال است ولی event AS برای این پست دیده نشد — zombie lock محتمل است؛ «آزاد کردن قفل» در متاباکس را بزن.';
	}

	if ( empty( $hints ) ) {
		$hints[] = 'اگر هنوز گیر کرده، JSON زیر را کپی کن و بفرست.';
	}

	return $hints;
}

/**
 * Full troubleshoot bundle for one post (job, stubborn, AS, recovery).
 *
 * @param int    $post_id Post ID.
 * @param string $lang    Language code.
 * @return array<string, mixed>
 */
function polymart_ai_queue_diag_troubleshoot_snapshot( $post_id, $lang ) {
	$post_id = absint( $post_id );
	$lang    = sanitize_key( (string) $lang );

	// Default LIGHT — full Elementor decode/scan on #21870-class posts caused 503.
	// Pass full=1 (or light=0) only when you explicitly need the heavy report.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$want_full = ! empty( $_GET['full'] ) || ( isset( $_GET['light'] ) && '0' === (string) $_GET['light'] );
	$light     = ! $want_full;

	$snapshot = $light
		? polymart_ai_queue_diag_post_snapshot_light( $post_id, $lang )
		: polymart_ai_queue_diag_post_snapshot( $post_id, $lang );

	if ( ! empty( $snapshot['error'] ) ) {
		return $snapshot;
	}

	$partial_meta_key = '_polymart_ai_job_partial_' . $lang;
	$partial_raw      = get_post_meta( $post_id, $partial_meta_key, true );
	$partial_bytes    = is_string( $partial_raw )
		? strlen( $partial_raw )
		: strlen( (string) wp_json_encode( $partial_raw ) );

	if ( $light && $partial_bytes > 400000 ) {
		$state = array(
			'phase'         => 'elementor',
			'partial_bytes' => $partial_bytes,
			'skipped_load'  => true,
			'note'          => 'partial state too large for light report',
		);
	} else {
		$state_raw = is_array( $partial_raw )
			? $partial_raw
			: \PolymartAI\Translation\Post_Translator::get_job_partial_state( $post_id, $lang );
		$state     = is_array( $state_raw ) ? $state_raw : array();

		// Never hydrate (decode+merge map) in light mode — that alone can 503 huge posts.
		if ( ! $light ) {
			try {
				if ( method_exists( '\PolymartAI\Translation\Post_Translator', 'hydrate_elementor_job_partial_state' ) ) {
					$state = \PolymartAI\Translation\Post_Translator::hydrate_elementor_job_partial_state( $post_id, $lang, $state );
				}
			} catch ( \Throwable $e ) {
				$state = is_array( $state_raw ) ? $state_raw : array();
			}
		} else {
			// Drop bulky maps early so the HTML/JSON response stays small.
			foreach ( array( 'elementor_map', 'elementor_persist_map', 'elementor_seg_map' ) as $map_key ) {
				if ( isset( $state[ $map_key ] ) && is_array( $state[ $map_key ] ) ) {
					$state[ $map_key . '_count' ] = count( $state[ $map_key ] );
					unset( $state[ $map_key ] );
				}
			}
		}
	}

	$job    = \PolymartAI\Activity_Logger::get_job( false );
	$pinned = absint( $job['partial_post_id'] ?? 0 );

	if ( $pinned <= 0 ) {
		$pinned = absint( $job['current_post_id'] ?? 0 );
	}

	$recovery = array(
		'has_stubborn_remaining'  => null,
		'needs_gap_fill'          => null,
		'has_remaining_payload'   => null,
		'should_force_finalize'   => null,
		'pipeline_force_finalize' => null,
		'progress_marker'         => (string) get_post_meta( $post_id, '_polymart_ai_elementor_progress_' . $lang, true ),
		'remaining_field_count'   => null,
		'errors'                  => array(),
	);

	$safe = static function ( $label, $callback ) use ( &$recovery ) {
		try {
			return $callback();
		} catch ( \Throwable $e ) {
			$recovery['errors'][] = $label . ': ' . $e->getMessage();

			return null;
		}
	};

	if ( ! $light ) {
		$recovery['progress_marker'] = (string) $safe(
			'progress_marker',
			static function () use ( $post_id, $lang, $state ) {
				return \PolymartAI\Translation\Post_Translator::format_elementor_job_progress_marker( $post_id, $lang, $state );
			}
		);

		$recovery['remaining_field_count'] = $safe(
			'remaining_field_count',
			static function () use ( $post_id, $lang ) {
				return method_exists( '\PolymartAI\Translation\Post_Translator', 'count_elementor_remaining_fields' )
					? \PolymartAI\Translation\Post_Translator::count_elementor_remaining_fields( $post_id, $lang )
					: null;
			}
		);

		$recovery['has_stubborn_remaining'] = $safe(
			'has_stubborn_remaining',
			static function () use ( $post_id, $lang ) {
				return \PolymartAI\Translation\Post_Translator::elementor_job_has_stubborn_remaining( $post_id, $lang );
			}
		);
		$recovery['needs_gap_fill'] = $safe(
			'needs_gap_fill',
			static function () use ( $post_id, $lang ) {
				return \PolymartAI\Translation\Post_Translator::elementor_needs_gap_fill_work( $post_id, $lang );
			}
		);
		$recovery['has_remaining_payload'] = $safe(
			'has_remaining_payload',
			static function () use ( $post_id, $lang ) {
				return \PolymartAI\Translation\Post_Translator::elementor_job_has_remaining_payload( $post_id, $lang );
			}
		);
		$recovery['should_force_finalize'] = $safe(
			'should_force_finalize',
			static function () use ( $post_id, $lang, $job ) {
				return \PolymartAI\Translation\Post_Translator::elementor_recovery_should_force_finalize( $post_id, $lang, $job );
			}
		);
		$recovery['pipeline_force_finalize'] = $safe(
			'pipeline_force_finalize',
			static function () use ( $post_id, $lang, $state ) {
				return \PolymartAI\Translation\Post_Translator::elementor_should_force_finalize_in_pipeline( $post_id, $lang, $state );
			}
		);
	}

	$scan = is_array( $snapshot['elementor_scan'] ?? null ) ? $snapshot['elementor_scan'] : null;

	$report = array(
		'post_id'                 => $post_id,
		'lang'                    => $lang,
		'title'                   => (string) ( $snapshot['title'] ?? '' ),
		'post_type'               => (string) ( $snapshot['post_type'] ?? '' ),
		'edit_url'                => (string) ( $snapshot['edit_url'] ?? '' ),
		'snapshot'                => $snapshot,
		'elementor_scan'          => $scan,
		'stubborn_details'        => \PolymartAI\Translation\Post_Translator::get_elementor_stubborn_field_diagnostics( $post_id, $lang ),
		'job_partial_state'       => polymart_ai_queue_diag_summarize_partial_state( $state ),
		'lock'                    => \PolymartAI\Translation\Post_Translator::get_translation_lock_status( $post_id, $lang ),
		'recovery'                => $recovery,
		'action_scheduler_events' => array(),
		'bulk_job'                => array(
			'status'          => (string) ( $job['status'] ?? 'idle' ),
			'lang'            => (string) ( $job['lang'] ?? '' ),
			'remaining'       => (int) ( $job['remaining'] ?? 0 ),
			'partial_post_id' => (int) ( $job['partial_post_id'] ?? 0 ),
			'pinned_on_post'  => ( $pinned === $post_id ),
			'worker_lively'   => \PolymartAI\Activity_Logger::is_bulk_worker_lively( 180 ),
			'api_cooldown'    => \PolymartAI\Activity_Logger::get_job_api_cooldown_remaining(),
		),
		'meta_keys'               => array(
			'partial'   => '_polymart_ai_job_partial_' . $lang,
			'elementor' => method_exists( '\PolymartAI\Translation\Post_Translator', 'get_elementor_meta_key' )
				? \PolymartAI\Translation\Post_Translator::get_elementor_meta_key( $lang )
				: '_elementor_data_' . $lang,
			'stubborn'  => '_polymart_ai_stubborn_details_' . $lang,
			'finalized' => '_polymart_ai_elementor_finalized_' . $lang,
			'progress'  => '_polymart_ai_elementor_progress_' . $lang,
			'error'     => '_polymart_ai_elementor_error_' . $lang,
		),
		'light'                   => (bool) $light,
	);

	if ( ! $light ) {
		try {
			$report['action_scheduler_events'] = polymart_ai_queue_diag_list_as_actions( $post_id, $lang );
		} catch ( \Throwable $e ) {
			$report['action_scheduler_events'] = array();
			$recovery['errors'][]              = 'as_events: ' . $e->getMessage();
			$report['recovery']                = $recovery;
		}
	}

	$report['recommendations'] = polymart_ai_queue_diag_build_recommendations( $report );

	return $report;
}

/**
 * @param string $view View slug.
 * @param string $lang Language code.
 * @return array<string, mixed>
 */
function polymart_ai_queue_diag_collect( $view, $lang, $compact = false ) {
	polymart_ai_queue_diag_bootstrap();

	$view = sanitize_key( (string) $view );
	$lang = sanitize_key( (string) $lang );

	if ( '' === $view ) {
		$view = 'summary';
	}

	if ( '' === $lang ) {
		$lang = 'en';
	}

	$ajax_json = admin_url( 'admin-ajax.php?action=polymart_ai_queue_debug&view=' . rawurlencode( $view ) . '&lang=' . rawurlencode( $lang ) . '&compact=1' );

	$data = array(
		'generated_at' => gmdate( 'c' ),
		'view'         => $view,
		'lang'         => $lang,
		'compact'      => (bool) $compact,
		'links'        => array(
			'summary'      => polymart_ai_queue_diag_url( 'summary', array( 'lang' => $lang ) ),
			'homepage'     => polymart_ai_queue_diag_url( 'homepage', array( 'lang' => $lang ) ),
			'queue'        => polymart_ai_queue_diag_url( 'queue', array( 'lang' => $lang ) ),
			'remaining'    => polymart_ai_queue_diag_url( 'remaining', array( 'lang' => $lang ) ),
			'mismatch'     => polymart_ai_queue_diag_url( 'mismatch', array( 'lang' => $lang ) ),
			'post'         => polymart_ai_queue_diag_url( 'post', array( 'lang' => $lang, 'post_id' => absint( $_GET['post_id'] ?? get_option( 'page_on_front' ) ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'troubleshoot' => polymart_ai_queue_diag_url( 'troubleshoot', array( 'lang' => $lang, 'post_id' => absint( $_GET['post_id'] ?? 0 ) ), 'polymart-ai-troubleshoot' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'json_compact' => add_query_arg( array( 'format' => 'json', 'compact' => '1' ), polymart_ai_queue_diag_url( $view, array( 'lang' => $lang ) ) ),
			'json_ajax'    => $ajax_json,
		),
	);

	switch ( $view ) {
		case 'homepage':
			$front = absint( get_option( 'page_on_front' ) );
			$data['page_on_front'] = $front;
			$data['homepage']      = $front > 0 ? polymart_ai_queue_diag_post_snapshot( $front, $lang ) : array( 'error' => 'no_static_front_page' );
			$data['is_homepage_translated'] = $front > 0
				&& ! \PolymartAI\Translation\Post_Translator::post_needs_translation_work( $front, $lang )
				&& ! \PolymartAI\Translation\Post_Translator::storefront_would_show_persian_source( $front, $lang );
			break;

		case 'queue':
			$data['simulation'] = polymart_ai_queue_diag_simulate_start( $lang );
			break;

		case 'remaining':
			$data['remaining'] = polymart_ai_queue_diag_remaining_light( $lang );
			$data['message']   = 0 === (int) ( $data['remaining']['total'] ?? 0 )
				? 'Light probe: no priority/homepage items need work (full scan skipped).'
				: 'Light probe found ' . (int) $data['remaining']['total'] . ' item(s) needing work.';
			break;

		case 'mismatch':
			$data['mismatches'] = polymart_ai_queue_diag_mismatches( $lang, 40 );
			$data['count']      = count( $data['mismatches'] );
			break;

		case 'post':
			$post_id = absint( $_GET['post_id'] ?? get_option( 'page_on_front' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$data['post'] = polymart_ai_queue_diag_post_snapshot( $post_id, $lang );
			break;

		case 'troubleshoot':
			$post_id = absint( $_GET['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$data['troubleshoot'] = $post_id > 0
				? polymart_ai_queue_diag_troubleshoot_snapshot( $post_id, $lang )
				: array(
					'error'   => 'missing_post_id',
					'message' => 'شناسه پست را وارد کنید.',
				);
			break;

		default:
			$data['summary'] = polymart_ai_queue_diag_summary( $lang, $compact );
			break;
	}

	return $data;
}

/**
 * @param array<string, mixed> $data Diagnostic payload.
 * @return void
 */
function polymart_ai_queue_diag_render_html( array $data ) {
	$view = (string) ( $data['view'] ?? 'summary' );
	$lang = (string) ( $data['lang'] ?? 'en' );
	?>
	<div class="wrap polymart-queue-debug">
		<h1>PolyMart — Queue Diagnostic (<?php echo esc_html( strtoupper( $lang ) ); ?>)</h1>
		<p>Read-only debug. Copy JSON link output and send to developer.</p>

		<h2>Links</h2>
		<ul style="font-family:monospace;font-size:13px;line-height:1.8;">
			<?php foreach ( (array) ( $data['links'] ?? array() ) as $label => $url ) : ?>
				<li>
					<strong><?php echo esc_html( (string) $label ); ?>:</strong>
					<a href="<?php echo esc_url( (string) $url ); ?>"><?php echo esc_html( (string) $url ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>

		<p>
			<?php
			foreach ( array( 'summary', 'homepage', 'queue', 'remaining', 'mismatch', 'post' ) as $tab ) {
				$extra = array( 'lang' => $lang );
				if ( 'post' === $tab ) {
					$extra['post_id'] = absint( $_GET['post_id'] ?? get_option( 'page_on_front' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}
				$url    = polymart_ai_queue_diag_url( $tab, $extra );
				$active = $view === $tab ? ' style="font-weight:700;"' : '';
				echo '<a href="' . esc_url( $url ) . '"' . $active . '>' . esc_html( ucfirst( $tab ) ) . '</a> | ';
			}
			?>
		</p>

		<?php if ( ! empty( $data['error'] ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( (string) $data['error'] ); ?></p></div>
		<?php endif; ?>

		<?php if ( ( 'summary' === $view || '' === $view ) && ! empty( $data['summary'] ) ) : ?>
			<?php $s = $data['summary']; ?>
			<h2>Quick answer</h2>
			<table class="widefat striped" style="max-width:960px;">
				<tbody>
					<tr><th>Homepage ID</th><td><?php echo esc_html( polymart_ai_queue_diag_str( $s['page_on_front'] ?? '' ) ); ?> — <?php echo esc_html( (string) ( $s['page_on_front_title'] ?? '' ) ); ?></td></tr>
					<tr><th>Homepage needs work?</th><td><?php echo esc_html( polymart_ai_queue_diag_str( $s['homepage_snapshot']['needs_work'] ?? 'n/a' ) ); ?></td></tr>
					<tr><th>Homepage shows Persian on EN?</th><td><?php echo esc_html( polymart_ai_queue_diag_str( $s['homepage_snapshot']['storefront_persian'] ?? 'n/a' ) ); ?></td></tr>
					<tr><th>Remaining (light probe)</th><td><?php echo esc_html( polymart_ai_queue_diag_str( $s['remaining_light']['total'] ?? '' ) ); ?></td></tr>
					<tr><th>Start would log total</th><td><strong><?php echo esc_html( polymart_ai_queue_diag_str( $s['start_simulation']['simulation']['total'] ?? '' ) ); ?></strong> — <?php echo esc_html( polymart_ai_queue_diag_str( $s['start_simulation']['simulation']['would_log'] ?? '' ) ); ?></td></tr>
					<tr><th>Start seed IDs</th><td><code><?php echo esc_html( polymart_ai_queue_diag_str( $s['start_simulation']['simulation']['seed_ids'] ?? array() ) ); ?></code></td></tr>
					<tr><th>Would start fail?</th><td><?php echo esc_html( polymart_ai_queue_diag_str( $s['start_simulation']['simulation']['would_error'] ?? false ) ); ?></td></tr>
					<tr><th>Current job total</th><td><?php echo esc_html( polymart_ai_queue_diag_str( $s['current_job']['total'] ?? '' ) ); ?> (status: <?php echo esc_html( polymart_ai_queue_diag_str( $s['current_job']['status'] ?? '' ) ); ?>)</td></tr>
					<tr><th>Stats (index)</th><td><pre style="margin:0;"><?php echo esc_html( polymart_ai_queue_diag_str( $s['stats_index'] ?? array() ) ); ?></pre></td></tr>
					<tr><th>Pure JSON (compact)</th><td><a href="<?php echo esc_url( (string) ( $data['links']['json_compact'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $data['links']['json_compact'] ?? '' ) ); ?></a></td></tr>
					<tr><th>Pure JSON (ajax)</th><td><a href="<?php echo esc_url( (string) ( $data['links']['json_ajax'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $data['links']['json_ajax'] ?? '' ) ); ?></a></td></tr>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( 'homepage' === $view ) : ?>
			<h2>Homepage translated?</h2>
			<p><strong><?php echo ! empty( $data['is_homepage_translated'] ) ? 'YES (according to plugin checks)' : 'NO — still needs work or shows Persian'; ?></strong></p>
		<?php endif; ?>

		<?php if ( 'remaining' === $view && ! empty( $data['message'] ) ) : ?>
			<h2>Remaining work API</h2>
			<p><strong><?php echo esc_html( (string) $data['message'] ); ?></strong> — total=<?php echo esc_html( polymart_ai_queue_diag_str( $data['remaining']['total'] ?? 0 ) ); ?></p>
		<?php endif; ?>

		<?php if ( 'mismatch' === $view ) : ?>
			<h2>Index says translated but still needs work</h2>
			<p>Found: <strong><?php echo esc_html( (string) ( $data['count'] ?? 0 ) ); ?></strong></p>
		<?php endif; ?>

		<?php if ( 'post' === $view && ! empty( $data['post'] ) ) : ?>
			<?php
			$p     = $data['post'];
			$serve = (array) ( $p['can_serve_details'] ?? array() );
			$ok    = ! empty( $p['can_serve_elementor'] );
			?>
			<h2>Post <?php echo esc_html( (string) ( $p['post_id'] ?? '' ) ); ?> — Elementor storefront serve</h2>
			<div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?>" style="padding:12px;">
				<p><strong>can_serve_elementor:</strong> <?php echo $ok ? 'YES' : 'NO'; ?></p>
				<?php if ( ! empty( $serve['codes'] ) ) : ?>
					<p><strong>Codes:</strong> <code><?php echo esc_html( implode( ', ', array_map( 'strval', (array) $serve['codes'] ) ) ); ?></code></p>
				<?php endif; ?>
				<?php if ( ! empty( $serve['messages'] ) ) : ?>
					<ul style="margin:8px 0 0 1.2em;">
						<?php foreach ( (array) $serve['messages'] as $msg ) : ?>
							<li><?php echo esc_html( (string) $msg ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php elseif ( $ok ) : ?>
					<p>No blockers — Elementor translation JSON can be served on the storefront.</p>
				<?php else : ?>
					<p>No detail messages available (explain_elementor_storefront_serve_blockers missing or empty).</p>
				<?php endif; ?>
			</div>
			<p>
				Title: <strong><?php echo esc_html( (string) ( $p['title'] ?? '' ) ); ?></strong>
				— live_status: <code><?php echo esc_html( polymart_ai_queue_diag_str( $p['live_status'] ?? '' ) ); ?></code>
				— needs_work: <code><?php echo esc_html( polymart_ai_queue_diag_str( $p['needs_work'] ?? '' ) ); ?></code>
				— storefront_persian: <code><?php echo esc_html( polymart_ai_queue_diag_str( $p['storefront_persian'] ?? '' ) ); ?></code>
			</p>
		<?php endif; ?>

		<h2>Raw data (JSON)</h2>
		<textarea readonly rows="28" style="width:100%;font-family:monospace;font-size:12px;"><?php echo esc_textarea( wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ); ?></textarea>
	</div>
	<?php
}

/**
 * Admin page callback.
 *
 * @return void
 */
function polymart_ai_render_queue_diagnostic_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'polymart-ai' ) );
	}

	polymart_ai_queue_diag_bootstrap();

	register_shutdown_function(
		static function () {
			$error = error_get_last();

			if ( ! is_array( $error ) ) {
				return;
			}

			$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

			if ( ! in_array( (int) ( $error['type'] ?? 0 ), $fatal_types, true ) ) {
				return;
			}

			if ( headers_sent() ) {
				echo "\n<pre style=\"padding:12px;background:#fee;\">PolyMart diagnostic fatal: "
					. esc_html( (string) ( $error['message'] ?? 'unknown' ) )
					. ' in '
					. esc_html( (string) ( $error['file'] ?? '' ) )
					. ':'
					. esc_html( (string) ( $error['line'] ?? '' ) )
					. '</pre>';
			}
		}
	);

	$lang = sanitize_key( (string) ( $_GET['lang'] ?? 'en' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$view = sanitize_key( (string) ( $_GET['view'] ?? 'summary' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( '' === $view ) {
		$view = 'summary';
	}

	try {
		$data = polymart_ai_queue_diag_collect( $view, $lang, ! empty( $_GET['compact'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	} catch ( \Throwable $exception ) {
		$data = array(
			'error'   => $exception->getMessage(),
			'view'    => $view,
			'lang'    => $lang,
		);
	}

	polymart_ai_queue_diag_render_html( $data );
}

/**
 * User-facing troubleshoot page (رفع اشکال).
 *
 * @return void
 */
function polymart_ai_render_troubleshoot_page() {
	$cap = class_exists( '\PolymartAI\REST_API' )
		? \PolymartAI\REST_API::required_admin_capability()
		: 'manage_options';

	if ( ! current_user_can( $cap ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'polymart-ai' ) );
	}

	polymart_ai_queue_diag_bootstrap();

	$lang    = sanitize_key( (string) ( $_GET['lang'] ?? 'en' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$post_id = absint( $_GET['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( '' === $lang ) {
		$lang = 'en';
	}

	$data = array(
		'view' => 'troubleshoot',
		'lang' => $lang,
	);

	if ( $post_id > 0 ) {
		try {
			$data['troubleshoot'] = polymart_ai_queue_diag_troubleshoot_snapshot( $post_id, $lang );
		} catch ( \Throwable $exception ) {
			$data['error'] = $exception->getMessage();
		}
	}

	$json_url = add_query_arg(
		array(
			'page'     => 'polymart-ai-troubleshoot',
			'post_id'  => $post_id,
			'lang'     => $lang,
			'format'   => 'json',
		),
		admin_url( 'admin.php' )
	);

	$ajax_url = add_query_arg(
		array(
			'action'  => 'polymart_ai_queue_debug',
			'view'    => 'troubleshoot',
			'post_id' => $post_id,
			'lang'    => $lang,
		),
		admin_url( 'admin-ajax.php' )
	);

	$report = (array) ( $data['troubleshoot'] ?? array() );
	?>
	<div class="wrap polymart-troubleshoot">
		<h1>رفع اشکال ترجمه PolyMart</h1>
		<p>شناسه پست را وارد کن، گزارش کامل (قفل، stubborn، Action Scheduler، recovery) را ببین و JSON را برای توسعه‌دهنده بفرست.</p>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="max-width:720px;margin:16px 0 24px;padding:16px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;">
			<input type="hidden" name="page" value="polymart-ai-troubleshoot" />
			<table class="form-table" role="presentation" style="margin:0;">
				<tr>
					<th scope="row"><label for="polymart-troubleshoot-post-id">شناسه پست</label></th>
					<td><input name="post_id" id="polymart-troubleshoot-post-id" type="number" min="1" class="regular-text" value="<?php echo esc_attr( (string) $post_id ); ?>" placeholder="21870" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="polymart-troubleshoot-lang">زبان</label></th>
					<td><input name="lang" id="polymart-troubleshoot-lang" type="text" class="regular-text" value="<?php echo esc_attr( $lang ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row">جزئیات</th>
					<td>
						<label>
							<input type="checkbox" name="full" value="1" <?php checked( ! empty( $_GET['full'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?> />
							گزارش کامل (سنگین — ممکن است روی پست‌های بزرگ Elementor خطای ۵۰۳ بدهد)
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button( 'نمایش گزارش', 'primary', '', false ); ?>
		</form>

		<p style="max-width:720px;">
			گزارش پیش‌فرض سبک است (فقط متا، قفل، stubborn ذخیره‌شده، وضعیت صف).
			برای JSON سبک:
			<code><?php echo esc_html( add_query_arg( array( 'page' => 'polymart-ai-troubleshoot', 'post_id' => $post_id ?: 21870, 'lang' => $lang, 'format' => 'json' ), admin_url( 'admin.php' ) ) ); ?></code>
		</p>

		<?php if ( ! empty( $data['error'] ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( (string) $data['error'] ); ?></p></div>
		<?php endif; ?>

		<?php if ( $post_id > 0 && ! empty( $report ) && empty( $report['error'] ) ) : ?>
			<?php
			$recovery = (array) ( $report['recovery'] ?? array() );
			$scan     = (array) ( $report['elementor_scan'] ?? array() );
			$stubborn = (array) ( $report['stubborn_details'] ?? array() );
			$bulk     = (array) ( $report['bulk_job'] ?? array() );
			$lock     = (array) ( $report['lock'] ?? array() );
			?>
			<h2>#<?php echo esc_html( (string) $post_id ); ?> — <?php echo esc_html( (string) ( $report['title'] ?? '' ) ); ?></h2>
			<?php if ( ! empty( $report['light'] ) ) : ?>
				<div class="notice notice-info inline"><p>گزارش سبک (بدون decode کامل Elementor). برای گزارش کامل تیک «گزارش کامل» را بزن — روی پست‌های خیلی بزرگ ممکن است ۵۰۳ بدهد.</p></div>
			<?php endif; ?>
			<p>
				نوع: <code><?php echo esc_html( (string) ( $report['post_type'] ?? '' ) ); ?></code>
				<?php if ( ! empty( $report['edit_url'] ) ) : ?>
					— <a href="<?php echo esc_url( (string) $report['edit_url'] ); ?>">ویرایش پست</a>
				<?php endif; ?>
			</p>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;max-width:1100px;margin-bottom:20px;">
				<div style="background:#fff;border:1px solid #ccd0d4;padding:12px;border-radius:4px;">
					<strong>وضعیت زنده</strong><br />
					<code><?php echo esc_html( polymart_ai_queue_diag_str( $report['snapshot']['live_status'] ?? '' ) ); ?></code>
				</div>
				<div style="background:#fff;border:1px solid #ccd0d4;padding:12px;border-radius:4px;">
					<strong>پیشرفت Elementor</strong><br />
					<code><?php echo esc_html( polymart_ai_queue_diag_str( $recovery['progress_marker'] ?? '' ) ); ?></code>
				</div>
				<div style="background:#fff;border:1px solid #ccd0d4;padding:12px;border-radius:4px;">
					<strong>فیلد باقی‌مانده</strong><br />
					<code><?php echo esc_html( polymart_ai_queue_diag_str( $scan['remaining_field_count'] ?? $recovery['remaining_field_count'] ?? '' ) ); ?></code>
				</div>
				<div style="background:#fff;border:1px solid #ccd0d4;padding:12px;border-radius:4px;">
					<strong>قفل</strong><br />
					<code><?php echo esc_html( ! empty( $lock['locked'] ) ? 'فعال' : 'خاموش' ); ?></code>
				</div>
				<div style="background:#fff;border:1px solid #ccd0d4;padding:12px;border-radius:4px;">
					<strong>ترجمه خودکار</strong><br />
					<code><?php echo esc_html( polymart_ai_queue_diag_str( $bulk['status'] ?? 'idle' ) ); ?></code>
					<?php if ( ! empty( $bulk['pinned_on_post'] ) ) : ?> (روی همین پست)<?php endif; ?>
				</div>
				<div style="background:#fff;border:1px solid #ccd0d4;padding:12px;border-radius:4px;">
					<strong>AS events</strong><br />
					<code><?php echo esc_html( (string) count( (array) ( $report['action_scheduler_events'] ?? array() ) ) ); ?></code>
				</div>
			</div>

			<?php if ( ! empty( $report['recommendations'] ) ) : ?>
				<h3>پیشنهاد</h3>
				<ul style="max-width:900px;">
					<?php foreach ( (array) $report['recommendations'] as $hint ) : ?>
						<li><?php echo esc_html( (string) $hint ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $stubborn['fields'] ) ) : ?>
				<h3>فیلدهای سرسخت</h3>
				<table class="widefat striped" style="max-width:1100px;">
					<thead>
						<tr>
							<th>مسیر</th>
							<th>دلیل</th>
							<th>کاراکتر</th>
							<th>سگمنت</th>
							<th>پیش‌نمایش</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( (array) $stubborn['fields'] as $field ) : ?>
							<?php $field = (array) $field; ?>
							<tr>
								<td><code><?php echo esc_html( (string) ( $field['path'] ?? '' ) ); ?></code></td>
								<td><?php echo esc_html( (string) ( $field['reason_label'] ?? $field['reason'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $field['chars'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $field['segment_count'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $field['content']['preview'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h3>لینک JSON</h3>
			<ul style="font-family:monospace;font-size:13px;">
				<li><a href="<?php echo esc_url( $json_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $json_url ); ?></a></li>
				<li><a href="<?php echo esc_url( $ajax_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $ajax_url ); ?></a></li>
			</ul>

			<h3>JSON کامل (کپی کن و بفرست)</h3>
			<textarea id="polymart-troubleshoot-json" readonly rows="24" style="width:100%;font-family:monospace;font-size:12px;"><?php
				echo esc_textarea(
					wp_json_encode(
						array(
							'generated_at' => gmdate( 'c' ),
							'view'         => 'troubleshoot',
							'lang'         => $lang,
							'troubleshoot' => $report,
						),
						JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
					)
				);
			?></textarea>
			<p><button type="button" class="button" onclick="(function(){var t=document.getElementById('polymart-troubleshoot-json');t.select();document.execCommand('copy');})();">کپی JSON</button></p>
		<?php elseif ( $post_id > 0 && ! empty( $report['error'] ) ) : ?>
			<div class="notice notice-warning"><p><?php echo esc_html( polymart_ai_queue_diag_str( $report['error'] ) ); ?></p></div>
		<?php endif; ?>
	</div>
	<?php
}
