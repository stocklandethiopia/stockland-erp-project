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

$stock_source  = file_get_contents( $root . '/includes/rest/class-rest-stock.php' );
$return_source = file_get_contents( $root . '/includes/rest/class-rest-returns.php' );
$sales_source  = file_get_contents( $root . '/includes/rest/class-rest-sales.php' );
if ( ! is_string( $stock_source ) || ! is_string( $return_source ) || ! is_string( $sales_source ) ) {
	fwrite( STDERR, "FAIL: stock, returns, or sales source could not be read.\n" );
	exit( 1 );
}
$stock_start = strpos( $stock_source, 'public function get_stock( WP_REST_Request $request ) {' );
$stock_end   = strpos( $stock_source, '/** Mirrors REORDER ACTIONS sheet' );
$reorder_start = strpos( $stock_source, 'public function get_reorder_status( WP_REST_Request $request ) {' );
$reorder_end = strrpos( $stock_source, "\n}" );
$return_start = strpos( $return_source, 'public function list_returns( WP_REST_Request $request ) {' );
$return_end = strpos( $return_source, 'public function create_return( WP_REST_Request $request ) {' );
$sales_start = strpos( $sales_source, 'public function list_sales( WP_REST_Request $request ) {' );
$sales_end = strpos( $sales_source, 'public function create_sale( WP_REST_Request $request ) {' );
if ( false === $stock_start || false === $stock_end || false === $reorder_start || false === $reorder_end || false === $return_start || false === $return_end || false === $sales_start || false === $sales_end ) {
	fwrite( STDERR, "FAIL: one or more stock/returns/sales list methods could not be located.\n" );
	exit( 1 );
}
$stock_methods   = substr( $stock_source, $stock_start, $stock_end - $stock_start );
$reorder_methods = substr( $stock_source, $reorder_start, $reorder_end - $reorder_start );
$returns_methods = substr( $return_source, $return_start, $return_end - $return_start );
$sales_methods   = substr( $sales_source, $sales_start, $sales_end - $sales_start );
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
	'Stock reads use a static prepared query with bound location and SKU filters' =>
		false !== strpos( $stock_methods, 'FROM %i t WHERE t.variant_id IS NOT NULL' ) &&
		false !== strpos( $stock_methods, 'AND (%d = 0 OR v.location_id=%d)' ) &&
		false !== strpos( $stock_methods, 'AND (%d = 0 OR v.variant_id=%d)' ) &&
		false === strpos( $stock_methods, '$sql .=' ),
	'Reorder reads bind location filter without query concatenation' =>
		false !== strpos( $reorder_methods, 'FROM %i p CROSS JOIN %i l' ) &&
		false !== strpos( $reorder_methods, 'AND (%d = 0 OR l.id=%d)' ) &&
		false === strpos( $reorder_methods, '$sql .=' ),
	'Returns list binds table and optional location in one prepared statement' =>
		false !== strpos( $returns_methods, 'SELECT * FROM %i WHERE (%d = 0 OR location_id = %d)' ) &&
		false === strpos( $returns_methods, '$sql .=' ),
	'Sales lists use separate static column sets and bound table/location/date filters' =>
		false !== strpos( $sales_methods, 'posting_status,cogs,gross_profit' ) &&
		false !== strpos( $sales_methods, 'FROM %i' ) &&
		false !== strpos( $sales_methods, 'AND (%d = 0 OR sale_date = %s)' ) &&
		false === strpos( $sales_methods, '$cols .=' ) &&
		false === strpos( $sales_methods, '$sql .=' ),
);

foreach ( $checks as $name => $passed ) {
	if ( ! $passed ) {
		fwrite( STDERR, 'FAIL: ' . $name . "\n" );
		exit( 1 );
	}
	echo 'PASS: ' . $name . "\n";
}

echo "Product Master search regression checks PASS.\n";
