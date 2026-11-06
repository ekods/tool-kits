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
        assert.equal(await page.$eval('[data-tk-gtm-status]', node => node.dataset.tkGtmStatus), 'configured');
        assert.match(await page.$eval('[data-tk-gtm-status]', node => node.textContent), /Configured: GTM-TEST123/);
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
        // Head code / Body code tabs.
        const tabState = () => page.evaluate(() => ({
            selected: [...document.querySelectorAll('[role=tab]')].map(tab => tab.getAttribute('aria-selected')),
            hidden: [...document.querySelectorAll('[role=tabpanel]')].map(panel => panel.hidden),
            tabbable: [...document.querySelectorAll('[role=tab]')].map(tab => tab.tabIndex),
            focused: document.activeElement && document.activeElement.id,
        }));
        const expectTab = async (index, message, focused) => {
            const state = await tabState();
            assert.deepEqual([state.selected, state.hidden, state.tabbable], [index === 0 ? ['true', 'false'] : ['false', 'true'], index === 0 ? [false, true] : [true, false], index === 0 ? [0, -1] : [-1, 0]], message);
            if (focused) assert.equal(state.focused, focused, message + ' (focus)');
        };
        assert.deepEqual(await page.$$eval('[role=tab]', tabs => tabs.map(tab => tab.textContent.trim())), ['Head code', 'Body code'], 'Two tabs named Head code and Body code');
        assert.equal(await page.$eval('.tk-analytics-tablist', node => getComputedStyle(node).display), 'flex', 'Tabs are shown once the script runs');
        await expectTab(0, 'Head code is selected first');
        // CodeMirror cannot measure a hidden editor: content changed while the body panel is hidden must still be laid out
        // (without the refresh on tab show only a couple of lines render and the editor cannot be scrolled).
        await page.$$eval('.CodeMirror', nodes => nodes[1].CodeMirror.setValue(Array.from({length: 60}, (_, i) => 'line ' + (i + 1) + ' ' + 'x'.repeat(40)).join('\n')));
        await page.click('#tk-gtm-tab-body');
        await expectTab(1, 'Clicking Body code shows the body panel');
        assert.ok(await page.$$eval('#tk-gtm-panel-body .CodeMirror-linenumber', nodes => nodes.length > 10), 'Body editor renders its lines once its tab is shown');
        await page.focus('#tk-gtm-tab-body');
        await page.keyboard.press('ArrowLeft'); await expectTab(0, 'ArrowLeft selects Head code', 'tk-gtm-tab-head');
        await page.keyboard.press('End'); await expectTab(1, 'End selects the last tab', 'tk-gtm-tab-body');
        await page.keyboard.press('Home'); await expectTab(0, 'Home selects the first tab', 'tk-gtm-tab-head');
        await page.keyboard.press('ArrowRight'); await expectTab(1, 'ArrowRight selects Body code');
        await page.keyboard.press('ArrowRight'); await expectTab(0, 'ArrowRight wraps to Head code');
        const head = '<!-- Google Tag Manager -->\n<script>window.dataLayer = window.dataLayer || [];\nwindow.dataLayer.push({event: "gtm.js", value: "a\\\\b"});</script>';
        const body = '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-TEST123" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
        await page.evaluate((head, body) => {
            const editors = document.querySelectorAll('.CodeMirror');
            editors[0].CodeMirror.setValue(head); editors[1].CodeMirror.setValue(body);
        }, head, body);
        // The body panel is hidden here: both snippets must still be submitted.
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
        assert.equal(await page.$eval('[data-tk-gtm-status]', node => node.dataset.tkGtmStatus), 'configured', 'Badge represents saved configuration until the edited form is saved');
        await page.addScriptTag({path: path.join(root, 'assets/analytics-editor.js')});
        assert.equal(await page.$$eval('.CodeMirror', nodes => nodes.length), 2, 'No duplicate editor initialization');
        const fallback = await browser.newPage();
        await fallback.setContent(html);
        await fallback.evaluate(() => { window.tkAnalyticsEditor = {settings: false}; });
        await fallback.addScriptTag({path: path.join(root, 'assets/analytics-editor.js')});
        assert.equal(await fallback.$$eval('.CodeMirror', nodes => nodes.length), 0);
        assert.equal(await fallback.$eval('[name="google_tag_manager_head"]', field => getComputedStyle(field).display !== 'none'), true, 'Plain textarea fallback stays editable');
        assert.equal(await fallback.$eval('[data-tk-analytics-tabs]', node => node.classList.contains('is-ready')), true, 'Tabs work without the code editor');
        await fallback.click('#tk-gtm-tab-body');
        assert.deepEqual(await fallback.$$eval('[name^="google_tag_manager_"]', fields => fields.map(field => field.offsetParent !== null)), [false, true], 'Body textarea shown after choosing its tab');
        await fallback.setJavaScriptEnabled(false); await fallback.setContent(html);
        await fallback.addStyleTag({path: path.join(root, 'assets/analytics-editor.css')});
        assert.equal(await fallback.$eval('.tk-analytics-tablist', node => getComputedStyle(node).display), 'none', 'Tab buttons stay hidden without JavaScript');
        assert.deepEqual(await fallback.$$eval('[name^="google_tag_manager_"]', fields => fields.map(field => field.offsetParent !== null)), [true, true], 'Both fields stay visible without JavaScript');
        assert.match(await fallback.$eval('form', form => new FormData(form).get('google_tag_manager_body')), /<noscript><iframe/);
        for (const [flag, state] of [['--empty', 'not-set'], ['--incomplete', 'incomplete'], ['--mismatch', 'mismatch']]) {
            const preview = execFileSync(process.env.TK_PHP_BINARY || 'php', [path.join(__dirname, 'analytics-gtm-regression.php'), '--preview', flag], {encoding: 'utf8'});
            await page.setContent(preview);
            assert.equal(await page.$eval('[data-tk-gtm-status]', node => node.dataset.tkGtmStatus), state);
            assert.equal(await page.$eval('[data-tk-gtm-status]', node => node.classList.contains('tk-on')), false, 'Incomplete or mismatched config must not show green');
        }
        assert.deepEqual(errors, []);
        console.log('PASS: saved GTM configuration indicators, mismatch/incomplete/empty states, real WordPress CodeMirror, Head/Body tabs with keyboard support, submitted snippets, responsive layout and textarea fallback');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
