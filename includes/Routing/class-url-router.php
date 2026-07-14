<?php
/**
 * URL routing for configurable language URL prefixes.
 *
 * @package PolymartAI\Routing
 */

namespace PolymartAI\Routing;

use PolymartAI\Admin_Request_Guard;
use PolymartAI\Language_Registry;
use PolymartAI\Translation\Post_Translator;


defined( 'ABSPATH' ) || exit;

/**
 * Class Url_Router
 */
final class Url_Router {

	/**
	 * Query var holding the active language code.
	 */
	const QUERY_VAR = 'polymart_lang';

	/**
	 * Internal query var for the path segment after the language prefix.
	 */
	const PATH_VAR = 'polymart_path';

	/**
	 * Cached current language code.
	 *
	 * @var string|null
	 */
	private static $current_language_cache = null;

	/**
	 * Guard against recursive language detection during nested WP lookups.
	 *
	 * @var bool
	 */
	private static $resolving_language = false;

	/**
	 * Guard against recursive permalink prefixing.
	 *
	 * @var bool
	 */
	private static $prefixing_permalink = false;

	/**
	 * Cached site home URL built without the home_url filter chain.
	 *
	 * @var string|null
	 */
	private static $site_home_url_cache = null;

	/**
	 * Guard against recursion inside the locale filter.
	 *
	 * @var bool
	 */
	private static $resolving_locale = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		self::bootstrap_current_language_from_request();

		add_filter( 'locale', array( $this, 'filter_frontend_locale' ), 20 );
		add_filter( 'determine_locale', array( $this, 'filter_frontend_locale' ), 20 );

		add_action( 'init', array( $this, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'parse_request', array( $this, 'resolve_language_request' ), 5 );
		add_filter( 'redirect_canonical', array( $this, 'disable_canonical_redirect_for_translated_urls' ), 10, 2 );
		add_filter( 'post_link', array( $this, 'filter_prefixed_permalink' ), 10, 3 );
		add_filter( 'page_link', array( $this, 'filter_prefixed_page_link' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'filter_prefixed_permalink' ), 10, 4 );
		add_filter( 'term_link', array( $this, 'filter_prefixed_term_link' ), 10, 3 );
		add_filter( 'post_type_archive_link', array( $this, 'filter_prefixed_archive_link' ), 10, 2 );
		add_action( 'update_option_show_on_front', array( $this, 'flush_rewrite_rules_on_front_page_change' ) );
		add_action( 'update_option_page_on_front', array( $this, 'flush_rewrite_rules_on_front_page_change' ) );
	}

	/**
	 * Plugin activation — seed languages, register rules, flush rewrite cache.
	 *
	 * @return void
	 */
	public static function activate() {
		Language_Registry::maybe_seed_defaults();

		$router = new self();
		$router->register_rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation — flush rewrite cache after removal.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Serve translated storefront URLs under the target language locale.
	 *
	 * Without this, /en/ pages keep the default-language locale (e.g. fa_IR),
	 * so WooCommerce, Woodmart, and core render hundreds of UI strings in the
	 * default language. Switching the locale loads the target-language .mo
	 * files (or falls back to English source msgids) — the same approach WPML
	 * uses. wp-admin keeps its own locale; frontend AJAX resolves the language
	 * from the referring page.
	 *
	 * @param string $locale Current locale.
	 * @return string
	 */
	public function filter_frontend_locale( $locale ) {
		if ( self::$resolving_locale ) {
			return $locale;
		}

		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $locale;
		}

		if ( is_admin() && ( ! wp_doing_ajax() || Admin_Request_Guard::is_admin_system_ajax() ) ) {
			return $locale;
		}

		if ( Admin_Request_Guard::is_infrastructure_request() || Admin_Request_Guard::is_rest_media_request() ) {
			return $locale;
		}

		self::$resolving_locale = true;

		$mapped = '';

		if ( self::is_translated_request() ) {
			$mapped = Language_Registry::get_locale_for_language( self::get_current_language() );
		}

		self::$resolving_locale = false;

		return '' !== $mapped ? $mapped : $locale;
	}

	/**
	 * Prevent WordPress from redirecting prefixed language URLs to the default permalink.
	 *
	 * When a static front page (or any page) is loaded via /en/, redirect_canonical
	 * would otherwise send visitors to the default-language URL and break Elementor.
	 *
	 * @param string|false $redirect_url  Canonical redirect target.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public function disable_canonical_redirect_for_translated_urls( $redirect_url, $requested_url ) {
		if ( self::is_translated_request() ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Prefix post and custom post type permalinks.
	 *
	 * @param string  $url       Permalink.
	 * @param \WP_Post $post     Post object.
	 * @param bool    $leavename Whether the post name was left in `%postname%` form.
	 * @return string
	 */
	public function filter_prefixed_permalink( $url, $post, $leavename = false ) {
		unset( $leavename );

		if ( self::$prefixing_permalink || ! self::should_prefix_links() || ! is_string( $url ) ) {
			return $url;
		}

		self::$prefixing_permalink = true;
		$prefixed                  = self::add_language_prefix_to_url( $url );
		self::$prefixing_permalink = false;

		return $prefixed;
	}

	/**
	 * Prefix page permalinks.
	 *
	 * @param string $url     Page permalink.
	 * @param int    $post_id Page ID.
	 * @return string
	 */
	public function filter_prefixed_page_link( $url, $post_id ) {
		unset( $post_id );

		if ( self::$prefixing_permalink || ! self::should_prefix_links() || ! is_string( $url ) ) {
			return $url;
		}

		self::$prefixing_permalink = true;
		$prefixed                  = self::add_language_prefix_to_url( $url );
		self::$prefixing_permalink = false;

		return $prefixed;
	}

	/**
	 * Prefix taxonomy term links.
	 *
	 * @param string  $termlink Term link URL.
	 * @param \WP_Term $term    Term object.
	 * @param string  $taxonomy Taxonomy slug.
	 * @return string
	 */
	public function filter_prefixed_term_link( $termlink, $term, $taxonomy ) {
		unset( $term, $taxonomy );

		if ( self::$prefixing_permalink || ! self::should_prefix_links() || ! is_string( $termlink ) ) {
			return $termlink;
		}

		self::$prefixing_permalink = true;
		$prefixed                  = self::add_language_prefix_to_url( $termlink );
		self::$prefixing_permalink = false;

		return $prefixed;
	}

	/**
	 * Prefix post type archive links (e.g. shop).
	 *
	 * @param string $link      Archive URL.
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	public function filter_prefixed_archive_link( $link, $post_type ) {
		unset( $post_type );

		if ( self::$prefixing_permalink || ! self::should_prefix_links() || ! is_string( $link ) ) {
			return $link;
		}

		self::$prefixing_permalink = true;
		$prefixed                  = self::add_language_prefix_to_url( $link );
		self::$prefixing_permalink = false;

		return $prefixed;
	}

	/**
	 * Whether permalinks should receive the active language prefix.
	 *
	 * @return bool
	 */
	private static function should_prefix_links() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		if ( ! self::is_translated_request() ) {
			return false;
		}

		/*
		 * Cart fragment AJAX renders mini-cart HTML on /en/ with wp_doing_ajax() true.
		 * Keep language-prefixed permalinks so cart links stay on the translated storefront.
		 */
		if ( self::is_storefront_wc_ajax_request() ) {
			return true;
		}

		if ( self::is_system_request() ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the current request is a WooCommerce wc-ajax sub-request.
	 *
	 * @return bool
	 */
	private static function is_storefront_wc_ajax_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing hint.
		if ( isset( $_GET['wc-ajax'] ) && is_string( $_GET['wc-ajax'] ) && '' !== $_GET['wc-ajax'] ) {
			return true;
		}

		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		return false !== strpos( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), 'wc-ajax=' );
	}

	/**
	 * Detect REST API, AJAX, CLI, and other non-frontend browsing contexts.
	 *
	 * @return bool
	 */
	private static function is_system_request() {
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] );

		return false !== strpos( $uri, '/wp-json/' )
			|| false !== strpos( $uri, 'admin-ajax.php' )
			|| false !== strpos( $uri, 'wc-ajax=' )
			|| false !== strpos( $uri, 'wp-admin' )
			|| false !== strpos( $uri, 'wp-login.php' );
	}

	/**
	 * URL prefix for the active routed language.
	 *
	 * @return string
	 */
	private static function get_current_url_prefix() {
		$language = Language_Registry::get_language( self::get_current_language() );

		if ( ! $language || empty( $language['url_prefix'] ) ) {
			return '';
		}

		return sanitize_key( (string) $language['url_prefix'] );
	}

	/**
	 * Insert the active language prefix immediately after the site home path.
	 *
	 * @param string $url Permalink or home URL.
	 * @return string
	 */
	public static function add_language_prefix_to_url( $url ) {
		return self::add_language_prefix_for_code( $url, self::get_current_language() );
	}

	/**
	 * Insert a specific language URL prefix immediately after the site home path.
	 *
	 * @param string $url           Permalink or home URL.
	 * @param string $language_code Routed language code.
	 * @return string
	 */
	public static function add_language_prefix_for_code( $url, $language_code ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return is_string( $url ) ? $url : '';
		}

		$language_code = sanitize_key( (string) $language_code );

		if ( '' === $language_code || $language_code === Language_Registry::get_default_language_code() ) {
			return $url;
		}

		$language = Language_Registry::get_language( $language_code );

		if ( ! $language || empty( $language['url_prefix'] ) ) {
			return $url;
		}

		$prefix = sanitize_key( (string) $language['url_prefix'] );

		if ( '' === $prefix ) {
			return $url;
		}

		$site_home = untrailingslashit( (string) get_option( 'home' ) );

		if ( '' === $site_home ) {
			return $url;
		}

		$parsed_url  = wp_parse_url( $url );
		$parsed_home = wp_parse_url( $site_home );

		if ( empty( $parsed_url['host'] ) || empty( $parsed_home['host'] ) ) {
			return $url;
		}

		if ( strtolower( (string) $parsed_url['host'] ) !== strtolower( (string) $parsed_home['host'] ) ) {
			return $url;
		}

		$home_path = isset( $parsed_home['path'] ) ? untrailingslashit( (string) $parsed_home['path'] ) : '';
		$url_path  = isset( $parsed_url['path'] ) ? (string) $parsed_url['path'] : '/';

		$relative = $url_path;

		if ( '' !== $home_path && 0 === strpos( $url_path, $home_path ) ) {
			$relative = substr( $url_path, strlen( $home_path ) );
		}

		$relative = ltrim( $relative, '/' );

		if ( $relative === $prefix || 0 === strpos( $relative, $prefix . '/' ) ) {
			return $url;
		}

		$new_path = ( '' !== $home_path ? $home_path . '/' : '/' ) . $prefix;

		if ( '' !== $relative ) {
			$new_path .= '/' . $relative;
		}

		$parsed_url['path'] = trailingslashit( $new_path );

		return self::build_url_from_parts( $parsed_url );
	}

	/**
	 * Override the cached storefront language for the current request.
	 *
	 * @param string $language_code Routed language code.
	 * @return void
	 */
	public static function set_current_language( $language_code ) {
		$language_code = sanitize_key( (string) $language_code );

		if ( '' === $language_code || ! self::is_valid_routed_language( $language_code ) ) {
			return;
		}

		self::$current_language_cache         = $language_code;
		self::$is_translated_request_cache    = null;
	}

	/**
	 * Rebuild a URL from wp_parse_url() parts.
	 *
	 * @param array<string, mixed> $parts Parsed URL components.
	 * @return string
	 */
	private static function build_url_from_parts( array $parts ) {
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$user   = $parts['user'] ?? '';
		$pass   = isset( $parts['pass'] ) ? ':' . $parts['pass'] : '';
		$pass   = ( $user || $pass ) ? $pass . '@' : '';
		$host   = $parts['host'] ?? '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path   = $parts['path'] ?? '';
		$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$frag   = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return $scheme . $user . $pass . $host . $port . $path . $query . $frag;
	}

	/**
	 * Register rewrite rules for each enabled routed language.
	 *
	 * @return void
	 */
	public function register_rewrite_rules() {
		Language_Registry::maybe_seed_defaults();

		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([^&]+)' );
		add_rewrite_tag( '%' . self::PATH_VAR . '%', '(.+)' );

		foreach ( Language_Registry::get_routed_languages() as $language ) {
			$prefix = preg_quote( (string) $language['url_prefix'], '#' );
			$code   = (string) $language['code'];

			$home_query = self::QUERY_VAR . '=' . $code;
			$front_page_id = self::get_static_front_page_id();

			if ( $front_page_id > 0 ) {
				$home_query .= '&page_id=' . $front_page_id;
			}

			add_rewrite_rule(
				'^' . $prefix . '/?$',
				'index.php?' . $home_query,
				'top'
			);

			add_rewrite_rule(
				'^' . $prefix . '/(.+?)/?$',
				'index.php?' . self::QUERY_VAR . '=' . $code . '&' . self::PATH_VAR . '=$matches[1]',
				'top'
			);
		}
	}

	/**
	 * Flush rewrite rules when the static front page configuration changes.
	 *
	 * @return void
	 */
	public function flush_rewrite_rules_on_front_page_change() {
		$this->register_rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Return the published static front page ID, or 0 when the blog index is used.
	 *
	 * @return int
	 */
	private static function get_static_front_page_id() {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return 0;
		}

		$front_page_id = absint( get_option( 'page_on_front' ) );

		if ( $front_page_id <= 0 || 'publish' !== get_post_status( $front_page_id ) ) {
			return 0;
		}

		return $front_page_id;
	}

	/**
	 * Expose custom query vars to WordPress.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function register_query_vars( array $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::PATH_VAR;

		return $vars;
	}

	/**
	 * Resolve the stripped path after a language prefix into standard WP query vars.
	 *
	 * @param \WP $wp WordPress environment instance.
	 * @return void
	 */
	public function resolve_language_request( $wp ) {
		$lang_code = isset( $wp->query_vars[ self::QUERY_VAR ] )
			? sanitize_key( (string) $wp->query_vars[ self::QUERY_VAR ] )
			: '';

		if ( '' === $lang_code || ! self::is_valid_routed_language( $lang_code ) ) {
			return;
		}

		self::$current_language_cache = $lang_code;

		$path = isset( $wp->query_vars[ self::PATH_VAR ] )
			? trim( (string) $wp->query_vars[ self::PATH_VAR ], '/' )
			: '';

		if ( isset( $wp->query_vars[ self::PATH_VAR ] ) ) {
			unset( $wp->query_vars[ self::PATH_VAR ] );
		}

		if ( '' === $path ) {
			$this->apply_translated_homepage_query_vars( $wp );
			return;
		}

		$resolved = $this->path_to_query_vars( $path );

		if ( isset( $resolved['product'] ) || ( isset( $resolved['post_type'] ) && 'product' === $resolved['post_type'] ) ) {
			unset( $wp->query_vars['pagename'], $wp->query_vars['page_id'], $wp->query_vars['name'] );
		}

		foreach ( $resolved as $key => $value ) {
			if ( self::QUERY_VAR !== $key ) {
				$wp->query_vars[ $key ] = $value;
			}
		}

		unset( $wp->query_vars['error'] );
	}

	/**
	 * Ensure translated language roots load the static front page instead of the blog index.
	 *
	 * @param \WP $wp WordPress environment instance.
	 * @return void
	 */
	private function apply_translated_homepage_query_vars( $wp ) {
		$front_page_id = self::get_static_front_page_id();

		if ( $front_page_id <= 0 ) {
			return;
		}

		if ( ! empty( $wp->query_vars['page_id'] ) || ! empty( $wp->query_vars['pagename'] ) ) {
			return;
		}

		$wp->query_vars['page_id'] = $front_page_id;

		unset( $wp->query_vars['name'], $wp->query_vars['p'] );
	}

	/**
	 * Current active language code (default language when no prefix is used).
	 *
	 * @return string
	 */
	public static function get_current_language() {
		if ( null !== self::$current_language_cache ) {
			return self::$current_language_cache;
		}

		if ( self::$resolving_language ) {
			return Language_Registry::get_default_language_code();
		}

		self::$resolving_language = true;

		$from_uri = self::detect_language_from_request_uri();

		if ( '' !== $from_uri && self::is_valid_routed_language( $from_uri ) ) {
			self::$current_language_cache = $from_uri;
			self::$resolving_language      = false;

			return self::$current_language_cache;
		}

		$detected = self::detect_language_code();

		if ( '' !== $detected && self::is_valid_routed_language( $detected ) ) {
			self::$current_language_cache = $detected;
			self::$resolving_language      = false;

			return $detected;
		}

		/**
		 * Filter the detected language code before falling back to default.
		 *
		 * @param string $language_code Detected code or empty string.
		 */
		$filtered = apply_filters( 'polymart_ai_current_language', '' );

		if ( is_string( $filtered ) && '' !== $filtered && self::is_valid_routed_language( $filtered ) ) {
			self::$current_language_cache = sanitize_key( $filtered );
			self::$resolving_language      = false;

			return self::$current_language_cache;
		}

		// Backward compatibility with polymart_ai_is_english filter.
		if ( (bool) apply_filters( 'polymart_ai_is_english', false ) ) {
			self::$current_language_cache = 'en';
			self::$resolving_language      = false;

			return 'en';
		}

		self::$current_language_cache = Language_Registry::get_default_language_code();
		self::$resolving_language      = false;

		return self::$current_language_cache;
	}

	/**
	 * Site home URL from the `home` option (avoids home_url / permalink filter recursion).
	 *
	 * @return string
	 */
	public static function get_site_home_url() {
		if ( null !== self::$site_home_url_cache ) {
			return self::$site_home_url_cache;
		}

		$home = untrailingslashit( (string) get_option( 'home' ) );

		if ( '' === $home && function_exists( 'home_url' ) ) {
			$home = untrailingslashit( (string) home_url( '/' ) );
		}

		self::$site_home_url_cache = $home;

		return self::$site_home_url_cache;
	}

	/**
	 * Seed the language cache from the request URI before WP query resolution.
	 *
	 * Prevents recursive permalink filtering from exhausting memory on /en/ URLs.
	 *
	 * @return void
	 */
	public static function bootstrap_current_language_from_request() {
		if ( null !== self::$current_language_cache ) {
			return;
		}

		$from_uri = self::detect_language_from_request_uri();

		if ( '' !== $from_uri && self::is_valid_routed_language( $from_uri ) ) {
			self::$current_language_cache = $from_uri;

			return;
		}

		/*
		 * Unprefixed storefront URLs (/shop/, /, etc.) are the default language.
		 * Do not let a stale polymart_ai_lang cookie from /en/ or /ar/ override them.
		 */
		if ( self::is_unprefixed_storefront_request() ) {
			self::$current_language_cache = Language_Registry::get_default_language_code();

			return;
		}

		/*
		 * wc-ajax / admin-ajax omit language prefixes. Prefer live page hints injected
		 * by language-context.js (query arg + header + referer) over a stale cookie
		 * left from a previous /en/ visit while the user is browsing /ar/.
		 */
		if ( self::should_detect_language_from_referer() ) {
			$from_query = self::detect_language_from_query_var();

			if ( '' !== $from_query && self::is_valid_routed_language( $from_query ) ) {
				self::$current_language_cache = $from_query;

				return;
			}

			$from_header = self::detect_language_from_header();

			if ( '' !== $from_header && self::is_valid_routed_language( $from_header ) ) {
				self::$current_language_cache = $from_header;

				return;
			}

			$from_referer = self::detect_language_from_referer();

			if ( '' !== $from_referer && self::is_valid_routed_language( $from_referer ) ) {
				self::$current_language_cache = $from_referer;

				return;
			}
		}

		$from_cookie = self::detect_language_from_cookie();

		if ( '' !== $from_cookie && self::is_valid_routed_language( $from_cookie ) ) {
			self::$current_language_cache = $from_cookie;

			return;
		}

		if ( self::should_detect_language_from_referer() ) {
			return;
		}

		$from_query = self::detect_language_from_query_var();

		if ( '' !== $from_query && self::is_valid_routed_language( $from_query ) ) {
			self::$current_language_cache = $from_query;

			return;
		}

		$from_header = self::detect_language_from_header();

		if ( '' !== $from_header && self::is_valid_routed_language( $from_header ) ) {
			self::$current_language_cache = $from_header;
		}
	}

	/**
	 * Read polymart_lang from the query string.
	 *
	 * @return string
	 */
	private static function detect_language_from_query_var() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing hint.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return '';
		}

		return sanitize_key( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) );
	}

	/**
	 * Read the explicit storefront language header from AJAX clients.
	 *
	 * @return string
	 */
	private static function detect_language_from_header() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		if ( empty( $_SERVER['HTTP_X_POLYMART_LANG'] ) ) {
			return '';
		}

		return sanitize_key( wp_unslash( (string) $_SERVER['HTTP_X_POLYMART_LANG'] ) );
	}

	/**
	 * Cached result of is_translated_request() for the current request.
	 *
	 * @var bool|null
	 */
	private static $is_translated_request_cache = null;

	/**
	 * Whether the current request serves a non-default translated language.
	 *
	 * @return bool
	 */
	public static function is_translated_request() {
		if ( null !== self::$is_translated_request_cache ) {
			return self::$is_translated_request_cache;
		}

		self::$is_translated_request_cache = self::get_current_language() !== Language_Registry::get_default_language_code();

		return self::$is_translated_request_cache;
	}

	/**
	 * Determine whether the current request is serving English content.
	 *
	 * @return bool
	 */
	public static function is_english() {
		return 'en' === self::get_current_language();
	}

	/**
	 * Resolve polymart_lang without requiring WP_Query.
	 *
	 * @return string Language code, or empty string when unknown.
	 */
	private static function detect_language_code() {
		global $wp, $wp_query;

		if ( isset( $wp ) && is_object( $wp ) && ! empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return sanitize_key( (string) $wp->query_vars[ self::QUERY_VAR ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing hint.
		if ( isset( $_GET[ self::QUERY_VAR ] ) ) {
			return sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		}

		// Explicit header for AJAX/REST clients (e.g. Customer Portal React).
		if ( ! empty( $_SERVER['HTTP_X_POLYMART_LANG'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
			return sanitize_key( wp_unslash( (string) $_SERVER['HTTP_X_POLYMART_LANG'] ) );
		}

		$from_uri = self::detect_language_from_request_uri();

		if ( '' !== $from_uri ) {
			return $from_uri;
		}

		if ( self::is_unprefixed_storefront_request() ) {
			return Language_Registry::get_default_language_code();
		}

		if ( self::should_detect_language_from_referer() ) {
			$from_referer = self::detect_language_from_referer();

			if ( '' !== $from_referer ) {
				return $from_referer;
			}
		}

		$from_cookie = self::detect_language_from_cookie();

		if ( '' !== $from_cookie ) {
			return $from_cookie;
		}

		if ( $wp_query instanceof \WP_Query ) {
			return sanitize_key( (string) get_query_var( self::QUERY_VAR ) );
		}

		return '';
	}

	/**
	 * Whether the current request is a sub-request that omits the language prefix.
	 *
	 * @return bool
	 */
	private static function should_detect_language_from_referer() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] );

		return false !== strpos( $uri, '/wp-json/' )
			|| false !== strpos( $uri, 'admin-ajax.php' )
			|| false !== strpos( $uri, 'wc-ajax=' )
			|| false !== strpos( $uri, '/wp-admin/admin-ajax.php' );
	}

	/**
	 * Whether the current HTTP request is a normal unprefixed storefront page load.
	 *
	 * Such URLs map to the default language (no /en/ or /ar/ segment). The language
	 * cookie is only for AJAX sub-requests and must not override full navigations.
	 *
	 * @return bool
	 */
	private static function is_unprefixed_storefront_request() {
		if ( self::should_detect_language_from_referer() ) {
			return false;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		if ( wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		if ( '' !== self::detect_language_from_request_uri() ) {
			return false;
		}

		if ( '' !== self::detect_language_from_query_var() ) {
			return false;
		}

		if ( ! empty( $_SERVER['HTTP_X_POLYMART_LANG'] ) ) {
			return false;
		}

		return isset( $_SERVER['REQUEST_URI'] );
	}

	/**
	 * Match the request URI against configured language prefixes.
	 *
	 * @return string
	 */
	private static function detect_language_from_request_uri() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );

		return is_string( $path ) ? self::detect_language_from_path( $path ) : '';
	}

	/**
	 * Match the persisted storefront language cookie.
	 *
	 * @return string
	 */
	private static function detect_language_from_cookie() {
		$cookie_name = \PolymartAI\Frontend\Storefront_Language_Persistence::COOKIE_NAME;

		if ( empty( $_COOKIE[ $cookie_name ] ) ) {
			return '';
		}

		$code = sanitize_key( wp_unslash( (string) $_COOKIE[ $cookie_name ] ) );

		return self::is_valid_routed_language( $code ) ? $code : '';
	}

	/**
	 * Whether a language code is enabled (routed or default-only).
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	public static function is_valid_language_code( $code ) {
		$code = sanitize_key( (string) $code );

		if ( '' === $code ) {
			return false;
		}

		foreach ( Language_Registry::get_enabled_languages() as $language ) {
			if ( (string) ( $language['code'] ?? '' ) === $code ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Match the HTTP referer against configured language prefixes.
	 *
	 * Used for admin-ajax.php and REST requests that do not carry /en/ in their own URL.
	 *
	 * @return string
	 */
	private static function detect_language_from_referer() {
		$referer = '';

		if ( function_exists( 'wp_get_raw_referer' ) ) {
			$referer = (string) wp_get_raw_referer();
		} elseif ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path only, sanitized below.
			$referer = (string) wp_unslash( $_SERVER['HTTP_REFERER'] );
		}

		if ( '' === $referer ) {
			return '';
		}

		$path = wp_parse_url( $referer, PHP_URL_PATH );

		return is_string( $path ) ? self::detect_language_from_path( $path ) : '';
	}

	/**
	 * Match a URL path against configured language prefixes.
	 *
	 * @param string $path URL path.
	 * @return string
	 */
	private static function detect_language_from_path( $path ) {
		$path = (string) $path;

		if ( '' === $path ) {
			return '';
		}

		$home_path = wp_parse_url( self::get_site_home_url(), PHP_URL_PATH );

		if ( is_string( $home_path ) && '' !== $home_path && '/' !== $home_path ) {
			$home_path = untrailingslashit( $home_path );

			if ( 0 === strpos( $path, $home_path ) ) {
				$path = substr( $path, strlen( $home_path ) );
			}
		}

		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return '';
		}

		foreach ( Language_Registry::get_routed_languages() as $language ) {
			$prefix = (string) $language['url_prefix'];

			if ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) ) {
				return (string) $language['code'];
			}
		}

		return '';
	}

	/**
	 * Whether a language code is enabled and routed.
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	private static function is_valid_routed_language( $code ) {
		$code = sanitize_key( (string) $code );

		foreach ( Language_Registry::get_routed_languages() as $language ) {
			if ( $language['code'] === $code ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert a permalink path (without language prefix) into WordPress query variables.
	 *
	 * @param string $path Request path relative to site root.
	 * @return array<string, string|int>
	 */
	private function path_to_query_vars( $path ) {
		$product_query = $this->path_to_product_query_vars( $path );

		if ( ! empty( $product_query ) ) {
			return $product_query;
		}

		$rewrite_query = $this->path_to_rewrite_query_vars( $path );

		if ( ! empty( $rewrite_query ) ) {
			return $rewrite_query;
		}

		$post_id = url_to_postid( home_url( '/' . $path . '/' ) );

		if ( $post_id ) {
			$post = get_post( $post_id );

			if ( $post instanceof \WP_Post ) {
				return $this->post_to_query_vars( $post );
			}
		}

		$page = get_page_by_path( $path );

		if ( $page instanceof \WP_Post ) {
			return array(
				'page_id' => $page->ID,
			);
		}

		$term_query = $this->path_to_term_query_vars( $path );

		if ( ! empty( $term_query ) ) {
			return $term_query;
		}

		$slug = (string) basename( $path );

		foreach ( array( 'product', 'post' ) as $post_type ) {
			$posts = get_posts(
				array(
					'name'           => $slug,
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);

			if ( ! empty( $posts ) ) {
				$post = get_post( $posts[0] );

				if ( $post instanceof \WP_Post ) {
					return $this->post_to_query_vars( $post );
				}
			}
		}

		return array(
			'pagename' => $path,
		);
	}

	/**
	 * Resolve the stripped path through WordPress' own rewrite table.
	 *
	 * This keeps translated URLs compatible with WooCommerce/custom permalink
	 * structures instead of rebuilding product query vars by hand.
	 *
	 * @param string $path Request path without language prefix.
	 * @return array<string, string|int>
	 */
	private function path_to_rewrite_query_vars( $path ) {
		global $wp_rewrite;

		$path = trim( (string) $path, '/' );

		if ( '' === $path || ! $wp_rewrite instanceof \WP_Rewrite ) {
			return array();
		}

		$rewrite_rules = $wp_rewrite->wp_rewrite_rules();

		if ( empty( $rewrite_rules ) || ! is_array( $rewrite_rules ) ) {
			return array();
		}

		foreach ( $rewrite_rules as $match => $query ) {
			if ( ! is_string( $match ) || ! is_string( $query ) ) {
				continue;
			}

			if ( ! preg_match( '#^' . $match . '#', $path, $matches ) ) {
				continue;
			}

			$query = preg_replace_callback(
				'/\$matches\[(\d+)\]/',
				static function ( $placeholder ) use ( $matches ) {
					$index = absint( $placeholder[1] );

					return isset( $matches[ $index ] ) ? urlencode( $matches[ $index ] ) : '';
				},
				$query
			);

			$query = preg_replace( '#^index\.php\??#', '', (string) $query );

			if ( '' === $query ) {
				continue;
			}

			parse_str( $query, $query_vars );

			if ( empty( $query_vars ) || ! is_array( $query_vars ) ) {
				continue;
			}

			unset( $query_vars[ self::QUERY_VAR ], $query_vars[ self::PATH_VAR ] );

			if ( $this->is_unreliable_pagename_match( $query_vars, $path ) ) {
				continue;
			}

			return $query_vars;
		}

		return array();
	}

	/**
	 * Resolve WooCommerce product paths before generic pagename rewrite rules.
	 *
	 * @param string $path Request path without language prefix.
	 * @return array<string, string|int>
	 */
	private function path_to_product_query_vars( $path ) {
		$path = trim( (string) $path, '/' );

		if ( '' === $path || ! preg_match( '#^product/(.+)$#', $path, $product_match ) ) {
			return array();
		}

		$product_slug = (string) basename( $product_match[1] );
		$product_id   = self::find_published_post_id_by_slug( $product_slug, 'product' );

		if ( $product_id <= 0 ) {
			return array();
		}

		$post = get_post( $product_id );

		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		return $this->post_to_query_vars( $post );
	}

	/**
	 * Detect pagename rewrite matches that should not win over CPT routes.
	 *
	 * @param array<string, string|int> $query_vars Parsed rewrite query vars.
	 * @param string                    $path       Request path without language prefix.
	 * @return bool
	 */
	private function is_unreliable_pagename_match( array $query_vars, $path ) {
		if ( ! isset( $query_vars['pagename'] ) || isset( $query_vars['product'] ) ) {
			return false;
		}

		$pagename = trim( (string) $query_vars['pagename'], '/' );

		if ( preg_match( '#^product/#', $pagename ) || preg_match( '#^product/#', trim( (string) $path, '/' ) ) ) {
			return true;
		}

		if ( count( $query_vars ) > 1 ) {
			return false;
		}

		return ! ( get_page_by_path( $pagename ) instanceof \WP_Post );
	}

	/**
	 * Convert taxonomy archive paths into WordPress query variables.
	 *
	 * @param string $path Request path without language prefix.
	 * @return array<string, string>
	 */
	private function path_to_term_query_vars( $path ) {
		$path = trim( (string) $path, '/' );

		if ( '' === $path ) {
			return array();
		}

		$known_bases = array(
			'product_cat' => array( 'product-category' ),
			'product_tag' => array( 'product-tag' ),
			'category'    => array( trim( (string) get_option( 'category_base' ), '/' ), 'category' ),
			'post_tag'    => array( trim( (string) get_option( 'tag_base' ), '/' ), 'tag' ),
		);

		foreach ( Post_Translator::SUPPORTED_TAXONOMIES as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$taxonomy_object = get_taxonomy( $taxonomy );
			$bases           = isset( $known_bases[ $taxonomy ] ) ? $known_bases[ $taxonomy ] : array();

			if ( is_object( $taxonomy_object ) && ! empty( $taxonomy_object->rewrite['slug'] ) ) {
				$bases[] = trim( (string) $taxonomy_object->rewrite['slug'], '/' );
			}

			$bases = array_filter( array_unique( $bases ) );

			foreach ( $bases as $base ) {
				if ( '' === $base ) {
					continue;
				}

				if ( $path === $base || 0 !== strpos( $path, $base . '/' ) ) {
					continue;
				}

				$term_path = trim( substr( $path, strlen( $base ) ), '/' );
				$term_slug = urldecode( (string) basename( $term_path ) );
				$term      = get_term_by( 'slug', $term_slug, $taxonomy );

				if ( '' === $term_slug || ! ( $term instanceof \WP_Term ) ) {
					continue;
				}

				return $this->term_to_query_vars( $taxonomy, $term_slug, $term_path );
			}
		}

		return array();
	}

	/**
	 * Map taxonomy and slug to the public query var WordPress expects.
	 *
	 * @param string $taxonomy  Taxonomy slug.
	 * @param string $term_slug Term slug.
	 * @param string $term_path Full hierarchical term path after base.
	 * @return array<string, string>
	 */
	private function term_to_query_vars( $taxonomy, $term_slug, $term_path ) {
		if ( 'category' === $taxonomy ) {
			return array(
				'category_name' => $term_path,
			);
		}

		if ( 'post_tag' === $taxonomy ) {
			return array(
				'tag' => $term_slug,
			);
		}

		return array(
			$taxonomy => $term_slug,
		);
	}

	/**
	 * Find a published post ID by slug.
	 *
	 * @param string $slug      Post slug.
	 * @param string $post_type Post type slug.
	 * @return int
	 */
	private static function find_published_post_id_by_slug( $slug, $post_type ) {
		$slug = trim( (string) $slug );

		if ( '' === $slug ) {
			return 0;
		}

		$candidates = array_values(
			array_unique(
				array_filter(
					array(
						$slug,
						urldecode( $slug ),
						rawurldecode( $slug ),
						sanitize_title( urldecode( $slug ) ),
						sanitize_title( rawurldecode( $slug ) ),
					)
				)
			)
		);

		foreach ( $candidates as $candidate ) {
			$post = get_page_by_path( $candidate, OBJECT, $post_type );

			if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
				return (int) $post->ID;
			}

			$posts = get_posts(
				array(
					'name'           => $candidate,
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);

			if ( ! empty( $posts ) ) {
				return (int) $posts[0];
			}
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $candidates ), '%s' ) );
		$query_args   = array_merge( array( $post_type ), $candidates );
		$post_id      = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_name IN ({$placeholders}) ORDER BY ID DESC LIMIT 1",
				$query_args
			)
		);

		if ( $post_id ) {
			return (int) $post_id;
		}

		return 0;
	}

	/**
	 * Map a WP_Post object to primary query vars.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<string, string|int>
	 */
	private function post_to_query_vars( \WP_Post $post ) {
		if ( 'page' === $post->post_type ) {
			return array(
				'page_id' => $post->ID,
			);
		}

		if ( 'product' === $post->post_type ) {
			return array(
				'post_type' => 'product',
				'p'         => $post->ID,
				'name'      => $post->post_name,
				'product'   => $post->post_name,
			);
		}

		return array(
			'name'      => $post->post_name,
			'post_type' => $post->post_type,
		);
	}
}
