<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
$s = file_get_contents($root . '/includes/class-stockland-xlsx.php');
$required = [
    'MAX_FILE_BYTES',
    'MAX_ZIP_ENTRIES',
    'MAX_ZIP_UNCOMPRESSED_BYTES',
    'MAX_ZIP_RATIO',
    'PK\\x03\\x04',
    'ZipArchive',
    'XMLReader',
    'MAX_PHYSICAL_ROW_NODES'
];
$missing = [];
foreach ($required as $needle) if (strpos($s, $needle) === false) $missing[] = $needle;
if ($missing) { fwrite(STDERR, 'XLSX safety QA FAILED: ' . implode(', ', $missing) . "\n"); exit(1); }
if (!preg_match('/MAX_FILE_BYTES\s*=\s*8\s*\*\s*1024\s*\*\s*1024/', $s)) { fwrite(STDERR, "XLSX size cap is missing or changed.\n"); exit(1); }
if (!preg_match('/MAX_ZIP_ENTRIES\s*=\s*300/', $s)) { fwrite(STDERR, "XLSX ZIP entry cap is missing or changed.\n"); exit(1); }
if (!preg_match('/MAX_ZIP_UNCOMPRESSED_BYTES\s*=\s*96\s*\*\s*1024\s*\*\s*1024/', $s)) { fwrite(STDERR, "XLSX expansion cap is missing or changed.\n"); exit(1); }
echo "XLSX safety QA PASS: size, signature, ZIP-entry, expansion, ratio and streaming limits present.\n";
