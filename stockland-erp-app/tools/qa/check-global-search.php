<?php
/**
 * Regression contract for the 16.1.78 global ERP record search.
 * This is a source-level check; authenticated live acceptance remains separate.
 */
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}
$root = dirname( __DIR__, 2 );
$read = static function ( $relative ) use ( $root ) {
	return file_get_contents( $root . '/' . $relative );
};
$manifest = json_decode( $read( 'RELEASE_MANIFEST.json' ), true );
$plugin   = $read( 'stockland-inventory.php' );
$frontend = $read( 'includes/class-stockland-frontend.php' );
$rest     = $read( 'includes/rest/class-rest-app.php' );
$app      = $read( 'assets/app.js' );
$nav      = $read( 'assets/css/02-shell-navigation.css' );
$responsive = $read( 'assets/css/05-responsive-layout.css' );
$modules  = json_decode( $read( 'assets/css/modules.json' ), true );
$fail     = array();
$pass     = 0;
$check    = static function ( $ok, $label ) use ( &$fail, &$pass ) {
	if ( $ok ) {
		++$pass;
		echo "PASS {$label}\n";
	} else {
		$fail[] = $label;
		echo "FAIL {$label}\n";
	}
};
$search_start = strpos( $rest, 'public function global_search(' );
$search_end   = strpos( $rest, 'public function dashboard_insights(', $search_start );
$search       = false !== $search_start && false !== $search_end ? substr( $rest, $search_start, $search_end - $search_start ) : '';
$check(
	( $manifest['release'] ?? '' ) === '16.1.78'
		&& str_contains( $plugin, 'Version:           16.1.78' )
		&& str_contains( $plugin, "define( 'STOCKLAND_VERSION', '16.1.78' );" )
		&& str_contains( $frontend, "const ROUTE_VERSION      = '16.1.78';" ),
	'Plugin, frontend cache version and release manifest are aligned at 16.1.78.'
);
$check(
	( $manifest['schema_contract']['total'] ?? 0 ) === 49
		&& ( $manifest['corrective_release_16_1_78']['schema_changed'] ?? true ) === false
		&& ( $manifest['corrective_release_16_1_78']['database_version_changed'] ?? true ) === false,
	'Global search leaves the existing 49-table business contract and database version unchanged.'
);
$check(
	str_contains( $rest, "'/app/global-search'" )
		&& str_contains( $rest, "'callback'            => array( $this, 'global_search' )" )
		&& str_contains( $rest, "current_user_can( 'manage_options' ) || Stockland_Authority::assert_profile()" ),
	'REST route is registered behind authentication and the existing Stockland profile gate.'
);
$check(
	str_contains( $search, "strlen( $term ) < 2" )
		&& str_contains( $search, "substr( trim( sanitize_text_field" )
		&& str_contains( $search, "WHERE is_active=1 AND product_type<>'STYLE'" )
		&& str_contains( $search, 'LIMIT %d' ),
	'Search input is sanitized/bounded; only active non-style Product Master variants are returned in capped result sets.'
);
$check(
	str_contains( $search, 'Stockland_Authority::can_view_external_purchase_orders()' )
		&& str_contains( $search, '$this->scope_locations()' )
		&& str_contains( $search, "\\$this->append_scope_filter( 's.branch_location_id'" )
		&& str_contains( $search, 'po.location_id IN(' ),
	'Customer, sales and purchase-order search is role/capability/location scoped.'
);
$check(
	! str_contains( $search, 'phone' )
		&& ! str_contains( $search, 'email' )
		&& ! str_contains( $search, 'opening_balance' )
		&& ! str_contains( $search, 'purchase_cost' )
		&& ! str_contains( $search, 'gross_profit' ),
	'Search response projection does not return contact details, customer balances, product cost or profit.'
);
$check(
	str_contains( $app, 'id="slGlobalSearch"' )
		&& str_contains( $app, 'id="slGlobalSearchResults"' )
		&& str_contains( $app, "api('/app/global-search?q='+encodeURIComponent(q)" )
		&& str_contains( $app, 'window.__stocklandGlobalSearchRequest' )
		&& str_contains( $app, 'function bindGlobalSearch()' )
		&& str_contains( $app, "event.key==='Escape'" ),
	'Header search uses the real REST API, ignores stale responses and supports keyboard dismissal.'
);
$check(
	str_contains( $nav, '.sl-global-search-control' )
		&& str_contains( $nav, '.sl-global-search-results' )
		&& str_contains( $responsive, '.sl-global-search{grid-column:1;grid-row:2' )
		&& str_contains( $responsive, 'grid-template-rows:40px 42px 44px' ),
	'Header search has a dedicated responsive row and bounded result panel.'
);
$check(
	( $modules['release'] ?? '' ) === '16.1.78'
		&& count( $modules['modules'] ?? array() ) === 6,
	'Six ordered CSS modules are versioned together at 16.1.78.'
);
if ( $fail ) {
	fwrite( STDERR, 'FAIL global-search QA: ' . count( $fail ) . " failure(s)\n" );
	foreach ( $fail as $failure ) {
		fwrite( STDERR, " - {$failure}\n" );
	}
	exit( 1 );
}
echo "PASS global-search QA: {$pass} assertions\n";
