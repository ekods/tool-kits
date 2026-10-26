<?php
// Isolated files only; never load wp-config.php or connect to a live database.
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL);
set_error_handler(function($level, $message) { if (error_reporting() & $level) { throw new RuntimeException($message); } return false; });
$root = sys_get_temp_dir() . '/tk-cache-upgrade-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
define('ABSPATH', $root . '/');
define('WP_CONTENT_DIR', $root . '/wp-content');
define('TK_VERSION', 'fixture');
$options = array('page_cache_enabled' => 1, 'page_cache_ttl' => 3600, 'page_cache_exclude_paths' => "/excluded\n/special");
$wp_options = array(); $licensed = true; $multisite = false; $writes = 0; $actions = array(); $checks = 0;
function check_cache($condition, $message) { if (!$condition) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
function tk_get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function get_option($key, $default = null) { return $GLOBALS['wp_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['wp_options'][$key] = $value; }
function get_transient($key) { return $key === 'tk_server_cache_status' ? array('detected' => false, 'detail' => 'Isolated fixture') : false; }
function tk_license_features_enabled() { return $GLOBALS['licensed']; }
function tk_license_verified_claims() { return $GLOBALS['licensed'] ? array('expires_at' => $GLOBALS['license_deadline'] ?? time() + 86400) : false; }
function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0755, true); }
function is_multisite() { return $GLOBALS['multisite']; }
function get_current_blog_id() { return 1; }
function trailingslashit($path) { return rtrim($path, '/') . '/'; }
function apply_filters($name, $value) { return $value; }
function add_action($name, $callback, $priority = 10) { $GLOBALS['actions'][] = array($name, $callback, $priority); }
function is_ssl() { return isset($_SERVER['HTTPS']) ? in_array(strtolower((string) $_SERVER['HTTPS']), array('on', '1'), true) : (($_SERVER['SERVER_PORT'] ?? '') == '443'); }
function insert_with_markers($path, $marker, $lines) { $GLOBALS['writes']++; file_put_contents($path, '# BEGIN ' . $marker . "\n" . implode("\n", $lines) . "\n# END " . $marker); return true; }
function tk_is_admin_user() { return true; }
function tk_notice($message, $type) { $GLOBALS['notices'][] = array($message, $type); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
require dirname(__DIR__) . '/includes/cache.php';
require dirname(__DIR__) . '/includes/security-hardening.php';
function cleanup_cache_fixture($directory) { foreach (new FilesystemIterator($directory) as $entry) { if ($entry->isDir()) { cleanup_cache_fixture($entry->getPathname()); } else { unlink($entry->getPathname()); } } rmdir($directory); }
try {
    // Upgrade hashes must regenerate existing browser-cache rules once.
    foreach (array('Apache', 'LiteSpeed', 'OpenLiteSpeed') as $server) {
        $_SERVER['SERVER_SOFTWARE'] = $server;
        $snippet = tk_hardening_server_rule_snippet();
        foreach (array('text/html', 'application/json', 'application/xml', 'text/xml') as $mime) { check_cache(strpos($snippet, 'ExpiresByType ' . $mime . ' "access plus 0 seconds"') !== false, $server . ': dynamic MIME cached'); }
        foreach (array('text/css', 'application/javascript', 'image/png') as $mime) { check_cache(strpos($snippet, 'ExpiresByType ' . $mime . ' "access plus 1 year"') !== false, 'Static asset lifetime changed'); }
    }
    update_option('tk_hardening_root_rules_hash', 'old-version-hash');
    tk_hardening_maybe_apply_root_server_rules();
    $first_writes = $writes;
    tk_hardening_maybe_apply_root_server_rules();
    check_cache($first_writes === 1 && $writes === 1 && strpos(file_get_contents($root . '/.htaccess'), 'ExpiresByType text/html "access plus 0 seconds"') !== false, 'Upgrade did not rewrite .htaccess exactly once');
    $_SERVER['SERVER_SOFTWARE'] = 'nginx';
    $snippet = tk_hardening_server_rule_snippet();
    preg_match('/location[^\n]+\{\n  expires 1y;/', $snippet, $asset_location);
    check_cache(!empty($asset_location) && !preg_match('/html|json|xml/', $asset_location[0]), 'Nginx long-cache location includes HTML/API types');

    // Identical keys for the real WordPress is_ssl semantics.
    foreach (array(array(), array('HTTPS' => 'on'), array('HTTPS' => '1'), array('SERVER_PORT' => 443), array('HTTPS' => 'off', 'SERVER_PORT' => 443)) as $ssl) {
        $_SERVER = array_merge(array('REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'EXAMPLE.test:8080', 'REQUEST_URI' => '/path?utm_custom=test'), $ssl);
        check_cache(tk_page_cache_key() === TK_Page_Cache_Runtime::key($_SERVER), 'Early key differs from normal key');
    }
    $config = array('dynamic_paths' => tk_cache_dynamic_path_patterns(), 'exclude_paths' => array('/excluded'));
    $_SERVER = array('REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.test', 'REQUEST_URI' => '/');
    foreach (array('/wp-admin', '/wp-admin/edit.php', '/wp-login.php', '/wp-json/posts', '/cart', '/cart/items', '/checkout', '/excluded/a', '/?s=term', '/?preview=true', '/?utm_source[]=x', '/wp%2dadmin/', '/%252fwp-admin', '/a/../wp-admin') as $uri) {
        check_cache(!TK_Page_Cache_Runtime::request_allowed($config, array_merge($_SERVER, array('REQUEST_URI' => $uri)), array()), 'Dynamic path/query served: ' . $uri);
    }
    foreach (array('/?utm_custom=x&gclid=y', '/?fbclid=abc&msclkid=def', '/public-page') as $uri) { check_cache(TK_Page_Cache_Runtime::request_allowed($config, array_merge($_SERVER, array('REQUEST_URI' => $uri)), array()), 'Public request excluded: ' . $uri); }
    foreach (array('wordpress_logged_in_123', 'wp-postpass_123', 'comment_author_123', 'wp_woocommerce_session_123', 'woocommerce_items_in_cart', 'PHPSESSID', 'cart') as $cookie) { check_cache(!TK_Page_Cache_Runtime::request_allowed($config, $_SERVER, array($cookie => 'x')), 'Dynamic cookie served: ' . $cookie); }
    foreach (array(array('REQUEST_METHOD' => 'POST'), array('HTTP_AUTHORIZATION' => 'Bearer x'), array('PHP_AUTH_USER' => 'u'), array('HTTP_RANGE' => 'bytes=0-5')) as $override) { check_cache(!TK_Page_Cache_Runtime::request_allowed($config, array_merge($_SERVER, $override), array()), 'Dynamic method/auth/range served'); }

    $input = '<html><head><!-- <script nonce="comment">demo</script> --><script data-demo=\' nonce="sample"\' NONCE = old nonce="duplicate">let demo = \'<style nonce="js-text">\';</script><style nonce=old>a { color: red }</style></head><body><div nonce="remove">Keep</div></body></html>';
    $clean = TK_Page_Cache_Runtime::html_nonce($input);
    check_cache(strpos($clean, 'NONCE = old') === false && strpos($clean, 'nonce="duplicate"') === false && strpos($clean, '<div>Keep</div>') !== false, 'Real nonce attributes persist on disk');
    check_cache(strpos($clean, '<!-- <script nonce="comment">demo</script> -->') !== false && strpos($clean, 'data-demo=\' nonce="sample"\'') !== false && strpos($clean, '<style nonce="js-text">') !== false, 'Nonce parser damaged comments/data/script text');
    $output = TK_Page_Cache_Runtime::html_nonce($clean, 'freshnonce');
    check_cache(substr_count($output, 'nonce="freshnonce"') === 2 && TK_Page_Cache_Runtime::html_nonce($output, 'freshnonce') === $output, 'Nonce application is missing or not idempotent');
    $templates = TK_Page_Cache_Runtime::header_templates(array('Content-Type: text/html; charset=UTF-8', "Content-Security-Policy: script-src 'nonce-old'; style-src 'nonce-other'", 'Set-Cookie: session=secret', 'Location: /private', 'Cache-Control: max-age=2592000', 'X-Frame-Options: SAMEORIGIN'));
    check_cache(count($templates) === 3 && strpos(implode('', $templates), 'secret') === false && substr_count(implode('', $templates), TK_Page_Cache_Runtime::NONCE) === 2, 'Unsafe headers persisted or CSP not templated');

    $config_file = $root . '/wp-config.php';
    $original_config = "<?php\n// define('WP_CACHE', false); example only\n\$sample = \"define('WP_CACHE', false)\";\nrequire 'wp-settings.php';\n";
    file_put_contents($config_file, $original_config);
    tk_page_cache_early_sync();
    $snapshot = include tk_page_cache_early_config_path();
    check_cache($snapshot['ttl'] === 3600 && $snapshot['exclude_paths'] === array('/excluded', '/special') && $snapshot['valid_until'] > time(), 'Early config snapshot incorrect');
    check_cache(tk_page_cache_early_owns_dropin(file_get_contents(WP_CONTENT_DIR . '/advanced-cache.php')), 'Owned drop-in not installed');
    check_cache(strpos(file_get_contents($config_file), '/* BEGIN Tool Kits WP_CACHE */') !== false, 'Managed WP_CACHE not added');
    $snapshot_text = file_get_contents(tk_page_cache_early_config_path());
    touch(tk_page_cache_early_config_path(), 1234567890);
    tk_page_cache_early_sync();
    clearstatcache();
    check_cache(filemtime(tk_page_cache_early_config_path()) === 1234567890 && file_get_contents(tk_page_cache_early_config_path()) === $snapshot_text, 'Unchanged admin config rewritten');
    $options['page_cache_ttl'] = 120;
    tk_page_cache_early_sync(); $snapshot = include tk_page_cache_early_config_path();
    check_cache($snapshot['ttl'] === 120, 'Changed TTL not synchronized');
    wp_mkdir_p(tk_page_cache_dir());
    file_put_contents(tk_page_cache_dir() . '/old.html', 'old');
    file_put_contents(tk_page_cache_dir() . '/old.html.meta.json', '{}');
    $snapshot['version'] = 'old-version'; $snapshot['valid_until'] = 1;
    file_put_contents(tk_page_cache_early_config_path(), '<?php return ' . var_export($snapshot, true) . ';');
    tk_page_cache_early_sync();
    $renewed = include tk_page_cache_early_config_path();
    check_cache(!is_file(tk_page_cache_dir() . '/old.html') && !is_file(tk_page_cache_dir() . '/old.html.meta.json') && $renewed['valid_until'] > time(), 'Upgrade did not purge old metadata or renew expired snapshot');
    $license_deadline = time() + 30;
    tk_page_cache_early_sync(); $bounded = include tk_page_cache_early_config_path();
    check_cache($bounded['valid_until'] === $license_deadline, 'Early cache outlives signed receipt');
    $license_deadline = time() - 1;
    tk_page_cache_early_sync();
    check_cache(!is_file(tk_page_cache_early_config_path()) && !is_file(WP_CONTENT_DIR . '/advanced-cache.php'), 'Expired signed receipt leaves early cache installed');
    unset($license_deadline);
    tk_page_cache_early_sync();
    $options['page_cache_enabled'] = 0; tk_page_cache_early_sync();
    check_cache(!is_file(tk_page_cache_early_config_path()) && !is_file(WP_CONTENT_DIR . '/advanced-cache.php'), 'Disabled page cache left early caching enabled');
    $options['page_cache_enabled'] = 1; tk_page_cache_early_sync();
    $licensed = false; tk_page_cache_early_sync();
    check_cache(!is_file(tk_page_cache_early_config_path()) && !is_file(WP_CONTENT_DIR . '/advanced-cache.php') && strpos(file_get_contents($config_file), 'BEGIN Tool Kits WP_CACHE') === false, 'Inactive license left early caching enabled');
    $licensed = true;
    // Foreign drop-ins and user-owned constants are never overwritten/removed.
    file_put_contents(WP_CONTENT_DIR . '/advanced-cache.php', '<?php /* another cache */');
    tk_page_cache_early_sync();
    check_cache(file_get_contents(WP_CONTENT_DIR . '/advanced-cache.php') === '<?php /* another cache */' && strpos($GLOBALS['tk_page_cache_early_notice'], 'another plugin') !== false, 'Foreign drop-in overwritten or no notice');
    unlink(WP_CONTENT_DIR . '/advanced-cache.php');
    file_put_contents($config_file, "<?php\ndefine('WP_CACHE', false);\n");
    tk_page_cache_early_sync();
    check_cache(file_get_contents($config_file) === "<?php\ndefine('WP_CACHE', false);\n" && strpos($GLOBALS['tk_page_cache_early_notice'], 'WP_CACHE') !== false, 'Existing WP_CACHE overwritten or no notice');
    tk_page_cache_early_remove();
    check_cache(file_get_contents($config_file) === "<?php\ndefine('WP_CACHE', false);\n", 'User-owned WP_CACHE removed');
    file_put_contents($config_file, $original_config);
    check_cache(tk_page_cache_early_hook_requirements() === array(), 'Inactive features reported as cache blockers');
    foreach (array('hardening_waf_enabled' => 'PHP firewall', 'hardening_httpauth_enabled' => 'HTTP authentication') as $key => $reason) {
        tk_page_cache_early_sync(); // Begin with an installed early cache.
        $options[$key] = 1; tk_page_cache_early_sync();
        check_cache(!is_file(tk_page_cache_early_config_path()) && !is_file(WP_CONTENT_DIR . '/advanced-cache.php'), 'Early cache bypasses security hook: ' . $key);
        check_cache(tk_cache_page_enabled() && tk_page_cache_early_hook_requirements() === array($reason), 'Normal cache or active reason incorrect: ' . $key);
        ob_start(); tk_cache_render_status_rows(); $status_html = ob_get_clean();
        check_cache(strpos($status_html, 'Mode: Normal page cache.') !== false && strpos($status_html, 'Active features: ' . $reason . '.') !== false, 'Cache Status did not explain the specific normal-mode requirement: ' . $key);
        $notices = array(); tk_page_cache_early_admin_notice();
        check_cache($GLOBALS['tk_page_cache_early_notice'] === '' && $notices === array(), 'Expected normal mode still raises a warning: ' . $key);
        $options[$key] = 0;
    }
    $multisite = true; tk_page_cache_early_sync();
    check_cache(tk_page_cache_early_hook_requirements() === array('Multisite') && $GLOBALS['tk_page_cache_early_notice'] === '' && !is_file(tk_page_cache_early_config_path()), 'Multisite should select normal cache without a warning');
    $options['hardening_waf_enabled'] = 1; $options['hardening_httpauth_enabled'] = 1;
    check_cache(tk_page_cache_early_hook_requirements() === array('Multisite', 'HTTP authentication', 'PHP firewall'), 'Status must show only active requirements');
    $options['hardening_waf_enabled'] = 0; $options['hardening_httpauth_enabled'] = 0; $multisite = false;
    ob_start(); tk_cache_render_status_rows(); $status_html = ob_get_clean();
    check_cache(strpos($status_html, 'Mode: Normal page cache.') === false, 'Status shows normal-mode reason when no feature requires it');
    $GLOBALS['tk_page_cache_early_notice'] = 'Actual setup failure'; $notices = array(); tk_page_cache_early_admin_notice();
    check_cache($notices === array(array('Actual setup failure', 'warning')), 'Actionable early cache warning was hidden');
    tk_page_cache_early_sync();
    $GLOBALS['tk_page_cache_early_deactivated'] = true;
    tk_page_cache_early_remove(); tk_page_cache_early_sync();
    check_cache(!is_file(WP_CONTENT_DIR . '/advanced-cache.php') && strpos(file_get_contents($config_file), 'BEGIN Tool Kits WP_CACHE') === false, 'Deactivation/shutdown reinstalled early cache');
    echo 'PASS: ' . $checks . " cache upgrade, .htaccess refresh, nonce/parser, eligibility/key parity, config sync, ownership, licensing and cleanup checks\n";
} finally { cleanup_cache_fixture($root); }
