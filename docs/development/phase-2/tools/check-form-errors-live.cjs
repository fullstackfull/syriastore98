#!/usr/bin/env node
'use strict';
/**
 * F70 — a validation error must SURFACE and OPEN the section the failed field lives in.
 *
 * NO REAL CUSTOMER DATA. The tenant is `database/seed-p2dev.php`; every value is invented.
 *
 * WHAT MADE THIS UNREACHABLE, and why the fix has two halves. The editor posts by AJAX, so a
 * failed save never re-renders the page: `ProductController` returned `{flag, msg}` -- one
 * message, no field names -- and the server-rendered error story (the summary component, the
 * inline messages, a collapsed `<details>` opening because one of its fields failed) could never
 * fire. On top of that, NONE of the six fields the P2-03-8 disclosure holds had a validation rule
 * at all, so nothing in that section could fail even in principle.
 *
 * So: the response now also carries `errors` keyed by field (additive -- `flag` and `msg` are
 * untouched, and the shipped Flutter app does not reach this handler at all, posting to
 * `api/admin/addproduct` instead), `StoreSyriaDS.applyFormErrors()` applies it, and
 * `product_weight` -- which is INSIDE the collapsed section -- gained
 * `nullable|numeric|min:0`. That last part is what makes this case drivable rather than
 * hypothetical.
 *
 * THE DECISIVE CASE IS "COLLAPSED FIELD". A merchant whose weight is rejected was being shown a
 * toast while the box holding the weight stayed shut. Marking the field is not enough on its own,
 * because the field is not on screen.
 *
 * usage: node check-form-errors-live.cjs [baseUrl]
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

async function login(p) {
    await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await p.fill('input[name="email"]', EMAIL);
    await p.fill('input[name="password"]', PASSWORD);
    await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
}

(async () => {
    const browser = await chromium.launch();
    try {
        const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
        const p = await ctx.newPage();
        const errs = [];
        p.on('pageerror', (e) => errs.push(String(e.message).slice(0, 160)));

        let response = null;
        p.on('response', async (r) => {
            if (r.request().method() === 'POST' && /\/product\/\d+$/.test(r.url())) {
                try { response = (await r.text()).slice(0, 600); } catch (e) { response = '<unreadable>'; }
            }
        });

        await login(p);
        await p.goto(BASE + '/product/32/edit', { waitUntil: 'networkidle' });
        await p.waitForTimeout(1400);

        check('the DS exposes applyFormErrors', await p.evaluate(
            () => !!(window.StoreSyriaDS && typeof StoreSyriaDS.applyFormErrors === 'function')));

        const before = await p.evaluate(() => ({
            open: document.querySelector('details.ss-advanced').hasAttribute('open'),
            summary: !!document.querySelector('[data-ss-error-summary]'),
            invalid: document.querySelectorAll('.is-invalid').length,
            weightInDetails: !!(document.querySelector('[name="product_weight"]') || {}).closest
                && !!document.querySelector('[name="product_weight"]').closest('details'),
        }));
        check('the section starts closed', before.open === false);
        check('no summary and no invalid field before the save', !before.summary && before.invalid === 0,
              'summary=' + before.summary + ' invalid=' + before.invalid);
        check('product_weight really is inside the collapsed section', before.weightInDetails);

        /* An invalid weight, written while the section is CLOSED, plus a valid cost price so the
           only failure is the one under test.
           A NEGATIVE NUMBER, NOT "abc". `product_weight` is `Form::number()`, and assigning a
           non-numeric string to an `<input type="number">` is REJECTED BY THE BROWSER -- the
           value stays empty, which `nullable` accepts, so the save succeeded and this case
           measured nothing. `-5` is a value the input accepts and `min:0` refuses. */
        await p.fill('[name="cost_price"]', '5');
        const wrote = await p.evaluate(() => {
            const w = document.querySelector('[name="product_weight"]');
            w.value = '-5';
            w.dispatchEvent(new Event('input', { bubbles: true }));
            w.dispatchEvent(new Event('change', { bubbles: true }));
            return w.value;
        });
        check('the invalid value really landed in the control', wrote === '-5', JSON.stringify(wrote));
        check('the section is still closed when the save is fired',
              await p.evaluate(() => !document.querySelector('details.ss-advanced').hasAttribute('open')));

        await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await p.waitForTimeout(150);
        await p.click('.ss-actionbar [data-ss-click-proxy]');
        await p.waitForTimeout(3500);

        /* ---- the response shape: additive, not replaced ---- */
        let json = null;
        try { json = JSON.parse(response); } catch (e) { /* not json */ }
        check('the server answered with JSON', !!json, String(response).slice(0, 160));
        if (json) {
            check('`flag` is unchanged for existing readers', json.flag === 'error', String(json.flag));
            check('`msg` is unchanged for existing readers', typeof json.msg === 'string' && json.msg.length > 0,
                  JSON.stringify(json.msg));
            check('`errors` is present and names the field', !!(json.errors && json.errors.product_weight),
                  JSON.stringify(json.errors));
        }

        /* ---- and the page acted on it ---- */
        const stillOnEditor = await p.evaluate(() => !!document.querySelector('details.ss-advanced'));
        check('the save was REFUSED, so the page did not navigate away', stillOnEditor,
              'url=' + p.url().replace(BASE, ''));
        if (!stillOnEditor) {
            console.log('\n  the editor navigated away, so the remaining cases cannot be measured');
            console.log('FAIL ' + (failures.length) + ' of ' + cases);
            failures.forEach((f) => console.log('   - ' + f));
            await ctx.close(); await browser.close();
            process.exit(1);
        }
        const after = await p.evaluate(() => {
            const d = document.querySelector('details.ss-advanced');
            const w = document.querySelector('[name="product_weight"]');
            const summary = document.querySelector('[data-ss-error-summary]');
            const vis = (el) => !!(el && (el.checkVisibility ? el.checkVisibility({ contentVisibilityAuto: true }) : el.offsetParent !== null));
            return {
                sectionOpen: d.hasAttribute('open'),
                sectionFlagged: d.getAttribute('data-ss-advanced-error') === 'true',
                badge: !!d.querySelector('.ss-advanced__badge'),
                badgeText: (d.querySelector('.ss-advanced__badge') || {}).textContent || null,
                weightInvalid: w.classList.contains('is-invalid'),
                weightAriaInvalid: w.getAttribute('aria-invalid'),
                weightVisibleNow: vis(w),
                note: (() => {
                    const n = document.querySelector('.ss-invalid-note');
                    return n ? n.textContent.trim() : null;
                })(),
                describedBy: w.getAttribute('aria-describedby'),
                summaryPresent: !!summary,
                summaryText: summary ? summary.textContent.trim().replace(/\s+/g, ' ').slice(0, 140) : null,
                summaryRole: summary ? summary.getAttribute('role') : null,
                toast: Array.from(document.querySelectorAll('.notifier'))
                    .map((e) => e.textContent.trim().replace(/\s+/g, ' ').slice(0, 90)),
            };
        });

        check('THE COLLAPSED SECTION OPENED', after.sectionOpen === true);
        check('the section carries the error state', after.sectionFlagged === true);
        check('and a textual badge, not colour alone', after.badge === true, String(after.badgeText));
        check('the badge is localised', !!after.badgeText && after.badgeText.trim().length > 0, String(after.badgeText));
        check('the failed field is marked invalid', after.weightInvalid === true);
        check('and marked for assistive technology', after.weightAriaInvalid === 'true', String(after.weightAriaInvalid));
        check('the field is now actually on screen', after.weightVisibleNow === true);
        check('an inline message sits beside it', !!after.note, String(after.note));
        check('the field points at that message', !!after.describedBy, String(after.describedBy));
        check('a summary was rendered', after.summaryPresent === true);
        check('the summary is announced', after.summaryRole === 'alert', String(after.summaryRole));
        check('the summary names the problem', !!after.summaryText && after.summaryText.length > 5,
              String(after.summaryText));
        check('the existing toast still fires (nothing regressed)', after.toast.length > 0,
              JSON.stringify(after.toast));

        /* ---- and a subsequent VALID save clears all of it ---- */
        await p.evaluate(() => {
            const w = document.querySelector('[name="product_weight"]');
            w.value = '2.5';
            w.dispatchEvent(new Event('change', { bubbles: true }));
        });
        await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await p.waitForTimeout(150);
        await p.click('.ss-actionbar [data-ss-click-proxy]');
        await p.waitForTimeout(4000);

        await p.goto(BASE + '/product/32/edit', { waitUntil: 'networkidle' });
        await p.waitForTimeout(1200);
        const cleared = await p.evaluate(() => ({
            weight: document.querySelector('[name="product_weight"]').value,
            invalid: document.querySelectorAll('.is-invalid').length,
            notes: document.querySelectorAll('.ss-invalid-note').length,
            summary: !!document.querySelector('[data-ss-error-summary]'),
            open: document.querySelector('details.ss-advanced').hasAttribute('open'),
        }));
        check('a corrected value saves', cleared.weight === '2.5', JSON.stringify(cleared.weight));
        check('and the error state is gone afterwards',
              cleared.invalid === 0 && cleared.notes === 0 && !cleared.summary,
              JSON.stringify(cleared));
        check('and the section is closed again on a clean load', cleared.open === false);

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
