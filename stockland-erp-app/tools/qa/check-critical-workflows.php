<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
$root      = dirname( __DIR__, 2 );
$import    = file_get_contents( $root . '/includes/rest/class-rest-import-v16.php' );
$wholesale = file_get_contents( $root . '/includes/rest/class-rest-wholesale.php' );
$inventory = file_get_contents( $root . '/includes/rest/class-rest-inventory-report-v16.php' );
$js        = file_get_contents( $root . '/assets/app.js' );
$acct      = file_get_contents( $root . '/includes/rest/class-rest-accounting.php' );
$app       = file_get_contents( $root . '/includes/rest/class-rest-app.php' );
$main      = file_get_contents( $root . '/stockland-inventory.php' );
$all_php   = '';
$it        = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( $f->isFile() && strtolower( $f->getExtension() ) === 'php' && strpos( $f->getPathname(), DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'qa' . DIRECTORY_SEPARATOR ) === false ) {
		$all_php .= file_get_contents( $f->getPathname() ) . "\n";
	}
}
$receiveBranch = '';
if ( preg_match( "/else if\\(f\\.id==='receiveForm'\\)\\{(.*?)\\}\\n?else if\\(f\\.id==='transferForm'/s", $js, $m ) ) {
	$receiveBranch = $m[1];
}
$checks = array(
	'daily POS explicit no-auto-post staging'              => strpos( $import, 'STAGED_ONLY_NO_AUTO_POST' ) !== false,
	'daily POS staged import has explicit inventory ledger' => strpos( $import, 'Stockland_Ledger::post_sale_closed' ) !== false,
	'daily POS staged import has GL/PLEDGER'               => strpos( $import, 'Stockland_Accounting::post_journal' ) !== false,
	'daily POS uses explicit commit transaction'           => strpos( $import, 'START TRANSACTION' ) !== false && strpos( $import, 'COMMIT' ) !== false,
	'daily POS has cross-job receipt identity guard'       => strpos( $import, 'cross_job_batch' ) !== false && strpos( $import, 'branch_location_id=%d AND business_date=%s AND source_system=%s AND external_receipt_no=%s' ) !== false,
	'product import persists destination variant_id'       => 1 === preg_match( '/\'variant_id\'\s*=>\s*\$destination_variant_id/', $import ),
	'product import response exposes destination tracking' => strpos( $import, 'destination_tracking' ) !== false,
	'Product Master never creates physical stock'          => strpos( $import, 'Product Master commit rejected physical stock field' ) !== false,
	'inventory report is read-only'                        => strpos( $inventory, 'INSERT INTO' ) === false && strpos( $inventory, 'UPDATE ' ) === false && strpos( $inventory, 'DELETE FROM' ) === false,
	'inventory report REST permission callback is public'  => preg_match( '/public function permission\\s*\\(/', $inventory ) === 1,
	'import REST permission callbacks are public'          => preg_match( '/public function capability_daily\\s*\\(/', $import ) === 1 && preg_match( '/public function capability_product\\s*\\(/', $import ) === 1 && preg_match( '/public function capability_daily_commit\\s*\\(/', $import ) === 1 && preg_match( '/public function capability_mappings\\s*\\(/', $import ) === 1,
	'wholesale order posts Inventory Ledger'               => strpos( $wholesale, 'Stockland_Ledger::record' ) !== false,
	'wholesale order posts PLEDGER/GL'                     => strpos( $wholesale, 'Stockland_Accounting::post_journal' ) !== false,
	'wholesale PLEDGER includes revenue and VAT'           => strpos( $wholesale, "'4100'" ) !== false && strpos( $wholesale, "'2100'" ) !== false,
	'wholesale PLEDGER includes COGS and inventory'        => strpos( $wholesale, "'5000'" ) !== false && strpos( $wholesale, "'1200'" ) !== false,
	'multipart FormData does not receive JSON Content-Type' => strpos( $js, 'opt.body instanceof FormData' ) !== false && strpos( $js, 'if(multipart){Object.keys(headers).forEach' ) !== false,
	'warehouse receipt form stages before physical confirmation' => $receiveBranch !== '' && strpos( $receiveBranch, "'/receipts/'+encodeURIComponent(r.receipt_id)+'/complete'" ) === false && strpos( $receiveBranch, 'receipt tracking ID' ) !== false,
	'warehouse has visible pending receipt queue'          => strpos( $js, 'id="receiptQueue"' ) !== false && strpos( $js, 'async function loadReceipts()' ) !== false,
	'warehouse receiving capability is distinct from view permission' => 1 === preg_match( '/\'receive_warehouse\'\s*=>/', $app ) && strpos( $js, 'capabilities.receive_warehouse' ) !== false,
	'warehouse navigation is available to receive-only users' => strpos( $js, "['warehouse','Central Warehouse',!!(c.warehouse||c.receive_warehouse)]" ) !== false,
	'purchase-order receipt queue is accessible from Central Warehouse' => strpos( $js, 'const purchaseOrderQueue=canViewPO?' ) !== false && strpos( $js, 'id="poActionQueue"' ) !== false,
	'purchase order queue function exists and wires actual API' => strpos( $js, 'async function loadPurchaseOrders()' ) !== false && strpos( $js, '/accounting/purchase-orders' ) !== false,
	'purchase order receive UI posts GRN to backend'       => strpos( $js, 'async function openPOReceiveDialog' ) !== false && strpos( $js, "+'/receive'" ) !== false && strpos( $acct, 'purchase_order_receive' ) !== false,
	'purchase order receiving validates real calendar dates' => 1 === preg_match( '/Stockland_Business_Calendar::valid_date\(\s*\$date\s*\)/', $acct ) && 1 === preg_match( '/Stockland_Business_Calendar::assert_business_day\(\s*\$date\s*\)/', $acct ),
	'purchase order receiving checks day closure before and inside transaction' => preg_match_all( '/Stockland_DB::is_day_closed\(\s*\$location\s*,\s*\$date\s*\)/', $acct ) >= 2,
	'Daily POS release remains explicit user action'       => strpos( $js, 'RELEASE TO ERP' ) !== false && strpos( $js, '/import/daily-sales/commit' ) !== false,
	'Product Master commit remains explicit user action'   => strpos( $js, 'COMMIT PRODUCT MASTER' ) !== false && strpos( $js, '/import/products/commit' ) !== false,
	'no server-side cron dependency'                       => ! preg_match( '/\\b(?:wp_schedule_event|wp_schedule_single_event|wp_schedule_single|wp_next_scheduled)\\s*\\(/i', $main . $import ),
);
$errors = array();
foreach ( $checks as $name => $ok ) {
	if ( ! $ok ) {
		$errors[] = $name;
	}
}
if ( $errors ) {
	fwrite( STDERR, "CRITICAL WORKFLOW QA FAILED\n" . implode( "\n", array_map( fn( $x )=>'- ' . $x, $errors ) ) . "\n" );
	exit( 1 );
}echo 'CRITICAL WORKFLOW QA PASS: ' . count( $checks ) . ' gates.\n';
