/**
 * P2-06-1. THE ONBOARDING CHECKLIST, DRIVEN IN THE RUNNING APPLICATION.
 *
 * WHY IT EXISTS. The checklist was built, measured, and deliberately NOT shipped: under
 * `dir="rtl"` the preview harness painted each step's mark TWICE, once in its own box and once
 * at the far inline-end of the row. The investigation exhausted what that harness could answer
 * -- one mark in the DOM, ONE client rect at the correct position, no box-shadow, transform,
 * filter, reflection or pseudo-element, and hiding the mark removed BOTH circles -- and named
 * the remaining candidate as the harness's own stand-in RTL layout. Only the real application
 * could settle it.
 *
 * SO THE COUNT IS TAKEN FROM PAINTED GEOMETRY, NOT FROM THE DOM. Counting elements is exactly
 * what could not see the defect: the DOM always had one. Each row is read through
 * `getClientRects()`, and a row reporting anything but one rect for its mark fails.
 *
 * usage: node shoot-dashboard-live.cjs <baseUrl> <outDir>
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const OUT = process.argv[3] || path.join(__dirname, '..', 'screenshots', 'p2-06-onboarding');
const EMAIL = 'merchant@example.test';
const PASSWORD = 'password';

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

(async () => {
    const browser = await chromium.launch();
    const results = [];

    for (const vp of [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844 }]) {
        for (const locale of ['en', 'ar']) {
            for (const theme of ['light', 'dark']) {
                setServerState(theme, locale === 'ar' ? 'rtl' : 'ltr');

                const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
                const page = await ctx.newPage();
                const errors = [];
                page.on('pageerror', (e) => errors.push(String(e)));
                /*
                 * `top-brand-category-chart` IS EXCLUDED, AND IT IS NOT A PRODUCT DEFECT.
                 * HomeController::top_brand_category_chart builds raw SQL using MySQL-only
                 * functions -- SUBSTRING_INDEX and CURDATE -- which SQLite does not have
                 * (verified directly: "no such function: SUBSTRING_INDEX"). Production is MySQL,
                 * where the query runs. This harness uses a SQLite file, so that one AJAX call
                 * 500s HERE and only here; the dashboard page itself answers 200.
                 *
                 * Named explicitly rather than filtered by a pattern, so a 500 on any OTHER
                 * endpoint still fails the run. An unexplained exclusion list is how a real
                 * error gets quietly accepted.
                 */
                const SQLITE_ONLY_500 = ['/top-brand-category-chart'];
                page.on('response', (r) => {
                    if (r.status() < 500) { return; }
                    if (SQLITE_ONLY_500.some((u) => r.url().includes(u))) { return; }
                    errors.push(`${r.status()} ${r.url()}`);
                });

                await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
                await page.fill('input[name="email"]', EMAIL);
                await page.fill('input[name="password"]', PASSWORD);
                await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
                if (page.url().includes('/login')) throw new Error('login failed');

                await page.goto(`${BASE}/change-language/${locale}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
                await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
                await page.waitForTimeout(500);

                const tag = `${vp.name}-${locale}-${theme}`;

                const setup = await page.evaluate(() => {
                    const root = document.querySelector('.ss-setup');
                    if (!root) return { present: false };

                    const rows = [...root.querySelectorAll('.ss-setup__list > li')];
                    const marks = rows.map((li) => {
                        const m = li.querySelector('.ss-setup__mark');
                        if (!m) return { rects: 0 };
                        const rects = [...m.getClientRects()];
                        const r = rects[0];
                        return {
                            rects: rects.length,
                            x: r ? Math.round(r.x) : null,
                            w: r ? Math.round(r.width) : null,
                        };
                    });

                    return {
                        present: true,
                        dir: getComputedStyle(root).direction,
                        rows: rows.length,
                        domMarks: root.querySelectorAll('.ss-setup__mark').length,
                        marks,
                        count: (root.querySelector('.ss-setup__count') || {}).textContent,
                    };
                });

                await page.screenshot({ path: path.join(OUT, `01-dashboard-${tag}.png`), fullPage: false });

                const setupEl = await page.locator('.ss-setup').first();
                if (await setupEl.count() > 0) {
                    await setupEl.screenshot({ path: path.join(OUT, `02-setup-checklist-${tag}.png`) }).catch(() => {});
                }

                // THE ASSERTION. Exactly one PAINTED rect per row, and the DOM count agreeing.
                const bad = setup.present
                    ? setup.marks.filter((m) => m.rects !== 1).length
                    : 0;
                const ok = setup.present && bad === 0 && setup.domMarks === setup.rows;

                results.push({ tag, setup, ok, errors });
                console.log(
                    `${tag.padEnd(22)} checklist=${setup.present ? 'shown' : 'ABSENT'}`
                    + (setup.present
                        ? ` dir=${setup.dir} rows=${setup.rows} domMarks=${setup.domMarks}`
                        + ` paintedRects=[${setup.marks.map((m) => m.rects).join(',')}]`
                        + ` ${ok ? 'OK' : '*** DUPLICATE MARK ***'}`
                        : '')
                    + (errors.length ? '  ERRORS: ' + errors.slice(0, 2).join(' | ') : '')
                );

                await ctx.close();
            }
        }
    }

    await browser.close();
    const bad = results.filter((r) => !r.ok || r.errors.length);
    console.log(`\n${results.length} combinations; ${bad.length} with a problem.`);
    process.exit(bad.length ? 1 : 0);
})().catch((e) => { console.error('FATAL', e.message); process.exit(2); });
