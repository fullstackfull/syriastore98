/**
 * P2-04-6. THE ORDER DETAIL PAGE, DRIVEN IN THE RUNNING APPLICATION.
 *
 * WHY IT EXISTS. Three blocks on this page had never been seen with content in them --
 * `order_billing_details`, `order_notes`, and the `countries`/`states`/`cities` the two address
 * cards resolve through were all empty in the dev fixture -- so both address cards rendered
 * every field blank and the notes panel rendered nothing at all. An empty block looks exactly
 * like a healthy block with nothing to show, which is what it is for. Nothing looked wrong.
 *
 * GETTING THE NOTES ON SCREEN TOOK THREE FIXTURE FIXES, each of which looked like success:
 * seeding five notes (they went on the oldest order while this check opens the one the list
 * shows first); seeding them on every order (the panel is gated on `Manage Order Note`, which
 * the fixture merchant's role did not hold); and using NULL rather than 0 for
 * `order_notes.customer_id`, which carries a foreign key to `customers`. So this run asserts the
 * CONTENT is there before it measures anything about it -- `notes=0` was the state in which
 * every colour assertion would have passed for free.
 *
 * WHAT IT THEN MEASURED, before the fixes:
 *
 *     dark  customer-note       1.24:1      dark  status button   1.09:1
 *     dark  order-create-note   1.77:1      dark  grand total     1.10:1
 *     dark  admin-private-note  1.86:1      light item name link  2.59:1
 *     dark  stock-mange-note    2.95:1
 *
 * usage: node check-order-detail-live.cjs [baseUrl]
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
   measure-contrast-live.cjs. A computed `backgroundColor` is `rgba(0,0,0,0)` on any element that
   does not paint one, so the effective background is walked up the ancestors; and a tint written
   as `rgba(…, .31)` is NOT its own ratio until it is composited over what is behind it. A probe
   that skipped that step reported a warning label at exactly 1.00:1 earlier in this phase. */
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

    var readings = [];
    function measure(label, el) {
        if (!el) { return; }
        var text = (el.textContent || '').trim();
        if (!text) { return; }
        var cs = getComputedStyle(el);
        var fg = parse(cs.color), bg = effBg(el);
        var size = parseFloat(cs.fontSize), weight = parseInt(cs.fontWeight, 10) || 400;
        var large = size >= 24 || (size >= 18.66 && weight >= 700);
        readings.push({
            label: label, text: text.slice(0, 34),
            ratio: Math.round(ratio(over(fg, bg), bg) * 100) / 100,
            need: large ? 3 : 4.5
        });
    }

    var TINTS = ['stock-mange-note', 'order-create-note', 'order-status-note',
                 'admin-private-note', 'customer-note'];

    /* THE DELETE ICON ON EACH NOTE, which carries no text, so 'measure()' would skip it.
       Three of the five branches had 'text-white' on it and two did not -- an asymmetry from
       the five-way copy-paste rather than a decision -- and white on a LIGHT tint is nothing
       at all. Icons need 3:1, not 4.5:1. */
    var iconReadings = [];
    function measureIcon(label, el) {
        if (!el) { return; }
        var cs = getComputedStyle(el);
        var fg = parse(cs.color), bg = effBg(el);
        iconReadings.push({
            label: label,
            ratio: Math.round(ratio(over(fg, bg), bg) * 100) / 100,
            need: 3
        });
    }

    var tintsFound = 0;
    TINTS.forEach(function (c) {
        var hdr = document.querySelector('.card-header.' + c);
        if (!hdr) { return; }
        tintsFound++;
        measure('note:' + c, hdr.querySelector('.tl-btn'));
        measure('time:' + c, hdr.querySelector('.time'));
        measureIcon('trash:' + c, hdr.querySelector('.order-not-dlt i'));
    });

    /* THE ADDRESS VALUES, not just their count. The first version of this run counted non-empty
       <dd>s and measured none of them -- so it could report both cards "have values" while every
       one of those values was unreadable, which is what a screenshot then showed in dark mode.
       Counting is a premise; the ratio is the check. */
    ['shipping', 'billing'].forEach(function (which) {
        var h = document.querySelector('[data-ss-address="' + which + '"]');
        if (!h) { return; }
        var card = h.closest('.card') || h.parentElement.parentElement;
        [].slice.call(card.querySelectorAll('dt')).forEach(function (dt, i) {
            measure(which + ' label ' + i, dt);
        });
        [].slice.call(card.querySelectorAll('dd')).forEach(function (dd, i) {
            measure(which + ' value ' + i, dd);
        });
    });

    measure('status control', document.querySelector('.order_status_btn'));
    [].slice.call(document.querySelectorAll('.sub-total-footer li')).forEach(function (li, i) {
        measure('totals row ' + i, li);
    });
    [].slice.call(document.querySelectorAll('table tbody td.total')).forEach(function (td, i) {
        measure('item name ' + i, td);
    });

    /* The items table: as many cells per row as the header has columns. A DELETED product used
       to drop the Downloadable Product cell while the header kept its column, which slid Total
       underneath the wrong heading. */
    var itemsTable = null;
    [].slice.call(document.querySelectorAll('table')).forEach(function (t) {
        if (!itemsTable && t.querySelector('td.total')) { itemsTable = t; }
    });
    var cellCounts = [];
    var headerCount = 0;
    if (itemsTable) {
        headerCount = itemsTable.querySelectorAll('thead th').length;
        [].slice.call(itemsTable.querySelectorAll('tbody tr')).forEach(function (tr) {
            cellCounts.push(tr.querySelectorAll('td').length);
        });
    }

    var blank = [].slice.call(document.querySelectorAll('a[target="_blank"]'));

    /* The address cards, found by the hook on their headings -- NOT by the heading text, which is
       translated: the first version of this looked for an English fragment and reported both cards
       missing on the Arabic page while passing on the English one, 4 failures out of 48. Both cards
       resolve through 'order_billing_details'
       plus the country/state/city tables, and a blank card is indistinguishable from a healthy
       one, so the VALUES are counted rather than the cards. */
    function addressValues(which) {
        var h = document.querySelector('[data-ss-address="' + which + '"]');
        if (!h) { return -1; }
        var card = h.closest('.card') || h.parentElement.parentElement;
        var dds = [].slice.call(card.querySelectorAll('dd'));
        return dds.filter(function (d) { return (d.textContent || '').trim() !== ''; }).length;
    }

    return {
        readings: readings,
        iconReadings: iconReadings,
        tintsFound: tintsFound,
        notes: document.querySelectorAll('.card-header.note-bg-color').length,
        headerCount: headerCount,
        cellCounts: cellCounts,
        blank: blank.length,
        opener: blank.filter(function (a) {
            return (a.getAttribute('rel') || '').indexOf('noopener') !== -1;
        }).length,
        shippingValues: addressValues('shipping'),
        billingValues: addressValues('billing'),
        bodyText: (document.body.innerText || '')
    };
})()`;

/*
 * A BACKTICK INSIDE THE PROBE ENDS ITS TEMPLATE LITERAL AND THE FILE STOPS PARSING. It has
 * happened three times in this phase, every time in a COMMENT inside the probe, where a
 * backtick is the natural way to quote a column name. Node's error points at the identifier
 * AFTER the stray backtick rather than at the backtick, so it reads like a typo somewhere else.
 *
 * A RUNTIME GUARD CANNOT CATCH IT, and the first version of this file had one -- a check that
 * the probe string contains no backtick. It never fires: if a backtick is there, the file does
 * not parse, so no line in it runs. It was protection in appearance only, which is worse than
 * none. `node --check <file>` is the check that actually works, and it is cheap enough to run
 * before every invocation. Inside the probe, quote with single quotes.
 */

(async () => {
    const browser = await chromium.launch();
    let pass = 0, fail = 0;
    const say = (ok, msg) => { ok ? pass++ : fail++; console.log(`  ${ok ? 'ok  ' : 'FAIL'}  ${msg}`); };

    const OUT = path.join(__dirname, '..', 'screenshots', 'p2-04-detail');

    for (const vp of [{ name: 'desktop', width: 1440, height: 1200 },
                      { name: 'mobile', width: 390, height: 900 }]) {
    for (const locale of ['en', 'ar']) {
        for (const theme of ['light', 'dark']) {
            setServerState(theme, locale === 'ar' ? 'rtl' : 'ltr');

            const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
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
            await page.goto(`${BASE}/order`, { waitUntil: 'networkidle' });
            await page.waitForTimeout(700);

            const href = await page.locator('a[href*="/order-view/"]').first().getAttribute('href');
            if (!href) throw new Error('no order to open from the list');
            await page.goto(href, { waitUntil: 'networkidle' });
            await page.waitForTimeout(600);

            const tag = `${vp.name} ${locale}/${theme}`;
            const r = await page.evaluate(PROBE);

            // ---- PREMISES. Every assertion after these is free if the content is not there.
            say(r.notes >= 5, `${tag} — the notes panel holds all five kinds (${r.notes})`);
            say(r.tintsFound === 5, `${tag} — all five note tints are on screen (${r.tintsFound})`);
            say(r.shippingValues > 0, `${tag} — the shipping card has values (${r.shippingValues})`);
            say(r.billingValues > 0, `${tag} — the billing card has values (${r.billingValues})`);
            say(r.headerCount > 0, `${tag} — the items table has headers (${r.headerCount})`);
            say(r.cellCounts.length > 0, `${tag} — the items table has rows (${r.cellCounts.length})`);
            say(r.readings.length >= 12, `${tag} — enough was measured (${r.readings.length} readings)`);

            // ---- The measurements.
            const dimIcons = (r.iconReadings || []).filter((x) => x.ratio < x.need);
            say((r.iconReadings || []).length >= 5,
                `${tag} — every note's delete icon was measured (${(r.iconReadings || []).length})`);
            say(dimIcons.length === 0,
                `${tag} — every note delete icon reaches 3:1`
                + (dimIcons.length ? ` — worst ${Math.min(...dimIcons.map((x) => x.ratio))}:1 on "${dimIcons[0].label}"` : ''));

            const dim = r.readings.filter((x) => x.ratio < x.need);
            say(dim.length === 0,
                `${tag} — every measured string reaches its threshold`
                + (dim.length ? ` — worst ${Math.min(...dim.map((x) => x.ratio))}:1 on "${dim[0].label}"` : ''));

            const ragged = r.cellCounts.filter((c) => c !== r.headerCount);
            say(ragged.length === 0,
                `${tag} — every items row has as many cells as the header has columns`
                + (ragged.length ? ` — ${ragged.length} row(s) with ${ragged[0]} against ${r.headerCount}` : ''));

            say(r.blank > 0 && r.opener === r.blank,
                `${tag} — all ${r.blank} new-window links carry rel=noopener (${r.opener})`);

            /*
             * NO NOTE CLAIMS THE READER AS ITS AUTHOR. The page used to print
             * `Auth::user()->name` beside every note. The header greets the signed-in user by
             * name once, which is legitimate, so this counts rather than merely searching:
             * more than one occurrence means a note is claiming it too.
             */
            const who = 'Amina Haddad';
            const occurrences = r.bodyText.split(who).length - 1;
            say(occurrences <= 1,
                `${tag} — the signed-in user's name appears only in the header (${occurrences})`);

            say(errors.length === 0, `${tag} — no uncaught page error and no 500`
                + (errors.length ? ` — ${errors[0]}` : ''));

            /* Shot from the SAME run that measured it, so the screenshot and the numbers
               describe one page load rather than two that happen to look alike. */
            const file = `${vp.name}-${locale}-${theme}`;
            await page.screenshot({ path: path.join(OUT, `01-order-detail-${file}.png`), fullPage: false });

            /*
             * THE PANEL, NOT ONE NOTE. This read `.card` alone, which was the outer panel until
             * D-25 Step 3 converted it to `ss-card` -- after which the first `.card` containing a
             * tinted header was the NOTE ITEM, and the evidence screenshot silently went from the
             * whole panel (50KB) to a single note (6.8KB). Nothing failed; the run still reported
             * 112/112. Caught by the file size in `git status`, which is a poor detector, so the
             * selector names both surfaces and takes the outermost (document order puts the panel
             * before the note it contains).
             */
            const notes = page.locator('.ss-card, .card')
                .filter({ has: page.locator('.card-header.note-bg-color') })
                .first();
            if (await notes.count() > 0) {
                await notes.screenshot({ path: path.join(OUT, `02-order-notes-${file}.png`) }).catch(() => {});
            }

            await ctx.close();
        }
    }
    }

    await browser.close();
    console.log(`\n${pass + fail} cases, ${pass} passed, ${fail} failed`);
    process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
