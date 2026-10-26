#!/usr/bin/env node
'use strict';
/**
 * P2-03-4 — the media and variant lifecycle a merchant actually performs.
 *
 * NO REAL CUSTOMER DATA. The tenant is `database/seed-p2dev.php`; every value is invented and
 * every uploaded file is generated here.
 *
 * WHY THIS FILE EXISTS BESIDE THE OTHERS. `check-gallery-upload-feedback-live.cjs` proved the
 * UPLOAD half (guidance, client refusal, server refusal reported). The owner's condition for
 * closing P2-03-4 names four more things, and none of them was driven: **deletion**, the **primary
 * image**, the **variant experience** and **validation errors**. This drives the first three
 * end-to-end against the real editor; the variant-key request-mangling case needs a term a browser
 * cannot easily type, so it is a Feature test instead (`VariantRequestKeyTest`).
 *
 * EACH SECTION READS THE DATABASE BACK through a separate PHP call rather than trusting the page.
 * The editor is an AJAX form that re-renders nothing, so "the thumbnail disappeared" and "the row
 * is gone" are different claims, and only the second one matters.
 *
 * usage: node check-media-variant-lifecycle-live.cjs [baseUrl]
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const REPO = '/home/user/syriastore98';
const PRODUCT = 1;

let cases = 0; const failures = [];
const check = (label, cond, detail) => {
    cases++;
    if (cond) { console.log('  ok    ' + label); }
    else { failures.push(label + (detail ? '  [' + detail + ']' : '')); console.log('  FAIL  ' + label + (detail ? '  [' + detail + ']' : '')); }
};

/** Ask the database directly. The page is not the record. */
function db(phpExpr) {
    const code = 'require "vendor/autoload.php"; $a = require "bootstrap/app.php";'
        + ' $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo json_encode(' + phpExpr + ');';
    const out = execFileSync('php', ['-r', code], {
        cwd: REPO,
        env: Object.assign({}, process.env, {
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'),
            CACHE_STORE: 'file',
        }),
        encoding: 'utf8',
    });
    return JSON.parse(out);
}

const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAQklEQVR4nO3OMQ'
    + 'EAIAzAsMH/9ygDGrhJ+ts9JjMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzO7'
    + 'cQF7mQGJ3wZ0sQAAAABJRU5ErkJggg==';

async function login(p) {
    await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await p.fill('input[name="email"]', 'merchant@example.test');
    await p.fill('input[name="password"]', 'password');
    await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
}

(async () => {
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'p2-lifecycle-'));
    const newCover = path.join(tmp, 'new-cover.png');
    fs.writeFileSync(newCover, Buffer.from(PNG_B64, 'base64'));

    // Re-seed so the run starts from a known state whatever a previous run did.
    execFileSync('php', ['artisan', 'tinker', '--execute', "require base_path('database/seed-p2dev.php');"], {
        cwd: REPO,
        env: Object.assign({}, process.env, {
            DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'), CACHE_STORE: 'file',
        }),
        stdio: 'pipe',
    });

    const browser = await chromium.launch();
    try {
        const ctx = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
        const p = await ctx.newPage();
        const errs = [];
        p.on('pageerror', (e) => errs.push(String(e.message).slice(0, 160)));

        await login(p);

        /* ===================== 1. GALLERY DELETION ===================== */
        console.log('\n  == 1. deleting an existing gallery image ==');
        await p.goto(BASE + '/product/' + PRODUCT + '/edit', { waitUntil: 'networkidle' });
        await p.waitForTimeout(2500);

        const before = db('DB::table("product_images")->where("product_id",' + PRODUCT + ')->pluck("image_path")');
        check('the product starts with a gallery to delete from', before.length >= 2,
              before.length + ' rows');

        const shown = await p.evaluate(() => Array.from(document.querySelectorAll('.deleteRecord'))
            .map((e) => e.getAttribute('data-id')));
        console.log('        delete controls on the page: ' + JSON.stringify(shown));
        check('every stored image has a delete control', shown.length === before.length,
              'controls=' + shown.length + ' rows=' + before.length);

        const targetId = shown[0];
        let deleteResponse = null;
        const waitDelete = p.waitForResponse(
            (r) => /\/products\/\d+\/delete/.test(r.url()), { timeout: 20000 }
        ).then(async (r) => { try { return { code: r.status(), body: (await r.text()).slice(0, 200) }; } catch (e) { return { code: r.status(), body: '<unreadable>' }; } })
         .catch(() => null);

        await p.evaluate((id) => {
            const btn = Array.from(document.querySelectorAll('.deleteRecord'))
                .find((e) => e.getAttribute('data-id') === id);
            btn.click();
        }, targetId);
        deleteResponse = await waitDelete;
        await p.waitForTimeout(2000);
        console.log('        server answered: ' + JSON.stringify(deleteResponse));

        check('the delete request reached the server', !!deleteResponse, 'no request observed');
        check('and was not refused', !!deleteResponse && deleteResponse.code < 400,
              JSON.stringify(deleteResponse));

        const after = db('DB::table("product_images")->where("product_id",' + PRODUCT + ')->pluck("image_path")');
        check('THE ROW IS REALLY GONE from the database', after.length === before.length - 1,
              'before=' + before.length + ' after=' + after.length);

        /* The reload is the merchant's own next step, and the only way to know the page agrees
           with the database rather than merely having hidden a node. */
        await p.goto(BASE + '/product/' + PRODUCT + '/edit', { waitUntil: 'networkidle' });
        await p.waitForTimeout(2200);
        const shownAfter = await p.evaluate(() => document.querySelectorAll('.deleteRecord').length);
        check('and the editor agrees after a reload', shownAfter === after.length,
              'page=' + shownAfter + ' db=' + after.length);

        /* ===================== 2. THE PRIMARY IMAGE ===================== */
        console.log('\n  == 2. changing the primary (cover) image ==');
        const coverBefore = db('DB::table("products")->where("id",' + PRODUCT + ')->value("cover_image_path")');
        console.log('        cover before: ' + JSON.stringify(coverBefore));
        check('the product starts with a cover image', !!coverBefore, String(coverBefore));

        const preview = await p.evaluate(() => {
            const c = document.querySelector('#upcoverImg');
            return { src: (c && c.getAttribute('src')) || '', natural: c ? c.naturalWidth : 0 };
        });
        check('the existing cover is previewed in the editor', preview.natural > 0,
              JSON.stringify(preview));

        await p.setInputFiles('#cover_image', newCover);
        await p.waitForTimeout(600);
        await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await p.waitForTimeout(200);
        await p.click('.ss-actionbar [data-ss-click-proxy]');
        await p.waitForTimeout(6500);

        const coverAfter = db('DB::table("products")->where("id",' + PRODUCT + ')->value("cover_image_path")');
        console.log('        cover after:  ' + JSON.stringify(coverAfter));
        check('THE COVER REALLY CHANGED in the database', !!coverAfter && coverAfter !== coverBefore,
              'before=' + JSON.stringify(coverBefore) + ' after=' + JSON.stringify(coverAfter));
        check('and the new cover is a path inside the store folder (P1-SEC-28)',
              !!coverAfter && /^uploads\//.test(String(coverAfter)), String(coverAfter));

        await p.goto(BASE + '/product/' + PRODUCT + '/edit', { waitUntil: 'networkidle' });
        await p.waitForTimeout(2200);
        const previewNew = await p.evaluate(() => {
            const c = document.querySelector('#upcoverImg');
            return { src: (c && c.getAttribute('src')) || '', natural: c ? c.naturalWidth : 0 };
        });
        check('the new cover renders in the editor', previewNew.natural > 0, JSON.stringify(previewNew));

        /* ===================== 3. THE VARIANT EXPERIENCE ===================== */
        console.log('\n  == 3. editing a variant and saving it ==');
        const variantsBefore = db('DB::table("product_variants")->where("product_id",' + PRODUCT
            + ')->orderBy("id")->get(["id","variant","variation_price","stock","sku"])');
        console.log('        variants: ' + JSON.stringify(variantsBefore.map((v) => v.variant)));
        check('the product has variants to edit', variantsBefore.length > 0, String(variantsBefore.length));

        /* THE CANARY VARIANT IS THE ONE WORTH DRIVING: `M/L-Natural` carries a CSS-special
           character, which is what F72 was about. Proving the toggles no longer throw is not the
           same as proving the variant can be SAVED. */
        const canary = variantsBefore.find((v) => /[^\w-]/.test(v.variant)) || variantsBefore[0];
        console.log('        driving variant: ' + JSON.stringify(canary.variant));

        const panel = await p.evaluate(async (key) => {
            const esc = (v) => (window.CSS && CSS.escape ? CSS.escape(v) : v);
            const item = document.querySelector('.remove_option_' + esc(key));
            if (!item) { return { err: 'no panel for ' + key }; }
            const toggle = item.querySelector('[data-bs-toggle="collapse"]');
            if (toggle) { toggle.click(); await new Promise((r) => setTimeout(r, 900)); }
            const priceField = item.querySelector('[name^="product_variation_price_"]');
            const stockField = item.querySelector('[name^="product_stock_"]');
            const skuField = item.querySelector('[name^="product_sku_"]');
            return {
                opened: !!item.querySelector('.accordion-collapse.show'),
                priceName: priceField ? priceField.getAttribute('name') : null,
                stockName: stockField ? stockField.getAttribute('name') : null,
                skuName: skuField ? skuField.getAttribute('name') : null,
            };
        }, canary.variant);
        console.log('        panel: ' + JSON.stringify(panel));
        check('the variant panel OPENS (what F72 restored)', panel.opened === true, JSON.stringify(panel));
        check('and it exposes the per-variant price field', !!panel.priceName, JSON.stringify(panel));

        const NEW_PRICE = '77.25', NEW_STOCK = '13', NEW_SKU = 'SKU-LIFECYCLE';
        const wrote = await p.evaluate(async ([key, price, stock, sku]) => {
            const esc = (v) => (window.CSS && CSS.escape ? CSS.escape(v) : v);
            const item = document.querySelector('.remove_option_' + esc(key));
            const set = (sel, v) => {
                const el = item.querySelector(sel);
                if (!el) { return null; }
                el.value = v;
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
                return el.value;
            };
            return {
                price: set('[name^="product_variation_price_"]', price),
                stock: set('[name^="product_stock_"]', stock),
                sku: set('[name^="product_sku_"]', sku),
            };
        }, [canary.variant, NEW_PRICE, NEW_STOCK, NEW_SKU]);
        console.log('        wrote into the controls: ' + JSON.stringify(wrote));
        check('the new values landed in the controls', wrote.price === NEW_PRICE, JSON.stringify(wrote));

        await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await p.waitForTimeout(200);
        await p.click('.ss-actionbar [data-ss-click-proxy]');
        await p.waitForTimeout(7000);

        const variantsAfter = db('DB::table("product_variants")->where("product_id",' + PRODUCT
            + ')->orderBy("id")->get(["id","variant","variation_price","stock","sku"])');
        const savedCanary = variantsAfter.find((v) => v.variant === canary.variant);
        console.log('        after save: ' + JSON.stringify(savedCanary));

        check('the variant row still exists after the save', !!savedCanary,
              'variants now: ' + JSON.stringify(variantsAfter.map((v) => v.variant)));
        if (savedCanary) {
            check('THE VARIANT PRICE PERSISTED', Math.abs(Number(savedCanary.variation_price) - Number(NEW_PRICE)) < 0.011,
                  'expected ' + NEW_PRICE + ' got ' + savedCanary.variation_price);
            check('the variant stock persisted', String(savedCanary.stock) === NEW_STOCK,
                  'expected ' + NEW_STOCK + ' got ' + savedCanary.stock);
            check('and the SKU persisted', String(savedCanary.sku || '').includes(NEW_SKU),
                  'expected to contain ' + NEW_SKU + ' got ' + JSON.stringify(savedCanary.sku));
        }
        check('no other variant was lost', variantsAfter.length === variantsBefore.length,
              'before=' + variantsBefore.length + ' after=' + variantsAfter.length);

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
