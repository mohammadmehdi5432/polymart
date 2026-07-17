<?php
/**
 * Scoped find scanner for translation corrections.
 *
 * @package PolymartAI\Translation\Correction
 */

namespace PolymartAI\Translation\Correction;

use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Storefront\Runtime_String_Translator;
use PolymartAI\Translation\UI_String\UI_String_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Correction_Scanner
 */
final class Correction_Scanner {

	/**
	 * Default preview limit.
	 */
	const DEFAULT_LIMIT = 50;

	/**
	 * Hard cap for preview matches.
	 */
	const MAX_LIMIT = 50;

	/**
	 * Rows fetched per SQL page when scanning postmeta.
	 */
	const SQL_PAGE_SIZE = 80;

	/**
	 * Allowed scopes.
	 *
	 * @var string[]
	 */
	const SCOPES = array( 'ui_strings', 'products', 'elementor' );

	/**
	 * Preview matches across selected scopes.
	 *
	 * @param array<string, mixed> $args Request args.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function preview( array $args ) {
		$lang          = sanitize_key( (string) ( $args['lang'] ?? '' ) );
		$find          = is_string( $args['find'] ?? null ) ? (string) $args['find'] : '';
		$replace       = is_string( $args['replace'] ?? null ) ? (string) $args['replace'] : '';
		$mode          = isset( $args['mode'] ) && 'contains' === $args['mode'] ? 'contains' : 'exact';
		$word_boundary = ! empty( $args['word_boundary'] );
		$scopes        = self::normalize_scopes( $args['scopes'] ?? array() );
		$limit         = min( self::MAX_LIMIT, max( 1, absint( $args['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$cursor        = is_array( $args['cursor'] ?? null ) ? $args['cursor'] : array();

		if ( '' === $lang ) {
			return new \WP_Error(
				'polymart_ai_correction_lang',
				__( 'زبان مقصد را انتخاب کنید.', 'polymart-ai' )
			);
		}

		$valid = Correction_Text::validate_pair( $find, $replace, $mode, $word_boundary );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( empty( $scopes ) ) {
			return new \WP_Error(
				'polymart_ai_correction_scopes',
				__( 'حداقل یک محدوده (UI، محصولات، یا Elementor) را انتخاب کنید.', 'polymart-ai' )
			);
		}

		$matches   = array();
		$truncated = false;
		$next      = array();

		foreach ( $scopes as $scope ) {
			if ( count( $matches ) >= $limit ) {
				$truncated = true;
				break;
			}

			$remaining = $limit - count( $matches );
			$scope_cur = isset( $cursor[ $scope ] ) ? (string) $cursor[ $scope ] : '';

			if ( 'ui_strings' === $scope ) {
				$result = self::scan_ui_strings( $lang, $find, $mode, $word_boundary, $scope_cur, $remaining );
			} elseif ( 'products' === $scope ) {
				$result = self::scan_products( $lang, $find, $mode, $word_boundary, $scope_cur, $remaining );
			} else {
				$result = self::scan_elementor( $lang, $find, $mode, $word_boundary, $scope_cur, $remaining );
			}

			foreach ( (array) ( $result['matches'] ?? array() ) as $match ) {
				$matches[] = $match;
			}

			if ( ! empty( $result['next_cursor'] ) ) {
				$next[ $scope ] = (string) $result['next_cursor'];
			}

			if ( ! empty( $result['truncated'] ) ) {
				$truncated = true;
			}
		}

		return array(
			'lang'          => $lang,
			'find'          => $find,
			'replace'       => $replace,
			'mode'          => $mode,
			'word_boundary' => (bool) $word_boundary,
			'scopes'        => $scopes,
			'matches'       => $matches,
			'count'         => count( $matches ),
			'truncated'    => $truncated || ! empty( $next ),
			'next_cursor'   => $next,
		);
	}

	/**
	 * @param mixed $scopes Raw scopes.
	 * @return string[]
	 */
	public static function normalize_scopes( $scopes ) {
		if ( ! is_array( $scopes ) ) {
			$scopes = array();
		}

		$out = array();

		foreach ( $scopes as $scope ) {
			$scope = sanitize_key( (string) $scope );

			if ( in_array( $scope, self::SCOPES, true ) ) {
				$out[] = $scope;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Meta keys scanned for product/page field translations.
	 *
	 * @param string $lang Language.
	 * @return string[]
	 */
	public static function product_meta_keys( $lang ) {
		$lang = sanitize_key( (string) $lang );
		$keys = array(
			Meta_Keys::get_meta_key( 'title', $lang ),
			Meta_Keys::get_meta_key( 'content', $lang ),
			Meta_Keys::get_meta_key( 'excerpt', $lang ),
			'_polymart_ai_attr_i18n_' . $lang,
		);

		foreach ( Meta_Keys::CUSTOM_META_KEYS as $source_key ) {
			$keys[] = Meta_Keys::get_custom_meta_key( $source_key, $lang );
		}

		/**
		 * Filter product/page translation meta keys scanned by corrections.
		 *
		 * @param string[] $keys Meta keys.
		 * @param string   $lang Language.
		 */
		return array_values( array_unique( (array) apply_filters( 'polymart_ai_correction_product_meta_keys', $keys, $lang ) ) );
	}

	/**
	 * @param string $lang          Lang.
	 * @param string $find          Find.
	 * @param string $mode          Mode.
	 * @param bool   $word_boundary Boundary.
	 * @param string $cursor        Offset cursor (int as string).
	 * @param int    $limit         Limit.
	 * @return array{matches:array,next_cursor:string,truncated:bool}
	 */
	private static function scan_ui_strings( $lang, $find, $mode, $word_boundary, $cursor, $limit ) {
		$matches = array();
		$offset  = max( 0, absint( $cursor ) );
		$index   = 0;
		$bucket  = self::get_ui_bucket( $lang );
		$entries = isset( $bucket['h'] ) && is_array( $bucket['h'] ) ? $bucket['h'] : array();

		foreach ( $entries as $hash => $text ) {
			if ( ! is_string( $text ) || ! Correction_Text::matches( $text, $find, $mode, $word_boundary ) ) {
				continue;
			}

			if ( $index++ < $offset ) {
				continue;
			}

			$entry  = method_exists( UI_String_Registry::class, 'get_entry' )
				? UI_String_Registry::get_entry( (string) $hash )
				: null;
			$msgid  = is_array( $entry ) && isset( $entry['msgid'] ) ? (string) $entry['msgid'] : '';
			$domain = is_array( $entry ) && isset( $entry['domain'] ) ? (string) $entry['domain'] : '';

			$matches[] = self::build_match(
				'ui_strings',
				'ui:' . $hash,
				array(
					'hash'        => (string) $hash,
					'label'       => '' !== $msgid ? $msgid : (string) $hash,
					'location'    => '' !== $domain ? $domain : 'ui_strings',
					'snippet'     => Correction_Text::snippet( $text, $find ),
					'value'       => $text,
					'match_count' => Correction_Text::match_count( $text, $find, $mode, $word_boundary ),
				)
			);

			if ( count( $matches ) >= $limit ) {
				return array(
					'matches'     => $matches,
					'next_cursor' => (string) ( $offset + count( $matches ) ),
					'truncated'  => true,
				);
			}
		}

		// Runtime cache option.
		$runtime = get_option( Runtime_String_Translator::CACHE_OPTION_PREFIX . $lang, array() );
		$runtime_h = is_array( $runtime ) && isset( $runtime['h'] ) && is_array( $runtime['h'] ) ? $runtime['h'] : array();

		foreach ( $runtime_h as $hash => $text ) {
			if ( ! is_string( $text ) || ! Correction_Text::matches( $text, $find, $mode, $word_boundary ) ) {
				continue;
			}

			if ( $index++ < $offset ) {
				continue;
			}

			$matches[] = self::build_match(
				'ui_strings',
				'runtime:' . $hash,
				array(
					'hash'        => (string) $hash,
					'store'       => 'runtime',
					'label'       => __( 'کش ران‌تایم', 'polymart-ai' ),
					'location'    => 'runtime_cache',
					'snippet'     => Correction_Text::snippet( $text, $find ),
					'value'       => $text,
					'match_count' => Correction_Text::match_count( $text, $find, $mode, $word_boundary ),
				)
			);

			if ( count( $matches ) >= $limit ) {
				return array(
					'matches'     => $matches,
					'next_cursor' => (string) ( $offset + count( $matches ) ),
					'truncated'  => true,
				);
			}
		}

		return array(
			'matches'     => $matches,
			'next_cursor' => '',
			'truncated'  => false,
		);
	}

	/**
	 * @param string $lang          Lang.
	 * @param string $find          Find.
	 * @param string $mode          Mode.
	 * @param bool   $word_boundary Boundary.
	 * @param string $cursor        post_id cursor.
	 * @param int    $limit         Limit.
	 * @return array{matches:array,next_cursor:string,truncated:bool}
	 */
	private static function scan_products( $lang, $find, $mode, $word_boundary, $cursor, $limit ) {
		global $wpdb;

		$matches   = array();
		$after_id  = max( 0, absint( $cursor ) );
		$meta_keys = self::product_meta_keys( $lang );
		$like      = '%' . $wpdb->esc_like( $find ) . '%';
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from count.
		$query = "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.post_id > %d
				AND pm.meta_key IN ($placeholders)
				AND pm.meta_value LIKE %s
				AND p.post_status IN ('publish','private','draft')
			ORDER BY pm.post_id ASC, pm.meta_id ASC
			LIMIT %d";

		$prepare_args = array_merge( array( $after_id ), $meta_keys, array( $like, self::SQL_PAGE_SIZE ) );
		$sql          = $wpdb->prepare( $query, ...$prepare_args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array(
				'matches'     => array(),
				'next_cursor' => '',
				'truncated'  => false,
			);
		}

		$last_post = $after_id;

		foreach ( $rows as $row ) {
			$post_id   = absint( $row['post_id'] ?? 0 );
			$meta_key  = (string) ( $row['meta_key'] ?? '' );
			$meta_id   = absint( $row['meta_id'] ?? 0 );
			$value     = (string) ( $row['meta_value'] ?? '' );
			$last_post = max( $last_post, $post_id );

			if ( $post_id <= 0 || '' === $meta_key ) {
				continue;
			}

			// Serialized attr maps: scan leaf strings.
			if ( is_serialized( $value ) ) {
				$unserialized = maybe_unserialize( $value );

				if ( is_array( $unserialized ) ) {
					$leaf_hits = self::scan_array_leaves( $unserialized, $find, $mode, $word_boundary );

					foreach ( $leaf_hits as $path => $leaf_value ) {
						$matches[] = self::build_match(
							'products',
							'products:' . $post_id . ':' . $meta_key . ':' . md5( (string) $path ),
							array(
								'post_id'     => $post_id,
								'meta_key'    => $meta_key,
								'meta_id'     => $meta_id,
								'path'        => (string) $path,
								'label'       => get_the_title( $post_id ),
								'location'    => $meta_key . ( '' !== $path ? ' › ' . $path : '' ),
								'edit_url'    => get_edit_post_link( $post_id, 'raw' ),
								'snippet'     => Correction_Text::snippet( (string) $leaf_value, $find ),
								'value'       => (string) $leaf_value,
								'match_count' => Correction_Text::match_count( (string) $leaf_value, $find, $mode, $word_boundary ),
							)
						);

						if ( count( $matches ) >= $limit ) {
							return array(
								'matches'     => $matches,
								'next_cursor' => (string) $post_id,
								'truncated'  => true,
							);
						}
					}

					continue;
				}
			}

			if ( ! Correction_Text::matches( $value, $find, $mode, $word_boundary ) ) {
				continue;
			}

			$matches[] = self::build_match(
				'products',
				'products:' . $post_id . ':' . $meta_key,
				array(
					'post_id'     => $post_id,
					'meta_key'    => $meta_key,
					'meta_id'     => $meta_id,
					'label'       => get_the_title( $post_id ),
					'location'    => $meta_key,
					'edit_url'    => get_edit_post_link( $post_id, 'raw' ),
					'snippet'     => Correction_Text::snippet( $value, $find ),
					'value'       => $value,
					'match_count' => Correction_Text::match_count( $value, $find, $mode, $word_boundary ),
				)
			);

			if ( count( $matches ) >= $limit ) {
				return array(
					'matches'     => $matches,
					'next_cursor' => (string) $post_id,
					'truncated'  => true,
				);
			}
		}

		$truncated = count( $rows ) >= self::SQL_PAGE_SIZE;

		return array(
			'matches'     => $matches,
			'next_cursor' => $truncated ? (string) $last_post : '',
			'truncated'  => $truncated,
		);
	}

	/**
	 * @param string $lang          Lang.
	 * @param string $find          Find.
	 * @param string $mode          Mode.
	 * @param bool   $word_boundary Boundary.
	 * @param string $cursor        post_id cursor.
	 * @param int    $limit         Limit.
	 * @return array{matches:array,next_cursor:string,truncated:bool}
	 */
	private static function scan_elementor( $lang, $find, $mode, $word_boundary, $cursor, $limit ) {
		global $wpdb;

		$matches  = array();
		$after_id = max( 0, absint( $cursor ) );
		$meta_key = '_elementor_data_' . sanitize_key( (string) $lang );
		$like     = '%' . $wpdb->esc_like( $find ) . '%';

		$sql = $wpdb->prepare(
			"SELECT pm.post_id, pm.meta_value
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.post_id > %d
				AND pm.meta_key = %s
				AND pm.meta_value LIKE %s
				AND p.post_status IN ('publish','private','draft')
			ORDER BY pm.post_id ASC
			LIMIT %d",
			$after_id,
			$meta_key,
			$like,
			min( self::SQL_PAGE_SIZE, max( $limit * 2, 10 ) )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array(
				'matches'     => array(),
				'next_cursor' => '',
				'truncated'  => false,
			);
		}

		$last_post = $after_id;

		foreach ( $rows as $row ) {
			$post_id   = absint( $row['post_id'] ?? 0 );
			$raw       = (string) ( $row['meta_value'] ?? '' );
			$last_post = max( $last_post, $post_id );

			if ( $post_id <= 0 || '' === $raw ) {
				continue;
			}

			// Cap decode cost.
			if ( strlen( $raw ) > Post_Translator::get_max_storefront_elementor_json_bytes() ) {
				continue;
			}

			$data = json_decode( $raw, true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			$hits = self::scan_array_leaves( $data, $find, $mode, $word_boundary );

			if ( empty( $hits ) ) {
				continue;
			}

			$sample = reset( $hits );
			$path   = (string) key( $hits );

			$matches[] = self::build_match(
				'elementor',
				'elementor:' . $post_id,
				array(
					'post_id'     => $post_id,
					'meta_key'    => $meta_key,
					'label'       => get_the_title( $post_id ),
					'location'    => 'Elementor · ' . count( $hits ) . ' ' . __( 'فیلد', 'polymart-ai' ),
					'edit_url'    => get_edit_post_link( $post_id, 'raw' ),
					'snippet'     => Correction_Text::snippet( (string) $sample, $find ),
					'hit_count'   => count( $hits ),
					'sample_path' => $path,
					'match_count' => array_sum(
						array_map(
							static function ( $leaf ) use ( $find, $mode, $word_boundary ) {
								return Correction_Text::match_count( (string) $leaf, $find, $mode, $word_boundary );
							},
							$hits
						)
					),
				)
			);

			if ( count( $matches ) >= $limit ) {
				return array(
					'matches'     => $matches,
					'next_cursor' => (string) $post_id,
					'truncated'  => true,
				);
			}
		}

		$truncated = count( $rows ) >= min( self::SQL_PAGE_SIZE, max( $limit * 2, 10 ) );

		return array(
			'matches'     => $matches,
			'next_cursor' => $truncated ? (string) $last_post : '',
			'truncated'  => $truncated,
		);
	}

	/**
	 * Recursively collect matching string leaves.
	 *
	 * @param mixed  $node          Node.
	 * @param string $find          Find.
	 * @param string $mode          Mode.
	 * @param bool   $word_boundary Boundary.
	 * @param string $path          Path.
	 * @return array<string, string>
	 */
	public static function scan_array_leaves( $node, $find, $mode, $word_boundary, $path = '' ) {
		$hits = array();

		if ( is_string( $node ) ) {
			if ( Correction_Text::matches( $node, $find, $mode, $word_boundary ) ) {
				$hits[ '' !== $path ? $path : 'value' ] = $node;
			}

			return $hits;
		}

		if ( ! is_array( $node ) ) {
			return $hits;
		}

		foreach ( $node as $key => $child ) {
			$child_path = '' === $path ? (string) $key : $path . '.' . (string) $key;
			$hits       = array_merge( $hits, self::scan_array_leaves( $child, $find, $mode, $word_boundary, $child_path ) );
		}

		return $hits;
	}

	/**
	 * Recursively replace matching string leaves.
	 *
	 * @param mixed  $node          Node.
	 * @param string $find          Find.
	 * @param string $replace       Replace.
	 * @param string $mode          Mode.
	 * @param bool   $word_boundary Boundary.
	 * @return array{0:mixed,1:int} Node and replacement count.
	 */
	public static function replace_array_leaves( $node, $find, $replace, $mode, $word_boundary ) {
		$count = 0;

		if ( is_string( $node ) ) {
			if ( ! Correction_Text::matches( $node, $find, $mode, $word_boundary ) ) {
				return array( $node, 0 );
			}

			$before = $node;
			$after  = Correction_Text::replace( $node, $find, $replace, $mode, $word_boundary );
			$count  = $before === $after ? 0 : max( 1, Correction_Text::match_count( $before, $find, $mode, $word_boundary ) );

			return array( $after, $count );
		}

		if ( ! is_array( $node ) ) {
			return array( $node, 0 );
		}

		foreach ( $node as $key => $child ) {
			list( $new_child, $child_count ) = self::replace_array_leaves( $child, $find, $replace, $mode, $word_boundary );
			$node[ $key ] = $new_child;
			$count       += $child_count;
		}

		return array( $node, $count );
	}

	/**
	 * @param string               $scope Scope.
	 * @param string               $id    Match id.
	 * @param array<string, mixed> $data  Fields.
	 * @return array<string, mixed>
	 */
	private static function build_match( $scope, $id, array $data ) {
		return array_merge(
			array(
				'id'    => $id,
				'scope' => $scope,
			),
			$data
		);
	}

	/**
	 * @param string $lang Language.
	 * @return array<string, mixed>
	 */
	private static function get_ui_bucket( $lang ) {
		$lang = sanitize_key( (string) $lang );
		$raw  = get_option( UI_String_Registry::TRANSLATIONS_OPTION_PREFIX . $lang, array() );

		return is_array( $raw ) ? $raw : array();
	}
}
