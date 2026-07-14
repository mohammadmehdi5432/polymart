<?php
/**
 * Add missing use statements after namespace reorganization.
 */
$fixes = array(
	'includes/Activity_Logger/class-job-action-scheduler.php' => array(
		'PolymartAI\\Translation\\Post_Translator',
	),
	'includes/Activity_Logger/class-metabox-action-scheduler.php' => array(
		'PolymartAI\\Language_Registry',
	),
	'includes/Translation/AI/class-ai-client.php' => array(
		'PolymartAI\\Translation\\Post_Translator',
	),
	'includes/Translation/Content/class-comment-translator.php' => array(
		'PolymartAI\\Translation\\AI\\AI_Client',
		'PolymartAI\\Translation\\AI\\Persian_Detector',
		'PolymartAI\\Translation\\Storefront\\Runtime_String_Translator',
		'PolymartAI\\Translation\\Post_Translator',
	),
	'includes/Translation/Content/class-menu-translator.php' => array(
		'PolymartAI\\Translation\\AI\\AI_Client',
		'PolymartAI\\Translation\\AI\\Persian_Detector',
		'PolymartAI\\Translation\\Storefront\\Runtime_String_Translator',
		'PolymartAI\\Translation\\Post_Translator',
	),
	'includes/Translation/Content/class-option-translator.php' => array(
		'PolymartAI\\Translation\\AI\\Persian_Detector',
		'PolymartAI\\Translation\\Storefront\\Runtime_String_Translator',
	),
	'includes/Translation/Pipeline/class-async-translator.php' => array(
		'PolymartAI\\Translation\\Storefront\\Runtime_String_Translator',
		'PolymartAI\\Translation\\Post_Translator',
	),
	'includes/Translation/Pipeline/class-translation-query.php' => array(
		'PolymartAI\\Translation\\Post_Translator',
	),
	'includes/Translation/Pipeline/class-universal-translator.php' => array(
		'PolymartAI\\Translation\\AI\\Persian_Detector',
		'PolymartAI\\Translation\\Storefront\\Runtime_String_Translator',
		'PolymartAI\\Translation\\UI_String\\UI_String_Registry',
		'PolymartAI\\Translation\\WooCommerce\\WooCommerce_Translator',
		'PolymartAI\\Translation\\Post_Translator',
	),
	'includes/Translation/Post_Translator/Traits/AiPersistence/trait-ai-persistence.php' => array(
		'PolymartAI\\Translation\\Storefront\\Runtime_String_Translator',
	),
	'includes/Translation/Post_Translator/Traits/Invalidation/trait-invalidation.php' => array(
		'PolymartAI\\Translation\\Storefront\\Runtime_String_Translator',
	),
	'includes/Translation/Storefront/class-frontend-interceptor.php' => array(
		'PolymartAI\\Translation\\WooCommerce\\WooCommerce_Translator',
	),
	'includes/Translation/UI_String/class-ui-string-bulk-job.php' => array(
		'PolymartAI\\Translation\\AI\\AI_Client',
		'PolymartAI\\Translation\\Post_Translator',
	),
	'includes/Translation/UI_String/class-ui-string-registry.php' => array(
		'PolymartAI\\Translation\\AI\\Persian_Detector',
	),
	'includes/Translation/WooCommerce/class-variation-translation-sync.php' => array(
		'PolymartAI\\Translation\\Post_Translator',
	),
	'includes/Translation/WooCommerce/class-woocommerce-translator.php' => array(
		'PolymartAI\\Translation\\AI\\Persian_Detector',
		'PolymartAI\\Translation\\Storefront\\Runtime_String_Translator',
		'PolymartAI\\Translation\\UI_String\\UI_String_Registry',
		'PolymartAI\\Translation\\Post_Translator',
	),
);

$root = dirname( __DIR__ );

foreach ( $fixes as $rel => $toAdd ) {
	$path = $root . '/' . $rel;
	if ( ! is_readable( $path ) ) {
		echo "MISSING: $rel\n";
		continue;
	}
	$content = file_get_contents( $path );
	if ( ! preg_match( '/^namespace\s+([^;]+);/m', $content, $nm ) ) {
		echo "NO_NS: $rel\n";
		continue;
	}

	$existing = array();
	if ( preg_match_all( '/^use\s+([^;{]+);/m', $content, $uses ) ) {
		foreach ( $uses[1] as $useStmt ) {
			$useStmt = trim( $useStmt );
			if ( 0 === strpos( $useStmt, 'function ' ) || 0 === strpos( $useStmt, 'const ' ) ) {
				continue;
			}
			$parts = preg_split( '/\s+as\s+/', $useStmt );
			$existing[ trim( $parts[0] ) ] = true;
		}
	}

	$missing = array();
	foreach ( $toAdd as $fqcn ) {
		if ( ! isset( $existing[ $fqcn ] ) ) {
			$missing[] = $fqcn;
		}
	}
	if ( empty( $missing ) ) {
		echo "OK: $rel\n";
		continue;
	}

	sort( $missing );
	$useLines = '';
	foreach ( $missing as $fqcn ) {
		$useLines .= "use {$fqcn};\n";
	}

	if ( preg_match( '/^namespace\s+[^;]+;\s*\n/m', $content, $m, PREG_OFFSET_CAPTURE ) ) {
		$insertAt = $m[0][1] + strlen( $m[0][0] );
		// Insert after existing use block if present.
		if ( preg_match( '/^namespace\s+[^;]+;\s*\n((?:use\s+[^;]+;\s*\n)+)/m', $content, $um, PREG_OFFSET_CAPTURE ) ) {
			$insertAt = $um[1][1] + strlen( $um[1][0] );
		}
		$content = substr( $content, 0, $insertAt ) . $useLines . substr( $content, $insertAt );
		file_put_contents( $path, $content );
		echo "FIXED: $rel (" . count( $missing ) . " uses)\n";
	} else {
		echo "FAIL_INSERT: $rel\n";
	}
}
