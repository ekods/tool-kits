(function (root) {
    'use strict';
    function canonical(node) {
        function sort(value) {
            if (!value || typeof value !== 'object') return value;
            if (Array.isArray(value)) return value.map(sort);
            var copy = Object.create(null);
            Object.keys(value).sort().forEach(function (key) { copy[key] = sort(value[key]); });
            return copy;
        }
        var copy = Object.assign(Object.create(null), node); delete copy['@context'];
        return JSON.stringify(sort(copy));
    }
    function analyze(value) {
        var nodes = [], types = Object.create(null), ids = Object.create(null), references = [], anonymous = Object.create(null);
        function walk(node, path, topLevel) {
            if (!node || typeof node !== 'object') return;
            if (!Array.isArray(node)) {
                var nodeTypes = Array.from(new Set([].concat(node['@type'] || []).filter(function (type) { return typeof type === 'string' && type.trim(); })));
                nodeTypes.forEach(function (type) { types[type] = (types[type] || 0) + 1; });
                if (typeof node['@id'] === 'string') {
                    if (!Object.keys(node).some(function (key) { return key !== '@id' && key !== '@context'; })) references.push(node['@id']);
                    else ids[node['@id']] = (ids[node['@id']] || 0) + 1;
                }
                var signature = topLevel && nodeTypes.length && node['@id'] === undefined ? canonical(node) : '';
                if (signature) anonymous[signature] = (anonymous[signature] || 0) + 1;
                if (nodeTypes.length) nodes.push({path: path, node: node, types: nodeTypes, id: node['@id'] || '', signature: signature});
            }
            Object.keys(node).forEach(function (key) { if (node[key] && typeof node[key] === 'object') walk(node[key], path.concat(Array.isArray(node) ? Number(key) : key), key === '@graph' || (topLevel && Array.isArray(node))); });
        }
        walk(value, [], true);
        return {nodes: nodes, types: types, ids: ids, references: references, anonymous: anonymous};
    }
    function remove(value, path) {
        if (!path.length) return null;
        var copy = JSON.parse(JSON.stringify(value));
        var parent = copy;
        path.slice(0, -1).forEach(function (key) { parent = parent[key]; });
        var key = path[path.length - 1];
        if (Array.isArray(parent)) parent.splice(key, 1); else delete parent[key];
        if (!analyze(copy).nodes.length) return null;
        return copy;
    }
    function add(value, node) {
        if (!value) return {'@context': 'https://schema.org', '@graph': [node]};
        var copy = JSON.parse(JSON.stringify(value));
        if (Array.isArray(copy)) { copy.push(node); return copy; }
        if (Array.isArray(copy['@graph'])) { copy['@graph'].push(node); return copy; }
        var context = copy['@context'] || 'https://schema.org';
        delete copy['@context'];
        return {'@context': context, '@graph': [copy, node]};
    }
    function fill(value, path, defaults) {
        var copy = JSON.parse(JSON.stringify(value)), node = copy;
        path.forEach(function (key) { node = node[key]; });
        Object.keys(defaults).forEach(function (key) { if (node[key] === undefined || node[key] === '') node[key] = defaults[key]; });
        return copy;
    }
    // Recommended properties per type; a nested array means "any one of".
    var article = ['headline', 'image', 'datePublished', 'author'];
    var recommended = {
        WebSite: ['name', 'url'], Organization: ['name', 'url', 'logo'], LocalBusiness: ['name', 'address'], Person: ['name'],
        WebPage: ['name', 'url'], Article: article, BlogPosting: article, NewsArticle: article, ImageObject: [['contentUrl', 'url']],
        FAQPage: ['mainEntity'], Question: ['name', 'acceptedAnswer'], Answer: ['text'], BreadcrumbList: ['itemListElement'],
        ItemList: ['itemListElement'], ListItem: ['position'], Product: ['name'], Service: ['name'], Event: ['name', 'startDate', 'location']
    };
    var urlFields = ['url', 'contentUrl', 'logo', 'image', 'sameAs', 'item'];
    function absent(node, field) {
        var value = node[field];
        return value === undefined || value === null || value === '' || Array.isArray(value) && !value.length;
    }
    function httpUrl(value) {
        try { var parsed = new URL(value); return (parsed.protocol === 'http:' || parsed.protocol === 'https:') && !parsed.username && !parsed.password; }
        catch (error) { return false; }
    }
    function isObject(value) { return value !== null && typeof value === 'object' && !Array.isArray(value); }
    function isReference(value) { return isObject(value) && typeof value['@id'] === 'string' && Object.keys(value).every(function (key) { return key === '@id' || key === '@context'; }); }
    // schema.org term name, or null for terms from another vocabulary (prefixed or absolute non-schema.org IRIs).
    function schemaName(term) {
        var name = String(term).replace(/^https?:\/\/schema\.org\//, '').replace(/^schema:/, '');
        return /[:\/]/.test(name) ? null : name;
    }
    // Ancestors of a schema.org type including itself; empty for unknown types.
    function ancestors(vocab, type) {
        vocab.ancestorCache = vocab.ancestorCache || Object.create(null);
        if (vocab.ancestorCache[type]) return vocab.ancestorCache[type];
        var seen = [], queue = [type];
        while (queue.length) {
            var current = queue.shift();
            if (seen.indexOf(current) !== -1 || !Object.prototype.hasOwnProperty.call(vocab.types, current)) continue;
            seen.push(current); queue = queue.concat(vocab.types[current]);
        }
        return (vocab.ancestorCache[type] = seen);
    }
    // Top-level items as validator.schema.org counts them: each document, or each @graph entry.
    function topLevel(value) {
        var items = [];
        function visit(node, path) {
            if (!isObject(node)) return;
            if (Array.isArray(node['@graph'])) {
                node['@graph'].forEach(function (child, index) { if (isObject(child)) items.push({path: path.concat('@graph', index), node: child}); });
                if (node['@type'] !== undefined) items.push({path: path, node: node});
            } else items.push({path: path, node: node});
        }
        if (Array.isArray(value)) value.forEach(function (node, index) { visit(node, [index]); }); else visit(value, []);
        return items;
    }
    function startsWith(path, prefix) { return prefix.length <= path.length && prefix.every(function (key, index) { return path[index] === key; }); }
    // Findings for a draft, grouped like validator.schema.org. Errors break the markup or name unknown types; warnings cover
    // properties outside a type, unexpected targets, superseded terms and relative URLs. Notices are Tool Kits tips that
    // validator.schema.org does not report (recommended properties, repeated @id, dangling references), so counts stay comparable. Pass the compact vocabulary (assets/schema/schemaorg-vocabulary.json) to check terms.
    function check(value, vocab) {
        var info = analyze(value), findings = [];
        function report(path, property, severity, message) { findings.push({path: path, property: property || '', severity: severity, message: message}); }
        var roots = Array.isArray(value) ? value : [value];
        if (!value || typeof value !== 'object' || !roots.length) report([], '', 'error', 'Enter a JSON-LD object or array.');
        else roots.forEach(function (root, index) {
            var path = Array.isArray(value) ? [index] : [], label = Array.isArray(value) ? 'Document ' + (index + 1) : 'Document';
            if (!isObject(root)) { report(path, '', 'error', label + ' must be an object.'); return; }
            var context = root['@context'];
            if (context === undefined) report(path, '@context', 'warning', label + ' has no @context. Add "https://schema.org".');
            else if (!/^https?:\/\/schema\.org\/?$/.test(typeof context === 'string' ? context : '')) report(path, '@context', 'warning', label + ' uses a non-standard @context. Use "https://schema.org".');
            if (root['@type'] === undefined && !Array.isArray(root['@graph'])) report(path, '', 'error', label + ' has no @type or @graph.');
            if (root['@graph'] !== undefined && !Array.isArray(root['@graph'])) report(path, '@graph', 'error', label + ' @graph must be an array.');
        });
        var nodeCount = 0;
        function checkNode(node, path) {
            var types = [], known = [];
            nodeCount++;
            [].concat(node['@type'] === undefined ? [] : node['@type']).forEach(function (type) {
                if (typeof type !== 'string' || !type.trim()) { report(path, '@type', 'error', '@type must be a non-empty string or an array of strings.'); return; }
                if (types.indexOf(type) !== -1) return;
                types.push(type);
                var name = schemaName(type);
                if (vocab && name !== null) {
                    if (!ancestors(vocab, name).length) report(path, '@type', 'error', '"' + type + '" is not a known schema.org type.');
                    else {
                        known.push(name);
                        if (vocab.supersededTypes[name]) report(path, '@type', 'warning', name + ' is superseded by ' + vocab.supersededTypes[name] + '.');
                    }
                } else if (!vocab && !/^[A-Z][A-Za-z0-9]*$/.test(type) && name !== null) report(path, '@type', 'notice', 'Unrecognised @type "' + type + '". Use a schema.org type name such as Organization.');
            });
            if (node['@type'] === undefined) report(path, '', 'warning', 'No @type. Add one unless this node only extends an entity defined elsewhere.');
            if (node['@id'] !== undefined && (typeof node['@id'] !== 'string' || !node['@id'].trim())) report(path, '@id', 'error', '@id must be a non-empty string.');
            var id = typeof node['@id'] === 'string' ? node['@id'] : '';
            if (id && info.ids[id] > 1) report(path, '@id', 'notice', 'Repeated @id definition: ' + id + '. Merge the properties into one node.');
            var signature = info.nodes.filter(function (other) { return other.node === node; }).map(function (other) { return other.signature; })[0];
            if (signature && info.anonymous[signature] > 1) report(path, '', 'notice', 'Identical top-level node. Remove the copy.');
            var missing = [];
            types.forEach(function (type) {
                (recommended[schemaName(type)] || []).forEach(function (field) {
                    var options = [].concat(field);
                    if (options.every(function (name) { return absent(node, name); }) && missing.indexOf(options.join(' or ')) === -1) missing.push(options.join(' or '));
                });
            });
            if (missing.length) report(path, '', 'notice', 'Recommended properties missing: ' + missing.join(', ') + '.');
            var lineage = [];
            known.forEach(function (name) { lineage = lineage.concat(ancestors(vocab, name)); });
            Object.keys(node).forEach(function (key) {
                if (key.charAt(0) === '@') return;
                if (urlFields.indexOf(key) !== -1) [].concat(node[key]).forEach(function (item) {
                    if (typeof item === 'string' && !httpUrl(item)) report(path, key, 'warning', key + ' must be an absolute http(s) URL: ' + item);
                });
                // Action annotations such as query-input / target-output qualify an existing property.
                var term = schemaName(key.replace(/-(input|output)$/, ''));
                if (!vocab || !known.length || term === null) return;
                var property = Object.prototype.hasOwnProperty.call(vocab.properties, term) ? vocab.properties[term] : null;
                // validator.schema.org reports unrecognised properties as warnings, not errors.
                if (!property) { report(path, key, 'warning', 'The property ' + key + ' is not recognised by the schema (e.g. schema.org).'); return; }
                if (property[0].length && !property[0].some(function (domain) { return lineage.indexOf(domain) !== -1; })) {
                    report(path, key, 'warning', 'The property ' + key + ' is not recognised by the schema (e.g. schema.org) for an object of type ' + known.join(', ') + '.');
                }
                if (property[2]) report(path, key, 'warning', key + ' is superseded by ' + property[2] + '.');
                if (term !== key) return;
                [].concat(node[key]).forEach(function (child) {
                    if (!isObject(child) || isReference(child) || child['@type'] === undefined) return;
                    [].concat(child['@type']).map(schemaName).filter(function (name) { return name && ancestors(vocab, name).length; }).forEach(function (name) {
                        var line = ancestors(vocab, name);
                        if (property[1].length && line.indexOf('Role') === -1 && !property[1].some(function (range) { return line.indexOf(range) !== -1; })) {
                            report(path, key, 'warning', name + ' is not a known valid target type for the ' + key + ' property (expected ' + property[1].join(', ') + ').');
                        }
                    });
                });
            });
        }
        function walk(node, path) {
            if (!node || typeof node !== 'object') return;
            if (!Array.isArray(node) && (node['@type'] !== undefined || node['@id'] !== undefined && !isReference(node))) checkNode(node, path);
            Object.keys(node).forEach(function (key) {
                var child = node[key];
                if (!Array.isArray(node) && key.charAt(0) !== '@') [].concat(child).forEach(function (item) {
                    if (isReference(item) && !info.ids[item['@id']]) report(path, key, 'notice', 'Reference ' + item['@id'] + ' has no definition in this JSON-LD. Confirm another schema source defines it.');
                });
                if (child && typeof child === 'object') walk(child, path.concat(Array.isArray(node) ? Number(key) : key));
            });
        }
        if (value && typeof value === 'object') walk(value, []);
        if (!nodeCount && value && typeof value === 'object') report([], '', 'error', 'No schema nodes found. Add at least one object with @type.');
        // Group top-level items by type; findings outside every item stay at document level.
        var groups = [], byKey = Object.create(null), claimed = [], tops = topLevel(value);
        tops.forEach(function (item) {
            var key = [].concat(item.node['@type'] === undefined ? [] : item.node['@type']).filter(function (type) { return typeof type === 'string'; }).map(function (type) { return schemaName(type) || type; }).join(', ') || 'Untyped item';
            var own = findings.filter(function (finding) {
                return startsWith(finding.path, item.path) && !tops.some(function (other) { return other.path.length > item.path.length && startsWith(other.path, item.path) && startsWith(finding.path, other.path); });
            });
            own.forEach(function (finding) { claimed.push(finding); });
            if (!byKey[key]) groups.push(byKey[key] = {type: key, items: [], errors: 0, warnings: 0, notices: 0});
            byKey[key].items.push({path: item.path, node: item.node, findings: own});
            own.forEach(function (finding) { byKey[key][finding.severity + 's']++; });
        });
        var documentFindings = findings.filter(function (finding) { return claimed.indexOf(finding) === -1; });
        var count = function (severity) { return findings.filter(function (finding) { return finding.severity === severity; }).length; };
        return {findings: findings, document: documentFindings, groups: groups, errors: count('error'), warnings: count('warning'), notices: count('notice'),
            items: groups.reduce(function (total, group) { return total + group.items.length; }, 0), vocabulary: Boolean(vocab)};
    }
    var api = {analyze: analyze, remove: remove, add: add, fill: fill, check: check};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.ToolKitsSchemaEditor = api;
}(typeof window !== 'undefined' ? window : globalThis));
