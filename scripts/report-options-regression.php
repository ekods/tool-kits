<?php
// php scripts/report-options-regression.php — scan reports and admin logs live outside tk_options, so saving one
// does not rewrite every setting; existing data migrates on upgrade and imports cannot put reports back.
define('ABSPATH', __DIR__ . '/');
$GLOBALS['wp_options'] = array();
$GLOBALS['writes'] = array();
$GLOBALS['hooks'] = array();
function add_action($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$hook][] = $callback; return true; }
function add_filter($hook, $callback, $priority = 10, $args = 1) { return add_action($hook, $callback, $priority, $args); }
function do_action($hook, ...$args) { foreach ($GLOBALS['hooks'][$hook] ?? array() as $callback) { $callback(...$args); } }
function apply_filters($hook, $value) { return $value; }
function get_current_blog_id() { return 1; }
function get_option($name, $default = false) { return array_key_exists($name, $GLOBALS['wp_options']) ? unserialize($GLOBALS['wp_options'][$name]) : $default; }
function update_option($name, $value, $autoload = null) {
    $exists = array_key_exists($name, $GLOBALS['wp_options']);
    $GLOBALS['writes'][] = $name;
    $GLOBALS['wp_options'][$name] = serialize($value);
    do_action($exists ? 'updated_option' : 'added_option', $name);
    if ($name === 'tk_options') { do_action('update_option_tk_options'); }
    return true;
}
function add_option($name, $value, $deprecated = '', $autoload = 'yes') { return array_key_exists($name, $GLOBALS['wp_options']) ? false : update_option($name, $value); }
function is_admin() { return false; }
function is_user_logged_in() { return false; }
require dirname(__DIR__) . '/includes/helpers.php';

$checks = 0;
function check_reports($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['checks']++; }

update_option('tk_options', array('cache_page_enabled' => 1, 'seo_geo_audit_report' => array('items' => array('old')), 'geo_fix_history' => array(), 'toolkits_audit_log' => array(array('action' => 'old'))));

// Upgrade: non-empty reports move to tk_report_<key>, every report key leaves tk_options, settings stay.
tk_upgrade_to_2767_split_reports();
$opts = get_option('tk_options');
check_reports($opts === array('cache_page_enabled' => 1), 'Reports were left in tk_options: ' . json_encode($opts));
check_reports(tk_get_option('seo_geo_audit_report') === array('items' => array('old')) && tk_get_option('toolkits_audit_log')[0]['action'] === 'old', 'Migrated report values were lost');
check_reports(!array_key_exists('tk_report_geo_fix_history', $GLOBALS['wp_options']) && tk_get_option('geo_fix_history', 'fallback') === 'fallback', 'Empty report created an option or ignored the default');

// Saving a report writes only its own option.
$GLOBALS['writes'] = array();
tk_update_option('seo_geo_audit_report', array('items' => array('new')));
check_reports($GLOBALS['writes'] === array('tk_report_seo_geo_audit_report'), 'Saving a report rewrote other options: ' . json_encode($GLOBALS['writes']));
check_reports(tk_get_option('seo_geo_audit_report') === array('items' => array('new')) && !isset(tk_get_options()['seo_geo_audit_report']), 'Report read or settings array is wrong');

// Batched writes split reports from settings: one tk_options write plus the report option.
$GLOBALS['writes'] = array();
tk_update_options(array('cache_page_enabled' => 0, 'image_opt_queue' => array('offset' => 5)));
check_reports($GLOBALS['writes'] === array('tk_report_image_opt_queue', 'tk_options') && tk_get_option('cache_page_enabled') === 0 && tk_get_option('image_opt_queue')['offset'] === 5, 'Batched write mixed reports into tk_options');

// The admin audit log appends to its own option.
$GLOBALS['writes'] = array();
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
function wp_get_current_user() { return (object) array('user_login' => 'admin'); }
function sanitize_text_field($value) { return trim((string) $value); }
function wp_unslash($value) { return $value; }
function home_url($path = '/') { return 'https://site.example' . $path; }
function __($text) { return $text; }
tk_toolkits_audit_log('test_action');
check_reports($GLOBALS['writes'] === array('tk_report_toolkits_audit_log') && array_slice(tk_get_option('toolkits_audit_log'), -1)[0]['action'] === 'test_action', 'Audit log entry rewrote tk_options');

// Defaults never re-add report keys to tk_options.
tk_option_init_defaults();
check_reports(!array_intersect(array_keys(get_option('tk_options')), tk_report_option_keys()), 'Defaults put report keys back into tk_options');

// Page-cache purge ignores report options.
check_reports(strpos(file_get_contents(dirname(__DIR__) . '/includes/cache.php'), "'tk_report_',") !== false, 'Saving a report purges the page cache');
check_reports(strpos(file_get_contents(dirname(__DIR__) . '/includes/general.php'), 'if (tk_is_report_option($key)) {') !== false, 'Settings import can write reports into tk_options');

echo 'PASS: ' . $checks . " report option checks: migration, isolated report writes, batched split, audit log, defaults, purge/import guards\n";
