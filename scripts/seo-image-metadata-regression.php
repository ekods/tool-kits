<?php
// Exercise all public image outputs without loading WordPress or writing its DB.
require __DIR__ . '/seo-content-fixture.php';
require dirname(__DIR__) . '/includes/geo-crawler-fixes.php';
function get_locale() { return 'en_US'; }
function wp_get_document_title() { return 'Company | BRANDKRAFT'; }
function sanitize_email($value) { return filter_var($value, FILTER_SANITIZE_EMAIL); }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function is_search() { return false; }
function is_404() { return false; }
function image_check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    $GLOBALS['image_checks'] = ($GLOBALS['image_checks'] ?? 0) + 1;
}
function image_values(string $html): array {
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    // Older libxml relocates head tags when JS examples contain </head>.
    $html = preg_replace('~<!--.*?-->|<script\b[^>]*>.*?</script\s*>~is', '', $html);
    $dom->loadHTML($html); libxml_clear_errors(); libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($dom);
    $values = array();
    foreach (array('og:image' => '//head/meta[@property="og:image"]/@content',
        'twitter:image' => '//head/meta[@name="twitter:image"]/@content',
        'twitter:card' => '//head/meta[@name="twitter:card"]/@content',
        'image_src' => '//head/link[@rel="image_src"]/@href',
        'schema:image' => '//head/meta[@itemprop="image"]/@content') as $key => $query) {
        $values[$key] = array();
        foreach ($xpath->query($query) as $node) { $values[$key][] = $node->nodeValue; }
    }
    return $values;
}
function expect_image(string $html, string $image): void {
    foreach (image_values($html) as $key => $values) {
        image_check($values === array($key === 'twitter:card' ? 'summary_large_image' : $image), 'Wrong/missing/duplicate ' . $key);
    }
}
function buffered_image_output(string $html, string $callback): string {
    $level = ob_get_level(); ob_start(); $callback(); echo $html;
    while (ob_get_level() > $level + 1) { ob_end_flush(); }
    return ob_get_clean();
}

$image = 'https://brandkraft.id/share.jpg?size=full&crop=off';
$empty = '<html><head></head><body>Page content</body></html>';
$attachment_urls = array(90 => $image);
$meta['_tk_seo_social_image_id'] = 90;
$post->post_status = 'publish';
$_SERVER['REQUEST_METHOD'] = 'GET';
http_response_code(200);

// Native wp_head, including featured/global/logo fallbacks, uses the same image.
ob_start(); tk_seo_render_head_tags(); $head = ob_get_clean();
expect_image('<html><head>' . $head . '</head></html>', $image);
image_check(strpos($head, 'name="twitter:title"') !== false && strpos($head, 'name="twitter:description"') !== false, 'Native Twitter text missing');
unset($meta['_tk_seo_social_image_id']);
$featured = array(42 => 'https://brandkraft.id/featured.jpg');
ob_start(); tk_seo_render_head_tags(); $head = ob_get_clean();
expect_image('<html><head>' . $head . '</head></html>', $featured[42]);
$featured = array(); $options['seo_global_enabled'] = 1;
$options['seo_global_content'] = array('image' => 'https://brandkraft.id/default.jpg');
ob_start(); tk_seo_render_head_tags(); $head = ob_get_clean();
expect_image('<html><head>' . $head . '</head></html>', $options['seo_global_content']['image']);
unset($options['seo_global_content']); $theme_mods = array('custom_logo' => 90);
ob_start(); tk_seo_render_head_tags(); $head = ob_get_clean();
expect_image('<html><head>' . $head . '</head></html>', $image);
$theme_mods = array();
ob_start(); tk_seo_render_head_tags(); $head = ob_get_clean();
foreach (image_values('<html><head>' . $head . '</head></html>') as $values) { image_check(!$values, 'Empty image emitted metadata'); }

// Explicit page edits fill absent fields and replace stale duplicates, even on managed themes.
$meta['_tk_seo_social_image_id'] = 90; $state['theme'] = true;
expect_image(buffered_image_output($empty, 'tk_seo_content_output_start'), $image);
$schema = '<script type="application/ld+json">{"@type":"WebPage","image":"original.jpg"}</script>';
$body = '<body itemscope itemtype="https://schema.org/Product"><img itemprop="image" src="product.jpg"></body></html>';
$examples = '<!-- <link rel="image_src" href="fake"> --><script>const demo = \'</head><meta itemprop="image" content="fake">\';</script>';
$stale = '<html><head><title>Keep title</title><link rel="canonical" href="https://brandkraft.id/company/">' . $schema . $examples;
foreach (array('og:image' => 'old.jpg', 'twitter:image' => 'old.jpg', 'twitter:card' => 'summary', 'image_src' => 'old.jpg', 'schema:image' => 'old.jpg') as $key => $value) {
    $stale .= tk_seo_content_head_tag($key, $value) . tk_seo_content_head_tag($key, 'duplicate');
}
$stale .= '</head>' . $body;
$fixed = buffered_image_output($stale, 'tk_seo_content_output_start');
expect_image($fixed, $image);
image_check(strpos($fixed, $schema . $examples) !== false && substr($fixed, -strlen($body)) === $body
    && strpos($fixed, '<title>Keep title</title>') !== false && strpos($fixed, 'rel="canonical"') !== false, 'Unrelated markup changed');
image_check(buffered_image_output($fixed, 'tk_seo_content_output_start') === $fixed, 'Explicit image output is not idempotent');

// Global overrides must work without a custom title/description or existing OG tag.
unset($meta['_tk_seo_social_image_id']); $options['seo_global_content'] = array('image' => $image);
expect_image(buffered_image_output($empty, 'tk_seo_global_start'), $image);
$valid = '<html><head><meta property="og:image" content="theme-og.jpg"><meta name="twitter:image" content="theme-twitter.jpg">'
    . '<meta name="twitter:card" content="summary"><link rel="image_src" href="theme-source.jpg"><meta itemprop="image" content="theme-schema.jpg"></head>' . $body;
expect_image(tk_seo_global_fill_html($valid, array('image' => $image)), $image);
$missing = '<html><head><meta property="og:image" content="theme-og.jpg"><meta name="twitter:image" content="">'
    . '<link rel="image_src" href=""><meta itemprop="image" content=""></head>' . $body;
$filled = tk_seo_global_fill_html($missing, array('image' => $image));
$values = image_values($filled);
image_check($values['og:image'] === array($image), 'Existing OG image was not replaced');
foreach (array('twitter:image', 'image_src', 'schema:image') as $key) { image_check($values[$key] === array($image), 'Empty image tag not repaired: ' . $key); }
image_check(tk_seo_global_fill_html($filled, array('image' => $image)) === $filled, 'Global image output is not idempotent');

// The existing social-output switch controls every image representation.
$options['seo_og_enabled'] = 0;
$meta['_tk_seo_social_image_id'] = 90;
image_check(buffered_image_output($empty, 'tk_seo_content_output_start') === $empty, 'Disabled social output changed an image-only page');
$output = buffered_image_output($empty, 'tk_seo_global_start');
foreach (image_values($output) as $values) { image_check(!$values, 'Disabled global social output emitted image metadata'); }
$state['theme'] = false;
ob_start(); tk_seo_render_head_tags(); $head = ob_get_clean();
foreach (image_values('<html><head>' . $head . '</head></html>') as $values) { image_check(!$values, 'Disabled native social output emitted image metadata'); }
foreach (array('{"image":"API"}', '<html><head>', '<rss><title>Feed</title></rss>') as $non_html) {
    image_check(tk_seo_content_replace_html($non_html, array('image' => $image)) === $non_html, 'Non-HTML/incomplete response modified');
}
echo 'PASS: ' . $image_checks . " Twitter, Image Source and Schema.org image checks across native, per-page and global output\n";
