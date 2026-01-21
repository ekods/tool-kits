<?php
if (!defined('ABSPATH')) exit;

/**
 * Hide wp-login.php
 */

function tk_hide_login_init() {
    add_action('init', 'tk_hide_login_rewrite');
    add_action('login_init', 'tk_hide_login_block_login');
    add_action('admin_init', 'tk_hide_login_block_admin', 1);
    add_action('template_redirect', 'tk_hide_login_block');
    add_action('admin_post_tk_hide_login_save', 'tk_hide_login_save');
    add_filter('login_url', 'tk_hide_login_filter_login_url', 10, 3);
    add_filter('logout_url', 'tk_hide_login_filter_logout_url', 10, 2);
    add_filter('lostpassword_url', 'tk_hide_login_filter_lostpassword_url', 10, 2);
    add_filter('register_url', 'tk_hide_login_filter_register_url', 10, 1);
}

function tk_hide_login_slug() {
    return tk_sanitize_slug(tk_get_option('hide_login_slug', 'secure-login'));
}

function tk_hide_login_slug_path() {
    $slug = tk_hide_login_slug();
    if ($slug === '') {
        return array();
    }
    $paths = array('/' . trim($slug, '/') . '/');
    $base = wp_parse_url(home_url('/'), PHP_URL_PATH);
    if (!empty($base) && $base !== '/') {
        $paths[] = rtrim($base, '/') . '/' . trim($slug, '/') . '/';
    }
    return array_values(array_unique($paths));
}

function tk_hide_login_normalize_path($path) {
    if ($path === '') {
        return '';
    }
    return rtrim($path, '/') . '/';
}

function tk_hide_login_is_slug_path($path) {
    $paths = tk_hide_login_slug_path();
    if (empty($paths)) {
        return false;
    }
    $normalized = tk_hide_login_normalize_path($path);
    return in_array($normalized, $paths, true);
}

function tk_hide_login_custom_url() {
    $slug = tk_hide_login_slug();
    if ($slug === '') {
        return home_url('/wp-login.php');
    }
    return home_url('/' . trim($slug, '/') . '/');
}

function tk_hide_login_swap_url_base($url) {
    if (!tk_get_option('hide_login_enabled')) {
        return $url;
    }
    $custom = tk_hide_login_custom_url();
    $target_parts = wp_parse_url($custom);
    $parts = wp_parse_url($url);
    if (empty($target_parts) || empty($parts)) {
        return $url;
    }

    $new = $target_parts['scheme'] . '://' . $target_parts['host'];
    if (!empty($target_parts['port'])) {
        $new .= ':' . $target_parts['port'];
    }
    $new .= $target_parts['path'] ?? '/';
    if (!empty($parts['query'])) {
        $new .= '?' . $parts['query'];
    }
    if (!empty($parts['fragment'])) {
        $new .= '#' . $parts['fragment'];
    }
    return $new;
}

function tk_hide_login_filter_login_url($login_url, $redirect, $force_reauth) {
    return tk_hide_login_swap_url_base($login_url);
}

function tk_hide_login_filter_logout_url($logout_url, $redirect) {
    return tk_hide_login_swap_url_base($logout_url);
}

function tk_hide_login_filter_lostpassword_url($lostpassword_url, $redirect) {
    return tk_hide_login_swap_url_base($lostpassword_url);
}

function tk_hide_login_filter_register_url($register_url) {
    return tk_hide_login_swap_url_base($register_url);
}

function tk_hide_login_rewrite() {
    if (!tk_get_option('hide_login_enabled')) return;

    $slug = tk_hide_login_slug();
    add_rewrite_rule("^{$slug}/?$", 'wp-login.php', 'top');
}

function tk_hide_login_block() {
    if (!tk_get_option('hide_login_enabled')) return;

    $path = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($path === '') {
        return;
    }
    $path = strtok($path, '?');
    if (tk_hide_login_is_slug_path($path)) {
        require_once ABSPATH . 'wp-login.php';
        exit;
    }

    if (strpos($path, 'wp-login.php') !== false) {
        if (empty(tk_hide_login_slug_path())) {
            return;
        }
        $query = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        $target = tk_hide_login_custom_url();
        if ($query !== '') {
            $target .= '?' . $query;
        }
        wp_redirect($target);
        exit;
    }

    $is_admin_area = strpos($path, '/wp-admin') === 0 || strpos($path, '/admin') === 0;
    if (!$is_admin_area) {
        return;
    }
    if (is_user_logged_in()) {
        return;
    }
    if (strpos($path, '/wp-admin/admin-ajax.php') === 0 || strpos($path, '/wp-admin/admin-post.php') === 0) {
        return;
    }
    wp_redirect(home_url());
    exit;
}

function tk_hide_login_block_login() {
    if (!tk_get_option('hide_login_enabled')) return;
    $path = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($path === '') {
        return;
    }
    $path = strtok($path, '?');
    if (tk_hide_login_is_allowed_post()) {
        return;
    }
    if (tk_hide_login_is_slug_path($path)) {
        return;
    }
    wp_redirect(home_url());
    exit;
}

function tk_hide_login_is_allowed_post() {
    if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST') {
        return false;
    }
    if (isset($_POST['log'], $_POST['pwd'])) {
        return true;
    }
    if (!empty($_REQUEST['action']) && $_REQUEST['action'] === 'lostpassword' && isset($_POST['user_login'])) {
        return true;
    }
    return false;
}

function tk_hide_login_block_admin() {
    if (!tk_get_option('hide_login_enabled')) return;
    if (is_user_logged_in()) {
        return;
    }
    $path = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($path === '') {
        return;
    }
    $path = strtok($path, '?');
    if (strpos($path, '/wp-admin/admin-ajax.php') === 0 || strpos($path, '/wp-admin/admin-post.php') === 0) {
        return;
    }
    wp_redirect(home_url());
    exit;
}

function tk_hide_login_flush_rewrite($enable = true) {
    $slug = tk_hide_login_slug();
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
    wp_redirect(add_query_arg(array('page' => 'tool-kits-optimization', 'tk_tab' => 'hide-login', 'tk_saved' => 1), admin_url('admin.php')));
    exit;
}

function tk_render_hide_login_page() {
    if (function_exists('tk_render_optimization_page')) {
        tk_render_optimization_page('hide-login');
        return;
    }
    if (!tk_is_admin_user()) return;
    ?>
    <div class="wrap tk-wrap">
        <h1>Optimization</h1>
        <?php tk_render_hide_login_panel(); ?>
    </div>
    <?php
}

function tk_render_hide_login_panel() {
    if (!tk_is_admin_user()) return;

    $enabled = (int) tk_get_option('hide_login_enabled', 0);
    $slug = tk_get_option('hide_login_slug', 'secure-login');

    ?>
    <div class="tk-card">
        <h2>Hide Login</h2>
        <p>This module replaces the login URL with a custom slug and blocks direct hits to <code>wp-login.php</code>.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_hide_login_save'); ?>
            <input type="hidden" name="action" value="tk_hide_login_save">
            <input type="hidden" name="tk_tab" value="hide-login">
            <label><input type="checkbox" name="enabled" value="1" <?php checked(1, $enabled); ?>> Enable Hide Login</label>
            <p>
                <strong>Custom slug</strong><br>
                <input type="text" name="slug" value="<?php echo esc_attr($slug); ?>" class="regular-text">
            </p>
            <p class="description">After saving, visit <code><?php echo esc_html(home_url('/' . trim($slug, '/') . '/')); ?></code> to login.</p>
            <p><button class="button button-primary">Save</button></p>
        </form>
    </div>
    <?php
}
