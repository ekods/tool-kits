<?php
/** Standalone verifier. No WordPress APIs, remote keys, filters or database trust anchors. */
final class TK_License_Receipt {
    public static function normalize_site(string $url): string {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host']) || !isset($parts['scheme'])
            || !in_array(strtolower($parts['scheme']), array('https', 'http'), true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) { return ''; }
        return strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . (isset($parts['path']) && trim($parts['path'], '/') !== '' ? '/' . trim($parts['path'], '/') : '');
    }

    /** Return verified claims, or false. Time and binding checks are never memoized. */
    public static function verify($receipt, array $keys, array $context, int $now, string $nonce = '') {
        if (!is_array($receipt) || !function_exists('openssl_verify')) { return false; }
        foreach (array('key_id', 'payload', 'signature') as $name) {
            if (!isset($receipt[$name]) || !is_string($receipt[$name])) { return false; }
        }
        if (!isset($keys[$receipt['key_id']]) || !is_string($keys[$receipt['key_id']])
            || strlen($receipt['payload']) > 16384 || strlen($receipt['signature']) > 1024) { return false; }
        $payload = base64_decode($receipt['payload'], true);
        $signature = base64_decode($receipt['signature'], true);
        if ($payload === false || $signature === false || base64_encode($payload) !== $receipt['payload']
            || base64_encode($signature) !== $receipt['signature']) { return false; }
        static $verified = array();
        $digest = hash('sha256', $receipt['payload'] . '|' . $receipt['signature'] . '|' . $keys[$receipt['key_id']]);
        if (!isset($verified[$digest])) {
            $key = @openssl_pkey_get_public($keys[$receipt['key_id']]);
            $details = $key ? openssl_pkey_get_details($key) : false;
            if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 3072
                || @openssl_verify('tool-kits-license-v1.' . $receipt['payload'], $signature, $key, OPENSSL_ALGO_SHA256) !== 1) { return false; }
            $verified = array($digest => true);
        }
        $claims = json_decode($payload, true, 16);
        if (!is_array($claims)) { return false; }
        foreach (array('issued_at', 'expires_at', 'license_expires_at') as $name) {
            if (!isset($claims[$name]) || !is_int($claims[$name])) { return false; }
        }
        foreach (array('product', 'status', 'license_sha256', 'site_url', 'site_id', 'license_type', 'request_nonce') as $name) {
            if (!isset($claims[$name]) || !is_string($claims[$name])) { return false; }
        }
        if (($claims['version'] ?? null) !== 1 || $claims['product'] !== 'tool-kits' || $claims['status'] !== 'valid'
            || $claims['issued_at'] <= 0 || $claims['issued_at'] > $now + 300 || $claims['expires_at'] <= $now
            || $claims['expires_at'] <= $claims['issued_at'] || $claims['expires_at'] - $claims['issued_at'] > 86400
            || $claims['license_expires_at'] < 0 || ($claims['license_expires_at'] > 0
                && ($claims['license_expires_at'] <= $now || $claims['expires_at'] > $claims['license_expires_at']))
            || !preg_match('/^[a-f0-9]{64}$/D', $claims['request_nonce'])
            || ($nonce !== '' && !hash_equals($nonce, $claims['request_nonce']))) { return false; }
        $site = self::normalize_site((string) ($context['site_url'] ?? ''));
        if ($site === '' || $site !== $claims['site_url'] || empty($context['site_id']) || empty($context['license_key'])
            || !hash_equals(hash('sha256', $context['license_key']), $claims['license_sha256'])
            || !hash_equals((string) $context['site_id'], $claims['site_id'])) { return false; }
        return $claims;
    }
}
