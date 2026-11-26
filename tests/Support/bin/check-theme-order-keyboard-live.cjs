#!/usr/bin/env node
/**
 * GAP-100 / P03-B-07 — the theme section-order screen's keyboard path, driven in a real browser.
 * =================================================================================================
 *
 * WHAT ONLY A BROWSER CAN ANSWER. `ThemeOrderKeyboardPathTest` asserts the DOM the merchant
 * receives — that the controls are native buttons, that they are named, that they carry the
 * position as a description, that the end stops are `aria-disabled` and not `disabled`. None of
 * that proves Tab actually reaches one, that the focus ring paints, that Enter moves a row, that
 * the hidden input the form posts follows the move, that focus survives the row being reparented,
 * or that "down" means down in Arabic. Those are the things that break, and they are why the
 * project's own measurement — "neither first-party sortable has any keyboard path, a WCAG 2.1.1
 * and 2.5.7 failure" (`PHASE-03-M4-THEME-EDITOR-PLAN.md:298`) — needed an instrument and not a
 * reading.
 *
 * jQuery UI IS LOADED HERE, WHICH IS HALF THE POINT. The sortable and the buttons share the list.
 * jQuery UI 1.12.1's sortable defaults to `cancel: "input, textarea, button, select, option"`, so
 * a mousedown on a button does not start a drag — verified in the vendored file and then verified
 * again here by the buttons working at all with `.sortable()` live on the same `ul`.
 *
 * PRECONDITIONS, and it fails loudly rather than reporting a clean pass without them:
 *   - node, and a Playwright install resolvable from this process (PLAYWRIGHT env var, a global
 *     install, or a local node_modules);
 *   - a Chromium-family binary (CHROME env var, else /usr/bin/google-chrome);
 *   - php on PATH, because the renderings come from the Feature test (below).
 *
 * WHY IT RENDERS THROUGH THE TEST SUITE AND NOT A DEV SERVER. The other live harnesses in this
 * repository point a browser at `127.0.0.1:8899` backed by `database/p2dev.sqlite`. That cannot
 * reach this screen. The file is ZERO BYTES in a fresh checkout (measured), and M4-7 measured that
 * even seeded the fixture does not hold `Manage Themes` — which all four theme GETs require, and
 * which is why the four `_theme_customize__*.png` captures in the Phase 2.5 evidence folder are
 * pictures of the dashboard. So this script runs `ThemeOrderKeyboardPathTest` with
 * `SS_ORDER_HTML_DIR` set and takes the bytes it renders: the real route, the real permission
 * gate, the real `ThemeEditorGate`, the real `pages.json`.
 *
 * The harness page is then assembled from those bytes and the repository's own
 * `public/js/jquery-3.6.0.min.js`, `public/js/jquery-ui.min.js`, `public/css/store-syria-ds.css`
 * and `public/css/store-syria-a11y.css`. The list markup and the inline script are copied
 * VERBATIM; only the page chrome around them is stand-in, and the chrome is not under test.
 *
 * usage: node tests/Support/bin/check-theme-order-keyboard-live.cjs
 */

'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const REPO = path.resolve(__dirname, '../../..');
const CHROME = process.env.CHROME || '/usr/bin/google-chrome';

/* The nine tokens themes/stylique/theme_json/pages.json declares for the orderable `home` page.
   Read from the file rather than written down, so a theme change fails here instead of silently
   measuring a list this script invented. */
const EXPECTED = (() => {
    const pages = JSON.parse(fs.readFileSync(path.join(REPO, 'themes/stylique/theme_json/pages.json'), 'utf8'));
    const home = (Array.isArray(pages) ? pages : Object.values(pages))
        .find((p) => p && p.slug === 'home' && p.is_order === true);

    if (!home || !home.orders) {
        throw new Error('PRECONDITION: stylique/home is no longer an orderable page with an `orders` list.');
    }

    return String(home.orders).split(',').map((s) => s.trim()).filter(Boolean);
})();

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
    execFileSync('php', ['artisan', 'test', 'tests/Feature/Theme/ThemeOrderKeyboardPathTest.php'], {
        cwd: REPO,
        stdio: 'inherit',
        env: Object.assign({}, process.env, { SS_ORDER_HTML_DIR: dir }),
    });

    const out = {};

    for (const lang of ['en', 'ar']) {
        const file = path.join(dir, `order-${lang}.html`);

        if (!fs.existsSync(file)) {
            throw new Error(`PRECONDITION: the Feature test wrote no ${lang} rendering to ${dir}.`);
        }

        out[lang] = fs.readFileSync(file, 'utf8');
    }

    return out;
}

/* The two fragments under test, lifted verbatim out of a real rendering. */
function fragments(html) {
    const list = /<ul class="list-unstyled list-group sortable[\s\S]*?<\/ul>/.exec(html);
    const hidden = /<input type="hidden" name="orders"[^>]*>/.exec(html);
    const region = /<div class="ss-visually-hidden" role="status"[\s\S]*?<\/div>/.exec(html);
    const inline = (html.match(/<script>[\s\S]*?<\/script>/g) || [])
        .filter((s) => s.includes('data-ss-order-move'));

    if (!list || !hidden || !region) {
        throw new Error('PRECONDITION: the rendering does not contain the list, the hidden input and the live region.');
    }

    if (inline.length !== 1) {
        throw new Error(`PRECONDITION: expected one inline order script in the rendering, found ${inline.length}.`);
    }

    return {
        list: list[0],
        hidden: hidden[0],
        region: region[0],
        script: inline[0].replace(/^<script>/, '').replace(/<\/script>$/, ''),
    };
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
       comes from the first-party file rather than from a theme default. */
    const page = `<!doctype html>
<html lang="en" dir="${direction}">
<head><meta charset="utf-8">
<link rel="stylesheet" href="store-syria-ds.css">
<link rel="stylesheet" href="store-syria-a11y.css">
<style>
body { background: #f8f9fd; font-family: sans-serif; margin: 0; padding: 16px; }
.card { background: #fff; padding: 16px; }
.list-unstyled { list-style: none; padding: 0; }
.list-group-item { display: flex; align-items: center; justify-content: space-between;
                   padding: 10px; border-bottom: 1px solid #ddd; }
</style>
</head>
<body>
<a href="#" id="seed">seed</a>
<div class="card"><form>${parts.hidden}
${parts.list}
</form></div>
${parts.region}
<script src="jquery-3.6.0.min.js"></script>
<script src="jquery-ui.min.js"></script>
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

(async () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ss-order-kb-'));
    const html = renderings(dir);
    const { chromium } = playwright();
    const browser = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });

    /* The LTR run uses the English rendering and the RTL run the Arabic one, so the Arabic
       labels and the Arabic page are what is actually driven in the RTL case. */
    for (const [direction, lang] of [['ltr', 'en'], ['rtl', 'ar']]) {
        const file = harness(dir, direction, fragments(html[lang]));
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(String(e)));
        await page.goto('file://' + file);
        await page.waitForTimeout(250);

        const L = (s) => `[${direction}] ${s}`;
        const order = () => page.$$eval('[data-ss-order-item]', (ns) => ns.map((n) => n.dataset.id));
        const hiddenValue = () => page.$eval('#hidden_order', (n) => n.value);
        const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

        check(L('the page runs without a script error'), errors.length === 0, errors.join(' | '));
        check(L(`the document really is ${direction}`),
            (await page.evaluate(() => getComputedStyle(document.body).direction)) === direction);
        check(L('the rendered rows are the theme\'s nine sections'), same(await order(), EXPECTED),
            JSON.stringify(await order()));
        check(L('the hidden input is filled on load'), (await hiddenValue()) === EXPECTED.join(','),
            await hiddenValue());

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
                    move: a.getAttribute('data-ss-order-move'),
                    row: a.closest('[data-ss-order-item]') ? a.closest('[data-ss-order-item]').dataset.id : null,
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
        check(L('the focused control is announced with its section and direction'),
            !!(landed && landed.name && landed.row && landed.name !== landed.move), landed && landed.name);
        check(L('and with its current position as a description'),
            !!(landed && /\b1\b/.test(String(landed.description || ''))), landed && landed.description);

        /* ---- moving, by keyboard only ---- */
        await page.evaluate(() => document.querySelector(
            '[data-ss-order-item][data-id="product"] [data-ss-order-move="up"]').focus());
        await page.keyboard.press('Enter');
        await page.waitForTimeout(120);

        let want = EXPECTED.slice();
        want.splice(want.indexOf('product'), 1);
        want.splice(EXPECTED.indexOf('product') - 1, 0, 'product');

        check(L('Enter on a move-up control moves the row'), same(await order(), want),
            JSON.stringify(await order()));
        check(L('the hidden input the form posts followed the move'),
            (await hiddenValue()) === want.join(','), await hiddenValue());
        check(L('focus stayed on the same control, so a second press works'),
            await page.evaluate(() => {
                const a = document.activeElement;

                return !!a && a.getAttribute('data-ss-order-move') === 'up'
                    && a.closest('[data-ss-order-item]').dataset.id === 'product';
            }));
        check(L('the position description was rewritten'),
            /\b2\b/.test(await page.$eval(
                '[data-ss-order-item][data-id="product"] [data-ss-order-position]',
                (n) => n.textContent.trim())),
            await page.$eval('[data-ss-order-item][data-id="product"] [data-ss-order-position]',
                (n) => n.textContent.trim()));
        check(L('the move was announced in the live region'),
            (await page.$eval('#ss-order-status', (n) => n.textContent)).trim() !== '',
            await page.$eval('#ss-order-status', (n) => n.textContent));

        await page.keyboard.press('Space');
        await page.waitForTimeout(120);
        check(L('Space moves it again, so the control is not Enter-only'),
            (await order())[0] === 'product', JSON.stringify(await order()));

        /* ---- the end stop is inert, still focusable, and refuses the activation ---- */
        const stop = '[data-ss-order-item][data-id="product"] [data-ss-order-move="up"]';
        check(L('the now-first row\'s up control went inert'),
            await page.$eval(stop, (n) => n.getAttribute('aria-disabled') === 'true'));
        check(L('the inert control is still in the tab order'),
            await page.$eval(stop, (n) => !n.hasAttribute('disabled') && n.tabIndex >= 0));

        const before = await order();
        await page.keyboard.press('Enter');
        await page.waitForTimeout(120);
        check(L('Enter on the inert end stop changes nothing'), same(await order(), before),
            JSON.stringify(await order()));

        /* ---- and down means down in both directions ---- */
        await page.evaluate(() => document.querySelector(
            '[data-ss-order-item][data-id="product"] [data-ss-order-move="down"]').focus());
        await page.keyboard.press('Enter');
        await page.waitForTimeout(120);
        check(L('"down" moves the row LATER in the list, in this direction too'),
            (await order()).indexOf('product') === 1, JSON.stringify(await order()));

        const boxes = await page.$$eval('[data-ss-order-item]',
            (ns) => ns.map((n) => ({ id: n.dataset.id, y: Math.round(n.getBoundingClientRect().top) })));
        check(L('document order still matches visual top-to-bottom order'),
            boxes.every((b, i) => i === 0 || b.y >= boxes[i - 1].y), JSON.stringify(boxes));

        /* ---- the DRAG still persists, which this slice could have broken ----
           The drag used to build the hidden-input string itself; it now goes through the same
           writer as the keyboard path. If jQuery UI's `stop` callback were not registered, or
           `cancel` let a mousedown on a button start a drag, the regression would be silent: the
           rows would still reorder on screen and Save Changes would post the old order. */
        const dragged = (await order())[0];
        const from = await page.$eval('[data-ss-order-item]:nth-child(1) h6',
            (n) => { const r = n.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; });
        const to = await page.$eval('[data-ss-order-item]:nth-child(3) h6',
            (n) => { const r = n.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; });

        await page.mouse.move(from.x, from.y);
        await page.mouse.down();
        for (let step = 1; step <= 8; step++) {
            await page.mouse.move(from.x + ((to.x - from.x) * step) / 8, from.y + ((to.y - from.y) * step) / 8);
            await page.waitForTimeout(20);
        }
        await page.mouse.up();
        await page.waitForTimeout(200);

        const afterDrag = await order();
        check(L('a mouse drag still reorders the list'), afterDrag[0] !== dragged,
            `${dragged} is still first: ${JSON.stringify(afterDrag)}`);
        check(L('a mouse drag still writes the hidden input the form posts'),
            (await hiddenValue()) === afterDrag.join(','),
            `${await hiddenValue()} vs ${afterDrag.join(',')}`);
        check(L('a mouse drag refreshes the position descriptions too'),
            await page.$$eval('[data-ss-order-item]', (ns) => ns.every((n, i) => {
                const t = n.querySelector('[data-ss-order-position]').textContent;
                return new RegExp('\\b' + (i + 1) + '\\b').test(t);
            })),
            await page.$$eval('[data-ss-order-item] [data-ss-order-position]',
                (ns) => ns.map((n) => n.textContent.trim()).join(' | ')));

        await page.close();
    }

    await browser.close();
    console.log(`\n${passed} passed, ${failed} failed`);
    process.exit(failed ? 1 : 0);
})().catch((e) => {
    console.error(String(e && e.message ? e.message : e));
    process.exit(2);
});
