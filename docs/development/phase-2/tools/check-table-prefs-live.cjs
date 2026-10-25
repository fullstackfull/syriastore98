#!/usr/bin/env node
/**
 * P2-06-4 -- table preferences, driven in a real browser against the running application.
 * ============================================================================================
 *
 * The node check (`check-table-state.cjs`) proves the sanitiser does what it claims to a state
 * object. It cannot prove that DataTables 1.10.20 CALLS it, that localStorage really holds the
 * preference across a navigation, or that the page length the merchant chose is the one the
 * table opens with next time. Only a browser can, and this is the claim the slice makes, so
 * this is the evidence for it.
 *
 * WHAT IT MEASURES, in order, on the real product list:
 *
 *   1. the default page length                        -- the baseline, or step 3 proves nothing
 *   2. choose 25 rows, type a search, go to page 2, and LEAVE ALL THREE SET
 *   3. reload: the LENGTH is 25                       -- the preference persisted
 *   4. reload: the SEARCH BOX IS EMPTY                -- the hazard did not
 *   5. reload: the table is on page 1 and shows the UNFILTERED count
 *   6. a SECOND screen (orders) keeps its own length  -- the key is per table, not global
 *   7. the stored state carries no tenant or customer data
 *
 * Step 4 is the one worth having. It is the difference between a preference and a merchant
 * concluding their orders are gone.
 *
 * "AND LEAVE ALL THREE SET" IS THE WHOLE CORRECTNESS OF THIS FILE, and it is here because the
 * first version got it wrong. That version cleared the search and returned to page one before
 * reloading -- so the saved state held no search and no offset, and steps 4 and 5 passed against
 * a state that never contained what they claimed to have dropped. BITE-TESTED, which is how it
 * was caught: with `sanitizeTableState` short-circuited to a no-op, that version still reported
 * 14 of 14 passing.
 *
 * Two consequences follow, and both are deliberate:
 *   - the search term MATCHES ROWS ('Panel', 27 of 32), because a term matching nothing would
 *     leave the reloaded table empty and "it shows rows again" would be ambiguous;
 *   - `recordsDisplay` after the reload is asserted to be the FULL count, not merely > 0. A
 *     restored search would give the filtered count, and that is the sharpest single reading
 *     this file takes.
 *
 * usage: node check-table-prefs-live.cjs [baseUrl]
 * exit 0 = every case passed. Any failure prints the case and exits 1.
 */

'use strict';

const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';

/* Matches 27 of the fixture's 32 products (database/seed-p2dev.php). It has to match MANY rows
   but not ALL of them -- see the header. */
const SEARCH_TERM = 'Panel';

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

/** The host's own login form, as the other live harnesses drive it. */
async function login(page) {
    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', 'merchant@example.test');
    await page.fill('input[name="password"]', 'password');
    await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.click('button[type="submit"]'),
    ]);
}

/** Waits for a DataTable to have finished its first AJAX draw. */
async function openList(page, path, tableId) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    await page.waitForFunction(
        (id) => !!(window.jQuery && window.jQuery.fn.dataTable
            && window.jQuery.fn.dataTable.isDataTable('#' + id)),
        tableId,
        { timeout: 20000 }
    );
}

const api = (page, tableId, expr) => page.evaluate(
    ([id, e]) => {
        const t = window.jQuery('#' + id).DataTable();
        /* eslint-disable no-new-func */
        return new Function('t', 'return (' + e + ');')(t);
    },
    [tableId, expr]
);

(async () => {
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();

    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('response', (r) => {
        // The one known MySQL-only dashboard chart (ledger blocker B5) answers 500 on SQLite and
        // is excluded BY NAME, so a 500 anywhere else still fails this run.
        if (r.status() >= 500 && !r.url().includes('/top-brand-category-chart')) {
            errors.push(r.status() + ' ' + r.url());
        }
    });

    try {
        await login(page);

        // Start from a clean slate, so case 3 cannot pass on state left by an earlier run.
        await page.evaluate(() => { try { localStorage.clear(); } catch (e) { /* private mode */ } });

        // ---------------------------------------------------------- 1. baseline
        await openList(page, '/product', 'product-table');

        const defaultLength = await api(page, 'product-table', 't.page.len()');
        check('premise: the product table draws and reports a page length',
            typeof defaultLength === 'number' && defaultLength > 0, 'len=' + defaultLength);
        check('premise: the default length is NOT the one we are about to choose',
            defaultLength !== 25, 'len=' + defaultLength);

        const saveWired = await api(page, 'product-table', 't.settings()[0].oFeatures.bStateSave');
        check('premise: DataTables has state saving enabled on this table', saveWired === true);

        const totalRows = await api(page, 'product-table', 't.page.info().recordsTotal');
        check('premise: the fixture has enough rows for a second page at a length of 25',
            totalRows > 25, 'total=' + totalRows);

        // ---------------------------------------------------------- 2. make choices, and KEEP them
        await page.evaluate(() => {
            const t = window.jQuery('#product-table').DataTable();
            t.page.len(25).draw(false);
        });
        await page.waitForTimeout(400);

        /*
         * The search goes on FIRST, because DataTables resets the page offset to 0 whenever the
         * filter changes (`_fnFilterComplete`). Searching after paging would silently undo the
         * paging and step 5 would have nothing to measure.
         */
        await page.evaluate((term) => {
            const t = window.jQuery('#product-table').DataTable();
            t.search(term).draw();
        }, SEARCH_TERM);
        await page.waitForTimeout(800);

        const filteredRows = await api(page, 'product-table', 't.page.info().recordsDisplay');
        check('premise: the search term matches some rows but not all of them',
            filteredRows > 25 && filteredRows < totalRows,
            'filtered=' + filteredRows + ' of ' + totalRows);

        await page.evaluate(() => {
            const t = window.jQuery('#product-table').DataTable();
            t.page(1).draw(false);
        });
        await page.waitForTimeout(800);

        // PREMISE: all three really are set in the live table, so the saved state has something
        // in it to drop. Without this the two "did not survive" cases below are vacuous -- which
        // is precisely how the first version of this file passed with the sanitiser disabled.
        const beforeReload = await page.evaluate(() => {
            const t = window.jQuery('#product-table').DataTable();
            return { len: t.page.len(), search: t.search(), page: t.page.info().page };
        });
        check('premise: the length is set before reloading', beforeReload.len === 25,
            'len=' + beforeReload.len);
        check('premise: the search term is still set before reloading',
            beforeReload.search === SEARCH_TERM, 'search=' + JSON.stringify(beforeReload.search));
        check('premise: the table is on page two before reloading', beforeReload.page === 1,
            'page=' + beforeReload.page);

        // PREMISE: something really was written. Without this, every "it was not restored"
        // assertion below passes against a feature that never stored anything at all.
        const stored = await page.evaluate(() => {
            const out = {};
            for (let i = 0; i < localStorage.length; i += 1) {
                const k = localStorage.key(i);
                if (k && k.indexOf('DataTables_') === 0) { out[k] = localStorage.getItem(k); }
            }
            return out;
        });
        const keys = Object.keys(stored);
        check('premise: a DataTables state was written to localStorage', keys.length > 0,
            keys.join(', '));

        // ---------------------------------------------------------- 3-5. reload
        await openList(page, '/product', 'product-table');

        const restoredLength = await api(page, 'product-table', 't.page.len()');
        check('the chosen page length survived the reload', restoredLength === 25,
            'len=' + restoredLength);

        const restoredSearch = await api(page, 'product-table', 't.search()');
        check('the search term did NOT survive the reload', restoredSearch === '',
            'search=' + JSON.stringify(restoredSearch));

        const searchBox = await page.evaluate(
            () => (document.querySelector('#product-table_wrapper input[type="search"]') || {}).value
        );
        check('and the search BOX the merchant looks at is empty', !searchBox,
            'box=' + JSON.stringify(searchBox));

        const restoredPage = await api(page, 'product-table', 't.page.info().page');
        check('the table opened on the first page', restoredPage === 0, 'page=' + restoredPage);

        /*
         * THE SHARPEST READING IN THE FILE. A restored search would give the FILTERED count, so
         * asserting the full count distinguishes "the search was dropped" from "the search
         * happened to match everything" and from "the table is simply not empty".
         */
        const rows = await api(page, 'product-table', 't.page.info().recordsDisplay');
        check('the reloaded table shows the UNFILTERED row count', rows === totalRows,
            'rows=' + rows + ' expected=' + totalRows);

        // ---------------------------------------------------------- 6. per table, not global
        await openList(page, '/order', 'order-table');
        const orderLength = await api(page, 'order-table', 't.page.len()');
        check('a different list keeps its OWN length rather than inheriting one',
            orderLength !== 25, 'order len=' + orderLength);

        // ---------------------------------------------------------- 7. what is stored
        /*
         * THE RAW VALUES, JOINED -- not `JSON.stringify(stored)`. Each localStorage value is
         * itself a JSON string, so stringifying the MAP escapes every quote inside it and
         * `/"length":25/` cannot match `\"length\":25`. Measured: the premise case below
         * reported a missing preference against a state that plainly carried it.
         */
        const blob = Object.keys(stored).map((k) => stored[k]).join('\n');
        check('the stored state carries no email address', !/@/.test(blob));
        check('the stored state carries no store identifier key', !/store_id/i.test(blob));
        /*
         * AND THE TERM IS NOT IN THE STORED BLOB AT ALL. Read from the state that was saved
         * while the search was still live, so this measures the sanitiser's write and not just
         * what DataTables chose to apply on load.
         */
        const savedAfter = await page.evaluate(() => {
            const out = [];
            for (let i = 0; i < localStorage.length; i += 1) {
                const k = localStorage.key(i);
                if (k && k.indexOf('DataTables_') === 0) { out.push(localStorage.getItem(k)); }
            }
            return out.join('\n');
        });

        check('the stored state carries no search term',
            !savedAfter.includes(SEARCH_TERM) && !blob.includes(SEARCH_TERM));

        // And the preference IS in it, so the assertion above is not passing because the state
        // is empty -- which is the only way "no search term" could be true for free.
        check('premise: the stored state does carry the chosen length',
            /"length":25/.test(savedAfter));

        check('premise: the stored state records the first page, not the second',
            /"start":0/.test(savedAfter));

        // ---------------------------------------------------------- no page errors
        check('no uncaught page error and no 500 on any driven screen',
            errors.length === 0, errors.join(' | '));
    } finally {
        await browser.close();
    }

    if (failures.length > 0) {
        console.error('\n' + failures.length + ' of ' + cases + ' cases FAILED');
        process.exit(1);
    }

    console.log('\n' + cases + ' cases passed -- table preferences persist, the search term does not');
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
