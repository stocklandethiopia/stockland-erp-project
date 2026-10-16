<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}
$root  = dirname( __DIR__, 2 );
$health = is_file( $root . '/includes/rest/class-rest-app.php' ) ? file_get_contents( $root . '/includes/rest/class-rest-app.php' ) : '';
$import = is_file( $root . '/includes/rest/class-rest-import-v16.php' ) ? file_get_contents( $root . '/includes/rest/class-rest-import-v16.php' ) : '';
$app    = is_file( $root . '/assets/app.js' ) ? file_get_contents( $root . '/assets/app.js' ) : '';
$manifest_raw = is_file( $root . '/RELEASE_MANIFEST.json' ) ? file_get_contents( $root . '/RELEASE_MANIFEST.json' ) : '';
$manifest = is_string( $manifest_raw ) ? json_decode( $manifest_raw, true ) : null;
$fail   = array();
$pass   = 0;
$ok     = function ( $condition, $message ) use ( &$fail, &$pass ) {
	if ( $condition ) {
		++$pass;
		echo "PASS {$message}\n";
	} else {
		$fail[] = $message;
		echo "FAIL {$message}\n";
	}
};
$compact_import = preg_replace( '/\s+/', '', $import );
$compact_health = preg_replace( '/\s+/', '', $health );
$has_legacy_certification_flag = function ( $value ) use ( &$has_legacy_certification_flag ) {
	if ( ! is_array( $value ) ) {
		return false;
	}
	foreach ( $value as $key => $child ) {
		if ( in_array( $key, array( 'live_certification', 'production_certified' ), true ) ) {
			return true;
		}
		if ( $has_legacy_certification_flag( $child ) ) {
			return true;
		}
	}
	return false;
};
$ok(
	is_array( $manifest )
		&& ! $has_legacy_certification_flag( $manifest )
		&& ( $manifest['release_acceptance']['target_host'] ?? '' ) === 'ACCEPTED_EXISTING_HOST_BASELINE_FROM_PRIOR_FULL_LIVE_READY_REPORT'
		&& ( $manifest['release_acceptance']['production_deployment'] ?? '' ) === 'PACKAGE_READY; CI_DOES_NOT_DEPLOY_TO_HOST',
	'Release manifest carries forward the accepted LIVE READY host baseline, records package readiness accurately, and omits legacy binary certification flags.'
);
$ok(
	str_contains( $health, 'if ( is_string( $import_source ) )' )
		&& str_contains( $health, "preg_replace( '/\\s+/', '', $"."import_source )" )
		&& str_contains( $health, '$normalized_import_source = false;' )
		&& str_contains( $health, "strpos( $"."normalized_import_source, \"array('initial_stock','opening_stock','stock_quantity','quantity','qty','stock')\" ) !== false" ),
	'Product Master stock-field health contract is whitespace-insensitive.'
);
$ok(
	str_contains( $import, 'is not permitted in Product Master import. Use Warehouse Receiving for physical stock.' )
		&& str_contains( $import, 'Product Master commit rejected physical stock field' )
		&& str_contains( $compact_import, "array('initial_stock','opening_stock','stock_quantity','quantity','qty','stock')" ),
	'Preview and commit both preserve the no-stock Product Master boundary.'
);
$ok(
	str_contains( $app, 'const integrity=q.supplemental_integrity_scan;' )
		&& str_contains( $app, 'Checks requiring review' )
		&& str_contains( $app, 'UNKNOWN — query did not return a reliable result' )
		&& str_contains( $app, 'CHECKS CLEAN — FK metadata reviewed separately' ),
	'System Health shows non-zero/unknown scanner checks and separates FK metadata status.'
);
$ok(
	str_contains( $health, 'Quick health skips the full integrity matrix.' )
		&& str_contains( $health, "\$diagnostics['live_ready_status'] = 'NOT_EVALUATED';" )
		&& str_contains( $app, 'Deep integrity checks pending — status UNVERIFIED' )
		&& str_contains( $app, 'It has not checked ledger, PLEDGER, AP/AR, GL, stock or audit-chain integrity.' ),
	'Quick health reports UNVERIFIED and preserves fail-closed readiness until deep checks run.'
);
$setup = is_file( $root . '/includes/rest/class-rest-setup.php' ) ? file_get_contents( $root . '/includes/rest/class-rest-setup.php' ) : '';
$accounting = is_file( $root . '/includes/class-stockland-accounting.php' ) ? file_get_contents( $root . '/includes/class-stockland-accounting.php' ) : '';
$ok(
	str_contains( $compact_health, "\$checks['accounting_schema_upgrade_ready']=empty(\$accounting_schema_gaps);" )
		&& str_contains( $compact_health, "\$checks['hr_schema_upgrade_ready']=empty(\$hr_schema_gaps);" )
		&& str_contains( $compact_health, "'accounting_schema_upgrade_ready']&&\$checks['hr_schema_upgrade_ready']" )
		&& str_contains( $compact_health, "\$diagnostics['accounting_schema_upgrade_gaps']" )
		&& str_contains( $compact_health, "\$diagnostics['hr_schema_upgrade_gaps']" ),
	'Deep LIVE READY includes both schema-upgrade gates that control route registration.'
);
$ok(
	str_contains( $setup, "'/system/health'" )
		&& str_contains( $setup, 'schema_gate_health' )
		&& str_contains( $setup, "'health_mode'" )
		&& str_contains( $setup, 'empty( Stockland_HR::upgrade_gaps() )' )
		&& str_contains( $setup, '$snapshot = $this->snapshot( true, true );' )
		&& str_contains( $setup, 'private function snapshot( $read_only = false, $include_schema_details = false )' )
		&& str_contains( $setup, 'if ( ! $read_only )' )
		&& str_contains( $setup, 'if ( ! $this->can_setup() && ! $include_schema_details )' )
		&& str_contains( $setup, '$snapshot = $this->snapshot( true, true );' ),
	'Schema-gated startup keeps a read-only repair health endpoint and aligns HR route readiness.'
);
$ok(
	str_contains( $accounting, '__table_metadata_query_failed__' )
		&& str_contains( $accounting, '__column_metadata_query_failed__' )
		&& str_contains( $accounting, '__index_metadata_query_failed__' ),
	'Accounting metadata SQL failures remain explicit repair gates rather than being treated as absent schema.'
);
$ok(
	str_contains( $app, "const setupRequired=q.health_mode==='setup';" )
		&& str_contains( $app, 'Full integrity scan has not run in setup mode.' )
		&& str_contains( $app, 'SETUP REPAIR REQUIRED' ),
	'Frontend does not present a deferred setup-mode audit as a passed audit.'
);

if ( $fail ) {
	fwrite( STDERR, 'Health gate / diagnostics QA FAILED: ' . implode( '; ', $fail ) . "\n" );
	exit( 1 );
}
echo "Health gate / diagnostics QA PASS: {$pass} assertions.\n";
