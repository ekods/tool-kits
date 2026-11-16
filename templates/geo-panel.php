<?php
if (!defined('ABSPATH')) { exit; }

    if (!tk_is_admin_user()) return;

    $geo_audit_page = isset($_GET['page']) && sanitize_key((string) $_GET['page']) === 'tool-kits-geo-audit';

    $enabled = (int) tk_get_option('geo_enabled', 0);
    $seo_schema = (int) tk_get_option('seo_schema_enabled', 1);
    $seo_breadcrumb = (int) tk_get_option('seo_breadcrumb_enabled', 1);
    $custom_json = (string) tk_get_option('geo_custom_jsonld', '');
    $faq_enabled = (int) tk_get_option('geo_faq_enabled', 0);
    $faq_items = tk_geo_normalize_faq_items(tk_get_option('geo_faq_items', array()));
    $faq_default_language = tk_geo_normalize_language_tag((string) get_bloginfo('language'), 'id') ?: 'id';
    $faq_languages = array($faq_default_language);
    foreach ($faq_items as $faq_item) {
        $faq_language = tk_geo_normalize_language_tag($faq_item['language'] ?? '');
        if ($faq_language !== '') {
            $faq_languages[] = $faq_language;
        }
    }
    $faq_languages = tk_geo_sort_language_tags($faq_languages, tk_geo_bilingual_language_order());
    $itemlist_enabled = (int) tk_get_option('geo_itemlist_enabled', 0);
    $itemlist_name = (string) tk_get_option('geo_itemlist_name', '');
    $itemlist_description = (string) tk_get_option('geo_itemlist_description', '');
    $itemlist_post_type = sanitize_key((string) tk_get_option('geo_itemlist_post_type', 'post'));
    $itemlist_language_mode = tk_geo_itemlist_language_mode();
    $filter_post_type = isset($_GET['geo_post_type']) ? sanitize_key((string) wp_unslash($_GET['geo_post_type'])) : '';
    $itemlist_limit = max(1, min(50, (int) tk_get_option('geo_itemlist_limit', 10)));
    $selected_ids = tk_geo_selected_itemlist_ids();
    $post_types = tk_geo_public_post_types();
    if ($filter_post_type !== '' && isset($post_types[$filter_post_type])) {
        $itemlist_post_type = $filter_post_type;
    }
    if (!isset($post_types[$itemlist_post_type])) {
        $itemlist_post_type = 'post';
    }
    $selector_posts = tk_geo_posts_for_selector($itemlist_post_type);
    $preview = tk_geo_build_schema_documents();
    $preview_json = !empty($preview) ? wp_json_encode(count($preview) === 1 ? $preview[0] : $preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
    $ai_access_report = tk_get_option('geo_ai_access_report', array());
    if (!is_array($ai_access_report)) {
        $ai_access_report = array();
    }
    $schema_duplicate_report = tk_get_option('geo_schema_duplicate_report', array());
    if (!is_array($schema_duplicate_report)) {
        $schema_duplicate_report = array();
    }
    $schema_duplicate_fix_report = tk_get_option('geo_schema_duplicate_fix_report', array());
    if (!is_array($schema_duplicate_fix_report)) {
        $schema_duplicate_fix_report = array();
    }
    $crawler_preview = tk_get_option('geo_crawler_preview', array());
    if (!is_array($crawler_preview)) {
        $crawler_preview = array();
    }
    $crawler_agents = tk_geo_ai_crawler_agents();
    $llms_enabled = (int) tk_get_option('geo_llms_enabled', 0);
    $llms_mode = sanitize_key((string) tk_get_option('geo_llms_mode', 'auto'));
    $llms_manual = (string) tk_get_option('geo_llms_manual', '');
    $llms_sections = tk_geo_normalize_llms_sections(tk_get_option('geo_llms_sections', array()));
    $llms_preview = tk_geo_build_llms_text();
    $visibility_report = tk_get_option('geo_visibility_report', array());
    $visibility_report = is_array($visibility_report) ? $visibility_report : array();
    $ai_radar_report = tk_get_option('geo_ai_radar_report', array());
    $ai_radar_report = is_array($ai_radar_report) ? $ai_radar_report : array();
    $geo_report = tk_get_option('seo_geo_audit_report', array());
    $geo_report = is_array($geo_report) ? $geo_report : array();
    $prompt_preview_report = tk_get_option('geo_prompt_preview_report', array());
    $prompt_preview_report = is_array($prompt_preview_report) ? $prompt_preview_report : array();
    $geo_audit_has_report = !empty($geo_report['scanned_at']) || !empty($geo_report['items']);
    $geo_audit_score = (int) ($geo_report['average_score'] ?? 0);
    $duplicate_has_report = !empty($schema_duplicate_report['scanned_at']);
    $duplicate_issue_count = (int) ($schema_duplicate_report['issue_count'] ?? 0);
    $duplicate_types = tk_geo_schema_duplicate_types_from_report($schema_duplicate_report);
    $duplicate_seo_types = array_diff(tk_geo_toolkits_seo_schema_types(), array('BreadcrumbList'));
    $duplicate_fix_available = $enabled && (int) ($schema_duplicate_report['analysis_version'] ?? 0) === 2 && (
        ($seo_schema && array_intersect($duplicate_types, $duplicate_seo_types)) ||
        ($seo_breadcrumb && in_array('BreadcrumbList', $duplicate_types, true))
    );
    $duplicate_details_url = admin_url('admin.php?page=tool-kits-geo-audit') . '#schema-duplicates';
    $geo_audit_details_url = admin_url('admin.php?page=tool-kits-geo-audit') . '#geo-audit';
    $configured_document_count = count($preview);
    $faq_item_count = count($faq_items);
    $faq_language_count = count($faq_languages);
    while (count($faq_items) < 3) {
        $faq_items[] = array('question' => '', 'answer' => '');
    }
    while (count($llms_sections) < 1) {
        $llms_sections[] = array('title' => '', 'content' => '');
    }
    ?>
    <form id="tk-geo-crawler-preview-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php tk_nonce_field('tk_geo_crawler_preview'); ?>
        <input type="hidden" name="action" value="tk_geo_crawler_preview">
    </form>
    <form id="tk-geo-visibility-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php tk_nonce_field('tk_geo_visibility_scan'); ?>
        <input type="hidden" name="action" value="tk_geo_visibility_scan">
    </form>
    <form id="tk-geo-ai-radar-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php tk_nonce_field('tk_geo_ai_radar_scan'); ?><input type="hidden" name="action" value="tk_geo_ai_radar_scan"></form>
    <form id="tk-geo-crawler-fix-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php tk_nonce_field('tk_geo_crawler_fix'); ?>
        <input type="hidden" name="action" value="tk_geo_crawler_fix">
        <input type="hidden" name="crawler_fix_url" value="<?php echo esc_attr((string) ($crawler_preview['url'] ?? '')); ?>">
    </form>
    <form id="tk-geo-visibility-fix-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php tk_nonce_field('tk_geo_visibility_fix'); ?>
        <input type="hidden" name="action" value="tk_geo_visibility_fix">
        <input type="hidden" name="visibility_fix_url" value="<?php echo esc_attr((string) ($visibility_report['url'] ?? '')); ?>">
    </form>
    <form id="tk-geo-prompt-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php tk_nonce_field('tk_geo_prompt_preview'); ?>
        <input type="hidden" name="action" value="tk_geo_prompt_preview">
    </form>
    <form id="tk-geo-schema-duplicate-fix-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php tk_nonce_field('tk_geo_schema_duplicate_fix'); ?>
        <input type="hidden" name="action" value="tk_geo_schema_duplicate_fix">
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php tk_nonce_field('tk_geo_save'); ?>
        <input type="hidden" name="action" value="tk_geo_save">
        <input type="hidden" name="tk_geo_active_tab" id="tk-geo-active-tab" value="overview">

        <div class="tk-tabs tk-geo-tabs <?php echo $geo_audit_page ? 'tk-geo-page-audit' : 'tk-geo-page-setup'; ?>">
            <style>
                .tk-geo-page-setup :is([data-panel="geo-audit"],[data-panel="ai-visibility"],[data-panel="ai-radar"],[data-panel="prompt-preview"],[data-panel="schema-duplicates"],[data-panel="crawler-preview"],[data-panel="ai-access"]),
                .tk-geo-page-setup :is([data-panel-id="geo-audit"],[data-panel-id="ai-visibility"],[data-panel-id="ai-radar"],[data-panel-id="prompt-preview"],[data-panel-id="schema-duplicates"],[data-panel-id="crawler-preview"],[data-panel-id="ai-access"]),
                .tk-geo-page-audit :is([data-panel="overview"],[data-panel="custom-jsonld"],[data-panel="faqpage"],[data-panel="itemlist"],[data-panel="llms"],[data-panel="ai-catalog"],[data-panel="preview"]),
                .tk-geo-page-audit :is([data-panel-id="overview"],[data-panel-id="custom-jsonld"],[data-panel-id="faqpage"],[data-panel-id="itemlist"],[data-panel-id="llms"],[data-panel-id="ai-catalog"],[data-panel-id="preview"]) { display:none !important; }
            </style>
            <div class="tk-tabs-nav" role="tablist" aria-label="GEO feature tabs">
                <button type="button" class="tk-tabs-nav-button<?php echo $geo_audit_page ? '' : ' is-active'; ?>" data-panel="overview">Output</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="custom-jsonld">Custom JSON-LD</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="faqpage">FAQPage</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="itemlist">ItemList</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="llms">llms.txt</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="ai-catalog">ai-catalog.json</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $geo_audit_page ? ' is-active' : ''; ?>" data-panel="geo-audit">GEO Audit</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="ai-visibility">AI Visibility</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="ai-radar">AI Radar</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="prompt-preview">Prompt Preview</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="schema-duplicates">Duplicate Detector</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="crawler-preview">Crawler Preview</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="ai-access">AI Access Review</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="preview">Preview</button>
            </div>
            <div class="tk-tabs-content">
        <div class="tk-card tk-tab-panel<?php echo $geo_audit_page ? '' : ' is-active'; ?>" data-panel-id="overview">
            <h2>GEO Output</h2>
            <p>Enable structured data for AI answer engines and search features. JSON-LD is printed in the frontend head.</p>
            <dl class="tk-geo-overview-metrics">
                <div>
                    <dt>GEO Output</dt>
                    <dd class="<?php echo $enabled ? 'is-good' : 'is-muted'; ?>"><?php echo $enabled ? 'Enabled' : 'Disabled'; ?></dd>
                    <span><?php echo esc_html((string) $configured_document_count); ?> JSON-LD document<?php echo $configured_document_count === 1 ? '' : 's'; ?> configured</span>
                </div>
                <div>
                    <dt>FAQ Content</dt>
                    <dd class="<?php echo $faq_enabled && $faq_item_count > 0 ? 'is-good' : 'is-muted'; ?>"><?php echo esc_html((string) $faq_item_count); ?></dd>
                    <span><?php echo $faq_enabled ? 'Enabled' : 'Disabled'; ?> / <?php echo esc_html((string) $faq_language_count); ?> locale<?php echo $faq_language_count === 1 ? '' : 's'; ?></span>
                </div>
                <div>
                    <dt>GEO Audit</dt>
                    <dd class="<?php echo !$geo_audit_has_report ? 'is-muted' : ($geo_audit_score >= 80 ? 'is-good' : ($geo_audit_score >= 60 ? 'is-warn' : 'is-danger')); ?>"><a class="tk-geo-metric-link" href="<?php echo esc_url($geo_audit_details_url); ?>" aria-label="View GEO Audit results and run the audit"><?php echo $geo_audit_has_report ? esc_html((string) $geo_audit_score) . '%' : 'Not run'; ?></a></dd>
                    <span><?php echo $geo_audit_has_report ? esc_html((string) ((int) ($geo_report['issue_count'] ?? 0))) . ' issues in latest audit' : 'Run an audit to establish a baseline'; ?></span>
                    <span>Click to view URLs or run the audit.</span>
                </div>
                <div>
                    <dt>Duplicate Schema</dt>
                    <dd class="<?php echo !$duplicate_has_report ? 'is-muted' : ($duplicate_issue_count > 0 ? 'is-danger' : 'is-good'); ?>"><a class="tk-geo-metric-link" href="<?php echo esc_url($duplicate_details_url); ?>" aria-label="View Duplicate Schema details and Auto Fix options"><?php echo $duplicate_has_report ? ($duplicate_issue_count > 0 ? esc_html((string) $duplicate_issue_count) : 'Clear') : 'Not run'; ?></a></dd>
                    <span><?php echo $duplicate_has_report ? ($duplicate_issue_count > 0 ? 'URLs with potential duplicate entities or invalid schema' : 'No duplicate entity definitions detected') : 'Run Duplicate Detector to check'; ?></span>
                    <span>Click to view URLs and fix options.</span>
                </div>
            </dl>
            <?php tk_render_switch('geo_enabled', 'Enable GEO JSON-LD Output', 'Print enabled custom JSON-LD, FAQPage, and ItemList documents on public pages.', $enabled); ?>
            <div style="margin-top:16px;">
                <?php tk_render_switch('seo_schema_enabled', 'SEO JSON-LD Schema', 'Generate WebSite, Organization, WebPage, Article, and Service schema through GEO output.', $seo_schema); ?>
                <?php tk_render_switch('seo_breadcrumb_enabled', 'BreadcrumbList Schema', 'Generate a hierarchy-aware breadcrumb graph and connect it to WebPage.', $seo_breadcrumb); ?>
                <?php if (tk_seo_has_theme_managed_seo()) : ?>
                    <?php tk_render_switch('geo_schema_override_theme', 'Replace Theme JSON-LD', 'The active theme prints its own SEO schema. Output the Tool Kits schema instead and remove the theme JSON-LD; the theme meta tags stay.', (int) tk_get_option('geo_schema_override_theme', 0)); ?>
                <?php endif; ?>
                <p class="description">This setting was moved from SEO so all JSON-LD output is managed from GEO.</p>
                <p class="description">Schema types are detected automatically from the content type. No manual mapping is required.</p>
            </div>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="custom-jsonld">
            <h3>Custom JSON-LD</h3>
            <p class="description">Paste a complete JSON-LD object or graph. It must be valid JSON.</p>
            <div class="tk-schema-edit-layout tk-geo-schema-check" data-nonce="<?php echo esc_attr(wp_create_nonce('tk_geo_schema_check')); ?>" data-vocabulary="<?php echo esc_url(TK_URL . 'assets/schema/schemaorg-vocabulary.json?ver=' . rawurlencode((string) tk_asset_version('assets/schema/schemaorg-vocabulary.json'))); ?>">
                <div>
                    <textarea name="geo_custom_jsonld" rows="12" style="width:100%; max-width:100%; font-family:monospace;" placeholder='{"@context":"https://schema.org","@type":"Organization","name":"Example"}'><?php echo esc_textarea($custom_json); ?></textarea>
                    <p class="tk-inline">
                        <button type="submit" class="button" name="geo_custom_jsonld_generate" value="1">Generate JSON-LD Draft</button>
                    </p>
                    <p class="description">Schema nodes are validated as you type.</p>
                    <div class="tk-geo-schema-url">
                        <label for="tk-geo-schema-url">Test a live URL</label>
                        <div class="tk-inline">
                            <?php $schema_test_url = isset($_GET['tk_schema_test']) ? esc_url_raw(wp_unslash((string) $_GET['tk_schema_test'])) : ''; ?>
                            <input type="url" id="tk-geo-schema-url" class="regular-text" value="<?php echo esc_attr($schema_test_url !== '' ? $schema_test_url : home_url('/')); ?>" placeholder="https://example.com/page/"<?php echo $schema_test_url !== '' ? ' data-autorun="1"' : ''; ?>>
                            <button type="button" class="button tk-geo-schema-fetch">Run Test</button>
                        </div>
                        <p class="description">Checks every JSON-LD block on the page against the schema.org vocabulary, like validator.schema.org. Microdata and RDFa are not checked.</p>
                    </div>
                </div>
                <div>
                    <div class="tk-geo-schema-source"></div>
                    <p class="tk-geo-schema-summary" role="status" aria-live="polite"></p>
                    <div class="tk-sv-report"></div>
                </div>
            </div>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="faqpage">
            <h3>FAQPage</h3>
            <?php tk_render_switch('geo_faq_enabled', 'Enable FAQPage Schema', 'Generate FAQPage JSON-LD from the question and answer rows below.', $faq_enabled); ?>
            <p class="description">Use shortcode <code>[tool_kits_geo_faq]</code> on a page to render the same FAQ as visible structured content.</p>
            <details class="tk-geo-faq-import" style="margin-top:16px;" data-current-faq="<?php echo esc_attr(wp_json_encode($faq_items)); ?>">
                <summary><strong>Import FAQPage JSON-LD</strong></summary>
                <div style="margin-top:12px;">
                    <textarea name="geo_faq_import_json" rows="8" style="width:100%;max-width:100%;font-family:monospace;" placeholder='{"@context":"https://schema.org","@type":"FAQPage","inLanguage":"id-ID","mainEntity":[]}'></textarea>
                    <div class="tk-inline" style="align-items:end;">
                        <label>
                            Import to locale<br>
                            <select name="geo_faq_import_language">
                                <?php foreach ($faq_languages as $faq_language) : ?>
                                    <option value="<?php echo esc_attr($faq_language); ?>" <?php selected($faq_language, $faq_default_language); ?>><?php echo esc_html(strtoupper($faq_language)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Custom locale<br>
                            <input type="text" name="geo_faq_import_language_custom" placeholder="en-sg" style="width:90px;">
                        </label>
                        <label>
                            Mode<br>
                            <select name="geo_faq_import_mode">
                                <option value="merge">Update same questions, add new</option>
                                <option value="append">Append as new rows</option>
                                <option value="replace">Replace target locale FAQ</option>
                            </select>
                        </label>
                        <button type="submit" class="button button-secondary" name="geo_faq_import_submit" value="1">Import FAQPage</button>
                    </div>
                    <p class="description">Imported questions are saved to the selected locale. In update mode, the same question in that locale is replaced when the answer differs; missing questions are added.</p>
                </div>
            </details>
            <script>
            (function(){
                var importer = document.querySelector('.tk-geo-faq-import');
                if (!importer) { return; }
                var form = importer.closest('form');
                var button = importer.querySelector('button[name="geo_faq_import_submit"]');
                var textarea = importer.querySelector('textarea[name="geo_faq_import_json"]');
                var mode = importer.querySelector('select[name="geo_faq_import_mode"]');
                var language = importer.querySelector('select[name="geo_faq_import_language"]');
                var customLanguage = importer.querySelector('input[name="geo_faq_import_language_custom"]');
                function normalizeText(value) {
                    return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
                }
                function normalizeLanguage(value) {
                    return String(value || '').trim().toLowerCase().replace(/_/g, '-');
                }
                function isList(value) {
                    return Array.isArray(value);
                }
                function collectFaqItems(node, fallback, items) {
                    if (!node || typeof node !== 'object') { return; }
                    if (Array.isArray(node)) {
                        node.forEach(function(child){ collectFaqItems(child, fallback, items); });
                        return;
                    }
                    var types = Array.isArray(node['@type']) ? node['@type'] : [node['@type']];
                    if (types.indexOf('FAQPage') !== -1 && isList(node.mainEntity)) {
                        var pageLanguage = normalizeLanguage(node.inLanguage || fallback);
                        node.mainEntity.forEach(function(question){
                            if (!question || typeof question !== 'object') { return; }
                            var answer = question.acceptedAnswer || {};
                            if (Array.isArray(answer)) { answer = answer[0] || {}; }
                            var questionText = String(question.name || '').trim();
                            var answerText = String(answer.text || '').trim();
                            if (!questionText || !answerText) { return; }
                            items.push({
                                question: questionText,
                                answer: answerText,
                                language: normalizeLanguage(question.inLanguage || pageLanguage || fallback)
                            });
                        });
                    }
                    if (node['@graph']) { collectFaqItems(node['@graph'], fallback, items); }
                }
                function currentFormItems() {
                    if (!form) { return []; }
                    var questions = form.querySelectorAll('input[name="geo_faq_question[]"]');
                    var answers = form.querySelectorAll('textarea[name="geo_faq_answer[]"]');
                    var languages = form.querySelectorAll('input[name="geo_faq_language[]"]');
                    return Array.prototype.map.call(questions, function(question, index) {
                        return {
                            question: question.value,
                            answer: answers[index] ? answers[index].value : '',
                            language: languages[index] ? languages[index].value : ''
                        };
                    }).filter(function(item) {
                        return normalizeText(item.question) && String(item.answer || '').trim();
                    });
                }
                button.addEventListener('click', function(event){
                    var selectedMode = mode ? mode.value : 'merge';
                    var targetLanguage = normalizeLanguage(customLanguage && customLanguage.value ? customLanguage.value : (language ? language.value : ''));
                    if (selectedMode === 'replace') {
                        if (!window.confirm('Replace FAQ items in locale ' + targetLanguage.toUpperCase() + '? Other locales will be kept.')) {
                            event.preventDefault();
                        }
                        return;
                    }

                    var imported = [];
                    try {
                        collectFaqItems(JSON.parse(textarea.value || 'null'), targetLanguage, imported);
                    } catch (error) {
                        return;
                    }
                    imported = imported.map(function(item) {
                        item.language = targetLanguage || normalizeLanguage(item.language);
                        return item;
                    });

                    var current = currentFormItems();
                    try { current = JSON.parse(importer.dataset.currentFaq || '[]') || []; } catch (error) {}
                    current = currentFormItems().length ? currentFormItems() : current;
                    var currentByQuestion = {};
                    current.forEach(function(item) {
                        var key = normalizeLanguage(item.language) + '|' + normalizeText(item.question);
                        currentByQuestion[key] = String(item.answer || '').trim();
                    });

                    var changed = 0;
                    var duplicates = 0;
                    imported.forEach(function(item) {
                        var key = normalizeLanguage(item.language) + '|' + normalizeText(item.question);
                        if (!Object.prototype.hasOwnProperty.call(currentByQuestion, key)) { return; }
                        duplicates++;
                        if (String(currentByQuestion[key]).trim() !== String(item.answer || '').trim()) {
                            changed++;
                        }
                    });

                    if (selectedMode === 'merge' && changed > 0 && !window.confirm(changed + ' existing FAQ answer(s) in locale ' + targetLanguage.toUpperCase() + ' will be updated, and new questions will be added. Continue?')) {
                        event.preventDefault();
                    }
                    if (selectedMode === 'append' && duplicates > 0 && !window.confirm(duplicates + ' imported question(s) already exist in locale ' + targetLanguage.toUpperCase() + '. Append them as additional rows anyway?')) {
                        event.preventDefault();
                    }
                });
            })();
            </script>
            <div id="tk-geo-faq-rows" style="margin-top:16px;">
                <?php foreach ($faq_items as $index => $item) : ?>
                    <div class="tk-responsive-grid tk-geo-faq-row" style="display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1.5fr) auto; gap:12px; margin-bottom:12px; align-items:start;">
                        <div>
                            <input type="text" name="geo_faq_language[]" value="<?php echo esc_attr($item['language'] ?? ''); ?>" placeholder="Language: id, en, en-sg" aria-label="FAQ language" style="width:100%;margin-bottom:8px;">
                            <input type="text" name="geo_faq_question[]" value="<?php echo esc_attr((string) $item['question']); ?>" placeholder="Question" style="width:100%;">
                        </div>
                        <textarea name="geo_faq_answer[]" rows="2" placeholder="Answer"><?php echo esc_textarea((string) $item['answer']); ?></textarea>
                        <button type="button" class="button tk-geo-faq-remove">Remove</button>
                    </div>
                <?php endforeach; ?>
            <p class="description">Save up to 50 rows. Blank rows are ignored.</p>
            </div>
            <p><button type="button" class="button" id="tk-geo-faq-add">Add Question</button></p>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="itemlist">
            <h3>ItemList Generator</h3>
            <?php tk_render_switch('geo_itemlist_enabled', 'Enable ItemList Schema', 'Generate an ItemList from selected posts or the latest posts in the selected post type.', $itemlist_enabled); ?>
            <div class="tk-grid tk-grid-2" style="margin-top:16px;">
                <div>
                    <label>List Name</label>
                    <input type="text" name="geo_itemlist_name" value="<?php echo esc_attr($itemlist_name); ?>" placeholder="Featured articles">
                    <label>Description</label>
                    <textarea name="geo_itemlist_description" rows="3" placeholder="Optional list description"><?php echo esc_textarea($itemlist_description); ?></textarea>
                    <label>Post Type</label>
                    <select name="geo_itemlist_post_type" id="tk-geo-itemlist-post-type">
                        <?php foreach ($post_types as $type => $object) : ?>
                            <option value="<?php echo esc_attr((string) $type); ?>" <?php selected($itemlist_post_type, (string) $type); ?>>
                                <?php echo esc_html((string) ($object->labels->name ?? $type)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label>Fallback Limit</label>
                    <input type="number" min="1" max="50" class="small-text" name="geo_itemlist_limit" value="<?php echo esc_attr((string) $itemlist_limit); ?>">
                    <label>Bilingual Output</label>
                    <select name="geo_itemlist_language_mode">
                        <option value="single" <?php selected($itemlist_language_mode, 'single'); ?>>Generate selected posts only</option>
                        <option value="all" <?php selected($itemlist_language_mode, 'all'); ?>>Generate all translations</option>
                    </select>
                    <p class="description">If no posts are selected, the generator uses latest published content from the chosen post type.</p>
                </div>
                <div>
                    <p style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin:0 0 6px;">
                        <label style="margin:0;">Select Posts</label>
                        <span>
                            <button type="button" class="button button-small" id="tk-geo-itemlist-select-all">Select All</button>
                            <button type="button" class="button button-small" id="tk-geo-itemlist-clear">Clear</button>
                        </span>
                    </p>
                    <select id="tk-geo-itemlist-post-ids" name="geo_itemlist_post_ids[]" multiple size="12" style="width:100%; max-width:100%;">
                        <?php foreach ($selector_posts as $post) : ?>
                            <option value="<?php echo esc_attr((string) $post->ID); ?>" <?php selected(in_array((int) $post->ID, $selected_ids, true)); ?>>
                                <?php echo esc_html((get_the_title($post) ?: ('#' . (int) $post->ID)) . ' (' . $post->post_name . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Use Select All for every loaded item. In bilingual mode, "all translations" expands selected posts through Polylang/WPML translations when available.</p>
                </div>
            </div>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="llms" id="llms-txt">
            <h3>llms.txt Generator</h3>
            <p>Render a virtual <code>/llms.txt</code> for LLM crawlers. Auto mode can be edited: generate a draft, adjust the textarea, then save.</p>
            <?php tk_render_switch('geo_llms_enabled', 'Enable llms.txt', 'Serve a text/plain llms.txt document at the site root.', $llms_enabled); ?>
            <div class="tk-grid tk-grid-2" style="gap:16px; margin-top:16px;">
                <div>
                    <label>Mode</label>
                    <select name="geo_llms_mode">
                        <option value="auto" <?php selected($llms_mode, 'auto'); ?>>Auto generated, editable</option>
                        <option value="manual" <?php selected($llms_mode, 'manual'); ?>>Manual only</option>
                        <option value="hybrid" <?php selected($llms_mode, 'hybrid'); ?>>Auto + manual notes</option>
                    </select>
                    <p>
                        <label><input type="checkbox" name="geo_llms_include_itemlist" value="1" <?php checked(1, tk_get_option('geo_llms_include_itemlist', 1)); ?>> Include ItemList content</label>
                    </p>
                    <p>
                        <label><input type="checkbox" name="geo_llms_include_faq" value="1" <?php checked(1, tk_get_option('geo_llms_include_faq', 1)); ?>> Include FAQ content</label>
                    </p>
                    <p><a href="<?php echo esc_url(home_url('/llms.txt')); ?>" target="_blank" rel="noopener">Open llms.txt</a></p>
                    <p><button class="button" name="geo_llms_generate_draft" value="1">Generate Editable Draft</button></p>
                </div>
                <div>
                    <label>Editable llms.txt Content / Notes</label>
                    <textarea name="geo_llms_manual" rows="8" style="width:100%; max-width:100%; font-family:monospace;" placeholder="Generate a draft, then edit it here. Leave empty to use live auto generation."><?php echo esc_textarea($llms_manual); ?></textarea>
                </div>
            </div>
            <h4 style="margin-top:18px;">llms.txt Section Builder</h4>
            <p class="description">Add structured sections to the auto-generated llms.txt draft. These sections are included before curated content and FAQ.</p>
            <div id="tk-geo-llms-section-rows" style="margin-top:12px;">
                <?php foreach ($llms_sections as $section) : ?>
                    <div class="tk-responsive-grid tk-geo-llms-section-row" style="display:grid; grid-template-columns:minmax(180px,0.5fr) minmax(0,1.5fr) auto; gap:12px; margin-bottom:12px; align-items:start;">
                        <input type="text" name="geo_llms_section_title[]" value="<?php echo esc_attr((string) $section['title']); ?>" placeholder="Section title">
                        <textarea name="geo_llms_section_content[]" rows="3" placeholder="Section content"><?php echo esc_textarea((string) $section['content']); ?></textarea>
                        <button type="button" class="button tk-geo-llms-section-remove">Remove</button>
                    </div>
                <?php endforeach; ?>
                <p class="description">Blank rows are ignored. Use this for products, services, policies, editorial notes, or source priority hints.</p>
            </div>
            <p><button type="button" class="button" id="tk-geo-llms-section-add">Add Section</button></p>
            <label>Preview</label>
            <textarea readonly rows="12" style="width:100%; max-width:100%; font-family:monospace;"><?php echo esc_textarea($llms_preview); ?></textarea>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="ai-catalog" id="ai-catalog">
            <h3>ai-catalog.json Generator</h3>
            <p>Generate a document with <code>version</code>, <code>last_updated</code>, and a <code>catalog</code> array. Each entry includes id, name, provider, category, description, website, features, pricing, tags, and status.</p>
            <?php tk_render_switch('geo_ai_catalog_enabled', 'Enable ai-catalog.json', 'Serve the catalog at the public URL below.', (int) tk_get_option('geo_ai_catalog_enabled', 0)); ?>
            <input type="hidden" name="geo_ai_catalog_settings_present" value="1">
            <p><label for="tk-ai-catalog-mode"><strong>Catalog source</strong></label><br>
                <select id="tk-ai-catalog-mode" name="geo_ai_catalog_mode">
                    <option value="auto" <?php selected(tk_get_option('geo_ai_catalog_mode', 'auto'), 'auto'); ?>>Website + AI Radar (automatic)</option>
                    <option value="manual" <?php selected(tk_get_option('geo_ai_catalog_mode', 'auto'), 'manual'); ?>>Custom catalog (manual)</option>
                </select>
            </p>
            <p><label for="tk-ai-catalog-entries"><strong>Custom catalog entries (JSON)</strong></label></p>
            <textarea id="tk-ai-catalog-entries" name="geo_ai_catalog_entries" rows="16" class="large-text code"><?php echo esc_textarea((string) wp_json_encode(tk_get_option('geo_ai_catalog_entries', array()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></textarea>
            <p class="description">Generate Website + AI Radar fills this editor and selects Custom catalog so you can edit the result. Save your changes to publish them. Generating again replaces these entries with a fresh website and AI Radar catalog. You can also paste an entries array or a full document containing a catalog array. IDs must be unique; features and tags must be arrays.</p>
            <p><button type="submit" class="button" name="geo_ai_catalog_use_example" value="1">Use ChatGPT &amp; Claude Example</button></p>
            <p><code><?php echo esc_html(home_url('/ai-catalog.json')); ?></code></p>
            <p>
                <button type="submit" class="button button-primary" name="geo_ai_catalog_generate_auto" value="1">Generate Website + AI Radar</button>
                <button type="submit" class="button button-secondary" name="geo_ai_catalog_generate" value="1">Save &amp; Enable Catalog</button>
                <?php if (tk_geo_ai_catalog_enabled()) : ?>
                    <a class="button" href="<?php echo esc_url(home_url('/ai-catalog.json')); ?>" target="_blank" rel="noopener">View Catalog</a>
                    <a class="button" href="<?php echo esc_url(add_query_arg('download', '1', home_url('/ai-catalog.json'))); ?>">Download ai-catalog.json</a>
                <?php endif; ?>
            </p>
            <p class="description">Automatic mode combines this website's name, description, homepage and published public content with the platform list shared by AI Radar. Drafts, private posts, attachments, and password-protected content are excluded. Custom mode publishes only the entries you enter above.</p>
            <p class="description">For AI Radar entries, status is the last saved crawler access result for this website: eligible, partial, blocked, or unknown. Run AI Radar to refresh it; generate again to copy the new results into a custom catalog. Unknown means no matching result is available. Crawler access does not confirm indexing or citation. Pricing is left blank. Generation requires no external AI API calls.</p>
            <h4>Agentic Resource Discovery (ARD)</h4>
            <p>The separate ARD manifest uses the discovery schema checked by Lighthouse. It is served before 404 redirects and linked automatically from public pages.</p>
            <p><code><?php echo esc_html(home_url('/.well-known/ard.json')); ?></code><br>
                <code><?php echo esc_html(home_url('/.well-known/ai-catalog.json')); ?></code> (legacy discovery)</p>
            <p class="description">The ARD manifest publishes the site name with an empty entries array: this generator does not provide callable agents or tools. The content and AI product catalog above keeps its own format.</p>
            <?php if (tk_geo_ai_catalog_enabled()) : ?>
            <p><a class="button" href="<?php echo esc_url(home_url('/.well-known/ard.json')); ?>" target="_blank" rel="noopener">View ARD Manifest</a></p>
            <?php endif; ?>
            <?php if (tk_get_option('geo_ai_catalog_mode', 'auto') === 'manual') : ?>
            <p><label for="tk-ai-catalog-preview"><strong>Current JSON preview</strong></label></p>
            <textarea id="tk-ai-catalog-preview" readonly rows="16" class="large-text code"><?php echo esc_textarea(tk_geo_build_ai_catalog_json()); ?></textarea>
            <?php endif; ?>
        </div>

        <div class="tk-card tk-tab-panel<?php echo $geo_audit_page ? ' is-active' : ''; ?>" data-panel-id="geo-audit" id="geo-audit">
            <h3>GEO Audit</h3>
            <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_geo_clear_saved_results'), 'tk_geo_clear_saved_results')); ?>">Clear All Saved GEO Results</a></p>
            <p>Scan live pages for AI answer readiness: schema validity, entity completeness, semantic HTML, trust signals, and freshness.</p>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_seo_geo_audit_clear'), 'tk_seo_geo_audit_clear')); ?>">Clear GEO Audit</a>
            </p>
            <?php tk_geo_batch_controls(); ?>

            <?php if (!empty($geo_report)) : ?>
                <?php
                $geo_avg = (int) ($geo_report['average_score'] ?? 0);
                $geo_avg_class = 'tk-priority-low';
                if ($geo_avg < 50) {
                    $geo_avg_class = 'tk-priority-critical';
                } elseif ($geo_avg < 70) {
                    $geo_avg_class = 'tk-priority-high';
                } elseif ($geo_avg < 85) {
                    $geo_avg_class = 'tk-priority-medium';
                }
                $geo_items = isset($geo_report['items']) && is_array($geo_report['items']) ? $geo_report['items'] : array();
                ?>
                <p class="tk-report-actions">
                    <button type="button" class="button button-primary tk-geo-bulk-fix">Bulk Fix All</button>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_geo_audit_remove_all_fixes'), 'tk_geo_audit_remove_all_fixes')); ?>">Remove All Fixes</a>
                    <button type="button" class="button tk-geo-export-pdf" data-report-target="tk-geo-audit-report" data-report-title="GEO Audit Report">Export Report PDF</button>
                </p>
                <div id="tk-geo-audit-report">
                    <p>
                        <button type="button" class="button tk-geo-report-selected">Run Selected</button>
                        <button type="button" class="button tk-geo-retry-failed">Retry Failed</button>
                    </p>
                    <p class="tk-geo-report-status" role="status" aria-live="polite"></p>
                    <p class="description">
                        Last scan: <?php echo !empty($geo_report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $geo_report['scanned_at'])) : '-'; ?> |
                        Checked: <?php echo esc_html((string) ((int) ($geo_report['checked_urls'] ?? 0))); ?> |
                        Issues: <?php echo esc_html((string) ((int) ($geo_report['issue_count'] ?? 0))); ?> |
                        Avg GEO Score: <span class="tk-badge <?php echo esc_attr($geo_avg_class); ?>"><?php echo esc_html((string) $geo_avg); ?>/100</span>
                    </p>
                    <?php $geo_fix_summary = tk_geo_audit_fix_summary($geo_items); ?>
                    <?php if (!empty($geo_fix_summary)) : ?>
                        <?php $geo_fix_url_count = count(array_unique(array_merge(...array_column($geo_fix_summary, 'urls')))); ?>
                        <div class="tk-geo-fix-summary">
                            <h4>What to fix</h4>
                            <p class="description"><?php echo esc_html(sprintf('%d issue type%s across %d URL%s, most urgent first. Auto = Tool Kits can apply a fallback with Bulk Fix All; fixing the source is preferred.', count($geo_fix_summary), count($geo_fix_summary) === 1 ? '' : 's', $geo_fix_url_count, $geo_fix_url_count === 1 ? '' : 's')); ?></p>
                            <div class="tk-table-scroll">
                                <table class="widefat striped">
                                    <thead><tr><th>Issue</th><th>Priority</th><th>URLs</th><th>Fix</th><th>Solution</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($geo_fix_summary as $fix_row) : ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($fix_row['issue']); ?></strong> <span class="tk-badge">-<?php echo esc_html((string) $fix_row['penalty']); ?></span></td>
                                            <td><span class="tk-badge tk-priority-<?php echo esc_attr($fix_row['priority']); ?>"><?php echo esc_html(strtoupper($fix_row['priority'])); ?></span></td>
                                            <td>
                                                <details>
                                                    <summary><?php echo esc_html((string) count($fix_row['urls'])); ?></summary>
                                                    <ul class="tk-list"><?php foreach ($fix_row['urls'] as $fix_url) : ?><li><a href="<?php echo esc_url($fix_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($fix_url); ?></a></li><?php endforeach; ?></ul>
                                                </details>
                                            </td>
                                            <td><?php echo $fix_row['automatic'] ? '<span class="tk-badge tk-on">Auto</span>' : '<span class="tk-badge tk-warn">Manual</span>'; ?></td>
                                            <td><?php echo esc_html($fix_row['solution']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <details class="tk-geo-fix-prompt" open>
                                <summary><strong>Fix prompt</strong> <span class="description">Paste into an AI assistant or send to a developer.</span></summary>
                                <textarea id="tk-geo-fix-prompt" readonly rows="12"><?php echo esc_textarea(tk_geo_audit_fix_prompt($geo_fix_summary, $geo_report, home_url('/'))); ?></textarea>
                                <p><button type="button" class="button button-primary tk-geo-copy-prompt" data-target="tk-geo-fix-prompt">Copy Prompt</button> <span class="description tk-geo-copy-status" role="status" aria-live="polite"></span></p>
                            </details>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($geo_items)) : ?>
                        <h4>Results per URL</h4>
                        <p class="description tk-geo-scroll-hint">Scroll the table sideways to see Schema, Semantic, Freshness and Issues.</p>
                        <div class="tk-table-scroll">
                        <?php if (!empty($geo_report['incomplete'])) : ?><p>Audit belum lengkap: pemeriksaan berhenti karena batas waktu atau kegagalan koneksi. Jalankan ulang setelah konektivitas server diperiksa.</p><?php endif; ?>
                        <table class="widefat striped">
                            <thead><tr><th><input type="checkbox" class="tk-geo-report-all" aria-label="Select all report URLs"></th><th>URL</th><th>Status</th><th>Grade</th><th>Score</th><th>Schema</th><th>Semantic</th><th>Freshness</th><th>Issues</th></tr></thead>
                            <tbody>
                            <?php foreach ($geo_items as $item) : ?>
                                <?php
                                $schema_item = is_array($item['schema'] ?? null) ? $item['schema'] : array();
                                $semantic_item = is_array($item['semantic'] ?? null) ? $item['semantic'] : array();
                                $freshness_item = is_array($item['freshness'] ?? null) ? $item['freshness'] : array();
                                $item_score = (int) ($item['score'] ?? 0);
                                $item_score_class = 'tk-priority-low';
                                if ($item_score < 50) {
                                    $item_score_class = 'tk-priority-critical';
                                } elseif ($item_score < 70) {
                                    $item_score_class = 'tk-priority-high';
                                } elseif ($item_score < 85) {
                                    $item_score_class = 'tk-priority-medium';
                                }
                                ?>
                                <tr>
                                    <td><input type="checkbox" class="tk-geo-report-target" value="<?php echo esc_attr((string) ($item['url'] ?? '')); ?>" aria-label="<?php echo esc_attr('Select ' . ($item['url'] ?? 'URL')); ?>"></td>
                                    <td><a href="<?php echo esc_url((string) ($item['url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($item['url'] ?? '')); ?></a><?php if (!empty($item['fix_verification'])) : $verification = (array) $item['fix_verification']; ?><br><span class="tk-badge <?php echo ($verification['status'] ?? '') === 'verified' ? 'tk-on' : 'tk-warn'; ?>"><?php echo esc_html((string) ($verification['label'] ?? 'Fix checked')); ?></span><br><span class="description"><?php echo esc_html((string) ($verification['detail'] ?? '')); ?></span><?php endif; ?></td>
                                    <td><?php echo esc_html((string) ((int) ($item['status'] ?? 0))); ?></td>
                                    <td><strong><?php echo esc_html((string) ($item['grade'] ?? 'F')); ?></strong></td>
                                    <td><?php if (isset($item['score'])) : ?><span class="tk-badge <?php echo esc_attr($item_score_class); ?>"><?php echo esc_html((string) $item_score); ?>/100</span><?php else : ?>Not verified<?php endif; ?></td>
                                    <td>
                                        <span class="tk-badge <?php echo !empty($schema_item['valid']) ? 'tk-on' : 'tk-off'; ?>">JSON-LD</span>
                                        <span class="tk-badge <?php echo !empty($schema_item['entity_ok']) ? 'tk-on' : 'tk-off'; ?>">Entity</span>
                                        <?php if (!empty($schema_item['types']) && is_array($schema_item['types'])) : ?>
                                            <div style="margin-top:6px;"><?php echo tk_geo_render_schema_type_badges(array_slice($schema_item['types'], 0, 8), false); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="tk-badge <?php echo !empty($semantic_item['main']) ? 'tk-on' : 'tk-off'; ?>">Main</span>
                                        <span class="tk-badge <?php echo ((int) ($semantic_item['h1_count'] ?? 0)) === 1 ? 'tk-on' : 'tk-off'; ?>">H1</span>
                                        <span class="tk-badge <?php echo !empty($semantic_item['article_or_section']) ? 'tk-on' : 'tk-off'; ?>">Sections</span>
                                    </td>
                                    <td>
                                        <span class="tk-badge <?php echo !empty($freshness_item['published']) ? 'tk-on' : 'tk-off'; ?>">Published</span>
                                        <span class="tk-badge <?php echo !empty($freshness_item['modified']) ? 'tk-on' : 'tk-off'; ?>">Modified</span>
                                    </td>
                                    <td>
                                        <?php
                                        $issues = isset($item['issues']) && is_array($item['issues']) ? $item['issues'] : array();
                                        $solutions = isset($item['solutions']) && is_array($item['solutions']) ? $item['solutions'] : array();
                                        ?>
                                        <?php if (empty($issues)) : ?>
                                            <span class="tk-badge tk-on">OK</span>
                                        <?php else : ?>
                                            <ul class="tk-list tk-geo-issue-list">
                                                <?php foreach (array_slice($issues, 0, 8) as $issue_index => $issue) : ?>
                                                    <?php
                                                    $issue_data = tk_seo_geo_issue_data((string) $issue);
                                                    $solution = (string) ($solutions[$issue_index] ?? $issue_data['solution']);
                                                    $fix_url = !empty($issue_data['automatic']) ? wp_nonce_url(add_query_arg(array('action' => 'tk_geo_audit_fix', 'url' => (string) ($item['url'] ?? ''), 'issue' => (string) $issue), admin_url('admin-post.php')), 'tk_geo_audit_fix') : '';
                                                    $fix_status = is_array($item['fix_status'][$issue] ?? null) ? $item['fix_status'][$issue] : array();
                                                    $remove_url = wp_nonce_url(add_query_arg(array('action' => 'tk_geo_audit_remove_fix', 'url' => (string) ($item['url'] ?? '')), admin_url('admin-post.php')), 'tk_geo_audit_remove_fix');
                                                    ?>
                                                    <li style="margin-bottom:10px;">
                                                        <strong><?php echo esc_html((string) $issue); ?></strong>
                                                        <span class="tk-badge tk-priority-<?php echo esc_attr((string) $issue_data['priority']); ?>"><?php echo esc_html(strtoupper((string) $issue_data['priority'])); ?></span>
                                                        <span class="tk-badge">-<?php echo esc_html((string) ((int) $issue_data['penalty'])); ?></span><br>
                                                        <span class="description"><strong>Solution:</strong> <?php echo esc_html($solution); ?></span><br>
                                                        <?php if (!empty($fix_status)) : ?><span class="tk-badge tk-on" style="margin-top:5px;">Applied to Database</span> <a class="button button-small" style="margin-top:5px" href="<?php echo esc_url($fix_url); ?>">Apply Again</a><?php elseif ($fix_url !== '') : ?><a class="button button-small" style="margin-top:5px" href="<?php echo esc_url($fix_url); ?>">Fix in Database</a><?php else : ?><span class="tk-badge tk-warn" style="margin-top:5px;">Needs Review</span><?php endif; ?>
                                                        <a class="button button-small" style="margin-top:5px" href="<?php echo esc_url($remove_url); ?>">Remove URL Fix</a>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <p class="description">No GEO audit report yet.</p>
            <?php endif; ?>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="ai-radar" id="ai-radar"><?php tk_geo_render_ai_radar($ai_radar_report); ?></div>

        <div class="tk-card tk-tab-panel" data-panel-id="ai-visibility" id="ai-visibility">
            <h3>AI Visibility Score per URL</h3>
            <p>Scan one URL for crawler access, indexability signals, metadata, schema availability, AI crawler visibility and AI search readiness.</p>
            <div class="tk-grid tk-grid-2" style="gap:12px;">
                <p>
                    <label>URL</label>
                    <input type="url" form="tk-geo-visibility-form" name="geo_visibility_url" value="<?php echo esc_attr((string) ($visibility_report['url'] ?? home_url('/'))); ?>">
                </p>
                <p style="display:flex; align-items:flex-end;">
                    <button class="button button-primary" form="tk-geo-visibility-form">Generate AI Visibility Score</button>
                    <button class="button" form="tk-geo-visibility-form" name="geo_visibility_refresh" value="1">Scan Again</button>
                </p>
            </div>
            <?php if (!empty($visibility_report)) : ?>
                <?php
                $visibility_score = (int) ($visibility_report['score'] ?? 0);
                $visibility_badge = $visibility_score >= 80 ? 'tk-on' : ($visibility_score >= 60 ? 'tk-warn' : '');
                $visibility_checks = isset($visibility_report['checks']) && is_array($visibility_report['checks']) ? $visibility_report['checks'] : array();
                $visibility_agents = isset($visibility_report['agents']) && is_array($visibility_report['agents']) ? $visibility_report['agents'] : array();
                $visibility_schema = isset($visibility_report['schema']) && is_array($visibility_report['schema']) ? $visibility_report['schema'] : array();
                $visibility_snapshot = isset($visibility_report['snapshot']) && is_array($visibility_report['snapshot']) ? $visibility_report['snapshot'] : array();
                $visibility_issues = isset($visibility_report['issues']) && is_array($visibility_report['issues']) ? $visibility_report['issues'] : array();
                ?>
                <?php tk_geo_visibility_fix_controls($visibility_report); ?>
                <p class="tk-report-actions">
                    <button type="button" class="button tk-geo-export-pdf" data-report-target="tk-geo-visibility-report" data-report-title="AI Visibility Score Report">Export Report PDF</button>
                </p>
                <div id="tk-geo-visibility-report">
                    <p>
                        <span class="tk-badge <?php echo esc_attr($visibility_badge); ?>">Score: <?php echo esc_html((string) $visibility_score); ?>%</span>
                        <span class="description">Last scan: <?php echo !empty($visibility_report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $visibility_report['scanned_at'])) : '-'; ?></span>
                    </p>
                    <?php if (!empty($visibility_issues)) : ?>
                        <ul class="tk-list">
                            <?php foreach (array_slice($visibility_issues, 0, 12) as $issue) : ?>
                                <li><?php echo esc_html((string) $issue); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <div class="tk-grid tk-grid-3" style="gap:16px; margin:14px 0;">
                        <div class="tk-card"><strong>HTTP</strong><br><?php echo esc_html((string) ((int) ($visibility_report['status'] ?? 0))); ?></div>
                        <div class="tk-card"><strong>JSON-LD</strong><br><?php echo esc_html((string) ((int) ($visibility_schema['documents'] ?? 0))); ?> docs, <?php echo esc_html((string) ((int) ($visibility_schema['invalid'] ?? 0))); ?> invalid</div>
                        <div class="tk-card"><strong>Schema Types</strong><br><?php echo tk_geo_render_schema_type_badges($visibility_schema['types'] ?? array()); ?></div>
                    </div>
                    <div class="tk-table-scroll">
                    <table class="widefat striped">
                        <tbody>
                            <tr><th>URL</th><td><a href="<?php echo esc_url((string) ($visibility_report['url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($visibility_report['url'] ?? '')); ?></a></td></tr>
                            <tr><th>Title</th><td><?php echo esc_html((string) ($visibility_snapshot['title'] ?? '')); ?></td></tr>
                            <tr><th>Description</th><td><?php echo esc_html((string) ($visibility_snapshot['description'] ?? '')); ?></td></tr>
                            <tr><th>Author</th><td><?php echo esc_html((string) ($visibility_snapshot['author'] ?? '')); ?></td></tr>
                            <tr><th>Publisher</th><td><?php echo esc_html((string) ($visibility_snapshot['publisher'] ?? '')); ?></td></tr>
                            <tr><th>Canonical</th><td><code><?php echo esc_html((string) ($visibility_snapshot['canonical'] ?? '')); ?></code></td></tr>
                        </tbody>
                    </table>
                    </div>
                    <h4 style="margin-top:18px;">Checks</h4>
                    <div class="tk-table-scroll">
                    <table class="widefat striped">
                        <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
                        <tbody>
                        <?php foreach ($visibility_checks as $check) : ?>
                            <?php $check_status = sanitize_key((string) ($check['status'] ?? 'warn')); ?>
                            <tr>
                                <td><?php echo esc_html((string) ($check['name'] ?? '')); ?></td>
                                <td><span class="tk-badge <?php echo esc_attr($check_status === 'ok' ? 'tk-on' : ($check_status === 'warn' ? 'tk-warn' : '')); ?>"><?php echo esc_html(strtoupper($check_status)); ?></span></td>
                                <td><?php echo esc_html((string) ($check['detail'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <h4 style="margin-top:18px;">AI Crawler Visibility</h4>
                    <div class="tk-table-scroll">
                    <table class="widefat striped">
                        <thead><tr><th>Visible</th><th>User Agent</th><th>Robots</th><th>HTTP</th><th>HTML</th><th>Error</th></tr></thead>
                        <tbody>
                        <?php foreach ($visibility_agents as $row) : ?>
                            <?php
                            $visible = !empty($row['visible']);
                            $verification = (string) ($row['verification'] ?? ($visible ? 'eligible' : (!empty($row['allowed']) ? 'unavailable' : 'blocked')));
                            ?>
                            <tr>
                                <td><span class="tk-badge <?php echo $verification === 'eligible' ? 'tk-on' : ($verification === 'unavailable' ? 'tk-warn' : ''); ?>"><?php echo $verification === 'eligible' ? 'Eligible' : ($verification === 'unavailable' ? 'Page unavailable' : 'Blocked'); ?></span></td>
                                <td><strong><?php echo esc_html((string) ($row['agent'] ?? '')); ?></strong><br><span class="description"><?php echo esc_html((string) ($row['label'] ?? '')); ?></span></td>
                                <td><?php echo !empty($row['allowed']) ? '<span class="tk-badge tk-on">Allowed</span>' : '<span class="tk-badge">Blocked</span>'; ?><br><code><?php echo esc_html((string) ($row['matched'] ?? '')); ?></code></td>
                                <td><?php echo esc_html((string) ((int) ($row['status'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) ((int) ($row['body_bytes'] ?? 0))); ?> bytes</td>
                                <td><?php echo esc_html((string) ($row['error'] ?? '')); ?><?php if ($verification === 'eligible') : ?><span class="description">Allowed by robots.txt and the page is publicly fetchable. Crawler identity is not spoofed.</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php tk_geo_render_search_radar(tk_geo_build_search_radar($visibility_report)); ?>
                </div>
            <?php else : ?>
                <p class="description">No AI visibility scan yet.</p>
            <?php endif; ?>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="prompt-preview" id="prompt-preview">
            <h3>Prompt Preview</h3>
            <p>Preview how an AI answer engine may summarize a page from visible title, description, headings, body sample, and schema types.</p>
            <div class="tk-grid tk-grid-2" style="gap:12px;">
                <p>
                    <label>URL</label>
                    <input type="url" form="tk-geo-prompt-form" name="geo_prompt_url" value="<?php echo esc_attr((string) ($prompt_preview_report['url'] ?? home_url('/'))); ?>">
                </p>
                <p style="display:flex; align-items:flex-end;">
                    <button class="button button-primary" form="tk-geo-prompt-form">Generate Prompt Preview</button>
                </p>
            </div>
            <?php if (!empty($prompt_preview_report)) : ?>
                <?php $prompt_types = isset($prompt_preview_report['schema_types']) && is_array($prompt_preview_report['schema_types']) ? $prompt_preview_report['schema_types'] : array(); ?>
                <p class="tk-report-actions">
                    <button type="button" class="button tk-geo-export-pdf" data-report-target="tk-geo-prompt-report" data-report-title="Prompt Preview Report">Export Report PDF</button>
                </p>
                <div id="tk-geo-prompt-report">
                    <p><strong>Prompt:</strong> <?php echo esc_html((string) ($prompt_preview_report['prompt'] ?? '')); ?></p>
                    <div class="tk-card" style="background:var(--tk-bg-soft);">
                        <h4 style="margin-top:0;">AI Summary Preview</h4>
                        <p style="font-size:16px; line-height:1.6;"><?php echo esc_html((string) ($prompt_preview_report['summary'] ?? '')); ?></p>
                    </div>
                    <div class="tk-table-scroll" style="margin-top:14px;">
                    <table class="widefat striped">
                        <tbody>
                            <tr><th>URL</th><td><a href="<?php echo esc_url((string) ($prompt_preview_report['url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($prompt_preview_report['url'] ?? '')); ?></a></td></tr>
                            <tr><th>HTTP</th><td><?php echo esc_html((string) ((int) ($prompt_preview_report['status'] ?? 0))); ?></td></tr>
                            <tr><th>Title</th><td><?php echo esc_html((string) ($prompt_preview_report['title'] ?? '')); ?></td></tr>
                            <tr><th>Description</th><td><?php echo esc_html((string) ($prompt_preview_report['description'] ?? '')); ?></td></tr>
                            <tr><th>H1</th><td><code><?php echo esc_html(wp_json_encode($prompt_preview_report['h1s'] ?? array(), JSON_UNESCAPED_SLASHES)); ?></code></td></tr>
                            <tr><th>Schema Types</th><td><?php echo tk_geo_render_schema_type_badges($prompt_types, false); ?></td></tr>
                            <?php if (!empty($prompt_preview_report['error'])) : ?><tr><th>Error</th><td><?php echo esc_html((string) $prompt_preview_report['error']); ?></td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                    <h4 style="margin-top:18px;">Visible Context Sample</h4>
                    <textarea readonly rows="8" style="width:100%; max-width:100%;"><?php echo esc_textarea((string) ($prompt_preview_report['context_sample'] ?? '')); ?></textarea>
                </div>
            <?php else : ?>
                <p class="description">No prompt preview yet.</p>
            <?php endif; ?>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="schema-duplicates" id="schema-duplicates">
            <h3>Schema Duplicate Detector</h3>
            <p>Scan homepage and recent public content for repeated entity definitions or invalid JSON-LD blocks.</p>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_geo_schema_duplicate_scan'), 'tk_geo_schema_duplicate_scan')); ?>">Run Duplicate Detector</a>
                <button type="submit" class="button button-primary" form="tk-geo-schema-duplicate-fix-form" aria-describedby="tk-schema-duplicate-fix-help" <?php disabled(!$duplicate_fix_available); ?>>Auto Fix Tool Kits Duplicates</button>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tool-kits-geo') . '#overview'); ?>">Manage Schema Output</a>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_geo_schema_duplicate_clear'), 'tk_geo_schema_duplicate_clear')); ?>">Clear Report</a>
            </p>
            <p class="description" id="tk-schema-duplicate-fix-help">
                <?php if ($duplicate_fix_available) : ?>
                    Auto Fix disables the Tool Kits SEO schema generator and/or BreadcrumbList output for conflicting types, clears caches, and rechecks the URLs. These settings apply site-wide.
                <?php elseif (!$duplicate_has_report || (int) ($schema_duplicate_report['analysis_version'] ?? 0) !== 2) : ?>
                    Run Duplicate Detector to get current entity-based findings before using Auto Fix.
                <?php else : ?>
                    No enabled Tool Kits SEO or Breadcrumb output matches these findings. Use Validate to inspect the page's JSON-LD in GEO Setup &gt; Custom JSON-LD, or review the source settings for theme and other plugin schema.
                <?php endif; ?>
                Use Manage Schema Output to review or disable Tool Kits schema generators while keeping their saved settings.
            </p>
            <?php if (!empty($schema_duplicate_fix_report)) : ?>
                <?php
                $fix_actions = isset($schema_duplicate_fix_report['actions']) && is_array($schema_duplicate_fix_report['actions']) ? $schema_duplicate_fix_report['actions'] : array();
                $fix_remaining = isset($schema_duplicate_fix_report['remaining']) && is_array($schema_duplicate_fix_report['remaining']) ? $schema_duplicate_fix_report['remaining'] : array();
                ?>
                <div class="notice notice-info inline">
                    <p><strong>Auto Fix Result:</strong> <?php echo !empty($schema_duplicate_fix_report['fixed_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $schema_duplicate_fix_report['fixed_at'])) : '-'; ?></p>
                    <?php if (!empty($fix_actions)) : ?>
                        <ul class="tk-list">
                            <?php foreach ($fix_actions as $action) : ?>
                                <li><?php echo esc_html((string) $action); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else : ?>
                        <p>No Tool Kits automatic SEO or Breadcrumb schema setting needed to be changed.</p>
                    <?php endif; ?>
                    <?php if (!empty($fix_remaining)) : ?>
                        <ul class="tk-list">
                            <?php foreach ($fix_remaining as $remaining_item) : ?>
                                <li><?php echo esc_html((string) $remaining_item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($schema_duplicate_report)) : ?>
                <?php $duplicate_items = isset($schema_duplicate_report['items']) && is_array($schema_duplicate_report['items']) ? $schema_duplicate_report['items'] : array(); ?>
                <p class="description">Last scan: <?php echo !empty($schema_duplicate_report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $schema_duplicate_report['scanned_at'])) : '-'; ?> | Issues: <?php echo esc_html((string) ($schema_duplicate_report['issue_count'] ?? 0)); ?></p>
                <p class="description">Type counts are an inventory. Potential duplicates use repeated <code>@id</code> definitions or identical top-level nodes; complementary descriptions may be intentional. Compare sources before removing anything.</p>
                <?php if ((int) ($schema_duplicate_report['analysis_version'] ?? 0) !== 2) : ?>
                    <p class="description">This saved report used type counts. Run Duplicate Detector again for entity-based findings.</p>
                <?php endif; ?>
                <table class="widefat striped">
                    <thead><tr><th>URL</th><th>Status</th><th>JSON-LD</th><th>Schema Types</th><th>Potential Duplicates</th><th>Issue</th></tr></thead>
                    <tbody>
                    <?php foreach ($duplicate_items as $item) : ?>
                        <?php $duplicates = isset($item['duplicates']) && is_array($item['duplicates']) ? $item['duplicates'] : array(); ?>
                        <tr>
                            <td><a href="<?php echo esc_url((string) ($item['url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($item['url'] ?? '')); ?></a></td>
                            <td><?php echo esc_html((string) ((int) ($item['status'] ?? 0))); ?></td>
                            <td><?php echo esc_html((string) ((int) ($item['documents'] ?? 0))); ?> docs, <?php echo esc_html((string) ((int) ($item['invalid'] ?? 0))); ?> invalid</td>
                            <td><?php echo tk_geo_render_schema_type_badges($item['types'] ?? array()); ?></td>
                            <td><?php echo !empty($duplicates) ? tk_geo_render_schema_type_badges($duplicates, (int) ($schema_duplicate_report['analysis_version'] ?? 0) === 2) : '<span class="tk-badge tk-on">None</span>'; ?></td>
                            <td><?php echo !empty($item['issue']) ? esc_html((string) $item['issue']) : '<span class="tk-badge tk-on">OK</span>'; ?><p><a class="button" href="<?php echo esc_url(add_query_arg(array('page' => 'tool-kits-geo', 'tk_schema_test' => rawurlencode((string) ($item['url'] ?? ''))), admin_url('admin.php')) . '#custom-jsonld'); ?>">Validate</a></p></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="description">No duplicate schema report yet.</p>
            <?php endif; ?>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="crawler-preview" id="crawler-preview">
            <h3>Crawler Preview</h3>
            <p>Fetch a URL with known AI crawler user-agents and list which crawlers can see the page, metadata, and structured data.</p>
            <div class="tk-grid tk-grid-2" style="gap:12px;">
                <p>
                    <label>URL</label>
                    <input type="url" form="tk-geo-crawler-preview-form" name="crawler_preview_url" value="<?php echo esc_attr((string) ($crawler_preview['url'] ?? home_url('/'))); ?>">
                </p>
                <p style="display:flex; align-items:flex-end;">
                    <button class="button" form="tk-geo-crawler-preview-form">Preview Crawler Fetch</button>
                    <button class="button" form="tk-geo-crawler-preview-form" name="crawler_preview_refresh" value="1">Scan Again</button>
                </p>
            </div>
            <?php if (!empty($crawler_preview)) : ?>
                <p class="description">Last scanned: <?php echo !empty($crawler_preview['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s T', (int) $crawler_preview['scanned_at'])) : 'Unknown'; ?>. Both Preview Crawler Fetch and Scan Again fetch new responses.</p>
                <?php if (empty($crawler_preview['metadata_version'])): ?><p><span class="tk-badge tk-warn">Refresh required</span> This saved report predates the current metadata scan. Run Preview Crawler Fetch or Scan Again to update these findings.</p><?php endif; ?>
                <?php $schema_types = isset($crawler_preview['schema_types']) && is_array($crawler_preview['schema_types']) ? $crawler_preview['schema_types'] : array(); ?>
                <?php $preview_agents = isset($crawler_preview['agents']) && is_array($crawler_preview['agents']) ? $crawler_preview['agents'] : array(); ?>
                <?php tk_geo_crawler_fix_controls($crawler_preview); ?>
                <p class="tk-report-actions">
                    <button type="button" class="button tk-geo-export-pdf" data-report-target="tk-geo-crawler-report" data-report-title="Crawler Preview Report">Export Report PDF</button>
                </p>
                <div id="tk-geo-crawler-report">
                <?php if (!empty($preview_agents)) : ?>
                    <h4>Visible User Agents</h4>
                    <div class="tk-table-scroll">
                    <table class="widefat striped">
                        <thead><tr><th>Visible</th><th>User Agent</th><th>HTTP</th><th>Title</th><th>Canonical</th><th>Schema</th><th>HTML</th><th>Issues / Fix</th></tr></thead>
                        <tbody>
                        <?php foreach ($preview_agents as $row) : ?>
                            <?php
                            $row_schema_types = isset($row['schema_types']) && is_array($row['schema_types']) ? $row['schema_types'] : array();
                            $row_ok = !empty($row['ok']);
                            $row_issues = tk_geo_crawler_issues($row);
                            ?>
                            <tr>
                                <td><span class="tk-badge <?php echo $row_ok && !$row_issues ? 'tk-on' : 'tk-warn'; ?>"><?php echo !$row_ok ? 'Issue' : ($row_issues ? 'Missing / Issue' : 'Visible'); ?></span></td>
                                <td><strong><?php echo esc_html((string) ($row['agent'] ?? '')); ?></strong><br><span class="description"><?php echo esc_html((string) ($row['label'] ?? '')); ?></span></td>
                                <td><?php echo esc_html((string) ((int) ($row['status'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) ($row['title'] ?? '')); ?></td>
                                <td><code><?php echo esc_html((string) ($row['canonical'] ?? '')); ?></code></td>
                                <td><?php echo esc_html((string) ((int) ($row['schema_documents'] ?? 0))); ?> docs, <?php echo esc_html((string) ((int) ($row['schema_invalid'] ?? 0))); ?> invalid<br><?php echo tk_geo_render_schema_type_badges($row_schema_types); ?></td>
                                <td><?php echo esc_html((string) ((int) ($row['body_bytes'] ?? 0))); ?> bytes</td>
                                <td><?php foreach ($row_issues as $issue) : ?><p><?php echo esc_html($issue); ?></p><?php endforeach; ?><?php echo esc_html((string) ($row['error'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
                <h4 style="margin-top:18px;">First Visible Fetch Summary</h4>
                <div class="tk-table-scroll">
                <table class="widefat striped">
                    <tbody>
                        <tr><th>User Agent</th><td><code><?php echo esc_html((string) ($crawler_preview['agent'] ?? '')); ?></code></td></tr>
                        <tr><th>Status</th><td><?php echo esc_html((string) ($crawler_preview['status'] ?? 0)); ?> <?php echo !empty($crawler_preview['ok']) ? '<span class="tk-badge tk-on">OK</span>' : '<span class="tk-badge">Check</span>'; ?></td></tr>
                        <tr><th>Title</th><td><?php echo esc_html((string) ($crawler_preview['title'] ?? '')); ?></td></tr>
                        <tr><th>Description</th><td><?php echo esc_html((string) ($crawler_preview['description'] ?? '')); ?></td></tr>
                        <tr><th>Author</th><td><?php echo esc_html((string) ($crawler_preview['author'] ?? '')); ?></td></tr>
                        <tr><th>Publisher</th><td><?php echo esc_html((string) ($crawler_preview['publisher'] ?? '')); ?></td></tr>
                        <tr><th>Canonical</th><td><code><?php echo esc_html((string) ($crawler_preview['canonical'] ?? '')); ?></code></td></tr>
                        <tr><th>Robots</th><td><?php echo esc_html((string) ($crawler_preview['robots'] ?? '')); ?></td></tr>
                        <tr><th>Schema</th><td><?php echo esc_html((string) ($crawler_preview['schema_documents'] ?? 0)); ?> docs, <?php echo esc_html((string) ($crawler_preview['schema_invalid'] ?? 0)); ?> invalid<br><?php echo tk_geo_render_schema_type_badges($schema_types); ?></td></tr>
                        <tr><th>HTML Size</th><td><?php echo esc_html((string) ($crawler_preview['body_bytes'] ?? 0)); ?> bytes</td></tr>
                        <?php if (!empty($crawler_preview['error'])) : ?><tr><th>Error</th><td><?php echo esc_html((string) $crawler_preview['error']); ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
                </div>
                </div>
            <?php else : ?>
                <p class="description">No crawler preview yet.</p>
            <?php endif; ?>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="ai-access" id="ai-access">
            <h3>AI Crawler Accessibility Review</h3>
            <p>Review whether AI search and assistant crawlers can access public content, read robots.txt, discover sitemap URLs, and consume structured data.</p>
            <div class="notice notice-info inline" style="margin:12px 0;padding:10px 12px;">
                <p style="margin:0 0 6px;"><strong>About Google AI Overviews</strong></p>
                <p style="margin:0 0 6px;">AI Overviews and AI Mode are grounded from the regular Google Search index via <code>Googlebot</code>. There is no dedicated AI Overviews crawler and no robots.txt token that opts a site in or out of them.</p>
                <p style="margin:0 0 6px;">Blocking <code>Google-Extended</code> only limits Gemini and Vertex AI training and grounding &mdash; it does <strong>not</strong> remove the site from AI Overviews.</p>
                <p style="margin:0;">To opt out, use the property-level control in <a href="https://search.google.com/search-console/settings" target="_blank" rel="noopener noreferrer">Search Console &rsaquo; Settings &rsaquo; Search generative AI</a>, or limit on-page snippet usage with <code>nosnippet</code> / <code>max-snippet</code> (note: those also remove ordinary search snippets).</p>
            </div>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_geo_ai_access_scan'), 'tk_geo_ai_access_scan')); ?>">Run AI Access Review</a>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_geo_ai_access_clear'), 'tk_geo_ai_access_clear')); ?>">Clear Review</a>
            </p>
            <?php if (!empty($ai_access_report)) : ?>
                <?php
                $score = isset($ai_access_report['score']) ? (int) $ai_access_report['score'] : 0;
                $badge_class = $score >= 80 ? 'tk-on' : ($score >= 60 ? 'tk-warn' : '');
                $checks = isset($ai_access_report['checks']) && is_array($ai_access_report['checks']) ? $ai_access_report['checks'] : array();
                $robots_results = isset($ai_access_report['robots_results']) && is_array($ai_access_report['robots_results']) ? $ai_access_report['robots_results'] : array();
                $crawler_fetch_results = isset($ai_access_report['crawler_fetch_results']) && is_array($ai_access_report['crawler_fetch_results']) ? $ai_access_report['crawler_fetch_results'] : array();
                $issues = isset($ai_access_report['issues']) && is_array($ai_access_report['issues']) ? $ai_access_report['issues'] : array();
                ?>
                <p class="tk-report-actions">
                    <button type="button" class="button tk-geo-export-pdf" data-report-target="tk-geo-ai-access-report" data-report-title="AI Crawler Accessibility Review">Export Report PDF</button>
                </p>
                <div id="tk-geo-ai-access-report">
                <p>
                    <span class="tk-badge <?php echo esc_attr($badge_class); ?>">Score: <?php echo esc_html((string) $score); ?>%</span>
                    <span class="description">Last scan: <?php echo !empty($ai_access_report['scanned_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $ai_access_report['scanned_at'])) : '-'; ?></span>
                </p>
                <?php if (!empty($issues)) : ?>
                    <ul class="tk-list">
                        <?php foreach ($issues as $issue) : ?>
                            <li><?php echo esc_html((string) $issue); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <div class="tk-table-scroll">
                <table class="widefat striped" style="margin-top:14px;">
                    <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
                    <tbody>
                    <?php foreach ($checks as $check) : ?>
                        <?php
                        $status = sanitize_key((string) ($check['status'] ?? 'warn'));
                        $class = $status === 'ok' ? 'tk-on' : ($status === 'warn' ? 'tk-warn' : '');
                        ?>
                        <tr>
                            <td><?php echo esc_html((string) ($check['name'] ?? '')); ?></td>
                            <td><span class="tk-badge <?php echo esc_attr($class); ?>"><?php echo esc_html(strtoupper($status)); ?></span></td>
                            <td><?php echo esc_html((string) ($check['detail'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php if (!empty($crawler_fetch_results)) : ?>
                    <h4 style="margin-top:18px;">Crawler Eligibility Checklist</h4>
                    <div class="tk-table-scroll">
                    <table class="widefat striped">
                        <thead><tr><th>Eligibility</th><th>Crawler</th><th>HTTP</th><th>HTML</th><th>Structured Data</th><th>Detail</th></tr></thead>
                        <tbody>
                        <?php foreach ($crawler_fetch_results as $result) : ?>
                            <?php
                            $ok = !empty($result['ok']);
                            $eligibility = (string) ($result['eligibility'] ?? ($ok ? 'eligible' : 'unknown'));
                            $eligibility_label = $eligibility === 'eligible' ? 'Eligible' : ($eligibility === 'blocked' ? 'Blocked' : ($eligibility === 'unavailable' ? 'Page unavailable' : 'Unknown'));
                            ?>
                            <tr>
                                <td><span class="tk-badge <?php echo $ok ? 'tk-on' : ($eligibility === 'unknown' ? 'tk-warn' : ''); ?>"><?php echo esc_html($eligibility_label); ?></span></td>
                                <td><strong><?php echo esc_html((string) ($result['agent'] ?? '')); ?></strong><br><span class="description"><?php echo esc_html((string) ($result['label'] ?? '')); ?></span></td>
                                <td><?php echo esc_html((string) ((int) ($result['status'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) ((int) ($result['body_bytes'] ?? 0))); ?> bytes</td>
                                <td><?php echo esc_html((string) ((int) ($result['schema_documents'] ?? 0))); ?> docs</td>
                                <td><?php echo esc_html((string) ($result['error'] ?? '')); ?><?php if ($eligibility === 'eligible') : ?><span class="description">Homepage is fetchable and robots.txt allows this crawler.</span><?php elseif ($eligibility === 'unknown') : ?><span class="description">robots.txt could not be reviewed.</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>

                <?php if (!empty($robots_results)) : ?>
                    <h4 style="margin-top:18px;">AI Crawler robots.txt Access</h4>
                    <div class="tk-table-scroll">
                    <table class="widefat striped">
                        <thead><tr><th>Crawler</th><th>Purpose</th><th>Access</th><th>Matched Rule</th></tr></thead>
                        <tbody>
                        <?php foreach ($robots_results as $result) : ?>
                            <?php
                            $status = sanitize_key((string) ($result['status'] ?? 'warn'));
                            $class = $status === 'ok' ? 'tk-on' : ($status === 'warn' ? 'tk-warn' : '');
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html((string) ($result['agent'] ?? '')); ?></strong><br><span class="description"><?php echo esc_html((string) ($result['label'] ?? '')); ?></span></td>
                                <td>
                                    <?php echo esc_html((string) ($result['purpose'] ?? '')); ?>
                                    <?php if (!empty($result['note'])) : ?>
                                        <br><span class="description"><?php echo esc_html((string) $result['note']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="tk-badge <?php echo esc_attr($class); ?>"><?php echo !empty($result['allowed']) ? 'Allowed' : 'Blocked'; ?></span></td>
                                <td><code><?php echo esc_html((string) ($result['matched'] ?? '')); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
                </div>
            <?php else : ?>
                <p class="description">No AI crawler review yet.</p>
            <?php endif; ?>
        </div>

        <div class="tk-card tk-tab-panel" data-panel-id="preview">
            <h3>Preview</h3>
            <?php if ($preview_json !== '') : ?>
                <textarea readonly rows="16" style="width:100%; max-width:100%; font-family:monospace;"><?php echo esc_textarea($preview_json); ?></textarea>
            <?php else : ?>
                <p class="description">No JSON-LD generated yet.</p>
            <?php endif; ?>
        </div>
            </div>
        </div>

        <?php if (!$geo_audit_page) : ?><p style="margin-top:16px;">
            <button class="button button-primary button-hero">Save GEO Settings</button>
        </p><?php endif; ?>
    </form>
    <script>
    (function(){
        var siteTitle = <?php echo wp_json_encode(get_bloginfo('name'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?> || 'Site';
        var select = document.getElementById('tk-geo-itemlist-post-type');
        if (select) {
        select.addEventListener('change', function(){
            var url = new URL(window.location.href);
            url.searchParams.set('page', 'tool-kits-geo');
            url.searchParams.set('geo_post_type', select.value);
            url.hash = 'itemlist';
            window.location.href = url.toString();
        });
        }

        var postSelect = document.getElementById('tk-geo-itemlist-post-ids');
        var selectAll = document.getElementById('tk-geo-itemlist-select-all');
        var clearAll = document.getElementById('tk-geo-itemlist-clear');
        var triggerPostSelectChange = function() {
            if (window.jQuery && postSelect) {
                window.jQuery(postSelect).trigger('change');
            } else if (postSelect) {
                postSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        };
        if (postSelect && selectAll) {
            selectAll.addEventListener('click', function(){
                Array.prototype.forEach.call(postSelect.options, function(option){ option.selected = true; });
                triggerPostSelectChange();
            });
        }
        if (postSelect && clearAll) {
            clearAll.addEventListener('click', function(){
                Array.prototype.forEach.call(postSelect.options, function(option){ option.selected = false; });
                triggerPostSelectChange();
            });
        }

        document.querySelectorAll('.tk-geo-copy-prompt').forEach(function(button){
            button.addEventListener('click', function(){
                var field = document.getElementById(button.getAttribute('data-target'));
                var status = button.parentNode.querySelector('.tk-geo-copy-status');
                if (!field) { return; }
                var done = function(ok){ if (status) { status.textContent = ok ? 'Prompt copied.' : 'Select the text and copy it manually.'; } };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(field.value).then(function(){ done(true); }, function(){ field.select(); done(false); });
                } else {
                    field.select();
                    done(document.execCommand('copy'));
                }
            });
        });

        var wrapper = document.querySelector('.tk-geo-tabs');
        var activeInput = document.getElementById('tk-geo-active-tab');
        if (wrapper) {
            var activate = function(panelId) {
                wrapper.querySelectorAll('.tk-tab-panel').forEach(function(panel){
                    panel.classList.toggle('is-active', panel.getAttribute('data-panel-id') === panelId);
                });
                wrapper.querySelectorAll('.tk-tabs-nav-button').forEach(function(button){
                    button.classList.toggle('is-active', button.getAttribute('data-panel') === panelId);
                });
                if (activeInput) {
                    activeInput.value = panelId;
                }
            };
            wrapper.querySelectorAll('.tk-tabs-nav-button').forEach(function(button){
                button.addEventListener('click', function(){
                    var panelId = button.getAttribute('data-panel');
                    activate(panelId);
                    if (history.replaceState) {
                        history.replaceState(null, '', '#' + panelId);
                    }
                });
            });
            var initial = window.location.hash ? window.location.hash.substring(1) : '';
            var visibleButton = initial ? wrapper.querySelector('.tk-tabs-nav-button[data-panel="' + initial + '"]') : null;
            if (visibleButton && window.getComputedStyle(visibleButton).display !== 'none') {
                activate(initial);
            }
        }

        var rows = document.getElementById('tk-geo-faq-rows');
        var add = document.getElementById('tk-geo-faq-add');
        var faqDefaultLanguage = <?php echo wp_json_encode($faq_default_language); ?>;
        var faqPreferredLanguages = <?php echo wp_json_encode($faq_languages); ?>;
        var faqPicker = null;
        var faqRowLanguage = function(row) { return row.querySelector('[name="geo_faq_language[]"]').value; };
        if (rows && add && window.ToolKitsLanguagePicker) {
            faqPicker = window.ToolKitsLanguagePicker.create({
                defaultLanguage: faqDefaultLanguage,
                preferred: faqPreferredLanguages,
                label: 'FAQ language',
                className: 'tk-geo-faq-languages',
                onError: function(message) {
                    var field = faqPicker ? faqPicker.element.querySelector('input[type="search"]') : null;
                    if (field) { field.setCustomValidity(message); if (message) { field.reportValidity(); } }
                },
                onActivate: function(tag) {
                    rows.querySelectorAll('.tk-geo-faq-row').forEach(function(row) {
                        row.style.display = faqRowLanguage(row) === tag ? 'grid' : 'none';
                    });
                    var hasRows = Array.prototype.some.call(rows.querySelectorAll('.tk-geo-faq-row'), function(row) {
                        return faqRowLanguage(row) === tag;
                    });
                    if (!hasRows && rowCount() < 50) { add.click(); }
                },
                onRemove: function(tag) {
                    var localeRows = Array.prototype.filter.call(rows.querySelectorAll('.tk-geo-faq-row'), function(row) {
                        return faqRowLanguage(row) === tag;
                    });
                    var filled = localeRows.filter(function(row) {
                        return row.querySelector('[name="geo_faq_question[]"]').value.trim() || row.querySelector('[name="geo_faq_answer[]"]').value.trim();
                    }).length;
                    if (filled && !window.confirm('Remove locale ' + tag.toUpperCase() + ' and all ' + filled + ' FAQ item(s) in it?')) { return false; }
                    localeRows.forEach(function(row) { row.remove(); });
                    updateFaqLimit();
                    return true;
                }
            });
            faqPicker.element.querySelector('input[type="search"]').addEventListener('input', function(event) { event.target.setCustomValidity(''); });
            rows.before(faqPicker.element);
            faqPreferredLanguages.forEach(faqPicker.add);
            rows.querySelectorAll('[name="geo_faq_language[]"]').forEach(function(field) {
                field.value = window.ToolKitsLanguagePicker.normalize(field.value);
                if (!window.ToolKitsLanguagePicker.pattern.test(field.value)) {
                    field.value = faqDefaultLanguage;
                }
                field.type = 'hidden';
                faqPicker.add(field.value);
            });
        }
        var rowCount = function() {
            return rows ? rows.querySelectorAll('.tk-geo-faq-row').length : 0;
        };
        var updateFaqLimit = function() {
            if (add) {
                add.disabled = rowCount() >= 50;
            }
        };
        var wireRemove = function(button) {
            button.addEventListener('click', function(){
                var row = button.closest('.tk-geo-faq-row');
                if (row && rowCount() > 1) {
                    row.remove();
                } else if (row) {
                    row.querySelectorAll('input, textarea').forEach(function(field){ field.value = ''; });
                    row.querySelector('[name="geo_faq_language[]"]').value = faqPicker ? faqPicker.active() : faqDefaultLanguage;
                }
                updateFaqLimit();
            });
        };
        if (rows) {
            rows.querySelectorAll('.tk-geo-faq-remove').forEach(wireRemove);
        }
        if (rows && add) {
            updateFaqLimit();
            add.addEventListener('click', function(){
                if (rowCount() >= 50) { return; }
                var row = document.createElement('div');
                row.className = 'tk-geo-faq-row';
                row.style.cssText = 'display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1.5fr) auto; gap:12px; margin-bottom:12px; align-items:start;';
                row.innerHTML = '<div><input type="text" name="geo_faq_language[]" value="" placeholder="Language: id, en, en-sg" aria-label="FAQ language" style="width:100%;margin-bottom:8px;"><input type="text" name="geo_faq_question[]" value="" placeholder="Question" style="width:100%;"></div><textarea name="geo_faq_answer[]" rows="2" placeholder="Answer"></textarea><button type="button" class="button tk-geo-faq-remove">Remove</button>';
                rows.insertBefore(row, rows.querySelector('.description'));
                var rowLanguage = row.querySelector('[name="geo_faq_language[]"]');
                rowLanguage.type = 'hidden';
                rowLanguage.value = faqPicker ? faqPicker.active() : faqDefaultLanguage;
                wireRemove(row.querySelector('.tk-geo-faq-remove'));
                updateFaqLimit();
                row.querySelector('[name="geo_faq_question[]"]').focus();
            });
            if (faqPicker) { faqPicker.activate(faqDefaultLanguage); }
        }

        var sectionRows = document.getElementById('tk-geo-llms-section-rows');
        var sectionAdd = document.getElementById('tk-geo-llms-section-add');
        var sectionCount = function() {
            return sectionRows ? sectionRows.querySelectorAll('.tk-geo-llms-section-row').length : 0;
        };
        var wireSectionRemove = function(button) {
            button.addEventListener('click', function(){
                var row = button.closest('.tk-geo-llms-section-row');
                if (row && sectionCount() > 1) {
                    row.remove();
                } else if (row) {
                    row.querySelectorAll('input, textarea').forEach(function(field){ field.value = ''; });
                }
            });
        };
        if (sectionRows) {
            sectionRows.querySelectorAll('.tk-geo-llms-section-remove').forEach(wireSectionRemove);
        }
        if (sectionRows && sectionAdd) {
            sectionAdd.addEventListener('click', function(){
                if (sectionCount() >= 20) { return; }
                var row = document.createElement('div');
                row.className = 'tk-geo-llms-section-row';
                row.style.cssText = 'display:grid; grid-template-columns:minmax(180px,0.5fr) minmax(0,1.5fr) auto; gap:12px; margin-bottom:12px; align-items:start;';
                row.innerHTML = '<input type="text" name="geo_llms_section_title[]" value="" placeholder="Section title"><textarea name="geo_llms_section_content[]" rows="3" placeholder="Section content"></textarea><button type="button" class="button tk-geo-llms-section-remove">Remove</button>';
                sectionRows.insertBefore(row, sectionRows.querySelector('.description'));
                wireSectionRemove(row.querySelector('.tk-geo-llms-section-remove'));
            });
        }

        document.querySelectorAll('.tk-geo-export-pdf').forEach(function(button){
            button.addEventListener('click', function(){
                var targetId = button.getAttribute('data-report-target');
                var title = button.getAttribute('data-report-title') || 'Tool Kits GEO Report';
                var target = targetId ? document.getElementById(targetId) : null;
                if (!target) { return; }
                var printWindow = window.open('', '_blank');
                if (!printWindow) { return; }
                var cleanTitle = title.replace(/[<>&"]/g, '');
                var cleanSiteTitle = String(siteTitle).replace(/[<>&"]/g, '');
                var printedAt = new Date().toLocaleString().replace(/[<>&"]/g, '');
                printWindow.document.open();
                printWindow.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + cleanSiteTitle + ' - ' + cleanTitle + '</title><style>body{font-family:Arial,sans-serif;color:#172033;margin:24px;}h1{font-size:22px;margin:0 0 4px;}h2{font-size:15px;margin:0 0 18px;color:#4b5563;font-weight:600;}h4{margin:18px 0 8px;}table{border-collapse:collapse;width:100%;font-size:12px;margin-bottom:16px;}th,td{border:1px solid #d7dde8;padding:8px;vertical-align:top;text-align:left;}th{background:#f6f8fb;}code{white-space:normal;word-break:break-word;}.tk-badge{display:inline-block;margin:0 4px 4px 0;padding:2px 7px;border-radius:4px;background:#eef2f7;font-weight:700;}.tk-on{background:#dcfce7;color:#166534;}.tk-warn{background:#fef3c7;color:#92400e;}.description{color:#667085;}.tk-schema-type-list{display:block;line-height:1.9;}@media print{button{display:none;}}</style></head><body><h1>' + cleanSiteTitle + '</h1><h2>' + cleanTitle + ' | Exported ' + printedAt + '</h2>' + target.innerHTML + '</body></html>');
                printWindow.document.close();
                printWindow.focus();
                setTimeout(function(){ printWindow.print(); }, 250);
            });
        });
    })();
    jQuery(function($){
        var select = $('#tk-geo-itemlist-post-ids');
        if (!select.length || !$.fn.select2) { return; }
        select.select2({
            width: '100%',
            placeholder: 'Select posts',
            closeOnSelect: false
        });
    });
    </script>
    <?php
