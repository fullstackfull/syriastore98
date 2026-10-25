#!/usr/bin/env node
/**
 * P2-03-1 -- the product list's eight screenshots, and the measurement that goes under them.
 * ============================================================================================
 *
 * The committed evidence in `docs/development/phase-2/evidence/p2-03-1/` is produced by this
 * file, so it can be regenerated rather than taken on trust. It shoots the product list in all
 * eight configurations (LTR/RTL x light/dark x 1440/390) and prints, for each, the table's
 * width, its wrapper's width, how much is hidden behind the horizontal scroll, and whether a
 * REAL CLICK on Edit navigates with the table at its starting scroll position.
 *
 * THE CLICK IS THE POINT, not the screenshots. Run it with `ss-col-pinned` removed from
 * `buildDataTableColumn` and all eight configurations report NO NAVIGATION -- 1440 included,
 * where the table overflows its wrapper by 155px in English and 73px in Arabic. That is the
 * before-and-after this slice is justified by, and it is a different claim from "it looks
 * better".
 *
 * TWO THINGS IT DOES ON PURPOSE, both of which were defects in earlier versions of it:
 *
 *   - IT BRINGS THE ROW INTO VIEW VERTICALLY BEFORE CLICKING. At 390px the filter bar and
 *     toolbar put the first row near y=826 in an 844px viewport, and a click there lands on
 *     whatever is fixed to the bottom of the window. `elementsFromPoint` named the culprit:
 *     the Laravel Debugbar, `position: fixed`, `z-index: 6000000`, sitting above the Edit
 *     anchor in all four configurations. The dev instance is now run with
 *     `DEBUGBAR_ENABLED=false`, which also keeps it out of the screenshots.
 *   - IT MEASURES AFTER A FRAME, not in the same evaluate that resets the scroll. Sticky
 *     positions re-resolve on the next frame, so the first version read pre-reflow rectangles
 *     and `elementFromPoint` disagreed with every one of them.
 *
 * It falls back to the row's last cell when the pinned class is absent, so a bite run measures
 * the same control rather than reporting "no anchor found".
 *
 * usage: node shoot-product-list-live.cjs
 * Preconditions: the p2dev instance is serving on 127.0.0.1:8899, seeded by
 * `database/seed-p2dev.php` (invented data only -- no real customer, order or credential), and
 * `php` can reach `database/p2dev.sqlite`. Theme and direction are SERVER-side settings, so this
 * flips them through `set-preview-state.php`; it restores light/RTL on the way out.
 */

'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8899';
const REPO = '/home/user/syriastore98';
const OUT = REPO + '/docs/development/phase-2/evidence/p2-03-1';

function st(theme, dir) {
    execFileSync('php', [REPO + '/docs/development/phase-2/tools/set-preview-state.php', '1', theme, dir],
        { cwd: REPO, env: Object.assign({}, process.env, {
            DB_CONNECTION: 'sqlite', DB_DATABASE: REPO + '/database/p2dev.sqlite', CACHE_STORE: 'file',
        }), stdio: 'pipe' });
}
async function login(p) {
    await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await p.fill('input[name="email"]', 'merchant@example.test');
    await p.fill('input[name="password"]', 'password');
    await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
}
async function openList(p, lp, id) {
    await p.goto(BASE + lp, { waitUntil: 'networkidle' });
    await p.waitForFunction((i) => !!(window.jQuery && window.jQuery.fn.dataTable
        && window.jQuery.fn.dataTable.isDataTable('#' + i)), id, { timeout: 20000 });
    await p.waitForTimeout(500);
}

(async () => {
    const browser = await chromium.launch();
    const report = [];
    for (const dir of ['ltr', 'rtl']) {
        for (const theme of ['light', 'dark']) {
            st(theme, dir);
            for (const vw of [1440, 390]) {
                const ctx = await browser.newContext({ viewport: { width: vw, height: vw === 390 ? 844 : 900 } });
                const page = await ctx.newPage();
                await login(page);
                await page.goto(BASE + '/change-language/' + (dir === 'rtl' ? 'ar' : 'en'),
                    { waitUntil: 'domcontentloaded' }).catch(() => {});
                await openList(page, '/product', 'product-table');

                /* Reachability WITHOUT scrolling: is the Edit control inside the wrapper's
                   visible box at scrollLeft 0, and does an actual click land on it? */
                /* Reset the scroll and let sticky re-resolve BEFORE measuring: doing both in
                   one evaluate gave rects from the pre-reflow layout, and elementFromPoint then
                   disagreed with them. */
                await page.evaluate(() => {
                    document.getElementById('product-table')
                        .closest('.table-responsive').scrollLeft = 0;
                });
                /* Bring the first row into view. At 390px the filter bar and toolbar push the
                   table's first row to y~826 in an 844px viewport -- below the fold -- so a
                   click at its centre lands wherever the browser chrome or a dev overlay
                   happens to be. Diagnosed, not assumed: elementsFromPoint at that coordinate
                   returned the Laravel Debugbar (position: fixed, z-index 6000000) above the
                   Edit anchor in all four configurations, which is why the dev instance now
                   runs with DEBUGBAR_ENABLED=false. */
                await page.evaluate(() => {
                    const row = document.querySelector('#product-table tbody tr');
                    if (row) { row.scrollIntoView({ block: 'center' }); }
                });
                await page.waitForTimeout(250);
                const m = await page.evaluate(() => {
                    const t = document.getElementById('product-table');
                    const wrap = t.closest('.table-responsive');
                    const td = t.querySelector('tbody tr td.ss-col-pinned') || t.querySelector('tbody tr td:last-child');
                    const edit = td ? td.querySelector('a[href*="/product/"][href*="/edit"], a.ss-btn--ghost[href*="edit"]') : null;
                    const anchor = edit || (td ? td.querySelector('a') : null);
                    const wb = wrap.getBoundingClientRect();
                    const ab = anchor ? anchor.getBoundingClientRect() : null;
                    return {
                        tableWidth: Math.round(t.getBoundingClientRect().width),
                        wrapWidth: Math.round(wb.width),
                        overflowPx: Math.round(t.getBoundingClientRect().width - wb.width),
                        editFound: !!anchor,
                        editHref: anchor ? anchor.getAttribute('href') : null,
                        editInsideWrapper: !!ab && ab.left >= wb.left - 1 && ab.right <= wb.right + 1,
                        editCentre: ab ? { x: Math.round(ab.left + ab.width / 2), y: Math.round(ab.top + ab.height / 2) } : null,
                    };
                });

                /* THE REAL CLICK, at scrollLeft 0 -- the only unambiguous form of
                   "reachable". A geometry assertion can be satisfied by a control sitting under
                   something else; a navigation cannot. */
                let clickNavigated = null;
                if (m.editCentre) {
                    const before = page.url();
                    try {
                        await page.mouse.click(m.editCentre.x, m.editCentre.y);
                        await page.waitForURL(/\/product\/\d+\/edit/, { timeout: 6000 });
                        clickNavigated = page.url().replace(BASE, '');
                    } catch (e) {
                        clickNavigated = 'NO NAVIGATION (still ' + page.url().replace(BASE, '') + ')';
                    }
                    if (page.url() !== before) { await openList(page, '/product', 'product-table'); }
                }

                const name = `product-list-${dir}-${theme}-${vw}.png`;
                await page.screenshot({ path: path.join(OUT, name), fullPage: false });
                report.push({ config: `${dir}/${theme}/${vw}`, ...m, topmostAtEditCentre: clickNavigated, shot: name });
                await ctx.close();
            }
        }
    }
    st('light', 'rtl');
    await browser.close();
    for (const r of report) {
        console.log(`${r.config.padEnd(18)} table=${String(r.tableWidth).padStart(5)} wrap=${String(r.wrapWidth).padStart(5)}`
            + ` overflow=${String(r.overflowPx).padStart(4)}px editReachableAtScroll0=${String(r.editInsideWrapper).padEnd(5)}`
            + ` click=${r.topmostAtEditCentre}`);
    }
})().catch((e) => { console.error(e); process.exit(1); });
