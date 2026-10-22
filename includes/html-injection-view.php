<?php
if (!defined('ABSPATH')) { exit; }

function tk_html_scan_render_row(array $raw): void {
    $f = tk_html_scan_normalize_finding($raw);
    echo '<tr data-finding-row data-severity="' . esc_attr($f['severity']) . '" data-type="' . esc_attr($f['rule']) . '" data-source="' . esc_attr($f['source']) . '" data-classification="' . esc_attr($f['classification']) . '" data-action="' . esc_attr($f['action']) . '" data-confidence="' . esc_attr($f['confidence']) . '" data-pages="' . esc_attr(json_encode($f['affected_urls'])) . '">';
    echo '<td><strong class="tk-scan-severity tk-scan-' . esc_attr($f['severity']) . '">' . esc_html(strtoupper($f['severity'])) . '</strong><p>' . esc_html($f['action']) . '</p><small>' . esc_html((string) round($f['confidence'] * 100)) . '% confidence</small></td>';
    echo '<td>' . esc_html($f['source'] . ' · line ' . $f['line']);
    if ($f['post_id'] && ($link = get_edit_post_link((int) $f['post_id'], 'raw'))) { echo '<br><a href="' . esc_url($link) . '">Edit source</a>'; }
    echo '<p>' . esc_html($f['occurrences'] . ' occurrence(s)') . '</p>';
    if ($f['affected_urls']) { echo '<details><summary>Affected pages (' . esc_html(count($f['affected_urls'])) . ')</summary><ul>'; foreach ($f['affected_urls'] as $url) { echo '<li>' . esc_html($url) . '</li>'; } echo '</ul></details>'; }
    echo '</td><td><strong>' . esc_html($f['rule']) . '</strong><p>' . esc_html($f['reason']) . '</p><code>' . esc_html($f['rule_id']) . '</code><p>' . esc_html($f['classification']) . '</p>';
    if (!empty($f['provider'])) { echo '<p>' . esc_html($f['provider']) . '</p>'; }
    if ($f['host'] !== '') { echo '<code>' . esc_html($f['host']) . '</code>'; }
    if ($f['timestamp']) { echo '<p><small>' . esc_html(gmdate('Y-m-d H:i:s', $f['timestamp'])) . ' UTC</small></p>'; }
    if ($f['action'] === 'SKIP') { echo '<p><button type="button" class="button" data-scan-restore="' . esc_attr($f['fingerprint']) . '">Restore to Needs Review</button></p>'; }
    echo '</td><td><code class="tk-scan-evidence">' . esc_html($f['snippet']) . '</code></td></tr>';
}

function tk_html_scan_render_report(array $report): void {
    $stats = tk_html_scan_statistics($report); $all = array_merge($report['findings'] ?? array(), $report['suppressed'] ?? array());
    echo '<div class="tk-card" id="tk-html-report"><h2>Security Scan Summary</h2><div data-scan-summary>';
    if ($report) { echo '<p>' . esc_html('Status: ' . ($report['status'] ?? '') . ' · Posts: ' . ($report['counts']['posts'] ?? 0) . ' · Meta: ' . ($report['counts']['meta'] ?? 0) . ' · Options: ' . ($report['counts']['options'] ?? 0) . ' · URLs attempted: ' . ($report['counts']['urls'] ?? 0)) . '</p>'; }
    echo '</div><div class="tk-scan-stats" data-scan-statistics>';
    foreach (array('pages_scanned' => 'Pages scanned', 'files_analyzed' => 'Files analyzed', 'critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low', 'needs_review' => 'Needs review', 'suppressed' => 'Suppressed occurrences', 'duplicates_grouped' => 'Duplicate occurrences grouped') as $key => $label) { echo '<div><strong>' . esc_html($stats[$key]) . '</strong><span>' . esc_html($label) . '</span></div>'; }
    echo '</div><ul data-scan-issues>';
    if (!empty($report['limited'])) { echo '<li>Coverage is incomplete or limited to the configured sample.</li>'; }
    if ($report && ($report['schema_version'] ?? 1) < 2) { echo '<li>This report predates contextual analysis. Run a new scan to classify and group findings; old evidence remains available.</li>'; }
    foreach ($report['issues'] ?? array() as $issue) { echo '<li>' . esc_html($issue) . '</li>'; }
    if (!empty($stats['suppression_audit_overflow'])) { echo '<li>' . esc_html($stats['suppression_audit_overflow'] . ' suppressed occurrences are retained as aggregate audit metadata beyond the detailed evidence budget.') . '</li>'; }
    echo '</ul><div class="tk-scan-filters" aria-label="Filter scan findings">';
    $filters = array('severity' => array('critical', 'high', 'medium', 'low', 'info'), 'type' => array(), 'source' => array(), 'classification' => array(), 'action' => array('REPORT', 'REVIEW', 'SKIP'), 'confidence' => array('0.7', '0.9', '0.95'), 'page' => array());
    foreach ($all as $raw) { $item = tk_html_scan_normalize_finding($raw); foreach (array('type' => 'rule', 'source' => 'source', 'classification' => 'classification') as $key => $field) { $filters[$key][] = $item[$field]; } foreach ($item['affected_urls'] as $url) { $filters['page'][] = $url; } }
    foreach ($filters as $key => $values) {
        echo '<label>' . esc_html($key === 'confidence' ? 'Minimum confidence' : ($key === 'page' ? 'Affected page' : ucfirst($key))) . '<select data-scan-filter="' . esc_attr($key) . '"><option value="">All</option>';
        foreach (array_unique($values) as $value) { echo '<option value="' . esc_attr($value) . '">' . esc_html($key === 'confidence' ? round((float) $value * 100) . '%' : $value) . '</option>'; }
        echo '</select></label>';
    }
    echo '</div><p data-scan-empty' . ($all ? ' hidden' : '') . '>' . esc_html($report ? 'No matching patterns in the sources checked so far. Review coverage and status above.' : 'No scan report yet.') . '</p>';
    foreach (array('REPORT' => array('Security Findings', 'results'), 'REVIEW' => array('Needs Review', 'review'), 'SKIP' => array('Suppressed Findings', 'suppressed')) as $action => $section) {
        if ($action === 'SKIP') { echo '<details class="tk-scan-suppressed"><summary>Suppressed Findings — auditable safe patterns</summary><p>Excluded from Security Findings. Restore a group to Needs Review if you disagree with its classification. This changes report metadata only.</p>'; }
        else { echo '<h3>' . esc_html($section[0]) . '</h3>'; }
        echo '<div class="tk-scan-table"><table class="widefat striped"><thead><tr><th>Severity / Action</th><th>Source</th><th>Finding</th><th>Evidence</th></tr></thead><tbody data-scan-' . esc_attr($section[1]) . '>';
        foreach ($all as $raw) { if (tk_html_scan_normalize_finding($raw)['action'] === $action) { tk_html_scan_render_row($raw); } }
        echo '</tbody></table></div>';
        if ($action === 'SKIP') { echo '</details>'; }
    }
    echo '</div>';
}
