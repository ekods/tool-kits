<?php
if (!defined('ABSPATH')) { exit; }

function tk_seo_locale_available(): bool {
    foreach (array('pll_is_translated_post_type', 'pll_languages_list', 'pll_get_post_language', 'pll_get_post_translations', 'pll_get_post') as $function) {
        if (!function_exists($function)) { return false; }
    }
    return true;
}

/** Resolve only configured, linked translations that this editor can modify. */
function tk_seo_locale_targets(int $post_id): array {
    if (!tk_seo_locale_available()) { return array(); }
    $source = get_post($post_id);
    if (!$source || !current_user_can('edit_post', $post_id)
        || !in_array($source->post_type, tk_seo_selected_post_types(), true)
        || !pll_is_translated_post_type($source->post_type)) { return array(); }
    $language = pll_get_post_language($post_id, 'slug');
    if (!is_string($language) || $language === '') { return array(); }
    $slugs = pll_languages_list(array('hide_empty' => false, 'fields' => 'slug'));
    $names = pll_languages_list(array('hide_empty' => false, 'fields' => 'name'));
    $locales = pll_languages_list(array('hide_empty' => false, 'fields' => 'locale'));
    $translations = pll_get_post_translations($post_id);
    $targets = array();
    if (!is_array($slugs) || !is_array($translations)) { return $targets; }
    foreach ($slugs as $index => $slug) {
        if (!is_string($slug) || $slug === $language) { continue; }
        $id = (int) ($translations[$slug] ?? 0);
        if ($id <= 0 || $id === $post_id || (int) pll_get_post($post_id, $slug) !== $id) { continue; }
        $target = get_post($id);
        if (!$target || $target->post_type !== $source->post_type
            || in_array($target->post_status, array('trash', 'auto-draft', 'inherit'), true)
            || pll_get_post_language($id, 'slug') !== $slug || !current_user_can('edit_post', $id)) { continue; }
        $targets[$slug] = array('id' => $id, 'name' => (string) ($names[$index] ?? $slug), 'locale' => (string) ($locales[$index] ?? $slug));
    }
    return $targets;
}

function tk_seo_render_locale_copy(int $post_id): void {
    if (!tk_seo_locale_available()) { return; }
    $targets = tk_seo_locale_targets($post_id);
    ?>
    <div class="tk-seo-locale-copy" data-post-id="<?php echo esc_attr((string) $post_id); ?>" data-copy-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-copy-nonce="<?php echo esc_attr(wp_create_nonce('tk_seo_copy_locale_' . $post_id)); ?>">
        <strong>Copy to locale</strong>
        <?php if ($targets) : ?>
            <div class="tk-seo-locale-controls">
                <label for="tk-seo-copy-locale">Target locale</label>
                <select id="tk-seo-copy-locale"><option value="">Select a locale</option><?php foreach ($targets as $slug => $target) : ?><option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($target['name'] . ' (' . $target['locale'] . ')'); ?></option><?php endforeach; ?></select>
                <button type="button" class="button tk-seo-copy-locale-button" disabled>Copy to locale</button>
            </div>
            <p><label><input type="checkbox" class="tk-seo-copy-replace" value="1"> Replace existing SEO values in the target locale</label></p>
            <p class="description">Copy the current focus keyphrase, SEO title and meta description to the linked translation. By default, only empty target fields are filled. Automatic defaults stay automatic. Text is copied as-is; translate it afterward. Save/Update this page separately.</p>
            <p class="tk-seo-copy-status" role="status" aria-live="polite" hidden></p>
            <a class="tk-seo-copy-edit" hidden>Open target translation</a>
            <noscript><p class="description">Enable JavaScript to copy SEO fields between locales.</p></noscript>
        <?php else : ?>
            <p class="description">Create and link an editable translation in Polylang to copy SEO fields to another locale.</p>
        <?php endif; ?>
    </div>
    <?php
}

/** Copy a small allowlist; never copy IDs, slugs, core content or audit reports. */
function tk_seo_copy_locale(int $source_id, array $input) {
    if (!tk_is_admin_user() || !tk_seo_editor_panel_visible('content') || !current_user_can('edit_post', $source_id)) {
        return new WP_Error('forbidden', 'You cannot copy SEO content from this page.');
    }
    if (!is_string($input['nonce'] ?? null) || !wp_verify_nonce($input['nonce'], 'tk_seo_copy_locale_' . $source_id)) {
        return new WP_Error('nonce', 'This copy request has expired. Reload the editor and try again.');
    }
    $targets = tk_seo_locale_targets($source_id);
    $locale = $input['locale'] ?? null;
    if (!is_string($locale) || !isset($targets[$locale])) {
        return new WP_Error('locale', 'Select an editable, linked Polylang translation.');
    }
    $target_id = $targets[$locale]['id'];
    $fields = array('tk_seo_focus_keyword', 'tk_seo_title', 'tk_seo_meta_description');
    $initial = is_array($input['initial'] ?? null) ? $input['initial'] : array();
    $values = array();
    foreach ($fields as $field) {
        if (!is_string($input[$field] ?? null)) { return new WP_Error('fields', 'The SEO fields are incomplete. Reload the editor and try again.'); }
        $value = sanitize_text_field($input[$field]);
        // Do not freeze an inherited source excerpt/site default into another language.
        if ((string) get_post_meta($source_id, '_' . $field, true) === ''
            && is_string($initial[$field] ?? null) && $value === sanitize_text_field($initial[$field])) { continue; }
        if ($value !== '') { $values['_' . $field] = $value; }
    }
    $replace = ($input['replace'] ?? '') === '1';
    $copied = 0; $preserved = 0;
    foreach ($values as $key => $value) {
        $current = (string) get_post_meta($target_id, $key, true);
        if ($current !== '' && !$replace) { $preserved++; continue; }
        if ($current === $value) { continue; }
        update_post_meta($target_id, $key, wp_slash($value));
        if ((string) get_post_meta($target_id, $key, true) !== $value) {
            return new WP_Error('write', 'Some SEO fields could not be copied. Review the target translation before retrying.');
        }
        $copied++;
    }
    return array('copied' => $copied, 'preserved' => $preserved,
        'message' => $copied . ' SEO field(s) copied. ' . $preserved . ' existing field(s) preserved. Automatic defaults and blank source fields are skipped.',
        'edit_url' => esc_url_raw((string) get_edit_post_link($target_id, 'raw')));
}

function tk_seo_copy_locale_ajax(): void {
    $input = wp_unslash($_POST);
    $raw_id = $input['post_id'] ?? '';
    $source_id = is_string($raw_id) && ctype_digit($raw_id) ? (int) $raw_id : 0;
    if ($source_id <= 0) { wp_send_json_error(array('message' => 'Invalid source page.'), 400); }
    $result = tk_seo_copy_locale($source_id, $input);
    if (is_wp_error($result)) {
        $status = in_array($result->get_error_code(), array('forbidden', 'nonce'), true) ? 403 : ($result->get_error_code() === 'write' ? 500 : 400);
        wp_send_json_error(array('message' => $result->get_error_message()), $status);
    }
    wp_send_json_success($result);
}
