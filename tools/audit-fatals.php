<?php
/**
 * Confirm PHP "Class not found" fatals from unqualified references.
 */
$pluginRoot = dirname( __DIR__ );
$includes   = $pluginRoot . '/includes/';

function autoload_readable( string $fqcn, string $pluginRoot ): bool {
	$prefix = 'PolymartAI\\';
	if ( strpos( $fqcn, $prefix ) !== 0 ) {
		return false;
	}
	$relative  = substr( $fqcn, strlen( $prefix ) );
	$parts     = explode( '\\', $relative );
	$className = array_pop( $parts );
	$fileName  = 'class-' . strtolower( str_replace( '_', '-', $className ) ) . '.php';
	$base      = $pluginRoot . '/includes/';
	$file      = empty( $parts ) ? $base . $fileName : $base . implode( '/', $parts ) . '/' . $fileName;
	return is_readable( $file );
}

$correct = array(
	'AI_Client'               => 'PolymartAI\\Translation\\AI\\AI_Client',
	'Persian_Detector'        => 'PolymartAI\\Translation\\AI\\Persian_Detector',
	'Runtime_String_Translator' => 'PolymartAI\\Translation\\Storefront\\Runtime_String_Translator',
	'WooCommerce_Translator'  => 'PolymartAI\\Translation\\WooCommerce\\WooCommerce_Translator',
	'Universal_Translator'    => 'PolymartAI\\Translation\\Pipeline\\Universal_Translator',
	'Menu_Translator'         => 'PolymartAI\\Translation\\Content\\Menu_Translator',
	'Translation_Query'       => 'PolymartAI\\Translation\\Pipeline\\Translation_Query',
	'Async_Translator'        => 'PolymartAI\\Translation\\Pipeline\\Async_Translator',
	'Post_Translator'         => 'PolymartAI\\Translation\\Post_Translator',
	'Language_Registry'       => 'PolymartAI\\Language_Registry',
	'Activity_Logger'         => 'PolymartAI\\Activity_Logger',
	'REST_API'                => 'PolymartAI\\REST_API',
	'Url_Router'              => 'PolymartAI\\Routing\\Url_Router',
	'Product_Diagnostics'     => 'PolymartAI\\Translation\\WooCommerce\\Product_Diagnostics',
	'Frontend_Interceptor'    => 'PolymartAI\\Translation\\Storefront\\Frontend_Interceptor',
	'UI_String_Registry'      => 'PolymartAI\\Translation\\UI_String\\UI_String_Registry',
	'UI_String_Scanner'       => 'PolymartAI\\Translation\\UI_String\\UI_String_Scanner',
	'UI_String_Bulk_Job'      => 'PolymartAI\\Translation\\UI_String\\UI_String_Bulk_Job',
	'UI_String_Source_Scanner' => 'PolymartAI\\Translation\\UI_String\\UI_String_Source_Scanner',
	'UI_String_Storefront_Filter' => 'PolymartAI\\Translation\\UI_String\\UI_String_Storefront_Filter',
	'Storefront_Gettext_Resolver' => 'PolymartAI\\Translation\\Storefront\\Storefront_Gettext_Resolver',
	'Layout_Guard'            => 'PolymartAI\\Translation\\Storefront\\Layout_Guard',
	'Job_Action_Scheduler'    => 'PolymartAI\\Activity_Logger\\Job_Action_Scheduler',
	'Metabox_Action_Scheduler'=> 'PolymartAI\\Activity_Logger\\Metabox_Action_Scheduler',
);

$fatals = array();

foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $includes ) ) as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}
	$path = str_replace( '\\', '/', $file->getPathname() );
	$rel  = str_replace( $pluginRoot . '/', '', $path );
	$lines = file( $path );
	$content = implode( '', $lines );
	if ( ! preg_match( '/^namespace\s+([^;]+);/m', $content, $nm ) ) {
		continue;
	}
	$ns = trim( $nm[1] );

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

	foreach ( $correct as $short => $fqcn ) {
		$resolvedInNs = $ns . '\\' . $short;
		// Same-namespace class exists at autoload path?
		if ( autoload_readable( $resolvedInNs, $pluginRoot ) ) {
			continue; // unqualified resolves fine
		}
		if ( isset( $imports[ $short ] ) ) {
			continue; // imported
		}

		$pattern = '/(?<!\\\\)\b' . preg_quote( $short, '/' ) . '\b/';
		foreach ( $lines as $i => $line ) {
			if ( preg_match( '/^\s*(namespace|use|\/\/|\/\*|\*)\s/', $line ) ) {
				continue;
			}
			// Skip FQCN references on line.
			if ( false !== strpos( $line, '\\' . $short ) ) {
				continue;
			}
			if ( preg_match( $pattern, $line ) ) {
				$fatals[ $rel . ':' . ( $i + 1 ) ] = array(
					'file'    => $rel,
					'line'    => $i + 1,
					'wrong'   => $resolvedInNs,
					'correct' => $fqcn,
					'fix'     => "add: use {$fqcn};",
					'snippet' => trim( $line ),
				);
			}
		}
	}
}

// Dedupe by file - group
$byFile = array();
foreach ( $fatals as $f ) {
	$byFile[ $f['file'] ][ $f['correct'] ] = true;
}

echo 'CONFIRMED_FATALS ' . count( $fatals ) . "\n";
foreach ( $fatals as $f ) {
	echo json_encode( $f, JSON_UNESCAPED_SLASHES ) . "\n";
}
echo "\nFILES_NEEDING_USE:\n";
foreach ( $byFile as $file => $uses ) {
	echo $file . " => " . implode( ', ', array_keys( $uses ) ) . "\n";
}
