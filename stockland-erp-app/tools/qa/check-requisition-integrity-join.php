<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}
$root = dirname( __DIR__, 2 );
$integrity = file_get_contents( $root . '/includes/class-stockland-integrity.php' );
$schema = file_get_contents( $root . '/includes/class-stockland-activator.php' );
if ( false === $integrity || false === $schema ) {
	fwrite( STDERR, "FAIL: unable to read requisition integrity sources.\n" );
	exit( 1 );
}
$expected_query = "'requisition_items_without_header'             => \"SELECT COUNT(*) FROM {\$t('requisition_items')} ri LEFT JOIN {\$t('requisitions')} r ON r.requisition_id=ri.requisition_id WHERE r.requisition_id IS NULL\"";
if ( false === strpos( $integrity, $expected_query ) ) {
	fwrite( STDERR, "FAIL: requisition orphan scan must join to requisitions.requisition_id.\n" );
	exit( 1 );
}
if ( false !== strpos( $integrity, "r.id=ri.requisition_id" ) ) {
	fwrite( STDERR, "FAIL: stale join to nonexistent requisitions.id remains.\n" );
	exit( 1 );
}
$start = strpos( $schema, 'CREATE TABLE {$p}stock_requisitions (' );
$end = false === $start ? false : strpos( $schema, 'ENGINE=InnoDB', $start );
$header_ddl = false === $start || false === $end ? '' : substr( $schema, $start, $end - $start );
if ( false === strpos( $header_ddl, 'PRIMARY KEY  (requisition_id)' ) ) {
	fwrite( STDERR, "FAIL: expected requisition_id primary key not found in schema DDL.\n" );
	exit( 1 );
}
echo "Requisition integrity join regression PASS.\n";
