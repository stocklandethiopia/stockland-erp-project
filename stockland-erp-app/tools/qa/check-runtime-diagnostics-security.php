<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('ABSPATH')) define('ABSPATH', '/qa/wordpress/');
$GLOBALS['stockland_qa_options'] = array(
    'stockland_runtime_fatal_v1' => array(
        'reference' => 'FATAL-TEST-001',
        'time_gmt' => '2026-10-09 00:00:00',
        'message' => "A test failure\nwithout controls",
        'file' => 'class-stockland.php',
        'line' => 42,
        'stage' => 'runtime_test',
        'method' => 'GET',
        'request_path' => '/wp-json/stockland/v16/system/runtime?rest_nonce=DO_NOT_LEAK&token=secret#frag',
    ),
);
$GLOBALS['stockland_qa_roles'] = array();
$GLOBALS['stockland_qa_admins'] = array(99 => true);
function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['stockland_qa_options']) ? $GLOBALS['stockland_qa_options'][$name] : $default;
}
function get_current_user_id() { return (int)($GLOBALS['stockland_qa_current_user'] ?? 0); }
function user_can($user_id, $capability) {
    return $capability === 'manage_options' && !empty($GLOBALS['stockland_qa_admins'][(int)$user_id]);
}
class Stockland_Roles {
    public static function get_profile($user_id) {
        $role = $GLOBALS['stockland_qa_roles'][(int)$user_id] ?? '';
        return $role === '' ? null : array('role' => $role, 'is_active' => 1);
    }
}
require_once dirname(__DIR__, 2) . '/includes/class-stockland-runtime-diagnostics.php';
require_once dirname(__DIR__, 2) . '/includes/class-stockland-authority.php';

$fatal = Stockland_Runtime_Diagnostics::last_fatal();
if (($fatal['request_path'] ?? '') !== '/wp-json/stockland/v16/system/runtime') {
    fwrite(STDERR, "FAIL legacy fatal URI query/fragment redaction\n"); exit(1);
}
if (str_contains(json_encode($fatal), 'DO_NOT_LEAK') || str_contains(json_encode($fatal), 'secret')) {
    fwrite(STDERR, "FAIL legacy fatal diagnostics still expose query secrets\n"); exit(1);
}

$GLOBALS['stockland_qa_roles'] = array(1 => 'owner', 2 => 'management', 3 => 'branch_manager', 4 => 'warehouse_employee', 5 => 'auditor');
$cases = array(
    'Owner can view diagnostics' => Stockland_Authority::can_view_system_diagnostics(1) === true,
    'Management can view diagnostics' => Stockland_Authority::can_view_system_diagnostics(2) === true,
    'Branch manager cannot view diagnostics' => Stockland_Authority::can_view_system_diagnostics(3) === false,
    'Warehouse employee cannot view diagnostics' => Stockland_Authority::can_view_system_diagnostics(4) === false,
    'Auditor cannot view server diagnostics' => Stockland_Authority::can_view_system_diagnostics(5) === false,
    'Native WordPress admin can view diagnostics before ERP profile setup' => Stockland_Authority::can_view_system_diagnostics(99) === true,
);
foreach ($cases as $name => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$ok) exit(1);
}
$app = file_get_contents(dirname(__DIR__, 2) . '/includes/rest/class-rest-app.php');
$js = file_get_contents(dirname(__DIR__, 2) . '/assets/app.js');
if (substr_count($app, 'Stockland_Authority::can_view_system_diagnostics()') < 3) {
    fwrite(STDERR, "FAIL all detailed health/runtime endpoints are not restricted\n"); exit(1);
}
if (!str_contains($js, 'function resolveTrustedApiUrl(path)') || !str_contains($js, 'const url=resolveTrustedApiUrl(path)')) {
    fwrite(STDERR, "FAIL REST API helper does not enforce destination validation\n"); exit(1);
}
$integrity = file_get_contents(dirname(__DIR__, 2) . '/includes/class-stockland-integrity.php');
$integrity_normalized = preg_replace('/\\s+/', ' ', $integrity);
if (!str_contains($integrity, 'information_schema.KEY_COLUMN_USAGE') || !str_contains($integrity_normalized, "'database_foreign_keys' => \$foreign_keys") || !str_contains($integrity_normalized, "'automatic_migration' => false")) {
    fwrite(STDERR, "FAIL read-only database foreign-key inventory is missing\n"); exit(1);
}
echo "Runtime diagnostics security QA PASS: sensitive route authorization, legacy query redaction, REST destination guard and read-only FK inventory present.\n";
