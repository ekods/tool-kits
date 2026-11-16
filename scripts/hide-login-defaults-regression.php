<?php
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
$stored = array();
function get_option($key, $default = null) { return $GLOBALS['stored'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['stored'][$key] = $value; }
function add_action(...$args) {}
function add_filter(...$args) {}
function home_url($path = '/') { return 'https://example.test' . $path; }
require dirname(__DIR__) . '/includes/helpers.php';
tk_option_init_defaults();
$first = $stored['tk_options']['hide_login_slug'];
if (!preg_match('/^staff-[a-f0-9]{24}$/D', $first)) { throw new RuntimeException('New installations need a cryptographically random slug'); }
$stored = array(); tk_options_cache_invalidate();
tk_option_init_defaults();
if ($stored['tk_options']['hide_login_slug'] === $first) { throw new RuntimeException('Different installations reused a login slug'); }
$stored['tk_options']['hide_login_slug'] = 'existing-private-login'; tk_options_cache_invalidate();
tk_option_init_defaults();
if ($stored['tk_options']['hide_login_slug'] !== 'existing-private-login') { throw new RuntimeException('An upgrade rotated the existing login URL'); }
echo "PASS: random per-installation default slug; existing login URLs are preserved\n";
