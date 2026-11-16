const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const http = require('node:http');
const path = require('node:path');

(async () => {
    const {default: puppeteer} = await import('puppeteer');
    const render = mode => execFileSync(process.env.TK_PHP_BINARY || 'php', [path.join(__dirname, 'render-cookie-consent-preview.php'), mode], {encoding: 'utf8'});
    const pages = {'/': render('policy'), '/without-policy': render('none'), '/legacy': render('legacy'), '/privacy': '<!doctype html><html lang="en"><title>Privacy Policy</title><main><h1>Privacy Policy</h1></main></html>'};
    const server = http.createServer((request, response) => {
        response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
        response.end(pages[request.url] || pages['/']);
    });
    let browser;
    try {
        await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
        browser = await puppeteer.launch({headless: true});
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const origin = 'http://127.0.0.1:' + server.address().port;
        const choice = () => page.evaluate(() => JSON.parse(decodeURIComponent(document.cookie.split('; ').find(value => value.startsWith('tk_cookie_consent=')).split('=').slice(1).join('='))));
        const reset = async () => {
            await page.evaluate(() => { document.cookie = 'tk_cookie_consent=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'; });
            await page.reload({waitUntil: 'load'});
        };
        await page.goto(origin, {waitUntil: 'load'});
        assert.equal(await page.$eval('.tk-cookie-consent-link', node => node.getAttribute('href')), '/privacy');
        assert.equal(await page.evaluate(() => window.dataLayer[0][2].analytics_storage), 'denied');
        await page.focus('#tk-cookie-btn-pref');
        await page.keyboard.press('Enter');
        assert.equal(await page.$eval('dialog', node => node.open), true);
        assert.equal(await page.evaluate(() => document.activeElement.id), 'tk-cookie-modal-close');
        assert.equal(await page.$eval('#tk-cookie-pref-analytics', node => Array.from(node.labels).some(label => label.textContent.includes('Analytics Cookies'))), true);
        await page.keyboard.down('Shift');
        await page.keyboard.press('Tab');
        await page.keyboard.up('Shift');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'tk-cookie-btn-save-pref');
        await page.keyboard.press('Escape');
        assert.equal(await page.$eval('dialog', node => node.open), false);
        assert.equal(await page.evaluate(() => document.activeElement.id), 'tk-cookie-btn-pref');
        await page.click('#tk-cookie-btn-pref');
        await page.click('label[for="tk-cookie-pref-analytics"]');
        await page.click('#tk-cookie-btn-save-pref');
        assert.deepEqual(await choice(), {analytics: false, marketing: true});
        await page.waitForFunction(() => document.activeElement.id === 'tk-cookie-floating-btn');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'tk-cookie-floating-btn');
        await page.click('#tk-cookie-floating-btn');
        assert.equal(await page.$eval('#tk-cookie-pref-analytics', node => node.checked), false);
        await page.click('#tk-cookie-modal-close');
        assert.equal(await page.$eval('dialog', node => node.open), false);
        for (const width of [1280, 390, 320]) {
            await page.setViewport({width, height: 640});
            await page.click('#tk-cookie-floating-btn');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Page overflow at ' + width);
            assert.equal(await page.$eval('dialog', node => node.scrollWidth <= node.clientWidth + 1), true, 'Dialog overflow at ' + width);
            await page.mouse.click(3, 3);
            assert.equal(await page.$eval('dialog', node => node.open), false, 'Backdrop closes preferences');
        }
        await page.emulateMediaFeatures([{name: 'prefers-reduced-motion', value: 'reduce'}]);
        assert.equal(await page.$eval('.tk-cookie-slider', node => getComputedStyle(node).transitionDuration), '0s');
        await reset();
        await page.click('#tk-cookie-btn-accept');
        assert.deepEqual(await choice(), {analytics: true, marketing: true});
        await page.reload({waitUntil: 'load'});
        assert.equal(await page.evaluate(() => window.dataLayer.at(-1)[2].analytics_storage), 'granted');
        await reset();
        await page.click('#tk-cookie-btn-reject');
        assert.deepEqual(await choice(), {analytics: false, marketing: false});
        await reset();
        await page.click('.tk-cookie-consent-link');
        await page.waitForFunction(() => location.pathname === '/privacy');
        for (const route of ['/without-policy', '/legacy']) {
            await page.goto(origin + route, {waitUntil: 'load'});
            assert.equal(await page.$$eval('a[href="#"]', nodes => nodes.length), 0, 'No placeholder policy link');
        }
        assert.deepEqual(errors, []);
        console.log('PASS: cookie consent, accept/reject/save, labels, keyboard focus, Escape, backdrop, policy links, reduced motion and 1280/390/320px layouts');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
