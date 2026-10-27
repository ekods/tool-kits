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
    $title_settings = tk_seo_title_posted_settings();
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
    foreach ($title_settings as $key => $value) { tk_update_option($key, $value); }
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
        <?php if (tk_seo_has_third_party_plugin()): ?><p class="description">Your SEO plugin manages frontend metadata unless Global Metadata Override or Meta Title Override is enabled here.</p><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_seo_global_save'); ?>
            <input type="hidden" name="action" value="tk_seo_global_save">
            <?php tk_seo_title_render_settings(); ?>
            <p><label><input type="checkbox" name="seo_global_enabled" value="1" <?php checked((int) tk_get_option('seo_global_enabled', 0), 1); ?>> Enable global metadata override</label><br><span class="description">Requires Enable SEO Toolkit. Replace existing theme/plugin metadata with the values shown below and remove duplicate tags. Saved page SEO titles, meta descriptions and social images take priority. The SEO title applies to the homepage; keywords do not replace page focus keywords. Uncheck to restore theme/plugin metadata.</span></p>
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
                    <?php if ($field === 'image'): ?><p class="description">Used for Open Graph, Twitter Cards, Image Source and Schema.org image metadata when social output is enabled. A saved page SEO Social Image takes priority; Featured Images are used when no social image is configured.</p><?php endif; ?>
                    <?php if ($field === 'title' && $item['inherited'] && $item['source'] === 'WordPress Site Title'): ?><p class="description">This value follows the WordPress site name.</p><?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
            <p class="description">Service name and description are shared with Tool Kits GEO organization/service schema. Metadata overrides do not change visible page content.</p>
            <p><button class="button button-primary">Save Global SEO Content</button></p>
        </form>
    </div>
    <?php
}

function tk_seo_global_document_title($title) {
    if (!tk_seo_global_enabled() || is_admin() || wp_doing_ajax() || is_feed() || is_preview()
        || (defined('REST_REQUEST') && REST_REQUEST)
        || !is_front_page() || !tk_seo_post_feature_enabled('seo')) { return $title; }
    if (is_singular()) {
        $custom = tk_seo_expand_variables((string) get_post_meta(get_queried_object_id(), '_tk_seo_title', true), (int) get_queried_object_id());
        if ($custom !== '') { return esc_html($custom); }
    }
    $content = tk_seo_global_content();
    return $content['title']['value'] !== '' ? esc_html($content['title']['value']) : $title;
}

function tk_seo_global_start(): void {
    if (is_admin() || wp_doing_ajax() || is_feed() || is_preview() || is_search() || is_404()
        || (defined('REST_REQUEST') && REST_REQUEST)
        || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', array('GET', 'HEAD'), true)
        || !tk_seo_global_enabled() || !tk_seo_post_feature_enabled('seo')) { return; }
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
    elseif ((int) tk_get_option('seo_title_override_enabled', 0) === 1) { $values['title'] = tk_seo_title_value(); }
    ob_start(function($html) use ($values) {
        $status = http_response_code();
        if ($status && $status !== 200) { return $html; }
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) { return $html; }
        }
        return tk_seo_global_fill_html($html, $values);
    });
}

function tk_seo_global_fill_html(string $html, array $values): string {
    // Keep the function name for existing callers; checked now means override.
    return tk_seo_content_replace_html($html, $values, (int) tk_get_option('seo_og_enabled', 1) === 1);
}
