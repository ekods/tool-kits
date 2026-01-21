<?php
if (!defined('ABSPATH')) { exit; }

function tk_upload_limits_init() {
    add_action('admin_post_tk_upload_limits_save', 'tk_upload_limits_save');
    add_filter('wp_handle_upload_prefilter', 'tk_upload_limits_prefilter');
    add_filter('upload_size_limit', 'tk_upload_limits_override_display');
    add_filter('upload_size_limit_filter', 'tk_upload_limits_override_display');
}

function tk_upload_limits_enabled(): bool {
    return (int) tk_get_option('upload_images_limit_enabled', 1) === 1;
}

function tk_upload_limits_max_bytes(): int {
    $mb = max(1, (int) tk_get_option('upload_images_max_mb', 2));
    return $mb * 1024 * 1024;
}

function tk_upload_limits_prefilter($file) {
    if (!tk_upload_limits_enabled()) {
        return $file;
    }
    if (!is_array($file) || empty($file['name']) || empty($file['size'])) {
        return $file;
    }
    $type = isset($file['type']) ? (string) $file['type'] : '';
    $is_image = $type !== '' && strpos($type, 'image/') === 0;
    if (!$is_image) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $is_image = in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'), true);
    }
    if (!$is_image) {
        return $file;
    }
    $max = tk_upload_limits_max_bytes();
    if ((int) $file['size'] > $max) {
        $mb = round($max / (1024 * 1024), 2);
        $file['error'] = 'Image exceeds the maximum upload size of ' . $mb . ' MB.';
    }
    return $file;
}

function tk_upload_limits_override_display($size) {
    if (!tk_upload_limits_enabled()) {
        return $size;
    }
    return tk_upload_limits_max_bytes();
}

function tk_render_upload_limits_panel() {
    if (!tk_is_admin_user()) return;
    $enabled = (int) tk_get_option('upload_images_limit_enabled', 1);
    $max_mb = (int) tk_get_option('upload_images_max_mb', 2);
    ?>
    <div class="tk-card">
        <h2>Upload Limits</h2>
        <p>Limit image upload size to reduce bandwidth and storage usage.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_upload_limits_save'); ?>
            <input type="hidden" name="action" value="tk_upload_limits_save">
            <input type="hidden" name="tk_tab" value="uploads">
            <p>
                <label>
                    <input type="checkbox" name="upload_images_limit_enabled" value="1" <?php checked(1, $enabled); ?>>
                    Enable maximum image upload size
                </label>
            </p>
            <p>
                <label>Max image size (MB)</label><br>
                <input type="number" name="upload_images_max_mb" min="1" value="<?php echo esc_attr((string) $max_mb); ?>">
            </p>
            <p><button class="button button-primary">Save Settings</button></p>
        </form>
    </div>
    <?php
}

function tk_upload_limits_save() {
    tk_check_nonce('tk_upload_limits_save');
    tk_update_option('upload_images_limit_enabled', !empty($_POST['upload_images_limit_enabled']) ? 1 : 0);
    tk_update_option('upload_images_max_mb', max(1, (int) tk_post('upload_images_max_mb', 2)));
    wp_redirect(add_query_arg(array('page' => 'tool-kits-optimization', 'tk_tab' => 'uploads', 'tk_saved' => 1), admin_url('admin.php')));
    exit;
}
