<?php
if (!defined('ABSPATH')) { exit; }

/** Real public TTFB sample. Fixed home_url, no redirects, cookies or arbitrary targets. */
function tk_health_center_public_request(): array {
    $url = home_url('/'); $response = array('ttfb' => null, 'status' => 0, 'headers' => array(), 'body' => '', 'error' => '', 'truncated' => false);
    if (!function_exists('curl_init') || !wp_http_validate_url($url) || wp_parse_url($url, PHP_URL_QUERY)) { $response['error'] = 'PHP cURL unavailable or homepage URL rejected by WordPress HTTP safety checks.'; return $response; }
    if (class_exists('WP_Http') && (new WP_Http())->block_request($url)) { $response['error'] = 'Outbound HTTP is blocked by site configuration.'; return $response; }
    $handle = curl_init($url);
    curl_setopt_array($handle, array(CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Tool-Kits-Health-Center/' . TK_VERSION, CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => array('Accept: text/html')));
    if (class_exists('WP_HTTP_Proxy')) {
        $proxy = new WP_HTTP_Proxy();
        if ($proxy->is_enabled() && $proxy->send_through_proxy($url)) {
            curl_setopt($handle, CURLOPT_PROXY, $proxy->host()); curl_setopt($handle, CURLOPT_PROXYPORT, $proxy->port());
            if ($proxy->use_authentication()) { curl_setopt($handle, CURLOPT_PROXYUSERPWD, $proxy->authentication()); }
        }
    }
    curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($curl, $line) use (&$response) {
        if (preg_match('~^HTTP/\S+\s+\d+~', $line)) { $response['headers'] = array(); }
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) { $key = strtolower(trim($parts[0])); if (in_array($key, array('content-type', 'content-security-policy', 'x-frame-options', 'x-content-type-options', 'referrer-policy', 'x-robots-tag', 'cache-control', 'expires', 'date', 'x-tool-kits-cache'), true)) { $response['headers'][$key] = substr(trim($parts[1]), 0, 8192); } }
        return strlen($line);
    });
    curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function ($curl, $chunk) use (&$response) {
        $remaining = 524288 - strlen($response['body']);
        $response['body'] .= substr($chunk, 0, max(0, $remaining));
        if (strlen($chunk) > $remaining) { $response['truncated'] = true; return 0; }
        return strlen($chunk);
    });
    try {
        $ok = curl_exec($handle);
        $response['status'] = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $ttfb = (float) curl_getinfo($handle, CURLINFO_STARTTRANSFER_TIME);
        if ($ttfb > 0) { $response['ttfb'] = $ttfb; }
        if ($ok === false && !$response['truncated']) { $response['error'] = 'Homepage request failed (cURL ' . curl_errno($handle) . ').'; }
    } finally { curl_close($handle); }
    return $response;
}

function tk_health_center_request_profile(array &$state): void {
    $probe = bin2hex(random_bytes(24)); $state['probe'] = $probe;
    set_transient('tk_health_center_probe_' . hash('sha256', $probe), array('expires' => time() + 120, 'url' => home_url('/')), 120);
    $response = wp_safe_remote_get(home_url('/'), array('timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'cookies' => array(), 'limit_response_size' => 2097152, 'headers' => array('X-Tool-Kits-Health-Probe' => $probe, 'Cookie' => 'tk_health_center_session=1', 'Cache-Control' => 'no-cache'), 'user-agent' => 'Tool-Kits-Health-Profile/' . TK_VERSION));
    $state['profile_response_ok'] = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200 && wp_remote_retrieve_header($response, 'x-tool-kits-health-probe') === hash('sha256', $probe);
}

function tk_health_center_clean_probe(string $probe): void {
    $id = hash('sha256', $probe);
    delete_transient('tk_health_center_probe_' . $id); delete_transient('tk_health_center_profile_' . $id);
}

function tk_health_center_finish_profile(array &$state): void {
    $profile = !empty($state['probe']) ? get_transient('tk_health_center_profile_' . hash('sha256', $state['probe'])) : false;
    $valid = !empty($state['profile_response_ok']) && is_array($profile) && !empty($profile['completed']);
    if ($valid) { $state['report']['performance']['queries'] = $profile['queries']; $state['report']['performance']['slow_http'] = $profile['slow_http']; }
    $p = $state['report']['performance'];
    tk_health_check($state['report'], 'Performance', 'Homepage database query count', $valid ? tk_health_metric_status($p['queries'], 150, 300) : 'unknown', $valid ? $p['queries'] . ' queries in the uncached profiled homepage request (includes measurement overhead); SQL text is not collected.' : 'Profile not verified. Loopback, proxy/cache, authentication or request timeout may prevent measurement.');
    tk_health_check($state['report'], 'Performance', 'Slow outbound HTTP requests', $valid ? ($p['slow_http'] ? 'warning' : 'passed') : 'unknown', $valid ? $p['slow_http'] . ' completed WP HTTP API requests ≥1 second, observed after Tool Kits loaded; requests before it loaded or using direct cURL are not included.' : 'Verified homepage profile unavailable.');
    if (!empty($state['probe'])) { tk_health_center_clean_probe($state['probe']); }
}

/** Ordinary frontend requests do no profiling work, including no option/transient lookups here. */
function tk_health_center_maybe_profile(): void {
    $probe = $_SERVER['HTTP_X_TOOL_KITS_HEALTH_PROBE'] ?? '';
    if (!is_string($probe) || !preg_match('/^[a-f0-9]{48}$/D', $probe) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { return; }
    $id = hash('sha256', $probe); $config = get_transient('tk_health_center_probe_' . $id);
    if (!is_array($config) || $config['expires'] < time() || !tk_license_features_enabled()) { return; }
    $home = wp_parse_url($config['url']);
    if (($_SERVER['REQUEST_URI'] ?? '') !== ($home['path'] ?? '/') || strcasecmp($_SERVER['HTTP_HOST'] ?? '', ($home['host'] ?? '') . (isset($home['port']) ? ':' . $home['port'] : '')) !== 0) { return; }
    if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
    $starts = array(); $slow = 0;
    add_filter('pre_http_request', static function ($pre, $args, $url) use (&$starts) {
        if ($pre === false) { $key = hash('sha256', $url); $starts[$key][] = microtime(true); }
        return $pre;
    }, PHP_INT_MAX, 3);
    add_action('http_api_debug', static function ($response, $context, $transport, $args, $url) use (&$starts, &$slow) {
        $key = hash('sha256', $url);
        if ($context === 'response' && !empty($starts[$key])) { $start = array_pop($starts[$key]); if (microtime(true) - $start >= 1) { $slow++; } if (!$starts[$key]) { unset($starts[$key]); } }
    }, PHP_INT_MAX, 5);
    add_action('send_headers', static function () use ($id) { nocache_headers(); header('X-Tool-Kits-Health-Probe: ' . $id); }, PHP_INT_MAX);
    add_action('shutdown', static function () use ($id, &$slow) {
        global $wpdb;
        if (http_response_code() !== 200 || function_exists('is_404') && is_404() || function_exists('is_user_logged_in') && is_user_logged_in()) { return; }
        $fatal = error_get_last();
        if ($fatal && in_array($fatal['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) { return; }
        $queries = (int) $wpdb->num_queries;
        set_transient('tk_health_center_profile_' . $id, array('completed' => time(), 'queries' => $queries, 'slow_http' => $slow), 120);
    }, PHP_INT_MAX);
}
tk_health_center_maybe_profile();
