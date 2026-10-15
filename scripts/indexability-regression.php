<?php
define('ABSPATH', '/tmp/');
define('MB_IN_BYTES', 1048576);
require dirname(__DIR__) . '/includes/seo-indexability.php';
set_error_handler(function($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
function verify_indexing($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class WP_Error {
    private $message;
    function __construct($code = 'http_request_failed', $message = 'Connection failed') { $this->message = $message; }
    function get_error_message() { return $this->message; }
}
class WP_Http {
    static function make_absolute_url($url, $base) {
        if (preg_match('~^https?://~', $url)) return $url;
        $parts = parse_url($base); $origin = $parts['scheme'] . '://' . $parts['host'];
        return $origin . (substr($url, 0, 1) === '/' ? $url : dirname($parts['path'] ?? '/') . '/' . $url);
    }
}
function home_url($path) { return 'https://site.example' . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function esc_url_raw($url, $schemes = array()) { return $url; }
function esc_url($url) { return htmlspecialchars($url, ENT_QUOTES); }
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES); }
function tk_get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function tk_update_option($key, $value) { $GLOBALS['options'][$key] = $value; }
function tk_seo_tools_enabled() { return $GLOBALS['tools']; }
function tk_seo_post_feature_enabled($feature, $id) { return $GLOBALS['content_enabled']; }
function tk_license_features_enabled() { return $GLOBALS['licensed']; }
function tk_is_admin_user() { return $GLOBALS['admin']; }
function current_user_can(...$args) { return $GLOBALS['editable']; }
function get_option($key) { return $GLOBALS['public']; }
function get_post($id) { return $GLOBALS['discovery_posts'][$id] ?? $GLOBALS['post']; }
function url_to_postid($url) { return $url === 'https://site.example/page/' ? 7 : 0; }
function get_permalink($id) { return $GLOBALS['discovery_urls'][$id] ?? 'https://site.example/page/'; }
function is_post_type_viewable($type) { return $type === 'page'; }
function get_post_types($args, $output) {
    verify_indexing($args === array('public' => true, 'publicly_queryable' => true), 'Discovery includes non-public post types');
    return $GLOBALS['discovery_types'];
}
class WP_Query {
    public $posts;
    function __construct($args) {
        verify_indexing($args['post_status'] === 'publish' && $args['has_password'] === false && !in_array('attachment', $args['post_type'], true), 'Discovery includes restricted content');
        verify_indexing($args['orderby'] === 'ID' && $args['order'] === 'ASC', 'Unstable discovery ordering');
        $posts = array_filter($GLOBALS['discovery_posts'], function($post) use ($args) {
            return $post->post_status === 'publish' && $post->post_password === '' && in_array($post->post_type, $args['post_type'], true);
        });
        $this->posts = array_slice(array_keys($posts), ($args['paged'] - 1) * $args['posts_per_page'], $args['posts_per_page']);
    }
}
function tk_seo_get_redirect_rules() { return tk_get_option('seo_redirect_rules', array()); }
function tk_seo_normalize_path($url) { return rtrim(parse_url($url, PHP_URL_PATH) ?: '/', '/') ?: '/'; }
function wp_safe_remote_get($url, $args) {
    verify_indexing($args['redirection'] === 0 && $args['limit_response_size'] === 2 * MB_IN_BYTES, 'Unsafe HTTP request options');
    $GLOBALS['requests'][] = $url;
    $GLOBALS['request_args'][] = array('url' => $url, 'timeout' => $args['timeout']);
    if ($url === 'https://site.example/robots.txt') return $GLOBALS['robots'];
    if ($args['timeout'] < ($GLOBALS['minimum_page_timeout'] ?? 0)) return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
    return $GLOBALS['response'];
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_header($response, $name) { return $response['headers'][$name] ?? ''; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function tk_post($key, $default = '') { return $_POST[$key] ?? $default; }
function check_ajax_referer($action, $field) { verify_indexing(($_POST[$field] ?? '') === 'valid', 'Invalid nonce'); }
class IndexingReply extends Exception {
    public $success; public $data;
    function __construct($success, $data) { $this->success = $success; $this->data = $data; }
}
function wp_send_json_error($data, $status = 400) { throw new IndexingReply(false, $data); }
function wp_send_json_success($data) { throw new IndexingReply(true, $data); }
function tk_page_cache_purge() { $GLOBALS['purged']++; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function is_feed() { return false; }
function is_preview() { return false; }
function is_search() { return false; }
function is_404() { return false; }
function ajax_indexing() { try { tk_seo_indexability_ajax(); } catch (IndexingReply $reply) { return $reply; } }

$url = 'https://site.example/page/';
$options = array(); $tools = true; $licensed = true; $admin = true; $editable = true; $content_enabled = true; $public = '1'; $requests = array(); $purged = 0;
$discovery_posts = array(); $discovery_urls = array(); $discovery_types = array('page' => 'page', 'attachment' => 'attachment');
$post = (object) array('post_status' => 'publish', 'post_password' => '', 'post_type' => 'page');
$robots = array('code' => 200, 'headers' => array(), 'body' => "User-agent: *\nDisallow: /private/\n");
$response = array('code' => 200, 'headers' => array('content-type' => 'text/html'), 'body' => '<html><head><meta content="index, follow" name="ROBOTS"><link href="/page/" rel="alternate canonical"></head><body>Public</body></html>');
$healthy = $response;
verify_indexing(tk_seo_indexability_url($url . '#part') === $url, 'Fragment not removed');
foreach (array('https://external.example/page', 'https://site.example.evil/page', 'http://site.example/page', 'https://user:pass@site.example/page', 'https://site.example:444/page') as $unsafe) {
    verify_indexing(tk_seo_indexability_url($unsafe) === '', 'External or credentialed URL accepted: ' . $unsafe);
}
$item = tk_seo_indexability_scan($url);
foreach ($item['checks'] as $check) verify_indexing($check['state'] === 'pass', 'Healthy page flagged: ' . $check['label']);
verify_indexing($requests[1] === 'https://site.example/robots.txt', 'Robots fetched below site subdirectory');

// A page needing more than five seconds must remain auditable. Robots requests
// have a shorter timeout and every scan/action has a shared wall-clock budget.
$minimum_page_timeout = 8.0;
$slow = tk_seo_indexability_scan($url);
verify_indexing($slow['checks']['noindex']['state'] === 'pass' && $slow['checks']['canonical']['state'] === 'pass', 'Slow page still fails the former five-second timeout');
verify_indexing($request_args[count($request_args) - 2]['timeout'] >= 8 && $request_args[count($request_args) - 2]['timeout'] <= 20, 'Page timeout is not bounded');
verify_indexing($request_args[count($request_args) - 1]['timeout'] <= 5, 'Robots request exhausted the page budget');
$minimum_page_timeout = 0;
$request_count = count($requests); $expired = tk_seo_indexability_scan($url, microtime(true) - 1);
verify_indexing(count($requests) === $request_count && $expired['checks']['noindex']['state'] === 'unknown' && $expired['checks']['robots']['state'] === 'unknown', 'Expired budget starts HTTP calls or falsely passes checks');
$response = new WP_Error('http_request_failed', 'cURL error 28: Operation timed out'); $before = $options;
try { tk_seo_indexability_apply($url, 'noindex'); throw new Exception('Unverified page was changed'); }
catch (RuntimeException $error) { verify_indexing(strpos($error->getMessage(), 'No change was applied') !== false && $options === $before, 'Timeout mistaken for a resolved issue or allowed a blind fix'); }
$response = $healthy;

foreach (array('noindex, follow', 'NOINDEX', 'none', 'googlebot: noindex') as $directive) verify_indexing(tk_seo_indexability_has_noindex($directive), 'Missed directive ' . $directive);
verify_indexing(tk_seo_indexability_has_noindex('max-snippet: -1, noindex'), 'Colon directive mistaken for a user-agent');
verify_indexing(!tk_seo_indexability_has_noindex('bingbot: noindex'), 'Other crawler header blocks Googlebot');
$response['body'] = '<html><head><meta name="Googlebot" content="none"><link href="" rel="CANONICAL"></head><body></body></html>';
$item = tk_seo_indexability_scan($url);
verify_indexing($item['checks']['noindex']['state'] === 'issue' && $item['checks']['canonical']['state'] === 'issue', 'Noindex/empty canonical not detected');
$response['body'] = '<html><head><!-- <meta name="robots" content="noindex"> --><script>var example="<link rel=canonical href=/fake>";</script></head></html>';
$item = tk_seo_indexability_scan($url);
verify_indexing($item['checks']['noindex']['state'] === 'pass' && $item['checks']['canonical']['state'] === 'issue', 'Comments/scripts became real directives');
$response = $healthy; $response['headers']['x-robots-tag'] = array('bingbot: noindex', 'googlebot: noindex');
verify_indexing(tk_seo_indexability_scan($url)['header_noindex'], 'Repeated X-Robots-Tag missed');
try { tk_seo_indexability_apply($url, 'noindex'); throw new Exception('Header override accepted'); } catch (RuntimeException $error) { verify_indexing(strpos($error->getMessage(), 'X-Robots-Tag') !== false, 'Wrong header guidance'); }
$response = $healthy; $response['headers']['link'] = '</preferred/>; type="text/html"; rel="canonical"';
$item = tk_seo_indexability_scan($url);
verify_indexing($item['header_canonical'] && $item['checks']['canonical']['state'] === 'issue', 'Conflicting HTTP canonical not detected');

$policies = array(
    array("User-agent: *\nDisallow: /\nAllow: /page/$", '/page/', true),
    array("User-agent: *\nDisallow: /*?secret=", '/page/?secret=1', false),
    array("User-agent: *\nDisallow: /page/$", '/page/sub', true),
    array("User-agent: *\nDisallow: /page/\nAllow: /page/", '/page/', true),
    array("User-agent: *\nDisallow: /\nUser-agent: Googlebot\nDisallow:", '/page/', true),
    array("User-agent: Googlebot\nDisallow: /page/\nUser-agent: Googlebot\nAllow: /page/", '/page/', true),
    array("User-agent: *\nDisallow: /caf%C3%A9/", '/café/', false),
    array("User-agent: *\nDisallow: /~user/", '/%7euser/', false),
);
foreach ($policies as $policy) verify_indexing(tk_seo_indexability_robots_check($policy[0], $policy[1])['allowed'] === $policy[2], 'Wrong robots precedence: ' . $policy[0]);
$policy = "User-agent: *\nDisallow: /\n\nUser-agent: Googlebot\nDisallow: /page/\nDisallow: /private/\n\nUser-agent: Bingbot\nDisallow: /other/\n";
$allowed = tk_seo_indexability_robots_allow($policy, array('/page/'));
verify_indexing(tk_seo_indexability_robots_check($allowed, '/page/')['allowed'], 'Exact allow did not work');
verify_indexing(!tk_seo_indexability_robots_check($allowed, '/page/child')['allowed'] && !tk_seo_indexability_robots_check($allowed, '/private/')['allowed'], 'Allow broadened crawl access');
verify_indexing(strpos($allowed, "User-agent: Bingbot\nDisallow: /other/") !== false, 'Other crawler policy changed');
$allowed = tk_seo_indexability_robots_allow("User-agent: *\nDisallow: /\n", array('/page/'));
verify_indexing(strpos($allowed, 'User-agent: googlebot') === false && !tk_seo_indexability_robots_check($allowed, '/private/')['allowed'], 'New specific group bypassed wildcard restrictions');

$response = $healthy; $response['code'] = 302; $response['headers']['location'] = '/destination/';
$item = tk_seo_indexability_scan($url);
verify_indexing($item['checks']['redirect']['state'] === 'issue' && $item['redirect_to'] === 'https://site.example/destination/', 'Redirect was followed or destination lost');
verify_indexing($item['checks']['canonical']['state'] === 'unknown', 'Redirect body treated as final page');
try { tk_seo_indexability_apply($url, 'redirect'); throw new Exception('Unknown redirect altered'); } catch (RuntimeException $error) { verify_indexing(strpos($error->getMessage(), 'server/CDN') !== false, 'Missing redirect source guidance'); }
$options['seo_redirect_rules'] = array(array('id' => 'x', 'from' => '/page', 'to' => '/destination', 'status' => 301, 'enabled' => 1));
tk_seo_indexability_apply($url, 'redirect');
verify_indexing($options['seo_redirect_rules'][0]['enabled'] === 0, 'Own redirect not disabled');
foreach (array(new WP_Error(), array('code' => 503, 'headers' => array(), 'body' => ''), array('code' => 200, 'headers' => array('content-type' => 'application/json'), 'body' => '{}')) as $failure) {
    $response = $failure; $item = tk_seo_indexability_scan($url);
    verify_indexing($item['checks']['noindex']['state'] === 'unknown' && $item['checks']['canonical']['state'] === 'unknown', 'Request failure/non-HTML falsely passed');
}
$response = $healthy; $robots = array('code' => 403, 'headers' => array(), 'body' => 'Forbidden');
verify_indexing(tk_seo_indexability_scan($url)['checks']['robots']['state'] === 'unknown', 'Inaccessible robots passed');
$robots['code'] = 404;
verify_indexing(tk_seo_indexability_scan($url)['checks']['robots']['state'] === 'pass', 'Missing robots treated as blocked');
$robots = array('code' => 200, 'headers' => array(), 'body' => "User-agent: *\nDisallow: /\n");
tk_seo_indexability_apply($url, 'robots');
verify_indexing(tk_seo_indexability_robots_check(tk_seo_indexability_robots_filter($robots['body'], true), '/page/')['allowed'], 'Saved virtual robots override unused');
$post->post_status = 'private';
verify_indexing(tk_seo_indexability_robots_filter($robots['body'], true) === $robots['body'], 'Private content kept crawling override');
$post->post_status = 'publish';

$html = '<html><head><!-- <meta name="robots" content="noindex"> --><script>var sample="<link rel=canonical href=/fake>";</script><meta name="robots" content="noindex, nofollow, max-snippet:20"><meta content="none" name="googlebot"><meta name="bingbot" content="noindex"><link rel="canonical" href="/a"><link href="/b" rel="CANONICAL"><meta name="empty" content=""></head><body><link rel="canonical" href="/body">Untouched</body></html>';
$fix = array('noindex' => true, 'canonical' => $url);
$fixed = tk_seo_indexability_fix_html($html, $fix);
$parsed = tk_seo_indexability_parse($fixed);
verify_indexing(!$parsed['meta_noindex'] && $parsed['canonicals'] === array($url), 'Rendered overrides failed');
verify_indexing(strpos($fixed, 'nofollow, max-snippet:20') !== false && strpos($fixed, '<meta name="bingbot" content="noindex">') !== false, 'Other directives changed');
verify_indexing(strpos($fixed, '<!-- <meta name="robots" content="noindex"> -->') !== false && strpos($fixed, '<link rel=canonical href=/fake>') !== false && strpos($fixed, '<body><link rel="canonical" href="/body">Untouched</body>') !== false, 'Comments, scripts or body rewritten');
verify_indexing(tk_seo_indexability_fix_html($fixed, $fix) === $fixed, 'Repeated HTML override not idempotent');
verify_indexing(tk_seo_indexability_fix_html('No HTML head', $fix) === 'No HTML head', 'Non-HTML rewritten');

$response = $healthy; $robots['body'] = "User-agent: *\nDisallow:\n";
$response['body'] = '<html><head><meta name="robots" content="noindex"></head></html>';
$public = '0';
try { tk_seo_indexability_apply($url, 'noindex'); throw new Exception('Site-wide privacy bypassed'); } catch (RuntimeException $error) { verify_indexing(strpos($error->getMessage(), 'site-wide') !== false, 'Missing site-wide visibility guidance'); }
$public = '1'; tk_seo_indexability_apply($url, 'noindex');
verify_indexing($options['seo_indexability_fixes'][hash('sha256', $url)]['noindex'], 'Noindex fix not persisted');
foreach (array('draft', 'private', 'password', 'unauthorized') as $guard) {
    $post->post_status = in_array($guard, array('draft', 'private')) ? $guard : 'publish';
    $post->post_password = $guard === 'password' ? 'secret' : '';
    $editable = $guard !== 'unauthorized';
    verify_indexing(!tk_seo_indexability_editable($url), 'Public/edit guard failed: ' . $guard);
}
$post->post_status = 'publish'; $post->post_password = ''; $editable = true;
$content_enabled = false;
verify_indexing(!tk_seo_indexability_editable($url), 'Per-content SEO disable ignored');
$content_enabled = true;
verify_indexing(!tk_seo_indexability_editable($url . '?variant=1'), 'Alternate URL accepted for page override');
$response = $healthy; $response['body'] = '<html><head></head></html>';
$request_count = count($requests);
tk_seo_indexability_apply($url, 'canonical', $url);
verify_indexing(count($requests) - $request_count === 2, 'Self-canonical fix fetched the same page twice');
verify_indexing($options['seo_indexability_fixes'][hash('sha256', $url)]['canonical'] === $url, 'Preferred canonical not persisted');
tk_seo_indexability_apply($url, 'remove');
verify_indexing(!isset($options['seo_indexability_fixes'][hash('sha256', $url)]), 'Undo did not remove page overrides');

$options['seo_indexability_fixes'][hash('sha256', $url)] = array('url' => $url, 'canonical' => $url, 'noindex' => true);
$_SERVER['REQUEST_URI'] = '/page/'; $_SERVER['REQUEST_METHOD'] = 'GET';
ob_start(); tk_seo_indexability_start(); echo $html; ob_end_flush(); $frontend = ob_get_clean();
verify_indexing(!tk_seo_indexability_parse($frontend)['meta_noindex'], 'Frontend did not apply the saved override');
$public = '0';
ob_start(); tk_seo_indexability_start(); echo $html; ob_end_flush(); $frontend = ob_get_clean();
verify_indexing(tk_seo_indexability_parse($frontend)['meta_noindex'], 'Frontend bypassed site-wide visibility');
$public = '1'; $post->post_password = 'secret'; $level = ob_get_level(); tk_seo_indexability_start();
verify_indexing(ob_get_level() === $level, 'Password-protected frontend started an override buffer');
$post->post_password = ''; unset($options['seo_indexability_fixes'][hash('sha256', $url)]);

$_POST = array('nonce' => 'valid', 'url' => $url, 'operation' => 'scan');
$reply = ajax_indexing();
verify_indexing($reply->success && isset($reply->data['item']) && $purged === 0, 'Scan response/cache contract failed');
$_POST = array('nonce' => 'valid', 'url' => $url, 'operation' => 'fix', 'kind' => 'canonical', 'canonical' => $url);
$reply = ajax_indexing();
verify_indexing($reply->success && isset($reply->data['message']) && !isset($reply->data['item']) && $purged === 1, 'Fix must complete before separate recheck');
// Automatic discovery includes every public page across batches and known redirect sources.
foreach (range(100, 204) as $id) {
    $discovery_posts[$id] = (object) array('post_status' => 'publish', 'post_password' => '', 'post_type' => 'page');
    $discovery_urls[$id] = 'https://site.example/content-' . $id . '/';
}
foreach (array('draft', 'private', 'password', 'attachment') as $i => $kind) {
    $id = 300 + $i; $discovery_posts[$id] = clone $discovery_posts[100];
    $discovery_urls[$id] = 'https://site.example/restricted-' . $id . '/';
    if (in_array($kind, array('draft', 'private'))) $discovery_posts[$id]->post_status = $kind;
    if ($kind === 'password') $discovery_posts[$id]->post_password = 'secret';
    if ($kind === 'attachment') $discovery_posts[$id]->post_type = 'attachment';
}
$options['seo_redirect_rules'] = array(
    array('from' => '/legacy-page', 'enabled' => 1, 'status' => 301),
    array('from' => '/disabled-rule', 'enabled' => 0, 'status' => 302),
);
$first = tk_seo_indexability_discover(1); $second = tk_seo_indexability_discover(2);
verify_indexing(count($first['urls']) === 102 && $first['has_more'] && $first['next_page'] === 2, 'First discovery batch omits homepage, redirect or content');
verify_indexing(in_array(home_url('/'), $first['urls'], true) && in_array('https://site.example/legacy-page', $first['urls'], true), 'Current-site discovery lost homepage/redirect sources');
verify_indexing(count($second['urls']) === 5 && !$second['has_more'], 'Discovery truncated the website at 100 public pages');
verify_indexing(strpos(implode(' ', array_merge($first['urls'], $second['urls'])), 'restricted-') === false, 'Discovery exported restricted content');
$_POST = array('nonce' => 'valid', 'operation' => 'discover', 'page' => 1);
$request_count = count($requests); $purge_count = $purged; $reply = ajax_indexing();
verify_indexing($reply->success && count($reply->data['urls']) === 102 && count($requests) === $request_count && $purged === $purge_count, 'Discovery requires URL input, HTTP probes or cache purging');
verify_indexing($options['seo_indexability_report'] === array(), 'New website scan retained stale results');
$discovery_types = array();
verify_indexing(tk_seo_indexability_discover(2)['urls'] === array() && !tk_seo_indexability_discover(2)['has_more'], 'Empty website discovery did not terminate');
$admin = false;
verify_indexing(!ajax_indexing()->success, 'Unauthorized AJAX accepted');
$admin = true; $licensed = false;
verify_indexing(!ajax_indexing()->success, 'Unlicensed AJAX accepted');
echo "PASS: automatic paged website discovery, public content and redirect sources, four live checks, scoped fixes, frontend guards, AJAX contracts and cache purging\n";
