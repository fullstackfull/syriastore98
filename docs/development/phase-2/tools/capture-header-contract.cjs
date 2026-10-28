/**
 * D-25 STEP 1 — the shared header's BEHAVIOURAL CONTRACT, captured so a conversion can be compared
 * against it rather than eyeballed.
 *
 * WHY THIS EXISTS BEFORE THE CHANGE. The owner's conditions for Step 1 are "احفظ جميع معرفات
 * العناصر وEvent Handlers والروابط والصلاحيات" and a before/after behaviour comparison. Those are
 * claims about a running page, and the header renders on every merchant screen across 70 vendored
 * modules, so "it still looks right" is not a measurement. This records the contract as DATA:
 *
 *   - every id in the header, so none can be dropped (the theme's own JS selects #mobile-collapse
 *     by id, and style.css targets `.dash-header .dash-head-link#mobile-collapse`);
 *   - every link href and its visible/accessible name, so no navigation or permission-gated entry
 *     disappears;
 *   - every Bootstrap dropdown: clicked FOR REAL, with the menu's open state read afterwards,
 *     because `dropdown-toggle` / `dropdown-menu` are a JS contract and renaming either breaks the
 *     control silently;
 *   - the header's own computed geometry and colours, so a conversion that loses the theme's 210
 *     `.dash-header` rules shows up as a measured difference rather than a surprise;
 *   - the tab order through the header, since keyboard access is one of the conditions.
 *
 * ALL FOUR CONFIGURATIONS, because the DS mirrors the theme via `data-ss-theme` and the merchant
 * admin's direction comes from `users.language` rather than SITE_RTL: en/ltr/light, en/ltr/dark,
 * ar/rtl/light, ar/rtl/dark.
 *
 * usage:
 *   node docs/development/phase-2/tools/capture-header-contract.cjs <out.json> [baseUrl] [storeId]
 *
 * Run it once before the conversion and once after, then diff the two files. A difference is not
 * automatically a defect -- a deliberate colour change is a difference -- but every difference has
 * to be read by a person, which is the point.
 */
'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const OUT = process.argv[2] || 'header-contract.json';
const BASE = process.argv[3] || 'http://127.0.0.1:8899';
const STORE = process.argv[4] || '1';
const REPO = '/home/user/syriastore98';
const EMAIL = 'merchant@example.test';
const PASSWORD = 'password';

/* Representative rather than exhaustive, and the choice is stated: two first-party list screens,
   one first-party form, the dashboard, and two screens whose CONTENT comes from vendored modules --
   the header is shared, so a module screen is where a regression in it would be missed. */
const PAGES = ['/dashboard', '/order', '/product', '/product/create', '/coupon', '/order-kanban'];

const ENV = Object.assign({}, process.env, {
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'),
    CACHE_STORE: 'file',
    SESSION_DRIVER: 'file',
});

const CONTRACT = `(function () {
    var head = document.querySelector('header.dash-header, header.ss-topbar, header');
    if (!head) { return { present: false }; }

    function name(el) {
        var aria = el.getAttribute('aria-label');
        if (aria) { return aria.trim(); }
        var txt = (el.textContent || '').replace(/\\s+/g, ' ').trim();
        if (txt) { return txt; }
        /* The data-bs-original-title attribute TOO, AND THAT IS WHAT MADE THIS CAPTURE
           DETERMINISTIC. Reading the title attribute alone gave different answers on two runs of
           the SAME tree, in 2 of 24 captures: the Store Link and POS controls are icon-only with
           a title, and Bootstrap's tooltip plugin MOVES that attribute into
           data-bs-original-title and clears title when it initialises -- so the name depended on
           whether the plugin had run yet. The artifact very nearly got attributed to a CSS
           change. Reading both is stable and is also the more accurate answer, since the tooltip
           is the name a user gets either way.
           (No backticks in here: this comment lives inside a template literal.) */
        var t = el.getAttribute('title') || el.getAttribute('data-bs-original-title');
        return t ? t.trim() : '';
    }

    var cs = getComputedStyle(head);

    return {
        present: true,
        ids: Array.prototype.map.call(head.querySelectorAll('[id]'), function (e) { return e.id; }).sort(),
        links: Array.prototype.map.call(head.querySelectorAll('a[href]'), function (a) {
            return { href: a.getAttribute('href'), name: name(a) };
        }),
        forms: Array.prototype.map.call(head.querySelectorAll('form'), function (f) {
            return { action: f.getAttribute('action'), method: (f.getAttribute('method') || '').toLowerCase(), id: f.id || '' };
        }),
        toggles: Array.prototype.map.call(head.querySelectorAll('[data-bs-toggle="dropdown"]'), function (t) {
            return { name: name(t), expanded: t.getAttribute('aria-expanded') };
        }),
        /* Geometry and colour, so losing the theme's rules is visible as data. */
        box: (function () { var r = head.getBoundingClientRect(); return { h: Math.round(r.height), top: Math.round(r.top) }; })(),
        style: { bg: cs.backgroundColor, color: cs.color, position: cs.position, zIndex: cs.zIndex },
        /* Every focusable control in the header, in document order -- the tab order. */
        focusables: Array.prototype.filter.call(
            head.querySelectorAll('a[href], button, input, select, [tabindex]'),
            function (e) { return e.tabIndex >= 0 && e.offsetParent !== null; }
        ).map(function (e) { return e.tagName.toLowerCase() + ':' + (name(e) || '(unnamed)'); }),
    };
})()`;

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox'] });
    const out = {};

    for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
        for (const theme of ['light', 'dark']) {
            execFileSync('php', [
                path.join(__dirname, 'set-admin-preview-state.php'),
                STORE, theme, dir, lang, EMAIL,
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
                const key = `${lang}/${dir}/${theme}${url}`;
                const resp = await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });

                if (!resp || resp.status() !== 200) {
                    out[key] = { unreachable: resp ? resp.status() : 0 };
                    continue;
                }

                const contract = await page.evaluate(CONTRACT);

                /* THE DROPDOWNS, CLICKED. A renamed `dropdown-toggle` or `dropdown-menu` leaves the
                   markup looking fine and the control dead, which no static capture would notice. */
                contract.dropdownsOpen = [];
                const toggles = await page.$$('header [data-bs-toggle="dropdown"]');
                for (let i = 0; i < toggles.length; i++) {
                    let opened = null;
                    try {
                        await toggles[i].click({ timeout: 2000 });
                        await page.waitForTimeout(250);
                        opened = await toggles[i].evaluate(t =>
                            t.getAttribute('aria-expanded') === 'true'
                            || !!(t.parentElement && t.parentElement.querySelector('.show, [data-ss-open]')));
                        await page.keyboard.press('Escape');
                        await page.waitForTimeout(100);
                    } catch (e) {
                        opened = 'error: ' + e.message.split('\n')[0];
                    }
                    contract.dropdownsOpen.push(opened);
                }

                /* The real tab order: Tab from the top of the document and record what the header
                   hands focus to, in order. A link removed from the flow shows up here. */
                await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
                await page.evaluate(() => document.body.focus());
                const tabbed = [];
                for (let i = 0; i < 25; i++) {
                    await page.keyboard.press('Tab');
                    const where = await page.evaluate(() => {
                        const a = document.activeElement;
                        if (!a || !a.closest || !a.closest('header')) { return null; }
                        return a.tagName.toLowerCase() + ':'
                            + ((a.getAttribute('aria-label') || a.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 40) || '(unnamed)');
                    });
                    if (where) { tabbed.push(where); }
                }
                contract.tabOrder = tabbed;

                out[key] = contract;
            }

            await ctx.close();
        }
    }

    await browser.close();
    fs.writeFileSync(OUT, JSON.stringify(out, null, 2));
    process.stdout.write(`wrote ${OUT}: ${Object.keys(out).length} page/configuration captures\n`);
})();
