<?php
/**
 * Stockland ERP exact monetary arithmetic service.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exact two-decimal monetary arithmetic without requiring BCMath on shared hosting.
 * Inputs are treated as decimal strings and converted to integer minor units/rationals.
 */
final class Stockland_Money {
	const SCALE = 100;

	/**
	 * Convert a decimal value into an exact numerator and denominator.
	 *
	 * @param mixed $value Decimal input.
	 * @return array Pair containing the signed numerator and positive denominator.
	 * @throws InvalidArgumentException When the input is not a decimal number.
	 */
	private static function fraction( $value ) {
		$s = trim( (string) $value );
		if ( '' === $s || ! preg_match( '/^([+-]?)(\d+)(?:\.(\d+))?$/', $s, $m ) ) {
			throw new InvalidArgumentException( 'Invalid decimal monetary value.' );
		}
		$sign  = '-' === $m[1] ? -1 : 1;
		$whole = (int) $m[2];
		$frac  = isset( $m[3] ) ? rtrim( $m[3], '0' ) : '';
		$scale = '' === $frac ? 0 : strlen( $frac );
		$den   = $scale ? 10 ** $scale : 1;
		$num   = $whole * $den + ( '' === $frac ? 0 : (int) $frac );
		return array( $sign * $num, $den );
	}

	/**
	 * Divide integers using half-away-from-zero rounding.
	 *
	 * @param int $num Numerator.
	 * @param int $den Denominator.
	 * @return int Rounded quotient.
	 * @throws Exception When the denominator is zero.
	 */
	private static function rounded_div( $num, $den ) {
		if ( 0 === $den ) {
			throw new Exception( 'Cannot divide by zero in monetary calculation.' );
		}
		$negative = ( $num < 0 ) !== ( $den < 0 );
		$num      = abs( (int) $num );
		$den      = abs( (int) $den );
		$q        = intdiv( $num, $den );
		$r        = $num % $den;
		if ( $r * 2 >= $den ) {
			++$q;
		}
		return $negative ? -$q : $q;
	}

	/**
	 * Convert a decimal money amount to integer minor units.
	 *
	 * @param mixed $value Decimal amount.
	 * @return int Amount in minor units.
	 */
	public static function minor( $value ) {
		list( $num, $den ) = self::fraction( $value );
		return self::rounded_div( $num * self::SCALE, $den );
	}

	/**
	 * Format integer minor units as a two-decimal amount.
	 *
	 * @param int $minor Amount in minor units.
	 * @return string Formatted decimal amount.
	 */
	public static function decimal( $minor ) {
		$minor = (int) $minor;

		$sign  = 0 > $minor ? '-' : '';
		$minor = abs( $minor );
		return $sign . intdiv( $minor, self::SCALE ) . '.' . str_pad( (string) ( $minor % self::SCALE ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Add two decimal money amounts.
	 *
	 * @param mixed $left  Left amount.
	 * @param mixed $right Right amount.
	 * @return string Sum formatted to two decimal places.
	 */
	public static function add( $left, $right ) {
		return self::decimal( self::minor( $left ) + self::minor( $right ) );
	}

	/**
	 * Subtract one decimal money amount from another.
	 *
	 * @param mixed $left  Amount to subtract from.
	 * @param mixed $right Amount to subtract.
	 * @return string Difference formatted to two decimal places.
	 */
	public static function subtract( $left, $right ) {
		return self::decimal( self::minor( $left ) - self::minor( $right ) );
	}

	/**
	 * Multiply a quantity by a unit price using exact integer arithmetic.
	 *
	 * @param mixed $quantity  Quantity.
	 * @param mixed $unit_price Unit price.
	 * @return string Product formatted to two decimal places.
	 */
	public static function multiply( $quantity, $unit_price ) {
		list( $q_num, $q_den ) = self::fraction( $quantity );
		$price_minor           = self::minor( $unit_price );
		return self::decimal( self::rounded_div( $q_num * $price_minor, $q_den ) );
	}

	/**
	 * Divide a money amount by a quantity.
	 *
	 * @param mixed $amount   Amount to divide.
	 * @param mixed $quantity Quantity divisor.
	 * @return string Quotient formatted to two decimal places.
	 * @throws Exception When the quantity is zero.
	 */
	public static function divide( $amount, $quantity ) {
		$amount_minor          = self::minor( $amount );
		list( $q_num, $q_den ) = self::fraction( $quantity );
		if ( 0 === $q_num ) {
			throw new Exception( 'Cannot divide money by zero.' );
		}
		return self::decimal( self::rounded_div( $amount_minor * $q_den, $q_num ) );
	}

	/**
	 * Calculate a percentage amount.
	 *
	 * @param mixed $amount Amount on which to calculate the percentage.
	 * @param mixed $rate   Percentage rate.
	 * @return string Percentage value formatted to two decimal places.
	 */
	public static function percentage( $amount, $rate ) {
		$amount_minor                = self::minor( $amount );
		list( $rate_num, $rate_den ) = self::fraction( $rate );
		return self::decimal( self::rounded_div( $amount_minor * $rate_num, $rate_den ) );
	}

	/**
	 * Extract tax included in a gross amount.
	 *
	 * @param mixed $gross Gross amount including tax.
	 * @param mixed $rate  Tax rate.
	 * @return string Included tax formatted to two decimal places.
	 */
	public static function inclusive_tax( $gross, $rate ) {
		$gross_minor                 = self::minor( $gross );
		list( $rate_num, $rate_den ) = self::fraction( $rate );
		if ( $rate_num <= 0 ) {
			return '0.00';
		}
		return self::decimal( self::rounded_div( $gross_minor * $rate_num, $rate_den + $rate_num ) );
	}

	/**
	 * Calculate a proportional share of a money amount.
	 *
	 * @param mixed $amount Amount to allocate.
	 * @param mixed $ratio  Proportional ratio.
	 * @return string Proportional share formatted to two decimal places.
	 */
	public static function proportion( $amount, $ratio ) {
		return self::percentage( $amount, $ratio );
	}

	/**
	 * Allocate a money amount by a part-to-whole quantity ratio.
	 *
	 * @param mixed $amount Amount to allocate.
	 * @param mixed $part   Quantity represented by this share.
	 * @param mixed $whole  Total quantity.
	 * @return string Allocated amount formatted to two decimal places.
	 * @throws Exception When the total quantity is zero.
	 */
	public static function proportion_of_quantity( $amount, $part, $whole ) {
		$amount_minor                  = self::minor( $amount );
		list( $part_num, $part_den )   = self::fraction( $part );
		list( $whole_num, $whole_den ) = self::fraction( $whole );
		if ( 0 === $whole_num ) {
			throw new Exception( 'Cannot calculate a quantity proportion from zero total quantity.' );
		}
		return self::decimal( self::rounded_div( $amount_minor * $part_num * $whole_den, $part_den * $whole_num ) );
	}
}
