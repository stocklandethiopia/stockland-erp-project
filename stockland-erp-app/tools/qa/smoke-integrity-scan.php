<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
function current_time( $type ) {
	return '2026-10-10 00:00:00'; }
final class Stockland_DB {
	public static function table( $name ) {
		return 'wp_stock_' . $name; }
}
final class Stockland_Fake_Integrity_WPDB {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $history    = array();
	public $fail_on    = '';
	public function esc_like( $value ) {
		return addcslashes( $value, '_%\\' ); }
	public function prepare( $query, ...$args ) {
		return $query; }
	public function get_var( $query ) {
		$this->history[] = $query;
		if ( $this->fail_on !== '' && strpos( $query, $this->fail_on ) !== false ) {
			$this->last_error = 'simulated database query error';
			return null;
		}
		$this->last_error = '';
		return '0';
	}
	public function get_results( $query, $format = null ) {
		$this->history[]  = $query;
		$this->last_error = '';
		return array();
	}
}
require_once dirname( __DIR__, 2 ) . '/includes/class-stockland-integrity.php';
$failures   = array();
$expect     = static function ( $condition, $message ) use ( &$failures ) {
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $message . "\n";
	if ( ! $condition ) {
		$failures[] = $message; }
};
$wpdb       = new Stockland_Fake_Integrity_WPDB();
$result     = Stockland_Integrity::scan();
$all_select = count( $wpdb->history ) >= 40;
foreach ( $wpdb->history as $query ) {
	if ( ! preg_match( '/^\s*SELECT\b/i', $query ) ) {
		$all_select = false;
		break; }
}
$expect( count( $result['checks'] ) >= 41, 'comprehensive procurement/ledger scan executes 41 or more logical checks' );
$expect( $result['healthy'] === true && $result['total_orphan_rows'] === 0, 'zero-only successful mock scan reports clean data scan, separately from FK requirement' );
$expect( $result['database_foreign_keys']['status'] === 'NOT_ENFORCED', 'no observed database FKs is reported explicitly, not certified as relationally enforced' );
$expect( $result['read_only'] === true && $all_select, 'all emitted scan SQL is SELECT-only; no repair or DDL statement is executed' );
$wpdb          = new Stockland_Fake_Integrity_WPDB();
$wpdb->fail_on = 'po.supplier_id<>r.supplier_id';
$result        = Stockland_Integrity::scan();
$expect( in_array( 'purchase_receipts_bad_po_supplier_or_location', $result['unknown_checks'], true ), 'a simulated SQL failure is surfaced by its exact check name' );
$expect( $result['healthy'] === false && $result['overall_status'] === 'SCAN_INCOMPLETE', 'scan errors fail closed and are never silently treated as zero orphans' );
if ( $failures ) {
	fwrite( STDERR, 'Integrity smoke failed: ' . implode( '; ', $failures ) . "\n" );
	exit( 1 ); }
echo "Integrity scan mock smoke PASS: 6 assertions; no production database used.\n";
