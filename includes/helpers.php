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
    update_option('tk_killswitch_last_key', $key, false);
}

function tk_log($message) {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    if (!is_scalar($message)) {
        $message = print_r($message, true);
    }
    error_log('[Tool Kits] ' . trim((string) $message));
}

function tk_clear_all_caches(): array {
    global $wpdb;
    $actions = array();
    $errors = array();

    if (function_exists('wp_cache_flush')) {
        $res = wp_cache_flush();
        if ($res === false) {
            $errors[] = 'Object cache flush failed.';
        } else {
            $actions[] = 'Object cache flushed.';
        }
    }

    if (isset($wpdb->options)) {
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'"
        );
        if ($deleted === false) {
            $errors[] = 'Failed to clear transients.';
        } else {
            $actions[] = 'Transients cleared.';
        }
    }

    if (is_multisite() && isset($wpdb->sitemeta)) {
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_%'"
        );
        if ($deleted === false) {
            $errors[] = 'Failed to clear site transients.';
        } else {
            $actions[] = 'Site transients cleared.';
        }
    }

    if (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
        $actions[] = 'WP Rocket cache cleared.';
    }
    if (function_exists('w3tc_flush_all')) {
        w3tc_flush_all();
        $actions[] = 'W3 Total Cache cleared.';
    }
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
        $actions[] = 'WP Super Cache cleared.';
    }
    if (function_exists('litespeed_purge_all')) {
        litespeed_purge_all();
        $actions[] = 'LiteSpeed cache cleared.';
    }
    if (has_action('litespeed_purge_all')) {
        do_action('litespeed_purge_all');
        $actions[] = 'LiteSpeed purge triggered.';
    }
    if (class_exists('WpeCommon')) {
        if (method_exists('WpeCommon', 'purge_memcached')) {
            WpeCommon::purge_memcached();
        }
        if (method_exists('WpeCommon', 'purge_varnish_cache')) {
            WpeCommon::purge_varnish_cache();
        }
        $actions[] = 'WP Engine cache cleared.';
    }
    if (class_exists('autoptimizeCache')) {
        autoptimizeCache::clearall();
        $actions[] = 'Autoptimize cache cleared.';
    }
    if (class_exists('Cache_Enabler')) {
        Cache_Enabler::clear_total_cache();
        $actions[] = 'Cache Enabler cleared.';
    }
    if (class_exists('WpFastestCache')) {
        $wpf = new WpFastestCache();
        if (method_exists($wpf, 'deleteCache')) {
            $wpf->deleteCache();
        }
        $actions[] = 'WP Fastest Cache cleared.';
    }

    if (empty($actions)) {
        $actions[] = 'No cache handlers detected.';
    }

    $ok = empty($errors);
    $message = implode(' ', $actions);
    if (!empty($errors)) {
        $message .= ' ' . implode(' ', $errors);
    }
    return array('ok' => $ok, 'message' => $message);
}

function tk_killswitch_init() {
    if (defined('WP_CLI') && WP_CLI) {
        return;
    }
    register_shutdown_function('tk_killswitch_shutdown');
    add_action('init', 'tk_killswitch_maybe_recover', 1);
    add_action('admin_notices', 'tk_killswitch_notice');
}

function tk_killswitch_snapshot(string $context = ''): void {
    $opts = get_option('tk_options', array());
    if (!is_array($opts)) {
        $opts = array();
    }
    $data = array(
        'time' => time(),
        'context' => $context,
        'options' => $opts,
    );
    update_option('tk_killswitch_snapshot', $data, false);
    update_option('tk_killswitch_last_context', $context, false);
}

function tk_killswitch_shutdown(): void {
    $error = error_get_last();
    if (!is_array($error)) {
        return;
    }
    $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (!in_array($error['type'], $fatal_types, true)) {
        return;
    }
    $file = isset($error['file']) ? (string) $error['file'] : '';
    if ($file === '' || (defined('TK_PATH') && strpos($file, TK_PATH) === false)) {
        return;
    }
    set_transient('tk_killswitch_triggered', 1, MINUTE_IN_SECONDS * 10);
}

function tk_killswitch_maybe_recover(): void {
    if (!get_transient('tk_killswitch_triggered')) {
        return;
    }
    $snapshot = get_option('tk_killswitch_snapshot', array());
    if (!is_array($snapshot) || empty($snapshot['options']) || !is_array($snapshot['options'])) {
        delete_transient('tk_killswitch_triggered');
        return;
    }
    $opts = $snapshot['options'];
    $last_key = get_option('tk_killswitch_last_key', '');
    $last_context = get_option('tk_killswitch_last_context', '');
    if ($last_context === 'hardening' || $last_context === 'core-updates') {
        if (is_string($last_key) && $last_key !== '' && array_key_exists($last_key, $opts)) {
            $opts[$last_key] = 0;
        }
    }
    update_option('tk_options', $opts, false);
    update_option('tk_killswitch_last_recovery', time(), false);
    update_option('tk_killswitch_last_recovery_key', $last_key, false);
    delete_transient('tk_killswitch_triggered');
}

function tk_killswitch_notice(): void {
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }
    $last = (int) get_option('tk_killswitch_last_recovery', 0);
    if ($last <= 0 || (time() - $last) > HOUR_IN_SECONDS) {
        return;
    }
    $key = (string) get_option('tk_killswitch_last_recovery_key', '');
    $msg = 'Tool Kits recovery applied after a fatal error. Last rule disabled: ' . ($key !== '' ? $key : 'unknown');
    tk_notice($msg, 'warning');
}

function tk_tamper_collect_hashes(): array {
    if (!defined('TK_PATH')) {
        return array();
    }
    $root = rtrim(TK_PATH, '/');
    $hashes = array();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $path = $file->getPathname();
        if (!is_string($path) || $path === '') {
            continue;
        }
        if (strpos($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }
        
        $rel = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
        $hash = @sha1_file($path);
        if ($hash !== false) {
            $hashes[$rel] = $hash;
        }
    }
    ksort($hashes);
    return $hashes;
}

function tk_tamper_detect_files(): array {
    $current = tk_tamper_collect_hashes();
    $baseline = tk_get_option('monitoring_tamper_hashes', array());
    if (!is_array($baseline) || empty($baseline)) {
        tk_update_option('monitoring_tamper_hashes', $current);
        return array();
    }
    $added = array_values(array_diff(array_keys($current), array_keys($baseline)));
    $removed = array_values(array_diff(array_keys($baseline), array_keys($current)));
    $changed = array();
    foreach ($current as $file => $hash) {
        if (isset($baseline[$file]) && $baseline[$file] !== $hash) {
            $changed[] = $file;
        }
    }
    return array(
        'added' => $added,
        'removed' => $removed,
        'changed' => $changed,
    );
}

function tk_tamper_detect_security(): array {
    $checks = array(
        'hardening_disable_file_editor' => 'File editor disabled',
        'hardening_disable_xmlrpc' => 'XML-RPC disabled',
        'hardening_xmlrpc_block_methods' => 'XML-RPC dangerous methods blocked',
        'hardening_disable_rest_user_enum' => 'REST user enumeration disabled',
        'hardening_security_headers' => 'Security headers',
        'hardening_csp_lite_enabled' => 'CSP lite',
        'hardening_block_uploads_php' => 'Block uploads PHP',
        'hardening_block_plugin_installs' => 'Block plugin/theme installs',
    );
    $disabled = array();
    foreach ($checks as $key => $label) {
        if (!tk_get_option($key, 0)) {
            $disabled[] = $label;
        }
    }
    return $disabled;
}

function tk_tamper_maybe_alert(): void {
    if (!tk_get_option('monitoring_tamper_enabled', 1)) {
        return;
    }
    $email = tk_get_option('monitoring_alert_email', '');
    if (!is_string($email) || $email === '') {
        return;
    }
    if (get_transient('tk_tamper_alerted')) {
        return;
    }
    $file_changes = tk_tamper_detect_files();
    $disabled = tk_tamper_detect_security();
    $has_file_changes = !empty($file_changes['added']) || !empty($file_changes['removed']) || !empty($file_changes['changed']);
    if (!$has_file_changes && empty($disabled)) {
        return;
    }
    $lines = array();
    if ($has_file_changes) {
        if (!empty($file_changes['added'])) {
            $lines[] = "Added files:\n- " . implode("\n- ", $file_changes['added']);
        }
        if (!empty($file_changes['removed'])) {
            $lines[] = "Removed files:\n- " . implode("\n- ", $file_changes['removed']);
        }
        if (!empty($file_changes['changed'])) {
            $lines[] = "Changed files:\n- " . implode("\n- ", $file_changes['changed']);
        }
    }
    if (!empty($disabled)) {
        $lines[] = "Security settings disabled:\n- " . implode("\n- ", $disabled);
    }
    $subject = 'Tool Kits: Tamper detection alert';
    $body = implode("\n\n", $lines);
    wp_mail($email, $subject, $body);
    set_transient('tk_tamper_alerted', 1, DAY_IN_SECONDS);
}


function tk_hardening_active_items(): array {
    $items = array();
    if (tk_get_option('hardening_disable_file_editor', 1)) {
        $items[] = 'File editor disabled';
    }
    if (tk_get_option('hardening_disable_xmlrpc', 1)) {
        $items[] = 'XML-RPC disabled';
    }
    if (tk_get_option('hardening_xmlrpc_block_methods', 1)) {
        $items[] = 'XML-RPC dangerous methods blocked';
    }
    if (tk_get_option('hardening_xmlrpc_rate_limit_enabled', 0)) {
        $items[] = 'XML-RPC rate limit';
    }
    if (tk_get_option('hardening_disable_rest_user_enum', 1)) {
        $items[] = 'REST user enumeration disabled';
    }
    if (tk_get_option('hardening_disable_comments', 0)) {
        $items[] = 'Comments disabled';
    }
    if (tk_get_option('hardening_server_aware_enabled', 1)) {
        $items[] = 'Server-aware rules';
    }
    if (tk_get_option('hardening_auto_toggle', 1)) {
        $items[] = 'Auto hardening';
    }
    if (tk_get_option('hardening_security_headers', 1)) {
        $items[] = 'Security headers';
    }
    if (tk_get_option('hardening_csp_lite_enabled', 0)) {
        $items[] = 'CSP lite';
    }
    if (tk_get_option('hardening_hsts_enabled', 0)) {
        $items[] = 'HSTS';
    }
    if (tk_get_option('hardening_block_uploads_php', 1)) {
        $items[] = 'Block uploads PHP';
    }
    if (tk_get_option('hardening_block_plugin_installs', 1)) {
        $items[] = 'Block plugin installs (non-admin)';
    }
    if (tk_get_option('hardening_disable_pingbacks', 1)) {
        $items[] = 'XML-RPC pingbacks disabled';
    }
    if (tk_get_option('hardening_waf_enabled', 0)) {
        $items[] = 'WAF enabled';
    }
    if (tk_get_option('hardening_httpauth_enabled', 0)) {
        $items[] = 'HTTP Basic Auth';
    }
    if (tk_get_option('hardening_cors_custom_origins_enabled', 0)) {
        $items[] = 'Custom CORS origins';
    }
    return $items;
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
        'hardening_cors_allowed_origins' => '',
        'hardening_cors_custom_origins_enabled' => 0,
        'hardening_cors_allow_credentials' => 0,
        'hardening_cors_allowed_methods' => '',
        'hardening_cors_allowed_headers' => '',
        'hardening_xmlrpc_block_methods' => 1,
        'hardening_xmlrpc_blocked_methods' => "system.multicall\npingback.ping\npingback.extensions.getPingbacks",
        'hardening_xmlrpc_rate_limit_enabled' => 0,
        'hardening_xmlrpc_rate_limit_window_minutes' => 10,
        'hardening_xmlrpc_rate_limit_max_attempts' => 20,
        'hardening_xmlrpc_rate_limit_lockout_minutes' => 30,
        'hardening_waf_enabled' => 0,
        'hardening_waf_allow_paths' => '',
        'hardening_waf_allow_regex' => '',
        'hardening_waf_check_methods' => 'GET, POST',
        'hardening_waf_log_to_file' => 0,
        'hardening_waf_log_max_kb' => 1024,
        'hardening_waf_log_max_files' => 3,
        'hardening_waf_log_compress' => 0,
        'hardening_waf_log_compress_min_kb' => 256,
        'hardening_waf_log_keep_days' => 14,
        'hardening_waf_log_schedule' => 'daily',
        'hardening_httpauth_enabled' => 0,
        'hardening_httpauth_user' => '',
        'hardening_httpauth_pass' => '',
        'hardening_httpauth_scope' => 'both',
        'hardening_httpauth_allow_paths' => "/wp-cron.php\n/wp-json/\n/wp-admin/admin-ajax.php",
        'hardening_httpauth_allow_regex' => '',
        'hardening_disable_comments' => 0,
        'hardening_server_aware_enabled' => 1,
        'hardening_auto_toggle' => 1,
        'hardening_auto_applied' => 0,
        'hardening_block_uploads_php' => 1,
        'hardening_csp_lite_enabled' => 1,
        'hardening_hsts_enabled' => 0,
        'hardening_core_auto_updates' => 1,
        'hardening_block_plugin_installs' => 1,
        'monitoring_alert_email' => '',
        'monitoring_noncore_known' => array(),
        'monitoring_tamper_enabled' => 1,
        'monitoring_tamper_hashes' => array(),
        'heartbeat_enabled' => 0,
        'heartbeat_collector_url' => '',
        'heartbeat_auth_key' => '',
        'heartbeat_http_user' => '',
        'heartbeat_http_pass' => '',
        'hide_toolkits_menu' => 0,
        'hide_cff_menu' => 0,
        'minify_html_enabled' => 0,
        'minify_inline_css' => 1,
        'minify_inline_js' => 1,
        'minify_assets_enabled' => 0,
        'page_cache_enabled' => 0,
        'page_cache_ttl' => 3600,
        'page_cache_exclude_paths' => "/wp-login.php\n/wp-admin\n",
        'fragment_cache_keys' => array(),
        'webp_convert_enabled' => 0,
        'webp_serve_enabled' => 0,
        'webp_quality' => 82,
        'lazy_load_enabled' => 0,
        'lazy_load_eager_images' => 2,
        'lazy_load_iframe_video' => 1,
        'lazy_load_script_defer' => '',
        'lazy_load_script_delay' => '',
        'monitoring_404_enabled' => 1,
        'monitoring_404_exclude_paths' => "/wp-admin\n/wp-login.php\n/wp-cron.php\n",
        'monitoring_404_log' => array(),
        'monitoring_healthcheck_enabled' => 0,
        'monitoring_healthcheck_key' => '',
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

function tk_debug_deprecated_init(): void {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }
    if (!isset($_GET['tk_debug_deprecated'])) {
        return;
    }
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        if (($errno & (E_DEPRECATED | E_USER_DEPRECATED)) === 0) {
            return false;
        }
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $frames = array();
        foreach ($trace as $frame) {
            $func = isset($frame['function']) ? $frame['function'] : '';
            $file = isset($frame['file']) ? $frame['file'] : '';
            $line = isset($frame['line']) ? (int) $frame['line'] : 0;
            if ($func === '' && $file === '') {
                continue;
            }
            $frames[] = ($func !== '' ? $func . '()' : '(file)') . ($file !== '' ? " {$file}:{$line}" : '');
            if (count($frames) >= 12) {
                break;
            }
        }
        $stack = implode(' | ', $frames);
        error_log('[Tool Kits][Deprecated] ' . $errstr . ' in ' . $errfile . ':' . $errline . ' | ' . $stack);
        return false;
    }, E_DEPRECATED | E_USER_DEPRECATED);
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
        if (get_class($data) === '__PHP_Incomplete_Class') {
            static $logged_incomplete = false;
            if (!$logged_incomplete) {
                tk_log('Skipping recursive replacement for __PHP_Incomplete_Class instance while preparing an export.');
                $logged_incomplete = true;
            }
            return $data;
        }
        foreach ($data as $k => $v) {
            $data->$k = tk_recursive_replace($find, $replace, $v);
        }
        return $data;
    }
    return $data;
}

function tk_maybe_unserialize_replace($find, $replace, $value) {
    if (!is_string($value)) return $value;

    // Normalize find/replace to strings to avoid PHP 8.1+ deprecations
    $find = ($find === null) ? '' : (string) $find;
    $replace = ($replace === null) ? '' : (string) $replace;

    $un = @unserialize($value);
    if ($un !== false || $value === 'b:0;') {
        $un = tk_recursive_replace($find, $replace, $un);
        return serialize($un);
    }
    return str_replace($find, $replace, $value);
}
