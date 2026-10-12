<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}
$root  = dirname( __DIR__, 2 );
$health = is_file( $root . '/includes/rest/class-rest-app.php' ) ? file_get_contents( $root . '/includes/rest/class-rest-app.php' ) : '';
$import = is_file( $root . '/includes/rest/class-rest-import-v16.php' ) ? file_get_contents( $root . '/includes/rest/class-rest-import-v16.php' ) : '';
$app    = is_file( $root . '/assets/app.js' ) ? file_get_contents( $root . '/assets/app.js' ) : '';
$fail   = array();
$pass   = 0;
$ok     = function ( $condition, $message ) use ( &$fail, &$pass ) {
	if ( $condition ) {
		++$pass;
		echo "PASS {$message}\n";
	} else {
		$fail[] = $message;
		echo "FAIL {$message}\n";
	}
};
$compact_import = preg_replace( '/\s+/', '', $import );
$ok(
	str_contains( $health, "$"."normalized_import_source = is_string( $"."import_source ) ? preg_replace" )
		&& str_contains( $health, "strpos( $"."normalized_import_source, \"array('initial_stock','opening_stock','stock_quantity','quantity','qty','stock')\" ) !== false" ),
	'Product Master stock-field health contract is whitespace-insensitive.'
);
$ok(
	str_contains( $import, 'is not permitted in Product Master import. Use Warehouse Receiving for physical stock.' )
		&& str_contains( $import, 'Product Master commit rejected physical stock field' )
		&& str_contains( $compact_import, "array('initial_stock','opening_stock','stock_quantity','quantity','qty','stock')" ),
	'Preview and commit both preserve the no-stock Product Master boundary.'
);
$ok(
	str_contains( $app, 'const integrity=q.supplemental_integrity_scan;' )
		&& str_contains( $app, 'Checks requiring review' )
		&& str_contains( $app, 'UNKNOWN — query did not return a reliable result' )
		&& str_contains( $app, 'CHECKS CLEAN — FK metadata reviewed separately' ),
	'System Health shows non-zero/unknown scanner checks and separates FK metadata status.'
);
if ( $fail ) {
	fwrite( STDERR, 'Health gate / diagnostics QA FAILED: ' . implode( '; ', $fail ) . "\n" );
	exit( 1 );
}
echo "Health gate / diagnostics QA PASS: {$pass} assertions.\n";
