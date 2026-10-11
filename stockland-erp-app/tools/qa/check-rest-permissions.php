<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
$root          = dirname( __DIR__, 2 );
$files         = glob( $root . '/includes/rest/*.php' );
$registrations = 0;
$missing       = array();

foreach ( $files as $file ) {
	$source = file_get_contents( $file );
	$tokens = token_get_all( $source );
	$count  = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( ! is_array( $token ) || $token[0] !== T_STRING || strtolower( $token[1] ) !== 'register_rest_route' ) {
			continue;
		}

		$j = $i + 1;
		while ( $j < $count && ( ! is_string( $tokens[ $j ] ) || $tokens[ $j ] !== '(' ) ) {
			++$j;
		}
		if ( $j >= $count ) {
			$missing[] = basename( $file ) . ': register_rest_route has no opening parenthesis';
			continue;
		}

		$depth          = 0;
		$has_permission = false;
		$start_line     = $tokens[ $i ][2] ?? 0;
		for ( ; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			if ( is_string( $t ) ) {
				if ( '(' === $t ) {
					++$depth;
				} elseif ( ')' === $t ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				}
				continue;
			}
			if ( $t[0] === T_CONSTANT_ENCAPSED_STRING ) {
				$value = trim( $t[1], "'\"" );
				if ( strtolower( $value ) === 'permission_callback' ) {
					$k = $j + 1;
					while ( $k < $count && is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
						++$k;
					}
					if ( $k < $count && is_array( $tokens[ $k ] ) && $tokens[ $k ][0] === T_DOUBLE_ARROW ) {
						$has_permission = true;
					}
				}
			}
		}
		++$registrations;
		if ( 0 !== $depth ) {
			$missing[] = basename( $file ) . ': unbalanced register_rest_route call near line ' . $start_line;
		} elseif ( ! $has_permission ) {
			$missing[] = basename( $file ) . ': registration near line ' . $start_line . ' has no permission_callback';
		}
	}
}

if ( $registrations === 0 || $missing ) {
	fwrite( STDERR, "REST permission QA FAILED\nRegistrations: {$registrations}\n" . implode( "\n", $missing ) . "\n" );
	exit( 1 );
}

echo "REST permission QA PASS: {$registrations} register_rest_route calls have explicit permission_callback.\n";
