<?php
if (!defined('ABSPATH')) { exit; }

function tk_minify_init() {
    add_action('admin_post_tk_minify_save', 'tk_minify_save');
    add_action('template_redirect', 'tk_minify_start_buffer', 0);
    add_filter('style_loader_src', 'tk_minify_filter_asset_src', 20, 2);
    add_filter('script_loader_src', 'tk_minify_filter_asset_src', 20, 2);
}

function tk_minify_start_buffer() {
    if (is_admin() || wp_doing_ajax() || is_feed() || is_preview()) {
        return;
    }
    if (!tk_get_option('minify_html_enabled', 0)) {
        return;
    }
    ob_start('tk_minify_buffer_callback');
}

function tk_minify_filter_asset_src($src, $handle) {
    if (is_admin()) {
        return $src;
    }
    if (!tk_get_option('minify_assets_enabled', 0)) {
        return $src;
    }
    if (!is_string($src) || $src === '') {
        return $src;
    }
    $parts = wp_parse_url($src);
    if (!is_array($parts) || empty($parts['path']) || !is_string($parts['path'])) {
        return $src;
    }
    $path = $parts['path'];
    if (preg_match('/\.min\.(css|js)$/i', $path)) {
        return $src;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext !== 'css' && $ext !== 'js') {
        return $src;
    }
    $abs = tk_minify_src_to_path($src);
    if ($abs === '') {
        return $src;
    }
    $min_abs = preg_replace('/\.' . preg_quote($ext, '/') . '$/i', '.min.' . $ext, $abs);
    if (!is_string($min_abs) || !file_exists($min_abs)) {
        return $src;
    }
    $src_no_query = strtok($src, '?');
    $min_src = preg_replace('/\.' . preg_quote($ext, '/') . '$/i', '.min.' . $ext, $src_no_query);
    if (!empty($parts['query'])) {
        $min_src .= '?' . $parts['query'];
    }
    return $min_src;
}

function tk_minify_src_to_path($src) {
    if (!is_string($src) || $src === '') {
        return '';
    }
    $parts = wp_parse_url($src);
    if (!is_array($parts) || empty($parts['path']) || !is_string($parts['path'])) {
        return '';
    }
    if (!empty($parts['host'])) {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        if (is_string($host) && $host !== '' && strcasecmp($parts['host'], $host) !== 0) {
            return '';
        }
    }
    $path_part = $parts['path'];
    $abs = wp_normalize_path(ABSPATH . ltrim($path_part, '/'));
    return file_exists($abs) ? $abs : '';
}

function tk_minify_buffer_callback($html) {
    if (!is_string($html) || $html === '' || stripos($html, '<html') === false) {
        return $html;
    }

    $minify_inline_css = tk_get_option('minify_inline_css', 1);
    $minify_inline_js = tk_get_option('minify_inline_js', 1);

    $placeholders = array();
    $html = preg_replace_callback(
        '#<(pre|textarea)\b[^>]*>.*?</\1>#is',
        function($m) use (&$placeholders) {
            $key = '%%TKMINIFY' . count($placeholders) . '%%';
            $placeholders[$key] = $m[0];
            return $key;
        },
        $html
    );

    if ($minify_inline_css) {
        $html = preg_replace_callback(
            '#<style\b([^>]*)>(.*?)</style>#is',
            function($m) {
                $attrs = $m[1];
                $content = $m[2];
                $minified = tk_minify_inline_css($content);
                return '<style' . $attrs . '>' . $minified . '</style>';
            },
            $html
        );
    }

    if ($minify_inline_js) {
        $html = preg_replace_callback(
            '#<script\b([^>]*)>(.*?)</script>#is',
            function($m) {
                $attrs = $m[1];
                if (preg_match('/\bsrc\s*=/i', $attrs)) {
                    return $m[0];
                }
                if (preg_match('/\btype\s*=\s*(["\'])(.*?)\1/i', $attrs, $type_match)) {
                    $type = strtolower(trim($type_match[2]));
                    $skip = array(
                        'application/ld+json',
                        'application/json',
                        'text/template',
                        'text/html',
                        'text/x-template',
                    );
                    if (in_array($type, $skip, true)) {
                        return $m[0];
                    }
                }
                $minified = tk_minify_inline_js($m[2]);
                return '<script' . $attrs . '>' . $minified . '</script>';
            },
            $html
        );
    }

    $html = preg_replace_callback('/<!--(.*?)-->/s', function($m) {
        $content = $m[1];
        if (stripos($content, '[if') !== false || stripos($content, '<![endif') !== false) {
            return $m[0];
        }
        return '';
    }, $html);

    $html = preg_replace('/>\s+</', '><', $html);
    $html = trim($html);

    if (!empty($placeholders)) {
        $html = strtr($html, $placeholders);
    }

    return $html;
}

function tk_minify_inline_css($css) {
    if ($css === null) {
        return '';
    }
    if (!is_string($css) || $css === '') {
        return $css;
    }
    $css = preg_replace('!/\*.*?\*/!s', '', $css);
    $css = preg_replace('/\s+/', ' ', $css);
    $css = preg_replace('/\s*([{}:;,])\s*/', '$1', $css);
    $css = str_replace(';}', '}', $css);
    return trim($css);
}

function tk_minify_inline_js($js) {
    if (!is_string($js) || $js === '') {
        return $js;
    }
    $js = preg_replace('!/\*.*?\*/!s', '', $js);
    $js = preg_replace('/\s+/', ' ', $js);
    return trim($js);
}

function tk_render_minify_page() {
    if (function_exists('tk_render_optimization_page')) {
        tk_render_optimization_page('minify');
        return;
    }
    if (!tk_is_admin_user()) return;
    ?>
    <div class="wrap tk-wrap">
        <h1>Optimization</h1>
        <?php tk_render_minify_panel(); ?>
    </div>
    <?php
}

function tk_render_minify_panel() {
    if (!tk_is_admin_user()) return;
    ?>
    <div class="tk-card">
        <h2>Minify</h2>
        <p>Optimize frontend output by trimming HTML and using minified assets when available.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_minify_save'); ?>
            <input type="hidden" name="action" value="tk_minify_save">
            <input type="hidden" name="tk_tab" value="minify">
            <p>
                <label>
                    <input type="checkbox" name="minify_html_enabled" value="1" <?php checked(1, tk_get_option('minify_html_enabled', 0)); ?>>
                    Minify HTML output (frontend)
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="minify_inline_css" value="1" <?php checked(1, tk_get_option('minify_inline_css', 1)); ?>>
                    Minify inline CSS blocks
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="minify_inline_js" value="1" <?php checked(1, tk_get_option('minify_inline_js', 1)); ?>>
                    Minify inline JS blocks (conservative)
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="minify_assets_enabled" value="1" <?php checked(1, tk_get_option('minify_assets_enabled', 0)); ?>>
                    Use .min.css/.min.js when available for local assets
                </label>
            </p>
            <p><button class="button button-primary">Save</button></p>
        </form>
    </div>
    <?php
}

function tk_minify_save() {
    tk_check_nonce('tk_minify_save');

    tk_update_option('minify_html_enabled', !empty($_POST['minify_html_enabled']) ? 1 : 0);
    tk_update_option('minify_inline_css', !empty($_POST['minify_inline_css']) ? 1 : 0);
    tk_update_option('minify_inline_js', !empty($_POST['minify_inline_js']) ? 1 : 0);
    tk_update_option('minify_assets_enabled', !empty($_POST['minify_assets_enabled']) ? 1 : 0);

    wp_redirect(add_query_arg(array('page' => 'tool-kits-optimization', 'tk_tab' => 'minify', 'tk_saved' => 1), admin_url('admin.php')));
    exit;
}
