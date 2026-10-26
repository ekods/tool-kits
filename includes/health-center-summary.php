<?php
if (!defined('ABSPATH')) { exit; }

/** Small cached-option reads only; never enumerate plugins, scan files or make requests. */
function tk_health_center_environment(): string {
    global $wp_version;
    $plugins = (array) get_option('active_plugins', array());
    sort($plugins, SORT_STRING);
    $network_plugins = array_keys((array) get_site_option('active_sitewide_plugins', array()));
    sort($network_plugins, SORT_STRING);
    $settings = get_option('tk_options', array());
    $settings = is_array($settings) ? array_intersect_key($settings, array_flip(array(
        'seo_enabled', 'geo_enabled', 'seo_schema_enabled', 'firewall_enabled',
        'hardening_waf_enabled', 'hardening_security_headers', 'hardening_server_aware_enabled',
    ))) : array();
    ksort($settings, SORT_STRING);
    return hash('sha256', serialize(array(
        PHP_VERSION, (string) $wp_version, defined('TK_VERSION') ? TK_VERSION : '',
        ini_get('memory_limit'), defined('WP_DEBUG') && WP_DEBUG,
        defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY,
        defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,
        defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS,
        defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        defined('WP_CACHE') && WP_CACHE, $settings, tk_health_center_policy(),
        get_option('home', ''), get_option('stylesheet', ''), get_option('template', ''),
        $plugins, $network_plugins, get_option('tk_health_center_environment_revision', ''),
    )));
}

function tk_health_center_invalidate(): void {
    update_option('tk_health_center_environment_revision', bin2hex(random_bytes(8)), false);
}

function tk_health_center_freshness(array $report, ?int $now = null): array {
    $completed = (int) ($report['completed'] ?? 0);
    $now = $now ?? time();
    if ($completed <= 0) { return array('stale' => true, 'message' => 'No saved scan. Run a manual scan.'); }
    if (empty($report['environment']) || !hash_equals(tk_health_center_environment(), (string) $report['environment'])) {
        return array('stale' => true, 'message' => 'Run again: configuration changed or this report predates configuration tracking.');
    }
    if ($completed > $now + 300 || $now - $completed >= 86400) {
        return array('stale' => true, 'message' => 'Run again: this report is older than 24 hours or its timestamp needs verification.');
    }
    return array('stale' => false, 'message' => 'Saved scan is current.');
}

/** Reuse only the small saved PHP assessment; heartbeat never performs a Health Center scan. */
function tk_health_center_php_summary(): array {
    $report = get_option('tk_health_center_report', array());
    $php = is_array($report) && isset($report['php_security']) && is_array($report['php_security']) ? $report['php_security'] : array();
    if (!$php) {
        return array('status' => 'unknown', 'version' => '', 'sapi' => '', 'source' => 'unknown', 'checked_at' => 0, 'fixed_version' => '', 'stale' => true);
    }
    $summary = array_intersect_key($php, array_flip(array('status', 'version', 'sapi', 'source', 'checked_at', 'fixed_version')));
    // A changed environment invalidates a previous pass, even if the last scan was recent.
    $summary['stale'] = tk_health_center_freshness($report)['stale'];
    return $summary;
}

/** Only link to an existing, relevant Tool Kits control; hosting-only checks have no link. */
function tk_health_center_settings_link(array $check): array {
    if (!in_array($check['status'] ?? '', array('warning', 'critical'), true) || !tk_health_center_allowed()) { return array(); }
    $hardening = function_exists('tk_hardening_page_slug') ? tk_hardening_page_slug() : 'tool-kits-guard';
    $targets = array(
        'Dashboard file editor' => array($hardening, 'section-file_editor', 'File Editor settings', true),
        'MIME sniffing protection' => array($hardening, 'section-headers', 'Security Headers settings', true),
        'Referrer policy' => array($hardening, 'section-headers', 'Security Headers settings', true),
        'Frame embedding protection' => array($hardening, 'section-headers', 'Security Headers settings', true),
        'Content Security Policy' => array($hardening, 'section-csp', 'Content Security Policy settings', true),
        'HTML browser cache lifetime' => array('tool-kits-cache', 'page', 'Page Cache settings', false),
        'Homepage TTFB' => array('tool-kits-cache', 'page', 'Page Cache settings', false),
        'Homepage title' => array('tool-kits-seo', 'global-content', 'Global SEO Content settings', true),
        'Meta description' => array('tool-kits-seo', 'global-content', 'Global SEO Content settings', true),
        'Canonical URL' => array('tool-kits-seo', 'settings', 'SEO settings', true),
        'Search indexing' => array('tool-kits-seo', 'indexing', 'SEO Indexing settings', true),
    );
    $target = $targets[$check['label'] ?? ''] ?? null;
    if (!$target) { return array(); }
    $settings = get_option('tk_options', array());
    if ($target[3] && is_array($settings) && ($settings['license_type'] ?? '') === 'local') { return array(); }
    return array('url' => admin_url('admin.php?page=' . rawurlencode($target[0])) . '#' . $target[1], 'label' => $target[2]);
}

function tk_health_center_status_badge(string $status): string {
    $labels = array('passed' => 'Passed', 'warning' => 'Warning', 'critical' => 'Critical', 'unknown' => 'Not measured', 'info' => 'Recommended');
    if (!isset($labels[$status])) { $status = 'unknown'; }
    return '<span class="tk-health-status tk-health-check-status tk-health-' . esc_attr($status) . '">' . esc_html($labels[$status]) . '</span>';
}

function tk_health_center_recommendation(array $check): string {
    switch ($check['label'] ?? '') {
        case 'PHP2Shell runtime patch': return 'Update PHP through your host, restart web PHP workers, and verify the runtime patch or vendor backport.';
        case 'Public debug output': return 'Disable public debug display in wp-config.php; keep diagnostic logs private.';
        case 'Dashboard file editor': return 'Disable the dashboard code editor using DISALLOW_FILE_EDIT in wp-config.php.';
        case 'HTTPS homepage': return 'Configure a valid TLS certificate and set the WordPress homepage URL to HTTPS.';
        case 'HTML browser cache lifetime': return 'Shorten the HTML cache lifetime and purge the affected page cache.';
        case 'PHP memory limit': return 'Review actual memory needs and your hosting limits before changing the PHP memory budget.';
        case 'Persistent object cache': return 'Review whether a persistent object cache is appropriate for this site.';
        case 'Overdue cron events': return 'Review the overdue hooks and server scheduler before rerunning jobs.';
        case 'Recorded failed jobs': return 'Review retained Action Scheduler failures and resolve their causes before retrying.';
        default: return 'Open the saved check details and review the reported configuration before making changes.';
    }
}
