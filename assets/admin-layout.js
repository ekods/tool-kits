/* Progressive table layout for Tool Kits pages. No form or module state changes. */
(function () {
    'use strict';
    function paginateContentAudit(table, region) {
        if (!table.matches('.tk-content-audit-table') || table.dataset.tkPaginationReady) return;
        const rows = Array.from(table.tBodies).flatMap(body => Array.from(body.rows));
        if (!rows.length) return;
        table.dataset.tkPaginationReady = '1';
        let page = 1;
        let perPage = 10;
        const nav = document.createElement('nav');
        nav.className = 'tk-content-audit-pagination';
        nav.setAttribute('aria-label', 'Content SEO Audit pagination');
        const count = document.createElement('span');
        count.setAttribute('role', 'status');
        const label = document.createElement('label');
        label.textContent = 'Posts per page ';
        const select = document.createElement('select');
        [10, 25, 50, 100].forEach(size => {
            const option = document.createElement('option');
            option.value = String(size); option.textContent = String(size); select.appendChild(option);
        });
        label.appendChild(select);
        const controls = document.createElement('span');
        controls.className = 'tk-content-audit-page-controls';
        const position = document.createElement('span');
        const buttons = ['First', 'Previous', 'Next', 'Last'].map((text, index) => {
            if (index === 2) controls.appendChild(position);
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'button'; button.textContent = text;
            button.addEventListener('click', () => {
                const pages = Math.ceil(rows.length / perPage);
                page = [1, Math.max(1, page - 1), Math.min(pages, page + 1), pages][index];
                render();
            });
            controls.appendChild(button); return button;
        });
        function render() {
            const pages = Math.ceil(rows.length / perPage);
            const start = (page - 1) * perPage;
            rows.forEach((row, index) => { row.hidden = index < start || index >= start + perPage; });
            count.textContent = 'Showing ' + (start + 1) + '–' + Math.min(start + perPage, rows.length) + ' of ' + rows.length + ' posts';
            position.textContent = 'Page ' + page + ' of ' + pages;
            buttons.forEach((button, index) => { button.disabled = index < 2 ? page === 1 : page === pages; });
            region.scrollTop = 0;
        }
        select.addEventListener('change', () => {
            const start = (page - 1) * perPage;
            perPage = Number(select.value); page = Math.floor(start / perPage) + 1; render();
        });
        nav.append(count, label, controls); region.before(nav); render();
    }
    function prepareTable(table) {
        if (table.matches('.form-table, .wp-list-table') || table.parentElement.closest('table')) return;
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
        paginateContentAudit(table, region);
    }
    function enhance(root) {
        if (root.matches('table.widefat')) prepareTable(root);
        root.querySelectorAll('table.widefat').forEach(prepareTable);
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
