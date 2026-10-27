<?php
// Saved form -> resolved variables -> final public HTML, without live DB writes.
require __DIR__ . '/seo-content-fixture.php';
define('WPSEO_VERSION', 'fixture');
$state['theme'] = true;
$post->post_status = 'publish';
$post->post_password = '';
$options['seo_title_override_enabled'] = 1;
$_SERVER['REQUEST_METHOD'] = 'GET';
http_response_code(200);
function verify_output($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    $GLOBALS['output_checks'] = ($GLOBALS['output_checks'] ?? 0) + 1;
}
function render_output_fixture(string $html, bool $global_title = false): string {
    $level = ob_get_level(); ob_start();
    tk_seo_content_output_start();
    if ($global_title) { tk_seo_title_start(); }
    echo $html;
    while (ob_get_level() > $level + 1) { ob_end_flush(); }
    return ob_get_clean();
}
tk_seo_opt_init();
verify_output(in_array(array('template_redirect', 'tk_seo_content_output_start', 2), $hooks, true), 'The page override must wrap later metadata buffers inside page caching');
verify_output(in_array(array('template_redirect', 'tk_seo_global_start', 3), $hooks, true), 'Global metadata must wrap late theme/plugin buffers after explicit page overrides');
$title_hooks = array_values(array_filter($hooks, function ($hook) { return $hook[0] === 'pre_get_document_title'; }));
verify_output(end($title_hooks) === array('pre_get_document_title', 'tk_seo_content_title_filter', PHP_INT_MAX), 'Saved page titles must run after the global title formula');

$description = str_repeat('Complete description with full words. ', 6) . 'Reviewed & approved "🧭".';
$_POST = array('tk_seo_content_meta_nonce' => 'fixture', 'tk_seo_title' => '%%title%% | %%sitename%%', 'tk_seo_meta_description' => $description);
tk_seo_save_content_meta_box(42, $post);
$values = tk_seo_content_output_values(42);
verify_output($values === array('title' => 'Company | BRANDKRAFT', 'description' => $description), 'Form values must resolve exactly without truncation');
verify_output(tk_seo_content_title_filter('Theme/plugin title') === 'Company | BRANDKRAFT', 'Theme and plugin title filters must not hide the saved page title');
verify_output(tk_seo_title_value() === 'Company | BRANDKRAFT', 'The global title override must honor page-specific content');

$samples = '<!-- <meta name="description" content="Comment example"> -->'
    . '<script>const demo = "</head><title>Script example</title><meta name=description content=fake>";</script>'
    . '<style>/* <meta name="description" content="Style example"> */</style>'
    . '<template><title>Template example</title><meta name="description" content="Template"></template>'
    . '<noscript><meta name="description" content="Noscript example"></noscript>'
    . '<svg><title>Icon title</title></svg>';
$untouched = '<meta name="robots" content="noindex,follow"><link rel="canonical" href="https://brandkraft.id/company/">'
    . '<meta name="author" content="Author"><meta property="og:image" content="https://brandkraft.id/keep-image.jpg">'
    . '<script type="application/ld+json">{"name":"Keep schema"}</script>';
$body = '<body><main><h1>Company</h1><p>Keep page content.</p><svg><title>Body icon</title></svg></main></body></html>';
$html = '<!doctype html><html><head data-x=">">' . $samples
    . '<TITLE>Old theme title</TITLE><title>Duplicate plugin title</title>'
    . '<meta content="Old theme description" NAME=Description><META NAME="description" content="Duplicate">'
    . '<meta property="og:title" content="Old social title"><meta name="og:title" content="Duplicate social">'
    . '<meta property="og:description" content="Old social description">'
    . '<meta name="twitter:title" content="Old Twitter title"><meta name="twitter:description" content="Old Twitter description">'
    . $untouched . '</head>' . $body;
$output = render_output_fixture($html, true);
$report = tk_seo_parse_rendered_audit($output);
verify_output($report['title'] === $values['title'] && $report['description'] === $description, 'Rendered head metadata must equal saved values with theme/plugin/global overrides active');
verify_output($report['title_count'] === 1 && $report['description_count'] === 1, 'There must be one real title and meta description');
$nested = '<template data-example=">"><template><title>Inner fake</title></template><title>Outer fake</title><script>const demo = "</template>";</script></template>';
$nested_report = tk_seo_parse_rendered_audit('<html><head>' . $nested . '<title>Actual title</title><meta name="description" content="Actual description"></head><body><main>Readable</main></body></html>');
verify_output($nested_report['title'] === 'Actual title' && $nested_report['description'] === 'Actual description' && $nested_report['title_count'] === 1,
    'Nested inert HTML5 examples must not hide or impersonate public metadata on older libxml');
verify_output(tk_seo_audit_readable_html('<svg/><math /><title>Keep</title>') === '<title>Keep</title>', 'Self-closing foreign content must not consume following metadata');
verify_output(strpos($output, $samples) !== false && strpos($output, $untouched) !== false && substr($output, -strlen($body)) === $body, 'Examples, robots, canonical, author, image, schema and body must stay unchanged');
verify_output(strpos($output, '%%title%%') === false && strpos($output, '%%sitename%%') === false, 'Variable placeholders must not leak into public metadata');
verify_output(substr_count($output, '<meta property="og:title" content="Company | BRANDKRAFT">') === 1, 'Social title duplicates must collapse to the saved title');
verify_output(strpos($output, '<meta name="twitter:description" content="' . esc_attr($description) . '">') !== false, 'Existing social descriptions must agree with the saved description');
verify_output(tk_seo_content_replace_html($output, $values) === $output, 'Metadata replacement must be idempotent');
verify_output(tk_seo_rendered_metadata_matches($report, $values) === array('title' => true, 'description' => true), 'Public audit must confirm matching saved metadata');
$stale = tk_seo_parse_rendered_audit('<html><head><title>Cached title</title><meta name="description" content="Cached description"></head><body></body></html>');
verify_output(tk_seo_rendered_metadata_matches($stale, $values) === array('title' => false, 'description' => false), 'Public audit must catch old cached values');
$duplicate = $report; $duplicate['description_count'] = 2;
verify_output(tk_seo_rendered_metadata_matches($duplicate, $values)['description'] === false, 'Duplicate metadata cannot be reported as a successful match');

$empty = '<html><head></head><body>Keep</body></html>';
$filled = render_output_fixture($empty);
verify_output(strpos($filled, '<title>Company | BRANDKRAFT</title>') !== false && strpos($filled, 'property="og:title"') !== false, 'Missing metadata must be inserted');
$description_only = tk_seo_content_replace_html($html, array('description' => $description));
verify_output(strpos($description_only, '<TITLE>Old theme title</TITLE>') !== false, 'Only explicitly owned fields may replace theme metadata');
verify_output(strpos(tk_seo_content_replace_html($empty, $values, false), 'property="og:') === false, 'Disabled OG generation must not create social tags');
$image_values = $values + array('image' => 'https://brandkraft.id/new-image.jpg');
verify_output(strpos(tk_seo_content_replace_html($html, $image_values), 'property="og:image" content="https://brandkraft.id/new-image.jpg"') !== false, 'The selected social image must reach final Open Graph output');
foreach (array('{"title":"API"}', '{"example":"<head><title>Fake</title></head>"}', '<rss><title>Feed</title></rss>', '<html><head><title>Partial</title>') as $non_html) {
    verify_output(tk_seo_content_replace_html($non_html, $values) === $non_html, 'Non-HTML or incomplete responses must stay unchanged');
}

foreach (array('admin', 'ajax', 'feed', 'preview', 'protected') as $flag) {
    $state[$flag] = true;
    verify_output(render_output_fixture($html) === $html, 'Unsafe request buffered: ' . $flag);
    $state[$flag] = false;
}
foreach (array('draft', 'private') as $status) {
    $post->post_status = $status;
    verify_output(render_output_fixture($html) === $html, 'Non-public content overridden');
}
$post->post_status = 'publish';
foreach (array(301, 404, 500) as $status) {
    http_response_code($status);
    verify_output(render_output_fixture($html) === $html, 'HTTP response guard failed: ' . $status);
}
http_response_code(200);
$_SERVER['REQUEST_METHOD'] = 'POST';
verify_output(render_output_fixture($html) === $html, 'POST response overridden');
$_SERVER['REQUEST_METHOD'] = 'GET';
$options['seo_enabled'] = 0;
verify_output(render_output_fixture($html) === $html, 'Global SEO opt-out ignored');
$options['seo_enabled'] = 1; $meta['_tk_seo_enabled'] = '0';
verify_output(render_output_fixture($html) === $html, 'Per-page SEO opt-out ignored');
$meta['_tk_seo_enabled'] = '1'; $state['licensed'] = false;
verify_output(render_output_fixture($html) === $html, 'License guard ignored');
$state['licensed'] = true;
$meta['_tk_seo_title'] = $meta['_tk_seo_meta_description'] = '';
verify_output(render_output_fixture($html) === $html, 'Clearing custom values must restore theme/plugin ownership');
$attachment_urls = array(90 => 'https://brandkraft.id/custom-og.jpg');
$featured = array(42 => 'https://brandkraft.id/thumbnail.jpg');
$_POST = array('tk_seo_content_meta_nonce' => 'fixture', 'tk_seo_social_image_id' => '90');
tk_seo_save_content_meta_box(42, $post);
verify_output($meta['_tk_seo_social_image_id'] === 90 && tk_seo_social_preview_image(42) === $attachment_urls[90]
    && tk_seo_og_image_url(42) === $attachment_urls[90], 'A saved custom image must override thumbnails in previews and public OG output');
$image_only_html = '<html><head><title>Theme title</title><meta property="og:image" content="old"><meta property="og:image" content="duplicate"><meta name="twitter:image" content="old"></head><body>Keep</body></html>';
$image_only = render_output_fixture($image_only_html);
verify_output(strpos($image_only, '<title>Theme title</title>') !== false && substr_count($image_only, 'property="og:image"') === 1
    && strpos($image_only, 'property="og:image" content="' . $attachment_urls[90] . '"') !== false
    && strpos($image_only, 'name="twitter:image" content="' . $attachment_urls[90] . '"') !== false, 'An image-only edit must reach the public head without requiring title or description overrides');
$post->post_status = 'auto-draft';
ob_start(); tk_seo_render_content_meta_box($post); $image_panel = ob_get_clean();
$post->post_status = 'publish';
verify_output(strpos($image_panel, 'name="tk_seo_social_image_id" value="90"') !== false
    && strpos($image_panel, 'Using a custom Open Graph image.') !== false, 'Reloading the editor must preserve the chosen image');
foreach (array('999', '-90', '90invalid', array('90')) as $bad_image) {
    $_POST['tk_seo_social_image_id'] = $bad_image; tk_seo_save_content_meta_box(42, $post);
    verify_output($meta['_tk_seo_social_image_id'] === 90, 'Invalid or non-image attachment values must not overwrite the saved image');
}
foreach (array('nonce', 'can_edit', 'can_upload') as $guard) {
    $state[$guard] = false; $_POST['tk_seo_social_image_id'] = '90'; $meta['_tk_seo_social_image_id'] = 0;
    tk_seo_save_content_meta_box(42, $post);
    verify_output($meta['_tk_seo_social_image_id'] === 0, 'Custom image save must respect ' . $guard);
    $state[$guard] = true;
}
$_POST['tk_seo_social_image_id'] = '90'; tk_seo_save_content_meta_box(42, $post);
$options['seo_og_enabled'] = 0;
verify_output(render_output_fixture($image_only_html) === $image_only_html, 'Disabled OG output must ignore image-only overrides');
$options['seo_og_enabled'] = 1;
unset($attachment_urls[90]);
verify_output(tk_seo_social_preview_image(42) === $featured[42], 'A deleted custom attachment must fall back to the thumbnail');
$attachment_urls[90] = 'https://brandkraft.id/custom-og.jpg';
$_POST['tk_seo_social_image_id'] = '0'; tk_seo_save_content_meta_box(42, $post);
verify_output($meta['_tk_seo_social_image_id'] === 0 && tk_seo_social_preview_image(42) === $featured[42]
    && tk_seo_og_image_url(42) === $featured[42], 'Use thumbnail must clear the custom selection and restore the Featured Image');
verify_output(render_output_fixture($image_only_html) === $image_only_html, 'Clearing the custom image must restore theme metadata ownership');
define('REST_REQUEST', true);
verify_output(render_output_fixture($html) === $html, 'REST request overridden');
echo 'PASS: ' . $output_checks . " saved-content/public-output checks, including theme/plugin conflicts, duplicate removal, audit mismatches, escaping, full descriptions and request guards\n";
