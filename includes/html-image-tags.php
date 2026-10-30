<?php
if (!defined('ABSPATH')) { exit; }

if (!function_exists('tk_html_map_img_tags')) {
    /**
     * Applies $callback to each <img> tag in page markup, skipping script, style, template,
     * textarea and noscript bodies and HTML comments: an "<img>" inside JavaScript or JSON-LD
     * is a string, and adding double-quoted attributes there breaks the script.
     * Returns the original HTML when a regex hits PCRE limits.
     */
    function tk_html_map_img_tags(string $html, callable $callback): string {
        $found = preg_match_all(
            '#<!--.*?-->|<(script|style|template|textarea|noscript)\b[^>]*>.*?</\1\s*>#is',
            $html,
            $protected,
            PREG_OFFSET_CAPTURE
        );
        if ($found === false) {
            return $html;
        }
        $out = '';
        $pos = 0;
        foreach ($protected[0] as $block) {
            $mapped = preg_replace_callback('/<img\b[^>]*>/i', $callback, substr($html, $pos, $block[1] - $pos));
            if (!is_string($mapped)) {
                return $html;
            }
            $out .= $mapped . $block[0];
            $pos = $block[1] + strlen($block[0]);
        }
        $mapped = preg_replace_callback('/<img\b[^>]*>/i', $callback, substr($html, $pos));
        return is_string($mapped) ? $out . $mapped : $html;
    }
}
