<?php
/**
 * Post_Translator Variation (auto-split).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\Variation;

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\AI\AI_Client;
use PolymartAI\Translation\AI\Persian_Detector;
use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Post_Translator\Persistence_Guard;
use PolymartAI\Translation\Post_Translator\Text_Normalizer;
use PolymartAI\Translation\Post_Translator\Translation_Lock;


defined( 'ABSPATH' ) || exit;

trait Trait_Variation {

	public static function get_product_variation_title_snapshot( $product_id ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			return array();
		}

		$snapshot = array();

		foreach ( $product->get_children() as $variation_id ) {
			$variation_id = absint( $variation_id );

			if ( $variation_id <= 0 ) {
				continue;
			}

			$custom_title = self::get_variation_custom_title( $variation_id );
			$description  = self::get_variation_custom_description( $variation_id );

			if ( '' === $custom_title && '' === $description ) {
				continue;
			}

			$snapshot[] = array(
				'variation_id'       => $variation_id,
				'custom_title'       => $custom_title,
				'custom_description' => $description,
			);
		}

		return $snapshot;
	}

	public static function get_variation_custom_title_meta_raw( $variation_id ) {
		$variation_id = absint( $variation_id );

		if ( $variation_id <= 0 ) {
			return '';
		}

		$custom = get_post_meta( $variation_id, self::WVE_VARIATION_CUSTOM_TITLE_META, true );

		return is_string( $custom ) ? trim( $custom ) : '';
	}

	public static function get_variation_custom_title( $variation_id ) {
		$variation_id = absint( $variation_id );

		if ( $variation_id <= 0 ) {
			return '';
		}

		$custom = self::get_variation_custom_title_meta_raw( $variation_id );

		if ( '' !== $custom ) {
			return $custom;
		}

		return trim( (string) get_post_field( 'post_title', $variation_id ) );
	}

	public static function get_variation_custom_description( $variation_id ) {
		$variation_id = absint( $variation_id );

		if ( $variation_id <= 0 ) {
			return '';
		}

		$custom = get_post_meta( $variation_id, self::WVE_VARIATION_CUSTOM_DESCRIPTION_META, true );

		return is_string( $custom ) ? trim( $custom ) : '';
	}

	public static function compute_product_variation_titles_fingerprint( $product_id ) {
		$snapshot = self::get_product_variation_title_snapshot( $product_id );

		return md5( wp_json_encode( $snapshot ) );
	}

	public static function refresh_product_variation_titles_fingerprint( $product_id ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return;
		}

		$snapshot = self::get_product_variation_title_snapshot( $product_id );

		update_post_meta( $product_id, '_polymart_ai_variation_titles_snapshot', $snapshot );
		update_post_meta( $product_id, '_polymart_ai_variation_titles_fingerprint', md5( wp_json_encode( $snapshot ) ) );
	}

}
