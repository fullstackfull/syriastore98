/**
 * P2-03 / P2-04. THE ROW ACTIONS ON BOTH LISTS, DRIVEN IN THE RUNNING APPLICATION.
 *
 * WHY IT EXISTS. Four defects on these two cells, none of which any test in the repository
 * could see, and all four found by cropping one table row at 3x and looking at it.
 *
 *  1. THREE OF FOUR BUTTONS HAD NO ACCESSIBLE NAME. They carried `title`, which looks like a
 *     name and is not: `data-bs-toggle="tooltip"` makes Bootstrap move the title into
 *     `data-bs-original-title` and blank the attribute on initialisation. So the DOM a test
 *     renders has a name and the page a merchant uses does not -- which is exactly why this
 *     check has to run in a browser, after the scripts.
 *  2. ICON CONTRAST BELOW THE 3:1 NON-TEXT MINIMUM, worst `btn-info`'s pencil at 1.09:1 in
 *     dark mode: white on a light-blue background the vendored dark theme leaves alone.
 *  3. A BUTTON WITH NO GLYPH AT ALL. `ti-layout-sidebar-right-expand` is not in the bundled
 *     icon font, so the quick-preview button on BOTH lists rendered an empty 18px box for as
 *     long as the feature existed. `Tests\Unit\Ui\IconNamesExistTest` now catches the NAME;
 *     this catches the PAINTED RESULT, which is the thing that actually matters and which no
 *     amount of source reading can establish.
 *  4. The status column's own controls measured below AA in 44 of 56 readings.
 *
 * usage: node check-row-actions-live.cjs [baseUrl]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const EMAIL = 'merchant@example.test';
const PASSWORD = 'password';

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

/* WCAG luminance, contrast and alpha compositing -- the same arithmetic as
   measure-contrast-live.cjs. A computed `backgroundColor` is `rgba(0,0,0,0)` on any element
   that does not paint one, so the effective background has to be walked up the ancestors or
   every ratio is taken against transparent black. */
const PROBE = `(function () {
    function parse(c) {
        var m = String(c).match(/rgba?\\(([^)]+)\\)/);
        if (!m) return null;
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
            if (bg && bg.a > 0) st.push(bg);
            if (bg && bg.a === 1) break;
            n = n.parentElement;
        }
        for (var i = st.length - 1; i >= 0; i--) acc = over(st[i], acc);
        return acc;
    }

    /* THE ACCESSIBLE NAME AS THE PAGE ACTUALLY EXPOSES IT, in the order the accname spec
       resolves: aria-label, then aria-labelledby, then the element's own text, then title.
       \'title\' is LAST and is read from the live attribute, so a tooltip plugin that has
       emptied it is reflected rather than papered over. */
    function accName(el) {
        var label = (el.getAttribute('aria-label') || '').trim();
        if (label) return label;
        var by = el.getAttribute('aria-labelledby');
        if (by) {
            var t = by.split(/\\s+/).map(function (id) {
                var n = document.getElementById(id);
                return n ? (n.textContent || '').trim() : '';
            }).join(' ').trim();
            if (t) return t;
        }
        var own = (el.textContent || '').trim();
        if (own) return own;
        return (el.getAttribute('title') || '').trim();
    }

    /*
     * REAL DATA ROWS ONLY. The selector "table tbody tr" also matches DataTables' own
     * "No data available in table" row (a td.dataTables_empty), so an EMPTY list used to report a
     * non-zero rowCount and satisfy the premise below -- after which every per-action assertion
     * passed because there were no actions to measure.
     *
     * MEASURED, not hypothetical: driving the customer list's delete control archived all three
     * fixture customers, and the next run of this file reported accessible names and icon contrast
     * as PASSING on an empty table. emptyNotice is reported separately so the premise can refuse
     * outright instead of quietly measuring nothing.
     *
     * NO BACKTICKS IN THIS COMMENT. PROBE is a template literal, so a backtick here ends the
     * string and the file stops parsing. That trap has now bitten this project four times, which
     * is why it is called out at the point where someone would reintroduce it.
     */
    var emptyNotice = document.querySelectorAll('td.dataTables_empty').length;

    /*
     * VISIBLE TABLES ONLY, and this is the second thing the row count got wrong.
     *
     * The customer index renders TWO tables of the same three customers: the live DataTable and a
     * hidden alternate view (the grid/filter markup) that carries no row actions. Counting both
     * gave rowCount 6 for 3 customers, so the delete assertion compared 3 against 6 and FAILED on
     * a screen where every row really does have a delete control. A premise that over-counts
     * invents defects exactly as readily as one that under-counts invents passes.
     *
     * offsetParent is null for an element in a display:none subtree, which is how the hidden view
     * is hidden here.
     */
    var rows = [].slice.call(document.querySelectorAll('table'))
        .filter(function (t) { return t.offsetParent !== null; })
        .reduce(function (acc, t) {
            return acc.concat([].slice.call(t.querySelectorAll('tbody tr')));
        }, [])
        .filter(function (tr) { return !tr.querySelector('td.dataTables_empty'); });
    var actions = [], badges = [], confirms = [];

    rows.forEach(function (tr, i) {
        [].slice.call(tr.querySelectorAll('.ss-btn, .btn')).forEach(function (el) {
            var icon = el.querySelector('i, svg');
            var target = icon || el;
            var cs = getComputedStyle(target);
            var fg = parse(cs.color), bg = effBg(target);
            var ir = icon ? icon.getBoundingClientRect() : null;
            actions.push({
                row: i,
                cls: (el.className || '').toString().slice(0, 60),
                icon: icon ? (icon.className || '').toString() : '',
                name: accName(el),
                hasText: !!(el.textContent || '').trim(),
                iconWidth: ir ? Math.round(ir.width) : null,
                ratio: Math.round(ratio(over(fg, bg), bg) * 100) / 100
            });
        });

        /* EVERY DELETE BUTTON HAS A FORM TO SUBMIT, AND IT SPOOFS DELETE.
           'public/js/custom.js' handles '.show_confirm' with closest('form'), so a button
           outside any form is a delete that silently does nothing.

           WHAT THIS DOES NOT CATCH, stated because it was tried: a missing 'Form::close()'.
           F40 was exactly that, and driving the list with and without the close tag renders an
           IDENTICAL DOM -- the cell is injected as a DataTables fragment, so the parser closes
           the form at the end of the fragment either way. The bite came back clean because
           there was nothing to catch, not because this assertion is weak. It guards the case
           it names: a control that has drifted out of its form entirely. */
        [].slice.call(tr.querySelectorAll('.show_confirm')).forEach(function (el) {
            confirms.push({
                row: i,
                hasForm: !!el.closest('form'),
                method: el.closest('form') ? (el.closest('form').querySelector('input[name="_method"]') || {}).value || '' : ''
            });
        });

        [].slice.call(tr.querySelectorAll('.ss-badge')).forEach(function (el) {
            var cs = getComputedStyle(el);
            var fg = parse(cs.color), bg = effBg(el);
            badges.push({
                row: i,
                cls: (el.className || '').toString(),
                text: (el.textContent || '').trim().slice(0, 40),
                dots: el.querySelectorAll('.ss-badge__dot[aria-hidden="true"]').length,
                ratio: Math.round(ratio(over(fg, bg), bg) * 100) / 100
            });
        });
    });

    return { rowCount: rows.length, emptyNotice: emptyNotice, actions: actions, badges: badges, confirms: confirms };
})()`;

(async () => {
    const browser = await chromium.launch();
    let pass = 0, fail = 0;
    const say = (ok, msg) => { ok ? pass++ : fail++; console.log(`  ${ok ? 'ok  ' : 'FAIL'}  ${msg}`); };

    /*
     * P2-05-2. `customers` is added here rather than in a new harness, deliberately: the
     * accessible-name rule, the tooltip-plugin trap and the four-configuration contrast sweep are
     * already encoded below, and a second file would be a second place for them to drift. The
     * customers screen was measured by this harness the day it was added and failed on names.
     */
    /*
     * P2-05-4 adds `categories` and `attributes` for the same reason, and with the same result:
     * both screens were measured by this harness the day they were added and both failed on
     * names -- two unnamed controls per row on each, plus an unnamed primary "Add" button in the
     * page header, all of them carrying a `title` the tooltip plugin had already blanked.
     */
    for (const screen of [{ name: 'orders', url: '/order' }, { name: 'products', url: '/product' }, { name: 'customers', url: '/customer' }, { name: 'categories', url: '/category' }, { name: 'attributes', url: '/product-attributes' }, { name: 'staff', url: '/user-list' }, { name: 'roles', url: '/roles' }]) {
        /*
         * P2-05-5 adds `staff` and `roles`. NOTE THE STAFF URL: `/user-list`, not `/users`.
         * `/users` renders a CARD GRID (`delivery-user-cards`) with its own inline controls and
         * no table at all, so pointing this harness at it would meet the "has real data rows"
         * premise with zero and skip every assertion below. `/user-list` is the DataTable that
         * renders `users/action.blade.php`, which is the partial this slice converted. The card
         * grid's controls are a separate surface and are covered by the shoot harness.
         */
        for (const locale of ['en', 'ar']) {
            for (const theme of ['light', 'dark']) {
                setServerState(theme, locale === 'ar' ? 'rtl' : 'ltr');

                const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
                const page = await ctx.newPage();
                const errors = [];
                page.on('pageerror', (e) => errors.push(String(e)));
                page.on('response', (r) => { if (r.status() >= 500) errors.push(`${r.status()} ${r.url()}`); });

                await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
                await page.fill('input[name="email"]', EMAIL);
                await page.fill('input[name="password"]', PASSWORD);
                await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
                if (page.url().includes('/login')) throw new Error('login failed');

                await page.goto(`${BASE}/change-language/${locale}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
                await page.goto(BASE + screen.url, { waitUntil: 'networkidle' });
                // The tooltip plugin empties `title` on init; measuring before that would
                // report a name the merchant's page does not have.
                await page.waitForTimeout(900);

                const tag = `${screen.name} ${locale}/${theme}`;

                /*
                 * PREMISE ZERO: WE ARE ON THE SCREEN WE ASKED FOR.
                 *
                 * Added by P2-05-4, because its absence produced a whole screen's worth of
                 * confidently wrong output. `/product-attributes` refuses a user without
                 * `Manage Attributes` with `redirect()->back()`, which landed on the PREVIOUS
                 * screen in this very loop -- so the harness measured the category list, labelled
                 * every line `attributes`, and reported contrast failures naming `#ORD-2047`, an
                 * order reference, on a screen that has no orders on it.
                 *
                 * Note which way that failed: it did NOT pass vacuously, it FAILED LOUDLY about
                 * the wrong page. A redirect is indistinguishable from the real screen to every
                 * assertion below, so the check has to be made before any of them.
                 */
                const landed = new URL(page.url()).pathname;
                say(landed === screen.url,
                    `${tag} — the browser is actually on ${screen.url}`
                    + (landed === screen.url ? '' : ` — it was redirected to ${landed}, so NOTHING below would be measuring this screen (missing permission? the fixture user needs the Manage permission for it)`));
                if (landed !== screen.url) {
                    console.log(`  SKIP  ${tag} — not on the requested screen, remaining checks not run`);
                    await ctx.close();
                    continue;
                }

                const r = await page.evaluate(PROBE);

                // PREMISE: there are rows, and they have actions. Every assertion below is
                // satisfied for free by an empty list.
                /*
                 * THE PREMISE REFUSES AN EMPTY LIST OUTRIGHT, rather than letting the per-action
                 * assertions below pass for free. An empty table is a FIXTURE failure, and saying
                 * so is the difference between a green run and a meaningless one.
                 */
                say(r.rowCount > 0 && r.emptyNotice === 0,
                    `${tag} — the list has real data rows (${r.rowCount}`
                    + (r.emptyNotice ? `, and DataTables is showing its "no data" notice — THE FIXTURE IS EMPTY, so nothing below means anything` : '')
                    + ')');
                say(r.actions.length >= r.rowCount, `${tag} — every row has at least one action (${r.actions.length})`);
                if (r.rowCount === 0) {
                    console.log(`  SKIP  ${tag} — no data rows, so the remaining checks are not run`);
                    await ctx.close();
                    continue;
                }
                /*
                 * BADGES ARE CHECKED WHEREVER THEY ARE, not where a screen name says to expect
                 * them -- and the first version of this check got that wrong in a way that read
                 * as a pass. It keyed the badge assertions on `screen.name === 'orders'`,
                 * because at the time only the order list used `ss-badge`. The product list's
                 * stock column moved onto the same badge an hour later (F45) and the check went
                 * on printing "no status badges on this list by design (10 found)" -- skipping
                 * ten real badges while saying so out loud.
                 *
                 * So: the order list MUST have badges (a list with none means the status column
                 * stopped rendering), and every badge found anywhere is measured.
                 */
                if (screen.name === 'orders') {
                    say(r.badges.length > 0,
                        `${tag} — the order status column rendered badges (${r.badges.length})`);
                }

                const expectsBadges = r.badges.length > 0;
                if (!expectsBadges) {
                    console.log(`  n/a   ${tag} — this list renders no ss-badge, so there is nothing to measure`);
                }

                const unnamed = r.actions.filter((a) => !a.name);
                say(unnamed.length === 0,
                    `${tag} — every row action has an accessible name`
                    + (unnamed.length ? ` — ${unnamed.length} unnamed: ${unnamed.map((a) => a.icon || a.cls).join(', ')}` : ''));

                const blank = r.actions.filter((a) => a.icon && !a.hasText && a.iconWidth === 0);
                say(blank.length === 0,
                    `${tag} — every icon-only action paints a glyph`
                    + (blank.length ? ` — ${blank.length} empty: ${blank.map((a) => a.icon).join(', ')}` : ''));

                /* A ROW CONTROL THAT CARRIES TEXT IS TEXT, and needs 4.5:1, not 3:1. The
                   order-reference pill is one: `.btn-primary` is `#fff` on the merchant's
                   brand, which on the default theme measured 2.59:1 -- the same figure F9
                   recorded for the selected sidebar item, because it is the same pair of
                   colours. `--ss-onbrand` (F9's own mechanism) now supplies the label colour. */
                const dimText = r.actions.filter((a) => a.hasText && a.ratio < 4.5);
                say(dimText.length === 0,
                    `${tag} — every row control that carries text reaches 4.5:1`
                    + (dimText.length ? ` — worst ${Math.min(...dimText.map((a) => a.ratio))}:1 on "${dimText[0].name}"` : ''));

                const dimIcons = r.actions.filter((a) => a.icon && a.ratio < 3);
                say(dimIcons.length === 0,
                    `${tag} — every action icon reaches 3:1`
                    + (dimIcons.length ? ` — worst ${Math.min(...dimIcons.map((a) => a.ratio))}:1 on ${dimIcons[0].icon}` : ''));

                if (expectsBadges) {
                    const dimBadges = r.badges.filter((b) => b.ratio < 4.5);
                    say(dimBadges.length === 0,
                        `${tag} — every status badge reaches 4.5:1`
                        + (dimBadges.length ? ` — worst ${Math.min(...dimBadges.map((b) => b.ratio))}:1 on "${dimBadges[0].text}"` : ''));

                    // WCAG 1.4.1: colour alone must not carry meaning. The dot is the second
                    // channel and is hidden from assistive technology, because the text says it.
                    const dotless = r.badges.filter((b) => b.dots !== 1 || !b.text);
                    say(dotless.length === 0,
                        `${tag} — every status badge carries text and exactly one hidden dot`);

                    // And more than one tone is exercised, or "they all reach AA" could be one
                    // colour measured nine times.
                    // More than one tone, or "they all reach AA" could be one colour measured
                    // nine times. The order list exercises five; the stock column two or three.
                    const tones = new Set(r.badges.map((b) => b.cls));
                    const needTones = screen.name === 'orders' ? 3 : 1;
                    say(tones.size >= needTones,
                        `${tag} — at least ${needTones} badge tone(s) on screen (${tones.size})`);
                }

                say(r.confirms.length >= r.rowCount,
                    `${tag} — every row has a delete control (${r.confirms.length})`);
                const orphan = r.confirms.filter((c) => !c.hasForm);
                say(orphan.length === 0,
                    `${tag} — every delete control is inside its form`
                    + (orphan.length ? ` — ${orphan.length} orphaned, so the click submits nothing` : ''));
                const notDelete = r.confirms.filter((c) => c.method.toUpperCase() !== 'DELETE');
                say(notDelete.length === 0,
                    `${tag} — every delete form spoofs DELETE (${r.confirms.length - notDelete.length}/${r.confirms.length})`);

                say(errors.length === 0, `${tag} — no uncaught page error and no 500`
                    + (errors.length ? ` — ${errors[0]}` : ''));

                await ctx.close();
            }
        }
    }

    await browser.close();
    console.log(`\n${pass + fail} cases, ${pass} passed, ${fail} failed`);
    process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
