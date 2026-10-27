#!/usr/bin/env node
/**
 * P2-06-9 — transient action feedback, announced to assistive technology.
 * =================================================================================================
 *
 * WHAT ONLY A BROWSER CAN ANSWER. `TransientFeedbackContractTest` asserts the source: that the
 * notifier plugin announces nothing of its own, that `show_toastr` calls the announcer, that an
 * error is assertive and a success polite, and that the persistent inbox is not a live region.
 * None of it proves the region is actually CREATED, that it carries the right role and politeness
 * in a real DOM, that the message reaches it, or that the design system's own announcements and
 * the toast's converge on ONE element rather than two that compete.
 *
 * TWO REGIONS ARE THE BUG THIS CATCHES. A page with two polite live regions announces
 * unpredictably — a screen reader may read either, in either order. `store-syria-ds.js` already
 * owned `#ss-live-region`, and the obvious implementation of this slice would have added a
 * second. So the count is asserted after BOTH paths have fired.
 *
 * `show_toastr` IS CALLED HERE EXACTLY AS THE LAYOUT CALLS IT — same function, same three
 * arguments — rather than by driving a form that happens to flash. Driving a form would test the
 * form; the surface is what this row is about, and the source test already asserts that
 * `layouts/app.blade.php` is wired to this function.
 *
 * PRECONDITIONS, and it fails loudly rather than reporting a clean pass without them:
 *   - a server on 8899 serving THIS repository, launched from inside `public/`;
 *   - `database/p2dev.sqlite` seeded by `database/seed-p2dev.php`.
 *
 * usage: node docs/development/phase-2/tools/check-transient-feedback-live.cjs [baseUrl]
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
    failures.push(name);
    console.log('  FAIL  ' + name + (detail ? '  [' + detail + ']' : ''));
}

function setSetting(name, value) {
    const got = execFileSync('php', ['-r',
        'require "vendor/autoload.php"; $a = require "bootstrap/app.php";'
        + ' $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
        + ' $u = App\\Models\\User::where("email", "merchant@example.test")->firstOrFail();'
        + ' $store = (int) $u->current_store;'
        + ' $row = App\\Models\\Setting::withoutGlobalScopes()->where("store_id", $store)'
        + '     ->where("name", "' + name + '")->first();'
        + ' if ($row) { $row->value = "' + value + '"; $row->save(); }'
        + ' else { App\\Models\\Setting::create(["name" => "' + name + '", "value" => "' + value + '",'
        + '     "store_id" => $store, "created_by" => $u->creatorId()]); }'
        + ' (new App\\Models\\Setting)->flushCache();'
        + ' echo App\\Models\\Setting::withoutGlobalScopes()->where("store_id", $store)'
        + '     ->where("name", "' + name + '")->value("value");'],
        { cwd: REPO, env: ENV, stdio: 'pipe' }).toString().trim();

    if (got !== value) throw new Error(`${name} is "${got}", asked for "${value}"`);
}

function setDirection(lang) {
    execFileSync('php', ['-r',
        'require "vendor/autoload.php"; $a = require "bootstrap/app.php";'
        + ' $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
        + ' $u = App\\Models\\User::where("email", "merchant@example.test")->firstOrFail();'
        + ' $u->language = "' + lang + '"; $u->save(); (new App\\Models\\User)->flushCache();'],
        { cwd: REPO, env: ENV, stdio: 'pipe' });
    setSetting('SITE_RTL', (lang === 'ar' || lang === 'he') ? 'on' : 'off');
}

async function login(page) {
    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', 'merchant@example.test');
    await page.fill('input[name="password"]', 'password');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
    if (page.url().includes('/login')) throw new Error('login failed');
}

(async () => {
    const browser = await chromium.launch();

    try {
        for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
            setDirection(lang);

            for (const theme of ['light', 'dark']) {
                setSetting('cust_darklayout', theme === 'dark' ? 'on' : 'off');

                const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
                const page = await ctx.newPage();
                const errors = [];
                page.on('pageerror', (e) => errors.push(e.message));

                const tag = `${dir}/${theme}`;

                await login(page);
                await page.goto(BASE + '/product', { waitUntil: 'domcontentloaded' });
                await page.waitForTimeout(800);

                const htmlDir = (await page.getAttribute('html', 'dir')) || 'ltr';
                check(`${tag} — the page is really ${dir}`, (htmlDir === 'rtl') === (dir === 'rtl'), htmlDir);
                const htmlTheme = (await page.getAttribute('html', 'data-ss-theme')) || 'light';
                check(`${tag} — the page is really ${theme}`, htmlTheme === theme, htmlTheme);

                // PREMISE: the function the whole slice hangs on is actually present.
                const wired = await page.evaluate(() => ({
                    toastr: typeof window.show_toastr,
                    announcer: typeof window.ss_announce_transient,
                    notifier: typeof window.notifier,
                }));
                check(`${tag} — premise: show_toastr is defined`, wired.toastr === 'function', wired.toastr);
                check(`${tag} — premise: the announcer is defined`, wired.announcer === 'function', wired.announcer);
                check(`${tag} — premise: the notifier plugin loaded`, wired.notifier === 'object', wired.notifier);

                /* ---- a SUCCESS: polite, role=status, and the message arrives ---- */
                await page.evaluate(() => window.show_toastr('Success', 'Saved the product.', 'success'));
                await page.waitForTimeout(400);

                const polite = await page.evaluate(() => {
                    const r = document.getElementById('ss-live-region');
                    return r ? {
                        role: r.getAttribute('role'),
                        live: r.getAttribute('aria-live'),
                        atomic: r.getAttribute('aria-atomic'),
                        text: r.textContent,
                        // visually hidden, not display:none -- a display:none region announces nothing
                        display: getComputedStyle(r).display,
                        visibility: getComputedStyle(r).visibility,
                        inBody: document.body.contains(r),
                    } : null;
                });

                check(`${tag} — a polite region exists after a success`, polite !== null);
                check(`${tag} — it is aria-live=polite`, polite && polite.live === 'polite',
                    polite && polite.live);
                check(`${tag} — it is role=status`, polite && polite.role === 'status',
                    polite && polite.role);
                check(`${tag} — it is atomic`, polite && polite.atomic === 'true',
                    polite && polite.atomic);
                check(`${tag} — the success message reached it`,
                    polite && polite.text === 'Saved the product.', polite && polite.text);
                /* A `display: none` region is skipped by screen readers entirely, which is the
                   classic way a "visually hidden" helper silently breaks an announcement. */
                check(`${tag} — it is not display:none`, polite && polite.display !== 'none',
                    polite && polite.display);
                check(`${tag} — it is not visibility:hidden`, polite && polite.visibility !== 'hidden',
                    polite && polite.visibility);

                /* ---- an ERROR: assertive, role=alert, and a DIFFERENT element ---- */
                await page.evaluate(() => window.show_toastr('Error', 'Could not save.', 'error'));
                await page.waitForTimeout(400);

                const assertive = await page.evaluate(() => {
                    const r = document.getElementById('ss-live-region-assertive');
                    return r ? {
                        role: r.getAttribute('role'),
                        live: r.getAttribute('aria-live'),
                        text: r.textContent,
                        display: getComputedStyle(r).display,
                    } : null;
                });

                check(`${tag} — an assertive region exists after an error`, assertive !== null);
                check(`${tag} — it is aria-live=assertive`,
                    assertive && assertive.live === 'assertive', assertive && assertive.live);
                check(`${tag} — it is role=alert`, assertive && assertive.role === 'alert',
                    assertive && assertive.role);
                check(`${tag} — the error message reached it`,
                    assertive && assertive.text === 'Could not save.', assertive && assertive.text);
                check(`${tag} — it is not display:none`, assertive && assertive.display !== 'none',
                    assertive && assertive.display);

                /* The success text must NOT have been overwritten by the error: two surfaces. */
                const stillPolite = await page.evaluate(() =>
                    (document.getElementById('ss-live-region') || {}).textContent);
                check(`${tag} — the success and the error are separate regions`,
                    stillPolite === 'Saved the product.', String(stillPolite));

                /* ---- ONE POLITE REGION, after the design system has also announced ---- */
                await page.evaluate(() => {
                    // The design system's own announcer, used by the column keys and the
                    // favourite toggle. If it created its own region there would now be two.
                    if (window.StoreSyriaDS && window.StoreSyriaDS.wireFavouriteToggle) {
                        var btn = document.querySelector('[data-ss-favourite]');
                        if (btn) { btn.click(); }
                    }
                });
                await page.waitForTimeout(900);

                const regions = await page.evaluate(() => ({
                    polite: Array.from(document.querySelectorAll('[aria-live="polite"]'))
                        .map((e) => e.id || e.className || e.tagName),
                    assertive: document.querySelectorAll('[aria-live="assertive"]').length,
                    byId: document.querySelectorAll('#ss-live-region').length,
                }));

                check(`${tag} — exactly one element with the shared polite id`, regions.byId === 1,
                    String(regions.byId));
                check(`${tag} — exactly one assertive region`, regions.assertive === 1,
                    String(regions.assertive));

                /* THE POLITE REGIONS ARE NAMED, NOT COUNTED, and the count is what this check
                   started as -- "at most two" -- which failed against correct code because I had
                   not accounted for DATATABLES' OWN region: core gives `#<table>_info`
                   `aria-live="polite"` `role="status"` for "Showing 1 to 10 of 51 entries". So
                   three is the right number on any list page, and a threshold would have been
                   either wrong or quietly relaxed.
                
                   Naming them is also the stronger assertion: a NEW competing region fails here
                   whatever the total, which is the thing that actually breaks announcements --
                   two regions for the same job announce unpredictably. The three allowed are
                   DataTables' row count, the palette's result count (P2-06-2, describing the
                   search box rather than an action outcome), and this slice's shared region. */
                const ALLOWED_POLITE = [/_info$/, /^ss-command-status$/, /^ss-live-region$/];
                const unexpected = regions.polite.filter(
                    (id) => !ALLOWED_POLITE.some((re) => re.test(id))
                );
                check(`${tag} — every polite region is a known one`, unexpected.length === 0,
                    'unexpected polite live region(s): ' + JSON.stringify(unexpected)
                    + ' (all: ' + JSON.stringify(regions.polite) + ')');

                /* ---- the persistent inbox is NOT a live region, in the live DOM ---- */
                const inbox = await page.evaluate(() => {
                    const feed = document.querySelector('.ss-feed');
                    const bell = document.querySelector('.ss-bell__count');
                    const liveAncestor = (el) => {
                        let n = el;
                        while (n) { if (n.hasAttribute && n.hasAttribute('aria-live')) return true; n = n.parentElement; }
                        return false;
                    };
                    return {
                        feedPresent: !!feed,
                        feedLive: feed ? liveAncestor(feed) : false,
                        bellPresent: !!bell,
                        bellLive: bell ? liveAncestor(bell) : false,
                    };
                });

                check(`${tag} — the notification bell is not inside a live region`,
                    inbox.bellLive === false,
                    'the persistent inbox announces operational notifications as they arrive');
                if (inbox.feedPresent) {
                    check(`${tag} — the notification feed is not inside a live region`,
                        inbox.feedLive === false);
                }

                check(`${tag} — no JavaScript errors`, errors.length === 0, errors.join(' | '));

                await ctx.close();
            }
        }
    } finally {
        setDirection('en');
        setSetting('cust_darklayout', 'off');
        await browser.close();
    }

    if (failures.length) {
        console.log(`\n${failures.length} of ${pass + failures.length} cases FAILED`);
        process.exit(1);
    }
    console.log(`\n${pass} cases passed — the transient surface is a real live region in both politeness levels, the two outcomes are separate, and the persistent inbox stays silent`);
})().catch((e) => { console.error('FATAL ' + e.message); process.exit(1); });
