<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
/** Targeted offline QA for POS, Product Master Import and Business Calendar remediation. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../' );
}
function sanitize_text_field( $v ) {
	return trim( strip_tags( (string) $v ) ); }
function wp_json_encode( $v ) {
	return json_encode( $v ); }
class Stockland_DB {
	public static $setting = '';
	public static function get_setting( $key, $default = '' ) {
		return self::$setting === '' ? $default : self::$setting; }
}
require_once ABSPATH . 'includes/class-stockland-money.php';
require_once ABSPATH . 'includes/class-stockland-sale-math.php';
require_once ABSPATH . 'includes/class-stockland-business-calendar.php';

$fail = array();
$pass = 0;
$ok   = function ( $cond, $msg ) use ( &$fail, &$pass ) {
	if ( $cond ) {
		++$pass;
	} else {
		$fail[] = $msg;
	} };

$inc = Stockland_Sale_Math::calculate( 2, '115.00', 'VAT_INCLUDED', 0.15 );
$ok( $inc['sales_total'] === '230.00' && $inc['vat_amount'] === '30.00' && $inc['net_sales'] === '200.00', 'VAT_INCLUDED math mismatch' );
$exc = Stockland_Sale_Math::calculate( 2, '100.00', 'WITHOUT_VAT', 0.15 );
$ok( $exc['sales_total'] === '230.00' && $exc['vat_amount'] === '30.00' && $exc['net_sales'] === '200.00', 'WITHOUT_VAT math mismatch' );
try {
	Stockland_Sale_Math::calculate( 0, '100.00', 'VAT_INCLUDED', 0.15 );
	$ok( false, 'Zero quantity was accepted' );
} catch ( Throwable $e ) {
	$ok( true, '' );}
try {
	Stockland_Sale_Math::calculate( 1, '100.00', 'VAT_INCLUDED', 1.01 );
	$ok( false, 'VAT rate >100% was accepted' );
} catch ( Throwable $e ) {
	$ok( true, '' );}

Stockland_DB::$setting = json_encode(
	array(
		'version'        => 1,
		'weekend_days'   => array( 0, 6 ),
		'holidays'       => array( '2026-10-12' => 'Holiday' ),
		'open_overrides' => array( '2026-10-11' => 'Special opening' ),
	)
);
$ok( Stockland_Business_Calendar::status( '2026-10-10' )['status'] === 'CLOSED', 'Saturday weekend rule failed' );
$ok( Stockland_Business_Calendar::status( '2026-10-11' )['status'] === 'OPEN', 'Open override failed' );
$ok( Stockland_Business_Calendar::status( '2026-10-12' )['status'] === 'CLOSED', 'Holiday rule failed' );
$ok( Stockland_Business_Calendar::valid_date( '2026-02-29' ) === false, 'Invalid leap date accepted' );
Stockland_DB::$setting = '{not-json';
try {
	Stockland_Business_Calendar::config();
	$ok( false, 'Malformed Business Calendar configuration did not fail closed' );
} catch ( Throwable $e ) {
	$ok( true, '' ); }
Stockland_DB::$setting = '';
$ok( Stockland_Business_Calendar::status( '2026-10-08' )['status'] === 'OPEN', 'Blank Business Calendar default should remain open until configured' );

$base          = dirname( __DIR__, 2 );
$sales         = file_get_contents( $base . '/includes/rest/class-rest-sales.php' );
$import        = file_get_contents( $base . '/includes/rest/class-rest-import-v16.php' );
$db            = file_get_contents( $base . '/includes/class-stockland-db.php' );
$app           = file_get_contents( $base . '/assets/app.js' );
$calendar_rest = file_get_contents( $base . '/includes/rest/class-rest-calendar.php' );
$plugin        = file_get_contents( $base . '/stockland-inventory.php' );
$runtime       = file_get_contents( $base . '/includes/class-stockland-runtime-diagnostics.php' );
$roles         = file_get_contents( $base . '/includes/class-stockland-roles.php' );
$manifest      = file_get_contents( $base . '/RELEASE_MANIFEST.json' );

$ok( strpos( $sales, "'methods'             => WP_REST_Server::CREATABLE" ) !== false && strpos( $sales, "'callback'            => array( \$this, 'create_sale' )" ) !== false, 'POS POST /sales route is not wired to create_sale' );
$ok( strpos( $sales, "'status' => 410" ) === false && strpos( $sales, 'manual_pos_disabled' ) === false, 'Disabled POS path remains in sales controller' );
$ok( strpos( $sales, 'Stockland_Sale_Math::calculate' ) !== false && strpos( $sales, 'Stockland_Business_Calendar::assert_business_day' ) !== false, 'POS is not using centralized math/calendar' );
$ok( 1 === preg_match( '/\'account_code\'\s*=>\s*\'1020\'/', $sales ) && 0 === preg_match( '/\'account_code\'\s*=>\s*\'1000\'/', $sales ), 'Legacy POS close path still posts to old 1000 cash account' );
$ok( 1 === preg_match( '/\'posting_status\'\s*=>\s*\'CLOSED\'/', $sales ) && strpos( $sales, 'Stockland_Ledger::post_sale_closed' ) !== false, 'POS does not atomically close/post inventory' );

$ok( strpos( $import, '^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$' ) !== false, 'Product Master generic SKU contract is missing' );
$ok( 1 === preg_match( '/\$variant_rows\s*=\s*array\s*\(\s*\)/', $import ) && 1 === preg_match( '/if\s*\(\s*(?:\$mapped_type\s*===\s*\'VARIANT\'|\'VARIANT\'\s*===\s*\$mapped_type)\s*\)\s*\{?\s*\$variant_rows\[\]\s*=\s*\$candidate\s*;/', $import ), 'Product Master same-workbook variant ordering guard is missing' );
$ok( 1 === preg_match( '/array\s*\(\s*\'initial_stock\'\s*,\s*\'opening_stock\'\s*,\s*\'stock_quantity\'\s*,\s*\'quantity\'\s*,\s*\'qty\'\s*,\s*\'stock\'\s*\)/', $import ), 'Product Master physical-stock rejection missing' );
$ok( strpos( $import, 'private function map_vat_mode' ) !== false && strpos( $import, 'Stockland_Sale_Math::calculate' ) !== false, 'Daily POS does not use centralized sale math' );
$ok( strpos( $import, 'Stockland_Business_Calendar::assert_business_day' ) !== false, 'Daily POS calendar gate missing' );
$ok( strpos( $import, "existing_row['posting_status']" ) !== false, 'Daily POS idempotency status check missing' );

$ok( strpos( $db, 'Stockland_Business_Calendar::is_business_day' ) !== false, 'Database day-close authority does not use Business Calendar' );
$ok( strpos( $calendar_rest, '/business-calendar' ) !== false && strpos( $calendar_rest, '/business-calendar/date' ) !== false && strpos( $calendar_rest, '/business-calendar/weekends' ) !== false, 'Business Calendar REST routes missing' );
$ok( strpos( $app, "api16('/sales'" ) !== false, 'Frontend POS does not call canonical /sales endpoint' );
$ok( strpos( $app, "api16('/business-calendar?month=" ) !== false, 'Frontend Calendar does not load calendar endpoint' );
$ok( strpos( $roles, "'stockland_create_sale'" ) !== false, 'POS capability missing from role policy' );
$ok( strpos( $plugin, 'class-stockland-business-calendar.php' ) !== false && strpos( $plugin, 'class-rest-calendar.php' ) !== false, 'Calendar classes are not bootstrapped' );
$ok( strpos( $manifest, '"release": "16.1.77"' ) !== false, 'Release manifest is not at the current corrective release' );
$ok( strpos( $manifest, '"internal_pos_rest": true' ) !== false && strpos( $manifest, '"business_calendar_authority": true' ) !== false, 'Release manifest remediation contracts missing' );

if ( $fail ) {
	fwrite( STDERR, 'FAIL targeted remediation QA (' . count( $fail ) . " failures)\n" );
	foreach ( $fail as $f ) {
		fwrite( STDERR, " - {$f}\n" );
	}
	exit( 1 );
}
echo "PASS targeted remediation QA: {$pass} assertions\n";
