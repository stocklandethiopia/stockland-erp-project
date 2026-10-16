<?php
/**
 * Source-contract regression checks for cumulative release 16.1.77.
 * These static checks do not replace authenticated database/ledger acceptance.
 */
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}
$root = dirname( __DIR__, 2 );
$read = static function ( $relative ) use ( $root ) {
	return file_get_contents( $root . '/' . $relative );
};
$plugin  = $read( 'stockland-inventory.php' );
$manifest = json_decode( $read( 'RELEASE_MANIFEST.json' ), true );
$frontend = $read( 'includes/class-stockland-frontend.php' );
$payroll  = $read( 'includes/class-stockland-payroll-statutory.php' );
$payroll_test = $read( 'tools/qa/check-payroll-statutory.php' );
$app      = $read( 'assets/app.js' );
$requisition_service = $read( 'includes/class-stockland-requisition-service.php' );
$requisition_rest = $read( 'includes/rest/class-rest-requisition-v16.php' );
$accounting = $read( 'includes/rest/class-rest-accounting.php' );
$css_nav = $read( 'assets/css/02-shell-navigation.css' );
$stock = $read( 'includes/rest/class-rest-stock.php' );
$ddl = $read( 'includes/class-stockland-activator.php' );
$fail = array();
$pass = 0;
$check = static function ( $condition, $label ) use ( &$fail, &$pass ) {
	if ( $condition ) {
		++$pass;
		echo "PASS {$label}\n";
	} else {
		$fail[] = $label;
		echo "FAIL {$label}\n";
	}
};
$expense_create_start = strpos( $accounting, 'public function expense_create' );
$expense_decision_start = strpos( $accounting, 'public function expense_status' );
$expense_create = false !== $expense_create_start && false !== $expense_decision_start
	? substr( $accounting, $expense_create_start, $expense_decision_start - $expense_create_start )
	: '';
$check( str_contains( $plugin, 'Version:           16.1.77' ) && ( $manifest['release'] ?? '' ) === '16.1.77' && str_contains( $plugin, "define( 'STOCKLAND_VERSION', '16.1.77' );" ), 'Plugin, manifest and runtime version are aligned at 16.1.77' );
$check( ( $manifest['schema_contract']['total'] ?? 0 ) === 49, 'Release manifest preserves the 49-table business schema contract' );
$check( str_contains( $ddl, "'requisitions'" ) && str_contains( $ddl, "'transactions'" ), 'Core requisition and stock-ledger tables remain in the core DDL contract' );
$check( str_contains( $stock, 'valid Product Master variant may have no posted movement yet' ) && str_contains( $stock, "'stock_read_failed'" ) && str_contains( $stock, 'current_stock' ) && str_contains( $stock, 'CRITICAL' ), 'SKU stock lookup returns verified zero balances and negative/zero stock is treated as critical' );
$check( str_contains( $payroll, 'Stockland_Money::subtract( $income_before_pension, $employee_pension )' ) && str_contains( $payroll, 'ET-PAYE-1395-2025-v3-PENSION-BASE' ), 'Server PAYE taxable base deducts employee pension under the agreed rule version' );
$check( str_contains( $app, 'taxableIncome=Math.max(0,incomeBeforePension-emp)' ) && str_contains( $app, 'PAYE base after pension' ), 'Frontend payroll preview uses the same pension-first PAYE base' );
$check( str_contains( $payroll_test, "'taxable_income'      => '27900.00'" ) && str_contains( $payroll_test, "'income_tax'          => '7715.00'" ) && str_contains( $payroll_test, "'net_pay'             => '20185.00'" ), 'ETB 30,000 agreed payroll regression expects tax 7,715 and net 20,185' );
$check( str_contains( $app, 'loadDashboardInsights' ) && str_contains( $app, 'dashboardTrendHtml' ) && str_contains( $app, 'dashboardCategoryHtml' ) && str_contains( $app, 'dashboardBranchesHtml' ) && str_contains( $app, 'dashboardTransactionsHtml' ), 'Dashboard analytics render sales trend, category mix, branch performance and recent transactions' );
$check( str_contains( $frontend, 'dashboard/insights' ), 'Dashboard analytics endpoint is registered through the existing REST app' );
$check( str_contains( $requisition_service, 'idempotency_key_required' ) && str_contains( $requisition_service, 'acquire_named_lock' ) && str_contains( $requisition_service, 'idempotency_conflict' ) && str_contains( $requisition_service, '[[SLREQ:' ) && str_contains( $requisition_service, 'display_notes' ), 'Requisition submission has stable-key locking, replay/conflict handling and hidden marker cleanup' );
$check( str_contains( $app, 'const request_key=f.dataset.requestKey' ) && str_contains( $app, 'refreshReqStockPreview' ) && str_contains( $app, '/requisitions/source-preview/availability' ), 'Requisition UI reuses a stable key on retry and shows live branch/warehouse sourcing' );
$check( str_contains( $requisition_rest, "'/requisitions/source-preview/availability'" ) && str_contains( $requisition_rest, 'public function source_preview' ) && str_contains( $requisition_rest, "'read_only'  => true" ), 'Live source preview is read-only and registered with role/branch checks' );
$check( str_contains( $requisition_rest, '$direct_pick = \'pick\' === $action && \'Approved\' === $req[\'status\']' ) && str_contains( $requisition_rest, 'Stockland_DB::get_available_stock' ) && str_contains( $requisition_rest, 'Stockland_Ledger::post_transfer_dispatched' ), 'Immediate warehouse pick checks available stock and posts through the existing transfer ledger' );
$check( str_contains( $requisition_rest, 'Stockland_Workflow::event(' ) && str_contains( $requisition_rest, "'immediate_pick'" ) && str_contains( $requisition_rest, 'Immediate-pick reservation audit event' ), 'Immediate pick records the reserved intermediate state and immutable audit evidence' );
$check( substr_count( $requisition_rest, "(float) ( $item['quantity_approved'] ?? 0 ) <= 0" ) >= 2, 'Zero-approved lines are skipped without blocking approved requisition lines' );
$check( str_contains( $expense_create, '$owner_expense_hold = $corporate_expense && \'owner\' === $role' ) && str_contains( $expense_create, '$status             = \'PENDING\'' ) && str_contains( $expense_create, 'OWNER_CORPORATE_EXPENSE_PENDING_AUTHORITY' ) && ! str_contains( $expense_create, '$direct_post' ), 'Owner-created corporate expense is held pending and cannot self-post a GL journal' );
$check( str_contains( $accounting, 'Owner approval is limited to a Management corporate expense submitted by a different active ERP user.' ), 'Expense decision endpoint enforces independent Owner approval' );
$check( str_contains( $accounting, 'Expense creators cannot approve or reject their own expense.' ), 'All expense creators are prevented from self-approval or self-rejection' );
$check( ! str_contains( $css_nav, '.sl-sidebar{display:none!important}' ) && str_contains( $css_nav, '.sl-sidebar' ), 'Responsive operational sidebar is preserved and not forcibly hidden' );
if ( $fail ) {
	fwrite( STDERR, 'FAIL cumulative operational source checks: ' . count( $fail ) . " failure(s)\n" );
	exit( 1 );
}
echo 'PASS cumulative operational source checks: ' . $pass . " assertions\n";
