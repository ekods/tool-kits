<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Restore the Polylang language on self-referencing canonical and og:url tags.
 *
 * Polylang filters home_url() to the language home only when the result is the bare
 * home URL. Themes that normalize URLs with home_url('/') and then home_url($path)
 * drop the language: /id/kontak/ declares /kontak/, id.site.com declares site.com.
 * The language is added back with Polylang's own links model, so directory (with or
 * without the /language/ base), subdomain, multiple-domain and ?lang= modes behave
 * exactly like Polylang. Only a URL that then equals the current request is rewritten,
 * which leaves deliberate cross-language canonicals and the hidden default language alone.
 */
function tk_seo_language_canonical_localizer(): ?callable {
    if (!function_exists('PLL') || !function_exists('pll_current_language')) { return null; }
    $lang = (string) pll_current_language('slug');
    $links = PLL()->links_model ?? null;
    if ($lang === '' || !is_object($links) || !method_exists($links, 'add_language_to_link')) { return null; }
    return function (string $url) use ($links, $lang): string {
        return (string) $links->add_language_to_link($url, $lang);
    };
}

function tk_seo_language_canonical_start(): void {
    if (is_admin() || wp_doing_ajax() || is_feed() || is_preview() || is_404()
        || (defined('REST_REQUEST') && REST_REQUEST)
        || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', array('GET', 'HEAD'), true)
        || !tk_license_features_enabled()) { return; }
    $localize = tk_seo_language_canonical_localizer();
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!$localize || $host === '') { return; }
    $request = (is_ssl() ? 'https' : 'http') . '://' . $host . (string) wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
    ob_start(function ($html) use ($localize, $request) {
        $status = http_response_code();
        if ($status && $status !== 200) { return $html; }
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) { return $html; }
        }
        return tk_seo_language_canonical_fix_html($html, $localize, $request);
    });
}

/** Split a URL into a host/port/decoded-path key and its query arguments. */
function tk_seo_language_canonical_parts(string $url): array {
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) { return array('', array()); }
    parse_str((string) ($parts['query'] ?? ''), $query);
    return array(strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '')
        . '/' . trim(rawurldecode((string) ($parts['path'] ?? '')), '/'), $query);
}

/**
 * Return the localized URL when it is the current request, or '' to leave the URL as is.
 * Every query argument of the localized URL (?lang=, ?page_id= on plain permalinks) must
 * match the request; extra request arguments such as tracking parameters are ignored.
 */
function tk_seo_language_canonical_url(string $url, callable $localize, string $request_url): string {
    if ($url === '') { return ''; }
    $fixed = $localize($url);
    if ($fixed === '' || $fixed === $url) { return ''; }
    list($key, $query) = tk_seo_language_canonical_parts($fixed);
    list($request_key, $request_query) = tk_seo_language_canonical_parts($request_url);
    if ($key === '' || $key !== $request_key) { return ''; }
    foreach ($query as $name => $value) {
        if (!array_key_exists($name, $request_query) || $request_query[$name] !== $value) { return ''; }
    }
    return $fixed;
}

/** Rewrite canonical/og:url in real head tags only, skipping scripts and comments. */
function tk_seo_language_canonical_fix_html(string $html, callable $localize, string $request_url): string {
    $attributes = '(?:"[^"]*"|\'[^\']*\'|[^\'">])*';
    $pattern = '~<!--.*?-->|<(script|style|textarea|title)\b' . $attributes . '>.*?</\1\s*>|<head\b' . $attributes . '>|</head\s*>|<(?:meta|link)\b' . $attributes . '>~is';
    if (!preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE)) { return $html; }
    $in_head = false; $edits = array();
    foreach ($matches[0] as $match) {
        $tag = $match[0];
        if (preg_match('/^<head\b/i', $tag)) { $in_head = true; continue; }
        if (preg_match('/^<\/head\s*>/i', $tag)) { break; }
        if (!$in_head || !preg_match('/^<(meta|link)\b/i', $tag, $type)) { continue; }
        $attrs = array();
        preg_match_all('/\s([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $tag, $parts, PREG_SET_ORDER);
        foreach ($parts as $part) { $attrs[strtolower($part[1])] = html_entity_decode(($part[2] ?? '') !== '' ? $part[2] : (($part[3] ?? '') !== '' ? $part[3] : ($part[4] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
        if (strtolower($type[1]) === 'link' && in_array('canonical', preg_split('/\s+/', strtolower(trim($attrs['rel'] ?? ''))), true)) {
            $attribute = 'href';
        } elseif (strtolower($type[1]) === 'meta' && strtolower(trim($attrs['property'] ?? '')) === 'og:url') {
            $attribute = 'content';
        } else {
            continue;
        }
        $fixed = tk_seo_language_canonical_url((string) ($attrs[$attribute] ?? ''), $localize, $request_url);
        if ($fixed === '') { continue; }
        $replacement = $attribute === 'href'
            ? '<link rel="canonical" href="' . esc_url($fixed) . '">'
            : '<meta property="og:url" content="' . esc_url($fixed) . '">';
        $edits[] = array($match[1], strlen($tag), $replacement);
    }
    foreach (array_reverse($edits) as $edit) { $html = substr_replace($html, $edit[2], $edit[0], $edit[1]); }
    return $html;
}
