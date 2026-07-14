<?php
/**
 * Split Post_Translator monolith into domain traits (tokenizer-safe).
 *
 * Usage: php tools/split-post-translator-traits.php
 *
 * @package PolymartAI
 */

declare( strict_types=1 );

$plugin_root  = dirname( __DIR__ );
$backup_file  = $plugin_root . '/includes/Translation/class-post-translator.full-backup.php';
$output_file  = $plugin_root . '/includes/Translation/class-post-translator.php';
$traits_root  = $plugin_root . '/includes/Translation/Post_Translator/Traits';
$legacy_traits = $plugin_root . '/includes/Translation/Post_Translator/traits';

if ( ! is_readable( $backup_file ) ) {
	fwrite( STDERR, "Backup missing: {$backup_file}\n" );
	exit( 1 );
}

$code  = file_get_contents( $backup_file );
$lines = file( $backup_file, FILE_IGNORE_NEW_LINES );
if ( false === $lines || ! is_string( $code ) ) {
	fwrite( STDERR, "Failed to read backup.\n" );
	exit( 1 );
}

/**
 * @return array{open:int,close:int}|null
 */
function find_class_bounds( array $lines ): ?array {
	$open = null;
	foreach ( $lines as $i => $line ) {
		if ( preg_match( '/^\s*final\s+class\s+Post_Translator\b/', $line ) ) {
			$open = $i;
			break;
		}
	}
	if ( null === $open ) {
		return null;
	}
	$close = count( $lines ) - 1;
	while ( $close > $open && ! preg_match( '/^\}\s*$/', $lines[ $close ] ) ) {
		$close--;
	}
	return $close > $open ? array( 'open' => $open, 'close' => $close ) : null;
}

/**
 * @param array<int, array|string> $tokens
 * @return array<int, int>
 */
function build_token_line_index( array $tokens ): array {
	$lines = array();
	$line  = 1;

	foreach ( $tokens as $idx => $tok ) {
		if ( is_array( $tok ) && isset( $tok[2] ) ) {
			$line = (int) $tok[2];
		}

		$lines[ $idx ] = $line;
		$text          = is_array( $tok ) ? $tok[1] : $tok;
		$line         += substr_count( $text, "\n" );
	}

	return $lines;
}

/**
 * @return list<array{name:string,start:int,end:int}>
 */
function parse_methods_in_class( string $code, array $lines, int $class_open_line, int $class_close_line ): array {
	$tokens      = token_get_all( $code );
	$token_lines = build_token_line_index( $tokens );
	$methods = array();
	$in_target_class = false;
	$class_depth     = 0;
	$i               = 0;
	$count           = count( $tokens );

	while ( $i < $count ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) && T_CLASS === $token[0] ) {
			$name_idx = $i + 1;
			while ( $name_idx < $count && is_array( $tokens[ $name_idx ] ) && T_WHITESPACE === $tokens[ $name_idx ][0] ) {
				++$name_idx;
			}
			if ( $name_idx < $count && is_array( $tokens[ $name_idx ] ) && T_STRING === $tokens[ $name_idx ][0] && 'Post_Translator' === $tokens[ $name_idx ][1] ) {
				$in_target_class = true;
				$class_depth     = 0;
			}
			++$i;
			continue;
		}

		if ( ! $in_target_class ) {
			++$i;
			continue;
		}

		if ( '{' === $tokens[ $i ] ) {
			++$class_depth;
			++$i;
			continue;
		}

		if ( '}' === $tokens[ $i ] ) {
			--$class_depth;
			if ( $class_depth <= 0 ) {
				break;
			}
			++$i;
			continue;
		}

		if ( is_array( $token ) && T_FUNCTION === $token[0] && $class_depth === 1 ) {
			$vis_idx = $i - 1;
			while ( $vis_idx >= 0 && is_array( $tokens[ $vis_idx ] ) && T_WHITESPACE === $tokens[ $vis_idx ][0] ) {
				--$vis_idx;
			}
			if ( $vis_idx < 0 || ! is_array( $tokens[ $vis_idx ] ) || T_PRIVATE !== $tokens[ $vis_idx ][0] && T_PROTECTED !== $tokens[ $vis_idx ][0] && T_PUBLIC !== $tokens[ $vis_idx ][0] && T_STATIC !== $tokens[ $vis_idx ][0] ) {
				// Allow docblock-only gap: scan back for visibility.
				$scan = $i - 1;
				$has_visibility = false;
				while ( $scan >= 0 ) {
					if ( is_array( $tokens[ $scan ] ) && in_array( $tokens[ $scan ][0], array( T_PRIVATE, T_PROTECTED, T_PUBLIC, T_STATIC ), true ) ) {
						$has_visibility = true;
						break;
					}
					if ( is_array( $tokens[ $scan ] ) && T_FUNCTION === $tokens[ $scan ][0] ) {
						break;
					}
					--$scan;
				}
				if ( ! $has_visibility ) {
					++$i;
					continue;
				}
			}

			$name_idx = $i + 1;
			while ( $name_idx < $count && is_array( $tokens[ $name_idx ] ) && T_WHITESPACE === $tokens[ $name_idx ][0] ) {
				++$name_idx;
			}
			if ( $name_idx >= $count || ! is_array( $tokens[ $name_idx ] ) || T_STRING !== $tokens[ $name_idx ][0] ) {
				++$i;
				continue;
			}

			$name = $tokens[ $name_idx ][1];
			$start_line = $token[2];

			// Include docblock immediately above.
			$doc_idx = $i - 1;
			while ( $doc_idx >= 0 && is_array( $tokens[ $doc_idx ] ) && T_WHITESPACE === $tokens[ $doc_idx ][0] ) {
				--$doc_idx;
			}
			if ( $doc_idx >= 0 && is_array( $tokens[ $doc_idx ] ) && T_DOC_COMMENT === $tokens[ $doc_idx ][0] ) {
				$start_line = $tokens[ $doc_idx ][2];
			} else {
				$vis_scan = $i - 1;
				while ( $vis_scan >= 0 ) {
					if ( is_array( $tokens[ $vis_scan ] ) && in_array( $tokens[ $vis_scan ][0], array( T_PUBLIC, T_PROTECTED, T_PRIVATE ), true ) ) {
						$start_line = min( $start_line, $tokens[ $vis_scan ][2] );
						break;
					}
					if ( is_array( $tokens[ $vis_scan ] ) && T_DOC_COMMENT === $tokens[ $vis_scan ][0] ) {
						$start_line = min( $start_line, $tokens[ $vis_scan ][2] );
					}
					--$vis_scan;
				}
			}

			$brace_idx = $name_idx + 1;
			while ( $brace_idx < $count && '{' !== $tokens[ $brace_idx ] ) {
				++$brace_idx;
			}
			if ( $brace_idx >= $count ) {
				++$i;
				continue;
			}

			$depth         = 0;
			$end_line      = $start_line;
			$interp_depth  = 0;
			$j             = $brace_idx;
			while ( $j < $count ) {
				$tok = $tokens[ $j ];

				if ( is_array( $tok ) ) {
					$type = $tok[0];
					if ( T_CONSTANT_ENCAPSED_STRING === $type || T_ENCAPSED_AND_WHITESPACE === $type ) {
						++$j;
						continue;
					}
					if ( T_CURLY_OPEN === $type || T_DOLLAR_OPEN_CURLY_BRACES === $type ) {
						++$interp_depth;
						++$j;
						continue;
					}
				}

				if ( $interp_depth > 0 ) {
					if ( '}' === $tok ) {
						--$interp_depth;
					}
					++$j;
					continue;
				}

				if ( '{' === $tok ) {
					++$depth;
				} elseif ( '}' === $tok ) {
					--$depth;
					if ( 0 === $depth ) {
						$end_line = $token_lines[ $j ] ?? $start_line;
						break;
					}
				}
				++$j;
			}

			if ( $end_line < $start_line ) {
				++$i;
				continue;
			}

			if ( $start_line >= $class_open_line && $end_line <= $class_close_line ) {
				$methods[] = array(
					'name'  => $name,
					'start' => $start_line - 1,
					'end'   => $end_line - 1,
				);
			}

			$i = $j + 1;
			continue;
		}

		++$i;
	}

	return $methods;
}

/**
 * @param list<array{name:string,start:int,end:int}> $methods
 */
function extract_config_properties( array $lines, int $class_open, int $class_close, array $methods ): string {
	$in_method = static function ( int $line ) use ( $methods ): bool {
		foreach ( $methods as $method ) {
			if ( $line >= $method['start'] && $line <= $method['end'] ) {
				return true;
			}
		}
		return false;
	};

	$config   = array();
	$pending  = array();
	$in_array = false;

	for ( $i = $class_open + 1; $i < $class_close; $i++ ) {
		if ( $in_method( $i ) ) {
			$pending  = array();
			$in_array = false;
			continue;
		}

		$line = $lines[ $i ];

		if ( preg_match( '/^\s*(\/\*\*|\*\/|\*\s)/', $line ) ) {
			$pending[] = $line;
			continue;
		}

		if ( preg_match( '/^\t(?!\t)(?:(?:public|private|protected)\s+(?:static\s+)?(?:const\s+\w+|\$\w+)|const\s+\w+)/', $line ) ) {
			foreach ( $pending as $doc ) {
				$config[] = $doc;
			}
			$pending   = array();
			$config[]  = $line;
			$in_array  = ( str_contains( $line, 'array(' ) && ! str_contains( $line, ');' ) )
				|| ( substr_count( $line, '(' ) > substr_count( $line, ')' ) );
			continue;
		}

		if ( $in_array ) {
			$config[] = $line;
			if ( preg_match( '/\);\s*$/', $line ) ) {
				$in_array = false;
			}
			continue;
		}

		if ( '' === trim( $line ) && ! empty( $config ) && '' !== trim( (string) end( $config ) ) ) {
			$config[]  = '';
			$pending   = array();
		}
	}

	// Trim leading/trailing blank lines.
	while ( ! empty( $config ) && '' === trim( (string) $config[0] ) ) {
		array_shift( $config );
	}
	while ( ! empty( $config ) && '' === trim( (string) end( $config ) ) ) {
		array_pop( $config );
	}

	return implode( "\n", $config );
}

/**
 * @return array{constants: string, state: string}
 */
function split_config_sections( string $config_body ): array {
	$config_lines = explode( "\n", $config_body );
	$constants    = array();
	$state        = array();
	$pending      = array();
	$target       = null;
	$in_array     = false;

	foreach ( $config_lines as $line ) {
		if ( preg_match( '/^\t(?!\t)(?:(?:public|private|protected)\s+(?:static\s+)?(?:const\s+\w+|\$\w+)|const\s+\w+)/', $line ) ) {
			$target = preg_match( '/^\t(?:private|protected|public)\s+static\s+\$/', $line ) ? 'state' : 'constants';
			$bucket = 'state' === $target ? $state : $constants;

			foreach ( $pending as $doc ) {
				$bucket[] = $doc;
			}
			$pending  = array();
			$bucket[] = $line;
			$in_array = ( str_contains( $line, 'array(' ) && ! str_contains( $line, ');' ) )
				|| ( substr_count( $line, '(' ) > substr_count( $line, ')' ) );

			if ( 'state' === $target ) {
				$state = $bucket;
			} else {
				$constants = $bucket;
			}
			continue;
		}

		if ( $in_array ) {
			if ( 'state' === $target ) {
				$state[] = $line;
			} else {
				$constants[] = $line;
			}
			if ( preg_match( '/\);\s*$/', $line ) ) {
				$in_array = false;
			}
			continue;
		}

		if ( preg_match( '/^\s*(\/\*\*|\*\/|\*\s)/', $line ) ) {
			$pending[] = $line;
		}
	}

	return array(
		'constants' => implode( "\n", $constants ),
		'state'     => implode( "\n", $state ),
	);
}

/**
 * Split Elementor job methods into focused sub-traits.
 */
$assign_elementor_job = static function ( string $name ): string {
	static $rules = array(
		'Job_Cursor'   => '/^get_elementor_primary_done_meta_key$|^resolve_elementor_done_count$|^get_elementor_slice_cursor_meta_key$|^read_elementor_slice_cursor|^write_elementor_slice_cursor$|^commit_elementor_chunk_progress_to_site$|^persist_elementor_primary_batches_done$|^clear_elementor_primary_batches_lock$|^clear_elementor_slice_cursor$|^infer_elementor_slice_cursor_from_saved$|^reconcile_elementor_slice_cursor$|^resolve_elementor_slice_cursor$|^get_elementor_progress_meta_key$|^is_elementor_progress_message$|^get_elementor_chunk_progress$|^count_elementor_job_progress_done$/i',
		'Job_State'    => '/^elementor_job_partial_state_is_durable$|^should_run_elementor_job_slice$|^hydrate_elementor_job_partial_state$|^elementor_job_api_slices_pending$|^elementor_job_api_schedule_complete$|^elementor_job_primary_batches_exhausted$|^elementor_primary_schedule_locked$|^elementor_job_map_has_no_remaining$|^elementor_in_gap_fill_handoff$|^elementor_needs_gap_fill_work$|^elementor_job_slice_is_truly_complete$|^elementor_job_has_remaining_payload$|^post_needs_elementor_job_work$|^repair_stale_elementor_job_state$|^unblock_elementor_chunk_queue$|^get_elementor_scan_diagnostics$|^format_elementor_job_progress_marker$|^format_elementor_job_slice_status_message$/i',
		'Job_Chunk'    => '/^elementor_chunk_is_satisfied$|^elementor_chunk_paths_translated$|^compute_elementor_api_batch_progress$|^build_elementor_job_chunk_queue$|^rebuild_elementor_map_from_saved_translation$|^get_elementor_value_at_path$/i',
		'Job_Runner'   => '/^process_elementor_job_slice$|^handle_elementor_job_slice_failure$|^skip_elementor_chunk_on_api_timeout$|^skip_next_elementor_pending_chunk$/i',
		'Job_Gap_Fill' => '/^ensure_elementor_stubborn_handoff$|^run_elementor_stubborn_handoff_tick$|^finalize_elementor_stubborn_handoff_success$|^force_finalize_elementor_job_with_fallback$|^get_elementor_job_finalize_blockers$|^respond_elementor_blocked_finalize$|^finalize_elementor_job_slice$|^describe_elementor_field_translation_blocker$|^format_elementor_gap_fill_remaining_message$|^log_elementor_remaining_field_diagnostics$|^schedule_elementor_gap_fill_chunks$|^register_elementor_gap_fill_chunk_done$|^build_elementor_gap_fill_chunks$|^resolve_elementor_gap_fill_totals$|^sort_elementor_gap_fill_remaining$|^translate_elementor_gap_fill_stubborn_fields$|^translate_elementor_stubborn_field_batch$|^elementor_gap_fill_remaining_is_long_tail_only$/i',
		'Job_Persist'  => '/^persist_elementor_job_progress$|^persist_elementor_translation$|^translate_elementor_payload_map$/i',
	);

	foreach ( $rules as $trait => $regex ) {
		if ( preg_match( $regex, $name ) ) {
			return $trait;
		}
	}

	return 'Job_State';
};

/**
 * @return list<string>
 */
function collect_trait_php_files( string $root ): array {
	$files = array();
	if ( ! is_dir( $root ) ) {
		return $files;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file_info ) {
		if ( ! $file_info->isFile() ) {
			continue;
		}

		$name = $file_info->getFilename();
		if ( str_starts_with( $name, 'trait-' ) && str_ends_with( $name, '.php' ) ) {
			$files[] = $file_info->getPathname();
		}
	}

	usort(
		$files,
		static function ( string $a, string $b ): int {
			$depth_a = substr_count( str_replace( '\\', '/', $a ), '/' );
			$depth_b = substr_count( str_replace( '\\', '/', $b ), '/' );

			return $depth_b <=> $depth_a;
		}
	);

	return $files;
}

/**
 * @param list<string> $paths
 */
function remove_path_tree( array $paths ): void {
	foreach ( $paths as $path ) {
		if ( is_file( $path ) ) {
			unlink( $path );
			continue;
		}

		if ( ! is_dir( $path ) ) {
			continue;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file_info ) {
			if ( $file_info->isDir() ) {
				rmdir( $file_info->getPathname() );
			} else {
				unlink( $file_info->getPathname() );
			}
		}

		rmdir( $path );
	}
}

/**
 * @param array<int, string> $uses FQCN list for composite trait body.
 */
function write_composite_trait( string $file, string $namespace, string $trait_name, string $label, array $uses ): void {
	$use_lines = array();
	foreach ( $uses as $fqcn ) {
		$use_lines[] = "\tuse {$fqcn};";
	}

	$content = <<<PHP
<?php
/**
 * {$label} (auto-split composite).
 *
 * @package PolymartAI\\Translation\\Post_Translator\\Traits
 */

namespace {$namespace};

defined( 'ABSPATH' ) || exit;

trait {$trait_name} {
{USES}
}

PHP;

	file_put_contents( $file, str_replace( '{USES}', implode( "\n", $use_lines ), $content ) );
}

/**
 * @param list<string> $body_lines
 */
function write_domain_trait(
	string $file,
	string $namespace,
	string $trait_name,
	string $label,
	array $body_lines,
	bool $with_service_imports = true
): void {
	$imports = $with_service_imports
		? <<<'PHP'

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\AI_Client;
use PolymartAI\Translation\Persian_Detector;
use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Post_Translator\Persistence_Guard;
use PolymartAI\Translation\Post_Translator\Storefront_Resolver;
use PolymartAI\Translation\Post_Translator\Text_Normalizer;
use PolymartAI\Translation\Post_Translator\Translation_Lock;
PHP
		: '';

	$content = <<<PHP
<?php
/**
 * Post_Translator {$label} (auto-split).
 *
 * @package PolymartAI\\Translation\\Post_Translator\\Traits
 */

namespace {$namespace};
{$imports}

defined( 'ABSPATH' ) || exit;

trait {$trait_name} {


PHP;

	$content .= implode( "\n", $body_lines ) . "\n}\n";
	file_put_contents( $file, $content );
}

/**
 * @param list<array{name:string,start:int,end:int}> $chunks
 * @return list<string>
 */
function extract_method_lines( array $lines, array $chunks, array $skip_names = array() ): array {
	$body = array();

	foreach ( $chunks as $chunk ) {
		if ( in_array( $chunk['name'], $skip_names, true ) ) {
			continue;
		}

		for ( $l = $chunk['start']; $l <= $chunk['end']; $l++ ) {
			$body[] = $lines[ $l ];
		}
		$body[] = '';
	}

	return $body;
}

$domain_map = array(
	'Admin_Save'         => array( 'path' => 'Admin', 'trait' => 'Trait_Admin_Save', 'file' => 'trait-admin-save.php' ),
	'Ai_Persistence'     => array( 'path' => 'AiPersistence', 'trait' => 'Trait_Ai_Persistence', 'file' => 'trait-ai-persistence.php' ),
	'Core'               => array( 'path' => 'Core', 'trait' => 'Trait_Core', 'file' => 'trait-core.php' ),
	'Invalidation'       => array( 'path' => 'Invalidation', 'trait' => 'Trait_Invalidation', 'file' => 'trait-invalidation.php' ),
	'Job_Slice'          => array( 'path' => 'JobSlice', 'trait' => 'Trait_Job_Slice', 'file' => 'trait-job-slice.php' ),
	'Meta_Discovery'     => array( 'path' => 'MetaDiscovery', 'trait' => 'Trait_Meta_Discovery', 'file' => 'trait-meta-discovery.php' ),
	'Storefront'         => array( 'path' => 'Storefront', 'trait' => 'Trait_Storefront', 'file' => 'trait-storefront.php' ),
	'Term'               => array( 'path' => 'Term', 'trait' => 'Trait_Term', 'file' => 'trait-term.php' ),
	'Translation_Status' => array( 'path' => 'TranslationStatus', 'trait' => 'Trait_Translation_Status', 'file' => 'trait-translation-status.php' ),
	'Variation'          => array( 'path' => 'Variation', 'trait' => 'Trait_Variation', 'file' => 'trait-variation.php' ),
	'Elementor_Storage'  => array( 'path' => 'Elementor', 'trait' => 'Trait_Storage', 'file' => 'trait-storage.php' ),
	'Elementor_Chunking' => array( 'path' => 'Elementor', 'trait' => 'Trait_Chunking', 'file' => 'trait-chunking.php' ),
	'Elementor_Walk'     => array( 'path' => 'Elementor', 'trait' => 'Trait_Walk', 'file' => 'trait-walk.php' ),
);

$elementor_job_map = array(
	'Job_Cursor'   => array( 'trait' => 'Trait_Job_Cursor', 'file' => 'trait-job-cursor.php' ),
	'Job_State'    => array( 'trait' => 'Trait_Job_State', 'file' => 'trait-job-state.php' ),
	'Job_Chunk'    => array( 'trait' => 'Trait_Job_Chunk', 'file' => 'trait-job-chunk.php' ),
	'Job_Runner'   => array( 'trait' => 'Trait_Job_Runner', 'file' => 'trait-job-runner.php' ),
	'Job_Gap_Fill' => array( 'trait' => 'Trait_Job_Gap_Fill', 'file' => 'trait-job-gap-fill.php' ),
	'Job_Persist'  => array( 'trait' => 'Trait_Job_Persist', 'file' => 'trait-job-persist.php' ),
);

$core_normalizer_methods = array(
	'normalize_ai_translation_value',
	'prepare_stored_meta_value',
	'normalize_translation_plaintext',
	'is_clean_target_language_translation',
	'utf8_strlen',
	'utf8_substr',
);

$facade_delegate_methods = array(
	'is_persisting_translations',
	'is_admin_translation_save_request',
	'should_skip_translation_invalidation',
	'begin_persisting_translations',
	'end_persisting_translations',
	'field_source_text_meaningfully_changed',
	'get_supported_post_types',
	'get_skipped_meta_prefixes',
	'is_translatable_meta_key',
	'is_custom_meta_key',
	'get_translatable_taxonomies',
	'get_meta_key',
	'get_form_field_name',
	'map_meta_keys_to_form_fields',
	'get_custom_meta_key',
	'get_thumbnail_meta_key',
	'get_field_source_hash_meta_key',
	'get_term_source_hash_meta_key',
	'has_meaningful_translation',
	'is_usable_storefront_translation',
	'get_term_meta_key',
	'get_menu_title_meta_key',
	'owns_translation_lock',
	'acquire_translation_lock',
	'release_translation_lock',
);

$assign = static function ( string $name ): string {
	static $rules = array(
		'Elementor_Storage' => '/^get_elementor_meta_key$|^get_elementor_source_hash_meta_key$|^compute_elementor_source_hash$|^is_elementor_translation_current$|^get_stored_elementor_json$|^can_serve_stored_elementor_json_on_storefront$|^get_max_storefront_elementor_json_bytes$|^save_elementor_source_hash$|^invalidate_elementor_translations$|^uses_elementor_builder$|^should_require_elementor_translation$|^capture_elementor_data_prev_value$|^maybe_invalidate_elementor_on_source_change$|^has_elementor_persian_content$|^has_stored_elementor_translation$|^stored_elementor_translation_has_persian$|^collect_stored_elementor_persian_plain_text$|^allows_elementor_audit_decode$/i',
		'Elementor_Chunking' => '/^get_elementor_segment_max_chars$|^elementor_find_html_safe_cut$|^elementor_balance_html_fragment$|^elementor_html_safe_take_chunk$|^elementor_path_is_segment$|^elementor_segment_is_resolved$|^bind_elementor_segment_passthrough_context$|^remember_elementor_segment_progress$|^apply_elementor_segment_source_fallback$|^apply_elementor_field_source_fallback$|^split_elementor_text_into_segments$|^expand_elementor_payload_for_ai$|^get_elementor_segment_keys$|^get_elementor_segment_source_lookup$|^chunk_elementor_payload_for_job$|^chunk_elementor_payload_for_ai$|^chunk_elementor_gap_fill_payload$|^collapse_duplicate_elementor_payload$|^expand_elementor_map_mirrors$|^get_elementor_text_mirror_paths$|^elementor_map_value_is_valid_translation$|^sanitize_elementor_translation_map$|^extract_elementor_segment_map_entries$|^extract_elementor_persist_map_entries$|^sync_elementor_persist_map_state$|^elementor_chunk_fingerprint$|^elementor_field_translation_complete$|^elementor_collapsed_base_translation_complete$|^elementor_all_segments_present_in_map$|^merge_elementor_api_translations_into_map$|^prepare_elementor_map_for_persist$|^prune_complete_elementor_segment_map_entries$|^build_elementor_stubborn_segment_batches$|^filter_elementor_gap_fill_pending_chunks$|^filter_elementor_gap_fill_batch_remaining$|^stubborn_elementor_field_is_long$|^alias_elementor_payload_keys$|^unmap_elementor_aliases$|^merge_elementor_path_map$|^merge_elementor_job_path_map$/i',
		'Elementor_Job' => '/^elementor_|^persist_elementor_|^translate_elementor_|^process_elementor_|^handle_elementor_|^skip_.*elementor|^skip_next_elementor|^run_elementor_|^ensure_elementor_|^finalize_elementor_|^force_finalize_elementor_|^repair_stale_elementor|^unblock_elementor|^get_elementor_scan|^hydrate_elementor_job|^should_run_elementor|^resolve_elementor_|^get_elementor_slice|^read_elementor_slice|^write_elementor_slice|^commit_elementor_|^clear_elementor_|^persist_elementor_primary|^infer_elementor_|^reconcile_elementor_|^compute_elementor_api|^elementor_chunk_|^elementor_primary|^elementor_in_gap|^build_elementor_gap|^build_elementor_job|^rebuild_elementor_map|^get_elementor_value_at|^get_elementor_progress|^is_elementor_progress|^schedule_elementor_gap|^register_elementor_gap|^sort_elementor_gap|^describe_elementor_field|^format_elementor_gap|^log_elementor_remaining|^format_elementor_job|^count_elementor_job|^get_elementor_chunk_progress|^get_elementor_job_finalize|^respond_elementor_blocked|^elementor_needs_gap|^elementor_job_|^post_needs_elementor|^get_elementor_primary_done_meta_key$/i',
		'Elementor_Walk' => '/^walk_elementor|^collect_elementor_translation_payload$|^collect_elementor_filtered_out_payload$|^walk_elementor_filtered_out|^walk_elementor_translation_payload$|^walk_elementor_settings_payload$|^should_collect_elementor_setting_for_translation$|^should_skip_elementor_setting_key$|^is_elementor_user_text_setting_key$|^is_elementor_translatable_url_key$|^elementor_gap_fill_remaining_is_long_tail_only$|^apply_elementor_translation_payload$|^apply_elementor_settings_payload$|^collect_elementor_persian_plain_text$|^walk_elementor_persian_text$|^extract_elementor_html_persian_excerpt$|^translate_elementor_payload_map$|^translate_elementor_gap_fill_stubborn_fields$|^translate_elementor_stubborn_field_batch$|^persist_elementor_translation$|^persist_elementor_job_progress$/i',
		'Job_Slice' => '/^process_job_|^run_job_|^build_initial_job_|^get_job_partial|^save_job_partial|^clear_job_partial|^job_partial|^chunk_payload_for_ai$|^chunk_payload_for_job_phase$|^chunk_payload_with_limits$|^expand_payload_for_ai$|^collapse_payload_parts$|^collect_persian_fields|^get_job_ai_request_options$|^get_job_step_|^translate_job_chunk|^finalize_job_field|^get_next_job_field|^get_job_field_phase|^run_job_field_phase|^is_recoverable_job_slice|^make_recoverable_partial|^can_translate_post$|^post_is_actionable_for_job$|^post_needs_translation_work$|^describe_translation_gap$|^storefront_would_show_persian_source$|^refresh_translation_lock$|^touch_translation_lock$|^ensure_translation_lock_for_persist$|^release_stale_translation_lock$|^get_translation_lock_status$|^prepare_admin_metabox_translation_lock$|^is_api_transport_timeout_error$|^get_translation_lock_token$|^get_translation_lock_keys$/i',
		'Translation_Status' => '/^get_translation_status|^get_translation_gaps|^compute_translation_audit|^sanitize_translation_audit|^finalize_translation_audit|^sync_translation_index|^reconcile_.*translation_index|^flush_translation_status|^format_translation_record|^get_status_index|^get_custom_meta_label|^get_post_type_label|^get_discovered_meta_fields_cached|^has_persian_content$|^post_has_persian_content$|^get_translation_settings$/i',
		'Ai_Persistence' => '/^request_ai_|^save_ai_|^map_ai_response|^save_term_translations$|^save_variation_title_translations$|^collect_translatable_terms$|^collect_product_attribute_fields$|^store_product_attribute_translation$|^persist_attribute_runtime_cache$|^persist_cms_block_runtime_cache$|^get_stored_product_attribute_translation$|^has_product_attribute_translation$|^build_term_payload_key$|^build_variation_|^is_term_payload_key$|^is_variation_title_payload_key$|^attribute_/i',
		'Invalidation' => '/^invalidate_|^clear_all_post_translations$|^clear_post_language_translations$|^reconcile_post_translations|^reconcile_variation|^should_serve_stored_translation$|^is_field_translation_current$|^is_term_translation_current$|^store_field_source_hash$|^persist_field_source_hashes$|^sync_field_source_hashes_for_post$|^resolve_source_key_for_translated_meta$|^get_field_source_text$|^resolve_translated_meta_key_for_source$/i',
		'Variation' => '/^get_product_variation|^get_variation_|^compute_product_variation|^refresh_product_variation|^invalidate_product_variation/i',
		'Term' => '/^term_has_persian|^collect_term_persian|^request_ai_term|^save_ai_term|^invalidate_term_translations$/i',
		'Storefront' => '/^resolve_storefront_|^peek_storefront|^storefront_title_|^derive_storefront|^format_storefront|^is_unsuitable_derived|^get_storefront_companion|^get_translated_thumbnail|^ensure_translated_thumbnail|^supports_featured_image/i',
		'Admin_Save' => '/^__construct$|^maybe_begin_admin|^save_manual|^sync_translation_index_on_save$|^save_meta_field$|^save_thumbnail_field$|^save_manual_translations_from_api$/i',
		'Meta_Discovery' => '/^collect_discovered_meta|^get_product_attribute_runtime|^collect_core_persian|^collect_commerce_persian/i',
		'Facade' => '/^is_persisting|^is_admin_translation|^should_skip_translation|^begin_persisting|^end_persisting|^field_source_text_meaningfully|^get_supported_post|^get_skipped_meta|^is_translatable_meta|^is_custom_meta|^get_translatable_tax|^get_meta_key$|^get_form_field_name$|^map_meta_keys_to_form_fields$|^get_custom_meta_key$|^get_thumbnail_meta_key$|^get_field_source_hash_meta_key$|^get_term_source_hash_meta_key$|^get_term_meta_key$|^get_menu_title_meta_key$|^has_meaningful_translation$|^is_usable_storefront_translation$|^owns_translation_lock$|^acquire_translation_lock$|^release_translation_lock$|^retranslate_post$/i',
	);

	foreach ( $rules as $trait => $regex ) {
		if ( preg_match( $regex, $name ) ) {
			return $trait;
		}
	}

	return 'Core';
};

$bounds = find_class_bounds( $lines );
if ( null === $bounds ) {
	fwrite( STDERR, "Class bounds not found.\n" );
	exit( 1 );
}

$methods = parse_methods_in_class( $code, $lines, $bounds['open'] + 1, $bounds['close'] + 1 );
if ( empty( $methods ) ) {
	fwrite( STDERR, "No methods parsed.\n" );
	exit( 1 );
}

echo 'Parsed ' . count( $methods ) . " methods.\n";

$trait_methods = array();
$facade_chunks = array();

foreach ( $methods as $method ) {
	$bucket = $assign( $method['name'] );
	if ( 'Facade' === $bucket ) {
		$facade_chunks[] = $method;
		continue;
	}
	$trait_methods[ $bucket ][] = $method;
}

// Split Elementor job bucket into sub-traits.
$elementor_job_methods = $trait_methods['Elementor_Job'] ?? array();
unset( $trait_methods['Elementor_Job'] );

$elementor_job_buckets = array();
foreach ( $elementor_job_methods as $method ) {
	$elementor_job_buckets[ $assign_elementor_job( $method['name'] ) ][] = $method;
}

remove_path_tree( array( $traits_root, $legacy_traits ) );
mkdir( $traits_root, 0755, true );

$trait_imports = array();

foreach ( $trait_methods as $bucket_name => $chunks ) {
	if ( ! isset( $domain_map[ $bucket_name ] ) ) {
		fwrite( STDERR, "Unknown trait bucket: {$bucket_name}\n" );
		exit( 1 );
	}

	$meta      = $domain_map[ $bucket_name ];
	$dir       = $traits_root . '/' . $meta['path'];
	$file      = $dir . '/' . $meta['file'];
	$namespace = 'PolymartAI\\Translation\\Post_Translator\\Traits\\' . str_replace( '/', '\\', $meta['path'] );
	$skip      = 'Core' === $bucket_name ? $core_normalizer_methods : array();
	$body      = extract_method_lines( $lines, $chunks, $skip );

	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}

	if ( 'Core' === $bucket_name ) {
		$normalizer_delegates = <<<'PHP'
	private static function normalize_ai_translation_value( $value ) {
		return Text_Normalizer::normalize_ai_translation_value( $value );
	}

	private static function prepare_stored_meta_value( $value, $field_kind ) {
		return Text_Normalizer::prepare_stored_meta_value( $value, $field_kind );
	}

	private static function normalize_translation_plaintext( $value ) {
		return Text_Normalizer::normalize_translation_plaintext( $value );
	}

	private static function is_clean_target_language_translation( $value, $lang ) {
		return Text_Normalizer::is_clean_target_language_translation( $value, $lang );
	}

	private static function utf8_strlen( $text ) {
		return Text_Normalizer::utf8_strlen( $text );
	}

	private static function utf8_substr( $text, $start, $length ) {
		return Text_Normalizer::utf8_substr( $text, $start, $length );
	}

PHP;
		array_unshift( $body, $normalizer_delegates );
	}

	write_domain_trait( $file, $namespace, $meta['trait'], $bucket_name, $body );

	if ( ! in_array( $bucket_name, array( 'Elementor_Storage', 'Elementor_Chunking', 'Elementor_Walk' ), true ) ) {
		$trait_imports[] = $namespace . '\\' . $meta['trait'];
	}

	echo 'Wrote ' . $file . ' (' . count( $chunks ) . " methods)\n";
}

// Config traits.
$config_body     = extract_config_properties( $lines, $bounds['open'], $bounds['close'], $methods );
$config_sections = split_config_sections( $config_body );
$config_dir      = $traits_root . '/Config';
$config_ns       = 'PolymartAI\\Translation\\Post_Translator\\Traits\\Config';

if ( ! is_dir( $config_dir ) ) {
	mkdir( $config_dir, 0755, true );
}

write_domain_trait(
	$config_dir . '/trait-config-constants.php',
	$config_ns,
	'Trait_Config_Constants',
	'Config Constants',
	explode( "\n", $config_sections['constants'] ),
	false
);
write_domain_trait(
	$config_dir . '/trait-config-state.php',
	$config_ns,
	'Trait_Config_State',
	'Config State',
	explode( "\n", $config_sections['state'] ),
	false
);
write_composite_trait(
	$config_dir . '/trait-config.php',
	$config_ns,
	'Trait_Config',
	'Config',
	array(
		'Trait_Config_Constants',
		'Trait_Config_State',
	)
);
array_unshift( $trait_imports, $config_ns . '\\Trait_Config' );
echo "Wrote {$config_dir}/trait-config-constants.php\n";
echo "Wrote {$config_dir}/trait-config-state.php\n";
echo "Wrote {$config_dir}/trait-config.php\n";

// Elementor job sub-traits.
$job_dir = $traits_root . '/Elementor/Job';
$job_ns  = 'PolymartAI\\Translation\\Post_Translator\\Traits\\Elementor\\Job';
if ( ! is_dir( $job_dir ) ) {
	mkdir( $job_dir, 0755, true );
}

$job_trait_names = array();
foreach ( $elementor_job_map as $job_bucket => $job_meta ) {
	$job_chunks = $elementor_job_buckets[ $job_bucket ] ?? array();
	if ( empty( $job_chunks ) ) {
		continue;
	}

	$job_file = $job_dir . '/' . $job_meta['file'];
	write_domain_trait(
		$job_file,
		$job_ns,
		$job_meta['trait'],
		'Elementor ' . $job_bucket,
		extract_method_lines( $lines, $job_chunks )
	);
	$job_trait_names[] = $job_meta['trait'];
	echo 'Wrote ' . $job_file . ' (' . count( $job_chunks ) . " methods)\n";
}

write_composite_trait(
	$job_dir . '/trait-job.php',
	$job_ns,
	'Trait_Job',
	'Elementor Job',
	$job_trait_names
);
echo "Wrote {$job_dir}/trait-job.php\n";

// Elementor composite.
$elementor_dir = $traits_root . '/Elementor';
$elementor_ns  = 'PolymartAI\\Translation\\Post_Translator\\Traits\\Elementor';
write_composite_trait(
	$elementor_dir . '/trait-elementor.php',
	$elementor_ns,
	'Trait_Elementor',
	'Elementor',
	array(
		'Trait_Storage',
		'Trait_Chunking',
		'Job\\Trait_Job',
		'Trait_Walk',
	)
);
$trait_imports[] = $elementor_ns . '\\Trait_Elementor';
echo "Wrote {$elementor_dir}/trait-elementor.php\n";

sort( $trait_imports );

// Facade class — thin delegates to service classes.
$facade_body = array();
foreach ( $facade_chunks as $chunk ) {
	if ( in_array( $chunk['name'], $facade_delegate_methods, true ) || 'retranslate_post' === $chunk['name'] ) {
		if ( 'retranslate_post' === $chunk['name'] ) {
			for ( $l = $chunk['start']; $l <= $chunk['end']; $l++ ) {
				$facade_body[] = $lines[ $l ];
			}
			$facade_body[] = '';
		}
		continue;
	}

	for ( $l = $chunk['start']; $l <= $chunk['end']; $l++ ) {
		$facade_body[] = $lines[ $l ];
	}
	$facade_body[] = '';
}

$facade_body[] = <<<'PHP'
	public static function is_persisting_translations() {
		return Persistence_Guard::is_persisting_translations();
	}

	public static function is_admin_translation_save_request() {
		return Persistence_Guard::is_admin_translation_save_request();
	}

	public static function should_skip_translation_invalidation() {
		return Persistence_Guard::should_skip_translation_invalidation();
	}

	public static function begin_persisting_translations() {
		Persistence_Guard::begin_persisting_translations();
	}

	public static function end_persisting_translations() {
		Persistence_Guard::end_persisting_translations();
	}

	public static function field_source_text_meaningfully_changed( $before, $after ) {
		return Text_Normalizer::field_source_text_meaningfully_changed( $before, $after );
	}

	public static function get_supported_post_types() {
		return Meta_Keys::get_supported_post_types();
	}

	public static function get_skipped_meta_prefixes() {
		return Meta_Keys::get_skipped_meta_prefixes();
	}

	public static function is_translatable_meta_key( $meta_key, $lang = '' ) {
		return Meta_Keys::is_translatable_meta_key( $meta_key, $lang );
	}

	public static function is_custom_meta_key( $meta_key ) {
		return Meta_Keys::is_custom_meta_key( $meta_key );
	}

	public static function get_translatable_taxonomies() {
		return Meta_Keys::get_translatable_taxonomies();
	}

	public static function get_meta_key( $field, $lang ) {
		return Meta_Keys::get_meta_key( $field, $lang );
	}

	public static function get_form_field_name( $meta_key ) {
		return Meta_Keys::get_form_field_name( $meta_key );
	}

	public static function map_meta_keys_to_form_fields( array $fields ) {
		return Meta_Keys::map_meta_keys_to_form_fields( $fields );
	}

	public static function get_custom_meta_key( $source_key, $lang ) {
		return Meta_Keys::get_custom_meta_key( $source_key, $lang );
	}

	public static function get_thumbnail_meta_key( $lang ) {
		return Meta_Keys::get_thumbnail_meta_key( $lang );
	}

	public static function get_field_source_hash_meta_key( $source_key, $lang ) {
		return Meta_Keys::get_field_source_hash_meta_key( $source_key, $lang );
	}

	public static function get_term_source_hash_meta_key( $field, $lang ) {
		return Meta_Keys::get_term_source_hash_meta_key( $field, $lang );
	}

	public static function has_meaningful_translation( $value ) {
		return Text_Normalizer::has_meaningful_translation( $value );
	}

	public static function is_usable_storefront_translation( $value, $lang ) {
		return Text_Normalizer::is_usable_storefront_translation( $value, $lang );
	}

	public static function get_term_meta_key( $field, $lang ) {
		return Meta_Keys::get_term_meta_key( $field, $lang );
	}

	public static function get_menu_title_meta_key( $lang ) {
		return Meta_Keys::get_menu_title_meta_key( $lang );
	}

	public static function owns_translation_lock( $post_id, $lang ) {
		return Translation_Lock::owns_translation_lock( $post_id, $lang );
	}

	public static function acquire_translation_lock( $post_id, $lang ) {
		return Translation_Lock::acquire_translation_lock( $post_id, $lang );
	}

	public static function release_translation_lock( $post_id, $lang, $force = false ) {
		Translation_Lock::release_translation_lock( $post_id, $lang, $force );
	}

PHP;

$use_imports = array(
	'use PolymartAI\Translation\Post_Translator\Meta_Keys;',
	'use PolymartAI\Translation\Post_Translator\Persistence_Guard;',
	'use PolymartAI\Translation\Post_Translator\Text_Normalizer;',
	'use PolymartAI\Translation\Post_Translator\Translation_Lock;',
);
$use_trait_lines = array();
foreach ( $trait_imports as $trait_fqcn ) {
	$use_imports[]     = 'use ' . $trait_fqcn . ';';
	$parts             = explode( '\\', $trait_fqcn );
	$use_trait_lines[] = "\tuse " . end( $parts ) . ';';
}

$slim = <<<PHP
<?php
/**
 * Post translation facade — implementation lives in Post_Translator/Traits/.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation;

{USE_IMPORTS}

require_once __DIR__ . '/Post_Translator/trait-loader.php';

defined( 'ABSPATH' ) || exit;

/**
 * Class Post_Translator
 */
final class Post_Translator {

{USE_TRAITS}


{FACADE_BODY}
}

PHP;

$slim = str_replace( '{USE_IMPORTS}', implode( "\n", $use_imports ), $slim );
$slim = str_replace( '{USE_TRAITS}', implode( "\n", $use_trait_lines ), $slim );
$slim = str_replace( '{FACADE_BODY}', implode( "\n", $facade_body ), $slim );
file_put_contents( $output_file, $slim );

echo 'Wrote ' . $output_file . ' (' . count( file( $output_file ) ) . " lines)\n";

// Syntax check all traits.
$failed = false;
foreach ( collect_trait_php_files( $traits_root ) as $file ) {
	exec( 'php -l ' . escapeshellarg( $file ), $out, $code );
	if ( 0 !== $code ) {
		$failed = true;
		echo implode( "\n", $out ) . "\n";
	}
}
exec( 'php -l ' . escapeshellarg( $output_file ), $out, $code );
if ( 0 !== $code ) {
	$failed = true;
	echo implode( "\n", $out ) . "\n";
}

exit( $failed ? 1 : 0 );
