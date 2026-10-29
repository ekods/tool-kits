// node scripts/schema-node-check-regression.cjs — validator behind GEO Setup → Custom JSON-LD (code snippet and live URL).
'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
// Load the browser asset directly; a parent package.json may mark .js files as ES modules.
const vm = require('vm');
const sandbox = {URL};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/schema-editor-tools.js'), 'utf8'), sandbox);
const tools = sandbox.ToolKitsSchemaEditor;
const vocab = JSON.parse(fs.readFileSync(path.join(__dirname, '../assets/schema/schemaorg-vocabulary.json'), 'utf8'));
let checks = 0;
function ok(condition, message) { assert.ok(condition, message); checks++; }
const text = (result, severity) => result.findings.filter((f) => !severity || f.severity === severity).map((f) => f.message).join(' | ');
const find = (result, pattern) => result.findings.find((f) => pattern.test(f.message));

// Vocabulary snapshot shape.
ok(vocab.types.ListItem.includes('Intangible') && vocab.properties.itemListElement[0].includes('ItemList') && vocab.properties.name[1].includes('Text'), 'Vocabulary snapshot is missing core terms');

// A complete graph is clean, with and without the vocabulary, and groups one item per @graph entry.
const graph = {'@context': 'https://schema.org', '@graph': [
    {'@type': 'Organization', '@id': 'https://site.example/#org', name: 'Site', url: 'https://site.example/', logo: 'https://site.example/logo.png'},
    {'@type': 'WebSite', '@id': 'https://site.example/#website', name: 'Site', url: 'https://site.example/', publisher: {'@id': 'https://site.example/#org'}},
]};
const clean = tools.check(graph, vocab);
ok(clean.errors === 0 && clean.warnings === 0 && clean.items === 2, 'Complete graph produced findings: ' + text(clean));
ok(clean.groups.map((g) => g.type).join() === 'Organization,WebSite' && tools.check(graph).findings.length === 0, 'Grouping or vocabulary-free run is wrong');

// validator.schema.org-style grouping: WebPage, ItemList, Service, FAQPage with their own counts.
const page = [
    {'@context': 'https://schema.org', '@type': 'WebPage', name: 'Home', url: 'https://site.example/'},
    {'@context': 'https://schema.org', '@type': 'ItemList', itemListElement: [
        {'@type': 'ListItem', position: 1, name: 'A', url: 'https://site.example/a', headline: 'H'},
        {'@type': 'ListItem', position: 2, item: {'@type': 'PostalAddress', streetAddress: 'x'}},
    ]},
    {'@context': 'https://schema.org', '@type': 'Service', name: 'Design', provider: {'@type': 'Organization', name: 'Site', url: 'https://site.example/', logo: 'https://site.example/l.png'}},
    {'@context': 'https://schema.org', '@type': 'FAQPage', mainEntity: [{'@type': 'Question', name: 'Q', acceptedAnswer: {'@type': 'Answer', text: 'A'}}]},
];
const detected = tools.check(page, vocab);
ok(detected.groups.map((g) => g.type + ':' + g.items.length).join() === 'WebPage:1,ItemList:1,Service:1,FAQPage:1' && detected.items === 4, 'Top-level groups do not match the validator layout');
const itemList = detected.groups[1];
ok(itemList.errors === 0 && itemList.warnings === 1 && /property headline is not recognised by the schema \(e\.g\. schema\.org\) for an object of type ListItem/.test(text(detected, 'warning')), 'Property outside its domain was not a warning');
// Tool Kits' former ItemList output: inLanguage on ItemList and each ListItem, as in validator.schema.org (1 + 7 = 8 warnings).
const legacy = tools.check({'@context': 'https://schema.org', '@type': 'ItemList', name: 'L', inLanguage: 'en-US', itemListElement: Array.from({length: 7}, (_, i) => ({'@type': 'ListItem', position: i + 1, url: 'https://a.example/' + i, name: 'N', inLanguage: 'en-US'}))}, vocab);
ok(legacy.errors === 0 && legacy.warnings === 8 && legacy.items === 1, 'Legacy ItemList did not match the validator counts: ' + text(legacy));
const search = tools.check({'@context': 'https://schema.org', '@type': 'WebSite', name: 'S', url: 'https://a.example/', potentialAction: {'@type': 'SearchAction', target: 'https://a.example/?s={q}', 'query-input': 'required name=q'}}, vocab);
ok(search.findings.length === 0, 'SearchAction query-input flagged: ' + text(search));
ok(detected.groups.filter((g) => g.type !== 'ItemList').every((g) => g.errors === 0 && g.warnings === 0), 'Clean groups picked up findings from ItemList: ' + text(detected));
const inherited = tools.check({'@context': 'https://schema.org', '@type': 'NewsArticle', headline: 'H', image: 'https://a.example/i.png', datePublished: '2026-01-01', author: {'@type': 'Person', name: 'P'}, wordCount: 10}, vocab);
ok(inherited.errors === 0 && inherited.warnings === 0, 'Inherited properties (Article/CreativeWork) were rejected: ' + text(inherited));

// Unknown types, unknown properties, superseded terms and unexpected target types.
const unknown = tools.check({'@context': 'https://schema.org', '@type': 'Organisation', name: 'x'}, vocab);
ok(/"Organisation" is not a known schema.org type/.test(text(unknown, 'error')), 'Misspelled type accepted');
ok(find(tools.check({'@context': 'https://schema.org', '@type': 'Thing', nmae: 'x'}, vocab), /property nmae is not recognised by the schema \(e\.g\. schema\.org\)\.$/).severity === 'warning', 'Unknown property not reported');
ok(/is superseded by/.test(text(tools.check({'@context': 'https://schema.org', '@type': 'Code', name: 'x'}, vocab), 'warning')), 'Superseded type not reported');
const target = find(tools.check({'@context': 'https://schema.org', '@type': 'Article', headline: 'H', image: 'https://a.example/i.png', datePublished: 'x', author: {'@type': 'Place', name: 'Somewhere'}}, vocab), /not a known valid target type/);
ok(target && target.severity === 'warning' && target.property === 'author', 'Unexpected target type not a warning on its property');
const prefixed = tools.check({'@context': 'https://schema.org', '@type': ['Organization', 'https://schema.org/LocalBusiness'], 'name': 'x', 'url': 'https://a.example/', 'logo': 'https://a.example/l.png', 'address': 'x', 'ex:custom': 1, '@reverse': {}}, vocab);
ok(prefixed.errors === 0 && prefixed.groups[0].type === 'Organization, LocalBusiness', 'Full schema.org IRIs or extension properties misreported: ' + text(prefixed));

// Structural checks still apply.
const bad = tools.check({'@context': 'https://schema.org', '@graph': [{'@type': 4}, {'@type': 'ImageObject', '@id': 7, url: '/logo.png'}]}, vocab);
ok(/@type must/.test(text(bad, 'error')) && /@id must/.test(text(bad, 'error')) && /absolute http/.test(text(bad, 'warning')), 'Bad @type, @id or relative URL accepted');
// Tool Kits tips are notices so error/warning counts match validator.schema.org.
const tips = tools.check({'@context': 'https://schema.org', '@type': 'Organization', name: 'x', parentOrganization: {'@id': '#parent'}}, vocab);
ok(tips.errors === 0 && tips.warnings === 0 && tips.notices === 2 && tips.groups[0].notices === 2, 'Recommendations or dangling references counted as warnings: ' + text(tips));
const yoast = tools.check({'@context': 'https://schema.org', '@type': 'SearchAction', target: {'@type': 'EntryPoint', urlTemplate: 'https://a.example/?s={q}'}, 'query-input': {'@type': 'PropertyValueSpecification', valueRequired: true, valueName: 'q'}}, vocab);
ok(yoast.findings.length === 0, 'query-input object flagged: ' + text(yoast));
ok(/no @context/.test(text(tools.check({'@type': 'Organization', name: 'Site'}, vocab), 'warning')), 'Missing context not reported');
const repeated = tools.check([{'@context': 'https://schema.org', '@type': 'WebSite', '@id': '#site', name: 'A', url: 'https://a.example/'}, {'@context': 'https://schema.org', '@type': 'WebSite', '@id': '#site', name: 'A', url: 'https://a.example/'}], vocab);
ok(repeated.groups[0].items.every((item) => item.findings.some((f) => /Repeated @id/.test(f.message))), 'Repeated @id not flagged on both items');
const dangling = find(tools.check({'@context': 'https://schema.org', '@type': 'WebPage', name: 'P', url: 'https://a.example/', isPartOf: {'@id': '#website'}}, vocab), /Reference #website/);
ok(dangling && dangling.property === 'isPartOf', 'Dangling reference not attached to its property');
ok(/@graph must be an array/.test(text(tools.check({'@context': 'https://schema.org', '@graph': {}}, vocab))), 'Non-array @graph accepted');
ok(/No schema nodes/.test(text(tools.check({'@context': 'https://schema.org', name: 'x'}, vocab))), 'Object without @type accepted');
ok(tools.check({'@context': 'https://schema.org', '@type': 'ImageObject', contentUrl: 'https://a.example/i.png'}, vocab).warnings === 0, 'contentUrl should satisfy ImageObject');

// Wrapper-level findings stay at document level; Remove Node uses item paths.
const wrapped = tools.check({'@graph': [{'@type': 'Thing', name: 'x'}]}, vocab);
ok(wrapped.document.length === 1 && /no @context/.test(wrapped.document[0].message) && wrapped.groups[0].warnings === 0, 'Wrapper finding attributed to an item');
const removed = tools.remove(graph, detected.groups.length ? ['@graph', 0] : []);
ok(removed['@graph'].length === 1 && removed['@graph'][0]['@type'] === 'WebSite', 'Remove Node path broke');

// Identity analysis: nested shared values are not top-level duplicates, and a context-bearing reference is not a definition.
const sharedAddress = tools.analyze([{'@type': 'Person', '@id': '#a', address: {'@type': 'PostalAddress', streetAddress: 'Same'}}, {'@type': 'Person', '@id': '#b', address: {'@type': 'PostalAddress', streetAddress: 'Same'}}]);
ok(Object.keys(sharedAddress.anonymous).length === 0, 'Nested shared data flagged as anonymous top-level duplicates');
ok(tools.analyze([{'@type': 'Organization', '@id': '#org'}, {'@id': '#org', '@context': 'https://schema.org'}]).ids['#org'] === 1, 'Context-bearing reference counted as a definition');

console.log('PASS: ' + checks + ' schema validator checks (vocabulary terms, domains, target types, grouping, structure, references)');
