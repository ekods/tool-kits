<?php
// Isolated regression checks: no WordPress installation or database is bootstrapped.
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
define('WP_CONTENT_DIR', __DIR__ . '/nonexistent-content');
define('HOUR_IN_SECONDS', 3600);
define('AUTH_KEY', 'isolated-test-key');
$options = array('tk_options' => array(
    'license_status' => 'valid', 'license_last_checked' => time(),
    'license_key' => 'fixture', 'license_site_url' => 'https://example.test',
    'toolkits_install_id' => 'fixture-install',
));
$transients = array('tk_healthcheck_cpu_cores' => 4);
$allowed = true;
$nonce_valid = true;
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; }
function add_action(...$args) {}
function add_filter(...$args) {}
function is_admin() { return false; }
function home_url($path = '/') { return 'https://example.test' . $path; }
function wp_parse_url($url) { return parse_url($url); }
function wp_json_encode($data) { return json_encode($data); }
function untrailingslashit($value) { return rtrim($value, '/'); }
function trailingslashit($value) { return rtrim($value, '/') . '/'; }
function get_plugins() { throw new RuntimeException('Unexpected plugin filesystem scan'); }
function current_user_can($capability) { return $GLOBALS['allowed']; }
function check_ajax_referer($action, $name) {
    if ($action !== 'tk_realtime_health' || $name !== 'nonce' || !$GLOBALS['nonce_valid']) throw new RuntimeException('Invalid nonce');
}
class MonitoringResponse extends RuntimeException {
    public $data;
    public function __construct($data) { $this->data = $data; }
}
function wp_send_json_success($data) { throw new MonitoringResponse(array('success' => true, 'data' => $data)); }
function wp_send_json_error($data) { throw new MonitoringResponse(array('success' => false, 'data' => $data)); }
require dirname(__DIR__) . '/includes/helpers.php';
require dirname(__DIR__) . '/includes/monitoring-404-health.php';
$checks = 0;
function verify($condition, $label) {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $GLOBALS['checks']++;
    echo 'PASS: ', $label, PHP_EOL;
}
$options['tk_options']['license_signature'] = tk_license_expected_signature();
verify(tk_license_validate()['status'] === 'valid', 'Signed cached license validates without a network call');
$options['tk_options']['license_signature'] = 'tampered';
verify(tk_license_validate()['status'] === 'integrity_violation', 'Cached license still enforces signature integrity');
verify(tk_healthcheck_cpu_cores() === 4, 'CPU capacity uses its cached value');
$transients['tk_healthcheck_cpu_cores'] = 0;
verify(tk_healthcheck_cpu_cores() === null, 'Unavailable CPU capacity is cached without shell retries');
$transients['tk_healthcheck_cpu_cores'] = 4;
$data = tk_realtime_health_data(false);
verify($data['heavy_plugins'] === array(), 'Lightweight metrics do not enumerate plugins');
verify($data['memory']['used'] > 0 && $data['cpu_cores'] === 4, 'Lightweight metrics retain memory and CPU data');
try { tk_realtime_health_ajax(); } catch (MonitoringResponse $response) {
    verify($response->data['success'] && $response->data['data']['heavy_plugins'] === array(), 'AJAX uses the lightweight path');
}
$allowed = false;
try { tk_realtime_health_ajax(); } catch (MonitoringResponse $response) {
    verify(!$response->data['success'], 'Unauthorized users cannot fetch metrics');
}
$allowed = true;
$nonce_valid = false;
try { tk_realtime_health_ajax(); throw new LogicException('Nonce was not checked'); } catch (RuntimeException $error) {
    verify($error->getMessage() === 'Invalid nonce', 'Invalid nonce is rejected before collecting metrics');
}
echo $checks, " monitoring regression checks passed.\n";
