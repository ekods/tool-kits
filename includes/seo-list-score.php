<?php
if (!defined('ABSPATH')) { exit; }

/** Cached per-post content score for the editor sidebar, plus SEO and GEO columns for wp-admin/edit.php lists. */
const TK_SEO_SCORE_META = '_tk_seo_content_score';
// Scoring runs a content audit, so a long uncached list fills over a few loads.
const TK_SEO_SCORE_LIST_LIMIT = 25;

function tk_seo_list_score_init(): void {
    add_action('current_screen', 'tk_seo_list_score_register');
    add_action('admin_enqueue_scripts', 'tk_seo_list_score_enqueue');
    // REST saves finish terms and additional metadata after save_post fires.
    add_action('wp_after_insert_post', 'tk_seo_refresh_content_score', 20, 2);
    add_action('wp_ajax_tk_seo_content_score', 'tk_seo_content_score_ajax');
    add_filter('tk_page_cache_ignored_post_meta_keys', 'tk_seo_score_ignore_cache_meta');
}

function tk_seo_list_score_enabled(): bool {
    return (int) tk_get_option('seo_list_score_enabled', 1) === 1;
}

function tk_seo_list_geo_enabled(): bool {
    return (int) tk_get_option('seo_list_geo_enabled', 1) === 1;
}

function tk_seo_list_score_screen_ok($screen): bool {
    return is_object($screen) && $screen->base === 'edit' && (tk_seo_list_score_enabled() || tk_seo_list_geo_enabled())
        && tk_is_admin_user() && in_array($screen->post_type, tk_seo_selected_post_types(), true);
}

function tk_seo_list_score_register($screen): void {
    if (!tk_seo_list_score_screen_ok($screen)) { return; }
    add_filter('manage_' . $screen->post_type . '_posts_columns', 'tk_seo_list_score_column');
    add_action('manage_' . $screen->post_type . '_posts_custom_column', 'tk_seo_list_score_cell', 10, 2);
}

function tk_seo_list_score_enqueue(): void {
    $screen = get_current_screen();
    $editor = is_object($screen) && $screen->base === 'post'
        && (tk_seo_editor_panel_visible('geo') || tk_seo_editor_panel_visible('content')) && tk_is_admin_user()
        && in_array($screen->post_type, tk_seo_selected_post_types(), true);
    if (!$editor && !tk_seo_list_score_screen_ok($screen)) { return; }
    wp_enqueue_style('tk-seo-score', TK_URL . 'assets/seo-score.css', array(), tk_asset_version('assets/seo-score.css'));
    if ($editor) {
        wp_enqueue_script('tk-seo-score-refresh', TK_URL . 'assets/seo-score.js', array(), tk_asset_version('assets/seo-score.js'), true);
    }
}

function tk_seo_score_refresh_attributes(int $post_id): void {
    printf('data-tk-seo-score-post="%s" data-tk-seo-score-url="%s" data-tk-seo-score-nonce="%s"',
        esc_attr((string) $post_id), esc_url(admin_url('admin-ajax.php')), esc_attr(wp_create_nonce('tk_seo_content_score_' . $post_id)));
}

/** Read saved content only; an editor refresh must never save unsaved form fields. */
function tk_seo_content_score_ajax(): void {
    if (!tk_toolkits_can_manage()) { wp_send_json_error(array('message' => 'Forbidden'), 403); }
    $raw_id = $_POST['post_id'] ?? '';
    if (!is_scalar($raw_id) || !ctype_digit((string) $raw_id) || (int) $raw_id <= 0) {
        wp_send_json_error(array('message' => 'Invalid post.'), 400);
    }
    $post_id = (int) $raw_id;
    check_ajax_referer('tk_seo_content_score_' . $post_id, 'nonce');
    $post = get_post($post_id);
    if (!$post || !current_user_can('edit_post', $post_id) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)
        || !in_array($post->post_type, tk_seo_selected_post_types(), true)) {
        wp_send_json_error(array('message' => 'You cannot review this content.'), 403);
    }
    if (in_array($post->post_status, array('auto-draft', 'trash'), true)) {
        wp_send_json_error(array('message' => 'Save a draft before refreshing the score.'), 400);
    }
    $report = tk_seo_run_content_audit($post_id);
    $item = $report['items'][0] ?? null;
    if (!is_array($item) || !isset($item['score'])) {
        wp_send_json_error(array('message' => 'The content score is unavailable.'), 500);
    }
    $score = max(0, min(100, (int) $item['score']));
    update_post_meta($post_id, TK_SEO_SCORE_META, array('score' => $score, 'version' => tk_seo_content_editor_version($post), 'scanned_at' => time()));
    ob_start(); tk_seo_render_content_analysis($post, $item); $analysis = ob_get_clean();
    ob_start(); tk_seo_list_score_badges($post, false); $badge = ob_get_clean();
    wp_send_json_success(array('post_id' => $post_id, 'score' => $score, 'analysis_html' => $analysis, 'badge_html' => $badge));
}

function tk_seo_list_score_column(array $columns): array {
    $column = array();
    if (tk_seo_list_score_enabled()) { $column['tk_seo_score'] = '<span title="Tool Kits SEO score">SEO</span>'; }
    if (tk_seo_list_geo_enabled()) { $column['tk_geo_score'] = '<span title="Tool Kits GEO audit score">GEO</span>'; }
    $position = array_search('date', array_keys($columns), true);
    if ($position === false) { return $columns + $column; }
    return array_slice($columns, 0, $position, true) + $column + array_slice($columns, $position, null, true);
}

function tk_seo_list_score_cell(string $column, int $post_id): void {
    if (!in_array($column, array('tk_seo_score', 'tk_geo_score'), true)) { return; }
    $post = get_post($post_id);
    if (!$post) { return; }
    if ($column === 'tk_seo_score') {
        tk_seo_list_score_badges($post);
    } else {
        tk_seo_list_geo_badge($post);
    }
}

/** GEO results come from the saved GEO Audit report; list views never fetch public pages. */
function tk_seo_list_geo_items(): array {
    static $items = null;
    if ($items !== null) { return $items; }
    $items = array();
    $report = (array) tk_get_option('seo_geo_audit_report', array());
    foreach ((array) ($report['items'] ?? array()) as $item) {
        if (is_array($item) && is_string($item['url'] ?? null)) { $items[$item['url']] = $item; }
    }
    return $items;
}

function tk_seo_list_geo_badge($post): void {
    if (!tk_seo_post_feature_enabled('geo', (int) $post->ID)) {
        echo '<span class="tk-seo-score tk-seo-score-off" title="GEO is off for this content">Off</span>';
        return;
    }
    if ($post->post_status !== 'publish' || $post->post_password !== '' || !is_post_type_viewable($post->post_type)) {
        echo '<span class="tk-seo-score tk-seo-score-pending" title="GEO Audit checks published public content only">—</span>';
        return;
    }
    $item = tk_seo_list_geo_items()[(string) get_permalink($post)] ?? null;
    $audit = esc_url(tk_admin_url('tool-kits-geo-audit'));
    if (!is_array($item)) {
        printf('<a class="tk-seo-score tk-seo-score-pending" href="%s" title="Not audited yet. Run GEO Audit &amp; Fixes to score this URL.">—</a>', $audit);
        return;
    }
    $issues = count((array) ($item['issues'] ?? array()));
    if (!isset($item['score']) || !is_numeric($item['score'])) {
        printf('<a class="tk-seo-score tk-seo-score-poor" href="%s" title="%s">!</a>', $audit, esc_attr('GEO Audit could not verify this URL: ' . (string) (((array) ($item['issues'] ?? array()))[0] ?? 'request failed')));
        return;
    }
    $score = (int) $item['score'];
    $title = 'GEO audit score: ' . $score . '/100' . (!empty($item['grade']) && $item['grade'] !== '-' ? ' (grade ' . $item['grade'] . ')' : '') . ', ' . $issues . ' ' . ($issues === 1 ? 'issue' : 'issues');
    printf('<a class="tk-seo-score tk-seo-score-%1$s" href="%2$s" title="%3$s">%4$d</a>', esc_attr(tk_seo_score_level($score)), $audit, esc_attr($title), $score);
}

function tk_seo_score_ignore_cache_meta(array $keys): array {
    $keys[] = TK_SEO_SCORE_META;
    return $keys;
}

function tk_seo_score_level(int $score): string {
    return $score >= 85 ? 'good' : ($score >= 50 ? 'fair' : 'poor');
}

/** Returns the cached score, recalculating when stored content or keyword changed. */
function tk_seo_content_score($post, bool $allow_compute = true): ?int {
    if (!is_object($post) || $post->post_status === 'auto-draft') { return null; }
    $version = tk_seo_content_editor_version($post);
    $cached = get_post_meta($post->ID, TK_SEO_SCORE_META, true);
    if (is_array($cached) && ($cached['version'] ?? '') === $version && isset($cached['score'])) {
        return (int) $cached['score'];
    }
    if (!$allow_compute) { return null; }
    $report = tk_seo_run_content_audit((int) $post->ID);
    $item = $report['items'][0] ?? null;
    if (!is_array($item)) { return null; }
    $score = (int) $item['score'];
    update_post_meta($post->ID, TK_SEO_SCORE_META, array('score' => $score, 'version' => $version, 'scanned_at' => time()));
    return $score;
}

function tk_seo_refresh_content_score($post_id, $post): void {
    if (!is_object($post) || wp_is_post_revision((int) $post_id) || wp_is_post_autosave((int) $post_id)
        || !in_array($post->post_type, tk_seo_selected_post_types(), true)) { return; }
    delete_post_meta((int) $post_id, TK_SEO_SCORE_META);
    if (is_admin() && tk_is_admin_user()) { tk_seo_content_score(get_post((int) $post_id)); }
}

function tk_seo_list_score_badges($post, bool $show_public = true): void {
    static $computed = 0;
    if (!tk_seo_post_feature_enabled('seo', (int) $post->ID)) {
        echo '<span class="tk-seo-score tk-seo-score-off" title="SEO is off for this content">Off</span>';
        return;
    }
    $score = tk_seo_content_score($post, false);
    if ($score === null && $computed < TK_SEO_SCORE_LIST_LIMIT) {
        $computed++;
        $score = tk_seo_content_score($post);
    }
    if ($score === null && $post->post_status !== 'auto-draft') {
        echo '<span class="tk-seo-score tk-seo-score-pending" title="Reload the list to calculate the remaining scores">—</span>';
    } elseif ($score !== null) {
        printf('<span class="tk-seo-score tk-seo-score-%1$s" title="Content SEO score: %2$d/100">%2$d</span>', esc_attr(tk_seo_score_level($score)), $score);
    }
    if (!$show_public) { return; }
    $report = get_post_meta($post->ID, '_tk_seo_rendered_audit', true);
    if (is_array($report) && empty($report['error']) && isset($report['score'])) {
        $public = (int) $report['score'];
        $date = !empty($report['scanned_at']) ? ' (' . wp_date('Y-m-d H:i', (int) $report['scanned_at']) . ')' : '';
        printf('<span class="tk-seo-score tk-seo-score-public tk-seo-score-%1$s" title="%2$s">%3$d</span>', esc_attr(tk_seo_score_level($public)), esc_attr('Public page audit' . $date . ': ' . $public . '/100'), $public);
    }
}
