<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Uninstall cleanup. Loaded only from tk_uninstall(), and it removes data only when an administrator turned on
 * General → Maintenance → "Delete all data on uninstall" (option tk_uninstall_delete_data, outside tk_options so
 * a settings import cannot turn it on). Deactivation already removed the cache drop-in and scheduled jobs.
 * Hardening rules written to .htaccess and optimized image copies in uploads are left in place.
 */

// Options Tool Kits creates outside tk_options; prefixes are specific enough not to match other plugins.
function tk_uninstall_option_names(): array {
    return array(
        'tk_options', 'tk_version', 'tk_uninstall_delete_data', 'tk_server_timezone', 'tk_sessions_invalidated_for_version',
        'tk_license_reset_skip_validate', 'tk_github_updater_status', 'tk_hidden_admin_menus', 'tk_heartbeat_cron_metrics',
        'tk_firewall_event_log', 'tk_rate_limit_blocked_ips', 'tk_fim_last_scan_result', 'tk_fim_last_scan_time',
        'tk_db_cleanup_acf_last_run', 'tk_db_cleanup_revision_last_run', 'tk_login_log_schema_version',
        'tk_security_events_schema_version',
    );
}

function tk_uninstall_option_prefixes(): array {
    return array(
        'tk_jobs_',
        'tk_report_', 'tk_killswitch_', 'tk_html_scan_', 'tk_health_center_', 'tk_monitoring_', 'tk_audit_',
        'tk_page_cache_', 'tk_seo_sitemap_', 'tk_hardening_', '_tk_captcha_used_',
        '_transient_tk_', '_transient_timeout_tk_', '_site_transient_tk_', '_site_transient_timeout_tk_',
    );
}

function tk_uninstall_meta_prefixes(): array {
    return array('_tk_seo_', '_tk_geo_', '_tk_image_opt_', '_tk_webp_');
}

function tk_uninstall_delete_site_data(): void {
    global $wpdb;
    foreach (tk_uninstall_option_names() as $name) {
        delete_option($name);
    }
    foreach (tk_uninstall_option_prefixes() as $prefix) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($prefix) . '%'));
    }
    foreach (tk_uninstall_meta_prefixes() as $prefix) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like($prefix) . '%'));
    }
    foreach (array($wpdb->prefix . 'tk_login_log', $wpdb->prefix . 'tk_security_events') as $table) {
        $wpdb->query("DROP TABLE IF EXISTS `" . esc_sql($table) . "`");
    }
}

function tk_uninstall_delete_directory(string $dir): void {
    if ($dir === '' || !is_dir($dir) || is_link($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

function tk_uninstall_run(): void {
    if ((int) get_option('tk_uninstall_delete_data', 0) !== 1) {
        return;
    }
    if (is_multisite()) {
        foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $site_id) {
            switch_to_blog((int) $site_id);
            tk_uninstall_delete_site_data();
            restore_current_blog();
        }
    } else {
        tk_uninstall_delete_site_data();
    }
    delete_metadata('user', 0, 'tk_admin_login_ips', '', true);
    tk_uninstall_delete_directory(trailingslashit(WP_CONTENT_DIR) . 'cache/tool-kits');
}
