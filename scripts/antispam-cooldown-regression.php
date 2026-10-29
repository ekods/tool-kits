<?php
// Isolated anti-spam checks: no database, mail, network, or real visitor state.
error_reporting(E_ALL);
set_error_handler(function ($level, $message) {
    if (error_reporting() & $level) { throw new RuntimeException($message); }
    return false;
});
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('TK_URL', 'https://example.test/wp-content/plugins/tool-kits/');
$options = array('antispam_cf7_enabled' => 1);
$cache = $timeouts = $writes = $assets = array();
$ip = '192.0.2.1';
$admin = $nonce = true;
$checks = 0;
function tk_get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function tk_update_option($key, $value) { $GLOBALS['options'][$key] = $value; $GLOBALS['writes'][] = $key; }
function get_option($key, $default = null) { return $GLOBALS['timeouts'][$key] ?? $default; }
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = $value; $GLOBALS['timeouts']['_transient_timeout_' . $key] = time() + $ttl; }
function delete_transient($key) { unset($GLOBALS['cache'][$key], $GLOBALS['timeouts']['_transient_timeout_' . $key]); }
function tk_get_ip() { return $GLOBALS['ip']; }
function tk_user_agent() { return 'Cooldown regression'; }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function wp_json_encode($value) { return json_encode($value); }
function wp_unslash($value) { return $value; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function wp_strip_all_tags($value) { return strip_tags($value); }
function __($value, $domain = '') { return $value; }
function current_time($type, $gmt = false) { return time(); }
function wpcf7() { return true; }
function tk_is_admin_user() { return $GLOBALS['admin']; }
function tk_check_nonce($action) { if (!$GLOBALS['nonce']) { wp_die('Invalid nonce'); } }
function tk_post($key, $default = '') { return $_POST[$key] ?? $default; }
function wp_die($message) { throw new DomainException($message); }
function wp_redirect($url) { throw new LogicException('redirect'); }
function admin_url($path) { return '/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_script_is($handle, $state) { return $GLOBALS['assets']['cf7'] ?? false; }
function tk_asset_version($path) { return 'fixture'; }
function wp_enqueue_script($handle, $src, $dependencies, $version, $footer) { $GLOBALS['assets'][$handle] = compact('src', 'dependencies', 'footer'); }
function wp_localize_script($handle, $name, $data) { $GLOBALS['assets']['labels'] = $data; }
class WPCF7_FormTag {}
class CooldownValidation {
    public $valid = true;
    public $errors = array();
    public function is_valid() { return $this->valid; }
    public function invalidate($field, $message) { $this->valid = false; $this->errors[$field] = $message; }
}
require dirname(__DIR__) . '/includes/antispam-contact.php';
function check_cooldown($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    $GLOBALS['checks']++;
}
function seed_cooldown($key, $age) { set_transient($key, array('started_at' => time() - $age), 3600); }

$values = array('your-name' => 'Maya Putri', 'your-email' => 'maya@example.test', 'your-message' => 'We need a company website with a service catalog.');
$email_key = tk_antispam_email_cooldown_key('maya@example.test');
$duplicate_key = tk_antispam_duplicate_key(tk_antispam_submission_signature($values));
seed_cooldown($email_key, 70);
seed_cooldown($duplicate_key, 70);
seed_cooldown(tk_antispam_ip_cooldown_key(), 10);
$status = tk_antispam_cooldown_status($values);
check_cooldown($status['reason'] === 'email_cooldown:maya@example.test', 'Longest active rule must win');
check_cooldown(abs($status['retry_after'] - 830) <= 1, 'Remaining time must subtract elapsed seconds');
$again = tk_antispam_cooldown_status($values);
check_cooldown($again['retry_after'] <= $status['retry_after'], 'Checking must not restart cooldown');
$options['antispam_email_cooldown_minutes'] = 1;
check_cooldown(tk_antispam_cooldown_status($values)['reason'] === 'duplicate_submission', 'Shortened email setting must apply to active records');
$options['antispam_duplicate_window_minutes'] = 0;
check_cooldown(tk_antispam_cooldown_status($values)['reason'] === 'ip_cooldown_active', 'Zero must disable the existing duplicate record');
$options['antispam_ip_cooldown_seconds'] = 0;
check_cooldown(tk_antispam_cooldown_status($values) === array(), 'Expired/disabled waits must allow retry');
$options['antispam_email_cooldown_minutes'] = 15;
$options['antispam_cooldown_enabled'] = 0;
$before = $cache;
tk_antispam_register_submission($values);
check_cooldown(tk_antispam_cooldown_status($values) === array() && $cache === $before, 'Master off must ignore existing waits and avoid writing new ones');
check_cooldown(tk_antispam_replay_reason($values) === '', 'Master off must allow replay');
$options['antispam_cooldown_enabled'] = 1;

// Existing scalar flags upgrade once while retaining their remaining database TTL.
$cache[$email_key] = 1;
$timeouts['_transient_timeout_' . $email_key] = time() + 42;
check_cooldown(abs(tk_antispam_cooldown_remaining($email_key, 900) - 42) <= 1, 'Legacy expiry was not retained');
check_cooldown(is_array($cache[$email_key]), 'Legacy flag was not upgraded');
$legacy_started = $cache[$email_key]['started_at'];
tk_antispam_cooldown_remaining($email_key, 900);
check_cooldown($cache[$email_key]['started_at'] === $legacy_started, 'Legacy countdown restarted');
$cache[$email_key] = 1;
$timeouts['_transient_timeout_' . $email_key] = time() + 800;
check_cooldown(tk_antispam_cooldown_remaining($email_key, 60) <= 60, 'A shorter duration must cap legacy flags');
$cache[$email_key] = 1;
unset($timeouts['_transient_timeout_' . $email_key]);
check_cooldown(tk_antispam_cooldown_remaining($email_key, 60) === 60, 'Object-cache legacy fallback must have a bounded timer');
seed_cooldown($email_key, 901);
check_cooldown(tk_antispam_cooldown_status($values) === array(), 'Expired timestamps must stop blocking');

// CF7 error JSON includes numeric wait data and success starts a timer without changing mail_sent.
seed_cooldown($email_key, 870);
$result = new CooldownValidation();
tk_antispam_reject($result, 'Wait', 'email_cooldown:maya@example.test', $values, array(array('name' => 'your-message')));
$json = tk_antispam_cf7_ajax_json_echo(array('status' => 'validation_failed'), array());
check_cooldown($json['tool_kits_cooldown']['reason'] === 'email_cooldown', 'Public timer metadata must omit the email address');
check_cooldown(abs($json['tool_kits_cooldown']['retry_after'] - 30) <= 1, 'CF7 wait must reflect actual remaining seconds');
check_cooldown(strpos($json['message'], '00:') !== false && isset($result->errors['your-message']), 'Fallback message/field error must show the wait');
unset($GLOBALS['tk_antispam_current_rejection']);
delete_transient(tk_antispam_current_rejection_key());
$_POST = $values;
$json = tk_antispam_cf7_ajax_json_echo(array('status' => 'mail_sent', 'message' => 'Sent successfully'), array());
check_cooldown($json['status'] === 'mail_sent' && $json['message'] === 'Sent successfully' && isset($json['tool_kits_cooldown']), 'Successful response was replaced or lost its cooldown');
$options['antispam_cooldown_enabled'] = 0;
$json = tk_antispam_cf7_ajax_json_echo(array('status' => 'mail_sent'), array());
check_cooldown(!isset($json['tool_kits_cooldown']), 'Master off must omit timer metadata');

// A new valid request clears the previous rejection and does not create cooldowns when off.
$options += array('antispam_rate_limit_enabled' => 0, 'antispam_require_referrer' => 0, 'antispam_block_randomness' => 0);
tk_antispam_store_rejection('email_cooldown', 'Old rejection');
$result = tk_cf7_validate_honeypot_and_time(new CooldownValidation(), array());
check_cooldown($result->is_valid() && tk_antispam_get_rejection() === array(), 'Previous error leaked into a new request');
$json = tk_antispam_cf7_ajax_json_echo(array('status' => 'mail_sent'), array());
check_cooldown($json['status'] === 'mail_sent', 'Cleared rejection still overrides success');

// Registration is idempotent across CF7 validation and mail hooks in a single request.
$cache = $timeouts = array();
$options['antispam_cooldown_enabled'] = 1;
$new_values = array_replace($values, array('your-message' => 'A new detailed inquiry about a website catalog.'));
tk_antispam_register_submission($new_values);
$registered = $cache;
tk_antispam_register_submission($new_values);
check_cooldown($registered === $cache && tk_antispam_replay_reason($new_values) === '', 'Mail hooks must not restart or reject their own submission');
check_cooldown(!empty(tk_antispam_cooldown_status($new_values)), 'Success metadata must still see its registered cooldown');
$cache = $timeouts = array();
$invalid = new CooldownValidation(); $invalid->valid = false;
$_POST = array_replace($values, array('your-message' => 'An incomplete request with another validation error.'));
tk_cf7_validate_honeypot_and_time($invalid, array());
check_cooldown(!$cache, 'Other field validation errors must not start cooldown');

// Duration/master values save with the existing permission and nonce checks.
foreach (array('permission', 'nonce', 'valid') as $case) {
    $writes = array(); $admin = $case !== 'permission'; $nonce = $case !== 'nonce';
    $_POST = array('enabled' => 1, 'cooldown_enabled' => 1, 'email_cooldown_minutes' => 2, 'ip_cooldown_seconds' => 12, 'duplicate_window_minutes' => 0);
    try { tk_antispam_save_settings(); }
    catch (DomainException $error) { check_cooldown(!$writes, 'Unauthorized save changed settings'); }
    catch (LogicException $error) {
        check_cooldown($options['antispam_cooldown_enabled'] === 1 && $options['antispam_email_cooldown_minutes'] === 2 && $options['antispam_ip_cooldown_seconds'] === 12, 'Settings did not persist');
    }
}
$admin = $nonce = true;
$_POST = array('enabled' => 1, 'email_cooldown_minutes' => -5, 'ip_cooldown_seconds' => 0);
try { tk_antispam_save_settings(); } catch (LogicException $error) {}
check_cooldown($options['antispam_cooldown_enabled'] === 0 && $options['antispam_email_cooldown_minutes'] === 0 && $options['antispam_ip_cooldown_seconds'] === 0, 'Disable/zero/negative setting handling failed');

$assets = array('cf7' => true); $options['antispam_cooldown_enabled'] = 1;
tk_antispam_enqueue_countdown();
check_cooldown(isset($assets['tk-antispam-cooldown']) && $assets['tk-antispam-cooldown']['dependencies'] === array(), 'Timer must not force CF7 assets on unused pages');
$assets = array('cf7' => false); tk_antispam_enqueue_countdown();
check_cooldown(!isset($assets['tk-antispam-cooldown']), 'Timer loaded without CF7 assets');
$assets = array('cf7' => true); $options['antispam_cooldown_enabled'] = 0; tk_antispam_enqueue_countdown();
check_cooldown(!isset($assets['tk-antispam-cooldown']), 'Timer loaded while cooldown disabled');
$_POST = array_replace($values, array('tk_captcha_token' => 'internal-token', 'tk_captcha_answer' => 'TEST123', 'tk_robot_honeypot' => ''));
check_cooldown(tk_antispam_scalar_post_values() === $values, 'Internal CAPTCHA proofs were scanned as user message content');
echo "PASS: {$checks} cooldown expiry, legacy flags, settings, authorization, CF7 responses and asset checks\n";
