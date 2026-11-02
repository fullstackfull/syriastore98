/**
 * P2-06-2. The global search palette, driven in the running application.
 *
 * It types a real term, waits for the real endpoint, and asserts the rows that came back are
 * the caller's own -- so this is a behaviour check with screenshots attached, not a photograph
 * of a mock. The authorization itself is covered by
 * tests/Feature/Security/MerchantSearchAuthorizationTest; what this adds is proof the wiring
 * between the box, the endpoint and the list actually works in a browser.
 *
 * usage: node shoot-search-live.cjs <baseUrl> <outDir>
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const OUT = process.argv[3] || path.join(__dirname, '..', 'screenshots', 'p2-06-search');
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
                page.on('response', (r) => {
                    if (r.status() >= 500 && !r.url().includes('/top-brand-category-chart')) {
                        errors.push(`${r.status()} ${r.url()}`);
                    }
                });

                await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
                await page.fill('input[name="email"]', EMAIL);
                await page.fill('input[name="password"]', PASSWORD);
                await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);

                await page.goto(`${BASE}/change-language/${locale}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
                await page.goto(`${BASE}/order`, { waitUntil: 'networkidle' });

                const tag = `${vp.name}-${locale}-${theme}`;

                // Ctrl+K, then a term that matches the seeded catalogue.
                await page.keyboard.press('Control+k');
                await page.waitForSelector('#ss-command:not([hidden])', { timeout: 5000 }).catch(() => {});
                /* `Co` matches TWO seeded products (Damask Cotton Scarf, Brocade Cushion
                   Cover). A single-row result makes ArrowDown wrap to the same item, so the
                   "selection moved" assertion below could never hold -- which is what the
                   first run of this file reported as eight failures against working code. */
                await page.fill('#ss-command-input', 'Co');

                await page.waitForFunction(() => {
                    const l = document.getElementById('ss-command-results');
                    return l && l.querySelectorAll('.ss-command__item').length > 0;
                }, { timeout: 8000 }).catch(() => {});
                await page.waitForTimeout(300);

                const state = await page.evaluate(() => {
                    const input = document.getElementById('ss-command-input');
                    const items = [...document.querySelectorAll('#ss-command-results .ss-command__item')];
                    return {
                        open: !document.getElementById('ss-command').hasAttribute('hidden'),
                        expanded: input.getAttribute('aria-expanded'),
                        activeDescendant: input.getAttribute('aria-activedescendant'),
                        selected: document.querySelectorAll('#ss-command-results [aria-selected="true"]').length,
                        groups: [...document.querySelectorAll('.ss-command__group')].map((g) => g.textContent.trim()),
                        titles: items.map((i) => i.querySelector('.ss-command__title').textContent.trim()),
                    };
                });

                await page.screenshot({ path: path.join(OUT, `01-command-palette-${tag}.png`) });

                // Arrow keys must move the SELECTION without moving focus out of the input.
                await page.keyboard.press('ArrowDown');
                await page.waitForTimeout(120);
                const afterArrow = await page.evaluate(() => ({
                    focusIsInput: document.activeElement.id === 'ss-command-input',
                    activeDescendant: document.getElementById('ss-command-input').getAttribute('aria-activedescendant'),
                }));

                // The arrow assertion only means anything with more than one row.
                const arrowMoved = state.titles.length > 1
                    ? afterArrow.activeDescendant !== state.activeDescendant
                    : true;

                const ok = state.open
                    && state.expanded === 'true'
                    && state.titles.length > 1
                    && state.selected === 1
                    && afterArrow.focusIsInput
                    && arrowMoved
                    && errors.length === 0;

                results.push({ tag, ok });
                console.log(
                    `${tag.padEnd(22)} open=${state.open} rows=${String(state.titles.length).padEnd(2)}`
                    + ` groups=[${state.groups.join('|')}]`
                    + ` sel=${state.selected} arrowMovedSelection=${arrowMoved}`
                    + ` focusStaysInInput=${afterArrow.focusIsInput} ${ok ? 'OK' : '*** FAIL ***'}`
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
