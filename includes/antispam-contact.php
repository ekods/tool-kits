<?php
if (!defined('ABSPATH')) { exit; }

function tk_antispam_contact_init() {
    add_action('admin_post_tk_antispam_save', 'tk_antispam_save_settings');
    add_action('admin_post_tk_antispam_log_clear', 'tk_antispam_log_clear');

    // Contact Form 7 integration (optional, only active if CF7 present + enabled)
    add_filter('wpcf7_form_elements', 'tk_cf7_add_honeypot_and_time', 20, 1);
    add_filter('wpcf7_validate', 'tk_cf7_validate_honeypot_and_time', 20, 2);
    add_action('wpcf7_before_send_mail', 'tk_antispam_cf7_before_send_mail', 20, 3);
    add_filter('pre_wp_mail', 'tk_antispam_pre_wp_mail', 20, 2);
    add_filter('wpcf7_display_message', 'tk_antispam_cf7_display_message', 20, 2);
    add_filter('wpcf7_ajax_json_echo', 'tk_antispam_cf7_ajax_json_echo', 20, 2);
    add_filter('wpcf7_feedback_response', 'tk_antispam_cf7_ajax_json_echo', 20, 2);
}

function tk_antispam_enabled() {
    return (int) tk_get_option('antispam_cf7_enabled', 0) === 1;
}

function tk_antispam_key() {
    return 'tk_antispam_' . md5(tk_get_ip() . '|' . tk_user_agent());
}

function tk_antispam_scalar_post_values(): array {
    $values = array();
    foreach ($_POST as $key => $value) {
        if (!is_scalar($value)) {
            continue;
        }
        $name = sanitize_key((string) $key);
        if (strpos($name, '_wpcf7') === 0 || in_array($name, array('tk_hp_field', 'tk_form_ts', '_wpnonce', '_tk_nonce'), true)) {
            continue;
        }
        $values[$name] = trim((string) wp_unslash($value));
    }
    return $values;
}

function tk_antispam_line_list(string $raw): array {
    $parts = preg_split('/\r\n|\r|\n/', $raw);
    if (!is_array($parts)) {
        return array();
    }
    return array_values(array_filter(array_map('trim', $parts), function($item) {
        return is_string($item) && $item !== '';
    }));
}

function tk_antispam_current_host(): string {
    $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    return strtolower(preg_replace('/^www\./i', '', $host));
}

function tk_antispam_link_is_current_domain(string $url): bool {
    $url = trim($url);
    if ($url === '') {
        return false;
    }

    if (strpos($url, '//') === 0) {
        $url = 'https:' . $url;
    } elseif (stripos($url, 'www.') === 0) {
        $url = 'https://' . $url;
    }

    $host = (string) wp_parse_url($url, PHP_URL_HOST);
    if ($host === '') {
        return true;
    }

    $host = strtolower(preg_replace('/^www\./i', '', $host));
    $current_host = tk_antispam_current_host();

    return $current_host !== '' && $host === $current_host;
}

function tk_antispam_extract_links_from_value(string $value): array {
    $links = array();

    if (preg_match_all('/\bhref\s*=\s*(["\'])(.*?)\1/i', $value, $matches) && !empty($matches[2])) {
        foreach ($matches[2] as $url) {
            $links[] = trim((string) $url);
        }
    }

    if (preg_match_all('#https?://[^\s<>"\']+#i', $value, $matches) && !empty($matches[0])) {
        foreach ($matches[0] as $url) {
            $links[] = trim((string) $url);
        }
    }

    if (preg_match_all('/\bwww\.[^\s<>"\']+/i', $value, $matches) && !empty($matches[0])) {
        foreach ($matches[0] as $url) {
            $links[] = trim((string) $url);
        }
    }

    return array_values(array_unique(array_filter($links)));
}

function tk_antispam_submission_link_count(array $values, bool $external_only = true): int {
    $count = 0;
    foreach ($values as $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }

        foreach (tk_antispam_extract_links_from_value($value) as $url) {
            if ($external_only && tk_antispam_link_is_current_domain($url)) {
                continue;
            }
            $count++;
        }
    }
    return $count;
}

function tk_antispam_submission_email_domains(array $values): array {
    $domains = array();
    foreach ($values as $value) {
        if (!is_string($value) || !is_email($value)) {
            continue;
        }
        $parts = explode('@', strtolower($value));
        if (count($parts) === 2 && $parts[1] !== '') {
            $domains[] = $parts[1];
        }
    }
    return array_values(array_unique($domains));
}

function tk_antispam_submission_emails(array $values): array {
    $emails = array();
    foreach ($values as $value) {
        if (!is_string($value) || !is_email($value)) {
            continue;
        }
        $emails[] = strtolower(trim($value));
    }
    return array_values(array_unique($emails));
}

function tk_antispam_message_value(array $values): string {
    foreach ($values as $key => $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }
        if (strpos($key, 'message') !== false || strpos($key, 'pesan') !== false || strpos($key, 'comment') !== false) {
            return $value;
        }
    }
    foreach ($values as $value) {
        if (is_string($value) && $value !== '' && !is_email($value)) {
            return $value;
        }
    }
    return '';
}

function tk_antispam_name_value(array $values): string {
    foreach ($values as $key => $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }
        if (
            strpos($key, 'name') !== false ||
            strpos($key, 'nama') !== false ||
            strpos($key, 'full_name') !== false ||
            strpos($key, 'fullname') !== false
        ) {
            return $value;
        }
    }
    return '';
}

function tk_antispam_text_looks_random(string $value): bool {
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    $normalized = preg_replace('/[^a-z0-9]+/i', '', $value);
    if (!is_string($normalized) || $normalized === '') {
        return false;
    }

    $length = strlen($normalized);
    if ($length < 8) {
        return false;
    }

    $has_letters = (bool) preg_match('/[a-z]/i', $normalized);
    $has_digits = (bool) preg_match('/\d/', $normalized);
    $has_vowel = (bool) preg_match('/[aeiou]/i', $normalized);
    $has_long_consonant_run = (bool) preg_match('/[bcdfghjklmnpqrstvwxyz]{5,}/i', $normalized);
    $has_case_mix = (bool) (preg_match('/[a-z]/', $normalized) && preg_match('/[A-Z]/', $normalized));
    $unique_ratio = count(array_unique(str_split(strtolower($normalized)))) / max(1, $length);

    if ($has_long_consonant_run && $length >= 10) {
        return true;
    }

    if ($has_letters && $has_digits && $length >= 10 && $unique_ratio > 0.7) {
        return true;
    }

    if ($has_case_mix && !$has_vowel && $length >= 10) {
        return true;
    }

    return false;
}

function tk_antispam_email_local_part_looks_suspicious(string $email): bool {
    if (!is_email($email)) {
        return false;
    }

    $parts = explode('@', strtolower($email), 2);
    $local = $parts[0] ?? '';
    if ($local === '') {
        return false;
    }

    $segments = preg_split('/[._+-]+/', $local);
    if (!is_array($segments)) {
        $segments = array($local);
    }
    $segments = array_values(array_filter($segments, static function ($segment) {
        return is_string($segment) && $segment !== '';
    }));

    $short_segments = 0;
    foreach ($segments as $segment) {
        if (strlen($segment) <= 2) {
            $short_segments++;
        }
    }

    if (count($segments) >= 4 && $short_segments >= 3) {
        return true;
    }

    if (tk_antispam_text_looks_random($local)) {
        return true;
    }

    return false;
}

function tk_antispam_detect_random_submission_reason(array $values): string {
    $name = tk_antispam_name_value($values);
    $message = tk_antispam_message_value($values);
    $random_hits = 0;

    if ($name !== '' && tk_antispam_text_looks_random($name)) {
        $random_hits++;
    }

    if ($message !== '' && tk_antispam_text_looks_random($message)) {
        $random_hits++;
    }

    foreach ($values as $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }
        if (is_email($value) && tk_antispam_email_local_part_looks_suspicious($value)) {
            return 'suspicious_email_local_part';
        }
    }

    if ($random_hits >= 2) {
        return 'randomized_submission_pattern';
    }

    return '';
}

function tk_antispam_normalize_text(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/\s+/', ' ', $value);
    return is_string($value) ? $value : '';
}

function tk_antispam_submission_signature(array $values): string {
    $name = tk_antispam_normalize_text(tk_antispam_name_value($values));
    $message = tk_antispam_normalize_text(tk_antispam_message_value($values));
    $emails = tk_antispam_submission_emails($values);
    sort($emails);

    $payload = array(
        'name' => $name,
        'emails' => $emails,
        'message' => $message,
    );

    return md5(wp_json_encode($payload));
}

function tk_antispam_duplicate_key(string $signature): string {
    return 'tk_antispam_dup_' . $signature;
}

function tk_antispam_email_cooldown_key(string $email): string {
    return 'tk_antispam_email_cd_' . md5(strtolower($email));
}

function tk_antispam_ip_cooldown_key(): string {
    return 'tk_antispam_ip_cd_' . md5(tk_get_ip());
}

function tk_antispam_submission_request_cache(string $signature = '', bool $register = false): bool {
    static $seen = array();

    if ($signature === '') {
        return false;
    }

    if ($register) {
        $seen[$signature] = true;
    }

    return !empty($seen[$signature]);
}

function tk_antispam_replay_reason(array $values): string {
    $signature = tk_antispam_submission_signature($values);
    if ($signature !== '' && tk_antispam_submission_request_cache($signature)) {
        return '';
    }

    $duplicate_window = max(0, (int) tk_get_option('antispam_duplicate_window_minutes', 5));
    if (
        $duplicate_window > 0 &&
        $signature !== '' &&
        get_transient(tk_antispam_duplicate_key($signature))
    ) {
        return 'duplicate_submission';
    }

    $email_cooldown = max(0, (int) tk_get_option('antispam_email_cooldown_minutes', 15));
    if ($email_cooldown > 0) {
        foreach (tk_antispam_submission_emails($values) as $email) {
            if (get_transient(tk_antispam_email_cooldown_key($email))) {
                return 'email_cooldown:' . $email;
            }
        }
    }

    $ip_cooldown = max(0, (int) tk_get_option('antispam_ip_cooldown_seconds', 60));
    if ($ip_cooldown > 0 && get_transient(tk_antispam_ip_cooldown_key())) {
        return 'ip_cooldown_active';
    }

    return '';
}

function tk_antispam_register_submission(array $values): void {
    $signature = tk_antispam_submission_signature($values);
    $duplicate_window = max(0, (int) tk_get_option('antispam_duplicate_window_minutes', 5));
    if ($duplicate_window > 0 && $signature !== '') {
        set_transient(tk_antispam_duplicate_key($signature), 1, $duplicate_window * MINUTE_IN_SECONDS);
        tk_antispam_submission_request_cache($signature, true);
    }

    $email_cooldown = max(0, (int) tk_get_option('antispam_email_cooldown_minutes', 15));
    if ($email_cooldown > 0) {
        foreach (tk_antispam_submission_emails($values) as $email) {
            set_transient(tk_antispam_email_cooldown_key($email), 1, $email_cooldown * MINUTE_IN_SECONDS);
        }
    }

    $ip_cooldown = max(0, (int) tk_get_option('antispam_ip_cooldown_seconds', 60));
    if ($ip_cooldown > 0) {
        set_transient(tk_antispam_ip_cooldown_key(), 1, $ip_cooldown);
    }
}

function tk_antispam_contains_html(array $values): bool {
    foreach ($values as $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }
        if ($value !== wp_strip_all_tags($value)) {
            return true;
        }
    }
    return false;
}

function tk_antispam_shortener_domains(): array {
    return array('bit.ly', 'tinyurl.com', 'is.gd', 'cutt.ly', 't.co', 'rb.gy', 'rebrand.ly', 'goo.su');
}

function tk_antispam_contains_shortener(array $values): bool {
    $domains = tk_antispam_shortener_domains();
    foreach ($values as $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }
        $haystack = strtolower($value);
        foreach ($domains as $domain) {
            if (strpos($haystack, $domain) !== false) {
                return true;
            }
        }
    }
    return false;
}

function tk_antispam_log_get(): array {
    $log = tk_get_option('antispam_log', array());
    return is_array($log) ? array_values($log) : array();
}

function tk_antispam_log_record(string $reason, array $values = array()): void {
    $log = tk_antispam_log_get();
    foreach ($values as $key => $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }
        $sample[$key] = $value;
    }
    array_unshift($log, array(
        'time' => current_time('timestamp', 1),
        'ip' => tk_get_ip(),
        'reason' => $reason,
        'sample' => $sample,
    ));
    $log = array_slice($log, 0, 50);
    tk_update_option('antispam_log', $log);
}

function tk_antispam_current_rejection_key(): string {
    return 'tk_antispam_reject_' . md5(tk_get_ip() . '|' . tk_user_agent());
}

function tk_antispam_rejection_message(string $reason, string $fallback = ''): string {
    $base_reason = strtolower(trim(strtok($reason, ':')));
    $messages = array(
        'mail_guard_html_detected' => __('HTML is not allowed in this form submission.', 'tool-kits'),
        'html_detected' => __('HTML is not allowed in this form submission.', 'tool-kits'),
        'mail_guard_links_blocked' => __('Links are not allowed in this form submission.', 'tool-kits'),
        'links_blocked' => __('Links are not allowed in this form submission.', 'tool-kits'),
        'rate_limit_exceeded' => __('Too many form submissions. Please try again later.', 'tool-kits'),
        'submitted_too_quickly' => __('Form submitted too quickly. Please try again.', 'tool-kits'),
        'mail_guard_shortener_link_detected' => __('Shortened links are not allowed in this form submission.', 'tool-kits'),
        'shortener_link_detected' => __('Shortened links are not allowed in this form submission.', 'tool-kits'),
    );

    $message = isset($messages[$base_reason]) ? $messages[$base_reason] : $fallback;
    if ($message === '') {
        $message = __('Form submission blocked by security policy.', 'tool-kits');
    }

    return sprintf('%s Reason: %s', $message, $reason);
}

function tk_antispam_store_rejection(string $reason, string $message): void {
    $payload = array(
        'reason' => $reason,
        'message' => $message,
        'time' => time(),
    );

    $GLOBALS['tk_antispam_current_rejection'] = $payload;
    set_transient(tk_antispam_current_rejection_key(), $payload, MINUTE_IN_SECONDS * 5);
}

function tk_antispam_get_rejection(): array {
    if (!empty($GLOBALS['tk_antispam_current_rejection']) && is_array($GLOBALS['tk_antispam_current_rejection'])) {
        return $GLOBALS['tk_antispam_current_rejection'];
    }

    $payload = get_transient(tk_antispam_current_rejection_key());
    return is_array($payload) ? $payload : array();
}

function tk_antispam_cf7_rejection_field(array $values = array(), $tags = null): string {
    $preferred = array('your-message', 'message', 'subject', 'your-subject', 'your-email', 'email', 'your-name', 'name');
    $tag_names = array();

    if ($tags === null && function_exists('wpcf7_scan_form_tags')) {
        $tags = wpcf7_scan_form_tags();
    }

    if ($tags instanceof WPCF7_FormTag) {
        $tags = array($tags);
    }

    if (is_array($tags)) {
        foreach ($tags as $tag) {
            if ($tag instanceof WPCF7_FormTag && !empty($tag->name)) {
                $tag_names[] = (string) $tag->name;
            } elseif (is_array($tag) && !empty($tag['name'])) {
                $tag_names[] = (string) $tag['name'];
            }
        }
    }

    foreach ($preferred as $name) {
        if (in_array($name, $tag_names, true)) {
            return $name;
        }
    }

    foreach (array_keys($values) as $name) {
        if (strpos((string) $name, '_wpcf7') === 0 || strpos((string) $name, 'tk_') === 0) {
            continue;
        }
        if (function_exists('wpcf7_is_name') && !wpcf7_is_name((string) $name)) {
            continue;
        }
        if (in_array((string) $name, $tag_names, true)) {
            return (string) $name;
        }
    }

    return !empty($tag_names) ? (string) $tag_names[0] : '';
}

function tk_antispam_reject($result, string $message, string $reason, array $values = array(), $tags = null) {
    tk_antispam_log_record($reason, $values);
    $message = tk_antispam_rejection_message($reason, $message);
    tk_antispam_store_rejection($reason, $message);
    $field = tk_antispam_cf7_rejection_field($values, $tags);
    if ($field !== '') {
        $result->invalidate($field, $message);
    }
    return $result;
}

function tk_antispam_cf7_display_message($message, $status) {
    if (!tk_antispam_enabled()) {
        return $message;
    }

    if (!in_array((string) $status, array('validation_failed', 'spam', 'mail_failed', 'aborted'), true)) {
        return $message;
    }

    $rejection = tk_antispam_get_rejection();
    if (empty($rejection['message'])) {
        return $message;
    }

    return (string) $rejection['message'];
}

function tk_antispam_cf7_ajax_json_echo($response, $result) {
    if (!tk_antispam_enabled() || !is_array($response)) {
        return $response;
    }

    $rejection = tk_antispam_get_rejection();
    if (empty($rejection['message']) || empty($rejection['reason'])) {
        return $response;
    }

    $response['status'] = 'validation_failed';
    $response['message'] = (string) $rejection['message'];
    if (empty($response['invalid_fields']) || !is_array($response['invalid_fields'])) {
        $response['invalid_fields'] = array();
    }
    $response['tool_kits_reason'] = (string) $rejection['reason'];

    return $response;
}

function tk_antispam_rate_limit_key(): string {
    return 'tk_antispam_rl_' . md5(tk_get_ip());
}

function tk_antispam_rate_limit_exceeded(): bool {
    if ((int) tk_get_option('antispam_rate_limit_enabled', 1) !== 1) {
        return false;
    }
    $window = max(1, (int) tk_get_option('antispam_rate_limit_window_minutes', 15));
    $max = max(1, (int) tk_get_option('antispam_rate_limit_max_attempts', 3));
    $key = tk_antispam_rate_limit_key();
    $data = get_transient($key);
    if (!is_array($data)) {
        $data = array('count' => 0);
    }
    $data['count'] = isset($data['count']) ? (int) $data['count'] + 1 : 1;
    set_transient($key, $data, $window * MINUTE_IN_SECONDS);
    return $data['count'] > $max;
}

function tk_antispam_text_values_from_string(string $text): array {
    $values = array();
    $lines = preg_split('/\r\n|\r|\n/', $text);
    if (!is_array($lines)) {
        return $values;
    }
    foreach ($lines as $line) {
        if (!is_string($line)) {
            continue;
        }
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (strpos($line, ':') !== false) {
            list($key, $value) = array_map('trim', explode(':', $line, 2));
            if ($value !== '') {
                $values[sanitize_key($key)] = $value;
                continue;
            }
        }
        $values[] = $line;
    }
    return $values;
}

function tk_antispam_is_contact_notification_mail(array $atts): bool {
    $subject = isset($atts['subject']) ? strtolower((string) $atts['subject']) : '';
    $message = isset($atts['message']) ? strtolower((string) $atts['message']) : '';
    $markers = array(
        'contact form',
        'new contact form submission',
        'message body:',
        'this email was sent from the contact form',
        'full name',
        'phone',
    );
    foreach ($markers as $marker) {
        if (($subject !== '' && strpos($subject, $marker) !== false) || ($message !== '' && strpos($message, $marker) !== false)) {
            return true;
        }
    }
    return false;
}

function tk_antispam_mail_guard_reason(array $values): string {
    $reason = '';

    if ((int) tk_get_option('antispam_block_html', 1) === 1 && tk_antispam_contains_html($values)) {
        $reason = 'mail_guard_html_detected';
    } elseif ((int) tk_get_option('antispam_block_shorteners', 1) === 1 && tk_antispam_contains_shortener($values)) {
        $reason = 'mail_guard_shortener_link_detected';
    } elseif ((int) tk_get_option('antispam_block_links', 1) === 1 && tk_antispam_submission_link_count($values) > max(0, (int) tk_get_option('antispam_max_links', 0))) {
        $reason = 'mail_guard_links_blocked';
    } else {
        $email_domains = tk_antispam_submission_email_domains($values);
        $blocked_domains = tk_antispam_line_list((string) tk_get_option('antispam_disposable_domains', ''));
        foreach ($email_domains as $domain) {
            if (in_array($domain, $blocked_domains, true)) {
                $reason = 'mail_guard_disposable_email_domain:' . $domain;
                break;
            }
        }
    }

    if ($reason === '') {
        $haystack = strtolower(implode("\n", array_filter($values, 'is_string')));
        foreach (tk_antispam_line_list((string) tk_get_option('antispam_block_keywords', '')) as $keyword) {
            if ($keyword !== '' && strpos($haystack, strtolower($keyword)) !== false) {
                $reason = 'mail_guard_blocked_keyword:' . strtolower($keyword);
                break;
            }
        }
    }

    if ($reason === '') {
        $message_value = tk_antispam_message_value($values);
        $normalized_message = strtolower(trim(preg_replace('/\s+/', ' ', $message_value)));
        foreach (tk_antispam_line_list((string) tk_get_option('antispam_generic_phrases', '')) as $phrase) {
            $phrase = strtolower(trim($phrase));
            if ($phrase !== '' && strpos($normalized_message, $phrase) !== false) {
                $reason = 'mail_guard_generic_message_phrase';
                break;
            }
        }
    }

    if ($reason === '') {
        $reason = tk_antispam_detect_random_submission_reason($values);
    }

    return $reason;
}

function tk_antispam_cf7_before_send_mail($contact_form, &$abort = false, $submission = null): void {
    if (!tk_antispam_enabled()) {
        return;
    }

    $values = tk_antispam_scalar_post_values();
    if (empty($values)) {
        return;
    }

    $reason = tk_antispam_mail_guard_reason($values);
    if ($reason === '') {
        return;
    }

    $message = tk_antispam_rejection_message($reason);
    tk_antispam_store_rejection($reason, $message);
    tk_antispam_log_record($reason, $values);
    tk_log('Blocked suspicious contact form submission before mail: ' . $reason);

    $abort = true;
    if (is_object($submission) && method_exists($submission, 'set_response')) {
        $submission->set_response($message);
    }
}

function tk_antispam_pre_wp_mail($return, array $atts) {
    if (!tk_antispam_enabled()) {
        return $return;
    }
    if (!tk_antispam_is_contact_notification_mail($atts)) {
        return $return;
    }

    $message = isset($atts['message']) && is_string($atts['message']) ? $atts['message'] : '';
    if ($message === '') {
        return $return;
    }

    $submitted_values = tk_antispam_scalar_post_values();
    $values = !empty($submitted_values)
        ? $submitted_values
        : tk_antispam_text_values_from_string(wp_strip_all_tags($message));
    $reason = tk_antispam_mail_guard_reason($values);

    if ($reason !== '') {
        $message = tk_antispam_rejection_message($reason);
        tk_antispam_store_rejection($reason, $message);
        tk_antispam_log_record($reason, $values);
        tk_log('Blocked suspicious contact notification email: ' . $reason);
        return false;
    }

    tk_antispam_register_submission($values);
    return $return;
}

function tk_cf7_add_honeypot_and_time($form) {
    if (!tk_antispam_enabled()) return $form;
    if (!function_exists('wpcf7')) return $form;

    $ts = time();
    set_transient(tk_antispam_key(), $ts, 30 * MINUTE_IN_SECONDS);

    $honeypot = '<span class="tk-hp" style="display:none !important; visibility:hidden !important; position:absolute !important; left:-9999px !important; top:-9999px !important; height:1px !important; width:1px !important; overflow:hidden !important;" aria-hidden="true">'
        . '<label>Leave this field empty<input type="text" name="tk_hp_field" value="" tabindex="-1" autocomplete="off" style="display:none !important;"></label>'
        . '</span>';

    $timefield = '<input type="hidden" name="tk_form_ts" value="' . esc_attr($ts) . '">';

    return $form . $honeypot . $timefield;
}

function tk_cf7_validate_honeypot_and_time($result, $tags) {
    if (!tk_antispam_enabled()) return $result;
    if (!function_exists('wpcf7')) return $result;

    $hp = isset($_POST['tk_hp_field']) ? trim(wp_unslash($_POST['tk_hp_field'])) : '';
    if ($hp !== '') {
        return tk_antispam_reject($result, __('Spam detected.', 'tool-kits'), 'honeypot_filled', array(), $tags);
    }

    $min = (int) tk_get_option('antispam_min_seconds', 3);
    $posted = isset($_POST['tk_form_ts']) ? (int) $_POST['tk_form_ts'] : 0;
    $stored = (int) get_transient(tk_antispam_key());

    // If no timestamp, be strict but still allow (some caching might strip fields); we only enforce if both present.
    if ($posted > 0 && $stored > 0) {
        $elapsed = time() - $stored;
        if ($elapsed < $min) {
            return tk_antispam_reject($result, __('Form submitted too quickly. Please try again.', 'tool-kits'), 'submitted_too_quickly', array(), $tags);
        }
    }

    $values = tk_antispam_scalar_post_values();
    if (empty($values)) {
        return $result;
    }

    if (tk_antispam_rate_limit_exceeded()) {
        return tk_antispam_reject($result, __('Too many form submissions. Please try again later.', 'tool-kits'), 'rate_limit_exceeded', $values, $tags);
    }

    $message = tk_antispam_message_value($values);
    $min_chars = max(0, (int) tk_get_option('antispam_message_min_chars', 12));
    if ($min_chars > 0 && $message !== '' && function_exists('mb_strlen') && mb_strlen($message) < $min_chars) {
        return tk_antispam_reject($result, __('Message is too short.', 'tool-kits'), 'message_too_short', $values, $tags);
    } elseif ($min_chars > 0 && $message !== '' && strlen($message) < $min_chars) {
        return tk_antispam_reject($result, __('Message is too short.', 'tool-kits'), 'message_too_short', $values, $tags);
    }

    if ((int) tk_get_option('antispam_block_html', 1) === 1 && tk_antispam_contains_html($values)) {
        return tk_antispam_reject($result, __('HTML is not allowed in this form submission.', 'tool-kits'), 'html_detected', $values, $tags);
    }

    if ((int) tk_get_option('antispam_block_shorteners', 1) === 1 && tk_antispam_contains_shortener($values)) {
        return tk_antispam_reject($result, __('Shortened links are not allowed in this form submission.', 'tool-kits'), 'shortener_link_detected', $values, $tags);
    }

    $generic_phrases = tk_antispam_line_list((string) tk_get_option('antispam_generic_phrases', ''));
    if ($message !== '' && !empty($generic_phrases)) {
        $normalized_message = strtolower(trim(preg_replace('/\s+/', ' ', $message)));
        foreach ($generic_phrases as $phrase) {
            $phrase = strtolower(trim($phrase));
            if ($phrase !== '' && strpos($normalized_message, $phrase) !== false) {
                return tk_antispam_reject($result, __('Spam-like content detected.', 'tool-kits'), 'generic_message_phrase', $values, $tags);
            }
        }
    }

    $link_count = tk_antispam_submission_link_count($values);
    if ((int) tk_get_option('antispam_block_links', 1) === 1 && $link_count > 0) {
        $max_links = max(0, (int) tk_get_option('antispam_max_links', 0));
        if ($link_count > $max_links) {
            return tk_antispam_reject($result, __('Links are not allowed in this form submission.', 'tool-kits'), 'links_blocked', $values, $tags);
        }
    }

    if ((int) tk_get_option('antispam_block_disposable_email', 1) === 1) {
        $blocked_domains = tk_antispam_line_list((string) tk_get_option('antispam_disposable_domains', ''));
        $email_domains = tk_antispam_submission_email_domains($values);
        foreach ($email_domains as $domain) {
            if (in_array($domain, $blocked_domains, true)) {
                return tk_antispam_reject($result, __('Disposable email addresses are not allowed.', 'tool-kits'), 'disposable_email_domain:' . $domain, $values, $tags);
            }
        }
    }

    $keywords = tk_antispam_line_list((string) tk_get_option('antispam_block_keywords', ''));
    if (!empty($keywords)) {
        $haystack = strtolower(implode("\n", array_filter($values, 'is_string')));
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && strpos($haystack, strtolower($keyword)) !== false) {
                return tk_antispam_reject($result, __('Spam-like content detected.', 'tool-kits'), 'blocked_keyword:' . strtolower($keyword), $values, $tags);
            }
        }
    }

    $random_reason = tk_antispam_detect_random_submission_reason($values);
    if ($random_reason !== '') {
        return tk_antispam_reject($result, __('Spam-like content detected.', 'tool-kits'), $random_reason, $values, $tags);
    }

    $replay_reason = tk_antispam_replay_reason($values);
    if ($replay_reason !== '') {
        return tk_antispam_reject($result, __('Please wait before sending another message.', 'tool-kits'), $replay_reason, $values, $tags);
    }

    tk_antispam_register_submission($values);

    return $result;
}

function tk_render_antispam_contact_page() {
    if (function_exists('tk_render_spam_protection_page')) {
        tk_render_spam_protection_page('antispam');
        return;
    }
    if (!tk_is_admin_user()) return;
    ?>
    <div class="wrap tk-wrap">
        <h1>Spam Protection</h1>
        <?php tk_render_antispam_contact_panel(); ?>
    </div>
    <?php
}

function tk_render_antispam_contact_panel() {
    if (!tk_is_admin_user()) return;

    $enabled = (int) tk_get_option('antispam_cf7_enabled', 0);
    $min_seconds = (int) tk_get_option('antispam_min_seconds', 5);
    $block_links = (int) tk_get_option('antispam_block_links', 1);
    $max_links = (int) tk_get_option('antispam_max_links', 0);
    $block_disposable = (int) tk_get_option('antispam_block_disposable_email', 1);
    $disposable_domains = (string) tk_get_option('antispam_disposable_domains', "mailinator.com\ntrashmail.com\ntempmail.com\ntemp-mail.org\n10minutemail.com\nguerrillamail.com\nwildbmail.com\nyopmail.com\nsharklasers.com");
    $block_keywords = (string) tk_get_option('antispam_block_keywords', "crypto\nbitcoin\nforex\nseo service\nguest post\nbacklink\ncasino\nloan\nhref=\n<a \nis.gd\nbit.ly\ntinyurl.com\ncutt.ly\nfuck\nsex");
    $message_min_chars = (int) tk_get_option('antispam_message_min_chars', 20);
    $generic_phrases = (string) tk_get_option('antispam_generic_phrases', "hi\nhello\ncheck this\nsee it here\nsee here\nclick here\nhave a peek here\ncontact me\nwhatsapp me\ngood day\nhow are you\ni have a question\ntest");
    $block_html = (int) tk_get_option('antispam_block_html', 1);
    $block_shorteners = (int) tk_get_option('antispam_block_shorteners', 1);
    $duplicate_window = (int) tk_get_option('antispam_duplicate_window_minutes', 5);
    $email_cooldown = (int) tk_get_option('antispam_email_cooldown_minutes', 15);
    $ip_cooldown = (int) tk_get_option('antispam_ip_cooldown_seconds', 60);
    $rate_limit_enabled = (int) tk_get_option('antispam_rate_limit_enabled', 1);
    $rate_limit_window = (int) tk_get_option('antispam_rate_limit_window_minutes', 15);
    $rate_limit_max = (int) tk_get_option('antispam_rate_limit_max_attempts', 2);
    $cf7_installed = function_exists('wpcf7');

    ?>
    <div class="tk-card">
        <h2>Anti-spam Contact</h2>
        <p>This module adds a <strong>honeypot</strong>, <strong>minimum submit time</strong>, and content rules to Contact Form 7 submissions.</p>
        <p>CF7 status: <?php echo $cf7_installed ? '<span class="tk-badge tk-on">Detected</span>' : '<span class="tk-badge">Not Installed</span>'; ?></p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_antispam_save'); ?>
            <input type="hidden" name="action" value="tk_antispam_save">
            <input type="hidden" name="tk_tab" value="antispam">

            <div style="margin-bottom:24px;">
                <?php tk_render_switch('enabled', 'Enable Contact Form 7 Protection', 'Apply honeypot and content rules to all CF7 forms.', $enabled); ?>
            </div>

            <div class="tk-grid tk-grid-2" style="gap:24px;">
                <!-- Timing & Velocity -->
                <div style="background:var(--tk-bg-soft); padding:20px; border-radius:16px; border:1px solid var(--tk-border-soft);">
                    <h4 style="margin-top:0; color:var(--tk-primary); font-size:14px; margin-bottom:16px;">Timing & Velocity</h4>
                    <div style="display:flex; flex-direction:column; gap:16px;">
                        <div class="tk-control-row">
                            <div class="tk-control-info">
                                <label>Min Submit Time</label>
                                <p class="description">Seconds required to fill the form.</p>
                            </div>
                            <input class="small-text" type="number" min="0" name="min_seconds" value="<?php echo esc_attr($min_seconds); ?>">
                        </div>
                        <div class="tk-control-row">
                            <div class="tk-control-info">
                                <label>Duplicate Window</label>
                                <p class="description">Minutes to block identical posts.</p>
                            </div>
                            <input class="small-text" type="number" min="0" name="duplicate_window_minutes" value="<?php echo esc_attr($duplicate_window); ?>">
                        </div>
                        <?php tk_render_switch('rate_limit_enabled', 'IP Rate Limiting', 'Throttle multiple submissions from one IP.', $rate_limit_enabled); ?>
                        <div class="tk-control-row" style="margin-left:26px;">
                            <div class="tk-control-info"><label>Max Attempts</label></div>
                            <input class="small-text" type="number" min="1" name="rate_limit_max_attempts" value="<?php echo esc_attr($rate_limit_max); ?>">
                        </div>
                    </div>
                </div>

                <!-- Content Rules -->
                <div style="background:var(--tk-bg-soft); padding:20px; border-radius:16px; border:1px solid var(--tk-border-soft);">
                    <h4 style="margin-top:0; color:var(--tk-primary); font-size:14px; margin-bottom:16px;">Content Rules</h4>
                    <div style="display:flex; flex-direction:column; gap:16px;">
                        <?php 
                        tk_render_switch('block_links', 'Strict Link Blocking', 'Reject any submission containing URLs.', $block_links);
                        tk_render_switch('block_html', 'Block HTML Tags', 'Prevent code injection in textareas.', $block_html);
                        tk_render_switch('block_shorteners', 'Block URL Shorteners', 'Reject bit.ly, tinyurl, and others.', $block_shorteners);
                        tk_render_switch('block_disposable_email', 'Block Disposable Emails', 'Reject mailinator, trashmail, etc.', $block_disposable);
                        ?>
                        <div class="tk-control-row">
                            <div class="tk-control-info">
                                <label>Min Message Length</label>
                                <p class="description">Characters required to submit.</p>
                            </div>
                            <input class="small-text" type="number" min="0" name="message_min_chars" value="<?php echo esc_attr($message_min_chars); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div style="margin-top:24px; display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                <div style="padding:20px; background:var(--tk-bg-soft); border-radius:12px; border:1px solid var(--tk-border-soft);">
                    <label style="display:block; font-weight:600; margin-bottom:8px;">Blocked Keywords</label>
                    <textarea class="large-text" rows="5" name="block_keywords" style="width:100%; border-radius:8px;"><?php echo esc_textarea($block_keywords); ?></textarea>
                </div>
                <div style="padding:20px; background:var(--tk-bg-soft); border-radius:12px; border:1px solid var(--tk-border-soft);">
                    <label style="display:block; font-weight:600; margin-bottom:8px;">Generic Spam Phrases</label>
                    <textarea class="large-text" rows="5" name="generic_phrases" style="width:100%; border-radius:8px;"><?php echo esc_textarea($generic_phrases); ?></textarea>
                </div>
            </div>

            <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                <button class="button button-primary button-hero">Save Anti-spam Settings</button>
            </div>
        </form>

	        <p class="description">If CF7 is not installed, the module stays passive and safe.</p>
	    </div>
	    <?php tk_render_antispam_block_notes(); ?>
	    <?php
	}

function tk_render_antispam_block_notes(): void {
    $min_seconds = max(0, (int) tk_get_option('antispam_min_seconds', 5));
    $min_minutes = $min_seconds > 0 ? round($min_seconds / 60, 2) : 0;
    $message_min_chars = max(0, (int) tk_get_option('antispam_message_min_chars', 20));
    $message_min_words = $message_min_chars > 0 ? max(1, (int) ceil($message_min_chars / 5)) : 0;
    $max_links = max(0, (int) tk_get_option('antispam_max_links', 0));
    $rate_limit_window = max(1, (int) tk_get_option('antispam_rate_limit_window_minutes', 15));
    $rate_limit_max = max(1, (int) tk_get_option('antispam_rate_limit_max_attempts', 2));
    $duplicate_window = max(0, (int) tk_get_option('antispam_duplicate_window_minutes', 5));
    $email_cooldown = max(0, (int) tk_get_option('antispam_email_cooldown_minutes', 15));
    $ip_cooldown = max(0, (int) tk_get_option('antispam_ip_cooldown_seconds', 60));
    $ip_cooldown_minutes = $ip_cooldown > 0 ? round($ip_cooldown / 60, 2) : 0;
    $current_host = tk_antispam_current_host();
    $shorteners = implode(', ', tk_antispam_shortener_domains());
    $notes = array(
        array(
            'type' => 'Honeypot',
            'reasons' => 'honeypot_filled',
            'cause' => 'Hidden field is filled. Usually bot/autofill touched a field real visitors cannot see.',
            'response' => 'Spam detected.',
        ),
        array(
            'type' => 'Submit too quickly',
            'reasons' => 'submitted_too_quickly',
            'cause' => 'Submission happened before minimum submit time: ' . $min_seconds . ' seconds' . ($min_minutes > 0 ? ' (' . $min_minutes . ' minutes)' : '') . '.',
            'response' => 'Form submitted too quickly. Please try again.',
        ),
        array(
            'type' => 'Rate limit',
            'reasons' => 'rate_limit_exceeded',
            'cause' => 'Same IP submitted more than ' . $rate_limit_max . ' times within ' . $rate_limit_window . ' minutes.',
            'response' => 'Too many form submissions. Please try again later.',
        ),
        array(
            'type' => 'Message length',
            'reasons' => 'message_too_short',
            'cause' => 'Message field is shorter than ' . $message_min_chars . ' characters, roughly ' . $message_min_words . ' words.',
            'response' => 'Message is too short.',
        ),
        array(
            'type' => 'HTML content',
            'reasons' => 'html_detected, mail_guard_html_detected',
            'cause' => 'Submitted fields or generated mail body contain HTML tags, such as anchor/script markup.',
            'response' => 'HTML is not allowed in this form submission.',
        ),
        array(
            'type' => 'Shortened links',
            'reasons' => 'shortener_link_detected, mail_guard_shortener_link_detected',
            'cause' => 'Submission contains known URL shortener domains: ' . $shorteners . '.',
            'response' => 'Shortened links are not allowed in this form submission.',
        ),
        array(
            'type' => 'Links blocked',
            'reasons' => 'links_blocked, mail_guard_links_blocked',
            'cause' => 'Detected external link count is higher than Allowed links per message (' . $max_links . '). Links to the current domain' . ($current_host !== '' ? ' (' . $current_host . ')' : '') . ' are allowed.',
            'response' => 'Links are not allowed in this form submission.',
        ),
        array(
            'type' => 'Disposable email',
            'reasons' => 'disposable_email_domain:domain, mail_guard_disposable_email_domain:domain',
            'cause' => 'Email domain matches the disposable email domains list.',
            'response' => 'Disposable email addresses are not allowed.',
        ),
        array(
            'type' => 'Blocked keyword/domain',
            'reasons' => 'blocked_keyword:keyword, mail_guard_blocked_keyword:keyword',
            'cause' => 'Submitted text or generated mail body contains a configured blocked keyword/domain.',
            'response' => 'Spam-like content detected.',
        ),
        array(
            'type' => 'Generic spam phrase',
            'reasons' => 'generic_message_phrase, mail_guard_generic_message_phrase',
            'cause' => 'Message contains configured generic spam phrases such as short throwaway phrases.',
            'response' => 'Spam-like content detected.',
        ),
        array(
            'type' => 'Random-looking content',
            'reasons' => 'randomized_submission_pattern, suspicious_email_local_part',
            'cause' => 'Name, message, or email local part looks machine-generated.',
            'response' => 'Spam-like content detected.',
        ),
        array(
            'type' => 'Replay / cooldown',
            'reasons' => 'duplicate_submission, email_cooldown:email, ip_cooldown_active',
            'cause' => 'Same submission is blocked for ' . $duplicate_window . ' minutes, same email for ' . $email_cooldown . ' minutes, and same IP for ' . $ip_cooldown . ' seconds' . ($ip_cooldown_minutes > 0 ? ' (' . $ip_cooldown_minutes . ' minutes)' : '') . '.',
            'response' => 'Please wait before sending another message.',
        ),
        array(
            'type' => 'Mail guard',
            'reasons' => 'mail_guard_*',
            'cause' => 'Submission passed CF7 validation but the outgoing notification email body still matched spam rules before wp_mail sent it.',
            'response' => 'Shown in CF7 response output with the exact mail_guard reason.',
        ),
    );
    ?>
    <div class="tk-card">
        <h2>Block Reason Notes</h2>
        <p class="description">These notes map anti-spam log reasons and Contact Form 7 response messages to the setting that caused the block.</p>
        <table class="widefat striped tk-table">
            <thead>
                <tr>
                    <th style="width:18%;">Type</th>
                    <th style="width:26%;">Reason code</th>
                    <th>What causes it</th>
                    <th style="width:24%;">Visitor response</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($notes as $note) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($note['type']); ?></strong></td>
                        <td><code><?php echo esc_html($note['reasons']); ?></code></td>
                        <td><?php echo esc_html($note['cause']); ?></td>
                        <td><?php echo esc_html($note['response']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function tk_render_antispam_log_panel() {
    if (!tk_is_admin_user()) return;
    $antispam_log = tk_antispam_log_get();
    ?>
    <div class="tk-card">
        <h2>Recent Anti-spam Log</h2>
        <p class="description">Blocked submissions and mail-guard rejections are recorded here.</p>
        <?php if (!empty($antispam_log)) : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>IP</th>
                        <th>Reason</th>
                        <th>Sample</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($antispam_log as $entry) : ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n('Y-m-d H:i', (int) ($entry['time'] ?? 0))); ?></td>
                            <td><code><?php echo esc_html((string) ($entry['ip'] ?? '')); ?></code></td>
                            <td><span class="tk-badge"><?php echo esc_html((string) ($entry['reason'] ?? '')); ?></span></td>
                            <td>
                                <div class="tk-log-details">
                                    <?php 
                                    $sample = $entry['sample'] ?? array();
                                    if (!empty($sample) && is_array($sample)) : ?>
                                        <table class="tk-mini-table" style="width:100%; border-collapse:collapse; font-size:11px;">
                                            <?php foreach ($sample as $k => $v) : ?>
                                                <tr>
                                                    <td style="font-weight:700; width:100px; padding:4px 0; color:#1e293b;"><?php echo esc_html($k); ?>:</td>
                                                    <td style="padding:4px 0; color:#64748b;"><?php echo nl2br(esc_html($v)); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </table>
                                    <?php else : ?>
                                        &mdash;
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                <?php tk_nonce_field('tk_antispam_log_clear'); ?>
                <input type="hidden" name="action" value="tk_antispam_log_clear">
                <button class="button button-secondary">Clear log</button>
            </form>
        <?php else : ?>
            <p class="description">No anti-spam log entries yet.</p>
        <?php endif; ?>
    </div>
    <?php
}

function tk_antispam_save_settings() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    tk_check_nonce('tk_antispam_save');

    tk_update_option('antispam_cf7_enabled', !empty($_POST['enabled']) ? 1 : 0);
    tk_update_option('antispam_min_seconds', max(0, (int) tk_post('min_seconds', 5)));
    tk_update_option('antispam_block_links', !empty($_POST['block_links']) ? 1 : 0);
    tk_update_option('antispam_max_links', max(0, (int) tk_post('max_links', 0)));
    tk_update_option('antispam_block_disposable_email', !empty($_POST['block_disposable_email']) ? 1 : 0);
    $disposable_domains = isset($_POST['disposable_domains']) ? trim((string) wp_unslash($_POST['disposable_domains'])) : '';
    tk_update_option('antispam_disposable_domains', $disposable_domains);
    $block_keywords = isset($_POST['block_keywords']) ? trim((string) wp_unslash($_POST['block_keywords'])) : '';
    tk_update_option('antispam_block_keywords', $block_keywords);
    tk_update_option('antispam_message_min_chars', max(0, (int) tk_post('message_min_chars', 20)));
    tk_update_option('antispam_duplicate_window_minutes', max(0, (int) tk_post('duplicate_window_minutes', 5)));
    tk_update_option('antispam_email_cooldown_minutes', max(0, (int) tk_post('email_cooldown_minutes', 15)));
    tk_update_option('antispam_ip_cooldown_seconds', max(0, (int) tk_post('ip_cooldown_seconds', 60)));
    $generic_phrases = isset($_POST['generic_phrases']) ? trim((string) wp_unslash($_POST['generic_phrases'])) : '';
    tk_update_option('antispam_generic_phrases', $generic_phrases);
    tk_update_option('antispam_block_html', !empty($_POST['block_html']) ? 1 : 0);
    tk_update_option('antispam_block_shorteners', !empty($_POST['block_shorteners']) ? 1 : 0);
    tk_update_option('antispam_rate_limit_enabled', !empty($_POST['rate_limit_enabled']) ? 1 : 0);
    tk_update_option('antispam_rate_limit_window_minutes', max(1, (int) tk_post('rate_limit_window_minutes', 15)));
    tk_update_option('antispam_rate_limit_max_attempts', max(1, (int) tk_post('rate_limit_max_attempts', 2)));

    wp_redirect(add_query_arg(array('page'=>'tool-kits-security-spam','tk_tab'=>'antispam','tk_saved'=>1), admin_url('admin.php')));
    exit;
}

function tk_antispam_log_clear() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    tk_check_nonce('tk_antispam_log_clear');
    tk_update_option('antispam_log', array());
    wp_redirect(add_query_arg(array('page'=>'tool-kits-security-spam','tk_tab'=>'antispam-log','tk_cleared'=>1), admin_url('admin.php')));
    exit;
}
