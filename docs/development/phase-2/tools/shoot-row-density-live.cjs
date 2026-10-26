#!/usr/bin/env node
'use strict';
/**
 * P2-03-10 -- both row densities, in all four configurations, of the RUNNING application.
 *
 * NO REAL CUSTOMER DATA. The tenant is `database/seed-p2dev.php`, every value in which is
 * invented.
 *
 * WHY THE SERVER STATE IS FLIPPED AND NOT THE DOM. The admin layout chooses between style.css,
 * style-dark.css, rtl-style.css and rtl-style-dark.css SERVER-side from the store's
 * `cust_darklayout` and `SITE_RTL` settings, so setting `data-theme` from the browser produces
 * four screenshots of the light stylesheet. `set-preview-state.php` writes the settings the
 * layout actually reads; the language is switched separately through `/change-language`, because
 * direction and language are two different settings and the column-menu case in
 * `check-pinned-column-live.cjs` was order-dependent for exactly that reason.
 *
 * usage: node shoot-row-density-live.cjs [baseUrl] [storeId] [outDir]
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const STORE = process.argv[3] || '1';
const OUT = process.argv[4] || path.join(__dirname, '..', 'screenshots', 'p2-03-density');
const REPO = path.join(__dirname, '..', '..', '..', '..');
const EMAIL = 'merchant@example.test', PASSWORD = 'password';

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

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch();
    let shots = 0;

    try {
        for (const dir of ['ltr', 'rtl']) {
            for (const theme of ['light', 'dark']) {
                setServerState(theme, dir);
                /* A FRESH CONTEXT PER CONFIGURATION. The density is persisted in localStorage
                   beside the column set, so a reused profile would carry the previous
                   configuration's choice into this one's "comfortable" shot. */
                const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
                const page = await ctx.newPage();

                await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
                await page.fill('input[name="email"]', EMAIL);
                await page.fill('input[name="password"]', PASSWORD);
                await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
                await page.goto(BASE + '/change-language/' + (dir === 'rtl' ? 'ar' : 'en'),
                    { waitUntil: 'domcontentloaded' }).catch(() => {});

                for (const density of ['comfortable', 'compact']) {
                    await page.goto(BASE + '/product', { waitUntil: 'networkidle' });
                    await page.waitForSelector('#product-table tbody tr', { timeout: 20000 });
                    await page.waitForTimeout(600);

                    const now = await page.evaluate(() => {
                        const h = document.querySelector('[data-ss-density]');
                        return h ? h.getAttribute('data-ss-density') : null;
                    });
                    if (now !== density) {
                        await page.click('.dt-buttons .ss-density');
                        await page.waitForTimeout(500);
                    }

                    const rowH = await page.evaluate(() => {
                        const tr = document.querySelector('#product-table tbody tr');
                        return tr ? Math.round(tr.getBoundingClientRect().height) : null;
                    });

                    const name = density + '-' + dir + '-' + theme + '-1440.png';
                    await page.screenshot({ path: path.join(OUT, name), fullPage: false });
                    shots += 1;
                    console.log('  ' + name + '   row=' + rowH + 'px');
                }

                await ctx.close();
            }
        }
    } finally {
        setServerState('light', 'rtl');
        await browser.close();
    }

    console.log('\n' + shots + ' screenshots written to ' + OUT);
})().catch((e) => { console.error(e); process.exit(1); });
