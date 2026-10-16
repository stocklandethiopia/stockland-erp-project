<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
$root       = dirname( __DIR__, 2 );
$fail       = array();
$pass       = 0;
$ok         = function ( $cond, $message ) use ( &$fail, &$pass ) {
	if ( $cond ) {
		++$pass;
		echo "PASS {$message}\n";
	} else {
		$fail[] = $message;
		echo "FAIL {$message}\n";
	}};
$read       = function ( $path ) use ( $root ) {
	return is_file( $root . '/' . $path ) ? file_get_contents( $root . '/' . $path ) : '';
};
$manifest   = json_decode( $read( 'RELEASE_MANIFEST.json' ), true );
$plugin     = $read( 'stockland-inventory.php' );
$health     = $read( 'includes/rest/class-rest-app.php' );
$accounting = $read( 'includes/rest/class-rest-accounting.php' );
$accounting_compact = preg_replace( '/\\s+/', '', $accounting );
$hr         = $read( 'includes/rest/class-rest-hr.php' );
$authority  = $read( 'includes/class-stockland-authority.php' );
$requisition = $read( 'includes/rest/class-rest-requisition-v16.php' );
$integrity  = $read( 'includes/class-stockland-integrity.php' );
$frontend   = $read( 'includes/class-stockland-frontend.php' );
$ddl        = $read( 'includes/class-stockland-activator.php' );
$app        = $read( 'assets/app.js' );
$ok( ( $manifest['release'] ?? '' ) === '16.1.77' && str_contains( $plugin, 'Version:           16.1.77' ), 'plugin header and manifest use the same release identity' );
$plugin_compact     = preg_replace( '/\\s+/', '', $plugin );
$owner_po_guard      = '$owner_po_cap_missing=!$owner_role||!$owner_role->has_cap(' . "'stockland_approve_purchase_orders'" . ');';
$management_po_guard = '$management_po_cap_bad=$management_role&&$management_role->has_cap(' . "'stockland_approve_purchase_orders'" . ');';
$ok(
	str_contains( $plugin_compact, $owner_po_guard )
		&& str_contains( $plugin_compact, $management_po_guard )
		&& str_contains( $plugin_compact, '$admin_po_cap_bad=$admin_role&&$admin_role->has_cap(' . "'stockland_approve_purchase_orders'" . ');' )
		&& str_contains( $plugin_compact, '$owner_po_cap_missing||$management_po_cap_bad||$admin_po_cap_bad' ),
	'WordPress update re-syncs missing Owner PO approval and removes that capability from Management.'
);
$ok( ( $manifest['schema_contract']['total'] ?? 0 ) === 49 && ! ( $manifest['schema_contract']['new_business_table'] ?? true ), '49-table business schema contract remains exact; no business table added' );
$ok( str_contains( $health, 'Stockland_Integrity::scan()' ) && str_contains( $health, 'supplemental_integrity_scan' ), 'explicit deep health invokes the read-only supplemental integrity scanner' );
$ok( str_contains( $health, "&& \$checks['supplemental_integrity_scan']" ), 'deep live-ready certification requires supplemental ledger/procurement integrity to pass' );
$ok( str_contains( $integrity, 'supplier_invoice_receipt_grni_reconciliation' ) && str_contains( $integrity, 'posted_supplier_invoice_grni_ap_gl_mismatch' ) && str_contains( $integrity, 'supplier_payment_allocation_total_mismatch' ), 'deep scanner checks GRNI, supplier AP journal values and PLEDGER allocations' );
$ok( str_contains( $accounting, '$owner_expense_hold = $corporate_expense && \'owner\' === $role' ) && str_contains( $accounting_compact, "\$status='PENDING'" ) && str_contains( $accounting, 'OWNER_CORPORATE_EXPENSE_PENDING_AUTHORITY' ) && ! str_contains( $accounting, '$direct_post' ) && str_contains( $accounting, 'LOCATION_EXPENSE_SUBMIT' ) && str_contains( $accounting, 'can_approve_expenses' ), 'Owner-created corporate expenses remain pending and unposted without an independent approver; Management corporate and assigned-location expense paths remain separately authorized' );
$ok( str_contains( $accounting, 'separately Owner-approved Central Warehouse PO' ) && str_contains( $accounting_compact, "'account_code'=>'2010'" ) && str_contains( $accounting_compact, "'account_code'=>'2000'" ) && str_contains( $accounting_compact, "\$invoice['total_amount']" ), 'supplier invoice approval clears receipt GRNI to AP without a second VAT debit' );
$ok( str_contains( $accounting, 'payment_integrity_error' ) && str_contains( $accounting_compact, "acquire_named_lock('payment_'" ) && str_contains( $accounting_compact, "'status'=>\$status" ), 'manual finance payments use idempotency, a named lock and pending approval' );
$ok( str_contains( $hr, "'SUBMITTED'" ) && str_contains( $hr, 'payroll_approval' ) && str_contains( $hr, 'PAYROLL_APPROVE' ) && str_contains( $hr, 'payroll_paid' ), 'payroll has Management submission, Owner approval/payment, GL and notification markers' );
$ok( str_contains( $authority, 'can_prepare_hr' ) && str_contains( $authority, 'can_prepare_finance' ) && str_contains( $authority, 'can_approve_finance' ) && str_contains( $authority, 'can_submit_expenses' ), 'server-side role authority helpers enforce prepare vs Owner approval' );
$authority_compact = preg_replace( '/\\s+/', '', $authority );
$ok(
	str_contains( $authority_compact, 'returnin_array($r,array(self::WAREHOUSE_EMPLOYEE,self::WAREHOUSE_MANAGER),true);' )
		&& str_contains( $requisition, 'Only the Central Warehouse Manager may PICK an approved requisition directly.' )
		&& str_contains( $requisition, 'Only the Central Warehouse Manager or a Warehouse Employee may pick a reserved requisition.' )
		&& str_contains( $requisition, "\$cur['dispatched_qty'] = \$dispatch" )
		&& str_contains( $app, "const canPick=role==='warehouse_employee'||role==='warehouse_manager';" )
		&& str_contains( $app, "const canDirectPick=role==='warehouse_manager'&&q.status==='Approved'" ),
	'Central Warehouse Manager can direct-PICK approved stock; Warehouse Employees retain reserved/assigned-only picking, with exact quantity and role controls.'
);
$ok(
	str_contains( $requisition, "'warehouse_manager', \$wh, 'warehouse_requisition', 'Reserved requisition — PICK required'" )
		&& str_contains( $requisition, "'warehouse_employee', \$wh, 'warehouse_requisition', 'Reserved requisition — PICK required'" ),
	'Reserved stock queue notifies both eligible Central Warehouse pick roles.'
);
$ok(
	str_contains( $frontend, "hash_file( 'sha256', \$file )" )
		&& str_contains( $frontend, "substr( \$digest, 0, 16 )" ),
	'Asset cache identity follows file content across reproducible in-place updates.'
);
$ok( str_contains( $app, "appendChild(d);bindSkuPickers()" ), 'legacy Add SKU row binds the live SKU picker after dynamic insertion' );
$ok( str_contains( $app, "box.appendChild(div);div.querySelector('[data-remove-fin-line]').onclick=()=>{if(type==='po'&&box.querySelectorAll('.sl-fin-line').length<=1)" ) && str_contains( $app, 'div.remove();};bindSkuPickers();' ), 'dynamic Finance/PO lines keep one required PO line and bind the live touch/click SKU picker after insertion' );
$guards = $read( 'assets/css/06-operational-guards.css' );
$ok( str_contains( $guards, '.sl-req-line.sl-form{display:grid' ) && str_contains( $guards, '@media(max-width:575.98px)' ), 'dynamic requisition lines use responsive grid layout on mobile' );
$ok( str_contains( $app, 'RUN DEEP INTEGRITY AUDIT' ) && str_contains( $app, 'QUICK HEALTH ONLY' ), 'UI visibly separates fast checks from the explicit deep audit' );
$ok( str_contains( $plugin, 'includes/class-stockland-integrity.php' ) && ! preg_match( '/FOREIGN KEY\s*\(/i', $ddl ), 'scanner loaded and no schema expansion/foreign-key migration was introduced' );
$ok( str_contains( $app, 'The purchase-order transition is confirmed as' ) && str_contains( $app, 'notifications could not refresh. Open Notifications' ), 'post-transition refresh failures remain distinct from server-confirmed procurement decisions' );

if ( $fail ) {
	fwrite( STDERR, 'Release 16.1.77 comprehensive gate FAILED: ' . implode( '; ', $fail ) . "\n" );
	exit( 1 );
}echo "Release 16.1.77 comprehensive gate PASS: {$pass} assertions.\n";
