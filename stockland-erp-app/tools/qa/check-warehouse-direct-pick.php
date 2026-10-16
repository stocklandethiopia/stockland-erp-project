<?php
/**
 * Isolated executable regression coverage for requisition pick/receive transitions.
 * This QA-only file is excluded from the production WordPress installer.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
function add_action( ...$args ) {}
function register_rest_route( ...$args ) {}
function rest_ensure_response( $v ) { return $v; }
function current_time( $type ) { return 'Y-m-d' === $type ? '2026-10-16' : '2026-10-16 14:00:00'; }
function get_current_user_id() { return MockERP::$userId; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
class WP_Error { public $code; public $message; public $data; public function __construct( $c, $m, $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; } }
class WP_REST_Request implements ArrayAccess {
	private $params;
	private $json;
	public function __construct( $method = 'POST', $route = '', $json = array(), $params = array() ) { $this->json = $json; $this->params = $params; }
	public function get_json_params() { return $this->json; }
	public function offsetExists( $o ): bool { return isset( $this->params[$o] ); }
	public function offsetGet( $o ): mixed { return $this->params[$o] ?? null; }
	public function offsetSet( $o, $v ): void { $this->params[$o] = $v; }
	public function offsetUnset( $o ): void { unset( $this->params[$o] ); }
}
class MockERP {
	public static $userId = 7;
	public static $role = 'warehouse_manager';
	public static $req = array();
	public static $item = array();
	public static $transfer = array();
	public static $reservations = array();
	public static $stock = 10.0;
	public static $branchStock = 0.0;
	public static $ledgerPosts = 0;
	public static $receiptPosts = 0;
	public static $workflowEvents = 0;
	public static $auditEvents = 0;
	public static $notifications = array();
	public static $lastError = '';
	public static function reset( $status = 'Approved', $transferStatus = 'Approved', $stock = 10.0, $qty = 4.0, $pickup = null ) {
		self::$userId = 7;
		self::$role = 'warehouse_manager';
		self::$stock = $stock;
		self::$branchStock = 0.0;
		self::$ledgerPosts = 0;
		self::$receiptPosts = 0;
		self::$workflowEvents = 0;
		self::$auditEvents = 0;
		self::$notifications = array();
		self::$reservations = array();
		self::$lastError = '';
		self::$req = array( 'requisition_id' => 'REQ-TEST-1', 'branch_location_id' => 2, 'requested_by' => 99, 'status' => $status, 'pickup_date' => $pickup, 'location_name' => 'Regression Branch' );
		self::$item = array( array( 'id' => 8, 'requisition_id' => 'REQ-TEST-1', 'sku' => 'SKU-001', 'variant_id' => 100, 'quantity_requested' => $qty, 'quantity_approved' => $qty, 'quantity_dispatched' => 0, 'quantity_received' => 0 ) );
		self::$transfer = array( 'transfer_id' => 'TR-TEST-1', 'requisition_item_id' => 8, 'requisition_id' => 'REQ-TEST-1', 'status' => $transferStatus, 'from_location_id' => 1, 'to_location_id' => 2, 'sku' => 'SKU-001', 'variant_id' => 100, 'quantity' => $qty, 'dispatched_qty' => 0, 'transfer_date' => '2026-10-16', 'created_at' => '2026-10-16 10:00:00', 'pickup_date' => $pickup );
	}
}
class WPDBMock {
	public $last_error = '';
	public function prepare( $q, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$q = preg_replace_callback(
				'/%[idsf]/',
				static function ( $m ) use ( $arg ) {
					if ( '%i' === $m[0] ) {
						return (string) $arg;
					}
					if ( '%d' === $m[0] || '%f' === $m[0] ) {
						return (string) ( 0 + $arg );
					}
					return "'" . str_replace( "'", "''", (string) $arg ) . "'";
				},
				$q,
				1
			);
		}
		return $q;
	}
}
$GLOBALS['wpdb'] = new WPDBMock();
class Stockland_DB {
	public static function table( $n ) { return 'tbl_' . $n; }
	public static function get_location_id( $n ) { return 'Central Warehouse' === $n ? 1 : 2; }
	public static function acquire_day_lock( $id, $d ) { return array( 'ok' => true, 'id' => $id, 'date' => $d ); }
	public static function release_day_lock( $l ) { return true; }
	public static function acquire_location_locks( $ids, $date ) { return array( 'ok' => true, 'locks' => $ids ); }
	public static function release_location_locks( $locks ) { return true; }
	public static function execute_query( $sql ) {
		if ( str_contains( $sql, "SET status='Released'" ) ) {
			preg_match( "/transfer_id='([^']+)'/", $sql, $m );
			foreach ( MockERP::$reservations as &$r ) {
				if ( isset( $m[1] ) && $r['transfer_id'] === $m[1] && 'Active' === $r['status'] ) {
					$r['status'] = 'Released';
				}
			}
			unset( $r );
		}
		return true;
	}
	public static function read_row( $sql, $mode = null ) {
		if ( str_contains( $sql, 'FROM tbl_requisitions' ) ) {
			return MockERP::$req;
		}
		if ( str_contains( $sql, 'FROM tbl_transfers' ) ) {
			$t = MockERP::$transfer;
			if ( str_contains( $sql, "status='Reserved'" ) && 'Reserved' !== $t['status'] ) {
				return null;
			}
			if ( str_contains( $sql, "status IN('Approved','Reserved')" ) && ! in_array( $t['status'], array( 'Approved', 'Reserved' ), true ) ) {
				return null;
			}
			return $t;
		}
		return null;
	}
	public static function read_results( $sql, $mode = null ) {
		if ( str_contains( $sql, 'tbl_requisition_items' ) ) {
			return MockERP::$item;
		}
		if ( str_contains( $sql, 'tbl_transfers' ) ) {
			return array( MockERP::$transfer );
		}
		return array();
	}
	public static function read_var( $sql ) {
		if ( str_contains( $sql, 'SELECT quantity_received' ) ) {
			return (float) ( MockERP::$item[0]['quantity_received'] ?? 0 );
		}
		preg_match( "/transfer_id='([^']+)'/", $sql, $m );
		$sum = 0.0;
		foreach ( MockERP::$reservations as $r ) {
			if ( isset( $m[1] ) && $r['transfer_id'] === $m[1] && 'Active' === $r['status'] ) {
				$sum += $r['quantity_reserved'];
			}
		}
		return $sum;
	}
	public static function insert_record( $table, $data ) {
		if ( 'tbl_reservations' === $table ) {
			MockERP::$reservations[] = $data;
		}
		return true;
	}
	public static function update_record( $table, $data, $where ) {
		if ( 'tbl_transfers' === $table ) {
			if ( ( $where['transfer_id'] ?? '' ) !== MockERP::$transfer['transfer_id'] ) {
				return false;
			}
			MockERP::$transfer = array_merge( MockERP::$transfer, $data );
			return true;
		}
		if ( 'tbl_requisitions' === $table ) {
			MockERP::$req = array_merge( MockERP::$req, $data );
			return true;
		}
		if ( 'tbl_requisition_items' === $table ) {
			foreach ( MockERP::$item as &$it ) {
				if ( $it['id'] === (int) ( $where['id'] ?? 0 ) ) {
					$it = array_merge( $it, $data );
				}
			}
			unset( $it );
			return true;
		}
		return true;
	}
	public static function get_available_stock( $location, $sku ) {
		$reserved = 0.0;
		foreach ( MockERP::$reservations as $r ) {
			if ( $r['location_id'] === $location && $r['sku'] === $sku && 'Active' === $r['status'] ) {
				$reserved += $r['quantity_reserved'];
			}
		}
		return MockERP::$stock - $reserved;
	}
	public static function get_product( $sku ) { return array( 'sku' => $sku, 'variant_id' => 100 ); }
}
class Stockland_Authority {
	const WAREHOUSE_MANAGER = 'warehouse_manager';
	public static function can_pick_requisition() { return in_array( MockERP::$role, array( 'warehouse_manager', 'warehouse_employee' ), true ); }
	public static function can_reserve_requisition() { return 'warehouse_manager' === MockERP::$role; }
	public static function role() { return MockERP::$role; }
	public static function company_scope() { return false; }
	public static function can_warehouse_operate_requisition() { return in_array( MockERP::$role, array( 'warehouse_manager', 'warehouse_employee' ), true ); }
	public static function can_access_location( $id ) { return 2 === (int) $id; }
	public static function can_receive_requisition() { return 'branch_manager' === MockERP::$role; }
}
class Stockland_Ledger {
	public static function post_transfer_dispatched( $transfer, $uid ) {
		if ( 'Reserved' !== $transfer['status'] ) {
			throw new Exception( 'Ledger received a transfer that was not protected by reservation state.' );
		}
		$qty = (float) ( $transfer['dispatched_qty'] ?? $transfer['quantity'] );
		if ( $qty <= 0 ) {
			return false;
		}
		MockERP::$ledgerPosts++;
		MockERP::$stock -= $qty;
		return MockERP::$ledgerPosts;
	}
	public static function post_transfer_received( $transfer, $uid ) {
		$qty = (float) ( $transfer['received_qty'] ?? 0 );
		if ( $qty <= 0 ) {
			return false;
		}
		MockERP::$receiptPosts++;
		MockERP::$branchStock += $qty;
		return MockERP::$receiptPosts;
	}
}
class Stockland_Workflow { public static function event( ...$a ) { MockERP::$workflowEvents++; return true; } }
class Stockland_Audit { public static function log( ...$a ) { MockERP::$auditEvents++; return true; } }
class Stockland_Notifications {
	public static function notify_branch( ...$a ) { MockERP::$notifications[] = $a; return true; }
	public static function push_role( ...$a ) { MockERP::$notifications[] = $a; return true; }
	public static function push_user( ...$a ) { MockERP::$notifications[] = $a; return true; }
}
$root = dirname( __DIR__, 2 );
require $root . '/includes/rest/class-rest-requisition-v16.php';
class TestedRequisitionController extends Stockland_REST_Requisition_V16 {
	public function detail( WP_REST_Request $r ) { return array( 'status' => MockERP::$req['status'], 'pickup_date' => MockERP::$req['pickup_date'] ); }
}
$controller = new TestedRequisitionController();
$pass = 0;
$fail = array();
$check = function ( $ok, $label ) use ( &$pass, &$fail ) {
	if ( $ok ) {
		++$pass;
		echo "PASS {$label}\n";
	} else {
		$fail[] = $label;
		echo "FAIL {$label}\n";
	}
};
$req = function ( $action, $date = null ) {
	return new WP_REST_Request( 'POST', '/stockland/v16/requisitions/REQ-TEST-1/warehouse-action', array_filter( array( 'action' => $action, 'pickup_date' => $date ), static fn( $v ) => null !== $v ), array( 'id' => 'REQ-TEST-1' ) );
};
$receive_req = function ( $receipts = array() ) {
	return new WP_REST_Request( 'POST', '/stockland/v16/requisitions/REQ-TEST-1/receive', array( 'receipts' => $receipts ), array( 'id' => 'REQ-TEST-1' ) );
};
MockERP::reset();
$out = $controller->warehouse_action( $req( 'pick' ) );
$check( ! ( $out instanceof WP_Error ) && 'Picked' === MockERP::$req['status'] && 1 === MockERP::$ledgerPosts && null === MockERP::$req['pickup_date'], 'Manager can PICK Approved work without reservation/date' );
$check( 1 === count( MockERP::$reservations ) && 'Released' === MockERP::$reservations[0]['status'] && 6.0 === MockERP::$stock, 'Direct PICK protects stock, posts the approved units and releases the temporary reservation' );
$check( 1 === count( MockERP::$notifications ) && str_contains( MockERP::$notifications[0][3], 'branch collection is ready' ), 'Date-free PICK notification gives the branch the correct next action' );
$out = $controller->warehouse_action( $req( 'pick' ) );
$check( is_array( $out ) && ( $out['idempotent'] ?? false ) === true && 1 === MockERP::$ledgerPosts, 'Repeated PICK is idempotent and cannot add another movement' );
MockERP::reset();
MockERP::$role = 'warehouse_employee';
$out = $controller->warehouse_action( $req( 'pick' ) );
$check( $out instanceof WP_Error && 403 === $out->data['status'] && 0 === MockERP::$ledgerPosts && 'Approved' === MockERP::$req['status'], 'Warehouse Employee cannot directly PICK Approved work' );
MockERP::reset( 'Reserved', 'Reserved', 10, 4, '2026-10-17' );
MockERP::$role = 'warehouse_employee';
MockERP::$reservations[] = array( 'location_id' => 1, 'sku' => 'SKU-001', 'quantity_reserved' => 4.0, 'transfer_id' => 'TR-TEST-1', 'status' => 'Active' );
$out = $controller->warehouse_action( $req( 'pick' ) );
$check( ! ( $out instanceof WP_Error ) && 'Picked' === MockERP::$req['status'] && 1 === MockERP::$ledgerPosts, 'Warehouse Employee can PICK properly Reserved work' );
MockERP::reset();
$out = $controller->warehouse_action( $req( 'reserve' ) );
$check( $out instanceof WP_Error && 'Approved' === MockERP::$req['status'] && 0 === MockERP::$ledgerPosts, 'RESERVE without a pickup date is rejected' );
MockERP::reset();
$out = $controller->warehouse_action( $req( 'reserve', '2026-10-17' ) );
$reserved = is_array( $out ) && 'Reserved' === MockERP::$req['status'] && 'Reserved' === MockERP::$transfer['status'];
$out2 = $controller->warehouse_action( $req( 'pick' ) );
$check( $reserved && ! ( $out2 instanceof WP_Error ) && 'Picked' === MockERP::$req['status'] && 1 === MockERP::$ledgerPosts && '2026-10-17' === MockERP::$req['pickup_date'], 'Reserve-then-PICK path remains functional' );
MockERP::reset( 'Approved', 'Approved', 2, 4 );
$out = $controller->warehouse_action( $req( 'pick' ) );
$check( $out instanceof WP_Error && 'Approved' === MockERP::$req['status'] && 0 === MockERP::$ledgerPosts && 0 === count( MockERP::$reservations ), 'Insufficient warehouse stock rejects direct PICK before posting' );
MockERP::reset( 'Reserved', 'Reserved', 10, 4, '2026-10-17' );
$out = $controller->warehouse_action( $req( 'pick' ) );
$check( $out instanceof WP_Error && 'Reserved' === MockERP::$req['status'] && 0 === MockERP::$ledgerPosts, 'Reserved transfer without an active reservation cannot post' );
MockERP::reset();
$out = $controller->warehouse_action( $req( 'pick' ) );
$check( 1 === MockERP::$workflowEvents && 1 === MockERP::$auditEvents && 1 === count( MockERP::$notifications ), 'Successful direct PICK records workflow, audit and branch notification' );
MockERP::reset();
$controller->warehouse_action( $req( 'pick' ) );
MockERP::$role = 'branch_manager';
$received = $controller->receive_info( $receive_req() );
$check( ! ( $received instanceof WP_Error ) && 'Received' === MockERP::$req['status'] && 1 === MockERP::$receiptPosts && 4.0 === MockERP::$branchStock && 4.0 === MockERP::$transfer['received_qty'], 'Branch receipt posts inbound ledger units and moves requisition to Received' );
MockERP::reset();
$controller->warehouse_action( $req( 'pick' ) );
MockERP::$role = 'branch_manager';
$partial = $controller->receive_info( $receive_req( array( array( 'transfer_id' => 'TR-TEST-1', 'received_qty' => 3, 'reason' => 'One unit missing at physical handover' ) ) ) );
$check( ! ( $partial instanceof WP_Error ) && 'Received' === MockERP::$req['status'] && 1 === MockERP::$receiptPosts && 3.0 === MockERP::$branchStock && -1.0 === MockERP::$transfer['variance_qty'], 'Documented short receipt posts actual units and records the variance' );
MockERP::reset();
$controller->warehouse_action( $req( 'pick' ) );
MockERP::$role = 'branch_manager';
$bad_variance = $controller->receive_info( $receive_req( array( array( 'transfer_id' => 'TR-TEST-1', 'received_qty' => 3 ) ) ) );
$check( $bad_variance instanceof WP_Error && 'Picked' === MockERP::$req['status'] && 0 === MockERP::$receiptPosts, 'Receipt variance without a reason is rejected before ledger posting' );
MockERP::reset();
$controller->warehouse_action( $req( 'pick' ) );
MockERP::$role = 'branch_manager';
$over = $controller->receive_info( $receive_req( array( array( 'transfer_id' => 'TR-TEST-1', 'received_qty' => 5, 'reason' => 'Invalid over-count' ) ) ) );
$check( $over instanceof WP_Error && 'Picked' === MockERP::$req['status'] && 0 === MockERP::$receiptPosts && 0.0 === MockERP::$branchStock, 'Over-receipt is rejected without adding branch stock' );
MockERP::reset();
$controller->warehouse_action( $req( 'pick' ) );
$forbidden = $controller->receive_info( $receive_req() );
$check( $forbidden instanceof WP_Error && 403 === $forbidden->data['status'] && 0 === MockERP::$receiptPosts, 'Warehouse roles cannot act as the receiving Branch Manager' );
MockERP::reset();
$controller->warehouse_action( $req( 'pick' ) );
MockERP::$role = 'branch_manager';
$controller->receive_info( $receive_req() );
$again = $controller->receive_info( $receive_req() );
$check( $again instanceof WP_Error && 1 === MockERP::$receiptPosts && 4.0 === MockERP::$branchStock, 'Repeated receipt cannot duplicate inbound stock movement' );
if ( $fail ) {
	fwrite( STDERR, 'Warehouse pick/receive regression gate FAILED: ' . implode( '; ', $fail ) . "\n" );
	exit( 1 );
}
echo "Warehouse pick/receive regression gate PASS: {$pass} assertions.\n";
