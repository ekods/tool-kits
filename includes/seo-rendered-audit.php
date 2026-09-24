<?php
if (!defined('ABSPATH')) { exit; }

function tk_seo_parse_rendered_audit(string $html): array {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $xpath = new DOMXPath($document);
    foreach ($xpath->query('//script|//style|//noscript|//svg') as $node) {
        $node->parentNode->removeChild($node);
    }
    $main = $xpath->query('//main')->item(0) ?: $xpath->query('//*[@role="main"]')->item(0);
    $scope = $main ?: $xpath->query('//body')->item(0);
    $text = $scope ? trim(preg_replace('/\s+/u', ' ', $scope->textContent) ?? '') : '';
    $description = trim($xpath->evaluate('string(//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content)'));
    $title = trim($xpath->evaluate('string(//title)'));
    $canonical = trim($xpath->evaluate('string(//link[@rel="canonical"]/@href)'));
    $h1 = $xpath->query('//h1')->length;
    $missing_alt = $scope ? $xpath->query('.//img[not(@alt)]', $scope)->length : 0;
    $issues = array();
    $score = 100;
    foreach (array(
        array($title === '', 15, 'Missing document title.'),
        array($description === '', 15, 'Missing meta description.'),
        array($canonical === '', 10, 'Missing canonical URL.'),
        array($h1 !== 1, 15, 'Expected one H1; found ' . $h1 . '.'),
        array(!$main, 10, 'Missing main landmark; text includes full page.'),
        array($text === '', 20, 'No readable server-rendered text.'),
        array($missing_alt > 0, min(15, $missing_alt * 5), 'Images without alt attributes: ' . $missing_alt . '.'),
    ) as $check) {
        if ($check[0]) { $score -= $check[1]; $issues[] = $check[2]; }
    }
    return array('score' => max(0, $score), 'issues' => $issues, 'title' => $title, 'description' => $description, 'canonical' => $canonical, 'h1_count' => $h1, 'text_sample' => mb_substr($text, 0, 900));
}

function tk_seo_post_rendered_audit(): void {
    $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
    if (!tk_is_admin_user() || !current_user_can('edit_post', $post_id)) { wp_die('Forbidden'); }
    check_admin_referer('tk_seo_post_rendered_audit_' . $post_id);
    if (!in_array(get_post_type($post_id), tk_seo_selected_post_types(), true)) { wp_die('Content type is disabled for SEO / GEO.'); }
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish' || $post->post_password !== '' || !is_post_type_viewable($post->post_type)) {
        wp_die('Only public published content can be audited.');
    }
    $url = get_permalink($post_id);
    if (wp_parse_url($url, PHP_URL_HOST) !== wp_parse_url(home_url('/'), PHP_URL_HOST)) { wp_die('Invalid site URL.'); }
    $response = wp_safe_remote_get($url, array('timeout' => 12, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES, 'headers' => array('User-Agent' => 'Tool Kits Rendered SEO Audit')));
    $report = array('scanned_at' => time(), 'url' => $url, 'post_modified' => $post->post_modified_gmt);
    if (is_wp_error($response)) {
        $report['error'] = $response->get_error_message();
    } elseif (wp_remote_retrieve_response_code($response) !== 200) {
        $report['error'] = 'Expected HTTP 200; received ' . wp_remote_retrieve_response_code($response) . '. Check permalink redirects.';
    } elseif (stripos((string) wp_remote_retrieve_header($response, 'content-type'), 'text/html') === false) {
        $report['error'] = 'Response is not HTML.';
    } else {
        $report += tk_seo_parse_rendered_audit((string) wp_remote_retrieve_body($response));
    }
    update_post_meta($post_id, '_tk_seo_rendered_audit', $report);
    wp_safe_redirect(get_edit_post_link($post_id, 'raw'));
    exit;
}

function tk_seo_render_public_audit_panel($post): void {
    if (!is_object($post) || $post->post_status !== 'publish' || $post->post_password !== '' || !is_post_type_viewable($post->post_type)) { return; }
    $report = get_post_meta($post->ID, '_tk_seo_rendered_audit', true);
    $url = wp_nonce_url(add_query_arg(array('action' => 'tk_seo_post_rendered_audit', 'post_id' => $post->ID), admin_url('admin-post.php')), 'tk_seo_post_rendered_audit_' . $post->ID);
    ?>
    <p><a class="button" href="<?php echo esc_url($url); ?>">Audit halaman publik</a></p>
    <?php if (is_array($report) && !empty($report['scanned_at'])) : ?>
        <p class="description"><?php echo esc_html(wp_date('Y-m-d H:i', $report['scanned_at'])); ?></p>
        <?php if (($report['post_modified'] ?? '') !== $post->post_modified_gmt) : ?><p>Konten berubah; jalankan audit ulang.</p><?php endif; ?>
        <?php if (!empty($report['error'])) : ?>
            <p><?php echo esc_html($report['error']); ?></p>
        <?php else : ?>
            <p><strong>Rendered SEO: <?php echo esc_html((string) $report['score']); ?>/100</strong></p>
            <details><summary>Hasil halaman publik</summary>
                <ul><?php foreach ($report['issues'] as $issue) : ?><li><?php echo esc_html($issue); ?></li><?php endforeach; ?></ul>
                <p><?php echo esc_html($report['text_sample']); ?></p>
            </details>
        <?php endif; ?>
    <?php endif;
}
