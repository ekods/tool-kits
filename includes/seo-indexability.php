<?php
if (!defined('ABSPATH')) { exit; }

function tk_seo_indexability_init(): void {
    add_action('wp_ajax_tk_seo_indexability', 'tk_seo_indexability_ajax');
    add_action('admin_enqueue_scripts', 'tk_seo_indexability_assets');
    // Start before page-cache buffers; process the final HTML after theme/plugin output.
    add_action('template_redirect', 'tk_seo_indexability_start', -2);
    add_filter('robots_txt', 'tk_seo_indexability_robots_filter', PHP_INT_MAX, 2);
}

function tk_seo_indexability_assets(): void {
    if (($_GET['page'] ?? '') !== 'tool-kits-seo' || !tk_is_admin_user()) { return; }
    wp_enqueue_script('tk-seo-indexability', TK_URL . 'assets/seo-indexability.js', array(), tk_asset_version('assets/seo-indexability.js'), true);
}

/** Exact origin matching keeps scans and overrides on this website. */
function tk_seo_indexability_url(string $url): string {
    $url = esc_url_raw(trim($url), array('http', 'https'));
    $parts = wp_parse_url($url);
    $home = wp_parse_url(home_url('/'));
    if (!is_array($parts) || !isset($parts['host'], $parts['scheme']) || isset($parts['user']) || isset($parts['pass'])
        || strtolower($parts['host']) !== strtolower($home['host']) || strtolower($parts['scheme']) !== strtolower($home['scheme'])
        || ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)) !== ($home['port'] ?? ($home['scheme'] === 'https' ? 443 : 80))) { return ''; }
    return preg_replace('/#.*$/', '', $url) ?: '';
}

function tk_seo_indexability_path(string $url): string {
    $parts = wp_parse_url($url);
    return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

/** Return the matching Googlebot group, falling back to * only if none exists. */
function tk_seo_indexability_robots_groups(string $text): array {
    $groups = array(); $agents = array(); $rules = array();
    $flush = function() use (&$groups, &$agents, &$rules): void {
        if ($agents) { $groups[] = array('agents' => $agents, 'rules' => $rules); }
        $agents = array(); $rules = array();
    };
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim(preg_replace('/#.*/', '', $line));
        if (strpos($line, ':') === false) { continue; }
        list($key, $value) = array_map('trim', explode(':', $line, 2));
        $key = strtolower($key);
        if ($key === 'user-agent') {
            if ($rules) { $flush(); }
            $agents[] = strtolower($value);
        } elseif ($agents && in_array($key, array('allow', 'disallow'), true)) {
            $rules[] = array('type' => $key, 'path' => $value);
        }
    }
    $flush();
    $specific = array_filter($groups, function($group) { return in_array('googlebot', $group['agents'], true); });
    return array_values($specific ?: array_filter($groups, function($group) { return in_array('*', $group['agents'], true); }));
}

function tk_seo_indexability_robots_check(string $text, string $path): array {
    $path = tk_seo_indexability_robots_octets($path);
    $best = null; $length = -1;
    foreach (tk_seo_indexability_robots_groups($text) as $group) {
        foreach ($group['rules'] as $rule) {
            $pattern = tk_seo_indexability_robots_octets($rule['path']);
            if ($pattern === '') { continue; }
            $anchored = substr($pattern, -1) === '$';
            if ($anchored) { $pattern = substr($pattern, 0, -1); }
            $regex = '~^' . str_replace('\\*', '.*', preg_quote($pattern, '~')) . ($anchored ? '$' : '') . '~';
            $specificity = strlen(str_replace('*', '', $pattern));
            if (!preg_match($regex, $path)) { continue; }
            if ($specificity > $length || ($specificity === $length && $rule['type'] === 'allow')) {
                $best = $rule; $length = $specificity;
            }
        }
    }
    return array('allowed' => !$best || $best['type'] === 'allow', 'rule' => $best ? ucfirst($best['type']) . ': ' . $best['path'] : 'No matching disallow rule.');
}

function tk_seo_indexability_robots_octets(string $path): string {
    $path = preg_replace_callback('/[\x80-\xFF]/', function($match) { return rawurlencode($match[0]); }, $path);
    return preg_replace_callback('/%[0-9a-f]{2}/i', function($match) {
        $decoded = rawurldecode($match[0]);
        return preg_match('/[a-zA-Z0-9._~-]/', $decoded) ? $decoded : strtoupper($match[0]);
    }, $path);
}

function tk_seo_indexability_has_noindex(string $directives): bool {
    // Ignore headers scoped to other crawlers (e.g. bingbot: noindex).
    $agent = 'googlebot';
    foreach (explode(',', strtolower($directives)) as $part) {
        if (preg_match('/^\s*([a-z][a-z0-9_-]*)\s*:\s*(.*)$/', $part, $match)
            && !in_array($match[1], array('max-snippet', 'max-image-preview', 'max-video-preview', 'unavailable_after'), true)) {
            $agent = $match[1]; $part = $match[2];
        }
        if ($agent === 'googlebot' && preg_match('/\b(noindex|none)\b/', $part)) { return true; }
    }
    return false;
}

function tk_seo_indexability_parse(string $html): array {
    $result = array('meta_noindex' => false, 'canonicals' => array());
    if (!class_exists('DOMDocument')) { return $result; }
    $previous = libxml_use_internal_errors(true);
    try {
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//head/meta') as $node) {
            if (in_array(strtolower(trim($node->getAttribute('name'))), array('robots', 'googlebot'), true)
                && tk_seo_indexability_has_noindex($node->getAttribute('content'))) { $result['meta_noindex'] = true; }
        }
        foreach ($xpath->query('//head/link[@href]') as $node) {
            if (in_array('canonical', preg_split('/\s+/', strtolower(trim($node->getAttribute('rel')))), true)) {
                $result['canonicals'][] = trim($node->getAttribute('href'));
            }
        }
    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    return $result;
}

function tk_seo_indexability_request(string $url) {
    return wp_safe_remote_get($url, array('timeout' => 5, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES,
        'headers' => array('User-Agent' => 'Tool Kits Indexability Audit', 'Cache-Control' => 'no-cache')));
}

/** Discover the current website's public content in batches, without a URL input. */
function tk_seo_indexability_discover(int $page = 1): array {
    $page = max(1, $page);
    $urls = $page === 1 ? array(home_url('/')) : array();
    if ($page === 1) {
        $origin = wp_parse_url(home_url('/'));
        $base = $origin['scheme'] . '://' . $origin['host'] . (isset($origin['port']) ? ':' . $origin['port'] : '');
        foreach (tk_seo_get_redirect_rules() as $rule) {
            if ((int) $rule['enabled'] === 1 && in_array((int) $rule['status'], array(301, 302), true)) {
                $url = tk_seo_indexability_url($base . $rule['from']);
                if ($url !== '') { $urls[] = $url; }
            }
        }
    }
    $types = get_post_types(array('public' => true, 'publicly_queryable' => true), 'names');
    $types = array_values(array_diff($types, array('attachment')));
    $ids = array();
    if ($types) {
        $query = new WP_Query(array(
            'post_type' => $types, 'post_status' => 'publish', 'has_password' => false,
            'posts_per_page' => 100, 'paged' => $page, 'fields' => 'ids',
            'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true,
            'ignore_sticky_posts' => true, 'suppress_filters' => true,
        ));
        $ids = $query->posts;
        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post || $post->post_status !== 'publish' || $post->post_password !== '' || !is_post_type_viewable($post->post_type)) { continue; }
            $url = tk_seo_indexability_url((string) get_permalink($id));
            if ($url !== '') { $urls[] = $url; }
        }
    }
    return array('urls' => array_values(array_unique($urls)), 'has_more' => count($ids) === 100, 'next_page' => $page + 1);
}

function tk_seo_indexability_scan(string $url): array {
    $url = tk_seo_indexability_url($url);
    if ($url === '') { throw new RuntimeException('Enter a URL on this website using its current scheme and port.'); }
    $item = array('url' => $url, 'scanned_at' => time(), 'checks' => array());
    $response = tk_seo_indexability_request($url);
    $ok = !is_wp_error($response);
    $status = $ok ? (int) wp_remote_retrieve_response_code($response) : 0;
    $item['http_status'] = $status;
    $html = $ok && $status === 200 && stripos((string) wp_remote_retrieve_header($response, 'content-type'), 'text/html') !== false;
    $parsed = $html ? tk_seo_indexability_parse((string) wp_remote_retrieve_body($response)) : array('meta_noindex' => false, 'canonicals' => array());
    $headers = $ok ? wp_remote_retrieve_header($response, 'x-robots-tag') : '';
    $header_noindex = false;
    foreach (is_array($headers) ? $headers : array($headers) as $header) {
        if (tk_seo_indexability_has_noindex((string) $header)) { $header_noindex = true; }
    }
    $item['meta_noindex'] = $parsed['meta_noindex'];
    $item['header_noindex'] = $header_noindex;
    $unknown = $ok ? 'HTTP ' . $status . ($html ? '.' : '; no usable HTML response.') : $response->get_error_message();
    $item['checks']['noindex'] = array(
        'label' => 'Excluded by noindex tag',
        'state' => $parsed['meta_noindex'] || $header_noindex ? 'issue' : ($html && class_exists('DOMDocument') ? 'pass' : 'unknown'),
        'detail' => $header_noindex ? 'X-Robots-Tag blocks Google indexing. Remove it in the originating server, CDN, or plugin setting.' : ($parsed['meta_noindex'] ? 'A robots or Googlebot meta tag contains noindex/none.' : ($html ? (class_exists('DOMDocument') ? 'No noindex directive found for Googlebot.' : 'PHP DOM extension is required to inspect HTML meta tags.') : $unknown)),
        'recommendation' => 'Keep intentional exclusions. For a public page that should appear in search, remove its noindex directive.',
    );
    $canonicals = $parsed['canonicals'];
    if ($ok) {
        $link_headers = wp_remote_retrieve_header($response, 'link');
        foreach (is_array($link_headers) ? $link_headers : array($link_headers) as $link_header) {
            preg_match_all('/<([^>]+)>([^<]*)/', (string) $link_header, $links, PREG_SET_ORDER);
            foreach ($links as $link) {
                if (preg_match('/;\s*rel\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s;,]+))/i', $link[2], $rel)) {
                    $value = ($rel[1] ?? '') ?: (($rel[2] ?? '') ?: ($rel[3] ?? ''));
                    if (in_array('canonical', preg_split('/\s+/', strtolower(trim($value))), true)) {
                        $canonicals[] = $link[1]; $item['header_canonical'] = true;
                    }
                }
            }
        }
    }
    $resolved = array_map(function($canonical) use ($url) { return $canonical !== '' ? WP_Http::make_absolute_url($canonical, $url) : ''; }, $canonicals);
    $item['canonicals'] = $resolved;
    $canonical_ok = count($resolved) === 1 && filter_var($resolved[0], FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $resolved[0]);
    $item['checks']['canonical'] = array(
        'label' => 'Duplicate without user-selected canonical',
        'state' => !$html || !class_exists('DOMDocument') ? 'unknown' : ($canonical_ok ? 'pass' : 'issue'),
        'detail' => !$html ? $unknown : (!class_exists('DOMDocument') ? 'PHP DOM extension is required to inspect HTML canonical tags.' : (!$resolved ? 'No canonical was declared. This is a local signal; Google-selected duplicates require URL Inspection.' : 'Declared canonical(s): ' . implode(', ', $resolved))),
        'recommendation' => 'Choose the preferred URL for duplicate content. Use a self-canonical only for a page that should be indexed separately.',
    );
    $origin = wp_parse_url(home_url('/'));
    $robots_url = $origin['scheme'] . '://' . $origin['host'] . (isset($origin['port']) ? ':' . $origin['port'] : '') . '/robots.txt';
    $robots = tk_seo_indexability_request($robots_url);
    $robots_status = is_wp_error($robots) ? 0 : (int) wp_remote_retrieve_response_code($robots);
    $robots_body = is_wp_error($robots) ? '' : (string) wp_remote_retrieve_body($robots);
    $robots_known = $robots_status === 200 && !preg_match('/<(?:!doctype|html|head|body)\b/i', $robots_body);
    $not_found = in_array($robots_status, array(404, 410), true);
    $rule = tk_seo_indexability_robots_check($robots_body, tk_seo_indexability_path($url));
    $item['checks']['robots'] = array(
        'label' => 'Blocked by robots.txt',
        'state' => $not_found ? 'pass' : (!$robots_known ? 'unknown' : ($rule['allowed'] ? 'pass' : 'issue')),
        'detail' => $not_found ? 'robots.txt is absent; no robots.txt block.' : ($robots_known ? $rule['rule'] : 'Could not verify robots.txt (HTTP ' . $robots_status . ').'),
        'recommendation' => 'Allow only this public URL for Googlebot if the block is unintended. Physical robots.txt files must be edited at their source.',
    );
    $location = $ok ? (string) wp_remote_retrieve_header($response, 'location') : '';
    $redirect = in_array($status, array(301, 302, 303, 307, 308), true);
    $item['toolkits_redirect'] = false;
    if ($redirect && tk_seo_tools_enabled()) {
        foreach (tk_seo_get_redirect_rules() as $rule) {
            if ((int) $rule['enabled'] === 1 && $rule['from'] === tk_seo_normalize_path($url) && in_array((int) $rule['status'], array(301, 302), true)) { $item['toolkits_redirect'] = true; break; }
        }
    }
    $item['redirect_to'] = $location !== '' ? WP_Http::make_absolute_url($location, $url) : '';
    $item['checks']['redirect'] = array(
        'label' => 'Page with redirect', 'state' => $redirect ? 'issue' : ($status === 200 ? 'pass' : 'unknown'),
        'detail' => $redirect ? 'HTTP ' . $status . ' → ' . ($item['redirect_to'] ?: 'missing Location header') : $unknown,
        'recommendation' => 'If intentional, keep the redirect and use its destination in links and sitemaps. Remove only an unintended redirect at its source.',
    );
    return $item;
}

/** Fixes only target a published, editable permalink or the site homepage. */
function tk_seo_indexability_editable(string $url, bool $require_enabled = true): bool {
    if (!tk_seo_indexability_public_url($url) || ($require_enabled && !tk_seo_tools_enabled())) { return false; }
    if ($url === home_url('/')) { return current_user_can('manage_options'); }
    $id = url_to_postid($url); $post = $id ? get_post($id) : null;
    return $post && get_permalink($id) === $url && $post->post_status === 'publish' && $post->post_password === ''
        && is_post_type_viewable($post->post_type) && tk_seo_post_feature_enabled('seo', $id) && current_user_can('edit_post', $id);
}

function tk_seo_indexability_fixes(): array {
    $fixes = tk_get_option('seo_indexability_fixes', array());
    return is_array($fixes) ? $fixes : array();
}

function tk_seo_indexability_apply(string $url, string $kind, string $canonical = ''): string {
    if (!tk_seo_indexability_editable($url, $kind !== 'remove')) { throw new RuntimeException('Enable SEO Toolkit and choose an editable public permalink or homepage. Alternate URLs need changes at their source.'); }
    $key = hash('sha256', $url); $fixes = tk_seo_indexability_fixes();
    if ($kind === 'remove') {
        unset($fixes[$key]);
        tk_update_option('seo_indexability_fixes', $fixes);
        return 'Removed the Tool Kits page overrides for this URL. Redirect rules remain managed in Redirects.';
    }
    $fresh = tk_seo_indexability_scan($url);
    if (!in_array($kind, array('noindex', 'canonical', 'robots', 'redirect'), true) || $fresh['checks'][$kind]['state'] !== 'issue') {
        throw new RuntimeException('This issue is no longer present. Run the check again.');
    }
    if ($kind === 'redirect') {
        $rules = tk_seo_get_redirect_rules(); $changed = false;
        foreach ($rules as &$rule) {
            if ((int) $rule['enabled'] === 1 && $rule['from'] === tk_seo_normalize_path($url) && in_array((int) $rule['status'], array(301, 302), true)) {
                $rule['enabled'] = 0; $changed = true;
            }
        }
        unset($rule);
        if (!$changed) { throw new RuntimeException('This redirect is managed by WordPress, another plugin, or the server/CDN. Edit that source; use the destination URL when the redirect is intentional.'); }
        tk_update_option('seo_redirect_rules', $rules);
        return 'Disabled the matching Tool Kits redirect. Add the rule again in Redirects to restore it if needed.';
    }
    if ($kind === 'noindex') {
        if ((string) get_option('blog_public') !== '1') { throw new RuntimeException('Search engine visibility is disabled site-wide. Review Settings > Reading before enabling indexing for this site.'); }
        if (!empty($fresh['header_noindex'])) { throw new RuntimeException('Remove X-Robots-Tag noindex at its originating server, CDN, or plugin setting, then recheck.'); }
    }
    if ($kind === 'canonical') {
        if (!empty($fresh['header_canonical'])) { throw new RuntimeException('A canonical HTTP Link header is present. Correct that header at its source before overriding the HTML canonical.'); }
        $canonical = tk_seo_indexability_url($canonical);
        if ($canonical === '') { throw new RuntimeException('Enter the preferred canonical URL on this website.'); }
        $target = tk_seo_indexability_scan($canonical);
        if ($target['http_status'] !== 200 || $target['checks']['noindex']['state'] !== 'pass' || $target['checks']['robots']['state'] !== 'pass') {
            throw new RuntimeException('The canonical target must return HTML with HTTP 200, allow indexing, and allow Googlebot crawling.');
        }
    }
    if ($kind === 'robots' && !preg_match('~^/[^\r\n#*$]*$~', tk_seo_indexability_path($url))) {
        throw new RuntimeException('This path contains robots.txt wildcard characters. Edit its crawling policy at the source.');
    }
    $fixes[$key] = array_merge($fixes[$key] ?? array(), array('url' => $url, 'updated_at' => time()));
    $fixes[$key][$kind] = $kind === 'canonical' ? $canonical : true;
    tk_update_option('seo_indexability_fixes', $fixes);
    return $kind === 'robots' ? 'Saved an exact-URL allow rule for virtual robots.txt. If the recheck remains blocked, apply the suggested Allow rule to the physical file or server/CDN source.' : 'Saved the reviewed per-URL ' . $kind . ' override.';
}

function tk_seo_indexability_ajax(): void {
    if (!tk_is_admin_user() || !tk_license_features_enabled()) { wp_send_json_error(array('message' => 'Forbidden'), 403); }
    check_ajax_referer('tk_seo_indexability', 'nonce');
    $url = tk_seo_indexability_url((string) tk_post('url', ''));
    try {
        $operation = (string) tk_post('operation', 'scan');
        if ($operation === 'discover') {
            $page = max(1, (int) tk_post('page', 1));
            $batch = tk_seo_indexability_discover($page);
            if ($page === 1) { tk_update_option('seo_indexability_report', array()); }
            wp_send_json_success($batch);
        }
        if ($url === '') { throw new RuntimeException('Enter a URL on this website.'); }
        $message = '';
        if ($operation === 'fix') {
            $message = tk_seo_indexability_apply($url, (string) tk_post('kind', ''), (string) tk_post('canonical', ''));
            if (function_exists('tk_page_cache_purge')) { tk_page_cache_purge(); }
            if (function_exists('tk_server_cache_purge_layers')) { tk_server_cache_purge_layers(); }
            // Recheck in a separate request so a slow site cannot exhaust this request's time budget.
            wp_send_json_success(array('message' => $message));
        } elseif ($operation !== 'scan') { throw new RuntimeException('Unknown operation.'); }
        $item = tk_seo_indexability_scan($url);
        $item['editable'] = tk_seo_indexability_editable($url);
        $item['has_fix'] = isset(tk_seo_indexability_fixes()[hash('sha256', $url)]);
        $report = tk_get_option('seo_indexability_report', array());
        $report = is_array($report) ? $report : array();
        unset($report[hash('sha256', $url)]);
        $report[hash('sha256', $url)] = $item;
        tk_update_option('seo_indexability_report', $report);
        wp_send_json_success(array('item' => $item, 'message' => $message));
    } catch (RuntimeException $exception) { wp_send_json_error(array('message' => $exception->getMessage()), 400); }
}

/** Add exact Allow rules inside existing matching groups; retain all other policy. */
function tk_seo_indexability_robots_allow(string $text, array $paths): string {
    $groups = tk_seo_indexability_robots_groups($text);
    $agent = '*';
    foreach ($groups as $group) { if (in_array('googlebot', $group['agents'], true)) { $agent = 'googlebot'; break; } }
    $allow = '';
    foreach (array_unique($paths) as $path) {
        if (preg_match('~^/[^\r\n#*$]*$~', $path)) { $allow .= 'Allow: ' . $path . "$\n"; }
    }
    if ($allow === '') { return $text; }
    $lines = preg_split('/\r\n|\r|\n/', $text); $agents = array(); $has_rules = false; $inserted = false; $out = '';
    foreach ($lines as $line) {
        if (preg_match('/^\s*user-agent\s*:\s*([^#\s]+)/i', $line, $match)) {
            if ($has_rules) {
                if (in_array($agent, $agents, true)) { $out .= $allow; $inserted = true; }
                $agents = array(); $has_rules = false;
            }
            $agents[] = strtolower($match[1]);
        } elseif (preg_match('/^\s*(?:allow|disallow)\s*:/i', $line)) { $has_rules = true; }
        $out .= $line . "\n";
    }
    if (in_array($agent, $agents, true)) { $out .= $allow; $inserted = true; }
    if (!$inserted) { $out .= "\nUser-agent: *\n" . $allow; }
    return $out;
}

function tk_seo_indexability_robots_filter($text, $public) {
    if (!$public || !tk_seo_tools_enabled()) { return $text; }
    $paths = array();
    foreach (tk_seo_indexability_fixes() as $fix) {
        if (!empty($fix['robots']) && tk_seo_indexability_public_url((string) ($fix['url'] ?? ''))) { $paths[] = tk_seo_indexability_path($fix['url']); }
    }
    return $paths ? tk_seo_indexability_robots_allow($text, $paths) : $text;
}

function tk_seo_indexability_public_url(string $url): bool {
    if (tk_seo_indexability_url($url) === '') { return false; }
    $id = url_to_postid($url);
    if (!$id && $url === home_url('/') && get_option('show_on_front') === 'page') { $id = (int) get_option('page_on_front'); }
    if (!$id) { return $url === home_url('/'); }
    $post = get_post($id);
    return $post && get_permalink($id) === $url && $post->post_status === 'publish' && $post->post_password === '' && is_post_type_viewable($post->post_type)
        && tk_seo_post_feature_enabled('seo', $id);
}

function tk_seo_indexability_start(): void {
    if (!tk_seo_tools_enabled() || is_admin() || wp_doing_ajax() || is_feed() || is_preview() || is_search() || is_404()
        || (defined('REST_REQUEST') && REST_REQUEST) || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', array('GET', 'HEAD'), true)) { return; }
    $home = wp_parse_url(home_url('/'));
    $url = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '') . ($_SERVER['REQUEST_URI'] ?? '/');
    $fix = tk_seo_indexability_fixes()[hash('sha256', $url)] ?? array();
    if (!$fix || !tk_seo_indexability_public_url($url)) { return; }
    if ((string) get_option('blog_public') !== '1') { unset($fix['noindex']); }
    if (empty($fix['noindex']) && empty($fix['canonical'])) { return; }
    ob_start(function($html) use ($fix) {
        if (http_response_code() >= 300) { return $html; }
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) { return $html; }
        }
        return tk_seo_indexability_fix_html($html, $fix);
    });
}

/** Edit only real head tags, skipping scripts/comments and preserving other markup. */
function tk_seo_indexability_fix_html(string $html, array $fix): string {
    $attributes = '(?:"[^"]*"|\'[^\']*\'|[^\'">])*';
    $pattern = '~<!--.*?-->|<(script|style|textarea|title)\b' . $attributes . '>.*?</\1\s*>|<head\b' . $attributes . '>|</head\s*>|<(?:meta|link)\b' . $attributes . '>~is';
    preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE);
    $in_head = false; $edits = array();
    foreach ($matches[0] as $match) {
        $tag = $match[0];
        if (preg_match('/^<head\b/i', $tag)) { $in_head = true; continue; }
        if (preg_match('/^<\/head\s*>/i', $tag)) {
            if ($in_head && !empty($fix['canonical'])) { $edits[] = array($match[1], 0, '<link rel="canonical" href="' . esc_url($fix['canonical']) . '">'); }
            break;
        }
        if (!$in_head || !preg_match('/^<(meta|link)\b/i', $tag, $type)) { continue; }
        $attrs = array();
        preg_match_all('/\s([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $tag, $parts, PREG_SET_ORDER);
        foreach ($parts as $part) { $attrs[strtolower($part[1])] = html_entity_decode(($part[2] ?? '') !== '' ? $part[2] : (($part[3] ?? '') !== '' ? $part[3] : ($part[4] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
        if (strtolower($type[1]) === 'link' && !empty($fix['canonical']) && in_array('canonical', preg_split('/\s+/', strtolower(trim($attrs['rel'] ?? ''))), true)) {
            $edits[] = array($match[1], strlen($tag), '');
        } elseif (strtolower($type[1]) === 'meta' && !empty($fix['noindex']) && in_array(strtolower(trim($attrs['name'] ?? '')), array('robots', 'googlebot'), true)) {
            $content = preg_replace('/\bnone\b/i', 'nofollow', $attrs['content'] ?? '');
            $content = trim(preg_replace('/\bnoindex\b\s*,?/i', '', $content), " ,\t\r\n");
            $replacement = '<meta name="' . esc_attr($attrs['name']) . '" content="' . esc_attr($content !== '' ? $content : 'index') . '">';
            $edits[] = array($match[1], strlen($tag), $replacement);
        }
    }
    usort($edits, function($a, $b) { return $b[0] <=> $a[0]; });
    foreach ($edits as $edit) { $html = substr_replace($html, $edit[2], $edit[0], $edit[1]); }
    return $html;
}

function tk_seo_indexability_panel(): void {
    $report = tk_get_option('seo_indexability_report', array());
    $report = is_array($report) ? array_values($report) : array();
    ?>
    <div id="tk-seo-indexability" data-nonce="<?php echo esc_attr(wp_create_nonce('tk_seo_indexability')); ?>" data-home="<?php echo esc_attr(home_url('/')); ?>">
        <h3>Indexing Checks &amp; Fixes</h3>
        <p>Check this website automatically for noindex tags and headers, declared canonical URLs, Googlebot robots.txt rules, and HTTP redirects. The scan discovers the homepage, published pages, posts, public custom content, and active Tool Kits redirect sources.</p>
        <p>Website: <strong><?php echo esc_html(home_url('/')); ?></strong></p>
        <p class="description">These are live website checks. Google's duplicate classification and indexing status still require Search Console URL Inspection. Keep intentional exclusions and redirects.</p>
        <?php if (!tk_seo_tools_enabled()): ?><p>Enable SEO Toolkit in Settings to apply per-URL fixes.</p><?php endif; ?>
        <p><button type="button" class="button button-primary" data-indexability-scan>Check Current Website</button> <button type="button" class="button button-secondary" data-indexability-fix-all disabled>Fix All</button> <button type="button" class="button" data-indexability-stop hidden disabled>Stop Fix All</button> <a class="button" href="<?php echo esc_url(admin_url('options-reading.php')); ?>">Search Engine Visibility</a> <a class="button" href="<?php echo esc_url(tk_admin_url('tool-kits-seo') . '#redirects'); ?>">Manage Redirects</a></p>
        <p><label><input type="checkbox" data-indexability-include-redirects> Include disabling Tool Kits redirects</label></p>
        <p class="description">Fix All processes editable findings from the current report and rechecks each change. Missing canonical tags use the page permalink; choose a preferred URL below for conflicting canonicals. Intentional redirects are kept unless included. Server/CDN rules and physical robots.txt files need correction at their source.</p>
        <p data-indexability-status role="status" aria-live="polite"></p>
        <div data-indexability-bulk-results aria-live="polite"></div>
        <div data-indexability-results></div>
        <script type="application/json" class="tk-indexability-saved"><?php echo wp_json_encode($report, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    </div>
    <?php
}
