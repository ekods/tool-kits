<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/license-receipt.php';

function tk_license_public_keys(): array {
    // A wp-config constant allows managed installations to pin a private issuer.
    // No option/filter or response-supplied public key may become a trust anchor.
    static $keys;
    if ($keys === null) {
        $file = defined('TK_LICENSE_PUBLIC_KEYS_FILE') ? TK_LICENSE_PUBLIC_KEYS_FILE : __DIR__ . '/license-public-keys.php';
        $loaded = is_string($file) && is_file($file) ? require $file : array();
        $keys = is_array($loaded) ? $loaded : array();
    }
    return $keys;
}

function tk_license_verified_claims(string $nonce = '', $receipt = null) {
    if ($receipt === null) { $receipt = tk_get_option('license_receipt', array()); }
    return TK_License_Receipt::verify($receipt, tk_license_public_keys(), array(
        'license_key' => (string) tk_get_option('license_key', ''),
        'site_url' => home_url('/'),
        'site_id' => tk_toolkits_install_id(),
    ), time(), $nonce);
}

function tk_license_has_valid_signature(): bool {
    $claims = tk_license_verified_claims();
    if ($claims === false) { return false; }
    // Display fields must match the signed authority, never override it.
    $expiry = (string) tk_get_option('license_expires_at', '');
    $expiry = $expiry === '' ? 0 : strtotime($expiry);
    return (string) tk_get_option('license_type', '') === $claims['license_type']
        && $expiry !== false && $expiry === $claims['license_expires_at']
        && TK_License_Receipt::normalize_site((string) tk_get_option('license_site_url', '')) === $claims['site_url'];
}

function tk_license_features_enabled(): bool {
    if ((string) tk_get_option('license_status', 'inactive') !== 'valid' || !tk_license_has_valid_signature()) { return false; }
    // A cloned database must not become licensed by keeping the original home URL.
    if (isset($_SERVER['HTTP_HOST'])) {
        $actual = parse_url('https://' . $_SERVER['HTTP_HOST']);
        $home = parse_url(home_url('/'));
        if (!is_array($actual) || !is_array($home) || isset($actual['user']) || isset($actual['pass'])
            || isset($actual['path']) || isset($actual['query']) || isset($actual['fragment'])
            || strtolower($actual['host'] ?? '') !== strtolower($home['host'] ?? '')
            || (int) ($actual['port'] ?? (($home['scheme'] ?? '') === 'https' ? 443 : 80))
                !== (int) ($home['port'] ?? (($home['scheme'] ?? '') === 'https' ? 443 : 80))) { return false; }
    }
    return true;
}

function tk_license_is_valid(): bool { return tk_license_features_enabled(); }

function tk_license_validate(bool $force = false): array {
    $now = time();
    $status = (string) tk_get_option('license_status', 'inactive');
    $message = (string) tk_get_option('license_message', '');
    $checked = (int) tk_get_option('license_last_checked', 0);
    $verified = tk_license_features_enabled();
    // A genuine receipt whose features stay off for this request (e.g. www/apex alias or
    // proxy port in HTTP_HOST) would otherwise trigger a blocking check on every load.
    // The throttle only skips the network call; it never enables features.
    $host_mismatch = !$verified && $status === 'valid' && tk_license_has_valid_signature();
    // An invalid/legacy receipt always triggers revalidation; changing the local
    // timestamp or status cannot extend the issuer's signed validity window.
    if (!$force && ($status !== 'valid' || $verified || $host_mismatch) && $checked > 0 && $checked <= $now
        && $now - $checked < ($host_mismatch ? 600 : ($verified && tk_get_option('license_last_error_message', '') !== '' ? 300 : 21600))) {
        return array('status' => $status, 'message' => $message);
    }
    $key = trim((string) tk_get_option('license_key', ''));
    $url = tk_license_server_url();
    $failure = '';
    $failure_status = 'error';
    if ($key === '') { $failure = tk_toolkits_missing_config_message('license_key'); $failure_status = 'missing'; }
    elseif (!tk_toolkits_license_server_is_trusted($url)) { $failure = 'License endpoint must use an approved HTTPS URL.'; $failure_status = 'missing_server'; }
    elseif (tk_heartbeat_auth_key() === '') { $failure = tk_toolkits_missing_config_message('collector_token'); $failure_status = 'missing_secret'; }
    if ($failure === '') {
        try { $nonce = bin2hex(random_bytes(32)); }
        catch (Exception $e) { $failure = 'Cannot generate a secure license challenge.'; }
    }
    if ($failure === '') {
        $payload = array('license_key' => $key, 'site_url' => home_url('/'), 'site_id' => tk_toolkits_install_id(),
            'env' => tk_license_env(), 'plugin' => 'tool-kits', 'action' => 'activate', 'timestamp' => $now,
            'protocol' => 1, 'request_nonce' => $nonce);
        $body = wp_json_encode($payload);
        if (!is_string($body)) { $failure = 'Cannot encode license request.'; }
        else {
            $response = tk_toolkits_signed_post($url, $body, $now, array('timeout' => 10, 'license_request' => true));
            $network_error = is_wp_error($response);
            $code = $network_error ? 0 : (int) wp_remote_retrieve_response_code($response);
            $raw = $network_error ? '' : (string) wp_remote_retrieve_body($response);
            $data = strlen($raw) <= 65536 ? json_decode($raw, true, 16) : null;
            // Only a parsed issuer denial (ok:false) is a verdict; a bare 4xx can be a CDN
            // challenge or a 404 during deploy and is treated like an outage.
            $unexpected_4xx = $code >= 400 && $code < 500 && !(is_array($data) && ($data['ok'] ?? null) === false);
            // A temporary outage may use an already verified receipt, but only
            // until its original signed deadline. Never renew it locally.
            if (($network_error || $code >= 500 || $code === 429 || $unexpected_4xx) && $status === 'valid' && tk_license_has_valid_signature()) {
                $result = array('status' => 'valid', 'message' => 'License server temporarily unavailable. Using the existing signed license until its original expiry.');
                tk_update_options(array('license_last_checked' => $now, 'license_last_failure' => $now, 'license_last_error_message' => $result['message']));
                return $result;
            }
            if ($network_error) { $failure = $response->get_error_message(); }
            else {
                $claims = is_array($data) && ($data['ok'] ?? null) === true && ($data['status'] ?? '') === 'valid'
                    && $code >= 200 && $code < 300 ? tk_license_verified_claims($nonce, $data['receipt'] ?? array()) : false;
                if ($claims !== false) {
                    $result = array('status' => 'valid', 'message' => 'License verified successfully.');
                    tk_license_update_state(array('license_status' => 'valid', 'license_message' => $result['message'],
                        'license_last_checked' => $now, 'license_env' => tk_license_env(), 'license_receipt' => $data['receipt'],
                        'license_type' => $claims['license_type'], 'license_site_url' => $claims['site_url'],
                        'license_expires_at' => $claims['license_expires_at'] === 0 ? '' : gmdate('c', $claims['license_expires_at'])));
                    tk_license_record_diagnostic_result($result, $url);
                    return $result;
                }
                $failure_status = 'invalid';
                $failure = 'The server did not return a valid signed license receipt for this request.';
                // Denial labels cannot turn a failed response into activation.
                if (is_array($data) && in_array($data['status'] ?? '', array('revoked', 'expired', 'not_found', 'site_mismatch', 'plugin_mismatch'), true)) {
                    $failure_status = $data['status'];
                    $failure = 'License rejected by the issuer: ' . $failure_status . '.';
                }
            }
        }
    }
    $result = array('status' => $failure_status, 'message' => tk_toolkits_license_validation_message($failure));
    tk_license_update_state(array('license_status' => $failure_status, 'license_message' => $result['message'],
        'license_last_checked' => $now, 'license_receipt' => array()));
    tk_license_record_diagnostic_result($result, $url);
    return $result;
}

function tk_license_refresh_schedule(): void {
    if (trim((string) tk_get_option('license_key', '')) === '') {
        wp_clear_scheduled_hook('tk_license_refresh');
        return;
    }
    if (!wp_next_scheduled('tk_license_refresh')) { wp_schedule_event(time() + 60, 'hourly', 'tk_license_refresh'); }
}

function tk_toolkits_install_id(): string {
    $id = (string) tk_get_option('toolkits_install_id', '');
    if ($id === '') {
        $id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
        tk_update_option('toolkits_install_id', $id);
    }
    return $id;
}

function tk_license_env(): string {
    $host = parse_url(home_url('/'), PHP_URL_HOST);
    $host = is_string($host) ? strtolower($host) : '';
    if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
        return 'local';
    }
    $locals = array('.local', '.test', '.dev', '.internal');
    foreach ($locals as $suffix) {
        if (substr($host, -strlen($suffix)) === $suffix) {
            return 'local';
        }
    }
    if (preg_match('/(^staging\.|\.staging\.|^stage\.|\.stage\.)/', $host)) {
        return 'staging';
    }
    return 'prod';
}

function tk_toolkits_collector_url(): string {
    $url = trim((string) tk_get_option('heartbeat_collector_url', ''));
    if ($url === '' && defined('TK_HEARTBEAT_URL') && TK_HEARTBEAT_URL !== '') {
        $url = trim((string) TK_HEARTBEAT_URL);
    }
    if ($url === '') {
        $url = (string) tk_get_option('collector_url', '');
    }
    if ($url === '') {
        $url = 'https://nexamonitor.theteamtheteam.com/api/toolkits/heartbeat';
    }
    return $url;
}

function tk_heartbeat_auth_key(): string {
    if (defined('TK_HEARTBEAT_AUTH_KEY') && TK_HEARTBEAT_AUTH_KEY !== '') {
        return (string) TK_HEARTBEAT_AUTH_KEY;
    }
    return (string) tk_get_option('heartbeat_auth_key', '');
}

function tk_heartbeat_auth_keys(): array {
    $keys = array();
    if (defined('TK_HEARTBEAT_AUTH_KEY') && TK_HEARTBEAT_AUTH_KEY !== '') {
        $keys[] = trim((string) TK_HEARTBEAT_AUTH_KEY);
    }
    if (defined('TK_HEARTBEAT_LEGACY_AUTH_KEY') && TK_HEARTBEAT_LEGACY_AUTH_KEY !== '') {
        $keys[] = trim((string) TK_HEARTBEAT_LEGACY_AUTH_KEY);
    }
    $keys[] = trim((string) tk_get_option('heartbeat_auth_key', ''));
    return array_values(array_unique(array_filter($keys, static function ($key) {
        return is_string($key) && $key !== '';
    })));
}

function tk_toolkits_heartbeat_url_for_license_server(string $license_url): string {
    return trim($license_url);
}

function tk_toolkits_default_collector_url(): string {
    return 'https://nexamonitor.theteamtheteam.com/api/toolkits/heartbeat';
}

function tk_heartbeat_collector_url(): string {
    if (defined('TK_HEARTBEAT_URL') && TK_HEARTBEAT_URL !== '') {
        return TK_HEARTBEAT_URL;
    }
    return tk_toolkits_default_collector_url();
}

function tk_toolkits_url_host(string $url): string {
    $parts = wp_parse_url(trim($url));
    return is_array($parts) && !empty($parts['host']) ? strtolower((string) $parts['host']) : '';
}

function tk_toolkits_license_server_is_trusted(string $url): bool {
    $parts = wp_parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || (isset($parts['port']) && $parts['port'] !== 443)) { return false; }
    $trusted = array(tk_toolkits_license_server_url_for_collector(tk_toolkits_default_collector_url()),
        tk_toolkits_license_server_url_for_collector(tk_heartbeat_collector_url()));
    if (defined('TK_LICENSE_SERVER_URL') && TK_LICENSE_SERVER_URL !== '') { $trusted[] = TK_LICENSE_SERVER_URL; }
    foreach (array_unique($trusted) as $endpoint) {
        $origin = wp_parse_url($endpoint);
        if (!is_array($origin) || ($origin['scheme'] ?? '') !== 'https'
            || strtolower($parts['host']) !== strtolower($origin['host'] ?? '')) { continue; }
        $paths = array($origin['path'] ?? '');
        // Exact known legacy routes on this issuer, not arbitrary same-host URLs.
        $path = $origin['path'] ?? '';
        if (preg_match('~/license(?:\.php)?$~D', $path)) {
            $base = preg_replace('~/license(?:\.php)?$~D', '', $path);
            $paths[] = $base . '/license'; $paths[] = $base . '/license.php'; $paths[] = $base . '/heartbeat/license.php';
        }
        if (in_array($parts['path'] ?? '', $paths, true)) { return true; }
    }
    return false;
}

function tk_license_server_url(): string {
    $url = trim((string) tk_get_option('license_server_url', ''));
    if ($url === '' && defined('TK_LICENSE_SERVER_URL') && TK_LICENSE_SERVER_URL !== '') {
        $url = trim((string) TK_LICENSE_SERVER_URL);
    }
    if ($url !== '' && ($url === tk_heartbeat_collector_url() || preg_match('~/heartbeat/license\.php$~i', $url))) {
        $url = '';
    }
    if ($url !== '' && !tk_toolkits_license_server_is_trusted($url)) {
        $url = '';
    }
    if ($url === '') {
        $url = tk_toolkits_license_server_url_for_collector(tk_heartbeat_collector_url());
    }
    if ($url !== '' && $url !== (string) tk_get_option('license_server_url', '')) {
        tk_update_option('license_server_url', $url);
    }
    return $url;
}

function tk_license_server_url_from_collector($collector_url): string {
    return is_string($collector_url) ? tk_toolkits_license_server_url_for_collector($collector_url) : '';
}

function tk_toolkits_license_server_url_for_collector(string $collector_url = ''): string {
    if (defined('TK_LICENSE_SERVER_URL') && TK_LICENSE_SERVER_URL !== '') {
        return trim((string) TK_LICENSE_SERVER_URL);
    }
    if ($collector_url === '') {
        $collector_url = tk_toolkits_collector_url();
    }
    $collector_url = trim($collector_url);
    if ($collector_url === '') {
        return '';
    }
    if (preg_match('~/license\.php$~i', $collector_url)) {
        return $collector_url;
    }
    return preg_replace('~/heartbeat/?$~i', '/license', rtrim($collector_url, '/')) ?: rtrim($collector_url, '/') . '/license';
}

function tk_license_normalize_expires_at($value): string {
    if (!is_scalar($value)) {
        return '';
    }
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    return strtotime($value) !== false ? $value : '';
}

function tk_toolkits_missing_config_message(string $field): string {
    $messages = array(
        'collector_url' => 'Collector URL is missing.',
        'collector_token' => 'Collector token is missing.',
        'license_key' => 'License key is missing.',
        'license_server_url' => 'License server URL is missing.',
    );
    return isset($messages[$field]) ? $messages[$field] : 'Required configuration is missing.';
}

function tk_toolkits_signed_post(string $url, string $body, int $timestamp, array $args = array()) {
    $timeout = isset($args['timeout']) ? (int) $args['timeout'] : 10;
    $headers = array(
        'Content-Type' => 'application/json',
        'X-Auth-Timestamp' => (string) $timestamp,
    );
    $http_user = (string) tk_get_option('heartbeat_http_user', '');
    $http_pass = (string) tk_get_option('heartbeat_http_pass', '');
    if ($http_user === '' && $http_pass === '' && defined('TK_HEARTBEAT_HTTP_USER') && defined('TK_HEARTBEAT_HTTP_PASS')) {
        $http_user = (string) TK_HEARTBEAT_HTTP_USER;
        $http_pass = (string) TK_HEARTBEAT_HTTP_PASS;
    }
    if ($http_user !== '' || $http_pass !== '') {
        $headers['Authorization'] = 'Basic ' . base64_encode($http_user . ':' . $http_pass);
    }

    $request_args = array(
        'timeout' => $timeout,
        'headers' => $headers,
        'body' => $body,
    );
    if (array_key_exists('sslverify', $args)) {
        $request_args['sslverify'] = (bool) $args['sslverify'];
    }
    if (!empty($args['license_request'])) {
        $request_args['sslverify'] = true;
        $request_args['redirection'] = 0;
        $request_args['limit_response_size'] = 65536;
    }

    $urls = array($url);
    if (!empty($args['license_request'])) {
        $collector = tk_heartbeat_collector_url();
        $urls[] = preg_replace('~/heartbeat/?$~i', '/license.php', rtrim($collector, '/')) ?: '';
        $urls[] = rtrim($collector, '/') . '/license.php';
    }
    $urls = array_values(array_unique(array_filter($urls)));

    $last_response = null;
    foreach ($urls as $target_url) {
        if (!empty($args['license_request']) && !tk_toolkits_license_server_is_trusted($target_url)) {
            continue;
        }
        foreach (tk_heartbeat_auth_keys() as $secret) {
            $request_args['headers']['X-Auth-Signature'] = hash_hmac('sha256', $body, $secret);
            $response = !empty($args['license_request']) ? wp_safe_remote_post($target_url, $request_args) : wp_remote_post($target_url, $request_args);
            $last_response = $response;
            if (is_wp_error($response)) {
                return $response;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $raw = (string) wp_remote_retrieve_body($response);
            if ($code === 401 && stripos($raw, 'Security signature invalid') !== false) {
                continue;
            }
            if (!empty($args['license_request']) && $code === 404) {
                break;
            }
            if (!empty($args['license_request']) && $code >= 500) {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    continue 2;
                }
            }
            return $response;
        }
    }

    return $last_response === null ? new WP_Error('tk_license_endpoint', 'No approved license endpoint or collector token is configured.') : $last_response;
}

function tk_toolkits_license_validation_message(string $detail = ''): string {
    $detail = trim($detail);
    if ($detail === '') {
        return 'License validation failed.';
    }
    if (strpos($detail, 'License validation failed:') === 0) {
        return $detail;
    }
    return 'License validation failed: ' . $detail;
}

function tk_toolkits_license_http_error_message(int $code, string $raw): string {
    $decoded = json_decode($raw, true);
    if (is_array($decoded) && isset($decoded['message'])) {
        return tk_toolkits_license_validation_message((string) $decoded['message']);
    }

    if ($code >= 500) {
        return tk_toolkits_license_validation_message('license server returned HTTP ' . $code . '. Check the NexaMonitor license API logs.');
    }

    $detail = trim(strip_tags($raw));
    $detail = preg_replace('/\s+/', ' ', $detail) ?: '';
    if ($detail !== '') {
        return tk_toolkits_license_validation_message('HTTP ' . $code . ': ' . substr($detail, 0, 160));
    }

    return tk_toolkits_license_validation_message('HTTP ' . $code . '.');
}

function tk_toolkits_license_reachability_message(int $code, string $detail = ''): string {
    $detail = trim($detail);
    $message = 'License server is reachable';
    if ($code > 0) {
        $message .= ' (HTTP ' . $code . ')';
    }
    if ($detail !== '') {
        $message .= ': ' . $detail;
    } else {
        $message .= '.';
    }
    return $message;
}

function tk_license_maybe_notify($old_status, $new_status, $message) {
    if ($old_status === $new_status && $new_status === 'valid') {
        return; // No change in valid status
    }

    $email = tk_get_option('license_notify_email', '');
    if ($email === '') {
        $email = tk_get_option('monitoring_alert_email', get_option('admin_email'));
    }

    if (!is_email($email)) {
        return;
    }

    $site_name = get_bloginfo('name');
    $site_url = home_url();
    $subject = '';
    $body = '';

    if ($new_status === 'valid' && $old_status !== 'valid') {
        $subject = "[Tool Kits] License Activated: {$site_name}";
        $body = "A new license has been successfully activated for {$site_name} ({$site_url}).\n\nStatus: Valid\nMessage: {$message}";
    } elseif ($new_status !== 'valid' && $old_status === 'valid') {
        $subject = "[Tool Kits] ALERT: License Error on {$site_name}";
        $body = "The license for {$site_name} ({$site_url}) is no longer valid or encountered an error.\n\nNew Status: " . strtoupper($new_status) . "\nError Message: {$message}\n\nPlease check your license settings in the WordPress admin.";
    } elseif ($new_status === 'error' || $new_status === 'invalid') {
        // We could notify on error if it was never valid, but usually activation handler handles that UI-wise.
        return;
    }

    if ($subject !== '') {
        wp_mail($email, $subject, $body);
    }
}

function tk_license_update_state(array $state): void {
    $old_status = (string) tk_get_option('license_status', 'inactive');

    $allowed = array(
        'license_status',
        'license_message',
        'license_last_checked',
        'license_env',
        'license_type',
        'license_site_url',
        'license_expires_at',
        'license_receipt',
    );
    $new_status = isset($state['license_status']) ? (string) $state['license_status'] : $old_status;
    $message = isset($state['license_message']) ? (string) $state['license_message'] : '';

    // Local HMACs are no longer an activation credential.
    $values = array_intersect_key($state, array_flip($allowed));
    $values['license_signature'] = '';
    if ($new_status !== 'valid') { $values['license_receipt'] = array(); }
    tk_update_options($values);
    if ($new_status === 'valid' && !tk_license_has_valid_signature()) {
        tk_update_options(array('license_status' => 'invalid', 'license_receipt' => array()));
    }
    $final_status = (string) tk_get_option('license_status', 'inactive');
    if ($old_status !== $final_status) {
        tk_license_maybe_notify($old_status, $final_status, $message);
    }
}

function tk_heartbeat_record_diagnostic_result(array $result, string $endpoint = ''): void {
    $now = time();
    $message = isset($result['message']) ? trim((string) $result['message']) : '';
    $values = array('heartbeat_last_checked' => $now, 'heartbeat_last_endpoint' => $endpoint);
    if (!empty($result['ok'])) {
        tk_update_options($values + array('heartbeat_last_success' => $now, 'heartbeat_last_error_message' => ''));
        return;
    }
    tk_update_options($values + array('heartbeat_last_failure' => $now, 'heartbeat_last_error_message' => $message));
}

function tk_license_record_diagnostic_result(array $result, string $endpoint = ''): void {
    $now = time();
    $message = isset($result['message']) ? trim((string) $result['message']) : '';
    $values = array('license_last_checked' => $now, 'license_last_endpoint' => $endpoint);
    if (isset($result['status']) && (string) $result['status'] === 'valid') {
        tk_update_options($values + array('license_last_success' => $now, 'license_last_error_message' => ''));
        return;
    }
    tk_update_options($values + array('license_last_failure' => $now, 'license_last_error_message' => $message));
}

function tk_license_reset(): void {
    tk_update_option('license_key', '');
    tk_update_option('license_server_url', '');
    tk_update_option('license_signature', '');
    tk_update_option('heartbeat_auth_key', '');
    tk_update_option('heartbeat_last_checked', 0);
    tk_update_option('heartbeat_last_success', 0);
    tk_update_option('heartbeat_last_failure', 0);
    tk_update_option('heartbeat_last_error_message', '');
    tk_update_option('heartbeat_last_endpoint', '');
    tk_license_update_state(array(
        'license_status' => 'inactive',
        'license_message' => '',
        'license_last_checked' => 0,
        'license_last_success' => 0,
        'license_last_failure' => 0,
        'license_last_error_message' => '',
        'license_last_endpoint' => '',
        'license_env' => '',
        'license_type' => '',
        'license_site_url' => '',
        'license_expires_at' => '',
    ));
}

function tk_license_normalize_site_url(string $site_url): string {
    return TK_License_Receipt::normalize_site($site_url);
}

function tk_license_site_matches_current(): bool {
    $site = tk_license_normalize_site_url((string) tk_get_option('license_site_url', ''));
    return $site !== '' && $site === tk_license_normalize_site_url(home_url('/'));
}

function tk_license_site_mismatch_message(): string {
    return 'License validation failed: this license is assigned to a different website URL.';
}

function tk_license_integrity_violation_message(): string {
    return 'License integrity violation detected. Please re-activate.';
}

function tk_license_test_connection(): array {
    $collector_url = tk_heartbeat_collector_url();
    $url = trim(tk_license_server_url());
    $secret = tk_heartbeat_auth_key();

    if ($collector_url === '') {
        $result = array('status' => 'missing_server', 'message' => tk_toolkits_missing_config_message('collector_url'));
        tk_license_record_diagnostic_result($result, '');
        return $result;
    }
    if ($url === '') {
        $result = array('status' => 'missing_server', 'message' => tk_toolkits_missing_config_message('license_server_url'));
        tk_license_record_diagnostic_result($result, '');
        return $result;
    }
    if ($secret === '') {
        $result = array('status' => 'missing_secret', 'message' => tk_toolkits_missing_config_message('collector_token'));
        tk_license_record_diagnostic_result($result, $url);
        return $result;
    }

    $payload = array(
        'action' => 'reachability_check',
        'site_url' => home_url('/'),
        'site_id' => tk_toolkits_install_id(),
        'env' => tk_license_env(),
        'timestamp' => time(),
    );
    $body = wp_json_encode($payload);
    if ($body === false) {
        $result = array('status' => 'error', 'message' => tk_toolkits_license_validation_message('failed to encode request payload.'));
        tk_license_record_diagnostic_result($result, $url);
        return $result;
    }

    $response = tk_toolkits_signed_post($url, $body, (int) $payload['timestamp'], array(
        'timeout' => 10,
        'license_request' => true,
    ));
    if (is_wp_error($response)) {
        $result = array('status' => 'error', 'message' => tk_toolkits_license_validation_message($response->get_error_message()));
        tk_license_record_diagnostic_result($result, $url);
        return $result;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $result = array(
        'status' => 'valid',
        'message' => tk_toolkits_license_reachability_message($code),
    );
    if ($code <= 0) {
        $result = array(
            'status' => 'error',
            'message' => tk_toolkits_license_validation_message('received an invalid HTTP response.'),
        );
        tk_license_record_diagnostic_result($result, $url);
        return $result;
    }
    if ($code >= 200 && $code < 300) {
        tk_license_record_diagnostic_result($result, $url);
        return $result;
    }

    $detail = trim((string) wp_remote_retrieve_response_message($response));
    $raw = trim((string) wp_remote_retrieve_body($response));
    if ($raw !== '' && $code < 500) {
        $body_detail = preg_replace('/\s+/', ' ', trim(strip_tags($raw))) ?: '';
        if ($body_detail !== '') {
            $detail = $detail !== '' ? $detail . ' ' . substr($body_detail, 0, 160) : substr($body_detail, 0, 160);
        }
    }
    $result = array(
        'status' => 'error',
        'message' => $code >= 500
            ? tk_toolkits_license_validation_message('license server returned HTTP ' . $code . '. Check the NexaMonitor license API logs.')
            : tk_toolkits_license_validation_message(tk_toolkits_license_reachability_message($code, $detail)),
    );
    tk_license_record_diagnostic_result($result, $url);
    return $result;
}
