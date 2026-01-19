<?php
if (!defined('ABSPATH')) { exit; }

function tk_render_spam_protection_page($forced_tab = '') {
    if (!tk_is_admin_user()) return;
    $allowed_tabs = array('captcha', 'antispam');
    $requested = isset($_GET['tk_tab']) ? sanitize_key($_GET['tk_tab']) : '';
    $active_tab = in_array($requested, $allowed_tabs, true) ? $requested : 'captcha';
    if ($forced_tab !== '' && in_array($forced_tab, $allowed_tabs, true)) {
        $active_tab = $forced_tab;
    }
    $saved = isset($_GET['tk_saved']) ? sanitize_key($_GET['tk_saved']) : '';
    ?>
    <div class="wrap tk-wrap">
        <h1>Spam Protection</h1>
        <?php if ($saved === '1') : ?>
            <?php tk_notice('Settings saved.', 'success'); ?>
        <?php endif; ?>
        <div class="tk-tabs tk-spam-tabs">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'captcha' ? ' is-active' : ''; ?>" data-panel="captcha">Captcha</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'antispam' ? ' is-active' : ''; ?>" data-panel="antispam">Anti-spam Contact</button>
            </div>
            <div class="tk-tabs-content">
                <div class="tk-tab-panel<?php echo $active_tab === 'captcha' ? ' is-active' : ''; ?>" data-panel-id="captcha">
                    <?php tk_render_captcha_panel(); ?>
                </div>
                <div class="tk-tab-panel<?php echo $active_tab === 'antispam' ? ' is-active' : ''; ?>" data-panel-id="antispam">
                    <?php tk_render_antispam_contact_panel(); ?>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var wrapper = document.querySelector('.tk-spam-tabs');
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
