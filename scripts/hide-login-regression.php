<?php
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
define('WP_CONTENT_DIR', __DIR__ . '/fixtures/nonexistent-hide-login-content');
$options = array('hide_login_enabled' => 1, 'hide_login_slug' => 'staff-door-7b9c');
$base = ''; $staff = false; $logged_in = false; $woocommerce = true; $hooks = array(); $removed = array(); $checks = 0;
function tk_get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function tk_sanitize_slug($slug) { return preg_replace('/[^a-z0-9-]/', '', strtolower($slug)) ?: 'secure-login'; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function home_url($path = '/') { return 'https://example.test' . $GLOBALS['base'] . '/' . ltrim($path, '/'); }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function current_user_can($capability) { return $GLOBALS['staff']; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function add_action($hook, $callback, ...$args) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_filter($hook, $callback, ...$args) { add_action($hook, $callback); }
function remove_action($hook, $callback, ...$args) { $GLOBALS['removed'][] = array($hook, $callback); }
function wc_get_page_id($name) { return $GLOBALS['woocommerce'] ? 12 : -1; }
function wc_get_page_permalink($name) { return home_url('/my-account/'); }
function wc_get_account_endpoint_url($endpoint) { return home_url('/my-account/' . $endpoint . '/?_wpnonce=customer-logout-nonce'); }
function wc_lostpassword_url($fallback = '') { return home_url('/my-account/lost-password/'); }
function get_option($key, $default = false) { return $default; }
function wp_unslash($value) { return $value; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid' && $action === 'log-out'; }
function tk_license_features_enabled() { return false; }
function wp_json_encode($value) { return json_encode($value); }
function trailingslashit($value) { return rtrim($value, '/') . '/'; }
function delete_option($key) { $GLOBALS['deleted_options'][] = $key; }
require dirname(__DIR__) . '/includes/security-hide-login.php';
require dirname(__DIR__) . '/includes/cache.php';
function hide_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['checks']++; }

$_SERVER['REQUEST_URI'] = '/shop/?redirect_to=/staff-door-7b9c/';
$login = home_url('/wp-login.php');
hide_check(tk_hide_login_filter_site_url($login, 'wp-login.php', 'login') === $login, 'Public site_url leaked the admin slug');
hide_check(tk_hide_login_filter_login_url($login, '', false) === home_url('/my-account/'), 'Public customer login must use My Account');
hide_check(tk_hide_login_filter_register_url($login . '?action=register') === home_url('/my-account/'), 'Public registration must use My Account');
hide_check(tk_hide_login_filter_lostpassword_url($login . '?action=lostpassword', '') === home_url('/my-account/lost-password/'), 'Customer password recovery must use the account endpoint');
foreach (array('/my-account/', '/my-account/lost-password/?key=fixture', '/checkout/?redirect_to=wp-login.php') as $path) {
    $url = home_url($path);
    hide_check(tk_hide_login_filter_site_url($url, ltrim($path, '/'), 'login') === $url, 'Customer URL was rewritten: ' . $path);
    hide_check(tk_hide_login_filter_login_url($url, '', false) === $url, 'Native customer login URL was overwritten');
}
$logged_in = true;
hide_check(tk_hide_login_filter_login_url($login, '', false) === home_url('/my-account/'), 'A customer account must not reveal the staff URL');
hide_check(tk_hide_login_filter_logout_url($login . '?action=logout&_wpnonce=valid', '') === wc_get_account_endpoint_url('customer-logout'), 'Customer logout lost its native nonce endpoint');
$staff = true;
hide_check(tk_hide_login_filter_login_url($login . '?reauth=1#form', '', true) === home_url('/staff-door-7b9c/?reauth=1#form'), 'Staff reauthentication lost query or fragment');
$logged_in = $staff = false;
$_SERVER['REQUEST_URI'] = '/staff-door-7b9c/?action=lostpassword';
hide_check(tk_hide_login_filter_site_url($login . '?action=rp&key=fixture', 'wp-login.php?action=rp&key=fixture', 'login') === home_url('/staff-door-7b9c/?action=rp&key=fixture'), 'Known login/reset-password flow was broken');
$patterns = tk_cache_dynamic_path_patterns();
hide_check(in_array('/staff-door-7b9c/', $patterns, true) || in_array('/staff-door-7b9c', $patterns, true), 'Custom login can be served from the early page cache');
hide_check(!TK_Page_Cache_Runtime::request_allowed(array('dynamic_paths' => $patterns), array('REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.test', 'REQUEST_URI' => '/staff-door-7b9c/'), array()), 'Early cache accepted a private login request');
$base = '/subsite'; $_SERVER['REQUEST_URI'] = '/subsite/staff-door-7b9c/';
hide_check(tk_hide_login_is_slug_path('/subsite/staff-door-7b9c/'), 'Subdirectory login is not recognised');
hide_check(tk_hide_login_filter_login_url(home_url('/wp-login.php'), '', false) === home_url('/staff-door-7b9c/'), 'Subdirectory login target is wrong');
hide_check(in_array('/subsite/staff-door-7b9c/', tk_cache_dynamic_path_patterns(), true) || in_array('/subsite/staff-door-7b9c', tk_cache_dynamic_path_patterns(), true), 'Subdirectory login is cacheable');
hide_check(!tk_hide_login_is_slug_path('/subsite/my-account/'), 'Customer account matched the secret route');
$base = ''; $woocommerce = false; $_SERVER['REQUEST_URI'] = '/';
hide_check(tk_hide_login_filter_login_url($login, '', false) === $login, 'A public site without WooCommerce must not publish the slug');
$options['hide_login_enabled'] = 0;
hide_check(tk_hide_login_filter_login_url($login, '', false) === $login, 'Disabled Hide Login changed the login URL');
$options['hide_login_enabled'] = 1;
$before = $options; $fingerprint = tk_page_cache_early_fingerprint();
$options['hide_login_slug'] = 'different-private-path';
hide_check(tk_page_cache_early_fingerprint() !== $fingerprint, 'Changing the slug did not invalidate the cache snapshot fingerprint');
tk_page_cache_early_option_changed('tk_options', $before, $options);
hide_check(in_array('tk_page_cache_early_state', $GLOBALS['deleted_options'] ?? array(), true), 'Changing the slug kept a stale early-cache snapshot');
hide_check(in_array('tk_page_cache_early_sync', $hooks['shutdown'] ?? array(), true), 'Updated login exclusions are not published after settings save');
echo 'PASS: ' . $checks . " Hide Login URL/privacy, staff/customer, WooCommerce endpoints, disabled mode, subdirectory and cache checks\n";
