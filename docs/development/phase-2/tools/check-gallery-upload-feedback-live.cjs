#!/usr/bin/env node
'use strict';
/**
 * P2-03-4 / F75 — the merchant learns the upload rules BEFORE choosing, and is TOLD when a file
 * is refused anyway.
 *
 * NO REAL CUSTOMER DATA. The tenant is `database/seed-p2dev.php`; every value is invented and
 * every uploaded file is generated here.
 *
 * WHAT THE SERVER ENFORCES, measured rather than assumed. `Utility::upload_file` and
 * `Utility::keyWiseUpload_file` build `mimes:`/`max:` from platform settings, and
 * `Utility::Setting()` supplies these when the rows are absent:
 *     local_storage_validation      = 'jpg,jpeg,png,csv,svg,pdf'
 *     local_storage_max_upload_size = '2048000'      (KB -> 2 GB)
 * But the SETTING IS NOT THE LIMIT: PHP's `upload_max_filesize` is 2M and `post_max_size` is 8M
 * in this container, so the real ceiling is 2 MB per file and 8 MB per request. The editor was
 * advertising a third number -- Dropzone's own 256 MB default -- and `acceptedFiles: null`, i.e.
 * any file type at all. Three numbers, none of them the one a merchant hits.
 *
 * TWO INDEPENDENT THINGS ARE MEASURED HERE, because the fix has two halves and they fail
 * separately:
 *
 *   A. THE CLIENT GUARD. The editor states the accepted formats and the real size limits, and
 *      refuses a file the server would refuse -- before any upload. A GIF is the case: common,
 *      and absent from the configured list.
 *
 *   B. THE SERVER FALLBACK. The client guard matches on EXTENSION, so a file can still pass it and
 *      be refused by the server, which guesses from CONTENT. A text file named `.png` is exactly
 *      that, and it is also what a corrupted or mislabelled file looks like. Before this change the
 *      gallery loop asked only `isset($pathss['url'])` and SKIPPED the refusal, while
 *      `$msg['msg']` had already been set to "Product Successfully Created" -- and the success
 *      branch then navigated away without displaying `data.msg` at all. So the merchant was told it
 *      worked, twice over, and the image was gone.
 *
 * usage: node check-gallery-upload-feedback-live.cjs [baseUrl]
 */
const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const EMAIL = 'merchant@example.test', PASSWORD = 'password';

let cases = 0; const failures = [];
const check = (label, cond, detail) => {
    cases++;
    if (cond) { console.log('  ok    ' + label); }
    else { failures.push(label + (detail ? '  [' + detail + ']' : '')); console.log('  FAIL  ' + label + (detail ? '  [' + detail + ']' : '')); }
};

const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAQklEQVR4nO3OMQ'
    + 'EAIAzAsMH/9ygDGrhJ+ts9JjMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzO7'
    + 'cQF7mQGJ3wZ0sQAAAABJRU5ErkJggg==';
/* A REAL GIF, not a renamed PNG: `acceptedFiles` and `mimes:` both look at what the file is. */
const GIF_B64 = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

async function login(p) {
    await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await p.fill('input[name="email"]', EMAIL);
    await p.fill('input[name="password"]', PASSWORD);
    await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
}

/** Fill everything store() requires except the gallery, so the only variable is the media. */
async function fillRequired(p, name) {
    await p.fill('input[name="name"]', name);
    await p.fill('input[name="price"]', '30');
    await p.fill('input[name="cost_price"]', '10');
    await p.evaluate(() => {
        const el = document.querySelector('#category_id');
        const w = el && el.closest('.choices');
        if (w) { w.setAttribute('data-t', '1'); }
    });
    const inner = await p.$('[data-t="1"] .choices__inner');
    if (inner) {
        await inner.click().catch(() => {});
        await p.waitForTimeout(400);
        for (const it of await p.$$('[data-t="1"] .choices__list--dropdown .choices__item--selectable')) {
            const t = (await it.textContent() || '').trim();
            const v = await it.getAttribute('data-value');
            if (!t || /^select\b/i.test(t) || !v) { continue; }
            await it.click().catch(() => {}); break;
        }
    }
    await p.evaluate(() => {
        const s = document.querySelector('#tag_id');
        if (s && s.options.length) {
            s.options[0].selected = true;
            if (window.jQuery && jQuery(s).data('select2')) { jQuery(s).trigger('change'); }
        }
        const c = document.querySelector('#status');
        if (c && !c.checked) { c.click(); }
    });
    await p.evaluate((b64) => {
        const bin = atob(b64); const bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
        const dt = new DataTransfer();
        dt.items.add(new File([bytes], 'cover.png', { type: 'image/png' }));
        const el = document.querySelector('#cover_image');
        el.files = dt.files;
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }, PNG_B64);
}

(async () => {
    const browser = await chromium.launch();
    try {
        const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
        const p = await ctx.newPage();
        const errs = [];
        p.on('pageerror', (e) => errs.push(String(e.message).slice(0, 160)));

        /* THE RESPONSE BODY IS READ BY INTERCEPTION, not by a late `r.text()`. The success branch
           navigates immediately, and an awaited `r.text()` afterwards returned `<unreadable>` every
           time -- so the server's own message could not be asserted at all. */
        let saveBody = null;
        await p.route((u) => /\/product$/.test(u.pathname || ''), async (route) => {
            if (route.request().method() !== 'POST') { return route.continue(); }
            const resp = await route.fetch();
            saveBody = await resp.text();
            await route.fulfill({ response: resp, body: saveBody });
        });

        await login(p);

        /* ================= A. THE CLIENT GUARD ================= */
        console.log('\n  == A. the editor states the rules, and refuses what the server would refuse ==');
        await p.goto(BASE + '/product/create', { waitUntil: 'networkidle' });
        await p.waitForTimeout(1600);

        const hints = await p.evaluate(() => Array.from(document.querySelectorAll('[data-ss-upload-hint]'))
            .map((e) => ({
                id: e.id,
                text: e.textContent.trim().replace(/\s+/g, ' '),
                visible: e.checkVisibility ? e.checkVisibility({ contentVisibilityAuto: true }) : e.offsetParent !== null,
                colour: getComputedStyle(e).color,
            })));
        console.log('        hints: ' + JSON.stringify(hints.map((h) => h.id + '=' + h.text.slice(0, 72))));
        check('an upload hint is rendered for the gallery and the cover', hints.length >= 2,
              'found ' + hints.length);
        check('both hints are visible', hints.every((h) => h.visible),
              JSON.stringify(hints.map((h) => h.id + ':' + h.visible)));
        check('the hints name the accepted formats', hints.every((h) => /JPG/i.test(h.text)),
              JSON.stringify(hints.map((h) => h.text.slice(0, 60))));
        check('the hints state the REAL per-file limit, not 256 MB or 2 GB',
              hints.every((h) => /2 MB/.test(h.text)) && !hints.some((h) => /256|2 GB/.test(h.text)),
              JSON.stringify(hints.map((h) => h.text.slice(0, 80))));

        const cover = await p.evaluate(() => {
            const el = document.querySelector('#cover_image');
            return { accept: el.getAttribute('accept'), describedBy: el.getAttribute('aria-describedby') };
        });
        check('the cover input filters the OS picker', /\.png/.test(String(cover.accept)),
              JSON.stringify(cover.accept));
        check('and points at its hint for assistive technology',
              cover.describedBy === 'ss-cover-upload-hint', String(cover.describedBy));

        const dz = await p.evaluate(async (gif) => {
            const el = document.querySelector('[data-toggle="dropzone1"]');
            if (!el || !el.dropzone) { return { err: 'no dropzone instance' }; }
            const bin = atob(gif); const bytes = new Uint8Array(bin.length);
            for (let i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
            el.dropzone.addFile(new File([bytes], 'holiday.gif', { type: 'image/gif' }));
            await new Promise((r) => setTimeout(r, 900));
            const f = el.dropzone.files[0];
            return {
                acceptedFiles: el.dropzone.options.acceptedFiles,
                maxFilesize: el.dropzone.options.maxFilesize,
                status: f && f.status,
                accepted: el.dropzone.getAcceptedFiles().length,
                removeLink: !!document.querySelector('.dz-remove'),
            };
        }, GIF_B64);
        console.log('        dropzone: ' + JSON.stringify(dz));
        check('acceptedFiles is the server\'s own list, not null', /\.png/.test(String(dz.acceptedFiles)),
              JSON.stringify(dz.acceptedFiles));
        check('maxFilesize is the real ceiling, not Dropzone\'s 256 MB default', dz.maxFilesize === 2,
              String(dz.maxFilesize));
        check('A GIF IS REFUSED BEFORE ANY UPLOAD', dz.status === 'error' && dz.accepted === 0,
              'status=' + dz.status + ' accepted=' + dz.accepted);
        check('a queued file can be removed again', dz.removeLink === true, String(dz.removeLink));

        /* ================= B. THE SERVER FALLBACK ================= */
        console.log('\n  == B. a file that passes the client guard and the server still refuses ==');
        await p.goto(BASE + '/product/create', { waitUntil: 'networkidle' });
        await p.waitForTimeout(1600);

        const unique = 'Gallery Feedback ' + Date.now().toString().slice(-7);
        await fillRequired(p, unique);

        /* A TEXT FILE NAMED `.png`. `acceptedFiles` matches on extension so the client lets it
           through; the server guesses from CONTENT and `mimes:` refuses it. This is also what a
           corrupted or mislabelled file looks like, so it is not a contrived case. */
        const queued = await p.evaluate(async (png) => {
            const el = document.querySelector('[data-toggle="dropzone1"]');
            const bin = atob(png); const bytes = new Uint8Array(bin.length);
            for (let i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
            el.dropzone.addFile(new File([bytes], 'accepted.png', { type: 'image/png' }));
            el.dropzone.addFile(new File(['this is not an image at all'], 'mislabelled.png', { type: 'image/png' }));
            await new Promise((r) => setTimeout(r, 1200));
            return {
                queued: el.dropzone.files.map((f) => f.name + ':' + f.status),
                accepted: el.dropzone.getAcceptedFiles().map((f) => f.name),
            };
        }, PNG_B64);
        console.log('        dropzone: ' + JSON.stringify(queued));
        /* THE PREMISE. If the client refused it there would be nothing for the server half to
           answer, and this case is what says so rather than the run passing on nothing. */
        check('both files passed the CLIENT guard (so the server half is reachable)',
              queued.accepted.length === 2, JSON.stringify(queued));

        await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await p.waitForTimeout(200);
        await p.click('.ss-actionbar [data-ss-click-proxy]');
        /* The success branch navigates to the product index, which is where the carried message is
           shown -- so wait for THAT page rather than reading a toast the navigation discarded. */
        await p.waitForURL(/\/product(\?|$)/, { timeout: 30000 }).catch(() => {});
        /* THE TOAST LIVES 4 SECONDS (`notifier.show(..., 4000)` in public/js/custom.js:236), so it
           is read as soon as the destination page is ready rather than after a fixed wait that
           could fall outside the window. Polled, because `$(document).ready()` on the index page is
           what reads the carried message and that is not tied to `waitForURL` resolving. */
        let shown = null;
        for (let i = 0; i < 24; i++) {
            await p.waitForTimeout(250);
            shown = await p.evaluate(() => ({
                url: location.pathname + location.search,
                toasts: Array.from(document.querySelectorAll('.notifier, .notifier-container, .toast'))
                    .map((e) => e.textContent.trim().replace(/\s+/g, ' ')).join(' | '),
                body: document.body.textContent.replace(/\s+/g, ' '),
            }));
            if (shown.toasts.length > 0) { break; }
        }

        const shownFinal = await p.evaluate(() => ({
            url: location.pathname + location.search,
            toasts: Array.from(document.querySelectorAll('.notifier, .notifier-container, .toast'))
                .map((e) => e.textContent.trim().replace(/\s+/g, ' ')).join(' | '),
            body: document.body.textContent.replace(/\s+/g, ' '),
        }));
        shown = shown && shown.toasts.length ? shown : shownFinal;
        console.log('        landed on: ' + shown.url);
        console.log('        toast: ' + JSON.stringify(shown.toasts.slice(0, 220)));

        check('the save succeeded and the merchant was taken to the list', /\/product/.test(shown.url),
              shown.url);
        /* B1 -- THE SERVER REPORTED IT. Separate from B2 on purpose: the server half and the
           display half fail independently, and an assertion that mixed them could not say which. */
        let saveJson = null;
        try { saveJson = JSON.parse(saveBody); } catch (e) { /* not json */ }
        console.log('        server msg: ' + JSON.stringify(saveJson && saveJson.msg));
        check('B1 — the SERVER response names the refused file and why',
              !!(saveJson && /mislabelled\.png/.test(String(saveJson.msg))
                 && saveJson.rejected_images && saveJson.rejected_images['mislabelled.png']),
              JSON.stringify(saveJson && { msg: String(saveJson.msg).slice(0, 160),
                                           rejected: saveJson.rejected_images }));
        check('B1b — and `flag` is still success, because the product WAS saved',
              !!(saveJson && saveJson.flag === 'success'), String(saveJson && saveJson.flag));

        console.log('        body mentions it: ' + /not uploaded|لم يتم تحميل/.test(shown.body));
        check('B2 — THE MERCHANT IS TOLD AN IMAGE WAS REFUSED',
              /mislabelled\.png/.test(shown.toasts) || /not uploaded|لم يتم تحميل/.test(shown.toasts),
              'toast=' + JSON.stringify(shown.toasts.slice(0, 220))
              + ' bodyHasIt=' + /not uploaded/.test(shown.body));
        check('and the good image is NOT reported as refused',
              !/accepted\.png/.test(shown.toasts), JSON.stringify(shown.toasts.slice(0, 200)));

        console.log('PRODUCT_NAME=' + unique);
        check('no page errors throughout', errs.length === 0, errs.join(' | '));
        await ctx.close();
    } finally {
        await browser.close();
    }

    console.log('');
    if (failures.length) {
        console.log('FAIL ' + failures.length + ' of ' + cases);
        failures.forEach((f) => console.log('   - ' + f));
        process.exit(1);
    }
    console.log('PASS ' + cases + '/' + cases);
})().catch((e) => { console.error('HARNESS ERROR: ' + e.message); process.exit(2); });
