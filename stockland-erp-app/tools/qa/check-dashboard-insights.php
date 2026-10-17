<?php
/**
 * Static contract gate for the 16.1.79 read-only dashboard analytics.
 */
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}
$root = dirname( __DIR__, 2 );
$read = static function ( $relative ) use ( $root ) {
	return file_get_contents( $root . '/' . $relative );
};
$checks = array();
$plugin = $read( 'stockland-inventory.php' );
$manifest = json_decode( $read( 'RELEASE_MANIFEST.json' ), true );
$rest = $read( 'includes/rest/class-rest-app.php' );
$js = $read( 'assets/app.js' );
$css = $read( 'assets/app.css' );
$mini = $read( 'assets/app.min.css' );
$modules = json_decode( $read( 'assets/css/modules.json' ), true );
$payroll = $read( 'includes/class-stockland-payroll-statutory.php' );
$payroll_test = $read( 'tools/qa/check-payroll-statutory.php' );

$checks['release_identity_167'] = str_contains( $plugin, 'Version:           16.1.79' ) && ( $manifest['release'] ?? '' ) === '16.1.79';
$checks['unchanged_49_table_contract'] = ( $manifest['schema_contract']['total'] ?? 0 ) === 49 && ( $manifest['schema_contract']['new_business_table'] ?? true ) === false && ( $manifest['corrective_release_16_1_67']['schema_changed'] ?? true ) === false;
$checks['insights_route_has_permission_callback'] = str_contains( $rest, "'/app/dashboard/insights'" ) && str_contains( $rest, "'callback'" ) && str_contains( $rest, "'dashboard_insights'" ) && str_contains( $rest, "'permission_callback'" );
$checks['insights_rejects_missing_location_scope'] = str_contains( $rest, 'public function dashboard_insights()' ) && str_contains( $rest, '$this->scope_locations()' ) && str_contains( $rest, "'location_scope_unavailable'" );
$checks['insights_use_closed_posted_sales'] = str_contains( $rest, "posting_status='CLOSED' AND sale_date BETWEEN %s AND %s" ) && str_contains( $rest, 'GROUP BY sale_date ORDER BY sale_date' );
$checks['insights_location_filter_applied_to_each_query'] = substr_count( substr( $rest, strpos( $rest, 'public function dashboard_insights()' ), strpos( $rest, 'public function reports_summary( WP_REST_Request $r )' ) - strpos( $rest, 'public function dashboard_insights()' ) ), '$this->append_scope_filter(' ) >= 4;
$insights_start = strpos( $rest, 'public function dashboard_insights()' );
$insights_end = strpos( $rest, 'public function reports_summary( WP_REST_Request $r )', $insights_start );
$insights = ( false !== $insights_start && false !== $insights_end ) ? substr( $rest, $insights_start, $insights_end - $insights_start ) : '';
$checks['dashboard_query_failures_are_not_reported_as_zero'] = substr_count( $insights, "'dashboard_query_failed'" ) === 4;
$checks['insights_exclude_personal_and_profit_fields'] = '' !== $insights && ! preg_match( '/customer_name|customer_phone|staff_user_id|display_name|gross_profit|\bcogs\b|purchase_cost/', $insights );
$checks['dashboard_consumes_server_data'] = str_contains( $js, 'function loadDashboardInsights(' ) && str_contains( $js, "api('/app/dashboard/insights')" ) && str_contains( $js, 'dashboardTrendHtml(state.dashboardInsights)' );
$checks['dashboard_has_actual_empty_error_states'] = str_contains( $js, 'No posted retail transactions were found' ) && str_contains( $js, 'Dashboard analytics are unavailable' ) && str_contains( $js, 'No posted category sales this month' );
$checks['dashboard_layout_contract'] = str_contains( $js, 'sl-dashboard-hero' ) && str_contains( $js, 'slDashboardSalesTrend' ) && str_contains( $js, 'slDashboardCategories' ) && str_contains( $js, 'slDashboardBranches' ) && str_contains( $js, 'slDashboardTransactions' ) && str_contains( $js, 'sl-dashboard-quick-grid' );
$dashboard_start = strpos( $js, 'function dashboard(){' );
$dashboard_end = strpos( $js, 'function dashboardTrendHtml', $dashboard_start );
$dashboard = ( false !== $dashboard_start && false !== $dashboard_end ) ? substr( $js, $dashboard_start, $dashboard_end - $dashboard_start ) : '';
$chart_grid_pos = strpos( $dashboard, 'sl-dashboard-chart-grid' );
$branch_panel_pos = strpos( $dashboard, 'sl-dashboard-branch-performance' );
$transactions_pos = strpos( $dashboard, 'sl-dashboard-transactions' );
$side_pos = strpos( $dashboard, 'sl-dashboard-side' );
$quick_pos = strpos( $dashboard, 'sl-dashboard-quick-grid' );
$activity_pos = strpos( $dashboard, 'sl-dashboard-activity' );
$pulse_pos = strpos( $dashboard, 'sl-dashboard-pulse' );
$checks['reference_dashboard_panel_order'] = false !== $chart_grid_pos && false !== $branch_panel_pos && false !== $transactions_pos && $chart_grid_pos < $branch_panel_pos && $branch_panel_pos < $transactions_pos && false !== $side_pos && false !== $quick_pos && false !== $activity_pos && false !== $pulse_pos && $side_pos < $quick_pos && $quick_pos < $activity_pos && $activity_pos < $pulse_pos;
$checks['desktop_dashboard_has_three_analytics_columns'] = (bool) preg_match( '/\\.sl-dashboard-chart-grid\\{[^}]*grid-template-columns:repeat\\(3,minmax\\(0,1fr\\)\\)/', $css );
$checks['dashboard_data_driven_styles_in_bundles'] = str_contains( $css, '.sl-dashboard-shell' ) && str_contains( $css, '.sl-dashboard-linechart' ) && str_contains( $css, '.sl-dashboard-donut' ) && str_contains( $mini, '.sl-dashboard-shell' ) && str_contains( $mini, '.sl-dashboard-donut' );
$checks['responsive_dashboard_breakpoints'] = preg_match( '/@media\s*\(max-width:\s*991px\)/', $css ) && preg_match( '/@media\s*\(max-width:\s*700px\)/', $css ) && preg_match( '/@media\s*\(max-width:\s*390px\)/', $css );
$checks['css_source_modules_remain_six_ordered'] = ( $modules['release'] ?? '' ) === '16.1.79' && count( $modules['modules'] ?? array() ) === 6;
$checks['payroll_base_less_employee_pension'] = str_contains( $payroll, 'ET-PAYE-1395-2025-v3-PENSION-BASE' ) && str_contains( $payroll, 'TAXABLE_GROSS_LESS_EMPLOYEE_PENSION' ) && str_contains( $payroll, 'Stockland_Money::subtract( $taxable_gross, $employee_pension )' );
$checks['etb_30000_payroll_regression_present'] = str_contains( $payroll_test, "'30000.00'" ) && str_contains( $payroll_test, "'27900.00'" ) && str_contains( $payroll_test, "'7715.00'" ) && str_contains( $payroll_test, "'20185.00'" );

$failed = array();
foreach ( $checks as $name => $ok ) {
	printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $name );
	if ( ! $ok ) {
		$failed[] = $name;
	}
}
if ( $failed ) {
	fwrite( STDERR, 'Dashboard / payroll regression gate failed: ' . implode( ', ', $failed ) . "\n" );
	exit( 1 );
}
echo 'Dashboard / payroll regression gate PASS: ' . count( $checks ) . " assertions.\n";
