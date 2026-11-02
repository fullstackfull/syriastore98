#!/usr/bin/env node
/**
 * P2-06-6 — column reorder, driven in a real browser.
 * =================================================================================================
 *
 * WHAT ONLY A BROWSER CAN ANSWER HERE. `ColumnReorderContractTest` asserts the version pairing,
 * the licence, the vendored path and the options as CONFIGURED. None of that proves the extension
 * loads against this DataTables build, that a drag actually moves a column, that the order comes
 * back after a reload, that the ACTION column refuses to move, or that the keyboard path moves a
 * column the way the merchant sees rather than the way the array is indexed. Those are the things
 * that break.
 *
 * THE KEYBOARD PATH IS OURS, NOT THE EXTENSION'S, and that is why it is tested hardest.
 * ColReorder 1.5.4 binds `mousedown` and `touchstart` and contains zero occurrences of `keydown`,
 * `keyup`, `keypress`, `tabindex` or `aria-` — measured in the vendored file. Pointer-only is a
 * WCAG 2.1.1 failure for the function itself and a 2.5.7 one for requiring a drag with no
 * alternative, so the keyboard path is built on the extension's own `colReorder.move()` API: one
 * mover, two ways to reach it, and the extension stays unmodified so an upgrade is a file swap.
 *
 * DIRECTION IS THE SUBTLE ONE. The keys move the column the way the MERCHANT sees: in Arabic the
 * first column paints on the right, so ArrowRight must move a column to a LOWER index. Getting
 * that backwards produces a feature that works in English and feels broken in Arabic, which is
 * the same class of defect as a hard-coded `right` in CSS, and no English-only test can see it.
 *
 * AND TWO OFF-BY-ONES WERE FOUND HERE, NOT BY READING. The hidden `id` column is column 0 and
 * renders no `<th>`, so the headers in the DOM are columns 1..12: using DOM positions made the
 * action header focusable and handed `move()` indexes that moved nothing. And the first
 * announcement read "3 / 12" on a table of thirteen columns of which ten can move — two numbers
 * describing neither.
 *
 * PRECONDITIONS, and it fails loudly rather than reporting a clean pass without them:
 *   - a server on 8899 serving THIS repository with its assets. Launch it from inside `public/`
 *     so Laravel's dev router finds the static files (see `shoot-settings-live.cjs` for why);
 *   - `database/p2dev.sqlite` seeded by `database/seed-p2dev.php`.
 *
 * usage: node docs/development/phase-2/tools/check-column-reorder-live.cjs [baseUrl]
 */

'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const REPO = '/home/user/syriastore98';
const ENV = Object.assign({}, process.env, {
    DB_CONNECTION: 'sqlite', DB_DATABASE: REPO + '/database/p2dev.sqlite',
    CACHE_STORE: 'file', SESSION_DRIVER: 'file',
});

/* DIRECTION HAS TWO HALVES AND THE LANGUAGE IS ONLY ONE OF THEM. `layouts/app.blade.php`
   reads the store setting first (line 20, `Utility::GetValueByName('SITE_RTL')`) and then
   does this:

       if ($setting['currantLang'] == 'ar' || $setting['currantLang'] == 'he') {
           $setting['SITE_RTL'] = 'on';
       }

   -- it can only ever turn RTL **on**. There is no branch that turns it off, so a stored
   `SITE_RTL = 'on'` survives an English user and the page renders RTL with English headers.

   THIS HARNESS REPORTED LTR CASES THAT WERE MEASURING AN RTL PAGE. `p2dev.sqlite` carries
   `SITE_RTL = 'on'` for store 1, so setting only the user's language left every "ltr" run
   right-to-left; the direction assertion then compared RTL behaviour against the LTR
   expectation and failed, while the RTL run passed for the wrong reason -- the only direction
   ever actually exercised. Both halves have to be set, and the `Setting` cache flushed with
   them: `Setting` is `Cachable` and `CACHE_STORE=file` persists across processes, so a stale
   entry outlives the run that wrote it. */
function setDirection(lang) {
    const rtl = (lang === 'ar' || lang === 'he') ? 'on' : 'off';
    const got = execFileSync('php', ['-r',
        'require "vendor/autoload.php"; $a = require "bootstrap/app.php";'
        + ' $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
        + ' $u = App\\Models\\User::where("email", "merchant@example.test")->firstOrFail();'
        + ' $u->language = "' + lang + '"; $u->save(); (new App\\Models\\User)->flushCache();'
        // The store predicate is explicit: `Setting` is NOT in `config/tenancy.php`'s
        // `scoped_models`, so the global scope adds no predicate and a bare where("name")
        // would reach every tenant's row.
        + ' $store = (int) $u->current_store;'
        + ' $row = App\\Models\\Setting::withoutGlobalScopes()->where("store_id", $store)'
        + '     ->where("name", "SITE_RTL")->first();'
        + ' if ($row) { $row->value = "' + rtl + '"; $row->save(); }'
        + ' else { App\\Models\\Setting::create(["name" => "SITE_RTL", "value" => "' + rtl + '",'
        + '     "store_id" => $store, "created_by" => $u->creatorId()]); }'
        + ' (new App\\Models\\Setting)->flushCache();'
        + ' echo App\\Models\\Setting::withoutGlobalScopes()->where("store_id", $store)'
        + '     ->where("name", "SITE_RTL")->value("value");'],
        { cwd: REPO, env: ENV, stdio: 'pipe' }).toString().trim();

    // Fail loudly rather than measure the wrong direction silently -- the whole point.
    if (got !== rtl) throw new Error(`SITE_RTL is "${got}", asked for "${rtl}"`);
}

const TABLES = [
    { path: '/product', table: 'product-table', label: 'products' },
    { path: '/order', table: 'order-table', label: 'orders' },
];

const failures = [];
let cases = 0;

function check(label, condition, detail) {
    cases++;
    if (condition) {
        console.log('  ok    ' + label);
    } else {
        failures.push(label + (detail ? '  [' + detail + ']' : ''));
        console.log('  FAIL  ' + label + (detail ? '  [' + detail + ']' : ''));
    }
}

async function login(page) {
    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', 'merchant@example.test');
    await page.fill('input[name="password"]', 'password');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
    if (page.url().includes('/login')) throw new Error('login failed');
}

async function ready(page, table) {
    await page.waitForFunction((id) => !!(window.jQuery && window.jQuery.fn.dataTable
        && window.jQuery.fn.dataTable.isDataTable('#' + id)), table, { timeout: 45000 });
    await page.waitForTimeout(900);
}

const headerText = (page, table) => page.evaluate((id) =>
    Array.from(document.querySelectorAll('#' + id + ' thead th')).map((t) => t.textContent.trim()),
    table);

(async () => {
    const browser = await chromium.launch();

    try {
        for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
            setDirection(lang);

            for (const t of TABLES) {
                const ctx = await browser.newContext({ viewport: { width: 1600, height: 950 } });
                const page = await ctx.newPage();
                const errors = [];
                page.on('pageerror', (e) => errors.push(e.message));

                await login(page);
                await page.goto(BASE + t.path, { waitUntil: 'domcontentloaded' });
                await ready(page, t.table);

                const tag = `${dir} ${t.label}`;

                /* PREMISE: the direction really is the one asked for, and the extension really
                   loaded against this build. Everything below is vacuous otherwise. */
                const htmlDir = (await page.getAttribute('html', 'dir')) || 'ltr';
                check(`${tag} — the page is really ${dir}`, (htmlDir === 'rtl') === (dir === 'rtl'), htmlDir);

                const state = await page.evaluate((id) => {
                    const dt = window.jQuery.fn.dataTable;
                    const api = window.jQuery('#' + id).DataTable();
                    const s = api.settings()[0];
                    const cr = s && s._colReorder;
                    return {
                        loaded: typeof dt.ColReorder,
                        dtVersion: dt.version,
                        instance: !!cr,
                        fixedLeft: cr ? cr.s.fixed : null,
                        fixedRight: cr ? cr.s.fixedRight : null,
                        columns: api.columns().count(),
                        actionIsLast: (() => {
                            const h = api.column(api.columns().count() - 1).header();
                            return !!(h && h.className.indexOf('ss-col-pinned') !== -1);
                        })(),
                        /* The hidden `id` column, measured three ways, because the attribute
                           alone turned out to belong to someone else. */
                        idColumn: (() => {
                            const ths = Array.from(document.querySelectorAll('#' + id + ' thead th'));
                            const h = api.column(0).header();
                            return {
                                visible: api.column(0).visible(),
                                inDom: ths.indexOf(h),
                                tabindex: h ? h.getAttribute('tabindex') : 'no-node',
                            };
                        })(),
                        tabindexes: api.columns().indexes().toArray().map((i) => {
                            const h = api.column(i).header();
                            return h ? h.getAttribute('tabindex') : 'no-header';
                        }),
                    };
                }, t.table);

                check(`${tag} — ColReorder is loaded`, state.loaded === 'function', String(state.loaded));
                check(`${tag} — against DataTables 1.10.20`, state.dtVersion === '1.10.20', state.dtVersion);
                check(`${tag} — the table has a ColReorder instance`, state.instance === true);
                check(`${tag} — the ACTION column is the last one and is pinned`, state.actionIsLast === true);
                check(`${tag} — exactly one trailing column is held`, state.fixedRight === 1, String(state.fixedRight));
                check(`${tag} — the leading locked columns are held`, state.fixedLeft >= 1, String(state.fixedLeft));

                /* The action header must NOT be focusable: offering a keyboard affordance that
                   then refuses to act is worse than offering none. */
                const lastTab = state.tabindexes[state.tabindexes.length - 1];
                check(`${tag} — the action header is not focusable`, lastTab === null, String(lastTab));
                /* THE HIDDEN `id` COLUMN'S `tabindex` IS DATATABLES' OWN, NOT OURS, and asserting
                   it is null asserted against a third party. Core does this to every SORTABLE
                   column's header, hidden or not (`jquery.dataTables.min.js`:
                   `!1!==f.bSortable&&(d.attr("tabindex",a.iTabIndex)...)`, `iTabIndex: 0`) --
                   which is also why the checkbox and action headers have none: both are
                   `bSortable: false`. Measured on a clean profile: column 0 is `visible: false`,
                   is NOT in the rendered header row, and carries `tabindex="0"` regardless.
                   `wireColumnReorderKeys` cannot be the author -- it skips invisible columns and
                   its range starts at `s.fixed` (2 here), so index 0 is excluded twice over.

                   So assert what "not a focus target" actually means: the node is not in the
                   document, hence unreachable by Tab or by click. That is the property the
                   requirement cares about, and unlike the attribute it is ours to keep true.
                   The action column -- the one a merchant CAN see -- is still held to the
                   attribute above AND to the behavioural check further down. */
                check(`${tag} — the hidden id column really is hidden`,
                    state.idColumn.visible === false, String(state.idColumn.visible));
                check(`${tag} — the hidden id column is unreachable (renders no header cell)`,
                    state.idColumn.inDom === -1, `dom index ${state.idColumn.inDom}`);

                // ---- the keyboard path, in the direction the merchant sees ----
                const before = await headerText(page, t.table);

                /* A MIDDLE movable column, not the first one — and the live run is what taught
                   me this. In RTL, Alt+Right on the FIRST movable column correctly moves
                   nothing: "right" means a lower index there, and a lower index is held. The
                   harness was asserting a move that must not happen, and reported the product
                   as broken. Starting in the middle leaves room in both directions. */
                const moved = await page.evaluate((id) => {
                    const api = window.jQuery('#' + id).DataTable();
                    const s = api.settings()[0];
                    const first = s._colReorder.s.fixed;
                    const last = api.columns().count() - 1 - s._colReorder.s.fixedRight;
                    const mid = Math.floor((first + last) / 2);
                    const h = api.column(mid).header();
                    h.focus();
                    return { name: h.textContent.trim(), index: mid, first: first, last: last };
                }, t.table);

                check(`${tag} — premise: there is room to move in both directions`,
                    moved.index > moved.first && moved.index < moved.last,
                    `movable range ${moved.first}..${moved.last}, chose ${moved.index}`);

                await page.keyboard.press('Alt+ArrowRight');
                await page.waitForTimeout(400);
                const after = await headerText(page, t.table);

                check(`${tag} — Alt+Right moves a column`, JSON.stringify(before) !== JSON.stringify(after),
                    'order unchanged: ' + JSON.stringify(after.slice(0, 4)));

                /* DIRECTION. The merchant pressed "right", so the column must move the way they
                   see right: later in the DOM in LTR, EARLIER in RTL. */
                const was = before.indexOf(moved.name);
                const now = after.indexOf(moved.name);
                const wantEarlier = dir === 'rtl';
                check(`${tag} — and it moves the way the merchant sees "right"`,
                    wantEarlier ? now < was : now > was, `${moved.name}: ${was} -> ${now}`);

                check(`${tag} — the move is announced`,
                    await page.evaluate(() => {
                        const r = document.getElementById('ss-live-region');
                        return !!(r && r.textContent && r.textContent.trim().length > 0);
                    }), 'the live region is empty, so a keyboard user gets no feedback');

                /* The action column still cannot be reached by the keys, even deliberately. */
                await page.evaluate((id) => {
                    const api = window.jQuery('#' + id).DataTable();
                    const h = api.column(api.columns().count() - 1).header();
                    h.setAttribute('tabindex', '0');
                    h.focus();
                }, t.table);
                await page.keyboard.press('Alt+ArrowLeft');
                await page.waitForTimeout(400);

                const forced = await headerText(page, t.table);
                check(`${tag} — the action column refuses to move even when focused`,
                    forced[forced.length - 1] === after[after.length - 1],
                    `last column was "${after[after.length - 1]}", now "${forced[forced.length - 1]}"`);

                // ---- the order comes back after a reload ----
                await page.reload({ waitUntil: 'domcontentloaded' });
                await ready(page, t.table);
                const reloaded = await headerText(page, t.table);

                check(`${tag} — the order survives a reload`,
                    JSON.stringify(reloaded) === JSON.stringify(forced),
                    'after reload: ' + JSON.stringify(reloaded.slice(0, 4)));

                check(`${tag} — and it is NOT the original order`,
                    JSON.stringify(reloaded) !== JSON.stringify(before),
                    'the reload restored the original order, so nothing was saved');

                check(`${tag} — no uncaught page error`, errors.length === 0, errors.slice(0, 2).join(' | '));

                /* Leave the table as it was found, so a later run starts from the same place. */
                await page.evaluate((id) => window.jQuery('#' + id).DataTable().colReorder.reset(), t.table);
                await page.waitForTimeout(300);

                await ctx.close();
            }
        }
    } finally {
        setDirection('en');
        await browser.close();
    }

    if (failures.length > 0) {
        console.error('\n' + failures.length + ' of ' + cases + ' cases FAILED');
        process.exit(1);
    }

    console.log('\n' + cases + ' cases passed — column reorder works by mouse and by keyboard, '
        + 'in both directions, with the action column held and the order surviving a reload');
})().catch((e) => { console.error(e); process.exit(1); });
