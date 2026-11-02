/**
 * F152 — every `<p>` in the dark merchant admin, measured.
 *
 * THE RULE AND WHY NOTHING COULD OVERRIDE IT. `public/assets/css/style-dark.css` and
 * `public/assets/css/rtl-style-dark.css` both carry, identically:
 *
 *     .dash-sidebar .dash-link, .dash-sidebar.light-sidebar .dash-link i, .dataTable-info, p
 *     { color: #808191 !important; }
 *
 * The bare `p` in that list hits EVERY paragraph in the dark admin, and `!important` means no
 * component stylesheet can win: raising the design system's selector specificity was tried on
 * P2-04-7's board card and did nothing, which is how F152 was found at 3.42:1.
 *
 * SCOPE, MEASURED STATICALLY FIRST: 23 distinct design-system classes are applied to `<p>`
 * elements across roughly 74 usages in `resources/views/` and `public/js/store-syria-ds.js` --
 * `ss-field__hint`, `ss-field__error`, `ss-page-header__subtitle`, `ss-panel__loading`,
 * `ss-panel__error`, `ss-feed__*`, `ss-empty__*` and the rest. Every one of them declares a colour
 * in the design system, and in dark mode every one of them loses it.
 *
 * WHAT THIS TOOL IS FOR: the owner's requirement is the narrowest safe change AND a measurement of
 * the impact on other screens. So it reports every paragraph on each page in dark mode, split into
 * the two sets the fix distinguishes -- paragraphs that carry a design-system class (which the fix
 * is meant to release) and paragraphs that do not (which it must leave exactly as they were).
 * Run it before and after; the second set must not move.
 *
 * usage: node docs/development/phase-2/tools/check-dark-paragraphs-live.cjs [baseUrl] [storeId]
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
const AA = 4.5;

const ENV = Object.assign({}, process.env, {
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'),
    CACHE_STORE: 'file',
    SESSION_DRIVER: 'file',
});

const PROBE = String.raw`
(function () {
    function parse(c) {
        var m = String(c).match(/rgba?\(([^)]+)\)/);
        if (!m) return null;
        var p = m[1].split(',').map(function (x) { return parseFloat(x.trim()); });
        return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
    }
    function over(fg, bg) {
        var a = fg.a;
        return { r: fg.r * a + bg.r * (1 - a), g: fg.g * a + bg.g * (1 - a),
                 b: fg.b * a + bg.b * (1 - a), a: 1 };
    }
    function lum(c) {
        var s = [c.r, c.g, c.b].map(function (v) {
            v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * s[0] + 0.7152 * s[1] + 0.0722 * s[2];
    }
    function ratio(a, b) {
        var l1 = lum(a), l2 = lum(b);
        return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    }
    function effectiveBg(el) {
        var node = el, stack = [], acc = { r: 255, g: 255, b: 255, a: 1 };
        while (node && node.nodeType === 1) {
            var bg = parse(getComputedStyle(node).backgroundColor);
            if (bg && bg.a > 0) stack.push(bg);
            if (bg && bg.a === 1) break;
            node = node.parentElement;
        }
        for (var i = stack.length - 1; i >= 0; i--) acc = over(stack[i], acc);
        return acc;
    }

    var out = [];
    Array.prototype.forEach.call(document.querySelectorAll('p'), function (el) {
        var text = (el.textContent || '').trim();
        if (!text) { return; }                         // an empty paragraph has nothing to read
        var cs = getComputedStyle(el);
        if (cs.visibility === 'hidden' || cs.display === 'none') { return; }
        var box = el.getBoundingClientRect();
        if (box.width === 0 || box.height === 0) { return; }

        var fg = parse(cs.color);
        if (!fg) { return; }
        var bg = effectiveBg(el);
        var cls = el.getAttribute('class') || '';
        out.push({
            cls: cls.slice(0, 48),
            ds: /(^|\s)ss-/.test(cls),
            color: cs.color,
            ratio: Math.round(ratio(over(fg, bg), bg) * 100) / 100,
            text: text.slice(0, 30),
        });
    });
    return out;
})()
`;

/*
 * `/dashboard`, `/setting`, `/order/create` and `/customer/create` are here specifically to supply
 * paragraphs that do NOT carry a design-system class. The first page set had none at all, so the
 * half of this measurement that matters most -- proving the fix leaves the host theme's own
 * paragraphs exactly where they were -- had nothing to measure.
 */
const PAGES = ['/order', '/product', '/coupon', '/customer', '/product/create', '/order-kanban',
    '/dashboard', '/setting', '/order/create', '/customer/create'];

(async () => {
    execFileSync('php', [
        path.join(__dirname, 'set-admin-preview-state.php'),
        STORE, 'dark', 'ltr', 'en', EMAIL,
    ], { cwd: REPO, env: ENV, stdio: 'pipe' });

    const browser = await chromium.launch({ args: ['--no-sandbox'] });
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

    let dsTotal = 0, dsFail = 0, otherTotal = 0, otherFail = 0;
    const dsFailures = [], otherRows = [];

    for (const url of PAGES) {
        const resp = await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
        if (!resp || resp.status() !== 200) {
            process.stdout.write(`PRECOND ${url} HTTP ${resp ? resp.status() : 0} -- skipped\n`);
            continue;
        }
        const rows = await page.evaluate(PROBE);
        rows.forEach(r => {
            if (r.ds) {
                dsTotal++;
                if (r.ratio < AA) { dsFail++; dsFailures.push({ url, ...r }); }
            } else {
                otherTotal++;
                if (r.ratio < AA) { otherFail++; }
                // every non-DS paragraph is recorded so a before/after diff can prove it did not move
                otherRows.push(`${url} | ${r.cls || '(no class)'} | ${r.color} | ${r.ratio}`);
            }
        });
    }

    process.stdout.write(`\nDESIGN-SYSTEM paragraphs : ${dsTotal} measured, ${dsFail} below ${AA}:1\n`);
    dsFailures.forEach(f =>
        process.stdout.write(`   FAIL ${f.ratio}:1  ${f.url}  ${f.cls}  "${f.text}"  ${f.color}\n`));

    process.stdout.write(`\nOTHER paragraphs         : ${otherTotal} measured, ${otherFail} below ${AA}:1\n`);
    process.stdout.write('   (the set the fix must NOT move -- full list below for a before/after diff)\n');
    otherRows.sort().forEach(r => process.stdout.write(`   ${r}\n`));

    await browser.close();
    process.exit(0);
})();
