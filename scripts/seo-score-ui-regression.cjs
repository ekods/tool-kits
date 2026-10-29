const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const path = require('node:path');

(async () => {
    const {default: puppeteer} = await import('puppeteer');
    const html = execFileSync('php', [path.join(__dirname, 'seo-content-panel-regression.php'), '--preview'], {encoding: 'utf8'});
    const editor = `<script>
        window.listeners = []; window.savingState = {saving:false, meta:false, autosave:false, preview:false, success:true};
        window.edited = {title:'Company',slug:'company',featured_media:0};
        window.wp = {data: {
            select: name => name === 'core/edit-post' ? {isSavingMetaBoxes:()=>savingState.meta} : name === 'core' ? {} : {
                getEditedPostAttribute:key=>edited[key], isSavingPost:()=>savingState.saving,
                isAutosavingPost:()=>savingState.autosave, isPreviewingPost:()=>savingState.preview,
                didPostSaveRequestSucceed:()=>savingState.success
            },
            dispatch:()=>({editPost:changes=>{Object.assign(edited,changes);listeners.forEach(fn=>fn());}}),
            subscribe:fn=>listeners.push(fn)
        }};
    </script>`;
    const browser = await puppeteer.launch({headless:true});
    try {
        const page = await browser.newPage(), errors = [], requests = [];
        let score = 72, failure = false, delay = 0;
        page.on('pageerror', error => errors.push(error.message));
        await page.setRequestInterception(true);
        page.on('request', async request => {
            if (new URL(request.url()).pathname === '/wp-admin/admin-ajax.php') {
                requests.push(Object.fromEntries(new URLSearchParams(request.postData())));
                const value = score, failed = failure, wait = delay;
                if (wait) await new Promise(resolve => setTimeout(resolve, wait));
                try {
                    await request.respond({status:failed?500:200,contentType:'application/json',body:JSON.stringify({success:!failed,data:{post_id:42,score:value,analysis_html:`<p><strong>SEO Score: ${value}/100</strong></p><meter aria-label="SEO content score" value="${value}" max="100"></meter><ul class="tk-seo-analysis-issues"><li>Saved score ${value}</li></ul>`,badge_html:`<span class="tk-seo-score tk-seo-score-good" title="Content SEO score: ${value}/100">${value}</span>`}})});
                } catch (error) { /* A superseded request may already have been aborted. */ }
            } else if (request.url() === 'https://brandkraft.id/editor/') {
                await request.respond({status:200,contentType:'text/html',body:'<!doctype html><html></html>'});
            } else await request.respond({status:200,contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC0lEQVR42mP8/x8AAwMCAO+jQ1kAAAAASUVORK5CYII=','base64')});
        });
        await page.goto('https://brandkraft.id/editor/');
        await page.setContent(html.replace('<script>', editor + '<script>'));
        await page.evaluate(() => {
            const analysis=document.querySelector('[data-tk-seo-score-post]');
            const sidebar=document.createElement('div'); sidebar.className='tk-seo-geo-score';
            ['data-tk-seo-score-post','data-tk-seo-score-url','data-tk-seo-score-nonce'].forEach(key=>sidebar.setAttribute(key,analysis.getAttribute(key)));
            sidebar.innerHTML='<span>Content score</span><span data-tk-seo-score-badge><span class="tk-seo-score">—</span></span><span data-tk-seo-score-status role="status" hidden></span>';
            document.body.append(sidebar);
        });
        async function state(changes) {
            await page.evaluate(changes=>{Object.assign(window.savingState,changes);window.listeners.forEach(fn=>fn());},changes);
        }
        async function idle() { await new Promise(resolve=>setTimeout(resolve,250)); }
        async function saved(value) {
            await page.waitForFunction(value=>document.querySelector('[data-tk-seo-score-badge]').textContent===String(value),{},value);
            assert.equal(await page.$eval('[data-tk-seo-analysis-body] meter',el=>el.value),value);
            assert.match(await page.$eval('.tk-seo-analysis-issues',el=>el.textContent),new RegExp('Saved score '+value));
            assert.equal(await page.$$eval('[data-tk-seo-score-post]',els=>els.every(el=>!el.hasAttribute('aria-busy'))),true);
        }
        await page.$eval('#tk-seo-description',el=>{el.value='Unsaved metadata';el.dispatchEvent(new Event('input',{bubbles:true}));});
        await idle(); assert.equal(requests.length,0,'Edits alone do not audit unsaved content');
        await state({saving:true}); await idle(); assert.equal(requests.length,0,'Do not audit while saving the post');
        await state({saving:false,meta:true}); await idle(); assert.equal(requests.length,0,'Wait for separate metabox saves');
        await state({meta:false}); await saved(72);
        assert.equal(requests.length,1,'Sidebar and analysis share one request');
        assert.deepEqual(Object.keys(requests[0]).sort(),['action','nonce','post_id']);
        assert.equal(requests[0].action,'tk_seo_content_score');
        assert.equal(requests[0].post_id,'42');
        for (const mode of ['autosave','preview']) {
            await state({saving:true,[mode]:true}); await state({saving:false,[mode]:false});
            await idle(); assert.equal(requests.length,1,mode+' does not refresh the saved score');
        }
        await state({saving:true,success:false}); await state({saving:false});
        await idle(); assert.equal(requests.length,1,'Failed saves do not refresh');
        assert.equal(await page.$eval('[data-tk-seo-score-badge]',el=>el.textContent),'72');
        score=94;
        await state({saving:true,success:true}); await state({saving:false}); await saved(94);
        assert.equal(requests.length,2,'Next successful update refreshes again');
        failure=true;
        await state({saving:true}); await state({saving:false});
        await page.waitForFunction(()=>Array.from(document.querySelectorAll('[data-tk-seo-score-status]')).every(el=>!el.hidden&&el.textContent.includes('could not be refreshed')));
        assert.equal(await page.$eval('[data-tk-seo-score-badge]',el=>el.textContent),'94','Request errors preserve the last known score');
        failure=false; score=60; delay=600;
        await state({saving:true}); await state({saving:false});
        await page.waitForFunction(()=>document.querySelector('[data-tk-seo-score-post]').getAttribute('aria-busy')==='true');
        await new Promise(resolve=>setTimeout(resolve,30));
        score=88;delay=0;
        await state({saving:true}); await state({saving:false});await saved(88);
        await new Promise(resolve=>setTimeout(resolve,700));
        assert.equal(await page.$eval('[data-tk-seo-score-badge]',el=>el.textContent),'88','An older response cannot overwrite the latest save');
        assert.deepEqual(errors,[]);
        console.log('PASS: score refresh after post and metabox saves, sidebar/analysis synchronization, no unsaved/autosave/preview/failed-save audits, retry and stale-response protection');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
