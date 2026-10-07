<?php
if (!defined('ABSPATH')) { exit; }

function tk_seo_content_fix_post(int $post_id): array {
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish' || $post->post_password !== '' ||
        !current_user_can('edit_post', $post_id) || !is_post_type_viewable($post->post_type) ||
        !in_array($post->post_type, tk_seo_selected_post_types(), true)) {
        return array('post_id' => $post_id, 'messages' => array('Skipped: content is not public or editable.'), 'changed' => false);
    }
    $messages = array();
    $updates = array('ID' => $post_id);
    if (trim((string) $post->post_excerpt) === '') {
        $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes($post->post_content))) ?? '');
        if ($text === '') {
            $url = get_permalink($post_id);
            if (wp_parse_url($url, PHP_URL_HOST) === wp_parse_url(home_url('/'), PHP_URL_HOST)) {
                $response = wp_safe_remote_get($url, array('timeout' => 8, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES));
                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200 &&
                    stripos((string) wp_remote_retrieve_header($response, 'content-type'), 'text/html') !== false && class_exists('DOMDocument')) {
                    $rendered = tk_seo_parse_rendered_audit(wp_remote_retrieve_body($response));
                    if (!in_array('Missing main landmark; text includes full page.', $rendered['issues'], true)) {
                        $text = (string) $rendered['text_sample'];
                    }
                }
            }
        }
        if ($text !== '') {
            $updates['post_excerpt'] = wp_trim_words($text, 30, '…');
        } else {
            $messages[] = 'Excerpt needs review: no readable source content found.';
        }
    }
    // Only restore known attachment alt text; never invent image descriptions.
    if (class_exists('WP_HTML_Tag_Processor') && $post->post_content !== '') {
        $html = new WP_HTML_Tag_Processor($post->post_content);
        $restored = 0;
        while ($html->next_tag('IMG')) {
            $alt = $html->get_attribute('alt');
            if (is_string($alt) && trim($alt) !== '') continue;
            // Empty alt can intentionally mark a decorative image.
            if ($alt !== null) continue;
            $classes = (string) $html->get_attribute('class');
            $attachment = preg_match('/\bwp-image-(\d+)\b/', $classes, $match) ? (int) $match[1] : 0;
            if (!$attachment || get_post_type($attachment) !== 'attachment') continue;
            $known_alt = trim((string) get_post_meta($attachment, '_wp_attachment_image_alt', true));
            if ($known_alt === '') continue;
            $html->set_attribute('alt', $known_alt);
            $restored++;
        }
        if ($restored > 0) $updates['post_content'] = $html->get_updated_html();
    }
    $changed = count($updates) > 1;
    if ($changed) {
        // wp_update_post expects slashed data; retain literal backslashes in blocks.
        $result = wp_update_post(wp_slash($updates), true);
        if (is_wp_error($result)) {
            return array('post_id' => $post_id, 'changed' => false, 'messages' => array('Save failed: ' . $result->get_error_message()));
        }
        if (isset($updates['post_excerpt'])) $messages[] = 'Missing excerpt filled from existing content.';
        if (isset($updates['post_content'])) $messages[] = 'Image alt restored from attachment metadata.';
    }
    if (!$messages) $messages[] = 'No automatic changes needed.';
    $messages[] = 'Remaining content, keyword, link and trust issues require editorial review.';
    return array('post_id' => $post_id, 'changed' => $changed, 'messages' => $messages);
}

function tk_seo_content_fix_authorize(): void {
    if (!tk_is_admin_user()) wp_send_json_error(array('message' => 'Forbidden'), 403);
    check_ajax_referer('tk_seo_content_fix', 'nonce');
}

function tk_seo_content_fix_ajax(): void {
    tk_seo_content_fix_authorize();
    $post_id = absint($_POST['post_id'] ?? 0);
    $report = tk_get_option('seo_content_audit_report', array());
    $ids = array_map(function ($item) { return (int) ($item['post_id'] ?? 0); }, is_array($report) ? (array) ($report['items'] ?? array()) : array());
    if (!$post_id || !in_array($post_id, $ids, true)) wp_send_json_error(array('message' => 'Post is not in the saved audit.'), 400);
    $result = tk_seo_content_fix_post($post_id);
    $saved = tk_get_option('seo_content_fix_report', array());
    $saved = is_array($saved) ? $saved : array();
    $saved['items'] = is_array($saved['items'] ?? null) ? $saved['items'] : array();
    $saved['items'][$post_id] = $result;
    $saved['items'] = array_slice($saved['items'], -200, null, true);
    $saved['updated_at'] = time();
    tk_update_option('seo_content_fix_report', $saved);
    wp_send_json_success($result);
}

function tk_seo_content_fix_finish_ajax(): void {
    tk_seo_content_fix_authorize();
    tk_update_option('seo_content_audit_report', tk_seo_run_content_audit());
    wp_send_json_success(array('message' => 'Audit refreshed.'));
}
