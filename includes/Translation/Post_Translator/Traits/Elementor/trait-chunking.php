<?php
/**
 * Post_Translator Elementor_Chunking (auto-split).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\Elementor;

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\AI\AI_Client;
use PolymartAI\Translation\AI\Persian_Detector;
use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Post_Translator\Persistence_Guard;
use PolymartAI\Translation\Post_Translator\Text_Normalizer;
use PolymartAI\Translation\Post_Translator\Translation_Lock;

defined( 'ABSPATH' ) || exit;

trait Trait_Chunking {

	private static function get_elementor_segment_max_chars() {
		return max(
			400,
			absint(
				apply_filters(
					'polymart_ai_elementor_segment_chars',
					self::ELEMENTOR_LONG_FIELD_SEGMENT_CHARS
				)
			)
		);
	}

	private static function elementor_find_html_safe_cut( $text, $max_chars ) {
		$text      = (string) $text;
		$max_chars = max( 200, absint( $max_chars ) );
		$length    = self::utf8_strlen( $text );

		if ( $length <= $max_chars ) {
			return $length;
		}

		$window = self::utf8_substr( $text, 0, $max_chars );
		$delims = array(
			'</p>',
			'</div>',
			'</li>',
			'</ul>',
			'</ol>',
			'</h1>',
			'</h2>',
			'</h3>',
			'</h4>',
			'</h5>',
			'</h6>',
			'</section>',
			'</article>',
			'</blockquote>',
			'</table>',
			'</tr>',
			'</td>',
			'</th>',
			'</span>',
		);

		$best   = 0;
		$min_ok = (int) floor( $max_chars * 0.45 );

		foreach ( $delims as $delim ) {
			$pos = 0;

			while ( true ) {
				$found = function_exists( 'mb_stripos' )
					? mb_stripos( $window, $delim, $pos, 'UTF-8' )
					: stripos( $window, $delim, $pos );

				if ( false === $found ) {
					break;
				}

				$cut = $found + self::utf8_strlen( $delim );

				if ( $cut >= $min_ok && $cut > $best ) {
					$best = $cut;
				}

				$pos = $found + 1;
			}
		}

		if ( $best > 0 ) {
			return $best;
		}

		$gt = function_exists( 'mb_strrpos' )
			? mb_strrpos( $window, '>', 0, 'UTF-8' )
			: strrpos( $window, '>' );

		if ( false !== $gt && $gt >= $min_ok ) {
			return $gt + 1;
		}

		return $max_chars;
	}

	private static function elementor_balance_html_fragment( $html ) {
		$html = (string) $html;

		if ( '' === $html || false === strpos( $html, '<' ) ) {
			return $html;
		}

		if ( ! preg_match_all( '/<\/?([a-z][a-z0-9]*)\b[^>]*>/i', $html, $matches, PREG_SET_ORDER ) ) {
			return $html;
		}

		$void_tags = array(
			'area',
			'base',
			'br',
			'col',
			'embed',
			'hr',
			'img',
			'input',
			'link',
			'meta',
			'source',
			'track',
			'wbr',
		);
		$stack     = array();

		foreach ( $matches as $match ) {
			$tag  = strtolower( (string) ( $match[1] ?? '' ) );
			$full = (string) ( $match[0] ?? '' );

			if ( '' === $tag || in_array( $tag, $void_tags, true ) ) {
				continue;
			}

			if ( 0 === strpos( $full, '</' ) ) {
				for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
					if ( $stack[ $i ] === $tag ) {
						array_splice( $stack, $i, 1 );
						break;
					}
				}
				continue;
			}

			if ( '/>' !== substr( $full, -2 ) ) {
				$stack[] = $tag;
			}
		}

		while ( ! empty( $stack ) ) {
			$tag  = array_pop( $stack );
			$html .= '</' . $tag . '>';
		}

		return $html;
	}

	private static function elementor_html_safe_take_chunk( $buffer, $max_chars ) {
		$buffer    = (string) $buffer;
		$max_chars = max( 200, absint( $max_chars ) );
		$cut_at    = self::elementor_find_html_safe_cut( $buffer, $max_chars );
		$head      = self::utf8_substr( $buffer, 0, $cut_at );
		$head      = self::elementor_balance_html_fragment( $head );
		$tail      = self::utf8_substr( $buffer, $cut_at, self::utf8_strlen( $buffer ) - $cut_at );

		return array( $head, $tail );
	}

	private static function elementor_path_is_segment( $path ) {
		return (bool) preg_match( '/::__(?:part|seg)\d+$/', (string) $path );
	}

	private static function elementor_segment_is_resolved( $seg_key, $seg_source, array $map ) {
		$seg_key    = (string) $seg_key;
		$seg_source = (string) $seg_source;

		if ( ! isset( $map[ $seg_key ] ) ) {
			return false;
		}

		$value = (string) $map[ $seg_key ];

		if (
			in_array( $seg_key, self::$current_elementor_segment_passthrough, true )
			&& $value === $seg_source
		) {
			return true;
		}

		return self::elementor_map_value_is_valid_translation( $seg_key, $seg_source, $value );
	}

	private static function bind_elementor_segment_passthrough_context( array $state ) {
		self::$current_elementor_segment_passthrough = is_array( $state['elementor_segment_passthrough'] ?? null )
			? array_values( array_unique( array_map( 'strval', $state['elementor_segment_passthrough'] ) ) )
			: array();
		self::$current_elementor_field_passthrough = is_array( $state['elementor_field_passthrough'] ?? null )
			? array_values( array_unique( array_map( 'strval', $state['elementor_field_passthrough'] ) ) )
			: array();
	}

	private static function remember_elementor_segment_progress( $post_id, $lang, array &$state, array $map, array $source_payload ) {
		self::sync_elementor_persist_map_state( $state, $map, $source_payload );
		$state['elementor_map'] = $map;
		self::save_job_partial_state( $post_id, $lang, $state );
		\PolymartAI\Activity_Logger::touch_job_worker_heartbeat();
	}

	private static function apply_elementor_segment_source_fallback( $seg_key, $seg_source, array &$map, array &$state ) {
		$seg_key    = (string) $seg_key;
		$seg_source = (string) $seg_source;

		if ( '' === $seg_key || '' === $seg_source ) {
			return;
		}

		$map[ $seg_key ] = $seg_source;

		$passthrough = is_array( $state['elementor_segment_passthrough'] ?? null )
			? $state['elementor_segment_passthrough']
			: array();
		$passthrough[]                               = $seg_key;
		$state['elementor_segment_passthrough']      = array_values( array_unique( array_map( 'strval', $passthrough ) ) );
		self::$current_elementor_segment_passthrough = $state['elementor_segment_passthrough'];

		\PolymartAI\Activity_Logger::log(
			'warning',
			sprintf(
				/* translators: 1: segment path */
				__( 'Elementor — سگمنت %1$s پس از چند تلاش با متن اصلی پر شد (fallback).', 'polymart-ai' ),
				$seg_key
			),
			array( 'path' => $seg_key )
		);
	}

	private static function apply_elementor_field_source_fallback( $path, $text, array &$map, array &$state ) {
		$path = (string) $path;
		$text = (string) $text;

		if ( '' === $path || '' === $text ) {
			return;
		}

		$seg_lookup = self::get_elementor_segment_source_lookup( $path, $text );

		if ( ! empty( $seg_lookup ) ) {
			foreach ( $seg_lookup as $seg_key => $seg_source ) {
				if ( ! self::elementor_segment_is_resolved( $seg_key, (string) $seg_source, $map ) ) {
					self::apply_elementor_segment_source_fallback( $seg_key, (string) $seg_source, $map, $state );
				}
			}

			return;
		}

		$map[ $path ] = $text;

		$passthrough = is_array( $state['elementor_field_passthrough'] ?? null )
			? $state['elementor_field_passthrough']
			: array();
		$passthrough[]                            = $path;
		$state['elementor_field_passthrough']       = array_values( array_unique( array_map( 'strval', $passthrough ) ) );
		self::$current_elementor_field_passthrough  = $state['elementor_field_passthrough'];

		\PolymartAI\Activity_Logger::log(
			'warning',
			sprintf(
				/* translators: 1: field path */
				__( 'Elementor — فیلد %1$s با متن اصلی ذخیره شد (force fallback).', 'polymart-ai' ),
				$path
			),
			array( 'path' => $path )
		);
	}

	private static function split_elementor_text_into_segments( $path, $text, $max_chars ) {
		$path      = (string) $path;
		$text      = (string) $text;
		$max_chars = max( 200, absint( $max_chars ) );

		// Prefer splitting on paragraph boundaries.
		$pieces = array();

		if ( false !== stripos( $text, '<p' ) || false !== stripos( $text, '</p>' ) ) {
			// Split after closing </p> while keeping the delimiter.
			$raw = preg_split( '/(<\/p>\s*)/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
			if ( is_array( $raw ) ) {
				for ( $i = 0; $i < count( $raw ); $i += 2 ) {
					$chunk = (string) ( $raw[ $i ] ?? '' );
					$delim = (string) ( $raw[ $i + 1 ] ?? '' );
					$piece = $chunk . $delim;
					if ( '' !== trim( $piece ) ) {
						$pieces[] = $piece;
					}
				}
			}
		}

		if ( empty( $pieces ) && ( false !== stripos( $text, '</div>' ) || false !== stripos( $text, '</li>' ) ) ) {
			$raw = preg_split( '/(<\/(?:div|li|ul|ol|section|article|blockquote)>\s*)/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
			if ( is_array( $raw ) ) {
				for ( $i = 0; $i < count( $raw ); $i += 2 ) {
					$chunk = (string) ( $raw[ $i ] ?? '' );
					$delim = (string) ( $raw[ $i + 1 ] ?? '' );
					$piece = $chunk . $delim;
					if ( '' !== trim( $piece ) ) {
						$pieces[] = $piece;
					}
				}
			}
		}

		if ( empty( $pieces ) ) {
			$raw = preg_split( "/\n\s*\n/u", $text );
			if ( is_array( $raw ) ) {
				foreach ( $raw as $p ) {
					$p = (string) $p;
					if ( '' !== trim( $p ) ) {
						$pieces[] = $p . "\n\n";
					}
				}
			}
		}

		if ( empty( $pieces ) ) {
			$pieces = array( $text );
		}

		$segments = array();
		$buffer   = '';
		$seg      = 1;

		foreach ( $pieces as $piece ) {
			$piece = (string) $piece;

			if ( '' === $buffer ) {
				$buffer = $piece;
			} elseif ( self::utf8_strlen( $buffer . $piece ) <= $max_chars ) {
				$buffer .= $piece;
			} else {
				$segments[ $path . '::__seg' . $seg ] = $buffer;
				++$seg;
				$buffer = $piece;
			}

			// Extremely long single piece — HTML-safe hard cut (never mid-tag).
			while ( self::utf8_strlen( $buffer ) > $max_chars ) {
				list( $head, $tail ) = self::elementor_html_safe_take_chunk( $buffer, $max_chars );
				$segments[ $path . '::__seg' . $seg ] = $head;
				++$seg;
				$buffer = $tail;
			}
		}

		if ( '' !== $buffer ) {
			$segments[ $path . '::__seg' . $seg ] = $buffer;
		}

		// If we failed to actually split, keep original.
		if ( count( $segments ) <= 1 ) {
			return array( $path => $text );
		}

		return $segments;
	}

	private static function expand_elementor_payload_for_ai( array $payload ) {
		$expanded = array();

		foreach ( $payload as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( '' === $path || '' === trim( $text ) ) {
				continue;
			}

			if ( self::utf8_strlen( $text ) <= self::get_elementor_segment_max_chars() ) {
				$expanded[ $path ] = $text;
				continue;
			}

			foreach ( self::split_elementor_text_into_segments( $path, $text, self::get_elementor_segment_max_chars() ) as $seg_key => $seg_text ) {
				$expanded[ (string) $seg_key ] = (string) $seg_text;
			}
		}

		return $expanded;
	}

	private static function get_elementor_segment_keys( $path, $text ) {
		$path = (string) $path;
		$text = (string) $text;

		if ( self::utf8_strlen( $text ) <= self::get_elementor_segment_max_chars() ) {
			return array();
		}

		$segments = self::split_elementor_text_into_segments( $path, $text, self::get_elementor_segment_max_chars() );
		$keys     = array();

		foreach ( array_keys( $segments ) as $k ) {
			$k = (string) $k;
			if ( $k !== $path ) {
				$keys[] = $k;
			}
		}

		return $keys;
	}

	private static function get_elementor_segment_source_lookup( $path, $text ) {
		$path = (string) $path;
		$text = (string) $text;

		if ( self::utf8_strlen( $text ) <= self::get_elementor_segment_max_chars() ) {
			return array();
		}

		$segments = self::split_elementor_text_into_segments( $path, $text, self::get_elementor_segment_max_chars() );
		$lookup   = array();

		foreach ( $segments as $k => $v ) {
			$k = (string) $k;
			$v = (string) $v;
			if ( $k === $path ) {
				continue;
			}
			$lookup[ $k ] = $v;
		}

		return $lookup;
	}

	private static function elementor_map_value_is_valid_translation( $path, $source_text, $translated ) {
		$path          = (string) $path;
		$source_text   = trim( (string) $source_text );
		$translated    = trim( (string) $translated );

		if ( '' === $translated || Persian_Detector::contains_persian( $translated ) ) {
			return false;
		}

		if ( '' !== $source_text && $translated === $source_text ) {
			return false;
		}

		unset( $path );

		return true;
	}

	private static function sanitize_elementor_translation_map( array $map, array $source_payload ) {
		$clean = array();

		foreach ( $map as $path => $value ) {
			$path = (string) $path;

			if ( '' === $path ) {
				continue;
			}

			$source_text = (string) ( $source_payload[ $path ] ?? '' );

			if ( preg_match( '/^(.+)::__part\d+$/', $path, $matches ) ) {
				$source_text = (string) ( $source_payload[ $matches[1] ] ?? $source_text );
			} elseif ( preg_match( '/^(.+)::__seg\d+$/', $path, $matches ) ) {
				$base       = (string) $matches[1];
				$base_text  = (string) ( $source_payload[ $base ] ?? '' );
				$seg_lookup = self::get_elementor_segment_source_lookup( $base, $base_text );
				$source_text = (string) ( $seg_lookup[ $path ] ?? $base_text );
			}

			if ( self::elementor_map_value_is_valid_translation( $path, $source_text, $value ) ) {
				$clean[ $path ] = trim( (string) $value );
				continue;
			}

			if (
				preg_match( '/::__(?:part|seg)\d+$/', $path )
				&& in_array( $path, self::$current_elementor_segment_passthrough, true )
				&& trim( (string) $value ) === trim( $source_text )
			) {
				$clean[ $path ] = trim( (string) $value );
				continue;
			}

			if (
				! preg_match( '/::__(?:part|seg)\d+$/', $path )
				&& in_array( $path, self::$current_elementor_field_passthrough, true )
				&& trim( (string) $value ) === trim( $source_text )
			) {
				$clean[ $path ] = trim( (string) $value );
			}
		}

		return $clean;
	}

	private static function extract_elementor_segment_map_entries( array $map ) {
		$segment_map = array();

		foreach ( $map as $path => $value ) {
			$path = (string) $path;

			if ( preg_match( '/::__(?:part|seg)\d+$/', $path ) ) {
				$segment_map[ $path ] = (string) $value;
			}
		}

		return $segment_map;
	}

	private static function extract_elementor_persist_map_entries( array $map, array $source_payload ) {
		$persist = array();
		$clean   = self::sanitize_elementor_translation_map( $map, $source_payload );

		foreach ( $clean as $path => $value ) {
			$path = (string) $path;

			if ( preg_match( '/::__(?:part|seg)\d+$/', $path ) ) {
				$base       = preg_replace( '/::__(?:part|seg)\d+$/', '', $path );
				$base_text  = (string) ( $source_payload[ $base ] ?? '' );
				$seg_lookup = self::get_elementor_segment_source_lookup( $base, $base_text );
				$seg_source = (string) ( $seg_lookup[ $path ] ?? '' );

				if ( self::elementor_segment_is_resolved( $path, $seg_source, $clean ) ) {
					$persist[ $path ] = (string) $value;
				}
				continue;
			}

			$source = (string) ( $source_payload[ $path ] ?? '' );

			if ( '' === $source ) {
				continue;
			}

			if ( self::elementor_field_translation_complete( $path, $source, $clean ) ) {
				$persist[ $path ] = (string) $value;
			}
		}

		return $persist;
	}

	private static function sync_elementor_persist_map_state( array &$state, array $map, array $source_payload ) {
		$existing = is_array( $state['elementor_persist_map'] ?? null ) ? $state['elementor_persist_map'] : array();

		if ( is_array( $state['elementor_seg_map'] ?? null ) ) {
			$existing = array_merge( $state['elementor_seg_map'], $existing );
			unset( $state['elementor_seg_map'] );
		}

		$state['elementor_persist_map'] = array_merge(
			$existing,
			self::extract_elementor_persist_map_entries( $map, $source_payload )
		);
	}

	private static function elementor_chunk_fingerprint( array $chunk ) {
		$keys = array_map( 'strval', array_keys( $chunk ) );
		sort( $keys, SORT_STRING );

		return implode( '|', $keys );
	}

	private static function filter_elementor_gap_fill_pending_chunks( array $chunks, array $map, array $source_payload ) {
		$pending = array();

		foreach ( $chunks as $chunk ) {
			if ( ! is_array( $chunk ) || empty( $chunk ) ) {
				continue;
			}

			if ( self::elementor_chunk_paths_translated( $chunk, $map, $source_payload ) ) {
				continue;
			}

			$pending[] = $chunk;
		}

		return $pending;
	}

	private static function build_elementor_stubborn_segment_batches( $path, $text, array $map, array $source_payload ) {
		$path = (string) $path;
		$text = (string) $text;

		if ( '' === $path || '' === $text ) {
			return array();
		}

		$field_keys = self::expand_elementor_payload_for_ai( array( $path => $text ) );
		$batches    = self::chunk_payload_with_limits( $field_keys, 2, self::ELEMENTOR_JOB_MAX_CHUNK_CHARS, false );
		$pending    = array();

		foreach ( $batches as $batch ) {
			if ( ! self::elementor_chunk_paths_translated( $batch, $map, $source_payload ) ) {
				$pending[] = $batch;
			}
		}

		return $pending;
	}

	private static function prune_complete_elementor_segment_map_entries( array $map, array $source_payload ) {
		$pruned = $map;

		foreach ( $source_payload as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( ! self::elementor_field_translation_complete( $path, $text, $pruned ) ) {
				continue;
			}

			foreach ( self::get_elementor_segment_keys( $path, $text ) as $seg_key ) {
				unset( $pruned[ $seg_key ] );
			}

			$parts = (int) ceil( self::utf8_strlen( $text ) / self::AI_MAX_SINGLE_FIELD_CHARS );

			for ( $part = 1; $part <= $parts; $part++ ) {
				unset( $pruned[ $path . '::__part' . $part ] );
			}
		}

		return $pruned;
	}

	private static function prepare_elementor_map_for_persist( array $map, array $source_payload ) {
		$prepared = $map;

		foreach ( $source_payload as $path => $text ) {
			$path     = (string) $path;
			$text     = (string) $text;
			$seg_keys = self::get_elementor_segment_keys( $path, $text );

			if ( empty( $seg_keys ) ) {
				continue;
			}

			$seg_lookup = self::get_elementor_segment_source_lookup( $path, $text );
			$parts      = array();

			foreach ( $seg_keys as $seg_key ) {
				$seg_source = (string) ( $seg_lookup[ $seg_key ] ?? '' );

				if (
					! isset( $prepared[ $seg_key ] )
					|| ! self::elementor_segment_is_resolved( $seg_key, $seg_source, $prepared )
				) {
					$parts = array();
					break;
				}

				$parts[] = (string) $prepared[ $seg_key ];
			}

			if ( empty( $parts ) ) {
				continue;
			}

			$prepared[ $path ] = implode( '', $parts );

			foreach ( $seg_keys as $seg_key ) {
				unset( $prepared[ $seg_key ] );
			}
		}

		return $prepared;
	}

	private static function elementor_all_segments_present_in_map( $base, $source, array $raw_mapped ) {
		$base       = (string) $base;
		$source     = (string) $source;
		$seg_lookup = self::get_elementor_segment_source_lookup( $base, $source );

		if ( empty( $seg_lookup ) ) {
			return false;
		}

		foreach ( $seg_lookup as $seg_key => $seg_source ) {
			if (
				! isset( $raw_mapped[ $seg_key ] )
				|| ! self::elementor_map_value_is_valid_translation( $seg_key, (string) $seg_source, (string) $raw_mapped[ $seg_key ] )
			) {
				return false;
			}
		}

		return true;
	}

	private static function elementor_collapsed_base_translation_complete( $path, $text, array $map ) {
		$path = (string) $path;
		$text = (string) $text;

		// Partial segment collapse must never satisfy completion for long fields.
		if ( ! empty( self::get_elementor_segment_keys( $path, $text ) ) ) {
			return false;
		}

		if ( ! isset( $map[ $path ] ) ) {
			return false;
		}

		$translated = trim( (string) $map[ $path ] );

		if ( ! self::elementor_map_value_is_valid_translation( $path, $text, $translated ) ) {
			return false;
		}

		return self::utf8_strlen( $translated ) >= (int) floor( self::utf8_strlen( $text ) * 0.85 );
	}

	private static function merge_elementor_api_translations_into_map( array $raw_mapped, array $source_payload ) {
		$mapped = $raw_mapped;

		foreach ( self::collapse_payload_parts( $raw_mapped ) as $base => $value ) {
			$base = (string) $base;

			if ( '' === $base || preg_match( '/::__(?:part|seg)\d+$/', $base ) ) {
				continue;
			}

			$source = (string) ( $source_payload[ $base ] ?? '' );

			if ( self::elementor_all_segments_present_in_map( $base, $source, $raw_mapped ) ) {
				$mapped[ $base ] = (string) $value;
				continue;
			}

			if ( self::elementor_collapsed_base_translation_complete( $base, $source, array( $base => (string) $value ) ) ) {
				$mapped[ $base ] = (string) $value;
			}
		}

		return $mapped;
	}

	private static function elementor_field_translation_complete( $path, $text, array $map ) {
		$path = (string) $path;
		$text = (string) $text;

		if (
			in_array( $path, self::$current_elementor_field_passthrough, true )
			&& isset( $map[ $path ] )
			&& (string) $map[ $path ] === $text
		) {
			return true;
		}

		// For very long Elementor fields, require all __segN pieces (or one collapsed base value).
		$seg_lookup = self::get_elementor_segment_source_lookup( $path, $text );
		if ( ! empty( $seg_lookup ) ) {
			$all_segs_ok = true;

			foreach ( $seg_lookup as $seg_key => $seg_source ) {
				if ( self::elementor_segment_is_resolved( $seg_key, (string) $seg_source, $map ) ) {
					continue;
				}

				$all_segs_ok = false;
				break;
			}

			if ( $all_segs_ok ) {
				return true;
			}

			return false;
		}

		if ( self::utf8_strlen( $text ) <= self::AI_MAX_SINGLE_FIELD_CHARS ) {
			return isset( $map[ $path ] )
				&& self::elementor_map_value_is_valid_translation( $path, $text, (string) $map[ $path ] );
		}

		if ( isset( $map[ $path ] ) && '' !== trim( (string) $map[ $path ] ) ) {
			$translated = (string) $map[ $path ];

			if (
				self::elementor_map_value_is_valid_translation( $path, $text, $translated )
				&& self::utf8_strlen( $translated ) >= (int) floor( self::utf8_strlen( $text ) * 0.85 )
			) {
				return true;
			}
		}

		$parts = (int) ceil( self::utf8_strlen( $text ) / self::AI_MAX_SINGLE_FIELD_CHARS );

		for ( $part = 1; $part <= $parts; $part++ ) {
			$part_key = $path . '::__part' . $part;

			if (
				! isset( $map[ $part_key ] )
				|| ! self::elementor_map_value_is_valid_translation( $path, $text, (string) $map[ $part_key ] )
			) {
				return false;
			}
		}

		return true;
	}

	private static function chunk_elementor_payload_for_job( array $payload ) {
		$chunk_size = \PolymartAI\Activity_Logger::is_bulk_job_running()
			? 1
			: self::ELEMENTOR_JOB_FIELD_CHUNK_SIZE;

		/**
		 * Filter Elementor fields per API batch in job slices.
		 *
		 * @param int                   $chunk_size Fields per batch.
		 * @param array<string, string> $payload    Source field map.
		 */
		$chunk_size = max( 1, (int) apply_filters( 'polymart_ai_elementor_job_field_chunk_size', $chunk_size, $payload ) );

		// Sub-chunk long Elementor fields (paragraph-aware) before batching.
		$payload = self::expand_elementor_payload_for_ai( $payload );

		return self::chunk_payload_with_limits(
			$payload,
			$chunk_size,
			self::ELEMENTOR_JOB_MAX_CHUNK_CHARS
		);
	}

	private static function collapse_duplicate_elementor_payload( array $payload ) {
		$text_paths = array();

		foreach ( $payload as $path => $text ) {
			$path = (string) $path;
			$text = trim( (string) $text );

			if ( '' === $path || '' === $text ) {
				continue;
			}

			if ( ! isset( $text_paths[ $text ] ) ) {
				$text_paths[ $text ] = array();
			}

			$text_paths[ $text ][] = $path;
		}

		$collapsed = array();
		$mirrors   = array();

		foreach ( $text_paths as $text => $paths ) {
			$canonical = (string) $paths[0];

			$collapsed[ $canonical ] = $text;

			if ( count( $paths ) > 1 ) {
				$mirrors[ $canonical ] = array_map( 'strval', array_slice( $paths, 1 ) );
			}
		}

		return array(
			'payload' => $collapsed,
			'mirrors' => $mirrors,
		);
	}

	private static function expand_elementor_map_mirrors( array $map, array $mirrors ) {
		foreach ( $mirrors as $canonical => $paths ) {
			$canonical = (string) $canonical;

			if ( ! isset( $map[ $canonical ] ) || '' === trim( (string) $map[ $canonical ] ) ) {
				continue;
			}

			$value = (string) $map[ $canonical ];

			foreach ( (array) $paths as $path ) {
				$path = (string) $path;

				if ( '' !== $path ) {
					$map[ $path ] = $value;
				}
			}
		}

		return $map;
	}

	private static function get_elementor_text_mirror_paths( array $payload ) {
		return self::collapse_duplicate_elementor_payload( $payload )['mirrors'];
	}

	private static function filter_elementor_gap_fill_batch_remaining( array $remaining ) {
		$batch_remaining = array();

		foreach ( $remaining as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( self::stubborn_elementor_field_is_long( $path, $text ) ) {
				continue;
			}

			$batch_remaining[ $path ] = $text;
		}

		return $batch_remaining;
	}

	private static function stubborn_elementor_field_is_long( $path, $text ) {
		$path = (string) $path;
		$text = (string) $text;

		return ! empty( self::get_elementor_segment_source_lookup( $path, $text ) )
			|| self::utf8_strlen( $text ) > self::get_elementor_segment_max_chars();
	}

	private static function chunk_elementor_gap_fill_payload( array $payload ) {
		$collapsed = self::collapse_duplicate_elementor_payload( $payload );
		$expanded  = self::expand_elementor_payload_for_ai( $collapsed['payload'] );

		if ( empty( $expanded ) ) {
			return array();
		}

		// Small batches only — one translate_fields() call must stay a single HTTP round-trip.
		return self::chunk_payload_with_limits(
			$expanded,
			3,
			self::ELEMENTOR_JOB_MAX_CHUNK_CHARS,
			false
		);
	}

	private static function merge_elementor_job_path_map( $post_id, $lang, array $source_data, array $state = array(), array $overlay_map = array() ) {
		$state_map   = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$persist_map = is_array( $state['elementor_persist_map'] ?? null ) ? $state['elementor_persist_map'] : array();
		$seg_map     = is_array( $state['elementor_seg_map'] ?? null ) ? $state['elementor_seg_map'] : array();
		$rebuilt     = self::rebuild_elementor_map_from_saved_translation( $post_id, $lang, $source_data );

		return array_merge( $rebuilt, $persist_map, $seg_map, $state_map, $overlay_map );
	}

	private static function chunk_elementor_payload_for_ai( array $payload ) {
		$payload       = self::expand_payload_for_ai( $payload );
		$chunks        = array();
		$current       = array();
		$current_chars = 0;

		foreach ( $payload as $key => $value ) {
			$value = (string) $value;
			$size  = self::utf8_strlen( (string) $key ) + self::utf8_strlen( $value );

			if (
				! empty( $current )
				&& (
					$current_chars + $size > self::ELEMENTOR_AI_MAX_CHUNK_CHARS
					|| count( $current ) >= self::ELEMENTOR_AI_FIELD_CHUNK_SIZE
				)
			) {
				$chunks[]      = $current;
				$current       = array();
				$current_chars = 0;
			}

			$current[ $key ] = $value;
			$current_chars  += $size;
		}

		if ( ! empty( $current ) ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	private static function alias_elementor_payload_keys( array $payload ) {
		$aliased       = array();
		$alias_to_path = array();
		$index         = 0;

		foreach ( $payload as $path => $text ) {
			$alias = 'el_' . $index;
			++$index;

			$aliased[ $alias ]       = (string) $text;
			$alias_to_path[ $alias ] = (string) $path;
		}

		return array( $aliased, $alias_to_path );
	}

	private static function unmap_elementor_aliases( array $translated_map, array $alias_to_path ) {
		$mapped = array();

		foreach ( $translated_map as $key => $value ) {
			if ( isset( $alias_to_path[ $key ] ) ) {
				$mapped[ $alias_to_path[ $key ] ] = $value;
				continue;
			}

			$mapped[ $key ] = $value;
		}

		return $mapped;
	}

	private static function merge_elementor_path_map( $post_id, $lang, array $source_data, array $map ) {
		$rebuilt = self::rebuild_elementor_map_from_saved_translation( $post_id, $lang, $source_data );

		return array_merge( $rebuilt, $map );
	}

}
