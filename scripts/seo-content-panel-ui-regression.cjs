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
        let unavailableSocialImage = true;
        page.on('request', request => {
            if (unavailableSocialImage && request.url().includes('/unavailable-social.jpg')) {
                return request.respond({status: 404, headers: {'cache-control': 'no-store'}, body: ''});
            }
            return request.respond({status: 200, contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jQ1kAAAAASUVORK5CYII=', 'base64')});
        });
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
        const mediaPicker = `<script>
            window.mediaCallbacks = {}; window.mediaPickerCalls = 0; window.selectedAttachment = null;
            window.wp.media = options => {
                window.mediaPickerCalls++; window.mediaPickerOptions = options;
                const selection = {first: () => ({toJSON: () => window.chosenSocialImage}), reset: () => {window.selectedAttachment = null;}, add: attachment => {window.selectedAttachment = attachment.id;}};
                return {on: (name, callback) => {window.mediaCallbacks[name] = callback;}, state: () => ({get: () => selection}), open: () => window.mediaCallbacks.open()};
            };
            window.wp.media.attachment = id => ({id});
        </script>`;
        await page.setContent(html.replace('<script>', editor + mediaPicker + '<script>'));
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
        await page.click('.tk-seo-social-image-select');
        assert.equal(await page.$eval('#tk-seo-social-image-id', node => node.value), '0', 'Opening or cancelling the picker must preserve the default');
        assert.deepEqual(await page.evaluate(() => window.mediaPickerOptions.library), {type: 'image'});
        await page.evaluate(() => { window.chosenSocialImage = {id: 90, type: 'image', url: 'https://brandkraft.id/custom-og.jpg'}; window.mediaCallbacks.select(); });
        assert.equal(await page.$eval('#tk-seo-social-image-id', node => node.value), '90');
        assert.equal(await page.$eval('.tk-seo-social-image', node => node.src), 'https://brandkraft.id/custom-og.jpg');
        assert.equal(await page.evaluate(() => window.edited.featured_media), 0, 'Choosing an OG image must preserve the post thumbnail');
        await page.evaluate(() => { window.media[11] = {source_url: 'https://brandkraft.id/new-thumbnail.jpg'}; window.wp.data.dispatch().editPost({featured_media: 11}); });
        assert.equal(await page.$eval('.tk-seo-social-image', node => node.src), 'https://brandkraft.id/custom-og.jpg', 'Thumbnail edits must not overwrite a custom OG preview');
        await page.click('.tk-seo-social-image-select');
        assert.equal(await page.evaluate(() => window.mediaPickerCalls), 1, 'Reuse the media picker');
        assert.equal(await page.evaluate(() => window.selectedAttachment), 90, 'Preselect the saved custom image');
        await page.click('.tk-seo-social-image-reset');
        assert.equal(await page.$eval('#tk-seo-social-image-id', node => node.value), '0');
        assert.equal(await page.$eval('.tk-seo-social-image', node => node.src), 'https://brandkraft.id/new-thumbnail.jpg', 'Reset must use the latest thumbnail');
        assert.equal(await page.$eval('.tk-seo-social-image-reset', node => node.hidden), true);
        await page.waitForFunction(() => document.querySelector('.tk-seo-social-image').naturalWidth > 0 && !document.querySelector('.tk-seo-social-image').hidden);
        await page.$eval('.tk-seo-social-image', node => node.dispatchEvent(new Event('error')));
        assert.equal(await page.$eval('.tk-seo-social-image', node => node.hidden), false, 'A stale error must not hide a successfully decoded current image');
        assert.equal(await page.$eval('.tk-seo-social-image-retry', node => node.hidden), true);
        await page.evaluate(() => { window.chosenSocialImage = {id: 91, type: 'image', url: 'https://brandkraft.id/unavailable-social.jpg'}; window.mediaCallbacks.select(); });
        await page.waitForFunction(() => !document.querySelector('.tk-seo-social-image-retry').hidden);
        assert.equal(await page.$eval('.tk-seo-social-image', node => node.hidden), true, 'A genuine failed image must use the error placeholder');
        assert.equal(await page.$eval('.tk-seo-social-image-open', node => node.href), 'https://brandkraft.id/unavailable-social.jpg');
        assert.equal(await page.$eval('#tk-seo-social-image-id', node => node.value), '91', 'A preview failure must preserve the saved image choice');
        unavailableSocialImage = false;
        await page.click('.tk-seo-social-image-retry');
        await page.waitForFunction(() => !document.querySelector('.tk-seo-social-image').hidden && document.querySelector('.tk-seo-social-image').naturalWidth > 0);
        assert.equal(await page.$eval('.tk-seo-social-image', node => node.src), 'https://brandkraft.id/unavailable-social.jpg', 'Retry must preserve the original image URL without a metadata/cache-busting rewrite');
        assert.equal(await page.$eval('.tk-seo-social-image-retry', node => node.hidden), true);
        assert.equal(await page.$eval('.tk-seo-social-image-open', node => node.hidden), true);
        assert.equal(await page.$eval('#tk-seo-social-image-id', node => node.value), '91');
        assert.equal(await page.evaluate(() => window.edited.featured_media), 11, 'Retry must not change the Featured Image');
        await page.click('.tk-seo-social-image-reset');
        await page.$eval('.tk-seo-post-editor', node => { node.dataset.socialDefaultImage = ''; });
        await page.evaluate(() => window.wp.data.dispatch().editPost({featured_media: 0}));
        await page.$eval('.tk-seo-social-image', node => node.dispatchEvent(new Event('error')));
        assert.equal(await page.$eval('.tk-seo-social-image-empty', node => node.textContent), 'No social image', 'An old error after clearing the source must not replace the empty state');
        assert.equal(await page.$eval('.tk-seo-social-image-retry', node => node.hidden), true);
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
        // Polylang copy uses current raw field values and explicit replacement opt-in.
        const localeHtml = execFileSync('php', [path.join(__dirname, 'seo-content-locale-regression.php'), '--preview'], {encoding: 'utf8'});
        for (const width of [1440, 390, 320]) {
            await page.setViewport({width, height: 1000});
            await page.setContent(html.replace(/<div class="tk-seo-post-editor"[^>]*>/, match => match + localeHtml));
            assert.equal(await page.$eval('.tk-seo-copy-locale-button', node => node.disabled), true);
            await page.select('#tk-seo-copy-locale', 'en');
            assert.equal(await page.$eval('.tk-seo-copy-locale-button', node => node.disabled), false);
            await page.evaluate(() => {
                window.copyRequests = [];
                window.fetch = (url, options) => { window.copyRequests.push(Object.fromEntries(options.body)); return new Promise(resolve => { window.copyResolve = resolve; }); };
                const title = document.querySelector('#tk-seo-title'); title.value = 'New %%title%%'; title.dispatchEvent(new Event('input', {bubbles: true}));
            });
            await page.click('.tk-seo-copy-locale-button');
            assert.equal(await page.$eval('.tk-seo-copy-locale-button', node => node.disabled), true, 'Block duplicate copy clicks');
            assert.equal(await page.$eval('#tk-seo-copy-locale', node => node.disabled), true);
            const request = await page.evaluate(() => window.copyRequests[0]);
            assert.equal(request.locale, 'en'); assert.equal(request.replace, '0');
            assert.equal(request.tk_seo_title, 'New %%title%%');
            assert.equal(request['initial[tk_seo_title]'], '%%title%% %%page%% %%sep%% %%sitename%%');
            assert.equal(request.tk_seo_meta_description, 'Original page content');
            await page.evaluate(() => window.copyResolve({ok: true, json: async () => ({success: true, data: {message: '2 fields copied. Existing title preserved.', edit_url: 'https://example.com/wp-admin/post.php?post=43&action=edit'}})}));
            await page.waitForFunction(() => !document.querySelector('.tk-seo-copy-edit').hidden);
            assert.match(await page.$eval('.tk-seo-copy-status', node => node.textContent), /2 fields copied/);
            assert.match(await page.$eval('.tk-seo-copy-edit', node => node.href), /post=43.*#tk-seo-content$/);
            assert.equal(await page.$eval('#tk-seo-title', node => node.value), 'New %%title%%', 'Copy must not save or reset the source fields');
            await page.click('.tk-seo-copy-replace');
            await page.click('.tk-seo-copy-locale-button');
            assert.equal(await page.evaluate(() => window.copyRequests[1].replace), '1');
            await page.evaluate(() => window.copyResolve({ok: false, json: async () => ({success: false, data: {message: 'Translation is no longer editable.'}})}));
            await page.waitForFunction(() => !document.querySelector('.tk-seo-copy-locale-button').disabled);
            assert.equal(await page.$eval('.tk-seo-copy-status', node => node.textContent), 'Translation is no longer editable.');
            assert.equal(await page.$eval('.tk-seo-copy-edit', node => node.hidden), true);
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Locale controls overflow at ' + width);
            await page.select('#tk-seo-copy-locale', '');
            assert.equal(await page.$eval('.tk-seo-copy-locale-button', node => node.disabled), true);
        }
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
        console.log('PASS: Polylang copy current values, replacement opt-in, busy/error states, responsive locale controls, length statuses, social previews, variables, mobile and no-JS controls');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
