<?php
/**
 * Shared trait directory resolution (Linux-safe casing).
 *
 * @package PolymartAI
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'polymart_ai_resolve_traits_root' ) ) {
	/**
	 * Resolve the Traits/ directory next to a trait-loader.php file.
	 *
	 * @param string $loader_dir Directory containing trait-loader.php.
	 * @return string|null Absolute traits root, or null when missing.
	 */
	function polymart_ai_resolve_traits_root( $loader_dir ) {
		$loader_dir = rtrim( (string) $loader_dir, '/\\' );
		$preferred  = $loader_dir . '/Traits';

		if ( is_dir( $preferred ) ) {
			return $preferred;
		}

		if ( is_dir( $loader_dir ) ) {
			$entries = scandir( $loader_dir );
			if ( is_array( $entries ) ) {
				foreach ( $entries as $entry ) {
					if ( '.' === $entry || '..' === $entry ) {
						continue;
					}
					if ( 0 !== strcasecmp( $entry, 'Traits' ) ) {
						continue;
					}
					$candidate = $loader_dir . '/' . $entry;
					if ( is_dir( $candidate ) ) {
						return $candidate;
					}
				}
			}
		}

		return null;
	}
}
