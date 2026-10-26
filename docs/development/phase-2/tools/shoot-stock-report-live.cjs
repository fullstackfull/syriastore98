#!/usr/bin/env node
/**
 * P2-05-3 -- the stock report's screenshots, and the three numbers that justify them.
 * ====================================================================================
 *
 * The committed evidence in `docs/development/phase-2/evidence/p2-05-3/` is produced by this
 * file, so it can be regenerated rather than taken on trust. Ledger F106 records three measured
 * defects on this one screen; this shoots the AFTER for all three:
 *
 *   - THE STATUS COLUMN. It was empty on the low-stock and most-stocked tabs and a raw
 *     Bootstrap pill on the out-of-stock one, because the partials gated on
 *     `stock_order_status` -- the merchant's BACKORDER POLICY, not a stock level. Had that
 *     column held `allow`, the LOW-STOCK report would have rendered a badge reading "In
 *     stock". The shots now show a `DsBadge` whose tone comes from
 *     `ProductDataTable::trackedStockState()`, the same predicate the catalogue uses.
 *   - THE ROW ACTIONS. Their `title` sat on the inner `<i>`, where it cannot name the parent
 *     link, and Bootstrap then moved it to `data-bs-original-title` and blanked it: 5 links,
 *     accessible name empty on all 5. This prints the computed accessible name per row.
 *   - THE ACTIVE TAB PILL, measured at 2.59:1 in all four configurations against the 4.5:1
 *     text minimum. This prints the ratio it now reports, per configuration, so the number
 *     under the picture comes from the same run as the picture.
 *
 * WHY IT SHOOTS TWO TABS. The status column's defect was per-tab -- it rendered on exactly one
 * of three -- so a single tab's screenshot is not evidence that the column works. The
 * out-of-stock tab is shot as well because it is the one that DID render something before, and
 * so the one where a regression would be invisible in the low-stock shot.
 *
 * usage: node shoot-stock-report-live.cjs
 * Preconditions: the p2dev instance is serving on 127.0.0.1:8899, seeded by
 * `database/seed-p2dev.php` (invented data only -- no real customer, order or credential), and
 * `php` can reach `database/p2dev.sqlite`. Theme and direction are SERVER-side settings, so this
 * flips them through `set-preview-state.php`; it restores light/RTL on the way out.
 */

'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const BASE = 'http://127.0.0.1:8899';
const REPO = '/home/user/syriastore98';
const OUT = REPO + '/docs/development/phase-2/evidence/p2-05-3';

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
    if (p.url().includes('/login')) throw new Error('login failed');
}
async function openReport(p, tableId) {
    await p.waitForFunction((i) => !!(window.jQuery && window.jQuery.fn.dataTable
        && window.jQuery.fn.dataTable.isDataTable('#' + i)), tableId, { timeout: 25000 });
    await p.waitForTimeout(600);
}

/* The accessible name of a link, computed the way a screen reader resolves it: aria-label wins,
   then the trimmed text content. The icon carries aria-hidden so it must contribute nothing --
   which is exactly what the F106 defect got wrong by putting `title` on the icon instead. */
function readRows(tableId) {
    const t = document.getElementById(tableId);
    if (!t) return { error: 'no table ' + tableId };
    const rows = Array.from(t.querySelectorAll('tbody tr')).filter((r) => !r.querySelector('td.dataTables_empty'));
    return {
        rowCount: rows.length,
        badges: rows.map((r) => {
            const b = r.querySelector('.ss-badge');
            return b ? { cls: b.className, label: b.textContent.trim(), title: b.getAttribute('title') || '' } : null;
        }),
        actionNames: rows.map((r) => {
            const a = r.querySelector('td:last-child a, td:last-child button');
            if (!a) return null;
            const label = a.getAttribute('aria-label');
            return { name: (label && label.trim()) || a.textContent.trim(), cls: a.className };
        }),
    };
}

/* The active pill's ratio, read off the real painted page rather than from the stylesheet, so a
   cascade order that loses to the host theme shows up as a number instead of as an assumption. */
function readActivePill() {
    function parse(c) {
        const m = String(c).match(/rgba?\(([^)]+)\)/);
        if (!m) return null;
        const p = m[1].split(',').map((x) => parseFloat(x.trim()));
        return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
    }
    function over(fg, bg) {
        const a = fg.a;
        return { r: fg.r * a + bg.r * (1 - a), g: fg.g * a + bg.g * (1 - a), b: fg.b * a + bg.b * (1 - a), a: 1 };
    }
    function lum(c) {
        const s = [c.r, c.g, c.b].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
        return 0.2126 * s[0] + 0.7152 * s[1] + 0.0722 * s[2];
    }
    function effBg(el) {
        let node = el, acc = { r: 255, g: 255, b: 255, a: 1 };
        const stack = [];
        while (node && node.nodeType === 1) {
            const bg = parse(getComputedStyle(node).backgroundColor);
            if (bg && bg.a > 0) stack.push(bg);
            if (bg && bg.a === 1) break;
            node = node.parentElement;
        }
        for (let i = stack.length - 1; i >= 0; i--) acc = over(stack[i], acc);
        return acc;
    }
    const el = document.querySelector('.ss-tabrail .nav-link.active');
    if (!el) return { error: 'no active pill' };
    const fg = parse(getComputedStyle(el).color);
    const bg = effBg(el);
    const comp = over(fg, bg);
    const l1 = lum(comp), l2 = lum(bg);
    return {
        ratio: Math.round(((Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05)) * 100) / 100,
        color: getComputedStyle(el).color,
        bg: 'rgb(' + Math.round(bg.r) + ',' + Math.round(bg.g) + ',' + Math.round(bg.b) + ')',
    };
}

(async () => {
    const browser = await chromium.launch();
    const report = [];
    for (const dir of ['ltr', 'rtl']) {
        for (const theme of ['light', 'dark']) {
            st(theme, dir);
            for (const vw of [1440, 390]) {
                const ctx = await browser.newContext({ viewport: { width: vw, height: vw === 390 ? 844 : 1000 } });
                const page = await ctx.newPage();
                await login(page);
                await page.goto(BASE + '/change-language/' + (dir === 'rtl' ? 'ar' : 'en'),
                    { waitUntil: 'domcontentloaded' }).catch(() => {});
                await page.goto(BASE + '/stock-report', { waitUntil: 'networkidle' });
                await openReport(page, 'low-stock-table');

                const low = await page.evaluate(readRows, 'low-stock-table');
                const pill = await page.evaluate(readActivePill);
                await page.screenshot({ path: OUT + '/stock-report-low-' + dir + '-' + theme + '-' + vw + '.png', fullPage: false });
                report.push({ dir, theme, vw, tab: 'low', pill, rows: low });

                /* The out-of-stock tab only at 1440: it is shot to prove the column still
                   renders on the tab that previously rendered something, not to document
                   responsive behaviour, which the low-stock pair at 390 already covers. */
                if (vw === 1440) {
                    await page.click('#pills-out-of-stock-tab');
                    await page.waitForTimeout(900);
                    const out = await page.evaluate(readRows, 'out-of-stock-table');
                    await page.screenshot({ path: OUT + '/stock-report-out-' + dir + '-' + theme + '-' + vw + '.png', fullPage: false });
                    report.push({ dir, theme, vw, tab: 'out', pill: await page.evaluate(readActivePill), rows: out });
                }
                await ctx.close();
            }
        }
    }
    st('light', 'rtl');
    await browser.close();

    console.log('config        tab   pill   rows  badge tones                      action names named');
    let fails = 0;
    for (const r of report) {
        const tones = (r.rows.badges || []).map((b) => (b ? b.cls.replace(/^.*ss-badge--/, '') : 'NONE')).join(',');
        const named = (r.rows.actionNames || []).filter((a) => a && a.name).length;
        const total = (r.rows.actionNames || []).length;
        const ratio = r.pill && r.pill.ratio ? r.pill.ratio : 0;
        if (ratio < 4.5) fails++;
        if (!total || named !== total) fails++;
        if ((r.rows.badges || []).some((b) => !b)) fails++;
        console.log(
            (r.dir + '/' + r.theme + '/' + r.vw).padEnd(14),
            r.tab.padEnd(5),
            String(ratio).padEnd(6),
            String(r.rows.rowCount).padEnd(5),
            tones.padEnd(32),
            named + '/' + total
        );
    }
    console.log(fails === 0 ? 'OK: every configuration clears AA, every row carries a badge, every action is named.'
                            : 'FAILURES: ' + fails);
    process.exit(fails === 0 ? 0 : 1);
})();
