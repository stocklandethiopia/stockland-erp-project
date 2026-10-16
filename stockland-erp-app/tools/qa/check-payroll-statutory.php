<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
require_once ABSPATH . 'includes/class-stockland-money.php';
require_once ABSPATH . 'includes/class-stockland-payroll-statutory.php';
$cases = array(
	'0.00'     => '0.00',
	'2000.00'  => '0.00',
	'2000.01'  => '0.00',
	'2001.00'  => '0.15',
	'4000.00'  => '300.00',
	'4001.00'  => '300.20',
	'7000.00'  => '900.00',
	'7001.00'  => '900.25',
	'10000.00' => '1650.00',
	'10001.00' => '1650.30',
	'14000.00' => '2850.00',
	'14001.00' => '2850.35',
	'15000.00' => '3200.00',
	'21000.00' => '5300.00',
);
$fail  = array();
$pass  = 0;
foreach ( $cases as $income => $expected ) {
	try {
		$actual = Stockland_Payroll_Statutory::income_tax( $income )[0];
		if ( $actual !== $expected ) {
			$fail[] = "Tax {$income}: expected {$expected}, got {$actual}";
		} else {
			++$pass;
		}
	} catch ( Throwable $e ) {
		$fail[] = "Tax {$income} threw: " . $e->getMessage();}
}
try {
	$r    = Stockland_Payroll_Statutory::calculate( '20000.00', '1000.00', '500.00', '20000.00', '200.00' );
	$want = array(
		'gross_pay'           => '21500.00',
		'taxable_income'      => '19600.00',
		'income_tax'          => '4810.00',
		'employee_pension'    => '1400.00',
		'employer_pension'    => '2200.00',
		'deduction_total'     => '6410.00',
		'net_pay'             => '15090.00',
		'employer_cost_total' => '23700.00',
		'tax_rule_version'    => 'ET-PAYE-1395-2025-v3-PENSION-BASE',
		'tax_base_basis'      => 'TAXABLE_GROSS_LESS_EMPLOYEE_PENSION',
		'statutory_status'    => 'CALCULATED',
	);
	foreach ( $want as $k => $v ) {
		if ( ( $r[ $k ] ?? null ) !== $v ) {
			$fail[] = "Full payroll {$k}: expected {$v}, got " . var_export( $r[ $k ] ?? null, true );
		} else {
			++$pass;
		}
	}
} catch ( Throwable $e ) {
	$fail[] = 'Valid payroll calculation threw: ' . $e->getMessage();}

// Regression from the approved Stockland payroll example:
// ETB 30,000 base/pensionable salary -> 7% pension = 2,100,
// PAYE base = 27,900, PAYE = 7,715, net pay = 20,185.
try {
	$r = Stockland_Payroll_Statutory::calculate( '30000.00', '0.00', '0.00', '30000.00', '0.00' );
	$want = array(
		'gross_pay'        => '30000.00',
		'taxable_income'   => '27900.00',
		'income_tax'       => '7715.00',
		'employee_pension' => '2100.00',
		'employer_pension' => '3300.00',
		'net_pay'          => '20185.00',
	);
	foreach ( $want as $key => $value ) {
		if ( ( $r[ $key ] ?? null ) !== $value ) {
			$fail[] = "ETB 30000 payroll {$key}: expected {$value}, got " . var_export( $r[ $key ] ?? null, true );
		} else {
			++$pass;
		}
	}
} catch ( Throwable $e ) {
	$fail[] = 'ETB 30000 payroll regression threw: ' . $e->getMessage();
}
foreach ( array(
	array( '-1', '0', '0', '0', '0', 'negative base accepted' ),
	array( '1000', '-1', '0', '1000', '0', 'negative allowance accepted' ),
	array( '1000', '0', '0', '1000.01', '0', 'pensionable salary greater than gross accepted' ),
	array( '2000', '0', '0', '2000', '5000', 'deductions greater than gross accepted' ),
) as $c ) {
	try {
		Stockland_Payroll_Statutory::calculate( $c[0], $c[1], $c[2], $c[3], $c[4] );
		$fail[] = $c[5];
	} catch ( Throwable $e ) {
		++$pass;}
}
if ( Stockland_Payroll_Statutory::EMPLOYEE_PENSION_RATE !== '0.07' || Stockland_Payroll_Statutory::EMPLOYER_PENSION_RATE !== '0.11' ) {
	$fail[] = 'Pension-rate version contract mismatch';
} else {
	++$pass;
}
if ( Stockland_Payroll_Statutory::LAW_EFFECTIVE_DATE !== '2025-07-08' || Stockland_Payroll_Statutory::EFFECTIVE_DATE !== '2025-08-01' ) {
	$fail[] = 'Proclamation effective date / first-full-month guard mismatch';
} else {
	++$pass;
}
if ( $fail ) {
	fwrite( STDERR, 'FAIL statutory payroll QA (' . count( $fail ) . " failures)\n" );
	foreach ( $fail as $f ) {
		fwrite( STDERR, " - {$f}\n" );
	}exit( 1 );}
echo "PASS statutory payroll QA: {$pass} assertions\n";
