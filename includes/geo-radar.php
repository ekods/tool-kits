<?php
if (!defined('ABSPATH')) { exit; }

function tk_geo_radar_has_type(array $schema, array $wanted): bool {
    $types = array_map('strtolower', array_keys(is_array($schema['types'] ?? null) ? $schema['types'] : array()));
    foreach ($wanted as $type) {
        if (in_array(strtolower($type), $types, true)) { return true; }
    }
    return false;
}

function tk_geo_build_search_radar(array $report): array {
    $snapshot = is_array($report['snapshot'] ?? null) ? $report['snapshot'] : array();
    $schema = is_array($report['schema'] ?? null) ? $report['schema'] : array();
    $checks = is_array($report['checks'] ?? null) ? $report['checks'] : array();
    $check_status = array();
    foreach ($checks as $check) { $check_status[(string) ($check['name'] ?? '')] = (string) ($check['status'] ?? ''); }
    $fetchable = ($check_status['Fetchable URL'] ?? '') === 'ok';
    $valid_schema = (int) ($schema['documents'] ?? 0) > 0 && (int) ($schema['invalid'] ?? 0) === 0;
    $has_entity = tk_geo_radar_has_type($schema, array('Organization', 'Person', 'LocalBusiness'));
    $has_page = tk_geo_radar_has_type($schema, array('Article', 'NewsArticle', 'BlogPosting', 'WebPage'));
    $has_author = trim((string) ($snapshot['author'] ?? '')) !== '';
    $has_publisher = trim((string) ($snapshot['publisher'] ?? '')) !== '';
    $has_h1 = !empty($snapshot['h1s']);
    $has_title = trim((string) ($snapshot['title'] ?? '')) !== '';
    $has_description = trim((string) ($snapshot['description'] ?? '')) !== '';
    $has_canonical = trim((string) ($snapshot['canonical'] ?? '')) !== '';
    $enough_content = strlen(trim((string) ($snapshot['text_sample'] ?? ''))) >= 250;
    $robots_ok = ($check_status['Meta robots'] ?? '') === 'ok' && ($check_status['X-Robots-Tag'] ?? '') === 'ok';

    $scores = array(
        'entity' => ($valid_schema ? 30 : 0) + ($has_entity ? 35 : 0) + ($has_page ? 20 : 0) + ($has_publisher ? 15 : 0),
        'content' => ($has_title ? 20 : 0) + ($has_description ? 20 : 0) + ($has_h1 ? 25 : 0) + ($enough_content ? 20 : 0) + ($has_canonical ? 15 : 0),
        'eeat' => ($has_author ? 30 : 0) + ($has_publisher ? 30 : 0) + ($has_entity ? 25 : 0) + ($valid_schema ? 15 : 0),
        'tech' => ($fetchable ? 30 : 0) + ($robots_ok ? 25 : 0) + ($has_canonical ? 15 : 0) + ($valid_schema ? 20 : 0) + (!empty($report['agents']) ? 10 : 0),
    );
    $recommendations = array();
    $add = function(string $title, string $category, string $priority, bool $quick, string $detail) use (&$recommendations): void {
        $recommendations[] = compact('title', 'category', 'priority', 'quick', 'detail');
    };
    if (!$fetchable) { $add('Restore public URL access', 'tech', 'high', false, 'The standard homepage request failed. Review origin, CDN, TLS, and timeout logs.'); }
    if (!$has_entity) { $add('Define the primary site entity', 'entity', 'high', true, 'Add Organization, Person, or LocalBusiness JSON-LD with a stable @id.'); }
    if (!$valid_schema) { $add('Add valid JSON-LD', 'tech', 'high', true, 'Publish parseable structured data and repair invalid JSON-LD documents.'); }
    if (!$has_h1) { $add('Add one descriptive H1', 'content', 'high', true, 'Use one meaningful page heading that describes the main topic.'); }
    if (!$has_description) { $add('Write a useful meta description', 'content', 'medium', true, 'Summarize the page topic and value in a concise description.'); }
    if (!$enough_content) { $add('Strengthen the page answer content', 'content', 'medium', false, 'Add clear factual copy, sections, and direct answers for the page topic.'); }
    if (!$has_author) { $add('Assign a named author', 'eeat', 'high', true, 'Expose the responsible author in metadata and Article schema where applicable.'); }
    if (!$has_publisher) { $add('Identify the publisher', 'eeat', 'high', true, 'Expose the publisher or organization identity in metadata and schema.'); }
    if (!$robots_ok) { $add('Remove indexing restrictions', 'tech', 'high', true, 'Review meta robots and X-Robots-Tag directives for this URL.'); }
    if (empty($recommendations)) { $add('Maintain current AI search readiness', 'tech', 'low', false, 'No major technical gaps were detected. Review content accuracy and freshness regularly.'); }
    $priority_order = array('high' => 0, 'medium' => 1, 'low' => 2);
    usort($recommendations, fn($a, $b) => ($priority_order[$a['priority']] ?? 9) <=> ($priority_order[$b['priority']] ?? 9));
    return array(
        'scanned_at' => time(), 'url' => (string) ($report['url'] ?? ''),
        'score' => (int) round(array_sum($scores) / count($scores)), 'scores' => $scores,
        'recommendations' => $recommendations, 'visibility' => $report,
    );
}

function tk_geo_build_ai_radar(array $search, array $access): array {
    $rows = is_array($access['crawler_fetch_results'] ?? null) ? $access['crawler_fetch_results'] : array();
    $by_agent = array();
    foreach ($rows as $row) { $by_agent[(string) ($row['agent'] ?? '')] = $row; }
    $platforms = array(
        'Google AI' => array('Googlebot'), 'ChatGPT Search' => array('OAI-SearchBot', 'ChatGPT-User'),
        'Claude' => array('Claude-User'), 'Perplexity' => array('PerplexityBot', 'Perplexity-User'),
        'Common Crawl' => array('CCBot'),
    );
    $engines = array();
    foreach ($platforms as $name => $agents) {
        $eligible = 0; $found = 0; $details = array();
        foreach ($agents as $agent) {
            if (!isset($by_agent[$agent])) { continue; }
            $found++; $ok = !empty($by_agent[$agent]['ok']); $eligible += $ok ? 1 : 0;
            $details[] = $agent . ': ' . ($ok ? 'eligible' : (string) ($by_agent[$agent]['eligibility'] ?? 'unavailable'));
        }
        $status = $found === 0 ? 'unknown' : ($eligible === $found ? 'eligible' : ($eligible > 0 ? 'partial' : 'blocked'));
        $engines[] = array('name' => $name, 'status' => $status, 'agents' => implode('; ', $details));
    }
    $eligible_count = count(array_filter($engines, fn($row) => $row['status'] === 'eligible'));
    return array(
        'scanned_at' => time(), 'url' => (string) ($search['url'] ?? home_url('/')),
        'score' => count($engines) ? (int) round(($eligible_count / count($engines)) * 60 + ((int) ($search['score'] ?? 0) * .4)) : 0,
        'engines' => $engines, 'search_score' => (int) ($search['score'] ?? 0), 'access_score' => (int) ($access['score'] ?? 0),
    );
}

function tk_geo_ai_search_radar_scan(): void {
    tk_require_admin_post('tk_geo_ai_search_radar_scan');
    $url = tk_geo_normalize_site_url((string) tk_post('geo_ai_search_radar_url', home_url('/')));
    tk_update_option('geo_visibility_report', array());
    $visibility = tk_geo_run_visibility_scan($url);
    tk_update_option('geo_visibility_report', $visibility);
    tk_update_option('geo_ai_search_radar_report', tk_geo_build_search_radar($visibility));
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-geo-audit') . '#ai-search-radar'); exit;
}

function tk_geo_ai_radar_scan(): void {
    tk_require_admin_post('tk_geo_ai_radar_scan');
    tk_update_option('geo_visibility_report', array());
    $visibility = tk_geo_run_visibility_scan(home_url('/'));
    $search = tk_geo_build_search_radar($visibility);
    tk_update_option('geo_ai_access_report', array());
    $access = tk_geo_run_ai_access_review();
    tk_update_option('geo_ai_search_radar_report', $search);
    tk_update_option('geo_ai_access_report', $access);
    tk_update_option('geo_ai_radar_report', tk_geo_build_ai_radar($search, $access));
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-geo-audit') . '#ai-radar'); exit;
}

function tk_geo_render_search_radar(array $report): void {
    ?>
    <h3>AI Search Radar</h3><p>Audit one public URL across entity clarity, content readiness, E-E-A-T signals, and technical foundations.</p>
    <div class="tk-grid tk-grid-2"><p><label>URL</label><input type="url" form="tk-geo-ai-search-radar-form" name="geo_ai_search_radar_url" value="<?php echo esc_attr((string) ($report['url'] ?? home_url('/'))); ?>"></p><p style="display:flex;align-items:flex-end"><button class="button button-primary" form="tk-geo-ai-search-radar-form">Run AI Search Radar</button></p></div>
    <?php if (empty($report)) { echo '<p class="description">No AI Search Radar report yet.</p>'; return; }
    $labels = array('entity' => 'Entity Analysis', 'content' => 'Content Readiness', 'eeat' => 'E-E-A-T Signals', 'tech' => 'Tech Foundation'); ?>
    <p><span class="tk-badge <?php echo ((int) $report['score'] >= 80) ? 'tk-on' : 'tk-warn'; ?>">Overall: <?php echo esc_html((string) ((int) $report['score'])); ?>/100</span> <span class="description">Last scan: <?php echo esc_html(wp_date('Y-m-d H:i:s', (int) ($report['scanned_at'] ?? time()))); ?></span></p>
    <div class="tk-grid tk-grid-2" style="gap:12px;margin:14px 0"><?php foreach ($labels as $key => $label) : $score = (int) ($report['scores'][$key] ?? 0); ?><div class="tk-card"><strong><?php echo esc_html($label); ?></strong><br><span style="font-size:24px;font-weight:700"><?php echo esc_html((string) $score); ?></span>/100</div><?php endforeach; ?></div>
    <h4>Strategic Recommendations</h4><div class="tk-table-scroll"><table class="widefat striped tk-table"><thead><tr><th>Recommendation</th><th>Category</th><th>Priority</th><th>Detail</th></tr></thead><tbody><?php foreach (($report['recommendations'] ?? array()) as $item) : ?><tr><td><strong><?php echo esc_html((string) $item['title']); ?></strong><?php if (!empty($item['quick'])) : ?> <span class="tk-badge tk-on">Quick win</span><?php endif; ?></td><td><?php echo esc_html(strtoupper((string) $item['category'])); ?></td><td><span class="tk-badge tk-priority-<?php echo esc_attr((string) $item['priority']); ?>"><?php echo esc_html(strtoupper((string) $item['priority'])); ?></span></td><td><?php echo esc_html((string) $item['detail']); ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php
}

function tk_geo_render_ai_radar(array $report): void {
    ?>
    <h3>AI Radar</h3><p>Site-wide readiness snapshot for major AI search and answer platforms. Eligibility uses normal homepage fetchability and robots.txt rules.</p>
    <p><button class="button button-primary" form="tk-geo-ai-radar-form">Run AI Radar</button></p>
    <?php if (empty($report)) { echo '<p class="description">No AI Radar report yet.</p>'; return; } ?>
    <p><span class="tk-badge <?php echo ((int) $report['score'] >= 80) ? 'tk-on' : 'tk-warn'; ?>">Radar Score: <?php echo esc_html((string) ((int) $report['score'])); ?>/100</span> <span class="description">Search readiness <?php echo esc_html((string) ((int) $report['search_score'])); ?>/100; crawler access <?php echo esc_html((string) ((int) $report['access_score'])); ?>/100</span></p>
    <div class="tk-table-scroll"><table class="widefat striped tk-table"><thead><tr><th>AI Platform</th><th>Status</th><th>Evidence</th></tr></thead><tbody><?php foreach (($report['engines'] ?? array()) as $engine) : $status = (string) ($engine['status'] ?? 'unknown'); ?><tr><td><strong><?php echo esc_html((string) $engine['name']); ?></strong></td><td><span class="tk-badge <?php echo $status === 'eligible' ? 'tk-on' : ($status === 'partial' || $status === 'unknown' ? 'tk-warn' : ''); ?>"><?php echo esc_html(ucfirst($status)); ?></span></td><td><?php echo esc_html((string) $engine['agents']); ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php
}
