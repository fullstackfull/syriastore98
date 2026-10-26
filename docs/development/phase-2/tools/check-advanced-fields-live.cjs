#!/usr/bin/env node
'use strict';
/**
 * P2-03-8 -- the advanced-fields disclosure, in the RUNNING application.
 *
 * NO REAL CUSTOMER DATA. The tenant is `database/seed-p2dev.php`; every value is invented.
 *
 * Owner conditions of 2026-10-26 that this exists to answer:
 *   (1) every field preserved -- names, values, submit paths, validation. That is
 *       `inventory-product-fields.cjs`; this file does not duplicate it.
 *   (2) hiding a field must not prevent submission or lose its value. Case B below
 *       drives a real save with the section CLOSED and re-reads the product.
 *   (4) actually test Arabic/English, RTL/LTR, light/dark, mobile/desktop. Cases C/D.
 *
 * Direction and theme are SERVER-side settings (`SITE_RTL`, `cust_darklayout`), so they are
 * flipped with set-preview-state.php rather than by setting an attribute the layout never
 * reads -- ledger finding F8 is that mistake, recorded twice. Language is a separate switch
 * through /change-language, and it is applied AFTER the direction for the same
 * order-dependence that made check-pinned-column-live.cjs read 57/58.
 *
 * usage: node check-advanced-fields-live.cjs [baseUrl] [storeId]
 */
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const STORE = process.argv[3] || '1';
const REPO = path.join(__dirname, '..', '..', '..', '..');
const EMAIL = 'merchant@example.test', PASSWORD = 'password';

const ADVANCED = ['tax_id[]', 'tax_status', 'brand_id', 'label_id', 'shipping_id', 'product_weight'];

let cases = 0; const failures = [];
const check = (label, cond, detail) => {
    cases++;
    if (cond) { console.log('  ok    ' + label); }
    else { failures.push(label + (detail ? '  [' + detail + ']' : '')); console.log('  FAIL  ' + label + (detail ? '  [' + detail + ']' : '')); }
};

const PHPENV = Object.assign({}, process.env, {
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'),
    CACHE_STORE: 'file',
});
const readState = () => {
    try { return JSON.parse(execFileSync('php', [path.join(__dirname, 'read-preview-state.php'), STORE],
        { cwd: REPO, stdio: 'pipe', env: PHPENV }).toString().trim()); }
    catch (e) { console.error('  note: could not read fixture state -- ' + e.message); return null; }
};
const setState = (theme, dir) => execFileSync('php',
    [path.join(__dirname, 'set-preview-state.php'), STORE, theme, dir],
    { cwd: REPO, stdio: 'pipe', env: PHPENV });

async function login(p) {
    await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await p.fill('input[name="email"]', EMAIL);
    await p.fill('input[name="password"]', PASSWORD);
    await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
}

/* sRGB relative luminance + WCAG ratio, computed here rather than eyeballed. */
const lum = (rgb) => {
    const f = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]);
};
const ratio = (a, b) => { const la = lum(a), lb = lum(b); const hi = Math.max(la, lb), lo = Math.min(la, lb); return (hi + 0.05) / (lo + 0.05); };
const parseRgb = (s) => { const m = String(s).match(/(\d+(?:\.\d+)?)/g); return m ? m.slice(0, 3).map(Number) : null; };

const INITIAL = readState();

(async () => {
    const browser = await chromium.launch();
    try {
        /* ---------------- A. structure, en/ltr/light ---------------- */
        setState('light', 'ltr');
        let ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        let p = await ctx.newPage();
        const errs = [];
        p.on('pageerror', (e) => errs.push(String(e.message).slice(0, 160)));
        await login(p);
        await p.goto(BASE + '/change-language/en', { waitUntil: 'domcontentloaded' }).catch(() => {});

        for (const [label, url] of [['create', '/product/create'], ['edit', '/product/32/edit']]) {
            await p.goto(BASE + url, { waitUntil: 'networkidle' });
            await p.waitForTimeout(900);

            const a = await p.evaluate((ADV) => {
                const d = document.querySelector('details.ss-advanced');
                if (!d) { return { present: false }; }
                const inside = Array.from(d.querySelectorAll('input[name],select[name],textarea[name]')).map((e) => e.name);
                const sum = d.querySelector('summary.ss-advanced__summary');
                const r = sum.getBoundingClientRect();
                return {
                    present: true,
                    open: d.hasAttribute('open'),
                    inside,
                    missing: ADV.filter((n) => !inside.includes(n)),
                    extra: inside.filter((n) => !ADV.includes(n)),
                    tagInside: inside.includes('tag_id[]'),
                    requiredInside: Array.from(d.querySelectorAll('[required]')).map((e) => e.name || e.id),
                    summaryText: sum ? sum.textContent.trim().replace(/\s+/g, ' ') : null,
                    summaryHeight: Math.round(r.height),
                    // a <details> with aria-expanded reports its state twice
                    doubleState: d.hasAttribute('aria-expanded') || sum.hasAttribute('aria-expanded'),
                    hintPresent: !!d.querySelector('.ss-advanced__hint'),
                };
            }, ADVANCED);

            check(`[${label}] disclosure is present`, a.present);
            if (!a.present) { continue; }
            check(`[${label}] collapsed by default`, a.open === false, 'open=' + a.open);
            check(`[${label}] holds exactly the six advanced fields`, a.missing.length === 0 && a.extra.length === 0,
                  'missing=' + a.missing.join(',') + ' extra=' + a.extra.join(','));
            check(`[${label}] tag_id[] is NOT inside (it carries required)`, a.tagInside === false);
            check(`[${label}] no required control inside the disclosure`, a.requiredInside.length === 0,
                  a.requiredInside.join(','));
            check(`[${label}] state is not reported twice (no hand-written aria-expanded)`, a.doubleState === false);
            check(`[${label}] summary tap target >= 24px (WCAG 2.5.8)`, a.summaryHeight >= 24, a.summaryHeight + 'px');
            check(`[${label}] hint tells the merchant what is inside`, a.hintPresent);

            /* The six fields are not rendered while closed, and are once open.
               `offsetParent !== null` IS NOT THE TEST. Chromium hides a closed
               <details> with content-visibility on ::details-content, which leaves
               a plain control's offsetParent non-null -- measured: it reported
               tax_status and product_weight as laid out inside a closed section,
               while the four Choices-managed selects read correctly only because
               their widget sets display:none on them. checkVisibility() with
               contentVisibilityAuto is the probe that actually answers this. */
            const visClosed = await p.evaluate(() => {
                const d = document.querySelector('details.ss-advanced');
                return Array.from(d.querySelectorAll('input[name],select[name]'))
                    .filter((e) => e.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true, contentVisibilityAuto: true }))
                    .length;
            });
            check(`[${label}] closed: no advanced control is laid out`, visClosed === 0, 'laidOut=' + visClosed);

            await p.click('details.ss-advanced > summary');
            await p.waitForTimeout(250);
            const afterClick = await p.evaluate(() => {
                const d = document.querySelector('details.ss-advanced');
                return { open: d.hasAttribute('open'),
                         laidOut: Array.from(d.querySelectorAll('input[name],select[name]'))
                             .filter((e) => e.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true, contentVisibilityAuto: true })).length };
            });
            check(`[${label}] click opens it`, afterClick.open === true);
            check(`[${label}] open: advanced controls are laid out`, afterClick.laidOut > 0, 'laidOut=' + afterClick.laidOut);

            /* keyboard: Enter on the summary is the native disclosure toggle */
            await p.focus('details.ss-advanced > summary');
            await p.keyboard.press('Enter');
            await p.waitForTimeout(200);
            const afterEnter = await p.evaluate(() => document.querySelector('details.ss-advanced').hasAttribute('open'));
            check(`[${label}] Enter on the summary closes it again`, afterEnter === false, 'open=' + afterEnter);
        }
        check('no page errors in the en/ltr/light pass', errs.length === 0, errs.join(' | '));
        await ctx.close();

        /* ---------------- B. condition 2: a CLOSED section still submits ----------------

           This is the case the whole slice stands on. It asserts the claim in TWO
           independent ways, because each one alone can lie:

             1. The MULTIPART REQUEST BODY is read and the collapsed fields are
                looked up in it by name. `#submit-all` does not submit the form
                natively -- it builds a FormData from $('#choice_form').serializeArray()
                and posts it with contentType:false -- so the body is multipart and
                parsing it as urlencoded returns null for every key. That mistake made
                an earlier run of this file report "POST sent null" for fields that
                were in fact present, which would have been a false defect against
                correct code.
             2. The PERSISTED ROW, re-read from a fresh page load.

           The product must also be made VALID first. `cost_price` is
           `required|numeric|min:0|lte:price` on update and the fixture product has
           none, so a save that does not set it is rejected with
           {"flag":"error","msg":"Cost price is required."} -- correctly -- and
           nothing persists. An earlier run of this file read that as the collapsed
           fields being dropped. It was the pre-existing rule doing its job. */
        ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        p = await ctx.newPage();
        let postBody = null, postResponse = null, navigatedToIndex = false;
        p.on('request', (r) => { if (r.method() === 'POST' && /\/product\/32$/.test(r.url())) { postBody = r.postData(); } });
        p.on('response', async (r) => {
            if (r.request().method() === 'POST' && /\/product\/32$/.test(r.url())) {
                try { postResponse = (await r.text()).slice(0, 400); } catch (e) { postResponse = '<unreadable>'; }
            }
        });
        /* On success the handler sets window.location to the product index, and that
           navigation destroys the response body before r.text() resolves -- the body
           reads '<unreadable>' for a save that in fact succeeded. The navigation is
           itself the success signal, because the handler performs it ONLY in the
           `data.flag == "success"` branch, so it is captured here and either signal
           satisfies the case. */
        p.on('framenavigated', (f) => {
            if (f === p.mainFrame() && /\/product(\?|$)/.test(f.url().replace(BASE, ''))) { navigatedToIndex = true; }
        });
        await login(p);
        await p.goto(BASE + '/product/32/edit', { waitUntil: 'networkidle' });
        await p.waitForTimeout(900);

        const newWeight = '7.77';
        const before = await p.evaluate(() => ({
            taxStatus: document.querySelector('[name="tax_status"]').value,
            price: document.querySelector('[name="price"]').value,
            open: document.querySelector('details.ss-advanced').hasAttribute('open'),
        }));
        check('[submit] the section is closed before the edit', before.open === false);

        /* Write to the two PLAIN controls inside the closed section. They are plain on
           purpose: this case is about whether a closed <details> submits, not about
           whether Choices.js works, so driving a widget would confuse the two. */
        const chosenTax = await p.evaluate((w) => {
            const wt = document.querySelector('[name="product_weight"]');
            wt.value = w; wt.dispatchEvent(new Event('input', { bubbles: true })); wt.dispatchEvent(new Event('change', { bubbles: true }));
            const ts = document.querySelector('[name="tax_status"]');
            const other = Array.from(ts.options).map((o) => o.value).filter((v) => v !== ts.value && v !== '');
            if (other.length) { ts.value = other[0]; ts.dispatchEvent(new Event('change', { bubbles: true })); }
            return ts.value;
        }, newWeight);
        check('[submit] a different tax_status was available to choose', chosenTax !== before.taxStatus,
              before.taxStatus + ' -> ' + chosenTax);

        /* satisfy the pre-existing required rule, OUTSIDE the collapsed section */
        const cost = String(Math.max(1, Math.floor(Number(before.price || 10) / 2)));
        await p.fill('[name="cost_price"]', cost);

        const stillClosed = await p.evaluate(() => document.querySelector('details.ss-advanced').hasAttribute('open'));
        check('[submit] the section is STILL closed after writing to its fields', stillClosed === false);

        /* save through the sticky action bar, which is how a merchant saves from the bottom */
        await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await p.waitForTimeout(200);
        await p.click('.ss-actionbar [data-ss-click-proxy]');
        await p.waitForTimeout(4000);

        /* --- proof 1: the request body --- */
        const inBody = (field) => {
            if (!postBody) { return null; }
            const re = new RegExp('name="' + field.replace(/[[\]]/g, '\\$&') + '"\\r?\\n\\r?\\n([^\\r\\n]*)');
            const m = postBody.match(re);
            return m ? m[1] : null;
        };
        check('[submit] a POST to the product was observed', !!postBody);
        check('[submit] product_weight is IN the multipart body while collapsed', inBody('product_weight') === newWeight,
              JSON.stringify(inBody('product_weight')));
        check('[submit] tax_status is IN the multipart body while collapsed', inBody('tax_status') === chosenTax,
              JSON.stringify(inBody('tax_status')));
        for (const f of ['brand_id', 'label_id', 'shipping_id']) {
            check(`[submit] ${f} is present in the multipart body while collapsed`, inBody(f) !== null,
                  'value=' + JSON.stringify(inBody(f)));
        }

        /* --- the server accepted it --- */
        let flag = null;
        try { flag = JSON.parse(postResponse).flag; } catch (e) { /* not json */ }
        check('[submit] the server accepted the save', flag === 'success' || navigatedToIndex,
              'flag=' + flag + ' navigatedToIndex=' + navigatedToIndex +
              ' response=' + String(postResponse).replace(/\s+/g, ' ').slice(0, 120));

        /* --- proof 2: the persisted row --- */
        await p.goto(BASE + '/product/32/edit', { waitUntil: 'networkidle' });
        await p.waitForTimeout(900);
        const after = await p.evaluate(() => ({
            weight: document.querySelector('[name="product_weight"]').value,
            taxStatus: document.querySelector('[name="tax_status"]').value,
        }));
        check('[submit] product_weight set while COLLAPSED persisted', after.weight === newWeight,
              'expected ' + newWeight + ' got ' + JSON.stringify(after.weight));
        check('[submit] tax_status set while COLLAPSED persisted', after.taxStatus === chosenTax,
              'expected ' + chosenTax + ' got ' + JSON.stringify(after.taxStatus));

        /* --- condition 2, the other half: a REJECTED save must tell the merchant.
               The editor posts by AJAX and reports through show_toastr, which uses
               `notifier`, NOT toastr -- `window.toastr` is undefined on this page, and
               looking for toastr's #toast-container found nothing and nearly became a
               reported defect against working code. The real node is .notifier. --- */
        await p.fill('[name="cost_price"]', '');
        await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await p.waitForTimeout(150);
        await p.click('.ss-actionbar [data-ss-click-proxy]');
        await p.waitForTimeout(2500);
        const rejected = await p.evaluate(() => {
            const n = Array.from(document.querySelectorAll('.notifier'))
                .filter((e) => e.checkVisibility ? e.checkVisibility() : e.offsetParent !== null);
            return { count: n.length, text: n.map((e) => e.textContent.trim().replace(/\s+/g, ' ').slice(0, 120)) };
        });
        check('[submit] a rejected save is reported to the merchant', rejected.count > 0,
              JSON.stringify(rejected.text));
        await ctx.close();

        /* ---------------- C. four configurations ---------------- */
        for (const [theme, dir, lang, expect] of [
            ['light', 'ltr', 'en', 'Advanced options'],
            ['light', 'rtl', 'ar', 'خيارات متقدمة'],
            ['dark',  'ltr', 'en', 'Advanced options'],
            ['dark',  'rtl', 'ar', 'خيارات متقدمة'],
        ]) {
            setState(theme, dir);
            ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
            p = await ctx.newPage();
            const cfgErrs = [];
            p.on('pageerror', (e) => cfgErrs.push(String(e.message).slice(0, 140)));
            await login(p);
            await p.goto(BASE + '/change-language/' + lang, { waitUntil: 'domcontentloaded' }).catch(() => {});
            await p.goto(BASE + '/product/32/edit', { waitUntil: 'networkidle' });
            await p.waitForTimeout(900);

            const tag = `${theme}/${dir}/${lang}`;
            const m = await p.evaluate(async () => {
                const d = document.querySelector('details.ss-advanced');
                if (!d) { return null; }
                const sum = d.querySelector('summary');
                const title = d.querySelector('.ss-advanced__title');
                const chev = d.querySelector('.ss-advanced__chevron');
                const bgOf = (el) => {
                    let n = el;
                    while (n && n !== document.documentElement) {
                        const c = getComputedStyle(n).backgroundColor;
                        if (c && c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent') { return c; }
                        n = n.parentElement;
                    }
                    return getComputedStyle(document.body).backgroundColor;
                };
                /* The chevron TRANSITIONS, so getComputedStyle immediately after
                   toggling `open` returns the value at t=0, not the end state.
                   Measured: in RTL that made closed and open both read
                   matrix(-1,0,0,-1,0,0) -- 180deg, the start of a 180->90
                   interpolation -- and the case failed against correct CSS.
                   In LTR it happened to differ only because `none` -> 90deg does
                   not interpolate the same way. Both are settled here. */
                const settle = () => new Promise((r) => setTimeout(r, 450));
                const chevNow = () => getComputedStyle(d.querySelector('.ss-advanced__chevron')).transform;
                d.removeAttribute('open'); await settle();
                const closedChev = chevNow();
                d.setAttribute('open', ''); await settle();
                const openChev = chevNow();
                d.removeAttribute('open'); await settle();
                return {
                    htmlDir: document.documentElement.getAttribute('dir') || 'ltr',
                    title: title ? title.textContent.trim() : null,
                    fg: getComputedStyle(sum).color,
                    bg: bgOf(sum),
                    closedChev, openChev,
                    borderColor: getComputedStyle(d).borderTopColor,
                    cardBg: bgOf(d.parentElement),
                };
            });

            check(`[${tag}] disclosure renders`, !!m);
            if (!m) { await ctx.close(); continue; }
            check(`[${tag}] document direction is ${dir}`, (m.htmlDir || 'ltr') === dir, 'dir=' + m.htmlDir);
            check(`[${tag}] summary title is localised`, m.title === expect, JSON.stringify(m.title));

            const fg = parseRgb(m.fg), bg = parseRgb(m.bg);
            const r = (fg && bg) ? ratio(fg, bg) : 0;
            check(`[${tag}] summary text contrast >= 4.5:1`, r >= 4.5, r.toFixed(2) + ':1  ' + m.fg + ' on ' + m.bg);

            const bc = parseRgb(m.borderColor), cb = parseRgb(m.cardBg);
            const br = (bc && cb) ? ratio(bc, cb) : 0;
            check(`[${tag}] box border >= 3:1 against the card behind it`, br >= 3.0,
                  br.toFixed(2) + ':1  ' + m.borderColor + ' on ' + m.cardBg);

            /* the chevron must point along the reading direction when closed, and the
               open state must differ from the closed one in every configuration */
            check(`[${tag}] open chevron differs from closed`, m.openChev !== m.closedChev,
                  'closed=' + m.closedChev + ' open=' + m.openChev);
            if (dir === 'rtl') {
                check(`[${tag}] closed chevron is mirrored for RTL`, m.closedChev !== 'none',
                      'transform=' + m.closedChev);
            }
            check(`[${tag}] no page errors`, cfgErrs.length === 0, cfgErrs.join(' | '));
            await ctx.close();
        }

        /* ---------------- D. mobile ---------------- */
        for (const [theme, dir, lang] of [['light', 'ltr', 'en'], ['light', 'rtl', 'ar']]) {
            setState(theme, dir);
            ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
            p = await ctx.newPage();
            await login(p);
            await p.goto(BASE + '/change-language/' + lang, { waitUntil: 'domcontentloaded' }).catch(() => {});
            await p.goto(BASE + '/product/32/edit', { waitUntil: 'networkidle' });
            await p.waitForTimeout(900);
            const tag = `mobile-390/${dir}`;
            const m = await p.evaluate(() => {
                const d = document.querySelector('details.ss-advanced');
                if (!d) { return null; }
                const sum = d.querySelector('summary');
                const r = sum.getBoundingClientRect();
                d.setAttribute('open', '');
                const overflowOpen = document.documentElement.scrollWidth - document.documentElement.clientWidth;
                d.removeAttribute('open');
                return { h: Math.round(r.height), w: Math.round(r.width),
                         overflowClosed: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                         overflowOpen,
                         inViewport: r.left >= -1 && r.right <= window.innerWidth + 1 };
            });
            check(`[${tag}] disclosure renders`, !!m);
            if (!m) { await ctx.close(); continue; }
            check(`[${tag}] summary tap target >= 24px`, m.h >= 24, m.h + 'px');
            check(`[${tag}] summary sits inside the viewport`, m.inViewport, 'w=' + m.w);
            check(`[${tag}] no horizontal page overflow, closed`, m.overflowClosed <= 1, m.overflowClosed + 'px');
            check(`[${tag}] no horizontal page overflow, open`, m.overflowOpen <= 1, m.overflowOpen + 'px');
            await ctx.close();
        }
    } finally {
        await browser.close();
        if (INITIAL) {
            try { setState(INITIAL.theme, INITIAL.dir); console.log('\n  (fixture state restored to ' + INITIAL.theme + '/' + INITIAL.dir + ')'); }
            catch (e) { console.error('  note: could not restore fixture state -- ' + e.message); }
        }
    }

    console.log('');
    if (failures.length) {
        console.log('FAIL ' + failures.length + ' of ' + cases);
        failures.forEach((f) => console.log('   - ' + f));
        process.exit(1);
    }
    console.log('PASS ' + cases + '/' + cases);
})().catch((e) => { console.error('HARNESS ERROR: ' + e.message); process.exit(2); });
