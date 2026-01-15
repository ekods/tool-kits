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

require_once TK_PATH . 'includes/helpers.php';
require_once TK_PATH . 'includes/admin-menu.php';
require_once TK_PATH . 'includes/db-migrate.php';
require_once TK_PATH . 'includes/db-cleanup.php';
require_once TK_PATH . 'includes/security-hide-login.php';
require_once TK_PATH . 'includes/security-captcha.php';
require_once TK_PATH . 'includes/antispam-contact.php';
require_once TK_PATH . 'includes/security-rate-limit.php';
require_once TK_PATH . 'includes/security-login-log.php';
require_once TK_PATH . 'includes/security-hardening.php';

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
}
register_activation_hook(__FILE__, 'tk_activate');

function tk_deactivate() {
    // Flush rewrite rules so custom login slug is removed cleanly
    tk_hide_login_flush_rewrite(false);
}
register_deactivation_hook(__FILE__, 'tk_deactivate');

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
});

/**
 * Load admin assets
 */
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'tool-kits') !== false) {
        wp_enqueue_style('tool-kits-admin', TK_URL . 'assets/admin.css', array(), TK_VERSION);
    }
});