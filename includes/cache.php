<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/cache-runtime.php';
require_once __DIR__ . '/cache-early.php';

function tk_cache_init() {
    $page_cache_enabled = (int) tk_get_option('page_cache_enabled', 0) === 1;

    add_action('admin_post_tk_cache_save', 'tk_cache_save');
    add_action('admin_post_tk_cache_purge', 'tk_cache_purge');
    add_action('admin_post_tk_cache_preload', 'tk_cache_preload');
    add_action('admin_post_tk_cache_refresh_detection', 'tk_cache_refresh_detection');
    add_action('admin_post_tk_cache_object_flush', 'tk_cache_object_flush');
    add_action('admin_post_tk_cache_opcache_reset', 'tk_cache_opcache_reset');
    add_action('admin_post_tk_fragment_cache_flush', 'tk_fragment_cache_flush');
    add_action('admin_bar_menu', 'tk_cache_admin_bar_menu', 100);
    add_action('admin_notices', 'tk_cache_admin_notice');
    add_action('wp_loaded', 'tk_page_cache_early_sync', 99);
    add_action('admin_notices', 'tk_page_cache_early_admin_notice');
    add_action('updated_option', 'tk_page_cache_early_option_changed', 20, 3);
    add_action('added_option', 'tk_page_cache_early_option_changed', 20);
    add_action('deleted_option', 'tk_page_cache_early_option_changed', 20);

    if ($page_cache_enabled) {
        add_action('template_redirect', 'tk_page_cache_maybe_serve', 0);
        add_action('template_redirect', 'tk_page_cache_start_buffer', 1);
    }
    add_action('save_post', 'tk_page_cache_purge_once');
    add_action('deleted_post', 'tk_page_cache_purge_once');
    add_action('transition_comment_status', 'tk_page_cache_purge_once');
    add_action('comment_post', 'tk_page_cache_purge_once');
    add_action('edit_comment', 'tk_page_cache_purge_once');
    add_action('deleted_comment', 'tk_page_cache_purge_once');
    add_action('clean_post_cache', 'tk_page_cache_purge_on_content_change');
    add_action('added_post_meta', 'tk_page_cache_purge_on_meta_change', 10, 4);
    add_action('updated_post_meta', 'tk_page_cache_purge_on_meta_change', 10, 4);
    add_action('deleted_post_meta', 'tk_page_cache_purge_on_meta_change', 10, 4);
    add_action('added_term_meta', 'tk_page_cache_purge_on_content_change');
    add_action('updated_term_meta', 'tk_page_cache_purge_on_content_change');
    add_action('deleted_term_meta', 'tk_page_cache_purge_on_content_change');
    add_action('set_object_terms', 'tk_page_cache_purge_on_content_change');
    add_action('deleted_term_relationships', 'tk_page_cache_purge_on_content_change');
    add_action('created_term', 'tk_page_cache_purge_on_content_change');
    add_action('edited_term', 'tk_page_cache_purge_on_content_change');
    add_action('delete_term', 'tk_page_cache_purge_on_content_change');
    add_action('wp_update_nav_menu', 'tk_page_cache_purge_on_content_change');
    add_action('customize_save_after', 'tk_page_cache_purge_on_content_change');
    add_action('switch_theme', 'tk_page_cache_purge_on_content_change');
    add_action('added_option', 'tk_page_cache_purge_on_option_change');
    add_action('updated_option', 'tk_page_cache_purge_on_updated_option', 10, 3);
    add_action('deleted_option', 'tk_page_cache_purge_on_option_change');
}

function tk_cache_dir() {
    return trailingslashit(WP_CONTENT_DIR) . 'cache/tool-kits';
}

function tk_page_cache_dir() {
    if (function_exists('is_multisite') && is_multisite()) {
        return trailingslashit(tk_cache_dir()) . 'page/blog-' . get_current_blog_id();
    }
    return trailingslashit(tk_cache_dir()) . 'page';
}

function tk_page_cache_stats() {
    $dir = tk_page_cache_dir();
    $stats = array(
        'files' => 0,
        'bytes' => 0,
    );

    if (!is_dir($dir)) {
        return $stats;
    }

    $files = glob($dir . '/*.html');
    if (!is_array($files)) {
        return $stats;
    }

    foreach ($files as $file) {
        if (!is_string($file) || $file === '' || !is_file($file)) {
            continue;
        }
        $size = @filesize($file);
        $stats['files']++;
        $stats['bytes'] += $size !== false ? (int) $size : 0;
    }

    return $stats;
}

function tk_page_cache_summary_text(array $stats) {
    $files = isset($stats['files']) ? max(0, (int) $stats['files']) : 0;
    $bytes = isset($stats['bytes']) ? max(0, (int) $stats['bytes']) : 0;

    if ($files === 0 || $bytes === 0) {
        return '0 files (0 B)';
    }

    return $files . ' file' . ($files === 1 ? '' : 's') . ' (' . size_format($bytes) . ')';
}

function tk_server_cache_status(bool $force = false): array {
    $cached = $force ? false : get_transient('tk_server_cache_status');
    if (is_array($cached)) {
        return $cached;
    }

    $layers = tk_server_cache_known_layers();
    $headers = array();
    $detected = !empty($layers);
    $detail = '';

    $response = wp_remote_head(home_url('/'), array(
        'timeout' => 4,
        'redirection' => 3,
        'sslverify' => false,
        'headers' => array(
            'Cache-Control' => 'no-cache',
            'User-Agent' => 'Tool Kits Cache Detector',
        ),
    ));

    if (is_wp_error($response)) {
        $detail = 'Detection request failed: ' . $response->get_error_message();
    } else {
        $headers = tk_server_cache_extract_headers(wp_remote_retrieve_headers($response));
        $header_layers = tk_server_cache_detect_from_headers($headers);
        $layers = array_merge($layers, $header_layers);
        $layers = array_values(array_unique(array_filter($layers)));
        $detected = !empty($layers);
    }

    if ($detail === '') {
        $detail = $detected
            ? implode(', ', $layers)
            : 'No server/CDN cache headers detected on homepage response.';
    }

    $status = array(
        'detected' => $detected,
        'label' => $detected ? 'DETECTED' : 'NOT DETECTED',
        'detail' => $detail,
        'headers' => $headers,
    );

    set_transient('tk_server_cache_status', $status, 5 * MINUTE_IN_SECONDS);
    return $status;
}

function tk_server_cache_known_layers(): array {
    $layers = array();

    if (defined('WP_CACHE') && WP_CACHE) {
        $layers[] = 'WordPress advanced-cache enabled';
    }
    if (function_exists('litespeed_purge_all') || defined('LSCWP_V')) {
        $layers[] = 'LiteSpeed cache plugin/server hook';
    }
    if (class_exists('WpeCommon')) {
        $layers[] = 'WP Engine cache';
    }
    if (function_exists('w3tc_flush_all')) {
        $layers[] = 'W3 Total Cache';
    }
    if (function_exists('rocket_clean_domain')) {
        $layers[] = 'WP Rocket';
    }
    if (function_exists('wp_cache_clear_cache')) {
        $layers[] = 'WP Super Cache';
    }
    if (class_exists('Cache_Enabler')) {
        $layers[] = 'Cache Enabler';
    }
    if (class_exists('WpFastestCache')) {
        $layers[] = 'WP Fastest Cache';
    }

    return $layers;
}

function tk_server_cache_extract_headers($headers): array {
    $out = array();
    if (!is_object($headers) && !is_array($headers)) {
        return $out;
    }

    foreach ($headers as $name => $value) {
        $name = strtolower((string) $name);
        if ($name === '') {
            continue;
        }
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }
        $out[$name] = trim((string) $value);
    }

    return $out;
}

function tk_server_cache_detect_from_headers(array $headers): array {
    $layers = array();
    $map = array(
        'cf-cache-status' => 'Cloudflare cache',
        'x-cache' => 'Proxy/CDN cache',
        'x-cache-status' => 'Proxy cache',
        'x-proxy-cache' => 'Proxy cache',
        'x-nginx-cache' => 'Nginx cache',
        'x-fastcgi-cache' => 'FastCGI cache',
        'x-litespeed-cache' => 'LiteSpeed cache',
        'x-litespeed-cache-control' => 'LiteSpeed cache',
        'x-varnish' => 'Varnish cache',
        'x-cache-hits' => 'Varnish/proxy cache',
        'age' => 'CDN/proxy cache',
        'server-timing' => 'Server timing cache signal',
    );

    foreach ($map as $header => $label) {
        if (!isset($headers[$header]) || $headers[$header] === '') {
            continue;
        }

        $value = $headers[$header];
        if ($header === 'server-timing' && stripos($value, 'cache') === false) {
            continue;
        }

        $layers[] = $label . ' (' . $header . ': ' . $value . ')';
    }

    return $layers;
}

function tk_server_cache_purge_layers(): array {
    static $results = array();
    $blog_id = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
    if (isset($results[$blog_id])) { return $results[$blog_id]; }
    $results[$blog_id] = array('ok' => true, 'actions' => array(), 'errors' => array(), 'message' => 'Purge in progress.');
    $actions = array();
    $errors = array();

    if (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
        $actions[] = 'WP Rocket cache cleared.';
    }
    if (function_exists('w3tc_flush_all')) {
        w3tc_flush_all();
        $actions[] = 'W3 Total Cache cleared.';
    }
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
        $actions[] = 'WP Super Cache cleared.';
    }
    if (function_exists('litespeed_purge_all')) {
        litespeed_purge_all();
        $actions[] = 'LiteSpeed cache cleared.';
    }
    if (has_action('litespeed_purge_all')) {
        do_action('litespeed_purge_all');
        $actions[] = 'LiteSpeed purge hook triggered.';
    }
    if (has_action('cloudflare_purge_cache')) {
        do_action('cloudflare_purge_cache');
        $actions[] = 'Cloudflare purge hook triggered.';
    }
    if (class_exists('WpeCommon')) {
        if (method_exists('WpeCommon', 'purge_memcached')) {
            WpeCommon::purge_memcached();
        }
        if (method_exists('WpeCommon', 'purge_varnish_cache')) {
            WpeCommon::purge_varnish_cache();
        }
        $actions[] = 'WP Engine cache cleared.';
    }
    if (class_exists('autoptimizeCache')) {
        autoptimizeCache::clearall();
        $actions[] = 'Autoptimize cache cleared.';
    }
    if (class_exists('Cache_Enabler')) {
        Cache_Enabler::clear_total_cache();
        $actions[] = 'Cache Enabler cleared.';
    }
    if (class_exists('WpFastestCache')) {
        $wpf = new WpFastestCache();
        if (method_exists($wpf, 'deleteCache')) {
            $wpf->deleteCache();
            $actions[] = 'WP Fastest Cache cleared.';
        } else {
            $errors[] = 'WP Fastest Cache purge method unavailable.';
        }
    }

    delete_transient('tk_server_cache_status');

    if (empty($actions) && empty($errors)) {
        $actions[] = 'No purgeable server/plugin cache layer detected.';
    }

    $results[$blog_id] = array(
        'ok' => empty($errors),
        'actions' => $actions,
        'errors' => $errors,
        'message' => trim(implode(' ', array_merge($actions, $errors))),
    );
    return $results[$blog_id];
}

function tk_page_cache_key() {
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $scheme = is_ssl() ? 'https' : 'http';
    // Multisite is isolated by tk_page_cache_dir(), not by a different key.
    return md5($scheme . '|' . $host . '|' . $uri);
}

function tk_page_cache_path() {
    $dir = tk_page_cache_dir();
    return trailingslashit($dir) . tk_page_cache_key() . '.html';
}

function tk_cache_page_enabled(): bool {
    return (int) tk_get_option('page_cache_enabled', 0) === 1;
}

function tk_cache_request_has_dynamic_query(): bool {
    if (empty($_GET) || !is_array($_GET)) {
        return false;
    }

    return TK_Page_Cache_Runtime::dynamic_query($_GET, TK_Page_Cache_Runtime::allowed_query_keys());
}

function tk_cache_has_dynamic_cookie(): bool {
    if (empty($_COOKIE) || !is_array($_COOKIE)) {
        return false;
    }

    return TK_Page_Cache_Runtime::dynamic_cookie($_COOKIE, TK_Page_Cache_Runtime::cookie_markers());
}

function tk_cache_dynamic_path_patterns(): array {
    $patterns = array(
        '/wp-login.php',
        '/wp-admin',
        '/wp-json',
        '/xmlrpc.php',
        '/cart',
        '/checkout',
        '/my-account',
        '/account',
        '/wc-api',
        '/edd-api',
        '/members',
        '/login',
        '/logout',
        '/register',
        '/search',
    );

    if (function_exists('wc_get_page_permalink')) {
        foreach (array('cart', 'checkout', 'myaccount') as $page) {
            $url = wc_get_page_permalink($page);
            $path = is_string($url) ? (string) wp_parse_url($url, PHP_URL_PATH) : '';
            if ($path !== '') {
                $patterns[] = rtrim($path, '/');
            }
        }
    }

    return array_values(array_unique(array_filter($patterns, 'strlen')));
}

function tk_cache_is_dynamic_path(string $path): bool {
    $path = '/' . ltrim($path, '/');
    $path = rtrim($path, '/') ?: '/';

    foreach (tk_cache_dynamic_path_patterns() as $pattern) {
        $pattern = '/' . ltrim((string) $pattern, '/');
        $pattern = rtrim($pattern, '/') ?: '/';
        if ($pattern !== '/' && ($path === $pattern || strpos($path . '/', $pattern . '/') === 0)) {
            return true;
        }
    }

    if (function_exists('is_search') && is_search()) {
        return true;
    }
    if (function_exists('is_cart') && is_cart()) {
        return true;
    }
    if (function_exists('is_checkout') && is_checkout()) {
        return true;
    }
    if (function_exists('is_account_page') && is_account_page()) {
        return true;
    }

    return false;
}

function tk_cache_response_allows_store(): bool {
    if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
        return false;
    }

    foreach (headers_list() as $header) {
        $header = strtolower((string) $header);
        if (strpos($header, 'set-cookie:') === 0) { return false; }
        if (strpos($header, 'content-type:') === 0 && strpos($header, 'text/html') === false) { return false; }
        if (strpos($header, 'vary:') === 0) {
            $vary = array_filter(array_map('trim', explode(',', substr($header, strlen('vary:')))));
            if (array_diff($vary, array('accept-encoding'))) { return false; }
        }
        if (strpos($header, 'cache-control:') === 0 && preg_match('/\b(no-store|no-cache|private)\b/', $header)) {
            return false;
        }
        if (strpos($header, 'x-robots-tag:') === 0 && strpos($header, 'noarchive') !== false) {
            return false;
        }
    }

    return true;
}

function tk_cache_is_cacheable_request() {
    if (!tk_license_features_enabled()) {
        return false;
    }
    if (!tk_cache_page_enabled()) {
        return false;
    }
    if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
        return false;
    }
    if ((defined('REST_REQUEST') && REST_REQUEST) || !empty($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['PHP_AUTH_USER']) || !empty($_SERVER['HTTP_RANGE'])) { return false; }
    if (is_admin() || wp_doing_ajax() || is_feed() || is_preview()) {
        return false;
    }
    if (is_user_logged_in()) {
        return false;
    }
    if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'GET') {
        return false;
    }
    if (is_404()) {
        return false;
    }
    if (tk_cache_request_has_dynamic_query() || tk_cache_has_dynamic_cookie()) {
        return false;
    }
    $path = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = strtok($path, '?');
    if ($path === '') {
        return false;
    }
    if (tk_cache_is_dynamic_path($path)) {
        return false;
    }
    if (!TK_Page_Cache_Runtime::request_allowed(array('dynamic_paths' => tk_cache_dynamic_path_patterns()), $_SERVER, $_COOKIE)) { return false; }
    $excludes = tk_get_option('page_cache_exclude_paths', "/wp-login.php\n/wp-admin\n");
    $list = array_filter(array_map('trim', explode("\n", (string) $excludes)));
    foreach ($list as $item) {
        if ($item === '') {
            continue;
        }
        if (strpos($path, $item) === 0) {
            return false;
        }
    }
    return true;
}

function tk_page_cache_maybe_serve() {
    if (!tk_cache_is_cacheable_request()) {
        return;
    }
    $path = tk_page_cache_path();
    if (!file_exists($path)) {
        return;
    }
    $ttl = (int) tk_get_option('page_cache_ttl', 3600);
    if ($ttl > 0 && (time() - filemtime($path)) > $ttl) {
        // One writer refreshes an expired page; contenders may use a short stale window.
        if (tk_page_cache_acquire_writer() || time() - filemtime($path) > $ttl + 30) {
            return;
        }
    }
    $page = TK_Page_Cache_Runtime::read($path, 0); // TTL/stale window checked above.
    if ($page === null || headers_sent() || !tk_cache_response_allows_store()) { return; }
    $nonce = tk_page_cache_csp_nonce();
    // Current request headers take priority; retain cached theme headers that
    // would otherwise be skipped when a theme emits them at template_redirect.
    $headers = headers_list();
    $names = array_map(function($line) { return strtolower(explode(':', $line, 2)[0]); }, $headers);
    $templates = array_values(array_filter($page['headers'], function($line) use ($names) { return !in_array(strtolower(explode(':', $line, 2)[0]), $names, true); }));
    if ($nonce === '' && strpos(implode("\n", $templates), TK_Page_Cache_Runtime::NONCE) !== false) {
        try { $nonce = TK_Page_Cache_Runtime::new_nonce(); } catch (Exception $error) { return; }
    }
    TK_Page_Cache_Runtime::send_headers(array_merge($templates, $headers), $nonce, 'HIT');
    echo TK_Page_Cache_Runtime::html_nonce($page['html'], $nonce);
    exit;
}

function tk_page_cache_csp_nonce(): string {
    $nonce = TK_Page_Cache_Runtime::nonce_from_headers(headers_list());
    if ($nonce === '' && function_exists('tk_csp_nonce')) { $nonce = tk_csp_nonce(); }
    // Themes with their own CSP generator can supply the header's request nonce.
    $nonce = apply_filters('tk_page_cache_csp_nonce', $nonce);
    return is_string($nonce) && preg_match('~^[A-Za-z0-9+/_=-]+$~D', $nonce) ? $nonce : '';
}

function tk_page_cache_start_buffer() {
    if (!tk_cache_is_cacheable_request()) {
        return;
    }
    if (!tk_page_cache_acquire_writer()) { return; }
    ob_start('tk_page_cache_callback');
}

// flock is atomic across local PHP workers and needs no option/transient writes.
// A fixed number of lock shards bounds disk growth even with tracking URLs.
function tk_page_cache_acquire_writer(): bool {
    global $tk_page_cache_writer;
    $path = tk_page_cache_path();
    if (!empty($tk_page_cache_writer)) { return $tk_page_cache_writer['path'] === $path; }
    $dir = tk_page_cache_dir();
    if (!is_dir($dir) && !wp_mkdir_p($dir)) { return false; }
    $lock = @fopen($dir . '/.writer-' . substr(tk_page_cache_key(), 0, 2), 'c');
    if (!$lock) { return false; }
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return false; }
    $state = @fopen($dir . '/.generation', 'c+');
    if (!$state) { fclose($lock); return false; }
    if (!flock($state, LOCK_SH)) { fclose($state); fclose($lock); return false; }
    $generation = stream_get_contents($state);
    flock($state, LOCK_UN);
    $tk_page_cache_writer = array('path' => $path, 'lock' => $lock, 'state' => $state, 'generation' => $generation, 'partial' => false);
    return true;
}

function tk_page_cache_release_writer(): void {
    global $tk_page_cache_writer;
    if (empty($tk_page_cache_writer)) { return; }
    fclose($tk_page_cache_writer['state']);
    flock($tk_page_cache_writer['lock'], LOCK_UN);
    fclose($tk_page_cache_writer['lock']);
    $tk_page_cache_writer = null;
}

function tk_page_cache_callback($html, $phase = PHP_OUTPUT_HANDLER_FINAL) {
    global $tk_page_cache_writer;
    if (empty($tk_page_cache_writer)) { return $html; }
    if (!($phase & PHP_OUTPUT_HANDLER_FINAL)) {
        $tk_page_cache_writer['partial'] = true;
        return $html;
    }
    try {
        if ($tk_page_cache_writer['partial'] || ($phase & PHP_OUTPUT_HANDLER_CLEAN)) { return $html; }
        if ($tk_page_cache_writer['path'] !== tk_page_cache_path()) { return $html; }
        if (!tk_cache_is_cacheable_request()) {
            return $html;
        }
        $code = function_exists('http_response_code') ? http_response_code() : 200;
        if ($code !== 200) {
            return $html;
        }
        if (headers_sent() || !tk_cache_response_allows_store()) {
            return $html;
        }
        $headers = TK_Page_Cache_Runtime::header_templates(headers_list());
        $nonce = tk_page_cache_csp_nonce();
        $stored_html = TK_Page_Cache_Runtime::html_nonce($html);
        $state = $tk_page_cache_writer['state'];
        if (!flock($state, LOCK_SH)) { return $html; }
        rewind($state);
        // A purge during rendering must prevent an old response repopulating cache.
        if (stream_get_contents($state) === $tk_page_cache_writer['generation']) {
            $path = $tk_page_cache_writer['path'];
            $tmp = tempnam(dirname($path), '.page-');
            if ($tmp !== false) {
                $written = @file_put_contents($tmp, $stored_html);
                if ($written === strlen($stored_html) && @rename($tmp, $path)) {
                    $meta = array('format' => TK_Page_Cache_Runtime::FORMAT, 'generation' => $tk_page_cache_writer['generation'], 'sha256' => hash('sha256', $stored_html), 'headers' => $headers);
                    // Readers reject a mismatched pair during publication.
                    tk_page_cache_early_atomic_write($path . '.meta.json', (string) json_encode($meta, JSON_UNESCAPED_SLASHES));
                }
                if (is_file($tmp)) { @unlink($tmp); }
            }
        }
        flock($state, LOCK_UN);
        TK_Page_Cache_Runtime::send_headers($headers, $nonce, 'MISS');
        return $nonce !== '' ? TK_Page_Cache_Runtime::html_nonce($html, $nonce) : $html;
    } finally { tk_page_cache_release_writer(); }
}

function tk_page_cache_purge() {
    $dir = tk_page_cache_dir();
    $deleted = 0;

    if (!is_dir($dir)) {
        return array(
            'files' => 0,
            'bytes' => 0,
            'deleted' => 0,
        );
    }
    $state = @fopen($dir . '/.generation', 'c+');
    if (!$state || !flock($state, LOCK_EX)) {
        if ($state) { fclose($state); }
        return array('files' => 0, 'bytes' => 0, 'deleted' => 0);
    }
    ftruncate($state, 0);
    fwrite($state, uniqid('', true));
    fflush($state);
    $before = array('files' => 0, 'bytes' => 0);
    $files = glob($dir . '/*.html');
    if (is_array($files)) {
        foreach ($files as $file) {
            $before['files']++;
            $before['bytes'] += (int) @filesize($file);
            if (@unlink($file)) {
                $deleted++;
            }
        }
    }
    foreach (glob($dir . '/*.html.meta.json') ?: array() as $meta_file) { @unlink($meta_file); }
    flock($state, LOCK_UN);
    fclose($state);

    return array(
        'files' => isset($before['files']) ? (int) $before['files'] : 0,
        'bytes' => isset($before['bytes']) ? (int) $before['bytes'] : 0,
        'deleted' => $deleted,
    );
}

function tk_page_cache_purge_on_content_change() {
    tk_page_cache_purge_once();
}

function tk_page_cache_purge_on_meta_change($meta_id, $post_id, $meta_key, $value) {
    $ignored = apply_filters('tk_page_cache_ignored_post_meta_keys', array('_tk_seo_rendered_audit', '_edit_lock', '_edit_last'), $post_id);
    if (in_array($meta_key, (array) $ignored, true)) {
        return;
    }
    if (!apply_filters('tk_page_cache_purge_for_meta_change', true, $meta_key, $post_id)) { return; }
    tk_page_cache_purge_once();
}

function tk_page_cache_purge_on_updated_option($option, $old_value, $new_value) {
    if ($option === 'tk_options' && is_array($old_value) && is_array($new_value)) {
        // Audit results are admin-only data, not changes to rendered page content.
        $reports = array(
            'seo_geo_audit_report', 'seo_content_audit_report', 'seo_canonical_audit_report',
            'seo_broken_links_report', 'seo_index_monitor', 'seo_indexability_report', 'authority_reports',
            'geo_visibility_report', 'geo_prompt_preview_report', 'geo_post_schema_report',
            'geo_schema_duplicate_report', 'geo_schema_duplicate_fix_report',
            'geo_crawler_preview', 'geo_ai_access_report',
            'geo_ai_search_radar_report', 'geo_ai_radar_report',
            'geo_fix_history',
            'monitoring_404_log', 'toolkits_audit_log', 'operations_health_report',
            'smtp_dns_report', 'image_opt_compression_report', 'image_opt_cleanup_report',
            'image_opt_queue', 'tk_fim_last_scan_result', 'tk_fim_last_scan_time',
            'fragment_cache_keys',
            'heartbeat_last_success', 'heartbeat_last_failure', 'heartbeat_last_message',
            'heartbeat_last_checked', 'heartbeat_last_error_message', 'heartbeat_last_endpoint',
            'security_events_backfilled', 'security_events_last_backfill_count', 'security_events_last_backfill_time',
        );
        $reports = (array) apply_filters('tk_page_cache_ignored_config_keys', $reports);
        foreach ($reports as $key) {
            unset($old_value[$key], $new_value[$key]);
        }
        if ($old_value === $new_value) { return; }
    }
    tk_page_cache_purge_on_option_change($option);
}

function tk_page_cache_purge_on_option_change($option) {
    $option = is_scalar($option) ? (string) $option : '';
    if ($option === '' || tk_page_cache_should_ignore_option_change($option)) {
        return;
    }

    tk_page_cache_purge_once();
}

function tk_page_cache_purge_once() {
    if (!tk_cache_page_enabled()) { return; }
    global $tk_page_cache_pending_blogs;
    $blog_id = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
    if (!empty($tk_page_cache_pending_blogs[$blog_id])) { return; }
    $first = empty($tk_page_cache_pending_blogs);
    $tk_page_cache_pending_blogs[$blog_id] = true;
    // Defer until all content and metadata updates in this request are complete.
    if ($first) { add_action('shutdown', 'tk_page_cache_flush_pending', PHP_INT_MAX); }
}

function tk_page_cache_flush_pending() {
    global $tk_page_cache_pending_blogs;
    $blogs = (array) $tk_page_cache_pending_blogs;
    $tk_page_cache_pending_blogs = array();
    foreach (array_keys($blogs) as $blog_id) {
        $switched = function_exists('get_current_blog_id') && get_current_blog_id() !== $blog_id;
        if ($switched) { switch_to_blog($blog_id); }
        try { tk_page_cache_purge(); }
        finally { if ($switched) { restore_current_blog(); } }
    }
}

function tk_page_cache_should_ignore_option_change(string $option): bool {
    if (strpos($option, '_transient_') === 0 || strpos($option, '_site_transient_') === 0) {
        return true;
    }

    $ignored = array(
        'cron',
        'doing_cron',
        'rewrite_rules',
        'recently_activated',
        'uninstall_plugins',
        'tk_cache_purged_notice',
        'tk_cache_preload_notice',
        'tk_cache_object_flush',
        'tk_cache_opcache_reset',
        'tk_fragment_cache_keys',
    );

    if (in_array($option, $ignored, true)) {
        return true;
    }

    $ignored_prefixes = array(
        'tk_html_scan_',
        'tk_health_center_',
        '_site_transient_',
        '_transient_',
        'tk_audit_',
        'tk_login_',
        'tk_monitoring_',
    );

    foreach ($ignored_prefixes as $prefix) {
        if (strpos($option, $prefix) === 0) {
            return true;
        }
    }

    return (bool) apply_filters('tk_page_cache_ignore_option_change', false, $option);
}

function tk_cache_save() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_cache_save');

    $was_page_cache_enabled = (int) tk_get_option('page_cache_enabled', 0) === 1;
    $page_cache_enabled = !empty($_POST['page_cache_enabled']) ? 1 : 0;
    tk_update_option('page_cache_enabled', $page_cache_enabled);
    tk_update_option('page_cache_ttl', max(0, (int) tk_post('page_cache_ttl', 3600)));
    tk_update_option('page_cache_exclude_paths', (string) tk_post('page_cache_exclude_paths', "/wp-login.php\n/wp-admin\n"));
    tk_update_option('page_cache_preload_urls', (string) tk_post('page_cache_preload_urls', ''));
    tk_update_option('page_cache_auto_preload_after_purge', !empty($_POST['page_cache_auto_preload_after_purge']) ? 1 : 0);
    tk_page_cache_early_sync();

    if ($was_page_cache_enabled && $page_cache_enabled === 0) {
        $purged = tk_page_cache_purge();
        set_transient(
            'tk_cache_purged_notice',
            'Page cache disabled. Removed ' . tk_page_cache_summary_text($purged) . '.',
            30
        );
    }

    wp_redirect(admin_url('admin.php?page=tool-kits-cache&tk_updated=1'));
    exit;
}

function tk_cache_preload_urls(): array {
    $urls = array(home_url('/'));

    $post_types = get_post_types(array('public' => true), 'names');
    unset($post_types['attachment']);
    if (!empty($post_types)) {
        $ids = get_posts(array(
            'post_type' => array_values($post_types),
            'post_status' => 'publish',
            'numberposts' => 20,
            'fields' => 'ids',
            'orderby' => 'modified',
            'order' => 'DESC',
        ));
        foreach ($ids as $post_id) {
            $url = get_permalink((int) $post_id);
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }
    }

    $custom = (string) tk_get_option('page_cache_preload_urls', '');
    foreach (preg_split('/\r\n|\r|\n/', $custom) ?: array() as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        if (!preg_match('#^https?://#i', $line)) {
            $line = home_url('/' . ltrim($line, '/'));
        }
        $urls[] = $line;
    }

    $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    $filtered = array();
    foreach ($urls as $url) {
        $url = esc_url_raw((string) $url);
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if ($url === '' || ($home_host !== '' && $host !== '' && strcasecmp($home_host, $host) !== 0)) {
            continue;
        }
        $filtered[$url] = true;
    }

    return array_slice(array_keys($filtered), 0, 50);
}

function tk_cache_preload() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_cache_preload');

    if (!tk_get_option('page_cache_enabled', 0)) {
        set_transient('tk_cache_preload_notice', 'Page cache is disabled. Enable it before preloading.', 30);
        wp_redirect(admin_url('admin.php?page=tool-kits-cache&tk_preload=fail#page'));
        exit;
    }

    $result = tk_cache_preload_run();
    $message = !empty($result['deferred']) ? 'Preload skipped: a recent request is still within the five-minute cooldown.' : 'Preload finished. Warmed ' . (int) $result['ok'] . ' URL(s), failed ' . (int) $result['failed'] . ', not attempted ' . (int) ($result['remaining'] ?? 0) . '.';
    set_transient('tk_cache_preload_notice', $message, 30);
    wp_redirect(admin_url('admin.php?page=tool-kits-cache&tk_preload=ok#page'));
    exit;
}

function tk_cache_preload_run(): array {
    if (get_transient('tk_cache_preload_cooldown')) {
        return array('ok' => 0, 'failed' => 0, 'urls' => 0, 'deferred' => true, 'remaining' => 0);
    }
    set_transient('tk_cache_preload_cooldown', 1, 5 * MINUTE_IN_SECONDS);
    $urls = tk_cache_preload_urls();
    $started = microtime(true);
    $ok = 0;
    $failed = 0;
    foreach ($urls as $url) {
        $remaining = 12 - (microtime(true) - $started);
        if ($remaining < 1) { break; }
        $response = wp_remote_get($url, array(
            'timeout' => min(8, $remaining),
            'redirection' => 3,
            'sslverify' => false,
            'headers' => array(
                'User-Agent' => 'Tool Kits Cache Preloader',
            ),
        ));
        if (is_wp_error($response)) {
            $failed++;
            break;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 400) {
            $ok++;
        } else {
            $failed++;
            break;
        }
    }

    return array(
        'ok' => $ok,
        'failed' => $failed,
        'urls' => count($urls),
        'remaining' => count($urls) - $ok - $failed,
    );
}

function tk_cache_purge() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_cache_purge');
    $purged = tk_page_cache_purge();
    $message = 'Page cache cleared. Removed ' . tk_page_cache_summary_text($purged) . '.';
    if (isset($purged['files'], $purged['deleted']) && (int) $purged['files'] !== (int) $purged['deleted']) {
        $message .= ' Deleted ' . (int) $purged['deleted'] . ' item(s).';
    }
    $external = tk_server_cache_purge_layers();
    if (!empty($external['message'])) {
        $message .= ' ' . (string) $external['message'];
    }
    if ((int) tk_get_option('page_cache_auto_preload_after_purge', 0) === 1 && (int) tk_get_option('page_cache_enabled', 0) === 1) {
        $preloaded = tk_cache_preload_run();
        $message .= !empty($preloaded['deferred']) ? ' Auto-preload skipped during cooldown.' : ' Auto-preload warmed ' . (int) $preloaded['ok'] . ' URL(s), failed ' . (int) $preloaded['failed'] . ', not attempted ' . (int) ($preloaded['remaining'] ?? 0) . '.';
    }
    set_transient('tk_cache_purged_notice', $message, 30);
    $redirect = trim((string) tk_post('redirect_to', ''));
    if ($redirect === '' && isset($_GET['redirect_to'])) {
        $redirect = trim((string) wp_unslash($_GET['redirect_to']));
    }
    if ($redirect === '') {
        $redirect = wp_get_referer();
    }
    if (!$redirect) {
        $redirect = admin_url('admin.php?page=tool-kits-cache');
    }
    wp_safe_redirect($redirect);
    exit;
}

function tk_cache_refresh_detection() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_cache_refresh_detection');
    delete_transient('tk_server_cache_status');
    $status = tk_server_cache_status(true);
    set_transient(
        'tk_cache_detection_notice',
        !empty($status['detected']) ? 'Server/CDN cache detected: ' . (string) $status['detail'] : 'No server/CDN cache detected.',
        30
    );
    wp_redirect(admin_url('admin.php?page=tool-kits-cache&tk_detection=1#status'));
    exit;
}

function tk_cache_consume_purged_notice() {
    $message = get_transient('tk_cache_purged_notice');
    if (!is_string($message) || $message === '') {
        return false;
    }

    delete_transient('tk_cache_purged_notice');
    return $message;
}

function tk_cache_admin_bar_menu($wp_admin_bar) {
    if (!is_admin_bar_showing() || !tk_is_admin_user() || !tk_get_option('page_cache_enabled', 0)) {
        return;
    }

    $current_url = isset($_SERVER['REQUEST_URI']) ? home_url(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '';
    $url = wp_nonce_url(
        add_query_arg(
            array(
                'action' => 'tk_cache_purge',
                'redirect_to' => $current_url !== '' ? $current_url : tk_admin_url('tool-kits-cache'),
            ),
            admin_url('admin-post.php')
        ),
        'tk_cache_purge',
        '_tk_nonce'
    );

    $wp_admin_bar->add_node(array(
        'id' => 'tool-kits',
        'title' => __('Tool Kits', 'tool-kits'),
        'href' => tk_admin_url('tool-kits-cache'),
        'meta' => array(
            'title' => __('Open Tool Kits cache settings', 'tool-kits'),
        ),
    ));

    $wp_admin_bar->add_node(array(
        'id' => 'tk-cache-purge',
        'parent' => 'tool-kits',
        'title' => __('Purge Page Cache', 'tool-kits'),
        'href' => $url,
        'meta' => array(
            'title' => __('Clear Tool Kits page cache', 'tool-kits'),
        ),
    ));
}

function tk_cache_admin_notice() {
    if (!is_admin()) {
        return;
    }
    if (!tk_is_admin_user()) {
        return;
    }
    if (isset($_GET['page']) && sanitize_key((string) $_GET['page']) === 'tool-kits-cache') {
        return;
    }

    $message = tk_cache_consume_purged_notice();
    if (!$message) {
        return;
    }

    tk_notice($message, 'success');
}

function tk_cache_object_flush() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_cache_object_flush');
    $ok = function_exists('wp_cache_flush') ? wp_cache_flush() : false;
    set_transient('tk_cache_object_flush', $ok ? 'ok' : 'fail', 30);
    wp_redirect(admin_url('admin.php?page=tool-kits-cache&tk_object=' . ($ok ? 'ok' : 'fail')));
    exit;
}

function tk_cache_opcache_reset() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_cache_opcache_reset');
    $ok = false;
    if (function_exists('opcache_reset') && ini_get('opcache.enable')) {
        $ok = @opcache_reset();
    }
    set_transient('tk_cache_opcache_reset', $ok ? 'ok' : 'fail', 30);
    wp_redirect(admin_url('admin.php?page=tool-kits-cache&tk_opcache=' . ($ok ? 'ok' : 'fail')));
    exit;
}

function tk_fragment_cache_get($key) {
    if (!tk_cache_page_enabled()) {
        return false;
    }

    $cache_key = 'tk_frag_' . md5((string) $key);
    return get_transient($cache_key);
}

function tk_fragment_cache_set($key, $value, $ttl = 300) {
    if (!tk_cache_page_enabled()) {
        return false;
    }

    $cache_key = 'tk_frag_' . md5((string) $key);
    $ttl = max(1, (int) $ttl);
    set_transient($cache_key, $value, $ttl);
    $keys = tk_get_option('fragment_cache_keys', array());
    if (!is_array($keys)) {
        $keys = array();
    }
    if (!in_array($cache_key, $keys, true)) {
        $keys[] = $cache_key;
        if (count($keys) > 200) {
            $keys = array_slice($keys, -200);
        }
        tk_update_option('fragment_cache_keys', $keys);
    }

    return true;
}

function tk_fragment_cache_flush() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_fragment_cache_flush');
    $keys = tk_get_option('fragment_cache_keys', array());
    if (is_array($keys)) {
        foreach ($keys as $cache_key) {
            delete_transient($cache_key);
        }
    }
    tk_update_option('fragment_cache_keys', array());
    wp_redirect(admin_url('admin.php?page=tool-kits-cache&tk_fragment=ok'));
    exit;
}

function tk_cache_render_status_rows() {
    $object = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
    $redis = function_exists('wp_cache_get') && defined('WP_REDIS_VERSION');
    $opcache = function_exists('opcache_get_status') && ini_get('opcache.enable');
    $page_stats = tk_page_cache_stats();
    $server_cache = tk_server_cache_status();
    $fragment_keys = tk_get_option('fragment_cache_keys', array());
    $fragment_count = is_array($fragment_keys) ? count($fragment_keys) : 0;
    ?>
    <table class="widefat striped tk-table">
        <thead><tr><th>Cache</th><th>Status</th><th>Detail</th></tr></thead>
        <tbody>
            <tr>
                <td>Page cache</td>
                <td><?php echo tk_get_option('page_cache_enabled', 0) ? '<span class="tk-badge tk-on">ON</span>' : '<span class="tk-badge">OFF</span>'; ?></td>
                <td>File-based HTML cache for anonymous static GET requests. Dynamic URLs, sessions, carts, checkout, account pages, search, and private/no-cache responses are skipped. Current usage: <?php echo esc_html(tk_page_cache_summary_text($page_stats)); ?>.</td>
            </tr>
            <tr>
                <td>Object cache</td>
                <td><?php echo $object ? '<span class="tk-badge tk-on">PERSISTENT</span>' : '<span class="tk-badge">DEFAULT</span>'; ?></td>
                <td><?php echo $object ? 'External object cache enabled.' : 'Using default non-persistent cache.'; ?></td>
            </tr>
            <tr>
                <td>Redis</td>
                <td><?php echo $redis ? '<span class="tk-badge tk-on">ENABLED</span>' : '<span class="tk-badge">OFF</span>'; ?></td>
                <td><?php echo $redis ? 'Redis object cache detected.' : 'Redis not detected.'; ?></td>
            </tr>
            <tr>
                <td>Opcode cache</td>
                <td><?php echo $opcache ? '<span class="tk-badge">AVAILABLE</span>' : '<span class="tk-badge">OFF</span>'; ?></td>
                <td><?php echo $opcache ? 'PHP OPcache is available at server level. Tool Kits does not use it for page caching.' : 'OPcache not enabled.'; ?></td>
            </tr>
            <tr>
                <td>Server/CDN cache</td>
                <td><?php echo !empty($server_cache['detected']) ? '<span class="tk-badge tk-on">DETECTED</span>' : '<span class="tk-badge">NONE</span>'; ?></td>
                <td><?php echo esc_html((string) $server_cache['detail']); ?></td>
            </tr>
            <tr>
                <td>Fragment cache</td>
                <td><?php echo $fragment_count > 0 ? '<span class="tk-badge">STORED</span>' : '<span class="tk-badge">IDLE</span>'; ?></td>
                <td>No automatic fragment caching is active. Helper functions only store fragments when Page Cache is enabled and called by code. Stored fragments: <?php echo esc_html((string) $fragment_count); ?>.</td>
            </tr>
        </tbody>
    </table>
    <?php
}

function tk_cache_render_debug_headers(array $server_cache) {
    $headers = isset($server_cache['headers']) && is_array($server_cache['headers']) ? $server_cache['headers'] : array();
    $interesting = array(
        'cf-cache-status',
        'x-cache',
        'x-cache-status',
        'x-proxy-cache',
        'x-nginx-cache',
        'x-fastcgi-cache',
        'x-litespeed-cache',
        'x-litespeed-cache-control',
        'x-varnish',
        'x-cache-hits',
        'age',
        'cache-control',
        'server',
        'server-timing',
        'x-tool-kits-cache',
    );
    ?>
    <div style="margin-top:20px; padding-top:18px; border-top:1px solid var(--tk-border-soft);">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px;">
            <h3 style="margin:0;">Server Cache Debug</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                <?php tk_nonce_field('tk_cache_refresh_detection'); ?>
                <input type="hidden" name="action" value="tk_cache_refresh_detection">
                <button class="button button-secondary">Refresh detection</button>
            </form>
        </div>
        <p class="description">Homepage response headers used to detect reverse proxy, CDN, and server cache layers. Result is cached for 5 minutes.</p>
        <table class="widefat striped tk-table" style="margin-top:12px;">
            <thead><tr><th>Header</th><th>Value</th></tr></thead>
            <tbody>
                <?php foreach ($interesting as $header) : ?>
                    <?php if (!isset($headers[$header]) || $headers[$header] === '') continue; ?>
                    <tr>
                        <td><?php echo esc_html($header); ?></td>
                        <td><code><?php echo esc_html((string) $headers[$header]); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($headers)) : ?>
                    <tr><td colspan="2">No headers captured yet. Click Refresh detection.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function tk_render_cache_page() {
    if (!tk_is_admin_user()) return;
    $updated = isset($_GET['tk_updated']) ? sanitize_key($_GET['tk_updated']) : '';
    $purged = tk_cache_consume_purged_notice();
    $object = isset($_GET['tk_object']) ? sanitize_key($_GET['tk_object']) : '';
    $opcache = isset($_GET['tk_opcache']) ? sanitize_key($_GET['tk_opcache']) : '';
    $fragment = isset($_GET['tk_fragment']) ? sanitize_key($_GET['tk_fragment']) : '';
    $preload = isset($_GET['tk_preload']) ? sanitize_key($_GET['tk_preload']) : '';
    $detection = isset($_GET['tk_detection']) ? sanitize_key($_GET['tk_detection']) : '';
    $preload_notice = get_transient('tk_cache_preload_notice');
    if ($preload_notice !== false) {
        delete_transient('tk_cache_preload_notice');
    }
    $detection_notice = get_transient('tk_cache_detection_notice');
    if ($detection_notice !== false) {
        delete_transient('tk_cache_detection_notice');
    }
    $page_stats = tk_page_cache_stats();
    $server_cache = tk_server_cache_status();
    ?>
    <div class="wrap tk-wrap">
        <?php tk_render_header_branding(); ?>
        <?php tk_render_page_hero(__('Performance Cache', 'tool-kits'), __('Boost your site speed with advanced page, object, and opcode caching.', 'tool-kits'), 'dashicons-performance'); ?>
        <?php if ($updated === '1') : ?>
            <?php tk_notice('Cache settings saved.', 'success'); ?>
        <?php endif; ?>
        <?php if ($purged) : ?>
            <?php tk_notice($purged, 'success'); ?>
        <?php endif; ?>
        <?php if ($object === 'ok') : ?>
            <?php tk_notice('Object cache flushed.', 'success'); ?>
        <?php elseif ($object === 'fail') : ?>
            <?php tk_notice('Object cache flush failed.', 'error'); ?>
        <?php endif; ?>
        <?php if ($opcache === 'ok') : ?>
            <?php tk_notice('Opcode cache reset.', 'success'); ?>
        <?php elseif ($opcache === 'fail') : ?>
            <?php tk_notice('Opcode cache reset failed or unavailable.', 'error'); ?>
        <?php endif; ?>
        <?php if ($fragment === 'ok') : ?>
            <?php tk_notice('Fragment cache cleared.', 'success'); ?>
        <?php endif; ?>
        <?php if ($preload !== '' && is_string($preload_notice) && $preload_notice !== '') : ?>
            <?php tk_notice($preload_notice, $preload === 'ok' ? 'success' : 'warning'); ?>
        <?php endif; ?>
        <?php if ($detection !== '' && is_string($detection_notice) && $detection_notice !== '') : ?>
            <?php tk_notice($detection_notice, !empty($server_cache['detected']) ? 'success' : 'info'); ?>
        <?php endif; ?>

        <div class="tk-tabs">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button is-active" data-panel="status">Status</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="page">Page Cache</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="object">Object Cache</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="opcode">Opcode Cache</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="fragment">Fragment Cache</button>
            </div>
            <div class="tk-tabs-content">
                <div class="tk-card tk-tab-panel is-active" data-panel-id="status">
                    <h2>Cache Status</h2>
                    <?php tk_cache_render_status_rows(); ?>
                    <?php tk_cache_render_debug_headers($server_cache); ?>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="page">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                        <div>
                            <h2 style="margin:0;">Page Cache</h2>
                            <p class="description">Static HTML caching for near-instant page loads.</p>
                        </div>
                        <div style="text-align:right;">
                            <span class="tk-badge tk-on" style="font-size:12px; padding:6px 12px;"><?php echo esc_html(tk_page_cache_summary_text($page_stats)); ?></span>
                        </div>
                    </div>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_cache_save'); ?>
                        <input type="hidden" name="action" value="tk_cache_save">
                        
                        <div style="display:flex; flex-direction:column; gap:20px;">
                            <?php tk_render_switch('page_cache_enabled', 'Enable Page Caching', 'Serve pre-rendered HTML files to anonymous visitors.', tk_get_option('page_cache_enabled', 0)); ?>
                            <?php tk_render_switch('page_cache_auto_preload_after_purge', 'Auto-preload After Purge', 'Warm homepage and critical URLs immediately after page cache is cleared.', tk_get_option('page_cache_auto_preload_after_purge', 0)); ?>
                            
                            <div class="tk-control-row">
                                <div class="tk-control-info">
                                    <label>Cache TTL (Seconds)</label>
                                    <p class="description">Server-side HTML lifetime before regenerating. 3600 = 1 hour. Browsers revalidate HTML on every visit.</p>
                                </div>
                                <input type="number" name="page_cache_ttl" value="<?php echo esc_attr((string) tk_get_option('page_cache_ttl', 3600)); ?>" min="0" style="width:120px;">
                            </div>

                            <div style="padding:20px; background:var(--tk-bg-soft); border-radius:12px; border:1px solid var(--tk-border-soft);">
                                <label style="display:block; font-weight:600; margin-bottom:8px;">Exclude Paths</label>
                                <textarea name="page_cache_exclude_paths" rows="3" class="large-text" style="width:100%; border-radius:8px;"><?php echo esc_textarea((string) tk_get_option('page_cache_exclude_paths', "/wp-login.php\n/wp-admin\n")); ?></textarea>
                            </div>
                        </div>

                        <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft); display:flex; gap:10px;">
                            <button class="button button-primary button-hero">Save Cache Settings</button>
                        </div>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-top:12px; margin-right:8px;">
                        <?php tk_nonce_field('tk_cache_purge'); ?>
                        <input type="hidden" name="action" value="tk_cache_purge">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url(tk_admin_url('tool-kits-cache') . '#page'); ?>">
                        <button class="button button-secondary">Purge page cache</button>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-top:12px;">
                        <?php tk_nonce_field('tk_cache_preload'); ?>
                        <input type="hidden" name="action" value="tk_cache_preload">
                        <button class="button button-secondary">Preload critical pages</button>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="object">
                    <h2>Object Cache (query DB)</h2>
                    <p>Flushes the object cache pool if available.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_cache_object_flush'); ?>
                        <input type="hidden" name="action" value="tk_cache_object_flush">
                        <button class="button button-secondary">Flush object cache</button>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="opcode">
                    <h2>Opcode Cache (PHP)</h2>
                    <p>Reset OPcache when available.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_cache_opcache_reset'); ?>
                        <input type="hidden" name="action" value="tk_cache_opcache_reset">
                        <button class="button button-secondary">Reset opcode cache</button>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="fragment">
                    <h2>Fragment Cache</h2>
                    <p>Use helpers for static template fragments. When Page Cache is disabled, these helpers read as empty and do not create cache entries.</p>
                    <pre>if (($block = tk_fragment_cache_get('home:hero')) === false) {
    ob_start();
    // render block
    $block = ob_get_clean();
    tk_fragment_cache_set('home:hero', $block, 300);
}
echo $block;</pre>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_fragment_cache_flush'); ?>
                        <input type="hidden" name="action" value="tk_fragment_cache_flush">
                        <button class="button button-secondary">Clear fragment cache</button>
                    </form>
                </div>
            </div>
        </div>
        <?php
        tk_csp_print_inline_script(
            "(function(){
                function activateTab(panelId) {
                    document.querySelectorAll('.tk-tab-panel').forEach(function(panel){
                        panel.classList.toggle('is-active', panel.getAttribute('data-panel-id') === panelId);
                    });
                    document.querySelectorAll('.tk-tabs-nav-button').forEach(function(btn){
                        btn.classList.toggle('is-active', btn.getAttribute('data-panel') === panelId);
                    });
                }
                function getPanelFromHash() {
                    var hash = window.location.hash || '';
                    if (!hash) { return ''; }
                    return hash.replace('#', '');
                }
                document.querySelectorAll('.tk-tabs-nav-button').forEach(function(button){
                    button.addEventListener('click', function(){
                        var panelId = button.getAttribute('data-panel');
                        if (panelId) {
                            window.location.hash = panelId;
                            activateTab(panelId);
                        }
                    });
                });
                var initial = getPanelFromHash();
                if (initial) {
                    activateTab(initial);
                }
            })();",
            array('id' => 'tk-cache-tabs')
        );
        ?>
    </div>
    <?php
}
