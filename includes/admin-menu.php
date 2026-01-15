<?php
if (!defined('ABSPATH')) { exit; }

function tk_admin_menu_init() {
    add_action('admin_menu', 'tk_register_admin_menus');
}

function tk_register_admin_menus() {
    if (!current_user_can('manage_options')) return;

    // Parent
    add_menu_page(
        __('Tool Kits', 'tool-kits'),
        __('Tool Kits', 'tool-kits'),
        'manage_options',
        'tool-kits',
        'tk_render_overview_page',
        'dashicons-admin-tools',
        59
    );

    // DB
    add_submenu_page('tool-kits', __('DB Migrate', 'tool-kits'), __('DB Migrate', 'tool-kits'), 'manage_options', 'tool-kits-db-migrate', 'tk_render_db_migrate_page');
    add_submenu_page('tool-kits', __('DB Cleanup', 'tool-kits'), __('DB Cleanup', 'tool-kits'), 'manage_options', 'tool-kits-db-cleanup', 'tk_render_db_cleanup_page');

    // Security modules now live under the main Tool Kits menu.
    add_submenu_page('tool-kits', __('Hide Login', 'tool-kits'), __('Hide Login', 'tool-kits'), 'manage_options', 'tool-kits-security-hide-login', 'tk_render_hide_login_page');
    add_submenu_page('tool-kits', __('Captcha', 'tool-kits'), __('Captcha', 'tool-kits'), 'manage_options', 'tool-kits-security-captcha', 'tk_render_captcha_page');
    add_submenu_page('tool-kits', __('Anti-spam Contact', 'tool-kits'), __('Anti-spam Contact', 'tool-kits'), 'manage_options', 'tool-kits-security-antispam', 'tk_render_antispam_contact_page');
    add_submenu_page('tool-kits', __('Rate Limit', 'tool-kits'), __('Rate Limit', 'tool-kits'), 'manage_options', 'tool-kits-security-rate-limit', 'tk_render_rate_limit_page');
    add_submenu_page('tool-kits', __('Login Log', 'tool-kits'), __('Login Log', 'tool-kits'), 'manage_options', 'tool-kits-security-login-log', 'tk_render_login_log_page');
    add_submenu_page('tool-kits', __('Hardening', 'tool-kits'), __('Hardening', 'tool-kits'), 'manage_options', 'tool-kits-security-hardening', 'tk_render_hardening_page');
}

function tk_render_overview_page() {
    if (!tk_is_admin_user()) return;
    ?>
    <div class="wrap tk-wrap">
        <h1>Tool Kits</h1>
        <div class="tk-card" style="margin-top:24px;">
            <h2>Security Modules</h2>
            <p class="description">Control each module directly from this page.</p>
            <?php tk_render_security_table(); ?>
        </div>
    </div>
    <?php
}

function tk_render_security_table() {
    ?>
    <table class="widefat striped tk-table">
        <thead><tr><th>Module</th><th>Status</th><th>Shortcut</th></tr></thead>
        <tbody>
            <tr><td>Hide Login</td><td><?php echo tk_get_option('hide_login_enabled',0) ? '<span class="tk-badge tk-on">ON</span>' : '<span class="tk-badge">OFF</span>'; ?></td><td><a href="<?php echo esc_url(tk_admin_url('tool-kits-security-hide-login')); ?>">Settings</a></td></tr>
            <tr><td>Captcha</td><td><?php echo tk_get_option('captcha_enabled',0) ? '<span class="tk-badge tk-on">ON</span>' : '<span class="tk-badge">OFF</span>'; ?></td><td><a href="<?php echo esc_url(tk_admin_url('tool-kits-security-captcha')); ?>">Settings</a></td></tr>
            <tr><td>Anti-spam Contact</td><td><?php echo tk_get_option('antispam_cf7_enabled',0) ? '<span class="tk-badge tk-on">ON</span>' : '<span class="tk-badge">OFF</span>'; ?></td><td><a href="<?php echo esc_url(tk_admin_url('tool-kits-security-antispam')); ?>">Settings</a></td></tr>
            <tr><td>Rate Limit</td><td><?php echo tk_get_option('rate_limit_enabled',0) ? '<span class="tk-badge tk-on">ON</span>' : '<span class="tk-badge">OFF</span>'; ?></td><td><a href="<?php echo esc_url(tk_admin_url('tool-kits-security-rate-limit')); ?>">Settings</a></td></tr>
            <tr><td>Login Log</td><td><?php echo tk_get_option('login_log_enabled',1) ? '<span class="tk-badge tk-on">ON</span>' : '<span class="tk-badge">OFF</span>'; ?></td><td><a href="<?php echo esc_url(tk_admin_url('tool-kits-security-login-log')); ?>">View</a></td></tr>
            <tr><td>Hardening</td><td><?php echo (tk_get_option('hardening_disable_file_editor',1) || tk_get_option('hardening_disable_xmlrpc',1) || tk_get_option('hardening_security_headers',1)) ? '<span class="tk-badge tk-on">ACTIVE</span>' : '<span class="tk-badge">OFF</span>'; ?></td><td><a href="<?php echo esc_url(tk_admin_url('tool-kits-security-hardening')); ?>">Settings</a></td></tr>
        </tbody>
    </table>
    <?php
}
