<?php
if (!defined('ABSPATH')) { exit; }

function tk_monitoring_404_health_init() {
    add_action('template_redirect', 'tk_monitoring_log_404', 20);
    add_action('admin_post_tk_404_monitoring_save', 'tk_404_monitoring_save');
    add_action('admin_post_tk_404_monitoring_clear', 'tk_404_monitoring_clear');
    add_action('admin_post_tk_healthcheck_save', 'tk_healthcheck_save');
    add_action('init', 'tk_healthcheck_endpoint');
    add_action('rest_api_init', 'tk_healthcheck_register_rest');
    add_action('wp_ajax_tk_realtime_health', 'tk_realtime_health_ajax');
}

function tk_monitoring_log_404() {
    if (is_admin() || wp_doing_ajax() || !is_404()) {
        return;
    }
    if (!tk_get_option('monitoring_404_enabled', 1)) {
        return;
    }
    $path = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($path === '') {
        return;
    }
    $excludes = (string) tk_get_option('monitoring_404_exclude_paths', "/wp-admin\n/wp-login.php\n/wp-cron.php\n");
    $list = array_filter(array_map('trim', explode("\n", $excludes)));
    foreach ($list as $item) {
        if ($item !== '' && strpos($path, $item) === 0) {
            return;
        }
    }
    $key = md5($path);
    $log = tk_get_option('monitoring_404_log', array());
    if (!is_array($log)) {
        $log = array();
    }
    $entry = isset($log[$key]) && is_array($log[$key]) ? $log[$key] : array(
        'path' => $path,
        'count' => 0,
        'last' => 0,
        'ref' => '',
        'ua' => '',
    );
    $entry['count'] = (int) $entry['count'] + 1;
    $entry['last'] = time();
    $entry['ref'] = isset($_SERVER['HTTP_REFERER']) ? substr((string) $_SERVER['HTTP_REFERER'], 0, 200) : '';
    $entry['ua'] = tk_user_agent();
    $log[$key] = $entry;

    if (count($log) > 200) {
        uasort($log, function($a, $b) {
            $a_count = isset($a['count']) ? (int) $a['count'] : 0;
            $b_count = isset($b['count']) ? (int) $b['count'] : 0;
            if ($a_count === $b_count) {
                $a_last = isset($a['last']) ? (int) $a['last'] : 0;
                $b_last = isset($b['last']) ? (int) $b['last'] : 0;
                return $b_last <=> $a_last;
            }
            return $b_count <=> $a_count;
        });
        $log = array_slice($log, 0, 200, true);
    }

    tk_update_option('monitoring_404_log', $log);
}

function tk_404_monitoring_save() {
    tk_check_nonce('tk_404_monitoring_save');
    tk_update_option('monitoring_404_enabled', !empty($_POST['monitoring_404_enabled']) ? 1 : 0);
    tk_update_option('monitoring_404_exclude_paths', (string) tk_post('monitoring_404_exclude_paths', "/wp-admin\n/wp-login.php\n/wp-cron.php\n"));
    wp_redirect(admin_url('admin.php?page=tool-kits-monitoring#missing&tk_404_updated=1'));
    exit;
}

function tk_404_monitoring_clear() {
    tk_check_nonce('tk_404_monitoring_clear');
    tk_update_option('monitoring_404_log', array());
    wp_redirect(admin_url('admin.php?page=tool-kits-monitoring#missing&tk_404_cleared=1'));
    exit;
}

function tk_healthcheck_save() {
    tk_check_nonce('tk_healthcheck_save');
    tk_update_option('monitoring_healthcheck_enabled', !empty($_POST['monitoring_healthcheck_enabled']) ? 1 : 0);
    $health_key = isset($_POST['monitoring_healthcheck_key']) ? sanitize_text_field(wp_unslash($_POST['monitoring_healthcheck_key'])) : '';
    tk_update_option('monitoring_healthcheck_key', $health_key);
    wp_redirect(admin_url('admin.php?page=tool-kits-monitoring#health&tk_health_updated=1'));
    exit;
}

function tk_healthcheck_endpoint() {
    if (!tk_get_option('monitoring_healthcheck_enabled', 1)) {
        return;
    }
    if (!isset($_GET['tk-health'])) {
        return;
    }
    if (!tk_healthcheck_validate()) {
        status_header(403);
        echo 'forbidden';
        exit;
    }
    $data = tk_healthcheck_data();
    wp_send_json($data);
}

function tk_healthcheck_register_rest() {
    register_rest_route('tool-kits/v1', '/health', array(
        'methods' => 'GET',
        'callback' => function($request) {
            if (!tk_healthcheck_validate((string) $request->get_param('key'))) {
                return new WP_REST_Response(array('ok' => false, 'error' => 'forbidden'), 403);
            }
            return tk_healthcheck_data();
        },
        'permission_callback' => '__return_true',
    ));
}

function tk_healthcheck_validate($key = null) {
    $saved = (string) tk_get_option('monitoring_healthcheck_key', '');
    if ($saved === '') {
        return false;
    }
    if ($key === null && isset($_GET['key'])) {
        $key = (string) wp_unslash($_GET['key']);
    }
    return hash_equals($saved, (string) $key);
}

function tk_healthcheck_data() {
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : array();
    $disk_free = @disk_free_space(ABSPATH);
    $disk_total = @disk_total_space(ABSPATH);
    $cron = wp_next_scheduled('wp_cron');
    return array(
        'ok' => true,
        'time' => time(),
        'wp' => array(
            'home' => home_url('/'),
            'version' => get_bloginfo('version'),
        ),
        'server' => array(
            'php' => PHP_VERSION,
            'load' => $load,
            'disk_free' => is_float($disk_free) ? (int) $disk_free : null,
            'disk_total' => is_float($disk_total) ? (int) $disk_total : null,
        ),
        'cron' => array(
            'next' => $cron ? (int) $cron : null,
            'disabled' => (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON),
        ),
    );
}

function tk_realtime_health_ajax() {
    check_ajax_referer('tk_realtime_health', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'forbidden'));
    }
    $data = tk_realtime_health_data();
    wp_send_json_success($data);
}

function tk_realtime_health_data(): array {
    $memory_used = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
    $memory_peak = function_exists('memory_get_peak_usage') ? (int) memory_get_peak_usage(true) : 0;
    $memory_limit = tk_parse_size(ini_get('memory_limit'));
    $memory_pct = $memory_limit > 0 ? round(($memory_used / $memory_limit) * 100, 1) : null;
    $errors = tk_error_rate_recent(300);
    $heavy = tk_heaviest_active_plugin();

    return array(
        'time' => time(),
        'memory' => array(
            'used' => $memory_used,
            'peak' => $memory_peak,
            'limit' => $memory_limit,
            'percent' => $memory_pct,
        ),
        'errors' => $errors,
        'heavy_plugin' => $heavy,
    );
}

function tk_error_rate_recent(int $seconds): array {
    $path = trailingslashit(WP_CONTENT_DIR) . 'debug.log';
    if (!file_exists($path)) {
        return array('available' => false);
    }
    $lines = tk_tail_file($path, 200000);
    if ($lines === '') {
        return array('available' => true, 'count' => 0, 'per_min' => 0);
    }
    $count = 0;
    $since = time() - max(60, $seconds);
    foreach (explode("\n", $lines) as $line) {
        if ($line === '') {
            continue;
        }
        if (strpos($line, 'PHP ') === false && strpos($line, 'Fatal') === false) {
            continue;
        }
        if (!preg_match('/^\[([^\]]+)\]/', $line, $m)) {
            continue;
        }
        $ts = strtotime($m[1]);
        if ($ts === false) {
            continue;
        }
        if ($ts >= $since) {
            $count++;
        }
    }
    $per_min = round($count / ($seconds / 60), 2);
    return array('available' => true, 'count' => $count, 'per_min' => $per_min, 'window_sec' => $seconds);
}

function tk_heaviest_active_plugin(): array {
    $cache = get_transient('tk_heaviest_plugin');
    if (is_array($cache)) {
        return $cache;
    }
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $active = (array) get_option('active_plugins', array());
    if (empty($active)) {
        return array('name' => '-', 'size' => 0);
    }
    $sizes = array();
    foreach ($active as $plugin_file) {
        $base = dirname($plugin_file);
        $path = $base === '.' ? WP_PLUGIN_DIR . '/' . $plugin_file : WP_PLUGIN_DIR . '/' . $base;
        $size = tk_dir_size($path);
        $sizes[$plugin_file] = $size;
    }
    arsort($sizes);
    $top_file = key($sizes);
    $top_size = reset($sizes);
    $info = get_plugins();
    $name = isset($info[$top_file]['Name']) ? $info[$top_file]['Name'] : $top_file;
    $data = array('name' => $name, 'size' => (int) $top_size);
    set_transient('tk_heaviest_plugin', $data, 10 * MINUTE_IN_SECONDS);
    return $data;
}

function tk_dir_size($path): int {
    if (is_file($path)) {
        return (int) filesize($path);
    }
    if (!is_dir($path)) {
        return 0;
    }
    $size = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    return $size;
}

function tk_tail_file(string $path, int $max_bytes): string {
    if (!is_readable($path)) {
        return '';
    }
    $size = filesize($path);
    if ($size <= 0) {
        return '';
    }
    $fp = fopen($path, 'rb');
    if (!$fp) {
        return '';
    }
    $seek = max(0, $size - $max_bytes);
    fseek($fp, $seek);
    $data = stream_get_contents($fp);
    fclose($fp);
    return is_string($data) ? $data : '';
}

function tk_parse_size($value): int {
    if (!is_string($value) || $value === '') {
        return 0;
    }
    if (is_numeric($value)) {
        return (int) $value;
    }
    $unit = strtolower(substr($value, -1));
    $num = (int) $value;
    if ($unit === 'g') {
        return $num * 1024 * 1024 * 1024;
    }
    if ($unit === 'm') {
        return $num * 1024 * 1024;
    }
    if ($unit === 'k') {
        return $num * 1024;
    }
    return (int) $value;
}
