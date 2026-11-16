<?php
define('ABSPATH', __DIR__ . '/');
$wp_version = 'fixture'; $saved = array(); $request_args = array();
function tk_require_admin_post($action) {}
function get_locale() { return 'en_US'; }
function wp_remote_get($url, $args) {
    $GLOBALS['request_args'] = $args;
    return array('body' => json_encode(array('checksums' => array(
        'wp-includes/missing-core-file.php' => 'synthetic',
        'wp-content/themes/missing-theme/style.css' => 'synthetic',
        'readme.html' => 'synthetic',
        '.' => 'synthetic',
        'security-fim-regression.php' => md5_file(__FILE__),
    ))));
}
function is_wp_error($value) { return false; }
function wp_remote_retrieve_response_code($value) { return 200; }
function wp_remote_retrieve_body($value) { return $value['body']; }
function tk_get_option($key, $default = null) { return $key === 'license_ssl_verify' ? 0 : ($GLOBALS['saved'][$key] ?? $default); }
function tk_update_option($key, $value) { $GLOBALS['saved'][$key] = $value; }
function admin_url($path) { return '/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_safe_redirect($url) { throw new LogicException($url); }
require dirname(__DIR__) . '/includes/security-fim.php';
try { tk_fim_scan_handler(); } catch (LogicException $response) {}
$expected = array('wp-includes/missing-core-file.php (missing)', '. (unreadable)');
if (($saved['tk_fim_last_scan_result'] ?? null) !== $expected) { throw new RuntimeException('Missing or unreadable core files must be reported; optional distributions may be absent'); }
if (($request_args['sslverify'] ?? null) !== true) { throw new RuntimeException('Checksum TLS must not depend on a license setting'); }
echo "PASS: core integrity reports missing/unreadable files, permits absent optional bundles, and verifies TLS\n";
