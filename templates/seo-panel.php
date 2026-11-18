<?php
if (!defined('ABSPATH')) { exit; }

    if (!tk_is_admin_user()) return;

    $enabled = (int) tk_get_option('seo_enabled', 0);
    $meta_desc = (int) tk_get_option('seo_meta_desc_enabled', 1);
    $canonical = (int) tk_get_option('seo_canonical_enabled', 1);
    $og = (int) tk_get_option('seo_og_enabled', 1);
    $noindex_search = (int) tk_get_option('seo_noindex_search', 1);
    $noindex_404 = (int) tk_get_option('seo_noindex_404', 1);
    $noindex_paged = (int) tk_get_option('seo_noindex_paged_archives', 1);

    $has_third_party = tk_seo_has_third_party_plugin();
    $has_theme_managed = tk_seo_has_theme_managed_seo();
    $rules = tk_seo_get_redirect_rules();
    $suggestions = tk_seo_redirect_suggestions();
    $report = tk_get_option('seo_broken_links_report', array());
    $canonical_report = tk_get_option('seo_canonical_audit_report', array());
    $index_items = tk_seo_get_index_monitor_items();
    $audit_report = tk_get_option('seo_content_audit_report', array());
    if (!is_array($report)) {
        $report = array();
    }
    if (!is_array($canonical_report)) {
        $canonical_report = array();
    }
    if (!is_array($audit_report)) {
        $audit_report = array();
    }

    if (isset($_GET['tk_seo_redirect_error']) && sanitize_key((string) $_GET['tk_seo_redirect_error']) === '1') {
        tk_notice('Failed to add redirect rule. Please check source and target path.', 'error');
    }
    if (isset($_GET['tk_seo_redirect_added']) && sanitize_key((string) $_GET['tk_seo_redirect_added']) === '1') {
        tk_notice('Redirect rule added.', 'success');
    }
    if (isset($_GET['tk_seo_redirect_deleted']) && sanitize_key((string) $_GET['tk_seo_redirect_deleted']) === '1') {
        tk_notice('Redirect rule deleted.', 'success');
    }
    if (isset($_GET['tk_seo_links_scanned']) && sanitize_key((string) $_GET['tk_seo_links_scanned']) === '1') {
        tk_notice('Broken link scan completed.', 'success');
    }
    if (isset($_GET['tk_seo_links_cleared']) && sanitize_key((string) $_GET['tk_seo_links_cleared']) === '1') {
        tk_notice('Broken link report cleared.', 'success');
    }
    if (isset($_GET['tk_seo_canonical_scanned']) && sanitize_key((string) $_GET['tk_seo_canonical_scanned']) === '1') {
        tk_notice('Canonical audit completed.', 'success');
    }
    if (isset($_GET['tk_seo_canonical_cleared']) && sanitize_key((string) $_GET['tk_seo_canonical_cleared']) === '1') {
        tk_notice('Canonical audit cleared.', 'success');
    }
    if (isset($_GET['tk_seo_index_error']) && sanitize_key((string) $_GET['tk_seo_index_error']) === '1') {
        tk_notice('Failed to save indexing item. URL is required.', 'error');
    }
    if (isset($_GET['tk_seo_index_added']) && sanitize_key((string) $_GET['tk_seo_index_added']) === '1') {
        tk_notice('Indexing monitor item added.', 'success');
    }
    if (isset($_GET['tk_seo_index_deleted']) && sanitize_key((string) $_GET['tk_seo_index_deleted']) === '1') {
        tk_notice('Indexing monitor item deleted.', 'success');
    }
    if (isset($_GET['tk_seo_audit_scanned']) && sanitize_key((string) $_GET['tk_seo_audit_scanned']) === '1') {
        tk_notice('Content SEO audit completed.', 'success');
    }
    if (isset($_GET['tk_seo_audit_cleared']) && sanitize_key((string) $_GET['tk_seo_audit_cleared']) === '1') {
        tk_notice('Content SEO audit report cleared.', 'success');
    }
    if (!empty($_GET['tk_global_saved'])) { tk_notice('Global SEO content saved. Theme-managed values remain linked to their source.', 'success'); }
    $tk_seo_url = tk_admin_url('tool-kits-seo');
    $tk_override_link = '<a href="' . esc_url($tk_seo_url . '#tk-seo-global-enabled') . '">Enable Global Metadata Override &rarr;</a>';
    if ($has_third_party) {
        tk_notice('Third-party SEO plugin detected. Enable Global Metadata Override to replace its head metadata with Tool Kits values, or Meta Title Override for titles only. JSON-LD Schema is managed from GEO.<br>' . $tk_override_link . ' &middot; <a href="' . esc_url($tk_seo_url . '#tk-seo-title-override') . '">Enable Meta Title Override &rarr;</a>', 'warning');
    }
    if ($has_theme_managed) {
        tk_notice('Active theme manages SEO head output. Global Metadata Override replaces its head metadata with the values in Global SEO Content when enabled. Canonical tags remain managed by the theme.<br>' . $tk_override_link, 'warning');
    }
    ?>
    <div class="tk-tabs tk-seo-tabs">
        <div class="tk-tabs-nav">
            <button type="button" class="tk-tabs-nav-button is-active" data-panel="settings">Settings</button>
            <button type="button" class="tk-tabs-nav-button" data-panel="global-content">Global SEO Content</button>
            <button type="button" class="tk-tabs-nav-button" data-panel="redirects">Redirects</button>
            <button type="button" class="tk-tabs-nav-button" data-panel="canonical">Canonical</button>
            <button type="button" class="tk-tabs-nav-button" data-panel="indexing">Indexing</button>
            <button type="button" class="tk-tabs-nav-button" data-panel="broken-links">Broken Links</button>
            <button type="button" class="tk-tabs-nav-button" data-panel="content-audit">Content Audit</button>
            <button type="button" class="tk-tabs-nav-button" data-panel="indexnow">IndexNow</button>
        </div>
        <div class="tk-tabs-content">
    <div class="tk-card tk-tab-panel is-active" data-panel-id="settings">
        <h3>Settings</h3>
        <p>Technical SEO toolkit: metadata, redirects, sitemap, and broken link checker.</p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_seo_opt_save'); ?>
            <input type="hidden" name="action" value="tk_seo_opt_save">
            <input type="hidden" name="tk_tab" value="seo">

            <div style="margin-bottom:24px;">
                <?php tk_render_switch('seo_enabled', 'Enable SEO Toolkit', 'Unlock metadata management, redirects, and content auditing.', $enabled); ?>
            </div>

            <fieldset style="margin-bottom:24px;">
                <legend><strong>Editor Panels</strong></legend>
                <input type="hidden" name="tk_seo_panels_present" value="1">
                <p><label><input type="checkbox" name="seo_content_panel_enabled" value="1" <?php checked(tk_seo_editor_panel_visible('content')); ?>> Show Tool Kits SEO Content</label></p>
                <p><label><input type="checkbox" name="seo_geo_panel_enabled" value="1" <?php checked(tk_seo_editor_panel_visible('geo')); ?>> Show SEO / GEO controls</label></p>
                <p><label><input type="checkbox" name="seo_faq_panel_enabled" value="1" <?php checked(tk_seo_editor_panel_visible('faq')); ?>> Show FAQ GEO in Tool Kits SEO / GEO</label></p>
                <p><label><input type="checkbox" name="seo_list_score_enabled" value="1" <?php checked(tk_seo_list_score_enabled()); ?>> Show SEO score column in content lists</label></p>
                <p><label><input type="checkbox" name="seo_list_geo_enabled" value="1" <?php checked(tk_seo_list_geo_enabled()); ?>> Show GEO indicator column in content lists</label></p>
                <p class="description">SEO / GEO controls and FAQ GEO share one sidebar card; each section can be hidden separately. Choose whether content lists (wp-admin/edit.php) show the SEO score and GEO indicators. GEO uses the latest GEO Audit &amp; Fixes results. Hiding a section, panel or column preserves saved metadata and public SEO/GEO output.</p>
            </fieldset>

            <fieldset style="margin-bottom:24px;">
                <legend><strong>SEO / GEO Content Types</strong></legend>
                <input type="hidden" name="tk_seo_post_types_present" value="1">
                <?php $selected_types = tk_seo_selected_post_types(); ?>
                <?php foreach (tk_seo_editor_post_types() as $slug => $type) : ?>
                    <p><label>
                        <input type="checkbox" name="seo_geo_post_types[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $selected_types, true)); ?>>
                        <?php echo esc_html($type->labels->name); ?> <code><?php echo esc_html($slug); ?></code>
                        <?php if (!is_post_type_viewable($type)) : ?><span> — Section / non-public</span><?php endif; ?>
                    </label></p>
                <?php endforeach; ?>
            </fieldset>

            <div class="tk-grid tk-grid-2" style="gap:24px;">
                <div style="background:var(--tk-bg-soft); padding:20px; border-radius:16px; border:1px solid var(--tk-border-soft);">
                    <h4 style="margin-top:0; color:var(--tk-primary); font-size:14px; margin-bottom:16px;">Meta Markup</h4>
                    <div style="display:flex; flex-direction:column; gap:16px;">
                        <?php
                        tk_render_switch('seo_meta_desc_enabled', 'Auto Meta Description', 'Generate meta tags from content excerpts.', $meta_desc);
                        tk_render_switch('seo_canonical_enabled', 'Canonical Tags', 'Prevent duplicate content issues.', $canonical);
                        tk_render_switch('seo_og_enabled', 'Open Graph & Social Images', 'Output Open Graph, Twitter Cards, Image Source and Schema.org image metadata using the SEO social image.', $og);
                        ?>
                        <p class="description" style="margin:0;">JSON-LD Schema settings have moved to <a href="<?php echo esc_url(tk_admin_url('tool-kits-geo') . '#overview'); ?>">GEO Output</a>.</p>
                    </div>
                </div>

                <div style="background:var(--tk-bg-soft); padding:20px; border-radius:16px; border:1px solid var(--tk-border-soft);">
                    <h4 style="margin-top:0; color:var(--tk-primary); font-size:14px; margin-bottom:16px;">Robots & Indexing Control</h4>
                    <div style="display:flex; flex-direction:column; gap:16px;">
                        <?php
                        tk_render_switch('seo_noindex_search', 'Noindex Search', 'Prevent search results from being indexed.', $noindex_search);
                        tk_render_switch('seo_noindex_404', 'Noindex 404 Pages', 'Prevent error pages from appearing in search.', $noindex_404);
                        tk_render_switch('seo_noindex_paged_archives', 'Noindex Paged Archives', 'Noindex /page/2/ and subsequent pages.', $noindex_paged);
                        ?>
                    </div>
                </div>
            </div>

            <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                <button class="button button-primary button-hero">Save SEO Settings</button>
            </div>
        </form>
    </div>

    <?php tk_seo_global_render_panel(); ?>
    <div class="tk-card tk-tab-panel" data-panel-id="redirects">
        <h3>Redirect Manager</h3>
        <p>Create 301/302/410 redirects and use 404 logs as quick suggestions.</p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:14px;">
            <?php tk_nonce_field('tk_seo_redirect_add'); ?>
            <input type="hidden" name="action" value="tk_seo_redirect_add">
            <input type="hidden" name="tk_tab" value="seo">
            <p>
                <input type="text" name="redirect_from" placeholder="/old-url" class="regular-text">
                <input type="text" name="redirect_to" placeholder="/new-url or https://example.com" class="regular-text">
                <select name="redirect_status">
                    <option value="301">301</option>
                    <option value="302">302</option>
                    <option value="410">410</option>
                </select>
                <button class="button">Add Redirect</button>
            </p>
        </form>

        <?php if (!empty($rules)) : ?>
            <table class="widefat striped">
                <thead><tr><th>From</th><th>To</th><th>Status</th><th>Hits</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($rules as $rule) : ?>
                    <tr>
                        <td><code><?php echo esc_html((string) $rule['from']); ?></code></td>
                        <td><code><?php echo esc_html((string) $rule['to']); ?></code></td>
                        <td><?php echo esc_html((string) $rule['status']); ?><?php if ((int) $rule['enabled'] !== 1): ?> <span class="tk-badge">Disabled</span><?php endif; ?></td>
                        <td><?php echo esc_html((string) (int) $rule['hits']); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                <?php tk_nonce_field('tk_seo_redirect_delete'); ?>
                                <input type="hidden" name="action" value="tk_seo_redirect_delete">
                                <input type="hidden" name="id" value="<?php echo esc_attr((string) $rule['id']); ?>">
                                <button class="button button-small" onclick="return confirm('Delete redirect?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p class="description">No redirect rules yet.</p>
        <?php endif; ?>

        <?php if (!empty($suggestions)) : ?>
            <hr style="margin:16px 0;">
            <p><strong>404 Suggestions</strong></p>
            <table class="widefat striped">
                <thead><tr><th>Path</th><th>Hits</th><th>Quick Add 301</th></tr></thead>
                <tbody>
                <?php foreach ($suggestions as $s) : ?>
                    <tr>
                        <td><code><?php echo esc_html((string) $s['path']); ?></code></td>
                        <td><?php echo esc_html((string) $s['count']); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; gap:6px; align-items:center;">
                                <?php tk_nonce_field('tk_seo_redirect_add'); ?>
                                <input type="hidden" name="action" value="tk_seo_redirect_add">
                                <input type="hidden" name="redirect_from" value="<?php echo esc_attr((string) $s['path']); ?>">
                                <input type="hidden" name="redirect_status" value="301">
                                <input type="text" name="redirect_to" placeholder="/target-url" class="regular-text">
                                <button class="button button-small">Add</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="tk-card tk-tab-panel" data-panel-id="canonical">
        <h3>Canonical Conflict Checker</h3>
        <p>Scan homepage and recent content for missing or duplicate canonical tags.</p>
        <div class="tk-seo-audit-actions">
            <?php if (!empty($audit_report['items'])) : ?>
                <button type="button" class="button button-primary" id="tk-seo-fix-all" style="margin-right:8px">Fix All</button>
                <p class="description">Fill missing excerpts from existing content and restore image alt from attachment metadata. Existing text and custom fields are preserved. Keywords, links, client outcomes and content length require editorial review.</p>
                <p id="tk-seo-fix-status" role="status" aria-live="polite"></p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:8px;">
                <?php tk_nonce_field('tk_seo_canonical_scan'); ?>
                <input type="hidden" name="action" value="tk_seo_canonical_scan">
                <button class="button">Run Canonical Audit</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                <?php tk_nonce_field('tk_seo_canonical_clear'); ?>
                <input type="hidden" name="action" value="tk_seo_canonical_clear">
                <button class="button">Clear Audit</button>
            </form>
        </div>
        <?php if (!empty($canonical_report)) : ?>
            <p class="description">
                Last scan: <?php echo !empty($canonical_report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $canonical_report['scanned_at'])) : '-'; ?> |
                Third-party SEO plugin: <?php echo !empty($canonical_report['third_party_detected']) ? 'Detected' : 'Not detected'; ?>
            </p>
            <?php $canonical_items = isset($canonical_report['items']) && is_array($canonical_report['items']) ? $canonical_report['items'] : array(); ?>
            <?php if (!empty($canonical_items)) : ?>
                <table class="widefat striped">
                    <thead><tr><th>URL</th><th>Status</th><th>Canonical</th><th>Issue</th></tr></thead>
                    <tbody>
                    <?php foreach ($canonical_items as $item) : ?>
                        <?php $canonicals = isset($item['canonicals']) && is_array($item['canonicals']) ? $item['canonicals'] : array(); ?>
                        <tr>
                            <td><a href="<?php echo esc_url((string) ($item['url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($item['url'] ?? '')); ?></a></td>
                            <td><?php echo esc_html((string) ((int) ($item['status'] ?? 0))); ?></td>
                            <td>
                                <?php if (!empty($canonicals)) : ?>
                                    <?php foreach ($canonicals as $canonical_url) : ?>
                                        <code><?php echo esc_html((string) $canonical_url); ?></code><br>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo !empty($item['issue']) ? esc_html((string) $item['issue']) : '<span class="tk-badge tk-on">OK</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php else : ?>
            <p class="description">No canonical audit yet.</p>
        <?php endif; ?>
    </div>

    <div class="tk-card tk-tab-panel" data-panel-id="indexing">
        <?php tk_seo_indexability_panel(); ?>
        <hr>
        <h3>Indexing Monitor</h3>
        <p>Track index status per URL and use quick links to inspect in Google tools.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:14px;">
            <?php tk_nonce_field('tk_seo_index_add'); ?>
            <input type="hidden" name="action" value="tk_seo_index_add">
            <p>
                <input type="url" name="index_url" class="large-text" placeholder="<?php echo esc_attr(home_url('/sample-page')); ?>">
            </p>
            <p>
                <select name="index_status">
                    <option value="unknown">Unknown</option>
                    <option value="submitted">Submitted</option>
                    <option value="indexed">Indexed</option>
                    <option value="not_indexed">Not Indexed</option>
                    <option value="crawled_not_indexed">Crawled - Not Indexed</option>
                </select>
                <input type="text" name="index_note" class="regular-text" placeholder="Optional note">
                <button class="button">Add Item</button>
            </p>
        </form>
        <?php if (!empty($index_items)) : ?>
            <table class="widefat striped">
                <thead><tr><th>URL</th><th>Status</th><th>Updated</th><th>Check</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($index_items as $item) : ?>
                    <?php $url = (string) ($item['url'] ?? ''); ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html($url); ?></a>
                            <?php if (!empty($item['note'])) : ?>
                                <div class="description"><?php echo esc_html((string) $item['note']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(ucwords(str_replace('_', ' ', (string) ($item['status'] ?? 'unknown')))); ?></td>
                        <td><?php echo !empty($item['updated_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $item['updated_at'])) : '-'; ?></td>
                        <td>
                            <a href="<?php echo esc_url('https://www.google.com/search?q=' . rawurlencode('site:' . $url)); ?>" target="_blank" rel="noopener">site:</a>
                            |
                            <a href="<?php echo esc_url('https://search.google.com/test/rich-results?url=' . rawurlencode($url)); ?>" target="_blank" rel="noopener">test</a>
                        </td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                <?php tk_nonce_field('tk_seo_index_delete'); ?>
                                <input type="hidden" name="action" value="tk_seo_index_delete">
                                <input type="hidden" name="id" value="<?php echo esc_attr((string) ($item['id'] ?? '')); ?>">
                                <button class="button button-small" onclick="return confirm('Delete item?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p class="description">No indexing monitor items yet.</p>
        <?php endif; ?>
    </div>

    <div class="tk-card tk-tab-panel" data-panel-id="broken-links">
        <h3>Broken Link Checker</h3>
        <p>Scan internal links from latest published content and report HTTP errors.</p>
        <div class="tk-seo-audit-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:8px;">
                <?php tk_nonce_field('tk_seo_links_scan'); ?>
                <input type="hidden" name="action" value="tk_seo_links_scan">
                <button class="button">Run Scan</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                <?php tk_nonce_field('tk_seo_links_clear'); ?>
                <input type="hidden" name="action" value="tk_seo_links_clear">
                <button class="button">Clear Report</button>
            </form>
        </div>

        <?php if (!empty($report)) : ?>
            <p class="description">
                Last scan: <?php echo !empty($report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $report['scanned_at'])) : '-'; ?> |
                Posts: <?php echo esc_html((string) ((int) ($report['checked_posts'] ?? 0))); ?> |
                URLs: <?php echo esc_html((string) ((int) ($report['total_urls'] ?? 0))); ?> |
                Broken: <?php echo esc_html((string) ((int) ($report['broken_count'] ?? 0))); ?>
            </p>
            <?php $items = isset($report['items']) && is_array($report['items']) ? $report['items'] : array(); ?>
            <?php if (!empty($items)) : ?>
                <table class="widefat striped">
                    <thead><tr><th>Status</th><th>URL</th><th>Source Post</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $item) : ?>
                        <tr>
                            <td><?php echo esc_html((string) ((int) ($item['status'] ?? 0))); ?></td>
                            <td><a href="<?php echo esc_url((string) ($item['url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($item['url'] ?? '')); ?></a></td>
                            <td>
                                <?php $pid = (int) ($item['post_id'] ?? 0); ?>
                                <?php if (!empty($item['source_url'])) : ?>
                                    <a href="<?php echo esc_url((string) $item['source_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($item['post_title'] ?? 'Post')); ?></a>
                                    <?php if ($pid > 0) : ?>
                                        <br><a class="description" href="<?php echo esc_url(get_edit_post_link($pid)); ?>">Edit source</a>
                                    <?php endif; ?>
                                <?php elseif ($pid > 0) : ?>
                                    <a href="<?php echo esc_url(get_edit_post_link($pid)); ?>"><?php echo esc_html((string) ($item['post_title'] ?? 'Post')); ?></a>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="description">No broken internal links found in the latest scan.</p>
            <?php endif; ?>
        <?php else : ?>
            <p class="description">No scan report yet.</p>
        <?php endif; ?>
    </div>

    <div class="tk-card tk-tab-panel" data-panel-id="content-audit">
        <h3>Content SEO Audit</h3>
        <p class="description">Latest 200 published entries. Stored-content checks may omit page-builder fields and theme output. Review statuses require live verification.</p>
        <div class="tk-seo-audit-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:8px;">
                <?php tk_nonce_field('tk_seo_content_audit_scan'); ?>
                <input type="hidden" name="action" value="tk_seo_content_audit_scan">
                <button class="button">Run Audit</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                <?php tk_nonce_field('tk_seo_content_audit_clear'); ?>
                <input type="hidden" name="action" value="tk_seo_content_audit_clear">
                <button class="button">Clear Audit</button>
            </form>
        </div>

        <?php $fix_report = tk_get_option('seo_content_fix_report', array()); ?>
        <?php if (is_array($fix_report) && !empty($fix_report['items'])) : ?>
            <details style="margin-top:12px;"><summary>Last Fix All results</summary>
                <ul><?php foreach ($fix_report['items'] as $fix_item) : ?>
                    <li><?php echo esc_html(sprintf('Post #%d: %s', (int) $fix_item['post_id'], implode('; ', (array) $fix_item['messages']))); ?></li>
                <?php endforeach; ?></ul>
            </details>
        <?php endif; ?>
        <script>
        (function () {
            var button = document.getElementById('tk-seo-fix-all');
            if (!button) return;
            var status = document.getElementById('tk-seo-fix-status');
            var ids = <?php echo wp_json_encode(array_values(array_unique(array_filter(array_map(function ($item) { return (int) ($item['post_id'] ?? 0); }, (array) ($audit_report['items'] ?? array())))))); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('tk_seo_content_fix')); ?>;
            function send(action, id) {
                var data = new URLSearchParams({action: action, nonce: nonce, post_id: String(id || 0)});
                return fetch(ajaxurl, {method: 'POST', credentials: 'same-origin', body: data}).then(function (response) {
                    if (!response.ok) throw new Error('Request failed (' + response.status + ').');
                    return response.json();
                }).then(function (result) {
                    if (!result.success) throw new Error(result.data && result.data.message || 'Fix failed.');
                    return result.data;
                });
            }
            button.addEventListener('click', async function () {
                if (button.disabled) return;
                button.disabled = true;
                try {
                    for (var i = 0; i < ids.length; i++) {
                        status.textContent = 'Fixing ' + (i + 1) + ' / ' + ids.length + '…';
                        var result = await send('tk_seo_content_fix', ids[i]);
                        status.textContent = result.messages.join('; ');
                    }
                    status.textContent = 'Updating audit scores…';
                    await send('tk_seo_content_fix_finish');
                    window.location.hash = 'content-audit';
                    window.location.reload();
                } catch (error) {
                    status.textContent = error.message + ' Completed changes are saved. Click Fix All to retry.';
                    button.disabled = false;
                }
            });
        }());
        </script>

        <?php if (!empty($audit_report)) : ?>
            <?php
            $priority_counts = isset($audit_report['priority_counts']) && is_array($audit_report['priority_counts']) ? $audit_report['priority_counts'] : array();
            $critical_count = (int) ($priority_counts['critical'] ?? 0);
            $high_count = (int) ($priority_counts['high'] ?? 0);
            $medium_count = (int) ($priority_counts['medium'] ?? 0);
            $low_count = (int) ($priority_counts['low'] ?? 0);
            $avg_score = (int) ($audit_report['average_score'] ?? 0);
            $avg_score_class = 'tk-priority-low';
            if ($avg_score < 50) {
                $avg_score_class = 'tk-priority-critical';
            } elseif ($avg_score < 70) {
                $avg_score_class = 'tk-priority-high';
            } elseif ($avg_score < 85) {
                $avg_score_class = 'tk-priority-medium';
            }
            ?>
            <p class="description">
                Last audit: <?php echo !empty($audit_report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $audit_report['scanned_at'])) : '-'; ?> |
                Checked: <?php echo esc_html((string) ((int) ($audit_report['checked_posts'] ?? 0))); ?> |
                Flagged: <?php echo esc_html((string) ((int) ($audit_report['flagged_count'] ?? 0))); ?> |
                Avg Score: <span class="tk-badge <?php echo esc_attr($avg_score_class); ?>"><?php echo esc_html((string) $avg_score); ?>/100</span>
            </p>
            <p class="description">
                Priority summary:
                Critical: <strong><?php echo esc_html((string) $critical_count); ?></strong>,
                High: <strong><?php echo esc_html((string) $high_count); ?></strong>,
                Medium: <strong><?php echo esc_html((string) $medium_count); ?></strong>,
                Low: <strong><?php echo esc_html((string) $low_count); ?></strong>
            </p>
            <?php if ($critical_count > 0 || $high_count > 0) : ?>
                <div class="tk-seo-priority-notice" role="status">
                    <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                    <p><strong>Priority action:</strong> focus on Critical and High pages first, then rerun audit after updates.</p>
                </div>
            <?php endif; ?>
            <?php $audit_items = isset($audit_report['items']) && is_array($audit_report['items']) ? $audit_report['items'] : array(); ?>
            <?php if (!empty($audit_items)) : ?>
                <div class="tk-table-scroll tk-content-audit-scroll" role="region" aria-label="Content SEO Audit — scrollable table" tabindex="0">
                <table class="widefat striped tk-content-audit-table">
                    <colgroup><col style="width:20%"><col style="width:6%"><col style="width:8%"><col style="width:6%"><col style="width:10%"><col style="width:16%"><col style="width:34%"></colgroup>
                    <thead><tr><th scope="col">Post</th><th scope="col">Score</th><th scope="col">Priority</th><th scope="col">Words</th><th scope="col">Links (Int/Ext)</th><th scope="col">Trust Signals</th><th scope="col">Issues</th></tr></thead>
                    <tbody>
                    <?php foreach ($audit_items as $item) : ?>
                        <?php $pid = (int) ($item['post_id'] ?? 0); ?>
                        <?php $score = (int) ($item['score'] ?? 0); ?>
                        <?php $priority = (string) ($item['priority'] ?? 'low'); ?>
                        <?php $trust = is_array($item['trust_signals'] ?? null) ? $item['trust_signals'] : array(); ?>
                        <?php $priority_class = 'tk-priority-low'; ?>
                        <?php if ($priority === 'critical') { $priority_class = 'tk-priority-critical'; } ?>
                        <?php if ($priority === 'high') { $priority_class = 'tk-priority-high'; } ?>
                        <?php if ($priority === 'medium') { $priority_class = 'tk-priority-medium'; } ?>
                        <tr>
                            <td>
                                <?php if ($pid > 0) : ?>
                                    <a href="<?php echo esc_url(get_edit_post_link($pid)); ?>"><?php echo esc_html((string) ($item['title'] ?? 'Post')); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html((string) ($item['title'] ?? 'Post')); ?>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo esc_html((string) $score); ?>/100</strong></td>
                            <td><span class="tk-badge <?php echo esc_attr($priority_class); ?>"><?php echo esc_html(ucfirst($priority)); ?></span></td>
                            <td><?php echo esc_html((string) ((int) ($item['words'] ?? 0))); ?></td>
                            <td><?php echo esc_html((string) ((int) ($item['internal_links'] ?? 0))); ?> / <?php echo esc_html((string) ((int) ($item['external_links'] ?? 0))); ?></td>
                            <td>
                                <span class="tk-badge <?php echo !empty($trust['author']) ? 'tk-on' : 'tk-off'; ?>">Author</span>
                                <span class="tk-badge <?php echo !empty($trust['author_bio']) ? 'tk-on' : 'tk-off'; ?>">Bio</span>
                                <span class="tk-badge <?php echo (!empty($trust['published_date']) && !empty($trust['modified_date'])) ? 'tk-on' : 'tk-off'; ?>">Dates</span>
                                <span class="tk-badge <?php echo !empty($trust['references']) ? 'tk-on' : 'tk-off'; ?>">Refs</span>
                                <span class="tk-badge <?php echo !empty($trust['related_content']) ? 'tk-on' : 'tk-off'; ?>">Related</span>
                            </td>
                            <td>
                                <?php
                                $issues = is_array($item['issues'] ?? null) ? $item['issues'] : array();
                                echo empty($issues) ? 'OK' : esc_html(implode('; ', $issues));
                                ?>
                                <?php if ($pid > 0 && current_user_can('edit_post', $pid)) : ?>
                                    <p><a class="button button-small" href="<?php echo esc_url(get_edit_post_link($pid) . '#tk-seo-content'); ?>">Tool Kits SEO Content</a></p>
                                <?php endif; ?>
                                <?php tk_seo_content_checks_render($item); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php else : ?>
                <p class="description">No issues found in latest audit.</p>
            <?php endif; ?>
        <?php else : ?>
            <p class="description">No audit report yet.</p>
        <?php endif; ?>
    </div>
    <?php if (function_exists('tk_indexnow_render_panel')) { tk_indexnow_render_panel(); } ?>
        </div>
    </div>
    <script>
    (function(){
        var wrapper = document.querySelector('.tk-seo-tabs');
        if (!wrapper) { return; }
        function activateTab(panelId) {
            wrapper.querySelectorAll('.tk-tab-panel').forEach(function(panel){
                panel.classList.toggle('is-active', panel.getAttribute('data-panel-id') === panelId);
            });
            wrapper.querySelectorAll('.tk-tabs-nav-button').forEach(function(button){
                button.classList.toggle('is-active', button.getAttribute('data-panel') === panelId);
            });
        }
        wrapper.querySelectorAll('.tk-tabs-nav-button').forEach(function(button){
            button.addEventListener('click', function(){
                var panelId = button.getAttribute('data-panel');
                if (panelId) {
                    activateTab(panelId);
                    if (history.replaceState) {
                        history.replaceState(null, '', '#' + panelId);
                    }
                }
            });
        });
        // A hash may name a tab or any element inside one (e.g. a setting); open its tab and bring it into view.
        function openHash() {
            var id = window.location.hash ? decodeURIComponent(window.location.hash.substring(1)) : '';
            if (!id) { return; }
            var panel = wrapper.querySelector('.tk-tab-panel[data-panel-id="' + CSS.escape(id) + '"]');
            if (panel) { activateTab(id); return; }
            var target = document.getElementById(id);
            panel = target && target.closest('.tk-tab-panel');
            if (!panel || !wrapper.contains(panel)) { return; }
            activateTab(panel.getAttribute('data-panel-id'));
            target.scrollIntoView({ block: 'center' });
            target.focus({ preventScroll: true });
        }
        openHash();
        window.addEventListener('hashchange', openHash);
    })();
    </script>
    <?php
