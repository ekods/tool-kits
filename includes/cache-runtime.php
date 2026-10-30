<?php
/** Shared by WordPress and advanced-cache.php. No WordPress functions here. */
if (!defined('ABSPATH')) { return; }

final class TK_Page_Cache_Runtime {
    const FORMAT = 1;
    const NONCE = '__TOOL_KITS_CSP_NONCE__';

    public static function key(array $server): string {
        // Match WordPress is_ssl(), including the HTTPS=off + port=443 case.
        $ssl = isset($server['HTTPS'])
            ? (strtolower((string) $server['HTTPS']) === 'on' || (string) $server['HTTPS'] === '1')
            : (isset($server['SERVER_PORT']) && (string) $server['SERVER_PORT'] === '443');
        return md5(($ssl ? 'https' : 'http') . '|' . strtolower((string) ($server['HTTP_HOST'] ?? '')) . '|' . self::cache_uri((string) ($server['REQUEST_URI'] ?? '')));
    }

    /** Drop campaign/click parameters: every ad click carries a unique value, which made each one a cache miss and a new file. */
    public static function cache_uri(string $uri): string {
        $position = strpos($uri, '?');
        if ($position === false) { return $uri; }
        $kept = array();
        foreach (explode('&', (string) substr($uri, $position + 1)) as $pair) {
            if ($pair === '') { continue; }
            $name = urldecode((string) strtok($pair, '='));
            if (!self::dynamic_query(array($name => ''), self::allowed_query_keys())) { continue; }
            $kept[] = $pair;
        }
        return substr($uri, 0, $position) . ($kept ? '?' . implode('&', $kept) : '');
    }

    public static function allowed_query_keys(): array {
        return array('utm_*', 'gclid', 'fbclid', 'msclkid');
    }

    public static function cookie_markers(): array {
        return array('wordpress_logged_in_', 'wordpress_sec_', 'wp-postpass_', 'comment_author_', 'woocommerce_cart_hash', 'woocommerce_items_in_cart', 'wp_woocommerce_session_', 'edd_items_in_cart', 'easy_cart', 'cart', 'checkout', 'session', 'phpsessid');
    }

    public static function dynamic_query(array $query, array $allowed): bool {
        foreach ($query as $key => $value) {
            if (!is_scalar($value)) { return true; }
            $matched = false;
            foreach ($allowed as $pattern) {
                if ($key === $pattern || (substr($pattern, -1) === '*' && strpos((string) $key, substr($pattern, 0, -1)) === 0)) { $matched = true; break; }
            }
            if (!$matched) { return true; }
        }
        return false;
    }

    public static function dynamic_cookie(array $cookies, array $markers): bool {
        foreach (array_keys($cookies) as $name) {
            // Analytics cookies (Hotjar _hjSession, Google _ga, Clarity _clsk, ...) never personalize the page,
            // but their names contain generic markers such as "session".
            if (preg_match('/^(_hj|_ga|_gid|_gcl|_fbp|_fbc|_clck|_clsk|_pk_|mp_|_uet|_tt_)/i', (string) $name)) { continue; }
            foreach ($markers as $marker) {
                if ($marker !== '' && strpos(strtolower((string) $name), strtolower($marker)) !== false) { return true; }
            }
        }
        return false;
    }

    public static function request_allowed(array $config, array $server, array $cookies): bool {
        if (($server['REQUEST_METHOD'] ?? '') !== 'GET' || empty($server['HTTP_HOST']) || !empty($server['HTTP_AUTHORIZATION'])
            || isset($server['PHP_AUTH_USER']) || !empty($server['HTTP_RANGE'])) { return false; }
        $uri = (string) ($server['REQUEST_URI'] ?? '');
        if ($uri === '' || $uri[0] !== '/' || substr($uri, 0, 2) === '//' || preg_match('/[\x00-\x20\x7f\\\\]/', $uri)) { return false; }
        $path = explode('?', $uri, 2)[0];
        $decoded = rawurldecode($path);
        // Encoded delimiters/dot segments must pass through WordPress routing.
        if (preg_match('~(?:^|/)\.{1,2}(?:/|$)|[\\\\\x00-\x20\x7f%]~', $decoded)) { return false; }
        $path = rtrim($decoded, '/') ?: '/';
        foreach ($config['dynamic_paths'] ?? array() as $pattern) {
            $pattern = rtrim('/' . ltrim($pattern, '/'), '/') ?: '/';
            if ($pattern !== '/' && ($path === $pattern || strpos($path . '/', $pattern . '/') === 0)) { return false; }
        }
        foreach ($config['exclude_paths'] ?? array() as $excluded) {
            if ($excluded !== '' && strpos($path, $excluded) === 0) { return false; }
        }
        $query = array();
        parse_str(explode('?', $uri, 2)[1] ?? '', $query);
        return !self::dynamic_query($query, $config['allowed_query_keys'] ?? self::allowed_query_keys())
            && !self::dynamic_cookie($cookies, $config['cookie_markers'] ?? self::cookie_markers());
    }

    /** Strip real nonce attributes, preserving comments, JS/CSS and data-* text. */
    public static function html_nonce(string $html, string $nonce = ''): string {
        $attributes = '(?:"[^"]*"|\'[^\']*\'|[^\'">])*';
        $pattern = '~<!--.*?-->|<(script|style|textarea|title)(?=[\s>])' . $attributes . '>.*?</\1\s*>|<[a-zA-Z][a-zA-Z0-9:_-]*(?=[\s/>])' . $attributes . '>~is';
        return preg_replace_callback($pattern, function($match) use ($nonce, $attributes) {
            $token = $match[0];
            if (substr($token, 0, 4) === '<!--') { return $token; }
            if (!preg_match('~^<([a-zA-Z][a-zA-Z0-9:_-]*)\b(' . $attributes . ')>~s', $token, $opening)) { return $token; }
            $tag = preg_replace_callback('~\s+([^\s=/>]+)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?~s', function($attribute) {
                return strtolower($attribute[1]) === 'nonce' ? '' : $attribute[0];
            }, $opening[0]);
            if ($nonce !== '' && in_array(strtolower($opening[1]), array('script', 'style'), true)) {
                $tag = substr_replace($tag, ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"', 1 + strlen($opening[1]), 0);
            }
            return $tag . substr($token, strlen($opening[0]));
        }, $html) ?? $html;
    }

    public static function header_templates(array $headers): array {
        $result = array();
        $allowed = array('content-type', 'content-security-policy', 'content-security-policy-report-only', 'x-frame-options', 'x-content-type-options', 'x-xss-protection', 'referrer-policy', 'permissions-policy', 'strict-transport-security', 'cross-origin-opener-policy', 'cross-origin-embedder-policy', 'cross-origin-resource-policy', 'x-robots-tag', 'vary');
        foreach ($headers as $line) {
            $name = strtolower(trim(explode(':', $line, 2)[0]));
            if ((!in_array($name, $allowed, true) && !preg_match('/^cross-origin-[a-z-]+$/D', $name)) || preg_match('/[\r\n]/', $line)) { continue; }
            if (strpos($name, 'content-security-policy') === 0) {
                $line = preg_replace("~'nonce-[^']+'~i", "'nonce-" . self::NONCE . "'", $line);
            }
            $result[] = $line;
        }
        return $result;
    }

    public static function nonce_from_headers(array $headers): string {
        foreach ($headers as $line) {
            if (stripos($line, 'Content-Security-Policy') === 0 && preg_match("~'nonce-([A-Za-z0-9+/_=-]+)'~i", $line, $match)) { return $match[1]; }
        }
        return '';
    }

    public static function new_nonce(): string {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    public static function send_headers(array $templates, string $nonce, string $hit): void {
        if (headers_sent()) { return; }
        $seen = array();
        foreach (self::header_templates($templates) as $line) {
            $name = strtolower(explode(':', $line, 2)[0]);
            header(str_replace(self::NONCE, $nonce, $line), !isset($seen[$name]));
            $seen[$name] = true;
        }
        if (!isset($seen['content-type'])) { header('Content-Type: text/html; charset=UTF-8'); }
        header_remove('Content-Length');
        header_remove('ETag');
        header_remove('Last-Modified');
        // Browser/CDN revalidation is separate from the on-disk page-cache TTL.
        header('Cache-Control: public, max-age=0, must-revalidate');
        header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
        header('X-Tool-Kits-Cache: ' . $hit);
    }

    /** A hash binds the two atomic files; a purge generation rejects old pairs. */
    public static function read(string $path, int $ttl): ?array {
        clearstatcache(true, $path);
        if (!is_file($path) || ($ttl > 0 && time() - (int) @filemtime($path) > $ttl)) { return null; }
        $state = @fopen(dirname($path) . '/.generation', 'r');
        if (!$state || !flock($state, LOCK_SH)) { if ($state) { fclose($state); } return null; }
        try {
            $meta = json_decode((string) @file_get_contents($path . '.meta.json'), true);
            if (!is_array($meta) || ($meta['format'] ?? null) !== self::FORMAT || !is_array($meta['headers'] ?? null)
                || ($meta['generation'] ?? null) !== stream_get_contents($state)) { return null; }
            $html = @file_get_contents($path);
            if (!is_string($html) || !hash_equals((string) ($meta['sha256'] ?? ''), hash('sha256', $html))) { return null; }
            return array('html' => $html, 'headers' => $meta['headers']);
        } finally { flock($state, LOCK_UN); fclose($state); }
    }
}
