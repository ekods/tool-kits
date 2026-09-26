<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/geo-audit-batch.php';

function tk_geo_init() {
    add_action('wp_ajax_tk_geo_batch_start', 'tk_geo_batch_start');
    add_action('wp_ajax_tk_geo_batch_step', 'tk_geo_batch_step');
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
    add_action('admin_post_tk_geo_visibility_scan', 'tk_geo_visibility_scan_handler');
    add_action('admin_post_tk_geo_prompt_preview', 'tk_geo_prompt_preview_handler');
    add_action('admin_post_tk_geo_post_schema_validate', 'tk_geo_post_schema_validate_handler');
    add_action('init', 'tk_geo_llms_maybe_render', 1);
    add_action('wp_head', 'tk_geo_render_head_jsonld', 3);
    add_shortcode('tool_kits_geo_faq', 'tk_geo_faq_shortcode');
    add_action('wp_enqueue_scripts', 'tk_geo_enqueue_faq_styles');
}

function tk_geo_enqueue_faq_styles(): void {
    wp_enqueue_style('tk-geo-faq', TK_URL . 'assets/geo-faq.css', array(), tk_asset_version('assets/geo-faq.css'));
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

function tk_geo_current_faq_items(): array {
    if (!is_admin() && is_singular()) {
        $post_id = (int) get_queried_object_id();
        if (metadata_exists('post', $post_id, '_tk_geo_faq_items')) {
            if (!tk_geo_enabled()) {
                return array();
            }
            return tk_geo_normalize_faq_items(get_post_meta($post_id, '_tk_geo_faq_items', true));
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

    ob_start();
    ?>
    <section class="tk-geo-faq" itemscope itemtype="https://schema.org/FAQPage">
        <?php foreach ($items as $item) : ?>
            <details class="tk-geo-faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                <summary><span itemprop="name"><?php echo esc_html((string) $item['question']); ?></span></summary>
                <div class="tk-geo-faq-answer" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                    <div itemprop="text"><?php echo wpautop(esc_html((string) $item['answer'])); ?></div>
                </div>
            </details>
        <?php endforeach; ?>
    </section>
    <?php
    return (string) ob_get_clean();
}

function tk_geo_build_itemlist_schema(): array {
    if ((int) tk_get_option('geo_itemlist_enabled', 0) !== 1) {
        return array();
    }

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
    $languages = array();
    $position = 1;
    foreach ($posts as $post) {
        if (!is_object($post)) {
            continue;
        }
        $url = get_permalink($post);
        if (!is_string($url) || $url === '') {
            continue;
        }
        $language = tk_geo_post_language((int) $post->ID);
        if ($language !== '') {
            $languages[] = $language;
        }
        $item = array(
            '@type' => 'ListItem',
            'position' => $position,
            'url' => $url,
            'name' => get_the_title($post) ?: ('#' . (int) $post->ID),
        );
        if ($language !== '') {
            $item['inLanguage'] = $language;
        }
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
    $languages = array_values(array_unique(array_filter($languages)));
    if (!empty($languages)) {
        $schema['inLanguage'] = count($languages) === 1 ? $languages[0] : $languages;
    }
    return $schema;
}

function tk_geo_build_schema_documents(): array {
    $docs = array();
    $seo_schema_allowed = (function_exists('tk_seo_tools_enabled') ? tk_seo_tools_enabled() : true);
    if (function_exists('tk_seo_post_feature_enabled') && !tk_seo_post_feature_enabled('seo')) {
        $seo_schema_allowed = false;
    }
    if (function_exists('tk_seo_has_third_party_plugin') && tk_seo_has_third_party_plugin()) {
        $seo_schema_allowed = false;
    }
    if (function_exists('tk_seo_has_theme_managed_seo') && tk_seo_has_theme_managed_seo()) {
        $seo_schema_allowed = false;
    }
    if ($seo_schema_allowed && (int) tk_get_option('seo_schema_enabled', 1) === 1 && function_exists('tk_seo_build_schema_graph')) {
        $url = function_exists('tk_seo_current_url') ? tk_seo_current_url() : tk_geo_current_url();
        $title = wp_get_document_title();
        $description = function_exists('tk_seo_generate_description') ? tk_seo_generate_description() : '';
        $seo_schema = tk_seo_build_schema_graph($url, $title, $description);
        if (!empty($seo_schema)) {
            $docs[] = $seo_schema;
        }
    }
    $custom = tk_geo_decode_custom_jsonld((string) tk_get_option('geo_custom_jsonld', ''));
    if (is_array($custom)) {
        $docs[] = $custom;
    }
    $faq = tk_geo_build_faq_schema();
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
    );
}

function tk_geo_fetch_url(string $url, string $user_agent = 'Tool Kits GEO AI Access Review') {
    return wp_remote_get($url, array(
        'timeout' => 8,
        'redirection' => 3,
        'sslverify' => false,
        'headers' => array(
            'User-Agent' => $user_agent,
        ),
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
    if (preg_match_all('/<script\b[^>]*type=("|\')application\/ld\+json\1[^>]*>(.*?)<\/script>/is', $html, $matches)) {
        foreach ($matches[2] as $json) {
            $documents++;
            $decoded = json_decode(trim(html_entity_decode((string) $json)), true);
            if (!is_array($decoded)) {
                $invalid++;
                continue;
            }
            if ($recursive) {
                tk_geo_collect_schema_types_from_node($decoded, $types);
            } else {
                tk_geo_collect_top_level_schema_types($decoded, $types);
            }
        }
    }
    $counts = array_count_values($types);
    return array(
        'documents' => $documents,
        'invalid' => $invalid,
        'types' => $counts,
        'duplicates' => array_filter($counts, function($count) {
            return (int) $count > 1;
        }),
    );
}

function tk_geo_render_schema_type_badges($types, bool $highlight_duplicates = true): string {
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
    foreach ($agents as $agent => $info) {
        $response = tk_geo_fetch_url($home_url, (string) $agent);
        $status_code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $body = is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response);
        $schema = tk_geo_extract_jsonld_report($body, true);
        $ok = $status_code >= 200 && $status_code < 400 && trim($body) !== '';
        $crawler_fetch_results[] = array(
            'agent' => (string) $agent,
            'label' => (string) ($info['label'] ?? $agent),
            'status' => $status_code,
            'ok' => $ok,
            'body_bytes' => strlen($body),
            'schema_documents' => (int) ($schema['documents'] ?? 0),
            'error' => is_wp_error($response) ? $response->get_error_message() : '',
        );
        if (!$ok) {
            $issues[] = (string) $agent . ' could not fetch the homepage.';
        }
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
        $fetch = tk_geo_fetch_url($url, (string) $agent);
        $agent_status = is_wp_error($fetch) ? 0 : (int) wp_remote_retrieve_response_code($fetch);
        $agent_body = is_wp_error($fetch) ? '' : (string) wp_remote_retrieve_body($fetch);
        $visible = $allowed && $agent_status >= 200 && $agent_status < 400 && trim($agent_body) !== '';
        $agent_rows[] = array(
            'agent' => (string) $agent,
            'label' => (string) ($info['label'] ?? $agent),
            'allowed' => $allowed,
            'matched' => $matched,
            'status' => $agent_status,
            'visible' => $visible,
            'body_bytes' => strlen($agent_body),
            'error' => is_wp_error($fetch) ? $fetch->get_error_message() : '',
        );
        if (!$visible) {
            $issues[] = (string) $agent . ' may not be able to consume this URL.';
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
        if (empty($row['visible'])) {
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
    $saved = tk_get_option('geo_prompt_preview_report', array());
    if (is_array($saved) && ($saved['url'] ?? '') === $url) { return $saved; }
    $response = tk_geo_fetch_url($url, 'Tool Kits GEO Prompt Preview');
    $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    $html = is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response);
    $snapshot = tk_geo_extract_page_snapshot($html);
    $schema = tk_geo_extract_jsonld_report($html, true);
    $types = isset($schema['types']) && is_array($schema['types']) ? array_keys($schema['types']) : array();
    $heading = !empty($snapshot['h1s']) ? (string) $snapshot['h1s'][0] : (string) $snapshot['title'];
    $summary = trim($heading . '. ' . (string) $snapshot['description']);
    if ($summary === '.') {
        $summary = 'AI preview could not generate a confident summary because title and description are missing.';
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
        'error' => is_wp_error($response) ? $response->get_error_message() : '',
    );
}

function tk_geo_validate_post_schema(int $post_id): array {
    $saved = tk_get_option('geo_post_schema_report', array());
    if (is_array($saved) && isset($saved['post_id']) && (int) $saved['post_id'] === $post_id) { return $saved; }
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') {
        return array(
            'scanned_at' => time(),
            'post_id' => $post_id,
            'url' => '',
            'status' => 0,
            'score' => 0,
            'schema' => array('documents' => 0, 'invalid' => 0, 'types' => array(), 'duplicates' => array()),
            'issues' => array('Post not found or not published.'),
        );
    }

    $url = (string) get_permalink($post);
    $response = tk_geo_fetch_url($url, 'Tool Kits GEO Post Schema Validator');
    $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    $html = is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response);
    $schema = tk_geo_extract_jsonld_report($html, true);
    $issues = array();
    if ($status < 200 || $status >= 400) {
        $issues[] = 'Post URL returned HTTP ' . $status . '.';
    }
    if ((int) ($schema['documents'] ?? 0) === 0) {
        $issues[] = 'No JSON-LD schema detected on this post.';
    }
    if ((int) ($schema['invalid'] ?? 0) > 0) {
        $issues[] = 'Invalid JSON-LD detected.';
    }
    if (!empty($schema['duplicates'])) {
        $issues[] = 'Duplicate schema types detected.';
    }

    $score = 100;
    if ($status < 200 || $status >= 400) {
        $score -= 35;
    }
    if ((int) ($schema['documents'] ?? 0) === 0) {
        $score -= 35;
    }
    if ((int) ($schema['invalid'] ?? 0) > 0) {
        $score -= 25;
    }
    if (!empty($schema['duplicates'])) {
        $score -= 10;
    }

    return array(
        'scanned_at' => time(),
        'post_id' => $post_id,
        'post_title' => get_the_title($post),
        'post_type' => (string) get_post_type($post),
        'url' => $url,
        'status' => $status,
        'score' => max(0, min(100, $score)),
        'schema' => $schema,
        'issues' => $issues,
        'error' => is_wp_error($response) ? $response->get_error_message() : '',
    );
}

function tk_geo_clear_saved_results(): void {
    tk_require_admin_post('tk_geo_clear_saved_results');
    foreach (array('seo_geo_audit_report', 'geo_ai_access_report', 'geo_visibility_report', 'geo_prompt_preview_report', 'geo_post_schema_report', 'geo_schema_duplicate_report', 'geo_schema_duplicate_fix_report', 'geo_crawler_preview') as $key) {
        tk_update_option($key, array());
    }
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-geo') . '#geo-audit');
    exit;
}

function tk_geo_visibility_scan_handler(): void {
    tk_require_admin_post('tk_geo_visibility_scan');
    $url = tk_geo_normalize_site_url((string) tk_post('geo_visibility_url', home_url('/')));
    tk_update_option('geo_visibility_report', tk_geo_run_visibility_scan($url));
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo', 'tk_geo_visibility' => 1), admin_url('admin.php')) . '#ai-visibility');
    exit;
}

function tk_geo_prompt_preview_handler(): void {
    tk_require_admin_post('tk_geo_prompt_preview');
    $url = tk_geo_normalize_site_url((string) tk_post('geo_prompt_url', home_url('/')));
    tk_update_option('geo_prompt_preview_report', tk_geo_build_prompt_preview($url));
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo', 'tk_geo_prompt_preview' => 1), admin_url('admin.php')) . '#prompt-preview');
    exit;
}

function tk_geo_post_schema_validate_handler(): void {
    tk_require_admin_post('tk_geo_post_schema_validate');
    $post_id = max(0, (int) tk_post('geo_schema_post_id', 0));
    tk_update_option('geo_post_schema_report', tk_geo_validate_post_schema($post_id));
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo', 'tk_geo_post_schema' => 1), admin_url('admin.php')) . '#post-schema');
    exit;
}

function tk_geo_run_schema_duplicate_detector(bool $force_refresh = false): array {
    $saved = tk_get_option('geo_schema_duplicate_report', array());
    if (!$force_refresh && is_array($saved) && !empty($saved)) { return $saved; }
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
            'issue' => !empty($duplicates) ? 'Duplicate schema types detected.' : ($invalid > 0 ? 'Invalid JSON-LD detected.' : ''),
        );
    }

    return array(
        'scanned_at' => time(),
        'checked_urls' => count($items),
        'issue_count' => $issue_count,
        'items' => $items,
    );
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
    if (empty($report)) {
        $report = tk_geo_run_schema_duplicate_detector();
    }

    $duplicate_types = tk_geo_schema_duplicate_types_from_report($report);
    $seo_conflicts = array_values(array_intersect($duplicate_types, tk_geo_toolkits_seo_schema_types()));
    $actions = array();
    $remaining = array();

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

    $new_report = tk_geo_run_schema_duplicate_detector(true);
    $new_duplicate_types = tk_geo_schema_duplicate_types_from_report($new_report);
    if (!empty($new_duplicate_types)) {
        $remaining[] = 'Remaining duplicate types may come from the active theme, custom JSON-LD, or another SEO/schema plugin: ' . implode(', ', $new_duplicate_types) . '.';
    }

    tk_update_option('geo_schema_duplicate_report', $new_report);
    tk_update_option('geo_schema_duplicate_fix_report', array(
        'fixed_at' => time(),
        'actions' => $actions,
        'remaining' => $remaining,
    ));

    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo', 'tk_geo_schema_fixed' => 1), admin_url('admin.php')) . '#schema-duplicates');
    exit;
}

function tk_geo_schema_duplicate_scan(): void {
    tk_require_admin_post('tk_geo_schema_duplicate_scan');
    tk_update_option('geo_schema_duplicate_report', tk_geo_run_schema_duplicate_detector(true));
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo', 'tk_geo_schema_duplicates' => 1), admin_url('admin.php')) . '#schema-duplicates');
    exit;
}

function tk_geo_schema_duplicate_clear(): void {
    tk_require_admin_post('tk_geo_schema_duplicate_clear');
    tk_update_option('geo_schema_duplicate_report', array());
    tk_update_option('geo_schema_duplicate_fix_report', array());
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo'), admin_url('admin.php')) . '#schema-duplicates');
    exit;
}

function tk_geo_crawler_preview_handler(): void {
    tk_require_admin_post('tk_geo_crawler_preview');
    $url = esc_url_raw((string) tk_post('crawler_preview_url', home_url('/')));
    $agents = tk_geo_ai_crawler_agents();
    $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    $url_host = (string) wp_parse_url($url, PHP_URL_HOST);
    if ($url === '' || ($home_host !== '' && $url_host !== '' && strcasecmp($home_host, $url_host) !== 0)) {
        $url = home_url('/');
    }
    $saved = tk_get_option('geo_crawler_preview', array());
    if (is_array($saved) && ($saved['url'] ?? '') === $url) {
        wp_safe_redirect(admin_url('admin.php?page=tool-kits-geo') . '#crawler-preview');
        exit;
    }

    $results = array();
    $first_visible = array();
    foreach ($agents as $agent => $info) {
        $response = tk_geo_fetch_url($url, (string) $agent);
        $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $html = is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response);
        $schema = tk_geo_extract_jsonld_report($html, true);
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $title_match)) {
            $title = trim(wp_strip_all_tags(html_entity_decode((string) $title_match[1])));
        }
        $ok = $status >= 200 && $status < 400 && trim($html) !== '';
        $row = array(
            'agent' => (string) $agent,
            'label' => (string) ($info['label'] ?? $agent),
            'purpose' => (string) ($info['purpose'] ?? ''),
            'status' => $status,
            'ok' => $ok,
            'title' => $title,
            'description' => tk_geo_extract_meta_content($html, 'description'),
            'robots' => implode(', ', tk_geo_find_meta_robots($html)),
            'canonical' => tk_geo_extract_link_href($html, 'canonical'),
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
    tk_update_option('geo_crawler_preview', array(
        'scanned_at' => time(),
        'url' => $url,
        'agent' => (string) ($summary['agent'] ?? ''),
        'status' => (int) ($summary['status'] ?? 0),
        'ok' => !empty($summary['ok']),
        'title' => (string) ($summary['title'] ?? ''),
        'description' => (string) ($summary['description'] ?? ''),
        'robots' => (string) ($summary['robots'] ?? ''),
        'canonical' => (string) ($summary['canonical'] ?? ''),
        'schema_documents' => (int) ($summary['schema_documents'] ?? 0),
        'schema_invalid' => (int) ($summary['schema_invalid'] ?? 0),
        'schema_types' => isset($summary['schema_types']) && is_array($summary['schema_types']) ? $summary['schema_types'] : array(),
        'body_bytes' => (int) ($summary['body_bytes'] ?? 0),
        'error' => (string) ($summary['error'] ?? ''),
        'agents' => $results,
    ));
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo', 'tk_geo_crawler_preview' => 1), admin_url('admin.php')) . '#crawler-preview');
    exit;
}

function tk_geo_ai_access_scan(): void {
    tk_require_admin_post('tk_geo_ai_access_scan');
    tk_update_option('geo_ai_access_report', tk_geo_run_ai_access_review());
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo', 'tk_geo_ai_access_scanned' => 1), admin_url('admin.php')) . '#ai-access');
    exit;
}

function tk_geo_ai_access_clear(): void {
    tk_require_admin_post('tk_geo_ai_access_clear');
    tk_update_option('geo_ai_access_report', array());
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo', 'tk_geo_ai_access_cleared' => 1), admin_url('admin.php')) . '#ai-access');
    exit;
}

function tk_geo_save(): void {
    tk_require_admin_post('tk_geo_save');

    $active_tab = sanitize_key((string) tk_post('tk_geo_active_tab', 'overview'));
    $allowed_tabs = array('overview', 'custom-jsonld', 'faqpage', 'itemlist', 'llms', 'geo-audit', 'ai-visibility', 'prompt-preview', 'post-schema', 'schema-duplicates', 'crawler-preview', 'ai-access', 'preview');
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
        $imported_items = tk_geo_import_faqpage_json($import_json, (string) get_bloginfo('language'));
        if (is_wp_error($imported_items)) {
            wp_safe_redirect(add_query_arg(array(
                'page' => 'tool-kits-geo',
                'tk_geo_error' => 'faq-import',
                'tk_geo_error_message' => $imported_items->get_error_message(),
            ), admin_url('admin.php')) . '#faqpage');
            exit;
        }
        $import_mode = sanitize_key((string) tk_post('geo_faq_import_mode', 'replace'));
        $faq_items = $import_mode === 'append' ? array_merge($faq_items, $imported_items) : $imported_items;
        $faq_imported = count($imported_items);
        $active_tab = 'faqpage';
    }

    $post_ids = isset($_POST['geo_itemlist_post_ids']) && is_array($_POST['geo_itemlist_post_ids']) ? $_POST['geo_itemlist_post_ids'] : array();
    $post_ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $post_ids)))), 0, 50);
    $itemlist_language_mode = sanitize_key((string) tk_post('geo_itemlist_language_mode', 'single'));
    if (!in_array($itemlist_language_mode, array('single', 'all'), true)) {
        $itemlist_language_mode = 'single';
    }

    tk_update_option('geo_enabled', !empty($_POST['geo_enabled']) ? 1 : 0);
    tk_update_option('seo_schema_enabled', !empty($_POST['seo_schema_enabled']) ? 1 : 0);
    $schema_choices = function_exists('tk_seo_schema_type_choices') ? tk_seo_schema_type_choices() : array('auto' => 'Auto detect');
    $schema_map_input = isset($_POST['seo_schema_post_type_map']) && is_array($_POST['seo_schema_post_type_map']) ? $_POST['seo_schema_post_type_map'] : array();
    $schema_map = array();
    foreach ($post_types as $schema_post_type => $schema_post_type_object) {
        $schema_type = isset($schema_map_input[$schema_post_type]) ? sanitize_text_field(wp_unslash((string) $schema_map_input[$schema_post_type])) : 'auto';
        $schema_map[$schema_post_type] = isset($schema_choices[$schema_type]) ? $schema_type : 'auto';
    }
    tk_update_option('seo_schema_post_type_map', $schema_map);
    tk_update_option('geo_custom_jsonld', $custom_json);
    tk_update_option('geo_faq_enabled', !empty($_POST['geo_faq_enabled']) ? 1 : 0);
    tk_update_option('geo_faq_items', tk_geo_normalize_faq_items($faq_items));
    tk_update_option('geo_itemlist_enabled', !empty($_POST['geo_itemlist_enabled']) ? 1 : 0);
    tk_update_option('geo_itemlist_name', sanitize_text_field((string) tk_post('geo_itemlist_name', '')));
    tk_update_option('geo_itemlist_description', sanitize_textarea_field((string) tk_post('geo_itemlist_description', '')));
    tk_update_option('geo_itemlist_post_type', $post_type);
    tk_update_option('geo_itemlist_post_ids', $post_ids);
    tk_update_option('geo_itemlist_limit', max(1, min(50, (int) tk_post('geo_itemlist_limit', 10))));
    tk_update_option('geo_itemlist_language_mode', $itemlist_language_mode);
    tk_update_option('geo_llms_enabled', !empty($_POST['geo_llms_enabled']) ? 1 : 0);
    $llms_mode = sanitize_key((string) tk_post('geo_llms_mode', 'auto'));
    if (!in_array($llms_mode, array('auto', 'manual', 'hybrid'), true)) {
        $llms_mode = 'auto';
    }
    tk_update_option('geo_llms_mode', $llms_mode);
    $llms_manual = sanitize_textarea_field((string) tk_post('geo_llms_manual', ''));
    tk_update_option('geo_llms_manual', $llms_manual);
    tk_update_option('geo_llms_include_faq', !empty($_POST['geo_llms_include_faq']) ? 1 : 0);
    tk_update_option('geo_llms_include_itemlist', !empty($_POST['geo_llms_include_itemlist']) ? 1 : 0);
    $llms_sections = array();
    $llms_titles = isset($_POST['geo_llms_section_title']) && is_array($_POST['geo_llms_section_title']) ? $_POST['geo_llms_section_title'] : array();
    $llms_contents = isset($_POST['geo_llms_section_content']) && is_array($_POST['geo_llms_section_content']) ? $_POST['geo_llms_section_content'] : array();
    foreach ($llms_titles as $index => $title) {
        $llms_sections[] = array(
            'title' => wp_unslash((string) $title),
            'content' => isset($llms_contents[$index]) ? wp_unslash((string) $llms_contents[$index]) : '',
        );
    }
    tk_update_option('geo_llms_sections', tk_geo_normalize_llms_sections($llms_sections));
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

function tk_render_geo_panel(): void {
    if (!tk_is_admin_user()) return;

    $enabled = (int) tk_get_option('geo_enabled', 0);
    $seo_schema = (int) tk_get_option('seo_schema_enabled', 1);
    $seo_schema_post_type_map = tk_get_option('seo_schema_post_type_map', array());
    $seo_schema_post_type_map = is_array($seo_schema_post_type_map) ? $seo_schema_post_type_map : array();
    $custom_json = (string) tk_get_option('geo_custom_jsonld', '');
    $faq_enabled = (int) tk_get_option('geo_faq_enabled', 0);
    $faq_items = tk_geo_normalize_faq_items(tk_get_option('geo_faq_items', array()));
    $itemlist_enabled = (int) tk_get_option('geo_itemlist_enabled', 0);
    $itemlist_name = (string) tk_get_option('geo_itemlist_name', '');
    $itemlist_description = (string) tk_get_option('geo_itemlist_description', '');
    $itemlist_post_type = sanitize_key((string) tk_get_option('geo_itemlist_post_type', 'post'));
    $itemlist_language_mode = tk_geo_itemlist_language_mode();
    $filter_post_type = isset($_GET['geo_post_type']) ? sanitize_key((string) wp_unslash($_GET['geo_post_type'])) : '';
    $itemlist_limit = max(1, min(50, (int) tk_get_option('geo_itemlist_limit', 10)));
    $selected_ids = tk_geo_selected_itemlist_ids();
    $post_types = tk_geo_public_post_types();
    if ($filter_post_type !== '' && isset($post_types[$filter_post_type])) {
        $itemlist_post_type = $filter_post_type;
    }
    if (!isset($post_types[$itemlist_post_type])) {
        $itemlist_post_type = 'post';
    }
    $selector_posts = tk_geo_posts_for_selector($itemlist_post_type);
    $preview = tk_geo_build_schema_documents();
    $preview_json = !empty($preview) ? wp_json_encode(count($preview) === 1 ? $preview[0] : $preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
    $ai_access_report = tk_get_option('geo_ai_access_report', array());
    if (!is_array($ai_access_report)) {
        $ai_access_report = array();
    }
    $schema_duplicate_report = tk_get_option('geo_schema_duplicate_report', array());
    if (!is_array($schema_duplicate_report)) {
        $schema_duplicate_report = array();
    }
    $schema_duplicate_fix_report = tk_get_option('geo_schema_duplicate_fix_report', array());
    if (!is_array($schema_duplicate_fix_report)) {
        $schema_duplicate_fix_report = array();
    }
    $crawler_preview = tk_get_option('geo_crawler_preview', array());
    if (!is_array($crawler_preview)) {
        $crawler_preview = array();
    }
    $crawler_agents = tk_geo_ai_crawler_agents();
    $llms_enabled = (int) tk_get_option('geo_llms_enabled', 0);
    $llms_mode = sanitize_key((string) tk_get_option('geo_llms_mode', 'auto'));
    $llms_manual = (string) tk_get_option('geo_llms_manual', '');
    $llms_sections = tk_geo_normalize_llms_sections(tk_get_option('geo_llms_sections', array()));
    $llms_preview = tk_geo_build_llms_text();
    $visibility_report = tk_get_option('geo_visibility_report', array());
    $visibility_report = is_array($visibility_report) ? $visibility_report : array();
    $geo_report = tk_get_option('seo_geo_audit_report', array());
    $geo_report = is_array($geo_report) ? $geo_report : array();
    $prompt_preview_report = tk_get_option('geo_prompt_preview_report', array());
    $prompt_preview_report = is_array($prompt_preview_report) ? $prompt_preview_report : array();
    $post_schema_report = tk_get_option('geo_post_schema_report', array());
    $post_schema_report = is_array($post_schema_report) ? $post_schema_report : array();
    $schema_post_type = sanitize_key((string) tk_get_option('geo_itemlist_post_type', 'post'));
    if (!isset($post_types[$schema_post_type])) {
        $schema_post_type = 'post';
    }
    $schema_posts = tk_geo_posts_for_selector($schema_post_type);
    while (count($faq_items) < 3) {
        $faq_items[] = array('question' => '', 'answer' => '');
    }
    while (count($llms_sections) < 1) {
        $llms_sections[] = array('title' => '', 'content' => '');
    }
    ?>
    <form id="tk-geo-crawler-preview-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php tk_nonce_field('tk_geo_crawler_preview'); ?>
        <input type="hidden" name="action" value="tk_geo_crawler_preview">
    </form>
    <form id="tk-geo-visibility-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php tk_nonce_field('tk_geo_visibility_scan'); ?>
        <input type="hidden" name="action" value="tk_geo_visibility_scan">
    </form>
    <form id="tk-geo-prompt-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php tk_nonce_field('tk_geo_prompt_preview'); ?>
        <input type="hidden" name="action" value="tk_geo_prompt_preview">
    </form>
    <form id="tk-geo-post-schema-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php tk_nonce_field('tk_geo_post_schema_validate'); ?>
        <input type="hidden" name="action" value="tk_geo_post_schema_validate">
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php tk_nonce_field('tk_geo_save'); ?>
        <input type="hidden" name="action" value="tk_geo_save">
        <input type="hidden" name="tk_geo_active_tab" id="tk-geo-active-tab" value="overview">

        <div class="tk-tabs tk-geo-tabs">
            <div class="tk-tabs-nav" role="tablist" aria-label="GEO feature tabs">
                <button type="button" class="tk-tabs-nav-button is-active" data-panel="overview">Output</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="custom-jsonld">Custom JSON-LD</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="faqpage">FAQPage</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="itemlist">ItemList</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="llms">llms.txt</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="geo-audit">GEO Audit</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="ai-visibility">AI Visibility</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="prompt-preview">Prompt Preview</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="post-schema">Post Schema</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="schema-duplicates">Duplicate Detector</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="crawler-preview">Crawler Preview</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="ai-access">AI Access Review</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="preview">Preview</button>
            </div>
            <div class="tk-tabs-content">
        <div class="tk-card tk-tab-panel is-active" data-panel-id="overview">
            <h2>GEO Output</h2>
            <p>Enable structured data for AI answer engines and search features. JSON-LD is printed in the frontend head.</p>
            <?php tk_render_switch('geo_enabled', 'Enable GEO JSON-LD Output', 'Print enabled custom JSON-LD, FAQPage, and ItemList documents on public pages.', $enabled); ?>
            <div style="margin-top:16px;">
                <?php tk_render_switch('seo_schema_enabled', 'SEO JSON-LD Schema', 'Generate WebSite, Organization, WebPage, Article, and Service schema through GEO output.', $seo_schema); ?>
                <p class="description">This setting was moved from SEO so all JSON-LD output is managed from GEO.</p>
                <?php if (function_exists('tk_seo_schema_type_choices')) : ?>
                    <h3 style="margin-top:20px;">Schema by Post Type</h3>
                    <p class="description">Choose the content entity emitted beside WebPage. Auto detection uses the post type name and adds no extra frontend request.</p>
                    <table class="widefat striped" style="max-width:720px;margin-top:10px;">
                        <thead><tr><th>Post Type</th><th>Schema Type</th></tr></thead>
                        <tbody>
                        <?php foreach ($post_types as $schema_post_type => $schema_post_type_object) : ?>
                            <?php $mapped_schema = isset($seo_schema_post_type_map[$schema_post_type]) ? (string) $seo_schema_post_type_map[$schema_post_type] : 'auto'; ?>
                            <tr>
                                <td><label for="tk-schema-map-<?php echo esc_attr($schema_post_type); ?>"><?php echo esc_html($schema_post_type_object->labels->singular_name); ?></label></td>
                                <td>
                                    <select id="tk-schema-map-<?php echo esc_attr($schema_post_type); ?>" name="seo_schema_post_type_map[<?php echo esc_attr($schema_post_type); ?>]">
                                        <?php foreach (tk_seo_schema_type_choices() as $schema_value => $schema_label) : ?>
                                            <option value="<?php echo esc_attr($schema_value); ?>" <?php selected($mapped_schema, $schema_value); ?>><?php echo esc_html($schema_label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="custom-jsonld">
            <h3>Custom JSON-LD</h3>
            <p class="description">Paste a complete JSON-LD object or graph. It must be valid JSON.</p>
            <textarea name="geo_custom_jsonld" rows="12" style="width:100%; max-width:100%; font-family:monospace;" placeholder='{"@context":"https://schema.org","@type":"Organization","name":"Example"}'><?php echo esc_textarea($custom_json); ?></textarea>
            <p>
                <button type="submit" class="button" name="geo_custom_jsonld_generate" value="1">Generate JSON-LD Draft</button>
            </p>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="faqpage">
            <h3>FAQPage</h3>
            <?php tk_render_switch('geo_faq_enabled', 'Enable FAQPage Schema', 'Generate FAQPage JSON-LD from the question and answer rows below.', $faq_enabled); ?>
            <p class="description">Use shortcode <code>[tool_kits_geo_faq]</code> on a page to render the same FAQ as visible structured content.</p>
            <details class="tk-geo-faq-import" style="margin-top:16px;">
                <summary><strong>Import FAQPage JSON-LD</strong></summary>
                <div style="margin-top:12px;">
                    <textarea name="geo_faq_import_json" rows="8" style="width:100%;max-width:100%;font-family:monospace;" placeholder='{"@context":"https://schema.org","@type":"FAQPage","inLanguage":"id-ID","mainEntity":[]}'></textarea>
                    <div class="tk-inline">
                        <label>
                            <span class="screen-reader-text">Import mode</span>
                            <select name="geo_faq_import_mode">
                                <option value="replace">Replace existing FAQ</option>
                                <option value="append">Append to existing FAQ</option>
                            </select>
                        </label>
                        <button type="submit" class="button button-secondary" name="geo_faq_import_submit" value="1">Import FAQPage</button>
                    </div>
                    <p class="description">Reads locale from <code>inLanguage</code> on each Question or FAQPage. Entries without a locale use the current WordPress locale.</p>
                </div>
            </details>
            <div id="tk-geo-faq-rows" style="margin-top:16px;">
                <?php foreach ($faq_items as $index => $item) : ?>
                    <div class="tk-geo-faq-row" style="display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1.5fr) auto; gap:12px; margin-bottom:12px; align-items:start;">
                        <div>
                            <input type="text" name="geo_faq_language[]" value="<?php echo esc_attr($item['language'] ?? ''); ?>" placeholder="Language: id, en, en-sg" aria-label="FAQ language" style="width:100%;margin-bottom:8px;">
                            <input type="text" name="geo_faq_question[]" value="<?php echo esc_attr((string) $item['question']); ?>" placeholder="Question" style="width:100%;">
                        </div>
                        <textarea name="geo_faq_answer[]" rows="2" placeholder="Answer"><?php echo esc_textarea((string) $item['answer']); ?></textarea>
                        <button type="button" class="button tk-geo-faq-remove">Remove</button>
                    </div>
                <?php endforeach; ?>
            <p class="description">Save up to 50 rows. Blank rows are ignored.</p>
            </div>
            <p><button type="button" class="button" id="tk-geo-faq-add">Add Question</button></p>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="itemlist">
            <h3>ItemList Generator</h3>
            <?php tk_render_switch('geo_itemlist_enabled', 'Enable ItemList Schema', 'Generate an ItemList from selected posts or the latest posts in the selected post type.', $itemlist_enabled); ?>
            <div class="tk-grid tk-grid-2" style="margin-top:16px;">
                <div>
                    <label>List Name</label>
                    <input type="text" name="geo_itemlist_name" value="<?php echo esc_attr($itemlist_name); ?>" placeholder="Featured articles">
                    <label>Description</label>
                    <textarea name="geo_itemlist_description" rows="3" placeholder="Optional list description"><?php echo esc_textarea($itemlist_description); ?></textarea>
                    <label>Post Type</label>
                    <select name="geo_itemlist_post_type" id="tk-geo-itemlist-post-type">
                        <?php foreach ($post_types as $type => $object) : ?>
                            <option value="<?php echo esc_attr((string) $type); ?>" <?php selected($itemlist_post_type, (string) $type); ?>>
                                <?php echo esc_html((string) ($object->labels->name ?? $type)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label>Fallback Limit</label>
                    <input type="number" min="1" max="50" class="small-text" name="geo_itemlist_limit" value="<?php echo esc_attr((string) $itemlist_limit); ?>">
                    <label>Bilingual Output</label>
                    <select name="geo_itemlist_language_mode">
                        <option value="single" <?php selected($itemlist_language_mode, 'single'); ?>>Generate selected posts only</option>
                        <option value="all" <?php selected($itemlist_language_mode, 'all'); ?>>Generate all translations</option>
                    </select>
                    <p class="description">If no posts are selected, the generator uses latest published content from the chosen post type.</p>
                </div>
                <div>
                    <p style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin:0 0 6px;">
                        <label style="margin:0;">Select Posts</label>
                        <span>
                            <button type="button" class="button button-small" id="tk-geo-itemlist-select-all">Select All</button>
                            <button type="button" class="button button-small" id="tk-geo-itemlist-clear">Clear</button>
                        </span>
                    </p>
                    <select id="tk-geo-itemlist-post-ids" name="geo_itemlist_post_ids[]" multiple size="12" style="width:100%; max-width:100%;">
                        <?php foreach ($selector_posts as $post) : ?>
                            <option value="<?php echo esc_attr((string) $post->ID); ?>" <?php selected(in_array((int) $post->ID, $selected_ids, true)); ?>>
                                <?php echo esc_html((get_the_title($post) ?: ('#' . (int) $post->ID)) . ' (' . $post->post_name . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Use Select All for every loaded item. In bilingual mode, "all translations" expands selected posts through Polylang/WPML translations when available.</p>
                </div>
            </div>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="llms" id="llms-txt">
            <h3>llms.txt Generator</h3>
            <p>Render a virtual <code>/llms.txt</code> for LLM crawlers. Auto mode can be edited: generate a draft, adjust the textarea, then save.</p>
            <?php tk_render_switch('geo_llms_enabled', 'Enable llms.txt', 'Serve a text/plain llms.txt document at the site root.', $llms_enabled); ?>
            <div class="tk-grid tk-grid-2" style="gap:16px; margin-top:16px;">
                <div>
                    <label>Mode</label>
                    <select name="geo_llms_mode">
                        <option value="auto" <?php selected($llms_mode, 'auto'); ?>>Auto generated, editable</option>
                        <option value="manual" <?php selected($llms_mode, 'manual'); ?>>Manual only</option>
                        <option value="hybrid" <?php selected($llms_mode, 'hybrid'); ?>>Auto + manual notes</option>
                    </select>
                    <p>
                        <label><input type="checkbox" name="geo_llms_include_itemlist" value="1" <?php checked(1, tk_get_option('geo_llms_include_itemlist', 1)); ?>> Include ItemList content</label>
                    </p>
                    <p>
                        <label><input type="checkbox" name="geo_llms_include_faq" value="1" <?php checked(1, tk_get_option('geo_llms_include_faq', 1)); ?>> Include FAQ content</label>
                    </p>
                    <p><a href="<?php echo esc_url(home_url('/llms.txt')); ?>" target="_blank" rel="noopener">Open llms.txt</a></p>
                    <p><button class="button" name="geo_llms_generate_draft" value="1">Generate Editable Draft</button></p>
                </div>
                <div>
                    <label>Editable llms.txt Content / Notes</label>
                    <textarea name="geo_llms_manual" rows="8" style="width:100%; max-width:100%; font-family:monospace;" placeholder="Generate a draft, then edit it here. Leave empty to use live auto generation."><?php echo esc_textarea($llms_manual); ?></textarea>
                </div>
            </div>
            <h4 style="margin-top:18px;">llms.txt Section Builder</h4>
            <p class="description">Add structured sections to the auto-generated llms.txt draft. These sections are included before curated content and FAQ.</p>
            <div id="tk-geo-llms-section-rows" style="margin-top:12px;">
                <?php foreach ($llms_sections as $section) : ?>
                    <div class="tk-geo-llms-section-row" style="display:grid; grid-template-columns:minmax(180px,0.5fr) minmax(0,1.5fr) auto; gap:12px; margin-bottom:12px; align-items:start;">
                        <input type="text" name="geo_llms_section_title[]" value="<?php echo esc_attr((string) $section['title']); ?>" placeholder="Section title">
                        <textarea name="geo_llms_section_content[]" rows="3" placeholder="Section content"><?php echo esc_textarea((string) $section['content']); ?></textarea>
                        <button type="button" class="button tk-geo-llms-section-remove">Remove</button>
                    </div>
                <?php endforeach; ?>
                <p class="description">Blank rows are ignored. Use this for products, services, policies, editorial notes, or source priority hints.</p>
            </div>
            <p><button type="button" class="button" id="tk-geo-llms-section-add">Add Section</button></p>
            <label>Preview</label>
            <textarea readonly rows="12" style="width:100%; max-width:100%; font-family:monospace;"><?php echo esc_textarea($llms_preview); ?></textarea>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="geo-audit" id="geo-audit">
            <h3>GEO Audit</h3>
            <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_geo_clear_saved_results'), 'tk_geo_clear_saved_results')); ?>">Clear All Saved GEO Results</a></p>
            <p>Scan live pages for AI answer readiness: schema validity, entity completeness, semantic HTML, trust signals, and freshness.</p>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_seo_geo_audit_clear'), 'tk_seo_geo_audit_clear')); ?>">Clear GEO Audit</a>
            </p>
            <?php tk_geo_batch_controls(); ?>

            <?php if (!empty($geo_report)) : ?>
                <?php
                $geo_avg = (int) ($geo_report['average_score'] ?? 0);
                $geo_avg_class = 'tk-priority-low';
                if ($geo_avg < 50) {
                    $geo_avg_class = 'tk-priority-critical';
                } elseif ($geo_avg < 70) {
                    $geo_avg_class = 'tk-priority-high';
                } elseif ($geo_avg < 85) {
                    $geo_avg_class = 'tk-priority-medium';
                }
                $geo_items = isset($geo_report['items']) && is_array($geo_report['items']) ? $geo_report['items'] : array();
                ?>
                <p class="tk-report-actions">
                    <button type="button" class="button tk-geo-export-pdf" data-report-target="tk-geo-audit-report" data-report-title="GEO Audit Report">Export Report PDF</button>
                </p>
                <div id="tk-geo-audit-report">
                    <p>
                        <button type="button" class="button tk-geo-report-selected">Run Selected</button>
                        <button type="button" class="button tk-geo-retry-failed">Retry Failed</button>
                    </p>
                    <p class="tk-geo-report-status" role="status" aria-live="polite"></p>
                    <p class="description">
                        Last scan: <?php echo !empty($geo_report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $geo_report['scanned_at'])) : '-'; ?> |
                        Checked: <?php echo esc_html((string) ((int) ($geo_report['checked_urls'] ?? 0))); ?> |
                        Issues: <?php echo esc_html((string) ((int) ($geo_report['issue_count'] ?? 0))); ?> |
                        Avg GEO Score: <span class="tk-badge <?php echo esc_attr($geo_avg_class); ?>"><?php echo esc_html((string) $geo_avg); ?>/100</span>
                    </p>
                    <?php if (!empty($geo_items)) : ?>
                        <div class="tk-table-scroll">
                        <?php if (!empty($geo_report['incomplete'])) : ?><p>Audit belum lengkap: pemeriksaan berhenti karena batas waktu atau kegagalan koneksi. Jalankan ulang setelah konektivitas server diperiksa.</p><?php endif; ?>
                        <table class="widefat striped tk-table">
                            <thead><tr><th><input type="checkbox" class="tk-geo-report-all" aria-label="Select all report URLs"></th><th>URL</th><th>Status</th><th>Grade</th><th>Score</th><th>Schema</th><th>Semantic</th><th>Freshness</th><th>Issues</th></tr></thead>
                            <tbody>
                            <?php foreach ($geo_items as $item) : ?>
                                <?php
                                $schema_item = is_array($item['schema'] ?? null) ? $item['schema'] : array();
                                $semantic_item = is_array($item['semantic'] ?? null) ? $item['semantic'] : array();
                                $freshness_item = is_array($item['freshness'] ?? null) ? $item['freshness'] : array();
                                $item_score = (int) ($item['score'] ?? 0);
                                $item_score_class = 'tk-priority-low';
                                if ($item_score < 50) {
                                    $item_score_class = 'tk-priority-critical';
                                } elseif ($item_score < 70) {
                                    $item_score_class = 'tk-priority-high';
                                } elseif ($item_score < 85) {
                                    $item_score_class = 'tk-priority-medium';
                                }
                                ?>
                                <tr>
                                    <td><input type="checkbox" class="tk-geo-report-target" value="<?php echo esc_attr((string) ($item['url'] ?? '')); ?>" aria-label="<?php echo esc_attr('Select ' . ($item['url'] ?? 'URL')); ?>"></td>
                                    <td><a href="<?php echo esc_url((string) ($item['url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($item['url'] ?? '')); ?></a></td>
                                    <td><?php echo esc_html((string) ((int) ($item['status'] ?? 0))); ?></td>
                                    <td><strong><?php echo esc_html((string) ($item['grade'] ?? 'F')); ?></strong></td>
                                    <td><?php if (isset($item['score'])) : ?><span class="tk-badge <?php echo esc_attr($item_score_class); ?>"><?php echo esc_html((string) $item_score); ?>/100</span><?php else : ?>Not verified<?php endif; ?></td>
                                    <td>
                                        <span class="tk-badge <?php echo !empty($schema_item['valid']) ? 'tk-on' : 'tk-off'; ?>">JSON-LD</span>
                                        <span class="tk-badge <?php echo !empty($schema_item['entity_ok']) ? 'tk-on' : 'tk-off'; ?>">Entity</span>
                                        <?php if (!empty($schema_item['types']) && is_array($schema_item['types'])) : ?>
                                            <div style="margin-top:6px;"><?php echo tk_geo_render_schema_type_badges(array_slice($schema_item['types'], 0, 8), false); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="tk-badge <?php echo !empty($semantic_item['main']) ? 'tk-on' : 'tk-off'; ?>">Main</span>
                                        <span class="tk-badge <?php echo ((int) ($semantic_item['h1_count'] ?? 0)) === 1 ? 'tk-on' : 'tk-off'; ?>">H1</span>
                                        <span class="tk-badge <?php echo !empty($semantic_item['article_or_section']) ? 'tk-on' : 'tk-off'; ?>">Sections</span>
                                    </td>
                                    <td>
                                        <span class="tk-badge <?php echo !empty($freshness_item['published']) ? 'tk-on' : 'tk-off'; ?>">Published</span>
                                        <span class="tk-badge <?php echo !empty($freshness_item['modified']) ? 'tk-on' : 'tk-off'; ?>">Modified</span>
                                    </td>
                                    <td>
                                        <?php
                                        $issues = isset($item['issues']) && is_array($item['issues']) ? $item['issues'] : array();
                                        echo empty($issues) ? '<span class="tk-badge tk-on">OK</span>' : esc_html(implode('; ', array_slice($issues, 0, 8)));
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <p class="description">No GEO audit report yet.</p>
            <?php endif; ?>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="ai-visibility" id="ai-visibility">
            <h3>AI Visibility Score per URL</h3>
            <p>Scan one URL for crawler access, indexability signals, metadata, schema availability, and AI crawler visibility.</p>
            <div class="tk-grid tk-grid-2" style="gap:12px;">
                <p>
                    <label>URL</label>
                    <input type="url" form="tk-geo-visibility-form" name="geo_visibility_url" value="<?php echo esc_attr((string) ($visibility_report['url'] ?? home_url('/'))); ?>">
                </p>
                <p style="display:flex; align-items:flex-end;">
                    <button class="button button-primary" form="tk-geo-visibility-form">Generate AI Visibility Score</button>
                </p>
            </div>
            <?php if (!empty($visibility_report)) : ?>
                <?php
                $visibility_score = (int) ($visibility_report['score'] ?? 0);
                $visibility_badge = $visibility_score >= 80 ? 'tk-on' : ($visibility_score >= 60 ? 'tk-warn' : '');
                $visibility_checks = isset($visibility_report['checks']) && is_array($visibility_report['checks']) ? $visibility_report['checks'] : array();
                $visibility_agents = isset($visibility_report['agents']) && is_array($visibility_report['agents']) ? $visibility_report['agents'] : array();
                $visibility_schema = isset($visibility_report['schema']) && is_array($visibility_report['schema']) ? $visibility_report['schema'] : array();
                $visibility_snapshot = isset($visibility_report['snapshot']) && is_array($visibility_report['snapshot']) ? $visibility_report['snapshot'] : array();
                $visibility_issues = isset($visibility_report['issues']) && is_array($visibility_report['issues']) ? $visibility_report['issues'] : array();
                ?>
                <p class="tk-report-actions">
                    <button type="button" class="button tk-geo-export-pdf" data-report-target="tk-geo-visibility-report" data-report-title="AI Visibility Score Report">Export Report PDF</button>
                </p>
                <div id="tk-geo-visibility-report">
                    <p>
                        <span class="tk-badge <?php echo esc_attr($visibility_badge); ?>">Score: <?php echo esc_html((string) $visibility_score); ?>%</span>
                        <span class="description">Last scan: <?php echo !empty($visibility_report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $visibility_report['scanned_at'])) : '-'; ?></span>
                    </p>
                    <?php if (!empty($visibility_issues)) : ?>
                        <ul class="tk-list">
                            <?php foreach (array_slice($visibility_issues, 0, 12) as $issue) : ?>
                                <li><?php echo esc_html((string) $issue); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <div class="tk-grid tk-grid-3" style="gap:16px; margin:14px 0;">
                        <div class="tk-card"><strong>HTTP</strong><br><?php echo esc_html((string) ((int) ($visibility_report['status'] ?? 0))); ?></div>
                        <div class="tk-card"><strong>JSON-LD</strong><br><?php echo esc_html((string) ((int) ($visibility_schema['documents'] ?? 0))); ?> docs, <?php echo esc_html((string) ((int) ($visibility_schema['invalid'] ?? 0))); ?> invalid</div>
                        <div class="tk-card"><strong>Schema Types</strong><br><?php echo tk_geo_render_schema_type_badges($visibility_schema['types'] ?? array()); ?></div>
                    </div>
                    <div class="tk-table-scroll">
                    <table class="widefat striped tk-table">
                        <tbody>
                            <tr><th>URL</th><td><a href="<?php echo esc_url((string) ($visibility_report['url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($visibility_report['url'] ?? '')); ?></a></td></tr>
                            <tr><th>Title</th><td><?php echo esc_html((string) ($visibility_snapshot['title'] ?? '')); ?></td></tr>
                            <tr><th>Description</th><td><?php echo esc_html((string) ($visibility_snapshot['description'] ?? '')); ?></td></tr>
                            <tr><th>Canonical</th><td><code><?php echo esc_html((string) ($visibility_snapshot['canonical'] ?? '')); ?></code></td></tr>
                        </tbody>
                    </table>
                    </div>
                    <h4 style="margin-top:18px;">Checks</h4>
                    <div class="tk-table-scroll">
                    <table class="widefat striped tk-table">
                        <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
                        <tbody>
                        <?php foreach ($visibility_checks as $check) : ?>
                            <?php $check_status = sanitize_key((string) ($check['status'] ?? 'warn')); ?>
                            <tr>
                                <td><?php echo esc_html((string) ($check['name'] ?? '')); ?></td>
                                <td><span class="tk-badge <?php echo esc_attr($check_status === 'ok' ? 'tk-on' : ($check_status === 'warn' ? 'tk-warn' : '')); ?>"><?php echo esc_html(strtoupper($check_status)); ?></span></td>
                                <td><?php echo esc_html((string) ($check['detail'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <h4 style="margin-top:18px;">AI Crawler Visibility</h4>
                    <div class="tk-table-scroll">
                    <table class="widefat striped tk-table">
                        <thead><tr><th>Visible</th><th>User Agent</th><th>Robots</th><th>HTTP</th><th>HTML</th><th>Error</th></tr></thead>
                        <tbody>
                        <?php foreach ($visibility_agents as $row) : ?>
                            <?php $visible = !empty($row['visible']); ?>
                            <tr>
                                <td><span class="tk-badge <?php echo $visible ? 'tk-on' : ''; ?>"><?php echo $visible ? 'Visible' : 'Issue'; ?></span></td>
                                <td><strong><?php echo esc_html((string) ($row['agent'] ?? '')); ?></strong><br><span class="description"><?php echo esc_html((string) ($row['label'] ?? '')); ?></span></td>
                                <td><?php echo !empty($row['allowed']) ? '<span class="tk-badge tk-on">Allowed</span>' : '<span class="tk-badge">Blocked</span>'; ?><br><code><?php echo esc_html((string) ($row['matched'] ?? '')); ?></code></td>
                                <td><?php echo esc_html((string) ((int) ($row['status'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) ((int) ($row['body_bytes'] ?? 0))); ?> bytes</td>
                                <td><?php echo esc_html((string) ($row['error'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            <?php else : ?>
                <p class="description">No AI visibility scan yet.</p>
            <?php endif; ?>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="prompt-preview" id="prompt-preview">
            <h3>Prompt Preview</h3>
            <p>Preview how an AI answer engine may summarize a page from visible title, description, headings, body sample, and schema types.</p>
            <div class="tk-grid tk-grid-2" style="gap:12px;">
                <p>
                    <label>URL</label>
                    <input type="url" form="tk-geo-prompt-form" name="geo_prompt_url" value="<?php echo esc_attr((string) ($prompt_preview_report['url'] ?? home_url('/'))); ?>">
                </p>
                <p style="display:flex; align-items:flex-end;">
                    <button class="button button-primary" form="tk-geo-prompt-form">Generate Prompt Preview</button>
                </p>
            </div>
            <?php if (!empty($prompt_preview_report)) : ?>
                <?php $prompt_types = isset($prompt_preview_report['schema_types']) && is_array($prompt_preview_report['schema_types']) ? $prompt_preview_report['schema_types'] : array(); ?>
                <p class="tk-report-actions">
                    <button type="button" class="button tk-geo-export-pdf" data-report-target="tk-geo-prompt-report" data-report-title="Prompt Preview Report">Export Report PDF</button>
                </p>
                <div id="tk-geo-prompt-report">
                    <p><strong>Prompt:</strong> <?php echo esc_html((string) ($prompt_preview_report['prompt'] ?? '')); ?></p>
                    <div class="tk-card" style="background:var(--tk-bg-soft);">
                        <h4 style="margin-top:0;">AI Summary Preview</h4>
                        <p style="font-size:16px; line-height:1.6;"><?php echo esc_html((string) ($prompt_preview_report['summary'] ?? '')); ?></p>
                    </div>
                    <div class="tk-table-scroll" style="margin-top:14px;">
                    <table class="widefat striped tk-table">
                        <tbody>
                            <tr><th>URL</th><td><a href="<?php echo esc_url((string) ($prompt_preview_report['url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($prompt_preview_report['url'] ?? '')); ?></a></td></tr>
                            <tr><th>HTTP</th><td><?php echo esc_html((string) ((int) ($prompt_preview_report['status'] ?? 0))); ?></td></tr>
                            <tr><th>Title</th><td><?php echo esc_html((string) ($prompt_preview_report['title'] ?? '')); ?></td></tr>
                            <tr><th>Description</th><td><?php echo esc_html((string) ($prompt_preview_report['description'] ?? '')); ?></td></tr>
                            <tr><th>H1</th><td><code><?php echo esc_html(wp_json_encode($prompt_preview_report['h1s'] ?? array(), JSON_UNESCAPED_SLASHES)); ?></code></td></tr>
                            <tr><th>Schema Types</th><td><?php echo tk_geo_render_schema_type_badges($prompt_types, false); ?></td></tr>
                            <?php if (!empty($prompt_preview_report['error'])) : ?><tr><th>Error</th><td><?php echo esc_html((string) $prompt_preview_report['error']); ?></td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                    <h4 style="margin-top:18px;">Visible Context Sample</h4>
                    <textarea readonly rows="8" style="width:100%; max-width:100%;"><?php echo esc_textarea((string) ($prompt_preview_report['context_sample'] ?? '')); ?></textarea>
                </div>
            <?php else : ?>
                <p class="description">No prompt preview yet.</p>
            <?php endif; ?>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="post-schema" id="post-schema">
            <h3>Schema Validation Report per Post</h3>
            <p>Validate the frontend JSON-LD output for one published post or page.</p>
            <div class="tk-grid tk-grid-2" style="gap:12px;">
                <p>
                    <label>Post</label>
                    <select form="tk-geo-post-schema-form" name="geo_schema_post_id" style="width:100%; max-width:100%;">
                        <?php foreach ($schema_posts as $schema_post) : ?>
                            <option value="<?php echo esc_attr((string) $schema_post->ID); ?>" <?php selected((int) ($post_schema_report['post_id'] ?? 0), (int) $schema_post->ID); ?>>
                                <?php echo esc_html((get_the_title($schema_post) ?: ('#' . (int) $schema_post->ID)) . ' (' . get_post_type($schema_post) . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p style="display:flex; align-items:flex-end;">
                    <button class="button button-primary" form="tk-geo-post-schema-form">Validate Post Schema</button>
                </p>
            </div>
            <p class="description">Uses the current ItemList post type selector as the source list.</p>

            <?php if (!empty($post_schema_report)) : ?>
                <?php
                $post_schema = isset($post_schema_report['schema']) && is_array($post_schema_report['schema']) ? $post_schema_report['schema'] : array();
                $post_schema_score = (int) ($post_schema_report['score'] ?? 0);
                $post_schema_badge = $post_schema_score >= 80 ? 'tk-on' : ($post_schema_score >= 60 ? 'tk-warn' : '');
                $post_schema_issues = isset($post_schema_report['issues']) && is_array($post_schema_report['issues']) ? $post_schema_report['issues'] : array();
                ?>
                <p class="tk-report-actions">
                    <button type="button" class="button tk-geo-export-pdf" data-report-target="tk-geo-post-schema-report" data-report-title="Post Schema Validation Report">Export Report PDF</button>
                </p>
                <div id="tk-geo-post-schema-report">
                    <p>
                        <span class="tk-badge <?php echo esc_attr($post_schema_badge); ?>">Score: <?php echo esc_html((string) $post_schema_score); ?>%</span>
                        <span class="description">Last scan: <?php echo !empty($post_schema_report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $post_schema_report['scanned_at'])) : '-'; ?></span>
                    </p>
                    <?php if (!empty($post_schema_issues)) : ?>
                        <ul class="tk-list">
                            <?php foreach ($post_schema_issues as $issue) : ?>
                                <li><?php echo esc_html((string) $issue); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <div class="tk-table-scroll">
                    <table class="widefat striped tk-table">
                        <tbody>
                            <tr><th>Post</th><td><?php echo esc_html((string) ($post_schema_report['post_title'] ?? '')); ?></td></tr>
                            <tr><th>URL</th><td><a href="<?php echo esc_url((string) ($post_schema_report['url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($post_schema_report['url'] ?? '')); ?></a></td></tr>
                            <tr><th>HTTP</th><td><?php echo esc_html((string) ((int) ($post_schema_report['status'] ?? 0))); ?></td></tr>
                            <tr><th>JSON-LD</th><td><?php echo esc_html((string) ((int) ($post_schema['documents'] ?? 0))); ?> docs, <?php echo esc_html((string) ((int) ($post_schema['invalid'] ?? 0))); ?> invalid</td></tr>
                            <tr><th>Types</th><td><?php echo tk_geo_render_schema_type_badges($post_schema['types'] ?? array()); ?></td></tr>
                            <tr><th>Duplicates</th><td><?php echo !empty($post_schema['duplicates']) ? tk_geo_render_schema_type_badges($post_schema['duplicates'] ?? array()) : '<span class="tk-badge tk-on">None</span>'; ?></td></tr>
                            <?php if (!empty($post_schema_report['error'])) : ?><tr><th>Error</th><td><?php echo esc_html((string) $post_schema_report['error']); ?></td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            <?php else : ?>
                <p class="description">No post schema validation report yet.</p>
            <?php endif; ?>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="schema-duplicates" id="schema-duplicates">
            <h3>Schema Duplicate Detector</h3>
            <p>Scan homepage and recent public content for duplicate JSON-LD types or invalid JSON-LD blocks.</p>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_geo_schema_duplicate_scan'), 'tk_geo_schema_duplicate_scan')); ?>">Run Duplicate Detector</a>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_geo_schema_duplicate_fix'), 'tk_geo_schema_duplicate_fix')); ?>">Auto Fix Tool Kits Duplicates</a>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_geo_schema_duplicate_clear'), 'tk_geo_schema_duplicate_clear')); ?>">Clear Report</a>
            </p>
            <?php if (!empty($schema_duplicate_fix_report)) : ?>
                <?php
                $fix_actions = isset($schema_duplicate_fix_report['actions']) && is_array($schema_duplicate_fix_report['actions']) ? $schema_duplicate_fix_report['actions'] : array();
                $fix_remaining = isset($schema_duplicate_fix_report['remaining']) && is_array($schema_duplicate_fix_report['remaining']) ? $schema_duplicate_fix_report['remaining'] : array();
                ?>
                <div class="notice notice-info inline">
                    <p><strong>Auto Fix Result:</strong> <?php echo !empty($schema_duplicate_fix_report['fixed_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $schema_duplicate_fix_report['fixed_at'])) : '-'; ?></p>
                    <?php if (!empty($fix_actions)) : ?>
                        <ul class="tk-list">
                            <?php foreach ($fix_actions as $action) : ?>
                                <li><?php echo esc_html((string) $action); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else : ?>
                        <p>No Tool Kits schema setting needed to be changed.</p>
                    <?php endif; ?>
                    <?php if (!empty($fix_remaining)) : ?>
                        <ul class="tk-list">
                            <?php foreach ($fix_remaining as $remaining_item) : ?>
                                <li><?php echo esc_html((string) $remaining_item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($schema_duplicate_report)) : ?>
                <?php $duplicate_items = isset($schema_duplicate_report['items']) && is_array($schema_duplicate_report['items']) ? $schema_duplicate_report['items'] : array(); ?>
                <p class="description">Last scan: <?php echo !empty($schema_duplicate_report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $schema_duplicate_report['scanned_at'])) : '-'; ?> | Issues: <?php echo esc_html((string) ($schema_duplicate_report['issue_count'] ?? 0)); ?></p>
                <p class="description">Duplicate means the same schema <code>@type</code> appears more than once on a URL. If it is unintended, keep one canonical schema source and disable the overlapping theme/plugin output.</p>
                <table class="widefat striped tk-table">
                    <thead><tr><th>URL</th><th>Status</th><th>JSON-LD</th><th>Schema Types</th><th>Duplicate Types</th><th>Issue</th></tr></thead>
                    <tbody>
                    <?php foreach ($duplicate_items as $item) : ?>
                        <?php $duplicates = isset($item['duplicates']) && is_array($item['duplicates']) ? $item['duplicates'] : array(); ?>
                        <tr>
                            <td><a href="<?php echo esc_url((string) ($item['url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($item['url'] ?? '')); ?></a></td>
                            <td><?php echo esc_html((string) ((int) ($item['status'] ?? 0))); ?></td>
                            <td><?php echo esc_html((string) ((int) ($item['documents'] ?? 0))); ?> docs, <?php echo esc_html((string) ((int) ($item['invalid'] ?? 0))); ?> invalid</td>
                            <td><?php echo tk_geo_render_schema_type_badges($item['types'] ?? array()); ?></td>
                            <td><?php echo !empty($duplicates) ? tk_geo_render_schema_type_badges($duplicates) : '<span class="tk-badge tk-on">None</span>'; ?></td>
                            <td><?php echo !empty($item['issue']) ? esc_html((string) $item['issue']) : '<span class="tk-badge tk-on">OK</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="description">No duplicate schema report yet.</p>
            <?php endif; ?>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="crawler-preview" id="crawler-preview">
            <h3>Crawler Preview</h3>
            <p>Fetch a URL with known AI crawler user-agents and list which crawlers can see the page, metadata, and structured data.</p>
            <div class="tk-grid tk-grid-2" style="gap:12px;">
                <p>
                    <label>URL</label>
                    <input type="url" form="tk-geo-crawler-preview-form" name="crawler_preview_url" value="<?php echo esc_attr((string) ($crawler_preview['url'] ?? home_url('/'))); ?>">
                </p>
                <p style="display:flex; align-items:flex-end;">
                    <button class="button" form="tk-geo-crawler-preview-form">Preview Crawler Fetch</button>
                </p>
            </div>
            <?php if (!empty($crawler_preview)) : ?>
                <?php $schema_types = isset($crawler_preview['schema_types']) && is_array($crawler_preview['schema_types']) ? $crawler_preview['schema_types'] : array(); ?>
                <?php $preview_agents = isset($crawler_preview['agents']) && is_array($crawler_preview['agents']) ? $crawler_preview['agents'] : array(); ?>
                <p class="tk-report-actions">
                    <button type="button" class="button tk-geo-export-pdf" data-report-target="tk-geo-crawler-report" data-report-title="Crawler Preview Report">Export Report PDF</button>
                </p>
                <div id="tk-geo-crawler-report">
                <?php if (!empty($preview_agents)) : ?>
                    <h4>Visible User Agents</h4>
                    <div class="tk-table-scroll">
                    <table class="widefat striped tk-table">
                        <thead><tr><th>Visible</th><th>User Agent</th><th>HTTP</th><th>Title</th><th>Canonical</th><th>Schema</th><th>HTML</th><th>Error</th></tr></thead>
                        <tbody>
                        <?php foreach ($preview_agents as $row) : ?>
                            <?php
                            $row_schema_types = isset($row['schema_types']) && is_array($row['schema_types']) ? $row['schema_types'] : array();
                            $row_ok = !empty($row['ok']);
                            ?>
                            <tr>
                                <td><span class="tk-badge <?php echo $row_ok ? 'tk-on' : ''; ?>"><?php echo $row_ok ? 'Visible' : 'Issue'; ?></span></td>
                                <td><strong><?php echo esc_html((string) ($row['agent'] ?? '')); ?></strong><br><span class="description"><?php echo esc_html((string) ($row['label'] ?? '')); ?></span></td>
                                <td><?php echo esc_html((string) ((int) ($row['status'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) ($row['title'] ?? '')); ?></td>
                                <td><code><?php echo esc_html((string) ($row['canonical'] ?? '')); ?></code></td>
                                <td><?php echo esc_html((string) ((int) ($row['schema_documents'] ?? 0))); ?> docs, <?php echo esc_html((string) ((int) ($row['schema_invalid'] ?? 0))); ?> invalid<br><?php echo tk_geo_render_schema_type_badges($row_schema_types); ?></td>
                                <td><?php echo esc_html((string) ((int) ($row['body_bytes'] ?? 0))); ?> bytes</td>
                                <td><?php echo esc_html((string) ($row['error'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
                <h4 style="margin-top:18px;">First Visible Fetch Summary</h4>
                <div class="tk-table-scroll">
                <table class="widefat striped tk-table">
                    <tbody>
                        <tr><th>User Agent</th><td><code><?php echo esc_html((string) ($crawler_preview['agent'] ?? '')); ?></code></td></tr>
                        <tr><th>Status</th><td><?php echo esc_html((string) ($crawler_preview['status'] ?? 0)); ?> <?php echo !empty($crawler_preview['ok']) ? '<span class="tk-badge tk-on">OK</span>' : '<span class="tk-badge">Check</span>'; ?></td></tr>
                        <tr><th>Title</th><td><?php echo esc_html((string) ($crawler_preview['title'] ?? '')); ?></td></tr>
                        <tr><th>Description</th><td><?php echo esc_html((string) ($crawler_preview['description'] ?? '')); ?></td></tr>
                        <tr><th>Canonical</th><td><code><?php echo esc_html((string) ($crawler_preview['canonical'] ?? '')); ?></code></td></tr>
                        <tr><th>Robots</th><td><?php echo esc_html((string) ($crawler_preview['robots'] ?? '')); ?></td></tr>
                        <tr><th>Schema</th><td><?php echo esc_html((string) ($crawler_preview['schema_documents'] ?? 0)); ?> docs, <?php echo esc_html((string) ($crawler_preview['schema_invalid'] ?? 0)); ?> invalid<br><?php echo tk_geo_render_schema_type_badges($schema_types); ?></td></tr>
                        <tr><th>HTML Size</th><td><?php echo esc_html((string) ($crawler_preview['body_bytes'] ?? 0)); ?> bytes</td></tr>
                        <?php if (!empty($crawler_preview['error'])) : ?><tr><th>Error</th><td><?php echo esc_html((string) $crawler_preview['error']); ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
                </div>
                </div>
            <?php else : ?>
                <p class="description">No crawler preview yet.</p>
            <?php endif; ?>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="ai-access" id="ai-access">
            <h3>AI Crawler Accessibility Review</h3>
            <p>Review whether AI search and assistant crawlers can access public content, read robots.txt, discover sitemap URLs, and consume structured data.</p>
            <div class="notice notice-info inline" style="margin:12px 0;padding:10px 12px;">
                <p style="margin:0 0 6px;"><strong>About Google AI Overviews</strong></p>
                <p style="margin:0 0 6px;">AI Overviews and AI Mode are grounded from the regular Google Search index via <code>Googlebot</code>. There is no dedicated AI Overviews crawler and no robots.txt token that opts a site in or out of them.</p>
                <p style="margin:0 0 6px;">Blocking <code>Google-Extended</code> only limits Gemini and Vertex AI training and grounding &mdash; it does <strong>not</strong> remove the site from AI Overviews.</p>
                <p style="margin:0;">To opt out, use the property-level control in <a href="https://search.google.com/search-console/settings" target="_blank" rel="noopener noreferrer">Search Console &rsaquo; Settings &rsaquo; Search generative AI</a>, or limit on-page snippet usage with <code>nosnippet</code> / <code>max-snippet</code> (note: those also remove ordinary search snippets).</p>
            </div>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_geo_ai_access_scan'), 'tk_geo_ai_access_scan')); ?>">Run AI Access Review</a>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_geo_ai_access_clear'), 'tk_geo_ai_access_clear')); ?>">Clear Review</a>
            </p>
            <?php if (!empty($ai_access_report)) : ?>
                <?php
                $score = isset($ai_access_report['score']) ? (int) $ai_access_report['score'] : 0;
                $badge_class = $score >= 80 ? 'tk-on' : ($score >= 60 ? 'tk-warn' : '');
                $checks = isset($ai_access_report['checks']) && is_array($ai_access_report['checks']) ? $ai_access_report['checks'] : array();
                $robots_results = isset($ai_access_report['robots_results']) && is_array($ai_access_report['robots_results']) ? $ai_access_report['robots_results'] : array();
                $crawler_fetch_results = isset($ai_access_report['crawler_fetch_results']) && is_array($ai_access_report['crawler_fetch_results']) ? $ai_access_report['crawler_fetch_results'] : array();
                $issues = isset($ai_access_report['issues']) && is_array($ai_access_report['issues']) ? $ai_access_report['issues'] : array();
                ?>
                <p class="tk-report-actions">
                    <button type="button" class="button tk-geo-export-pdf" data-report-target="tk-geo-ai-access-report" data-report-title="AI Crawler Accessibility Review">Export Report PDF</button>
                </p>
                <div id="tk-geo-ai-access-report">
                <p>
                    <span class="tk-badge <?php echo esc_attr($badge_class); ?>">Score: <?php echo esc_html((string) $score); ?>%</span>
                    <span class="description">Last scan: <?php echo !empty($ai_access_report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $ai_access_report['scanned_at'])) : '-'; ?></span>
                </p>
                <?php if (!empty($issues)) : ?>
                    <ul class="tk-list">
                        <?php foreach ($issues as $issue) : ?>
                            <li><?php echo esc_html((string) $issue); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <div class="tk-table-scroll">
                <table class="widefat striped tk-table" style="margin-top:14px;">
                    <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
                    <tbody>
                    <?php foreach ($checks as $check) : ?>
                        <?php
                        $status = sanitize_key((string) ($check['status'] ?? 'warn'));
                        $class = $status === 'ok' ? 'tk-on' : ($status === 'warn' ? 'tk-warn' : '');
                        ?>
                        <tr>
                            <td><?php echo esc_html((string) ($check['name'] ?? '')); ?></td>
                            <td><span class="tk-badge <?php echo esc_attr($class); ?>"><?php echo esc_html(strtoupper($status)); ?></span></td>
                            <td><?php echo esc_html((string) ($check['detail'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php if (!empty($crawler_fetch_results)) : ?>
                    <h4 style="margin-top:18px;">Crawler Fetch Checklist</h4>
                    <div class="tk-table-scroll">
                    <table class="widefat striped tk-table">
                        <thead><tr><th>Done</th><th>Crawler</th><th>HTTP</th><th>HTML</th><th>Structured Data</th><th>Error</th></tr></thead>
                        <tbody>
                        <?php foreach ($crawler_fetch_results as $result) : ?>
                            <?php $ok = !empty($result['ok']); ?>
                            <tr>
                                <td><span class="tk-badge <?php echo $ok ? 'tk-on' : ''; ?>"><?php echo $ok ? 'Crawled' : 'Issue'; ?></span></td>
                                <td><strong><?php echo esc_html((string) ($result['agent'] ?? '')); ?></strong><br><span class="description"><?php echo esc_html((string) ($result['label'] ?? '')); ?></span></td>
                                <td><?php echo esc_html((string) ((int) ($result['status'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) ((int) ($result['body_bytes'] ?? 0))); ?> bytes</td>
                                <td><?php echo esc_html((string) ((int) ($result['schema_documents'] ?? 0))); ?> docs</td>
                                <td><?php echo esc_html((string) ($result['error'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>

                <?php if (!empty($robots_results)) : ?>
                    <h4 style="margin-top:18px;">AI Crawler robots.txt Access</h4>
                    <div class="tk-table-scroll">
                    <table class="widefat striped tk-table">
                        <thead><tr><th>Crawler</th><th>Purpose</th><th>Access</th><th>Matched Rule</th></tr></thead>
                        <tbody>
                        <?php foreach ($robots_results as $result) : ?>
                            <?php
                            $status = sanitize_key((string) ($result['status'] ?? 'warn'));
                            $class = $status === 'ok' ? 'tk-on' : ($status === 'warn' ? 'tk-warn' : '');
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html((string) ($result['agent'] ?? '')); ?></strong><br><span class="description"><?php echo esc_html((string) ($result['label'] ?? '')); ?></span></td>
                                <td>
                                    <?php echo esc_html((string) ($result['purpose'] ?? '')); ?>
                                    <?php if (!empty($result['note'])) : ?>
                                        <br><span class="description"><?php echo esc_html((string) $result['note']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="tk-badge <?php echo esc_attr($class); ?>"><?php echo !empty($result['allowed']) ? 'Allowed' : 'Blocked'; ?></span></td>
                                <td><code><?php echo esc_html((string) ($result['matched'] ?? '')); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
                </div>
            <?php else : ?>
                <p class="description">No AI crawler review yet.</p>
            <?php endif; ?>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="preview">
            <h3>Preview</h3>
            <?php if ($preview_json !== '') : ?>
                <textarea readonly rows="16" style="width:100%; max-width:100%; font-family:monospace;"><?php echo esc_textarea($preview_json); ?></textarea>
            <?php else : ?>
                <p class="description">No JSON-LD generated yet.</p>
            <?php endif; ?>
        </div>
            </div>
        </div>

        <p style="margin-top:16px;">
            <button class="button button-primary button-hero">Save GEO Settings</button>
        </p>
    </form>
    <script>
    (function(){
        var siteTitle = <?php echo wp_json_encode(get_bloginfo('name'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?> || 'Site';
        var select = document.getElementById('tk-geo-itemlist-post-type');
        if (select) {
        select.addEventListener('change', function(){
            var url = new URL(window.location.href);
            url.searchParams.set('page', 'tool-kits-geo');
            url.searchParams.set('geo_post_type', select.value);
            url.hash = 'itemlist';
            window.location.href = url.toString();
        });
        }

        var postSelect = document.getElementById('tk-geo-itemlist-post-ids');
        var selectAll = document.getElementById('tk-geo-itemlist-select-all');
        var clearAll = document.getElementById('tk-geo-itemlist-clear');
        var triggerPostSelectChange = function() {
            if (window.jQuery && postSelect) {
                window.jQuery(postSelect).trigger('change');
            } else if (postSelect) {
                postSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        };
        if (postSelect && selectAll) {
            selectAll.addEventListener('click', function(){
                Array.prototype.forEach.call(postSelect.options, function(option){ option.selected = true; });
                triggerPostSelectChange();
            });
        }
        if (postSelect && clearAll) {
            clearAll.addEventListener('click', function(){
                Array.prototype.forEach.call(postSelect.options, function(option){ option.selected = false; });
                triggerPostSelectChange();
            });
        }

        var wrapper = document.querySelector('.tk-geo-tabs');
        var activeInput = document.getElementById('tk-geo-active-tab');
        if (wrapper) {
            var activate = function(panelId) {
                wrapper.querySelectorAll('.tk-tab-panel').forEach(function(panel){
                    panel.classList.toggle('is-active', panel.getAttribute('data-panel-id') === panelId);
                });
                wrapper.querySelectorAll('.tk-tabs-nav-button').forEach(function(button){
                    button.classList.toggle('is-active', button.getAttribute('data-panel') === panelId);
                });
                if (activeInput) {
                    activeInput.value = panelId;
                }
            };
            wrapper.querySelectorAll('.tk-tabs-nav-button').forEach(function(button){
                button.addEventListener('click', function(){
                    var panelId = button.getAttribute('data-panel');
                    activate(panelId);
                    if (history.replaceState) {
                        history.replaceState(null, '', '#' + panelId);
                    }
                });
            });
            var initial = window.location.hash ? window.location.hash.substring(1) : '';
            if (initial && wrapper.querySelector('.tk-tab-panel[data-panel-id="' + initial + '"]')) {
                activate(initial);
            }
        }

        var rows = document.getElementById('tk-geo-faq-rows');
        var add = document.getElementById('tk-geo-faq-add');
        var faqLanguage = 'id';
        var faqLanguages = [];
        var faqTabs = document.createElement('div');
        faqTabs.setAttribute('role', 'tablist');
        faqTabs.setAttribute('aria-label', 'FAQ language');
        faqTabs.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;margin:16px 0;';
        var activateFaqLanguage = function(tag) {
            faqLanguage = tag;
            faqTabs.querySelectorAll('button').forEach(function(button) {
                var selected = button.dataset.language === tag;
                button.setAttribute('aria-selected', String(selected));
                button.classList.toggle('button-primary', selected);
            });
            rows.querySelectorAll('.tk-geo-faq-row').forEach(function(row) {
                row.style.display = row.querySelector('[name="geo_faq_language[]"]').value === tag ? 'grid' : 'none';
            });
            var hasRows = Array.prototype.some.call(rows.querySelectorAll('.tk-geo-faq-row'), function(row) {
                return row.querySelector('[name="geo_faq_language[]"]').value === tag;
            });
            if (!hasRows && rowCount() < 50) { add.click(); }
        };
        var addFaqLanguage = function(tag) {
            if (faqLanguages.indexOf(tag) !== -1) { return; }
            faqLanguages.push(tag);
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'button';
            button.setAttribute('role', 'tab');
            button.dataset.language = tag;
            button.textContent = tag ? tag.toUpperCase() : 'Default';
            button.addEventListener('click', function() { activateFaqLanguage(tag); });
            faqTabs.appendChild(button);
        };
        if (rows && add) {
            rows.before(faqTabs);
            addFaqLanguage('id');
            addFaqLanguage('en');
            rows.querySelectorAll('[name="geo_faq_language[]"]').forEach(function(field) {
                field.value = field.value.trim().toLowerCase().replace(/_/g, '-');
                field.type = 'hidden';
                addFaqLanguage(field.value);
            });
            var languageTools = document.createElement('div');
            languageTools.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;';
            var languageCode = document.createElement('input');
            languageCode.type = 'text';
            languageCode.placeholder = 'Language code: fr, en-sg';
            languageCode.setAttribute('aria-label', 'Additional FAQ language');
            var addLanguageButton = document.createElement('button');
            addLanguageButton.type = 'button';
            addLanguageButton.className = 'button';
            addLanguageButton.textContent = 'Add Language';
            addLanguageButton.addEventListener('click', function() {
                var tag = languageCode.value.trim().toLowerCase().replace(/_/g, '-');
                if (!/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/.test(tag)) {
                    languageCode.setCustomValidity('Use a language code such as id, en, or en-sg.');
                    languageCode.reportValidity();
                    return;
                }
                languageCode.setCustomValidity('');
                addFaqLanguage(tag);
                activateFaqLanguage(tag);
                languageCode.value = '';
            });
            languageCode.addEventListener('input', function() { languageCode.setCustomValidity(''); });
            languageCode.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') { event.preventDefault(); addLanguageButton.click(); }
            });
            languageTools.append(languageCode, addLanguageButton);
            rows.before(languageTools);
        }
        var rowCount = function() {
            return rows ? rows.querySelectorAll('.tk-geo-faq-row').length : 0;
        };
        var updateFaqLimit = function() {
            if (add) {
                add.disabled = rowCount() >= 50;
            }
        };
        var wireRemove = function(button) {
            button.addEventListener('click', function(){
                var row = button.closest('.tk-geo-faq-row');
                if (row && rowCount() > 1) {
                    row.remove();
                } else if (row) {
                    row.querySelectorAll('input, textarea').forEach(function(field){ field.value = ''; });
                    row.querySelector('[name="geo_faq_language[]"]').value = faqLanguage;
                }
                updateFaqLimit();
            });
        };
        if (rows) {
            rows.querySelectorAll('.tk-geo-faq-remove').forEach(wireRemove);
        }
        if (rows && add) {
            updateFaqLimit();
            add.addEventListener('click', function(){
                if (rowCount() >= 50) { return; }
                var row = document.createElement('div');
                row.className = 'tk-geo-faq-row';
                row.style.cssText = 'display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1.5fr) auto; gap:12px; margin-bottom:12px; align-items:start;';
                row.innerHTML = '<div><input type="text" name="geo_faq_language[]" value="" placeholder="Language: id, en, en-sg" aria-label="FAQ language" style="width:100%;margin-bottom:8px;"><input type="text" name="geo_faq_question[]" value="" placeholder="Question" style="width:100%;"></div><textarea name="geo_faq_answer[]" rows="2" placeholder="Answer"></textarea><button type="button" class="button tk-geo-faq-remove">Remove</button>';
                rows.insertBefore(row, rows.querySelector('.description'));
                var rowLanguage = row.querySelector('[name="geo_faq_language[]"]');
                rowLanguage.type = 'hidden';
                rowLanguage.value = faqLanguage;
                wireRemove(row.querySelector('.tk-geo-faq-remove'));
                updateFaqLimit();
                row.querySelector('[name="geo_faq_question[]"]').focus();
            });
            activateFaqLanguage('id');
        }

        var sectionRows = document.getElementById('tk-geo-llms-section-rows');
        var sectionAdd = document.getElementById('tk-geo-llms-section-add');
        var sectionCount = function() {
            return sectionRows ? sectionRows.querySelectorAll('.tk-geo-llms-section-row').length : 0;
        };
        var wireSectionRemove = function(button) {
            button.addEventListener('click', function(){
                var row = button.closest('.tk-geo-llms-section-row');
                if (row && sectionCount() > 1) {
                    row.remove();
                } else if (row) {
                    row.querySelectorAll('input, textarea').forEach(function(field){ field.value = ''; });
                }
            });
        };
        if (sectionRows) {
            sectionRows.querySelectorAll('.tk-geo-llms-section-remove').forEach(wireSectionRemove);
        }
        if (sectionRows && sectionAdd) {
            sectionAdd.addEventListener('click', function(){
                if (sectionCount() >= 20) { return; }
                var row = document.createElement('div');
                row.className = 'tk-geo-llms-section-row';
                row.style.cssText = 'display:grid; grid-template-columns:minmax(180px,0.5fr) minmax(0,1.5fr) auto; gap:12px; margin-bottom:12px; align-items:start;';
                row.innerHTML = '<input type="text" name="geo_llms_section_title[]" value="" placeholder="Section title"><textarea name="geo_llms_section_content[]" rows="3" placeholder="Section content"></textarea><button type="button" class="button tk-geo-llms-section-remove">Remove</button>';
                sectionRows.insertBefore(row, sectionRows.querySelector('.description'));
                wireSectionRemove(row.querySelector('.tk-geo-llms-section-remove'));
            });
        }

        document.querySelectorAll('.tk-geo-export-pdf').forEach(function(button){
            button.addEventListener('click', function(){
                var targetId = button.getAttribute('data-report-target');
                var title = button.getAttribute('data-report-title') || 'Tool Kits GEO Report';
                var target = targetId ? document.getElementById(targetId) : null;
                if (!target) { return; }
                var printWindow = window.open('', '_blank');
                if (!printWindow) { return; }
                var cleanTitle = title.replace(/[<>&"]/g, '');
                var cleanSiteTitle = String(siteTitle).replace(/[<>&"]/g, '');
                var printedAt = new Date().toLocaleString().replace(/[<>&"]/g, '');
                printWindow.document.open();
                printWindow.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + cleanSiteTitle + ' - ' + cleanTitle + '</title><style>body{font-family:Arial,sans-serif;color:#172033;margin:24px;}h1{font-size:22px;margin:0 0 4px;}h2{font-size:15px;margin:0 0 18px;color:#4b5563;font-weight:600;}h4{margin:18px 0 8px;}table{border-collapse:collapse;width:100%;font-size:12px;margin-bottom:16px;}th,td{border:1px solid #d7dde8;padding:8px;vertical-align:top;text-align:left;}th{background:#f6f8fb;}code{white-space:normal;word-break:break-word;}.tk-badge{display:inline-block;margin:0 4px 4px 0;padding:2px 7px;border-radius:4px;background:#eef2f7;font-weight:700;}.tk-on{background:#dcfce7;color:#166534;}.tk-warn{background:#fef3c7;color:#92400e;}.description{color:#667085;}.tk-schema-type-list{display:block;line-height:1.9;}@media print{button{display:none;}}</style></head><body><h1>' + cleanSiteTitle + '</h1><h2>' + cleanTitle + ' | Exported ' + printedAt + '</h2>' + target.innerHTML + '</body></html>');
                printWindow.document.close();
                printWindow.focus();
                setTimeout(function(){ printWindow.print(); }, 250);
            });
        });
    })();
    jQuery(function($){
        var select = $('#tk-geo-itemlist-post-ids');
        if (!select.length || !$.fn.select2) { return; }
        select.select2({
            width: '100%',
            placeholder: 'Select posts',
            closeOnSelect: false
        });
    });
    </script>
    <?php
}
