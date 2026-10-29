<?php
define('ABSPATH', '/tmp/');
require dirname(__DIR__) . '/includes/seo-language-canonical.php';
function esc_url($url) { return htmlspecialchars($url, ENT_QUOTES); }
function wp_parse_url($url, $part = -1) { return parse_url($url, $part); }
function verify($ok, $message) { if (!$ok) throw new RuntimeException($message); }

// Mirrors of Polylang 3.8 add_language_to_link() per URL mode.
function directory_mode(string $home, string $lang, bool $is_hidden_default, bool $rewrite = true): callable {
    return function (string $url) use ($home, $lang, $is_hidden_default, $rewrite) {
        $slug = $is_hidden_default ? '' : ($rewrite ? '' : 'language/') . $lang . '/';
        $root = preg_replace('#^https?://#', '://', rtrim($home, '/') . '/');
        return strpos($url, $root . $slug) === false ? preg_replace('#' . preg_quote($root, '#') . '#', $root . $slug, $url, 1) : $url;
    };
}
function subdomain_mode(string $lang, bool $is_hidden_default): callable {
    return function (string $url) use ($lang, $is_hidden_default) {
        return $is_hidden_default || strpos($url, '://' . $lang . '.') !== false ? $url : str_replace('://', '://' . $lang . '.', $url);
    };
}
function domain_mode(string $main_host, string $lang_host): callable {
    return function (string $url) use ($main_host, $lang_host) {
        return preg_replace('#://(' . preg_quote($main_host, '#') . ')($|/.*)#', '://' . $lang_host . '$2', $url);
    };
}
function query_mode(string $lang, bool $is_hidden_default): callable {
    return function (string $url) use ($lang, $is_hidden_default) {
        if ($is_hidden_default || preg_match('/[?&]lang=/', $url)) { return $url; }
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'lang=' . $lang;
    };
}

function head(string $tags): string {
    return '<html><head>' . $tags . '</head><body><a href="https://site.example/kontak/">x</a></body></html>';
}
function canonical_of(string $html): string {
    return preg_match('/<link rel="canonical" href="([^"]*)">/', $html, $m) ? html_entity_decode($m[1]) : '';
}
function run(string $canonical, callable $localize, string $request): string {
    return canonical_of(tk_seo_language_canonical_fix_html(head('<link rel="canonical" href="' . $canonical . '">'), $localize, $request));
}

$home = 'https://site.example/';

// Bilingual EN (default, hidden) + ID, directory mode.
$html = tk_seo_language_canonical_fix_html(head('<link rel="canonical" href="https://site.example/kontak/"><meta property="og:url" content="https://site.example/kontak/">'), directory_mode($home, 'id', false), 'https://site.example/id/kontak/');
verify(canonical_of($html) === 'https://site.example/id/kontak/', 'Bilingual ID canonical not restored');
verify(strpos($html, '<meta property="og:url" content="https://site.example/id/kontak/">') !== false, 'Bilingual ID og:url not restored');
verify(strpos($html, '<a href="https://site.example/kontak/">') !== false, 'Body links must stay untouched');
verify(run('https://site.example/contact/', directory_mode($home, 'en', true), 'https://site.example/contact/') === 'https://site.example/contact/', 'Hidden default language changed');
verify(run('https://site.example/contact/', directory_mode($home, 'id', false), 'https://site.example/id/kontak/') === 'https://site.example/contact/', 'Cross-language canonical rewritten');
verify(run('https://site.example/id/kontak/', directory_mode($home, 'id', false), 'https://site.example/id/kontak/') === 'https://site.example/id/kontak/', 'Correct canonical changed');

// Bilingual with the default language prefixed too (/en/ and /id/).
verify(run('https://site.example/about/', directory_mode($home, 'en', false), 'https://site.example/en/about/') === 'https://site.example/en/about/', 'Prefixed default language not restored');
verify(run('https://site.example/tentang/', directory_mode($home, 'id', false), 'https://site.example/id/tentang/') === 'https://site.example/id/tentang/', 'Second language not restored');

// /language/ base, subdirectory install, encoded ZH slug, slug sharing the language letters.
verify(run('https://site.example/kontak/', directory_mode($home, 'id', false, false), 'https://site.example/language/id/kontak/') === 'https://site.example/language/id/kontak/', 'Language base not restored');
verify(run('https://site.example/sub/kontak/', directory_mode('https://site.example/sub', 'id', false), 'https://site.example/sub/id/kontak/') === 'https://site.example/sub/id/kontak/', 'Subdirectory install not restored');
verify(run('https://site.example/%E8%81%94%E7%B3%BB/', directory_mode($home, 'zh', false), 'https://site.example/zh/%e8%81%94%e7%b3%bb/') === 'https://site.example/zh/%E8%81%94%E7%B3%BB/', 'Encoded ZH path not restored');
verify(run('https://site.example/idea/', directory_mode($home, 'id', false), 'https://site.example/id/idea/') === 'https://site.example/id/idea/', 'Slug sharing the prefix letters skipped');
verify(run('https://site.example/page/2/', directory_mode($home, 'id', false), 'https://site.example/id/page/2/') === 'https://site.example/id/page/2/', 'Paged canonical not restored');
verify(run('https://site.example/kontak/', directory_mode($home, 'id', false), 'https://site.example/id/kontak/?utm_source=x') === 'https://site.example/id/kontak/', 'Tracking parameters blocked the fix');

// Language set from content: URLs carry no language, so nothing matches and nothing changes.
verify(run('https://site.example/kontak/', directory_mode($home, 'id', false), 'https://site.example/kontak/') === 'https://site.example/kontak/', 'Content-language mode rewritten');

// Subdomain, multiple domains and plain permalinks (?lang=).
verify(run('https://site.example/kontak/', subdomain_mode('id', false), 'https://id.site.example/kontak/') === 'https://id.site.example/kontak/', 'Subdomain not restored');
verify(run('https://site.example/kontak/', subdomain_mode('en', true), 'https://site.example/kontak/') === 'https://site.example/kontak/', 'Subdomain default language changed');
verify(run('https://site.example/kontak/', domain_mode('site.example', 'site.co.id'), 'https://site.co.id/kontak/') === 'https://site.co.id/kontak/', 'Multiple domains not restored');
verify(run('https://site.example/?page_id=5', query_mode('id', false), 'https://site.example/?page_id=5&lang=id') === 'https://site.example/?page_id=5&lang=id', 'Query language not restored');
verify(run('https://site.example/?page_id=5', query_mode('id', false), 'https://site.example/?page_id=9&lang=id') === 'https://site.example/?page_id=5', 'Query mode rewrote another page');

// Guards.
verify(run('https://other.example/kontak/', directory_mode($home, 'id', false), 'https://site.example/id/kontak/') === 'https://other.example/kontak/', 'Foreign host rewritten');
$html = tk_seo_language_canonical_fix_html(head('<script>var c = \'<link rel="canonical" href="https://site.example/kontak/">\';</script><!-- <meta property="og:url" content="https://site.example/kontak/"> -->'), directory_mode($home, 'id', false), 'https://site.example/id/kontak/');
verify(strpos($html, '/id/') === false, 'Script or comment content rewritten');
$html = tk_seo_language_canonical_fix_html('<head></head><body><link rel="canonical" href="https://site.example/kontak/"></body>', directory_mode($home, 'id', false), 'https://site.example/id/kontak/');
verify(strpos($html, '/id/') === false, 'Tag outside head rewritten');

echo "PASS: bilingual (hidden and prefixed default), directory + /language/ base, subdirectory, subdomain, multiple domains, ?lang= mode, content-language mode, cross-language/foreign/script/comment/body guards\n";
