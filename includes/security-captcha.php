<?php
if (!defined('ABSPATH')) exit;

/**
 * Random string captcha
 */

function tk_captcha_init() {
    add_action('login_form', 'tk_captcha_render_field');
    add_filter('authenticate', 'tk_captcha_validate', 40, 3);
    add_action('admin_post_tk_captcha_save', 'tk_captcha_save');
    add_shortcode('toolkits_captcha', 'tk_captcha_shortcode');
    add_action('wpcf7_init', 'tk_captcha_register_wpcf7_tag');
    add_filter('wpcf7_validate', 'tk_captcha_validate_cf7', 30, 2);
    add_action('wp_footer', 'tk_captcha_refresh_script');
    add_action('login_footer', 'tk_captcha_refresh_script');
    
    // Custom AJAX handler to bypass admin-ajax.php blocks
    add_action('template_redirect', 'tk_captcha_custom_ajax_handler');
    add_action('init', 'tk_captcha_custom_ajax_handler_init');
}

function tk_captcha_custom_ajax_handler_init() {
    if (isset($_GET['tk_captcha_action'])) {
        if ($_GET['tk_captcha_action'] === 'refresh') {
            tk_captcha_refresh_ajax();
        } else {
            tk_captcha_verify_click_ajax();
        }
        exit;
    }
}

function tk_captcha_custom_ajax_handler() {
    if (isset($_GET['tk_captcha_action'])) {
        if ($_GET['tk_captcha_action'] === 'refresh') {
            tk_captcha_refresh_ajax();
        } else {
            tk_captcha_verify_click_ajax();
        }
        exit;
    }
}

function tk_captcha_length(): int {
    return max(3, min(10, (int) tk_get_option('captcha_length', 5)));
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
    return $out;
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

function tk_captcha_create_challenge(): array {
    $type = tk_get_option('captcha_type', 'text');
    $code = tk_captcha_make_code();
    if ($type === 'checkbox') {
        $code = wp_generate_password(12, false, false);
    }
    $token = wp_generate_password(18, false, false) . '_' . rand(1000, 9999);
    $hash = wp_hash_password($code);
    set_transient('tk_captcha_' . $token, $hash, MINUTE_IN_SECONDS * 5);
    return ['code' => $code, 'token' => $token];
}

function tk_captcha_render_field() {
    if (!tk_get_option('captcha_enabled') || !tk_get_option('captcha_on_login')) {
        return;
    }
    echo tk_captcha_render_markup();
}

function tk_captcha_render_markup(): string {
    $challenge = tk_captcha_create_challenge();
    $names = tk_captcha_field_names();

    $gradient = tk_captcha_random_gradient();
    $shadow = tk_captcha_random_text_shadow();
    $bgNoise = 'radial-gradient(circle at 25px 15px, rgba(255,255,255,0.12), transparent 47%), radial-gradient(circle at 80px 45px, rgba(255,255,255,0.06), transparent 40%)';
    $noiseSvg = "url('data:image/svg+xml;utf8,<svg xmlns=\\'http://www.w3.org/2000/svg\\' width=\\'140\\' height=\\'60\\'><line x1=\\'0\\' y1=\\'30\\' x2=\\'140\\' y2=\\'30\\' stroke=\\'rgba(255,255,255,0.05)\\' stroke-width=\\'1\\'/><circle cx=\\'20\\' cy=\\'15\\' r=\\'1\\' fill=\\'rgba(255,255,255,0.3)\\'/><circle cx=\\'60\\' cy=\\'10\\' r=\\'2\\' fill=\\'rgba(255,255,255,0.25)\\'/></svg>')";

    $chars = str_split($challenge['code']);
    $charSpans = '';
    foreach ($chars as $char) {
        $charSpans .= tk_captcha_span_for_char($char, ['shadow' => $shadow]);
    }

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
    padding:14px;
  }
  .tk-captcha-codebox{
    width: 100%;
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
    flex:1;
    width: 100%;
    padding:12px 16px;
    border-radius:10px;
    border:1px solid rgba(148,163,184,0.4);
    background:#030712;
    color:#f8fafc;
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

    $type = tk_get_option('captcha_type', 'text');
    $html = $css !== '' ? '<style' . tk_csp_nonce_attr() . '>' . $css . '</style>' : '';
    
    if ($type === 'checkbox') {
        $html .= '<div class="tk-captcha-field" data-type="checkbox">';
        $html .= '<div class="tk-captcha-checkbox-wrap">';
        $html .= '  <div class="tk-captcha-checkbox-left">';
        $html .= '    <div class="tk-captcha-checkbox-box" role="checkbox" aria-checked="false" tabindex="0">
          <svg class="tk-captcha-checkmark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"></polyline>
          </svg>
        </div>';
        $html .= '    <span class="tk-captcha-checkbox-text">I\'m not a robot</span>';
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
        $html .= '<div class="tk-robot-honeypot-wrap"><input type="text" name="tk_robot_honeypot" value="" tabindex="-1" autocomplete="off"></div>';
        $html .= '</div>';
    } else {
        $html .= '<div class="tk-captcha-field">';
        $html .= '<label class="tk-captcha-label">Enter the code below</label>';
        $html .= '<div class="tk-captcha-panel">';
        $html .= '<div class="tk-captcha-code-wrapper">';
        $html .= '<div class="tk-captcha-codebox">';
        $html .= $charSpans;
        $html .= '</div>';
        $html .= '<button type="button" class="tk-captcha-refresh" aria-label="Refresh captcha">
            <svg class="tk-captcha-refresh-icon" viewBox="0 0 24 24" fill="none">
                <path d="M4 12a8 8 0 0 1 13.66-5.66L20 4v6h-6l2.22-2.22A6 6 0 1 0 18 12"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"/>
            </svg>
            </button>';
        $html .= '</div>'; // code wrapper
        $html .= '<input type="text"
            class="tk-captcha-input"
            name="' . esc_attr($names['answer']) . '"
            autocomplete="off"
            placeholder="Type the captcha"
            aria-label="Captcha code">';
        $html .= '</div>'; // panel
        $html .= '<div class="tk-captcha-help">';
        $html .= '<span>Typing the code exactly, including case, is required.</span>';
        $html .= '<small>Codes expire in 5 minutes to keep brute-force attackers at bay.</small>';
        $html .= '</div>';
        $html .= '<input type="hidden" name="' . esc_attr($names['token']) . '" value="' . esc_attr($challenge['token']) . '">';
        $html .= '</div>';
    }

    return $html;
}




function tk_captcha_shortcode($atts) {
    if (!tk_get_option('captcha_enabled')) {
        return '';
    }
    return tk_captcha_render_markup();
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
    return tk_captcha_render_markup();
}

function tk_captcha_refresh_ajax() {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'tk_captcha_refresh')) {
        wp_send_json_error('invalid_nonce');
    }
    
    if (!tk_get_option('captcha_enabled')) {
        wp_send_json_error('disabled');
    }
    wp_send_json_success(array('markup' => tk_captcha_render_markup()));
}

function tk_captcha_verify_click_ajax() {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'tk_captcha_verify_click')) {
        wp_send_json_error('invalid_nonce');
    }
    
    if (!tk_get_option('captcha_enabled')) {
        wp_send_json_error('disabled');
    }

    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
    if ($token === '') {
        wp_send_json_error('missing_token');
    }
    $hash = get_transient('tk_captcha_' . $token);
    if (!$hash) {
        wp_send_json_error('expired');
    }
    // Record that this token was verified by a real click
    set_transient('tk_captcha_verified_' . $token, 1, MINUTE_IN_SECONDS * 5);
    
    // Return a temporary "solve" value that PHP can check later.
    // For simplicity, we just use the original code but only return it here.
    // In a real scenario, we could use a different salt.
    wp_send_json_success(array('answer' => 'VERIFIED_CLICK'));
}

function tk_captcha_refresh_script() {
    if (is_admin() || !tk_get_option('captcha_enabled')) {
        return;
    }
    static $printed = false;
    if ($printed) {
        return;
    }
    $printed = true;

    $ajax_url = home_url('/?tk_captcha_action=verify');
    $nonce_refresh = wp_create_nonce('tk_captcha_refresh');
    $nonce_verify = wp_create_nonce('tk_captcha_verify_click');
    
    tk_csp_print_inline_script(
        "(function(){
            var ajaxUrl = '" . esc_js($ajax_url) . "';
            var nonceRefresh = '" . esc_js($nonce_refresh) . "';
            var nonceVerify = '" . esc_js($nonce_verify) . "';
            
            var interactions = 0;
            var startTime = Date.now();
            
            function trackInteraction() { interactions++; }
            window.addEventListener('mousemove', trackInteraction, {once: true});
            window.addEventListener('touchstart', trackInteraction, {once: true});
            window.addEventListener('keydown', trackInteraction, {once: true});

            if (window.tkCaptchaRefreshBound) return;
            window.tkCaptchaRefreshBound = true;

            document.addEventListener('click', function(e){
                var box = e.target.closest('.tk-captcha-checkbox-box');
                var text = e.target.closest('.tk-captcha-checkbox-text');
                
                if (box || text) {
                    var field = e.target.closest('.tk-captcha-field');
                    var realBox = field.querySelector('.tk-captcha-checkbox-box');
                    if (realBox.classList.contains('is-checked') || realBox.classList.contains('is-loading')) return;
                    
                    var tokenInput = field.querySelector('input[name=\"tk_captcha_token\"]');
                    if (!tokenInput) return;

                    // Strong validation: Check if time on page is too low (< 500ms) or no interaction
                    if (Date.now() - startTime < 500 && interactions === 0) {
                        console.warn('ToolKits: Bot detected (fast click / no interaction)');
                        // Still allow but we can flag it or just be more strict later
                    }

                    realBox.classList.add('is-loading');
                    
                    fetch(ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({
                            action: 'tk_captcha_verify_click',
                            nonce: nonceVerify,
                            token: tokenInput.value,
                            interact: interactions,
                            timing: Date.now() - startTime
                        })
                    }).then(function(resp){ 
                        return resp.json(); 
                    }).then(function(data){
                        if (data.success && data.data.answer) {
                            setTimeout(function(){
                                realBox.classList.remove('is-loading');
                                realBox.classList.add('is-checked');
                                realBox.setAttribute('aria-checked', 'true');
                                var ans = field.querySelector('.tk-captcha-answer-field');
                                if (ans) ans.value = data.data.answer;
                            }, 600); // Artificial delay for premium feel
                        } else {
                            throw new Error(data.data || 'Verification failed');
                        }
                    }).catch(function(err){
                        realBox.classList.remove('is-loading');
                        alert('Verification failed. Please try again.');
                    });
                    return;
                }

                var btn = e.target.closest('.tk-captcha-refresh');
                if (!btn) {
                    return;
                }
                e.preventDefault();
                var field = btn.closest('.tk-captcha-field');
                if (!field) {
                    return;
                }
                btn.disabled = true;
                btn.classList.add('is-refreshing');
                btn.setAttribute('aria-busy', 'true');
                var refreshUrl = '" . home_url('/?tk_captcha_action=refresh') . "';
                fetch(refreshUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        nonce: nonceRefresh
                    })
                }).then(function(resp){ 
                    if (!resp.ok) throw new Error('Network response was not ok');
                    return resp.json(); 
                }).then(function(data){
                    if (data.success && data.data.markup) {
                        field.outerHTML = data.data.markup;
                    } else {
                        throw new Error('Invalid response from server');
                    }
                }).catch(function(err){
                    console.error('Captcha Refresh Error:', err);
                    btn.disabled = false;
                    btn.classList.remove('is-refreshing');
                    btn.removeAttribute('aria-busy');
                    alert('Failed to refresh captcha. Please reload the page.');
                }).finally(function(){
                    if (document.body.contains(btn)) {
                        btn.disabled = false;
                        btn.classList.remove('is-refreshing');
                        btn.removeAttribute('aria-busy');
                    }
                });
            });
        })();",
        array('id' => 'tk-captcha-refresh')
    );
}

function tk_captcha_validate($user) {
    if (!tk_get_option('captcha_enabled') || !tk_get_option('captcha_on_login')) {
        return $user;
    }
    $validation = tk_captcha_validate_request();
    if (!$validation['present']) {
        return new WP_Error('captcha_missing', 'Captcha is required.');
    }
    if (!$validation['valid']) {
        return new WP_Error('captcha_invalid', 'Captcha incorrect.');
    }
    return $user;
}

function tk_captcha_validate_request(): array {
    $names = tk_captcha_field_names();
    
    // Honeypot check
    if (!empty($_POST['tk_robot_honeypot'])) {
        return array('present' => true, 'valid' => false);
    }

    $token = isset($_POST[$names['token']]) ? sanitize_text_field(wp_unslash($_POST[$names['token']])) : '';
    $answer = isset($_POST[$names['answer']]) ? trim((string) wp_unslash($_POST[$names['answer']])) : '';

    if ($token === '' && $answer === '') {
        return array('present' => false, 'valid' => false);
    }

    if ($token === '' || $answer === '') {
        return array('present' => true, 'valid' => false);
    }

    if (tk_get_option('captcha_type') === 'checkbox') {
        if ($answer !== 'VERIFIED_CLICK') {
            return array('present' => true, 'valid' => false);
        }
        $verified = get_transient('tk_captcha_verified_' . $token);
        delete_transient('tk_captcha_verified_' . $token);
        delete_transient('tk_captcha_' . $token);
        return array('present' => true, 'valid' => (bool)$verified);
    }

    $hash = get_transient('tk_captcha_' . $token);
    delete_transient('tk_captcha_' . $token);

    return array(
        'present' => true,
        'valid' => (bool) ($hash && wp_check_password($answer, $hash)),
    );
}

function tk_captcha_validate_cf7($result, $tags) {
    if (!tk_get_option('captcha_enabled') || !function_exists('wpcf7')) {
        return $result;
    }

    $validation = tk_captcha_validate_request();
    if (!$validation['present']) {
        return $result;
    }

    if (!$validation['valid']) {
        $result->invalidate(null, __('Captcha incorrect.', 'tool-kits'));
    }

    return $result;
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

    ?>
    <div class="tk-card">
        <h2>Captcha</h2>
        <p>Protect login screens or any other form with a lightweight random challenge. Use <code>[toolkits_captcha]</code> to render the same challenge in contact forms or custom blocks.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_captcha_save'); ?>
            <input type="hidden" name="action" value="tk_captcha_save">
            <input type="hidden" name="tk_tab" value="captcha">

            <div style="display:flex; flex-direction:column; gap:20px; margin-bottom:24px;">
                <?php 
                tk_render_switch('enabled', 'Enable Captcha Module', 'Activate the global captcha system for all forms.', $enabled);
                tk_render_switch('on_login', 'Protect Login Form', 'Display a captcha on the standard WordPress login page.', $on_login);
                ?>
            </div>
            
            <div style="background:var(--tk-bg-soft); padding:24px; border-radius:16px; border:1px solid var(--tk-border-soft); margin-bottom:24px;">
                <label style="display:block; font-weight:700; margin-bottom:16px; font-size:14px; color:var(--tk-primary);">Verification Method</label>
                <?php $captcha_type = tk_get_option('captcha_type', 'text'); ?>
                <div style="display:flex; gap:20px;">
                    <label class="tk-radio-card" style="flex:1; cursor:pointer; padding:16px; background:#fff; border:1px solid <?php echo $captcha_type === 'text' ? 'var(--tk-primary)' : 'var(--tk-border-soft)'; ?>; border-radius:12px;">
                        <input type="radio" name="captcha_type" value="text" <?php checked('text', $captcha_type); ?> style="margin-right:8px;">
                        <strong>Classic Text</strong>
                        <p class="description" style="margin:4px 0 0 25px;">Users type a random code shown in an image/box.</p>
                    </label>
                    <label class="tk-radio-card" style="flex:1; cursor:pointer; padding:16px; background:#fff; border:1px solid <?php echo $captcha_type === 'checkbox' ? 'var(--tk-primary)' : 'var(--tk-border-soft)'; ?>; border-radius:12px;">
                        <input type="radio" name="captcha_type" value="checkbox" <?php checked('checkbox', $captcha_type); ?> style="margin-right:8px;">
                        <strong>"I'm not a robot"</strong>
                        <p class="description" style="margin:4px 0 0 25px;">Simple checkbox verification for better UX.</p>
                    </label>
                </div>

                <div id="tk-captcha-text-settings" style="<?php echo $captcha_type === 'checkbox' ? 'display:none;' : ''; ?> margin-top:24px; padding-top:24px; border-top:1px solid var(--tk-border-soft);">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                        <div>
                            <label style="display:block; font-weight:600; margin-bottom:8px;">Code Length</label>
                            <input class="widefat" type="number" min="3" max="10" name="length" value="<?php echo esc_attr($length); ?>" style="border-radius:8px;">
                            <p class="description">Number of characters to generate (3-10).</p>
                        </div>
                        <div>
                            <label style="display:block; font-weight:600; margin-bottom:8px;">Complexity Level</label>
                            <select name="strength" class="widefat" style="border-radius:8px; height:40px;">
                                <option value="easy" <?php selected($strength, 'easy'); ?>>Easy (Lowercase letters)</option>
                                <option value="medium" <?php selected($strength, 'medium'); ?>>Medium (Letters + Digits)</option>
                                <option value="hard" <?php selected($strength, 'hard'); ?>>Hard (Letters + Digits + Symbols)</option>
                            </select>
                            <p class="description">Characters used to build the captcha code.</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <script<?php echo tk_csp_nonce_attr(); ?>>
                document.querySelectorAll('input[name="captcha_type"]').forEach(function(radio){
                    radio.addEventListener('change', function(){
                        document.getElementById('tk-captcha-text-settings').style.display = (this.value === 'checkbox' ? 'none' : 'block');
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
        tk_update_option('captcha_type', sanitize_key($_POST['captcha_type']));
    }
    $length = isset($_POST['length']) ? max(3, min(10, (int) $_POST['length'])) : tk_captcha_length();
    $strength = isset($_POST['strength']) ? sanitize_key($_POST['strength']) : tk_captcha_strength();
    if (!in_array($strength, array('easy','medium','hard'), true)) {
        $strength = 'medium';
    }
    tk_update_option('captcha_length', $length);
    tk_update_option('captcha_strength', $strength);

    wp_redirect(add_query_arg(array('page'=>'tool-kits-security-spam','tk_tab'=>'captcha','tk_saved'=>1), admin_url('admin.php')));
    exit;
}
