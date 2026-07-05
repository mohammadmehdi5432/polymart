<?php
/**
 * Scan companion plugin .pot/.po catalogs for bulk UI string translation.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Class UI_String_Scanner
 */
final class UI_String_Scanner {

	/**
	 * File extensions treated as gettext catalogs.
	 *
	 * @var string[]
	 */
	private static $catalog_extensions = array( 'pot', 'po' );

	/**
	 * Scan allowed plugins and rebuild the UI string registry.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function scan_and_save() {
		$scan = self::scan_catalogs();

		if ( is_wp_error( $scan ) ) {
			return $scan;
		}

		UI_String_Registry::save_registry(
			array(
				'plugins' => $scan['plugins'],
				'entries' => $scan['entries'],
			)
		);

		return array_merge(
			UI_String_Registry::get_stats(),
			array(
				'debug_info' => $scan['debug_info'],
			)
		);
	}

	/**
	 * All plugin slugs to scan: configured list + every installed plugin (except blocklist).
	 *
	 * @return string[]
	 */
	public static function list_scannable_plugin_slugs() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$slugs = array_merge(
			UI_String_Registry::get_allowed_plugin_slugs(),
			self::get_core_storefront_plugin_slugs()
		);

		/**
		 * When true, every installed plugin folder is scanned (legacy/noisy behavior).
		 *
		 * @param bool $scan_all Default false — only companion + core storefront plugins.
		 */
		if ( apply_filters( 'polymart_ai_ui_string_scan_all_plugins', false ) ) {
			$skip_slugs = self::get_skipped_plugin_slugs();
			$skip_map   = array_fill_keys( array_map( 'strtolower', $skip_slugs ), true );
			$roots      = glob( wp_normalize_path( WP_PLUGIN_DIR . '/*' ), GLOB_ONLYDIR );

			if ( is_array( $roots ) ) {
				foreach ( $roots as $root ) {
					$slug = wp_basename( $root );

					if ( isset( $skip_map[ strtolower( $slug ) ] ) ) {
						continue;
					}

					$slugs[] = $slug;
				}
			}
		}

		$slugs = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $slug ) {
							return trim( str_replace( array( '..', '/', '\\', "\0" ), '', (string) $slug ) );
						},
						$slugs
					)
				)
			)
		);

		sort( $slugs, SORT_STRING );

		$cache = $slugs;

		return $cache;
	}

	/**
	 * Large storefront plugins scanned from .pot/.po catalogs only (not full source trees).
	 *
	 * @return string[]
	 */
	public static function get_catalog_only_plugin_slugs() {
		$defaults = array(
			'woocommerce',
			'woodmart-core',
			'persian-woocommerce',
		);

		/**
		 * Filter plugin slugs that should use languages/*.pot only (skip PHP/JS source scan).
		 *
		 * @param string[] $slugs Plugin directory slugs.
		 */
		return array_values(
			array_unique(
				array_map(
					'strtolower',
					(array) apply_filters( 'polymart_ai_ui_string_catalog_only_slugs', $defaults )
				)
			)
		);
	}

	/**
	 * Core WooCommerce/Woodmart plugins always included in UI string scans.
	 *
	 * @return string[]
	 */
	public static function get_core_storefront_plugin_slugs() {
		return self::get_catalog_only_plugin_slugs();
	}

	/**
	 * Plugin folder names excluded from UI string scans.
	 *
	 * @return string[]
	 */
	public static function get_skipped_plugin_slugs() {
		$skip_slugs = array(
			'PolyMartAI',
			'elementor',
			'elementor-pro',
			'wordpress-seo',
			'litespeed-cache',
			'query-monitor',
			'classic-editor',
			'insert-headers-and-footers',
			'members',
			'persian-woocommerce-sms',
			'akismet',
			'hello',
		);

		/**
		 * Filter plugin folder names excluded from UI string scans.
		 *
		 * @param string[] $skip_slugs Plugin directory slugs.
		 */
		return (array) apply_filters( 'polymart_ai_ui_string_skip_plugin_slugs', $skip_slugs );
	}

	/**
	 * Scan the active parent theme for storefront gettext strings.
	 *
	 * @param array<string, array<string, mixed>> $entries Registry entries (by reference).
	 * @param array<string, mixed>                $debug   Debug payload (by reference).
	 * @return array{plugins: array<string, array<string, mixed>>}
	 */
	private static function scan_active_theme( array &$entries, array &$debug ) {
		$theme_dir = wp_normalize_path( (string) get_template_directory() );
		$slug      = 'theme:' . sanitize_key( (string) get_template() );
		$plugins   = array();

		if ( '' === $theme_dir || ! is_dir( $theme_dir ) ) {
			return array( 'plugins' => $plugins );
		}

		$theme_debug = array(
			'slug'                 => $slug,
			'source_files_scanned' => 0,
			'source_entries_added' => 0,
			'catalog_entries_added' => 0,
			'resolved_dir'         => $theme_dir,
			'dir_exists'           => true,
			'languages_dir'        => wp_normalize_path( $theme_dir . '/languages' ),
			'languages_exists'     => is_dir( $theme_dir . '/languages' ),
			'files_found'          => array(),
			'files_parsed'         => array(),
			'source_sample_files'  => array(),
		);

		$catalog_files = self::discover_catalog_files( $theme_dir, $debug );
		$theme_debug['catalog_only'] = ! empty( $catalog_files );

		if ( empty( $catalog_files ) ) {
			$source_scan = UI_String_Source_Scanner::scan_limited_directories(
				$theme_dir,
				$slug,
				array( 'inc', 'template-parts', 'woocommerce', 'header', 'footer' )
			);
			$theme_debug['source_files_scanned'] = (int) ( $source_scan['files_scanned'] ?? 0 );
			$theme_debug['source_sample_files']  = array_slice( (array) ( $source_scan['files'] ?? array() ), 0, 5 );

				if ( ! empty( $source_scan['entries'] ) ) {
					$storefront_entries = UI_String_Storefront_Filter::filter_entries( $source_scan['entries'], $slug );

				foreach ( $storefront_entries as $entry ) {
					$domain = isset( $entry['domain'] ) ? (string) $entry['domain'] : 'woodmart';

					if ( ! UI_String_Registry::is_allowed_domain( $domain ) ) {
						continue;
					}

					$added = self::merge_parsed_entries( $entries, array( $entry ), $domain, $slug );
					$theme_debug['source_entries_added'] += $added;
					$debug['total_entries_added']        += $added;
				}
			}
		}

		foreach ( $catalog_files as $path ) {
			if ( UI_String_Storefront_Filter::is_admin_catalog_file( $path ) ) {
				continue;
			}

			$theme_debug['files_found'][] = $path;
			$debug['files_found'][]       = $path;

			if ( ! is_readable( $path ) ) {
				continue;
			}

			$parsed = self::parse_catalog_file( $path );

			if ( is_wp_error( $parsed ) ) {
				continue;
			}

			$domain = isset( $parsed['domain'] ) ? (string) $parsed['domain'] : 'woodmart';

			if ( ! UI_String_Registry::is_allowed_domain( $domain ) ) {
				continue;
			}

			$storefront_entries = UI_String_Storefront_Filter::filter_entries( $parsed['entries'], $slug );
			$added              = self::merge_parsed_entries( $entries, $storefront_entries, $domain, $slug );
			$theme_debug['catalog_entries_added'] += $added;
			$debug['total_entries_added']         += $added;
		}

		$theme_total = (int) $theme_debug['source_entries_added'] + (int) $theme_debug['catalog_entries_added'];

		if ( $theme_total > 0 ) {
			$plugins[ $slug ] = array(
				'catalog_file' => ! empty( $theme_debug['files_found'] )
					? implode( ', ', array_map( 'wp_basename', $theme_debug['files_found'] ) )
					: '',
				'source_files' => (int) $theme_debug['source_files_scanned'],
				'path'         => $theme_dir,
				'domain'       => 'woodmart',
				'count'        => $theme_total,
			);
		}

		$debug['plugins'][] = $theme_debug;

		return array( 'plugins' => $plugins );
	}

	public static function scan_catalogs() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		$debug = self::empty_debug_info();

		$entries = array();
		$plugins = array();

		$debug['scannable_plugin_slugs'] = self::list_scannable_plugin_slugs();

		foreach ( $debug['scannable_plugin_slugs'] as $slug ) {
			$plugin_debug = array(
				'slug'                 => $slug,
				'source_files_scanned' => 0,
				'source_entries_added' => 0,
				'catalog_entries_added' => 0,
				'resolved_dir'         => '',
				'dir_exists'           => false,
				'languages_dir'        => '',
				'languages_exists'     => false,
				'files_found'          => array(),
				'files_parsed'         => array(),
				'source_sample_files'  => array(),
			);

			$plugin_dir = self::resolve_plugin_directory( $slug );
			$plugin_debug['resolved_dir'] = $plugin_dir;
			$plugin_debug['dir_exists']   = '' !== $plugin_dir;

			if ( '' === $plugin_dir ) {
				$plugin_debug['error'] = 'plugin_directory_not_found';
				$debug['plugins'][]    = $plugin_debug;
				continue;
			}

			$debug['searched_paths'][] = $plugin_dir;

			$languages_dir = wp_normalize_path( $plugin_dir . '/languages' );
			$plugin_debug['languages_dir']    = $languages_dir;
			$plugin_debug['languages_exists'] = is_dir( $languages_dir );
			$catalog_only                     = in_array( strtolower( $slug ), self::get_catalog_only_plugin_slugs(), true );
			$plugin_debug['catalog_only']     = $catalog_only;

			// 1) Companion plugins: scan only public/frontend/template subtrees.
			if ( ! $catalog_only ) {
				$source_scan = UI_String_Source_Scanner::scan_limited_directories(
					$plugin_dir,
					$slug,
					UI_String_Storefront_Filter::companion_source_subdirs()
				);
				$plugin_debug['source_files_scanned'] = (int) ( $source_scan['files_scanned'] ?? 0 );
				$plugin_debug['source_sample_files']  = array_slice( (array) ( $source_scan['files'] ?? array() ), 0, 5 );
				$plugin_debug['source_scan_mode']     = 'companion_subdirs';

				if ( ! empty( $source_scan['entries'] ) ) {
					$storefront_entries = UI_String_Storefront_Filter::filter_entries( $source_scan['entries'], $slug );

					foreach ( $storefront_entries as $entry ) {
						$domain = isset( $entry['domain'] ) ? (string) $entry['domain'] : 'polymart-ai';

						if ( ! UI_String_Registry::is_allowed_domain( $domain ) ) {
							continue;
						}

						$added = self::merge_parsed_entries( $entries, array( $entry ), $domain, $slug );
						$plugin_debug['source_entries_added'] += $added;
						$debug['total_entries_added']         += $added;
					}

					$debug['total_source_entries_parsed'] = (int) ( $debug['total_source_entries_parsed'] ?? 0 )
						+ count( $storefront_entries );
				}
			}

			// 2) Merge .pot/.po catalogs (primary source for WooCommerce/Woodmart core).
			$catalog_files = self::discover_catalog_files( $plugin_dir, $debug );

			foreach ( $catalog_files as $path ) {
				if ( UI_String_Storefront_Filter::is_admin_catalog_file( $path ) ) {
					continue;
				}

				$plugin_debug['files_found'][] = $path;
				$debug['files_found'][]        = $path;

				$file_debug = array(
					'path'           => $path,
					'readable'       => is_readable( $path ),
					'extension'      => strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ),
					'entries_parsed' => 0,
					'entries_added'  => 0,
					'domain'         => '',
					'domain_allowed' => false,
					'parse_error'    => null,
					'skipped_reason' => null,
					'source'         => 'catalog',
					'entries_skipped_admin' => 0,
				);

				if ( ! is_readable( $path ) ) {
					$file_debug['skipped_reason']   = 'not_readable';
					$plugin_debug['files_parsed'][] = $file_debug;
					continue;
				}

				$parsed = self::parse_catalog_file( $path );

				if ( is_wp_error( $parsed ) ) {
					$file_debug['parse_error']    = $parsed->get_error_message();
					$file_debug['skipped_reason'] = 'parse_failed';
					$plugin_debug['files_parsed'][] = $file_debug;
					continue;
				}

				$domain = isset( $parsed['domain'] ) ? (string) $parsed['domain'] : 'polymart-ai';
				$file_debug['domain']         = $domain;
				$file_debug['entries_parsed'] = count( $parsed['entries'] );
				$file_debug['domain_allowed'] = UI_String_Registry::is_allowed_domain( $domain );

				if ( ! $file_debug['domain_allowed'] ) {
					$file_debug['skipped_reason'] = 'domain_not_allowed';
					$plugin_debug['files_parsed'][] = $file_debug;
					continue;
				}

				$storefront_entries = UI_String_Storefront_Filter::filter_entries(
					$parsed['entries'],
					$slug
				);
				$file_debug['entries_skipped_admin'] = max( 0, count( $parsed['entries'] ) - count( $storefront_entries ) );

				$added = self::merge_parsed_entries( $entries, $storefront_entries, $domain, $slug );

				$file_debug['entries_added']     = $added;
				$plugin_debug['files_parsed'][]  = $file_debug;
				$plugin_debug['catalog_entries_added'] += $added;
				$debug['total_entries_parsed']  += $file_debug['entries_parsed'];
				$debug['total_entries_added']   += $added;
			}

			$plugin_total = (int) $plugin_debug['source_entries_added'] + (int) $plugin_debug['catalog_entries_added'];

			if ( $plugin_total > 0 ) {
				$plugins[ $slug ] = array(
					'catalog_file'  => ! empty( $plugin_debug['files_found'] )
						? implode( ', ', array_map( 'wp_basename', $plugin_debug['files_found'] ) )
						: '',
					'source_files'  => (int) $plugin_debug['source_files_scanned'],
					'path'          => $plugin_dir,
					'domain'        => 'polymart-ai',
					'count'         => $plugin_total,
				);
			}

			$debug['plugins'][] = $plugin_debug;
		}

		$theme_scan = self::scan_active_theme( $entries, $debug );
		if ( ! empty( $theme_scan['plugins'] ) ) {
			$plugins = array_merge( $plugins, $theme_scan['plugins'] );
		}

		$extra_entries = apply_filters( 'polymart_ai_ui_string_scan_extra_entries', array() );
		$extra_added   = 0;

		if ( is_array( $extra_entries ) ) {
			foreach ( $extra_entries as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['msgid'] ) ) {
					continue;
				}

				$domain = isset( $entry['domain'] ) ? (string) $entry['domain'] : 'polymart-ai';
				$slug   = isset( $entry['plugin'] ) ? (string) $entry['plugin'] : 'JetCheckout';

				if ( ! UI_String_Registry::is_allowed_domain( $domain ) ) {
					continue;
				}

				$extra_added += self::merge_parsed_entries( $entries, array( $entry ), $domain, $slug );
			}
		}

		if ( $extra_added > 0 ) {
			$plugins['JetCheckout'] = array(
				'catalog_file' => '',
				'source_files' => 0,
				'path'         => 'woocommerce-settings',
				'domain'       => 'polymart-ai',
				'count'        => $extra_added,
			);
			$debug['total_entries_added'] = (int) ( $debug['total_entries_added'] ?? 0 ) + $extra_added;
		}

		$debug['total_unique_strings'] = count( $entries );
		$debug['files_found_count']    = count( $debug['files_found'] );

		if ( empty( $entries ) ) {
			return new \WP_Error(
				'polymart_ai_ui_strings_empty',
				__( 'هیچ رشته gettext در افزونه‌های مجاز یافت نشد. مسیرها و جزئیات در debug_info آمده است.', 'polymart-ai' ),
				array(
					'status'     => 400,
					'debug_info' => $debug,
				)
			);
		}

		return array(
			'entries'    => $entries,
			'plugins'    => $plugins,
			'debug_info' => $debug,
		);
	}

	/**
	 * Auto-discover installed plugins that declare an allowed text domain.
	 *
	 * @return string[]
	 */
	public static function discover_installed_plugin_slugs() {
		$skip_slugs = array(
			'PolyMartAI',
			'elementor',
			'elementor-pro',
			'wordpress-seo',
			'litespeed-cache',
			'query-monitor',
			'classic-editor',
			'insert-headers-and-footers',
			'members',
			'persian-woocommerce-sms',
			'akismet',
			'hello',
		);

		/**
		 * Filter plugin folder names excluded from auto-discovery.
		 *
		 * @param string[] $skip_slugs Plugin directory slugs.
		 */
		$skip_slugs = (array) apply_filters( 'polymart_ai_ui_string_skip_plugin_slugs', $skip_slugs );
		$skip_map   = array_fill_keys( array_map( 'strtolower', $skip_slugs ), true );

		$domains  = UI_String_Registry::get_allowed_domains();
		$results  = array();
		$roots    = glob( wp_normalize_path( WP_PLUGIN_DIR . '/*' ), GLOB_ONLYDIR );

		if ( ! is_array( $roots ) ) {
			return $results;
		}

		foreach ( $roots as $root ) {
			$slug = wp_basename( $root );

			if ( isset( $skip_map[ strtolower( $slug ) ] ) ) {
				continue;
			}

			if ( self::plugin_declares_allowed_domain( $root, $domains ) ) {
				$results[] = $slug;
			}
		}

		sort( $results, SORT_STRING );

		return $results;
	}

	/**
	 * Check whether a plugin folder references an allowed text domain.
	 *
	 * @param string   $plugin_dir Plugin directory.
	 * @param string[] $domains    Allowed domains.
	 * @return bool
	 */
	private static function plugin_declares_allowed_domain( $plugin_dir, array $domains ) {
		$plugin_dir = wp_normalize_path( (string) $plugin_dir );
		$markers    = array();

		foreach ( $domains as $domain ) {
			$markers[] = "Text Domain:\t\t" . $domain;
			$markers[] = "Text Domain:\t" . $domain;
			$markers[] = "Text Domain:       " . $domain;
			$markers[] = "Text Domain: " . $domain;
			$markers[] = "'" . $domain . "'";
			$markers[] = '"' . $domain . '"';
		}

		$candidates = array_merge(
			(array) glob( $plugin_dir . '/*.php' ),
			(array) glob( $plugin_dir . '/*/*.php' )
		);

		foreach ( $candidates as $file ) {
			if ( ! is_readable( $file ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- header scan only.
			$chunk = file_get_contents( $file, false, null, 0, 8192 );

			if ( false === $chunk || '' === $chunk ) {
				continue;
			}

			foreach ( $markers as $marker ) {
				if ( false !== strpos( $chunk, $marker ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Discover .pot/.po catalog files for a plugin (backward-compatible helper).
	 *
	 * @return array<int, array{plugin: string, path: string}>
	 */
	public static function discover_pot_files() {
		$results = array();

		foreach ( self::list_scannable_plugin_slugs() as $slug ) {
			$plugin_dir = self::resolve_plugin_directory( $slug );

			if ( '' === $plugin_dir ) {
				continue;
			}

			foreach ( self::discover_catalog_files( $plugin_dir ) as $path ) {
				$results[] = array(
					'plugin' => $slug,
					'path'   => $path,
				);
			}
		}

		return $results;
	}

	/**
	 * Resolve a plugin slug to an absolute directory path.
	 *
	 * Preserves slug casing and falls back to a case-insensitive match on Linux hosts.
	 *
	 * @param string $slug Plugin folder slug.
	 * @return string Normalized absolute path or empty string.
	 */
	public static function resolve_plugin_directory( $slug ) {
		$slug = self::normalize_plugin_slug( $slug );

		if ( '' === $slug ) {
			return '';
		}

		$direct = wp_normalize_path( untrailingslashit( WP_PLUGIN_DIR ) . '/' . $slug );
		$found  = UI_String_Source_Scanner::resolve_existing_directory( $direct );

		if ( '' !== $found ) {
			return $found;
		}

		$plugin_roots = glob( wp_normalize_path( WP_PLUGIN_DIR . '/*' ), GLOB_ONLYDIR );

		if ( ! is_array( $plugin_roots ) ) {
			return '';
		}

		$needle = strtolower( $slug );

		foreach ( $plugin_roots as $root ) {
			if ( strtolower( wp_basename( $root ) ) === $needle ) {
				return UI_String_Source_Scanner::resolve_existing_directory( $root );
			}
		}

		return '';
	}

	/**
	 * Find all .pot and .po files under a plugin's languages directory.
	 *
	 * @param string                    $plugin_dir Absolute plugin directory.
	 * @param array<string, mixed>|null $debug      Optional debug bucket to append search notes.
	 * @return string[]
	 */
	public static function discover_catalog_files( $plugin_dir, &$debug = null ) {
		$plugin_dir = wp_normalize_path( (string) $plugin_dir );
		$files      = array();
		$seen       = array();

		$search_roots = array(
			$plugin_dir . '/languages',
		);

		foreach ( $search_roots as $root ) {
			$root = wp_normalize_path( $root );

			if ( null !== $debug && is_array( $debug ) ) {
				$debug['searched_paths'][] = $root;
			}

			if ( ! is_dir( $root ) ) {
				continue;
			}

			foreach ( self::$catalog_extensions as $extension ) {
				$patterns = array(
					$root . '/*.' . $extension,
					$root . '/*/*.' . $extension,
					$root . '/*/*/*.' . $extension,
				);

				foreach ( $patterns as $pattern ) {
					$matches = glob( $pattern );

					if ( ! is_array( $matches ) ) {
						continue;
					}

					foreach ( $matches as $match ) {
						if ( ! is_string( $match ) || ! is_file( $match ) ) {
							continue;
						}

						$path = wp_normalize_path( $match );
						$key  = strtolower( $path );

						if ( isset( $seen[ $key ] ) ) {
							continue;
						}

						$seen[ $key ] = true;
						$files[]      = $path;
					}
				}
			}

			if ( class_exists( '\RecursiveIteratorIterator', false ) && class_exists( '\RecursiveDirectoryIterator', false ) ) {
				try {
					$iterator = new \RecursiveIteratorIterator(
						new \RecursiveDirectoryIterator( $root, \RecursiveDirectoryIterator::SKIP_DOTS )
					);

					foreach ( $iterator as $file_info ) {
						if ( ! $file_info instanceof \SplFileInfo || ! $file_info->isFile() ) {
							continue;
						}

						$extension = strtolower( $file_info->getExtension() );

						if ( ! in_array( $extension, self::$catalog_extensions, true ) ) {
							continue;
						}

						$path = wp_normalize_path( $file_info->getPathname() );
						$key  = strtolower( $path );

						if ( isset( $seen[ $key ] ) ) {
							continue;
						}

						$seen[ $key ] = true;
						$files[]      = $path;
					}
				} catch ( \Exception $exception ) {
					if ( null !== $debug && is_array( $debug ) ) {
						$debug['iterator_errors'][] = array(
							'path'    => $root,
							'message' => $exception->getMessage(),
						);
					}
				}
			}
		}

		sort( $files, SORT_STRING );

		return $files;
	}

	/**
	 * Parse a gettext catalog file using WordPress core PO parser.
	 *
	 * @param string $path Absolute path to a .pot or .po file.
	 * @return array{domain: string, entries: array<int, array<string, string>>}|\WP_Error
	 */
	public static function parse_catalog_file( $path ) {
		$path = wp_normalize_path( (string) $path );

		if ( ! is_readable( $path ) ) {
			return new \WP_Error(
				'polymart_ai_catalog_unreadable',
				sprintf(
					/* translators: %s: file path */
					__( 'فایل کاتالوگ قابل خواندن نیست: %s', 'polymart-ai' ),
					$path
				)
			);
		}

		self::ensure_po_class_loaded();

		$po = new \PO();

		if ( ! $po->import_from_file( $path ) ) {
			return new \WP_Error(
				'polymart_ai_catalog_parse_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'خواندن فایل کاتالوگ ناموفق بود: %s', 'polymart-ai' ),
					$path
				)
			);
		}

		$domain  = self::extract_domain_from_po( $po );
		$entries = self::convert_po_entries( $po );

		return array(
			'domain'  => $domain,
			'entries' => $entries,
		);
	}

	/**
	 * Backward-compatible alias for pot parsing.
	 *
	 * @param string $path File path.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function parse_pot_file( $path ) {
		return self::parse_catalog_file( $path );
	}

	/**
	 * Merge parsed entries into the master registry map.
	 *
	 * @param array<string, array<string, mixed>> $entries    Existing entries keyed by hash.
	 * @param array<int, array<string, mixed>> $parsed       Parsed catalog entries.
	 * @param string                              $domain     Text domain.
	 * @param string                              $plugin_slug Plugin slug.
	 * @return int Number of newly added entries.
	 */
	private static function merge_parsed_entries( array &$entries, array $parsed, $domain, $plugin_slug ) {
		$added = 0;

		foreach ( $parsed as $entry ) {
			$msgid = isset( $entry['msgid'] ) ? (string) $entry['msgid'] : '';

			if ( '' === trim( $msgid ) ) {
				continue;
			}

			$context = isset( $entry['context'] ) ? (string) $entry['context'] : '';
			$hash    = UI_String_Registry::string_hash( $msgid, $context );

			if ( ! isset( $entries[ $hash ] ) ) {
				$entries[ $hash ] = array(
					'msgid'   => $msgid,
					'context' => $context,
					'domain'  => $domain,
					'plugin'  => $plugin_slug,
					'plural'  => isset( $entry['msgid_plural'] ) ? (string) $entry['msgid_plural'] : '',
				);
				++$added;
			}

			$plural = isset( $entry['msgid_plural'] ) ? trim( (string) $entry['msgid_plural'] ) : '';

			if ( '' !== $plural ) {
				$plural_hash = UI_String_Registry::string_hash( $plural, $context );

				if ( ! isset( $entries[ $plural_hash ] ) ) {
					$entries[ $plural_hash ] = array(
						'msgid'          => $plural,
						'context'        => $context,
						'domain'         => $domain,
						'plugin'         => $plugin_slug,
						'plural'         => $plural,
						'is_plural_form' => true,
					);
					++$added;
				}
			}
		}

		return $added;
	}

	/**
	 * Convert PO class entries to scanner entry arrays.
	 *
	 * @param \PO $po Parsed PO object.
	 * @return array<int, array<string, string>>
	 */
	private static function convert_po_entries( \PO $po ) {
		$entries = array();

		if ( empty( $po->entries ) || ! is_array( $po->entries ) ) {
			return $entries;
		}

		foreach ( $po->entries as $entry ) {
			if ( ! $entry instanceof \Translation_Entry ) {
				continue;
			}

			$singular = is_string( $entry->singular ) ? $entry->singular : '';

			if ( '' === trim( $singular ) ) {
				continue;
			}

			$entries[] = array(
				'context'      => is_string( $entry->context ) ? $entry->context : '',
				'msgid'        => $singular,
				'msgid_plural' => ( $entry->is_plural && is_string( $entry->plural ) ) ? $entry->plural : '',
				'references'   => is_array( $entry->references ) ? array_values( $entry->references ) : array(),
			);
		}

		return $entries;
	}

	/**
	 * Extract text domain from PO headers.
	 *
	 * @param \PO $po Parsed PO object.
	 * @return string
	 */
	private static function extract_domain_from_po( \PO $po ) {
		if ( is_array( $po->headers ) ) {
			foreach ( $po->headers as $header => $value ) {
				if ( 'x-domain' === strtolower( (string) $header ) && is_string( $value ) && '' !== trim( $value ) ) {
					return sanitize_key( trim( $value ) );
				}
			}
		}

		return 'polymart-ai';
	}

	/**
	 * Load WordPress core PO parser when needed.
	 *
	 * @return void
	 */
	private static function ensure_po_class_loaded() {
		if ( class_exists( 'PO', false ) ) {
			return;
		}

		require_once ABSPATH . WPINC . '/pomo/po.php';
	}

	/**
	 * Sanitize plugin slug without changing directory name casing.
	 *
	 * @param string $slug Raw slug.
	 * @return string
	 */
	private static function normalize_plugin_slug( $slug ) {
		$slug = trim( (string) $slug );
		$slug = str_replace( array( '..', '/', '\\', "\0" ), '', $slug );

		return trim( $slug );
	}

	/**
	 * Default debug payload shape.
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_debug_info() {
		return array(
			'wp_plugin_dir'              => wp_normalize_path( WP_PLUGIN_DIR ),
			'allowed_plugin_slugs'       => UI_String_Registry::get_allowed_plugin_slugs(),
			'scannable_plugin_slugs'     => UI_String_Registry::get_scannable_plugin_slugs(),
			'allowed_domains'            => UI_String_Registry::get_allowed_domains(),
			'scan_modes'                 => array( 'php_js_source', 'pot_po_catalog' ),
			'searched_paths'             => array(),
			'files_found'                => array(),
			'files_found_count'          => 0,
			'total_source_entries_parsed' => 0,
			'total_entries_parsed'       => 0,
			'total_entries_added'        => 0,
			'total_unique_strings'       => 0,
			'iterator_errors'            => array(),
			'plugins'                    => array(),
		);
	}
}
