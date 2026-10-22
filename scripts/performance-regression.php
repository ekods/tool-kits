<?php
error_reporting(E_ALL);
set_error_handler(function($level, $message) { if (error_reporting() & $level) { throw new RuntimeException($message); } return false; });
require __DIR__ . '/performance-core-fixture.php';
require dirname(__DIR__) . '/includes/helpers.php';
require dirname(__DIR__) . '/includes/cache.php';
require dirname(__DIR__) . '/includes/asset-optimization.php';
require dirname(__DIR__) . '/includes/lazy-load.php';
require dirname(__DIR__) . '/includes/security-hardening.php';
require dirname(__DIR__) . '/includes/security-alerts.php';
require dirname(__DIR__) . '/includes/security-login-log.php';
$checks = 0;
function check_perf($ok, $message) {
    if (!$ok) { throw new RuntimeException($message); }
    $GLOBALS['checks']++;
}
$base = array('license_status' => 'valid', 'license_key' => 'fixture', 'license_site_url' => home_url(), 'toolkits_install_id' => 'fixture', 'license_last_checked' => time(), 'page_cache_enabled' => 1);
tk_perf_seed($base);
$base['license_signature'] = tk_license_expected_signature();
update_option('tk_options', $base, false);
check_perf(tk_license_validate()['status'] === 'valid', 'Signed license must validate');
$reads = 0;
tk_options_cache_invalidate();
for ($i = 0; $i < 100; $i++) { tk_get_option('license_key'); }
check_perf($reads === 1, 'Repeated options should deserialize once');
$copy = tk_get_options(); $copy['license_key'] = 'local edit';
check_perf(tk_get_option('license_key') === 'fixture', 'Caller must not mutate cached array');
$seen = '';
add_action('update_option_tk_options', function() { $GLOBALS['seen'] = tk_get_option('test'); }, 10, 0);
$changed = $base; $changed['test'] = 'new';
update_option('tk_options', $changed, false);
check_perf($seen === 'new' && tk_get_option('test') === 'new', 'Dynamic callbacks see fresh config');
$wpdb->fail = true;
$failed = $changed; $failed['test'] = 'failed';
check_perf(!update_option('tk_options', $failed, false) && tk_get_option('test') === 'new', 'Failed writes preserve actual config');
$wpdb->fail = false;
add_filter('pre_update_option_tk_options', function($value) { $value['test'] = 'filtered'; return $value; });
update_option('tk_options', $failed, false);
check_perf(tk_get_option('test') === 'filtered', 'Sanitized config is read from WordPress');
remove_all_filters('pre_update_option_tk_options');
delete_option('tk_options');
check_perf(tk_get_options() === array(), 'Deleted options invalidate');
add_option('tk_options', $base, '', false);
check_perf(tk_get_option('license_key') === 'fixture', 'Added options invalidate a cached miss');
switch_to_blog(2);
tk_perf_seed(array('test' => 'second', 'page_cache_enabled' => 1), 2);
check_perf(tk_get_option('test') === 'second', 'Multisite config is isolated');
restore_current_blog();
tk_perf_seed($base);
check_perf(tk_get_option('license_key') === 'fixture', 'Restored blog reads its own config');
$tampered = $base; $tampered['license_signature'] = 'bad';
update_option('tk_options', $tampered, false);
check_perf(!tk_license_features_enabled(), 'Tampering immediately disables features');
update_option('tk_options', $base, false);
$mismatch = $base; $mismatch['license_site_url'] = 'https://another.test';
update_option('tk_options', $mismatch, false);
$mismatch['license_signature'] = tk_license_expected_signature();
update_option('tk_options', $mismatch, false);
check_perf(!tk_license_features_enabled(), 'Valid signature cannot bypass site match');
update_option('tk_options', $base, false);

// Derived metadata/config reports may be excluded; source content remains dirty.
add_filter('tk_page_cache_ignored_post_meta_keys', function($keys) { $keys[] = '_fixture_stats'; return $keys; });
tk_page_cache_purge_on_meta_change(1, 1, '_fixture_stats', 1);
check_perf(empty($tk_page_cache_pending_blogs), 'Derived metadata filter is honored');
tk_page_cache_purge_on_updated_option('tk_options', $base, $base + array('monitoring_404_log' => array('one')));
check_perf(empty($tk_page_cache_pending_blogs), 'Telemetry does not purge pages');
tk_page_cache_purge_on_meta_change(1, 1, '_tk_geo_faq_items', array('new'));
tk_page_cache_purge_once();
wp_mkdir_p(tk_page_cache_dir());
file_put_contents(tk_page_cache_dir() . '/fixture.html', 'one');
$first_dir = tk_page_cache_dir();
switch_to_blog(2);
wp_mkdir_p(tk_page_cache_dir());
file_put_contents(tk_page_cache_dir() . '/fixture.html', 'two');
tk_page_cache_purge_once();
$second_dir = tk_page_cache_dir();
restore_current_blog();
check_perf(count($tk_page_cache_pending_blogs) === 2 && $first_dir !== $second_dir, 'Purge coalesces separately per blog');
tk_page_cache_flush_pending();
check_perf(!file_exists($first_dir . '/fixture.html') && !file_exists($second_dir . '/fixture.html') && $blog === 1, 'Purge clears both blogs and restores context');

$_SERVER = array('HTTP_HOST' => 'example.test', 'REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET');
$_GET = $_COOKIE = array();
http_response_code(200);
check_perf(tk_page_cache_acquire_writer(), 'First writer obtains lock');
$path = tk_page_cache_path();
$lock = fopen(tk_page_cache_dir() . '/.writer-' . substr(tk_page_cache_key(), 0, 2), 'c');
check_perf(!flock($lock, LOCK_EX | LOCK_NB), 'Concurrent writer is excluded');
fclose($lock);
tk_page_cache_purge();
tk_page_cache_callback('<html>stale</html>');
check_perf(!file_exists($path), 'Purge during rendering prevents stale publication');
check_perf(tk_page_cache_acquire_writer(), 'Lock releases after callback');
tk_page_cache_callback('<html>fresh</html>');
check_perf(file_get_contents($path) === '<html>fresh</html>', 'Fresh response publishes atomically');
tk_page_cache_acquire_writer();
tk_page_cache_callback('partial', PHP_OUTPUT_HANDLER_FLUSH);
tk_page_cache_callback('tail', PHP_OUTPUT_HANDLER_FINAL);
check_perf(file_get_contents($path) === '<html>fresh</html>', 'Flushed partial output is never cached');
function cache_worker_output() {
    $process = proc_open(array(PHP_BINARY, __DIR__ . '/performance-cache-worker.php', WP_CONTENT_DIR), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $out = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || $error !== '') { throw new RuntimeException('Worker failed: ' . $error); }
    return $out;
}
check_perf(cache_worker_output() === '<html>fresh</html>', 'A separate worker serves a fresh cache hit');
tk_page_cache_acquire_writer();
touch($path, time() - 3610);
check_perf(cache_worker_output() === '<html>fresh</html>', 'Contending worker serves expired cache during the 30-second stale window');
touch($path, time() - 3640);
check_perf(cache_worker_output() === 'MISS', 'Stale cache is never served beyond the grace window');
tk_page_cache_release_writer();

$src = 'https://example.test/theme/app.js?ver=custom';
check_perf(tk_assets_theme_asset_version($src) === $src && tk_remove_wp_version_strings($src) === $src, 'Explicit theme version survives all filters');
wp_mkdir_p(get_stylesheet_directory());
file_put_contents(get_stylesheet_directory() . '/app.js', 'asset');
check_perf(strpos(tk_assets_theme_asset_version('https://example.test/theme/app.js'), '?ver=') !== false, 'Unversioned theme gets stat version');
tk_update_option('assets_theme_version_enabled', 0);
$before_queries = $wpdb->queries;
check_perf(tk_assets_theme_asset_version('https://example.test/theme/app.js') === 'https://example.test/theme/app.js' && $before_queries === $wpdb->queries, 'Disabled fallback performs no transient queries');
$wp_scripts = (object) array('registered' => array('explicit' => (object) array('ver' => '6.9')));
check_perf(tk_remove_wp_ver_css_js('https://example.test/app.js?ver=6.9', 'explicit') === 'https://example.test/app.js?ver=6.9', 'Explicit version equal to core version survives');
$input = '<img src="image.jpg" loading = lazy fetchpriority="low" decoding="sync" width=640 height=480 srcset="a.jpg 640w" sizes="100vw">';
tk_update_option('lazy_load_enabled', 1);
check_perf(tk_lazy_html_buffer($input) === $input, 'HTML lazy loading preserves explicit loading/priority');
$output = tk_assets_perf_buffer('<html><head></head><body>' . $input . '</body></html>');
foreach (array('loading = lazy', 'fetchpriority="low"', 'decoding="sync"', 'srcset="a.jpg 640w"', 'sizes="100vw"') as $attribute) {
    check_perf(strpos($output, $attribute) !== false, 'Image attribute preserved: ' . $attribute);
}
$attrs = array('loading' => 'lazy', 'fetchpriority' => 'low', 'decoding' => 'sync', 'srcset' => 'a.jpg 640w', 'sizes' => '100vw');
check_perf(tk_lazy_image_attributes($attrs, null, null) === $attrs, 'Attachment attributes preserved');
$plain = tk_assets_apply_image_hints('<img src="x.jpg" width=10 height=20>', true, true);
check_perf(strpos($plain, 'loading="eager"') !== false && strpos($plain, 'aspect-ratio: 10 / 20') !== false, 'Unspecified image still gets optimizations');
check_perf(tk_login_log_location_for_ip('8.8.8.8') === 'Unknown' && $http === 0, 'Login logging never waits for geolocation HTTP');
$wpdb->cleanup_rows = 1000;
tk_security_events_cleanup();
check_perf(isset($scheduled['tk_security_events_cleanup_batch']), 'Bounded retention schedules continuation');
unset($scheduled['tk_security_events_cleanup_batch']);
$wpdb->cleanup_rows = 10;
tk_security_events_cleanup();
check_perf(!isset($scheduled['tk_security_events_cleanup_batch']), 'Cleanup stops scheduling when the backlog is drained');
function rocket_clean_domain() { $GLOBALS['external_purges'] = ($GLOBALS['external_purges'] ?? 0) + 1; }
$purge_result = tk_server_cache_purge_layers();
check_perf(tk_server_cache_purge_layers() === $purge_result && $external_purges === 1, 'External purges coalesce within each blog request');
switch_to_blog(2);
tk_server_cache_purge_layers();
restore_current_blog();
check_perf($external_purges === 2, 'Another blog receives its own external purge');
tk_cache_init();
foreach (array('comment_post', 'edit_comment', 'deleted_comment', 'set_object_terms', 'updated_term_meta') as $hook) {
    check_perf(has_action($hook) !== false, 'Source content invalidation hook exists: ' . $hook);
}
echo "PASS: {$checks} real WordPress Option/Hook API checks, simulated multisite, licenses, purge/locking, assets, image attributes and bounded cleanup\n";
