<?php
// Real report helpers and AJAX handler over a recording WordPress Option API.
define('ABSPATH', '/tmp/');
define('MB_IN_BYTES', 1048576);
$stored = array('tk_options' => array('keep_setting' => 'unchanged'));
$writes = array(); $authorized = true; $valid_nonce = true; $fail_write = false;
function get_option($key, $default = false) { return $GLOBALS['stored'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) {
    if ($GLOBALS['fail_write']) return false;
    $GLOBALS['stored'][$key] = $value; $GLOBALS['writes'][] = array($key, $autoload); return true;
}
function tk_toolkits_audit_log_option_change($key, $old, $value) {}
function tk_is_admin_user() { return $GLOBALS['authorized']; }
function tk_license_features_enabled() { return true; }
function tk_post($key, $default = '') { return $_POST[$key] ?? $default; }
function check_ajax_referer($action, $key) { if (!$GLOBALS['valid_nonce']) wp_send_json_error(array('message' => 'Invalid nonce'), 403); }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function home_url($path) { return 'https://fixture.invalid' . $path; }
function get_transient($key) { return false; }
function set_transient($key, $value, $ttl) {}
class WP_Error { function get_error_message() { return 'Fixture loopback unavailable'; } }
function wp_safe_remote_get($url, $args) { return new WP_Error(); }
function is_wp_error($value) { return $value instanceof WP_Error; }
class GSC_Response extends RuntimeException {
    public $success, $data, $status;
    function __construct($success, $data, $status) { $this->success = $success; $this->data = $data; $this->status = $status; }
}
function wp_send_json_success($data) { throw new GSC_Response(true, $data, 200); }
function wp_send_json_error($data, $status = 400) { throw new GSC_Response(false, $data, $status); }
$helpers = file_get_contents(dirname(__DIR__) . '/includes/helpers.php');
foreach (array('tk_report_option_keys', 'tk_is_report_option', 'tk_report_option_name', 'tk_get_option', 'tk_update_option') as $name) {
    if (!preg_match('/^function ' . $name . '\([^\n]*\).*?^\}/ms', $helpers, $match)) throw new RuntimeException('Missing real helper: ' . $name);
    eval($match[0]);
}
require dirname(__DIR__) . '/includes/seo-gsc-readiness.php';
function request_result(array $input): GSC_Response {
    $_POST = $input;
    try { tk_gsc_readiness_ajax(); } catch (GSC_Response $response) { return $response; }
    throw new RuntimeException('Handler did not respond');
}
function verify_gsc($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$first = request_result(array('item' => 'verification', 'run' => 'run-1', 'first' => '1'));
verify_gsc($first->success && $first->data['item']['state'] === 'unknown', 'A real failed check must be persisted');
$saved = tk_get_option('seo_gsc_readiness_report');
verify_gsc($saved['items']['verification'] === $first->data['item'] && $saved['scanned_at'] > 0, 'Report must round-trip with its timestamp');
verify_gsc($writes === array(array('tk_report_seo_gsc_readiness_report', false)), 'Report must use a dedicated non-autoloaded option');
verify_gsc($stored['tk_options'] === array('keep_setting' => 'unchanged'), 'Saving results must preserve plugin settings');
$failed = request_result(array('item' => 'robots', 'run' => 'run-1', 'operation' => 'record_error', 'error' => '<b>Timeout</b>'));
$saved = tk_get_option('seo_gsc_readiness_report');
verify_gsc($failed->success && count($saved['items']) === 2 && $saved['items']['robots']['detail'] === 'Timeout', 'Request failures must be saved as plain-text unverified results');
$new = request_result(array('item' => 'sitemap', 'run' => 'run-2', 'operation' => 'record_error', 'error' => 'Disconnected'));
$saved = tk_get_option('seo_gsc_readiness_report');
verify_gsc($new->success && array_keys($saved['items']) === array('sitemap'), 'A new run must not mix with old results, including when its first request fails');
$before = $stored;
foreach (array(
    array('item' => 'invalid', 'run' => 'run-2'),
    array('item' => 'sitemap', 'run' => ''),
    array('item' => 'sitemap', 'run' => 'run-2', 'operation' => 'invalid'),
) as $input) { verify_gsc(!request_result($input)->success && $stored === $before, 'Invalid requests must not overwrite the saved report'); }
$authorized = false;
verify_gsc(request_result(array('item' => 'sitemap', 'run' => 'run-3'))->status === 403 && $stored === $before, 'Unauthorized requests must preserve saved data');
$authorized = true; $valid_nonce = false;
verify_gsc(request_result(array('item' => 'sitemap', 'run' => 'run-3'))->status === 403 && $stored === $before, 'A failed nonce must preserve saved data');
$valid_nonce = true; $fail_write = true;
verify_gsc(request_result(array('item' => 'sitemap', 'run' => 'run-3', 'operation' => 'record_error', 'error' => 'Timeout'))->status === 500, 'Write failure must not be reported as saved');
echo "PASS: durable report routing, timestamps, preserved settings, request-error persistence, separate runs, rejected writes and storage-failure feedback. No live requests.\n";
