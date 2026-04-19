<?php
if (!defined('ABSPATH')) { exit; }

function tk_upload_limits_init() {
    add_action('admin_post_tk_upload_limits_save', 'tk_upload_limits_save');
    add_filter('wp_handle_upload_prefilter', 'tk_upload_limits_prefilter');
    add_filter('upload_size_limit', 'tk_upload_limits_override_display');
    add_filter('upload_size_limit_filter', 'tk_upload_limits_override_display');
}

function tk_upload_limits_enabled(): bool {
    if (!tk_license_features_enabled()) {
        return false;
    }
    return (int) tk_get_option('upload_images_limit_enabled', 1) === 1;
}

function tk_upload_limits_fallback_max_mb(): int {
    return max(1, (int) tk_get_option('upload_images_max_mb', 10));
}

function tk_upload_limits_default_mb(): int {
    $default_mb = (int) tk_get_option('upload_images_default_mb', 2);
    if ($default_mb < 1) {
        $default_mb = min(2, tk_upload_limits_fallback_max_mb());
    }
    return min($default_mb, tk_upload_limits_fallback_max_mb());
}

function tk_upload_limits_max_bytes(): int {
    $mb = tk_upload_limits_fallback_max_mb();
    return $mb * 1024 * 1024;
}

function tk_upload_limits_default_bytes(): int {
    $mb = tk_upload_limits_default_mb();
    return $mb * 1024 * 1024;
}

function tk_upload_limits_current_bytes($file = array()): int {
    $default = tk_upload_limits_default_bytes();
    $max = tk_upload_limits_max_bytes();
    $requested_mb = 0;

    if (isset($_REQUEST['tk_upload_limit_mb'])) {
        $requested_mb = (int) $_REQUEST['tk_upload_limit_mb'];
    } elseif (isset($_REQUEST['post_data']) && is_array($_REQUEST['post_data']) && isset($_REQUEST['post_data']['tk_upload_limit_mb'])) {
        $requested_mb = (int) $_REQUEST['post_data']['tk_upload_limit_mb'];
    }

    $requested_bytes = $requested_mb > 0 ? ($requested_mb * 1024 * 1024) : $default;
    $limit = (int) apply_filters('tk_upload_limits_current_bytes', $requested_bytes, $file, $default, $max);
    if ($limit < 1) {
        $limit = $default;
    }
    return min($limit, $max);
}

function tk_upload_limits_prefilter($file) {
    if (!tk_upload_limits_enabled()) {
        return $file;
    }
    if (!is_array($file) || empty($file['name']) || empty($file['size'])) {
        return $file;
    }
    $max = tk_upload_limits_current_bytes($file);
    if ((int) $file['size'] > $max) {
        $mb = round($max / (1024 * 1024), 2);
        $file['error'] = 'File exceeds the maximum upload size of ' . $mb . ' MB.';
    }
    return $file;
}

function tk_upload_limits_override_display($size) {
    if (!tk_upload_limits_enabled()) {
        return $size;
    }
    return tk_upload_limits_default_bytes();
}

function tk_render_upload_limits_panel() {
    if (!tk_is_admin_user()) return;
    $enabled = (int) tk_get_option('upload_images_limit_enabled', 1);
    $default_mb = tk_upload_limits_default_mb();
    $max_mb = tk_upload_limits_fallback_max_mb();
    ?>
    <div class="tk-card">
        <h2>Upload Limits</h2>
        <p>Set a global default upload size for the standard WordPress uploader, while keeping a higher ceiling for custom fields that need larger files.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_upload_limits_save'); ?>
            <input type="hidden" name="action" value="tk_upload_limits_save">
            <input type="hidden" name="tk_tab" value="uploads">
            <p>
                <label>
                    <input type="checkbox" name="upload_images_limit_enabled" value="1" <?php checked(1, $enabled); ?>>
                    Enable upload size limits
                </label>
            </p>
            <p>
                <label>Default upload size shown in WordPress uploader (MB)</label><br>
                <input type="number" name="upload_images_default_mb" min="1" value="<?php echo esc_attr((string) $default_mb); ?>">
            </p>
            <p>
                <label>Maximum allowed size for custom fields (MB)</label><br>
                <input type="number" name="upload_images_max_mb" min="1" value="<?php echo esc_attr((string) $max_mb); ?>">
            </p>
            <p class="description">Custom fields can request a larger limit by sending <code>tk_upload_limit_mb</code> or by using the <code>tk_upload_limits_current_bytes</code> filter. The effective limit will never exceed the maximum above.</p>
            <p><button class="button button-primary">Save Settings</button></p>
        </form>
    </div>
    <?php
}

function tk_upload_limits_save() {
    tk_require_admin_post('tk_upload_limits_save');
    $max_mb = max(1, (int) tk_post('upload_images_max_mb', tk_upload_limits_fallback_max_mb()));
    $default_mb = max(1, (int) tk_post('upload_images_default_mb', tk_upload_limits_default_mb()));
    $default_mb = min($default_mb, $max_mb);
    tk_update_option('upload_images_limit_enabled', !empty($_POST['upload_images_limit_enabled']) ? 1 : 0);
    tk_update_option('upload_images_default_mb', $default_mb);
    tk_update_option('upload_images_max_mb', $max_mb);
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-optimization', 'tk_tab' => 'uploads', 'tk_saved' => 1), admin_url('admin.php')));
    exit;
}
