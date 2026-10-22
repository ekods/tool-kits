<?php
require __DIR__ . '/performance-core-fixture.php';
$plugin = $argv[1] ?? dirname(__DIR__);
$scenario = $argv[2] ?? 'config';
require $plugin . '/includes/helpers.php';
require $plugin . '/includes/asset-optimization.php';
require $plugin . '/includes/security-hardening.php';
$base = array('license_status' => 'valid', 'license_key' => 'fixture', 'license_site_url' => home_url(), 'toolkits_install_id' => 'fixture', 'license_last_checked' => time());
tk_perf_seed($base);
$base['license_signature'] = tk_license_expected_signature();
if ($scenario === 'config') {
    // Deliberately large, synthetic report; never represents a production database.
    $base['seo_content_audit_report'] = array_fill(0, 500, array('url' => 'https://example.test/page', 'text' => str_repeat('sample ', 300)));
}
tk_perf_seed($base);
$urls = array();
if ($scenario === 'assets') {
    wp_mkdir_p(get_stylesheet_directory());
    for ($i = 0; $i < 64; $i++) {
        file_put_contents(get_stylesheet_directory() . '/asset-' . $i . '.js', str_repeat('/* asset */', 6000));
        $urls[] = 'https://example.test/theme/asset-' . $i . '.js';
    }
}
$transient_writes = 0;
add_action('set_transient', function() { $GLOBALS['transient_writes']++; });
$reads = 0;
$wpdb->queries = 0;
$memory_start = memory_get_usage();
if (function_exists('memory_reset_peak_usage')) { memory_reset_peak_usage(); }
$started = hrtime(true);
if ($scenario === 'config') {
    for ($i = 0; $i < 1000; $i++) { tk_get_option('license_key'); }
} elseif ($scenario === 'assets') {
    foreach ($urls as $url) { tk_assets_theme_asset_version($url); }
} else { throw new RuntimeException('Unknown scenario'); }
$elapsed = (hrtime(true) - $started) / 1000000;
echo json_encode(array(
    'scenario' => $scenario, 'php' => PHP_VERSION,
    'php_ms' => $elapsed,
    'peak_delta_bytes' => memory_get_peak_usage() - $memory_start,
    'retained_delta_bytes' => memory_get_usage() - $memory_start,
    'option_reads' => $reads,
    'adapter_queries' => $wpdb->queries,
    'real_sql_ms' => null,
    'http_calls' => $http,
    'transient_writes' => $transient_writes,
    'config_bytes' => strlen(serialize($base)),
)), PHP_EOL;
