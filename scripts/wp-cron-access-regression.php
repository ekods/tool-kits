<?php
// Public wp-cron.php gate: local requests, the spawn_cron() loopback token and the external cron key pass; nothing else does.
define('ABSPATH', '/tmp/');
set_error_handler(static function ($level, $message) { throw new RuntimeException('PHP warning: ' . $message); });
require dirname(__DIR__) . '/includes/security-hardening.php';
$settings = array(); $transients = array();
class WpCronDenied extends RuntimeException {}
function tk_get_option($key, $default = null) { return $GLOBALS['settings'][$key] ?? $default; }
function tk_update_option($key, $value) { $GLOBALS['settings'][$key] = $value; return true; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_unslash($value) { return $value; }
function wp_generate_password($length, $special, $extra) { return substr(str_repeat(bin2hex(random_bytes(16)), 2), 0, $length); }
function site_url($path) { return 'https://site.example/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function status_header($code) {}
function nocache_headers() {}
function wp_die($message) { throw new WpCronDenied($message); }
function verify_cron($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function cron_allowed(string $uri, array $get = array(), string $remote = '203.0.113.50'): bool {
    $_SERVER['REQUEST_URI'] = $uri; $_SERVER['REMOTE_ADDR'] = $remote; $_SERVER['SERVER_ADDR'] = '198.51.100.10'; $_GET = $get;
    try { tk_hardening_block_public_wp_cron_request(); return true; } catch (WpCronDenied $denied) { return false; }
}

verify_cron(cron_allowed('/about/'), 'Non-cron requests must pass through');
verify_cron(!cron_allowed('/wp-cron.php'), 'Public wp-cron.php without credentials must be blocked');
verify_cron(!cron_allowed('/wp-cron.php', array('tk_cron_key' => '')), 'An empty key must not match an unset key');
verify_cron(cron_allowed('/wp-cron.php', array(), '127.0.0.1'), 'Local requests must pass');

$key = tk_monitoring_external_cron_key();
verify_cron(strlen($key) === 32 && $settings['monitoring_external_cron_key'] === $key && tk_monitoring_external_cron_key() === $key, 'The cron key must be generated once and persisted');
verify_cron(cron_allowed('/wp-cron.php', array('doing_wp_cron' => '', 'tk_cron_key' => $key)), 'The external cron key must be accepted from a public IP');
verify_cron(!cron_allowed('/wp-cron.php', array('tk_cron_key' => $key . 'x')), 'A wrong key must be blocked');
verify_cron(!cron_allowed('/wp-cron.php', array('tk_cron_key' => array($key))), 'A non-string key must be blocked');
$url = tk_monitoring_external_cron_url();
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
verify_cron(strpos($url, 'https://site.example/wp-cron.php?') === 0 && array_key_exists('doing_wp_cron', $query) && $query['tk_cron_key'] === $key, 'The external cron URL must target wp-cron.php with the key');
verify_cron(cron_allowed(parse_url($url, PHP_URL_PATH), $query), 'The generated URL itself must pass the gate');

$transients['doing_cron'] = '1700000000.123';
verify_cron(cron_allowed('/wp-cron.php', array('doing_wp_cron' => '1700000000.123')), 'The spawn_cron() loopback token must still pass behind proxies');
verify_cron(!cron_allowed('/wp-cron.php', array('doing_wp_cron' => '1700000000.999')), 'A stale loopback token must be blocked');

$settings['monitoring_external_cron_key'] = 'rotated-key-rotated-key-rotated-k';
verify_cron(!cron_allowed('/wp-cron.php', array('tk_cron_key' => $key)), 'A regenerated key must invalidate the old URL');
echo "PASS: wp-cron.php gate allows local, loopback-token and external-key requests only; key generated once, rotated keys invalidate old URLs\n";
