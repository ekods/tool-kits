// Production admin templates with isolated data and real WordPress CSS, without a live database.
// TK_PREVIEW_WP_ROOT=/path/to/wordpress node scripts/admin-layout-ui-regression.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {pathToFileURL} = require('node:url');

(async () => {
    const {default: puppeteer} = await import('puppeteer');
    const root = path.resolve(__dirname, '..');
    const output = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkits-layout-'));
    const views = process.argv.slice(2).length ? process.argv.slice(2) : ['overview', 'monitoring', 'image', 'assets', 'otp', 'uploads', 'antispam', 'lazy-load', 'minify', 'captcha', 'smtp-dns', 'schema-editor'];
    const browser = await puppeteer.launch({headless: true});
    const failures = [];
    let checks = 0;
    try {
        const page = await browser.newPage();
        await page.setRequestInterception(true);
        page.on('request', request => {
            if (/^https?:/.test(request.url())) return request.respond({status: 200, contentType: 'application/json', body: '{"success":false,"data":{"message":"Preview: no live request"}}'});
            return request.continue();
        });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        for (const view of views) {
            const file = path.join(output, view + '.html');
            const args = [path.join(__dirname, 'render-image-ui-preview.php'), file];
            if (view !== 'image') args.push('--' + view);
            execFileSync(process.env.TK_PHP_BINARY || 'php', args, {env: process.env, stdio: 'pipe'});
            await page.goto(pathToFileURL(file).href, {waitUntil: 'load'});
            if (view === 'smtp-dns') await page.$eval('.tk-tab-panel', panel => panel.classList.add('is-active'));
            if (view === 'schema-editor') await page.$eval('dialog', dialog => dialog.showModal());
            if (view === 'assets') {
                assert.equal(await page.$eval('[name="assets_font_display_swap"]', input => input.checked && getComputedStyle(input).backgroundColor !== 'rgba(0, 0, 0, 0)'), true, 'Checked settings need a visible selected state');
                checks++;
            }
            for (const width of [1440, 1024, 782, 390, 320]) {
                await page.setViewport({width, height: 960});
                await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
                const layout = await page.evaluate(() => ({
                    width: innerWidth,
                    scroll: document.documentElement.scrollWidth,
                    offenders: [...document.querySelectorAll('main *')].filter(node => {
                        const rect = node.getBoundingClientRect();
                        return rect.width && rect.right > innerWidth + 1 && !node.closest('.tk-table-scroll, .tk-tabs-nav, pre, .CodeMirror');
                    }).slice(0, 8).map(node => node.tagName + '.' + node.className),
                }));
                if (layout.scroll > width + 1) failures.push(view + ' at ' + width + ': ' + JSON.stringify(layout));
                else checks++;
                if (view === 'schema-editor') {
                    assert.equal(await page.$eval('dialog', dialog => dialog.scrollWidth <= dialog.clientWidth + 1), true, 'Dialog content overflows at ' + width);
                    checks++;
                }
                if (width === 1440 || width === 390) await page.screenshot({path: path.join(output, view + '-' + width + '.png'), fullPage: true});
            }
            const before = await page.$$eval('form', forms => forms.map(form => [...new FormData(form)]));
            const wrappers = await page.$$eval('.tk-table-scroll', nodes => nodes.length);
            await page.addScriptTag({path: path.join(root, 'assets/admin-layout.js')});
            assert.equal(await page.$$eval('.tk-table-scroll', nodes => nodes.length), wrappers, 'Reinitialization duplicated table wrappers');
            assert.deepEqual(await page.$$eval('form', forms => forms.map(form => [...new FormData(form)])), before, 'Layout changed submitted form values');
            checks += 2;
            console.log('Checked ' + view);
        }
        // A dynamically inserted report remains a real table, with working controls and keyboard scrolling.
        await page.evaluate(() => {
            document.querySelectorAll('dialog[open]').forEach(dialog => dialog.close());
            const host = document.querySelector('.tk-wrap');
            const section = document.createElement('section');
            section.className = 'tk-card';
            section.innerHTML = '<h2>Async report</h2><form><table class="widefat" id="tk-async-table"><caption>Results</caption><tbody><tr><td><input name="selection" value="kept"><button type="button" id="tk-row-action">Inspect</button></td></tr></tbody></table></form>';
            host.appendChild(section);
            document.getElementById('tk-row-action').addEventListener('click', () => { window.tkRowClicked = true; });
        });
        await page.waitForSelector('#tk-async-table:only-child');
        await page.waitForFunction(() => document.getElementById('tk-async-table').parentElement.classList.contains('tk-table-scroll'));
        assert.equal(await page.$eval('#tk-async-table', table => new FormData(table.closest('form')).get('selection')), 'kept');
        await page.click('#tk-row-action');
        assert.equal(await page.evaluate(() => window.tkRowClicked), true);
        assert.equal(await page.$eval('#tk-async-table', table => {
            const region = table.parentElement;
            region.focus();
            return region.getAttribute('role') === 'region' && region.getAttribute('aria-label').includes('Results') && document.activeElement === region;
        }), true);
        checks += 3;
        // Shared CSS/JS must not wrap or redesign unrelated WordPress tables.
        await page.setContent('<main><div class="wrap"><table class="widefat"><tr><td>WordPress page</td></tr></table></div></main>');
        await page.addScriptTag({path: path.join(root, 'assets/admin-layout.js')});
        assert.equal(await page.$$eval('.tk-table-scroll', nodes => nodes.length), 0);
        checks++;
        assert.deepEqual(errors, [], 'Admin JavaScript errors');
        assert.deepEqual(failures, [], 'Responsive layout overflow');
        console.log('PASS: ' + checks + ' layout/form/dependency-preserving DOM checks across ' + views.length + ' admin views; previews: ' + output);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
