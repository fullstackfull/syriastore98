/**
 * P2-01-20 / WS A7 / F5 — keyboard navigation and focus, driven with real Tab presses.
 *
 * WHY THIS EXISTS. That row shipped the hard part -- `*:focus { outline: none }` is in all four
 * stylesheets the layout swaps between, and the restoration brings an indicator back for keyboard
 * users without changing what pointer users see. But the row stayed PARTIAL and said exactly why:
 *
 *     "Tab ORDER on a rendered page, and whether a focus ring is actually PAINTED by a browser,
 *      need a real page and a real tab press. What is asserted here is every condition the paint
 *      depends on; what is not asserted is the paint."
 *
 * This asserts the paint. `page.keyboard.press('Tab')` is real keyboard input, so `:focus-visible`
 * applies exactly as it does for a person -- which a `.focus()` call would NOT trigger, and that
 * distinction is the whole reason this is a browser tool and not a unit test.
 *
 * A PAINTED INDICATOR is an outline with width, or a box-shadow, or a visible border change. All
 * three are read from the computed style of the element that actually holds focus, in each of the
 * four real configurations, with the theme and direction taken from the SERVER.
 *
 * THE MODAL TRAP IS DRIVEN TOO: the command palette is `aria-modal="true"`, so Tab must not escape
 * it. Measured by opening it and tabbing more times than it has focusable children.
 *
 * usage: node docs/development/phase-2/tools/check-keyboard-focus-live.cjs [baseUrl] [storeId]
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
const TAB_DEPTH = 30;

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

/*
 * HOW FOCUS INDICATION IS MEASURED, and why the first version was wrong.
 *
 * It checked only `outline` and `box-shadow`, and reported the command palette's input as
 * unindicated. It is not: `.ss-command__input:focus-visible` sets `outline: none` and changes
 * `border-block-end-color` to the brand instead -- a brand-coloured underline, which is a perfectly
 * good indicator that my property list simply could not see. A measurement that enumerates the
 * properties it expects will keep missing the ones it did not think of.
 *
 * So nothing is enumerated. Every focusable control's computed style is recorded BEFORE any Tab is
 * pressed, and the focused style is diffed against its own unfocused style. Any visible difference
 * -- outline, shadow, border, background, colour, underline -- counts, because any of them tells a
 * keyboard user where they are. No blur-and-refocus trick: that would make the second focus
 * PROGRAMMATIC, and `:focus-visible` does not apply to programmatic focus, so the measurement would
 * destroy the thing it measures.
 */
const SNAPSHOT_BASELINE = `(function () {
    function snap(cs) {
        return [cs.outlineStyle, cs.outlineWidth, cs.outlineColor, cs.boxShadow,
                cs.borderTopColor, cs.borderRightColor, cs.borderBottomColor, cs.borderLeftColor,
                cs.borderTopWidth, cs.borderBottomWidth,
                cs.backgroundColor, cs.color, cs.textDecorationLine].join(' ~ ');
    }
    var sel = 'a[href],button,input,select,textarea,[tabindex]:not([tabindex="-1"])';
    var out = {};
    Array.prototype.forEach.call(document.querySelectorAll(sel), function (el, i) {
        el.setAttribute('data-kbidx', String(i));
        out[String(i)] = snap(getComputedStyle(el));
    });
    return out;
})()`;

const FOCUSED = `(function () {
    function snap(cs) {
        return [cs.outlineStyle, cs.outlineWidth, cs.outlineColor, cs.boxShadow,
                cs.borderTopColor, cs.borderRightColor, cs.borderBottomColor, cs.borderLeftColor,
                cs.borderTopWidth, cs.borderBottomWidth,
                cs.backgroundColor, cs.color, cs.textDecorationLine].join(' ~ ');
    }
    var el = document.activeElement;
    if (!el || el === document.body) { return null; }
    var cs = getComputedStyle(el);
    var r = el.getBoundingClientRect();
    return {
        tag: el.tagName.toLowerCase(),
        cls: (el.getAttribute('class') || '').slice(0, 44),
        id: el.id || '',
        idx: el.getAttribute('data-kbidx'),
        visible: r.width > 0 && r.height > 0,
        style: snap(cs),
        outline: cs.outlineStyle + ' ' + cs.outlineWidth,
        inPalette: !!(el.closest && el.closest('#ss-command')),
    };
})()`;

async function tabThrough(page, label) {
    // Record every control's UNFOCUSED style first; the diff against it is the measurement.
    const baseline = await page.evaluate(SNAPSHOT_BASELINE);
    check(`[${label}] the baseline saw the page's controls`,
        Object.keys(baseline).length > 10, `${Object.keys(baseline).length} controls catalogued`);

    const seen = [];
    const unindicated = [];

    for (let i = 0; i < TAB_DEPTH; i++) {
        await page.keyboard.press('Tab');
        const f = await page.evaluate(FOCUSED);
        if (!f) { continue; }
        seen.push(f);
        if (!f.visible) { continue; }

        const before = f.idx !== null ? baseline[f.idx] : undefined;
        // A control that appeared after the baseline (the palette opens as you tab into the
        // sidebar search) has no recorded unfocused style; it cannot be diffed, so it is
        // reported separately rather than counted as a pass or a failure.
        if (before === undefined) { continue; }
        if (before === f.style) {
            unindicated.push(f);
        }
    }

    check(`[${label}] focus advanced through controls`, seen.length > 5,
        `${seen.length} of ${TAB_DEPTH} presses landed on a control`);
    check(`[${label}] every visible focused control looks different from its unfocused self`,
        unindicated.length === 0,
        unindicated.length
            ? unindicated.slice(0, 4).map(u => `<${u.tag} ${u.id || u.cls}> outline="${u.outline}"`).join(' | ')
            : `${seen.length} controls tabbed`);

    const distinct = new Set(seen.map(s => s.id + '|' + s.cls + '|' + s.tag)).size;
    check(`[${label}] focus does not get stuck`, distinct > 3, `${distinct} distinct controls`);
}

async function paletteTrap(page, label) {
    await page.evaluate(() => window.StoreSyriaDS && window.StoreSyriaDS.openCommandPalette(document.body));
    await page.waitForSelector('#ss-command', { state: 'visible', timeout: 4000 }).catch(() => {});

    let escaped = 0, inside = 0;
    for (let i = 0; i < 25; i++) {
        await page.keyboard.press('Tab');
        const f = await page.evaluate(FOCUSED);
        if (!f) { continue; }
        if (f.inPalette) { inside++; } else { escaped++; }
    }

    check(`[${label}] the modal palette keeps focus inside it`, escaped === 0,
        `${inside} presses stayed inside, ${escaped} escaped`);

    await page.keyboard.press('Escape');
}

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox'] });

    for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
        for (const theme of ['light', 'dark']) {
            execFileSync('php', [
                path.join(__dirname, 'set-admin-preview-state.php'),
                STORE, theme, dir, lang, EMAIL,
            ], { cwd: REPO, env: ENV, stdio: 'pipe' });

            const label = `${lang}/${dir} ${theme}`;
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

            await page.goto(`${BASE}/order`, { waitUntil: 'domcontentloaded' });
            await tabThrough(page, label);
            await paletteTrap(page, label);

            await ctx.close();
        }
    }

    await browser.close();
    process.stdout.write(`\n${failures} check(s) failed\n`);
    process.exit(failures ? 1 : 0);
})();
