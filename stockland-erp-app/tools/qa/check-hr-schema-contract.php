<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
$root  = dirname( __DIR__, 2 );
$hr    = file_get_contents( $root . '/includes/class-stockland-hr.php' );
$rest  = file_get_contents( $root . '/includes/rest/class-rest-hr.php' );
$app   = file_get_contents( $root . '/includes/rest/class-rest-app.php' );
$acct  = file_get_contents( $root . '/includes/class-stockland-accounting.php' );
$js    = file_get_contents( $root . '/assets/app.js' );
$fail  = array();
$pass  = 0;
$check = function ( $condition, $message ) use ( &$fail, &$pass ) {
	if ( $condition ) {
		++$pass;
	} else {
		$fail[] = $message;
	}
};
foreach ( array( 'taxable_allowance_total', 'exempt_allowance_total', 'pensionable_salary', 'statutory_classification_status', 'statutory_classification_note' ) as $column ) {
	$check( strpos( $hr, "'{$column}'" ) !== false, 'HR compensation migration missing ' . $column );
}
foreach ( array( 'income_tax_total', 'employee_pension_total', 'employer_pension_total', 'other_deduction_total' ) as $column ) {
	$check( strpos( $hr, "'{$column}'" ) !== false, 'Payroll period migration missing ' . $column );
}
foreach ( array( 'taxable_income', 'income_tax_rate', 'employee_pension_rate', 'employer_pension_rate', 'tax_rule_version', 'statutory_status', 'employer_cost_total' ) as $column ) {
	$check( strpos( $hr, "'{$column}'" ) !== false, 'Payroll line migration missing ' . $column );
}
$check( strpos( $hr, 'HR schema migration failed' ) !== false && strpos( $hr, '__columns_metadata_query_failed__' ) !== false, 'HR migration does not fail closed on errors' );
$check( strpos( $rest, 'Stockland_Payroll_Statutory::calculate' ) !== false && strpos( $rest, 'PAYROLL_APPROVE' ) !== false, 'Payroll REST does not use statutory service/audit' );
$check( strpos( $rest, "'2033'" ) !== false && strpos( $rest, "'2030'" ) !== false, 'Payroll journal does not separate employee pension and other deductions' );
$check( 1 === preg_match( '/array\s*\(\s*\'2033\'\s*,\s*\'Other Payroll Deductions Payable\'\s*,\s*\'LIABILITY\'\s*\)/', $acct ), 'Other deduction liability account missing' );
$check( 1 === preg_match( '/array\s*\(\s*\'2030\'\s*,\s*\'Employee Pension Payable\'\s*,\s*\'LIABILITY\'\s*\)/', $acct ), 'Employee pension liability account missing' );
$check( 1 === preg_match( '/strtoupper\s*\(\s*\(string\)\s*\$row\[\'account_type\'\]\s*\)\s*!==\s*\$a\[2\]/', $acct ), 'Payroll GL posting does not verify account types' );
$check( 1 === preg_match( '/\'source_sha256\'\s*,\s*\'source_system\'\s*,\s*\'status\'/ ', $app ) && 1 === preg_match( '/\'source_row_number\'\s*,\s*\'status\'/ ', $app ), 'System Health does not check physical import schema names' );
$check( strpos( $app, "\$checks['payroll_statutory_model'] = false" ) === false, 'Payroll statutory health gate remains hard-coded false' );
$check( strpos( $app, 'payroll_compensation_classification' ) !== false && strpos( $app, "UPPER(COALESCE(e.employment_type,''))<>'RETAINER_SERVICE'" ) !== false, 'HR readiness gate fails to separate service contracts' );
$check( strpos( $js, 'Service contract — outside employee PAYE payroll' ) !== false, 'Retainer service is not clearly identified in employee UI' );
$check( strpos( $js, 'classification_basis_required' ) === false || strpos( $rest, 'classification_basis_required' ) !== false, 'UI/REST statutory classification validation drift' );
if ( $fail ) {
	fwrite( STDERR, 'FAIL HR schema contract QA (' . count( $fail ) . ' failures)' . PHP_EOL );
	foreach ( $fail as $f ) {
		fwrite( STDERR, ' - ' . $f . PHP_EOL );
	}exit( 1 );
}echo "PASS HR schema contract QA: {$pass} assertions\n";
