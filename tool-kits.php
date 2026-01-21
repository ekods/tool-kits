<?php
/**
 * Plugin Name: Tool Kits
 * Description: Admin toolkit: DB migrate/export, DB cleanup, and security modules (hide login, captcha, antispam contact, rate limit, login log, hardening).
 * Version: 1.0.0
 * Author: Eko Dwi Saputro
 * License: GPLv2 or later
 * Text Domain: tool-kits
 */

if (!defined('ABSPATH')) { exit; }

define('TK_VERSION', '1.0.0');
define('TK_PATH', plugin_dir_path(__FILE__));
define('TK_URL', plugin_dir_url(__FILE__));
define('TK_SLUG', 'tool-kits');

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

require_once TK_PATH . 'includes/helpers.php';
require_once TK_PATH . 'includes/admin-menu.php';
require_once TK_PATH . 'includes/db-migrate.php';
require_once TK_PATH . 'includes/db-cleanup.php';
require_once TK_PATH . 'includes/security-hide-login.php';
require_once TK_PATH . 'includes/security-captcha.php';
require_once TK_PATH . 'includes/antispam-contact.php';
require_once TK_PATH . 'includes/security-spam.php';
require_once TK_PATH . 'includes/security-rate-limit.php';
require_once TK_PATH . 'includes/security-login-log.php';
require_once TK_PATH . 'includes/security-hardening.php';
require_once TK_PATH . 'includes/monitoring-heartbeat.php';
require_once TK_PATH . 'includes/minify.php';
require_once TK_PATH . 'includes/cache.php';
require_once TK_PATH . 'includes/webp.php';
require_once TK_PATH . 'includes/monitoring-404-health.php';
require_once TK_PATH . 'includes/optimization.php';
require_once TK_PATH . 'includes/lazy-load.php';
require_once TK_PATH . 'includes/asset-optimization.php';
require_once TK_PATH . 'includes/upload-limits.php';
require_once TK_PATH . 'includes/theme-checker.php';
require_once TK_PATH . 'includes/security-alerts.php';
require_once TK_PATH . 'includes/user-id-change.php';

/**
 * Activation / Deactivation
 */
function tk_activate() {
    // Default options
    tk_option_init_defaults();

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
    tk_admin_menu_init();
    tk_db_migrate_init();
    tk_db_cleanup_init();

    tk_hide_login_init();
    tk_captcha_init();
    tk_antispam_contact_init();
    tk_rate_limit_init();
    tk_login_log_init();
    tk_hardening_init();
    tk_heartbeat_init();
    tk_minify_init();
    tk_cache_init();
    tk_webp_init();
    tk_monitoring_404_health_init();
    tk_lazy_load_init();
    tk_assets_opt_init();
    tk_upload_limits_init();
    tk_security_alerts_init();
    tk_user_id_change_init();
});

/**
 * Load admin assets
 */
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'tool-kits') !== false) {
        wp_enqueue_style('tool-kits-admin', TK_URL . 'assets/admin.css', array(), TK_VERSION);
    }
});
add_action('admin_footer', 'tk_toolkits_mask_fields_script');
