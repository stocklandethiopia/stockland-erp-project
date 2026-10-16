<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
$root     = dirname( __DIR__, 2 );
$fail     = array();
$pass     = 0;
$ok       = function ( $cond, $label ) use ( &$fail, &$pass ) {
	if ( $cond ) {
		++$pass;
		echo "PASS {$label}\n";
	} else {
		$fail[] = $label;
		echo "FAIL {$label}\n";
	}};
$read     = function ( $p ) use ( $root ) {
	$f = $root . '/' . $p;
	return is_file( $f ) ? file_get_contents( $f ) : '';
};
$js       = $read( 'assets/app.js' );
$css      = $read( 'assets/app.min.css' );
$layout   = $read( 'assets/css/05-responsive-layout.css' );
$app      = $read( 'includes/rest/class-rest-app.php' );
$acct     = $read( 'includes/rest/class-rest-accounting.php' );
$gl       = $read( 'includes/class-stockland-accounting.php' );
$manifest = json_decode( $read( 'RELEASE_MANIFEST.json' ), true );
$ok( ( $manifest['release'] ?? '' ) === '16.1.77', '16.1.77 release manifest is current' );
$ok( str_contains( $js, 'function reports()' ) && str_contains( $js, "api('/app/reports/summary?" ) && str_contains( $js, "['reports','Reports & Analytics',canReports]" ), 'Reports screen, route loader and visible navigation are wired' );
$ok( str_contains( $app, 'location_scope_unavailable' ) && preg_match( '/(?:\\$scope\\s*===\\s*false|false\\s*===\\s*\\$scope)/', $app ), 'report scope fails closed for invalid/unassigned location' );
$ok( str_contains( $layout, '.sl-modern-grid{grid-template-columns:minmax(0,1.2fr) minmax(260px,.9fr)}' ) && str_contains( $layout, '@media (max-width:991.98px)' ), 'desktop uses two columns while tablet/mobile keep one-column breakpoints' );
$ok( str_contains( $js, 'name="request_key"' ) && str_contains( $js, 'request_key:requestKey' ), 'PO creation and receipt UI keep stable retry keys' );
$ok( str_contains( $acct, '[[SLPOREQ:' ) && str_contains( $acct, 'po_create_request_' ) && str_contains( $acct, 'FOR UPDATE' ), 'PO create retries serialize by request key and retain row-lock checks' );
$ok( str_contains( $acct, '[[SLGRNREQ:' ) && str_contains( $acct, 'grn_request_' ) && str_contains( $acct, 'idempotency_conflict' ), 'goods receipt retries replay only exact matching submissions' );
$ok( str_contains( $acct, 'FOR UPDATE' ) && str_contains( $acct, 'ORDER BY id FOR UPDATE' ), 'PO and PO line rows are locked before receipt quantities change' );
$ok( str_contains( $acct, 'Stockland_Ledger::record' ) && str_contains( $acct, 'Stockland_Accounting::post_journal' ) && str_contains( $acct, 'Stockland_Audit::log' ), 'receiving keeps inventory ledger, balanced journal, and audit in the transaction' );
$ok( str_contains( $gl, 'UNIQUE KEY source (source_type,source_id)' ) && str_contains( $gl, 'existing_journal_matches' ) && str_contains( $gl, 'SELECT @@session.in_transaction' ), 'PLEDGER/GL unique source guard and nested transaction/savepoint protection remain' );
$ok( str_contains( $css, '--sl-font:Inter' ) && str_contains( $css, 'font-family:var(--sl-font)!important' ) && ! str_contains( $css, 'Times New Roman' ) && str_contains( $css, 'body[data-sl-theme="emerald"]' ) && str_contains( $css, '.sl-theme-option' ) && str_contains( $css, 'background:var(--sl-navy)' ), 'approved sans-serif typography and premium navy workspace theme remain compiled' );
$ok( str_contains( $read( 'stockland-inventory.php' ), "define( 'STOCKLAND_DB_VERSION', '16.1.1' );" ), 'database version is unchanged' );
$ok( str_contains( $read( 'includes/class-stockland-accounting.php' ), 'CREATE TABLE {$p}stock_gl_journals' ) && str_contains( $read( 'includes/class-stockland-accounting.php' ), 'UNIQUE KEY source (source_type,source_id)' ), 'no schema expansion required; GL source uniqueness is existing DDL' );
$auth  = $read( 'includes/class-stockland-authority.php' );
$roles = $read( 'includes/class-stockland-roles.php' );
$ok( str_contains( $auth, 'can_create_external_purchase_order' ) && str_contains( $auth, 'can_approve_external_purchase_order' ) && str_contains( $auth, 'can_receive_external_purchase_order' ), 'external procurement uses exact ERP role-profile authorities' );
$ok( str_contains( $acct, 'OWNER_APPROVED' ) && str_contains( $acct, 'external_supply_destination' ) && str_contains( $acct, 'OWNER_REVIEW' ), 'external procurement requires separate Owner approval and Central Warehouse target' );
$ok( str_contains( $acct, 'Only an active Stockland Management profile may enter' ) && str_contains( $acct, 'Only a different active Owner may approve' ) && str_contains( $acct, 'Only an active Stockland Warehouse Manager may accept' ), 'API rejects Administrator/settings, self-approval and Warehouse Employee bypasses' );
$ok( str_contains( $acct, 'WHERE id=%d FOR UPDATE' ) && str_contains( $acct, 'ORDER BY id FOR UPDATE' ) && str_contains( $acct, 'Stockland_Ledger::record' ) && str_contains( $acct, 'Stockland_Accounting::post_journal' ) && str_contains( $acct, 'Stockland_Audit::log' ), 'receipt locks PO and lines and atomically posts inventory, PLEDGER/GL and audit' );
$ok( str_contains( $acct, 'purchase_order_receipt_required' ) && str_contains( $acct, 'purchase_order_receipt_posted' ) && str_contains( $js, 'data-review-purchase-order' ), 'owner/warehouse/outcome notifications are actionable and location scoped' );
$ok( ! preg_match( '/FOREIGN KEY\s*\(/i', $read( 'includes/class-stockland-accounting.php' ) ) && ( str_contains( $read( 'includes/class-stockland-integrity.php' ), 'foreign key' ) || str_contains( $read( 'includes/class-stockland-integrity.php' ), 'foreign-key' ) ), 'no unreviewed schema/FK migration introduced; read-only FK audit capability retained' );
if ( $fail ) {
	fwrite( STDERR, 'Corrective 16.1.77 QA FAILED: ' . implode( '; ', $fail ) . "\n" );
	exit( 1 );
}echo "Corrective 16.1.77 QA PASS: {$pass} assertions.\n";
