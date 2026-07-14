<?php
/**
 * Autoload / namespace audit for PolyMartAI plugin.
 * Run: php tools/audit-autoload.php
 */

$pluginRoot = dirname( __DIR__ );
$includes   = $pluginRoot . '/includes/';

function autoload_path( string $fqcn, string $pluginRoot ): ?string {
	$prefix = 'PolymartAI\\';
	if ( strpos( $fqcn, $prefix ) !== 0 ) {
		return null;
	}
	$relative  = substr( $fqcn, strlen( $prefix ) );
	$parts     = explode( '\\', $relative );
	$className = array_pop( $parts );
	$fileName  = 'class-' . strtolower( str_replace( '_', '-', $className ) ) . '.php';
	$base      = $pluginRoot . '/includes/';
	if ( ! empty( $parts ) ) {
		return $base . implode( '/', $parts ) . '/' . $fileName;
	}
	return $base . $fileName;
}

function trait_path( string $fqcn, string $pluginRoot ): ?string {
	$prefix = 'PolymartAI\\';
	if ( strpos( $fqcn, $prefix ) !== 0 ) {
		return null;
	}
	$relative  = substr( $fqcn, strlen( $prefix ) );
	$parts     = explode( '\\', $relative );
	$traitName = array_pop( $parts );
	$fileName  = 'trait-' . strtolower( str_replace( '_', '-', $traitName ) ) . '.php';
	$base      = $pluginRoot . '/includes/';
	if ( ! empty( $parts ) ) {
		return $base . implode( '/', $parts ) . '/' . $fileName;
	}
	return $base . $fileName;
}

$classMap = array();
$iter     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $includes ) );
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
			$fqcn               = $ns . '\\' . $cn;
			$classMap[ $fqcn ][] = $path;
		}
	}
	if ( preg_match_all( '/^trait\s+(\w+)/m', $content, $tm ) ) {
		foreach ( $tm[1] as $tn ) {
			$fqcn               = $ns . '\\' . $tn;
			$classMap[ $fqcn ][] = $path . ' [trait]';
		}
	}
}

$oldToNew = array(
	'PolymartAI\\Translation\\AI_Client'              => 'PolymartAI\\Translation\\AI\\AI_Client',
	'PolymartAI\\Translation\\Persian_Detector'       => 'PolymartAI\\Translation\\AI\\Persian_Detector',
	'PolymartAI\\Translation\\Menu_Translator'        => 'PolymartAI\\Translation\\Content\\Menu_Translator',
	'PolymartAI\\Translation\\Translation_Query'      => 'PolymartAI\\Translation\\Pipeline\\Translation_Query',
	'PolymartAI\\Translation\\Async_Translator'       => 'PolymartAI\\Translation\\Pipeline\\Async_Translator',
	'PolymartAI\\Job_Action_Scheduler'                => 'PolymartAI\\Activity_Logger\\Job_Action_Scheduler',
	'PolymartAI\\Metabox_Action_Scheduler'            => 'PolymartAI\\Activity_Logger\\Metabox_Action_Scheduler',
	'PolymartAI\\Translation\\Product_Diagnostics'    => 'PolymartAI\\Translation\\WooCommerce\\Product_Diagnostics',
	'PolymartAI\\Translation\\Storefront\\Product_Diagnostics' => 'PolymartAI\\Translation\\WooCommerce\\Product_Diagnostics',
	'PolymartAI\\Translation\\Universal_Translator'  => 'PolymartAI\\Translation\\Pipeline\\Universal_Translator',
	'PolymartAI\\Translation\\Comment_Translator'     => 'PolymartAI\\Translation\\Content\\Comment_Translator',
	'PolymartAI\\Translation\\Option_Translator'      => 'PolymartAI\\Translation\\Content\\Option_Translator',
	'PolymartAI\\Translation\\Frontend_Interceptor'  => 'PolymartAI\\Translation\\Storefront\\Frontend_Interceptor',
	'PolymartAI\\Translation\\Layout_Guard'         => 'PolymartAI\\Translation\\Storefront\\Layout_Guard',
	'PolymartAI\\Translation\\Runtime_String_Translator' => 'PolymartAI\\Translation\\Storefront\\Runtime_String_Translator',
	'PolymartAI\\Translation\\WooCommerce_Translator' => 'PolymartAI\\Translation\\WooCommerce\\WooCommerce_Translator',
	'PolymartAI\\Translation\\Storefront_Gettext_Resolver' => 'PolymartAI\\Translation\\Storefront\\Storefront_Gettext_Resolver',
);

$confirmed = array();
$suspicious = array();

// Autoload path mismatches for declared classes.
foreach ( $classMap as $fqcn => $files ) {
	if ( strpos( $fqcn, 'PolymartAI\\' ) !== 0 ) {
		continue;
	}
	$isTrait = strpos( $files[0], '[trait]' ) !== false;
	if ( $isTrait ) {
		continue;
	}
	$expected = autoload_path( $fqcn, $pluginRoot );
	$actual   = $files[0];
	if ( $expected && realpath( $expected ) !== realpath( $actual ) ) {
		$suspicious[] = array(
			'kind'             => 'class_wrong_path',
			'class'            => $fqcn,
			'declared_in'      => $actual,
			'autoload_expects' => $expected,
			'autoload_file_exists' => is_readable( $expected ),
		);
	}
	$realFiles = array_filter( $files, static fn( $f ) => strpos( $f, '[trait]' ) === false );
	if ( count( $realFiles ) > 1 ) {
		$suspicious[] = array(
			'kind'  => 'duplicate_class',
			'class' => $fqcn,
			'files' => array_values( $realFiles ),
		);
	}
}

// Scan references in all includes PHP files.
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $includes ) ) as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}
	$path  = str_replace( '\\', '/', $file->getPathname() );
	$lines = file( $path );
	$content = implode( '', $lines );

	if ( ! preg_match( '/^namespace\s+([^;]+);/m', $content, $nm ) ) {
		continue;
	}
	$currentNs = trim( $nm[1] );

	// Parse use imports.
	$imports = array();
	if ( preg_match_all( '/^use\s+([^;{]+);/m', $content, $uses ) ) {
		foreach ( $uses[1] as $useStmt ) {
			$useStmt = trim( $useStmt );
			if ( 0 === strpos( $useStmt, 'function ' ) || 0 === strpos( $useStmt, 'const ' ) ) {
				continue;
			}
			$parts = preg_split( '/\s+as\s+/', $useStmt );
			$fqcn  = trim( $parts[0] );
			$alias = isset( $parts[1] ) ? trim( $parts[1] ) : substr( strrchr( $fqcn, '\\' ), 1 );
			$imports[ $alias ] = $fqcn;
		}
	}

	// Validate use statements for PolymartAI classes.
	foreach ( $imports as $alias => $fqcn ) {
		if ( 0 !== strpos( $fqcn, 'PolymartAI\\' ) ) {
			continue;
		}
		$expected = autoload_path( $fqcn, $pluginRoot );
		if ( ! $expected || is_readable( $expected ) ) {
			continue;
		}
		// Trait?
		$tp = trait_path( $fqcn, $pluginRoot );
		if ( $tp && is_readable( $tp ) ) {
			continue;
		}
		if ( isset( $classMap[ $fqcn ] ) ) {
			continue;
		}
		$lineNum = 0;
		foreach ( $lines as $i => $line ) {
			if ( preg_match( '/use\s+' . preg_quote( str_replace( '\\', '\\\\', $fqcn ), '/' ) . '/', $line ) ) {
				$lineNum = $i + 1;
				break;
			}
		}
		$confirmed[] = array(
			'kind'    => 'broken_use',
			'file'    => str_replace( $pluginRoot . '/', '', $path ),
			'line'    => $lineNum,
			'wrong'   => $fqcn,
			'note'    => 'autoload path missing: ' . str_replace( $pluginRoot . '/', '', $expected ),
		);
	}

	// Old flat namespace references.
	foreach ( $oldToNew as $old => $new ) {
		$needle = $old;
		foreach ( $lines as $i => $line ) {
			if ( false !== strpos( $line, $needle ) ) {
				// Skip if it's the correct new namespace on same line.
				if ( false !== strpos( $line, $new ) ) {
					continue;
				}
				$confirmed[] = array(
					'kind'    => 'old_namespace',
					'file'    => str_replace( $pluginRoot . '/', '', $path ),
					'line'    => $i + 1,
					'wrong'   => $old,
					'correct' => $new,
					'content' => trim( $line ),
				);
			}
		}
	}

	// Unqualified PolymartAI-looking class refs without use (heuristic).
	$knownShort = array(
		'Post_Translator', 'AI_Client', 'Persian_Detector', 'Async_Translator',
		'Translation_Query', 'Menu_Translator', 'Job_Action_Scheduler', 'Metabox_Action_Scheduler',
		'Product_Diagnostics', 'Universal_Translator', 'Activity_Logger', 'Language_Registry',
		'REST_API', 'Url_Router', 'Runtime_String_Translator', 'Frontend_Interceptor',
		'WooCommerce_Translator', 'Comment_Translator', 'Option_Translator',
	);
	foreach ( $knownShort as $short ) {
		if ( isset( $imports[ $short ] ) ) {
			continue;
		}
		// Same-namespace resolution.
		$sameNs = $currentNs . '\\' . $short;
		if ( isset( $classMap[ $sameNs ] ) ) {
			continue;
		}
		$pattern = '/(?<!\\\\)\b' . preg_quote( $short, '/' ) . '\b/';
		foreach ( $lines as $i => $line ) {
			if ( preg_match( '/^\s*(namespace|use|\/\/|\/\*|\*)\s/', $line ) ) {
				continue;
			}
			if ( preg_match( $pattern, $line ) ) {
				// Guess intended FQCN from old map or common paths.
				$guesses = array();
				foreach ( $oldToNew as $old => $new ) {
					if ( str_ends_with( $old, '\\' . $short ) || $old === 'PolymartAI\\' . $short ) {
						$guesses[] = $new;
					}
				}
				$guesses[] = 'PolymartAI\\' . $short;
				$resolved  = null;
				foreach ( $guesses as $g ) {
					$p = autoload_path( $g, $pluginRoot );
					if ( $p && is_readable( $p ) ) {
						$resolved = $g;
						break;
					}
				}
				if ( $resolved && ! isset( $imports[ $short ] ) ) {
					$suspicious[] = array(
						'kind'     => 'unqualified_maybe_fatal',
						'file'     => str_replace( $pluginRoot . '/', '', $path ),
						'line'     => $i + 1,
						'ref'      => $short,
						'namespace'=> $currentNs,
						'likely'   => $resolved,
						'content'  => trim( $line ),
					);
				}
			}
		}
	}
}

echo "CONFIRMED (" . count( $confirmed ) . "):\n";
foreach ( $confirmed as $c ) {
	echo json_encode( $c, JSON_UNESCAPED_SLASHES ) . "\n";
}
echo "\nSUSPICIOUS (" . count( $suspicious ) . "):\n";
foreach ( $suspicious as $s ) {
	echo json_encode( $s, JSON_UNESCAPED_SLASHES ) . "\n";
}
