<?php
// php scripts/build-schema-vocabulary.php [schemaorg-current-https.jsonld]
// Compacts the schema.org vocabulary into assets/schema/schemaorg-vocabulary.json for the JSON-LD node validator.
// Without an argument the current release is downloaded from schema.org.
$source = 'https://schema.org/version/latest/schemaorg-current-https.jsonld';
$raw = file_get_contents($argv[1] ?? $source);
$data = $raw === false ? null : json_decode($raw, true);
if (!is_array($data) || !isset($data['@graph']) || !is_array($data['@graph'])) { fwrite(STDERR, "Could not read the schema.org vocabulary.\n"); exit(1); }
function vocab_names($value): array {
    $names = array();
    foreach (isset($value['@id']) ? array($value) : (array) $value as $item) {
        if (is_array($item) && isset($item['@id']) && strpos($item['@id'], 'schema:') === 0) $names[] = substr($item['@id'], 7);
    }
    sort($names);
    return $names;
}
$types = $properties = $superseded_types = array();
foreach ($data['@graph'] as $term) {
    if (!isset($term['@id'], $term['@type']) || strpos($term['@id'], 'schema:') !== 0) continue;
    $name = substr($term['@id'], 7);
    $kinds = (array) $term['@type'];
    if (in_array('rdfs:Class', $kinds, true)) {
        $types[$name] = vocab_names($term['rdfs:subClassOf'] ?? array());
        if ($replacement = vocab_names($term['schema:supersededBy'] ?? array())) $superseded_types[$name] = $replacement[0];
    } elseif (in_array('rdf:Property', $kinds, true)) {
        $property = array(vocab_names($term['schema:domainIncludes'] ?? array()), vocab_names($term['schema:rangeIncludes'] ?? array()));
        if ($replacement = vocab_names($term['schema:supersededBy'] ?? array())) $property[] = $replacement[0];
        $properties[$name] = $property;
    }
}
ksort($types); ksort($properties); ksort($superseded_types);
if (count($types) < 500 || count($properties) < 1000 || !isset($types['Thing'], $properties['name'])) { fwrite(STDERR, "Unexpected vocabulary shape.\n"); exit(1); }
$out = array('source' => $source, 'generated' => gmdate('Y-m-d'), 'types' => $types, 'supersededTypes' => $superseded_types, 'properties' => $properties);
$target = dirname(__DIR__) . '/assets/schema/schemaorg-vocabulary.json';
file_put_contents($target, json_encode($out, JSON_UNESCAPED_SLASHES) . "\n");
printf("Wrote %s: %d types, %d properties, %d bytes\n", $target, count($types), count($properties), filesize($target));
