<?php
/**
 * One-time / maintenance sync for WVE variation translation meta keys.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation\WooCommerce;

use PolymartAI\Language_Registry;

use PolymartAI\Translation\Post_Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Variation_Translation_Sync
 */
final class Variation_Translation_Sync {

	/**
	 * Count published product variations.
	 *
	 * @return int
	 */
	public static function count_variations() {
		if ( ! post_type_exists( 'product_variation' ) ) {
			return 0;
		}

		$counts = wp_count_posts( 'product_variation' );

		return $counts instanceof \stdClass ? absint( $counts->publish ?? 0 ) : 0;
	}

	/**
	 * Sync variation translation meta to the canonical dual-key structure.
	 *
	 * Ensures `_polymart_ai_title_{lang}` and `_custom_title_{lang}` (and description
	 * companions) stay aligned, then refreshes source fingerprints and parent snapshots.
	 *
	 * @param array<string, mixed> $args {
	 *     @type bool   $dry_run When true, report changes without writing.
	 *     @type string $lang    Limit to one language code, or empty for all targets.
	 *     @type int    $limit   Batch size (1–500).
	 *     @type int    $offset  Variation batch offset.
	 * }
	 * @return array<string, mixed>
	 */
	public static function run( array $args = array() ) {
		$dry_run = ! empty( $args['dry_run'] );
		$lang    = sanitize_key( (string) ( $args['lang'] ?? '' ) );
		$limit   = max( 1, min( 500, absint( $args['limit'] ?? 200 ) ) );
		$offset  = absint( $args['offset'] ?? 0 );

		$languages = self::resolve_target_languages( $lang );
		$stats     = array(
			'variations_scanned'        => 0,
			'title_companion_synced'    => 0,
			'title_polymart_backfilled' => 0,
			'description_companion_synced'    => 0,
			'description_polymart_backfilled' => 0,
			'hashes_refreshed'          => 0,
			'parents_touched'           => 0,
			'dry_run'                   => $dry_run,
		);

		if ( empty( $languages ) || ! post_type_exists( 'product_variation' ) ) {
			return self::build_result( $stats, $offset, $limit, 0 );
		}

		$variation_ids = get_posts(
			array(
				'post_type'              => 'product_variation',
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache'=> false,
			)
		);

		$parents = array();

		foreach ( $variation_ids as $variation_id ) {
			$variation_id = absint( $variation_id );

			if ( $variation_id <= 0 ) {
				continue;
			}

			++$stats['variations_scanned'];

			foreach ( $languages as $target_lang ) {
				self::sync_variation_language( $variation_id, $target_lang, $dry_run, $stats );
			}

			$parent_id = wp_get_post_parent_id( $variation_id );

			if ( $parent_id > 0 ) {
				$parents[ $parent_id ] = true;
			}
		}

		if ( ! $dry_run ) {
			foreach ( array_keys( $parents ) as $parent_id ) {
				Post_Translator::refresh_product_variation_titles_fingerprint( absint( $parent_id ) );
				Post_Translator::invalidate_product_variation_title_runtime_cache( absint( $parent_id ) );
				++$stats['parents_touched'];
			}
		} else {
			$stats['parents_touched'] = count( $parents );
		}

		$batch_count = count( $variation_ids );

		return self::build_result( $stats, $offset, $limit, $batch_count );
	}

	/**
	 * Run the sync across every variation in batches.
	 *
	 * @param array<string, mixed> $args Sync args (dry_run, lang, limit).
	 * @return array<string, mixed>
	 */
	public static function run_all( array $args = array() ) {
		$limit      = max( 1, min( 500, absint( $args['limit'] ?? 200 ) ) );
		$offset     = 0;
		$aggregate  = array(
			'variations_scanned'              => 0,
			'title_companion_synced'          => 0,
			'title_polymart_backfilled'       => 0,
			'description_companion_synced'    => 0,
			'description_polymart_backfilled' => 0,
			'hashes_refreshed'                => 0,
			'parents_touched'                 => 0,
			'dry_run'                         => ! empty( $args['dry_run'] ),
			'batches'                         => 0,
		);

		do {
			$batch_args           = $args;
			$batch_args['offset'] = $offset;
			$batch_args['limit']  = $limit;
			$result               = self::run( $batch_args );
			$batch_stats          = isset( $result['stats'] ) && is_array( $result['stats'] ) ? $result['stats'] : array();

			foreach ( array_keys( $aggregate ) as $key ) {
				if ( 'batches' === $key || 'dry_run' === $key ) {
					continue;
				}

				$aggregate[ $key ] += absint( $batch_stats[ $key ] ?? 0 );
			}

			++$aggregate['batches'];

			if ( ! empty( $result['done'] ) ) {
				break;
			}

			$offset = absint( $result['next_offset'] ?? ( $offset + $limit ) );
		} while ( $aggregate['batches'] < 1000 );

		$aggregate['total_variations'] = self::count_variations();
		$aggregate['done']             = true;

		return array(
			'success' => true,
			'stats'   => $aggregate,
			'message' => self::build_summary_message( $aggregate, true ),
		);
	}

	/**
	 * @param string $lang_filter Optional language filter.
	 * @return string[]
	 */
	private static function resolve_target_languages( $lang_filter ) {
		$languages = array();

		foreach ( Language_Registry::get_translation_target_languages() as $language ) {
			$code = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $code ) {
				continue;
			}

			if ( '' !== $lang_filter && $lang_filter !== $code ) {
				continue;
			}

			$languages[] = $code;
		}

		return array_values( array_unique( $languages ) );
	}

	/**
	 * Sync one variation for one target language.
	 *
	 * @param int                  $variation_id Variation post ID.
	 * @param string               $lang         Target language code.
	 * @param bool                 $dry_run      Whether to skip writes.
	 * @param array<string, mixed> $stats        Mutable stats bag.
	 * @return void
	 */
	private static function sync_variation_language( $variation_id, $lang, $dry_run, array &$stats ) {
		$variation_id = absint( $variation_id );
		$lang         = sanitize_key( (string) $lang );

		if ( $variation_id <= 0 || '' === $lang ) {
			return;
		}

		self::sync_field_pair(
			$variation_id,
			$lang,
			Post_Translator::get_meta_key( 'title', $lang ),
			Post_Translator::get_custom_meta_key( Post_Translator::WVE_VARIATION_CUSTOM_TITLE_META, $lang ),
			'title_companion_synced',
			'title_polymart_backfilled',
			$dry_run,
			$stats
		);

		self::sync_field_pair(
			$variation_id,
			$lang,
			Post_Translator::get_meta_key( 'excerpt', $lang ),
			Post_Translator::get_custom_meta_key( Post_Translator::WVE_VARIATION_CUSTOM_DESCRIPTION_META, $lang ),
			'description_companion_synced',
			'description_polymart_backfilled',
			$dry_run,
			$stats
		);

		if ( ! $dry_run ) {
			self::refresh_variation_source_hashes( $variation_id, $lang, $stats );
		}
	}

	/**
	 * Keep polymart core meta and legacy companion meta aligned.
	 *
	 * @param int                  $variation_id Variation post ID.
	 * @param string               $lang         Target language code.
	 * @param string               $polymart_key Canonical storage key.
	 * @param string               $legacy_key   Companion `{source}_{lang}` key.
	 * @param string               $forward_stat Stats key when polymart wins.
	 * @param string               $reverse_stat Stats key when legacy backfills polymart.
	 * @param bool                 $dry_run      Whether to skip writes.
	 * @param array<string, mixed> $stats        Mutable stats bag.
	 * @return void
	 */
	private static function sync_field_pair( $variation_id, $lang, $polymart_key, $legacy_key, $forward_stat, $reverse_stat, $dry_run, array &$stats ) {
		$polymart_value = get_post_meta( $variation_id, $polymart_key, true );
		$legacy_value   = get_post_meta( $variation_id, $legacy_key, true );

		$polymart_ok = Post_Translator::has_meaningful_translation( $polymart_value );
		$legacy_ok   = Post_Translator::has_meaningful_translation( $legacy_value );

		if ( $polymart_ok ) {
			$normalized = is_string( $polymart_value ) ? trim( $polymart_value ) : '';

			if ( ! $legacy_ok || ( is_string( $legacy_value ) && trim( $legacy_value ) !== $normalized ) ) {
				++$stats[ $forward_stat ];

				if ( ! $dry_run ) {
					update_post_meta( $variation_id, $legacy_key, $normalized );
				}
			}

			return;
		}

		if ( $legacy_ok ) {
			$normalized = is_string( $legacy_value ) ? trim( $legacy_value ) : '';

			++$stats[ $reverse_stat ];

			if ( ! $dry_run ) {
				update_post_meta( $variation_id, $polymart_key, $normalized );
				update_post_meta( $variation_id, $legacy_key, $normalized );
			}
		}
	}

	/**
	 * Refresh source fingerprints for WVE custom fields on one variation.
	 *
	 * @param int                  $variation_id Variation post ID.
	 * @param string               $lang         Target language code.
	 * @param array<string, mixed> $stats        Mutable stats bag.
	 * @return void
	 */
	private static function refresh_variation_source_hashes( $variation_id, $lang, array &$stats ) {
		$custom_title = Post_Translator::get_variation_custom_title_meta_raw( $variation_id );

		if ( '' !== $custom_title ) {
			update_post_meta(
				$variation_id,
				Post_Translator::get_field_source_hash_meta_key( Post_Translator::WVE_VARIATION_CUSTOM_TITLE_META, $lang ),
				md5( $custom_title )
			);
			++$stats['hashes_refreshed'];
		} else {
			$post_title = trim( (string) get_post_field( 'post_title', $variation_id ) );

			if ( '' !== $post_title ) {
				update_post_meta(
					$variation_id,
					Post_Translator::get_field_source_hash_meta_key( 'post_title', $lang ),
					md5( $post_title )
				);
				++$stats['hashes_refreshed'];
			}
		}

		$custom_description = trim( (string) get_post_meta( $variation_id, Post_Translator::WVE_VARIATION_CUSTOM_DESCRIPTION_META, true ) );

		if ( '' !== $custom_description ) {
			update_post_meta(
				$variation_id,
				Post_Translator::get_field_source_hash_meta_key( Post_Translator::WVE_VARIATION_CUSTOM_DESCRIPTION_META, $lang ),
				md5( $custom_description )
			);
			++$stats['hashes_refreshed'];
		}

		Post_Translator::flush_translation_status_cache( $variation_id );
	}

	/**
	 * @param array<string, mixed> $stats       Batch stats.
	 * @param int                  $offset      Current offset.
	 * @param int                  $limit       Batch size.
	 * @param int                  $batch_count Variations processed in this batch.
	 * @return array<string, mixed>
	 */
	private static function build_result( array $stats, $offset, $limit, $batch_count ) {
		$total        = self::count_variations();
		$next_offset  = $offset + $batch_count;
		$done         = $batch_count < $limit || $next_offset >= $total;

		return array(
			'success'           => true,
			'stats'             => $stats,
			'total_variations'  => $total,
			'offset'            => $offset,
			'next_offset'       => $done ? $total : $next_offset,
			'done'              => $done,
			'message'           => self::build_summary_message( $stats, $done ),
		);
	}

	/**
	 * @param array<string, mixed> $stats Batch stats.
	 * @param bool                 $done  Whether all batches finished.
	 * @return string
	 */
	private static function build_summary_message( array $stats, $done ) {
		$scanned = absint( $stats['variations_scanned'] ?? 0 );
		$changed = absint( $stats['title_companion_synced'] ?? 0 )
			+ absint( $stats['title_polymart_backfilled'] ?? 0 )
			+ absint( $stats['description_companion_synced'] ?? 0 )
			+ absint( $stats['description_polymart_backfilled'] ?? 0 );

		if ( ! empty( $stats['dry_run'] ) ) {
			return sprintf(
				/* translators: 1: scanned variations, 2: fields that would change */
				__( 'حالت آزمایشی: %1$d متغیر بررسی شد؛ %2$d فیلد نیاز به هماهنگ‌سازی دارد.', 'polymart-ai' ),
				$scanned,
				$changed
			);
		}

		if ( $done ) {
			return sprintf(
				/* translators: 1: scanned variations, 2: synced fields, 3: parent products refreshed */
				__( 'هماهنگ‌سازی تمام شد: %1$d متغیر، %2$d فیلد به‌روزرسانی شد، %3$d محصول والد refresh شد.', 'polymart-ai' ),
				$scanned,
				$changed,
				absint( $stats['parents_touched'] ?? 0 )
			);
		}

		return sprintf(
			/* translators: %d: scanned variations in this batch */
			__( 'یک دسته پردازش شد (%d متغیر). برای ادامه دوباره اجرا کنید.', 'polymart-ai' ),
			$scanned
		);
	}
}
