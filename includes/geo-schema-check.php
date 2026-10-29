<?php
if (!defined('ABSPATH')) { exit; }

// Schema checks for GEO Setup > Custom JSON-LD and the Duplicate Detector.
function tk_geo_schema_check_assets(): void {
    if (!tk_toolkits_can_manage() || ($_GET['page'] ?? '') !== 'tool-kits-geo') return;
    wp_enqueue_style('tk-editor-modals', TK_URL . 'assets/editor-modals.css', array(), tk_asset_version('assets/editor-modals.css'));
    wp_enqueue_script('tk-schema-editor-tools', TK_URL . 'assets/schema-editor-tools.js', array(), tk_asset_version('assets/schema-editor-tools.js'), true);
    wp_enqueue_script('tk-geo-custom-jsonld', TK_URL . 'assets/geo-custom-jsonld.js', array('tk-schema-editor-tools'), tk_asset_version('assets/geo-custom-jsonld.js'), true);
}

function tk_geo_schema_canonical_node(array $node): string {
    unset($node['@context']);
    $sort = function ($value) use (&$sort) {
        if (!is_array($value)) return $value;
        if ($value && array_keys($value) !== range(0, count($value) - 1)) ksort($value, SORT_STRING);
        foreach ($value as $key => $child) $value[$key] = $sort($child);
        return $value;
    };
    return (string) json_encode($sort($node), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function tk_geo_schema_validate_json(string $json): array {
    if (trim($json) === '') return array('valid' => true, 'messages' => array('No custom JSON-LD configured.'));
    $value = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($value) || !$value) {
        return array('valid' => false, 'messages' => array('Enter a non-empty JSON-LD object or array. ' . json_last_error_msg()));
    }
    $ids = array();
    $types = array();
    $messages = array();
    $id_types = array();
    $anonymous = array();
    $anonymous_types = array();
    $walk = function ($node, $top_level = true) use (&$walk, &$ids, &$types, &$messages, &$id_types, &$anonymous, &$anonymous_types) {
        if (!is_array($node)) return;
        $node_types = array();
        if (isset($node['@type'])) {
            foreach ((array) $node['@type'] as $type) {
                if (!is_string($type) || trim($type) === '') { $messages[] = 'Invalid @type: use a non-empty string or an array of strings.'; continue; }
                if (in_array($type, $node_types, true)) continue;
                $node_types[] = $type;
                $types[$type] = ($types[$type] ?? 0) + 1;
            }
        }
        if (isset($node['@id']) && count(array_diff(array_keys($node), array('@id', '@context'))) > 0) {
            if (!is_string($node['@id']) || trim($node['@id']) === '') $messages[] = 'Invalid @id: use a non-empty string.';
            else {
                $ids[$node['@id']] = ($ids[$node['@id']] ?? 0) + 1;
                $id_types[$node['@id']][] = $node_types;
            }
        }
        if ($top_level && $node_types && !isset($node['@id'])) {
            $signature = tk_geo_schema_canonical_node($node);
            $anonymous[$signature] = ($anonymous[$signature] ?? 0) + 1;
            $anonymous_types[$signature] = $node_types;
        }
        $is_list = $node && array_keys($node) === range(0, count($node) - 1);
        foreach ($node as $key => $child) if (is_array($child)) $walk($child, $key === '@graph' || ($top_level && $is_list));
    };
    $walk($value);
    $valid = !$messages && (bool) $types;
    if (!$types) $messages[] = 'No typed schema entities found.';
    $duplicate_types = array();
    foreach ($ids as $id => $count) if ($count > 1) {
        $messages[] = 'Repeated entity definition: ' . $id . ' (' . $count . '). Review whether these are complementary descriptions or unintended duplicates; @id references are excluded.';
        foreach ($id_types[$id] as $node_types) foreach ($node_types as $type) $duplicate_types[$type] = ($duplicate_types[$type] ?? 0) + 1;
    }
    foreach ($anonymous as $signature => $count) if ($count > 1) {
        $messages[] = 'Identical top-level entity definition: ' . implode(', ', $anonymous_types[$signature]) . ' (' . $count . '). Review sources before removing a node.';
        foreach ($anonymous_types[$signature] as $type) $duplicate_types[$type] = ($duplicate_types[$type] ?? 0) + $count;
    }
    if ($valid) array_unshift($messages, 'JSON structure is valid. Review identity warnings before publishing.');
    return array('valid' => $valid, 'messages' => $messages, 'types' => $types,
        'repeated_types' => array_filter($types, function ($count) { return $count > 1; }),
        'duplicate_types' => $duplicate_types,
        'duplicate_ids' => array_filter($ids, function ($count) { return $count > 1; }));
}

// GEO Setup → Custom JSON-LD → Fetch URL: returns the raw JSON-LD blocks of any public page for the browser validator.
function tk_geo_schema_check_url_ajax(): void {
    if (!tk_toolkits_can_manage()) wp_send_json_error(array('message' => 'Forbidden'), 403);
    check_ajax_referer('tk_geo_schema_check', 'nonce');
    $url = isset($_POST['url']) && is_string($_POST['url']) ? esc_url_raw(trim(wp_unslash($_POST['url']))) : '';
    $parts = wp_parse_url($url);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', array('http', 'https'), true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        wp_send_json_error(array('message' => 'Enter a full http(s) URL.'), 400);
    }
    // wp_safe_remote_get() refuses private and loopback addresses unless the host is this site.
    $response = wp_safe_remote_get($url, array('timeout' => 15, 'redirection' => 3, 'limit_response_size' => 3 * MB_IN_BYTES, 'headers' => array('Cache-Control' => 'no-cache')));
    if (is_wp_error($response)) wp_send_json_error(array('message' => 'Could not fetch the URL: ' . $response->get_error_message()), 502);
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code !== 200 || stripos((string) wp_remote_retrieve_header($response, 'content-type'), 'html') === false) {
        wp_send_json_error(array('message' => 'Expected an HTTP 200 HTML page, got HTTP ' . $code . '.'), 502);
    }
    $blocks = array();
    if (preg_match_all('/<script\b[^>]*type=("|\')application\/ld\+json\1[^>]*>(.*?)<\/script>/is', (string) wp_remote_retrieve_body($response), $matches)) {
        foreach ($matches[2] as $raw) $blocks[] = trim($raw);
    }
    wp_send_json_success(array('url' => $url, 'blocks' => $blocks));
}
