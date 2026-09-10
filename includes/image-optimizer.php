<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/image-optimizer-engine.php';

function tk_image_opt_init() {
    add_action('admin_post_tk_image_opt_save', 'tk_image_opt_save');
    add_action('admin_post_tk_image_opt_report', 'tk_image_opt_report_handler');
    add_action('admin_post_tk_image_opt_cleanup', 'tk_image_opt_cleanup_handler');
    add_action('admin_post_tk_image_opt_queue_start', 'tk_image_opt_queue_start_handler');
    add_action('admin_post_tk_image_opt_queue_stop', 'tk_image_opt_queue_stop_handler');
    add_action('wp_ajax_tk_image_opt_batch', 'tk_image_opt_batch');
    add_action('tk_image_opt_queue_process', 'tk_image_opt_queue_process');
    add_filter('wp_generate_attachment_metadata', 'tk_image_opt_on_upload', 15, 2);
    add_filter('wp_calculate_image_srcset', 'tk_image_opt_original_only_srcset', 30, 5);
    add_filter('wp_get_attachment_image_src', 'tk_image_opt_filter_image_src', 30, 4);
    add_action('template_redirect', 'tk_image_opt_start_output_rewrite', 1);
}

function tk_image_opt_on_upload($metadata, $attachment_id) {
    if (!tk_license_features_enabled()) {
        return $metadata;
    }
    if (!tk_get_option('image_opt_enabled', 0)) {
        return $metadata;
    }
    $quality = (int) tk_get_option('image_opt_quality', 92);
    tk_image_opt_optimize_attachment($attachment_id, $quality, $metadata);
    return $metadata;
}

function tk_image_opt_frontend_optimize_enabled(): bool {
    return tk_license_features_enabled()
        && (int) tk_get_option('image_opt_enabled', 0) === 1
        && (int) tk_get_option('image_opt_frontend_optimize', 0) === 1;
}

function tk_image_opt_original_only_srcset_enabled(): bool {
    return tk_license_features_enabled()
        && (int) tk_get_option('image_opt_enabled', 0) === 1
        && (int) tk_get_option('image_opt_srcset_original_only', 1) === 1;
}

function tk_image_opt_original_only_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
    if (is_admin() || !tk_image_opt_original_only_srcset_enabled() || !is_array($sources) || empty($sources) || !is_array($image_meta)) {
        return $sources;
    }

    $width = isset($image_meta['width']) ? (int) $image_meta['width'] : 0;
    $relative_file = isset($image_meta['file']) && is_string($image_meta['file']) ? ltrim($image_meta['file'], '/') : '';
    $url = '';

    $full_file = tk_image_opt_source_file($attachment_id, $image_meta);
    $full_size = $full_file !== '' ? @getimagesize($full_file) : false;
    if ($full_size) {
        $url = tk_image_opt_path_to_upload_url($full_file);
        $width = (int) $full_size[0];
    }

    if ($url === '' && $relative_file !== '') {
        $upload = wp_get_upload_dir();
        if (empty($upload['error']) && !empty($upload['baseurl'])) {
            $url = trailingslashit((string) $upload['baseurl']) . $relative_file;
        }
    }

    if ($url === '' || $width <= 0) {
        $largest = array();
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $value = isset($source['value']) ? (int) $source['value'] : 0;
            if ($value > (int) ($largest['value'] ?? 0)) {
                $largest = $source;
            }
        }
        return !empty($largest) ? array((int) ($largest['value'] ?? 0) => $largest) : $sources;
    }

    if (tk_image_opt_frontend_optimize_enabled()) {
        $url = tk_image_opt_get_or_create_optimized_url($url);
    }

    return array(
        $width => array(
            'url' => $url,
            'descriptor' => 'w',
            'value' => $width,
        ),
    );
}

function tk_image_opt_safe_derivatives_enabled(): bool {
    return true;
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
    if (!tk_image_opt_frontend_optimize_enabled()) {
        return;
    }
    if (is_feed() || (function_exists('is_robots') && is_robots()) || (function_exists('is_trackback') && is_trackback())) {
        return;
    }
    ob_start('tk_image_opt_rewrite_html_buffer');
}

function tk_image_opt_rewrite_html_buffer($html) {
    if (!is_string($html) || $html === '' || !class_exists('WP_HTML_Tag_Processor')) {
        return $html;
    }
    $processor = new WP_HTML_Tag_Processor($html);
    while ($processor->next_tag()) {
        if (!in_array($processor->get_tag(), array('IMG', 'SOURCE'), true)) {
            continue;
        }
        foreach (array('src', 'data-src', 'data-lazy-src', 'data-tk-lazy-src') as $attribute) {
            $url = $processor->get_attribute($attribute);
            if (is_string($url) && $url !== '') {
                $processor->set_attribute($attribute, tk_image_opt_frontend_url($url));
            }
        }
        foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-tk-lazy-srcset') as $attribute) {
            $value = $processor->get_attribute($attribute);
            if (!is_string($value) || $value === '' || strpos($value, 'data:') !== false) {
                continue;
            }
            $sources = array();
            foreach (explode(',', $value) as $candidate) {
                $parts = preg_split('/\\s+/', trim($candidate), 2);
                $url = tk_image_opt_frontend_url($parts[0]);
                if (tk_image_opt_original_only_srcset_enabled()) {
                    $path = tk_image_opt_url_to_local_path($url);
                    $size = $path !== '' ? @getimagesize($path) : false;
                    if ($size) {
                        $sources = array($url . ' ' . (int) $size[0] . 'w');
                        break;
                    }
                }
                $sources[] = $url . (isset($parts[1]) ? ' ' . $parts[1] : '');
            }
            $processor->set_attribute($attribute, implode(', ', $sources));
        }
    }
    return $processor->get_updated_html();
}

function tk_image_opt_frontend_url(string $url): string {
    if (tk_image_opt_original_only_srcset_enabled()) {
        $url = tk_image_opt_original_url_for_intermediate_url($url);
    }
    return tk_image_opt_frontend_optimize_enabled() ? tk_image_opt_get_or_create_optimized_url($url) : $url;
}

function tk_image_opt_filter_image_src($image, $attachment_id, $size = null, $icon = false) {
    if (is_admin() || $icon || !is_array($image) || !tk_image_opt_frontend_optimize_enabled()) {
        return $image;
    }
    if (tk_image_opt_original_only_srcset_enabled()) {
        $file = tk_image_opt_source_file($attachment_id);
        $full_url = $file !== '' ? tk_image_opt_path_to_upload_url($file) : '';
        if ($full_url !== '') {
            $image[0] = $full_url;
        }
    }
    $image[0] = tk_image_opt_frontend_url($image[0]);
    $path = tk_image_opt_url_to_local_path($image[0]);
    $dimensions = $path !== '' ? @getimagesize($path) : false;
    if ($dimensions) {
        $image[1] = (int) $dimensions[0];
        $image[2] = (int) $dimensions[1];
        $image[3] = false;
    }
    return $image;
}

function tk_image_opt_optimize_url_file($url) {
    return tk_image_opt_get_or_create_optimized_url($url);
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

function tk_image_opt_path_to_upload_url(string $path): string {
    $upload = wp_get_upload_dir();
    if (!empty($upload['error']) || empty($upload['basedir']) || empty($upload['baseurl'])) {
        return '';
    }

    $base_dir = wp_normalize_path(realpath($upload['basedir']) ?: (string) $upload['basedir']);
    $path = wp_normalize_path(realpath($path) ?: ((realpath(dirname($path)) ?: dirname($path)) . '/' . basename($path)));
    if (strpos($path, trailingslashit($base_dir)) !== 0) {
        return '';
    }

    $relative = ltrim(substr($path, strlen(trailingslashit($base_dir))), '/');
    return trailingslashit((string) $upload['baseurl']) . str_replace('%2F', '/', rawurlencode($relative));
}

function tk_image_opt_original_url_for_intermediate_url(string $url): string {
    $path = tk_image_opt_url_to_local_path($url);
    if ($path === '') {
        return $url;
    }
    if (preg_match('/-scaled\\.(jpe?g|png|webp)$/i', $path)) {
        $id = attachment_url_to_postid($url);
        $source = $id ? tk_image_opt_source_file($id) : '';
        return $source !== '' ? tk_image_opt_path_to_upload_url($source) : $url;
    }
    if (!preg_match('/-\\d+x\\d+(?:-tkopt)?\\.(jpe?g|png|webp)$/i', $path)) {
        return $url;
    }
    $base = preg_replace('/-\\d+x\\d+(?:-tkopt)?\\.(jpe?g|png|webp)$/i', '', $path);
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $candidates = array();
    foreach (array_unique(array($extension, 'jpg', 'jpeg', 'png', 'webp')) as $ext) {
        foreach (array('', '-scaled') as $suffix) {
            $candidate = $base . $suffix . '.' . $ext;
            if (!is_file($candidate)) {
                continue;
            }
            $candidate_url = tk_image_opt_path_to_upload_url($candidate);
            $id = attachment_url_to_postid($candidate_url);
            if ($id) {
                $source = tk_image_opt_source_file($id);
                return $source !== '' ? tk_image_opt_path_to_upload_url($source) : $candidate_url;
            }
            $candidates[] = $candidate_url;
        }
    }
    // Unregistered legacy WebP files may themselves be lossy copies of JPG/PNG.
    if ($extension === 'webp') {
        foreach ($candidates as $candidate_url) {
            if (preg_match('/\\.(jpe?g|png)$/i', $candidate_url)) {
                return $candidate_url;
            }
        }
    }
    return $candidates[0] ?? $url;
}

function tk_image_opt_get_or_create_optimized_url($url) {
    $path = tk_image_opt_url_to_local_path($url);
    if ($path === '') {
        return $url;
    }
    // Resolve legacy optimized URLs back to their source, including old WebP copies.
    if (preg_match('/-tkopt\\.(jpe?g|png|webp)$/i', $path)) {
        foreach (tk_image_opt_source_candidates_for_derivative($path) as $candidate) {
            if (is_file($candidate)) {
                $path = $candidate;
                $url = tk_image_opt_path_to_upload_url($path);
                break;
            }
        }
    }
    if (!is_file($path)) {
        return $url;
    }
    if (tk_image_opt_original_only_srcset_enabled()) {
        $url = tk_image_opt_original_url_for_intermediate_url($url);
        $path = tk_image_opt_url_to_local_path($url);
        if ($path === '' || !is_file($path)) {
            return $url;
        }
    }
    $quality = (int) tk_get_option('image_opt_quality', 95);
    $candidates = array(tk_image_opt_derivative_path($path));
    if (tk_get_option('webp_serve_enabled', 0) && preg_match('/\\.(jpe?g|png)$/i', $path)) {
        $candidates[] = $path . '-tkopt.webp';
    }
    $best = '';
    $bytes = filesize($path);
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && tk_image_opt_valid_derivative($path, $candidate, $quality) && filesize($candidate) < $bytes) {
            $best = $candidate;
            $bytes = filesize($candidate);
        }
    }
    if ($best === '') {
        return $url;
    }
    return add_query_arg('tkopt', substr(tk_image_opt_signature($path, $quality), 0, 12), tk_image_opt_path_to_upload_url($best));
}

function tk_image_opt_get_or_create_webp_url($url) {
    return tk_image_opt_get_or_create_optimized_url($url);
}

function tk_image_opt_url_to_local_path($url) {
    if (!is_string($url) || $url === '') {
        return '';
    }
    $upload = wp_get_upload_dir();
    if (!empty($upload['error']) || empty($upload['basedir']) || empty($upload['baseurl'])) {
        return '';
    }
    $parts = wp_parse_url($url);
    $base = wp_parse_url($upload['baseurl']);
    if (!is_array($parts) || empty($parts['path']) || !is_array($base)
        || (!empty($parts['scheme']) && !in_array(strtolower($parts['scheme']), array('http', 'https'), true))
        || (!empty($parts['host']) && strcasecmp($parts['host'], $base['host'] ?? '') !== 0)
        || (isset($parts['port']) && $parts['port'] !== ($base['port'] ?? null))) {
        return '';
    }
    $prefix = trailingslashit(rawurldecode($base['path'] ?? '/'));
    $request = rawurldecode($parts['path']);
    if (strpos($request, $prefix) !== 0 || strpos($request, chr(0)) !== false || strpos($request, '\\\\') !== false) {
        return '';
    }
    $relative = substr($request, strlen($prefix));
    if (in_array('..', explode('/', $relative), true)) {
        return '';
    }
    $root = realpath($upload['basedir']);
    if (!$root) {
        return '';
    }
    $path = $root . '/' . $relative;
    $parent = realpath(dirname($path));
    if (!$parent || strpos($parent . '/', $root . '/') !== 0) {
        return '';
    }
    if (file_exists($path) && strpos((string) realpath($path), $root . '/') !== 0) {
        return '';
    }
    return $path;
}

function tk_image_opt_optimize_attachment($attachment_id, $quality, $metadata = null) {
    $file = tk_image_opt_source_file($attachment_id, $metadata);
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

    $main_result = tk_image_opt_generate_copies($file, $quality);
    if ($main_result['optimized']) {
        $files_optimized++;
        $bytes_saved += $main_result['saved'];
    }

    if (tk_image_opt_original_only_srcset_enabled()) {
        tk_image_opt_cleanup_intermediate_derivatives($file, $metadata);
        if ($files_optimized > 0) {
            update_post_meta($attachment_id, '_tk_image_opt_optimized_at', time());
            update_post_meta($attachment_id, '_tk_image_opt_saved_bytes', (int) $bytes_saved);
        }
        return array(
            'files' => $files_optimized,
            'saved' => (int) $bytes_saved,
        );
    }

    if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
        $dir = dirname($file);
        foreach ($metadata['sizes'] as $size) {
            if (empty($size['file']) || basename($size['file']) !== $size['file']) {
                continue;
            }
            $variant = trailingslashit($dir) . $size['file'];
            $res = tk_image_opt_generate_copies($variant, $quality);
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

function tk_image_opt_cleanup_intermediate_derivatives(string $main_file, $metadata): int {
    if (tk_image_opt_path_to_upload_url($main_file) === ''
        || !is_array($metadata) || empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
        return 0;
    }

    $removed = 0;
    $dir = dirname($main_file);
    $main_derivative = tk_image_opt_derivative_path($main_file);

    foreach ($metadata['sizes'] as $size) {
        if (empty($size['file']) || !is_string($size['file']) || basename($size['file']) !== $size['file']) {
            continue;
        }
        $variant = trailingslashit($dir) . $size['file'];
        $derivative = tk_image_opt_derivative_path($variant);
        if ($derivative === '' || $derivative === $main_derivative) {
            continue;
        }

        $candidates = array($derivative, $variant . '-tkopt.webp');
        $without_ext = preg_replace('/\.(jpe?g|png|webp)$/i', '', $derivative);
        if (is_string($without_ext) && $without_ext !== '') {
            foreach (array('jpg', 'jpeg', 'png', 'webp') as $ext) {
                $candidates[] = $without_ext . '.' . $ext;
            }
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if ($candidate === $main_derivative || !file_exists($candidate)) {
                continue;
            }
            if (@unlink($candidate)) {
                @unlink($candidate . '.json');
                $removed++;
            }
        }
    }

    return $removed;
}

function tk_image_opt_create_optimized_derivative($source_path, $target_path, $quality) {
    if (!is_string($source_path) || !is_string($target_path) || $target_path === '') {
        return array('optimized' => false, 'saved' => 0, 'path' => '');
    }
    $result = tk_image_opt_encode($source_path, $target_path, $quality);
    if (!$result['optimized']) {
        tk_image_opt_forget_derivative_report($target_path);
    }
    return $result;
}

function tk_image_opt_compress_file($path, $quality) {
    return tk_image_opt_create_optimized_derivative($path, tk_image_opt_derivative_path($path), $quality);
}

function tk_image_opt_postprocess_file($path, bool $was_resized = false): void {
    // Kept for compatibility. Metadata is applied before the single encoding pass.
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
        'orderby' => 'ID',
        'order' => 'ASC',
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
        $file = tk_image_opt_source_file($attachment_id);
        if (!is_string($file) || $file === '' || !file_exists($file)) {
            continue;
        }
        $size = filesize($file);
        if (!is_int($size) || $size <= 0) {
            continue;
        }
        $total_original += $size;
        $derivative = tk_image_opt_derivative_path($file);
        $quality = (int) tk_get_option('image_opt_quality', 95);
        $best_bytes = $size;
        $best_derivative = '';
        $candidates = array($derivative);
        if (tk_get_option('webp_serve_enabled', 0)) {
            $candidates[] = $file . '-tkopt.webp';
        }
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && tk_image_opt_valid_derivative($file, $candidate, $quality) && filesize($candidate) < $best_bytes) {
                $best_bytes = filesize($candidate);
                $best_derivative = $candidate;
            }
        }
        $derivative = $best_derivative;
        if ($derivative !== '') {
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

function tk_image_opt_source_candidates_for_derivative(string $path): array {
    $candidates = array();
    if (preg_match('/\\.(jpe?g|png)-tkopt\\.webp$/i', $path)) {
        $candidates[] = substr($path, 0, -11);
    } elseif (preg_match('/-tkopt\.webp$/i', $path)) {
        $stem = substr($path, 0, -11);
        $native_webp = $stem . '.webp';
        if (is_file($native_webp) && attachment_url_to_postid(tk_image_opt_path_to_upload_url($native_webp))) {
            $candidates[] = $native_webp;
        }
        foreach (array('jpg', 'jpeg', 'png') as $ext) {
            $candidates[] = $stem . '.' . $ext;
        }
    }
    $base = preg_replace('/-tkopt\.(jpe?g|png|webp)$/i', '.$1', $path);
    if (is_string($base) && $base !== $path) {
        $candidates[] = $base;
    }

    $without_tkopt = preg_replace('/-tkopt\.(jpe?g|png|webp)$/i', '', $path);
    if (is_string($without_tkopt) && $without_tkopt !== '') {
        foreach (array('jpg', 'jpeg', 'png', 'webp') as $ext) {
            $candidates[] = $without_tkopt . '.' . $ext;
        }
    }

    if (preg_match('/-\d+x\d+-tkopt\.(jpe?g|png|webp)$/i', $path)) {
        $main = preg_replace('/-\d+x\d+-tkopt\.(jpe?g|png|webp)$/i', '.$1', $path);
        if (is_string($main) && $main !== '') {
            $candidates[] = $main;
        }
        $main_without_ext = preg_replace('/-\d+x\d+-tkopt\.(jpe?g|png|webp)$/i', '', $path);
        if (is_string($main_without_ext) && $main_without_ext !== '') {
            foreach (array('jpg', 'jpeg', 'png', 'webp') as $ext) {
                $candidates[] = $main_without_ext . '.' . $ext;
            }
        }
    }

    return array_values(array_unique(array_filter($candidates)));
}

function tk_image_opt_cleanup_derivatives(): array {
    $upload = wp_get_upload_dir();
    if (!empty($upload['error']) || empty($upload['basedir'])) {
        return array(
            'scanned_at' => time(),
            'scanned' => 0,
            'removed' => 0,
            'bytes_removed' => 0,
            'items' => array(),
            'error' => !empty($upload['error']) ? (string) $upload['error'] : 'Upload directory is unavailable.',
        );
    }

    $base_dir = wp_normalize_path((string) $upload['basedir']);
    $removed = 0;
    $bytes_removed = 0;
    $scanned = 0;
    $items = array();
    $original_only = tk_image_opt_original_only_srcset_enabled();

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS)
        );
    } catch (Exception $e) {
        return array(
            'scanned_at' => time(),
            'scanned' => 0,
            'removed' => 0,
            'bytes_removed' => 0,
            'items' => array(),
            'error' => $e->getMessage(),
        );
    }

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $path = wp_normalize_path($file->getPathname());
        if (!preg_match('/-tkopt\.(jpe?g|png|webp)$/i', $path)) {
            continue;
        }
        $scanned++;
        $is_intermediate = false;
        // Only classify a thumbnail as unused when attachment metadata confirms it.
        foreach (tk_image_opt_source_candidates_for_derivative($path) as $source_candidate) {
            $source_url = tk_image_opt_path_to_upload_url($source_candidate);
            $full_url = tk_image_opt_original_url_for_intermediate_url($source_url);
            if ($full_url === $source_url) {
                continue;
            }
            $attachment_id = attachment_url_to_postid($full_url);
            $metadata = $attachment_id ? wp_get_attachment_metadata($attachment_id) : array();
            foreach (($metadata['sizes'] ?? array()) as $size) {
                if (($size['file'] ?? '') === basename($source_candidate)) {
                    $is_intermediate = true;
                    break 2;
                }
            }
        }
        $source_exists = false;
        foreach (tk_image_opt_source_candidates_for_derivative($path) as $candidate) {
            if (file_exists($candidate)) {
                $source_exists = true;
                break;
            }
        }
        $reason = '';
        if (!$source_exists) {
            $reason = 'Missing source image';
        } elseif ($original_only && $is_intermediate) {
            $reason = 'Unused intermediate derivative in original-only srcset mode';
        }
        if ($reason === '') {
            continue;
        }
        $size = filesize($path);
        $size = is_int($size) ? $size : 0;
        if (@unlink($path)) {
            @unlink($path . '.json');
            $removed++;
            $bytes_removed += $size;
            $items[] = array(
                'file' => ltrim(substr($path, strlen(trailingslashit($base_dir))), '/'),
                'size' => $size,
                'reason' => $reason,
            );
        }
        if ($removed >= 1000) {
            break;
        }
    }

    return array(
        'scanned_at' => time(),
        'scanned' => $scanned,
        'removed' => $removed,
        'bytes_removed' => (int) $bytes_removed,
        'items' => array_slice($items, 0, 100),
        'error' => '',
    );
}

function tk_image_opt_cleanup_handler(): void {
    tk_require_admin_post('tk_image_opt_cleanup');
    tk_update_option('image_opt_cleanup_report', tk_image_opt_cleanup_derivatives());
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-image-opt', 'tk_image_cleanup' => 1), admin_url('admin.php')) . '#image-maintenance');
    exit;
}

function tk_image_opt_queue_start_handler(): void {
    tk_require_admin_post('tk_image_opt_queue_start');
    $scan = tk_image_opt_attachment_ids(0, 1);
    tk_update_option('image_opt_queue', array(
        'status' => 'running',
        'run_id' => wp_generate_uuid4(),
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
    wp_clear_scheduled_hook('tk_image_opt_queue_process');
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-image-opt', 'tk_image_queue' => 'stopped'), admin_url('admin.php')) . '#background-queue');
    exit;
}

function tk_image_opt_queue_process(): void {
    if (!tk_license_features_enabled()) {
        return;
    }
    $upload = wp_get_upload_dir();
    if (!empty($upload['error']) || empty($upload['basedir']) || !is_dir($upload['basedir'])) {
        return;
    }
    $lock = @fopen(trailingslashit($upload['basedir']) . '.tkopt-queue.lock', 'c');
    if (!$lock) {
        return;
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return;
    }
    try {
        $queue = tk_get_option('image_opt_queue', array());
        if (!is_array($queue) || ($queue['status'] ?? '') !== 'running') {
            return;
        }
        $offset = max(0, (int) ($queue['offset'] ?? 0));
        $scan = tk_image_opt_attachment_ids($offset, 5);
        $saved = 0;
        $count = 0;
        $started = microtime(true);
        foreach ($scan['ids'] as $attachment_id) {
            $result = tk_image_opt_optimize_attachment((int) $attachment_id, (int) tk_get_option('image_opt_quality', 95));
            $saved += (int) ($result['saved'] ?? 0);
            $count++;
            if (microtime(true) - $started >= 10) {
                break;
            }
        }

        // A stop or restart submitted during encoding must win over this worker.
        wp_cache_delete('tk_options', 'options');
        $current = tk_get_option('image_opt_queue', array());
        if (($current['status'] ?? '') !== 'running' || ($current['run_id'] ?? '') !== ($queue['run_id'] ?? '')) {
            return;
        }
        $queue['offset'] = $offset + $count;
        $queue['total'] = (int) $scan['total'];
        $queue['processed'] = min((int) $scan['total'], $queue['offset']);
        $queue['saved'] = (int) ($queue['saved'] ?? 0) + $saved;
        $queue['updated_at'] = time();
        $queue['status'] = empty($scan['ids']) || $queue['offset'] >= (int) $scan['total'] ? 'complete' : 'running';
        tk_update_option('image_opt_queue', $queue);
        if ($queue['status'] === 'running' && !wp_next_scheduled('tk_image_opt_queue_process')) {
            wp_schedule_single_event(time() + 20, 'tk_image_opt_queue_process');
        }
        if ($queue['status'] === 'complete') {
            tk_update_option('image_opt_compression_report', tk_image_opt_compression_report());
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function tk_image_opt_convert_attachment_to_webp($attachment_id, $metadata) {
    $source = tk_image_opt_source_file($attachment_id, $metadata);
    if ($source !== '') {
        tk_image_opt_generate_copies($source, (int) tk_get_option('image_opt_quality', 95));
    }
    return $metadata;
}

function tk_image_opt_convert_file_to_webp($path, $quality, $delete_original = false) {
    $failed = array('new_path' => '', 'new_basename' => '', 'new_size' => 0);
    if (!is_string($path) || !preg_match('/\\.(jpe?g|png)$/i', $path)) {
        return $failed;
    }
    $result = tk_image_opt_encode($path, $path . '-tkopt.webp', $quality);
    if (!$result['optimized']) {
        return $failed;
    }
    return array('new_path' => $result['path'], 'new_basename' => basename($result['path']), 'new_size' => filesize($result['path']));
}

function tk_render_image_opt_panel() {
    if (!tk_is_admin_user()) return;
    $report = tk_get_option('image_opt_compression_report', array());
    $report = is_array($report) ? $report : array();
    $cleanup_report = tk_get_option('image_opt_cleanup_report', array());
    $cleanup_report = is_array($cleanup_report) ? $cleanup_report : array();
    $queue = tk_get_option('image_opt_queue', array());
    $queue = is_array($queue) ? $queue : array();
    require __DIR__ . '/../templates/image-optimizer.php';
}

function tk_image_opt_save() {
    tk_require_admin_post('tk_image_opt_save');
    tk_update_option('image_opt_enabled', !empty($_POST['image_opt_enabled']) ? 1 : 0);
    tk_update_option('image_opt_frontend_optimize', !empty($_POST['image_opt_frontend_optimize']) ? 1 : 0);
    tk_update_option('image_opt_safe_derivatives', 1);
    tk_update_option('image_opt_preserve_hires', !empty($_POST['image_opt_preserve_hires']) ? 1 : 0);
    tk_update_option('image_opt_srcset_original_only', !empty($_POST['image_opt_srcset_original_only']) ? 1 : 0);
    tk_update_option('image_opt_frontend_to_webp', (int) tk_get_option('webp_serve_enabled', 0));
    tk_update_option('image_opt_rewrite_all_assets', (int) tk_get_option('webp_serve_enabled', 0));
    tk_update_option('image_opt_quality', max(30, min(100, (int) tk_post('image_opt_quality', 95))));
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
    $limit = 5;
    $scan = tk_image_opt_attachment_ids($offset, $limit);

    $quality = (int) tk_get_option('image_opt_quality', 92);
    $saved_bytes = 0;
    $count = 0;
    $started = microtime(true);
    if (!empty($scan['ids'])) {
        foreach ($scan['ids'] as $attachment_id) {
            $result = tk_image_opt_optimize_attachment($attachment_id, $quality);
            $saved_bytes += isset($result['saved']) ? (int) $result['saved'] : 0;
            $count++;
            if (microtime(true) - $started >= 10) {
                break;
            }
        }
    }

    $next = $offset + $count;
    $total = (int) $scan['total'];
    $done = empty($scan['ids']) || $next >= $total;
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
