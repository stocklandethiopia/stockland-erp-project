<?php
/**
 * Regression checks for prepared accounting read queries.
 *
 * @package Stockland_ERP
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}

$root       = dirname( __DIR__, 2 );
$sourcePath = $root . '/includes/rest/class-rest-accounting.php';
$source     = file_get_contents( $sourcePath );
if ( ! is_string( $source ) ) {
	fwrite( STDERR, "FAIL: accounting REST source could not be read.\n" );
	exit( 1 );
}

$cases = array(
	'supplier invoices' => array(
		'start' => 'public function supplier_invoices(',
		'end'   => '/**\n\t * Creates a supplier invoice',
		'needles' => array( 'FROM %i i', 'LEFT JOIN %i u', 'WHERE (%d = 0 OR i.status = %s)', '$wpdb->prepare(' ),
	),
	'customer invoices' => array(
		'start' => 'public function customer_invoices(',
		'end'   => '/**\n\t * Inserts a customer payment',
		'needles' => array( 'FROM %i i', 'JOIN %i c', 'LEFT JOIN %i l', '$wpdb->prepare(' ),
	),
	'expenses' => array(
		'start' => 'public function expenses(',
		'end'   => '/**\n\t * Creates an expense record',
		'needles' => array( 'FROM %i e', 'LEFT JOIN %i l', 'FIND_IN_SET(e.location_id, %s)', 'WHERE (%d = 0 OR e.status = %s)', '$wpdb->prepare(' ),
	),
	'payments' => array(
		'start' => 'public function payments(',
		'end'   => '/**\n\t * Records an internal customer payment',
		'needles' => array( 'FROM %i p', 'LEFT JOIN %i u', 'WHERE (%d = 0 OR p.status = %s)', '$wpdb->prepare(' ),
	),
	'trial balance' => array(
		'start' => 'public function trial_balance(',
		'end'   => '/**\n\t * Builds the authorized financial summary',
		'needles' => array( 'FROM %i a', 'LEFT JOIN %i l', "j.status='POSTED'", '$wpdb->prepare(' ),
	),
	'general ledger' => array(
		'start' => 'public function gl(',
		'end'   => "\n}",
		'needles' => array( 'FROM %i j', 'JOIN %i l', 'AND (%d = 0 OR j.journal_date >= %s)', 'AND (%d = 0 OR j.journal_date <= %s)', '$wpdb->prepare(' ),
	),
);

foreach ( $cases as $label => $case ) {
	$start = strpos( $source, $case['start'] );
	$end   = false === $start ? false : strpos( $source, $case['end'], $start + strlen( $case['start'] ) );
	if ( false === $start || false === $end ) {
		fwrite( STDERR, 'FAIL: could not locate accounting ' . $label . " method.\n" );
		exit( 1 );
	}
	$method = substr( $source, $start, $end - $start );
	foreach ( $case['needles'] as $needle ) {
		if ( false === strpos( $method, $needle ) ) {
			fwrite( STDERR, 'FAIL: accounting ' . $label . ' method is missing prepared-query contract: ' . $needle . "\n" );
			exit( 1 );
		}
	}
	if ( false !== strpos( $method, '$sql' ) || false !== strpos( $method, '$where' ) ) {
		fwrite( STDERR, 'FAIL: accounting ' . $label . ' query still builds SQL fragments dynamically.' . "\n" );
		exit( 1 );
	}
	echo 'PASS: accounting ' . $label . " read query is static and prepared.\n";
}

echo "Accounting prepared-read regression checks PASS.\n";
