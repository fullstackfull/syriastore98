/**
 * P2-01-19 — "Generate with AI" IN A SIDE PANEL, NOT A SECOND MODAL, driven for real.
 *
 * The owner's conditions are to preserve the AJAX contract, to leave no two stacked dialog levels,
 * and to test focus, closing, Escape and the keyboard. Those are claims about a running page, so
 * this clicks the real button on a real create screen and reads what happens.
 *
 * WHAT IT CHECKS, and each is a separate claim because each fails separately:
 *   1. the button is THERE at all -- gated on `currentPlan->enable_chatgpt == 'on'`, so the probe
 *      turns that on in the dev database first and asserts the button rendered. Without that this
 *      whole file would pass by measuring an absent control;
 *   2. the create view is itself inside a modal, which is the premise of the defect;
 *   3. clicking the button opens the PANEL and NOT a second modal -- the old `#commanModelOver`
 *      must stay shut, and that is the actual subject of the row;
 *   4. the panel loads the generate form over AJAX (the contract is preserved, not just the shell);
 *   5. focus moves into the panel;
 *   6. Tab stays inside it;
 *   7. Escape closes it and the FIRST modal is still open underneath -- closing the inner surface
 *      must not take the form the merchant was filling in with it.
 *
 * usage: node docs/development/phase-2/tools/check-ai-panel-live.cjs [baseUrl] [storeId]
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

const sql = (q) => execFileSync('php', ['-r',
    "$d=new PDO('sqlite:'.getenv('DB_DATABASE')); $d->exec(\"" + q + "\");"],
    { cwd: REPO, env: ENV, stdio: 'pipe' });

(async () => {
    /* The button is plan-gated. Turn it on in the DEV database only, and assert below that it
       really rendered -- a probe that measures an absent control proves nothing. */
    sql("update plans set enable_chatgpt='on'");
    execFileSync('php', ['artisan', 'cache:clear'], { cwd: REPO, env: ENV, stdio: 'pipe' });

    const browser = await chromium.launch({ args: ['--no-sandbox'] });

    try {
        for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
            execFileSync('php', [
                path.join(__dirname, 'set-admin-preview-state.php'), STORE, 'light', dir, lang, EMAIL,
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

            /* The create view as the merchant reaches it: inside the shared modal, opened from the
               list screen's own "Create" trigger. That is the nesting this row is about -- loading
               `/coupon/create` directly would test a page that is not the defect. */
            await page.goto(`${BASE}/category`, { waitUntil: 'domcontentloaded' });

            /* THE VISIBLE one, and that qualifier is a measurement rather than pedantry. Every
               merchant screen carries the header's own Quick Add list, whose entries are
               `data-ajax-popup` triggers sitting in a CLOSED dropdown -- so a selector that only
               matches the attribute finds three hidden links before it finds the screen's own
               button, and clicking a hidden one times out. (The first run of this probe did
               exactly that, against /coupon, which turned out to have no visible create trigger at
               all for this fixture: the dev merchant holds `Manage Coupon` and not `Create
               Coupon`, so its button is correctly absent. /category is used instead.) */
            const triggers = await page.$$('[data-ajax-popup="true"][data-url*="category/create"]');
            let trigger = null;
            for (const t of triggers) {
                if (await t.isVisible()) { trigger = t; break; }
            }

            if (!trigger) {
                check(`[${lang}] the category list offers a VISIBLE create trigger`, false,
                    'precondition absent -- cannot reach the nested case');
                await ctx.close();
                continue;
            }

            await trigger.click();
            await page.waitForFunction(() => {
                var m = document.querySelector('#commanModel');
                return !!m && m.classList.contains('show');
            }, { timeout: 5000 }).catch(() => {});

            const modalOpen = await page.evaluate(() => {
                var m = document.querySelector('#commanModel');
                return !!m && m.classList.contains('show');
            });
            check(`[${lang}] the create form opens in the shared modal`, modalOpen === true);

            const btn = await page.$('#commanModel [data-ss-panel-open="ai-generate"]');
            check(`[${lang}] the Generate with AI button is present inside that modal`, !!btn,
                btn ? '' : 'the plan gate or the markup changed -- every check below would be vacuous');
            if (!btn) { await ctx.close(); continue; }

            await btn.click();
            await page.waitForFunction(() => {
                var p = document.querySelector('[data-ss-panel="ai-generate"], #ai-generate');
                return !!p && p.getAttribute('data-ss-state') === 'open';
            }, { timeout: 5000 }).catch(() => {});

            /* WAIT FOR THE BODY, because `loadPanel()` opens the panel FIRST and fills it from a
               fetch -- deliberately, so the click has immediate feedback. Reading the body straight
               after the open state flips therefore measures the loading placeholder, and the first
               run of this probe reported "the panel loaded the generate form" as FAILING for
               exactly that reason. A probe's own race is not a defect in the thing it measures. */
            await page.waitForFunction(() => {
                var p = document.querySelector('[data-ss-panel="ai-generate"], #ai-generate');
                var b = p && p.querySelector('[data-ss-panel-body]');
                return !!(b && b.querySelector('#myForm'));
            }, { timeout: 8000 }).catch(() => {});

            const after = await page.evaluate(() => {
                var panel = document.querySelector('[data-ss-panel="ai-generate"], #ai-generate');
                var over = document.querySelector('#commanModelOver');
                var body = panel && panel.querySelector('[data-ss-panel-body]');
                return {
                    panelOpen: !!panel && panel.getAttribute('data-ss-state') === 'open',
                    overOpen: !!over && over.classList.contains('show'),
                    firstModalStillOpen: !!document.querySelector('#commanModel.show'),
                    loadedForm: !!(body && body.querySelector('#myForm')),
                    focusInside: !!(document.activeElement && document.activeElement.closest
                                    && document.activeElement.closest('[data-ss-panel="ai-generate"], #ai-generate')),
                };
            });

            check(`[${lang}] clicking it opens the SIDE PANEL`, after.panelOpen === true);
            check(`[${lang}] no second modal level is opened`, after.overOpen === false,
                `#commanModelOver.show=${after.overOpen}`);
            check(`[${lang}] the panel loaded the generate form over AJAX`, after.loadedForm === true,
                'the contract is the route, not just the shell');
            check(`[${lang}] focus moved into the panel`, after.focusInside === true);

            /* Tab stays inside: ten presses, every landing inside the panel. */
            let escaped = 0;
            for (let i = 0; i < 10; i++) {
                await page.keyboard.press('Tab');
                const inside = await page.evaluate(() => !!(document.activeElement
                    && document.activeElement.closest
                    && document.activeElement.closest('[data-ss-panel="ai-generate"], #ai-generate')));
                if (!inside) { escaped++; }
            }
            check(`[${lang}] Tab stays inside the panel`, escaped === 0, `${escaped}/10 escaped`);

            await page.keyboard.press('Escape');
            await page.waitForTimeout(350);
            const closed = await page.evaluate(() => ({
                panelOpen: (function () {
                    var p = document.querySelector('[data-ss-panel="ai-generate"], #ai-generate');
                    return !!p && p.getAttribute('data-ss-state') === 'open';
                })(),
                firstModalStillOpen: !!document.querySelector('#commanModel.show'),
            }));

            check(`[${lang}] Escape closes the panel`, closed.panelOpen === false);
            check(`[${lang}] and the create form underneath is still open`,
                closed.firstModalStillOpen === true,
                'closing the inner surface must not take the merchant\'s half-filled form with it');

            await ctx.close();
        }
    } finally {
        await browser.close();
        sql("update plans set enable_chatgpt='off'");
        execFileSync('php', ['artisan', 'cache:clear'], { cwd: REPO, env: ENV, stdio: 'pipe' });
    }

    process.stdout.write(`\n${failures} check(s) failed\n`);
    process.exit(failures ? 1 : 0);
})();
