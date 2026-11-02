/**
 * P2-05-4. The category and attribute screens, driven in the running application.
 * ============================================================================================
 *
 * Four configurations (en/ar x light/dark), three screens each: the category list, the
 * attribute list, and the create-category modal — which is where this slice's least visible fix
 * lives, since every label on that form used to render `<label for="">`.
 *
 * WHAT IT FAILS ON, beyond taking a picture. A screenshot proves nothing on its own; each of
 * these is a premise that, unmet, makes the image misleading rather than merely empty:
 *
 *   1. THE BROWSER IS ON THE SCREEN THE FILENAME CLAIMS. `/product-attributes` answers
 *      `redirect()->back()` without `Manage Attributes`, and during this slice that silently put
 *      the CATEGORY list under an `attributes` label in the sibling harness for a whole run.
 *   2. the stylesheet the SERVER sent is the one the combination asked for — a client attribute
 *      is not the theme;
 *   3. the lists rendered real rows, so an empty table is not filed as evidence of a list;
 *   4. EVERY LABEL ON THE CREATE FORM RESOLVES TO A CONTROL. This is the fix the pictures cannot
 *      show: a `<label for="">` looks identical to a working one. Read off the DOM by resolving
 *      each `for` to an element that exists;
 *   5. the two switches have a non-empty accessible name — they had none at all before;
 *   6. the parent-category options are indented AND carry no live markup, which is the pair
 *      `buildCategoryTree()` has to satisfy at once after P2-05-4 escaped the name in it;
 *   7. no Phase 02 string renders in English inside the Arabic runs;
 *   8. no uncaught page error and no 500 anywhere but the known MySQL-only dashboard chart
 *      (ledger blocker B5), excluded BY NAME so a 500 elsewhere still fails the run.
 *
 * usage: node shoot-categories-attributes-live.cjs [baseUrl] [outDir]
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
const OUT = process.argv[3] || path.join(__dirname, '..', 'evidence', 'p2-05-4');

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

    for (const locale of ['en', 'ar']) {
        for (const theme of ['light', 'dark']) {
            setServerState(theme, locale === 'ar' ? 'rtl' : 'ltr');

            const tag = `${locale}-${theme}`;
            const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
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

            // ------------------------------------------------------------------ the two lists
            for (const [name, url] of [['category', '/category'], ['attributes', '/product-attributes']]) {
                await page.goto(BASE + url, { waitUntil: 'networkidle' });
                await page.waitForTimeout(900);   // the tooltip plugin blanks `title` on init

                say(new URL(page.url()).pathname === url,
                    `${tag} ${name} — the browser is on ${url} (${new URL(page.url()).pathname})`);

                const sheet = await page.evaluate(() => {
                    const el = document.getElementById('main-style-link');
                    return el ? (el.getAttribute('href') || '').split('/').pop() : '(none)';
                });
                say(/style-dark\.css/.test(sheet) === (theme === 'dark') && /rtl/i.test(sheet) === (locale === 'ar'),
                    `${tag} ${name} — the SERVER sent the ${theme}/${locale} stylesheet (${sheet})`);

                const rows = await page.evaluate(() => document.querySelectorAll('table tbody tr').length);
                say(rows > 0, `${tag} ${name} — the list rendered real rows (${rows})`);

                await page.screenshot({ path: path.join(OUT, `${name === 'category' ? '01' : '02'}-${name}-list-${tag}.png`), fullPage: true });
            }

            // --------------------------------------------------- the create-category modal
            await page.goto(`${BASE}/category`, { waitUntil: 'networkidle' });
            await page.waitForTimeout(900);

            /*
             * `:visible`, and it is not a nicety. The page header renders the action buttons
             * TWICE -- once in a collapsed `.dropdown-menu` and once in the bar -- so
             * `locator(...).first()` picks the hidden copy and the click times out. Measured:
             * two matches, the first 0x0 inside a `display:none` ancestor, the second 99x32.
             */
            await page.locator('a[data-url$="/category/create"]:visible').first().click().catch(() => {});
            // `#commanModel` is the host's own id, misspelled in public/js/custom.js.
            await page.waitForSelector('#commanModel.show form', { timeout: 10000 }).catch(() => {});
            await page.waitForTimeout(600);

            /*
             * THE MODAL IS A HARD PREMISE. The first version of this read
             * `document.querySelector('.modal.show') || document` and, when the click failed,
             * measured the LIST PAGE instead: "every label resolves to a control (5)" passed
             * against the page chrome while the form it names was never on screen. A fallback
             * scope turns a failed interaction into a quiet pass about something else.
             */
            const modalOpen = await page.evaluate(() => !!document.querySelector('#commanModel.show form'));
            say(modalOpen, `${tag} create-category — the create modal opened`);

            await page.evaluate(CONTRAST_FN);

            const form = modalOpen ? await page.evaluate(() => {
                const scope = document.querySelector('#commanModel.show');

                /* A label "works" only if its `for` names an element that EXISTS. An empty `for`
                   and a `for` pointing at a removed id look the same in a screenshot. */
                const labels = Array.prototype.slice.call(scope.querySelectorAll('label[for]'))
                    .map((l) => ({
                        text: (l.textContent || '').trim().slice(0, 40),
                        target: l.getAttribute('for'),
                        resolves: !!(l.getAttribute('for') && scope.querySelector('#' + CSS.escape(l.getAttribute('for')))),
                    }));

                const switchName = (id) => {
                    const el = scope.querySelector('#' + id);
                    if (!el) { return 'absent'; }
                    const lab = scope.querySelector('label[for="' + id + '"]');
                    return (el.getAttribute('aria-label') || (lab ? (lab.textContent || '').trim() : '')) || '';
                };

                const opts = Array.prototype.slice.call(scope.querySelectorAll('select[name="parent_id"] option'));

                return {
                    labels: labels,
                    emptyFor: scope.querySelectorAll('label[for=""]').length,
                    trending: switchName('customCheckdef1trending'),
                    status: switchName('status'),
                    // innerHTML, not textContent: the indent is &nbsp; markup and must survive,
                    // while anything that could EXECUTE must not.
                    optionHtml: opts.map((o) => o.innerHTML.trim()).filter(Boolean),
                    dsButtons: scope.querySelectorAll('.ss-btn').length,
                    legacyButtons: scope.querySelectorAll('.btn-badge').length,

                    /*
                     * EVERY PIECE OF TEXT IN THE FORM, measured. Added after a screenshot showed
                     * the two switch captions rendering visibly dimmer than the fields above
                     * them while this harness reported 66/66 — it was checking that labels
                     * RESOLVE, never that they can be READ. The acceptance for this slice asks
                     * for AA in all four configurations and nothing was measuring it here.
                     */
                    text: Array.prototype.slice.call(scope.querySelectorAll('label, .ss-label, .ss-field__hint, .ss-btn, option'))
                        .filter((el) => (el.textContent || '').trim() !== '' && el.getClientRects().length > 0)
                        .map((el) => ({
                            text: (el.textContent || '').trim().slice(0, 32),
                            cls: (el.className || '').toString().slice(0, 40),
                            ratio: window.__ssContrast(el),
                            size: parseFloat(getComputedStyle(el).fontSize),
                            bold: (parseInt(getComputedStyle(el).fontWeight, 10) || 400) >= 700,
                        })),

                    /* Nothing may spill out of the dialog body: the first version of the switch
                       labels wrapped over two lines and ran into the footer. */
                    overflow: (function () {
                        const body = scope.querySelector('.modal-body');
                        if (!body) { return -1; }
                        const b = body.getBoundingClientRect();
                        return Array.prototype.slice.call(body.querySelectorAll('*'))
                            .filter((el) => el.getClientRects().length > 0)
                            .filter((el) => el.getBoundingClientRect().bottom > b.bottom + 2).length;
                    }()),
                };
            }) : null;

            if (!modalOpen) {
                console.log(`  SKIP  ${tag} create-category — the modal did not open, so its checks are not run`);
            }

            if (form) {
            say(form.labels.length > 0 && form.labels.every((l) => l.resolves),
                `${tag} create-category — every label resolves to a control (${form.labels.length})`
                + (form.labels.filter((l) => !l.resolves).length
                    ? ` — broken: ${form.labels.filter((l) => !l.resolves).map((l) => l.text + '→' + l.target).join(', ')}` : ''));
            say(form.emptyFor === 0,
                `${tag} create-category — no label carries an empty for="" (${form.emptyFor})`);
            say(form.trending !== '' && form.trending !== 'absent',
                `${tag} create-category — the Trending switch has an accessible name ("${form.trending}")`);
            say(form.status !== '' && form.status !== 'absent',
                `${tag} create-category — the Status switch has an accessible name ("${form.status}")`);
            say(form.dsButtons > 0 && form.legacyButtons === 0,
                `${tag} create-category — the footer uses the design system (${form.dsButtons} ss-btn, ${form.legacyButtons} legacy)`);

            const indented = form.optionHtml.filter((h) => h.includes('&nbsp;')).length;
            const live = form.optionHtml.filter((h) => /<(img|script|svg|iframe)/i.test(h)).length;
            say(live === 0,
                `${tag} create-category — no parent option contains live markup (${live})`);
            say(form.optionHtml.length > 0,
                `${tag} create-category — the parent select has options (${form.optionHtml.length}, ${indented} indented)`);

            /* 3:1 for large text (>=24px, or >=18.66px bold), 4.5:1 otherwise — WCAG 1.4.3. */
            const unreadable = form.text.filter((t) => t.ratio < ((t.size >= 24 || (t.bold && t.size >= 18.66)) ? 3 : 4.5));
            say(unreadable.length === 0,
                `${tag} create-category — every piece of text reaches AA (${form.text.length} measured)`
                + (unreadable.length
                    ? ` — worst ${Math.min.apply(null, unreadable.map((u) => u.ratio))}:1 on "${unreadable.sort((a, b) => a.ratio - b.ratio)[0].text}"` : ''));

            say(form.overflow === 0,
                `${tag} create-category — nothing overflows the dialog body (${form.overflow})`);

            await page.screenshot({ path: path.join(OUT, `03-category-create-${tag}.png`) });
            }

            // ------------------------------------------------------------- the pilot, unchanged
            await page.goto(`${BASE}/product-attributes`, { waitUntil: 'networkidle' });
            /*
             * `/attribute-option/`, NOT `/product-attribute-option/`. The route is NAMED
             * `product-attribute-option.show` but its URI is `attribute-option/{id?}`
             * (routes/web.php:919), and the first version of this selector matched the name --
             * so it found zero links on every run and reported the pilot screen as unreachable.
             *
             * The link is also inside a DataTables cell drawn by ajax AFTER networkidle
             * resolves, so it has to be waited for rather than queried straight after goto.
             */
            await page.waitForSelector('a[href*="/attribute-option/"]', { timeout: 10000 }).catch(() => {});
            const cfg = page.locator('a[href*="/attribute-option/"]:visible').first();
            if (await cfg.count() > 0) {
                await Promise.all([page.waitForLoadState('networkidle'), cfg.click()]);
                await page.waitForTimeout(400);
                const sortables = await page.evaluate(() => document.querySelectorAll('.ss-sort-item').length);
                say(sortables > 0, `${tag} pilot — the attribute-option screen still renders its sortable list (${sortables})`);
                await page.screenshot({ path: path.join(OUT, `04-attribute-options-pilot-${tag}.png`), fullPage: true });
            } else {
                say(false, `${tag} pilot — no "Configure terms" link on the attribute list to follow`);
            }

            // ------------------------------------------------------------------ cross-cutting
            if (locale === 'ar') {
                const english = await page.evaluate(() => {
                    const t = document.body.innerText;
                    return ['Configure terms', 'Add Attribute', 'Add Category'].filter((s) => t.includes(s));
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

    await browser.close();
    console.log(`\n${pass + fail} cases, ${pass} passed, ${fail} failed`);
    console.log(`screenshots in ${OUT}`);
    process.exit(fail === 0 ? 0 : 1);
})();
