<?php
// Synthetic issuer for isolated CLI tests. Never included by the plugin/runtime.
if (PHP_SAPI !== 'cli') { exit; }
require_once dirname(__DIR__) . '/includes/license-receipt.php';
$tk_test_key = @openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072));
if (!$tk_test_key) { throw new RuntimeException('Cannot generate isolated signing key'); }
$tk_test_public = openssl_pkey_get_details($tk_test_key)['key'];
$tk_test_key_id = substr(hash('sha256', $tk_test_public), 0, 16);
$tk_test_pins = tempnam(sys_get_temp_dir(), 'tk-test-pins-');
file_put_contents($tk_test_pins, '<?php return ' . var_export(array($tk_test_key_id => $tk_test_public), true) . ';');
define('TK_LICENSE_PUBLIC_KEYS_FILE', $tk_test_pins);
register_shutdown_function(function() use ($tk_test_pins) { if (is_file($tk_test_pins)) { unlink($tk_test_pins); } });
function tk_test_license_receipt(array $options, array $overrides = array()): array {
    $expiry = (string) ($options['license_expires_at'] ?? '');
    $claims = array_merge(array('version' => 1, 'product' => 'tool-kits', 'status' => 'valid',
        'license_sha256' => hash('sha256', $options['license_key'] ?? 'fixture'),
        'site_url' => TK_License_Receipt::normalize_site($options['license_site_url'] ?? home_url('/')),
        'site_id' => $options['toolkits_install_id'] ?? 'fixture-install', 'license_type' => $options['license_type'] ?? '',
        'license_expires_at' => $expiry === '' ? 0 : strtotime($expiry), 'issued_at' => time(),
        'expires_at' => $expiry === '' ? time() + 86400 : min(time() + 86400, strtotime($expiry)),
        'request_nonce' => str_repeat('a', 64)), $overrides);
    $payload = base64_encode(json_encode($claims));
    if (!openssl_sign('tool-kits-license-v1.' . $payload, $signature, $GLOBALS['tk_test_key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Cannot sign isolated fixture');
    }
    return array('key_id' => $GLOBALS['tk_test_key_id'], 'payload' => $payload, 'signature' => base64_encode($signature));
}
