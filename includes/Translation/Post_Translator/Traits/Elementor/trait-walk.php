<?php
/**
 * Post_Translator Elementor_Walk (auto-split).
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

trait Trait_Walk {

	private static function collect_elementor_translation_payload( array $node ) {
		$payload = array();
		self::walk_elementor_translation_payload( $node, $payload, 'root' );

		return $payload;
	}

	private static function collect_elementor_filtered_out_payload( array $node ) {
		$filtered = array();
		self::walk_elementor_filtered_out_payload( $node, $filtered, 'root' );

		return $filtered;
	}

	private static function walk_elementor_filtered_out_payload( array $node, array &$filtered, $path ) {
		if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
			self::walk_elementor_filtered_out_settings( $node['settings'], $filtered, $path . '.settings' );
		}

		foreach ( $node as $key => $item ) {
			if ( 'settings' === $key ) {
				continue;
			}

			$child_path = $path . '.' . (string) $key;

			if ( is_string( $key ) && isset( self::$elementor_text_keys[ $key ] ) && is_string( $item ) ) {
				$value = trim( (string) $item );

				if ( '' !== $value && Persian_Detector::contains_persian( $value ) && self::should_skip_elementor_setting_key( $key, $value ) ) {
					$filtered[] = array(
						'path'    => $child_path,
						'preview' => $value,
					);
				}
				continue;
			}

			if ( is_array( $item ) ) {
				self::walk_elementor_filtered_out_payload( $item, $filtered, $child_path );
			}
		}
	}

	private static function walk_elementor_filtered_out_settings( array $settings, array &$filtered, $path ) {
		foreach ( $settings as $key => $item ) {
			$child_path = $path . '.' . (string) $key;

			if ( is_string( $item ) ) {
				$value = trim( (string) $item );

				if ( '' !== $value && Persian_Detector::contains_persian( $value ) && self::should_skip_elementor_setting_key( (string) $key, $value ) ) {
					$filtered[] = array(
						'path'    => $child_path,
						'preview' => $value,
					);
				}
				continue;
			}

			if ( is_array( $item ) ) {
				self::walk_elementor_filtered_out_settings( $item, $filtered, $child_path );
			}
		}
	}

	private static function walk_elementor_translation_payload( array $node, array &$payload, $path ) {
		if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
			self::walk_elementor_settings_payload( $node['settings'], $payload, $path . '.settings' );
		}

		foreach ( $node as $key => $item ) {
			if ( 'settings' === $key ) {
				continue;
			}

			$child_path = $path . '.' . (string) $key;

			if ( is_string( $key ) && isset( self::$elementor_text_keys[ $key ] ) && is_string( $item ) ) {
				$value = Persian_Detector::only_persian_value( $item );

				if ( '' !== $value ) {
					$payload[ $child_path ] = $value;
				}
				continue;
			}

			if ( is_array( $item ) ) {
				self::walk_elementor_translation_payload( $item, $payload, $child_path );
			}
		}
	}

	private static function walk_elementor_settings_payload( array $settings, array &$payload, $path ) {
		foreach ( $settings as $key => $item ) {
			$child_path = $path . '.' . (string) $key;

			if ( is_string( $item ) ) {
				if ( ! self::should_collect_elementor_setting_for_translation( (string) $key, $item ) ) {
					continue;
				}

				$value = Persian_Detector::only_persian_value( $item );

				if ( '' !== $value ) {
					$payload[ $child_path ] = $value;
				}
				continue;
			}

			if ( is_array( $item ) ) {
				self::walk_elementor_settings_payload( $item, $payload, $child_path );
			}
		}
	}

	private static function should_collect_elementor_setting_for_translation( $key, $value ) {
		$key = (string) $key;

		if ( self::should_skip_elementor_setting_key( $key, $value ) ) {
			return false;
		}

		if ( self::is_elementor_user_text_setting_key( $key ) ) {
			return true;
		}

		/**
		 * Include extra visible Elementor setting keys for this site.
		 *
		 * @param bool   $include Default false — only whitelisted user-text keys are translated.
		 * @param string $key     Setting key.
		 * @param string $value   Setting value.
		 */
		return (bool) apply_filters( 'polymart_ai_elementor_include_setting_key', false, $key, (string) $value );
	}

	private static function is_elementor_user_text_setting_key( $key ) {
		return isset( self::$elementor_text_keys[ (string) $key ] );
	}

	private static function is_elementor_translatable_url_key( $key, $value ) {
		$key = strtolower( (string) $key );

		if ( self::is_elementor_user_text_setting_key( $key ) ) {
			return false;
		}

		if ( ! Persian_Detector::contains_persian( $value ) ) {
			return false;
		}

		if ( preg_match( '/(?:^|_)(?:image|img|photo|banner|gallery|media|thumb)(?:_)?(?:url|link|src|path|file)$/i', $key ) ) {
			return true;
		}

		return in_array( $key, array( 'image_url', 'image_link', 'image_src', 'image_path', 'image_file' ), true );
	}

	private static function should_skip_elementor_setting_key( $key, $value ) {
		$key          = (string) $key;
		$value        = trim( (string) $value );
		$is_user_text = self::is_elementor_user_text_setting_key( $key );

		if ( '' === $value || ! Persian_Detector::contains_persian( $value ) ) {
			return true;
		}

		static $blocked_keys = array(
			'_id'        => true,
			'id'         => true,
			'elType'     => true,
			'widgetType' => true,
			'isInner'    => true,
			'url'        => true,
			'link'       => true,
			'src'        => true,
			'href'       => true,
		);

		if ( isset( $blocked_keys[ $key ] ) && ! self::is_elementor_translatable_url_key( $key, $value ) ) {
			return true;
		}

		if (
			preg_match( '/(_url|_link|_id|_css|_class|_icon|_image|_img|_media|_src|_size|_color|_width|_height|_align|typography|animation|custom_css|z_index|motion_fx|background|overlay|mask|_json|editor_data|html_cache)/i', $key )
			&& ! self::is_elementor_translatable_url_key( $key, $value )
			&& ! $is_user_text
		) {
			return true;
		}

		// Embedded JSON/config blobs — not human-readable text.
		$trimmed = ltrim( $value );
		if ( ( '{' === $trimmed || '[' === $trimmed ) && ! $is_user_text ) {
			return true;
		}

		// Skip raw markup/config blobs, but allow rich Elementor widget HTML to pass through.
		if ( ! $is_user_text ) {
			if ( preg_match( '/^\s*<(?:style|script|svg|!DOCTYPE)/i', $value ) ) {
				return true;
			}
		}

		// Long serialized JSON-like strings.
		if ( mb_strlen( $value ) > 500 && preg_match( '/["\']\s*:\s*["\[\{]/', $value ) ) {
			return true;
		}

		// URLs, data-URIs, hex colors, pure numbers/units, SVG/path junk.
		if ( preg_match( '/^(https?:\/\/|\/\/|data:|#|[0-9a-f]{3,8}$|\d+(\.\d+)?(px|em|rem|%|vh|vw)?)$/i', $value ) ) {
			return true;
		}

		if ( preg_match( '/^(data:image\/|data:application\/|blob:)/i', $value ) ) {
			return true;
		}

		// Mostly media/path content without Persian text.
		if ( preg_match( '/\.(jpe?g|png|gif|webp|svg|mp4|webm|pdf)(\?|$)/i', $value ) && ! Persian_Detector::contains_persian( $value ) ) {
			return true;
		}

		// Extremely short non-prose tokens.
		if ( mb_strlen( $value ) <= 2 ) {
			return true;
		}

		return false;
	}

	private static function apply_elementor_translation_payload( array $node, array $translated_map, $path = 'root' ) {
		if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
			$node['settings'] = self::apply_elementor_settings_payload( $node['settings'], $translated_map, $path . '.settings' );
		}

		foreach ( $node as $key => $item ) {
			if ( 'settings' === $key ) {
				continue;
			}

			$child_path = $path . '.' . (string) $key;

			if ( is_string( $item ) && isset( $translated_map[ $child_path ] ) ) {
				$node[ $key ] = $translated_map[ $child_path ];
				continue;
			}

			if ( is_array( $item ) ) {
				$node[ $key ] = self::apply_elementor_translation_payload( $item, $translated_map, $child_path );
			}
		}

		return $node;
	}

	private static function apply_elementor_settings_payload( array $settings, array $translated_map, $path ) {
		foreach ( $settings as $key => $item ) {
			$child_path = $path . '.' . (string) $key;

			if ( is_string( $item ) && isset( $translated_map[ $child_path ] ) ) {
				$settings[ $key ] = $translated_map[ $child_path ];
				continue;
			}

			if ( is_array( $item ) ) {
				$settings[ $key ] = self::apply_elementor_settings_payload( $item, $translated_map, $child_path );
			}
		}

		return $settings;
	}

	public static function collect_elementor_persian_plain_text( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return '';
		}

		if ( array_key_exists( $post_id, self::$elementor_plain_text_cache ) ) {
			return self::$elementor_plain_text_cache[ $post_id ];
		}

		// Never decode multi-megabyte Elementor JSON during public page renders.
		if ( ! self::allows_elementor_audit_decode() ) {
			self::$elementor_plain_text_cache[ $post_id ] = '';

			return '';
		}

		if ( ! self::uses_elementor_builder( $post_id ) ) {
			self::$elementor_plain_text_cache[ $post_id ] = '';

			return '';
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			self::$elementor_plain_text_cache[ $post_id ] = '';

			return '';
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			self::$elementor_plain_text_cache[ $post_id ] = '';

			return '';
		}

		$payload = self::collect_elementor_translation_payload( $data );

		self::$elementor_plain_text_cache[ $post_id ] = implode(
			"\n\n",
			array_unique( array_filter( array_values( $payload ) ) )
		);

		return self::$elementor_plain_text_cache[ $post_id ];
	}

	private static function walk_elementor_persian_text( array $node, array &$chunks ) {
		foreach ( $node as $key => $item ) {
			if ( is_string( $key ) && isset( self::$elementor_text_keys[ $key ] ) && is_string( $item ) ) {
				$value = Persian_Detector::only_persian_value( $item );

				if ( '' !== $value ) {
					$chunks[] = $value;
				}
				continue;
			}

			if ( is_array( $item ) ) {
				self::walk_elementor_persian_text( $item, $chunks );
			}
		}
	}

	public static function extract_elementor_html_persian_excerpt( $html ) {
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return '';
		}

		$plain = wp_strip_all_tags( $html );
		$plain = html_entity_decode( $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$plain = trim( preg_replace( '/\s+/u', ' ', $plain ) );

		if ( '' === $plain ) {
			return '';
		}

		$persian = Persian_Detector::only_persian_value( $plain );

		if ( '' !== $persian ) {
			return $persian;
		}

		if ( Persian_Detector::contains_persian( $plain ) ) {
			return function_exists( 'mb_substr' ) ? mb_substr( $plain, 0, 4000, 'UTF-8' ) : substr( $plain, 0, 4000 );
		}

		return '';
	}

}
