<?php
if (!defined('ABSPATH')) { exit; }

function tk_heartbeat_recheck_register() {
    register_rest_route('tool-kits/v1', '/heartbeat', array(
        'methods' => 'POST',
        'permission_callback' => 'tk_heartbeat_recheck_authorize',
        'callback' => 'tk_heartbeat_recheck_response',
    ));
}

function tk_heartbeat_recheck_secret($request): string {
    $signature = (string) $request->get_header('x-auth-signature');
    if (!preg_match('/^[a-f0-9]{64}$/D', $signature)) { return ''; }
    foreach (tk_heartbeat_auth_keys() as $secret) {
        if (hash_equals(hash_hmac('sha256', $request->get_body(), $secret), $signature)) {
            return $secret;
        }
    }
    return '';
}

function tk_heartbeat_recheck_authorize($request) {
    if (strlen($request->get_body()) > 4096 || tk_heartbeat_recheck_secret($request) === '') {
        return new WP_Error('tk_recheck_signature_invalid', 'Invalid collector signature.', array('status' => 403));
    }
    $data = json_decode($request->get_body(), true);
    if (!is_array($data) || ($data['action'] ?? '') !== 'collect_heartbeat'
        || !is_int($data['timestamp'] ?? null) || abs(time() - $data['timestamp']) > 300
        || !is_string($data['nonce'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $data['nonce'])
        || !is_string($data['site_url'] ?? null)
        || tk_license_normalize_site_url($data['site_url']) !== tk_license_normalize_site_url(home_url('/'))
        || !is_string($data['site_id'] ?? null)
        || ($data['site_id'] !== '' && $data['site_id'] !== tk_toolkits_install_id())) {
        return new WP_Error('tk_recheck_invalid_request', 'Invalid or expired heartbeat request for this installation.', array('status' => 403));
    }
    return true;
}

function tk_heartbeat_recheck_response($request) {
    // Revalidate before returning private telemetry, including when called programmatically.
    $authorized = tk_heartbeat_recheck_authorize($request);
    if (is_wp_error($authorized)) { return $authorized; }

    $lock = 'tk_heartbeat_recheck_lock';
    $previous = (int) get_option($lock, 0);
    if ($previous > 0 && $previous < time() - 30) { delete_option($lock); }
    if (!add_option($lock, time(), '', false)) {
        return new WP_Error('tk_recheck_busy', 'Another heartbeat request is in progress.', array('status' => 429));
    }

    try {
        $data = json_decode($request->get_body(), true);
        $nonceKey = 'tk_hb_recheck_' . hash('sha256', $data['nonce']);
        if (get_transient($nonceKey)) {
            return new WP_Error('tk_recheck_replay', 'This heartbeat request has already been used.', array('status' => 409));
        }
        if (get_transient('tk_hb_recheck_cooldown')) {
            return new WP_Error('tk_recheck_busy', 'Please wait a few seconds before requesting another heartbeat.', array('status' => 429));
        }
        set_transient($nonceKey, 1, 600);
        set_transient('tk_hb_recheck_cooldown', 1, 10);

        $payload = tk_heartbeat_payload();
        $payload['recheck_nonce'] = $data['nonce'];
        $body = wp_json_encode($payload);
        if (!is_string($body)) {
            return new WP_Error('tk_recheck_encoding_failed', 'Unable to encode heartbeat data.', array('status' => 500));
        }
        $response = new WP_REST_Response(array(
            'ok' => true,
            'heartbeat' => $body,
            'signature' => hash_hmac('sha256', $body, tk_heartbeat_recheck_secret($request)),
        ), 200);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    } finally {
        delete_option($lock);
    }
}
