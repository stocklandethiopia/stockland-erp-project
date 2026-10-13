<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
$root                               = dirname( __DIR__, 2 );
$version                            = '16.1.66';
$checks                             = array();
$checks['plugin_version']           = str_contains( file_get_contents( $root . '/stockland-inventory.php' ), "Version:           {$version}" );
$checks['frontend_route_version']   = 1 === preg_match( '/ROUTE_VERSION\\s*=\\s*[\'\"]' . preg_quote( $version, '/' ) . '[\'\"]/', file_get_contents( $root . '/includes/class-stockland-frontend.php' ) );
$checks['no_legacy_ui_switch']      = ! preg_match( '/view_(?:legacy_ui|modern_saas)/', file_get_contents( $root . '/includes/class-stockland-frontend.php' ) );
$checks['no_advisor_endpoint']      = ! preg_match( '/inventory\/advisor|ai\/suggest|loadAdvisor/', file_get_contents( $root . '/assets/app.js' ) );
$checks['bootstrap_local']          = is_file( $root . '/assets/bootstrap.min.css' );
$checks['service_worker_bootstrap'] = str_contains( file_get_contents( $root . '/includes/class-stockland-frontend.php' ), 'assets/bootstrap.min.css' );
$checks['csp_nonce']                = str_contains( file_get_contents( $root . '/includes/class-stockland-frontend.php' ), 'Content-Security-Policy' );
$checks['returns_capability']       = str_contains( file_get_contents( $root . '/includes/rest/class-rest-returns.php' ), 'Stockland_Authority::can_view_returns' );
$checks['sequence_service']         = is_file( $root . '/includes/class-stockland-sequence.php' );
$checks['money_service']            = is_file( $root . '/includes/class-stockland-money.php' );
$checks['integrity_service']        = is_file( $root . '/includes/class-stockland-integrity.php' );
$checks['unit_tests']               = is_dir( $root . '/tests/Unit' ) && count( glob( $root . '/tests/Unit/*Test.php' ) ) >= 2;
$checks['ci_definition']            = is_file( $root . '/.github/workflows/ci.yml' );
$checks['coding_standard']          = is_file( $root . '/phpcs.xml.dist' );

$css                                = file_get_contents( $root . '/assets/app.css' );
$open                               = substr_count( $css, '{' );
$close                              = substr_count( $css, '}' );
$checks['css_balanced']             = ( $open === $close );
$checks['header_labels_not_hidden'] = ! preg_match( '/sl-top-action-text[^{}]*\{[^}]*display\s*:\s*none\s*!important/i', $css );
$checks['mobile_drawer_open_state'] = str_contains( $css, '.sl-mobile-menu-open .sl-mobile-menu{display:grid}' );
$checks['page_action_bar_sticky']   = str_contains( $css, '.sl-content-inner>.sl-page-title{position:sticky' );

foreach ( $checks as $name => $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . " {$name}\n";
}
if ( in_array( false, $checks, true ) ) {
	exit( 1 );
}
echo "Release integrity QA PASS.\n";
