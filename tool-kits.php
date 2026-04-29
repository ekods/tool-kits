<?php
/**
 * Plugin Name: Tool Kits
 * Description: Admin toolkit: DB migrate/export, DB cleanup, and security modules (hide login, captcha, antispam contact, rate limit, login log, hardening).
 * Version: 2.3.0
 * GitHub Plugin URI: https://github.com/ekods/tool-kits
 * Update URI: https://github.com/ekods/tool-kits
 * Author: Eko Dwi Saputro
 * License: GPLv2 or later
 * Text Domain: tool-kits
 */

if (!defined('ABSPATH')) { exit; }

define('TK_VERSION', '2.3.0');
define('TK_PATH', plugin_dir_path(__FILE__));
define('TK_URL', plugin_dir_url(__FILE__));
define('TK_SLUG', 'tool-kits');
define('TK_GITHUB_REPO', 'ekods/tool-kits');
define('TK_GITHUB_REPO_URL', 'https://github.com/' . TK_GITHUB_REPO);

if (!defined('TK_HEARTBEAT_URL')) {
    define('TK_HEARTBEAT_URL', '');
}
if (!defined('TK_HEARTBEAT_AUTH_KEY')) {
    define('TK_HEARTBEAT_AUTH_KEY', '');
}

/**
 * Load translations after init so the textdomain is available to helpers and admin UI.
 */
function tk_load_textdomain() {
    load_plugin_textdomain('tool-kits', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('init', 'tk_load_textdomain');
add_action('plugins_loaded', 'tk_killswitch_init', 1);
add_action('admin_init', 'tk_debug_deprecated_init');
add_action('admin_init', 'tk_toolkits_guard', 0);

/**
 * Module Registry
 * Map of module file paths to their initialization function (or false if none).
 */
global $tk_modules;
$tk_modules = array(
    'helpers.php'               => false,
    'admin-menu.php'            => 'tk_admin_menu_init',
    'db-migrate.php'            => 'tk_db_migrate_init',
    'db-cleanup.php'            => 'tk_db_cleanup_init',
    'security-hide-login.php'   => 'tk_hide_login_init',
    'security-captcha.php'      => 'tk_captcha_init',
    'antispam-contact.php'      => 'tk_antispam_contact_init',
    'security-form-guard.php'   => 'tk_form_guard_init',
    'security-spam.php'         => false,
    'security-rate-limit.php'   => 'tk_rate_limit_init',
    'security-login-log.php'    => 'tk_login_log_init',
    'security-hardening.php'    => 'tk_hardening_init',
    'smtp.php'                  => 'tk_smtp_init',
    'monitoring-heartbeat.php'  => 'tk_heartbeat_init',
    'minify.php'                => 'tk_minify_init',
    'cache.php'                 => 'tk_cache_init',
    'webp.php'                  => 'tk_webp_init',
    'image-optimizer.php'       => 'tk_image_opt_init',
    'seo-optimization.php'      => 'tk_seo_opt_init',
    'monitoring-404-health.php' => 'tk_monitoring_404_health_init',
    'optimization.php'          => false,
    'lazy-load.php'             => 'tk_lazy_load_init',
    'classic-editor.php'        => 'tk_classic_editor_init',
    'classic-widgets.php'       => 'tk_classic_widgets_init',
    'general.php'               => 'tk_general_init',
    'asset-optimization.php'    => 'tk_assets_opt_init',
    'upload-limits.php'         => 'tk_upload_limits_init',
    'theme-checker.php'         => false,
    'security-alerts.php'       => 'tk_security_alerts_init',
    'user-id-change.php'        => 'tk_user_id_change_init',
    'github-update-check.php'   => false,
    'security-fim.php'          => 'tk_fim_init',
    'analytics.php'             => 'tk_analytics_init',
    'dashboard-widget.php'      => 'tk_dashboard_widget_init',
);

// Require all modules dynamically
foreach ($tk_modules as $file => $init_func) {
    require_once TK_PATH . 'includes/' . $file;
}

/**
 * Activation / Deactivation
 */
function tk_activate() {
    // Default options
    tk_option_init_defaults();
    tk_run_versioned_upgrades();

    // Create login log table
    tk_login_log_install_table();

    // Hide login rewrite rules
    tk_hide_login_flush_rewrite(true);

    // Schedule heartbeat on activation (if enabled).
    if (function_exists('tk_heartbeat_schedule')) {
        tk_heartbeat_schedule();
    }
}
register_activation_hook(__FILE__, 'tk_activate');

function tk_deactivate() {
    // Flush rewrite rules so custom login slug is removed cleanly
    tk_hide_login_flush_rewrite(false);

}
register_deactivation_hook(__FILE__, 'tk_deactivate');

/**
 * Uninstall cleanup
 */
function tk_uninstall() {
}
register_uninstall_hook(__FILE__, 'tk_uninstall');

/**
 * Initialize modules
 */
add_action('plugins_loaded', function() {
    global $tk_modules;
    tk_run_versioned_upgrades();

    foreach ($tk_modules as $file => $init_func) {
        if ($init_func && function_exists($init_func)) {
            $init_func();
        }
    }
});

/**
 * Load admin assets
 */
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'tool-kits') !== false) {
        wp_enqueue_style('tool-kits-admin', TK_URL . 'assets/admin.css', array(), TK_VERSION);
        wp_enqueue_style('tool-kits-overview', TK_URL . 'assets/overview.css', array('tool-kits-admin'), TK_VERSION);
    }
});
add_action('admin_footer', 'tk_toolkits_mask_fields_script');
add_action('admin_footer', 'tk_toolkits_confirm_actions_script');
