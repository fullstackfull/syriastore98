/**
 * P2-A-02 / ledger F8, F9. CONTRAST MEASURED IN THE RUNNING APPLICATION.
 *
 * The Phase 02 contrast numbers so far came from a preview harness. Two of them were false
 * readings before anyone noticed (F8 records both: a file:// probe that loaded no stylesheet at
 * all and reported browser-default link blue at 9.40:1, and a preview hard-coding a
 * non-default `cust_theme_bg` branch). The cure for both is the same -- measure the real page,
 * served by the real application, in the configuration being claimed.
 *
 * usage: node measure-contrast-live.cjs <baseUrl> [storeId]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const STORE = process.argv[3] || '1';
const EMAIL = 'merchant@example.test';
const PASSWORD = 'password';

function setServerState(theme, dir) {
    const repo = path.join(__dirname, '..', '..', '..', '..');
    execFileSync('php', [path.join(__dirname, 'set-preview-state.php'), STORE, theme, dir], {
        cwd: repo,
        env: Object.assign({}, process.env, {
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: path.join(repo, 'database', 'p2dev.sqlite'),
            CACHE_STORE: 'file',
        }),
        stdio: 'pipe',
    });
}

/* WCAG 2.x relative luminance and contrast, and the alpha compositing that goes with reading a
   computed colour off a real page: `getComputedStyle().backgroundColor` is `rgba(0,0,0,0)` for
   any element that does not paint its own background, so the effective background has to be
   walked up the ancestor chain or every ratio is computed against transparent black. */
const PROBE = `
(function () {
    function parse(c) {
        var m = String(c).match(/rgba?\\(([^)]+)\\)/);
        if (!m) return null;
        var p = m[1].split(',').map(function (x) { return parseFloat(x.trim()); });
        return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
    }
    function over(fg, bg) {
        var a = fg.a;
        return { r: fg.r * a + bg.r * (1 - a), g: fg.g * a + bg.g * (1 - a), b: fg.b * a + bg.b * (1 - a), a: 1 };
    }
    function lum(c) {
        var s = [c.r, c.g, c.b].map(function (v) {
            v /= 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * s[0] + 0.7152 * s[1] + 0.0722 * s[2];
    }
    function ratio(a, b) {
        var l1 = lum(a), l2 = lum(b);
        return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    }
    function effectiveBg(el) {
        var node = el, acc = { r: 255, g: 255, b: 255, a: 1 };
        var stack = [];
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
    function measure(label, selector) {
        var el = document.querySelector(selector);
        if (!el) { out.push({ label: label, selector: selector, found: false }); return; }
        var cs = getComputedStyle(el);
        var fg = parse(cs.color);
        var bg = effectiveBg(el);
        var size = parseFloat(cs.fontSize);
        var weight = parseInt(cs.fontWeight, 10) || 400;
        // WCAG "large text": 18.66px bold, or 24px.
        var large = size >= 24 || (size >= 18.66 && weight >= 700);
        out.push({
            label: label, selector: selector, found: true,
            color: cs.color, bg: 'rgb(' + Math.round(bg.r) + ',' + Math.round(bg.g) + ',' + Math.round(bg.b) + ')',
            size: size, weight: weight, large: large,
            ratio: Math.round(ratio(over(fg, bg), bg) * 100) / 100,
            need: large ? 3 : 4.5
        });
    }

    measure('sidebar label (inactive)', '.dash-item:not(.active) > .dash-link .dash-mtext');
    measure('sidebar label (active)', '.dash-item.active > .dash-link .dash-mtext');
    measure('sidebar zone heading', '.ss-navgroup');
    measure('sidebar search input', '#sidebar-search, .dash-sidebar input[type=text]');
    measure('page title', '.page-header h2, h2');
    measure('table header', 'table thead th');
    measure('table cell', 'table tbody td');
    measure('breadcrumb link', '.breadcrumb a');
    return out;
})()
`;

(async () => {
    const browser = await chromium.launch();
    const rows = [];

    for (const dir of ['ltr', 'rtl']) {
        for (const theme of ['light', 'dark']) {
            setServerState(theme, dir);
            const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
            const page = await ctx.newPage();
            await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
            await page.fill('input[name="email"]', EMAIL);
            await page.fill('input[name="password"]', PASSWORD);
            await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
            if (page.url().includes('/login')) throw new Error('login failed');
            await page.goto(`${BASE}/change-language/${dir === 'rtl' ? 'ar' : 'en'}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
            await page.goto(`${BASE}/order`, { waitUntil: 'networkidle' });

            const sheet = await page.evaluate(() => {
                const el = document.getElementById('main-style-link');
                return el ? (el.getAttribute('href') || '').split('/').pop() : '(none)';
            });

            const results = await page.evaluate(PROBE);
            for (const r of results) rows.push(Object.assign({ dir, theme, sheet }, r));
            await ctx.close();
        }
    }

    await browser.close();

    let fails = 0;
    console.log('config         sheet                   element                       ratio  need  verdict');
    for (const r of rows) {
        const cfg = `${r.dir}/${r.theme}`.padEnd(14);
        if (!r.found) { console.log(`${cfg} ${r.sheet.padEnd(23)} ${r.label.padEnd(29)} (not present)`); continue; }
        const ok = r.ratio >= r.need;
        if (!ok) fails++;
        console.log(`${cfg} ${r.sheet.padEnd(23)} ${r.label.padEnd(29)} ${String(r.ratio).padStart(5)}  ${String(r.need).padStart(4)}  ${ok ? 'pass' : 'FAIL  ' + r.color + ' on ' + r.bg}`);
    }
    console.log(`\n${rows.filter(r => r.found).length} measured, ${fails} below AA.`);
    process.exit(0);
})().catch((e) => { console.error('FATAL', e.message); process.exit(2); });
