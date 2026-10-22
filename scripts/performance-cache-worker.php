<?php
// Worker used by the regression suite to exercise kernel locks in another process.
if (PHP_SAPI !== 'cli') { exit; }
define('WP_CONTENT_DIR', $argv[1]);
require __DIR__ . '/performance-core-fixture.php';
require __DIR__ . '/license-test-fixture.php';
require dirname(__DIR__) . '/includes/helpers.php';
require dirname(__DIR__) . '/includes/cache.php';
$base = array('page_cache_enabled' => 1, 'license_status' => 'valid', 'license_key' => 'fixture', 'license_site_url' => home_url(), 'toolkits_install_id' => 'fixture');
tk_perf_seed($base);
$base['license_receipt'] = tk_test_license_receipt($base);
tk_perf_seed($base);
$_SERVER = array('HTTP_HOST' => 'example.test', 'REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET');
$_GET = $_COOKIE = array();
tk_page_cache_maybe_serve();
echo 'MISS';
