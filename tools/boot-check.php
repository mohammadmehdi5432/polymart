<?php
/**
 * Boot-check: validates autoload paths for every class/trait file in git tree.
 * Run: php tools/boot-check.php
 */
$root     = dirname( __DIR__ );
$includes = $root . '/includes';
$errors   = array();

function polymart_autoload_path( string $fqcn, string $root ): string {
	$prefix = 'PolymartAI\\';
	if ( 0 !== strpos( $fqcn, $prefix ) ) {
		return '';
	}
	$relative  = substr( $fqcn, strlen( $prefix ) );
	$parts     = explode( '\\', $relative );
	$className = array_pop( $parts );
	$fileName  = 'class-' . strtolower( str_replace( '_', '-', $className ) ) . '.php';
	$base      = $root . '/includes/';
	if ( ! empty( $parts ) ) {
		return $base . implode( '/', $parts ) . '/' . $fileName;
	}
	return $base . $fileName;
}

// 1) Every declared class must match autoload path and file must exist.
$iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $includes ) );
foreach ( $iter as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}
	$path    = str_replace( '\\', '/', $file->getPathname() );
	$content = file_get_contents( $path );
	if ( ! preg_match( '/^namespace\s+([^;]+);/m', $content, $nm ) ) {
		continue;
	}
	$ns = trim( $nm[1] );
	if ( preg_match_all( '/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $content, $cm ) ) {
		foreach ( $cm[1] as $cn ) {
			$fqcn     = $ns . '\\' . $cn;
			$expected = polymart_autoload_path( $fqcn, $root );
			if ( $expected && realpath( $expected ) !== realpath( $path ) ) {
				$errors[] = "AUTOPATH_MISMATCH: {$fqcn} in {$path} expects {$expected}";
			}
		}
	}
}

// 2) Trait loaders must find Traits directory.
foreach ( array(
	$includes . '/Translation/Post_Translator/trait-loader.php',
	$includes . '/Activity_Logger/trait-loader.php',
) as $loader ) {
	if ( ! is_readable( $loader ) ) {
		$errors[] = "MISSING_LOADER: {$loader}";
		continue;
	}
	$dir = dirname( $loader );
	if ( ! is_dir( $dir . '/Traits' ) ) {
		$errors[] = "MISSING_TRAITS_DIR: {$dir}/Traits";
	}
}

// 3) Simulate trait load (catches duplicate trait names).
define( 'ABSPATH', $root . '/fake-wp/' );
$loaded_traits = array();
foreach ( array(
	$includes . '/Translation/Post_Translator/trait-loader.php',
	$includes . '/Activity_Logger/trait-loader.php',
) as $loader ) {
	if ( ! is_readable( $loader ) ) {
		continue;
	}
	ob_start();
	try {
		require $loader;
	} catch ( Throwable $e ) {
		$errors[] = 'TRAIT_LOAD_FATAL: ' . $loader . ' => ' . $e->getMessage();
	}
	ob_end_clean();
}

// 4) Load all facade classes.
require $root . '/includes/class-autoloader.php';
PolymartAI\Autoloader::register();

$must_load = array(
	'PolymartAI\\Plugin',
	'PolymartAI\\Activity_Logger',
	'PolymartAI\\Translation\\Post_Translator',
	'PolymartAI\\Admin',
	'PolymartAI\\REST_API',
	'PolymartAI\\Routing\\Url_Router',
	'PolymartAI\\Frontend\\Currency',
);

foreach ( $must_load as $class ) {
	try {
		if ( ! class_exists( $class, true ) ) {
			$errors[] = "CLASS_NOT_FOUND: {$class}";
		}
	} catch ( Throwable $e ) {
		$errors[] = "CLASS_LOAD_FATAL: {$class} => " . $e->getMessage();
	}
}

if ( empty( $errors ) ) {
	echo "BOOT-CHECK OK\n";
	exit( 0 );
}

echo "BOOT-CHECK FAILED (" . count( $errors ) . ")\n";
foreach ( $errors as $err ) {
	echo $err . "\n";
}
exit( 1 );
