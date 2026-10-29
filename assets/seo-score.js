(function () {
    function init() {
        var store = window.wp && window.wp.data;
        if (!store || typeof store.subscribe !== 'function' || !document.querySelector('[data-tk-seo-score-post]')) return;
        function state() {
            var editor, metaboxes;
            try { editor = store.select('core/editor'); } catch (error) { return null; }
            if (!editor || typeof editor.isSavingPost !== 'function') return null;
            try { metaboxes = store.select('core/edit-post'); } catch (error) { metaboxes = null; }
            return {
                saving: editor.isSavingPost(),
                autosave: typeof editor.isAutosavingPost === 'function' && editor.isAutosavingPost(),
                preview: typeof editor.isPreviewingPost === 'function' && editor.isPreviewingPost(),
                metaboxes: !!(metaboxes && typeof metaboxes.isSavingMetaBoxes === 'function' && metaboxes.isSavingMetaBoxes()),
                success: typeof editor.didPostSaveRequestSucceed !== 'function' || editor.didPostSaveRequestSucceed()
            };
        }
        if (!state()) return;
        var wasSaving = false, pending = false, timer, generation = 0, requests = [];
        function status(root, text) {
            var target = root.querySelector('[data-tk-seo-score-status]');
            if (target) { target.textContent = text; target.hidden = !text; }
        }
        function cancel() {
            generation++;
            requests.forEach(function (controller) { controller.abort(); });
            requests = [];
            document.querySelectorAll('[data-tk-seo-score-post]').forEach(function (root) {
                root.removeAttribute('aria-busy'); status(root, '');
            });
        }
        async function refresh() {
            cancel();
            var current = generation, groups = {};
            document.querySelectorAll('[data-tk-seo-score-post]').forEach(function (root) {
                var id = root.dataset.tkSeoScorePost;
                (groups[id] || (groups[id] = [])).push(root);
            });
            await Promise.all(Object.keys(groups).map(async function (id) {
                var roots = groups[id], config = roots[0].dataset;
                var controller = new AbortController(); requests.push(controller);
                var timeout = setTimeout(function () { controller.abort(); }, 15000);
                roots.forEach(function (root) { root.setAttribute('aria-busy', 'true'); status(root, 'Updating content score…'); });
                try {
                    var response = await fetch(config.tkSeoScoreUrl, {
                        method: 'POST', credentials: 'same-origin', signal: controller.signal,
                        body: new URLSearchParams({action: 'tk_seo_content_score', post_id: id, nonce: config.tkSeoScoreNonce})
                    });
                    var result = await response.json();
                    if (current !== generation) return;
                    if (!response.ok || !result.success || !result.data || String(result.data.post_id) !== id
                        || typeof result.data.analysis_html !== 'string' || typeof result.data.badge_html !== 'string') {
                        throw new Error('Score refresh failed');
                    }
                    roots.forEach(function (root) {
                        var analysis = root.querySelector('[data-tk-seo-analysis-body]');
                        var badge = root.querySelector('[data-tk-seo-score-badge]');
                        if (analysis) analysis.innerHTML = result.data.analysis_html;
                        if (badge) badge.innerHTML = result.data.badge_html;
                        status(root, '');
                    });
                } catch (error) {
                    if (current !== generation) return;
                    roots.forEach(function (root) { status(root, 'Content score could not be refreshed. Save again or reload the editor.'); });
                } finally {
                    clearTimeout(timeout);
                    if (current === generation) roots.forEach(function (root) { root.removeAttribute('aria-busy'); });
                }
            }));
        }
        store.subscribe(function () {
            var current = state();
            if (!current) return;
            var saving = current.saving || current.metaboxes;
            if (saving && !wasSaving) {
                pending = !current.autosave && !current.preview;
                clearTimeout(timer); cancel();
            }
            wasSaving = saving;
            if (!pending || saving) return;
            // Gutenberg can start its separate metabox save after the post save finishes.
            clearTimeout(timer);
            timer = setTimeout(function () {
                var latest = state();
                if (!latest || latest.saving || latest.metaboxes) return;
                pending = false;
                if (latest.success) refresh();
            }, 150);
        });
        window.addEventListener('pagehide', function () { clearTimeout(timer); cancel(); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
}());
