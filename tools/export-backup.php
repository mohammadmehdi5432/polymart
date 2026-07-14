<?php
$dest = __DIR__ . '/../includes/Translation/class-post-translator.full-backup.php';
$c    = shell_exec( 'git -C ' . escapeshellarg( dirname( __DIR__ ) ) . ' show HEAD:includes/Translation/class-post-translator.php' );
if ( ! is_string( $c ) || '' === $c ) {
	fwrite( STDERR, "git export failed\n" );
	exit( 1 );
}
file_put_contents( $dest, $c );
echo 'Exported ' . strlen( $c ) . " bytes\n";
