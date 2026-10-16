<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
$root   = dirname( __DIR__, 2 );
$fail   = array();
$pass   = 0;
$ok     = function ( $cond, $label ) use ( &$fail, &$pass ) {
	if ( $cond ) {
		++$pass;
		echo "PASS {$label}\n";
	} else {
		$fail[] = $label;
		echo "FAIL {$label}\n";
	}};
$read   = function ( $p ) use ( $root ) {
	$f = $root . '/' . $p;
	return is_file( $f ) ? file_get_contents( $f ) : '';
};
$full_runtime_ruleset = $read( 'phpcs-full-audit.xml.dist' );
$ok(
	str_contains( $full_runtime_ruleset, '<rule ref="WordPress.Files.FileName.InvalidClassFileName">' )
	&& str_contains( $full_runtime_ruleset, '<exclude-pattern>*/includes/rest/class-rest-*.php</exclude-pattern>' )
	&& ! str_contains( $full_runtime_ruleset, '<exclude-pattern>*/includes/rest/*</exclude-pattern>' ),
	'legacy REST filenames use a filename-sniff-only exclusion rather than excluding controller source'
);
$legacy_rest_files = array(
	'class-rest-accounting.php',
	'class-rest-app.php',
	'class-rest-calendar.php',
	'class-rest-controller.php',
	'class-rest-hr.php',
	'class-rest-import-v16.php',
	'class-rest-inventory-report-v16.php',
	'class-rest-locations.php',
	'class-rest-products.php',
	'class-rest-receipts.php',
	'class-rest-reports.php',
	'class-rest-requisition-v16.php',
	'class-rest-returns.php',
	'class-rest-sales.php',
	'class-rest-setup.php',
	'class-rest-stock.php',
	'class-rest-transfers.php',
	'class-rest-wholesale.php',
);
foreach ( $legacy_rest_files as $legacy_rest_file ) {
	$legacy_rest_source = $read( 'includes/rest/' . $legacy_rest_file );
	$ok(
		'' !== $legacy_rest_source
		&& false === strpos( $legacy_rest_source, 'phpcs:ignoreFile' )
		&& false !== strpos( $legacy_rest_source, 'phpcs:disable WordPress.Files.FileName.InvalidClassFileName' )
		&& false !== strpos( $legacy_rest_source, 'phpcs:enable WordPress.Files.FileName.InvalidClassFileName' ),
		'legacy REST controller remains in runtime WPCS scope: ' . $legacy_rest_file
	);
}
$css    = $read( 'assets/css/02-shell-navigation.css' );
$mobile = $read( 'assets/css/05-responsive-layout.css' );
$guard  = $read( 'assets/css/06-operational-guards.css' );
$js     = $read( 'assets/app.js' );
$ok( str_contains( $css, '.sl-btn:hover' ) && str_contains( $css, ':focus-visible' ) && str_contains( $css, ':active' ), 'premium button hover, keyboard focus and pressed states retained' );
$ok( preg_match( '/\.sl-nav-text\s*\{[^}]*white-space:\s*normal/s', $css ) === 1 && ! str_contains( $css, '-webkit-line-clamp:2' ) && str_contains( $css, 'font-size:14px' ), 'desktop menu labels use readable 14px type and wrap naturally without clipping' );
$ok( str_contains( $mobile, '@media (max-width:390px)' ) && str_contains( $mobile, '.sl-top-action-text' ) && str_contains( $mobile, 'overflow-x:auto' ) && str_contains( $mobile, 'min-height:44px' ) && str_contains( $mobile, 'font-size:11px!important' ), 'mobile header actions keep visible labels with controlled scrolling' );
$ok( str_contains( $guard, '.sl-overlay .sl-payroll-editor' ) && str_contains( $guard, '.sl-payroll-editor .sl-field select' ) && str_contains( $guard, 'min-height:44px' ), 'payroll modal is styled outside app root with responsive touch controls' );
$ok( str_contains( $js, 'function openCompensationEditor' ) && str_contains( $js, 'data-allowance-treatment' ) && str_contains( $js, 'slPayrollTaxPreview' ) && str_contains( $js, 'data-preview-cost' ), 'statutory payroll editor supports dropdown allowances and interactive totals' );
$ok( str_contains( $js, 'data-owner-verified' ) && str_contains( $js, 'data-allowance-basis' ) && str_contains( $js, 'data-allowance-evidence' ), 'tax exemption requires legal basis, evidence reference and Owner verification' );
$rules = $read( 'includes/class-stockland-payroll-statutory.php' );
$ok( str_contains( $rules, "const EMPLOYEE_PENSION_RATE = '0.07'" ) && str_contains( $rules, "const EMPLOYER_PENSION_RATE = '0.11'" ) && str_contains( $rules, "const LAW_EFFECTIVE_DATE = '2025-07-08'" ) && str_contains( $rules, "const EFFECTIVE_DATE = '2025-08-01'" ), 'statutory rates, 8 July legal boundary and August first-full-month guard present' );
$ok( 1 === preg_match( '/\'up_to\'\s*=>\s*\'2000\.00\'/', $rules ) && 1 === preg_match( '/\'up_to\'\s*=>\s*\'4000\.00\'/', $rules ) && 1 === preg_match( '/\'up_to\'\s*=>\s*\'7000\.00\'/', $rules ) && 1 === preg_match( '/\'up_to\'\s*=>\s*\'10000\.00\'/', $rules ) && 1 === preg_match( '/\'up_to\'\s*=>\s*\'14000\.00\'/', $rules ) && 1 === preg_match( '/\'rate\'\s*=>\s*\'0\.35\'/', $rules ), 'six monthly Ethiopian PAYE bands exposed from the server rule set' );
$hr = $read( 'includes/rest/class-rest-hr.php' );
$ok( str_contains( $hr, 'allowance_breakdown_json' ) && str_contains( $hr, 'Payroll payment commit failed' ) && str_contains( $hr, 'compensation_segments' ), 'payroll allowance snapshots, prorating and checked posting commit are present' );
$accounting = $read( 'includes/class-stockland-accounting.php' );
$ok( str_contains( $accounting, 'canonical_journal_lines' ) && str_contains( $accounting, 'existing_journal_matches' ) && str_contains( $accounting, 'SELECT @@session.in_transaction' ), 'PLEDGER/GL retries require matching posted journal and preserve outer transaction' );
$products = $read( 'includes/rest/class-rest-products.php' );
$req      = $read( 'includes/class-stockland-requisition-service.php' );
$app      = $read( 'includes/rest/class-rest-app.php' );
$imports  = $read( 'includes/rest/class-rest-import-v16.php' );
$product_transaction_starts = preg_match_all( '/if\s*\(\s*false\s*===\s*(?:\$wpdb->query|Stockland_DB::execute_query)\(\s*\'START TRANSACTION\'\s*\)\s*\)/', $products );
$ok( $product_transaction_starts >= 3 && str_contains( $products, 'No-change Product Master verification commit failed' ), 'Product Master create, variant and update transactions fail closed through the checked database gateway' );
$ok( 1 === preg_match( "~return\\s+new\\s+WP_Error\\(\\s*'transaction_failed'\\s*,\\s*'Could not start requisition transaction\\.~", $req ), 'requisition transaction-start failure is handled' );
$app_transaction_starts = preg_match_all( '/if\s*\(\s*false\s*===\s*(?:\$wpdb->query|Stockland_DB::execute_query)\(\s*\'START TRANSACTION\'\s*\)\s*\)/', $app );
$ok( $app_transaction_starts >= 7 && str_contains( $app, 'Physical count rejection commit failed' ) && str_contains( $app, 'Could not reject pending physical-count adjustments' ), 'stock reconciliation/count and settings/user transactions fail closed through the checked database gateway' );
$ok( str_contains( $imports, 'Product Master already-posted verification commit failed' ) && str_contains( $imports, 'Daily Sales preview commit failed' ) && str_contains( $imports, 'Product Master import commit failed' ), 'import preview, retry and commit paths check transaction outcomes' );
$accountingRoutes = $read( 'includes/rest/class-rest-accounting.php' );
$ledger           = $read( 'includes/class-stockland-ledger.php' );
$skuService       = $read( 'includes/class-stockland-product-service.php' );
$ok( str_contains( $accountingRoutes, 'Purchase-order create commit failed' ) && str_contains( $accountingRoutes, 'Customer invoice commit failed' ) && str_contains( $accountingRoutes, 'Expense commit failed' ) && str_contains( $accountingRoutes, 'POS payment commit failed' ) && str_contains( $accountingRoutes, 'Payment commit failed' ), 'order, invoice, expense and payment endpoints check commit results' );
$ok( str_contains( $ledger, 'begin_unit_of_work' ) && str_contains( $ledger, 'commit_unit_of_work' ) && str_contains( $ledger, 'ROLLBACK TO SAVEPOINT' ) && str_contains( $ledger, 'Stock balance update failed' ) && str_contains( $ledger, 'Could not rebuild a derived balance row' ) && str_contains( $skuService, 'No-change SKU replacement verification commit failed' ), 'inventory ledger, derived-balance rebuild and SKU replacement use checked atomic transactions/savepoints' );
$ok( str_contains( $skuService, "case 'reservations':" ) && str_contains( $skuService, "case 'transfers':" ) && str_contains( $skuService, "case 'requisitions':" ) && str_contains( $skuService, "case 'purchase_orders':" ) && ! str_contains( $skuService, 'prepare( $check' ), 'SKU replacement reference checks use allow-listed static prepared SQL per operational table' );
$manifest = json_decode( $read( 'RELEASE_MANIFEST.json' ), true );
$ok( ( $manifest['release'] ?? $manifest['version'] ?? '' ) === '16.1.78' && str_contains( $read( 'stockland-inventory.php' ), 'Version:           16.1.78' ), '16.1.78 release markers are consistent' );
if ( $fail ) {
	fwrite( STDERR, '16.1.78 inherited regression gate FAILED: ' . implode( '; ', $fail ) . "\n" );
	exit( 1 );
}echo "16.1.78 inherited regression QA PASS: {$pass} assertions.\n";
