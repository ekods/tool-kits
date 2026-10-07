<?php
define('ABSPATH', '/tmp/');
require dirname(__DIR__) . '/includes/geo-schema-modal.php';
function verify_schema($ok, $message) { if (!$ok) throw new RuntimeException($message); }
verify_schema(tk_geo_schema_validate_json('')['valid'], 'Empty setting cannot be removed');
foreach (array('{bad', 'null', '42', '[]', '{"@type":4}', '{"@type":"Organization","@id":4}') as $bad) {
    verify_schema(!tk_geo_schema_validate_json($bad)['valid'], 'Invalid structure accepted: ' . $bad);
}
$different = tk_geo_schema_validate_json('{"@context":"https://schema.org","@graph":[{"@type":"ImageObject","@id":"#a"},{"@type":"ImageObject","@id":"#b"}]}');
verify_schema($different['valid'] && strpos(implode(' ', $different['messages']), 'Repeated entity definition') === false, 'Different images treated as duplicate entities');
$reference = tk_geo_schema_validate_json('{"@type":"Organization","@id":"#org","publisher":{"@id":"#org"}}');
verify_schema($reference['valid'] && strpos(implode(' ', $reference['messages']), 'Repeated entity definition') === false, 'Identity reference treated as definition');
$repeated = tk_geo_schema_validate_json('[{"@type":"WebSite","@id":"#site"},{"@type":"WebSite","@id":"#site"}]');
verify_schema(strpos(implode(' ', $repeated['messages']), 'Repeated entity definition: #site') !== false, 'Repeated identity not found');
verify_schema($repeated['duplicate_types']['WebSite'] === 2 && $repeated['duplicate_ids']['#site'] === 2, 'Structured duplicate markers missing');
echo "PASS: malformed JSON, typed nodes, identity comparisons, references and removable settings\n";
