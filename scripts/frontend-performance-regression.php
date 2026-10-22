<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
error_reporting(E_ALL);
set_error_handler(function ($level, $message) { throw new RuntimeException($message); });
define('ABSPATH', '/tmp/');
$hooks = array(); $allow_purge = false; $version_assets = false; $parsed = 0; $checks = 0;
function add_action($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][] = array($hook, $callback, $priority); }
function apply_filters($hook, $value, ...$args) {
    if ($hook === 'tk_page_cache_purge_for_meta_change') { return $GLOBALS['allow_purge']; }
    if ($hook === 'tk_assets_version_theme_assets') { return $GLOBALS['version_assets']; }
    return $value;
}
function is_admin() { return false; }
function wp_parse_url($url) { $GLOBALS['parsed']++; return parse_url($url); }
function get_stylesheet_directory_uri() { return 'https://example.test/wp-content/themes/theme'; }
function get_template_directory_uri() { return get_stylesheet_directory_uri(); }
function get_stylesheet_directory() { return '/not-used'; }
function get_template_directory() { return '/not-used'; }
function tk_license_features_enabled() { return true; }
function tk_get_option($name, $default = null) { return array('page_cache_enabled' => 1, 'lazy_load_enabled' => 1, 'lazy_load_eager_images' => 2)[$name] ?? $default; }
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } $GLOBALS['checks']++; echo 'PASS ' . $message . PHP_EOL; }
require dirname(__DIR__) . '/includes/cache.php';
require dirname(__DIR__) . '/includes/asset-optimization.php';
require dirname(__DIR__) . '/includes/lazy-load.php';
tk_page_cache_purge_on_meta_change(1, 10, '_integration_review_count', array());
check(!$hooks, 'derived metadata can opt out without purging the page cache');
$allow_purge = true;
tk_page_cache_purge_on_meta_change(1, 10, '_edit_lock', 'lock');
check(!$hooks, 'built-in internal metadata exclusion remains effective');
tk_page_cache_purge_on_meta_change(1, 10, 'product_price', '2000000');
tk_page_cache_purge_on_meta_change(2, 10, 'schedule_status', 'Open');
check($hooks === array(array('shutdown', 'tk_page_cache_flush_pending', PHP_INT_MAX)), 'real content changes still queue exactly one final purge');
$url = 'https://example.test/wp-content/themes/theme/main.min.css?ver=16.24';
check(tk_assets_theme_asset_version($url) === $url && $parsed === 0, 'theme release URLs skip parsing, hashing and transient lookups when opted out');
$version_assets = true;
$external = 'https://cdn.example.test/styles.css';
check(tk_assets_theme_asset_version($external) === $external && $parsed > 0, 'default asset processing remains enabled and leaves external assets intact');
$attr = tk_lazy_image_attributes(array('loading' => 'lazy'), null, null);
check($attr['loading'] === 'lazy' && !isset($attr['fetchpriority']), 'first explicitly lazy image is not promoted to high priority');
$attr = tk_lazy_image_attributes(array('loading' => 'eager', 'fetchpriority' => 'high'), null, null);
check($attr['loading'] === 'eager' && $attr['fetchpriority'] === 'high', 'explicit high priority hero remains eager');
$attr = tk_lazy_image_attributes(array('loading' => 'lazy', 'fetchpriority' => 'high'), null, null);
check($attr['loading'] === 'lazy' && $attr['fetchpriority'] === 'high', 'both explicit attributes remain owned by the theme');
$attr = tk_lazy_image_attributes(array(), null, null);
check($attr['loading'] === 'lazy' && $attr['fetchpriority'] === 'auto', 'unspecified later images still receive generic lazy defaults');
echo $checks . ' frontend performance checks passed.' . PHP_EOL;
