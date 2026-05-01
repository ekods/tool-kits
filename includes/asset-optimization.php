<?php
if (!defined('ABSPATH')) { exit; }

function tk_assets_opt_init() {
    add_action('admin_post_tk_assets_opt_save', 'tk_assets_opt_save');
    add_action('admin_post_tk_assets_opt_scan_fonts', 'tk_assets_opt_scan_fonts');
    add_action('admin_post_tk_assets_opt_generate_critical', 'tk_assets_opt_generate_critical');
    add_action('wp_head', 'tk_assets_render_critical_css', 5);
    add_filter('style_loader_tag', 'tk_assets_style_loader_tag', 10, 4);
    add_filter('style_loader_src', 'tk_assets_style_loader_src', 25, 2);
    add_filter('script_loader_tag', 'tk_assets_script_loader_tag', 20, 3);
    add_filter('wp_get_attachment_image_attributes', 'tk_assets_image_attributes', 20, 3);
    add_action('wp_head', 'tk_assets_preload_fonts', 4);
    add_action('wp_footer', 'tk_assets_delay_js_bootstrap', 99);
    add_action('template_redirect', 'tk_assets_start_perf_buffer', 2);
    add_action('wp_enqueue_scripts', 'tk_assets_cleanup_bloat', 99);
    add_action('wp_footer', 'tk_assets_instant_page', 99);
}

function tk_assets_opt_enabled(): bool {
    return !is_admin() && tk_license_features_enabled();
}

function tk_assets_start_perf_buffer() {
    if (!tk_assets_opt_enabled()) {
        return;
    }
    if (is_feed() || (function_exists('is_robots') && is_robots()) || (function_exists('is_trackback') && is_trackback())) {
        return;
    }
    ob_start('tk_assets_perf_buffer');
}

function tk_assets_perf_buffer($html) {
    if (!is_string($html) || $html === '') {
        return $html;
    }
    $html = tk_assets_apply_cls_guard($html);
    $html = tk_assets_apply_lcp_boost($html);
    if ((int) tk_get_option('assets_lcp_bg_preload_enabled', 1) === 1) {
        $html = tk_assets_apply_lcp_bg_preload($html);
    }
    if ((int) tk_get_option('assets_preconnect_auto_enabled', 1) === 1) {
        $html = tk_assets_apply_auto_preconnect($html);
    }
    return $html;
}

function tk_assets_apply_lcp_bg_preload($html) {
    $bg_url = tk_assets_find_lcp_background_url($html);
    if ($bg_url === '') {
        return $html;
    }
    if (stripos($html, 'rel="preload"') !== false && stripos($html, 'as="image"') !== false && stripos($html, $bg_url) !== false) {
        return $html;
    }
    $preload = '<link rel="preload" as="image" href="' . esc_url($bg_url) . '">' . "\n";
    if (preg_match('/<head\b[^>]*>/i', $html)) {
        return (string) preg_replace('/<head\b[^>]*>/i', '$0' . "\n" . $preload, $html, 1);
    }
    return $html;
}

function tk_assets_find_lcp_background_url($html) {
    if (!is_string($html) || $html === '') {
        return '';
    }
    if (!preg_match_all('/style=("|\')(.*?)\1/is', $html, $styles)) {
        return '';
    }
    foreach ($styles[2] as $style) {
        if (!is_string($style) || stripos($style, 'background') === false) {
            continue;
        }
        if (!preg_match('/background(?:-image)?\s*:[^;]*url\((["\']?)([^)\'"]+)\1\)/i', $style, $m)) {
            continue;
        }
        $url = isset($m[2]) ? trim((string) $m[2]) : '';
        if ($url === '' || strpos($url, 'data:') === 0) {
            continue;
        }
        return $url;
    }
    return '';
}

function tk_assets_apply_auto_preconnect($html) {
    $hosts = tk_assets_collect_preconnect_hosts($html);
    if (empty($hosts)) {
        return $html;
    }
    $inject = '';
    foreach ($hosts as $origin) {
        if (stripos($html, 'rel="preconnect"') !== false && stripos($html, $origin) !== false) {
            continue;
        }
        $inject .= '<link rel="preconnect" href="' . esc_url($origin) . '" crossorigin>' . "\n";
        $inject .= '<link rel="dns-prefetch" href="' . esc_url($origin) . '">' . "\n";
    }
    if ($inject === '') {
        return $html;
    }
    if (preg_match('/<head\b[^>]*>/i', $html)) {
        return (string) preg_replace('/<head\b[^>]*>/i', '$0' . "\n" . $inject, $html, 1);
    }
    return $html;
}

function tk_assets_collect_preconnect_hosts($html) {
    if (!is_string($html) || $html === '') {
        return array();
    }
    $matches = array();
    preg_match_all('/<(?:script|link)\b[^>]*(?:src|href)=("|\')(https?:\/\/[^"\']+)\1/i', $html, $matches);
    if (empty($matches[2]) || !is_array($matches[2])) {
        return array();
    }
    $site_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $origins = array();
    foreach ($matches[2] as $url) {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $scheme = (string) wp_parse_url($url, PHP_URL_SCHEME);
        if ($host === '' || $scheme === '') {
            continue;
        }
        if ($site_host !== '' && strcasecmp($host, $site_host) === 0) {
            continue;
        }
        $origin = strtolower($scheme . '://' . $host);
        $origins[$origin] = true;
    }
    $origins = array_keys($origins);
    sort($origins);
    return array_slice($origins, 0, 3);
}

function tk_assets_apply_cls_guard($html) {
    return preg_replace_callback('/<img\b[^>]*>/i', function($m) {
        $tag = (string) $m[0];
        $src = tk_assets_html_get_attr($tag, 'src');
        if ($src === '' || strpos($src, 'data:') === 0) {
            return $tag;
        }
        $path = tk_assets_map_url_to_path($src);
        if ($path === '' || !is_readable($path)) {
            return tk_assets_html_upsert_attr($tag, 'decoding', 'async');
        }
        $size = @getimagesize($path);
        if (!is_array($size) || empty($size[0]) || empty($size[1])) {
            return tk_assets_html_upsert_attr($tag, 'decoding', 'async');
        }
        $w = (int) $size[0];
        $h = (int) $size[1];
        if ($w <= 0 || $h <= 0) {
            return tk_assets_html_upsert_attr($tag, 'decoding', 'async');
        }
        if (!tk_assets_html_has_attr($tag, 'width')) {
            $tag = tk_assets_html_upsert_attr($tag, 'width', (string) $w);
        }
        if (!tk_assets_html_has_attr($tag, 'height')) {
            $tag = tk_assets_html_upsert_attr($tag, 'height', (string) $h);
        }
        $style = tk_assets_html_get_attr($tag, 'style');
        if ($style === '' || stripos($style, 'aspect-ratio') === false) {
            $style = trim($style);
            if ($style !== '' && substr($style, -1) !== ';') {
                $style .= ';';
            }
            $style .= ' aspect-ratio: ' . $w . ' / ' . $h . ';';
            $tag = tk_assets_html_upsert_attr($tag, 'style', trim($style));
        }
        $tag = tk_assets_html_upsert_attr($tag, 'decoding', 'async');
        return $tag;
    }, $html);
}

function tk_assets_apply_lcp_boost($html) {
    if (!preg_match_all('/<img\b[^>]*>/i', $html, $matches)) {
        return $html;
    }
    $hero_tag = '';
    $hero_src = '';
    foreach ($matches[0] as $tag) {
        $src = tk_assets_html_get_attr((string) $tag, 'src');
        if ($src === '' || strpos($src, 'data:') === 0) {
            continue;
        }
        $hero_tag = (string) $tag;
        $hero_src = $src;
        break;
    }
    if ($hero_tag === '' || $hero_src === '') {
        return $html;
    }

    $updated_tag = tk_assets_html_upsert_attr($hero_tag, 'loading', 'eager');
    $updated_tag = tk_assets_html_upsert_attr($updated_tag, 'fetchpriority', 'high');
    $updated_tag = tk_assets_html_upsert_attr($updated_tag, 'decoding', 'async');

    $html = preg_replace('/' . preg_quote($hero_tag, '/') . '/', $updated_tag, $html, 1);
    if (!is_string($html)) {
        return '';
    }

    if (stripos($html, 'rel="preload"') !== false && stripos($html, 'as="image"') !== false && stripos($html, $hero_src) !== false) {
        return $html;
    }
    $preload = '<link rel="preload" as="image" href="' . esc_url($hero_src) . '">' . "\n";
    if (preg_match('/<head\b[^>]*>/i', $html)) {
        $html = preg_replace('/<head\b[^>]*>/i', '$0' . "\n" . $preload, $html, 1);
    }
    return $html;
}

function tk_assets_html_has_attr($tag, $attr) {
    return preg_match('/\b' . preg_quote($attr, '/') . '\s*=\s*("|\').*?\1/i', $tag) === 1;
}

function tk_assets_html_get_attr($tag, $attr) {
    if (!preg_match('/\b' . preg_quote($attr, '/') . '\s*=\s*("|\')(.*?)\1/i', $tag, $m)) {
        return '';
    }
    return isset($m[2]) ? html_entity_decode((string) $m[2], ENT_QUOTES) : '';
}

function tk_assets_html_upsert_attr($tag, $attr, $value) {
    $value = esc_attr($value);
    if (tk_assets_html_has_attr($tag, $attr)) {
        return (string) preg_replace('/\b' . preg_quote($attr, '/') . '\s*=\s*("|\').*?\1/i', $attr . '="' . $value . '"', $tag, 1);
    }
    $self_close = substr(trim($tag), -2) === '/>';
    if ($self_close) {
        return rtrim(substr($tag, 0, -2)) . ' ' . $attr . '="' . $value . '" />';
    }
    return rtrim(substr($tag, 0, -1)) . ' ' . $attr . '="' . $value . '">';
}

function tk_assets_parse_handles($raw): array {
    if (!is_string($raw) || $raw === '') {
        return array();
    }
    $items = preg_split('/[\s,]+/', $raw);
    if (!is_array($items)) {
        return array();
    }
    $items = array_filter(array_map('trim', $items));
    return array_values(array_unique($items));
}

function tk_assets_script_loader_tag($tag, $handle, $src) {
    if (!tk_assets_opt_enabled()) {
        return $tag;
    }
    if ((int) tk_get_option('assets_js_delay_enabled', 0) !== 1) {
        return $tag;
    }
    if (!is_string($tag) || stripos($tag, '<script') === false) {
        return $tag;
    }
    if (stripos($tag, 'data-tk-delay=') !== false || stripos($tag, 'data-tk-assets-delay=') !== false) {
        return $tag;
    }
    if (!is_string($handle) || $handle === '' || tk_assets_is_protected_script_handle($handle)) {
        return $tag;
    }
    $targets = tk_assets_parse_handles((string) tk_get_option('assets_js_delay_handles', ''));
    if (empty($targets) || !in_array($handle, $targets, true)) {
        return $tag;
    }
    $url = is_string($src) ? esc_url($src) : '';
    if ($url === '') {
        return $tag;
    }
    return '<script type="text/plain" data-tk-assets-delay="1" data-tk-assets-handle="' . esc_attr($handle) . '" data-tk-assets-src="' . $url . '"></script>';
}

function tk_assets_is_protected_script_handle($handle) {
    $protected = array(
        'jquery',
        'jquery-core',
        'jquery-migrate',
        'wp-hooks',
        'wp-i18n',
        'wp-element',
        'wp-polyfill',
        'wp-api-fetch',
        'wp-dom-ready',
        'regenerator-runtime',
    );
    return in_array((string) $handle, $protected, true);
}

function tk_assets_delay_js_bootstrap() {
    if (!tk_assets_opt_enabled()) {
        return;
    }
    if ((int) tk_get_option('assets_js_delay_enabled', 0) !== 1) {
        return;
    }
    $targets = tk_assets_parse_handles((string) tk_get_option('assets_js_delay_handles', ''));
    if (empty($targets)) {
        return;
    }
    $script = "(function(){
        var delayEvents = ['keydown', 'mousedown', 'mousemove', 'touchmove', 'touchstart', 'touchend', 'scroll'];
        var triggered = false;
        function trigger() {
            if (triggered) return;
            triggered = true;
            delayEvents.forEach(function(e){ window.removeEventListener(e, trigger); });
            document.querySelectorAll('script[data-tk-assets-delay]').forEach(function(s){
                var n = document.createElement('script');
                n.async = true;
                if (s.src) n.src = s.src;
                if (s.getAttribute('data-tk-assets-src')) n.src = s.getAttribute('data-tk-assets-src');
                if (s.textContent) n.textContent = s.textContent;
                document.head.appendChild(n);
                s.parentNode.removeChild(s);
            });
        }
        delayEvents.forEach(function(e){ window.addEventListener(e, trigger, {passive:true}); });
    })();";
    tk_csp_print_inline_script($script, array('id' => 'tk-assets-delay-js'));
}

function tk_assets_cleanup_bloat() {
    if (!tk_assets_opt_enabled()) return;

    if ((int) tk_get_option('assets_disable_emojis', 0) === 1) {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    }

    if ((int) tk_get_option('assets_disable_dashicons', 0) === 1 && !is_user_logged_in()) {
        wp_deregister_style('dashicons');
    }

    if ((int) tk_get_option('assets_disable_embeds', 0) === 1) {
        wp_deregister_script('wp-embed');
    }
}

function tk_assets_instant_page() {
    if (!tk_assets_opt_enabled()) return;
    if ((int) tk_get_option('assets_instant_page_enabled', 0) !== 1) return;
    $script = "(function(){
        var preload = function(url) {
            if (!url) return;
            var l = document.createElement('link');
            l.rel = 'prefetch';
            l.href = url;
            document.head.appendChild(l);
        };
        var handled = new Set();
        var observer = new IntersectionObserver(function(entries){
            entries.forEach(function(entry){
                if (entry.isIntersecting) {
                    var a = entry.target;
                    var url = a.href;
                    if (url && !handled.has(url) && url.origin === window.location.origin) {
                        handled.add(url);
                        preload(url);
                    }
                    observer.unobserve(a);
                }
            });
        });
        document.querySelectorAll('a').forEach(function(a){
            if (a.hostname === window.location.hostname && !a.hash && !a.href.includes('wp-login') && !a.href.includes('wp-admin')) {
                observer.observe(a);
            }
        });
    })();";
    tk_csp_print_inline_script($script, array('id' => 'tk-assets-instant-page'));
}

function tk_assets_render_critical_css() {
    if (!tk_assets_opt_enabled()) {
        return;
    }
    if ((int) tk_get_option('assets_critical_css_enabled', 0) !== 1) {
        return;
    }
    $css = (string) tk_get_option('assets_critical_css', '');
    $css = trim($css);
    if ($css === '') {
        return;
    }
    tk_csp_print_inline_style($css, array('id' => 'tk-critical-css'));
}

function tk_assets_style_loader_tag($tag, $handle, $href, $media) {
    if (!tk_assets_opt_enabled()) {
        return $tag;
    }
    if ((int) tk_get_option('assets_disable_google_fonts', 0) === 1) {
        $host = strtolower((string) wp_parse_url((string) $href, PHP_URL_HOST));
        if ($host === 'fonts.googleapis.com' || $host === 'fonts.gstatic.com') {
            return '';
        }
    }
    $defer = tk_assets_parse_handles((string) tk_get_option('assets_defer_css_handles', ''));
    $preload = tk_assets_parse_handles((string) tk_get_option('assets_preload_css_handles', ''));
    $href = esc_url($href);
    $media_attr = $media && $media !== 'all' ? ' media="' . esc_attr($media) . '"' : '';

    if (in_array($handle, $defer, true)) {
        $preload_tag = '<link rel="preload" as="style" href="' . $href . '"' . $media_attr . ' onload="this.onload=null;this.rel=\'stylesheet\'">';
        $noscript = '<noscript><link rel="stylesheet" href="' . $href . '"' . $media_attr . '></noscript>';
        return $preload_tag . $noscript;
    }

    if (in_array($handle, $preload, true)) {
        $preload_tag = '<link rel="preload" as="style" href="' . $href . '"' . $media_attr . '>';
        return $preload_tag . $tag;
    }

    return $tag;
}

function tk_assets_style_loader_src($src, $handle) {
    if (!tk_assets_opt_enabled()) {
        return $src;
    }
    if ((int) tk_get_option('assets_font_display_swap', 1) !== 1) {
        return $src;
    }
    $parts = wp_parse_url($src);
    if (!is_array($parts) || empty($parts['host'])) {
        return $src;
    }
    $host = strtolower($parts['host']);
    if ($host !== 'fonts.googleapis.com' && $host !== 'fonts.bunny.net') {
        return $src;
    }
    if (!empty($parts['query']) && strpos($parts['query'], 'display=') !== false) {
        return $src;
    }
    $sep = strpos($src, '?') === false ? '?' : '&';
    return $src . $sep . 'display=swap';
}

function tk_assets_preload_fonts() {
    if (!tk_assets_opt_enabled()) {
        return;
    }
    $raw = (string) tk_get_option('assets_preload_fonts', '');
    if ($raw === '') {
        return;
    }
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    if (!is_array($lines)) {
        return;
    }
    foreach ($lines as $line) {
        $url = trim($line);
        if ($url === '') {
            continue;
        }
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        $type = 'font/woff2';
        if ($ext === 'woff') {
            $type = 'font/woff';
        } elseif ($ext === 'ttf') {
            $type = 'font/ttf';
        } elseif ($ext === 'otf') {
            $type = 'font/otf';
        }
        echo '<link rel="preload" href="' . esc_url($url) . '" as="font" type="' . esc_attr($type) . '" crossorigin>' . "\n";
    }
}

function tk_assets_map_url_to_path($url) {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (strpos($url, '//') === 0) {
        $url = (is_ssl() ? 'https:' : 'http:') . $url;
    }
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['path'])) {
        return '';
    }
    $path = $parts['path'];
    $roots = array(
        wp_normalize_path(get_stylesheet_directory()) => get_stylesheet_directory_uri(),
        wp_normalize_path(get_template_directory()) => get_template_directory_uri(),
        wp_normalize_path(WP_CONTENT_DIR) => content_url(),
    );
    foreach ($roots as $root_path => $root_url) {
        $root_url = rtrim($root_url, '/');
        if ($root_url !== '' && strpos($url, $root_url) === 0) {
            $relative = ltrim(substr($url, strlen($root_url)), '/');
            return wp_normalize_path($root_path . '/' . $relative);
        }
    }
    if (!empty($parts['host'])) {
        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        if ($site_host && strcasecmp($parts['host'], $site_host) !== 0) {
            return '';
        }
    }
    $abs = wp_normalize_path(ABSPATH . ltrim($path, '/'));
    return $abs;
}

function tk_assets_extract_head($html) {
    if (!is_string($html) || $html === '') {
        return '';
    }
    if (preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $html, $m)) {
        return (string) $m[1];
    }
    return $html;
}

function tk_assets_collect_inline_styles($html) {
    if (!is_string($html) || $html === '') {
        return array();
    }
    $styles = array();
    if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $m)) {
        foreach ($m[1] as $block) {
            $block = trim($block);
            if ($block !== '') {
                $styles[] = $block;
            }
        }
    }
    return $styles;
}

function tk_assets_collect_stylesheets($html) {
    if (!is_string($html) || $html === '') {
        return array();
    }
    $urls = array();
    if (preg_match_all('/<link\b[^>]*rel=["\']stylesheet["\'][^>]*>/i', $html, $m)) {
        foreach ($m[0] as $tag) {
            if (preg_match('/href=["\']([^"\']+)["\']/i', $tag, $href_m)) {
                $urls[] = $href_m[1];
            }
        }
    }
    return array_values(array_unique($urls));
}

function tk_assets_opt_generate_critical() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_assets_opt_generate_critical');
    $resp = wp_remote_get(home_url('/'), array('timeout' => 8));
    if (is_wp_error($resp)) {
        $message = $resp->get_error_message();
        if (stripos($message, 'cURL error 60') !== false) {
            $resp = wp_remote_get(home_url('/'), array(
                'timeout' => 8,
                'sslverify' => false,
            ));
            if (is_wp_error($resp)) {
                $message = $resp->get_error_message();
            } else {
                $message = '';
            }
        }
        if (is_wp_error($resp)) {
            set_transient('tk_assets_critical_error', $message, MINUTE_IN_SECONDS * 5);
            wp_redirect(add_query_arg(array(
                'page' => 'tool-kits-optimization',
                'tk_tab' => 'assets',
                'tk_critical_error' => 1,
            ), admin_url('admin.php')));
            exit;
        }
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    if ($code < 200 || $code >= 300) {
        set_transient('tk_assets_critical_error', 'HTTP status ' . $code, MINUTE_IN_SECONDS * 5);
        wp_redirect(add_query_arg(array(
            'page' => 'tool-kits-optimization',
            'tk_tab' => 'assets',
            'tk_critical_error' => 1,
        ), admin_url('admin.php')));
        exit;
    }
    $html = (string) wp_remote_retrieve_body($resp);
    $head = tk_assets_extract_head($html);
    $inline_styles = tk_assets_collect_inline_styles($head);
    $stylesheet_urls = tk_assets_collect_stylesheets($head);

    $css = '';
    foreach ($inline_styles as $block) {
        $css .= "\n" . $block;
    }

    $newest_path = '';
    $newest_time = 0;

    foreach ($stylesheet_urls as $url) {
        $path = tk_assets_map_url_to_path($url);
        if ($path === '' || !is_readable($path)) {
            continue;
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'css') {
            continue;
        }
        if (@filesize($path) > 1024 * 1024) {
            continue;
        }
        
        $mtime = @filemtime($path);
        if ($mtime > $newest_time) {
            $newest_time = $mtime;
            $newest_path = $path;
        }
    }

    $file_count = 0;
    if ($newest_path !== '') {
        $contents = @file_get_contents($newest_path);
        if (is_string($contents) && $contents !== '') {
            $file_count = 1;
            $css .= "\n" . $contents;
        }
    }

    $css = preg_replace('!/\*.*?\*/!s', '', (string) $css);
    $css = trim($css);

    // Smart filtering: only keep CSS rules that match classes/IDs/tags in the HTML
    $css = tk_assets_filter_critical_css($css, $html);
    
    $max_bytes = 30000;
    if (strlen($css) > $max_bytes) {
        $css = substr($css, 0, $max_bytes);
    }

    tk_update_option('assets_critical_css', $css);
    tk_update_option('assets_critical_css_enabled', $css !== '' ? 1 : 0);

    wp_redirect(add_query_arg(array(
        'page' => 'tool-kits-optimization',
        'tk_tab' => 'assets',
        'tk_critical_generated' => strlen($css),
        'tk_critical_files' => $file_count,
    ), admin_url('admin.php')));
    exit;
}

/**
 * Filter CSS to only include rules that might match the given HTML.
 */
function tk_assets_filter_critical_css(string $css, string $html): string {
    if ($css === '' || $html === '') {
        return '';
    }

    // 1. Extract used classes, IDs, and tags from HTML
    $used = array();
    
    // Always keep these global/base selectors
    $always_keep = array('html', 'body', '*', ':root', '::before', '::after', '::placeholder', 'input', 'button', 'select', 'textarea');
    foreach ($always_keep as $k) {
        $used[$k] = true;
    }

    // Classes
    if (preg_match_all('/class=["\']([^"\']+)["\']/i', $html, $m)) {
        foreach ($m[1] as $c_str) {
            foreach (explode(' ', $c_str) as $class) {
                $class = trim($class);
                if ($class !== '') {
                    $used['.' . $class] = true;
                }
            }
        }
    }
    
    // IDs
    if (preg_match_all('/id=["\']([^"\']+)["\']/i', $html, $m)) {
        foreach ($m[1] as $id) {
            $id = trim($id);
            if ($id !== '') {
                $used['#' . $id] = true;
            }
        }
    }
    
    // Tags
    if (preg_match_all('/<([a-z1-6]+)\b/i', $html, $m)) {
        foreach ($m[1] as $tag) {
            $used[strtolower($tag)] = true;
        }
    }

    // 2. Process CSS Blocks
    $filtered = '';
    $blocks = array();
    $current = '';
    $depth = 0;
    $len = strlen($css);

    for ($i = 0; $i < $len; $i++) {
        $char = $css[$i];
        $current .= $char;
        if ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;
            if ($depth === 0) {
                $blocks[] = trim($current);
                $current = '';
            }
        }
    }

    foreach ($blocks as $block) {
        if (strpos($block, '@') === 0) {
            // Font-face and Animations are usually important
            if (stripos($block, '@font-face') === 0 || stripos($block, '@keyframes') === 0) {
                $filtered .= $block . "\n";
                continue;
            }

            // Recursively filter @media content
            if (stripos($block, '@media') === 0) {
                if (preg_match('/^(@media[^{]+)\{(.*)\}$/is', $block, $m)) {
                    $header = trim($m[1]);
                    $content = trim($m[2]);
                    $inner = tk_assets_filter_critical_css($content, $html);
                    if ($inner !== '') {
                        $filtered .= $header . "{\n" . $inner . "}\n";
                    }
                }
                continue;
            }
            continue;
        }

        // Standard Rule Set
        if (preg_match('/^([^{]+)\{(.*)\}$/is', $block, $m)) {
            $selector_str = trim($m[1]);
            $rules = trim($m[2]);
            $selectors = explode(',', $selector_str);
            $keep_block = false;

            foreach ($selectors as $sel) {
                $sel = trim($sel);
                if ($sel === '') {
                    continue;
                }

                if (isset($used[$sel])) {
                    $keep_block = true;
                    break;
                }

                // Check complex selectors by splitting into basic parts
                $parts = preg_split('/[\s>+~:]+/', $sel);
                foreach ($parts as $p) {
                    $p = trim($p);
                    if ($p === '') {
                        continue;
                    }
                    // Clean pseudo-classes and attributes for matching
                    $p = preg_replace('/\[.*?\]/', '', $p);
                    $p = preg_replace('/::?.*$/', '', $p);
                    if ($p !== '' && isset($used[$p])) {
                        $keep_block = true;
                        break 2;
                    }
                }
            }

            if ($keep_block) {
                $filtered .= $selector_str . "{" . $rules . "}\n";
            }
        }
    }

    return $filtered;
}

function tk_assets_normalize_path($path) {
    $path = str_replace('\\', '/', $path);
    $parts = explode('/', $path);
    $stack = array();
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($stack);
            continue;
        }
        $stack[] = $part;
    }
    return '/' . implode('/', $stack);
}

function tk_assets_collect_font_urls_from_css($css_path, $root_map) {
    $content = @file_get_contents($css_path);
    if (!is_string($content) || $content === '') {
        return array();
    }
    if (!preg_match_all('/url\(([^)]+)\)/i', $content, $matches)) {
        return array();
    }
    $urls = array();
    $css_dir = dirname($css_path);
    foreach ($matches[1] as $raw) {
        $raw = trim($raw, " \t\n\r\0\x0B'\"");
        if ($raw === '' || stripos($raw, 'data:') === 0) {
            continue;
        }
        $ext = strtolower(pathinfo(parse_url($raw, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        if (!in_array($ext, array('woff2', 'woff', 'ttf', 'otf', 'eot'), true)) {
            continue;
        }
        if (strpos($raw, '//') === 0 || preg_match('#^https?://#i', $raw)) {
            $urls[] = $raw;
            continue;
        }

        $target_path = $css_dir . '/' . $raw;
        $target_path = tk_assets_normalize_path($target_path);
        foreach ($root_map as $root_path => $root_url) {
            $root_path = rtrim(wp_normalize_path($root_path), '/');
            if (strpos($target_path, $root_path . '/') !== 0) {
                continue;
            }
            $relative = ltrim(substr($target_path, strlen($root_path)), '/');
            $urls[] = trailingslashit($root_url) . $relative;
        }
    }
    return $urls;
}

function tk_assets_scan_theme_fonts(): array {
    $roots = array(
        get_stylesheet_directory() => get_stylesheet_directory_uri(),
        get_template_directory() => get_template_directory_uri(),
    );
    $roots = array_filter($roots);
    $fonts = array();
    foreach ($roots as $root_path => $root_url) {
        if (!is_dir($root_path)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root_path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'css') {
                continue;
            }
            $size = $file->getSize();
            if ($size <= 0 || $size > 1024 * 1024) {
                continue;
            }
            $fonts = array_merge($fonts, tk_assets_collect_font_urls_from_css($file->getPathname(), $roots));
        }
    }
    $fonts = array_values(array_unique($fonts));
    return $fonts;
}

function tk_assets_opt_scan_fonts() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_assets_opt_scan_fonts');
    $fonts = tk_assets_scan_theme_fonts();
    tk_update_option('assets_preload_fonts', implode("\n", $fonts));
    $count = count($fonts);
    wp_redirect(add_query_arg(array(
        'page' => 'tool-kits-optimization',
        'tk_tab' => 'assets',
        'tk_fonts_scanned' => $count,
    ), admin_url('admin.php')));
    exit;
}

function tk_assets_image_attributes($attr, $attachment, $size) {
    if ((int) tk_get_option('assets_dimensions_enabled', 1) !== 1) {
        return $attr;
    }
    if (!is_array($attr)) {
        $attr = array();
    }
    if (!empty($attr['width']) && !empty($attr['height'])) {
        return tk_assets_add_aspect_ratio($attr);
    }
    if (!is_object($attachment) || empty($attachment->ID)) {
        return $attr;
    }
    $src = wp_get_attachment_image_src($attachment->ID, $size);
    if (is_array($src) && !empty($src[1]) && !empty($src[2])) {
        $attr['width'] = (int) $src[1];
        $attr['height'] = (int) $src[2];
    }
    return tk_assets_add_aspect_ratio($attr);
}

function tk_assets_add_aspect_ratio($attr) {
    if (empty($attr['width']) || empty($attr['height'])) {
        return $attr;
    }
    $ratio = (int) $attr['width'] . ' / ' . (int) $attr['height'];
    $style = isset($attr['style']) ? (string) $attr['style'] : '';
    if (strpos($style, 'aspect-ratio') !== false) {
        return $attr;
    }
    $style = trim($style);
    if ($style !== '' && substr($style, -1) !== ';') {
        $style .= ';';
    }
    $style .= ' aspect-ratio: ' . $ratio . ';';
    $attr['style'] = trim($style);
    return $attr;
}

function tk_render_assets_panel() {
    if (!tk_is_admin_user()) return;
    $scanned = isset($_GET['tk_fonts_scanned']) ? sanitize_text_field(wp_unslash($_GET['tk_fonts_scanned'])) : '';
    $critical_generated = isset($_GET['tk_critical_generated']) ? (int) $_GET['tk_critical_generated'] : 0;
    $critical_files = isset($_GET['tk_critical_files']) ? (int) $_GET['tk_critical_files'] : 0;
    $critical_error = isset($_GET['tk_critical_error']) ? (int) $_GET['tk_critical_error'] : 0;
    $critical_error_detail = $critical_error ? get_transient('tk_assets_critical_error') : '';
    $critical_enabled = (int) tk_get_option('assets_critical_css_enabled', 0);
    $critical_css = (string) tk_get_option('assets_critical_css', '');
    $defer_css = (string) tk_get_option('assets_defer_css_handles', '');
    $preload_css = (string) tk_get_option('assets_preload_css_handles', '');
    $preload_fonts = (string) tk_get_option('assets_preload_fonts', '');
    $font_swap = (int) tk_get_option('assets_font_display_swap', 1);
    $disable_google_fonts = (int) tk_get_option('assets_disable_google_fonts', 0);
    $dimensions = (int) tk_get_option('assets_dimensions_enabled', 1);
    $js_delay_enabled = (int) tk_get_option('assets_js_delay_enabled', 0);
    $js_delay_handles = (string) tk_get_option('assets_js_delay_handles', '');
    $lcp_bg_preload = (int) tk_get_option('assets_lcp_bg_preload_enabled', 1);
    $preconnect_auto = (int) tk_get_option('assets_preconnect_auto_enabled', 1);
    ?>
    <div class="tk-card">
        <h2>Asset Optimization</h2>
        <p>Reduce CLS and improve PageSpeed by inlining critical CSS, deferring non-critical styles, preloading fonts, and ensuring image dimensions.</p>
        <?php if ($scanned !== '') : ?>
            <?php tk_notice('Font scan completed. ' . esc_html($scanned) . ' font URL(s) found.', 'success'); ?>
        <?php endif; ?>
        <?php if ($critical_error === 1) : ?>
            <?php
            $message = 'Failed to generate critical CSS from homepage.';
            if (is_string($critical_error_detail) && $critical_error_detail !== '') {
                $message .= ' ' . $critical_error_detail;
            }
            tk_notice($message, 'error');
            ?>
        <?php elseif ($critical_generated > 0) : ?>
            <?php tk_notice('Critical CSS generated (' . esc_html((string) $critical_generated) . ' bytes from ' . esc_html((string) $critical_files) . ' file(s)).', 'success'); ?>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_assets_opt_save'); ?>
            <input type="hidden" name="action" value="tk_assets_opt_save">
            <input type="hidden" name="tk_tab" value="assets">

            <p>
                <label>
                    <input type="checkbox" name="assets_critical_css_enabled" value="1" <?php checked(1, $critical_enabled); ?>>
                    Enable Critical CSS
                </label>
            </p>
            <p>
                <label>Critical CSS (inline)</label><br>
                <textarea name="assets_critical_css" rows="6" class="large-text" placeholder="/* above-the-fold CSS */"><?php echo esc_textarea($critical_css); ?></textarea>
            </p>
            <p>
                <button class="button" form="tk-assets-critical-form" type="submit">Generate Critical CSS (Homepage)</button>
                <small>Collects inline CSS from head and the newest loaded theme stylesheet (limit 25KB).</small>
            </p>
            <p>
                <label>Defer CSS handles (comma/space separated)</label><br>
                <input type="text" name="assets_defer_css_handles" value="<?php echo esc_attr($defer_css); ?>" class="large-text" placeholder="e.g. theme-main-css, theme-aos-css">
            </p>
            <p>
                <label>Preload CSS handles (keep stylesheet)</label><br>
                <input type="text" name="assets_preload_css_handles" value="<?php echo esc_attr($preload_css); ?>" class="large-text" placeholder="e.g. theme-style-css">
            </p>
            <p>
                <label>Preload font URLs (one per line)</label><br>
                <textarea name="assets_preload_fonts" rows="4" class="large-text" placeholder="https://example.com/fonts/font.woff2"><?php echo esc_textarea($preload_fonts); ?></textarea>
            </p>
            <p>
                <button class="button" form="tk-assets-scan-form" type="submit">Scan Fonts from Theme</button>
                <small>Scans active theme CSS files and fills the font preload list.</small>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="assets_font_display_swap" value="1" <?php checked(1, $font_swap); ?>>
                    Force font-display: swap for Google/Bunny Fonts
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="assets_disable_google_fonts" value="1" <?php checked(1, $disable_google_fonts); ?>>
                    Disable Google Fonts stylesheets
                </label>
                <br><small class="description">Use only when your theme has local/system font fallback configured.</small>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="assets_dimensions_enabled" value="1" <?php checked(1, $dimensions); ?>>
                    Add width/height + aspect-ratio to attachment images
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="assets_lcp_bg_preload_enabled" value="1" <?php checked(1, $lcp_bg_preload); ?>>
                    Auto preload LCP background image from inline style
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="assets_preconnect_auto_enabled" value="1" <?php checked(1, $preconnect_auto); ?>>
                    Auto preconnect external script/style hosts
                </label>
            </p>
            <hr style="margin:16px 0;">
            <p><strong>Bloat Removal & Instant Page</strong></p>
            <p>
                <label>
                    <input type="checkbox" name="assets_disable_emojis" value="1" <?php checked(1, (int) tk_get_option('assets_disable_emojis', 0)); ?>>
                    Disable WordPress Emojis (saves ~10KB and 1 JS execution)
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="assets_disable_dashicons" value="1" <?php checked(1, (int) tk_get_option('assets_disable_dashicons', 0)); ?>>
                    Disable Dashicons on Frontend (saves ~30KB, only for logged-out users)
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="assets_disable_embeds" value="1" <?php checked(1, (int) tk_get_option('assets_disable_embeds', 0)); ?>>
                    Disable WP Embed JS (legacy embeds support)
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="assets_instant_page_enabled" value="1" <?php checked(1, (int) tk_get_option('assets_instant_page_enabled', 0)); ?>>
                    Enable Instant Page (Preload links when they enter viewport)
                </label>
            </p>
            <p class="description">Auto mode: CLS Guard and LCP Boost are always enabled on frontend output.</p>
            <p><button class="button button-primary">Save Settings</button></p>
        </form>
        <form id="tk-assets-scan-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_assets_opt_scan_fonts'); ?>
            <input type="hidden" name="action" value="tk_assets_opt_scan_fonts">
        </form>
        <form id="tk-assets-critical-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_assets_opt_generate_critical'); ?>
            <input type="hidden" name="action" value="tk_assets_opt_generate_critical">
        </form>
    </div>
    <?php
}

function tk_assets_opt_save() {
    tk_require_admin_post('tk_assets_opt_save');
    tk_update_option('assets_critical_css_enabled', !empty($_POST['assets_critical_css_enabled']) ? 1 : 0);
    tk_update_option('assets_critical_css', (string) tk_post('assets_critical_css', ''));
    tk_update_option('assets_defer_css_handles', sanitize_text_field((string) tk_post('assets_defer_css_handles', '')));
    tk_update_option('assets_preload_css_handles', sanitize_text_field((string) tk_post('assets_preload_css_handles', '')));
    tk_update_option('assets_preload_fonts', (string) tk_post('assets_preload_fonts', ''));
    tk_update_option('assets_font_display_swap', !empty($_POST['assets_font_display_swap']) ? 1 : 0);
    tk_update_option('assets_disable_google_fonts', !empty($_POST['assets_disable_google_fonts']) ? 1 : 0);
    tk_update_option('assets_dimensions_enabled', !empty($_POST['assets_dimensions_enabled']) ? 1 : 0);
    tk_update_option('assets_lcp_bg_preload_enabled', !empty($_POST['assets_lcp_bg_preload_enabled']) ? 1 : 0);
    tk_update_option('assets_preconnect_auto_enabled', !empty($_POST['assets_preconnect_auto_enabled']) ? 1 : 0);
    tk_update_option('assets_js_delay_enabled', !empty($_POST['assets_js_delay_enabled']) ? 1 : 0);
    tk_update_option('assets_js_delay_handles', sanitize_text_field((string) tk_post('assets_js_delay_handles', '')));
    tk_update_option('assets_disable_emojis', !empty($_POST['assets_disable_emojis']) ? 1 : 0);
    tk_update_option('assets_disable_dashicons', !empty($_POST['assets_disable_dashicons']) ? 1 : 0);
    tk_update_option('assets_disable_embeds', !empty($_POST['assets_disable_embeds']) ? 1 : 0);
    tk_update_option('assets_instant_page_enabled', !empty($_POST['assets_instant_page_enabled']) ? 1 : 0);
    tk_update_option('assets_cls_guard_enabled', 1);
    tk_update_option('assets_lcp_boost_enabled', 1);
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-optimization', 'tk_tab' => 'assets', 'tk_saved' => 1), admin_url('admin.php')));
    exit;
}
