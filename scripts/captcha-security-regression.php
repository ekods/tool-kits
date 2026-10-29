<?php
// No wp-load, database, mail or network. Optionally uses CF7's real validation classes.
error_reporting(E_ALL);
set_error_handler(function ($level, $message) {
    if (error_reporting() & $level) { throw new RuntimeException($message); }
    return false;
});
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
$options = array('captcha_enabled' => 1, 'captcha_on_login' => 1, 'captcha_max_attempts' => 100, 'captcha_pow_bits' => 14);
$cache = $claims = array();
$checks = 0;
$_COOKIE = array('tk_captcha_browser' => str_repeat('a', 64));
$_SERVER = array('REMOTE_ADDR' => '192.0.2.1', 'HTTP_USER_AGENT' => 'Human browser', 'REQUEST_METHOD' => 'POST',
    'HTTP_ACCEPT_LANGUAGE' => 'id-ID,id;q=0.9', 'HTTP_SEC_FETCH_SITE' => 'same-origin');
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
class WP_Error {
    public $code; public $codes = array();
    public function __construct($code = '', $message = '') { $this->code = $code; }
    public function get_error_code() { return $this->code; }
    public function add($code, $message) { $this->codes[] = $code; }
}
function is_admin() { return $GLOBALS['is_admin'] ?? false; }
function wp_doing_ajax() { return $GLOBALS['doing_ajax'] ?? false; }
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
        public $name = ''; public $type = ''; public $basetype = ''; public $options = array();
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
    foreach (array_keys($GLOBALS['cache']) as $key) { if (strpos($key, 'tk_captcha_fail_') === 0) { unset($GLOBALS['cache'][$key]); } }
    $challenge = tk_captcha_create_challenge($type);
    $GLOBALS['cache']['tk_captcha_' . $challenge['token']]['issued_at'] -= 3;
    return $challenge;
}
function submit_challenge($challenge, $answer = null) {
    $_POST = array('tk_captcha_token' => $challenge['token'], 'tk_captcha_answer' => $answer ?? $challenge['code']);
    return tk_captcha_validate_request();
}
function solve_pow($token) {
    $pow = $GLOBALS['cache']['tk_captcha_' . $token]['pow'] ?? array();
    for ($nonce = 0; $nonce < 10000000; $nonce++) {
        if (tk_captcha_pow_valid((string) ($pow['salt'] ?? ''), $token, (string) $nonce, (int) ($pow['bits'] ?? 32))) { return (string) $nonce; }
    }
    return '';
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

$options['captcha_type'] = 'checkbox';
$checkbox = fixture_challenge('checkbox');
check_captcha(!submit_challenge($checkbox, 'VERIFIED_CLICK')['valid'], 'Legacy constant click marker was accepted');
$checkbox = fixture_challenge('checkbox');
$proof = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '1', 'pow_nonce' => solve_pow($checkbox['token'])));
check_captcha($proof->success && preg_match('/^[a-f0-9]{64}$/D', $proof->data['answer']), 'Checkbox-only verification did not return an opaque proof');
$repeat = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '1', 'pow_nonce' => solve_pow($checkbox['token'])));
check_captcha(!$repeat->success, 'Checkbox verification could mint another proof');
check_captcha(submit_challenge($checkbox, $proof->data['answer'])['valid'], 'Valid server proof was rejected');
check_captcha(!submit_challenge($checkbox, $proof->data['answer'])['valid'], 'Checkbox proof was reusable');
$checkbox = fixture_challenge('checkbox');
$bad = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '0'));
check_captcha(!$bad->success && !isset($cache['tk_captcha_verified_' . $checkbox['token']]), 'An unchecked widget was approved');
$checkbox = tk_captcha_create_challenge('checkbox');
$fast = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '1', 'pow_nonce' => solve_pow($checkbox['token'])));
check_captcha(!$fast->success && $fast->data === 'too_fast', 'Checkbox bypassed minimum server timing');
$checkbox = fixture_challenge('checkbox');
$bot = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '1', 'pow_nonce' => solve_pow($checkbox['token']), 'tk_robot_honeypot' => 'bot'));
check_captcha(!$bot->success && !isset($cache['tk_captcha_' . $checkbox['token']]), 'Checkbox ignored its filled honeypot');
$checkbox = fixture_challenge('checkbox');
$original_cookie = $_COOKIE['tk_captcha_browser']; $_COOKIE['tk_captcha_browser'] = str_repeat('f', 64);
$foreign = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '1', 'pow_nonce' => solve_pow($checkbox['token'])));
check_captcha(!$foreign->success, 'Checkbox verification ignored browser binding');
$_COOKIE['tk_captcha_browser'] = $original_cookie;
$checkbox_markup = tk_captcha_render_markup('checkbox');
check_captcha(strpos($checkbox_markup, 'class="tk-captcha-puzzle"') === false && strpos($checkbox_markup, 'class="tk-captcha-input') === false && strpos($checkbox_markup, '<img ') === false, 'Checkbox still requires a visible code input');
$checkbox = fixture_challenge('checkbox');
$missing = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'interact' => 999, 'timing' => 999999));
check_captcha(!$missing->success, 'Fabricated interactions bypassed the actual puzzle');
// Proof-of-work is required before the checkbox mints a proof.
$checkbox = fixture_challenge('checkbox');
$no_pow = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '1'));
check_captcha(!$no_pow->success && $no_pow->data === 'invalid_proof', 'Checkbox accepted a click without proof-of-work');
$pow = $cache['tk_captcha_' . $checkbox['token']]['pow'];
for ($bad_nonce = 0; tk_captcha_pow_valid($pow['salt'], $checkbox['token'], (string) $bad_nonce, $pow['bits']); $bad_nonce++) {}
$wrong_pow = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '1', 'pow_nonce' => (string) $bad_nonce));
check_captcha(!$wrong_pow->success && $wrong_pow->data === 'invalid_proof', 'Checkbox accepted an invalid proof-of-work nonce');
check_captcha(strpos(tk_captcha_render_markup('checkbox'), 'data-pow="') !== false, 'Checkbox markup has no proof-of-work parameters');
// Adaptive proof-of-work: suspicious visitors only tick the box, but must do far more hashing.
$base = tk_captcha_pow_bits();
$checkbox = fixture_challenge('checkbox');
check_captcha($cache['tk_captcha_' . $checkbox['token']]['pow']['bits'] === $base, 'A normal visitor did not get the base difficulty');
$adaptive_tests = array(
    'headless agent' => function () { $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 HeadlessChrome/120'; },
    'no browser headers' => function () { unset($_SERVER['HTTP_ACCEPT_LANGUAGE'], $_SERVER['HTTP_SEC_FETCH_SITE']); },
);
foreach ($adaptive_tests as $label => $setup) {
    $server = $_SERVER;
    $setup();
    $checkbox = fixture_challenge('checkbox');
    check_captcha($cache['tk_captcha_' . $checkbox['token']]['pow']['bits'] >= $base + 4, 'Suspicious visitor (' . $label . ') got the base difficulty');
    $_SERVER = $server;
}
$checkbox = fixture_challenge('checkbox');
tk_captcha_record_failure(); tk_captcha_record_failure();
check_captcha(tk_captcha_required_bits() >= $base + 4, 'Repeated failures did not raise the difficulty');
$cache = array_filter($cache, function ($key) { return strpos($key, 'tk_captcha_fail_') !== 0; }, ARRAY_FILTER_USE_KEY);
$_SERVER['HTTP_USER_AGENT'] = 'curl/8.0'; unset($_SERVER['HTTP_ACCEPT_LANGUAGE'], $_SERVER['HTTP_SEC_FETCH_SITE']);
check_captcha(tk_captcha_required_bits() === $base + 6, 'Maximum risk did not add the full extra difficulty');
$options['captcha_pow_bits'] = 22;
check_captcha(tk_captcha_required_bits() === 24, 'Difficulty is not capped at 24 bits');
$options['captcha_pow_bits'] = 14;
$_SERVER['HTTP_USER_AGENT'] = 'Human browser'; $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'id-ID,id;q=0.9'; $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';

// Click-time signals: a scripted (untrusted) click on a base-difficulty challenge must redo more work first.
foreach (array('untrusted click' => array('interaction' => '0'), 'webdriver' => array('automation' => '1')) as $label => $signal) {
    $checkbox = fixture_challenge('checkbox');
    $pow = $cache['tk_captcha_' . $checkbox['token']]['pow'];
    for ($weak = 0; !tk_captcha_pow_valid($pow['salt'], $checkbox['token'], (string) $weak, $base)
        || tk_captcha_pow_valid($pow['salt'], $checkbox['token'], (string) $weak, $base + 4); $weak++) {}
    $step = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '1', 'pow_nonce' => (string) $weak) + $signal);
    check_captcha($step->success && ($step->data['work'] ?? 0) >= $base + 4 && !isset($step->data['answer']) && !isset($cache['tk_captcha_verified_' . $checkbox['token']]), 'A ' . $label . ' got a proof without extra work');
    $again = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '1', 'pow_nonce' => (string) $weak) + $signal);
    check_captcha(!$again->success && $again->data === 'invalid_proof', 'A ' . $label . ' reused its weak proof after the difficulty was raised');
    $cache = array_filter($cache, function ($key) { return strpos($key, 'tk_captcha_fail_') !== 0; }, ARRAY_FILTER_USE_KEY);
    $strong = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '1', 'pow_nonce' => solve_pow($checkbox['token'])) + $signal);
    check_captcha($strong->success && preg_match('/^[a-f0-9]{64}$/D', $strong->data['answer'] ?? '') && submit_challenge($checkbox, $strong->data['answer'])['valid'], 'A ' . $label . ' could not finish after doing the extra work');
}
$checkbox = fixture_challenge('checkbox');
$human = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $checkbox['token'], 'checked' => '1', 'interaction' => '1', 'automation' => '0', 'pow_nonce' => solve_pow($checkbox['token'])));
check_captcha(!empty($human->data['answer']), 'A trusted human click was asked for extra work');
$options['captcha_adaptive_pow'] = 0;
$_SERVER['HTTP_USER_AGENT'] = 'curl/8.0';
check_captcha(tk_captcha_required_bits() === $base, 'Adaptive difficulty ignored being switched off');
$_SERVER['HTTP_USER_AGENT'] = 'Human browser';
unset($options['captcha_adaptive_pow']);
$options['captcha_type'] = 'text';

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
$svg_a = base64_decode(substr(tk_captcha_svg_uri('AAAAAA', 188, 68), 26));
$svg_b = base64_decode(substr(tk_captcha_svg_uri('AAAAAA', 188, 68), 26));
preg_match_all('/<path fill="#1e293b" d="([^"]+)"/', $svg_a, $glyph_paths);
check_captcha(strpos($svg_a, '<svg') === 0 && strpos($svg_a, '<text') === false && strpos($svg_a, 'transform=') === false
    && count($glyph_paths[1]) === 1 && $svg_a !== $svg_b, 'SVG fallback exposes repeatable per-character glyph paths');

// A form configured for Classic must not accept the click-only checkbox flow, and vice versa.
$options['captcha_type'] = 'text';
$downgrade = fixture_challenge('checkbox');
$proof = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $downgrade['token'], 'checked' => '1', 'pow_nonce' => solve_pow($downgrade['token'])));
check_captcha($proof->success && !submit_challenge($downgrade, $proof->data['answer'])['valid'], 'Checkbox proof bypassed a Classic-only form');
$options['captcha_type'] = 'checkbox';
$upgrade = fixture_challenge('text');
check_captcha(!submit_challenge($upgrade)['valid'], 'Classic answer was accepted by a checkbox-only form');
$options['captcha_type'] = 'text';
$robot_tag = new WPCF7_FormTag(array('type' => 'toolkits_captcha', 'basetype' => 'toolkits_captcha', 'name' => '', 'options' => array('robot:1')));
check_captcha(tk_captcha_cf7_types(array($tags[0], $robot_tag)) === array('checkbox') && tk_captcha_cf7_types(array($tags[1])) === array('text'), 'CF7 expected type was not read from the form tag');
$classic_form = fixture_challenge('checkbox');
$proof = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $classic_form['token'], 'checked' => '1', 'pow_nonce' => solve_pow($classic_form['token'])));
$_POST = array('tk_captcha_token' => $classic_form['token'], 'tk_captcha_answer' => $proof->data['answer']);
check_captcha(!tk_captcha_validate_cf7(new WPCF7_Validation(), $tags)->is_valid() || tk_captcha_cf7_spam(false), 'Checkbox proof bypassed a Classic CF7 form');
$robot_form = fixture_challenge('checkbox');
$proof = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $robot_form['token'], 'checked' => '1', 'pow_nonce' => solve_pow($robot_form['token'])));
$_POST = array('tk_captcha_token' => $robot_form['token'], 'tk_captcha_answer' => $proof->data['answer']);
tk_captcha_validate_cf7(new WPCF7_Validation(), array($tags[0], $robot_tag));
check_captcha(!tk_captcha_cf7_spam(false), 'A robot CF7 form rejected its valid checkbox proof');

// Login form: same type enforcement, proof-of-work and adaptive rules as every other form.
$user = (object) array('ID' => 3);
$options['captcha_type'] = 'checkbox';
$login = fixture_challenge('checkbox');
$proof = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $login['token'], 'checked' => '1', 'interaction' => '1', 'pow_nonce' => solve_pow($login['token'])));
$_POST = array('log' => 'member', 'pwd' => 'password', 'tk_captcha_token' => $login['token'], 'tk_captcha_answer' => $proof->data['answer']);
check_captcha(tk_captcha_validate($user) === $user, 'Robot checkbox proof was rejected on the login form');
check_captcha(tk_captcha_validate($user) instanceof WP_Error, 'Login checkbox proof was reusable');
$login = fixture_challenge('checkbox');
$_POST = array('log' => 'member', 'pwd' => 'password', 'tk_captcha_token' => $login['token'], 'tk_captcha_answer' => str_repeat('a', 64));
check_captcha(tk_captcha_validate($user) instanceof WP_Error, 'Login accepted a checkbox without a server proof');
$options['captcha_type'] = 'text';
$login = fixture_challenge('checkbox');
$proof = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $login['token'], 'checked' => '1', 'interaction' => '1', 'pow_nonce' => solve_pow($login['token'])));
$_POST = array('log' => 'member', 'pwd' => 'password', 'tk_captcha_token' => $login['token'], 'tk_captcha_answer' => $proof->data['answer']);
check_captcha(tk_captcha_validate($user) instanceof WP_Error, 'Checkbox proof bypassed a Classic login form');
$login = fixture_challenge();
$_POST = array('log' => 'member', 'pwd' => 'password', 'tk_captcha_token' => $login['token'], 'tk_captcha_answer' => strtolower($login['code']));
check_captcha(tk_captcha_validate($user) === $user, 'Correct Classic code was rejected on the login form');
$options['captcha_type'] = 'checkbox';
fixture_challenge('checkbox');
$base_login = tk_captcha_required_bits();
tk_captcha_login_failed('member', new WP_Error('incorrect_password', ''));
tk_captcha_login_failed('member', new WP_Error('incorrect_password', ''));
check_captcha(tk_captcha_required_bits() >= $base_login + 4, 'Wrong passwords did not raise the login proof-of-work');
$login = fixture_challenge('checkbox');
check_captcha($cache['tk_captcha_' . $login['token']]['pow']['bits'] === $base_login, 'Fixture did not reset failures');
tk_captcha_login_failed('member', new WP_Error('captcha_invalid', ''));
check_captcha(tk_captcha_failures() === 0, 'A CAPTCHA rejection was counted twice on login');
$options['captcha_on_login'] = 0;
tk_captcha_login_failed('member', new WP_Error('incorrect_password', ''));
check_captcha(tk_captcha_failures() === 0 && tk_captcha_login_form_middle('<p>x</p>') === '<p>x</p>', 'Login CAPTCHA hooks ran while login protection was off');
$options['captcha_on_login'] = 1;
check_captcha(strpos(tk_captcha_login_form_middle('<p>x</p>'), 'data-pow="') !== false, 'wp_login_form() did not render the login CAPTCHA');
$options['captcha_type'] = 'text';

// Lost password and registration on wp-login.php follow the same rules; admin and WooCommerce resets are untouched.
$errors = new WP_Error(); $_POST = array('user_login' => 'member');
tk_captcha_validate_lostpassword($errors);
check_captcha($errors->codes === array('captcha_missing'), 'Lost password without CAPTCHA was accepted');
$reset = fixture_challenge(); $errors = new WP_Error();
$_POST = array('user_login' => 'member', 'tk_captcha_token' => $reset['token'], 'tk_captcha_answer' => $reset['code']);
tk_captcha_validate_lostpassword($errors);
check_captcha($errors->codes === array(), 'Lost password with a correct CAPTCHA was rejected');
$errors = new WP_Error();
tk_captcha_validate_lostpassword($errors);
check_captcha($errors->codes === array('captcha_invalid'), 'Lost password CAPTCHA was reusable');
foreach (array('admin reset' => function () { $GLOBALS['is_admin'] = true; }, 'ajax reset' => function () { $GLOBALS['doing_ajax'] = true; },
    'WooCommerce reset' => function () { $_POST['wc_reset_password'] = 'true'; }) as $label => $setup) {
    $_POST = array('user_login' => 'member'); $errors = new WP_Error(); $setup();
    tk_captcha_validate_lostpassword($errors);
    check_captcha($errors->codes === array(), 'CAPTCHA blocked the ' . $label);
    $GLOBALS['is_admin'] = $GLOBALS['doing_ajax'] = false;
}
$options['captcha_type'] = 'checkbox';
$signup = fixture_challenge('checkbox');
$proof = captcha_endpoint('tk_captcha_verify_click_ajax', array('nonce' => 'valid', 'token' => $signup['token'], 'checked' => '1', 'interaction' => '1', 'pow_nonce' => solve_pow($signup['token'])));
$_POST = array('user_login' => 'new', 'user_email' => 'new@example.test', 'tk_captcha_token' => $signup['token'], 'tk_captcha_answer' => $proof->data['answer']);
check_captcha(tk_captcha_validate_registration(new WP_Error())->codes === array(), 'Registration with a robot checkbox proof was rejected');
$_POST = array('user_login' => 'new', 'user_email' => 'new@example.test');
check_captcha(tk_captcha_validate_registration(new WP_Error())->codes === array('captcha_missing'), 'Registration without CAPTCHA was accepted');
$options['captcha_type'] = 'text';
$options['captcha_on_login'] = 0;
check_captcha(tk_captcha_validate_registration(new WP_Error())->codes === array(), 'Registration CAPTCHA ran while login protection was off');
$options['captcha_on_login'] = 1;

$user = (object) array('ID' => 3); $_POST = array();
check_captcha(tk_captcha_validate($user) === $user, 'Login GET requests were blocked');
$_POST = array('log' => 'member', 'pwd' => 'password');
check_captcha(tk_captcha_validate($user) instanceof WP_Error, 'Login without CAPTCHA was accepted');
$options['captcha_enabled'] = 0;
check_captcha(tk_captcha_validate($user) === $user, 'Disabled CAPTCHA still blocked login');
$_POST = array('_nonce' => 'valid', 'enabled' => 1, 'captcha_type' => 'unsupported', 'length' => 1, 'min_seconds' => 0, 'max_attempts' => 500, 'pow_bits' => 99, 'adaptive_pow' => '0');
try { tk_captcha_save(); } catch (LogicException $error) {}
check_captcha($options['captcha_type'] === 'text' && $options['captcha_length'] === 6 && $options['captcha_min_seconds'] === 1 && $options['captcha_max_attempts'] === 100
    && $options['captcha_pow_bits'] === 22 && $options['captcha_adaptive_pow'] === 0, 'Captcha settings were not normalized/clamped');
$before = $options; $_POST = array();
try { tk_captcha_save(); } catch (DomainException $error) {}
check_captcha($before === $options, 'Invalid settings nonce changed options');
echo 'PASS: ' . $checks . ' CAPTCHA binding, opaque proofs, one-use claims, missing-field rejection, image secrecy, rate limits, settings and ' . ($real_cf7 ? 'real CF7 validation' : 'CF7 API contract') . " checks\n";
