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
    // Opt-in data table: <table data-tk-datatable [data-tk-page-size="10"]>, <th data-tk-sort>, <td data-tk-sort-value>.
    // Rows stay server-rendered; this only filters, sorts and pages the existing tbody rows.
    function datatable(table) {
        const body = table.tBodies[0];
        if (table.dataset.tkDtReady || !body) return;
        table.dataset.tkDtReady = '1';
        const rows = Array.from(body.rows);
        const columns = table.tHead ? table.tHead.rows[0].cells.length : 1;
        const region = table.closest('.tk-table-scroll') || table;
        const sizes = [10, 25, 50, 100];
        let pageSize = Number(table.dataset.tkPageSize) || 10;
        let page = 1, query = '', sortIndex = -1, sortDir = 1;

        const toolbar = document.createElement('div');
        toolbar.className = 'tk-dt-toolbar';
        const sizeLabel = document.createElement('label');
        const size = document.createElement('select');
        sizes.concat(sizes.includes(pageSize) ? [] : [pageSize]).sort(function (a, b) { return a - b; }).forEach(function (value) {
            size.add(new Option(String(value), String(value), false, value === pageSize));
        });
        sizeLabel.append('Show ', size, ' entries');
        const searchLabel = document.createElement('label');
        const search = document.createElement('input');
        search.type = 'search';
        search.placeholder = 'Search…';
        searchLabel.append('Search ', search);
        toolbar.append(sizeLabel, searchLabel);
        region.before(toolbar);

        const footer = document.createElement('div');
        footer.className = 'tk-dt-footer';
        const info = document.createElement('div');
        info.setAttribute('aria-live', 'polite');
        const pager = document.createElement('div');
        pager.className = 'tk-dt-pages';
        footer.append(info, pager);
        region.after(footer);

        function sortValue(row) {
            const cell = row.cells[sortIndex];
            return cell ? (cell.dataset.tkSortValue ?? cell.textContent.trim().toLowerCase()) : '';
        }
        function compare(a, b) {
            const x = sortValue(a), y = sortValue(b);
            const nx = Number(x), ny = Number(y);
            const result = x !== '' && y !== '' && !isNaN(nx) && !isNaN(ny) ? nx - ny : String(x).localeCompare(String(y));
            return result * sortDir;
        }
        function pageButton(label, target, current) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button button-small';
            button.textContent = label;
            if (current) button.setAttribute('aria-current', 'page');
            if (target === null) button.disabled = true;
            else button.addEventListener('click', function () { page = target; render(); });
            return button;
        }
        function render() {
            const needle = query.toLowerCase();
            const list = rows.filter(function (row) { return !needle || row.textContent.toLowerCase().includes(needle); });
            if (sortIndex >= 0) list.sort(compare);
            const pages = Math.max(1, Math.ceil(list.length / pageSize));
            page = Math.min(page, pages);
            const start = (page - 1) * pageSize;
            const visible = list.slice(start, start + pageSize);
            if (visible.length) {
                body.replaceChildren.apply(body, visible);
            } else {
                const empty = document.createElement('tr');
                empty.className = 'tk-dt-empty';
                const cell = empty.insertCell();
                cell.colSpan = columns;
                cell.textContent = 'No matching entries.';
                body.replaceChildren(empty);
            }
            info.textContent = list.length
                ? 'Showing ' + (start + 1) + '–' + (start + visible.length) + ' of ' + list.length + (list.length === 1 ? ' entry' : ' entries')
                    + (list.length !== rows.length ? ' (filtered from ' + rows.length + ')' : '')
                : 'Showing 0 entries' + (rows.length ? ' (filtered from ' + rows.length + ')' : '');
            pager.replaceChildren();
            if (pages > 1) {
                pager.append(pageButton('‹', page > 1 ? page - 1 : null, false));
                let last = 0;
                for (let n = 1; n <= pages; n++) {
                    if (n !== 1 && n !== pages && Math.abs(n - page) > 1) continue;
                    if (n - last > 1) pager.append(pageButton('…', null, false));
                    pager.append(pageButton(String(n), n, n === page));
                    last = n;
                }
                pager.append(pageButton('›', page < pages ? page + 1 : null, false));
            }
        }

        size.addEventListener('change', function () { pageSize = Number(size.value) || 10; page = 1; render(); });
        search.addEventListener('input', function () { query = search.value.trim(); page = 1; render(); });
        Array.from(table.tHead ? table.tHead.rows[0].cells : []).forEach(function (th, index) {
            if (!th.hasAttribute('data-tk-sort')) return;
            th.tabIndex = 0;
            th.setAttribute('aria-sort', 'none');
            function toggle() {
                sortDir = sortIndex === index ? -sortDir : 1;
                sortIndex = index;
                table.querySelectorAll('thead th[data-tk-sort]').forEach(function (other) { other.setAttribute('aria-sort', 'none'); });
                th.setAttribute('aria-sort', sortDir === 1 ? 'ascending' : 'descending');
                page = 1;
                render();
            }
            th.addEventListener('click', toggle);
            th.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); toggle(); }
            });
        });
        render();
    }
    function enhance(root) {
        if (root.matches('table.widefat, table.tk-table')) prepareTable(root);
        root.querySelectorAll('table.widefat, table.tk-table').forEach(prepareTable);
        if (root.matches('table[data-tk-datatable]')) datatable(root);
        root.querySelectorAll('table[data-tk-datatable]').forEach(datatable);
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
