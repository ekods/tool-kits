<?php
// Separate processes exercise the real request classifier and SEO/GEO includes without wp-load or HTTP.
if (empty($argv[1])) {
    foreach (array('public', 'admin', 'ajax', 'cron', 'alternate-cron', 'rest', 'plain-rest', 'cli', 'xmlrpc') as $context) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($context), $status);
        if ($status !== 0) { exit($status); }
    }
    echo "PASS: public SEO/GEO skips eight editor/audit files; shared output and admin/AJAX/cron/REST/CLI/XML-RPC helpers remain available\n";
    exit;
}
define('ABSPATH', '/tmp/');
$context = $argv[1]; $hooks = array();
if ($context === 'cli') { define('WP_CLI', true); }
if ($context === 'xmlrpc') { define('XMLRPC_REQUEST', true); }
$_SERVER['REQUEST_URI'] = $context === 'rest' ? '/wp-json/wp/v2/pages' : '/example/';
if ($context === 'plain-rest') { $_GET['rest_route'] = '/wp/v2/pages'; }
if ($context === 'alternate-cron') { $_GET['doing_wp_cron'] = 'token'; }
function is_admin() { return in_array($GLOBALS['context'], array('admin', 'ajax'), true); }
function wp_doing_cron() { return $GLOBALS['context'] === 'cron'; }
function apply_filters($hook, $value) { return $value; }
function add_action($hook, $callback, ...$args) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_filter($hook, $callback, ...$args) { add_action($hook, $callback); }
function add_shortcode($name, $callback) {}
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function wp_strip_all_tags($value) { return strip_tags($value); }
$main = file_get_contents(dirname(__DIR__) . '/tool-kits.php');
preg_match('/^function tk_request_needs_admin_modules\(\).*?^\}/ms', $main, $classifier);
eval($classifier[0]);
require dirname(__DIR__) . '/includes/seo-optimization.php';
require dirname(__DIR__) . '/includes/geo.php';
$expected = $context !== 'public';
foreach (array('tk_seo_parse_rendered_audit', 'tk_seo_content_fix_ajax', 'tk_seo_content_checks_render', 'tk_seo_render_content_meta_box',
    'tk_seo_copy_locale_ajax', 'tk_seo_content_score_ajax', 'tk_geo_batch_start', 'tk_geo_schema_check_url_ajax') as $function) {
    if (function_exists($function) !== $expected) { throw new RuntimeException('Incorrect module scope: ' . $context . ' / ' . $function); }
}
foreach (array('tk_seo_content_defaults', 'tk_seo_post_social_image_url', 'tk_seo_social_preview_image', 'tk_seo_content_title_filter',
    'tk_seo_save_content_meta_box', 'tk_seo_content_slug_filter', 'tk_geo_ai_catalog_maybe_render', 'tk_geo_crawler_fix_start') as $function) {
    if (!function_exists($function)) { throw new RuntimeException('Shared helper missing: ' . $function); }
}
tk_seo_opt_init(); tk_geo_init();
foreach (array('template_redirect', 'wp_head', 'wp_robots', 'pre_get_document_title', 'wp_insert_post_data', 'save_post', 'wp_loaded', 'robots_txt') as $hook) {
    foreach ($hooks[$hook] ?? array() as $callback) {
        if (!is_callable($callback)) { throw new RuntimeException('Public hook has a missing callback: ' . $callback); }
    }
}
$html = '<html><head><title>Theme</title></head><body>Keep</body></html>';
$output = tk_seo_content_replace_html($html, array('title' => 'Page', 'description' => 'Description'), true);
if (strpos($output, '<title>Page</title>') === false || strpos($output, '<body>Keep</body>') === false) { throw new RuntimeException('Shared public output failed'); }
