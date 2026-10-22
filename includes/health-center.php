<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/health-center-engine.php';
require_once __DIR__ . '/health-center-probe.php';
require_once __DIR__ . '/health-center-view.php';

function tk_health_center_init(): void {
    add_action('admin_menu', 'tk_health_center_menu', 22);
    add_action('wp_dashboard_setup', 'tk_health_center_widget');
    add_action('admin_enqueue_scripts', 'tk_health_center_assets');
    foreach (array('start', 'step') as $action) { add_action('wp_ajax_tk_health_center_' . $action, 'tk_health_center_' . $action . '_ajax'); }
}

function tk_health_center_allowed(): bool {
    return current_user_can('manage_options') && tk_toolkits_can_manage() && tk_license_features_enabled();
}

function tk_health_center_menu(): void {
    if (tk_health_center_allowed()) { add_submenu_page('tool-kits', 'WordPress Health Center', 'Health Center', 'manage_options', 'tool-kits-health-center', 'tk_health_center_page'); }
}

function tk_health_center_widget(): void {
    if (tk_health_center_allowed()) { wp_add_dashboard_widget('tk_health_center_widget', 'WordPress Health Center', 'tk_health_center_render'); }
}

function tk_health_center_assets(string $hook): void {
    if (($hook !== 'index.php' && ($_GET['page'] ?? '') !== 'tool-kits-health-center') || !tk_health_center_allowed()) { return; }
    wp_enqueue_style('tk-health-center', TK_URL . 'assets/health-center.css', array(), tk_asset_version('assets/health-center.css'));
    wp_enqueue_script('tk-health-center', TK_URL . 'assets/health-center.js', array(), tk_asset_version('assets/health-center.js'), true);
}

function tk_health_center_authorize(): void {
    if (!tk_health_center_allowed()) { wp_send_json_error(array('message' => 'Access denied or Tool Kits license inactive.'), 403); }
    check_ajax_referer('tk_health_center');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_send_json_error(array('message' => 'Use POST to run the scanner.'), 405); }
}

function tk_health_center_start_ajax(): void {
    tk_health_center_authorize();
    $existing = get_option('tk_health_center_lock', array());
    if ($existing && (int) ($existing['expires'] ?? 0) < time()) { delete_option('tk_health_center_lock'); delete_option('tk_health_center_step_lock'); }
    $token = bin2hex(random_bytes(24));
    $lock = array('token' => $token, 'owner' => get_current_user_id(), 'expires' => time() + 600);
    if (!add_option('tk_health_center_lock', $lock, '', false)) { wp_send_json_error(array('message' => 'Another scan is running. Wait for it to finish or for the session to expire (10 minutes).'), 409); return; }
    $state = $lock + array('phase' => 0, 'report' => array('started' => time(), 'checks' => array(), 'server' => array(), 'performance' => array('ttfb' => null, 'slow_http' => null, 'queries' => null, 'failed_jobs' => null, 'failed_jobs_limited' => false, 'overdue_cron' => null, 'cron_limited' => false, 'cache' => 'Not reported')));
    set_transient('tk_health_center_job_' . $token, $state, 600);
    wp_send_json_success(array('token' => $token, 'done' => false, 'message' => 'Checking server, compatibility, and cron…'));
}

function tk_health_center_step_ajax(): void {
    tk_health_center_authorize();
    $token = isset($_POST['token']) && is_string($_POST['token']) ? $_POST['token'] : '';
    if (!preg_match('/^[a-f0-9]{48}$/D', $token)) { wp_send_json_error(array('message' => 'Invalid scan session.'), 400); return; }
    $lock = get_option('tk_health_center_lock', array());
    $state = get_transient('tk_health_center_job_' . $token);
    if (!is_array($state) || ($lock['token'] ?? '') !== $token || (int) $state['owner'] !== get_current_user_id() || $state['expires'] < time()) { wp_send_json_error(array('message' => 'This session expired or belongs to another administrator. Previous results remain available.'), 409); return; }
    $step_key = 'tk_health_center_step_lock';
    if (!add_option($step_key, time(), '', false)) { wp_send_json_error(array('message' => 'A scan step is still running.'), 409); return; }
    $done = false; $message = '';
    try {
        // Re-read after acquiring the lock: a simultaneous step may have just finished.
        $state = get_transient('tk_health_center_job_' . $token);
        if (!is_array($state)) { throw new RuntimeException('Scan session expired.'); }
        if ($state['phase'] === 0) {
            tk_health_center_local($state['report']);
            $message = 'Measuring TTFB and checking homepage HTML and headers…';
        } elseif ($state['phase'] === 1) {
            tk_health_analyze_public($state['report'], tk_health_center_public_request());
            $message = 'Measuring database queries and HTTP requests in the homepage profile…';
        } elseif ($state['phase'] === 2) {
            tk_health_center_request_profile($state);
            $message = 'Preparing scan results…';
        } else {
            tk_health_center_finish_profile($state);
            $state['report']['completed'] = time();
            update_option('tk_health_center_report', $state['report'], false);
            $done = true; $message = 'Scan complete. Results saved. Scans run only when requested.';
        }
        $state['phase']++;
        if ($done) { delete_transient('tk_health_center_job_' . $token); delete_option('tk_health_center_lock'); }
        else { set_transient('tk_health_center_job_' . $token, $state, 600); }
    } catch (Throwable $error) {
        delete_transient('tk_health_center_job_' . $token); delete_option('tk_health_center_lock');
        if (!empty($state['probe'])) { tk_health_center_clean_probe($state['probe']); }
        delete_option($step_key);
        wp_send_json_error(array('message' => 'Scan failed. Previous results remain available. Please try again.'), 500); return;
    }
    delete_option($step_key);
    $result = array('token' => $token, 'done' => $done, 'message' => $message);
    if ($done) { ob_start(); tk_health_center_report_html($state['report'], !empty($_POST['full'])); $result['html'] = ob_get_clean(); }
    wp_send_json_success($result);
}

function tk_health_center_local(array &$report): void {
    global $wpdb, $wp_version;
    $report['performance'] += array('cron_limited' => false, 'failed_jobs' => null, 'failed_jobs_limited' => false);
    $memory = (string) ini_get('memory_limit'); $bytes = tk_health_memory_bytes($memory);
    $database = method_exists($wpdb, 'db_server_info') ? $wpdb->db_server_info() : $wpdb->db_version();
    $report['server'] = array('PHP Version' => PHP_VERSION, 'WordPress Version' => (string) $wp_version, 'Memory Limit' => $memory === '-1' ? 'Unlimited' : $memory, 'Database' => (string) $database, 'Object Cache' => wp_using_ext_object_cache() ? 'Active' : 'Inactive');
    tk_health_check($report, 'Performance', 'PHP memory limit', $bytes === null ? 'unknown' : ($bytes === -1 || $bytes >= 256 * 1048576 ? 'passed' : ($bytes < 128 * 1048576 ? 'critical' : 'warning')), 'Effective PHP memory limit: ' . $report['server']['Memory Limit']);
    tk_health_check($report, 'Performance', 'Persistent object cache', wp_using_ext_object_cache() ? 'passed' : 'warning', wp_using_ext_object_cache() ? 'External object-cache backend reported active.' : 'No persistent object-cache backend reported.');
    tk_health_check($report, 'Security', 'HTTPS homepage', strpos(home_url('/'), 'https://') === 0 ? 'passed' : 'critical', 'Configured homepage transport.');
    tk_health_check($report, 'Security', 'Public debug output', defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY && defined('WP_DEBUG') && WP_DEBUG ? 'critical' : 'passed', 'Checks WP_DEBUG and WP_DEBUG_DISPLAY.');
    tk_health_check($report, 'Security', 'Dashboard file editor', defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT || defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS ? 'passed' : 'warning', 'DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS should disable the dashboard code editor.');
    $overdue = 0; $inspected = 0; $cron = _get_cron_array();
    if (is_array($cron)) {
        foreach ($cron as $timestamp => $hooks) {
            foreach ($hooks as $events) { foreach ($events as $_) { if (++$inspected > 5000) { $report['performance']['cron_limited'] = true; break 3; } if ((int) $timestamp < time() - 900) { $overdue++; } } }
        }
        $report['performance']['overdue_cron'] = $overdue;
    }
    tk_health_check($report, 'Performance', 'Overdue cron events', is_array($cron) ? ($overdue ? 'warning' : 'passed') : 'unknown', is_array($cron) ? $overdue . ' events overdue by more than 15 minutes. Overdue does not prove execution failure.' . ($report['performance']['cron_limited'] ? ' Sample limited to 5,000 events.' : '') : 'Cron schedule unavailable.');
    if (function_exists('as_get_scheduled_actions') && did_action('action_scheduler_init')) {
        try {
            $failed = as_get_scheduled_actions(array('status' => 'failed', 'per_page' => 1001), 'ids');
            if (is_array($failed)) { $report['performance']['failed_jobs'] = min(1000, count($failed)); $report['performance']['failed_jobs_limited'] = count($failed) > 1000; }
        } catch (Throwable $error) { /* Unknown, never invent a zero. */ }
    }
    $failed = $report['performance']['failed_jobs'];
    tk_health_check($report, 'Performance', 'Recorded failed jobs', $failed === null ? 'unknown' : ($failed ? 'warning' : 'passed'), $failed === null ? 'WordPress core has no complete cron failure history; Action Scheduler failure records unavailable.' : 'Retained Action Scheduler failed jobs: ' . $failed . ($report['performance']['failed_jobs_limited'] ? '+' : '') . '. Historical failures may already have been resolved.');
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugins = get_plugins(); $active = array_unique(array_merge((array) get_option('active_plugins', array()), array_keys((array) get_site_option('active_sitewide_plugins', array()))));
    $incompatible = array(); $requirements = 0;
    foreach ($active as $file) {
        if (!isset($plugins[$file])) { $incompatible[] = $file . ': plugin file missing'; continue; }
        $data = $plugins[$file];
        foreach (array('RequiresPHP' => PHP_VERSION, 'RequiresWP' => $wp_version) as $key => $actual) { if (!empty($data[$key])) { $requirements++; if (version_compare($actual, $data[$key], '<')) { $incompatible[] = $data['Name'] . ': requires ' . $key . ' ' . $data[$key]; } } }
    }
    $theme = wp_get_theme();
    foreach (array('RequiresPHP' => PHP_VERSION, 'RequiresWP' => $wp_version) as $key => $actual) { if ($theme->get($key)) { $requirements++; if (version_compare($actual, $theme->get($key), '<')) { $incompatible[] = 'Theme: requires ' . $key . ' ' . $theme->get($key); } } }
    tk_health_check($report, 'Compatibility', 'Plugin/theme declared requirements', $incompatible ? 'critical' : ($requirements ? 'passed' : 'unknown'), $incompatible ? implode('; ', array_slice($incompatible, 0, 20)) : $requirements . ' declared PHP/WP version requirements checked; undeclared requirements and runtime compatibility are not tested.');
    tk_health_check($report, 'Compatibility', 'Tool Kits PHP requirement', version_compare(PHP_VERSION, TK_MINIMUM_PHP, '>=') ? 'passed' : 'critical', 'Tool Kits requires PHP ' . TK_MINIMUM_PHP . '+.');
}
