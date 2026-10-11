<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append-only, concurrency-safe audit log.
 *
 * v1 rows are legacy rows written by the pre-10.5 hashing implementation.
 * v2 rows use a canonical payload so MySQL DECIMAL values verify exactly as
 * they were stored. Historical rows are NEVER rewritten or deleted.
 */
class Stockland_Audit {

	const HASH_VERSION = 2;

	public static function log(
		$user_id,
		$branch_location_id,
		$action,
		$sku = null,
		$quantity = null,
		$old_value = null,
		$new_value = null,
		$reason = null,
		$reference = null,
		$approver_id = null
	) {
		global $wpdb;
		$table     = Stockland_DB::table( 'audit_logs' );
		$lock_name = 'stockland_audit_chain';
		$locked    = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,10)', $lock_name ) );
		if ( '1' !== (string) $locked ) {
			return false;
		}
		try {
			$previous         = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT event_hash FROM %i ORDER BY id DESC LIMIT 1', $table ) );
			$stamp            = current_time( 'mysql' );
			$action_stored    = self::stored_action( $action );
			$sku_stored       = self::stored_text( $sku );
			$quantity_stored  = null !== $quantity ? number_format( (float) $quantity, 2, '.', '' ) : null;
			$old_stored       = self::stored_text( $old_value );
			$new_stored       = self::stored_text( $new_value );
			$reason_stored    = self::stored_text( $reason );
			$reference_stored = self::stored_text( $reference );
			$payload          = self::payload(
				$user_id,
				$branch_location_id,
				$action_stored,
				$sku_stored,
				$quantity_stored,
				$old_stored,
				$new_stored,
				$reason_stored,
				$reference_stored,
				$approver_id,
				$stamp,
				$previous,
				self::HASH_VERSION
			);
			$event_hash       = hash( 'sha256', self::HASH_VERSION . '|' . $previous . '|' . $payload );
			$ok               = $wpdb->insert(
				$table,
				array(
					'user_id'            => (int) $user_id,
					'branch_location_id' => null !== $branch_location_id ? (int) $branch_location_id : null,
					'action_time'        => $stamp,
					'action'             => $action_stored,
					'sku'                => $sku_stored,
					'quantity'           => $quantity_stored,
					'old_value'          => $old_stored,
					'new_value'          => $new_stored,
					'reason'             => $reason_stored,
					'reference'          => $reference_stored,
					'approver_id'        => null !== $approver_id ? (int) $approver_id : null,
					'previous_hash'      => $previous ? $previous : null,
					'event_hash'         => $event_hash,
					'hash_version'       => self::HASH_VERSION,
				)
			);
			return false !== $ok;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	private static function stored_text( $value ) {
		if ( null === $value ) {
			return null;
		}
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		return wp_json_encode( $value );
	}

	private static function stored_action( $action ) {
		return sanitize_text_field( (string) $action );
	}

	private static function payload(
		$user_id,
		$branch_location_id,
		$action,
		$sku,
		$quantity,
		$old_value,
		$new_value,
		$reason,
		$reference,
		$approver_id,
		$stamp,
		$previous,
		$version
	) {
		return wp_json_encode(
			array(
				'user_id'            => (int) $user_id,
				'branch_location_id' => null !== $branch_location_id ? (int) $branch_location_id : null,
				// Canonical payload mirrors the actual VARCHAR/DECIMAL storage representation.
				'action'             => self::stored_action( $action ),
				'sku'                => self::stored_text( $sku ),
				'quantity'           => null !== $quantity ? number_format( (float) $quantity, 2, '.', '' ) : null,
				'old_value'          => self::stored_text( $old_value ),
				'new_value'          => self::stored_text( $new_value ),
				'reason'             => self::stored_text( $reason ),
				'reference'          => self::stored_text( $reference ),
				'approver_id'        => null !== $approver_id ? (int) $approver_id : null,
				'action_time'        => (string) $stamp,
				'previous_hash'      => (string) $previous,
				'hash_version'       => (int) $version,
			)
		);
	}

	private static function numeric_candidate( $value ) {
		$s = trim( (string) $value );
		if ( '' === $s || ! preg_match( '/^-?(?:\d+|\d*\.\d+)(?:[eE][+-]?\d+)?$/', $s ) ) {
			return null;
		}
		return (float) $s;
	}

	private static function historical_v2_matches( $row, $previous ) {
		// Some early v2 events were hashed before VARCHAR values such as
		// reference/old_value/new_value were normalized to their database string
		// representation. Verify those historical rows without rewriting them.
		$fields   = array( 'old_value', 'new_value', 'reference', 'quantity' );
		$variants = array();
		foreach ( $fields as $field ) {
			$raw    = $row[ $field ] ?? null;
			$values = array( $raw );
			if ( null !== $raw && '' !== $raw ) {
				$n = self::numeric_candidate( $raw );
				if ( null !== $n ) {
					$values[] = $n;
				}
			}
			$uniq = array();
			foreach ( $values as $v ) {
				$key          = is_scalar( $v ) || null === $v ? gettype( $v ) . '|' . (string) $v : wp_json_encode( $v );
				$uniq[ $key ] = $v;
			}
			$variants[ $field ] = array_values( $uniq );
		}
		foreach ( $variants['old_value'] as $old ) {
			foreach ( $variants['new_value'] as $new ) {
				foreach ( $variants['reference'] as $reference ) {
					foreach ( $variants['quantity'] as $quantity ) {
						$payload = wp_json_encode(
							array(
								'user_id'            => (int) $row['user_id'],
								'branch_location_id' => null !== $row['branch_location_id'] ? (int) $row['branch_location_id'] : null,
								'action'             => (string) $row['action'],
								'sku'                => null !== $row['sku'] ? (string) $row['sku'] : null,
								'quantity'           => null !== $quantity && '' !== $quantity && is_numeric( $quantity ) ? $quantity : $quantity,
								'old_value'          => $old,
								'new_value'          => $new,
								'reason'             => null !== $row['reason'] ? (string) $row['reason'] : null,
								'reference'          => $reference,
								'approver_id'        => null !== $row['approver_id'] ? (int) $row['approver_id'] : null,
								'action_time'        => (string) $row['action_time'],
								'previous_hash'      => (string) $previous,
								'hash_version'       => self::HASH_VERSION,
							)
						);
						if ( hash_equals( (string) $row['event_hash'], hash( 'sha256', self::HASH_VERSION . '|' . (string) $previous . '|' . $payload ) ) ) {
							return true;
						}
					}
				}
			}
		}
		return false;
	}

	private static function legacy_matches( $row, $previous ) {
		// Legacy rows hashed request-time values. The common legacy failure was
		// DECIMAL quantity changing from numeric 5 to DB string "5.00". Try the
		// exact stored representation and the numeric representation, without
		// modifying the historical row.
		$base       = array(
			'user_id'            => (int) $row['user_id'],
			'branch_location_id' => null !== $row['branch_location_id'] ? (int) $row['branch_location_id'] : null,
			'action'             => (string) $row['action'],
			'sku'                => $row['sku'],
			'old_value'          => $row['old_value'],
			'new_value'          => $row['new_value'],
			'reason'             => $row['reason'],
			'reference'          => $row['reference'],
			'approver_id'        => null !== $row['approver_id'] ? (int) $row['approver_id'] : null,
			'action_time'        => $row['action_time'],
			'previous_hash'      => (string) $row['previous_hash'],
		);
		$quantities = array( $row['quantity'] );
		if ( null !== $row['quantity'] && '' !== $row['quantity'] ) {
			$quantities[] = (float) $row['quantity'];
			$quantities[] = (int) $row['quantity'];
		}
		foreach ( $quantities as $q ) {
			$payload = wp_json_encode(
				array(
					'user_id'            => $base['user_id'],
					'branch_location_id' => $base['branch_location_id'],
					'action'             => $base['action'],
					'sku'                => $base['sku'],
					'quantity'           => $q,
					'old_value'          => $base['old_value'],
					'new_value'          => $base['new_value'],
					'reason'             => $base['reason'],
					'reference'          => $base['reference'],
					'approver_id'        => $base['approver_id'],
					'action_time'        => $base['action_time'],
					'previous_hash'      => $base['previous_hash'],
				)
			);
			if ( hash_equals( (string) $row['event_hash'], hash( 'sha256', (string) $previous . '|' . $payload ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function verify_chain() {
		global $wpdb;
		$table         = Stockland_DB::table( 'audit_logs' );
		$rows          = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', $table ), ARRAY_A );
		$previous      = '';
		$checked       = 0;
		$legacy        = 0;
		$compatible_v2 = 0;
		foreach ( $rows as $row ) {
			if ( empty( $row['event_hash'] ) ) {
				++$legacy;
				continue;
			}
			if ( (string) $row['previous_hash'] !== $previous ) {
				return array(
					'ok'          => false,
					'checked'     => $checked,
					'legacy_rows' => $legacy,
					'reason'      => 'Audit hash chain continuity failure at row ' . $row['id'],
				);
			}
			$version = isset( $row['hash_version'] ) && (int) $row['hash_version'] > 0 ? (int) $row['hash_version'] : 1;
			if ( $version >= self::HASH_VERSION ) {
				$payload  = self::payload(
					$row['user_id'],
					$row['branch_location_id'],
					$row['action'],
					$row['sku'],
					$row['quantity'],
					$row['old_value'],
					$row['new_value'],
					$row['reason'],
					$row['reference'],
					$row['approver_id'],
					$row['action_time'],
					$previous,
					self::HASH_VERSION
				);
				$expected = hash( 'sha256', self::HASH_VERSION . '|' . $previous . '|' . $payload );
				if ( ! hash_equals( (string) $row['event_hash'], $expected ) ) {
					if ( self::historical_v2_matches( $row, $previous ) ) {
						++$compatible_v2;
					} else {
						return array(
							'ok'           => false,
							'checked'      => $checked,
							'legacy_rows'  => $legacy,
							'reason'       => 'Audit event hash mismatch at row ' . $row['id'],
							'mismatch_row' => (int) $row['id'],
							'hash_version' => $version,
						);
					}
				}
			} else {
				if ( ! self::legacy_matches( $row, $previous ) ) {
					return array(
						'ok'          => false,
						'checked'     => $checked,
						'legacy_rows' => $legacy,
						'reason'      => 'Audit event hash mismatch at row ' . $row['id'],
					);
				}
				++$legacy;
			}
			$previous = (string) $row['event_hash'];
			++$checked;
		}
		return array(
			'ok'                        => true,
			'checked'                   => $checked,
			'legacy_rows'               => $legacy,
			'compatible_legacy_v2_rows' => $compatible_v2,
			'hash_version'              => self::HASH_VERSION,
		);
	}
}
