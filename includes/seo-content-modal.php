<?php
if (!defined('ABSPATH')) { exit; }

function tk_seo_content_editor_enqueue($hook): void {
    if (!in_array($hook, array('index.php', 'post.php', 'post-new.php'), true) && ($_GET['page'] ?? '') !== 'tool-kits-seo') return;
    if (!tk_toolkits_can_manage()) return;
    wp_enqueue_style('tk-editor-modals', TK_URL . 'assets/editor-modals.css', array(), tk_asset_version('assets/editor-modals.css'));
    wp_enqueue_script('tk-seo-content-modal', TK_URL . 'assets/seo-content-modal.js', array(), tk_asset_version('assets/seo-content-modal.js'), true);
}

function tk_seo_content_editor_render(int $post_id = 0, bool $audit_context = false): void {
    if (!tk_toolkits_can_manage()) return;
    $report = tk_get_option('seo_content_audit_report', array());
    $items = is_array($report) ? (array) ($report['items'] ?? array()) : array();
    if (!$post_id && !$items) return;
    $title_id = 'tk-seo-content-title-' . ($audit_context ? 'audit' : (string) $post_id);
    ?>
    <div class="tk-seo-content-editor" data-post-id="<?php echo esc_attr((string) $post_id); ?>" data-audit-context="<?php echo $audit_context ? '1' : '0'; ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('tk_seo_content_editor')); ?>">
        <?php if (!$audit_context) : ?>
        <p><button type="button" class="button tk-seo-content-open" <?php disabled($post_id > 0 && get_post_status($post_id) !== 'publish'); ?>>Edit SEO Content</button></p>
        <?php endif; ?>
        <dialog class="tk-editor-modal tk-editor-modal-wide" aria-labelledby="<?php echo esc_attr($title_id); ?>">
            <div class="tk-editor-modal-header"><div><h2 id="<?php echo esc_attr($title_id); ?>">Validate &amp; Edit SEO Content</h2><p>Review SEO checks and improve page content and search signals.</p></div><button type="button" class="tk-editor-modal-cross tk-seo-content-close" aria-label="Close dialog" title="Close">&times;</button></div>
            <div class="tk-editor-modal-body">
            <?php if (!$post_id) : ?>
                <p><label>Page<br><select class="tk-seo-content-page" style="width:100%;">
                    <?php foreach ($items as $item) : $id = (int) ($item['post_id'] ?? 0); if (!$id || !current_user_can('edit_post', $id)) continue; ?>
                        <option value="<?php echo esc_attr((string) $id); ?>"><?php echo esc_html((string) ($item['title'] ?? '')); ?></option>
                    <?php endforeach; ?>
                </select></label></p>
            <?php endif; ?>
            <div class="tk-seo-content-summary"></div>
            <div class="tk-modal-tabs" role="tablist" aria-label="Content SEO sections">
                <button type="button" role="tab" id="<?php echo esc_attr($title_id); ?>-checks-tab" aria-controls="<?php echo esc_attr($title_id); ?>-checks" aria-selected="true" data-content-panel="checks">SEO Checks &amp; Optimization</button>
                <button type="button" role="tab" id="<?php echo esc_attr($title_id); ?>-edit-tab" aria-controls="<?php echo esc_attr($title_id); ?>-edit" aria-selected="false" tabindex="-1" data-content-panel="edit">Content Improvements</button>
            </div>
            <section class="tk-seo-content-panel" id="<?php echo esc_attr($title_id); ?>-checks" role="tabpanel" aria-labelledby="<?php echo esc_attr($title_id); ?>-checks-tab" data-panel="checks">
                <div class="tk-seo-content-validation" aria-live="polite"></div>
            </section>
            <section class="tk-seo-content-panel" id="<?php echo esc_attr($title_id); ?>-edit" role="tabpanel" aria-labelledby="<?php echo esc_attr($title_id); ?>-edit-tab" data-panel="edit" hidden>
            <p class="tk-modal-note">New content, links, and project details are appended to the page. Excerpt, keyword, and location update the saved values.</p>
            <section class="tk-modal-section"><h3>Page Content</h3>
            <p><label>Additional content (plain text)<br><textarea data-field="content" rows="6" style="width:100%;" placeholder="Write useful, original content. Aim for at least 300 words in total."></textarea></label></p>
            <div class="tk-modal-grid">
            <p><label>Internal links (one per line: URL | descriptive label)<br><textarea data-field="links" rows="3" style="width:100%;" placeholder="/services/ | Our services&#10;/contact/ | Contact our team"></textarea></label></p>
            <p><label>Manual excerpt<br><textarea data-field="excerpt" rows="3" style="width:100%;"></textarea></label></p>
            </div></section>
            <section class="tk-modal-section"><h3>Search Targeting</h3><div class="tk-modal-grid">
            <p><label>Focus keyword<br><input data-field="keyword" type="text" style="width:100%;"></label></p>
            <p><label>Target location<br><select data-field="location" style="width:100%;">
                <option value="">Not location-specific</option>
                <option value="jakarta">Jakarta</option>
                <option value="singapore">Singapore</option>
            </select></label></p>
            </div></section>
            <section class="tk-modal-section"><h3>Portfolio Context</h3><div class="tk-modal-grid">
            <p><label>Client context<br><textarea data-field="client" rows="2" style="width:100%;" placeholder="Verified client name and project context"></textarea></label></p>
            <p><label>Project outcome<br><textarea data-field="outcome" rows="2" style="width:100%;" placeholder="Verified results or project impact"></textarea></label></p>
            </div></section>
            </section>
            <p class="tk-seo-content-status" role="status" aria-live="polite"></p>
            </div>
            <div class="tk-editor-modal-footer"><button type="button" class="button tk-seo-content-validate" disabled>Validate Page</button><button type="button" class="button tk-seo-content-close">Close</button><button type="button" class="button button-primary tk-seo-content-save" disabled>Save &amp; Refresh Audit</button>
            </div>
        </dialog>
    </div>
    <?php
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
