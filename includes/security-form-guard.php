<?php
if (!defined('ABSPATH')) { exit; }

function tk_form_guard_init() {
    add_action('init', 'tk_form_guard_bootstrap', 1);
    add_action('comment_form_after_fields', 'tk_form_guard_render_comment_fields');
    add_action('comment_form_logged_in_after', 'tk_form_guard_render_comment_fields');
    add_filter('preprocess_comment', 'tk_form_guard_validate_comment', 5);
}

function tk_form_guard_enabled(): bool {
    return (int) tk_get_option('form_guard_enabled', 1) === 1;
}

function tk_form_guard_post_rate_key(string $context): string {
    return 'tk_form_guard_rl_' . md5($context . '|' . tk_get_ip());
}

function tk_form_guard_request_method(): string {
    return isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
}

function tk_form_guard_request_uri(): string {
    return isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
}

function tk_form_guard_request_context(): string {
    if (isset($_POST['comment_post_ID'], $_POST['comment'])) {
        return 'comment';
    }

    if (isset($_POST['log'], $_POST['pwd'])) {
        return 'login';
    }

    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return 'ajax';
    }

    if (defined('REST_REQUEST') && REST_REQUEST) {
        return 'rest';
    }

    return 'frontend';
}

function tk_form_guard_is_public_post(): bool {
    if (tk_form_guard_request_method() !== 'POST') {
        return false;
    }

    if (defined('WP_CLI') && WP_CLI) {
        return false;
    }

    $context = tk_form_guard_request_context();
    if (in_array($context, array('login', 'comment', 'frontend', 'ajax'), true)) {
        return true;
    }

    return false;
}

function tk_form_guard_user_agent_is_suspicious(string $ua): bool {
    $ua = strtolower(trim($ua));
    if ($ua === '') {
        return (int) tk_get_option('form_guard_block_empty_ua', 1) === 1;
    }

    foreach (tk_antispam_line_list((string) tk_get_option('form_guard_blocked_user_agents', "curl\nwget\npython-requests\npython-urllib\nlibwww-perl\nscrapy\nhttpclient\ngo-http-client\njava/\nokhttp\npowershell\npostmanruntime")) as $needle) {
        $needle = strtolower(trim($needle));
        if ($needle !== '' && strpos($ua, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function tk_form_guard_rate_limit_exceeded(string $context): bool {
    $window = max(1, (int) tk_get_option('form_guard_post_window_minutes', 10));
    $max = max(1, (int) tk_get_option('form_guard_post_max_attempts', 8));
    $key = tk_form_guard_post_rate_key($context);
    $data = get_transient($key);

    if (!is_array($data)) {
        $data = array('count' => 0);
    }

    $data['count'] = isset($data['count']) ? (int) $data['count'] + 1 : 1;
    set_transient($key, $data, $window * MINUTE_IN_SECONDS);

    return $data['count'] > $max;
}

function tk_form_guard_scalar_post_values(): array {
    $values = array();
    foreach ($_POST as $key => $value) {
        if (!is_scalar($value)) {
            continue;
        }
        $name = sanitize_key((string) $key);
        if ($name === '' || strpos($name, '_wp') === 0) {
            continue;
        }
        if (in_array($name, array('_tk_nonce', 'tk_hp_field', 'tk_form_ts', 'tk_comment_hp', 'tk_comment_ts', 'tk_captcha_token', 'tk_captcha_answer'), true)) {
            continue;
        }
        $values[$name] = trim((string) wp_unslash($value));
    }

    return $values;
}

function tk_form_guard_deny(string $reason, int $status = 403): void {
    tk_log('Blocked public form request: ' . $reason . ' | IP: ' . tk_get_ip() . ' | UA: ' . tk_user_agent());
    nocache_headers();
    status_header($status);
    wp_die(__('Request blocked by security policy.', 'tool-kits'), __('Access denied', 'tool-kits'), array('response' => $status));
}

function tk_form_guard_bootstrap(): void {
    if (!tk_form_guard_enabled() || !tk_form_guard_is_public_post()) {
        return;
    }

    if (is_user_logged_in() && current_user_can('manage_options')) {
        return;
    }

    $context = tk_form_guard_request_context();
    $ua = tk_user_agent();

    if (tk_form_guard_user_agent_is_suspicious($ua)) {
        tk_form_guard_deny('suspicious_user_agent:' . ($ua !== '' ? $ua : 'empty'));
    }

    if (tk_rate_limit_is_blocked(tk_get_ip())) {
        tk_form_guard_deny('ip_blocked', 429);
    }

    if (tk_form_guard_rate_limit_exceeded($context)) {
        tk_form_guard_deny('public_post_rate_limit:' . $context, 429);
    }

    if (in_array($context, array('frontend', 'ajax'), true) && function_exists('tk_antispam_detect_random_submission_reason')) {
        $values = tk_form_guard_scalar_post_values();
        if (!empty($values)) {
            $reason = tk_antispam_detect_random_submission_reason($values);
            if ($reason !== '') {
                tk_form_guard_deny($reason);
            }
        }
    }
}

function tk_form_guard_render_comment_fields(): void {
    if (!tk_form_guard_enabled()) {
        return;
    }

    $ts = time();
    set_transient('tk_comment_form_' . md5(tk_get_ip() . '|' . tk_user_agent()), $ts, 30 * MINUTE_IN_SECONDS);

    echo '<p class="comment-form-tk-hp" style="position:absolute;left:-9999px;top:-9999px;height:1px;overflow:hidden;" aria-hidden="true">';
    echo '<label>' . esc_html__('Leave this field empty', 'tool-kits') . '<input type="text" name="tk_comment_hp" value="" tabindex="-1" autocomplete="off"></label>';
    echo '</p>';
    echo '<input type="hidden" name="tk_comment_ts" value="' . esc_attr((string) $ts) . '">';

    if (tk_get_option('captcha_enabled') && tk_get_option('captcha_on_comments')) {
        echo tk_captcha_render_markup();
    }
}

function tk_form_guard_comment_key(): string {
    return 'tk_comment_form_' . md5(tk_get_ip() . '|' . tk_user_agent());
}

function tk_form_guard_validate_comment(array $commentdata): array {
    if (!tk_form_guard_enabled()) {
        return $commentdata;
    }

    $hp = isset($_POST['tk_comment_hp']) ? trim((string) wp_unslash($_POST['tk_comment_hp'])) : '';
    if ($hp !== '') {
        tk_form_guard_deny('comment_honeypot');
    }

    $posted = isset($_POST['tk_comment_ts']) ? (int) $_POST['tk_comment_ts'] : 0;
    $stored = (int) get_transient(tk_form_guard_comment_key());
    if ($posted > 0 && $stored > 0) {
        $elapsed = time() - $stored;
        $min = max(0, (int) tk_get_option('form_guard_comment_min_seconds', 4));
        if ($elapsed < $min) {
            tk_form_guard_deny('comment_submitted_too_quickly');
        }
    }

    if (tk_get_option('captcha_enabled') && tk_get_option('captcha_on_comments')) {
        $validation = tk_captcha_validate_request();
        if (!$validation['present']) {
            tk_form_guard_deny('comment_captcha_missing');
        }
        if (!$validation['valid']) {
            tk_form_guard_deny('comment_captcha_invalid');
        }
    }

    return $commentdata;
}
