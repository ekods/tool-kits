<?php
if (!defined('ABSPATH')) { exit; }

function tk_antispam_contact_init() {
    add_action('admin_post_tk_antispam_save', 'tk_antispam_save_settings');
    add_action('admin_post_tk_antispam_log_clear', 'tk_antispam_log_clear');

    // Contact Form 7 integration (optional, only active if CF7 present + enabled)
    add_filter('wpcf7_form_elements', 'tk_cf7_add_honeypot_and_time', 20, 1);
    add_filter('wpcf7_validate', 'tk_cf7_validate_honeypot_and_time', 20, 2);
    add_filter('wpcf7_validate_text', 'tk_cf7_validate_honeypot_and_time', 20, 2);
    add_filter('wpcf7_validate_email', 'tk_cf7_validate_honeypot_and_time', 20, 2);
    add_filter('pre_wp_mail', 'tk_antispam_pre_wp_mail', 20, 2);
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

function tk_antispam_submission_link_count(array $values): int {
    $count = 0;
    foreach ($values as $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }
        if (preg_match_all('#https?://#i', $value, $matches) === 1 && !empty($matches[0])) {
            $count += count($matches[0]);
        }
        if (preg_match_all('/\bwww\./i', $value, $matches) === 1 && !empty($matches[0])) {
            $count += count($matches[0]);
        }
        if (preg_match_all('/\bhref\s*=/i', $value, $matches) === 1 && !empty($matches[0])) {
            $count += count($matches[0]);
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

    $duplicate_window = max(0, (int) tk_get_option('antispam_duplicate_window_minutes', 30));
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
    $duplicate_window = max(0, (int) tk_get_option('antispam_duplicate_window_minutes', 30));
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

function tk_antispam_contains_shortener(array $values): bool {
    $domains = array('bit.ly', 'tinyurl.com', 'is.gd', 'cutt.ly', 't.co', 'rb.gy', 'rebrand.ly', 'goo.su');
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
    $sample = array();
    foreach ($values as $key => $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }
        $sample[$key] = mb_substr($value, 0, 120);
        if (count($sample) >= 5) {
            break;
        }
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

function tk_antispam_reject($result, string $message, string $reason, array $values = array()) {
    tk_antispam_log_record($reason, $values);
    $result->invalidate(null, $message);
    return $result;
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

    $values = tk_antispam_text_values_from_string(wp_strip_all_tags($message) . "\n" . $message);
    $reason = '';

    if ((int) tk_get_option('antispam_block_html', 1) === 1 && $message !== wp_strip_all_tags($message)) {
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

    if ($reason === '') {
        $reason = tk_antispam_replay_reason($values);
    }

    if ($reason !== '') {
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

    $honeypot = '<span class="tk-hp" style="position:absolute;left:-9999px;top:-9999px;height:1px;overflow:hidden;" aria-hidden="true">'
        . '<label>Leave this field empty<input type="text" name="tk_hp_field" value="" tabindex="-1" autocomplete="off"></label>'
        . '</span>';

    $timefield = '<input type="hidden" name="tk_form_ts" value="' . esc_attr($ts) . '">';

    return $form . $honeypot . $timefield;
}

function tk_cf7_validate_honeypot_and_time($result, $tags) {
    if (!tk_antispam_enabled()) return $result;
    if (!function_exists('wpcf7')) return $result;

    $hp = isset($_POST['tk_hp_field']) ? trim(wp_unslash($_POST['tk_hp_field'])) : '';
    if ($hp !== '') {
        return tk_antispam_reject($result, __('Spam detected.', 'tool-kits'), 'honeypot_filled');
    }

    $min = (int) tk_get_option('antispam_min_seconds', 3);
    $posted = isset($_POST['tk_form_ts']) ? (int) $_POST['tk_form_ts'] : 0;
    $stored = (int) get_transient(tk_antispam_key());

    // If no timestamp, be strict but still allow (some caching might strip fields); we only enforce if both present.
    if ($posted > 0 && $stored > 0) {
        $elapsed = time() - $stored;
        if ($elapsed < $min) {
            return tk_antispam_reject($result, __('Form submitted too quickly. Please try again.', 'tool-kits'), 'submitted_too_quickly');
        }
    }

    $values = tk_antispam_scalar_post_values();
    if (empty($values)) {
        return $result;
    }

    if (tk_antispam_rate_limit_exceeded()) {
        return tk_antispam_reject($result, __('Too many form submissions. Please try again later.', 'tool-kits'), 'rate_limit_exceeded', $values);
    }

    $message = tk_antispam_message_value($values);
    $min_chars = max(0, (int) tk_get_option('antispam_message_min_chars', 12));
    if ($min_chars > 0 && $message !== '' && function_exists('mb_strlen') && mb_strlen($message) < $min_chars) {
        return tk_antispam_reject($result, __('Message is too short.', 'tool-kits'), 'message_too_short', $values);
    } elseif ($min_chars > 0 && $message !== '' && strlen($message) < $min_chars) {
        return tk_antispam_reject($result, __('Message is too short.', 'tool-kits'), 'message_too_short', $values);
    }

    if ((int) tk_get_option('antispam_block_html', 1) === 1 && tk_antispam_contains_html($values)) {
        return tk_antispam_reject($result, __('HTML is not allowed in this form submission.', 'tool-kits'), 'html_detected', $values);
    }

    if ((int) tk_get_option('antispam_block_shorteners', 1) === 1 && tk_antispam_contains_shortener($values)) {
        return tk_antispam_reject($result, __('Shortened links are not allowed in this form submission.', 'tool-kits'), 'shortener_link_detected', $values);
    }

    $generic_phrases = tk_antispam_line_list((string) tk_get_option('antispam_generic_phrases', ''));
    if ($message !== '' && !empty($generic_phrases)) {
        $normalized_message = strtolower(trim(preg_replace('/\s+/', ' ', $message)));
        foreach ($generic_phrases as $phrase) {
            $phrase = strtolower(trim($phrase));
            if ($phrase !== '' && strpos($normalized_message, $phrase) !== false) {
                return tk_antispam_reject($result, __('Spam-like content detected.', 'tool-kits'), 'generic_message_phrase', $values);
            }
        }
    }

    $link_count = tk_antispam_submission_link_count($values);
    if ((int) tk_get_option('antispam_block_links', 1) === 1 && $link_count > 0) {
        $max_links = max(0, (int) tk_get_option('antispam_max_links', 0));
        if ($link_count > $max_links) {
            return tk_antispam_reject($result, __('Links are not allowed in this form submission.', 'tool-kits'), 'links_blocked', $values);
        }
    }

    if ((int) tk_get_option('antispam_block_disposable_email', 1) === 1) {
        $blocked_domains = tk_antispam_line_list((string) tk_get_option('antispam_disposable_domains', ''));
        $email_domains = tk_antispam_submission_email_domains($values);
        foreach ($email_domains as $domain) {
            if (in_array($domain, $blocked_domains, true)) {
                return tk_antispam_reject($result, __('Disposable email addresses are not allowed.', 'tool-kits'), 'disposable_email_domain:' . $domain, $values);
            }
        }
    }

    $keywords = tk_antispam_line_list((string) tk_get_option('antispam_block_keywords', ''));
    if (!empty($keywords)) {
        $haystack = strtolower(implode("\n", array_filter($values, 'is_string')));
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && strpos($haystack, strtolower($keyword)) !== false) {
                return tk_antispam_reject($result, __('Spam-like content detected.', 'tool-kits'), 'blocked_keyword:' . strtolower($keyword), $values);
            }
        }
    }

    $random_reason = tk_antispam_detect_random_submission_reason($values);
    if ($random_reason !== '') {
        return tk_antispam_reject($result, __('Spam-like content detected.', 'tool-kits'), $random_reason, $values);
    }

    $replay_reason = tk_antispam_replay_reason($values);
    if ($replay_reason !== '') {
        return tk_antispam_reject($result, __('Please wait before sending another message.', 'tool-kits'), $replay_reason, $values);
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
    $duplicate_window = (int) tk_get_option('antispam_duplicate_window_minutes', 30);
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

            <label><input type="checkbox" name="enabled" value="1" <?php checked(1, $enabled); ?>> Enable for Contact Form 7</label>

            <label><strong>Minimum seconds before submit</strong></label>
            <input class="small-text" type="number" min="0" name="min_seconds" value="<?php echo esc_attr($min_seconds); ?>"> seconds

            <p><label><input type="checkbox" name="block_links" value="1" <?php checked(1, $block_links); ?>> Block links in submissions</label></p>

            <label><strong>Allowed links per message</strong></label>
            <input class="small-text" type="number" min="0" name="max_links" value="<?php echo esc_attr($max_links); ?>">

            <p><label><input type="checkbox" name="block_disposable_email" value="1" <?php checked(1, $block_disposable); ?>> Block disposable email domains</label></p>

            <label><strong>Disposable email domains (one per line)</strong></label>
            <textarea class="large-text" rows="6" name="disposable_domains"><?php echo esc_textarea($disposable_domains); ?></textarea>

            <label><strong>Blocked keywords/domains (one per line)</strong></label>
            <textarea class="large-text" rows="6" name="block_keywords"><?php echo esc_textarea($block_keywords); ?></textarea>

            <label><strong>Minimum message length</strong></label>
            <input class="small-text" type="number" min="0" name="message_min_chars" value="<?php echo esc_attr($message_min_chars); ?>"> characters

            <label><strong>Duplicate submission window</strong></label>
            <input class="small-text" type="number" min="0" name="duplicate_window_minutes" value="<?php echo esc_attr($duplicate_window); ?>"> minutes

            <label><strong>Email cooldown</strong></label>
            <input class="small-text" type="number" min="0" name="email_cooldown_minutes" value="<?php echo esc_attr($email_cooldown); ?>"> minutes

            <label><strong>IP cooldown</strong></label>
            <input class="small-text" type="number" min="0" name="ip_cooldown_seconds" value="<?php echo esc_attr($ip_cooldown); ?>"> seconds

            <label><strong>Generic spam phrases (one per line)</strong></label>
            <textarea class="large-text" rows="5" name="generic_phrases"><?php echo esc_textarea($generic_phrases); ?></textarea>

            <p><label><input type="checkbox" name="block_html" value="1" <?php checked(1, $block_html); ?>> Block HTML in submissions</label></p>

            <p><label><input type="checkbox" name="block_shorteners" value="1" <?php checked(1, $block_shorteners); ?>> Block shortened links</label></p>

            <p><label><input type="checkbox" name="rate_limit_enabled" value="1" <?php checked(1, $rate_limit_enabled); ?>> Enable per-IP rate limit</label></p>

            <label><strong>Rate limit window</strong></label>
            <input class="small-text" type="number" min="1" name="rate_limit_window_minutes" value="<?php echo esc_attr($rate_limit_window); ?>"> minutes

            <label><strong>Max attempts per window</strong></label>
            <input class="small-text" type="number" min="1" name="rate_limit_max_attempts" value="<?php echo esc_attr($rate_limit_max); ?>">

            <p><button class="button button-primary">Save</button></p>
        </form>

        <p class="description">If CF7 is not installed, the module stays passive and safe.</p>
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
                            <td><?php echo esc_html((string) ($entry['ip'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($entry['reason'] ?? '')); ?></td>
                            <td><?php echo esc_html(wp_json_encode($entry['sample'] ?? array())); ?></td>
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
    tk_update_option('antispam_duplicate_window_minutes', max(0, (int) tk_post('duplicate_window_minutes', 30)));
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
