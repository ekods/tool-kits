<?php
if (!defined('ABSPATH')) { exit; }

if (!defined('TK_HEARTBEAT_URL')) {
    define('TK_HEARTBEAT_URL', 'https://nexamonitor.theteamtheteam.com/api/toolkits/heartbeat');
}
if (!defined('TK_HEARTBEAT_AUTH_KEY')) {
    define('TK_HEARTBEAT_AUTH_KEY', '');
}
if (!defined('TK_HEARTBEAT_HTTP_USER')) {
    define('TK_HEARTBEAT_HTTP_USER', '');
}
if (!defined('TK_HEARTBEAT_HTTP_PASS')) {
    define('TK_HEARTBEAT_HTTP_PASS', '');
}
if (!defined('TK_LICENSE_SERVER_URL')) {
    define('TK_LICENSE_SERVER_URL', '');
}

function tk_heartbeat_init() {
    add_action('tk_heartbeat_cron', 'tk_heartbeat_cron_run');
    add_action('init', 'tk_heartbeat_schedule');
    add_action('init', 'tk_heartbeat_register_cron_monitors', PHP_INT_MAX);
}

function tk_heartbeat_register_cron_monitors(): void {
    if (!tk_heartbeat_enabled() || !defined('DOING_CRON') || !DOING_CRON || !function_exists('_get_cron_array')) {
        return;
    }

    $hooks = array();
    foreach ((array) _get_cron_array() as $events) {
        foreach (array_keys((array) $events) as $hook) {
            $hooks[(string) $hook] = true;
        }
    }

    foreach (array_keys($hooks) as $hook) {
        add_action($hook, 'tk_heartbeat_cron_monitor_start', -999999);
        add_action($hook, 'tk_heartbeat_cron_monitor_end', 999999);
    }
}

function tk_heartbeat_cron_monitor_start(): void {
    global $tk_heartbeat_cron_starts;

    $hook = (string) current_filter();
    $tk_heartbeat_cron_starts[$hook] = array(
        'time' => microtime(true),
        'memory' => memory_get_usage(true),
        'peak' => memory_get_peak_usage(true),
    );
}

function tk_heartbeat_cron_monitor_end(): void {
    global $tk_heartbeat_cron_starts;

    $hook = (string) current_filter();
    if (empty($tk_heartbeat_cron_starts[$hook])) {
        return;
    }

    $start = $tk_heartbeat_cron_starts[$hook];
    unset($tk_heartbeat_cron_starts[$hook]);
    $metrics = get_option('tk_heartbeat_cron_metrics', array());
    if (!is_array($metrics)) {
        $metrics = array();
    }

    $memory_delta = max(
        memory_get_usage(true) - (int) $start['memory'],
        memory_get_peak_usage(true) - (int) $start['peak'],
        0
    );
    $metrics[$hook] = array(
        'last_run' => time(),
        'last_duration_ms' => (int) round((microtime(true) - (float) $start['time']) * 1000),
        'last_memory_mb' => round($memory_delta / 1048576, 2),
    );

    uasort($metrics, static function ($left, $right) {
        return (int) ($right['last_run'] ?? 0) <=> (int) ($left['last_run'] ?? 0);
    });
    update_option('tk_heartbeat_cron_metrics', array_slice($metrics, 0, 100, true), false);
}

function tk_heartbeat_cron_source(string $hook): string {
    $wordpress_hooks = array('wp_', 'delete_expired_transients', 'recovery_mode_', 'update_network_');
    foreach ($wordpress_hooks as $prefix) {
        if (strpos($hook, $prefix) === 0) {
            return 'wordpress';
        }
    }

    return strpos($hook, 'tk_') === 0 ? 'tool-kits' : 'plugin-or-theme';
}

function tk_heartbeat_cron_report(): array {
    $now = time();
    $metrics = get_option('tk_heartbeat_cron_metrics', array());
    $events = array();
    $due_now = 0;
    $overdue = 0;

    if (function_exists('_get_cron_array')) {
        foreach ((array) _get_cron_array() as $timestamp => $hooks) {
            foreach ((array) $hooks as $hook => $instances) {
                foreach ((array) $instances as $instance) {
                    $is_overdue = (int) $timestamp < ($now - MINUTE_IN_SECONDS * 5);
                    if ((int) $timestamp <= $now) {
                        $due_now++;
                    }
                    if ($is_overdue) {
                        $overdue++;
                    }
                    $metric = is_array($metrics[$hook] ?? null) ? $metrics[$hook] : array();
                    $events[] = array(
                        'hook' => (string) $hook,
                        'schedule' => !empty($instance['schedule']) ? (string) $instance['schedule'] : 'single',
                        'source' => tk_heartbeat_cron_source((string) $hook),
                        'next_run' => (int) $timestamp,
                        'last_run' => (int) ($metric['last_run'] ?? 0),
                        'last_duration_ms' => (int) ($metric['last_duration_ms'] ?? 0),
                        'last_memory_mb' => (float) ($metric['last_memory_mb'] ?? 0),
                        'overdue' => $is_overdue,
                    );
                }
            }
        }
    }

    usort($events, static function ($left, $right) {
        $left_score = (!empty($left['overdue']) ? 1000000 : 0) + (int) $left['last_duration_ms'] + ((float) $left['last_memory_mb'] * 10);
        $right_score = (!empty($right['overdue']) ? 1000000 : 0) + (int) $right['last_duration_ms'] + ((float) $right['last_memory_mb'] * 10);
        return $right_score <=> $left_score;
    });

    return array(
        'spawn_blocked' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        'total_events' => count($events),
        'due_now' => $due_now,
        'overdue' => $overdue,
        'last_scan' => $now,
        'events' => array_slice($events, 0, 25),
    );
}

function tk_heartbeat_enabled(): bool {
    if (defined('TK_HEARTBEAT_ENABLED')) {
        return (bool) TK_HEARTBEAT_ENABLED;
    }
    return (int) tk_get_option('heartbeat_enabled', 0) === 1;
}

function tk_heartbeat_schedule() {
    if (!tk_heartbeat_enabled()) {
        tk_heartbeat_unschedule();
        return;
    }
    if (!wp_next_scheduled('tk_heartbeat_cron')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'tk_heartbeat_cron');
    }
}

function tk_heartbeat_unschedule() {
    $timestamp = wp_next_scheduled('tk_heartbeat_cron');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'tk_heartbeat_cron');
    }
}

function tk_heartbeat_seo_geo_summary(): array {
    $seo = tk_get_option('seo_content_audit_report', array());
    $geo = tk_get_option('seo_geo_audit_report', array());
    $duplicates = tk_get_option('geo_schema_duplicate_report', array());
    $seo = is_array($seo) ? $seo : array();
    $geo = is_array($geo) ? $geo : array();
    $duplicates = is_array($duplicates) ? $duplicates : array();

    return array(
        'seo_score' => isset($seo['average_score']) ? max(0, min(100, (int) $seo['average_score'])) : null,
        'geo_score' => isset($geo['average_score']) ? max(0, min(100, (int) $geo['average_score'])) : null,
        'seo_flagged' => max(0, (int) ($seo['flagged_count'] ?? 0)),
        'geo_issues' => max(0, (int) ($geo['issue_count'] ?? 0)),
        'schema_issues' => max(0, (int) ($duplicates['issue_count'] ?? 0)),
        'indexnow_queue' => function_exists('tk_indexnow_queue') ? count(tk_indexnow_queue()) : 0,
        'last_seo_audit' => max(0, (int) ($seo['scanned_at'] ?? 0)),
        'last_geo_audit' => max(0, (int) ($geo['scanned_at'] ?? 0)),
    );
}

function tk_heartbeat_operations_summary(): array {
    $operations = tk_get_option('operations_health_report', array());
    $delivery = tk_get_option('smtp_dns_report', array());
    $operations = is_array($operations) ? $operations : array();
    $delivery = is_array($delivery) ? $delivery : array();

    return array(
        'operations_score' => isset($operations['score']) ? max(0, min(100, (int) $operations['score'])) : null,
        'overdue_jobs' => max(0, (int) ($operations['overdue_count'] ?? 0)),
        'missing_workers' => isset($operations['missing_workers']) && is_array($operations['missing_workers']) ? count($operations['missing_workers']) : 0,
        'delivery_score' => isset($delivery['score']) ? max(0, min(100, (int) $delivery['score'])) : null,
        'delivery_domain' => sanitize_text_field((string) ($delivery['domain'] ?? '')),
        'last_operations_scan' => max(0, (int) ($operations['scanned_at'] ?? 0)),
        'last_delivery_scan' => max(0, (int) ($delivery['scanned_at'] ?? 0)),
    );
}

function tk_heartbeat_send(): array {
    $url = tk_heartbeat_collector_url();
    $secret = tk_heartbeat_auth_key();
    if ($url === '' || $secret === '') {
        if ($url === '') {
            return array('ok' => false, 'message' => tk_toolkits_missing_config_message('collector_url'));
        }
        return array('ok' => false, 'message' => tk_toolkits_missing_config_message('collector_token'));
    }
    $hide_login_enabled = (int) tk_get_option('hide_login_enabled', 0) === 1;
    $acf_cleanup = get_option('tk_db_cleanup_acf_last_run', array());
    global $wpdb;
    $revision_limit = function_exists('tk_db_cleanup_effective_revision_limit')
        ? tk_db_cleanup_effective_revision_limit()
        : 5;
    $site_url = home_url('/');
    $payload = array(
        'action' => 'heartbeat',
        'license_key' => trim((string) tk_get_option('license_key', '')),
        'license_status' => (string) tk_get_option('license_status', 'inactive'),
        'license_site_url' => (string) tk_get_option('license_site_url', ''),
        'site_url' => $site_url,
        'url' => $site_url,
        'domain' => (string) parse_url($site_url, PHP_URL_HOST),
        'site_id' => tk_toolkits_install_id(),
        'site_name' => get_bloginfo('name'),
        'env' => tk_license_env(),
        'plugin' => 'tool-kits',
        'version' => defined('TK_VERSION') ? TK_VERSION : '',
        'timestamp' => time(),
        'status' => 'active',
        'active' => true,
        'wp_version' => get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'hide_login_enabled' => $hide_login_enabled,
        'hide_login_slug' => $hide_login_enabled ? tk_hide_login_slug() : '',
        'hide_login_url' => $hide_login_enabled ? tk_hide_login_custom_url() : '',
        'maintenance' => array(
            'revision_limit' => $revision_limit,
            'revision_limit_source' => defined('WP_POST_REVISIONS') ? 'wp-config' : 'tool-kits-default',
            'revisions_total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"),
            'last_revision_cleanup' => (int) get_option('tk_db_cleanup_revision_last_run', 0),
            'acf_cleanup' => array(
                'last_run' => isset($acf_cleanup['timestamp']) ? (int) $acf_cleanup['timestamp'] : 0,
                'references_deleted' => isset($acf_cleanup['references']) ? (int) $acf_cleanup['references'] : 0,
                'values_deleted' => isset($acf_cleanup['values']) ? (int) $acf_cleanup['values'] : 0,
                'option_references_deleted' => isset($acf_cleanup['option_references']) ? (int) $acf_cleanup['option_references'] : 0,
                'option_values_deleted' => isset($acf_cleanup['option_values']) ? (int) $acf_cleanup['option_values'] : 0,
                'rows_deleted' => isset($acf_cleanup['rows_deleted']) ? (int) $acf_cleanup['rows_deleted'] : 0,
            ),
        ),
        'wp_cron' => tk_heartbeat_cron_report(),
        'seo_geo' => tk_heartbeat_seo_geo_summary(),
        'operations' => tk_heartbeat_operations_summary(),
    );
    $body = wp_json_encode($payload);
    if ($body === false) {
        return array('ok' => false, 'message' => 'Failed to encode heartbeat payload.');
    }
    $response = tk_toolkits_signed_post($url, $body, (int) $payload['timestamp'], array(
        'timeout' => 10,
    ));
    if (is_wp_error($response)) {
        return array('ok' => false, 'message' => $response->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code >= 200 && $code < 300) {
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (is_array($data) && array_key_exists('ok', $data) && empty($data['ok'])) {
            $message = isset($data['message']) ? (string) $data['message'] : 'Heartbeat accepted with license warning.';
            $status = isset($data['license_status']) ? (string) $data['license_status'] : 'warning';
            return array('ok' => false, 'message' => $status . ': ' . $message);
        }
        return array('ok' => true, 'message' => 'Heartbeat accepted.');
    }
    $resp_message = wp_remote_retrieve_response_message($response);
    $resp_body = wp_remote_retrieve_body($response);
    $detail = $resp_message !== '' ? $resp_message : 'Unexpected response.';
    if (is_string($resp_body) && $resp_body !== '') {
        $detail .= ' ' . substr(trim($resp_body), 0, 160);
        $data = json_decode($resp_body, true);
        if (is_array($data) && isset($data['status']) && (string) $data['status'] === 'missing_fields') {
            $detail .= ' Sent fields: ' . implode(', ', array_keys($payload)) . '.';
        }
    }
    return array('ok' => false, 'message' => 'HTTP ' . $code . ': ' . $detail);
}

function tk_heartbeat_record_result(array $result): bool {
    $message = isset($result['message']) ? trim((string) $result['message']) : '';
    tk_heartbeat_record_diagnostic_result($result, tk_heartbeat_collector_url());
    if (empty($result['ok'])) {
        if ($message !== '') {
            set_transient('tk_heartbeat_last_error', $message, MINUTE_IN_SECONDS * 10);
        }
        return false;
    }
    if ($message === '') {
        $message = 'Heartbeat sent.';
    }
    delete_transient('tk_heartbeat_last_error');
    return true;
}

function tk_heartbeat_cron_run(): void {
    $result = tk_heartbeat_send();
    if (!$result['ok'] && !empty($result['message'])) {
        error_log('[Tool Kits] Heartbeat cron failed: ' . $result['message']);
    }
    tk_heartbeat_record_result($result);
}

function tk_heartbeat_manual_send() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_heartbeat_manual');
    $result = tk_heartbeat_send();
    $ok = !empty($result['ok']);
    $status = $ok ? 'ok' : 'fail';
    tk_heartbeat_record_result($result);
    wp_redirect(add_query_arg(array(
        'page' => 'tool-kits-monitoring',
        'tk_heartbeat' => $status,
    ), admin_url('admin.php')));
    exit;
}
