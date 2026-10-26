// Requires Puppeteer and a local WordPress core: TK_WP_CORE=/path/to/wordpress node scripts/analytics-gtm-ui-regression.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');

(async () => {
    const {default: puppeteer} = await import('puppeteer');
    const core = process.env.TK_WP_CORE;
    assert.ok(core && fs.existsSync(path.join(core, 'wp-admin/js/code-editor.js')), 'Set TK_WP_CORE to a WordPress installation');
    const root = path.resolve(__dirname, '..');
    const html = execFileSync(process.env.TK_PHP_BINARY || 'php', [path.join(__dirname, 'analytics-gtm-regression.php'), '--preview'], {encoding: 'utf8'});
    const browser = await puppeteer.launch({headless: true});
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent(html);
        await page.addStyleTag({content: 'body{margin:0;padding:16px;box-sizing:border-box;background:#f0f0f1;font:13px/1.5 -apple-system,BlinkMacSystemFont,sans-serif;color:#1d2327}main{max-width:960px;margin:auto;min-width:0}*{box-sizing:border-box}'});
        for (const file of ['wp-admin/css/forms.min.css', 'wp-admin/css/buttons.min.css', 'wp-includes/js/codemirror/codemirror.min.css']) {
            const asset = path.join(core, file);
            if (fs.existsSync(asset)) await page.addStyleTag({path: asset});
        }
        await page.addStyleTag({path: path.join(root, 'assets/admin.css')});
        await page.addStyleTag({path: path.join(root, 'assets/analytics-editor.css')});
        for (const file of ['wp-includes/js/jquery/jquery.min.js', 'wp-includes/js/underscore.min.js', 'wp-includes/js/codemirror/codemirror.min.js', 'wp-admin/js/code-editor.js']) await page.addScriptTag({path: path.join(core, file)});
        await page.evaluate(() => { window.tkAnalyticsEditor = {settings: {codemirror: {mode: 'htmlmixed', lineNumbers: true, lineWrapping: true, lint: false}}}; });
        await page.addScriptTag({path: path.join(root, 'assets/analytics-editor.js')});
        assert.equal(await page.$$eval('.CodeMirror', nodes => nodes.length), 2, 'Two real WordPress CodeMirror editors');
        const head = '<!-- Google Tag Manager -->\n<script>window.dataLayer = window.dataLayer || [];\nwindow.dataLayer.push({event: "gtm.js", value: "a\\\\b"});</script>';
        const body = '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-TEST123" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
        await page.evaluate((head, body) => {
            const editors = document.querySelectorAll('.CodeMirror');
            editors[0].CodeMirror.setValue(head); editors[1].CodeMirror.setValue(body);
        }, head, body);
        const saved = await page.$eval('form', form => Object.fromEntries(new FormData(form)));
        assert.equal(saved.google_tag_manager_head, head, 'Head editor syncs before FormData serialization');
        assert.equal(saved.google_tag_manager_body, body, 'Body editor syncs before FormData serialization');
        assert.equal(await page.evaluate(() => window.dataLayer), undefined, 'Pasted tracking code does not execute in admin');
        assert.equal(await page.$$eval('.CodeMirror-linenumber', nodes => nodes.length > 0), true);
        for (const width of [1440, 390, 320]) {
            await page.setViewport({width, height: 1000});
            await page.$$eval('.CodeMirror', nodes => nodes.forEach(node => node.CodeMirror.refresh()));
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No page overflow at ' + width);
            if (width === 1440) await page.screenshot({path: '/tmp/toolkits-gtm-desktop.png', fullPage: true});
            if (width === 390) await page.screenshot({path: '/tmp/toolkits-gtm-mobile.png', fullPage: true});
        }
        await page.$$eval('.CodeMirror', nodes => nodes[0].CodeMirror.setValue(''));
        assert.equal(await page.$eval('form', form => new FormData(form).get('google_tag_manager_head')), '', 'Clearing the editor clears the submitted value');
        await page.addScriptTag({path: path.join(root, 'assets/analytics-editor.js')});
        assert.equal(await page.$$eval('.CodeMirror', nodes => nodes.length), 2, 'No duplicate editor initialization');
        const fallback = await browser.newPage();
        await fallback.setContent(html);
        await fallback.evaluate(() => { window.tkAnalyticsEditor = {settings: false}; });
        await fallback.addScriptTag({path: path.join(root, 'assets/analytics-editor.js')});
        assert.equal(await fallback.$$eval('.CodeMirror', nodes => nodes.length), 0);
        assert.equal(await fallback.$eval('[name="google_tag_manager_head"]', field => getComputedStyle(field).display !== 'none'), true, 'Plain textarea fallback stays editable');
        await fallback.setJavaScriptEnabled(false); await fallback.setContent(html);
        assert.match(await fallback.$eval('form', form => new FormData(form).get('google_tag_manager_body')), /<noscript><iframe/);
        assert.deepEqual(errors, []);
        console.log('PASS: real WordPress CodeMirror, two HTML editors, exact submitted snippets, no admin execution, clear, responsive layout and textarea fallback');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
