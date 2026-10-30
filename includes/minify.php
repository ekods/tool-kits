<?php
if (!defined('ABSPATH')) { exit; }

function tk_minify_init() {
    add_action('admin_post_tk_minify_save', 'tk_minify_save');
    // Inside the page-cache buffer (priority 0): cached pages are stored minified and served without re-minifying.
    add_action('template_redirect', 'tk_minify_start_buffer', 1);
    add_filter('style_loader_src', 'tk_minify_filter_asset_src', 20, 2);
    add_filter('script_loader_src', 'tk_minify_filter_asset_src', 20, 2);
}

function tk_minify_start_buffer() {
    if (is_admin() || wp_doing_ajax() || is_feed() || is_preview()) {
        return;
    }
    if (!tk_license_features_enabled()) {
        return;
    }
    if (!tk_get_option('minify_html_enabled', 0)) {
        return;
    }
    if (!apply_filters('tk_minify_html_optimization_enabled', true)) { return; }
    ob_start('tk_minify_buffer_callback');
}

function tk_minify_filter_asset_src($src, $handle) {
    if (is_admin()) {
        return $src;
    }
    if (!tk_license_features_enabled()) {
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
    // preg_* returns null on backtrack/JIT limits (large inline JSON, scripts or <pre>); never emit an empty page.
    $original = $html;

    $minify_inline_css = tk_get_option('minify_inline_css', 1);
    $minify_inline_js = tk_get_option('minify_inline_js', 1);

    $placeholders = array();
    $html = preg_replace_callback(
        '#<textarea\b[^>]*name=(["\'])theme_options\[themes_mix\]\1[^>]*>.*?</textarea>#is',
        function($m) use (&$placeholders) {
            $key = '%%TKMINIFY' . count($placeholders) . '%%';
            $placeholders[$key] = $m[0];
            return $key;
        },
        $html
    );
    if (!is_string($html)) {
        return $original;
    }
    $html = preg_replace_callback(
        '#<(pre|textarea)\b[^>]*>.*?</\1>#is',
        function($m) use (&$placeholders) {
            $key = '%%TKMINIFY' . count($placeholders) . '%%';
            $placeholders[$key] = $m[0];
            return $key;
        },
        $html
    );
    if (!is_string($html)) {
        return $original;
    }

    if ($minify_inline_css) {
        $html = preg_replace_callback(
            '#<style\b([^>]*)>(.*?)</style>#is',
            function($m) {
                $attrs = $m[1];
                $content = $m[2];
                $minified = tk_minify_inline_css($content);
                if (!is_string($minified) || (trim($minified) === '' && trim($content) !== '')) {
                    return $m[0];
                }
                return '<style' . $attrs . '>' . $minified . '</style>';
            },
            $html
        );
        if (!is_string($html)) {
            return $original;
        }
    }

    if ($minify_inline_js) {
        $html = preg_replace_callback(
            '#<script\b([^>]*)>(.*?)</script>#is',
            function($m) {
                $attrs = $m[1];
                $content = $m[2];
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
                // Leave analytics snippets untouched because many themes paste vendor code
                // directly and even conservative whitespace/comment changes can break them.
                $analytics_markers = array(
                    'gtag(',
                    'googletagmanager.com',
                    'google-analytics.com',
                    'dataLayer',
                    'ga(',
                    'G-',
                    'UA-',
                    'AW-',
                );
                foreach ($analytics_markers as $marker) {
                    if (stripos($content, $marker) !== false) {
                        return $m[0];
                    }
                }
                $minified = tk_minify_inline_js($content);
                if (!is_string($minified) || (trim($minified) === '' && trim($content) !== '')) {
                    return $m[0];
                }
                return '<script' . $attrs . '>' . $minified . '</script>';
            },
            $html
        );
        if (!is_string($html)) {
            return $original;
        }
    }

    $html = preg_replace_callback(
        '#<(script|style)\b[^>]*>.*?</\1>#is',
        function($m) use (&$placeholders) {
            $key = '%%TKMINIFY' . count($placeholders) . '%%';
            $placeholders[$key] = $m[0];
            return $key;
        },
        $html
    );
    if (!is_string($html)) {
        return $original;
    }

    $html = preg_replace_callback('/<!--(.*?)-->/s', function($m) {
        $content = $m[1];
        if (stripos($content, '[if') !== false || stripos($content, '<![endif') !== false) {
            return $m[0];
        }
        return '';
    }, $html);
    if (!is_string($html)) {
        return $original;
    }

    // Normalize indentation/newlines without merging attribute or text tokens.
    $html = preg_replace('/[\r\n\t]+/', ' ', $html);
    if (!is_string($html)) {
        return $original;
    }
    $html = preg_replace('/\s{2,}/', ' ', $html);
    if (!is_string($html)) {
        return $original;
    }
    // Keep one space between tags: it is significant between inline elements ("<b>Price:</b> <em>10</em>").
    $html = preg_replace('/>\s+</', '> <', $html);
    if (!is_string($html)) {
        return $original;
    }
    $html = trim($html);

    if (!empty($placeholders)) {
        $html = strtr($html, $placeholders);
    }
    if ($html === '' || stripos($html, '<html') === false) {
        return $original;
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

/**
 * Conservative inline JS minifier: removes comments and redundant whitespace only.
 * One pass over the whole script keeps string, template literal (including nested ${}),
 * regex and comment state across lines; newlines are kept so automatic semicolon
 * insertion behaves exactly as before.
 */
function tk_minify_inline_js($js) {
    if (!is_string($js) || $js === '') {
        return $js;
    }
    $len = strlen($js);
    $out = '';
    $i = 0;
    $pending_space = false;
    $prev = '';        // last significant code character emitted
    $prev_word = '';   // identifier/keyword that ended at $prev
    $word = '';
    $braces = 0;
    $templates = array(); // brace depth at each open ${ ... }
    $regex_keywords = array('return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void', 'throw', 'case', 'do', 'else', 'yield', 'await');
    $is_ident = static function ($ch) {
        return $ch !== '' && (ctype_alnum($ch) || $ch === '_' || $ch === '$' || ord($ch) > 127);
    };
    $emit = static function ($text) use (&$out, &$pending_space) {
        if ($pending_space && $out !== '' && substr($out, -1) !== "\n") {
            $out .= ' ';
        }
        $pending_space = false;
        $out .= $text;
    };
    // Copies template literal text from $i until the closing backtick or a "${".
    $copy_template = static function () use (&$i, $len, $js, &$out, &$templates, &$braces) {
        while ($i < $len) {
            $ch = $js[$i];
            if ($ch === '\\') {
                $out .= substr($js, $i, 2);
                $i += 2;
                continue;
            }
            if ($ch === '`') {
                $out .= '`';
                $i++;
                return;
            }
            if ($ch === '$' && $i + 1 < $len && $js[$i + 1] === '{') {
                $out .= '${';
                $i += 2;
                $templates[] = $braces;
                return;
            }
            $out .= $ch;
            $i++;
        }
    };

    while ($i < $len) {
        $ch = $js[$i];
        $next = $i + 1 < $len ? $js[$i + 1] : '';

        if ($ch === "\n" || $ch === "\r") {
            if ($word !== '') { $prev_word = $word; $word = ''; }
            if ($out !== '' && substr($out, -1) !== "\n") {
                $out .= "\n";
            }
            $pending_space = false;
            $i++;
            continue;
        }
        if ($ch === ' ' || $ch === "\t" || $ch === "\f" || $ch === "\v") {
            if ($word !== '') { $prev_word = $word; $word = ''; }
            $pending_space = true;
            $i++;
            continue;
        }
        if ($ch === '/' && $next === '/') {
            $eol = strpos($js, "\n", $i);
            $i = $eol === false ? $len : $eol;
            continue;
        }
        if ($ch === '/' && $next === '*') {
            $close = strpos($js, '*/', $i + 2);
            if ($close === false) {
                return $js; // unterminated comment: leave the script untouched
            }
            if (strpos(substr($js, $i, $close - $i), "\n") !== false) {
                if ($out !== '' && substr($out, -1) !== "\n") {
                    $out .= "\n";
                }
                $pending_space = false;
            } else {
                $pending_space = true;
            }
            $i = $close + 2;
            continue;
        }
        if ($word !== '' && !$is_ident($ch)) {
            $prev_word = $word;
            $word = '';
        }
        if ($ch === '"' || $ch === "'") {
            $start = $i++;
            while ($i < $len && $js[$i] !== $ch && $js[$i] !== "\n") {
                $i += $js[$i] === '\\' ? 2 : 1;
            }
            $i++;
            $emit(substr($js, $start, $i - $start));
            $prev = $ch;
            $prev_word = '';
            continue;
        }
        if ($ch === '`') {
            $emit('`');
            $i++;
            $copy_template();
            $prev = '`';
            $prev_word = '';
            continue;
        }
        if ($ch === '/') {
            $regex_allowed = $prev === '' || strpos('(,=:[!&|?{};+-*%<>~^}', $prev) !== false
                || ($is_ident($prev) && in_array($prev_word, $regex_keywords, true));
            if ($regex_allowed) {
                $start = $i++;
                $in_class = false;
                while ($i < $len && $js[$i] !== "\n") {
                    $c = $js[$i];
                    if ($c === '\\') { $i += 2; continue; }
                    if ($c === '[') { $in_class = true; }
                    elseif ($c === ']') { $in_class = false; }
                    elseif ($c === '/' && !$in_class) { break; }
                    $i++;
                }
                $i++;
                $emit(substr($js, $start, $i - $start));
                $prev = '/';
                $prev_word = '';
                continue;
            }
        }
        if ($ch === '{') {
            $braces++;
        } elseif ($ch === '}') {
            if (!empty($templates) && end($templates) === $braces) {
                array_pop($templates);
                $emit('}');
                $i++;
                $copy_template();
                $prev = '`';
                $prev_word = '';
                continue;
            }
            $braces--;
        }
        if ($is_ident($ch)) {
            if ($word === '' && $pending_space === false && $is_ident(substr($out, -1))) {
                $pending_space = true;
            }
            $word .= $ch;
        }
        $emit($ch);
        $prev = $ch;
        $i++;
    }
    return trim($out);
}