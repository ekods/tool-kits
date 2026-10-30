<?php
if (!defined('ABSPATH')) { exit; }

/** Per-blog lease. Compare-and-delete prevents an expired worker releasing its successor's lock. */
function tk_job_lock(string $name, int $seconds = 120): ?array {
    $key = 'tk_jobs_lock_' . $name;
    $lease = array('token' => bin2hex(random_bytes(16)), 'expires' => time() + $seconds);
    if (add_option($key, $lease, '', false)) { return $lease; }
    $old = get_option($key);
    if (is_array($old) && (int) ($old['expires'] ?? PHP_INT_MAX) < time()) {
        tk_job_unlock($name, $old);
        if (add_option($key, $lease, '', false)) { return $lease; }
    }
    return null;
}

function tk_job_unlock(string $name, array $lease): void {
    global $wpdb;
    $key = 'tk_jobs_lock_' . $name;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, maybe_serialize($lease)));
    wp_cache_delete($key, 'options');
}

function tk_job_replace(string $key, array $old, array $new): bool {
    global $wpdb;
    $changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize($new), $key, maybe_serialize($old)));
    wp_cache_delete($key, 'options');
    return $changed === 1;
}

function tk_job_retry_after(string $header): int {
    $header = trim($header);
    if ($header === '') { return 0; }
    $date = ctype_digit($header) ? null : strtotime($header);
    return max(0, min(86400, ctype_digit($header) ? (int) $header : ($date === false ? 0 : $date - time())));
}
