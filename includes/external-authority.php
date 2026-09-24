<?php
if (!defined('ABSPATH')) { exit; }

function tk_authority_init(): void {
    add_action('admin_post_tk_authority_save', 'tk_authority_save');
    add_action('admin_post_tk_authority_check', 'tk_authority_check');
}

function tk_authority_external_url(string $url): bool {
    $parts = wp_parse_url($url);
    $home = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    $host = strtolower((string) ($parts['host'] ?? ''));
    return in_array($parts['scheme'] ?? '', array('https', 'http'), true)
        && $host !== '' && empty($parts['user']) && empty($parts['pass'])
        && $host !== $home && preg_replace('/^www\./', '', $host) !== preg_replace('/^www\./', '', $home)
        && substr($host, -strlen('.' . $home)) !== '.' . $home;
}

function tk_authority_save(): void {
    tk_require_admin_post('tk_authority_save');
    if (!tk_license_features_enabled()) { wp_die('Feature unavailable'); }
    $brand = isset($_POST['authority_brand']) && is_string($_POST['authority_brand']) ? sanitize_text_field(wp_unslash($_POST['authority_brand'])) : '';
    $raw = isset($_POST['authority_urls']) && is_string($_POST['authority_urls']) ? wp_unslash($_POST['authority_urls']) : '';
    $urls = array();
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $url = esc_url_raw(trim($line));
        if ($url !== '' && tk_authority_external_url($url)) { $urls[] = $url; }
        if (count($urls) >= 50) { break; }
    }
    tk_update_option('authority_brand', $brand);
    if (isset($_POST['authority_timeout']) && is_scalar($_POST['authority_timeout'])) {
        tk_update_option('authority_timeout', max(5, min(30, (int) $_POST['authority_timeout'])));
    }
    tk_update_option('authority_urls', array_values(array_unique($urls)));
    tk_update_option('authority_reports', array());
    wp_safe_redirect(tk_admin_url('tool-kits-authority'));
    exit;
}

function tk_authority_parse_evidence(string $html, string $brand, string $site_url): array {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try { $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    $xpath = new DOMXPath($document);
    foreach ($xpath->query('//script|//style|//noscript|//head') as $node) { $node->parentNode->removeChild($node); }
    $text = trim(preg_replace('/\s+/u', ' ', $document->textContent) ?? '');
    $mention = $brand !== '' && preg_match('/(?<![\pL\pN])' . preg_quote($brand, '/') . '(?![\pL\pN])/iu', $text) === 1;
    $site_host = preg_replace('/^www\./', '', strtolower((string) wp_parse_url($site_url, PHP_URL_HOST)));
    $links = array();
    foreach ($xpath->query('//a[@href]') as $anchor) {
        $href = $anchor->getAttribute('href');
        $host = preg_replace('/^www\./', '', strtolower((string) wp_parse_url($href, PHP_URL_HOST)));
        if ($site_host !== '' && $host === $site_host) {
            $links[] = array('url' => $href, 'rel' => $anchor->getAttribute('rel'));
        }
    }
    return array('brand_mention' => $mention, 'links' => array_slice($links, 0, 10), 'status' => $mention || $links ? 'evidence-detected' : 'not-verified');
}

function tk_authority_check(): void {
    tk_require_admin_post('tk_authority_check');
    if (!tk_license_features_enabled()) { wp_die('Feature unavailable'); }
    $urls = (array) tk_get_option('authority_urls', array());
    $url = isset($_POST['authority_url']) && is_string($_POST['authority_url']) ? wp_unslash($_POST['authority_url']) : '';
    if (!in_array($url, $urls, true) || !tk_authority_external_url($url)) { wp_die('Invalid source URL'); }
    $timeout = max(5, min(30, (int) tk_get_option('authority_timeout', 20)));
    $response = wp_safe_remote_get($url, array('timeout' => $timeout, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES, 'headers' => array('User-Agent' => 'Tool Kits External Authority Check')));
    $report = array('checked_at' => time(), 'site_url' => home_url('/'), 'status' => 'not-verified', 'http' => 0, 'brand_mention' => false, 'links' => array());
    if (is_wp_error($response)) {
        $report['error'] = $response->get_error_message();
        if (strpos($report['error'], 'cURL error 28:') !== false) {
            $report['error'] = 'Source did not respond within ' . $timeout . ' seconds. Retry later or review CDN/firewall access. No authority conclusion was made.';
        }
    }
    else {
        $report['http'] = (int) wp_remote_retrieve_response_code($response);
        if ($report['http'] !== 200) { $report['error'] = 'HTTP ' . $report['http'] . ': use the final public URL or review access restrictions.'; }
        elseif (stripos((string) wp_remote_retrieve_header($response, 'content-type'), 'text/html') === false) { $report['error'] = 'Source is not HTML.'; }
        else { $report = array_merge($report, tk_authority_parse_evidence((string) wp_remote_retrieve_body($response), (string) tk_get_option('authority_brand', ''), home_url('/'))); }
    }
    $reports = (array) tk_get_option('authority_reports', array());
    $reports[hash('sha256', $url)] = $report;
    tk_update_option('authority_reports', $reports);
    wp_safe_redirect(tk_admin_url('tool-kits-authority'));
    exit;
}

function tk_render_authority_page(): void {
    if (!tk_is_admin_user() || !tk_license_features_enabled()) { return; }
    $brand = (string) tk_get_option('authority_brand', get_bloginfo('name'));
    $urls = (array) tk_get_option('authority_urls', array());
    $reports = (array) tk_get_option('authority_reports', array());
    ?>
    <div class="wrap tk-wrap">
        <?php tk_render_header_branding(); ?>
        <h1>External Authority</h1>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_authority_save'); ?>
            <input type="hidden" name="action" value="tk_authority_save">
            <p><label for="tk-authority-brand">Brand name</label></p>
            <input id="tk-authority-brand" type="text" name="authority_brand" value="<?php echo esc_attr($brand); ?>" class="regular-text" required>
            <p><label for="tk-authority-timeout">Request timeout (seconds)</label></p>
            <input id="tk-authority-timeout" type="number" name="authority_timeout" min="5" max="30" value="<?php echo esc_attr((string) max(5, min(30, (int) tk_get_option('authority_timeout', 20)))); ?>">
            <p><label for="tk-authority-urls">External source URLs</label></p>
            <textarea id="tk-authority-urls" name="authority_urls" rows="8" class="large-text" placeholder="https://example.com/company-profile&#10;https://example.org/coverage"><?php echo esc_textarea(implode("\n", $urls)); ?></textarea>
            <p><button class="button button-primary">Save Sources</button></p>
        </form>
        <table class="widefat striped"><thead><tr><th>Source</th><th>HTTP</th><th>Evidence</th><th>Last checked</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($urls as $url) : $report = $reports[hash('sha256', $url)] ?? array(); ?>
            <tr>
                <td style="overflow-wrap:anywhere;"><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($url); ?></a></td>
                <td><?php echo esc_html((string) ($report['http'] ?? '-')); ?></td>
                <td>
                    <?php echo esc_html($report['status'] ?? 'Not checked'); ?>
                    <?php if (!empty($report['brand_mention'])) : ?><p>Brand mention detected</p><?php endif; ?>
                    <?php foreach ($report['links'] ?? array() as $link) : ?><p><?php echo esc_html($link['url']); ?> <code><?php echo esc_html($link['rel']); ?></code></p><?php endforeach; ?>
                    <?php if (!empty($report['error'])) : ?><p><?php echo esc_html($report['error']); ?></p><?php endif; ?>
                </td>
                <td><?php echo !empty($report['checked_at']) ? esc_html(wp_date('Y-m-d H:i', $report['checked_at'])) : '-'; ?></td>
                <td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php tk_nonce_field('tk_authority_check'); ?>
                    <input type="hidden" name="action" value="tk_authority_check">
                    <input type="hidden" name="authority_url" value="<?php echo esc_attr($url); ?>">
                    <button class="button">Check</button>
                </form></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$urls) : ?><tr><td colspan="5">No sources saved.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
    <?php
}
