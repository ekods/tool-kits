<?php
if (!defined('ABSPATH')) { exit; }

function tk_geo_schema_editor_assets(): void {
    if (!tk_toolkits_can_manage() || ($_GET['page'] ?? '') !== 'tool-kits-geo-audit') return;
    wp_enqueue_style('tk-editor-modals', TK_URL . 'assets/editor-modals.css', array(), tk_asset_version('assets/editor-modals.css'));
    wp_enqueue_script('tk-schema-editor-tools', TK_URL . 'assets/schema-editor-tools.js', array(), tk_asset_version('assets/schema-editor-tools.js'), true);
    wp_enqueue_script('tk-geo-schema-editor-v2', TK_URL . 'assets/geo-schema-editor.js', array('tk-schema-editor-tools'), tk_asset_version('assets/geo-schema-editor.js'), true);
}

function tk_geo_schema_editor_modal(): void {
    ?>
    <dialog id="tk-schema-editor" class="tk-editor-modal tk-editor-modal-wide" data-nonce="<?php echo esc_attr(wp_create_nonce('tk_geo_schema_editor')); ?>" aria-labelledby="tk-schema-editor-title">
        <div class="tk-editor-modal-header"><div><h2 id="tk-schema-editor-title">Validate &amp; Edit JSON-LD</h2><p>Inspect schema, resolve overlaps, and complete missing details.</p></div><button type="button" class="tk-editor-modal-cross tk-schema-close" aria-label="Close dialog" title="Close">&times;</button></div>
        <div class="tk-editor-modal-body">
        <p class="tk-schema-editor-url"></p>
        <div class="tk-modal-tabs" role="tablist" aria-label="Schema editor sections">
            <button type="button" id="tk-schema-tab-live" role="tab" aria-controls="tk-schema-panel-live" aria-selected="true" data-schema-panel="live">Live Validation</button>
            <button type="button" id="tk-schema-tab-edit" role="tab" aria-controls="tk-schema-panel-edit" aria-selected="false" tabindex="-1" data-schema-panel="edit">Edit JSON-LD</button>
            <button type="button" id="tk-schema-tab-add" role="tab" aria-controls="tk-schema-panel-add" aria-selected="false" tabindex="-1" data-schema-panel="add">Add Missing Schema</button>
        </div>
        <section id="tk-schema-panel-live" class="tk-schema-panel" role="tabpanel" aria-labelledby="tk-schema-tab-live" data-panel="live">
        <p class="tk-modal-note">Red marks highlight repeated schema. Compare identities before removing a node; different entities can share the same type.</p>
        <div class="tk-schema-live" aria-live="polite"></div>
        <p class="description">Checks JSON structure and repeated identities. Rich-result eligibility requires separate validation.</p>
        </section>
        <section id="tk-schema-panel-edit" class="tk-schema-panel" role="tabpanel" aria-labelledby="tk-schema-tab-edit" data-panel="edit" hidden>
        <h3>Tool Kits Custom JSON-LD</h3>
        <p class="tk-modal-note">Changes apply site-wide. Theme and other plugin schema must be edited in their source settings.</p>
        <div class="tk-schema-edit-layout"><div>
        <label for="tk-schema-json">Custom JSON-LD (JSON only)</label>
        <textarea id="tk-schema-json" rows="12" style="width:100%;font-family:monospace;"></textarea>
        <button type="button" class="button tk-schema-undo" disabled>Undo Last JSON Edit</button>
        </div><div><h4 class="tk-modal-field-heading">Schema Nodes</h4><div class="tk-schema-custom-nodes"></div></div></div>
        <p class="description">Edits remain a draft until saved. Saving does not enable schema output.</p>
        </section>
        <section id="tk-schema-panel-add" class="tk-schema-panel" role="tabpanel" aria-labelledby="tk-schema-tab-add" data-panel="add" hidden>
        <h3>Add Missing Schema</h3>
        <p class="description">Add verified entities that describe your site. ImageObject is optional.</p>
        <p class="tk-schema-missing" aria-live="polite"></p>
        <div class="tk-schema-add-fields">
            <label>Schema type<select class="tk-schema-add-type"><option value="WebSite">WebSite</option><option value="Organization">Organization</option><option value="ImageObject">ImageObject</option></select></label>
            <label>Name<input type="text" class="tk-schema-add-name"></label>
            <label>URL / Image URL<input type="url" class="tk-schema-add-url" placeholder="https://example.com/"></label>
            <button type="button" class="button tk-schema-add" disabled>Add JSON Node</button>
        </div>
        </section>
        <p class="tk-schema-editor-status" role="status" aria-live="polite"></p>
        </div>
        <div class="tk-editor-modal-footer">
        <button type="button" class="button tk-schema-retry">Retry Live Check</button>
        <button type="button" class="button tk-schema-validate" disabled>Validate Draft</button>
        <button type="button" class="button tk-schema-close">Close</button>
        <button type="button" class="button button-primary tk-schema-save" disabled>Save &amp; Validate Live</button>
        </div>
    </dialog>
    <?php
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
    $walk = function ($node) use (&$walk, &$ids, &$types, &$messages) {
        if (!is_array($node)) return;
        if (isset($node['@type'])) {
            foreach ((array) $node['@type'] as $type) {
                if (!is_string($type) || trim($type) === '') { $messages[] = 'Invalid @type: use a non-empty string or an array of strings.'; continue; }
                $types[$type] = ($types[$type] ?? 0) + 1;
            }
        }
        if (isset($node['@id']) && count(array_diff(array_keys($node), array('@id'))) > 0) {
            if (!is_string($node['@id']) || trim($node['@id']) === '') $messages[] = 'Invalid @id: use a non-empty string.';
            else $ids[$node['@id']] = ($ids[$node['@id']] ?? 0) + 1;
        }
        foreach ($node as $child) if (is_array($child)) $walk($child);
    };
    $walk($value);
    $valid = !$messages && (bool) $types;
    if (!$types) $messages[] = 'No typed schema entities found.';
    foreach ($ids as $id => $count) if ($count > 1) $messages[] = 'Repeated entity definition: ' . $id . ' (' . $count . '). Compare properties; references using only @id are excluded.';
    foreach ($types as $type => $count) if ($count > 1) $messages[] = 'Repeated type: ' . $type . ' (' . $count . '). This alone does not prove duplication.';
    if ($valid) array_unshift($messages, 'JSON structure is valid. Review identity warnings before publishing.');
    return array('valid' => $valid, 'messages' => $messages, 'types' => $types,
        'duplicate_types' => array_filter($types, function ($count) { return $count > 1; }),
        'duplicate_ids' => array_filter($ids, function ($count) { return $count > 1; }));
}

function tk_geo_schema_live_documents(string $url): array {
    $response = wp_safe_remote_get($url, array('timeout' => 12, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES, 'headers' => array('Cache-Control' => 'no-cache')));
    if (is_wp_error($response)) return array('error' => $response->get_error_message());
    if (wp_remote_retrieve_response_code($response) !== 200 || stripos((string) wp_remote_retrieve_header($response, 'content-type'), 'text/html') === false) {
        return array('error' => 'Expected an HTTP 200 HTML response. Check redirects and cache settings.');
    }
    $html = (string) wp_remote_retrieve_body($response);
    $documents = array();
    if (preg_match_all('/<script\b[^>]*type=("|\')application\/ld\+json\1[^>]*>(.*?)<\/script>/is', $html, $matches)) {
        foreach ($matches[2] as $raw) {
            $raw = trim($raw);
            $decoded = json_decode($raw, true);
            $documents[] = array('json' => is_array($decoded) ? wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $raw,
                'validation' => tk_geo_schema_validate_json($raw));
        }
    }
    $combined = array();
    foreach ($documents as $document) {
        $decoded = json_decode($document['json'], true);
        if (is_array($decoded)) $combined[] = $decoded;
    }
    return array('documents' => $documents, 'combined' => $combined ? tk_geo_schema_validate_json(wp_json_encode($combined)) : array('messages' => array('No JSON-LD found.')));
}

function tk_geo_schema_editor_live_ajax(): void {
    tk_geo_schema_editor_ajax('live');
}

function tk_geo_schema_editor_ajax(string $operation = ''): void {
    if (!tk_toolkits_can_manage()) wp_send_json_error(array('message' => 'Forbidden'), 403);
    check_ajax_referer('tk_geo_schema_editor', 'nonce');
    $mode = $operation !== '' ? $operation : (isset($_POST['mode']) && is_string($_POST['mode']) ? $_POST['mode'] : '');
    if ($mode === 'validate') {
        if (!isset($_POST['json']) || !is_string($_POST['json'])) wp_send_json_error(array('message' => 'Invalid JSON input.'), 400);
        wp_send_json_success(array('validation' => tk_geo_schema_validate_json(wp_unslash($_POST['json']))));
    }
    if (!in_array($mode, array('load', 'save', 'live'), true)) wp_send_json_error(array('message' => 'Invalid operation.'), 400);
    $url = isset($_POST['url']) && is_string($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
    $parts = wp_parse_url($url);
    $saved = tk_get_option('geo_schema_duplicate_report', array());
    $allowed = is_array($saved) ? array_column((array) ($saved['items'] ?? array()), 'url') : array();
    if (!in_array($url, $allowed, true) || !is_array($parts) || !in_array($parts['scheme'] ?? '', array('http', 'https'), true) ||
        isset($parts['user']) || isset($parts['pass']) || strcasecmp($parts['host'] ?? '', (string) wp_parse_url(home_url('/'), PHP_URL_HOST)) !== 0) {
        wp_send_json_error(array('message' => 'Choose a same-site URL from the saved duplicate report.'), 400);
    }
    if ($mode === 'live') wp_send_json_success(array('operation' => 'live', 'live' => tk_geo_schema_live_documents($url)));
    $json = (string) tk_get_option('geo_custom_jsonld', '');
    if ($mode === 'save') {
        if (!isset($_POST['version'], $_POST['json']) || !is_string($_POST['version']) || !is_string($_POST['json'])) wp_send_json_error(array('message' => 'Invalid input.'), 400);
        if (!hash_equals(hash('sha256', $json), $_POST['version'])) wp_send_json_error(array('message' => 'Custom JSON-LD changed. Reopen the dialog.'), 409);
        $candidate = trim(wp_unslash($_POST['json']));
        $validation = tk_geo_schema_validate_json($candidate);
        if (!$validation['valid']) wp_send_json_error(array('message' => implode(' ', $validation['messages'])), 400);
        tk_update_option('geo_custom_jsonld', $candidate);
        $json = $candidate;
        if (function_exists('tk_clear_all_caches')) tk_clear_all_caches();
    }
    wp_send_json_success(array('json' => $json, 'version' => hash('sha256', $json), 'validation' => tk_geo_schema_validate_json($json),
        'defaults' => array('name' => (string) get_bloginfo('name'), 'url' => home_url('/')),
        'saved' => $mode === 'save'));
}
