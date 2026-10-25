/**
 * P2-06-3. The notification feed and the header bell, driven in the running application.
 * ============================================================================================
 *
 * Same posture as the other live harnesses: a real login, a real session, the host's own
 * server-side theme and direction switch, and eight combinations
 * (desktop/mobile x en/ar x light/dark).
 *
 * WHAT IT FAILS ON, beyond taking a picture:
 *
 *   1. the stylesheet the SERVER sent is the one the combination asked for. A client attribute
 *      is not the theme -- four of eight "dark" screenshots in this phase were of the light
 *      stylesheet before that was asserted;
 *   2. the feed actually rendered rows, so a screenshot of an empty state is not filed as
 *      evidence of a feed;
 *   3. the unread BADGE on the header bell is visible and reads the right number. The badge is
 *      filled by a fetch, so a 403, a wrong route or a blocked script leaves it hidden -- and
 *      hidden is exactly what "nothing unread" looks like, which is why the number is asserted
 *      rather than the element's presence;
 *   4. the unread marker is on the correct SIDE. It is drawn with
 *      `border-inline-start`, so RTL should put it on the right; a physical `border-left` would
 *      pass every other check in this file and be wrong on every Arabic row. Read off the
 *      painted box, not off the stylesheet;
 *   5. no Phase 02 string renders in English inside the Arabic runs;
 *   6. no uncaught page error, and no 500 anywhere but the one known MySQL-only dashboard chart
 *      (ledger blocker B5), which is excluded BY NAME so a 500 elsewhere still fails the run.
 *
 * usage: node shoot-notifications-live.cjs [baseUrl] [outDir]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const OUT = process.argv[3] || path.join(__dirname, '..', 'screenshots', 'p2-06-notifications');

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
                page.on('response', (r) => {
                    if (r.status() >= 500 && !r.url().includes('/top-brand-category-chart')) {
                        errors.push(`${r.status()} ${r.url()}`);
                    }
                });

                await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
                await page.fill('input[name="email"]', 'merchant@example.test');
                await page.fill('input[name="password"]', 'password');
                await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);

                await page.goto(`${BASE}/change-language/${locale}`, { waitUntil: 'domcontentloaded' }).catch(() => {});

                // ---------------------------------------------------- the bell, on another screen
                await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });

                // The badge is filled by a fetch, so wait for it rather than for the page.
                await page.waitForFunction(() => {
                    const b = document.querySelector('[data-ss-notification-count]');
                    return b && !b.hidden && (b.textContent || '').trim() !== '';
                }, { timeout: 10000 }).catch(() => {});

                const badge = await page.evaluate(() => {
                    const b = document.querySelector('[data-ss-notification-count]');
                    if (!b) { return 'absent'; }
                    if (b.hidden) { return 'hidden'; }
                    return (b.textContent || '').trim() || 'empty';
                });

                const tag = `${vp.name}-${locale}-${theme}`;

                const sheet = await page.evaluate(() => {
                    const el = document.getElementById('main-style-link');
                    return el ? (el.getAttribute('href') || '').split('/').pop() : '(none)';
                });
                const stateOk = /style-dark\.css/.test(sheet) === (theme === 'dark')
                    && /rtl/i.test(sheet) === (locale === 'ar');

                await page.screenshot({ path: path.join(OUT, `01-bell-badge-${tag}.png`) });

                // ---------------------------------------------------- the feed
                await page.goto(`${BASE}/notifications`, { waitUntil: 'networkidle' });
                await page.waitForTimeout(300);

                const feed = await page.evaluate(() => {
                    const items = Array.prototype.slice.call(document.querySelectorAll('.ss-feed__item'));
                    const unreadRow = document.querySelector('.ss-feed__item--unread');

                    let markerSide = 'none';
                    if (unreadRow) {
                        const cs = getComputedStyle(unreadRow);
                        // The PAINTED box, not the declaration: `border-inline-start` resolves to
                        // left in LTR and right in RTL, and this reads whichever one got the
                        // non-transparent colour.
                        const left = parseFloat(cs.borderLeftWidth) || 0;
                        const right = parseFloat(cs.borderRightWidth) || 0;
                        const leftColoured = left >= 2 && cs.borderLeftColor !== 'rgba(0, 0, 0, 0)';
                        const rightColoured = right >= 2 && cs.borderRightColor !== 'rgba(0, 0, 0, 0)';
                        markerSide = leftColoured && rightColoured ? 'both'
                            : leftColoured ? 'left' : rightColoured ? 'right' : 'none';
                    }

                    return {
                        rows: items.length,
                        unread: document.querySelectorAll('.ss-feed__item--unread').length,
                        badges: document.querySelectorAll('.ss-feed__item--unread .ss-badge').length,
                        empty: !!document.querySelector('.ss-empty'),
                        markerSide: markerSide,
                    };
                });

                await page.screenshot({ path: path.join(OUT, `02-notification-feed-${tag}.png`), fullPage: true });

                // ---------------------------------------------------- the panel from a feed row
                let panel = 'not-opened';
                const trigger = page.locator('.ss-feed [data-ss-panel-open="ss-order-preview-panel"]').first();
                if (await trigger.count() > 0) {
                    await trigger.click();
                    await page.waitForFunction(() => {
                        const b = document.querySelector('#ss-order-preview-panel [data-ss-panel-body]');
                        return b && !b.hasAttribute('aria-busy') && b.innerHTML.trim().length > 40;
                    }, { timeout: 15000 }).catch(() => {});
                    await page.waitForTimeout(400);
                    await page.screenshot({ path: path.join(OUT, `03-notification-order-panel-${tag}.png`) });
                    panel = await page.evaluate(() => {
                        const b = document.querySelector('#ss-order-preview-panel [data-ss-panel-body]');
                        if (!b) { return 'no-body'; }
                        if (b.querySelector('.ss-panel__error')) { return 'error: ' + b.textContent.trim().slice(0, 80); }
                        if (b.querySelector('.ss-order-preview')) { return 'rendered'; }
                        return 'unknown';
                    });
                }

                // ---------------------------------------------------- untranslated Phase 02 strings
                let untranslated = [];
                if (locale === 'ar') {
                    untranslated = await page.evaluate(() => {
                        const text = document.body.textContent || '';
                        return ['Notifications', 'Mark all as read', 'Mark as read', 'Unread',
                            'View order', 'No notifications yet', 'Nothing unread',
                            'No notifications you can see']
                            .filter((w) => text.includes(w));
                    });
                }

                const wantSide = locale === 'ar' ? 'right' : 'left';
                const sideOk = feed.empty || feed.markerSide === wantSide;
                const feedOk = feed.rows > 0 && feed.unread > 0 && feed.badges === feed.unread;
                const badgeOk = badge === '2';

                const ok = stateOk && feedOk && badgeOk && sideOk
                    && panel === 'rendered' && !errors.length && !untranslated.length;

                results.push({ tag, ok });
                console.log(
                    `${tag.padEnd(22)} bell=${String(badge).padEnd(7)} rows=${feed.rows} unread=${feed.unread}`
                    + ` marker=${feed.markerSide.padEnd(5)}(want ${wantSide}) panel=${panel.padEnd(9)}`
                    + ` sheet=${stateOk ? 'OK' : 'WRONG(' + sheet + ')'}`
                    + (untranslated.length ? `  UNTRANSLATED(${untranslated.length}): ${untranslated.slice(0, 4).join(', ')}` : '')
                    + (errors.length ? '  ERRORS: ' + errors.slice(0, 2).join(' | ') : '')
                );

                await ctx.close();
            }
        }
    }

    await browser.close();
    const bad = results.filter((r) => !r.ok);
    console.log(`\n${results.length} combinations; ${bad.length} with a problem.`);
    process.exit(bad.length ? 1 : 0);
})().catch((e) => { console.error('FATAL', e.message); process.exit(2); });
