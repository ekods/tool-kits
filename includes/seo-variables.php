<?php
if (!defined('ABSPATH')) { exit; }

function tk_seo_variable_labels(): array {
    return array(
        '%%sitename%%' => 'Site title',
        '%%title%%' => 'Title',
        '%%primary_category%%' => 'Primary category',
        '%%sep%%' => 'Separator',
        '%%page%%' => 'Page',
        '%%excerpt%%' => 'Excerpt',
        '%%sitedesc%%' => 'Site description',
    );
}

function tk_seo_variable_values($post, int $page = 1): array {
    $defaults = tk_seo_content_defaults($post);
    $category = '';
    $terms = get_the_terms((int) $post->ID, 'category');
    if (is_array($terms) && $terms) {
        $primary = (int) get_post_meta((int) $post->ID, '_yoast_wpseo_primary_category', true);
        $category = (string) reset($terms)->name;
        foreach ($terms as $term) {
            if ((int) $term->term_id === $primary) { $category = (string) $term->name; break; }
        }
    }
    return array(
        '%%sitename%%' => tk_seo_title_text((string) get_bloginfo('name')),
        '%%title%%' => tk_seo_title_text((string) get_the_title((int) $post->ID)),
        '%%primary_category%%' => tk_seo_title_text($category),
        '%%sep%%' => tk_seo_title_separator(),
        '%%page%%' => $page > 1 ? 'Page ' . $page : '',
        '%%excerpt%%' => $defaults['description'],
        '%%sitedesc%%' => tk_seo_title_text((string) get_bloginfo('description')),
    );
}

/** Templates are plain text with a fixed token map; values are never evaluated. */
function tk_seo_expand_variables(string $template, int $post_id, ?array $values = null): string {
    if (strpos($template, '%%') === false) { return tk_seo_title_text($template); }
    if ($values === null) {
        $post = get_post($post_id);
        if (!is_object($post)) { return tk_seo_title_text($template); }
        $page = is_admin() ? 1 : max(1, (int) get_query_var('paged'), (int) get_query_var('page'));
        $values = tk_seo_variable_values($post, $page);
    }
    return trim((string) preg_replace('/\s+/u', ' ', tk_seo_title_text(strtr($template, $values))));
}

function tk_seo_preview_favicon(): string {
    $theme_icon = tk_seo_theme_option('themes_favicon', '');
    $candidates = array(is_scalar($theme_icon) ? trim((string) $theme_icon) : '', (string) get_site_icon_url(32));
    foreach ($candidates as $candidate) {
        $safe = esc_url_raw($candidate, array('http', 'https'));
        if ($safe !== '') { return $safe; }
    }
    if (function_exists('get_theme_file_path') && function_exists('get_theme_file_uri')) {
        foreach (array('assets/images/favicon/favicon-32x32.png', 'assets/images/favicon/favicon.png', 'assets/images/favicon.png', 'favicon.ico') as $relative) {
            if (is_file(get_theme_file_path($relative))) { return esc_url_raw(get_theme_file_uri($relative), array('http', 'https')); }
        }
    }
    return '';
}

function tk_seo_render_variable_tokens(string $value): void {
    $labels = tk_seo_variable_labels();
    foreach (preg_split('/(%%[a-z_]+%%)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
        if (isset($labels[$part])) {
            echo '<span class="tk-seo-token" contenteditable="false" data-token="' . esc_attr($part) . '">' . esc_html($labels[$part]) . '</span>';
        } else {
            echo esc_html($part);
        }
    }
}

function tk_seo_template_field(string $id, string $name, string $label, string $value, string $placeholder, string $help_id, bool $multiline = false): void {
    ?>
    <div class="tk-seo-template-field" data-template-field>
        <div class="tk-seo-field-heading"><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label><button type="button" class="button tk-seo-insert-variable" aria-haspopup="listbox" aria-expanded="false" aria-controls="<?php echo esc_attr($id); ?>-variables" hidden>Insert variable</button></div>
        <?php if ($multiline) : ?>
            <textarea class="tk-seo-template-source" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" rows="4" placeholder="<?php echo esc_attr($placeholder); ?>" aria-describedby="<?php echo esc_attr($help_id); ?>" hidden><?php echo esc_textarea($value); ?></textarea>
        <?php else : ?>
            <input class="tk-seo-template-source" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" type="text" value="<?php echo esc_attr($value); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" aria-describedby="<?php echo esc_attr($help_id); ?>" hidden>
        <?php endif; ?>
        <div class="tk-seo-token-input<?php echo $multiline ? ' tk-seo-token-multiline' : ''; ?>" id="<?php echo esc_attr($id); ?>-editor" data-token-display data-placeholder="<?php echo esc_attr($placeholder); ?>" role="textbox" aria-label="<?php echo esc_attr($label); ?>" aria-multiline="<?php echo $multiline ? 'true' : 'false'; ?>" aria-readonly="true" aria-describedby="<?php echo esc_attr($help_id); ?>"><?php tk_seo_render_variable_tokens($value); ?></div>
        <noscript><style>.tk-seo-post-editor .tk-seo-template-source[hidden]{display:block!important}.tk-seo-post-editor [data-token-display]{display:none!important}</style></noscript>
        <div class="tk-seo-variable-menu" id="<?php echo esc_attr($id); ?>-variables" role="listbox" aria-label="<?php echo esc_attr($label . ' variables'); ?>" hidden>
            <?php foreach (tk_seo_variable_labels() as $token => $text) : ?><button type="button" role="option" tabindex="-1" data-seo-variable="<?php echo esc_attr($token); ?>"><?php echo esc_html($text); ?></button><?php endforeach; ?>
        </div>
    </div>
    <?php
}
