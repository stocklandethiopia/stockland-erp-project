<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);

$bad_extensions = ['html','txt','md','csv','zip','py','log','bak','old','orig'];
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (! $file->isFile()) continue;
    $rel = ltrim(str_replace($root, '', $file->getPathname()), '/\\');
    $ext = strtolower($file->getExtension());
    // Enterprise QA/test configuration is intentionally permitted inside the
    // plugin because CI executes from the checked-out source tree. Audit/release
    // documents remain outside the deployable runtime package.
    if (str_starts_with($rel, 'tests/') || str_starts_with($rel, 'tools/qa/') || str_starts_with($rel, '.github/') || $rel === 'README.md') {
        continue;
    }
    if (in_array($ext, $bad_extensions, true)) $bad[] = $rel;
}

$markers = [
    'IT-00001-HO',
    'SKU-DRY',
    'SKU-BEV',
    'SKU-DAI',
    'SKU-BAK',
    'SKU-HOU',
    'SKU-PRO',
];
$found = [];
$scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($scan as $file) {
    if (! $file->isFile()) continue;
    $rel = ltrim(str_replace($root, '', $file->getPathname()), '/\\');
    if (str_starts_with($rel, 'tests/') || str_starts_with($rel, 'tools/qa/') || str_starts_with($rel, '.github/')) continue;
    if (! in_array(strtolower($file->getExtension()), ['php','js','css','json'], true)) continue;
    $data = @file_get_contents($file->getPathname());
    foreach ($markers as $marker) {
        if ($data !== false && strpos($data, $marker) !== false) $found[] = $rel . ' contains ' . $marker;
    }
}

if ($bad || $found) {
    fwrite(STDERR, "Runtime hygiene QA FAILED\n" . implode("\n", array_merge($bad, $found)) . "\n");
    exit(1);
}
echo "Runtime hygiene QA PASS: no audit/demo runtime residue or known unrelated SKU markers.\n";
