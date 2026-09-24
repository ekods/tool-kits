<?php
// Standalone integration tests: real image codecs and optional WordPress HTML parser.
// php scripts/test-image-optimizer.php /path/to/wordpress [/path/to/sRGB.icc]
declare(strict_types=1);

if (!extension_loaded('imagick')) {
    fwrite(STDERR, "Imagick is required for the codec regression tests.\n");
    exit(1);
}
define('ABSPATH', __DIR__ . '/');
$test_dir = sys_get_temp_dir() . '/tkopt-test-' . bin2hex(random_bytes(6));
mkdir($test_dir);
$test_dir = realpath($test_dir);
$test_options = array('image_opt_enabled' => 1, 'image_opt_frontend_optimize' => 1);
$test_files = array();
$test_metadata = array();
$passes = 0;

// Isolate filesystem and settings from any real site/database.
function tk_get_option($key, $default = null) { return $GLOBALS['test_options'][$key] ?? $default; }
function tk_update_option($key, $value) { $GLOBALS['test_options'][$key] = $value; }
function tk_license_features_enabled() { return true; }
function is_admin() { return false; }
function wp_get_upload_dir() { return array('basedir' => $GLOBALS['test_dir'], 'baseurl' => 'https://cdn.example.test/site/uploads'); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_json_encode($value) { return json_encode($value); }
function wp_normalize_path($path) { return str_replace('\\', '/', $path); }
function trailingslashit($value) { return rtrim($value, '/') . '/'; }
function get_attached_file($id) { return $GLOBALS['test_files'][$id] ?? ''; }
function wp_get_attachment_metadata($id) { return $GLOBALS['test_metadata'][$id] ?? array(); }
function update_post_meta($id, $key, $value) {
    if (!empty($GLOBALS['test_stop_worker'])) {
        $GLOBALS['test_options']['image_opt_queue']['status'] = 'stopped';
    }
}
function get_post_mime_type($id) { return 'image/jpeg'; }
function attachment_url_to_postid($url) {
    foreach ($GLOBALS['test_files'] as $id => $file) {
        if (tk_image_opt_path_to_upload_url($file) === $url) { return $id; }
    }
    return 0;
}
function add_query_arg($key, $value, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . urlencode($key) . '=' . urlencode($value); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_url($value) { return esc_attr($value); }
function wp_kses_uri_attributes() { return array('src', 'href'); }
function _doing_it_wrong($function, $message, $version) { throw new RuntimeException($function . ': ' . $message); }
function __($value) { return $value; }
function wp_cache_delete($key, $group) {}
function wp_next_scheduled($hook) { return false; }
function wp_schedule_single_event($time, $hook) { $GLOBALS['test_scheduled'][] = $hook; }
function get_the_title($id) { return 'Test ' . $id; }
function wp_get_attachment_url($id) { return tk_image_opt_path_to_upload_url(get_attached_file($id)); }
class WP_Query {
    public $posts;
    public $found_posts;
    public function __construct($args) {
        $ids = array_keys($GLOBALS['test_files']); sort($ids);
        $this->found_posts = count($ids);
        $this->posts = array_slice($ids, $args['offset'] ?? 0, $args['posts_per_page']);
    }
}

require dirname(__DIR__) . '/includes/image-optimizer.php';
require dirname(__DIR__) . '/includes/webp.php';

function check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
    $GLOBALS['passes']++;
    echo '[PASS] ', $message, PHP_EOL;
}
function pixels(string $path): string {
    $image = new Imagick($path);
    $signature = tk_image_opt_pixel_signature($image);
    $image->clear();
    return $signature;
}
function fixture(string $path, string $format, int $width = 3200, int $height = 1800): void {
    $image = new Imagick();
    $image->newPseudoImage($width, $height, 'gradient:#153728-#faf7fe');
    $draw = new ImagickDraw();
    $draw->setFillColor('#e72846');
    for ($x = 0; $x < $width; $x += 53) {
        $draw->rectangle($x, 30, $x + 2, $height - 30);
    }
    $image->drawImage($draw);
    $image->setImageDepth(8);
    $image->setImageFormat($format);
    $image->setImageCompressionQuality(100);
    $image->setImageProperty('comment', str_repeat('removable test metadata ', 2000));
    if (!empty($GLOBALS['argv'][2]) && $format === 'jpeg') {
        $image->profileImage('icc', file_get_contents($GLOBALS['argv'][2]));
    }
    $image->writeImage($path);
    $image->clear();
}

try {
    $source = $test_dir . '/photo.jpg';
    $target = $test_dir . '/photo-tkopt.jpg';
    fixture($source, 'jpeg');
    $hash = hash_file('sha256', $source);
    check(tk_image_opt_frontend_url(tk_image_opt_path_to_upload_url($source)) === tk_image_opt_path_to_upload_url($source)
        && !is_file($target), 'Missing derivative uses source without frontend generation');
    $test_options['image_opt_max_width'] = 768;
    $test_options['image_opt_max_height'] = 549;
    $result = tk_image_opt_create_optimized_derivative($source, $target, 30);
    check($result['optimized'], 'JPEG generates a smaller validated derivative');
    check(array_slice(getimagesize($target), 0, 2) === array(3200, 1800), 'Hi-Res ignores legacy resize limits');
    check(hash_file('sha256', $source) === $hash, 'Original upload remains byte-for-byte unchanged');
    $report = json_decode(file_get_contents($target . '.json'), true);
    check($report['quality'] === 95 && $report['dpi_applied'], 'Hi-Res clamps old low quality to 95 and applies DPI');
    $before = new Imagick($source);
    $after = new Imagick($target);
    $dpi = $after->getImageResolution();
    check(abs($dpi['x'] - 72) < 1 && abs($dpi['y'] - 72) < 1, 'DPI set to 72 without resampling');
    check($before->getImageProfiles('icc', true) === $after->getImageProfiles('icc', true), 'ICC profile survives metadata stripping');
    $comparison = $before->compareImages($after, Imagick::METRIC_ROOTMEANSQUAREDERROR);
    $psnr = $comparison[1] > 0 ? -20 * log10($comparison[1]) : INF;
    check($psnr > 35, 'JPEG fixture PSNR exceeds 35 dB (' . round($psnr, 2) . ' dB)');
    $comparison[0]->clear();
    echo 'JPEG fixture: ', filesize($source), ' -> ', filesize($target), " bytes\n";
    $before->clear(); $after->clear();

    $url = tk_image_opt_path_to_upload_url($source);
    check(strpos(tk_image_opt_frontend_url($url), 'photo-tkopt.jpg?tkopt=') !== false, 'Frontend selects validated derivative with cache revision');
    $test_options['image_opt_target_dpi'] = 96;
    check(tk_image_opt_frontend_url($url) === $url, 'Changed settings immediately fall back to source');
    $test_options['image_opt_target_dpi'] = 72;
    touch($source, time() + 2);
    check(tk_image_opt_frontend_url($url) === $url, 'Changed source invalidates old derivative');
    check(tk_image_opt_create_optimized_derivative($source, $target, 95)['optimized'], 'Regeneration succeeds from the original source');
    $regenerated = hash_file('sha256', $target);
    tk_image_opt_create_optimized_derivative($source, $target, 95);
    check(hash_file('sha256', $target) === $regenerated, 'Repeat generation does not accumulate JPEG loss');
    $test_files[99] = $target;
    check(!tk_image_opt_encode($source, $target, 95)['optimized'] && hash_file('sha256', $target) === $regenerated,
        'A derivative filename registered as another attachment is never overwritten');
    unset($test_files[99]);

    $png = $test_dir . '/graphic.png';
    $graphic = new Imagick();
    $graphic->newImage(640, 480, new ImagickPixel('transparent'), 'png');
    $graphic->setImageDepth(8);
    $draw = new ImagickDraw(); $draw->setFillColor('rgba(180,20,80,0.5)'); $draw->rectangle(30, 30, 400, 250);
    $graphic->drawImage($draw);
    $graphic->setImageProperty('comment', str_repeat('metadata ', 5000));
    $graphic->writeImage($png); $graphic->clear();
    $png_copy = $test_dir . '/graphic-tkopt.png';
    check(tk_image_opt_create_optimized_derivative($png, $png_copy, 95)['optimized'], 'PNG optimized without lossy quantization');
    check(pixels($png) === pixels($png_copy), 'PNG decoded pixels and alpha are identical');
    $test_options['webp_serve_enabled'] = 1;
    $webp_test = tk_image_opt_encode($source, $source . '-tkopt.webp', 95);
    if ($webp_test['optimized']) {
        check(tk_image_opt_valid_derivative($source, $source . '-tkopt.webp', 95)
            && pixels($source) === pixels($source . '-tkopt.webp'), 'JPEG-to-WebP is accepted only with matching source pixels');
    } else {
        check(!is_file($source . '-tkopt.webp') && !empty($webp_test['reason']), 'JPEG-to-WebP conversion that fails validation leaves no published copy');
        check(strpos(tk_image_opt_frontend_url($url), 'photo-tkopt.jpg') !== false, 'Rejected WebP conversion falls back to validated JPEG');
    }
    tk_image_opt_generate_copies($png, 95);
    check(is_file($png . '-tkopt.webp') && pixels($png) === pixels($png . '-tkopt.webp'), 'PNG-to-WebP preserves decoded pixels and transparency');
    check(tk_image_opt_source_candidates_for_derivative($png . '-tkopt.webp')[0] === $png, 'Source-extension WebP naming resolves to the correct source');
    $test_options['webp_serve_enabled'] = 0;

    $animated = new Imagick();
    foreach (array('red', 'blue') as $color) {
        $frame = new Imagick(); $frame->newImage(32, 32, $color, 'webp');
        $frame->setImageDelay(20); $animated->addImage($frame); $frame->clear();
    }
    $animation = $test_dir . '/animation.webp';
    $animated->writeImages($animation, true); $animated->clear();
    $animation_hash = hash_file('sha256', $animation);
    check(!tk_image_opt_create_optimized_derivative($animation, $test_dir . '/animation-tkopt.webp', 95)['optimized']
        && hash_file('sha256', $animation) === $animation_hash, 'Animated WebP is left intact instead of flattening frames');

    $tiny = $test_dir . '/tiny.png';
    $tiny_image = new Imagick(); $tiny_image->newImage(1, 1, 'transparent', 'png');
    $tiny_image->stripImage(); $tiny_image->writeImage($tiny); $tiny_image->clear();
    $tiny_hash = hash_file('sha256', $tiny);
    $tiny_result = tk_image_opt_create_optimized_derivative($tiny, $test_dir . '/tiny-tkopt.png', 95);
    check(!$tiny_result['optimized'] || filesize($tiny_result['path']) < filesize($tiny), 'No output larger than its source is accepted');
    check(hash_file('sha256', $tiny) === $tiny_hash, 'Already small image retains its source');

    $test_options['image_opt_preserve_hires'] = 0;
    check(tk_image_opt_create_optimized_derivative($source, $target, 95)['optimized']
        && getimagesize($target)[0] === 768, 'Explicitly disabling Hi-Res permits configured resize');
    $test_options['image_opt_preserve_hires'] = 1;
    check(tk_image_opt_frontend_url($url) === $url, 'Hi-Res refuses a previously resized derivative');
    tk_image_opt_create_optimized_derivative($source, $target, 95);

    $bad = $test_dir . '/broken.jpg'; file_put_contents($bad, 'not an image');
    check(!tk_image_opt_create_optimized_derivative($bad, $test_dir . '/broken-tkopt.jpg', 95)['optimized'], 'Invalid images are rejected');
    check(!tk_image_opt_create_optimized_derivative($source, $source, 95)['optimized'], 'Source replacement is rejected');
    check(!tk_image_opt_create_optimized_derivative($target, $test_dir . '/photo-tkopt-tkopt.jpg', 95)['optimized'], 'Already optimized sources cannot be recompressed');
    check(tk_image_opt_url_to_local_path('https://foreign.test/site/uploads/photo.jpg') === '', 'External origins are rejected');
    check(tk_image_opt_url_to_local_path('https://cdn.example.test/site/uploads/%2e%2e/private.jpg') === '', 'Encoded path traversal is rejected');
    check(tk_image_opt_url_to_local_path('https://cdn.example.test/site/uploads/photo.jpg') === $source, 'Custom uploads URL maps to local uploads correctly');
    $outside = tempnam(sys_get_temp_dir(), 'tkopt-outside-');
    symlink($outside, $test_dir . '/linked.jpg');
    check(tk_image_opt_url_to_local_path('https://cdn.example.test/site/uploads/linked.jpg') === '', 'Symlinks outside uploads are rejected');
    unlink($test_dir . '/linked.jpg'); unlink($outside);

    $scaled = $test_dir . '/photo-scaled.jpg'; copy($source, $scaled);
    $test_files[1] = $scaled;
    $test_metadata[1] = array('original_image' => 'photo.jpg', 'sizes' => array('medium' => array('file' => 'photo-768x432.jpg')));
    check(tk_image_opt_source_file(1) === $source, 'WordPress scaled attachments use original high-resolution upload');
    $native_src = tk_image_opt_filter_image_src(array($url, 768, 432, true), 1, 'medium', false);
    check($native_src[1] === 3200 && $native_src[2] === 1800, 'Native image source reports actual full-resolution dimensions');
    $native_srcset = tk_image_opt_original_only_srcset(array(768 => array('url' => $url, 'value' => 768, 'descriptor' => 'w')), array(768, 432), $url,
        array('file' => 'photo-scaled.jpg', 'width' => 2560, 'original_image' => 'photo.jpg'), 1);
    check(array_keys($native_srcset) === array(3200) && $native_srcset[3200]['value'] === 3200, 'Native srcset uses original pixel width instead of scaled metadata width');
    $test_files[2] = $test_dir . '/photo-e123.jpg'; copy($source, $test_files[2]);
    $test_metadata[2] = array('original_image' => 'photo.jpg');
    check(tk_image_opt_source_file(2) === $test_files[2], 'Deliberate WordPress image edits remain authoritative');
    $thumb = $test_dir . '/photo-768x432.jpg'; copy($source, $thumb);
    $unused = $test_dir . '/photo-768x432-tkopt.jpg'; copy($target, $unused);
    file_put_contents($unused . '.json', '{}');
    $new_unused = $thumb . '-tkopt.webp'; copy($target, $new_unused);
    check(tk_image_opt_cleanup_intermediate_derivatives($source, $test_metadata[1]) === 2, 'Regeneration removes obsolete thumbnail copies in both naming formats');
    check(is_file($thumb) && is_file($source) && !is_file($unused . '.json'), 'Cleanup retains original uploads and removes sidecar reports');
    $legacy = 'https://cdn.example.test/site/uploads/photo-768x432-tkopt.webp';
    copy($png . '-tkopt.webp', $test_dir . '/photo.webp');
    check(strpos(tk_image_opt_frontend_url($legacy), 'photo-tkopt.jpg') !== false, 'Old thumbnail WebP URL resolves to full-resolution optimized image');
    check(tk_image_opt_original_url_for_intermediate_url($legacy) === $url, 'Legacy lossy WebP sibling cannot displace registered original source');
    check(strpos(tk_image_opt_frontend_url('https://cdn.example.test/site/uploads/photo-tkopt.webp'), 'photo-tkopt.jpg') !== false,
        'Legacy full-size WebP copy resolves to JPEG source instead of another compressed WebP');

    if (!empty($argv[1])) {
        $wp = rtrim($argv[1], '/') . '/wp-includes/';
        require_once $wp . 'class-wp-token-map.php';
        foreach (array('html5-named-character-references.php', 'class-wp-html-attribute-token.php', 'class-wp-html-span.php', 'class-wp-html-text-replacement.php', 'class-wp-html-decoder.php', 'class-wp-html-tag-processor.php') as $file) {
            require_once $wp . 'html-api/' . $file;
        }
        $script = '<script type="application/ld+json">{"image":"' . $url . '"}</script>';
        $html = $script . '<img class="lozad" src="' . $url . '" data-src="' . $legacy . '" srcset="' . $legacy . ' 768w, ' . $url . ' 3200w"><a href="' . $url . '">Original</a>';
        $rewritten = tk_image_opt_rewrite_html_buffer($html);
        check(strpos($rewritten, $script) === 0 && strpos($rewritten, '<a href="' . $url . '">') !== false, 'HTML rewrite preserves JSON-LD, scripts and download links');
        $parser = new WP_HTML_Tag_Processor($rewritten); $parser->next_tag('IMG');
        check(strpos($parser->get_attribute('data-src'), 'photo-tkopt.jpg') !== false, 'Lazy-load attributes use full-resolution validated copy');
        check(substr($parser->get_attribute('srcset'), -6) === ' 3200w' && strpos($parser->get_attribute('srcset'), ',') === false, 'HTML srcset contains one accurate full-resolution candidate');
        $files_before = glob($test_dir . '/*');
        for ($i = 0; $i < 10; $i++) { tk_image_opt_rewrite_html_buffer($html); }
        check($files_before === glob($test_dir . '/*') && hash_file('sha256', $target) === $regenerated, 'Frontend requests do not create or recompress files');
    } else {
        echo "[SKIP] HTML parser integration: pass a WordPress root path.\n";
    }
    check(hash_file('sha256', $source) === $hash, 'Original source still unchanged after all workflows');
    for ($id = 1; $id <= 7; $id++) { $test_files[$id] = $source; }
    $test_options['image_opt_queue'] = array('status' => 'running', 'run_id' => 'test', 'offset' => 0);
    $held_lock = fopen($test_dir . '/.tkopt-queue.lock', 'c'); flock($held_lock, LOCK_EX);
    tk_image_opt_queue_process();
    check($test_options['image_opt_queue']['offset'] === 0, 'Concurrent queue worker cannot acquire held lock');
    flock($held_lock, LOCK_UN); fclose($held_lock);
    tk_image_opt_queue_process();
    check($test_options['image_opt_queue']['offset'] === 5 && $test_options['image_opt_queue']['status'] === 'running', 'Queue advances five attachments and schedules continuation');
    tk_image_opt_queue_process();
    check($test_options['image_opt_queue']['status'] === 'complete' && $test_options['image_opt_queue']['processed'] === 7, 'Queue finishes remaining attachments without skipping');
    $test_options['image_opt_queue'] = array('status' => 'running', 'run_id' => 'stop-test', 'offset' => 0);
    $GLOBALS['test_stop_worker'] = true;
    tk_image_opt_queue_process();
    check($test_options['image_opt_queue']['status'] === 'stopped' && $test_options['image_opt_queue']['offset'] === 0, 'Worker respects stop submitted during encoding');
    $GLOBALS['test_stop_worker'] = false;
    unlink($test_dir . '/.tkopt-queue.lock');
    $test_options['webp_convert_enabled'] = 1;
    $metadata = array('file' => 'photo.jpg', 'width' => 3200, 'height' => 1800, 'sizes' => array());
    check(tk_webp_generate_on_metadata_update($metadata, 1) === $metadata, 'Auto WebP metadata filter preserves array and receives attachment ID correctly');
    check(glob($test_dir . '/.tkopt-*') === array(), 'Encoding leaves no temporary files');
    echo $passes, " checks passed.\n";
} finally {
    foreach (glob($test_dir . '/*') as $file) { unlink($file); }
    foreach (glob($test_dir . '/.tkopt-*') as $file) { unlink($file); }
    rmdir($test_dir);
}
