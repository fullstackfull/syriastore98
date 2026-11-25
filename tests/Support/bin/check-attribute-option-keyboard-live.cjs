#!/usr/bin/env node
/**
 * GAP-100 / P03-B-07 — the attribute-option screen's keyboard path, driven in a real browser.
 * =================================================================================================
 *
 * WHAT ONLY A BROWSER CAN ANSWER. `AttributeOptionKeyboardPathTest` asserts the DOM the merchant
 * receives — that the controls are native buttons, that they are named, that they carry the
 * position as a description, that the end stops are `aria-disabled` and not `disabled`. None of
 * that proves Tab reaches one, that the focus ring paints, that Enter moves a row, that focus
 * survives the row being reparented, or that "down" means down in Arabic.
 *
 * AND ON THIS SCREEN THERE IS A WHOLE HALF THAT ONLY A BROWSER CAN SEE AT ALL. The sibling
 * sortable persists through a hidden input, so a DOM test can read the value the form would post.
 * This one persists by AJAX. The request is the contract, so this harness INTERCEPTS it and reads
 * the body:
 *
 *   - nothing is posted by merely loading the screen;
 *   - a keyboard move posts the moved order, not the loaded one;
 *   - two posts never overlap, and the last state wins (a keyboard burst is one request per press,
 *     each carrying the WHOLE order, which is a race a drag cannot produce);
 *   - a refused post is announced and the drag still posts.
 *
 * jQuery UI IS LOADED HERE, WHICH IS HALF THE POINT. The sortable and the buttons share the list.
 * jQuery UI 1.12.1's sortable defaults to `cancel: "input, textarea, button, select, option"`
 * (verified in the vendored file), so a mousedown on a button does not start a drag — and
 * `disableSelection()` binds `selectstart` rather than `mousedown` in Chromium, so it does not
 * swallow the click either. Both are verified here by the buttons working at all with
 * `.sortable()` live on the same `ul`.
 *
 * PRECONDITIONS, and it fails loudly rather than reporting a clean pass without them:
 *   - node, and a Playwright install resolvable from this process (PLAYWRIGHT env var, a global
 *     install, or a local node_modules);
 *   - php on PATH, because the renderings come from the Feature test (below).
 *
 * WHY IT RENDERS THROUGH THE TEST SUITE AND NOT A DEV SERVER. The other live harnesses in this
 * repository point a browser at `127.0.0.1:8899` backed by `database/p2dev.sqlite`. That file is
 * ZERO BYTES in this checkout (measured), so there is no server to point at. This runs
 * `AttributeOptionKeyboardPathTest` with `SS_ATTR_OPTION_HTML_DIR` set and takes the bytes it
 * renders: the real route, the real permission gate, the real tenant predicate, the real
 * `order`-sorted query, and the real Arabic strings.
 *
 * The harness page is then assembled from those bytes and the repository's own
 * `public/js/jquery-3.6.0.min.js`, `public/js/jquery-ui.min.js`, `public/css/store-syria-ds.css`
 * and `public/css/store-syria-a11y.css`. The list markup and the inline script are copied
 * VERBATIM; only the page chrome around them is stand-in, and the chrome is not under test.
 *
 * usage: node tests/Support/bin/check-attribute-option-keyboard-live.cjs
 */

'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const REPO = path.resolve(__dirname, '../../..');

function playwright() {
    const candidates = [
        process.env.PLAYWRIGHT,
        'playwright',
        '/opt/node-tools/node_modules/playwright',
        path.join(REPO, 'node_modules/playwright'),
    ].filter(Boolean);

    for (const c of candidates) {
        try {
            return require(c);
        } catch (e) { /* try the next one */ }
    }

    throw new Error('PRECONDITION: playwright is not resolvable. Set PLAYWRIGHT to its path.');
}

function renderings(dir) {
    execFileSync('php', ['artisan', 'test', 'tests/Feature/DesignSystem/AttributeOptionKeyboardPathTest.php'], {
        cwd: REPO,
        stdio: 'inherit',
        env: Object.assign({}, process.env, { SS_ATTR_OPTION_HTML_DIR: dir }),
    });

    const out = {};

    for (const lang of ['en', 'ar']) {
        const file = path.join(dir, `attribute-option-${lang}.html`);

        if (!fs.existsSync(file)) {
            throw new Error(`PRECONDITION: the Feature test wrote no ${lang} rendering to ${dir}.`);
        }

        out[lang] = fs.readFileSync(file, 'utf8');
    }

    return out;
}

/* The fragments under test, lifted verbatim out of a real rendering. */
function fragments(html) {
    const list = /<ul class="ss-sort-list sortable[\s\S]*?<\/ul>/.exec(html);
    const region = /<div class="ss-visually-hidden" role="status"[\s\S]*?<\/div>/.exec(html);
    const inline = (html.match(/<script>[\s\S]*?<\/script>/g) || [])
        .filter((s) => s.includes('data-ss-option-move'));

    if (!list || !region) {
        throw new Error('PRECONDITION: the rendering does not contain the sortable list and the live region.');
    }

    if (inline.length !== 1) {
        throw new Error(`PRECONDITION: expected one inline reorder script in the rendering, found ${inline.length}.`);
    }

    const script = inline[0].replace(/^<script>/, '').replace(/<\/script>$/, '');
    const endpoint = /var ENDPOINT = "([^"]+)"/.exec(script);

    if (!endpoint) {
        throw new Error('PRECONDITION: the inline script no longer declares a literal ENDPOINT, so '
            + 'this harness cannot know which request to intercept.');
    }

    /* The option ids the rendering actually carries, read out rather than invented. */
    const ids = [];
    const row = /<li class="ss-sort-item" data-id="([^"]+)"/g;
    let hit;

    while ((hit = row.exec(list[0])) !== null) {
        ids.push(hit[1]);
    }

    if (ids.length < 3) {
        throw new Error(`PRECONDITION: the rendering has ${ids.length} option row(s); at least 3 are needed `
            + 'to tell "moved up" from "moved to the top".');
    }

    return { list: list[0], region: region[0], script, endpoint: endpoint[1], ids };
}

function harness(dir, direction, parts) {
    for (const asset of [
        'js/jquery-3.6.0.min.js', 'js/jquery-ui.min.js',
        'css/store-syria-ds.css', 'css/store-syria-a11y.css',
    ]) {
        fs.copyFileSync(path.join(REPO, 'public', asset), path.join(dir, path.basename(asset)));
    }

    /* Minimal stand-in chrome. The host theme's own stylesheets are deliberately NOT loaded: this
       script measures behaviour, and `*:focus-visible` in store-syria-a11y.css is the rule that
       restores the ring the host suppresses, so loading only the first-party pair proves the ring
       comes from the first-party file rather than from a theme default.

       `show_toastr` is stubbed because the host defines it globally and the error path calls it;
       without the stub a refused POST would throw inside its own error handler and the failure
       under test would be masked by a ReferenceError. The csrf meta is the one the script reads. */
    const page = `<!doctype html>
<html lang="${direction === 'rtl' ? 'ar' : 'en'}" dir="${direction}">
<head><meta charset="utf-8">
<meta name="csrf-token" content="harness-token">
<link rel="stylesheet" href="store-syria-ds.css">
<link rel="stylesheet" href="store-syria-a11y.css">
<style>
body { background: #f8f9fd; font-family: sans-serif; margin: 0; padding: 16px; }
.ss-card { background: #fff; }
</style>
</head>
<body>
<a href="#" id="seed">seed</a>
<div class="ss-scope"><div class="ss-card">
${parts.list}
</div></div>
${parts.region}
<script src="jquery-3.6.0.min.js"></script>
<script src="jquery-ui.min.js"></script>
<script>
window.__toastrs = [];
window.show_toastr = function (title, message, type) { window.__toastrs.push([title, message, type]); };
</script>
<script>${parts.script}</script>
</body></html>`;

    const file = path.join(dir, `harness-${direction}.html`);
    fs.writeFileSync(file, page);

    return file;
}

let passed = 0;
let failed = 0;

function check(name, ok, detail) {
    ok ? passed++ : failed++;
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  -- ' + detail : ''}`);
}

/* The posted `terms[]`, out of a urlencoded body, in order. */
function postedOrder(body) {
    const out = [];

    for (const pair of String(body || '').split('&')) {
        const [k, v] = pair.split('=');

        if (decodeURIComponent(String(k).replace(/\+/g, ' ')).startsWith('terms[')) {
            out.push(decodeURIComponent(String(v || '').replace(/\+/g, ' ')));
        }
    }

    return out;
}

(async () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ss-attr-kb-'));
    const html = renderings(dir);
    const { chromium } = playwright();
    const browser = await chromium.launch();

    /* The LTR run uses the English rendering and the RTL run the Arabic one, so the Arabic labels
       and the Arabic page are what is actually driven in the RTL case. */
    for (const [direction, lang] of [['ltr', 'en'], ['rtl', 'ar']]) {
        const parts = fragments(html[lang]);
        const EXPECTED = parts.ids;
        const endpointPath = new URL(parts.endpoint).pathname;
        const file = harness(dir, direction, parts);

        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(String(e)));

        /* The reorder POST, intercepted. `posts` is every body seen, in arrival order; `live`
           counts how many are being held at once, which is what proves serialisation. */
        const posts = [];
        let live = 0;
        let maxLive = 0;
        let hold = 0;
        let refuse = false;

        await page.route(
            (url) => url.pathname === endpointPath,
            async (route) => {
                live++;
                maxLive = Math.max(maxLive, live);
                posts.push(postedOrder(route.request().postData()));

                if (hold) {
                    await new Promise((r) => setTimeout(r, hold));
                }

                live--;

                await route.fulfill({
                    status: refuse ? 403 : 200,
                    contentType: 'application/json',
                    headers: { 'access-control-allow-origin': '*' },
                    body: refuse ? JSON.stringify({ error: 'Permission denied.' })
                        : JSON.stringify({ success: true }),
                });
            }
        );

        await page.goto('file://' + file);
        await page.waitForTimeout(300);

        const L = (s) => `[${direction}] ${s}`;
        const order = () => page.$$eval('[data-ss-option-item]', (ns) => ns.map((n) => n.dataset.id));
        const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
        const status = () => page.$eval('#ss-sort-status', (n) => n.textContent.trim());

        check(L('the page runs without a script error'), errors.length === 0, errors.join(' | '));
        check(L(`the document really is ${direction}`),
            (await page.evaluate(() => getComputedStyle(document.body).direction)) === direction);
        check(L('the rendered rows are the screen\'s own option ids'), same(await order(), EXPECTED),
            JSON.stringify(await order()));

        /* ---- a page load must not persist anything ---- */
        check(L('merely loading the screen posts nothing'), posts.length === 0,
            `${posts.length} request(s): ${JSON.stringify(posts)}`);

        /* ---- the end stops the one writer computed on load ---- */
        check(L('the first row\'s up control is inert on load'),
            await page.$eval(`[data-id="${EXPECTED[0]}"] [data-ss-option-move="up"]`,
                (n) => n.getAttribute('aria-disabled') === 'true'));
        check(L('the last row\'s down control is inert on load'),
            await page.$eval(`[data-id="${EXPECTED[EXPECTED.length - 1]}"] [data-ss-option-move="down"]`,
                (n) => n.getAttribute('aria-disabled') === 'true'));
        check(L('no middle row is inert on load'),
            await page.$$eval('[data-ss-option-item]', (ns) => ns.slice(1, -1).every(
                (n) => !n.querySelector('[aria-disabled="true"]'))));

        /* ---- reachable by Tab, with a ring and a name. No pointer is used from here on. ---- */
        await page.focus('#seed');
        let hops = 0;
        let landed = null;

        while (hops < 12) {
            await page.keyboard.press('Tab');
            hops++;
            landed = await page.evaluate(() => {
                const a = document.activeElement;

                return a ? {
                    tag: a.tagName,
                    move: a.getAttribute('data-ss-option-move'),
                    row: a.closest('[data-ss-option-item]')
                        ? a.closest('[data-ss-option-item]').dataset.id : null,
                    name: (a.textContent || '').trim(),
                    describedBy: a.getAttribute('aria-describedby'),
                    description: a.getAttribute('aria-describedby')
                        ? (document.getElementById(a.getAttribute('aria-describedby')) || {}).textContent
                        : null,
                    ring: getComputedStyle(a).outlineWidth,
                } : null;
            });

            if (landed && landed.move) { break; }
        }

        check(L('a move control is reachable by Tab'), !!(landed && landed.move),
            `after ${hops} Tab press(es): ${JSON.stringify(landed)}`);
        check(L('the focused control shows a visible focus ring'),
            !!(landed && parseFloat(landed.ring) >= 1), `outline-width=${landed && landed.ring}`);
        check(L('the focused control is announced with its option and direction'),
            !!(landed && landed.name && landed.row && landed.name !== landed.move), landed && landed.name);
        check(L('and with its current position as a description'),
            !!(landed && /\b1\b/.test(String(landed.description || ''))), landed && landed.description);

        /* ---- moving, by keyboard only ---- */
        const mover = EXPECTED[2];
        const focusMove = (id, dir) => page.evaluate(([i, d]) => document.querySelector(
            `[data-ss-option-item][data-id="${i}"] [data-ss-option-move="${d}"]`).focus(), [id, dir]);

        await focusMove(mover, 'up');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(250);

        const want = EXPECTED.slice();
        want.splice(2, 1);
        want.splice(1, 0, mover);

        check(L('Enter on a move-up control moves the row'), same(await order(), want),
            JSON.stringify(await order()));
        check(L('the keyboard move posted exactly one reorder request'), posts.length === 1,
            `${posts.length}: ${JSON.stringify(posts)}`);
        check(L('and it carried the MOVED order, not the loaded one'),
            same(posts[posts.length - 1], want),
            `${JSON.stringify(posts[posts.length - 1])} vs ${JSON.stringify(want)}`);
        check(L('focus stayed on the same control, so a second press works'),
            await page.evaluate((i) => {
                const a = document.activeElement;

                return !!a && a.getAttribute('data-ss-option-move') === 'up'
                    && a.closest('[data-ss-option-item]').dataset.id === i;
            }, mover));
        check(L('the position description was rewritten'),
            /\b2\b/.test(await page.$eval(`[data-id="${mover}"] [data-ss-option-position]`,
                (n) => n.textContent.trim())),
            await page.$eval(`[data-id="${mover}"] [data-ss-option-position]`, (n) => n.textContent.trim()));
        check(L('the move was announced in the live region'), (await status()) !== '', await status());
        check(L('the announcement is not the English fallback'),
            direction !== 'rtl' || /[؀-ۿ]/.test(await status()), await status());

        await page.keyboard.press('Space');
        await page.waitForTimeout(250);
        check(L('Space moves it again, so the control is not Enter-only'),
            (await order())[0] === mover, JSON.stringify(await order()));

        /* ---- the end stop is inert, still focusable, and refuses the activation ---- */
        const stop = `[data-ss-option-item][data-id="${mover}"] [data-ss-option-move="up"]`;
        check(L('the now-first row\'s up control went inert'),
            await page.$eval(stop, (n) => n.getAttribute('aria-disabled') === 'true'));
        check(L('the inert control is still in the tab order'),
            await page.$eval(stop, (n) => !n.hasAttribute('disabled') && n.tabIndex >= 0));

        const beforeStop = await order();
        const postsBeforeStop = posts.length;
        await page.keyboard.press('Enter');
        await page.waitForTimeout(250);
        check(L('Enter on the inert end stop changes nothing'), same(await order(), beforeStop),
            JSON.stringify(await order()));
        check(L('and posts nothing'), posts.length === postsBeforeStop,
            `${posts.length - postsBeforeStop} extra request(s)`);

        /* ---- and down means down in both directions ---- */
        await focusMove(mover, 'down');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(250);
        check(L('"down" moves the row LATER in the list, in this direction too'),
            (await order()).indexOf(mover) === 1, JSON.stringify(await order()));

        const boxes = await page.$$eval('[data-ss-option-item]',
            (ns) => ns.map((n) => ({ id: n.dataset.id, y: Math.round(n.getBoundingClientRect().top) })));
        check(L('document order still matches visual top-to-bottom order'),
            boxes.every((b, i) => i === 0 || b.y >= boxes[i - 1].y), JSON.stringify(boxes));

        /* ---- a keyboard BURST does not race itself ----
           Each POST carries the whole order, so two in flight can be applied by the server in the
           wrong sequence and persist a list the merchant never saw. A drag cannot produce this —
           it is one gesture and one request — which is why it needed its own measurement here. */
        hold = 400;
        posts.length = 0;
        maxLive = 0;
        await focusMove(mover, 'down');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(40);
        await page.keyboard.press('Enter');
        await page.waitForTimeout(40);
        await page.keyboard.press('Enter');
        await page.waitForTimeout(1600);
        hold = 0;

        const burst = await order();
        check(L('three rapid presses never had two requests in flight at once'), maxLive === 1,
            `max concurrent = ${maxLive}`);
        check(L('and the last request carries the order left on screen'),
            posts.length > 0 && same(posts[posts.length - 1], burst),
            `${JSON.stringify(posts[posts.length - 1])} vs ${JSON.stringify(burst)}`);

        /* ---- a refused reorder is announced rather than swallowed ----
           The route answers 403 with {error} when a posted id is not this tenant's. */
        refuse = true;
        posts.length = 0;
        await focusMove(burst[burst.length - 1], 'up');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        refuse = false;

        check(L('a refused reorder reaches the live region'),
            /[^\s]/.test(await status()) && (await status()) !== '', await status());
        check(L('and raises a toast'),
            (await page.evaluate(() => window.__toastrs.length)) > 0,
            JSON.stringify(await page.evaluate(() => window.__toastrs)));

        /* ---- the DRAG still persists, which this slice could have broken ----
           The drag used to build the posted id list itself; it now goes through the same writer as
           the keyboard path. If jQuery UI's `stop` callback were not registered, or `cancel` let a
           mousedown on a button start a drag, the regression would be silent: the rows would still
           reorder on screen and nothing would be saved. */
        posts.length = 0;
        const dragged = (await order())[0];
        const box = (n) => page.$eval(n, (el) => {
            const r = el.getBoundingClientRect();

            return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
        });
        const from = await box('[data-ss-option-item]:nth-child(1) .ss-sort-item__label');
        const to = await box('[data-ss-option-item]:nth-child(3) .ss-sort-item__label');

        await page.mouse.move(from.x, from.y);
        await page.mouse.down();
        for (let step = 1; step <= 8; step++) {
            await page.mouse.move(from.x + ((to.x - from.x) * step) / 8, from.y + ((to.y - from.y) * step) / 8);
            await page.waitForTimeout(20);
        }
        await page.mouse.up();
        await page.waitForTimeout(400);

        const afterDrag = await order();
        check(L('a mouse drag still reorders the list'), afterDrag[0] !== dragged,
            `${dragged} is still first: ${JSON.stringify(afterDrag)}`);
        check(L('a mouse drag still posts the reorder'), posts.length === 1,
            `${posts.length} request(s)`);
        check(L('and the posted order is what is on screen'),
            posts.length > 0 && same(posts[posts.length - 1], afterDrag),
            `${JSON.stringify(posts[posts.length - 1])} vs ${JSON.stringify(afterDrag)}`);
        check(L('a mouse drag refreshes the position descriptions too'),
            await page.$$eval('[data-ss-option-item]', (ns) => ns.every((n, i) => {
                const t = n.querySelector('[data-ss-option-position]').textContent;

                return new RegExp('\\b' + (i + 1) + '\\b').test(t);
            })),
            await page.$$eval('[data-ss-option-item] [data-ss-option-position]',
                (ns) => ns.map((n) => n.textContent.trim()).join(' | ')));
        check(L('a mouse drag leaves the end stops correct'),
            await page.$$eval('[data-ss-option-item]', (ns) => ns.every((n, i) => {
                const up = n.querySelector('[data-ss-option-move="up"]');
                const down = n.querySelector('[data-ss-option-move="down"]');

                return (up.getAttribute('aria-disabled') === 'true') === (i === 0)
                    && (down.getAttribute('aria-disabled') === 'true') === (i === ns.length - 1);
            })));

        await page.close();
    }

    await browser.close();
    console.log(`\n${passed} passed, ${failed} failed`);
    process.exit(failed ? 1 : 0);
})().catch((e) => {
    console.error(String(e && e.message ? e.message : e));
    process.exit(2);
});
