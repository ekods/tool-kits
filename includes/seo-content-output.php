<?php
if (!defined('ABSPATH')) { exit; }

/** Explicit per-page edits own these fields, including on theme-managed sites. */
function tk_seo_content_output_enabled(): bool {
    return !is_admin() && !wp_doing_ajax() && !is_feed()
        && !(defined('REST_REQUEST') && REST_REQUEST)
        && is_singular() && tk_seo_tools_enabled() && tk_seo_post_feature_enabled('seo');
}

function tk_seo_content_output_values(int $post_id): array {
    $values = array();
    foreach (array('title' => '_tk_seo_title', 'description' => '_tk_seo_meta_description') as $field => $key) {
        $value = tk_seo_expand_variables((string) get_post_meta($post_id, $key, true), $post_id);
        if ($value !== '') { $values[$field] = $value; }
    }
    return $values;
}

function tk_seo_content_output_start(): void {
    if (!tk_seo_content_output_enabled() || is_preview() || get_post_status() !== 'publish' || post_password_required()
        || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', array('GET', 'HEAD'), true)) { return; }
    $values = tk_seo_content_output_values((int) get_queried_object_id());
    if (!$values) { return; }
    $social = (int) tk_get_option('seo_og_enabled', 1) === 1;
    if ($social) {
        $image = tk_seo_social_preview_image((int) get_queried_object_id());
        if ($image !== '') { $values['image'] = $image; }
    }
    // Start just inside page caching and outside later title/GEO/theme buffers.
    ob_start(function ($html) use ($values, $social) {
        $status = http_response_code();
        if ($status && $status !== 200) { return $html; }
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) { return $html; }
        }
        return tk_seo_content_replace_html($html, $values, $social);
    });
}

/** Replace owned head tags by offset, preserving body, schema and unrelated tags. */
function tk_seo_content_replace_html(string $html, array $values, bool $add_social = true): string {
    $targets = array(); $required = array();
    foreach (array('title', 'description') as $field) {
        if (trim((string) ($values[$field] ?? '')) === '') { continue; }
        $targets[$field] = $values[$field]; $required[$field] = true;
        $targets['og:' . $field] = $targets['twitter:' . $field] = $values[$field];
        if ($add_social) { $required['og:' . $field] = true; }
    }
    if (!$targets) { return $html; }
    if ($add_social && !empty($values['image'])) {
        $targets['og:image'] = $targets['twitter:image'] = $values['image'];
        $required['og:image'] = true;
    }
    $tag_for = function (string $key) use ($targets): string {
        if ($key === 'title') { return '<title>' . esc_html($targets[$key]) . '</title>'; }
        $attribute = strpos($key, 'og:') === 0 ? 'property' : 'name';
        return '<meta ' . $attribute . '="' . esc_attr($key) . '" content="' . esc_attr($targets[$key]) . '">';
    };
    $attributes = '(?:"[^"]*"|\'[^\']*\'|[^\'">])*';
    $pattern = '~<!--.*?-->|<(script|style|textarea|template|noscript|svg|math|title)(?=[\s>])' . $attributes . '>.*?</\1\s*>|<(?:html|head|meta)(?=[\s/>])' . $attributes . '>|</head\s*>~is';
    preg_match_all($pattern, $html, $tokens, PREG_OFFSET_CAPTURE);
    $in_html = false; $in_head = false; $closed = false; $seen = array(); $edits = array();
    foreach ($tokens[0] as $token) {
        $tag = $token[0];
        if (preg_match('/^<html(?=[\s>])/i', $tag)) { $in_html = true; continue; }
        if ($in_html && preg_match('/^<head(?=[\s>])/i', $tag)) { $in_head = true; continue; }
        if ($in_head && preg_match('/^<\/head\s*>/i', $tag)) {
            $missing = array();
            foreach ($required as $key => $unused) { if (!isset($seen[$key])) { $missing[] = $tag_for($key); } }
            if ($missing) { $edits[] = array($token[1], 0, implode("\n", $missing) . "\n"); }
            $closed = true; break;
        }
        if (!$in_head) { continue; }
        $key = '';
        if (preg_match('/^<title(?=[\s>])/i', $tag)) { $key = 'title'; }
        elseif (preg_match('~^<meta(?=[\s/>])~i', $tag)) {
            preg_match_all('/\s+([a-z_:][a-z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $matches, PREG_SET_ORDER);
            $attrs = array();
            foreach ($matches as $match) {
                $name = strtolower($match[1]);
                if (!isset($attrs[$name])) { $attrs[$name] = html_entity_decode($match[2] !== '' ? $match[2] : (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
            }
            $property = strtolower(trim($attrs['property'] ?? ''));
            $key = $property !== '' ? $property : strtolower(trim($attrs['name'] ?? ''));
        }
        if (!isset($targets[$key])) { continue; }
        $edits[] = array($token[1], strlen($tag), isset($seen[$key]) ? '' : $tag_for($key));
        $seen[$key] = true;
    }
    if (!$closed) { return $html; }
    foreach (array_reverse($edits) as $edit) { $html = substr_replace($html, $edit[2], $edit[0], $edit[1]); }
    return $html;
}
