<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
$root    = dirname( __DIR__, 2 );
$targets = array(
	$root . '/includes/rest/class-rest-import-v16.php' => array( 'capability_daily', 'capability_product', 'capability_daily_commit', 'capability_mappings' ),
	$root . '/includes/rest/class-rest-inventory-report-v16.php' => array( 'permission' ),
);
$errors  = array();
foreach ( $targets as $file => $methods ) {
	if ( ! file_exists( $file ) ) {
		$errors[] = 'Missing file ' . basename( $file );
		continue;}
	$src = file_get_contents( $file );
	foreach ( $methods as $m ) {
		if ( ! preg_match( '/\bpublic\s+function\s+' . preg_quote( $m, '/' ) . '\s*\(/', $src ) ) {
			$errors[] = 'Required public callback missing: ' . basename( $file ) . '::' . $m;
		}
	}
}
if ( $errors ) {
	fwrite( STDERR, "REST CALLBACK INTEGRITY FAILED\n" . implode( "\n", $errors ) . "\n" );
	exit( 1 );}
echo "REST CALLBACK INTEGRITY PASS: Product/POS/Inventory REST permission callbacks are public.\n";
