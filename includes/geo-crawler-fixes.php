<?php
if (!defined('ABSPATH')) { exit; }

// Inspect actual tags, skipping comments and raw text so examples in scripts do
// not count as metadata. Keep offsets to repair empty tags without rewriting HTML.
function tk_geo_crawler_metadata(string $html): array {
    $result = array('title' => '', 'description' => '', 'author' => '', 'publisher' => '', 'keywords' => '', 'image' => '', 'canonical' => '', 'schema' => false, 'h1' => false, 'empty' => array());
    $attributes = '(?:"[^"]*"|\'[^\']*\'|[^\'">])*';
    $pattern = '~<!--.*?-->|<(script|style|textarea|title)\b' . $attributes . '>.*?</\1\s*>|<(meta|link)\b' . $attributes . '>~is';
    preg_match_all($pattern, $html, $tokens, PREG_OFFSET_CAPTURE);
    foreach ($tokens[0] as $token) {
        $tag = $token[0];
        if (strpos($tag, '<!--') === 0) { continue; }
        preg_match('/^<([a-z]+)/i', $tag, $name);
        $name = strtolower($name[1] ?? '');
        preg_match('~^<[a-z]+\b' . $attributes . '>~i', $tag, $opening);
        $attrs = array();
        preg_match_all('/\s+([a-z_:][a-z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $opening[0] ?? '', $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = strtolower($match[1]);
            if (!isset($attrs[$key])) {
                $attrs[$key] = html_entity_decode($match[2] !== '' ? $match[2] : (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? '')), ENT_QUOTES, 'UTF-8');
            }
        }
        $key = '';
        $value = '';
        if ($name === 'script' && strtolower(trim($attrs['type'] ?? '')) === 'application/ld+json') {
            $result['schema'] = true; // Invalid existing schema needs editing, not another document.
        } elseif ($name === 'title') {
            $key = 'title';
            $value = html_entity_decode(wp_strip_all_tags(preg_replace('/<\/title\s*>$/i', '', substr($tag, strlen($opening[0])))), ENT_QUOTES, 'UTF-8');
        } elseif ($name === 'meta' && in_array(strtolower(trim($attrs['name'] ?? '')), array('description', 'author', 'publisher', 'keywords'), true)) {
            $key = strtolower(trim($attrs['name']));
            $value = $attrs['content'] ?? '';
        } elseif ($name === 'meta' && strtolower(trim($attrs['property'] ?? $attrs['name'] ?? '')) === 'og:image') {
            $key = 'image';
            $value = $attrs['content'] ?? '';
        } elseif ($name === 'link' && in_array('canonical', preg_split('/\s+/', strtolower(trim($attrs['rel'] ?? ''))), true)) {
            $key = 'canonical';
            $value = $attrs['href'] ?? '';
        }
        if ($key !== '') {
            if (trim($value) !== '') {
                $result[$key] = trim($value);
            } else {
                $result['empty'][$key][] = array($token[1], strlen($tag));
            }
        }
    }
    $visible = preg_replace('~<!--.*?-->|<(script|style|textarea|noscript)\b[^>]*>.*?</\1\s*>~is', '', $html);
    $result['h1'] = is_string($visible) && preg_match('/<h1\b[^>]*>.*?<\/h1\s*>/is', $visible) === 1;
    return $result;
}

function tk_geo_crawler_issues(array $row): array {
    if (empty($row['ok'])) {
        $status = (int) ($row['status'] ?? 0);
        $detail = $status === 403 || $status === 429
            ? 'Review firewall/CDN crawler rules and rate limits, then scan again.'
            : ($status === 404 ? 'Restore the page or correct the URL, then scan again.' : 'Check server availability, TLS and redirects, then scan again.');
        return array('fetch' => 'Fetch issue. ' . $detail);
    }
    $issues = array();
    foreach (array('title' => 'Title', 'description' => 'Meta description', 'author' => 'Author', 'publisher' => 'Publisher', 'canonical' => 'Canonical') as $key => $label) {
        if (trim((string) ($row[$key] ?? '')) === '') { $issues[$key] = $label . ' missing.'; }
    }
    if ((int) ($row['schema_invalid'] ?? 0) > 0) {
        $issues['invalid_schema'] = 'Invalid JSON-LD. Correct the existing schema in its theme/plugin source.';
    } elseif ((int) ($row['schema_documents'] ?? 0) === 0) {
        $issues['schema'] = 'JSON-LD missing.';
    }
    return $issues;
}

function tk_geo_crawler_fix_url(string $url): string {
    $parts = wp_parse_url($url);
    $home = wp_parse_url(home_url('/'));
    if (!is_array($parts) || !is_array($home) || isset($parts['user']) || isset($parts['pass'])
        || !in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true)
        || strtolower($parts['scheme']) !== strtolower($home['scheme'] ?? '')
        || strcasecmp($parts['host'] ?? '', $home['host'] ?? '') !== 0
        || ($parts['port'] ?? null) !== ($home['port'] ?? null)) { return ''; }
    // Query variants can be previews, searches or private views; repair public paths only.
    if (!empty($parts['query'])) { return ''; }
    return esc_url_raw(strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
        . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '/'));
}

function tk_geo_crawler_missing_findings(array $report): array {
    $findings = array();
    foreach (($report['agents'] ?? array($report)) as $row) {
        if (!is_array($row)) { continue; }
        foreach (tk_geo_crawler_issues($row) as $field => $message) {
            if (!isset($findings[$field])) { $findings[$field] = array('message' => $message, 'agents' => array()); }
            $agent = trim((string) ($row['agent'] ?? $row['label'] ?? ''));
            if ($agent !== '') { $findings[$field]['agents'][$agent] = $agent; }
        }
    }
    return $findings;
}

function tk_geo_metadata_identity(string $url): array {
    $publisher = trim((string) get_bloginfo('name'));
    if (function_exists('tk_seo_organization_data')) {
        $organization = tk_seo_organization_data();
        if (is_array($organization) && trim((string) ($organization['name'] ?? '')) !== '') {
            $publisher = trim((string) $organization['name']);
        }
    }
    $author = $publisher;
    if (function_exists('tk_seo_global_enabled') && tk_seo_global_enabled()) {
        $global = tk_seo_global_content();
        $publisher = $global['publisher']['value'] ?: $publisher;
        $author = $global['author']['value'] ?: $publisher;
    }
    $post_id = url_to_postid($url);
    if ($post_id > 0) {
        $post = get_post($post_id);
        $author_id = is_object($post) ? (int) ($post->post_author ?? 0) : 0;
        $post_author = $author_id > 0 ? trim((string) get_the_author_meta('display_name', $author_id)) : '';
        if ($post_author !== '') { $author = $post_author; }
    }
    return array('author' => $author, 'publisher' => $publisher);
}

function tk_geo_crawler_fix_handler(): void {
    tk_require_admin_post('tk_geo_crawler_fix');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('Use the Fix Missing Metadata form to apply changes.'); }
    if (!tk_license_features_enabled()) { wp_die('An active Tool Kits license is required.'); }
    $report = tk_get_option('geo_crawler_preview', array());
    $url = tk_geo_crawler_fix_url((string) tk_post('crawler_fix_url', ''));
    if ($url === '' || !is_array($report) || $url !== tk_geo_crawler_fix_url((string) ($report['url'] ?? ''))) {
        wp_die('Run Crawler Preview for this public URL before applying a fix.');
    }
    $fixes = tk_get_option('geo_crawler_fixes', array());
    $fixes = is_array($fixes) ? $fixes : array();
    if (tk_post('crawler_fix_mode', '') === 'remove') {
        unset($fixes[$url]);
    } else {
        $fields = array_values(array_intersect(array_keys(tk_geo_crawler_missing_findings($report)), array('title', 'description', 'author', 'publisher', 'canonical', 'schema')));
        if (!$fields) { wp_die('No missing metadata can be fixed in this report. Scan again to check the page.'); }
        $fields = array_values(array_unique(array_merge($fixes[$url]['fields'] ?? array(), $fields)));
        $identity = tk_geo_metadata_identity($url);
        $saved = is_array($fixes[$url] ?? null) ? $fixes[$url] : array();
        $values = array();
        foreach (array('title', 'description', 'author', 'publisher') as $field) {
            $default = (string) ($saved[$field] ?? $report[$field] ?? '');
            if ($default === '' && isset($identity[$field])) { $default = $identity[$field]; }
            $input = tk_post('crawler_fix_' . $field, $default);
            if (!is_string($input)) { wp_die('Enter a valid ' . $field . ' for the fix.'); }
            $values[$field] = $field === 'description' ? sanitize_textarea_field($input) : sanitize_text_field($input);
        }
        $title = $values['title'];
        $description = $values['description'];
        if ((in_array('title', $fields, true) || in_array('schema', $fields, true)) && $title === '') { wp_die('Enter a page title for the fix.'); }
        if (in_array('description', $fields, true) && $description === '') { wp_die('Enter a page description for the fix.'); }
        foreach (array('author', 'publisher') as $field) {
            if ((in_array($field, $fields, true) || in_array('schema', $fields, true)) && $values[$field] === '') { wp_die('Enter a ' . $field . ' for the fix.'); }
        }
        if (!isset($fixes[$url]) && count($fixes) >= 100) { wp_die('The limit of 100 URL fixes has been reached. Remove an unused fix first.'); }
        $fixes[$url] = array_merge($saved, $values, array('fields' => $fields));
    }
    tk_update_option('geo_crawler_fixes', $fixes);
    if (function_exists('tk_page_cache_purge')) { tk_page_cache_purge(); }
    // Verify the actual response after saving/removing a fallback. Never turn
    // missing badges green merely because values were stored in the form.
    tk_update_option('geo_crawler_preview', tk_geo_run_crawler_preview($url));
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-geo-audit') . '#crawler-preview');
    exit;
}

function tk_geo_audit_fix_handler(): void {
    tk_require_admin_post('tk_geo_audit_fix');
    if (!tk_license_features_enabled()) { wp_die('An active Tool Kits license is required.'); }
    $url = tk_geo_crawler_fix_url((string) ($_GET['url'] ?? ''));
    $issue = sanitize_text_field((string) ($_GET['issue'] ?? ''));
    $report = tk_get_option('seo_geo_audit_report', array());
    $matched = false;
    foreach (($report['items'] ?? array()) as $item) {
        if (tk_geo_crawler_fix_url((string) ($item['url'] ?? '')) === $url && in_array($issue, (array) ($item['issues'] ?? array()), true)) { $matched = true; break; }
    }
    $data = function_exists('tk_seo_geo_issue_data') ? tk_seo_geo_issue_data($issue) : array();
    if ($url === '' || !$matched || empty($data['automatic']) || empty($data['field'])) { wp_die('This GEO issue cannot be fixed automatically.'); }
    $fixes = tk_get_option('geo_crawler_fixes', array());
    $fixes = is_array($fixes) ? $fixes : array();
    if (!tk_geo_apply_audit_fixes($url, array($issue), $fixes)) { wp_die('The GEO issue could not be applied.'); }
    tk_update_option('geo_crawler_fixes', $fixes);
    if (function_exists('tk_page_cache_purge')) { tk_page_cache_purge(); }
    $fresh = tk_seo_geo_audit_url($url, 20, true);
    foreach (($report['items'] ?? array()) as $index => $item) {
        if (tk_geo_crawler_fix_url((string) ($item['url'] ?? '')) === $url) { $report['items'][$index] = $fresh; }
    }
    $summary = tk_seo_geo_audit_summary($report['items'] ?? array());
    $report['average_score'] = $summary['average_score'];
    $report['issue_count'] = $summary['issue_count'];
    $report['scanned_at'] = time();
    tk_update_option('seo_geo_audit_report', $report);
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-geo-audit&tk_geo_fixed=1') . '#geo-audit'); exit;
}

function tk_geo_apply_audit_fixes(string $url, array $issues, array &$fixes): bool {
    if ($url === '' || (!isset($fixes[$url]) && count($fixes) >= 100)) { return false; }
    $fields = (array) ($fixes[$url]['fields'] ?? array());
    $applied = array();
    foreach ($issues as $issue) {
        $data = function_exists('tk_seo_geo_issue_data') ? tk_seo_geo_issue_data((string) $issue) : array();
        if (empty($data['automatic']) || empty($data['field'])) { continue; }
        $fields[] = (string) $data['field'];
        $applied[] = array('issue' => (string) $issue, 'field' => (string) $data['field'], 'fixed_at' => time());
    }
    if (!$applied) { return false; }
    $post_id = url_to_postid($url);
    $title = $post_id > 0 ? trim((string) get_the_title($post_id)) : trim((string) get_bloginfo('name'));
    $description = '';
    if ($post_id > 0) {
        $post = get_post($post_id);
        $description = is_object($post) ? wp_html_excerpt(wp_strip_all_tags((string) ($post->post_excerpt ?: $post->post_content)), 160, '') : '';
    }
    if ($description === '') { $description = trim((string) get_bloginfo('description')); }
    $identity = tk_geo_metadata_identity($url);
    $fields = array_values(array_unique($fields));
    $fixes[$url] = array('fields' => $fields, 'title' => $title, 'description' => $description, 'author' => $identity['author'], 'publisher' => $identity['publisher']);
    if ($post_id > 0) { update_post_meta($post_id, '_tk_geo_last_auto_fix', $applied); }
    return true;
}

function tk_geo_audit_bulk_fix_handler(): void {
    tk_require_admin_post('tk_geo_audit_bulk_fix');
    if (!tk_license_features_enabled()) { wp_die('An active Tool Kits license is required.'); }
    $report = tk_get_option('seo_geo_audit_report', array());
    $items = is_array($report['items'] ?? null) ? $report['items'] : array();
    if (!$items) { wp_die('Run GEO Audit before using Bulk Fix All.'); }
    $fixes = tk_get_option('geo_crawler_fixes', array());
    $fixes = is_array($fixes) ? $fixes : array();
    $fixed_urls = array();
    foreach ($items as $item) {
        $url = tk_geo_crawler_fix_url((string) ($item['url'] ?? ''));
        if ($url !== '' && tk_geo_apply_audit_fixes($url, (array) ($item['issues'] ?? array()), $fixes)) { $fixed_urls[$url] = true; }
    }
    if (!$fixed_urls) { wp_die('No safe automatic fixes are available in this report.'); }
    tk_update_option('geo_crawler_fixes', $fixes);
    if (function_exists('tk_page_cache_purge')) { tk_page_cache_purge(); }
    foreach ($items as $index => $item) {
        $url = tk_geo_crawler_fix_url((string) ($item['url'] ?? ''));
        if (isset($fixed_urls[$url])) { $items[$index] = tk_seo_geo_audit_url($url, 20, true); }
    }
    $summary = tk_seo_geo_audit_summary($items);
    $report['items'] = $items;
    $report['average_score'] = $summary['average_score'];
    $report['issue_count'] = $summary['issue_count'];
    $report['scanned_at'] = time();
    $report['bulk_fix'] = array('fixed_urls' => count($fixed_urls), 'completed_at' => time());
    tk_update_option('seo_geo_audit_report', $report);
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-geo-audit&tk_geo_bulk_fixed=' . count($fixed_urls)) . '#geo-audit'); exit;
}

function tk_geo_audit_remove_fix_handler(): void {
    tk_require_admin_post('tk_geo_audit_remove_fix');
    $url = tk_geo_crawler_fix_url((string) ($_GET['url'] ?? ''));
    $fixes = (array) tk_get_option('geo_crawler_fixes', array());
    if ($url === '' || !isset($fixes[$url])) { wp_die('No stored fix exists for this URL.'); }
    unset($fixes[$url]); tk_update_option('geo_crawler_fixes', $fixes);
    if (function_exists('tk_page_cache_purge')) { tk_page_cache_purge(); }
    $report = (array) tk_get_option('seo_geo_audit_report', array());
    foreach ((array) ($report['items'] ?? array()) as $index => $item) { if (($item['url'] ?? '') === $url) { $report['items'][$index] = tk_seo_geo_audit_url($url, 20, true); } }
    $summary = tk_seo_geo_audit_summary($report['items'] ?? array()); $report['average_score'] = $summary['average_score']; $report['issue_count'] = $summary['issue_count']; $report['scanned_at'] = time();
    tk_update_option('seo_geo_audit_report', $report);
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-geo-audit&tk_geo_fix_removed=1') . '#geo-audit'); exit;
}

function tk_geo_audit_remove_all_fixes_handler(): void {
    tk_require_admin_post('tk_geo_audit_remove_all_fixes');
    tk_update_option('geo_crawler_fixes', array());
    tk_update_option('seo_geo_audit_report', array());
    if (function_exists('tk_page_cache_purge')) { tk_page_cache_purge(); }
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-geo-audit&tk_geo_fixes_removed=1') . '#geo-audit'); exit;
}

function tk_geo_crawler_fix_html(string $html, string $url, array $fix): string {
    $attributes = '(?:"[^"]*"|\'[^\']*\'|[^\'">])*';
    preg_match_all('~<!--.*?-->|<(script|style|textarea|title)\b' . $attributes . '>.*?</\1\s*>|<head\b' . $attributes . '>|</head\s*>~is', $html, $tokens, PREG_OFFSET_CAPTURE);
    $start = null; $length = -1;
    foreach ($tokens[0] as $token) {
        if ($start === null && preg_match('/^<head\b/i', $token[0])) { $start = $token[1] + strlen($token[0]); }
        if ($start !== null && preg_match('/^<\/head\s*>/i', $token[0])) { $length = $token[1] - $start; break; }
    }
    if ($length < 0) { return $html; }
    $content = substr($html, $start, $length);
    $metadata = tk_geo_crawler_metadata($content);
    // JSON-LD can legitimately be emitted in the body by a theme or SEO plugin.
    $document_metadata = tk_geo_crawler_metadata($html);
    $metadata['schema'] = $document_metadata['schema'];
    $metadata['h1'] = $document_metadata['h1'];
    $tags = array();
    $edits = array();
    $inject_h1 = false;
    $schema_added = false;
    foreach (($fix['fields'] ?? array()) as $field) {
        if ($field === 'schema_graph' && strpos($html, '"@id":"' . $url . '#webpage"') !== false) { continue; }
        if ($field !== 'schema_graph' && (!array_key_exists($field, $metadata) || !empty($metadata[$field]))) { continue; }
        $tag = '';
        if ($field === 'title' && !empty($fix['title'])) {
            $tag = '<title>' . esc_html($fix['title']) . '</title>';
        } elseif ($field === 'description' && !empty($fix['description'])) {
            $tag = '<meta name="description" content="' . esc_attr($fix['description']) . '">';
        } elseif ($field === 'author' && !empty($fix['author'])) {
            $tag = '<meta name="author" content="' . esc_attr($fix['author']) . '">';
        } elseif ($field === 'publisher' && !empty($fix['publisher'])) {
            $tag = '<meta name="publisher" content="' . esc_attr($fix['publisher']) . '">';
        } elseif ($field === 'canonical') {
            $tag = '<link rel="canonical" href="' . esc_url($url) . '">';
        } elseif ($field === 'schema' || $field === 'schema_graph') {
            if ($schema_added) { continue; }
            $site = home_url('/');
            $identity = tk_geo_metadata_identity($url);
            foreach (array('author', 'publisher') as $key) {
                if (trim((string) ($fix[$key] ?? '')) !== '') { $identity[$key] = $fix[$key]; }
            }
            $organization = array('@type' => 'Organization', '@id' => $site . '#organization', 'name' => $identity['publisher'], 'url' => $site);
            $website = array('@type' => 'WebSite', '@id' => $site . '#website', 'url' => $site, 'name' => get_bloginfo('name'), 'publisher' => array('@id' => $site . '#organization'));
            $post_id = url_to_postid($url);
            $post_type = $post_id > 0 && function_exists('get_post_type') ? (string) get_post_type($post_id) : '';
            $page_type = in_array($post_type, array('post', 'news'), true) ? 'Article' : ($post_type === 'product' ? 'Product' : 'WebPage');
            $page = array('@type' => $page_type, '@id' => $url . '#webpage', 'url' => $url, 'name' => $metadata['title'] ?: ($fix['title'] ?? ''), 'isPartOf' => array('@id' => $site . '#website'), 'publisher' => array('@id' => $site . '#organization'));
            if ($identity['author'] !== '') { $page['author'] = array('@type' => 'Person', 'name' => $identity['author']); }
            if (!empty($fix['description'])) { $page['description'] = $fix['description']; }
            if ($post_id > 0 && function_exists('get_post_time') && function_exists('get_post_modified_time')) {
                $published = get_post_time(DATE_W3C, true, $post_id);
                $modified = get_post_modified_time(DATE_W3C, true, $post_id);
                if ($published) { $page['datePublished'] = $published; }
                if ($modified) { $page['dateModified'] = $modified; }
            }
            $schema = array('@context' => 'https://schema.org', '@graph' => array($organization, $website, $page));
            $tag = '<script type="application/ld+json"' . tk_csp_nonce_attr() . '>'
                . wp_json_encode($schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . '</script>';
            $schema_added = true;
        } elseif ($field === 'h1' && !empty($fix['title'])) {
            $inject_h1 = true;
        }
        if ($tag !== '') {
            $tags[] = $tag;
            foreach (($metadata['empty'][$field] ?? array()) as $empty) { $edits[] = $empty; }
        }
    }
    if ($tags) {
        usort($edits, function($a, $b) { return $b[0] <=> $a[0]; });
        foreach ($edits as $edit) { $content = substr_replace($content, '', $edit[0], $edit[1]); }
        $html = substr_replace($html, $content . "\n" . implode("\n", $tags) . "\n", $start, $length);
    }
    if ($inject_h1 && preg_match('/<body\b[^>]*>/i', $html, $body, PREG_OFFSET_CAPTURE)) {
        $position = $body[0][1] + strlen($body[0][0]);
        $h1 = '<h1 class="tk-geo-fallback-h1" hidden>' . esc_html($fix['title']) . '</h1>';
        $html = substr_replace($html, "\n" . $h1 . "\n", $position, 0);
    }
    return $html;
}

function tk_geo_crawler_fix_controls(array $report): void {
    $url = tk_geo_crawler_fix_url((string) ($report['url'] ?? ''));
    $findings = tk_geo_crawler_missing_findings($report);
    $missing = array_intersect(array_keys($findings), array('title', 'description', 'author', 'publisher', 'canonical', 'schema'));
    $fixes = tk_get_option('geo_crawler_fixes', array());
    $active = is_array($fixes) && isset($fixes[$url]);
    if ($active) {
        echo '<p><strong>Missing metadata fallback enabled for this URL.</strong> Clear external page/CDN caches, then use Scan Again to verify the live output. The report below is the last saved scan.</p>';
        echo '<p><button class="button" form="tk-geo-crawler-fix-form" name="crawler_fix_mode" value="remove" formnovalidate>Remove URL Fix</button></p>';
    }
    if (!$findings) { return; }
    if ($url === '' && $missing) {
        echo '<p>To fix missing metadata, preview the public URL without query parameters on this site.</p>';
        return;
    }
    $saved = $active && is_array($fixes[$url]) ? $fixes[$url] : array();
    $values = array();
    $identity = tk_geo_metadata_identity($url);
    foreach (array('title', 'description', 'author', 'publisher') as $field) {
        $values[$field] = trim((string) ($report[$field] ?? ''));
        if ($values[$field] === '') { $values[$field] = (string) ($saved[$field] ?? $identity[$field] ?? ''); }
    }
    if ($values['title'] === '') {
        $post_id = url_to_postid($url);
        $values['title'] = $post_id ? get_the_title($post_id) : get_bloginfo('name');
    }
    if ($values['description'] === '') { $values['description'] = wp_html_excerpt((string) ($report['text_sample'] ?? ''), 160, ''); }
    $labels = array('title' => 'Page title', 'description' => 'Meta description', 'author' => 'Author', 'publisher' => 'Publisher');
    ?>
    <div class="tk-card">
        <h4>Fix Missing Metadata</h4>
        <p>These findings come from the last saved scan. Use Scan Again to check the current page before applying a fix.</p>
        <ul>
            <?php foreach ($findings as $field => $finding): ?>
                <li><span class="tk-badge tk-warn"><?php echo in_array($field, $missing, true) ? 'Missing' : 'Review required'; ?></span> <strong><?php echo esc_html($finding['message']); ?></strong>
                    <?php if ($finding['agents']): ?><span class="description"> Crawlers: <?php echo esc_html(implode(', ', $finding['agents'])); ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($missing): ?>
            <p>Review the fallback values below. They are applied to this URL for all visitors and crawlers when the corresponding metadata is missing.</p>
            <?php foreach ($labels as $field => $label):
                $is_missing = in_array($field, $missing, true);
                $schema_context = in_array('schema', $missing, true);
                if (!$is_missing && !$schema_context) { continue; }
                $required = $is_missing || ($schema_context && $field !== 'description');
            ?>
                <p><label for="tk-crawler-fix-<?php echo esc_attr($field); ?>"><strong><?php echo esc_html($label); ?></strong> <span class="description"><?php echo $is_missing ? '(missing)' : '(for JSON-LD)'; ?></span></label><br>
                    <?php if ($field === 'description'): ?>
                        <textarea id="tk-crawler-fix-description" class="large-text" rows="3" form="tk-geo-crawler-fix-form" name="crawler_fix_description"<?php echo $required ? ' required' : ''; ?>><?php echo esc_textarea($values[$field]); ?></textarea>
                    <?php else: ?>
                        <input id="tk-crawler-fix-<?php echo esc_attr($field); ?>" type="text" class="large-text" form="tk-geo-crawler-fix-form" name="crawler_fix_<?php echo esc_attr($field); ?>" value="<?php echo esc_attr($values[$field]); ?>"<?php echo $required ? ' required' : ''; ?>>
                    <?php endif; ?>
                </p>
            <?php endforeach; ?>
            <?php if (in_array('canonical', $missing, true)): ?><p><strong>Canonical URL (missing)</strong><br><code><?php echo esc_html($url); ?></code><br><span class="description">The public URL above will be used as the canonical URL.</span></p><?php endif; ?>
            <?php if (in_array('schema', $missing, true)): ?><p><strong>JSON-LD (missing)</strong><br><span class="description">Generate an Organization, WebSite and page graph using the reviewed values. The page type follows its WordPress post type.</span></p><?php endif; ?>
            <button class="button button-primary" form="tk-geo-crawler-fix-form" name="crawler_fix_mode" value="apply">Fix Missing Metadata</button>
            <p class="description">After saving, clear external page/CDN caches and use Scan Again to verify the live output.</p>
        <?php endif; ?>
    </div>
    <?php
}

function tk_geo_visibility_fixable_fields(array $report): array {
    if ((int) ($report['status'] ?? 0) < 200 || (int) ($report['status'] ?? 0) >= 400) { return array(); }
    $snapshot = is_array($report['snapshot'] ?? null) ? $report['snapshot'] : array();
    $schema = is_array($report['schema'] ?? null) ? $report['schema'] : array();
    $fields = array();
    foreach (array('title', 'description', 'author', 'publisher', 'canonical') as $field) {
        if (trim((string) ($snapshot[$field] ?? '')) === '') { $fields[] = $field; }
    }
    if (empty($snapshot['h1s'])) { $fields[] = 'h1'; }
    if ((int) ($schema['documents'] ?? 0) === 0) { $fields[] = 'schema'; }
    return $fields;
}

function tk_geo_visibility_fix_handler(): void {
    tk_require_admin_post('tk_geo_visibility_fix');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('Use the AI Visibility fix form to apply changes.'); }
    if (!tk_license_features_enabled()) { wp_die('An active Tool Kits license is required.'); }
    $report = tk_get_option('geo_visibility_report', array());
    $url = tk_geo_crawler_fix_url((string) tk_post('visibility_fix_url', ''));
    if ($url === '' || !is_array($report) || $url !== tk_geo_crawler_fix_url((string) ($report['url'] ?? ''))) {
        wp_die('Run AI Visibility Score for this public URL before applying a fix.');
    }
    $fixes = tk_get_option('geo_crawler_fixes', array());
    $fixes = is_array($fixes) ? $fixes : array();
    if (tk_post('visibility_fix_mode', '') === 'remove') {
        unset($fixes[$url]);
    } else {
        $fields = tk_geo_visibility_fixable_fields($report);
        if (!$fields) { wp_die('No missing metadata can be fixed automatically. Review the recommended actions and scan again.'); }
        $fields = array_values(array_unique(array_merge($fixes[$url]['fields'] ?? array(), $fields)));
        $title = sanitize_text_field((string) tk_post('visibility_fix_title', ''));
        $description = sanitize_textarea_field((string) tk_post('visibility_fix_description', ''));
        if ((in_array('title', $fields, true) || in_array('schema', $fields, true) || in_array('h1', $fields, true)) && $title === '') { wp_die('Enter a page title for the fix.'); }
        if (in_array('description', $fields, true) && $description === '') { wp_die('Enter a page description for the fix.'); }
        if (!isset($fixes[$url]) && count($fixes) >= 100) { wp_die('The limit of 100 URL fixes has been reached. Remove an unused fix first.'); }
        $identity = tk_geo_metadata_identity($url);
        $fixes[$url] = array('fields' => $fields, 'title' => $title, 'description' => $description, 'author' => $identity['author'], 'publisher' => $identity['publisher']);
    }
    tk_update_option('geo_crawler_fixes', $fixes);
    tk_update_option('geo_visibility_report', array());
    if (function_exists('tk_page_cache_purge')) { tk_page_cache_purge(); }
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-geo-audit') . '#ai-visibility');
    exit;
}

function tk_geo_visibility_recommendations(array $report): array {
    $recommendations = array();
    $status = (int) ($report['status'] ?? 0);
    if ($status < 200 || $status >= 400) {
        $recommendations[] = $status === 403 || $status === 429
            ? 'Review firewall/CDN crawler rules and rate limits. The page must return HTTP 2xx before metadata can be repaired.'
            : 'Restore the URL to an HTTP 2xx response and resolve TLS or redirect errors before applying metadata fixes.';
    }
    foreach (($report['checks'] ?? array()) as $check) {
        if (($check['status'] ?? 'ok') === 'ok') { continue; }
        $name = (string) ($check['name'] ?? '');
        if ($name === 'H1' && !in_array('h1', tk_geo_visibility_fixable_fields($report), true)) { $recommendations[] = 'Add one descriptive H1 in the page template or editor.'; }
        if ($name === 'Meta robots') { $recommendations[] = 'Remove noindex, none, or nofollow from the page SEO/robots setting when the URL should be discoverable.'; }
        if ($name === 'X-Robots-Tag') { $recommendations[] = 'Remove the blocking X-Robots-Tag in the server, CDN, or security plugin configuration.'; }
        if ($name === 'JSON-LD' && (int) (($report['schema']['invalid'] ?? 0)) > 0) { $recommendations[] = 'Correct the existing invalid JSON-LD at its theme/plugin source; another schema block will not repair invalid markup.'; }
    }
    foreach (($report['agents'] ?? array()) as $row) {
        if (empty($row['allowed'])) {
            $recommendations[] = 'Update robots.txt to allow ' . (string) ($row['agent'] ?? 'the crawler') . ' for this URL.';
        }
    }
    return array_values(array_unique($recommendations));
}

function tk_geo_visibility_fix_controls(array $report): void {
    $url = tk_geo_crawler_fix_url((string) ($report['url'] ?? ''));
    $fields = tk_geo_visibility_fixable_fields($report);
    $fixes = tk_get_option('geo_crawler_fixes', array());
    $active = $url !== '' && is_array($fixes) && isset($fixes[$url]);
    $snapshot = is_array($report['snapshot'] ?? null) ? $report['snapshot'] : array();
    $title = trim((string) ($snapshot['title'] ?? ''));
    if ($title === '' && $url !== '') {
        $post_id = url_to_postid($url);
        $title = $post_id ? get_the_title($post_id) : get_bloginfo('name');
    }
    $description = trim((string) ($snapshot['description'] ?? ''));
    if ($description === '') { $description = wp_html_excerpt((string) ($snapshot['text_sample'] ?? ''), 160, ''); }
    $identity = $url !== '' ? tk_geo_metadata_identity($url) : array('author' => '', 'publisher' => '');
    if ($active) {
        echo '<div class="notice notice-success inline"><p><strong>AI Visibility metadata fallback is active for this URL.</strong> Clear external CDN caches, then run Scan Again.</p></div>';
        echo '<p><button class="button" form="tk-geo-visibility-fix-form" name="visibility_fix_mode" value="remove">Remove URL Fix</button></p>';
    }
    if ($fields && $url !== '') {
        ?>
        <div class="tk-card">
            <h4>Fix AI Visibility Issues</h4>
            <p>Automatically fills only missing <?php echo esc_html(implode(', ', $fields)); ?>. A missing H1 is added to the HTML source with the hidden attribute, so it is not visually displayed. Existing output is preserved.</p>
            <p><label>Page title<br><input type="text" class="large-text" form="tk-geo-visibility-fix-form" name="visibility_fix_title" value="<?php echo esc_attr($title); ?>"></label></p>
            <p><label>Page description<br><textarea class="large-text" rows="3" form="tk-geo-visibility-fix-form" name="visibility_fix_description"><?php echo esc_textarea($description); ?></textarea></label></p>
            <p>Author: <strong><?php echo esc_html($identity['author']); ?></strong><br>Publisher: <strong><?php echo esc_html($identity['publisher']); ?></strong></p>
            <p>Canonical URL: <code><?php echo esc_html($url); ?></code></p>
            <button class="button button-primary" form="tk-geo-visibility-fix-form" name="visibility_fix_mode" value="apply">Fix Missing AI Visibility Metadata</button>
        </div>
        <?php
    }
    $recommendations = tk_geo_visibility_recommendations($report);
    if ($recommendations) {
        echo '<div class="tk-card"><h4>Recommended Manual Fixes</h4><ul class="tk-list">';
        foreach ($recommendations as $recommendation) { echo '<li>' . esc_html($recommendation) . '</li>'; }
        echo '</ul></div>';
    }
}

function tk_geo_crawler_fix_start(): void {
    if (is_admin() || wp_doing_ajax() || is_feed() || is_preview() || is_search() || is_404()
        || !tk_license_features_enabled() || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', array('GET', 'HEAD'), true)) { return; }
    if (is_singular() && (post_password_required() || get_post_status() !== 'publish')) { return; }
    $home = wp_parse_url(home_url('/'));
    $origin = ($home['scheme'] ?? 'https') . '://' . ($home['host'] ?? '') . (isset($home['port']) ? ':' . $home['port'] : '');
    $url = tk_geo_crawler_fix_url($origin . (string) wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
    $fixes = tk_get_option('geo_crawler_fixes', array());
    if ($url === '' || !is_array($fixes) || empty($fixes[$url])) { return; }
    $fix = $fixes[$url];
    ob_start(function($html) use ($url, $fix) {
        if (http_response_code() >= 300) { return $html; }
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) { return $html; }
        }
        return tk_geo_crawler_fix_html($html, $url, $fix);
    });
}
