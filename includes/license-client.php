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
    // An invalid/legacy receipt always triggers revalidation; changing the local
    // timestamp or status cannot extend the issuer's signed validity window.
    if (!$force && ($status !== 'valid' || $verified) && $checked > 0 && $checked <= $now
        && $now - $checked < ($verified && tk_get_option('license_last_error_message', '') !== '' ? 300 : 21600)) {
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
            $response = tk_toolkits_signed_post($url, $body, $now, array('timeout' => 15, 'license_request' => true));
            $network_error = is_wp_error($response);
            $code = $network_error ? 0 : (int) wp_remote_retrieve_response_code($response);
            // A temporary outage may use an already verified receipt, but only
            // until its original signed deadline. Never renew it locally.
            if (($network_error || $code >= 500 || $code === 429) && $verified && tk_license_features_enabled()) {
                $result = array('status' => 'valid', 'message' => 'License server temporarily unavailable. Using the existing signed license until its original expiry.');
                tk_update_option('license_last_checked', $now);
                tk_update_option('license_last_failure', $now);
                tk_update_option('license_last_error_message', $result['message']);
                return $result;
            }
            if ($network_error) { $failure = $response->get_error_message(); }
            else {
                $raw = (string) wp_remote_retrieve_body($response);
                $data = strlen($raw) <= 65536 ? json_decode($raw, true, 16) : null;
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
