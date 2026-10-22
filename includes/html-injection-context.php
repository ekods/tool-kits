<?php
if (!defined('ABSPATH')) { exit; }

/** A deliberately small JS lexer and approved-grammar matcher, never a JS interpreter. */
final class TK_HTML_Injection_Context {
    const VERSION = 1;

    public static function tokens(string $source): ?array {
        $tokens = array(); $length = strlen($source); $i = 0;
        while ($i < $length) {
            if (count($tokens) >= 20000) { return null; }
            $char = $source[$i];
            if (strpos(" \r\n\t\f", $char) !== false) { $i++; continue; }
            if (substr($source, $i, 2) === '//') { $end = strpos($source, "\n", $i + 2); $i = $end === false ? $length : $end; continue; }
            if (substr($source, $i, 2) === '/*') { $end = strpos($source, '*/', $i + 2); if ($end === false) { return null; } $i = $end + 2; continue; }
            if ($char === '"' || $char === "'") {
                $quote = $char; $value = ''; $closed = false; $i++;
                while ($i < $length) {
                    $char = $source[$i++];
                    if ($char === $quote) { $closed = true; break; }
                    if ($char === "\n" || $char === "\r") { return null; }
                    if ($char === '\\') {
                        if ($i >= $length) { return null; } $escape = $source[$i++];
                        if ($escape === 'x' || $escape === 'u') {
                            $size = $escape === 'x' ? 2 : 4; $hex = substr($source, $i, $size);
                            if (strlen($hex) !== $size || !ctype_xdigit($hex)) { return null; }
                            $code = hexdec($hex); if ($code > 127 || $code === 0) { return null; }
                            $value .= chr($code); $i += $size;
                        } elseif (in_array($escape, array('\\', "'", '"', '/'), true)) { $value .= $escape; }
                        elseif (strpos('nrtbfv', $escape) !== false) { $escapes = array('n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0c", 'v' => "\x0b"); $value .= $escapes[$escape]; }
                        elseif ($escape === "\n") { /* JS line continuation. */ }
                        else { return null; } // Ambiguous/octal/unicode escape: retain for review.
                    } else { $value .= $char; }
                }
                if (!$closed) { return null; } $tokens[] = array('s', $value); continue;
            }
            if (preg_match('/[a-zA-Z_$]/', $char)) {
                $start = $i++; while ($i < $length && preg_match('/[a-zA-Z0-9_$]/', $source[$i])) { $i++; }
                $tokens[] = array('i', substr($source, $start, $i - $start)); continue;
            }
            if (ctype_digit($char)) {
                $start = $i++; while ($i < $length && ctype_digit($source[$i])) { $i++; }
                $tokens[] = array('n', substr($source, $start, $i - $start)); continue;
            }
            // Template literals, escaped identifiers, regex literals and non-ASCII JS cannot be approved.
            if (strpos('(){}[];,.=:+-*!?&|<>%/', $char) === false) { return null; }
            $tokens[] = array('p', $char); $i++;
        }
        return $tokens;
    }

    public static function code(?array $tokens, string $fallback): string {
        if ($tokens === null) { return $fallback; }
        $parts = array();
        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i][1] === '[' && ($tokens[$i + 1][0] ?? '') === 's' && ($tokens[$i + 2][1] ?? '') === ']' && preg_match('/^[a-zA-Z_$][a-zA-Z0-9_$]*$/D', $tokens[$i + 1][1])) {
                $parts[] = '.'; $parts[] = $tokens[$i + 1][1]; $i += 2; continue;
            }
            $parts[] = $tokens[$i][0] === 's' ? 'STRING' : $tokens[$i][1];
        }
        return implode(' ', $parts);
    }

    public static function providers(): array {
        $providers = array(
            'google_analytics' => array('name' => 'Google Analytics / Tag Manager', 'hosts' => array('www.googletagmanager.com', 'www.google-analytics.com'), 'script_paths' => array('/gtag/js', '/gtm.js', '/analytics.js'), 'style_paths' => array()),
            'google_fonts' => array('name' => 'Google Fonts', 'hosts' => array('fonts.googleapis.com', 'fonts.gstatic.com'), 'script_paths' => array(), 'style_paths' => array('/css', '/css2')),
            'font_awesome_css' => array('name' => 'Font Awesome CSS (cdnjs)', 'hosts' => array('cdnjs.cloudflare.com'), 'script_paths' => array(), 'style_paths' => array(), 'style_patterns' => array('~^/ajax/libs/font-awesome/[0-9]+\.[0-9]+\.[0-9]+/css/(?:all|fontawesome|solid|regular|brands)(?:\.min)?\.css$~D')),
            'aos_css' => array('name' => 'AOS CSS (cdnjs)', 'hosts' => array('cdnjs.cloudflare.com'), 'script_paths' => array(), 'style_paths' => array(), 'style_patterns' => array('~^/ajax/libs/aos/[0-9]+\.[0-9]+\.[0-9]+/aos(?:\.min)?\.css$~D')),
        );
        return function_exists('apply_filters') ? (array) apply_filters('tk_html_scan_recognized_providers', $providers) : $providers;
    }

    /** Strict URL shape: credentials, controls, deceptive hosts, non-HTTP schemes and unusual ports are rejected. */
    public static function url(string $url, string $origin): ?array {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { return null; }
        if (strpos($url, '//') === 0) { $url = (parse_url($origin, PHP_URL_SCHEME) ?: 'https') . ':' . $url; }
        if (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
            $base = parse_url($origin); if (!is_array($base) || empty($base['host'])) { return null; }
            $path = $url[0] === '/' ? $url : rtrim(str_replace('\\', '/', dirname($base['path'] ?? '/')), '/') . '/' . $url;
            $url = ($base['scheme'] ?? 'https') . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '') . '/' . ltrim($path, '/');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || !in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true)) { return null; }
        $raw_host = $parts['host'] ?? '';
        if (substr($raw_host, -2) === '..') { return null; }
        $host = strtolower(rtrim($raw_host, '.'));
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host)) { return null; }
        $parts['host'] = $host; $parts['scheme'] = strtolower($parts['scheme']); $parts['path'] = $parts['path'] ?? '/';
        $segments = array();
        foreach (explode('/', $parts['path']) as $segment) { if ($segment === '..') { array_pop($segments); } elseif ($segment !== '.' && $segment !== '') { $segments[] = $segment; } }
        $parts['path'] = '/' . implode('/', $segments) . (substr($parts['path'], -1) === '/' && $segments ? '/' : '');
        return $parts;
    }

    public static function provider(string $url, string $kind, string $origin, array $providers): string {
        $parts = self::url($url, $origin);
        if (!$parts || $parts['scheme'] !== 'https' || isset($parts['port']) && $parts['port'] !== 443 || strpos($parts['path'], '%') !== false) { return ''; }
        foreach ($providers as $id => $provider) {
            if (!in_array($parts['host'], $provider['hosts'] ?? array(), true)) { continue; }
            $path_ok = in_array($parts['path'], $provider[$kind . '_paths'] ?? array(), true);
            foreach ($provider[$kind . '_patterns'] ?? array() as $pattern) { if (preg_match($pattern, $parts['path'])) { $path_ok = true; break; } }
            if (!$path_ok) { continue; }
            $query = array(); parse_str($parts['query'] ?? '', $query);
            if ($id === 'google_analytics') {
                if ($parts['path'] === '/analytics.js') { if ($parts['host'] !== 'www.google-analytics.com' || $query) { continue; } }
                else {
                    if ($parts['host'] !== 'www.googletagmanager.com' || array_diff(array_keys($query), array('id', 'l')) || !is_string($query['id'] ?? null)) { continue; }
                    $pattern = $parts['path'] === '/gtm.js' ? '/^GTM-[A-Z0-9]{3,32}$/D' : '/^(?:G|GT|AW|DC|UA)-[A-Z0-9-]{3,40}$/D';
                    if (!preg_match($pattern, $query['id']) || isset($query['l']) && $query['l'] !== 'dataLayer') { continue; }
                    // Duplicate/encoded parameter names are not approved by this registry.
                    if (count(explode('&', $parts['query'] ?? '')) !== count($query) || preg_match('/%(?:26|3d|00)/i', $parts['query'] ?? '')) { continue; }
                }
            }
            if ($id === 'google_fonts' && ($parts['host'] !== 'fonts.googleapis.com' || array_diff(array_keys($query), array('family', 'display', 'subset', 'text', 'effect')))) { continue; }
            if (in_array($id, array('font_awesome_css', 'aos_css'), true) && $query) { continue; }
            return (string) ($provider['name'] ?? $id);
        }
        return '';
    }

    public static function same_origin(array $parts, string $origin): bool {
        $home = self::url($origin, $origin);
        return $home && $parts['scheme'] === $home['scheme'] && $parts['host'] === $home['host'] && ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)) === ($home['port'] ?? ($home['scheme'] === 'https' ? 443 : 80));
    }

    public static function css_destination(string $url, string $origin, array $trusted, array $providers): bool {
        $parts = self::url($url, $origin);
        if (!$parts) { return false; }
        if (self::provider($url, 'style', $origin, $providers) !== '') { return true; }
        $host_ok = self::same_origin($parts, $origin) || $parts['scheme'] === 'https' && (!isset($parts['port']) || $parts['port'] === 443) && in_array($parts['host'], $trusted, true);
        return $host_ok && (bool) preg_match('/\.css$/iD', $parts['path']);
    }

    public static function safe_css_handler(DOMElement $node, string $name, string $handler, string $origin, array $trusted, array $providers): bool {
        if (strtolower($node->tagName) !== 'link' || $name !== 'onload' || !self::css_destination($node->getAttribute('href'), $origin, $trusted, $providers)) { return false; }
        foreach ($node->attributes as $attribute) { if (preg_match('/^on[a-z]+$/iD', $attribute->name) && strtolower($attribute->name) !== 'onload') { return false; } }
        $rel = strtolower(trim($node->getAttribute('rel'))); $property = '';
        if ($rel === 'stylesheet' && strtolower(trim($node->getAttribute('media'))) === 'print') { $property = 'media'; }
        if ($rel === 'preload' && strtolower(trim($node->getAttribute('as'))) === 'style') { $property = 'rel'; }
        if ($property === '') { return false; }
        $tokens = self::tokens($handler);
        if ($tokens === null) { return false; }
        $assignment = array(array('i', 'this'), array('p', '.'), array('i', $property), array('p', '='), array('s', $property === 'media' ? 'all' : 'stylesheet'));
        $clear = self::tokens('this.onload=null;');
        if (end($tokens) === array('p', ';')) { array_pop($tokens); }
        if ($tokens === $assignment) { return true; }
        return $tokens === array_merge($clear, $assignment) || $tokens === array_merge($assignment, array(array('p', ';')), array_slice($clear, 0, -1));
    }

    private static function template(array $tokens, string $template): bool {
        $expected = self::tokens($template); $bindings = array();
        if (end($tokens) === array('p', ';')) { array_pop($tokens); }
        if (end($expected) === array('p', ';')) { array_pop($expected); }
        if (count($tokens) !== count($expected)) { return false; }
        foreach ($expected as $i => $token) {
            if ($token[0] === 'i' && strpos($token[1], '$TK_') === 0) {
                $actual = $tokens[$i];
                if ($actual[0] !== 'i' || in_array($actual[1], array('window', 'document', 'eval', 'Function', 'location', 'fetch', 'this', 'return', 'var', 'const', 'let'), true)) { return false; }
                if (isset($bindings[$token[1]])) { if ($bindings[$token[1]] !== $actual[1]) { return false; } }
                else { if (in_array($actual[1], $bindings, true)) { return false; } $bindings[$token[1]] = $actual[1]; }
            } elseif ($token !== $tokens[$i]) { return false; }
        }
        return true;
    }

    /** Only entire approved token streams are suppressible. Unknown extra statements always fail. */
    public static function safe_loader(?array $tokens, string $origin, array $providers): string {
        if ($tokens === null || !$tokens || count($tokens) > 600) { return ''; }
        $urls = array(); $ids = array();
        foreach ($tokens as $token) {
            if ($token[0] !== 's') { continue; }
            if (self::provider($token[1], 'script', $origin, $providers) !== '') { $urls[] = $token[1]; }
            if (preg_match('/^(?:G|GT|AW|DC|UA|GTM)-[A-Z0-9-]{3,40}$/D', $token[1])) { $ids[] = $token[1]; }
        }
        if (count($urls) === 1) {
            $quoted = json_encode($urls[0]);
            foreach (array('const', 'let', 'var') as $declaration) {
                foreach (array('', '$TK_S.async=true;', '$TK_S.defer=true;') as $before) {
                    foreach (array('document.head', 'document.body', 'document.getElementsByTagName("head")[0]') as $parent) {
                        $template = $declaration . ' $TK_S=document.createElement("script");' . $before . '$TK_S.src=' . $quoted . ';' . $parent . '.appendChild($TK_S);';
                        if (self::template($tokens, $template)) { return self::provider($urls[0], 'script', $origin, $providers); }
                    }
                }
            }
        }
        foreach (array_slice(array_unique($ids), 0, 3) as $id) {
            if (strpos($id, 'GTM-') === 0 && isset($providers['google_analytics'])) {
                $template = '(function($TK_W,$TK_D,$TK_S,$TK_L,$TK_I){$TK_W[$TK_L]=$TK_W[$TK_L]||[];$TK_W[$TK_L].push({"gtm.start":new Date().getTime(),event:"gtm.js"});var $TK_F=$TK_D.getElementsByTagName($TK_S)[0],$TK_J=$TK_D.createElement($TK_S),$TK_DL=$TK_L!="dataLayer"?"&l="+$TK_L:"";$TK_J.async=true;$TK_J.src="https://www.googletagmanager.com/gtm.js?id="+$TK_I+$TK_DL;$TK_F.parentNode.insertBefore($TK_J,$TK_F);})(window,document,"script","dataLayer",' . json_encode($id) . ');';
                if (self::provider('https://www.googletagmanager.com/gtm.js?id=' . $id, 'script', $origin, $providers) !== '' && self::template($tokens, $template)) { return 'Google Tag Manager'; }
            }
            $template = 'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config",' . json_encode($id) . ');';
            if (self::provider('https://www.googletagmanager.com/gtag/js?id=' . $id, 'script', $origin, $providers) !== '' && self::template($tokens, $template)) { return 'Google Analytics'; }
        }
        return '';
    }

    public static function behavior(string $script, string $origin, array $trusted, array $providers): array {
        $tokens = self::tokens($script); $code = self::code($tokens, $script);
        $urls = array();
        if ($tokens !== null) { foreach ($tokens as $token) { if ($token[0] === 's') { preg_match_all('~(?:https?:)?//[^\s"\'<>`\\\\]+~i', $token[1], $matches); $urls = array_merge($urls, $matches[0]); } } }
        else { preg_match_all('~(?:https?:)?//[^\s"\'<>`\\\\]+~i', $script, $matches); $urls = $matches[0]; }
        $untrusted = ''; $insecure = false;
        foreach ($urls as $url) { $parts = self::url($url, $origin); if (!$parts) { continue; } if ($parts['scheme'] === 'http' && !self::same_origin($parts, $origin)) { $insecure = true; } if (!self::same_origin($parts, $origin) && !in_array($parts['host'], $trusted, true)) { $untrusted = $parts['host']; } }
        $network = (bool) preg_match('/\b(?:fetch|sendBeacon|XMLHttpRequest)\b|\bnew\s+Image\b|\.\s*src\s*=/', $code);
        $sensitive = (bool) preg_match('/\bdocument\s*\.\s*cookie\b|\b(?:localStorage|sessionStorage)\b/', $code);
        if (preg_match('/\.\s*value\b/', $code)) { foreach ($tokens ?? array() as $token) { if ($token[0] === 's' && preg_match('/password|csrf|token|secret|input\s*\[/i', $token[1])) { $sensitive = true; } } }
        $encoded = (bool) preg_match('/\b(?:eval|Function|write|writeln)\s*\(\s*(?:atob|unescape|decodeURIComponent|String\s*\.\s*fromCharCode)\s*\(/i', $code);
        $execution = (bool) preg_match('/\b(?:eval|Function)\s*\(/', $code);
        $input_expression = '(?:\blocation\s*\.\s*(?:hash|search|href)|\bdocument\s*\.\s*URL|\bURLSearchParams\b)';
        $input = (bool) preg_match('/(?:\b(?:eval|Function)\s*\(|\.\s*src\s*=)[^;]{0,400}' . $input_expression . '/', $code);
        $sensitive_expression = '(?:document\s*\.\s*cookie|(?:localStorage|sessionStorage)\b|\.\s*value\b)';
        $exfil = $sensitive && (bool) preg_match('/(?:\b(?:fetch|sendBeacon|send)\s*\(|\.\s*src\s*=)[^;]{0,400}' . $sensitive_expression . '/', $code);
        $dynamic = (bool) preg_match('/\bcreateElement\s*\(\s*(?:STRING|[a-zA-Z_$][a-zA-Z0-9_$]*)/', $code) && (strpos($script, 'script') !== false || $tokens === null || $input);
        // Decoded literals cover createElement("scr\x69pt") as well.
        if ($tokens !== null && preg_match('/\bcreateElement\s*\(/', $code)) { foreach ($tokens as $token) { if ($token === array('s', 'script')) { $dynamic = true; } } }
        return array('tokens' => $tokens, 'code' => $code, 'host' => $untrusted, 'network' => $network, 'sensitive' => $sensitive, 'exfil' => $exfil, 'encoded' => $encoded, 'execution' => $execution, 'input' => $input, 'dynamic' => $dynamic, 'insecure' => $insecure, 'provider' => self::safe_loader($tokens, $origin, $providers));
    }
}
