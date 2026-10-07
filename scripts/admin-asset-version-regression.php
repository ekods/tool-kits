<?php
define('ABSPATH', '/tmp/');
require dirname(__DIR__) . '/includes/security-hardening.php';
function is_admin() { return $GLOBALS['admin_request']; }
function get_bloginfo($key) { return '6.6.2'; }
function remove_query_arg($key, $url) {
    $parts = explode('?', $url, 2);
    parse_str($parts[1] ?? '', $query); unset($query[$key]);
    return $parts[0] . ($query ? '?' . http_build_query($query) : '');
}
function verify_asset_version($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function version_filters($url) { return tk_remove_wp_version_strings(tk_remove_wp_ver_css_js($url)); }
$base = 'https://site.example/wp-content/plugins/tool-kits/assets/geo-schema-editor.js';
$old = $base . '?ver=100'; $new = $base . '?ver=200';
$GLOBALS['admin_request'] = true;
verify_asset_version(version_filters($old) === $old && version_filters($new) === $new, 'Plugin versions stripped in admin');
verify_asset_version(version_filters($old) !== version_filters($new), 'Updated script uses the stale browser cache URL');
foreach (array($base . '?ver=6.6.2', 'https://site.example/wp-includes/css/admin.css?ver=6.6.2', $base . '?lang=en&ver=200') as $url) {
    verify_asset_version(version_filters($url) === $url, 'Admin CSS/JS cache key modified');
}
$GLOBALS['admin_request'] = false;
verify_asset_version(version_filters($new) === $base, 'Public version stripping changed');
verify_asset_version(tk_remove_wp_ver_css_js($base . '?ver=6.6.2') === $base, 'Public WordPress fingerprint retained');
verify_asset_version(tk_remove_wp_ver_css_js($new) === $new, 'WordPress-only filter stripped a file timestamp');
verify_asset_version(version_filters($base . '?lang=en&ver=200') === $base . '?lang=en', 'Other public query parameters removed');
echo "PASS: admin script/CSS versions survive both hardening filters, updates use distinct cache URLs, and public stripping behavior is preserved\n";
