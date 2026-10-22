<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/html-injection-context.php';
require_once __DIR__ . '/html-injection-css.php';

/** Read-only heuristics. Never execute JavaScript, PHP or serialized objects. */
final class TK_HTML_Injection_Engine {
    const MAX_BYTES = 524288;
    const MAX_FINDINGS = 40;

    public static function host(string $url, string $origin = ''): string {
        if (strpos($url, '//') === 0) { $url = 'https:' . $url; }
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) ? strtolower(rtrim($host, '.')) : ($url !== '' && !preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) ? strtolower((string) parse_url($origin, PHP_URL_HOST)) : '');
    }

    public static function trusted(string $host, string $origin, array $trusted): bool {
        if ($host === '' || $host === self::host($origin)) { return true; }
        foreach ($trusted as $entry) {
            if ($host === strtolower(rtrim($entry, '.'))) { return true; }
        }
        return false;
    }

    public static function snippet(string $html): string {
        // Do not keep nonce values, form values, URL queries or credentials.
        $html = preg_replace('~\b(value|nonce|data-(?:token|secret|key))\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~i', '$1="[redacted]"', $html);
        $html = preg_replace('~(https?://)[^\s/<>"\']+@~i', '$1[redacted]@', $html);
        $html = preg_replace('~(https?://[^\s<>"\'?]+)\?[^\s<>"\']*~i', '$1?[redacted]', $html);
        $html = preg_replace('/\s+/', ' ', $html);
        return function_exists('mb_substr') ? mb_substr($html, 0, 220) : substr($html, 0, 220);
    }

    private static function hidden(DOMElement $node): bool {
        $style = preg_replace('/\s+/', '', strtolower($node->getAttribute('style')));
        return $node->hasAttribute('hidden') || preg_match('/(?:display:none|visibility:hidden|opacity:0(?:;|$)|(?:left|top):-[1-9][0-9]{2,}px)/', $style)
            || ($node->hasAttribute('width') && (string) $node->getAttribute('width') === '0')
            || ($node->hasAttribute('height') && (string) $node->getAttribute('height') === '0');
    }

    public static function scan(string $html, string $origin, array $trusted = array(), int $depth = 0, bool $stored = false, ?array $providers = null): array {
        $result = array('findings' => array(), 'suppressed' => array(), 'resources' => array(), 'limited' => false, 'audit_overflow' => 0);
        if (strlen($html) > self::MAX_BYTES) { $result['limited'] = true; return $result; }
        if (strpos($html, '<') === false) { return $result; }
        if (!class_exists('DOMDocument')) { throw new RuntimeException('PHP DOM extension is required.'); }
        $providers = $providers ?? TK_HTML_Injection_Context::providers();
        $document = new DOMDocument(); $previous = libxml_use_internal_errors(true);
        try { $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$loaded) { $result['limited'] = true; return $result; }
        $base_safe = true;
        foreach ($document->getElementsByTagName('base') as $base) { if ($base->hasAttribute('href')) { $parts = TK_HTML_Injection_Context::url($base->getAttribute('href'), $origin); $base_safe = $parts && TK_HTML_Injection_Context::same_origin($parts, $origin); break; } }
        $add = function (DOMElement $node, string $rule, string $severity, string $reason, string $host = '', array $meta = array()) use (&$result, $document) {
            $severity = $severity === 'review' ? 'low' : $severity;
            $action = $meta['action'] ?? (in_array($severity, array('critical', 'high'), true) ? 'REPORT' : 'REVIEW');
            $copy = $node->cloneNode(true); $copy->removeAttribute('nonce');
            $opening = $document->saveHTML($copy);
            $normalized = $copy->cloneNode(true);
            foreach ($normalized->attributes as $attribute) { if (preg_match('/^on[a-z]+$/iD', $attribute->name)) { $tokens = TK_HTML_Injection_Context::tokens($attribute->value); if ($tokens !== null) { $normalized->setAttribute($attribute->name, json_encode($tokens)); } } }
            if (strtolower($node->tagName) === 'script') { $tokens = TK_HTML_Injection_Context::tokens($node->textContent); if ($tokens !== null) { $normalized->textContent = json_encode($tokens); } }
            $evidence_hash = hash('sha256', $document->saveHTML($normalized));
            if (strtolower($node->tagName) === 'form') { $opening = preg_replace('~>.*$~s', '>', $opening); }
            $finding = array('rule' => $rule, 'severity' => $severity, 'reason' => $reason, 'line' => max(1, $node->getLineNo()), 'host' => $host, 'snippet' => self::snippet($opening),
                'rule_id' => strtoupper($rule), 'action' => $action, 'classification' => $action === 'REPORT' ? 'dangerous_behavior' : 'suspicious_behavior', 'confidence' => $action === 'REPORT' ? .94 : .65,
                'location' => $node->getNodePath(), 'evidence_hash' => $evidence_hash, 'resource_identifier' => $host);
            self::retain($result, array_merge($finding, $meta));
        };
        $visited = 0;
        foreach ($document->getElementsByTagName('*') as $node) {
            if (++$visited > 15000) { $result['limited'] = true; break; }
            $tag = strtolower($node->tagName); $has_events = false;
            foreach ($node->attributes as $attribute) { if (preg_match('/^on[a-z]+$/iD', $attribute->name)) { $has_events = true; } }
            foreach ($node->attributes as $attribute) {
                $name = strtolower($attribute->name); $value = $attribute->value;
                if (preg_match('/^on[a-z]+$/D', $name)) {
                    $safe = ($base_safe || preg_match('~^https?://~i', $node->getAttribute('href'))) && TK_HTML_Injection_Context::safe_css_handler($node, $name, $value, $origin, $trusted, $providers);
                    if ($safe) {
                        $add($node, 'event_handler', 'info', 'Recognized asynchronous CSS loading pattern; the complete handler and stylesheet destination were validated.', '', array('rule_id' => 'SAFE_CSS_ONLOAD_001', 'classification' => 'expected_behavior', 'action' => 'SKIP', 'confidence' => .99, 'location' => $node->getNodePath() . '/@' . $name));
                    } else {
                        $behavior = TK_HTML_Injection_Context::behavior($value, $origin, $trusted, $providers);
                        $strong = $behavior['exfil'] || $behavior['network'] && $behavior['host'] !== '' || $behavior['execution'] && $behavior['input'];
                        $active = $strong || $behavior['network'] && in_array($name, array('onerror', 'onload'), true);
                        $add($node, 'event_handler', $strong ? 'high' : ($behavior['network'] || $behavior['execution'] || $behavior['encoded'] ? 'medium' : 'low'), 'Inline ' . $name . ': ' . ($strong ? 'sensitive-data/outbound or user-input execution indicators.' : 'behavior was not verified as an approved safe pattern; inspect its intended function.'), $behavior['host'], array('action' => $active ? 'REPORT' : 'REVIEW', 'location' => $node->getNodePath() . '/@' . $name));
                    }
                }
                if (in_array($name, array('src', 'href', 'action', 'formaction', 'xlink:href', 'data'), true)) {
                    $normalized = preg_replace('/[\x00-\x20\x7f]+/', '', $value);
                    if (preg_match('~^(?:javascript|vbscript):~i', $normalized)) { $add($node, 'executable_url', 'high', 'Executable URL in ' . $name . ' can run code when the element is used.'); }
                    elseif (preg_match('~^data:(?:text/html|(?:application|text)/(?:javascript|ecmascript)|image/svg\+xml)~i', $normalized)) { $add($node, 'active_data_url', 'high', 'An active document/script is embedded in a data URL. Review its origin.'); }
                }
                if ($name === 'style') {
                    $css = TK_HTML_Injection_CSS::scan('element{' . $value . '}', $origin, $trusted, $providers);
                    foreach ($css['findings'] as $finding) { $add($node, $finding['rule'], $finding['severity'], $finding['reason'], $finding['host'], array('action' => $finding['action'], 'location' => $node->getNodePath() . '/@style:' . $finding['line'])); }
                    $result['limited'] = $result['limited'] || $css['limited'];
                }
            }
            if ($tag === 'link') {
                $rel = strtolower(trim($node->getAttribute('rel'))); $style = $rel === 'stylesheet' || $rel === 'preload' && strtolower(trim($node->getAttribute('as'))) === 'style';
                if ($style) {
                    $url = $node->getAttribute('href'); $parts = TK_HTML_Injection_Context::url($url, $origin);
                    $safe = ($base_safe || preg_match('~^https?://~i', $url)) && TK_HTML_Injection_Context::css_destination($url, $origin, $trusted, $providers);
                    if ($safe && !$has_events) { $add($node, 'stylesheet', 'info', 'Validated stylesheet destination; a stylesheet link is not executable JavaScript.', $parts['host'] ?? '', array('rule_id' => 'SAFE_CSS_STYLESHEET_001', 'classification' => 'expected_behavior', 'action' => 'SKIP', 'confidence' => .99)); }
                    elseif (!$safe) { $add($node, 'external_stylesheet', 'low', 'Stylesheet destination is unreviewed, ambiguous or uses an unexpected scheme. A CSS loading handler does not establish trust.', $parts['host'] ?? '', array('action' => 'REVIEW')); }
                    if ($parts && TK_HTML_Injection_Context::same_origin($parts, $origin) && preg_match('/\.css$/iD', $parts['path'])) { $result['resources'][] = $url; }
                }
            }
            $resource = in_array($tag, array('script', 'iframe', 'embed'), true) ? $node->getAttribute('src') : ($tag === 'object' ? $node->getAttribute('data') : '');
            $host = self::host($resource, $origin); $external = $host !== '' && !self::trusted($host, $origin, $trusted);
            $type = strtolower(trim(explode(';', $node->getAttribute('type'))[0]));
            $executable = $type === '' || in_array($type, array('text/javascript', 'application/javascript', 'application/ecmascript', 'text/ecmascript', 'module'), true);
            $provider = $tag === 'script' && $executable && !$has_events && trim($node->textContent) === '' ? TK_HTML_Injection_Context::provider($resource, 'script', $origin, $providers) : '';
            if ($tag === 'script' && $executable && $resource !== '' && ($external || $host !== '' && $host !== self::host($origin))) {
                if ($provider !== '') { $add($node, 'external_script', 'info', 'Approved integration script URL and non-executable attributes verified. Remote content and account ownership are not attested.', $host, array('rule_id' => 'SAFE_PROVIDER_SCRIPT_001', 'classification' => 'recognized_integration', 'action' => 'SKIP', 'confidence' => .98, 'provider' => $provider)); }
                else {
                    $parts = TK_HTML_Injection_Context::url($resource, $origin); $insecure = !$parts || $parts['scheme'] !== 'https';
                    $add($node, 'external_script', $insecure ? 'medium' : 'low', 'External script was not verified against an approved integration path/context. Review its provenance; hostname trust alone does not approve arbitrary scripts.', $host, array('action' => $insecure ? 'REPORT' : 'REVIEW', 'classification' => $external ? 'unknown_integration' : 'custom_trusted_destination'));
                }
            }
            if ($tag === 'iframe') {
                if (self::hidden($node)) { $add($node, 'hidden_iframe', 'medium', 'A hidden/zero-sized iframe warrants contextual review; it may be an intentional integration.', $host); }
                elseif ($external) { $add($node, 'external_iframe', 'low', 'Iframe uses an unreviewed external host; verify this embed.', $host); }
                if ($node->hasAttribute('srcdoc')) {
                    if ($depth < 2) {
                        $nested = self::scan($node->getAttribute('srcdoc'), $origin, $trusted, $depth + 1, $stored, $providers);
                        foreach (array('findings', 'suppressed') as $bucket) { foreach ($nested[$bucket] as $finding) { $finding['reason'] = 'Inside iframe srcdoc: ' . $finding['reason']; $finding['location'] = $node->getNodePath() . '/srcdoc' . $finding['location']; $finding['line'] = max(1, $node->getLineNo()); self::retain($result, $finding); } }
                        $result['resources'] = array_merge($result['resources'], $nested['resources']); $result['limited'] = $result['limited'] || $nested['limited']; $result['audit_overflow'] += $nested['audit_overflow'];
                    } else { $result['limited'] = true; }
                }
            }
            if (in_array($tag, array('object', 'embed'), true) && $external) { $add($node, 'external_active_embed', 'medium', 'Active embedded content uses an unreviewed external host.', $host); }
            if ($tag === 'base' && $node->hasAttribute('href') && !$base_safe) { $add($node, 'external_base', 'high', 'External or invalid base URL changes where relative links, forms and resources resolve.', self::host($node->getAttribute('href'), $origin)); }
            if ($tag === 'meta' && strtolower(trim($node->getAttribute('http-equiv'))) === 'refresh' && preg_match('~url\s*=\s*["\']?([^"\']+)~i', $node->getAttribute('content'), $refresh)) {
                $destination = self::host(trim($refresh[1]), $origin); if (!self::trusted($destination, $origin, $trusted)) { $add($node, 'external_meta_redirect', 'medium', 'Meta refresh redirects to an unreviewed external host; verify intent.', $destination); }
            }
            if ($tag === 'form' && $node->hasAttribute('action')) {
                $destination = self::host($node->getAttribute('action'), $origin); $password = false;
                foreach ($node->getElementsByTagName('input') as $input) { if (strtolower($input->getAttribute('type')) === 'password') { $password = true; break; } }
                if ($destination !== '' && $destination !== self::host($origin) && ($password || !self::trusted($destination, $origin, $trusted))) { $add($node, 'external_form', $password ? 'high' : 'medium', $password ? 'A password form submits credentials to another origin; hostname trust cannot establish that this is intended.' : 'Form submits data to an unreviewed external host. Verify the destination.', $destination); }
            }
            if ($tag === 'a' && self::hidden($node)) { $destination = self::host($node->getAttribute('href'), $origin); if (!self::trusted($destination, $origin, $trusted)) { $add($node, 'hidden_external_link', 'medium', 'Hidden external link can indicate SEO spam. Verify intended content.', $destination); } }
            if ($tag === 'style') {
                $css = TK_HTML_Injection_CSS::scan($node->textContent, $origin, $trusted, $providers);
                foreach ($css['findings'] as $finding) { $add($node, $finding['rule'], $finding['severity'], $finding['reason'], $finding['host'], array('action' => $finding['action'], 'location' => $node->getNodePath() . '/css:' . $finding['line'])); }
                $result['resources'] = array_merge($result['resources'], $css['resources']); $result['limited'] = $result['limited'] || $css['limited'];
            }
            if ($tag !== 'script' || !$executable) { continue; }
            $behavior = TK_HTML_Injection_Context::behavior($node->textContent, $origin, $trusted, $providers);
            $safe_script = !$has_events && ($provider !== '' || $resource === '' && $behavior['provider'] !== '');
            if ($stored) { $add($node, 'stored_script', $safe_script ? 'info' : 'low', $safe_script ? 'Stored script matches an approved integration grammar.' : 'Executable script is stored in database content. Confirm it was intentionally added.', $host, $safe_script ? array('rule_id' => 'SAFE_STORED_INTEGRATION_001', 'classification' => 'recognized_integration', 'action' => 'SKIP', 'confidence' => .98, 'provider' => $provider ?: $behavior['provider']) : array('action' => 'REVIEW')); }
            if ($node->hasAttribute('src')) { continue; }
            if ($behavior['encoded']) { $add($node, 'encoded_execution', $behavior['exfil'] || $behavior['input'] ? 'high' : 'medium', 'Decoded/character-generated data is passed into code execution or document.write; inspect provenance and data flow.', $behavior['host']); }
            elseif ($behavior['execution']) { $add($node, 'dynamic_execution', $behavior['input'] ? 'high' : 'medium', 'Dynamic code execution requires review; user-controlled inputs increase risk.', $behavior['host']); }
            if ($behavior['sensitive'] && $behavior['network']) { $add($node, 'cookie_outbound', $behavior['exfil'] ? 'high' : 'medium', $behavior['exfil'] ? 'Sensitive data appears in an outbound transport expression; investigate potential leakage, including to recognized providers.' : 'Sensitive reads and outbound transport occur in the same script; data flow was not proven and requires review.', $behavior['host']); }
            if ($behavior['host'] !== '' && preg_match('/\blocation\s*(?:\.\s*href\s*)?=|\blocation\s*\.\s*(?:replace|assign)\s*\(/i', $behavior['code'])) { $add($node, 'script_redirect', 'medium', 'Navigation changes reference an unreviewed external host. Verify the redirect.', $behavior['host']); }
            if ($behavior['dynamic']) {
                $safe = $safe_script && !$behavior['sensitive'] && !$behavior['execution'];
                $strong = $behavior['input'] || $behavior['exfil'] || $behavior['insecure'];
                $add($node, 'dynamic_script_loader', $safe ? 'info' : ($strong ? 'high' : 'medium'), $safe ? 'Entire loader matches an approved integration grammar and URL.' : 'Dynamic script destination/behavior could not be verified; dynamic construction or user-controlled inputs require review.', $behavior['host'], $safe ? array('rule_id' => 'SAFE_DYNAMIC_INTEGRATION_001', 'classification' => 'recognized_integration', 'action' => 'SKIP', 'confidence' => .98, 'provider' => $behavior['provider']) : array('action' => $strong ? 'REPORT' : 'REVIEW', 'classification' => 'unknown_integration'));
            }
            if ($behavior['tokens'] === null && ($behavior['dynamic'] || $behavior['execution'] || $behavior['encoded'] || $behavior['sensitive'] || $behavior['network'])) { $add($node, 'unparsed_javascript', 'low', 'A script with behavior indicators exceeded the supported lexical grammar/budget; no safe-pattern suppression was applied. Manual review is required.'); }
        }
        $result['resources'] = array_values(array_unique($result['resources']));
        return $result;
    }

    /** Prioritize stronger evidence when weak observations exhaust a result budget. */
    public static function retain(array &$result, array $finding): void {
        $bucket = ($finding['action'] ?? '') === 'SKIP' ? 'suppressed' : 'findings';
        $limit = $bucket === 'suppressed' ? 80 : self::MAX_FINDINGS;
        if (count($result[$bucket]) < $limit) { $result[$bucket][] = $finding; return; }
        if ($bucket === 'suppressed') {
            $result['audit_overflow'] = ($result['audit_overflow'] ?? 0) + 1;
            $id = $finding['rule_id'];
            if (!isset($result['audit_overflow_rules'][$id])) { $result['audit_overflow_rules'][$id] = array('count' => 0, 'reason' => $finding['reason'], 'confidence' => $finding['confidence'], 'action' => 'SKIP'); }
            $result['audit_overflow_rules'][$id]['count']++; return;
        }
        $result['limited'] = true;
        $rank = static function ($item) { return (array('info' => 0, 'low' => 2, 'medium' => 4, 'high' => 6, 'critical' => 8)[$item['severity']] ?? 0) + (($item['action'] ?? '') === 'REPORT' ? 1 : 0); };
        $lowest = 0;
        foreach ($result[$bucket] as $index => $item) { if ($rank($item) < $rank($result[$bucket][$lowest])) { $lowest = $index; } }
        if ($rank($finding) > $rank($result[$bucket][$lowest])) { $result[$bucket][$lowest] = $finding; }
    }

    public static function secret_key(string $key): bool {
        return (bool) preg_match('/(?:pass(?:word)?|secret|token|nonce|salt|authorization|license|api[_-]?key|smtp|oauth|session)/i', $key);
    }

    /** Decode only JSON or a small scalar/array serialization grammar. */
    public static function strings(string $raw): array {
        if (strlen($raw) > self::MAX_BYTES) { return array('values' => array(), 'limited' => true); }
        $trimmed = trim($raw); $value = $raw; $limited = false;
        if ($trimmed !== '' && in_array($trimmed[0], array('{', '[', '"'), true)) {
            $decoded = json_decode($trimmed, true, 24);
            if (json_last_error() === JSON_ERROR_NONE) { $value = $decoded; }
        } elseif (preg_match('/^(?:a|s|O|C):/', $trimmed)) {
            try {
                $position = 0; $nodes = 0;
                $value = self::serialized_value($trimmed, $position, 0, $nodes);
                if ($position !== strlen($trimmed)) { throw new RuntimeException('Trailing serialized data'); }
            } catch (RuntimeException $error) { return array('values' => array(), 'limited' => true); }
        }
        $values = array(); $count = 0;
        $walk = function($part, string $path, int $depth) use (&$walk, &$values, &$limited, &$count) {
            if (++$count > 500 || $depth > 12) { $limited = true; return; }
            if (is_array($part)) {
                foreach ($part as $key => $child) {
                    if (self::secret_key((string) $key)) { continue; }
                    $walk($child, $path . '[' . substr((string) $key, 0, 60) . ']', $depth + 1);
                    if ($count > 500) { break; }
                }
            } elseif (is_string($part)) {
                $trim = trim($part);
                if ($trim !== '' && in_array($trim[0], array('{', '['), true)) {
                    $decoded = json_decode($trim, true, 24);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) { $walk($decoded, $path, $depth + 1); return; }
                }
                if (strpos($part, '<') !== false) { $values[] = array('path' => $path, 'html' => $part); }
            }
        };
        $walk($value, '', 0);
        return array('values' => $values, 'limited' => $limited);
    }

    private static function serialized_value(string $raw, int &$position, int $depth, int &$nodes) {
        if (++$nodes > 1000 || $depth > 12) { throw new RuntimeException('Serialization limit'); }
        $remaining = substr($raw, $position);
        if (strpos($remaining, 'N;') === 0) { $position += 2; return null; }
        if (preg_match('/^([ibd]):([^;]{1,40});/', $remaining, $match)) {
            if (!preg_match('/^[+\-0-9.eE]+$/', $match[2])) { throw new RuntimeException('Invalid scalar'); }
            $position += strlen($match[0]); return $match[1] === 'i' ? (int) $match[2] : $match[2];
        }
        if (preg_match('/^s:([0-9]{1,7}):"/', $remaining, $match)) {
            $length = (int) $match[1]; $start = $position + strlen($match[0]);
            if ($length > self::MAX_BYTES || substr($raw, $start + $length, 2) !== '";') { throw new RuntimeException('Invalid string'); }
            $position = $start + $length + 2; return substr($raw, $start, $length);
        }
        if (preg_match('/^a:([0-9]{1,4}):\{/', $remaining, $match) && (int) $match[1] <= 500) {
            $position += strlen($match[0]); $out = array();
            for ($i = 0; $i < (int) $match[1]; $i++) {
                $key = self::serialized_value($raw, $position, $depth + 1, $nodes);
                if (!is_int($key) && !is_string($key)) { throw new RuntimeException('Invalid key'); }
                $out[$key] = self::serialized_value($raw, $position, $depth + 1, $nodes);
            }
            if (substr($raw, $position++, 1) !== '}') { throw new RuntimeException('Invalid array'); }
            return $out;
        }
        throw new RuntimeException('Unsupported serialized value');
    }
}
