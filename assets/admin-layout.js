/* Progressive table layout for Tool Kits pages. No form or module state changes. */
(function () {
    'use strict';
    function prepareTable(table) {
        if (table.matches('.form-table') || table.parentElement.closest('table')) return;
        let region = table.closest('.tk-table-scroll');
        if (!region) {
            // Reuse an existing scroll container supplied by a module.
            const parent = table.parentElement;
            const overflow = getComputedStyle(parent).overflowX;
            if (overflow === 'auto' || overflow === 'scroll') {
                region = parent;
                region.classList.add('tk-table-scroll');
            } else {
                region = document.createElement('div');
                region.className = 'tk-table-scroll';
                table.before(region);
                region.appendChild(table);
            }
        }
        // Existing module labels take precedence. Preserve the table's native semantics.
        if (!region.hasAttribute('tabindex')) region.tabIndex = 0;
        if (!region.hasAttribute('role')) region.setAttribute('role', 'region');
        if (!region.hasAttribute('aria-label') && !region.hasAttribute('aria-labelledby')) {
            const heading = table.closest('.tk-card, .tk-tab-panel, .tk-image-panel, section')?.querySelector('h2, h3');
            const caption = table.caption?.textContent.trim();
            region.setAttribute('aria-label', (caption || heading?.textContent.trim() || 'Tool Kits data') + ' — scrollable table');
        }
    }
    function enhance(root) {
        if (root.matches('table.widefat, table.tk-table')) prepareTable(root);
        root.querySelectorAll('table.widefat, table.tk-table').forEach(prepareTable);
    }
    function init() {
        document.querySelectorAll('.tk-wrap, .tk-overview-wrap').forEach(function (root) {
            if (root.parentElement.closest('.tk-wrap, .tk-overview-wrap') || root.dataset.tkLayoutReady) return;
            root.dataset.tkLayoutReady = '1';
            enhance(root);
            // AJAX reports may introduce new tables after initial page load.
            new MutationObserver(function (records) {
                records.forEach(function (record) {
                    record.addedNodes.forEach(function (node) {
                        if (node.nodeType === 1) enhance(node);
                    });
                });
            }).observe(root, {childList: true, subtree: true});
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
    else init();
})();
