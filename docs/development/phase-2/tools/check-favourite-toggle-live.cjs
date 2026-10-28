/**
 * P2-01-15 / WS A8 — the favourite toggle's executing, success and failure states, driven for real.
 *
 * WHY A DRIVEN TEST AND NOT A MARKUP ASSERTION. The three defects this row fixed are all states
 * that only exist DURING or AFTER a request, so nothing static can see them:
 *
 *   1. nothing was announced between the click and the answer -- `[disabled]` gave a visible
 *      executing state (`cursor: progress`, dimmed) but a screen-reader user got silence;
 *   2. on success the glyph never changed. `is-on` moves `color` to the brand, and colour alone
 *      is not an accessible distinction;
 *   3. on success the `title` and the visually-hidden label were left as rendered, so after
 *      adding a page the accessible name still read "Add :page to favourites" -- the opposite of
 *      the `aria-pressed` beside it.
 *
 * THE RESPONSE IS DELAYED DELIBERATELY. The executing state is unobservable against a local
 * server that answers in single-digit milliseconds, so the POST is held open while the in-flight
 * attributes are sampled. Without that the test would pass whether or not `aria-busy` was ever
 * set, which is the failure mode this project keeps finding.
 *
 * FAILURE IS DRIVEN BY REFUSING THE REQUEST, not by simulating one: the route is fulfilled with a
 * 500 so the real `.catch` limb runs.
 *
 * Both languages, because the copy all travels in `data-` attributes now and a missing Arabic
 * string would be invisible to an English-only run.
 *
 * usage: node docs/development/phase-2/tools/check-favourite-toggle-live.cjs [baseUrl] [storeId]
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

/*
 * THE REAL ENDPOINT, and the reason it is a named constant.
 * The first version of this file routed `**\/favourite*`, which does NOT match
 * `/merchant-favourite-page` -- the segment starts with "merchant-". So neither interception ever
 * fired, both clicks went to the real server, and the test reported SUCCESS assertions that had
 * passed by coincidence (the server's own toggle happened to produce the same answer the fake body
 * would have) while the FAILURE phase never failed at all. Every interception is now counted and
 * asserted, so a pattern that matches nothing fails loudly instead of quietly passing.
 */
const FAV_URL = '**/merchant-favourite-page*';

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

const READ_STAR = `(function () {
    var b = document.querySelector('[data-ss-favourite]');
    if (!b) { return null; }
    var g = b.querySelector('[data-ss-fav-glyph]');
    var h = b.querySelector('[data-ss-fav-label]');
    var live = document.getElementById('ss-live-region');
    return {
        pressed: b.getAttribute('aria-pressed'),
        busy: b.getAttribute('aria-busy'),
        disabled: !!b.disabled,
        title: b.getAttribute('title') || '',
        glyph: g ? (g.textContent || '').trim() : null,
        hidden: h ? (h.textContent || '').trim() : null,
        live: live ? (live.textContent || '').trim() : null,
        titleOn: b.getAttribute('data-ss-fav-title-on') || '',
        titleOff: b.getAttribute('data-ss-fav-title-off') || '',
    };
})()`;

async function run(page, lang) {
    await page.goto(`${BASE}/order`, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => window.StoreSyriaDS && window.StoreSyriaDS.openCommandPalette(document.body));
    await page.waitForSelector('[data-ss-favourite]', { state: 'attached', timeout: 5000 });

    const before = await page.evaluate(READ_STAR);
    check(`[${lang}] a favourite control is present`, before !== null);
    if (!before) { return; }

    check(`[${lang}] both titles travel with the control`,
        !!before.titleOn && !!before.titleOff && before.titleOn !== before.titleOff,
        `on="${before.titleOn}" off="${before.titleOff}"`);
    check(`[${lang}] the control carries a swappable glyph`, before.glyph !== null, `glyph="${before.glyph}"`);

    // ---------- EXECUTING: hold the response open and sample mid-flight ----------
    let release;
    let hits = 0;
    const held = new Promise(r => { release = r; });
    await page.route(FAV_URL, async route => {
        hits++;
        await held;
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ favourited: before.pressed !== 'true', message: 'done' }),
        });
    });

    await page.click('[data-ss-favourite]');
    await page.waitForFunction(
        () => { var b = document.querySelector('[data-ss-favourite]'); return b && b.getAttribute('aria-busy') === 'true'; },
        { timeout: 4000 }
    ).catch(() => {});
    const during = await page.evaluate(READ_STAR);

    check(`[${lang}] EXECUTING: aria-busy is set while in flight`, during.busy === 'true', `aria-busy="${during.busy}"`);
    check(`[${lang}] EXECUTING: the control is disabled while in flight`, during.disabled === true);
    // `announceLive()` blanks the region and fills it on a 50ms timeout -- that blank frame is
    // what makes two identical messages both audible. Sampling immediately reads the blank, so
    // the wait is for CONTENT rather than for the attribute.
    await page.waitForFunction(() => {
        var r = document.getElementById('ss-live-region');
        return r && (r.textContent || '').trim().length > 0;
    }, { timeout: 4000 }).catch(() => {});
    const announced = await page.evaluate(READ_STAR);

    check(`[${lang}] EXECUTING: the request really was intercepted`, hits === 1, `hits=${hits}`);
    check(`[${lang}] EXECUTING: the live region announced it`,
        !!announced.live && announced.live.length > 0, `live="${announced.live}"`);

    release();
    await page.waitForFunction(
        () => { var b = document.querySelector('[data-ss-favourite]'); return b && !b.hasAttribute('aria-busy'); },
        { timeout: 5000 }
    ).catch(() => {});
    const after = await page.evaluate(READ_STAR);

    // ---------- SUCCESS ----------
    check(`[${lang}] SUCCESS: aria-pressed flipped`,
        after.pressed !== before.pressed, `${before.pressed} -> ${after.pressed}`);
    check(`[${lang}] SUCCESS: aria-busy was cleared`, after.busy === null);
    check(`[${lang}] SUCCESS: the control is enabled again`, after.disabled === false);
    check(`[${lang}] SUCCESS: the glyph changed, so the state is not colour-only`,
        after.glyph !== before.glyph, `"${before.glyph}" -> "${after.glyph}"`);
    check(`[${lang}] SUCCESS: the title is no longer the stale one`,
        after.title !== before.title && after.title.length > 0, `"${before.title}" -> "${after.title}"`);
    if (before.hidden !== null) {
        check(`[${lang}] SUCCESS: the accessible name is no longer the stale one`,
            after.hidden !== before.hidden, `"${before.hidden}" -> "${after.hidden}"`);
    }

    await page.unroute(FAV_URL);

    // ---------- FAILURE: refuse it for real ----------
    let failHits = 0;
    await page.route(FAV_URL, route => {
        failHits++;
        return route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
    });
    const beforeFail = await page.evaluate(READ_STAR);
    await page.click('[data-ss-favourite]');
    await page.waitForFunction(
        () => { var b = document.querySelector('[data-ss-favourite]'); return b && !b.hasAttribute('aria-busy'); },
        { timeout: 5000 }
    ).catch(() => {});
    const afterFail = await page.evaluate(READ_STAR);

    /* WAIT FOR THE ERROR TEXT SPECIFICALLY. The first version waited for the region to be
       non-empty, which the BUSY message already satisfies -- so it reported "it was announced"
       while holding "Saving your change…" and could not have told the two apart. The expected
       string is read from the markup, so this still asserts no copy of its own. */
    const errorText = await page.evaluate(() => {
        var m = document.querySelector('[data-ss-favourite-error]');
        return m ? m.getAttribute('data-ss-favourite-error') : '';
    });
    await page.waitForFunction(expected => {
        var r = document.getElementById('ss-live-region');
        return !!r && (r.textContent || '').trim() === expected;
    }, errorText, { timeout: 4000 }).catch(() => {});
    const failAnnounced = await page.evaluate(READ_STAR);

    check(`[${lang}] FAILURE: the refusal really was served`, failHits === 1, `hits=${failHits}`);
    check(`[${lang}] FAILURE: the announcement is the ERROR text, not the busy text`,
        failAnnounced.live === errorText, `live="${failAnnounced.live}" expected="${errorText}"`);
    check(`[${lang}] FAILURE: the state did NOT flip on a refusal`,
        afterFail.pressed === beforeFail.pressed, `${beforeFail.pressed} -> ${afterFail.pressed}`);
    check(`[${lang}] FAILURE: the control is usable again`, afterFail.disabled === false);
    check(`[${lang}] FAILURE: aria-busy was cleared`, afterFail.busy === null);
    check(`[${lang}] FAILURE: something was announced`,
        !!failAnnounced.live && failAnnounced.live.length > 0, `live="${failAnnounced.live}"`);
    await page.unroute(FAV_URL);
}

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

        await run(page, lang);
        await ctx.close();
    }

    await browser.close();
    process.stdout.write(`\n${failures} check(s) failed\n`);
    process.exit(failures ? 1 : 0);
})();
