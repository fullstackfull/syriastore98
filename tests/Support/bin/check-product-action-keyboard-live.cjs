#!/usr/bin/env node
/**
 * GAP-256 / P03-B-07 — the product card's conversion actions, driven by a real keyboard.
 * =================================================================================================
 *
 * WHAT ONLY A BROWSER CAN ANSWER, AND WHY THIS CANNOT LIVE IN THE SUITE.
 *
 * `ProductQuickView`, `ProductCompare` and `ProductCSV` each ship an INLINE `<style>` inside their
 * own `src/resources/views/pages/button.blade.php` declaring `opacity: 0; visibility: hidden` on
 * their button, restored only under `.product-box:hover` / `.product-item:hover` /
 * `.product-card:hover`. `visibility: hidden` REMOVES AN ELEMENT FROM THE TAB ORDER. So the claim
 * `GAP-256` makes is not "they are invisible" — it is "Tab never reaches them", and no PHP test can
 * see a tab order, a computed `visibility`, a `:focus-within` reveal, or whether
 * `@media (hover: none)` matches. Those are browser facts.
 *
 * It also cannot live in the suite for the reason CLAUDE.md gives: no WorkDo module loads in a test
 * run unless an `add_on_managers` row is inserted, and loading one in-process pollutes later tests
 * through the process-global Composer `ClassLoader`. So the render happens OUT OF PROCESS, in
 * `tests/Feature/Storefront/ProductActionKeyboardReachTest.php`, which this script runs with
 * `SS_GAP256_HTML_DIR` set and whose bytes it then photographs. That Feature test carries the
 * premises: the four modules really loaded, the plan gate and the four per-store switches are open,
 * all four buttons are in the page, the three modules' OWN `visibility: hidden` arrived in the
 * capture, `.product-card` is really an ancestor, and the host sheet is in `<head>` while the
 * module styles come after it.
 *
 * THE BASELINE THIS WAS WRITTEN AGAINST, measured here before one line of the fix existed:
 *
 *     quickview-btn   visibility: hidden  opacity: 0   -- absent from the tab order
 *     compare-btn     visibility: hidden  opacity: 0   -- absent from the tab order
 *     csv-btn         visibility: hidden  opacity: 0   -- absent from the tab order
 *     product-pdf-btn visibility: visible opacity: 1   -- reachable (it ships no stylesheet)
 *
 *     Tab from the first product card: a -> wishlist-btn -> product-pdf-btn -> product-pdf-btn(a)
 *                                      -> a -> addtocart-btn -> (next card)
 *
 * WHAT IS MEASURED, PER BUTTON AND PER LOCALE:
 *
 *   A  TAB ORDER      -- real `Tab` presses, reading `document.activeElement` after each, and the
 *                        hit must be inside the FIRST product card (a button on card 4 would
 *                        otherwise satisfy a walk that never left card 1).
 *   B  FOCUS REVEALS  -- computed `visibility`, `opacity`, `pointer-events` and the painted box at
 *                        the moment the element holds focus, plus the focus outline.
 *   C  MOUSE UNCHANGED-- resting appearance with neither hover nor focus, and the hover appearance,
 *                        against what the modules' own rules intend. This is the regression test
 *                        for the fix, not for the defect.
 *   D  TOUCH          -- a second context with `hasTouch`, which is what makes Chromium answer
 *                        `(hover: none)` and `(pointer: coarse)` (the CDP
 *                        `Emulation.setEmulatedMedia` `features` route does NOT work in this
 *                        Chromium: measured, `hover` stayed `hover`). Asserted as a premise before
 *                        anything is read, so a desktop measurement cannot be reported as a touch
 *                        one.
 *   E  CONTRAST       -- the icon against its own painted backdrop, in BOTH appearances, because a
 *                        control that becomes visible and then cannot be seen is not fixed. This
 *                        repository has already shipped option names at 1.00:1 white-on-white in
 *                        dark mode.
 *   F  PHANTOM CLICK  -- `elementFromPoint` at the button's centre while it is neither hovered nor
 *                        focused. `visibility: hidden` does not take pointer events, but
 *                        `opacity: 0` DOES: an `opacity`-only fix would leave an invisible 44px
 *                        control sitting over the product image. This is the measurement that makes
 *                        the `pointer-events` half of the fix an evidenced decision rather than a
 *                        precaution.
 *
 * PRECONDITIONS, and it fails loudly rather than reporting a clean pass without them:
 *   - node, with Playwright resolvable (PLAYWRIGHT env var, a global install, or local node_modules);
 *   - php on PATH, because the rendering comes from the Feature test.
 *
 * usage: node tests/Support/bin/check-product-action-keyboard-live.cjs
 */

'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');
const { execFileSync } = require('child_process');

const REPO = path.resolve(__dirname, '../../..');
const PORT = Number(process.env.SS_GAP256_PORT || 8976);

/** The three buttons GAP-256 is about, and the icon inside each. */
const SUBJECTS = [
    { cls: 'quickview-btn', label: 'Quick view', icon: 'svg path' },
    { cls: 'compare-btn', label: 'Compare', icon: 'i' },
    { cls: 'csv-btn', label: 'Export CSV', icon: 'i' },
];

/** The control group: visible today, and a fix that hid it would be a regression. */
const CONTROL = { cls: 'product-pdf-btn', label: 'PDF', icon: 'i' };

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

    throw new Error('PRECONDITION: Playwright is not resolvable. Set PLAYWRIGHT=<path>.');
}

const MIME = {
    '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript',
    '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
    '.gif': 'image/gif', '.svg': 'image/svg+xml', '.webp': 'image/webp', '.ico': 'image/x-icon',
    '.woff': 'font/woff', '.woff2': 'font/woff2', '.ttf': 'font/ttf',
    '.eot': 'application/vnd.ms-fontobject', '.map': 'application/json',
};

/**
 * `public/` plus `themes/` plus the capture directory. Nothing is written into the repository.
 *
 * `themes/` IS SERVED FROM THE REPOSITORY ROOT, NOT FROM `public/`: the layout builds every theme
 * asset as `asset('themes/<theme>/assets/...')` but the files live at `themes/` beside `app/`, and
 * `public/themes` does not exist. Without this root the runtime Tailwind bundle 404s, the page
 * renders unstyled, and every computed colour reads `rgb(0, 0, 0)` on `rgba(0, 0, 0, 0)` — which
 * looks exactly like a wall of 1.00:1 contrast failures the capture itself invented.
 */
function serve(captureDir) {
    const roots = [
        { prefix: '/__capture/', dir: captureDir },
        { prefix: '/themes/', dir: path.join(REPO, 'themes') },
        { prefix: '/', dir: path.join(REPO, 'public') },
    ];

    const server = http.createServer((req, res) => {
        const url = decodeURIComponent(req.url.split('?')[0]);

        for (const root of roots) {
            if (!url.startsWith(root.prefix)) {
                continue;
            }
            const file = path.resolve(root.dir, url.slice(root.prefix.length));

            if (!file.startsWith(path.resolve(root.dir))) {
                break;
            }
            if (fs.existsSync(file) && fs.statSync(file).isFile()) {
                res.writeHead(200, { 'content-type': MIME[path.extname(file).toLowerCase()] || 'application/octet-stream' });
                res.end(fs.readFileSync(file));

                return;
            }
        }

        res.writeHead(404, { 'content-type': 'text/plain' });
        res.end('not found');
    });

    return new Promise((resolve) => server.listen(PORT, '127.0.0.1', () => resolve(server)));
}

function relativeLuminance([r, g, b]) {
    const f = (c) => {
        const s = c / 255;

        return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    };

    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
}

function contrast(a, b) {
    const [l1, l2] = [relativeLuminance(a), relativeLuminance(b)].sort((x, y) => y - x);

    return (l1 + 0.05) / (l2 + 0.05);
}

function rgb(value) {
    const m = String(value).match(/(\d+(?:\.\d+)?)/g);

    return m && m.length >= 3 ? [Number(m[0]), Number(m[1]), Number(m[2])] : null;
}

/* =============================================================== the in-page instrument =========
   One function, injected once per page, so every measurement below reads the SAME definitions of
   "the first card", "the subject element" and "its painted backdrop". */
const INSTRUMENT = `
window.__gap256 = (function () {
    function card() {
        return document.querySelector('.product-card');
    }

    /* The subject inside the FIRST card only. A page carries six cards; a measurement that drifted
       to another card would read a different element with the same class. */
    function el(cls) {
        var c = card();

        return c ? c.querySelector('.' + cls) : null;
    }

    /* The first ancestor-or-self with a non-transparent background, which is what an icon is really
       seen against. A button whose own background is transparent (ProductsPDF) is otherwise scored
       against rgba(0,0,0,0) and reads as a 1.00:1 failure that is an artefact. */
    function backdrop(node) {
        for (var n = node; n && n.nodeType === 1; n = n.parentElement) {
            var bg = getComputedStyle(n).backgroundColor;
            var m = String(bg).match(/[\\d.]+/g);

            if (m && (m.length < 4 || Number(m[3]) > 0.05)) {
                return bg;
            }
        }

        return getComputedStyle(document.body).backgroundColor;
    }

    function style(cls, iconSelector) {
        var e = el(cls);

        if (!e) {
            return null;
        }

        var cs = getComputedStyle(e);
        var r = e.getBoundingClientRect();
        var icon = iconSelector ? e.querySelector(iconSelector) : null;
        var ink = null;

        var inkSource = null;

        if (icon) {
            var ics = getComputedStyle(icon);
            /* An <svg><path> carries its colour as \`fill\`; an icon font as \`color\`. */
            ink = iconSelector.indexOf('path') >= 0 ? ics.fill : ics.color;
            inkSource = 'element';

            /*
             * GAP-273. THE GLYPH OF AN ICON FONT IS PAINTED BY \`::before\`, NOT BY THE ELEMENT,
             * and reading only the element's own \`color\` is what let this measurement be
             * unactionable. \`.ti-printer:before\` carries the \`content\`
             * (public/assets/fonts/tabler-icons.min.css:3916), so the pixels belong to the
             * pseudo-element; it merely INHERITS \`color\` from the \`<i>\` unless an author rule
             * targets it directly.
             *
             * THAT DISTINCTION IS THE WHOLE REPAIR. An inline \`style="color: white"\` on the
             * \`<i>\` cannot be beaten by any stylesheet rule for that element without
             * \`!important\` -- but it is not a rule for \`::before\` at all, so a host rule on
             * \`i::before\` wins on nothing more than being the only declaration there.
             *
             * AND WITHOUT THIS READ THE FIX WOULD HAVE LOOKED INERT: the element's \`color\` stays
             * white afterwards, because nothing changed it. A probe measuring the element would
             * have reported 1.00:1 against a repaired screen -- the mirror image of this project's
             * inert-repair finding, and the reason the pseudo-element's value is reported BESIDE
             * the element's rather than instead of it.
             */
            if (iconSelector.indexOf('path') < 0) {
                var pcs = getComputedStyle(icon, '::before');

                if (pcs && pcs.content && pcs.content !== 'none' && pcs.color) {
                    ink = pcs.color;
                    inkSource = '::before';
                }
            }
        }

        return {
            tag: e.tagName.toLowerCase(),
            inkSource: inkSource,
            inkElement: icon && iconSelector.indexOf('path') < 0 ? getComputedStyle(icon).color : null,
            visibility: cs.visibility,
            opacity: Number(cs.opacity),
            pointerEvents: cs.pointerEvents,
            transform: cs.transform,
            width: Math.round(r.width),
            height: Math.round(r.height),
            outline: cs.outlineStyle + ' ' + cs.outlineWidth + ' ' + cs.outlineColor,
            background: cs.backgroundColor,
            backdrop: backdrop(e),
            ink: ink,
            inkBackdrop: icon ? backdrop(icon) : null,
            focused: document.activeElement === e || e.contains(document.activeElement),
        };
    }

    /* Is the button what a click at its own centre would hit? \`opacity: 0\` keeps an element in the
       hit-test tree; \`visibility: hidden\` and \`pointer-events: none\` take it out. */
    function hitAtCentre(cls) {
        var e = el(cls);

        if (!e) {
            return null;
        }

        var r = e.getBoundingClientRect();

        if (r.width === 0 || r.height === 0) {
            return 'zero-sized';
        }

        var hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);

        if (!hit) {
            return 'outside-viewport';
        }

        return (hit === e || e.contains(hit)) ? 'the-button' : hit.tagName.toLowerCase() +
            '.' + String(hit.className || '').trim().split(/\\s+/).slice(0, 2).join('.');
    }

    function active() {
        var e = document.activeElement;

        if (!e || e === document.body) {
            return { desc: 'body', classes: [], inFirstCard: false };
        }

        var c = card();

        return {
            desc: e.tagName.toLowerCase() + '.' + String(e.className || '').trim().split(/\\s+/).slice(0, 2).join('.'),
            classes: String(e.className || '').trim().split(/\\s+/).filter(Boolean),
            inFirstCard: !!(c && c.contains(e)),
        };
    }

    function focusFirstInCard() {
        var c = card();

        if (!c) {
            return false;
        }

        c.scrollIntoView({ block: 'center' });

        var first = c.querySelector('a[href], button, [tabindex]:not([tabindex="-1"])');

        if (!first) {
            return false;
        }

        first.focus();

        return document.activeElement === first;
    }

    return {
        style: style,
        hitAtCentre: hitAtCentre,
        active: active,
        focusFirstInCard: focusFirstInCard,
        appearance: function (mode) { document.documentElement.setAttribute('data-ss-theme', mode); },
        blur: function () { if (document.activeElement && document.activeElement.blur) { document.activeElement.blur(); } },
        present: function (cls) { return !!el(cls); },
        tailwind: function () { return typeof window.tailwind; },
        dir: function () { return document.documentElement.getAttribute('dir'); },
        hoverNone: function () { return matchMedia('(hover: none)').matches; },
        pointerCoarse: function () { return matchMedia('(pointer: coarse)').matches; },
        sheets: function () {
            return Array.prototype.map.call(document.querySelectorAll('link[rel="stylesheet"]'), function (l) { return l.getAttribute('href'); });
        },
    };
})();
`;

(async () => {
    const captureDir = fs.mkdtempSync(path.join(os.tmpdir(), 'ss-gap256-'));
    let failures = 0;
    let checks = 0;

    const note = (ok, message) => {
        checks += 1;
        if (!ok) {
            failures += 1;
        }
        console.log(`${ok ? '  ok  ' : '  FAIL'} ${message}`);
    };

    /**
     * An ADJACENT observation: measured here, reported here, and deliberately NOT a gate on this
     * change. It never affects the exit status.
     *
     * There is exactly one, and it is recorded rather than quietly fixed: `ProductsPDF`'s printer
     * icon carries `style="color: white"` INLINE on a button with no background of its own, so in
     * LIGHT mode — the default — it paints white on the design system's white `--ss-surface` at
     * 1.00:1. That is WCAG 1.4.11, on the one button of the four that `GAP-256` is NOT about, and
     * beating an inline style needs `!important`, which is a decision of its own rather than a
     * by-product of a tab-order fix.
     */
    const finding = (ok, message) => {
        notes += 1;
        if (!ok) {
            adjacent += 1;
        }
        console.log(`${ok ? '  ok  ' : '  NOTE'} ${message}`);
    };

    let notes = 0;
    let adjacent = 0;

    console.log('GAP-256 — conversion-action keyboard reach, measured in Chromium');
    console.log('='.repeat(97));
    console.log('\nRendering the real storefront route through the Feature test (modules really on)...\n');

    execFileSync('php', [
        'artisan', 'test', 'tests/Feature/Storefront/ProductActionKeyboardReachTest.php',
    ], {
        cwd: REPO,
        stdio: 'inherit',
        env: Object.assign({}, process.env, {
            SS_GAP256_HTML_DIR: captureDir,
            SS_GAP256_ASSET_BASE: `http://127.0.0.1:${PORT}`,
        }),
    });

    for (const locale of ['en', 'ar']) {
        const f = path.join(captureDir, `card-${locale}.html`);

        if (!fs.existsSync(f)) {
            throw new Error(`PRECONDITION: the Feature test wrote no ${locale} capture to ${captureDir}.`);
        }
    }

    const server = await serve(captureDir);
    const { chromium } = playwright();
    const browser = await chromium.launch();

    /** Opens a capture and installs the instrument. `touch` decides the media features. */
    const open = async (locale, touch) => {
        const context = await browser.newContext(touch
            ? { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, deviceScaleFactor: 3 }
            : { viewport: { width: 1280, height: 900 } });
        const page = await context.newPage();
        const errors = [];

        page.on('pageerror', (e) => errors.push(String(e).slice(0, 140)));
        await page.goto(`http://127.0.0.1:${PORT}/__capture/card-${locale}.html`, { waitUntil: 'load' });
        /* The runtime Tailwind JIT injects its rules after load; without this wait the computed
           styles are read off a half-built cascade. */
        await page.waitForTimeout(2500);
        await page.evaluate(INSTRUMENT);
        /* THE FIRST CARD MUST BE ON SCREEN BEFORE ANYTHING IS HIT-TESTED. The first draft of this
           harness did not scroll, and measurement F reported `outside-viewport` for all three
           buttons — which satisfied "is NOT clickable" for entirely the wrong reason, on the
           unfixed stylesheet. `elementFromPoint` is viewport-relative, so an off-screen element is
           indistinguishable from a `pointer-events: none` one unless the card is scrolled in. */
        await page.evaluate(() => {
            const c = document.querySelector('.product-card');

            if (c) {
                c.scrollIntoView({ block: 'center' });
            }
        });
        await page.waitForTimeout(400);

        return { context, page, errors };
    };

    try {
        for (const locale of ['en', 'ar']) {
            const expectedDir = locale === 'ar' ? 'rtl' : 'ltr';

            console.log(`\n${'-'.repeat(97)}\n  ${locale.toUpperCase()}  (dir=${expectedDir})\n${'-'.repeat(97)}`);

            /* ========================================================== premises, in the browser */
            const { context, page, errors } = await open(locale, false);

            note(await page.evaluate(() => window.__gap256.dir()) === expectedDir,
                `PREMISE: the page is dir="${expectedDir}"`);
            note(await page.evaluate(() => window.__gap256.tailwind()) === 'object',
                'PREMISE: the theme\'s runtime Tailwind JIT really loaded (window.tailwind is an object)');

            const sheets = await page.evaluate(() => window.__gap256.sheets());

            note(sheets.some((h) => String(h).includes('storefront-appearance.css')),
                'PREMISE: the host override sheet is linked');
            note(sheets.some((h) => String(h).includes('store-syria-ds.css')),
                'PREMISE: the design system is linked');

            for (const s of [...SUBJECTS, CONTROL]) {
                note(await page.evaluate((c) => window.__gap256.present(c), s.cls),
                    `PREMISE: ${s.label} (.${s.cls}) is in the first product card`);
            }

            note(errors.length === 0, `PREMISE: the page threw no script errors${errors.length ? ' — ' + errors.join(' | ') : ''}`);

            /* ===================================================== C(i) the resting appearance */
            console.log('\n  C(i) RESTING — neither hovered nor focused. What a mouse user sees before touching the card.');
            await page.mouse.move(2, 2);
            await page.evaluate(() => window.__gap256.blur());
            await page.waitForTimeout(450);

            const resting = {};

            for (const s of [...SUBJECTS, CONTROL]) {
                const st = await page.evaluate(([c, i]) => window.__gap256.style(c, i), [s.cls, s.icon]);

                resting[s.cls] = st;
                console.log(`       ${s.label.padEnd(11)} visibility: ${String(st.visibility).padEnd(8)} opacity: ${String(st.opacity).padEnd(4)} pointer-events: ${String(st.pointerEvents).padEnd(5)} ${st.width}x${st.height}`);
            }

            for (const s of SUBJECTS) {
                note(resting[s.cls].visibility === 'visible',
                    `${s.label} is \`visibility: visible\` at rest — the ONE declaration that puts it back in the tab order (got \`${resting[s.cls].visibility}\`)`);
                note(resting[s.cls].opacity === 0,
                    `${s.label} is still unpainted at rest (opacity ${resting[s.cls].opacity}) — the mouse user's resting view is unchanged`);
            }

            note(resting[CONTROL.cls].visibility === 'visible' && resting[CONTROL.cls].opacity === 1,
                `CONTROL: ${CONTROL.label} is still always visible (visibility ${resting[CONTROL.cls].visibility}, opacity ${resting[CONTROL.cls].opacity}) — not hidden by the fix`);

            /* ========================================================== F the phantom-click guard */
            console.log('\n  F  PHANTOM CLICK — what a click at the unpainted button\'s centre would hit.');

            for (const s of SUBJECTS) {
                const hit = await page.evaluate((c) => window.__gap256.hitAtCentre(c), s.cls);

                console.log(`       ${s.label.padEnd(11)} elementFromPoint -> ${hit}`);
                /* Asserted FIRST and separately: an off-screen or zero-sized element answers "not
                   the button" for a reason that has nothing to do with pointer events, and the
                   first draft of this harness passed all three that way. */
                note(hit !== 'outside-viewport' && hit !== 'zero-sized',
                    `PREMISE: ${s.label} is on screen and has a box, so this hit test is about pointer-events (got \`${hit}\`)`);
                note(hit !== 'the-button',
                    `${s.label} is NOT clickable while unpainted (hit \`${hit}\`) — an \`opacity: 0\` control that still takes clicks would be a new defect`);
            }

            /* =============================================================== A the tab order */
            console.log('\n  A  TAB ORDER — real Tab presses from the first focusable element in the first card.');
            const started = await page.evaluate(() => window.__gap256.focusFirstInCard());

            note(started, 'PREMISE: focus was placed on the first focusable element inside the first card');

            const walk = [];
            const reached = {};
            const focusState = {};

            for (let i = 0; i < 16; i += 1) {
                await page.keyboard.press('Tab');
                const a = await page.evaluate(() => window.__gap256.active());

                walk.push((a.inFirstCard ? '' : '[outside] ') + a.desc);

                for (const s of SUBJECTS) {
                    if (a.classes.includes(s.cls) && a.inFirstCard && !reached[s.cls]) {
                        reached[s.cls] = true;
                        /* READ THE STYLE WHILE IT HOLDS FOCUS, TWICE, AND THE SECOND READ IS THE
                           ONE THAT IS GATED.
                           The modules declare `transition: all 0.3s ease`, so an immediate read
                           catches the fade in flight: the first version of this harness read once
                           and reported `opacity: 0.0384374`, which looks exactly like "the focus
                           reveal does not work" and is in fact the reveal happening. The settled
                           value is what a shopper sees; the in-flight one is kept and printed so
                           the distinction is evidenced rather than asserted. */
                        const inFlight = await page.evaluate(([c, ic]) => window.__gap256.style(c, ic),
                            [s.cls, s.icon]);

                        await page.waitForTimeout(450);

                        focusState[s.cls] = await page.evaluate(([c, ic]) => window.__gap256.style(c, ic),
                            [s.cls, s.icon]);
                        focusState[s.cls].inFlightOpacity = inFlight.opacity;
                        focusState[s.cls].inFlightVisibility = inFlight.visibility;
                    }
                }
            }

            console.log('       ' + walk.join('\n       '));

            for (const s of SUBJECTS) {
                note(!!reached[s.cls],
                    `Tab REACHES ${s.label} (.${s.cls}) inside the first card — WCAG 2.1.1`);
            }

            /* ======================================================== B the focus reveal */
            console.log('\n  B  FOCUS REVEALS — computed style at the moment the element holds focus.');

            for (const s of SUBJECTS) {
                const st = focusState[s.cls];

                if (!st) {
                    note(false, `${s.label} was never focused, so its focus appearance could not be read`);

                    continue;
                }

                console.log(`       ${s.label.padEnd(11)} visibility: ${String(st.visibility).padEnd(8)} opacity: ${String(st.opacity).padEnd(4)} (${st.inFlightOpacity} the instant focus arrived, mid-fade) pointer-events: ${String(st.pointerEvents).padEnd(5)} ${st.width}x${st.height}  outline: ${st.outline}`);

                note(st.visibility === 'visible' && st.opacity > 0.9,
                    `${s.label} is PAINTED while focused (visibility ${st.visibility}, opacity ${st.opacity}) — WCAG 2.4.7; a focusable control at opacity 0 would be a new failure`);
                note(st.pointerEvents === 'auto',
                    `${s.label} takes activation while focused (pointer-events ${st.pointerEvents})`);
                note(st.width >= 24 && st.height >= 24,
                    `${s.label} has a real target while focused (${st.width}x${st.height})`);
                note(/\b(solid|auto|dotted|double|groove|ridge|inset|outset)\b/.test(st.outline) && !/\b0px\b/.test(st.outline),
                    `${s.label} paints a focus indicator (${st.outline})`);
            }

            /* ============================================== C(ii) the mouse hover path */
            console.log('\n  C(ii) MOUSE HOVER — hovering the product card, which is what the modules\' own rules key on.');
            await page.evaluate(() => window.__gap256.blur());
            await page.hover('.product-card');
            await page.waitForTimeout(500);

            for (const s of [...SUBJECTS, CONTROL]) {
                const st = await page.evaluate(([c, i]) => window.__gap256.style(c, i), [s.cls, s.icon]);

                console.log(`       ${s.label.padEnd(11)} visibility: ${String(st.visibility).padEnd(8)} opacity: ${String(st.opacity).padEnd(4)} pointer-events: ${String(st.pointerEvents).padEnd(5)} transform: ${st.transform}`);

                if (s === CONTROL) {
                    continue;
                }

                note(st.visibility === 'visible' && st.opacity === 1,
                    `${s.label} is fully painted on card hover (opacity ${st.opacity}) — the behaviour a mouse user already had`);
                note(st.pointerEvents === 'auto',
                    `${s.label} is clickable on card hover (pointer-events ${st.pointerEvents}) — the half a naive \`pointer-events: none\` would have broken`);
                note(st.transform === 'none' || /matrix\(1, 0, 0, 1, 0, 0\)/.test(st.transform),
                    `${s.label} has settled into place on hover (transform ${st.transform})`);
            }

            /* ============================================================= E contrast */
            console.log('\n  E  CONTRAST of the revealed icon against its own painted backdrop, both appearances.');

            for (const mode of ['light', 'dark']) {
                await page.evaluate((m) => window.__gap256.appearance(m), mode);
                await page.waitForTimeout(250);

                for (const s of [...SUBJECTS, CONTROL]) {
                    const st = await page.evaluate(([c, i]) => window.__gap256.style(c, i), [s.cls, s.icon]);
                    const ink = rgb(st.ink);
                    const bg = rgb(st.inkBackdrop);

                    if (!ink || !bg) {
                        console.log(`       ${mode.padEnd(5)} ${s.label.padEnd(11)} n/a (ink ${st.ink} on ${st.inkBackdrop})`);
                        note(false, `${s.label} (${mode}): the icon colour or its backdrop could not be read`);

                        continue;
                    }

                    const ratio = contrast(ink, bg);

                    /* GAP-273: `[::before]` or `[element]` says WHICH value was read, so a repair
                       that moves only the pseudo-element cannot be mistaken for an inert one, and
                       a probe that silently fell back to the element announces itself. */
                    const inkNote = st.inkSource === '::before' && st.inkElement && st.inkElement !== st.ink
                        ? ` [::before; the <i> itself is still ${st.inkElement}]`
                        : ` [${st.inkSource}]`;
                    console.log(`       ${mode.padEnd(5)} ${s.label.padEnd(11)} ${st.ink} on ${st.inkBackdrop} = ${ratio.toFixed(2)}:1${inkNote}`);

                    /* 3:1 is WCAG 1.4.11's floor for a non-text indicator, which is what an icon
                       inside a named control is. These are not text. */
                    const ok = ratio >= 3;

                    if (s === CONTROL) {
                        finding(ok, `ADJACENT — ${CONTROL.label} (${mode}) icon against WCAG 1.4.11's 3:1: ${ratio.toFixed(2)}:1 (not a GAP-256 gate; see the \`finding\` docblock)`);
                    } else {
                        note(ok, `${s.label} (${mode}) icon clears WCAG 1.4.11's 3:1 once revealed (${ratio.toFixed(2)}:1)`);
                    }
                }
            }

            await page.evaluate(() => window.__gap256.appearance('auto'));
            await context.close();

            /* ===================================================== D the touch device */
            console.log('\n  D  TOUCH — a context Chromium answers `(hover: none)` for. No hover state exists at all.');
            const touch = await open(locale, true);

            note(await touch.page.evaluate(() => window.__gap256.hoverNone()) === true,
                'PREMISE: the touch context really matches `(hover: none)` — otherwise this is a desktop measurement mislabelled');
            note(await touch.page.evaluate(() => window.__gap256.pointerCoarse()) === true,
                'PREMISE: the touch context really matches `(pointer: coarse)`');

            await touch.page.evaluate(() => window.__gap256.blur());
            await touch.page.evaluate(() => { const c = document.querySelector('.product-card'); if (c) { c.scrollIntoView({ block: 'center' }); } });
            await touch.page.waitForTimeout(400);

            for (const s of [...SUBJECTS, CONTROL]) {
                const st = await touch.page.evaluate(([c, i]) => window.__gap256.style(c, i), [s.cls, s.icon]);
                const hit = await touch.page.evaluate((c) => window.__gap256.hitAtCentre(c), s.cls);

                console.log(`       ${s.label.padEnd(11)} visibility: ${String(st.visibility).padEnd(8)} opacity: ${String(st.opacity).padEnd(4)} pointer-events: ${String(st.pointerEvents).padEnd(5)} ${st.width}x${st.height}  tap hits: ${hit}`);

                if (s === CONTROL) {
                    continue;
                }

                note(st.visibility === 'visible' && st.opacity === 1,
                    `${s.label} is visible WITHOUT any hover on a touch device (opacity ${st.opacity})`);
                note(st.pointerEvents === 'auto' && hit === 'the-button',
                    `${s.label} is tappable on a touch device (pointer-events ${st.pointerEvents}, tap hits ${hit})`);
                note(st.width >= 24 && st.height >= 24,
                    `${s.label} has a real tap target on a phone (${st.width}x${st.height})`);
            }

            await touch.context.close();
        }
    } finally {
        await browser.close();
        server.close();
        fs.rmSync(captureDir, { recursive: true, force: true });
    }

    console.log(`\n${'='.repeat(97)}`);
    console.log(`${checks} GAP-256 checks, ${failures} failed`);
    console.log(`${notes} adjacent observations, ${adjacent} outside WCAG 1.4.11's floor `
        + '(reported, not gated — see the `finding` docblock)');

    process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
    console.error('\nHARNESS ERROR: ' + (e && e.stack ? e.stack : e));
    process.exit(2);
});
