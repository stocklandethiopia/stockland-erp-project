<?php
/**
 * Statutory Ethiopian employment-income tax and private-pension calculations.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Versioned Ethiopian monthly employment-income withholding and private-pension
 * arithmetic. This service does not determine whether an allowance is legally
 * exempt; an authorized owner must classify components and record the basis.
 *
 * Tax source: Ethiopia Income Tax (Amendment) Proclamation No. 1395/2025,
 * amended employment-income monthly brackets (Article 11). Pension rates use
 * the private-organization pension contribution framework (employee 7%, employer
 * 11%). Rules must be reviewed by the employer's Ethiopian payroll adviser when
 * legislation/directives change. Historical payroll rows are never recalculated.
 */
final class Stockland_Payroll_Statutory {
	const RULE_VERSION          = 'ET-PAYE-1395-2025-v3-pension-deducted-tax-base';
	const EMPLOYEE_PENSION_RATE = '0.07';
	const EMPLOYER_PENSION_RATE = '0.11';
	// Proclamation No. 1395/2025, Effective Date clause 4(3), applies the other
	// amendments to income derived as of 8 July 2025. The Gazette publication date
	// (1 September 2025) is not the statutory application date.
	const LAW_EFFECTIVE_DATE = '2025-07-08';
	// July 2025 crosses the 8 July statutory boundary. This monthly calculator does
	// not silently prorate or rewrite posted July payroll: hold that month for a
	// separately approved split-period calculation. August is the first full month.
	const EFFECTIVE_DATE = '2025-08-01';

	/**
	 * Server-authoritative component catalog for the compensation editor.
	 * Cash/benefit types are taxable by default. The current amendment repeals exemptions
	 * granted outside its permitted framework, so the UI must not auto-exempt a category
	 * merely because it is called transport, meal, medical, hardship or reimbursement.
	 * Owner-confirmed exceptions require a clause/reference and supporting evidence.
	 */
	public static function allowance_catalog() {
		return array(
			'GENERAL_CASH'  => array(
				'label'             => 'General cash allowance',
				'default_treatment' => 'TAXABLE',
				'description'       => 'Taxable by default under the employment-income schedule.',
			),
			'HOUSING'       => array(
				'label'             => 'Housing / rent allowance',
				'default_treatment' => 'TAXABLE',
				'description'       => 'Taxable by default; require current legal authority for any exception.',
			),
			'TRANSPORT'     => array(
				'label'             => 'Transport allowance',
				'default_treatment' => 'TAXABLE',
				'description'       => 'Taxable by default; do not use old caps unless verified under current rules.',
			),
			'MEAL'          => array(
				'label'             => 'Meal / subsistence allowance',
				'default_treatment' => 'TAXABLE',
				'description'       => 'Taxable by default unless an applicable current provision is documented.',
			),
			'OVERTIME'      => array(
				'label'             => 'Overtime pay',
				'default_treatment' => 'TAXABLE',
				'description'       => 'Include in employment taxable income.',
			),
			'BONUS'         => array(
				'label'             => 'Bonus / incentive',
				'default_treatment' => 'TAXABLE',
				'description'       => 'Include in employment taxable income.',
			),
			'COMMISSION'    => array(
				'label'             => 'Commission',
				'default_treatment' => 'TAXABLE',
				'description'       => 'Include in employment taxable income.',
			),
			'PER_DIEM'      => array(
				'label'             => 'Duty travel / per diem',
				'default_treatment' => 'TAXABLE',
				'description'       => 'Retain trip dates, policy and receipts; tax exemption is not presumed.',
			),
			'REIMBURSEMENT' => array(
				'label'             => 'Expense reimbursement',
				'default_treatment' => 'TAXABLE',
				'description'       => 'Only reclassify when it meets a currently applicable legal test and the evidence is retained.',
			),
			'OTHER'         => array(
				'label'             => 'Other allowance / benefit',
				'default_treatment' => 'TAXABLE',
				'description'       => 'Taxable by default; describe and reference the applicable law if claiming an exemption.',
			),
		);
	}

	/** Monthly brackets and credits are exposed to the front end from this authoritative source. */
	public static function monthly_tax_bands() {
		return array(
			array(
				'up_to'  => '2000.00',
				'rate'   => '0.00',
				'credit' => '0.00',
				'label'  => 'ETB 0–2,000',
			),
			array(
				'up_to'  => '4000.00',
				'rate'   => '0.15',
				'credit' => '300.00',
				'label'  => 'ETB 2,001–4,000',
			),
			array(
				'up_to'  => '7000.00',
				'rate'   => '0.20',
				'credit' => '500.00',
				'label'  => 'ETB 4,001–7,000',
			),
			array(
				'up_to'  => '10000.00',
				'rate'   => '0.25',
				'credit' => '850.00',
				'label'  => 'ETB 7,001–10,000',
			),
			array(
				'up_to'  => '14000.00',
				'rate'   => '0.30',
				'credit' => '1350.00',
				'label'  => 'ETB 10,001–14,000',
			),
			array(
				'up_to'  => null,
				'rate'   => '0.35',
				'credit' => '2050.00',
				'label'  => 'Above ETB 14,000',
			),
		);
	}

	/**
	 * Calculates one monthly payroll line without floating-point money arithmetic.
	 *
	 * Allowance tax treatment must be verified and approved separately; this method
	 * only calculates amounts after the authoritative classifications are supplied.
	 *
	 * @param mixed $base_salary Base salary amount in ETB.
	 * @param mixed $taxable_allowance Total taxable allowance amount in ETB.
	 * @param mixed $exempt_allowance Owner-classified exempt allowance amount in ETB.
	 * @param mixed $pensionable_salary Salary amount subject to pension contribution.
	 * @param mixed $other_deduction Other authorized deduction amount in ETB.
	 * @return array<string, string> Calculated payroll amounts and rule metadata.
	 * @throws InvalidArgumentException When amounts are invalid or deductions exceed gross pay.
	 */
	public static function calculate( $base_salary, $taxable_allowance, $exempt_allowance, $pensionable_salary, $other_deduction ) {
		$base          = self::amount( $base_salary, 'Base salary' );
		$taxable_allow = self::amount( $taxable_allowance, 'Taxable allowance' );
		$exempt_allow  = self::amount( $exempt_allowance, 'Exempt allowance' );
		$pensionable   = self::amount( $pensionable_salary, 'Pensionable salary' );
		$other         = self::amount( $other_deduction, 'Other deduction' );

		$allowance_total = Stockland_Money::add( $taxable_allow, $exempt_allow );
		$gross           = Stockland_Money::add( Stockland_Money::add( $base, $taxable_allow ), $exempt_allow );
		if ( Stockland_Money::minor( $pensionable ) > Stockland_Money::minor( $gross ) ) {
			throw new InvalidArgumentException( 'Pensionable salary cannot exceed gross pay.' );
		}

		$income_before_pension = Stockland_Money::add( $base, $taxable_allow );
		$employee_pension      = Stockland_Money::percentage( $pensionable, self::EMPLOYEE_PENSION_RATE );
		$employer_pension      = Stockland_Money::percentage( $pensionable, self::EMPLOYER_PENSION_RATE );

		// Follow the established Stockland payroll SOP: deduct the employee pension
		// before calculating PAYE. Preview and server-side payroll use the same base.
		$taxable_income = Stockland_Money::subtract( $income_before_pension, $employee_pension );
		if ( Stockland_Money::minor( $taxable_income ) < 0 ) {
			$taxable_income = '0.00';
		}
		list( $income_tax, $income_tax_rate ) = self::income_tax( $taxable_income );
		$deduction_total                      = Stockland_Money::add( Stockland_Money::add( $income_tax, $employee_pension ), $other );
		$net                                  = Stockland_Money::subtract( $gross, $deduction_total );
		if ( Stockland_Money::minor( $net ) < 0 ) {
			throw new InvalidArgumentException( 'Statutory and other deductions exceed gross pay.' );
		}

		return array(
			'base_salary'             => $base,
			'taxable_allowance_total' => $taxable_allow,
			'exempt_allowance_total'  => $exempt_allow,
			'allowance_total'         => $allowance_total,
			'other_deduction_total'   => $other,
			'taxable_income'          => $taxable_income,
			'income_tax'              => $income_tax,
			'income_tax_rate'         => $income_tax_rate,
			'pensionable_salary'      => $pensionable,
			'employee_pension_rate'   => self::EMPLOYEE_PENSION_RATE,
			'employer_pension_rate'   => self::EMPLOYER_PENSION_RATE,
			'employee_pension'        => $employee_pension,
			'employer_pension'        => $employer_pension,
			'gross_pay'               => $gross,
			'deduction_total'         => $deduction_total,
			'net_pay'                 => $net,
			'employer_cost_total'     => Stockland_Money::add( $gross, $employer_pension ),
			'tax_rule_version'        => self::RULE_VERSION,
			'statutory_status'        => 'CALCULATED',
		);
	}

	/**
	 * Calculates PAYE using the published monthly brackets and credits.
	 *
	 * The bracket credits keep adjacent rates continuous. This method must only
	 * be used for a payroll period covered by this rule version.
	 *
	 * @param mixed $taxable_income Monthly taxable employment income in ETB.
	 * @return array{0: string, 1: string} The tax amount and applied percentage.
	 * @throws InvalidArgumentException When taxable income is invalid or negative.
	 */
	public static function income_tax( $taxable_income ) {
		$income = self::amount( $taxable_income, 'Taxable income' );
		$minor  = Stockland_Money::minor( $income );
		if ( $minor <= 200000 ) {
			return array( '0.00', '0.00' ); }
		if ( $minor <= 400000 ) {
			$rate   = '0.15';
			$credit = '300.00';
			$pct    = '15.00'; } elseif ( $minor <= 700000 ) {
			$rate   = '0.20';
			$credit = '500.00';
			$pct    = '20.00'; } elseif ( $minor <= 1000000 ) {
				$rate   = '0.25';
				$credit = '850.00';
				$pct    = '25.00'; } elseif ( $minor <= 1400000 ) {
				$rate   = '0.30';
				$credit = '1350.00';
				$pct    = '30.00'; } else {
					$rate   = '0.35';
					$credit = '2050.00';
					$pct    = '35.00'; }
				$tax = Stockland_Money::subtract( Stockland_Money::percentage( $income, $rate ), $credit );
				if ( Stockland_Money::minor( $tax ) < 0 ) {
					$tax = '0.00'; }
				return array( $tax, $pct );
	}

	/**
	 * Normalizes a non-negative monetary amount.
	 *
	 * @param mixed  $value Amount to normalize.
	 * @param string $label Human-readable amount label for validation errors.
	 * @return string Normalized two-decimal ETB amount.
	 * @throws InvalidArgumentException When the amount is negative.
	 */
	private static function amount( $value, $label ) {
		$amount = Stockland_Money::decimal( Stockland_Money::minor( $value ) );
		if ( Stockland_Money::minor( $amount ) < 0 ) {
			throw new InvalidArgumentException( esc_html( $label ) . ' cannot be negative.' );
		}
		return $amount;
	}
}
