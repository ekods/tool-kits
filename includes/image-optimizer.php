<?php
if (!defined('ABSPATH')) { exit; }

function tk_image_opt_init() {
    add_action('admin_post_tk_image_opt_save', 'tk_image_opt_save');
    add_action('wp_ajax_tk_image_opt_batch', 'tk_image_opt_batch');
    add_filter('wp_generate_attachment_metadata', 'tk_image_opt_on_upload', 15, 2);
    add_action('template_redirect', 'tk_image_opt_start_output_rewrite', 1);
}

function tk_image_opt_on_upload($metadata, $attachment_id) {
    if (!tk_license_features_enabled()) {
        return $metadata;
    }
    if (!tk_get_option('image_opt_enabled', 0)) {
        return $metadata;
    }
    $quality = (int) tk_get_option('image_opt_quality', 78);
    tk_image_opt_optimize_attachment($attachment_id, $quality, $metadata);
    if (tk_image_opt_should_convert_frontend_to_webp()) {
        $metadata = tk_image_opt_convert_attachment_to_webp($attachment_id, $metadata);
    }
    return $metadata;
}

function tk_image_opt_should_convert_frontend_to_webp() {
    if (!tk_get_option('image_opt_frontend_to_webp', 0)) {
        return false;
    }
    if (!tk_get_option('webp_serve_enabled', 0)) {
        return false;
    }
    if (!is_admin()) {
        return true;
    }

    if (defined('DOING_AJAX') && DOING_AJAX) {
        return tk_image_opt_is_frontend_ajax_request();
    }

    return false;
}

function tk_image_opt_is_frontend_ajax_request() {
    $referer = wp_get_referer();
    if (!is_string($referer) || $referer === '') {
        $referer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
    }
    if ($referer === '') {
        // Some frontend upload flows do not send referer.
        return true;
    }

    $home_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $ref_host = (string) wp_parse_url($referer, PHP_URL_HOST);
    if ($home_host !== '' && $ref_host !== '' && strcasecmp($home_host, $ref_host) !== 0) {
        return false;
    }

    $ref_path = (string) wp_parse_url($referer, PHP_URL_PATH);
    if ($ref_path !== '' && strpos($ref_path, '/wp-admin') !== false) {
        return false;
    }

    return true;
}

function tk_image_opt_start_output_rewrite() {
    if (is_admin()) {
        return;
    }
    if (!tk_license_features_enabled() || !tk_get_option('image_opt_enabled', 0)) {
        return;
    }
    if (!tk_image_opt_should_convert_frontend_to_webp()) {
        return;
    }
    if (is_feed() || (function_exists('is_robots') && is_robots()) || (function_exists('is_trackback') && is_trackback())) {
        return;
    }
    ob_start('tk_image_opt_rewrite_html_buffer');
}

function tk_image_opt_rewrite_html_buffer($html) {
    if (!is_string($html) || $html === '' || stripos($html, '.jpg') === false && stripos($html, '.png') === false && stripos($html, '.jpeg') === false) {
        return $html;
    }

    return preg_replace_callback(
        '/https?:\/\/[^\s"\']+\.(?:jpe?g|png)(?:\?[^\s"\']*)?/i',
        function($matches) {
            $url = isset($matches[0]) ? (string) $matches[0] : '';
            if ($url === '') {
                return $url;
            }
            return tk_image_opt_get_or_create_webp_url($url);
        },
        $html
    );
}

function tk_image_opt_get_or_create_webp_url($url) {
    if (!is_string($url) || $url === '') {
        return $url;
    }
    if (!preg_match('/\.(jpe?g|png)(\?|$)/i', $url)) {
        return $url;
    }

    $path = tk_image_opt_url_to_local_path($url);
    if ($path === '' || !file_exists($path)) {
        return $url;
    }

    $webp_path = (string) preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
    if ($webp_path === '') {
        return $url;
    }

    if (!file_exists($webp_path)) {
        $quality = max(10, min(100, (int) tk_get_option('webp_quality', 82)));
        $result = tk_image_opt_convert_file_to_webp($path, $quality, false);
        if (empty($result['new_path']) || !file_exists($result['new_path'])) {
            return $url;
        }
    }

    return (string) preg_replace('/\.(jpe?g|png)(\?|$)/i', '.webp$2', $url);
}

function tk_image_opt_url_to_local_path($url) {
    if (!is_string($url) || $url === '') {
        return '';
    }
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['path']) || !is_string($parts['path'])) {
        return '';
    }
    if (!empty($parts['host'])) {
        $home_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        if ($home_host !== '' && strcasecmp($home_host, (string) $parts['host']) !== 0) {
            return '';
        }
    }
    $path = wp_normalize_path(ABSPATH . ltrim((string) $parts['path'], '/'));
    return is_string($path) ? $path : '';
}

function tk_image_opt_optimize_attachment($attachment_id, $quality, $metadata = null) {
    $file = get_attached_file($attachment_id);
    if (!is_string($file) || $file === '' || !file_exists($file)) {
        return array('files' => 0, 'saved' => 0);
    }

    $mime = get_post_mime_type($attachment_id);
    if (!tk_image_opt_is_supported_mime($mime)) {
        return array('files' => 0, 'saved' => 0);
    }

    if (!is_array($metadata)) {
        $metadata = wp_get_attachment_metadata($attachment_id);
    }

    $files_optimized = 0;
    $bytes_saved = 0;

    $main_result = tk_image_opt_compress_file($file, $quality);
    if ($main_result['optimized']) {
        $files_optimized++;
        $bytes_saved += $main_result['saved'];
    }

    if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
        $dir = dirname($file);
        foreach ($metadata['sizes'] as $size) {
            if (empty($size['file'])) {
                continue;
            }
            $variant = trailingslashit($dir) . $size['file'];
            $res = tk_image_opt_compress_file($variant, $quality);
            if ($res['optimized']) {
                $files_optimized++;
                $bytes_saved += $res['saved'];
            }
        }
    }

    if ($files_optimized > 0) {
        update_post_meta($attachment_id, '_tk_image_opt_optimized_at', time());
        update_post_meta($attachment_id, '_tk_image_opt_saved_bytes', (int) $bytes_saved);
    }

    return array(
        'files' => $files_optimized,
        'saved' => (int) $bytes_saved,
    );
}

function tk_image_opt_compress_file($path, $quality) {
    if (!is_string($path) || $path === '' || !file_exists($path)) {
        return array('optimized' => false, 'saved' => 0);
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, array('jpg', 'jpeg', 'png'), true)) {
        return array('optimized' => false, 'saved' => 0);
    }

    $original_size = filesize($path);
    if (!is_int($original_size) || $original_size <= 0) {
        return array('optimized' => false, 'saved' => 0);
    }

    $editor = wp_get_image_editor($path);
    if (is_wp_error($editor)) {
        return array('optimized' => false, 'saved' => 0);
    }

    $quality = max(30, min(95, (int) $quality));
    $editor->set_quality($quality);

    $temp_path = preg_replace('/\.(jpe?g|png)$/i', '.tkopt.$1', $path);
    if (!is_string($temp_path) || $temp_path === '') {
        return array('optimized' => false, 'saved' => 0);
    }

    $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
    $saved = $editor->save($temp_path, $mime);
    if (is_wp_error($saved) || !file_exists($temp_path)) {
        if (file_exists($temp_path)) {
            @unlink($temp_path);
        }
        return array('optimized' => false, 'saved' => 0);
    }

    $optimized_size = filesize($temp_path);
    if (!is_int($optimized_size) || $optimized_size <= 0 || $optimized_size >= $original_size) {
        @unlink($temp_path);
        return array('optimized' => false, 'saved' => 0);
    }

    if (!@rename($temp_path, $path)) {
        @copy($temp_path, $path);
        @unlink($temp_path);
    }

    clearstatcache(true, $path);
    $final_size = filesize($path);
    if (!is_int($final_size) || $final_size <= 0) {
        return array('optimized' => false, 'saved' => 0);
    }

    $bytes_saved = max(0, $original_size - $final_size);
    return array(
        'optimized' => $bytes_saved > 0,
        'saved' => (int) $bytes_saved,
    );
}

function tk_image_opt_is_supported_mime($mime) {
    return in_array($mime, array('image/jpeg', 'image/jpg', 'image/png'), true);
}

function tk_image_opt_convert_attachment_to_webp($attachment_id, $metadata) {
    if (!is_array($metadata)) {
        return $metadata;
    }

    $file = get_attached_file($attachment_id);
    if (!is_string($file) || $file === '' || !file_exists($file)) {
        return $metadata;
    }

    $quality = max(10, min(100, (int) tk_get_option('webp_quality', 82)));
    $main = tk_image_opt_convert_file_to_webp($file, $quality, true);
    if (empty($main['new_path'])) {
        return $metadata;
    }

    if (!empty($metadata['file']) && is_string($metadata['file'])) {
        $metadata['file'] = (string) preg_replace('/\.(jpe?g|png)$/i', '.webp', $metadata['file']);
    }
    if (!empty($metadata['original_image']) && is_string($metadata['original_image'])) {
        $original_path = trailingslashit(dirname($file)) . $metadata['original_image'];
        $orig = tk_image_opt_convert_file_to_webp($original_path, $quality, true);
        if (!empty($orig['new_basename'])) {
            $metadata['original_image'] = $orig['new_basename'];
        }
    }

    if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
        foreach ($metadata['sizes'] as $size_name => $size_item) {
            if (empty($size_item['file']) || !is_string($size_item['file'])) {
                continue;
            }
            $size_path = trailingslashit(dirname($file)) . $size_item['file'];
            $size = tk_image_opt_convert_file_to_webp($size_path, $quality, true);
            if (empty($size['new_basename'])) {
                continue;
            }
            $metadata['sizes'][$size_name]['file'] = $size['new_basename'];
            $metadata['sizes'][$size_name]['mime-type'] = 'image/webp';
            if (!empty($size['new_size'])) {
                $metadata['sizes'][$size_name]['filesize'] = (int) $size['new_size'];
            }
        }
    }

    update_attached_file($attachment_id, $main['new_path']);
    wp_update_post(array(
        'ID' => $attachment_id,
        'post_mime_type' => 'image/webp',
    ));
    update_post_meta($attachment_id, '_tk_image_opt_frontend_webp', time());

    return $metadata;
}

function tk_image_opt_convert_file_to_webp($path, $quality, $delete_original = false) {
    if (!is_string($path) || $path === '' || !file_exists($path)) {
        return array('new_path' => '', 'new_basename' => '', 'new_size' => 0);
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, array('jpg', 'jpeg', 'png'), true)) {
        return array('new_path' => '', 'new_basename' => '', 'new_size' => 0);
    }

    $new_path = (string) preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
    if ($new_path === '' || $new_path === $path) {
        return array('new_path' => '', 'new_basename' => '', 'new_size' => 0);
    }

    $editor = wp_get_image_editor($path);
    if (is_wp_error($editor)) {
        if ($ext === 'png' && function_exists('tk_webp_convert_png_with_gd_fallback')) {
            $ok = tk_webp_convert_png_with_gd_fallback($path, $new_path, $quality);
            if ($ok) {
                if ($delete_original) {
                    @unlink($path);
                }
                clearstatcache(true, $new_path);
                $size = filesize($new_path);
                return array(
                    'new_path' => $new_path,
                    'new_basename' => basename($new_path),
                    'new_size' => is_int($size) ? $size : 0,
                );
            }
        }
        return array('new_path' => '', 'new_basename' => '', 'new_size' => 0);
    }

    $editor->set_quality(max(10, min(100, (int) $quality)));
    $saved = $editor->save($new_path, 'image/webp');
    if (is_wp_error($saved) || !file_exists($new_path)) {
        if ($ext === 'png' && function_exists('tk_webp_convert_png_with_gd_fallback')) {
            $ok = tk_webp_convert_png_with_gd_fallback($path, $new_path, $quality);
            if ($ok) {
                if ($delete_original) {
                    @unlink($path);
                }
                clearstatcache(true, $new_path);
                $size = filesize($new_path);
                return array(
                    'new_path' => $new_path,
                    'new_basename' => basename($new_path),
                    'new_size' => is_int($size) ? $size : 0,
                );
            }
        }
        return array('new_path' => '', 'new_basename' => '', 'new_size' => 0);
    }

    if ($delete_original) {
        @unlink($path);
    }
    clearstatcache(true, $new_path);
    $size = filesize($new_path);
    return array(
        'new_path' => $new_path,
        'new_basename' => basename($new_path),
        'new_size' => is_int($size) ? $size : 0,
    );
}

function tk_render_image_opt_panel() {
    if (!tk_is_admin_user()) return;
    ?>
    <div class="tk-progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
        <div class="tk-progress-bar" id="tk-image-opt-progress-bar" style="width:0%"></div>
    </div>
    <p><small id="tk-image-opt-progress-text">0% complete</small></p>
    <div class="tk-card">
        <h2>Image Optimizer</h2>
        <p>Compress JPG/PNG uploads automatically to reduce file size without changing dimensions.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_image_opt_save'); ?>
            <input type="hidden" name="action" value="tk_image_opt_save">
            <input type="hidden" name="tk_tab" value="image-opt">
            <p>
                <label>
                    <input type="checkbox" name="image_opt_enabled" value="1" <?php checked(1, tk_get_option('image_opt_enabled', 0)); ?>>
                    Enable automatic JPG/PNG compression on upload
                </label>
            </p>
            <p class="description">Auto mode: frontend upload is converted to WebP and local JPG/PNG asset URLs are rewritten to WebP automatically.</p>
            <p>
                <label>Compression quality (30-95)</label><br>
                <input type="number" name="image_opt_quality" min="30" max="95" value="<?php echo esc_attr((string) tk_get_option('image_opt_quality', 78)); ?>">
            </p>
            <p class="description">Lower quality = smaller file. Start at 78 for a TinyJPG-like balance.</p>
            <p><button class="button button-primary">Save</button></p>
        </form>
        <hr style="margin:16px 0;">
        <p><strong>Optimize existing media library images</strong></p>
        <button class="button button-secondary" id="tk-image-opt-batch" data-nonce="<?php echo esc_attr(wp_create_nonce('tk_image_opt_batch')); ?>">
            Optimize all JPG/PNG images
        </button>
        <p class="description" id="tk-image-opt-status" style="margin-top:8px;"></p>
    </div>
    <script>
    (function(){
        var button = document.getElementById('tk-image-opt-batch');
        var status = document.getElementById('tk-image-opt-status');
        var progress = document.getElementById('tk-image-opt-progress-bar');
        var progressText = document.getElementById('tk-image-opt-progress-text');
        if (!button) { return; }

        function setStatus(text) {
            if (status) { status.textContent = text; }
        }

        function setProgress(percent) {
            if (!progress) { return; }
            progress.style.width = percent + '%';
            var wrap = progress.parentElement;
            if (wrap) {
                wrap.setAttribute('aria-valuenow', String(percent));
            }
            if (progressText) {
                progressText.textContent = percent + '% complete';
            }
        }

        function formatSaved(bytes) {
            if (!bytes || bytes < 1024) { return bytes + ' B'; }
            var kb = bytes / 1024;
            if (kb < 1024) { return kb.toFixed(1) + ' KB'; }
            return (kb / 1024).toFixed(2) + ' MB';
        }

        function runBatch(offset, totalSaved) {
            button.disabled = true;
            setStatus('Processing...');

            var data = new URLSearchParams();
            data.append('action', 'tk_image_opt_batch');
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
                var savedNow = Number(res.data.saved || 0);
                var newTotalSaved = Number(totalSaved || 0) + savedNow;
                if (typeof res.data.progress === 'number') {
                    setProgress(res.data.progress);
                }
                setStatus(res.data.processed + '/' + res.data.total + ' | Saved ' + formatSaved(newTotalSaved));
                if (res.data.done) {
                    button.disabled = false;
                    return;
                }
                runBatch(res.data.next_offset || 0, newTotalSaved);
            }).catch(function(){
                setStatus('Failed to process. Please try again.');
                button.disabled = false;
            });
        }

        button.addEventListener('click', function(e){
            e.preventDefault();
            runBatch(0, 0);
        });
    })();
    </script>
    <?php
}

function tk_image_opt_save() {
    tk_check_nonce('tk_image_opt_save');
    tk_update_option('image_opt_enabled', !empty($_POST['image_opt_enabled']) ? 1 : 0);
    tk_update_option('image_opt_frontend_to_webp', (int) tk_get_option('webp_serve_enabled', 0));
    tk_update_option('image_opt_rewrite_all_assets', (int) tk_get_option('webp_serve_enabled', 0));
    tk_update_option('image_opt_quality', max(30, min(95, (int) tk_post('image_opt_quality', 78))));
    wp_redirect(add_query_arg(array('page' => 'tool-kits-optimization', 'tk_tab' => 'image-opt', 'tk_saved' => 1), admin_url('admin.php')));
    exit;
}

function tk_image_opt_batch() {
    check_ajax_referer('tk_image_opt_batch', 'nonce');
    if (!tk_is_admin_user()) {
        wp_send_json_error(array('message' => 'unauthorized'));
    }

    $offset = max(0, (int) tk_post('offset', 0));
    $limit = 80;
    $query = new WP_Query(array(
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'post_mime_type' => array('image/jpeg', 'image/png'),
        'posts_per_page' => $limit,
        'offset' => $offset,
        'fields' => 'ids',
        'no_found_rows' => false,
    ));

    $quality = (int) tk_get_option('image_opt_quality', 78);
    $saved_bytes = 0;
    if (!empty($query->posts)) {
        foreach ($query->posts as $attachment_id) {
            $result = tk_image_opt_optimize_attachment($attachment_id, $quality);
            $saved_bytes += isset($result['saved']) ? (int) $result['saved'] : 0;
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
        'saved' => (int) $saved_bytes,
    ));
}
