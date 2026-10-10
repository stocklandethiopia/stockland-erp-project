<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__, 2);
$scan_dirs = array('includes', 'templates');
$files = array($root . '/stockland-inventory.php', $root . '/uninstall.php');
foreach ($scan_dirs as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') $files[] = $file->getPathname();
    }
}
$blocked = array(
    'dynamic_code_or_deserialization' => '/\b(?:eval|assert|unserialize|base64_decode)\s*\(/i',
    'shell_execution' => '/\b(?:shell_exec|passthru|system|proc_open|popen|pcntl_exec|exec)\s*\(/i',
    'weak_hash_primitive' => '/\b(?:md5|sha1)\s*\(/i',
);
$findings = array();
foreach (array_unique($files) as $file) {
    $source = file_get_contents($file);
    if ($source === false) { fwrite(STDERR, "FAIL cannot read runtime PHP file: {$file}\n"); exit(1); }
    foreach ($blocked as $rule => $pattern) {
        if (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) {
            $offset = $match[0][1];
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            $findings[] = basename($file) . ':' . $line . ' [' . $rule . ']';
        }
    }
}
if ($findings) {
    fwrite(STDERR, "FAIL runtime security heuristic findings (manual review required):\n" . implode("\n", $findings) . "\n");
    exit(1);
}
echo 'Runtime security heuristic QA PASS: no dynamic-code/deserialization, shell-execution or MD5/SHA-1 call patterns in runtime PHP files (' . count(array_unique($files)) . " files).\n";
