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

function tk_upload_limits_document_max_mb(): int {
    return max(1, (int) tk_get_option('upload_documents_max_mb', 25));
}

function tk_upload_limits_video_max_mb(): int {
    return max(1, (int) tk_get_option('upload_videos_max_mb', 200));
}

function tk_upload_limits_category($file): string {
    $name = is_array($file) && isset($file['name']) ? (string) $file['name'] : '';
    $type = is_array($file) && isset($file['type']) ? strtolower((string) $file['type']) : '';
    $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

    $image_extensions = array('jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff', 'ico', 'svg');
    $video_extensions = array('mp4', 'm4v', 'mov', 'avi', 'mpg', 'mpeg', 'mpe', 'webm', 'ogv', '3gp', '3g2', 'mkv');
    $document_extensions = array(
        'pdf', 'txt', 'rtf', 'csv', 'tsv',
        'doc', 'docx', 'odt', 'pages',
        'xls', 'xlsx', 'ods', 'numbers',
        'ppt', 'pptx', 'odp', 'key',
        'epub', 'md',
    );

    if (in_array($extension, $image_extensions, true) || strpos($type, 'image/') === 0) {
        return 'image';
    }
    if (in_array($extension, $video_extensions, true) || strpos($type, 'video/') === 0) {
        return 'video';
    }
    if (
        in_array($extension, $document_extensions, true)
        || strpos($type, 'text/') === 0
        || in_array($type, array(
            'application/pdf',
            'application/rtf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation',
        ), true)
    ) {
        return 'document';
    }

    return 'other';
}

function tk_upload_limits_category_max_bytes(string $category, $file = array()): int {
    if ($category === 'document') {
        $bytes = tk_upload_limits_document_max_mb() * 1024 * 1024;
    } elseif ($category === 'video') {
        $bytes = tk_upload_limits_video_max_mb() * 1024 * 1024;
    } else {
        $bytes = tk_upload_limits_current_bytes($file);
    }

    return max(1, (int) apply_filters('tk_upload_limits_category_max_bytes', $bytes, $category, $file));
}

function tk_upload_limits_category_label(string $category): string {
    $labels = array(
        'image' => __('Image', 'tool-kits'),
        'document' => __('Document', 'tool-kits'),
        'video' => __('Video', 'tool-kits'),
        'other' => __('File', 'tool-kits'),
    );
    return $labels[$category] ?? $labels['other'];
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

    // Bypass custom limit for plugin/theme zip files
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext === 'zip') {
        return $file;
    }

    $category = tk_upload_limits_category($file);
    $max = tk_upload_limits_category_max_bytes($category, $file);
    if ((int) $file['size'] > $max) {
        $mb = round($max / (1024 * 1024), 2);
        $file['error'] = sprintf(
            __('%1$s exceeds the maximum upload size of %2$s MB.', 'tool-kits'),
            tk_upload_limits_category_label($category),
            (string) $mb
        );
    }
    return $file;
}

function tk_upload_limits_override_display($size) {
    if (!tk_upload_limits_enabled()) {
        return $size;
    }

    // Don't override upload limit on plugin/theme installer pages
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && in_array($screen->id, ['plugin-install', 'theme-install', 'update'], true)) {
        return $size;
    }

    // Also check by page parameter as fallback (screen may not be available yet)
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    if ($action === 'upload-plugin' || $action === 'upload-theme') {
        return $size;
    }

    $configured_max = max(
        tk_upload_limits_max_bytes(),
        tk_upload_limits_document_max_mb() * 1024 * 1024,
        tk_upload_limits_video_max_mb() * 1024 * 1024
    );
    return min((int) $size, $configured_max);
}

function tk_render_upload_limits_panel() {
    if (!tk_is_admin_user()) return;
    $enabled = (int) tk_get_option('upload_images_limit_enabled', 1);
    $default_mb = tk_upload_limits_default_mb();
    $max_mb = tk_upload_limits_fallback_max_mb();
    $document_max_mb = tk_upload_limits_document_max_mb();
    $video_max_mb = tk_upload_limits_video_max_mb();
    ?>
    <div class="tk-card">
        <h2>Upload Limits</h2>
        <p>Set separate maximum upload sizes for images, documents such as PDF/Office files, and videos.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_upload_limits_save'); ?>
            <input type="hidden" name="action" value="tk_upload_limits_save">
            <p>
                <label>
                    <input type="checkbox" name="upload_images_limit_enabled" value="1" <?php checked(1, $enabled); ?>>
                    Enable upload size limits
                </label>
            </p>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:16px; margin:20px 0;">
                <div style="padding:18px; border:1px solid var(--tk-border-soft); border-radius:14px; background:var(--tk-bg-soft);">
                    <h3 style="margin-top:0;">Images</h3>
                    <p><label>Default image limit (MB)</label><br><input type="number" name="upload_images_default_mb" min="1" max="10240" value="<?php echo esc_attr((string) $default_mb); ?>"></p>
                    <p><label>Maximum image/custom-field limit (MB)</label><br><input type="number" name="upload_images_max_mb" min="1" max="10240" value="<?php echo esc_attr((string) $max_mb); ?>"></p>
                    <p class="description">JPG, PNG, GIF, WebP, AVIF, SVG, and other image formats.</p>
                </div>
                <div style="padding:18px; border:1px solid var(--tk-border-soft); border-radius:14px; background:var(--tk-bg-soft);">
                    <h3 style="margin-top:0;">Documents</h3>
                    <p><label>Maximum document size (MB)</label><br><input type="number" name="upload_documents_max_mb" min="1" max="10240" value="<?php echo esc_attr((string) $document_max_mb); ?>"></p>
                    <p class="description">PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, ODT/ODS/ODP, TXT, CSV, RTF, and EPUB.</p>
                </div>
                <div style="padding:18px; border:1px solid var(--tk-border-soft); border-radius:14px; background:var(--tk-bg-soft);">
                    <h3 style="margin-top:0;">Videos</h3>
                    <p><label>Maximum video size (MB)</label><br><input type="number" name="upload_videos_max_mb" min="1" max="10240" value="<?php echo esc_attr((string) $video_max_mb); ?>"></p>
                    <p class="description">MP4, MOV, AVI, MPEG, WebM, OGV, 3GP, and MKV.</p>
                </div>
            </div>
            <p class="description">Image/custom fields can request a larger limit by sending <code>tk_upload_limit_mb</code> or by using the <code>tk_upload_limits_current_bytes</code> filter. The effective image limit will never exceed its configured maximum.</p>
            <p class="description">The PHP/web-server upload limit still applies. Increasing a value here cannot exceed the server limit.</p>
            <p><button class="button button-primary">Save Settings</button></p>
        </form>
    </div>
    <?php
}

function tk_upload_limits_save() {
    tk_require_admin_post('tk_upload_limits_save');
    $max_mb = min(10240, max(1, (int) tk_post('upload_images_max_mb', tk_upload_limits_fallback_max_mb())));
    $default_mb = max(1, (int) tk_post('upload_images_default_mb', tk_upload_limits_default_mb()));
    $default_mb = min($default_mb, $max_mb);
    $document_max_mb = min(10240, max(1, (int) tk_post('upload_documents_max_mb', tk_upload_limits_document_max_mb())));
    $video_max_mb = min(10240, max(1, (int) tk_post('upload_videos_max_mb', tk_upload_limits_video_max_mb())));
    tk_update_option('upload_images_limit_enabled', !empty($_POST['upload_images_limit_enabled']) ? 1 : 0);
    tk_update_option('upload_images_default_mb', $default_mb);
    tk_update_option('upload_images_max_mb', $max_mb);
    tk_update_option('upload_documents_max_mb', $document_max_mb);
    tk_update_option('upload_videos_max_mb', $video_max_mb);
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-general&tk_saved=1#uploads'));
    exit;
}
