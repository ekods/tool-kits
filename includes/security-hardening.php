<?php
if (!defined('ABSPATH')) exit;

/**
 * Basic WP hardening
 */

if (!function_exists('tk_csp_add_nonce_to_tag')) {
    function tk_csp_add_nonce_to_tag(string $tag, string $element = 'script'): string {
        if ($tag === '') {
            return $tag;
        }
        if (!function_exists('tk_csp_nonce_enabled') || !tk_csp_nonce_enabled()) {
            return $tag;
        }
        if (stripos($tag, '<' . $element) === false || stripos($tag, ' nonce=') !== false) {
            return $tag;
        }
        $nonce_attr = function_exists('tk_csp_nonce_attr') ? tk_csp_nonce_attr() : '';
        if ($nonce_attr === '') {
            return $tag;
        }
        return preg_replace('/<' . preg_quote($element, '/') . '\b/i', '<' . $element . $nonce_attr, $tag, 1) ?: $tag;
    }
}

function tk_hardening_init() {
    add_filter('cron_schedules', 'tk_hardening_waf_cron_schedules');
    tk_hardening_apply_recommended_defaults();
    add_filter('auto_update_core', 'tk_hardening_core_auto_updates', 10, 2);
    add_filter('allow_minor_auto_core_updates', 'tk_hardening_core_auto_updates');
    add_filter('allow_major_auto_core_updates', 'tk_hardening_core_auto_updates');
    if (tk_get_option('hardening_disable_xmlrpc', 1)) {
        add_filter('xmlrpc_enabled', '__return_false');
        add_action('init', 'tk_hardening_block_xmlrpc_request', 0);
    }
    if (tk_get_option('hardening_block_plugin_installs', 1)) {
        add_filter('user_has_cap', 'tk_hardening_block_plugin_caps', 10, 4);
        add_filter('rest_pre_dispatch', 'tk_hardening_block_plugin_rest', 10, 3);
    }
    if (tk_get_option('hardening_httpauth_enabled', 0)) {
        add_action('init', 'tk_hardening_http_auth', 0);
    }
    if (tk_get_option('hardening_disable_wp_cron', 0)) {
        add_action('plugins_loaded', 'tk_hardening_disable_wp_cron_runtime', 0);
    }
    if (tk_get_option('hardening_disable_comments', 0)) {
        add_action('init', 'tk_hardening_disable_comments');
    }
    if (tk_get_option('hardening_block_uploads_php', 1)) {
        add_action('init', 'tk_hardening_block_uploads_php', 1);
    }
    if (tk_get_option('hardening_server_aware_enabled', 1)) {
        add_action('init', 'tk_hardening_block_public_wp_cron_request', 0);
        add_action('init', 'tk_hardening_apply_root_server_rules', 1);
    }
    if (tk_get_option('hardening_xmlrpc_block_methods', 1)) {
        add_filter('xmlrpc_methods', 'tk_xmlrpc_block_methods');
    }
    if (tk_get_option('hardening_xmlrpc_rate_limit_enabled', 0)) {
        add_action('xmlrpc_call', 'tk_xmlrpc_rate_limit', 0, 1);
    }
    if (tk_get_option('hardening_disable_rest_user_enum', 1)) {
        add_filter('rest_endpoints', 'tk_disable_user_enum');
    }
    if (tk_get_option('hardening_disable_pingbacks', 1)) {
        add_filter('xmlrpc_methods', 'tk_disable_pingbacks');
    }
    if (tk_get_option('hardening_security_headers', 1)) {
        add_action('send_headers', 'tk_security_headers');
        add_action('send_headers', 'tk_hardening_cors_headers');
        add_filter('script_loader_tag', 'tk_csp_script_loader_tag', 999, 3);
        add_filter('style_loader_tag', 'tk_csp_style_loader_tag', 999, 4);
        add_action('template_redirect', 'tk_csp_start_buffer', 999);
    }
    if (tk_get_option('hardening_server_signature_hide', 1)) {
        add_action('send_headers', 'tk_hardening_hide_server_headers', 999);
    }
    if (tk_get_option('hardening_cookie_httponly_force', 0)) {
        add_action('init', 'tk_hardening_cookie_httponly_ini', 0);
        add_action('send_headers', 'tk_hardening_force_cookie_httponly', 999);
    }
    if (tk_get_option('hardening_url_param_guard_enabled', 0)) {
        add_action('init', 'tk_hardening_url_param_guard', 1);
    }
    if (tk_get_option('hardening_block_dangerous_methods_enabled', 1)) {
        add_action('init', 'tk_hardening_block_dangerous_methods', 0);
    }
    if (tk_get_option('hardening_http_methods_filter_enabled', 0)) {
        add_action('init', 'tk_hardening_http_methods_filter', 0);
    }
    if (tk_get_option('hardening_robots_txt_hardened', 0)) {
        add_filter('robots_txt', 'tk_hardening_robots_txt_content', 99, 2);
    }
    if (tk_get_option('hardening_block_unwanted_files_enabled', 1)) {
        add_action('template_redirect', 'tk_hardening_block_unwanted_files', 0);
    }
    if (tk_get_option('hardening_disable_file_editor', 1)) {
        add_action('init', 'tk_define_disallow_file_edit');
        add_filter('user_has_cap', 'tk_disable_file_editor_caps', 10, 4);
    }
    if (tk_get_option('hardening_hide_wp_version', 1)) {
        add_filter('the_generator', '__return_empty_string');
        add_action('init', 'tk_hardening_remove_version_strings');
    }
    if (tk_get_option('hardening_clean_wp_head', 0)) {
        add_action('init', 'tk_hardening_clean_wp_head');
    }
    if (tk_get_option('hardening_waf_enabled', 0)) {
        add_action('init', 'tk_hardening_waf', 1);
    }
    add_action('tk_hardening_waf_cleanup', 'tk_hardening_waf_cleanup_cron');
    add_action('admin_post_tk_hardening_save', 'tk_hardening_save');
    add_action('admin_post_tk_hardening_waf_reset', 'tk_hardening_waf_reset');
    if (tk_get_option('hardening_waf_log_to_file', 0)) {
        tk_hardening_waf_schedule_cleanup();
    }
}

function tk_hardening_remove_version_strings() {
    add_filter('script_loader_src', 'tk_remove_wp_ver_css_js', 9999);
    add_filter('style_loader_src', 'tk_remove_wp_ver_css_js', 9999);
}

function tk_remove_wp_ver_css_js($src) {
    if (strpos($src, 'ver=' . get_bloginfo('version'))) {
        $src = remove_query_arg('ver', $src);
    }
    return $src;
}

function tk_hardening_clean_wp_head() {
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wlwmanifest_link');
    remove_action('wp_head', 'wp_generator');
    remove_action('wp_head', 'wp_shortlink_wp_head');
    remove_action('wp_head', 'rest_output_link_wp_head', 10);
    remove_action('wp_head', 'wp_oembed_add_discovery_links', 10);
    remove_action('wp_head', 'wp_oembed_add_host_js');
    remove_action('template_redirect', 'rest_output_link_header', 11);
}

function tk_disable_user_enum($endpoints) {
    unset($endpoints['/wp/v2/users']);
    unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
    return $endpoints;
}

function tk_security_headers() {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin');
    header('Cross-Origin-Resource-Policy: cross-origin');
    $nonce = tk_csp_nonce();
    $nonce_token = $nonce !== '' ? " 'nonce-" . $nonce . "'" : '';
    if (tk_get_option('hardening_csp_strict_enabled', 0)) {
        header('Content-Security-Policy: ' . tk_hardening_build_csp_header(array(
            "default-src 'self'",
            "img-src 'self' data: https:" . tk_hardening_csp_custom_sources('img'),
            "font-src 'self' data: https:",
            "script-src 'self'" . $nonce_token . tk_hardening_csp_google_sources('script') . tk_hardening_csp_custom_sources('script'),
            "style-src 'self'" . $nonce_token . tk_hardening_csp_custom_sources('style'),
            "style-src-attr 'unsafe-inline'",
            "connect-src 'self' https:" . tk_hardening_csp_google_sources('connect') . tk_hardening_csp_custom_sources('connect'),
            "frame-src 'self' https:" . tk_hardening_csp_google_sources('frame') . tk_hardening_csp_custom_sources('frame'),
            "child-src 'self' https:" . tk_hardening_csp_google_sources('frame') . tk_hardening_csp_custom_sources('frame'),
            "object-src 'none'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            'upgrade-insecure-requests',
        )));
    } elseif (tk_get_option('hardening_csp_hardened_enabled', 0)) {
        header('Content-Security-Policy: ' . tk_hardening_build_csp_header(array(
            "default-src 'self'",
            "img-src 'self' data: blob: https:" . tk_hardening_csp_custom_sources('img'),
            "font-src 'self' data: https:",
            "script-src 'self'" . $nonce_token . tk_hardening_csp_google_sources('script') . tk_hardening_csp_custom_sources('script'),
            "style-src 'self' 'unsafe-inline'" . $nonce_token . tk_hardening_csp_custom_sources('style'),
            "style-src-attr 'unsafe-inline'",
            "connect-src 'self' https:" . tk_hardening_csp_google_sources('connect') . tk_hardening_csp_custom_sources('connect'),
            "worker-src 'self' blob:",
            "frame-src 'self' https:" . tk_hardening_csp_google_sources('frame') . tk_hardening_csp_custom_sources('frame'),
            "child-src 'self' https:" . tk_hardening_csp_google_sources('frame') . tk_hardening_csp_custom_sources('frame'),
            "object-src 'none'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            'upgrade-insecure-requests',
        )));
    } elseif (tk_get_option('hardening_csp_balanced_enabled', 0)) {
        header('Content-Security-Policy: ' . tk_hardening_build_csp_header(array(
            "default-src 'self'",
            "img-src 'self' data: blob: https:" . tk_hardening_csp_custom_sources('img'),
            "font-src 'self' data: https:",
            "script-src 'self' 'unsafe-inline'" . $nonce_token . tk_hardening_csp_google_sources('script') . tk_hardening_csp_custom_sources('script'),
            "style-src 'self' 'unsafe-inline'" . $nonce_token . tk_hardening_csp_custom_sources('style'),
            "connect-src 'self' https:" . tk_hardening_csp_google_sources('connect') . tk_hardening_csp_custom_sources('connect'),
            "worker-src 'self' blob:",
            "frame-src 'self' https:" . tk_hardening_csp_google_sources('frame') . tk_hardening_csp_custom_sources('frame'),
            "child-src 'self' https:" . tk_hardening_csp_google_sources('frame') . tk_hardening_csp_custom_sources('frame'),
            "object-src 'none'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            'upgrade-insecure-requests',
        )));
    } elseif (tk_get_option('hardening_csp_lite_enabled', 0)) {
        header('Content-Security-Policy: ' . tk_hardening_build_csp_header(array(
            "default-src 'self'",
            "img-src 'self' data: blob: https:" . tk_hardening_csp_custom_sources('img'),
            "font-src 'self' data: https:",
            "script-src 'self' 'unsafe-inline' https:" . $nonce_token . tk_hardening_csp_google_sources('script') . tk_hardening_csp_custom_sources('script'),
            "style-src 'self' 'unsafe-inline' https:" . $nonce_token . tk_hardening_csp_custom_sources('style'),
            "connect-src 'self' https:" . tk_hardening_csp_google_sources('connect') . tk_hardening_csp_custom_sources('connect'),
            "worker-src 'self' blob:",
            "frame-src 'self' https:" . tk_hardening_csp_google_sources('frame') . tk_hardening_csp_custom_sources('frame'),
            "child-src 'self' https:" . tk_hardening_csp_google_sources('frame') . tk_hardening_csp_custom_sources('frame'),
            "object-src 'none'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            'upgrade-insecure-requests',
        )));
    }
    if (tk_get_option('hardening_hsts_enabled', 0) && is_ssl()) {
        $hsts = 'max-age=31536000; includeSubDomains';
        if (tk_get_option('hardening_hsts_preload', 0)) {
            $hsts .= '; preload';
        }
        header('Strict-Transport-Security: ' . $hsts);
    }
}

function tk_hardening_csp_google_sources($directive = '') {
    $sources = array(
        'script' => array(
            'https://www.googletagmanager.com',
            'https://www.google-analytics.com',
            'https://www.gstatic.com',
            'https://www.google.com',
            'https://recaptcha.google.com',
            'https://*.google.com',
        ),
        'connect' => array(
            'https://www.google-analytics.com',
            'https://analytics.google.com',
            'https://stats.g.doubleclick.net',
            'https://www.googletagmanager.com',
            'https://www.google.com',
            'https://recaptcha.google.com',
            'https://*.google.com',
        ),
        'frame' => array(
            'https://www.google.com',
            'https://recaptcha.google.com',
            'https://*.google.com',
            'https://*.google.co.id',
        ),
        'img' => array(
            'https://www.google-analytics.com',
            'https://www.googletagmanager.com',
            'https://*.google.com',
            'https://*.gstatic.com',
            'data:',
        ),
    );

    $directive = is_string($directive) ? strtolower($directive) : '';
    if (!isset($sources[$directive])) {
        return '';
    }

    return ' ' . implode(' ', $sources[$directive]);
}

function tk_hardening_csp_option_key($directive = '') {
    $map = array(
        'script' => 'hardening_csp_script_sources',
        'style' => 'hardening_csp_style_sources',
        'connect' => 'hardening_csp_connect_sources',
        'frame' => 'hardening_csp_frame_sources',
        'img' => 'hardening_csp_img_sources',
    );
    $directive = is_string($directive) ? strtolower($directive) : '';
    return isset($map[$directive]) ? $map[$directive] : '';
}

function tk_hardening_sanitize_csp_sources($value): string {
    $value = is_string($value) ? $value : '';
    if ($value === '') {
        return '';
    }

    $lines = preg_split('/\r\n|\r|\n/', $value);
    if (!is_array($lines)) {
        return '';
    }

    $allowed = array();
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        $line = preg_replace('/\s+/', '', $line);
        if (!is_string($line) || $line === '' || strpos($line, ';') !== false) {
            continue;
        }
        if (!preg_match('/^(\'self\'|\'unsafe-inline\'|\'unsafe-eval\'|\'none\'|\'strict-dynamic\'|\'report-sample\'|\'unsafe-hashes\'|https?:\/\/[^\s]+|wss?:\/\/[^\s]+|blob:|data:|mediastream:|filesystem:|[a-z][a-z0-9+.-]*:|\*\.[a-z0-9.-]+|[a-z0-9.-]+\.[a-z]{2,})$/i', $line)) {
            continue;
        }
        $allowed[] = $line;
    }

    if (empty($allowed)) {
        return '';
    }

    $allowed = array_values(array_unique($allowed));
    return implode("\n", $allowed);
}

function tk_hardening_csp_custom_sources($directive = '') {
    $option_key = tk_hardening_csp_option_key($directive);
    if ($option_key === '') {
        return '';
    }

    $raw = tk_get_option($option_key, '');
    if (!is_string($raw) || $raw === '') {
        return '';
    }

    $items = preg_split('/\r\n|\r|\n/', $raw);
    if (!is_array($items)) {
        return '';
    }

    $items = array_values(array_filter(array_map('trim', $items)));
    if (empty($items)) {
        return '';
    }

    return ' ' . implode(' ', $items);
}

function tk_hardening_build_csp_header($directives) {
    if (!is_array($directives)) {
        return '';
    }

    $directives = array_values(array_filter(array_map('trim', $directives)));
    return implode('; ', $directives);
}

function tk_csp_script_loader_tag($tag, $handle, $src) {
    if (!is_string($tag)) {
        return $tag;
    }
    return tk_csp_add_nonce_to_tag($tag, 'script');
}

function tk_csp_style_loader_tag($tag, $handle, $href, $media) {
    if (!is_string($tag)) {
        return $tag;
    }
    return tk_csp_add_nonce_to_tag($tag, 'style');
}

function tk_csp_start_buffer(): void {
    if (is_admin() || wp_doing_ajax() || is_feed() || is_preview()) {
        return;
    }
    if (!tk_csp_nonce_enabled()) {
        return;
    }
    ob_start('tk_csp_buffer_callback');
}

function tk_csp_buffer_callback($html) {
    if (!is_string($html) || $html === '' || stripos($html, '<html') === false) {
        return $html;
    }

    $html = preg_replace_callback(
        '#<script\b([^>]*)>(.*?)</script>#is',
        function($matches) {
            return tk_csp_add_nonce_to_tag($matches[0], 'script');
        },
        $html
    );

    $html = preg_replace_callback(
        '#<style\b([^>]*)>(.*?)</style>#is',
        function($matches) {
            return tk_csp_add_nonce_to_tag($matches[0], 'style');
        },
        $html
    );

    return $html;
}

function tk_hardening_disable_wp_cron_runtime(): void {
    if (!defined('DISABLE_WP_CRON')) {
        define('DISABLE_WP_CRON', true);
    }
}

function tk_hardening_request_path(): string {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($request_uri === '') {
        return '';
    }
    $path = wp_parse_url($request_uri, PHP_URL_PATH);
    return is_string($path) ? $path : '';
}

function tk_hardening_is_local_request(): bool {
    $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
    if ($remote_addr === '') {
        return false;
    }
    if ($remote_addr === '127.0.0.1' || $remote_addr === '::1') {
        return true;
    }
    $server_addr = isset($_SERVER['SERVER_ADDR']) ? trim((string) $_SERVER['SERVER_ADDR']) : '';
    if ($server_addr !== '' && $remote_addr === $server_addr) {
        return true;
    }
    return filter_var($remote_addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

function tk_hardening_block_xmlrpc_request(): void {
    $path = tk_hardening_request_path();
    if ($path === '' || substr($path, -11) !== '/xmlrpc.php') {
        return;
    }
    status_header(403);
    nocache_headers();
    wp_die('XML-RPC disabled.', 'Forbidden', array('response' => 403));
}

function tk_hardening_block_public_wp_cron_request(): void {
    $path = tk_hardening_request_path();
    if ($path === '' || substr($path, -12) !== '/wp-cron.php') {
        return;
    }
    if (tk_hardening_is_local_request()) {
        return;
    }
    status_header(403);
    nocache_headers();
    wp_die('Public WP-Cron access disabled.', 'Forbidden', array('response' => 403));
}

function tk_hardening_cookie_httponly_ini(): void {
    @ini_set('session.cookie_httponly', '1');
    if (is_ssl()) {
        @ini_set('session.cookie_secure', '1');
    }
}

function tk_hardening_hide_server_headers(): void {
    if (headers_sent()) {
        return;
    }
    @ini_set('expose_php', '0');
    if (function_exists('header_remove')) {
        @header_remove('X-Powered-By');
    }
}

function tk_hardening_force_cookie_httponly(): void {
    if (headers_sent() || !function_exists('headers_list') || !function_exists('header_remove')) {
        return;
    }
    $headers = headers_list();
    if (!is_array($headers) || empty($headers)) {
        return;
    }
    $cookies = array();
    foreach ($headers as $header_line) {
        if (stripos($header_line, 'Set-Cookie:') !== 0) {
            continue;
        }
        $cookie = trim(substr($header_line, strlen('Set-Cookie:')));
        if ($cookie === '') {
            continue;
        }
        if (stripos($cookie, '; httponly') === false) {
            $cookie .= '; HttpOnly';
        }
        if (is_ssl() && stripos($cookie, '; secure') === false) {
            $cookie .= '; Secure';
        }
        $cookies[] = $cookie;
    }
    if (empty($cookies)) {
        return;
    }
    @header_remove('Set-Cookie');
    foreach ($cookies as $cookie_line) {
        header('Set-Cookie: ' . $cookie_line, false);
    }
}

function tk_hardening_url_param_guard(): void {
    $doing_ajax = function_exists('wp_doing_ajax') ? wp_doing_ajax() : false;
    if (is_admin() && !$doing_ajax) {
        return;
    }
    $query = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
    if ($query === '') {
        return;
    }
    if (strlen($query) > 2000) {
        wp_die('Blocked malformed query string.', 'Forbidden', array('response' => 403));
    }
    if (preg_match('/%(?![0-9a-fA-F]{2})/', $query) === 1) {
        wp_die('Blocked malformed query encoding.', 'Forbidden', array('response' => 403));
    }
    $decoded = rawurldecode($query);
    $patterns = array(
        '/<\s*script\b/i',
        '/\.\.\//',
        '/(?:\bunion\b|\bselect\b|\binsert\b|\bupdate\b|\bdrop\b)\s+/i',
        '/\b(?:sleep|benchmark)\s*\(/i',
        '/[\x00-\x08\x0B\x0C\x0E-\x1F]/',
    );
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $decoded) === 1) {
            wp_die('Blocked suspicious URL parameter.', 'Forbidden', array('response' => 403));
        }
    }
}

function tk_hardening_http_methods_filter(): void {
    if (is_admin()) {
        return;
    }
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
    if ($method === '') {
        return;
    }
    $allowed_raw = tk_get_option('hardening_http_methods_allowed', 'GET, POST');
    $allowed = is_string($allowed_raw) ? array_map('trim', explode(',', $allowed_raw)) : array('GET', 'POST');
    $allowed = array_filter(array_map('strtoupper', $allowed));
    if (empty($allowed)) {
        $allowed = array('GET', 'POST');
    }
    if (in_array($method, $allowed, true)) {
        return;
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $allow_paths = tk_get_option('hardening_http_methods_allow_paths', '');
    if (is_string($allow_paths) && $allow_paths !== '') {
        $lines = preg_split('/\r\n|\r|\n/', $allow_paths);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (stripos($request_uri, $line) !== false) {
                return;
            }
        }
    }

    status_header(405);
    header('Allow: ' . implode(', ', $allowed));
    wp_die('HTTP method not allowed.', 'Method Not Allowed', array('response' => 405));
}

function tk_hardening_block_dangerous_methods(): void {
    if (is_admin()) {
        return;
    }
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
    if ($method === '') {
        return;
    }
    $blocked_raw = tk_get_option('hardening_dangerous_http_methods', 'PUT, DELETE, TRACE, CONNECT');
    $blocked = is_string($blocked_raw) ? array_map('trim', explode(',', $blocked_raw)) : array('PUT', 'DELETE', 'TRACE', 'CONNECT');
    $blocked = array_filter(array_map('strtoupper', $blocked));
    if (empty($blocked) || !in_array($method, $blocked, true)) {
        return;
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $allow_paths = tk_get_option('hardening_dangerous_methods_allow_paths', '');
    if (is_string($allow_paths) && $allow_paths !== '') {
        $lines = preg_split('/\r\n|\r|\n/', $allow_paths);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (stripos($request_uri, $line) !== false) {
                return;
            }
        }
    }

    status_header(405);
    header('Allow: GET, POST');
    wp_die('Dangerous HTTP method blocked.', 'Method Not Allowed', array('response' => 405));
}

function tk_hardening_db_host_value(): string {
    if (!defined('DB_HOST')) {
        return '';
    }
    return trim((string) DB_HOST);
}

function tk_hardening_db_host_parse(string $value): array {
    $host = $value;
    $port = 3306;

    if ($host === '') {
        return array('host' => '', 'port' => $port);
    }
    if (strpos($host, ':') !== false && strpos($host, ']') === false && substr_count($host, ':') === 1) {
        $parts = explode(':', $host, 2);
        $host = trim($parts[0]);
        $port = is_numeric($parts[1]) ? (int) $parts[1] : 3306;
    } elseif (strpos($host, '[') === 0 && strpos($host, ']') !== false) {
        $end = strpos($host, ']');
        $host_only = substr($host, 1, $end - 1);
        $rest = substr($host, $end + 1);
        $host = $host_only !== false ? $host_only : $host;
        if (strpos($rest, ':') === 0) {
            $candidate = substr($rest, 1);
            if (is_numeric($candidate)) {
                $port = (int) $candidate;
            }
        }
    }

    if (strpos($host, '/') !== false) {
        $host = 'localhost';
    }
    if ($port <= 0 || $port > 65535) {
        $port = 3306;
    }
    return array('host' => strtolower(trim($host)), 'port' => $port);
}

function tk_hardening_is_private_ip(string $ip): bool {
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

function tk_hardening_robots_txt_content(string $output, bool $public): string {
    if (!$public) {
        return $output;
    }
    $lines = array(
        'User-agent: *',
        'Disallow: /wp-admin/',
        'Allow: /wp-admin/admin-ajax.php',
    );
    $sitemap = '';
    if (trim((string) get_option('blog_public')) === '1' && function_exists('wp_sitemaps_get_server')) {
        $server = wp_sitemaps_get_server();
        if (is_object($server) && method_exists($server, 'get_index_url')) {
            $sitemap = trim((string) $server->get_index_url());
        }
    }
    if ($sitemap !== '') {
        $lines[] = 'Sitemap: ' . esc_url_raw($sitemap);
    }
    return implode("\n", $lines) . "\n";
}

function tk_hardening_unwanted_file_names(): array {
    $raw = tk_get_option('hardening_unwanted_file_names', '.ds_store, thumbs.db, phpinfo.php, error_log, debug.log');
    $lines = is_string($raw) ? preg_split('/[\s,]+/', strtolower($raw)) : array();
    $names = array();
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
            $names[] = $line;
        }
    }
    if (empty($names)) {
        $names = array('.ds_store', 'thumbs.db', 'phpinfo.php', 'error_log', 'debug.log');
    }
    return array_values(array_unique($names));
}

function tk_hardening_block_unwanted_files(): void {
    if (is_admin()) {
        return;
    }
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($request_uri === '') {
        return;
    }
    $path = wp_parse_url($request_uri, PHP_URL_PATH);
    $basename = strtolower(basename((string) $path));
    if ($basename === '') {
        return;
    }
    $blocked = tk_hardening_unwanted_file_names();
    if (in_array($basename, $blocked, true)) {
        status_header(403);
        wp_die('Forbidden.', 'Forbidden', array('response' => 403));
    }
}

function tk_hardening_block_plugin_caps($allcaps, $caps, $args, $user) {
    if (!empty($allcaps['manage_options'])) {
        return $allcaps;
    }
    $deny = array(
        'install_plugins',
        'update_plugins',
        'delete_plugins',
        'activate_plugins',
        'edit_plugins',
        'install_themes',
        'update_themes',
        'delete_themes',
        'switch_themes',
        'edit_themes',
        'upload_themes',
        'customize',
    );
    foreach ($deny as $cap) {
        if (isset($allcaps[$cap])) {
            $allcaps[$cap] = false;
        }
    }
    return $allcaps;
}

function tk_hardening_block_plugin_rest($result, $server, $request) {
    if (current_user_can('manage_options')) {
        return $result;
    }
    if (!is_a($request, 'WP_REST_Request')) {
        return $result;
    }
    $route = $request->get_route();
    if (strpos($route, '/wp/v2/plugins') === 0 || strpos($route, '/wp/v2/themes') === 0 || strpos($route, '/wp/v2/plugin') === 0) {
        return new WP_Error('tk_forbidden', __('Plugin/theme management is disabled for non-admins.', 'tool-kits'), array('status' => 403));
    }
    return $result;
}

function tk_hardening_core_auto_updates($value) {
    $enabled = tk_get_option('hardening_core_auto_updates', 1) ? true : false;
    return $enabled;
}

function tk_hardening_wp_config_path(): string {
    $path = ABSPATH . 'wp-config.php';
    if (file_exists($path)) {
        return $path;
    }
    $parent = dirname(ABSPATH) . '/wp-config.php';
    if (file_exists($parent)) {
        return $parent;
    }
    return '';
}

function tk_hardening_set_wp_config_constant(string $name, bool $enabled): bool {
    $path = tk_hardening_wp_config_path();
    if ($path === '' || !file_exists($path) || !is_writable($path)) {
        return false;
    }
    $contents = @file_get_contents($path);
    if (!is_string($contents) || $contents === '') {
        return false;
    }

    $pattern = '/^[ \t]*define\s*\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]\s*,\s*(?:true|false|1|0)\s*\)\s*;\s*$/im';
    $replacement = "define('" . $name . "', " . ($enabled ? 'true' : 'false') . ");";
    if (preg_match($pattern, $contents) === 1) {
        $updated = preg_replace($pattern, $replacement, $contents, 1);
        if (!is_string($updated)) {
            return false;
        }
        return @file_put_contents($path, $updated) !== false;
    }

    if (!$enabled) {
        return true;
    }

    $anchor = "/* That's all, stop editing! Happy publishing. */";
    if (strpos($contents, $anchor) !== false) {
        $updated = str_replace($anchor, $replacement . "\n" . $anchor, $contents);
    } else {
        $updated = rtrim($contents) . "\n" . $replacement . "\n";
    }
    return @file_put_contents($path, $updated) !== false;
}

function tk_hardening_apply_recommended_defaults(): void {
    if (!tk_get_option('hardening_auto_toggle', 1)) {
        return;
    }
    if (tk_get_option('hardening_auto_applied', 0)) {
        $keys = array(
            'hardening_disable_file_editor',
            'hardening_disable_xmlrpc',
            'hardening_disable_rest_user_enum',
            'hardening_security_headers',
            'hardening_csp_lite_enabled',
            'hardening_server_aware_enabled',
            'hardening_block_uploads_php',
        );
        $any_enabled = false;
        foreach ($keys as $key) {
            if (tk_get_option($key, 0)) {
                $any_enabled = true;
                break;
            }
        }
        if ($any_enabled) {
            return;
        }
    }
    tk_update_option('hardening_disable_file_editor', 1);
    tk_update_option('hardening_disable_xmlrpc', 1);
    tk_update_option('hardening_xmlrpc_block_methods', 1);
    tk_update_option('hardening_disable_rest_user_enum', 1);
    tk_update_option('hardening_security_headers', 1);
    tk_update_option('hardening_csp_lite_enabled', 1);
    tk_update_option('hardening_hsts_enabled', 1);
    tk_update_option('hardening_server_signature_hide', 1);
    tk_update_option('hardening_server_aware_enabled', 1);
    tk_update_option('hardening_block_uploads_php', 1);
    tk_update_option('hardening_auto_applied', 1);
}

function tk_hardening_block_uploads_php(): void {
    $server = tk_hardening_detect_server();
    $uploads = wp_upload_dir();
    $uploads_path = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
    if ($uploads_path === '' || !is_dir($uploads_path)) {
        return;
    }

    if (in_array($server, array('apache', 'litespeed', 'openlitespeed'), true)) {
        $htaccess = trailingslashit($uploads_path) . '.htaccess';
        $block = "# Tool Kits: block uploads php\n<IfModule mod_rewrite.c>\n  RewriteEngine On\n  RewriteRule ^.*\\.php$ - [F]\n</IfModule>\n<IfModule mod_autoindex.c>\n  Options -Indexes\n</IfModule>\n";
        if (file_exists($htaccess)) {
            $contents = @file_get_contents($htaccess);
            if (is_string($contents) && strpos($contents, '# Tool Kits: block uploads php') !== false) {
                return;
            }
            @file_put_contents($htaccess, rtrim((string) $contents, "\r\n") . "\n\n" . $block);
            return;
        }
        @file_put_contents($htaccess, $block);
        return;
    }

    if ($server === 'iis') {
        $web_config = trailingslashit($uploads_path) . 'web.config';
        $block = "<configuration>\n  <system.webServer>\n    <handlers>\n      <add name=\"BlockPhp\" path=\"*.php\" verb=\"*\" modules=\"StaticFileModule\" resourceType=\"File\" requireAccess=\"Read\" />\n    </handlers>\n    <security>\n      <requestFiltering>\n        <fileExtensions>\n          <add fileExtension=\".php\" allowed=\"false\" />\n        </fileExtensions>\n      </requestFiltering>\n    </security>\n  </system.webServer>\n</configuration>\n";
        if (file_exists($web_config)) {
            $contents = @file_get_contents($web_config);
            if (is_string($contents) && strpos($contents, 'Tool Kits') !== false) {
                return;
            }
            @file_put_contents($web_config, rtrim((string) $contents, "\r\n") . "\n\n<!-- Tool Kits: block uploads php -->\n" . $block);
            return;
        }
        @file_put_contents($web_config, "<!-- Tool Kits: block uploads php -->\n" . $block);
    }
}

function tk_hardening_http_auth(): void {
    if (defined('WP_CLI') && WP_CLI) {
        return;
    }
    if (function_exists('wp_doing_cron') && wp_doing_cron()) {
        return;
    }
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }

    $server_aware = tk_get_option('hardening_server_aware_enabled', 1) ? true : false;
    $user = (string) tk_get_option('hardening_httpauth_user', '');
    $hash = (string) tk_get_option('hardening_httpauth_pass', '');
    $scope = (string) tk_get_option('hardening_httpauth_scope', 'both');
    if (!in_array($scope, array('frontend', 'admin', 'both'), true)) {
        $scope = 'both';
    }
    if ($scope !== 'both' && !tk_hardening_httpauth_scope_match($scope)) {
        return;
    }
    if ($user === '' || $hash === '') {
        return;
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if (tk_hardening_httpauth_is_allowed($request_uri)) {
        return;
    }

    $sent_user = isset($_SERVER['PHP_AUTH_USER']) ? (string) $_SERVER['PHP_AUTH_USER'] : '';
    $sent_pass = isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : '';
    if ($server_aware && $sent_user === '' && $sent_pass === '') {
        $auth_header = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth_header = (string) $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth_header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (stripos($auth_header, 'basic ') === 0) {
            $decoded = base64_decode(substr($auth_header, 6), true);
            if ($decoded !== false && strpos($decoded, ':') !== false) {
                list($sent_user, $sent_pass) = explode(':', $decoded, 2);
            }
        }
    }

    if ($sent_user !== $user || !wp_check_password($sent_pass, $hash)) {
        header('WWW-Authenticate: Basic realm="Tool Kits"');
        wp_die('Authorization required.', 'Unauthorized', array('response' => 401));
    }
}

function tk_hardening_httpauth_scope_match(string $scope): bool {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $is_admin_request = is_admin();
    if (!$is_admin_request && function_exists('get_current_screen')) {
        $screen = get_current_screen();
        $is_admin_request = $screen ? $screen->in_admin() : false;
    }
    if (!$is_admin_request && strpos($request_uri, '/wp-admin') !== false) {
        $is_admin_request = true;
    }
    if (!$is_admin_request && isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'wp-login.php') {
        $is_admin_request = true;
    }
    return $scope === 'admin' ? $is_admin_request : !$is_admin_request;
}

function tk_hardening_httpauth_is_allowed(string $request_uri): bool {
    $allow_paths = tk_get_option('hardening_httpauth_allow_paths', '');
    if (is_string($allow_paths) && $allow_paths !== '') {
        $lines = preg_split('/\r\n|\r|\n/', $allow_paths);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (stripos($request_uri, $line) !== false) {
                return true;
            }
        }
    }
    $allow_regex = tk_get_option('hardening_httpauth_allow_regex', '');
    if (is_string($allow_regex) && $allow_regex !== '') {
        $lines = preg_split('/\r\n|\r|\n/', $allow_regex);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $result = @preg_match($line, $request_uri);
            if ($result === 1) {
                return true;
            }
        }
    }
    return false;
}

function tk_hardening_detect_server(): string {
    $software = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower((string) $_SERVER['SERVER_SOFTWARE']) : '';
    if ($software === '') {
        return 'unknown';
    }
    if (strpos($software, 'nginx') !== false) {
        return 'nginx';
    }
    if (strpos($software, 'apache') !== false) {
        return 'apache';
    }
    if (strpos($software, 'litespeed') !== false) {
        return 'litespeed';
    }
    if (strpos($software, 'openlitespeed') !== false) {
        return 'openlitespeed';
    }
    if (strpos($software, 'caddy') !== false) {
        return 'caddy';
    }
    if (strpos($software, 'iis') !== false) {
        return 'iis';
    }
    return 'unknown';
}

function tk_hardening_server_rules(): array {
    $server = tk_hardening_detect_server();
    $rules = array();
    if ($server === 'apache' || $server === 'litespeed' || $server === 'openlitespeed') {
        $rules[] = 'Use .htaccess to deny access to /.env and /wp-content/debug.log';
        $rules[] = 'Disable directory listing with Options -Indexes';
        $rules[] = 'Block PHP execution in /wp-content/uploads via .htaccess';
    } elseif ($server === 'nginx') {
        $rules[] = 'Use nginx location blocks to deny access to /.env and /wp-content/debug.log';
        $rules[] = 'Disable directory listing with autoindex off';
        $rules[] = 'Ensure fastcgi_params includes HTTP_AUTHORIZATION for Basic Auth';
        $rules[] = 'Block PHP execution in /wp-content/uploads via location rule';
    } elseif ($server === 'caddy') {
        $rules[] = 'Use Caddyfile to deny access to /.env and /wp-content/debug.log';
        $rules[] = 'Disable directory listing with file_server browse off';
        $rules[] = 'Block PHP execution in /wp-content/uploads via matcher';
    } elseif ($server === 'iis') {
        $rules[] = 'Use web.config to deny access to /.env and /wp-content/debug.log';
        $rules[] = 'Disable directory browsing in IIS settings';
        $rules[] = 'Block PHP execution in /wp-content/uploads via web.config';
    } else {
        $rules[] = 'Block access to /.env and /wp-content/debug.log at the web server level';
        $rules[] = 'Disable directory listing on uploads directory';
        $rules[] = 'Block PHP execution in /wp-content/uploads';
    }
    return $rules;
}

function tk_hardening_server_rule_snippet(): string {
    $server = tk_hardening_detect_server();
    $browser_cache = (int) tk_get_option('hardening_browser_cache_enabled', 1) === 1;

    if ($server === 'apache' || $server === 'litespeed' || $server === 'openlitespeed') {
        $out = "<IfModule mod_autoindex.c>\n  Options -Indexes\n</IfModule>\n<FilesMatch \"^\\.env|debug\\.log$\">\n  Require all denied\n</FilesMatch>\n<IfModule mod_rewrite.c>\n  RewriteEngine On\n  RewriteRule ^wp-content/uploads/.*\\.php$ - [F]\n</IfModule>";
        if ($browser_cache) {
            $out .= "\n<IfModule mod_expires.c>\n  ExpiresActive On\n  ExpiresDefault \"access plus 1 month\"\n  ExpiresByType image/jpg \"access plus 1 year\"\n  ExpiresByType image/jpeg \"access plus 1 year\"\n  ExpiresByType image/gif \"access plus 1 year\"\n  ExpiresByType image/png \"access plus 1 year\"\n  ExpiresByType image/webp \"access plus 1 year\"\n  ExpiresByType image/x-icon \"access plus 1 year\"\n  ExpiresByType image/svg+xml \"access plus 1 year\"\n  ExpiresByType text/css \"access plus 1 year\"\n  ExpiresByType application/javascript \"access plus 1 year\"\n  ExpiresByType application/x-javascript \"access plus 1 year\"\n  ExpiresByType font/woff2 \"access plus 1 year\"\n  ExpiresByType font/woff \"access plus 1 year\"\n  ExpiresByType font/ttf \"access plus 1 year\"\n  ExpiresByType font/otf \"access plus 1 year\"\n</IfModule>\n<IfModule mod_headers.c>\n  <FilesMatch \"\\.(ico|pdf|jpg|jpeg|png|gif|webp|svg|js|css|woff2|woff|ttf|otf)$\">\n    Header set Cache-Control \"max-age=31536000, public\"\n  </FilesMatch>\n</IfModule>";
        }
        return $out;
    }
    if ($server === 'nginx') {
        $out = "autoindex off;\nlocation = /.env { deny all; }\nlocation = /wp-content/debug.log { deny all; }\nlocation ~* ^/wp-content/uploads/.*\\.php$ { deny all; }\nfastcgi_param HTTP_AUTHORIZATION \$http_authorization;";
        if ($browser_cache) {
            $out .= "\nlocation ~* \\.(jpg|jpeg|gif|png|webp|svg|woff|woff2|ttf|css|js|ico|pdf|zip|gz|mp4|m4v|ogg|ogv|webm)$ {\n  expires 1y;\n  add_header Cache-Control \"public, no-transform\";\n}";
        }
        return $out;
    }
    if ($server === 'caddy') {
        $out = "file_server {\n  browse off\n}\n@blocked path /.env /wp-content/debug.log\nrespond @blocked 403\n@uploadsPhp path_regexp uploadsPhp ^/wp-content/uploads/.*\\.php$\nrespond @uploadsPhp 403";
        return $out;
    }
    if ($server === 'iis') {
        $out = "<system.webServer>\n  <directoryBrowse enabled=\"false\" />\n  <security>\n    <requestFiltering>\n      <fileExtensions>\n        <add fileExtension=\".env\" allowed=\"false\" />\n        <add fileExtension=\".log\" allowed=\"false\" />\n        <add fileExtension=\".php\" allowed=\"false\" />\n      </fileExtensions>\n    </requestFiltering>\n  </security>";
        if ($browser_cache) {
            $out .= "\n  <staticContent>\n    <clientCache cacheControlMode=\"UseMaxAge\" cacheControlMaxAge=\"365.00:00:00\" />\n  </staticContent>";
        }
        $out .= "\n</system.webServer>";
        return $out;
    }
    return '';
}

function tk_hardening_apply_root_server_rules(): void {
    $server = tk_hardening_detect_server();
    if (!in_array($server, array('apache', 'litespeed', 'openlitespeed'), true)) {
        return;
    }

    $path = rtrim(ABSPATH, '/') . '/.htaccess';
    $snippet = tk_hardening_server_rule_snippet();
    $lines = explode("\n", $snippet);

    if (!function_exists('insert_with_markers')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    
    if (function_exists('insert_with_markers')) {
        insert_with_markers($path, 'Tool Kits Root', $lines);
    }
}

function tk_hardening_server_rule_status(): array {
    $server = tk_hardening_detect_server();
    if ($server === 'apache' || $server === 'litespeed' || $server === 'openlitespeed') {
        $htaccess = rtrim(ABSPATH, '/') . '/.htaccess';
        if (!file_exists($htaccess)) {
            return array('status' => 'warn', 'detail' => '.htaccess not found in WordPress root.');
        }
        $contents = @file_get_contents($htaccess);
        if (!is_string($contents)) {
            return array('status' => 'unknown', 'detail' => 'Unable to read .htaccess.');
        }
        $lower = strtolower($contents);
        $has_indexes = preg_match('/options\s+\-indexes/i', $contents) === 1
            || preg_match('/indexes?\s+off/i', $contents) === 1;
        $has_env = preg_match('/\.env/i', $contents) === 1;
        $has_debug = preg_match('/debug\.log/i', $contents) === 1;
        $has_uploads = preg_match('/wp-content\/uploads\/.*\.php/i', $contents) === 1;
        
        $browser_cache_enabled = (int) tk_get_option('hardening_browser_cache_enabled', 1) === 1;
        $has_cache = preg_match('/mod_expires\.c/i', $contents) === 1 || preg_match('/expires\s+1y/i', $contents) === 1;
        
        $ok = $has_indexes && $has_env && $has_debug && $has_uploads;
        if ($browser_cache_enabled) {
            $ok = $ok && $has_cache;
        }

        if ($ok) {
            return array('status' => 'ok', 'detail' => 'Server rules detected in .htaccess.');
        }
        return array('status' => 'warn', 'detail' => 'Server rules not fully detected in .htaccess.');
    }
    return array('status' => 'unknown', 'detail' => 'Detection not supported for this server.');
}

function tk_hardening_disable_comments(): void {
    add_filter('comments_open', '__return_false', 20, 2);
    add_filter('pings_open', '__return_false', 20, 2);
    add_filter('comments_array', '__return_empty_array', 10, 2);
    add_filter('preprocess_comment', 'tk_hardening_block_comment_submission', 1);
    add_filter('rest_endpoints', 'tk_hardening_disable_comment_rest_endpoints');
    add_filter('xmlrpc_methods', 'tk_hardening_disable_comment_xmlrpc_methods');
    add_action('template_redirect', 'tk_hardening_block_comment_feed', 0);
    add_action('admin_init', 'tk_hardening_disable_comment_post_type_support');
    add_action('admin_menu', 'tk_hardening_remove_comment_admin_menus', 999);
    add_action('admin_init', 'tk_hardening_block_comment_admin_pages');
    add_action('admin_bar_menu', 'tk_hardening_remove_comment_admin_bar_nodes', 999);
    add_action('wp_dashboard_setup', 'tk_hardening_remove_comment_dashboard_widgets', 999);
}

function tk_hardening_block_comment_submission(array $commentdata): array {
    wp_die(__('Comments are disabled.', 'tool-kits'), __('Comments disabled', 'tool-kits'), array('response' => 403));
}

function tk_hardening_disable_comment_rest_endpoints($endpoints) {
    if (!is_array($endpoints)) {
        return $endpoints;
    }
    unset($endpoints['/wp/v2/comments']);
    unset($endpoints['/wp/v2/comments/(?P<id>[\d]+)']);
    return $endpoints;
}

function tk_hardening_disable_comment_xmlrpc_methods($methods) {
    if (!is_array($methods)) {
        return $methods;
    }
    $blocked = array(
        'wp.getComment',
        'wp.getComments',
        'wp.deleteComment',
        'wp.editComment',
        'wp.newComment',
        'wp.getCommentStatusList',
    );
    foreach ($blocked as $method) {
        unset($methods[$method]);
    }
    return $methods;
}

function tk_hardening_block_comment_feed(): void {
    if (is_comment_feed()) {
        wp_die(__('Comment feeds are disabled.', 'tool-kits'), __('Comments disabled', 'tool-kits'), array('response' => 410));
    }
}

function tk_hardening_disable_comment_post_type_support(): void {
    update_option('default_comment_status', 'closed', false);
    update_option('default_ping_status', 'closed', false);

    $post_types = get_post_types(array(), 'names');
    if (!is_array($post_types)) {
        return;
    }
    foreach ($post_types as $type) {
        if (post_type_supports($type, 'comments')) {
            remove_post_type_support($type, 'comments');
        }
        if (post_type_supports($type, 'trackbacks')) {
            remove_post_type_support($type, 'trackbacks');
        }
    }
}

function tk_hardening_remove_comment_admin_menus(): void {
    remove_menu_page('edit-comments.php');
    remove_submenu_page('options-general.php', 'options-discussion.php');
}

function tk_hardening_block_comment_admin_pages(): void {
    global $pagenow;
    if ($pagenow === 'edit-comments.php' || $pagenow === 'comment.php') {
        wp_die(__('Comments are disabled.', 'tool-kits'), __('Comments disabled', 'tool-kits'), array('response' => 403));
    }
    if ($pagenow === 'options-discussion.php') {
        wp_die(__('Discussion settings are disabled.', 'tool-kits'), __('Comments disabled', 'tool-kits'), array('response' => 403));
    }
}

function tk_hardening_remove_comment_admin_bar_nodes($wp_admin_bar): void {
    if (is_object($wp_admin_bar) && method_exists($wp_admin_bar, 'remove_node')) {
        $wp_admin_bar->remove_node('comments');
    }
}

function tk_hardening_remove_comment_dashboard_widgets(): void {
    remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
}

function tk_hardening_core_root_entries(): array {
    return array(
        '.htaccess',
        'index.php',
        'license.txt',
        'readme.html',
        'wp-activate.php',
        'wp-admin',
        'wp-blog-header.php',
        'wp-comments-post.php',
        'wp-config.php',
        'wp-config-sample.php',
        'wp-content',
        'wp-cron.php',
        'wp-includes',
        'wp-links-opml.php',
        'wp-load.php',
        'wp-login.php',
        'wp-mail.php',
        'wp-settings.php',
        'wp-signup.php',
        'wp-trackback.php',
        'xmlrpc.php',
        'web.config',
        'robots.txt',
        'favicon.ico',
        'sitemap.xml',
        'sitemap_index.xml',
        '.well-known',
    );
}

function tk_hardening_noncore_root_entries(): array {
    $root = rtrim(ABSPATH, '/');
    $entries = @scandir($root);
    if (!is_array($entries)) {
        return array();
    }
    $allowed = array_flip(tk_hardening_core_root_entries());
    $noncore = array();
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (isset($allowed[$entry])) {
            continue;
        }
        $noncore[] = $entry;
    }
    sort($noncore);
    return $noncore;
}

function tk_hardening_fetch_url(string $url): array {
    $response = wp_remote_get($url, array(
        'timeout' => 5,
        'redirection' => 0,
    ));
    if (is_wp_error($response)) {
        return array('ok' => false, 'code' => 0, 'body' => '', 'headers' => array(), 'error' => $response->get_error_message());
    }
    return array(
        'ok' => true,
        'code' => (int) wp_remote_retrieve_response_code($response),
        'body' => (string) wp_remote_retrieve_body($response),
        'headers' => wp_remote_retrieve_headers($response),
        'error' => '',
    );
}

function tk_hardening_config_checks(): array {
    $checks = array();
    $server = tk_hardening_detect_server();
    $env_path = ABSPATH . '.env';
    if (!file_exists($env_path)) {
        $parent_env = dirname(ABSPATH) . '/.env';
        $env_path = file_exists($parent_env) ? $parent_env : '';
    }
    if ($env_path === '') {
            $checks[] = array(
                'label' => '.env accessible',
                'status' => 'ok',
                'detail' => 'File not present.',
                'action_label' => 'Server rules',
                'action_url' => tk_admin_url('tool-kits-monitoring') . '#server',
            );
        } else {
            $env_url = home_url('/.env');
            $result = tk_hardening_fetch_url($env_url);
            if (!$result['ok']) {
                $checks[] = array(
                    'label' => '.env accessible',
                    'status' => 'unknown',
                    'detail' => 'Request failed.',
                    'action_label' => 'Server rules',
                    'action_url' => tk_admin_url('tool-kits-monitoring') . '#server',
                );
            } else {
                $public = $result['code'] === 200 && trim($result['body']) !== '';
                $checks[] = array(
                    'label' => '.env accessible',
                    'status' => $public ? 'warn' : 'ok',
                    'detail' => $public ? 'Publicly accessible.' : 'Not publicly accessible.',
                    'action_label' => 'Server rules',
                    'action_url' => tk_admin_url('tool-kits-monitoring') . '#server',
                );
            }
        }

    $debug_log_path = WP_CONTENT_DIR . '/debug.log';
    if (!file_exists($debug_log_path)) {
        $checks[] = array(
            'label' => 'debug.log public',
            'status' => 'ok',
            'detail' => 'File not present.',
            'action_label' => 'Server rules',
            'action_url' => tk_admin_url('tool-kits-monitoring') . '#server',
        );
    } else {
        $debug_url = content_url('debug.log');
        $result = tk_hardening_fetch_url($debug_url);
        if (!$result['ok']) {
            $checks[] = array(
                'label' => 'debug.log public',
                'status' => 'unknown',
                'detail' => 'Request failed.',
                'action_label' => 'Server rules',
                'action_url' => tk_admin_url('tool-kits-monitoring') . '#server',
            );
        } else {
            $public = $result['code'] === 200 && trim($result['body']) !== '';
            $checks[] = array(
                'label' => 'debug.log public',
                'status' => $public ? 'warn' : 'ok',
                'detail' => $public ? 'Publicly accessible.' : 'Not publicly accessible.',
                'action_label' => 'Server rules',
                'action_url' => tk_admin_url('tool-kits-monitoring') . '#server',
            );
        }
    }

    $upload = wp_upload_dir();
    if (empty($upload['baseurl'])) {
        $checks[] = array(
            'label' => 'directory listing ON',
            'status' => 'unknown',
            'detail' => 'Uploads URL not available.',
            'action_label' => 'Server rules',
            'action_url' => tk_admin_url('tool-kits-monitoring') . '#server',
        );
    } else {
        $dir_url = trailingslashit($upload['baseurl']);
        $result = tk_hardening_fetch_url($dir_url);
        if (!$result['ok']) {
            $checks[] = array(
                'label' => 'directory listing ON',
                'status' => 'unknown',
                'detail' => 'Request failed.',
                'action_label' => 'Server rules',
                'action_url' => tk_admin_url('tool-kits-monitoring') . '#server',
            );
        } else {
            $body = strtolower($result['body']);
            $listing = $result['code'] === 200 && (strpos($body, 'index of /') !== false || strpos($body, '<title>index of') !== false);
            $checks[] = array(
                'label' => 'directory listing ON',
                'status' => $listing ? 'warn' : 'ok',
                'detail' => $listing ? 'Directory listing detected.' : 'No directory listing detected.',
                'action_label' => 'Server rules',
                'action_url' => tk_admin_url('tool-kits-monitoring') . '#server',
            );
        }
    }

    $rest_disabled = (int) tk_get_option('hardening_disable_rest_user_enum', 1) === 1;
    $checks[] = array(
        'label' => 'REST user listing ON',
        'status' => $rest_disabled ? 'ok' : 'warn',
        'detail' => $rest_disabled ? 'Disabled by hardening setting.' : 'REST user listing is enabled.',
        'action_label' => 'Hardening settings',
        'action_url' => tk_admin_url('tool-kits-guard') . '#general',
    );

    $unwanted_hits = array();
    $root = rtrim(ABSPATH, '/');
    foreach (tk_hardening_unwanted_file_names() as $name) {
        $root_candidate = $root . '/' . ltrim($name, '/');
        $content_candidate = WP_CONTENT_DIR . '/' . ltrim($name, '/');
        if (file_exists($root_candidate)) {
            $unwanted_hits[] = '/' . ltrim($name, '/');
        } elseif (file_exists($content_candidate)) {
            $unwanted_hits[] = '/wp-content/' . ltrim($name, '/');
        }
    }
    $unwanted_detail = 'No known unwanted files found in root/wp-content.';
    if (!empty($unwanted_hits)) {
        $unwanted_detail = 'Found: ' . implode(', ', $unwanted_hits) . '. Fix: remove these files if not needed, then keep "Block direct access to unwanted filenames" enabled in Hardening > General.';
    }
    $checks[] = array(
        'label' => 'Unwanted files detected',
        'status' => empty($unwanted_hits) ? 'ok' : 'warn',
        'detail' => $unwanted_detail,
        'action_label' => 'Hardening settings',
        'action_url' => tk_admin_url('tool-kits-guard') . '#general',
    );

    $wp_json_hits = array();
    $wp_json_candidates = array(
        '/wp-json',
        '/wp-json.php',
        '/wp_json.php',
        '/wp-content/wp-json',
        '/wp-content/wp-json.php',
        '/wp-content/wp_json.php',
    );
    foreach ($wp_json_candidates as $candidate) {
        $absolute = rtrim(ABSPATH, '/') . $candidate;
        if (strpos($candidate, '/wp-content/') === 0) {
            $absolute = WP_CONTENT_DIR . '/' . ltrim(substr($candidate, strlen('/wp-content/')), '/');
        }
        if (file_exists($absolute)) {
            $wp_json_hits[] = $candidate;
        }
    }
    $wp_json_detail = 'No wp-json file found in root/wp-content.';
    if (!empty($wp_json_hits)) {
        $wp_json_detail = 'Found: ' . implode(', ', $wp_json_hits) . '. Fix: inspect file contents, remove if not required, and scan site for malware/backdoor.';
    }
    $checks[] = array(
        'label' => 'WP-JSON file detected',
        'status' => empty($wp_json_hits) ? 'ok' : 'warn',
        'detail' => $wp_json_detail,
        'action_label' => 'Hardening settings',
        'action_url' => tk_admin_url('tool-kits-guard') . '#general',
    );

    $wp_config_path = tk_hardening_wp_config_path();
    if ($wp_config_path !== '') {
        $writable = is_writable($wp_config_path);
        $checks[] = array(
            'label' => 'wp-config.php read-only',
            'status' => $writable ? 'warn' : 'ok',
            'detail' => $writable ? 'Writable. Consider setting read-only permissions (e.g., 0440/0444).' : 'Read-only.',
            'action_label' => 'Quick action',
            'action_url' => tk_admin_url('tool-kits-monitoring') . '#actions',
        );
    }

    $auto_core_option = tk_get_option('hardening_core_auto_updates', 1) ? true : false;
    $auto_core_constant = defined('WP_AUTO_UPDATE_CORE') ? WP_AUTO_UPDATE_CORE : null;
    $auto_core = $auto_core_option || $auto_core_constant === true;
    $checks[] = array(
        'label' => 'Core auto-updates',
        'status' => $auto_core ? 'ok' : 'warn',
        'detail' => $auto_core ? 'Enabled.' : 'Not enabled. Consider enabling for security patches.',
        'action_label' => 'Quick action',
        'action_url' => tk_admin_url('tool-kits-monitoring') . '#actions',
    );

    $wp_cron_disabled = defined('DISABLE_WP_CRON') ? (bool) DISABLE_WP_CRON : (bool) tk_get_option('hardening_disable_wp_cron', 0);
    $checks[] = array(
        'label' => 'WP-Cron disabled',
        'status' => $wp_cron_disabled ? 'ok' : 'warn',
        'detail' => $wp_cron_disabled ? 'Disabled.' : 'Enabled. Consider disabling and running real server cron.',
        'action_label' => 'Hardening settings',
        'action_url' => tk_admin_url('tool-kits-guard') . '#general',
    );

    $disallow_file_mods = defined('DISALLOW_FILE_MODS') ? (bool) DISALLOW_FILE_MODS : false;
    $checks[] = array(
        'label' => 'Plugin/theme updates allowed',
        'status' => $disallow_file_mods ? 'warn' : 'ok',
        'detail' => $disallow_file_mods ? 'Updates/install disabled. Ensure updates are managed externally.' : 'Updates allowed.',
    );

    if (is_ssl() && !tk_get_option('hardening_hsts_enabled', 0)) {
        $checks[] = array(
            'label' => 'HSTS enabled',
            'status' => 'warn',
            'detail' => 'HSTS is not enabled. Consider enabling HSTS at the server level.',
            'action_label' => 'Hardening settings',
            'action_url' => tk_admin_url('tool-kits-guard') . '#general',
        );
    } else {
        $checks[] = array(
            'label' => 'HSTS enabled',
            'status' => is_ssl() ? 'ok' : 'ok',
            'detail' => is_ssl() ? 'HTTPS detected.' : 'Not applicable (HTTP).',
            'action_label' => 'Hardening settings',
            'action_url' => tk_admin_url('tool-kits-guard') . '#general',
        );
    }

    $strict_csp = tk_get_option('hardening_csp_strict_enabled', 0) ? true : false;
    $checks[] = array(
        'label' => 'CSP strict mode',
        'status' => $strict_csp ? 'ok' : 'warn',
        'detail' => $strict_csp ? 'Enabled (unsafe-inline/eval removed).' : 'Disabled. CSP may still allow unsafe-inline/eval.',
        'action_label' => 'Hardening settings',
        'action_url' => tk_admin_url('tool-kits-guard') . '#general',
    );

    $server_sig_hidden = tk_get_option('hardening_server_signature_hide', 1) ? true : false;
    $checks[] = array(
        'label' => 'Server signature headers hidden',
        'status' => $server_sig_hidden ? 'ok' : 'warn',
        'detail' => $server_sig_hidden ? 'Enabled.' : 'Disabled.',
        'action_label' => 'Hardening settings',
        'action_url' => tk_admin_url('tool-kits-guard') . '#general',
    );

    $home_result = tk_hardening_fetch_url(home_url('/'));
    if (!$home_result['ok']) {
        $checks[] = array(
            'label' => 'Server header disclosure',
            'status' => 'unknown',
            'detail' => 'Unable to inspect response headers from the homepage.',
            'action_label' => 'Server rules',
            'action_url' => tk_admin_url('tool-kits-monitoring') . '#server',
        );
    } else {
        $server_header = '';
        $headers = $home_result['headers'];
        if (is_array($headers) && isset($headers['server'])) {
            $server_header = is_array($headers['server']) ? (string) reset($headers['server']) : (string) $headers['server'];
        } elseif (is_object($headers) && method_exists($headers, 'offsetGet')) {
            $value = $headers->offsetGet('server');
            if (is_array($value)) {
                $server_header = (string) reset($value);
            } elseif (is_string($value)) {
                $server_header = $value;
            }
        }
        $server_header = trim($server_header);
        $checks[] = array(
            'label' => 'Server header disclosure',
            'status' => $server_header === '' ? 'ok' : 'warn',
            'detail' => $server_header === ''
                ? 'Server header not exposed in homepage response.'
                : 'Server header exposed as "' . $server_header . '". Remove or minimize it at the web server, reverse proxy, or CDN layer.',
            'action_label' => 'Server rules',
            'action_url' => tk_admin_url('tool-kits-monitoring') . '#server',
        );
    }

    $httponly_forced = tk_get_option('hardening_cookie_httponly_force', 0) ? true : false;
    $checks[] = array(
        'label' => 'Cookie HttpOnly enforcement',
        'status' => $httponly_forced ? 'ok' : 'warn',
        'detail' => $httponly_forced ? 'Enabled.' : 'Disabled.',
        'action_label' => 'Hardening settings',
        'action_url' => tk_admin_url('tool-kits-guard') . '#general',
    );

    $method_filter = tk_get_option('hardening_http_methods_filter_enabled', 0) ? true : false;
    $checks[] = array(
        'label' => 'HTTP methods filtering',
        'status' => $method_filter ? 'ok' : 'warn',
        'detail' => $method_filter ? 'Enabled.' : 'Disabled.',
        'action_label' => 'Hardening settings',
        'action_url' => tk_admin_url('tool-kits-guard') . '#general',
    );

    $dangerous_methods_block = tk_get_option('hardening_block_dangerous_methods_enabled', 1) ? true : false;
    $checks[] = array(
        'label' => 'Dangerous HTTP methods blocked',
        'status' => $dangerous_methods_block ? 'ok' : 'warn',
        'detail' => $dangerous_methods_block ? 'PUT/DELETE/TRACE/CONNECT block enabled.' : 'Disabled.',
        'action_label' => 'Hardening settings',
        'action_url' => tk_admin_url('tool-kits-guard') . '#general',
    );

    $robots_hardened = tk_get_option('hardening_robots_txt_hardened', 0) ? true : false;
    $checks[] = array(
        'label' => 'robots.txt hardened',
        'status' => $robots_hardened ? 'ok' : 'warn',
        'detail' => $robots_hardened ? 'Enabled minimal robots policy.' : 'Disabled.',
        'action_label' => 'Hardening settings',
        'action_url' => tk_admin_url('tool-kits-guard') . '#general',
    );

    $unwanted_block = tk_get_option('hardening_block_unwanted_files_enabled', 1) ? true : false;
    $checks[] = array(
        'label' => 'Unwanted file access blocked',
        'status' => $unwanted_block ? 'ok' : 'warn',
        'detail' => $unwanted_block ? 'Enabled.' : 'Disabled.',
        'action_label' => 'Hardening settings',
        'action_url' => tk_admin_url('tool-kits-guard') . '#general',
    );

    $mysql_check = tk_get_option('hardening_mysql_exposure_check_enabled', 1) ? true : false;
    if (!$mysql_check) {
        $checks[] = array(
            'label' => 'MySQL public exposure risk',
            'status' => 'unknown',
            'detail' => 'Check disabled.',
            'action_label' => 'Hardening settings',
            'action_url' => tk_admin_url('tool-kits-guard') . '#general',
        );
    } else {
        $parsed = tk_hardening_db_host_parse(tk_hardening_db_host_value());
        $db_host = isset($parsed['host']) ? (string) $parsed['host'] : '';
        $db_port = isset($parsed['port']) ? (int) $parsed['port'] : 3306;
        $allow_public = tk_get_option('hardening_mysql_allow_public_host', 0) ? true : false;
        if ($db_host === '' || $db_host === 'localhost' || $db_host === '127.0.0.1' || $db_host === '::1') {
            $checks[] = array(
                'label' => 'MySQL public exposure risk',
                'status' => 'ok',
                'detail' => 'DB host is local (' . ($db_host === '' ? 'localhost' : $db_host) . ').',
                'action_label' => 'Hardening settings',
                'action_url' => tk_admin_url('tool-kits-guard') . '#general',
            );
        } elseif (filter_var($db_host, FILTER_VALIDATE_IP) && tk_hardening_is_private_ip($db_host)) {
            $checks[] = array(
                'label' => 'MySQL public exposure risk',
                'status' => 'ok',
                'detail' => 'DB host uses private IP ' . $db_host . ':' . $db_port . '.',
                'action_label' => 'Hardening settings',
                'action_url' => tk_admin_url('tool-kits-guard') . '#general',
            );
        } else {
            $status = $allow_public ? 'ok' : 'warn';
            $detail = $allow_public
                ? 'Public/remote DB host allowed by setting: ' . $db_host . ':' . $db_port . '.'
                : 'DB host may be public/remote (' . $db_host . ':' . $db_port . '). Restrict 3306 via firewall.';
            $checks[] = array(
                'label' => 'MySQL public exposure risk',
                'status' => $status,
                'detail' => $detail,
                'action_label' => 'Hardening settings',
                'action_url' => tk_admin_url('tool-kits-guard') . '#general',
            );
        }
    }

    $uploads = wp_upload_dir();
    $uploads_path = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
    if ($uploads_path === '' || !is_dir($uploads_path)) {
        $checks[] = array(
            'label' => 'Uploads PHP execution',
            'status' => 'unknown',
            'detail' => 'Uploads directory not found.',
            'action_label' => 'Hardening settings',
            'action_url' => tk_admin_url('tool-kits-guard') . '#general',
        );
    } elseif (in_array($server, array('apache', 'litespeed', 'openlitespeed'), true)) {
        $htaccess = trailingslashit($uploads_path) . '.htaccess';
        $ok = false;
        if (file_exists($htaccess)) {
            $contents = @file_get_contents($htaccess);
            if (is_string($contents)) {
                $lower = strtolower($contents);
                $ok = strpos($lower, 'rewrite') !== false || strpos($lower, 'filesmatch') !== false || strpos($lower, 'php_flag') !== false || strpos($lower, 'removehandler') !== false;
            }
        }
        $checks[] = array(
            'label' => 'Uploads PHP execution',
            'status' => $ok ? 'ok' : 'warn',
            'detail' => $ok ? 'Upload PHP blocking rule detected.' : 'No uploads PHP blocking rule detected.',
            'action_label' => 'Hardening settings',
            'action_url' => tk_admin_url('tool-kits-guard') . '#general',
        );
    } elseif ($server === 'iis') {
        $web_config = trailingslashit($uploads_path) . 'web.config';
        $ok = false;
        if (file_exists($web_config)) {
            $contents = @file_get_contents($web_config);
            if (is_string($contents)) {
                $lower = strtolower($contents);
                $ok = strpos($lower, 'fileextensions') !== false && strpos($lower, '.php') !== false;
            }
        }
        $checks[] = array(
            'label' => 'Uploads PHP execution',
            'status' => $ok ? 'ok' : 'warn',
            'detail' => $ok ? 'Upload PHP blocking rule detected.' : 'No uploads PHP blocking rule detected.',
            'action_label' => 'Hardening settings',
            'action_url' => tk_admin_url('tool-kits-guard') . '#general',
        );
    } else {
        $checks[] = array(
            'label' => 'Uploads PHP execution',
            'status' => 'unknown',
            'detail' => 'Check server config to block PHP in uploads.',
            'action_label' => 'Hardening settings',
            'action_url' => tk_admin_url('tool-kits-guard') . '#general',
        );
    }

    return $checks;
}

function tk_hardening_normalize_origin(string $origin): string {
    $parts = wp_parse_url($origin);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return $parts['scheme'] . '://' . $parts['host'] . $port;
}

function tk_hardening_allowed_origins(): array {
    $origins = array(
        tk_hardening_normalize_origin(home_url()),
        tk_hardening_normalize_origin(site_url()),
    );
    $custom_enabled = tk_get_option('hardening_cors_custom_origins_enabled', 0) ? true : false;
    if ($custom_enabled) {
        $custom = tk_get_option('hardening_cors_allowed_origins', '');
        if (is_string($custom) && $custom !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $custom);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $normalized = tk_hardening_normalize_origin($line);
                if ($normalized !== '') {
                    $origins[] = $normalized;
                }
            }
        }
    }
    $origins = array_filter(array_unique($origins));
    return apply_filters('tk_hardening_allowed_origins', $origins);
}

function tk_hardening_cors_headers(): void {
    $origin = get_http_origin();
    if (!$origin) {
        return;
    }

    $normalized = tk_hardening_normalize_origin($origin);
    if ($normalized === '') {
        return;
    }

    $allowed = tk_hardening_allowed_origins();
    if (!in_array($normalized, $allowed, true)) {
        return;
    }

    header('Access-Control-Allow-Origin: ' . esc_url_raw($normalized));
    header('Vary: Origin');

    $allow_credentials = tk_get_option('hardening_cors_allow_credentials', 0) ? true : false;
    $allow_credentials = apply_filters('tk_hardening_allow_cors_credentials', $allow_credentials, $normalized);
    if ($allow_credentials) {
        header('Access-Control-Allow-Credentials: true');
    }

    $methods_value = tk_get_option('hardening_cors_allowed_methods', '');
    $methods = $methods_value !== '' ? array_map('trim', explode(',', $methods_value)) : array('GET', 'POST', 'OPTIONS');
    $methods = apply_filters('tk_hardening_allowed_cors_methods', $methods, $normalized);
    if (is_array($methods) && !empty($methods)) {
        header('Access-Control-Allow-Methods: ' . implode(', ', array_map('sanitize_text_field', $methods)));
    }

    $headers_value = tk_get_option('hardening_cors_allowed_headers', '');
    $headers = $headers_value !== '' ? array_map('trim', explode(',', $headers_value)) : array('Authorization', 'X-WP-Nonce', 'Content-Type');
    $headers = apply_filters('tk_hardening_allowed_cors_headers', $headers, $normalized);
    if (is_array($headers) && !empty($headers)) {
        header('Access-Control-Allow-Headers: ' . implode(', ', array_map('sanitize_text_field', $headers)));
    }
}

function tk_disable_pingbacks($methods) {
    if (isset($methods['pingback.ping'])) {
        unset($methods['pingback.ping']);
    }
    return $methods;
}

function tk_xmlrpc_block_methods($methods) {
    $blocked = tk_hardening_get_blocked_xmlrpc_methods();
    foreach ($blocked as $method) {
        if (isset($methods[$method])) {
            unset($methods[$method]);
        }
    }
    return $methods;
}

function tk_hardening_get_blocked_xmlrpc_methods(): array {
    $raw = tk_get_option('hardening_xmlrpc_blocked_methods', '');
    $lines = is_string($raw) ? preg_split('/\r\n|\r|\n/', $raw) : array();
    $blocked = array();
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $blocked[] = $line;
        }
    }
    if (empty($blocked)) {
        $blocked = array('system.multicall', 'pingback.ping', 'pingback.extensions.getPingbacks');
    }
    return array_values(array_unique($blocked));
}

function tk_xmlrpc_rate_limit_key(): string {
    return 'tk_xrl_' . md5(tk_get_ip());
}

function tk_xmlrpc_rate_limit_lock_key(): string {
    return 'tk_xrl_lock_' . md5(tk_get_ip());
}

function tk_xmlrpc_rate_limit($method): void {
    if (get_transient(tk_xmlrpc_rate_limit_lock_key())) {
        wp_die('Too many XML-RPC requests. Please try again later.', 'Rate limit', array('response' => 429));
    }

    $window = max(1, (int) tk_get_option('hardening_xmlrpc_rate_limit_window_minutes', 10));
    $max = max(1, (int) tk_get_option('hardening_xmlrpc_rate_limit_max_attempts', 20));
    $lock = max(1, (int) tk_get_option('hardening_xmlrpc_rate_limit_lockout_minutes', 30));

    $key = tk_xmlrpc_rate_limit_key();
    $data = get_transient($key);
    if (!is_array($data)) {
        $data = array(
            'count' => 0,
            'start' => time(),
        );
    }

    if (time() - $data['start'] > ($window * MINUTE_IN_SECONDS)) {
        $data = array(
            'count' => 0,
            'start' => time(),
        );
    }

    $data['count']++;
    set_transient($key, $data, $window * MINUTE_IN_SECONDS);

    if ($data['count'] >= $max) {
        set_transient(tk_xmlrpc_rate_limit_lock_key(), 1, $lock * MINUTE_IN_SECONDS);
    }
}

function tk_hardening_waf(): void {
    if (defined('WP_CLI') && WP_CLI) {
        return;
    }
    if (function_exists('wp_doing_cron') && wp_doing_cron()) {
        return;
    }
    // Skip wp-admin requests to avoid blocking legitimate editor and settings saves.
    if (is_admin()) {
        return;
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $query = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
    $methods_value = tk_get_option('hardening_waf_check_methods', '');
    $methods = $methods_value !== '' ? array_map('trim', explode(',', $methods_value)) : array('GET', 'POST');
    $methods = array_filter(array_map('strtoupper', $methods));
    if (!empty($methods) && !in_array($method, $methods, true)) {
        return;
    }
    $allow_paths = tk_get_option('hardening_waf_allow_paths', '');
    if (is_string($allow_paths) && $allow_paths !== '') {
        $lines = preg_split('/\r\n|\r|\n/', $allow_paths);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (stripos($request_uri, $line) !== false) {
                return;
            }
        }
    }
    $allow_regex = tk_get_option('hardening_waf_allow_regex', '');
    if (is_string($allow_regex) && $allow_regex !== '') {
        $lines = preg_split('/\r\n|\r|\n/', $allow_regex);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $result = @preg_match($line, $request_uri);
            if ($result === 1) {
                return;
            }
        }
    }

    $payload = $request_uri . "\n" . $query;
    if (!empty($_POST)) {
        $payload .= "\n" . wp_json_encode($_POST);
    }

    $payload = strtolower($payload);

    $patterns = array(
        '/\bunion\b.+\bselect\b/',
        '/\bselect\b.+\bfrom\b/',
        '/\bsleep\(\d+\)/',
        '/\bbenchmark\(\d+,\s*.+\)/',
        '/\bload_file\(/',
        '/\binformation_schema\b/',
        '/\bgroup_concat\b/',
        '/\btable_name\b/',
        '/\bcolumn_name\b/',
        '/\bdatabase\(\)/',
        '/\bschema\(\)/',
        '/\bdrop\s+table\b/',
        '/\btruncate\s+table\b/',
        '/\.\.\//',
        '/%2e%2e%2f/',
        '/<script\b/',
        '/%3cscript\b/',
        '/\bwp-config\.php\b/',
        '/\/etc\/passwd\b/',
        '/\bbase64_decode\(/',
    );
    $patterns = apply_filters('tk_hardening_waf_patterns', $patterns, $payload);
    foreach ($patterns as $pattern) {
        if (@preg_match($pattern, $payload)) {
            tk_log('WAF blocked request: ' . $method . ' ' . $request_uri . ' pattern=' . $pattern);
            if (tk_get_option('hardening_waf_log_to_file', 0)) {
                tk_hardening_waf_log_to_file($method, $request_uri, $pattern);
            }
            wp_die('Request blocked by WAF.', 'Forbidden', array('response' => 403));
        }
    }

    do_action('tk_hardening_waf_checked', $method, $request_uri);
}

function tk_hardening_waf_log_to_file(string $method, string $request_uri, string $pattern): void {
    $upload = wp_upload_dir();
    $dir = trailingslashit($upload['basedir']) . 'tool-kits-logs/';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    $path = $dir . 'waf.log';
    $max_kb = (int) tk_get_option('hardening_waf_log_max_kb', 1024);
    if ($max_kb <= 0) {
        $max_kb = 1024;
    }
    $max_bytes = $max_kb * 1024;
    $max_files = (int) tk_get_option('hardening_waf_log_max_files', 3);
    if ($max_files < 1) {
        $max_files = 1;
    }
    $compress = tk_get_option('hardening_waf_log_compress', 0) ? true : false;
    $compress_min_kb = (int) tk_get_option('hardening_waf_log_compress_min_kb', 256);
    if ($compress_min_kb < 0) {
        $compress_min_kb = 0;
    }
    if (file_exists($path)) {
        $size = @filesize($path);
        if ($size !== false && $size > $max_bytes) {
            for ($i = $max_files - 1; $i >= 1; $i--) {
                $from = $dir . 'waf.log.' . $i;
                $to = $dir . 'waf.log.' . ($i + 1);
                $from_gz = $from . '.gz';
                $to_gz = $to . '.gz';
                if (file_exists($from)) {
                    @rename($from, $to);
                }
                if (file_exists($from_gz)) {
                    @rename($from_gz, $to_gz);
                }
            }
            $rotated = $dir . 'waf.log.1';
            @rename($path, $rotated);
            if ($compress && file_exists($rotated)) {
                $rotated_size = @filesize($rotated);
                $min_bytes = $compress_min_kb * 1024;
                if ($rotated_size !== false && $rotated_size >= $min_bytes) {
                    tk_hardening_waf_compress_log($rotated);
                }
            }
        }
    }
    $line = sprintf(
        "[%s] %s %s pattern=%s\n",
        gmdate('c'),
        $method,
        $request_uri,
        $pattern
    );
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    tk_hardening_waf_cleanup_logs($dir);
}

function tk_hardening_waf_compress_log(string $path): void {
    if (!function_exists('gzopen')) {
        return;
    }
    $gz_path = $path . '.gz';
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return;
    }
    $gz = @gzopen($gz_path, 'wb6');
    if (!$gz) {
        return;
    }
    $ok = @gzwrite($gz, $raw);
    @gzclose($gz);
    if ($ok !== false) {
        @unlink($path);
    }
}

function tk_hardening_waf_cleanup_logs(string $dir): void {
    $keep_days = (int) tk_get_option('hardening_waf_log_keep_days', 14);
    if ($keep_days <= 0) {
        return;
    }
    $cutoff = time() - ($keep_days * DAY_IN_SECONDS);
    $files = glob(trailingslashit($dir) . 'waf.log*');
    if (!is_array($files)) {
        return;
    }
    foreach ($files as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime < $cutoff) {
            @unlink($file);
        }
    }
}

function tk_hardening_waf_cleanup_cron(): void {
    $upload = wp_upload_dir();
    $dir = trailingslashit($upload['basedir']) . 'tool-kits-logs/';
    if (!file_exists($dir)) {
        return;
    }
    tk_hardening_waf_cleanup_logs($dir);
}

function tk_hardening_waf_schedule_cleanup(): void {
    if (!wp_next_scheduled('tk_hardening_waf_cleanup')) {
        $schedule = tk_hardening_waf_get_schedule();
        wp_schedule_event(time() + HOUR_IN_SECONDS, $schedule, 'tk_hardening_waf_cleanup');
    }
}

function tk_hardening_waf_clear_cleanup(): void {
    $timestamp = wp_next_scheduled('tk_hardening_waf_cleanup');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'tk_hardening_waf_cleanup');
    }
}

function tk_hardening_waf_get_schedule(): string {
    $value = tk_get_option('hardening_waf_log_schedule', 'daily');
    $map = array(
        'hourly' => 'tk_hourly',
        'twice_daily' => 'tk_twice_daily',
        'daily' => 'tk_daily',
    );
    return isset($map[$value]) ? $map[$value] : 'tk_daily';
}

function tk_define_disallow_file_edit() {
    if (!defined('DISALLOW_FILE_EDIT')) {
        define('DISALLOW_FILE_EDIT', true);
    }
}

function tk_disable_file_editor_caps($allcaps, $caps, $args, $user) {
    $deny = array('edit_themes', 'edit_plugins', 'edit_files');
    foreach ($deny as $cap) {
        if (isset($allcaps[$cap])) {
            $allcaps[$cap] = false;
        }
    }
    return $allcaps;
}

function tk_hardening_calculate_score() {
    $rules = array(
        'hardening_disable_file_editor' => array('weight' => 10, 'default' => 1),
        'hardening_disable_rest_user_enum' => array('weight' => 8, 'default' => 1),
        'hardening_disable_pingbacks' => array('weight' => 5, 'default' => 1),
        'hardening_hide_wp_version' => array('weight' => 5, 'default' => 1),
        'hardening_block_uploads_php' => array('weight' => 15, 'default' => 1),
        'hardening_block_unwanted_files_enabled' => array('weight' => 7, 'default' => 1),
        'hardening_block_plugin_installs' => array('weight' => 10, 'default' => 1),
        'hardening_security_headers' => array('weight' => 10, 'default' => 1),
        'hardening_waf_enabled' => array('weight' => 15, 'default' => 0),
        'hardening_disable_xmlrpc' => array('weight' => 10, 'default' => 1),
        'hardening_browser_cache_enabled' => array('weight' => 10, 'default' => 1),
        'hardening_httpauth_enabled' => array('weight' => 5, 'default' => 0),
    );
    
    $score = 0;
    $total_possible = 0;
    foreach ($rules as $rule) { $total_possible += $rule['weight']; }
    
    $active_rules = array();
    foreach ($rules as $opt_key => $data) {
        if (tk_get_option($opt_key, $data['default'])) {
            $score += $data['weight'];
            $active_rules[] = $opt_key;
        }
    }
    
    // Normalize to 100
    $final_score = ($total_possible > 0) ? round(($score / $total_possible) * 100) : 0;
    
    $labels = array(
        'hardening_disable_file_editor' => __('File Editor Protection', 'tool-kits'),
        'hardening_disable_rest_user_enum' => __('REST API Hardening', 'tool-kits'),
        'hardening_disable_pingbacks' => __('Pingback Protection', 'tool-kits'),
        'hardening_hide_wp_version' => __('WP Version Masking', 'tool-kits'),
        'hardening_block_uploads_php' => __('Uploads Folder Security', 'tool-kits'),
        'hardening_block_unwanted_files_enabled' => __('System Files Protection', 'tool-kits'),
        'hardening_block_plugin_installs' => __('Plugin Lock Down', 'tool-kits'),
        'hardening_security_headers' => __('Security Headers', 'tool-kits'),
        'hardening_waf_enabled' => __('Web Application Firewall', 'tool-kits'),
        'hardening_disable_xmlrpc' => __('XML-RPC Protection', 'tool-kits'),
        'hardening_browser_cache_enabled' => __('Browser Caching Rules', 'tool-kits'),
        'hardening_httpauth_enabled' => __('HTTP Authentication', 'tool-kits'),
    );

    $base_url = tk_admin_url(tk_hardening_page_slug());
    $links = array(
        'hardening_disable_file_editor' => $base_url . '#section-file_editor',
        'hardening_disable_rest_user_enum' => $base_url . '#section-rest_user_enum',
        'hardening_disable_pingbacks' => $base_url . '#section-pingbacks',
        'hardening_hide_wp_version' => $base_url . '#section-hide_wp_version',
        'hardening_block_uploads_php' => $base_url . '#section-block_uploads_php',
        'hardening_block_unwanted_files_enabled' => $base_url . '#section-block_unwanted_files',
        'hardening_block_plugin_installs' => $base_url . '#section-block_plugin_installs',
        'hardening_security_headers' => $base_url . '#section-headers',
        'hardening_waf_enabled' => $base_url . '#section-waf_enabled',
        'hardening_disable_xmlrpc' => $base_url . '#section-xmlrpc',
        'hardening_httpauth_enabled' => $base_url . '#section-httpauth_enabled',
    );

    return array(
        'score' => (int) $final_score,
        'active_count' => count($active_rules),
        'total_count' => count($rules),
        'active_rules' => $active_rules,
        'all_rules' => array_map(function($r){ return $r['weight']; }, $rules),
        'labels' => $labels,
        'links' => $links
    );
}

function tk_hardening_get_recommendations() {
    $base_url = tk_admin_url(tk_hardening_page_slug());
    $rules = array(
        'hardening_disable_file_editor' => array('weight' => 10, 'default' => 1, 'label' => 'Disable File Editor', 'link' => $base_url . '#section-file_editor'),
        'hardening_disable_rest_user_enum' => array('weight' => 8, 'default' => 1, 'label' => 'Block REST User Enumeration', 'link' => $base_url . '#section-rest_user_enum'),
        'hardening_disable_pingbacks' => array('weight' => 5, 'default' => 1, 'label' => 'Disable Pingbacks', 'link' => $base_url . '#section-pingbacks'),
        'hardening_hide_wp_version' => array('weight' => 5, 'default' => 1, 'label' => 'Hide WP Version', 'link' => $base_url . '#section-hide_wp_version'),
        'hardening_block_uploads_php' => array('weight' => 15, 'default' => 1, 'label' => 'Block PHP in Uploads', 'link' => $base_url . '#section-block_uploads_php'),
        'hardening_block_unwanted_files_enabled' => array('weight' => 7, 'default' => 1, 'label' => 'Block System Files', 'link' => $base_url . '#section-block_unwanted_files'),
        'hardening_block_plugin_installs' => array('weight' => 10, 'default' => 1, 'label' => 'Block Plugin Installations', 'link' => $base_url . '#section-block_plugin_installs'),
        'hardening_security_headers' => array('weight' => 10, 'default' => 1, 'label' => 'Enable Security Headers', 'link' => $base_url . '#section-headers'),
        'hardening_waf_enabled' => array('weight' => 15, 'default' => 0, 'label' => 'Enable WAF', 'link' => $base_url . '#section-waf_enabled'),
        'hardening_disable_xmlrpc' => array('weight' => 10, 'default' => 1, 'label' => 'Disable XML-RPC', 'link' => $base_url . '#section-xmlrpc'),
        'hardening_browser_cache_enabled' => array('weight' => 10, 'default' => 1, 'label' => 'Enable Browser Caching', 'link' => $base_url . '#section-browser_cache'),
        'hardening_httpauth_enabled' => array('weight' => 5, 'default' => 0, 'label' => 'Enable HTTP Auth', 'link' => $base_url . '#section-httpauth_enabled'),
    );
    
    $total_possible = 0;
    foreach ($rules as $rule) {
        $total_possible += $rule['weight'];
    }
    
    $recommendations = array();
    foreach ($rules as $opt_key => $data) {
        if (!tk_get_option($opt_key, $data['default'])) {
            $recommendations[] = array(
                'label' => $data['label'],
                'weight' => round(($data['weight'] / $total_possible) * 100),
                'link' => $data['link']
            );
        }
    }
    
    usort($recommendations, function($a, $b) {
        return $b['weight'] <=> $a['weight'];
    });
    
    return $recommendations;
}

function tk_render_hardening_page() {
    if (!tk_is_admin_user()) return;

    $opts = array(
        'hardening_disable_file_editor' => tk_get_option('hardening_disable_file_editor', 1),
        'hardening_disable_xmlrpc' => tk_get_option('hardening_disable_xmlrpc', 1),
        'hardening_disable_rest_user_enum' => tk_get_option('hardening_disable_rest_user_enum', 1),
        'hardening_security_headers' => tk_get_option('hardening_security_headers', 1),
        'hardening_disable_pingbacks' => tk_get_option('hardening_disable_pingbacks', 1),
        'hardening_cors_allowed_origins' => tk_get_option('hardening_cors_allowed_origins', ''),
        'hardening_cors_custom_origins_enabled' => tk_get_option('hardening_cors_custom_origins_enabled', 0),
        'hardening_cors_allow_credentials' => tk_get_option('hardening_cors_allow_credentials', 0),
        'hardening_cors_allowed_methods' => tk_get_option('hardening_cors_allowed_methods', ''),
        'hardening_cors_allowed_headers' => tk_get_option('hardening_cors_allowed_headers', ''),
        'hardening_xmlrpc_block_methods' => tk_get_option('hardening_xmlrpc_block_methods', 1),
        'hardening_xmlrpc_blocked_methods' => tk_get_option('hardening_xmlrpc_blocked_methods', ''),
        'hardening_xmlrpc_rate_limit_enabled' => tk_get_option('hardening_xmlrpc_rate_limit_enabled', 0),
        'hardening_xmlrpc_rate_limit_window_minutes' => tk_get_option('hardening_xmlrpc_rate_limit_window_minutes', 10),
        'hardening_xmlrpc_rate_limit_max_attempts' => tk_get_option('hardening_xmlrpc_rate_limit_max_attempts', 20),
        'hardening_xmlrpc_rate_limit_lockout_minutes' => tk_get_option('hardening_xmlrpc_rate_limit_lockout_minutes', 30),
        'hardening_waf_enabled' => tk_get_option('hardening_waf_enabled', 0),
        'hardening_waf_allow_paths' => tk_get_option('hardening_waf_allow_paths', ''),
        'hardening_waf_allow_regex' => tk_get_option('hardening_waf_allow_regex', ''),
        'hardening_waf_check_methods' => tk_get_option('hardening_waf_check_methods', 'GET, POST'),
        'hardening_waf_log_to_file' => tk_get_option('hardening_waf_log_to_file', 0),
        'hardening_waf_log_max_kb' => tk_get_option('hardening_waf_log_max_kb', 1024),
        'hardening_waf_log_max_files' => tk_get_option('hardening_waf_log_max_files', 3),
        'hardening_waf_log_compress' => tk_get_option('hardening_waf_log_compress', 0),
        'hardening_waf_log_compress_min_kb' => tk_get_option('hardening_waf_log_compress_min_kb', 256),
        'hardening_waf_log_keep_days' => tk_get_option('hardening_waf_log_keep_days', 14),
        'hardening_waf_log_schedule' => tk_get_option('hardening_waf_log_schedule', 'daily'),
        'hardening_httpauth_enabled' => tk_get_option('hardening_httpauth_enabled', 0),
        'hardening_httpauth_user' => tk_get_option('hardening_httpauth_user', ''),
        'hardening_httpauth_scope' => tk_get_option('hardening_httpauth_scope', 'both'),
        'hardening_httpauth_allow_paths' => tk_get_option('hardening_httpauth_allow_paths', ''),
        'hardening_httpauth_allow_regex' => tk_get_option('hardening_httpauth_allow_regex', ''),
        'hardening_disable_comments' => tk_get_option('hardening_disable_comments', 0),
        'hardening_server_aware_enabled' => tk_get_option('hardening_server_aware_enabled', 1),
        'hardening_block_uploads_php' => tk_get_option('hardening_block_uploads_php', 1),
        'hardening_csp_lite_enabled' => tk_get_option('hardening_csp_lite_enabled', 0),
        'hardening_csp_balanced_enabled' => tk_get_option('hardening_csp_balanced_enabled', 0),
        'hardening_csp_hardened_enabled' => tk_get_option('hardening_csp_hardened_enabled', 0),
        'hardening_csp_strict_enabled' => tk_get_option('hardening_csp_strict_enabled', 0),
        'hardening_csp_script_sources' => tk_get_option('hardening_csp_script_sources', ''),
        'hardening_csp_style_sources' => tk_get_option('hardening_csp_style_sources', ''),
        'hardening_csp_connect_sources' => tk_get_option('hardening_csp_connect_sources', ''),
        'hardening_csp_frame_sources' => tk_get_option('hardening_csp_frame_sources', ''),
        'hardening_csp_img_sources' => tk_get_option('hardening_csp_img_sources', ''),
        'hardening_hsts_enabled' => tk_get_option('hardening_hsts_enabled', 0),
        'hardening_hsts_preload' => tk_get_option('hardening_hsts_preload', 0),
        'hardening_server_signature_hide' => tk_get_option('hardening_server_signature_hide', 1),
        'hardening_cookie_httponly_force' => tk_get_option('hardening_cookie_httponly_force', 0),
        'hardening_disable_wp_cron' => tk_get_option('hardening_disable_wp_cron', 0),
        'hardening_url_param_guard_enabled' => tk_get_option('hardening_url_param_guard_enabled', 0),
        'hardening_http_methods_filter_enabled' => tk_get_option('hardening_http_methods_filter_enabled', 0),
        'hardening_http_methods_allowed' => tk_get_option('hardening_http_methods_allowed', 'GET, POST'),
        'hardening_http_methods_allow_paths' => tk_get_option('hardening_http_methods_allow_paths', "/wp-json/\n/wp-admin/admin-ajax.php\n/wp-cron.php"),
        'hardening_block_dangerous_methods_enabled' => tk_get_option('hardening_block_dangerous_methods_enabled', 1),
        'hardening_dangerous_http_methods' => tk_get_option('hardening_dangerous_http_methods', 'PUT, DELETE, TRACE, CONNECT'),
        'hardening_dangerous_methods_allow_paths' => tk_get_option('hardening_dangerous_methods_allow_paths', "/wp-json/\n/wp-admin/admin-ajax.php\n/wp-cron.php"),
        'hardening_robots_txt_hardened' => tk_get_option('hardening_robots_txt_hardened', 0),
        'hardening_block_unwanted_files_enabled' => tk_get_option('hardening_block_unwanted_files_enabled', 1),
        'hardening_unwanted_file_names' => tk_get_option('hardening_unwanted_file_names', '.ds_store, thumbs.db, phpinfo.php, error_log, debug.log'),
        'hardening_mysql_exposure_check_enabled' => tk_get_option('hardening_mysql_exposure_check_enabled', 1),
        'hardening_mysql_allow_public_host' => tk_get_option('hardening_mysql_allow_public_host', 0),
        'hardening_block_plugin_installs' => tk_get_option('hardening_block_plugin_installs', 1),
        'hardening_browser_cache_enabled' => tk_get_option('hardening_browser_cache_enabled', 1),
        'hardening_hide_wp_version' => tk_get_option('hardening_hide_wp_version', 1),
        'hardening_clean_wp_head' => tk_get_option('hardening_clean_wp_head', 0),
    );
    $csp_mode = 'off';
    if (!empty($opts['hardening_csp_strict_enabled'])) {
        $csp_mode = 'strict';
    } elseif (!empty($opts['hardening_csp_hardened_enabled'])) {
        $csp_mode = 'hardened';
    } elseif (!empty($opts['hardening_csp_balanced_enabled'])) {
        $csp_mode = 'balanced';
    } elseif (!empty($opts['hardening_csp_lite_enabled'])) {
        $csp_mode = 'lite';
    }
    ?>
    <div class="wrap tk-wrap">
        <?php tk_render_header_branding(); ?>
        <?php tk_render_page_hero(__('Security Hardening', 'tool-kits'), __('Strengthen your WordPress installation with industry-standard security protocols and rules.', 'tool-kits'), 'dashicons-shield-alt'); ?>
        <div class="tk-tabs">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button is-active" data-panel="active">Security Score</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="general">General</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="xmlrpc">XML-RPC</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="waf">WAF</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="httpauth">HTTP Auth</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="cors">CORS</button>
            </div>
            <div class="tk-tabs-content">
                <div class="tk-card tk-tab-panel is-active" data-panel-id="active">
                    <?php
                    $score_data = tk_hardening_calculate_score();
                    $score = $score_data['score'];
                    $active_items = tk_hardening_active_items();
                    $recommendations = tk_hardening_get_recommendations();
                    $dash = round(2 * pi() * 60); // Circumference for r=60
                    $offset = $dash - ($dash * ($score / 100));
                    
                    $score_color = ($score >= 80) ? '#27ae60' : (($score >= 50) ? '#f39c12' : '#e74c3c');
                    ?>
                    
                    <div class="tk-score-wrap">
                        <div class="tk-score-circle">
                            <svg width="140" height="140">
                                <circle class="bg" cx="70" cy="70" r="60"></circle>
                                <circle class="fg" cx="70" cy="70" r="60" style="stroke-dasharray: <?php echo $dash; ?>; stroke-dashoffset: <?php echo $offset; ?>;"></circle>
                            </svg>
                            <div class="tk-score-text" style="color: <?php echo $score_color; ?>;">
                                <div class="tk-score-value"><?php echo $score; ?>%</div>
                                <div class="tk-score-label">Secure</div>
                            </div>
                        </div>
                        <h3>Security Hardening Score</h3>
                        <p class="description">
                            <?php printf(__('Your score is based on %d active security rules.', 'tool-kits'), count($active_items)); ?>
                            <?php if (empty($recommendations)) : ?>
                                <span style="color:#27ae60; font-weight:600; margin-left:8px; display:inline-flex; align-items:center; gap:4px;">
                                    <span class="dashicons dashicons-shield" style="font-size:16px; width:16px; height:16px;"></span>
                                    <?php _e('All recommended rules are active.', 'tool-kits'); ?>
                                </span>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="tk-score-sections" style="margin-top:30px;">
                        <div class="tk-score-tabs-nav" style="display:flex; gap:8px; margin-bottom:20px; border-bottom:1px solid var(--tk-border-soft); padding-bottom:0;">
                            <?php if (!empty($active_items)) : ?>
                            <button type="button" class="tk-score-tab-btn is-active" data-score-tab="active" style="padding:12px 20px; border:none; background:none; font-weight:600; cursor:pointer; color:var(--tk-primary); border-bottom:2px solid var(--tk-primary); transition:all 0.2s;">
                                <?php _e('Active Protections', 'tool-kits'); ?> <span style="margin-left:6px; opacity:0.6; font-weight:400; font-size:12px;">(<?php echo count($active_items); ?>)</span>
                            </button>
                            <?php endif; ?>
                            <?php if (!empty($recommendations)) : ?>
                            <button type="button" class="tk-score-tab-btn <?php echo empty($active_items) ? 'is-active' : ''; ?>" data-score-tab="recommendations" style="padding:12px 20px; border:none; background:none; font-weight:600; cursor:pointer; color:var(--tk-muted); border-bottom:2px solid transparent; transition:all 0.2s;">
                                <?php _e('Recommendations', 'tool-kits'); ?> <span style="margin-left:6px; opacity:0.6; font-weight:400; font-size:12px;">(<?php echo count($recommendations); ?>)</span>
                            </button>
                            <?php endif; ?>
                        </div>

                        <div class="tk-score-tabs-content">
                            <?php if (!empty($active_items)) : ?>
                            <div class="tk-score-tab-panel is-active" data-score-panel="active" style="animation: tk-fade-in 0.3s ease;">
                                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap:12px;">
                                    <?php foreach ($active_items as $item) : 
                                        $link = $item['link'];
                                        if (strpos($link, '#') !== false) {
                                            $parts = explode('#', $link);
                                            $link = '#' . end($parts);
                                        }
                                    ?>
                                        <a href="<?php echo esc_attr($link); ?>" class="tk-score-item-link" style="background:rgba(39, 174, 96, 0.05); border:1px solid rgba(39, 174, 96, 0.2); padding:14px 18px; border-radius:12px; display:flex; align-items:center; gap:12px; font-size:13px; color:#27ae60; font-weight:500; text-decoration:none; transition:all 0.2s ease;">
                                            <span class="dashicons dashicons-yes-alt" style="font-size:18px; width:18px; height:18px;"></span>
                                            <?php echo esc_html($item['label']); ?>
                                            <span class="dashicons dashicons-arrow-right-alt2" style="margin-left:auto; font-size:16px; width:16px; height:16px; opacity:0.5;"></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($recommendations)) : ?>
                            <div class="tk-score-tab-panel <?php echo empty($active_items) ? 'is-active' : ''; ?>" data-score-panel="recommendations" style="display:<?php echo !empty($active_items) ? 'none' : 'block'; ?>; animation: tk-fade-in 0.3s ease;">
                                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap:12px;">
                                    <?php foreach ($recommendations as $rec) : 
                                        $link = $rec['link'];
                                        if (strpos($link, '#') !== false) {
                                            $parts = explode('#', $link);
                                            $link = '#' . end($parts);
                                        }
                                    ?>
                                        <a href="<?php echo esc_attr($link); ?>" class="tk-score-item-link" style="background:rgba(231, 76, 60, 0.05); border:1px solid rgba(231, 76, 60, 0.2); padding:14px 18px; border-radius:12px; display:flex; align-items:center; gap:12px; font-size:13px; color:#c0392b; font-weight:500; text-decoration:none; transition:all 0.2s ease;">
                                             <span class="dashicons dashicons-warning" style="font-size:18px; width:18px; height:18px;"></span>
                                             <?php echo esc_html($rec['label']); ?>
                                             <span style="margin-left:auto; font-size:11px; background:rgba(231, 76, 60, 0.1); padding:3px 8px; border-radius:12px; margin-right:4px;">+<?php echo $rec['weight']; ?>%</span>
                                             <span class="dashicons dashicons-arrow-right-alt2" style="font-size:16px; width:16px; height:16px; opacity:0.5;"></span>
                                         </a>
                                     <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="tk-card tk-tab-panel" data-panel-id="general">
                    <h2 style="margin-bottom:8px;">General Hardening</h2>
                    <p class="description" style="margin-bottom:24px;">Essential security toggles that protect your WordPress site from common attacks.</p>
                    
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_hardening_save'); ?>
                        <input type="hidden" name="action" value="tk_hardening_save">
                        <input type="hidden" name="tk_tab" value="general">

                        <?php 
                        tk_render_switch('file_editor', 'Disable theme/plugin file editor', 'Prevents hackers from editing your files via the WP dashboard.', $opts['hardening_disable_file_editor'], 'Disables the built-in theme/plugin editor. You will need FTP access.');
                        
                        tk_render_switch('rest_user_enum', 'Block REST API user enumeration', 'Prevents bots from scanning your site to find valid usernames.', $opts['hardening_disable_rest_user_enum']);
                        
                        tk_render_switch('pingbacks', 'Disable XML-RPC pingbacks', 'Prevents your site from being used in DDoS attacks against others.', $opts['hardening_disable_pingbacks']);
                        
                        tk_render_switch('hide_wp_version', 'Hide WordPress version', 'Removes the version number from your source code to slow down targeted attacks.', $opts['hardening_hide_wp_version']);
                        
                        tk_render_switch('block_uploads_php', 'Block PHP execution in uploads', 'Crucial! Prevents uploaded malicious files from being executed.', $opts['hardening_block_uploads_php']);
                        
                        tk_render_switch('block_unwanted_files', 'Block direct access to system files', 'Blocks access to .ds_store, error_log, and other sensitive filenames.', $opts['hardening_block_unwanted_files_enabled']);
                        
                        tk_render_switch('block_plugin_installs', 'Block plugin/theme installations', 'A hardcore lock that prevents any new plugin/theme installs until disabled.', $opts['hardening_block_plugin_installs']);
                        
                        tk_render_switch('browser_cache', 'Leverage browser caching', 'Adds expires and cache-control headers for static assets in .htaccess.', $opts['hardening_browser_cache_enabled'], 'Optimizes loading speed by telling browsers to keep CSS, JS, and images cached for 1 year.');
                        
                        if ($opts['hardening_browser_cache_enabled'] && tk_hardening_detect_server() === 'nginx') : ?>
                            <div style="margin: -10px 0 20px 60px; padding: 12px; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; font-size: 12px; color: #92400e;">
                                <strong>Nginx detected:</strong> Please add the following to your server block configuration and restart Nginx:<br>
                                <pre style="margin-top:8px; background:rgba(0,0,0,0.05); padding:8px; border-radius:4px; overflow:auto; font-family:monospace;">location ~* \.(jpg|jpeg|gif|png|webp|svg|woff|woff2|ttf|css|js|ico|pdf|zip|gz)$ {
    expires 1y;
    add_header Cache-Control "public, no-transform";
}</pre>
                            </div>
                        <?php endif; ?>

                        tk_render_switch('headers', 'Send security headers', 'Adds X-Frame-Options, X-XSS-Protection, and X-Content-Type-Options.', $opts['hardening_security_headers']);
                        ?>

                        <div style="margin-top:24px; padding:20px; background:var(--tk-bg-soft); border-radius:12px;">
                            <h3 style="margin:0 0 16px; font-size:16px;">Content Security Policy (CSP)</h3>
                            <div style="display:grid; grid-template-columns: 200px 1fr; gap:20px; align-items:center;">
                                <label style="font-weight:600;">CSP Protection Level</label>
                                <select name="csp_mode" style="width:100%; max-width:300px;">
                                    <option value="off" <?php selected('off', $csp_mode); ?>>Off</option>
                                    <option value="lite" <?php selected('lite', $csp_mode); ?>>Lite (Safe)</option>
                                    <option value="balanced" <?php selected('balanced', $csp_mode); ?>>Balanced</option>
                                    <option value="hardened" <?php selected('hardened', $csp_mode); ?>>Hardened</option>
                                    <option value="strict" <?php selected('strict', $csp_mode); ?>>Strict (Theme-dependent)</option>
                                </select>
                            </div>
                            <p class="description" style="margin-top:8px;">Balanced is recommended for most sites. Strict may break some plugins.</p>
                        </div>

                        <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                            <button class="button button-primary button-hero">Save Security Settings</button>
                        </div>
                    </form>
                </div>

                <div class="tk-card tk-tab-panel" data-panel-id="xmlrpc">
                    <h2 style="margin-bottom:8px;">XML-RPC Security</h2>
                    <p class="description" style="margin-bottom:24px;">XML-RPC is often exploited for brute-force attacks and DDoS reflection.</p>
                    
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_hardening_save'); ?>
                        <input type="hidden" name="action" value="tk_hardening_save">
                        <input type="hidden" name="tk_tab" value="xmlrpc">

                        <div style="display:flex; flex-direction:column; gap:12px;">
                            <?php 
                            tk_render_switch('xmlrpc', 'Completely Disable XML-RPC', 'The safest option if you do not use Jetpack or the WP Mobile App.', $opts['hardening_disable_xmlrpc']);
                            
                            tk_render_switch('xmlrpc_block_methods', 'Block Dangerous Methods Only', 'Blocks system.multicall and system.listMethods which are used in brute-force.', $opts['hardening_xmlrpc_block_methods']);
                            
                            tk_render_switch('xmlrpc_rate_limit_enabled', 'Enable XML-RPC Rate Limiting', 'Throttles repeated requests to prevent automated attacks.', $opts['hardening_xmlrpc_rate_limit_enabled']);
                            ?>
                        </div>

                        <div style="margin-top:24px; padding:20px; background:var(--tk-bg-soft); border-radius:12px;">
                            <label style="display:block; font-weight:600; margin-bottom:8px;">Blocked XML-RPC methods (one per line)</label>
                            <textarea class="large-text" rows="3" name="xmlrpc_blocked_methods" placeholder="system.multicall" style="width:100%; border-radius:8px;"><?php echo esc_textarea((string)$opts['hardening_xmlrpc_blocked_methods']); ?></textarea>
                            
                            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:16px; margin-top:20px;">
                                <div>
                                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Window (minutes)</label>
                                    <input type="number" name="xmlrpc_rate_limit_window" value="<?php echo esc_attr((string)$opts['hardening_xmlrpc_rate_limit_window_minutes']); ?>" style="width:100%; border-radius:8px;">
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Max Requests</label>
                                    <input type="number" name="xmlrpc_rate_limit_max" value="<?php echo esc_attr((string)$opts['hardening_xmlrpc_rate_limit_max_attempts']); ?>" style="width:100%; border-radius:8px;">
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Lockout (minutes)</label>
                                    <input type="number" name="xmlrpc_rate_limit_lock" value="<?php echo esc_attr((string)$opts['hardening_xmlrpc_rate_limit_lockout_minutes']); ?>" style="width:100%; border-radius:8px;">
                                </div>
                            </div>
                        </div>

                        <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                            <button class="button button-primary button-hero">Save XML-RPC Settings</button>
                        </div>
                    </form>
                </div>

                <div class="tk-card tk-tab-panel" data-panel-id="waf">
                    <h2 style="margin-bottom:8px;">Web Application Firewall (WAF)</h2>
                    <p class="description" style="margin-bottom:24px;">Our lightweight WAF filters incoming requests for SQL injection, XSS, and local file inclusion.</p>
                    
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_hardening_save'); ?>
                        <input type="hidden" name="action" value="tk_hardening_save">
                        <input type="hidden" name="tk_tab" value="waf">

                        <div style="display:flex; flex-direction:column; gap:12px;">
                            <?php 
                            tk_render_switch('waf_enabled', 'Enable Firewall Protection', 'Actively monitor and block malicious GET/POST requests.', $opts['hardening_waf_enabled']);
                            
                            tk_render_switch('waf_log_to_file', 'Log WAF Detections', 'Saves blocked attempts to a log file for review.', $opts['hardening_waf_log_to_file']);

                            tk_render_switch('waf_log_compress', 'Compress Log Files', 'Automatically gzip rotated logs to save disk space.', $opts['hardening_waf_log_compress']);
                            ?>
                        </div>

                        <div style="margin-top:24px; padding:20px; background:var(--tk-bg-soft); border-radius:12px; display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:8px;">Allowlisted Paths</label>
                                <textarea class="large-text" rows="4" name="waf_allow_paths" placeholder="/wp-json/
/wp-admin/admin-ajax.php" style="width:100%; border-radius:8px;"><?php echo esc_textarea((string)$opts['hardening_waf_allow_paths']); ?></textarea>
                            </div>
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:8px;">Check Methods</label>
                                <input type="text" class="regular-text" name="waf_check_methods" value="<?php echo esc_attr((string)$opts['hardening_waf_check_methods']); ?>" placeholder="GET, POST" style="width:100%; border-radius:8px;">
                                <div style="margin-top:16px;">
                                    <label style="display:block; font-weight:600; margin-bottom:8px;">Cleanup Schedule</label>
                                    <select name="waf_log_schedule" style="width:100%; border-radius:8px;">
                                        <option value="hourly" <?php selected('hourly', $opts['hardening_waf_log_schedule']); ?>>Hourly</option>
                                        <option value="twice_daily" <?php selected('twice_daily', $opts['hardening_waf_log_schedule']); ?>>Twice Daily</option>
                                        <option value="daily" <?php selected('daily', $opts['hardening_waf_log_schedule']); ?>>Daily</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                            <button class="button button-primary button-hero">Save WAF Settings</button>
                        </div>
                    </form>
                </div>

                <div class="tk-card tk-tab-panel" data-panel-id="httpauth">
                    <h2 style="margin-bottom:8px;">HTTP Authentication</h2>
                    <p class="description" style="margin-bottom:24px;">Add a Basic Auth password prompt before visitors can access the site.</p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_hardening_save'); ?>
                        <input type="hidden" name="action" value="tk_hardening_save">
                        <input type="hidden" name="tk_tab" value="httpauth">

                        <div style="display:flex; flex-direction:column; gap:12px;">
                            <?php
                            tk_render_switch('httpauth_enabled', 'Enable HTTP password protection', 'Turn this off to disable the browser username/password prompt.', $opts['hardening_httpauth_enabled']);
                            ?>
                        </div>

                        <div style="margin-top:24px; padding:20px; background:var(--tk-bg-soft); border-radius:12px; display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:8px;">Username</label>
                                <input type="text" class="regular-text" name="httpauth_user" value="<?php echo esc_attr((string)$opts['hardening_httpauth_user']); ?>" style="width:100%; border-radius:8px;" autocomplete="username">
                            </div>
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:8px;">Password</label>
                                <input type="password" class="regular-text" name="httpauth_pass" value="" placeholder="<?php echo esc_attr($opts['hardening_httpauth_enabled'] ? 'Leave blank to keep current password' : 'Set a password'); ?>" style="width:100%; border-radius:8px;" autocomplete="new-password">
                            </div>
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:8px;">Protection Scope</label>
                                <select name="httpauth_scope" style="width:100%; border-radius:8px;">
                                    <option value="both" <?php selected('both', $opts['hardening_httpauth_scope']); ?>>Frontend and admin</option>
                                    <option value="frontend" <?php selected('frontend', $opts['hardening_httpauth_scope']); ?>>Frontend only</option>
                                    <option value="admin" <?php selected('admin', $opts['hardening_httpauth_scope']); ?>>Admin and login only</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:8px;">Allowlisted Paths</label>
                                <textarea class="large-text" rows="4" name="httpauth_allow_paths" placeholder="/wp-cron.php
/wp-json/
/wp-admin/admin-ajax.php" style="width:100%; border-radius:8px;"><?php echo esc_textarea((string)$opts['hardening_httpauth_allow_paths']); ?></textarea>
                            </div>
                            <div style="grid-column:1 / -1;">
                                <label style="display:block; font-weight:600; margin-bottom:8px;">Allowlisted Regex</label>
                                <textarea class="large-text" rows="3" name="httpauth_allow_regex" placeholder="#^/custom-path/#" style="width:100%; border-radius:8px;"><?php echo esc_textarea((string)$opts['hardening_httpauth_allow_regex']); ?></textarea>
                                <p class="description" style="margin-top:8px;">One regular expression per line. Matching requests bypass HTTP authentication.</p>
                            </div>
                        </div>

                        <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                            <button class="button button-primary button-hero">Save HTTP Auth Settings</button>
                        </div>
                    </form>
                </div>

                <div class="tk-card tk-tab-panel" data-panel-id="cors">
                    <h2 style="margin-bottom:8px;">CORS</h2>
                    <p class="description" style="margin-bottom:24px;">Control which browser origins can access your WordPress responses with CORS headers.</p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_hardening_save'); ?>
                        <input type="hidden" name="action" value="tk_hardening_save">
                        <input type="hidden" name="tk_tab" value="cors">

                        <div style="display:flex; flex-direction:column; gap:12px;">
                            <?php
                            tk_render_switch('cors_custom_origins_enabled', 'Enable custom origin allowlist', 'Allow additional trusted origins beyond this site URL.', $opts['hardening_cors_custom_origins_enabled']);
                            tk_render_switch('cors_allow_credentials', 'Allow credentialed requests', 'Send Access-Control-Allow-Credentials for allowed origins.', $opts['hardening_cors_allow_credentials']);
                            ?>
                        </div>

                        <div style="margin-top:24px; padding:20px; background:var(--tk-bg-soft); border-radius:12px; display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                            <div style="grid-column:1 / -1;">
                                <label style="display:block; font-weight:600; margin-bottom:8px;">Allowed Origins</label>
                                <textarea class="large-text" rows="4" name="cors_allowed_origins" placeholder="https://app.example.com
https://staging.example.com" style="width:100%; border-radius:8px;"><?php echo esc_textarea((string)$opts['hardening_cors_allowed_origins']); ?></textarea>
                                <p class="description" style="margin-top:8px;">One origin per line. Include scheme and host, for example https://app.example.com.</p>
                            </div>
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:8px;">Allowed Methods</label>
                                <input type="text" class="regular-text" name="cors_allowed_methods" value="<?php echo esc_attr((string)$opts['hardening_cors_allowed_methods']); ?>" placeholder="GET, POST, OPTIONS" style="width:100%; border-radius:8px;">
                            </div>
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:8px;">Allowed Headers</label>
                                <input type="text" class="regular-text" name="cors_allowed_headers" value="<?php echo esc_attr((string)$opts['hardening_cors_allowed_headers']); ?>" placeholder="Authorization, X-WP-Nonce, Content-Type" style="width:100%; border-radius:8px;">
                            </div>
                        </div>

                        <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                            <button class="button button-primary button-hero">Save CORS Settings</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    $tab_script = "
    (function(){
        var wrapper = document.querySelector('.tk-tabs');
        if (!wrapper) return;

        function activateMainTab(panelId) {
            if (!panelId) return;
            var panel = wrapper.querySelector('.tk-tab-panel[data-panel-id=\"' + panelId + '\"]');
            if (!panel) return;
            
            wrapper.querySelectorAll('.tk-tab-panel').forEach(function(p){
                p.classList.toggle('is-active', p.getAttribute('data-panel-id') === panelId);
                p.style.display = (p.getAttribute('data-panel-id') === panelId) ? 'block' : 'none';
            });
            wrapper.querySelectorAll('.tk-tabs-nav-button').forEach(function(btn){
                btn.classList.toggle('is-active', btn.getAttribute('data-panel') === panelId);
            });
        }

        function activateSubTab(btn) {
            var tabId = btn.getAttribute('data-score-tab');
            var panel = btn.closest('.tk-tab-panel');
            if (!panel || !tabId) return;

            panel.querySelectorAll('.tk-score-tab-btn').forEach(function(b){
                var isActive = (b === btn);
                b.classList.toggle('is-active', isActive);
                b.style.color = isActive ? 'var(--tk-primary)' : 'var(--tk-muted)';
                b.style.borderBottomColor = isActive ? 'var(--tk-primary)' : 'transparent';
            });

            panel.querySelectorAll('.tk-score-tab-panel').forEach(function(p){
                p.style.display = (p.getAttribute('data-score-panel') === tabId) ? 'block' : 'none';
            });
        }

        wrapper.addEventListener('click', function(e){
            var mainBtn = e.target.closest('.tk-tabs-nav-button');
            if (mainBtn) {
                e.preventDefault();
                var panelId = mainBtn.getAttribute('data-panel');
                if (panelId) {
                    activateMainTab(panelId);
                    history.replaceState(null, null, '#' + panelId);
                }
                return;
            }

            var subBtn = e.target.closest('.tk-score-tab-btn');
            if (subBtn) {
                e.preventDefault();
                activateSubTab(subBtn);
                return;
            }
        });

        function handleHash() {
            var hash = window.location.hash.substring(1);
            if (!hash) { activateMainTab('active'); return; }

            // Priority 1: Direct panel match
            if (wrapper.querySelector('.tk-tab-panel[data-panel-id=\"' + hash + '\"]')) {
                activateMainTab(hash);
                return;
            }

            // Priority 2: Nested element match
            var target = document.getElementById(hash);
            if (target) {
                var parentPanel = target.closest('.tk-tab-panel');
                if (parentPanel) {
                    var panelId = parentPanel.getAttribute('data-panel-id');
                    activateMainTab(panelId);

                    // Handle sub-tabs if target is inside one
                    var subPanel = target.closest('.tk-score-tab-panel');
                    if (subPanel) {
                        var subId = subPanel.getAttribute('data-score-panel');
                        var subBtn = parentPanel.querySelector('.tk-score-tab-btn[data-score-tab=\"' + subId + '\"]');
                        if (subBtn) activateSubTab(subBtn);
                    }

                    setTimeout(function(){
                        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        target.style.transition = 'background 0.5s';
                        target.style.background = 'rgba(109, 74, 255, 0.1)';
                        setTimeout(function(){ target.style.background = 'transparent'; }, 2000);
                    }, 200);
                }
            }
        }

        window.addEventListener('hashchange', handleHash);
        handleHash();
    })();";
    tk_csp_print_inline_script($tab_script, array('id' => 'tk-hardening-tabs-js'));
}

function tk_hardening_save() {
    tk_require_admin_post('tk_hardening_save');
    tk_killswitch_snapshot('hardening');

    $tab = isset($_POST['tk_tab']) ? sanitize_key($_POST['tk_tab']) : '';
    if ($tab === 'general') {
        tk_update_option('hardening_disable_file_editor', !empty($_POST['file_editor']) ? 1 : 0);
        $disable_comments = !empty($_POST['disable_comments']) ? 1 : 0;
        tk_update_option('hardening_disable_comments', $disable_comments);
        if ($disable_comments) {
            update_option('default_comment_status', 'closed', false);
            update_option('default_ping_status', 'closed', false);
        }
        tk_update_option('hardening_disable_rest_user_enum', !empty($_POST['rest_user_enum']) ? 1 : 0);
        tk_update_option('hardening_hide_wp_version', !empty($_POST['hide_wp_version']) ? 1 : 0);
        tk_update_option('hardening_clean_wp_head', !empty($_POST['clean_wp_head']) ? 1 : 0);
        tk_update_option('hardening_security_headers', !empty($_POST['headers']) ? 1 : 0);
        $posted_csp_mode = isset($_POST['csp_mode']) ? sanitize_key($_POST['csp_mode']) : '';
        if (!in_array($posted_csp_mode, array('off', 'lite', 'balanced', 'hardened', 'strict'), true)) {
            $posted_csp_mode = 'off';
            if (!empty($_POST['csp_strict'])) {
                $posted_csp_mode = 'strict';
            } elseif (!empty($_POST['csp_hardened'])) {
                $posted_csp_mode = 'hardened';
            } elseif (!empty($_POST['csp_balanced'])) {
                $posted_csp_mode = 'balanced';
            } elseif (!empty($_POST['csp_lite'])) {
                $posted_csp_mode = 'lite';
            }
        }
        $csp_lite = $posted_csp_mode === 'lite' ? 1 : 0;
        $csp_balanced = $posted_csp_mode === 'balanced' ? 1 : 0;
        $csp_hardened = $posted_csp_mode === 'hardened' ? 1 : 0;
        $csp_strict = $posted_csp_mode === 'strict' ? 1 : 0;
        tk_update_option('hardening_csp_lite_enabled', $csp_lite);
        tk_update_option('hardening_csp_balanced_enabled', $csp_balanced);
        tk_update_option('hardening_csp_hardened_enabled', $csp_hardened);
        tk_update_option('hardening_csp_strict_enabled', $csp_strict);
        $csp_script_sources = isset($_POST['csp_script_sources']) ? wp_unslash($_POST['csp_script_sources']) : '';
        $csp_style_sources = isset($_POST['csp_style_sources']) ? wp_unslash($_POST['csp_style_sources']) : '';
        $csp_connect_sources = isset($_POST['csp_connect_sources']) ? wp_unslash($_POST['csp_connect_sources']) : '';
        $csp_frame_sources = isset($_POST['csp_frame_sources']) ? wp_unslash($_POST['csp_frame_sources']) : '';
        $csp_img_sources = isset($_POST['csp_img_sources']) ? wp_unslash($_POST['csp_img_sources']) : '';
        tk_update_option('hardening_csp_script_sources', tk_hardening_sanitize_csp_sources($csp_script_sources));
        tk_update_option('hardening_csp_style_sources', tk_hardening_sanitize_csp_sources($csp_style_sources));
        tk_update_option('hardening_csp_connect_sources', tk_hardening_sanitize_csp_sources($csp_connect_sources));
        tk_update_option('hardening_csp_frame_sources', tk_hardening_sanitize_csp_sources($csp_frame_sources));
        tk_update_option('hardening_csp_img_sources', tk_hardening_sanitize_csp_sources($csp_img_sources));
        tk_update_option('hardening_hsts_enabled', !empty($_POST['hsts']) ? 1 : 0);
        tk_update_option('hardening_hsts_preload', !empty($_POST['hsts_preload']) ? 1 : 0);
        tk_update_option('hardening_server_signature_hide', !empty($_POST['server_signature_hide']) ? 1 : 0);
        tk_update_option('hardening_cookie_httponly_force', !empty($_POST['cookie_httponly_force']) ? 1 : 0);
        $disable_wp_cron = !empty($_POST['disable_wp_cron']) ? 1 : 0;
        tk_update_option('hardening_disable_wp_cron', $disable_wp_cron);
        tk_hardening_set_wp_config_constant('DISABLE_WP_CRON', (bool) $disable_wp_cron);
        if (function_exists('tk_page_cache_purge')) {
            tk_page_cache_purge();
        }
        tk_update_option('hardening_url_param_guard_enabled', !empty($_POST['url_param_guard']) ? 1 : 0);
        tk_update_option('hardening_http_methods_filter_enabled', !empty($_POST['http_methods_filter']) ? 1 : 0);
        $http_methods_allowed = isset($_POST['http_methods_allowed']) ? wp_unslash($_POST['http_methods_allowed']) : 'GET, POST';
        $http_methods_allowed = is_string($http_methods_allowed) ? trim(sanitize_text_field($http_methods_allowed)) : 'GET, POST';
        tk_update_option('hardening_http_methods_allowed', $http_methods_allowed);
        $http_methods_allow_paths = isset($_POST['http_methods_allow_paths']) ? wp_unslash($_POST['http_methods_allow_paths']) : '';
        $http_methods_allow_paths = is_string($http_methods_allow_paths) ? trim($http_methods_allow_paths) : '';
        tk_update_option('hardening_http_methods_allow_paths', $http_methods_allow_paths);
        tk_update_option('hardening_block_dangerous_methods_enabled', !empty($_POST['block_dangerous_methods']) ? 1 : 0);
        $dangerous_http_methods = isset($_POST['dangerous_http_methods']) ? wp_unslash($_POST['dangerous_http_methods']) : 'PUT, DELETE, TRACE, CONNECT';
        $dangerous_http_methods = is_string($dangerous_http_methods) ? trim(sanitize_text_field($dangerous_http_methods)) : 'PUT, DELETE, TRACE, CONNECT';
        tk_update_option('hardening_dangerous_http_methods', $dangerous_http_methods);
        $dangerous_methods_allow_paths = isset($_POST['dangerous_methods_allow_paths']) ? wp_unslash($_POST['dangerous_methods_allow_paths']) : '';
        $dangerous_methods_allow_paths = is_string($dangerous_methods_allow_paths) ? trim($dangerous_methods_allow_paths) : '';
        tk_update_option('hardening_dangerous_methods_allow_paths', $dangerous_methods_allow_paths);
        tk_update_option('hardening_robots_txt_hardened', !empty($_POST['robots_txt_hardened']) ? 1 : 0);
        tk_update_option('hardening_block_unwanted_files_enabled', !empty($_POST['block_unwanted_files']) ? 1 : 0);
        $unwanted_file_names = isset($_POST['unwanted_file_names']) ? wp_unslash($_POST['unwanted_file_names']) : '';
        $unwanted_file_names = is_string($unwanted_file_names) ? trim(preg_replace('/[\r\n]+/', ', ', $unwanted_file_names)) : '';
        tk_update_option('hardening_unwanted_file_names', $unwanted_file_names);
        tk_update_option('hardening_mysql_exposure_check_enabled', !empty($_POST['mysql_exposure_check']) ? 1 : 0);
        tk_update_option('hardening_mysql_allow_public_host', !empty($_POST['mysql_allow_public_host']) ? 1 : 0);
        tk_update_option('hardening_server_aware_enabled', !empty($_POST['server_aware']) ? 1 : 0);
        tk_update_option('hardening_block_uploads_php', !empty($_POST['block_uploads_php']) ? 1 : 0);
        tk_update_option('hardening_block_plugin_installs', !empty($_POST['block_plugin_installs']) ? 1 : 0);
        tk_update_option('hardening_browser_cache_enabled', !empty($_POST['browser_cache']) ? 1 : 0);
        tk_update_option('hardening_disable_pingbacks', !empty($_POST['pingbacks']) ? 1 : 0);
        tk_hardening_apply_root_server_rules();
    } elseif ($tab === 'xmlrpc') {
        tk_update_option('hardening_disable_xmlrpc', !empty($_POST['xmlrpc']) ? 1 : 0);
        tk_update_option('hardening_xmlrpc_block_methods', !empty($_POST['xmlrpc_block_methods']) ? 1 : 0);
        $blocked = isset($_POST['xmlrpc_blocked_methods']) ? wp_unslash($_POST['xmlrpc_blocked_methods']) : '';
        $blocked = is_string($blocked) ? trim($blocked) : '';
        tk_update_option('hardening_xmlrpc_blocked_methods', $blocked);
        tk_update_option('hardening_xmlrpc_rate_limit_enabled', !empty($_POST['xmlrpc_rate_limit']) ? 1 : 0);
        tk_update_option('hardening_xmlrpc_rate_limit_window_minutes', isset($_POST['xmlrpc_rate_limit_window']) ? (int) $_POST['xmlrpc_rate_limit_window'] : 10);
        tk_update_option('hardening_xmlrpc_rate_limit_max_attempts', isset($_POST['xmlrpc_rate_limit_max']) ? (int) $_POST['xmlrpc_rate_limit_max'] : 20);
        tk_update_option('hardening_xmlrpc_rate_limit_lockout_minutes', isset($_POST['xmlrpc_rate_limit_lock']) ? (int) $_POST['xmlrpc_rate_limit_lock'] : 30);
    } elseif ($tab === 'waf') {
        tk_update_option('hardening_waf_enabled', !empty($_POST['waf_enabled']) ? 1 : 0);
        $waf_methods = isset($_POST['waf_check_methods']) ? wp_unslash($_POST['waf_check_methods']) : '';
        $waf_methods = is_string($waf_methods) ? trim(sanitize_text_field($waf_methods)) : '';
        tk_update_option('hardening_waf_check_methods', $waf_methods);
        $allow_paths = isset($_POST['waf_allow_paths']) ? wp_unslash($_POST['waf_allow_paths']) : '';
        $allow_paths = is_string($allow_paths) ? trim($allow_paths) : '';
        tk_update_option('hardening_waf_allow_paths', $allow_paths);
        $allow_regex = isset($_POST['waf_allow_regex']) ? wp_unslash($_POST['waf_allow_regex']) : '';
        $allow_regex = is_string($allow_regex) ? trim($allow_regex) : '';
        tk_update_option('hardening_waf_allow_regex', $allow_regex);
        $log_to_file = !empty($_POST['waf_log_to_file']) ? 1 : 0;
        tk_update_option('hardening_waf_log_to_file', $log_to_file);
        tk_update_option('hardening_waf_log_max_kb', isset($_POST['waf_log_max_kb']) ? (int) $_POST['waf_log_max_kb'] : 1024);
        tk_update_option('hardening_waf_log_max_files', isset($_POST['waf_log_max_files']) ? (int) $_POST['waf_log_max_files'] : 3);
        tk_update_option('hardening_waf_log_compress', !empty($_POST['waf_log_compress']) ? 1 : 0);
        tk_update_option('hardening_waf_log_compress_min_kb', isset($_POST['waf_log_compress_min_kb']) ? (int) $_POST['waf_log_compress_min_kb'] : 256);
        tk_update_option('hardening_waf_log_keep_days', isset($_POST['waf_log_keep_days']) ? (int) $_POST['waf_log_keep_days'] : 14);
        $schedule = isset($_POST['waf_log_schedule']) ? sanitize_key($_POST['waf_log_schedule']) : 'daily';
        if (!in_array($schedule, array('hourly', 'twice_daily', 'daily'), true)) {
            $schedule = 'daily';
        }
        tk_update_option('hardening_waf_log_schedule', $schedule);
        if ($log_to_file) {
            tk_hardening_waf_clear_cleanup();
            tk_hardening_waf_schedule_cleanup();
        } else {
            tk_hardening_waf_clear_cleanup();
        }
    } elseif ($tab === 'httpauth') {
        $httpauth_enabled = !empty($_POST['httpauth_enabled']) ? 1 : 0;
        tk_update_option('hardening_httpauth_enabled', $httpauth_enabled);
        $httpauth_user = isset($_POST['httpauth_user']) ? trim(sanitize_text_field(wp_unslash($_POST['httpauth_user']))) : '';
        tk_update_option('hardening_httpauth_user', $httpauth_user);
        $httpauth_pass = isset($_POST['httpauth_pass']) ? wp_unslash($_POST['httpauth_pass']) : '';
        if (is_string($httpauth_pass) && $httpauth_pass !== '') {
            tk_update_option('hardening_httpauth_pass', wp_hash_password($httpauth_pass));
        }
        $httpauth_scope = isset($_POST['httpauth_scope']) ? sanitize_key($_POST['httpauth_scope']) : 'both';
        if (!in_array($httpauth_scope, array('frontend', 'admin', 'both'), true)) {
            $httpauth_scope = 'both';
        }
        tk_update_option('hardening_httpauth_scope', $httpauth_scope);
        $allow_paths = isset($_POST['httpauth_allow_paths']) ? wp_unslash($_POST['httpauth_allow_paths']) : '';
        $allow_paths = is_string($allow_paths) ? trim($allow_paths) : '';
        tk_update_option('hardening_httpauth_allow_paths', $allow_paths);
        $allow_regex = isset($_POST['httpauth_allow_regex']) ? wp_unslash($_POST['httpauth_allow_regex']) : '';
        $allow_regex = is_string($allow_regex) ? trim($allow_regex) : '';
        tk_update_option('hardening_httpauth_allow_regex', $allow_regex);
    } elseif ($tab === 'cors') {
        tk_update_option('hardening_cors_custom_origins_enabled', !empty($_POST['cors_custom_origins_enabled']) ? 1 : 0);
        $origins = isset($_POST['cors_allowed_origins']) ? wp_unslash($_POST['cors_allowed_origins']) : '';
        $origins = is_string($origins) ? trim($origins) : '';
        tk_update_option('hardening_cors_allowed_origins', $origins);
        $methods = isset($_POST['cors_allowed_methods']) ? wp_unslash($_POST['cors_allowed_methods']) : '';
        $methods = is_string($methods) ? trim(sanitize_text_field($methods)) : '';
        tk_update_option('hardening_cors_allowed_methods', $methods);
        $headers = isset($_POST['cors_allowed_headers']) ? wp_unslash($_POST['cors_allowed_headers']) : '';
        $headers = is_string($headers) ? trim(sanitize_text_field($headers)) : '';
        tk_update_option('hardening_cors_allowed_headers', $headers);
        tk_update_option('hardening_cors_allow_credentials', !empty($_POST['cors_allow_credentials']) ? 1 : 0);
    }

    $redirect = add_query_arg(array('page'=>'tool-kits-guard','tk_saved'=>1), admin_url('admin.php'));
    if ($tab !== '') {
        $redirect .= '#' . $tab;
    }
    wp_safe_redirect($redirect);
    exit;
}

function tk_hardening_waf_reset() {
    tk_require_admin_post('tk_hardening_waf_reset');

    tk_update_option('hardening_waf_enabled', 0);
    tk_update_option('hardening_waf_allow_paths', '');
    tk_update_option('hardening_waf_allow_regex', '');
    tk_update_option('hardening_waf_check_methods', 'GET, POST');
    tk_update_option('hardening_waf_log_to_file', 0);
    tk_update_option('hardening_waf_log_max_kb', 1024);
    tk_update_option('hardening_waf_log_max_files', 3);
    tk_update_option('hardening_waf_log_compress', 0);
    tk_update_option('hardening_waf_log_compress_min_kb', 256);
    tk_update_option('hardening_waf_log_keep_days', 14);
    tk_update_option('hardening_waf_log_schedule', 'daily');
    tk_hardening_waf_clear_cleanup();

    $redirect = add_query_arg(array(
        'page' => 'tool-kits',
        'tk_waf_reset' => '1',
    ), admin_url('admin.php'));
    wp_safe_redirect($redirect);
    exit;
}

function tk_hardening_waf_cron_schedules($schedules) {
    $schedules['tk_hourly'] = array(
        'interval' => HOUR_IN_SECONDS,
        'display' => 'Hourly',
    );
    $schedules['tk_twice_daily'] = array(
        'interval' => 12 * HOUR_IN_SECONDS,
        'display' => 'Twice daily',
    );
    $schedules['tk_daily'] = array(
        'interval' => DAY_IN_SECONDS,
        'display' => 'Daily',
    );
    return $schedules;
}
