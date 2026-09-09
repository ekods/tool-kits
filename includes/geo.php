<?php
if (!defined('ABSPATH')) { exit; }

function tk_geo_init() {
    add_action('admin_post_tk_geo_save', 'tk_geo_save');
    add_action('admin_post_tk_geo_ai_access_scan', 'tk_geo_ai_access_scan');
    add_action('admin_post_tk_geo_ai_access_clear', 'tk_geo_ai_access_clear');
    add_action('admin_post_tk_geo_schema_duplicate_scan', 'tk_geo_schema_duplicate_scan');
    add_action('admin_post_tk_geo_schema_duplicate_clear', 'tk_geo_schema_duplicate_clear');
    add_action('admin_post_tk_geo_crawler_preview', 'tk_geo_crawler_preview_handler');
    add_action('init', 'tk_geo_llms_maybe_render', 1);
    add_action('wp_head', 'tk_geo_render_head_jsonld', 3);
    add_shortcode('tool_kits_geo_faq', 'tk_geo_faq_shortcode');
}

function tk_geo_enabled(): bool {
    return tk_license_features_enabled() && (int) tk_get_option('geo_enabled', 0) === 1;
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
        $normalized[] = array(
            'question' => $question,
            'answer' => $answer,
        );
        if (count($normalized) >= 20) {
            break;
        }
    }
    return $normalized;
}

function tk_geo_selected_itemlist_ids(): array {
    $raw = tk_get_option('geo_itemlist_post_ids', array());
    if (!is_array($raw)) {
        return array();
    }
    return array_values(array_unique(array_filter(array_map('intval', $raw))));
}

function tk_geo_current_url(): string {
    $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '';
    if ($uri === '') {
        return home_url('/');
    }
    return home_url($uri);
}

function tk_geo_build_faq_schema(): array {
    if ((int) tk_get_option('geo_faq_enabled', 0) !== 1) {
        return array();
    }
    $items = tk_geo_normalize_faq_items(tk_get_option('geo_faq_items', array()));
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
        'inLanguage' => get_bloginfo('language'),
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
    $items = tk_geo_normalize_faq_items(tk_get_option('geo_faq_items', array()));
    if (empty($items)) {
        return '';
    }

    $atts = shortcode_atts(array(
        'title' => 'FAQ',
    ), is_array($atts) ? $atts : array(), 'tool_kits_geo_faq');

    ob_start();
    ?>
    <section class="tk-geo-faq" itemscope itemtype="https://schema.org/FAQPage">
        <?php if (trim((string) $atts['title']) !== '') : ?>
            <h2><?php echo esc_html((string) $atts['title']); ?></h2>
        <?php endif; ?>
        <?php foreach ($items as $item) : ?>
            <article class="tk-geo-faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                <h3 itemprop="name"><?php echo esc_html((string) $item['question']); ?></h3>
                <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                    <div itemprop="text"><?php echo wpautop(esc_html((string) $item['answer'])); ?></div>
                </div>
            </article>
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
        $args['post__in'] = $ids;
        $args['orderby'] = 'post__in';
        $args['numberposts'] = count($ids);
    }

    $posts = get_posts($args);
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
        $items[] = array(
            '@type' => 'ListItem',
            'position' => $position,
            'url' => $url,
            'name' => get_the_title($post) ?: ('#' . (int) $post->ID),
        );
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

function tk_geo_build_schema_documents(): array {
    $docs = array();
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

function tk_geo_build_llms_auto_text(): string {
    $lines = array();
    $lines[] = '# ' . get_bloginfo('name');
    $description = trim((string) get_bloginfo('description'));
    if ($description !== '') {
        $lines[] = '';
        $lines[] = '> ' . $description;
    }
    $lines[] = '';
    $lines[] = 'Site: ' . home_url('/');
    $lines[] = 'Sitemap: ' . tk_geo_sitemap_url();
    $lines[] = '';
    $lines[] = '## Important URLs';
    foreach (tk_geo_review_urls(12) as $url) {
        $lines[] = '- ' . $url;
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
                    $lines[] = '- ' . ($name !== '' ? $name . ': ' : '') . $url;
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
            'purpose' => 'Gemini training and grounding control token',
            'recommended' => 'policy',
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

function tk_geo_run_schema_duplicate_detector(): array {
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

function tk_geo_schema_duplicate_scan(): void {
    tk_require_admin_post('tk_geo_schema_duplicate_scan');
    tk_update_option('geo_schema_duplicate_report', tk_geo_run_schema_duplicate_detector());
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo', 'tk_geo_schema_duplicates' => 1), admin_url('admin.php')) . '#schema-duplicates');
    exit;
}

function tk_geo_schema_duplicate_clear(): void {
    tk_require_admin_post('tk_geo_schema_duplicate_clear');
    tk_update_option('geo_schema_duplicate_report', array());
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
    $allowed_tabs = array('overview', 'custom-jsonld', 'faqpage', 'itemlist', 'llms', 'schema-duplicates', 'crawler-preview', 'ai-access', 'preview');
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
    foreach ($questions as $index => $question) {
        $faq_items[] = array(
            'question' => wp_unslash((string) $question),
            'answer' => isset($answers[$index]) ? wp_unslash((string) $answers[$index]) : '',
        );
    }

    $post_ids = isset($_POST['geo_itemlist_post_ids']) && is_array($_POST['geo_itemlist_post_ids']) ? $_POST['geo_itemlist_post_ids'] : array();
    $post_ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $post_ids)))), 0, 50);

    tk_update_option('geo_enabled', !empty($_POST['geo_enabled']) ? 1 : 0);
    tk_update_option('geo_custom_jsonld', $custom_json);
    tk_update_option('geo_faq_enabled', !empty($_POST['geo_faq_enabled']) ? 1 : 0);
    tk_update_option('geo_faq_items', tk_geo_normalize_faq_items($faq_items));
    tk_update_option('geo_itemlist_enabled', !empty($_POST['geo_itemlist_enabled']) ? 1 : 0);
    tk_update_option('geo_itemlist_name', sanitize_text_field((string) tk_post('geo_itemlist_name', '')));
    tk_update_option('geo_itemlist_description', sanitize_textarea_field((string) tk_post('geo_itemlist_description', '')));
    tk_update_option('geo_itemlist_post_type', $post_type);
    tk_update_option('geo_itemlist_post_ids', $post_ids);
    tk_update_option('geo_itemlist_limit', max(1, min(50, (int) tk_post('geo_itemlist_limit', 10))));
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
    if (!empty($_POST['geo_llms_generate_draft'])) {
        tk_update_option('geo_llms_manual', tk_geo_build_llms_auto_text());
        $active_tab = 'llms';
    }

    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-geo', 'tk_saved' => 1), admin_url('admin.php')) . '#' . $active_tab);
    exit;
}

function tk_render_geo_panel(): void {
    if (!tk_is_admin_user()) return;

    $enabled = (int) tk_get_option('geo_enabled', 0);
    $custom_json = (string) tk_get_option('geo_custom_jsonld', '');
    $faq_enabled = (int) tk_get_option('geo_faq_enabled', 0);
    $faq_items = tk_geo_normalize_faq_items(tk_get_option('geo_faq_items', array()));
    $itemlist_enabled = (int) tk_get_option('geo_itemlist_enabled', 0);
    $itemlist_name = (string) tk_get_option('geo_itemlist_name', '');
    $itemlist_description = (string) tk_get_option('geo_itemlist_description', '');
    $itemlist_post_type = sanitize_key((string) tk_get_option('geo_itemlist_post_type', 'post'));
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
    $crawler_preview = tk_get_option('geo_crawler_preview', array());
    if (!is_array($crawler_preview)) {
        $crawler_preview = array();
    }
    $crawler_agents = tk_geo_ai_crawler_agents();
    $llms_enabled = (int) tk_get_option('geo_llms_enabled', 0);
    $llms_mode = sanitize_key((string) tk_get_option('geo_llms_mode', 'auto'));
    $llms_manual = (string) tk_get_option('geo_llms_manual', '');
    $llms_preview = tk_geo_build_llms_text();
    while (count($faq_items) < 3) {
        $faq_items[] = array('question' => '', 'answer' => '');
    }
    ?>
    <form id="tk-geo-crawler-preview-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php tk_nonce_field('tk_geo_crawler_preview'); ?>
        <input type="hidden" name="action" value="tk_geo_crawler_preview">
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
            <div id="tk-geo-faq-rows" style="margin-top:16px;">
                <?php foreach ($faq_items as $index => $item) : ?>
                    <div class="tk-geo-faq-row" style="display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1.5fr) auto; gap:12px; margin-bottom:12px; align-items:start;">
                        <input type="text" name="geo_faq_question[]" value="<?php echo esc_attr((string) $item['question']); ?>" placeholder="Question">
                        <textarea name="geo_faq_answer[]" rows="2" placeholder="Answer"><?php echo esc_textarea((string) $item['answer']); ?></textarea>
                        <button type="button" class="button tk-geo-faq-remove">Remove</button>
                    </div>
                <?php endforeach; ?>
                <p class="description">Save up to 20 rows. Blank rows are ignored.</p>
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
                    <p class="description">If no posts are selected, the generator uses latest published content from the chosen post type.</p>
                </div>
                <div>
                    <label>Select Posts</label>
                    <select id="tk-geo-itemlist-post-ids" name="geo_itemlist_post_ids[]" multiple size="12" style="width:100%; max-width:100%;">
                        <?php foreach ($selector_posts as $post) : ?>
                            <option value="<?php echo esc_attr((string) $post->ID); ?>" <?php selected(in_array((int) $post->ID, $selected_ids, true)); ?>>
                                <?php echo esc_html((get_the_title($post) ?: ('#' . (int) $post->ID)) . ' (' . $post->post_name . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Hold Cmd/Ctrl to select multiple items.</p>
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
            <label>Preview</label>
            <textarea readonly rows="12" style="width:100%; max-width:100%; font-family:monospace;"><?php echo esc_textarea($llms_preview); ?></textarea>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="schema-duplicates" id="schema-duplicates">
            <h3>Schema Duplicate Detector</h3>
            <p>Scan homepage and recent public content for duplicate JSON-LD types or invalid JSON-LD blocks.</p>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_geo_schema_duplicate_scan'), 'tk_geo_schema_duplicate_scan')); ?>">Run Duplicate Detector</a>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_geo_schema_duplicate_clear'), 'tk_geo_schema_duplicate_clear')); ?>">Clear Report</a>
            </p>
            <?php if (!empty($schema_duplicate_report)) : ?>
                <?php $duplicate_items = isset($schema_duplicate_report['items']) && is_array($schema_duplicate_report['items']) ? $schema_duplicate_report['items'] : array(); ?>
                <p class="description">Last scan: <?php echo !empty($schema_duplicate_report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $schema_duplicate_report['scanned_at'])) : '-'; ?> | Issues: <?php echo esc_html((string) ($schema_duplicate_report['issue_count'] ?? 0)); ?></p>
                <table class="widefat striped">
                    <thead><tr><th>URL</th><th>Status</th><th>JSON-LD</th><th>Duplicate Types</th><th>Issue</th></tr></thead>
                    <tbody>
                    <?php foreach ($duplicate_items as $item) : ?>
                        <?php $duplicates = isset($item['duplicates']) && is_array($item['duplicates']) ? $item['duplicates'] : array(); ?>
                        <tr>
                            <td><a href="<?php echo esc_url((string) ($item['url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($item['url'] ?? '')); ?></a></td>
                            <td><?php echo esc_html((string) ((int) ($item['status'] ?? 0))); ?></td>
                            <td><?php echo esc_html((string) ((int) ($item['documents'] ?? 0))); ?> docs, <?php echo esc_html((string) ((int) ($item['invalid'] ?? 0))); ?> invalid</td>
                            <td><?php echo !empty($duplicates) ? esc_html(wp_json_encode($duplicates, JSON_UNESCAPED_SLASHES)) : '<span class="tk-badge tk-on">None</span>'; ?></td>
                            <td><?php echo esc_html((string) ($item['issue'] ?? '')); ?></td>
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
                                <td><?php echo esc_html((string) ((int) ($row['schema_documents'] ?? 0))); ?> docs, <?php echo esc_html((string) ((int) ($row['schema_invalid'] ?? 0))); ?> invalid<br><code><?php echo esc_html(wp_json_encode($row_schema_types, JSON_UNESCAPED_SLASHES)); ?></code></td>
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
                        <tr><th>Schema</th><td><?php echo esc_html((string) ($crawler_preview['schema_documents'] ?? 0)); ?> docs, <?php echo esc_html((string) ($crawler_preview['schema_invalid'] ?? 0)); ?> invalid | <code><?php echo esc_html(wp_json_encode($schema_types, JSON_UNESCAPED_SLASHES)); ?></code></td></tr>
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
                                <td><?php echo esc_html((string) ($result['purpose'] ?? '')); ?></td>
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
        var rowCount = function() {
            return rows ? rows.querySelectorAll('.tk-geo-faq-row').length : 0;
        };
        var wireRemove = function(button) {
            button.addEventListener('click', function(){
                var row = button.closest('.tk-geo-faq-row');
                if (row && rowCount() > 1) {
                    row.remove();
                } else if (row) {
                    row.querySelectorAll('input, textarea').forEach(function(field){ field.value = ''; });
                }
            });
        };
        if (rows) {
            rows.querySelectorAll('.tk-geo-faq-remove').forEach(wireRemove);
        }
        if (rows && add) {
            add.addEventListener('click', function(){
                if (rowCount() >= 20) { return; }
                var row = document.createElement('div');
                row.className = 'tk-geo-faq-row';
                row.style.cssText = 'display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1.5fr) auto; gap:12px; margin-bottom:12px; align-items:start;';
                row.innerHTML = '<input type="text" name="geo_faq_question[]" value="" placeholder="Question"><textarea name="geo_faq_answer[]" rows="2" placeholder="Answer"></textarea><button type="button" class="button tk-geo-faq-remove">Remove</button>';
                rows.insertBefore(row, rows.querySelector('.description'));
                wireRemove(row.querySelector('.tk-geo-faq-remove'));
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
                printWindow.document.open();
                printWindow.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + title.replace(/[<>&"]/g, '') + '</title><style>body{font-family:Arial,sans-serif;color:#172033;margin:24px;}h1{font-size:22px;margin:0 0 18px;}h4{margin:18px 0 8px;}table{border-collapse:collapse;width:100%;font-size:12px;margin-bottom:16px;}th,td{border:1px solid #d7dde8;padding:8px;vertical-align:top;text-align:left;}th{background:#f6f8fb;}code{white-space:normal;word-break:break-word;}.tk-badge{font-weight:700;}.description{color:#667085;}@media print{button{display:none;}}</style></head><body><h1>' + title.replace(/[<>&"]/g, '') + '</h1>' + target.innerHTML + '</body></html>');
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
