<?php

use PHPUnit\Framework\TestCase;

final class StocklandMoneyTest extends TestCase {
	public function test_minor_conversion_rounds_half_away_from_zero(): void {
		self::assertSame( 12346, Stockland_Money::minor( '123.455' ) );
		self::assertSame( -12346, Stockland_Money::minor( '-123.455' ) );
	}

	public function test_add_and_subtract_are_decimal_exact(): void {
		self::assertSame( '10.11', Stockland_Money::add( '10.09', '0.02' ) );
		self::assertSame( '9.99', Stockland_Money::subtract( '10.01', '0.02' ) );
	}

	public function test_multiply_and_divide_use_decimal_inputs(): void {
		self::assertSame( '41.50', Stockland_Money::multiply( '2.5', '16.60' ) );
		self::assertSame( '16.60', Stockland_Money::divide( '41.50', '2.5' ) );
	}

	public function test_inclusive_vat_is_exact_to_two_decimals(): void {
		self::assertSame( '15.00', Stockland_Money::inclusive_tax( '115.00', '0.15' ) );
		self::assertSame( '100.00', Stockland_Money::subtract( '115.00', '15.00' ) );
	}

	public function test_quantity_proportion_rounds_exactly(): void {
		self::assertSame( '33.33', Stockland_Money::proportion_of_quantity( '100.00', '1', '3' ) );
		self::assertSame( '66.67', Stockland_Money::proportion_of_quantity( '100.00', '2', '3' ) );
	}

	public function test_invalid_decimal_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		Stockland_Money::minor( '12.3x' );
	}
}
