#!/usr/bin/env node
/**
 * P2-03-2 -- the product list filters, driven in a real browser.
 * ============================================================================================
 *
 * The suite drives the AJAX leg directly with hand-built query strings. That proves the QUERY
 * narrows correctly; it cannot prove the PAGE does, and the page is where the mechanism is
 * unusual: there is no JavaScript at all. The filter bar is a GET form, and the table's AJAX
 * url is the page url, so submitting the form is what carries the filters into the second leg.
 * If that inheritance ever stopped holding, every suite case would stay green and the feature
 * would silently filter nothing after the first paint.
 *
 * WHAT IT MEASURES:
 *
 *   1. the bar renders, with all four controls (asserted, so nothing below is vacuous);
 *   2. choosing a filter and submitting changes the **row count** on the page;
 *   3. the AJAX request that follows **carries the filter**, which is the inheritance above;
 *   4. the filter is still SELECTED after the reload -- a control that forgets what it is
 *      showing is how a merchant ends up staring at a filtered list wondering where the rows
 *      went;
 *   5. "Clear filters" returns the full count and leaves a clean url;
 *   6. it works in **both directions and both themes**, because the bar wraps and a wrapped
 *      row is where an RTL mistake shows;
 *   7. no uncaught page error, and no 500 anywhere but the known MySQL-only dashboard chart.
 *
 * usage: node check-product-filters-live.cjs [baseUrl]
 */

'use strict';

const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';

/*
 * BOTH LISTS, one harness. P2-03-2 and P2-04-8 use the same mechanism on purpose -- a GET form
 * whose parameters the AJAX leg inherits -- so they get the same checks rather than a second
 * copy that could drift from this one.
 */
const LISTS = [
    {
        name: 'product',
        path: '/product',
        table: 'product-table',
        bar: '[data-ss-product-filters]',
        controls: ['ss_status', 'ss_stock', 'ss_category', 'ss_variant'],
        narrow: { name: 'ss_stock', value: 'out' },
        out: path.join(__dirname, '..', 'screenshots', 'p2-03-products'),
        prefix: '03-product-filters',
        englishStrings: ['Any status', 'Any stock level', 'Any category', 'Apply filters',
            'Clear filters', 'Out of stock', 'Low stock', 'In stock', 'Has variants',
            'No variants', 'Applies to products with stock tracking on.'],
    },
    {
        name: 'order',
        path: '/order',
        table: 'order-table',
        bar: '[data-ss-order-filters]',
        controls: ['ss_status', 'ss_payment', 'ss_from', 'ss_to'],
        narrow: { name: 'ss_payment', value: 'Paid' },
        out: path.join(__dirname, '..', 'screenshots', 'p2-04-orders'),
        prefix: '03-order-filters',
        englishStrings: ['Any status', 'Any payment status', 'Apply filters', 'Clear filters',
            'Fulfilment', 'Recorded in the same field, but not fulfilment states'],
    },
];

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

/** The host picks its stylesheet server-side, so the fixture has to move, not the client. */
function setServerState(theme, dir) {
    const repo = path.join(__dirname, '..', '..', '..', '..');
    execFileSync('php', [path.join(__dirname, 'set-preview-state.php'), '1', theme, dir], {
        cwd: repo,
        env: Object.assign({}, process.env, {
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: path.join(repo, 'database', 'p2dev.sqlite'),
            CACHE_STORE: 'file',
        }),
        stdio: 'pipe',
    });
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

async function drawn(page, table) {
    await page.waitForFunction(
        (id) => !!(window.jQuery && window.jQuery.fn.dataTable
            && window.jQuery.fn.dataTable.isDataTable('#' + id)),
        table,
        { timeout: 20000 }
    );
    await page.waitForTimeout(400);
    return page.evaluate(
        (id) => window.jQuery('#' + id).DataTable().page.info().recordsTotal,
        table
    );
}

(async () => {
    const browser = await chromium.launch();

    for (const dir of ['ltr', 'rtl']) {
        for (const theme of ['light', 'dark']) {
            setServerState(theme, dir === 'rtl' ? 'rtl' : 'ltr');

            const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
            const page = await ctx.newPage();
            const errors = [];
            page.on('pageerror', (e) => errors.push(String(e)));
            page.on('response', (r) => {
                if (r.status() >= 500 && !r.url().includes('/top-brand-category-chart')) {
                    errors.push(r.status() + ' ' + r.url());
                }
            });

            const tag = `${dir}-${theme}`;

            await login(page);
            await page.goto(`${BASE}/change-language/${dir === 'rtl' ? 'ar' : 'en'}`,
                { waitUntil: 'domcontentloaded' }).catch(() => {});

            for (const list of LISTS) {
                const label = `${list.name} ${tag}`;

                await page.goto(BASE + list.path, { waitUntil: 'networkidle' });
                const total = await drawn(page, list.table);

                check(`${label} — the filter bar renders`,
                    await page.locator(list.bar).count() > 0);

                for (const name of list.controls) {
                    check(`${label} — the bar offers ${name}`,
                        await page.locator(`${list.bar} [name="${name}"]`).count() > 0);
                }

                check(`${label} — premise: the unfiltered list has rows to narrow`,
                    total > 1, 'total=' + total);

                await page.screenshot({ path: path.join(list.out, `${list.prefix}-${tag}.png`) });

                // --------------------------------------------- filter, and follow the AJAX
                const seen = [];
                const onRequest = (r) => {
                    if (r.url().includes(list.path) && r.url().includes('draw=')) {
                        seen.push(r.url());
                    }
                };
                page.on('request', onRequest);

                await page.selectOption(`${list.bar} [name="${list.narrow.name}"]`, list.narrow.value);
                await Promise.all([
                    page.waitForLoadState('networkidle'),
                    page.click(`${list.bar} button[type="submit"]`),
                ]);

                const filtered = await drawn(page, list.table);
                page.off('request', onRequest);

                check(`${label} — the filter narrowed the list`, filtered < total,
                    `${filtered} of ${total}`);
                check(`${label} — and it left rows, so the filter is not just empty`,
                    filtered > 0, 'filtered=' + filtered);

                check(`${label} — the AJAX leg inherited the filter from the page url`,
                    seen.some((u) => u.includes(`${list.narrow.name}=`)),
                    seen.length ? seen[seen.length - 1].slice(0, 120) : 'no ajax request seen');

                check(`${label} — the control still shows what it is filtering by`,
                    await page.inputValue(`${list.bar} [name="${list.narrow.name}"]`) === list.narrow.value);

                await page.screenshot({
                    path: path.join(list.out, `${list.prefix}-applied-${tag}.png`),
                });

                // --------------------------------------------- clear
                const clear = page.locator(`${list.bar} a.ss-btn--ghost`);
                check(`${label} — a Clear control appears once filtered`, await clear.count() > 0);

                if (await clear.count() > 0) {
                    await Promise.all([page.waitForLoadState('networkidle'), clear.click()]);
                    const cleared = await drawn(page, list.table);

                    check(`${label} — clearing restores the full list`, cleared === total,
                        `${cleared} vs ${total}`);
                    check(`${label} — and leaves a clean url`,
                        !page.url().includes(list.narrow.name), page.url().slice(0, 100));
                }

                // --------------------------------------------- untranslated Phase 02 strings
                if (dir === 'rtl') {
                    const untranslated = await page.evaluate(([barSelector, words]) => {
                        const bar = document.querySelector(barSelector);
                        const text = (bar ? bar.textContent : '') || '';
                        return words.filter((w) => text.includes(w));
                    }, [list.bar, list.englishStrings]);

                    check(`${label} — no Phase 02 filter string renders in English`,
                        untranslated.length === 0, untranslated.slice(0, 4).join(', '));
                }

            /* ------------------------------------------------------------------ P2-06-5
               NAVIGATE AWAY AND COME BACK. The suite proves the memory with HTTP requests;
               this proves the thing the suite structurally cannot: that the SECOND LEG follows.

               The table's `ajax.url` is `url()->full()` evaluated when the page RENDERS, so a
               restore that only filled the controls would leave the AJAX still fetching the
               unfiltered url — the bar would look filtered and the rows would not be. That is
               invisible to a feature test, which never runs the DataTable. Here the request is
               watched. */
            {
                const jTag = `${dir}/${theme} ${list.name} — restore`;

                // Apply, by submitting the real bar rather than by typing a url.
                await page.goto(BASE + list.path, { waitUntil: 'domcontentloaded' });
                await page.selectOption(`${list.bar} [name="${list.narrow.name}"]`, list.narrow.value)
                    .catch(() => {});
                await Promise.all([
                    page.waitForLoadState('domcontentloaded'),
                    page.click(`${list.bar} button[type="submit"]`),
                ]);
                cases++;
                check(`${jTag} — applying puts the filter in the url`,
                    page.url().includes(`${list.narrow.name}=${list.narrow.value}`), page.url());

                // Navigate away to an unrelated screen, then return to the BARE list url.
                await page.goto(BASE + '/dashboard', { waitUntil: 'domcontentloaded' });

                const ajaxUrls = [];
                page.on('request', (r) => {
                    if (r.url().includes(list.path) && r.resourceType() === 'xhr') {
                        ajaxUrls.push(r.url());
                    }
                });

                await page.goto(BASE + list.path, { waitUntil: 'domcontentloaded' });
                await drawn(page, list.table).catch(() => {});

                cases++;
                check(`${jTag} — the bare url is restored to the filtered one`,
                    page.url().includes(`${list.narrow.name}=${list.narrow.value}`), page.url());

                cases++;
                check(`${jTag} — the AJAX leg carries the restored filter`,
                    ajaxUrls.some((u) => u.includes(`${list.narrow.name}=${list.narrow.value}`)),
                    'ajax urls seen: ' + (ajaxUrls.slice(0, 2).join(' | ') || 'none'));

                // The receipt, which is what makes the restore safe rather than silent.
                const receipt = await page.$('.ss-filterbar__summary');
                cases++;
                check(`${jTag} — a restored list says so on screen`, receipt !== null,
                    'no .ss-filterbar__summary rendered');

                if (receipt) {
                    const text = (await receipt.innerText()).trim();
                    cases++;
                    check(`${jTag} — the receipt is not empty`, text.length > 0, text);
                }

                // And one click gets out of it, for good.
                /* ONE clear control, in the bar's actions row. An earlier version of this
                   journey also matched a link inside the receipt, and Playwright's strict mode
                   is what reported it: two links to one url, 100px apart, with two different
                   labels. The receipt now states and the bar acts. */
                const clear = await page.$(list.bar + ' a.ss-btn--ghost');
                cases++;
                check(`${jTag} — a restored list offers a way out`, clear !== null,
                    'no clear control on a restored list');

                if (clear) {
                    await Promise.all([page.waitForLoadState('domcontentloaded'), clear.click()]);
                    cases++;
                    check(`${jTag} — clearing lands on a clean url`,
                        !page.url().includes(list.narrow.name), page.url());

                    await page.goto(BASE + list.path, { waitUntil: 'domcontentloaded' });
                    cases++;
                    check(`${jTag} — and the memory is really forgotten, not re-applied`,
                        !page.url().includes(list.narrow.name), page.url());
                }
            }
            }

            check(`${tag} — no uncaught page error and no 500`, errors.length === 0,
                errors.slice(0, 2).join(' | '));

            await ctx.close();
        }
    }

    await browser.close();

    if (failures.length > 0) {
        console.error('\n' + failures.length + ' of ' + cases + ' cases FAILED');
        process.exit(1);
    }

    console.log('\n' + cases + ' cases passed — both lists filter in all four configurations, '
        + 'with no JavaScript');
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
