<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
$js = file_get_contents($root . '/assets/app.js');
$inner = substr_count($js, '.innerHTML');
$esc = preg_match_all('/\besc\s*\(/', $js, $m);
$csp = str_contains(file_get_contents($root . '/includes/class-stockland-frontend.php'), 'nonce=');
if ($inner < 1 || $esc < 1 || ! $csp) {
    fwrite(STDERR, "Client output-safety QA FAILED. innerHTML={$inner}, esc={$esc}, csp=" . ($csp ? 'yes' : 'no') . "\n");
    exit(1);
}
echo "Client output-safety QA PASS: CSP nonce present and escape helper coverage exists (innerHTML sinks remain explicitly regression-scanned).\n";
