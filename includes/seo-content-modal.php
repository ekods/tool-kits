<?php
if (!defined('ABSPATH')) { exit; }

function tk_seo_content_editor_enqueue($hook): void {
    if (!in_array($hook, array('index.php', 'post.php', 'post-new.php'), true) || !tk_toolkits_can_manage()) return;
    wp_enqueue_style('tk-editor-modals', TK_URL . 'assets/editor-modals.css', array(), tk_asset_version('assets/editor-modals.css'));
    wp_enqueue_script('tk-seo-content-modal', TK_URL . 'assets/seo-content-modal.js', array(), tk_asset_version('assets/seo-content-modal.js'), true);
}

function tk_seo_content_editor_render(int $post_id = 0): void {
    if (!tk_toolkits_can_manage()) return;
    $report = tk_get_option('seo_content_audit_report', array());
    $items = is_array($report) ? (array) ($report['items'] ?? array()) : array();
    if (!$post_id && !$items) return;
    ?>
    <div class="tk-seo-content-editor" data-post-id="<?php echo esc_attr((string) $post_id); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('tk_seo_content_editor')); ?>">
        <p><button type="button" class="button tk-seo-content-open" <?php disabled($post_id > 0 && get_post_status($post_id) !== 'publish'); ?>>Edit SEO Content</button></p>
        <dialog class="tk-editor-modal" aria-labelledby="tk-seo-content-title">
            <div class="tk-editor-modal-header"><div><h2 id="tk-seo-content-title">SEO / GEO Content Improvements</h2><p>Improve page content and search signals.</p></div><button type="button" class="tk-editor-modal-cross tk-seo-content-close" aria-label="Close dialog" title="Close">&times;</button></div>
            <div class="tk-editor-modal-body">
            <?php if (!$post_id) : ?>
                <p><label>Page<br><select class="tk-seo-content-page" style="width:100%;">
                    <?php foreach ($items as $item) : $id = (int) ($item['post_id'] ?? 0); if (!$id || !current_user_can('edit_post', $id)) continue; ?>
                        <option value="<?php echo esc_attr((string) $id); ?>"><?php echo esc_html((string) ($item['title'] ?? '')); ?></option>
                    <?php endforeach; ?>
                </select></label></p>
            <?php endif; ?>
            <p class="tk-modal-note">New content, links, and project details are appended to the page. Excerpt, keyword, and location update the saved values.</p>
            <p class="tk-seo-content-summary"></p>
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
            <p class="tk-seo-content-status" role="status" aria-live="polite"></p>
            </div>
            <div class="tk-editor-modal-footer"><button type="button" class="button tk-seo-content-close">Cancel</button><button type="button" class="button button-primary tk-seo-content-save" disabled>Save &amp; Refresh Audit</button>
            </div>
        </dialog>
    </div>
    <?php
}

function tk_seo_content_editor_version($post): string {
    return hash('sha256', $post->post_content . '\0' . $post->post_excerpt . '\0' . (string) get_post_meta($post->ID, '_tk_seo_focus_keyword', true) . '\0' . (string) get_post_meta($post->ID, '_tk_seo_target_location', true));
}

function tk_seo_content_editor_ajax(): void {
    if (!tk_toolkits_can_manage()) wp_send_json_error(array('message' => 'Forbidden'), 403);
    check_ajax_referer('tk_seo_content_editor', 'nonce');
    $id = absint($_POST['post_id'] ?? 0);
    $post = get_post($id);
    if (!$post || !current_user_can('edit_post', $id) || $post->post_status !== 'publish' || $post->post_password !== '' ||
        !is_post_type_viewable($post->post_type) || !in_array($post->post_type, tk_seo_selected_post_types(), true)) {
        wp_send_json_error(array('message' => 'Choose an editable public published page.'), 403);
    }
    $version = tk_seo_content_editor_version($post);
    if (($_POST['mode'] ?? '') === 'save') {
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
        tk_update_option('seo_content_audit_report', tk_seo_run_content_audit());
    }
    $post = get_post($id);
    wp_send_json_success(array('version' => tk_seo_content_editor_version($post), 'excerpt' => $post->post_excerpt,
        'keyword' => (string) get_post_meta($id, '_tk_seo_focus_keyword', true),
        'location' => (string) get_post_meta($id, '_tk_seo_target_location', true),
        'words' => str_word_count(wp_strip_all_tags($post->post_content)), 'title' => get_the_title($id)));
}
