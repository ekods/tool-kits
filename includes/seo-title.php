<?php
if (!defined('ABSPATH')) { exit; }

function tk_seo_title_init(): void {
    add_filter('pre_get_document_title', 'tk_seo_title_filter', PHP_INT_MAX);
    add_filter('wp_title', 'tk_seo_title_filter', PHP_INT_MAX);
    // Inside the page-cache buffer, outside Global SEO/GEO completion buffers.
    add_action('template_redirect', 'tk_seo_title_start', 980);
}

function tk_seo_title_enabled(): bool {
    return !is_admin() && !wp_doing_ajax() && !is_feed()
        && !(defined('REST_REQUEST') && REST_REQUEST)
        && (int) tk_get_option('seo_title_override_enabled', 0) === 1 && tk_seo_tools_enabled();
}

function tk_seo_title_text(string $text): string {
    return trim(wp_strip_all_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

function tk_seo_title_separator(): string {
    $value = tk_get_option('seo_title_separator', '-');
    return is_string($value) && trim($value) !== '' ? trim($value) : '-';
}

/** Build from WordPress content, never from an already formatted SEO title. */
function tk_seo_title_value(): string {
    if (is_singular() && tk_seo_content_output_enabled()) {
        $custom = tk_seo_expand_variables((string) get_post_meta(get_queried_object_id(), '_tk_seo_title', true), (int) get_queried_object_id());
        if ($custom !== '') { return $custom; }
    }
    $site = tk_seo_title_text((string) get_bloginfo('name'));
    if (is_front_page()) {
        $parts = array($site, tk_seo_title_text((string) get_bloginfo('description')));
    } else {
        if (is_404()) {
            $page_title = __('Page not found', 'tool-kits');
        } elseif (is_search()) {
            $page_title = sprintf(__('Search Results for “%s”', 'tool-kits'), get_search_query(false));
        } elseif (is_singular() || is_home()) {
            $page_title = get_the_title(get_queried_object_id());
        } elseif (is_archive()) {
            $page_title = get_the_archive_title();
        } else {
            $page_title = '';
        }
        $parts = array(tk_seo_title_text((string) $page_title));
        $page_number = max((int) get_query_var('paged'), (int) get_query_var('page'));
        if ($page_number > 1 && !is_404()) {
            $parts[] = sprintf(__('Page %s', 'tool-kits'), number_format_i18n($page_number));
        }
        $parts[] = $site;
    }
    return implode(' ' . tk_seo_title_separator() . ' ', array_values(array_filter($parts, function($part) { return $part !== ''; })));
}

function tk_seo_title_filter($title) {
    if (!tk_seo_title_enabled()) { return $title; }
    $override = tk_seo_title_value();
    return $override !== '' ? esc_html($override) : $title;
}

function tk_seo_title_start(): void {
    if (!tk_seo_title_enabled() || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', array('GET', 'HEAD'), true)) { return; }
    $title = tk_seo_title_value();
    if ($title === '') { return; }
    ob_start(function($html) use ($title) {
        $status = http_response_code();
        if ($status >= 300 && $status !== 404) { return $html; }
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) { return $html; }
        }
        return tk_seo_title_replace_html($html, $title);
    });
}

/** Replace real head titles, including hardcoded/late theme and plugin output. */
function tk_seo_title_replace_html(string $html, string $title): string {
    $attributes = '(?:"[^"]*"|\'[^\']*\'|[^\'">])*';
    $pattern = '~<!--.*?-->|<(script|style|textarea|template|noscript|svg)(?=[\s>])' . $attributes . '>.*?</\1\s*>|<title(?=[\s>])' . $attributes . '>.*?</title\s*>|<head(?=[\s>])' . $attributes . '>|</head\s*>~is';
    preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE);
    $in_head = false; $closed = false; $replaced = false; $edits = array();
    $tag = '<title>' . esc_html($title) . '</title>';
    foreach ($matches[0] as $match) {
        if (preg_match('/^<head\b/i', $match[0])) { $in_head = true; continue; }
        if (preg_match('/^<\/head\s*>/i', $match[0])) {
            if ($in_head) {
                if (!$replaced) { $edits[] = array($match[1], 0, $tag . "\n"); }
                $closed = true;
            }
            break;
        }
        if ($in_head && preg_match('/^<title(?=[\s>])/i', $match[0])) {
            $edits[] = array($match[1], strlen($match[0]), $replaced ? '' : $tag);
            $replaced = true;
        }
    }
    if (!$closed) { return $html; }
    // Reverse offsets keep all unrelated HTML, scripts and schema byte-for-byte.
    foreach (array_reverse($edits) as $edit) { $html = substr_replace($html, $edit[2], $edit[0], $edit[1]); }
    return $html;
}

function tk_seo_title_posted_settings(): array {
    if (tk_post('tk_seo_title_settings_present', '') !== '1') { return array(); }
    $separator = tk_post('seo_title_separator', '-');
    if (!is_string($separator)) { wp_die('Enter a valid title separator.'); }
    $separator = trim(sanitize_text_field($separator));
    if ($separator === '') { $separator = '-'; }
    $length = function_exists('mb_strlen') ? mb_strlen($separator, 'UTF-8') : strlen($separator);
    if ($length > 10) { wp_die('Use a title separator of up to 10 characters.'); }
    return array(
        'seo_title_override_enabled' => tk_post('seo_title_override_enabled', '') === '1' ? 1 : 0,
        'seo_title_separator' => $separator,
    );
}

function tk_seo_title_render_settings(): void {
    $separator = tk_seo_title_separator();
    $site = tk_seo_title_text((string) get_bloginfo('name'));
    $tagline = tk_seo_title_text((string) get_bloginfo('description'));
    ?>
    <fieldset style="margin:20px 0; padding:16px; border:1px solid var(--tk-border-soft); border-radius:8px;">
        <legend><strong>Meta Title Override</strong></legend>
        <input type="hidden" name="tk_seo_title_settings_present" value="1">
        <p><label><input type="checkbox" role="switch" name="seo_title_override_enabled" value="1" <?php checked((int) tk_get_option('seo_title_override_enabled', 0), 1); ?>> Override theme and plugin titles</label></p>
        <p class="description">Requires Enable SEO Toolkit. Applies to all frontend pages independently of global metadata fallbacks. Replaces theme/plugin titles and keeps a single &lt;title&gt; tag. This format takes priority over the Homepage SEO title below. Saved page SEO titles take priority over this format.</p>
        <p><label for="tk-title-separator"><strong>Title separator</strong></label><br>
            <input id="tk-title-separator" name="seo_title_separator" type="text" maxlength="10" value="<?php echo esc_attr($separator); ?>" class="small-text">
            <span class="description">Examples: - | &ndash; &mdash; &bull; / (blank uses -)</span></p>
        <p class="description">Front page: Site Title + separator + WordPress Tagline<br>
            <code><?php echo esc_html($site . ($tagline !== '' ? ' ' . $separator . ' ' . $tagline : '')); ?></code></p>
        <p class="description">Other pages: Page Title + separator + Site Title<br>
            <code><?php echo esc_html('About Us ' . $separator . ' ' . $site); ?></code></p>
        <p><a href="<?php echo esc_url(admin_url('options-general.php')); ?>">Edit WordPress Site Title &amp; Tagline</a></p>
    </fieldset>
    <?php
}
