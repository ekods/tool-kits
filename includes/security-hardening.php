<?php
if (!defined('ABSPATH')) exit;

/**
 * Basic WP hardening
 */

function tk_hardening_init() {
    if (tk_get_option('hardening_disable_xmlrpc', 1)) {
        add_filter('xmlrpc_enabled', '__return_false');
    }
    if (tk_get_option('hardening_disable_rest_user_enum', 1)) {
        add_filter('rest_endpoints', 'tk_disable_user_enum');
    }
    if (tk_get_option('hardening_disable_pingbacks', 1)) {
        add_filter('xmlrpc_methods', 'tk_disable_pingbacks');
    }
    if (tk_get_option('hardening_security_headers', 1)) {
        add_action('send_headers', 'tk_security_headers');
    }
    if (tk_get_option('hardening_disable_file_editor', 1)) {
        add_action('init', 'tk_define_disallow_file_edit');
        add_filter('user_has_cap', 'tk_disable_file_editor_caps', 10, 4);
    }
    add_action('admin_post_tk_hardening_save', 'tk_hardening_save');
}

function tk_disable_user_enum($endpoints) {
    unset($endpoints['/wp/v2/users']);
    unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
    return $endpoints;
}

function tk_security_headers() {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin');
}

function tk_disable_pingbacks($methods) {
    if (isset($methods['pingback.ping'])) {
        unset($methods['pingback.ping']);
    }
    return $methods;
}

function tk_define_disallow_file_edit() {
    if (!defined('DISALLOW_FILE_EDIT')) {
        define('DISALLOW_FILE_EDIT', true);
    }
}

function tk_disable_file_editor_caps($allcaps, $caps, $args, $user) {
    $deny = array('edit_themes', 'edit_plugins', 'edit_files');
    foreach ($deny as $cap) {
        if (isset($allcaps[$cap])) {
            $allcaps[$cap] = false;
        }
    }
    return $allcaps;
}

function tk_render_hardening_page() {
    if (!tk_is_admin_user()) return;

    $opts = array(
        'hardening_disable_file_editor' => tk_get_option('hardening_disable_file_editor', 1),
        'hardening_disable_xmlrpc' => tk_get_option('hardening_disable_xmlrpc', 1),
        'hardening_disable_rest_user_enum' => tk_get_option('hardening_disable_rest_user_enum', 1),
        'hardening_security_headers' => tk_get_option('hardening_security_headers', 1),
        'hardening_disable_pingbacks' => tk_get_option('hardening_disable_pingbacks', 1),
    );
    ?>
    <div class="wrap tk-wrap">
        <h1>Hardening</h1>
        <div class="tk-card">
            <p>Toggle the most effective WordPress hardening toggles that can be flipped without touching core files.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php tk_nonce_field('tk_hardening_save'); ?>
                <input type="hidden" name="action" value="tk_hardening_save">

                <p><label><input type="checkbox" name="file_editor" value="1" <?php checked(1, $opts['hardening_disable_file_editor']); ?>> Disable theme/plugin file editor</label></p>
                <p><label><input type="checkbox" name="xmlrpc" value="1" <?php checked(1, $opts['hardening_disable_xmlrpc']); ?>> Disable XML-RPC</label></p>
                <p><label><input type="checkbox" name="rest_user_enum" value="1" <?php checked(1, $opts['hardening_disable_rest_user_enum']); ?>> Disable REST user enumeration</label></p>
                <p><label><input type="checkbox" name="headers" value="1" <?php checked(1, $opts['hardening_security_headers']); ?>> Send security headers</label></p>
                <p><label><input type="checkbox" name="pingbacks" value="1" <?php checked(1, $opts['hardening_disable_pingbacks']); ?>> Disable XML-RPC pingbacks</label></p>

                <p><button class="button button-primary">Save Hardening Settings</button></p>
            </form>
        </div>
    </div>
    <?php
}

function tk_hardening_save() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    tk_check_nonce('tk_hardening_save');

    tk_update_option('hardening_disable_file_editor', !empty($_POST['file_editor']) ? 1 : 0);
    tk_update_option('hardening_disable_xmlrpc', !empty($_POST['xmlrpc']) ? 1 : 0);
    tk_update_option('hardening_disable_rest_user_enum', !empty($_POST['rest_user_enum']) ? 1 : 0);
    tk_update_option('hardening_security_headers', !empty($_POST['headers']) ? 1 : 0);
    tk_update_option('hardening_disable_pingbacks', !empty($_POST['pingbacks']) ? 1 : 0);

    wp_redirect(add_query_arg(array('page'=>'tool-kits-security-hardening','tk_saved'=>1), admin_url('admin.php')));
    exit;
}
