<?php
if (!defined('ABSPATH')) { exit; }

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
            if ($host === $entry || (strpos($entry, '*.') === 0 && substr($host, -strlen(substr($entry, 1))) === substr($entry, 1))) { return true; }
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

    public static function scan(string $html, string $origin, array $trusted = array(), int $depth = 0, bool $stored = false): array {
        $result = array('findings' => array(), 'limited' => false);
        if (strlen($html) > self::MAX_BYTES) { $result['limited'] = true; return $result; }
        if (strpos($html, '<') === false) { return $result; }
        if (!class_exists('DOMDocument')) { throw new RuntimeException('PHP DOM extension is required.'); }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$loaded) { $result['limited'] = true; return $result; }
        $add = function(DOMElement $node, string $rule, string $severity, string $reason, string $host = '') use (&$result, $document) {
            if (count($result['findings']) >= self::MAX_FINDINGS) { $result['limited'] = true; return; }
            $opening = $document->saveHTML($node);
            if (strtolower($node->tagName) === 'form') { $opening = preg_replace('~>.*$~s', '>', $opening); }
            $result['findings'][] = array('rule' => $rule, 'severity' => $severity, 'reason' => $reason, 'line' => max(1, $node->getLineNo()), 'host' => $host, 'snippet' => self::snippet($opening));
        };
        $visited = 0;
        foreach ($document->getElementsByTagName('*') as $node) {
            if (++$visited > 15000 || count($result['findings']) >= self::MAX_FINDINGS) { $result['limited'] = true; break; }
            $tag = strtolower($node->tagName);
            foreach ($node->attributes as $attribute) {
                $name = strtolower($attribute->name);
                $value = $attribute->value;
                if (preg_match('/^on[a-z]+$/', $name)) {
                    $high = preg_match('/(?:eval\s*\(|document\.cookie|(?:window\.)?location\s*[.=]|fetch\s*\(|atob\s*\()/i', $value);
                    $add($node, 'event_handler', $high ? 'high' : 'medium', 'Inline ' . $name . ' handler can execute JavaScript. Verify that it belongs to the intended page.');
                }
                if (in_array($name, array('src', 'href', 'action', 'formaction', 'xlink:href', 'data'), true)) {
                    $normalized = preg_replace('/[\x00-\x20\x7f]+/', '', $value);
                    if (preg_match('~^(?:javascript|vbscript):~i', $normalized)) {
                        $add($node, 'executable_url', 'high', 'Executable URL in ' . $name . ' can run code when the element is used.');
                    } elseif (preg_match('~^data:(?:text/html|(?:application|text)/(?:javascript|ecmascript)|image/svg\+xml)~i', $normalized)) {
                        $add($node, 'active_data_url', 'high', 'An active document/script is embedded in a data URL. Review its origin.');
                    }
                }
            }
            $resource = $tag === 'script' || $tag === 'iframe' || $tag === 'embed' ? $node->getAttribute('src') : ($tag === 'object' ? $node->getAttribute('data') : '');
            $host = self::host($resource, $origin);
            $external = $host !== '' && !self::trusted($host, $origin, $trusted);
            if ($tag === 'script' && $external) {
                $add($node, 'external_script', 'review', 'Script is loaded from an unreviewed external host. External scripts can be legitimate integrations.', $host);
            }
            if ($tag === 'iframe') {
                if (self::hidden($node)) {
                    $add($node, 'hidden_iframe', 'high', 'A hidden or zero-sized iframe can load another document without being visible. Confirm its purpose.', $host);
                } elseif ($external) {
                    $add($node, 'external_iframe', 'review', 'Iframe uses an unreviewed external host; verify this embed.', $host);
                }
                if ($node->hasAttribute('srcdoc')) {
                    if ($depth < 2) {
                        $nested = self::scan($node->getAttribute('srcdoc'), $origin, $trusted, $depth + 1, $stored);
                        foreach ($nested['findings'] as $finding) {
                            if (count($result['findings']) >= self::MAX_FINDINGS) { $result['limited'] = true; break; }
                            $finding['reason'] = 'Inside iframe srcdoc: ' . $finding['reason'];
                            $finding['line'] = max(1, $node->getLineNo());
                            $result['findings'][] = $finding;
                        }
                        $result['limited'] = $result['limited'] || $nested['limited'];
                    } else { $result['limited'] = true; }
                }
            }
            if (in_array($tag, array('object', 'embed'), true) && $external) { $add($node, 'external_active_embed', 'medium', 'Active embedded content uses an unreviewed external host.', $host); }
            if ($tag === 'base' && $node->hasAttribute('href')) {
                $host = self::host($node->getAttribute('href'), $origin);
                if (!self::trusted($host, $origin, $trusted)) { $add($node, 'external_base', 'high', 'External base URL changes where relative links, forms and resources resolve.', $host); }
            }
            if ($tag === 'meta' && strtolower(trim($node->getAttribute('http-equiv'))) === 'refresh' && preg_match('~url\s*=\s*["\']?([^"\']+)~i', $node->getAttribute('content'), $refresh)) {
                $host = self::host(trim($refresh[1]), $origin);
                if (!self::trusted($host, $origin, $trusted)) { $add($node, 'external_meta_redirect', 'high', 'Meta refresh redirects visitors to an unreviewed external host.', $host); }
            }
            if ($tag === 'form' && $node->hasAttribute('action')) {
                $host = self::host($node->getAttribute('action'), $origin);
                if (!self::trusted($host, $origin, $trusted)) {
                    $password = false;
                    foreach ($node->getElementsByTagName('input') as $input) { if (strtolower($input->getAttribute('type')) === 'password') { $password = true; break; } }
                    $add($node, 'external_form', $password ? 'high' : 'medium', $password ? 'A form with a password field submits to an unreviewed external host.' : 'Form submits data to an unreviewed external host. Verify the destination.', $host);
                }
            }
            if ($tag === 'a' && self::hidden($node)) {
                $host = self::host($node->getAttribute('href'), $origin);
                if (!self::trusted($host, $origin, $trusted)) { $add($node, 'hidden_external_link', 'medium', 'Hidden external link can indicate SEO spam. Verify the intended content.', $host); }
            }
            if ($tag !== 'script') { continue; }
            $type = strtolower(trim(explode(';', $node->getAttribute('type'))[0]));
            if ($type !== '' && !in_array($type, array('text/javascript', 'application/javascript', 'application/ecmascript', 'text/ecmascript', 'module'), true)) { continue; }
            if ($stored) { $add($node, 'stored_script', 'review', 'Executable script is stored in database content. Confirm that it was intentionally added.'); }
            if ($node->hasAttribute('src')) { continue; }
            $script = $node->textContent;
            if (preg_match('~(?:\beval|\bFunction|document\.write(?:ln)?)\s*\(\s*(?:atob|unescape|decodeURIComponent|String\.fromCharCode)\s*\(~i', $script)) {
                $add($node, 'encoded_execution', 'high', 'Decoded or character-generated content is passed into code execution/document.write.');
            }
            $untrusted = '';
            preg_match_all('~https?://[^\s"\'<>`\\\\]+~i', $script, $urls);
            foreach ($urls[0] as $url) { $candidate = self::host($url); if (!self::trusted($candidate, $origin, $trusted)) { $untrusted = $candidate; break; } }
            if ($untrusted !== '' && preg_match('/document\.cookie/i', $script) && preg_match('/(?:fetch\s*\(|sendBeacon\s*\(|XMLHttpRequest|new\s+Image|\.src\s*=)/i', $script)) {
                $add($node, 'cookie_outbound', 'high', 'Script reads cookies and contains an outbound transport plus an unreviewed external host. Review for data leakage.', $untrusted);
            }
            if ($untrusted !== '' && preg_match('/(?:window\.)?location(?:\.href)?\s*=|location\.(?:replace|assign)\s*\(/i', $script)) { $add($node, 'script_redirect', 'medium', 'Script changes navigation and references an unreviewed external host. Verify the redirect.', $untrusted); }
            if ($untrusted !== '' && preg_match('/createElement\s*\(\s*["\']script["\']/i', $script)) { $add($node, 'dynamic_script_loader', 'medium', 'Script creates a script element and references an unreviewed external host.', $untrusted); }
        }
        return $result;
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
