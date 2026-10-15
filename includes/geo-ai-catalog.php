<?php
if (!defined('ABSPATH')) { exit; }

function tk_geo_ai_catalog_enabled(): bool {
    return tk_license_features_enabled() && (int) tk_get_option('geo_ai_catalog_enabled', 0) === 1;
}

/**
 * A Tool Kits catalog of public content, generated live like llms.txt.
 * Summaries are plain text; shortcodes and content filters are never executed.
 */
function tk_geo_build_ai_catalog(): array {
    $catalog = array(
        'version' => '1.0',
        'site' => array(
            'name' => wp_strip_all_tags(get_bloginfo('name')),
            'url' => home_url('/'),
            'description' => wp_strip_all_tags(get_bloginfo('description')),
            'language' => get_bloginfo('language'),
        ),
        'links' => array('sitemap' => tk_geo_sitemap_url()),
        'items' => array(),
    );
    if (tk_geo_llms_enabled()) {
        $catalog['links']['llms'] = home_url('/llms.txt');
    }

    $post_types = get_post_types(array('public' => true, 'publicly_queryable' => true), 'names');
    unset($post_types['attachment']);
    if (empty($post_types)) {
        return $catalog;
    }

    $seen_urls = array();
    for ($page = 1; ; $page++) {
        $query = new WP_Query(array(
            'post_type' => array_values($post_types),
            'post_status' => 'publish',
            'has_password' => false,
            'posts_per_page' => 200,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        ));
        foreach ($query->posts as $post) {
            if (!is_post_publicly_viewable($post) || $post->post_password !== '') {
                continue;
            }
            $url = get_permalink($post);
            if (!$url || isset($seen_urls[$url])) {
                continue;
            }
            $seen_urls[$url] = true;
            $summary = $post->post_excerpt !== '' ? $post->post_excerpt : $post->post_content;
            $summary = wp_strip_all_tags(strip_shortcodes($summary), true);
            $catalog['items'][] = array(
                'title' => wp_strip_all_tags(get_the_title($post)),
                'url' => esc_url_raw($url),
                'type' => $post->post_type,
                'description' => wp_trim_words($summary, 55, '…'),
                'language' => tk_geo_post_language((int) $post->ID),
                'modified' => get_post_modified_time('c', true, $post),
            );
        }
        if (count($query->posts) < 200) {
            break;
        }
    }
    return $catalog;
}

function tk_geo_build_ai_catalog_json(): string {
    $json = wp_json_encode(tk_geo_build_ai_catalog(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json . "\n" : "{}\n";
}

function tk_geo_ai_catalog_maybe_render(): void {
    if (is_admin() || !tk_geo_ai_catalog_enabled()) {
        return;
    }
    $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    $catalog_path = (string) wp_parse_url(home_url('/ai-catalog.json'), PHP_URL_PATH);
    if (untrailingslashit($path) !== untrailingslashit($catalog_path)) {
        return;
    }
    status_header(200);
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    if (isset($_GET['download']) && $_GET['download'] === '1') {
        header('Content-Disposition: attachment; filename="ai-catalog.json"');
    }
    echo tk_geo_build_ai_catalog_json();
    exit;
}
