<?php
if (!defined('ABSPATH')) { exit; }
if (!function_exists('tk_request_needs_admin_modules') || tk_request_needs_admin_modules()) {
    require_once __DIR__ . '/geo-audit-batch.php';
    require_once __DIR__ . '/geo-schema-check.php';
}

require_once __DIR__ . '/geo-crawler-fixes.php';
require_once __DIR__ . '/geo-radar.php';
require_once __DIR__ . '/geo-ai-catalog.php';

function tk_geo_init() {
    add_action('wp_ajax_tk_geo_schema_check_url', 'tk_geo_schema_check_url_ajax');
    add_action('admin_enqueue_scripts', 'tk_geo_schema_check_assets');
    add_action('admin_enqueue_scripts', 'tk_geo_faq_editor_assets');
    add_action('wp_ajax_tk_geo_batch_start', 'tk_geo_batch_start');
    add_action('wp_ajax_tk_geo_batch_step', 'tk_geo_batch_step');
    add_action('wp_ajax_tk_geo_fix_batch_start', 'tk_geo_fix_batch_start');
    add_action('wp_ajax_tk_geo_fix_batch_step', 'tk_geo_fix_batch_step');
    add_action('admin_enqueue_scripts', 'tk_geo_batch_assets');
    add_action('admin_post_tk_geo_save', 'tk_geo_save');
    add_action('admin_post_tk_geo_clear_saved_results', 'tk_geo_clear_saved_results');
    add_action('admin_post_tk_geo_ai_access_scan', 'tk_geo_ai_access_scan');
    add_action('admin_post_tk_geo_ai_access_clear', 'tk_geo_ai_access_clear');
    add_action('admin_post_tk_geo_schema_duplicate_scan', 'tk_geo_schema_duplicate_scan');
    add_action('admin_post_tk_geo_schema_duplicate_clear', 'tk_geo_schema_duplicate_clear');
    add_action('admin_post_tk_geo_schema_duplicate_fix', 'tk_geo_schema_duplicate_fix');
    add_action('admin_post_tk_seo_geo_audit_scan', 'tk_seo_geo_audit_scan');
    add_action('admin_post_tk_seo_geo_audit_clear', 'tk_seo_geo_audit_clear');
    add_action('admin_post_tk_geo_crawler_preview', 'tk_geo_crawler_preview_handler');
    add_action('admin_post_tk_geo_crawler_fix', 'tk_geo_crawler_fix_handler');
    add_action('template_redirect', 'tk_geo_crawler_fix_start', 1000);
    add_action('admin_post_tk_geo_visibility_scan', 'tk_geo_visibility_scan_handler');
    add_action('admin_post_tk_geo_visibility_fix', 'tk_geo_visibility_fix_handler');
    add_action('admin_post_tk_geo_audit_fix', 'tk_geo_audit_fix_handler');
    add_action('admin_post_tk_geo_audit_bulk_fix', 'tk_geo_audit_bulk_fix_handler');
    add_action('admin_post_tk_geo_audit_remove_fix', 'tk_geo_audit_remove_fix_handler');
    add_action('admin_post_tk_geo_audit_remove_all_fixes', 'tk_geo_audit_remove_all_fixes_handler');
    add_action('admin_post_tk_geo_ai_radar_scan', 'tk_geo_ai_radar_scan');
    add_action('admin_post_tk_geo_prompt_preview', 'tk_geo_prompt_preview_handler');
    add_action('init', 'tk_geo_llms_maybe_render', 1);
    // After post types are registered, before routing, 404 redirects and HTML buffers.
    add_action('wp_loaded', 'tk_geo_ai_catalog_maybe_render', 0);
    add_filter('wp_headers', 'tk_geo_ai_catalog_discovery_headers');
    add_action('wp_head', 'tk_geo_ai_catalog_discovery_head', 2);
    add_action('wp_head', 'tk_geo_maybe_replace_theme_schema', 0);
    add_action('wp_head', 'tk_geo_render_head_jsonld', 3);
    // Same content-change hooks as the sitemap cache; WordPress registers the shared callback once.
    foreach (array('save_post', 'deleted_post', 'trashed_post', 'update_option_permalink_structure') as $hook) {
        add_action($hook, 'tk_seo_sitemap_flush_cache');
    }
    add_shortcode('tool_kits_geo_faq', 'tk_geo_faq_shortcode');
}

// Loaded by the FAQ shortcode only, not on every page.
function tk_geo_enqueue_faq_styles(): void {
    wp_enqueue_style('tk-geo-faq', TK_URL . 'assets/geo-faq.css', array(), tk_asset_version('assets/geo-faq.css'));
}

// The FAQPage editor's inline script runs while the page renders, so the picker loads in the head.
function tk_geo_faq_editor_assets(): void {
    if (($_GET['page'] ?? '') !== 'tool-kits-geo' || !tk_toolkits_can_manage()) {
        return;
    }
    wp_enqueue_style('tk-language-picker', TK_URL . 'assets/language-picker.css', array(), tk_asset_version('assets/language-picker.css'));
    wp_enqueue_script('tk-language-picker', TK_URL . 'assets/language-picker.js', array(), tk_asset_version('assets/language-picker.js'), false);
}

function tk_geo_enabled(): bool {
    return tk_license_features_enabled() && (int) tk_get_option('geo_enabled', 0) === 1
        && (!function_exists('tk_seo_post_feature_enabled') || tk_seo_post_feature_enabled('geo'));
}

function tk_geo_public_post_types(): array {
    $post_types = get_post_types(array('public' => true), 'objects');
    unset($post_types['attachment']);
    return is_array($post_types) ? $post_types : array();
}

function tk_geo_posts_for_selector(string $post_type): array {
    $post_types = tk_geo_public_post_types();
    if (!isset($post_types[$post_type])) {
        $post_type = 'post';
    }

    return get_posts(array(
        'post_type' => $post_type,
        'post_status' => 'publish',
        'numberposts' => 100,
        'orderby' => 'modified',
        'order' => 'DESC',
    ));
}

function tk_geo_decode_custom_jsonld(string $json) {
    $json = trim($json);
    if ($json === '') {
        return null;
    }
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return false;
    }
    return $decoded;
}

function tk_geo_generate_custom_jsonld(): array {
    $site_name = get_bloginfo('name');
    $description = trim((string) get_bloginfo('description'));
    $home_url = home_url('/');
    $schema = array(
        '@context' => 'https://schema.org',
        '@graph' => array(
            array(
                '@type' => 'WebSite',
                '@id' => $home_url . '#website',
                'url' => $home_url,
                'name' => $site_name,
                'inLanguage' => get_bloginfo('language'),
                'potentialAction' => array(
                    '@type' => 'SearchAction',
                    'target' => home_url('/?s={search_term_string}'),
                    'query-input' => 'required name=search_term_string',
                ),
            ),
            array(
                '@type' => 'Organization',
                '@id' => $home_url . '#organization',
                'name' => $site_name,
                'url' => $home_url,
            ),
        ),
    );

    if ($description !== '') {
        $schema['@graph'][0]['description'] = $description;
    }

    $logo_id = (int) get_theme_mod('custom_logo');
    $logo_url = $logo_id > 0 ? wp_get_attachment_image_url($logo_id, 'full') : '';
    if (is_string($logo_url) && $logo_url !== '') {
        $schema['@graph'][1]['logo'] = $logo_url;
    }

    return $schema;
}

function tk_geo_normalize_faq_items($items): array {
    if (!is_array($items)) {
        return array();
    }
    $normalized = array();
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $question = isset($item['question']) ? trim(wp_strip_all_tags((string) $item['question'])) : '';
        $answer = isset($item['answer']) ? trim(wp_strip_all_tags((string) $item['answer'])) : '';
        if ($question === '' || $answer === '') {
            continue;
        }
        $entry = array(
            'question' => $question,
            'answer' => $answer,
        );
        if (!empty($item['language']) && is_string($item['language'])) {
            $language = strtolower(str_replace('_', '-', trim($item['language'])));
            if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $language)) {
                $entry['language'] = $language;
            }
        }
        $normalized[] = $entry;
        if (count($normalized) >= 50) {
            break;
        }
    }
    return $normalized;
}

function tk_geo_normalize_language_tag($language, string $fallback = ''): string {
    if (is_array($language)) {
        $language = reset($language);
    }
    $language = is_string($language) ? trim($language) : '';
    if ($language === '') {
        $language = $fallback;
    }
    $language = strtolower(str_replace('_', '-', trim((string) $language)));
    return preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $language) ? $language : '';
}

function tk_geo_bilingual_language_order(): array {
    $languages = array();
    if (function_exists('pll_languages_list')) {
        $languages = pll_languages_list(array('fields' => 'locale'));
        if (!is_array($languages) || empty($languages)) {
            $languages = pll_languages_list(array('fields' => 'slug'));
        }
    } elseif (function_exists('has_filter') && has_filter('wpml_active_languages')) {
        $wpml_languages = apply_filters('wpml_active_languages', null, array('skip_missing' => 0));
        if (is_array($wpml_languages)) {
            foreach ($wpml_languages as $code => $language) {
                if (is_array($language)) {
                    $languages[] = $language['default_locale'] ?? $language['code'] ?? $code;
                } else {
                    $languages[] = is_string($code) ? $code : '';
                }
            }
        }
    }

    $normalized = array();
    foreach (is_array($languages) ? $languages : array() as $language) {
        $tag = tk_geo_normalize_language_tag($language);
        if ($tag !== '') {
            $normalized[] = $tag;
        }
    }
    $normalized = array_values(array_unique($normalized));
    if (function_exists('apply_filters')) {
        $filtered = apply_filters('tk_geo_faq_language_order', $normalized);
        if (is_array($filtered)) {
            $normalized = array_values(array_unique(array_filter(array_map('tk_geo_normalize_language_tag', $filtered))));
        }
    }
    return $normalized;
}

function tk_geo_sort_language_tags(array $languages, array $preferred_order = array()): array {
    $languages = array_values(array_unique(array_filter(array_map('tk_geo_normalize_language_tag', $languages))));
    $preferred_order = array_values(array_unique(array_filter(array_map('tk_geo_normalize_language_tag', $preferred_order))));
    $rank = function(string $language) use ($preferred_order): int {
        $exact = array_search($language, $preferred_order, true);
        if ($exact !== false) {
            return (int) $exact;
        }
        $base = explode('-', $language)[0];
        foreach ($preferred_order as $index => $preferred) {
            if (explode('-', $preferred)[0] === $base) {
                return (int) $index;
            }
        }
        return count($preferred_order) + 100;
    };
    $original_order = array_flip($languages);
    usort($languages, function(string $left, string $right) use ($rank, $original_order): int {
        $difference = $rank($left) <=> $rank($right);
        return $difference !== 0 ? $difference : (($original_order[$left] ?? 0) <=> ($original_order[$right] ?? 0));
    });
    return $languages;
}

function tk_geo_array_is_list(array $value): bool {
    if (function_exists('array_is_list')) {
        return array_is_list($value);
    }
    return $value === array_values($value);
}

function tk_geo_import_faqpage_json(string $json, string $fallback_language = '') {
    $decoded = json_decode(trim($json), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return new WP_Error('tk_geo_faq_import_json', __('FAQPage import must contain valid JSON.', 'tool-kits'));
    }

    $fallback_language = tk_geo_normalize_language_tag($fallback_language, (string) get_bloginfo('language'));
    $items = array();
    $walk = function ($node) use (&$walk, &$items, $fallback_language): void {
        if (!is_array($node)) {
            return;
        }
        if (tk_geo_array_is_list($node)) {
            foreach ($node as $child) {
                $walk($child);
            }
            return;
        }

        $types = isset($node['@type']) ? (array) $node['@type'] : array();
        if (in_array('FAQPage', $types, true) && !empty($node['mainEntity']) && is_array($node['mainEntity'])) {
            $page_language = tk_geo_normalize_language_tag($node['inLanguage'] ?? '', $fallback_language);
            foreach ($node['mainEntity'] as $question) {
                if (!is_array($question)) {
                    continue;
                }
                $answer = $question['acceptedAnswer'] ?? array();
                if (is_array($answer) && tk_geo_array_is_list($answer)) {
                    $answer = reset($answer);
                }
                $answer_text = is_array($answer) ? ($answer['text'] ?? '') : '';
                $items[] = array(
                    'question' => $question['name'] ?? '',
                    'answer' => $answer_text,
                    'language' => tk_geo_normalize_language_tag($question['inLanguage'] ?? '', $page_language),
                );
            }
        }

        if (!empty($node['@graph']) && is_array($node['@graph'])) {
            $walk($node['@graph']);
        }
    };
    $walk($decoded);

    $items = tk_geo_normalize_faq_items($items);
    if (empty($items)) {
        return new WP_Error('tk_geo_faq_import_empty', __('No valid FAQPage questions were found in the JSON.', 'tool-kits'));
    }
    return $items;
}

function tk_geo_faq_question_key(array $item): string {
    $language = isset($item['language']) ? tk_geo_normalize_language_tag($item['language']) : '';
    $question = isset($item['question']) ? trim(wp_strip_all_tags((string) $item['question'])) : '';
    $question = function_exists('mb_strtolower') ? mb_strtolower($question, 'UTF-8') : strtolower($question);
    return $language . '|' . preg_replace('/\s+/', ' ', $question);
}

function tk_geo_merge_faq_items(array $current_items, array $imported_items, string $mode = 'merge'): array {
    $current_items = tk_geo_normalize_faq_items($current_items);
    $imported_items = tk_geo_normalize_faq_items($imported_items);

    if ($mode === 'replace') {
        $replace_languages = array_values(array_unique(array_filter(array_map(function ($item) {
            return isset($item['language']) ? tk_geo_normalize_language_tag($item['language']) : '';
        }, $imported_items))));

        if (count($replace_languages) === 1) {
            $replace_language = $replace_languages[0];
            $current_items = array_values(array_filter($current_items, function ($item) use ($replace_language) {
                return tk_geo_normalize_language_tag($item['language'] ?? '') !== $replace_language;
            }));
            return array_slice(array_merge($current_items, $imported_items), 0, 50);
        }

        return array_slice($imported_items, 0, 50);
    }

    if ($mode === 'append') {
        return array_slice(array_merge($current_items, $imported_items), 0, 50);
    }

    $merged = $current_items;
    $indexes = array();
    foreach ($merged as $index => $item) {
        $key = tk_geo_faq_question_key($item);
        if ($key !== '|') {
            $indexes[$key] = $index;
        }
    }

    foreach ($imported_items as $item) {
        $key = tk_geo_faq_question_key($item);
        if ($key !== '|' && isset($indexes[$key])) {
            $merged[$indexes[$key]] = $item;
            continue;
        }
        if (count($merged) >= 50) {
            break;
        }
        $indexes[$key] = count($merged);
        $merged[] = $item;
    }

    return array_slice($merged, 0, 50);
}

function tk_geo_selected_itemlist_ids(): array {
    $raw = tk_get_option('geo_itemlist_post_ids', array());
    if (!is_array($raw)) {
        return array();
    }
    return array_values(array_unique(array_filter(array_map('intval', $raw))));
}

function tk_geo_itemlist_language_mode(): string {
    $mode = sanitize_key((string) tk_get_option('geo_itemlist_language_mode', 'single'));
    return in_array($mode, array('single', 'all'), true) ? $mode : 'single';
}

function tk_geo_post_language(int $post_id): string {
    if (function_exists('pll_get_post_language')) {
        $language = (string) pll_get_post_language($post_id, 'locale');
        if ($language !== '') {
            return $language;
        }
        $language = (string) pll_get_post_language($post_id, 'slug');
        if ($language !== '') {
            return $language;
        }
    }

    if (has_filter('wpml_post_language_details')) {
        $details = apply_filters('wpml_post_language_details', null, $post_id);
        if (is_array($details)) {
            foreach (array('locale', 'language_code') as $key) {
                if (!empty($details[$key]) && is_string($details[$key])) {
                    return (string) $details[$key];
                }
            }
        }
    }

    return (string) get_bloginfo('language');
}

function tk_geo_post_translation_ids(int $post_id): array {
    $ids = array($post_id);

    if (function_exists('pll_get_post_translations')) {
        $translations = pll_get_post_translations($post_id);
        if (is_array($translations)) {
            foreach ($translations as $translation_id) {
                $ids[] = (int) $translation_id;
            }
        }
    } elseif (has_filter('wpml_element_trid') && has_filter('wpml_get_element_translations')) {
        $post_type = (string) get_post_type($post_id);
        $trid = apply_filters('wpml_element_trid', null, $post_id, 'post_' . $post_type);
        if ($trid) {
            $translations = apply_filters('wpml_get_element_translations', null, $trid, 'post_' . $post_type);
            if (is_array($translations)) {
                foreach ($translations as $translation) {
                    if (is_object($translation) && !empty($translation->element_id)) {
                        $ids[] = (int) $translation->element_id;
                    } elseif (is_array($translation) && !empty($translation['element_id'])) {
                        $ids[] = (int) $translation['element_id'];
                    }
                }
            }
        }
    }

    $ids = apply_filters('tk_geo_itemlist_translation_ids', $ids, $post_id);
    if (!is_array($ids)) {
        $ids = array($post_id);
    }

    return array_values(array_unique(array_filter(array_map('intval', $ids))));
}

function tk_geo_expand_itemlist_post_ids(array $ids): array {
    if (tk_geo_itemlist_language_mode() !== 'all') {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    $expanded = array();
    foreach ($ids as $post_id) {
        foreach (tk_geo_post_translation_ids((int) $post_id) as $translation_id) {
            $expanded[] = $translation_id;
        }
    }

    return array_slice(array_values(array_unique(array_filter(array_map('intval', $expanded)))), 0, 50);
}

function tk_geo_current_url(): string {
    $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '';
    if ($uri === '') {
        return home_url('/');
    }
    return home_url($uri);
}

// A page's own Tool Kits FAQ GEO replaces the global FAQ; an emptied one falls back to the global FAQ.
function tk_geo_current_faq_items(): array {
    if (!is_admin() && is_singular()) {
        $post_items = tk_geo_normalize_faq_items(get_post_meta((int) get_queried_object_id(), '_tk_geo_faq_items', true));
        if ($post_items) {
            return tk_geo_enabled() ? $post_items : array();
        }
    }
    if ((int) tk_get_option('geo_faq_enabled', 0) !== 1) {
        return array();
    }
    return tk_geo_normalize_faq_items(tk_get_option('geo_faq_items', array()));
}

function tk_geo_faq_items_for_language(array $items, string $language): array {
    $language = strtolower(str_replace('_', '-', $language));
    $base = explode('-', $language)[0];
    return array_values(array_filter($items, function ($item) use ($language, $base) {
        $tag = $item['language'] ?? '';
        return $tag === '' || $tag === $language || $tag === $base;
    }));
}

function tk_geo_current_faq_language(): string {
    return !is_admin() && is_singular() ? tk_geo_post_language((int) get_queried_object_id()) : get_bloginfo('language');
}

function tk_geo_build_faq_schema(): array {
    $items = tk_geo_faq_items_for_language(tk_geo_current_faq_items(), tk_geo_current_faq_language());
    if (empty($items)) {
        return array();
    }

    $url = tk_geo_current_url();
    return array(
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        '@id' => $url . '#tool-kits-faq',
        'url' => $url,
        'name' => wp_get_document_title() . ' FAQ',
        'inLanguage' => tk_geo_current_faq_language(),
        'mainEntity' => array_map(function($item) {
            return array(
                '@type' => 'Question',
                '@id' => sanitize_title($item['question']),
                'name' => $item['question'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text' => $item['answer'],
                ),
            );
        }, $items),
    );
}

function tk_geo_faq_shortcode($atts = array()): string {
    $items = tk_geo_faq_items_for_language(tk_geo_current_faq_items(), tk_geo_current_faq_language());
    if (empty($items)) {
        return '';
    }
    tk_geo_enqueue_faq_styles();
    // The head already prints this FAQ as FAQPage JSON-LD; microdata here would be a second FAQPage.
    $microdata = !tk_geo_enabled();

    ob_start();
    ?>
    <section class="tk-geo-faq"<?php echo $microdata ? ' itemscope itemtype="https://schema.org/FAQPage"' : ''; ?>>
        <?php foreach ($items as $item) : ?>
            <details class="tk-geo-faq-item"<?php echo $microdata ? ' itemscope itemprop="mainEntity" itemtype="https://schema.org/Question"' : ''; ?>>
                <summary><span<?php echo $microdata ? ' itemprop="name"' : ''; ?>><?php echo esc_html((string) $item['question']); ?></span></summary>
                <div class="tk-geo-faq-answer"<?php echo $microdata ? ' itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer"' : ''; ?>>
                    <div<?php echo $microdata ? ' itemprop="text"' : ''; ?>><?php echo wpautop(esc_html((string) $item['answer'])); ?></div>
                </div>
            </details>
        <?php endforeach; ?>
    </section>
    <?php
    return (string) ob_get_clean();
}

// Every public page prints the ItemList, so keep the posts queries out of page views. The key follows the
// settings, the request language (Polylang/WPML filter the queries) and the content generation that
// tk_seo_sitemap_flush_cache() bumps on post/term changes.
function tk_geo_build_itemlist_schema(): array {
    if ((int) tk_get_option('geo_itemlist_enabled', 0) !== 1) {
        return array();
    }
    $settings = array();
    foreach (array('geo_itemlist_post_type', 'geo_itemlist_post_ids', 'geo_itemlist_limit', 'geo_itemlist_language_mode', 'geo_itemlist_name', 'geo_itemlist_description') as $key) {
        $settings[$key] = tk_get_option($key, '');
    }
    $cache_key = 'tk_geo_itemlist_' . md5((string) wp_json_encode(array($settings, home_url('/'), determine_locale(), (int) get_option('tk_seo_sitemap_generation', 0))));
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }
    $schema = tk_geo_build_itemlist_schema_uncached();
    set_transient($cache_key, $schema, 12 * HOUR_IN_SECONDS);
    return $schema;
}

function tk_geo_build_itemlist_schema_uncached(): array {

    $post_type = sanitize_key((string) tk_get_option('geo_itemlist_post_type', 'post'));
    $post_types = tk_geo_public_post_types();
    if (!isset($post_types[$post_type])) {
        $post_type = 'post';
    }

    $ids = tk_geo_selected_itemlist_ids();
    $limit = max(1, min(50, (int) tk_get_option('geo_itemlist_limit', 10)));
    $args = array(
        'post_type' => $post_type,
        'post_status' => 'publish',
        'numberposts' => $limit,
        'orderby' => 'modified',
        'order' => 'DESC',
    );
    if (!empty($ids)) {
        $ids = tk_geo_expand_itemlist_post_ids($ids);
        $args['post__in'] = $ids;
        $args['orderby'] = 'post__in';
        $args['numberposts'] = count($ids);
    }

    $posts = get_posts($args);
    if (empty($ids) && tk_geo_itemlist_language_mode() === 'all' && !empty($posts)) {
        $fallback_ids = array();
        foreach ($posts as $post) {
            if (is_object($post) && !empty($post->ID)) {
                $fallback_ids[] = (int) $post->ID;
            }
        }
        $expanded_ids = tk_geo_expand_itemlist_post_ids($fallback_ids);
        if (!empty($expanded_ids)) {
            $posts = get_posts(array(
                'post_type' => $post_type,
                'post_status' => 'publish',
                'numberposts' => count($expanded_ids),
                'post__in' => $expanded_ids,
                'orderby' => 'post__in',
            ));
        }
    }
    if (empty($posts)) {
        return array();
    }

    $items = array();
    $position = 1;
    foreach ($posts as $post) {
        if (!is_object($post)) {
            continue;
        }
        $url = get_permalink($post);
        if (!is_string($url) || $url === '') {
            continue;
        }
        // ItemList and ListItem are Intangible; schema.org defines inLanguage only for CreativeWork, Event and a few others.
        $item = array(
            '@type' => 'ListItem',
            'position' => $position,
            'url' => $url,
            'name' => get_the_title($post) ?: ('#' . (int) $post->ID),
        );
        $items[] = $item;
        $position++;
    }
    if (empty($items)) {
        return array();
    }

    $name = trim(wp_strip_all_tags((string) tk_get_option('geo_itemlist_name', '')));
    if ($name === '') {
        $name = 'Selected ' . ($post_types[$post_type]->labels->name ?? 'Content');
    }
    $description = trim(wp_strip_all_tags((string) tk_get_option('geo_itemlist_description', '')));

    $schema = array(
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => $name,
        'itemListElement' => $items,
    );
    if ($description !== '') {
        $schema['description'] = $description;
    }
    return $schema;
}

// Compare page URLs without scheme case, query, fragment or trailing slash differences.
function tk_geo_schema_url_key(string $url): string {
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return rtrim((string) strtok($url, '#?'), '/');
    }
    return strtolower(($parts['scheme'] ?? 'https') . '://' . $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '') . rtrim((string) ($parts['path'] ?? ''), '/');
}

function tk_geo_schema_is_page_node(array $node): bool {
    $page_types = array('WebPage', 'AboutPage', 'CheckoutPage', 'CollectionPage', 'ContactPage', 'FAQPage', 'ItemPage', 'MedicalWebPage', 'ProfilePage', 'QAPage', 'RealEstateListing', 'SearchResultsPage');
    return (bool) array_intersect((array) ($node['@type'] ?? array()), $page_types);
}

// Top-level nodes of a decoded JSON-LD value: list items, @graph entries, or the object itself.
function tk_geo_schema_top_nodes(array $document): array {
    if ($document && array_keys($document) === range(0, count($document) - 1)) {
        $nodes = array();
        foreach ($document as $item) {
            if (is_array($item)) {
                $nodes = array_merge($nodes, tk_geo_schema_top_nodes($item));
            }
        }
        return $nodes;
    }
    if (isset($document['@graph']) && is_array($document['@graph'])) {
        $nodes = array_values(array_filter($document['@graph'], 'is_array'));
        $root = $document;
        unset($root['@graph'], $root['@context']);
        if (isset($root['@type'])) {
            $nodes[] = $root;
        }
        return $nodes;
    }
    unset($document['@context']);
    return array($document);
}

// Site-wide Custom JSON-LD is printed on every URL. A page node (WebPage and subtypes) whose @id or url
// names another URL describes that page, not the current one, so it is only kept on its own URL.
function tk_geo_schema_node_belongs_here(array $node, string $current_url): bool {
    if (!tk_geo_schema_is_page_node($node)) {
        return true;
    }
    $target = isset($node['@id']) && is_string($node['@id']) && preg_match('#^https?://#i', $node['@id']) ? $node['@id'] : (isset($node['url']) && is_string($node['url']) ? $node['url'] : '');
    return $target === '' || tk_geo_schema_url_key($target) === tk_geo_schema_url_key($current_url);
}

// Merge Custom JSON-LD into the generated SEO graph so each @id is defined once. Custom values win because
// they are the administrator's explicit data; generated properties the custom node lacks are kept.
// Name and description a page sets in Tool Kits SEO Content. They win over the site-wide Custom JSON-LD.
function tk_geo_page_node_overrides(): array {
    if (is_admin() || !is_singular() || !function_exists('tk_seo_expand_variables')) {
        return array();
    }
    $post_id = (int) get_queried_object_id();
    $overrides = array();
    foreach (array('name' => '_tk_seo_title', 'description' => '_tk_seo_meta_description') as $property => $key) {
        $value = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags(tk_seo_expand_variables((string) get_post_meta($post_id, $key, true), $post_id))));
        if ($value !== '') {
            $overrides[$property] = $value;
        }
    }
    return $overrides;
}

function tk_geo_schema_has_type(array $node, string $type): bool {
    return in_array($type, (array) ($node['@type'] ?? array()), true);
}

// Drop Custom JSON-LD nodes that Tool Kits generates for this page (its FAQ), and apply page overrides to the
// custom page node. Returns the custom document unchanged when nothing applies.
function tk_geo_custom_for_page(array $custom, bool $has_faq, array $overrides, string $current_url): array {
    $nodes = tk_geo_schema_top_nodes($custom);
    $changed = false;
    $kept = array();
    foreach ($nodes as $node) {
        if ($has_faq && tk_geo_schema_has_type($node, 'FAQPage')) {
            $changed = true;
            continue;
        }
        if ($overrides && tk_geo_schema_is_page_node($node) && tk_geo_schema_node_belongs_here($node, $current_url)) {
            foreach ($overrides as $property => $value) {
                if (array_key_exists($property, $node) && $node[$property] !== $value) {
                    $node[$property] = $value;
                    $changed = true;
                }
            }
        }
        $kept[] = $node;
    }
    if (!$changed) {
        return $custom;
    }
    return $kept ? array('@context' => 'https://schema.org', '@graph' => $kept) : array();
}

function tk_geo_merge_custom_into_graph(array $seo, array $custom, string $current_url): array {
    if (!isset($seo['@graph']) || !is_array($seo['@graph'])) {
        return $seo;
    }
    $index = array();
    $page = null;
    foreach ($seo['@graph'] as $position => $node) {
        if (is_array($node) && isset($node['@id']) && is_string($node['@id']) && $node['@id'] !== '') {
            $index[$node['@id']] = $position;
        }
        if ($page === null && is_array($node) && tk_geo_schema_is_page_node($node)) {
            $page = $position;
        }
    }
    foreach (tk_geo_schema_top_nodes($custom) as $node) {
        if (!tk_geo_schema_node_belongs_here($node, $current_url)) {
            continue;
        }
        $id = isset($node['@id']) && is_string($node['@id']) ? $node['@id'] : '';
        if ($id !== '' && isset($index[$id])) {
            $seo['@graph'][$index[$id]] = array_merge($seo['@graph'][$index[$id]], $node);
            continue;
        }
        // The current page's node under another @id spelling (query string, trailing slash): keep the generated
        // @id, which the breadcrumb and article nodes reference.
        if ($page !== null && tk_geo_schema_is_page_node($node)) {
            unset($node['@id']);
            $seo['@graph'][$page] = array_merge($seo['@graph'][$page], $node);
            continue;
        }
        $seo['@graph'][] = $node;
        if ($id !== '') {
            $index[$id] = count($seo['@graph']) - 1;
        }
    }
    return $seo;
}

// Without a generated graph, print Custom JSON-LD unchanged unless it carries another URL's page node.
function tk_geo_filter_custom_jsonld(array $custom, string $current_url): array {
    $nodes = tk_geo_schema_top_nodes($custom);
    $kept = array_values(array_filter($nodes, function ($node) use ($current_url) {
        return tk_geo_schema_node_belongs_here($node, $current_url);
    }));
    if (count($kept) === count($nodes)) {
        return $custom;
    }
    return $kept ? array('@context' => 'https://schema.org', '@graph' => $kept) : array();
}

// Opt-in: Tool Kits prints the JSON-LD for a theme that manages its own SEO, and the theme's blocks are removed.
function tk_geo_schema_overrides_theme(): bool {
    return (int) tk_get_option('geo_schema_override_theme', 0) === 1
        && function_exists('tk_seo_has_theme_managed_seo') && tk_seo_has_theme_managed_seo();
}

function tk_geo_seo_schema_allowed(): bool {
    if (function_exists('tk_seo_tools_enabled') && !tk_seo_tools_enabled()) {
        return false;
    }
    if (function_exists('tk_seo_post_feature_enabled') && !tk_seo_post_feature_enabled('seo')) {
        return false;
    }
    if (function_exists('tk_seo_has_third_party_plugin') && tk_seo_has_third_party_plugin()) {
        return false;
    }
    if (function_exists('tk_seo_has_theme_managed_seo') && tk_seo_has_theme_managed_seo() && !tk_geo_schema_overrides_theme()) {
        return false;
    }
    return (int) tk_get_option('seo_schema_enabled', 1) === 1 && function_exists('tk_seo_build_schema_graph');
}

// The theme prints its meta tags and JSON-LD from one wp_head callback (cst_seo). Swap it for a wrapper that keeps
// the meta tags and drops the JSON-LD, only when the Tool Kits graph replaces it on this request.
function tk_geo_maybe_replace_theme_schema(): void {
    if (is_admin() || !tk_geo_enabled() || !tk_geo_schema_overrides_theme() || !tk_geo_seo_schema_allowed()) {
        return;
    }
    $priority = has_action('wp_head', 'cst_seo');
    if ($priority === false) {
        return;
    }
    remove_action('wp_head', 'cst_seo', $priority);
    add_action('wp_head', 'tk_geo_render_theme_seo_without_schema', $priority);
}

function tk_geo_render_theme_seo_without_schema(): void {
    ob_start();
    cst_seo();
    $html = (string) ob_get_clean();
    echo preg_replace('#<script\b[^>]*\btype\s*=\s*["\']application/ld\+json["\'][^>]*>.*?</script>\s*#is', '', $html);
}

function tk_geo_build_schema_documents(): array {
    $docs = array();
    // Admin previews and checks describe the home page rather than the wp-admin URL.
    $current_url = is_admin() ? home_url('/') : (function_exists('tk_seo_current_url') ? tk_seo_current_url() : tk_geo_current_url());
    $seo_schema = array();
    if (tk_geo_seo_schema_allowed()) {
        $title = wp_get_document_title();
        $description = function_exists('tk_seo_generate_description') ? tk_seo_generate_description() : '';
        $seo_schema = (array) tk_seo_build_schema_graph($current_url, $title, $description);
    }
    $custom = tk_geo_decode_custom_jsonld((string) tk_get_option('geo_custom_jsonld', ''));
    $faq = tk_geo_build_faq_schema();
    if (is_array($custom)) {
        // The page's own SEO Content and the Tool Kits FAQ win over the site-wide Custom JSON-LD.
        $custom = tk_geo_custom_for_page($custom, !empty($faq), tk_geo_page_node_overrides(), $current_url);
    }
    if (!empty($seo_schema)) {
        $docs[] = is_array($custom) ? tk_geo_merge_custom_into_graph($seo_schema, $custom, $current_url) : $seo_schema;
    } elseif (is_array($custom)) {
        $custom = tk_geo_filter_custom_jsonld($custom, $current_url);
        if (!empty($custom)) {
            $docs[] = $custom;
        }
    }
    if (!empty($faq)) {
        $docs[] = $faq;
    }
    $itemlist = tk_geo_build_itemlist_schema();
    if (!empty($itemlist)) {
        $docs[] = $itemlist;
    }
    return $docs;
}

function tk_geo_llms_enabled(): bool {
    return tk_license_features_enabled() && (int) tk_get_option('geo_llms_enabled', 0) === 1;
}

function tk_geo_normalize_llms_sections($sections): array {
    $items = array();
    if (!is_array($sections)) {
        return $items;
    }
    foreach ($sections as $section) {
        if (!is_array($section)) {
            continue;
        }
        $title = trim(sanitize_text_field((string) ($section['title'] ?? '')));
        $content = trim(sanitize_textarea_field((string) ($section['content'] ?? '')));
        if ($title === '' && $content === '') {
            continue;
        }
        $items[] = array(
            'title' => $title !== '' ? $title : 'Notes',
            'content' => $content,
        );
        if (count($items) >= 20) {
            break;
        }
    }
    return $items;
}

function tk_geo_llms_markdown_link(string $label, string $url): string {
    $label = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($label)) ?? '');
    $label = str_replace(array('\\', '[', ']'), array('\\\\', '\\[', '\\]'), $label);
    $url = str_replace(array(' ', '(', ')', "\r", "\n"), array('%20', '%28', '%29', '', ''), $url);
    return '[' . ($label !== '' ? $label : 'Page') . '](' . $url . ')';
}

function tk_geo_build_llms_auto_text(): string {
    $lines = array();
    $lines[] = '# ' . get_bloginfo('name');
    $description = trim((string) get_bloginfo('description'));
    if ($description !== '') {
        $lines[] = '';
        $lines[] = '> ' . $description;
    }
    $lines[] = '';
    $lines[] = 'Site: ' . tk_geo_llms_markdown_link(get_bloginfo('name'), home_url('/'));
    $lines[] = 'Sitemap: ' . tk_geo_llms_markdown_link('XML Sitemap', tk_geo_sitemap_url());
    $lines[] = '';
    $lines[] = '## Important URLs';
    $urls = array_values(array_unique(array_merge(array(home_url('/')), tk_geo_review_urls(12))));
    foreach ($urls as $url) {
        $post_id = url_to_postid($url);
        $label = $post_id > 0 ? get_the_title($post_id) : ($url === home_url('/') ? 'Homepage' : $url);
        $lines[] = '- ' . tk_geo_llms_markdown_link($label, $url);
    }

    $sections = tk_geo_normalize_llms_sections(tk_get_option('geo_llms_sections', array()));
    foreach ($sections as $section) {
        $lines[] = '';
        $lines[] = '## ' . $section['title'];
        if ((string) $section['content'] !== '') {
            $lines[] = (string) $section['content'];
        }
    }

    if ((int) tk_get_option('geo_llms_include_itemlist', 1) === 1) {
        $itemlist = tk_geo_build_itemlist_schema();
        $items = isset($itemlist['itemListElement']) && is_array($itemlist['itemListElement']) ? $itemlist['itemListElement'] : array();
        if (!empty($items)) {
            $lines[] = '';
            $lines[] = '## Curated Content';
            foreach ($items as $item) {
                $name = isset($item['name']) ? trim((string) $item['name']) : '';
                $url = isset($item['url']) ? trim((string) $item['url']) : '';
                if ($url !== '') {
                    $lines[] = '- ' . tk_geo_llms_markdown_link($name !== '' ? $name : $url, $url);
                }
            }
        }
    }

    if ((int) tk_get_option('geo_llms_include_faq', 1) === 1) {
        $faqs = tk_geo_normalize_faq_items(tk_get_option('geo_faq_items', array()));
        if (!empty($faqs)) {
            $lines[] = '';
            $lines[] = '## FAQ';
            foreach ($faqs as $faq) {
                $lines[] = '';
                $lines[] = '### ' . $faq['question'];
                $lines[] = $faq['answer'];
            }
        }
    }

    return trim(implode("\n", $lines)) . "\n";
}

function tk_geo_build_llms_text(): string {
    $mode = sanitize_key((string) tk_get_option('geo_llms_mode', 'auto'));
    if (!in_array($mode, array('auto', 'manual', 'hybrid'), true)) {
        $mode = 'auto';
    }
    $manual = trim((string) tk_get_option('geo_llms_manual', ''));
    if ($mode === 'manual') {
        return $manual;
    }
    if ($mode === 'auto' && $manual !== '') {
        return $manual . "\n";
    }

    $auto = trim(tk_geo_build_llms_auto_text());
    if ($mode === 'hybrid' && $manual !== '') {
        $auto .= "\n\n## Notes\n" . $manual;
    }

    return trim($auto) . "\n";
}

function tk_geo_llms_maybe_render(): void {
    if (is_admin() || !tk_geo_llms_enabled()) {
        return;
    }
    $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    if (untrailingslashit($path) !== '/llms.txt') {
        return;
    }
    status_header(200);
    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    echo tk_geo_build_llms_text();
    exit;
}

function tk_geo_render_head_jsonld(): void {
    if (is_admin() || !tk_geo_enabled()) {
        return;
    }
    foreach (tk_geo_build_schema_documents() as $schema) {
        echo '<script type="application/ld+json"' . tk_csp_nonce_attr() . '>' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }
}

function tk_geo_ai_crawler_agents(): array {
    return array(
        'Googlebot' => array(
            'label' => 'Google Search',
            'purpose' => 'Google Search index; gates AI Overviews eligibility',
            'recommended' => 'allow',
            'note' => 'AI Overviews and AI Mode are grounded from the regular Google Search index, so Googlebot is the crawler that decides eligibility. There is no separate AI Overviews crawler.',
        ),
        'OAI-SearchBot' => array(
            'label' => 'OpenAI Search',
            'purpose' => 'ChatGPT search visibility',
            'recommended' => 'allow',
        ),
        'ChatGPT-User' => array(
            'label' => 'ChatGPT User Fetch',
            'purpose' => 'User-triggered retrieval',
            'recommended' => 'allow',
        ),
        'GPTBot' => array(
            'label' => 'OpenAI Training',
            'purpose' => 'OpenAI model training crawl',
            'recommended' => 'policy',
        ),
        'ClaudeBot' => array(
            'label' => 'Anthropic Training',
            'purpose' => 'Anthropic model development crawl',
            'recommended' => 'policy',
        ),
        'Claude-User' => array(
            'label' => 'Claude User Fetch',
            'purpose' => 'User-triggered retrieval',
            'recommended' => 'allow',
        ),
        'PerplexityBot' => array(
            'label' => 'Perplexity Search',
            'purpose' => 'Perplexity search indexing',
            'recommended' => 'allow',
        ),
        'Perplexity-User' => array(
            'label' => 'Perplexity User Fetch',
            'purpose' => 'User-triggered retrieval',
            'recommended' => 'allow',
        ),
        'Google-Extended' => array(
            'label' => 'Google Extended',
            'purpose' => 'Gemini and Vertex AI training/grounding control token',
            'recommended' => 'policy',
            'note' => 'Blocking Google-Extended does NOT remove the site from Google AI Overviews or AI Mode. It only limits Gemini and Vertex AI training and grounding. To opt out of AI Overviews, use Search Console > Settings > Search generative AI.',
        ),
        'CCBot' => array(
            'label' => 'Common Crawl',
            'purpose' => 'Common Crawl dataset indexing',
            'recommended' => 'policy',
        ),
        // Meta tokens and user agents: https://developers.facebook.com/docs/sharing/webmasters/web-crawlers/
        'Meta-WebIndexer' => array(
            'label' => 'Meta AI Search',
            'purpose' => 'Meta AI search results and citations in WhatsApp, Instagram, Facebook and Messenger',
            'recommended' => 'allow',
            'user_agent' => 'meta-webindexer/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)',
            'note' => 'Meta states that allowing this crawler helps it cite and link to your content in Meta AI responses. Meta AI web answers have also been reported to use Bing and Google results, so keep the site indexed there.',
        ),
        'Meta-ExternalFetcher' => array(
            'label' => 'Meta AI User Fetch',
            'purpose' => 'User-requested link retrieval for Meta AI',
            'recommended' => 'allow',
            'user_agent' => 'meta-externalfetcher/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)',
            'note' => 'Meta states this crawler may bypass robots.txt because each fetch is requested by a user. Firewall or CDN rules can still block it.',
        ),
        'facebookexternalhit' => array(
            'label' => 'Meta Link Preview',
            'purpose' => 'Link previews in WhatsApp, Facebook, Messenger and Instagram',
            'recommended' => 'allow',
            'user_agent' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
            'note' => 'Builds the title, description and image preview from Open Graph tags when a link is shared.',
        ),
        'Meta-ExternalAgent' => array(
            'label' => 'Meta AI Training',
            'purpose' => 'Meta foundation model training and direct product indexing',
            'recommended' => 'policy',
            'user_agent' => 'meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)',
            'note' => 'Blocking Meta-ExternalAgent does not block Meta-WebIndexer, which Meta uses for Meta AI search citations.',
        ),
    );
}

function tk_geo_fetch_url(string $url, string $user_agent = 'Tool Kits GEO AI Access Review', int $timeout = 8, bool $refresh = false) {
    $headers = array('User-Agent' => $user_agent);
    if ($refresh) {
        $headers['Cache-Control'] = 'no-cache, max-age=0';
        $headers['Pragma'] = 'no-cache';
    }
    return wp_remote_get($url, array(
        'timeout' => max(1, min(60, $timeout)),
        'redirection' => 3,
        'sslverify' => true,
        'headers' => $headers,
    ));
}

function tk_geo_robots_rules_for_agent(string $robots, string $agent): array {
    $groups = array();
    $current_agents = array();
    $current_rules = array();

    $flush = function() use (&$groups, &$current_agents, &$current_rules): void {
        if (!empty($current_agents)) {
            $groups[] = array(
                'agents' => $current_agents,
                'rules' => $current_rules,
            );
        }
        $current_agents = array();
        $current_rules = array();
    };

    foreach (preg_split('/\r\n|\r|\n/', $robots) ?: array() as $line) {
        $line = preg_replace('/\s*#.*$/', '', (string) $line);
        $line = trim((string) $line);
        if ($line === '' || strpos($line, ':') === false) {
            continue;
        }
        [$key, $value] = array_map('trim', explode(':', $line, 2));
        $key = strtolower($key);
        if ($key === 'user-agent') {
            if (!empty($current_rules)) {
                $flush();
            }
            $current_agents[] = $value;
            continue;
        }
        if (in_array($key, array('allow', 'disallow'), true) && !empty($current_agents)) {
            $current_rules[] = array(
                'type' => $key,
                'path' => $value,
            );
        }
    }
    $flush();

    $exact = array();
    $wildcard = array();
    foreach ($groups as $group) {
        foreach ($group['agents'] as $group_agent) {
            if (strcasecmp((string) $group_agent, $agent) === 0) {
                $exact = array_merge($exact, $group['rules']);
            } elseif ((string) $group_agent === '*') {
                $wildcard = array_merge($wildcard, $group['rules']);
            }
        }
    }

    return !empty($exact) ? $exact : $wildcard;
}

function tk_geo_robots_allows_path(string $robots, string $agent, string $path = '/'): array {
    $rules = tk_geo_robots_rules_for_agent($robots, $agent);
    if (empty($rules)) {
        return array(
            'allowed' => true,
            'matched' => 'No matching robots.txt rule',
        );
    }

    $best = null;
    foreach ($rules as $rule) {
        $rule_path = isset($rule['path']) ? (string) $rule['path'] : '';
        if ($rule_path === '') {
            continue;
        }
        if (strpos($path, $rule_path) !== 0) {
            continue;
        }
        if ($best === null || strlen($rule_path) > strlen((string) $best['path'])) {
            $best = $rule;
            continue;
        }
        if (strlen($rule_path) === strlen((string) $best['path']) && $rule['type'] === 'allow') {
            $best = $rule;
        }
    }

    if ($best === null) {
        return array(
            'allowed' => true,
            'matched' => 'No matching path directive',
        );
    }

    return array(
        'allowed' => (string) $best['type'] === 'allow',
        'matched' => ucfirst((string) $best['type']) . ': ' . (string) $best['path'],
    );
}

function tk_geo_find_meta_robots(string $html): array {
    $values = array();
    if (preg_match_all('/<meta\b[^>]*name=("|\')(robots|googlebot|bingbot)\1[^>]*>/i', $html, $matches)) {
        foreach ($matches[0] as $tag) {
            if (preg_match('/\bcontent=("|\')(.*?)\1/i', (string) $tag, $content)) {
                $values[] = strtolower((string) $content[2]);
            }
        }
    }
    return array_values(array_unique($values));
}

function tk_geo_extract_meta_content(string $html, string $name): string {
    $pattern = '/<meta\b[^>]*name=("|\')' . preg_quote($name, '/') . '\1[^>]*>/i';
    if (preg_match($pattern, $html, $match) && preg_match('/\bcontent=("|\')(.*?)\1/i', (string) $match[0], $content)) {
        return trim(html_entity_decode((string) $content[2]));
    }
    return '';
}

function tk_geo_extract_link_href(string $html, string $rel): string {
    $pattern = '/<link\b[^>]*rel=("|\')' . preg_quote($rel, '/') . '\1[^>]*>/i';
    if (preg_match($pattern, $html, $match) && preg_match('/\bhref=("|\')(.*?)\1/i', (string) $match[0], $href)) {
        return esc_url_raw((string) $href[2]);
    }
    return '';
}

function tk_geo_collect_schema_types_from_node($node, array &$types): void {
    if (!is_array($node)) {
        return;
    }
    if (isset($node['@type'])) {
        $node_types = is_array($node['@type']) ? $node['@type'] : array($node['@type']);
        foreach ($node_types as $type) {
            if (is_string($type) && $type !== '') {
                $types[] = $type;
            }
        }
    }
    foreach ($node as $value) {
        if (is_array($value)) {
            tk_geo_collect_schema_types_from_node($value, $types);
        }
    }
}

function tk_geo_collect_top_level_schema_types($node, array &$types): void {
    if (!is_array($node)) {
        return;
    }
    $nodes = array();
    if (isset($node['@graph']) && is_array($node['@graph'])) {
        $nodes = $node['@graph'];
    } elseif (isset($node[0]) && is_array($node[0])) {
        $nodes = $node;
    } else {
        $nodes = array($node);
    }

    foreach ($nodes as $item) {
        if (!is_array($item) || !isset($item['@type'])) {
            continue;
        }
        $node_types = is_array($item['@type']) ? $item['@type'] : array($item['@type']);
        foreach ($node_types as $type) {
            if (is_string($type) && $type !== '') {
                $types[] = $type;
            }
        }
    }
}

function tk_geo_extract_jsonld_report(string $html, bool $recursive = false): array {
    $types = array();
    $documents = 0;
    $invalid = 0;
    $values = array();
    if (preg_match_all('/<script\b[^>]*type=("|\')application\/ld\+json\1[^>]*>(.*?)<\/script>/is', $html, $matches)) {
        foreach ($matches[2] as $json) {
            $documents++;
            $decoded = json_decode(trim(html_entity_decode((string) $json)), true);
            if (!is_array($decoded)) {
                $invalid++;
                continue;
            }
            $values[] = $decoded;
            if ($recursive) {
                tk_geo_collect_schema_types_from_node($decoded, $types);
            } else {
                tk_geo_collect_top_level_schema_types($decoded, $types);
            }
        }
    }
    $counts = array_count_values($types);
    $analysis = $values ? tk_geo_schema_validate_json((string) json_encode($values)) : array();
    return array(
        'analysis_version' => 2,
        'documents' => $documents,
        'invalid' => $invalid,
        'types' => $counts,
        'repeated_types' => array_filter($counts, function($count) { return (int) $count > 1; }),
        'duplicates' => $analysis['duplicate_types'] ?? array(),
        'duplicate_ids' => $analysis['duplicate_ids'] ?? array(),
    );
}

function tk_geo_render_schema_type_badges($types, bool $highlight_duplicates = false): string {
    if (!is_array($types) || empty($types)) {
        return '<span class="tk-badge">None</span>';
    }

    $normalized = array();
    foreach ($types as $key => $value) {
        if (is_string($key) && !is_numeric($key)) {
            $name = trim($key);
            $count = max(1, (int) $value);
        } else {
            $name = trim((string) $value);
            $count = 1;
        }
        if ($name === '') {
            continue;
        }
        $normalized[$name] = ($normalized[$name] ?? 0) + $count;
    }

    if (empty($normalized)) {
        return '<span class="tk-badge">None</span>';
    }

    ksort($normalized, SORT_NATURAL | SORT_FLAG_CASE);
    $html = '<span class="tk-schema-type-list">';
    foreach ($normalized as $name => $count) {
        $class = $highlight_duplicates && $count > 1 ? ' tk-warn' : ' tk-on';
        $html .= '<span class="tk-badge' . esc_attr($class) . '">' . esc_html($name);
        if ($count > 1) {
            $html .= ' x' . esc_html((string) $count);
        }
        $html .= '</span> ';
    }
    $html .= '</span>';

    return $html;
}

function tk_geo_sitemap_url(): string {
    $custom_path = function_exists('tk_seo_normalize_path') ? tk_seo_normalize_path((string) tk_get_option('seo_sitemap_path', 'sitemap.xml')) : '/sitemap.xml';
    if ((int) tk_get_option('seo_sitemap_enabled', 1) === 1 && $custom_path !== '') {
        return home_url($custom_path);
    }
    if (function_exists('wp_sitemaps_get_server')) {
        $server = wp_sitemaps_get_server();
        if (is_object($server) && method_exists($server, 'get_index_url')) {
            $url = (string) $server->get_index_url();
            if ($url !== '') {
                return $url;
            }
        }
    }
    return home_url('/sitemap.xml');
}

function tk_geo_run_ai_access_review(): array {
    $saved = tk_get_option('geo_ai_access_report', array());
    if (is_array($saved) && !empty($saved)) { return $saved; }
    $home_url = home_url('/');
    $robots_url = home_url('/robots.txt');
    $llms_url = home_url('/llms.txt');
    $sitemap_url = tk_geo_sitemap_url();
    $issues = array();
    $checks = array();
    $agents = tk_geo_ai_crawler_agents();

    $home = tk_geo_fetch_url($home_url);
    $home_status = is_wp_error($home) ? 0 : (int) wp_remote_retrieve_response_code($home);
    $home_body = is_wp_error($home) ? '' : (string) wp_remote_retrieve_body($home);
    $home_headers = is_wp_error($home) ? array() : wp_remote_retrieve_headers($home);
    $home_ok = $home_status >= 200 && $home_status < 400;
    $checks[] = array(
        'name' => 'Homepage fetch',
        'status' => $home_ok ? 'ok' : 'fail',
        'detail' => $home_ok ? 'Homepage returns HTTP ' . $home_status . '.' : 'Homepage fetch failed or returned HTTP ' . $home_status . '.',
    );
    if (!$home_ok) {
        $issues[] = 'Homepage is not reliably fetchable.';
    }

    $robots = tk_geo_fetch_url($robots_url);
    $robots_status = is_wp_error($robots) ? 0 : (int) wp_remote_retrieve_response_code($robots);
    $robots_body = is_wp_error($robots) ? '' : (string) wp_remote_retrieve_body($robots);
    $robots_ok = $robots_status >= 200 && $robots_status < 400 && trim($robots_body) !== '';
    $checks[] = array(
        'name' => 'robots.txt',
        'status' => $robots_ok ? 'ok' : 'warn',
        'detail' => $robots_ok ? 'robots.txt is reachable.' : 'robots.txt is missing, empty, or not reachable.',
    );
    if (!$robots_ok) {
        $issues[] = 'robots.txt could not be reviewed.';
    }

    $firewall_blocked = function_exists('tk_firewall_blocked_ai_crawlers') && (int) tk_get_option('hardening_waf_enabled', 0)
        ? tk_firewall_blocked_ai_crawlers() : array();
    $checks[] = array(
        'name' => 'Tool Kits firewall',
        'status' => $firewall_blocked ? 'fail' : 'ok',
        'detail' => $firewall_blocked
            ? 'Blocked user-agent rules return 403 to: ' . implode(', ', array_keys($firewall_blocked)) . '.'
            : 'No Tool Kits firewall user-agent rule blocks recommended AI crawlers.',
    );
    foreach ($firewall_blocked as $agent => $match) {
        $issues[] = $agent . ' is blocked by the Tool Kits firewall rule "' . $match['fragment'] . '".';
    }

    $robots_results = array();
    $crawler_fetch_results = array();
    if ($robots_ok) {
        foreach ($agents as $agent => $info) {
            $result = tk_geo_robots_allows_path($robots_body, (string) $agent, '/');
            $recommended = (string) ($info['recommended'] ?? 'policy');
            $allowed = !empty($result['allowed']);
            $status = $allowed ? 'ok' : ($recommended === 'policy' ? 'warn' : 'fail');
            $robots_results[] = array(
                'agent' => (string) $agent,
                'label' => (string) ($info['label'] ?? $agent),
                'purpose' => (string) ($info['purpose'] ?? ''),
                'note' => (string) ($info['note'] ?? ''),
                'recommended' => $recommended,
                'allowed' => $allowed,
                'status' => $status,
                'matched' => (string) ($result['matched'] ?? ''),
            );
            if (!$allowed && $recommended === 'allow') {
                $issues[] = (string) $agent . ' is blocked in robots.txt.';
            }
        }
    }
    $home_schema = tk_geo_extract_jsonld_report($home_body, true);
    foreach ($agents as $agent => $info) {
        $robots_result = null;
        foreach ($robots_results as $candidate) {
            if (($candidate['agent'] ?? '') === $agent) { $robots_result = $candidate; break; }
        }
        $allowed = is_array($robots_result) ? !empty($robots_result['allowed']) : null;
        $eligibility = !$home_ok ? 'unavailable' : (isset($firewall_blocked[$agent]) ? 'blocked' : ($allowed === null ? 'unknown' : ($allowed ? 'eligible' : 'blocked')));
        $crawler_fetch_results[] = array(
            'agent' => (string) $agent,
            'label' => (string) ($info['label'] ?? $agent),
            'status' => $home_status,
            'ok' => $eligibility === 'eligible',
            'eligibility' => $eligibility,
            'body_bytes' => strlen($home_body),
            'schema_documents' => (int) ($home_schema['documents'] ?? 0),
            'error' => is_wp_error($home) ? $home->get_error_message() : '',
        );
    }

    $x_robots = '';
    if (is_object($home_headers) && method_exists($home_headers, 'offsetGet')) {
        $x_robots = (string) $home_headers->offsetGet('x-robots-tag');
    } elseif (is_array($home_headers) && isset($home_headers['x-robots-tag'])) {
        $x_robots = (string) $home_headers['x-robots-tag'];
    }
    if ($x_robots !== '' && preg_match('/\b(noindex|none|nofollow)\b/i', $x_robots)) {
        $issues[] = 'Homepage sends X-Robots-Tag that limits indexing.';
        $checks[] = array('name' => 'X-Robots-Tag', 'status' => 'fail', 'detail' => $x_robots);
    } else {
        $checks[] = array('name' => 'X-Robots-Tag', 'status' => 'ok', 'detail' => $x_robots !== '' ? $x_robots : 'No blocking X-Robots-Tag detected.');
    }

    $meta_robots = tk_geo_find_meta_robots($home_body);
    $blocking_meta = array_filter($meta_robots, function($value) {
        return preg_match('/\b(noindex|none|nofollow)\b/i', (string) $value);
    });
    if (!empty($blocking_meta)) {
        $issues[] = 'Homepage has blocking meta robots directives.';
        $checks[] = array('name' => 'Meta robots', 'status' => 'fail', 'detail' => implode(', ', $blocking_meta));
    } else {
        $checks[] = array('name' => 'Meta robots', 'status' => 'ok', 'detail' => !empty($meta_robots) ? implode(', ', $meta_robots) : 'No blocking meta robots detected.');
    }

    $sitemap = tk_geo_fetch_url($sitemap_url);
    $sitemap_status = is_wp_error($sitemap) ? 0 : (int) wp_remote_retrieve_response_code($sitemap);
    $sitemap_ok = $sitemap_status >= 200 && $sitemap_status < 400;
    $checks[] = array(
        'name' => 'Sitemap',
        'status' => $sitemap_ok ? 'ok' : 'warn',
        'detail' => $sitemap_ok ? 'Sitemap is reachable: ' . $sitemap_url : 'Sitemap not reachable: ' . $sitemap_url,
    );
    if (!$sitemap_ok) {
        $issues[] = 'Sitemap is not reachable.';
    }

    $llms = tk_geo_fetch_url($llms_url);
    $llms_status = is_wp_error($llms) ? 0 : (int) wp_remote_retrieve_response_code($llms);
    $llms_ok = $llms_status >= 200 && $llms_status < 400 && trim((string) wp_remote_retrieve_body($llms)) !== '';
    $checks[] = array(
        'name' => 'llms.txt',
        'status' => $llms_ok ? 'ok' : 'warn',
        'detail' => $llms_ok ? 'llms.txt is available.' : 'llms.txt is not available. Optional, but useful for LLM-oriented documentation.',
    );

    $schema_docs = tk_geo_build_schema_documents();
    $checks[] = array(
        'name' => 'Structured data',
        'status' => !empty($schema_docs) ? 'ok' : 'warn',
        'detail' => !empty($schema_docs) ? count($schema_docs) . ' GEO JSON-LD document(s) configured.' : 'No GEO JSON-LD documents configured.',
    );
    if (empty($schema_docs)) {
        $issues[] = 'No GEO structured data is configured.';
    }

    if (trim((string) get_option('blog_public')) !== '1') {
        $issues[] = 'WordPress search engine visibility is disabled.';
        $checks[] = array(
            'name' => 'Search engine visibility',
            'status' => 'fail',
            'detail' => 'WordPress is configured to discourage search engines.',
        );
    } else {
        $checks[] = array(
            'name' => 'Search engine visibility',
            'status' => 'ok',
            'detail' => 'WordPress search engine visibility is enabled.',
        );
    }

    $score = 100;
    foreach ($checks as $check) {
        if (($check['status'] ?? '') === 'fail') {
            $score -= 18;
        } elseif (($check['status'] ?? '') === 'warn') {
            $score -= 7;
        }
    }
    foreach ($robots_results as $result) {
        if (($result['status'] ?? '') === 'fail') {
            $score -= 10;
        } elseif (($result['status'] ?? '') === 'warn') {
            $score -= 3;
        }
    }
    $score = max(0, min(100, $score));

    return array(
        'scanned_at' => time(),
        'score' => $score,
        'home_url' => $home_url,
        'robots_url' => $robots_url,
        'sitemap_url' => $sitemap_url,
        'llms_url' => $llms_url,
        'checks' => $checks,
        'robots_results' => $robots_results,
        'crawler_fetch_results' => $crawler_fetch_results,
        'issues' => array_values(array_unique($issues)),
    );
}

function tk_geo_review_urls(int $limit = 8): array {
    $urls = array(home_url('/'));
    $ids = get_posts(array(
        'post_type' => array_values(array_keys(tk_geo_public_post_types())),
        'post_status' => 'publish',
        'numberposts' => max(1, $limit),
        'fields' => 'ids',
        'orderby' => 'modified',
        'order' => 'DESC',
    ));
    foreach ($ids as $post_id) {
        $url = get_permalink((int) $post_id);
        if (is_string($url) && $url !== '') {
            $urls[] = $url;
        }
    }
    return array_slice(array_values(array_unique($urls)), 0, max(1, $limit + 1));
}

function tk_geo_normalize_site_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return home_url('/');
    }
    if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
        $url = home_url($url);
    }
    $url = esc_url_raw($url);
    $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    $url_host = (string) wp_parse_url($url, PHP_URL_HOST);
    if ($url === '' || ($home_host !== '' && $url_host !== '' && strcasecmp($home_host, $url_host) !== 0)) {
        return home_url('/');
    }
    return $url;
}

function tk_geo_extract_page_snapshot(string $html): array {
    $title = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match)) {
        $title = trim(wp_strip_all_tags(html_entity_decode((string) $match[1])));
    }

    $h1s = array();
    if (preg_match_all('/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $matches)) {
        foreach ($matches[1] as $heading) {
            $heading = trim(wp_strip_all_tags(html_entity_decode((string) $heading)));
            if ($heading !== '') {
                $h1s[] = $heading;
            }
        }
    }

    $body = preg_replace('/<(script|style|noscript|svg)\b[^>]*>.*?<\/\1>/is', ' ', $html);
    $body = preg_replace('/<head\b[^>]*>.*?<\/head>/is', ' ', is_string($body) ? $body : $html);
    $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(html_entity_decode(is_string($body) ? $body : $html))));
    if (strlen($text) > 900) {
        $text = substr($text, 0, 900) . '...';
    }

    return array(
        'title' => $title,
        'description' => tk_geo_extract_meta_content($html, 'description'),
        'author' => tk_geo_extract_meta_content($html, 'author'),
        'publisher' => tk_geo_extract_meta_content($html, 'publisher'),
        'canonical' => tk_geo_extract_link_href($html, 'canonical'),
        'h1s' => array_slice(array_values(array_unique($h1s)), 0, 5),
        'text_sample' => $text,
    );
}

function tk_geo_run_visibility_scan(string $url): array {
    $url = tk_geo_normalize_site_url($url);
    $saved = tk_get_option('geo_visibility_report', array());
    if (is_array($saved) && ($saved['url'] ?? '') === $url) { return $saved; }
    $response = tk_geo_fetch_url($url, 'Tool Kits GEO AI Visibility');
    $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    $html = is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response);
    $headers = is_wp_error($response) ? array() : wp_remote_retrieve_headers($response);
    $snapshot = tk_geo_extract_page_snapshot($html);
    $schema = tk_geo_extract_jsonld_report($html, true);
    $checks = array();
    $issues = array();

    $ok_fetch = $status >= 200 && $status < 400 && trim($html) !== '';
    $checks[] = array('name' => 'Fetchable URL', 'status' => $ok_fetch ? 'ok' : 'fail', 'detail' => $ok_fetch ? 'HTTP ' . $status : 'Fetch failed or empty response.');
    if (!$ok_fetch) {
        $issues[] = 'URL is not fetchable by a standard crawler.';
    }

    foreach (array(
        'Title' => $snapshot['title'] !== '',
        'Meta description' => $snapshot['description'] !== '',
        'Author' => $snapshot['author'] !== '',
        'Publisher' => $snapshot['publisher'] !== '',
        'Canonical' => $snapshot['canonical'] !== '',
        'H1' => !empty($snapshot['h1s']),
        'JSON-LD' => (int) ($schema['documents'] ?? 0) > 0 && (int) ($schema['invalid'] ?? 0) === 0,
    ) as $label => $passed) {
        $checks[] = array(
            'name' => $label,
            'status' => $passed ? 'ok' : 'warn',
            'detail' => $passed ? 'Detected.' : 'Missing or incomplete.',
        );
        if (!$passed) {
            $issues[] = $label . ' is missing or incomplete.';
        }
    }

    $meta_robots = tk_geo_find_meta_robots($html);
    $blocking_meta = array_filter($meta_robots, function($value) {
        return preg_match('/\b(noindex|none|nofollow)\b/i', (string) $value);
    });
    $checks[] = array(
        'name' => 'Meta robots',
        'status' => empty($blocking_meta) ? 'ok' : 'fail',
        'detail' => empty($meta_robots) ? 'No blocking robots meta detected.' : implode(', ', $meta_robots),
    );
    if (!empty($blocking_meta)) {
        $issues[] = 'Meta robots blocks indexing or following.';
    }

    $x_robots = '';
    if (is_object($headers) && method_exists($headers, 'offsetGet')) {
        $x_robots = (string) $headers->offsetGet('x-robots-tag');
    } elseif (is_array($headers) && isset($headers['x-robots-tag'])) {
        $x_robots = (string) $headers['x-robots-tag'];
    }
    $x_blocked = $x_robots !== '' && preg_match('/\b(noindex|none|nofollow)\b/i', $x_robots);
    $checks[] = array(
        'name' => 'X-Robots-Tag',
        'status' => $x_blocked ? 'fail' : 'ok',
        'detail' => $x_robots !== '' ? $x_robots : 'No blocking X-Robots-Tag detected.',
    );
    if ($x_blocked) {
        $issues[] = 'X-Robots-Tag blocks indexing or following.';
    }

    $robots_body = '';
    $robots = tk_geo_fetch_url(home_url('/robots.txt'), 'Tool Kits GEO AI Visibility');
    if (!is_wp_error($robots) && (int) wp_remote_retrieve_response_code($robots) >= 200 && (int) wp_remote_retrieve_response_code($robots) < 400) {
        $robots_body = (string) wp_remote_retrieve_body($robots);
    }

    $agent_rows = array();
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    if ($path === '') {
        $path = '/';
    }
    foreach (tk_geo_ai_crawler_agents() as $agent => $info) {
        $allowed = true;
        $matched = 'robots.txt not available';
        if ($robots_body !== '') {
            $rule = tk_geo_robots_allows_path($robots_body, (string) $agent, $path);
            $allowed = !empty($rule['allowed']);
            $matched = (string) ($rule['matched'] ?? '');
        }
        // Do not spoof crawler identities from the site server. CDNs commonly
        // validate crawler IP ranges and reject such probes even when the real
        // crawler is allowed. Eligibility is based on robots.txt plus the normal
        // page fetch already performed above.
        $visible = $allowed && $ok_fetch;
        $agent_rows[] = array(
            'agent' => (string) $agent,
            'label' => (string) ($info['label'] ?? $agent),
            'allowed' => $allowed,
            'matched' => $matched,
            'status' => $status,
            'visible' => $visible,
            'fetch_ok' => $ok_fetch,
            'verification' => !$allowed ? 'blocked' : ($ok_fetch ? 'eligible' : 'unavailable'),
            'body_bytes' => strlen($html),
            'error' => is_wp_error($response) ? $response->get_error_message() : '',
        );
        if (!$allowed) {
            $issues[] = (string) $agent . ' is blocked by robots.txt for this URL.';
        }
    }

    $score = 100;
    foreach ($checks as $check) {
        if (($check['status'] ?? '') === 'fail') {
            $score -= 18;
        } elseif (($check['status'] ?? '') === 'warn') {
            $score -= 7;
        }
    }
    foreach ($agent_rows as $row) {
        if (empty($row['allowed'])) {
            $score -= 4;
        }
    }
    $score = max(0, min(100, $score));

    return array(
        'scanned_at' => time(),
        'url' => $url,
        'score' => $score,
        'status' => $status,
        'snapshot' => $snapshot,
        'schema' => $schema,
        'checks' => $checks,
        'agents' => $agent_rows,
        'issues' => array_values(array_unique($issues)),
    );
}

function tk_geo_build_prompt_preview(string $url): array {
    $url = tk_geo_normalize_site_url($url);
    $response = tk_geo_fetch_url($url, 'Tool Kits GEO Prompt Preview', 20);
    $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    $html = is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response);
    $error = is_wp_error($response) ? $response->get_error_message() : '';
    $snapshot = tk_geo_extract_page_snapshot($html);
    $schema = tk_geo_extract_jsonld_report($html, true);
    $types = isset($schema['types']) && is_array($schema['types']) ? array_keys($schema['types']) : array();
    $heading = !empty($snapshot['h1s']) ? (string) $snapshot['h1s'][0] : (string) $snapshot['title'];
    $summary_parts = array_values(array_filter(array($heading, (string) $snapshot['description']), function($part) {
        return trim((string) $part) !== '';
    }));
    $summary = implode('. ', array_map('trim', $summary_parts));
    if ($error !== '') {
        $summary = 'AI preview could not fetch this page: ' . $error;
    } elseif ($status < 200 || $status >= 400) {
        $summary = 'AI preview could not analyze this page because it returned HTTP ' . $status . '.';
    } elseif (trim($html) === '') {
        $summary = 'AI preview could not analyze this page because the response body was empty.';
    } elseif ($summary === '') {
        $summary = 'AI preview could not generate a confident summary because no usable title, description, or H1 was found.';
    }

    return array(
        'scanned_at' => time(),
        'url' => $url,
        'status' => $status,
        'title' => (string) $snapshot['title'],
        'description' => (string) $snapshot['description'],
        'h1s' => isset($snapshot['h1s']) && is_array($snapshot['h1s']) ? $snapshot['h1s'] : array(),
        'schema_types' => $types,
        'summary' => $summary,
        'prompt' => 'How AI might summarize this page',
        'context_sample' => (string) $snapshot['text_sample'],
        'error' => $error,
    );
}

function tk_geo_clear_saved_results(): void {
    tk_require_admin_post('tk_geo_clear_saved_results');
    foreach (array('seo_geo_audit_report', 'geo_ai_access_report', 'geo_visibility_report', 'geo_ai_radar_report', 'geo_prompt_preview_report', 'geo_schema_duplicate_report', 'geo_schema_duplicate_fix_report', 'geo_crawler_preview') as $key) {
        tk_update_option($key, array());
    }
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-geo-audit') . '#geo-audit');
    exit;
}

function tk_geo_visibility_scan_handler(): void {
    tk_require_admin_post('tk_geo_visibility_scan');
    $url = tk_geo_normalize_site_url((string) tk_post('geo_visibility_url', home_url('/')));
    if (tk_post('geo_visibility_refresh', 0)) {
        tk_update_option('geo_visibility_report', array());
    }
    tk_update_option('geo_visibility_report', tk_geo_run_visibility_scan($url));
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo-audit', 'tk_geo_visibility' => 1), admin_url('admin.php')) . '#ai-visibility');
    exit;
}

function tk_geo_prompt_preview_handler(): void {
    tk_require_admin_post('tk_geo_prompt_preview');
    $url = tk_geo_normalize_site_url((string) tk_post('geo_prompt_url', home_url('/')));
    tk_update_option('geo_prompt_preview_report', tk_geo_build_prompt_preview($url));
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo-audit', 'tk_geo_prompt_preview' => 1), admin_url('admin.php')) . '#prompt-preview');
    exit;
}

function tk_geo_run_schema_duplicate_detector(bool $force_refresh = false): array {
    $saved = tk_get_option('geo_schema_duplicate_report', array());
    if (!$force_refresh && is_array($saved) && !empty($saved) && (int) ($saved['analysis_version'] ?? 0) === 2) { return $saved; }
    $items = array();
    $issue_count = 0;
    foreach (tk_geo_review_urls(8) as $url) {
        $response = tk_geo_fetch_url($url, 'Tool Kits GEO Schema Duplicate Detector');
        if (is_wp_error($response)) {
            $items[] = array(
                'url' => $url,
                'status' => 0,
                'documents' => 0,
                'invalid' => 0,
                'types' => array(),
                'duplicates' => array(),
                'issue' => $response->get_error_message(),
            );
            $issue_count++;
            continue;
        }
        $html = (string) wp_remote_retrieve_body($response);
        $report = tk_geo_extract_jsonld_report($html);
        $duplicates = isset($report['duplicates']) && is_array($report['duplicates']) ? $report['duplicates'] : array();
        $duplicate_types = array_values(array_filter(array_keys($duplicates), 'is_string'));
        $invalid = (int) ($report['invalid'] ?? 0);
        if (!empty($duplicates) || $invalid > 0) {
            $issue_count++;
        }
        $items[] = array(
            'url' => $url,
            'status' => (int) wp_remote_retrieve_response_code($response),
            'documents' => (int) ($report['documents'] ?? 0),
            'invalid' => $invalid,
            'types' => isset($report['types']) && is_array($report['types']) ? $report['types'] : array(),
            'duplicates' => $duplicates,
            'issue' => !empty($duplicate_types) ? 'Potential duplicate entity definitions: ' . implode(', ', $duplicate_types) . '. Review repeated identities or identical top-level nodes.' : ($invalid > 0 ? 'Invalid JSON-LD detected.' : ''),
        );
    }

    return array(
        'analysis_version' => 2,
        'scanned_at' => time(),
        'checked_urls' => count($items),
        'issue_count' => $issue_count,
        'items' => $items,
    );
}

function tk_geo_mark_remaining_schema_duplicates(array $report): array {
    $items = isset($report['items']) && is_array($report['items']) ? $report['items'] : array();
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }
        $duplicates = isset($item['duplicates']) && is_array($item['duplicates']) ? $item['duplicates'] : array();
        $duplicate_types = array_values(array_filter(array_keys($duplicates), 'is_string'));
        if (!empty($duplicate_types)) {
            $items[$index]['issue'] = 'Potential duplicate entities remain outside Tool Kits automatic SEO/Breadcrumb fix: ' . implode(', ', $duplicate_types) . '. Review source definitions.';
        }
    }
    $report['items'] = $items;
    return $report;
}

function tk_geo_schema_duplicate_types_from_report(array $report): array {
    $types = array();
    $items = isset($report['items']) && is_array($report['items']) ? $report['items'] : array();
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $duplicates = isset($item['duplicates']) && is_array($item['duplicates']) ? $item['duplicates'] : array();
        foreach ($duplicates as $type => $count) {
            if (is_string($type) && $type !== '' && (int) $count > 1) {
                $types[] = $type;
            }
        }
    }
    return array_values(array_unique($types));
}

function tk_geo_toolkits_seo_schema_types(): array {
    return array(
        'Article',
        'BlogPosting',
        'BreadcrumbList',
        'ContactPoint',
        'Country',
        'CreativeWork',
        'ImageObject',
        'NewsArticle',
        'Organization',
        'Person',
        'PostalAddress',
        'Product',
        'Service',
        'WebPage',
        'WebSite',
    );
}

function tk_geo_schema_duplicate_fix(): void {
    tk_require_admin_post('tk_geo_schema_duplicate_fix');

    $report = tk_get_option('geo_schema_duplicate_report', array());
    $report = is_array($report) ? $report : array();
    if (empty($report) || (int) ($report['analysis_version'] ?? 0) !== 2) {
        $report = tk_geo_run_schema_duplicate_detector();
    }

    $duplicate_types = tk_geo_schema_duplicate_types_from_report($report);
    $seo_conflicts = array_values(array_intersect($duplicate_types, tk_geo_toolkits_seo_schema_types()));
    $actions = array();
    $remaining = array();

    if ((int) tk_get_option('seo_breadcrumb_enabled', 1) === 1 && in_array('BreadcrumbList', $seo_conflicts, true)) {
        tk_update_option('seo_breadcrumb_enabled', 0);
        $seo_conflicts = array_values(array_diff($seo_conflicts, array('BreadcrumbList')));
        $actions[] = 'Disabled Tool Kits BreadcrumbList schema because another source already provides it.';
    }

    if ((int) tk_get_option('seo_schema_enabled', 1) === 1 && !empty($seo_conflicts)) {
        tk_update_option('seo_schema_enabled', 0);
        $actions[] = 'Disabled Tool Kits SEO JSON-LD Schema for duplicate types: ' . implode(', ', $seo_conflicts) . '.';
    }

    if (!empty($actions) && function_exists('tk_clear_all_caches')) {
        $cache_result = tk_clear_all_caches();
        if (!empty($cache_result['message'])) {
            $actions[] = 'Cleared caches before verification. ' . (string) $cache_result['message'];
        }
    }

    $new_report = tk_geo_mark_remaining_schema_duplicates(tk_geo_run_schema_duplicate_detector(true));
    $new_duplicate_types = tk_geo_schema_duplicate_types_from_report($new_report);
    if (!empty($new_duplicate_types)) {
        $remaining[] = 'Remaining repeated entity definitions may come from the active theme, custom JSON-LD, or another SEO/schema plugin: ' . implode(', ', $new_duplicate_types) . '. Compare properties before removing anything.';
    }

    tk_update_option('geo_schema_duplicate_report', $new_report);
    tk_update_option('geo_schema_duplicate_fix_report', array(
        'fixed_at' => time(),
        'actions' => $actions,
        'remaining' => $remaining,
    ));

    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo-audit', 'tk_geo_schema_fixed' => 1), admin_url('admin.php')) . '#schema-duplicates');
    exit;
}

function tk_geo_schema_duplicate_scan(): void {
    tk_require_admin_post('tk_geo_schema_duplicate_scan');
    tk_update_option('geo_schema_duplicate_report', tk_geo_run_schema_duplicate_detector(true));
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo-audit', 'tk_geo_schema_duplicates' => 1), admin_url('admin.php')) . '#schema-duplicates');
    exit;
}

function tk_geo_schema_duplicate_clear(): void {
    tk_require_admin_post('tk_geo_schema_duplicate_clear');
    tk_update_option('geo_schema_duplicate_report', array());
    tk_update_option('geo_schema_duplicate_fix_report', array());
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo-audit'), admin_url('admin.php')) . '#schema-duplicates');
    exit;
}

function tk_geo_crawler_preview_handler(): void {
    tk_require_admin_post('tk_geo_crawler_preview');
    $url = esc_url_raw((string) tk_post('crawler_preview_url', home_url('/')));
    $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    $url_host = (string) wp_parse_url($url, PHP_URL_HOST);
    if ($url === '' || ($home_host !== '' && $url_host !== '' && strcasecmp($home_host, $url_host) !== 0)) {
        $url = home_url('/');
    }
    // Both submit buttons request a scan. Saved results are only reused when
    // rendering the admin page, never in response to an explicit scan action.
    tk_update_option('geo_crawler_preview', tk_geo_run_crawler_preview($url));
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo-audit', 'tk_geo_crawler_preview' => 1), admin_url('admin.php')) . '#crawler-preview');
    exit;
}

function tk_geo_run_crawler_preview(string $url): array {
    $agents = tk_geo_ai_crawler_agents();
    $results = array();
    $first_visible = array();
    foreach ($agents as $agent => $info) {
        $response = tk_geo_fetch_url($url, (string) ($info['user_agent'] ?? $agent), 8, true);
        $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $html = is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response);
        $schema = tk_geo_extract_jsonld_report($html, true);
        $metadata = tk_geo_crawler_metadata($html);
        $snapshot = tk_geo_extract_page_snapshot($html);
        $ok = $status >= 200 && $status < 400 && trim($html) !== '';
        $row = array(
            'agent' => (string) $agent,
            'label' => (string) ($info['label'] ?? $agent),
            'purpose' => (string) ($info['purpose'] ?? ''),
            'status' => $status,
            'ok' => $ok,
            'title' => $metadata['title'],
            'description' => $metadata['description'],
            'author' => $metadata['author'],
            'publisher' => $metadata['publisher'],
            'robots' => implode(', ', tk_geo_find_meta_robots($html)),
            'canonical' => $metadata['canonical'],
            'text_sample' => (string) ($snapshot['text_sample'] ?? ''),
            'schema_documents' => (int) ($schema['documents'] ?? 0),
            'schema_invalid' => (int) ($schema['invalid'] ?? 0),
            'schema_types' => isset($schema['types']) && is_array($schema['types']) ? $schema['types'] : array(),
            'body_bytes' => strlen($html),
            'error' => is_wp_error($response) ? $response->get_error_message() : '',
        );
        $results[] = $row;
        if ($ok && empty($first_visible)) {
            $first_visible = $row;
        }
    }
    $summary = !empty($first_visible) ? $first_visible : (isset($results[0]) ? $results[0] : array());
    return array(
        'scanned_at' => time(),
        'metadata_version' => 1,
        'url' => $url,
        'agent' => (string) ($summary['agent'] ?? ''),
        'status' => (int) ($summary['status'] ?? 0),
        'ok' => !empty($summary['ok']),
        'title' => (string) ($summary['title'] ?? ''),
        'description' => (string) ($summary['description'] ?? ''),
        'author' => (string) ($summary['author'] ?? ''),
        'publisher' => (string) ($summary['publisher'] ?? ''),
        'robots' => (string) ($summary['robots'] ?? ''),
        'canonical' => (string) ($summary['canonical'] ?? ''),
        'text_sample' => (string) ($summary['text_sample'] ?? ''),
        'schema_documents' => (int) ($summary['schema_documents'] ?? 0),
        'schema_invalid' => (int) ($summary['schema_invalid'] ?? 0),
        'schema_types' => isset($summary['schema_types']) && is_array($summary['schema_types']) ? $summary['schema_types'] : array(),
        'body_bytes' => (int) ($summary['body_bytes'] ?? 0),
        'error' => (string) ($summary['error'] ?? ''),
        'agents' => $results,
    );
}

function tk_geo_ai_access_scan(): void {
    tk_require_admin_post('tk_geo_ai_access_scan');
    // A manual scan must replace saved evidence rather than returning it.
    tk_update_option('geo_ai_access_report', array());
    tk_update_option('geo_ai_access_report', tk_geo_run_ai_access_review());
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo-audit', 'tk_geo_ai_access_scanned' => 1), admin_url('admin.php')) . '#ai-access');
    exit;
}

function tk_geo_ai_access_clear(): void {
    tk_require_admin_post('tk_geo_ai_access_clear');
    tk_update_option('geo_ai_access_report', array());
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo-audit', 'tk_geo_ai_access_cleared' => 1), admin_url('admin.php')) . '#ai-access');
    exit;
}

function tk_geo_save(): void {
    $settings = array();
    tk_require_admin_post('tk_geo_save');

    $catalog_settings = tk_geo_ai_catalog_posted_settings();
    if (is_wp_error($catalog_settings)) { wp_die(esc_html($catalog_settings->get_error_message()), 'Invalid AI Catalog', array('back_link' => true, 'response' => 400)); }

    $active_tab = sanitize_key((string) tk_post('tk_geo_active_tab', 'overview'));
    $allowed_tabs = array('overview', 'custom-jsonld', 'faqpage', 'itemlist', 'llms', 'ai-catalog', 'geo-audit', 'ai-visibility', 'prompt-preview', 'schema-duplicates', 'crawler-preview', 'ai-access', 'preview');
    if (!in_array($active_tab, $allowed_tabs, true)) {
        $active_tab = 'overview';
    }

    $custom_json = isset($_POST['geo_custom_jsonld']) ? trim((string) wp_unslash($_POST['geo_custom_jsonld'])) : '';
    if (!empty($_POST['geo_custom_jsonld_generate'])) {
        $custom_json = (string) wp_json_encode(tk_geo_generate_custom_jsonld(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $active_tab = 'custom-jsonld';
    }
    if ($custom_json !== '' && tk_geo_decode_custom_jsonld($custom_json) === false) {
        wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo', 'tk_geo_error' => 'json'), admin_url('admin.php')));
        exit;
    }

    $post_types = tk_geo_public_post_types();
    $post_type = sanitize_key((string) tk_post('geo_itemlist_post_type', 'post'));
    if (!isset($post_types[$post_type])) {
        $post_type = 'post';
    }

    $faq_items = array();
    $questions = isset($_POST['geo_faq_question']) && is_array($_POST['geo_faq_question']) ? $_POST['geo_faq_question'] : array();
    $answers = isset($_POST['geo_faq_answer']) && is_array($_POST['geo_faq_answer']) ? $_POST['geo_faq_answer'] : array();
    $languages = isset($_POST['geo_faq_language']) && is_array($_POST['geo_faq_language']) ? $_POST['geo_faq_language'] : array();
    foreach ($questions as $index => $question) {
        $faq_items[] = array(
            'question' => wp_unslash((string) $question),
            'answer' => isset($answers[$index]) ? wp_unslash((string) $answers[$index]) : '',
            'language' => isset($languages[$index]) && is_string($languages[$index]) ? wp_unslash($languages[$index]) : '',
        );
    }
    $faq_imported = 0;
    if (!empty($_POST['geo_faq_import_submit'])) {
        $import_json = isset($_POST['geo_faq_import_json']) ? (string) wp_unslash($_POST['geo_faq_import_json']) : '';
        $default_faq_language = tk_geo_normalize_language_tag((string) get_bloginfo('language'), 'id');
        $import_language_input = trim((string) tk_post('geo_faq_import_language_custom', ''));
        if ($import_language_input === '') {
            $import_language_input = (string) tk_post('geo_faq_import_language', '');
        }
        $import_target_language = tk_geo_normalize_language_tag($import_language_input, $default_faq_language);
        if (trim((string) tk_post('geo_faq_import_language_custom', '')) !== '' && tk_geo_normalize_language_tag($import_language_input) === '') {
            wp_safe_redirect(add_query_arg(array(
                'page' => 'tool-kits-geo',
                'tk_geo_error' => 'faq-import',
                'tk_geo_error_message' => __('Use a valid locale code such as id, en, or en-sg.', 'tool-kits'),
            ), admin_url('admin.php')) . '#faqpage');
            exit;
        }
        $imported_items = tk_geo_import_faqpage_json($import_json, $import_target_language ?: $default_faq_language);
        if (is_wp_error($imported_items)) {
            wp_safe_redirect(add_query_arg(array(
                'page' => 'tool-kits-geo',
                'tk_geo_error' => 'faq-import',
                'tk_geo_error_message' => $imported_items->get_error_message(),
            ), admin_url('admin.php')) . '#faqpage');
            exit;
        }
        if ($import_target_language !== '') {
            $imported_items = array_map(function ($item) use ($import_target_language) {
                $item['language'] = $import_target_language;
                return $item;
            }, $imported_items);
        }
        $import_mode = sanitize_key((string) tk_post('geo_faq_import_mode', 'merge'));
        if (!in_array($import_mode, array('merge', 'append', 'replace'), true)) {
            $import_mode = 'merge';
        }
        $faq_items = tk_geo_merge_faq_items($faq_items, $imported_items, $import_mode);
        $faq_imported = count($imported_items);
        $active_tab = 'faqpage';
    }

    $post_ids = isset($_POST['geo_itemlist_post_ids']) && is_array($_POST['geo_itemlist_post_ids']) ? $_POST['geo_itemlist_post_ids'] : array();
    $post_ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $post_ids)))), 0, 50);
    $itemlist_language_mode = sanitize_key((string) tk_post('geo_itemlist_language_mode', 'single'));
    if (!in_array($itemlist_language_mode, array('single', 'all'), true)) {
        $itemlist_language_mode = 'single';
    }

    $settings['geo_enabled'] = !empty($_POST['geo_enabled']) ? 1 : 0;
    $settings['seo_schema_enabled'] = !empty($_POST['seo_schema_enabled']) ? 1 : 0;
    $settings['seo_breadcrumb_enabled'] = !empty($_POST['seo_breadcrumb_enabled']) ? 1 : 0;
    $settings['geo_schema_override_theme'] = !empty($_POST['geo_schema_override_theme']) ? 1 : 0;
    // Schema types are detected automatically. Legacy maps are preserved but no longer read or edited.
    $settings['geo_custom_jsonld'] = $custom_json;
    $settings['geo_faq_enabled'] = !empty($_POST['geo_faq_enabled']) ? 1 : 0;
    $settings['geo_faq_items'] = tk_geo_normalize_faq_items($faq_items);
    $settings['geo_itemlist_enabled'] = !empty($_POST['geo_itemlist_enabled']) ? 1 : 0;
    $settings['geo_itemlist_name'] = sanitize_text_field((string) tk_post('geo_itemlist_name', ''));
    $settings['geo_itemlist_description'] = sanitize_textarea_field((string) tk_post('geo_itemlist_description', ''));
    $settings['geo_itemlist_post_type'] = $post_type;
    $settings['geo_itemlist_post_ids'] = $post_ids;
    $settings['geo_itemlist_limit'] = max(1, min(50, (int) tk_post('geo_itemlist_limit', 10)));
    $settings['geo_itemlist_language_mode'] = $itemlist_language_mode;
    $settings['geo_llms_enabled'] = !empty($_POST['geo_llms_enabled']) ? 1 : 0;
    $settings['geo_ai_catalog_enabled'] = !empty($_POST['geo_ai_catalog_enabled']) || !empty($_POST['geo_ai_catalog_generate']) || !empty($_POST['geo_ai_catalog_generate_auto']) ? 1 : 0;
    tk_update_options($settings);
    $settings = array();
    if (is_array($catalog_settings)) { tk_geo_ai_catalog_save_settings($catalog_settings); }
    if (!empty($_POST['geo_ai_catalog_use_example'])) { $active_tab = 'ai-catalog'; }
    if (!empty($_POST['geo_ai_catalog_generate']) || !empty($_POST['geo_ai_catalog_generate_auto'])) {
        $active_tab = 'ai-catalog';
    }
    $llms_mode = sanitize_key((string) tk_post('geo_llms_mode', 'auto'));
    if (!in_array($llms_mode, array('auto', 'manual', 'hybrid'), true)) {
        $llms_mode = 'auto';
    }
    $settings['geo_llms_mode'] = $llms_mode;
    $llms_manual = sanitize_textarea_field((string) tk_post('geo_llms_manual', ''));
    $settings['geo_llms_manual'] = $llms_manual;
    $settings['geo_llms_include_faq'] = !empty($_POST['geo_llms_include_faq']) ? 1 : 0;
    $settings['geo_llms_include_itemlist'] = !empty($_POST['geo_llms_include_itemlist']) ? 1 : 0;
    $llms_sections = array();
    $llms_titles = isset($_POST['geo_llms_section_title']) && is_array($_POST['geo_llms_section_title']) ? $_POST['geo_llms_section_title'] : array();
    $llms_contents = isset($_POST['geo_llms_section_content']) && is_array($_POST['geo_llms_section_content']) ? $_POST['geo_llms_section_content'] : array();
    foreach ($llms_titles as $index => $title) {
        $llms_sections[] = array(
            'title' => wp_unslash((string) $title),
            'content' => isset($llms_contents[$index]) ? wp_unslash((string) $llms_contents[$index]) : '',
        );
    }
    $settings['geo_llms_sections'] = tk_geo_normalize_llms_sections($llms_sections);
    tk_update_options($settings);
    if (!empty($_POST['geo_llms_generate_draft'])) {
        tk_update_option('geo_llms_manual', tk_geo_build_llms_auto_text());
        $active_tab = 'llms';
    }

    $redirect_args = array('page' => 'tool-kits-geo', 'tk_saved' => 1);
    if ($faq_imported > 0) {
        $redirect_args['tk_geo_faq_imported'] = $faq_imported;
    }
    wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')) . '#' . $active_tab);
    exit;
}

// Group GEO Audit issues across URLs: one row per issue with every affected URL, most urgent first.
function tk_geo_audit_fix_summary(array $items): array {
    $summary = array();
    foreach ($items as $item) {
        if (!is_array($item) || empty($item['issues']) || !is_array($item['issues'])) {
            continue;
        }
        $url = (string) ($item['url'] ?? '');
        foreach ($item['issues'] as $issue) {
            $issue = trim((string) $issue);
            if ($issue === '') {
                continue;
            }
            if (!isset($summary[$issue])) {
                $data = tk_seo_geo_issue_data($issue);
                $summary[$issue] = array('issue' => $issue, 'priority' => (string) $data['priority'], 'penalty' => (int) $data['penalty'],
                    'solution' => (string) $data['solution'], 'automatic' => !empty($data['automatic']), 'urls' => array());
            }
            if ($url !== '' && !in_array($url, $summary[$issue]['urls'], true)) {
                $summary[$issue]['urls'][] = $url;
            }
        }
    }
    $rank = array('critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3);
    usort($summary, function ($a, $b) use ($rank) {
        return array(($rank[$a['priority']] ?? 4), -count($a['urls']), -$a['penalty']) <=> array(($rank[$b['priority']] ?? 4), -count($b['urls']), -$b['penalty']);
    });
    return $summary;
}

// A ready-to-paste brief for an AI assistant or developer, built from the grouped audit findings.
function tk_geo_audit_fix_prompt(array $summary, array $report, string $site): string {
    $urls = array();
    foreach ($summary as $row) {
        $urls = array_merge($urls, $row['urls']);
    }
    $lines = array(
        'Fix the GEO (AI search readiness) issues that the Tool Kits GEO Audit found on the WordPress site ' . $site . '.',
        'Audit: ' . (!empty($report['scanned_at']) ? gmdate('Y-m-d', (int) $report['scanned_at']) : 'latest scan') . ', '
            . count(array_unique($urls)) . ' affected URL(s) of ' . (int) ($report['checked_urls'] ?? 0) . ' checked, average GEO score '
            . (int) ($report['average_score'] ?? 0) . '/100.',
        '',
        'Work through the issues in this order. Change the source that renders each page (theme templates, page content, or SEO/schema settings), not the audit report. Keep existing URLs, content meaning and valid structured data. Do not add a second definition of an entity that already has an @id; extend the existing node instead.',
        '',
    );
    foreach (array_values($summary) as $index => $row) {
        $lines[] = ($index + 1) . '. [' . strtoupper($row['priority']) . '] ' . $row['issue'] . ' (' . count($row['urls']) . ' URL' . (count($row['urls']) === 1 ? '' : 's') . ', -' . $row['penalty'] . ' points each)';
        $lines[] = '   Fix: ' . $row['solution'];
        if ($row['automatic']) {
            $lines[] = '   Tool Kits can apply a fallback automatically (GEO Audit > Bulk Fix All), but fixing the source is preferred.';
        }
        $shown = array_slice($row['urls'], 0, 10);
        $lines[] = '   URLs: ' . implode(', ', $shown) . (count($row['urls']) > count($shown) ? ' (+' . (count($row['urls']) - count($shown)) . ' more)' : '');
    }
    $lines[] = '';
    $lines[] = 'When done, list what you changed for each numbered issue, and anything you could not fix with the reason. Then re-run the GEO Audit for the affected URLs.';
    return implode("\n", $lines);
}

function tk_render_geo_panel(): void {
    require __DIR__ . '/../templates/geo-panel.php';
}
