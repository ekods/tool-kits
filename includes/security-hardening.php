<?php
if (!defined('ABSPATH')) exit;

/**
 * Basic WP hardening
 */

function tk_hardening_init() {
    add_filter('cron_schedules', 'tk_hardening_waf_cron_schedules');
    tk_hardening_apply_recommended_defaults();
    add_filter('auto_update_core', 'tk_hardening_core_auto_updates', 10, 2);
    add_filter('allow_minor_auto_core_updates', 'tk_hardening_core_auto_updates');
    add_filter('allow_major_auto_core_updates', 'tk_hardening_core_auto_updates');
    if (tk_get_option('hardening_disable_xmlrpc', 1)) {
        add_filter('xmlrpc_enabled', '__return_false');
    }
    if (tk_get_option('hardening_block_plugin_installs', 1)) {
        add_filter('user_has_cap', 'tk_hardening_block_plugin_caps', 10, 4);
        add_filter('rest_pre_dispatch', 'tk_hardening_block_plugin_rest', 10, 3);
    }
    if (tk_get_option('hardening_httpauth_enabled', 0)) {
        add_action('init', 'tk_hardening_http_auth', 0);
    }
    if (tk_get_option('hardening_disable_comments', 0)) {
        add_action('init', 'tk_hardening_disable_comments');
    }
    if (tk_get_option('hardening_block_uploads_php', 1)) {
        add_action('init', 'tk_hardening_block_uploads_php', 1);
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
    }
    if (tk_get_option('hardening_disable_file_editor', 1)) {
        add_action('init', 'tk_define_disallow_file_edit');
        add_filter('user_has_cap', 'tk_disable_file_editor_caps', 10, 4);
    }
    if (tk_get_option('hardening_waf_enabled', 0)) {
        add_action('init', 'tk_hardening_waf', 1);
    }
    add_action('tk_hardening_waf_cleanup', 'tk_hardening_waf_cleanup_cron');
    add_action('admin_post_tk_hardening_save', 'tk_hardening_save');
    if (tk_get_option('hardening_waf_log_to_file', 0)) {
        tk_hardening_waf_schedule_cleanup();
    }
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
    if (tk_get_option('hardening_csp_lite_enabled', 0)) {
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https:; font-src 'self' data: https:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; script-src-elem 'self' 'unsafe-inline' https:; style-src 'self' 'unsafe-inline' https:; style-src-elem 'self' 'unsafe-inline' https:; connect-src 'self' https:; worker-src 'self' blob: https:; frame-src https:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    }
    if (tk_get_option('hardening_hsts_enabled', 0) && is_ssl()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
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
    if ($server === 'apache' || $server === 'litespeed' || $server === 'openlitespeed') {
        return "<IfModule mod_autoindex.c>\n  Options -Indexes\n</IfModule>\n<FilesMatch \"^\\.env|debug\\.log$\">\n  Require all denied\n</FilesMatch>\n<IfModule mod_rewrite.c>\n  RewriteEngine On\n  RewriteRule ^wp-content/uploads/.*\\.php$ - [F]\n</IfModule>";
    }
    if ($server === 'nginx') {
        return "autoindex off;\nlocation = /.env { deny all; }\nlocation = /wp-content/debug.log { deny all; }\nlocation ~* ^/wp-content/uploads/.*\\.php$ { deny all; }\nfastcgi_param HTTP_AUTHORIZATION $http_authorization;";
    }
    if ($server === 'caddy') {
        return "file_server {\n  browse off\n}\n@blocked path /.env /wp-content/debug.log\nrespond @blocked 403\n@uploadsPhp path_regexp uploadsPhp ^/wp-content/uploads/.*\\.php$\nrespond @uploadsPhp 403";
    }
    if ($server === 'iis') {
        return "<system.webServer>\n  <directoryBrowse enabled=\"false\" />\n  <security>\n    <requestFiltering>\n      <fileExtensions>\n        <add fileExtension=\".env\" allowed=\"false\" />\n        <add fileExtension=\".log\" allowed=\"false\" />\n        <add fileExtension=\".php\" allowed=\"false\" />\n      </fileExtensions>\n    </requestFiltering>\n  </security>\n</system.webServer>";
    }
    return '';
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
        if ($has_indexes && $has_env && $has_debug && $has_uploads) {
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
    add_action('admin_init', function() {
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
    });
    add_action('admin_menu', function() {
        remove_menu_page('edit-comments.php');
        remove_submenu_page('options-general.php', 'options-discussion.php');
    });
    add_action('admin_init', function() {
        global $pagenow;
        if ($pagenow === 'edit-comments.php' || $pagenow === 'comment.php') {
            wp_die('Comments are disabled.', 'Comments disabled', array('response' => 403));
        }
        if ($pagenow === 'options-discussion.php') {
            wp_die('Discussion settings are disabled.', 'Comments disabled', array('response' => 403));
        }
    });
    add_action('admin_bar_menu', function($wp_admin_bar) {
        $wp_admin_bar->remove_node('comments');
    }, 999);
    add_action('wp_before_admin_bar_render', function() {
        global $wp_admin_bar;
        if (is_object($wp_admin_bar)) {
            $wp_admin_bar->remove_menu('comments');
        }
    });
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
        return array('ok' => false, 'code' => 0, 'body' => '', 'error' => $response->get_error_message());
    }
    return array(
        'ok' => true,
        'code' => (int) wp_remote_retrieve_response_code($response),
        'body' => (string) wp_remote_retrieve_body($response),
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
        'action_url' => tk_admin_url('tool-kits-security-hardening') . '#general',
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
            'action_url' => tk_admin_url('tool-kits-security-hardening') . '#general',
        );
    } else {
        $checks[] = array(
            'label' => 'HSTS enabled',
            'status' => is_ssl() ? 'ok' : 'ok',
            'detail' => is_ssl() ? 'HTTPS detected.' : 'Not applicable (HTTP).',
            'action_label' => 'Hardening settings',
            'action_url' => tk_admin_url('tool-kits-security-hardening') . '#general',
        );
    }

    $uploads = wp_upload_dir();
    $uploads_path = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
    if ($uploads_path === '' || !is_dir($uploads_path)) {
        $checks[] = array(
            'label' => 'Uploads PHP execution',
            'status' => 'unknown',
            'detail' => 'Uploads directory not found.',
            'action_label' => 'Hardening settings',
            'action_url' => tk_admin_url('tool-kits-security-hardening') . '#general',
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
            'action_url' => tk_admin_url('tool-kits-security-hardening') . '#general',
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
            'action_url' => tk_admin_url('tool-kits-security-hardening') . '#general',
        );
    } else {
        $checks[] = array(
            'label' => 'Uploads PHP execution',
            'status' => 'unknown',
            'detail' => 'Check server config to block PHP in uploads.',
            'action_label' => 'Hardening settings',
            'action_url' => tk_admin_url('tool-kits-security-hardening') . '#general',
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
        'hardening_hsts_enabled' => tk_get_option('hardening_hsts_enabled', 0),
        'hardening_block_plugin_installs' => tk_get_option('hardening_block_plugin_installs', 1),
    );
    ?>
    <div class="wrap tk-wrap">
        <h1>Hardening</h1>
        <div class="tk-tabs">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button is-active" data-panel="active">Active Items</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="general">General</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="xmlrpc">XML-RPC</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="waf">WAF</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="httpauth">HTTP Auth</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="cors">CORS</button>
            </div>
            <div class="tk-tabs-content">
                <div class="tk-card tk-tab-panel is-active" data-panel-id="active">
                    <h2>Active Items</h2>
                    <?php
                    $active_items = tk_hardening_active_items();
                    if (!empty($active_items)) :
                    ?>
                        <ul class="tk-list">
                            <?php foreach ($active_items as $item) : ?>
                                <li>&#10003; <?php echo esc_html($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else : ?>
                        <p><small>No active items.</small></p>
                    <?php endif; ?>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="general">
                    <p>Toggle the most effective WordPress hardening toggles that can be flipped without touching core files.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_hardening_save'); ?>
                        <input type="hidden" name="action" value="tk_hardening_save">
                        <input type="hidden" name="tk_tab" value="general">

                        <p><label><input type="checkbox" name="file_editor" value="1" data-confirm="Disables the built-in theme/plugin editor. You will need FTP or file access to edit files." <?php checked(1, $opts['hardening_disable_file_editor']); ?>> Disable theme/plugin file editor</label></p>
                        <p class="description">Warning: requires FTP or file access to edit theme/plugin files.</p>
                        <p><label><input type="checkbox" name="disable_comments" value="1" <?php checked(1, $opts['hardening_disable_comments']); ?>> Disable comments site-wide</label></p>
                        <p><label><input type="checkbox" name="rest_user_enum" value="1" <?php checked(1, $opts['hardening_disable_rest_user_enum']); ?>> Disable REST user enumeration</label></p>
                        <p><label><input type="checkbox" name="headers" value="1" <?php checked(1, $opts['hardening_security_headers']); ?>> Send security headers</label></p>
                        <p><label><input type="checkbox" name="csp_lite" value="1" <?php checked(1, $opts['hardening_csp_lite_enabled']); ?>> Enable CSP lite header</label></p>
                        <p><label><input type="checkbox" name="hsts" value="1" <?php checked(1, $opts['hardening_hsts_enabled']); ?>> Enable HSTS header (HTTPS only)</label></p>
                        <p><label><input type="checkbox" name="server_aware" value="1" <?php checked(1, $opts['hardening_server_aware_enabled']); ?>> Enable server-aware rules</label></p>
                        <p><label><input type="checkbox" name="block_uploads_php" value="1" <?php checked(1, $opts['hardening_block_uploads_php']); ?>> Block PHP execution in uploads/ (Apache/LiteSpeed/IIS)</label></p>
                        <p><label><input type="checkbox" name="block_plugin_installs" value="1" <?php checked(1, $opts['hardening_block_plugin_installs']); ?>> Block plugin/theme install/update for non-admins</label></p>
                        <p><label><input type="checkbox" name="pingbacks" value="1" <?php checked(1, $opts['hardening_disable_pingbacks']); ?>> Disable XML-RPC pingbacks</label></p>

                        <p><button class="button button-primary">Save Hardening Settings</button></p>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="xmlrpc">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_hardening_save'); ?>
                        <input type="hidden" name="action" value="tk_hardening_save">
                        <input type="hidden" name="tk_tab" value="xmlrpc">
                        <p><label><input type="checkbox" name="xmlrpc" value="1" <?php checked(1, $opts['hardening_disable_xmlrpc']); ?>> Disable XML-RPC</label></p>
                        <p><label><input type="checkbox" name="xmlrpc_block_methods" value="1" <?php checked(1, $opts['hardening_xmlrpc_block_methods']); ?>> Block dangerous XML-RPC methods</label></p>
                        <p>
                            <label for="tk-xmlrpc-methods">Blocked XML-RPC methods (one per line)</label><br>
                            <textarea class="large-text" rows="3" id="tk-xmlrpc-methods" name="xmlrpc_blocked_methods" placeholder="system.multicall"><?php echo esc_textarea((string)$opts['hardening_xmlrpc_blocked_methods']); ?></textarea>
                        </p>
                        <p><label><input type="checkbox" name="xmlrpc_rate_limit" value="1" <?php checked(1, $opts['hardening_xmlrpc_rate_limit_enabled']); ?>> Rate-limit XML-RPC</label></p>
                        <p>
                            Window (minutes)<br>
                            <input type="number" name="xmlrpc_rate_limit_window" value="<?php echo esc_attr((string)$opts['hardening_xmlrpc_rate_limit_window_minutes']); ?>">
                        </p>
                        <p>
                            Max requests per window<br>
                            <input type="number" name="xmlrpc_rate_limit_max" value="<?php echo esc_attr((string)$opts['hardening_xmlrpc_rate_limit_max_attempts']); ?>">
                        </p>
                        <p>
                            Lockout duration (minutes)<br>
                            <input type="number" name="xmlrpc_rate_limit_lock" value="<?php echo esc_attr((string)$opts['hardening_xmlrpc_rate_limit_lockout_minutes']); ?>">
                        </p>
                        <p><button class="button button-primary">Save Hardening Settings</button></p>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="waf">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_hardening_save'); ?>
                        <input type="hidden" name="action" value="tk_hardening_save">
                        <input type="hidden" name="tk_tab" value="waf">
                        <p><label><input type="checkbox" name="waf_enabled" value="1" data-confirm="Enabling WAF can block legitimate traffic if rules are too strict." <?php checked(1, $opts['hardening_waf_enabled']); ?>> Enable simple WAF</label></p>
                        <p class="description">Warning: review allowlist and logs after enabling.</p>
                        <p>
                            <label for="tk-waf-check-methods">WAF check methods (comma-separated)</label><br>
                            <input class="regular-text" type="text" id="tk-waf-check-methods" name="waf_check_methods" value="<?php echo esc_attr((string)$opts['hardening_waf_check_methods']); ?>" placeholder="GET, POST">
                        </p>
                        <p>
                            <label for="tk-waf-allow-paths">WAF allow paths (one per line)</label><br>
                            <textarea class="large-text" rows="2" id="tk-waf-allow-paths" name="waf_allow_paths" placeholder="/wp-admin/admin-ajax.php"><?php echo esc_textarea((string)$opts['hardening_waf_allow_paths']); ?></textarea>
                        </p>
                        <p>
                            <label for="tk-waf-allow-regex">WAF allow regex (one per line)</label><br>
                            <textarea class="large-text" rows="2" id="tk-waf-allow-regex" name="waf_allow_regex" placeholder="#^/wp-json/#"><?php echo esc_textarea((string)$opts['hardening_waf_allow_regex']); ?></textarea>
                        </p>
                        <p><label><input type="checkbox" name="waf_log_to_file" value="1" <?php checked(1, $opts['hardening_waf_log_to_file']); ?>> Log WAF blocks to file</label></p>
                        <p>
                            Max log size (KB)<br>
                            <input type="number" name="waf_log_max_kb" value="<?php echo esc_attr((string)$opts['hardening_waf_log_max_kb']); ?>">
                        </p>
                        <p>
                            Max rotated files<br>
                            <input type="number" name="waf_log_max_files" value="<?php echo esc_attr((string)$opts['hardening_waf_log_max_files']); ?>">
                        </p>
                        <p><label><input type="checkbox" name="waf_log_compress" value="1" <?php checked(1, $opts['hardening_waf_log_compress']); ?>> Compress rotated logs (.gz)</label></p>
                        <p>
                            Compress if >= (KB)<br>
                            <input type="number" name="waf_log_compress_min_kb" value="<?php echo esc_attr((string)$opts['hardening_waf_log_compress_min_kb']); ?>">
                        </p>
                        <p>
                            Delete logs older than (days)<br>
                            <input type="number" name="waf_log_keep_days" value="<?php echo esc_attr((string)$opts['hardening_waf_log_keep_days']); ?>">
                        </p>
                        <p>
                            Log cleanup schedule<br>
                            <select name="waf_log_schedule">
                                <option value="hourly" <?php selected('hourly', $opts['hardening_waf_log_schedule']); ?>>Hourly</option>
                                <option value="twice_daily" <?php selected('twice_daily', $opts['hardening_waf_log_schedule']); ?>>Twice daily</option>
                                <option value="daily" <?php selected('daily', $opts['hardening_waf_log_schedule']); ?>>Daily</option>
                            </select>
                        </p>
                        <p><button class="button button-primary">Save Hardening Settings</button></p>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="httpauth">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_hardening_save'); ?>
                        <input type="hidden" name="action" value="tk_hardening_save">
                        <input type="hidden" name="tk_tab" value="httpauth">
                        <p><label><input type="checkbox" name="httpauth_enabled" value="1" <?php checked(1, $opts['hardening_httpauth_enabled']); ?>> Enable HTTP Basic Auth (site-wide)</label></p>
                        <p>
                            Scope<br>
                            <select name="httpauth_scope">
                                <option value="both" <?php selected('both', $opts['hardening_httpauth_scope']); ?>>Frontend + Admin</option>
                                <option value="frontend" <?php selected('frontend', $opts['hardening_httpauth_scope']); ?>>Frontend only</option>
                                <option value="admin" <?php selected('admin', $opts['hardening_httpauth_scope']); ?>>Admin only</option>
                            </select>
                        </p>
                        <p>
                            Username<br>
                            <input type="text" name="httpauth_user" value="<?php echo esc_attr((string)$opts['hardening_httpauth_user']); ?>">
                        </p>
                        <p>
                            Password (leave blank to keep current)<br>
                            <input type="password" name="httpauth_pass" value="">
                        </p>
                        <p>
                            Allow paths (one per line)<br>
                            <textarea class="large-text" rows="2" name="httpauth_allow_paths" placeholder="/wp-json/"><?php echo esc_textarea((string)$opts['hardening_httpauth_allow_paths']); ?></textarea>
                        </p>
                        <p>
                            Allow regex (one per line)<br>
                            <textarea class="large-text" rows="2" name="httpauth_allow_regex" placeholder="#^/wp-json/#"><?php echo esc_textarea((string)$opts['hardening_httpauth_allow_regex']); ?></textarea>
                        </p>
                        <p><button class="button button-primary">Save Hardening Settings</button></p>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="cors">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_hardening_save'); ?>
                        <input type="hidden" name="action" value="tk_hardening_save">
                        <input type="hidden" name="tk_tab" value="cors">
                        <p><label><input type="checkbox" name="cors_custom_origins_enabled" value="1" <?php checked(1, $opts['hardening_cors_custom_origins_enabled']); ?>> Enable custom allowed origins</label></p>
                        <p>
                            <label for="tk-cors-origins">Allowed origins (one per line)</label><br>
                            <textarea class="large-text" rows="3" id="tk-cors-origins" name="cors_allowed_origins" placeholder="<?php echo esc_attr(home_url('/')); ?>"><?php echo esc_textarea((string)$opts['hardening_cors_allowed_origins']); ?></textarea>
                        </p>
                        <p>
                            <label for="tk-cors-methods">Allowed methods (comma-separated)</label><br>
                            <input class="regular-text" type="text" id="tk-cors-methods" name="cors_allowed_methods" value="<?php echo esc_attr((string)$opts['hardening_cors_allowed_methods']); ?>" placeholder="GET, POST, OPTIONS">
                        </p>
                        <p>
                            <label for="tk-cors-headers">Allowed headers (comma-separated)</label><br>
                            <input class="regular-text" type="text" id="tk-cors-headers" name="cors_allowed_headers" value="<?php echo esc_attr((string)$opts['hardening_cors_allowed_headers']); ?>" placeholder="Authorization, X-WP-Nonce, Content-Type">
                        </p>
                        <p><label><input type="checkbox" name="cors_allow_credentials" value="1" <?php checked(1, $opts['hardening_cors_allow_credentials']); ?>> Allow credentialed CORS (only if required)</label></p>
                        <p><button class="button button-primary">Save Hardening Settings</button></p>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function(){
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
        document.querySelectorAll('form').forEach(function(form){
            var action = form.querySelector('input[name="action"][value="tk_hardening_save"]');
            if (!action) { return; }
            form.addEventListener('submit', function(e){
                var messages = [];
                form.querySelectorAll('input[type="checkbox"][data-confirm]').forEach(function(cb){
                    if (cb.checked) {
                        messages.push(cb.getAttribute('data-confirm'));
                    }
                });
                if (!messages.length) {
                    return;
                }
                var message = 'Please confirm:\n- ' + messages.join('\n- ');
                if (!window.confirm(message)) {
                    e.preventDefault();
                }
            });
        });
        var initial = getPanelFromHash();
        if (initial) {
            activateTab(initial);
        }
    })();
    </script>
    <?php
}

function tk_hardening_save() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    tk_check_nonce('tk_hardening_save');
    tk_killswitch_snapshot('hardening');

    $tab = isset($_POST['tk_tab']) ? sanitize_key($_POST['tk_tab']) : '';
    if ($tab === 'general') {
        tk_update_option('hardening_disable_file_editor', !empty($_POST['file_editor']) ? 1 : 0);
        tk_update_option('hardening_disable_comments', !empty($_POST['disable_comments']) ? 1 : 0);
        tk_update_option('hardening_disable_rest_user_enum', !empty($_POST['rest_user_enum']) ? 1 : 0);
        tk_update_option('hardening_security_headers', !empty($_POST['headers']) ? 1 : 0);
        tk_update_option('hardening_csp_lite_enabled', !empty($_POST['csp_lite']) ? 1 : 0);
        tk_update_option('hardening_hsts_enabled', !empty($_POST['hsts']) ? 1 : 0);
        tk_update_option('hardening_server_aware_enabled', !empty($_POST['server_aware']) ? 1 : 0);
        tk_update_option('hardening_block_uploads_php', !empty($_POST['block_uploads_php']) ? 1 : 0);
        tk_update_option('hardening_block_plugin_installs', !empty($_POST['block_plugin_installs']) ? 1 : 0);
        tk_update_option('hardening_disable_pingbacks', !empty($_POST['pingbacks']) ? 1 : 0);
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

    $redirect = add_query_arg(array('page'=>'tool-kits-security-hardening','tk_saved'=>1), admin_url('admin.php'));
    if ($tab !== '') {
        $redirect .= '#' . $tab;
    }
    wp_redirect($redirect);
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
