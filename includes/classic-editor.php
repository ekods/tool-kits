<?php
if (!defined('ABSPATH')) { exit; }

function tk_classic_editor_init() {
    add_action('admin_post_tk_classic_editor_save', 'tk_classic_editor_save');

    if (!tk_classic_editor_enabled()) {
        return;
    }

    add_filter('use_block_editor_for_post_type', 'tk_classic_editor_disable_block_editor_for_post_type', 100, 2);
    add_filter('use_block_editor_for_post', 'tk_classic_editor_disable_block_editor_for_post', 100, 2);
    add_filter('gutenberg_can_edit_post_type', 'tk_classic_editor_disable_gutenberg_for_post_type', 100, 2);
    add_filter('gutenberg_can_edit_post', 'tk_classic_editor_disable_gutenberg_for_post', 100, 2);
}

function tk_classic_editor_enabled(): bool {
    return (int) tk_get_option('classic_editor_enabled', 0) === 1;
}

function tk_classic_editor_disable_block_editor_for_post_type($can_edit, $post_type) {
    if (!post_type_supports((string) $post_type, 'editor')) {
        return $can_edit;
    }

    return false;
}

function tk_classic_editor_disable_block_editor_for_post($can_edit, $post) {
    if (is_numeric($post)) {
        $post = get_post((int) $post);
    }

    if (!is_object($post) || empty($post->post_type)) {
        return $can_edit;
    }

    return tk_classic_editor_disable_block_editor_for_post_type($can_edit, (string) $post->post_type);
}

function tk_classic_editor_disable_gutenberg_for_post_type($can_edit, $post_type) {
    return tk_classic_editor_disable_block_editor_for_post_type($can_edit, $post_type);
}

function tk_classic_editor_disable_gutenberg_for_post($can_edit, $post) {
    return tk_classic_editor_disable_block_editor_for_post($can_edit, $post);
}

function tk_render_classic_editor_panel() {
    if (!tk_is_admin_user()) return;
    $enabled = (int) tk_get_option('classic_editor_enabled', 0);
    ?>
    <div class="tk-card">
        <h2>Classic Editor</h2>
        <p>Enables the WordPress classic editor and the old-style Edit Post screen with TinyMCE, Meta Boxes, etc. Supports the older plugins that extend this screen.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_classic_editor_save'); ?>
            <input type="hidden" name="action" value="tk_classic_editor_save">
            <p>
                <label>
                    <input type="checkbox" name="classic_editor_enabled" value="1" <?php checked(1, $enabled); ?>>
                    Enable Classic Editor
                </label>
            </p>
            <p><button class="button button-primary">Save Settings</button></p>
        </form>
    </div>
    <?php
}

function tk_classic_editor_save() {
    tk_require_admin_post('tk_classic_editor_save');
    tk_update_option('classic_editor_enabled', !empty($_POST['classic_editor_enabled']) ? 1 : 0);
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-general&tk_saved=1#editing'));
    exit;
}
