const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
    const {default: puppeteer} = await import('puppeteer');
    const browser = await puppeteer.launch({headless: true});
    try {
        const page = await browser.newPage();
        page.on('dialog', dialog => dialog.accept());
        const errors = []; page.on('pageerror', error => errors.push(error.message));
        await page.setContent(`<style>.button {display:inline-block}</style>
            <form id="tk-db-cleanup-form"><input name="_tk_nonce" value="fixture-nonce">
            <input type="checkbox" name="do_revisions" value="1" checked>
            <button class="button button-primary">Run Database Pruning</button></form>
            <div id="tk-db-cleanup-progress" data-job="{}" data-owner="7" data-url="/ajax">
            <p class="tk-cleanup-status"></p><pre class="tk-cleanup-counts"></pre>
            <button type="button" class="button" data-command="pause" hidden>Pause</button>
            <button type="button" class="button" data-command="resume" hidden>Continue</button>
            <button type="button" class="button" data-command="cancel" hidden>Cancel</button></div>`);
        await page.evaluate(() => {
            window.commands = [];
            window.fixtureJob = {id: 'browser-job', owner: 7, status: 'running', tasks: ['revisions'], index: 0, counts: {revisions: 50}};
            window.steps = 0;
            window.fetch = async (_, args) => {
                const command = args.body.get('command');
                if (args.body.get('_tk_nonce') !== 'fixture-nonce') throw new Error('Missing nonce');
                if (command === 'start' && args.body.get('do_revisions') !== '1') throw new Error('Lost form selection');
                window.commands.push(command);
                if (command === 'step' && ++window.steps === 1) await new Promise(resolve => { window.releaseBatch = resolve; });
                if (command === 'pause') window.fixtureJob.status = 'paused';
                if (command === 'resume') window.fixtureJob.status = 'running';
                if (command === 'step' && window.steps === 2) { window.fixtureJob.status = 'completed'; window.fixtureJob.index = 1; window.fixtureJob.counts.revisions = 100; }
                return {async json() { return {success: true, data: JSON.parse(JSON.stringify(window.fixtureJob))}; }};
            };
        });
        await page.addScriptTag({content: fs.readFileSync(__dirname + '/../assets/db-cleanup.js', 'utf8')});
        assert(await page.$eval('[data-command=pause]', el => getComputedStyle(el).display === 'none'));
        await page.click('button.button-primary');
        await page.waitForFunction(() => window.commands.includes('step'));
        await page.click('[data-command=pause]');
        assert.deepEqual(await page.evaluate(() => window.commands), ['start', 'step']);
        await page.evaluate(() => window.releaseBatch());
        await page.waitForFunction(() => document.querySelector('.tk-cleanup-status').textContent.includes('paused'));
        await page.click('[data-command=resume]');
        await page.waitForFunction(() => document.querySelector('.tk-cleanup-status').textContent.includes('completed'));
        assert.deepEqual(await page.evaluate(() => window.commands), ['start', 'step', 'pause', 'resume', 'step']);
        assert.equal(await page.$eval('button.button-primary', el => el.disabled), false);
        assert.match(await page.$eval('.tk-cleanup-counts', el => el.textContent), /revisions: 100/);
        assert.deepEqual(errors, []);
        console.log('PASS: browser cleanup form, nonce, confirmation, hidden controls, in-flight pause, resume and completion');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
