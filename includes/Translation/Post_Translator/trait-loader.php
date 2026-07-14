<?php
/**
 * Loads Post_Translator trait files (traits are not PSR-4 classes).
 *
 * Leaf traits load before composite traits (deepest paths first).
 *
 * @package PolymartAI\Translation\Post_Translator
 */

defined( 'ABSPATH' ) || exit;

$polymart_ai_trait_path = ( defined( 'POLYMART_AI_PLUGIN_DIR' ) ? POLYMART_AI_PLUGIN_DIR : dirname( __DIR__, 2 ) . '/' ) . 'includes/trait-path.php';
require_once $polymart_ai_trait_path;

$traits_root = polymart_ai_resolve_traits_root( __DIR__ );

if ( null === $traits_root ) {
	trigger_error(
		'PolyMartAI trait-loader: Traits directory not found beside ' . __DIR__,
		E_USER_ERROR
	);
	return;
}

$files = array();
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $traits_root, FilesystemIterator::SKIP_DOTS )
);

foreach ( $iterator as $file_info ) {
	if ( ! $file_info->isFile() ) {
		continue;
	}

	$name = $file_info->getFilename();
	if ( 0 === strpos( $name, 'trait-' ) && '.php' === substr( $name, -4 ) ) {
		$files[] = $file_info->getPathname();
	}
}

usort(
	$files,
	static function ( string $a, string $b ): int {
		$depth_a = substr_count( str_replace( '\\', '/', $a ), '/' );
		$depth_b = substr_count( str_replace( '\\', '/', $b ), '/' );

		if ( $depth_a !== $depth_b ) {
			return $depth_b <=> $depth_a;
		}

		$composite = static function ( string $path ): bool {
			return (bool) preg_match( '/trait-(?:config|elementor|job)\.php$/', $path );
		};

		$composite_a = $composite( $a );
		$composite_b = $composite( $b );

		if ( $composite_a !== $composite_b ) {
			return $composite_a <=> $composite_b;
		}

		return strcmp( $a, $b );
	}
);

foreach ( $files as $trait_file ) {
	require_once $trait_file;
}
