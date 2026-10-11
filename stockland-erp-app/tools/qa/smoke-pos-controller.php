<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
/**
 * Offline behavioral smoke for the canonical POS controller.
 * It executes the real Stockland_REST_Sales::create_sale() method with a small
 * in-memory WordPress/DB/service boundary. No production database is touched.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'STOCKLAND_REST_NAMESPACE' ) ) {
	define( 'STOCKLAND_REST_NAMESPACE', 'stockland/v16' );
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
function current_user_can( $cap ) {
	return in_array( $cap, array( 'stockland_create_sale', 'stockland_view_own_branch', 'stockland_close_day' ), true );
}
function get_current_user_id() {
	return 42; }
function current_time( $type ) {
	return $type === 'Y-m-d' ? '2026-10-08' : '2026-10-08 04:00:00'; }
function sanitize_text_field( $v ) {
	return trim( strip_tags( (string) $v ) ); }
function sanitize_key( $v ) {
	return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function is_wp_error( $v ) {
	return $v instanceof WP_Error; }
function rest_ensure_response( $v ) {
	return $v; }
function wp_json_encode( $v ) {
	return json_encode( $v ); }

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code, $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data; }
	public function get_error_code() {
		return $this->code; }
	public function get_error_message() {
		return $this->message; }
	public function get_error_data() {
		return $this->data; }
}
class WP_REST_Request {
	private $params;
	public function __construct( $params = array() ) {
		$this->params = $params; }
	public function get_json_params() {
		return $this->params; }
	public function get_param( $key ) {
		return $this->params[ $key ] ?? null; }
	public function __get( $key ) {
		return $this->params[ $key ] ?? null; }
}
class Stockland_Roles {
	public static function get_profile( $uid ) {
		return array(
			'wp_user_id'   => 42,
			'location_ids' => array( 7 ),
		); }
	public static function get_location_scope( $uid ) {
		return array( 7 ); }
}
class Stockland_Authority {
	public static function assert_profile() {
		return true; }
}
class Stockland_Sequence {
	public static function next( $scope, $date = null ) {
		return 'POS-20261008-000001'; }
}
class Stockland_Ledger {
	public static $calls = 0;
	public static function post_sale_closed( $sale, $uid ) {
		++self::$calls;
		return true; }
}
class Stockland_Accounting {
	public static $calls = 0;
	public static $last  = null;
	public static function post_journal( $date, $type, $ref, $memo, $lines, $uid ) {
		++self::$calls;
		self::$last = $lines;
		return true; }
}
class Stockland_Audit {
	public static $calls = 0;
	public static function log() {
		++self::$calls;
		return true; }
}

class FakeWPDB {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $insert_id  = 0;
	public $sales      = array();
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$value = is_numeric( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query = preg_replace( '/%[sd]/', $value, $query, 1 );
		}
		return $query;
	}
	public function query( $sql ) {
		return true; }
	public function get_row( $sql, $output = ARRAY_A ) {
		if ( stripos( $sql, 'FROM wp_stock_sales' ) !== false ) {
			if ( preg_match( "/WHERE\s+sale_id\s*=\s*'([^']+)'/i", $sql, $m ) ) {
				return $this->sales[ $m[1] ] ?? null;
			}
		}
		if ( stripos( $sql, 'FROM wp_stock_products' ) !== false ) {
			if ( preg_match( "/WHERE sku='([^']+)'/", $sql, $m ) ) {
				$sku = $m[1];
				if ( $sku === 'TCP-JG-007' ) {
					return array(
						'sku'           => $sku,
						'product_name'  => 'Jogger',
						'variant_id'    => 101,
						'product_type'  => 'VARIANT',
						'retail_price'  => '115.00',
						'purchase_cost' => '60.00',
						'size'          => '3Y',
						'color'         => 'NAVY',
						'barcode'       => '123456789',
						'gtin'          => '',
					);
				}
				if ( $sku === 'STYLE-001' ) {
					return array(
						'sku'           => $sku,
						'product_name'  => 'Style',
						'variant_id'    => 102,
						'product_type'  => 'STYLE',
						'retail_price'  => '0.00',
						'purchase_cost' => '0.00',
						'size'          => '',
						'color'         => '',
						'barcode'       => '',
						'gtin'          => '',
					);
				}
				if ( $sku === 'LOW-STOCK' ) {
					return array(
						'sku'           => $sku,
						'product_name'  => 'Low Stock',
						'variant_id'    => 103,
						'product_type'  => 'VARIANT',
						'retail_price'  => '100.00',
						'purchase_cost' => '40.00',
						'size'          => 'M',
						'color'         => 'BLACK',
						'barcode'       => '987654321',
						'gtin'          => '',
					);
				}
			}
		}
		return null;
	}
	public function get_var( $sql ) {
		if ( stripos( $sql, 'FROM wp_stock_sales' ) !== false && preg_match( "/WHERE\s+sale_id\s*=\s*'([^']+)'/i", $sql, $m ) ) {
			return isset( $this->sales[ $m[1] ] ) ? $m[1] : null;
		}
		return null;
	}
	public function insert( $table, $data ) {
		if ( $table === 'wp_stock_sales' ) {
			if ( isset( $this->sales[ $data['sale_id'] ] ) ) {
				$this->last_error = 'Duplicate sale_id';
				return false; }
			$this->sales[ $data['sale_id'] ] = $data;
			$this->insert_id                 = count( $this->sales );
			return 1;
		}
		return 1;
	}
	public function update( $table, $data, $where ) {
		if ( $table === 'wp_stock_sales' && isset( $where['sale_id'], $this->sales[ $where['sale_id'] ] ) ) {
			$this->sales[ $where['sale_id'] ] = array_merge( $this->sales[ $where['sale_id'] ], $data );
			return 1; }
		return 1;
	}
}
$wpdb            = new FakeWPDB();
$GLOBALS['wpdb'] = $wpdb;

class Stockland_DB {
	public static $calendar  = '';
	public static $available = 10;
	public static function table( $key ) {
		$map = array(
			'sales'    => 'wp_stock_sales',
			'products' => 'wp_stock_products',
		);
		return $map[ $key ] ?? 'wp_stock_' . $key; }
	public static function acquire_day_lock( $branch, $date ) {
		return array(
			'ok'  => true,
			'key' => 'k',
		); }
	public static function release_day_lock( $lock ) {}
	public static function is_day_closed( $branch, $date ) {
		return false; }
	public static function get_vat_rate() {
		return 0.15; }
	public static function get_available_stock( $branch, $sku ) {
		return self::$available; }
	public static function get_setting( $key, $default = '' ) {
		return self::$calendar === '' ? $default : self::$calendar; }
	public static function get_product( $sku ) {
		global $wpdb;
		return $wpdb->get_row( "SELECT * FROM wp_stock_products WHERE sku='" . str_replace( "'", "''", $sku ) . "' LIMIT 1", ARRAY_A ); }
}
require_once ABSPATH . 'includes/class-stockland-money.php';
require_once ABSPATH . 'includes/class-stockland-sale-math.php';
require_once ABSPATH . 'includes/class-stockland-business-calendar.php';
require_once ABSPATH . 'includes/rest/class-rest-controller.php';
require_once ABSPATH . 'includes/rest/class-rest-sales.php';

$fail  = array();
$pass  = 0;
$ok    = function ( $cond, $msg ) use ( &$fail, &$pass ) {
	if ( $cond ) {
		++$pass;
	} else {
		$fail[] = $msg;
	}
};
$sales = new Stockland_REST_Sales();

$r = $sales->create_sale(
	new WP_REST_Request(
		array(
			'branch_location_id' => 7,
			'sale_date'          => '2026-10-08',
			'sku'                => 'TCP-JG-007',
			'quantity_sold'      => 2,
			'vat_mode'           => 'VAT_INCLUDED',
		)
	)
);
if ( is_wp_error( $r ) ) {
	fwrite( STDERR, 'POS first-call error: ' . $r->get_error_code() . ' / ' . $r->get_error_message() . "\n" );
}
$ok( is_array( $r ) && ( $r['posting_status'] ?? '' ) === 'CLOSED', 'POS success did not return CLOSED sale' );
$ok( is_array( $r ) && ( $r['sales_total'] ?? '' ) === '230.00' && ( $r['vat_amount'] ?? '' ) === '30.00' && ( $r['net_sales_excl_vat'] ?? '' ) === '200.00', 'POS VAT/total math incorrect' );
$ok( Stockland_Ledger::$calls === 1 && Stockland_Accounting::$calls === 1 && Stockland_Audit::$calls === 1, 'POS success did not execute exactly one ledger/GL/audit post' );
$first_sale_id = is_array( $r ) ? ( $r['sale_id'] ?? '' ) : '';

$r2 = $sales->create_sale(
	new WP_REST_Request(
		array(
			'branch_location_id' => 7,
			'sale_date'          => '2026-10-08',
			'sku'                => 'TCP-JG-007',
			'quantity_sold'      => 2,
			'vat_mode'           => 'VAT_INCLUDED',
			'sale_id'            => $first_sale_id,
		)
	)
);
$ok( is_array( $r2 ) && ( is_array( $r2 ) ? ( $r2['sale_id'] ?? '' ) : '' ) === $first_sale_id, 'POS idempotent retry did not return original sale' );
$ok( Stockland_Ledger::$calls === 1 && Stockland_Accounting::$calls === 1 && Stockland_Audit::$calls === 1, 'POS idempotent retry double-posted inventory/GL/audit' );

$conflict = $sales->create_sale(
	new WP_REST_Request(
		array(
			'branch_location_id' => 7,
			'sale_date'          => '2026-10-08',
			'sku'                => 'TCP-JG-007',
			'quantity_sold'      => 3,
			'vat_mode'           => 'VAT_INCLUDED',
			'sale_id'            => $first_sale_id,
		)
	)
);
$ok( is_wp_error( $conflict ) && $conflict->get_error_code() === 'pos_sale_failed', 'POS sale_id semantic conflict was not blocked' );

Stockland_DB::$available = 1;
$stock                   = $sales->create_sale(
	new WP_REST_Request(
		array(
			'branch_location_id' => 7,
			'sale_date'          => '2026-10-08',
			'sku'                => 'TCP-JG-007',
			'quantity_sold'      => 2,
			'vat_mode'           => 'VAT_INCLUDED',
		)
	)
);
$ok( is_wp_error( $stock ), 'Insufficient stock was not blocked' );
$ok( count( $wpdb->sales ) === 1, 'Failed insufficient-stock POS attempt changed persisted sales' );
Stockland_DB::$available = 10;

$scope = $sales->create_sale(
	new WP_REST_Request(
		array(
			'branch_location_id' => 8,
			'sale_date'          => '2026-10-08',
			'sku'                => 'TCP-JG-007',
			'quantity_sold'      => 1,
			'vat_mode'           => 'VAT_INCLUDED',
		)
	)
);
$ok( is_wp_error( $scope ) && $scope->get_error_code() === 'branch_forbidden', 'Cross-branch POS request was not blocked' );

$style = $sales->create_sale(
	new WP_REST_Request(
		array(
			'branch_location_id' => 7,
			'sale_date'          => '2026-10-08',
			'sku'                => 'STYLE-001',
			'quantity_sold'      => 1,
			'vat_mode'           => 'VAT_INCLUDED',
		)
	)
);
$ok( is_wp_error( $style ) && $style->get_error_code() === 'pos_sale_failed', 'STYLE template was allowed into POS' );

Stockland_DB::$calendar = json_encode(
	array(
		'version'        => 1,
		'weekend_days'   => array( 4 ),
		'holidays'       => array(),
		'open_overrides' => array(),
	)
);
$closed                 = $sales->create_sale(
	new WP_REST_Request(
		array(
			'branch_location_id' => 7,
			'sale_date'          => '2026-10-08',
			'sku'                => 'TCP-JG-007',
			'quantity_sold'      => 1,
			'vat_mode'           => 'VAT_INCLUDED',
		)
	)
);
$ok( is_wp_error( $closed ) && $closed->get_error_code() === 'pos_sale_failed', 'Business Calendar closure was not enforced' );

if ( $fail ) {
	fwrite( STDERR, 'FAIL POS behavioral smoke (' . count( $fail ) . " failures)\n" . implode( "\n", array_map( fn( $x )=>" - {$x}", $fail ) ) . "\n" );
	exit( 1 );}
echo "PASS POS behavioral smoke: {$pass} assertions; real create_sale() executed offline with no production DB access.\n";
