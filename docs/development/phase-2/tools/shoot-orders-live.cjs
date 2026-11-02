/**
 * P2-04. SCREENSHOTS OF THE RUNNING APPLICATION, not of a preview harness.
 *
 * Every Phase 02 screenshot before this one came from docs/design-system/tools/render-preview.php
 * -- a Blade subset renderer with no routing, auth, tenancy or DataTables. That harness proved
 * what the markup and the stylesheets do, which is real but is not the application, and the
 * brief is explicit that a static preview is not runtime verification. With `composer install`
 * restored, `php artisan serve` works, so these are the real screens: a real login, a real
 * session, a real DataTables AJAX leg, a real side panel fetching a real authorised fragment.
 *
 * NO REAL CUSTOMER DATA. The tenant is database/seed-p2dev.php, every value in which is
 * invented. The P1-SEC-40 / #120 production dump is not on this branch.
 *
 * usage: node shoot-orders-live.cjs <baseUrl> <outDir>
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const OUT = process.argv[3] || path.join(__dirname, '..', 'screenshots', 'p2-04-orders');

const EMAIL = 'merchant@example.test';
const PASSWORD = 'password';

const VIEWPORTS = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'mobile', width: 390, height: 844 },
];

async function login(page) {
    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.click('button[type="submit"], input[type="submit"]'),
    ]);
    // The premise: a failed login leaves us on /login and every shot below would be of the
    // login form. Asserted rather than assumed.
    const url = page.url();
    if (url.includes('/login')) {
        throw new Error(`login failed -- still at ${url}. Screenshots would be of the login form.`);
    }
    return url;
}

async function setLocale(page, locale) {
    // The host's own language switch, so RTL comes from the application's real code path
    // (SetLocate + the rtl stylesheet) rather than from a CSS override we applied ourselves.
    await page.goto(`${BASE}/change-language/${locale}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
}

/**
 * SERVER STATE, NOT A CLIENT ATTRIBUTE.
 *
 * The first version of this file set `data-theme` on <html> from the browser and called the
 * result a dark-mode screenshot. The admin layout does not read that attribute: it picks
 * between style.css, style-dark.css, rtl-style.css and rtl-style-dark.css SERVER-SIDE from the
 * store's `cust_darklayout` and `SITE_RTL` settings (layouts/app.blade.php:116-124). So all four
 * "dark" shots were of the LIGHT stylesheet with an attribute nothing consulted -- the same
 * false-reading shape ledger finding F8 records twice, produced here a third time.
 *
 * This shells out to set-preview-state.php, which writes the two settings and flushes the
 * cache, and the page is then loaded fresh so the server renders the right stylesheet.
 */
function setServerState(storeId, theme, dir) {
    const repo = path.join(__dirname, '..', '..', '..', '..');
    execFileSync('php', [
        path.join(__dirname, 'set-preview-state.php'), String(storeId), theme, dir,
    ], {
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

    for (const vp of VIEWPORTS) {
        for (const locale of ['en', 'ar']) {
            for (const theme of ['light', 'dark']) {
                const ctx = await browser.newContext({
                    viewport: { width: vp.width, height: vp.height },
                    deviceScaleFactor: 1,
                });
                const page = await ctx.newPage();

                const errors = [];
                page.on('pageerror', (e) => errors.push(String(e)));
                page.on('response', (r) => {
                    if (r.status() >= 500) errors.push(`${r.status()} ${r.url()}`);
                });

                // Server state FIRST, then the session, then the page -- so the layout renders
                // the right stylesheet rather than being patched afterwards.
                setServerState(1, theme, locale === 'ar' ? 'rtl' : 'ltr');

                await login(page);
                await setLocale(page, locale);

                await page.goto(`${BASE}/order`, { waitUntil: 'networkidle' });
                await page.waitForTimeout(600);

                const tag = `${vp.name}-${locale}-${theme}`;

                // PREMISE, asserted per combination: the stylesheet the server actually sent.
                // Without this the file can report eight green shots of four configurations.
                const sheet = await page.evaluate(() => {
                    const el = document.getElementById('main-style-link');
                    return el ? (el.getAttribute('href') || '') : '(no #main-style-link)';
                });
                const wantDark = theme === 'dark';
                const wantRtl = locale === 'ar';
                const isDark = /style-dark\.css/.test(sheet);
                const isRtl = /rtl/i.test(sheet);   // `rtl-style-dark.css` AND `style-rtl.css` -- the layout spells it both ways
                const stateOk = isDark === wantDark && isRtl === wantRtl;
                const dir = await page.evaluate(() => document.documentElement.getAttribute('dir') || getComputedStyle(document.body).direction);

                // 1. the order list
                await page.screenshot({
                    path: path.join(OUT, `01-order-list-${tag}.png`),
                    fullPage: false,
                });

                // 2. the quick preview panel, OPENED AND FETCHED
                let panelShot = 'not-opened';
                const trigger = page.locator('[data-ss-panel-open="ss-order-preview-panel"]').first();
                if (await trigger.count() > 0) {
                    await trigger.click();
                    // Wait for the fetch to land: the body stops being the loading paragraph.
                    await page.waitForFunction(() => {
                        const b = document.querySelector('#ss-order-preview-panel [data-ss-panel-body]');
                        return b && !b.hasAttribute('aria-busy') && b.innerHTML.trim().length > 40;
                    }, { timeout: 15000 }).catch(() => {});
                    await page.waitForTimeout(500);
                    await page.screenshot({
                        path: path.join(OUT, `02-order-preview-panel-${tag}.png`),
                        fullPage: false,
                    });
                    panelShot = await page.evaluate(() => {
                        const b = document.querySelector('#ss-order-preview-panel [data-ss-panel-body]');
                        if (!b) return 'no-body';
                        if (b.querySelector('.ss-panel__error')) return 'error: ' + b.textContent.trim().slice(0, 120);
                        if (b.querySelector('.ss-order-preview')) return 'rendered';
                        return 'unknown: ' + b.textContent.trim().slice(0, 80);
                    });
                }

                // UNTRANSLATED STRINGS, counted in the Arabic runs. A Phase 02 string with no
                // ar.json entry renders as its English key, so the panel reads half-English to
                // an Arabic merchant. Caught in a screenshot the first time; counted now.
                let untranslated = [];
                if (locale === 'ar') {
                    untranslated = await page.evaluate(() => {
                        const body = document.querySelector('#ss-order-preview-panel [data-ss-panel-body]');
                        if (!body) return [];
                        const WATCH = ['Order preview', 'Placed', 'Fulfilment', 'Payment method',
                            'Shipping method', 'Recorded as', 'Not recorded', 'Pre-order', 'Items',
                            'Line total', 'Qty', 'Totals', 'Subtotal', 'Order value',
                            'Open full order', 'Partially paid'];
                        const panel = document.getElementById('ss-order-preview-panel');
                        const text = (panel ? panel.textContent : '') || '';
                        return WATCH.filter((w) => text.includes(w));
                    });
                }

                results.push({ tag, panel: panelShot, errors, sheet, stateOk, dir, untranslated });
                console.log(
                    `${tag.padEnd(26)} panel=${panelShot.padEnd(10)} dir=${String(dir).padEnd(4)}`
                    + ` sheet=${stateOk ? 'OK' : 'WRONG(' + sheet.split('/').pop() + ')'}`
                    + (untranslated.length ? `  UNTRANSLATED(${untranslated.length}): ${untranslated.slice(0, 4).join(', ')}` : '')
                    + (errors.length ? '  ERRORS: ' + errors.slice(0, 2).join(' | ') : '')
                );

                await ctx.close();
            }
        }
    }

    await browser.close();

    const bad = results.filter((r) =>
        r.panel !== 'rendered' || r.errors.length || !r.stateOk || r.untranslated.length);
    console.log(`\n${results.length} combinations shot; ${bad.length} with a problem.`);
    process.exit(bad.length ? 1 : 0);
})().catch((e) => { console.error('FATAL', e.message); process.exit(2); });
