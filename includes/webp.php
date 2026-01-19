<?php
if (!defined('ABSPATH')) { exit; }

function tk_webp_init() {
    add_action('admin_post_tk_webp_save', 'tk_webp_save');
    add_action('admin_post_tk_webp_generate_all', 'tk_webp_generate_all');
    add_action('wp_ajax_tk_webp_generate_batch', 'tk_webp_generate_batch');
    add_filter('wp_generate_attachment_metadata', 'tk_webp_generate_on_upload', 20, 2);
    add_filter('wp_get_attachment_image_src', 'tk_webp_filter_image_src', 20, 2);
    add_filter('wp_calculate_image_srcset', 'tk_webp_filter_srcset', 20, 5);
}

function tk_webp_should_serve() {
    if (is_admin()) {
        return false;
    }
    if (!tk_get_option('webp_serve_enabled', 0)) {
        return false;
    }
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower((string) $_SERVER['HTTP_ACCEPT']) : '';
    return strpos($accept, 'image/webp') !== false;
}

function tk_webp_generate_on_upload($metadata, $attachment_id) {
    if (!tk_get_option('webp_convert_enabled', 0)) {
        return $metadata;
    }
    $quality = (int) tk_get_option('webp_quality', 82);
    tk_webp_generate_for_attachment($attachment_id, $quality, $metadata);
    update_post_meta($attachment_id, '_tk_webp_generated', time());
    return $metadata;
}

function tk_webp_generate_for_attachment($attachment_id, $quality, $metadata = null) {
    $file = get_attached_file($attachment_id);
    if (!is_string($file) || $file === '' || !file_exists($file)) {
        return;
    }
    $mime = get_post_mime_type($attachment_id);
    if (!tk_webp_is_convertible_mime($mime)) {
        return;
    }
    if (!is_array($metadata)) {
        $metadata = wp_get_attachment_metadata($attachment_id);
    }
    tk_webp_convert_file($file, $quality);
    if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
        $dir = dirname($file);
        foreach ($metadata['sizes'] as $size) {
            if (empty($size['file'])) {
                continue;
            }
            $path = trailingslashit($dir) . $size['file'];
            tk_webp_convert_file($path, $quality);
        }
    }
    update_post_meta($attachment_id, '_tk_webp_generated', time());
}

function tk_webp_convert_file($path, $quality) {
    if (!is_string($path) || $path === '' || !file_exists($path)) {
        return;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext !== 'jpg' && $ext !== 'jpeg' && $ext !== 'png') {
        return;
    }
    $webp_path = preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
    if (!is_string($webp_path) || $webp_path === '') {
        return;
    }
    if (file_exists($webp_path) && filemtime($webp_path) >= filemtime($path)) {
        return;
    }
    $editor = wp_get_image_editor($path);
    if (is_wp_error($editor)) {
        tk_webp_maybe_mark_palette_error($editor);
        return;
    }
    $editor->set_quality(max(10, min(100, (int) $quality)));
    $result = $editor->save($webp_path, 'image/webp');
    if (is_wp_error($result)) {
        tk_webp_maybe_mark_palette_error($result);
    }
}

function tk_webp_is_convertible_mime($mime) {
    return in_array($mime, array('image/jpeg', 'image/jpg', 'image/png'), true);
}

function tk_webp_filter_image_src($image, $attachment_id) {
    if (!tk_webp_should_serve()) {
        return $image;
    }
    if (!is_array($image) || empty($image[0])) {
        return $image;
    }
    $src = $image[0];
    $webp = tk_webp_url_to_webp($src);
    if ($webp !== '') {
        $image[0] = $webp;
    }
    return $image;
}

function tk_webp_filter_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
    if (!tk_webp_should_serve()) {
        return $sources;
    }
    if (!is_array($sources)) {
        return $sources;
    }
    foreach ($sources as $w => $source) {
        if (empty($source['url'])) {
            continue;
        }
        $webp = tk_webp_url_to_webp($source['url']);
        if ($webp !== '') {
            $sources[$w]['url'] = $webp;
        }
    }
    return $sources;
}

function tk_webp_url_to_webp($url) {
    if (!is_string($url) || $url === '') {
        return '';
    }
    if (preg_match('/\.webp(\?|$)/i', $url)) {
        return '';
    }
    $path = tk_webp_url_to_path($url);
    if ($path === '') {
        return '';
    }
    $webp_path = preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
    if (!is_string($webp_path) || $webp_path === '' || !file_exists($webp_path)) {
        return '';
    }
    return preg_replace('/\.(jpe?g|png)(\?|$)/i', '.webp$2', $url);
}

function tk_webp_url_to_path($url) {
    if (!is_string($url) || $url === '') {
        return '';
    }
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['path']) || !is_string($parts['path'])) {
        return '';
    }
    if (!empty($parts['host'])) {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        if (is_string($host) && $host !== '' && strcasecmp($parts['host'], $host) !== 0) {
            return '';
        }
    }
    $path_part = $parts['path'];
    $abs = wp_normalize_path(ABSPATH . ltrim($path_part, '/'));
    return file_exists($abs) ? $abs : '';
}

function tk_render_webp_page() {
    if (function_exists('tk_render_optimization_page')) {
        tk_render_optimization_page('webp');
        return;
    }
    if (!tk_is_admin_user()) return;
    ?>
    <div class="wrap tk-wrap">
        <h1>Optimization</h1>
        <?php tk_render_webp_panel(); ?>
    </div>
    <?php
}

function tk_render_webp_panel() {
    if (!tk_is_admin_user()) return;
    $palette = get_transient('tk_webp_palette_error');
    ?>
        <?php if ($palette) : ?>
            <?php tk_notice('Fatal error: Palette image not supported by webp. Some PNGs could not be converted.', 'warning'); ?>
            <?php delete_transient('tk_webp_palette_error'); ?>
        <?php endif; ?>
    <div class="tk-progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
        <div class="tk-progress-bar" style="width:0%"></div>
    </div>
    <p><small id="tk-webp-progress-text">0% complete</small></p>
    <div class="tk-card">
        <h2>Auto WebP</h2>
        <p>Automatically generate WebP files on upload and serve them when the browser supports it.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_webp_save'); ?>
            <input type="hidden" name="action" value="tk_webp_save">
            <input type="hidden" name="tk_tab" value="webp">
            <p>
                <label>
                    <input type="checkbox" name="webp_convert_enabled" value="1" <?php checked(1, tk_get_option('webp_convert_enabled', 0)); ?>>
                    Auto convert JPG/PNG to WebP on upload
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="webp_serve_enabled" value="1" <?php checked(1, tk_get_option('webp_serve_enabled', 0)); ?>>
                    Serve WebP when supported by the browser
                </label>
            </p>
            <p>
                <label>WebP quality (10-100)</label><br>
                <input type="number" name="webp_quality" min="10" max="100" value="<?php echo esc_attr((string) tk_get_option('webp_quality', 82)); ?>">
            </p>
            <p><button class="button button-primary">Save</button></p>
        </form>
        <hr style="margin:16px 0;">
        <p><strong>Generate WebP for existing images</strong></p>
        <button class="button button-secondary" id="tk-webp-generate-all" data-nonce="<?php echo esc_attr(wp_create_nonce('tk_webp_generate_batch')); ?>">
            Generate WebP for all images
        </button>
        <p class="description" id="tk-webp-status" style="margin-top:8px;"></p>
    </div>
    <script>
    (function(){
        var button = document.getElementById('tk-webp-generate-all');
        var status = document.getElementById('tk-webp-status');
        var progress = document.querySelector('.tk-progress-bar');
        var progressText = document.getElementById('tk-webp-progress-text');
        if (!button) { return; }
        function setStatus(text) {
            if (status) { status.textContent = text; }
        }
        function setProgress(percent) {
            if (!progress) { return; }
            progress.style.width = percent + '%';
            progress.parentElement.setAttribute('aria-valuenow', String(percent));
            if (progressText) {
                progressText.textContent = percent + '% complete';
            }
        }
        function runBatch(offset) {
            button.disabled = true;
            setStatus('Processing...');
            var data = new URLSearchParams();
            data.append('action', 'tk_webp_generate_batch');
            data.append('nonce', button.getAttribute('data-nonce'));
            data.append('offset', String(offset || 0));
            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: data.toString()
            }).then(function(resp){ return resp.json(); }).then(function(res){
                if (!res || !res.success || !res.data) {
                    throw new Error('Request failed');
                }
                if (typeof res.data.progress === 'number') {
                    setProgress(res.data.progress);
                    setStatus(res.data.processed + '/' + res.data.total);
                }
                if (res.data.done) {
                    setStatus('Completed');
                    button.disabled = false;
                    return;
                }
                runBatch(res.data.next_offset || 0);
            }).catch(function(){
                setStatus('Failed to process. Please try again.');
                button.disabled = false;
            });
        }
        button.addEventListener('click', function(e){
            e.preventDefault();
            runBatch(0);
        });
    })();
    </script>
    <?php
}

function tk_webp_save() {
    tk_check_nonce('tk_webp_save');
    tk_update_option('webp_convert_enabled', !empty($_POST['webp_convert_enabled']) ? 1 : 0);
    tk_update_option('webp_serve_enabled', !empty($_POST['webp_serve_enabled']) ? 1 : 0);
    tk_update_option('webp_quality', max(10, min(100, (int) tk_post('webp_quality', 82))));
    wp_redirect(add_query_arg(array('page' => 'tool-kits-optimization', 'tk_tab' => 'webp', 'tk_saved' => 1), admin_url('admin.php')));
    exit;
}

function tk_webp_generate_all() {
    tk_check_nonce('tk_webp_generate_all');
    if (!tk_is_admin_user()) {
        wp_die(__('You do not have permission.', 'tool-kits'));
    }
    $offset = max(0, (int) tk_post('offset', 0));
    $limit = 100;
    $query = new WP_Query(array(
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'post_mime_type' => 'image',
        'posts_per_page' => $limit,
        'offset' => $offset,
        'fields' => 'ids',
        'no_found_rows' => false,
    ));
    $quality = (int) tk_get_option('webp_quality', 82);
    if (!empty($query->posts)) {
        foreach ($query->posts as $attachment_id) {
            tk_webp_generate_for_attachment($attachment_id, $quality);
        }
    }
    $next = $offset + $limit;
    $total = (int) $query->found_posts;
    if ($next < $total) {
        $progress = $next . '/' . $total;
        $url = add_query_arg(array(
            'action' => 'tk_webp_generate_all',
            'offset' => $next,
            '_tk_nonce' => wp_create_nonce('tk_webp_generate_all'),
        ), admin_url('admin-post.php'));
        wp_safe_redirect(add_query_arg('tk_webp_progress', rawurlencode($progress), $url));
        exit;
    }
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-optimization', 'tk_tab' => 'webp', 'tk_webp_done' => 1), admin_url('admin.php')));
    exit;
}

function tk_webp_generate_batch() {
    check_ajax_referer('tk_webp_generate_batch', 'nonce');
    if (!tk_is_admin_user()) {
        wp_send_json_error(array('message' => 'unauthorized'));
    }
    $offset = max(0, (int) tk_post('offset', 0));
    $limit = 100;
    $query = new WP_Query(array(
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'post_mime_type' => 'image',
        'posts_per_page' => $limit,
        'offset' => $offset,
        'fields' => 'ids',
        'no_found_rows' => false,
    ));
    $quality = (int) tk_get_option('webp_quality', 82);
    if (!empty($query->posts)) {
        foreach ($query->posts as $attachment_id) {
            tk_webp_generate_for_attachment($attachment_id, $quality);
        }
    }
    $next = $offset + $limit;
    $total = (int) $query->found_posts;
    $done = $next >= $total;
    $processed = min($next, $total);
    $progress = $total > 0 ? (int) min(100, round(($processed / $total) * 100)) : 100;
    wp_send_json_success(array(
        'done' => $done,
        'next_offset' => $done ? 0 : $next,
        'processed' => $processed,
        'total' => $total,
        'progress' => $progress,
    ));
}

function tk_webp_maybe_mark_palette_error($error) {
    if (!is_wp_error($error)) {
        return;
    }
    $message = strtolower($error->get_error_message());
    if (strpos($message, 'palette') !== false && strpos($message, 'webp') !== false) {
        set_transient('tk_webp_palette_error', 1, 10 * MINUTE_IN_SECONDS);
    }
}
