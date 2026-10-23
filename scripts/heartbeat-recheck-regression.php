<?php
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
define('TK_VERSION', '2.7.33');
define('MINUTE_IN_SECONDS', 60);
$options = array(); $transients = array(); $actions = array(); $requests = array(); $queries = 0; $checks = 0;
error_reporting(E_ALL);
set_error_handler(static function ($level, $message) { if (error_reporting() & $level) { throw new RuntimeException($message); } return false; });
class WP_Error {
    public $code; public $message; public $data;
    public function __construct($code, $message, $data) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
class WP_REST_Response {
    public $data; public $status; public $headers = array();
    public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
    public function header($name, $value) { $this->headers[$name] = $value; }
}
class RecheckRequest {
    public $body; public $signature;
    public function __construct($body, $signature) { $this->body = $body; $this->signature = $signature; }
    public function get_body() { return $this->body; }
    public function get_header($name) { return $name === 'x-auth-signature' ? $this->signature : ''; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function add_option($key, $value, $deprecated = '', $autoload = null) {
    if (isset($GLOBALS['options'][$key])) { return false; }
    if ($autoload !== false) { throw new RuntimeException('Recheck locks must not autoload'); }
    $GLOBALS['options'][$key] = $value; return true;
}
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) {
    if ($ttl > 600) { throw new RuntimeException('Unbounded recheck nonce lifetime'); }
    $GLOBALS['transients'][$key] = $value;
}
function add_action($name, $callback, $priority = 10) { $GLOBALS['actions'][$name][] = $callback; }
function register_rest_route($namespace, $route, $args) { $GLOBALS['registered'] = array($namespace, $route, $args); }
function home_url($path = '/') { return 'https://93.184.216.34' . $path; }
function tk_license_normalize_site_url($url) { return TK_License_Receipt::normalize_site($url); }
function tk_toolkits_install_id() { return 'fixture-install'; }
function tk_heartbeat_auth_keys() { return array('collector-test-token'); }
function tk_heartbeat_auth_key() { return 'collector-test-token'; }
function tk_heartbeat_collector_url() { return 'https://nexamonitor.example/api/toolkits/heartbeat'; }
function tk_license_env() { return 'prod'; }
function tk_get_option($key, $default = null) {
    $values = array('license_key' => 'COLLECTOR-LICENSE', 'license_status' => 'valid',
        'seo_content_audit_report' => array('average_score' => 87), 'seo_geo_audit_report' => array('average_score' => 81));
    return $values[$key] ?? $default;
}
function get_bloginfo($field) { return $field === 'version' ? '6.6.2' : 'Fixture Website'; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function wp_json_encode($value) { return json_encode($value); }
function tk_toolkits_signed_post($url, $body, $timestamp, $args) {
    $GLOBALS['requests'][] = array('url' => $url, 'body' => $body);
    return array('code' => 200, 'body' => '{"ok":true}');
}
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
$wpdb = new class {
    public $posts = 'wp_posts';
    public function get_var($sql) { $GLOBALS['queries']++; return 12; }
};
require dirname(__DIR__) . '/includes/license-receipt.php';
require dirname(__DIR__) . '/includes/monitoring-heartbeat.php';

// Real plugin response used by the Laravel integration test, without a WordPress database.
if (in_array('--reply', $argv, true)) {
    $input = json_decode(stream_get_contents(STDIN), true);
    $request = new RecheckRequest($input['body'], $input['signature']);
    $reply = tk_heartbeat_recheck_response($request);
    if (is_wp_error($reply)) { fwrite(STDERR, $reply->code); exit(1); }
    echo json_encode($reply->data);
    exit;
}

function verify_recheck($condition, $message) {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $GLOBALS['checks']++;
}
function challenge(array $changes = array(), $signature = null) {
    $body = json_encode(array_replace(array('action' => 'collect_heartbeat', 'site_url' => home_url('/'),
        'site_id' => 'fixture-install', 'timestamp' => time(), 'nonce' => bin2hex(random_bytes(32))), $changes));
    return new RecheckRequest($body, $signature ?? hash_hmac('sha256', $body, 'collector-test-token'));
}
tk_heartbeat_init();
verify_recheck(isset($actions['rest_api_init']) && !$requests && !$queries && !$options && !$transients, 'Loading/init must not gather or transmit telemetry');
tk_heartbeat_recheck_register();
verify_recheck($registered[0] === 'tool-kits/v1' && $registered[1] === '/heartbeat' && $registered[2]['methods'] === 'POST'
    && $registered[2]['permission_callback'] === 'tk_heartbeat_recheck_authorize', 'REST endpoint must require POST and authorization');

foreach (array(challenge(array(), ''), challenge(array('timestamp' => time() - 301)), challenge(array('timestamp' => time() + 301)),
    challenge(array('nonce' => 'invalid')), challenge(array('site_url' => 'https://another.test')),
    challenge(array('site_id' => 'other-install')), challenge(array('action' => 'heartbeat')),
    new RecheckRequest('{bad', hash_hmac('sha256', '{bad', 'collector-test-token'))) as $request) {
    verify_recheck(is_wp_error(tk_heartbeat_recheck_authorize($request)), 'Invalid challenge accepted');
    verify_recheck(is_wp_error(tk_heartbeat_recheck_response($request)) && !$queries && !$requests && !$options && !$transients, 'Unauthorized request accessed telemetry or changed state');
}
$request = challenge();
verify_recheck(tk_heartbeat_recheck_authorize($request) === true, 'Genuine collector challenge rejected');
$reply = tk_heartbeat_recheck_response($request);
verify_recheck($reply instanceof WP_REST_Response && $reply->status === 200, 'Genuine request did not return heartbeat');
$heartbeat = json_decode($reply->data['heartbeat'], true);
verify_recheck(hash_equals(hash_hmac('sha256', $reply->data['heartbeat'], 'collector-test-token'), $reply->data['signature']), 'Heartbeat signature differs from exact encoded bytes');
verify_recheck($heartbeat['recheck_nonce'] === json_decode($request->body, true)['nonce'] && $heartbeat['active'] === true
    && $heartbeat['site_url'] === home_url('/') && $heartbeat['site_id'] === 'fixture-install', 'Heartbeat did not bind challenge and installation');
verify_recheck($heartbeat['php_version'] === PHP_VERSION && $heartbeat['wp_version'] === '6.6.2'
    && $heartbeat['version'] === '2.7.33' && $heartbeat['maintenance']['revisions_total'] === 12
    && $heartbeat['seo_geo']['seo_score'] === 87, 'Heartbeat did not use current real payload builder');
verify_recheck($reply->headers['Cache-Control'] === 'no-store, private' && !$requests, 'Telemetry cached or additional callback request sent');
verify_recheck(!isset($options['tk_heartbeat_recheck_lock']) && $queries === 1, 'Completed request leaked a lock or repeated database collection');
verify_recheck(tk_heartbeat_recheck_response($request)->code === 'tk_recheck_replay' && $queries === 1, 'Replayed nonce gathered data again');
verify_recheck(tk_heartbeat_recheck_response(challenge())->data['status'] === 429 && $queries === 1, 'Rapid requests bypassed cooldown');
unset($transients['tk_hb_recheck_cooldown']);
$options['tk_heartbeat_recheck_lock'] = time();
verify_recheck(tk_heartbeat_recheck_response(challenge())->data['status'] === 429 && $queries === 1, 'Concurrent recheck bypassed atomic lock');
$options['tk_heartbeat_recheck_lock'] = time() - 31;
verify_recheck(tk_heartbeat_recheck_response(challenge()) instanceof WP_REST_Response && !isset($options['tk_heartbeat_recheck_lock']), 'Expired lock prevented recovery');
$sent = tk_heartbeat_send();
verify_recheck($sent['ok'] && count($requests) === 1 && json_decode($requests[0]['body'], true)['php_version'] === PHP_VERSION
    && !isset(json_decode($requests[0]['body'], true)['recheck_nonce']), 'Existing daily/manual heartbeat behavior regressed');
echo 'PASS: ' . $checks . " heartbeat authentication, real payload, replay, cooldown, lock and daily-send checks\n";
