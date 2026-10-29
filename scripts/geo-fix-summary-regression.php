<?php
// php scripts/geo-fix-summary-regression.php — GEO Audit "What to fix" grouping and the copyable fix prompt.
define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/includes/seo-optimization.php';
require dirname(__DIR__) . '/includes/geo.php';
$checks = 0;
function check_fix($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
$items = array(
    array('url' => 'https://site.example/a/', 'issues' => array('Missing H1.', 'Missing semantic footer.', 'Missing Organization schema.')),
    array('url' => 'https://site.example/b/', 'issues' => array('Missing Organization schema.', 'Missing H1.')),
    array('url' => 'https://site.example/c/', 'issues' => array('HTTP status is not successful.', 'Missing Organization schema.')),
    array('url' => 'https://site.example/d/', 'issues' => array()),
);
$summary = tk_geo_audit_fix_summary($items);
check_fix(array_column($summary, 'issue') === array('HTTP status is not successful.', 'Missing Organization schema.', 'Missing H1.', 'Missing semantic footer.'), 'Issues are not ordered by priority, then affected URLs: ' . json_encode(array_column($summary, 'issue')));
check_fix($summary[1]['urls'] === array('https://site.example/a/', 'https://site.example/b/', 'https://site.example/c/') && $summary[1]['automatic'] && $summary[1]['penalty'] === 8, 'Grouped URLs or fix metadata are wrong');
check_fix(!$summary[0]['automatic'] && $summary[0]['priority'] === 'critical', 'Manual critical issue misclassified');
check_fix(tk_geo_audit_fix_summary(array(array('url' => 'https://site.example/', 'issues' => array()))) === array(), 'Clean report produced fix rows');
$prompt = tk_geo_audit_fix_prompt($summary, array('scanned_at' => 1793260800, 'checked_urls' => 4, 'average_score' => 71), 'https://site.example/');
check_fix(strpos($prompt, 'https://site.example/') !== false && strpos($prompt, '3 affected URL(s) of 4 checked, average GEO score 71/100') !== false, 'Prompt lacks site or audit context');
check_fix(strpos($prompt, "1. [CRITICAL] HTTP status is not successful.") !== false && strpos($prompt, "2. [HIGH] Missing Organization schema. (3 URLs, -8 points each)") !== false, 'Prompt order or counts are wrong');
check_fix(strpos($prompt, 'Fix: Add the missing Organization node') !== false && substr_count($prompt, 'Bulk Fix All') === 2, 'Prompt lacks solutions or the automatic-fix note');
check_fix(strpos($prompt, 'Do not add a second definition of an entity') !== false && strpos($prompt, 'list what you changed') !== false, 'Prompt lacks guardrails or the report-back step');
$many = array();
for ($i = 0; $i < 13; $i++) { $many[] = array('url' => 'https://site.example/p' . $i . '/', 'issues' => array('Missing H1.')); }
check_fix(strpos(tk_geo_audit_fix_prompt(tk_geo_audit_fix_summary($many), array(), 'https://site.example/'), '(+3 more)') !== false, 'Long URL lists are not truncated');
echo 'PASS: ' . $checks . " GEO Audit fix summary checks: priority order, grouping, auto/manual, prompt context, solutions, guardrails and truncation\n";
