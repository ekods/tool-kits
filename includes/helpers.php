<?php
if (!defined('ABSPATH')) { exit; }



function tk_render_page_hero($title, $description, $icon = 'dashicons-admin-tools', $action_html = '') {
    ?>
    <div class="tk-hero">
        <div class="tk-hero-bg-1"></div>
        <div class="tk-hero-bg-2"></div>
        
        <div class="tk-hero-content" style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
            <div style="display: flex; align-items: center; gap: 20px;">
                <div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 16px; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.2);">
                    <span class="dashicons <?php echo esc_attr($icon); ?>" style="color: #fff; font-size: 32px; width: 32px; height: 32px;"></span>
                </div>
                <div>
                    <h1 class="tk-hero-title"><?php echo esc_html($title); ?></h1>
                    <p class="tk-hero-subtitle"><?php echo esc_html($description); ?></p>
                </div>
            </div>
            <?php if ($action_html !== '') : ?>
                <div class="tk-hero-action">
                    <?php echo $action_html; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function tk_render_header_branding() {
    ?>
    <div class="tk-header-branding">
        <div class="tk-header-brand">
            <span class="dashicons dashicons-admin-tools"></span>
            <span>Tool Kits Pro</span>
            <span class="tk-header-version">v<?php echo TK_VERSION; ?></span>
        </div>

        <div style="display:flex; align-items:center; gap:20px;">
            <?php 
            $ga_id = tk_get_option('google_analytics_gtag_id', '');
            if ($ga_id) : ?>
                <div class="tk-header-status" style="background:#f0fdf4; border-color:#bbf7d0; color:#166534;">
                    <div class="tk-status-dot" style="background:#22c55e;"></div>
                    <span>GA Connected: <?php echo esc_html($ga_id); ?></span>
                </div>
            <?php endif; ?>

            <?php
            $heartbeat_success = (int) tk_get_option('heartbeat_last_success', 0);
            $heartbeat_fail = (int) tk_get_option('heartbeat_last_failure', 0);
            $is_online = $heartbeat_success > $heartbeat_fail;
            $hb_color = $is_online ? '#22c55e' : ($heartbeat_fail > 0 ? '#e74c3c' : '#94a3b8');
            $hb_text = $is_online ? 'Collector Online' : ($heartbeat_fail > 0 ? 'Collector Offline' : 'Collector Not Connected');
            ?>
            <div class="tk-header-status" style="background:#f8fafc; border-color:#e2e8f0; color:#334155;">
                <div class="tk-status-dot" style="background:<?php echo $hb_color; ?>;"></div>
                <span><?php echo esc_html($hb_text); ?></span>
            </div>

            <div class="tk-header-status">
                <div class="tk-status-dot"></div>
                <span>System Operational</span>
            </div>
        </div>
    </div>
    <style>
        .tk-header-branding {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #fff;
            padding: 12px 20px;
            border-radius: 12px;
            border: 1px solid var(--tk-border-soft);
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .tk-header-brand {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            font-size: 14px;
            color: #1e293b;
        }
        .tk-header-brand .dashicons {
            color: var(--tk-primary);
            font-size: 18px;
            width: 18px;
            height: 18px;
        }
        .tk-header-version {
            font-weight: 400;
            color: var(--tk-muted);
            font-size: 11px;
            background: var(--tk-bg-soft);
            padding: 2px 6px;
            border-radius: 4px;
        }
        .tk-header-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--tk-muted);
            background: #f0fdf4;
            padding: 6px 12px;
            border-radius: 20px;
            border: 1px solid #dcfce7;
        }
        .tk-status-dot {
            width: 8px;
            height: 8px;
            background: #22c55e;
            border-radius: 50%;
            box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.2);
            animation: tk-pulse 2s infinite;
        }
        @keyframes tk-pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
        }
    </style>
    <?php
}

/**
 * Option helpers
 */
function tk_get_option($key, $default = null) {
    $opts = get_option('tk_options', array());
    if (!is_array($opts)) { $opts = array(); }
    return array_key_exists($key, $opts) ? $opts[$key] : $default;
}

function tk_get_options() {
    $opts = get_option('tk_options', array());
    if (!is_array($opts)) { $opts = array(); }
    return $opts;
}

function tk_update_option($key, $value) {
    $opts = get_option('tk_options', array());
    if (!is_array($opts)) { $opts = array(); }
    $old = array_key_exists($key, $opts) ? $opts[$key] : null;
    $opts[$key] = $value;
    update_option('tk_options', $opts, false);
    $recoverable_key = tk_killswitch_current_recovery_key($key, $opts);
    if ($recoverable_key !== '') {
        update_option('tk_killswitch_last_key', $recoverable_key, false);
    }
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
    add_action('admin_notices', 'tk_license_admin_notice');
}

function tk_license_admin_notice() {
    if (!current_user_can('manage_options')) return;

    $screen = get_current_screen();
    if ($screen && $screen->id === 'tools_page_tool-kits-access') {
        return;
    }

    $status = tk_get_option('license_status', 'inactive');
    $key = tk_get_option('license_key', '');

    if ($status !== 'active' && $status !== 'valid' || $key === '') {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong>Tool Kits:</strong> Lisensi Anda belum aktif. Silakan masukkan kunci lisensi untuk mengaktifkan fitur premium dan pembaruan otomatis.
                <a href="<?php echo esc_url(tk_admin_url('tool-kits-access') . '#license'); ?>" class="button button-secondary" style="margin-left:10px;">Aktifkan Sekarang</a>
            </p>
        </div>
        <?php
    }
}

function tk_killswitch_is_recoverable_key(string $key): bool {
    return strpos($key, 'hardening_') === 0;
}

function tk_killswitch_is_armed_context($context): bool {
    return in_array($context, array('hardening', 'core-updates'), true);
}

function tk_killswitch_current_recovery_key($key, ?array $opts = null): string {
    $context = get_option('tk_killswitch_last_context', '');
    if (!tk_killswitch_is_armed_context($context)) {
        return '';
    }
    return tk_killswitch_normalize_recovery_key($key, $opts);
}

function tk_killswitch_normalize_recovery_key($key, ?array $opts = null): string {
    if (!is_string($key) || $key === '' || !tk_killswitch_is_recoverable_key($key)) {
        return '';
    }
    if ($opts !== null && !array_key_exists($key, $opts)) {
        return '';
    }
    return $key;
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
    update_option('tk_killswitch_last_key', '', false);
}

function tk_killswitch_store_fatal_error(array $error): void {
    $type = isset($error['type']) ? (int) $error['type'] : 0;
    $message = isset($error['message']) && is_scalar($error['message']) ? trim((string) $error['message']) : '';
    $file = isset($error['file']) && is_scalar($error['file']) ? (string) $error['file'] : '';
    $line = isset($error['line']) ? (int) $error['line'] : 0;
    if (defined('TK_PATH') && $file !== '' && strpos($file, TK_PATH) === 0) {
        $file = ltrim(str_replace(TK_PATH, '', $file), '/');
    }
    update_option('tk_killswitch_last_error', array(
        'time' => time(),
        'type' => $type,
        'message' => $message,
        'file' => $file,
        'line' => $line,
    ), false);
}

function tk_killswitch_clear_recovery_state(): void {
    delete_transient('tk_killswitch_triggered');
    delete_option('tk_killswitch_last_recovery');
    delete_option('tk_killswitch_last_recovery_key');
    delete_option('tk_killswitch_last_context');
    delete_option('tk_killswitch_last_key');
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
    tk_killswitch_store_fatal_error($error);
    set_transient('tk_killswitch_triggered', 1, MINUTE_IN_SECONDS * 10);
}

function tk_killswitch_maybe_recover(): void {
    if (!get_transient('tk_killswitch_triggered')) {
        return;
    }
    $snapshot = get_option('tk_killswitch_snapshot', array());
    if (!is_array($snapshot) || empty($snapshot['options']) || !is_array($snapshot['options'])) {
        tk_killswitch_clear_recovery_state();
        return;
    }
    $snapshot_time = isset($snapshot['time']) ? (int) $snapshot['time'] : 0;
    if ($snapshot_time <= 0 || (time() - $snapshot_time) > (15 * MINUTE_IN_SECONDS)) {
        tk_killswitch_clear_recovery_state();
        return;
    }
    $opts = $snapshot['options'];
    $last_key = get_option('tk_killswitch_last_key', '');
    $last_context = get_option('tk_killswitch_last_context', '');
    $recovery_key = '';
    if (tk_killswitch_is_armed_context($last_context)) {
        $last_key = tk_killswitch_normalize_recovery_key($last_key, $opts);
        if ($last_key !== '') {
            $opts[$last_key] = 0;
            $recovery_key = $last_key;
        }
    }
    update_option('tk_options', $opts, false);
    update_option('tk_killswitch_last_recovery', time(), false);
    update_option('tk_killswitch_last_recovery_key', $recovery_key, false);
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
    $raw_key = get_option('tk_killswitch_last_recovery_key', '');
    $key = tk_killswitch_normalize_recovery_key($raw_key);
    $error = get_option('tk_killswitch_last_error', array());
    $detail = '';
    if (is_array($error)) {
        $parts = array();
        if (!empty($error['file']) && !empty($error['line'])) {
            $parts[] = sprintf('Source: %s:%d', $error['file'], (int) $error['line']);
        }
        if (!empty($error['message'])) {
            $parts[] = sprintf('Error: %s', substr((string) $error['message'], 0, 180));
        }
        if (!empty($parts)) {
            $detail = '<br><small>' . esc_html(implode(' | ', $parts)) . '</small>';
        }
    }
    $hardening_url = admin_url('admin.php?page=' . urlencode(tk_hardening_page_slug()));
    $menu_hint = ' Review the setting in <a href="' . esc_url($hardening_url) . '">Tool Kits > Hardening</a>.';
    $msg = $key !== ''
        ? 'Tool Kits recovery applied after a fatal error. Last rule disabled: ' . $key . '.' . $menu_hint
        : 'Tool Kits recovery restored the last safe settings after a fatal error.' . $menu_hint;
    if ($key === '' && is_string($raw_key) && $raw_key !== '') {
        tk_log('Recovery notice suppressed non-hardening key: ' . $raw_key);
    }
    $msg .= $detail;
    tk_notice($msg, 'warning');
    tk_killswitch_clear_recovery_state();
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
        'hardening_csp_balanced_enabled' => 'CSP balanced',
        'hardening_csp_hardened_enabled' => 'CSP hardened',
        'hardening_csp_strict_enabled' => 'CSP strict',
        'hardening_block_uploads_php' => 'Block uploads PHP',
        'hardening_block_plugin_installs' => 'Block plugin/theme installs',
        'hardening_server_signature_hide' => 'Server signature hidden',
        'hardening_cookie_httponly_force' => 'Cookie HttpOnly',
        'hardening_disable_wp_cron' => 'WP-Cron disabled',
        'hardening_url_param_guard_enabled' => 'URL parameter guard',
        'hardening_http_methods_filter_enabled' => 'HTTP methods filter',
        'hardening_block_dangerous_methods_enabled' => 'Dangerous HTTP methods blocked',
        'hardening_robots_txt_hardened' => 'Robots.txt hardened',
        'hardening_block_unwanted_files_enabled' => 'Unwanted file block',
        'hardening_mysql_exposure_check_enabled' => 'MySQL exposure check',
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
    if (get_transient('tk_tamper_alerted') || get_transient('tk_tamper_scan_throttle')) {
        return;
    }
    // Throttle scan to once per hour even if no alert sent
    set_transient('tk_tamper_scan_throttle', 1, HOUR_IN_SECONDS);
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
    if (tk_get_option('hardening_csp_balanced_enabled', 0)) {
        $items[] = 'CSP balanced';
    }
    if (tk_get_option('hardening_csp_hardened_enabled', 0)) {
        $items[] = 'CSP hardened';
    }
    if (tk_get_option('hardening_csp_strict_enabled', 0)) {
        $items[] = 'CSP strict';
    }
    if (tk_get_option('hardening_hsts_enabled', 0)) {
        $items[] = tk_get_option('hardening_hsts_preload', 0) ? 'HSTS preload' : 'HSTS';
    }
    if (tk_get_option('hardening_server_signature_hide', 1)) {
        $items[] = 'Server signature hidden';
    }
    if (tk_get_option('hardening_cookie_httponly_force', 0)) {
        $items[] = 'Cookie HttpOnly forced';
    }
    if (tk_get_option('hardening_disable_wp_cron', 0)) {
        $items[] = 'WP-Cron disabled';
    }
    if (tk_get_option('hardening_url_param_guard_enabled', 0)) {
        $items[] = 'URL parameter guard';
    }
    if (tk_get_option('hardening_http_methods_filter_enabled', 0)) {
        $items[] = 'HTTP methods filter';
    }
    if (tk_get_option('hardening_block_dangerous_methods_enabled', 1)) {
        $items[] = 'Dangerous HTTP methods blocked';
    }
    if (tk_get_option('hardening_robots_txt_hardened', 0)) {
        $items[] = 'Robots.txt hardened';
    }
    if (tk_get_option('hardening_block_unwanted_files_enabled', 1)) {
        $items[] = 'Unwanted file block';
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
    if (tk_get_option('hardening_hide_wp_version', 1)) {
        $items[] = 'Hide WP version';
    }
    if (tk_get_option('hardening_clean_wp_head', 0)) {
        $items[] = 'Clean WP head';
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
        'captcha_on_login' => 0,
        'captcha_on_comments' => 0,
        'captcha_length' => 5,
        'captcha_strength' => 'medium',
        // Global form guard
        'form_guard_enabled' => 1,
        'form_guard_block_empty_ua' => 1,
        'form_guard_blocked_user_agents' => "curl\nwget\npython-requests\npython-urllib\nlibwww-perl\nscrapy\nhttpclient\ngo-http-client\njava/\nokhttp\npowershell\npostmanruntime",
        'form_guard_post_window_minutes' => 10,
        'form_guard_post_max_attempts' => 8,
        'form_guard_comment_min_seconds' => 4,
        // Antispam contact
        'antispam_cf7_enabled' => 0,
        'antispam_min_seconds' => 5,
        'antispam_block_links' => 1,
        'antispam_max_links' => 0,
        'antispam_block_disposable_email' => 1,
        'antispam_disposable_domains' => "mailinator.com\ntrashmail.com\ntempmail.com\ntemp-mail.org\n10minutemail.com\nguerrillamail.com\nwildbmail.com\nyopmail.com\nsharklasers.com",
        'antispam_block_keywords' => "crypto\nbitcoin\nforex\nseo service\nguest post\nbacklink\ncasino\nloan\nhref=\n<a \nis.gd\nbit.ly\ntinyurl.com\ncutt.ly\nfuck\nsex",
        'antispam_message_min_chars' => 20,
        'antispam_duplicate_window_minutes' => 5,
        'antispam_duplicate_window_default_5_migrated' => 0,
        'antispam_email_cooldown_minutes' => 15,
        'antispam_ip_cooldown_seconds' => 60,
        'antispam_generic_phrases' => "hi\nhello\ncheck this\nsee it here\nsee here\nclick here\nhave a peek here\ncontact me\nwhatsapp me\ngood day\nhow are you\ni have a question\ntest",
        'antispam_block_html' => 1,
        'antispam_block_shorteners' => 1,
        'antispam_rate_limit_enabled' => 1,
        'antispam_rate_limit_window_minutes' => 15,
        'antispam_rate_limit_max_attempts' => 2,
        'antispam_log' => array(),
        // Rate limit
        'rate_limit_enabled' => 0,
        'rate_limit_window_minutes' => 10,
        'rate_limit_max_attempts' => 5,
        'rate_limit_lockout_minutes' => 30,
        'rate_limit_block_on_fail' => 0,
        'rate_limit_whitelist' => '',
        'rate_limit_blocked_ips' => array(),
        // SMTP
        'smtp_enabled' => 0,
        'smtp_provider' => 'gmail',
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => 587,
        'smtp_secure' => 'tls',
        'smtp_username' => '',
        'smtp_password' => '',
        'smtp_from_email' => '',
        'smtp_from_name' => '',
        'smtp_force_from' => 1,
        'smtp_return_path' => 1,
        'smtp_test_log' => array(),
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
        'hardening_csp_balanced_enabled' => 0,
        'hardening_csp_hardened_enabled' => 0,
        'hardening_csp_strict_enabled' => 0,
        'hardening_csp_script_sources' => '',
        'hardening_csp_style_sources' => '',
        'hardening_csp_connect_sources' => '',
        'hardening_csp_frame_sources' => '',
        'hardening_csp_img_sources' => '',
        'hardening_hsts_enabled' => 1,
        'hardening_hsts_preload' => 0,
        'hardening_server_signature_hide' => 1,
        'hardening_cookie_httponly_force' => 0,
        'hardening_disable_wp_cron' => 0,
        'hardening_url_param_guard_enabled' => 0,
        'hardening_http_methods_filter_enabled' => 0,
        'hardening_http_methods_allowed' => 'GET, POST',
        'hardening_http_methods_allow_paths' => "/wp-json/\n/wp-admin/admin-ajax.php\n/wp-cron.php",
        'hardening_block_dangerous_methods_enabled' => 1,
        'hardening_dangerous_http_methods' => 'PUT, DELETE, TRACE, CONNECT',
        'hardening_dangerous_methods_allow_paths' => "/wp-json/\n/wp-admin/admin-ajax.php\n/wp-cron.php",
        'hardening_robots_txt_hardened' => 0,
        'hardening_block_unwanted_files_enabled' => 1,
        'hardening_unwanted_file_names' => '.ds_store, thumbs.db, phpinfo.php, error_log, debug.log',
        'hardening_mysql_exposure_check_enabled' => 1,
        'hardening_mysql_allow_public_host' => 0,
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
        'heartbeat_last_checked' => 0,
        'heartbeat_last_success' => 0,
        'heartbeat_last_failure' => 0,
        'heartbeat_last_error_message' => '',
        'heartbeat_last_endpoint' => '',
        'hide_toolkits_menu' => 0,
        'hide_cff_menu' => 0,
        'minify_html_enabled' => 0,
        'minify_inline_css' => 1,
        'minify_inline_js' => 1,
        'minify_assets_enabled' => 0,
        'page_cache_enabled' => 0,
        'page_cache_ttl' => 3600,
        'page_cache_exclude_paths' => "/wp-login.php\n/wp-admin\n",
        'page_cache_preload_urls' => '',
        'fragment_cache_keys' => array(),
        'webp_convert_enabled' => 0,
        'webp_serve_enabled' => 0,
        'webp_quality' => 82,
        'image_opt_enabled' => 0,
        'image_opt_frontend_to_webp' => 0,
        'image_opt_rewrite_all_assets' => 0,
        'image_opt_quality' => 78,
        'seo_enabled' => 0,
        'seo_meta_desc_enabled' => 1,
        'seo_canonical_enabled' => 1,
        'seo_og_enabled' => 1,
        'seo_schema_enabled' => 1,
        'seo_noindex_search' => 1,
        'seo_noindex_404' => 1,
        'seo_noindex_paged_archives' => 1,
        'seo_redirect_rules' => array(),
        'seo_sitemap_enabled' => 1,
        'seo_sitemap_path' => 'sitemap.xml',
        'seo_sitemap_include_taxonomies' => 1,
        'seo_sitemap_include_images' => 1,
        'seo_sitemap_changefreq' => 'weekly',
        'seo_sitemap_priority' => 0.8,
        'seo_sitemap_exclude_paths' => '',
        'seo_broken_links_report' => array(),
        'seo_canonical_audit_report' => array(),
        'seo_index_monitor' => array(),
        'seo_content_audit_report' => array(),
        'lazy_load_enabled' => 0,
        'lazy_load_eager_images' => 2,
        'lazy_load_html_images' => 1,
        'lazy_load_iframe_video' => 1,
        'lazy_load_script_defer' => '',
        'lazy_load_script_delay' => '',
        'classic_editor_enabled' => 0,
        'classic_widgets_enabled' => 0,
        'monitoring_404_enabled' => 1,
        'monitoring_404_redirect_home' => 0,
        'monitoring_404_exclude_paths' => "/wp-admin\n/wp-login.php\n/wp-cron.php\n",
        'monitoring_404_log' => array(),
        'captcha_type' => 'text', // text, checkbox
        'monitoring_healthcheck_enabled' => 0,
        'monitoring_healthcheck_key' => '',
        'license_ssl_verify' => 1,
        // Asset optimization
        'assets_critical_css_enabled' => 0,
        'assets_critical_css' => '',
        'assets_defer_css_handles' => '',
        'assets_preload_css_handles' => '',
        'assets_preload_fonts' => '',
        'assets_font_display_swap' => 1,
        'assets_disable_google_fonts' => 0,
        'assets_dimensions_enabled' => 1,
        'assets_cls_guard_enabled' => 1,
        'assets_lcp_boost_enabled' => 1,
        'assets_lcp_bg_preload_enabled' => 1,
        'assets_preconnect_auto_enabled' => 1,
        'assets_js_delay_enabled' => 0,
        'assets_js_delay_handles' => '',
        'upload_images_limit_enabled' => 1,
        'upload_images_default_mb' => 2,
        'upload_images_max_mb' => 10,
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
        'license_last_success' => 0,
        'license_last_failure' => 0,
        'license_last_error_message' => '',
        'license_last_endpoint' => '',
        'license_env' => '',
        'license_type' => '',
        'license_site_url' => '',
        'license_notify_email' => '',
        'license_expires_at' => '',
    );

    $opts = get_option('tk_options', array());
    if (!is_array($opts)) { $opts = array(); }
    $merged = array_merge($defaults, $opts);
    update_option('tk_options', $merged, false);
}

function tk_run_versioned_upgrades(): void {
    $stored_version = (string) get_option('tk_version', '');
    tk_option_init_defaults();
    tk_upgrade_antispam_duplicate_window_default();
    if ($stored_version === '' || version_compare($stored_version, '2.2.0', '<')) {
        tk_upgrade_to_220();
    }
    update_option('tk_version', defined('TK_VERSION') ? (string) TK_VERSION : '0.0.0', false);
}

function tk_upgrade_antispam_duplicate_window_default(): void {
    if ((int) tk_get_option('antispam_duplicate_window_default_5_migrated', 0) === 1) {
        return;
    }

    if ((int) tk_get_option('antispam_duplicate_window_minutes', 5) === 30) {
        tk_update_option('antispam_duplicate_window_minutes', 5);
    }

    tk_update_option('antispam_duplicate_window_default_5_migrated', 1);
}

function tk_upgrade_to_220(): void {
    $collector_url = trim((string) tk_get_option('heartbeat_collector_url', ''));
    if ($collector_url === '' && defined('TK_HEARTBEAT_URL') && TK_HEARTBEAT_URL !== '') {
        $collector_url = trim((string) TK_HEARTBEAT_URL);
        if ($collector_url !== '') {
            tk_update_option('heartbeat_collector_url', $collector_url);
        }
    }

    $license_server_url = trim((string) tk_get_option('license_server_url', ''));
    if ($license_server_url === '' && $collector_url !== '') {
        $license_server_url = tk_toolkits_license_server_url_for_collector($collector_url);
        if ($license_server_url !== '') {
            tk_update_option('license_server_url', $license_server_url);
        }
    }

    if ((int) tk_get_option('heartbeat_last_checked', 0) <= 0) {
        tk_update_option('heartbeat_last_checked', 0);
    }
    if ((int) tk_get_option('heartbeat_last_success', 0) <= 0) {
        tk_update_option('heartbeat_last_success', 0);
    }
    if ((int) tk_get_option('heartbeat_last_failure', 0) <= 0) {
        tk_update_option('heartbeat_last_failure', 0);
    }
    if (!is_string(tk_get_option('heartbeat_last_error_message', ''))) {
        tk_update_option('heartbeat_last_error_message', '');
    }
    if (!is_string(tk_get_option('heartbeat_last_endpoint', ''))) {
        tk_update_option('heartbeat_last_endpoint', '');
    }
    if ((int) tk_get_option('license_last_success', 0) <= 0) {
        tk_update_option('license_last_success', 0);
    }
    if ((int) tk_get_option('license_last_failure', 0) <= 0) {
        tk_update_option('license_last_failure', 0);
    }
    if (!is_string(tk_get_option('license_last_error_message', ''))) {
        tk_update_option('license_last_error_message', '');
    }
    if (!is_string(tk_get_option('license_last_endpoint', ''))) {
        tk_update_option('license_last_endpoint', '');
    }
}

/**
 * Admin UI helpers
 */
function tk_admin_url($page) {
    return admin_url('admin.php?page=' . urlencode($page));
}

function tk_hardening_page_slug(): string {
    return 'tool-kits-guard';
}

function tk_hardening_fallback_slug(): string {
    return tk_hardening_page_slug();
}

function tk_toolkits_hardening_admin_bypass(): bool {
    if (!is_admin() || !is_user_logged_in() || !current_user_can('manage_options')) {
        return false;
    }
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if (in_array($page, array(tk_hardening_page_slug(), 'tkg', 'tool-kits-security-hardening'), true)) {
        return true;
    }
    $action = isset($_REQUEST['action']) ? sanitize_key($_REQUEST['action']) : '';
    return in_array($action, array('tk_hardening_save', 'tk_hardening_waf_reset'), true);
}

function tk_is_admin_user() {
    return tk_toolkits_hardening_admin_bypass() || tk_toolkits_can_manage();
}

function tk_nonce_field($action) {
    wp_nonce_field($action, '_tk_nonce');
}

function tk_check_nonce($action) {
    $nonce = '';
    if (isset($_POST['_tk_nonce'])) {
        $nonce = (string) $_POST['_tk_nonce'];
    } elseif (isset($_REQUEST['_tk_nonce'])) {
        $nonce = (string) $_REQUEST['_tk_nonce'];
    }
    if ($nonce === '' || !wp_verify_nonce($nonce, $action)) {
        wp_die(__('Security check failed.', 'tool-kits'));
    }
}

function tk_require_admin_post(string $nonce_action): void {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }

    tk_check_nonce($nonce_action);
}

function tk_notice($message, $type = 'success') {
    if (empty($message) || trim($message) === '') {
        return;
    }
    $type = in_array($type, array('success','info','warning','error'), true) ? $type : 'success';
    printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), wp_kses_post($message));
}

function tk_csp_nonce_enabled(): bool {
    if (!tk_get_option('hardening_security_headers', 1)) {
        return false;
    }
    return (int) tk_get_option('hardening_csp_lite_enabled', 0) === 1
        || (int) tk_get_option('hardening_csp_balanced_enabled', 0) === 1
        || (int) tk_get_option('hardening_csp_hardened_enabled', 0) === 1
        || (int) tk_get_option('hardening_csp_strict_enabled', 0) === 1;
}

function tk_csp_nonce(): string {
    static $nonce = null;
    if (!tk_csp_nonce_enabled()) {
        return '';
    }
    if ($nonce === null) {
        try {
            $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        } catch (Exception $e) {
            $nonce = wp_generate_password(24, false, false);
        }
    }
    return $nonce;
}

function tk_csp_nonce_attr(): string {
    $nonce = tk_csp_nonce();
    return $nonce !== '' ? ' nonce="' . esc_attr($nonce) . '"' : '';
}

function tk_csp_add_nonce_to_tag(string $tag, string $element = 'script'): string {
    if ($tag === '' || !tk_csp_nonce_enabled()) {
        return $tag;
    }
    if (stripos($tag, '<' . $element) === false || stripos($tag, ' nonce=') !== false) {
        return $tag;
    }
    return preg_replace('/<' . preg_quote($element, '/') . '\b/i', '<' . $element . tk_csp_nonce_attr(), $tag, 1) ?: $tag;
}

function tk_csp_print_inline_script(string $script, array $attrs = array()): void {
    $attr_html = '';
    foreach ($attrs as $name => $value) {
        $name = sanitize_key((string) $name);
        if ($name === '') {
            continue;
        }
        $attr_html .= ' ' . $name . '="' . esc_attr((string) $value) . '"';
    }
    echo '<script' . tk_csp_nonce_attr() . $attr_html . '>' . "\n" . $script . "\n</script>\n";
}

function tk_csp_print_inline_style(string $css, array $attrs = array()): void {
    $attr_html = '';
    foreach ($attrs as $name => $value) {
        $name = sanitize_key((string) $name);
        if ($name === '') {
            continue;
        }
        $attr_html .= ' ' . $name . '="' . esc_attr((string) $value) . '"';
    }
    echo '<style' . tk_csp_nonce_attr() . $attr_html . '>' . "\n" . $css . "\n</style>\n";
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
    if (tk_toolkits_hardening_admin_bypass()) {
        return true;
    }
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

function tk_toolkits_collector_url(): string {
    $url = trim((string) tk_get_option('heartbeat_collector_url', ''));
    if ($url === '' && defined('TK_HEARTBEAT_URL') && TK_HEARTBEAT_URL !== '') {
        $url = trim((string) TK_HEARTBEAT_URL);
    }
    if ($url !== '' && $url !== (string) tk_get_option('heartbeat_collector_url', '')) {
        tk_update_option('heartbeat_collector_url', $url);
    }
    return $url;
}

function tk_toolkits_heartbeat_url_for_license_server(string $license_url): string {
    $license_url = trim($license_url);
    if ($license_url === '') {
        return '';
    }
    if (substr($license_url, -11) === 'license.php') {
        return substr($license_url, 0, -11) . 'heartbeat.php';
    }
    return rtrim($license_url, '/') . '/heartbeat.php';
}

function tk_heartbeat_collector_url(): string {
    if (defined('TK_HEARTBEAT_URL') && TK_HEARTBEAT_URL !== '') {
        return TK_HEARTBEAT_URL;
    }
    return 'https://nexamonitor.theteamtheteam.com/api/toolkits/heartbeat';
}


function tk_heartbeat_auth_key(): string {
    $secret = trim((string) tk_get_option('heartbeat_auth_key', ''));
    if ($secret === '' && defined('TK_HEARTBEAT_AUTH_KEY') && TK_HEARTBEAT_AUTH_KEY !== '') {
        $secret = trim((string) TK_HEARTBEAT_AUTH_KEY);
    }
    return $secret;
}

function tk_license_server_url(): string {
    $url = trim((string) tk_get_option('license_server_url', ''));
    if ($url === '' && defined('TK_LICENSE_SERVER_URL') && TK_LICENSE_SERVER_URL !== '') {
        $url = trim((string) TK_LICENSE_SERVER_URL);
    }
    if ($url === '') {
        $url = tk_toolkits_license_server_url_for_collector(tk_heartbeat_collector_url());
    }
    if ($url !== '' && $url !== (string) tk_get_option('license_server_url', '')) {
        tk_update_option('license_server_url', $url);
    }
    return $url;
}

function tk_license_server_url_from_collector($collector_url): string {
    $collector_url = is_string($collector_url) ? trim($collector_url) : '';
    if ($collector_url === '') {
        return '';
    }
    if (substr($collector_url, -13) === 'heartbeat.php') {
        return substr($collector_url, 0, -13) . 'license.php';
    }
    return rtrim($collector_url, '/') . '/license.php';
}

function tk_toolkits_license_server_url_for_collector(string $collector_url = ''): string {
    if (defined('TK_LICENSE_SERVER_URL') && TK_LICENSE_SERVER_URL !== '') {
        return trim((string) TK_LICENSE_SERVER_URL);
    }
    if ($collector_url === '') {
        $collector_url = tk_toolkits_collector_url();
    }
    return tk_license_server_url_from_collector($collector_url);
}

function tk_license_normalize_expires_at($value): string {
    if (!is_scalar($value)) {
        return '';
    }
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    return strtotime($value) !== false ? $value : '';
}

function tk_toolkits_missing_config_message(string $field): string {
    $messages = array(
        'collector_url' => 'Collector URL is missing.',
        'collector_token' => 'Collector token is missing.',
        'license_key' => 'License key is missing.',
        'license_server_url' => 'License server URL is missing.',
    );
    return isset($messages[$field]) ? $messages[$field] : 'Required configuration is missing.';
}

function tk_toolkits_license_validation_message(string $detail = ''): string {
    $detail = trim($detail);
    if ($detail === '') {
        return 'License validation failed.';
    }
    if (strpos($detail, 'License validation failed:') === 0) {
        return $detail;
    }
    return 'License validation failed: ' . $detail;
}

function tk_toolkits_license_reachability_message(int $code, string $detail = ''): string {
    $detail = trim($detail);
    $message = 'License server is reachable';
    if ($code > 0) {
        $message .= ' (HTTP ' . $code . ')';
    }
    if ($detail !== '') {
        $message .= ': ' . $detail;
    } else {
        $message .= '.';
    }
    return $message;
}

function tk_license_maybe_notify($old_status, $new_status, $message) {
    if ($old_status === $new_status && $new_status === 'valid') {
        return; // No change in valid status
    }

    $email = tk_get_option('license_notify_email', '');
    if ($email === '') {
        $email = tk_get_option('monitoring_alert_email', get_option('admin_email'));
    }

    if (!is_email($email)) {
        return;
    }

    $site_name = get_bloginfo('name');
    $site_url = home_url();
    $subject = '';
    $body = '';

    if ($new_status === 'valid' && $old_status !== 'valid') {
        $subject = "[Tool Kits] License Activated: {$site_name}";
        $body = "A new license has been successfully activated for {$site_name} ({$site_url}).\n\nStatus: Valid\nMessage: {$message}";
    } elseif ($new_status !== 'valid' && $old_status === 'valid') {
        $subject = "[Tool Kits] ALERT: License Error on {$site_name}";
        $body = "The license for {$site_name} ({$site_url}) is no longer valid or encountered an error.\n\nNew Status: " . strtoupper($new_status) . "\nError Message: {$message}\n\nPlease check your license settings in the WordPress admin.";
    } elseif ($new_status === 'error' || $new_status === 'invalid') {
        // We could notify on error if it was never valid, but usually activation handler handles that UI-wise.
        return;
    }

    if ($subject !== '') {
        wp_mail($email, $subject, $body);
    }
}

function tk_license_update_state(array $state): void {
    $old_status = (string) tk_get_option('license_status', 'inactive');
    
    $allowed = array(
        'license_status',
        'license_message',
        'license_last_checked',
        'license_env',
        'license_type',
        'license_site_url',
        'license_expires_at',
    );
    foreach ($state as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            continue;
        }
        tk_update_option($key, $value);
    }

    $new_status = isset($state['license_status']) ? (string) $state['license_status'] : $old_status;
    $message = isset($state['license_message']) ? (string) $state['license_message'] : '';

    if ($old_status !== $new_status) {
        tk_license_maybe_notify($old_status, $new_status, $message);
    }
}

function tk_heartbeat_record_diagnostic_result(array $result, string $endpoint = ''): void {
    $now = time();
    $message = isset($result['message']) ? trim((string) $result['message']) : '';
    tk_update_option('heartbeat_last_checked', $now);
    tk_update_option('heartbeat_last_endpoint', $endpoint);
    if (!empty($result['ok'])) {
        tk_update_option('heartbeat_last_success', $now);
        tk_update_option('heartbeat_last_error_message', '');
        return;
    }
    tk_update_option('heartbeat_last_failure', $now);
    tk_update_option('heartbeat_last_error_message', $message);
}

function tk_license_record_diagnostic_result(array $result, string $endpoint = ''): void {
    $now = time();
    $message = isset($result['message']) ? trim((string) $result['message']) : '';
    tk_update_option('license_last_checked', $now);
    tk_update_option('license_last_endpoint', $endpoint);
    if (isset($result['status']) && (string) $result['status'] === 'valid') {
        tk_update_option('license_last_success', $now);
        tk_update_option('license_last_error_message', '');
        return;
    }
    tk_update_option('license_last_failure', $now);
    tk_update_option('license_last_error_message', $message);
}

function tk_license_reset(): void {
    tk_update_option('license_key', '');
    tk_update_option('license_server_url', '');
    tk_update_option('heartbeat_auth_key', '');
    tk_update_option('heartbeat_last_checked', 0);
    tk_update_option('heartbeat_last_success', 0);
    tk_update_option('heartbeat_last_failure', 0);
    tk_update_option('heartbeat_last_error_message', '');
    tk_update_option('heartbeat_last_endpoint', '');
    tk_license_update_state(array(
        'license_status' => 'inactive',
        'license_message' => '',
        'license_last_checked' => 0,
        'license_last_success' => 0,
        'license_last_failure' => 0,
        'license_last_error_message' => '',
        'license_last_endpoint' => '',
        'license_env' => '',
        'license_type' => '',
        'license_site_url' => '',
        'license_expires_at' => '',
    ));
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
    $url = trim(tk_license_server_url());
    $secret = tk_heartbeat_auth_key();
    if ($key === '' && $url === '' && $secret === '') {
        tk_license_update_state(array(
            'license_status' => 'inactive',
            'license_message' => '',
            'license_last_checked' => 0,
            'license_env' => '',
            'license_type' => '',
            'license_site_url' => '',
            'license_expires_at' => '',
        ));
        return array('status' => 'inactive', 'message' => '');
    }
    if ($key === '') {
        $result = array('status' => 'missing', 'message' => tk_toolkits_missing_config_message('license_key'));
        tk_license_update_state(array(
            'license_status' => 'missing',
            'license_message' => $result['message'],
            'license_last_checked' => time(),
        ));
        tk_license_record_diagnostic_result($result, $url);
        return $result;
    }
    if ($url === '') {
        $collector_url = tk_heartbeat_collector_url();
        if ($collector_url !== '') {
            $url = tk_toolkits_license_server_url_for_collector($collector_url);
            tk_update_option('license_server_url', $url);
        }
    }
    if ($url === '') {
        $message = tk_heartbeat_collector_url() === '' ? tk_toolkits_missing_config_message('collector_url') : tk_toolkits_missing_config_message('license_server_url');
        $result = array('status' => 'missing_server', 'message' => $message);
        tk_license_update_state(array(
            'license_status' => 'missing_server',
            'license_message' => $message,
            'license_last_checked' => time(),
        ));
        tk_license_record_diagnostic_result($result, '');
        return $result;
    }
    if ($secret === '') {
        $result = array('status' => 'missing_secret', 'message' => tk_toolkits_missing_config_message('collector_token'));
        tk_license_update_state(array(
            'license_status' => 'missing_secret',
            'license_message' => $result['message'],
            'license_last_checked' => time(),
        ));
        tk_license_record_diagnostic_result($result, $url);
        return $result;
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
        $result = array('status' => 'error', 'message' => tk_toolkits_license_validation_message('failed to encode request payload.'));
        tk_license_update_state(array(
            'license_status' => 'error',
            'license_message' => $result['message'],
            'license_last_checked' => time(),
        ));
        tk_license_record_diagnostic_result($result, $url);
        return $result;
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
    $ssl_verify = (bool) tk_get_option('license_ssl_verify', 1);
    $response = wp_remote_post($url, array(
        'timeout' => 15,
        'headers' => $headers,
        'body' => $body,
        'sslverify' => $ssl_verify,
    ));
    if (is_wp_error($response)) {
        $result = array('status' => 'error', 'message' => tk_toolkits_license_validation_message($response->get_error_message()));
        tk_license_update_state(array(
            'license_status' => 'error',
            'license_message' => $result['message'],
            'license_last_checked' => time(),
        ));
        tk_license_record_diagnostic_result($result, $url);
        return $result;
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
            $new_message = tk_toolkits_license_validation_message('HTTP ' . $code . ': ' . substr($detail, 0, 200));
        } else {
            $new_message = tk_toolkits_license_validation_message();
        }
    }
    if (!$ok && $new_message !== '') {
        $new_message = tk_toolkits_license_validation_message($new_message);
    }
    if (!$ok && $server_status !== '') {
        $new_status = $server_status;
    }
    $license_state = array(
        'license_status' => $new_status,
        'license_message' => $new_message,
        'license_last_checked' => time(),
        'license_env' => (string) $payload['env'],
    );
    if (is_array($data) && isset($data['license_type'])) {
        $license_state['license_type'] = (string) $data['license_type'];
    }
    if (is_array($data) && isset($data['site_url'])) {
        $license_state['license_site_url'] = (string) $data['site_url'];
    }
    if (is_array($data) && array_key_exists('expires_at', $data)) {
        $license_state['license_expires_at'] = tk_license_normalize_expires_at($data['expires_at']);
    } elseif (!$ok && in_array($server_status, array('revoked', 'not_found', 'expired'), true)) {
        $license_state['license_expires_at'] = '';
    }
    tk_license_update_state($license_state);
    tk_license_record_diagnostic_result(array(
        'status' => $new_status,
        'message' => $new_message,
    ), $url);
    return array('status' => $new_status, 'message' => $new_message);
}

function tk_license_test_connection(): array {
    $collector_url = tk_heartbeat_collector_url();
    $url = trim(tk_license_server_url());
    $secret = tk_heartbeat_auth_key();

    if ($collector_url === '') {
        $result = array('status' => 'missing_server', 'message' => tk_toolkits_missing_config_message('collector_url'));
        tk_license_record_diagnostic_result($result, '');
        return $result;
    }
    if ($url === '') {
        $result = array('status' => 'missing_server', 'message' => tk_toolkits_missing_config_message('license_server_url'));
        tk_license_record_diagnostic_result($result, '');
        return $result;
    }
    if ($secret === '') {
        $result = array('status' => 'missing_secret', 'message' => tk_toolkits_missing_config_message('collector_token'));
        tk_license_record_diagnostic_result($result, $url);
        return $result;
    }

    $payload = array(
        'action' => 'reachability_check',
        'site_url' => home_url('/'),
        'site_id' => tk_toolkits_install_id(),
        'env' => tk_license_env(),
        'timestamp' => time(),
    );
    $body = wp_json_encode($payload);
    if ($body === false) {
        $result = array('status' => 'error', 'message' => tk_toolkits_license_validation_message('failed to encode request payload.'));
        tk_license_record_diagnostic_result($result, $url);
        return $result;
    }

    $headers = array(
        'Content-Type' => 'application/json',
        'X-Auth-Signature' => hash_hmac('sha256', $body, $secret),
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
        $result = array('status' => 'error', 'message' => tk_toolkits_license_validation_message($response->get_error_message()));
        tk_license_record_diagnostic_result($result, $url);
        return $result;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $result = array(
        'status' => 'valid',
        'message' => tk_toolkits_license_reachability_message($code),
    );
    if ($code <= 0) {
        $result = array(
            'status' => 'error',
            'message' => tk_toolkits_license_validation_message('received an invalid HTTP response.'),
        );
        tk_license_record_diagnostic_result($result, $url);
        return $result;
    }
    if ($code >= 200 && $code < 400) {
        tk_license_record_diagnostic_result($result, $url);
        return $result;
    }

    $detail = trim((string) wp_remote_retrieve_response_message($response));
    $raw = trim((string) wp_remote_retrieve_body($response));
    if ($raw !== '') {
        $detail = $detail !== '' ? $detail . ' ' . substr(strip_tags($raw), 0, 160) : substr(strip_tags($raw), 0, 160);
    }
    $result = array(
        'status' => 'reachable',
        'message' => tk_toolkits_license_reachability_message($code, $detail),
    );
    tk_license_record_diagnostic_result($result, $url);
    return $result;
}

function tk_license_is_valid(): bool {
    $status = (string) tk_get_option('license_status', 'inactive');
    $last_checked = (int) tk_get_option('license_last_checked', 0);
    return $status === 'valid' && $last_checked > 0 && (time() - $last_checked) < DAY_IN_SECONDS;
}

function tk_license_features_enabled(): bool {
    return (string) tk_get_option('license_status', 'inactive') === 'valid';
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
    if ($page === 'tool-kits-security-hardening' || $page === 'tkg') {
        $target = admin_url('admin.php?page=' . urlencode(tk_hardening_page_slug()));
        if (!empty($_SERVER['QUERY_STRING'])) {
            $query = wp_unslash((string) $_SERVER['QUERY_STRING']);
            parse_str($query, $params);
            if (is_array($params)) {
                unset($params['page']);
                if (!empty($params)) {
                    $target = add_query_arg($params, $target);
                }
            }
        }
        wp_safe_redirect($target);
        exit;
    }
    $action = isset($_REQUEST['action']) ? sanitize_key($_REQUEST['action']) : '';
    $is_toolkits_page = $page !== '' && strpos($page, 'tool-kits') === 0;
    $is_toolkits_action = $action !== '' && strpos($action, 'tk_') === 0;
    $license_exempt_pages = array('tool-kits-db');
    $license_exempt_actions = array(
        'tk_db_export',
        'tk_db_run_replace',
        'tk_db_download_temp_export',
        'tk_db_change_prefix',
        'tk_db_import',
        'tk_toolkits_license_activate',
        'tk_toolkits_license_reset',
    );
    $license_setup_actions = array(
        'tk_toolkits_access_save',
        'tk_toolkits_license_activate',
        'tk_toolkits_license_reset',
    );
    $is_license_exempt = in_array($page, $license_exempt_pages, true) || in_array($action, $license_exempt_actions, true);
    if (!$is_toolkits_page && !$is_toolkits_action) {
        return;
    }
    if (!tk_toolkits_can_manage()) {
        $message = '<h1>Access Restricted</h1><p>Tool Kits access is restricted for your account.</p><p><a href="' . esc_url(admin_url('tools.php?page=tool-kits-access')) . '">Go to Tool Kits Access</a></p>';
        wp_die($message, 'Tool Kits', array('response' => 403));
    }
    $collector_key = tk_heartbeat_auth_key();
    if ($collector_key === '' && $page !== 'tool-kits-access' && !in_array($action, $license_setup_actions, true)) {
        $message = '<h1>Collector Token Required</h1><p>Please set the collector token in Tool Kits Access.</p><p><a href="' . esc_url(admin_url('tools.php?page=tool-kits-access')) . '">Open Tool Kits Access</a></p>';
        wp_die($message, 'Tool Kits', array('response' => 403));
    }
    if ($page === 'tool-kits-access' || in_array($action, $license_setup_actions, true) || $is_license_exempt) {
        $license_reset = isset($_GET['tk_reset_license']) ? sanitize_key((string) $_GET['tk_reset_license']) : '';
        if ($license_reset === '1' || (int) get_option('tk_license_reset_skip_validate', 0) === 1) {
            delete_option('tk_license_reset_skip_validate');
            return;
        }
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
    tk_csp_print_inline_script(
        "(function(){
            var selectors = [
                'input[name*=\"key\"]',
                'input[name*=\"pass\"]',
                'textarea[name*=\"key\"]',
                'textarea[name*=\"pass\"]'
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
        })();",
        array('id' => 'tk-mask-sensitive-fields')
    );
}

function tk_toolkits_confirm_actions_script(): void {
    if (!is_admin()) {
        return;
    }

    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if ($page === '' || strpos($page, 'tool-kits') !== 0) {
        return;
    }

    tk_csp_print_inline_script(
        "(function(){
            document.addEventListener('submit', function(event){
                var form = event.target;
                if (!form || form.nodeName !== 'FORM') {
                    return;
                }
                var trigger = document.activeElement;
                var message = '';

                if (trigger && form.contains(trigger) && trigger.hasAttribute('data-confirm')) {
                    message = trigger.getAttribute('data-confirm') || '';
                } else if (form.hasAttribute('data-confirm')) {
                    message = form.getAttribute('data-confirm') || '';
                }

                if (message && !window.confirm(message)) {
                    event.preventDefault();
                }
            }, true);
        })();",
        array('id' => 'tk-confirm-actions')
    );
}

function tk_user_agent() {
    return !empty($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '';
}

function tk_toolkits_access_denied_page(): void {
    if (!is_admin()) {
        return;
    }
    if (tk_toolkits_hardening_admin_bypass()) {
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

/**
 * Keep taxonomy term checklists in the original order instead of moving checked terms to the top.
 */
function tk_terms_checklist_keep_order(array $args, $post_id) {
    $args['checked_ontop'] = false;
    $args['popular_cats'] = array();
    return $args;
}
add_filter('wp_terms_checklist_args', 'tk_terms_checklist_keep_order', 10, 2);
function tk_get_debug_info(): string {
    global $wp_version;
    $info = array();
    $info[] = '### Tool Kits Debug Info ###';
    $info[] = 'Time: ' . date('Y-m-d H:i:s') . ' (UTC ' . date('P') . ')';
    $info[] = 'Site URL: ' . home_url();
    $info[] = 'WP Version: ' . $wp_version;
    $info[] = 'PHP Version: ' . PHP_VERSION;
    $info[] = 'Server: ' . (isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : 'Unknown');
    $info[] = 'cURL Version: ' . (function_exists('curl_version') ? (string) curl_version()['version'] : 'Disabled');
    $info[] = 'OpenSSL Version: ' . (defined('OPENSSL_VERSION_TEXT') ? (string) OPENSSL_VERSION_TEXT : 'Unknown');
    $info[] = 'Tool Kits Version: ' . (defined('TK_VERSION') ? (string) TK_VERSION : 'Unknown');
    $info[] = 'License Status: ' . (string) tk_get_option('license_status', 'inactive');
    
    $heartbeat_err = (string) tk_get_option('heartbeat_last_error_message', '');
    $license_err = (string) tk_get_option('license_last_error_message', '');
    
    if ($heartbeat_err !== '') {
        $info[] = 'Last Heartbeat Error: ' . $heartbeat_err;
    }
    if ($license_err !== '') {
        $info[] = 'Last License Error: ' . $license_err;
    }
    
    return implode("\n", $info);
}

function tk_monitoring_get_largest_files($limit = 10) {
    $cache_key = 'tk_largest_files_cache_' . $limit;
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $root = ABSPATH;
    $largest = array();
    
    try {
        if (!is_dir($root)) return array();
        
        $iterator = new DirectoryIterator($root);
        
        foreach ($iterator as $file) {
            if ($file->isDot() || !$file->isFile()) continue;
            
            $path = $file->getPathname();
            $normalized_path = wp_normalize_path($path);
            
            $size = (int) $file->getSize();
            $largest[] = array(
                'path' => str_replace(wp_normalize_path($root), '', $normalized_path),
                'size' => $size
            );
        }
        usort($largest, function($a, $b) {
            return $b['size'] <=> $a['size'];
        });
        
        $result = array_slice($largest, 0, $limit);
        set_transient($cache_key, $result, HOUR_IN_SECONDS);
        return $result;
    } catch (Exception $e) {
        return array();
    }
}

/**
 * Load a template file with extracted variables.
 */
function tk_get_template($template_name, $args = array()) {
    if (!empty($args) && is_array($args)) {
        extract($args);
    }
    $template_path = TK_PATH . 'templates/' . $template_name . '.php';
    if (file_exists($template_path)) {
        include $template_path;
    } else {
        tk_log('Template not found: ' . $template_path);
    }
}

/**
 * Renders a consistent UI switch for settings.
 */
function tk_render_switch($name, $label, $description, $checked, $confirm = '') {
    ?>
    <div class="tk-control-row">
        <div class="tk-control-info">
            <label for="tk-sw-<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label>
            <p class="description"><?php echo esc_html($description); ?></p>
        </div>
        <label class="tk-switch">
            <input type="checkbox" id="tk-sw-<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>" value="1" <?php checked(1, $checked); ?> <?php echo $confirm ? 'data-confirm="' . esc_attr($confirm) . '"' : ''; ?>>
            <span class="tk-slider"></span>
        </label>
    </div>
    <?php
}
