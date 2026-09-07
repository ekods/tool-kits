<?php
if (!defined('ABSPATH')) { exit; }

function tk_seo_opt_init() {
    add_action('admin_post_tk_seo_opt_save', 'tk_seo_opt_save');
    add_action('admin_post_tk_seo_redirect_add', 'tk_seo_redirect_add');
    add_action('admin_post_tk_seo_redirect_delete', 'tk_seo_redirect_delete');
    add_action('admin_post_tk_seo_links_scan', 'tk_seo_links_scan');
    add_action('admin_post_tk_seo_links_clear', 'tk_seo_links_clear');
    add_action('admin_post_tk_seo_canonical_scan', 'tk_seo_canonical_scan');
    add_action('admin_post_tk_seo_canonical_clear', 'tk_seo_canonical_clear');
    add_action('admin_post_tk_seo_index_add', 'tk_seo_index_add');
    add_action('admin_post_tk_seo_index_delete', 'tk_seo_index_delete');
    add_action('admin_post_tk_seo_content_audit_scan', 'tk_seo_content_audit_scan');
    add_action('admin_post_tk_seo_content_audit_clear', 'tk_seo_content_audit_clear');
    add_action('admin_post_tk_seo_geo_audit_scan', 'tk_seo_geo_audit_scan');
    add_action('admin_post_tk_seo_geo_audit_clear', 'tk_seo_geo_audit_clear');

    add_action('init', 'tk_seo_sitemap_maybe_render', 1);
    add_action('template_redirect', 'tk_seo_redirect_maybe_handle', 1);
    add_action('add_meta_boxes', 'tk_seo_add_geo_meta_boxes');
    add_action('save_post', 'tk_seo_save_geo_meta_box', 10, 2);

    add_action('wp_head', 'tk_seo_render_head_tags', 2);
    add_filter('wp_robots', 'tk_seo_filter_robots');
}

function tk_seo_tools_enabled() {
    if (!tk_license_features_enabled()) {
        return false;
    }
    return (int) tk_get_option('seo_enabled', 0) === 1;
}

function tk_seo_opt_enabled() {
    if (is_admin()) {
        return false;
    }
    if (!tk_seo_tools_enabled()) {
        return false;
    }
    if (tk_seo_has_third_party_plugin()) {
        return false;
    }
    return true;
}

function tk_seo_has_third_party_plugin() {
    return defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION') || defined('SEOPRESS_VERSION');
}

function tk_seo_has_theme_managed_canonical(): bool {
    return function_exists('cst_seo') || function_exists('cst_geo_canonical_url');
}

function tk_seo_filter_robots($robots) {
    if (!tk_seo_opt_enabled()) {
        return $robots;
    }
    if (!is_array($robots)) {
        $robots = array();
    }

    if ((int) tk_get_option('seo_noindex_search', 1) === 1 && is_search()) {
        $robots['noindex'] = true;
    }
    if ((int) tk_get_option('seo_noindex_404', 1) === 1 && is_404()) {
        $robots['noindex'] = true;
    }
    if ((int) tk_get_option('seo_noindex_paged_archives', 1) === 1 && is_paged() && !is_singular()) {
        $robots['noindex'] = true;
    }
    if (!empty($robots['noindex'])) {
        $robots['follow'] = true;
    }
    return $robots;
}

function tk_seo_render_head_tags() {
    if (!tk_seo_opt_enabled()) {
        return;
    }

    $url = tk_seo_current_url();
    $title = wp_get_document_title();
    $description = tk_seo_generate_description();

    if ((int) tk_get_option('seo_canonical_enabled', 1) === 1 && $url !== '' && !is_singular() && !tk_seo_has_theme_managed_canonical()) {
        echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
    }

    if ((int) tk_get_option('seo_meta_desc_enabled', 1) === 1 && $description !== '') {
        echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
    }

    if ((int) tk_get_option('seo_og_enabled', 1) === 1) {
        $image = tk_seo_og_image_url();
        echo '<meta property="og:locale" content="' . esc_attr(get_locale()) . '">' . "\n";
        echo '<meta property="og:type" content="' . (is_singular() ? 'article' : 'website') . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        if ($description !== '') {
            echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
        }
        if ($url !== '') {
            echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        }
        echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
        if ($image !== '') {
            echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
        }
    }

    if ((int) tk_get_option('seo_schema_enabled', 1) === 1) {
        $schema = tk_seo_build_schema_graph($url, $title, $description);
        if (!empty($schema)) {
            echo '<script type="application/ld+json"' . tk_csp_nonce_attr() . '>' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
        }
    }
}

function tk_seo_generate_description() {
    $description = '';
    if (is_singular()) {
        $description = trim(wp_strip_all_tags(get_the_excerpt()));
        if ($description === '') {
            $post = get_post();
            if (is_object($post) && !empty($post->post_content)) {
                $description = trim(wp_strip_all_tags((string) $post->post_content));
            }
        }
    } elseif (is_home() || is_front_page()) {
        $description = (string) get_bloginfo('description');
    } elseif (is_category() || is_tag() || is_tax()) {
        $description = trim(wp_strip_all_tags(term_description()));
    }

    if ($description === '') {
        $description = (string) get_bloginfo('description');
    }

    $description = preg_replace('/\s+/', ' ', (string) $description);
    $description = trim((string) $description);
    if (strlen($description) > 160) {
        $description = trim(substr($description, 0, 157)) . '...';
    }
    return $description;
}

function tk_seo_og_image_url() {
    if (is_singular()) {
        $thumb = get_the_post_thumbnail_url(get_queried_object_id(), 'full');
        if (is_string($thumb) && $thumb !== '') {
            return $thumb;
        }
    }
    $custom_logo_id = get_theme_mod('custom_logo');
    if ($custom_logo_id) {
        $logo = wp_get_attachment_image_url((int) $custom_logo_id, 'full');
        if (is_string($logo) && $logo !== '') {
            return $logo;
        }
    }
    return '';
}

function tk_seo_current_url() {
    $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '';
    if ($uri === '') {
        return home_url('/');
    }
    return home_url($uri);
}

function tk_seo_add_geo_meta_boxes(): void {
    if (!tk_is_admin_user()) {
        return;
    }

    $post_types = get_post_types(array('public' => true), 'names');
    unset($post_types['attachment']);
    foreach ($post_types as $post_type) {
        add_meta_box(
            'tk-seo-geo-signals',
            'Tool Kits GEO Signals',
            'tk_seo_render_geo_meta_box',
            $post_type,
            'side',
            'default'
        );
    }
}

function tk_seo_render_geo_meta_box($post): void {
    $post_id = is_object($post) ? (int) $post->ID : 0;
    $references = get_post_meta($post_id, '_tk_geo_reference_urls', true);
    $related_ids = get_post_meta($post_id, '_tk_geo_related_posts', true);
    $references = is_array($references) ? $references : array();
    $related_ids = is_array($related_ids) ? array_map('intval', $related_ids) : array();
    $post_types = get_post_types(array('public' => true), 'names');
    unset($post_types['attachment']);
    $choices = get_posts(array(
        'post_type' => array_values($post_types),
        'post_status' => 'publish',
        'posts_per_page' => 80,
        'post__not_in' => array($post_id),
        'orderby' => 'modified',
        'order' => 'DESC',
        'no_found_rows' => true,
    ));

    wp_nonce_field('tk_seo_geo_meta_box', 'tk_seo_geo_meta_nonce');
    ?>
    <p><strong>References / Sources</strong></p>
    <textarea name="tk_geo_reference_urls" rows="5" style="width:100%;" placeholder="One source URL per line"><?php echo esc_textarea(implode("\n", $references)); ?></textarea>
    <p class="description">Used for GEO audit and Schema.org <code>citation</code>.</p>

    <p><strong>Related Content</strong></p>
    <select name="tk_geo_related_posts[]" multiple size="6" style="width:100%;">
        <?php foreach ($choices as $choice) : ?>
            <option value="<?php echo esc_attr((string) $choice->ID); ?>" <?php selected(in_array((int) $choice->ID, $related_ids, true)); ?>>
                <?php echo esc_html(get_the_title($choice) ?: ('#' . $choice->ID)); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <p class="description">Manual related content for audit and Schema.org <code>relatedLink</code>.</p>
    <?php
}

function tk_seo_save_geo_meta_box($post_id, $post): void {
    if (!is_object($post) || wp_is_post_revision((int) $post_id) || wp_is_post_autosave((int) $post_id)) {
        return;
    }
    if (!isset($_POST['tk_seo_geo_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['tk_seo_geo_meta_nonce'])), 'tk_seo_geo_meta_box')) {
        return;
    }
    if (!current_user_can('edit_post', (int) $post_id)) {
        return;
    }

    $raw_refs = isset($_POST['tk_geo_reference_urls']) ? (string) wp_unslash($_POST['tk_geo_reference_urls']) : '';
    $refs = array();
    foreach (preg_split('/\r\n|\r|\n/', $raw_refs) ?: array() as $line) {
        $url = esc_url_raw(trim((string) $line));
        if ($url !== '' && (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0)) {
            $refs[] = $url;
        }
    }
    $refs = array_values(array_unique($refs));
    if (!empty($refs)) {
        update_post_meta((int) $post_id, '_tk_geo_reference_urls', $refs);
    } else {
        delete_post_meta((int) $post_id, '_tk_geo_reference_urls');
    }

    $related = isset($_POST['tk_geo_related_posts']) && is_array($_POST['tk_geo_related_posts']) ? $_POST['tk_geo_related_posts'] : array();
    $related = array_slice(array_values(array_unique(array_filter(array_map('intval', $related)))), 0, 10);
    if (!empty($related)) {
        update_post_meta((int) $post_id, '_tk_geo_related_posts', $related);
    } else {
        delete_post_meta((int) $post_id, '_tk_geo_related_posts');
    }
}

function tk_seo_collect_urls_from_value($value): array {
    $urls = array();
    $value = maybe_unserialize($value);

    if (is_array($value)) {
        foreach ($value as $item) {
            $urls = array_merge($urls, tk_seo_collect_urls_from_value($item));
        }
        return $urls;
    }

    if (!is_scalar($value)) {
        return $urls;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return $urls;
    }

    if (filter_var($value, FILTER_VALIDATE_URL)) {
        $urls[] = $value;
    }
    if (preg_match_all('~https?://[^\s<>"\']+~i', $value, $matches)) {
        $urls = array_merge($urls, $matches[0]);
    }

    return $urls;
}

function tk_seo_reference_urls($post_id): array {
    $post_id = (int) $post_id;
    if ($post_id <= 0) {
        return array();
    }

    $keys = array(
        '_tk_geo_reference_urls',
        '_source_url',
        'source_url',
        'source',
        'sources',
        'reference_url',
        'reference',
        'references',
        'citation',
        'citations',
    );

    $urls = array();
    foreach ($keys as $key) {
        foreach (get_post_meta($post_id, $key, false) as $value) {
            $urls = array_merge($urls, tk_seo_collect_urls_from_value($value));
        }
    }

    $urls = array_filter(array_map('esc_url_raw', $urls));
    $urls = array_filter($urls, function($url) {
        return strpos((string) $url, 'http://') === 0 || strpos((string) $url, 'https://') === 0;
    });

    return array_values(array_unique($urls));
}

function tk_seo_author_data($post): array {
    $post = is_object($post) ? $post : get_post((int) $post);
    $author_id = is_object($post) ? (int) $post->post_author : 0;
    $site_name = (string) get_bloginfo('name');
    $name = $author_id > 0 ? (string) get_the_author_meta('display_name', $author_id) : '';
    if ($name === '') {
        $name = $site_name;
    }

    $description = $author_id > 0 ? (string) get_the_author_meta('description', $author_id) : '';
    if ($description === '') {
        $description = (string) get_bloginfo('description');
    }

    return array(
        'type' => $author_id > 0 ? 'Person' : 'Organization',
        'name' => $name,
        'description' => trim(wp_strip_all_tags($description)),
        'url' => $author_id > 0 ? get_author_posts_url($author_id) : home_url('/'),
    );
}

function tk_seo_theme_option($key, $fallback = '') {
    if (function_exists('myprefix_get_theme_option')) {
        $value = myprefix_get_theme_option($key);
        if (is_scalar($value) && trim((string) $value) !== '') {
            return $value;
        }
    }
    return $fallback;
}

function tk_seo_option_lines($key, $limit = 20): array {
    $value = tk_seo_theme_option($key, '');
    $lines = is_array($value) ? $value : preg_split('/[\r\n,]+/', (string) $value);
    $lines = array_filter(array_map(function($line) {
        return trim(wp_strip_all_tags((string) $line));
    }, is_array($lines) ? $lines : array()));

    return array_slice(array_values(array_unique($lines)), 0, (int) $limit);
}

function tk_seo_organization_data(): array {
    $logo = tk_seo_theme_option('themes_logo_color', '');
    if ($logo === '') {
        $logo = tk_seo_theme_option('themes_logo_secondary', '');
    }
    if ($logo === '') {
        $logo = tk_seo_theme_option('themes_favicon', '');
    }
    if ($logo === '') {
        $logo = tk_seo_og_image_url();
    }

    $same_as = array_filter(array_map('esc_url_raw', array(
        tk_seo_theme_option('themes_linkedin', ''),
        tk_seo_theme_option('themes_instagram', ''),
        tk_seo_theme_option('themes_facebook', ''),
        tk_seo_theme_option('themes_x', ''),
        tk_seo_theme_option('themes_youtube', ''),
        tk_seo_theme_option('themes_tiktok', ''),
    )));

    $telephone = array_filter(array_map('trim', explode(',', (string) tk_seo_theme_option('themes_telephone', ''))));
    $email = sanitize_email((string) tk_seo_theme_option('themes_email', get_option('admin_email')));
    $address = trim(wp_strip_all_tags((string) tk_seo_theme_option('themes_address', '')));
    $expertise = tk_seo_option_lines('themes_expertise', 20);
    $service_name = trim(wp_strip_all_tags((string) tk_seo_theme_option('themes_service_name', get_bloginfo('name'))));

    if ($service_name !== '') {
        array_unshift($expertise, $service_name);
    }

    return array(
        'name' => (string) get_bloginfo('name'),
        'description' => trim(wp_strip_all_tags((string) tk_seo_theme_option('themes_description', get_bloginfo('description')))),
        'url' => home_url('/'),
        'contact_url' => home_url('/contact/'),
        'logo' => esc_url_raw((string) $logo),
        'same_as' => array_values(array_unique($same_as)),
        'telephone' => array_values($telephone),
        'email' => is_email($email) ? $email : '',
        'address' => $address,
        'founders' => tk_seo_option_lines('themes_founder', 8),
        'team' => tk_seo_option_lines('themes_team', 12),
        'expertise' => array_values(array_unique(array_filter($expertise))),
        'service_name' => $service_name,
        'service_description' => trim(wp_strip_all_tags((string) tk_seo_theme_option('themes_service_description', get_bloginfo('description')))),
    );
}

function tk_seo_related_urls($post_id, $limit = 3): array {
    $post_id = (int) $post_id;
    $post_type = $post_id > 0 ? (string) get_post_type($post_id) : '';
    if ($post_id <= 0 || $post_type === '') {
        return array();
    }

    $manual_related = get_post_meta($post_id, '_tk_geo_related_posts', true);
    if (is_array($manual_related) && !empty($manual_related)) {
        $urls = array();
        foreach (array_slice(array_map('intval', $manual_related), 0, (int) $limit) as $related_id) {
            if ($related_id <= 0 || get_post_status($related_id) !== 'publish') {
                continue;
            }
            $url = get_permalink($related_id);
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }
        if (!empty($urls)) {
            return array_values(array_unique($urls));
        }
    }

    $taxonomies = get_object_taxonomies($post_type);
    $taxonomy = in_array('category', $taxonomies, true) ? 'category' : '';
    if ($taxonomy === '' && !empty($taxonomies)) {
        $taxonomy = (string) reset($taxonomies);
    }
    if ($taxonomy === '') {
        return array();
    }

    $terms = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'ids'));
    if (is_wp_error($terms) || empty($terms)) {
        return array();
    }

    $related = get_posts(array(
        'post_type' => $post_type,
        'post_status' => 'publish',
        'posts_per_page' => (int) $limit,
        'post__not_in' => array($post_id),
        'tax_query' => array(
            array(
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => $terms,
            ),
        ),
        'fields' => 'ids',
        'no_found_rows' => true,
    ));

    $urls = array();
    foreach ($related as $related_id) {
        $url = get_permalink((int) $related_id);
        if (is_string($url) && $url !== '') {
            $urls[] = $url;
        }
    }

    return $urls;
}

function tk_seo_build_schema_graph($url, $title, $description) {
    $site_name = (string) get_bloginfo('name');
    $org_id = trailingslashit(home_url('/')) . '#organization';
    $website_id = trailingslashit(home_url('/')) . '#website';
    $entity = tk_seo_organization_data();
    $graph = array();

    $organization = array(
        '@type' => 'Organization',
        '@id' => $org_id,
        'url' => $entity['url'],
        'name' => $entity['name'],
        'description' => $entity['description'],
    );
    if ($entity['logo'] !== '') {
        $organization['logo'] = array(
            '@type' => 'ImageObject',
            'url' => $entity['logo'],
        );
    }
    if (!empty($entity['same_as'])) {
        $organization['sameAs'] = $entity['same_as'];
    }
    if (!empty($entity['telephone']) || $entity['email'] !== '' || $entity['contact_url'] !== '') {
        $contact_point = array(
            '@type' => 'ContactPoint',
            'contactType' => 'customer support',
            'areaServed' => 'ID',
            'availableLanguage' => array('en', 'id'),
        );
        if ($entity['contact_url'] !== '') {
            $contact_point['url'] = $entity['contact_url'];
        }
        if (!empty($entity['telephone'])) {
            $contact_point['telephone'] = reset($entity['telephone']);
        }
        if ($entity['email'] !== '') {
            $contact_point['email'] = $entity['email'];
        }
        $organization['contactPoint'] = array($contact_point);
    }
    if ($entity['address'] !== '') {
        $organization['address'] = array(
            '@type' => 'PostalAddress',
            'streetAddress' => $entity['address'],
        );
        $organization['location'] = $organization['address'];
    }
    if (!empty($entity['founders'])) {
        $organization['founder'] = array_map(function($name) {
            return array('@type' => 'Person', 'name' => $name);
        }, $entity['founders']);
    }
    if (!empty($entity['team'])) {
        $organization['employee'] = array_map(function($name) {
            return array('@type' => 'Person', 'name' => $name);
        }, $entity['team']);
    }
    if (!empty($entity['expertise'])) {
        $organization['knowsAbout'] = $entity['expertise'];
    }
    $graph[] = $organization;

    $graph[] = array(
        '@type' => 'WebSite',
        '@id' => $website_id,
        'url' => home_url('/'),
        'name' => $site_name,
        'description' => (string) get_bloginfo('description'),
        'publisher' => array('@id' => $org_id),
    );

    $webpage = array(
        '@type' => 'WebPage',
        '@id' => $url !== '' ? $url . '#webpage' : trailingslashit(home_url('/')) . '#webpage',
        'url' => $url,
        'name' => (string) $title,
        'isPartOf' => array('@id' => $website_id),
        'about' => array('@id' => $org_id),
    );
    if ($description !== '') {
        $webpage['description'] = $description;
    }
    $graph[] = $webpage;

    if (is_singular()) {
        $post = get_post();
        if (is_object($post)) {
            $author = tk_seo_author_data($post);
            $references = tk_seo_reference_urls((int) $post->ID);
            $related = tk_seo_related_urls((int) $post->ID);
            $article = array(
                '@type' => 'Article',
                '@id' => $url !== '' ? $url . '#article' : '',
                'headline' => (string) get_the_title($post),
                'datePublished' => get_the_date(DATE_W3C, $post),
                'dateModified' => get_the_modified_date(DATE_W3C, $post),
                'mainEntityOfPage' => array('@id' => $url !== '' ? $url . '#webpage' : ''),
                'author' => array(
                    '@type' => $author['type'],
                    'name' => $author['name'],
                    'url' => $author['url'],
                    'description' => $author['description'],
                ),
                'publisher' => array('@id' => $org_id),
            );
            $image = tk_seo_og_image_url();
            if ($image !== '') {
                $article['image'] = array($image);
            }
            if (!empty($references)) {
                $article['citation'] = $references;
            }
            if (!empty($related)) {
                $article['relatedLink'] = $related;
            }
            $graph[] = $article;
        }
    }

    if ($entity['service_name'] !== '') {
        $service = array(
            '@type' => 'Service',
            '@id' => trailingslashit(home_url('/')) . '#service',
            'name' => $entity['service_name'],
            'description' => $entity['service_description'],
            'provider' => array('@id' => $org_id),
            'areaServed' => array(
                '@type' => 'Country',
                'name' => 'Indonesia',
            ),
        );
        if (!empty($entity['expertise'])) {
            $service['serviceType'] = $entity['expertise'];
        }
        $graph[] = $service;
    }

    return array(
        '@context' => 'https://schema.org',
        '@graph' => $graph,
    );
}

function tk_seo_normalize_path($path) {
    $path = is_string($path) ? trim($path) : '';
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        $path = (string) wp_parse_url($path, PHP_URL_PATH);
    }
    $qpos = strpos($path, '?');
    if ($qpos !== false) {
        $path = substr($path, 0, $qpos);
    }
    $path = '/' . ltrim($path, '/');
    $path = preg_replace('#/+#', '/', $path);
    return untrailingslashit($path) === '' ? '/' : untrailingslashit($path);
}

function tk_seo_get_redirect_rules() {
    $raw = tk_get_option('seo_redirect_rules', array());
    if (!is_array($raw)) {
        return array();
    }
    $rules = array();
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $from = tk_seo_normalize_path(isset($item['from']) ? (string) $item['from'] : '');
        $to = isset($item['to']) ? trim((string) $item['to']) : '';
        $status = isset($item['status']) ? (int) $item['status'] : 301;
        if ($from === '' || ($to === '' && $status !== 410)) {
            continue;
        }
        if (!in_array($status, array(301, 302, 410), true)) {
            $status = 301;
        }
        $rules[] = array(
            'id' => isset($item['id']) ? sanitize_key((string) $item['id']) : wp_generate_password(12, false, false),
            'from' => $from,
            'to' => $to,
            'status' => $status,
            'enabled' => isset($item['enabled']) ? (int) $item['enabled'] : 1,
            'hits' => isset($item['hits']) ? (int) $item['hits'] : 0,
            'last' => isset($item['last']) ? (int) $item['last'] : 0,
        );
    }
    return $rules;
}

function tk_seo_redirect_maybe_handle() {
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    if (!tk_seo_tools_enabled()) {
        return;
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($request_uri === '') {
        return;
    }
    $request_path = tk_seo_normalize_path((string) wp_parse_url($request_uri, PHP_URL_PATH));
    if ($request_path === '') {
        return;
    }

    $rules = tk_seo_get_redirect_rules();
    if (empty($rules)) {
        return;
    }

    foreach ($rules as $index => $rule) {
        if ((int) $rule['enabled'] !== 1) {
            continue;
        }
        if (!hash_equals((string) $rule['from'], (string) $request_path)) {
            continue;
        }

        $status = (int) $rule['status'];
        $rules[$index]['hits'] = isset($rules[$index]['hits']) ? (int) $rules[$index]['hits'] + 1 : 1;
        $rules[$index]['last'] = time();
        tk_update_option('seo_redirect_rules', $rules);

        if ($status === 410) {
            status_header(410);
            nocache_headers();
            wp_die('Gone', '410 Gone', array('response' => 410));
        }

        $target = trim((string) $rule['to']);
        if ($target === '') {
            return;
        }
        if (!preg_match('#^https?://#i', $target)) {
            $target = home_url(tk_seo_normalize_path($target));
        }
        if ($target === tk_seo_current_url()) {
            return;
        }

        wp_redirect($target, $status === 302 ? 302 : 301, 'Tool Kits SEO');
        exit;
    }
}

function tk_seo_redirect_add() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_seo_redirect_add');

    $from = tk_seo_normalize_path((string) tk_post('redirect_from', ''));
    $to = trim((string) tk_post('redirect_to', ''));
    $status = (int) tk_post('redirect_status', 301);
    if (!in_array($status, array(301, 302, 410), true)) {
        $status = 301;
    }

    if ($from === '' || ($to === '' && $status !== 410)) {
        wp_redirect(add_query_arg(array('page' => 'tool-kits-seo', 'tk_seo_redirect_error' => 1), admin_url('admin.php')) . '#redirects');
        exit;
    }

    if ($status !== 410 && !preg_match('#^https?://#i', $to)) {
        $to = tk_seo_normalize_path($to);
    }

    $rules = tk_seo_get_redirect_rules();
    $rules[] = array(
        'id' => wp_generate_password(12, false, false),
        'from' => $from,
        'to' => $to,
        'status' => $status,
        'enabled' => 1,
        'hits' => 0,
        'last' => 0,
    );
    tk_update_option('seo_redirect_rules', $rules);

    wp_redirect(add_query_arg(array('page' => 'tool-kits-seo', 'tk_seo_redirect_added' => 1), admin_url('admin.php')) . '#redirects');
    exit;
}

function tk_seo_redirect_delete() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_seo_redirect_delete');

    $id = sanitize_key((string) tk_post('id', ''));
    $rules = tk_seo_get_redirect_rules();
    $filtered = array();
    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        if ((string) ($rule['id'] ?? '') === $id) {
            continue;
        }
        $filtered[] = $rule;
    }
    tk_update_option('seo_redirect_rules', $filtered);

    wp_redirect(add_query_arg(array('page' => 'tool-kits-seo', 'tk_seo_redirect_deleted' => 1), admin_url('admin.php')) . '#redirects');
    exit;
}

function tk_seo_redirect_suggestions() {
    $log = tk_get_option('monitoring_404_log', array());
    if (!is_array($log) || empty($log)) {
        return array();
    }
    $entries = array_values($log);
    usort($entries, function($a, $b) {
        $a_count = isset($a['count']) ? (int) $a['count'] : 0;
        $b_count = isset($b['count']) ? (int) $b['count'] : 0;
        if ($a_count === $b_count) {
            return (int) ($b['last'] ?? 0) <=> (int) ($a['last'] ?? 0);
        }
        return $b_count <=> $a_count;
    });

    $existing = array();
    foreach (tk_seo_get_redirect_rules() as $rule) {
        $existing[(string) $rule['from']] = true;
    }

    $result = array();
    foreach ($entries as $entry) {
        $path = tk_seo_normalize_path(isset($entry['path']) ? (string) $entry['path'] : '');
        if ($path === '' || isset($existing[$path])) {
            continue;
        }
        $result[] = array(
            'path' => $path,
            'count' => (int) ($entry['count'] ?? 0),
            'last' => (int) ($entry['last'] ?? 0),
        );
        if (count($result) >= 10) {
            break;
        }
    }
    return $result;
}

function tk_seo_sitemap_maybe_render() {
    if (is_admin()) {
        return;
    }
    if (!tk_license_features_enabled()) {
        return;
    }
    if ((int) tk_get_option('seo_sitemap_enabled', 1) !== 1) {
        return;
    }

    $request_path = tk_seo_normalize_path((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
    $sitemap_path = tk_seo_normalize_path((string) tk_get_option('seo_sitemap_path', 'sitemap.xml'));
    if ($request_path === '' || $sitemap_path === '' || $request_path !== $sitemap_path) {
        return;
    }

    $rows = tk_seo_collect_sitemap_rows();

    nocache_headers();
    header('Content-Type: application/xml; charset=' . get_bloginfo('charset'));
    echo tk_seo_build_sitemap_xml($rows);
    exit;
}

function tk_seo_build_sitemap_xml(array $rows): string {
    $xml = '<?xml version="1.0" encoding="' . esc_attr(get_bloginfo('charset')) . '"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
    foreach ($rows as $row) {
        $xml .= "<url>";
        $xml .= '<loc>' . esc_url($row['loc']) . '</loc>';
        if (!empty($row['lastmod'])) {
            $xml .= '<lastmod>' . esc_html($row['lastmod']) . '</lastmod>';
        }
        if (!empty($row['changefreq'])) {
            $xml .= '<changefreq>' . esc_html($row['changefreq']) . '</changefreq>';
        }
        if (!empty($row['priority'])) {
            $xml .= '<priority>' . esc_html($row['priority']) . '</priority>';
        }
        if (!empty($row['image'])) {
            $xml .= '<image:image><image:loc>' . esc_url($row['image']) . '</image:loc></image:image>';
        }
        $xml .= "</url>\n";
    }
    $xml .= '</urlset>';

    return $xml;
}

function tk_seo_generate_sitemap_file() {
    if (!defined('ABSPATH') || ABSPATH === '') {
        return new WP_Error('tk_sitemap_missing_root', 'WordPress root path is unavailable.');
    }

    $sitemap_path = ltrim((string) tk_get_option('seo_sitemap_path', 'sitemap.xml'), '/');
    $sitemap_path = trim($sitemap_path);
    if ($sitemap_path === '') {
        $sitemap_path = 'sitemap.xml';
    }
    if (strpos($sitemap_path, '..') !== false || preg_match('#^[a-z]+:#i', $sitemap_path)) {
        return new WP_Error('tk_sitemap_invalid_path', 'Sitemap path is invalid.');
    }

    $target = trailingslashit(ABSPATH) . $sitemap_path;
    $target_dir = dirname($target);
    if (!wp_mkdir_p($target_dir)) {
        return new WP_Error('tk_sitemap_mkdir_failed', 'Could not create sitemap directory.');
    }

    $xml = tk_seo_build_sitemap_xml(tk_seo_collect_sitemap_rows());
    $written = file_put_contents($target, $xml);
    if ($written === false) {
        return new WP_Error('tk_sitemap_write_failed', 'Could not write sitemap file.');
    }

    return array(
        'path' => $target,
        'url'  => home_url('/' . ltrim($sitemap_path, '/')),
        'size' => (int) $written,
    );
}

function tk_seo_collect_sitemap_rows() {
    $changefreq = sanitize_key((string) tk_get_option('seo_sitemap_changefreq', 'weekly'));
    $allowed_changefreq = array('always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never');
    if (!in_array($changefreq, $allowed_changefreq, true)) {
        $changefreq = 'weekly';
    }
    $priority = (float) tk_get_option('seo_sitemap_priority', 0.8);
    $priority = max(0.0, min(1.0, $priority));
    $exclude_paths = tk_seo_sitemap_exclude_paths();
    $rows = array();
    tk_seo_sitemap_add_row($rows, array(
        'loc' => home_url('/'),
        'lastmod' => gmdate('c'),
        'changefreq' => $changefreq,
        'priority' => '1.0',
        'image' => '',
    ), $exclude_paths);

    $include_images = (int) tk_get_option('seo_sitemap_include_images', 1) === 1;
    $post_types = get_post_types(array('public' => true), 'names');
    unset($post_types['attachment']);

    if (!empty($post_types)) {
        $query = new WP_Query(array(
            'post_type' => array_values($post_types),
            'post_status' => 'publish',
            'posts_per_page' => 1000,
            'fields' => 'ids',
            'no_found_rows' => false,
            'paged' => 1,
        ));
        while (true) {
            if (!empty($query->posts)) {
                foreach ($query->posts as $post_id) {
                    $loc = get_permalink((int) $post_id);
                    if (!is_string($loc) || $loc === '') {
                        continue;
                    }
                    $image = '';
                    if ($include_images) {
                        $image = get_the_post_thumbnail_url((int) $post_id, 'full');
                        if (!is_string($image)) {
                            $image = '';
                        }
                    }
                    tk_seo_sitemap_add_row($rows, array(
                        'loc' => $loc,
                        'lastmod' => get_post_modified_time('c', true, (int) $post_id),
                        'changefreq' => $changefreq,
                        'priority' => number_format($priority, 1, '.', ''),
                        'image' => $image,
                    ), $exclude_paths);
                }
            }
            if ((int) $query->max_num_pages <= (int) $query->get('paged')) {
                break;
            }
            $next = (int) $query->get('paged') + 1;
            $query = new WP_Query(array(
                'post_type' => array_values($post_types),
                'post_status' => 'publish',
                'posts_per_page' => 1000,
                'fields' => 'ids',
                'no_found_rows' => false,
                'paged' => $next,
            ));
            if ($next > 50) {
                break;
            }
        }
    }

    if ((int) tk_get_option('seo_sitemap_include_taxonomies', 1) === 1) {
        $taxonomies = get_taxonomies(array('public' => true), 'names');
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(array(
                'taxonomy' => $taxonomy,
                'hide_empty' => true,
                'number' => 500,
            ));
            if (is_wp_error($terms) || !is_array($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                if (!is_object($term) || empty($term->term_id)) {
                    continue;
                }
                $loc = get_term_link((int) $term->term_id, $taxonomy);
                if (is_wp_error($loc) || !is_string($loc)) {
                    continue;
                }
                tk_seo_sitemap_add_row($rows, array(
                    'loc' => $loc,
                    'lastmod' => '',
                    'changefreq' => $changefreq,
                    'priority' => number_format(max(0.1, $priority - 0.2), 1, '.', ''),
                    'image' => '',
                ), $exclude_paths);
            }
        }
    }

    return $rows;
}

function tk_seo_sitemap_exclude_paths(): array {
    $raw = (string) tk_get_option('seo_sitemap_exclude_paths', '');
    $paths = array();
    foreach (preg_split('/\r\n|\r|\n/', $raw) ?: array() as $line) {
        $path = tk_seo_normalize_path((string) $line);
        if ($path !== '') {
            $paths[$path] = true;
        }
    }
    return array_keys($paths);
}

function tk_seo_sitemap_add_row(array &$rows, array $row, array $exclude_paths): void {
    $loc = isset($row['loc']) ? (string) $row['loc'] : '';
    if ($loc === '') {
        return;
    }
    $path = tk_seo_normalize_path((string) wp_parse_url($loc, PHP_URL_PATH));
    foreach ($exclude_paths as $exclude) {
        if ($exclude !== '' && strpos($path, $exclude) === 0) {
            return;
        }
    }
    $rows[] = $row;
}

function tk_seo_links_scan() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_seo_links_scan');

    $report = tk_seo_scan_internal_links();
    tk_update_option('seo_broken_links_report', $report);

    wp_redirect(add_query_arg(array('page' => 'tool-kits-seo', 'tk_seo_links_scanned' => 1), admin_url('admin.php')) . '#broken-links');
    exit;
}

function tk_seo_links_clear() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_seo_links_clear');
    tk_update_option('seo_broken_links_report', array());
    wp_redirect(add_query_arg(array('page' => 'tool-kits-seo', 'tk_seo_links_cleared' => 1), admin_url('admin.php')) . '#broken-links');
    exit;
}

function tk_seo_canonical_scan() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_seo_canonical_scan');
    $report = tk_seo_run_canonical_audit();
    tk_update_option('seo_canonical_audit_report', $report);
    wp_redirect(add_query_arg(array('page' => 'tool-kits-seo', 'tk_seo_canonical_scanned' => 1), admin_url('admin.php')) . '#canonical');
    exit;
}

function tk_seo_canonical_clear() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_seo_canonical_clear');
    tk_update_option('seo_canonical_audit_report', array());
    wp_redirect(add_query_arg(array('page' => 'tool-kits-seo', 'tk_seo_canonical_cleared' => 1), admin_url('admin.php')) . '#canonical');
    exit;
}

function tk_seo_run_canonical_audit(): array {
    $urls = array(home_url('/'));
    $ids = get_posts(array(
        'post_type' => array_values(array_diff(get_post_types(array('public' => true), 'names'), array('attachment'))),
        'post_status' => 'publish',
        'numberposts' => 5,
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

    $items = array();
    foreach (array_slice(array_values(array_unique($urls)), 0, 6) as $url) {
        $response = wp_remote_get($url, array(
            'timeout' => 8,
            'redirection' => 3,
            'sslverify' => false,
            'headers' => array('User-Agent' => 'Tool Kits Canonical Audit'),
        ));
        if (is_wp_error($response)) {
            $items[] = array(
                'url' => $url,
                'status' => 0,
                'canonicals' => array(),
                'issue' => $response->get_error_message(),
            );
            continue;
        }
        $body = (string) wp_remote_retrieve_body($response);
        $canonicals = array();
        if (preg_match_all('/<link\b[^>]*rel=("|\')canonical\1[^>]*>/i', $body, $tags)) {
            foreach ($tags[0] as $tag) {
                if (preg_match('/\bhref=("|\')(.*?)\1/i', (string) $tag, $m)) {
                    $canonicals[] = esc_url_raw((string) $m[2]);
                }
            }
        }
        $issue = '';
        if (count($canonicals) === 0) {
            $issue = 'Missing canonical tag.';
        } elseif (count(array_unique($canonicals)) > 1 || count($canonicals) > 1) {
            $issue = 'Multiple canonical tags detected.';
        }
        $items[] = array(
            'url' => $url,
            'status' => (int) wp_remote_retrieve_response_code($response),
            'canonicals' => array_values(array_unique($canonicals)),
            'issue' => $issue,
        );
    }

    return array(
        'scanned_at' => time(),
        'third_party_detected' => tk_seo_has_third_party_plugin(),
        'toolkits_canonical_enabled' => (int) tk_get_option('seo_canonical_enabled', 1) === 1,
        'items' => $items,
    );
}

function tk_seo_scan_internal_links() {
    $post_types = get_post_types(array('public' => true), 'names');
    unset($post_types['attachment']);

    $post_ids = get_posts(array(
        'post_type' => array_values($post_types),
        'post_status' => 'publish',
        'numberposts' => 250,
        'fields' => 'ids',
        'orderby' => 'date',
        'order' => 'DESC',
    ));

    $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    $checked = array();
    $broken = array();

    foreach ($post_ids as $post_id) {
        $post = get_post((int) $post_id);
        if (!is_object($post) || empty($post->post_content)) {
            continue;
        }
        $content = (string) $post->post_content;
        if (!preg_match_all('/<a\\b[^>]*href=("|\')(.*?)\\1/i', $content, $matches)) {
            continue;
        }
        foreach ($matches[2] as $idx => $href) {
            $url = trim((string) $href);
            if ($url === '' || strpos($url, '#') === 0 || stripos($url, 'mailto:') === 0 || stripos($url, 'tel:') === 0 || stripos($url, 'javascript:') === 0) {
                continue;
            }
            if (strpos($url, '//') === 0) {
                $url = (is_ssl() ? 'https:' : 'http:') . $url;
            }
            if (!preg_match('#^https?://#i', $url)) {
                if (strpos($url, '/') !== 0) {
                    $url = '/' . ltrim($url, '/');
                }
                $url = home_url($url);
            }
            $host = (string) wp_parse_url($url, PHP_URL_HOST);
            if ($home_host !== '' && $host !== '' && strcasecmp($home_host, $host) !== 0) {
                continue;
            }

            if (!array_key_exists($url, $checked)) {
                $checked[$url] = tk_seo_check_url_status($url);
            }
            $status = (int) $checked[$url];
            if ($status >= 400 || $status === 0) {
                $broken[] = array(
                    'url' => $url,
                    'post_id' => (int) $post_id,
                    'post_title' => get_the_title((int) $post_id),
                    'source_url' => get_permalink((int) $post_id),
                    'anchor' => isset($matches[0][$idx]) ? wp_strip_all_tags((string) $matches[0][$idx]) : '',
                    'status' => $status,
                );
                if (count($broken) >= 200) {
                    break 2;
                }
            }
        }
    }

    return array(
        'scanned_at' => time(),
        'checked_posts' => is_array($post_ids) ? count($post_ids) : 0,
        'total_urls' => count($checked),
        'broken_count' => count($broken),
        'items' => $broken,
    );
}

function tk_seo_check_url_status($url) {
    $args = array(
        'timeout' => 6,
        'redirection' => 3,
        'sslverify' => false,
    );
    $response = wp_remote_head($url, $args);
    if (is_wp_error($response)) {
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return 0;
        }
    }
    return (int) wp_remote_retrieve_response_code($response);
}

function tk_seo_get_index_monitor_items() {
    $raw = tk_get_option('seo_index_monitor', array());
    if (!is_array($raw)) {
        return array();
    }
    $items = array();
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $url = isset($item['url']) ? esc_url_raw((string) $item['url']) : '';
        if ($url === '') {
            continue;
        }
        $items[] = array(
            'id' => isset($item['id']) ? sanitize_key((string) $item['id']) : wp_generate_password(12, false, false),
            'url' => $url,
            'status' => isset($item['status']) ? sanitize_key((string) $item['status']) : 'unknown',
            'note' => isset($item['note']) ? sanitize_text_field((string) $item['note']) : '',
            'updated_at' => isset($item['updated_at']) ? (int) $item['updated_at'] : 0,
        );
    }
    usort($items, function($a, $b) {
        return (int) ($b['updated_at'] ?? 0) <=> (int) ($a['updated_at'] ?? 0);
    });
    return $items;
}

function tk_seo_index_add() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_seo_index_add');

    $url = esc_url_raw((string) tk_post('index_url', ''));
    $status = sanitize_key((string) tk_post('index_status', 'unknown'));
    $note = sanitize_text_field((string) tk_post('index_note', ''));
    $allowed_status = array('unknown', 'submitted', 'indexed', 'not_indexed', 'crawled_not_indexed');
    if (!in_array($status, $allowed_status, true)) {
        $status = 'unknown';
    }
    if ($url === '') {
        wp_redirect(add_query_arg(array('page' => 'tool-kits-seo', 'tk_seo_index_error' => 1), admin_url('admin.php')) . '#indexing');
        exit;
    }

    $items = tk_seo_get_index_monitor_items();
    $items[] = array(
        'id' => wp_generate_password(12, false, false),
        'url' => $url,
        'status' => $status,
        'note' => $note,
        'updated_at' => time(),
    );
    tk_update_option('seo_index_monitor', $items);

    wp_redirect(add_query_arg(array('page' => 'tool-kits-seo', 'tk_seo_index_added' => 1), admin_url('admin.php')) . '#indexing');
    exit;
}

function tk_seo_index_delete() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_seo_index_delete');
    $id = sanitize_key((string) tk_post('id', ''));
    $items = tk_seo_get_index_monitor_items();
    $filtered = array();
    foreach ($items as $item) {
        if ((string) ($item['id'] ?? '') === $id) {
            continue;
        }
        $filtered[] = $item;
    }
    tk_update_option('seo_index_monitor', $filtered);
    wp_redirect(add_query_arg(array('page' => 'tool-kits-seo', 'tk_seo_index_deleted' => 1), admin_url('admin.php')) . '#indexing');
    exit;
}

function tk_seo_content_audit_scan() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_seo_content_audit_scan');
    $report = tk_seo_run_content_audit();
    tk_update_option('seo_content_audit_report', $report);
    wp_redirect(add_query_arg(array('page' => 'tool-kits-seo', 'tk_seo_audit_scanned' => 1), admin_url('admin.php')) . '#content-audit');
    exit;
}

function tk_seo_content_audit_clear() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_seo_content_audit_clear');
    tk_update_option('seo_content_audit_report', array());
    wp_redirect(add_query_arg(array('page' => 'tool-kits-seo', 'tk_seo_audit_cleared' => 1), admin_url('admin.php')) . '#content-audit');
    exit;
}

function tk_seo_geo_audit_scan() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_seo_geo_audit_scan');
    tk_update_option('seo_geo_audit_report', tk_seo_run_geo_audit());
    wp_redirect(add_query_arg(array('page' => 'tool-kits-seo', 'tk_seo_geo_audit_scanned' => 1), admin_url('admin.php')) . '#geo-audit');
    exit;
}

function tk_seo_geo_audit_clear() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_seo_geo_audit_clear');
    tk_update_option('seo_geo_audit_report', array());
    wp_redirect(add_query_arg(array('page' => 'tool-kits-seo', 'tk_seo_geo_audit_cleared' => 1), admin_url('admin.php')) . '#geo-audit');
    exit;
}

function tk_seo_run_geo_audit(): array {
    $urls = array(home_url('/'));
    $ids = get_posts(array(
        'post_type' => array_values(array_diff(get_post_types(array('public' => true), 'names'), array('attachment'))),
        'post_status' => 'publish',
        'numberposts' => 12,
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

    $items = array();
    foreach (array_slice(array_values(array_unique($urls)), 0, 12) as $url) {
        $items[] = tk_seo_geo_audit_url($url);
    }

    $summary = tk_seo_geo_audit_summary($items);
    return array(
        'scanned_at' => time(),
        'checked_urls' => count($items),
        'average_score' => (int) ($summary['average_score'] ?? 0),
        'issue_count' => (int) ($summary['issue_count'] ?? 0),
        'items' => $items,
    );
}

function tk_seo_geo_audit_url($url): array {
    $response = wp_remote_get($url, array(
        'timeout' => 8,
        'redirection' => 3,
        'sslverify' => false,
        'headers' => array('User-Agent' => 'Tool Kits GEO Audit'),
    ));
    if (is_wp_error($response)) {
        return array(
            'url' => $url,
            'status' => 0,
            'score' => 0,
            'grade' => 'F',
            'issues' => array($response->get_error_message()),
            'schema' => array('valid' => false, 'types' => array(), 'entity_ok' => false),
            'semantic' => array(),
            'freshness' => array(),
        );
    }

    $body = (string) wp_remote_retrieve_body($response);
    $schema = tk_seo_geo_validate_schema($body);
    $semantic = tk_seo_geo_scan_semantic_html($body);
    $freshness = tk_seo_geo_scan_freshness($body);
    $issues = array_merge($schema['issues'], $semantic['issues'], $freshness['issues']);
    $score = 100;
    $score -= min(35, count($schema['issues']) * 8);
    $score -= min(30, count($semantic['issues']) * 6);
    $score -= min(20, count($freshness['issues']) * 10);
    if ((int) wp_remote_retrieve_response_code($response) >= 400) {
        $issues[] = 'HTTP status is not successful.';
        $score -= 20;
    }
    $score = max(0, min(100, $score));

    return array(
        'url' => $url,
        'status' => (int) wp_remote_retrieve_response_code($response),
        'score' => $score,
        'grade' => tk_seo_geo_grade($score),
        'issues' => array_values(array_unique($issues)),
        'schema' => $schema,
        'semantic' => $semantic,
        'freshness' => $freshness,
    );
}

function tk_seo_geo_validate_schema($html): array {
    $issues = array();
    $types = array();
    $entity_ok = false;
    $schema_count = 0;
    $invalid_count = 0;
    $required_entity = array('name', 'description', 'url', 'logo', 'sameAs', 'contactPoint', 'address', 'knowsAbout');
    $entity_fields = array();

    if (preg_match_all('/<script\b[^>]*type=("|\')application\/ld\+json\1[^>]*>(.*?)<\/script>/is', (string) $html, $matches)) {
        foreach ($matches[2] as $json) {
            $schema_count++;
            $decoded = json_decode(trim(html_entity_decode((string) $json)), true);
            if (!is_array($decoded)) {
                $invalid_count++;
                continue;
            }
            $nodes = isset($decoded['@graph']) && is_array($decoded['@graph']) ? $decoded['@graph'] : array($decoded);
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $type = $node['@type'] ?? '';
                $node_types = is_array($type) ? $type : array($type);
                foreach ($node_types as $node_type) {
                    if (is_string($node_type) && $node_type !== '') {
                        $types[] = $node_type;
                    }
                }
                if (in_array('Organization', $node_types, true)) {
                    foreach ($required_entity as $field) {
                        $entity_fields[$field] = !empty($node[$field]);
                    }
                }
            }
        }
    }

    $types = array_values(array_unique($types));
    if ($schema_count === 0) {
        $issues[] = 'Missing JSON-LD schema.';
    }
    if ($invalid_count > 0) {
        $issues[] = 'Invalid JSON-LD detected.';
    }
    foreach (array('Organization', 'WebSite', 'WebPage') as $required_type) {
        if (!in_array($required_type, $types, true)) {
            $issues[] = 'Missing ' . $required_type . ' schema.';
        }
    }
    foreach ($required_entity as $field) {
        if (empty($entity_fields[$field])) {
            $issues[] = 'Organization missing ' . $field . '.';
        }
    }
    $entity_ok = !empty($entity_fields) && !in_array(false, $entity_fields, true);

    return array(
        'valid' => $schema_count > 0 && $invalid_count === 0,
        'count' => $schema_count,
        'types' => $types,
        'entity_ok' => $entity_ok,
        'entity_fields' => $entity_fields,
        'issues' => $issues,
    );
}

function tk_seo_geo_scan_semantic_html($html): array {
    $issues = array();
    $h1_count = preg_match_all('/<h1\b[^>]*>/i', (string) $html);
    $has_main = (bool) preg_match('/<main\b/i', (string) $html);
    $has_header = (bool) preg_match('/<header\b/i', (string) $html);
    $has_footer = (bool) preg_match('/<footer\b/i', (string) $html);
    $has_nav = (bool) preg_match('/<nav\b/i', (string) $html);
    $has_article_or_section = (bool) preg_match('/<(article|section)\b/i', (string) $html);
    $has_meta_desc = (bool) preg_match('/<meta\b[^>]*name=("|\')description\1[^>]*content=("|\')[^"\']{50,}\2/i', (string) $html);

    if ((int) $h1_count === 0) {
        $issues[] = 'Missing H1.';
    } elseif ((int) $h1_count > 1) {
        $issues[] = 'Multiple H1 headings.';
    }
    if (!$has_main) {
        $issues[] = 'Missing semantic main element.';
    }
    if (!$has_header) {
        $issues[] = 'Missing semantic header element.';
    }
    if (!$has_footer) {
        $issues[] = 'Missing semantic footer element.';
    }
    if (!$has_nav) {
        $issues[] = 'Missing semantic nav element.';
    }
    if (!$has_article_or_section) {
        $issues[] = 'Missing article/section structure.';
    }
    if (!$has_meta_desc) {
        $issues[] = 'Missing or short meta description.';
    }

    return array(
        'h1_count' => (int) $h1_count,
        'main' => $has_main,
        'header' => $has_header,
        'footer' => $has_footer,
        'nav' => $has_nav,
        'article_or_section' => $has_article_or_section,
        'meta_description' => $has_meta_desc,
        'issues' => $issues,
    );
}

function tk_seo_geo_scan_freshness($html): array {
    $issues = array();
    $published = '';
    $modified = '';
    if (preg_match('/property=("|\')article:published_time\1[^>]*content=("|\')(.*?)\2/i', (string) $html, $match)) {
        $published = (string) $match[3];
    }
    if (preg_match('/property=("|\')article:modified_time\1[^>]*content=("|\')(.*?)\2/i', (string) $html, $match)) {
        $modified = (string) $match[3];
    }
    if ($published === '' && preg_match('/"datePublished"\s*:\s*"([^"]+)"/i', (string) $html, $match)) {
        $published = (string) $match[1];
    }
    if ($modified === '' && preg_match('/"dateModified"\s*:\s*"([^"]+)"/i', (string) $html, $match)) {
        $modified = (string) $match[1];
    }

    $modified_ts = $modified !== '' ? strtotime($modified) : false;
    if ($published === '') {
        $issues[] = 'Missing published date.';
    }
    if ($modified === '') {
        $issues[] = 'Missing modified date.';
    } elseif ($modified_ts !== false && $modified_ts < strtotime('-12 months')) {
        $issues[] = 'Content is stale over 12 months.';
    }

    return array(
        'published' => $published,
        'modified' => $modified,
        'issues' => $issues,
    );
}

function tk_seo_geo_grade($score): string {
    $score = (int) $score;
    if ($score >= 90) {
        return 'A';
    }
    if ($score >= 80) {
        return 'B';
    }
    if ($score >= 70) {
        return 'C';
    }
    if ($score >= 50) {
        return 'D';
    }
    return 'F';
}

function tk_seo_geo_audit_summary($items): array {
    $sum = 0;
    $count = 0;
    $issue_count = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $sum += (int) ($item['score'] ?? 0);
        $issue_count += isset($item['issues']) && is_array($item['issues']) ? count($item['issues']) : 0;
        $count++;
    }
    return array(
        'average_score' => $count > 0 ? (int) round($sum / $count) : 0,
        'issue_count' => $issue_count,
    );
}

function tk_seo_run_content_audit() {
    $post_types = get_post_types(array('public' => true), 'names');
    unset($post_types['attachment']);
    $ids = get_posts(array(
        'post_type' => array_values($post_types),
        'post_status' => 'publish',
        'numberposts' => 200,
        'fields' => 'ids',
        'orderby' => 'date',
        'order' => 'DESC',
    ));

    $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    $items = array();
    foreach ($ids as $post_id) {
        $post = get_post((int) $post_id);
        if (!is_object($post)) {
            continue;
        }
        $content = (string) $post->post_content;
        $plain = trim(wp_strip_all_tags($content));
        $words = str_word_count($plain);

        $h2 = preg_match_all('/<h2\\b[^>]*>/i', $content, $tmp1);
        $h3 = preg_match_all('/<h3\\b[^>]*>/i', $content, $tmp2);
        $h1 = preg_match_all('/<h1\\b[^>]*>/i', $content, $tmp3);
        $headings = preg_match_all('/<h([1-6])\\b[^>]*>/i', $content, $heading_matches) ? $heading_matches[1] : array();
        $heading_jump = false;
        $prev = 0;
        foreach ($headings as $level) {
            $current = (int) $level;
            if ($prev > 0 && $current > ($prev + 1)) {
                $heading_jump = true;
                break;
            }
            $prev = $current;
        }

        $internal_links = 0;
        $external_links = 0;
        if (preg_match_all('/<a\\b[^>]*href=("|\')(.*?)\\1/i', $content, $links)) {
            foreach ($links[2] as $href) {
                $url = trim((string) $href);
                if ($url === '' || strpos($url, '#') === 0 || stripos($url, 'mailto:') === 0 || stripos($url, 'tel:') === 0) {
                    continue;
                }
                if (!preg_match('#^https?://#i', $url)) {
                    $internal_links++;
                    continue;
                }
                $host = (string) wp_parse_url($url, PHP_URL_HOST);
                if ($home_host !== '' && $host !== '' && strcasecmp($home_host, $host) === 0) {
                    $internal_links++;
                } else {
                    $external_links++;
                }
            }
        }

        $images = preg_match_all('/<img\\b[^>]*>/i', $content, $img_matches) ? $img_matches[0] : array();
        $missing_alt = 0;
        foreach ($images as $img_tag) {
            if (!preg_match('/\\balt=("|\')(.*?)\\1/i', (string) $img_tag)) {
                $missing_alt++;
                continue;
            }
            if (preg_match('/\\balt=("|\')\\s*\\1/i', (string) $img_tag)) {
                $missing_alt++;
            }
        }

        $author = tk_seo_author_data($post);
        $has_author = trim((string) ($author['name'] ?? '')) !== '';
        $has_author_bio = trim((string) ($author['description'] ?? '')) !== '';
        $has_published_date = !empty($post->post_date_gmt) && $post->post_date_gmt !== '0000-00-00 00:00:00';
        $has_modified_date = !empty($post->post_modified_gmt) && $post->post_modified_gmt !== '0000-00-00 00:00:00';
        $reference_urls = tk_seo_reference_urls((int) $post_id);
        $related_urls = tk_seo_related_urls((int) $post_id);
        $has_references = !empty($reference_urls) || $external_links > 0;
        $has_related = !empty($related_urls) || $internal_links >= 2;

        $score = 100;
        $issues = array();
        $priority = 'low';

        if ($words < 300) {
            $issues[] = 'Low word count (<300)';
            $score -= 20;
        } elseif ($words < 600) {
            $issues[] = 'Word count can be improved (<600)';
            $score -= 10;
        }

        if ((int) $h1 > 0) {
            $issues[] = 'H1 found in content body';
            $score -= 15;
        }
        if ((int) $h2 === 0 && (int) $h3 > 0) {
            $issues[] = 'Has H3 but no H2';
            $score -= 12;
        }
        if ($heading_jump) {
            $issues[] = 'Heading level jump detected';
            $score -= 12;
        }
        if ($internal_links < 2) {
            $issues[] = 'Low internal links (<2)';
            $score -= 15;
        }
        if ($missing_alt > 0) {
            $issues[] = 'Images missing alt: ' . $missing_alt;
            $score -= min(20, $missing_alt * 5);
        }
        if (trim((string) $post->post_excerpt) === '') {
            $issues[] = 'Missing manual excerpt';
            $score -= 8;
        }
        if (!$has_author) {
            $issues[] = 'Missing author signal';
            $score -= 12;
        }
        if (!$has_author_bio) {
            $issues[] = 'Missing author bio';
            $score -= 8;
        }
        if (!$has_published_date || !$has_modified_date) {
            $issues[] = 'Missing published/modified date signal';
            $score -= 8;
        }
        if (!$has_references && $post->post_type === 'post') {
            $issues[] = 'Missing reference/source signal';
            $score -= 8;
        }
        if (!$has_related) {
            $issues[] = 'Missing related content signal';
            $score -= 8;
        }

        $score = max(0, min(100, (int) round($score)));
        $priority = tk_seo_audit_priority_from_score($score);

        $items[] = array(
            'post_id' => (int) $post_id,
            'title' => get_the_title((int) $post_id),
            'words' => (int) $words,
            'internal_links' => (int) $internal_links,
            'external_links' => (int) $external_links,
            'trust_signals' => array(
                'author' => $has_author,
                'author_bio' => $has_author_bio,
                'published_date' => $has_published_date,
                'modified_date' => $has_modified_date,
                'references' => $has_references,
                'related_content' => $has_related,
            ),
            'issues' => $issues,
            'score' => $score,
            'priority' => $priority,
        );
    }

    usort($items, function($a, $b) {
        $a_score = isset($a['score']) ? (int) $a['score'] : 0;
        $b_score = isset($b['score']) ? (int) $b['score'] : 0;
        if ($a_score === $b_score) {
            $a_issues = isset($a['issues']) && is_array($a['issues']) ? count($a['issues']) : 0;
            $b_issues = isset($b['issues']) && is_array($b['issues']) ? count($b['issues']) : 0;
            return $b_issues <=> $a_issues;
        }
        return $a_score <=> $b_score;
    });

    $summary = tk_seo_audit_build_summary($items);

    return array(
        'scanned_at' => time(),
        'checked_posts' => is_array($ids) ? count($ids) : 0,
        'flagged_count' => count(array_filter($items, function($item) {
            return !empty($item['issues']);
        })),
        'average_score' => (int) ($summary['average_score'] ?? 0),
        'priority_counts' => isset($summary['priority_counts']) ? $summary['priority_counts'] : array(),
        'items' => $items,
    );
}

function tk_seo_audit_priority_from_score($score) {
    $score = (int) $score;
    if ($score < 50) {
        return 'critical';
    }
    if ($score < 70) {
        return 'high';
    }
    if ($score < 85) {
        return 'medium';
    }
    return 'low';
}

function tk_seo_audit_build_summary($items) {
    $count = 0;
    $sum = 0;
    $priority_counts = array(
        'critical' => 0,
        'high' => 0,
        'medium' => 0,
        'low' => 0,
    );
    if (!is_array($items)) {
        return array('average_score' => 0, 'priority_counts' => $priority_counts);
    }
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $score = isset($item['score']) ? (int) $item['score'] : 0;
        $priority = isset($item['priority']) ? sanitize_key((string) $item['priority']) : 'low';
        if (!isset($priority_counts[$priority])) {
            $priority = 'low';
        }
        $priority_counts[$priority]++;
        $sum += $score;
        $count++;
    }
    $average = $count > 0 ? (int) round($sum / $count) : 0;
    return array(
        'average_score' => $average,
        'priority_counts' => $priority_counts,
    );
}

function tk_render_seo_opt_panel() {
    if (!tk_is_admin_user()) return;

    $enabled = (int) tk_get_option('seo_enabled', 0);
    $meta_desc = (int) tk_get_option('seo_meta_desc_enabled', 1);
    $canonical = (int) tk_get_option('seo_canonical_enabled', 1);
    $og = (int) tk_get_option('seo_og_enabled', 1);
    $schema = (int) tk_get_option('seo_schema_enabled', 1);
    $noindex_search = (int) tk_get_option('seo_noindex_search', 1);
    $noindex_404 = (int) tk_get_option('seo_noindex_404', 1);
    $noindex_paged = (int) tk_get_option('seo_noindex_paged_archives', 1);

    $has_third_party = tk_seo_has_third_party_plugin();
    $rules = tk_seo_get_redirect_rules();
    $suggestions = tk_seo_redirect_suggestions();
    $report = tk_get_option('seo_broken_links_report', array());
    $canonical_report = tk_get_option('seo_canonical_audit_report', array());
    $index_items = tk_seo_get_index_monitor_items();
    $audit_report = tk_get_option('seo_content_audit_report', array());
    $geo_report = tk_get_option('seo_geo_audit_report', array());
    if (!is_array($report)) {
        $report = array();
    }
    if (!is_array($canonical_report)) {
        $canonical_report = array();
    }
    if (!is_array($audit_report)) {
        $audit_report = array();
    }
    if (!is_array($geo_report)) {
        $geo_report = array();
    }

    if (isset($_GET['tk_seo_redirect_error']) && sanitize_key((string) $_GET['tk_seo_redirect_error']) === '1') {
        tk_notice('Failed to add redirect rule. Please check source and target path.', 'error');
    }
    if (isset($_GET['tk_seo_redirect_added']) && sanitize_key((string) $_GET['tk_seo_redirect_added']) === '1') {
        tk_notice('Redirect rule added.', 'success');
    }
    if (isset($_GET['tk_seo_redirect_deleted']) && sanitize_key((string) $_GET['tk_seo_redirect_deleted']) === '1') {
        tk_notice('Redirect rule deleted.', 'success');
    }
    if (isset($_GET['tk_seo_links_scanned']) && sanitize_key((string) $_GET['tk_seo_links_scanned']) === '1') {
        tk_notice('Broken link scan completed.', 'success');
    }
    if (isset($_GET['tk_seo_links_cleared']) && sanitize_key((string) $_GET['tk_seo_links_cleared']) === '1') {
        tk_notice('Broken link report cleared.', 'success');
    }
    if (isset($_GET['tk_seo_canonical_scanned']) && sanitize_key((string) $_GET['tk_seo_canonical_scanned']) === '1') {
        tk_notice('Canonical audit completed.', 'success');
    }
    if (isset($_GET['tk_seo_canonical_cleared']) && sanitize_key((string) $_GET['tk_seo_canonical_cleared']) === '1') {
        tk_notice('Canonical audit cleared.', 'success');
    }
    if (isset($_GET['tk_seo_index_error']) && sanitize_key((string) $_GET['tk_seo_index_error']) === '1') {
        tk_notice('Failed to save indexing item. URL is required.', 'error');
    }
    if (isset($_GET['tk_seo_index_added']) && sanitize_key((string) $_GET['tk_seo_index_added']) === '1') {
        tk_notice('Indexing monitor item added.', 'success');
    }
    if (isset($_GET['tk_seo_index_deleted']) && sanitize_key((string) $_GET['tk_seo_index_deleted']) === '1') {
        tk_notice('Indexing monitor item deleted.', 'success');
    }
    if (isset($_GET['tk_seo_audit_scanned']) && sanitize_key((string) $_GET['tk_seo_audit_scanned']) === '1') {
        tk_notice('Content SEO audit completed.', 'success');
    }
    if (isset($_GET['tk_seo_audit_cleared']) && sanitize_key((string) $_GET['tk_seo_audit_cleared']) === '1') {
        tk_notice('Content SEO audit report cleared.', 'success');
    }
    if (isset($_GET['tk_seo_geo_audit_scanned']) && sanitize_key((string) $_GET['tk_seo_geo_audit_scanned']) === '1') {
        tk_notice('GEO audit completed.', 'success');
    }
    if (isset($_GET['tk_seo_geo_audit_cleared']) && sanitize_key((string) $_GET['tk_seo_geo_audit_cleared']) === '1') {
        tk_notice('GEO audit report cleared.', 'success');
    }

    ?>
    <div class="tk-card">
        <h2>SEO Optimization</h2>
        <p>Technical SEO toolkit: metadata, redirects, sitemap, and broken link checker.</p>
        <?php if ($has_third_party) : ?>
            <?php tk_notice('Third-party SEO plugin detected. Tool Kits meta/schema output is auto-disabled to avoid duplicate tags.', 'warning'); ?>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_seo_opt_save'); ?>
            <input type="hidden" name="action" value="tk_seo_opt_save">
            <input type="hidden" name="tk_tab" value="seo">
            
            <div style="margin-bottom:24px;">
                <?php tk_render_switch('seo_enabled', 'Enable SEO Toolkit', 'Unlock metadata management, redirects, and content auditing.', $enabled); ?>
            </div>

            <div class="tk-grid tk-grid-2" style="gap:24px;">
                <div style="background:var(--tk-bg-soft); padding:20px; border-radius:16px; border:1px solid var(--tk-border-soft);">
                    <h4 style="margin-top:0; color:var(--tk-primary); font-size:14px; margin-bottom:16px;">Meta & Schema Markup</h4>
                    <div style="display:flex; flex-direction:column; gap:16px;">
                        <?php 
                        tk_render_switch('seo_meta_desc_enabled', 'Auto Meta Description', 'Generate meta tags from content excerpts.', $meta_desc);
                        tk_render_switch('seo_canonical_enabled', 'Canonical Tags', 'Prevent duplicate content issues.', $canonical);
                        tk_render_switch('seo_og_enabled', 'Open Graph (Social)', 'Enable rich previews for Facebook/Twitter.', $og);
                        tk_render_switch('seo_schema_enabled', 'JSON-LD Schema', 'Improve search results with structured data.', $schema);
                        ?>
                    </div>
                </div>

                <div style="background:var(--tk-bg-soft); padding:20px; border-radius:16px; border:1px solid var(--tk-border-soft);">
                    <h4 style="margin-top:0; color:var(--tk-primary); font-size:14px; margin-bottom:16px;">Robots & Indexing Control</h4>
                    <div style="display:flex; flex-direction:column; gap:16px;">
                        <?php 
                        tk_render_switch('seo_noindex_search', 'Noindex Search', 'Prevent search results from being indexed.', $noindex_search);
                        tk_render_switch('seo_noindex_404', 'Noindex 404 Pages', 'Prevent error pages from appearing in search.', $noindex_404);
                        tk_render_switch('seo_noindex_paged_archives', 'Noindex Paged Archives', 'Noindex /page/2/ and subsequent pages.', $noindex_paged);
                        ?>
                    </div>
                </div>
            </div>

            <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                <button class="button button-primary button-hero">Save SEO Settings</button>
            </div>
        </form>
    </div>

    <div class="tk-card" style="margin-top:16px;">
        <h3>Redirect Manager</h3>
        <p>Create 301/302/410 redirects and use 404 logs as quick suggestions.</p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:14px;">
            <?php tk_nonce_field('tk_seo_redirect_add'); ?>
            <input type="hidden" name="action" value="tk_seo_redirect_add">
            <input type="hidden" name="tk_tab" value="seo">
            <p>
                <input type="text" name="redirect_from" placeholder="/old-url" class="regular-text">
                <input type="text" name="redirect_to" placeholder="/new-url or https://example.com" class="regular-text">
                <select name="redirect_status">
                    <option value="301">301</option>
                    <option value="302">302</option>
                    <option value="410">410</option>
                </select>
                <button class="button">Add Redirect</button>
            </p>
        </form>

        <?php if (!empty($rules)) : ?>
            <table class="widefat striped">
                <thead><tr><th>From</th><th>To</th><th>Status</th><th>Hits</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($rules as $rule) : ?>
                    <tr>
                        <td><code><?php echo esc_html((string) $rule['from']); ?></code></td>
                        <td><code><?php echo esc_html((string) $rule['to']); ?></code></td>
                        <td><?php echo esc_html((string) $rule['status']); ?></td>
                        <td><?php echo esc_html((string) (int) $rule['hits']); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                <?php tk_nonce_field('tk_seo_redirect_delete'); ?>
                                <input type="hidden" name="action" value="tk_seo_redirect_delete">
                                <input type="hidden" name="id" value="<?php echo esc_attr((string) $rule['id']); ?>">
                                <button class="button button-small" onclick="return confirm('Delete redirect?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p class="description">No redirect rules yet.</p>
        <?php endif; ?>

        <?php if (!empty($suggestions)) : ?>
            <hr style="margin:16px 0;">
            <p><strong>404 Suggestions</strong></p>
            <table class="widefat striped">
                <thead><tr><th>Path</th><th>Hits</th><th>Quick Add 301</th></tr></thead>
                <tbody>
                <?php foreach ($suggestions as $s) : ?>
                    <tr>
                        <td><code><?php echo esc_html((string) $s['path']); ?></code></td>
                        <td><?php echo esc_html((string) $s['count']); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; gap:6px; align-items:center;">
                                <?php tk_nonce_field('tk_seo_redirect_add'); ?>
                                <input type="hidden" name="action" value="tk_seo_redirect_add">
                                <input type="hidden" name="redirect_from" value="<?php echo esc_attr((string) $s['path']); ?>">
                                <input type="hidden" name="redirect_status" value="301">
                                <input type="text" name="redirect_to" placeholder="/target-url" class="regular-text">
                                <button class="button button-small">Add</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="tk-card" style="margin-top:16px;">
        <h3>Canonical Conflict Checker</h3>
        <p>Scan homepage and recent content for missing or duplicate canonical tags.</p>
        <p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:8px;">
                <?php tk_nonce_field('tk_seo_canonical_scan'); ?>
                <input type="hidden" name="action" value="tk_seo_canonical_scan">
                <button class="button">Run Canonical Audit</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                <?php tk_nonce_field('tk_seo_canonical_clear'); ?>
                <input type="hidden" name="action" value="tk_seo_canonical_clear">
                <button class="button">Clear Audit</button>
            </form>
        </p>
        <?php if (!empty($canonical_report)) : ?>
            <p class="description">
                Last scan: <?php echo !empty($canonical_report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $canonical_report['scanned_at'])) : '-'; ?> |
                Third-party SEO plugin: <?php echo !empty($canonical_report['third_party_detected']) ? 'Detected' : 'Not detected'; ?>
            </p>
            <?php $canonical_items = isset($canonical_report['items']) && is_array($canonical_report['items']) ? $canonical_report['items'] : array(); ?>
            <?php if (!empty($canonical_items)) : ?>
                <table class="widefat striped">
                    <thead><tr><th>URL</th><th>Status</th><th>Canonical</th><th>Issue</th></tr></thead>
                    <tbody>
                    <?php foreach ($canonical_items as $item) : ?>
                        <?php $canonicals = isset($item['canonicals']) && is_array($item['canonicals']) ? $item['canonicals'] : array(); ?>
                        <tr>
                            <td><a href="<?php echo esc_url((string) ($item['url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($item['url'] ?? '')); ?></a></td>
                            <td><?php echo esc_html((string) ((int) ($item['status'] ?? 0))); ?></td>
                            <td>
                                <?php if (!empty($canonicals)) : ?>
                                    <?php foreach ($canonicals as $canonical_url) : ?>
                                        <code><?php echo esc_html((string) $canonical_url); ?></code><br>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo !empty($item['issue']) ? esc_html((string) $item['issue']) : '<span class="tk-badge tk-on">OK</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php else : ?>
            <p class="description">No canonical audit yet.</p>
        <?php endif; ?>
    </div>

    <div class="tk-card" style="margin-top:16px;">
        <h3>Indexing Monitor</h3>
        <p>Track index status per URL and use quick links to inspect in Google tools.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:14px;">
            <?php tk_nonce_field('tk_seo_index_add'); ?>
            <input type="hidden" name="action" value="tk_seo_index_add">
            <p>
                <input type="url" name="index_url" class="large-text" placeholder="<?php echo esc_attr(home_url('/sample-page')); ?>">
            </p>
            <p>
                <select name="index_status">
                    <option value="unknown">Unknown</option>
                    <option value="submitted">Submitted</option>
                    <option value="indexed">Indexed</option>
                    <option value="not_indexed">Not Indexed</option>
                    <option value="crawled_not_indexed">Crawled - Not Indexed</option>
                </select>
                <input type="text" name="index_note" class="regular-text" placeholder="Optional note">
                <button class="button">Add Item</button>
            </p>
        </form>
        <?php if (!empty($index_items)) : ?>
            <table class="widefat striped">
                <thead><tr><th>URL</th><th>Status</th><th>Updated</th><th>Check</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($index_items as $item) : ?>
                    <?php $url = (string) ($item['url'] ?? ''); ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html($url); ?></a>
                            <?php if (!empty($item['note'])) : ?>
                                <div class="description"><?php echo esc_html((string) $item['note']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(ucwords(str_replace('_', ' ', (string) ($item['status'] ?? 'unknown')))); ?></td>
                        <td><?php echo !empty($item['updated_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $item['updated_at'])) : '-'; ?></td>
                        <td>
                            <a href="<?php echo esc_url('https://www.google.com/search?q=' . rawurlencode('site:' . $url)); ?>" target="_blank" rel="noopener">site:</a>
                            |
                            <a href="<?php echo esc_url('https://search.google.com/test/rich-results?url=' . rawurlencode($url)); ?>" target="_blank" rel="noopener">test</a>
                        </td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                <?php tk_nonce_field('tk_seo_index_delete'); ?>
                                <input type="hidden" name="action" value="tk_seo_index_delete">
                                <input type="hidden" name="id" value="<?php echo esc_attr((string) ($item['id'] ?? '')); ?>">
                                <button class="button button-small" onclick="return confirm('Delete item?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p class="description">No indexing monitor items yet.</p>
        <?php endif; ?>
    </div>

    <div class="tk-card" style="margin-top:16px;">
        <h3>Broken Link Checker</h3>
        <p>Scan internal links from latest published content and report HTTP errors.</p>
        <p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:8px;">
                <?php tk_nonce_field('tk_seo_links_scan'); ?>
                <input type="hidden" name="action" value="tk_seo_links_scan">
                <button class="button">Run Scan</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                <?php tk_nonce_field('tk_seo_links_clear'); ?>
                <input type="hidden" name="action" value="tk_seo_links_clear">
                <button class="button">Clear Report</button>
            </form>
        </p>

        <?php if (!empty($report)) : ?>
            <p class="description">
                Last scan: <?php echo !empty($report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $report['scanned_at'])) : '-'; ?> |
                Posts: <?php echo esc_html((string) ((int) ($report['checked_posts'] ?? 0))); ?> |
                URLs: <?php echo esc_html((string) ((int) ($report['total_urls'] ?? 0))); ?> |
                Broken: <?php echo esc_html((string) ((int) ($report['broken_count'] ?? 0))); ?>
            </p>
            <?php $items = isset($report['items']) && is_array($report['items']) ? $report['items'] : array(); ?>
            <?php if (!empty($items)) : ?>
                <table class="widefat striped">
                    <thead><tr><th>Status</th><th>URL</th><th>Source Post</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $item) : ?>
                        <tr>
                            <td><?php echo esc_html((string) ((int) ($item['status'] ?? 0))); ?></td>
                            <td><a href="<?php echo esc_url((string) ($item['url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($item['url'] ?? '')); ?></a></td>
                            <td>
                                <?php $pid = (int) ($item['post_id'] ?? 0); ?>
                                <?php if (!empty($item['source_url'])) : ?>
                                    <a href="<?php echo esc_url((string) $item['source_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($item['post_title'] ?? 'Post')); ?></a>
                                    <?php if ($pid > 0) : ?>
                                        <br><a class="description" href="<?php echo esc_url(get_edit_post_link($pid)); ?>">Edit source</a>
                                    <?php endif; ?>
                                <?php elseif ($pid > 0) : ?>
                                    <a href="<?php echo esc_url(get_edit_post_link($pid)); ?>"><?php echo esc_html((string) ($item['post_title'] ?? 'Post')); ?></a>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="description">No broken internal links found in the latest scan.</p>
            <?php endif; ?>
        <?php else : ?>
            <p class="description">No scan report yet.</p>
        <?php endif; ?>
    </div>

    <div class="tk-card" id="geo-audit" style="margin-top:16px;">
        <h3>GEO Audit</h3>
        <p>Scan live pages for AI answer readiness: schema validity, entity completeness, semantic HTML, trust signals, and freshness.</p>
        <p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:8px;">
                <?php tk_nonce_field('tk_seo_geo_audit_scan'); ?>
                <input type="hidden" name="action" value="tk_seo_geo_audit_scan">
                <button class="button">Run GEO Audit</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                <?php tk_nonce_field('tk_seo_geo_audit_clear'); ?>
                <input type="hidden" name="action" value="tk_seo_geo_audit_clear">
                <button class="button">Clear GEO Audit</button>
            </form>
        </p>

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
            <p class="description">
                Last scan: <?php echo !empty($geo_report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $geo_report['scanned_at'])) : '-'; ?> |
                Checked: <?php echo esc_html((string) ((int) ($geo_report['checked_urls'] ?? 0))); ?> |
                Issues: <?php echo esc_html((string) ((int) ($geo_report['issue_count'] ?? 0))); ?> |
                Avg GEO Score: <span class="tk-badge <?php echo esc_attr($geo_avg_class); ?>"><?php echo esc_html((string) $geo_avg); ?>/100</span>
            </p>
            <?php if (!empty($geo_items)) : ?>
                <table class="widefat striped">
                    <thead><tr><th>URL</th><th>Status</th><th>Grade</th><th>Score</th><th>Schema</th><th>Semantic</th><th>Freshness</th><th>Issues</th></tr></thead>
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
                            <td><a href="<?php echo esc_url((string) ($item['url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($item['url'] ?? '')); ?></a></td>
                            <td><?php echo esc_html((string) ((int) ($item['status'] ?? 0))); ?></td>
                            <td><strong><?php echo esc_html((string) ($item['grade'] ?? 'F')); ?></strong></td>
                            <td><span class="tk-badge <?php echo esc_attr($item_score_class); ?>"><?php echo esc_html((string) $item_score); ?>/100</span></td>
                            <td>
                                <span class="tk-badge <?php echo !empty($schema_item['valid']) ? 'tk-on' : 'tk-off'; ?>">JSON-LD</span>
                                <span class="tk-badge <?php echo !empty($schema_item['entity_ok']) ? 'tk-on' : 'tk-off'; ?>">Entity</span>
                                <?php if (!empty($schema_item['types']) && is_array($schema_item['types'])) : ?>
                                    <div class="description"><?php echo esc_html(implode(', ', array_slice($schema_item['types'], 0, 6))); ?></div>
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
            <?php endif; ?>
        <?php else : ?>
            <p class="description">No GEO audit report yet.</p>
        <?php endif; ?>
    </div>

    <div class="tk-card" style="margin-top:16px;">
        <h3>Content SEO Audit</h3>
        <p>Audit recent content for basic on-page SEO issues: structure, length, links, and image alt text.</p>
        <p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:8px;">
                <?php tk_nonce_field('tk_seo_content_audit_scan'); ?>
                <input type="hidden" name="action" value="tk_seo_content_audit_scan">
                <button class="button">Run Audit</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                <?php tk_nonce_field('tk_seo_content_audit_clear'); ?>
                <input type="hidden" name="action" value="tk_seo_content_audit_clear">
                <button class="button">Clear Audit</button>
            </form>
        </p>

        <?php if (!empty($audit_report)) : ?>
            <?php
            $priority_counts = isset($audit_report['priority_counts']) && is_array($audit_report['priority_counts']) ? $audit_report['priority_counts'] : array();
            $critical_count = (int) ($priority_counts['critical'] ?? 0);
            $high_count = (int) ($priority_counts['high'] ?? 0);
            $medium_count = (int) ($priority_counts['medium'] ?? 0);
            $low_count = (int) ($priority_counts['low'] ?? 0);
            $avg_score = (int) ($audit_report['average_score'] ?? 0);
            $avg_score_class = 'tk-priority-low';
            if ($avg_score < 50) {
                $avg_score_class = 'tk-priority-critical';
            } elseif ($avg_score < 70) {
                $avg_score_class = 'tk-priority-high';
            } elseif ($avg_score < 85) {
                $avg_score_class = 'tk-priority-medium';
            }
            ?>
            <p class="description">
                Last audit: <?php echo !empty($audit_report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $audit_report['scanned_at'])) : '-'; ?> |
                Checked: <?php echo esc_html((string) ((int) ($audit_report['checked_posts'] ?? 0))); ?> |
                Flagged: <?php echo esc_html((string) ((int) ($audit_report['flagged_count'] ?? 0))); ?> |
                Avg Score: <span class="tk-badge <?php echo esc_attr($avg_score_class); ?>"><?php echo esc_html((string) $avg_score); ?>/100</span>
            </p>
            <p class="description">
                Priority summary:
                Critical: <strong><?php echo esc_html((string) $critical_count); ?></strong>,
                High: <strong><?php echo esc_html((string) $high_count); ?></strong>,
                Medium: <strong><?php echo esc_html((string) $medium_count); ?></strong>,
                Low: <strong><?php echo esc_html((string) $low_count); ?></strong>
            </p>
            <?php if ($critical_count > 0 || $high_count > 0) : ?>
                <?php tk_notice('Priority action: focus on Critical and High pages first, then rerun audit after updates.', 'warning'); ?>
            <?php endif; ?>
            <?php $audit_items = isset($audit_report['items']) && is_array($audit_report['items']) ? $audit_report['items'] : array(); ?>
            <?php if (!empty($audit_items)) : ?>
                <table class="widefat striped">
                    <thead><tr><th>Post</th><th>Score</th><th>Priority</th><th>Words</th><th>Links (Int/Ext)</th><th>Trust Signals</th><th>Issues</th></tr></thead>
                    <tbody>
                    <?php foreach ($audit_items as $item) : ?>
                        <?php $pid = (int) ($item['post_id'] ?? 0); ?>
                        <?php $score = (int) ($item['score'] ?? 0); ?>
                        <?php $priority = (string) ($item['priority'] ?? 'low'); ?>
                        <?php $trust = is_array($item['trust_signals'] ?? null) ? $item['trust_signals'] : array(); ?>
                        <?php $priority_class = 'tk-priority-low'; ?>
                        <?php if ($priority === 'critical') { $priority_class = 'tk-priority-critical'; } ?>
                        <?php if ($priority === 'high') { $priority_class = 'tk-priority-high'; } ?>
                        <?php if ($priority === 'medium') { $priority_class = 'tk-priority-medium'; } ?>
                        <tr>
                            <td>
                                <?php if ($pid > 0) : ?>
                                    <a href="<?php echo esc_url(get_edit_post_link($pid)); ?>"><?php echo esc_html((string) ($item['title'] ?? 'Post')); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html((string) ($item['title'] ?? 'Post')); ?>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo esc_html((string) $score); ?>/100</strong></td>
                            <td><span class="tk-badge <?php echo esc_attr($priority_class); ?>"><?php echo esc_html(ucfirst($priority)); ?></span></td>
                            <td><?php echo esc_html((string) ((int) ($item['words'] ?? 0))); ?></td>
                            <td><?php echo esc_html((string) ((int) ($item['internal_links'] ?? 0))); ?> / <?php echo esc_html((string) ((int) ($item['external_links'] ?? 0))); ?></td>
                            <td>
                                <span class="tk-badge <?php echo !empty($trust['author']) ? 'tk-on' : 'tk-off'; ?>">Author</span>
                                <span class="tk-badge <?php echo !empty($trust['author_bio']) ? 'tk-on' : 'tk-off'; ?>">Bio</span>
                                <span class="tk-badge <?php echo (!empty($trust['published_date']) && !empty($trust['modified_date'])) ? 'tk-on' : 'tk-off'; ?>">Dates</span>
                                <span class="tk-badge <?php echo !empty($trust['references']) ? 'tk-on' : 'tk-off'; ?>">Refs</span>
                                <span class="tk-badge <?php echo !empty($trust['related_content']) ? 'tk-on' : 'tk-off'; ?>">Related</span>
                            </td>
                            <td>
                                <?php
                                $issues = is_array($item['issues'] ?? null) ? $item['issues'] : array();
                                echo empty($issues) ? 'OK' : esc_html(implode('; ', $issues));
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="description">No issues found in latest audit.</p>
            <?php endif; ?>
        <?php else : ?>
            <p class="description">No audit report yet.</p>
        <?php endif; ?>
    </div>
    <?php
}

function tk_seo_opt_save() {
    tk_require_admin_post('tk_seo_opt_save');
    tk_update_option('seo_enabled', !empty($_POST['seo_enabled']) ? 1 : 0);
    tk_update_option('seo_meta_desc_enabled', !empty($_POST['seo_meta_desc_enabled']) ? 1 : 0);
    tk_update_option('seo_canonical_enabled', !empty($_POST['seo_canonical_enabled']) ? 1 : 0);
    tk_update_option('seo_og_enabled', !empty($_POST['seo_og_enabled']) ? 1 : 0);
    tk_update_option('seo_schema_enabled', !empty($_POST['seo_schema_enabled']) ? 1 : 0);
    tk_update_option('seo_noindex_search', !empty($_POST['seo_noindex_search']) ? 1 : 0);
    tk_update_option('seo_noindex_404', !empty($_POST['seo_noindex_404']) ? 1 : 0);
    tk_update_option('seo_noindex_paged_archives', !empty($_POST['seo_noindex_paged_archives']) ? 1 : 0);

    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-seo', 'tk_saved' => 1), admin_url('admin.php')) . '#settings');
    exit;
}
