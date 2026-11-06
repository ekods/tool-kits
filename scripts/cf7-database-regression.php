<?php
// Isolated WordPress/CF7 boundary fixtures; never touches a site's database.
define('ABSPATH', sys_get_temp_dir() . '/tk-cf7-database-' . getmypid() . '/');
define('TK_URL', 'https://site.test/wp-content/plugins/tool-kits/');
define('OBJECT_K', 'OBJECT_K');
$GLOBALS['options'] = array();
$GLOBALS['tk'] = array();
$GLOBALS['licensed'] = true;
$GLOBALS['manager'] = true;
$GLOBALS['nonce_valid'] = true;
$GLOBALS['hooks'] = array();
$GLOBALS['checks'] = 0;

class DatabaseRedirect extends RuntimeException {}
class DatabaseDenied extends RuntimeException {}
function check_database($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['options'][$key] = $value; }
function tk_get_option($key, $default = null) { return $GLOBALS['tk'][$key] ?? $default; }
function tk_update_option($key, $value) { $GLOBALS['tk'][$key] = $value; }
function tk_license_features_enabled() { return $GLOBALS['licensed']; }
function tk_is_admin_user() { return $GLOBALS['manager']; }
function wp_die($message, ...$args) { throw new DatabaseDenied($message); }
function wp_verify_nonce($nonce, $action) { return $GLOBALS['nonce_valid'] && $nonce === 'valid'; }
function wp_safe_redirect($url) { throw new DatabaseRedirect($url); }
function check_admin_referer($action) { $GLOBALS['nonce_action'] = $action; if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', $action)) { wp_die('Invalid nonce'); } }
function add_action($hook, $callback, $priority = 10) { $GLOBALS['hooks'][$hook][$priority][$callback] = true; }
function remove_action($hook, $callback, $priority = 10) { unset($GLOBALS['hooks'][$hook][$priority][$callback]); }
function do_action($hook, ...$args) {
    $priorities = $GLOBALS['hooks'][$hook] ?? array(); ksort($priorities);
    foreach ($priorities as $callbacks) { foreach ($callbacks as $callback => $unused) { $callback(...$args); } }
}
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
function wp_check_invalid_utf8($value) { return $value; }
function wp_strip_all_tags($value) { return strip_tags($value); }
function esc_url_raw($value) { return $value; }
function wp_json_encode($value) { return json_encode($value); }
function absint($value) { return abs((int) $value); }
function current_time($format) { return '2026-11-06 12:00:00'; }
function admin_url($path) { return 'https://site.test/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . '&' . http_build_query($args); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function __($value, $domain = '') { return $value; }
function _e($value, $domain = '') { echo $value; }
function esc_html__($value, $domain = '') { return esc_html($value); }
function esc_html_e($value, $domain = '') { echo esc_html($value); }
function checked($value) { if ($value) { echo 'checked="checked"'; } }
function wp_nonce_field($action, $name) { echo '<input type="hidden" name="' . $name . '" value="valid">'; }
function tk_render_header_branding() {}
function tk_render_page_hero($title, ...$args) { echo '<h1>' . esc_html($title) . '</h1>'; }
function tk_notice($message, $type) { echo esc_html($message); }
function number_format_i18n($number) { return number_format($number); }
function wp_kses_post($value) { return $value; }
function get_posts($args) { return array((object) array('ID' => 7, 'post_title' => 'Contact')); }
function get_the_title($id) { return 'Contact'; }
function wp_nonce_url($url, $action) { return $url . '&_wpnonce=valid'; }
function wp_tempnam($filename) { return tempnam(sys_get_temp_dir(), 'tk-cf7-xlsx-'); }
function tk_asset_version($path) { return 'fixture'; }
function wp_enqueue_style(...$args) { $GLOBALS['styles'][] = $args; }

// Exercise the real Tool Kits permission/nonce helpers without bootstrapping unrelated modules.
$helpers = file_get_contents(dirname(__DIR__) . '/includes/helpers.php');
foreach (array('tk_nonce_field', 'tk_check_nonce', 'tk_require_admin_post') as $name) {
    preg_match('/function ' . $name . '\([^\n]*\).*?\n\}/s', $helpers, $match);
    eval($match[0]);
}

class CollectionDatabase {
    public $prefix = 'wp_';
    public $rows = array();
    public $updates = 0;
    public $queries = 0;
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function insert($table, $row, $formats) { $row['id'] = count($this->rows) + 1; $row['read_at'] = null; $this->rows[] = $row; }
    public function get_var($query) { $this->queries++; return count($this->rows); }
    public function get_results($query, $format = null) { $this->queries++; return array(); }
    public function get_row($query) { $this->queries++; return $this->rows ? (object) $this->rows[0] : null; }
    public function prepare($query, ...$values) { return $query; }
    public function update($table, $values, ...$args) { $this->updates++; $this->rows[0] = array_merge($this->rows[0], $values); }
    public function delete($table, $where, $formats) { $this->rows = array_values(array_filter($this->rows, function ($row) use ($where) { return $row['id'] !== $where['id']; })); }
}
$wpdb = new CollectionDatabase();
require dirname(__DIR__) . '/includes/cf7-database.php';
tk_cf7_database_init();

$_GET = array('page' => 'tool-kits-cf7-database');
ob_start(); tk_render_cf7_database_page(); $off_html = ob_get_clean();
check_database(strpos($off_html, 'Enable CF7 Database Collection') !== false && strpos($off_html, 'checked=') === false, 'Default checkbox must be off');
check_database($wpdb->queries === 0 && !function_exists('tk_cf7dbc_save_submission'), 'Disabled module loaded its engine or queried the collection');
tk_cf7_database_register_capture();
check_database(empty($GLOBALS['hooks']['wpcf7_mail_sent']), 'Default-off registered capture');
tk_cf7_database_assets();
check_database(count($GLOBALS['styles']) === 1, 'Collection stylesheet missing');
$_GET['page'] = 'other'; tk_cf7_database_assets();
check_database(count($GLOBALS['styles']) === 1, 'Collection stylesheet leaked to other screens');

$GLOBALS['tk']['cf7_database_enabled'] = 1;
tk_cf7_database_capture((object) array());
check_database(!function_exists('tk_cf7dbc_save_submission'), 'Missing CF7 should be a no-op');
eval('class WPCF7_Submission {
    public static $available = true;
    public static function get_instance() { return self::$available ? new self() : null; }
    public function get_posted_data() { return array("your-name" => "<b>Alice</b>", "your-email" => "alice@example.test", "your-message" => "Hello", "_wpcf7" => 7); }
}');
$form = new class { public function id() { return 7; } public function title() { return 'Contact'; } };
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';

// Simulate dbDelta with its own isolated WordPress upgrade include.
mkdir(ABSPATH . 'wp-admin/includes', 0777, true);
file_put_contents(ABSPATH . 'wp-admin/includes/upgrade.php', '<?php function dbDelta($sql) { $GLOBALS["schema_calls"][] = $sql; }');
register_shutdown_function(function () {
    unlink(ABSPATH . 'wp-admin/includes/upgrade.php');
    rmdir(ABSPATH . 'wp-admin/includes'); rmdir(ABSPATH . 'wp-admin'); rmdir(ABSPATH);
});
function cf7dbc_save_submission($form) { $GLOBALS['standalone_captures'] = ($GLOBALS['standalone_captures'] ?? 0) + 1; }
add_action('wpcf7_mail_sent', 'cf7dbc_save_submission');
tk_cf7_database_register_capture();
do_action('wpcf7_mail_sent', $form);
check_database(count($wpdb->rows) === 1 && empty($GLOBALS['standalone_captures']), 'Standalone plugin caused duplicate capture');
check_database(get_option('cf7dbc_schema_version') === '1.0.1' && count($GLOBALS['schema_calls']) === 1, 'Schema not installed lazily');
check_database(strpos($GLOBALS['schema_calls'][0], 'wp_cf7dbc_submissions') !== false, 'Existing standalone table was not reused');
$data = json_decode($wpdb->rows[0]['data'], true);
check_database($data['your-name'] === 'Alice' && !isset($data['_wpcf7']) && $wpdb->rows[0]['read_status'] === 'unread', 'Stored data is not sanitized/unread');
do_action('wpcf7_mail_sent', $form);
check_database(count($wpdb->rows) === 2 && count($GLOBALS['schema_calls']) === 1, 'Existing schema was recreated');
WPCF7_Submission::$available = false;
do_action('wpcf7_mail_sent', $form);
check_database(count($wpdb->rows) === 2, 'Missing submission instance was stored');
WPCF7_Submission::$available = true;
$GLOBALS['tk']['cf7_database_enabled'] = 0;
do_action('wpcf7_mail_sent', $form);
check_database(count($wpdb->rows) === 2, 'Off switch still stored a submission');
$GLOBALS['tk']['cf7_database_enabled'] = 1;
$GLOBALS['licensed'] = false;
do_action('wpcf7_mail_sent', $form);
check_database(count($wpdb->rows) === 2, 'Unlicensed module still stored a submission');
$GLOBALS['licensed'] = true;

// Real permission/nonce helper must reject before changing the switch.
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array('_tk_nonce' => 'valid', 'cf7_database_enabled' => '1');
foreach (array('manager', 'nonce_valid', 'licensed') as $guard) {
    $GLOBALS[$guard] = false;
    try { tk_cf7_database_save(); throw new RuntimeException('Save bypassed ' . $guard); }
    catch (DatabaseDenied $e) { check_database(tk_cf7_database_enabled(), 'Denied save changed settings'); }
    $GLOBALS[$guard] = true;
}
$_SERVER['REQUEST_METHOD'] = 'GET';
try { tk_cf7_database_save(); throw new RuntimeException('GET saved settings'); }
catch (DatabaseDenied $e) { check_database(true, 'POST-only save'); }
$_SERVER['REQUEST_METHOD'] = 'POST';
unset($_POST['cf7_database_enabled']);
try { tk_cf7_database_save(); } catch (DatabaseRedirect $e) {}
check_database(!tk_cf7_database_enabled() && tk_get_option('cf7_database_managed') === 1 && count($wpdb->rows) === 2, 'Unchecked save did not stop collection or deleted data');
$GLOBALS['hooks']['wpcf7_mail_sent'] = array();
add_action('wpcf7_mail_sent', 'cf7dbc_save_submission');
tk_cf7_database_register_capture(); do_action('wpcf7_mail_sent', $form);
check_database(count($wpdb->rows) === 2 && empty($GLOBALS['standalone_captures']), 'Disabled switch did not control the standalone collector after saving');
$_POST['cf7_database_enabled'] = '1';
try { tk_cf7_database_save(); } catch (DatabaseRedirect $e) {}
check_database(tk_cf7_database_enabled(), 'Checked save did not enable collection');

// Views, read state, export escaping and deletion still work while disabled.
$GLOBALS['tk']['cf7_database_enabled'] = 0;
$_GET = array('page' => 'tool-kits-cf7-database', 'view' => 'forms');
ob_start(); tk_render_cf7_database_page(); $forms_html = ob_get_clean();
check_database(strpos($forms_html, 'Export Excel') !== false && strpos($forms_html, 'form_id=7') !== false, 'Old forms/export unavailable while disabled');
check_database(strpos($forms_html, 'page=cf7dbc') === false, 'View linked to standalone plugin');
$_GET = array('page' => 'tool-kits-cf7-database', 'submission_id' => 1);
$wpdb->rows[0]['data'] = json_encode(array('message' => '<script>alert(1)</script>'));
ob_start(); tk_render_cf7_database_page(); $detail_html = ob_get_clean();
check_database(strpos($detail_html, '<script>') === false && strpos($detail_html, '&lt;script&gt;') !== false, 'Message detail is not XSS-safe');
check_database($wpdb->rows[0]['read_status'] === 'read' && $wpdb->updates === 1, 'Opening a message did not mark it read');
check_database(tk_cf7dbc_escape_excel_cell('=HYPERLINK("bad")') === '\'=HYPERLINK("bad")', 'Excel formula protection was lost');
check_database(!tk_cf7dbc_should_export_field('tk_captcha_answer') && tk_cf7dbc_should_export_field('your-email'), 'Export field filtering was lost');
$excel = tk_cf7dbc_create_excel_file(array('Message'), array(array('=1+1'), array('<script>message</script>')), 'submissions.xlsx');
$archive = new ZipArchive();
check_database($archive->open($excel) === true, 'Export is not a valid Excel ZIP');
$sheet = $archive->getFromName('xl/worksheets/sheet1.xml');
check_database(simplexml_load_string($sheet) !== false && strpos($sheet, "'=1+1") !== false && strpos($sheet, '&lt;script&gt;') !== false, 'Excel cells lost XML/formula escaping');
check_database($archive->locateName('xl/workbook.xml') !== false, 'Excel workbook is missing');
$archive->close(); unlink($excel);
$GLOBALS['manager'] = false;
ob_start(); tk_render_cf7_database_page(); $denied_html = ob_get_clean();
check_database($denied_html === '', 'Unauthorized manager viewed messages');
$_GET = array('page' => 'tool-kits-cf7-database', 'tk_cf7dbc_delete' => 1, '_wpnonce' => 'valid');
tk_cf7_database_handle_actions();
check_database(count($wpdb->rows) === 2, 'Unauthorized manager deleted messages');
$GLOBALS['manager'] = true;
$_GET['_wpnonce'] = 'invalid';
try { tk_cf7_database_handle_actions(); throw new RuntimeException('Invalid nonce deleted data'); }
catch (DatabaseDenied $e) { check_database(count($wpdb->rows) === 2, 'Denied delete changed data'); }
$_GET['_wpnonce'] = 'valid';
try { tk_cf7_database_handle_actions(); } catch (DatabaseRedirect $e) {}
check_database(count($wpdb->rows) === 1 && $GLOBALS['nonce_action'] === 'tk_cf7dbc_delete_1', 'Authorized deletion failed');

if (in_array('--render', $argv, true)) {
    echo $forms_html;
    exit;
}
echo 'PASS: ' . $GLOBALS['checks'] . " CF7 database checks (switch, capture, coexistence, schema reuse, access, saved messages and export safety)\n";
