<?php
// Render the production template with isolated fixture data; no WordPress DB is loaded.
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
preg_match('/Version:\s*([\d.]+)/', file_get_contents(dirname(__DIR__) . '/tool-kits.php'), $version);
define('TK_VERSION', $version[1]);
define('TK_URL', 'file://' . dirname(__DIR__) . '/');
function get_option($key, $default = null) {
    if ($key !== 'tk_options') return $default;
    return array('image_opt_enabled' => 1, 'image_opt_frontend_optimize' => 1, 'image_opt_quality' => 95, 'heartbeat_last_success' => 1);
}
function add_filter(...$args) {}
function add_action(...$args) {}
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function checked($a, $b = true, $echo = true) { $out = (string) $a === (string) $b ? 'checked' : ''; if ($echo) echo $out; return $out; }
function disabled($a, $b) { if ($a === $b) echo 'disabled'; }
function number_format_i18n($number) { return number_format($number); }
function wp_date($format, $timestamp) { return date($format, $timestamp); }
function admin_url($path) { return 'https://toolkits-ui.test/wp-admin/' . $path; }
function wp_nonce_url($url, $action) { return $url . '&_wpnonce=preview'; }
function wp_create_nonce($action) { return 'preview'; }
function wp_nonce_field($action, $name, $referer = true, $echo = true) { $out = '<input type="hidden" name="' . esc_attr($name) . '" value="preview">'; if ($echo) echo $out; return $out; }
function __($value) { return $value; }
function _e($value) { echo $value; }
function get_transient($key) { return strpos($key, 'tk_largest_files_cache_') === 0 ? array() : false; }
function esc_html__($value) { return esc_html($value); }
function esc_attr_e($value) { echo esc_attr($value); }
function date_i18n($format, $timestamp) { return date($format, $timestamp); }
function size_format($value) { return round($value / 1048576, 1) . ' MB'; }
function tk_render_security_table() {
    echo '<div class="tk-module-grid">';
    foreach (array('Login Protection', 'Firewall', 'Security Monitoring', 'Content Protection') as $title) {
        echo '<div class="tk-module-card"><div class="tk-module-header"><h2 class="tk-module-title">' . esc_html($title) . '</h2></div><div class="tk-module-body"><span class="tk-badge tk-on">Active</span></div><div class="tk-module-footer"><a class="button" href="#">Configure</a></div></div>';
    }
    echo '</div>';
}
require dirname(__DIR__) . '/includes/helpers.php';
require dirname(__DIR__) . '/includes/image-optimizer.php';
$empty = in_array('--empty', $argv, true);
$overview = in_array('--overview', $argv, true);
$monitoring = in_array('--monitoring', $argv, true);
$checks = $server_rules = $noncore_root = $log_values = array();
$server = 'nginx';
$server_snippet = '';
$server_status = array('status' => 'unknown', 'detail' => 'Not checked');
$monitor_email = 'admin@example.test';
$health_url = 'https://example.test/?tk-health=1';
$health_key = '';
$healthcheck = array('time' => time(), 'server' => array('load' => array(0.8, 0.6, 0.5)), 'cron' => array('disabled' => false));
$connection_summary = array('collector_status' => 'configured');
$core_auto = true;
$wp_config_path = '';
$score = 90;
$score_color = '#16835d';
$score_data = array();
$seo_score_data = array('score' => 100, 'color' => '#16835d', 'link' => '#', 'checks' => array());
$geo_score_data = array('score' => 85, 'color' => '#16835d', 'link' => '#', 'checks' => array());
$report = $empty ? array() : array(
    'estimated_saved_bytes' => 148543488, 'optimized_files' => 1248, 'scanned' => 1320,
    'library_total' => 1600, 'missing_derivatives' => 72, 'original_optimized_source_bytes' => 415543488,
    'optimized_bytes' => 267000000, 'scanned_at' => time(),
    'large_unoptimized' => array(
        array('title' => 'Tool Kits application icon', 'size' => 804830, 'url' => TK_URL . 'assets/icon-1024x1024.png'),
        array('title' => 'Large image with a longer descriptive filename', 'size' => 1483030, 'url' => TK_URL . 'assets/icon-512x512.png'),
    ),
);
$queue = $empty ? array() : array('status' => 'complete', 'total' => 1320, 'processed' => 1320, 'saved' => 148543488, 'updated_at' => time());
$cleanup_report = $empty ? array() : array('scanned' => 164, 'removed' => 92, 'bytes_removed' => 30820483, 'scanned_at' => time(), 'items' => array(
    array('file' => '2026/09/previous-image-768x549-tkopt.webp', 'size' => 68043, 'reason' => 'Unused intermediate derivative'),
));
$wp_root = getenv('TK_PREVIEW_WP_ROOT');
if (!$wp_root || !is_dir($wp_root . '/wp-admin/css')) {
    fwrite(STDERR, "Set TK_PREVIEW_WP_ROOT to a local WordPress source directory.\n");
    exit(1);
}
$wp = rtrim($wp_root, '/') . '/wp-admin/';
ob_start();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tool Kits - Image Optimizer</title>
<link rel="stylesheet" href="file://<?php echo esc_attr(dirname($wp) . '/wp-includes/css/dashicons.css'); ?>">
<link rel="stylesheet" href="file://<?php echo esc_attr($wp . 'css/common.css'); ?>">
<link rel="stylesheet" href="file://<?php echo esc_attr($wp . 'css/forms.css'); ?>">
<?php foreach (array('admin.css', 'overview.css', 'tool-kits-admin-ui.css') as $css) : ?><link rel="stylesheet" href="<?php echo TK_URL . 'assets/' . $css; ?>"><?php endforeach; ?>
<style>body{margin:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px}.preview-bar{height:32px;background:#20242b;color:white;padding:0 20px;display:flex;align-items:center;font-size:12px}.preview-nav{width:160px;position:absolute;top:32px;bottom:0;background:#252a32;color:#cdd2dc;padding:22px 0}.preview-nav span{display:block;padding:10px 16px}.preview-nav .selected{background:#2457e6;color:white}main{margin-left:160px;padding:28px 32px}.tk-wrap{margin:0 auto}a{text-decoration:none}.screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}@media(max-width:782px){.preview-nav{display:none}main{margin:0;padding:18px 12px}.preview-bar{height:40px}}</style>
</head><body><div class="preview-bar">WordPress / Tool Kits</div><aside class="preview-nav" aria-label="WordPress admin"><span>Dashboard</span><span>Media</span><span>Pages</span><span class="selected">Tool Kits</span><span>Overview</span><span>Image Optimizer</span><span>GEO</span><span>Monitoring</span></aside><main><div class="wrap tk-wrap">
<?php if ($monitoring) { require dirname(__DIR__) . '/templates/monitoring.php'; } elseif ($overview) { require dirname(__DIR__) . '/templates/overview.php'; } else { tk_render_header_branding(); tk_render_page_hero('Image Optimizer', 'Image quality, optimized delivery, and media library maintenance.', 'dashicons-format-image'); require dirname(__DIR__) . '/templates/image-optimizer.php'; } ?>
</div></main><script>window.ajaxurl='https://toolkits-ui.test/wp-admin/admin-ajax.php';window.tkMonitoringData={nonce:'preview',ajaxurl:window.ajaxurl};</script><?php if ($monitoring) : ?><script src="<?php echo TK_URL; ?>assets/vendor/chart.js/chart.umd.min.js"></script><script src="<?php echo TK_URL; ?>assets/health-monitor-chart.js"></script><?php else : ?><script src="<?php echo TK_URL; ?>assets/image-optimizer.js"></script><?php endif; ?></body></html><?php
$output = $argv[1] ?? '/tmp/toolkits-image-preview.html';
file_put_contents($output, ob_get_clean());
echo $output, PHP_EOL;
