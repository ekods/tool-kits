<?php
if (!defined('ABSPATH')) { exit; }

function tk_render_optimization_page($forced_tab = '') {
    if (!tk_is_admin_user()) return;
    $allowed_tabs = array('hide-login', 'minify', 'webp', 'lazy-load');
    $requested = isset($_GET['tk_tab']) ? sanitize_key($_GET['tk_tab']) : '';
    $active_tab = in_array($requested, $allowed_tabs, true) ? $requested : 'hide-login';
    if ($forced_tab !== '' && in_array($forced_tab, $allowed_tabs, true)) {
        $active_tab = $forced_tab;
    }
    $saved = isset($_GET['tk_saved']) ? sanitize_key($_GET['tk_saved']) : '';
    $progress = isset($_GET['tk_webp_progress']) ? sanitize_text_field(wp_unslash($_GET['tk_webp_progress'])) : '';
    $done = isset($_GET['tk_webp_done']) ? sanitize_key($_GET['tk_webp_done']) : '';
    ?>
    <div class="wrap tk-wrap">
        <h1>Optimization</h1>
        <?php if ($saved === '1') : ?>
            <?php tk_notice('Settings saved.', 'success'); ?>
        <?php endif; ?>
        <?php if ($progress !== '') : ?>
            <?php tk_notice('WebP generation: ' . esc_html($progress), 'info'); ?>
        <?php endif; ?>
        <?php if ($done === '1') : ?>
            <?php tk_notice('WebP generation completed.', 'success'); ?>
        <?php endif; ?>
        <div class="tk-tabs tk-optimization-tabs">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'hide-login' ? ' is-active' : ''; ?>" data-panel="hide-login">Hide Login</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'minify' ? ' is-active' : ''; ?>" data-panel="minify">Minify</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'webp' ? ' is-active' : ''; ?>" data-panel="webp">Auto WebP</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'lazy-load' ? ' is-active' : ''; ?>" data-panel="lazy-load">Lazy Load</button>
            </div>
            <div class="tk-tabs-content">
                <div class="tk-tab-panel<?php echo $active_tab === 'hide-login' ? ' is-active' : ''; ?>" data-panel-id="hide-login">
                    <?php tk_render_hide_login_panel(); ?>
                </div>
                <div class="tk-tab-panel<?php echo $active_tab === 'minify' ? ' is-active' : ''; ?>" data-panel-id="minify">
                    <?php tk_render_minify_panel(); ?>
                </div>
                <div class="tk-tab-panel<?php echo $active_tab === 'webp' ? ' is-active' : ''; ?>" data-panel-id="webp">
                    <?php tk_render_webp_panel(); ?>
                </div>
                <div class="tk-tab-panel<?php echo $active_tab === 'lazy-load' ? ' is-active' : ''; ?>" data-panel-id="lazy-load">
                    <?php tk_render_lazy_load_panel(); ?>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var wrapper = document.querySelector('.tk-optimization-tabs');
            if (!wrapper) { return; }
            function activateTab(panelId) {
                wrapper.querySelectorAll('.tk-tab-panel').forEach(function(panel){
                    panel.classList.toggle('is-active', panel.getAttribute('data-panel-id') === panelId);
                });
                wrapper.querySelectorAll('.tk-tabs-nav-button').forEach(function(btn){
                    btn.classList.toggle('is-active', btn.getAttribute('data-panel') === panelId);
                });
            }
            function getPanelFromHash() {
                var hash = window.location.hash || '';
                if (!hash) { return ''; }
                return hash.replace('#', '');
            }
            wrapper.querySelectorAll('.tk-tabs-nav-button').forEach(function(button){
                button.addEventListener('click', function(){
                    var panelId = button.getAttribute('data-panel');
                    if (panelId) {
                        window.location.hash = panelId;
                        activateTab(panelId);
                    }
                });
            });
            var initial = getPanelFromHash();
            if (initial && wrapper.querySelector('.tk-tab-panel[data-panel-id="' + initial + '"]')) {
                activateTab(initial);
            }
        })();
        </script>
    </div>
    <?php
}
