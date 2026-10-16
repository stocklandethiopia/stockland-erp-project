<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
$root     = dirname( __DIR__, 2 );
$commands = array(
	'php ' . escapeshellarg( $root . '/tools/qa/check-rest-permissions.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/test-rest-callback-integrity.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-schema-contract.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-ddl-49.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-sequences.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-xlsx-safety.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/smoke-php.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-release-integrity.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-client-sinks.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-runtime-diagnostics-security.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/smoke-integrity-scan.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-requisition-integrity-join.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-runtime-security-heuristics.php' ),
	'node ' . escapeshellarg( $root . '/tools/qa/check-api-origin.js' ),
	'python3 ' . escapeshellarg( $root . '/tools/qa/rebuild-css.py' ),
	'bash ' . escapeshellarg( $root . '/tools/qa/check-css.sh' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-operational-ui-contracts.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-dashboard-insights.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-menu-mobile-layout.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-runtime-hygiene.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-critical-workflows.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-three-remediations.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-payroll-statutory.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-hr-schema-contract.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/smoke-pos-controller.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-corrective-1653-regressions.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-corrective-1653.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-external-procurement-authority.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-product-master-search.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-accounting-prepared-reads.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-release-1663.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-health-gate-diagnostics.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-global-search.php' ),
	'php ' . escapeshellarg( $root . '/tools/qa/check-cumulative-operational-regressions.php' ),
);
foreach ( $commands as $command ) {
	passthru( $command, $code );
	if ( $code !== 0 ) {
		exit( $code );
	}
}
echo "Static QA PASS.\n";
