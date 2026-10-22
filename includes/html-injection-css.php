<?php
if (!defined('ABSPATH')) { exit; }

/** Bounded CSS lexer: comments/strings are parsed, escaped identifiers are decoded, no resources are executed. */
final class TK_HTML_Injection_CSS {
    private static function escape(string $source, int &$i): string {
        $i++; $hex = ''; $length = strlen($source);
        while ($i < $length && strlen($hex) < 6 && ctype_xdigit($source[$i])) { $hex .= $source[$i++]; }
        if ($hex !== '') {
            if ($i < $length && ctype_space($source[$i])) { $i++; }
            $number = hexdec($hex); return $number > 0 && $number < 128 ? chr($number) : '?';
        }
        return $i < $length ? $source[$i++] : '?';
    }

    private static function tokens(string $source): ?array {
        $tokens = array(); $i = 0; $length = strlen($source); $line = 1;
        while ($i < $length) {
            if (count($tokens) > 30000) { return null; }
            $char = $source[$i];
            if (ctype_space($char)) { if ($char === "\n") { $line++; } $i++; continue; }
            if (substr($source, $i, 2) === '/*') { $end = strpos($source, '*/', $i + 2); if ($end === false) { return null; } $line += substr_count(substr($source, $i, $end + 2 - $i), "\n"); $i = $end + 2; continue; }
            if ($char === '"' || $char === "'") {
                $quote = $char; $value = ''; $closed = false; $i++; $start = $line;
                while ($i < $length) { $char = $source[$i]; if ($char === $quote) { $i++; $closed = true; break; } if ($char === "\n") { $line++; } $value .= $char === '\\' ? self::escape($source, $i) : $source[$i++]; }
                if (!$closed) { return null; } $tokens[] = array('s', $value, $start); continue;
            }
            if (preg_match('/[a-zA-Z0-9_@-]/', $char) || $char === '\\') {
                $value = ''; $start = $line;
                while ($i < $length && (preg_match('/[a-zA-Z0-9_@-]/', $source[$i]) || $source[$i] === '\\')) { $value .= $source[$i] === '\\' ? self::escape($source, $i) : $source[$i++]; }
                $tokens[] = array('i', $value, $start); continue;
            }
            $tokens[] = array('p', $char, $line); $i++;
        }
        return $tokens;
    }

    public static function scan(string $css, string $origin, array $trusted, array $providers): array {
        $result = array('findings' => array(), 'suppressed' => array(), 'resources' => array(), 'limited' => false);
        if (strlen($css) > TK_HTML_Injection_Engine::MAX_BYTES) { $result['limited'] = true; return $result; }
        $tokens = self::tokens($css);
        if ($tokens === null) { $result['limited'] = true; return $result; }
        $stack = array(); $prelude = ''; $external = false;
        $add = static function (string $rule, string $severity, string $reason, int $line, string $evidence, string $host = '', string $action = 'REVIEW') use (&$result) {
            TK_HTML_Injection_Engine::retain($result, array('rule' => $rule, 'rule_id' => strtoupper($rule), 'severity' => $severity, 'reason' => $reason, 'line' => $line, 'host' => $host, 'snippet' => TK_HTML_Injection_Engine::snippet($evidence), 'classification' => $action === 'REPORT' ? 'dangerous_behavior' : 'suspicious_behavior', 'action' => $action, 'confidence' => $action === 'REPORT' ? .94 : .7, 'location' => 'css:line:' . $line, 'evidence_hash' => hash('sha256', $evidence), 'resource_identifier' => $host));
        };
        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i]; $value = strtolower($token[1]);
            if ($value === '{') { $stack[] = $prelude; $prelude = ''; continue; }
            if ($value === '}') { array_pop($stack); $prelude = ''; continue; }
            if ($value === ';') { $prelude = ''; continue; }
            $prelude .= ($token[0] === 's' ? '"' . $token[1] . '"' : $token[1]);
            if ($token[0] !== 'i') { continue; }
            if ($value === 'expression' && ($tokens[$i + 1][1] ?? '') === '(') { $add('css_expression', 'medium', 'Legacy executable CSS expression; verify browser targets and intended behavior.', $token[2], $prelude, '', 'REPORT'); }
            $import = $value === '@import'; $url = ''; $end = $i;
            if ($import && ($tokens[$i + 1][0] ?? '') === 's') { $url = $tokens[$i + 1][1]; $end = $i + 1; }
            elseif (($value === 'url' || $import && strtolower($tokens[$i + 1][1] ?? '') === 'url') && ($tokens[$i + ($import ? 2 : 1)][1] ?? '') === '(') {
                $start = $i + ($import ? 3 : 2); $end = $start;
                if (($tokens[$start][0] ?? '') === 's') { $url = $tokens[$start][1]; $end++; }
                else { while (isset($tokens[$end]) && !in_array($tokens[$end][1], array(')', '(', '{', '}'), true) && $end - $start < 2048) { $url .= $tokens[$end++][1]; } }
                if (($tokens[$end][1] ?? '') !== ')') { $result['limited'] = true; continue; }
            } else { continue; }
            $i = $end; $selector = end($stack) ?: ''; $evidence = $selector . ' {' . $prelude . '(' . $url . ')}';
            if (preg_match('/^(?:javascript|vbscript):/i', preg_replace('/[\x00-\x20]+/', '', $url))) { $add('css_executable_url', 'high', 'Executable scheme in a CSS resource URL.', $token[2], $evidence, '', 'REPORT'); continue; }
            if ($url === '' || $url[0] === '#' || preg_match('~^data:image/(?:png|gif|jpe?g|webp|svg\+xml);|^data:(?:font/|application/(?:font|x-font))~i', $url)) { continue; }
            $parts = TK_HTML_Injection_Context::url($url, $origin);
            if (!$parts) { $add('css_resource_scheme', 'low', 'CSS resource scheme or URL could not be validated; inspect its context.', $token[2], $evidence); continue; }
            $local = TK_HTML_Injection_Context::same_origin($parts, $origin);
            if ($import && $local && preg_match('/\.css$/iD', $parts['path'])) { $result['resources'][] = $url; }
            $font = $parts['scheme'] === 'https' && $parts['host'] === 'fonts.gstatic.com' && strpos($parts['path'], '/s/') === 0 && preg_match('/\.(?:woff2?|ttf|otf)$/iD', $parts['path']) && empty($parts['query']) && (!isset($parts['port']) || $parts['port'] === 443) && isset($providers['google_fonts']);
            $known = $font || TK_HTML_Injection_Context::provider($url, 'style', $origin, $providers) !== '';
            $leak = preg_match('/\[(?:value|data-(?:token|secret|nonce))\s*[*^$|~]?=|\b(?:input|textarea)\b[^{}]*\[(?:name|id)[*^$|~]?=[^\]]*(?:password|token|csrf|secret)/i', $selector);
            if (!$local && $leak) { $external = true; $add('css_exfiltration', 'high', 'A value/sensitive-field selector triggers a cross-origin request, consistent with CSS data probing. Provider/custom hostname trust cannot approve this behavior.', $token[2], $evidence, $parts['host'], 'REPORT'); continue; }
            if ($local || $known || $parts['scheme'] === 'https' && in_array($parts['host'], $trusted, true)) { continue; }
            $external = true;
            $add($import ? 'css_external_import' : 'css_external_resource', $import ? 'medium' : 'low', $import ? 'Stylesheet imports CSS from an unreviewed destination. Verify ownership and intended content.' : 'External CSS image/font/resource destination needs review; url() alone is not proof of a vulnerability.', $token[2], $evidence, $parts['host']);
        }
        $code = implode('', array_map(static function ($t) { return $t[0] === 's' ? '' : strtolower($t[1]); }, $tokens));
        if ($external && strpos($code, 'position:fixed') !== false && preg_match('/z-index:(?:2147483647|999999[0-9]*)/', $code) && strpos($code, 'opacity:0') !== false && (strpos($code, 'width:100vw') !== false || strpos($code, 'inset:0') !== false)) {
            $add('css_concealed_overlay', 'medium', 'A transparent fixed overlay with extreme stacking and external resources warrants review; individual layout properties are not classified as malware.', 1, TK_HTML_Injection_Engine::snippet($css));
        }
        return $result;
    }
}
