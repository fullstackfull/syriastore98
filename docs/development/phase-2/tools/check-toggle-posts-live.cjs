#!/usr/bin/env node
/**
 * P2-05-1 -- the four converted toggles, driven in a real browser.
 * ============================================================================================
 *
 * The suite proves the handlers refuse a foreign row, a GET and an ungated caller. It cannot
 * prove that the SCREENS still work: the three views' `$.ajax` calls were changed from GET to
 * POST and given a CSRF header, and a mistake there is a toggle that silently stops working --
 * which a merchant discovers and a test suite does not, because the suite drives the route
 * rather than the page.
 *
 * WHAT IT MEASURES, per toggle:
 *
 *   1. the switch is on the page at all (a selector that matches nothing would make everything
 *      below pass vacuously -- the premise is asserted, not assumed);
 *   2. clicking it issues a **POST**, not a GET, to the right route;
 *   3. that request answers 200 and the handler reports success -- so the token reached it. A
 *      missing `X-CSRF-TOKEN` gives 419, which is exactly the shape this check exists to catch;
 *   4. **the database row actually changed**, read back over HTTP by reloading the page and
 *      re-reading the switch. A 200 whose write silently did nothing is the other failure mode;
 *   5. **a tokenless POST is refused with 419** -- `fetch` bypasses `$.ajaxSetup`, so this is
 *      the shape a cross-site POST takes once it reaches the handler. Without this case the
 *      file claimed CSRF protection it had never measured; removing the views' explicit header
 *      changed nothing here, because the global setup was supplying it all along;
 *   6. no uncaught page error anywhere.
 *
 * usage: node check-toggle-posts-live.cjs [baseUrl]
 * exit 0 = every case passed.
 */

'use strict';

const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';

const failures = [];
let cases = 0;

function check(label, condition, detail) {
    cases += 1;
    if (condition) {
        console.log('  ok    ' + label);
    } else {
        failures.push(label);
        console.error('  FAIL  ' + label + (detail === undefined ? '' : '  [' + detail + ']'));
    }
}

async function login(page) {
    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', 'merchant@example.test');
    await page.fill('input[name="password"]', 'password');
    await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.click('button[type="submit"]'),
    ]);
}

/**
 * Clicks the first matching switch and reports what the page sent and what came back.
 *
 * The request is captured by URL SUBSTRING rather than by listening for "any POST", because the
 * admin layout issues its own fetches (the notification badge, for one) and matching the wrong
 * one would report a POST that has nothing to do with the toggle.
 */
async function toggle(page, { screen, selector, uriPart, label }) {
    await page.goto(BASE + screen, { waitUntil: 'networkidle' });

    const switches = page.locator(selector);
    const count = await switches.count();

    check('premise: ' + label + ' — the switch is on the page', count > 0, selector);
    if (count === 0) { return; }

    const first = switches.first();
    const before = await first.isChecked();

    const seen = [];
    const handler = (request) => {
        if (request.url().includes(uriPart)) {
            seen.push({ method: request.method(), url: request.url() });
        }
    };
    page.on('request', handler);

    const responded = page.waitForResponse(
        (r) => r.url().includes(uriPart),
        { timeout: 15000 }
    ).catch(() => null);

    await first.click();
    const response = await responded;
    await page.waitForTimeout(500);
    page.off('request', handler);

    check(label + ' — the page issued a request to the route', seen.length > 0, uriPart);
    if (seen.length === 0) { return; }

    check(label + ' — it was a POST, not a GET', seen[0].method === 'POST', seen[0].method);

    // The query string is where the values used to travel. They belong in the body now.
    check(label + ' — no values are left in the query string',
        !/[?&](status|is_popular|customer_id|id)=/.test(seen[0].url), seen[0].url);

    check(label + ' — the handler answered 200 (a 419 means the token did not reach it)',
        response !== null && response.status() === 200,
        response ? String(response.status()) : 'no response');

    if (response && response.status() === 200) {
        let body = null;
        try { body = await response.json(); } catch (e) { /* not JSON */ }

        const ok = body && (body.status === 'success' || body.success);
        check(label + ' — the handler reported success', !!ok,
            body ? JSON.stringify(body).slice(0, 120) : 'unparseable body');
    }

    // THE WRITE LANDED. Reloaded, so this is read back from the database and not from the DOM
    // the click left behind.
    await page.goto(BASE + screen, { waitUntil: 'networkidle' });
    const after = await page.locator(selector).first().isChecked();

    check(label + ' — the row actually changed in the database',
        after === !before, 'before=' + before + ' after=' + after);
}

(async () => {
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();

    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('response', (r) => {
        if (r.status() >= 500 && !r.url().includes('/top-brand-category-chart')) {
            errors.push(r.status() + ' ' + r.url());
        }
    });

    try {
        await login(page);

        await toggle(page, {
            screen: '/product-brand',
            selector: '.status-index',
            uriPart: 'product-brand-status',
            label: 'brand status',
        });

        await toggle(page, {
            screen: '/product-brand',
            selector: '.popular-index',
            uriPart: 'product-brand-popular',
            label: 'brand popularity',
        });

        await toggle(page, {
            screen: '/product-label',
            selector: '.status-index',
            uriPart: 'product-label-status',
            label: 'label status',
        });

        await toggle(page, {
            screen: '/customer',
            selector: '.page-checkbox',
            uriPart: 'customer-status',
            label: 'customer status',
        });

        // ------------------------------------------------------- CSRF is actually enforced
        /*
         * MEASURED, NOT ASSUMED, and this is the case the file most needed.
         *
         * The three views do not set a CSRF header of their own -- `public/js/custom.js` sets
         * `$.ajaxSetup({headers: {'X-CSRF-TOKEN': ...}})` for every jQuery call, which is how
         * every other POST in this codebase gets its token. An earlier version of these views
         * DID set one explicitly, and removing it changed nothing in this check: the global
         * setup was already supplying it. That left the protection claimed and unmeasured.
         *
         * `fetch` bypasses `$.ajaxSetup` entirely, so a tokenless POST from the page's own
         * origin and session is exactly the shape a cross-site form POST would take once it
         * reached the handler. 419 is the answer required. A 200 here would mean these four
         * routes are POST in name only.
         */
        const tokenless = await page.evaluate(async (base) => {
            const out = {};
            for (const [label, uri, body] of [
                ['brand status', '/product-brand-status', 'id=1&status=0'],
                ['brand popularity', '/product-brand-popular', 'id=1&is_popular=1'],
                ['label status', '/product-label-status', 'id=1&status=0'],
                ['customer status', '/customer-status', 'customer_id=1&status=0'],
            ]) {
                try {
                    const r = await fetch(base + uri, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body,
                        credentials: 'same-origin',
                    });
                    out[label] = r.status;
                } catch (e) {
                    out[label] = 'threw: ' + String(e);
                }
            }
            return out;
        }, BASE);

        for (const [label, status] of Object.entries(tokenless)) {
            check(label + ' — a tokenless POST is refused with 419', status === 419, String(status));
        }

        check('no uncaught page error and no 500 on any driven screen',
            errors.length === 0, errors.slice(0, 3).join(' | '));
    } finally {
        await browser.close();
    }

    if (failures.length > 0) {
        console.error('\n' + failures.length + ' of ' + cases + ' cases FAILED');
        process.exit(1);
    }

    console.log('\n' + cases + ' cases passed — all four toggles POST, write, and refuse a '
        + 'tokenless request');
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
