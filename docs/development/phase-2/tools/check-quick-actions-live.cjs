/**
 * P2-01-18 / WS B5 / H5 — the quick-action surface, reached from anywhere, by keyboard.
 *
 * WHAT THIS ROW TURNED OUT TO BE. Its acceptance is "a quick-action surface the merchant can reach
 * from anywhere, permission-aware, and reachable from search results; the host theme's own
 * 'Quick Add' is not ours and does not satisfy this". Measured before building anything, all three
 * clauses were already answered by shipped work:
 *
 *   - FROM ANYWHERE: `layouts/app.blade.php:537` includes `partision.command-palette`, so it is on
 *     every merchant screen, and Ctrl/Cmd+K is bound on `document`. That is what this tool drives.
 *   - PERMISSION-AWARE: `MerchantSearchController::may()` is operator-first and resolves each
 *     action grant separately (P2-06-8), asserted by `MerchantSearchAuthorizationTest` -- including
 *     "a section the caller may not see is absent, NOT EMPTY" and the positive case, "the same
 *     staff member sees the section once granted".
 *   - FROM SEARCH RESULTS: P2-06-8, DONE.
 *
 * So the work was to VERIFY rather than to build -- and building a second quick-action surface
 * beside the palette is exactly the parallel mechanism the owner's standing limits forbid.
 *
 * WHY THE KEYPRESS AND NOT A GREP. "Reachable from anywhere" is a claim about a running page:
 * the include could be present while a JS error on a particular screen stops the binding ever
 * attaching. So Ctrl+K is pressed for real, on several screens, in both languages.
 *
 * usage: node docs/development/phase-2/tools/check-quick-actions-live.cjs [baseUrl] [storeId]
 */
'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const STORE = process.argv[3] || '1';
const REPO = '/home/user/syriastore98';
const EMAIL = 'merchant@example.test';
const PASSWORD = 'password';
const PAGES = ['/order', '/product', '/customer', '/coupon', '/order-kanban', '/product/create'];

const ENV = Object.assign({}, process.env, {
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'),
    CACHE_STORE: 'file',
    SESSION_DRIVER: 'file',
});

let failures = 0;
function check(label, ok, detail) {
    process.stdout.write(`${ok ? 'ok  ' : 'FAIL'}  ${label}${detail ? '  -- ' + detail : ''}\n`);
    if (!ok) { failures++; }
}

const STATE = `(function () {
    var root = document.getElementById('ss-command');
    if (!root) { return { present: false }; }
    var cs = getComputedStyle(root);
    return {
        present: true,
        open: !root.hasAttribute('hidden') && cs.display !== 'none',
        modal: root.getAttribute('aria-modal'),
        role: root.getAttribute('role'),
        labelled: !!(root.getAttribute('aria-label') || root.getAttribute('aria-labelledby')),
        focusInside: !!(document.activeElement && document.activeElement.closest
                        && document.activeElement.closest('#ss-command')),
        favourites: document.querySelectorAll('#ss-command [data-ss-favourite]').length,
    };
})()`;

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox'] });

    for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
        execFileSync('php', [
            path.join(__dirname, 'set-admin-preview-state.php'),
            STORE, 'light', dir, lang, EMAIL,
        ], { cwd: REPO, env: ENV, stdio: 'pipe' });

        const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 } });
        await ctx.route('**/merchant-notifications/feed*', r => r.abort());
        const page = await ctx.newPage();

        await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
        await page.fill('input[name=email]', EMAIL);
        await page.fill('input[name=password]', PASSWORD);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
            page.click('button[type=submit]'),
        ]);

        for (const url of PAGES) {
            const resp = await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
            if (!resp || resp.status() !== 200) {
                check(`[${lang}] ${url} reachable`, false, `HTTP ${resp ? resp.status() : 0} -- precondition, not a result`);
                continue;
            }

            const before = await page.evaluate(STATE);
            if (!before.present || before.open) {
                check(`[${lang}] ${url}: the palette starts present and closed`, false,
                    `present=${before.present} open=${before.open}`);
                continue;
            }

            // The real shortcut, on the real page.
            await page.keyboard.press('Control+k');
            await page.waitForFunction(() => {
                var r = document.getElementById('ss-command');
                return !!r && !r.hasAttribute('hidden');
            }, { timeout: 3000 }).catch(() => {});

            const after = await page.evaluate(STATE);

            check(`[${lang}] ${url}: Ctrl+K opens the quick-action surface`, after.open === true,
                `open=${after.open}`);
            check(`[${lang}] ${url}: it is a labelled modal dialog`,
                after.role === 'dialog' && after.modal === 'true' && after.labelled === true,
                `role=${after.role} aria-modal=${after.modal} labelled=${after.labelled}`);
            check(`[${lang}] ${url}: focus moves into it`, after.focusInside === true);

            await page.keyboard.press('Escape');
            const closed = await page.evaluate(STATE);
            check(`[${lang}] ${url}: Escape closes it again`, closed.open === false, `open=${closed.open}`);
        }

        await ctx.close();
    }

    await browser.close();
    process.stdout.write(`\n${failures} check(s) failed\n`);
    process.exit(failures ? 1 : 0);
})();
