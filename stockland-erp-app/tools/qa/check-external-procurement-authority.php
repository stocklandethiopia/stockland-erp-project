<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
$root      = dirname( __DIR__, 2 );
$fail      = array();
$pass      = 0;
$ok        = function ( $c, $m ) use ( &$fail, &$pass ) {
	if ( $c ) {
		++$pass;
		echo "PASS {$m}\n";
	} else {
		$fail[] = $m;
		echo "FAIL {$m}\n";
	}};
$r         = function ( $p ) use ( $root ) {
	return is_file( $root . '/' . $p ) ? file_get_contents( $root . '/' . $p ) : '';
};
$a         = $r( 'includes/rest/class-rest-accounting.php' );
$auth      = $r( 'includes/class-stockland-authority.php' );
$roles     = $r( 'includes/class-stockland-roles.php' );
$app       = $r( 'assets/app.js' );
$ddl       = $r( 'includes/class-stockland-accounting.php' );
$integrity = $r( 'includes/class-stockland-integrity.php' );
$manifest  = json_decode( $r( 'RELEASE_MANIFEST.json' ), true );
// Compare PHP tokens without formatting whitespace so PHPCBF cannot create false failures.
$a_compact    = preg_replace( '/\\s+/', '', $a );
$auth_compact  = preg_replace( '/\\s+/', '', $auth );
$roles_compact = preg_replace( '/\\s+/', '', $roles );
$ok( ( $manifest['release'] ?? '' ) === '16.1.57', 'release manifest marks 16.1.57' );
$ok( str_contains( $auth_compact, 'self::role($uid)===self::MANAGEMENT' ) && str_contains( $auth_compact, 'self::role($uid)===self::OWNER' ) && str_contains( $auth_compact, 'self::role($uid)===self::WAREHOUSE_MANAGER' ), 'strict profile-level Management / Owner / Warehouse Manager gates exist' );
$mapStart      = strpos( $roles_compact, 'self::ROLE_MANAGEMENT=>' );
$mapEnd        = strpos( $roles_compact, 'self::ROLE_BRANCH_MANAGER=>', $mapStart === false ? 0 : $mapStart );
$managementMap = $mapStart !== false && $mapEnd !== false ? substr( $roles_compact, $mapStart, $mapEnd - $mapStart ) : '';
$ok( $managementMap !== '' && ! str_contains( $managementMap, 'stockland_approve_purchase_orders' ), 'Management role does not retain PO approval capability' );
$ok( str_contains( $a, 'Only the Management member who created this Central Warehouse external-supply PO' ) && str_contains( $a, 'Only a different active Owner may approve' ) && str_contains( $a, 'OWNER_APPROVED' ), 'Management creates/submits and independent Owner approves' );
$ok( str_contains( $a, 'External-supply purchase orders must be received into Central Warehouse' ) && str_contains( $a, 'Only an active Stockland Warehouse Manager may accept' ), 'Goro/branch PO destination and non-Manager receipt are blocked' );
$ok( str_contains( $a_compact, "Stockland_DB::table('purchase_orders').'WHEREid=%dFORUPDATE'" ) && str_contains( $a_compact, 'ORDERBYidFORUPDATE' ), 'PO header and PO lines use row locks' );
$ok( str_contains( $a, 'grn_request_' ) && str_contains( $a, 'idempotency_conflict' ) && str_contains( $a, 'open_quantity_remaining' ), 'receipt retries idempotent and partial open balance surfaced' );
$ok( str_contains( $a, 'A stable request_key is required to prevent duplicate purchase orders' ) && str_contains( $a, 'A stable request_key is required for goods receiving' ), 'PO create and receipt APIs reject requests without an idempotency key' );
$ok( str_contains( $a, 'received quantities remain uninvoiced' ) && str_contains( $a, 'remaining_uninvoiced' ) && str_contains( $a_compact, "\$reopened_status=\$remaining_unreceived>0.00001?'PARTIALLY_RECEIVED':'RECEIVED'" ) && str_contains( $a, 'unreceived PO quantities; closure is blocked' ) && str_contains( $integrity, 'closed_purchase_orders_with_uninvoiced_received_qty' ) && str_contains( $integrity, 'received_status_pos_with_unreceived_qty' ), 'PO closure waits for invoice matching; integrity service remains separately read-only' );
$ok( str_contains( $a, 'Stockland_Ledger::record' ) && str_contains( $a, 'Stockland_Accounting::post_journal' ) && str_contains( $a, 'Stockland_Audit::log' ) && str_contains( $a, 'purchase_order_receipt_posted' ), 'stock, double-entry and audit remain in the receive transaction' );
$ok( str_contains( $integrity, 'foreign' ) || str_contains( $integrity, 'FOREIGN' ), 'read-only FK metadata audit remains available' );
$ok( str_contains( $integrity, 'purchase_order_items_without_header' ) && str_contains( $integrity, 'purchase_receipt_items_bad_relations' ) && str_contains( $integrity, 'purchase_order_received_qty_mismatch' ) && str_contains( $integrity, 'active_purchase_orders_not_central_warehouse' ) && str_contains( $integrity, 'purchase_orders_bad_authority_evidence' ), 'read-only procurement FK and PO received-quantity consistency scans are present' );
$ok( str_contains( $integrity, 'posted_journals_unbalanced_or_too_few_lines' ) && str_contains( $integrity, 'posted_receipts_without_journal' ) && str_contains( $integrity, 'posted_supplier_invoices_without_journal' ) && str_contains( $integrity, 'purchase_receipt_items_without_inventory_move' ) && str_contains( $integrity, 'posted_receipt_journal_amount_mismatch' ), 'read-only PLEDGER balance and source-journal integrity scans are present' );
$integrity_normalized = preg_replace( '/\\s+/', ' ', $integrity );
$ok( str_contains( $integrity_normalized, "'read_only' => true" ) && str_contains( $integrity_normalized, "'unknown_checks' => \$unknown" ) && str_contains( $integrity_normalized, "'SCAN_INCOMPLETE'" ), 'integrity SQL errors fail visibly instead of being counted as zero and scanner stays read-only' );
$ok( ! preg_match( '/FOREIGN KEY\\s*\\(/i', $ddl ), '49-table DDL did not gain an unreviewed FK migration' );
$ok( str_contains( $auth, 'can_view_external_purchase_orders' ) && str_contains( $a, 'can_view_external_purchase_orders' ), 'PO reads require an active, explicit Stockland role' );
$receipts = $r( 'includes/rest/class-rest-receipts.php' );
$notifs   = $r( 'includes/class-stockland-notifications.php' );
$ok( substr_count( $receipts, "'purchase_order_required'" ) >= 2 && str_contains( $receipts, 'Direct supplier receipts are disabled by Stockland SOP' ), 'free-form receipt creation and completion cannot bypass Owner-approved PO workflow' );
$ok( str_contains( $app, 'HOLD — legacy receipt' ) && ! str_contains( $app, 'data-receive=' ) && ! str_contains( $app, 'CREATE RECEIPT' ), 'legacy manual receipt UI is read-only and cannot post inventory' );
$ok( str_contains( $notifs, 'EXISTS (SELECT 1 FROM {$assigned_table} sul' ) && str_contains( $notifs, 'effective_from<=CURDATE()' ), 'location-bound notifications respect all active assigned warehouse locations' );
$ok( str_contains( $a_compact, 'is_wp_error($warehouse_guard)' ), 'warehouse assertion handles WP_Error fail-closed' );
$ok( str_contains( $a, "'/accounting/suppliers'" ) && str_contains( $a_compact, "Stockland_Authority::can_manage_external_supplier_finance('stockland_view_financials')" ), 'supplier master reads are restricted to explicit Owner/Management role' );
$ok( str_contains( $a, "'/accounting/bootstrap'" ) && str_contains( $a, 'Supplier/AP accounting bootstrap is restricted to active Stockland Owner and Management profiles' ), 'accounting bootstrap cannot expose supplier/AP data using Administrator/settings capability alone' );
$ok( str_contains( $app, "['owner','management','warehouse_manager'].includes(state.boot?.user?.role)" ), 'PO queue UI only appears for permitted procurement roles' );
$ok( str_contains( $auth, 'can_manage_external_supplier_finance' ) && str_contains( $a, 'can_manage_external_supplier_finance' ), 'Supplier/AP routes require Owner or Management ERP role' );
$ok( str_contains( $a, 'po_required' ) && str_contains( $a, 'separately Owner-approved Central Warehouse PO' ), 'supplier invoices cannot bypass the approved PO / receipt path' );
$ok( str_contains( $a, 'idempotency_key_required' ) && str_contains( $a_compact, "acquire_named_lock('payment_'" ) && str_contains( $a, 'SLPAYREQ:' ) && str_contains( $a, 'payment_integrity_error' ), 'supplier payment retries are serialized, idempotent and journal-verified' );
$ok( str_contains( $a, 'allocation_total_mismatch' ) && str_contains( $a, 'allocation_required' ), 'supplier payments require exact invoice allocation' );
$ok( str_contains( $app, 'OWNER_APPROVED' ) && str_contains( $app, 'SUBMIT TO OWNER' ) && str_contains( $app, 'ACCEPT RECEIVED QTY' ), 'UI labels reflect the required SOP' );
if ( $fail ) {
	fwrite( STDERR, 'External procurement gate FAILED: ' . implode( '; ', $fail ) . "\n" );
	exit( 1 );
}echo "External procurement static gate PASS: {$pass} assertions.\n";
