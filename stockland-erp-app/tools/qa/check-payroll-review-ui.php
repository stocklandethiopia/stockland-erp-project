<?php
/**
 * Source-contract checks for the guided payroll review workflow.
 * Static coverage does not replace authenticated payroll posting/restore acceptance.
 */
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}
$root = dirname( __DIR__, 2 );
$read = static function ( $relative ) use ( $root ) {
	return file_get_contents( $root . '/' . $relative );
};
$rest = $read( 'includes/rest/class-rest-hr.php' );
$app = $read( 'assets/app.js' );
$css = $read( 'assets/css/06-operational-guards.css' );
$statutory = $read( 'includes/class-stockland-payroll-statutory.php' );
$tax_qa = $read( 'tools/qa/check-payroll-statutory.php' );
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
$get_start = strpos( $rest, 'public function payroll_get' );
$get_end   = false !== $get_start ? strpos( $rest, 'public function payroll_create', $get_start ) : false;
$get       = false !== $get_start && false !== $get_end ? substr( $rest, $get_start, $get_end - $get_start ) : '';
$line_start = strpos( $rest, 'public function payroll_line_update' );
$line_end   = false !== $line_start ? strpos( $rest, 'private function payroll_detail', $line_start ) : false;
$line_update = false !== $line_start && false !== $line_end ? substr( $rest, $line_start, $line_end - $line_start ) : '';
$check(
	str_contains( $rest, "'/hr/payroll/(?P<id>\\\\d+)'")
		&& str_contains( $rest, "'methods'             => 'GET'")
		&& str_contains( $rest, "'callback'            => array( $this, 'payroll_get' )")
		&& str_contains( $rest, 'Stockland_Authority::can_view_payroll()'),
	'Payroll detail GET endpoint is registered behind existing payroll-view authority.'
);
$check(
	'' !== $get
		&& str_contains( $get, '$this->payroll_detail( (int) $r[\'id\'] )')
		&& str_contains( $get, "new WP_Error( 'not_found'")
		&& ! preg_match( '/(?:INSERT INTO|UPDATE\\s+|DELETE FROM|START TRANSACTION|COMMIT|ROLLBACK)/i', $get ),
	'Payroll detail endpoint is a bounded read-only lookup and returns 404 for a missing period.'
);
$check(
	str_contains( $app, 'function openPayrollReview(id)')
		&& str_contains( $app, "api16('/hr/payroll/'+encodeURIComponent(id))")
		&& str_contains( $app, 'function renderPayrollReview(period)')
		&& str_contains( $app, 'function renderPayrollTable(d,c)')
		&& str_contains( $app, "document.querySelectorAll('[data-pay-review]')"),
	'Payroll list opens the read-only detail panel using the GET endpoint instead of a direct approval click.'
);
$check(
	str_contains( $app, "const canEdit=status==='DRAFT'&&!!caps.payroll_manage;")
		&& str_contains( $app, "const canApprove=status==='SUBMITTED'&&!!caps.payroll_approve;")
		&& str_contains( $app, "const canPay=status==='APPROVED'&&!!caps.payroll_approve;")
		&& str_contains( $app, "data-payroll-line-form")
		&& str_contains( $app, "'/hr/payroll/'+encodeURIComponent(form.dataset.periodId)+'/line/'+encodeURIComponent(form.dataset.lineId)"),
	'Only Management can edit DRAFT lines; Owner approval/payment actions are status-gated in the review panel.'
);
$check(
	str_contains( $line_update, "if ( 'DRAFT' !== $period['status'] )")
		&& str_contains( $line_update, "throw" ) === false
		&& str_contains( $line_update, 'Approved or paid payroll cannot be edited.')
		&& str_contains( $line_update, '$verified_exempt_total')
		&& str_contains( $line_update, '$preserved_exempt_components'),
	'Draft line recalculation stays locked after submission and preserves previously verified exemption evidence.'
);
$check(
	str_contains( $app, 'slPayrollCurrentMonthDefaults(pf)')
		&& str_contains( $app, 'slPayrollCurrentMonthDefaults(form,true)')
		&& str_contains( $app, "value=\''+esc(periodKey)+'\'")
		&& str_contains( $app, 'CREATE DRAFT PAYROLL')
		&& str_contains( $app, '<details class="sl-payroll-setup">'),
	'Payroll period form defaults to the current full calendar month and keeps setup forms collapsed until opened.'
);
$check(
	str_contains( $app, 'RETAINER_SERVICE')
		&& str_contains( $app, 'service contract(s) were excluded from employee PAYE payroll')
		&& str_contains( $app, 'Historical payroll')
		&& str_contains( $css, '.sl-payroll-review')
		&& str_contains( $css, '@media(max-width:760px)')
		&& str_contains( $css, '.sl-payroll-setup-grid'),
	'Payroll simplification keeps service-contract warnings, historical record policy and responsive review styling.'
);
$check(
	str_contains( $statutory, 'ET-PAYE-1395-2025-v3-PENSION-BASE')
		&& str_contains( $statutory, 'Stockland_Money::subtract( $taxable_gross, $employee_pension )')
		&& str_contains( $tax_qa, "'7715.00'")
		&& str_contains( $tax_qa, "'20185.00'"),
	'PAYE/pension formula and agreed ETB 30,000 regression remain unchanged.'
);
if ( $fail ) {
	fwrite( STDERR, 'Payroll review QA FAILED: ' . implode( '; ', $fail ) . "\n" );
	exit( 1 );
}
echo "Payroll review QA PASS: {$pass} assertions.\n";
