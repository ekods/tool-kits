const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const path = require('node:path');

(async () => {
    const {default: puppeteer} = await import('puppeteer');
    const html = execFileSync('php', [path.join(__dirname, 'seo-content-panel-regression.php'), '--preview'], {encoding: 'utf8'});
    const browser = await puppeteer.launch({headless: true});
    try {
        const page = await browser.newPage();
        await page.setRequestInterception(true);
        page.on('request', request => request.respond({status: 200, contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jQ1kAAAAASUVORK5CYII=', 'base64')}));
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        for (const width of [1440, 390, 320]) {
            await page.setViewport({width, height: 1000});
            await page.setContent(html);
            assert.equal(await page.$eval('.tk-seo-preview-title', node => node.textContent), 'Company - BRANDKRAFT');
            assert.equal(await page.$eval('#tk-seo-title', node => node.value), '%%title%% %%page%% %%sep%% %%sitename%%');
            assert.equal(await page.$$eval('#tk-seo-title-editor .tk-seo-token', nodes => nodes.length), 4);
            assert.equal(await page.$$eval('#tk-seo-title-editor', nodes => nodes.length), 1, 'Enhancement must reuse the rendered chip field');
            assert.equal(await page.$eval('#tk-seo-title', node => getComputedStyle(node).display), 'none');
            assert.equal(await page.$eval('#tk-seo-title-editor', node => node.textContent.includes('%%')), false);
            assert.equal(await page.$eval('#tk-seo-title', node => node.placeholder), 'Enter an SEO title');
            assert.equal(await page.$eval('#tk-seo-description', node => node.value), 'Original page content');
            assert.equal(await page.$$eval('.tk-seo-editor-section', nodes => nodes.map(node => node.open).join(',')), 'true,true,true,false');
            assert.equal(await page.$eval('[data-length-feedback="title"] .tk-seo-length-status', node => node.textContent), 'Too short');
            await page.$eval('#tk-seo-title', node => { node.value = 'Creative brand design'; node.dispatchEvent(new Event('input', {bubbles: true})); });
            await page.$eval('#tk-seo-description', node => { node.value = '<script>Visible text 🧭</script>'; node.dispatchEvent(new Event('input', {bubbles: true})); });
            assert.equal(await page.$eval('.tk-seo-preview-title', node => node.textContent), 'Creative brand design');
            assert.equal(await page.$eval('.tk-seo-preview-description', node => node.textContent), '<script>Visible text 🧭</script>');
            assert.equal(await page.$eval('.tk-seo-social-title', node => node.textContent), 'Creative brand design');
            assert.equal(await page.$eval('.tk-seo-social-description', node => node.textContent), '<script>Visible text 🧭</script>');
            assert.equal(await page.$('.tk-seo-social-description script'), null);
            await page.click('[for="tk-seo-social-linkedin"]');
            assert.equal(await page.$eval('.tk-seo-social-card', node => node.dataset.network), 'linkedin');
            assert.equal(await page.$eval('.tk-seo-search-preview', node => node.dataset.device), 'mobile', 'Social controls must not change Google device mode');
            assert.equal(await page.$('.tk-seo-preview-description script'), null, 'Input must be treated as text');
            const counts = await page.$eval('[data-description-length]', node => node.textContent);
            assert.ok(counts.startsWith(Array.from('<script>Visible text 🧭</script>').length + ' characters'));
            const mobileWidth = await page.$eval('.tk-seo-search-preview', node => node.getBoundingClientRect().width);
            assert.equal(await page.$eval('.tk-seo-preview-url', node => getComputedStyle(node).display), 'none');
            await page.click('[data-preview-device="desktop"]');
            assert.equal(await page.$eval('.tk-seo-search-preview', node => node.dataset.device), 'desktop');
            assert.equal(await page.$eval('#tk-seo-preview-desktop', node => node.checked), true);
            assert.notEqual(await page.$eval('.tk-seo-preview-url', node => getComputedStyle(node).display), 'none');
            if (width === 1440) assert.ok(await page.$eval('.tk-seo-search-preview', node => node.getBoundingClientRect().width) > mobileWidth, 'Desktop must be visually wider');
            await page.click('[data-preview-device="mobile"]');
            assert.equal(await page.$eval('.tk-seo-preview-url', node => getComputedStyle(node).display), 'none');
            await page.click('[data-preview-device="desktop"]');
            await page.$eval('#tk-seo-slug', node => { node.value = 'new-company'; node.dispatchEvent(new Event('input', {bubbles: true})); });
            assert.equal(await page.$eval('.tk-seo-preview-url', node => node.textContent), 'https://brandkraft.id/new-company/');
            assert.equal(await page.$eval('.tk-seo-social-url', node => node.textContent), 'https://brandkraft.id/new-company/');
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Overflow at ' + width);
            await page.click('.tk-seo-editor-section:last-child > summary');
            assert.match(await page.$eval('.tk-seo-editor-section:last-child', node => node.innerText), /Save a draft/);
            if (width === 1440) await page.screenshot({path: '/tmp/toolkits-seo-content-desktop.png', fullPage: true});
            if (width === 390) await page.screenshot({path: '/tmp/toolkits-seo-content-mobile.png', fullPage: true});
        }
        const editor = `<script>window.edited = {title: 'Company', slug: 'company', featured_media: 0}; window.media = {}; window.editorListeners = []; window.wp = {data: {select: name => name === 'core' ? {getMedia: id => window.media[id]} : {getEditedPostAttribute: key => window.edited[key]}, dispatch: () => ({editPost: changes => {Object.assign(window.edited, changes); window.editorListeners.forEach(fn => fn());}}), subscribe: fn => window.editorListeners.push(fn)}};</script>`;
        await page.setContent(html.replace('<script>', editor + '<script>'));
        await page.$eval('#tk-seo-slug', node => { node.value = 'block-editor-slug'; node.dispatchEvent(new Event('input', {bubbles: true})); });
        assert.equal(await page.evaluate(() => window.edited.slug), 'block-editor-slug', 'Slug must update Gutenberg state');
        await page.evaluate(() => window.wp.data.dispatch().editPost({slug: 'native-slug', title: 'Changed page title'}));
        assert.equal(await page.$eval('#tk-seo-slug', node => node.value), 'native-slug');
        assert.equal(await page.$eval('.tk-seo-preview-title', node => node.textContent), 'Changed page title - BRANDKRAFT');
        assert.equal(await page.$eval('#tk-seo-title', node => node.value), '%%title%% %%page%% %%sep%% %%sitename%%');
        assert.equal(await page.$eval('#tk-seo-title', node => node.placeholder), 'Enter an SEO title');
        assert.equal(await page.$eval('.tk-seo-social-title', node => node.textContent), 'Changed page title - BRANDKRAFT');
        await page.evaluate(() => { window.media[9] = {source_url: 'https://brandkraft.id/featured.jpg'}; window.wp.data.dispatch().editPost({featured_media: 9}); });
        await page.waitForFunction(() => document.querySelector('.tk-seo-social-image').naturalWidth > 0);
        assert.equal(await page.$eval('.tk-seo-social-image', node => node.src), 'https://brandkraft.id/featured.jpg');
        await page.evaluate(() => window.wp.data.dispatch().editPost({featured_media: 10}));
        assert.equal(await page.$eval('.tk-seo-social-image-empty', node => node.textContent), 'Loading social image…');
        await page.evaluate(() => { window.media[10] = {source_url: 'https://brandkraft.id/updated-featured.jpg'}; window.editorListeners.forEach(fn => fn()); });
        assert.equal(await page.$eval('.tk-seo-social-image', node => node.src), 'https://brandkraft.id/updated-featured.jpg');
        await page.$eval('.tk-seo-post-editor', node => { node.dataset.socialDefaultImage = 'https://brandkraft.id/default-social.jpg'; });
        await page.evaluate(() => window.wp.data.dispatch().editPost({featured_media: 0}));
        assert.equal(await page.$eval('.tk-seo-social-image', node => node.src), 'https://brandkraft.id/default-social.jpg');
        // Classic editor may have no core/editor store even when wp.data exists.
        const classic = '<script>window.wp = {data: {select() {throw new Error("Store unavailable");}}};</script>';
        await page.setContent(html.replace('<script>', '<div id="postimagediv"></div>' + classic + '<script>'));
        await page.$eval('#postimagediv', node => { const image = document.createElement('img'); image.src = 'https://brandkraft.id/classic-featured.jpg'; node.append(image); });
        await page.waitForFunction(() => document.querySelector('.tk-seo-social-image').getAttribute('src') === 'https://brandkraft.id/classic-featured.jpg');
        await page.$eval('#postimagediv', node => node.replaceChildren());
        await page.waitForFunction(() => document.querySelector('.tk-seo-social-image').hidden);
        await page.click('[data-preview-device="desktop"]');
        assert.equal(await page.$eval('#tk-seo-preview-desktop', node => node.checked), true);
        for (const [length, expected] of [[29, 'Too short'], [30, 'Ideal'], [60, 'Ideal'], [61, 'Too long']]) {
            await page.$eval('#tk-seo-title', (node, value) => { node.value = value; node.dispatchEvent(new Event('input', {bubbles: true})); }, 'a'.repeat(length));
            assert.equal(await page.$eval('[data-length-feedback="title"] .tk-seo-length-status', node => node.textContent), expected);
        }
        for (const [length, expected] of [[119, 'Too short'], [120, 'Ideal'], [160, 'Ideal'], [161, 'Too long']]) {
            await page.$eval('#tk-seo-description', (node, value) => { node.value = value; node.dispatchEvent(new Event('input', {bubbles: true})); }, '🧭'.repeat(length));
            assert.equal(await page.$eval('[data-length-feedback="description"] .tk-seo-length-status', node => node.textContent), expected);
            assert.equal(await page.$eval('#tk-seo-description', node => Array.from(node.value).length), length, 'Guidance must not trim text');
        }
        // Rich variable insertion, typed % completion, removal and live preview.
        const longDescription = 'Deskripsi lengkap dengan kata utuh. '.repeat(12) + 'KALIMAT TERAKHIR 🧭';
        await page.$eval('#tk-seo-description', (node, text) => { node.value = text; node.dispatchEvent(new Event('input', {bubbles: true})); }, longDescription);
        assert.equal(await page.$eval('.tk-seo-preview-description', node => node.textContent), longDescription);
        assert.equal(await page.$eval('.tk-seo-social-description', node => node.textContent), longDescription);
        assert.equal(await page.$eval('#tk-seo-description-editor', node => node.textContent), longDescription);
        assert.ok(await page.$eval('#tk-seo-description-editor', node => node.scrollHeight <= node.clientHeight + 1 && node.scrollWidth <= node.clientWidth + 1), 'Long descriptions must wrap and grow without clipping on mobile');
        assert.ok((await page.$eval('[data-description-length]', node => node.textContent)).startsWith(Array.from(longDescription).length + ' characters'));
        await page.$eval('#tk-seo-title', node => { node.value = ''; node.dispatchEvent(new Event('input', {bubbles: true})); });
        await page.focus('#tk-seo-title-editor');
        await page.keyboard.type('%sep');
        assert.equal(await page.$eval('#tk-seo-title-variables', node => node.hidden), false);
        await page.keyboard.press('Enter');
        assert.equal(await page.$eval('#tk-seo-title', node => node.value.trim()), '%%sep%%');
        assert.equal(await page.$eval('.tk-seo-preview-title', node => node.textContent), '-');
        await page.$eval('#tk-seo-title', node => { node.value = 'Hello world'; node.dispatchEvent(new Event('input', {bubbles: true})); });
        await page.$eval('#tk-seo-title-editor', node => {
            node.focus(); const range = document.createRange(); range.setStart(node.firstChild, 6); range.collapse(true);
            const selection = getSelection(); selection.removeAllRanges(); selection.addRange(range); node.dispatchEvent(new Event('mouseup'));
        });
        await page.click('[data-template-field]:has(#tk-seo-title) .tk-seo-insert-variable');
        await page.click('#tk-seo-title-variables [data-seo-variable="%%sitename%%"]');
        assert.equal(await page.$eval('#tk-seo-title', node => node.value), 'Hello %%sitename%% world');
        assert.equal(await page.$eval('.tk-seo-preview-title', node => node.textContent), 'Hello BRANDKRAFT world');
        await page.click('#tk-seo-title-editor .tk-seo-token-remove');
        assert.equal(await page.$eval('.tk-seo-preview-title', node => node.textContent), 'Hello world');
        await page.$eval('#tk-seo-description', node => { node.value = 'Learn about %%title%% at %%sitename%%.'; node.dispatchEvent(new Event('input', {bubbles: true})); });
        assert.equal(await page.$eval('.tk-seo-preview-description', node => node.textContent), 'Learn about Company at BRANDKRAFT.');
        assert.equal(await page.$$eval('#tk-seo-description-editor .tk-seo-token', nodes => nodes.length), 2);
        const expectedDescriptionLength = Array.from('Learn about Company at BRANDKRAFT.').length;
        assert.ok((await page.$eval('[data-description-length]', node => node.textContent)).startsWith(expectedDescriptionLength + ' characters'));
        await page.$eval('#tk-seo-description-editor', node => {
            node.focus(); const range = document.createRange(); range.selectNodeContents(node); range.collapse(false);
            const selection = getSelection(); selection.removeAllRanges(); selection.addRange(range);
        });
        await page.keyboard.type(' Updated');
        assert.match(await page.$eval('.tk-seo-preview-description', node => node.textContent), /Updated$/);
        await page.setContent(html.replace('<style>', '<link rel="icon" href="https://brandkraft.id/existing-favicon.png"><style>'));
        await page.waitForFunction(() => document.querySelector('.tk-seo-preview-favicon').naturalWidth > 0);
        assert.equal(await page.$eval('.tk-seo-preview-favicon', node => node.src), 'https://brandkraft.id/existing-favicon.png');
        assert.equal(await page.$eval('.tk-seo-preview-icon', node => getComputedStyle(node).display), 'none');
        await page.setViewport({width: 1440, height: 1000});
        await page.$eval('#tk-seo-description', node => { node.value = 'Explore %%title%% at %%sitename%%. %%excerpt%%'; node.dispatchEvent(new Event('input', {bubbles: true})); });
        await page.click('[data-preview-device="desktop"]');
        await page.click('[data-template-field]:has(#tk-seo-title) .tk-seo-insert-variable');
        await page.screenshot({path: '/tmp/toolkits-seo-variables-desktop.png', fullPage: true});
        // When a script is delayed or blocked, the server HTML still shows chips.
        await page.setContent(html.replace(/<script>[\s\S]*?<\/script>/g, ''));
        assert.equal(await page.$$eval('#tk-seo-title-editor .tk-seo-token', nodes => nodes.length), 4);
        assert.equal(await page.$eval('#tk-seo-title-editor', node => node.textContent.includes('%%')), false);
        assert.equal(await page.$eval('#tk-seo-title-editor', node => node.getAttribute('aria-readonly')), 'true');
        assert.equal(await page.$eval('#tk-seo-title', node => getComputedStyle(node).display), 'none');
        assert.equal(await page.$eval('.tk-seo-preview-title', node => node.textContent), 'Company - BRANDKRAFT');
        assert.equal(await page.evaluate(() => {
            const form = document.createElement('form'); document.body.append(form); form.append(document.querySelector('#tk-seo-content'));
            return new FormData(form).get('tk_seo_title');
        }), '%%title%% %%page%% %%sep%% %%sitename%%', 'The raw template must stay available for saving');
        // Device controls must also work when editor scripts are blocked or delayed.
        await page.setJavaScriptEnabled(false);
        await page.setContent(html);
        assert.notEqual(await page.$eval('#tk-seo-title', node => getComputedStyle(node).display), 'none', 'No-JS users must retain an editable plain-text fallback');
        await page.click('[data-preview-device="desktop"]');
        assert.equal(await page.$eval('#tk-seo-preview-desktop', node => node.checked), true);
        assert.notEqual(await page.$eval('.tk-seo-preview-url', node => getComputedStyle(node).display), 'none');
        await page.click('[data-preview-device="mobile"]');
        assert.equal(await page.$eval('.tk-seo-preview-url', node => getComputedStyle(node).display), 'none');
        await page.click('[for="tk-seo-social-linkedin"]');
        assert.equal(await page.$eval('#tk-seo-social-linkedin', node => node.checked), true);
        assert.deepEqual(errors, []);
        console.log('PASS: length status boundaries, full text, live social title/description/URL, Facebook/LinkedIn, Gutenberg/Classic featured images and defaults, variable chips, mobile and no-JS controls');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
