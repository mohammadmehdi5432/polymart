<?php
/**
 * Post_Translator Term (auto-split).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\Term;

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\AI_Client;
use PolymartAI\Translation\Persian_Detector;
use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Post_Translator\Persistence_Guard;
use PolymartAI\Translation\Post_Translator\Storefront_Resolver;
use PolymartAI\Translation\Post_Translator\Text_Normalizer;
use PolymartAI\Translation\Post_Translator\Translation_Lock;

defined( 'ABSPATH' ) || exit;

trait Trait_Term {

	public static function term_has_persian_content( $term_id ) {
		return ! empty( self::collect_term_persian_fields( $term_id ) );
	}

	public static function collect_term_persian_fields( $term ) {
		if ( is_numeric( $term ) ) {
			$term = get_term( absint( $term ) );
		}

		if ( ! $term instanceof \WP_Term || ! in_array( $term->taxonomy, self::get_translatable_taxonomies(), true ) ) {
			return array();
		}

		$fields = array();

		$name = Persian_Detector::only_persian_value( $term->name );

		if ( '' !== $name ) {
			$fields[ self::build_term_payload_key( $term->term_id, 'name' ) ] = $name;
		}

		$description = Persian_Detector::only_persian_value( $term->description );

		if ( '' !== $description ) {
			$fields[ self::build_term_payload_key( $term->term_id, 'desc' ) ] = $description;
		}

		return $fields;
	}

}
