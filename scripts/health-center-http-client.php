<?php
// CLI-only client for the isolated local HTTP fixture. Never loads wp-config or a real database.
if (PHP_SAPI !== 'cli' || !getenv('TK_HEALTH_HTTP_ORIGIN')) { exit(1); }
define('ABSPATH', '/tmp/'); define('TK_VERSION', 'fixture');
function home_url($path) { return getenv('TK_HEALTH_HTTP_ORIGIN') . ($GLOBALS['argv'][1] ?? '/'); }
function wp_parse_url($url, $part = -1) { return parse_url($url, $part); }
function wp_http_validate_url($url) { return strpos($url, getenv('TK_HEALTH_HTTP_ORIGIN') . '/') === 0 ? $url : false; }
function get_option($key, $default = false) { return $default; }
require dirname(__DIR__) . '/includes/health-center-engine.php';
require dirname(__DIR__) . '/includes/health-center-probe.php';
$start = microtime(true); $response = tk_health_center_public_request();
$report = array('checks' => array(), 'performance' => array()); tk_health_analyze_public($report, $response);
$response['bytes'] = strlen($response['body']); unset($response['body']);
echo json_encode(array('elapsed' => microtime(true) - $start, 'response' => $response, 'report' => $report));
