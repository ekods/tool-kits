// Uses the prepared, disposable WordPress runtime and cf7-ui-fixture.json.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const puppeteer = require('puppeteer');

(async () => {
    const origin = process.env.PLUGIN_UI_ORIGIN || 'http://127.0.0.1:8897';
    assert.ok(['127.0.0.1', 'localhost', '[::1]'].includes(new URL(origin).hostname), 'Use an isolated loopback runtime');
    const runtime = process.env.WP_ADMIN_TEST_ROOT || '/tmp/nexa-ui-runtime';
    const fixture = JSON.parse(fs.readFileSync(path.join(runtime, 'cf7-ui-fixture.json'), 'utf8'));
    const output = fs.mkdtempSync('/tmp/toolkits-cf7-native-');
    const browser = await puppeteer.launch({headless: true});
    let checks = 0;
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(origin + '/wp-login.php', {waitUntil: 'networkidle0'});
        await page.type('#user_login', 'ui_review');
        await page.type('#user_pass', 'ui-review-local-2026!');
        await Promise.all([page.waitForNavigation({waitUntil: 'networkidle0'}), page.click('#wp-submit')]);
        const base = origin + '/wp-admin/admin.php?page=tool-kits-cf7-collection';
        const tick = () => page.evaluate(() => new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))));
        for (const [name, query] of [['dashboard', ''], ['forms', '&view=forms'], ['messages', '&form_id=' + fixture.form]]) {
            await page.setViewport({width: 1440, height: 1000});
            await page.goto(base + query, {waitUntil: 'networkidle0'});
            assert.equal(await page.$$eval('.cf7dbc-page-heading .tk-header-brand', n => n.length), 1, name + ' Tool Kits branding'); checks++;
            for (const width of [1440, 1024, 782, 390, 320]) {
                await page.setViewport({width, height: 1000}); await tick();
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, name + ' page overflow at ' + width); checks++;
                const native = await page.evaluate(() => {
                    const button = document.querySelector('.cf7dbc-actions .button');
                    const copy = button.cloneNode(true); document.body.appendChild(copy);
                    const properties = ['fontSize', 'fontFamily', 'lineHeight', 'padding', 'minHeight', 'borderRadius', 'borderColor', 'backgroundColor', 'boxShadow'];
                    const matches = properties.every(key => getComputedStyle(button)[key] === getComputedStyle(copy)[key]);
                    copy.remove();
                    const table = document.querySelector('.cf7dbc-wrap .wp-list-table');
                    if (!table) return matches;
                    const reference = table.cloneNode(true); document.body.appendChild(reference);
                    const tableMatches = ['border', 'boxShadow', 'borderRadius', 'fontFamily'].every(key => getComputedStyle(table)[key] === getComputedStyle(reference)[key]);
                    const headerMatches = ['fontSize', 'padding', 'backgroundColor', 'borderBottom'].every(key => getComputedStyle(table.querySelector('th'))[key] === getComputedStyle(reference.querySelector('th'))[key]);
                    reference.remove(); return matches && tableMatches && headerMatches;
                });
                assert.equal(native, true, name + ' native buttons/table at ' + width); checks++;
                if (name !== 'dashboard') {
                    assert.equal(await page.$eval('.current-page', n => n.form.method), 'get');
                    assert.equal(await page.$eval('input[name=page]', n => n.value), 'tool-kits-cf7-collection');
                    checks += 2;
                    if (width === 390) {
                        await page.click('.wp-list-table tbody tr:first-child .toggle-row');
                        assert.equal(await page.$eval('.wp-list-table tbody tr:first-child', n => n.classList.contains('is-expanded')), true);
                        assert.notEqual(await page.$eval('.wp-list-table tbody tr:first-child td:nth-child(2)', n => getComputedStyle(n).display), 'none');
                        await page.click('.wp-list-table tbody tr:first-child .toggle-row');
                        checks += 2;
                    }
                }
                if (width === 1440 || width === 390) await page.screenshot({path: path.join(output, name + '-' + width + '.png'), fullPage: true});
            }
            if (name !== 'dashboard') {
                await page.setViewport({width: 1440, height: 1000});
                await Promise.all([page.waitForNavigation({waitUntil: 'networkidle0'}), page.click('.next-page')]);
                assert.equal(new URL(page.url()).searchParams.get('paged'), '2');
                assert.equal(await page.$$eval('.wp-list-table tbody tr', rows => rows.length), name === 'forms' ? 3 : 5);
                checks += 2;
            }
            console.log('Checked native CF7 ' + name);
        }
        for (const width of [1440, 390, 320]) {
            await page.setViewport({width, height: 1000});
            await page.goto(base + '&form_id=' + fixture.form, {waitUntil: 'networkidle0'});
            const id = fixture.messages[0];
            const trigger = '.row-title.tk-cf7dbc-view[data-submission-id="' + id + '"]';
            const listUrl = page.url();
            await page.click(trigger);
            await page.waitForSelector('#TB_window .cf7dbc-message-detail');
            assert.equal(page.url(), listUrl, 'Opening detail preserves the list URL');
            assert.equal(await page.$eval('#TB_window', n => n.getAttribute('role')), 'dialog');
            assert.equal(await page.$eval('#TB_window', n => n.getAttribute('aria-modal')), 'true');
            assert.equal(await page.$eval('#TB_ajaxWindowTitle', n => n.textContent), 'Message Detail');
            assert.equal(await page.evaluate(() => window.cf7Xss), undefined, 'Stored content cannot execute');
            assert.match(await page.$eval('#TB_ajaxContent', n => n.textContent), /<script>window.cf7Xss=1<\/script>/);
            assert.equal(await page.$eval('[data-cf7-submission="' + id + '"] .column-read_status', n => n.textContent.trim()), 'Read');
            const size = await page.$eval('#TB_window', n => {const r=n.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom};});
            assert.ok(size.left >= 0 && size.right <= width + 1 && size.top >= 0 && size.bottom <= 1001, 'Native modal fits at ' + width + ': ' + JSON.stringify(size));
            await page.focus('#TB_closeWindowButton');
            await page.keyboard.down('Shift'); await page.keyboard.press('Tab'); await page.keyboard.up('Shift');
            assert.equal(await page.evaluate(() => !!document.activeElement.closest('#TB_window')), true);
            await page.screenshot({path: path.join(output, 'modal-' + width + '.png')});
            await page.keyboard.press('Escape');
            await page.waitForSelector('#TB_window', {hidden: true});
            assert.equal(await page.$eval(trigger, n => document.activeElement === n), true, 'Closing restores focus to the message');
            checks += 10;
        }
        await page.goto(base + '&form_id=' + fixture.form + '&submission_id=' + fixture.messages[1], {waitUntil: 'networkidle0'});
        await page.waitForSelector('#TB_window .cf7dbc-message-detail');
        assert.equal(await page.$eval('.cf7dbc-wrap .wp-list-table', n => !!n), true, 'Legacy detail link opens above its list'); checks++;
        await page.click('#TB_closeWindowButton'); await page.waitForSelector('#TB_window', {hidden:true});
        await page.setViewport({width:1440,height:1000});
        await Promise.all([page.waitForNavigation({waitUntil:'networkidle0'}), page.click('.next-page')]);
        assert.equal(new URL(page.url()).searchParams.has('submission_id'), false, 'Pagination does not reopen a legacy message'); checks++;
        await page.goto(base + '&form_id=' + fixture.form, {waitUntil:'networkidle0'});
        const denied = await page.evaluate(async id => {
            const response = await fetch(tkCf7Database.ajaxUrl, {method:'POST',body:new URLSearchParams({action:'tk_cf7_database_detail',nonce:'invalid',submission_id:id})});
            return response.status;
        }, fixture.messages[2]);
        assert.equal(denied, 403, 'Invalid modal nonce is denied'); checks++;
        const missing = await page.evaluate(async () => {
            const response = await fetch(tkCf7Database.ajaxUrl, {method:'POST',body:new URLSearchParams({action:'tk_cf7_database_detail',nonce:tkCf7Database.nonce,submission_id:'99999999'})});
            return response.status;
        });
        assert.equal(missing, 404, 'Unknown message returns a controlled error'); checks++;
        await page.goto(base + '&form_id=' + fixture.form + '&submission_id=99999999', {waitUntil:'networkidle0'});
        await page.waitForSelector('#TB_window .notice-error');
        assert.match(await page.$eval('#TB_window .notice-error', n => n.textContent), /Submission not found/); checks++;
        await page.keyboard.press('Escape'); await page.waitForSelector('#TB_window', {hidden:true});
        await page.goto(base, {waitUntil:'networkidle0'});
        const unread = Number(await page.$eval('[data-cf7-unread-count]', n => n.textContent));
        await page.click('.tk-cf7dbc-view[data-submission-id="' + fixture.messages[2] + '"]');
        await page.waitForSelector('#TB_window .cf7dbc-message-detail');
        assert.equal(Number(await page.$eval('[data-cf7-unread-count]', n => n.textContent)), unread - 1, 'Dashboard unread count updates without reloading');
        assert.equal(await page.$$eval('[data-cf7-submission="' + fixture.messages[2] + '"] .cf7dbc-badge-unread', nodes => nodes.length), 0);
        checks += 2;
        await page.click('#TB_closeWindowButton'); await page.waitForSelector('#TB_window', {hidden:true});
        await page.goto(base + '&form_id=' + fixture.form, {waitUntil:'networkidle0'});
        const deleted = '[data-cf7-submission="' + fixture.messages[0] + '"]';
        await page.hover(deleted + ' .column-primary');
        page.once('dialog', dialog => dialog.accept());
        await Promise.all([page.waitForNavigation({waitUntil:'networkidle0'}), page.click(deleted + ' .delete a')]);
        assert.equal(await page.$$eval(deleted, nodes => nodes.length), 0, 'Native row action deletes only the selected fixture message');
        assert.match(await page.$eval('.notice-success', n => n.textContent), /deleted successfully/);
        checks += 2;
        assert.deepEqual(errors, []);
        console.log('PASS: ' + checks + ' CF7 native table/button, pagination, responsive, Thickbox, focus, escaping and access checks. Previews: ' + output);
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exitCode=1;});
