<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
$root  = dirname( __DIR__, 2 );
$files = array();
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( $file->isFile() && $file->getExtension() === 'php' ) {
		$files[] = $file->getPathname();
	}
}
$defs = array();
foreach ( $files as $file ) {
	$source = file_get_contents( $file );
	if ( preg_match_all( '/CREATE\s+TABLE\s+\{\$p\}stock_([a-z0-9_]+)\s*\((.*?)\)\s+ENGINE\s*=\s*InnoDB/is', $source, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $match ) {
			$key          = strtolower( $match[1] );
			$body         = $match[2];
			$defs[ $key ] = array(
				'file' => $file,
				'body' => $body,
			);
		}
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
$missing  = array_values( array_diff( $expected, array_keys( $defs ) ) );
$extra    = array_values( array_diff( array_keys( $defs ), $expected ) );
$bad      = array();
foreach ( $expected as $key ) {
	if ( ! isset( $defs[ $key ] ) ) {
		continue;
	}
	$body = $defs[ $key ]['body'];
	if ( ! preg_match( '/\bPRIMARY\s+KEY\b/i', $body ) ) {
		$bad[] = "$key: missing PRIMARY KEY";
	}
}
if ( count( $defs ) !== 49 || $missing || $extra || $bad ) {
	fwrite( STDERR, 'DDL QA FAILED: defs=' . count( $defs ) . ', missing=' . implode( ',', $missing ) . ', extra=' . implode( ',', $extra ) . ', bad=' . implode( '; ', $bad ) . "\n" );
	exit( 1 );
}
echo "DDL QA PASS: 49 unique InnoDB application tables with primary keys.\n";
