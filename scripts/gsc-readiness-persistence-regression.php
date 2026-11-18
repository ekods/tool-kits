<?php
// Real report helpers and AJAX handler over a recording WordPress Option API.
define('ABSPATH', sys_get_temp_dir() . '/tk-gsc-readiness-' . getmypid() . '/');
mkdir(ABSPATH);
register_shutdown_function(static function () { array_map('unlink', glob(ABSPATH . '*') ?: array()); @rmdir(ABSPATH); });
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
$http = array();
function wp_safe_remote_get($url, $args) { return $GLOBALS['http'][$url] ?? new WP_Error(); }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function trailingslashit($path) { return rtrim($path, '/\\') . '/'; }
function is_wp_error($value) { return $value instanceof WP_Error; }
class GSC_Response extends RuntimeException {
    public $success, $data, $status;
    function __construct($success, $data, $status) { $this->success = $success; $this->data = $data; $this->status = $status; }
}
function wp_send_json_success($data) { throw new GSC_Response(true, $data, 200); }
function wp_send_json_error($data, $status = 400) { throw new GSC_Response(false, $data, $status); }
$helpers = file_get_contents(dirname(__DIR__) . '/includes/helpers.php');
foreach (array('tk_report_option_keys', 'tk_is_report_option', 'tk_report_option_name', 'tk_get_option', 'tk_get_options', 'tk_request_options_cache', 'tk_clear_request_options_cache', 'tk_update_option') as $name) {
    if (!preg_match('/^function &?' . $name . '\([^\n]*\).*?^\}/ms', $helpers, $match)) throw new RuntimeException('Missing real helper: ' . $name);
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

// HTML-file verification: valid and reachable passes; blocked or mismatched files are issues; no file stays unknown/review.
$name = 'google1a2b3c4d5e6f7a8b.html'; $url = 'https://fixture.invalid/' . $name;
file_put_contents(ABSPATH . $name, 'google-site-verification: ' . $name);
file_put_contents(ABSPATH . 'google-notes.html', 'not a verification file');
$http[$url] = array('code' => 200, 'body' => 'google-site-verification: ' . $name);
$check = tk_gsc_readiness_check('verification');
verify_gsc($check['state'] === 'pass' && strpos($check['detail'], $name) !== false, 'A reachable verification file must pass even when the homepage cannot be fetched');
$http[$url] = array('code' => 403, 'body' => 'Forbidden');
$check = tk_gsc_readiness_check('verification');
verify_gsc($check['state'] === 'issue' && strpos($check['detail'], 'HTTP 403') !== false, 'A blocked verification file must be reported as an issue');
$http[$url] = array('code' => 200, 'body' => 'cached page');
verify_gsc(tk_gsc_readiness_check('verification')['state'] === 'issue', 'A file served with different content must be an issue');
file_put_contents(ABSPATH . $name, 'wrong token');
$http[$url] = array('code' => 200, 'body' => 'wrong token');
verify_gsc(tk_gsc_readiness_check('verification')['state'] === 'issue', 'A file without its own token must be an issue');
unlink(ABSPATH . $name);
verify_gsc(tk_gsc_readiness_check('verification')['state'] === 'unknown', 'Without a verification file a failed homepage stays unverified');

// A file name entered in Tool Kits is served by WordPress and verified at its public URL; no file on disk is needed.
foreach (array('google1a2b3c4d5e6f7a8b.html', 'https://fixture.invalid/google1a2b3c4d5e6f7a8b.html', 'google-site-verification: google1a2b3c4d5e6f7a8b.html') as $input) {
    verify_gsc(tk_gsc_verification_parse($input) === 'google1a2b3c4d5e6f7a8b.html', 'Verification input not parsed: ' . $input);
}
verify_gsc(tk_gsc_verification_parse('google.html') === '' && tk_gsc_verification_parse('verify-me.html') === '', 'Non-verification names must be rejected');
$stored['tk_options']['seo_gsc_verification_file'] = 'google9f8e7d6c5b4a3f2e.html'; tk_clear_request_options_cache();
$entered = 'https://fixture.invalid/google9f8e7d6c5b4a3f2e.html';
verify_gsc(tk_gsc_verification_requested('/google9f8e7d6c5b4a3f2e.html?x=1') === 'google9f8e7d6c5b4a3f2e.html', 'The entered file must be served at the site root');
verify_gsc(tk_gsc_verification_requested('/blog/google9f8e7d6c5b4a3f2e.html') === '' && tk_gsc_verification_requested('/google0000000000000000.html') === '', 'Other paths must not be answered');
$http[$entered] = array('code' => 200, 'body' => tk_gsc_verification_content('google9f8e7d6c5b4a3f2e.html'));
$check = tk_gsc_readiness_check('verification');
verify_gsc($check['state'] === 'pass' && strpos($check['detail'], 'entered in Tool Kits') !== false, 'A reachable entered verification file must pass without a file on disk');
$http[$entered] = array('code' => 404, 'body' => 'Not found');
$check = tk_gsc_readiness_check('verification');
verify_gsc($check['state'] === 'issue' && strpos($check['detail'], 'HTTP 404') !== false, 'An entered file that is not served must be an issue');
unset($stored['tk_options']['seo_gsc_verification_file']); tk_clear_request_options_cache();
verify_gsc(tk_gsc_verification_requested('/google9f8e7d6c5b4a3f2e.html') === '', 'A cleared file must no longer be served');

echo "PASS: entered verification file parsing, serving and checks, HTML verification file detection, durable report routing, timestamps, preserved settings, request-error persistence, separate runs, rejected writes and storage-failure feedback. No live requests.\n";
