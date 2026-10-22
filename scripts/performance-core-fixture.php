<?php
// Real WordPress Option/Hook APIs with in-memory SQL/cache adapters. No wp-load,
// wp-config, external HTTP, live database or installed plugins are executed.
$core = rtrim((string) getenv('TK_WP_ROOT'), '/');
if (!is_file($core . '/wp-includes/option.php')) {
    throw new RuntimeException('Set TK_WP_ROOT to an unpacked WordPress core directory.');
}
define('ABSPATH', $core . '/');
define('WPINC', 'wp-includes');
$owns_content = !defined('WP_CONTENT_DIR');
if ($owns_content) { define('WP_CONTENT_DIR', sys_get_temp_dir() . '/tk-perf-' . getmypid()); }
define('AUTH_KEY', 'isolated-performance-key');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);
define('TK_VERSION', '2.7.25');
$blog = 1;
$blogs = array();
$cache = array();
$reads = 0;
$http = 0;
$multisite = true;
$scheduled = array();
function get_current_blog_id() { return $GLOBALS['blog']; }
function is_multisite() { return $GLOBALS['multisite']; }
function switch_to_blog($id) { $GLOBALS['blogs'][] = $GLOBALS['blog']; $GLOBALS['blog'] = $id; }
function restore_current_blog() { $GLOBALS['blog'] = array_pop($GLOBALS['blogs']); }
function wp_installing() { return false; }
function wp_using_ext_object_cache() { return false; }
function is_admin() { return false; }
function is_user_logged_in() { return false; }
function wp_doing_ajax() { return false; }
function is_feed() { return false; }
function is_preview() { return false; }
function is_404() { return false; }
function is_ssl() { return true; }
function home_url($path = '/') { return 'https://example.test' . ($GLOBALS['blog'] === 1 ? '' : '/blog-' . $GLOBALS['blog']) . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_parse_str($value, &$result) { parse_str($value, $result); }
function urlencode_deep($value) { return is_array($value) ? array_map('urlencode_deep', $value) : urlencode($value); }
function sanitize_option($option, $value) { return apply_filters('sanitize_option_' . $option, $value, $option, $value); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_url($value) { return esc_attr($value); }
function trailingslashit($value) { return rtrim($value, '/\\') . '/'; }
function untrailingslashit($value) { return rtrim($value, '/\\'); }
function get_bloginfo($key) { return '6.9'; }
function get_stylesheet_directory_uri() { return 'https://example.test/theme'; }
function get_stylesheet_directory() { return WP_CONTENT_DIR . '/theme'; }
function get_template_directory_uri() { return get_stylesheet_directory_uri(); }
function get_template_directory() { return get_stylesheet_directory(); }
function wp_remote_get() { $GLOBALS['http']++; throw new RuntimeException('Unexpected synchronous HTTP'); }
function wp_next_scheduled($hook) { return $GLOBALS['scheduled'][$hook] ?? false; }
function wp_schedule_single_event($time, $hook) { $GLOBALS['scheduled'][$hook] = $time; return true; }
function wp_cache_get($key, $group = '', $force = false, &$found = null) {
    $found = isset($GLOBALS['cache'][$GLOBALS['blog']][$group][$key]);
    return $found ? $GLOBALS['cache'][$GLOBALS['blog']][$group][$key] : false;
}
function wp_cache_set($key, $value, $group = '', $ttl = 0) { $GLOBALS['cache'][$GLOBALS['blog']][$group][$key] = $value; return true; }
function wp_cache_add($key, $value, $group = '', $ttl = 0) { if (wp_cache_get($key, $group) !== false) { return false; } return wp_cache_set($key, $value, $group, $ttl); }
function wp_cache_delete($key, $group = '') { unset($GLOBALS['cache'][$GLOBALS['blog']][$group][$key]); return true; }
function wp_cache_get_multiple($keys, $group = '', $force = false) { $out = array(); foreach ($keys as $key) { $out[$key] = wp_cache_get($key, $group, $force); } return $out; }
function wp_cache_set_multiple($values, $group = '', $ttl = 0) { foreach ($values as $key => $value) { wp_cache_set($key, $value, $group, $ttl); } return true; }
require ABSPATH . WPINC . '/compat.php';
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/functions.php';
add_filter('pre_wp_load_alloptions', function() { return array(); });
add_filter('option_tk_options', function($value) { $GLOBALS['reads']++; return $value; });
class TK_Performance_DB {
    public $options = 'fixture_options';
    public $prefix = 'wp_';
    public $data = array();
    public $queries = 0;
    public $fail = false;
    public $cleanup_rows = 0;
    public function prepare($sql, ...$args) { return json_encode(array($sql, $args)); }
    public function get_row($query) {
        $this->queries++;
        list($sql, $args) = json_decode($query, true);
        $value = $this->data[get_current_blog_id()][$args[0]] ?? null;
        return $value === null ? null : (object) array('option_value' => $value, 'autoload' => 'off');
    }
    public function get_var($query) {
        $this->queries++;
        list($sql, $args) = json_decode($query, true);
        return strpos($sql, 'SHOW TABLES') === 0 ? $args[0] : 'off';
    }
    public function get_results($query) { $this->queries++; return array(); }
    public function query($query) {
        $this->queries++;
        if ($this->fail) { return false; }
        list($sql, $args) = json_decode($query, true);
        if (strpos($sql, 'DELETE FROM') === 0) {
            if (strpos($sql, 'LIMIT 1000') === false) { throw new RuntimeException('Unbounded cleanup'); }
            return $this->cleanup_rows;
        }
        $this->data[get_current_blog_id()][$args[0]] = $args[1];
        return 1;
    }
    public function update($table, $args, $where) {
        $this->queries++;
        if ($this->fail) { return false; }
        $this->data[get_current_blog_id()][$where['option_name']] = $args['option_value'];
        return 1;
    }
    public function delete($table, $where) {
        $this->queries++;
        unset($this->data[get_current_blog_id()][$where['option_name']]);
        return 1;
    }
}
$wpdb = new TK_Performance_DB();
function tk_perf_seed(array $options, $blog_id = 1) {
    $GLOBALS['wpdb']->data[$blog_id]['tk_options'] = serialize($options);
    unset($GLOBALS['cache'][$blog_id], $GLOBALS['tk_request_options'][$blog_id]);
    if ($blog_id === get_current_blog_id() && function_exists('tk_clear_request_options_cache')) { tk_clear_request_options_cache(); }
}
function tk_perf_remove_tree($path) {
    if (!is_dir($path)) { return; }
    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $file = $path . '/' . $entry;
        if (is_dir($file)) { tk_perf_remove_tree($file); } else { unlink($file); }
    }
    rmdir($path);
}
if ($owns_content) { register_shutdown_function(function() { tk_perf_remove_tree(WP_CONTENT_DIR); }); }
