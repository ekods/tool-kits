<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
error_reporting(E_ALL);
set_error_handler(function ($level, $message) { throw new RuntimeException($message); });
define('ABSPATH', dirname(__DIR__, 4) . '/');
define('AUTH_KEY', 'request-cache-test-key');
require ABSPATH . 'wp-includes/plugin.php';
$blog = 1; $stored = array(); $reads = 0; $checks = 0;
function get_current_blog_id() { return $GLOBALS['blog']; }
function get_option($key, $default = false) {
    $GLOBALS['reads']++;
    return isset($GLOBALS['stored'][$GLOBALS['blog']][$key]) ? unserialize($GLOBALS['stored'][$GLOBALS['blog']][$key]) : $default;
}
function update_option($key, $value, $autoload = null) {
    $exists = isset($GLOBALS['stored'][$GLOBALS['blog']][$key]);
    $old = $exists ? unserialize($GLOBALS['stored'][$GLOBALS['blog']][$key]) : null;
    $GLOBALS['stored'][$GLOBALS['blog']][$key] = serialize($value);
    do_action($exists ? 'updated_option' : 'added_option', $key, $old, $value);
}
function delete_option($key) { unset($GLOBALS['stored'][$GLOBALS['blog']][$key]); do_action('deleted_option', $key); }
function wp_json_encode($value) { return json_encode($value); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function home_url($path = '/') { return 'https://example.test' . $path; }
function untrailingslashit($value) { return rtrim($value, '/'); }
function verify($condition, $message) { if (!$condition) { throw new RuntimeException($message); } $GLOBALS['checks']++; echo 'PASS ' . $message . PHP_EOL; }
require dirname(__DIR__) . '/includes/helpers.php';
$options = array('license_status' => 'valid', 'license_key' => 'fixture', 'license_site_url' => 'https://example.test', 'toolkits_install_id' => 'fixture-install', 'report' => str_repeat('x', 262144));
update_option('tk_options', $options);
for ($i = 0; $i < 1000; $i++) { tk_get_option('license_status'); }
verify($reads === 1, '1000 option reads deserialize the report-bearing option once');
$options['license_signature'] = tk_license_expected_signature();
update_option('tk_options', $options);
verify(tk_license_features_enabled(), 'valid signed license remains enabled');
$copy = tk_get_options(); $copy['license_status'] = 'inactive';
verify(tk_get_option('license_status') === 'valid', 'callers cannot mutate the cached option through a returned copy');
$options['license_signature'] = 'tampered'; update_option('tk_options', $options);
verify(!tk_license_features_enabled(), 'signature tampering is rejected immediately after an update');
$options['license_signature'] = tk_license_expected_signature(); update_option('tk_options', $options);
verify(tk_license_features_enabled(), 'restored signature is revalidated after an update');
$options['license_site_url'] = 'https://other.test'; update_option('tk_options', $options);
$options['license_signature'] = tk_license_expected_signature(); update_option('tk_options', $options);
verify(!tk_license_features_enabled(), 'a valid signature for another site does not enable features');
$blog = 2; update_option('tk_options', array('license_status' => 'inactive'));
verify(tk_get_option('license_status') === 'inactive', 'multisite has a separate parsed option cache');
$blog = 1; $before = $reads; tk_get_options();
verify($reads === $before, 'switching back reuses only that site cache');
update_option('other_option', true); tk_get_options();
verify($reads === $before, 'unrelated option changes do not invalidate Tool Kits options');
delete_option('tk_options');
verify(tk_get_options() === array() && !tk_license_features_enabled(), 'deleting options clears the cached state');
update_option('tk_options', array('new' => true));
verify(tk_get_option('new') === true, 'adding options clears a cached missing value');
update_option('tk_options', 'malformed');
verify(tk_get_options() === array() && tk_get_option('missing', 'fallback') === 'fallback', 'malformed options and missing keys retain safe defaults');
echo $checks . ' request option checks passed.' . PHP_EOL;
