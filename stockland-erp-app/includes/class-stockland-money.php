<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Exact two-decimal monetary arithmetic without requiring BCMath on shared hosting.
 * Inputs are treated as decimal strings and converted to integer minor units/rationals.
 */
final class Stockland_Money {
    const SCALE = 100;

    private static function fraction( $value ) {
        $s = trim( (string) $value );
        if ( '' === $s || ! preg_match( '/^([+-]?)(\d+)(?:\.(\d+))?$/', $s, $m ) ) {
            throw new InvalidArgumentException( 'Invalid decimal monetary value.' );
        }
        $sign  = '-' === $m[1] ? -1 : 1;
        $whole = (int) $m[2];
        $frac  = isset( $m[3] ) ? rtrim( $m[3], '0' ) : '';
        $scale = $frac === '' ? 0 : strlen( $frac );
        $den   = $scale ? 10 ** $scale : 1;
        $num   = $whole * $den + ( $frac === '' ? 0 : (int) $frac );
        return array( $sign * $num, $den );
    }

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

    public static function minor( $value ) {
        list( $num, $den ) = self::fraction( $value );
        return self::rounded_div( $num * self::SCALE, $den );
    }

    public static function decimal( $minor ) {
        $minor = (int) $minor;
        
        $sign = $minor < 0 ? '-' : '';
        $minor = abs( $minor );
        return $sign . intdiv( $minor, self::SCALE ) . '.' . str_pad( (string) ( $minor % self::SCALE ), 2, '0', STR_PAD_LEFT );
    }

    public static function add( $left, $right ) {
        return self::decimal( self::minor( $left ) + self::minor( $right ) );
    }

    public static function subtract( $left, $right ) {
        return self::decimal( self::minor( $left ) - self::minor( $right ) );
    }

    public static function multiply( $quantity, $unit_price ) {
        list( $q_num, $q_den ) = self::fraction( $quantity );
        $price_minor = self::minor( $unit_price );
        return self::decimal( self::rounded_div( $q_num * $price_minor, $q_den ) );
    }

    public static function divide( $amount, $quantity ) {
        $amount_minor = self::minor( $amount );
        list( $q_num, $q_den ) = self::fraction( $quantity );
        if ( 0 === $q_num ) {
            throw new Exception( 'Cannot divide money by zero.' );
        }
        return self::decimal( self::rounded_div( $amount_minor * $q_den, $q_num ) );
    }

    public static function percentage( $amount, $rate ) {
        $amount_minor = self::minor( $amount );
        list( $rate_num, $rate_den ) = self::fraction( $rate );
        return self::decimal( self::rounded_div( $amount_minor * $rate_num, $rate_den ) );
    }

    public static function inclusive_tax( $gross, $rate ) {
        $gross_minor = self::minor( $gross );
        list( $rate_num, $rate_den ) = self::fraction( $rate );
        if ( $rate_num <= 0 ) {
            return '0.00';
        }
        return self::decimal( self::rounded_div( $gross_minor * $rate_num, $rate_den + $rate_num ) );
    }

    public static function proportion( $amount, $ratio ) {
        return self::percentage( $amount, $ratio );
    }

    public static function proportion_of_quantity( $amount, $part, $whole ) {
        $amount_minor = self::minor( $amount );
        list( $part_num, $part_den ) = self::fraction( $part );
        list( $whole_num, $whole_den ) = self::fraction( $whole );
        if ( 0 === $whole_num ) {
            throw new Exception( 'Cannot calculate a quantity proportion from zero total quantity.' );
        }
        return self::decimal( self::rounded_div( $amount_minor * $part_num * $whole_den, $part_den * $whole_num ) );
    }
}
