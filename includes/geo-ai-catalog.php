<?php
if (!defined('ABSPATH')) { exit; }

function tk_geo_ai_catalog_enabled(): bool {
    return tk_license_features_enabled() && (int) tk_get_option('geo_ai_catalog_enabled', 0) === 1;
}

function tk_geo_ai_catalog_example_entries(): array {
    return array(
        array('id' => 'chatgpt', 'name' => 'ChatGPT', 'provider' => 'OpenAI', 'category' => 'AI Assistant',
            'description' => 'AI assistant untuk penulisan, coding, dan analisis.', 'website' => 'https://chatgpt.com',
            'features' => array('Text Generation', 'Code Generation', 'Image Understanding', 'Data Analysis'),
            'pricing' => 'Freemium', 'tags' => array('chatbot', 'productivity', 'coding'), 'status' => 'active'),
        array('id' => 'claude', 'name' => 'Claude', 'provider' => 'Anthropic', 'category' => 'AI Assistant',
            'description' => 'AI assistant untuk penulisan, analisis, dan coding.', 'website' => 'https://claude.ai',
            'features' => array('Text Generation', 'Code Generation', 'Document Analysis'),
            'pricing' => 'Freemium', 'tags' => array('chatbot', 'coding', 'writing'), 'status' => 'active'),
    );
}

/** Accept either an entries array or a complete catalog document. */
function tk_geo_ai_catalog_parse_entries(string $json) {
    if (trim($json) === '') { return array(); }
    $decoded = json_decode($json);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('tk_catalog_json', 'Catalog JSON is invalid: ' . json_last_error_msg());
    }
    $entries = is_object($decoded) ? ($decoded->catalog ?? null) : $decoded;
    if (!is_array($entries)) { return new WP_Error('tk_catalog_array', 'Enter a JSON array of entries or a document containing a catalog array.'); }
    $result = array(); $seen = array();
    foreach ($entries as $index => $entry) {
        if (!is_object($entry)) { return new WP_Error('tk_catalog_entry', 'Catalog entry ' . ($index + 1) . ' must be an object.'); }
        $item = array();
        foreach (array('id', 'name', 'provider', 'category', 'description', 'website', 'pricing', 'status') as $field) {
            $value = $entry->$field ?? ($field === 'status' ? 'active' : '');
            if (!is_string($value)) { return new WP_Error('tk_catalog_field', 'Catalog entry ' . ($index + 1) . ': ' . $field . ' must be text.'); }
            $item[$field] = trim($field === 'description' ? sanitize_textarea_field($value) : sanitize_text_field($value));
        }
        if ($item['id'] === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $item['id']) || $item['name'] === '') {
            return new WP_Error('tk_catalog_identity', 'Each catalog entry needs a name and an ID containing only letters, numbers, hyphens, or underscores.');
        }
        if (isset($seen[$item['id']])) { return new WP_Error('tk_catalog_duplicate', 'Duplicate catalog ID: ' . $item['id']); }
        $seen[$item['id']] = true;
        $website = esc_url_raw($item['website'], array('http', 'https'));
        $parts = wp_parse_url($website);
        if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true) || isset($parts['user']) || isset($parts['pass'])) {
            return new WP_Error('tk_catalog_website', 'Catalog entry ' . $item['id'] . ' needs an HTTP or HTTPS website URL without credentials.');
        }
        $item['website'] = $website;
        foreach (array('features', 'tags') as $field) {
            $values = $entry->$field ?? array();
            if (!is_array($values)) { return new WP_Error('tk_catalog_list', 'Catalog entry ' . $item['id'] . ': ' . $field . ' must be an array of text values.'); }
            $item[$field] = array();
            foreach ($values as $value) {
                if (!is_string($value)) { return new WP_Error('tk_catalog_list_value', 'Catalog entry ' . $item['id'] . ': ' . $field . ' must contain text values.'); }
                $value = trim(sanitize_text_field($value));
                if ($value !== '') { $item[$field][] = $value; }
            }
            $item[$field] = array_values(array_unique($item[$field]));
        }
        $result[] = array('id' => $item['id'], 'name' => $item['name'], 'provider' => $item['provider'],
            'category' => $item['category'], 'description' => $item['description'], 'website' => $item['website'],
            'features' => $item['features'], 'pricing' => $item['pricing'], 'tags' => $item['tags'], 'status' => $item['status']);
    }
    return $result;
}

/** Validate before the shared GEO save handler writes any settings. */
function tk_geo_ai_catalog_posted_settings() {
    if (!empty($_POST['geo_ai_catalog_generate_auto'])) {
        // Generate from the website even when a custom catalog is currently active.
        // Save an editable snapshot so the editor and public output stay in sync.
        $catalog = tk_geo_build_ai_catalog(true);
        $json = wp_json_encode($catalog['catalog']);
        if (!is_string($json)) { return new WP_Error('tk_catalog_generate', 'The generated catalog could not be encoded as JSON.'); }
        $entries = tk_geo_ai_catalog_parse_entries($json);
        return is_wp_error($entries) ? $entries : array('mode' => 'manual', 'entries' => $entries);
    }
    if (empty($_POST['geo_ai_catalog_settings_present']) && empty($_POST['geo_ai_catalog_use_example'])) { return null; }
    $mode = tk_post('geo_ai_catalog_mode', 'auto');
    if (!is_string($mode) || !in_array($mode, array('auto', 'manual'), true)) { return new WP_Error('tk_catalog_mode', 'Choose automatic website content or a custom catalog.'); }
    if (!empty($_POST['geo_ai_catalog_use_example'])) {
        return array('mode' => 'manual', 'entries' => tk_geo_ai_catalog_example_entries());
    }
    $json = tk_post('geo_ai_catalog_entries', '[]');
    if (!is_string($json)) { return new WP_Error('tk_catalog_input', 'Enter catalog entries as JSON text.'); }
    $entries = tk_geo_ai_catalog_parse_entries($json);
    return is_wp_error($entries) ? $entries : array('mode' => $mode, 'entries' => $entries);
}

function tk_geo_ai_catalog_save_settings(array $settings): void {
    if ($settings['mode'] !== tk_get_option('geo_ai_catalog_mode', 'auto') || $settings['entries'] !== tk_get_option('geo_ai_catalog_entries', array())) {
        tk_update_option('geo_ai_catalog_updated_at', wp_date('Y-m-d'));
    }
    tk_update_option('geo_ai_catalog_mode', $settings['mode']);
    tk_update_option('geo_ai_catalog_entries', $settings['entries']);
}

/** A migrated site's saved report must not become evidence for its new hostname. */
function tk_geo_ai_catalog_radar_report(): array {
    $report = tk_get_option('geo_ai_radar_report', array());
    if (!is_array($report) || !is_string($report['url'] ?? null)
        || untrailingslashit($report['url']) !== untrailingslashit(home_url('/'))) {
        return array();
    }
    return $report;
}

function tk_geo_ai_catalog_site_name(): string {
    $name = trim(wp_strip_all_tags(get_bloginfo('name')));
    return $name !== '' ? $name : (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
}

function tk_geo_ai_catalog_radar_entries(array $report): array {
    $statuses = array();
    foreach (is_array($report['engines'] ?? null) ? $report['engines'] : array() as $engine) {
        if (!is_array($engine) || !is_string($engine['name'] ?? null)) { continue; }
        $status = $engine['status'] ?? null;
        $statuses[$engine['name']] = in_array($status, array('eligible', 'partial', 'blocked', 'unknown'), true) ? $status : 'unknown';
    }
    $entries = array();
    $site_name = tk_geo_ai_catalog_site_name();
    foreach (tk_geo_ai_radar_platforms() as $id => $platform) {
        $entries[] = array(
            'id' => 'ai-radar-' . $id,
            'name' => $platform['name'],
            'provider' => $platform['provider'],
            'category' => $platform['category'],
            'description' => $platform['description'] . ' for ' . $site_name . ' (' . esc_url_raw(home_url('/')) . '). Status reflects the last saved AI Radar access check for this website, not indexing or citation.',
            'website' => $platform['website'],
            'features' => array_map(static fn($agent) => 'Crawler access: ' . $agent, $platform['agents']),
            'pricing' => '',
            'tags' => array('ai-radar', 'crawler-access'),
            'status' => $statuses[$platform['name']] ?? 'unknown',
        );
    }
    return $entries;
}

/**
 * A catalog using the version/last_updated/catalog format in both modes.
 * Summaries are plain text; shortcodes and content filters are never executed.
 */
function tk_geo_build_ai_catalog(bool $force_auto = false): array {
    $catalog = array(
        'version' => '1.0.0',
        'last_updated' => (string) tk_get_option('geo_ai_catalog_updated_at', ''),
        'catalog' => array(),
    );
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $catalog['last_updated'])) { $catalog['last_updated'] = wp_date('Y-m-d'); }
    if (!$force_auto && tk_get_option('geo_ai_catalog_mode', 'auto') === 'manual') {
        $json = wp_json_encode(tk_get_option('geo_ai_catalog_entries', array()));
        $entries = is_string($json) ? tk_geo_ai_catalog_parse_entries($json) : array();
        $catalog['catalog'] = is_wp_error($entries) ? array() : $entries;
        return $catalog;
    }

    $report = tk_geo_ai_catalog_radar_report();
    $catalog['catalog'][] = array(
        'id' => 'site-home',
        'name' => tk_geo_ai_catalog_site_name(),
        'provider' => tk_geo_ai_catalog_site_name(),
        'category' => 'Website',
        'description' => wp_strip_all_tags(get_bloginfo('description'), true),
        'website' => esc_url_raw(home_url('/')),
        'features' => array(), 'pricing' => '', 'tags' => array('website'), 'status' => 'active',
    );
    $catalog['catalog'] = array_merge($catalog['catalog'], tk_geo_ai_catalog_radar_entries($report));
    $scan_time = $report['scanned_at'] ?? 0;
    if (is_numeric($scan_time) && (int) $scan_time > 0) {
        $scan_date = wp_date('Y-m-d', (int) $scan_time);
        if ($scan_date > $catalog['last_updated']) { $catalog['last_updated'] = $scan_date; }
    }

    $post_types = get_post_types(array('public' => true, 'publicly_queryable' => true), 'names');
    unset($post_types['attachment']);
    if (empty($post_types)) {
        return $catalog;
    }

    $seen_urls = array(home_url('/') => true);
    $latest = 0;
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
            $title = trim(wp_strip_all_tags(get_the_title($post)));
            $catalog['catalog'][] = array(
                'id' => $post->post_type . '-' . (int) $post->ID,
                'name' => $title !== '' ? $title : 'Untitled ' . $post->post_type . ' #' . (int) $post->ID,
                'provider' => tk_geo_ai_catalog_site_name(),
                'category' => $post->post_type,
                'description' => wp_trim_words($summary, 55, '…'),
                'website' => esc_url_raw($url),
                'features' => array(),
                'pricing' => '',
                'tags' => array(),
                'status' => 'active',
            );
            $modified = strtotime((string) get_post_modified_time('c', true, $post));
            if ($modified !== false) { $latest = max($latest, $modified); }
        }
        if (count($query->posts) < 200) {
            break;
        }
    }
    if ($latest > 0) {
        $modified_date = wp_date('Y-m-d', $latest);
        if ($modified_date > $catalog['last_updated']) { $catalog['last_updated'] = $modified_date; }
    }
    return $catalog;
}

function tk_geo_build_ai_catalog_json(): string {
    $json = wp_json_encode(tk_geo_build_ai_catalog(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json . "\n" : "{}\n";
}

/**
 * ARD describes callable resources, rather than a directory of pages or AI products.
 * No callable tools are supplied by this generator, so do not invent declarations.
 * The legacy fields keep this document compatible with Lighthouse 13.5 as well.
 */
function tk_geo_build_ard_manifest(): array {
    return array(
        'specVersion' => '1.0',
        'host' => array('displayName' => wp_strip_all_tags(get_bloginfo('name'))),
        'entries' => array(),
    );
}

function tk_geo_ai_catalog_discovery_headers(array $headers): array {
    if (!tk_geo_ai_catalog_enabled()) { return $headers; }
    $links = '<' . esc_url_raw(home_url('/.well-known/ard.json')) . '>; rel="ard"; type="application/json", '
        . '<' . esc_url_raw(home_url('/.well-known/ai-catalog.json')) . '>; rel="ai-catalog"; type="application/json"';
    $headers['Link'] = empty($headers['Link']) ? $links : $headers['Link'] . ', ' . $links;
    return $headers;
}

function tk_geo_ai_catalog_discovery_head(): void {
    if (!tk_geo_ai_catalog_enabled()) { return; }
    echo '<link rel="ard" type="application/json" href="' . esc_url(home_url('/.well-known/ard.json')) . '">' . "\n";
    echo '<link rel="ai-catalog" type="application/json" href="' . esc_url(home_url('/.well-known/ai-catalog.json')) . '">' . "\n";
}

function tk_geo_ai_catalog_maybe_render(): void {
    if (is_admin() || !tk_geo_ai_catalog_enabled()) {
        return;
    }
    $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    $filename = '';
    foreach (array('/ai-catalog.json', '/.well-known/ai-catalog.json', '/.well-known/ard.json') as $endpoint) {
        $endpoint_path = (string) wp_parse_url(home_url($endpoint), PHP_URL_PATH);
        if (untrailingslashit($path) === untrailingslashit($endpoint_path)) {
            $filename = basename($endpoint);
            $is_ard = $endpoint !== '/ai-catalog.json';
            break;
        }
    }
    if ($filename === '') { return; }
    status_header(200);
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    if (isset($_GET['download']) && $_GET['download'] === '1') {
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    }
    if ($is_ard) {
        echo wp_json_encode(tk_geo_build_ard_manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo tk_geo_build_ai_catalog_json();
    }
    exit;
}
