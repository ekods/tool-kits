// The third menu level reuses WordPress's own flyout markup (ul.wp-submenu.wp-submenu-wrap) and its "opensub" state.
// Runs the real script from includes/helpers.php and the real assets/admin-menu.css over core's admin menu CSS.
// Requires Puppeteer and a local WordPress core: TK_WP_CORE=/path/to/wordpress node scripts/admin-menu-flyout-ui-regression.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const root = path.resolve(__dirname, '..');
const core = process.env.TK_WP_CORE;
assert.ok(core && fs.existsSync(path.join(core, 'wp-admin/css/admin-menu.min.css')), 'Set TK_WP_CORE to a WordPress installation');

const helpers = fs.readFileSync(path.join(root, 'includes/helpers.php'), 'utf8');
const script = /function tk_toolkits_nested_admin_menu_script[\s\S]*?<script>([\s\S]*?)<\/script>/.exec(helpers)[1];
const scheme = ['modern', 'fresh', 'blue'].find(name => fs.existsSync(path.join(core, 'wp-admin/css/colors', name, 'colors.min.css')));
assert.ok(scheme, 'Core ships an admin color scheme stylesheet');

// Flat submenu as WordPress prints it; the script moves the children into their group.
const items = [
    ['tool-kits', 'Overview'], ['tool-kits-monitoring', 'Monitoring'],
    ['tool-kits-settings', 'Settings'], ['tool-kits-general', 'General'], ['tool-kits-access', 'Access'],
    ['tool-kits-security', 'Security'], ['tool-kits-guard', 'Guard'], ['tool-kits-firewall', 'Firewall'], ['tool-kits-security-hide-login', 'Hide Login'],
    ['tool-kits-performance', 'Performance'], ['tool-kits-cache', 'Cache'], ['tool-kits-minify', 'Minify'], ['tool-kits-image-opt', 'Images'],
    ['tool-kits-seo', 'SEO'],
    ['tool-kits-system', 'System'], ['tool-kits-db', 'Database Tools'], ['tool-kits-smtp', 'SMTP Delivery'],
];
function pageHtml({current, folded}) {
    const list = items.map(([slug, label], index) => {
        const isCurrent = current && slug === 'tool-kits-cache';
        return `<li class="${(index === 0 ? 'wp-first-item ' : '') + (isCurrent ? 'current' : '')}"><a href="admin.php?page=${slug}" class="${isCurrent ? 'current' : ''}"${isCurrent ? ' aria-current="page"' : ''}>${label}</a></li>`;
    }).join('');
    const state = current ? 'wp-has-submenu wp-has-current-submenu wp-menu-open' : 'wp-has-submenu wp-not-current-submenu';
    const sheets = ['/core/wp-includes/css/dashicons.min.css', '/core/wp-admin/css/common.min.css', '/core/wp-admin/css/admin-menu.min.css', `/core/wp-admin/css/colors/${scheme}/colors.min.css`, '/tk/assets/admin-menu.css'];
    return `<!doctype html><html class="js"><head><meta charset="utf-8">${sheets.map(href => `<link rel="stylesheet" href="${href}">`).join('')}<style>body{margin:0;min-height:900px}</style></head>`
        + `<body class="wp-admin wp-core-ui js ${folded ? 'folded' : 'auto-fold'}"><div id="adminmenumain"><div id="adminmenuback"></div><div id="adminmenuwrap"><ul id="adminmenu">`
        + `<li class="wp-not-current-submenu menu-top"><a href="edit.php" class="wp-not-current-submenu menu-top"><div class="wp-menu-name">Posts</div></a></li>`
        + `<li class="${state} menu-top" id="toplevel_page_tool-kits"><a href="admin.php?page=tool-kits" class="${state} menu-top"><div class="wp-menu-name">Tool Kits</div></a>`
        + `<ul class="wp-submenu wp-submenu-wrap"><li class="wp-submenu-head" aria-hidden="true">Tool Kits</li>${list}</ul></li></ul></div></div></body></html>`;
}

(async () => {
    const {default: puppeteer} = await import('puppeteer');
    const pages = {};
    const server = http.createServer((request, response) => {
        const url = decodeURIComponent(request.url.split('?')[0]);
        if (pages[url]) { response.writeHead(200, {'Content-Type': 'text/html'}); response.end(pages[url]); return; }
        const file = url.startsWith('/core/') ? path.join(core, url.slice(6)) : path.join(root, url.replace(/^\/tk\//, ''));
        fs.readFile(file, (error, data) => {
            if (error) { response.writeHead(404); response.end(); return; }
            const type = {'.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2'}[path.extname(file)] || 'application/octet-stream';
            response.writeHead(200, {'Content-Type': type}); response.end(data);
        });
    }).listen(0, '127.0.0.1');
    await new Promise(resolve => server.on('listening', resolve));
    const base = 'http://127.0.0.1:' + server.address().port;
    const browser = await puppeteer.launch({headless: true});
    let checks = 0;
    const check = (condition, message) => { assert.ok(condition, message); checks++; };
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setViewport({width: 1000, height: 900});
        const link = slug => `#toplevel_page_tool-kits .wp-submenu > li:has(> a[href$="page=${slug}"]) > a`;
        const groups = () => page.evaluate(() => [...document.querySelectorAll('#toplevel_page_tool-kits .wp-submenu > li.wp-has-submenu')].map(item => {
            const list = item.querySelector(':scope > .wp-submenu').getBoundingClientRect();
            const anchor = item.querySelector(':scope > a');
            return {name: anchor.textContent.trim(), opensub: item.classList.contains('opensub'), shown: list.top > -200 && list.height > 0, expanded: anchor.getAttribute('aria-expanded')};
        }));
        const shown = async () => (await groups()).filter(group => group.shown).map(group => group.name).join();

        for (const [name, options] of [['current menu', {current: true}], ['flyout from a hovered top-level item', {current: false}], ['folded menu, current', {current: true, folded: true}], ['folded menu, flyout', {current: false, folded: true}]]) {
            const url = '/' + name.replace(/\W+/g, '-') + '.html';
            pages[url] = pageHtml(options);
            await page.goto(base + url, {waitUntil: 'networkidle0'});
            // Core adds opensub to a hovered top-level item, which is how its flyout (and, when folded, even the current
            // menu's list) is shown; do the same so core's own rules are in play for the nested lists.
            if (!options.current || options.folded) await page.evaluate(() => document.getElementById('toplevel_page_tool-kits').classList.add('opensub'));
            await page.evaluate(script);

            const lists = await page.$$eval('#toplevel_page_tool-kits .wp-submenu > li > .wp-submenu', nodes => nodes.map(node => node.className));
            check(lists.length === 4 && lists.every(className => className === 'wp-submenu wp-submenu-wrap'), name + ': nested lists use core wp-submenu wp-submenu-wrap');
            check(await page.evaluate(() => !document.querySelector('[class*="tk-submenu-flyout"], .is-open, .tk-submenu-group, .has-tk-submenu')), name + ': no custom flyout classes');
            check((await shown()) === '' && (await groups()).every(group => group.expanded === 'false'), name + ': every nested list is closed at rest');

            await page.hover(link('tool-kits-security')); await sleep(60);
            const open = (await groups()).find(group => group.name === 'Security');
            check((await shown()) === 'Security' && open.opensub && open.expanded === 'true', name + ': hover opens only that group (opensub, aria-expanded)');
            const geometry = await page.evaluate(() => {
                const item = document.querySelector('#toplevel_page_tool-kits .wp-submenu > li.opensub');
                const list = item.querySelector(':scope > .wp-submenu');
                const a = item.getBoundingClientRect(), b = list.getBoundingClientRect();
                const coreList = getComputedStyle(document.querySelector('#toplevel_page_tool-kits > .wp-submenu'));
                return {gap: Math.round(b.left - a.right), top: Math.round(b.top - a.top), background: getComputedStyle(list).backgroundColor, coreBackground: coreList.backgroundColor};
            });
            check(Math.abs(geometry.gap) <= 1 && Math.abs(geometry.top) <= 2, name + ': flyout sits beside its item (' + geometry.gap + 'px, ' + geometry.top + 'px)');
            check(geometry.background === geometry.coreBackground && geometry.background !== 'rgba(0, 0, 0, 0)', name + ': uses the core submenu background of the active color scheme');

            await page.hover(link('tool-kits-performance')); await sleep(60);
            check((await shown()) === 'Performance', name + ': moving to another group closes the first');
            await page.mouse.move(900, 800); await sleep(260);
            check((await shown()) === '' && (await groups()).every(group => !group.opensub), name + ': leaving closes the flyout');

            if (options.folded) continue;
            await page.focus(link('tool-kits-system')); await sleep(60);
            check((await shown()) === 'System', name + ': keyboard focus opens the group');
            await page.keyboard.press('Escape'); await sleep(30);
            check((await shown()) === '' && (await page.evaluate(() => document.activeElement.textContent.trim())) === 'System', name + ': Escape closes it and keeps focus on the group link');
            if (options.current) {
                check(await page.evaluate(() => document.querySelector('#toplevel_page_tool-kits .wp-submenu > li.tk-submenu-current > a').textContent.trim()) === 'Performance', name + ': the group holding the current page is marked');
                await page.hover(link('tool-kits-performance')); await sleep(60);
                const colors = await page.evaluate(() => {
                    const links = [...document.querySelectorAll('#toplevel_page_tool-kits li.opensub > .wp-submenu a')];
                    const color = label => getComputedStyle(links.find(anchor => anchor.textContent.trim() === label)).color;
                    return {current: color('Cache'), other: color('Minify')};
                });
                check(colors.current === 'rgb(255, 255, 255)' && colors.other !== colors.current, name + ': only the current child is highlighted');
            }
        }
        check(errors.length === 0, 'No JavaScript errors: ' + errors.join('; '));
        console.log('PASS: ' + checks + ' admin menu flyout checks (core wp-submenu-wrap markup and opensub state, hover, focus, Escape, current item, folded menu)');
    } finally { await browser.close(); server.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
