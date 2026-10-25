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
const OUT = path.join(__dirname, '..', 'screenshots', 'p2-03-products');

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

async function drawn(page) {
    await page.waitForFunction(
        () => !!(window.jQuery && window.jQuery.fn.dataTable
            && window.jQuery.fn.dataTable.isDataTable('#product-table')),
        null,
        { timeout: 20000 }
    );
    await page.waitForTimeout(400);
    return page.evaluate(() => window.jQuery('#product-table').DataTable().page.info().recordsTotal);
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

            await page.goto(BASE + '/product', { waitUntil: 'networkidle' });
            const total = await drawn(page);

            check(`${tag} — the filter bar renders`,
                await page.locator('[data-ss-product-filters]').count() > 0);

            for (const name of ['ss_status', 'ss_stock', 'ss_category', 'ss_variant']) {
                check(`${tag} — the bar offers ${name}`,
                    await page.locator(`[data-ss-product-filters] [name="${name}"]`).count() > 0);
            }

            check(`${tag} — premise: the unfiltered list has rows to narrow`, total > 1, 'total=' + total);

            await page.screenshot({ path: path.join(OUT, `03-product-filters-${tag}.png`) });

            // ------------------------------------------------- filter, and follow the AJAX
            const seen = [];
            const onRequest = (r) => {
                if (r.url().includes('/product') && r.url().includes('draw=')) { seen.push(r.url()); }
            };
            page.on('request', onRequest);

            await page.selectOption('[data-ss-product-filters] [name="ss_stock"]', 'out');
            await Promise.all([
                page.waitForLoadState('networkidle'),
                page.click('[data-ss-product-filters] button[type="submit"]'),
            ]);

            const filtered = await drawn(page);
            page.off('request', onRequest);

            check(`${tag} — the filter narrowed the list`, filtered < total,
                `${filtered} of ${total}`);
            check(`${tag} — and it left rows, so "out of stock" is not just empty`,
                filtered > 0, 'filtered=' + filtered);

            check(`${tag} — the AJAX leg inherited the filter from the page url`,
                seen.some((u) => u.includes('ss_stock=out')),
                seen.length ? seen[seen.length - 1].slice(0, 120) : 'no ajax request seen');

            check(`${tag} — the control still shows what it is filtering by`,
                await page.inputValue('[data-ss-product-filters] [name="ss_stock"]') === 'out');

            await page.screenshot({ path: path.join(OUT, `04-product-filters-applied-${tag}.png`) });

            // ------------------------------------------------- clear
            const clear = page.locator('[data-ss-product-filters] a.ss-btn--ghost');
            check(`${tag} — a Clear control appears once filtered`, await clear.count() > 0);

            if (await clear.count() > 0) {
                await Promise.all([page.waitForLoadState('networkidle'), clear.click()]);
                const cleared = await drawn(page);

                check(`${tag} — clearing restores the full list`, cleared === total,
                    `${cleared} vs ${total}`);
                check(`${tag} — and leaves a clean url`, !page.url().includes('ss_stock'),
                    page.url().slice(0, 100));
            }

            // ------------------------------------------------- untranslated Phase 02 strings
            if (dir === 'rtl') {
                const untranslated = await page.evaluate(() => {
                    const bar = document.querySelector('[data-ss-product-filters]');
                    const text = (bar ? bar.textContent : '') || '';
                    return ['Any status', 'Any stock level', 'Any category', 'Apply filters',
                        'Clear filters', 'Out of stock', 'Low stock', 'In stock', 'Has variants',
                        'No variants', 'Applies to products with stock tracking on.']
                        .filter((w) => text.includes(w));
                });

                check(`${tag} — no Phase 02 filter string renders in English`,
                    untranslated.length === 0, untranslated.slice(0, 4).join(', '));
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

    console.log('\n' + cases + ' cases passed — the filters narrow the real list in all four '
        + 'configurations, with no JavaScript');
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
