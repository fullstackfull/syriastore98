#!/usr/bin/env node
'use strict';
/**
 * F58 / P2-03-3 — can a product be CREATED through the real UI?
 *
 * NO REAL CUSTOMER DATA. The tenant is `database/seed-p2dev.php`; every value is invented and
 * the uploaded images are generated here.
 *
 * WHY THIS EXISTS. P2-03-3's acceptance asks for end-to-end save proven "بما يشمل الوسائط
 * والمتغيرات" — including media and variants. The UPDATE half was proven on 2026-10-26. The
 * CREATE half could not be: `store()` validates `cost_price` as required and writes it
 * unconditionally, while `products.cost_price` was a GATE F column with no migration, so on any
 * database built from this repository a create answered
 * `table products has no column named cost_price`. That was F58, escalated and then approved by
 * the owner; this file is what establishes the fix actually unblocked the path rather than
 * asserting it from the schema.
 *
 * IT UPLOADS REAL FILES. `cover_image` and `product_image` are both `required` in store()'s
 * rules, and `product_image` arrives through Dropzone rather than a plain input — so a harness
 * that only filled text fields would be refused by validation and prove nothing about the
 * column. The gallery file is handed to the live Dropzone instance.
 *
 * usage: node check-product-create-live.cjs [baseUrl]
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const EMAIL = 'merchant@example.test', PASSWORD = 'password';

let cases = 0; const failures = [];
const check = (label, cond, detail) => {
    cases++;
    if (cond) { console.log('  ok    ' + label); }
    else { failures.push(label + (detail ? '  [' + detail + ']' : '')); console.log('  FAIL  ' + label + (detail ? '  [' + detail + ']' : '')); }
};

/* A real PNG on disk, so the upload is a genuine multipart file part. */
function makePng(file) {
    const b64 = 'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAQklEQVR4nO3OMQ'
        + 'EAIAzAsMH/9ygDGrhJ+ts9JjMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzO7'
        + 'cQF7mQGJ3wZ0sQAAAABJRU5ErkJggg==';
    fs.writeFileSync(file, Buffer.from(b64, 'base64'));
    return file;
}

async function login(p) {
    await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await p.fill('input[name="email"]', EMAIL);
    await p.fill('input[name="password"]', PASSWORD);
    await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
}

(async () => {
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'p2-create-'));
    const cover = makePng(path.join(tmp, 'cover.png'));
    const gallery = makePng(path.join(tmp, 'gallery.png'));

    const browser = await chromium.launch();
    try {
        const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
        const p = await ctx.newPage();
        const errs = [];
        p.on('pageerror', (e) => errs.push(String(e.message).slice(0, 160)));

        /* `postData()` returns NULL for a multipart body that carries a real file, so the
           request is tracked with its own flag and the body is only used when it happens to be
           readable. An earlier version reported "no POST was issued" while the server's reply
           to that very POST was in hand. */
        let postBody = null, postResponse = null, navigated = false, postSeen = false;
        p.on('request', (r) => {
            if (r.method() === 'POST' && /\/product$/.test(r.url())) {
                postSeen = true;
                postBody = r.postData();   // null when multipart carries a file
            }
        });
        p.on('response', async (r) => {
            if (r.request().method() === 'POST' && /\/product$/.test(r.url())) {
                try { postResponse = (await r.text()).slice(0, 500); } catch (e) { postResponse = '<unreadable>'; }
            }
        });
        p.on('framenavigated', (f) => { if (f === p.mainFrame() && /\/product(\?|$)/.test(f.url().replace(BASE, ''))) { navigated = true; } });

        await login(p);
        await p.goto(BASE + '/product/create', { waitUntil: 'networkidle' });
        await p.waitForTimeout(1500);

        const unique = 'Created Via UI ' + Math.abs(Buffer.from(tmp).reduce((a, c) => a + c, 0)) + '-' + tmp.slice(-5);

        /* ---- the plain text fields ---- */
        await p.fill('input[name="name"]', unique);
        await p.fill('input[name="price"]', '45');
        await p.fill('input[name="cost_price"]', '20');

        /* ---- category and a tag, both through their Choices widgets ---- */
        const pickChoices = async (nativeSelector, label) => {
            const marked = await p.evaluate((sel) => {
                const el = document.querySelector(sel);
                const wrap = el && el.closest('.choices');
                if (!wrap) { return false; }
                wrap.setAttribute('data-create-target', '1');
                return true;
            }, nativeSelector);
            if (!marked) { return null; }
            const inner = await p.$('[data-create-target="1"] .choices__inner');
            if (!inner) { return null; }
            await inner.click().catch(() => {});
            await p.waitForTimeout(400);
            /* SKIP THE PLACEHOLDER. The first selectable item is "Select Category", whose
               value is empty -- picking it left `category_id` blank and the server answered
               "The category id field is required.", which read as a create failure when it was
               the harness choosing nothing. */
            const items = await p.$$('[data-create-target="1"] .choices__list--dropdown .choices__item--selectable');
            let txt = null;
            for (const it of items) {
                const t = (await it.textContent() || '').trim();
                const v = await it.getAttribute('data-value');
                if (!t || /^select\b/i.test(t) || v === '' || v === null) { continue; }
                txt = t;
                await it.click().catch(() => {});
                break;
            }
            await p.evaluate(() => {
                const w = document.querySelector('[data-create-target="1"]');
                if (w) { w.removeAttribute('data-create-target'); }
            });
            await p.waitForTimeout(300);
            console.log('        picked ' + label + ': ' + JSON.stringify(txt));
            return txt;
        };
        const cat = await pickChoices('#category_id', 'category');
        check('a category could be chosen through its widget', !!cat, String(cat));

        // tag_id[] is a select2, not Choices
        const tagged = await p.evaluate(() => {
            const s = document.querySelector('#tag_id');
            if (!s || !s.options.length) { return null; }
            s.options[0].selected = true;
            if (window.jQuery && jQuery(s).data('select2')) { jQuery(s).trigger('change'); }
            else { s.dispatchEvent(new Event('change', { bubbles: true })); }
            return s.options[0].text.trim();
        });
        check('a tag could be selected', !!tagged, String(tagged));

        /* ---- status, which store() validates as required ---- */
        await p.evaluate(() => {
            const c = document.querySelector('#status');
            if (c && !c.checked) { c.click(); }
        });

        /* ---- the images ---- */
        await p.setInputFiles('#cover_image', cover);

        /* THE GALLERY FILE GOES THROUGH `dropzone.addFile()`, which is Dropzone's own
           programmatic entry point and what a real drop ends up calling.
           `setInputFiles('[data-toggle="dropzone1"] input[type=file]')` does NOT work: the
           element contains a decoy `<input id="dropzone-1">` from the preview TEMPLATE, and
           Dropzone's actual input (`hiddenFileInput`) is appended to <body>, detached from the
           element. Setting files on the decoy left `dropzone.files` empty while a template
           `.dz-preview` made it look as though a file had landed -- so the harness reported
           `previews=1, accepted=0` and the server refused with "The product image field is
           required." */
        const galleryB64 = fs.readFileSync(gallery).toString('base64');
        const added = await p.evaluate(async (b64) => {
            const el = document.querySelector('[data-toggle="dropzone1"]');
            if (!el || !el.dropzone) { return 'no instance'; }
            const bin = atob(b64);
            const bytes = new Uint8Array(bin.length);
            for (let i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
            const file = new File([bytes], 'gallery.png', { type: 'image/png' });
            el.dropzone.addFile(file);
            return 'added';
        }, galleryB64);
        console.log('        dropzone addFile: ' + added);
        await p.waitForTimeout(1500);

        const media = await p.evaluate(() => {
            const el = document.querySelector('[data-toggle="dropzone1"]');
            return {
                coverChosen: (document.querySelector('#cover_image') || {}).files ? document.querySelector('#cover_image').files.length : 0,
                dropzoneAccepted: el && el.dropzone ? el.dropzone.getAcceptedFiles().length : null,
                dropzoneAll: el && el.dropzone ? el.dropzone.files.length : null,
                dropzoneStatuses: el && el.dropzone ? el.dropzone.files.map((f) => f.status) : null,
                previews: document.querySelectorAll('.dz-preview').length,
            };
        });
        check('the cover image is attached', media.coverChosen > 0, 'files=' + media.coverChosen);
        check('the gallery image reached the Dropzone instance', media.dropzoneAll > 0,
              'all=' + media.dropzoneAll + ' accepted=' + media.dropzoneAccepted
              + ' statuses=' + JSON.stringify(media.dropzoneStatuses) + ' previews=' + media.previews);

        /* ---- save, through the sticky bar ---- */
        await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await p.waitForTimeout(200);
        await p.click('.ss-actionbar [data-ss-click-proxy]');
        await p.waitForTimeout(6000);

        check('a POST to /product was issued', postSeen);
        const inBody = (f) => {
            if (!postBody) { return null; }
            const m = postBody.match(new RegExp('name="' + f.replace(/[[\]]/g, '\\$&') + '"\\r?\\n\\r?\\n([^\\r\\n]*)'));
            return m ? m[1] : null;
        };
        // Only assertable when the body was readable; the persisted row below is the real proof.
        if (postBody) {
            check('cost_price was submitted', inBody('cost_price') === '20', JSON.stringify(inBody('cost_price')));
        } else {
            console.log('  note  the POST body is multipart with a file, so it is not readable here;'
                + ' the persisted row below is what proves the field arrived');
        }

        let flag = null, msg = null;
        try { const j = JSON.parse(postResponse); flag = j.flag; msg = j.msg; } catch (e) { /* not json */ }
        check('the server did NOT answer with the missing-column error',
              !(String(postResponse || '').includes('cost_price') && String(postResponse || '').includes('no column')),
              String(postResponse).replace(/\s+/g, ' ').slice(0, 200));
        check('the create was accepted', flag === 'success' || navigated,
              'flag=' + flag + ' msg=' + JSON.stringify(msg) + ' navigated=' + navigated);

        /* ---- and it is really there ---- */
        await p.goto(BASE + '/product', { waitUntil: 'networkidle' });
        await p.waitForTimeout(1200);
        await p.fill('#product-table_filter input, input[type="search"]', unique.slice(0, 24)).catch(() => {});
        await p.waitForTimeout(1500);
        const listed = await p.evaluate((needle) => document.body.textContent.includes(needle), unique);
        check('the new product is listed', listed, 'searched for ' + JSON.stringify(unique));

        check('no page errors throughout', errs.length === 0, errs.join(' | '));
        await ctx.close();
    } finally {
        await browser.close();
        try { fs.rmSync(tmp, { recursive: true, force: true }); } catch (e) { /* best effort */ }
    }

    console.log('');
    if (failures.length) {
        console.log('FAIL ' + failures.length + ' of ' + cases);
        failures.forEach((f) => console.log('   - ' + f));
        process.exit(1);
    }
    console.log('PASS ' + cases + '/' + cases);
})().catch((e) => { console.error('HARNESS ERROR: ' + e.message); process.exit(2); });
