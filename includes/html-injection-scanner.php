<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/html-injection-engine.php';
require_once __DIR__ . '/html-injection-report.php';
require_once __DIR__ . '/html-injection-resources.php';
require_once __DIR__ . '/html-injection-view.php';

function tk_html_scan_init(): void {
    add_action('admin_menu', 'tk_html_scan_register_page', 22);
    add_action('admin_enqueue_scripts', 'tk_html_scan_enqueue');
    foreach (array('start', 'step', 'cancel', 'clear', 'restore') as $action) { add_action('wp_ajax_tk_html_scan_' . $action, 'tk_html_scan_' . $action . '_ajax'); }
}

function tk_html_scan_allowed(): bool {
    return current_user_can('manage_options') && tk_toolkits_can_manage() && tk_license_features_enabled();
}

function tk_html_scan_register_page(): void {
    if (!tk_html_scan_allowed()) { return; }
    add_submenu_page('tool-kits', 'HTML Injection Scanner', 'HTML Injection Scanner', 'manage_options', 'tool-kits-html-scanner', 'tk_html_scan_render_page');
}

function tk_html_scan_enqueue(): void {
    if (($_GET['page'] ?? '') !== 'tool-kits-html-scanner' || !tk_html_scan_allowed()) { return; }
    wp_enqueue_script('tk-html-injection-scanner', TK_URL . 'assets/html-injection-scanner.js', array(), tk_asset_version('assets/html-injection-scanner.js'), true);
    wp_enqueue_style('tk-html-injection-scanner', TK_URL . 'assets/html-injection-scanner.css', array(), tk_asset_version('assets/html-injection-scanner.css'));
}

function tk_html_scan_authorize(): void {
    if (!tk_html_scan_allowed()) { wp_send_json_error(array('message' => 'Access denied or Tool Kits license inactive.'), 403); }
    check_ajax_referer('tk_html_scan');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_send_json_error(array('message' => 'Use POST to run the scanner.'), 405); }
    if (!class_exists('DOMDocument')) { wp_send_json_error(array('message' => 'Enable the PHP DOM extension to use this scanner.'), 400); }
}

function tk_html_scan_settings(array $posted): array {
    $hosts = $posted['trusted_hosts'] ?? '';
    if (!is_string($hosts) || strlen($hosts) > 4000) { throw new InvalidArgumentException('Enter up to 4,000 characters of trusted host names.'); }
    $trusted = array();
    foreach (preg_split('/\s+/', strtolower(trim($hosts))) as $host) {
        if ($host === '') { continue; }
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/D', $host)) { throw new InvalidArgumentException('Trusted hosts must be exact domain names. Wildcards, URLs and host suffix rules are not accepted.'); }
        $trusted[] = $host;
    }
    foreach (array('max_urls', 'max_rows') as $key) {
        if (isset($posted[$key]) && !is_scalar($posted[$key])) { throw new InvalidArgumentException('Enter valid scan limits.'); }
    }
    return array('max_urls' => min(50, max(1, (int) ($posted['max_urls'] ?? 20))), 'max_rows' => min(5000, max(25, (int) ($posted['max_rows'] ?? 500))), 'trusted_hosts' => array_values(array_unique($trusted)));
}

function tk_html_scan_local_url(string $url): bool {
    $parts = wp_parse_url($url); $home = wp_parse_url(home_url('/'));
    if (!is_array($parts) || !is_array($home) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) { return false; }
    if (!empty($parts['query'])) {
        $query = array(); parse_str($parts['query'], $query);
        if (array_diff(array_keys($query), array('p', 'page_id', 'post_type')) || !isset($query['p']) && !isset($query['page_id'])) { return false; }
        foreach ($query as $key => $value) {
            if (!is_string($value) || ($key === 'post_type' ? !preg_match('/^[a-z0-9_-]+$/D', $value) : !preg_match('/^[1-9][0-9]*$/D', $value))) { return false; }
        }
    }
    return in_array($parts['scheme'] ?? '', array('http', 'https'), true) && ($parts['scheme'] ?? '') === ($home['scheme'] ?? '')
        && strtolower($parts['host'] ?? '') === strtolower($home['host'] ?? '') && ($parts['port'] ?? null) === ($home['port'] ?? null)
        && !preg_match('~/(?:wp-admin|wp-login\.php|wp-json|xmlrpc\.php)(?:/|$)~i', $parts['path'] ?? '/');
}

function tk_html_scan_targets(int $maximum): array {
    $urls = array(home_url('/')); $skipped = false;
    $ids = get_posts(array('post_type' => array_values(get_post_types(array('public' => true), 'names')), 'post_status' => 'publish', 'has_password' => false, 'numberposts' => $maximum + 1, 'fields' => 'ids', 'orderby' => 'modified', 'order' => 'DESC', 'suppress_filters' => false));
    foreach ($ids as $id) { $url = get_permalink($id); if (is_string($url) && tk_html_scan_local_url($url)) { $urls[] = $url; } else { $skipped = true; } }
    $urls = array_values(array_unique(array_filter($urls, 'tk_html_scan_local_url')));
    return array('urls' => array_slice($urls, 0, $maximum), 'limited' => $skipped || count($urls) > $maximum);
}

function tk_html_scan_start_ajax(): void {
    tk_html_scan_authorize();
    try { $settings = tk_html_scan_settings(wp_unslash($_POST)); }
    catch (InvalidArgumentException $error) { wp_send_json_error(array('message' => $error->getMessage()), 400); return; }
    if ((int) get_option('tk_html_scan_step_lock', 0) < time()) { delete_option('tk_html_scan_step_lock'); }
    if (!add_option('tk_html_scan_step_lock', time() + 60, '', false)) { wp_send_json_error(array('message' => 'A scanner operation is still running.'), 409); return; }
    $lock = get_option('tk_html_scan_lock', array());
    if (is_array($lock) && (int) ($lock['expires'] ?? 0) < time()) { delete_option('tk_html_scan_lock'); }
    $token = wp_generate_uuid4();
    $lock = array('token' => $token, 'owner' => get_current_user_id(), 'expires' => time() + 1800);
    if (!add_option('tk_html_scan_lock', $lock, '', false)) { delete_option('tk_html_scan_step_lock'); wp_send_json_error(array('message' => 'A scan is already running. Resume or stop it before starting another.'), 409); return; }
    try {
        $targets = tk_html_scan_targets($settings['max_urls']);
        $state = array('token' => $token, 'owner' => get_current_user_id(), 'settings' => $settings, 'urls' => $targets['urls'], 'phase' => 'posts', 'cursor' => 0,
            'report' => array('started' => time(), 'updated' => time(), 'status' => 'running', 'phase' => 'posts', 'counts' => array('posts' => 0, 'meta' => 0, 'options' => 0, 'urls' => 0), 'findings' => array(), 'issues' => $targets['limited'] ? array('Public URLs are a limited sample or some permalinks could not be checked.') : array(), 'limited' => $targets['limited'], 'max_urls' => count($targets['urls']), 'max_rows' => $settings['max_rows']));
        $state += array('providers' => TK_HTML_Injection_Context::providers(), 'restored' => get_option('tk_html_scan_restored', array()), 'baseline' => get_option('tk_html_scan_css_baseline', array()), 'css_queue' => array(), 'css_seen' => array(), 'css_cache' => array());
        $state['report'] += array('schema_version' => 2, 'suppressed' => array(), 'pages_scanned' => 0, 'files_analyzed' => 0, 'suppression_audit_overflow' => 0);
        tk_html_scan_persist($state);
        update_option('tk_html_scan_settings', $settings, false);
    } catch (Throwable $error) { delete_option('tk_html_scan_lock'); delete_option('tk_html_scan_step_lock'); wp_send_json_error(array('message' => 'The scan could not start. No site content was changed.'), 500); return; }
    delete_option('tk_html_scan_step_lock');
    wp_send_json_success(tk_html_scan_public_state($state));
}

function tk_html_scan_load(): array {
    $token = isset($_POST['token']) && is_string($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
    if (!preg_match('/^[a-f0-9-]{36}$/D', $token)) { throw new RuntimeException('Invalid scan session.'); }
    $state = get_transient('tk_html_scan_job_' . $token);
    $lock = get_option('tk_html_scan_lock', array());
    if (!is_array($state) || (int) $state['owner'] !== get_current_user_id() || ($lock['token'] ?? '') !== $token) { throw new RuntimeException('This scan session expired or belongs to another administrator.'); }
    return $state;
}

function tk_html_scan_persist(array $state): void {
    $state['report']['statistics'] = tk_html_scan_statistics($state['report']);
    if (!empty($state['baseline_dirty'])) { update_option('tk_html_scan_css_baseline', $state['baseline'], false); $state['baseline_dirty'] = false; }
    set_transient('tk_html_scan_job_' . $state['token'], $state, 1800);
    update_option('tk_html_scan_report', $state['report'], false);
    $lock = get_option('tk_html_scan_lock', array());
    if (($lock['token'] ?? '') === $state['token']) { $lock['expires'] = time() + 1800; update_option('tk_html_scan_lock', $lock, false); }
}

function tk_html_scan_public_state(array $state): array {
    $state['report']['statistics'] = tk_html_scan_statistics($state['report']);
    return array('token' => $state['token'], 'done' => $state['report']['status'] !== 'running', 'report' => $state['report']);
}

function tk_html_scan_issue(array &$state, string $message): void {
    $state['report']['limited'] = true;
    if (count($state['report']['issues']) < 40 && !in_array($message, $state['report']['issues'], true)) { $state['report']['issues'][] = $message; }
}

function tk_html_scan_source(array &$state, string $raw, string $source, string $source_url = '', int $post_id = 0): void {
    $strings = TK_HTML_Injection_Engine::strings($raw);
    if ($strings['limited']) { tk_html_scan_issue($state, $source . ': value exceeded limits or used an unsupported serialized format.'); }
    foreach ($strings['values'] as $part) {
        $base = $source_url !== '' ? $source_url : home_url('/');
        // Per-batch cache avoids reparsing identical builder fields without retaining raw HTML in the job.
        static $parsed = array();
        $cache_key = hash('sha256', $part['html'] . '|' . $base . '|' . (strpos($source, 'Public HTML / ') === 0 ? 'public' : 'stored') . '|' . json_encode(array($state['settings']['trusted_hosts'], $state['providers'] ?? array())));
        if (isset($parsed[$cache_key])) { $result = $parsed[$cache_key]; }
        else { $result = TK_HTML_Injection_Engine::scan($part['html'], $base, $state['settings']['trusted_hosts'], 0, strpos($source, 'Public HTML / ') !== 0, $state['providers'] ?? null); if (count($parsed) < 5) { $parsed[$cache_key] = $result; } }
        if ($result['limited']) { tk_html_scan_issue($state, $source . ': HTML analysis limit reached.'); }
        foreach (array('findings', 'suppressed') as $bucket) { foreach ($result[$bucket] as $finding) { tk_html_scan_record($state, $finding, $source . $part['path'], tk_html_scan_local_url($source_url) ? $source_url : '', $post_id); } }
        if ($result['audit_overflow']) { $state['report']['suppression_audit_overflow'] = ($state['report']['suppression_audit_overflow'] ?? 0) + $result['audit_overflow']; tk_html_scan_issue($state, 'Safe-pattern audit evidence exceeded the per-source budget; threat detection continued.'); }
        foreach ($result['audit_overflow_rules'] ?? array() as $id => $summary) { if (!isset($state['report']['suppression_overflow_rules'][$id])) { $state['report']['suppression_overflow_rules'][$id] = $summary; $state['report']['suppression_overflow_rules'][$id]['count'] = 0; } $state['report']['suppression_overflow_rules'][$id]['count'] += $summary['count']; $state['report']['suppression_overflow_rules'][$id]['timestamp'] = time(); }
        tk_html_scan_queue_css($state, $result['resources'], $base, $source_url !== '' ? array($source_url) : array());
    }
}

/** Only SELECTs against known tables/columns; cursor and response sizes bounded. */
function tk_html_scan_rows(string $phase, int $cursor, int $limit): array {
    global $wpdb;
    $bytes = TK_HTML_Injection_Engine::MAX_BYTES + 1;
    if ($phase === 'posts') {
        $query = $wpdb->prepare("SELECT ID AS id, LEFT(CAST(post_title AS BINARY), %d) AS post_title, LEFT(CAST(post_content AS BINARY), %d) AS post_content, LEFT(CAST(post_excerpt AS BINARY), %d) AS post_excerpt FROM {$wpdb->posts} WHERE ID > %d AND post_type NOT IN ('revision','attachment') AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC LIMIT %d", $bytes, $bytes, $bytes, $cursor, $limit + 1);
    } elseif ($phase === 'meta') {
        $query = $wpdb->prepare("SELECT m.meta_id AS id, m.post_id, m.meta_key, LEFT(CAST(m.meta_value AS BINARY), %d) AS scan_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_id > %d AND p.post_type NOT IN ('revision','attachment') AND p.post_status NOT IN ('trash','auto-draft') ORDER BY m.meta_id ASC LIMIT %d", $bytes, $cursor, $limit + 1);
    } elseif ($phase === 'options') {
        $clauses = array(); $arguments = array($bytes, $cursor);
        foreach (array('widget_', 'theme_mods_', 'theme_', 'themes_', 'options_') as $prefix) { $clauses[] = 'option_name LIKE %s'; $arguments[] = $wpdb->esc_like($prefix) . '%'; }
        $arguments[] = $limit + 1;
        $query = $wpdb->prepare("SELECT option_id AS id, option_name, LEFT(CAST(option_value AS BINARY), %d) AS scan_value FROM {$wpdb->options} WHERE option_id > %d AND (" . implode(' OR ', $clauses) . ') ORDER BY option_id ASC LIMIT %d', ...$arguments);
    } else { throw new RuntimeException('Unknown scan phase'); }
    $rows = $wpdb->get_results($query, ARRAY_A);
    if (!is_array($rows) || $wpdb->last_error !== '') { throw new RuntimeException('Database query failed for ' . $phase); }
    return $rows;
}

function tk_html_scan_database_step(array &$state): void {
    $phase = $state['phase'];
    $remaining = $state['settings']['max_rows'] - $state['report']['counts'][$phase];
    $limit = min(5, $remaining);
    $rows = tk_html_scan_rows($phase, $state['cursor'], $limit);
    $more = count($rows) > $limit;
    foreach (array_slice($rows, 0, $limit) as $row) {
        $state['cursor'] = (int) $row['id']; $state['report']['counts'][$phase]++;
        if ($phase === 'posts') {
            foreach (array('post_title', 'post_excerpt', 'post_content') as $field) { tk_html_scan_source($state, (string) $row[$field], 'posts #' . $row['id'] . ' / ' . $field, '', (int) $row['id']); }
        } else {
            $key = (string) $row[$phase === 'meta' ? 'meta_key' : 'option_name'];
            if (TK_HTML_Injection_Engine::secret_key($key) || preg_match('/(?:^_?tk_|^_edit_|^_wp_attachment_|(?:cache|transient|log|report)$)/i', $key)) { continue; }
            tk_html_scan_source($state, (string) $row['scan_value'], ($phase === 'meta' ? 'postmeta #' : 'options #') . $row['id'] . ' / ' . $key, '', (int) ($row['post_id'] ?? 0));
        }
        if (count($state['report']['findings']) >= 300) { return; }
    }
    if (!$more || $state['report']['counts'][$phase] >= $state['settings']['max_rows']) {
        if ($more) { tk_html_scan_issue($state, $phase . ': row limit reached; additional rows were not scanned.'); }
        $phases = array('posts' => 'meta', 'meta' => 'options', 'options' => 'urls');
        $state['phase'] = $phases[$phase]; $state['cursor'] = 0;
    }
}

function tk_html_scan_url_step(array &$state): void {
    $url = $state['urls'][$state['cursor']] ?? '';
    if ($url === '') { $state['phase'] = empty($state['css_queue']) ? 'done' : 'css'; return; }
    $state['cursor']++; $state['report']['counts']['urls']++;
    if (!tk_html_scan_local_url($url)) { tk_html_scan_issue($state, 'A URL was outside the permitted site origin.'); return; }
    $response = wp_safe_remote_get($url, array('timeout' => 8, 'redirection' => 0, 'limit_response_size' => TK_HTML_Injection_Engine::MAX_BYTES + 1, 'sslverify' => true, 'cookies' => array(), 'headers' => array('Accept' => 'text/html', 'User-Agent' => 'Tool Kits HTML Injection Scanner')));
    if (is_wp_error($response)) { tk_html_scan_issue($state, $url . ': HTTP request failed; this page was not checked.'); return; }
    $code = wp_remote_retrieve_response_code($response);
    $type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
    if ($code !== 200 || strpos($type, 'text/html') === false) { tk_html_scan_issue($state, $url . ': HTTP ' . $code . ' or non-HTML response; not checked (redirects are not followed).'); return; }
    tk_html_scan_source($state, wp_remote_retrieve_body($response), 'Public HTML / ' . $url, $url);
    $state['report']['pages_scanned'] = ($state['report']['pages_scanned'] ?? 0) + 1;
    if ($state['cursor'] >= count($state['urls'])) { $state['phase'] = empty($state['css_queue']) ? 'done' : 'css'; }
}

function tk_html_scan_step_ajax(): void {
    tk_html_scan_authorize();
    $step_lock = get_option('tk_html_scan_step_lock', 0);
    if ((int) $step_lock < time()) { delete_option('tk_html_scan_step_lock'); }
    if (!add_option('tk_html_scan_step_lock', time() + 60, '', false)) { wp_send_json_error(array('message' => 'A scan batch is still running. Wait before resuming.'), 409); return; }
    $failure = '';
    try {
        // Reload after taking the lock so retries never replay an old cursor.
        $state = tk_html_scan_load();
        try {
            if ($state['phase'] === 'urls') { tk_html_scan_url_step($state); }
            elseif ($state['phase'] === 'css') { tk_html_scan_css_step($state); }
            elseif ($state['phase'] !== 'done') { tk_html_scan_database_step($state); }
        } catch (Throwable $error) {
            tk_html_scan_issue($state, 'The ' . $state['phase'] . ' batch failed; remaining sources were not checked.');
            $state['phase'] = 'done';
        }
        if ($state['phase'] === 'done' || count($state['report']['findings']) >= 300) {
            if (count($state['report']['findings']) >= 300) { tk_html_scan_issue($state, 'Report limit reached; remaining sources were not checked.'); }
            $state['report']['status'] = $state['report']['limited'] ? 'partial' : 'complete';
        }
        $state['report']['phase'] = $state['phase']; $state['report']['updated'] = time();
        tk_html_scan_persist($state);
        if ($state['report']['status'] !== 'running') { delete_option('tk_html_scan_lock'); delete_transient('tk_html_scan_job_' . $state['token']); }
    } catch (Throwable $error) { $failure = 'Scan session could not be loaded. Reload the scanner page to resume.'; }
    finally { delete_option('tk_html_scan_step_lock'); }
    if ($failure !== '') { wp_send_json_error(array('message' => $failure), 403); return; }
    wp_send_json_success(tk_html_scan_public_state($state));
}

function tk_html_scan_cancel_ajax(): void {
    tk_html_scan_authorize();
    if ((int) get_option('tk_html_scan_step_lock', 0) < time()) { delete_option('tk_html_scan_step_lock'); }
    if (!add_option('tk_html_scan_step_lock', time() + 60, '', false)) { wp_send_json_error(array('message' => 'Wait for the current batch to finish before stopping.'), 409); return; }
    $failure = '';
    try {
        $state = tk_html_scan_load();
        $state['report']['status'] = 'cancelled'; $state['report']['updated'] = time();
        update_option('tk_html_scan_report', $state['report'], false);
        delete_transient('tk_html_scan_job_' . $state['token']); delete_option('tk_html_scan_lock');
    } catch (Throwable $error) { $failure = 'This scan session expired or belongs to another administrator.'; }
    finally { delete_option('tk_html_scan_step_lock'); }
    if ($failure !== '') { wp_send_json_error(array('message' => $failure), 403); return; }
    wp_send_json_success(tk_html_scan_public_state($state));
}

function tk_html_scan_clear_ajax(): void {
    tk_html_scan_authorize();
    if ((int) get_option('tk_html_scan_step_lock', 0) < time()) { delete_option('tk_html_scan_step_lock'); }
    if (!add_option('tk_html_scan_step_lock', time() + 60, '', false)) { wp_send_json_error(array('message' => 'A scanner operation is still running.'), 409); return; }
    $lock = get_option('tk_html_scan_lock', array());
    if ((int) ($lock['expires'] ?? 0) >= time()) { delete_option('tk_html_scan_step_lock'); wp_send_json_error(array('message' => 'Stop the active scan before clearing the report.'), 409); return; }
    delete_option('tk_html_scan_report');
    delete_option('tk_html_scan_step_lock');
    wp_send_json_success(array('cleared' => true));
}

function tk_html_scan_render_page(): void {
    if (!tk_html_scan_allowed()) { return; }
    $settings = get_option('tk_html_scan_settings', array('max_urls' => 20, 'max_rows' => 500, 'trusted_hosts' => array()));
    $report = get_option('tk_html_scan_report', array());
    $lock = get_option('tk_html_scan_lock', array());
    $resume = ($lock['owner'] ?? 0) === get_current_user_id() && ($lock['expires'] ?? 0) >= time() && get_transient('tk_html_scan_job_' . ($lock['token'] ?? '')) ? $lock['token'] : '';
    if (($report['status'] ?? '') === 'running' && (int) ($lock['expires'] ?? 0) < time()) { $report['status'] = 'interrupted'; $report['limited'] = true; $report['issues'][] = 'The previous session expired. Start a new scan; saved findings remain available.'; }
    ?>
    <div class="wrap tk-wrap">
        <?php tk_render_header_branding(); tk_render_page_hero('HTML Injection Scanner', 'Inspect public page HTML and stored content for suspicious scripts, redirects, hidden embeds, and external form destinations.', 'dashicons-editor-code'); ?>
        <div class="tk-card" id="tk-html-scan" data-edit-url="<?php echo esc_url(admin_url('post.php')); ?>" data-endpoint="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('tk_html_scan')); ?>" data-token="<?php echo esc_attr($resume); ?>">
            <h2>Scan HTML &amp; Database</h2>
            <p>Scans post titles/content/excerpts, post meta, selected widget/theme/ACF options, and the homepage plus recent public posts/pages. Findings require review; external resources can be legitimate. Site content is never modified.</p>
            <p class="description">Runs in small batches while this page is open. Reloading lets the same administrator resume for up to 30 minutes. Oversized, unsupported, failed and unscanned sources are reported as incomplete coverage.</p>
            <form id="tk-html-scan-form">
                <div class="tk-grid tk-grid-2">
                    <p><label for="tk-html-max-urls"><strong>Maximum public URLs</strong></label><br><input id="tk-html-max-urls" type="number" name="max_urls" min="1" max="50" value="<?php echo esc_attr((string) ($settings['max_urls'] ?? 20)); ?>"></p>
                    <p><label for="tk-html-max-rows"><strong>Maximum rows per database source</strong></label><br><input id="tk-html-max-rows" type="number" name="max_rows" min="25" max="5000" value="<?php echo esc_attr((string) ($settings['max_rows'] ?? 500)); ?>"></p>
                </div>
                <p><label for="tk-html-trusted"><strong>Trusted external hosts</strong></label><br><textarea id="tk-html-trusted" name="trusted_hosts" class="large-text" rows="4" placeholder="www.googletagmanager.com&#10;www.youtube.com"><?php echo esc_textarea(implode("\n", (array) ($settings['trusted_hosts'] ?? array()))); ?></textarea></p>
                <p class="description">One exact hostname per line. No wildcards or suffix matching. Custom trust allows expected CSS destinations; scripts still require path and behavior verification. Google Analytics/Tag Manager and Google Fonts have a separate recognized-provider registry. Remote scripts and external stylesheets are not downloaded, followed through redirects, or executed.</p>
                <p class="description">Discovered local CSS is inspected in batches (up to 100 files, 512 KiB each). First-seen file hashes become reference metadata; subsequent changes require review and are not automatically labeled malware. Existing wildcard host settings must be replaced with exact hosts before starting a new scan.</p>
                <p><button type="submit" class="button button-primary">Start New Scan</button> <button type="button" class="button" data-scan-resume <?php disabled($resume === ''); ?>>Resume Scan</button> <button type="button" class="button" data-scan-stop <?php disabled($resume === ''); ?>>Stop Scan</button> <button type="button" class="button" data-scan-clear>Clear Report</button></p>
            </form>
            <p role="status" aria-live="polite" data-scan-status>Ready. Reports identify suspicious patterns, not proof that a site is infected.</p>
            <progress data-scan-progress hidden aria-label="Scan running"></progress>
        </div>
        <?php tk_html_scan_render_report(is_array($report) ? $report : array()); ?>
    </div>
    <?php
}
