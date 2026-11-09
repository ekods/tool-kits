<?php
if (!defined('ABSPATH')) { exit; }

// Shared frontend/editor value resolution and persistence; no editor UI or audit engine.

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

function tk_seo_custom_social_image_url(int $post_id): string {
    if ($post_id <= 0) { return ''; }
    $image_id = (int) get_post_meta($post_id, '_tk_seo_social_image_id', true);
    if ($image_id <= 0 || !wp_attachment_is_image($image_id)) { return ''; }
    return esc_url_raw((string) wp_get_attachment_image_url($image_id, 'full'), array('http', 'https'));
}

function tk_seo_translated_social_image_url(int $post_id): string {
    if ($post_id <= 0 || !function_exists('pll_get_post_translations')) { return ''; }
    $translations = pll_get_post_translations($post_id);
    if (!is_array($translations)) { return ''; }
    $default = function_exists('pll_default_language') ? pll_default_language('slug') : '';
    if (is_string($default) && isset($translations[$default])) {
        $translations = array($default => $translations[$default]) + $translations;
    }
    $post_type = get_post_type($post_id);
    foreach ($translations as $id) {
        $id = (int) $id;
        if ($id <= 0 || $id === $post_id || get_post_type($id) !== $post_type) { continue; }
        $url = tk_seo_custom_social_image_url($id);
        if ($url !== '') { return $url; }
    }
    return '';
}

function tk_seo_post_social_image_url(int $post_id, bool $use_custom = true): string {
    $custom = $use_custom ? tk_seo_custom_social_image_url($post_id) : '';
    return $custom !== '' ? $custom : tk_seo_translated_social_image_url($post_id);
}

function tk_seo_social_preview_image(int $post_id, bool $use_custom = true): string {
    $custom = tk_seo_post_social_image_url($post_id, $use_custom);
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
