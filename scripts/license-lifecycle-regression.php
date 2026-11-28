<?php
// Runs the Tool Kits bootstrap with WordPress hooks and isolated options, cron and files.
if (PHP_SAPI !== 'cli') { exit; }
$core = rtrim((string) getenv('TK_WP_ROOT'), '/');
if (!is_file($core . '/wp-includes/plugin.php')) {
    throw new RuntimeException('Set TK_WP_ROOT to an unpacked WordPress core directory.');
}

if (!isset($argv[1])) {
    require __DIR__ . '/license-test-fixture.php';
    $root = sys_get_temp_dir() . '/tk-lifecycle-' . bin2hex(random_bytes(6));
    mkdir($root . '/wp-content/uploads', 0700, true);
    $settings = array(
        'license_status' => 'valid', 'license_key' => 'fixture', 'license_type' => 'production',
        'license_site_url' => 'https://example.test', 'toolkits_install_id' => 'fixture-install',
        'hide_login_enabled' => 1, 'hide_login_slug' => 'private-login',
        'hardening_auto_applied' => 1, 'hardening_server_aware_enabled' => 1,
        'google_analytics_gtag_id' => 'G-FIXTURE', 'duplicate_content_enabled' => 1,
    );
    $settings['license_receipt'] = tk_test_license_receipt($settings);
    $expired = tk_test_license_receipt($settings, array('issued_at' => time() - 3600, 'expires_at' => time() - 1));
    $queue = array('status' => 'running', 'offset' => 4, 'total' => 10);
    $state = array('tk_options' => $settings, 'tk_report_image_opt_queue' => $queue,
        'tk_security_events_schema_version' => '1', 'tk_login_log_schema_version' => '2', 'expired_receipt_fixture' => $expired);
    file_put_contents($root . '/state.json', json_encode($state));
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ';
    try {
        foreach (array('valid', 'host-mismatch', 'missing', 'revoked', 'expired', 'renewed', 'deactivate', 'inactive', 'reactivate') as $scenario) {
            passthru($command . escapeshellarg($scenario) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($tk_test_pins), $status);
            if ($status !== 0) { throw new RuntimeException('Scenario failed: ' . $scenario); }
        }
        echo "PASS: license lifecycle gates features and private frontend notices, preserves settings and resumes a saved queue\n";
    } finally {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
        rmdir($root);
    }
    exit;
}

[$scenario, $root, $pins] = array_slice($argv, 1);
define('ABSPATH', $root . '/');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('WP_PLUGIN_DIR', dirname(__DIR__, 2));
define('WPMU_PLUGIN_DIR', ABSPATH . 'wp-content/mu-plugins');
define('TK_LICENSE_PUBLIC_KEYS_FILE', $pins);
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
$options = json_decode(file_get_contents(ABSPATH . 'state.json'), true);
$license = $options['tk_options'];
if ($scenario === 'missing') { unset($options['tk_options']['license_key']); }
if ($scenario === 'revoked') { $options['tk_options']['license_status'] = 'revoked'; }
if ($scenario === 'expired') { $options['tk_options']['license_receipt'] = $options['expired_receipt_fixture']; }
$saved = $options['tk_options'];
$queue = $options['tk_report_image_opt_queue'];
$events = array('tk_license_refresh' => time() + 60);
if (empty($options['tk_runtime_suspended'])) { $events['tk_image_opt_queue_process'] = time() + 60; }
$_SERVER['HTTP_HOST'] = $scenario === 'host-mismatch' ? 'other.example.test' : 'example.test';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php';
$_SERVER['SERVER_SOFTWARE'] = 'Apache';
$wp_rewrite = (object) array('non_wp_rules' => array());
$wp_plugin_paths = array();
$did_flush = 0;

require $core . '/wp-includes/plugin.php';
require $core . '/wp-includes/shortcodes.php';

function check_lifecycle(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) {
    $old = get_option($name);
    $GLOBALS['options'][$name] = $value;
    if ($old !== $value) { do_action('updated_option', $name, $old, $value); }
    return true;
}
function delete_option($name) {
    unset($GLOBALS['options'][$name]);
    do_action('deleted_option', $name);
    return true;
}
function is_admin() { return empty($GLOBALS['frontend']); }
function is_multisite() { return false; }
function is_user_logged_in() { return !empty($GLOBALS['logged_in']); }
function wp_get_current_user() { return (object) array('ID' => 1, 'roles' => array('subscriber')); }
function current_user_can($capability) { return $capability === 'toolkits_manage' && !empty($GLOBALS['manager']); }
function admin_url($path) { return home_url('/wp-admin/' . $path); }
function nocache_headers() { $GLOBALS['notice_nocache'] = true; }
function esc_url($value) { return htmlspecialchars($value, ENT_QUOTES); }
function esc_attr_e($value, $domain) { echo htmlspecialchars($value, ENT_QUOTES); }
function esc_html_e($value, $domain) { echo htmlspecialchars($value, ENT_QUOTES); }
function wp_doing_cron() { return false; }
function wp_doing_ajax() { return false; }
function wp_installing() { return false; }
function is_ssl() { return true; }
function plugins_url() { return 'https://example.test/wp-content/plugins'; }
function home_url($path = '') { return 'https://example.test' . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_json_encode($value) { return json_encode($value); }
function sanitize_title($value) { return $value; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function trailingslashit($path) { return rtrim($path, '/\\') . '/'; }
function wp_normalize_path($path) { return str_replace('\\', '/', $path); }
function get_transient($name) { return false; }
function set_transient($name, $value, $expiration = 0) { return true; }
function wp_get_upload_dir() { return array('basedir' => WP_CONTENT_DIR . '/uploads'); }
function wp_next_scheduled($hook) { return $GLOBALS['events'][$hook] ?? false; }
function wp_schedule_single_event($time, $hook) { $GLOBALS['events'][$hook] = $time; return true; }
function wp_schedule_event($time, $period, $hook) { return wp_schedule_single_event($time, $hook); }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['events'][$hook]); }
function wp_unschedule_event($time, $hook) { wp_clear_scheduled_hook($hook); }
function add_rewrite_rule($regex, $query, $position) { $GLOBALS['wp_rewrite']->non_wp_rules[$regex] = $query; }
function flush_rewrite_rules() { $GLOBALS['did_flush']++; }

$plugin = dirname(__DIR__) . '/tool-kits.php';
if ($scenario !== 'inactive') {
    require $plugin;
    $options['tk_version'] = TK_VERSION;
    do_action('plugins_loaded');
}
$running = in_array($scenario, array('valid', 'renewed', 'deactivate', 'reactivate'), true);
foreach (array('wp_head' => 'tk_analytics_render_gtag', 'admin_post_tk_db_export' => 'tk_db_export_handler',
    'tk_image_opt_queue_process' => 'tk_image_opt_queue_process', 'login_init' => 'tk_hide_login_block_login',
    'rest_api_init' => 'tk_heartbeat_recheck_register', 'admin_post_tk_duplicate_content' => 'tk_general_duplicate_content_handler') as $hook => $callback) {
    check_lifecycle((has_action($hook, $callback) !== false) === $running, 'Unexpected hook state: ' . $scenario . ' / ' . $callback);
}
check_lifecycle((has_action('admin_post_tk_toolkits_license_activate', 'tk_toolkits_license_activate') !== false) === ($scenario !== 'inactive'), 'License recovery hook unavailable');
check_lifecycle(shortcode_exists('tool_kits_geo_faq') === $running, 'FAQ shortcode did not follow the license state');

if ($scenario === 'deactivate') {
    add_rewrite_rule('^private-login/?$', 'wp-login.php', 'top');
    do_action('deactivate_' . plugin_basename($plugin));
    check_lifecycle(!$wp_rewrite->non_wp_rules && $did_flush === 1 && empty($events), 'Deactivation kept the login rewrite or a background job');
} elseif ($scenario === 'reactivate') {
    do_action('activate_' . plugin_basename($plugin));
} elseif ($scenario === 'host-mismatch') {
    do_action('wp_loaded');
    check_lifecycle(!isset($options['tk_runtime_suspended']) && isset($events['tk_image_opt_queue_process']), 'A different host paused the licensed site');
}
if (in_array($scenario, array('missing', 'revoked', 'expired', 'renewed', 'reactivate'), true)) {
    if ($scenario === 'missing') {
        file_put_contents(ABSPATH . '.htaccess', "# Keep this rule\n# BEGIN Tool Kits Root\nTool Kits rule\n# END Tool Kits Root\n");
        file_put_contents(WP_CONTENT_DIR . '/uploads/.htaccess', "# Keep this rule\n" . tk_hardening_uploads_server_rule(false));
    }
    do_action('wp_loaded');
    check_lifecycle(isset($events['tk_image_opt_queue_process']) === $running, 'A saved image queue did not pause or resume');
    check_lifecycle(isset($events['tk_license_refresh']), 'License refresh stopped while the plugin was active');
    if ($scenario === 'missing') {
        check_lifecycle(file_get_contents(ABSPATH . '.htaccess') === "# Keep this rule\n"
            && file_get_contents(WP_CONTENT_DIR . '/uploads/.htaccess') === "# Keep this rule\n", 'Managed server rules were not removed cleanly');
    }
}
check_lifecycle($options['tk_options'] === $saved && $options['tk_report_image_opt_queue'] === $queue, 'Pause or resume altered saved settings or queue progress');

$notice_hook = has_action('template_redirect', 'tk_license_frontend_notice_init') !== false;
check_lifecycle($notice_hook === (!$running && $scenario !== 'inactive'), 'Frontend notice hook did not follow the license state');
if ($notice_hook) {
    $logged_in = in_array($scenario, array('expired', 'revoked'), true);
    $manager = $scenario === 'revoked';
    $notice_nocache = false;
    do_action('template_redirect');
    check_lifecycle(!has_action('wp_body_open', 'tk_license_frontend_notice') && !$notice_nocache, 'Frontend notice was prepared for wp-admin');

    $frontend = true;
    $_SERVER['REQUEST_URI'] = '/example/';
    do_action('template_redirect');
    ob_start();
    if (!$manager) { do_action('wp_body_open'); }
    do_action('wp_footer');
    $notice = ob_get_clean();
    check_lifecycle(substr_count($notice, 'class="tk-license-frontend-notice"') === (int) $logged_in, 'Notice visibility or footer fallback failed');
    check_lifecycle((strpos($notice, 'admin.php?page=tool-kits-access#license') !== false) === $manager, 'License link shown to the wrong user');
    check_lifecycle($notice_nocache === $logged_in && defined('DONOTCACHEPAGE') === $logged_in, 'Notice response can be cached or affects guest caching');
}

$options['tk_options'] = $license;
file_put_contents(ABSPATH . 'state.json', json_encode($options));
