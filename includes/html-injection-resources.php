<?php
if (!defined('ABSPATH')) { exit; }

/** Resolve only local .css under approved WordPress asset roots, with a realpath boundary. */
function tk_html_scan_css_path(string $url): string {
    $parts = TK_HTML_Injection_Context::url($url, home_url('/'));
    if (!$parts || !TK_HTML_Injection_Context::same_origin($parts, home_url('/')) || !preg_match('/\.css$/iD', $parts['path'])) { return ''; }
    $path = rawurldecode($parts['path']);
    if (preg_match('~(?:^|/)\.{1,2}(?:/|$)|[\x00-\x20\x7f%\\\\]~', $path)) { return ''; }
    $roots = array();
    if (defined('WP_CONTENT_DIR') && function_exists('content_url')) { $roots[] = array(content_url('/'), WP_CONTENT_DIR); }
    if (function_exists('includes_url')) { $roots[] = array(includes_url('/'), ABSPATH . 'wp-includes'); }
    foreach ($roots as $root) {
        $prefix = parse_url($root[0], PHP_URL_PATH) ?: '/'; $prefix = rtrim($prefix, '/') . '/';
        if (strpos($path, $prefix) !== 0) { continue; }
        $base = realpath($root[1]); $file = realpath(rtrim($root[1], '/') . '/' . substr($path, strlen($prefix)));
        if ($base && $file && preg_match('/\.css$/iD', $file) && strpos($file, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0 && is_file($file) && is_readable($file)) { return $file; }
    }
    return '';
}

function tk_html_scan_queue_css(array &$state, array $urls, string $base, array $pages = array()): void {
    foreach ($urls as $url) {
        $parts = TK_HTML_Injection_Context::url($url, $base);
        if (!$parts || !TK_HTML_Injection_Context::same_origin($parts, home_url('/'))) { continue; }
        $absolute = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $parts['path'];
        $file = tk_html_scan_css_path($absolute);
        if ($file === '') { tk_html_scan_issue($state, 'A local stylesheet was unavailable or outside approved asset roots; its contents were not checked.'); continue; }
        $key = hash('sha256', $file);
        $new_pages = array_diff($pages, $state['css_pages'][$key] ?? array());
        $state['css_pages'][$key] = array_slice(array_values(array_unique(array_merge($state['css_pages'][$key] ?? array(), $pages))), 0, 50);
        if (isset($state['css_seen'][$key])) {
            if ($new_pages) {
                foreach ($state['report']['findings'] as &$finding) { if (($finding['asset_key'] ?? '') === $key) { $finding['affected_urls'] = $state['css_pages'][$key]; $finding['occurrences'] = max(1, count($finding['affected_urls'])); } } unset($finding);
                if (isset($state['css_imports'][$key])) { tk_html_scan_queue_css($state, $state['css_imports'][$key]['urls'], $state['css_imports'][$key]['base'], $state['css_pages'][$key]); }
            }
            continue;
        }
        if (count($state['css_seen'] ?? array()) >= 100) { tk_html_scan_issue($state, 'Stylesheet sample limited to 100 distinct local files.'); return; }
        $state['css_seen'][$key] = true; $state['css_queue'][] = array('file' => $file, 'url' => $absolute);
    }
}

function tk_html_scan_css_step(array &$state): void {
    for ($batch = 0; $batch < 2; $batch++) {
        $item = array_shift($state['css_queue']);
        if (!$item) { $state['phase'] = 'done'; return; }
        if (tk_html_scan_css_path($item['url']) !== $item['file']) { tk_html_scan_issue($state, 'Stylesheet path changed or no longer resolves inside an approved root.'); continue; }
        $handle = @fopen($item['file'], 'rb');
        if (!$handle) { tk_html_scan_issue($state, 'Stylesheet could not be opened for reading.'); continue; }
        try { $css = stream_get_contents($handle, TK_HTML_Injection_Engine::MAX_BYTES + 1); } finally { fclose($handle); }
        if (!is_string($css) || strlen($css) > TK_HTML_Injection_Engine::MAX_BYTES) { tk_html_scan_issue($state, 'Stylesheet exceeds the 512 KiB analysis limit or could not be read.'); continue; }
        $hash = hash('sha256', $css); $key = hash('sha256', $item['url']);
        $source = 'CSS file / ' . (parse_url($item['url'], PHP_URL_PATH) ?: '/');
        $state['report']['files_analyzed'] = ($state['report']['files_analyzed'] ?? 0) + 1;
        $cache_key = hash('sha256', $hash . '|' . dirname($item['url']));
        if (isset($state['css_cache'][$cache_key])) { $result = $state['css_cache'][$cache_key]; }
        else {
            $result = TK_HTML_Injection_CSS::scan($css, $item['url'], $state['settings']['trusted_hosts'], $state['providers'] ?? TK_HTML_Injection_Context::providers());
            if (count($state['css_cache'] ?? array()) < 20) { $state['css_cache'][$cache_key] = $result; }
        }
        if ($result['limited']) { tk_html_scan_issue($state, $source . ': CSS parsing budget/grammar exceeded; contents require review.'); }
        $pages = $state['css_pages'][hash('sha256', $item['file'])] ?? array();
        $asset_key = hash('sha256', $item['file']);
        foreach ($result['findings'] as $finding) { $finding['asset_key'] = $asset_key; $finding['affected_urls'] = $pages; $finding['occurrences'] = max(1, count($pages)); tk_html_scan_record($state, $finding, $source); }
        $state['css_imports'][$asset_key] = array('urls' => array_slice($result['resources'], 0, 100), 'base' => $item['url']);
        tk_html_scan_queue_css($state, $result['resources'], $item['url'], $pages);
        if (isset($state['baseline'][$key]) && $state['baseline'][$key] !== $hash) {
            tk_html_scan_record($state, array('rule' => 'css_file_changed', 'rule_id' => 'CSS_BASELINE_CHANGED_001', 'severity' => 'low', 'action' => 'REVIEW', 'classification' => 'integrity_change', 'confidence' => .99, 'reason' => 'Stylesheet differs from the first manually scanned reference hash. Updates/builds may be legitimate; this is not proof of compromise.', 'line' => 1, 'host' => '', 'snippet' => 'SHA256 reference ' . $state['baseline'][$key] . ' → current ' . $hash, 'location' => 'file', 'evidence_hash' => $hash, 'resource_identifier' => $key, 'asset_key' => $asset_key, 'affected_urls' => $pages, 'occurrences' => max(1, count($pages))), $source);
        } elseif (!isset($state['baseline'][$key]) && count($state['baseline'] ?? array()) < 300) { $state['baseline'][$key] = $hash; $state['baseline_dirty'] = true; }
        elseif (!isset($state['baseline'][$key])) { tk_html_scan_issue($state, 'CSS reference budget reached (300 files); new files were analyzed without an integrity reference.'); }
    }
    if (!$state['css_queue']) { $state['phase'] = 'done'; }
}
