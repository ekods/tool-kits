<?php
// Router for cache-http-regression.py; never usable as a normal web endpoint.
if (PHP_SAPI !== 'cli-server' || !getenv('TK_CACHE_TEST_ROOT')) { http_response_code(403); exit; }
define('ABSPATH', rtrim(getenv('TK_CACHE_TEST_ROOT'), '/') . '/');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('TK_VERSION', 'http-fixture');
$source = dirname(__DIR__);
if (is_file(WP_CONTENT_DIR . '/advanced-cache.php')) { include WP_CONTENT_DIR . '/advanced-cache.php'; }
// Early hits must exit above this point, before the simulated expensive boot.
file_put_contents(ABSPATH . 'boots.log', "boot\n", FILE_APPEND);
usleep(180000);
$fixture = json_decode((string) file_get_contents(ABSPATH . 'fixture.json'), true);
$options = array_merge(array('page_cache_enabled' => 1, 'page_cache_ttl' => 3600, 'page_cache_exclude_paths' => "/excluded\n", 'hardening_csp_strict_enabled' => 1), $fixture);
function tk_get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function tk_license_features_enabled() { return empty($GLOBALS['options']['unlicensed']); }
function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0755, true); }
function trailingslashit($path) { return rtrim($path, '/') . '/'; }
function is_multisite() { return false; }
function is_admin() { return strpos($_SERVER['REQUEST_URI'], '/wp-admin') === 0; }
function wp_doing_ajax() { return false; }
function is_feed() { return false; }
function is_preview() { return isset($_GET['preview']); }
function is_404() { return false; }
function is_user_logged_in() { foreach (array_keys($_COOKIE) as $name) { if (strpos($name, 'wordpress_logged_in_') === 0) { return true; } } return false; }
function is_ssl() { return false; }
function tk_csp_nonce_enabled() { return true; }
function tk_csp_nonce() { static $nonce; if ($nonce === null) { $nonce = TK_Page_Cache_Runtime::new_nonce(); } return $nonce; }
function tk_csp_nonce_attr() { return ' nonce="' . tk_csp_nonce() . '"'; }
function apply_filters($name, $value) {
    if ($name === 'tk_page_cache_csp_nonce' && strpos($_SERVER['REQUEST_URI'], '/theme') === 0) { return $GLOBALS['theme_nonce']; }
    return $value;
}
require $source . '/includes/cache.php';
require $source . '/includes/security-hardening.php';
tk_page_cache_early_sync();
header('X-Test-Bootstrap: loaded');
header('Content-Type: text/html; charset=UTF-8');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
tk_security_headers();
if (strpos($_SERVER['REQUEST_URI'], '/theme') === 0) {
    $theme_nonce = TK_Page_Cache_Runtime::new_nonce();
    header("Content-Security-Policy: default-src 'self'; script-src 'nonce-" . $theme_nonce . "'; style-src 'nonce-" . $theme_nonce . "'");
}
if ($_SERVER['REQUEST_URI'] === '/sets-cookie') { header('Set-Cookie: custom_dynamic=secret; HttpOnly'); }
if ($_SERVER['REQUEST_URI'] === '/json') { header('Content-Type: application/json'); }
if ($_SERVER['REQUEST_URI'] === '/private') { header('Cache-Control: private, no-store'); }
if ($_SERVER['REQUEST_URI'] === '/varies-cookie') { header('Vary: Cookie'); }
if ($_SERVER['REQUEST_URI'] === '/purge') { tk_page_cache_purge(); }
// Exercise both outside and inside buffer orderings used by themes/plugins.
if (strpos($_SERVER['REQUEST_URI'], '/outer') === 0 || strpos($_SERVER['REQUEST_URI'], '/theme') === 0) { tk_csp_start_buffer(); }
tk_page_cache_maybe_serve();
tk_page_cache_start_buffer();
if (strpos($_SERVER['REQUEST_URI'], '/inner') === 0) { tk_csp_start_buffer(); }
http_response_code(200);
if ($_SERVER['REQUEST_URI'] === '/json') { echo '{"ok":true}'; return; }
echo '<!doctype html><html><head><title>Cache fixture</title><style nonce="previous">body { color: rgb(12, 34, 56); }</style></head><body><h1>Public fixture</h1><script>window.fixtureExecuted = true; document.documentElement.dataset.scriptOne = "ok";</script><script nonce="previous">window.secondExecuted = true; document.documentElement.dataset.scriptTwo = "ok"; document.documentElement.dataset.cssApplied = getComputedStyle(document.body).color;</script></body></html>';
