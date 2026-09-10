const { chromium } = require(process.env.TK_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true, args: ['--allow-file-access-from-files'] });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
        const errors = [];
        const consoleMessages = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', message => consoleMessages.push(message.text()));
        await page.addInitScript(() => {
            window.calls = 0;
            window.aborts = 0;
            window.testHidden = false;
            Object.defineProperty(document, 'hidden', { get: () => window.testHidden });
            window.fetch = (url, options) => {
                window.calls++;
                window.requestUrl = url;
                window.requestBody = options.body;
                return new Promise((resolve, reject) => {
                    window.respond = (data, ok = true) => resolve({ ok, status: ok ? 200 : 500, json: async () => data });
                    options.signal.addEventListener('abort', () => {
                        window.aborts++;
                        reject(new DOMException('Aborted', 'AbortError'));
                    });
                });
            };
            window.sample = { success: true, data: { load: [1, 0.8, 0.6], cpu_cores: 4, memory: { used: 67108864, limit: 268435456, percent: 25 }, errors: { available: true, per_min: 0 } } };
        });
        const url = 'file:///tmp/toolkits-monitoring-preview.html?page=tool-kits-monitoring';
        await page.goto(url + '#invalid');
        const loadedAssets = await page.evaluate(() => ({
            scripts: Array.from(document.scripts, script => script.src),
            styles: Array.from(document.querySelectorAll('link[rel="stylesheet"]'), link => link.href)
        }));
        assert.ok(loadedAssets.scripts.some(src => src.endsWith('/assets/health-monitor-chart.js')), 'Current Monitoring controller is loaded');
        assert.ok(loadedAssets.styles.some(href => href.endsWith('/assets/tool-kits-admin-ui.css')), 'Current admin stylesheet is loaded');
        assert.equal(loadedAssets.scripts.some(src => src.endsWith('/assets/monitoring-tabs.js')), false, 'Legacy Monitoring controller is not loaded');
        assert.equal(loadedAssets.styles.some(href => href.endsWith('/assets/admin-layout.css')), false, 'Legacy admin stylesheet is not loaded');
        await page.waitForFunction(() => window.calls === 1);
        assert.equal(await page.locator('#tk-rt-cpu-plot').getAttribute('aria-busy'), 'true');
        assert.equal(await page.locator('#tk-rt-cpu-state').isVisible(), true);
        const heading = await page.locator('.tk-page-heading').evaluate(el => {
            const style = getComputedStyle(el);
            return { bg: style.backgroundColor, radius: style.borderRadius, border: style.borderTopWidth, cardClass: el.classList.contains('tk-card') };
        });
        assert.deepEqual(heading, { bg: 'rgb(255, 255, 255)', radius: '8px', border: '1px', cardClass: true });
        const height = (await page.locator('.tk-rt-charts').boundingBox()).height;
        await page.screenshot({ path: '/tmp/toolkits-monitoring-loading.png', fullPage: true });
        await page.waitForTimeout(5500);
        assert.equal(await page.evaluate(() => window.calls), 1, 'A slow fetch must not overlap');
        await page.evaluate(() => window.respond(window.sample));
        await page.waitForFunction(() => document.getElementById('tk-rt-cpu-plot').getAttribute('aria-busy') === 'false');
        const firstChartState = await page.evaluate(() => {
            const cpu = Chart.getChart(document.getElementById('tk-rt-cpu-chart'));
            const mem = Chart.getChart(document.getElementById('tk-rt-mem-chart'));
            return {
                version: Chart.version,
                cpuType: cpu.config.type,
                cpuValues: cpu.data.datasets[0].data,
                cpuMax: cpu.options.scales.y.max,
                memMax: mem.options.scales.y.max,
                canvasWidth: cpu.canvas.width,
                canvasHeight: cpu.canvas.height
            };
        });
        assert.equal(firstChartState.version, '4.5.1');
        assert.equal(firstChartState.cpuType, 'line');
        assert.equal(firstChartState.cpuValues.filter(Number.isFinite).length, 1, 'First actual sample is rendered by Chart.js');
        assert.equal(firstChartState.cpuValues[29], 25, 'First sample is anchored at the latest rolling-window slot');
        assert.ok(firstChartState.canvasWidth > 0 && firstChartState.canvasHeight > 0, 'Chart.js canvas has a rendered bitmap');
        assert.equal(await page.locator('#tk-rt-cpu-state').isVisible(), false);
        assert.ok(Math.abs((await page.locator('.tk-rt-charts').boundingBox()).height - height) < 0.5, 'Loading does not shift chart layout');
        assert.equal(await page.locator('#tk-rt-cpu-avg').textContent(), '25%');
        assert.equal(firstChartState.cpuMax, 100);
        assert.equal(firstChartState.memMax, 256);
        assert.match(await page.locator('#tk-rt-load').textContent(), /s$/);
        assert.equal(await page.evaluate(() => new URLSearchParams(window.requestBody).get('nonce')), 'preview');
        await page.waitForFunction(() => window.calls === 2, { timeout: 8000 });
        await page.evaluate(() => { window.sample.data.load[0] = 2; window.respond(window.sample); });
        await page.waitForTimeout(400);
        const plottedValues = await page.evaluate(() => Chart.getChart(document.getElementById('tk-rt-cpu-chart')).data.datasets[0].data);
        assert.deepEqual(plottedValues.slice(0, 28), new Array(28).fill(null), 'Unused rolling-window slots remain empty');
        assert.deepEqual(plottedValues.slice(28), [25, 50], 'Initial samples stay right-aligned and chronological');
        const desktopMetricBoxes = await page.locator('[data-panel-id="realtime"] .tk-rt-card').evaluateAll(elements => elements.map(el => el.getBoundingClientRect().toJSON()));
        assert.equal(new Set(desktopMetricBoxes.map(box => Math.round(box.top))).size, 1, 'Desktop metrics stay on one row');
        const desktopChartBoxes = await page.locator('.tk-rt-chart-section').evaluateAll(elements => elements.map(el => el.getBoundingClientRect().toJSON()));
        assert.ok(Math.abs(desktopChartBoxes[0].height - desktopChartBoxes[1].height) < 1, 'Desktop chart panels have matching heights');
        assert.ok(desktopChartBoxes[0].right < desktopChartBoxes[1].left, 'Desktop chart panels do not overlap');
        await page.screenshot({ path: '/tmp/toolkits-monitoring-desktop.png', fullPage: true });
        for (const width of [390, 320]) {
            await page.setViewportSize({ width, height: 844 });
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'No overflow at ' + width);
            const mobileChartBoxes = await page.locator('.tk-rt-chart-section').evaluateAll(elements => elements.map(el => el.getBoundingClientRect().toJSON()));
            assert.ok(mobileChartBoxes[0].bottom < mobileChartBoxes[1].top, 'Mobile chart panels stack without overlap at ' + width);
            await page.screenshot({ path: '/tmp/toolkits-monitoring-' + width + '.png', fullPage: true });
        }
        await page.setViewportSize({ width: 1440, height: 1100 });
        await page.locator('[data-panel="health"]').click();
        await page.reload();
        assert.equal(await page.locator('[data-panel-id="health"]').isVisible(), true);
        await page.waitForTimeout(150);
        assert.equal(await page.evaluate(() => window.calls), 0, 'Saved non-realtime tab does not poll');
        await page.evaluate(() => history.replaceState(null, '', location.pathname + location.search));
        await page.reload();
        assert.equal(await page.locator('[data-panel-id="health"]').isVisible(), true, 'Session storage restores the tab without a hash');
        await page.locator('[data-panel="realtime"]').click();
        await page.waitForFunction(() => window.calls === 1);
        await page.locator('[data-panel="checks"]').click();
        assert.equal(await page.evaluate(() => window.aborts), 1, 'Tab switch cancels the request');
        await page.locator('[data-panel="realtime"]').click();
        await page.waitForFunction(() => window.calls === 2);
        await page.evaluate(() => { window.testHidden = true; document.dispatchEvent(new Event('visibilitychange')); });
        assert.equal(await page.evaluate(() => window.aborts), 2, 'Hidden page cancels the request');
        await page.evaluate(() => { window.testHidden = false; document.dispatchEvent(new Event('visibilitychange')); });
        await page.waitForFunction(() => window.calls === 3);
        await page.evaluate(() => window.respond({}, false));
        await page.waitForFunction(() => !document.getElementById('tk-rt-retry').hidden);
        assert.equal(await page.locator('#tk-rt-cpu-plot').getAttribute('aria-busy'), 'false');
        assert.match(await page.locator('#tk-rt-cpu-state').textContent(), /could not/);
        await page.locator('#tk-rt-retry').click();
        await page.waitForFunction(() => window.calls === 4);
        await page.evaluate(() => window.respond({ success: false, data: { message: 'forbidden' } }));
        await page.waitForFunction(() => !document.getElementById('tk-rt-retry').hidden);
        await page.locator('#tk-rt-retry').click();
        await page.waitForFunction(() => window.calls === 5);
        await page.evaluate(() => { window.sample.data.load = null; window.respond(window.sample); });
        await page.waitForFunction(() => document.getElementById('tk-rt-cpu-state').textContent.includes('unavailable'));
        assert.ok(await page.evaluate(() => Chart.getChart(document.getElementById('tk-rt-mem-chart')).data.datasets[0].data.some(Number.isFinite)));
        await page.emulateMedia({ reducedMotion: 'reduce' });
        assert.equal(await page.locator('#tk-rt-pulse').evaluate(el => getComputedStyle(el).animationName), 'none');
        assert.deepEqual(errors, []);
        assert.deepEqual(consoleMessages.filter(message => message.startsWith('ToolKits:')), []);
        console.log('PASS: Chart.js 4.5.1, cache-busted assets, header card, loading, rolling samples, responsive layout, tab persistence, cancellation, errors, retry, missing CPU, reduced motion.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
