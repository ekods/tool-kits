<?php
if (!defined('ABSPATH')) { exit; }

function tk_seo_content_panel_enqueue(): void {
    $screen = get_current_screen();
    if (!tk_seo_editor_panel_visible('content') || !$screen || $screen->base !== 'post' || !tk_is_admin_user()
        || !in_array($screen->post_type, tk_seo_selected_post_types(), true)) { return; }
    if (current_user_can('upload_files')) { wp_enqueue_media(); }
    wp_enqueue_style('tk-seo-content-panel', TK_URL . 'assets/seo-content-panel.css', array(), tk_asset_version('assets/seo-content-panel.css'));
    wp_enqueue_script('tk-seo-content-panel', TK_URL . 'assets/seo-content-panel.js', array(), tk_asset_version('assets/seo-content-panel.js'), true);
}

/** Resolve defaults for this post explicitly; the admin query is not singular. */
function tk_seo_content_defaults($post): array {
    $site_name = tk_seo_title_text((string) get_bloginfo('name'));
    $title = implode(' ' . tk_seo_title_separator() . ' ', array_filter(array(tk_seo_title_text((string) get_the_title($post->ID)), $site_name)));
    $global = tk_seo_global_content();
    $front = (int) get_option('page_on_front') === (int) $post->ID;
    if ($front) {
        if ((int) tk_get_option('seo_title_override_enabled', 0) === 1) {
            $title = implode(' ' . tk_seo_title_separator() . ' ', array_filter(array($site_name, tk_seo_title_text((string) get_bloginfo('description')))));
        } elseif (tk_seo_global_enabled() && $global['title']['value'] !== '') {
            $title = $global['title']['value'];
        }
    }
    $description = tk_seo_global_enabled() ? $global['description']['value'] : '';
    if ($description === '') { $description = trim((string) ($post->post_excerpt ?? '')); }
    if ($description === '' && $front) { $description = $global['description']['value']; }
    if ($description === '') { $description = (string) get_the_excerpt($post); }
    if (trim($description) === '') { $description = (string) ($post->post_content ?? ''); }
    $description = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes($description))));
    if ($description === '') { $description = $global['description']['value']; }
    return array('title' => $title, 'description' => $description);
}

function tk_seo_length_feedback(string $field, string $text, int $min, int $max): void {
    $length = mb_strlen($text, 'UTF-8');
    $state = $length === 0 ? 'empty' : ($length < $min ? 'short' : ($length > $max ? 'long' : 'ideal'));
    $labels = array('empty' => 'Not set', 'short' => 'Too short', 'ideal' => 'Ideal', 'long' => 'Too long');
    ?>
    <div class="tk-seo-length-feedback" id="tk-seo-<?php echo esc_attr($field); ?>-length-status" data-length-feedback="<?php echo esc_attr($field); ?>" data-min="<?php echo esc_attr((string) $min); ?>" data-max="<?php echo esc_attr((string) $max); ?>" role="status" aria-live="polite" aria-atomic="true">
        <span class="tk-seo-length-status" data-state="<?php echo esc_attr($state); ?>"><?php echo esc_html($labels[$state]); ?></span>
        <span data-<?php echo esc_attr($field); ?>-length><?php echo esc_html($length . ' characters (suggested: ' . $min . '–' . $max . ').'); ?></span>
    </div>
    <?php
}

function tk_seo_custom_social_image_url(int $post_id): string {
    if ($post_id <= 0) { return ''; }
    $image_id = (int) get_post_meta($post_id, '_tk_seo_social_image_id', true);
    if ($image_id <= 0 || !wp_attachment_is_image($image_id)) { return ''; }
    return esc_url_raw((string) wp_get_attachment_image_url($image_id, 'full'), array('http', 'https'));
}

function tk_seo_social_preview_image(int $post_id, bool $use_custom = true): string {
    $custom = $use_custom ? tk_seo_custom_social_image_url($post_id) : '';
    if ($custom !== '') { return $custom; }
    if (tk_seo_global_enabled()) {
        $global = tk_seo_global_content();
        if ($global['image']['value'] !== '') { return $global['image']['value']; }
    }
    if (tk_seo_has_theme_managed_seo() && function_exists('cst_get_social_share_image_data')) {
        $image = cst_get_social_share_image_data($post_id);
        if (is_array($image) && is_string($image['url'] ?? null)) {
            $url = esc_url_raw($image['url'], array('http', 'https'));
            if ($url !== '') { return $url; }
        }
    }
    return esc_url_raw((string) tk_seo_og_image_url($post_id, $use_custom), array('http', 'https'));
}

function tk_seo_render_content_meta_box($post): void {
    $post_id = (int) $post->ID;
    $site_name = tk_seo_title_text((string) get_bloginfo('name'));
    $page_title = tk_seo_title_text((string) get_the_title($post_id));
    $separator = tk_seo_title_separator();
    $defaults = tk_seo_content_defaults($post);
    $default_title = $defaults['title'];
    $standard_title = implode(' ' . $separator . ' ', array_filter(array($page_title, $site_name)));
    $title_template = $default_title === $standard_title ? '%%title%% %%page%% %%sep%% %%sitename%%' : $default_title;
    $saved_title = (string) get_post_meta($post_id, '_tk_seo_title', true);
    $saved_description = (string) get_post_meta($post_id, '_tk_seo_meta_description', true);
    $title = $saved_title !== '' ? $saved_title : $title_template;
    $description = $saved_description !== '' ? $saved_description : $defaults['description'];
    $url = (string) get_permalink($post_id);
    $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    $icon = tk_seo_preview_favicon();
    $variables = tk_seo_variable_values($post);
    $preview_title = tk_seo_expand_variables($title, $post_id, $variables);
    $preview_description = tk_seo_expand_variables($description, $post_id, $variables);
    $social_image = tk_seo_social_preview_image($post_id);
    $social_default_image = tk_seo_social_preview_image(0);
    $social_custom_image = tk_seo_custom_social_image_url($post_id);
    $social_image_id = $social_custom_image !== '' ? (int) get_post_meta($post_id, '_tk_seo_social_image_id', true) : 0;
    $social_fallback_image = tk_seo_social_preview_image($post_id, false);
    wp_nonce_field('tk_seo_content_meta_box', 'tk_seo_content_meta_nonce');
    ?>
    <div class="tk-seo-post-editor" data-site-name="<?php echo esc_attr($site_name); ?>" data-page-title="<?php echo esc_attr($page_title); ?>" data-separator="<?php echo esc_attr($separator); ?>" data-variables="<?php echo esc_attr(wp_json_encode($variables)); ?>" data-default-title="<?php echo esc_attr($default_title); ?>" data-default-description="<?php echo esc_attr($defaults['description']); ?>" data-social-default-image="<?php echo esc_url($social_default_image); ?>" data-homepage="<?php echo (int) get_option('page_on_front') === $post_id ? '1' : '0'; ?>" data-url="<?php echo esc_url($url); ?>" data-slug="<?php echo esc_attr((string) $post->post_name); ?>">
        <?php tk_seo_render_locale_copy($post_id); ?>
        <details class="tk-seo-editor-section" open>
            <summary>Focus keyphrase</summary>
            <div class="tk-seo-editor-body">
                <label for="tk-seo-keyword">Focus keyphrase</label>
                <input id="tk-seo-keyword" name="tk_seo_focus_keyword" type="text" value="<?php echo esc_attr((string) get_post_meta($post_id, '_tk_seo_focus_keyword', true)); ?>" placeholder="Enter a focus keyphrase" aria-describedby="tk-seo-keyword-help">
                <p id="tk-seo-keyword-help" class="description">Use the main word or phrase you want your content found for across search and AI.</p>
            </div>
        </details>
        <details class="tk-seo-editor-section" open>
            <summary>Search appearance</summary>
            <div class="tk-seo-editor-body">
                <p class="description">Determine how your page should look in search results.</p>
                <?php if (!tk_seo_tools_enabled() || !tk_seo_post_feature_enabled('seo', $post_id)) : ?>
                    <p class="description">Enable SEO in Tool Kits settings and for this content to use the saved search settings.</p>
                <?php else : ?>
                    <p class="description">Saved page titles and descriptions take priority over theme and plugin metadata for this page. Blank fields use automatic defaults.</p>
                <?php endif; ?>
                <div class="tk-seo-device-preview">
                <input class="tk-seo-device-choice" type="radio" id="tk-seo-preview-mobile" name="tk_seo_preview_device" value="mobile" checked>
                <input class="tk-seo-device-choice" type="radio" id="tk-seo-preview-desktop" name="tk_seo_preview_device" value="desktop">
                <div class="tk-seo-preview-heading"><strong>Google preview</strong><div class="tk-seo-preview-controls"><label for="tk-seo-preview-mobile" data-preview-device="mobile">Mobile</label><label for="tk-seo-preview-desktop" data-preview-device="desktop">Desktop</label></div></div>
                <div class="tk-seo-search-preview" data-device="mobile">
                    <div class="tk-seo-preview-identity">
                        <img class="tk-seo-preview-favicon" <?php if ($icon !== '') : ?>src="<?php echo esc_url($icon); ?>"<?php else : ?>hidden<?php endif; ?> alt="" width="32" height="32"><span class="tk-seo-preview-icon" aria-hidden="true" <?php if ($icon !== '') : ?>hidden<?php endif; ?>><?php echo esc_html(function_exists('mb_substr') ? mb_substr($site_name, 0, 1) : substr($site_name, 0, 1)); ?></span>
                        <div><strong><?php echo esc_html($site_name); ?></strong><span><?php echo esc_html($host); ?></span></div>
                    </div>
                    <div class="tk-seo-preview-url"><?php echo esc_html($url); ?></div>
                    <div class="tk-seo-preview-title"><?php echo esc_html($preview_title ?: $default_title); ?></div>
                    <div class="tk-seo-preview-description"><?php echo esc_html($preview_description ?: 'Add a meta description below. Search engines may also use relevant text from your page.'); ?></div>
                </div>
                </div>
                <p class="description tk-seo-preview-note">Preview only. Search engines may display a different title or description.</p>
                <?php if ($saved_title === '') : ?><input type="hidden" name="tk_seo_initial[tk_seo_title]" value="<?php echo esc_attr($title); ?>"><?php endif; ?>
                <?php tk_seo_template_field('tk-seo-title', 'tk_seo_title', 'SEO title', $title, 'Enter an SEO title', 'tk-seo-title-help tk-seo-title-length-status'); ?>
                <meter class="tk-seo-length" min="0" max="70" low="30" high="60" optimum="50" value="<?php echo esc_attr((string) mb_strlen($preview_title, 'UTF-8')); ?>" aria-label="SEO title length"></meter>
                <?php tk_seo_length_feedback('title', $preview_title, 30, 60); ?>
                <p id="tk-seo-title-help" class="description">Insert a variable or type % to choose one. Leave blank to use the automatic title.</p>
                <label for="tk-seo-slug">Slug</label>
                <input type="hidden" name="tk_seo_original_slug" value="<?php echo esc_attr((string) $post->post_name); ?>">
                <input id="tk-seo-slug" name="tk_seo_slug" type="text" value="<?php echo esc_attr((string) $post->post_name); ?>" placeholder="Enter a URL slug" <?php if ((int) get_option('page_on_front') === $post_id) : ?>readonly="readonly"<?php endif; ?> aria-describedby="tk-seo-slug-help">
                <p id="tk-seo-slug-help" class="description">Changing the slug changes the page URL. WordPress resolves duplicate slugs when saving.</p>
                <?php if ($saved_description === '') : ?><input type="hidden" name="tk_seo_initial[tk_seo_meta_description]" value="<?php echo esc_attr($description); ?>"><?php endif; ?>
                <?php tk_seo_template_field('tk-seo-description', 'tk_seo_meta_description', 'Meta description', $description, 'Enter a meta description', 'tk-seo-description-help tk-seo-description-length-status', true); ?>
                <meter class="tk-seo-length" min="0" max="180" low="120" high="160" optimum="150" value="<?php echo esc_attr((string) mb_strlen($preview_description, 'UTF-8')); ?>" aria-label="Meta description length"></meter>
                <?php tk_seo_length_feedback('description', $preview_description, 120, 160); ?>
                <p id="tk-seo-description-help" class="description">Leave blank to generate a description from existing content. Length statuses are guidance; the full text is saved.</p>
                <p class="description">Save these settings with the page’s Save or Update button.</p>
            </div>
        </details>
        <details class="tk-seo-editor-section" open>
            <summary>Social media preview</summary>
            <div class="tk-seo-editor-body">
                <p class="description">Uses your SEO title and meta description. Upload or choose a custom Open Graph image, or leave it unset to use the Featured Image (thumbnail), with the site's social image as a fallback.</p>
                <p id="tk-seo-social-image-label"><strong>SEO Social Image</strong></p>
                <input type="hidden" id="tk-seo-social-image-id" name="tk_seo_social_image_id" value="<?php echo esc_attr((string) $social_image_id); ?>" data-custom-url="<?php echo esc_url($social_custom_image); ?>" data-fallback-url="<?php echo esc_url($social_fallback_image); ?>">
                <div class="tk-seo-social-image-controls" role="group" aria-labelledby="tk-seo-social-image-label" aria-describedby="tk-seo-social-image-help">
                    <?php if (current_user_can('upload_files')) : ?><button type="button" class="button tk-seo-social-image-select" hidden>Upload / choose image</button><?php endif; ?>
                    <button type="button" class="button tk-seo-social-image-reset" hidden>Use thumbnail</button>
                    <button type="button" class="button tk-seo-social-image-retry" hidden>Retry image</button>
                    <a class="button tk-seo-social-image-open" target="_blank" rel="noopener noreferrer" hidden>Open image</a>
                </div>
                <p class="description" id="tk-seo-social-image-help">Recommended size: <strong>1200 &times; 630 px</strong> (approximately 1.91:1). Use JPG or PNG, ideally under 1 MB. Keep important text and logos near the centre to allow for cropping.</p>
                <p class="description tk-seo-social-image-status" role="status" aria-live="polite"><?php echo $social_image_id > 0 ? 'Using a custom Open Graph image.' : 'Using the Featured Image (thumbnail), with the site image as a fallback.'; ?></p>
                <div class="tk-seo-social-preview">
                    <input class="tk-seo-device-choice tk-seo-social-choice" type="radio" id="tk-seo-social-facebook" name="tk_seo_social_network" value="facebook" checked>
                    <input class="tk-seo-device-choice tk-seo-social-choice" type="radio" id="tk-seo-social-linkedin" name="tk_seo_social_network" value="linkedin">
                    <div class="tk-seo-preview-heading"><strong>Open Graph preview</strong><div class="tk-seo-preview-controls"><label for="tk-seo-social-facebook">Facebook</label><label for="tk-seo-social-linkedin">LinkedIn</label></div></div>
                    <div class="tk-seo-social-card" data-network="facebook">
                        <div class="tk-seo-social-visual"><img class="tk-seo-social-image" loading="eager" decoding="async" referrerpolicy="no-referrer" <?php if ($social_image !== '') : ?>src="<?php echo esc_url($social_image); ?>"<?php else : ?>hidden<?php endif; ?> alt=""><span class="tk-seo-social-image-empty" <?php if ($social_image !== '') : ?>hidden<?php endif; ?>>No social image</span></div>
                        <div class="tk-seo-social-copy"><span class="tk-seo-social-domain"><?php echo esc_html($host); ?></span><strong class="tk-seo-social-title"><?php echo esc_html($preview_title ?: $default_title); ?></strong><p class="tk-seo-social-description"><?php echo esc_html($preview_description); ?></p><span class="tk-seo-social-url"><?php echo esc_html($url); ?></span></div>
                    </div>
                </div>
                <p class="description">Illustrative preview. Social platforms may display the shared link differently.</p>
            </div>
        </details>
        <details class="tk-seo-editor-section">
            <summary>SEO analysis</summary>
            <div class="tk-seo-editor-body">
                <?php
                $report = $post->post_status !== 'auto-draft' ? tk_seo_run_content_audit($post_id) : array();
                $item = $report['items'][0] ?? null;
                if (is_array($item)) : ?>
                    <p><strong>SEO Score: <?php echo esc_html((string) $item['score']); ?>/100</strong> · <?php echo esc_html(ucfirst($item['priority'])); ?> priority</p>
                    <meter min="0" max="100" low="50" high="85" optimum="100" value="<?php echo esc_attr((string) $item['score']); ?>" aria-label="SEO content score" class="tk-seo-length"></meter>
                    <p class="description">Analysis uses saved content. Save your changes to refresh the score. Page-builder content and rendered schema require separate review.</p>
                    <ul class="tk-seo-analysis-issues"><?php foreach ($item['issues'] as $issue) : ?><li><?php echo esc_html($issue); ?></li><?php endforeach; ?></ul>
                    <?php tk_seo_content_checks_render($item); ?>
                <?php else : ?>
                    <p class="description">Save a draft to calculate the score.</p>
                <?php endif; ?>
            </div>
        </details>
    </div>
    <?php
}

function tk_seo_content_can_save(int $post_id): bool {
    return $post_id > 0 && !wp_is_post_revision($post_id) && !wp_is_post_autosave($post_id)
        && isset($_POST['tk_seo_content_meta_nonce']) && is_string($_POST['tk_seo_content_meta_nonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tk_seo_content_meta_nonce'])), 'tk_seo_content_meta_box')
        && current_user_can('edit_post', $post_id)
        && in_array(get_post_type($post_id), tk_seo_selected_post_types(), true);
}

function tk_seo_save_content_meta_box($post_id, $post): void {
    if (!is_object($post) || !tk_seo_content_can_save((int) $post_id)) { return; }
    foreach (array('tk_seo_focus_keyword', 'tk_seo_title', 'tk_seo_meta_description') as $field) {
        if (!isset($_POST[$field]) || !is_string($_POST[$field])) { continue; }
        $value = sanitize_text_field(wp_unslash($_POST[$field]));
        $initial = is_array($_POST['tk_seo_initial'] ?? null) ? ($_POST['tk_seo_initial'][$field] ?? null) : null;
        // Showing a default as a value must not freeze it into a custom override.
        if ((string) get_post_meta((int) $post_id, '_' . $field, true) === '' && is_string($initial)
            && $value === sanitize_text_field(wp_unslash($initial))) { continue; }
        update_post_meta((int) $post_id, '_' . $field, $value);
    }
    if (isset($_POST['tk_seo_social_image_id']) && is_string($_POST['tk_seo_social_image_id'])) {
        $raw_id = wp_unslash($_POST['tk_seo_social_image_id']);
        if (preg_match('/^\d+$/D', $raw_id)) {
            $image_id = (int) $raw_id;
            if ($image_id === 0 || (current_user_can('upload_files') && wp_attachment_is_image($image_id))) {
                update_post_meta((int) $post_id, '_tk_seo_social_image_id', $image_id);
            }
        }
    }
}

function tk_seo_content_slug_filter($data, $postarr) {
    $post_id = (int) ($postarr['ID'] ?? 0);
    if (!tk_seo_content_can_save($post_id) || !isset($_POST['tk_seo_slug']) || !is_string($_POST['tk_seo_slug'])
        || (int) get_option('page_on_front') === $post_id) { return $data; }
    $slug = sanitize_title(wp_unslash($_POST['tk_seo_slug']));
    if ($slug === '') { return $data; }
    if (isset($_POST['tk_seo_original_slug']) && is_string($_POST['tk_seo_original_slug'])
        && $slug === sanitize_title(wp_unslash($_POST['tk_seo_original_slug']))) { return $data; }
    // wp_insert_post_data runs after WordPress's first uniqueness check.
    $data['post_name'] = wp_unique_post_slug($slug, $post_id, $data['post_status'], $data['post_type'], (int) $data['post_parent']);
    return $data;
}

function tk_seo_content_title_filter($title) {
    if (!tk_seo_content_output_enabled()) { return $title; }
    $custom = tk_seo_expand_variables((string) get_post_meta(get_queried_object_id(), '_tk_seo_title', true), (int) get_queried_object_id());
    return $custom !== '' ? esc_html($custom) : $title;
}
