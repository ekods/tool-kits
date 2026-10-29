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
function delete_option($name) { $GLOBALS['writes'][] = '-' . $name; unset($GLOBALS['wp_options'][$name]); return true; }
function wp_using_ext_object_cache() { return false; }
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
check_load(!in_array('tk_monitoring_404_log', $GLOBALS['writes'], true), 'Each 404 rewrote the full 404 log instead of the small buffer');
for ($i = 0; $i < 20; $i++) {
    $_SERVER['REQUEST_URI'] = '/scan-' . $i . '.php';
    tk_monitoring_log_404();
}
$saved = get_option('tk_monitoring_404_log', array());
check_load(count($saved) === 20 && (int) $saved[md5('/missing/')]['count'] === 3 && count(get_option('tk_monitoring_404_pending')) === 2 && count(tk_monitoring_404_log()) === 21, 'Buffered 404s were not merged into the log every 20 paths: ' . count($saved));
$GLOBALS['wp_options']['tk_monitoring_404_pending'] = array('_since' => time() - 61, md5('/missing/') => array('path' => '/missing/', 'count' => 1, 'last' => time()));
$_SERVER['REQUEST_URI'] = '/missing/';
tk_monitoring_log_404();
check_load((int) get_option('tk_monitoring_404_log')[md5('/missing/')]['count'] === 5, 'A minute-old buffer was not flushed');
tk_monitoring_404_log_save(array());
check_load(tk_monitoring_404_log() === array() && !array_key_exists('tk_monitoring_404_pending', $GLOBALS['wp_options']), 'Clearing the 404 log kept buffered hits');

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

check_load(strpos($source('geo.php'), 'get_transient($cache_key)') !== false && strpos($source('geo.php'), 'add_action($hook, \'tk_seo_sitemap_flush_cache\')') !== false, 'ItemList queries posts on every page view again');
preg_match_all('/attachment_url_to_postid\(\$/',$source('image-optimizer.php'), $lookups);
check_load(count($lookups[0]) === 2, 'Image Optimizer calls attachment_url_to_postid() outside the cached lookup and the admin cleanup');

check_load(strpos($source('security-captcha.php'), "    tk_captcha_cleanup_claims();\n    set_transient(\n        'tk_captcha_' . \$token,") !== false && strpos($source('security-captcha.php'), "esc_like('_transient_tk_captcha_')") !== false, 'Expired CAPTCHA challenges pile up until the daily core cleanup again');
$main = file_get_contents(dirname(__DIR__) . '/tool-kits.php');
check_load(strpos($main, "'assets/admin-menu.css'") !== false && strpos($main, "in_array(\$hook, array('index.php', 'post.php', 'post-new.php'), true)") !== false, 'admin.css loads on every wp-admin screen again');
check_load(strpos(file_get_contents(dirname(__DIR__) . '/assets/admin.css'), 'tk-submenu') === false, 'Menu rules are back in admin.css instead of admin-menu.css');

// Public page views skip admin-only modules. Nothing that stays loaded may call into them, apart from these
// reviewed callers that only run on admin screens or check function_exists() first.
$root = dirname(__DIR__);
preg_match('/\$tk_admin_only_modules = array\((.*?)\);/s', file_get_contents($root . '/tool-kits.php'), $list);
preg_match_all("/'([a-z0-9-]+\.php)'/", $list[1] ?? '', $admin_modules);
check_load(count($admin_modules[1]) >= 10, 'Admin-only module list not found');
$admin_files = array();
$queue = $admin_modules[1];
while ($queue) {
    $file = array_shift($queue);
    if (isset($admin_files[$file])) continue;
    $admin_files[$file] = true;
    preg_match_all("/require_once __DIR__ \. '\/([a-z0-9-]+\.php)'/", $source($file), $children);
    $queue = array_merge($queue, $children[1]);
}
// Shared files that always-loaded modules also require stay loaded.
do {
    $before = count($admin_files);
    foreach (glob($root . '/includes/*.php') as $path) {
        if (isset($admin_files[basename($path)])) continue;
        preg_match_all("/require_once __DIR__ \. '\/([a-z0-9-]+\.php)'/", file_get_contents($path), $children);
        foreach ($children[1] as $child) unset($admin_files[$child]);
    }
} while (count($admin_files) !== $before);
$functions = array();
foreach (array_keys($admin_files) as $file) {
    preg_match_all('/function\s+(tk_\w+)\s*\(/', $source($file), $defined);
    foreach ($defined[1] as $name) $functions[$name] = $file;
}
$allowed = array(
    'admin-menu.php:tk_render_db_tools_page', 'admin-menu.php:tk_render_theme_checker_page', 'admin-menu.php:tk_render_user_id_change_panel',
    'admin-menu.php:tk_render_optimization_page', 'admin-menu.php:tk_health_center_allowed', 'db-cleanup.php:tk_render_db_tools_page',
    'security-hardening.php:tk_html_scan_allowed', 'health-center-summary.php:tk_health_center_allowed',
);
$unexpected = array();
foreach (array_merge(glob($root . '/includes/*.php'), array($root . '/tool-kits.php')) as $path) {
    $file = basename($path);
    if (isset($admin_files[$file])) continue;
    $code = file_get_contents($path);
    foreach ($functions as $name => $owner) {
        if (preg_match('/(?<![\w$>:])' . $name . '\b/', $code) && !in_array($file . ':' . $name, $allowed, true) && !preg_match('/^tk_\w+_init$/', $name)) {
            $unexpected[] = $file . ' -> ' . $name . ' (' . $owner . ')';
        }
    }
}
check_load(!$unexpected, 'Always-loaded code calls admin-only modules: ' . implode(', ', $unexpected));

echo 'PASS: ' . $checks . " public-request load checks: buffered 404s outside tk_options, no tk_options writes for blocks/redirect hits, cached geolocation, tracking-free cache keys, guarded hot paths, admin-only modules isolated\n";
