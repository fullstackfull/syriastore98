/**
 * P2-03-6. The product quick preview, driven in the running application.
 * Same posture as shoot-orders-live.cjs: a real login, a real list, a real fetch.
 *
 * usage: node shoot-products-live.cjs <baseUrl> <outDir>
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const OUT = process.argv[3] || path.join(__dirname, '..', 'screenshots', 'p2-03-products');

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
                await page.goto(`${BASE}/product`, { waitUntil: 'networkidle' });

                const tag = `${vp.name}-${locale}-${theme}`;
                const sheet = await page.evaluate(() => {
                    const el = document.getElementById('main-style-link');
                    return el ? (el.getAttribute('href') || '').split('/').pop() : '(none)';
                });
                const wantDark = theme === 'dark';
                const wantRtl = locale === 'ar';
                const stateOk = /style-dark\.css/.test(sheet) === wantDark && /rtl/i.test(sheet) === wantRtl;

                await page.screenshot({ path: path.join(OUT, `01-product-list-${tag}.png`) });

                let panel = 'not-opened';
                const trigger = page.locator('[data-ss-panel-open="ss-product-preview-panel"]').first();
                if (await trigger.count() > 0) {
                    await trigger.click();
                    await page.waitForFunction(() => {
                        const b = document.querySelector('#ss-product-preview-panel [data-ss-panel-body]');
                        return b && !b.hasAttribute('aria-busy') && b.innerHTML.trim().length > 40;
                    }, { timeout: 15000 }).catch(() => {});
                    await page.waitForTimeout(400);
                    await page.screenshot({ path: path.join(OUT, `02-product-preview-panel-${tag}.png`) });
                    panel = await page.evaluate(() => {
                        const b = document.querySelector('#ss-product-preview-panel [data-ss-panel-body]');
                        if (!b) return 'no-body';
                        if (b.querySelector('.ss-panel__error')) return 'error: ' + b.textContent.trim().slice(0, 100);
                        if (b.querySelector('.ss-order-preview')) return 'rendered';
                        return 'unknown';
                    });
                }

                // Untranslated Phase 02 strings in the Arabic runs.
                let untranslated = [];
                if (locale === 'ar') {
                    untranslated = await page.evaluate(() => {
                        const p = document.getElementById('ss-product-preview-panel');
                        const text = (p ? p.textContent : '') || '';
                        return ['Product preview', 'Pricing', 'Details', 'Variants', 'Last saved',
                            'Open full product', 'Not tracked', 'Out of stock', 'Has variants',
                            'No variants', 'Category', 'Brand', 'Price', 'Active', 'Inactive']
                            .filter((w) => text.includes(w));
                    });
                }

                const ok = panel === 'rendered' && stateOk && !errors.length && !untranslated.length;
                results.push({ tag, ok });
                console.log(
                    `${tag.padEnd(22)} panel=${panel.padEnd(10)} sheet=${stateOk ? 'OK' : 'WRONG(' + sheet + ')'}`
                    + (untranslated.length ? `  UNTRANSLATED(${untranslated.length}): ${untranslated.slice(0, 5).join(', ')}` : '')
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
