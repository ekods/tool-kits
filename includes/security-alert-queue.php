<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/job-lock.php';

function tk_security_alert_queue_init(): void {
    add_action('tk_security_alert_dispatch', 'tk_security_alert_dispatch');
    add_action('tk_security_alert_sweep', 'tk_security_alert_dispatch');
    add_action('init', 'tk_security_alert_schedule_sweep');
}

function tk_security_alert_schedule_sweep(): void {
    // A backstop recovers pending jobs if scheduling the immediate event ever fails.
    if (!wp_next_scheduled('tk_security_alert_sweep')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'tk_security_alert_sweep');
    }
}

function tk_security_alert_schedule(int $timestamp): void {
    $next = wp_next_scheduled('tk_security_alert_dispatch');
    if (!$next || $next > $timestamp) {
        wp_clear_scheduled_hook('tk_security_alert_dispatch');
        wp_schedule_single_event($timestamp, 'tk_security_alert_dispatch');
    }
}

function tk_security_alert_enqueue(string $subject, string $message): void {
    global $wpdb;
    $email = tk_security_alert_email();
    $webhook = (string) tk_get_option('toolkits_alert_webhook', '');
    if ($email === '' && $webhook === '') { return; }
    $subject = substr(str_replace(array("\r", "\n"), ' ', $subject), 0, 240);
    $message = substr($message, 0, 16000);
    // Timestamps in human-readable event messages must not defeat duplicate detection.
    $identity = preg_replace('/^Time:.*$/m', 'Time: [event]', $message);
    $key = 'tk_jobs_alert_' . hash('sha256', $subject . "\n" . $identity . "\n" . $email . "\n" . $webhook);
    $old = get_option($key, array());
    if (is_array($old) && $old && time() - (int) $old['created'] < 600) {
        // Content is immutable; concurrent duplicate updates cannot overwrite delivery checkpoints.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $new = $old; $new['duplicates'] = (int) ($old['duplicates'] ?? 0) + 1;
            if (tk_job_replace($key, $old, $new)) { break; }
            $old = get_option($key, array());
            if (!$old) { break; }
        }
        tk_security_alert_schedule(time() + 10);
        return;
    }
    // Existing unfinished deliveries retain their own retry cycle even after the dedup window.
    if (($old['status'] ?? '') === 'pending') { tk_security_alert_schedule(time() + 10); return; }
    $size = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('tk_jobs_alert_') . '%'));
    if ($size >= 100) {
        tk_toolkits_audit_log('security_alert_queue_full', array('subject' => $subject));
        tk_security_alert_schedule(time() + 10);
        return;
    }
    $job = array('created' => time(), 'status' => 'pending', 'subject' => $subject, 'message' => $message, 'duplicates' => 0,
        'channels' => array(
            'email' => array('target' => $email, 'status' => $email === '' ? 'skipped' : 'pending', 'attempts' => 0, 'next' => time()),
            'webhook' => array('target' => $webhook, 'status' => $webhook === '' ? 'skipped' : 'pending', 'attempts' => 0, 'next' => time()),
        ));
    $stored = !$old ? add_option($key, $job, '', false) : tk_job_replace($key, $old, $job);
    if ($stored) { tk_toolkits_audit_log('security_alert_queued', array('subject' => $subject)); }
    tk_security_alert_schedule(time() + 10);
}

function tk_security_alert_checkpoint(string $key, array $job): array {
    // Enqueue can only change duplicates. Preserve increments made while a network request ran.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $current = get_option($key, array());
        if (!$current) { throw new RuntimeException('Queued alert disappeared.'); }
        $job['duplicates'] = $current['duplicates'] ?? 0;
        if (tk_job_replace($key, $current, $job)) { return $job; }
    }
    throw new RuntimeException('Queued alert changed concurrently.');
}

function tk_security_alert_deliver(string $key, array $job): void {
    $retry_delays = array(60, 300, 900, 3600, 14400);
    foreach ($job['channels'] as $name => $channel) {
        if ($channel['status'] !== 'pending' || $channel['next'] > time()) { continue; }
        $retry_after = 0;
        $retryable = true;
        $message = $job['message'] . (!empty($job['duplicates']) ? "\n\nRepeated events grouped: " . ((int) $job['duplicates'] + 1) : '');
        try {
            if ($name === 'email') {
                $ok = wp_mail($channel['target'], $job['subject'], $message);
            } else {
                $response = wp_safe_remote_post($channel['target'], array('timeout' => 5, 'redirection' => 0, 'limit_response_size' => 1024,
                    'headers' => array('Content-Type' => 'application/json', 'X-Tool-Kits-Event-ID' => substr($key, 14) . '-' . $job['created']),
                    'body' => wp_json_encode(array('text' => '*' . $job['subject'] . "*\n" . $message))));
                $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
                $ok = $code >= 200 && $code < 300;
                $retryable = $code === 0 || $code === 408 || $code === 429 || $code >= 500;
                if ($code === 429 || $code === 503) { $retry_after = tk_job_retry_after((string) wp_remote_retrieve_header($response, 'retry-after')); }
            }
        } catch (Throwable $error) {
            // Third-party mail/HTTP hooks may throw. Count the attempt without storing their error text.
            $ok = false; $retryable = true;
        }
        $channel['attempts']++;
        if ($ok) { $channel['status'] = 'sent'; }
        elseif (!$retryable || $channel['attempts'] >= 6) { $channel['status'] = 'failed'; }
        else { $channel['next'] = time() + max($retry_after, $retry_delays[$channel['attempts'] - 1] + wp_rand(0, 30)); }
        $job['channels'][$name] = $channel;
        // Save each successful channel before attempting the next one.
        $job = tk_security_alert_checkpoint($key, $job);
    }
    $pending = array_filter($job['channels'], static function ($channel) { return $channel['status'] === 'pending'; });
    if (!$pending) {
        $failed = array_filter($job['channels'], static function ($channel) { return $channel['status'] === 'failed'; });
        $job['status'] = $failed ? 'failed' : 'sent';
        $job['finished'] = time();
        unset($job['message']);
        foreach ($job['channels'] as &$channel) { unset($channel['target']); } unset($channel);
        tk_security_alert_checkpoint($key, $job);
        tk_toolkits_audit_log($failed ? 'security_alert_delivery_failed' : 'security_alert_delivered', array('subject' => $job['subject']));
    }
}

function tk_security_alert_dispatch(): void {
    global $wpdb;
    $lease = tk_job_lock('alerts', 120);
    if (!$lease) { tk_security_alert_schedule(time() + 60); return; }
    try {
        $keys = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 110", $wpdb->esc_like('tk_jobs_alert_') . '%'));
        $deadline = microtime(true) + 15;
        $processed = 0; $next = null;
        foreach ($keys as $key) {
            $job = get_option($key, array());
            if (!$job) { continue; }
            if (time() - (int) $job['created'] >= DAY_IN_SECONDS || !tk_security_alert_enabled()) {
                if (($job['status'] ?? '') === 'pending') {
                    tk_toolkits_audit_log(tk_security_alert_enabled() ? 'security_alert_expired' : 'security_alert_cancelled', array('subject' => $job['subject'] ?? ''));
                }
                delete_option($key); continue;
            }
            if ($job['status'] !== 'pending') {
                if (time() - (int) ($job['finished'] ?? $job['created']) >= 600) { delete_option($key); }
                continue;
            }
            $due = min(array_column(array_filter($job['channels'], static function ($c) { return $c['status'] === 'pending'; }), 'next') ?: array(time()));
            if ($due <= time() && $processed < 5 && microtime(true) < $deadline) {
                try { tk_security_alert_deliver($key, $job); }
                catch (Throwable $error) { tk_toolkits_audit_log('security_alert_worker_error'); }
                $job = get_option($key, array()); $processed++;
            }
            foreach ($job['channels'] ?? array() as $channel) {
                if ($channel['status'] === 'pending') { $next = min($next ?? PHP_INT_MAX, max(time() + 10, (int) $channel['next'])); }
            }
        }
        if ($next !== null) { tk_security_alert_schedule($next); }
    } finally { tk_job_unlock('alerts', $lease); }
}
