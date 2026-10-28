/**
 * P2-01-14 / WS A3 / F151 — the unified breadcrumb, measured in the running application.
 *
 * WHY THIS IS A SEPARATE TOOL FROM measure-contrast-live.cjs. That one sweeps seven pages for
 * every design-system colour, and on the single-threaded PHP built-in server a page whose
 * notification feed fires a concurrent XHR can deadlock it -- the first attempt at this
 * measurement served 1385 requests and then stopped dead, having printed nothing, because its
 * output was buffered through a pipe. This tool measures ONE component, prints each configuration
 * as it completes so a hang is visible rather than silent, and blocks the feed XHR outright.
 *
 * `found: false` IS A FAILURE HERE, NOT A PASS. The contrast sweep's old target was
 * `.breadcrumb a`; after this row that selector matches nothing, and a tool that reports
 * "not found" where a human reads "no failure" is how a measurement lies. Every assertion below
 * requires the element to exist first.
 *
 * WHAT IS CHECKED, in all four real configurations (en/ltr and ar/rtl x light and dark, the theme
 * and direction taken from the SERVER's `cust_darklayout` and `SITE_RTL` settings and the language
 * from `stores.default_language`, never from an attribute poked onto <html> -- that mistake made
 * all four "dark" readings light, and is recorded as F8):
 *
 *   1. the navigation landmark exists and is named;
 *   2. exactly ONE item is marked `aria-current="page"`;
 *   3. that item is not a link;
 *   4. the Home crumb exists, exactly once, and points at the dashboard;
 *   5. NO ITEM RENDERS TWO SEPARATORS -- the design system's is a `::before` with `content: ""`,
 *      and Bootstrap's `.breadcrumb-item::before` injects a literal "/", so any item whose
 *      computed `::before` content is not empty is the two-separator defect;
 *   6. contrast of the trail link and of the current-page item against their real composited
 *      background, which is the F151 question.
 *
 * usage: node docs/development/phase-2/tools/check-breadcrumb-live.cjs [baseUrl] [storeId]
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

const ENV = Object.assign({}, process.env, {
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'),
    CACHE_STORE: 'file',
    SESSION_DRIVER: 'file',
});

/*
 * STATE IS SET THE WAY THE LAYOUT ACTUALLY READS IT, which took a measurement to establish.
 *
 * `set-preview-state.php` writes `cust_darklayout` and `SITE_RTL`, and for the storefront that is
 * the whole story. For the MERCHANT ADMIN it is not: `resources/views/layouts/app.blade.php:42-50`
 * derives the direction from the USER'S LANGUAGE and overrides the setting --
 *
 *     $setting['currantLang'] = auth()->user()->language;
 *     if ($setting['currantLang'] == 'ar' || $setting['currantLang'] == 'he') {
 *         $setting['SITE_RTL'] = 'on';
 *     }
 *
 * -- so with the dev merchant's `users.language = 'ar'`, RTL is forced on and writing SITE_RTL=off
 * changes nothing. The first run of this tool reported all five en/ltr pages as failures for
 * `<html dir> = "rtl", expected "ltr"`, which was my state control being wrong rather than the
 * page: it had measured ar/rtl twice and en/ltr never. Flipping SITE_RTL on and off and watching
 * the rendered attribute not move is what found it.
 *
 * AND THE WRITES GO THROUGH ELOQUENT. `Setting` and `User` are both `Cachable`
 * (GeneaLabs\LaravelModelCaching), so a `DB::table()->update()` leaves Eloquent reads serving the
 * pre-write row -- the trap CLAUDE.md records, and one I walked into here before noticing that an
 * on/off/on sweep produced three identical renders.
 */
function setServerState(theme, dir, lang) {
    execFileSync('php', [
        path.join(__dirname, 'set-admin-preview-state.php'),
        STORE, theme, dir, lang, EMAIL,
    ], { cwd: REPO, env: ENV, stdio: 'pipe' });
}

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
            v /= 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * s[0] + 0.7152 * s[1] + 0.0722 * s[2];
    }
    function ratio(a, b) {
        var l1 = lum(a), l2 = lum(b);
        return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    }
    /* getComputedStyle().backgroundColor is rgba(0,0,0,0) for anything that paints no background
       of its own, so the effective colour has to be composited up the ancestor chain or every
       ratio is measured against transparent black. */
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
    function contrastOf(el) {
        var fg = parse(getComputedStyle(el).color);
        if (!fg) return null;
        return Math.round(ratio(over(fg, effectiveBg(el)), effectiveBg(el)) * 100) / 100;
    }

    var out = {
        dir: document.documentElement.getAttribute('dir') || '',
        lang: document.documentElement.getAttribute('lang') || '',
        nav: null, navLabel: null,
        pageBg: null,
        items: 0, current: 0, currentIsLink: null, currentText: null, currentContrast: null,
        homeCount: 0, homeHref: null,
        links: [], doubleSeparator: [], legacyItems: 0,
    };

    /* The page background, composited. Printed with every row so "light" and "dark" are proved by
       a measured colour rather than asserted by the loop variable -- four "dark" readings were
       once taken of the light stylesheet (F8), and listing the linked stylesheets does NOT
       distinguish them because the theme switcher references all four names on every page. */
    var bodyEl = document.body;
    if (bodyEl) {
        var b = effectiveBg(bodyEl);
        out.pageBg = 'rgb(' + Math.round(b.r) + ',' + Math.round(b.g) + ',' + Math.round(b.b) + ')';
    }

    var nav = document.querySelector('nav[aria-label]');
    var list = document.querySelector('.ss-breadcrumb');
    out.nav = !!list;
    if (nav && list && nav.contains(list)) out.navLabel = nav.getAttribute('aria-label');

    if (!list) return out;

    out.legacyItems = document.querySelectorAll('.ss-breadcrumb .breadcrumb-item').length;

    var items = Array.prototype.slice.call(list.querySelectorAll('.ss-breadcrumb__item'));
    out.items = items.length;

    items.forEach(function (li, i) {
        if (li.getAttribute('aria-current') === 'page') {
            out.current++;
            out.currentIsLink = !!li.querySelector('a');
            out.currentText = (li.textContent || '').trim().slice(0, 40);
            out.currentContrast = contrastOf(li);
        }
        /* The design system's separator is a pseudo-element with content: "". Bootstrap's
           .breadcrumb-item::before injects a literal "/" -- so a non-empty content string on any
           item but the first is the two-separator defect this row had to avoid. */
        if (i > 0) {
            var c = getComputedStyle(li, '::before').content;
            if (c && c !== 'none' && c !== '""' && c !== "''") {
                out.doubleSeparator.push({ index: i, content: c });
            }
        }
        var a = li.querySelector('a');
        if (a) {
            out.links.push({
                text: (a.textContent || '').trim().slice(0, 30),
                href: a.getAttribute('href') || '',
                hasDsClass: a.classList.contains('ss-breadcrumb__link'),
                contrast: contrastOf(a),
            });
        }
    });

    var homes = Array.prototype.slice.call(list.querySelectorAll('a'))
        .filter(function (a) { return /\/(dashboard|home)?$/.test(a.getAttribute('href') || '') ||
                                      /dashboard/.test(a.getAttribute('href') || ''); });
    out.homeCount = homes.length;
    out.homeHref = homes.length ? homes[0].getAttribute('href') : null;

    return out;
})()
`;

/*
 * PAGES THE DEV FIXTURE CAN ACTUALLY OPEN, verified by status before this list was written.
 * `/finance` was in the first version and answered 403 "Permission denied" in all four
 * configurations -- it is a module screen this merchant holds no permission for. It reported as
 * four breadcrumb failures, which it was not: the page never rendered the merchant layout at all.
 * An unreachable page is now called a PRECONDITION failure so the two can never be confused.
 *
 * `/product/create` is here deliberately: it is one of the five screens that carried
 * `aria-current="page"` on BOTH the parent link and the leaf, so measuring it live is what proves
 * that fix rather than inferring it from the markup. `/order-kanban` is here because it has a
 * trail link AND a leaf, which is what exercises the separator and the link class.
 */
const PAGES = ['/order', '/product', '/coupon', '/customer', '/product/create', '/order-kanban'];
const AA = 4.5;

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox'] });
    let failures = 0, checks = 0;

    for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
        for (const theme of ['light', 'dark']) {
            setServerState(theme, dir, lang);
            const tag = `${lang}/${dir} ${theme}`;

            const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 } });
            // The notification feed is a concurrent XHR, and the PHP built-in server is
            // single-threaded: letting it through deadlocked the first attempt at this
            // measurement after 1385 requests.
            await ctx.route('**/merchant-notifications/feed*', r => r.abort());
            const page = await ctx.newPage();

            await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
            await page.fill('input[name=email]', EMAIL);
            await page.fill('input[name=password]', PASSWORD);
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
                page.click('button[type=submit]'),
            ]);

            for (const url of PAGES) {
                checks++;
                const resp = await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
                const status = resp ? resp.status() : 0;
                const r = await page.evaluate(PROBE);
                const problems = [];

                // A page that did not render is a precondition failure, not a breadcrumb one.
                if (status !== 200) {
                    process.stdout.write(`PRECOND  ${tag.padEnd(14)} ${url.padEnd(16)} HTTP ${status}` +
                        ' -- page not reachable for this fixture; NOT a breadcrumb result\n');
                    failures++;
                    continue;
                }

                if (!r.nav) problems.push('NO .ss-breadcrumb RENDERED (found:false is a failure)');
                else {
                    if (!r.navLabel) problems.push('the list is not inside a named <nav>');
                    if (r.items === 0) problems.push('the breadcrumb rendered no items');
                    if (r.current !== 1) problems.push(`aria-current="page" count = ${r.current}, must be 1`);
                    if (r.currentIsLink) problems.push('the current page is a link');
                    if (r.legacyItems) problems.push(`${r.legacyItems} legacy .breadcrumb-item inside the list`);
                    if (r.doubleSeparator.length) {
                        problems.push('TWO SEPARATORS on item(s) ' +
                            r.doubleSeparator.map(d => `${d.index}:${d.content}`).join(', '));
                    }
                    if (r.homeCount !== 1) problems.push(`Home crumb count = ${r.homeCount}, must be 1`);
                    /* The layout emits dir="rtl" or dir="" -- never the literal "ltr", because an
                       absent dir IS left-to-right per HTML. Requiring "ltr" made every LTR page a
                       failure against a correct render; the rule is what the document DIRECTION
                       resolves to, not the attribute's spelling. */
                    const resolvedDir = r.dir === 'rtl' ? 'rtl' : 'ltr';
                    if (resolvedDir !== dir) {
                        problems.push(`document direction resolved to "${resolvedDir}" (dir="${r.dir}"), expected "${dir}"`);
                    }
                    if (r.currentContrast === null) problems.push('current-page contrast unmeasurable');
                    else if (r.currentContrast < AA) problems.push(`current page ${r.currentContrast}:1 < ${AA}`);
                    r.links.forEach(l => {
                        if (!l.hasDsClass) problems.push(`link "${l.text}" lacks ss-breadcrumb__link`);
                        if (l.contrast === null) problems.push(`link "${l.text}" contrast unmeasurable`);
                        else if (l.contrast < AA) problems.push(`link "${l.text}" ${l.contrast}:1 < ${AA}`);
                    });
                }

                const ratios = [r.currentContrast].concat(r.links.map(l => l.contrast))
                    .filter(v => typeof v === 'number');
                const line = `${problems.length ? 'FAIL' : 'ok  '}  ${tag.padEnd(14)} ${url.padEnd(16)}` +
                    ` items=${r.items} current=${r.current} home=${r.homeCount}` +
                    ` dir=${r.dir || '-'} lang=${r.lang || '-'} bg=${r.pageBg || '-'}` +
                    ` ratios=[${ratios.join(', ')}]`;
                process.stdout.write(line + '\n');
                if (problems.length) {
                    failures++;
                    problems.forEach(p => process.stdout.write(`        -> ${p}\n`));
                }
            }

            await ctx.close();
        }
    }

    await browser.close();
    process.stdout.write(`\n${checks} page-configurations checked, ${failures} with a problem\n`);
    // Restore a sane default so the dev database is not left in the last loop's state.
    setServerState('light', 'ltr', 'en');
    process.exit(failures ? 1 : 0);
})();
