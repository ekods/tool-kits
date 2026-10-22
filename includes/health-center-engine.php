<?php
if (!defined('ABSPATH')) { exit; }

/** Unknown checks never count as passed. These helpers do no I/O. */
function tk_health_check(array &$report, string $category, string $label, string $status, string $detail): void {
    $report['checks'][] = array('category' => $category, 'label' => $label, 'status' => $status, 'detail' => $detail);
}

function tk_health_scores(array $checks): array {
    $categories = array_fill_keys(array('Security', 'Performance', 'SEO', 'Compatibility'), null);
    $counts = array('critical' => 0, 'warning' => 0, 'passed' => 0, 'unknown' => 0);
    foreach ($categories as $category => $_) {
        $points = 0; $measured = 0;
        foreach ($checks as $check) {
            if ($check['category'] !== $category) { continue; }
            $status = $check['status'];
            if (isset($counts[$status])) { $counts[$status]++; }
            if ($status === 'unknown') { continue; }
            $measured++; $points += $status === 'passed' ? 100 : ($status === 'warning' ? 50 : 0);
        }
        if ($measured) { $categories[$category] = (int) round($points / $measured); }
    }
    $measured = array_filter($categories, static function ($value) { return $value !== null; });
    return array('overall' => $measured ? (int) round(array_sum($measured) / count($measured)) : null, 'categories' => $categories, 'counts' => $counts);
}

function tk_health_metric_status($number, float $warning, float $critical): string {
    if ($number === null) { return 'unknown'; }
    return $number >= $critical ? 'critical' : ($number >= $warning ? 'warning' : 'passed');
}

function tk_health_memory_bytes(string $value): ?int {
    if (trim($value) === '-1') { return -1; }
    if (!preg_match('/^\s*([0-9]+)\s*([KMG]?)\s*$/iD', $value, $matches)) { return null; }
    return (int) $matches[1] * (1024 ** (int) strpos(' KMG', strtoupper($matches[2] ?: ' ')));
}

function tk_health_analyze_public(array &$report, array $response): void {
    $metrics =& $report['performance'];
    $metrics['ttfb'] = $response['ttfb'] ?? null;
    $metrics['cache'] = $response['headers']['x-tool-kits-cache'] ?? 'Not reported';
    $error = $response['error'] ?? '';
    $success = $error === '' && ($response['status'] ?? 0) === 200 && stripos($response['headers']['content-type'] ?? '', 'text/html') !== false;
    tk_health_check($report, 'Performance', 'Homepage TTFB', $success ? tk_health_metric_status($metrics['ttfb'], 0.8, 1.8) : 'unknown', $success ? 'Public homepage, first byte including connection/TLS; one sample, not a site-wide average.' : 'Not measured: ' . ($error ?: 'homepage did not return HTTP 200 HTML.'));
    if (!$success) { $metrics['ttfb'] = null; }
    tk_health_check($report, 'Compatibility', 'Public homepage response', $error !== '' ? 'unknown' : ($success ? 'passed' : 'critical'), $error ?: 'HTTP ' . (int) ($response['status'] ?? 0));
    $headers = $success ? $response['headers'] : array();
    foreach (array('x-content-type-options' => 'MIME sniffing protection', 'content-security-policy' => 'Content Security Policy', 'referrer-policy' => 'Referrer policy') as $key => $label) {
        $present = !empty($headers[$key]);
        if ($key === 'x-content-type-options') { $present = strtolower(trim($headers[$key] ?? '')) === 'nosniff'; }
        tk_health_check($report, 'Security', $label, !$success ? 'unknown' : ($present ? 'passed' : 'warning'), !$success ? 'Homepage unavailable.' : ($present ? 'Response header present.' : 'Expected response header missing or invalid.'));
    }
    $frames = !empty($headers['x-frame-options']) || preg_match('/(?:^|;)\s*frame-ancestors\s/i', $headers['content-security-policy'] ?? '');
    tk_health_check($report, 'Security', 'Frame embedding protection', !$success ? 'unknown' : ($frames ? 'passed' : 'warning'), !$success ? 'Homepage unavailable.' : ($frames ? 'X-Frame-Options or CSP frame-ancestors present.' : 'No frame embedding restriction observed.'));
    $cache = strtolower($headers['cache-control'] ?? '');
    $age = preg_match('/(?:^|,)\s*max-age\s*=\s*"?(\d+)/i', $cache, $m) ? (int) $m[1] : null;
    $expires = isset($headers['expires']) ? strtotime($headers['expires']) : false;
    $date = isset($headers['date']) ? strtotime($headers['date']) : time();
    $long = $age !== null ? $age > 86400 : ($expires !== false && $expires - ($date ?: time()) > 86400);
    tk_health_check($report, 'Performance', 'HTML browser cache lifetime', !$success ? 'unknown' : ($long ? 'critical' : 'passed'), !$success ? 'Homepage unavailable.' : ($long ? 'HTML remains fresh in browsers for over 24 hours; stale content and expired WP nonces are possible.' : 'No HTML browser freshness above 24 hours observed.'));
    $html = $success && class_exists('DOMDocument') && empty($response['truncated']) && trim($response['body'] ?? '') !== '';
    $document = null;
    if ($html) {
        $previous = libxml_use_internal_errors(true);
        try { $document = new DOMDocument(); $html = $document->loadHTML($response['body'], LIBXML_NONET); }
        catch (Throwable $error) { $html = false; }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }
    $title = ''; $description = ''; $canonical = ''; $robots = strtolower($headers['x-robots-tag'] ?? ''); $title_count = 0;
    if ($html) {
        $title_count = $document->getElementsByTagName('title')->length;
        if ($title_count) { $title = trim($document->getElementsByTagName('title')->item(0)->textContent); }
        foreach ($document->getElementsByTagName('meta') as $node) {
            $name = strtolower($node->getAttribute('name'));
            if ($name === 'description') { $description = trim($node->getAttribute('content')); }
            if (in_array($name, array('robots', 'googlebot'), true)) { $robots .= ' ' . strtolower($node->getAttribute('content')); }
        }
        foreach ($document->getElementsByTagName('link') as $node) { if (preg_match('/(?:^|\s)canonical(?:\s|$)/i', $node->getAttribute('rel'))) { $canonical = trim($node->getAttribute('href')); } }
    }
    foreach (array(
        array('Homepage title', $title_count === 1 && $title !== '', 'Exactly one non-empty title is expected.'),
        array('Meta description', $description !== '', 'Homepage meta description should be present.'),
        array('Canonical URL', (bool) filter_var($canonical, FILTER_VALIDATE_URL), 'Homepage should declare an absolute canonical URL.'),
        array('Search indexing', !preg_match('/\b(?:noindex|none)\b/i', $robots), 'Checks homepage robots directives; intentional noindex sites may ignore this warning.')
    ) as $check) { tk_health_check($report, 'SEO', $check[0], !$html ? 'unknown' : ($check[1] ? 'passed' : 'warning'), !$html ? 'Complete homepage HTML/DOM parser unavailable.' : $check[2]); }
}
