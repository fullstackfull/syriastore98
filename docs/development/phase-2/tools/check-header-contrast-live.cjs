/**
 * F158 (header) — THE SHARED HEADER'S TEXT CONTRAST, MEASURED RATHER THAN REASONED.
 *
 * The owner's F158 conditions are to start from the sites that actually failed, to measure computed
 * values in a real browser, to keep a before/after, and not to change the theme's general rule for
 * every paragraph in one go. This is the measurement half; `capture-header-contract.cjs` is the
 * behavioural before/after beside it.
 *
 * WHAT IT DOES. For each of en/ltr and ar/rtl x light and dark, it logs in, opens the dashboard,
 * and walks every element in the header that owns a DIRECT text node, reading the COMPUTED colour
 * and the first opaque background up the ancestor chain, then computing the WCAG ratio and the
 * threshold that applies at that element's own font size and weight.
 *
 * IT OPENS EVERY DROPDOWN FIRST, AND THAT IS NOT A DETAIL. The first version of this probe read
 * only the visible bar: five text nodes. The header's dropdown menus are display:none until opened,
 * so the profile menu, the quick-add list, the store switcher and all eighteen language entries were
 * invisible to it -- 23 of the 28 failures in dark mode, and BOTH of the light-mode failures, were
 * in there. A contrast tool that does not open what the user opens is measuring the wrong page.
 * They are opened by adding Bootstrap's own `.show` rather than by clicking, because clicking one
 * closes the last.
 *
 * MEASURED RESULTS, 2026-10-28. Before: dark 28 of 30 nodes below AA (the bar at 3.71:1, the
 * dropdown items at 3.5:1), light 2 of 30 (the brand green as text at 2.59:1). After: 0 of 30 in
 * all four configurations.
 *
 * usage: node docs/development/phase-2/tools/check-header-contrast-live.cjs
 */
'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const REPO = '/home/user/syriastore98';
const TOOLS = path.join(REPO, 'docs/development/phase-2/tools');
const ENV = Object.assign({}, process.env, {
    DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'),
    CACHE_STORE: 'file', SESSION_DRIVER: 'file',
});
const PROBE = `(function () {
    function lum(c) {
        var m = c.match(/rgba?\\(([^)]+)\\)/); if (!m) { return null; }
        var p = m[1].split(',').map(Number);
        var f = p.slice(0,3).map(function (v) { v = v/255; return v <= 0.03928 ? v/12.92 : Math.pow((v+0.055)/1.055, 2.4); });
        return 0.2126*f[0] + 0.7152*f[1] + 0.0722*f[2];
    }
    function bgOf(el) {
        var n = el;
        while (n && n !== document.documentElement) {
            var c = getComputedStyle(n).backgroundColor;
            var m = c.match(/rgba?\\(([^)]+)\\)/);
            if (m) { var p = m[1].split(',').map(Number); if (p.length < 4 || p[3] > 0.95) { return c; } }
            n = n.parentElement;
        }
        return getComputedStyle(document.body).backgroundColor;
    }
    var head = document.querySelector('header');
    if (!head) { return []; }
    var out = [];
    head.querySelectorAll('*').forEach(function (el) {
        var direct = Array.prototype.filter.call(el.childNodes, function (n) {
            return n.nodeType === 3 && n.textContent.trim().length > 1;
        }).map(function (n) { return n.textContent.trim(); }).join(' ');
        if (!direct) { return; }
        var r = el.getBoundingClientRect();
        if (r.width < 1 || r.height < 1) { return; }
        var cs = getComputedStyle(el);
        if (cs.visibility === 'hidden' || cs.display === 'none' || parseFloat(cs.opacity) < 0.1) { return; }
        var L1 = lum(cs.color), L2 = lum(bgOf(el));
        if (L1 === null || L2 === null) { return; }
        var hi = Math.max(L1, L2), lo = Math.min(L1, L2);
        var px = parseFloat(cs.fontSize), bold = parseInt(cs.fontWeight, 10) >= 700;
        out.push({
            text: direct.slice(0, 34),
            color: cs.color, bg: bgOf(el),
            px: px, bold: bold,
            ratio: Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100,
            need: (px >= 24 || (bold && px >= 18.66)) ? 3 : 4.5,
        });
    });
    return out;
})()`;
(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox'] });
    for (const [lang, dir] of [['en','ltr'], ['ar','rtl']]) {
        for (const theme of ['light','dark']) {
            execFileSync('php', [path.join(TOOLS,'set-admin-preview-state.php'), '1', theme, dir, lang, 'merchant@example.test'],
                { cwd: REPO, env: ENV, stdio: 'pipe' });
            const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 } });
            await ctx.route('**/merchant-notifications/feed*', r => r.abort());
            const page = await ctx.newPage();
            await page.goto('http://127.0.0.1:8899/login', { waitUntil: 'domcontentloaded' });
            await page.fill('input[name=email]','merchant@example.test');
            await page.fill('input[name=password]','password');
            await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}).catch(()=>{}), page.click('button[type=submit]')]);
            await page.goto('http://127.0.0.1:8899/dashboard', { waitUntil: 'domcontentloaded' });
            /* OPEN EVERY DROPDOWN FIRST. Their items are display:none until opened, so a probe that
               only reads the visible header measures five text nodes and misses the profile menu,
               the quick-add list, the store switcher and the language list entirely. Opened by
               adding Bootstrap's own `.show`, rather than by clicking, so they can all be open at
               once -- clicking closes the previous one. */
            await page.evaluate(() => {
                document.querySelectorAll('header .dropdown-menu').forEach(m => {
                    m.classList.add('show');
                    m.style.display = 'block';
                });
            });
            await page.waitForTimeout(150);
            const rows = await page.evaluate(PROBE);
            const bad = rows.filter(r => r.ratio < r.need);
            process.stdout.write(`\n=== ${lang}/${dir}/${theme} — ${rows.length} text nodes, ${bad.length} below AA ===\n`);
            bad.forEach(r => process.stdout.write(
                `  ${r.ratio}:1 (need ${r.need})  ${r.px}px${r.bold?' bold':''}  ${r.color} on ${r.bg}  "${r.text}"\n`));
            await ctx.close();
        }
    }
    await browser.close();
})();
