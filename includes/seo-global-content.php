<?php
if (!defined('ABSPATH')) { exit; }

function tk_seo_global_fields(): array {
    return array(
        'title' => array('label' => 'Homepage SEO title', 'theme' => array('themes_seo_title'), 'mods' => array('seo_title', 'meta_title')),
        'description' => array('label' => 'Default meta description', 'theme' => array('themes_description'), 'mods' => array('seo_description', 'meta_description'), 'multiline' => true),
        'author' => array('label' => 'Default author', 'theme' => array('themes_author'), 'mods' => array('seo_author', 'meta_author')),
        'publisher' => array('label' => 'Publisher / organization name', 'theme' => array('themes_publisher'), 'mods' => array('seo_publisher', 'meta_publisher')),
        'keywords' => array('label' => 'Global keywords', 'theme' => array('themes_keyword'), 'mods' => array('seo_keywords', 'meta_keywords'), 'multiline' => true),
        'image' => array('label' => 'Default social image URL', 'theme' => array('themes_og_image', 'themes_default_article_image', 'themes_logo_color', 'themes_logo_secondary'), 'mods' => array('seo_og_image', 'og_image'), 'url' => true),
        'service_name' => array('label' => 'Service name', 'theme' => array('themes_service_name'), 'mods' => array()),
        'service_description' => array('label' => 'Service description', 'theme' => array('themes_service_description'), 'mods' => array(), 'multiline' => true),
    );
}

function tk_seo_global_clean(string $field, $value): string {
    if (!is_scalar($value)) { return ''; }
    $value = trim((string) $value);
    if ($field === 'image') {
        $url = esc_url_raw($value, array('http', 'https'));
        return preg_match('~^https?://~i', $url) ? $url : '';
    }
    return in_array($field, array('description', 'keywords', 'service_description'), true)
        ? sanitize_textarea_field($value) : sanitize_text_field($value);
}

// Resolve every time so changing Theme Settings immediately changes this view.
// Inherited values are never copied into the Tool Kits option.
function tk_seo_global_content(): array {
    $saved = tk_get_option('seo_global_content', array());
    $saved = is_array($saved) ? $saved : array();
    $managed = tk_seo_has_theme_managed_seo();
    $result = array();
    foreach (tk_seo_global_fields() as $field => $definition) {
        $value = ''; $source = ''; $inherited = false;
        foreach ($definition['theme'] as $key) {
            if ($key === 'themes_description' && function_exists('myprefix_get_theme_option_lang')) {
                $value = tk_seo_global_clean($field, myprefix_get_theme_option_lang($key));
            }
            if ($value === '') { $value = tk_seo_global_clean($field, tk_seo_theme_option($key, '')); }
            if ($value !== '') { $source = 'Theme Settings'; $inherited = true; break; }
        }
        if ($value === '' && function_exists('get_theme_mod')) {
            foreach ($definition['mods'] as $key) {
                $value = tk_seo_global_clean($field, get_theme_mod($key, ''));
                if ($value !== '') { $source = 'Theme Customizer'; $inherited = true; break; }
            }
        }
        if ($value === '' && $managed && in_array($field, array('title', 'publisher'), true)) {
            $value = tk_seo_global_clean($field, get_bloginfo('name'));
            if ($value !== '') { $source = 'WordPress Site Title'; $inherited = true; }
        }
        $local = tk_seo_global_clean($field, $saved[$field] ?? '');
        if ($value === '' && $local !== '') { $value = $local; $source = 'Tool Kits'; }
        if ($value === '') {
            if (in_array($field, array('title', 'publisher'), true)) {
                $value = tk_seo_global_clean($field, get_bloginfo('name'));
                if ($value !== '') { $source = 'WordPress Site Title'; }
            } elseif ($field === 'description') {
                $value = tk_seo_global_clean($field, get_bloginfo('description'));
                if ($value !== '') { $source = 'WordPress Tagline'; }
            }
        }
        $result[$field] = array('value' => $value, 'source' => $source ?: 'Not configured', 'inherited' => $inherited, 'local' => $local);
    }
    return $result;
}

function tk_seo_global_enabled(): bool {
    return (int) tk_get_option('seo_global_enabled', 0) === 1 && tk_seo_tools_enabled();
}

function tk_seo_global_save(): void {
    tk_require_admin_post('tk_seo_global_save');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('Use the Global SEO Content form to save changes.'); }
    $posted = tk_post('seo_global_content', array());
    if (!is_array($posted)) { wp_die('Enter valid global SEO content.'); }
    $resolved = tk_seo_global_content();
    $values = array();
    foreach (tk_seo_global_fields() as $field => $definition) {
        if ($resolved[$field]['inherited']) { continue; }
        if (isset($posted[$field]) && !is_string($posted[$field])) { wp_die('Enter a valid ' . strtolower($definition['label']) . '.'); }
        $value = tk_seo_global_clean($field, $posted[$field] ?? '');
        if ($field === 'image' && trim((string) ($posted[$field] ?? '')) !== '' && $value === '') { wp_die('Enter an HTTP or HTTPS social image URL.'); }
        if ($value !== '') { $values[$field] = $value; }
    }
    tk_update_option('seo_global_content', $values);
    tk_update_option('seo_global_enabled', tk_post('seo_global_enabled', '') === '1' ? 1 : 0);
    if (function_exists('tk_page_cache_purge')) { tk_page_cache_purge(); }
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-seo&tk_global_saved=1') . '#global-content');
    exit;
}

function tk_seo_global_render_panel(): void {
    $resolved = tk_seo_global_content();
    ?>
    <div class="tk-card tk-tab-panel" data-panel-id="global-content" id="global-content">
        <h3>Global SEO Content</h3>
        <p>Review site-wide SEO defaults and their sources. Values supplied by your theme stay linked to Theme Settings and update here automatically.</p>
        <p><a class="button" href="<?php echo esc_url(admin_url(function_exists('myprefix_get_theme_option') ? 'admin.php?page=theme-settings' : 'customize.php')); ?>">Edit Theme Settings</a> <a class="button" href="<?php echo esc_url(admin_url('options-general.php')); ?>">Edit Site Title &amp; Tagline</a></p>
        <?php if (tk_seo_has_third_party_plugin()): ?><p class="description">Your SEO plugin manages frontend metadata. These defaults remain available for review; Tool Kits global metadata output is disabled.</p><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_seo_global_save'); ?>
            <input type="hidden" name="action" value="tk_seo_global_save">
            <p><label><input type="checkbox" name="seo_global_enabled" value="1" <?php checked((int) tk_get_option('seo_global_enabled', 0), 1); ?>> Enable global metadata fallbacks</label><br><span class="description">Requires Enable SEO Toolkit. Fill missing metadata while preserving tags provided by the theme or page. Page-specific excerpts, authors and featured images take priority. The SEO title applies to the homepage; keywords do not replace page focus keywords.</span></p>
            <div class="tk-grid tk-grid-2" style="gap:20px;">
            <?php foreach (tk_seo_global_fields() as $field => $definition): $item = $resolved[$field]; ?>
                <div>
                    <p><label for="tk-global-<?php echo esc_attr($field); ?>"><strong><?php echo esc_html($definition['label']); ?></strong></label> <span class="tk-badge <?php echo $item['inherited'] ? 'tk-on' : ($item['value'] === '' ? 'tk-warn' : ''); ?>"><?php echo $item['inherited'] ? 'Linked' : ($item['value'] === '' ? 'Not configured' : 'Default'); ?></span></p>
                    <?php $attributes = $item['inherited'] ? ' readonly' : ' name="seo_global_content[' . esc_attr($field) . ']"'; $value = $item['inherited'] ? $item['value'] : $item['local']; ?>
                    <?php if (!empty($definition['multiline'])): ?>
                        <textarea id="tk-global-<?php echo esc_attr($field); ?>" class="large-text" rows="3" placeholder="<?php echo esc_attr($item['value']); ?>"<?php echo $attributes; ?>><?php echo esc_textarea($value); ?></textarea>
                    <?php else: ?>
                        <input id="tk-global-<?php echo esc_attr($field); ?>" class="large-text" type="<?php echo !empty($definition['url']) ? 'url' : 'text'; ?>" value="<?php echo esc_attr($value); ?>" placeholder="<?php echo esc_attr($item['value']); ?>"<?php echo $attributes; ?>>
                    <?php endif; ?>
                    <p class="description">Source: <?php echo esc_html($item['source']); ?><?php if ($item['inherited']): ?>. Edit this value at its source.<?php elseif ($item['local'] === '' && $item['value'] !== ''): ?>. Leave blank to keep this default.<?php endif; ?></p>
                    <?php if ($field === 'title' && $item['inherited'] && $item['source'] === 'WordPress Site Title'): ?><p class="description">The active theme formats the homepage title using this site name.</p><?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
            <p class="description">Service name and description are shared with Tool Kits GEO organization/service schema. Global descriptions are fallbacks and do not replace each page's content.</p>
            <p><button class="button button-primary">Save Global SEO Content</button></p>
        </form>
    </div>
    <?php
}

function tk_seo_global_document_title($title) {
    if (!tk_seo_global_enabled() || is_admin() || !is_front_page() || !tk_seo_post_feature_enabled('seo')
        || tk_seo_has_third_party_plugin() || tk_seo_has_theme_managed_seo()) { return $title; }
    $content = tk_seo_global_content();
    return $content['title']['local'] !== '' ? $content['title']['value'] : $title;
}

function tk_seo_global_start(): void {
    if (is_admin() || wp_doing_ajax() || is_feed() || is_preview() || is_search() || is_404()
        || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', array('GET', 'HEAD'), true)
        || !tk_seo_global_enabled() || tk_seo_has_third_party_plugin() || !tk_seo_post_feature_enabled('seo')) { return; }
    if (is_singular() && (post_password_required() || get_post_status() !== 'publish')) { return; }
    $content = tk_seo_global_content();
    $values = array_map(function($item) { return $item['value']; }, $content);
    $values['description'] = tk_seo_generate_description();
    $identity = tk_seo_meta_identity();
    $values['author'] = $identity['author']; $values['publisher'] = $identity['publisher'];
    $values['image'] = tk_seo_og_image_url();
    if ((int) tk_get_option('seo_meta_desc_enabled', 1) !== 1) { unset($values['description']); }
    if ((int) tk_get_option('seo_og_enabled', 1) !== 1) { unset($values['image']); }
    if (!is_front_page()) { unset($values['title']); }
    ob_start(function($html) use ($values) {
        if (http_response_code() >= 300) { return $html; }
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) { return $html; }
        }
        return tk_seo_global_fill_html($html, $values);
    });
}

function tk_seo_global_fill_html(string $html, array $values): string {
    // Inspect only the real head and preserve all existing nonempty tags,
    // including tags emitted after wp_head by a theme.
    $attributes = '(?:"[^"]*"|\'[^\']*\'|[^\'">])*';
    $tokens = '~<!--.*?-->|<(script|style|textarea|title)\b' . $attributes . '>.*?</\1\s*>|<head\b' . $attributes . '>|</head\s*>~is';
    preg_match_all($tokens, $html, $matches, PREG_OFFSET_CAPTURE);
    $start = null; $length = -1;
    foreach ($matches[0] as $match) {
        if ($start === null && preg_match('/^<head\b/i', $match[0])) { $start = $match[1] + strlen($match[0]); }
        if ($start !== null && preg_match('/^<\/head\s*>/i', $match[0])) { $length = $match[1] - $start; break; }
    }
    if ($length < 0) { return $html; }
    $head = substr($html, $start, $length);
    $metadata = tk_geo_crawler_metadata($head);
    $tags = array(); $edits = array();
    foreach (array('title', 'description', 'author', 'publisher', 'keywords', 'image') as $field) {
        $value = trim((string) ($values[$field] ?? ''));
        if ($value === '') { continue; }
        if (!empty($metadata[$field])) { continue; }
        if ($field === 'title') { $tags[] = '<title>' . esc_html($value) . '</title>'; }
        elseif ($field === 'image') { $tags[] = '<meta property="og:image" content="' . esc_url($value) . '">'; }
        else { $tags[] = '<meta name="' . esc_attr($field) . '" content="' . esc_attr($value) . '">'; }
        foreach (($metadata['empty'][$field] ?? array()) as $empty) { $edits[] = $empty; }
    }
    if (!$tags) { return $html; }
    usort($edits, function($a, $b) { return $b[0] <=> $a[0]; });
    foreach ($edits as $edit) { $head = substr_replace($head, '', $edit[0], $edit[1]); }
    return substr_replace($html, $head . "\n" . implode("\n", $tags) . "\n", $start, $length);
}
