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
use PolymartAI\Translation\Post_Translator\Shortcode_Masker;
use PolymartAI\Translation\Post_Translator\Text_Normalizer;
use PolymartAI\Translation\Post_Translator\Translation_Lock;


defined( 'ABSPATH' ) || exit;

trait Trait_Chunking {

	private static function get_elementor_segment_max_chars() {
		$default = \PolymartAI\Activity_Logger::is_bulk_job_running()
			? self::ELEMENTOR_BULK_LONG_FIELD_SEGMENT_CHARS
			: self::ELEMENTOR_LONG_FIELD_SEGMENT_CHARS;

		return max(
			250,
			absint(
				apply_filters(
					'polymart_ai_elementor_segment_chars',
					$default
				)
			)
		);
	}

	/**
	 * Heavy Elementor fields (HTML editor segments) must not share an API batch with siblings.
	 *
	 * @param string $path Field path.
	 * @param string $text Source text.
	 * @return bool
	 */
	private static function elementor_field_needs_isolated_job_batch( $path, $text ) {
		$path = (string) $path;
		$text = (string) $text;

		if ( self::elementor_path_is_segment( $path ) ) {
			return true;
		}

		if ( self::utf8_strlen( $text ) > 220 ) {
			return true;
		}

		return false !== stripos( $text, '<' ) && self::utf8_strlen( $text ) > 80;
	}

	/**
	 * Scale Arvan HTTP timeout from outbound chunk size (matches test-API behaviour).
	 *
	 * @param array<string, string> $chunk Field map for one API call.
	 * @return array{min_timeout: int, max_timeout: int}
	 */
	private static function build_elementor_chunk_ai_options( array $chunk ) {
		$chars = 0;

		foreach ( $chunk as $key => $value ) {
			$chars += self::utf8_strlen( (string) $key ) + self::utf8_strlen( (string) $value );
		}

		$base = max( 30, absint( self::ELEMENTOR_JOB_REQUEST_TIMEOUT ) );
		$cap  = max( $base, absint( self::ELEMENTOR_JOB_REQUEST_TIMEOUT_MAX ) );

		// Short labels/buttons: keep the fast window. HTML segments scale up like the admin test API.
		if ( $chars <= 160 ) {
			$timeout = $base;
		} else {
			$timeout = (int) min( $cap, max( $base, 18 + (int) ceil( $chars / 16 ) ) );
		}

		/**
		 * Filter HTTP timeout (seconds) for one Elementor API batch.
		 *
		 * @param int                   $timeout Seconds.
		 * @param array<string, string> $chunk   Outbound field map.
		 */
		$timeout = max( $base, min( $cap, (int) apply_filters( 'polymart_ai_elementor_chunk_timeout', $timeout, $chunk ) ) );

		return array(
			'min_timeout' => $timeout,
			'max_timeout' => $timeout,
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
			// Persian source-fallback is a temporary placeholder — never treat it as resolved
			// or gap-fill will mark the long HTML field complete while customers still see FA.
			return ! Persian_Detector::contains_persian( $value );
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

	/**
	 * Bind accepted Elementor field paths for the current request.
	 * Public so Activity_Logger scan/queue helpers can share the same context.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return void
	 */
	public static function bind_elementor_accepted_paths_context( $post_id, $lang ) {
		self::$current_elementor_accepted_paths = self::get_elementor_accepted_paths( $post_id, $lang );
	}

	private static function elementor_path_is_accepted( $path ) {
		$path = (string) $path;

		return '' !== $path && in_array( $path, self::$current_elementor_accepted_paths, true );
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

	/**
	 * Unblock a chunk when API/safety rejected every field — keep partial saves and move on.
	 *
	 * @param array<string, string> $chunk          Chunk payload.
	 * @param array<string, string> $map            Translation map.
	 * @param array<string, string> $source_payload Source payload.
	 * @param array<string, mixed>  $state          Job partial state.
	 * @param array<string, int>    $failures       Failure counters.
	 * @return void
	 */
	private static function release_stalled_elementor_chunk_fields( array $chunk, array &$map, array $source_payload, array &$state, array &$failures ) {
		foreach ( $chunk as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( preg_match( '/::__(?:part|seg)\d+$/', $path ) ) {
				continue;
			}

			$source_text = (string) ( $source_payload[ $path ] ?? $text );

			if ( self::elementor_field_translation_complete( $path, $source_text, $map ) ) {
				continue;
			}

			if ( isset( $map[ $path ] ) ) {
				$restored = Shortcode_Masker::restore_shortcodes( $source_text, (string) $map[ $path ] );

				if ( self::elementor_map_value_is_valid_translation( $path, $source_text, $restored ) ) {
					$map[ $path ] = $restored;
					continue;
				}
			}

			if ( Shortcode_Masker::contains_shortcode( $source_text ) ) {
				self::apply_elementor_shortcode_hybrid_fallback( $path, $source_text, $map, $state );
				continue;
			}

			$failures[ $path ] = absint( $failures[ $path ] ?? 0 ) + 1;

			if ( $failures[ $path ] >= self::ELEMENTOR_SEGMENT_MAX_RETRIES ) {
				$skipped = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
				$skipped[] = $path;
				$state['elementor_skipped'] = array_values( array_unique( array_map( 'strval', $skipped ) ) );
				unset( $failures[ $path ] );
			}
		}
	}

	/**
	 * Keep translated prose but force original shortcodes back into the field.
	 *
	 * @param string               $path  Field path.
	 * @param string               $text  Source text.
	 * @param array<string,string> $map   Translation map.
	 * @param array<string,mixed>  $state Job state.
	 * @return void
	 */
	private static function apply_elementor_shortcode_hybrid_fallback( $path, $text, array &$map, array &$state ) {
		$path = (string) $path;
		$text = (string) $text;

		if ( '' === $path || '' === $text || ! Shortcode_Masker::contains_shortcode( $text ) ) {
			return;
		}

		$candidate = isset( $map[ $path ] ) ? (string) $map[ $path ] : '';
		$hybrid    = Shortcode_Masker::restore_shortcodes( $text, $candidate );

		if ( ! self::elementor_map_value_is_valid_translation( $path, $text, $hybrid ) ) {
			return;
		}

		$map[ $path ] = $hybrid;

		\PolymartAI\Activity_Logger::log(
			'warning',
			sprintf(
				/* translators: 1: field path */
				__( 'Elementor — فیلد %1$s با fallback شورت‌کد ذخیره شد.', 'polymart-ai' ),
				$path
			),
			array( 'path' => $path )
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
			self::accept_elementor_long_field_with_partial_fallback( $path, $text, $map, $state );
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

	/**
	 * Force-close a long HTML field while retaining every successfully translated __segN.
	 * Only unresolved segments are filled from the Persian source.
	 *
	 * @param string                $path  Base field path.
	 * @param string                $text  Source HTML.
	 * @param array<string, string> $map   Translation map.
	 * @param array<string, mixed>  $state Job state.
	 * @return array{kept:int, fallback:int}
	 */
	private static function accept_elementor_long_field_with_partial_fallback( $path, $text, array &$map, array &$state ) {
		$path = (string) $path;
		$text = (string) $text;
		$kept = 0;
		$fallback = 0;
		$seg_lookup = self::get_elementor_segment_source_lookup( $path, $text );

		foreach ( $seg_lookup as $seg_key => $seg_source ) {
			$seg_key    = (string) $seg_key;
			$seg_source = (string) $seg_source;

			if ( self::elementor_segment_is_resolved( $seg_key, $seg_source, $map ) ) {
				++$kept;
				continue;
			}

			// Soft keep: already-English map value that validity helpers might still reject
			// (e.g. shortcode edge cases) must never be overwritten with Persian source.
			if (
				isset( $map[ $seg_key ] )
				&& '' !== trim( (string) $map[ $seg_key ] )
				&& ! Persian_Detector::contains_persian( (string) $map[ $seg_key ] )
				&& (string) $map[ $seg_key ] !== $seg_source
			) {
				++$kept;
				continue;
			}

			self::apply_elementor_segment_source_fallback( $seg_key, $seg_source, $map, $state );
			++$fallback;
		}

		$assembled = self::assemble_elementor_segment_field( $path, $text, $map, true );

		if ( '' !== $assembled ) {
			$map[ $path ] = $assembled;
		}

		\PolymartAI\Activity_Logger::log(
			'warning',
			sprintf(
				/* translators: 1: field path, 2: kept English segments, 3: Persian fallback segments */
				__( 'Elementor — فیلد %1$s: %2$d سگمنت انگلیسی نگه داشته شد؛ %3$d سگمنت با متن اصلی پر شد (partial fallback).', 'polymart-ai' ),
				$path,
				$kept,
				$fallback
			),
			array(
				'path'     => $path,
				'kept'     => $kept,
				'fallback' => $fallback,
			)
		);

		return array(
			'kept'     => $kept,
			'fallback' => $fallback,
		);
	}

	/**
	 * Max __segN items from one widget allowed in a single AI request.
	 *
	 * @param int $segment_count Missing or total segment count for the widget.
	 * @return int
	 */
	private static function resolve_elementor_segment_api_batch_size( $segment_count ) {
		$segment_count = absint( $segment_count );

		if ( $segment_count > self::ELEMENTOR_HUGE_FIELD_SEGMENT_THRESHOLD ) {
			return max( 1, absint( self::ELEMENTOR_HUGE_FIELD_SEGMENT_BATCH_SIZE ) );
		}

		if ( \PolymartAI\Activity_Logger::is_trusted_as_tick() ) {
			return min( 2, max( 1, absint( self::ELEMENTOR_HUGE_FIELD_SEGMENT_BATCH_SIZE ) ) );
		}

		return min( 3, max( 1, absint( self::ELEMENTOR_HUGE_FIELD_SEGMENT_BATCH_SIZE ) ) );
	}

	/**
	 * How many force-finalize deferrals a post's long HTML fields may endure.
	 * Scales with the largest remaining segmented widget so 31-seg fields get enough AI packs.
	 *
	 * @param int                  $post_id Post ID.
	 * @param string               $lang    Language code.
	 * @param array<string, mixed> $state   Optional partial state.
	 * @return int
	 */
	private static function resolve_elementor_long_gap_force_attempt_limit( $post_id, $lang, array $state = array() ) {
		$base_limit = max( 1, absint( self::ELEMENTOR_LONG_GAP_FORCE_ATTEMPT_LIMIT ) );
		$post_id    = absint( $post_id );
		$lang       = sanitize_key( (string) $lang );
		$max_segs   = 0;

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return $base_limit;
		}

		$source_data = json_decode( $raw, true );

		if ( ! is_array( $source_data ) ) {
			return $base_limit;
		}

		$source_payload = self::collect_elementor_translation_payload( $source_data );
		$map            = is_array( $state['elementor_map'] ?? null ) ? $state['elementor_map'] : array();
		$skipped        = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
		$remaining      = self::filter_remaining_elementor_payload( $source_payload, $map, $skipped );

		foreach ( $remaining as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( ! self::stubborn_elementor_field_is_long( $path, $text ) ) {
				continue;
			}

			$max_segs = max( $max_segs, count( self::get_elementor_segment_keys( $path, $text ) ) );
		}

		if ( $max_segs <= self::ELEMENTOR_HUGE_FIELD_SEGMENT_THRESHOLD ) {
			return $base_limit;
		}

		$batch = max( 1, absint( self::ELEMENTOR_HUGE_FIELD_SEGMENT_BATCH_SIZE ) );

		// ceil(segs/batch) packs + a small buffer for API errors/timeouts.
		return max( $base_limit, (int) ceil( $max_segs / $batch ) + 2 );
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
		$is_segment    = (bool) preg_match( '/::__seg\d+$/', $path );

		if ( '' !== $source_text && Shortcode_Masker::contains_shortcode( $source_text ) ) {
			$translated = Shortcode_Masker::restore_shortcodes( $source_text, $translated );
		}

		if ( '' === $translated || Persian_Detector::contains_persian( $translated ) ) {
			return false;
		}

		if ( '' !== $source_text && $translated === $source_text ) {
			return false;
		}

		// Hard shortcode gate for whole fields; segments get a relaxed gate below.
		if (
			! $is_segment
			&& '' !== $source_text
			&& ! Shortcode_Masker::shortcodes_preserved( $source_text, $translated )
		) {
			return false;
		}

		if ( $is_segment ) {
			return self::elementor_segment_translation_is_acceptable( $source_text, $translated );
		}

		return true;
	}

	/**
	 * Relaxed acceptance for __segN HTML pieces — keep English even when AI
	 * lightly reorders safe inline tags (span/strong/em/b/i/br/a).
	 *
	 * @param string $source_text Source segment.
	 * @param string $translated  AI translation.
	 * @return bool
	 */
	private static function elementor_segment_translation_is_acceptable( $source_text, $translated ) {
		$source_text = (string) $source_text;
		$translated  = (string) $translated;

		if ( '' === trim( $translated ) || Persian_Detector::contains_persian( $translated ) ) {
			return false;
		}

		// Dangerous markup must never enter Elementor JSON.
		if ( preg_match( '/<\s*(?:script|iframe|object|embed|form|link|meta|style)\b/i', $translated ) ) {
			return false;
		}

		if ( false !== stripos( $translated, 'javascript:' ) ) {
			return false;
		}

		// Prefer shortcode preservation, but do not hard-fail the whole segment if
		// AI kept most tokens: require at least half of shortcodes to survive.
		if ( Shortcode_Masker::contains_shortcode( $source_text ) ) {
			if ( Shortcode_Masker::shortcodes_preserved( $source_text, $translated ) ) {
				return true;
			}

			$source_codes = preg_match_all( '/\[[^\]]+\]/', $source_text, $sm ) ? count( $sm[0] ) : 0;
			$kept         = 0;

			if ( $source_codes > 0 && preg_match_all( '/\[[^\]]+\]/', $translated, $tm ) ) {
				$kept = count( $tm[0] );
			}

			if ( $source_codes > 0 && $kept < (int) ceil( $source_codes * 0.5 ) ) {
				return false;
			}
		}

		// Length sanity — reject tiny stubs / empty shells for large HTML chunks.
		$src_len = self::utf8_strlen( wp_strip_all_tags( $source_text ) );
		$tr_len  = self::utf8_strlen( wp_strip_all_tags( $translated ) );

		if ( $src_len >= 40 && $tr_len < (int) floor( $src_len * 0.25 ) ) {
			return false;
		}

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
		$missing    = array();

		foreach ( $field_keys as $seg_path => $seg_text ) {
			$seg_path = (string) $seg_path;
			$seg_text = (string) $seg_text;

			if ( self::elementor_path_is_segment( $seg_path ) ) {
				if ( ! self::elementor_segment_is_resolved( $seg_path, $seg_text, $map ) ) {
					$missing[ $seg_path ] = $seg_text;
				}
				continue;
			}

			if ( ! self::elementor_field_translation_complete( $seg_path, $seg_text, $map ) ) {
				$missing[ $seg_path ] = $seg_text;
			}
		}

		if ( empty( $missing ) ) {
			return array();
		}

		uksort(
			$missing,
			static function ( $left, $right ) {
				$left_num  = 0;
				$right_num = 0;

				if ( preg_match( '/::__seg(\d+)$/', (string) $left, $matches ) ) {
					$left_num = (int) $matches[1];
				}

				if ( preg_match( '/::__seg(\d+)$/', (string) $right, $matches ) ) {
					$right_num = (int) $matches[1];
				}

				if ( $left_num !== $right_num ) {
					return $left_num <=> $right_num;
				}

				return strcmp( (string) $left, (string) $right );
			}
		);

		// Huge HTML widgets (e.g. 14–31 segs): never dump everything into one AI call.
		// Cap at ELEMENTOR_HUGE_FIELD_SEGMENT_BATCH_SIZE so Arvan replies stay complete.
		$batch_size = self::resolve_elementor_segment_api_batch_size( count( $missing ) );

		return self::chunk_payload_with_limits(
			$missing,
			$batch_size,
			self::ELEMENTOR_JOB_MAX_CHUNK_CHARS,
			false
		);
	}

	private static function resolve_elementor_stubborn_request_timeout( array $pending ) {
		$max_chars = 0;

		foreach ( $pending as $text ) {
			$max_chars = max( $max_chars, self::utf8_strlen( (string) $text ) );
		}

		if ( $max_chars <= 0 ) {
			return self::ELEMENTOR_JOB_REQUEST_TIMEOUT;
		}

		$scaled = (int) ceil( $max_chars / 12 ) + 25;

		return min(
			self::ELEMENTOR_JOB_REQUEST_TIMEOUT_MAX,
			max( self::ELEMENTOR_JOB_REQUEST_TIMEOUT, $scaled )
		);
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

	private static function prepare_elementor_map_for_persist( array $map, array $source_payload, $allow_partial_segments = false ) {
		$prepared = self::repair_elementor_segment_map_keys( $map, $source_payload );

		foreach ( $source_payload as $path => $text ) {
			$path     = (string) $path;
			$text     = (string) $text;
			$seg_keys = self::get_elementor_segment_keys( $path, $text );

			if ( empty( $seg_keys ) ) {
				continue;
			}

			// Prefer an already-complete collapsed English base over rebuilding from
			// incomplete __segN pieces (source-filled gaps would reintroduce Persian).
			if (
				isset( $prepared[ $path ] )
				&& self::elementor_map_value_is_valid_translation( $path, $text, (string) $prepared[ $path ] )
				&& self::utf8_strlen( (string) $prepared[ $path ] ) >= (int) floor( self::utf8_strlen( $text ) * 0.85 )
			) {
				foreach ( $seg_keys as $seg_key ) {
					unset( $prepared[ $seg_key ] );
				}
				continue;
			}

			$assembled = self::assemble_elementor_segment_field( $path, $text, $prepared, $allow_partial_segments );

			if ( '' === $assembled ) {
				continue;
			}

			// Never overwrite a clean English base with a Persian hybrid.
			if (
				isset( $prepared[ $path ] )
				&& self::elementor_map_value_is_valid_translation( $path, $text, (string) $prepared[ $path ] )
				&& Persian_Detector::contains_persian( $assembled )
			) {
				// Keep English base AND retain any English __segN that still help repairs.
				continue;
			}

			$prepared[ $path ] = $assembled;

			// Only drop __segN keys once the collapsed field is fully English.
			// Partial/hybrid assemblies must keep successful English segments so later
			// gap-fill / force-finalize never rewrites them back to Persian source.
			if ( self::elementor_map_value_is_valid_translation( $path, $text, $assembled ) ) {
				foreach ( $seg_keys as $seg_key ) {
					unset( $prepared[ $seg_key ] );
				}
			}
		}

		return $prepared;
	}

	/**
	 * Merge available __segN translations; optionally gap-fill missing pieces with source HTML.
	 *
	 * @param string                $path                    Base field path.
	 * @param string                $text                    Source field text.
	 * @param array<string, string> $map                     Translation map.
	 * @param bool                  $fill_missing_with_source When true, unresolved segments use source text.
	 * @return string Assembled value or empty when nothing usable exists.
	 */
	private static function assemble_elementor_segment_field( $path, $text, array $map, $fill_missing_with_source = false ) {
		$path     = (string) $path;
		$text     = (string) $text;
		$seg_keys = self::get_elementor_segment_keys( $path, $text );

		if ( empty( $seg_keys ) ) {
			return isset( $map[ $path ] ) ? (string) $map[ $path ] : '';
		}

		$seg_lookup = self::get_elementor_segment_source_lookup( $path, $text );

		// Scan ahead: if any __segN is translated, never abort the whole field on an earlier missing piece.
		if ( ! $fill_missing_with_source ) {
			$any_resolved = false;

			foreach ( $seg_keys as $probe_key ) {
				$probe_source = (string) ( $seg_lookup[ $probe_key ] ?? '' );

				if (
					isset( $map[ $probe_key ] )
					&& self::elementor_segment_is_resolved( $probe_key, $probe_source, $map )
				) {
					$any_resolved = true;
					break;
				}
			}

			if ( $any_resolved ) {
				$fill_missing_with_source = true;
			}
		}

		$parts    = array();
		$resolved = 0;

		foreach ( $seg_keys as $seg_key ) {
			$seg_source = (string) ( $seg_lookup[ $seg_key ] ?? '' );

			if (
				isset( $map[ $seg_key ] )
				&& self::elementor_segment_is_resolved( $seg_key, $seg_source, $map )
			) {
				$parts[] = (string) $map[ $seg_key ];
				++$resolved;
				continue;
			}

			if ( $fill_missing_with_source && '' !== $seg_source ) {
				$parts[] = $seg_source;
				continue;
			}

			return '';
		}

		if ( $resolved <= 0 ) {
			return '';
		}

		return implode( '', $parts );
	}

	/**
	 * Whether enough __segN pieces are translated to treat the long field as complete.
	 *
	 * @param string                $path Base field path.
	 * @param string                $text Source field text.
	 * @param array<string, string> $map  Translation map.
	 * @return bool
	 */
	private static function elementor_segment_field_has_sufficient_coverage( $path, $text, array $map ) {
		$path       = (string) $path;
		$text       = (string) $text;
		$seg_lookup = self::get_elementor_segment_source_lookup( $path, $text );

		if ( empty( $seg_lookup ) ) {
			return false;
		}

		$source_chars    = max( 1, self::utf8_strlen( $text ) );
		$translated_chars = 0;
		$resolved_count   = 0;

		foreach ( $seg_lookup as $seg_key => $seg_source ) {
			$seg_key    = (string) $seg_key;
			$seg_source = (string) $seg_source;

			if ( ! self::elementor_segment_is_resolved( $seg_key, $seg_source, $map ) ) {
				continue;
			}

			++$resolved_count;
			$translated_chars += self::utf8_strlen( (string) $map[ $seg_key ] );
		}

		if ( $resolved_count <= 0 ) {
			return false;
		}

		// Coverage is about progress, not storefront-ready English. Hybrid assemblies
		// may still contain Persian source-filled gaps; those must stay "incomplete"
		// for gap-fill until every __segN is resolved.
		$assembled = self::assemble_elementor_segment_field( $path, $text, $map, true );

		if ( '' === $assembled ) {
			return false;
		}

		if ( $translated_chars < (int) floor( $source_chars * 0.75 ) ) {
			return false;
		}

		// Only treat as sufficiently covered when the collapsed value is clean English.
		return self::elementor_map_value_is_valid_translation( $path, $text, $assembled );
	}

	/**
	 * Match AI responses that dropped the base path prefix but kept __segN suffixes.
	 *
	 * @param array<string, string> $map            Translation map.
	 * @param array<string, string> $source_payload Source payload.
	 * @return array<string, string>
	 */
	private static function repair_elementor_segment_map_keys( array $map, array $source_payload ) {
		$repaired = $map;

		foreach ( $source_payload as $path => $text ) {
			$path       = (string) $path;
			$text       = (string) $text;
			$seg_lookup = self::get_elementor_segment_source_lookup( $path, $text );

			if ( empty( $seg_lookup ) ) {
				continue;
			}

			foreach ( $seg_lookup as $expected_key => $seg_source ) {
				$expected_key = (string) $expected_key;
				$seg_source   = (string) $seg_source;

				if (
					isset( $repaired[ $expected_key ] )
					&& self::elementor_segment_is_resolved( $expected_key, $seg_source, $repaired )
				) {
					continue;
				}

				if ( ! preg_match( '/::__seg(\d+)$/', $expected_key, $matches ) ) {
					continue;
				}

				$seg_index = (string) $matches[1];

				foreach ( $repaired as $candidate_key => $candidate_value ) {
					$candidate_key = (string) $candidate_key;

					if ( $candidate_key === $expected_key ) {
						continue;
					}

					if ( ! preg_match( '/::__seg' . preg_quote( $seg_index, '/' ) . '$/', $candidate_key ) ) {
						continue;
					}

					if ( ! self::elementor_map_value_is_valid_translation( $expected_key, $seg_source, (string) $candidate_value ) ) {
						continue;
					}

					$repaired[ $expected_key ] = (string) $candidate_value;
					break;
				}
			}
		}

		return $repaired;
	}

	/**
	 * Whether the in-memory map contains at least one field worth writing to _elementor_data_{lang}.
	 *
	 * @param array<string, string> $map            Translation map.
	 * @param array<string, string> $source_payload Source payload.
	 * @return bool
	 */
	private static function elementor_map_has_persistable_translations( array $map, array $source_payload ) {
		if ( empty( $map ) ) {
			return false;
		}

		$prepared = self::prepare_elementor_map_for_persist( $map, $source_payload, true );

		foreach ( $source_payload as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( ! isset( $prepared[ $path ] ) ) {
				continue;
			}

			$stored = (string) $prepared[ $path ];

			if ( '' === trim( $stored ) ) {
				continue;
			}

			if ( self::elementor_map_value_is_valid_translation( $path, $text, $stored ) ) {
				return true;
			}
		}

		return false;
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
		$raw_mapped = self::repair_elementor_segment_map_keys( $raw_mapped, $source_payload );
		$mapped     = array();

		foreach ( $raw_mapped as $path => $value ) {
			$path = (string) $path;

			if ( '' === $path ) {
				continue;
			}

			$source_text = (string) ( $source_payload[ $path ] ?? '' );

			if ( preg_match( '/^(.+)::__(?:part|seg)\d+$/', $path, $matches ) ) {
				$base = (string) $matches[1];

				if ( '' === $source_text && preg_match( '/::__seg\d+$/', $path ) ) {
					$base_text   = (string) ( $source_payload[ $base ] ?? '' );
					$seg_lookup  = self::get_elementor_segment_source_lookup( $base, $base_text );
					$source_text = (string) ( $seg_lookup[ $path ] ?? $source_text );
				} elseif ( '' === $source_text && preg_match( '/::__part\d+$/', $path ) ) {
					$source_text = (string) ( $source_payload[ $base ] ?? $source_text );
				}
			}

			if ( self::elementor_map_value_is_valid_translation( $path, $source_text, (string) $value ) ) {
				$mapped[ $path ] = trim( (string) $value );
			}
		}

		foreach ( self::collapse_payload_parts( $raw_mapped ) as $base => $value ) {
			$base = (string) $base;

			if ( '' === $base || preg_match( '/::__(?:part|seg)\d+$/', $base ) ) {
				continue;
			}

			$source = (string) ( $source_payload[ $base ] ?? '' );

			if ( self::elementor_all_segments_present_in_map( $base, $source, $mapped ) ) {
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

		// "Accepted" force-finalize must NOT hide Persian leftovers — otherwise
		// remaining_payload becomes empty while audit still says ناقص Elementor
		// and the bulk job bounces to the next post forever.
		if ( self::elementor_path_is_accepted( $path ) ) {
			$mapped = isset( $map[ $path ] ) ? (string) $map[ $path ] : '';

			if ( '' !== $mapped && ! Persian_Detector::contains_persian( $mapped ) ) {
				return true;
			}

			// Persian / empty accepted → fall through to real completeness checks.
		}

		if (
			in_array( $path, self::$current_elementor_field_passthrough, true )
			&& isset( $map[ $path ] )
			&& (string) $map[ $path ] === $text
		) {
			// Same rule as segments: Persian source placeholders are not complete translations.
			return ! Persian_Detector::contains_persian( $text );
		}

		// For very long Elementor fields, require all __segN pieces (or one collapsed base value).
		$seg_lookup = self::get_elementor_segment_source_lookup( $path, $text );
		if ( ! empty( $seg_lookup ) ) {
			if (
				isset( $map[ $path ] )
				&& self::elementor_map_value_is_valid_translation( $path, $text, (string) $map[ $path ] )
				&& self::utf8_strlen( (string) $map[ $path ] ) >= (int) floor( self::utf8_strlen( $text ) * 0.85 )
			) {
				return true;
			}

			$all_segs_ok = true;
			$resolved    = 0;

			foreach ( $seg_lookup as $seg_key => $seg_source ) {
				if ( self::elementor_segment_is_resolved( $seg_key, (string) $seg_source, $map ) ) {
					++$resolved;
					continue;
				}

				$all_segs_ok = false;
			}

			if ( $all_segs_ok ) {
				return true;
			}

			// Accept resilient partial assembly when most segment characters are translated
			// (including cases where an early __segN is missing but later ones are present).
			if ( $resolved > 0 && self::elementor_segment_field_has_sufficient_coverage( $path, $text, $map ) ) {
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
			? self::ELEMENTOR_BULK_JOB_FIELD_CHUNK_SIZE
			: self::ELEMENTOR_JOB_FIELD_CHUNK_SIZE;

		/**
		 * Filter Elementor fields per API batch in job slices.
		 *
		 * @param int                   $chunk_size Fields per batch.
		 * @param array<string, string> $payload    Source field map.
		 */
		$chunk_size = max( 1, (int) apply_filters( 'polymart_ai_elementor_job_field_chunk_size', $chunk_size, $payload ) );

		$expanded = self::expand_elementor_payload_for_ai( $payload );
		$chunks   = array();
		$current  = array();
		$current_chars = 0;
		$max_chars     = self::ELEMENTOR_JOB_MAX_CHUNK_CHARS;

		// Group __segN by base widget so huge HTML fields pack ≤ N segs/request.
		$segment_groups = array();
		$plain          = array();

		foreach ( $expanded as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( preg_match( '/^(.+)::__seg\d+$/', $path, $matches ) ) {
				$base = (string) $matches[1];
				if ( ! isset( $segment_groups[ $base ] ) ) {
					$segment_groups[ $base ] = array();
				}
				$segment_groups[ $base ][ $path ] = $text;
				continue;
			}

			$plain[ $path ] = $text;
		}

		foreach ( $segment_groups as $base => $group ) {
			uksort(
				$group,
				static function ( $left, $right ) {
					$left_num  = preg_match( '/::__seg(\d+)$/', (string) $left, $m ) ? (int) $m[1] : 0;
					$right_num = preg_match( '/::__seg(\d+)$/', (string) $right, $m ) ? (int) $m[1] : 0;

					return $left_num <=> $right_num;
				}
			);

			$seg_batch_size = self::resolve_elementor_segment_api_batch_size( count( $group ) );
			$seg_chunks     = self::chunk_payload_with_limits(
				$group,
				$seg_batch_size,
				$max_chars,
				false
			);

			foreach ( $seg_chunks as $seg_chunk ) {
				if ( ! empty( $current ) ) {
					$chunks[]      = $current;
					$current       = array();
					$current_chars = 0;
				}
				$chunks[] = $seg_chunk;
			}

			unset( $base );
		}

		foreach ( $plain as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( self::elementor_field_needs_isolated_job_batch( $path, $text ) ) {
				if ( ! empty( $current ) ) {
					$chunks[] = $current;
					$current       = array();
					$current_chars = 0;
				}

				$chunks[] = array( $path => $text );
				continue;
			}

			$size = self::utf8_strlen( $path ) + self::utf8_strlen( $text );

			if (
				! empty( $current )
				&& (
					$current_chars + $size > $max_chars
					|| count( $current ) >= $chunk_size
				)
			) {
				$chunks[]      = $current;
				$current       = array();
				$current_chars = 0;
			}

			$current[ $path ] = $text;
			$current_chars += $size;
		}

		if ( ! empty( $current ) ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Canonical job payload bundle — same collapse/mirror rules for scanner, queue, and repair.
	 *
	 * @param array<string, mixed> $source_data Elementor JSON tree.
	 * @return array{full: array<string, string>, payload: array<string, string>, mirrors: array<string, string[]>}
	 */
	private static function prepare_elementor_job_payload_bundle( array $source_data ) {
		$full      = self::collect_elementor_translation_payload( $source_data );
		$collapsed = self::collapse_duplicate_elementor_payload( $full );

		return array(
			'full'    => $full,
			'payload' => $collapsed['payload'],
			'mirrors' => $collapsed['mirrors'],
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
		$merged      = array_merge( $rebuilt, $persist_map, $seg_map, $state_map, $overlay_map );
		$payload     = self::collect_elementor_translation_payload( $source_data );

		$merged = self::repair_elementor_segment_map_keys( $merged, $payload );

		return self::expand_elementor_map_mirrors( $merged, self::get_elementor_text_mirror_paths( $payload ) );
	}

	private static function merge_elementor_path_map( $post_id, $lang, array $source_data, array $map ) {
		$rebuilt = self::rebuild_elementor_map_from_saved_translation( $post_id, $lang, $source_data );
		$merged  = array_merge( $rebuilt, $map );
		$payload = self::collect_elementor_translation_payload( $source_data );

		$merged = self::repair_elementor_segment_map_keys( $merged, $payload );

		return self::expand_elementor_map_mirrors( $merged, self::get_elementor_text_mirror_paths( $payload ) );
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

}
