<?php
// php scripts/schema-merge-regression.php — Custom JSON-LD and the generated SEO graph define each @id once.
// Fixtures mirror a live site where Custom JSON-LD repeated WebSite, Organization and the home page WebPage.
define('ABSPATH', __DIR__ . '/');
$GLOBALS['tk_test'] = array('admin' => false, 'uri' => '/our-story/', 'options' => array(), 'seo' => true);
function tk_get_option($key, $default = null) { return $GLOBALS['tk_test']['options'][$key] ?? $default; }
function is_admin() { return $GLOBALS['tk_test']['admin']; }
function is_singular() { return !empty($GLOBALS['tk_test']['post']); }
function get_queried_object_id() { return (int) ($GLOBALS['tk_test']['post'] ?? 0); }
function get_post_meta($id, $key, $single = false) { return $GLOBALS['tk_test']['meta'][$key] ?? ''; }
function tk_seo_expand_variables($value, $post_id = 0) { return (string) $value; }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function has_filter($hook) { return false; }
function tk_license_features_enabled() { return true; }
function sanitize_title($value) { return strtolower(preg_replace('/[^a-z0-9]+/i', '-', $value)); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function wpautop($value) { return '<p>' . $value . '</p>'; }
function wp_enqueue_style() {}
function tk_asset_version($path) { return '1'; }
define('TK_URL', 'https://site.example/wp-content/plugins/tool-kits/');
function home_url($path = '/') { return 'https://site.example' . $path; }
function get_bloginfo($key) { return $key === 'language' ? 'en-US' : 'Site'; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function untrailingslashit($value) { return rtrim((string) $value, '/\\'); }
function wp_get_document_title() { return 'Our Story'; }
function tk_seo_current_url() { return home_url($GLOBALS['tk_test']['uri']); }
function tk_seo_build_schema_graph($url, $title, $description) {
    if (!$GLOBALS['tk_test']['seo']) return array();
    $site = 'https://site.example/';
    return array('@context' => 'https://schema.org', '@graph' => array(
        array('@type' => 'Organization', '@id' => $site . '#organization', 'url' => $site, 'name' => 'Site', 'description' => 'Generated', 'contactPoint' => array(array('@type' => 'ContactPoint', 'availableLanguage' => array('en', 'id')))),
        array('@type' => 'WebSite', '@id' => $site . '#website', 'url' => $site, 'name' => 'Site', 'publisher' => array('@id' => $site . '#organization')),
        array('@type' => 'WebPage', '@id' => $url . '#webpage', 'url' => $url, 'name' => $title, 'isPartOf' => array('@id' => $site . '#website')),
    ));
}
require dirname(__DIR__) . '/includes/geo.php';

$checks = 0;
function check_merge($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
// Count full definitions per @id across all printed documents (references do not count).
function defined_ids(array $docs): array {
    $ids = array();
    $walk = function ($node) use (&$walk, &$ids) {
        if (!is_array($node)) return;
        if (isset($node['@id']) && is_string($node['@id']) && count(array_diff(array_keys($node), array('@id', '@context'))) > 0) $ids[$node['@id']] = ($ids[$node['@id']] ?? 0) + 1;
        foreach ($node as $child) $walk($child);
    };
    foreach ($docs as $doc) $walk($doc);
    return $ids;
}
function node_by_id(array $doc, string $id): array {
    foreach ($doc['@graph'] as $node) if (($node['@id'] ?? '') === $id) return $node;
    return array();
}
$custom = array('@context' => 'https://schema.org', '@graph' => array(
    array('@type' => 'WebSite', '@id' => 'https://site.example/#website', 'url' => 'https://site.example/', 'name' => 'Site', 'inLanguage' => 'en-US', 'publisher' => array('@id' => 'https://site.example/#organization')),
    array('@type' => 'Organization', '@id' => 'https://site.example/#organization', 'name' => 'Site', 'description' => 'Curated', 'telephone' => '+62 800', 'sameAs' => array('https://social.example/site'),
        'contactPoint' => array('@type' => 'ContactPoint', 'availableLanguage' => array('en'))),
    array('@type' => 'WebPage', '@id' => 'https://site.example/#webpage', 'url' => 'https://site.example/', 'name' => 'Home', 'inLanguage' => 'en-US'),
    array('@type' => 'TouristTrip', '@id' => 'https://site.example/#komodo-trip', 'name' => 'Komodo trip'),
));
$GLOBALS['tk_test']['options']['geo_custom_jsonld'] = json_encode($custom);

// Inner page: one document, every @id defined once, the home page WebPage dropped, custom values kept.
$docs = tk_geo_build_schema_documents();
$ids = defined_ids($docs);
check_merge(count($docs) === 1 && max($ids) === 1, 'Inner page still repeats an @id: ' . json_encode($ids));
check_merge(!isset($ids['https://site.example/#webpage']) && isset($ids['https://site.example/our-story/#webpage']), 'Home page WebPage leaked onto an inner page');
$org = node_by_id($docs[0], 'https://site.example/#organization');
check_merge($org['description'] === 'Curated' && $org['telephone'] === '+62 800' && $org['url'] === 'https://site.example/', 'Custom Organization values did not win or generated values were lost');
check_merge($org['contactPoint']['availableLanguage'] === array('en'), 'Conflicting contactPoint was not replaced by the custom value');
check_merge(node_by_id($docs[0], 'https://site.example/#website')['inLanguage'] === 'en-US' && node_by_id($docs[0], 'https://site.example/#komodo-trip')['name'] === 'Komodo trip', 'Custom-only properties or nodes were dropped');

// Home page (with a query string): the custom WebPage merges into the generated one.
$GLOBALS['tk_test']['uri'] = '/?utm_source=x';
$docs = tk_geo_build_schema_documents();
$ids = defined_ids($docs);
check_merge(max($ids) === 1 && count($docs[0]['@graph']) === 4, 'Home page still repeats an @id: ' . json_encode($ids));

// No generated graph (third-party SEO plugin or SEO schema off): Custom JSON-LD prints alone, minus another URL's page node.
$GLOBALS['tk_test']['seo'] = false;
$GLOBALS['tk_test']['uri'] = '/our-story/';
$docs = tk_geo_build_schema_documents();
$ids = defined_ids($docs);
check_merge(count($docs) === 1 && !isset($ids['https://site.example/#webpage']) && isset($ids['https://site.example/#organization']), 'Custom JSON-LD alone kept the home page WebPage');
$GLOBALS['tk_test']['uri'] = '/';
check_merge(tk_geo_build_schema_documents() === array($custom), 'Custom JSON-LD without foreign page nodes was rewritten');
$GLOBALS['tk_test']['options']['geo_custom_jsonld'] = json_encode(array('@context' => 'https://schema.org', '@type' => 'WebPage', '@id' => 'https://site.example/#webpage', 'name' => 'Home'));
$GLOBALS['tk_test']['uri'] = '/our-story/';
check_merge(tk_geo_build_schema_documents() === array(), 'A custom document holding only another page was printed');

// Admin previews describe the home page instead of dropping custom page nodes for the wp-admin URL.
$GLOBALS['tk_test']['admin'] = true;
check_merge(count(tk_geo_build_schema_documents()) === 1, 'Admin preview dropped the home page WebPage');
$GLOBALS['tk_test']['admin'] = false;

// Relative @id values and non-page nodes are never filtered; list documents are flattened.
check_merge(tk_geo_schema_node_belongs_here(array('@type' => 'WebPage', '@id' => '#webpage'), 'https://site.example/a/'), 'Relative page @id was filtered');
check_merge(tk_geo_schema_node_belongs_here(array('@type' => 'Service', '@id' => 'https://site.example/#service'), 'https://site.example/a/'), 'Site-wide entity was filtered');
check_merge(count(tk_geo_schema_top_nodes(array(array('@type' => 'A'), array('@graph' => array(array('@type' => 'B'), array('@type' => 'C')))))) === 3, 'List documents were not flattened');
check_merge(tk_geo_schema_url_key('HTTPS://Site.Example/a/?x=1#y') === 'https://site.example/a', 'URL normalization is wrong');

// Crawler fallback: existing definitions are found regardless of formatting; only missing entities are injected.
$pretty = '<script type="application/ld+json">' . json_encode(array('@graph' => array(array('@type' => 'WebPage', '@id' => 'https://site.example/a#webpage', 'name' => 'A'), array('@type' => 'WebSite', '@id' => 'https://site.example/#website', 'name' => 'S'), array('@id' => 'https://site.example/#organization'))), JSON_PRETTY_PRINT) . '</script>';
$found = tk_geo_html_schema_ids($pretty);
check_merge(isset($found[tk_geo_schema_id_key('https://site.example/a/#webpage')]) && isset($found[tk_geo_schema_id_key('https://site.example/#website')]), 'Pretty-printed or escaped JSON-LD definitions were missed');
check_merge(!isset($found[tk_geo_schema_id_key('https://site.example/#organization')]), 'A reference was counted as a definition');

// Page-level Tool Kits content wins over site-wide settings, and nothing is printed twice.
$GLOBALS['tk_test']['seo'] = true;
$GLOBALS['tk_test']['uri'] = '/our-story/';
$GLOBALS['tk_test']['post'] = 7;
$GLOBALS['tk_test']['options']['geo_enabled'] = 1;
$GLOBALS['tk_test']['options']['geo_faq_enabled'] = 1;
$GLOBALS['tk_test']['options']['geo_faq_items'] = array(array('question' => 'Global question?', 'answer' => 'Global answer.'));
$GLOBALS['tk_test']['options']['geo_custom_jsonld'] = json_encode(array('@context' => 'https://schema.org', '@graph' => array(
    array('@type' => 'WebPage', '@id' => 'https://site.example/our-story/#webpage', 'url' => 'https://site.example/our-story/', 'name' => 'Custom name', 'description' => 'Custom description', 'inLanguage' => 'en-US'),
    array('@type' => 'FAQPage', '@id' => 'https://site.example/our-story/#faq', 'url' => 'https://site.example/our-story/', 'mainEntity' => array(array('@type' => 'Question', 'name' => 'Custom question?'))),
)));
$GLOBALS['tk_test']['meta'] = array('_tk_seo_title' => 'Page SEO title', '_tk_seo_meta_description' => 'Page SEO description', '_tk_geo_faq_items' => array(array('question' => 'Page question?', 'answer' => 'Page answer.')));
$docs = tk_geo_build_schema_documents();
$page = node_by_id($docs[0], 'https://site.example/our-story/#webpage');
check_merge($page['@type'] === 'WebPage' && $page['name'] === 'Page SEO title' && $page['description'] === 'Page SEO description' && $page['inLanguage'] === 'en-US', 'Custom JSON-LD overwrote the page SEO Content: ' . json_encode($page));
$faq_pages = array_values(array_filter($docs, function ($doc) { return ($doc['@type'] ?? '') === 'FAQPage'; }));
$custom_faq = array_filter($docs[0]['@graph'], function ($node) { return tk_geo_schema_has_type($node, 'FAQPage'); });
check_merge(count($faq_pages) === 1 && !$custom_faq && $faq_pages[0]['mainEntity'][0]['name'] === 'Page question?', 'FAQPage printed twice or the page FAQ did not replace the global FAQ');
check_merge(strpos(tk_geo_faq_shortcode(), 'itemscope') === false && strpos(tk_geo_faq_shortcode(), 'Page question?') !== false, 'FAQ shortcode repeats the JSON-LD FAQPage as microdata');

// An emptied page FAQ falls back to the global FAQ; without page SEO Content the custom values stay.
$GLOBALS['tk_test']['meta'] = array('_tk_geo_faq_items' => array());
$docs = tk_geo_build_schema_documents();
$faq_pages = array_values(array_filter($docs, function ($doc) { return ($doc['@type'] ?? '') === 'FAQPage'; }));
check_merge(count($faq_pages) === 1 && $faq_pages[0]['mainEntity'][0]['name'] === 'Global question?', 'An empty page FAQ hid the global FAQ');
check_merge(node_by_id($docs[0], 'https://site.example/our-story/#webpage')['description'] === 'Custom description', 'Custom page values were dropped without page SEO Content');

// No Tool Kits FAQ at all: the Custom JSON-LD FAQPage is kept; with GEO output off the shortcode keeps microdata.
$GLOBALS['tk_test']['options']['geo_faq_enabled'] = 0;
check_merge(count(array_filter(tk_geo_build_schema_documents()[0]['@graph'], function ($node) { return tk_geo_schema_has_type($node, 'FAQPage'); })) === 1, 'Custom FAQPage dropped although Tool Kits prints no FAQ');
$GLOBALS['tk_test']['options']['geo_faq_enabled'] = 1;
$GLOBALS['tk_test']['options']['geo_enabled'] = 0;
check_merge(strpos(tk_geo_faq_shortcode(), 'itemtype="https://schema.org/FAQPage"') !== false, 'Shortcode lost its microdata while JSON-LD output is off');
$GLOBALS['tk_test']['post'] = 0;

echo 'PASS: ' . $checks . " Custom JSON-LD merge, page-node scoping, admin preview, crawler fallback and page-over-global (SEO Content, FAQ GEO) checks\n";
