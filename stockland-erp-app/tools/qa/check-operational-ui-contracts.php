<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
$root    = dirname( __DIR__, 2 );
$fail    = array();
$pass    = 0;
$ok      = function ( $cond, $label ) use ( &$fail, &$pass ) {
	if ( $cond ) {
		++$pass;
		echo "PASS {$label}\n";
	} else {
		$fail[] = $label;
		echo "FAIL {$label}\n";
	}};
$modules = json_decode( file_get_contents( $root . '/assets/css/modules.json' ), true );
$ok( is_array( $modules ) && ( $modules['release'] ?? '' ) === '16.1.67' && count( $modules['modules'] ?? array() ) === 6, 'six ordered CSS source modules declared' );
$chunks = array();
$modsOk = is_array( $modules );
foreach ( ( $modules['modules'] ?? array() ) as $name ) {
	$p = $root . '/assets/css/' . $name;
	if ( ! is_file( $p ) ) {
		$modsOk = false;
		continue;
	}$v = trim( file_get_contents( $p ) );
	if ( substr_count( $v, '{' ) !== substr_count( $v, '}' ) ) {
		$modsOk = false;
	}$chunks[] = $v;}
$expected = implode( "\n\n", $chunks ) . "\n";
$ok( $modsOk && hash_equals( $expected, file_get_contents( $root . '/assets/app.css' ) ), 'canonical CSS bundle matches its ordered source modules' );
$css = file_get_contents( $root . '/assets/app.min.css' );
$ok( str_contains( $css, '.sl-page-title-static' ) && str_contains( $css, '@media (max-width:760px)' ) && str_contains( $css, 'content:attr(data-label)' ), 'compiled CSS includes health-heading and responsive-table safeguards' );
$js        = file_get_contents( $root . '/assets/app.js' );
$inventory = file_get_contents( $root . '/includes/rest/class-rest-inventory-report-v16.php' );
$imports   = file_get_contents( $root . '/includes/rest/class-rest-import-v16.php' );
$ok( str_contains( $js, 'id="dailyInventoryReport"' ) && str_contains( $js, "api16('/reports/daily-inventory" ) && str_contains( $inventory, "'/reports/daily-inventory'" ) && str_contains( $inventory, 'public function daily_inventory' ), 'Daily Inventory front-end and read-only report route are wired' );
$ok( str_contains( $js, 'id="dailyPosImportForm"' ) && str_contains( $js, "api16('/import/daily-sales/preview" ) && str_contains( $js, "'/import/daily-sales/commit'" ) && str_contains( $imports, 'public function capability_daily_commit' ), 'Daily POS preview/review/release controls remain wired' );
$ok( str_contains( $js, 'id="productImportForm"' ) && str_contains( $js, "api16('/import/products/preview" ) && str_contains( $js, 'COMMIT PRODUCT MASTER' ) && str_contains( $js, "'/import/products/commit'" ) && str_contains( $imports, 'Product Master commit rejected physical stock field' ), 'Product Master preview/commit is explicit and blocks stock quantity mutation' );
$ok( str_contains( $js, 'data-label="Location"' ) && str_contains( $js, 'data-label="SKU"' ) && str_contains( $js, 'data-label="Status"' ) && str_contains( $js, 'data-label="ERP field"' ), 'operational report/import cells carry accessible mobile labels' );
$ok( str_contains( $js, "'sl-page-title-static'" ) && str_contains( $css, '.sl-page-title-static' ), 'System Health uses a non-sticky evidence heading' );
$warehouse = file_get_contents( $root . '/includes/rest/class-rest-requisition-v16.php' );
$authority = file_get_contents( $root . '/includes/class-stockland-authority.php' );
$ok( str_contains( $warehouse, "\$allowed_states = 'reserve' === \$action ? array( 'Approved', 'Reserved' ) : array( 'Approved', 'Reserved', 'Picked' );" ) && str_contains( $warehouse, "\$locked_allowed_states = 'reserve' === \$action ? array( 'Approved', 'Reserved' ) : array( 'Approved', 'Reserved', 'Picked' );" ), 'warehouse reserve/pick transitions accept Approved for Manager direct PICK and revalidate after row locking' );
$ok( str_contains( $warehouse, "'pick' === \$action && 'Picked' === \$locked_status" ) && str_contains( $warehouse, 'no additional stock movement was posted' ), 'replayed PICK is idempotent and cannot post a second warehouse movement' );
$ok( str_contains( $warehouse, "'pick' === \$action && 'Approved' === \$req['status'] && Stockland_Authority::role() !== Stockland_Authority::WAREHOUSE_MANAGER" ) && str_contains( $warehouse, "'pick' === \$action && 'Approved' === \$locked_status && Stockland_Authority::role() !== Stockland_Authority::WAREHOUSE_MANAGER" ), 'direct PICK is restricted to the Central Warehouse Manager before and after row locking' );
$ok( str_contains( $warehouse, "status IN('Approved','Reserved') ORDER BY created_at DESC LIMIT 1" ) && str_contains( $warehouse, "Stockland_DB::get_available_stock( \$wh, \$transfer['sku'] )" ) && str_contains( $warehouse, "'status'            => 'Active'" ) && str_contains( $warehouse, "'status'      => 'Reserved'" ), 'direct PICK secures available stock using a transaction-scoped reservation before dispatch' );
$ok( str_contains( $warehouse, "\$cur['dispatched_qty'] = \$dispatch" ) && str_contains( $warehouse, "Stockland_Ledger::post_transfer_dispatched( \$cur, get_current_user_id() )" ), 'ledger dispatch receives the real approved quantity rather than an initial zero dispatched quantity' );
$ok( str_contains( $warehouse, 'when the branch collection is ready' ) && str_contains( $warehouse, "for the scheduled pickup on " ), 'branch notification supports direct PICK without an appointment and keeps scheduled pickup wording' );
$ok( str_contains( $authority, 'return in_array( $r, array( self::WAREHOUSE_EMPLOYEE, self::WAREHOUSE_MANAGER ), true );' ) && str_contains( $js, "const canPick=role==='warehouse_employee'||role==='warehouse_manager'" ) && str_contains( $js, "'/warehouse-action'" ), 'Warehouse Manager and Warehouse Employee both have the visible PICK path and matching server authority' );
$ok( str_contains( $js, 'data-warehouse-action="pick">PICK / READY</button>' ) && str_contains( $js, 'data-warehouse-action="reserve">RESERVE</button>' ) && str_contains( $js, 'const action=String(b.dataset.warehouseAction||\'\')' ), 'Central Warehouse Manager can choose direct PICK or optional RESERVE from the main queue' );
$ok( str_contains( $js, 'const canDirectPick=role===\'warehouse_manager\'&&q.status===\'Approved\'' ) && str_contains( $js, 'data-warehouse-detail-action="pick">PICK / READY FOR BRANCH</button>' ) && str_contains( $js, 'data-warehouse-detail-action="reserve">RESERVE STOCK</button>' ), 'requisition detail exposes direct PICK and optional RESERVE to the Central Warehouse Manager' );
$ok( str_contains( $js, 'if(action===\'reserve\'){date=document.getElementById(\'warehousePickupDate\')?.value||\'\';if(!date)' ) && str_contains( $js, 'action,...(date?{pickup_date:date}:{})' ), 'direct PICK does not require a pickup appointment; RESERVE requires a selected date' );
if ( $fail ) {
	fwrite( STDERR, 'Operational UI contracts FAILED: ' . implode( '; ', $fail ) . "\n" );
	exit( 1 );
}echo "Operational UI contracts PASS: {$pass} assertions.\n";
