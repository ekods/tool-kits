<?php
if (!defined('ABSPATH')) { exit; }

function tk_health_center_policy_defaults(): array {
    return array('ttfb_warning' => 0.8, 'ttfb_critical' => 1.8, 'queries_warning' => 150, 'queries_critical' => 300, 'memory_warning' => 256, 'memory_critical' => 128, 'object_cache_required' => false);
}

/** Pure validation: diagnostic thresholds never change PHP, cache, or security settings. */
function tk_health_center_validate_policy(array $input): array {
    $ranges = array('ttfb_warning' => array(0.1, 15, false), 'ttfb_critical' => array(0.2, 20, false), 'queries_warning' => array(1, 100000, true), 'queries_critical' => array(2, 100000, true), 'memory_warning' => array(32, 16384, true), 'memory_critical' => array(16, 16383, true));
    $policy = array();
    foreach ($ranges as $key => $range) {
        $value = $input[$key] ?? null;
        if (!is_scalar($value)) { throw new InvalidArgumentException('Enter a valid value for ' . str_replace('_', ' ', $key) . '.'); }
        $value = filter_var($value, $range[2] ? FILTER_VALIDATE_INT : FILTER_VALIDATE_FLOAT);
        if ($value === false || $value < $range[0] || $value > $range[1]) { throw new InvalidArgumentException(str_replace('_', ' ', ucfirst($key)) . ' must be between ' . $range[0] . ' and ' . $range[1] . '.'); }
        $policy[$key] = $value;
    }
    if ($policy['ttfb_warning'] >= $policy['ttfb_critical'] || $policy['queries_warning'] >= $policy['queries_critical'] || $policy['memory_critical'] >= $policy['memory_warning']) {
        throw new InvalidArgumentException('Critical thresholds must be worse than warning thresholds: higher for TTFB/queries, lower for memory.');
    }
    $required = $input['object_cache_required'] ?? false;
    if (!in_array($required, array(false, true, 0, 1, '0', '1'), true)) { throw new InvalidArgumentException('Choose whether persistent object cache is required.'); }
    $policy['object_cache_required'] = in_array($required, array(true, 1, '1'), true);
    return $policy;
}

function tk_health_center_policy(): array {
    $saved = get_option('tk_health_center_settings', array());
    try { return tk_health_center_validate_policy(array_merge(tk_health_center_policy_defaults(), is_array($saved) ? $saved : array())); }
    catch (InvalidArgumentException $error) { return tk_health_center_policy_defaults(); }
}

function tk_health_check_id(string $label): string {
    return trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $label)), '-');
}

/** Fixed targets only: no client-supplied URL, callback, or PHP function is executed. */
function tk_health_center_check_group(string $id): string {
    $groups = array(
        'local' => array('PHP2Shell runtime patch', 'PHP memory limit', 'Persistent object cache', 'HTTPS homepage', 'Public debug output', 'Dashboard file editor', 'Overdue cron events', 'Recorded failed jobs', 'Plugin/theme declared requirements', 'Tool Kits PHP requirement'),
        'public' => array('Homepage TTFB', 'Public homepage response', 'MIME sniffing protection', 'Content Security Policy', 'Referrer policy', 'Frame embedding protection', 'HTML browser cache lifetime', 'Homepage title', 'Meta description', 'Canonical URL', 'Search indexing'),
        'profile' => array('Homepage database query count', 'Slow outbound HTTP requests'),
    );
    foreach ($groups as $group => $labels) { foreach ($labels as $label) { if (tk_health_check_id($label) === $id) { return $group; } } }
    return '';
}

function tk_health_center_evidence(string $current, string $expected, string $source): array {
    return array('current' => substr($current, 0, 1200), 'expected' => substr($expected, 0, 1200), 'source' => $source);
}

function tk_health_center_policy_html(): void {
    $policy = tk_health_center_policy();
    echo '<details class="tk-health-policy"><summary>Warning thresholds</summary><p>Diagnostic thresholds for this website. Saving does not change PHP, caching, or security settings. Run a new scan or recheck a row to apply them to measured results.</p><form class="tk-health-policy-form">';
    foreach (array('ttfb' => array('Homepage TTFB (seconds)', 'Warning at or above', 'Critical at or above', '0.1'), 'queries' => array('Homepage database queries', 'Warning at or above', 'Critical at or above', '1'), 'memory' => array('PHP memory limit (MB)', 'Warning below', 'Critical below', '1')) as $metric => $labels) {
        echo '<fieldset><legend>' . esc_html($labels[0]) . '</legend>';
        foreach (array('warning', 'critical') as $index => $severity) {
            $key = $metric . '_' . $severity;
            $min = $metric === 'ttfb' ? ($severity === 'warning' ? 0.1 : 0.2) : ($metric === 'memory' ? ($severity === 'warning' ? 32 : 16) : ($severity === 'warning' ? 1 : 2));
            $max = $metric === 'ttfb' ? ($severity === 'warning' ? 15 : 20) : ($metric === 'memory' ? ($severity === 'warning' ? 16384 : 16383) : 100000);
            echo '<label>' . esc_html($labels[$index + 1]) . '<input type="number" name="' . esc_attr($key) . '" value="' . esc_attr($policy[$key]) . '" min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" step="' . esc_attr($labels[3]) . '" required></label>';
        }
        echo '</fieldset>';
    }
    echo '<label class="tk-health-cache-policy"><input type="checkbox" name="object_cache_required" value="1"' . ($policy['object_cache_required'] ? ' checked' : '') . '> Persistent object cache is required for this website</label><p>When optional, an inactive object cache is informational and does not lower the score.</p><button type="submit" class="button">Save thresholds</button></form></details>';
}
