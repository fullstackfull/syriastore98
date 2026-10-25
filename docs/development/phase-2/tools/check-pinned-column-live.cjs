#!/usr/bin/env node
/**
 * P2-03-1 -- the merchant list's column controls, driven in a real browser.
 * ============================================================================================
 *
 * TWO CLAIMS, AND NEITHER CAN BE MADE FROM A STATIC PREVIEW. The column-visibility menu is
 * built by DataTables at init from `buildDataTable`'s `colvis` config, and the pinned column's
 * colour is resolved by the browser from a custom property declared four elements above the
 * cell. A rendered fixture shows neither, so this file drives the running application.
 *
 * WHAT IT MEASURES, in order:
 *
 *   1. the colvis button EXISTS, the extension is really loaded, and the button has an explicit
 *      ARABIC accessible name rather than relying on `title`
 *   2. the menu OMITS the Action column and omits the hidden Id column   <- `ss-col-locked`
 *   3. toggling a menu entry hides that column
 *   4. the choice SURVIVES A RELOAD                                     <- it is a preference
 *   5. the actions cell is `position: sticky` in all four configurations
 *   6. the actions cell stays flush with the wrapper's trailing edge after scrolling to the far
 *      end -- the right in LTR, the LEFT in RTL
 *   7. the pinned cell is OPAQUE and its colour MATCHES THE CARD'S OWN PAINTED BACKGROUND
 *   8. EDIT IS CLICKABLE WITH THE TABLE AT ITS STARTING SCROLL POSITION
 *
 * CASE 7 IS THE ONE THIS FILE EXISTS FOR, and it is a ratchet, not a style preference. The
 * pinned cell needs an opaque background or the row scrolls through it, and the design system's
 * `--ss-surface` is the wrong colour in dark mode -- measured, the host card is `#292a33` there
 * while the token is `#263331`. The CSS therefore hard-codes the host's two card colours into
 * `--ss-dt-card-bg`, which is a COPY OF A VENDORED VALUE. This case reads the card's real
 * painted colour and the cell's, and fails if they stop matching, so a vendored theme update is
 * caught here instead of by a merchant looking at a band of the wrong grey.
 *
 * CASE 8 IS THE SLICE'S WHOLE POINT, stated the way a merchant would state it, and it is a
 * real click rather than a geometry assertion. Measured with `ss-col-pinned` removed, all
 * eight configurations report NO NAVIGATION -- 1440px included, where the table overflows
 * its wrapper by 155px in English and 73px in Arabic. The row actions were off-screen on a
 * DESKTOP, not only on a phone.
 *
 * CASE 6 IS WHY THE RTL LEGS ARE NOT DECORATION. `inset-inline-end` flips on its own, but the
 * `box-shadow` that draws the pinned edge has no logical form and needed a `[dir="rtl"]`
 * override. An LTR-only check would have passed over that entirely.
 *
 * WHAT IT DOES NOT CLAIM. It says nothing about whether the eleven columns are the RIGHT
 * eleven, or about row density -- the list's density is still open, and the ledger says so.
 * Pinning the actions does not make a 1168px table narrow; it makes its actions reachable.
 *
 * usage: node check-pinned-column-live.cjs [baseUrl] [storeId]
 * exit 0 = every case passed. Any failure prints the case and exits 1.
 *
 * Preconditions, stated rather than assumed: the p2dev instance is serving on `baseUrl`, seeded
 * by `database/seed-p2dev.php`, and `php` can reach `database/p2dev.sqlite` -- the theme and
 * direction are SERVER-side settings, so this flips them through `set-preview-state.php` the
 * way `measure-contrast-live.cjs` does. It restores light/RTL on the way out.
 */

'use strict';

const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const STORE = process.argv[3] || '1';
const REPO = path.join(__dirname, '..', '..', '..', '..');
const EMAIL = 'merchant@example.test';
const PASSWORD = 'password';

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

/* The signal that the colvis button is missing, so the four cases that need it are reported
   as failures and the pinned cases still run. Not an error condition -- a control-flow marker
   with a name, which reads better at the catch than a sentinel return value threaded through
   four awaits. */
class SkipMenuCases extends Error {}

function setServerState(theme, dir) {
    execFileSync('php', [path.join(__dirname, 'set-preview-state.php'), STORE, theme, dir], {
        cwd: REPO,
        env: Object.assign({}, process.env, {
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'),
            CACHE_STORE: 'file',
        }),
        stdio: 'pipe',
    });
}

async function login(page) {
    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.click('button[type="submit"]'),
    ]);
    if (page.url().includes('/login')) { throw new Error('login failed'); }
}

async function openList(page, listPath, tableId) {
    await page.goto(BASE + listPath, { waitUntil: 'networkidle' });
    await page.waitForFunction(
        (id) => !!(window.jQuery && window.jQuery.fn.dataTable
            && window.jQuery.fn.dataTable.isDataTable('#' + id)),
        tableId,
        { timeout: 20000 }
    );
    await page.waitForTimeout(400);
}

/* `rgb(a, b, c)` / `rgba(a, b, c, d)` -> [r, g, b, alpha]. Returns null for a keyword. */
function parseColor(c) {
    const m = String(c).match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/);
    if (!m) { return null; }
    return [Number(m[1]), Number(m[2]), Number(m[3]), m[4] === undefined ? 1 : Number(m[4])];
}

/* The colour an element EFFECTIVELY shows, walking up for the first ancestor that paints. The
   same routine the contrast probe uses, and it is needed here for the same reason: a cell that
   paints nothing reads as `rgba(0, 0, 0, 0)`, not as the colour a merchant sees. */
const PAINTED = `(function (el) {
    var n = el;
    while (n) {
        var p = String(getComputedStyle(n).backgroundColor)
            .match(/rgba?\\((\\d+),\\s*(\\d+),\\s*(\\d+)(?:,\\s*([\\d.]+))?\\)/);
        if (p && (p[4] === undefined || parseFloat(p[4]) > 0.99)) {
            return { who: n.tagName + '.' + String(n.className || '').trim().split(/\\s+/)
                .slice(0, 2).join('.'), color: getComputedStyle(n).backgroundColor };
        }
        n = n.parentElement;
    }
    return { who: '(none)', color: null };
})`;

/* Case 8, as its own function because it has to run in BOTH paths: when the pinned class is
   missing the per-configuration block skips the sticky and colour cases, and skipping THIS
   one too would mean the check went quiet on its most important claim at exactly the moment
   the claim stopped holding. Bite-tested: with `ss-col-pinned` removed this reported six
   failures and said nothing at all about reachability until it was lifted out of there. */
async function checkEditReachable(page, config) {
    /* ---- THE CASE THE SLICE IS FOR, and the only one stated as a merchant would
       state it: with the table at its natural starting scroll position, can Edit be
       clicked?

       A REAL CLICK, not a geometry assertion. Measured with `ss-col-pinned`
       removed, every one of the eight configurations reported NO NAVIGATION --
       1440px included, where the table overflows its wrapper by 155px in English
       and 73px in Arabic. So the row actions were off-screen on a DESKTOP until the
       merchant dragged sideways, which is not what "a narrow-viewport problem"
       would have predicted.

       THE ROW IS BROUGHT INTO VIEW VERTICALLY FIRST. At 390px the filter bar and
       toolbar put the first row near y=826 in an 844px viewport, and a click there
       lands on whatever is fixed to the bottom of the window. Diagnosed rather than
       worked around: `elementsFromPoint` returned the Laravel Debugbar
       (`position: fixed`, `z-index: 6000000`) above the Edit anchor in all four
       configurations, which is why the dev instance runs with
       `DEBUGBAR_ENABLED=false`. If this case ever fails with the anchor apparently
       in place, look for an overlay before looking at the CSS. ---- */
    await page.evaluate(() => {
        const row = document.querySelector('#product-table tbody tr');
        if (row) { row.scrollIntoView({ block: 'center' }); }
        document.getElementById('product-table')
            .closest('.table-responsive').scrollLeft = 0;
    });
    await page.waitForTimeout(300);

    /* Falls back to the last cell when the pinned class is absent, so the bite that
       removes it measures the SAME control rather than reporting "no anchor". */
    const target = await page.evaluate(() => {
        const td = document.querySelector('#product-table tbody tr td.ss-col-pinned')
            || document.querySelector('#product-table tbody tr td:last-child');
        const a = td && td.querySelector('a[href*="/edit"]');
        if (!a) { return null; }
        const r = a.getBoundingClientRect();
        return {
            x: Math.round(r.left + r.width / 2),
            y: Math.round(r.top + r.height / 2),
            href: a.getAttribute('href'),
        };
    });

    if (!target) {
        check(config + ': Edit is clickable without scrolling sideways', false,
            'no Edit anchor in the row-actions cell');
    } else {
        let landed = null;
        try {
            await page.mouse.click(target.x, target.y);
            await page.waitForURL(/\/product\/\d+\/edit/, { timeout: 6000 });
            landed = page.url();
        } catch (e) {
            landed = null;
        }
        check(config + ': Edit is clickable without scrolling sideways',
            landed !== null,
            'clicked (' + target.x + ',' + target.y + ') for ' + target.href
            + ' and the page did not navigate');
    }
}

async function run() {
    const browser = await chromium.launch();

    try {
        /* ---- Cases 1-4: the column-visibility menu. One configuration is enough: the menu's
           CONTENTS are built from the column config, which does not vary by theme. A FRESH
           CONTEXT matters, though -- `stateSave` keeps column visibility in localStorage, so a
           reused profile carries a previous run's hidden column into the baseline. That is
           exactly how an earlier reading of this table came back 59px narrow. ---- */
        setServerState('light', 'rtl');
        try {
            const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
            const page = await ctx.newPage();
            await login(page);
            await openList(page, '/product', 'product-table');

            const extensionLoaded = await page.evaluate(
                () => !!(window.jQuery && window.jQuery.fn.dataTable
                    && window.jQuery.fn.dataTable.ext.buttons.colvis)
            );
            check('colvis extension is loaded', extensionLoaded);

            const buttonCount = await page.locator('.dt-buttons .ss-colvis').count();
            check('the column-visibility button is rendered', buttonCount === 1, 'count=' + buttonCount);

            /* ITS ACCESSIBLE NAME, READ OFF THE LIVE ELEMENT. The button is an <a> wrapping an
               `aria-hidden` icon, so without an explicit name it has only `title` -- the
               last-resort source, and the one F38/F39 were about. DataTables Buttons 1.3.1
               ignores the `attr` config that would set this (measured: `aria-label` came back
               null), so `buildDataTable` sets it from `initComplete` and this case is what says
               that actually happened.

               ASSERTED AS ARABIC, not merely as non-empty. This leg runs in RTL, so a missing
               `Choose columns` entry in `ar.json` would leave an English name on an Arabic
               screen -- which is exactly ledger P2-03-7, found the same way. `non-empty` would
               have passed straight over it. */
            const colvisName = await page.evaluate(() => {
                const b = document.querySelector('.ss-colvis');
                return b ? { aria: b.getAttribute('aria-label'), pop: b.getAttribute('aria-haspopup') } : null;
            });
            check('the column-visibility button has an explicit accessible name',
                !!colvisName && !!colvisName.aria && colvisName.aria.trim() !== '',
                'aria-label=' + JSON.stringify(colvisName && colvisName.aria));
            check('that name is translated, not left in English on the Arabic screen',
                !!colvisName && /[\u0600-\u06FF]/.test(String(colvisName.aria || '')),
                'aria-label=' + JSON.stringify(colvisName && colvisName.aria));
            check('the button announces that it opens a menu',
                !!colvisName && colvisName.pop === 'true',
                'aria-haspopup=' + JSON.stringify(colvisName && colvisName.pop));

            const headersBefore = await page.locator('#product-table thead th:visible').count();
            check('the product list opens with its full column set', headersBefore >= 10,
                'headers=' + headersBefore);

            /* EVERY CASE BELOW NEEDS THE BUTTON, so its absence is reported once per
               dependent case rather than thrown. Bite-tested by removing `$colvisButtonConfig`
               from `buildDataTable`'s buttons array: the first version named the missing button
               and then died on a Playwright TimeoutError waiting for a menu that could never
               open, so the remaining four claims went unreported. */
            if (buttonCount !== 1) {
                for (const label of [
                    'the menu does not offer the actions column',
                    'the menu does not offer the hidden Id column',
                    'toggling a menu entry hides that column',
                    'the hidden column is still hidden after a reload',
                ]) {
                    check(label, false, 'the colvis button is absent, so this was not measurable');
                }
                await ctx.close();
                /* `break` out of the menu block and go on to the pinned cases, which do not
                   depend on the button and are the other half of the slice. */
                throw new SkipMenuCases();
            }

            await page.click('.dt-buttons .ss-colvis');
            await page.waitForSelector('.dt-button-collection', { timeout: 5000 });

            const menuItems = await page.$$eval(
                '.dt-button-collection .dt-button',
                (els) => els.map((e) => (e.textContent || '').trim())
            );

            /* `ss-col-locked` is the whole claim of these two cases: the actions column and the
               hidden primary key must not be offerable. Asserted by COUNT and by the absence of
               the action column's own heading, so a translation change cannot make it vacuous:
               the heading text is read off the table itself rather than written in here.

               READ WITH `$` AND A NULL CHECK, NOT `$eval`. Bite-tested by dropping
               `ss-col-pinned` from `buildDataTableColumn`, and the first version of this file
               THREW on the missing selector instead of reporting it -- so the regression was
               detected (a crash still exits non-zero) but the output named no case and said
               nothing about what had broken. A check whose failure mode is a stack trace makes
               the next person diagnose it twice. */
            const actionTh = await page.$('#product-table thead th.ss-col-pinned');
            check('the actions column carries ss-col-pinned', !!actionTh,
                'no th.ss-col-pinned in the product list header');
            const actionHeading = actionTh
                ? (await actionTh.evaluate((th) => (th.textContent || '').trim()))
                : null;
            check('the menu does not offer the actions column',
                actionHeading !== null && !menuItems.includes(actionHeading),
                'heading=' + JSON.stringify(actionHeading) + ' menu=' + JSON.stringify(menuItems));
            check('the menu does not offer the hidden Id column',
                menuItems.length === headersBefore - 1,
                'menu=' + menuItems.length + ' visibleHeaders=' + headersBefore);

            /* Toggle the LAST entry rather than the first: the first is the name column, which
               several other cases and the search box lean on. */
            await page.click('.dt-button-collection .dt-button:last-child');
            await page.waitForTimeout(300);
            await page.keyboard.press('Escape');
            await page.waitForTimeout(200);

            const headersAfter = await page.locator('#product-table thead th:visible').count();
            check('toggling a menu entry hides that column', headersAfter === headersBefore - 1,
                'before=' + headersBefore + ' after=' + headersAfter);

            await openList(page, '/product', 'product-table');
            const headersReloaded = await page.locator('#product-table thead th:visible').count();
            check('the hidden column is still hidden after a reload',
                headersReloaded === headersBefore - 1,
                'reloaded=' + headersReloaded + ' expected=' + (headersBefore - 1));

            await ctx.close();
        } catch (e) {
            if (!(e instanceof SkipMenuCases)) { throw e; }
        }

        /* ---- Cases 5-7: the pinned column, in all four configurations. ---- */
        for (const dir of ['ltr', 'rtl']) {
            for (const theme of ['light', 'dark']) {
                const config = dir + '/' + theme;
                setServerState(theme, dir);

                /* 390px: the narrowest width the design system claims, and the one where the
                   gap this slice closes is largest -- 320 of 1168 pixels visible. */
                const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
                const page = await ctx.newPage();
                await login(page);
                await page.goto(BASE + '/change-language/' + (dir === 'rtl' ? 'ar' : 'en'),
                    { waitUntil: 'domcontentloaded' }).catch(() => {});
                await openList(page, '/product', 'product-table');

                const sheet = await page.evaluate(() => {
                    const el = document.getElementById('main-style-link');
                    return el ? (el.getAttribute('href') || '').split('/').pop() : '(none)';
                });
                /* THE PREMISE. Without this the dark legs are the light stylesheet with a
                   different label -- the false reading `set-preview-state.php` was written
                   for. Asserted, not assumed. */
                check(config + ': the configuration under test really loaded',
                    theme === 'dark' ? /dark/.test(sheet) : !/dark/.test(sheet), 'sheet=' + sheet);

                const m = await page.evaluate(([painted, pageDir]) => {
                    /* eslint-disable no-new-func */
                    const paintedFn = new Function('return ' + painted)();
                    const table = document.getElementById('product-table');
                    const wrap = table.closest('.table-responsive');
                    const td = table.querySelector('tbody tr td.ss-col-pinned');
                    const th = table.querySelector('thead th.ss-col-pinned');
                    const card = table.closest('.card');
                    if (!td || !th) { return { missing: true }; }

                    /* In RTL a scroll container's `scrollLeft` runs NEGATIVE from 0 at the
                       right edge, so dragging to the far end means the most negative value,
                       not the most positive. Passing `dir` in is the only reason this closure
                       takes an argument at all. */
                    wrap.scrollLeft = pageDir === 'rtl' ? -wrap.scrollWidth : wrap.scrollWidth;
                    return new Promise((res) => requestAnimationFrame(() => requestAnimationFrame(() => {
                        const cell = td.getBoundingClientRect();
                        const head = th.getBoundingClientRect();
                        const box = wrap.getBoundingClientRect();
                        res({
                            missing: false,
                            tdPosition: getComputedStyle(td).position,
                            thPosition: getComputedStyle(th).position,
                            tdBg: getComputedStyle(td).backgroundColor,
                            cardBg: getComputedStyle(card).backgroundColor,
                            paintedBehindCard: paintedFn(card),
                            shadow: getComputedStyle(td).boxShadow,
                            scrolled: Math.round(Math.abs(wrap.scrollLeft)),
                            wrapLeft: Math.round(box.left),
                            wrapRight: Math.round(box.right),
                            cellLeft: Math.round(cell.left),
                            cellRight: Math.round(cell.right),
                            headLeft: Math.round(head.left),
                            tableWidth: Math.round(table.getBoundingClientRect().width),
                            wrapWidth: Math.round(box.width),
                        });
                    })));
                }, [PAINTED, dir]);

                if (m.missing) {
                    check(config + ': the actions column carries ss-col-pinned', false,
                        'no td/th.ss-col-pinned in the product list');
                    /* Still ask the merchant's question. The sticky and colour cases have
                       nothing to measure without the class, but "can Edit be clicked" is
                       exactly as meaningful -- and that is the reading worth having. */
                    await checkEditReachable(page, config);
                    await ctx.close();
                    continue;
                }

                check(config + ': the actions cell is sticky', m.tdPosition === 'sticky',
                    m.tdPosition);
                check(config + ': the actions heading is sticky too', m.thPosition === 'sticky',
                    m.thPosition);

                /* The table must actually be WIDER than its wrapper, or "it stayed at the edge"
                   is satisfied by a table that never scrolled. The premise for case 6. */
                check(config + ': the table is wider than its wrapper, so there is a scroll',
                    m.tableWidth > m.wrapWidth + 1,
                    'table=' + m.tableWidth + ' wrap=' + m.wrapWidth);
                check(config + ': the scroll really moved', m.scrolled > 100, 'scrollLeft=' + m.scrolled);

                /* Flush with the TRAILING edge: the right in LTR, the left in RTL. 2px of
                   tolerance for subpixel layout, not more -- a column that drifted a whole
                   cell width would pass a loose bound. */
                if (dir === 'rtl') {
                    check(config + ': the pinned column holds the left edge',
                        Math.abs(m.cellLeft - m.wrapLeft) <= 2,
                        'cell=' + m.cellLeft + ' wrap=' + m.wrapLeft);
                } else {
                    check(config + ': the pinned column holds the right edge',
                        Math.abs(m.cellRight - m.wrapRight) <= 2,
                        'cell=' + m.cellRight + ' wrap=' + m.wrapRight);
                }
                check(config + ': the heading stayed over its column',
                    Math.abs(m.headLeft - m.cellLeft) <= 2,
                    'head=' + m.headLeft + ' cell=' + m.cellLeft);

                /* CASE 7. Opaque first -- a transparent pinned cell is the defect, not a
                   near-miss -- then the colour match against the card's own paint. */
                const cellColor = parseColor(m.tdBg);
                check(config + ': the pinned cell is opaque',
                    !!cellColor && cellColor[3] > 0.99, 'tdBg=' + m.tdBg);

                const cardColor = parseColor(m.cardBg);
                const same = !!cellColor && !!cardColor
                    && cellColor[0] === cardColor[0]
                    && cellColor[1] === cardColor[1]
                    && cellColor[2] === cardColor[2];
                check(config + ': the pinned cell matches the card it sits on', same,
                    'cell=' + m.tdBg + ' card=' + m.cardBg
                    + ' (--ss-dt-card-bg is a copy of a vendored colour; if the theme changed'
                    + ' it, update the two values in store-syria-ds.css)');

                /* The pinned edge, and which side it is on. `inset 1px` is the inline start in
                   LTR; RTL needs `inset -1px`, which is the one rule in that section that
                   could not be written logically. */
                const negative = /(^|\s)-1px/.test(m.shadow) || /\s-1px\s/.test(m.shadow);
                check(config + ': the pinned edge is drawn on the scrolled-under side',
                    dir === 'rtl' ? negative : !negative, 'shadow=' + m.shadow);

                await checkEditReachable(page, config);

                await ctx.close();
            }
        }
    } finally {
        setServerState('light', 'rtl');
        await browser.close();
    }

    console.log('');
    if (failures.length) {
        console.error(failures.length + ' of ' + cases + ' cases FAILED');
        failures.forEach((f) => console.error('  - ' + f));
        process.exit(1);
    }
    console.log(cases + ' of ' + cases + ' cases passed');
}

run().catch((e) => { console.error(e); process.exit(1); });
