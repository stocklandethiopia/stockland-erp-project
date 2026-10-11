<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
$root     = dirname( __DIR__, 2 );
$source   = '';
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	if ( $file->isFile() && $file->getExtension() === 'php' ) {
		$source .= "\n" . file_get_contents( $file->getPathname() );
	}
}
$expected = array(
	'locations',
	'settings',
	'products',
	'price_history',
	'product_sku_history',
	'users',
	'transactions',
	'receipts',
	'transfers',
	'requisitions',
	'requisition_items',
	'reservations',
	'sales',
	'wholesale_sales',
	'returns',
	'adjustments',
	'count_batches',
	'count_lines',
	'workflow_events',
	'daily_closings',
	'import_jobs',
	'import_rows',
	'notifications',
	'sale_batches',
	'balances',
	'import_profiles',
	'product_code_maps',
	'audit_logs',
	'user_locations',
	'suppliers',
	'customers',
	'purchase_orders',
	'purchase_order_items',
	'purchase_receipts',
	'purchase_receipt_items',
	'supplier_invoices',
	'supplier_invoice_items',
	'customer_invoices',
	'invoice_items',
	'expenses',
	'payments',
	'payment_allocations',
	'gl_accounts',
	'gl_journals',
	'gl_lines',
	'employees',
	'employee_compensation',
	'payroll_periods',
	'payroll_lines',
);
$found    = array();
foreach ( $expected as $name ) {
	if ( preg_match( '/stock_' . preg_quote( $name, '/' ) . '\\b/', $source ) ) {
		$found[ $name ] = true;
	}
}
$missing = array_values( array_diff( $expected, array_keys( $found ) ) );
if ( count( $found ) !== 49 || $missing ) {
	fwrite( STDERR, '49-table contract FAILED. Found ' . count( $found ) . '/49. Missing: ' . implode( ', ', $missing ) . "\n" );
	exit( 1 );
}
echo "49-table contract PASS: 49 unique application table keys referenced across includes.\n";
