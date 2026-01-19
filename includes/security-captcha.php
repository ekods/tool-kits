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
    add_action('wp_footer', 'tk_captcha_refresh_script');
    add_action('wp_ajax_tk_captcha_refresh', 'tk_captcha_refresh_ajax');
    add_action('wp_ajax_nopriv_tk_captcha_refresh', 'tk_captcha_refresh_ajax');
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
    $code = tk_captcha_make_code();
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
<style>
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
    min-width:160px;
    display:flex;
    flex-wrap:wrap;
    gap:4px;
    align-items:center;
    padding:10px 14px;
    border-radius:8px 14px;
    border:1px solid #eee;a
    text-transform:none;
    font-size:32px;
    font-weight:700;
  }
  .tk-captcha-input{
    flex:1;
    min-width:210px;
    padding:12px 16px;
    border-radius:10px;
    border:1px solid rgba(148,163,184,0.4);
    background:#030712;
    color:#f8fafc;
    font-weight:600;
    outline:none;
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
</style>';
    }

    $html  = $css;
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
    check_ajax_referer('tk_captcha_refresh', 'nonce');
    if (!tk_get_option('captcha_enabled')) {
        wp_send_json_error('disabled');
    }
    wp_send_json_success(array('markup' => tk_captcha_render_markup()));
}

function tk_captcha_refresh_script() {
    if (is_admin() || !tk_get_option('captcha_enabled')) {
        return;
    }
    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('tk_captcha_refresh');
    ?>
    <script>
    (function(){
        var ajaxUrl = '<?php echo esc_js($ajax_url); ?>';
        var nonce = '<?php echo esc_js($nonce); ?>';
        document.addEventListener('click', function(e){
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
            var originalLabel = btn.getAttribute('data-tk-label');
            if (!originalLabel) {
                originalLabel = btn.innerHTML;
                btn.setAttribute('data-tk-label', originalLabel);
            }
            btn.innerHTML = 'Refreshing…';
            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'tk_captcha_refresh',
                    nonce: nonce
                })
            }).then(function(resp){ return resp.json(); }).then(function(data){
                if (data.success && data.data.markup) {
                    field.outerHTML = data.data.markup;
                }
            }).finally(function(){
                btn.disabled = false;
                if (originalLabel) {
                    btn.innerHTML = originalLabel;
                }
            });
        });
    })();
    </script>
    <?php
}

function tk_captcha_validate($user) {
    if (!tk_get_option('captcha_enabled') || !tk_get_option('captcha_on_login')) {
        return $user;
    }
    $names = tk_captcha_field_names();
    $token = isset($_POST[$names['token']]) ? sanitize_text_field($_POST[$names['token']]) : '';
    $answer = isset($_POST[$names['answer']]) ? wp_unslash($_POST[$names['answer']]) : '';
    if ($token === '' || $answer === '') {
        return new WP_Error('captcha_missing', 'Captcha is required.');
    }
    $hash = get_transient('tk_captcha_' . $token);
    delete_transient('tk_captcha_' . $token);
    if (!$hash || !wp_check_password(trim($answer), $hash)) {
        return new WP_Error('captcha_invalid', 'Captcha incorrect.');
    }
    return $user;
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

            <label><input type="checkbox" name="enabled" value="1" <?php checked(1, $enabled); ?>> Enable captcha module</label>
            <p><label><input type="checkbox" name="on_login" value="1" <?php checked(1, $on_login); ?>> Require captcha on login form</label></p>
            <p>
                <label><strong>Captcha length</strong></label><br>
                <input class="small-text" type="number" min="3" max="10" name="length" value="<?php echo esc_attr($length); ?>">
                characters (higher values increase randomness)
            </p>
            <p>
                <label><strong>Difficulty</strong></label><br>
                <select name="strength">
                    <option value="easy" <?php selected($strength, 'easy'); ?>>Easy (lowercase letters only)</option>
                    <option value="medium" <?php selected($strength, 'medium'); ?>>Medium (letters + digits)</option>
                    <option value="hard" <?php selected($strength, 'hard'); ?>>Hard (letters + digits + symbols)</option>
                </select>
            </p>
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
