<?php
/**
 * Regression contract for the simplified, review-first payroll workflow.
 * This checks source wiring and does not claim authenticated live-host acceptance.
 */
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}
$root = dirname( __DIR__, 2 );
$read = static function ( $relative ) use ( $root ) {
	return is_file( $root . '/' . $relative ) ? file_get_contents( $root . '/' . $relative ) : '';
};
$plugin = $read( 'stockland-inventory.php' );
$manifest = json_decode( $read( 'RELEASE_MANIFEST.json' ), true );
$app = $read( 'assets/app.js' );
$hr_rest = $read( 'includes/rest/class-rest-hr.php' );
$authority = $read( 'includes/class-stockland-authority.php' );
$statutory = $read( 'includes/class-stockland-payroll-statutory.php' );
$statutory_test = $read( 'tools/qa/check-payroll-statutory.php' );
$hr = $read( 'includes/class-stockland-hr.php' );
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
$check(
	str_contains( $plugin, 'Version:           16.1.79' ) && ( $manifest['release'] ?? '' ) === '16.1.79',
	'Plugin and manifest carry the 16.1.79 release identity.'
);
$check(
	( $manifest['schema_contract']['total'] ?? 0 ) === 49
		&& ( $manifest['schema_contract']['new_business_table'] ?? true ) === false
		&& str_contains( $plugin, "define( 'STOCKLAND_CORE_SCHEMA_VERSION', '16.1.1' );" )
		&& str_contains( $plugin, "define( 'STOCKLAND_ACCOUNTING_SCHEMA_VERSION', '16.1.15' );" )
		&& str_contains( $plugin, "define( 'STOCKLAND_HR_SCHEMA_VERSION', '16.1.3' );" )
		&& str_contains( $plugin, "define( 'STOCKLAND_DB_VERSION', '16.1.1' );" ),
	'Payroll usability changes preserve all 49 tables and all existing schema markers.'
);
$check(
	str_contains( $app, 'name="payroll_month" type="month" required' )
		&& str_contains( $app, 'period_key:month' )
		&& str_contains( $app, "start_date:month+'-01'" )
		&& str_contains( $app, "String(lastDay).padStart(2,'0')" )
		&& str_contains( $app, 'if(!/^\d{4}-(0[1-9]|1[0-2])$/.test(month))' ),
	'Monthly payroll period creation uses one month picker and derives the exact calendar dates.'
);
$check(
	str_contains( $app, 'async function openPayrollReview(periodId)' )
		&& str_contains( $app, "api16('/hr/payroll')" )
		&& str_contains( $app, 'Array.isArray(period.lines)' )
		&& str_contains( $app, 'data-label="Employee"' )
		&& str_contains( $app, 'data-label="Net pay"' ),
	'Review view consumes real payroll rows and presents employee-level amounts with mobile labels.'
);
$payroll_table_start = strpos( $app, "const pe=document.getElementById('hrPayroll');" );
$payroll_table_end   = strpos( $app, "document.querySelectorAll('[data-hr-comp]')", $payroll_table_start );
$payroll_table       = false !== $payroll_table_start && false !== $payroll_table_end
	? substr( $app, $payroll_table_start, $payroll_table_end - $payroll_table_start )
	: '';
$check(
	str_contains( $payroll_table, 'REVIEW DRAFT' )
		&& str_contains( $payroll_table, 'REVIEW SUBMISSION' )
		&& str_contains( $payroll_table, 'REVIEW APPROVED PAYROLL' )
		&& ! str_contains( $payroll_table, 'SUBMIT FOR OWNER APPROVAL</button>' )
		&& ! str_contains( $payroll_table, '>OWNER APPROVE</button>' )
		&& ! str_contains( $payroll_table, '>PAY BANK</button>' )
		&& str_contains( $app, "if(a==='REVIEW'){await openPayrollReview(id);return}" ),
	'Payroll summary rows route users to a role-aware review step instead of one-click posting/payment.'
);
$check(
	str_contains( $app, 'function openPayrollLineEditor(period,line,reviewOverlay)' )
		&& str_contains( $app, "api16('/hr/payroll/'+encodeURIComponent(period.id)+'/line/'+encodeURIComponent(line.id)" )
		&& str_contains( $app, "line.statutory_status!=='CALCULATED'" )
		&& str_contains( $app, 'line.tax_rule_version!==' ),
	'Draft line review exposes only the existing draft-edit endpoint and blocks submission with stale calculation versions.'
);
$check(
	str_contains( $app, "else if(period.status==='DRAFT'&&canApprove&&lines.length)" )
		&& str_contains( $app, 'only Management may edit or submit it' )
		&& str_contains( $app, 'Submit for Owner approval' )
		&& str_contains( $app, 'Approve & post GL' ),
	'UI keeps Management submission and Owner approval/payment as distinct authority stages.'
);
$check(
	str_contains( $app, "api16('/hr/payroll/'+encodeURIComponent(period.id)+'/submit'" )
		&& str_contains( $app, "api16('/hr/payroll/'+encodeURIComponent(period.id)+'/approve'" )
		&& str_contains( $app, "api16('/hr/payroll/'+encodeURIComponent(period.id)+'/pay'" )
		&& str_contains( $app, "confirm('Approve this reviewed payroll" )
		&& str_contains( $app, "confirm('Record Owner payroll payment" ),
	'Review actions call existing submit/approve/pay REST transitions, with confirmations before posting/payment.'
);
$check(
	str_contains( $hr_rest, "'/hr/payroll/" ) && str_contains( $hr_rest, 'public function payroll_line_update' )
		&& str_contains( $hr_rest, 'Stockland_Authority::can_manage_payroll()' )
		&& str_contains( $hr_rest, 'Stockland_Authority::can_approve_payroll()' ),
	'Existing payroll endpoints remain protected by server-side Management vs Owner authority.'
);
$check(
	str_contains( $hr_rest, 'if ( \'DRAFT\' !== $period[\'status\'] )' )
		&& str_contains( $hr_rest, 'Stockland_Payroll_Statutory::calculate' )
		&& str_contains( $hr_rest, 'PAYROLL_LINE_UPDATE' )
		&& str_contains( $hr_rest, 'ROLLBACK' ),
	'Payroll line edits remain draft-only, re-run the authoritative calculator and audit within a transaction.'
);
$check(
	str_contains( $hr_rest, 'reuses_classification' )
		&& str_contains( $hr_rest, 'line_rule_is_current' )
		&& str_contains( $hr_rest, 'new or legacy non-standard pensionable salary' ),
	'Repeated edits can reuse a current validated pension classification while new or legacy changes still need a legal basis.'
);
$check(
	str_contains( $authority, 'self::role( $uid ) === self::MANAGEMENT' )
		&& str_contains( $authority, 'self::role( $uid ) === self::OWNER' )
		&& str_contains( $authority, 'function can_manage_payroll' )
		&& str_contains( $authority, 'function can_approve_payroll' ),
	'Authority helpers keep payroll preparation Management-only and final approval/payment Owner-only.'
);
$check(
	str_contains( $statutory, 'ET-PAYE-1395-2025-v3-PENSION-BASE' )
		&& str_contains( $statutory, 'TAXABLE_GROSS_LESS_EMPLOYEE_PENSION' )
		&& str_contains( $statutory, "EMPLOYEE_PENSION_RATE = '0.07'" )
		&& str_contains( $statutory, "EMPLOYER_PENSION_RATE = '0.11'" )
		&& str_contains( $statutory_test, "'30000.00'" )
		&& str_contains( $statutory_test, "'7715.00'" )
		&& str_contains( $statutory_test, "'20185.00'" ),
	'PAYE/pension formulas and the ETB 30,000 regression remain unchanged.'
);
$check(
	str_contains( $hr_rest, "UPPER(COALESCE(employment_type,''))<>'RETAINER_SERVICE'" )
		&& str_contains( $hr_rest, 'excluded_service_contracts' )
		&& str_contains( $hr, "'allowance_breakdown_json'" ),
	'Retainer service contracts remain excluded from employee payroll and allowance component audit snapshots remain available.'
);
$check(
	str_contains( $app, 'sl-payroll-review' )
		&& str_contains( $app, 'sl-payroll-line-editor' )
		&& str_contains( $app, 'data-payroll-review-approve' )
		&& str_contains( $app, 'data-payroll-review-bank' ),
	'Guided payroll review controls have corresponding responsive modal styling hooks.'
);
if ( $fail ) {
	fwrite( STDERR, 'Simplified payroll workflow QA FAILED: ' . implode( '; ', $fail ) . "\n" );
	exit( 1 );
}
echo 'Simplified payroll workflow QA PASS: ' . $pass . " assertions.\n";
