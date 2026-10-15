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
if ( $fail ) {
	fwrite( STDERR, 'Operational UI contracts FAILED: ' . implode( '; ', $fail ) . "\n" );
	exit( 1 );
}echo "Operational UI contracts PASS: {$pass} assertions.\n";
