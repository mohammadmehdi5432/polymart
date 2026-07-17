<?php
/**
 * Apply a single correction match to storage.
 *
 * @package PolymartAI\Translation\Correction
 */

namespace PolymartAI\Translation\Correction;

use PolymartAI\Activity_Logger;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Storefront\Runtime_String_Translator;
use PolymartAI\Translation\UI_String\UI_String_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Correction_Applier
 */
final class Correction_Applier {

	/**
	 * Apply one match descriptor.
	 *
	 * @param array<string, mixed> $match         Match from preview/job.
	 * @param string               $find          Find.
	 * @param string               $replace       Replace.
	 * @param string               $mode          Mode.
	 * @param bool                 $word_boundary Boundary.
	 * @return array{ok:bool,replacements:int,message:string}|\WP_Error
	 */
	public static function apply_match( array $match, $find, $replace, $mode = 'exact', $word_boundary = false ) {
		$scope = sanitize_key( (string) ( $match['scope'] ?? '' ) );
		$valid = Correction_Text::validate_pair( $find, $replace, $mode, $word_boundary );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( 'ui_strings' === $scope ) {
			return self::apply_ui_match( $match, $find, $replace, $mode, $word_boundary );
		}

		if ( 'products' === $scope ) {
			return self::apply_product_match( $match, $find, $replace, $mode, $word_boundary );
		}

		if ( 'elementor' === $scope ) {
			return self::apply_elementor_match( $match, $find, $replace, $mode, $word_boundary );
		}

		return new \WP_Error(
			'polymart_ai_correction_scope',
			__( 'محدودهٔ ناشناخته برای اعمال تصحیح.', 'polymart-ai' )
		);
	}

	/**
	 * @param array<string, mixed> $match Match.
	 * @param string               $find Find.
	 * @param string               $replace Replace.
	 * @param string               $mode Mode.
	 * @param bool                 $word_boundary Boundary.
	 * @return array{ok:bool,replacements:int,message:string}|\WP_Error
	 */
	private static function apply_ui_match( array $match, $find, $replace, $mode, $word_boundary ) {
		$id   = (string) ( $match['id'] ?? '' );
		$hash = (string) ( $match['hash'] ?? '' );
		$lang = sanitize_key( (string) ( $match['lang'] ?? '' ) );

		if ( '' === $hash && 0 === strpos( $id, 'ui:' ) ) {
			$hash = substr( $id, 3 );
		} elseif ( '' === $hash && 0 === strpos( $id, 'runtime:' ) ) {
			$hash = substr( $id, 8 );
		}

		if ( '' === $hash ) {
			return new \WP_Error(
				'polymart_ai_correction_ui_hash',
				__( 'شناسهٔ رشته UI نامعتبر است.', 'polymart-ai' )
			);
		}

		$store = (string) ( $match['store'] ?? '' );

		if ( '' === $store && 0 === strpos( $id, 'runtime:' ) ) {
			$store = 'runtime';
		}

		if ( 'runtime' === $store ) {
			return self::apply_runtime_cache( $lang, $hash, $find, $replace, $mode, $word_boundary );
		}

		$bucket = get_option( UI_String_Registry::TRANSLATIONS_OPTION_PREFIX . $lang, array() );

		if ( ! is_array( $bucket ) || empty( $bucket['h'][ $hash ] ) || ! is_string( $bucket['h'][ $hash ] ) ) {
			return new \WP_Error(
				'polymart_ai_correction_ui_missing',
				__( 'ترجمه UI یافت نشد (ممکن است قبلاً تغییر کرده باشد).', 'polymart-ai' )
			);
		}

		$before = $bucket['h'][ $hash ];
		$after  = Correction_Text::replace( $before, $find, $replace, $mode, $word_boundary );

		if ( $before === $after ) {
			return array(
				'ok'            => true,
				'replacements'  => 0,
				'message'       => __( 'تغییری لازم نبود.', 'polymart-ai' ),
			);
		}

		UI_String_Registry::save_translations( $lang, array( $hash => $after ) );

		Activity_Logger::log(
			'info',
			sprintf(
				/* translators: 1: hash */
				__( 'تصحیح ترجمه UI — hash %s', 'polymart-ai' ),
				$hash
			),
			array(
				'lang'   => $lang,
				'source' => 'correction',
				'scope'  => 'ui_strings',
			)
		);

		return array(
			'ok'           => true,
			'replacements' => Correction_Text::match_count( $before, $find, $mode, $word_boundary ),
			'message'      => __( 'رشته UI به‌روز شد.', 'polymart-ai' ),
		);
	}

	/**
	 * @param string $lang Lang.
	 * @param string $hash Hash.
	 * @param string $find Find.
	 * @param string $replace Replace.
	 * @param string $mode Mode.
	 * @param bool   $word_boundary Boundary.
	 * @return array{ok:bool,replacements:int,message:string}|\WP_Error
	 */
	private static function apply_runtime_cache( $lang, $hash, $find, $replace, $mode, $word_boundary ) {
		$lang   = sanitize_key( (string) $lang );
		$option = Runtime_String_Translator::CACHE_OPTION_PREFIX . $lang;
		$bucket = get_option( $option, array() );

		if ( ! is_array( $bucket ) || empty( $bucket['h'][ $hash ] ) || ! is_string( $bucket['h'][ $hash ] ) ) {
			return new \WP_Error(
				'polymart_ai_correction_runtime_missing',
				__( 'ورودی کش ران‌تایم یافت نشد.', 'polymart-ai' )
			);
		}

		$before = $bucket['h'][ $hash ];
		$after  = Correction_Text::replace( $before, $find, $replace, $mode, $word_boundary );

		if ( $before === $after ) {
			return array(
				'ok'           => true,
				'replacements' => 0,
				'message'      => __( 'تغییری لازم نبود.', 'polymart-ai' ),
			);
		}

		$bucket['h'][ $hash ] = $after;
		update_option( $option, $bucket, false );

		return array(
			'ok'           => true,
			'replacements' => Correction_Text::match_count( $before, $find, $mode, $word_boundary ),
			'message'      => __( 'کش ران‌تایم به‌روز شد.', 'polymart-ai' ),
		);
	}

	/**
	 * @param array<string, mixed> $match Match.
	 * @param string               $find Find.
	 * @param string               $replace Replace.
	 * @param string               $mode Mode.
	 * @param bool                 $word_boundary Boundary.
	 * @return array{ok:bool,replacements:int,message:string}|\WP_Error
	 */
	private static function apply_product_match( array $match, $find, $replace, $mode, $word_boundary ) {
		$post_id  = absint( $match['post_id'] ?? 0 );
		$meta_key = (string) ( $match['meta_key'] ?? '' );
		$path     = isset( $match['path'] ) ? (string) $match['path'] : '';

		if ( $post_id <= 0 || '' === $meta_key ) {
			return new \WP_Error(
				'polymart_ai_correction_product_args',
				__( 'شناسه مطلب یا کلید متا نامعتبر است.', 'polymart-ai' )
			);
		}

		$raw = get_post_meta( $post_id, $meta_key, true );

		if ( is_array( $raw ) || ( is_string( $raw ) && is_serialized( $raw ) ) ) {
			$data = is_array( $raw ) ? $raw : maybe_unserialize( $raw );

			if ( ! is_array( $data ) ) {
				return new \WP_Error(
					'polymart_ai_correction_product_serialize',
					__( 'متای سریالایز محصول قابل خواندن نیست.', 'polymart-ai' )
				);
			}

			if ( '' !== $path ) {
				list( $data, $count ) = self::replace_path_leaf( $data, $path, $find, $replace, $mode, $word_boundary );
			} else {
				list( $data, $count ) = Correction_Scanner::replace_array_leaves( $data, $find, $replace, $mode, $word_boundary );
			}

			if ( $count <= 0 ) {
				return array(
					'ok'           => true,
					'replacements' => 0,
					'message'      => __( 'تغییری لازم نبود.', 'polymart-ai' ),
				);
			}

			update_post_meta( $post_id, $meta_key, $data );
			Post_Translator::sync_translation_index_meta( $post_id );

			return array(
				'ok'           => true,
				'replacements' => $count,
				'message'      => __( 'متای محصول/برگه به‌روز شد.', 'polymart-ai' ),
			);
		}

		if ( ! is_string( $raw ) ) {
			return new \WP_Error(
				'polymart_ai_correction_product_missing',
				__( 'مقدار متا یافت نشد.', 'polymart-ai' )
			);
		}

		$after = Correction_Text::replace( $raw, $find, $replace, $mode, $word_boundary );

		if ( $raw === $after ) {
			return array(
				'ok'           => true,
				'replacements' => 0,
				'message'      => __( 'تغییری لازم نبود.', 'polymart-ai' ),
			);
		}

		update_post_meta( $post_id, $meta_key, $after );
		Post_Translator::sync_translation_index_meta( $post_id );

		Activity_Logger::log(
			'info',
			sprintf(
				/* translators: 1: post ID, 2: meta key */
				__( 'تصحیح ترجمه — پست #%1$d متای %2$s', 'polymart-ai' ),
				$post_id,
				$meta_key
			),
			array(
				'post_id' => $post_id,
				'source'  => 'correction',
				'scope'   => 'products',
			)
		);

		return array(
			'ok'           => true,
			'replacements' => Correction_Text::match_count( $raw, $find, $mode, $word_boundary ),
			'message'      => __( 'متای محصول/برگه به‌روز شد.', 'polymart-ai' ),
		);
	}

	/**
	 * @param array<string, mixed> $match Match.
	 * @param string               $find Find.
	 * @param string               $replace Replace.
	 * @param string               $mode Mode.
	 * @param bool                 $word_boundary Boundary.
	 * @return array{ok:bool,replacements:int,message:string}|\WP_Error
	 */
	private static function apply_elementor_match( array $match, $find, $replace, $mode, $word_boundary ) {
		$post_id  = absint( $match['post_id'] ?? 0 );
		$meta_key = (string) ( $match['meta_key'] ?? '' );
		$lang     = sanitize_key( (string) ( $match['lang'] ?? '' ) );

		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'polymart_ai_correction_elementor_post',
				__( 'شناسه پست Elementor نامعتبر است.', 'polymart-ai' )
			);
		}

		if ( '' === $meta_key && '' !== $lang ) {
			$meta_key = '_elementor_data_' . $lang;
		}

		if ( '' === $meta_key ) {
			return new \WP_Error(
				'polymart_ai_correction_elementor_key',
				__( 'کلید متای Elementor نامعتبر است.', 'polymart-ai' )
			);
		}

		$raw = get_post_meta( $post_id, $meta_key, true );

		if ( ! is_string( $raw ) || '' === ltrim( $raw ) ) {
			return new \WP_Error(
				'polymart_ai_correction_elementor_missing',
				__( 'JSON ترجمه Elementor یافت نشد.', 'polymart-ai' )
			);
		}

		if ( strlen( $raw ) > Post_Translator::get_max_storefront_elementor_json_bytes() ) {
			return new \WP_Error(
				'polymart_ai_correction_elementor_oversize',
				__( 'JSON Elementor بزرگ‌تر از سقف مجاز است.', 'polymart-ai' )
			);
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'polymart_ai_correction_elementor_json',
				__( 'JSON Elementor نامعتبر است.', 'polymart-ai' )
			);
		}

		list( $data, $count ) = Correction_Scanner::replace_array_leaves( $data, $find, $replace, $mode, $word_boundary );

		if ( $count <= 0 ) {
			return array(
				'ok'           => true,
				'replacements' => 0,
				'message'      => __( 'تغییری لازم نبود.', 'polymart-ai' ),
			);
		}

		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $json || '' === $json ) {
			return new \WP_Error(
				'polymart_ai_correction_elementor_encode',
				__( 'ذخیره JSON Elementor ناموفق بود.', 'polymart-ai' )
			);
		}

		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'polymart_ai_correction_elementor_corrupt',
				__( 'JSON خروجی Elementor خراب است؛ ذخیره نشد.', 'polymart-ai' )
			);
		}

		update_post_meta( $post_id, $meta_key, wp_slash( $json ) );

		Activity_Logger::log(
			'info',
			sprintf(
				/* translators: 1: post ID, 2: replacement count */
				__( 'تصحیح Elementor — پست #%1$d (%2$d جایگزینی)', 'polymart-ai' ),
				$post_id,
				$count
			),
			array(
				'post_id' => $post_id,
				'source'  => 'correction',
				'scope'   => 'elementor',
			)
		);

		return array(
			'ok'           => true,
			'replacements' => $count,
			'message'      => __( 'JSON Elementor به‌روز شد.', 'polymart-ai' ),
		);
	}

	/**
	 * Replace a single dotted path leaf inside an array.
	 *
	 * @param array<string, mixed> $data Data.
	 * @param string               $path Path.
	 * @param string               $find Find.
	 * @param string               $replace Replace.
	 * @param string               $mode Mode.
	 * @param bool                 $word_boundary Boundary.
	 * @return array{0:array,1:int}
	 */
	private static function replace_path_leaf( array $data, $path, $find, $replace, $mode, $word_boundary ) {
		$parts = explode( '.', (string) $path );

		if ( empty( $parts ) || ( 1 === count( $parts ) && 'value' === $parts[0] ) ) {
			return Correction_Scanner::replace_array_leaves( $data, $find, $replace, $mode, $word_boundary );
		}

		$ref    =& $data;
		$last   = array_pop( $parts );
		$ok     = true;

		foreach ( $parts as $part ) {
			if ( ! is_array( $ref ) || ! array_key_exists( $part, $ref ) ) {
				$ok = false;
				break;
			}

			$ref =& $ref[ $part ];
		}

		if ( ! $ok || ! is_array( $ref ) || ! array_key_exists( $last, $ref ) || ! is_string( $ref[ $last ] ) ) {
			return Correction_Scanner::replace_array_leaves( $data, $find, $replace, $mode, $word_boundary );
		}

		$before       = $ref[ $last ];
		$ref[ $last ] = Correction_Text::replace( $before, $find, $replace, $mode, $word_boundary );
		$count        = $before === $ref[ $last ]
			? 0
			: Correction_Text::match_count( $before, $find, $mode, $word_boundary );

		return array( $data, $count );
	}
}
