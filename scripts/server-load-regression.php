<?php
// Heartbeat control, WooCommerce cart fragments and the autoloaded-options audit. Isolated stubs, no database.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
error_reporting(E_ALL);
set_error_handler(function ($level, $message) { throw new RuntimeException($message); });
define('ABSPATH', '/tmp/');
define('TK_URL', 'https://site.test/wp-content/plugins/tool-kits/');
define('MINUTE_IN_SECONDS', 60);

class LoadRedirect extends RuntimeException {}
class LoadDenied extends RuntimeException {}

$GLOBALS['tk'] = array();
$GLOBALS['licensed'] = true;
$GLOBALS['checks'] = 0;
$GLOBALS['deregistered'] = array();
$GLOBALS['dequeued'] = array();
$GLOBALS['autoload_off'] = array();

function check_load($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
function add_action() {} function add_filter() {} function remove_action() {} function remove_filter() {}
function apply_filters($hook, $value) { return $value; }
function tk_get_option($key, $default = null) { return $GLOBALS['tk'][$key] ?? $default; }
function tk_license_features_enabled() { return $GLOBALS['licensed']; }
function is_admin() { return false; }
function is_user_logged_in() { return false; }
function wp_deregister_script($handle) { $GLOBALS['deregistered'][] = $handle; }
function wp_deregister_style($handle) {}
function wp_dequeue_script($handle) { $GLOBALS['dequeued'][] = $handle; }
function get_option($key, $default = false) { return $key === 'stylesheet' ? 'child-theme' : $default; }
function wp_unslash($value) { return $value; }
function admin_url($path) { return 'https://site.test/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . '&' . http_build_query($args); }
function wp_safe_redirect($url) { throw new LoadRedirect($url); }
function wp_die($message) { throw new LoadDenied($message); }
function tk_require_admin_post($action) { if (empty($GLOBALS['allowed'])) { throw new LoadDenied('denied'); } }
function tk_toolkits_is_locked() { return false; }
function wp_autoload_values_to_autoload() { return array('yes', 'on', 'auto-on', 'auto'); }
function wp_set_option_autoload($name, $autoload) { $GLOBALS['autoload_off'][$name] = $autoload; }

require dirname(__DIR__) . '/includes/asset-optimization.php';

// Heartbeat interval in wp-admin.
$defaults = array('interval' => 15, 'nonce' => 'x');
check_load(tk_assets_heartbeat_settings($defaults) === $defaults, 'Default Heartbeat settings changed without opting in');
$GLOBALS['tk']['assets_heartbeat_interval'] = 120;
check_load(tk_assets_heartbeat_settings($defaults)['interval'] === 120, 'Heartbeat interval was not slowed to 120 seconds');
$GLOBALS['tk']['assets_heartbeat_interval'] = 60;
check_load(tk_assets_heartbeat_settings($defaults)['interval'] === 60, 'Heartbeat interval was not slowed to 60 seconds');
$GLOBALS['tk']['assets_heartbeat_interval'] = 5;
check_load(tk_assets_heartbeat_settings($defaults) === $defaults, 'An unsupported Heartbeat interval was applied');
$GLOBALS['tk']['assets_heartbeat_interval'] = 120;
$GLOBALS['licensed'] = false;
check_load(tk_assets_heartbeat_settings($defaults) === $defaults, 'Unlicensed site changed Heartbeat');
$GLOBALS['licensed'] = true;

// Front-end Heartbeat and cart fragments, before WooCommerce is loaded.
tk_assets_cleanup_bloat();
check_load($GLOBALS['deregistered'] === array() && $GLOBALS['dequeued'] === array(), 'Default settings removed scripts');
$GLOBALS['tk']['assets_heartbeat_frontend_disabled'] = 1;
$GLOBALS['tk']['assets_disable_cart_fragments'] = 1;
tk_assets_cleanup_bloat();
check_load($GLOBALS['deregistered'] === array('heartbeat'), 'Front-end Heartbeat was not removed');
check_load($GLOBALS['dequeued'] === array(), 'Cart fragments touched without WooCommerce');

$GLOBALS['page'] = 'blog';
// Declared conditionally so they only exist from here on, like WooCommerce loading.
if (!function_exists('is_woocommerce')) {
    function is_woocommerce() { return $GLOBALS['page'] === 'shop'; }
    function is_cart() { return $GLOBALS['page'] === 'cart'; }
    function is_checkout() { return $GLOBALS['page'] === 'checkout'; }
    function is_account_page() { return $GLOBALS['page'] === 'account'; }
}
$GLOBALS['dequeued'] = array();
tk_assets_cleanup_bloat();
check_load($GLOBALS['dequeued'] === array('wc-cart-fragments'), 'Cart fragments still load on a blog page');
foreach (array('shop', 'cart', 'checkout', 'account') as $page) {
    $GLOBALS['page'] = $page; $GLOBALS['dequeued'] = array();
    tk_assets_cleanup_bloat();
    check_load($GLOBALS['dequeued'] === array(), 'Cart fragments removed on the ' . $page . ' page');
}
$GLOBALS['page'] = 'blog'; $GLOBALS['dequeued'] = array();
$GLOBALS['tk']['assets_disable_cart_fragments'] = 0;
tk_assets_cleanup_bloat();
check_load($GLOBALS['dequeued'] === array(), 'Cart fragments removed while the option is off');

// Autoloaded options audit.
class AutoloadDatabase {
    public $options = 'wp_options';
    public $prefix = 'wp_';
    public $rows = array(
        array('option_name' => 'big_plugin_cache', 'option_value' => 900000, 'autoload' => 'yes'),
        array('option_name' => 'rewrite_rules', 'option_value' => 60000, 'autoload' => 'on'),
        array('option_name' => 'widget_text', 'option_value' => 3000, 'autoload' => 'auto-on'),
        array('option_name' => 'small_setting', 'option_value' => 20, 'autoload' => 'auto'),
        array('option_name' => 'lazy_report', 'option_value' => 500000, 'autoload' => 'off'),
    );
    public function prepare($query, $args) { return array($query, $args); }
    private function autoloaded($values) { return array_values(array_filter($this->rows, function ($row) use ($values) { return in_array($row['autoload'], $values, true); })); }
    public function get_row($prepared) {
        $rows = $this->autoloaded($prepared[1]);
        return (object) array('options' => count($rows), 'bytes' => array_sum(array_column($rows, 'option_value')));
    }
    public function get_results($prepared) {
        $args = $prepared[1]; $limit = array_pop($args);
        $rows = $this->autoloaded($args);
        usort($rows, function ($a, $b) { return $b['option_value'] <=> $a['option_value']; });
        return array_map(function ($row) { return (object) array('option_name' => $row['option_name'], 'bytes' => $row['option_value']); }, array_slice($rows, 0, $limit));
    }
    public function get_var($prepared) {
        $args = $prepared[1]; $name = array_shift($args);
        foreach ($this->autoloaded($args) as $row) { if ($row['option_name'] === $name) { return '1'; } }
        return null;
    }
}
$wpdb = new AutoloadDatabase();
require dirname(__DIR__) . '/includes/db-cleanup.php';

$report = tk_db_autoload_report(3);
check_load($report['options'] === 4 && $report['bytes'] === 963020, 'Autoload totals must count yes/on/auto-on/auto and skip off');
check_load(array_column($report['top'], 'option_name') === array('big_plugin_cache', 'rewrite_rules', 'widget_text'), 'Largest autoloaded options are not listed first');
check_load($report['limit'] === 800000 && $report['bytes'] > $report['limit'], 'Site Health threshold not applied');
check_load(tk_db_autoload_is_core('rewrite_rules') && tk_db_autoload_is_core('widget_text') && tk_db_autoload_is_core('wp_user_roles') && tk_db_autoload_is_core('theme_mods_child-theme'), 'Core options offered for the action');
check_load(!tk_db_autoload_is_core('big_plugin_cache'), 'Plugin option treated as core');

function size_format($bytes) { return round($bytes / 1000) . ' KB'; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return (string) $value; }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function tk_notice($message, $type) { echo '<div class="notice">' . esc_html($message) . '</div>'; }
function tk_nonce_field($action) { echo '<input type="hidden" name="_tk_nonce" value="' . $action . '">'; }
ob_start(); tk_render_db_autoload_panel(); $panel = ob_get_clean();
check_load(strpos($panel, '963 KB in 4 options') !== false && strpos($panel, 'tk-warn') !== false && strpos($panel, 'above the') !== false, 'Panel does not show the over-limit total');
check_load(substr_count($panel, 'Stop autoloading') === 2 && substr_count($panel, 'WordPress core, used on every page') === 2, 'Action offered on core options or missing on plugin options');
check_load(strpos($panel, 'value="big_plugin_cache"') !== false && strpos($panel, 'value="rewrite_rules"') === false, 'Wrong option posted by the action');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array('option_name' => 'big_plugin_cache');
$GLOBALS['allowed'] = false;
try { tk_db_autoload_disable_handler(); throw new RuntimeException('Unauthorized autoload change'); }
catch (LoadDenied $e) { check_load($GLOBALS['autoload_off'] === array(), 'Denied request changed autoload'); }
$GLOBALS['allowed'] = true;
foreach (array('rewrite_rules', 'lazy_report', 'missing_option') as $refused) {
    $_POST['option_name'] = $refused;
    try { tk_db_autoload_disable_handler(); throw new RuntimeException('Refused option changed: ' . $refused); }
    catch (LoadDenied $e) { check_load($GLOBALS['autoload_off'] === array(), 'Core, non-autoloaded or missing option changed: ' . $refused); }
}
$_POST['option_name'] = 'big_plugin_cache';
try { tk_db_autoload_disable_handler(); throw new RuntimeException('No redirect after change'); }
catch (LoadRedirect $e) {
    check_load($GLOBALS['autoload_off'] === array('big_plugin_cache' => false), 'Plugin option kept autoloading');
    check_load(strpos($e->getMessage(), 'tk_tab=db-cleanup') !== false, 'Did not return to the Cleanup tab');
}
$_SERVER['REQUEST_METHOD'] = 'GET';
try { tk_db_autoload_disable_handler(); throw new RuntimeException('GET changed autoload'); }
catch (LoadDenied $e) { check_load(true, 'POST only'); }

echo 'PASS: ' . $GLOBALS['checks'] . " server load checks (Heartbeat, cart fragments, autoloaded options)\n";
