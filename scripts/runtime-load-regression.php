<?php
// php scripts/runtime-load-regression.php — public requests must not rewrite tk_options, call remote APIs or
// re-run file I/O that a previous request already settled.
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['wp_options'] = array();
$GLOBALS['writes'] = array();
$GLOBALS['tk'] = array('monitoring_404_enabled' => 1, 'firewall_log_enabled' => 1, 'seo_redirect_rules' => array());
function get_option($name, $default = false) { return array_key_exists($name, $GLOBALS['wp_options']) ? $GLOBALS['wp_options'][$name] : $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['writes'][] = $name; $GLOBALS['wp_options'][$name] = $value; return true; }
function add_option($name, $value, $deprecated = '', $autoload = 'yes') { if (array_key_exists($name, $GLOBALS['wp_options'])) return false; return update_option($name, $value); }
function tk_get_option($key, $default = null) { return $GLOBALS['tk'][$key] ?? $default; }
function tk_update_option($key, $value) { $GLOBALS['writes'][] = 'tk_options'; $GLOBALS['tk'][$key] = $value; }
function tk_get_options() { return $GLOBALS['tk']; }
function tk_options_cache_invalidate() {}
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function is_404() { return true; }
function tk_user_agent() { return 'bot'; }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)); }
function tk_seo_normalize_path($path) { return '/' . trim((string) $path, '/'); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function tk_security_alert_ip_location($ip, $allow_remote = true) { $GLOBALS['geo_remote'][] = $allow_remote; return 'Unknown'; }
function tk_security_events_record($event) { $GLOBALS['events'][] = $event; }
require dirname(__DIR__) . '/includes/monitoring-404-health.php';
require dirname(__DIR__) . '/includes/security-firewall.php';
require dirname(__DIR__) . '/includes/cache-runtime.php';

$checks = 0;
function check_load($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['checks']++; }

// 404s: own option, grouped by path without query strings, never tk_options.
$GLOBALS['tk']['security_404_scanner_paths'] = '';
foreach (array('/missing/?a=1', '/missing/?a=2', '/missing/') as $uri) {
    $_SERVER['REQUEST_URI'] = $uri;
    tk_monitoring_log_404();
}
$log = tk_monitoring_404_log();
check_load(!in_array('tk_options', $GLOBALS['writes'], true), '404 logging rewrote tk_options');
check_load(count($log) === 1 && (int) reset($log)['count'] === 3 && reset($log)['path'] === '/missing/', 'Query strings created separate 404 entries: ' . json_encode($log));

// Blocked requests: cached geolocation only, own option.
$GLOBALS['writes'] = array();
$_SERVER['REQUEST_METHOD'] = 'GET';
tk_firewall_log_event('Blocked user agent', '203.0.113.9');
check_load($GLOBALS['geo_remote'] === array(false), 'Blocked request looked up geolocation remotely');
check_load($GLOBALS['writes'] === array('tk_firewall_event_log') && count(tk_firewall_event_log()) === 1, 'Firewall log did not use its own option');

// Page-cache key ignores campaign/click parameters but keeps real query parameters.
check_load(TK_Page_Cache_Runtime::cache_uri('/a/?utm_source=x&gclid=1&fbclid=2&msclkid=3') === '/a/', 'Tracking parameters split the page cache');
check_load(TK_Page_Cache_Runtime::cache_uri('/a/?utm_medium=x&p=2') === '/a/?p=2', 'A real query parameter was dropped from the cache key');
$base = array('HTTPS' => 'on', 'HTTP_HOST' => 'site.example');
check_load(TK_Page_Cache_Runtime::key($base + array('REQUEST_URI' => '/a/')) === TK_Page_Cache_Runtime::key($base + array('REQUEST_URI' => '/a/?utm_campaign=z')), 'Early-cache key differs for a tracked URL');

// Source guards for paths that need WordPress to execute.
$source = function ($file) { return file_get_contents(dirname(__DIR__) . '/includes/' . $file); };
check_load(strpos($source('helpers.php'), '$license = tk_license_validate();') !== false && substr_count($source('helpers.php'), 'tk_license_validate(true)') === 1, 'Tool Kits requests force a remote license check again');
preg_match('/function tk_seo_redirect_maybe_handle\(\) \{.*?\n\}\n/s', $source('seo-optimization.php'), $handler);
check_load(!empty($handler[0]) && strpos($handler[0], 'tk_update_option') === false && strpos($handler[0], 'tk_seo_redirect_record_hit') !== false, 'A redirect hit rewrites tk_options again');
check_load(strpos($source('security-rate-limit.php'), "tk_update_option('rate_limit_blocked_ips'") === false, 'Blocked IPs are stored in tk_options again');
check_load(strpos($source('cache.php'), "'tk_page_cache_start_buffer', 0") !== false && strpos($source('minify.php'), "'tk_minify_start_buffer', 1") !== false, 'Minify wraps the page cache again');
check_load(strpos($source('cache.php'), "'wp_loaded', 'tk_page_cache_early_sync_request'") !== false, 'Early-cache sync runs its file I/O on every request again');
check_load(strpos($source('geo.php'), "add_action('wp_enqueue_scripts', 'tk_geo_enqueue_faq_styles')") === false, 'FAQ CSS loads on every page again');
check_load(strpos($source('seo-optimization.php'), "get_transient(\$cache_key)") !== false, 'Sitemap is rebuilt on every request again');

echo 'PASS: ' . $checks . " public-request load checks: no tk_options writes for 404s/blocks/redirect hits, cached geolocation, tracking-free cache keys, guarded hot paths\n";
