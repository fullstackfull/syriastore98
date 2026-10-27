#!/usr/bin/env node
/**
 * P2-06-7 — favourite pages and recent pages, driven in a real browser.
 * =================================================================================================
 *
 * WHAT ONLY A BROWSER CAN ANSWER. `FavouritePagesTest` proves the decisions: the gate, the two
 * tenant predicates, the render-time re-check, that a URL cannot be stored, that a recent page
 * leaves no row. None of it proves the palette actually SHOWS the lists, that the star toggles
 * against the live endpoint and comes back reflecting the server's answer, that the control is
 * reachable and readable in Arabic and in dark mode, or that the text meets contrast on the
 * surface the shortcuts block repaints underneath it.
 *
 * CONTRAST IS CHECKED ON THE SURFACE THE COMPONENT ACTUALLY PAINTS, which is the defect this
 * project keeps rediscovering: `--ss-fg-subtle` passes on `--ss-surface` and fails on
 * `--ss-surface-sunken`, and the shortcuts block is sunken. Reading the computed style rather
 * than the token is the only way to see that.
 *
 * PRECONDITIONS, and it fails loudly rather than reporting a clean pass without them:
 *   - a server on 8899 serving THIS repository, launched from inside `public/`;
 *   - `database/p2dev.sqlite` migrated with `merchant_favourite_pages`.
 *
 * usage: node docs/development/phase-2/tools/check-favourite-pages-live.cjs [baseUrl]
 */

'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const REPO = '/home/user/syriastore98';
const ENV = Object.assign({}, process.env, {
    DB_CONNECTION: 'sqlite', DB_DATABASE: REPO + '/database/p2dev.sqlite',
    CACHE_STORE: 'file', SESSION_DRIVER: 'file',
});

let pass = 0; const failures = [];
function check(name, ok, detail) {
    if (ok) { pass++; return; }
    failures.push(name + (detail ? '  [' + detail + ']' : ''));
    console.log('  FAIL  ' + name + (detail ? '  [' + detail + ']' : ''));
}

/* Both halves of direction -- the language AND the store's SITE_RTL setting. The layout can only
   force RTL *on* from the language, so setting the language alone leaves a stored 'on' in place
   and every "ltr" case silently measures an RTL page. Measured on P2-06-6, where it had made four
   green cases vacuous. */
function setDirection(lang) {
    const rtl = (lang === 'ar' || lang === 'he') ? 'on' : 'off';
    const got = execFileSync('php', ['-r',
        'require "vendor/autoload.php"; $a = require "bootstrap/app.php";'
        + ' $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
        + ' $u = App\\Models\\User::where("email", "merchant@example.test")->firstOrFail();'
        + ' $u->language = "' + lang + '"; $u->save(); (new App\\Models\\User)->flushCache();'
        + ' $store = (int) $u->current_store;'
        + ' $row = App\\Models\\Setting::withoutGlobalScopes()->where("store_id", $store)'
        + '     ->where("name", "SITE_RTL")->first();'
        + ' if ($row) { $row->value = "' + rtl + '"; $row->save(); }'
        + ' else { App\\Models\\Setting::create(["name" => "SITE_RTL", "value" => "' + rtl + '",'
        + '     "store_id" => $store, "created_by" => $u->creatorId()]); }'
        + ' (new App\\Models\\Setting)->flushCache();'
        + ' echo App\\Models\\Setting::withoutGlobalScopes()->where("store_id", $store)'
        + '     ->where("name", "SITE_RTL")->value("value");'],
        { cwd: REPO, env: ENV, stdio: 'pipe' }).toString().trim();

    if (got !== rtl) throw new Error(`SITE_RTL is "${got}", asked for "${rtl}"`);
}

/* THEME IS A STORE SETTING TOO, NOT A BROWSER PREFERENCE, and the first version of this harness
   got that wrong in exactly the way the direction bug went: it passed Playwright's `colorScheme`
   and labelled the cases light/dark, while `layouts/app.blade.php:70` sets `data-ss-theme="dark"`
   from the `cust_darklayout` SETTING and ignores the browser entirely. Measured: both "light" and
   "dark" cases ran with `data-ss-theme="dark"` and `prefers-color-scheme: light`, so the light
   palette was never tested. The premise assertion below is what stops that recurring. */
function setTheme(theme) {
    const value = theme === 'dark' ? 'on' : 'off';
    const got = execFileSync('php', ['-r',
        'require "vendor/autoload.php"; $a = require "bootstrap/app.php";'
        + ' $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
        + ' $u = App\\Models\\User::where("email", "merchant@example.test")->firstOrFail();'
        + ' $store = (int) $u->current_store;'
        + ' $row = App\\Models\\Setting::withoutGlobalScopes()->where("store_id", $store)'
        + '     ->where("name", "cust_darklayout")->first();'
        + ' if ($row) { $row->value = "' + value + '"; $row->save(); }'
        + ' else { App\\Models\\Setting::create(["name" => "cust_darklayout", "value" => "' + value + '",'
        + '     "store_id" => $store, "created_by" => $u->creatorId()]); }'
        + ' (new App\\Models\\Setting)->flushCache();'
        + ' echo App\\Models\\Setting::withoutGlobalScopes()->where("store_id", $store)'
        + '     ->where("name", "cust_darklayout")->value("value");'],
        { cwd: REPO, env: ENV, stdio: 'pipe' }).toString().trim();

    if (got !== value) throw new Error(`cust_darklayout is "${got}", asked for "${value}"`);
}

function clearFavourites() {
    execFileSync('php', ['-r',
        'require "vendor/autoload.php"; $a = require "bootstrap/app.php";'
        + ' $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
        + ' Illuminate\\Support\\Facades\\DB::table("merchant_favourite_pages")->delete();'],
        { cwd: REPO, env: ENV, stdio: 'pipe' });
}

function countFavourites() {
    return Number(execFileSync('php', ['-r',
        'require "vendor/autoload.php"; $a = require "bootstrap/app.php";'
        + ' $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
        + ' echo Illuminate\\Support\\Facades\\DB::table("merchant_favourite_pages")->count();'],
        { cwd: REPO, env: ENV, stdio: 'pipe' }).toString().trim());
}

async function login(page) {
    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', 'merchant@example.test');
    await page.fill('input[name="password"]', 'password');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
    if (page.url().includes('/login')) throw new Error('login failed');
}

const srgb = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
const lum = ([r, g, b]) => 0.2126 * srgb(r) + 0.7152 * srgb(g) + 0.0722 * srgb(b);
const parse = (s) => { const m = String(s).match(/-?\d+(\.\d+)?/g); return m ? m.slice(0, 3).map(Number) : null; };
function ratio(fg, bg) {
    const a = lum(fg), b = lum(bg);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

(async () => {
    const browser = await chromium.launch();

    try {
        for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
            setDirection(lang);

            for (const theme of ['light', 'dark']) {
                clearFavourites();
                setTheme(theme);

                const ctx = await browser.newContext({
                    viewport: { width: 1600, height: 950 },
                    colorScheme: theme,
                });
                const page = await ctx.newPage();
                const errors = [];
                page.on('pageerror', (e) => errors.push(e.message));

                const tag = `${dir}/${theme}`;

                await login(page);
                await page.goto(BASE + '/product', { waitUntil: 'domcontentloaded' });
                await page.waitForTimeout(700);

                // PREMISE: the direction and theme really are the ones asked for.
                const htmlDir = (await page.getAttribute('html', 'dir')) || 'ltr';
                check(`${tag} — the page is really ${dir}`, (htmlDir === 'rtl') === (dir === 'rtl'), htmlDir);

                const htmlTheme = (await page.getAttribute('html', 'data-ss-theme')) || 'light';
                check(`${tag} — the page is really ${theme}`, htmlTheme === theme, htmlTheme);

                // The shortcuts block exists, and the "add this page" control is offered.
                check(`${tag} — the shortcuts block renders`,
                    await page.locator('[data-ss-command-shortcuts]').count() === 1);

                const addCurrent = page.locator('.ss-command__addcurrent');
                check(`${tag} — the current page can be added`, await addCurrent.count() === 1);

                /* THE TOGGLE, AGAINST THE LIVE ENDPOINT. The palette must be open for the control
                   to be visible, and a hidden element cannot be clicked. */
                await page.keyboard.press('Control+k');
                await page.waitForTimeout(400);
                check(`${tag} — the palette opens`, await page.locator('#ss-command').isVisible());
                check(`${tag} — the shortcuts show in the pre-search state`,
                    await page.locator('[data-ss-command-shortcuts]').isVisible());

                const before = countFavourites();
                await addCurrent.click();
                await page.waitForTimeout(900);
                const after = countFavourites();

                check(`${tag} — clicking the star stores the favourite`, after === before + 1,
                    `${before} -> ${after}`);
                check(`${tag} — and the control reflects the server's answer`,
                    await addCurrent.getAttribute('aria-pressed') === 'true',
                    String(await addCurrent.getAttribute('aria-pressed')));

                // It survives a reload, which is the whole point of it not being in the session.
                await page.reload({ waitUntil: 'domcontentloaded' });
                await page.waitForTimeout(700);
                check(`${tag} — the favourite is rendered after a reload`,
                    await page.locator('[data-ss-favourite="product"]').count() >= 1);

                /* AND IT SURVIVES A WHOLE NEW SESSION, which is the "from a different device"
                   half of the requirement: a fresh browser context shares no cookie, no session
                   and no localStorage with the one that set it. */
                const fresh = await browser.newContext({ viewport: { width: 1280, height: 800 }, colorScheme: theme });
                const freshPage = await fresh.newPage();
                await login(freshPage);
                await freshPage.goto(BASE + '/product', { waitUntil: 'domcontentloaded' });
                await freshPage.waitForTimeout(700);
                check(`${tag} — the favourite is there in a brand new session`,
                    await freshPage.locator('[data-ss-favourite="product"]').count() >= 1);
                await fresh.close();

                // Recent pages: visit another page, and it is offered as recent.
                await page.goto(BASE + '/category', { waitUntil: 'domcontentloaded' });
                await page.waitForTimeout(500);
                await page.goto(BASE + '/product', { waitUntil: 'domcontentloaded' });
                await page.waitForTimeout(700);
                await page.keyboard.press('Control+k');
                await page.waitForTimeout(400);

                const recentHeads = await page.locator('.ss-command__grouphead').allTextContents();
                check(`${tag} — a recent page group appears after visiting another page`,
                    recentHeads.length >= 1, recentHeads.join(' | '));

                /* THE STRINGS ARE ACTUALLY TRANSLATED, not merely laid out right-to-left. The
                   contrast checks below would pass on English text in an RTL page, so without
                   this the harness's own summary line ("reads correctly in Arabic") would be an
                   overclaim: it would prove the direction and the colours and say nothing about
                   the words. `__()` falls back to the KEY when a translation is missing, so the
                   English literal appearing here is exactly the failure mode. */
                if (dir === 'rtl') {
                    check(`${tag} — the group headings are translated`,
                        recentHeads.length > 0 && !recentHeads.some((h) => h.trim() === 'Favourites'
                            || h.trim() === 'Recent'),
                        recentHeads.join(' | '));

                    const addLabel = (await page.locator('.ss-command__addcurrent').count())
                        ? (await page.locator('.ss-command__addcurrent').first().textContent() || '').trim()
                        : '';
                    check(`${tag} — the add control is translated`,
                        !addLabel.includes('Add this page to favourites'), addLabel || '(absent)');
                } else {
                    check(`${tag} — the group headings are in English`,
                        recentHeads.some((h) => ['Favourites', 'Recent'].includes(h.trim())),
                        recentHeads.join(' | '));
                }

                /* CONTRAST, READ FROM THE COMPUTED STYLE on the surface the block really paints.
                   The group heading is the one at risk: `--ss-fg-subtle` fails on
                   `--ss-surface-sunken` and this block is sunken. */
                const contrast = await page.evaluate(() => {
                    const out = [];
                    const bgOf = (el) => {
                        let n = el;
                        while (n && n !== document.documentElement) {
                            const c = getComputedStyle(n).backgroundColor;
                            if (c && c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent') return c;
                            n = n.parentElement;
                        }
                        return getComputedStyle(document.body).backgroundColor;
                    };
                    document.querySelectorAll(
                        '.ss-command__grouphead, .ss-command__shortcuttitle, .ss-command__addcurrent, .ss-command__star'
                    ).forEach((el) => {
                        const text = (el.textContent || '').trim();
                        if (!text) return;
                        const s = getComputedStyle(el);
                        out.push({
                            sel: el.className, text: text.slice(0, 24),
                            fg: s.color, bg: bgOf(el), size: parseFloat(s.fontSize),
                            weight: s.fontWeight,
                        });
                    });
                    return out;
                });

                check(`${tag} — there is text to contrast-check`, contrast.length > 0,
                    String(contrast.length));

                for (const c of contrast) {
                    const fg = parse(c.fg), bg = parse(c.bg);
                    if (!fg || !bg) { check(`${tag} — colour is readable for "${c.text}"`, false, c.fg + ' on ' + c.bg); continue; }
                    const r = ratio(fg, bg);
                    const large = c.size >= 24 || (c.size >= 18.66 && Number(c.weight) >= 700);
                    const need = large ? 3 : 4.5;
                    check(`${tag} — "${c.text}" meets ${need}:1`, r >= need,
                        `${r.toFixed(2)}:1  ${c.fg} on ${c.bg} @ ${c.size}px`);
                }

                check(`${tag} — no JavaScript errors`, errors.length === 0, errors.join(' | '));

                await ctx.close();
            }
        }
    } finally {
        setDirection('en');
        setTheme('light');
        await browser.close();
    }

    if (failures.length) {
        console.log(`\n${failures.length} of ${pass + failures.length} cases FAILED`);
        process.exit(1);
    }
    console.log(`\n${pass} cases passed — favourites persist across sessions, recents appear, and both read correctly in Arabic and in dark mode`);
})().catch((e) => { console.error('FATAL ' + e.message); process.exit(1); });
