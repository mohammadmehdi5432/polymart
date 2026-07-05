<?php
/**
 * Universal string and Elementor JSON translator for translated storefront URLs.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation;

use PolymartAI\Frontend\Storefront_Script_Guard;
use PolymartAI\Routing\Url_Router;

defined( 'ABSPATH' ) || exit;

/**
 * Class Universal_Translator
 */
final class Universal_Translator {

	/**
	 * Elementor settings keys that may hold user-facing Persian text.
	 *
	 * @var array<string, true>
	 */
	private static $elementor_text_keys = array(
		'story_text'         => true,
		'button_text'        => true,
		'customer_label'     => true,
		'partner_label'      => true,
		'search_placeholder' => true,
		'search_brand_name'  => true,
		'no_results_message' => true,
		'title'              => true,
		'editor'             => true,
		'text'               => true,
		'heading'            => true,
		'subtitle'           => true,
		'btn_text'           => true,
		'link_text'          => true,
		'placeholder'        => true,
		'html'               => true,
		'label_description'  => true,
		'label_attributes'   => true,
		'label_video'        => true,
		'label_customer_home' => true,
		'label_satisfaction' => true,
	);

	/**
	 * Cached result of should_translate() for the current request.
	 *
	 * @var bool|null
	 */
	private $translate_cache = null;

	/**
	 * Cached result of should_skip_gettext() for the current request.
	 *
	 * @var bool|null
	 */
	private $gettext_skip_cache = null;

	/**
	 * Per-request cache of translated Elementor JSON keyed by post ID.
	 *
	 * @var array<int, string>
	 */
	private $elementor_cache = array();

	/**
	 * Cache sentinel when Elementor JSON did not require translation.
	 */
	private const ELEMENTOR_CACHE_UNCHANGED = '__polymart_elementor_unchanged__';

	/**
	 * Re-entrancy guard for gettext filters during Elementor/WooCommerce render.
	 *
	 * @var int
	 */
	private $gettext_depth = 0;

	/**
	 * Re-entrancy guard for Elementor metadata interception.
	 *
	 * @var int
	 */
	private static $elementor_metadata_depth = 0;

	/**
	 * Whether embedded cms_block Elementor swapping is registered.
	 *
	 * @var bool
	 */
	private static $embedded_elementor_registered = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'gettext', array( $this, 'filter_gettext' ), 20, 3 );
		add_filter( 'gettext_with_context', array( $this, 'filter_gettext_with_context' ), 20, 4 );
		add_filter( 'ngettext', array( $this, 'filter_ngettext' ), 20, 5 );

		// Register after the main query is resolved so product/layout context is reliable.
		add_action( 'template_redirect', array( $this, 'maybe_register_elementor_metadata_filter' ), 1 );
		add_action( 'template_redirect', array( $this, 'maybe_register_embedded_elementor_filter' ), 1 );
		add_action( 'init', array( $this, 'maybe_register_embedded_elementor_filter' ), 25 );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_script_locale_data' ), 10000 );
		add_action( 'wp_enqueue_scripts', array( $this, 'patch_companion_localized_strings' ), 10001 );

		add_filter( 'polymart_ai_apd_widget_label', array( $this, 'filter_apd_widget_label' ), 10, 3 );
		add_filter( 'polymart_ai_apd_widget_text', array( $this, 'filter_apd_widget_label' ), 10, 3 );

		add_action( 'apd_before_capture_product_data', array( $this, 'begin_apd_product_snapshot' ), 0 );
		add_action( 'apd_after_capture_product_data', array( $this, 'end_apd_product_snapshot' ), 0 );

		if ( function_exists( 'acf' ) ) {
			add_filter( 'acf/format_value', array( $this, 'filter_acf_value' ), 20, 3 );
		}
	}

	/**
	 * Cached queried object ID for Elementor metadata swapping on this request.
	 *
	 * @var int|null
	 */
	private $elementor_main_post_id = null;

	/**
	 * Register `_elementor_data` interception on translated storefront URLs.
	 *
	 * Only hooks when the main queried post already has a validated stored
	 * Elementor companion from admin translation. Never runs on single-product
	 * pages (Woodmart layout shell) or for nested cms_block templates.
	 *
	 * @return void
	 */
	public function maybe_register_elementor_metadata_filter() {
		if ( ! Url_Router::is_translated_request() || is_admin() ) {
			return;
		}

		if ( Layout_Guard::is_single_product_context() || ! is_singular() ) {
			return;
		}

		/**
		 * Filter whether `_elementor_data` may be swapped for a stored companion JSON.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'polymart_ai_enable_elementor_json_swap', true ) ) {
			return;
		}

		$post_id = get_queried_object_id();

		if ( $post_id <= 0 || Layout_Guard::should_preserve_elementor_metadata( $post_id ) ) {
			return;
		}

		$lang = Url_Router::get_current_language();

		if ( ! Post_Translator::can_serve_stored_elementor_json_on_storefront( $post_id, $lang ) ) {
			return;
		}

		$this->elementor_main_post_id = $post_id;

		add_filter( 'get_post_metadata', array( $this, 'filter_elementor_metadata' ), 10, 4 );
	}

	/**
	 * Register `_elementor_data` swapping for embedded cms_block / popup templates.
	 *
	 * Header/footer HTML blocks load Elementor JSON by post ID, not via the main query.
	 *
	 * @return void
	 */
	public function maybe_register_embedded_elementor_filter() {
		if ( self::$embedded_elementor_registered || ! Url_Router::is_translated_request() ) {
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		self::$embedded_elementor_registered = true;

		add_filter( 'get_post_metadata', array( $this, 'filter_embedded_elementor_metadata' ), 10, 4 );
	}

	/**
	 * Serve pre-translated gettext strings from the UI string registry.
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Original (msgid) string.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filter_gettext( $translation, $text, $domain ) {
		if ( $this->gettext_depth > 0 ) {
			return $translation;
		}

		if ( $this->should_skip_gettext() ) {
			return $translation;
		}

		if ( ! UI_String_Registry::is_allowed_domain( $domain ) ) {
			return $translation;
		}

		if ( ! Url_Router::is_translated_request() ) {
			return $translation;
		}

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return $translation;
		}

		++$this->gettext_depth;

		try {
			$resolved = $this->resolve_registry_string( $text, '', $translation );

			if ( null !== $resolved ) {
				return $resolved;
			}
		} finally {
			--$this->gettext_depth;
		}

		return $translation;
	}

	/**
	 * Serve contextual gettext strings from the registry.
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Original (msgid) string.
	 * @param string $context     Translation context.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filter_gettext_with_context( $translation, $text, $context, $domain ) {
		if ( $this->gettext_depth > 0 ) {
			return $translation;
		}

		if ( $this->should_skip_gettext() ) {
			return $translation;
		}

		if ( ! UI_String_Registry::is_allowed_domain( $domain ) ) {
			return $translation;
		}

		if ( ! Url_Router::is_translated_request() ) {
			return $translation;
		}

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return $translation;
		}

		++$this->gettext_depth;

		try {
			$resolved = $this->resolve_registry_string(
				$text,
				is_string( $context ) ? $context : '',
				$translation
			);

			if ( null !== $resolved ) {
				return $resolved;
			}
		} finally {
			--$this->gettext_depth;
		}

		return $translation;
	}

	/**
	 * Serve plural gettext strings from the registry.
	 *
	 * @param string $translation Current translation.
	 * @param string $single      Singular form.
	 * @param string $plural      Plural form.
	 * @param int    $number      Item count.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filter_ngettext( $translation, $single, $plural, $number, $domain ) {
		if ( $this->gettext_depth > 0 ) {
			return $translation;
		}

		if ( $this->should_skip_gettext() ) {
			return $translation;
		}

		if ( ! UI_String_Registry::is_allowed_domain( $domain ) ) {
			return $translation;
		}

		if ( ! Url_Router::is_translated_request() ) {
			return $translation;
		}

		$form = ( 1 === (int) $number ) ? $single : $plural;

		if ( ! is_string( $form ) || '' === trim( $form ) ) {
			return $translation;
		}

		++$this->gettext_depth;

		try {
			$resolved = $this->resolve_registry_string( $form, '', $translation );

			if ( null !== $resolved ) {
				return $resolved;
			}
		} finally {
			--$this->gettext_depth;
		}

		return $translation;
	}

	/**
	 * Resolve one gettext string: stored registry hit first, then runtime
	 * cache with background AI queueing for untranslated Persian strings.
	 *
	 * Companion plugins (e.g. Advanced Product Description) register Persian
	 * msgids under the polymart-ai domain. When a string was never scanned or
	 * bulk-translated it previously stayed Persian forever; now it enters the
	 * pending queue and is served from cache on the next request.
	 *
	 * @param string $text        Source msgid (or plural form).
	 * @param string $context     Optional msgctxt.
	 * @param string $translation Current translation from loaded .mo files.
	 * @return string|null Translated string, or null to keep the current translation.
	 */
	private function resolve_registry_string( $text, $context, $translation ) {
		if ( ! is_string( $text ) || strlen( $text ) > 1000 ) {
			return null;
		}

		$lang = Url_Router::get_current_language();
		$resolved = Storefront_Gettext_Resolver::resolve(
			is_string( $translation ) ? $translation : $text,
			$text,
			is_string( $context ) ? $context : '',
			$lang,
			'ui_gettext:',
			'ui_gettext:' . md5( $context . '|' . $text )
		);

		$source = is_string( $translation ) && '' !== trim( $translation ) ? $translation : $text;

		return $resolved !== $source ? $resolved : null;
	}

	/**
	 * Translate ACF field values on translated storefront URLs.
	 *
	 * @param mixed $value   Field value.
	 * @param int   $post_id Post ID.
	 * @param array $field   Field config.
	 * @return mixed
	 */
	public function filter_acf_value( $value, $post_id, $field ) {
		unset( $field );

		if ( Layout_Guard::should_preserve_post_data( $post_id ) ) {
			return $value;
		}

		if ( ! $this->should_translate() || ! is_string( $value ) || ! Persian_Detector::contains_persian( $value ) ) {
			return $value;
		}

		return Runtime_String_Translator::translate(
			$value,
			Url_Router::get_current_language(),
			'acf:' . md5( $value )
		);
	}

	/**
	 * Serve pre-translated Elementor JSON for the main queried post only.
	 *
	 * Storefront requests must never live-walk, json_decode, or reload the Persian
	 * source document on every `_elementor_data` read — that duplicated multi-megabyte
	 * payloads and exhausted memory on Elementor pages with nested templates.
	 *
	 * @param mixed  $value     Short-circuit value (null = fetch from DB).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Requested meta key.
	 * @param bool   $single    Whether a single value was requested.
	 * @return mixed
	 */
	public function filter_elementor_metadata( $value, $object_id, $meta_key, $single ) {
		if ( '_elementor_data' !== $meta_key || ! $single ) {
			return $value;
		}

		$post_id = (int) $object_id;
		$main_id = null !== $this->elementor_main_post_id ? (int) $this->elementor_main_post_id : get_queried_object_id();

		if ( $post_id <= 0 || $main_id <= 0 || $post_id !== $main_id || null !== $value ) {
			return $value;
		}

		if ( array_key_exists( $post_id, $this->elementor_cache ) ) {
			if ( self::ELEMENTOR_CACHE_UNCHANGED === $this->elementor_cache[ $post_id ] ) {
				return $value;
			}

			return $this->elementor_cache[ $post_id ];
		}

		if ( self::$elementor_metadata_depth > 0 ) {
			return $value;
		}

		++self::$elementor_metadata_depth;

		remove_filter( 'get_post_metadata', array( $this, 'filter_elementor_metadata' ), 10 );

		try {
			$lang = Url_Router::get_current_language();

			if ( ! Post_Translator::can_serve_stored_elementor_json_on_storefront( $post_id, $lang ) ) {
				$this->elementor_cache[ $post_id ] = self::ELEMENTOR_CACHE_UNCHANGED;

				return $value;
			}

			$stored = Post_Translator::get_stored_elementor_json( $post_id, $lang );

			if ( ! is_string( $stored ) || '' === $stored ) {
				$this->elementor_cache[ $post_id ] = self::ELEMENTOR_CACHE_UNCHANGED;

				return $value;
			}

			if ( strlen( $stored ) > Post_Translator::get_max_storefront_elementor_json_bytes() ) {
				$this->elementor_cache[ $post_id ] = self::ELEMENTOR_CACHE_UNCHANGED;

				return $value;
			}

			$this->elementor_cache[ $post_id ] = $stored;

			return $stored;
		} finally {
			--self::$elementor_metadata_depth;
			add_filter( 'get_post_metadata', array( $this, 'filter_elementor_metadata' ), 10, 4 );
		}
	}

	/**
	 * Serve stored Elementor JSON for embedded cms_block / popup documents.
	 *
	 * @param mixed  $value     Short-circuit value (null = fetch from DB).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Requested meta key.
	 * @param bool   $single    Whether a single value was requested.
	 * @return mixed
	 */
	public function filter_embedded_elementor_metadata( $value, $object_id, $meta_key, $single ) {
		if ( '_elementor_data' !== $meta_key || ! $single || null !== $value ) {
			return $value;
		}

		$post_id = (int) $object_id;

		if ( $post_id <= 0 || Layout_Guard::should_preserve_post_data( $post_id ) ) {
			return $value;
		}

		$post_type = get_post_type( $post_id );

		if ( ! in_array( $post_type, array( 'cms_block', 'wd_popup' ), true ) ) {
			return $value;
		}

		if ( array_key_exists( $post_id, $this->elementor_cache ) ) {
			if ( self::ELEMENTOR_CACHE_UNCHANGED === $this->elementor_cache[ $post_id ] ) {
				return $value;
			}

			return $this->elementor_cache[ $post_id ];
		}

		if ( self::$elementor_metadata_depth > 0 ) {
			return $value;
		}

		++self::$elementor_metadata_depth;

		remove_filter( 'get_post_metadata', array( $this, 'filter_embedded_elementor_metadata' ), 10 );

		try {
			$lang = Url_Router::get_current_language();

			if ( ! Post_Translator::can_serve_stored_elementor_json_on_storefront( $post_id, $lang ) ) {
				$this->elementor_cache[ $post_id ] = self::ELEMENTOR_CACHE_UNCHANGED;

				return $value;
			}

			$stored = Post_Translator::get_stored_elementor_json( $post_id, $lang );

			if ( ! is_string( $stored ) || '' === $stored ) {
				$this->elementor_cache[ $post_id ] = self::ELEMENTOR_CACHE_UNCHANGED;

				return $value;
			}

			if ( strlen( $stored ) > Post_Translator::get_max_storefront_elementor_json_bytes() ) {
				$this->elementor_cache[ $post_id ] = self::ELEMENTOR_CACHE_UNCHANGED;

				return $value;
			}

			$this->elementor_cache[ $post_id ] = $stored;

			return $stored;
		} finally {
			--self::$elementor_metadata_depth;
			add_filter( 'get_post_metadata', array( $this, 'filter_embedded_elementor_metadata' ), 10, 4 );
		}
	}

	/**
	 * Recursively translate Elementor widget text from the UI registry / runtime cache.
	 *
	 * @param array<string|int, mixed> $node    Elementor JSON node.
	 * @param int                      $post_id Post ID.
	 * @param string                   $lang    Target language.
	 * @return array<string|int, mixed>
	 */
	private static function translate_elementor_tree( array $node, $post_id, $lang ) {
		foreach ( $node as $key => $item ) {
			if ( is_string( $key ) && isset( self::$elementor_text_keys[ $key ] ) && is_string( $item ) ) {
				if ( Persian_Detector::contains_persian( $item ) ) {
					$node[ $key ] = self::translate_elementor_string( $item, $lang, $post_id, $key );
				}
				continue;
			}

			if ( is_array( $item ) ) {
				$node[ $key ] = self::translate_elementor_tree( $item, $post_id, $lang );
			}
		}

		return $node;
	}

	/**
	 * Resolve one Elementor setting string: UI registry first, then runtime cache/AI.
	 *
	 * @param string $text    Source Persian text.
	 * @param string $lang    Target language.
	 * @param int    $post_id Post ID.
	 * @param string $key     Elementor setting key.
	 * @return string
	 */
	private static function translate_elementor_string( $text, $lang, $post_id, $key ) {
		$from_registry = UI_String_Registry::lookup( $lang, $text, '' );

		if ( null !== $from_registry ) {
			return $from_registry;
		}

		return Runtime_String_Translator::translate(
			$text,
			$lang,
			'elementor:' . $post_id . ':' . $key
		);
	}

	/**
	 * Push UI string registry translations into wp.i18n for JS/React bundles.
	 *
	 * Customer Portal and similar apps call wp.i18n.__( text, 'polymart-ai' ).
	 * PHP gettext filters do not affect that path — locale data must be injected.
	 *
	 * @return void
	 */
	public function enqueue_script_locale_data() {
		static $done = false;

		if ( $done || ! Storefront_Script_Guard::should_inject_i18n_locale_data() ) {
			return;
		}

		$lang   = Url_Router::get_current_language();
		$locale = UI_String_Registry::export_locale_data( $lang, true );

		// Only the domain header means nothing useful to inject.
		if ( count( $locale ) <= 1 ) {
			return;
		}

		$json = wp_json_encode(
			$locale,
			JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		if ( false === $json || strlen( $json ) > 65536 ) {
			return;
		}

		$inline = '(function(){try{if(typeof wp==="undefined"||!wp.i18n||typeof wp.i18n.setLocaleData!=="function"){return;}wp.i18n.setLocaleData('
			. $json
			. ',"polymart-ai");}catch(e){}})();';

		$attached = false;

		foreach ( Storefront_Script_Guard::get_i18n_consumer_handles() as $handle ) {
			if ( ! wp_script_is( $handle, 'enqueued' ) ) {
				continue;
			}

			global $wp_scripts;

			if ( $wp_scripts instanceof \WP_Scripts && isset( $wp_scripts->registered[ $handle ] ) ) {
				$wp_scripts->registered[ $handle ]->deps = array_values(
					array_unique(
						array_merge(
							(array) $wp_scripts->registered[ $handle ]->deps,
							array( 'wp-i18n' )
						)
					)
				);
			}

			wp_enqueue_script( 'wp-i18n' );
			wp_add_inline_script( $handle, $inline, 'before' );
			$attached = true;
		}

		if ( ! $attached && wp_script_is( 'wp-i18n', 'registered' ) ) {
			wp_enqueue_script( 'wp-i18n' );
			wp_add_inline_script( 'wp-i18n', $inline, 'after' );
		}

		$done = true;
	}

	/**
	 * Re-localize companion React bundles after they register wp_localize_script data.
	 *
	 * Advanced Product Description injects UI copy through apdI18n on priority 10.
	 * Our gettext filters usually catch those __() calls, but re-localizing here
	 * guarantees translated strings even when a companion registers late or skips
	 * the registry lookup path.
	 *
	 * @return void
	 */
	public function patch_companion_localized_strings() {
		if ( is_admin() || ! Url_Router::is_translated_request() ) {
			return;
		}

		if ( ! wp_script_is( 'apd-tabs-script', 'enqueued' ) || ! class_exists( '\APD_Frontend_i18n' ) ) {
			return;
		}

		$lang    = Url_Router::get_current_language();
		$strings = \APD_Frontend_i18n::get_strings();

		if ( ! is_array( $strings ) || array() === $strings ) {
			return;
		}

		foreach ( $strings as $key => $text ) {
			if ( ! is_string( $text ) || '' === trim( $text ) ) {
				continue;
			}

			$resolved = $this->resolve_registry_string( $text, '', $text );

			if ( null !== $resolved ) {
				$strings[ $key ] = $resolved;
				continue;
			}

			if ( Persian_Detector::contains_persian( $text ) ) {
				$strings[ $key ] = Runtime_String_Translator::translate(
					$text,
					$lang,
					'apd_i18n:' . sanitize_key( (string) $key )
				);
			}
		}

		wp_localize_script(
			'apd-tabs-script',
			'apdI18n',
			array(
				'strings' => $strings,
			)
		);
	}

	/**
	 * Pause WooCommerce storefront filters while APD captures product HTML.
	 *
	 * @return void
	 */
	public function begin_apd_product_snapshot() {
		WooCommerce_Translator::set_apd_snapshot_active( true );
	}

	/**
	 * Resume WooCommerce storefront filters after APD capture.
	 *
	 * @return void
	 */
	public function end_apd_product_snapshot() {
		WooCommerce_Translator::set_apd_snapshot_active( false );
	}

	/**
	 * Translate one APD widget label/title string for translated storefront URLs.
	 *
	 * @param string $text       Source label.
	 * @param string $context_key Stable widget field key.
	 * @param string $widget_id  Elementor widget ID.
	 * @return string
	 */
	public function filter_apd_widget_label( $text, $context_key, $widget_id ) {
		unset( $widget_id );

		if ( ! $this->should_translate() || ! is_string( $text ) || '' === trim( $text ) ) {
			return $text;
		}

		$lang = Url_Router::get_current_language();
		$resolved = $this->resolve_registry_string( $text, '', $text );

		if ( null === $resolved && Persian_Detector::contains_persian( $text ) ) {
			$context = 'apd_widget:' . sanitize_key( (string) $context_key );
			$resolved = Runtime_String_Translator::lookup_cached( $text, $lang, $context );

			if ( null === $resolved ) {
				$resolved = Runtime_String_Translator::translate( $text, $lang, $context );
			}
		}

		return is_string( $resolved ) && '' !== trim( $resolved ) ? $resolved : $text;
	}

	/**
	 * @deprecated Replaced by polymart_ai_apd_widget_label filters.
	 */
	public function filter_elementor_widget_render( $content, $widget ) {
		unset( $widget );

		return is_string( $content ) ? $content : '';
	}

	/**
	 * @deprecated
	 */
	private function replace_apd_data_attribute( $attribute_name, $raw_value, $context_prefix, $lang ) {
		$label = html_entity_decode( $raw_value, ENT_QUOTES, 'UTF-8' );

		if ( '' === trim( $label ) ) {
			return $attribute_name . '="' . $raw_value . '"';
		}

		$context_key = sanitize_key( str_replace( array( 'data-', '-' ), array( '', '_' ), $attribute_name ) );
		$resolved      = $this->resolve_registry_string( $label, '', $label );

		if ( null === $resolved && Persian_Detector::contains_persian( $label ) ) {
			$resolved = Runtime_String_Translator::lookup_cached(
				$label,
				$lang,
				$context_prefix . $context_key
			);

			if ( null === $resolved ) {
				$resolved = Runtime_String_Translator::translate(
					$label,
					$lang,
					$context_prefix . $context_key
				);
			}
		}

		if ( ! is_string( $resolved ) || '' === trim( $resolved ) || $resolved === $label ) {
			return $attribute_name . '="' . $raw_value . '"';
		}

		return $attribute_name . '="' . esc_attr( $resolved ) . '"';
	}

	/**
	 * Skip gettext processing on real wp-admin screens only.
	 *
	 * Frontend pages, REST, and storefront AJAX (admin-ajax.php from the site,
	 * not from /wp-admin/) must keep registry lookups active.
	 *
	 * @return bool
	 */
	private function should_skip_gettext() {
		if ( null !== $this->gettext_skip_cache ) {
			return $this->gettext_skip_cache;
		}

		if ( ! is_admin() ) {
			$this->gettext_skip_cache = false;

			return false;
		}

		// REST API is not a classic admin screen for our purposes.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$this->gettext_skip_cache = false;

			return false;
		}

		if ( wp_doing_ajax() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only action hint.
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

			/**
			 * Allow gettext registry lookups during specific frontend AJAX actions.
			 *
			 * @param string[] $actions Action slugs.
			 */
			$frontend_actions = (array) apply_filters( 'polymart_ai_gettext_frontend_ajax_actions', array() );

			if ( in_array( $action, $frontend_actions, true ) ) {
				$this->gettext_skip_cache = false;

				return false;
			}

			// Storefront widgets/portals post to admin-ajax.php; referer is the public page.
			$referer = wp_get_referer();

			if ( is_string( $referer ) && '' !== $referer && false === strpos( $referer, '/wp-admin/' ) ) {
				$this->gettext_skip_cache = false;

				return false;
			}

			$this->gettext_skip_cache = true;

			return true;
		}

		$this->gettext_skip_cache = true;

		return true;
	}

	/**
	 * Determine whether storefront string filters should run.
	 *
	 * @return bool
	 */
	private function should_translate() {
		if ( null !== $this->translate_cache ) {
			return $this->translate_cache;
		}

		if ( ! did_action( 'wp' ) ) {
			$this->translate_cache = false;
			return false;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			$this->translate_cache = false;
			return false;
		}

		$this->translate_cache = Url_Router::is_translated_request();

		return $this->translate_cache;
	}
}
