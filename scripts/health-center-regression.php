<?php
if (PHP_SAPI !== 'cli') { exit; }
$root = sys_get_temp_dir() . '/tk-health-unit-' . bin2hex(random_bytes(6));
mkdir($root . '/wp-admin/includes', 0700, true); file_put_contents($root . '/wp-admin/includes/plugin.php', '<?php');
register_shutdown_function(static function () use ($root) { unlink($root . '/wp-admin/includes/plugin.php'); rmdir($root . '/wp-admin/includes'); rmdir($root . '/wp-admin'); rmdir($root); });
define('ABSPATH', $root . '/'); define('TK_URL', 'https://site.test/plugins/tool-kits/'); define('TK_VERSION', 'test'); define('TK_MINIMUM_PHP', '7.4');
$options = array(); $transients = array(); $actions = array(); $reads = 0; $requests = array(); $checks = 0;
$capable = true; $manageable = true; $licensed = true; $nonce = true; $user = 7; $wp_version = '6.6'; $cron = array(); $as_initialized = false; $failed_actions = array(); $plugins = array(); $theme_headers = array(); $http_status = 200; $profile_reply = true; $url_valid = false;
error_reporting(E_ALL);
set_error_handler(static function ($level, $message) { if (error_reporting() & $level) { throw new RuntimeException($message); } return false; });
function verify_health($condition, $message) { if (!$condition) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
class HealthReply extends RuntimeException { public $data; public $status; public $success; public function __construct($data, $status, $success) { $this->data = $data; $this->status = $status; $this->success = $success; } }
function wp_send_json_success($data) { throw new HealthReply($data, 200, true); }
function wp_send_json_error($data, $status = 400) { throw new HealthReply($data, $status, false); }
function current_user_can($cap) { return $GLOBALS['capable']; }
function tk_toolkits_can_manage() { return $GLOBALS['manageable']; }
function tk_license_features_enabled() { return $GLOBALS['licensed']; }
function check_ajax_referer($action) { verify_health($action === 'tk_health_center', 'Nonce action mismatch'); if (!$GLOBALS['nonce']) { wp_send_json_error(array('message' => 'Bad nonce'), 403); } }
function get_current_user_id() { return $GLOBALS['user']; }
function get_option($key, $default = false) { $GLOBALS['reads']++; return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { verify_health(strpos($key, 'tk_health_center_') === 0 && $autoload === false, 'Non-health option changed or autoload enabled'); $GLOBALS['options'][$key] = $value; return true; }
function add_option($key, $value, $deprecated = '', $autoload = null) { if (isset($GLOBALS['options'][$key])) { return false; } return update_option($key, $value, $autoload); }
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function get_site_option($key, $default = false) { return $default; }
function get_transient($key) { $GLOBALS['reads']++; return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { verify_health(strpos($key, 'tk_health_center_') === 0 && $ttl <= 600, 'Unbounded or unrelated scan state'); $GLOBALS['transients'][$key] = $value; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); }
function add_action($name, $callback, $priority = 10, $args = 1) { $GLOBALS['actions'][$name][] = $callback; }
function add_filter($name, $callback, $priority = 10, $args = 1) { add_action($name, $callback, $priority, $args); }
function home_url($path = '/') { return 'https://site.test' . $path; }
function admin_url($path = '') { return 'https://site.test/wp-admin/' . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_http_validate_url($url) { return $GLOBALS['url_valid'] ? $url : false; }
function wp_using_ext_object_cache() { return false; }
function _get_cron_array() { return $GLOBALS['cron']; }
function did_action($name) { return $GLOBALS['as_initialized']; }
function as_get_scheduled_actions($args, $format) { verify_health($args['status'] === 'failed' && $args['per_page'] === 1001 && $format === 'ids', 'Unbounded failure lookup'); return $GLOBALS['failed_actions']; }
function get_plugins() { $GLOBALS['plugin_reads'] = ($GLOBALS['plugin_reads'] ?? 0) + 1; if (!empty($GLOBALS['plugin_read_error'])) { throw new RuntimeException('Fixture plugin metadata failure'); } return $GLOBALS['plugins']; }
function wp_get_theme() { return new class { public function get($key) { return $GLOBALS['theme_headers'][$key] ?? ''; } }; }
function wp_safe_remote_get($url, $args) {
    verify_health($url === home_url('/') && $args['redirection'] === 0 && $args['sslverify'] && $args['cookies'] === array() && $args['headers']['Cookie'] === 'tk_health_center_session=1', 'Unsafe profile request');
    $GLOBALS['requests'][] = $args; $id = hash('sha256', $args['headers']['X-Tool-Kits-Health-Probe']);
    // Different from the admin request's 999 queries: only the profiled request is accepted.
    set_transient('tk_health_center_profile_' . $id, array('completed' => time(), 'queries' => 187, 'slow_http' => 3), 120);
    return array('code' => $GLOBALS['http_status'], 'headers' => array('x-tool-kits-health-probe' => $GLOBALS['profile_reply'] ? $id : 'cached'));
}
function is_wp_error($response) { return false; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_header($response, $key) { return $response['headers'][$key] ?? ''; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function wp_date($format, $timestamp) { return gmdate($format, $timestamp); }
function wp_create_nonce($action) { return 'fixture'; }
function add_submenu_page(...$args) { $GLOBALS['menu'] = $args; }
function wp_add_dashboard_widget(...$args) { $GLOBALS['widget'] = $args; }
function tk_asset_version($file) { return 'fixture'; }
function wp_enqueue_style(...$args) { $GLOBALS['enqueued'][] = $args; }
function wp_enqueue_script(...$args) { $GLOBALS['enqueued'][] = $args; }
$wpdb = new class { public $num_queries = 999; public function db_server_info() { return '8.0.42 MySQL'; } };
require dirname(__DIR__) . '/includes/health-center.php';
function health_request($action, $data = array(), $method = 'POST') {
    $_POST = $data; $_SERVER['REQUEST_METHOD'] = $method;
    try { call_user_func('tk_health_center_' . $action . '_ajax'); throw new RuntimeException('No JSON reply'); }
    catch (HealthReply $reply) { return $reply; }
}
function find_health(array $report, string $label): array { foreach ($report['checks'] as $check) { if ($check['label'] === $label) { return $check; } } throw new RuntimeException('Missing check: ' . $label); }
verify_health($reads === 0 && !$actions, 'Module automatically profiles or reads probe data on ordinary requests');
tk_health_center_init();
verify_health(isset($actions['wp_ajax_tk_health_center_start']) && !isset($actions['wp_ajax_nopriv_tk_health_center_start']), 'Unauthenticated endpoint registered');
tk_health_center_menu(); tk_health_center_widget();
verify_health($menu[4] === 'tool-kits-health-center' && $widget[0] === 'tk_health_center_widget', 'Menu/widget absent');
ob_start(); tk_health_center_render(); $empty = ob_get_clean();
verify_health(strpos($empty, 'No scan results yet') !== false && strpos($empty, '82/100') === false && !$requests && !$options, 'Widget scanned or invented metrics before manual run');
tk_health_center_assets('plugins.php'); verify_health(empty($enqueued), 'Assets loaded on unrelated screen');
tk_health_center_assets('index.php'); verify_health(count($enqueued) === 2, 'Dashboard assets missing');
foreach (array('capable', 'manageable', 'licensed', 'nonce') as $flag) { $GLOBALS[$flag] = false; verify_health(health_request('start')->status === 403 && !$options && !$requests, 'Unauthorized scan: ' . $flag); $GLOBALS[$flag] = true; }
verify_health(health_request('start', array(), 'GET')->status === 405, 'GET started scan');
verify_health(tk_health_scores(array())['overall'] === null, 'Empty scan has a score');
$score = tk_health_scores(array(array('category' => 'Security', 'status' => 'critical'), array('category' => 'Performance', 'status' => 'passed'), array('category' => 'SEO', 'status' => 'unknown')));
verify_health($score['overall'] === 50 && $score['categories']['Security'] === 0 && $score['categories']['SEO'] === null && $score['counts']['unknown'] === 1, 'Unknown/zero scores averaged incorrectly');
verify_health(tk_health_memory_bytes('256M') === 268435456 && tk_health_memory_bytes('1G') === 1073741824 && tk_health_memory_bytes('-1') === -1 && tk_health_memory_bytes('oops') === null, 'Memory conversion incorrect');
verify_health(tk_health_metric_status(null, 1, 2) === 'unknown' && tk_health_metric_status(2.4, .8, 1.8) === 'critical', 'Thresholds or unknown values incorrect');
$start = health_request('start'); $token = $start->data['token'];
verify_health($start->success && !$requests && !isset($options['tk_health_center_report']), 'Starting scan replaced last report or performed HTTP');
verify_health(health_request('start')->status === 409, 'Concurrent scan accepted');
$user = 8; verify_health(health_request('step', array('token' => $token))->status === 409 && !$requests, 'Cross-user scan session accepted'); $user = 7;
verify_health(health_request('step', array('token' => array()))->status === 400, 'Array token accepted');
$options['tk_health_center_step_lock'] = time(); verify_health(health_request('step', array('token' => $token))->status === 409, 'Concurrent step accepted'); unset($options['tk_health_center_step_lock']);
$cron = array(time() - 1200 => array('demo' => array('a' => array(), 'b' => array())), time() + 1200 => array('future' => array('c' => array())));
$plugins = array('demo/plugin.php' => array('Name' => '<script>Bad</script>', 'RequiresPHP' => '99.0', 'RequiresWP' => '6.0')); $options['active_plugins'] = array('demo/plugin.php');
verify_health(health_request('step', array('token' => $token))->success && !$requests, 'Local stage sent HTTP');
$state = $transients['tk_health_center_job_' . $token];
verify_health($state['report']['performance']['overdue_cron'] === 2 && $state['report']['performance']['failed_jobs'] === null, 'Overdue cron mislabeled as failed');
verify_health(find_health($state['report'], 'Plugin/theme declared requirements')['status'] === 'critical', 'Incompatible plugin requirement missed');
verify_health(health_request('step', array('token' => $token))->success, 'Public-stage unavailable handling failed');
verify_health(health_request('step', array('token' => $token))->success && count($requests) === 1, 'Profile stage not manual or duplicated');
$finish = health_request('step', array('token' => $token, 'full' => '1')); $saved = $options['tk_health_center_report'];
verify_health(count(tk_health_center_history()) === 1 && tk_health_center_history()[0]['id'] === $saved['scan_id'], 'Completed full scan was not archived');
verify_health(strpos(serialize(tk_health_center_history()), $token) === false, 'Scan probe/session token leaked into history');
$full_scan_history = $options['tk_health_center_history'];
verify_health($finish->success && $finish->data['done'] && $saved['performance']['queries'] === 187 && $saved['performance']['slow_http'] === 3 && $saved['performance']['ttfb'] === null, 'Profile numbers fabricated from AJAX or failed TTFB');
verify_health(!isset($options['tk_health_center_lock'], $options['tk_health_center_step_lock']) && !$transients, 'Finished scan leaked lock/probe/job');
verify_health(strpos($finish->data['html'], '<script>Bad') === false && strpos($finish->data['html'], '&lt;script&gt;Bad') !== false, 'Report metadata not escaped');
$snapshot = $options; $request_count = count($requests); ob_start(); tk_health_center_render(); tk_health_center_render(true); ob_end_clean();
verify_health($options === $snapshot && count($requests) === $request_count, 'Opening saved widget/page rescanned');
$state = array('report' => array('checks' => array(), 'performance' => array('queries' => null, 'slow_http' => null)));
$profile_reply = false; tk_health_center_request_profile($state); tk_health_center_finish_profile($state);
verify_health($state['report']['performance']['queries'] === null && find_health($state['report'], 'Slow outbound HTTP requests')['status'] === 'unknown', 'Unverified cache/proxy profile reported numbers');
$report = array('checks' => array(), 'performance' => array()); $as_initialized = true; $failed_actions = range(1, 1001);
tk_health_center_local($report);
verify_health($report['performance']['failed_jobs'] === 1000 && $report['performance']['failed_jobs_limited'], 'Failed job sample not labeled as lower bound');
$response = array('ttfb' => 2.4, 'status' => 200, 'headers' => array('content-type' => 'text/html', 'cache-control' => 'public, max-age=2592000', 'x-content-type-options' => 'nosniff', 'content-security-policy' => "frame-ancestors 'self'", 'referrer-policy' => 'strict-origin', 'x-tool-kits-cache' => 'HIT-EARLY'), 'body' => '<html><head><title>Site</title><meta name="description" content="Hello"><link rel="canonical" href="https://site.test/"></head></html>');
$report = array('checks' => array(), 'performance' => array()); tk_health_analyze_public($report, $response);
verify_health($report['performance']['ttfb'] === 2.4 && find_health($report, 'HTML browser cache lifetime')['status'] === 'critical' && find_health($report, 'Homepage TTFB')['status'] === 'critical', 'Actual TTFB/cache issue not detected');
verify_health(find_health($report, 'Homepage title')['status'] === 'passed' && find_health($report, 'Frame embedding protection')['status'] === 'passed', 'Valid SEO/security check failed');
$response['body'] = '<title>A</title><title>B</title><meta name="robots" content="noindex">'; $response['headers']['cache-control'] = 'no-cache, max-age=0';
$report = array('checks' => array(), 'performance' => array()); tk_health_analyze_public($report, $response);
verify_health(find_health($report, 'Homepage title')['status'] === 'warning' && find_health($report, 'Search indexing')['status'] === 'warning' && find_health($report, 'HTML browser cache lifetime')['status'] === 'passed', 'Duplicate title, robots or cache precedence missed');
foreach (array('', '<title>truncated') as $body) { $response['body'] = $body; $response['truncated'] = $body !== ''; $report = array('checks' => array(), 'performance' => array()); tk_health_analyze_public($report, $response); verify_health(find_health($report, 'Homepage title')['status'] === 'unknown', 'Empty/truncated HTML treated as complete'); }
$response['status'] = 500; $report = array('checks' => array(), 'performance' => array()); tk_health_analyze_public($report, $response);
verify_health($report['performance']['ttfb'] === null && find_health($report, 'Public homepage response')['status'] === 'critical', 'Error response fabricated a successful metric');
// A valid-looking but inactive probe must not attach profile hooks.
$actions = array(); $_SERVER = array('REQUEST_METHOD' => 'GET', 'HTTP_X_TOOL_KITS_HEALTH_PROBE' => str_repeat('a', 48), 'REQUEST_URI' => '/', 'HTTP_HOST' => 'site.test');
tk_health_center_maybe_profile(); verify_health(!$actions, 'Unsigned/expired probe activated profiler');
$id = hash('sha256', $_SERVER['HTTP_X_TOOL_KITS_HEALTH_PROBE']); $transients['tk_health_center_probe_' . $id] = array('expires' => time() + 120, 'url' => home_url('/'));
$_SERVER['REQUEST_URI'] = '/wp-admin/'; tk_health_center_maybe_profile(); verify_health(!$actions, 'Probe activated outside homepage');
$_SERVER['REQUEST_URI'] = '/'; $licensed = false; tk_health_center_maybe_profile(); verify_health(!$actions, 'Inactive license activated profiler'); $licensed = true;
tk_health_center_maybe_profile(); verify_health(defined('DONOTCACHEPAGE') && DONOTCACHEPAGE && isset($actions['pre_http_request'], $actions['http_api_debug'], $actions['shutdown']), 'Manual probe not instrumented/cache bypassed');
$pre = $actions['pre_http_request'][0]; $debug = $actions['http_api_debug'][0];
$pre(false, array(), 'https://api.test/slow'); usleep(1050000); $debug(array(), 'response', 'curl', array(), 'https://api.test/slow');
$pre(array('cached'), array(), 'https://api.test/cached'); $debug(array(), 'response', 'curl', array(), 'https://api.test/cached');
http_response_code(200); $wpdb->num_queries = 192; $actions['shutdown'][0]();
$profile = $transients['tk_health_center_profile_' . $id]; verify_health($profile['queries'] === 192 && $profile['slow_http'] === 1, 'Profiler did not capture real request-local SQL/HTTP counts');
require dirname(__DIR__) . '/includes/cache.php';
verify_health(tk_page_cache_should_ignore_option_change('tk_health_center_report') && tk_page_cache_should_ignore_option_change('tk_health_center_lock') && tk_page_cache_should_ignore_option_change('tk_health_center_step_lock'), 'Manual scan purges page cache while measuring it');
verify_health(!TK_Page_Cache_Runtime::request_allowed(array(), array('REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'site.test', 'REQUEST_URI' => '/'), array('tk_health_center_session' => '1')), 'Manual profile can receive early cache HTML');
$priority_report = $saved;
$priority_report['completed'] = time() - 86401;
$priority_report['checks'] = array(
    array('category' => 'Security', 'label' => 'Warning first', 'status' => 'warning', 'detail' => 'Warning'),
    array('category' => 'Security', 'label' => 'Critical one', 'status' => 'critical', 'detail' => 'Critical'),
    array('category' => 'Security', 'label' => 'Critical two', 'status' => 'critical', 'detail' => 'Critical'),
    array('category' => 'Performance', 'label' => 'Warning second', 'status' => 'warning', 'detail' => 'Warning'),
);
$before_options = $options; $before_requests = count($requests);
ob_start(); tk_health_center_report_html($priority_report, false); $priority_html = ob_get_clean();
verify_health(substr_count($priority_html, 'tk-health-finding-status ') === 3, 'Priority actions must be capped at three');
verify_health(strpos($priority_html, 'Critical two') < strpos($priority_html, 'Warning first'), 'Critical findings must appear before warnings');
verify_health(strpos($priority_html, 'Scan again') !== false, 'Old report must show scan-again notice');
verify_health(strpos($priority_html, '#tk-health-check-1') !== false, 'Priority action must link to saved check details');
verify_health($options === $before_options && count($requests) === $before_requests, 'Priority/freshness rendering must be read-only without HTTP');
$details_report = $saved;
$details_report['checks'] = array(
    array('category' => 'Security', 'label' => 'Dashboard file editor', 'status' => 'warning', 'detail' => 'Editor enabled'),
    array('category' => 'Security', 'label' => 'Content Security Policy', 'status' => 'warning', 'detail' => 'Header missing'),
    array('category' => 'Security', 'label' => 'MIME sniffing protection', 'status' => 'critical', 'detail' => 'Header invalid'),
    array('category' => 'SEO', 'label' => 'Meta description', 'status' => 'warning', 'detail' => 'Description missing'),
    array('category' => 'Performance', 'label' => 'HTML browser cache lifetime', 'status' => 'critical', 'detail' => 'Long cache'),
    array('category' => 'SEO', 'label' => 'Canonical URL', 'status' => 'passed', 'detail' => 'Present'),
    array('category' => 'Performance', 'label' => 'Homepage TTFB', 'status' => 'unknown', 'detail' => 'Unavailable'),
    array('category' => 'Performance', 'label' => 'PHP memory limit', 'status' => 'warning', 'detail' => 'Change through hosting'),
);
$before_options = $options; $before_requests = count($requests);
ob_start(); tk_health_center_report_html($details_report, true); $details_html = ob_get_clean();
verify_health(substr_count($details_html, 'tk-health-check-status ') === count($details_report['checks']), 'Every Check Details row needs a badge');
foreach (array('passed' => 'Passed', 'warning' => 'Warning', 'critical' => 'Critical', 'unknown' => 'Not measured') as $status => $label) {
    verify_health(strpos($details_html, 'tk-health-check-status tk-health-' . $status . '">' . $label . '</span>') !== false, 'Status badge missing: ' . $status);
}
foreach (array('tool-kits-guard#section-file_editor', 'tool-kits-guard#section-csp', 'tool-kits-guard#section-headers', 'tool-kits-seo#global-content', 'tool-kits-cache#page') as $target) {
    verify_health(strpos($details_html, $target) !== false, 'Missing settings target: ' . $target);
}
verify_health(substr_count($details_html, 'class="tk-health-setting"') === 5, 'Only actionable warning/critical checks get settings links');
verify_health(tk_health_center_settings_link(array('label' => 'Persistent object cache', 'status' => 'warning')) === array(), 'Object-cache flush is not a setting to enable a backend');
verify_health(tk_health_center_settings_link(array('label' => 'Search indexing', 'status' => 'warning'))['url'] === admin_url('admin.php?page=tool-kits-seo') . '#indexing', 'Indexing settings target incorrect');
verify_health(strpos(tk_health_center_status_badge('bad"><script>'), '<script>') === false, 'Invalid badge status not normalized');
$options['tk_options'] = array('license_type' => 'local');
verify_health(tk_health_center_settings_link($details_report['checks'][0]) === array(), 'Local license must not link to unavailable Hardening');
verify_health(tk_health_center_settings_link($details_report['checks'][3]) === array(), 'Local license must not link to unavailable SEO');
verify_health(tk_health_center_settings_link($details_report['checks'][4]) !== array(), 'Local license still has Cache settings');
$options = $before_options;
$capable = false; verify_health(tk_health_center_settings_link($details_report['checks'][0]) === array(), 'Unauthorized settings link exposed'); $capable = true;
verify_health($options === $before_options && count($requests) === $before_requests, 'Check Details rendering must not update options or make HTTP requests');
// Contextual thresholds and optional recommendations must not fabricate a pass.
$prior_transients = $transients;
$optional = array('checks' => array(), 'performance' => array()); tk_health_center_local($optional, 'persistent-object-cache');
verify_health(count($optional['checks']) === 1 && $optional['checks'][0]['status'] === 'info', 'Optional object cache must be informational');
$optional_score = tk_health_scores($optional['checks']);
verify_health($optional_score['overall'] === null && $optional_score['counts']['info'] === 1, 'Recommendation must not count as a measured pass');
$required = array('policy' => array_merge(tk_health_center_policy_defaults(), array('object_cache_required' => true)), 'checks' => array(), 'performance' => array());
tk_health_center_local($required, 'persistent-object-cache');
verify_health($required['checks'][0]['status'] === 'warning', 'Required object cache must warn when inactive');
foreach ($saved['checks'] as $check) {
    verify_health(isset($check['evidence']['current'], $check['evidence']['expected'], $check['evidence']['source'], $check['checked_at'], $check['id']), 'Every new check must capture evidence and a measurement time');
}
$policy = tk_health_center_policy_defaults();
foreach (array(array('ttfb_warning' => 2, 'ttfb_critical' => 1), array('queries_warning' => array()), array('memory_warning' => 64, 'memory_critical' => 128), array('ttfb_warning' => 'NaN'), array('object_cache_required' => 'unexpected')) as $invalid) {
    try { tk_health_center_validate_policy(array_merge($policy, $invalid)); verify_health(false, 'Invalid policy accepted'); }
    catch (InvalidArgumentException $error) { verify_health(true, 'Invalid policy rejected'); }
}
$new_policy = array_merge($policy, array('ttfb_warning' => 1.1, 'ttfb_critical' => 2.1, 'queries_warning' => 200, 'queries_critical' => 500));
$options['tk_health_center_report'] = $saved;
$before_report = $options['tk_health_center_report'];
verify_health(health_request('settings', $new_policy + array('full' => '1'))->success, 'Valid thresholds did not save');
verify_health($options['tk_health_center_report'] === $before_report && $options['tk_health_center_settings'] === $new_policy, 'Saving policy must not reclassify saved results');
verify_health(tk_health_center_freshness($saved)['stale'], 'Changed policy must mark old full scan stale');
$http_fixture = array('ttfb' => 1.2, 'status' => 200, 'headers' => array('content-type' => 'text/html', 'x-content-type-options' => '<script>header</script>'), 'body' => '<html><head><title>Measured</title><meta name="description" content="Actual description"><link rel="canonical" href="https://site.test/"></head></html>');
$measured = array('checks' => array(), 'performance' => array()); tk_health_analyze_public($measured, $http_fixture);
verify_health(find_health($measured, 'Homepage TTFB')['status'] === 'warning' && strpos(find_health($measured, 'Homepage TTFB')['evidence']['expected'], '1.1') !== false, 'Custom TTFB thresholds not used/captured');
$with_headers = $saved; $with_headers['checks'] = $measured['checks'];
ob_start(); tk_health_center_report_html($with_headers, true); $evidence_html = ob_get_clean();
verify_health(strpos($evidence_html, '<script>header</script>') === false && strpos($evidence_html, '&lt;script&gt;header&lt;/script&gt;') !== false, 'Observed evidence must be escaped');

// Legacy saved rows can be rechecked without marking the entire snapshot fresh.
$baseline = $saved; $baseline['completed'] = time() - 3600;
foreach ($baseline['checks'] as &$check) { unset($check['id'], $check['evidence'], $check['checked_at']); } unset($check);
$options['tk_health_center_report'] = $baseline;
$plugin_reads = $GLOBALS['plugin_reads']; $before_requests = count($requests);
$recheck = health_request('start', array('check' => 'dashboard-file-editor')); $recheck_token = $recheck->data['token'];
verify_health($recheck->success && $recheck->data['steps'] === 2, 'Individual recheck must use two bounded stages');
verify_health(health_request('settings', $new_policy)->status === 409, 'Policy changed during an active scan');
verify_health(health_request('step', array('token' => $recheck_token))->success && $options['tk_health_center_report'] === $baseline, 'Measuring replaced the report before completion');
$local_state = $transients['tk_health_center_job_' . $recheck_token];
verify_health(count($local_state['report']['checks']) === 1 && $GLOBALS['plugin_reads'] === $plugin_reads && count($requests) === $before_requests, 'Local recheck enumerated plugins or requested HTTP');
$done = health_request('step', array('token' => $recheck_token, 'full' => '1'));
$updated = $options['tk_health_center_report'];
verify_health($done->success && $done->data['done'] && $updated['completed'] === $baseline['completed'] && !empty($updated['last_recheck']), 'Individual recheck incorrectly refreshed full-scan timestamp');
foreach ($baseline['checks'] as $index => $check) {
    if ($check['label'] === 'Dashboard file editor') { verify_health(!empty($updated['checks'][$index]['evidence']) && !empty($updated['checks'][$index]['checked_at']), 'Selected evidence was not replaced'); }
    else { verify_health($check === $updated['checks'][$index], 'Individual recheck changed an unrelated row'); }
}
verify_health($updated['performance'] === $baseline['performance'] && $updated['server'] === $baseline['server'], 'Local recheck rewrote full-scan metric/server snapshots');
verify_health(health_request('start', array('check' => 'https://external.test/'))->status === 400 && health_request('start', array('check' => array()))->status === 400, 'Client-controlled target accepted');

// Profile rechecks use current thresholds and update only the selected metric.
$before = $options['tk_health_center_report']; $before_requests = count($requests);
$profile_reply = true;
$recheck = health_request('start', array('check' => 'homepage-database-query-count')); $recheck_token = $recheck->data['token'];
verify_health(health_request('step', array('token' => $recheck_token))->success && count($requests) === $before_requests + 1, 'Profile recheck must make exactly one verified profile request');
verify_health(health_request('step', array('token' => $recheck_token))->data['done'], 'Profile recheck did not finish');
$updated = $options['tk_health_center_report'];
verify_health(find_health($updated, 'Homepage database query count')['status'] === 'passed' && strpos(find_health($updated, 'Homepage database query count')['evidence']['current'], '187') !== false, 'Query recheck ignored thresholds or actual measurement');
verify_health($updated['completed'] === $before['completed'] && find_health($updated, 'Slow outbound HTTP requests') === find_health($before, 'Slow outbound HTTP requests'), 'Query recheck overwrote unrelated HTTP results');
$profile_reply = false;
$recheck = health_request('start', array('check' => 'homepage-database-query-count')); $recheck_token = $recheck->data['token'];
health_request('step', array('token' => $recheck_token)); health_request('step', array('token' => $recheck_token));
verify_health(find_health($options['tk_health_center_report'], 'Homepage database query count')['status'] === 'unknown' && $options['tk_health_center_report']['performance']['queries'] === null, 'Failed profile recheck invented success/zero');
$before = $options['tk_health_center_report']; $before_requests = count($requests);
$recheck = health_request('start', array('check' => 'meta-description')); $recheck_token = $recheck->data['token'];
health_request('step', array('token' => $recheck_token)); health_request('step', array('token' => $recheck_token));
$updated = $options['tk_health_center_report'];
verify_health(find_health($updated, 'Meta description')['status'] === 'unknown' && find_health($updated, 'Homepage title') === find_health($before, 'Homepage title') && $updated['performance'] === $before['performance'], 'Unavailable public recheck overwrote unrelated evidence');
verify_health(count($requests) === $before_requests && !isset($options['tk_health_center_lock'], $options['tk_health_center_step_lock']) && $transients === $prior_transients, 'Recheck leaked locks, probes or sessions');
foreach (array('capable', 'manageable', 'licensed', 'nonce') as $flag) { $GLOBALS[$flag] = false; verify_health(health_request('settings', $new_policy)->status === 403, 'Unauthorized policy save accepted'); $GLOBALS[$flag] = true; }
verify_health(health_request('settings', $new_policy, 'GET')->status === 405, 'GET changed policy');
$required_policy = array_merge($new_policy, array('object_cache_required' => true));
verify_health(health_request('settings', $required_policy)->success, 'Required object-cache policy did not save');
$before = $options['tk_health_center_report'];
$recheck = health_request('start', array('check' => 'persistent-object-cache')); $recheck_token = $recheck->data['token'];
health_request('step', array('token' => $recheck_token)); health_request('step', array('token' => $recheck_token));
$updated = $options['tk_health_center_report'];
verify_health(find_health($updated, 'Persistent object cache')['status'] === 'warning' && find_health($updated, 'PHP memory limit') === find_health($before, 'PHP memory limit'), 'Object-cache requirement did not apply only to its rechecked row');
health_request('settings', $new_policy);
$recheck = health_request('start', array('check' => 'persistent-object-cache')); $recheck_token = $recheck->data['token'];
health_request('step', array('token' => $recheck_token)); health_request('step', array('token' => $recheck_token));
verify_health(find_health($options['tk_health_center_report'], 'Persistent object cache')['status'] === 'info', 'Optional object cache did not return to informational after recheck');
$before_options = $options; $before_requests = count($requests);
ob_start(); tk_health_center_render(true); ob_end_clean();
verify_health($options === $before_options && count($requests) === $before_requests, 'Opening thresholds/report caused writes or scans');
verify_health($options['tk_health_center_history'] === $full_scan_history, 'Rechecking rows or changing policy rewrote archived full scans');
$before = $options['tk_health_center_report'];
$plugin_read_error = true;
$failed_start = health_request('start');
verify_health(health_request('step', array('token' => $failed_start->data['token']))->status === 500, 'Failed full scan unexpectedly succeeded');
$plugin_read_error = false;
verify_health($options['tk_health_center_history'] === $full_scan_history && $options['tk_health_center_report'] === $before, 'Failed scan archived an incomplete result or replaced the report');
echo 'PASS: Health Center (' . $checks . " checks)\n";
