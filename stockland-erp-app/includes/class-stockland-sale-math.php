<?php
/**
 * Authoritative POS and Daily POS price/VAT calculations.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single authoritative VAT/price calculation used by both manual POS and
 * Daily POS XLSX import. A transaction may either provide a VAT-inclusive
 * unit price or a VAT-exclusive unit price; the central VAT rate is always
 * supplied by the ERP, never by the browser/import file as an authority.
 */
final class Stockland_Sale_Math {

	/**
	 * Normalizes the VAT-inclusive or VAT-exclusive mode.
	 *
	 * @param mixed  $raw_mode Requested mode from a transaction.
	 * @param string $vat_basis Configured fallback VAT basis.
	 * @return string Canonical VAT mode.
	 */
	public static function normalize_mode( $raw_mode, $vat_basis = 'INCLUSIVE' ) {
		$mode = strtoupper( trim( (string) $raw_mode ) );
		if ( in_array( $mode, array( 'VAT_INCLUDED', 'INCLUSIVE' ), true ) ) {
			return 'VAT_INCLUDED';
		}
		if ( in_array( $mode, array( 'WITHOUT_VAT', 'EXCLUSIVE' ), true ) ) {
			return 'WITHOUT_VAT';
		}
		return strtoupper( (string) $vat_basis ) === 'EXCLUSIVE' ? 'WITHOUT_VAT' : 'VAT_INCLUDED';
	}

	/**
	 * Calculates a sales line using exact ERP money arithmetic.
	 *
	 * @param mixed  $quantity Quantity sold.
	 * @param mixed  $unit_price Unit price in ETB.
	 * @param string $vat_mode VAT-inclusive or VAT-exclusive mode.
	 * @param mixed  $vat_rate Central VAT rate as a decimal fraction.
	 * @return array<string, string> Normalized totals and VAT breakdown.
	 * @throws InvalidArgumentException When quantity, price or VAT rate is invalid.
	 */
	public static function calculate( $quantity, $unit_price, $vat_mode, $vat_rate ) {
		$quantity = (float) $quantity;
		if ( $quantity <= 0 ) {
			throw new InvalidArgumentException( 'Quantity must be greater than zero.' );
		}

		$unit_price = Stockland_Money::decimal( Stockland_Money::minor( $unit_price ) );
		if ( Stockland_Money::minor( $unit_price ) <= 0 ) {
			throw new InvalidArgumentException( 'Selling price must be greater than zero.' );
		}

		$vat_rate = (float) $vat_rate;
		if ( $vat_rate < 0 || $vat_rate > 1 ) {
			throw new InvalidArgumentException( 'VAT rate is outside the supported 0–100% range.' );
		}

		$mode       = self::normalize_mode( $vat_mode, 'INCLUSIVE' );
		$line_price = Stockland_Money::multiply( $quantity, $unit_price );

		if ( 'VAT_INCLUDED' === $mode ) {
			$sales_total = $line_price;
			$vat_amount  = Stockland_Money::inclusive_tax( $sales_total, $vat_rate );
			$net_sales   = Stockland_Money::subtract( $sales_total, $vat_amount );
		} else {
			$net_sales   = $line_price;
			$vat_amount  = Stockland_Money::percentage( $net_sales, $vat_rate );
			$sales_total = Stockland_Money::add( $net_sales, $vat_amount );
		}

		return array(
			'vat_mode'            => $mode,
			'unit_price'          => $unit_price,
			'line_price_excl_vat' => $net_sales,
			'sales_total'         => $sales_total,
			'vat_amount'          => $vat_amount,
			'net_sales'           => $net_sales,
		);
	}

	/**
	 * Compares two monetary values at the ERP's minor-unit precision.
	 *
	 * @param mixed $left First monetary amount.
	 * @param mixed $right Second monetary amount.
	 * @return bool Whether both values have the same minor-unit amount.
	 */
	public static function same_money( $left, $right ) {
		return Stockland_Money::minor( $left ) === Stockland_Money::minor( $right );
	}
}
