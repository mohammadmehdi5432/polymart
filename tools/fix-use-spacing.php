<?php
$root = dirname( __DIR__ ) . '/includes';
$iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
$fixed = 0;
foreach ( $iter as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}
	$path = $file->getPathname();
	$c    = file_get_contents( $path );
	$n    = preg_replace( '/((?:^use [^;]+;\s*\n)+)(defined\s*\(\s*\'ABSPATH\'\s*\))/m', "$1\n$2", $c, -1, $count );
	if ( $count ) {
		file_put_contents( $path, $n );
		$fixed++;
	}
}
echo "formatted: {$fixed}\n";
