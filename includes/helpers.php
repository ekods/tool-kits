<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Option helpers
 */
function tk_get_option($key, $default = null) {
    $opts = get_option('tk_options', array());
    if (!is_array($opts)) { $opts = array(); }
    return array_key_exists($key, $opts) ? $opts[$key] : $default;
}

function tk_update_option($key, $value) {
    $opts = get_option('tk_options', array());
    if (!is_array($opts)) { $opts = array(); }
    $opts[$key] = $value;
    update_option('tk_options', $opts, false);
}

function tk_option_init_defaults() {
    $defaults = array(
        // DB
        'db_find' => '',
        'db_replace' => '',
        'db_pairs' => array(),
        'db_new_prefix' => '',
        // Hide login
        'hide_login_enabled' => 0,
        'hide_login_slug' => 'secure-login',
        'hide_login_redirect' => home_url('/'),
        // Captcha
        'captcha_enabled' => 1,
        'captcha_on_login' => 1,
        'captcha_on_comments' => 0,
        'captcha_length' => 5,
        'captcha_strength' => 'medium',
        // Antispam contact
        'antispam_cf7_enabled' => 0,
        'antispam_min_seconds' => 3,
        // Rate limit
        'rate_limit_enabled' => 0,
        'rate_limit_window_minutes' => 10,
        'rate_limit_max_attempts' => 5,
        'rate_limit_lockout_minutes' => 30,
        // Login log
        'login_log_enabled' => 1,
        'login_log_keep_days' => 30,
        // Hardening
        'hardening_disable_file_editor' => 1,
        'hardening_disable_xmlrpc' => 1,
        'hardening_disable_rest_user_enum' => 1,
        'hardening_security_headers' => 1,
        'hardening_disable_pingbacks' => 1,
    );

    $opts = get_option('tk_options', array());
    if (!is_array($opts)) { $opts = array(); }
    $merged = array_merge($defaults, $opts);
    update_option('tk_options', $merged, false);
}

/**
 * Admin UI helpers
 */
function tk_admin_url($page) {
    return admin_url('admin.php?page=' . urlencode($page));
}

function tk_is_admin_user() {
    return current_user_can('manage_options');
}

function tk_nonce_field($action) {
    wp_nonce_field($action, '_tk_nonce');
}

function tk_check_nonce($action) {
    if (!isset($_POST['_tk_nonce']) || !wp_verify_nonce($_POST['_tk_nonce'], $action)) {
        wp_die(__('Security check failed.', 'tool-kits'));
    }
}

function tk_notice($message, $type = 'success') {
    $type = in_array($type, array('success','info','warning','error'), true) ? $type : 'success';
    printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), wp_kses_post($message));
}

/**
 * Request helpers
 */
function tk_post($key, $default = '') {
    return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : $default;
}

function tk_sanitize_slug($slug) {
    $slug = sanitize_title($slug);
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));
    return $slug ?: 'secure-login';
}

function tk_get_ip() {
    // Best-effort IP detection; still trustable enough for rate limiting/logging.
    $keys = array('HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR');
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER[$k]));
            if ($k === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $ip);
                $ip = trim($parts[0]);
            }
            return $ip;
        }
    }
    return '0.0.0.0';
}

function tk_user_agent() {
    return !empty($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '';
}

/**
 * Serialized-safe find/replace
 */
function tk_recursive_replace($find, $replace, $data) {
    if (is_string($data)) {
        return str_replace($find, $replace, $data);
    }
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $data[$k] = tk_recursive_replace($find, $replace, $v);
        }
        return $data;
    }
    if (is_object($data)) {
        foreach ($data as $k => $v) {
            $data->$k = tk_recursive_replace($find, $replace, $v);
        }
        return $data;
    }
    return $data;
}

function tk_maybe_unserialize_replace($find, $replace, $value) {
    if (!is_string($value)) return $value;

    $un = @unserialize($value);
    if ($un !== false || $value === 'b:0;') {
        $un = tk_recursive_replace($find, $replace, $un);
        return serialize($un);
    }
    return str_replace($find, $replace, $value);
}
