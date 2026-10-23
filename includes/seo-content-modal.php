<?php
if (!defined('ABSPATH')) { exit; }

function tk_seo_content_checks_enqueue($hook): void {
    if (!in_array($hook, array('index.php', 'post.php', 'post-new.php'), true) && ($_GET['page'] ?? '') !== 'tool-kits-seo') return;
    if (!tk_toolkits_can_manage()) return;
    wp_enqueue_style('tk-editor-modals', TK_URL . 'assets/editor-modals.css', array(), tk_asset_version('assets/editor-modals.css'));
}

function tk_seo_content_editor_version($post): string {
    return hash('sha256', $post->post_content . '\0' . $post->post_excerpt . '\0' . (string) get_post_meta($post->ID, '_tk_seo_focus_keyword', true) . '\0' . (string) get_post_meta($post->ID, '_tk_seo_target_location', true));
}

function tk_seo_content_checks_render(array $item): void {
    $checks = is_array($item['strategy_checks'] ?? null) ? $item['strategy_checks'] : array();
    if (!$checks) { return; }
    $has_scores = false;
    foreach ($checks as $check) { if (is_array($check) && array_key_exists('score', $check)) { $has_scores = true; break; } }
    ?>
    <details class="tk-content-audit-checks">
        <summary>SEO Checks &amp; Optimization <span class="tk-content-check-badge">Score: <?php echo esc_html((string) ($item['score'] ?? '—')); ?>/100</span></summary>
        <p class="description"><?php echo $has_scores ? 'Each score covers the named saved-content test. Pending items need live review.' : 'Run Content Audit to refresh individual check scores.'; ?></p>
        <ul class="tk-content-check-grid">
        <?php foreach ($checks as $check): if (!is_array($check)) { continue; }
            $state = in_array($check['status'] ?? '', array('pass', 'warning', 'review', 'not-applicable'), true) ? $check['status'] : 'review';
            $score = $check['score'] ?? null;
            $score = $state !== 'not-applicable' && (is_int($score) || is_float($score)) && $score >= 0 && $score <= 100 ? (int) round($score) : null;
            $score_text = $state === 'not-applicable' ? 'N/A' : ($score !== null ? $score . '/100' : ($state === 'warning' ? 'Needs attention' : ($state === 'pass' ? 'Passed' : 'Pending')));
            $color = $score === null ? ($state === 'warning' ? 'poor' : 'pending') : ($score >= 85 ? 'good' : ($score >= 50 ? 'fair' : 'poor'));
        ?>
            <li class="tk-content-check tk-content-check-<?php echo esc_attr($state); ?>">
                <div class="tk-content-check-heading"><strong><?php echo esc_html((string) ($check['label'] ?? $check['category'] ?? 'SEO check')); ?></strong> <span class="tk-content-check-badge tk-content-score-<?php echo esc_attr($color); ?>"><?php echo esc_html($score_text); ?></span></div>
                <p><?php echo esc_html((string) ($check['summary'] ?? $check['finding'] ?? '')); ?></p>
                <?php if ($state !== 'not-applicable'): ?>
                    <details class="tk-content-check-details"><summary>Details</summary>
                        <p><?php echo esc_html((string) ($check['finding'] ?? '')); ?></p>
                        <p class="description"><?php echo esc_html((string) ($check['action'] ?? '')); ?></p>
                        <?php if (!empty($check['target'])): ?><a href="<?php echo esc_url($check['target']); ?>">Review</a><?php endif; ?>
                    </details>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    </details>
    <?php
}

function tk_seo_content_editor_ajax(): void {
    if (!tk_toolkits_can_manage()) wp_send_json_error(array('message' => 'Forbidden'), 403);
    check_ajax_referer('tk_seo_content_editor', 'nonce');
    $mode = isset($_POST['mode']) && is_string($_POST['mode']) ? $_POST['mode'] : '';
    if (!in_array($mode, array('load', 'validate', 'save'), true)) wp_send_json_error(array('message' => 'Invalid operation.'), 400);
    $id = absint($_POST['post_id'] ?? 0);
    $post = get_post($id);
    if (!$post || !current_user_can('edit_post', $id) || $post->post_status !== 'publish' || $post->post_password !== '' ||
        !is_post_type_viewable($post->post_type) || !in_array($post->post_type, tk_seo_selected_post_types(), true)) {
        wp_send_json_error(array('message' => 'Choose an editable public published page.'), 403);
    }
    $version = tk_seo_content_editor_version($post);
    $report = array();
    if ($mode === 'save') {
        if (!hash_equals($version, (string) ($_POST['version'] ?? ''))) wp_send_json_error(array('message' => 'This page changed. Reopen the dialog before saving.'), 409);
        $fields = array();
        foreach (array('content', 'links', 'excerpt', 'keyword', 'location', 'client', 'outcome') as $key) {
            if (!isset($_POST[$key]) || !is_string($_POST[$key])) wp_send_json_error(array('message' => 'Invalid input.'), 400);
            $fields[$key] = sanitize_textarea_field(wp_unslash($_POST[$key]));
        }
        if (!in_array($fields['location'], array('', 'jakarta', 'singapore'), true)) {
            wp_send_json_error(array('message' => 'Choose a valid target location.'), 400);
        }
        $extra = '';
        foreach (array('content' => 'Additional Information', 'client' => 'Client', 'outcome' => 'Project Outcome') as $key => $heading) {
            if ($fields[$key] !== '') $extra .= '<h2>' . $heading . '</h2>' . wpautop(esc_html($fields[$key]));
        }
        $links = '';
        foreach (preg_split('/\R/', $fields['links']) as $line) {
            if (trim($line) === '') continue;
            $parts = array_map('trim', explode('|', $line, 2));
            $url = $parts[0];
            if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) $url = home_url($url);
            $parsed = wp_parse_url($url);
            if (count($parts) !== 2 || $parts[1] === '' || !is_array($parsed) || !in_array(strtolower($parsed['scheme'] ?? ''), array('http', 'https'), true) ||
                !empty($parsed['user']) || !empty($parsed['pass']) || strcasecmp($parsed['host'] ?? '', (string) wp_parse_url(home_url('/'), PHP_URL_HOST)) !== 0) {
                wp_send_json_error(array('message' => 'Each internal link needs a same-site HTTP(S) URL and a label, separated by |.'), 400);
            }
            $links .= '<li><a href="' . esc_url($url) . '">' . esc_html($parts[1]) . '</a></li>';
        }
        if ($links !== '') $extra .= '<h2>Related Resources</h2><ul>' . $links . '</ul>';
        $updates = array('ID' => $id);
        if ($extra !== '') $updates['post_content'] = $post->post_content . "\n" . $extra;
        if ($fields['excerpt'] !== $post->post_excerpt) $updates['post_excerpt'] = $fields['excerpt'];
        if (count($updates) > 1) {
            $result = wp_update_post(wp_slash($updates), true);
            if (is_wp_error($result)) wp_send_json_error(array('message' => $result->get_error_message()), 500);
        }
        update_post_meta($id, '_tk_seo_focus_keyword', sanitize_text_field($fields['keyword']));
        update_post_meta($id, '_tk_seo_target_location', $fields['location']);
        $report = tk_seo_run_content_audit();
        tk_update_option('seo_content_audit_report', $report);
    }
    $validation = null;
    foreach ((array) ($report['items'] ?? array()) as $item) {
        if ((int) ($item['post_id'] ?? 0) === $id) { $validation = $item; break; }
    }
    if (!$validation) {
        $report = tk_seo_run_content_audit($id, true);
        $validation = $report['items'][0] ?? null;
    }
    if (!$validation) wp_send_json_error(array('message' => 'Could not validate this page. Run Content SEO Audit and retry.'), 500);
    $post = get_post($id);
    wp_send_json_success(array('version' => tk_seo_content_editor_version($post), 'excerpt' => $post->post_excerpt,
        'keyword' => (string) get_post_meta($id, '_tk_seo_focus_keyword', true),
        'location' => (string) get_post_meta($id, '_tk_seo_target_location', true),
        'words' => (int) $validation['words'], 'title' => get_the_title($id),
        'validation' => $validation, 'checked_at' => (int) ($report['scanned_at'] ?? time())));
}
