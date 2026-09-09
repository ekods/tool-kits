<?php
if (!defined('ABSPATH')) { exit; }

function tk_image_opt_init() {
    add_action('admin_post_tk_image_opt_save', 'tk_image_opt_save');
    add_action('admin_post_tk_image_opt_report', 'tk_image_opt_report_handler');
    add_action('admin_post_tk_image_opt_queue_start', 'tk_image_opt_queue_start_handler');
    add_action('admin_post_tk_image_opt_queue_stop', 'tk_image_opt_queue_stop_handler');
    add_action('wp_ajax_tk_image_opt_batch', 'tk_image_opt_batch');
    add_action('tk_image_opt_queue_process', 'tk_image_opt_queue_process');
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
    $quality = (int) tk_get_option('image_opt_quality', 86);
    tk_image_opt_optimize_attachment($attachment_id, $quality, $metadata);
    if (tk_image_opt_should_convert_frontend_to_webp() && !tk_image_opt_safe_derivatives_enabled()) {
        $metadata = tk_image_opt_convert_attachment_to_webp($attachment_id, $metadata);
    }
    return $metadata;
}

function tk_image_opt_frontend_optimize_enabled(): bool {
    return tk_license_features_enabled()
        && (int) tk_get_option('image_opt_enabled', 0) === 1
        && (int) tk_get_option('image_opt_frontend_optimize', 0) === 1;
}

function tk_image_opt_safe_derivatives_enabled(): bool {
    return (int) tk_get_option('image_opt_safe_derivatives', 1) === 1;
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
    if (!tk_image_opt_should_convert_frontend_to_webp() && !tk_image_opt_frontend_optimize_enabled()) {
        return;
    }
    if (is_feed() || (function_exists('is_robots') && is_robots()) || (function_exists('is_trackback') && is_trackback())) {
        return;
    }
    ob_start('tk_image_opt_rewrite_html_buffer');
}

function tk_image_opt_rewrite_html_buffer($html) {
    if (!is_string($html) || $html === '' || stripos($html, '.jpg') === false && stripos($html, '.png') === false && stripos($html, '.jpeg') === false && stripos($html, '.webp') === false) {
        return $html;
    }

    return preg_replace_callback(
        '/https?:\/\/[^\s"\']+\.(?:jpe?g|png|webp)(?:\?[^\s"\']*)?/i',
        function($matches) {
            $url = isset($matches[0]) ? (string) $matches[0] : '';
            if ($url === '') {
                return $url;
            }
            if (tk_image_opt_frontend_optimize_enabled()) {
                $url = tk_image_opt_safe_derivatives_enabled()
                    ? tk_image_opt_get_or_create_optimized_url($url)
                    : tk_image_opt_optimize_url_file($url);
            }
            if (!tk_image_opt_should_convert_frontend_to_webp()) {
                return $url;
            }
            return tk_image_opt_get_or_create_webp_url($url);
        },
        $html
    );
}

function tk_image_opt_optimize_url_file($url) {
    $path = tk_image_opt_url_to_local_path($url);
    if ($path === '' || !file_exists($path)) {
        return $url;
    }
    $last_optimized = (int) get_transient('tk_image_opt_file_' . md5($path));
    $mtime = (int) filemtime($path);
    if ($last_optimized >= $mtime) {
        return $url;
    }
    tk_image_opt_compress_file($path, (int) tk_get_option('image_opt_quality', 86));
    clearstatcache(true, $path);
    set_transient('tk_image_opt_file_' . md5($path), (int) filemtime($path), DAY_IN_SECONDS);
    return $url;
}

function tk_image_opt_derivative_path($path): string {
    if (!is_string($path) || $path === '') {
        return '';
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, array('jpg', 'jpeg', 'png', 'webp'), true)) {
        return '';
    }
    if (preg_match('/-tkopt\.(jpe?g|png|webp)$/i', $path)) {
        return '';
    }
    return (string) preg_replace('/\.(jpe?g|png|webp)$/i', '-tkopt.$1', $path);
}

function tk_image_opt_derivative_url($url): string {
    if (!is_string($url) || $url === '') {
        return '';
    }
    if (preg_match('/-tkopt\.(jpe?g|png|webp)(\?|$)/i', $url)) {
        return $url;
    }
    $next = preg_replace('/\.(jpe?g|png|webp)(\?|$)/i', '-tkopt.$1$2', $url);
    return is_string($next) ? $next : '';
}

function tk_image_opt_get_or_create_optimized_url($url) {
    $path = tk_image_opt_url_to_local_path($url);
    if ($path === '' || !file_exists($path)) {
        return $url;
    }

    $optimized_path = tk_image_opt_derivative_path($path);
    $optimized_url = tk_image_opt_derivative_url($url);
    if ($optimized_path === '' || $optimized_url === '') {
        return $url;
    }

    if (!file_exists($optimized_path) || filemtime($optimized_path) < filemtime($path)) {
        $result = tk_image_opt_create_optimized_derivative($path, $optimized_path, (int) tk_get_option('image_opt_quality', 86));
        if (empty($result['optimized'])) {
            return $url;
        }
    }

    return file_exists($optimized_path) ? $optimized_url : $url;
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

    $main_result = tk_image_opt_safe_derivatives_enabled()
        ? tk_image_opt_create_optimized_derivative($file, tk_image_opt_derivative_path($file), $quality)
        : tk_image_opt_compress_file($file, $quality);
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
            $res = tk_image_opt_safe_derivatives_enabled()
                ? tk_image_opt_create_optimized_derivative($variant, tk_image_opt_derivative_path($variant), $quality)
                : tk_image_opt_compress_file($variant, $quality);
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

function tk_image_opt_create_optimized_derivative($source_path, $target_path, $quality) {
    if (!is_string($source_path) || $source_path === '' || !file_exists($source_path) || !is_string($target_path) || $target_path === '') {
        return array('optimized' => false, 'saved' => 0, 'path' => '');
    }
    if ($source_path === $target_path || preg_match('/-tkopt-tkopt\./i', $target_path)) {
        return array('optimized' => false, 'saved' => 0, 'path' => '');
    }

    $ext = strtolower(pathinfo($source_path, PATHINFO_EXTENSION));
    if (!in_array($ext, array('jpg', 'jpeg', 'png', 'webp'), true)) {
        return array('optimized' => false, 'saved' => 0, 'path' => '');
    }

    $original_size = filesize($source_path);
    if (!is_int($original_size) || $original_size <= 0) {
        return array('optimized' => false, 'saved' => 0, 'path' => '');
    }

    $editor = wp_get_image_editor($source_path);
    if (is_wp_error($editor)) {
        return array('optimized' => false, 'saved' => 0, 'path' => '');
    }

    $editor->set_quality(max(30, min(95, (int) $quality)));
    $max_width = max(0, (int) tk_get_option('image_opt_max_width', 2560));
    $max_height = max(0, (int) tk_get_option('image_opt_max_height', 2560));
    $size = method_exists($editor, 'get_size') ? $editor->get_size() : array();
    $width = isset($size['width']) ? (int) $size['width'] : 0;
    $height = isset($size['height']) ? (int) $size['height'] : 0;
    $was_resized = false;
    if (($max_width > 0 && $width > $max_width) || ($max_height > 0 && $height > $max_height)) {
        $resized = $editor->resize($max_width > 0 ? $max_width : null, $max_height > 0 ? $max_height : null, false);
        if (is_wp_error($resized)) {
            return array('optimized' => false, 'saved' => 0, 'path' => '');
        }
        $was_resized = true;
    }

    $temp_path = preg_replace('/\.(jpe?g|png|webp)$/i', '.tmp-tkopt.$1', $target_path);
    if (!is_string($temp_path) || $temp_path === '') {
        return array('optimized' => false, 'saved' => 0, 'path' => '');
    }

    if ($ext === 'png') {
        $mime = 'image/png';
    } elseif ($ext === 'webp') {
        $mime = 'image/webp';
    } else {
        $mime = 'image/jpeg';
    }

    $saved = $editor->save($temp_path, $mime);
    if (is_wp_error($saved) || !file_exists($temp_path)) {
        if (file_exists($temp_path)) {
            @unlink($temp_path);
        }
        return array('optimized' => false, 'saved' => 0, 'path' => '');
    }

    tk_image_opt_postprocess_file($temp_path, $was_resized);
    clearstatcache(true, $temp_path);
    $optimized_size = filesize($temp_path);
    if (!is_int($optimized_size) || $optimized_size <= 0 || $optimized_size > $original_size) {
        @unlink($temp_path);
        return array('optimized' => false, 'saved' => 0, 'path' => '');
    }

    if (!@rename($temp_path, $target_path)) {
        @copy($temp_path, $target_path);
        @unlink($temp_path);
    }
    clearstatcache(true, $target_path);

    return array(
        'optimized' => file_exists($target_path),
        'saved' => max(0, (int) $original_size - (int) filesize($target_path)),
        'path' => $target_path,
    );
}

function tk_image_opt_compress_file($path, $quality) {
    if (!is_string($path) || $path === '' || !file_exists($path)) {
        return array('optimized' => false, 'saved' => 0);
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, array('jpg', 'jpeg', 'png', 'webp'), true)) {
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

    $max_width = max(0, (int) tk_get_option('image_opt_max_width', 2560));
    $max_height = max(0, (int) tk_get_option('image_opt_max_height', 2560));
    $size = method_exists($editor, 'get_size') ? $editor->get_size() : array();
    $width = isset($size['width']) ? (int) $size['width'] : 0;
    $height = isset($size['height']) ? (int) $size['height'] : 0;
    $was_resized = false;
    if (($max_width > 0 && $width > $max_width) || ($max_height > 0 && $height > $max_height)) {
        $resize_width = $max_width > 0 ? $max_width : null;
        $resize_height = $max_height > 0 ? $max_height : null;
        $resized = $editor->resize($resize_width, $resize_height, false);
        if (is_wp_error($resized)) {
            return array('optimized' => false, 'saved' => 0);
        }
        $was_resized = true;
    }

    $temp_path = preg_replace('/\.(jpe?g|png|webp)$/i', '.tkopt.$1', $path);
    if (!is_string($temp_path) || $temp_path === '') {
        return array('optimized' => false, 'saved' => 0);
    }

    if ($ext === 'png') {
        $mime = 'image/png';
    } elseif ($ext === 'webp') {
        $mime = 'image/webp';
    } else {
        $mime = 'image/jpeg';
    }
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

    tk_image_opt_postprocess_file($path, $was_resized);

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

function tk_image_opt_postprocess_file($path, bool $was_resized = false): void {
    if (!is_string($path) || $path === '' || !file_exists($path)) {
        return;
    }
    if (!class_exists('Imagick')) {
        return;
    }

    $strip_metadata = (int) tk_get_option('image_opt_strip_metadata', 1) === 1;
    $target_dpi = max(0, min(600, (int) tk_get_option('image_opt_target_dpi', 72)));
    $sharpen = $was_resized && (int) tk_get_option('image_opt_sharpen_enabled', 1) === 1;
    if (!$strip_metadata && $target_dpi <= 0 && !$sharpen) {
        return;
    }

    try {
        $image = new Imagick($path);
        if ($strip_metadata) {
            $image->stripImage();
        }
        if ($target_dpi > 0) {
            $image->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);
            $image->setImageResolution($target_dpi, $target_dpi);
        }
        if ($sharpen) {
            $image->unsharpMaskImage(0, 0.5, 0.65, 0.02);
        }
        $image->writeImage($path);
        $image->clear();
        $image->destroy();
    } catch (Exception $e) {
        return;
    }
}

function tk_image_opt_is_supported_mime($mime) {
    return in_array($mime, array('image/jpeg', 'image/jpg', 'image/png', 'image/webp'), true);
}

function tk_image_opt_attachment_ids($offset = 0, $limit = 80): array {
    $query = new WP_Query(array(
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'post_mime_type' => array('image/jpeg', 'image/png', 'image/webp'),
        'posts_per_page' => (int) $limit,
        'offset' => max(0, (int) $offset),
        'fields' => 'ids',
        'no_found_rows' => false,
    ));

    return array(
        'ids' => is_array($query->posts) ? array_map('intval', $query->posts) : array(),
        'total' => (int) $query->found_posts,
    );
}

function tk_image_opt_format_bytes($bytes): string {
    $bytes = max(0, (int) $bytes);
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $kb = $bytes / 1024;
    if ($kb < 1024) {
        return number_format($kb, 1) . ' KB';
    }
    return number_format($kb / 1024, 2) . ' MB';
}

function tk_image_opt_compression_report(): array {
    $scan = tk_image_opt_attachment_ids(0, 500);
    $total_original = 0;
    $total_original_with_derivatives = 0;
    $total_optimized = 0;
    $optimized_files = 0;
    $missing_derivatives = 0;
    $large_unoptimized = array();

    foreach ($scan['ids'] as $attachment_id) {
        $file = get_attached_file($attachment_id);
        if (!is_string($file) || $file === '' || !file_exists($file)) {
            continue;
        }
        $size = filesize($file);
        if (!is_int($size) || $size <= 0) {
            continue;
        }
        $total_original += $size;
        $derivative = tk_image_opt_derivative_path($file);
        if ($derivative !== '' && file_exists($derivative)) {
            $opt_size = filesize($derivative);
            if (is_int($opt_size) && $opt_size > 0) {
                $total_original_with_derivatives += $size;
                $total_optimized += $opt_size;
                $optimized_files++;
            }
        } else {
            $missing_derivatives++;
            if ($size >= 300 * 1024) {
                $large_unoptimized[] = array(
                    'id' => (int) $attachment_id,
                    'title' => get_the_title((int) $attachment_id),
                    'size' => $size,
                    'url' => wp_get_attachment_url((int) $attachment_id),
                );
            }
        }
    }

    usort($large_unoptimized, function($a, $b) {
        return (int) ($b['size'] ?? 0) <=> (int) ($a['size'] ?? 0);
    });

    return array(
        'scanned_at' => time(),
        'scanned' => count($scan['ids']),
        'library_total' => (int) $scan['total'],
        'original_bytes' => (int) $total_original,
        'original_optimized_source_bytes' => (int) $total_original_with_derivatives,
        'optimized_bytes' => (int) $total_optimized,
        'optimized_files' => (int) $optimized_files,
        'missing_derivatives' => (int) $missing_derivatives,
        'estimated_saved_bytes' => max(0, (int) $total_original_with_derivatives - (int) $total_optimized),
        'large_unoptimized' => array_slice($large_unoptimized, 0, 20),
    );
}

function tk_image_opt_report_handler(): void {
    tk_require_admin_post('tk_image_opt_report');
    tk_update_option('image_opt_compression_report', tk_image_opt_compression_report());
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-image-opt', 'tk_image_report' => 1), admin_url('admin.php')) . '#compression-report');
    exit;
}

function tk_image_opt_queue_start_handler(): void {
    tk_require_admin_post('tk_image_opt_queue_start');
    $scan = tk_image_opt_attachment_ids(0, 1);
    tk_update_option('image_opt_queue', array(
        'status' => 'running',
        'offset' => 0,
        'total' => (int) $scan['total'],
        'processed' => 0,
        'saved' => 0,
        'started_at' => time(),
        'updated_at' => time(),
        'last_error' => '',
    ));
    if (!wp_next_scheduled('tk_image_opt_queue_process')) {
        wp_schedule_single_event(time() + 5, 'tk_image_opt_queue_process');
    }
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-image-opt', 'tk_image_queue' => 'started'), admin_url('admin.php')) . '#background-queue');
    exit;
}

function tk_image_opt_queue_stop_handler(): void {
    tk_require_admin_post('tk_image_opt_queue_stop');
    $queue = tk_get_option('image_opt_queue', array());
    $queue = is_array($queue) ? $queue : array();
    $queue['status'] = 'stopped';
    $queue['updated_at'] = time();
    tk_update_option('image_opt_queue', $queue);
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-image-opt', 'tk_image_queue' => 'stopped'), admin_url('admin.php')) . '#background-queue');
    exit;
}

function tk_image_opt_queue_process(): void {
    $queue = tk_get_option('image_opt_queue', array());
    if (!is_array($queue) || ($queue['status'] ?? '') !== 'running') {
        return;
    }
    $offset = max(0, (int) ($queue['offset'] ?? 0));
    $limit = 20;
    $scan = tk_image_opt_attachment_ids($offset, $limit);
    $saved = 0;
    foreach ($scan['ids'] as $attachment_id) {
        $result = tk_image_opt_optimize_attachment((int) $attachment_id, (int) tk_get_option('image_opt_quality', 86));
        $saved += isset($result['saved']) ? (int) $result['saved'] : 0;
    }

    $processed = min((int) $scan['total'], $offset + count($scan['ids']));
    $queue['offset'] = $offset + $limit;
    $queue['total'] = (int) $scan['total'];
    $queue['processed'] = $processed;
    $queue['saved'] = (int) ($queue['saved'] ?? 0) + $saved;
    $queue['updated_at'] = time();
    $queue['status'] = $processed >= (int) $scan['total'] ? 'complete' : 'running';
    tk_update_option('image_opt_queue', $queue);

    if ($queue['status'] === 'running' && !wp_next_scheduled('tk_image_opt_queue_process')) {
        wp_schedule_single_event(time() + 20, 'tk_image_opt_queue_process');
    }
    if ($queue['status'] === 'complete') {
        tk_update_option('image_opt_compression_report', tk_image_opt_compression_report());
    }
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
    $report = tk_get_option('image_opt_compression_report', array());
    $report = is_array($report) ? $report : array();
    $queue = tk_get_option('image_opt_queue', array());
    $queue = is_array($queue) ? $queue : array();
    ?>
    <div class="tk-progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
        <div class="tk-progress-bar" id="tk-image-opt-progress-bar" style="width:0%"></div>
    </div>
    <p><small id="tk-image-opt-progress-text">0% complete</small></p>
    <div class="tk-card tk-image-opt-card">
        <div class="tk-processing-overlay" id="tk-image-opt-overlay" aria-hidden="true">
            <div class="tk-processing-box">
                <span class="tk-processing-spinner" aria-hidden="true"></span>
                <strong>Optimizing images...</strong>
                <span id="tk-image-opt-overlay-text">Preparing media library batch.</span>
            </div>
        </div>
        <h2>Image Optimizer</h2>
        <p>Compress JPEG, PNG, and WebP uploads automatically, strip heavy metadata, cap oversized dimensions, and serve optimized local images on the frontend.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_image_opt_save'); ?>
            <input type="hidden" name="action" value="tk_image_opt_save">
            <input type="hidden" name="tk_tab" value="image-opt">
            <p>
                <label>
                    <input type="checkbox" name="image_opt_enabled" value="1" <?php checked(1, tk_get_option('image_opt_enabled', 0)); ?>>
                    Enable automatic JPEG/PNG/WebP compression on upload
                </label>
            </p>
            <p class="description">Auto mode: frontend upload is converted to WebP and local JPG/PNG asset URLs are rewritten to WebP automatically.</p>
            <p>
                <label>
                    <input type="checkbox" name="image_opt_frontend_optimize" value="1" <?php checked(1, tk_get_option('image_opt_frontend_optimize', 0)); ?>>
                    Optimize local JPEG/PNG/WebP files when they are used on the frontend
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="image_opt_safe_derivatives" value="1" <?php checked(1, tk_get_option('image_opt_safe_derivatives', 1)); ?>>
                    Generate safe optimized copies instead of replacing originals
                </label>
            </p>
            <p class="description">Creates files like <code>image-tkopt.jpg</code> and rewrites frontend URLs to them. If the optimized copy is missing or cannot be generated, the original URL is used.</p>
            <p>
                <label>Compression quality (30-95)</label><br>
                <input type="number" name="image_opt_quality" min="30" max="95" value="<?php echo esc_attr((string) tk_get_option('image_opt_quality', 86)); ?>">
            </p>
            <p class="description">Lower quality = smaller file. Use 84-90 for sharper frontend assets. DPI metadata does not control web sharpness; pixel dimensions and compression quality do.</p>
            <div class="tk-grid tk-grid-3" style="gap:16px; margin-top:16px;">
                <p>
                    <label>Max width (px)</label><br>
                    <input type="number" name="image_opt_max_width" min="0" max="12000" value="<?php echo esc_attr((string) tk_get_option('image_opt_max_width', 0)); ?>">
                </p>
                <p>
                    <label>Max height (px)</label><br>
                    <input type="number" name="image_opt_max_height" min="0" max="12000" value="<?php echo esc_attr((string) tk_get_option('image_opt_max_height', 0)); ?>">
                </p>
                <p>
                    <label>Target DPI metadata</label><br>
                    <input type="number" name="image_opt_target_dpi" min="0" max="600" value="<?php echo esc_attr((string) tk_get_option('image_opt_target_dpi', 72)); ?>">
                </p>
            </div>
            <p class="description">Default is TinyJPG-like compression without resizing. Keep width/height at 0 to preserve original pixel dimensions; only set a cap if you intentionally want large images resized.</p>
            <p>
                <label>
                    <input type="checkbox" name="image_opt_strip_metadata" value="1" <?php checked(1, tk_get_option('image_opt_strip_metadata', 1)); ?>>
                    Strip EXIF/profile metadata after compression
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="image_opt_sharpen_enabled" value="1" <?php checked(1, tk_get_option('image_opt_sharpen_enabled', 1)); ?>>
                    Preserve sharpness after resize
                </label>
            </p>
            <p><button class="button button-primary">Save</button></p>
        </form>
        <hr style="margin:16px 0;">
        <p><strong>Optimize existing media library images</strong></p>
        <button class="button button-secondary" id="tk-image-opt-batch" data-nonce="<?php echo esc_attr(wp_create_nonce('tk_image_opt_batch')); ?>">
            Optimize all JPEG/PNG/WebP images
        </button>
        <p class="description" id="tk-image-opt-status" style="margin-top:8px;"></p>
    </div>
    <div class="tk-card" id="background-queue" style="margin-top:16px;">
        <h3>Background Queue</h3>
        <p>Run optimization in small WP-Cron batches to avoid admin timeouts on large media libraries.</p>
        <?php
        $queue_status = sanitize_key((string) ($queue['status'] ?? 'idle'));
        $queue_total = max(0, (int) ($queue['total'] ?? 0));
        $queue_processed = max(0, (int) ($queue['processed'] ?? 0));
        $queue_progress = $queue_total > 0 ? min(100, (int) round(($queue_processed / $queue_total) * 100)) : 0;
        ?>
        <p>
            <span class="tk-badge <?php echo esc_attr($queue_status === 'complete' ? 'tk-on' : ($queue_status === 'running' ? 'tk-warn' : '')); ?>"><?php echo esc_html(strtoupper($queue_status)); ?></span>
            <span class="description"><?php echo esc_html((string) $queue_processed); ?>/<?php echo esc_html((string) $queue_total); ?> processed, saved <?php echo esc_html(tk_image_opt_format_bytes((int) ($queue['saved'] ?? 0))); ?></span>
        </p>
        <div class="tk-progress" role="progressbar" aria-valuenow="<?php echo esc_attr((string) $queue_progress); ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="tk-progress-bar" style="width:<?php echo esc_attr((string) $queue_progress); ?>%"></div>
        </div>
        <p style="margin-top:12px;">
            <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_image_opt_queue_start'), 'tk_image_opt_queue_start')); ?>">Start Background Queue</a>
            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_image_opt_queue_stop'), 'tk_image_opt_queue_stop')); ?>">Stop Queue</a>
        </p>
    </div>
    <div class="tk-card" id="compression-report" style="margin-top:16px;">
        <h3>Compression Report</h3>
        <p>Summarizes generated <code>-tkopt</code> files and large images that still need optimized derivatives.</p>
        <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_image_opt_report'), 'tk_image_opt_report')); ?>">Generate Compression Report</a></p>
        <?php if (!empty($report)) : ?>
            <div class="tk-grid tk-grid-3" style="gap:16px;">
                <div class="tk-card"><strong>Scanned</strong><br><?php echo esc_html((string) ($report['scanned'] ?? 0)); ?> / <?php echo esc_html((string) ($report['library_total'] ?? 0)); ?></div>
                <div class="tk-card"><strong>Optimized Copies</strong><br><?php echo esc_html((string) ($report['optimized_files'] ?? 0)); ?></div>
                <div class="tk-card"><strong>Missing Copies</strong><br><?php echo esc_html((string) ($report['missing_derivatives'] ?? 0)); ?></div>
            </div>
            <p><strong>Estimated saved:</strong> <?php echo esc_html(tk_image_opt_format_bytes((int) ($report['estimated_saved_bytes'] ?? 0))); ?> from <?php echo esc_html(tk_image_opt_format_bytes((int) ($report['original_optimized_source_bytes'] ?? 0))); ?> optimized source bytes.</p>
            <p class="description">Last report: <?php echo !empty($report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $report['scanned_at'])) : '-'; ?></p>
            <?php $large = isset($report['large_unoptimized']) && is_array($report['large_unoptimized']) ? $report['large_unoptimized'] : array(); ?>
            <?php if (!empty($large)) : ?>
                <table class="widefat striped">
                    <thead><tr><th>Image</th><th>Size</th><th>URL</th></tr></thead>
                    <tbody>
                    <?php foreach ($large as $item) : ?>
                        <tr>
                            <td><?php echo esc_html((string) ($item['title'] ?? '')); ?></td>
                            <td><?php echo esc_html(tk_image_opt_format_bytes((int) ($item['size'] ?? 0))); ?></td>
                            <td><a href="<?php echo esc_url((string) ($item['url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($item['url'] ?? '')); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php else : ?>
            <p class="description">No compression report yet.</p>
        <?php endif; ?>
    </div>
    <script>
    (function(){
        var button = document.getElementById('tk-image-opt-batch');
        var status = document.getElementById('tk-image-opt-status');
        var progress = document.getElementById('tk-image-opt-progress-bar');
        var progressText = document.getElementById('tk-image-opt-progress-text');
        var overlay = document.getElementById('tk-image-opt-overlay');
        var overlayText = document.getElementById('tk-image-opt-overlay-text');
        if (!button) { return; }

        function setStatus(text) {
            if (status) { status.textContent = text; }
            if (overlayText) { overlayText.textContent = text; }
        }

        function setProcessing(isProcessing) {
            if (!overlay) { return; }
            overlay.classList.toggle('is-active', !!isProcessing);
            overlay.setAttribute('aria-hidden', isProcessing ? 'false' : 'true');
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
            setProcessing(true);
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
                    setProcessing(false);
                    return;
                }
                runBatch(res.data.next_offset || 0, newTotalSaved);
            }).catch(function(){
                setStatus('Failed to process. Please try again.');
                button.disabled = false;
                setProcessing(false);
            });
        }

        button.addEventListener('click', function(e){
            e.preventDefault();
            setProgress(0);
            runBatch(0, 0);
        });
    })();
    </script>
    <?php
}

function tk_image_opt_save() {
    tk_require_admin_post('tk_image_opt_save');
    tk_update_option('image_opt_enabled', !empty($_POST['image_opt_enabled']) ? 1 : 0);
    tk_update_option('image_opt_frontend_optimize', !empty($_POST['image_opt_frontend_optimize']) ? 1 : 0);
    tk_update_option('image_opt_safe_derivatives', !empty($_POST['image_opt_safe_derivatives']) ? 1 : 0);
    tk_update_option('image_opt_frontend_to_webp', (int) tk_get_option('webp_serve_enabled', 0));
    tk_update_option('image_opt_rewrite_all_assets', (int) tk_get_option('webp_serve_enabled', 0));
    tk_update_option('image_opt_quality', max(30, min(95, (int) tk_post('image_opt_quality', 86))));
    tk_update_option('image_opt_max_width', max(0, min(12000, (int) tk_post('image_opt_max_width', 0))));
    tk_update_option('image_opt_max_height', max(0, min(12000, (int) tk_post('image_opt_max_height', 0))));
    tk_update_option('image_opt_target_dpi', max(0, min(600, (int) tk_post('image_opt_target_dpi', 72))));
    tk_update_option('image_opt_strip_metadata', !empty($_POST['image_opt_strip_metadata']) ? 1 : 0);
    tk_update_option('image_opt_sharpen_enabled', !empty($_POST['image_opt_sharpen_enabled']) ? 1 : 0);
    wp_redirect(add_query_arg(array('page' => 'tool-kits-image-opt', 'tk_saved' => 1), admin_url('admin.php')));
    exit;
}

function tk_image_opt_batch() {
    check_ajax_referer('tk_image_opt_batch', 'nonce');
    if (!tk_is_admin_user()) {
        wp_send_json_error(array('message' => 'unauthorized'));
    }

    $offset = max(0, (int) tk_post('offset', 0));
    $limit = 80;
    $scan = tk_image_opt_attachment_ids($offset, $limit);

    $quality = (int) tk_get_option('image_opt_quality', 86);
    $saved_bytes = 0;
    if (!empty($scan['ids'])) {
        foreach ($scan['ids'] as $attachment_id) {
            $result = tk_image_opt_optimize_attachment($attachment_id, $quality);
            $saved_bytes += isset($result['saved']) ? (int) $result['saved'] : 0;
        }
    }

    $next = $offset + $limit;
    $total = (int) $scan['total'];
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
