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
 *   JSON:      append &format=json to any view
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
		add_submenu_page(
			null,
			'PolyMart Queue Debug',
			'Queue Debug',
			'manage_options',
			'polymart-ai-queue-debug',
			'polymart_ai_render_queue_diagnostic_page'
		);
	}
);

/**
 * @return string
 */
function polymart_ai_queue_diag_base_url() {
	return admin_url( 'admin.php?page=polymart-ai-queue-debug' );
}

/**
 * @param string               $view   View slug.
 * @param array<string, mixed> $extra  Extra query args.
 * @return string
 */
function polymart_ai_queue_diag_url( $view = 'summary', array $extra = array() ) {
	$args = array_merge(
		array(
			'page' => 'polymart-ai-queue-debug',
			'view' => sanitize_key( (string) $view ),
		),
		$extra
	);

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

	if ( \PolymartAI\Translation\Post_Translator::uses_elementor_builder( $post_id ) ) {
		\PolymartAI\Translation\Post_Translator::repair_stale_elementor_completion_meta( $post_id, $lang );
		\PolymartAI\Translation\Post_Translator::repair_completed_elementor_job_meta( $post_id, $lang );
		\PolymartAI\Translation\Post_Translator::flush_translation_status_cache( $post_id );
	}

	$elementor_key = method_exists( '\PolymartAI\Translation\Post_Translator', 'get_elementor_meta_key' )
		? \PolymartAI\Translation\Post_Translator::get_elementor_meta_key( $lang )
		: '_elementor_data_' . $lang;

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
		'can_serve_elementor'  => \PolymartAI\Translation\Post_Translator::can_serve_stored_elementor_json_on_storefront( $post_id, $lang ),
		'elementor_storefront_ready' => method_exists( '\PolymartAI\Translation\Post_Translator', 'elementor_translation_is_storefront_ready' )
			? \PolymartAI\Translation\Post_Translator::elementor_translation_is_storefront_ready( $post_id, $lang )
			: null,
		'stored_elementor_persian' => \PolymartAI\Translation\Post_Translator::stored_elementor_translation_has_persian( $post_id, $lang ),
		'needs_elementor_job'  => \PolymartAI\Translation\Post_Translator::post_needs_elementor_job_work( $post_id, $lang ),
		'gap_reason'           => \PolymartAI\Translation\Post_Translator::describe_translation_gap( $post_id, $lang ),
		'gaps_missing'         => array_values( array_map( 'strval', (array) ( \PolymartAI\Translation\Post_Translator::get_translation_gaps( $post_id, $lang )['missing'] ?? array() ) ) ),
		'meta'                 => array(
			'_elementor_edit_mode'                  => (string) get_post_meta( $post_id, '_elementor_edit_mode', true ),
			$elementor_key . '_bytes'               => strlen( (string) get_post_meta( $post_id, $elementor_key, true ) ),
			'_polymart_ai_elementor_finalized_' . $lang => (string) get_post_meta( $post_id, '_polymart_ai_elementor_finalized_' . $lang, true ),
			'_polymart_ai_elementor_progress_' . $lang  => (string) get_post_meta( $post_id, '_polymart_ai_elementor_progress_' . $lang, true ),
			'_polymart_ai_elementor_error_' . $lang       => (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true ),
			'title_en'                              => (string) get_post_meta( $post_id, \PolymartAI\Translation\Post_Translator::get_meta_key( 'title', $lang ), true ),
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
function polymart_ai_queue_diag_summary( $lang ) {
	$lang = sanitize_key( (string) $lang );
	$job  = \PolymartAI\Activity_Logger::get_job( false );
	$front = absint( get_option( 'page_on_front' ) );

	$as_available = class_exists( '\PolymartAI\Activity_Logger\Job_Action_Scheduler' )
		&& \PolymartAI\Activity_Logger\Job_Action_Scheduler::is_available();

	$as_pending = $as_available && \PolymartAI\Activity_Logger\Job_Action_Scheduler::has_pending_or_running();

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
		'remaining_light' => polymart_ai_queue_diag_remaining_light( $lang ),
		'homepage_snapshot' => $front > 0 ? polymart_ai_queue_diag_post_snapshot( $front, $lang ) : null,
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
 * @param string $view View slug.
 * @param string $lang Language code.
 * @return array<string, mixed>
 */
function polymart_ai_queue_diag_collect( $view, $lang ) {
	polymart_ai_queue_diag_bootstrap();

	$view = sanitize_key( (string) $view );
	$lang = sanitize_key( (string) $lang );

	if ( '' === $view ) {
		$view = 'summary';
	}

	if ( '' === $lang ) {
		$lang = 'en';
	}

	$data = array(
		'generated_at' => gmdate( 'c' ),
		'view'         => $view,
		'lang'         => $lang,
		'links'        => array(
			'summary'   => polymart_ai_queue_diag_url( 'summary', array( 'lang' => $lang ) ),
			'homepage'  => polymart_ai_queue_diag_url( 'homepage', array( 'lang' => $lang ) ),
			'queue'     => polymart_ai_queue_diag_url( 'queue', array( 'lang' => $lang ) ),
			'remaining' => polymart_ai_queue_diag_url( 'remaining', array( 'lang' => $lang ) ),
			'mismatch'  => polymart_ai_queue_diag_url( 'mismatch', array( 'lang' => $lang ) ),
			'json'      => polymart_ai_queue_diag_url( $view, array( 'lang' => $lang, 'format' => 'json' ) ),
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
			$post_id = absint( $_GET['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$data['post'] = polymart_ai_queue_diag_post_snapshot( $post_id, $lang );
			break;

		default:
			$data['summary'] = polymart_ai_queue_diag_summary( $lang );
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
			foreach ( array( 'summary', 'homepage', 'queue', 'remaining', 'mismatch' ) as $tab ) {
				$url   = polymart_ai_queue_diag_url( $tab, array( 'lang' => $lang ) );
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
					<tr><th>Stats (index / full)</th><td><pre style="margin:0;"><?php echo esc_html( polymart_ai_queue_diag_str( $s['stats_index'] ?? array() ) . "\n" . polymart_ai_queue_diag_str( $s['stats_full'] ?? array() ) ); ?></pre></td></tr>
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

	$lang   = sanitize_key( (string) ( $_GET['lang'] ?? 'en' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$view   = sanitize_key( (string) ( $_GET['view'] ?? 'summary' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$format = sanitize_key( (string) ( $_GET['format'] ?? 'html' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( '' === $view ) {
		$view = 'summary';
	}

	try {
		$data = polymart_ai_queue_diag_collect( $view, $lang );
	} catch ( \Throwable $exception ) {
		$data = array(
			'error'   => $exception->getMessage(),
			'view'    => $view,
			'lang'    => $lang,
		);
	}

	if ( 'json' === $format ) {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		exit;
	}

	polymart_ai_queue_diag_render_html( $data );
}
