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
$ok( ( $manifest['release'] ?? '' ) === '16.1.66', 'release manifest marks 16.1.66' );
$plugin = $r( 'stockland-inventory.php' );
$ok( str_contains( $plugin, "STOCKLAND_ROLE_CAPS_VERSION', '16.1.66-finance-procurement-authority-v1'" ) && str_contains( $plugin, "get_option( 'stockland_roles_cap_version', '' ) !== STOCKLAND_ROLE_CAPS_VERSION" ), 'in-place release re-syncs role capabilities even when the plugin version is unchanged' );
$ok( str_contains( $roles, "SELECT wp_user_id, role, is_active FROM %i" ) && str_contains( $roles, "'owner' === (string) ( \$profile['role'] ?? '' )" ) && str_contains( $roles, "\$wp_user->add_cap( 'stockland_approve_purchase_orders' )" ) && str_contains( $roles, "\$wp_user->remove_cap( 'stockland_approve_purchase_orders' )" ), 'only active ERP Owner profiles receive the direct PO approval capability when needed' );
$ok( str_contains( $app, 'slProfileBtn' ) && str_contains( $app, 'slProfileMenu' ) && str_contains( $app, 'Finance & Procurement' ) && str_contains( $app, 'data-profile-view="finance"' ), 'ERP user icon opens a role-aware dropdown with finance/procurement navigation' );
$ok( str_contains( $auth_compact, 'self::role($uid)===self::MANAGEMENT' ) && str_contains( $auth_compact, 'self::role($uid)===self::OWNER' ) && str_contains( $auth_compact, 'self::role($uid)===self::WAREHOUSE_MANAGER' ), 'strict profile-level Management / Owner / Warehouse Manager gates exist' );
$mapStart      = strpos( $roles_compact, 'self::ROLE_MANAGEMENT=>' );
$mapEnd        = strpos( $roles_compact, 'self::ROLE_BRANCH_MANAGER=>', $mapStart === false ? 0 : $mapStart );
$managementMap = $mapStart !== false && $mapEnd !== false ? substr( $roles_compact, $mapStart, $mapEnd - $mapStart ) : '';
$ok( $managementMap !== '' && ! str_contains( $managementMap, 'stockland_approve_purchase_orders' ), 'Management role does not retain PO approval capability' );
$ok( str_contains( $a, 'Only the Management member who created this Central Warehouse external-supply PO' ) && str_contains( $a, 'Only a different active Owner may approve' ) && str_contains( $a, 'OWNER_APPROVED' ), 'Management creates/submits and independent Owner approves' );
$ok( str_contains( $a, '$profile_role( $creator )' ) && str_contains( $a, '$profile_role( $approved_by )' ) && ! str_contains( $a, 'cp.role AS creator_role' ), 'PO queue derives creator and approver roles from the same active ERP profile authority used by the status transition' );
$ok( str_contains( $a, 'External-supply purchase orders must be received into Central Warehouse' ) && str_contains( $a, 'Only an active Stockland Warehouse Manager may accept' ), 'Goro/branch PO destination and non-Manager receipt are blocked' );
$ok( str_contains( $a_compact, "'SELECT*FROM%iWHEREid=%dFORUPDATE',Stockland_DB::table('purchase_orders')" ) && str_contains( $a_compact, "'SELECT*FROM%iWHEREpo_id=%dORDERBYidFORUPDATE',Stockland_DB::table('purchase_order_items')" ), 'PO header and PO lines use row locks against the physical prefixed tables' );
$ok( str_contains( $a_compact, "Stockland_DB::table('supplier_invoice_items'),Stockland_DB::table('supplier_invoices'),\$poid" ), 'supplier invoice quantity query binds both physical tables before its PO filter value' );
$ok( str_contains( $a_compact, "Stockland_DB::table('payment_allocations'),Stockland_DB::table('payments'),\$invoice_type,\$iid,(int)\$payment_id" ), 'payment allocation query binds both physical tables before value placeholders' );
$ok( ! str_contains( $a_compact, "FORUPDATE','purchase_orders'" ) && ! str_contains( $a_compact, "WHEREnotesLIKE%sORDERBYidDESCLIMIT1','purchase_receipts'" ) && ! str_contains( $a_compact, "WHEREreferenceLIKE%sORDERBYidDESCLIMIT1','expenses'" ), 'Finance/Procurement %i queries no longer pass unprefixed logical table names' );
$grant_start = strpos( $ddl, 'private static function grant_capabilities()' );
$grant_end = strpos( $ddl, 'Creates the required walk-in customer', $grant_start );
$grant_block = false !== $grant_start && false !== $grant_end ? substr( $ddl, $grant_start, $grant_end - $grant_start ) : '';
$caps_variable_start = strpos( $grant_block, '$company_finance_caps' );
$caps_start = false !== $caps_variable_start ? strpos( $grant_block, 'array(', $caps_variable_start ) : false;
$caps_end = false !== $caps_start ? strpos( $grant_block, ');', $caps_start ) : false;
$caps_block = false !== $caps_start && false !== $caps_end ? substr( $grant_block, $caps_start, $caps_end - $caps_start ) : '';
$ok( str_contains( $grant_block, "\$branch_manager->add_cap( 'stockland_manage_expenses' );" ), 'Branch Manager retains expense submission capability in accounting capability sync' );
$ok( str_contains( $grant_block, "\$owner_role->add_cap( 'stockland_approve_purchase_orders' );" ) && str_contains( $grant_block, "\$management_role->remove_cap( 'stockland_approve_purchase_orders' );" ), 'Owner receives PO approval and Management loses PO approval in accounting capability sync' );
$ok( '' !== $caps_block && ! str_contains( $caps_block, 'stockland_approve_purchase_orders' ), 'PO approval is not included in the shared company-finance capability list' );
$ok( str_contains( $roles_compact, "if('stockland_approve_purchase_orders'===\$cap){continue;}" ) && str_contains( $plugin, "16.1.66-finance-procurement-authority-v1" ) && str_contains( $plugin, '$admin_po_cap_bad' ), 'in-place role repair removes shared Administrator PO approval and regrants it only to active Owner profiles' );
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
$ok( str_contains( $notifs, 'EXISTS (SELECT 1 FROM %i sul' ) && str_contains( $notifs, 'effective_from<=CURDATE()' ) && str_contains( $notifs, '$assigned_table' ), 'location-bound notifications use a prepared assigned-location table identifier and effective-date filters' );
$ok( str_contains( $a_compact, 'is_wp_error($warehouse_guard)' ), 'warehouse assertion handles WP_Error fail-closed' );
$ok( str_contains( $a, "'/accounting/suppliers'" ) && str_contains( $a_compact, "Stockland_Authority::can_manage_external_supplier_finance('stockland_view_financials')" ), 'supplier master reads are restricted to explicit Owner/Management role' );
$ok( str_contains( $a, "'/accounting/bootstrap'" ) && str_contains( $a, 'Supplier/AP accounting bootstrap is restricted to active Stockland Owner and Management profiles' ), 'accounting bootstrap cannot expose supplier/AP data using Administrator/settings capability alone' );
$ok( str_contains( $app, "['owner','management','warehouse_manager'].includes(state.boot?.user?.role)" ), 'PO queue UI only appears for permitted procurement roles' );
$ok( str_contains( $auth, 'can_manage_external_supplier_finance' ) && str_contains( $a, 'can_manage_external_supplier_finance' ), 'Supplier/AP routes require Owner or Management ERP role' );
$ok( str_contains( $a, 'po_required' ) && str_contains( $a, 'separately Owner-approved Central Warehouse PO' ), 'supplier invoices cannot bypass the approved PO / receipt path' );
$ok( str_contains( $a, 'idempotency_key_required' ) && str_contains( $a_compact, "acquire_named_lock('payment_'" ) && str_contains( $a, 'SLPAYREQ:' ) && str_contains( $a, 'payment_integrity_error' ), 'supplier payment retries are serialized, idempotent and journal-verified' );
$ok( str_contains( $a, 'allocation_total_mismatch' ) && str_contains( $a, 'allocation_required' ), 'supplier payments require exact invoice allocation' );
$ok( str_contains( $app, 'OWNER_APPROVED' ) && str_contains( $app, 'SUBMIT TO OWNER' ) && str_contains( $app, 'ACCEPT RECEIVED QTY' ), 'UI labels reflect the required SOP' );

$ok( str_contains( $auth, "array( self::OWNER, self::MANAGEMENT, self::BRANCH_MANAGER, self::WAREHOUSE_MANAGER )" ) && str_contains( $auth, "array( self::OWNER, self::BRANCH_MANAGER, self::WAREHOUSE_MANAGER )" ) && str_contains( $a, 'Owner approval is limited to a Management corporate expense submitted by a different active ERP user.' ), 'expense submission and record-specific final authority include Owner corporate decisions and assigned location decisions' );
$ok( str_contains( $a, 'Central Warehouse expenses use the Warehouse Manager workflow' ) && str_contains( $a, 'Management / Corporate' ) && str_contains( $a_compact, "'location_id'=>\$location_id>0?\$location_id:null" ), 'corporate expenses have no branch attribution and location expenses use server-assigned scope' );
$ok( str_contains( $a, 'A Branch Manager may decide expenses only for their assigned branch' ) && str_contains( $a, 'A Warehouse Manager may decide expenses only for the assigned Central Warehouse' ) && str_contains( $a, 'LOCATION_EXPENSE_APPROVE' ) && str_contains( $a, 'LOCATION_EXPENSE_REJECT' ), 'expense decisions enforce the assigned location and record role-appropriate audit events' );
$ok( str_contains( $grant_block, "\$warehouse_manager->add_cap( 'stockland_manage_expenses' );" ) && str_contains( $grant_block, 'ROLE_WAREHOUSE_MANAGER ), true )' ), 'Warehouse Manager receives only the expense capability needed for the Central Warehouse workflow' );
$ok( str_contains( $app, 'Location Expense History' ) && str_contains( $app, 'Expense Report — Read Only' ) && str_contains( $app, 'bindSkuPickers();' ), 'role-scoped expense UI, read-only reports and dynamic Finance/PO SKU picker are wired' );
$ok( str_contains( $app, "if(['branch_manager','warehouse_manager'].includes(role))" ) && str_contains( $app, "api('/accounting/expenses')" ), 'Branch/Warehouse finance UI loads only scoped expense data, not the supplier/AP/AR bootstrap' );
$ok( str_contains( $a_compact, "if(\$has_status&&'PENDING'===\$status&&in_array(\$current_role,array('branch_manager','warehouse_manager'),true))" ) && str_contains( $a, 'Keep legacy pending records visible in reports' ), 'legacy pending rows remain reportable but are not shown as actionable to the wrong authority role' );
$ok( str_contains( $a, "if ( 'branch_manager' === \$current_role )" ) && str_contains( $a, "Stockland_Authority::branch_id()" ) && str_contains( $a, "Stockland_DB::get_location_id( 'Central Warehouse' )" ), 'expense list scope is forced to one assigned branch or Central Warehouse, not arbitrary user-location memberships' );

$ok( str_contains( $a, "'direct_post    = 'owner' === \$role" ) && str_contains( $a, 'CORPORATE_EXPENSE_SUBMIT' ) && str_contains( $a, 'CORPORATE_EXPENSE_OWNER_APPROVE' ), 'Owner corporate expenses post independently while Management corporate expenses require an Owner decision' );
$ok( str_contains( $a, 'former Management-submission policy without rewriting their history' ) && str_contains( $app, "if(role==='owner')rows=rows.filter(x=>Number(x.location_id||0)===0" ), 'legacy location expenses remain actionable only in their assigned location queue while Owner queue excludes branch records' );
if ( $fail ) {
	fwrite( STDERR, 'External procurement gate FAILED: ' . implode( '; ', $fail ) . "\n" );
	exit( 1 );
}echo "External procurement static gate PASS: {$pass} assertions.\n";
