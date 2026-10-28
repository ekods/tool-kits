<?php
if (!defined('ABSPATH')) { exit; }

/** Cached per-post content score for the editor sidebar and wp-admin/edit.php lists. */
const TK_SEO_SCORE_META = '_tk_seo_content_score';
// Scoring runs a content audit, so a long uncached list fills over a few loads.
const TK_SEO_SCORE_LIST_LIMIT = 25;

function tk_seo_list_score_init(): void {
    add_action('current_screen', 'tk_seo_list_score_register');
    add_action('admin_enqueue_scripts', 'tk_seo_list_score_enqueue');
    add_action('save_post', 'tk_seo_refresh_content_score', 20, 2);
    add_filter('tk_page_cache_ignored_post_meta_keys', 'tk_seo_score_ignore_cache_meta');
}

function tk_seo_list_score_enabled(): bool {
    return (int) tk_get_option('seo_list_score_enabled', 1) === 1;
}

function tk_seo_list_score_screen_ok($screen): bool {
    return is_object($screen) && $screen->base === 'edit' && tk_seo_list_score_enabled() && tk_is_admin_user()
        && in_array($screen->post_type, tk_seo_selected_post_types(), true);
}

function tk_seo_list_score_register($screen): void {
    if (!tk_seo_list_score_screen_ok($screen)) { return; }
    add_filter('manage_' . $screen->post_type . '_posts_columns', 'tk_seo_list_score_column');
    add_action('manage_' . $screen->post_type . '_posts_custom_column', 'tk_seo_list_score_cell', 10, 2);
}

function tk_seo_list_score_enqueue(): void {
    $screen = get_current_screen();
    $editor = is_object($screen) && $screen->base === 'post' && tk_seo_editor_panel_visible('geo') && tk_is_admin_user()
        && in_array($screen->post_type, tk_seo_selected_post_types(), true);
    if (!$editor && !tk_seo_list_score_screen_ok($screen)) { return; }
    wp_enqueue_style('tk-seo-score', TK_URL . 'assets/seo-score.css', array(), tk_asset_version('assets/seo-score.css'));
}

function tk_seo_list_score_column(array $columns): array {
    $column = array('tk_seo_score' => '<span title="Tool Kits SEO score">SEO</span>');
    $position = array_search('date', array_keys($columns), true);
    if ($position === false) { return $columns + $column; }
    return array_slice($columns, 0, $position, true) + $column + array_slice($columns, $position, null, true);
}

function tk_seo_list_score_cell(string $column, int $post_id): void {
    if ($column !== 'tk_seo_score') { return; }
    $post = get_post($post_id);
    if ($post) { tk_seo_list_score_badges($post); }
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
