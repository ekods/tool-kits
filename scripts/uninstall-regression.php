<?php
// php scripts/uninstall-regression.php — deleting Tool Kits removes its data only when the administrator opted in,
// and then removes options, reports, transients, per-post fields, tables and cache files without touching others.
define('ABSPATH', __DIR__ . '/');
$content = sys_get_temp_dir() . '/tk-uninstall-' . bin2hex(random_bytes(4));
define('WP_CONTENT_DIR', $content);
$GLOBALS['opts'] = array();
$GLOBALS['deleted'] = array();
$GLOBALS['meta_deleted'] = array();
class TkUninstallDb {
    public $options = 'wp_options';
    public $postmeta = 'wp_postmeta';
    public $prefix = 'wp_';
    public $queries = array();
    public function esc_like($text) { return addcslashes($text, '_%\\'); }
    public function prepare($query, ...$args) { foreach ($args as $arg) { $query = preg_replace('/%s/', "'" . $arg . "'", $query, 1); } return $query; }
    public function query($sql) { $this->queries[] = $sql; return 0; }
}
$wpdb = new TkUninstallDb();
function get_option($name, $default = false) { return $GLOBALS['opts'][$name] ?? $default; }
function delete_option($name) { $GLOBALS['deleted'][] = $name; unset($GLOBALS['opts'][$name]); return true; }
function delete_metadata($type, $id, $key, $value = '', $all = false) { $GLOBALS['meta_deleted'][] = array($type, $key, $all); return true; }
function is_multisite() { return false; }
function esc_sql($value) { return $value; }
function trailingslashit($value) { return rtrim($value, '/') . '/'; }
require dirname(__DIR__) . '/includes/uninstall.php';

$checks = 0;
function check_uninstall($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['checks']++; }

mkdir($content . '/cache/tool-kits/site/page', 0777, true);
file_put_contents($content . '/cache/tool-kits/site/page/index.html', 'cached');
file_put_contents($content . '/keep.txt', 'other');

try {
    // Default: deleting the plugin keeps everything.
    $GLOBALS['opts'] = array('tk_options' => array('cache_page_enabled' => 1));
    tk_uninstall_run();
    check_uninstall(!$GLOBALS['deleted'] && !$wpdb->queries && !$GLOBALS['meta_deleted'] && is_file($content . '/cache/tool-kits/site/page/index.html'), 'Data was removed without the opt-in');

    // Opted in: Tool Kits data goes, other data stays.
    $GLOBALS['opts']['tk_uninstall_delete_data'] = 1;
    tk_uninstall_run();
    $sql = implode("\n", $wpdb->queries);
    check_uninstall(in_array('tk_options', $GLOBALS['deleted'], true) && in_array('tk_uninstall_delete_data', $GLOBALS['deleted'], true), 'Settings or the opt-in flag were kept');
    foreach (array('tk\\_report\\_%', 'tk\\_killswitch\\_%', '\\_transient\\_tk\\_%', '\\_tk\\_captcha\\_used\\_%') as $pattern) {
        check_uninstall(strpos($sql, "option_name LIKE '" . $pattern . "'") !== false, 'Options matching ' . $pattern . ' were kept');
    }
    check_uninstall(strpos($sql, "meta_key LIKE '\\_tk\\_seo\\_%'") !== false && strpos($sql, 'DROP TABLE IF EXISTS `wp_tk_login_log`') !== false && strpos($sql, 'DROP TABLE IF EXISTS `wp_tk_security_events`') !== false, 'Post fields or tables were kept');
    check_uninstall(strpos($sql, "LIKE 'tk\\_%'") === false && strpos($sql, "LIKE '\\_tk\\_%'") === false, 'A catch-all prefix could delete other plugins\' data');
    check_uninstall($GLOBALS['meta_deleted'] === array(array('user', 'tk_admin_login_ips', true)), 'User login IP history was kept');
    check_uninstall(!is_dir($content . '/cache/tool-kits') && is_file($content . '/keep.txt'), 'Page cache files were kept or other files removed');
} finally {
    @unlink($content . '/keep.txt');
    @rmdir($content . '/cache');
    @rmdir($content);
}

// The flag lives outside tk_options and is only wired to the uninstall hook.
$main = file_get_contents(dirname(__DIR__) . '/tool-kits.php');
check_uninstall(strpos($main, "require_once TK_PATH . 'includes/uninstall.php';") !== false && strpos($main, "'uninstall.php'") === false, 'uninstall.php is loaded on normal requests or not from the hook');

echo 'PASS: ' . $checks . " uninstall checks: opt-in only, Tool Kits options/reports/transients/post fields/tables/cache removed, other data kept\n";
