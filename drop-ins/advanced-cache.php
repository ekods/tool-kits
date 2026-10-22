<?php
// Tool Kits advanced-cache v1. Managed by Tool Kits; safe to remove on deactivation.
if (!defined('ABSPATH') || !defined('WP_CONTENT_DIR')) { return; }
foreach (array('DONOTCACHEPAGE', 'WP_ADMIN', 'DOING_AJAX', 'DOING_CRON', 'REST_REQUEST', 'WP_CLI', 'WP_INSTALLING', 'SHORTINIT', 'MULTISITE') as $tk_flag) {
    if (defined($tk_flag) && constant($tk_flag)) { return; }
}
$tk_config_file = WP_CONTENT_DIR . '/cache/tool-kits/page-cache-config.php';
if (!is_file($tk_config_file)) { return; }
$tk_config = include $tk_config_file;
if (!is_array($tk_config) || empty($tk_config['enabled']) || ($tk_config['valid_until'] ?? 0) <= time()
    || !is_file($tk_config['runtime'] ?? '') || !is_file($tk_config['plugin_file'] ?? '')
    || (int) @filemtime($tk_config['plugin_file']) !== ($tk_config['plugin_mtime'] ?? 0)) { return; }
require_once $tk_config['runtime'];
if (!class_exists('TK_Page_Cache_Runtime', false) || ($tk_config['format'] ?? null) !== TK_Page_Cache_Runtime::FORMAT
    || !TK_Page_Cache_Runtime::request_allowed($tk_config, $_SERVER, $_COOKIE)) { return; }
$tk_page = TK_Page_Cache_Runtime::read($tk_config['directory'] . '/' . TK_Page_Cache_Runtime::key($_SERVER) . '.html', (int) $tk_config['ttl']);
if ($tk_page === null || headers_sent()) { return; }
try { $tk_nonce = TK_Page_Cache_Runtime::new_nonce(); } catch (Exception $tk_error) { return; }
TK_Page_Cache_Runtime::send_headers($tk_page['headers'], $tk_nonce, 'HIT-EARLY');
echo TK_Page_Cache_Runtime::html_nonce($tk_page['html'], $tk_nonce);
exit;
