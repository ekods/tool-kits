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
    $old = array_key_exists($key, $opts) ? $opts[$key] : null;
    $opts[$key] = $value;
    update_option('tk_options', $opts, false);
    update_option('tk_killswitch_last_key', $key, false);
    tk_toolkits_audit_log_option_change($key, $old, $value);
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

function tk_remove_directory(string $dir, int &$removed_files, int &$removed_dirs, int &$failed): void {
    if ($dir === '' || !is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if (!is_string($path) || $path === '') {
            continue;
        }
        if ($item->isDir()) {
            if (@rmdir($path)) {
                $removed_dirs++;
            } else {
                $failed++;
            }
            continue;
        }
        if (@unlink($path)) {
            $removed_files++;
        } else {
            $failed++;
        }
    }
    if (@rmdir($dir)) {
        $removed_dirs++;
    } else {
        $failed++;
    }
}

function tk_remove_ds_store_in_root(): array {
    $root = defined('ABSPATH') ? rtrim(ABSPATH, "/\\") : '';
    if ($root === '' || !is_dir($root)) {
        return array('ok' => false, 'removed' => 0, 'failed' => 0, 'message' => 'WordPress root not found.');
    }
    $removed = 0;
    $removed_dirs = 0;
    $failed = 0;
    $dirs = array();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isDir() && $file->getFilename() === '__MACOSX') {
            $dirs[] = $file->getPathname();
            continue;
        }
        if (!$file->isFile()) {
            continue;
        }
        if ($file->getFilename() !== '.DS_Store') {
            continue;
        }
        $path = $file->getPathname();
        if (!is_string($path) || $path === '') {
            continue;
        }
        if (strpos($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }
        if (@unlink($path)) {
            $removed++;
        } else {
            $failed++;
        }
    }

    if (!empty($dirs)) {
        $dirs = array_values(array_unique($dirs));
        foreach ($dirs as $dir) {
            if (!is_string($dir) || $dir === '') {
                continue;
            }
            if (strpos($dir, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR) !== false) {
                continue;
            }
            tk_remove_directory($dir, $removed, $removed_dirs, $failed);
        }
    }

    if ($removed === 0 && $removed_dirs === 0 && $failed === 0) {
        $message = 'No .DS_Store or __MACOSX items found in WordPress root.';
    } else {
        $message = 'Removed ' . $removed . ' .DS_Store file(s) and ' . $removed_dirs . ' __MACOSX folder(s).';
        if ($failed > 0) {
            $message .= ' ' . $failed . ' failed to delete.';
        }
    }
    return array('ok' => $failed === 0, 'removed' => $removed, 'failed' => $failed, 'message' => $message);
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
        'rate_limit_block_on_fail' => 0,
        'rate_limit_whitelist' => '',
        'rate_limit_blocked_ips' => array(),
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
        // Asset optimization
        'assets_critical_css_enabled' => 0,
        'assets_critical_css' => '',
        'assets_defer_css_handles' => '',
        'assets_preload_css_handles' => '',
        'assets_preload_fonts' => '',
        'assets_font_display_swap' => 1,
        'assets_dimensions_enabled' => 1,
        'upload_images_limit_enabled' => 1,
        'upload_images_max_mb' => 2,
        // Access control
        'toolkits_allowed_roles' => array('administrator'),
        'toolkits_ip_allowlist' => '',
        'toolkits_lock_enabled' => 0,
        'toolkits_mask_sensitive_fields' => 0,
        'toolkits_audit_log' => array(),
        'toolkits_alert_enabled' => 1,
        'toolkits_alert_email' => '',
        'toolkits_alert_admin_created' => 1,
        'toolkits_alert_role_change' => 1,
        'toolkits_alert_admin_login_new_ip' => 1,
        'toolkits_owner_only_enabled' => 0,
        'toolkits_owner_user_id' => 1,
        'toolkits_install_id' => '',
        'license_server_url' => '',
        'license_key' => '',
        'license_status' => 'inactive',
        'license_message' => '',
        'license_last_checked' => 0,
        'license_env' => '',
        'license_type' => '',
        'license_site_url' => '',
        'license_expires_at' => '',
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
    return tk_toolkits_can_manage();
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

function tk_toolkits_allowed_roles(): array {
    $roles = tk_get_option('toolkits_allowed_roles', array('administrator'));
    if (!is_array($roles)) {
        $roles = array('administrator');
    }
    $roles = array_filter(array_map('sanitize_key', $roles));
    if (empty($roles)) {
        $roles = array('administrator');
    }
    return array_values(array_unique($roles));
}

function tk_toolkits_ip_allowlist(): array {
    $raw = (string) tk_get_option('toolkits_ip_allowlist', '');
    if (trim($raw) === '') {
        return array();
    }
    $parts = preg_split('/[\s,]+/', $raw);
    if (!is_array($parts)) {
        return array();
    }
    $list = array();
    foreach ($parts as $part) {
        $ip = trim($part);
        if ($ip === '') {
            continue;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            continue;
        }
        $list[] = $ip;
    }
    return array_values(array_unique($list));
}

function tk_toolkits_ip_allowed(): bool {
    $allowlist = tk_toolkits_ip_allowlist();
    if (empty($allowlist)) {
        return true;
    }
    $ip = tk_get_ip();
    return in_array($ip, $allowlist, true);
}

function tk_toolkits_capability(): string {
    return 'toolkits_manage';
}

function tk_toolkits_user_allowed($user): bool {
    if (!$user || empty($user->roles)) {
        return false;
    }
    $allowed = tk_toolkits_allowed_roles();
    foreach ($user->roles as $role) {
        if (in_array($role, $allowed, true)) {
            return true;
        }
    }
    return false;
}

function tk_toolkits_grant_manage_cap(array $allcaps, array $caps, array $args, $user): array {
    if (!in_array(tk_toolkits_capability(), $caps, true)) {
        return $allcaps;
    }
    if (!$user || empty($user->ID)) {
        return $allcaps;
    }
    if (!tk_toolkits_ip_allowed()) {
        return $allcaps;
    }
    if ((int) tk_get_option('toolkits_owner_only_enabled', 0) === 1) {
        $owner_id = (int) tk_get_option('toolkits_owner_user_id', 1);
        if ((int) $user->ID === $owner_id) {
            $allcaps[tk_toolkits_capability()] = true;
        }
        return $allcaps;
    }
    if (tk_toolkits_user_allowed($user) || (!empty($allcaps['manage_options']) && $allcaps['manage_options'])) {
        $allcaps[tk_toolkits_capability()] = true;
    }
    return $allcaps;
}
add_filter('user_has_cap', 'tk_toolkits_grant_manage_cap', 10, 4);

function tk_toolkits_can_manage(): bool {
    if (!is_user_logged_in()) {
        return false;
    }
    if (!tk_toolkits_ip_allowed()) {
        return false;
    }
    if ((int) tk_get_option('toolkits_owner_only_enabled', 0) === 1) {
        $owner_id = (int) tk_get_option('toolkits_owner_user_id', 1);
        $user = wp_get_current_user();
        if (!$user || (int) $user->ID !== $owner_id) {
            return false;
        }
        return true;
    }
    $user = wp_get_current_user();
    if (!$user) {
        return false;
    }
    if (tk_toolkits_user_allowed($user)) {
        return true;
    }
    return current_user_can('manage_options');
}

function tk_toolkits_install_id(): string {
    $id = (string) tk_get_option('toolkits_install_id', '');
    if ($id === '') {
        $id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid((string) mt_rand(), true));
        tk_update_option('toolkits_install_id', $id);
    }
    return $id;
}

function tk_license_env(): string {
    $host = parse_url(home_url('/'), PHP_URL_HOST);
    $host = is_string($host) ? strtolower($host) : '';
    if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
        return 'local';
    }
    $locals = array('.local', '.test', '.dev', '.internal');
    foreach ($locals as $suffix) {
        if (substr($host, -strlen($suffix)) === $suffix) {
            return 'local';
        }
    }
    if (preg_match('/(^staging\.|\.staging\.|^stage\.|\.stage\.)/', $host)) {
        return 'staging';
    }
    return 'prod';
}

function tk_license_server_url(): string {
    return (string) tk_get_option('license_server_url', '');
}

function tk_license_validate(bool $force = false): array {
    $status = (string) tk_get_option('license_status', 'inactive');
    $message = (string) tk_get_option('license_message', '');
    $last_checked = (int) tk_get_option('license_last_checked', 0);
    $ttl = 6 * HOUR_IN_SECONDS;
    if (!$force && $last_checked > 0 && (time() - $last_checked) < $ttl) {
        return array('status' => $status, 'message' => $message);
    }
    $key = trim((string) tk_get_option('license_key', ''));
    if ($key === '') {
        tk_update_option('license_status', 'missing');
        tk_update_option('license_message', 'License key is required.');
        tk_update_option('license_last_checked', time());
        return array('status' => 'missing', 'message' => 'License key is required.');
    }
    $url = trim(tk_license_server_url());
    if ($url === '') {
        $collector_url = (string) tk_get_option('heartbeat_collector_url', '');
        if ($collector_url !== '') {
            if (substr($collector_url, -13) === 'heartbeat.php') {
                $url = substr($collector_url, 0, -13) . 'license.php';
            } else {
                $url = rtrim($collector_url, '/') . '/license.php';
            }
            tk_update_option('license_server_url', $url);
        }
    }
    if ($url === '') {
        tk_update_option('license_status', 'missing_server');
        tk_update_option('license_message', 'License server URL is required.');
        tk_update_option('license_last_checked', time());
        return array('status' => 'missing_server', 'message' => 'License server URL is required.');
    }
    $secret = (string) tk_get_option('heartbeat_auth_key', '');
    if ($secret === '') {
        tk_update_option('license_status', 'missing_secret');
        tk_update_option('license_message', 'Collector token is required.');
        tk_update_option('license_last_checked', time());
        return array('status' => 'missing_secret', 'message' => 'Collector token is required.');
    }

    $payload = array(
        'license_key' => $key,
        'site_url' => home_url('/'),
        'site_id' => tk_toolkits_install_id(),
        'env' => tk_license_env(),
        'action' => 'activate',
        'timestamp' => time(),
    );
    $body = wp_json_encode($payload);
    if ($body === false) {
        tk_update_option('license_status', 'error');
        tk_update_option('license_message', 'Failed to encode license request.');
        tk_update_option('license_last_checked', time());
        return array('status' => 'error', 'message' => 'Failed to encode license request.');
    }
    $signature = hash_hmac('sha256', $body, $secret);
    $headers = array(
        'Content-Type' => 'application/json',
        'X-Auth-Signature' => $signature,
        'X-Auth-Timestamp' => (string) $payload['timestamp'],
    );
    $http_user = (string) tk_get_option('heartbeat_http_user', '');
    $http_pass = (string) tk_get_option('heartbeat_http_pass', '');
    if ($http_user === '' && $http_pass === '' && defined('TK_HEARTBEAT_HTTP_USER') && defined('TK_HEARTBEAT_HTTP_PASS')) {
        $http_user = (string) TK_HEARTBEAT_HTTP_USER;
        $http_pass = (string) TK_HEARTBEAT_HTTP_PASS;
    }
    if ($http_user !== '' || $http_pass !== '') {
        $headers['Authorization'] = 'Basic ' . base64_encode($http_user . ':' . $http_pass);
    }
    $response = wp_remote_post($url, array(
        'timeout' => 10,
        'headers' => $headers,
        'body' => $body,
    ));
    if (is_wp_error($response)) {
        tk_update_option('license_status', 'error');
        tk_update_option('license_message', $response->get_error_message());
        tk_update_option('license_last_checked', time());
        return array('status' => 'error', 'message' => $response->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $raw = (string) wp_remote_retrieve_body($response);
    $data = json_decode($raw, true);
    $ok = is_array($data) && !empty($data['ok']) && $code >= 200 && $code < 300;
    $server_status = is_array($data) && isset($data['status']) ? (string) $data['status'] : '';
    $new_status = $ok ? 'valid' : 'invalid';
    $new_message = '';
    if (is_array($data) && isset($data['message'])) {
        $new_message = (string) $data['message'];
    } elseif (!$ok) {
        $detail = trim(strip_tags($raw));
        if ($detail !== '') {
            $new_message = 'HTTP ' . $code . ': ' . substr($detail, 0, 200);
        } else {
            $new_message = 'License validation failed.';
        }
    }
    if (!$ok && $server_status !== '') {
        $new_status = $server_status;
    }
    tk_update_option('license_status', $new_status);
    tk_update_option('license_message', $new_message);
    tk_update_option('license_last_checked', time());
    tk_update_option('license_env', (string) $payload['env']);
    if (is_array($data) && isset($data['license_type'])) {
        tk_update_option('license_type', (string) $data['license_type']);
    }
    if (is_array($data) && isset($data['site_url'])) {
        tk_update_option('license_site_url', (string) $data['site_url']);
    }
    if (is_array($data) && isset($data['expires_at'])) {
        tk_update_option('license_expires_at', (string) $data['expires_at']);
    } elseif (!$ok && in_array($server_status, array('revoked', 'not_found', 'expired'), true)) {
        tk_update_option('license_expires_at', '');
    }
    return array('status' => $new_status, 'message' => $new_message);
}

function tk_license_is_valid(): bool {
    $status = (string) tk_get_option('license_status', 'inactive');
    $last_checked = (int) tk_get_option('license_last_checked', 0);
    return $status === 'valid' && $last_checked > 0 && (time() - $last_checked) < DAY_IN_SECONDS;
}

function tk_toolkits_is_locked(): bool {
    return (int) tk_get_option('toolkits_lock_enabled', 0) === 1;
}

function tk_toolkits_mask_sensitive(): bool {
    return (int) tk_get_option('toolkits_mask_sensitive_fields', 0) === 1;
}

function tk_toolkits_audit_log_option_change($key, $old, $new): void {
    if (!is_admin() || !is_user_logged_in()) {
        return;
    }
    if (defined('WP_CLI') && WP_CLI) {
        return;
    }
    if ($key === 'toolkits_audit_log') {
        return;
    }
    if ($old === $new) {
        return;
    }
    $sensitive = array(
        'hardening_httpauth_pass',
        'heartbeat_auth_key',
        'heartbeat_http_pass',
        'monitoring_healthcheck_key',
        'toolkits_ip_allowlist',
    );
    $detail = array(
        'key' => $key,
        'from' => in_array($key, $sensitive, true) ? '***' : (is_scalar($old) ? (string) $old : gettype($old)),
        'to' => in_array($key, $sensitive, true) ? '***' : (is_scalar($new) ? (string) $new : gettype($new)),
    );
    tk_toolkits_audit_log('option_update', $detail);
}

function tk_toolkits_audit_log(string $action, array $detail = array()): void {
    $opts = get_option('tk_options', array());
    if (!is_array($opts)) {
        $opts = array();
    }
    $log = array_key_exists('toolkits_audit_log', $opts) && is_array($opts['toolkits_audit_log']) ? $opts['toolkits_audit_log'] : array();
    $user = wp_get_current_user();
    $entry = array(
        'time' => time(),
        'user' => $user ? $user->user_login : 'system',
        'action' => $action,
        'detail' => $detail,
        'ip' => tk_get_ip(),
    );
    $log[] = $entry;
    if (count($log) > 200) {
        $log = array_slice($log, -200);
    }
    $opts['toolkits_audit_log'] = $log;
    update_option('tk_options', $opts, false);
}

function tk_toolkits_guard(): void {
    if (!is_admin()) {
        return;
    }
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    $action = isset($_REQUEST['action']) ? sanitize_key($_REQUEST['action']) : '';
    $is_toolkits_page = $page !== '' && strpos($page, 'tool-kits') === 0;
    $is_toolkits_action = $action !== '' && strpos($action, 'tk_') === 0;
    if (!$is_toolkits_page && !$is_toolkits_action) {
        return;
    }
    if (!tk_toolkits_can_manage()) {
        $message = '<h1>Access Restricted</h1><p>Tool Kits access is restricted for your account.</p><p><a href="' . esc_url(admin_url('tools.php?page=tool-kits-access')) . '">Go to Tool Kits Access</a></p>';
        wp_die($message, 'Tool Kits', array('response' => 403));
    }
    $collector_key = (string) tk_get_option('heartbeat_auth_key', '');
    if ($collector_key === '' && defined('TK_HEARTBEAT_AUTH_KEY')) {
        $collector_key = (string) TK_HEARTBEAT_AUTH_KEY;
    }
    if ($collector_key === '' && $page !== 'tool-kits-access' && $action !== 'tk_toolkits_access_save') {
        $message = '<h1>Collector Token Required</h1><p>Please set the collector token in Tool Kits Access.</p><p><a href="' . esc_url(admin_url('tools.php?page=tool-kits-access')) . '">Open Tool Kits Access</a></p>';
        wp_die($message, 'Tool Kits', array('response' => 403));
    }
    if ($page === 'tool-kits-access' || $action === 'tk_toolkits_access_save') {
        tk_license_validate(true);
        return;
    }
    $license = tk_license_validate(true);
    if (!tk_license_is_valid()) {
        $detail = isset($license['message']) && $license['message'] !== '' ? $license['message'] : 'License invalid.';
        $message = '<h1>License Required</h1><p>' . esc_html($detail) . '</p><p><a href="' . esc_url(admin_url('tools.php?page=tool-kits-access&tk_license=1')) . '">Open License Settings</a></p>';
        if (!$is_toolkits_action) {
            $target = admin_url('tools.php?page=tool-kits-access&tk_license=1');
            wp_safe_redirect($target);
            exit;
        }
        wp_die($message, 'Tool Kits', array('response' => 403));
    }
    if (tk_toolkits_is_locked() && $is_toolkits_action && $action !== 'tk_toolkits_access_save' && $action !== 'tk_toolkits_audit_clear') {
        wp_die('Tool Kits settings are locked.', 'Tool Kits', array('response' => 403));
    }
}

function tk_toolkits_mask_fields_script(): void {
    if (!tk_toolkits_mask_sensitive() || !is_admin()) {
        return;
    }
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if ($page === '' || strpos($page, 'tool-kits') !== 0) {
        return;
    }
    ?>
    <script>
    (function(){
        var selectors = [
            'input[name*="key"]',
            'input[name*="pass"]',
            'textarea[name*="key"]',
            'textarea[name*="pass"]'
        ];
        document.querySelectorAll(selectors.join(',')).forEach(function(el){
            if (el.type === 'password') {
                return;
            }
            if (el.tagName === 'INPUT') {
                el.type = 'password';
            }
            el.setAttribute('autocomplete', 'off');
        });
    })();
    </script>
    <?php
}

function tk_user_agent() {
    return !empty($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '';
}

function tk_toolkits_access_denied_page(): void {
    if (!is_admin()) {
        return;
    }
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if ($page === '' || strpos($page, 'tool-kits') !== 0) {
        return;
    }
    $message = '<h1>Access Restricted</h1><p>You do not have permission to access Tool Kits.</p><p><a href="' . esc_url(admin_url('tools.php?page=tool-kits-access')) . '">Go to Tool Kits Access</a></p>';
    wp_die($message, 'Tool Kits', array('response' => 403));
}
add_action('admin_page_access_denied', 'tk_toolkits_access_denied_page');

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
