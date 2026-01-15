<?php
if (!defined('ABSPATH')) exit;

/**
 * Hide wp-login.php
 */

function tk_hide_login_init() {
    add_action('init', 'tk_hide_login_rewrite');
    add_action('template_redirect', 'tk_hide_login_block');
    add_action('admin_post_tk_hide_login_save', 'tk_hide_login_save');
}

function tk_hide_login_rewrite() {
    if (!tk_get_option('hide_login_enabled')) return;

    $slug = tk_sanitize_slug(tk_get_option('hide_login_slug', 'secure-login'));
    add_rewrite_rule("^{$slug}/?$", 'wp-login.php', 'top');
}

function tk_hide_login_block() {
    if (!tk_get_option('hide_login_enabled')) return;

    if (strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false) {
        wp_redirect(home_url());
        exit;
    }
}

function tk_hide_login_flush_rewrite($enable = true) {
    $slug = tk_sanitize_slug(tk_get_option('hide_login_slug', 'secure-login'));
    if ($enable && $slug !== '') {
        add_rewrite_rule("^{$slug}/?$", 'wp-login.php', 'top');
    }
    flush_rewrite_rules();
}

function tk_hide_login_save() {
    tk_check_nonce('tk_hide_login_save');

    tk_update_option('hide_login_enabled', !empty($_POST['enabled']) ? 1 : 0);
    tk_update_option('hide_login_slug', tk_sanitize_slug($_POST['slug']));

    flush_rewrite_rules();
    wp_redirect(admin_url('admin.php?page=tool-kits-security-hide-login'));
    exit;
}

function tk_render_hide_login_page() {
    if (!tk_is_admin_user()) return;

    $enabled = (int) tk_get_option('hide_login_enabled', 0);
    $slug = tk_get_option('hide_login_slug', 'secure-login');

    ?>
    <div class="wrap tk-wrap">
        <h1>Hide Login</h1>
        <div class="tk-card">
            <p>This module replaces the login URL with a custom slug and blocks direct hits to <code>wp-login.php</code>.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_hide_login_save'); ?>
            <input type="hidden" name="action" value="tk_hide_login_save">
            <label><input type="checkbox" name="enabled" value="1" <?php checked(1, $enabled); ?>> Enable Hide Login</label>
            <p>
                <strong>Custom slug</strong><br>
                <input type="text" name="slug" value="<?php echo esc_attr($slug); ?>" class="regular-text">
            </p>
            <p class="description">After saving, visit <code><?php echo esc_html(home_url('/' . trim($slug, '/') . '/')); ?></code> to login.</p>
            <p><button class="button button-primary">Save</button></p>
        </form>
        </div>
    </div>
    <?php
}
