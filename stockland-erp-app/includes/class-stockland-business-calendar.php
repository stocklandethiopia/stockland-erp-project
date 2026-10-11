<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Business calendar stored as a versioned JSON policy in the existing settings
 * table so the ERP keeps its 49-table contract. The calendar is deliberately
 * fail-closed for malformed saved state. A missing/blank setting defaults to
 * every date being open until Management explicitly defines weekend/holiday rules.
 */
final class Stockland_Business_Calendar {
	const SETTING_KEY = 'business_calendar_v1';
	const VERSION     = 1;

	public static function default_config() {
		return array(
			'version'        => self::VERSION,
			'weekend_days'   => array(),
			'holidays'       => array(),
			'open_overrides' => array(),
		);
	}

	public static function config() {
		$raw = trim( (string) Stockland_DB::get_setting( self::SETTING_KEY, '' ) );
		if ( '' === $raw ) {
			return self::default_config();
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'Business Calendar configuration is invalid. Management must repair it before transactions can be posted.' );
		}
		return self::normalize_config( $data );
	}

	public static function normalize_config( $data ) {
		$base = self::default_config();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$weekends = array();
		foreach ( (array) ( $data['weekend_days'] ?? array() ) as $day ) {
			$day = (int) $day;
			if ( $day >= 0 && $day <= 6 ) {
				$weekends[ $day ] = true;
			}
		}
		$base['weekend_days'] = array_map( 'intval', array_keys( $weekends ) );
		sort( $base['weekend_days'], SORT_NUMERIC );

		foreach ( array( 'holidays', 'open_overrides' ) as $key ) {
			$base[ $key ] = array();
			foreach ( (array) ( $data[ $key ] ?? array() ) as $date => $label ) {
				if ( ! self::valid_date( $date ) ) {
					continue;
				}
				$label                 = sanitize_text_field( (string) $label );
				$base[ $key ][ $date ] = '' !== $label ? $label : ( 'holidays' === $key ? 'Closed' : 'Open' );
			}
			ksort( $base[ $key ], SORT_STRING );
		}
		return $base;
	}

	public static function valid_date( $date ) {
		$date = (string) $date;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		return $dt && $dt->format( 'Y-m-d' ) === $date;
	}

	public static function status( $date ) {
		if ( ! self::valid_date( $date ) ) {
			throw new InvalidArgumentException( 'Business date must be YYYY-MM-DD.' );
		}
		$cfg = self::config();
		if ( array_key_exists( $date, $cfg['open_overrides'] ) ) {
			return array(
				'status' => 'OPEN',
				'label'  => $cfg['open_overrides'][ $date ],
				'reason' => 'Open override',
			);
		}
		if ( array_key_exists( $date, $cfg['holidays'] ) ) {
			return array(
				'status' => 'CLOSED',
				'label'  => $cfg['holidays'][ $date ],
				'reason' => 'Holiday',
			);
		}
		$dt  = new DateTimeImmutable( $date );
		$dow = (int) $dt->format( 'w' );
		if ( in_array( $dow, $cfg['weekend_days'], true ) ) {
			return array(
				'status' => 'CLOSED',
				'label'  => 'Weekend',
				'reason' => 'Weekend rule',
			);
		}
		return array(
			'status' => 'OPEN',
			'label'  => 'Business day',
			'reason' => 'Default open',
		);
	}

	public static function is_business_day( $date ) {
		return 'OPEN' === self::status( $date )['status'];
	}

	public static function assert_business_day( $date ) {
		$status = self::status( $date );
		if ( 'CLOSED' === $status['status'] ) {
			throw new Exception( 'The selected business date is closed by the Business Calendar: ' . $status['label'] . '.' );
		}
		return $status;
	}

	public static function month( $month ) {
		$month = (string) $month;
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			throw new InvalidArgumentException( 'Month must be YYYY-MM.' );
		}
		$first = DateTimeImmutable::createFromFormat( '!Y-m-d', $month . '-01' );
		if ( ! $first || $first->format( 'Y-m' ) !== $month ) {
			throw new InvalidArgumentException( 'Invalid calendar month.' );
		}
		$days = (int) $first->format( 't' );
		$out  = array();
		for ( $i = 1; $i <= $days; $i++ ) {
			$date  = $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'm' ), $i )->format( 'Y-m-d' );
			$s     = self::status( $date );
			$out[] = array_merge( array( 'date' => $date ), $s );
		}
		return array(
			'month'  => $month,
			'days'   => $out,
			'config' => self::config(),
		);
	}

	public static function save_exception( $date, $status, $label, $user_id ) {
		if ( ! self::valid_date( $date ) ) {
			return new WP_Error( 'invalid_date', 'Calendar date must be YYYY-MM-DD.', array( 'status' => 400 ) );
		}
		$status = strtoupper( sanitize_key( (string) $status ) );
		if ( ! in_array( $status, array( 'OPEN', 'CLOSED', 'RESET' ), true ) ) {
			return new WP_Error( 'invalid_calendar_status', 'Calendar status must be OPEN, CLOSED or RESET.', array( 'status' => 400 ) );
		}
		$cfg   = self::config();
		$label = sanitize_text_field( (string) $label );
		unset( $cfg['holidays'][ $date ], $cfg['open_overrides'][ $date ] );
		if ( 'CLOSED' === $status ) {
			$cfg['holidays'][ $date ] = '' !== $label ? $label : 'Closed by Management';
		}
		if ( 'OPEN' === $status ) {
			$cfg['open_overrides'][ $date ] = '' !== $label ? $label : 'Open override';
		}
		return Stockland_Settings::update( self::SETTING_KEY, wp_json_encode( self::normalize_config( $cfg ) ), $user_id );
	}

	public static function save_weekends( $days, $user_id ) {
		$cfg                 = self::config();
		$cfg['weekend_days'] = array_values( array_unique( array_map( 'intval', (array) $days ) ) );
		foreach ( $cfg['weekend_days'] as $day ) {
			if ( $day < 0 || $day > 6 ) {
				return new WP_Error( 'invalid_weekend_day', 'Weekend day must be an integer from 0 (Sunday) to 6 (Saturday).', array( 'status' => 400 ) );
			}
		}
		sort( $cfg['weekend_days'], SORT_NUMERIC );
		return Stockland_Settings::update( self::SETTING_KEY, wp_json_encode( self::normalize_config( $cfg ) ), $user_id );
	}
}
