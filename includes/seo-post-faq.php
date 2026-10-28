<?php
if (!defined('ABSPATH')) { exit; }

function tk_seo_enqueue_faq_editor(): void {
    $screen = get_current_screen();
    if (!tk_seo_editor_panel_visible('faq') || !$screen || $screen->base !== 'post' || !tk_is_admin_user()
        || !in_array($screen->post_type, tk_seo_selected_post_types(), true)) {
        return;
    }
    wp_enqueue_style('tk-post-faq', TK_URL . 'assets/post-faq.css', array(), tk_asset_version('assets/post-faq.css'));
    wp_enqueue_script('tk-post-faq', TK_URL . 'assets/post-faq.js', array(), tk_asset_version('assets/post-faq.js'), true);
}

function tk_seo_render_faq_meta_box($post): void {
    $post_id = is_object($post) ? (int) $post->ID : 0;
    $faq_items = tk_geo_normalize_faq_items(get_post_meta($post_id, '_tk_geo_faq_items', true));
    $faq_default_language = function_exists('tk_geo_normalize_language_tag') ? tk_geo_normalize_language_tag((string) get_bloginfo('language'), 'id') : 'id';
    $faq_language_order = function_exists('tk_geo_bilingual_language_order') ? tk_geo_bilingual_language_order() : array();
    wp_nonce_field('tk_seo_faq_meta_box', 'tk_seo_faq_meta_nonce');
    ?>
    <div class="tk-post-faq" data-default-language="<?php echo esc_attr($faq_default_language ?: 'id'); ?>" data-language-order="<?php echo esc_attr(wp_json_encode($faq_language_order)); ?>">
        <input type="hidden" name="tk_geo_post_faq" value="<?php echo esc_attr(wp_json_encode($faq_items)); ?>" disabled>
        <p class="description">Question and answer pairs for FAQPage schema and AI answers. Save the page to publish changes.</p>
        <p><button type="button" class="button tk-faq-open"><span class="dashicons dashicons-editor-help" aria-hidden="true"></span> Edit FAQ <span class="tk-faq-count"><?php echo esc_html((string) count($faq_items)); ?></span></button></p>
        <?php if (!tk_seo_post_feature_enabled('geo', $post_id)) : ?>
            <p class="description">GEO is off for this content, so the FAQ is saved but not output.</p>
        <?php endif; ?>
        <dialog class="tk-faq-dialog" aria-labelledby="tk-faq-title">
            <div class="tk-faq-header"><h2 id="tk-faq-title">FAQ GEO</h2><button type="button" class="button tk-faq-cancel" aria-label="Close" title="Close"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></div>
            <div class="tk-faq-body"><div class="tk-faq-rows"></div>
            <p class="tk-faq-error" role="alert" hidden></p>
            </div>
            <div class="tk-faq-footer">
                <button type="button" class="button tk-faq-add"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> Add FAQ</button>
                <button type="button" class="button tk-faq-cancel">Cancel</button>
                <button type="button" class="button button-primary tk-faq-apply">Apply</button>
            </div>
        </dialog>
    </div>
    <?php
}

function tk_seo_save_faq_meta_box($post_id, $post): void {
    $post_id = (int) $post_id;
    if (!is_object($post) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if (!isset($_POST['tk_seo_faq_meta_nonce']) || !is_string($_POST['tk_seo_faq_meta_nonce'])
        || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tk_seo_faq_meta_nonce'])), 'tk_seo_faq_meta_box')) {
        return;
    }
    if (!current_user_can('edit_post', $post_id) || !in_array(get_post_type($post_id), tk_seo_selected_post_types(), true)) {
        return;
    }
    // The field stays disabled until Apply, so untouched FAQs are never rewritten.
    if (isset($_POST['tk_geo_post_faq']) && is_string($_POST['tk_geo_post_faq'])) {
        $faq = json_decode(wp_unslash($_POST['tk_geo_post_faq']), true);
        if (is_array($faq)) {
            update_post_meta($post_id, '_tk_geo_faq_items', tk_geo_normalize_faq_items($faq));
        }
    }
}
