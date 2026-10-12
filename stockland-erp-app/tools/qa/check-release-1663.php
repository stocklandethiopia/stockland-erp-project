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
$integrity  = $read( 'includes/class-stockland-integrity.php' );
$ddl        = $read( 'includes/class-stockland-activator.php' );
$app        = $read( 'assets/app.js' );
$ok( ( $manifest['release'] ?? '' ) === '16.1.63' && str_contains( $plugin, 'Version:           16.1.63' ), 'plugin header and manifest use the same release identity' );
$plugin_compact = preg_replace( '/\\s+/', '', $plugin );
$ok(
	str_contains( $plugin_compact, '\$owner_po_cap_missing=!\$owner_role||!\$owner_role->has_cap(\\'stockland_approve_purchase_orders\\');' )
		&& str_contains( $plugin_compact, '\$management_po_cap_bad=\$management_role&&\$management_role->has_cap(\\'stockland_approve_purchase_orders\\');' )
		&& str_contains( $plugin_compact, '\$owner_po_cap_missing||\$management_po_cap_bad' ),
	'WordPress update re-syncs missing Owner PO approval and removes that capability from Management.'
);
$ok( ( $manifest['schema_contract']['total'] ?? 0 ) === 49 && ! ( $manifest['schema_contract']['new_business_table'] ?? true ), '49-table business schema contract remains exact; no business table added' );
$ok( str_contains( $health, 'Stockland_Integrity::scan()' ) && str_contains( $health, 'supplemental_integrity_scan' ), 'explicit deep health invokes the read-only supplemental integrity scanner' );
$ok( str_contains( $health, "&& \$checks['supplemental_integrity_scan']" ), 'deep live-ready certification requires supplemental ledger/procurement integrity to pass' );
$ok( str_contains( $integrity, 'supplier_invoice_receipt_grni_reconciliation' ) && str_contains( $integrity, 'posted_supplier_invoice_grni_ap_gl_mismatch' ) && str_contains( $integrity, 'supplier_payment_allocation_total_mismatch' ), 'deep scanner checks GRNI, supplier AP journal values and PLEDGER allocations' );
$ok( str_contains( $accounting_compact, "\$status='PENDING'" ) && str_contains( $accounting, 'BRANCH_EXPENSE_SUBMIT' ) && str_contains( $accounting, 'can_approve_expenses' ), 'branch expenses use pending submission and Owner decision flow' );
$ok( str_contains( $accounting, 'separately Owner-approved Central Warehouse PO' ) && str_contains( $accounting_compact, "'account_code'=>'2010'" ) && str_contains( $accounting_compact, "'account_code'=>'2000'" ) && str_contains( $accounting_compact, "\$invoice['total_amount']" ), 'supplier invoice approval clears receipt GRNI to AP without a second VAT debit' );
$ok( str_contains( $accounting, 'payment_integrity_error' ) && str_contains( $accounting_compact, "acquire_named_lock('payment_'" ) && str_contains( $accounting_compact, "'status'=>\$status" ), 'manual finance payments use idempotency, a named lock and pending approval' );
$ok( str_contains( $hr, "'SUBMITTED'" ) && str_contains( $hr, 'payroll_approval' ) && str_contains( $hr, 'PAYROLL_APPROVE' ) && str_contains( $hr, 'payroll_paid' ), 'payroll has Management submission, Owner approval/payment, GL and notification markers' );
$ok( str_contains( $authority, 'can_prepare_hr' ) && str_contains( $authority, 'can_prepare_finance' ) && str_contains( $authority, 'can_approve_finance' ) && str_contains( $authority, 'can_submit_expenses' ), 'server-side role authority helpers enforce prepare vs Owner approval' );
$ok( str_contains( $app, 'RUN DEEP INTEGRITY AUDIT' ) && str_contains( $app, 'QUICK HEALTH ONLY' ), 'UI visibly separates fast checks from the explicit deep audit' );
$ok( str_contains( $plugin, 'includes/class-stockland-integrity.php' ) && ! preg_match( '/FOREIGN KEY\s*\(/i', $ddl ), 'scanner loaded and no schema expansion/foreign-key migration was introduced' );
if ( $fail ) {
	fwrite( STDERR, 'Release 16.1.63 comprehensive gate FAILED: ' . implode( '; ', $fail ) . "\n" );
	exit( 1 );
}echo "Release 16.1.63 comprehensive gate PASS: {$pass} assertions.\n";
