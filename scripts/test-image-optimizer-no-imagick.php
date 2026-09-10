<?php
declare(strict_types=1);
if (class_exists('Imagick') || !extension_loaded('gd')) {
    fwrite(STDERR, "Run with a PHP binary that has GD and no Imagick.\n");
    exit(1);
}
define('ABSPATH', __DIR__ . '/');
$test_dir = sys_get_temp_dir() . '/tkopt-gd-test-' . bin2hex(random_bytes(6));
mkdir($test_dir);
function tk_get_option($key, $default = null) { return $default; }
function wp_get_upload_dir() { return array('basedir' => $GLOBALS['test_dir']); }
require dirname(__DIR__) . '/includes/image-optimizer-engine.php';
$source = $test_dir . '/source.png';
try {
    $image = imagecreatetruecolor(64, 64);
    imagepng($image, $source); imagedestroy($image);
    $hash = hash_file('sha256', $source);
    $result = tk_image_opt_encode($source, $test_dir . '/source-tkopt.png', 95);
    if ($result['optimized'] || strpos($result['reason'], 'requires Imagick') === false
        || hash_file('sha256', $source) !== $hash || count(glob($test_dir . '/*')) !== 1) {
        throw new RuntimeException('No-Imagick fallback failed.');
    }
    echo "[PASS] Missing Imagick preserves original and reports unavailable Hi-Res verification.\n";
} finally {
    foreach (glob($test_dir . '/*') as $file) { unlink($file); }
    rmdir($test_dir);
}
