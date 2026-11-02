/**
 * P2-05-5. The staff and roles screens, driven in the running application.
 * ============================================================================================
 *
 * Four configurations (en/ar x light/dark) at TWO viewports (1440 desktop and 390 mobile),
 * because the acceptance for this slice asks for responsive behaviour as well as AA, and the
 * staff list is the widest table in this area.
 *
 * WHAT IT FAILS ON, beyond taking a picture — each a premise that, unmet, makes the image
 * misleading rather than merely empty:
 *
 *   1. THE BROWSER IS ON THE SCREEN THE FILENAME CLAIMS. `/users` is a CARD GRID, `/user-list`
 *      is the table; a permission redirect silently swapped two screens during P2-05-4 and the
 *      run reported failures naming an order reference on a screen with no orders.
 *   2. the stylesheet the SERVER sent is the one the combination asked for;
 *   3. the lists rendered real rows — an empty table satisfies every per-row assertion, which is
 *      how F102/F103 produced a clean run over a screen with nothing on it;
 *   4. EVERY `for` IN THE DOCUMENT RESOLVES, AND NO id IS EMITTED TWICE. This is the defect
 *      family this slice met four times (F132, F134 and twice in the Admin Info modal), and it
 *      is invisible in a screenshot: a broken `for` looks exactly like a working one;
 *   5. every interactive control has an accessible name, resolved the way the accname spec
 *      does — aria-label, aria-labelledby, own text, then `title` LAST and from the LIVE
 *      attribute, because the tooltip plugin blanks it on init;
 *   6. AA contrast over the EFFECTIVE background with alpha compositing;
 *   7. NOTHING OVERFLOWS THE VIEWPORT HORIZONTALLY at 390px;
 *   8. no Phase 02 string renders in English on the Arabic run;
 *   9. no uncaught page error and no 500 but the known MySQL-only dashboard chart (blocker B5).
 *
 * usage: node shoot-staff-roles-live.cjs [baseUrl] [outDir]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');


/*
 * WCAG contrast over the EFFECTIVE background. Alpha compositing matters: a label drawn in a
 * semi-transparent muted colour over a dark surface is not the ratio its declared colour
 * suggests, and this phase has twice recorded readings that only appear once the layers are
 * composited (the order list's 1.12:1, the product options' 1.00:1 white-on-white).
 */
const CONTRAST_FN = `(function () {
    function parse(c) {
        var m = (c || '').match(/rgba?\\(([^)]+)\\)/);
        if (!m) { return [0, 0, 0, 1]; }
        var p = m[1].split(',').map(function (x) { return parseFloat(x); });
        return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1];
    }
    function lum(c) {
        var a = [c[0], c[1], c[2]].map(function (v) {
            v /= 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
    }
    function ratio(a, b) {
        var l1 = lum(a), l2 = lum(b);
        return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    }
    function over(fg, bg) {
        var a = fg[3];
        return [fg[0] * a + bg[0] * (1 - a), fg[1] * a + bg[1] * (1 - a), fg[2] * a + bg[2] * (1 - a), 1];
    }
    function effBg(el) {
        var n = el;
        while (n && n !== document.documentElement) {
            var c = parse(getComputedStyle(n).backgroundColor);
            if (c[3] > 0) { return c; }
            n = n.parentElement;
        }
        return [255, 255, 255, 1];
    }
    window.__ssContrast = function (el) {
        var cs = getComputedStyle(el);
        var bg = effBg(el);
        return Math.round(ratio(over(parse(cs.color), bg), bg) * 100) / 100;
    };
}())`;

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const OUT = process.argv[3] || path.join(__dirname, '..', 'evidence', 'p2-05-5');

function setServerState(theme, dir) {
    const repo = path.join(__dirname, '..', '..', '..', '..');
    execFileSync('php', [path.join(__dirname, 'set-preview-state.php'), '1', theme, dir], {
        cwd: repo,
        env: Object.assign({}, process.env, {
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: path.join(repo, 'database', 'p2dev.sqlite'),
            CACHE_STORE: 'file',
        }),
        stdio: 'pipe',
    });
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch();
    let pass = 0, fail = 0;
    const say = (ok, msg) => { ok ? pass++ : fail++; console.log(`  ${ok ? 'ok  ' : 'FAIL'}  ${msg}`); };

    const SCREENS = [
        { name: 'staff', url: '/user-list' },
        { name: 'roles', url: '/roles' },
    ];

    for (const vp of [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844 }]) {
        for (const locale of ['en', 'ar']) {
            for (const theme of ['light', 'dark']) {
                setServerState(theme, locale === 'ar' ? 'rtl' : 'ltr');

                const tag = `${vp.name}-${locale}-${theme}`;
                const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
                const page = await ctx.newPage();
                const errors = [];
                page.on('pageerror', (e) => errors.push(String(e)));
                page.on('response', (r) => {
                    if (r.status() >= 500 && !r.url().includes('/top-brand-category-chart')) {
                        errors.push(`${r.status()} ${r.url()}`);
                    }
                });

                await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
                await page.fill('input[name="email"]', 'merchant@example.test');
                await page.fill('input[name="password"]', 'password');
                await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
                if (page.url().includes('/login')) { throw new Error('login failed'); }
                await page.goto(`${BASE}/change-language/${locale}`, { waitUntil: 'domcontentloaded' }).catch(() => {});

                for (const screen of SCREENS) {
                    await page.goto(BASE + screen.url, { waitUntil: 'networkidle' });
                    await page.waitForTimeout(900);   // the tooltip plugin blanks `title` on init

                    say(new URL(page.url()).pathname === screen.url,
                        `${tag} ${screen.name} — on ${screen.url} (${new URL(page.url()).pathname})`);

                    const sheet = await page.evaluate(() => {
                        const el = document.getElementById('main-style-link');
                        return el ? (el.getAttribute('href') || '').split('/').pop() : '(none)';
                    });
                    say(/style-dark\.css/.test(sheet) === (theme === 'dark') && /rtl/i.test(sheet) === (locale === 'ar'),
                        `${tag} ${screen.name} — SERVER sent the ${theme}/${locale} stylesheet (${sheet})`);

                    await page.evaluate(CONTRAST_FN);

                    const r = await page.evaluate(() => {
                        const rows = document.querySelectorAll('table tbody tr').length;

                        const ids = [].slice.call(document.querySelectorAll('[id]')).map((e) => e.id).filter(Boolean);
                        const counts = {};
                        ids.forEach((i) => { counts[i] = (counts[i] || 0) + 1; });
                        /*
                         * HOST CHROME IS DECLARED BY NAME, NOT WAIVED BY PATTERN.
                         * `layouts/app.blade.php` renders TWO shared modals (`commanModel` and
                         * `commanModelOver`) and both label their title with the same id. That is
                         * the host's markup on every admin page, not this slice's, and fixing it
                         * means editing a layout all 70 modules render through. Listing the exact
                         * id keeps the check biting on everything else: a NEW duplicate still
                         * fails, including a new one in the host.
                         */
                        const HOST_CHROME_DUPLICATE_IDS = ['modelCommanModelLabel'];

                        const duplicateIds = Object.keys(counts)
                            .filter((i) => counts[i] > 1)
                            .filter((i) => HOST_CHROME_DUPLICATE_IDS.indexOf(i) === -1);

                        const dangling = [].slice.call(document.querySelectorAll('label[for]'))
                            .map((l) => l.getAttribute('for'))
                            .filter((t) => t && !document.getElementById(t));

                        /* accname order, with `title` LAST and read from the LIVE attribute so a
                           tooltip plugin that has already emptied it is reflected. */
                        const nameOf = (el) => {
                            const aria = (el.getAttribute('aria-label') || '').trim();
                            if (aria) { return aria; }
                            const by = el.getAttribute('aria-labelledby');
                            if (by) {
                                const t = document.getElementById(by);
                                if (t && (t.textContent || '').trim()) { return (t.textContent || '').trim(); }
                            }
                            const own = (el.textContent || '').trim();
                            if (own) { return own; }
                            return (el.getAttribute('title') || '').trim();
                        };

                        const controls = [].slice.call(document.querySelectorAll(
                            'table tbody a, table tbody button, .ss-btn, [data-ajax-popup="true"]'
                        )).filter((el) => el.getClientRects().length > 0);

                        const unnamed = controls.filter((el) => !nameOf(el)).map((el) => el.className.toString().slice(0, 40));

                        const text = [].slice.call(document.querySelectorAll(
                            'table tbody td, .ss-btn, .ss-chip, .ss-badge, label'
                        )).filter((el) => (el.textContent || '').trim() && el.getClientRects().length > 0)
                            .map((el) => ({
                                text: (el.textContent || '').trim().slice(0, 28),
                                ratio: window.__ssContrast(el),
                                size: parseFloat(getComputedStyle(el).fontSize),
                                bold: (parseInt(getComputedStyle(el).fontWeight, 10) || 400) >= 700,
                            }));

                        return {
                            rows: rows,
                            duplicateIds: duplicateIds,
                            dangling: dangling,
                            controls: controls.length,
                            unnamed: unnamed,
                            text: text,
                            docWidth: document.documentElement.scrollWidth,
                            viewWidth: document.documentElement.clientWidth,
                        };
                    });

                    say(r.rows > 0, `${tag} ${screen.name} — real data rows (${r.rows})`);
                    say(r.duplicateIds.length === 0,
                        `${tag} ${screen.name} — no id is emitted twice`
                        + (r.duplicateIds.length ? ` — ${r.duplicateIds.slice(0, 4).join(', ')}` : ''));
                    say(r.dangling.length === 0,
                        `${tag} ${screen.name} — every label's for= resolves`
                        + (r.dangling.length ? ` — dangling: ${r.dangling.slice(0, 4).join(', ')}` : ''));
                    say(r.controls > 0 && r.unnamed.length === 0,
                        `${tag} ${screen.name} — every visible control has an accessible name (${r.controls})`
                        + (r.unnamed.length ? ` — ${r.unnamed.length} unnamed: ${r.unnamed.slice(0, 3).join(', ')}` : ''));

                    const bad = r.text.filter((t) => t.ratio < ((t.size >= 24 || (t.bold && t.size >= 18.66)) ? 3 : 4.5));
                    say(bad.length === 0,
                        `${tag} ${screen.name} — every piece of text reaches AA (${r.text.length} measured)`
                        + (bad.length ? ` — worst ${Math.min.apply(null, bad.map((b) => b.ratio))}:1 on "${bad.sort((a, b) => a.ratio - b.ratio)[0].text}"` : ''));

                    /* The DOCUMENT must not scroll sideways. A fixed-width table inside
                       `.table-responsive` is fine — that scrolls its own wrapper. */
                    say(r.docWidth <= r.viewWidth + 1,
                        `${tag} ${screen.name} — no horizontal page overflow (${r.docWidth} vs ${r.viewWidth})`);

                    await page.screenshot({
                        path: path.join(OUT, `${screen.name === 'staff' ? '01' : '02'}-${screen.name}-${tag}.png`),
                        fullPage: true,
                    });
                }

                if (locale === 'ar') {
                    const english = await page.evaluate(() => {
                        const t = document.body.innerText;
                        return ['Add Role', 'Add User', 'Show More'].filter((s) => t.includes(s));
                    });
                    say(english.length === 0,
                        `${tag} — no Phase 02 string renders in English on the Arabic run`
                        + (english.length ? ` — ${english.join(', ')}` : ''));
                }

                say(errors.length === 0, `${tag} — no uncaught page error and no unexpected 500`
                    + (errors.length ? ` — ${errors.slice(0, 2).join('; ')}` : ''));

                await ctx.close();
            }
        }
    }

    await browser.close();
    console.log(`\n${pass + fail} cases, ${pass} passed, ${fail} failed`);
    console.log(`screenshots in ${OUT}`);
    process.exit(fail === 0 ? 0 : 1);
})();
