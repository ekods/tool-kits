<?php
if (!defined('ABSPATH')) { exit; }

function tk_lazy_load_init() {
    add_action('admin_post_tk_lazy_load_save', 'tk_lazy_load_save');
    add_filter('wp_get_attachment_image_attributes', 'tk_lazy_image_attributes', 10, 3);
    add_filter('embed_oembed_html', 'tk_lazy_oembed_iframe', 10, 4);
    add_filter('wp_video_shortcode', 'tk_lazy_video_shortcode', 10, 5);
    add_filter('script_loader_tag', 'tk_lazy_script_loader_tag', 10, 3);
    add_action('wp_enqueue_scripts', 'tk_lazy_frontend_script');
}

function tk_lazy_load_enabled(): bool {
    return (int) tk_get_option('lazy_load_enabled', 0) === 1;
}

function tk_lazy_image_attributes($attr, $attachment, $size) {
    if (!tk_lazy_load_enabled()) {
        return $attr;
    }
    if (!is_array($attr)) {
        $attr = array();
    }
    static $count = 0;
    $count++;
    $eager = max(0, (int) tk_get_option('lazy_load_eager_images', 2));
    if ($count <= $eager) {
        $attr['loading'] = 'eager';
        $attr['fetchpriority'] = 'high';
    } else {
        $attr['loading'] = 'lazy';
        $attr['fetchpriority'] = 'auto';
    }
    $attr['decoding'] = 'async';
    return $attr;
}

function tk_lazy_oembed_iframe($html, $url, $attr, $post_id) {
    if (!tk_lazy_load_enabled()) {
        return $html;
    }
    if (!tk_get_option('lazy_load_iframe_video', 1)) {
        return $html;
    }
    if (!is_string($html) || stripos($html, '<iframe') === false) {
        return $html;
    }
    $html = preg_replace('/\sloading=(["\']).*?\1/i', '', $html);
    $html = preg_replace('/\ssrc=(["\'])(.*?)\1/i', ' data-tk-lazy-src="$2" loading="lazy"', $html, 1);
    return $html;
}

function tk_lazy_video_shortcode($output, $atts, $video, $post_id, $library) {
    if (!tk_lazy_load_enabled()) {
        return $output;
    }
    if (!tk_get_option('lazy_load_iframe_video', 1)) {
        return $output;
    }
    if (!is_string($output) || stripos($output, '<video') === false) {
        return $output;
    }
    $output = preg_replace('/\spreload=(["\']).*?\1/i', '', $output);
    $output = preg_replace('/<video/i', '<video preload="none"', $output, 1);
    $output = preg_replace('/\ssrc=(["\'])(.*?)\1/i', ' data-tk-lazy-src="$2"', $output);
    $output = preg_replace('/\ssrcset=(["\'])(.*?)\1/i', ' data-tk-lazy-srcset="$2"', $output);
    return $output;
}

function tk_lazy_script_loader_tag($tag, $handle, $src) {
    if (!tk_lazy_load_enabled()) {
        return $tag;
    }
    if (is_admin()) {
        return $tag;
    }
    $delay = tk_lazy_handles_list('lazy_load_script_delay');
    $defer = tk_lazy_handles_list('lazy_load_script_defer');
    if (in_array($handle, $delay, true)) {
        $tag = preg_replace('/\stype=["\']text\/javascript["\']/', '', $tag);
        $tag = preg_replace('/\ssrc=["\'][^"\']+["\']/', '', $tag);
        $tag = str_replace('<script', '<script type="text/plain" data-tk-delay="1" data-tk-src="' . esc_url($src) . '"', $tag);
        return $tag;
    }
    if (in_array($handle, $defer, true) && strpos($tag, ' defer') === false) {
        return str_replace('<script ', '<script defer ', $tag);
    }
    return $tag;
}

function tk_lazy_handles_list(string $key): array {
    $raw = tk_get_option($key, '');
    if (!is_string($raw) || $raw === '') {
        return array();
    }
    $items = preg_split('/[\s,]+/', $raw);
    $items = array_filter(array_map('trim', $items));
    return array_values(array_unique($items));
}

function tk_lazy_frontend_script() {
    if (!tk_lazy_load_enabled() || is_admin()) {
        return;
    }
    wp_register_script('tool-kits-lazy', '', array(), TK_VERSION, true);
    wp_enqueue_script('tool-kits-lazy');
    $script = <<<'JS'
(function(){
    function loadNode(node) {
        var src = node.getAttribute('data-tk-lazy-src');
        var srcset = node.getAttribute('data-tk-lazy-srcset');
        if (src) {
            node.setAttribute('src', src);
            node.removeAttribute('data-tk-lazy-src');
        }
        if (srcset) {
            node.setAttribute('srcset', srcset);
            node.removeAttribute('data-tk-lazy-srcset');
        }
        if (node.tagName === 'VIDEO') {
            try { node.load(); } catch (e) {}
        }
    }

    var lazyNodes = [].slice.call(document.querySelectorAll('[data-tk-lazy-src], [data-tk-lazy-srcset]'));
    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function(entries){
            entries.forEach(function(entry){
                if (entry.isIntersecting) {
                    loadNode(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, { rootMargin: '200px 0px' });
        lazyNodes.forEach(function(node){ observer.observe(node); });
    } else {
        lazyNodes.forEach(loadNode);
    }

    var delayed = [].slice.call(document.querySelectorAll('script[data-tk-delay="1"]'));
    if (delayed.length) {
        var activated = false;
        function activate() {
            if (activated) { return; }
            activated = true;
            delayed.forEach(function(tag){
                var src = tag.getAttribute('data-tk-src');
                if (!src) { return; }
                var s = document.createElement('script');
                s.src = src;
                s.defer = true;
                document.head.appendChild(s);
            });
        }
        ['scroll','mousemove','keydown','touchstart','click'].forEach(function(evt){
            window.addEventListener(evt, activate, { once: true, passive: true });
        });
    }
})();
JS;
    wp_add_inline_script('tool-kits-lazy', $script);
}

function tk_render_lazy_load_panel() {
    if (!tk_is_admin_user()) return;
    $enabled = (int) tk_get_option('lazy_load_enabled', 0);
    $eager = (int) tk_get_option('lazy_load_eager_images', 2);
    $lazy_media = (int) tk_get_option('lazy_load_iframe_video', 1);
    $defer = (string) tk_get_option('lazy_load_script_defer', '');
    $delay = (string) tk_get_option('lazy_load_script_delay', '');
    ?>
    <div class="tk-card">
        <h2>Lazy Load Adaptif</h2>
        <p>Optimize loading by prioritizing above-the-fold content and delaying heavy assets.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_lazy_load_save'); ?>
            <input type="hidden" name="action" value="tk_lazy_load_save">
            <input type="hidden" name="tk_tab" value="lazy-load">
            <p>
                <label>
                    <input type="checkbox" name="lazy_load_enabled" value="1" <?php checked(1, $enabled); ?>>
                    Enable Lazy Load Adaptif
                </label>
            </p>
            <p>
                <label>Above-fold eager images</label><br>
                <input type="number" min="0" name="lazy_load_eager_images" value="<?php echo esc_attr((string) $eager); ?>">
                <small>Number of first images to load eagerly.</small>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="lazy_load_iframe_video" value="1" <?php checked(1, $lazy_media); ?>>
                    Lazy load iframe & video (below fold)
                </label>
            </p>
            <p>
                <label>Defer script handles (comma/space separated)</label><br>
                <input type="text" name="lazy_load_script_defer" value="<?php echo esc_attr($defer); ?>" class="large-text" placeholder="e.g. jquery-migrate, some-heavy-lib">
            </p>
            <p>
                <label>Delay script handles until interaction</label><br>
                <input type="text" name="lazy_load_script_delay" value="<?php echo esc_attr($delay); ?>" class="large-text" placeholder="e.g. analytics, chat-widget">
            </p>
            <p><button class="button button-primary">Save Settings</button></p>
        </form>
    </div>
    <?php
}

function tk_lazy_load_save() {
    tk_check_nonce('tk_lazy_load_save');
    tk_update_option('lazy_load_enabled', !empty($_POST['lazy_load_enabled']) ? 1 : 0);
    tk_update_option('lazy_load_eager_images', max(0, (int) tk_post('lazy_load_eager_images', 2)));
    tk_update_option('lazy_load_iframe_video', !empty($_POST['lazy_load_iframe_video']) ? 1 : 0);
    tk_update_option('lazy_load_script_defer', sanitize_text_field((string) tk_post('lazy_load_script_defer', '')));
    tk_update_option('lazy_load_script_delay', sanitize_text_field((string) tk_post('lazy_load_script_delay', '')));
    wp_redirect(add_query_arg(array('page' => 'tool-kits-optimization', 'tk_tab' => 'lazy-load', 'tk_saved' => 1), admin_url('admin.php')));
    exit;
}
