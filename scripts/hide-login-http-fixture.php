<?php
if (PHP_SAPI !== 'cli-server' || getenv('TK_HIDE_LOGIN_FIXTURE') !== '1') { exit; }
define('ABSPATH', getenv('TK_HIDE_LOGIN_FIXTURE_ROOT') . '/');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
$options = array('hide_login_enabled' => ($_SERVER['HTTP_X_FIXTURE_HIDE_LOGIN'] ?? '1') === '1', 'hide_login_slug' => 'staff-door-7b9c');
$hooks = array();
$fixture_role = $_SERVER['HTTP_X_FIXTURE_ROLE'] ?? '';
$fixture_base = strpos($_SERVER['REQUEST_URI'], '/subsite/') === 0 ? '/subsite' : '';
function tk_get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function tk_sanitize_slug($slug) { return preg_replace('/[^a-z0-9-]/', '', strtolower($slug)) ?: 'secure-login'; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function home_url($path = '/') { return 'http://' . $_SERVER['HTTP_HOST'] . $GLOBALS['fixture_base'] . '/' . ltrim($path, '/'); }
function is_user_logged_in() { return $GLOBALS['fixture_role'] !== ''; }
function current_user_can($capability) { return $GLOBALS['fixture_role'] === 'staff'; }
function is_admin() { return preg_match('#/wp-admin(?:/|$)#', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) === 1; }
function wp_doing_ajax() { return basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) === 'admin-ajax.php'; }
function wp_unslash($value) { return $value; }
function tk_get_ip() { return '192.0.2.10'; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) {
    $GLOBALS['transients'][$key] = $value;
    if (strpos($key, 'tk_rl_lock_') === 0) { header('X-Fixture-Lock: 1'); }
}
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid' && $action === 'log-out'; }
function nocache_headers() { header('Cache-Control: no-store, no-cache, must-revalidate'); }
function status_header($code) { http_response_code($code); }
function wp_redirect($url) { header('Location: ' . $url, true, 302); }
function wp_safe_redirect($url) { wp_redirect($url); }
function add_rewrite_rule(...$args) {}
function add_action($hook, $callback, $priority = 10, ...$args) { $GLOBALS['hooks'][$hook][$priority][] = $callback; }
function add_filter(...$args) {}
function remove_action($hook, $callback, $priority = 10) {
    $GLOBALS['hooks'][$hook][$priority] = array_filter($GLOBALS['hooks'][$hook][$priority] ?? array(), function($registered) use ($callback) { return $registered !== $callback; });
}
function fixture_run($hook) {
    $priorities = array_keys($GLOBALS['hooks'][$hook] ?? array()); sort($priorities);
    foreach ($priorities as $priority) { foreach ($GLOBALS['hooks'][$hook][$priority] ?? array() as $callback) { $callback(); } }
}
function wc_get_page_id($page) { return 12; }
function wc_get_page_permalink($page) { return home_url('/my-account/'); }
function wc_get_account_endpoint_url($endpoint) { return home_url('/my-account/' . $endpoint . '/?_wpnonce=valid-customer'); }
function wc_lostpassword_url($default = '') { return home_url('/my-account/lost-password/'); }
function get_option($key, $default = null) { return $default; }
require getenv('TK_HIDE_LOGIN_SOURCE');
require dirname(getenv('TK_HIDE_LOGIN_SOURCE')) . '/security-rate-limit.php';
function wp_login_url() { return tk_hide_login_filter_login_url(tk_hide_login_filter_site_url(home_url('/wp-login.php'), 'wp-login.php', 'login'), '', false); }
function wp_strict_cross_origin_referrer() { echo '<meta name="referrer" content="strict-origin-when-cross-origin">'; }
function wp_redirect_admin_locations() {
    if (in_array(rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'), array('/login', '/login.php', '/subsite/login'), true)) {
        wp_redirect(wp_login_url()); exit;
    }
}
add_action('template_redirect', 'wp_redirect_admin_locations', 1000);
tk_hide_login_init();
tk_rate_limit_init();
fixture_run('init');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($path, '/wp-login.php') !== false) { require ABSPATH . 'wp-login.php'; exit; }
if (is_admin()) { fixture_run('admin_init'); }
fixture_run('template_redirect');
if (in_array(rtrim($path, '/'), array('/login', '/login.php', '/subsite/login', '/not-found'), true)) {
    status_header(404); echo 'Not found.'; exit;
}
header('Content-Type: application/json');
echo json_encode(array(
    'login' => wp_login_url(),
    'register' => tk_hide_login_filter_register_url(home_url('/wp-login.php?action=register')),
    'lostpassword' => tk_hide_login_filter_lostpassword_url(home_url('/wp-login.php?action=lostpassword'), ''),
    'logout' => tk_hide_login_filter_logout_url(home_url('/wp-login.php?action=logout&_wpnonce=valid'), ''),
    'account' => home_url('/my-account/'), 'checkout' => home_url('/checkout/'),
));
