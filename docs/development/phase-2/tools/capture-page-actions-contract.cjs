/**
 * D-25 STEP 2 — the shared `action-button` page-header region, captured as a CONTRACT so the
 * conversion can be compared against it rather than eyeballed.
 *
 * WHAT THE REGION IS. `layouts/app.blade.php` renders `@yield('action-button')` inside
 * `.page-header-icon` on every merchant screen; each screen supplies its own controls. The owner's
 * D-25 Step 2 is the order list's four — Create Order, Export, Grid View, Board View — "as one
 * unit, changing no action behaviour and breaking no form submission or Bootstrap/JavaScript
 * contract".
 *
 * WHY A CAPTURE AND NOT A LOOK. Three of these four controls are icon-only, and two of their
 * contracts are invisible in the markup:
 *
 *   - `.export-btn` is bound by a DELEGATED jQuery handler at `public/js/loader.js:116`. It
 *     preventDefaults the click, fetches the href as a blob and names the download from the
 *     control's own `filename` attribute. Rename the class or drop the attribute and the control
 *     still looks and navigates fine while the download stops working or lands as
 *     "Export.xlsx" -- so this clicks it FOR REAL with the route intercepted, and reads the
 *     filename the handler computed.
 *   - `title` is not the accessible name once Bootstrap's tooltip plugin has run: it MOVES the
 *     attribute into `data-bs-original-title` and blanks it. Reading only `title` gave this
 *     project two different answers on two runs of the SAME tree, so both are read.
 *
 * ALL FOUR CONFIGURATIONS AND BOTH VIEWPORTS, because the owner's conditions name Arabic and
 * English, RTL and LTR, dark and light, phone and desktop.
 *
 * usage:
 *   node capture-page-actions-contract.cjs <out.json> [baseUrl] [storeId]
 *
 * Run once before the conversion and once after, then diff. A difference is not automatically a
 * defect -- a deliberate colour change is a difference -- but every difference has to be read.
 */
'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const OUT = process.argv[2] || 'page-actions-contract.json';
const BASE = process.argv[3] || 'http://127.0.0.1:8899';
const STORE = process.argv[4] || '1';
const REPO = path.join(__dirname, '..', '..', '..', '..');
const EMAIL = 'merchant@example.test';
const PASSWORD = 'password';

/* The order list and its grid view: the same region on the same screen in its two view modes. */
const PAGES = ['/order', '/order-grid'];
const VIEWPORTS = [{ name: 'desktop', width: 1440, height: 900 }, { name: 'phone', width: 390, height: 844 }];

const ENV = Object.assign({}, process.env, {
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'),
    CACHE_STORE: 'file',
    SESSION_DRIVER: 'file',
});

const PROBE = `(function () {
    var region = document.querySelector('.page-header-icon');
    if (!region) { return { present: false }; }

    function parse(c) {
        var m = String(c).match(/rgba?\\(([^)]+)\\)/);
        if (!m) { return null; }
        var p = m[1].split(',').map(function (x) { return parseFloat(x.trim()); });
        return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
    }
    function over(f, b) { var a = f.a; return { r: f.r*a + b.r*(1-a), g: f.g*a + b.g*(1-a), b: f.b*a + b.b*(1-a), a: 1 }; }
    function lum(c) { var s = [c.r,c.g,c.b].map(function (v) { v /= 255; return v <= 0.03928 ? v/12.92 : Math.pow((v+0.055)/1.055, 2.4); }); return 0.2126*s[0]+0.7152*s[1]+0.0722*s[2]; }
    function ratio(a, b) { var l1 = lum(a), l2 = lum(b); return (Math.max(l1,l2)+0.05)/(Math.min(l1,l2)+0.05); }
    function effBg(el) {
        var n = el, acc = { r:255, g:255, b:255, a:1 }, st = [];
        while (n && n.nodeType === 1) {
            var bg = parse(getComputedStyle(n).backgroundColor);
            if (bg && bg.a > 0) { st.push(bg); }
            if (bg && bg.a === 1) { break; }
            n = n.parentElement;
        }
        for (var i = st.length - 1; i >= 0; i--) { acc = over(st[i], acc); }
        return acc;
    }
    /* Both spellings of the name, because the tooltip plugin moves one into the other. */
    function name(el) {
        var aria = (el.getAttribute('aria-label') || '').trim();
        if (aria) { return aria; }
        var txt = (el.textContent || '').replace(/\\s+/g, ' ').trim();
        if (txt) { return txt; }
        var t = el.getAttribute('title') || el.getAttribute('data-bs-original-title');
        return t ? t.trim() : '';
    }

    var controls = [].slice.call(region.querySelectorAll('a, button')).map(function (el) {
        var cs = getComputedStyle(el);
        var icon = el.querySelector('i, svg');
        var r = el.getBoundingClientRect();
        var fg = parse(cs.color), bg = effBg(el);
        var out = {
            tag: el.tagName.toLowerCase(),
            href: el.getAttribute('href'),
            id: el.id || '',
            name: name(el),
            hasText: !!(el.textContent || '').replace(/\\s+/g, ' ').trim(),
            /* The handler contract, read off the live element. */
            exportBtn: el.classList.contains('export-btn'),
            filename: el.getAttribute('filename'),
            tooltipToggle: el.getAttribute('data-bs-toggle'),
            tooltipPlacement: el.getAttribute('data-bs-placement'),
            icon: icon ? (icon.getAttribute('class') || '') : '',
            iconHidden: icon ? icon.getAttribute('aria-hidden') : null,
            iconWidth: icon ? Math.round(icon.getBoundingClientRect().width) : null,
            box: { w: Math.round(r.width), h: Math.round(r.height) },
            style: { bg: cs.backgroundColor, color: cs.color, border: cs.borderTopColor, radius: cs.borderTopLeftRadius },
            ratio: Math.round(ratio(over(fg, bg), bg) * 100) / 100,
        };
        if (icon) {
            var ics = getComputedStyle(icon);
            var ifg = parse(ics.color), ibg = effBg(icon);
            out.iconRatio = Math.round(ratio(over(ifg, ibg), ibg) * 100) / 100;
        }
        return out;
    });

    /* The region's own layout: a conversion that collapses the row or loses its gap shows up here
       rather than in a screenshot nobody compares. */
    var rcs = getComputedStyle(region);
    var rb = region.getBoundingClientRect();

    return {
        present: true,
        controls: controls,
        region: {
            classes: region.getAttribute('class'),
            display: rcs.display,
            gap: rcs.gap,
            flexWrap: rcs.flexWrap,
            box: { w: Math.round(rb.width), h: Math.round(rb.height) },
            /* Does the row of controls stay on the screen at this width? */
            overflowsRight: Math.round(rb.right) > document.documentElement.clientWidth + 1,
        },
    };
})()`;

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox'] });
    const out = {};

    for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
        for (const theme of ['light', 'dark']) {
            execFileSync('php', [
                path.join(__dirname, 'set-admin-preview-state.php'),
                STORE, theme, dir, lang, EMAIL,
            ], { cwd: REPO, env: ENV, stdio: 'pipe' });

            for (const vp of VIEWPORTS) {
                const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
                await ctx.route('**/merchant-notifications/feed*', (r) => r.abort());
                const page = await ctx.newPage();
                const errors = [];
                page.on('pageerror', (e) => errors.push(String(e)));
                page.on('response', (r) => { if (r.status() >= 500) errors.push(`${r.status()} ${r.url()}`); });

                await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
                await page.fill('input[name=email]', EMAIL);
                await page.fill('input[name=password]', PASSWORD);
                await Promise.all([
                    page.waitForLoadState('domcontentloaded').catch(() => {}),
                    page.click('button[type=submit]'),
                ]);

                for (const url of PAGES) {
                    const key = `${lang}/${dir}/${theme}/${vp.name}${url}`;
                    const resp = await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
                    if (!resp || resp.status() !== 200) { out[key] = { unreachable: resp ? resp.status() : 0 }; continue; }

                    /* After the tooltip plugin has initialised, or `title` is still a name here
                       and is not one on the merchant's screen. */
                    await page.waitForTimeout(900);
                    const contract = await page.evaluate(PROBE);

                    /*
                     * CHROMIUM'S OWN COMPUTED ACCESSIBLE NAMES, not this file's heuristic, and
                     * taken HERE -- after the 900ms wait -- because that is the merchant's real
                     * state. A control whose only name was a `title` the tooltip plugin has
                     * since blanked shows up as a bare `- link:` with no name. The heuristic
                     * above stays because it reports WHICH attribute supplied a name; this
                     * reports whether the browser found one at all, which is the question.
                     */
                    contract.axNames = await page.locator('.page-header-icon').ariaSnapshot()
                        .catch((e) => 'error: ' + String(e.message).split('\n')[0]);

                    /*
                     * THE EXPORT HANDLER, CLICKED FOR REAL. The route is intercepted so no file
                     * is written; what is recorded is whether the delegated handler in loader.js
                     * fired at all (it preventDefaults, so a navigation means it did NOT) and
                     * what filename it computed from the control's own attribute.
                     */
                    contract.exportHandler = null;
                    const exportBtn = await page.$('.page-header-icon .export-btn');
                    if (exportBtn) {
                        let fetched = null;
                        await page.route('**/order/export*', (route) => {
                            fetched = route.request().url();
                            route.fulfill({ status: 200, contentType: 'application/octet-stream', body: 'x' });
                        });
                        const download = page.waitForEvent('download', { timeout: 3000 }).catch(() => null);
                        const urlBefore = page.url();
                        await exportBtn.click().catch(() => {});
                        const dl = await download;
                        contract.exportHandler = {
                            fetchedHref: fetched,
                            /* preventDefault held: the page did not navigate away. */
                            stayedOnPage: page.url() === urlBefore,
                            downloadName: dl ? dl.suggestedFilename() : null,
                        };
                        await page.unroute('**/order/export*');
                        if (page.url() !== urlBefore) { await page.goto(BASE + url, { waitUntil: 'domcontentloaded' }); }
                    }

                    /* The real tab order through the region. */
                    await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
                    await page.evaluate(() => document.body.focus());
                    const tabbed = [];
                    for (let i = 0; i < 40; i++) {
                        await page.keyboard.press('Tab');
                        const where = await page.evaluate(() => {
                            const a = document.activeElement;
                            if (!a || !a.closest || !a.closest('.page-header-icon')) { return null; }
                            /* TRIM EACH CANDIDATE BEFORE CHOOSING. An icon-only <a> has a
                               textContent of "\n        " -- whitespace, which is TRUTHY, so it
                               won the || chain and every icon control read "(unnamed)". That
                               looked like a finding about the page and was a bug here. */
                            const pick = (...vals) => {
                                for (const v of vals) {
                                    const t = (v || '').replace(/\s+/g, ' ').trim();
                                    if (t) { return t; }
                                }
                                return '';
                            };
                            const n = pick(a.getAttribute('aria-label'), a.textContent,
                                a.getAttribute('title'), a.getAttribute('data-bs-original-title'));
                            return a.tagName.toLowerCase() + ':' + (n.slice(0, 40) || '(unnamed)');
                        });
                        if (where) { tabbed.push(where); }
                    }
                    contract.tabOrder = tabbed;
                    contract.errors = errors.slice(0, 3);

                    out[key] = contract;
                }

                await ctx.close();
            }
        }
    }

    await browser.close();
    fs.writeFileSync(OUT, JSON.stringify(out, null, 2));
    process.stdout.write(`wrote ${OUT}: ${Object.keys(out).length} captures\n`);
})().catch((e) => { console.error(e); process.exit(1); });
