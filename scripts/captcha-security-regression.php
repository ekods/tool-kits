<?php
// No wp-load, database, mail or network. Optionally uses CF7's real validation classes.
error_reporting(E_ALL);
set_error_handler(function ($level, $message) {
    if (error_reporting() & $level) { throw new RuntimeException($message); }
    return false;
});
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
$options = array('captcha_enabled' => 1, 'captcha_on_login' => 1, 'captcha_max_attempts' => 100);
$cache = $claims = array();
$checks = 0;
$_COOKIE = array('tk_captcha_browser' => str_repeat('a', 64));
$_SERVER = array('REMOTE_ADDR' => '192.0.2.1', 'HTTP_USER_AGENT' => 'Human browser', 'REQUEST_METHOD' => 'POST');
$_POST = array();
function tk_get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function tk_update_option($key, $value) { $GLOBALS['options'][$key] = $value; }
function tk_post($key, $default = '') { return $_POST[$key] ?? $default; }
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = $value; }
function delete_transient($key) { unset($GLOBALS['cache'][$key]); }
function add_option($key, $value, $unused = '', $autoload = false) {
    if (isset($GLOBALS['claims'][$key])) { return false; }
    $GLOBALS['claims'][$key] = $value; return true;
}
function wp_salt($scheme = '') { return 'fixture-secret-salt'; }
function wp_unslash($value) { return $value; }
function wp_hash_password($value) { return password_hash($value, PASSWORD_BCRYPT, array('cost' => 4)); }
function wp_check_password($value, $hash) { return password_verify($value, $hash); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)); }
function shortcode_atts($defaults, $atts, $name = '') { return array_merge($defaults, $atts); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value) { return esc_attr($value); }
function tk_csp_nonce_attr() { return ''; }
function wpcf7() { return true; }
function wpcf7_is_name($value) { return preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', (string) $value); }
function wpcf7_scan_form_tags($conditions = array()) { return $GLOBALS['tags'] ?? array(); }
function wpcf7_get_current_contact_form() { return $GLOBALS['form'] ?? null; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid'; }
function nocache_headers() { $GLOBALS['no_cache'] = true; }
function is_ssl() { return true; }
function tk_is_admin_user() { return $GLOBALS['admin'] ?? true; }
function tk_check_nonce($action) { if (($_POST['_nonce'] ?? '') !== 'valid') { throw new DomainException('nonce'); } }
function wp_die($message) { throw new DomainException($message); }
function wp_redirect($url) { throw new LogicException('redirect'); }
function admin_url($path) { return '/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
class WP_Error { public $code; public function __construct($code, $message) { $this->code = $code; } }
class CaptchaResponse extends RuntimeException {
    public $success; public $data; public $status;
    public function __construct($success, $data, $status) { $this->success = $success; $this->data = $data; $this->status = $status; }
}
function wp_send_json_error($data, $status = 200) { throw new CaptchaResponse(false, $data, $status); }
function wp_send_json_success($data, $status = 200) { throw new CaptchaResponse(true, $data, $status); }
$cf7_root = rtrim((string) getenv('TK_CF7_ROOT'), '/');
$real_cf7 = is_file($cf7_root . '/includes/validation.php');
if ($real_cf7) {
    if (!function_exists('str_starts_with')) { function str_starts_with($haystack, $needle) { return $needle === '' || strpos($haystack, $needle) === 0; } }
    require $cf7_root . '/includes/form-tag.php';
    require $cf7_root . '/includes/validation.php';
} else {
    class WPCF7_FormTag {
        public $name = ''; public $type = ''; public $basetype = '';
        public function __construct($properties) { foreach ($properties as $key => $value) { $this->$key = $value; } }
    }
    class WPCF7_Validation {
        private $errors = array();
        public function invalidate($tag, $message) { if ($tag instanceof WPCF7_FormTag && $tag->name !== '') { $this->errors[$tag->name] = $message; } }
        public function is_valid($name = null) { return !$this->errors; }
    }
}
require dirname(__DIR__) . '/includes/security-captcha.php';
class CaptchaDatabase {
    public $options = 'wp_options';
    public function esc_like($value) { return addcslashes($value, '_%'); }
    public function prepare($sql, ...$args) { return $sql; }
    public function query($sql) { $GLOBALS['cleaned'] = strpos($sql, 'LIMIT 1000') !== false; }
}
$wpdb = new CaptchaDatabase();
function check_captcha($condition, $message) { if (!$condition) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
function fixture_challenge($type = 'text') {
    $challenge = tk_captcha_create_challenge($type);
    $GLOBALS['cache']['tk_captcha_' . $challenge['token']]['issued_at'] -= 3;
    return $challenge;
}
function submit_challenge($challenge, $answer = null) {
    $_POST = array('tk_captcha_token' => $challenge['token'], 'tk_captcha_answer' => $answer ?? $challenge['code']);
    return tk_captcha_validate_request();
}
function captcha_endpoint($name, $post) {
    $_POST = $post;
    try { call_user_func($name); } catch (CaptchaResponse $response) { return $response; }
    throw new RuntimeException('Endpoint did not return JSON');
}

$tags = array(new WPCF7_FormTag(array('type' => 'email*', 'basetype' => 'email', 'name' => 'your-email')),
    new WPCF7_FormTag(array('type' => 'toolkits_captcha', 'basetype' => 'toolkits_captcha', 'name' => '')));
$result = tk_captcha_validate_cf7(new WPCF7_Validation(), $tags);
check_captcha(!$result->is_valid(), 'Removing both CAPTCHA fields bypassed a protected CF7 form');
check_captcha(tk_captcha_cf7_spam(false), 'Missing CAPTCHA did not stop CF7 mail');
$response = tk_captcha_cf7_response(array('status' => 'mail_sent'), array());
check_captcha($response['status'] === 'validation_failed' && $response['tool_kits_captcha_error'], 'CF7 did not expose the CAPTCHA failure');
$_POST = array();
check_captcha(tk_captcha_validate_cf7(new WPCF7_Validation(), array($tags[0]))->is_valid(), 'A form without a configured CAPTCHA was blocked');
check_captcha(!tk_captcha_cf7_spam(false), 'A previous form rejection leaked into another form');
$form = new class { public function prop($name) { return '[email* your-email] [toolkits_captcha robot="1"]'; } };
check_captcha(tk_captcha_cf7_required(array($tags[0])), 'Server-side form configuration was not checked');
$form = null;
$_POST = array();
tk_captcha_validate_cf7(new WPCF7_Validation(), array($tags[1]));
check_captcha(tk_captcha_cf7_spam(false), 'An unnamed CAPTCHA-only form could still send mail');

$challenge = fixture_challenge();
check_captcha(strlen($challenge['code']) >= 6 && preg_match('/^[a-f0-9]{48}$/D', $challenge['token']), 'Challenge entropy/length is insufficient');
$snapshot = $cache['tk_captcha_' . $challenge['token']];
check_captcha(submit_challenge($challenge)['valid'], 'Correct image answer was rejected');
$cache['tk_captcha_' . $challenge['token']] = $snapshot;
check_captcha(!submit_challenge($challenge)['valid'], 'Concurrent/replayed token bypassed atomic claim');
$challenge = fixture_challenge();
check_captcha(!submit_challenge($challenge, 'WRONG123')['valid'], 'Incorrect code was accepted');
check_captcha(!submit_challenge($challenge)['valid'], 'A guessed challenge could be retried');
$challenge = fixture_challenge();
$original_cookie = $_COOKIE['tk_captcha_browser']; $_COOKIE['tk_captcha_browser'] = str_repeat('b', 64);
check_captcha(!submit_challenge($challenge)['valid'], 'A challenge was accepted by another browser');
$_COOKIE['tk_captcha_browser'] = $original_cookie;
$_SERVER['HTTP_USER_AGENT'] = 'Different agent';
check_captcha(!submit_challenge($challenge)['valid'], 'A transferred token ignored its user-agent binding');
$_SERVER['HTTP_USER_AGENT'] = 'Human browser';
check_captcha(submit_challenge($challenge)['valid'], 'Foreign attempts invalidated the genuine visitor token');
$challenge = fixture_challenge(); $_SERVER['REMOTE_ADDR'] = '192.0.2.2';
check_captcha(submit_challenge($challenge)['valid'], 'A reverse-proxy peer change rejected the same browser cookie');
$_SERVER['REMOTE_ADDR'] = '192.0.2.1'; unset($_COOKIE['tk_captcha_browser']);
$challenge = fixture_challenge(); $_SERVER['REMOTE_ADDR'] = '192.0.2.2';
check_captcha(!submit_challenge($challenge)['valid'], 'Cookie-less fallback ignored its network peer');
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
check_captcha(submit_challenge($challenge)['valid'], 'Cookie-less same-peer verification was rejected');
$_COOKIE['tk_captcha_browser'] = $original_cookie;
$challenge = fixture_challenge(); $cache['tk_captcha_' . $challenge['token']]['expires_at'] = time() - 1;
check_captcha(!submit_challenge($challenge)['valid'], 'Expired challenge was accepted');
$challenge = tk_captcha_create_challenge();
check_captcha(!submit_challenge($challenge)['valid'], 'Browser-reported timing bypassed minimum server solve time');
$_POST = array('tk_captcha_token' => array('bad'), 'tk_captcha_answer' => array('bad'));
check_captcha(!tk_captcha_validate_request()['valid'], 'Malformed field arrays were accepted');
$challenge = fixture_challenge(); $_POST = array('tk_robot_honeypot' => 'bot', 'tk_captcha_token' => $challenge['token'], 'tk_captcha_answer' => $challenge['code']);
check_captcha(!tk_captcha_validate_request()['valid'], 'Filled honeypot was ignored');

$checkbox = fixture_challenge('checkbox');
check_captcha(!submit_challenge($checkbox, 'VERIFIED_CLICK')['valid'], 'Legacy constant click marker was accepted');
$checkbox = fixture_challenge('checkbox');
$proof = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '1'));
check_captcha($proof->success && preg_match('/^[a-f0-9]{64}$/D', $proof->data['answer']), 'Checkbox-only verification did not return an opaque proof');
$repeat = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '1'));
check_captcha(!$repeat->success, 'Checkbox verification could mint another proof');
check_captcha(submit_challenge($checkbox, $proof->data['answer'])['valid'], 'Valid server proof was rejected');
check_captcha(!submit_challenge($checkbox, $proof->data['answer'])['valid'], 'Checkbox proof was reusable');
$checkbox = fixture_challenge('checkbox');
$bad = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '0'));
check_captcha(!$bad->success && !isset($cache['tk_captcha_verified_' . $checkbox['token']]), 'An unchecked widget was approved');
$checkbox = tk_captcha_create_challenge('checkbox');
$fast = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '1'));
check_captcha(!$fast->success && $fast->data === 'too_fast', 'Checkbox bypassed minimum server timing');
$checkbox = fixture_challenge('checkbox');
$bot = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '1', 'tk_robot_honeypot' => 'bot'));
check_captcha(!$bot->success && !isset($cache['tk_captcha_' . $checkbox['token']]), 'Checkbox ignored its filled honeypot');
$checkbox = fixture_challenge('checkbox');
$original_cookie = $_COOKIE['tk_captcha_browser']; $_COOKIE['tk_captcha_browser'] = str_repeat('f', 64);
$foreign = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '1'));
check_captcha(!$foreign->success, 'Checkbox verification ignored browser binding');
$_COOKIE['tk_captcha_browser'] = $original_cookie;
$checkbox_markup = tk_captcha_render_markup('checkbox');
check_captcha(strpos($checkbox_markup, 'class="tk-captcha-puzzle"') === false && strpos($checkbox_markup, 'class="tk-captcha-input') === false && strpos($checkbox_markup, '<img ') === false, 'Checkbox still requires a visible code input');
$checkbox = fixture_challenge('checkbox');
$missing = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'interact' => 999, 'timing' => 999999));
check_captcha(!$missing->success, 'Fabricated interactions bypassed the actual puzzle');

$old = fixture_challenge();
$refresh = captcha_endpoint('tk_captcha_refresh_ajax', array('nonce' => 'valid', 'type' => 'text', 'old_token' => $old['token']));
check_captcha($refresh->success && !isset($cache['tk_captcha_' . $old['token']]) && !empty($no_cache), 'Refresh did not invalidate the old challenge or disable caching');
check_captcha(strpos($refresh->data['markup'], 'data:image/') !== false, 'Image CAPTCHA was not rendered');
check_captcha(strpos($refresh->data['markup'], 'tk-captcha-status') !== false, 'Accessible retry status is missing');
$nonce = captcha_endpoint('tk_captcha_refresh_ajax', array('nonce' => 'invalid'));
check_captcha(!$nonce->success && $nonce->status === 403, 'Invalid refresh nonce was accepted');
$_SERVER['REQUEST_METHOD'] = 'GET';
$method = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid'));
check_captcha(!$method->success && $method->status === 405, 'GET verification was accepted');
$_SERVER['REQUEST_METHOD'] = 'POST';
$options['captcha_max_attempts'] = 5; $cache = array();
for ($i = 0; $i < 5; $i++) { check_captcha(!tk_captcha_rate_limited('verify'), 'Rate limit blocked too early'); }
check_captcha(tk_captcha_rate_limited('verify'), 'Repeated verification was not rate limited');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.77';
check_captcha(tk_captcha_rate_limited('verify'), 'Forging a forwarded IP bypassed rate limiting');
$options['captcha_max_attempts'] = 100;
$uri = tk_captcha_image_uri('ABC234');
$decoded = base64_decode(substr($uri, strpos($uri, ',') + 1));
check_captcha(strpos($decoded, 'ABC234') === false && strpos($decoded, '<text') === false, 'Answer remains available as source text');
check_captcha(substr($decoded, 0, 8) === "\x89PNG\r\n\x1a\n" || strpos($decoded, '<svg') === 0, 'Image is not a valid PNG/SVG');
if (getenv('TK_CAPTCHA_PREVIEW')) { file_put_contents(getenv('TK_CAPTCHA_PREVIEW'), $decoded); }

$user = (object) array('ID' => 3); $_POST = array();
check_captcha(tk_captcha_validate($user) === $user, 'Login GET requests were blocked');
$_POST = array('log' => 'member', 'pwd' => 'password');
check_captcha(tk_captcha_validate($user) instanceof WP_Error, 'Login without CAPTCHA was accepted');
$options['captcha_enabled'] = 0;
check_captcha(tk_captcha_validate($user) === $user, 'Disabled CAPTCHA still blocked login');
$_POST = array('_nonce' => 'valid', 'enabled' => 1, 'captcha_type' => 'unsupported', 'length' => 1, 'min_seconds' => 0, 'max_attempts' => 500);
try { tk_captcha_save(); } catch (LogicException $error) {}
check_captcha($options['captcha_type'] === 'text' && $options['captcha_length'] === 6 && $options['captcha_min_seconds'] === 1 && $options['captcha_max_attempts'] === 100, 'Captcha settings were not normalized/clamped');
$before = $options; $_POST = array();
try { tk_captcha_save(); } catch (DomainException $error) {}
check_captcha($before === $options, 'Invalid settings nonce changed options');
echo 'PASS: ' . $checks . ' CAPTCHA binding, opaque proofs, one-use claims, missing-field rejection, image secrecy, rate limits, settings and ' . ($real_cf7 ? 'real CF7 validation' : 'CF7 API contract') . " checks\n";
