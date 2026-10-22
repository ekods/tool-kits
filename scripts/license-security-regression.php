<?php
// No wp-load, live database or network. The HTTP double acts as a separate issuer.
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
define('AUTH_KEY', 'an-attacker-can-read-this-local-wordpress-secret');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
require __DIR__ . '/license-test-fixture.php';
$options = array(); $http_calls = array(); $mode = 'valid'; $checks = 0; $mail = array(); $scheduled = array();
class WP_Error { private $message; function __construct($code, $message) { $this->message = $message; } function get_error_message() { return $this->message; } }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_current_blog_id() { return 1; }
function get_option($key, $default = false) { return $key === 'tk_options' ? $GLOBALS['options'] : $default; }
function update_option($key, $value, $autoload = null) { if ($key === 'tk_options') { $GLOBALS['options'] = $value; } }
function add_action(...$args) {}
function add_filter(...$args) {}
function is_admin() { return false; }
function home_url($path = '/') { return 'https://example.test' . $path; }
function wp_json_encode($value) { return json_encode($value); }
function wp_parse_url($url) { return parse_url($url); }
function untrailingslashit($value) { return rtrim($value, '/'); }
function is_email($value) { return false; }
function wp_next_scheduled($hook) { return $GLOBALS['scheduled'][$hook] ?? false; }
function wp_schedule_event($time, $recurrence, $hook) { $GLOBALS['scheduled'][$hook] = $time; }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['scheduled'][$hook]); }
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_safe_remote_post($url, $args) {
    $GLOBALS['http_calls'][] = array($url, $args);
    $mode = $GLOBALS['mode'];
    if ($mode === 'offline') { return new WP_Error('offline', 'Synthetic network outage'); }
    $payload = json_decode($args['body'], true);
    $receipt = tk_test_license_receipt($GLOBALS['options'], array('request_nonce' => $payload['request_nonce']));
    $data = array('ok' => true, 'status' => 'valid', 'receipt' => $receipt,
        // Unsigned metadata must not override signed authority.
        'license_type' => 'forged-enterprise', 'site_url' => 'https://attacker.test', 'expires_at' => '2099-01-01');
    $code = 200;
    if ($mode === 'unsigned') { unset($data['receipt']); }
    if ($mode === 'false-valid') { $data['ok'] = false; }
    if ($mode === 'string-true') { $data['ok'] = 'true'; }
    if ($mode === 'replay') { $data['receipt'] = $GLOBALS['saved_receipt']; }
    if ($mode === 'bad-signature') { $data['receipt']['signature'] = base64_encode(str_repeat('x', 384)); }
    if ($mode === 'denied-valid') { $data['ok'] = false; $code = 403; }
    if ($mode === 'revoked') { $data = array('ok' => false, 'status' => 'revoked'); $code = 403; }
    if ($mode === 'server-error') { $code = 503; }
    if ($mode === 'redirect') { $code = 302; }
    return array('response' => array('code' => $code), 'body' => json_encode($data));
}
require dirname(__DIR__) . '/includes/helpers.php';
function license_check($condition, $message) { if (!$condition) { throw new RuntimeException('FAIL: ' . $message); } $GLOBALS['checks']++; }
function seed_license(array $changes = array(), array $claims = array()) {
    $options = array_merge(array('license_key' => 'fixture', 'toolkits_install_id' => 'fixture-install',
        'license_status' => 'valid', 'license_type' => 'prod', 'license_site_url' => home_url('/'),
        'license_expires_at' => '', 'license_last_checked' => time(), 'heartbeat_auth_key' => 'synthetic-collector-token'), $changes);
    $options['license_receipt'] = tk_test_license_receipt($options, $claims);
    $GLOBALS['options'] = $options; tk_options_cache_invalidate(); return $options;
}
function alter_license($field, $value) { $GLOBALS['options'][$field] = $value; tk_options_cache_invalidate(); }
seed_license();
license_check(tk_license_features_enabled() && tk_license_is_valid(), 'Genuine receipt enables features');
license_check(tk_license_validate()['status'] === 'valid' && count($http_calls) === 0, 'Fresh receipt avoids network');
$context = array('license_key' => 'fixture', 'site_url' => home_url('/'), 'site_id' => 'fixture-install');
$receipt = $options['license_receipt']; $keys = tk_license_public_keys();
$now = time();
license_check(TK_License_Receipt::verify($receipt, $keys, $context, $now + 86401) === false, 'Same-request crypto cache cannot extend expiry');
license_check(TK_License_Receipt::verify($receipt, $keys, $context, $now, str_repeat('b', 64)) === false, 'Challenge mismatch rejects replay');
foreach (array('license_key' => 'stolen-other-key', 'toolkits_install_id' => 'other-install', 'license_site_url' => 'https://other.test', 'license_type' => 'enterprise', 'license_expires_at' => '2099-01-01') as $field => $value) {
    seed_license(); alter_license($field, $value);
    license_check(!tk_license_features_enabled(), 'Database tampering rejected: ' . $field);
}
seed_license();
$_SERVER['HTTP_HOST'] = 'cloned.test'; license_check(!tk_license_features_enabled(), 'Cloned database on another host rejected');
$_SERVER['HTTP_HOST'] = 'example.test'; license_check(tk_license_features_enabled(), 'Current host accepted'); unset($_SERVER['HTTP_HOST']);
foreach (array(array('expires_at' => $now - 1), array('issued_at' => $now + 301), array('expires_at' => $now + 86401),
    array('license_expires_at' => $now - 1), array('license_expires_at' => $now + 60), array('product' => 'another-plugin'),
    array('site_id' => 'cloned-install'), array('site_url' => 'https://cloned.test'), array('version' => 2), array('issued_at' => (string) $now)) as $claims) {
    seed_license(array(), $claims); license_check(!tk_license_features_enabled(), 'Invalid signed claims rejected: ' . json_encode($claims));
}
seed_license(); alter_license('license_receipt', array());
alter_license('license_signature', hash_hmac('sha256', 'fixture|valid', AUTH_KEY));
license_check(!tk_license_features_enabled(), 'Legacy local HMAC cannot activate');
tk_license_update_state(array('license_status' => 'valid'));
license_check(!tk_license_features_enabled(), 'State update cannot mint an activation credential');
seed_license(); $options['license_receipt']['key_id'] = 'unknown'; tk_options_cache_invalidate();
license_check(!tk_license_features_enabled(), 'Unknown signing key rejected');
seed_license(); $options['license_receipt']['payload'] .= ' '; tk_options_cache_invalidate();
license_check(!tk_license_features_enabled(), 'Noncanonical payload rejected');
foreach (array('http://nexamonitor.theteamtheteam.com/api/toolkits/license', 'https://nexamonitor.theteamtheteam.com.evil.test/api/toolkits/license',
    'https://nexamonitor.theteamtheteam.com/arbitrary', 'https://nexamonitor.theteamtheteam.com:444/api/toolkits/license',
    'https://u:p@nexamonitor.theteamtheteam.com/api/toolkits/license', 'https://nexamonitor.theteamtheteam.com/api/toolkits/license?key=x',
    'https://127.0.0.1/api/toolkits/license') as $url) {
    license_check(!tk_toolkits_license_server_is_trusted($url), 'Unsafe endpoint rejected: ' . $url);
}
license_check(tk_toolkits_license_server_is_trusted('https://nexamonitor.theteamtheteam.com/api/toolkits/license'), 'Canonical endpoint approved');
foreach (array('unsigned', 'false-valid', 'string-true', 'replay', 'bad-signature', 'denied-valid', 'revoked', 'redirect') as $test_mode) {
    seed_license(); $saved_receipt = $options['license_receipt']; $mode = $test_mode;
    $result = tk_license_validate(true);
    license_check($result['status'] !== 'valid' && !tk_license_features_enabled(), 'Untrusted response rejected: ' . $test_mode);
}
$mode = 'valid'; seed_license(); alter_license('license_last_checked', time() + 999999);
$before = count($http_calls); license_check(tk_license_validate()['status'] === 'valid' && count($http_calls) > $before, 'Future local timestamp cannot suppress refresh');
license_check(tk_get_option('license_type') === 'prod' && tk_get_option('license_site_url') === 'https://example.test' && tk_get_option('license_expires_at') === '', 'Unsigned metadata ignored');
$args = end($http_calls)[1];
license_check($args['sslverify'] === true && $args['redirection'] === 0 && $args['limit_response_size'] === 65536, 'License transport enforces TLS, no redirects and bounded response');
$deadline = tk_license_verified_claims()['expires_at'];
foreach (array('offline', 'server-error') as $test_mode) {
    $mode = $test_mode; license_check(tk_license_validate(true)['status'] === 'valid' && tk_license_features_enabled(), 'Bounded outage tolerance: ' . $test_mode);
    license_check(tk_license_verified_claims()['expires_at'] === $deadline, 'Outage never renews receipt');
}
seed_license(array(), array('expires_at' => time() - 1)); $mode = 'offline';
license_check(tk_license_validate(true)['status'] !== 'valid' && !tk_license_features_enabled(), 'Expired receipt fails closed during outage');
seed_license(); tk_license_refresh_schedule(); license_check(isset($scheduled['tk_license_refresh']), 'Refresh scheduled independently of heartbeat');
alter_license('license_key', ''); tk_license_refresh_schedule(); license_check(!isset($scheduled['tk_license_refresh']), 'Refresh removed after key removal');
echo 'PASS license security: ', $checks, ' checks', PHP_EOL;
