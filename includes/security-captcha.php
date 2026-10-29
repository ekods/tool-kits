<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/captcha-image.php';

/**
 * Random string captcha
 */

function tk_captcha_init() {
    add_action('login_form', 'tk_captcha_render_field');
    add_filter('login_form_middle', 'tk_captcha_login_form_middle');
    add_filter('authenticate', 'tk_captcha_validate', 40, 3);
    add_action('wp_login_failed', 'tk_captcha_login_failed', 10, 2);
    add_action('lostpassword_form', 'tk_captcha_render_field');
    add_action('lostpassword_post', 'tk_captcha_validate_lostpassword', 10, 1);
    add_action('register_form', 'tk_captcha_render_field');
    add_filter('registration_errors', 'tk_captcha_validate_registration', 10, 1);
    add_action('admin_post_tk_captcha_save', 'tk_captcha_save');
    add_shortcode('toolkits_captcha', 'tk_captcha_shortcode');
    add_action('wpcf7_init', 'tk_captcha_register_wpcf7_tag');
    add_filter('wpcf7_validate', 'tk_captcha_validate_cf7', 10, 2);
    add_filter('wpcf7_spam', 'tk_captcha_cf7_spam', 30, 2);
    add_filter('wpcf7_feedback_response', 'tk_captcha_cf7_response', 30, 2);
    add_action('wp_footer', 'tk_captcha_refresh_script', 19); // After footer content, before footer scripts print (20).
    add_action('login_footer', 'tk_captcha_refresh_script');
    
    // Custom AJAX handler to bypass admin-ajax.php blocks
    add_action('template_redirect', 'tk_captcha_custom_ajax_handler');
    add_action('init', 'tk_captcha_custom_ajax_handler_init');
}

function tk_captcha_custom_ajax_handler_init() {
    if (isset($_GET['tk_captcha_action'])) {
        $action = sanitize_key((string) wp_unslash($_GET['tk_captcha_action']));
        if ($action === 'refresh') {
            tk_captcha_refresh_ajax();
        } elseif ($action === 'verify_click') {
            tk_captcha_verify_click_ajax();
        } else {
            wp_send_json_error('invalid_action');
        }
        exit;
    }
}

function tk_captcha_custom_ajax_handler() {
    if (isset($_GET['tk_captcha_action'])) {
        $action = sanitize_key((string) wp_unslash($_GET['tk_captcha_action']));
        if ($action === 'refresh') {
            tk_captcha_refresh_ajax();
        } elseif ($action === 'verify_click') {
            tk_captcha_verify_click_ajax();
        } else {
            wp_send_json_error('invalid_action');
        }
        exit;
    }
}

function tk_captcha_length(): int {
    return max(6, min(10, (int) tk_get_option('captcha_length', 6)));
}

function tk_captcha_make_code(int $length = 0): string {
    if ($length <= 0) {
        $length = tk_captcha_length();
    }
    $chars = tk_captcha_char_set();
    $max = strlen($chars) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $pick = $chars[random_int(0, $max)];
        $out .= tk_captcha_rand_letter_case($pick);
    }
    return strtoupper($out);
}

function tk_captcha_strength(): string {
    $strength = sanitize_key(tk_get_option('captcha_strength', 'medium'));
    if (!in_array($strength, array('easy', 'medium', 'hard'), true)) {
        return 'medium';
    }
    return $strength;
}

function tk_captcha_char_set(): string {
    $strength = tk_captcha_strength();
    switch ($strength) {
        case 'easy':
            return 'abcdefghijklmnopqrstuvwxyz';
        case 'hard':
            return 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        case 'medium':
        default:
            return 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    }
}

function tk_captcha_random_gradient(): string {
    $c1 = sprintf('#%06x', mt_rand(0, 0xffffff));
    $c2 = sprintf('#%06x', mt_rand(0, 0xffffff));
    return "linear-gradient(135deg, {$c1} 0%, {$c2} 100%)";
}

function tk_captcha_random_text_shadow(): string {
    $offset = mt_rand(1, 4);
    $blur = mt_rand(3, 6);
    $color = sprintf('#%06x', mt_rand(0, 0xffffff));
    return "{$offset}px {$offset}px {$blur}px {$color}";
}

function tk_captcha_rand_letter_case(string $char): string {
    if (!ctype_alpha($char)) {
        return $char;
    }
    return (random_int(0, 1) === 1) ? strtoupper($char) : strtolower($char);
}

function tk_captcha_span_for_char(string $char, array $params): string {
    $rotation = random_int(-20, 20);
    $skew = random_int(-5, 5);
    $translateX = random_int(-1, 8);
    $color = sprintf('#%06x', mt_rand(0xB0C4DE, 0xE2E8F0));
    return sprintf(
        '<span style="display:inline-block;transform:rotate(%ddeg) skew(%ddeg) translateX(%dpx);margin-right:%dpx;margin-bottom:4px;color:%s;text-shadow:%s;">%s</span>',
        $rotation,
        $skew,
        $translateX,
        random_int(2, 6),
        esc_attr($color),
        esc_attr($params['shadow']),
        esc_html($char)
    );
}

function tk_captcha_field_names(): array {
    return [
        'token' => 'tk_captcha_token',
        'answer' => 'tk_captcha_answer',
    ];
}

function tk_captcha_normalize_type($type = ''): string {
    $type = sanitize_key((string) $type);
    if (in_array($type, array('robot', 'checkbox'), true)) {
        return 'checkbox';
    }
    if (in_array($type, array('classic', 'clasic', 'text'), true)) {
        return 'text';
    }
    return 'text';
}

function tk_captcha_current_type(): string {
    return tk_captcha_normalize_type(tk_get_option('captcha_type', 'text'));
}

function tk_captcha_type_from_shortcode_atts(array $atts): string {
    $atts = shortcode_atts(
        array(
            'type' => '',
            'mode' => '',
            'robot' => '',
            'classic' => '',
            'clasic' => '',
        ),
        $atts,
        'toolkits_captcha'
    );

    if (tk_truthy_shortcode_value($atts['robot'])) {
        return 'checkbox';
    }

    if (tk_truthy_shortcode_value($atts['classic']) || tk_truthy_shortcode_value($atts['clasic'])) {
        return 'text';
    }

    $type = $atts['type'] !== '' ? $atts['type'] : $atts['mode'];
    if ($type !== '') {
        return tk_captcha_normalize_type($type);
    }

    return tk_captcha_current_type();
}

function tk_truthy_shortcode_value($value): bool {
    if (is_bool($value)) {
        return $value;
    }

    $value = strtolower(trim((string) $value));
    return $value !== '' && !in_array($value, array('0', 'false', 'no', 'off'), true);
}

function tk_captcha_text(string $key): string {
    $locale = function_exists('determine_locale') ? determine_locale() : (function_exists('get_locale') ? get_locale() : 'en_US');
    $language = strtolower(substr((string) $locale, 0, 2));
    $strings = array(
        'en' => array(
            'enter_code' => 'Enter the code below',
            'type_captcha' => 'Type the captcha',
            'exact_case' => 'Type the verification code shown in the image.',
            'expires' => 'The code expires in 5 minutes.',
            'not_robot' => "I'm not a robot",
            'refresh_failed' => 'Failed to refresh captcha. Please reload the page.',
            'required' => 'Captcha is required.',
            'incorrect' => 'Captcha incorrect.',
            'verify' => 'Verify code',
            'loading' => 'Loading verification…',
            'too_fast' => 'Please wait a moment before verifying.',
            'rate_limited' => 'Too many verification attempts. Please try again later.',
            'verify_failed' => 'Verification failed. Please check the box again.',
        ),
        'id' => array(
            'enter_code' => 'Masukkan kode di bawah ini',
            'type_captcha' => 'Ketik kode captcha',
            'exact_case' => 'Ketik kode verifikasi yang terlihat pada gambar.',
            'expires' => 'Kode akan kedaluwarsa dalam 5 menit.',
            'not_robot' => 'Saya bukan robot',
            'refresh_failed' => 'Captcha gagal dimuat ulang. Silakan muat ulang halaman.',
            'required' => 'Captcha wajib diisi.',
            'incorrect' => 'Captcha tidak sesuai.',
            'verify' => 'Verifikasi kode',
            'loading' => 'Memuat verifikasi…',
            'too_fast' => 'Tunggu sebentar sebelum melakukan verifikasi.',
            'rate_limited' => 'Terlalu banyak percobaan verifikasi. Silakan coba lagi nanti.',
            'verify_failed' => 'Verifikasi gagal. Silakan centang kembali.',
        ),
    );

    $dictionary = $strings[$language] ?? $strings['en'];
    return $dictionary[$key] ?? ($strings['en'][$key] ?? $key);
}

function tk_captcha_scalar(array $source, string $key): string {
    return isset($source[$key]) && is_scalar($source[$key]) ? trim((string) wp_unslash($source[$key])) : '';
}

function tk_captcha_client(): string {
    // REMOTE_ADDR cannot be forged by adding an arbitrary X-Forwarded-For header.
    $cookie = tk_captcha_scalar($_COOKIE, 'tk_captcha_browser');
    $peer = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    // The browser cookie remains stable when a mobile connection or reverse-proxy peer changes.
    $identity = preg_match('/^[a-f0-9]{64}$/D', $cookie) ? 'browser:' . $cookie : 'peer:' . $peer;
    return hash_hmac('sha256', $identity . '|' . $agent, wp_salt('auth'));
}

function tk_captcha_browser_cookie(): void {
    if (preg_match('/^[a-f0-9]{64}$/D', tk_captcha_scalar($_COOKIE, 'tk_captcha_browser')) || headers_sent()) { return; }
    $value = bin2hex(random_bytes(32));
    setcookie('tk_captcha_browser', $value, array('expires' => 0, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax'));
    $_COOKIE['tk_captcha_browser'] = $value;
}

function tk_captcha_min_seconds(): int {
    return max(1, min(10, (int) tk_get_option('captcha_min_seconds', 2)));
}

function tk_captcha_rate_limited(string $bucket): bool {
    $max = max(5, min(100, (int) tk_get_option('captcha_max_attempts', 20)));
    foreach (array(tk_captcha_client() => $bucket === 'refresh' ? $max * 3 : $max,
        'peer:' . ($_SERVER['REMOTE_ADDR'] ?? '') => 600) as $identity => $limit) {
        $key = 'tk_captcha_rate_' . md5($bucket . '|' . $identity);
        $record = get_transient($key);
        if (!is_array($record) || ($record['expires_at'] ?? 0) <= time()) { $record = array('count' => 0, 'expires_at' => time() + 300); }
        $record['count']++;
        set_transient($key, $record, max(1, $record['expires_at'] - time()));
        if ($record['count'] > $limit) { return true; }
    }
    return false;
}

/** Unique option names make token consumption atomic, including with an external object cache. */
function tk_captcha_claim(string $token, string $stage): bool {
    return add_option('_tk_captcha_used_' . $stage . '_' . $token, time() + 600, '', false);
}

function tk_captcha_cleanup_claims(): void {
    if (get_transient('tk_captcha_claim_cleanup')) { return; }
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT 1000", $wpdb->esc_like('_tk_captcha_used_') . '%', time()));
    set_transient('tk_captcha_claim_cleanup', 1, 300);
}

function tk_captcha_pow_bits(): int {
    return max(14, min(22, (int) tk_get_option('captcha_pow_bits', 18)));
}

function tk_captcha_adaptive(): bool {
    return (bool) tk_get_option('captcha_adaptive_pow', 1);
}

/** Proof-of-work: sha256(salt:token:nonce) must start with $bits zero bits. Cheap once, costly at bot scale. */
function tk_captcha_pow_valid(string $salt, string $token, string $nonce, int $bits): bool {
    if ($salt === '' || !preg_match('/^[0-9]{1,12}$/D', $nonce)) { return false; }
    $hash = hash('sha256', $salt . ':' . $token . ':' . $nonce, true);
    $full = intdiv($bits, 8);
    for ($i = 0; $i < $full; $i++) {
        if ($hash[$i] !== "\0") { return false; }
    }
    $rest = $bits % 8;
    return $rest === 0 || (ord($hash[$full]) >> (8 - $rest)) === 0;
}

function tk_captcha_failure_keys(): array {
    return array('tk_captcha_fail_' . md5(tk_captcha_client()), 'tk_captcha_fail_' . md5('peer:' . ($_SERVER['REMOTE_ADDR'] ?? '')));
}

function tk_captcha_record_failure(): void {
    foreach (tk_captcha_failure_keys() as $key) {
        set_transient($key, (int) get_transient($key) + 1, HOUR_IN_SECONDS);
    }
}

function tk_captcha_failures(): int {
    return max(array_map(function ($key) { return (int) get_transient($key); }, tk_captcha_failure_keys()));
}

/** Request signals a real browser sends on its own; each one is cheap to fake, so they only raise the work required. */
function tk_captcha_risk_score(): int {
    $agent = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $score = min(3, tk_captcha_failures());
    if ($agent === '' || preg_match('/headless|phantomjs|selenium|puppeteer|playwright|webdriver|curl|wget|python|httpclient|go-http|java\/|okhttp|scrapy|node-fetch|axios|libwww/', $agent)) { $score += 2; }
    if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) { $score++; }
    if (empty($_SERVER['HTTP_SEC_FETCH_SITE'])) { $score++; }
    if (tk_captcha_scalar($_POST, 'automation') === '1') { $score += 2; }
    // A script calling element.click() produces an untrusted event; a real tap or click does not.
    if (isset($_POST['interaction']) && tk_captcha_scalar($_POST, 'interaction') !== '1') { $score += 2; }
    return $score;
}

/**
 * Instead of an image challenge, suspicious visitors get exponentially more work: every 2 risk points
 * add 4x the hashes, capped at 24 bits (~64x the default), so the user still only ticks a box.
 */
function tk_captcha_required_bits(): int {
    $extra = tk_captcha_adaptive() ? min(6, 2 * tk_captcha_risk_score()) : 0;
    return min(24, tk_captcha_pow_bits() + $extra);
}

function tk_captcha_challenge_valid($challenge): bool {
    return is_array($challenge) && !empty($challenge['client']) && !empty($challenge['issued_at'])
        && ($challenge['expires_at'] ?? 0) > time()
        && hash_equals($challenge['client'], tk_captcha_client());
}

function tk_captcha_answer_hash(string $answer, string $token): string {
    return hash_hmac('sha256', $token . '|' . strtoupper($answer), wp_salt('nonce'));
}

function tk_captcha_create_challenge(string $type = ''): array {
    $type = $type !== '' ? tk_captcha_normalize_type($type) : tk_captcha_current_type();
    $code = $type === 'text' ? tk_captcha_make_code() : '';
    $token = bin2hex(random_bytes(24));
    $hash = $type === 'text' ? tk_captcha_answer_hash($code, $token) : '';
    $pow = $type === 'checkbox' ? array('salt' => bin2hex(random_bytes(16)), 'bits' => tk_captcha_required_bits()) : array();
    set_transient(
        'tk_captcha_' . $token,
        array(
            'hash' => $hash,
            'type' => $type,
            'stage' => $type === 'checkbox' ? 'checkbox' : 'text',
            'pow' => $pow,
            'client' => tk_captcha_client(),
            'issued_at' => time(),
            'expires_at' => time() + 300,
        ),
        MINUTE_IN_SECONDS * 5
    );
    return ['code' => $code, 'token' => $token, 'pow' => $pow];
}

function tk_captcha_render_field() {
    if (!tk_get_option('captcha_enabled') || !tk_get_option('captcha_on_login')) {
        return;
    }
    echo tk_captcha_render_markup();
}

/** wp_login_form() never fires login_form, yet its POST still reaches tk_captcha_validate(). */
function tk_captcha_login_form_middle($content) {
    if (!tk_get_option('captcha_enabled') || !tk_get_option('captcha_on_login')) {
        return $content;
    }
    return $content . tk_captcha_render_markup();
}

/** A wrong password raises the next proof-of-work too, so password guessing gets slower per attempt. */
function tk_captcha_login_failed($username = '', $error = null) {
    if (!tk_get_option('captcha_enabled') || !tk_get_option('captcha_on_login')) {
        return;
    }
    // CAPTCHA rejections were already counted by tk_captcha_validate_request().
    if (is_wp_error($error) && in_array($error->get_error_code(), array('captcha_missing', 'captcha_invalid'), true)) {
        return;
    }
    tk_captcha_record_failure();
}

/** Adds the CAPTCHA error of a wp-login.php form submission to $errors, when protection applies. */
function tk_captcha_add_login_page_error(WP_Error $errors): void {
    if (!tk_get_option('captcha_enabled') || !tk_get_option('captcha_on_login')) {
        return;
    }
    $validation = tk_captcha_validate_request();
    if (!$validation['present']) {
        $errors->add('captcha_missing', tk_captcha_text('required'));
    } elseif (!$validation['valid']) {
        $errors->add('captcha_invalid', tk_captcha_text('incorrect'));
    }
}

function tk_captcha_validate_lostpassword($errors = null) {
    // retrieve_password() also runs for an admin's "Send password reset" and WooCommerce's own lost-password form,
    // neither of which renders this CAPTCHA.
    $doing_ajax = function_exists('wp_doing_ajax') && wp_doing_ajax();
    if (!is_wp_error($errors) || is_admin() || $doing_ajax || isset($_POST['wc_reset_password']) || !isset($_POST['user_login'])) {
        return;
    }
    tk_captcha_add_login_page_error($errors);
}

function tk_captcha_validate_registration($errors) {
    if (is_wp_error($errors)) {
        tk_captcha_add_login_page_error($errors);
    }
    return $errors;
}

function tk_captcha_render_markup(string $type = ''): string {
    $GLOBALS['tk_captcha_rendered'] = true;
    $type = $type !== '' ? tk_captcha_normalize_type($type) : tk_captcha_current_type();
    $challenge = tk_captcha_create_challenge($type);
    $names = tk_captcha_field_names();

    $gradient = tk_captcha_random_gradient();
    $shadow = tk_captcha_random_text_shadow();
    $bgNoise = 'radial-gradient(circle at 25px 15px, rgba(255,255,255,0.12), transparent 47%), radial-gradient(circle at 80px 45px, rgba(255,255,255,0.06), transparent 40%)';
    $noiseSvg = "url('data:image/svg+xml;utf8,<svg xmlns=\\'http://www.w3.org/2000/svg\\' width=\\'140\\' height=\\'60\\'><line x1=\\'0\\' y1=\\'30\\' x2=\\'140\\' y2=\\'30\\' stroke=\\'rgba(255,255,255,0.05)\\' stroke-width=\\'1\\'/><circle cx=\\'20\\' cy=\\'15\\' r=\\'1\\' fill=\\'rgba(255,255,255,0.3)\\'/><circle cx=\\'60\\' cy=\\'10\\' r=\\'2\\' fill=\\'rgba(255,255,255,0.25)\\'/></svg>')";

    $image = $type === 'text' ? '<img src="' . esc_attr(tk_captcha_image_uri($challenge['code'])) . '" alt="' . esc_attr(tk_captcha_text('type_captcha')) . '" width="' . (strlen($challenge['code']) * 28 + 20) . '" height="68" draggable="false">' : '';

    // inject CSS once per request (kalau function dipanggil berkali-kali)
    static $css_loaded = false;
    $css = '';
    if (!$css_loaded) {
        $css_loaded = true;
        $css = '
  .tk-captcha-field{
    margin:12px 0;
    display:flex;
    flex-direction:column;
    gap:8px;
  }
  .tk-captcha-label{
    display:block;
    margin-bottom:4px;
  }
  .tk-captcha-panel{
    display:flex;
    flex-direction:column;
    gap:16px;
    background:#fff;
    border-radius:14px;
    width: 100%;
    max-width: 300px;
  }
  .tk-captcha-codebox{
    width: fit-content;
    max-width: 100%;
    display:flex;
    flex-wrap:wrap;
    gap:4px;
    align-items:center;
    padding:10px 14px;
    border-radius:8px 14px;
    border:1px solid #eee;
    text-transform:none;
    font-size:32px;
    font-weight:700;
    box-sizing: border-box;
  }
  .tk-captcha-input{
    width: 100%;
    padding:12px 16px;
    border-radius:10px;
    border:1px solid rgba(148,163,184,0.4);
    color:#000;
    font-weight:600;
    outline:none;
    box-sizing: border-box;
  }
  .tk-captcha-input::placeholder{
    color:rgba(148,163,184,0.85);
  }
  .tk-captcha-input:focus{
    border-color:rgba(248,250,252,0.55);
    box-shadow:0 0 0 3px rgba(148,163,184,0.18);
  }
  .tk-captcha-help{
    display:flex;
    flex-direction:column;
    gap:3px;
    font-size:12px;
    color:#94a3b8;
    margin-top:12px;
  }
  .tk-captcha-help small{
    font-size:11px;
    color:#94a3b8;
  }
  .tk-captcha-code-wrapper{
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:12px;
  }
.tk-captcha-refresh {
  margin-left: 8px;
  margin-top: 4px;
  padding: 8px;
  border-radius: 8px;
  background: #fff;
  border: 1px solid #eee;
  color: #0f172a;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.tk-captcha-refresh-icon {
  width: 18px;
  height: 18px;
  transition: transform .25s ease;
}

.tk-captcha-refresh:hover .tk-captcha-refresh-icon {
  transform: rotate(180deg);
}

.tk-captcha-refresh:active .tk-captcha-refresh-icon {
  transform: rotate(360deg);
}
  .tk-captcha-checkbox-wrap {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #ffffff;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 0 16px;
    height: 78px;
    width: 100%;
    max-width: 320px;
    box-sizing: border-box;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
    user-select: none;
    transition: all 0.3s ease;
  }
  @media (max-width: 350px) {
    .tk-captcha-checkbox-wrap {
      padding: 0 10px;
      height: auto;
      min-height: 78px;
      flex-direction: column;
      padding-top: 12px;
      padding-bottom: 12px;
      gap: 12px;
      align-items: flex-start;
    }
    .tk-captcha-checkbox-right {
      align-self: flex-end;
      width: 100%;
      border-top: 1px solid #eee;
      padding-top: 8px;
    }
  }
  .tk-captcha-checkbox-wrap:hover {
    border-color: #9ca3af;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.08);
  }
  .tk-captcha-checkbox-left {
    display: flex;
    align-items: center;
    gap: 16px;
  }
  .tk-captcha-checkbox-box {
    width: 28px;
    height: 28px;
    border: 2px solid #cbd5e1;
    border-radius: 4px;
    background: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
  }
  .tk-captcha-checkbox-box:hover {
    border-color: #3b82f6;
    background: #f8fafc;
  }
  .tk-captcha-checkbox-box.is-checked {
    border: none;
    background: transparent;
  }
  .tk-captcha-checkbox-box.is-loading {
    border-color: #3b82f6;
    border-top-color: transparent;
    border-radius: 50%;
    animation: tk-captcha-spin 0.8s linear infinite;
    width: 28px;
    height: 28px;
  }
  @keyframes tk-captcha-spin { to { transform: rotate(360deg); } }
  .tk-captcha-checkmark {
    display: none;
    width: 36px;
    height: 36px;
    color: #059669;
    position: absolute;
    filter: drop-shadow(0 2px 4px rgba(5, 150, 105, 0.2));
  }
  .tk-captcha-checkbox-box.is-checked .tk-captcha-checkmark { 
    display: block; 
    animation: tk-checkmark-pop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
  }
  @keyframes tk-checkmark-pop {
    0% { transform: scale(0) rotate(-10deg); opacity: 0; }
    100% { transform: scale(1) rotate(0deg); opacity: 1; }
  }
  .tk-captcha-checkbox-text {
    font-size: 12px;
    color: #1e293b;
    font-weight: 500;
    cursor: pointer;
  }
  .tk-captcha-checkbox-right {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
  }
  .tk-captcha-logo-box {
    display: flex;
    flex-direction: column;
    align-items: center;
  }
  .tk-captcha-logo {
    width: 32px;
    height: 32px;
    filter: drop-shadow(0 2px 4px rgba(59, 130, 246, 0.1));
  }
  .tk-captcha-brand-text {
    font-size: 8px;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
  }
  .tk-captcha-footer-links {
    font-size: 8px;
    color: #94a3b8;
    margin-top: 2px;
  }
  .tk-captcha-footer-links a {
    color: #94a3b8;
    text-decoration: none;
    transition: color 0.2s;
  }
  .tk-captcha-footer-links a:hover {
    color: #64748b;
  }
  .tk-hp, .tk-captcha-answer-field, .tk-captcha-answer-field[type="hidden"], .comment-form-tk-hp, .tk-robot-honeypot-wrap {
    display: none !important;
    visibility: hidden !important;
    position: absolute !important;
    left: -9999px !important;
    top: -9999px !important;
    width: 1px !important;
    height: 1px !important;
    overflow: hidden !important;
    opacity: 0 !important;
    pointer-events: none !important;
  }
}';
    }

    $css .= '.tk-captcha-codebox img{display:block;max-width:100%;height:68px;object-fit:contain}.tk-captcha-codebox{height:auto;min-height:68px;background:#eef3fa!important;padding:4px}.tk-captcha-status{font-size:13px;margin:8px 0}.tk-captcha-field[aria-busy="true"]{opacity:.65}';
    $html = '<style' . tk_csp_nonce_attr() . '>' . $css . '</style>';
    
    if ($type === 'checkbox') {
        $html .= '<div class="tk-captcha-field" data-type="checkbox" data-pow="' . esc_attr($challenge['pow']['salt'] . ':' . $challenge['pow']['bits']) . '">';
        $html .= '<div class="tk-captcha-checkbox-wrap">';
        $html .= '  <div class="tk-captcha-checkbox-left">';
        $html .= '    <div class="tk-captcha-checkbox-box" role="checkbox" aria-checked="false" tabindex="0">
          <svg class="tk-captcha-checkmark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"></polyline>
          </svg>
        </div>';
        $html .= '    <span class="tk-captcha-checkbox-text">' . esc_html(tk_captcha_text('not_robot')) . '</span>';
        $html .= '  </div>';
        $html .= '  <div class="tk-captcha-checkbox-right">';
        $html .= '    <div class="tk-captcha-logo-box">';
        $html .= '      <svg class="tk-captcha-logo" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    <path d="M12 8v4"></path>
                    <path d="M12 16h.01"></path>
                  </svg>';
        $html .= '      <span class="tk-captcha-brand-text">ToolKits Guard</span>';
        $html .= '      <div class="tk-captcha-footer-links"><a href="#">Privacy</a> - <a href="#">Terms</a></div>';
        $html .= '    </div>';
        $html .= '  </div>';
        $html .= '</div>';
        $html .= '<input type="hidden" name="' . esc_attr($names['answer']) . '" value="" class="tk-captcha-answer-field">';
        $html .= '<input type="hidden" name="' . esc_attr($names['token']) . '" value="' . esc_attr($challenge['token']) . '">';
        $html .= '<div class="tk-robot-honeypot-wrap"><input type="text" name="tk_robot_honeypot" value="" tabindex="-1" autocomplete="off" aria-hidden="true"></div>';
        $html .= '<p class="tk-captcha-status" role="status" aria-live="polite"></p>';
        $html .= '</div>';
    } else {
        $html .= '<div class="tk-captcha-field" data-type="text">';
        $html .= '<label class="tk-captcha-label">' . esc_html(tk_captcha_text('enter_code')) . '</label>';
        $html .= '<div class="tk-captcha-panel">';
        $html .= '<div class="tk-captcha-code-wrapper">';
        $html .= '<div class="tk-captcha-codebox">';
        $html .= $image;
        $html .= '</div>';
        $html .= '<button type="button" class="tk-captcha-refresh" aria-label="Refresh captcha">
            <svg class="tk-captcha-refresh-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 12a9 9 0 0 0-15.74-6.74L3 8"/>
                <path d="M3 3v5h5"/>
                <path d="M3 12a9 9 0 0 0 15.74 6.74L21 16"/>
                <path d="M16 16h5v5"/>
            </svg>
            </button>';
        $html .= '</div>'; // code wrapper
        $html .= '<input type="text"
            class="tk-captcha-input"
            name="' . esc_attr($names['answer']) . '"
            autocomplete="off"
            placeholder="' . esc_attr(tk_captcha_text('type_captcha')) . '"
            aria-label="' . esc_attr(tk_captcha_text('type_captcha')) . '">';
        $html .= '</div>'; // panel
        $html .= '<div class="tk-captcha-help">';
        $html .= '<span>' . esc_html(tk_captcha_text('exact_case')) . '</span>';
        $html .= '<small>' . esc_html(tk_captcha_text('expires')) . '</small>';
        $html .= '</div>';
        $html .= '<input type="hidden" name="' . esc_attr($names['token']) . '" value="' . esc_attr($challenge['token']) . '">';
        $html .= '<p class="tk-captcha-status" role="status" aria-live="polite"></p>';
        $html .= '</div>';
    }

    return $html;
}




function tk_captcha_shortcode($atts) {
    if (!tk_get_option('captcha_enabled')) {
        return '';
    }
    return tk_captcha_render_markup(tk_captcha_type_from_shortcode_atts((array) $atts));
}

function tk_captcha_register_wpcf7_tag() {
    if (function_exists('wpcf7_add_form_tag')) {
        wpcf7_add_form_tag('toolkits_captcha', 'tk_captcha_wpcf7_tag_handler');
    }
}

function tk_captcha_wpcf7_tag_handler($tag) {
    if (!tk_get_option('captcha_enabled')) {
        return '';
    }
    return tk_captcha_render_markup(tk_captcha_type_from_wpcf7_tag($tag));
}

function tk_captcha_type_from_wpcf7_tag($tag): string {
    $atts = array();
    $items = array();

    foreach (array('options', 'raw_values', 'values') as $property) {
        if (is_object($tag) && isset($tag->{$property}) && is_array($tag->{$property})) {
            $items = array_merge($items, $tag->{$property});
        } elseif (is_array($tag) && isset($tag[$property]) && is_array($tag[$property])) {
            $items = array_merge($items, $tag[$property]);
        }
    }

    foreach ($items as $item) {
        $item = trim((string) $item);
        if (preg_match('/^(type|mode|robot|classic|clasic)(?::|=)(.*)$/i', $item, $match)) {
            $atts[strtolower($match[1])] = trim($match[2], " \t\n\r\0\x0B\"'");
        }
    }

    return tk_captcha_type_from_shortcode_atts($atts);
}

function tk_captcha_refresh_ajax() {
    nocache_headers();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_send_json_error('method_not_allowed', 405); }
    if (!wp_verify_nonce(tk_captcha_scalar($_POST, 'nonce'), 'tk_captcha_refresh')) { wp_send_json_error('invalid_nonce', 403); }
    if (!tk_get_option('captcha_enabled')) { wp_send_json_error('disabled', 403); }
    tk_captcha_browser_cookie();
    if (tk_captcha_rate_limited('refresh')) { wp_send_json_error('rate_limited', 429); }
    tk_captcha_cleanup_claims();
    $old = tk_captcha_scalar($_POST, 'old_token');
    if (preg_match('/^[a-f0-9]{48}$/D', $old) && tk_captcha_challenge_valid(get_transient('tk_captcha_' . $old))) {
        tk_captcha_claim($old, 'submit');
        delete_transient('tk_captcha_' . $old);
        delete_transient('tk_captcha_verified_' . $old);
    }
    wp_send_json_success(array('markup' => tk_captcha_render_markup(tk_captcha_scalar($_POST, 'type'))));
}

function tk_captcha_verify_click_ajax() {
    nocache_headers();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_send_json_error('method_not_allowed', 405); }
    if (!wp_verify_nonce(tk_captcha_scalar($_POST, 'nonce'), 'tk_captcha_verify_click')) { wp_send_json_error('invalid_nonce', 403); }
    if (!tk_get_option('captcha_enabled')) { wp_send_json_error('disabled', 403); }
    if (tk_captcha_rate_limited('verify')) { wp_send_json_error('rate_limited', 429); }
    $token = tk_captcha_scalar($_POST, 'token');
    if (!preg_match('/^[a-f0-9]{48}$/D', $token) || tk_captcha_scalar($_POST, 'checked') !== '1'
        || !preg_match('/^[a-f0-9]{64}$/D', tk_captcha_scalar($_COOKIE, 'tk_captcha_browser'))) { wp_send_json_error('invalid_challenge', 400); }
    $challenge = get_transient('tk_captcha_' . $token);
    if (!tk_captcha_challenge_valid($challenge) || ($challenge['type'] ?? '') !== 'checkbox') { wp_send_json_error('expired', 400); }
    if (time() - $challenge['issued_at'] < tk_captcha_min_seconds()) { wp_send_json_error('too_fast', 400); }
    if (tk_captcha_scalar($_POST, 'tk_robot_honeypot') !== '' || is_array($_POST['tk_robot_honeypot'] ?? null)) {
        delete_transient('tk_captcha_' . $token);
        tk_captcha_record_failure();
        wp_send_json_error('incorrect', 400);
    }
    $remaining = max(1, $challenge['expires_at'] - time());
    if (($challenge['stage'] ?? 'checkbox') !== 'checkbox') { wp_send_json_error('expired', 400); }
    $pow = (array) ($challenge['pow'] ?? array());
    $salt = (string) ($pow['salt'] ?? '');
    $issued = (int) ($pow['bits'] ?? 32);
    $nonce = tk_captcha_scalar($_POST, 'pow_nonce');
    if (!tk_captcha_pow_valid($salt, $token, $nonce, $issued)) {
        tk_captcha_record_failure();
        wp_send_json_error('invalid_proof', 400);
    }
    // Signals only visible at click time (automation flag, untrusted click) can still raise the work once more.
    $required = max($issued, tk_captcha_required_bits());
    if ($required > $issued && !tk_captcha_pow_valid($salt, $token, $nonce, $required)) {
        $challenge['pow']['bits'] = $required;
        set_transient('tk_captcha_' . $token, $challenge, $remaining);
        wp_send_json_success(array('work' => $required));
    }
    if (!tk_captcha_claim($token, 'solve')) { wp_send_json_error('expired', 400); }
    $proof = bin2hex(random_bytes(32));
    set_transient('tk_captcha_verified_' . $token, array(
        'hash' => hash('sha256', $proof), 'client' => $challenge['client'], 'expires_at' => $challenge['expires_at'],
    ), $remaining);
    $challenge['hash'] = '';
    $challenge['stage'] = 'done';
    set_transient('tk_captcha_' . $token, $challenge, $remaining);
    wp_send_json_success(array('answer' => $proof));
}

function tk_captcha_refresh_script() {
    if (is_admin() || !tk_get_option('captcha_enabled')) { return; }
    // Only pages that render a CAPTCHA, or load Contact Form 7 (forms in popups or AJAX-inserted markup bring
    // its script), need captcha.js. The login screen always renders one.
    $needed = !empty($GLOBALS['tk_captcha_rendered']) || doing_action('login_footer')
        || wp_script_is('contact-form-7', 'enqueued') || wp_script_is('contact-form-7', 'done');
    if (!apply_filters('tk_captcha_enqueue_script', $needed)) { return; }
    static $printed = false;
    if ($printed) { return; }
    $printed = true;
    wp_enqueue_script('tk-captcha', TK_URL . 'assets/js/captcha.js', array(), tk_asset_version('assets/js/captcha.js'), true);
    wp_localize_script('tk-captcha', 'tkCaptcha', array(
        'refreshUrl' => home_url('/?tk_captcha_action=refresh'),
        'verifyUrl' => home_url('/?tk_captcha_action=verify_click'),
        'refreshNonce' => wp_create_nonce('tk_captcha_refresh'),
        'verifyNonce' => wp_create_nonce('tk_captcha_verify_click'),
        'minSeconds' => tk_captcha_min_seconds(),
        'loading' => tk_captcha_text('loading'), 'required' => tk_captcha_text('required'),
        'incorrect' => tk_captcha_text('incorrect'), 'refreshFailed' => tk_captcha_text('refresh_failed'),
        'tooFast' => tk_captcha_text('too_fast'), 'rateLimited' => tk_captcha_text('rate_limited'),
        'verifyFailed' => tk_captcha_text('verify_failed'),
    ));
}

function tk_captcha_validate($user) {
    if (!tk_get_option('captcha_enabled') || !tk_get_option('captcha_on_login')) {
        return $user;
    }
    if (!isset($_POST['log'], $_POST['pwd'])) {
        return $user;
    }
    $validation = tk_captcha_validate_request();
    if (!$validation['present']) {
        return new WP_Error('captcha_missing', tk_captcha_text('required'));
    }
    if (!$validation['valid']) {
        return new WP_Error('captcha_invalid', tk_captcha_text('incorrect'));
    }
    return $user;
}

/**
 * $expected lists the challenge types this form accepts. The refresh endpoint lets the browser choose a
 * type, so without it a bot could swap a Classic image for the checkbox flow and never read the image.
 */
function tk_captcha_validate_request(array $expected = array()): array {
    $expected = $expected ? array_unique(array_map('tk_captcha_normalize_type', $expected)) : array(tk_captcha_current_type());
    $names = tk_captcha_field_names();
    $token = tk_captcha_scalar($_POST, $names['token']);
    $answer = tk_captcha_scalar($_POST, $names['answer']);
    if ($token === '' && $answer === '') { return array('present' => false, 'valid' => false); }
    $invalid = array('present' => true, 'valid' => false);
    if (!preg_match('/^[a-f0-9]{48}$/D', $token) || $answer === '' || strlen($answer) > 128
        || tk_captcha_scalar($_POST, 'tk_robot_honeypot') !== '' || is_array($_POST['tk_robot_honeypot'] ?? null)
        || tk_captcha_rate_limited('submit')) { return $invalid; }
    $challenge = get_transient('tk_captcha_' . $token);
    if (!tk_captcha_challenge_valid($challenge)) { return $invalid; }
    if (!in_array($challenge['type'] ?? '', $expected, true)) { return $invalid; }
    if (time() - $challenge['issued_at'] < tk_captcha_min_seconds()) { return $invalid; }
    $verified = get_transient('tk_captcha_verified_' . $token);
    if (!tk_captcha_claim($token, 'submit')) { return $invalid; }
    delete_transient('tk_captcha_' . $token);
    delete_transient('tk_captcha_verified_' . $token);
    if ($challenge['type'] === 'checkbox') {
        $valid = is_array($verified) && !empty($verified['hash'])
            && ($verified['expires_at'] ?? 0) > time()
            && hash_equals((string) ($verified['client'] ?? ''), tk_captcha_client())
            && hash_equals($verified['hash'], hash('sha256', $answer));
    } else {
        $valid = !empty($challenge['hash']) && hash_equals($challenge['hash'], tk_captcha_answer_hash($answer, $token));
    }
    if (!$valid) { tk_captcha_record_failure(); }
    return array('present' => true, 'valid' => (bool) $valid);
}

function tk_captcha_cf7_required($tags): bool {
    foreach ((array) $tags as $tag) {
        $type = is_object($tag) ? ($tag->basetype ?? $tag->type ?? '') : ($tag['basetype'] ?? $tag['type'] ?? '');
        if ($type === 'toolkits_captcha') { return true; }
    }
    if (function_exists('wpcf7_get_current_contact_form')) {
        $form = wpcf7_get_current_contact_form();
        if ($form && preg_match('/\[toolkits_captcha(?:\s|\])/', (string) $form->prop('form'))) { return true; }
    }
    return false;
}

/** Types configured on the form itself (server side), never the type the browser asked for. */
function tk_captcha_cf7_types($tags): array {
    $types = array();
    foreach ((array) $tags as $tag) {
        $type = is_object($tag) ? ($tag->basetype ?? $tag->type ?? '') : ($tag['basetype'] ?? $tag['type'] ?? '');
        if ($type === 'toolkits_captcha') { $types[] = tk_captcha_type_from_wpcf7_tag($tag); }
    }
    if (!$types && function_exists('wpcf7_get_current_contact_form')) {
        $form = wpcf7_get_current_contact_form();
        if ($form && preg_match_all('/\[toolkits_captcha(?:\s+([^\]]*))?\]/', (string) $form->prop('form'), $matches)) {
            foreach ($matches[1] as $options) {
                $types[] = tk_captcha_type_from_wpcf7_tag(array('options' => preg_split('/\s+/', trim($options), -1, PREG_SPLIT_NO_EMPTY)));
            }
        }
    }
    return $types;
}

function tk_captcha_validate_cf7($result, $tags) {
    $GLOBALS['tk_captcha_cf7_error'] = '';
    if (!tk_get_option('captcha_enabled') || !function_exists('wpcf7')) { return $result; }
    $required = tk_captcha_cf7_required($tags);
    if (!$required && !isset($_POST['tk_captcha_token'], $_POST['tk_captcha_answer'])) { return $result; }
    $validation = tk_captcha_validate_request(tk_captcha_cf7_types($tags));
    if ($validation['valid']) { return $result; }
    $message = tk_captcha_text($validation['present'] ? 'incorrect' : 'required');
    $GLOBALS['tk_captcha_cf7_error'] = $message;
    // CF7 silently ignores invalidate(null, ...). Always target a real, named server-side tag.
    foreach ((array) $tags as $tag) {
        $name = is_object($tag) ? ($tag->name ?? '') : ($tag['name'] ?? '');
        if ($name !== '' && wpcf7_is_name($name)) { $result->invalidate($tag, $message); break; }
    }
    return $result;
}

function tk_captcha_cf7_spam($spam, $submission = null) {
    return $spam || !empty($GLOBALS['tk_captcha_cf7_error']);
}

function tk_captcha_cf7_response($response, $result) {
    if (is_array($response) && !empty($GLOBALS['tk_captcha_cf7_error'])) {
        $response['status'] = 'validation_failed';
        $response['message'] = $GLOBALS['tk_captcha_cf7_error'];
        $response['tool_kits_captcha_error'] = true;
    }
    return $response;
}

function tk_render_captcha_page() {
    if (function_exists('tk_render_spam_protection_page')) {
        tk_render_spam_protection_page('captcha');
        return;
    }
    if (!tk_is_admin_user()) return;
    ?>
    <div class="wrap tk-wrap">
        <h1>Spam Protection</h1>
        <?php tk_render_captcha_panel(); ?>
    </div>
    <?php
}

function tk_render_captcha_panel() {
    if (!tk_is_admin_user()) return;

    $enabled = (int) tk_get_option('captcha_enabled', 0);
    $on_login = (int) tk_get_option('captcha_on_login', 1);
    $on_comments = (int) tk_get_option('captcha_on_comments', 0);
    $length = tk_captcha_length();
    $strength = tk_captcha_strength();
    $min_seconds = tk_captcha_min_seconds();
    $max_attempts = max(5, min(100, (int) tk_get_option('captcha_max_attempts', 20)));
    $pow_bits = tk_captcha_pow_bits();
    $adaptive = tk_captcha_adaptive();

    ?>
    <div class="tk-card">
        <h2>Captcha</h2>
        <p>Protect login screens or any other form with a lightweight random challenge. Use <code>[toolkits_captcha]</code>, <code>[toolkits_captcha robot="1"]</code>, or <code>[toolkits_captcha clasic="1"]</code> in contact forms or custom blocks.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_captcha_save'); ?>
            <input type="hidden" name="action" value="tk_captcha_save">
            <input type="hidden" name="tk_tab" value="captcha">

            <div style="display:flex; flex-direction:column; gap:20px; margin-bottom:24px;">
                <?php 
                tk_render_switch('enabled', 'Enable Captcha Module', 'Activate the global captcha system for all forms.', $enabled);
                tk_render_switch('on_login', 'Protect Login Form', 'Display a captcha on the WordPress login, lost password and registration forms.', $on_login);
                ?>
            </div>
            
            <div style="background:var(--tk-bg-soft); padding:24px; border-radius:16px; border:1px solid var(--tk-border-soft); margin-bottom:24px;">
                <label style="display:block; font-weight:700; margin-bottom:16px; font-size:14px; color:var(--tk-primary);">Verification Method</label>
                <?php $captcha_type = tk_captcha_current_type(); ?>
                <div class="tk-choice-grid">
                    <label class="tk-radio-card" style="flex:1; cursor:pointer; padding:16px; background:#fff; border:1px solid <?php echo $captcha_type === 'text' ? 'var(--tk-primary)' : 'var(--tk-border-soft)'; ?>; border-radius:12px;">
                        <input type="radio" name="captcha_type" value="text" <?php checked('text', $captcha_type); ?> style="margin-right:8px;">
                        <strong>Classic Text</strong>
                        <p class="description" style="margin:4px 0 0 25px;">Users type a random code shown in an image/box.</p>
                    </label>
                    <label class="tk-radio-card" style="flex:1; cursor:pointer; padding:16px; background:#fff; border:1px solid <?php echo $captcha_type === 'checkbox' ? 'var(--tk-primary)' : 'var(--tk-border-soft)'; ?>; border-radius:12px;">
                        <input type="radio" name="captcha_type" value="checkbox" <?php checked('checkbox', $captcha_type); ?> style="margin-right:8px;">
                        <strong>"I'm not a robot"</strong>
                        <p class="description" style="margin:4px 0 0 25px;">Single checkbox with automatic server-side session, timing, expiry and token checks.</p>
                    </label>
                </div>

                <div id="tk-captcha-text-settings" style="<?php echo $captcha_type === 'checkbox' ? 'display:none;' : ''; ?> margin-top:24px; padding-top:24px; border-top:1px solid var(--tk-border-soft);">
                    <div class="tk-responsive-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                        <div>
                            <label style="display:block; font-weight:600; margin-bottom:8px;">Code Length</label>
                            <input class="widefat" type="number" min="6" max="10" name="length" value="<?php echo esc_attr($length); ?>" style="border-radius:8px;">
                            <p class="description">Classic Text image code length (6-10).</p>
                        </div>
                        <div>
                            <label style="display:block; font-weight:600; margin-bottom:8px;">Complexity Level</label>
                            <select name="strength" class="widefat" style="border-radius:8px; height:40px;">
                                <option value="easy" <?php selected($strength, 'easy'); ?>>Easy (Letters)</option>
                                <option value="medium" <?php selected($strength, 'medium'); ?>>Medium (Letters + Digits)</option>
                                <option value="hard" <?php selected($strength, 'hard'); ?>>Hard (Letters + Digits + Symbols)</option>
                            </select>
                            <p class="description">Characters used to build the captcha code.</p>
                        </div>
                    </div>
                </div>
                <div class="tk-responsive-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px;">
                    <div><label for="tk-captcha-min-seconds">Minimum Solve Time (seconds)</label>
                        <input id="tk-captcha-min-seconds" class="widefat" type="number" min="1" max="10" name="min_seconds" value="<?php echo esc_attr($min_seconds); ?>">
                        <p class="description">Enforced by the server, independently of browser timing.</p></div>
                    <div><label for="tk-captcha-max-attempts">Maximum Verification Attempts / 5 Minutes</label>
                        <input id="tk-captcha-max-attempts" class="widefat" type="number" min="5" max="100" name="max_attempts" value="<?php echo esc_attr($max_attempts); ?>">
                        <p class="description">Limits repeated guesses and verification requests from one visitor.</p></div>
                    <div><label for="tk-captcha-pow-bits">"I'm not a robot" Proof-of-Work Difficulty</label>
                        <input id="tk-captcha-pow-bits" class="widefat" type="number" min="14" max="22" name="pow_bits" value="<?php echo esc_attr($pow_bits); ?>">
                        <p class="description">Leading zero bits (14-22). Each +1 doubles the browser work; 18 takes well under a second on a phone.</p></div>
                    <div><label for="tk-captcha-adaptive">"I'm not a robot" Adaptive Difficulty</label>
                        <select id="tk-captcha-adaptive" class="widefat" name="adaptive_pow">
                            <option value="1" <?php selected($adaptive, true); ?>>On (suspicious visitors get up to 64x more work)</option>
                            <option value="0" <?php selected($adaptive, false); ?>>Off (same work for everyone)</option>
                        </select>
                        <p class="description">Automation user agents, missing browser headers, scripted clicks and repeated failures make the checkbox take longer. Visitors never see an image.</p></div>
                </div>
            </div>
            
            <script<?php echo tk_csp_nonce_attr(); ?>>
                document.querySelectorAll('input[name="captcha_type"]').forEach(function(radio){
                    radio.addEventListener('change', function(){
                        document.getElementById('tk-captcha-text-settings').style.display = this.value === 'checkbox' ? 'none' : 'block';
                        document.querySelectorAll('.tk-radio-card').forEach(function(card){
                           card.style.borderColor = 'var(--tk-border-soft)';
                        });
                        this.closest('.tk-radio-card').style.borderColor = 'var(--tk-primary)';
                    });
                });
            </script>

            <p class="description">Set the difficulty depending on how aggressive you want the bot protection to be. The shortcode renders the same block anywhere else you need it.</p>

            <p><button class="button button-primary">Save Settings</button></p>
        </form>
    </div>
    <?php
}

function tk_captcha_save() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    tk_check_nonce('tk_captcha_save');

    tk_update_option('captcha_enabled', !empty($_POST['enabled']) ? 1 : 0);
    tk_update_option('captcha_on_login', !empty($_POST['on_login']) ? 1 : 0);
    tk_update_option('captcha_on_comments', !empty($_POST['on_comments']) ? 1 : 0);
    if (isset($_POST['captcha_type'])) {
        tk_update_option('captcha_type', tk_captcha_normalize_type(tk_captcha_scalar($_POST, 'captcha_type')));
    }
    $length = isset($_POST['length']) ? max(6, min(10, (int) $_POST['length'])) : tk_captcha_length();
    $strength = isset($_POST['strength']) ? sanitize_key($_POST['strength']) : tk_captcha_strength();
    if (!in_array($strength, array('easy','medium','hard'), true)) {
        $strength = 'medium';
    }
    tk_update_option('captcha_length', $length);
    tk_update_option('captcha_strength', $strength);
    tk_update_option('captcha_min_seconds', max(1, min(10, (int) tk_post('min_seconds', 2))));
    tk_update_option('captcha_max_attempts', max(5, min(100, (int) tk_post('max_attempts', 20))));
    tk_update_option('captcha_pow_bits', max(14, min(22, (int) tk_post('pow_bits', 18))));
    tk_update_option('captcha_adaptive_pow', (string) tk_post('adaptive_pow', '1') === '0' ? 0 : 1);

    wp_redirect(add_query_arg(array('page'=>'tool-kits-security-spam','tk_tab'=>'captcha','tk_saved'=>1), admin_url('admin.php')));
    exit;
}
