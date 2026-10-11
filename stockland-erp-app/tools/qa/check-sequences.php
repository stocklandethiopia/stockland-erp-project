<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
$root   = dirname( __DIR__, 2 );
$it     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ) );
$legacy = array();
foreach ( $it as $file ) {
	if ( ! $file->isFile() || $file->getExtension() !== 'php' ) {
		continue;
	}
	$s = file_get_contents( $file->getPathname() );
	foreach ( array( "JRN-'.*wp_rand", "TRF-'.*wp_rand", "ADJ-'.*wp_rand", "RCPT-'.*wp_rand", "WHO-'.*wp_rand", "REQ-'.*wp_rand" ) as $pattern ) {
		if ( preg_match( '/' . $pattern . '/i', $s ) ) {
			$legacy[] = $file->getFilename() . ' matches ' . $pattern;
		}
	}
}
if ( $legacy ) {
	fwrite( STDERR, "Sequence QA FAILED:\n" . implode( "\n", $legacy ) . "\n" );
	exit( 1 );
}
if ( ! is_file( $root . '/includes/class-stockland-sequence.php' ) ) {
	exit( 1 );
}
echo "Sequence QA PASS: generated document identifiers use the atomic sequence service; legacy random 3-digit document patterns absent.\n";
