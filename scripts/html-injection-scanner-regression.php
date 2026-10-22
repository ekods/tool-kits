<?php
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', '/tmp/');
define('ARRAY_A', 'ARRAY_A');
define('TK_URL', 'https://site.test/wp-content/plugins/tool-kits/');
$checks = 0; $options = array(); $transients = array(); $mutations = array(); $requests = array(); $actions = array(); $enqueued = array();
$capable = true; $manageable = true; $licensed = true; $nonce_valid = true; $user = 7;
error_reporting(E_ALL);
set_error_handler(function($level, $message) { if (error_reporting() & $level) { throw new RuntimeException($message); } return false; });
function check_scan($value, $message) { if (!$value) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
class HTML_Scan_Reply extends RuntimeException { public $success; public $data; public $status; public function __construct($success, $data, $status) { $this->success = $success; $this->data = $data; $this->status = $status; } }
function wp_send_json_success($data) { throw new HTML_Scan_Reply(true, $data, 200); }
function wp_send_json_error($data, $status = 400) { throw new HTML_Scan_Reply(false, $data, $status); }
function current_user_can($cap) { return $GLOBALS['capable']; }
function tk_toolkits_can_manage() { return $GLOBALS['manageable']; }
function tk_license_features_enabled() { return $GLOBALS['licensed']; }
function check_ajax_referer($action) { check_scan($action === 'tk_html_scan', 'Wrong nonce action'); if (!$GLOBALS['nonce_valid']) { wp_send_json_error(array('message' => 'Bad nonce'), 403); } }
function wp_unslash($value) { return $value; }
function sanitize_text_field($text) { return trim(strip_tags($text)); }
function get_current_user_id() { return $GLOBALS['user']; }
function wp_generate_uuid4() { $bytes = bin2hex(random_bytes(16)); return substr($bytes, 0, 8) . '-' . substr($bytes, 8, 4) . '-' . substr($bytes, 12, 4) . '-' . substr($bytes, 16, 4) . '-' . substr($bytes, 20); }
function get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { check_scan(strpos($key, 'tk_html_scan_') === 0, 'Scanner mutated a site option'); $GLOBALS['options'][$key] = $value; $GLOBALS['mutations'][] = $key; }
function add_option($key, $value, $deprecated = '', $autoload = null) { if (array_key_exists($key, $GLOBALS['options'])) { return false; } update_option($key, $value, $autoload); return true; }
function delete_option($key) { check_scan(strpos($key, 'tk_html_scan_') === 0, 'Scanner deleted a site option'); unset($GLOBALS['options'][$key]); }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { check_scan($ttl === 1800, 'Job lifetime unbounded'); $GLOBALS['transients'][$key] = $value; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); }
function home_url($path = '/') { return 'https://site.test' . $path; }
function admin_url($path) { return 'https://site.test/wp-admin/' . $path; }
function wp_parse_url($url) { return parse_url($url); }
function get_posts($args) { check_scan($args['post_status'] === 'publish' && $args['has_password'] === false && $args['numberposts'] <= 51, 'Private posts fetched or discovery unbounded'); return array(1, 2, 3); }
function get_post_types($args, $format) { return array('post' => 'post', 'page' => 'page'); }
function get_permalink($id) { return home_url(array(1 => '/welcome', 2 => '/redirect', 3 => '/nothtml')[$id]); }
function wp_safe_remote_get($url, $args) {
    check_scan(strpos($url, 'https://site.test/') === 0 && $args['redirection'] === 0 && $args['cookies'] === array() && $args['sslverify'] === true && $args['limit_response_size'] === 524289, 'Unsafe HTTP scan request');
    $GLOBALS['requests'][] = $url;
    if (strpos($url, '/redirect') !== false) { return array('code' => 301, 'type' => 'text/html', 'body' => '', 'location' => 'https://external.test'); }
    if (strpos($url, '/nothtml') !== false) { return array('code' => 200, 'type' => 'application/json', 'body' => '{"ok":true}'); }
    return array('code' => 200, 'type' => 'text/html; charset=UTF-8', 'body' => '<html><body><img src=x onerror="alert(1)"><script nonce="valid">eval(atob("eA=="))</script></body></html>');
}
function is_wp_error($response) { return false; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_header($response, $key) { return $response['type']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_url($text) { return esc_html($text); }
function esc_textarea($text) { return esc_html($text); }
function wp_create_nonce($action) { return 'fixture-nonce'; }
function disabled($condition) { if ($condition) { echo 'disabled'; } }
function tk_render_header_branding() {}
function tk_render_page_hero($title, $description, $icon) { echo '<h1>' . esc_html($title) . '</h1>'; }
function get_edit_post_link($id, $context) { return admin_url('post.php?post=' . (int) $id . '&action=edit'); }
function add_action($name, $callback, $priority = 10) { $GLOBALS['actions'][$name] = $callback; }
function add_submenu_page(...$args) { $GLOBALS['menu'] = $args; }
function tk_asset_version($path) { return 'fixture'; }
function wp_enqueue_script(...$args) { $GLOBALS['enqueued'][] = $args; }
class HTML_Scan_DB {
    public $posts = 'fixture_posts'; public $postmeta = 'fixture_postmeta'; public $options = 'fixture_options'; public $last_error = ''; public $queries = array(); public $data;
    public function __construct() {
        $this->data = array(
            'posts' => array(array('id' => 1, 'post_title' => 'Demo', 'post_excerpt' => '', 'post_content' => '<script>alert(1)</script><img onerror="alert(2)">')),
            'meta' => array(array('id' => 20, 'post_id' => 1, 'meta_key' => 'builder_html', 'scan_value' => json_encode(array('html' => '<iframe hidden src="https://external.test"></iframe>'))), array('id' => 21, 'post_id' => 1, 'meta_key' => 'api_token', 'scan_value' => '<img onerror=secret()>')),
            'options' => array(array('id' => 30, 'option_name' => 'widget_custom_html', 'scan_value' => serialize(array('html' => '<a href="javascript:alert(1)">Link</a>')))),
        );
    }
    public function esc_like($value) { return addcslashes($value, '_%\\'); }
    public function prepare($sql, ...$args) { return json_encode(array($sql, $args)); }
    public function get_results($query, $format) {
        list($sql, $args) = json_decode($query, true); $this->queries[] = $sql;
        check_scan(strpos($sql, 'SELECT ') === 0 && strpos($sql, 'LEFT(') !== false && strpos($sql, 'LIMIT %d') !== false, 'Database scan is unbounded or mutating');
        $phase = strpos($sql, 'fixture_postmeta') !== false ? 'meta' : (strpos($sql, 'fixture_posts') !== false ? 'posts' : 'options');
        $cursor = $phase === 'posts' ? $args[3] : $args[1]; $limit = end($args);
        return array_slice(array_values(array_filter($this->data[$phase], function($row) use ($cursor) { return $row['id'] > $cursor; })), 0, $limit);
    }
}
$wpdb = new HTML_Scan_DB();
require dirname(__DIR__) . '/includes/html-injection-scanner.php';
function request_scan($action, $posted = array(), $method = 'POST') {
    $_POST = $posted; $_SERVER['REQUEST_METHOD'] = $method;
    try { call_user_func('tk_html_scan_' . $action . '_ajax'); throw new RuntimeException('No JSON reply'); }
    catch (HTML_Scan_Reply $reply) { return $reply; }
}
tk_html_scan_init();
check_scan(isset($actions['wp_ajax_tk_html_scan_start'], $actions['wp_ajax_tk_html_scan_step']) && !isset($actions['wp_ajax_nopriv_tk_html_scan_start']), 'Public scan endpoints registered');
tk_html_scan_register_page(); check_scan($menu[4] === 'tool-kits-html-scanner', 'Scanner menu missing');
$_GET = array('page' => 'unrelated'); tk_html_scan_enqueue(); check_scan(!$enqueued, 'Scanner JS loaded on unrelated screen');
$_GET['page'] = 'tool-kits-html-scanner'; tk_html_scan_enqueue(); check_scan(count($enqueued) === 1, 'Scanner JS not scoped to own page');

$original_data = $wpdb->data;
foreach (array('capable', 'manageable', 'licensed', 'nonce_valid') as $flag) {
    $GLOBALS[$flag] = false; $before = $options; $reply = request_scan('start');
    check_scan(!$reply->success && $reply->status === 403 && $before === $options && !$requests, 'Unauthorized scan mutated options or made a request: ' . $flag); $GLOBALS[$flag] = true;
}
check_scan(request_scan('start', array(), 'GET')->status === 405, 'GET starts a scan');
check_scan(request_scan('start', array('trusted_hosts' => array('bad')))->status === 400 && !$options, 'Array input accepted');
check_scan(request_scan('start', array('trusted_hosts' => '*'))->status === 400, 'Global wildcard allowed');
check_scan(!tk_html_scan_local_url('https://evil.test/') && !tk_html_scan_local_url('http://site.test/') && !tk_html_scan_local_url('https://u:p@site.test/') && !tk_html_scan_local_url('https://site.test/wp-admin/'), 'Unsafe origin accepted');
check_scan(tk_html_scan_local_url('https://site.test/?p=42') && !tk_html_scan_local_url('https://site.test/?s=private') && !tk_html_scan_local_url('https://site.test/?p[]=1'), 'Plain permalink/query validation incorrect');
$start = request_scan('start', array('max_urls' => 20, 'max_rows' => 25, 'trusted_hosts' => 'good.test'));
check_scan($start->success && $start->data['report']['status'] === 'running', 'Scan did not start');
$token = $start->data['token'];
check_scan(request_scan('start')->status === 409, 'Concurrent scan start accepted');
$user = 8; $before = $options;
check_scan(request_scan('step', array('token' => $token))->status === 403 && $requests === array(), 'Another user advanced scan');
check_scan(!isset($options['tk_html_scan_step_lock']), 'Authorization failure left a batch lock');
$user = 7;
$options['tk_html_scan_step_lock'] = time() + 30;
check_scan(request_scan('step', array('token' => $token))->status === 409, 'Concurrent batch accepted');
check_scan(request_scan('cancel', array('token' => $token))->status === 409, 'Cancellation raced an active batch');
unset($options['tk_html_scan_step_lock']);
check_scan(request_scan('clear')->status === 409, 'Active report cleared');
for ($i = 0; $i < 15; $i++) {
    $step = request_scan('step', array('token' => $token));
    check_scan($step->success, 'Batch failed');
    if ($step->data['done']) { break; }
}
check_scan($step->data['done'] && $step->data['report']['status'] === 'partial', 'Redirect/non-HTML coverage not marked partial');
$report = $step->data['report'];
check_scan($report['counts'] === array('posts' => 1, 'meta' => 2, 'options' => 1, 'urls' => 4), 'Source cursors/counts wrong');
check_scan(count($requests) === 4 && !isset($options['tk_html_scan_lock']) && !isset($options['tk_html_scan_step_lock']), 'HTTP duplicated or locks leaked');
check_scan(in_array('stored_script', array_column($report['findings'], 'rule'), true) && in_array('encoded_execution', array_column($report['findings'], 'rule'), true), 'Stored/live script evidence missing');
check_scan(strpos(json_encode($report), 'api_token') === false, 'Secret meta field scanned');
check_scan($wpdb->data === $original_data, 'Scanner mutated site content');
ob_start(); tk_html_scan_render_page(); $page = ob_get_clean();
check_scan(strpos($page, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false && strpos($page, '<script>alert(1)</script>') === false && strpos($page, '<iframe hidden') === false, 'Report evidence is executable HTML');
check_scan(strpos($page, 'Edit source') !== false && strpos($page, 'incomplete') !== false, 'Source editing or partial coverage explanation missing');
check_scan(request_scan('step', array('token' => $token))->status === 403 && !isset($options['tk_html_scan_step_lock']), 'Finished scan replay accepted or leaked lock');
check_scan(request_scan('clear')->success && !isset($options['tk_html_scan_report']), 'Report not cleared');

$start = request_scan('start'); $token = $start->data['token'];
check_scan(request_scan('cancel', array('token' => $token))->data['report']['status'] === 'cancelled' && !isset($options['tk_html_scan_lock']), 'Stop did not preserve partial report/release lock');
// A cap must be reported as incomplete instead of suggesting all rows were clean.
$wpdb->data['posts'] = array();
for ($i = 1; $i <= 26; $i++) { $wpdb->data['posts'][] = array('id' => $i, 'post_title' => '', 'post_content' => '', 'post_excerpt' => ''); }
$start = request_scan('start', array('max_rows' => 25)); $token = $start->data['token'];
for ($i = 0; $i < 5; $i++) { $step = request_scan('step', array('token' => $token)); }
check_scan($step->data['report']['counts']['posts'] === 25 && $step->data['report']['limited'] && $step->data['report']['phase'] === 'meta', 'Database cap/cursor does not mark remaining rows');
request_scan('cancel', array('token' => $token));
require dirname(__DIR__) . '/includes/cache.php';
check_scan(tk_page_cache_should_ignore_option_change('tk_html_scan_report') && tk_page_cache_should_ignore_option_change('tk_html_scan_lock'), 'Scan progress purges frontend page cache');
echo 'PASS: ' . $checks . " scanner access/nonce checks, read-only bounded DB/HTTP, cursors, ownership, cancellation, report escaping, incomplete coverage and cache exclusions\n";
