<?php
if (!defined('ABSPATH')) { exit; }

function tk_assets_opt_init() {
    add_action('admin_post_tk_assets_opt_save', 'tk_assets_opt_save');
    add_action('admin_post_tk_assets_opt_scan_fonts', 'tk_assets_opt_scan_fonts');
    add_action('admin_post_tk_assets_opt_generate_critical', 'tk_assets_opt_generate_critical');
    add_action('wp_head', 'tk_assets_render_critical_css', 5);
    add_filter('style_loader_tag', 'tk_assets_style_loader_tag', 10, 4);
    add_filter('style_loader_src', 'tk_assets_style_loader_src', 25, 2);
    add_filter('wp_get_attachment_image_attributes', 'tk_assets_image_attributes', 20, 3);
    add_action('wp_head', 'tk_assets_preload_fonts', 4);
}

function tk_assets_opt_enabled(): bool {
    return !is_admin();
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
    echo "\n<style id=\"tk-critical-css\">\n" . $css . "\n</style>\n";
}

function tk_assets_style_loader_tag($tag, $handle, $href, $media) {
    if (!tk_assets_opt_enabled()) {
        return $tag;
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

    $file_count = 0;
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
        $contents = @file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            continue;
        }
        $file_count++;
        $css .= "\n" . $contents;
    }

    $css = preg_replace('!/\*.*?\*/!s', '', (string) $css);
    $css = trim($css);
    $max_bytes = 25000;
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
    $dimensions = (int) tk_get_option('assets_dimensions_enabled', 1);
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
                <small>Collects CSS from the homepage head and theme stylesheets (limit 25KB).</small>
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
                    <input type="checkbox" name="assets_dimensions_enabled" value="1" <?php checked(1, $dimensions); ?>>
                    Add width/height + aspect-ratio to attachment images
                </label>
            </p>
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
    tk_check_nonce('tk_assets_opt_save');
    tk_update_option('assets_critical_css_enabled', !empty($_POST['assets_critical_css_enabled']) ? 1 : 0);
    tk_update_option('assets_critical_css', (string) tk_post('assets_critical_css', ''));
    tk_update_option('assets_defer_css_handles', sanitize_text_field((string) tk_post('assets_defer_css_handles', '')));
    tk_update_option('assets_preload_css_handles', sanitize_text_field((string) tk_post('assets_preload_css_handles', '')));
    tk_update_option('assets_preload_fonts', (string) tk_post('assets_preload_fonts', ''));
    tk_update_option('assets_font_display_swap', !empty($_POST['assets_font_display_swap']) ? 1 : 0);
    tk_update_option('assets_dimensions_enabled', !empty($_POST['assets_dimensions_enabled']) ? 1 : 0);
    wp_redirect(add_query_arg(array('page' => 'tool-kits-optimization', 'tk_tab' => 'assets', 'tk_saved' => 1), admin_url('admin.php')));
    exit;
}
