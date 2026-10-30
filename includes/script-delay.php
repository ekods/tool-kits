<?php
if (!defined('ABSPATH')) { exit; }

if (!function_exists('tk_script_delay_tag')) {
    /**
     * Turns the script_loader_tag output of one handle into delayed placeholders.
     *
     * Since WordPress 6.3 $tag also holds the handle's inline "-js-translations", "-js-before" and
     * "-js-after" scripts. Translations and "before" code only set up globals (wpcf7 = {...}), so it
     * keeps running now; the external file and its "after" code, which calls into that file, are both
     * deferred and replayed in document order by the loader.
     *
     * @param string $marker   Attributes that mark a placeholder for the loader, e.g. 'data-tk-delay="1"'.
     * @param string $src_attr Attribute that carries the deferred script URL.
     */
    function tk_script_delay_tag(string $tag, string $src, string $marker, string $src_attr): string {
        $delayed = preg_replace_callback('#<script\b([^>]*)>(.*?)</script>#is', static function ($m) use ($src, $marker, $src_attr) {
            $attrs = $m[1];
            if (preg_match('/\ssrc\s*=/i', $attrs)) {
                return '<script type="text/plain" ' . $marker . ' ' . $src_attr . '="' . esc_url($src) . '"></script>';
            }
            if (preg_match('/\sid\s*=\s*(["\'])([^"\']*-js-after)\1/i', $attrs, $id)) {
                return '<script type="text/plain" ' . $marker . ' data-tk-inline="1" id="' . esc_attr($id[2]) . '">' . $m[2] . '</script>';
            }
            return $m[0];
        }, $tag);
        return is_string($delayed) ? $delayed : $tag;
    }
}
