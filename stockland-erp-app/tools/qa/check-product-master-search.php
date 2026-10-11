<?php
/**
 * Static regression checks for safe Product Master search parameter ordering.
 *
 * @package Stockland_ERP
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}

$root       = dirname( __DIR__, 2 );
$sourcePath = $root . '/includes/rest/class-rest-products.php';
$source     = file_get_contents( $sourcePath );
if ( ! is_string( $source ) ) {
	fwrite( STDERR, "FAIL: Product Master source could not be read.\n" );
	exit( 1 );
}

$start = strpos( $source, 'private function check_identifier_unique(' );
$end   = strpos( $source, 'public function get_product(', $start );
if ( false === $start || false === $end ) {
	fwrite( STDERR, "FAIL: Product Master search methods could not be located.\n" );
	exit( 1 );
}
$methods = substr( $source, $start, $end - $start );
$like_position = strpos( $methods, 'array_fill( 0, 9, $like )' );
$exact_args_match = preg_match( '/array\\(\\s*\\$q,\\s*\\$q,\\s*\\$limit,\\s*\\)/', $methods, $exact_args, PREG_OFFSET_CAPTURE );
$exact_args_position = 1 === $exact_args_match ? $exact_args[0][1] : false;

$checks = array(
	'Identifier uniqueness uses prepared table and column identifiers' =>
		false !== strpos( $methods, 'SELECT sku FROM %i WHERE %i = %s AND sku <> %s LIMIT 1' ),
	'Product Master query binds its table identifier with %i' =>
		false !== strpos( $methods, 'FROM %i' ),
	'Search query has a static prepared SQL template' =>
		false !== strpos( $methods, '$wpdb->prepare(' ) && false === strpos( $methods, '$sql' ) && false === strpos( $methods, '$where' ),
	'Nine LIKE values are bound before exact-SKU and limit values' =>
		false !== $like_position &&
		false !== $exact_args_position &&
		$like_position < $exact_args_position &&
		false === strpos( $methods, 'array_unshift( $args, $q )' ),
	'Search limit remains bounded to at most 500 rows' =>
		false !== strpos( $methods, 'min( 500, max( 1,' ),
);

foreach ( $checks as $name => $passed ) {
	if ( ! $passed ) {
		fwrite( STDERR, 'FAIL: ' . $name . "\n" );
		exit( 1 );
	}
	echo 'PASS: ' . $name . "\n";
}

echo "Product Master search regression checks PASS.\n";
