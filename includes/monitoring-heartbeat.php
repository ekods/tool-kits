<?php
if (!defined('ABSPATH')) { exit; }

if (!defined('TK_HEARTBEAT_URL')) {
    define('TK_HEARTBEAT_URL', '');
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

function tk_heartbeat_send(): array {
    $url = (string) tk_get_option('heartbeat_collector_url', '');
    if ($url === '') {
        $url = TK_HEARTBEAT_URL;
    }
    $secret = (string) tk_get_option('heartbeat_auth_key', '');
    if ($secret === '') {
        $secret = TK_HEARTBEAT_AUTH_KEY;
    }
    if ($url === '' || $secret === '') {
        return array('ok' => false, 'message' => 'Missing heartbeat URL or auth key.');
    }
    $hide_login_enabled = (int) tk_get_option('hide_login_enabled', 0) === 1;
    $payload = array(
        'site_url' => home_url('/'),
        'plugin' => 'tool-kits',
        'version' => defined('TK_VERSION') ? TK_VERSION : '',
        'timestamp' => time(),
        'active' => true,
        'wp_version' => get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'hide_login_enabled' => $hide_login_enabled,
        'hide_login_slug' => $hide_login_enabled ? tk_hide_login_slug() : '',
        'hide_login_url' => $hide_login_enabled ? tk_hide_login_custom_url() : '',
    );
    $body = wp_json_encode($payload);
    if ($body === false) {
        return array('ok' => false, 'message' => 'Failed to encode heartbeat payload.');
    }
    $signature = hash_hmac('sha256', $body, $secret);
    $headers = array(
        'Content-Type' => 'application/json',
        'X-Auth-Signature' => $signature,
        'X-Auth-Timestamp' => (string) $payload['timestamp'],
    );
    $http_user = (string) tk_get_option('heartbeat_http_user', '');
    $http_pass = (string) tk_get_option('heartbeat_http_pass', '');
    if ($http_user === '' && $http_pass === '') {
        $http_user = TK_HEARTBEAT_HTTP_USER;
        $http_pass = TK_HEARTBEAT_HTTP_PASS;
    }
    if ($http_user !== '' || $http_pass !== '') {
        $headers['Authorization'] = 'Basic ' . base64_encode($http_user . ':' . $http_pass);
    }
    $response = wp_remote_post($url, array(
        'timeout' => 10,
        'headers' => $headers,
        'body' => $body,
    ));
    if (is_wp_error($response)) {
        return array('ok' => false, 'message' => $response->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code >= 200 && $code < 300) {
        return array('ok' => true, 'message' => 'Heartbeat accepted.');
    }
    $resp_message = wp_remote_retrieve_response_message($response);
    $resp_body = wp_remote_retrieve_body($response);
    $detail = $resp_message !== '' ? $resp_message : 'Unexpected response.';
    if (is_string($resp_body) && $resp_body !== '') {
        $detail .= ' ' . substr(trim($resp_body), 0, 160);
    }
    return array('ok' => false, 'message' => 'HTTP ' . $code . ': ' . $detail);
}

function tk_heartbeat_record_result(array $result): bool {
    $message = isset($result['message']) ? trim((string) $result['message']) : '';
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
