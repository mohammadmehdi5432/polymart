<?php
/**
 * Loads Activity_Logger trait files (traits are not PSR-4 classes).
 *
 * Leaf traits load before composite traits (deepest paths first).
 *
 * @package PolymartAI\Activity_Logger
 */

defined( 'ABSPATH' ) || exit;

$traits_root = __DIR__ . '/Traits';

if ( ! is_dir( $traits_root ) ) {
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
			return (bool) preg_match( '/trait-(?:config|job|queue)\.php$/', $path );
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
