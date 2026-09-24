<?php
if (!defined('ABSPATH')) { exit; }

function tk_incident_response_init(): void {
    add_action('admin_menu', 'tk_incident_response_register_page', 22);
    add_action('admin_post_tk_incident_snapshot', 'tk_incident_snapshot_handler');
    add_action('admin_post_tk_incident_save', 'tk_incident_save_handler');
    add_action('admin_post_tk_incident_export', 'tk_incident_export_handler');
}

function tk_incident_response_register_page(): void {
    $license_valid = (string) tk_get_option('license_status', 'inactive') === 'valid';
    $license_limited = (string) tk_get_option('license_type', '') === 'local';
    if (!tk_toolkits_can_manage() || !$license_valid || $license_limited) {
        return;
    }

    add_submenu_page(
        'tool-kits',
        __('Incident Response', 'tool-kits'),
        __('Incident Response', 'tool-kits'),
        'manage_options',
        'tool-kits-incident-response',
        'tk_incident_response_render_page'
    );
}

function tk_incident_default_tasks(): array {
    return array(
        'investigation' => array(
            'isolate_admin_access' => 'Restrict admin access to trusted IPs and known administrators.',
            'run_malware_scan' => 'Run Tool Kits Malware Scanner and review every critical/high finding.',
            'verify_core_files' => 'Verify WordPress core integrity and replace modified core files from a clean source.',
            'review_recent_admins' => 'Review administrator accounts, recent users, and unexpected role changes.',
            'rotate_credentials' => 'Rotate WordPress admin passwords, hosting/SFTP, database, SMTP, API, and secret keys.',
            'remove_backdoors' => 'Remove confirmed malicious files, injected code, rogue cron jobs, and unknown mu-plugins.',
            'patch_entry_point' => 'Update vulnerable plugins/themes/core and remove unused components.',
            'clear_all_caches' => 'Purge page cache, object cache, CDN cache, and server cache after cleanup.',
        ),
        'blocklist' => array(
            'confirm_clean_state' => 'Confirm the site is clean before requesting any blocklist review.',
            'scan_public_urls' => 'Check homepage, login page, sitemap, and top landing pages for injected output.',
            'google_safe_browsing' => 'Review Google Safe Browsing status and request review when clean.',
            'microsoft_smart_screen' => 'Review Microsoft SmartScreen/Bing security status where applicable.',
            'security_vendor_reviews' => 'Submit delisting requests to vendors that still flag the domain.',
            'monitor_recurrence' => 'Monitor browser warnings, firewall events, and malware scan results after delisting.',
        ),
        'search' => array(
            'remove_spam_urls' => 'Remove spam pages, doorway pages, cloaked content, and malicious redirects.',
            'repair_indexing_files' => 'Review robots.txt, sitemap.xml, canonical tags, and noindex directives.',
            'search_console_review' => 'Use Google Search Console Security Issues and Manual Actions reports.',
            'bing_webmaster_review' => 'Use Bing Webmaster Tools security and index coverage reports.',
            'request_recrawl' => 'Request re-crawl for cleaned URLs and resubmit clean sitemap.',
            'watch_search_results' => 'Track indexed spam URLs and suspicious snippets until search results normalize.',
        ),
    );
}

function tk_incident_get_tasks(): array {
    $saved = tk_get_option('incident_response_tasks', array());
    $saved = is_array($saved) ? $saved : array();
    $tasks = tk_incident_default_tasks();

    foreach ($tasks as $group => $items) {
        foreach ($items as $key => $label) {
            $tasks[$group][$key] = array(
                'label' => $label,
                'done' => !empty($saved[$group][$key]),
            );
        }
    }

    return $tasks;
}

function tk_incident_save_handler(): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Forbidden', 'tool-kits'), '', array('response' => 403));
    }
    tk_check_nonce('tk_incident_save');

    $defaults = tk_incident_default_tasks();
    $posted = isset($_POST['incident_tasks']) && is_array($_POST['incident_tasks']) ? wp_unslash($_POST['incident_tasks']) : array();
    $tasks = array();
    foreach ($defaults as $group => $items) {
        $tasks[$group] = array();
        foreach ($items as $key => $label) {
            $tasks[$group][$key] = !empty($posted[$group][$key]) ? 1 : 0;
        }
    }

    $status = sanitize_key((string) tk_post('incident_response_status', 'investigating'));
    if (!array_key_exists($status, tk_incident_status_options())) {
        $status = 'investigating';
    }

    tk_update_option('incident_response_status', $status);
    tk_update_option('incident_response_summary', sanitize_textarea_field((string) tk_post('incident_response_summary', '')));
    tk_update_option('incident_malware_removal_log', sanitize_textarea_field((string) tk_post('incident_malware_removal_log', '')));
    tk_update_option('incident_blocklist_notes', sanitize_textarea_field((string) tk_post('incident_blocklist_notes', '')));
    tk_update_option('incident_search_cleanup_notes', sanitize_textarea_field((string) tk_post('incident_search_cleanup_notes', '')));
    tk_update_option('incident_response_tasks', $tasks);
    tk_update_option('incident_response_updated_at', time());

    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-incident-response', 'tk_saved' => '1'), admin_url('admin.php')));
    exit;
}

function tk_incident_snapshot_handler(): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Forbidden', 'tool-kits'), '', array('response' => 403));
    }
    tk_check_nonce('tk_incident_snapshot');

    tk_update_option('incident_response_snapshot', tk_incident_build_snapshot());
    tk_update_option('incident_response_updated_at', time());

    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-incident-response', 'tk_snapshot' => 'created'), admin_url('admin.php')));
    exit;
}

function tk_incident_build_snapshot(): array {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugins = function_exists('get_plugins') ? get_plugins() : array();
    $active_plugins = (array) get_option('active_plugins', array());
    $malware_report = tk_get_option('malware_scan_report', array());
    $malware_results = is_array($malware_report) && isset($malware_report['results']) && is_array($malware_report['results'])
        ? $malware_report['results']
        : array();

    return array(
        'time' => time(),
        'site_url' => home_url('/'),
        'wp_version' => get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'active_theme' => wp_get_theme()->get('Name') . ' ' . wp_get_theme()->get('Version'),
        'plugin_count' => count($plugins),
        'active_plugin_count' => count($active_plugins),
        'administrator_count' => count(get_users(array('role' => 'administrator', 'fields' => 'ID'))),
        'malware_findings' => count($malware_results),
        'critical_malware_findings' => count(array_filter($malware_results, function ($result) {
            return (string) ($result['severity'] ?? '') === 'critical';
        })),
        'firewall_log_count' => count((array) tk_get_option('firewall_event_log', array())),
        'login_log_count' => count((array) get_option('tk_login_logs', array())),
    );
}

function tk_incident_status_options(): array {
    return array(
        'investigating' => 'Investigating',
        'cleaning' => 'Cleaning',
        'monitoring' => 'Post-cleanup monitoring',
        'review_requested' => 'Blocklist/search review requested',
        'resolved' => 'Resolved',
    );
}

function tk_incident_external_links(): array {
    $domain = wp_parse_url(home_url('/'), PHP_URL_HOST);
    $domain = is_string($domain) ? $domain : '';

    return array(
        'Google Safe Browsing' => 'https://transparencyreport.google.com/safe-browsing/search?url=' . rawurlencode($domain),
        'Google Search Console' => 'https://search.google.com/search-console',
        'Bing Webmaster Tools' => 'https://www.bing.com/webmasters',
        'VirusTotal Domain Report' => 'https://www.virustotal.com/gui/domain/' . rawurlencode($domain),
        'Sucuri SiteCheck' => 'https://sitecheck.sucuri.net/results/' . rawurlencode($domain),
        'Yandex Webmaster' => 'https://webmaster.yandex.com/',
    );
}

function tk_incident_export_handler(): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Forbidden', 'tool-kits'), '', array('response' => 403));
    }
    tk_check_nonce('tk_incident_export');

    $content = tk_incident_report_text();
    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="tool-kits-incident-report-' . gmdate('Ymd-His') . '.txt"');
    echo $content;
    exit;
}

function tk_incident_report_text(): string {
    $snapshot = tk_get_option('incident_response_snapshot', array());
    $snapshot = is_array($snapshot) ? $snapshot : array();
    $tasks = tk_incident_get_tasks();
    $lines = array(
        'Tool Kits Incident Response Report',
        'Generated: ' . gmdate('Y-m-d H:i:s') . ' UTC',
        'Site: ' . home_url('/'),
        'Status: ' . (tk_incident_status_options()[(string) tk_get_option('incident_response_status', 'investigating')] ?? 'Investigating'),
        '',
        'Investigation Summary',
        (string) tk_get_option('incident_response_summary', ''),
        '',
        'Malware Removal Log',
        (string) tk_get_option('incident_malware_removal_log', ''),
        '',
        'Blocklist Removal Notes',
        (string) tk_get_option('incident_blocklist_notes', ''),
        '',
        'Search Engine Cleanup Notes',
        (string) tk_get_option('incident_search_cleanup_notes', ''),
        '',
        'Snapshot',
    );

    foreach ($snapshot as $key => $value) {
        $lines[] = '- ' . $key . ': ' . (is_scalar($value) ? (string) $value : wp_json_encode($value));
    }

    $lines[] = '';
    $lines[] = 'Checklist';
    foreach ($tasks as $group => $items) {
        $lines[] = '[' . $group . ']';
        foreach ($items as $item) {
            $lines[] = '- [' . (!empty($item['done']) ? 'x' : ' ') . '] ' . (string) $item['label'];
        }
    }

    return implode("\n", $lines) . "\n";
}

function tk_incident_render_task_group(string $group, string $title, array $items): void {
    ?>
    <div class="tk-card">
        <h2><?php echo esc_html($title); ?></h2>
        <div style="display:grid; gap:10px;">
            <?php foreach ($items as $key => $item) : ?>
                <label style="display:flex; gap:10px; align-items:flex-start;">
                    <input type="checkbox" name="incident_tasks[<?php echo esc_attr($group); ?>][<?php echo esc_attr($key); ?>]" value="1" <?php checked(!empty($item['done'])); ?>>
                    <span><?php echo esc_html((string) $item['label']); ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function tk_incident_response_render_page(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    $status = (string) tk_get_option('incident_response_status', 'investigating');
    $status_options = tk_incident_status_options();
    $tasks = tk_incident_get_tasks();
    $snapshot = tk_get_option('incident_response_snapshot', array());
    $snapshot = is_array($snapshot) ? $snapshot : array();
    ?>
    <div class="wrap tk-wrap">
        <?php tk_render_header_branding(); ?>
        <?php tk_render_page_hero(__('Incident Response', 'tool-kits'), __('Investigation and malware removal tracking, post-incident blocklist removal, and search engine security cleanup workflows.', 'tool-kits'), 'dashicons-sos'); ?>
        <?php if (isset($_GET['tk_saved'])) : ?><?php tk_notice(__('Incident response checklist saved.', 'tool-kits'), 'success'); ?><?php endif; ?>
        <?php if (isset($_GET['tk_snapshot'])) : ?><?php tk_notice(__('Investigation snapshot created.', 'tool-kits'), 'success'); ?><?php endif; ?>

        <div class="notice notice-warning inline"><p><strong><?php esc_html_e('Controlled cleanup:', 'tool-kits'); ?></strong> <?php esc_html_e('This page tracks incident work and evidence. It does not delete files, submit delisting requests, or modify search engine accounts automatically.', 'tool-kits'); ?></p></div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_incident_save'); ?>
            <input type="hidden" name="action" value="tk_incident_save">

            <div class="tk-grid tk-grid-2" style="margin-bottom:24px;">
                <div class="tk-card">
                    <h2><?php esc_html_e('Case Status', 'tool-kits'); ?></h2>
                    <p>
                        <label><strong><?php esc_html_e('Current status', 'tool-kits'); ?></strong></label><br>
                        <select name="incident_response_status">
                            <?php foreach ($status_options as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p>
                        <label><strong><?php esc_html_e('Investigation summary', 'tool-kits'); ?></strong></label><br>
                        <textarea class="large-text" rows="6" name="incident_response_summary" placeholder="Timeline, suspected entry point, affected URLs, affected users, and cleanup decision notes."><?php echo esc_textarea((string) tk_get_option('incident_response_summary', '')); ?></textarea>
                    </p>
                    <p>
                        <label><strong><?php esc_html_e('Malware removal log', 'tool-kits'); ?></strong></label><br>
                        <textarea class="large-text" rows="6" name="incident_malware_removal_log" placeholder="Confirmed malicious files removed, clean files restored, credentials rotated, and patches applied."><?php echo esc_textarea((string) tk_get_option('incident_malware_removal_log', '')); ?></textarea>
                    </p>
                </div>

                <div class="tk-card">
                    <h2><?php esc_html_e('Investigation Snapshot', 'tool-kits'); ?></h2>
                    <p class="description"><?php esc_html_e('Capture current site facts before and after cleanup for support handoff and audit notes.', 'tool-kits'); ?></p>
                    <table class="widefat striped tk-table">
                        <tbody>
                            <?php if (empty($snapshot)) : ?>
                                <tr><td><?php esc_html_e('No snapshot captured yet.', 'tool-kits'); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ($snapshot as $key => $value) : ?>
                                    <tr><th><?php echo esc_html(ucwords(str_replace('_', ' ', (string) $key))); ?></th><td><?php echo esc_html(is_scalar($value) ? (string) $value : wp_json_encode($value)); ?></td></tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <p class="description"><?php esc_html_e('Use the buttons below the workflow to create snapshots or export the case report.', 'tool-kits'); ?></p>
                </div>
            </div>

            <div class="tk-grid tk-grid-3">
                <?php tk_incident_render_task_group('investigation', 'Investigation and Malware Removal', $tasks['investigation']); ?>
                <?php tk_incident_render_task_group('blocklist', 'Post-incident Blocklist Removal', $tasks['blocklist']); ?>
                <?php tk_incident_render_task_group('search', 'Post-incident Search Engine Security Cleanup', $tasks['search']); ?>
            </div>

            <div class="tk-grid tk-grid-2" style="margin-top:24px;">
                <div class="tk-card">
                    <h2><?php esc_html_e('Blocklist Removal Notes', 'tool-kits'); ?></h2>
                    <textarea class="large-text" rows="8" name="incident_blocklist_notes" placeholder="Vendor names, review URLs, request IDs, dates submitted, and current status."><?php echo esc_textarea((string) tk_get_option('incident_blocklist_notes', '')); ?></textarea>
                    <h3 style="margin-top:18px;"><?php esc_html_e('External Review Links', 'tool-kits'); ?></h3>
                    <ul style="margin-left:18px; list-style:disc;">
                        <?php foreach (tk_incident_external_links() as $label => $url) : ?>
                            <li><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($label); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="tk-card">
                    <h2><?php esc_html_e('Search Engine Security Cleanup Notes', 'tool-kits'); ?></h2>
                    <textarea class="large-text" rows="8" name="incident_search_cleanup_notes" placeholder="Spam URLs removed, sitemap fixes, Search Console/Bing findings, recrawl requests, and indexed result cleanup status."><?php echo esc_textarea((string) tk_get_option('incident_search_cleanup_notes', '')); ?></textarea>
                    <p class="description"><?php esc_html_e('Keep cleaned URL examples and search result evidence here until warnings and malicious snippets disappear.', 'tool-kits'); ?></p>
                </div>
            </div>

            <p style="margin-top:24px;"><button class="button button-primary button-hero"><?php esc_html_e('Save Incident Workflow', 'tool-kits'); ?></button></p>
        </form>

        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:-8px;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php tk_nonce_field('tk_incident_snapshot'); ?>
                <input type="hidden" name="action" value="tk_incident_snapshot">
                <button class="button"><?php esc_html_e('Create Snapshot', 'tool-kits'); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php tk_nonce_field('tk_incident_export'); ?>
                <input type="hidden" name="action" value="tk_incident_export">
                <button class="button"><?php esc_html_e('Export Report', 'tool-kits'); ?></button>
            </form>
        </div>
    </div>
    <?php
}
