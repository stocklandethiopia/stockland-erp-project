<?php

use PHPUnit\Framework\TestCase;

final class StocklandSequenceFakeWpdb {
	public int $next = 0;
	public function prepare( $sql, ...$args ) {
		return $sql; }
	public function query( $sql ) {
		if ( str_starts_with( trim( $sql ), 'INSERT IGNORE' ) ) {
			return 1;
		}
		if ( str_starts_with( trim( $sql ), 'UPDATE' ) ) {
			++$this->next;
			return 1;
		}
		return 0;
	}
	public function get_var( $sql ) {
		return $this->next;
	}
}

final class StocklandSequenceTest extends TestCase {
	public function test_sequence_is_year_scoped_and_zero_padded(): void {
		global $wpdb;
		$wpdb = new StocklandSequenceFakeWpdb();

		self::assertSame( 'JRN-2026-000001', Stockland_Sequence::next( 'JRN-', '2026-01-02' ) );
		self::assertSame( 'JRN-2026-000002', Stockland_Sequence::next( 'JRN-', '2026-01-02' ) );
	}
}
