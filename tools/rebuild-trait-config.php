<?php
/**
 * Rebuild trait-config.php with constants/properties only.
 */
declare( strict_types=1 );

$backup = __DIR__ . '/../includes/Translation/class-post-translator.full-backup.php';
$out    = __DIR__ . '/../includes/Translation/Post_Translator/Traits/Config/trait-config.php';
$lines  = file( $backup, FILE_IGNORE_NEW_LINES );

$class_open = null;
foreach ( $lines as $i => $line ) {
	if ( preg_match( '/^\s*final\s+class\s+Post_Translator\b/', $line ) ) {
		$class_open = $i;
		break;
	}
}

$pattern = '/^\s*(public|protected|private)\s+(static\s+)?function\s+\w+\s*\(/';
$method_ranges = array();
for ( $i = $class_open + 1; $i < count( $lines ); $i++ ) {
	if ( ! preg_match( $pattern, $lines[ $i ], $m ) ) {
		continue;
	}
	$depth = 0;
	$end   = $i;
	for ( $j = $i; $j < count( $lines ); $j++ ) {
		$depth += substr_count( $lines[ $j ], '{' ) - substr_count( $lines[ $j ], '}' );
		if ( $depth <= 0 && $j > $i ) {
			$end = $j;
			break;
		}
	}
	$method_ranges[] = array( $i, $end );
}

$in_method = static function ( int $line ) use ( $method_ranges ): bool {
	foreach ( $method_ranges as $range ) {
		if ( $line >= $range[0] && $line <= $range[1] ) {
			return true;
		}
	}
	return false;
};

$config = array();
$pending_doc = array();
$in_array    = false;

for ( $i = $class_open + 1; $i < count( $lines ) - 1; $i++ ) {
	if ( $in_method( $i ) ) {
		$pending_doc = array();
		$in_array    = false;
		continue;
	}

	$line = $lines[ $i ];

	if ( preg_match( '/^\s*(\/\*\*|\*\/|\*\s)/', $line ) ) {
		$pending_doc[] = $line;
		continue;
	}

	if ( preg_match( '/^\s*(public|private|protected)\s+(static\s+)?(const\s+\w+|\$\w+)/', $line ) ) {
		foreach ( $pending_doc as $doc_line ) {
			$config[] = $doc_line;
		}
		$pending_doc = array();
		$config[]    = $line;
		$in_array    = ( substr_count( $line, '(' ) > substr_count( $line, ')' ) ) || ( str_contains( $line, 'array(' ) && ! str_contains( $line, ');' ) );
		continue;
	}

	if ( $in_array ) {
		$config[] = $line;
		if ( preg_match( '/\);\s*$/', $line ) ) {
			$in_array = false;
		}
		continue;
	}

	if ( trim( $line ) === '' ) {
		$config[]    = '';
		$pending_doc = array();
	}
}

$header = <<<'PHP'
<?php
/**
 * Post_Translator shared constants and per-request caches.
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits;

use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Post_Translator\Persistence_Guard;
use PolymartAI\Translation\Post_Translator\Translation_Lock;

defined( 'ABSPATH' ) || exit;

trait Trait_Config {


PHP;

$footer = "\n}\n";
file_put_contents( $out, $header . implode( "\n", $config ) . $footer );
echo 'Wrote ' . $out . ' (' . count( $config ) . " config lines)\n";

PHP;

exec( 'php -l ' . escapeshellarg( $out ), $output, $code );
echo implode( "\n", $output ) . "\n";
