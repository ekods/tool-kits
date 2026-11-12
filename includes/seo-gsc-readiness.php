<?php
if (!defined('ABSPATH')) { exit; }

/** Read-only, site-level checklist for Google Search Console readiness. Each item runs in its own AJAX request. */
function tk_gsc_readiness_init(): void {
    add_action('wp_ajax_tk_gsc_readiness', 'tk_gsc_readiness_ajax');
    add_action('admin_enqueue_scripts', 'tk_gsc_readiness_assets');
}

function tk_gsc_readiness_assets(): void {
    if (($_GET['page'] ?? '') !== 'tool-kits-seo' || !tk_is_admin_user()) { return; }
    wp_enqueue_script('tk-gsc-readiness', TK_URL . 'assets/gsc-readiness.js', array(), tk_asset_version('assets/gsc-readiness.js'), true);
}

function tk_gsc_readiness_items(): array {
    return array(
        'verification' => 'Ownership verification tag',
        'visibility' => 'Site is open to indexing',
        'https_host' => 'HTTPS and a single host version',
        'robots' => 'robots.txt allows crawling and lists the sitemap',
        'sitemap' => 'Sitemap is reachable and valid',
        'basics' => 'Homepage basics (title, description, mobile, H1, canonical)',
        'schema' => 'Structured data is valid',
        'security' => 'No malware findings (Security issues report)',
    );
}

function tk_gsc_readiness_result(string $key, string $state, string $detail, string $recommendation = ''): array {
    return array('key' => $key, 'label' => tk_gsc_readiness_items()[$key], 'state' => $state, 'detail' => $detail, 'recommendation' => $recommendation);
}

/** Homepage response, shared by several items for a short time so the checklist makes one request for it. */
function tk_gsc_readiness_home(bool $refresh = false): array {
    $cached = $refresh ? false : get_transient('tk_gsc_readiness_home');
    if (is_array($cached)) { return $cached; }
    $response = wp_safe_remote_get(home_url('/'), array('timeout' => 15, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES,
        'headers' => array('User-Agent' => 'Tool Kits GSC Readiness', 'Cache-Control' => 'no-cache')));
    $home = is_wp_error($response)
        ? array('error' => $response->get_error_message(), 'status' => 0, 'robots_header' => array(), 'html' => '')
        : array('error' => '', 'status' => (int) wp_remote_retrieve_response_code($response),
            'robots_header' => array_values(array_filter((array) wp_remote_retrieve_header($response, 'x-robots-tag'), 'strlen')),
            'html' => stripos((string) wp_remote_retrieve_header($response, 'content-type'), 'text/html') !== false ? (string) wp_remote_retrieve_body($response) : '');
    set_transient('tk_gsc_readiness_home', $home, 120);
    return $home;
}

/** Parse the homepage head once with DOM. Returns null when the DOM extension or HTML is unavailable. */
function tk_gsc_readiness_dom(string $html): ?DOMXPath {
    if ($html === '' || !class_exists('DOMDocument')) { return null; }
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    return new DOMXPath($document);
}

function tk_gsc_readiness_fetch(string $url, float $timeout = 10.0) {
    return wp_safe_remote_get($url, array('timeout' => $timeout, 'redirection' => 0, 'limit_response_size' => 5 * MB_IN_BYTES,
        'headers' => array('User-Agent' => 'Tool Kits GSC Readiness', 'Cache-Control' => 'no-cache')));
}

function tk_gsc_readiness_check(string $key, bool $refresh = false): array {
    $home = tk_gsc_readiness_home($refresh && $key === 'verification');
    $xpath = tk_gsc_readiness_dom($home['html']);
    $home_failed = $home['status'] !== 200 || !$xpath;
    $home_detail = $home['error'] !== '' ? $home['error'] : ($home['status'] !== 200 ? 'Homepage returned HTTP ' . $home['status'] . '.' : 'Homepage HTML could not be inspected (PHP DOM extension required).');

    switch ($key) {
        case 'verification':
            if ($home_failed) { return tk_gsc_readiness_result($key, 'unknown', $home_detail, 'Recheck once the homepage responds with HTTP 200.'); }
            $found = $xpath->query('//head/meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="google-site-verification"][string-length(@content)>0]')->length > 0;
            return $found ? tk_gsc_readiness_result($key, 'pass', 'A google-site-verification meta tag is present on the homepage.')
                : tk_gsc_readiness_result($key, 'review', 'No google-site-verification meta tag was found. Verification through DNS, Google Analytics, Tag Manager, or an uploaded HTML file cannot be detected from here.', 'Add the meta tag from Search Console, or confirm the property is already verified another way.');

        case 'visibility':
            if ((string) get_option('blog_public') !== '1') { return tk_gsc_readiness_result($key, 'issue', 'Settings > Reading has "Discourage search engines from indexing this site" enabled.', 'Disable it in Settings > Reading.'); }
            if ($home['status'] === 0) { return tk_gsc_readiness_result($key, 'unknown', $home_detail); }
            foreach ($home['robots_header'] as $header) {
                if (tk_seo_indexability_has_noindex($header)) { return tk_gsc_readiness_result($key, 'issue', 'The homepage sends an X-Robots-Tag noindex header.', 'Remove it in the originating server, CDN, or plugin setting.'); }
            }
            if ($xpath && tk_seo_indexability_parse($home['html'])['meta_noindex']) { return tk_gsc_readiness_result($key, 'issue', 'The homepage has a robots meta tag containing noindex.', 'Remove noindex from the homepage.'); }
            if ($home['status'] !== 200) { return tk_gsc_readiness_result($key, 'issue', 'Homepage returned HTTP ' . $home['status'] . '.', 'Make the homepage return HTTP 200 without redirecting.'); }
            return tk_gsc_readiness_result($key, 'pass', 'Search engine visibility is on and the homepage has no noindex directive.');

        case 'https_host':
            $parts = wp_parse_url(home_url('/'));
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (($parts['scheme'] ?? '') !== 'https') { return tk_gsc_readiness_result($key, 'issue', 'The site URL uses http.', 'Serve the site over HTTPS and update Settings > General.'); }
            $problems = array(); $notes = array();
            $http = tk_gsc_readiness_fetch('http://' . $host . ($parts['path'] ?? '/'), 8.0);
            if (!is_wp_error($http)) {
                $code = (int) wp_remote_retrieve_response_code($http);
                if (!in_array($code, array(301, 302, 307, 308), true) || stripos((string) wp_remote_retrieve_header($http, 'location'), 'https://') !== 0) { $problems[] = 'http:// answers HTTP ' . $code . ' instead of redirecting to https://'; }
            } else { $notes[] = 'http:// is not reachable (acceptable)'; }
            if (strpos($host, '.') !== false && !filter_var($host, FILTER_VALIDATE_IP)) {
                $alt = strpos($host, 'www.') === 0 ? substr($host, 4) : 'www.' . $host;
                $other = tk_gsc_readiness_fetch('https://' . $alt . ($parts['path'] ?? '/'), 8.0);
                if (!is_wp_error($other)) {
                    $code = (int) wp_remote_retrieve_response_code($other);
                    if ($code === 200) { $problems[] = $alt . ' serves the site as well as ' . $host . ' without redirecting'; }
                    elseif (in_array($code, array(301, 302, 307, 308), true)) { $notes[] = $alt . ' redirects (HTTP ' . $code . ')'; }
                } else { $notes[] = $alt . ' is not served'; }
            }
            return $problems ? tk_gsc_readiness_result($key, 'issue', implode('; ', $problems) . '.', 'Redirect every alternate scheme and host to the one preferred URL with a 301.')
                : tk_gsc_readiness_result($key, 'pass', 'HTTPS is used' . ($notes ? ' (' . implode('; ', $notes) . ')' : '') . '.');

        case 'robots':
            $response = tk_gsc_readiness_fetch(home_url('/robots.txt'));
            if (is_wp_error($response)) { return tk_gsc_readiness_result($key, 'unknown', $response->get_error_message(), 'Recheck once robots.txt responds.'); }
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            if ($code >= 500) { return tk_gsc_readiness_result($key, 'issue', 'robots.txt returned HTTP ' . $code . '. Google may pause crawling the whole site.', 'Fix the server error so robots.txt returns 200 or 404.'); }
            if ($code !== 200 || preg_match('/<(?:!doctype|html|head|body)\b/i', $body)) {
                return tk_gsc_readiness_result($key, 'review', 'No robots.txt was found (HTTP ' . $code . '). Crawling is allowed, but there is no Sitemap line.', 'Add a robots.txt that includes a Sitemap: line.');
            }
            $problems = array();
            if (!tk_seo_indexability_robots_check($body, '/')['allowed']) { $problems[] = 'Googlebot is blocked from the entire site'; }
            foreach (array('/wp-includes/js/jquery/jquery.min.js' => 'JavaScript', '/wp-content/themes/' => 'theme CSS/JS') as $path => $name) {
                if (!tk_seo_indexability_robots_check($body, $path)['allowed']) { $problems[] = 'Googlebot is blocked from ' . $name . ' (' . $path . ')'; }
            }
            if ($problems) { return tk_gsc_readiness_result($key, 'issue', implode('; ', $problems) . '.', 'Allow Googlebot to crawl the site and its rendering resources.'); }
            if (!preg_match('/^\s*sitemap\s*:\s*\S+/im', $body)) { return tk_gsc_readiness_result($key, 'review', 'robots.txt allows crawling but has no Sitemap: line.', 'Add "Sitemap: ' . (function_exists('tk_general_sitemap_url') ? tk_general_sitemap_url() : home_url('/sitemap.xml')) . '".'); }
            return tk_gsc_readiness_result($key, 'pass', 'robots.txt allows Googlebot, does not block rendering resources, and lists a sitemap.');

        case 'sitemap':
            $sitemap = function_exists('tk_general_sitemap_url') ? tk_general_sitemap_url() : home_url('/sitemap.xml');
            $response = tk_gsc_readiness_fetch($sitemap, 15.0);
            if (is_wp_error($response)) { return tk_gsc_readiness_result($key, 'unknown', $response->get_error_message(), 'Recheck once the sitemap responds.'); }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code !== 200) { return tk_gsc_readiness_result($key, 'issue', $sitemap . ' returned HTTP ' . $code . '.', 'Enable the sitemap in SEO settings or correct its path.'); }
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_string((string) wp_remote_retrieve_body($response), 'SimpleXMLElement', LIBXML_NONET);
            libxml_clear_errors(); libxml_use_internal_errors($previous);
            if (!$xml || !in_array($xml->getName(), array('urlset', 'sitemapindex'), true)) { return tk_gsc_readiness_result($key, 'issue', 'The sitemap is not valid sitemap XML.', 'Regenerate the sitemap and check for stray output before the XML declaration.'); }
            $count = count($xml->children());
            if ($count === 0) { return tk_gsc_readiness_result($key, 'issue', 'The sitemap contains no entries.', 'Make sure public content types are included.'); }
            $foreign = 0;
            foreach ($xml->children() as $entry) { if (tk_seo_indexability_url((string) $entry->loc) === '') { $foreign++; } }
            if ($foreign) { return tk_gsc_readiness_result($key, 'issue', $foreign . ' of ' . $count . ' sitemap entries use a different scheme or host than the site URL.', 'List only canonical URLs on the same origin as the property.'); }
            $submitted = tk_get_option('sitemap_google_access_token', '') !== '' ? ' Google submission credentials are configured.' : '';
            return tk_gsc_readiness_result($key, 'pass', 'Valid ' . $xml->getName() . ' with ' . $count . ' entries on the site origin.' . $submitted, 'Submit it under Sitemaps in Search Console if you have not yet.');

        case 'basics':
            if ($home_failed) { return tk_gsc_readiness_result($key, 'unknown', $home_detail, 'Recheck once the homepage responds with HTTP 200.'); }
            $missing = array();
            $title = trim((string) $xpath->evaluate('string(//head/title)'));
            if ($title === '') { $missing[] = 'title'; }
            if ($xpath->query('//head/meta[@name="description"][string-length(normalize-space(@content))>0]')->length === 0) { $missing[] = 'meta description'; }
            if ($xpath->query('//head/meta[@name="viewport"]')->length === 0) { $missing[] = 'viewport meta (mobile usability)'; }
            $h1 = $xpath->query('//h1')->length;
            if ($h1 !== 1) { $missing[] = $h1 === 0 ? 'an H1 heading' : 'a single H1 (found ' . $h1 . ')'; }
            $canonicals = array();
            foreach ($xpath->query('//head/link[@href]') as $node) {
                if (in_array('canonical', preg_split('/\s+/', strtolower(trim($node->getAttribute('rel')))), true)) { $canonicals[] = trim($node->getAttribute('href')); }
            }
            if (count($canonicals) !== 1) { $missing[] = count($canonicals) ? 'one canonical (found ' . count($canonicals) . ')' : 'a canonical link'; }
            elseif (untrailingslashit(WP_Http::make_absolute_url($canonicals[0], home_url('/'))) !== untrailingslashit(home_url('/'))) { $missing[] = 'a self-referencing canonical (points to ' . $canonicals[0] . ')'; }
            return $missing ? tk_gsc_readiness_result($key, 'issue', 'Homepage is missing or has problems with: ' . implode(', ', $missing) . '.', 'Fix these in SEO settings or the theme.')
                : tk_gsc_readiness_result($key, 'pass', 'Title, meta description, viewport, a single H1, and a self-canonical are present.');

        case 'schema':
            if ($home_failed) { return tk_gsc_readiness_result($key, 'unknown', $home_detail, 'Recheck once the homepage responds with HTTP 200.'); }
            $blocks = $xpath->query('//script[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]');
            if ($blocks->length === 0) { return tk_gsc_readiness_result($key, 'review', 'No JSON-LD structured data on the homepage. It is optional, but needed for rich results.', 'Add Organization and WebSite schema, and Article schema on posts.'); }
            $problems = array(); $types = array();
            foreach ($blocks as $index => $block) {
                $data = json_decode(trim($block->textContent), true);
                if (!is_array($data)) { $problems[] = 'block ' . ($index + 1) . ' is not valid JSON'; continue; }
                $nodes = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : (array_keys($data) === range(0, count($data) - 1) ? $data : array($data));
                foreach ($nodes as $node) {
                    if (!is_array($node)) { continue; }
                    $type = $node['@type'] ?? '';
                    $type = is_array($type) ? (string) reset($type) : (string) $type;
                    if ($type === '') { $problems[] = 'block ' . ($index + 1) . ' has an item without @type'; continue; }
                    $types[$type] = true;
                    $required = array('Organization' => array('name'), 'WebSite' => array('url'), 'Article' => array('headline', 'datePublished'), 'NewsArticle' => array('headline', 'datePublished'), 'BlogPosting' => array('headline', 'datePublished'), 'Product' => array('name'), 'LocalBusiness' => array('name', 'address'));
                    foreach ($required[$type] ?? array() as $property) { if (empty($node[$property])) { $problems[] = $type . ' is missing "' . $property . '"'; } }
                }
            }
            return $problems ? tk_gsc_readiness_result($key, 'issue', implode('; ', array_unique($problems)) . '.', 'Correct the JSON-LD and confirm with the Rich Results Test.')
                : tk_gsc_readiness_result($key, 'pass', 'Valid JSON-LD found (' . implode(', ', array_keys($types)) . '). Rich-result eligibility still needs the Rich Results Test.');

        case 'security':
            $report = tk_get_option('malware_scan_report', array());
            if (!is_array($report) || empty($report['time'])) { return tk_gsc_readiness_result($key, 'unknown', 'No malware scan has been run yet.', 'Run the Malware Scanner; hacked content triggers "Security issues" in Search Console.'); }
            $findings = count((array) ($report['results'] ?? array()));
            $when = wp_date('Y-m-d H:i', (int) $report['time']);
            if ($findings) { return tk_gsc_readiness_result($key, 'issue', $findings . ' file(s) flagged in the scan of ' . $when . '.', 'Review and clean the findings, then rescan.'); }
            if (time() - (int) $report['time'] > 30 * DAY_IN_SECONDS) { return tk_gsc_readiness_result($key, 'review', 'The last clean scan was on ' . $when . ', over 30 days ago.', 'Run the Malware Scanner again.'); }
            return tk_gsc_readiness_result($key, 'pass', 'The scan of ' . $when . ' found nothing.');
    }
    return tk_gsc_readiness_result('security', 'unknown', 'Unknown check.');
}

function tk_gsc_readiness_ajax(): void {
    if (!tk_is_admin_user() || !tk_license_features_enabled()) { wp_send_json_error(array('message' => 'Forbidden'), 403); }
    check_ajax_referer('tk_gsc_readiness', 'nonce');
    $key = (string) tk_post('item', '');
    if (!isset(tk_gsc_readiness_items()[$key])) { wp_send_json_error(array('message' => 'Unknown check.'), 400); }
    wp_send_json_success(array('item' => tk_gsc_readiness_check($key, (string) tk_post('first', '') === '1')));
}

function tk_gsc_readiness_panel(): void {
    ?>
    <div id="tk-gsc-readiness" data-nonce="<?php echo esc_attr(wp_create_nonce('tk_gsc_readiness')); ?>" data-items="<?php echo esc_attr(wp_json_encode(array_keys(tk_gsc_readiness_items()))); ?>">
        <h3>Search Console Readiness</h3>
        <p>A read-only checklist of what Google Search Console expects from this website. It changes nothing; follow each recommendation at its source.</p>
        <p><button type="button" class="button button-primary" data-gsc-run>Run Readiness Check</button></p>
        <p data-gsc-summary role="status" aria-live="polite"></p>
        <ul data-gsc-results style="margin:0;padding:0;list-style:none;"></ul>
    </div>
    <?php
}
