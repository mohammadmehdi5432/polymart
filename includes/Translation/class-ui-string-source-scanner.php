<?php
/**
 * Extract gettext strings directly from plugin PHP/JS source files.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Class UI_String_Source_Scanner
 */
final class UI_String_Source_Scanner {

	/**
	 * Source file extensions to scan.
	 *
	 * @var string[]
	 */
	private static $source_extensions = array( 'php', 'js', 'jsx', 'ts', 'tsx' );

	/**
	 * Directory names skipped during recursive scans.
	 *
	 * @var string[]
	 */
	private static $skip_directories = array(
		'admin',
		'node_modules',
		'vendor',
		'build',
		'dist',
		'.git',
		'.svn',
		'tests',
		'test',
		'coverage',
		'packages',
		'bin',
		'config',
		'emails',
		'email',
		'legacy',
		'sample-data',
		'importers',
		'cli',
		'client',
	);

	/**
	 * Regex fragment for a quoted PHP/JS string literal.
	 */
	const QUOTED_STRING = '(?:\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")';

	/**
	 * Regex fragment for text-domain argument: quoted literal OR PHP constant/variable.
	 */
	const DOMAIN_ARG = '(?:[\'"](?P<domain_literal>[^\'"]+)[\'"]|(?P<domain_constant>\$?[a-zA-Z_][a-zA-Z0-9_$]*(?:::[a-zA-Z_][a-zA-Z0-9_$]*)*))';

	/**
	 * Scan a plugin directory for translatable strings in source code.
	 *
	 * @param string $plugin_dir Absolute plugin directory.
	 * @param string $plugin_slug Plugin folder slug.
	 * @return array{entries: array<int, array<string, string>>, files_scanned: int, files: string[]}
	 */
	public static function scan_plugin_directory( $plugin_dir, $plugin_slug ) {
		$plugin_dir  = self::resolve_existing_directory( $plugin_dir );
		$plugin_slug = (string) $plugin_slug;
		$domains     = UI_String_Registry::get_allowed_domains();
		$entries     = array();
		$files       = array();

		if ( '' === $plugin_dir || ! is_dir( $plugin_dir ) || empty( $domains ) ) {
			return array(
				'entries'       => array(),
				'files_scanned' => 0,
				'files'         => array(),
			);
		}

		foreach ( self::collect_source_files( $plugin_dir ) as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin scan.
			$content = file_get_contents( $path );

			if ( false === $content || '' === $content ) {
				continue;
			}

			$file_entries = self::extract_strings_from_source( $content, $domains );

			if ( empty( $file_entries ) ) {
				continue;
			}

			$files[] = $path;

			$relative_reference = ltrim(
				str_replace( wp_normalize_path( $plugin_dir ), '', wp_normalize_path( $path ) ),
				'/'
			);

			foreach ( $file_entries as $entry ) {
				$entry['references'] = array( $relative_reference );
				$entries[]           = $entry;
			}
		}

		unset( $plugin_slug );

		return array(
			'entries'       => $entries,
			'files_scanned' => count( $files ),
			'files'         => $files,
		);
	}

	/**
	 * Extract i18n strings from file contents for allowed text domains.
	 *
	 * Accepts quoted domain literals and PHP constants/variables (assumed to map to allowed domains).
	 *
	 * @param string   $content File contents.
	 * @param string[] $domains Allowed text domains.
	 * @return array<int, array<string, string>>
	 */
	public static function extract_strings_from_source( $content, array $domains ) {
		$analysis = self::analyze_source_content( $content, $domains, true );

		return $analysis['entries'];
	}

	/**
	 * Analyze source content and return detailed match metadata (for sandbox UI).
	 *
	 * @param string   $content       File contents.
	 * @param string[] $domains       Allowed text domains.
	 * @param bool     $apply_filter  When false, report all matches with skip reasons but do not build entries.
	 * @return array<string, mixed>
	 */
	public static function analyze_source_content( $content, array $domains, $apply_filter = true ) {
		$content = (string) $content;
		$domains = array_values(
			array_filter(
				array_map( 'strval', $domains ),
				static function ( $domain ) {
					return '' !== trim( $domain );
				}
			)
		);

		$default_domain = isset( $domains[0] ) ? $domains[0] : 'polymart-ai';
		$domain_lookup  = array_fill_keys( array_map( 'strtolower', $domains ), true );
		$matches        = array();
		$entries        = array();
		$seen           = array();
		$by_function    = array();

		foreach ( self::find_all_i18n_calls( $content ) as $call ) {
			$function = $call['function'];

			if ( ! isset( $by_function[ $function ] ) ) {
				$by_function[ $function ] = 0;
			}

			++$by_function[ $function ];

			$domain_info = self::resolve_domain_argument( $call['domain_literal'], $call['domain_constant'], $domains, $default_domain, $domain_lookup );
			$status      = 'accepted';
			$skip_reason = '';

			if ( '' === trim( $call['msgid'] ) ) {
				$status      = 'skipped';
				$skip_reason = 'empty_msgid';
			} elseif ( 'missing' === $domain_info['type'] ) {
				$status      = 'skipped';
				$skip_reason = 'missing_domain_arg';
			} elseif ( 'literal' === $domain_info['type'] && ! $domain_info['allowed'] ) {
				$status      = 'skipped';
				$skip_reason = 'domain_literal_not_allowed:' . $domain_info['raw'];
			}

			$match_row = array(
				'function'         => $function,
				'msgid'            => $call['msgid'],
				'context'          => $call['context'],
				'msgid_plural'     => $call['msgid_plural'],
				'domain_arg'       => $domain_info['raw'],
				'domain_type'      => $domain_info['type'],
				'domain_resolved'  => $domain_info['resolved'],
				'status'           => $status,
				'skip_reason'      => $skip_reason,
				'line'             => $call['line'],
				'snippet'          => $call['snippet'],
			);

			$matches[] = $match_row;

			if ( ! $apply_filter || 'accepted' !== $status ) {
				continue;
			}

			$resolved_domain = $domain_info['resolved'];
			$key             = $resolved_domain . '|' . $call['context'] . '|' . $call['msgid'];

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$entries[]    = array(
				'msgid'        => $call['msgid'],
				'context'      => $call['context'],
				'msgid_plural' => $call['msgid_plural'],
				'domain'       => $resolved_domain,
			);

			if ( '' !== trim( $call['msgid_plural'] ) && $call['msgid_plural'] !== $call['msgid'] ) {
				$plural_key = $resolved_domain . '|' . $call['msgid_plural'];

				if ( ! isset( $seen[ $plural_key ] ) ) {
					$seen[ $plural_key ] = true;
					$entries[]           = array(
						'msgid'        => $call['msgid_plural'],
						'context'      => '',
						'msgid_plural' => $call['msgid_plural'],
						'domain'       => $resolved_domain,
					);
				}
			}
		}

		$accepted = 0;
		$skipped  = 0;

		foreach ( $matches as $match ) {
			if ( 'accepted' === $match['status'] ) {
				++$accepted;
			} else {
				++$skipped;
			}
		}

		return array(
			'allowed_domains' => $domains,
			'default_domain'  => $default_domain,
			'entries'         => $entries,
			'matches'         => $matches,
			'summary'         => array(
				'total_calls_found' => count( $matches ),
				'accepted'          => $accepted,
				'skipped'           => $skipped,
				'entries_unique'    => count( $entries ),
				'by_function'       => $by_function,
			),
		);
	}

	/**
	 * Analyze a single plugin source file (sandbox tester).
	 *
	 * @param string $input_path Relative or absolute path under wp-content/plugins.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function analyze_file( $input_path ) {
		$probe = self::resolve_plugin_file_path( $input_path );

		$response = array(
			'input_path'               => $probe['input_path'],
			'resolved_absolute_path'   => $probe['resolved_absolute_path'],
			'resolved_path'            => $probe['resolved_absolute_path'],
			'candidates_checked'       => $probe['candidates_checked'],
			'plugins_root'             => $probe['plugins_root'],
			'abspath'                  => $probe['abspath'],
			'wp_plugin_dir'            => $probe['wp_plugin_dir'],
			'readable'                 => false,
			'file_size'                => 0,
			'file_size_human'          => '0 B',
			'error'                    => '',
			'allowed_domains'          => UI_String_Registry::get_allowed_domains(),
			'summary'                  => array(
				'total_calls_found' => 0,
				'accepted'          => 0,
				'skipped'           => 0,
				'entries_unique'    => 0,
				'by_function'       => array(),
			),
			'matches'                  => array(),
			'accepted_msgids'          => array(),
		);

		if ( ! $probe['exists'] ) {
			$response['error'] = __( 'فایل در wp-content/plugins پیدا نشد.', 'polymart-ai' );

			return $response;
		}

		$resolved = $probe['resolved_absolute_path'];
		$extension = strtolower( pathinfo( $resolved, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, self::$source_extensions, true ) ) {
			$response['error'] = __( 'فقط فایل‌های PHP/JS قابل تحلیل هستند.', 'polymart-ai' );

			return $response;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- sandbox read.
		$content = file_get_contents( $resolved );

		if ( false === $content ) {
			$response['error'] = __( 'فایل پیدا شد اما قابل خواندن نیست.', 'polymart-ai' );

			return $response;
		}

		$domains  = UI_String_Registry::get_allowed_domains();
		$filtered = self::analyze_source_content( $content, $domains, true );
		$raw      = self::analyze_source_content( $content, $domains, false );

		$response['readable']        = true;
		$response['file_size']       = (int) filesize( $resolved );
		$response['file_size_human'] = size_format( $response['file_size'] );
		$response['allowed_domains'] = $domains;
		$response['summary']         = array(
			'total_calls_found' => $raw['summary']['total_calls_found'],
			'accepted'          => $filtered['summary']['accepted'],
			'skipped'           => $filtered['summary']['skipped'],
			'entries_unique'    => $filtered['summary']['entries_unique'],
			'by_function'       => $raw['summary']['by_function'],
		);
		$response['matches']         = $raw['matches'];
		$response['accepted_msgids'] = array_values(
			array_map(
				static function ( $entry ) {
					return $entry['msgid'];
				},
				$filtered['entries']
			)
		);

		return $response;
	}

	/**
	 * Resolve a sandbox file path and ensure it stays inside wp-content/plugins.
	 *
	 * @param string $input User-supplied path.
	 * @return string|\WP_Error
	 */
	public static function resolve_sandbox_file_path( $input ) {
		$probe = self::resolve_plugin_file_path( $input );

		if ( ! $probe['exists'] ) {
			return new \WP_Error(
				'polymart_ai_sandbox_not_found',
				__( 'فایل در wp-content/plugins پیدا نشد.', 'polymart-ai' ),
				array(
					'status'               => 404,
					'input_path'           => $probe['input_path'],
					'resolved_absolute_path' => $probe['resolved_absolute_path'],
					'candidates_checked'   => $probe['candidates_checked'],
					'plugins_root'         => $probe['plugins_root'],
					'abspath'              => $probe['abspath'],
					'wp_plugin_dir'        => $probe['wp_plugin_dir'],
				)
			);
		}

		return $probe['resolved_absolute_path'];
	}

	/**
	 * Normalize a relative or absolute path to an absolute filesystem path.
	 *
	 * Rules:
	 * - wp-content/, wp-admin/, wp-includes/ → prefixed with ABSPATH
	 * - plugins/… → prefixed with WP_CONTENT_DIR
	 * - wp-content/plugins/{slug}/… → also resolved via WP_PLUGIN_DIR/{slug}/…
	 * - otherwise → prefixed with WP_PLUGIN_DIR (plugin-relative path)
	 *
	 * @param string $input Raw path input.
	 * @return string
	 */
	public static function normalize_to_absolute_path( $input ) {
		$input = self::sanitize_path_input( $input );

		if ( self::is_absolute_path( $input ) ) {
			return wp_normalize_path( $input );
		}

		$relative = ltrim( $input, '/' );

		if ( preg_match( '#^wp-(content|admin|includes)(/|$)#', $relative ) ) {
			return wp_normalize_path( untrailingslashit( ABSPATH ) . '/' . $relative );
		}

		if ( preg_match( '#^plugins(/|$)#', $relative ) ) {
			return wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) . '/' . $relative );
		}

		return wp_normalize_path( untrailingslashit( WP_PLUGIN_DIR ) . '/' . $relative );
	}

	/**
	 * Resolve an existing directory to a normalized absolute path.
	 *
	 * @param string $path Directory path (relative or absolute).
	 * @return string Empty string when not found.
	 */
	public static function resolve_existing_directory( $path ) {
		$path = trim( wp_normalize_path( (string) $path ) );

		if ( '' === $path ) {
			return '';
		}

		foreach ( self::build_path_candidates( $path, false ) as $candidate ) {
			if ( ! is_dir( $candidate ) ) {
				continue;
			}

			$real = function_exists( 'realpath' ) ? realpath( $candidate ) : false;

			return wp_normalize_path( false !== $real ? $real : $candidate );
		}

		return '';
	}

	/**
	 * Probe a plugin file path and return resolution diagnostics.
	 *
	 * @param string $input User-supplied path.
	 * @return array<string, mixed>
	 */
	public static function resolve_plugin_file_path( $input ) {
		$input_path = trim( (string) $input );
		$candidates = self::build_path_candidates( $input_path, true );
		$plugins_root = self::get_plugins_root();
		$resolved     = '';

		foreach ( $candidates as $candidate ) {
			$candidate = wp_normalize_path( $candidate );

			if ( ! self::path_is_under_plugins( $candidate, $plugins_root ) ) {
				continue;
			}

			$path_to_test = $candidate;

			if ( function_exists( 'realpath' ) ) {
				$real = realpath( $candidate );

				if ( false !== $real ) {
					$path_to_test = wp_normalize_path( $real );
				}
			}

			if ( ! self::path_is_under_plugins( $path_to_test, $plugins_root ) ) {
				continue;
			}

			if ( ! is_file( $path_to_test ) ) {
				continue;
			}

			$resolved = $path_to_test;
			break;
		}

		return array(
			'input_path'             => $input_path,
			'resolved_absolute_path' => $resolved,
			'candidates_checked'     => $candidates,
			'exists'                 => '' !== $resolved,
			'plugins_root'           => $plugins_root,
			'abspath'                => wp_normalize_path( ABSPATH ),
			'wp_plugin_dir'          => wp_normalize_path( WP_PLUGIN_DIR ),
		);
	}

	/**
	 * Build ordered absolute path candidates for a user or scanner input.
	 *
	 * @param string $input      Raw path.
	 * @param bool   $for_file   When true, include file-specific fallbacks.
	 * @return string[]
	 */
	private static function build_path_candidates( $input, $for_file = true ) {
		$input       = self::sanitize_path_input( $input );
		$candidates  = array();
		$relative    = ltrim( $input, '/' );

		if ( self::is_absolute_path( $input ) ) {
			$candidates[] = wp_normalize_path( $input );
		}

		$candidates[] = self::normalize_to_absolute_path( $input );

		if ( preg_match( '#^wp-content/plugins/(.+)$#', $relative, $matches ) ) {
			$candidates[] = wp_normalize_path( untrailingslashit( WP_PLUGIN_DIR ) . '/' . $matches[1] );
		}

		if ( preg_match( '#^wp-content/(.+)$#', $relative, $matches ) ) {
			$candidates[] = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) . '/' . $matches[1] );
		}

		$candidates[] = wp_normalize_path( untrailingslashit( ABSPATH ) . '/' . $relative );
		$candidates[] = wp_normalize_path( untrailingslashit( WP_PLUGIN_DIR ) . '/' . $relative );
		$candidates[] = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) . '/plugins/' . $relative );

		$unique = array();

		foreach ( $candidates as $candidate ) {
			$candidate = wp_normalize_path( $candidate );

			if ( '' === $candidate || isset( $unique[ $candidate ] ) ) {
				continue;
			}

			$unique[ $candidate ] = true;
		}

		return array_keys( $unique );
	}

	/**
	 * Sanitize raw path input.
	 *
	 * @param string $input Raw path.
	 * @return string
	 */
	private static function sanitize_path_input( $input ) {
		$input = trim( (string) $input );
		$input = str_replace( array( "\0" ), '', $input );
		$input = wp_normalize_path( $input );
		$input = preg_replace( '#(/\.\./|/\./)#', '/', $input );
		$input = preg_replace( '#^\./#', '', $input );

		return is_string( $input ) ? $input : '';
	}

	/**
	 * Whether a path is absolute on the current OS.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function is_absolute_path( $path ) {
		$path = wp_normalize_path( (string) $path );

		if ( '' === $path ) {
			return false;
		}

		if ( '/' === $path[0] ) {
			return true;
		}

		return (bool) preg_match( '#^[A-Za-z]:/#', $path );
	}

	/**
	 * Normalized plugins root (prefers realpath when available).
	 *
	 * @return string
	 */
	private static function get_plugins_root() {
		$root = wp_normalize_path( WP_PLUGIN_DIR );
		$real = function_exists( 'realpath' ) ? realpath( $root ) : false;

		return wp_normalize_path( false !== $real ? $real : $root );
	}

	/**
	 * Case-insensitive check that a path is under wp-content/plugins.
	 *
	 * @param string $path         Path to test.
	 * @param string $plugins_root Normalized plugins root.
	 * @return bool
	 */
	private static function path_is_under_plugins( $path, $plugins_root ) {
		$path         = wp_normalize_path( (string) $path );
		$plugins_root = wp_normalize_path( (string) $plugins_root );

		if ( '' === $path || '' === $plugins_root ) {
			return false;
		}

		$root_len = strlen( $plugins_root );

		if ( strlen( $path ) < $root_len ) {
			return false;
		}

		if ( 0 !== strncasecmp( $path, $plugins_root, $root_len ) ) {
			return false;
		}

		if ( strlen( $path ) === $root_len ) {
			return true;
		}

		$next = $path[ $root_len ];

		return '/' === $next || '\\' === $next;
	}

	/**
	 * Find every i18n call in source, regardless of domain filter.
	 *
	 * @param string $content Source code.
	 * @return array<int, array<string, mixed>>
	 */
	private static function find_all_i18n_calls( $content ) {
		$calls = array();

		foreach ( self::match_simple_calls( $content ) as $item ) {
			$calls[] = $item;
		}

		foreach ( self::match_context_calls( $content ) as $item ) {
			$calls[] = $item;
		}

		foreach ( self::match_plural_calls( $content ) as $item ) {
			$calls[] = $item;
		}

		usort(
			$calls,
			static function ( $a, $b ) {
				$a_line = isset( $a['line'] ) ? (int) $a['line'] : 0;
				$b_line = isset( $b['line'] ) ? (int) $b['line'] : 0;

				if ( $a_line === $b_line ) {
					return 0;
				}

				return $a_line < $b_line ? -1 : 1;
			}
		);

		return $calls;
	}

	/**
	 * @param string $content Source code.
	 * @return array<int, array<string, mixed>>
	 */
	private static function match_simple_calls( $content ) {
		$calls   = array();
		$pattern = '/(?P<func>__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e|wp\.i18n\.__)\s*\(\s*'
			. self::QUOTED_STRING . '\s*,\s*'
			. self::DOMAIN_ARG . '\s*\)/s';

		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $calls;
		}

		foreach ( $matches[0] as $index => $full_match ) {
			$snippet = $full_match[0];
			$offset  = (int) $full_match[1];
			$msgid   = self::extract_first_string_argument( $snippet );

			$calls[] = array(
				'function'          => (string) ( isset( $matches['func'][ $index ][0] ) ? $matches['func'][ $index ][0] : '__' ),
				'msgid'             => null !== $msgid ? $msgid : '',
				'context'           => '',
				'msgid_plural'      => '',
				'domain_literal'    => (string) ( isset( $matches['domain_literal'][ $index ][0] ) ? $matches['domain_literal'][ $index ][0] : '' ),
				'domain_constant'   => (string) ( isset( $matches['domain_constant'][ $index ][0] ) ? $matches['domain_constant'][ $index ][0] : '' ),
				'line'              => self::line_number_at_offset( $content, $offset ),
				'snippet'           => self::trim_snippet( $snippet ),
			);
		}

		return $calls;
	}

	/**
	 * @param string $content Source code.
	 * @return array<int, array<string, mixed>>
	 */
	private static function match_context_calls( $content ) {
		$calls   = array();
		$pattern = '/(?P<func>_x|wp\.i18n\._x)\s*\(\s*'
			. self::QUOTED_STRING . '\s*,\s*'
			. self::QUOTED_STRING . '\s*,\s*'
			. self::DOMAIN_ARG . '\s*\)/s';

		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $calls;
		}

		foreach ( $matches[0] as $index => $full_match ) {
			$snippet = $full_match[0];
			$offset  = (int) $full_match[1];
			$parts   = self::extract_string_arguments( $snippet );

			$calls[] = array(
				'function'          => (string) ( isset( $matches['func'][ $index ][0] ) ? $matches['func'][ $index ][0] : '_x' ),
				'msgid'             => isset( $parts[0] ) ? $parts[0] : '',
				'context'           => isset( $parts[1] ) ? $parts[1] : '',
				'msgid_plural'      => '',
				'domain_literal'    => (string) ( isset( $matches['domain_literal'][ $index ][0] ) ? $matches['domain_literal'][ $index ][0] : '' ),
				'domain_constant'   => (string) ( isset( $matches['domain_constant'][ $index ][0] ) ? $matches['domain_constant'][ $index ][0] : '' ),
				'line'              => self::line_number_at_offset( $content, $offset ),
				'snippet'           => self::trim_snippet( $snippet ),
			);
		}

		return $calls;
	}

	/**
	 * @param string $content Source code.
	 * @return array<int, array<string, mixed>>
	 */
	private static function match_plural_calls( $content ) {
		$calls   = array();
		$pattern = '/(?P<func>_n|wp\.i18n\._n)\s*\(\s*'
			. self::QUOTED_STRING . '\s*,\s*'
			. self::QUOTED_STRING . '\s*,\s*[^,]+,\s*'
			. self::DOMAIN_ARG . '\s*\)/s';

		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $calls;
		}

		foreach ( $matches[0] as $index => $full_match ) {
			$snippet = $full_match[0];
			$offset  = (int) $full_match[1];
			$parts   = self::extract_string_arguments( $snippet );

			$calls[] = array(
				'function'          => (string) ( isset( $matches['func'][ $index ][0] ) ? $matches['func'][ $index ][0] : '_n' ),
				'msgid'             => isset( $parts[0] ) ? $parts[0] : '',
				'context'           => '',
				'msgid_plural'      => isset( $parts[1] ) ? $parts[1] : '',
				'domain_literal'    => (string) ( isset( $matches['domain_literal'][ $index ][0] ) ? $matches['domain_literal'][ $index ][0] : '' ),
				'domain_constant'   => (string) ( isset( $matches['domain_constant'][ $index ][0] ) ? $matches['domain_constant'][ $index ][0] : '' ),
				'line'              => self::line_number_at_offset( $content, $offset ),
				'snippet'           => self::trim_snippet( $snippet ),
			);
		}

		return $calls;
	}

	/**
	 * Resolve domain argument to a stored domain slug.
	 *
	 * @param string   $literal        Quoted domain capture.
	 * @param string   $constant       Constant/variable capture.
	 * @param string[] $domains        Allowed domains.
	 * @param string   $default_domain Fallback domain for constants.
	 * @param array<string, bool> $domain_lookup Lowercase allowed-domain map.
	 * @return array{type: string, raw: string, resolved: string, allowed: bool}
	 */
	private static function resolve_domain_argument( $literal, $constant, array $domains, $default_domain, array $domain_lookup ) {
		$literal  = trim( (string) $literal );
		$constant = trim( (string) $constant );

		if ( '' !== $literal ) {
			$allowed = isset( $domain_lookup[ strtolower( $literal ) ] );

			return array(
				'type'     => 'literal',
				'raw'      => $literal,
				'resolved' => $allowed ? $literal : $default_domain,
				'allowed'  => $allowed,
			);
		}

		if ( '' !== $constant ) {
			return array(
				'type'     => 'constant',
				'raw'      => $constant,
				'resolved' => $default_domain,
				'allowed'  => true,
			);
		}

		return array(
			'type'     => 'missing',
			'raw'      => '',
			'resolved' => $default_domain,
			'allowed'  => false,
		);
	}

	/**
	 * Collect PHP/JS source files under a plugin directory.
	 *
	 * @param string $plugin_dir Plugin root directory.
	 * @return string[]
	 */
	public static function collect_source_files( $plugin_dir ) {
		$plugin_dir = wp_normalize_path( (string) $plugin_dir );
		$files      = array();
		$seen       = array();

		if ( ! is_dir( $plugin_dir ) ) {
			return $files;
		}

		if ( ! class_exists( '\RecursiveIteratorIterator', false ) ) {
			return $files;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $plugin_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file_info ) {
				if ( ! $file_info instanceof \SplFileInfo || ! $file_info->isFile() ) {
					continue;
				}

				$path = wp_normalize_path( $file_info->getPathname() );

				if ( self::should_skip_path( $path, $plugin_dir ) ) {
					continue;
				}

				$extension = strtolower( $file_info->getExtension() );

				if ( ! in_array( $extension, self::$source_extensions, true ) ) {
					continue;
				}

				$key = strtolower( $path );

				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
				$files[]      = $path;
			}
		} catch ( \Exception $exception ) {
			return $files;
		}

		sort( $files, SORT_STRING );

		return $files;
	}

	/**
	 * Pull the first quoted string argument from a function call snippet.
	 *
	 * @param string $snippet Function call text.
	 * @return string|null
	 */
	private static function extract_first_string_argument( $snippet ) {
		$parts = self::extract_string_arguments( $snippet );

		return isset( $parts[0] ) ? $parts[0] : null;
	}

	/**
	 * Pull all quoted string arguments from a function call snippet.
	 *
	 * @param string $snippet Function call text.
	 * @return string[]
	 */
	private static function extract_string_arguments( $snippet ) {
		$strings = array();

		if ( ! preg_match_all( '/' . self::QUOTED_STRING . '/s', (string) $snippet, $matches ) ) {
			return $strings;
		}

		foreach ( $matches[0] as $literal ) {
			if ( ! is_string( $literal ) || strlen( $literal ) < 2 ) {
				continue;
			}

			$strings[] = self::decode_quoted_string( $literal );
		}

		return $strings;
	}

	/**
	 * Decode a PHP/JS quoted string literal.
	 *
	 * @param string $literal Quoted literal.
	 * @return string
	 */
	private static function decode_quoted_string( $literal ) {
		$literal = (string) $literal;

		if ( strlen( $literal ) >= 2 ) {
			$literal = substr( $literal, 1, -1 );
		}

		return stripcslashes( $literal );
	}

	/**
	 * @param string $content Full file contents.
	 * @param int    $offset  Byte offset of match.
	 * @return int
	 */
	private static function line_number_at_offset( $content, $offset ) {
		if ( $offset <= 0 ) {
			return 1;
		}

		return substr_count( substr( $content, 0, $offset ), "\n" ) + 1;
	}

	/**
	 * @param string $snippet Matched call snippet.
	 * @return string
	 */
	private static function trim_snippet( $snippet ) {
		$snippet = preg_replace( '/\s+/u', ' ', trim( (string) $snippet ) );

		if ( is_string( $snippet ) && strlen( $snippet ) > 180 ) {
			return substr( $snippet, 0, 177 ) . '...';
		}

		return (string) $snippet;
	}

	/**
	 * Whether a file path should be skipped.
	 *
	 * @param string $path       Absolute file path.
	 * @param string $plugin_dir Plugin root directory.
	 * @return bool
	 */
	private static function should_skip_path( $path, $plugin_dir ) {
		$path       = wp_normalize_path( $path );
		$plugin_dir = wp_normalize_path( $plugin_dir );
		$relative   = ltrim( substr( $path, strlen( $plugin_dir ) ), '/' );
		$segments   = explode( '/', $relative );

		foreach ( $segments as $segment ) {
			if ( in_array( strtolower( $segment ), self::$skip_directories, true ) ) {
				return true;
			}
		}

		if ( in_array( 'languages', $segments, true ) || in_array( 'lang', $segments, true ) ) {
			return true;
		}

		$relative_lower = strtolower( $relative );

		if ( preg_match( '#(^|/)(includes/admin|assets/client/admin|src/admin|assets/js/admin)(/|$)#', $relative_lower ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Scan companion plugin sources: known storefront subtrees, bootstrap PHP, then wider fallback.
	 *
	 * Most custom plugins keep __()/esc_html__() in includes/ or the main plugin file — not only
	 * public/frontend/ folders. When the limited pass finds nothing, fall back to includes/inc/src.
	 *
	 * @param string $root_dir Absolute plugin directory.
	 * @param string $slug     Plugin folder slug.
	 * @return array{entries: array<int, array<string, mixed>>, files_scanned: int, files: string[], scan_mode: string}
	 */
	public static function scan_companion_plugin_sources( $root_dir, $slug ) {
		$limited = self::scan_limited_directories(
			$root_dir,
			$slug,
			UI_String_Storefront_Filter::companion_source_subdirs()
		);

		$bootstrap = self::scan_root_bootstrap_files( $root_dir, $slug );
		$entries   = self::merge_source_scan_entries(
			(array) ( $limited['entries'] ?? array() ),
			(array) ( $bootstrap['entries'] ?? array() )
		);
		$files     = array_values(
			array_unique(
				array_merge(
					(array) ( $limited['files'] ?? array() ),
					(array) ( $bootstrap['files'] ?? array() )
				)
			)
		);
		$scan_mode = 'companion_subdirs';

		if ( empty( $entries ) ) {
			$fallback = self::scan_limited_directories(
				$root_dir,
				$slug,
				array( 'includes', 'inc', 'src', 'elementor', 'assets' )
			);
			$entries  = (array) ( $fallback['entries'] ?? array() );
			$files    = array_values(
				array_unique(
					array_merge(
						$files,
						(array) ( $fallback['files'] ?? array() )
					)
				)
			);
			$scan_mode = empty( $entries ) ? 'companion_subdirs' : 'companion_fallback';
		}

		return array(
			'entries'       => $entries,
			'files_scanned' => count( $files ),
			'files'         => $files,
			'scan_mode'     => $scan_mode,
		);
	}

	/**
	 * Scan top-level plugin PHP bootstrap files (main plugin file and root helpers).
	 *
	 * @param string $root_dir Absolute plugin directory.
	 * @param string $slug     Plugin folder slug.
	 * @return array{entries: array<int, array<string, mixed>>, files: string[]}
	 */
	public static function scan_root_bootstrap_files( $root_dir, $slug ) {
		$root_dir = self::resolve_existing_directory( $root_dir );
		$entries  = array();
		$files    = array();
		$domains  = UI_String_Registry::get_allowed_domains();

		if ( '' === $root_dir || ! is_dir( $root_dir ) || empty( $domains ) ) {
			return array(
				'entries' => array(),
				'files'   => array(),
			);
		}

		$candidates = (array) glob( wp_normalize_path( $root_dir . '/*.php' ) );

		foreach ( $candidates as $path ) {
			if ( ! is_string( $path ) || ! is_readable( $path ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin scan.
			$content = file_get_contents( $path );

			if ( false === $content || '' === $content ) {
				continue;
			}

			$file_entries = self::extract_strings_from_source( $content, $domains );

			if ( empty( $file_entries ) ) {
				continue;
			}

			$files[]  = $path;
			$basename = wp_basename( $path );

			foreach ( $file_entries as $entry ) {
				$entry['references'] = array( $basename );
				$entries[]           = $entry;
			}
		}

		unset( $slug );

		return array(
			'entries' => $entries,
			'files'   => $files,
		);
	}

	/**
	 * Merge source scan entry lists without duplicating msgid+context pairs.
	 *
	 * @param array<int, array<string, mixed>> $primary  Primary entries.
	 * @param array<int, array<string, mixed>> $secondary Secondary entries.
	 * @return array<int, array<string, mixed>>
	 */
	private static function merge_source_scan_entries( array $primary, array $secondary ) {
		if ( empty( $secondary ) ) {
			return $primary;
		}

		$seen   = array();
		$merged = array();

		foreach ( array_merge( $primary, $secondary ) as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['msgid'] ) ) {
				continue;
			}

			$key = md5( (string) ( $entry['context'] ?? '' ) . '|' . (string) $entry['msgid'] );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$merged[]     = $entry;
		}

		return $merged;
	}

	/**
	 * Scan only selected subdirectories under a plugin/theme root.
	 *
	 * @param string   $root_dir  Absolute root directory.
	 * @param string   $slug      Slug label for debug.
	 * @param string[] $subdirs   Relative subdirectory names.
	 * @return array{entries: array<int, array<string, mixed>>, files_scanned: int, files: string[]}
	 */
	public static function scan_limited_directories( $root_dir, $slug, array $subdirs ) {
		$root_dir = self::resolve_existing_directory( $root_dir );
		$entries  = array();
		$files    = array();

		if ( '' === $root_dir || ! is_dir( $root_dir ) ) {
			return array(
				'entries'       => array(),
				'files_scanned' => 0,
				'files'         => array(),
			);
		}

		foreach ( $subdirs as $subdir ) {
			$subdir = trim( str_replace( array( '..', '\\' ), '', (string) $subdir ), '/' );

			if ( '' === $subdir ) {
				continue;
			}

			$scan = self::scan_plugin_directory(
				wp_normalize_path( $root_dir . '/' . $subdir ),
				$slug
			);

			if ( empty( $scan['entries'] ) ) {
				continue;
			}

			foreach ( (array) $scan['files'] as $path ) {
				$files[] = $path;
			}

			foreach ( $scan['entries'] as $entry ) {
				$entry['references'] = array(
					$subdir . '/' . ltrim( (string) ( $entry['references'][0] ?? '' ), '/' ),
				);
				$entries[] = $entry;
			}
		}

		unset( $slug );

		return array(
			'entries'       => $entries,
			'files_scanned' => count( $files ),
			'files'         => $files,
		);
	}
}
