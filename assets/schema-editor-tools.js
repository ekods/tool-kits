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
    var api = {analyze: analyze, remove: remove, add: add, fill: fill};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.ToolKitsSchemaEditor = api;
}(typeof window !== 'undefined' ? window : globalThis));
