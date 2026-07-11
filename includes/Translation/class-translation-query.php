<?php
/**
 * Batched, memory-safe queries for the translation manager and jobs.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Class Translation_Query
 */
final class Translation_Query {

	/**
	 * Posts loaded per internal batch (keeps memory bounded on large catalogs).
	 */
	const BATCH_SIZE = 200;

	/**
	 * Posts indexed per admin request while the lookup index is warming up.
	 */
	const INDEX_BACKFILL_BATCH = 200;

	/**
	 * Minimum share of published posts flagged before trusting the SQL index.
	 */
	const INDEX_COVERAGE_THRESHOLD = 0.75;

	/**
	 * Aggregate translation stats by scanning the catalog in batches.
	 *
	 * Uses denormalized index meta when available (fast). Pass $force_full true
	 * for refresh_stats / explicit admin recomputation.
	 *
	 * @param string $lang       Target language code.
	 * @param bool   $force_full When true, always run a full per-post audit.
	 * @return array{total: int, untranslated: int, partial: int, translated: int}
	 */
	public static function compute_translation_stats( $lang, $force_full = false ) {
		$lang = sanitize_key( (string) $lang );

		self::maybe_backfill_translation_index( $lang );

		if ( ! $force_full ) {
			$indexed = self::compute_translation_stats_from_index( $lang );

			if ( null !== $indexed ) {
				return $indexed;
			}
		}

		Post_Translator::reconcile_all_flagged_translation_indexes( $lang );
		Post_Translator::flush_translation_status_cache();

		$total        = 0;
		$untranslated = 0;
		$partial      = 0;
		$translated   = 0;

		self::scan_posts(
			array(
				'post_type' => Post_Translator::get_supported_post_types(),
			),
			static function ( \WP_Post $post ) use ( $lang, &$total, &$untranslated, &$partial, &$translated ) {
				if ( ! Post_Translator::post_has_persian_content( $post ) ) {
					return;
				}

				++$total;

				$item_status = Post_Translator::get_translation_status( $post->ID, $lang );

				if ( 'untranslated' === $item_status ) {
					++$untranslated;
				} elseif ( 'partial' === $item_status ) {
					++$partial;
				} else {
					++$translated;
				}

				Post_Translator::sync_translation_index_meta( $post->ID, $lang );
			}
		);

		return array(
			'total'        => $total,
			'untranslated' => $untranslated,
			'partial'      => $partial,
			'translated'   => $translated,
		);
	}

	/**
	 * Paginated translation manager list using batched scans (no posts_per_page => -1).
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
	 */
	public static function get_translations_page( array $args ) {
		$lang       = sanitize_key( (string) ( $args['lang'] ?? 'en' ) );
		$status     = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$post_type  = sanitize_key( (string) ( $args['post_type'] ?? '' ) );
		$search     = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$page       = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page   = min( 50, max( 1, absint( $args['per_page'] ?? 20 ) ) );
		$title_key  = Post_Translator::get_meta_key( 'title', $lang );
		$post_types = Post_Translator::get_supported_post_types();

		if ( in_array( $post_type, $post_types, true ) ) {
			$post_types = array( $post_type );
		}

		self::maybe_backfill_translation_index( $lang );
		Post_Translator::reconcile_all_flagged_translation_indexes( $lang );
		Post_Translator::flush_translation_status_cache();

		$indexed = self::get_translations_page_from_index(
			array(
				'lang'       => $lang,
				'status'     => $status,
				'post_types' => $post_types,
				'search'     => $search,
				'page'       => $page,
				'per_page'   => $per_page,
				'title_key'  => $title_key,
			)
		);

		if ( null !== $indexed ) {
			return $indexed;
		}

		$query_args = array(
			'post_type' => $post_types,
			'search'    => $search,
		);

		$query_args = self::apply_title_status_meta_query( $query_args, $status, $title_key );

		$needed_offset = ( $page - 1 ) * $per_page;
		$matched_total = 0;
		$items         = array();

		self::scan_posts(
			$query_args,
			static function ( \WP_Post $post ) use (
				$lang,
				$status,
				$title_key,
				$needed_offset,
				$per_page,
				&$matched_total,
				&$items
			) {
				if ( ! Post_Translator::post_has_persian_content( $post ) ) {
					return;
				}

				$item_status = Post_Translator::get_translation_status( $post->ID, $lang );

				if ( '' !== $status && 'all' !== $status && $item_status !== $status ) {
					return;
				}

				if ( $matched_total >= $needed_offset && count( $items ) < $per_page ) {
					$translated_at = (int) get_post_meta( $post->ID, '_polymart_ai_translated_at_' . $lang, true );

					if ( $translated_at <= 0 ) {
						$translated_at = (int) get_post_meta( $post->ID, '_polymart_ai_translated_at', true );
					}

					$items[] = array(
						'post_id'         => (int) $post->ID,
						'post_type'       => $post->post_type,
						'post_type_label' => Post_Translator::get_post_type_label( $post->post_type ),
						'title_fa'        => $post->post_title,
						'title_en'        => (string) get_post_meta( $post->ID, $title_key, true ),
						'status'          => $item_status,
						'translated_at'   => $translated_at > 0 ? $translated_at : null,
						'edit_url'        => get_edit_post_link( $post->ID, 'raw' ),
					);
				}

				++$matched_total;

				Post_Translator::sync_translation_index_meta( $post->ID, $lang );
			}
		);

		$pages = (int) ceil( max( 1, $matched_total ) / $per_page );

		return array(
			'items'    => $items,
			'total'    => $matched_total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => max( 1, $pages ),
		);
	}

	/**
	 * Count published posts with Persian content (batched).
	 *
	 * @param array<string> $post_types Optional post type filter.
	 * @return int
	 */
	public static function count_persian_posts( array $post_types = array() ) {
		if ( empty( $post_types ) ) {
			$post_types = Post_Translator::get_supported_post_types();
		}

		$count = 0;

		self::scan_posts(
			array(
				'post_type' => $post_types,
			),
			static function ( \WP_Post $post ) use ( &$count ) {
				if ( Post_Translator::post_has_persian_content( $post ) ) {
					++$count;
				}
			}
		);

		return $count;
	}

	/**
	 * Count untranslated Persian posts for a language (batched).
	 *
	 * @param string        $lang       Target language code.
	 * @param array<string> $post_types Optional post type filter.
	 * @return int
	 */
	public static function count_untranslated_persian_posts( $lang, array $post_types = array() ) {
		$lang = sanitize_key( (string) $lang );

		if ( empty( $post_types ) ) {
			$post_types = Post_Translator::get_supported_post_types();
		}

		$count      = 0;
		$query_args = array(
			'post_type' => $post_types,
		);

		self::scan_posts(
			$query_args,
			static function ( \WP_Post $post ) use ( $lang, &$count ) {
				if ( Post_Translator::post_has_persian_content( $post ) && 'translated' !== Post_Translator::get_translation_status( $post->ID, $lang ) ) {
					++$count;
				}
			}
		);

		return $count;
	}

	/**
	 * Collect post IDs that still need translation for a language.
	 *
	 * @param string $lang  Target language code.
	 * @param int    $limit Maximum IDs to return.
	 * @return int[]
	 */
	public static function collect_remaining_post_ids( $lang, $limit = 50 ) {
		$lang       = sanitize_key( (string) $lang );
		$limit      = max( 1, absint( $limit ) );
		$post_ids   = array();
		$query_args = array(
			'post_type' => Post_Translator::get_supported_post_types(),
		);

		self::scan_posts(
			$query_args,
			static function ( \WP_Post $post ) use ( $lang, $limit, &$post_ids ) {
				if ( count( $post_ids ) >= $limit ) {
					return;
				}

				if ( Post_Translator::post_has_persian_content( $post ) && 'translated' !== Post_Translator::get_translation_status( $post->ID, $lang ) ) {
					$post_ids[] = (int) $post->ID;
				}
			}
		);

		return $post_ids;
	}

	/**
	 * Collect every post ID that still needs translation (no sample cap).
	 *
	 * @param string $lang Target language code.
	 * @return int[]
	 */
	public static function collect_all_remaining_post_ids( $lang ) {
		return self::collect_remaining_post_ids( $lang, 50000 );
	}

	/**
	 * Find the next untranslated Persian post ID after a cursor (for resumable jobs).
	 *
	 * @param string $lang        Target language code.
	 * @param int    $after_id    Last processed post ID (0 = from start).
	 * @param int[]  $exclude_ids Post IDs to skip (e.g. exhausted retries).
	 * @return int Post ID or 0 when none remain.
	 */
	public static function find_next_untranslated_post_id( $lang, $after_id = 0, array $exclude_ids = array() ) {
		$fast = self::find_next_actionable_post_id( $lang, $after_id, $exclude_ids );

		if ( $fast > 0 ) {
			return $fast;
		}

		$lang         = sanitize_key( (string) $lang );
		$after_id     = absint( $after_id );
		$exclude_ids  = array_filter( array_map( 'absint', $exclude_ids ) );
		$exclude_lookup = array_fill_keys( $exclude_ids, true );
		$query_args   = array(
			'post_type' => Post_Translator::get_supported_post_types(),
		);

		$cursor_filter = static function ( $where ) use ( $after_id ) {
			if ( $after_id > 0 ) {
				global $wpdb;
				$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
			}

			return $where;
		};

		add_filter( 'posts_where', $cursor_filter, 10, 1 );

		$query = new \WP_Query(
			self::build_wp_query_args(
				$query_args,
				array(
					'posts_per_page'         => 1,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			)
		);

		remove_filter( 'posts_where', $cursor_filter, 10 );

		foreach ( $query->posts as $post_id ) {
			$post_id = absint( $post_id );

			if ( isset( $exclude_lookup[ $post_id ] ) ) {
				continue;
			}

			if ( $post_id && Post_Translator::has_persian_content( $post_id ) && 'translated' !== Post_Translator::get_translation_status( $post_id, $lang ) ) {
				return $post_id;
			}
		}

		// First candidate may lack Persian content; scan forward in small batches.
		$scan_after = $after_id;
		$attempts   = 0;
		$max_scan   = 8;

		while ( $attempts < $max_scan ) {
			++$attempts;
			$batch_ids = self::fetch_untranslated_ids_after( $lang, $scan_after, self::BATCH_SIZE );

			if ( empty( $batch_ids ) ) {
				return 0;
			}

			foreach ( $batch_ids as $post_id ) {
				$scan_after = max( $scan_after, $post_id );

				if ( isset( $exclude_lookup[ $post_id ] ) ) {
					continue;
				}

				if ( Post_Translator::has_persian_content( $post_id ) && 'translated' !== Post_Translator::get_translation_status( $post_id, $lang ) ) {
					return $post_id;
				}
			}

			do_action( 'polymart_ai_worker_heartbeat' );
		}

		return 0;
	}

	/**
	 * Fast SQL lookup for the next untranslated/partial Persian post (job picker).
	 *
	 * @param string $lang        Target language code.
	 * @param int    $after_id    Resume cursor (post ID).
	 * @param int[]  $exclude_ids Post IDs to skip.
	 * @return int Post ID or 0.
	 */
	public static function find_next_actionable_post_id( $lang, $after_id = 0, array $exclude_ids = array() ) {
		$lang        = sanitize_key( (string) $lang );
		$after_id    = absint( $after_id );
		$exclude_ids = array_values( array_filter( array_map( 'absint', $exclude_ids ) ) );

		self::maybe_backfill_translation_index( $lang );

		$indexed = self::find_next_indexed_actionable_post_id( $lang, $after_id, $exclude_ids );

		if ( $indexed > 0 ) {
			return $indexed;
		}

		return 0;
	}

	/**
	 * Collect the first N actionable post IDs for job queue seeding.
	 *
	 * @param string $lang        Target language code.
	 * @param int    $limit       Maximum IDs.
	 * @param int[]  $exclude_ids Post IDs to skip.
	 * @return int[]
	 */
	public static function seed_actionable_post_ids( $lang, $limit = 20, array $exclude_ids = array() ) {
		$lang        = sanitize_key( (string) $lang );
		$limit       = max( 1, min( 50, absint( $limit ) ) );
		$exclude_ids = array_values( array_filter( array_map( 'absint', $exclude_ids ) ) );

		self::maybe_backfill_translation_index( $lang );

		$ids = self::fetch_indexed_actionable_post_ids( $lang, 0, $exclude_ids, $limit );

		if ( ! empty( $ids ) ) {
			return $ids;
		}

		$cursor = 0;
		$found  = array();

		while ( count( $found ) < $limit ) {
			$next = self::find_next_untranslated_post_id(
				$lang,
				$cursor,
				array_merge( $exclude_ids, $found )
			);

			if ( $next <= 0 ) {
				break;
			}

			$found[] = $next;
			$cursor  = $next;

			if ( count( $found ) >= 3 ) {
				break;
			}
		}

		return $found;
	}

	/**
	 * @param string $lang        Target language.
	 * @param int    $after_id    Cursor post ID.
	 * @param int[]  $exclude_ids Excluded post IDs.
	 * @param int    $limit       Max IDs.
	 * @return int[]
	 */
	private static function fetch_indexed_actionable_post_ids( $lang, $after_id, array $exclude_ids, $limit ) {
		global $wpdb;

		$limit      = max( 1, min( 50, absint( $limit ) ) );
		$post_types = Post_Translator::get_supported_post_types();
		$status_key = Post_Translator::get_status_index_meta_key( $lang );
		$clause     = self::build_post_type_in_clause( $post_types );
		$after_sql  = $after_id > 0 ? ' AND p.ID > %d ' : '';
		$exclude_sql = '';
		$exclude_vals = array();

		if ( ! empty( $exclude_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $exclude_ids ), '%d' ) );
			$exclude_sql  = " AND p.ID NOT IN ( {$placeholders} ) ";
			$exclude_vals = $exclude_ids;
		}

		$sql = "SELECT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm_flag
				ON p.ID = pm_flag.post_id
				AND pm_flag.meta_key = %s
				AND pm_flag.meta_value = '1'
			LEFT JOIN {$wpdb->postmeta} pm_status
				ON p.ID = pm_status.post_id
				AND pm_status.meta_key = %s
			WHERE p.post_status = 'publish' {$clause['sql']}
				{$after_sql}
				{$exclude_sql}
				AND ( pm_status.meta_id IS NULL OR pm_status.meta_value IN ( 'untranslated', 'partial' ) )
			ORDER BY p.ID ASC
			LIMIT %d";

		$prepare_args = array_merge(
			array( Post_Translator::PERSIAN_CONTENT_FLAG_META, $status_key ),
			$clause['values'],
			$after_id > 0 ? array( $after_id ) : array(),
			$exclude_vals,
			array( $limit )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $prepare_args ) );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', $rows ) ) );
	}

	/**
	 * @param string $lang        Target language.
	 * @param int    $after_id    Cursor post ID.
	 * @param int[]  $exclude_ids Excluded post IDs.
	 * @return int
	 */
	private static function find_next_indexed_actionable_post_id( $lang, $after_id, array $exclude_ids ) {
		global $wpdb;

		$post_types = Post_Translator::get_supported_post_types();
		$status_key = Post_Translator::get_status_index_meta_key( $lang );
		$clause     = self::build_post_type_in_clause( $post_types );
		$after_sql  = $after_id > 0 ? ' AND p.ID > %d ' : '';
		$exclude_sql = '';
		$exclude_vals = array();

		if ( ! empty( $exclude_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $exclude_ids ), '%d' ) );
			$exclude_sql  = " AND p.ID NOT IN ( {$placeholders} ) ";
			$exclude_vals = $exclude_ids;
		}

		$sql = "SELECT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm_flag
				ON p.ID = pm_flag.post_id
				AND pm_flag.meta_key = %s
				AND pm_flag.meta_value = '1'
			LEFT JOIN {$wpdb->postmeta} pm_status
				ON p.ID = pm_status.post_id
				AND pm_status.meta_key = %s
			WHERE p.post_status = 'publish' {$clause['sql']}
				{$after_sql}
				{$exclude_sql}
				AND ( pm_status.meta_id IS NULL OR pm_status.meta_value IN ( 'untranslated', 'partial' ) )
			ORDER BY p.ID ASC
			LIMIT 1";

		$prepare_args = array_merge(
			array( Post_Translator::PERSIAN_CONTENT_FLAG_META, $status_key ),
			$clause['values'],
			$after_id > 0 ? array( $after_id ) : array(),
			$exclude_vals
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return absint( $wpdb->get_var( $wpdb->prepare( $sql, $prepare_args ) ) );
	}

	/**
	 * Collect untranslated post IDs in batches (for bulk backlog endpoint).
	 *
	 * @param string $lang       Target language code.
	 * @param int    $limit      Maximum IDs to return.
	 * @param int    $after_id   Resume cursor.
	 * @return array{post_ids: int[], total: int, truncated: bool, scanned_through: int}
	 */
	public static function collect_untranslated_post_ids( $lang, $limit = 500, $after_id = 0 ) {
		$lang       = sanitize_key( (string) $lang );
		$limit      = max( 1, absint( $limit ) );
		$after_id   = absint( $after_id );
		$query_args = array(
			'post_type' => Post_Translator::get_supported_post_types(),
		);

		$post_ids         = array();
		$scanned_through  = $after_id;
		$matched_total    = 0;
		$paged            = 1;
		$cursor_filter    = static function ( $where ) use ( $after_id ) {
			if ( $after_id > 0 ) {
				global $wpdb;
				$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
			}

			return $where;
		};

		add_filter( 'posts_where', $cursor_filter, 10, 1 );

		do {
			$query = new \WP_Query(
				self::build_wp_query_args(
					$query_args,
					array(
						'posts_per_page'         => self::BATCH_SIZE,
						'paged'                  => $paged,
						'orderby'                => 'ID',
						'order'                  => 'ASC',
						'fields'                 => 'ids',
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				)
			);

			if ( empty( $query->posts ) ) {
				break;
			}

			foreach ( $query->posts as $post_id ) {
				$post_id = absint( $post_id );
				$scanned_through = max( $scanned_through, $post_id );

				if ( ! $post_id || ! Post_Translator::has_persian_content( $post_id ) || 'translated' === Post_Translator::get_translation_status( $post_id, $lang ) ) {
					continue;
				}

				++$matched_total;

				if ( count( $post_ids ) < $limit ) {
					$post_ids[] = $post_id;
				}
			}

			++$paged;
		} while ( $paged <= (int) $query->max_num_pages );

		remove_filter( 'posts_where', $cursor_filter, 10 );

		$truncated = count( $post_ids ) >= $limit;

		return array(
			'post_ids'          => $post_ids,
			'total'             => $matched_total,
			'truncated'         => $truncated,
			'scanned_through'   => $scanned_through,
		);
	}

	/**
	 * Iterate published posts in fixed-size batches.
	 *
	 * @param array<string, mixed> $args     post_type, search, meta_query keys.
	 * @param callable             $callback Receives WP_Post instances.
	 * @return void
	 */
	public static function scan_posts( array $args, callable $callback ) {
		$paged = 1;

		do {
			$query = new \WP_Query(
				self::build_wp_query_args(
					$args,
					array(
						'posts_per_page'         => self::BATCH_SIZE,
						'paged'                  => $paged,
						'orderby'                => 'modified',
						'order'                  => 'DESC',
						'no_found_rows'          => false,
						'update_post_meta_cache' => true,
						'update_post_term_cache' => false,
					)
				)
			);

			if ( empty( $query->posts ) ) {
				break;
			}

			foreach ( $query->posts as $post ) {
				if ( $post instanceof \WP_Post ) {
					$callback( $post );
				}
			}

			/**
			 * Fires between batched catalog scans so long DB walks keep the job heartbeat fresh.
			 */
			do_action( 'polymart_ai_worker_heartbeat' );

			++$paged;
		} while ( $paged <= (int) $query->max_num_pages );
	}

	/**
	 * Fetch a batch of untranslated post IDs after a cursor.
	 *
	 * @param string $lang     Target language.
	 * @param int    $after_id Cursor post ID.
	 * @param int    $limit    Batch size.
	 * @return int[]
	 */
	private static function fetch_untranslated_ids_after( $lang, $after_id, $limit ) {
		$query_args = array(
			'post_type' => Post_Translator::get_supported_post_types(),
		);

		$cursor_filter = static function ( $where ) use ( $after_id ) {
			if ( $after_id > 0 ) {
				global $wpdb;
				$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
			}

			return $where;
		};

		add_filter( 'posts_where', $cursor_filter, 10, 1 );

		$query = new \WP_Query(
			self::build_wp_query_args(
				$query_args,
				array(
					'posts_per_page'         => max( 1, absint( $limit ) ),
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			)
		);

		remove_filter( 'posts_where', $cursor_filter, 10 );

		return array_map( 'absint', $query->posts );
	}

	/**
	 * Merge shared defaults into a WP_Query argument array.
	 *
	 * @param array<string, mixed> $args    Custom args (post_type, search, meta_query).
	 * @param array<string, mixed> $overrides Pagination / ordering overrides.
	 * @return array<string, mixed>
	 */
	private static function build_wp_query_args( array $args, array $overrides = array() ) {
		$query_args = array_merge(
			array(
				'post_status'            => 'publish',
				'posts_per_page'         => self::BATCH_SIZE,
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			),
			$overrides
		);

		if ( ! empty( $args['post_type'] ) ) {
			$query_args['post_type'] = $args['post_type'];
		}

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = $args['search'];
		}

		if ( ! empty( $args['meta_query'] ) ) {
			$query_args['meta_query'] = $args['meta_query'];
		}

		return $query_args;
	}

	/**
	 * Attach a title-meta filter for coarse untranslated/translated SQL filtering.
	 *
	 * @param array<string, mixed> $args      Query args accumulator.
	 * @param string               $status    untranslated|translated|all|partial|''.
	 * @param string               $title_key Translated title meta key.
	 * @return array<string, mixed>
	 */
	private static function apply_title_status_meta_query( array $args, $status, $title_key ) {
		if ( 'translated' === $status ) {
			$args['meta_query'] = array(
				array(
					'key'     => $title_key,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => $title_key,
					'value'   => '',
					'compare' => '!=',
				),
			);
		}

		return $args;
	}

	/**
	 * Warm the denormalized translation index in small batches.
	 *
	 * @param string $lang Target language code.
	 * @return void
	 */
	private static function maybe_backfill_translation_index( $lang ) {
		if ( self::translation_index_is_usable() ) {
			return;
		}

		$query = new \WP_Query(
			self::build_wp_query_args(
				array(
					'post_type' => Post_Translator::get_supported_post_types(),
				),
				array(
					'posts_per_page'         => self::INDEX_BACKFILL_BATCH,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'meta_query'             => array(
						array(
							'key'     => Post_Translator::PERSIAN_CONTENT_FLAG_META,
							'compare' => 'NOT EXISTS',
						),
					),
				)
			)
		);

		foreach ( array_map( 'absint', $query->posts ) as $post_id ) {
			if ( $post_id > 0 ) {
				Post_Translator::sync_translation_index_meta( $post_id, $lang );
			}
		}
	}

	/**
	 * Whether enough posts are indexed to trust SQL lookups.
	 *
	 * @return bool
	 */
	private static function translation_index_is_usable() {
		$coverage = self::get_translation_index_coverage();

		return $coverage['flagged'] > 0
			&& ( $coverage['ratio'] >= self::INDEX_COVERAGE_THRESHOLD || $coverage['unindexed'] <= 0 );
	}

	/**
	 * Share of published supported posts that already carry the Persian flag meta.
	 *
	 * @return array{published: int, flagged: int, unindexed: int, ratio: float}
	 */
	private static function get_translation_index_coverage() {
		global $wpdb;

		$post_types = Post_Translator::get_supported_post_types();

		if ( empty( $post_types ) ) {
			return array(
				'published' => 0,
				'flagged'   => 0,
				'unindexed' => 0,
				'ratio'     => 1.0,
			);
		}

		$clause = self::build_post_type_in_clause( $post_types );

		$published = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status = 'publish' {$clause['sql']}",
				$clause['values']
			)
		);

		if ( $published <= 0 ) {
			return array(
				'published' => 0,
				'flagged'   => 0,
				'unindexed' => 0,
				'ratio'     => 1.0,
			);
		}

		$flagged = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm
					ON p.ID = pm.post_id
					AND pm.meta_key = %s
					AND pm.meta_value = '1'
				WHERE p.post_status = 'publish' {$clause['sql']}",
				array_merge( array( Post_Translator::PERSIAN_CONTENT_FLAG_META ), $clause['values'] )
			)
		);

		$unindexed = max( 0, $published - $flagged );

		return array(
			'published' => $published,
			'flagged'   => $flagged,
			'unindexed' => $unindexed,
			'ratio'     => $flagged / $published,
		);
	}

	/**
	 * Aggregate translation stats from denormalized index meta.
	 *
	 * @param string $lang Target language code.
	 * @return array{total: int, untranslated: int, partial: int, translated: int}|null
	 */
	private static function compute_translation_stats_from_index( $lang ) {
		if ( ! self::translation_index_is_usable() ) {
			return null;
		}

		global $wpdb;

		$post_types  = Post_Translator::get_supported_post_types();
		$status_key  = Post_Translator::get_status_index_meta_key( $lang );
		$clause      = self::build_post_type_in_clause( $post_types );
		$total       = self::count_indexed_persian_posts( $post_types );
		$translated  = 0;
		$partial     = 0;
		$untranslated = 0;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(pm_status.meta_value, 'untranslated') AS status_value, COUNT(DISTINCT p.ID) AS item_count
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_flag
					ON p.ID = pm_flag.post_id
					AND pm_flag.meta_key = %s
					AND pm_flag.meta_value = '1'
				LEFT JOIN {$wpdb->postmeta} pm_status
					ON p.ID = pm_status.post_id
					AND pm_status.meta_key = %s
				WHERE p.post_status = 'publish' {$clause['sql']}
				GROUP BY status_value",
				array_merge(
					array( Post_Translator::PERSIAN_CONTENT_FLAG_META, $status_key ),
					$clause['values']
				)
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return null;
		}

		foreach ( $rows as $row ) {
			$count = (int) ( $row['item_count'] ?? 0 );
			$value = sanitize_key( (string) ( $row['status_value'] ?? 'untranslated' ) );

			if ( 'translated' === $value ) {
				$translated += $count;
			} elseif ( 'partial' === $value ) {
				$partial += $count;
			} else {
				$untranslated += $count;
			}
		}

		return array(
			'total'        => $total,
			'untranslated' => $untranslated,
			'partial'      => $partial,
			'translated'   => $translated,
		);
	}

	/**
	 * Paginated translation manager list backed by denormalized index meta.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int}|null
	 */
	private static function get_translations_page_from_index( array $args ) {
		if ( ! self::translation_index_is_usable() ) {
			return null;
		}

		$lang       = sanitize_key( (string) ( $args['lang'] ?? 'en' ) );
		$status     = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$post_types = is_array( $args['post_types'] ?? null ) ? $args['post_types'] : Post_Translator::get_supported_post_types();
		$search     = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$page       = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page   = min( 50, max( 1, absint( $args['per_page'] ?? 20 ) ) );
		$title_key  = (string) ( $args['title_key'] ?? Post_Translator::get_meta_key( 'title', $lang ) );
		$status_key = Post_Translator::get_status_index_meta_key( $lang );

		$query = new \WP_Query(
			self::build_wp_query_args(
				array(
					'post_type'  => $post_types,
					'search'     => $search,
					'meta_query' => self::build_index_meta_query( $status, $status_key ),
				),
				array(
					'posts_per_page'         => $per_page,
					'paged'                  => $page,
					'orderby'                => 'modified',
					'order'                  => 'DESC',
					'no_found_rows'          => false,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
				)
			)
		);

		$items = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$item_status = Post_Translator::get_translation_status( $post->ID, $lang );

			$translated_at = (int) get_post_meta( $post->ID, '_polymart_ai_translated_at_' . $lang, true );

			if ( $translated_at <= 0 ) {
				$translated_at = (int) get_post_meta( $post->ID, '_polymart_ai_translated_at', true );
			}

			$items[] = array(
				'post_id'         => (int) $post->ID,
				'post_type'       => $post->post_type,
				'post_type_label' => Post_Translator::get_post_type_label( $post->post_type ),
				'title_fa'        => $post->post_title,
				'title_en'        => (string) get_post_meta( $post->ID, $title_key, true ),
				'status'          => $item_status,
				'translated_at'   => $translated_at > 0 ? $translated_at : null,
				'edit_url'        => get_edit_post_link( $post->ID, 'raw' ),
			);
		}

		$matched_total = (int) $query->found_posts;

		if ( '' !== $status && 'all' !== $status ) {
			$matched_total = self::count_indexed_posts_by_status( $post_types, $status_key, $status );
		} elseif ( '' === $search ) {
			$matched_total = self::count_indexed_persian_posts( $post_types );
		}

		$pages = (int) ceil( max( 1, $matched_total ) / $per_page );

		return array(
			'items'    => $items,
			'total'    => $matched_total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => max( 1, $pages ),
		);
	}

	/**
	 * Build meta_query filters for indexed translation list queries.
	 *
	 * @param string $status     Status filter.
	 * @param string $status_key Indexed status meta key.
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_index_meta_query( $status, $status_key ) {
		$meta_query = array(
			array(
				'key'     => Post_Translator::PERSIAN_CONTENT_FLAG_META,
				'value'   => '1',
				'compare' => '=',
			),
		);

		if ( 'translated' === $status ) {
			$meta_query[] = array(
				'key'     => $status_key,
				'value'   => 'translated',
				'compare' => '=',
			);
		} elseif ( 'partial' === $status ) {
			$meta_query[] = array(
				'key'     => $status_key,
				'value'   => 'partial',
				'compare' => '=',
			);
		} elseif ( 'untranslated' === $status ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => $status_key,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => $status_key,
					'value'   => 'untranslated',
					'compare' => '=',
				),
			);
		}

		return $meta_query;
	}

	/**
	 * Count indexed Persian posts for supported post types.
	 *
	 * @param array<string> $post_types Post types.
	 * @return int
	 */
	private static function count_indexed_persian_posts( array $post_types ) {
		global $wpdb;

		$clause = self::build_post_type_in_clause( $post_types );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm
					ON p.ID = pm.post_id
					AND pm.meta_key = %s
					AND pm.meta_value = '1'
				WHERE p.post_status = 'publish' {$clause['sql']}",
				array_merge( array( Post_Translator::PERSIAN_CONTENT_FLAG_META ), $clause['values'] )
			)
		);
	}

	/**
	 * Count indexed posts for a specific translation status.
	 *
	 * @param array<string> $post_types Post types.
	 * @param string        $status_key Indexed status meta key.
	 * @param string        $status     Status filter.
	 * @return int
	 */
	private static function count_indexed_posts_by_status( array $post_types, $status_key, $status ) {
		global $wpdb;

		$clause = self::build_post_type_in_clause( $post_types );

		if ( 'untranslated' === $status ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID)
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm_flag
						ON p.ID = pm_flag.post_id
						AND pm_flag.meta_key = %s
						AND pm_flag.meta_value = '1'
					LEFT JOIN {$wpdb->postmeta} pm_status
						ON p.ID = pm_status.post_id
						AND pm_status.meta_key = %s
					WHERE p.post_status = 'publish' {$clause['sql']}
						AND (pm_status.meta_id IS NULL OR pm_status.meta_value = %s)",
					array_merge(
						array( Post_Translator::PERSIAN_CONTENT_FLAG_META, $status_key, 'untranslated' ),
						$clause['values']
					)
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_flag
					ON p.ID = pm_flag.post_id
					AND pm_flag.meta_key = %s
					AND pm_flag.meta_value = '1'
				INNER JOIN {$wpdb->postmeta} pm_status
					ON p.ID = pm_status.post_id
					AND pm_status.meta_key = %s
					AND pm_status.meta_value = %s
				WHERE p.post_status = 'publish' {$clause['sql']}",
				array_merge(
					array( Post_Translator::PERSIAN_CONTENT_FLAG_META, $status_key, $status ),
					$clause['values']
				)
			)
		);
	}

	/**
	 * Build a prepared SQL IN clause for supported post types.
	 *
	 * @param array<string> $post_types Post types.
	 * @return array{sql: string, values: array<int, string>}
	 */
	private static function build_post_type_in_clause( array $post_types ) {
		$post_types = array_values(
			array_filter(
				array_map( 'sanitize_key', $post_types )
			)
		);

		if ( empty( $post_types ) ) {
			return array(
				'sql'    => '',
				'values' => array(),
			);
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		return array(
			'sql'    => " AND post_type IN ( {$placeholders} ) ",
			'values' => $post_types,
		);
	}
}
