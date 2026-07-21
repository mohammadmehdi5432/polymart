<?php
/**
 * Per-language images on Elementor's Image widget.
 *
 * Adds MEDIA controls for each PolyMart target language in the editor, then
 * promotes `polymart_image_{lang}` → `image` on translated storefront URLs.
 *
 * @package PolymartAI\Integration
 */

namespace PolymartAI\Integration;

use PolymartAI\Language_Registry;
use PolymartAI\Routing\Url_Router;


defined( 'ABSPATH' ) || exit;

/**
 * Class Elementor_Image_Translation
 */
final class Elementor_Image_Translation {

	/**
	 * Setting key prefix stored inside Elementor widget settings.
	 */
	const SETTING_PREFIX = 'polymart_image_';

	/**
	 * Per-request rewritten JSON cache: "{post_id}:{lang}" => string|false.
	 *
	 * @var array<string, string|false>
	 */
	private static $json_cache = array();

	/**
	 * Re-entrancy guard for get_post_metadata.
	 *
	 * @var int
	 */
	private static $meta_depth = 0;

	/**
	 * Boot editor controls + storefront swap.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'elementor/init', array( __CLASS__, 'register_elementor_hooks' ) );

		if ( ! is_admin() || wp_doing_ajax() ) {
			add_filter( 'get_post_metadata', array( __CLASS__, 'filter_elementor_data_images' ), 20, 4 );
			add_filter( 'get_post_metadata', array( __CLASS__, 'filter_element_cache_bust' ), 6, 4 );
			add_action( 'elementor/frontend/widget/before_render', array( __CLASS__, 'before_image_widget_render' ), 5 );
			add_filter( 'elementor/frontend/widget/should_render', array( __CLASS__, 'should_render_image_widget' ), 10, 2 );
		}
	}

	/**
	 * Drop Elementor HTML element-cache when this document has a per-language image.
	 *
	 * Otherwise cached FA markup keeps the Persian `<img>` even after JSON is rewritten.
	 *
	 * @param mixed  $value     Short-circuit value.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Single flag.
	 * @return mixed
	 */
	public static function filter_element_cache_bust( $value, $object_id, $meta_key, $single ) {
		if ( '_elementor_element_cache' !== (string) $meta_key ) {
			return $value;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return $value;
		}

		if ( ! Url_Router::is_translated_request() ) {
			return $value;
		}

		$post_id = absint( $object_id );
		$lang    = Url_Router::get_current_language();

		if ( $post_id <= 0 || '' === $lang ) {
			return $value;
		}

		if ( ! self::document_needs_image_localization( $post_id, $lang ) ) {
			return $value;
		}

		return $single ? '' : array();
	}

	/**
	 * Register Elementor editor hooks once Elementor is ready.
	 *
	 * @return void
	 */
	public static function register_elementor_hooks() {
		add_action(
			'elementor/element/image/section_image/after_section_end',
			array( __CLASS__, 'register_image_controls' ),
			10,
			2
		);
	}

	/**
	 * Add one MEDIA control per translation target language.
	 *
	 * @param \Elementor\Controls_Stack $element Elementor element.
	 * @param array<string, mixed>      $args    Section args (unused).
	 * @return void
	 */
	public static function register_image_controls( $element, $args = array() ) {
		unset( $args );

		if ( ! is_object( $element ) || ! method_exists( $element, 'start_controls_section' ) ) {
			return;
		}

		if ( ! class_exists( '\Elementor\Controls_Manager' ) ) {
			return;
		}

		$languages = Language_Registry::get_translation_target_languages();

		if ( empty( $languages ) ) {
			return;
		}

		$element->start_controls_section(
			'polymart_ai_image_languages',
			array(
				'label' => __( 'پلی‌مارت — تصاویر چندزبانه', 'polymart-ai' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$element->add_control(
			'polymart_ai_image_languages_notice',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => '<p style="margin:0;line-height:1.5;">' . esc_html__(
					'تصویر اصلی بالا برای فارسی است. اگر برای یک زبان تصویر بگذارید همان نمایش داده می‌شود. اگر کنترل زبان را خالی بگذارید، در آن زبان ویجت مخفی می‌شود. اگر اصلاً این بخش را پر نکنید، همان تصویر فارسی می‌ماند.',
					'polymart-ai'
				) . '</p>',
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);

		foreach ( $languages as $language ) {
			$code = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $code ) {
				continue;
			}

			$label = ! empty( $language['native_name'] )
				? (string) $language['native_name']
				: ( ! empty( $language['name'] ) ? (string) $language['name'] : strtoupper( $code ) );

			$element->add_control(
				self::SETTING_PREFIX . $code,
				array(
					'label'      => sprintf(
						/* translators: %s: language name */
						__( 'تصویر (%s)', 'polymart-ai' ),
						$label
					),
					'type'       => \Elementor\Controls_Manager::MEDIA,
					'media_types' => array( 'image' ),
					'default'    => array(
						'url' => '',
						'id'  => '',
					),
					'dynamic'    => array(
						'active' => true,
					),
				)
			);
		}

		$element->end_controls_section();
	}

	/**
	 * Setting key for a language.
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function get_setting_key( $lang ) {
		return self::SETTING_PREFIX . sanitize_key( (string) $lang );
	}

	/**
	 * Rewrite `_elementor_data` on translated storefront so Image widgets use lang media.
	 *
	 * Runs after Universal_Translator companion swap (priority 5) so both companion
	 * and source FA JSON get `polymart_image_{lang}` promoted into `image`.
	 *
	 * @param mixed  $value     Short-circuit value.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Single flag.
	 * @return mixed
	 */
	public static function filter_elementor_data_images( $value, $object_id, $meta_key, $single ) {
		if ( '_elementor_data' !== $meta_key ) {
			return $value;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return $value;
		}

		if ( ! Url_Router::is_translated_request() ) {
			return $value;
		}

		$post_id = absint( $object_id );

		if ( $post_id <= 0 || self::$meta_depth > 0 ) {
			return $value;
		}

		$lang = Url_Router::get_current_language();

		if ( '' === $lang || Language_Registry::get_default_language_code() === $lang ) {
			return $value;
		}

		$cache_key = $post_id . ':' . $lang;

		if ( array_key_exists( $cache_key, self::$json_cache ) ) {
			$cached = self::$json_cache[ $cache_key ];

			if ( false === $cached ) {
				return $value;
			}

			return $single ? $cached : array( $cached );
		}

		++self::$meta_depth;

		try {
			$original = self::extract_elementor_json_string( $value, $post_id );

			if ( ! is_string( $original ) || '' === ltrim( $original ) ) {
				self::$json_cache[ $cache_key ] = false;

				return $value;
			}

			// Fast path: page never used multilingual Image controls — do not decode/rewrite.
			if ( false === strpos( $original, '"' . self::SETTING_PREFIX ) ) {
				self::$json_cache[ $cache_key ] = false;

				return $value;
			}

			$working = $original;
			$needle  = '"' . self::get_setting_key( $lang ) . '"';

			// Companion JSON may predate the language-image controls; copy them from source.
			if ( false === strpos( $working, $needle ) ) {
				$working = self::merge_language_images_from_source( $working, $post_id, $lang );
			}

			$rewritten = self::apply_language_images_to_json( $working, $lang );

			if ( ! is_string( $rewritten ) || $rewritten === $original ) {
				self::$json_cache[ $cache_key ] = false;

				return $value;
			}

			self::$json_cache[ $cache_key ] = $rewritten;

			return $single ? $rewritten : array( $rewritten );
		} finally {
			--self::$meta_depth;
		}
	}

	/**
	 * Copy `polymart_image_{lang}` settings from source `_elementor_data` into another tree by element id.
	 *
	 * @param string $json    Target JSON (usually companion).
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language.
	 * @return string
	 */
	private static function merge_language_images_from_source( $json, $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$key     = self::get_setting_key( $lang );

		if ( $post_id <= 0 || '' === $lang || ! is_string( $json ) ) {
			return is_string( $json ) ? $json : '';
		}

		remove_filter( 'get_post_metadata', array( __CLASS__, 'filter_elementor_data_images' ), 20 );
		$source_raw = get_post_meta( $post_id, '_elementor_data', true );
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_elementor_data_images' ), 20, 4 );

		if ( ! is_string( $source_raw ) || false === strpos( $source_raw, '"' . $key . '"' ) ) {
			return $json;
		}

		$source = json_decode( $source_raw, true );
		$target = json_decode( $json, true );

		if ( ! is_array( $source ) || ! is_array( $target ) ) {
			return $json;
		}

		$map = array();
		self::collect_language_images_by_id( $source, $key, $map );

		if ( empty( $map ) ) {
			return $json;
		}

		$changed = self::inject_language_images_by_id( $target, $key, $map );

		if ( ! $changed ) {
			return $json;
		}

		$encoded = wp_json_encode( $target, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return is_string( $encoded ) && '' !== $encoded ? $encoded : $json;
	}

	/**
	 * @param array<int|string, mixed>     $nodes Element tree.
	 * @param string                       $key   Setting key.
	 * @param array<string, array<string, mixed>> $map Output id => media.
	 * @return void
	 */
	private static function collect_language_images_by_id( array $nodes, $key, array &$map ) {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$id = isset( $node['id'] ) ? (string) $node['id'] : '';

			if (
				'' !== $id
				&& isset( $node['widgetType'] )
				&& 'image' === $node['widgetType']
				&& isset( $node['settings'][ $key ] )
				&& self::is_usable_media( $node['settings'][ $key ] )
			) {
				$map[ $id ] = $node['settings'][ $key ];
			}

			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				self::collect_language_images_by_id( $node['elements'], $key, $map );
			}
		}
	}

	/**
	 * @param array<int|string, mixed>            $nodes Element tree.
	 * @param string                              $key   Setting key.
	 * @param array<string, array<string, mixed>> $map   id => media.
	 * @return bool
	 */
	private static function inject_language_images_by_id( array &$nodes, $key, array $map ) {
		$changed = false;

		foreach ( $nodes as &$node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$id = isset( $node['id'] ) ? (string) $node['id'] : '';

			if (
				'' !== $id
				&& isset( $map[ $id ] )
				&& isset( $node['widgetType'] )
				&& 'image' === $node['widgetType']
			) {
				if ( ! isset( $node['settings'] ) || ! is_array( $node['settings'] ) ) {
					$node['settings'] = array();
				}

				$node['settings'][ $key ] = $map[ $id ];
				$changed                  = true;
			}

			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				if ( self::inject_language_images_by_id( $node['elements'], $key, $map ) ) {
					$changed = true;
				}
			}
		}
		unset( $node );

		return $changed;
	}

	/**
	 * Safety net: override Image widget settings right before HTML render.
	 *
	 * Missing language media clears the image so nothing falls back to Persian.
	 *
	 * @param \Elementor\Element_Base $widget Widget instance.
	 * @return void
	 */
	public static function before_image_widget_render( $widget ) {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) ) {
			return;
		}

		if ( 'image' !== $widget->get_name() ) {
			return;
		}

		if ( ! Url_Router::is_translated_request() ) {
			return;
		}

		$lang = Url_Router::get_current_language();

		if ( '' === $lang || Language_Registry::get_default_language_code() === $lang ) {
			return;
		}

		if ( ! method_exists( $widget, 'get_settings_for_display' ) || ! method_exists( $widget, 'set_settings' ) ) {
			return;
		}

		$settings  = $widget->get_settings_for_display();
		$key       = self::get_setting_key( $lang );

		if ( ! is_array( $settings ) || ! array_key_exists( $key, $settings ) ) {
			return;
		}

		$localized = is_array( $settings[ $key ] ) ? $settings[ $key ] : null;

		if ( self::is_usable_media( $localized ) ) {
			$widget->set_settings( 'image', $localized );
			return;
		}

		$widget->set_settings(
			'image',
			array(
				'url' => '',
				'id'  => '',
			)
		);
	}

	/**
	 * Hide Image widgets on translated URLs when that language has no uploaded image.
	 *
	 * @param bool                        $should_render Whether to render.
	 * @param \Elementor\Element_Base     $widget        Widget.
	 * @return bool
	 */
	public static function should_render_image_widget( $should_render, $widget ) {
		if ( ! $should_render || ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) ) {
			return $should_render;
		}

		if ( 'image' !== $widget->get_name() ) {
			return $should_render;
		}

		if ( ! Url_Router::is_translated_request() ) {
			return $should_render;
		}

		$lang = Url_Router::get_current_language();

		if ( '' === $lang || Language_Registry::get_default_language_code() === $lang ) {
			return $should_render;
		}

		if ( ! method_exists( $widget, 'get_settings_for_display' ) ) {
			return $should_render;
		}

		$settings = $widget->get_settings_for_display();
		$key      = self::get_setting_key( $lang );

		// Widget never configured for multilingual images — keep default render.
		if ( ! is_array( $settings ) || ! array_key_exists( $key, $settings ) ) {
			return $should_render;
		}

		$localized = is_array( $settings[ $key ] ) ? $settings[ $key ] : null;

		return self::is_usable_media( $localized );
	}

	/**
	 * Promote per-language media into the main `image` setting for all Image widgets.
	 *
	 * @param string $json Elementor JSON string.
	 * @param string $lang Target language.
	 * @return string Rewritten JSON, or original when unchanged / invalid.
	 */
	public static function apply_language_images_to_json( $json, $lang ) {
		$json = is_string( $json ) ? $json : '';
		$lang = sanitize_key( (string) $lang );

		if ( '' === $json || '' === $lang ) {
			return $json;
		}

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return $json;
		}

		$changed = self::promote_images_in_tree( $data, $lang );

		if ( ! $changed ) {
			return $json;
		}

		$encoded = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return is_string( $encoded ) && '' !== $encoded ? $encoded : $json;
	}

	/**
	 * @param array<int|string, mixed> $nodes Elementor element tree.
	 * @param string                   $lang  Language code.
	 * @return bool Whether any node changed.
	 */
	private static function promote_images_in_tree( array &$nodes, $lang ) {
		$changed = false;
		$key     = self::get_setting_key( $lang );
		$empty   = array(
			'url' => '',
			'id'  => '',
		);

		foreach ( $nodes as &$node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$widget_type = isset( $node['widgetType'] ) ? (string) $node['widgetType'] : '';

			if ( 'image' === $widget_type && isset( $node['settings'] ) && is_array( $node['settings'] ) && array_key_exists( $key, $node['settings'] ) ) {
				$localized = is_array( $node['settings'][ $key ] ) ? $node['settings'][ $key ] : null;
				$current   = isset( $node['settings']['image'] ) && is_array( $node['settings']['image'] )
					? $node['settings']['image']
					: array();

				if ( self::is_usable_media( $localized ) ) {
					if ( self::media_differs( $current, $localized ) ) {
						$node['settings']['image'] = $localized;
						$changed                   = true;
					}
				} elseif ( self::is_usable_media( $current ) ) {
					// Control present but empty for this language → hide (no FA fallback).
					$node['settings']['image'] = $empty;
					$changed                   = true;
				}
			}

			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				if ( self::promote_images_in_tree( $node['elements'], $lang ) ) {
					$changed = true;
				}
			}
		}
		unset( $node );

		return $changed;
	}

	/**
	 * @param mixed $media Elementor MEDIA control value.
	 * @return bool
	 */
	private static function is_usable_media( $media ) {
		if ( ! is_array( $media ) ) {
			return false;
		}

		$id  = isset( $media['id'] ) ? absint( $media['id'] ) : 0;
		$url = isset( $media['url'] ) ? trim( (string) $media['url'] ) : '';

		return $id > 0 || '' !== $url;
	}

	/**
	 * @param array<string, mixed> $a Media A.
	 * @param array<string, mixed> $b Media B.
	 * @return bool
	 */
	private static function media_differs( array $a, array $b ) {
		$a_id  = isset( $a['id'] ) ? absint( $a['id'] ) : 0;
		$b_id  = isset( $b['id'] ) ? absint( $b['id'] ) : 0;
		$a_url = isset( $a['url'] ) ? (string) $a['url'] : '';
		$b_url = isset( $b['url'] ) ? (string) $b['url'] : '';

		return $a_id !== $b_id || $a_url !== $b_url;
	}

	/**
	 * Resolve raw Elementor JSON from short-circuit value or DB.
	 *
	 * @param mixed $value   get_post_metadata short-circuit.
	 * @param int   $post_id Post ID.
	 * @return string|null
	 */
	private static function extract_elementor_json_string( $value, $post_id ) {
		if ( null !== $value ) {
			if ( is_string( $value ) ) {
				return $value;
			}

			if ( is_array( $value ) && isset( $value[0] ) && is_string( $value[0] ) ) {
				return $value[0];
			}

			return null;
		}

		remove_filter( 'get_post_metadata', array( __CLASS__, 'filter_elementor_data_images' ), 20 );
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_elementor_data_images' ), 20, 4 );

		return is_string( $raw ) ? $raw : null;
	}

	/**
	 * Whether this document needs Image-widget localization on a translated URL.
	 *
	 * Bust cache when language-specific media exists OR any Image widget is present
	 * (missing language media must hide the Persian fallback, which requires re-render).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language.
	 * @return bool
	 */
	private static function document_needs_image_localization( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		static $memo = array();
		$memo_key    = $post_id . ':' . $lang;

		if ( array_key_exists( $memo_key, $memo ) ) {
			return $memo[ $memo_key ];
		}

		remove_filter( 'get_post_metadata', array( __CLASS__, 'filter_elementor_data_images' ), 20 );
		remove_filter( 'get_post_metadata', array( __CLASS__, 'filter_element_cache_bust' ), 6 );

		$candidates = array(
			get_post_meta( $post_id, '_elementor_data', true ),
			get_post_meta( $post_id, '_elementor_data_' . $lang, true ),
		);

		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_element_cache_bust' ), 6, 4 );
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_elementor_data_images' ), 20, 4 );

		$lang_needle  = '"' . self::SETTING_PREFIX;
		$needs        = false;

		foreach ( $candidates as $raw ) {
			if ( ! is_string( $raw ) || '' === $raw ) {
				continue;
			}

			// Only bust when multilingual Image controls exist — never for every page with an Image widget.
			if ( false !== strpos( $raw, $lang_needle ) ) {
				$needs = true;
				break;
			}
		}

		$memo[ $memo_key ] = $needs;

		return $needs;
	}

	/**
	 * @deprecated Kept as alias for callers; use document_needs_image_localization().
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language.
	 * @return bool
	 */
	private static function document_has_language_image( $post_id, $lang ) {
		return self::document_needs_image_localization( $post_id, $lang );
	}
}
