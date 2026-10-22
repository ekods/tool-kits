<?php
define('ABSPATH', __DIR__ . '/');
$options = array(); $writes = 0; $reads = 0; $wp_version = '6.6.2';
function get_option($key, $default = false) { $GLOBALS['reads']++; return $GLOBALS['options'][$key] ?? $default; }
function get_site_option($key, $default = false) { return $default; }
function update_option($key, $value, $autoload = null) {
    if ($key !== 'tk_health_center_environment_revision' || $autoload !== false) { throw new RuntimeException('Unexpected write'); }
    $GLOBALS['writes']++; $GLOBALS['options'][$key] = $value;
}
require dirname(__DIR__) . '/includes/php-runtime-security.php';
require dirname(__DIR__) . '/includes/health-center-summary.php';
require dirname(__DIR__) . '/includes/health-center-engine.php';
$checks = 0;
function verify_runtime($condition, $message) { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks']++; }
verify_runtime($reads === 0 && $writes === 0, 'Loading helpers must not read or write options');
foreach (array('8.2.34' => 'affected', '8.2.35' => 'patched', '8.3.35' => 'affected', '8.3.36' => 'patched', '8.4.26' => 'affected', '8.4.27' => 'patched', '8.5.11' => 'affected', '8.5.12' => 'patched', '8.5.13' => 'patched', '7.4.33' => 'affected', '8.1.34' => 'affected', '8.6.0' => 'unknown', '9.0.0' => 'unknown', '5.3.29' => 'unknown', 'invalid' => 'unknown', '8.3.6-0ubuntu0.24.04.5' => 'verify_vendor', '8.4.27-custom' => 'verify_vendor', '8.5.12RC1' => 'verify_vendor') as $version => $status) {
    verify_runtime(tk_php2shell_assessment($version, 'fpm-fcgi')['status'] === $status, 'Wrong version classification: ' . $version);
}
foreach (array('cli', 'phpdbg') as $sapi) {
    $assessment = tk_php2shell_assessment('8.4.27', $sapi);
    verify_runtime($assessment['status'] === 'unknown' && $assessment['source'] === 'cli', 'CLI must not establish web patch status');
}
verify_runtime($reads === 0 && $writes === 0, 'Version checks must perform no database operations');
$options['active_plugins'] = array('one/main.php', 'two/main.php');
$environment = tk_health_center_environment();
$options['active_plugins'] = array_reverse($options['active_plugins']);
verify_runtime($environment === tk_health_center_environment(), 'Plugin order must not invalidate report');
$report = array('completed' => time(), 'environment' => $environment, 'php_security' => tk_php2shell_assessment('8.4.27', 'fpm-fcgi'));
verify_runtime(!tk_health_center_freshness($report)['stale'], 'Current report marked stale');
verify_runtime(tk_health_center_freshness($report, $report['completed'] + 86400)['stale'], '24-hour report must be stale');
verify_runtime(tk_health_center_freshness($report, $report['completed'] - 301)['stale'], 'Future report must not pass freshness');
verify_runtime(tk_health_center_freshness(array('completed' => time()))['stale'], 'Legacy report must request a new manual scan');
$options['tk_health_center_report'] = $report;
$summary = tk_health_center_php_summary();
verify_runtime($summary['status'] === 'patched' && !$summary['stale'] && !isset($summary['message'], $summary['advisory']), 'Heartbeat must send bounded saved metadata only');
$options['stylesheet'] = 'new-theme';
verify_runtime(tk_health_center_php_summary()['stale'], 'Theme changes must invalidate cached PHP status');
unset($options['stylesheet']);
$options['tk_options'] = array('seo_enabled' => 0);
verify_runtime(tk_health_center_freshness($report)['stale'], 'Relevant Tool Kits settings must invalidate saved report');
unset($options['tk_options']);
tk_health_center_invalidate();
verify_runtime(tk_health_center_freshness($report)['stale'] && $writes === 1, 'Upgrade event must invalidate once without starting a scan');
unset($options['tk_health_center_environment_revision']);
$options['active_plugins'][] = 'new/main.php';
verify_runtime(tk_health_center_freshness($report)['stale'], 'Plugin activation must invalidate saved report');
unset($options['tk_health_center_report']);
verify_runtime(tk_health_center_php_summary()['status'] === 'unknown', 'Missing saved scan must not invent patch status');
$snapshot = $options; $before_writes = $writes;
for ($i = 0; $i < 20; $i++) { tk_health_center_php_summary(); tk_health_center_freshness($report); }
verify_runtime($options === $snapshot && $writes === $before_writes, 'Opening report or heartbeat must never write or start scan work');
echo 'PASS: ' . $checks . " PHP runtime / saved-summary / freshness checks\n";
