<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
const ABSPATH = '/tmp/';
function sanitize_key( $v ) {
	return preg_replace( '/[^a-z0-9_-]/i', '', strtolower( (string) $v ) );}
function get_current_user_id() {
	return 7;}
function current_time( $type ) {
	return '2026-01-02';}
final class Stockland_DB {
	public static function table( $name ) {
		return 'wp_stock_' . $name;}
}
require_once dirname( __DIR__, 2 ) . '/includes/class-stockland-money.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-stockland-sequence.php';
$tests = array(
	array( Stockland_Money::minor( '123.455' ), 12346, 'positive half rounding' ),
	array( Stockland_Money::minor( '-123.455' ), -12346, 'negative half rounding' ),
	array( Stockland_Money::add( '10.09', '0.02' ), '10.11', 'exact addition' ),
	array( Stockland_Money::subtract( '10.01', '0.02' ), '9.99', 'exact subtraction' ),
	array( Stockland_Money::multiply( '2.5', '16.60' ), '41.50', 'exact multiplication' ),
	array( Stockland_Money::divide( '41.50', '2.5' ), '16.60', 'exact division' ),
	array( Stockland_Money::percentage( '100', '0.15' ), '15.00', 'percentage' ),
	array( Stockland_Money::inclusive_tax( '115.00', '0.15' ), '15.00', 'inclusive VAT' ),
);
foreach ( $tests as [$actual, $expected, $name] ) {
	if ( $actual !== $expected ) {
		throw new RuntimeException( "{$name}: expected {$expected}, got {$actual}" );
	}
}
$failed = false;
try {
	Stockland_Money::minor( '1.2x' );
} catch ( InvalidArgumentException $e ) {
	$failed = true; }
if ( ! $failed ) {
	throw new RuntimeException( 'invalid decimal was not rejected' );
}
if ( ( new ReflectionClass( 'Stockland_Sequence' ) )->isInstantiable() ) {
	throw new RuntimeException( 'sequence class must remain non-instantiable' );
}
echo "Local PHP smoke PASS: exact money arithmetic and sequence class invariants.\n";
