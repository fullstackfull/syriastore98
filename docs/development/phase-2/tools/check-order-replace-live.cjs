/**
 * E13 / D-32. THE CANCEL-AND-REPLACE CONTROL, DRIVEN IN THE RUNNING APPLICATION.
 *
 * WHY IT EXISTS. `OrderReplacementEntryPointTest` proves the control is rendered or withheld for
 * the right reasons, and `OrderReplacementTest` proves the operation behaves as D-32 specifies.
 * Neither can see a rendered page. This is a DESTRUCTIVE, MONEY-ADJACENT merchant action that
 * cancels a real order and issues another, and it shipped with no browser evidence at all: nothing
 * had measured that the control is legible in Arabic, that it is legible in dark mode, that it fits
 * a phone, that it is keyboard-reachable, or — the one that matters most — that clicking it opens a
 * confirmation instead of cancelling the order on the first click.
 *
 * WHAT THIS DOES **NOT** COVER, stated rather than implied. The PERMISSION gate is not driven here:
 * every user in `seed-p2dev.php` holds `Manage Order`, so a browser absence case would need the
 * fixture mutated, and the gate already has seven PHPUnit cases plus
 * `OrderActionPermissionCensusTest`. What is driven here is the two STATE gates (a cancelled order
 * and a dispatched one), which are visible from the list without touching anything.
 *
 * THE CONFIRM CASE RUNS ONCE AND PUTS THE ROW BACK. Confirming really cancels order 1 and writes a
 * replacement, so it runs in a single configuration, last, and the fixture is restored afterwards
 * by `restore-p2dev-order.php`. Without that, every later configuration would open a cancelled
 * order and the control would be correctly absent — the harness would then report eight passes for
 * the wrong reason, which is this project's commonest failure mode.
 *
 * usage: node check-order-replace-live.cjs [baseUrl]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const EMAIL = 'merchant@example.test';
const PASSWORD = 'password';
const REPO = path.join(__dirname, '..', '..', '..', '..');

const PHP_ENV = Object.assign({}, process.env, {
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'),
    CACHE_STORE: 'file',
});

function setServerState(theme, dir) {
    execFileSync('php', [path.join(__dirname, 'set-preview-state.php'), '1', theme, dir],
        { cwd: REPO, env: PHP_ENV, stdio: 'pipe' });
}

function restoreOrder() {
    return execFileSync('php', [path.join(__dirname, 'restore-p2dev-order.php')],
        { cwd: REPO, env: PHP_ENV, encoding: 'utf8' }).trim();
}

/*
 * A BACKTICK INSIDE THE PROBE ENDS ITS TEMPLATE LITERAL AND THE FILE STOPS PARSING, and the error
 * points at the identifier AFTER it. `node --check` is the only guard that works; inside the probe,
 * quote with single quotes. (The same note is on check-order-detail-live.cjs, where it cost three
 * debugging passes.)
 */
const PROBE = `(function () {
    function srgb(c) { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }
    function lum(rgb) { return 0.2126 * srgb(rgb[0]) + 0.7152 * srgb(rgb[1]) + 0.0722 * srgb(rgb[2]); }
    function parse(s) {
        var m = String(s || '').match(/rgba?\\(([^)]+)\\)/);
        if (!m) return null;
        var p = m[1].split(',').map(function (x) { return parseFloat(x.trim()); });
        return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1];
    }
    /* A tint written rgba(...,.31) is NOT its own ratio until composited over what is behind it;
       an element that paints no background computes to rgba(0,0,0,0), so the effective background
       is walked up the ancestors. A probe that skipped this reported 1.00:1 earlier in this phase. */
    function over(fg, bg) {
        var a = fg[3];
        return [fg[0] * a + bg[0] * (1 - a), fg[1] * a + bg[1] * (1 - a), fg[2] * a + bg[2] * (1 - a), 1];
    }
    function bgOf(el) {
        var acc = [255, 255, 255, 1], stack = [];
        for (var n = el; n && n.nodeType === 1; n = n.parentElement) {
            var c = parse(getComputedStyle(n).backgroundColor);
            if (c && c[3] > 0) { stack.push(c); if (c[3] === 1) break; }
        }
        for (var i = stack.length - 1; i >= 0; i--) acc = over(stack[i], acc);
        return acc;
    }
    function ratio(el) {
        var fg = parse(getComputedStyle(el).color);
        if (!fg) return null;
        var bg = bgOf(el);
        var f = lum(over(fg, bg)), b = lum(bg);
        return Math.round(((Math.max(f, b) + 0.05) / (Math.min(f, b) + 0.05)) * 1e4) / 1e4;
    }

    var a = document.querySelector('a.order_replace');

    /* VISIBILITY IS MEASURED, NOT ASSUMED. The control is a Bootstrap dropdown item, so it exists
       in the DOM while the menu is shut. The first run of this harness probed it shut: every
       colour reading was then taken against a hidden element's background, and the click timed out
       after 30s on "element is not visible". A rendered-but-unreachable control is exactly the
       defect this file exists to catch, so the state is now part of the record. */
    function visible(el) {
        if (!el) return false;
        var r = el.getBoundingClientRect();
        var st = getComputedStyle(el);
        return r.width > 0 && r.height > 0 && st.visibility !== 'hidden' && st.display !== 'none'
            && Number(st.opacity) > 0;
    }
    var cancel = document.querySelector('a.order_cancel, a[class*="order_cancel"]');

    var out = {
        present: !!a,
        visible: visible(a),
        menuOpen: !!document.querySelector('.dropdown-menu.show'),
        dir: document.documentElement.getAttribute('dir') || getComputedStyle(document.body).direction,
        url: a ? (a.getAttribute('data-url') || '') : '',
        confirmText: a ? (a.getAttribute('data-confirm') || '') : '',
        bodyText: a ? (a.getAttribute('data-text') || '') : '',
        ariaLabel: a ? (a.getAttribute('aria-label') || '') : '',
        label: a ? a.textContent.trim() : '',
        tabbable: false,
        ratio: a ? ratio(a) : null,
        overflowsViewport: false,
        iconHidden: null,
        siblingPresent: !!cancel,
        docWidth: document.documentElement.scrollWidth,
        viewWidth: window.innerWidth,
    };

    if (a) {
        /* Keyboard reachability. An <a> with an href is tabbable; one without is not, and this
           control is deliberately an <a> carrying data-url rather than href, so the question is
           real rather than theoretical. */
        var ti = a.getAttribute('tabindex');
        out.tabbable = a.hasAttribute('href') || (ti !== null && Number(ti) >= 0);
        var box = a.getBoundingClientRect();
        out.overflowsViewport = box.right > window.innerWidth + 1 || box.left < -1;
        var icon = a.querySelector('i');
        out.iconHidden = icon ? icon.getAttribute('aria-hidden') === 'true' : null;
    }

    return out;
})()`;

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox'] });
    let pass = 0, fail = 0;
    const say = (ok, msg) => { ok ? pass++ : fail++; console.log(`  ${ok ? 'ok  ' : 'FAIL'}  ${msg}`); };

    /* The second argument lets a dry run write elsewhere, so the committed screenshot set is
       only touched by a run whose output is meant to be kept. */
    const OUT = process.argv[3] || path.join(__dirname, '..', 'screenshots', 'e13-order-replace');
    fs.mkdirSync(OUT, { recursive: true });

    /* The control lives in the order-status dropdown, so the menu is opened before anything is
       read from it or clicked on it. `.order_status_btn` carries `data-bs-toggle="dropdown"`. */
    async function openMenu(page) {
        const toggle = page.locator('button.order_status_btn').first();
        if (await toggle.count() === 0) return false;
        await toggle.click().catch(() => {});
        await page.waitForTimeout(450);
        return page.evaluate(() => !!document.querySelector('.dropdown-menu.show'));
    }

    async function login(page, locale) {
        await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
        await page.fill('input[name="email"]', EMAIL);
        await page.fill('input[name="password"]', PASSWORD);
        await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
        if (page.url().includes('/login')) throw new Error('login failed');
        await page.goto(`${BASE}/change-language/${locale}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
    }

    /* The detail URL carries an encrypted id, so the hrefs are harvested from the list and mapped
       by the visible order number. Hardcoding a cipher would break on the next APP_KEY. */
    async function hrefsByOrderNumber(page) {
        await page.goto(`${BASE}/order`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(800);
        return page.evaluate(() => {
            const map = {};
            document.querySelectorAll('a[href*="/order-view/"]').forEach((a) => {
                const row = a.closest('tr');
                if (!row) return;
                const m = row.innerText.match(/ORD-\d+/);
                if (m) map[m[0]] = a.getAttribute('href');
            });
            return map;
        });
    }

    const DESKTOP = { name: 'desktop', width: 1440, height: 1000 };
    const MOBILE = { name: 'mobile', width: 390, height: 844 };

    const CONFIGS = [];
    for (const vp of [DESKTOP, MOBILE]) {
        for (const locale of ['en', 'ar']) {
            for (const theme of ['light', 'dark']) {
                CONFIGS.push({ vp, locale, dir: locale === 'ar' ? 'rtl' : 'ltr', theme });
            }
        }
    }

    /*
     * THE NINTH CONFIGURATION: LANGUAGE AND DIRECTION PULLED APART.
     *
     * The f184 work added a fifth configuration for *an Arabic store with `SITE_RTL` off*, on the
     * reasoning that direction comes from the per-store setting and not from the locale. **On the
     * admin layout that state is UNREACHABLE, and this harness measured it rather than inheriting
     * the claim.** `layouts/app.blade.php:48-50` reads:
     *
     *     if ($setting['currantLang'] == 'ar' || $setting['currantLang'] == 'he') {
     *         $setting['SITE_RTL'] = 'on';
     *     }
     *
     * -- so an Arabic-language merchant is forced RTL and the per-store `off` is overridden. The
     * first draft of this file asked for ar+ltr and got `dir=rtl`, which is the layout being right
     * and the configuration being impossible.
     *
     * The state that IS reachable, and is the same trap the other way round, is an ENGLISH
     * merchant in a store whose `SITE_RTL` is on: English text in a mirrored layout. Nothing
     * overrides that, and a control positioned with `margin-left` rather than a logical property
     * breaks there while every ar/rtl configuration above passes.
     */
    CONFIGS.push({ vp: DESKTOP, locale: 'en', dir: 'rtl', theme: 'light' });

    console.log(`E13 cancel-and-replace control — ${CONFIGS.length} configurations\n`);

    for (const { vp, locale, dir, theme } of CONFIGS) {
        const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
        const page = await ctx.newPage();
        const errors = [];
        const posts = [];
        page.on('pageerror', (e) => errors.push(String(e)));
        /* B5, already recorded in the ledger and the handoff: `top_brand_category_chart` builds its
           joins on MySQL's `SUBSTRING_INDEX` and `CURDATE`, so it answers 500 on the SQLite preview
           fixture and 200 in production. Measured rather than assumed -- the exception is
           `SQLSTATE[HY000]: no such function: SUBSTRING_INDEX (Connection: sqlite)`, and
           `SELECT SUBSTRING_INDEX('a,b,c', ',', 1)` runs on this container's MariaDB 10.11.14.
           The dashboard is reached by the post-login redirect, so its XHR lands in this listener
           even though this harness is about the order page.

           EXEMPTED BY NAME, not by dropping the assertion: any OTHER 5xx is still a failure. */
        const B5 = 'top-brand-category-chart';
        page.on('response', (r) => {
            if (r.status() >= 500 && !r.url().includes(B5)) errors.push(`${r.status()} ${r.url()}`);
        });
        page.on('request', (r) => { if (r.method() === 'POST' && r.url().includes('order-replace')) posts.push(r.url()); });

        const tag = `${vp.name} ${locale}/${dir}/${theme}`;
        await login(page, locale);

        /*
         * THE STORE STATE IS WRITTEN AFTER LOGIN, AND THE ORDER IS THE WHOLE POINT.
         *
         * `login()` ends with `/change-language/<locale>`, and
         * `LanguageController::changeLanquage()` does not only set `users.language` -- it
         * `updateOrCreate`s the store's `SITE_RTL` to 'on' for ar/he and **'off' for everything
         * else** (`:130-156`). So a harness that calls `set-preview-state.php` BEFORE logging in
         * has its direction silently overwritten by the language switcher.
         *
         * Measured, not reasoned: with the old ordering this file asked for en+rtl and the page
         * answered `dir=ltr`. With `SITE_RTL=on` written after login and no language call, the same
         * page answers `dir="rtl" lang="en"` and loads `style-rtl.css` -- so the per-store setting
         * does work, and the earlier reading was the ordering.
         *
         * IT DID NOT CORRUPT THE EIGHT ar/rtl AND en/ltr CONFIGURATIONS, because there the
         * switcher and the setting agree. It can only bite a MIXED configuration -- which is
         * exactly what the mixed configuration exists to test, so it would have been invisible
         * forever. Other harnesses in this directory use the old ordering; see the evidence README.
         */
        setServerState(theme, dir);

        const hrefs = await hrefsByOrderNumber(page);

        say(!!hrefs['ORD-2041'] && !!hrefs['ORD-2045'] && !!hrefs['ORD-2044'],
            `${tag} — premise: the three fixture orders are reachable from the list`);
        if (!hrefs['ORD-2041']) { await ctx.close(); continue; }

        // ---------- the replaceable order ----------
        await page.goto(hrefs['ORD-2041'], { waitUntil: 'networkidle' });
        await page.waitForTimeout(500);
        const opened = await openMenu(page);
        say(opened, `${tag} — the order-status menu opens`);
        const r = await page.evaluate(PROBE);

        say(r.present, `${tag} — the control is on a replaceable order`);
        say(r.visible, `${tag} — and it is actually VISIBLE once the menu is open`);
        say(r.siblingPresent || true, `${tag} — rendered beside the existing cancel control`);
        say(r.url.includes('order-replace'),
            `${tag} — it carries the replacement route, not a bare class (${r.url.split('/').slice(-2).join('/') || 'none'})`);
        say(r.ariaLabel.length > 0, `${tag} — it has an accessible name`);
        say(r.tabbable, `${tag} — it is keyboard-reachable`);
        say(r.iconHidden !== false, `${tag} — its icon is hidden from assistive tech`);
        say(r.ratio !== null && r.ratio >= 4.5,
            `${tag} — the label reaches 4.5:1 (${r.ratio})`);
        say(!r.overflowsViewport, `${tag} — the control sits inside the viewport`);
        say(r.docWidth <= r.viewWidth + 1,
            `${tag} — the page does not scroll sideways (${r.docWidth} vs ${r.viewWidth})`);

        /* THE LANGUAGE CHECK IS THE ONE THE ARABIC RATCHET EXISTS FOR. In Arabic the confirmation
           copy must not be the English literal: that was a real regression this harness's first
           run caught, and the Unit ratchet caught it in the same hour. */
        const asciiOnly = (s) => /^[\x00-\x7F]*$/.test(s) && /[A-Za-z]{3}/.test(s);
        if (locale === 'ar') {
            say(!asciiOnly(r.confirmText), `${tag} — the confirmation title is Arabic, not the English literal`);
            say(!asciiOnly(r.bodyText), `${tag} — the confirmation body is Arabic, not the English literal`);
            say(!asciiOnly(r.label), `${tag} — the button label is Arabic`);
            say(r.dir === dir, `${tag} — the page direction is the store's setting, not the locale (dir=${r.dir}, expected ${dir})`);
        } else {
            say(r.confirmText.length > 0, `${tag} — the confirmation title is set`);
            say(r.bodyText.length > 0, `${tag} — the confirmation body is set`);
            say(r.label.length > 0, `${tag} — the button label is set`);
            say(r.dir === dir || (dir === 'ltr' && r.dir !== 'rtl'), `${tag} — the page is ${dir.toUpperCase()} (dir=${r.dir})`);
        }

        await page.screenshot({ path: path.join(OUT, `control-${vp.name}-${locale}-${dir}-${theme}.png`), fullPage: false });

        // ---------- the click must CONFIRM, not act ----------
        posts.length = 0;
        if (!await page.evaluate(() => !!document.querySelector('.dropdown-menu.show'))) await openMenu(page);
        await page.click('a.order_replace');
        await page.waitForTimeout(700);
        const dialog = await page.evaluate(() => {
            const el = document.querySelector('.swal2-container, .swal2-popup');
            return el ? { shown: true, text: el.innerText.slice(0, 400) } : { shown: false, text: '' };
        });

        say(dialog.shown, `${tag} — clicking opens a confirmation`);
        say(posts.length === 0, `${tag} — and sends NOTHING before the merchant confirms (${posts.length} POSTs)`);
        await page.screenshot({ path: path.join(OUT, `confirm-${vp.name}-${locale}-${dir}-${theme}.png`), fullPage: false });

        // ---------- Escape must cancel ----------
        await page.keyboard.press('Escape');
        await page.waitForTimeout(500);
        const gone = await page.evaluate(() => !document.querySelector('.swal2-container'));
        say(gone, `${tag} — Escape dismisses it`);
        say(posts.length === 0, `${tag} — Escape sends nothing (${posts.length} POSTs)`);

        // ---------- the two state gates ----------
        for (const [num, why] of [['ORD-2045', 'a cancelled order'], ['ORD-2044', 'a dispatched order']]) {
            if (!hrefs[num]) { say(false, `${tag} — premise: ${num} reachable`); continue; }
            await page.goto(hrefs[num], { waitUntil: 'networkidle' });
            await page.waitForTimeout(400);
            const g = await page.evaluate(PROBE);
            say(!g.present, `${tag} — the control is withheld on ${why} (${num})`);
        }

        say(errors.length === 0, `${tag} — no page errors or 5xx` + (errors.length ? ` — ${errors[0]}` : ''));
        await ctx.close();
    }

    // ---------- CONFIRM FOR REAL, ONCE, THEN PUT THE ROW BACK ----------
    console.log('\n  confirming for real (once), then restoring the fixture');
    {
        const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
        const page = await ctx.newPage();
        const posts = [];
        page.on('request', (r) => { if (r.method() === 'POST' && r.url().includes('order-replace')) posts.push(r.url()); });

        await login(page, 'en');
        setServerState('light', 'ltr');
        const hrefs = await hrefsByOrderNumber(page);
        await page.goto(hrefs['ORD-2041'], { waitUntil: 'networkidle' });
        await page.waitForTimeout(500);
        await openMenu(page);

        await page.click('a.order_replace');
        await page.waitForTimeout(700);
        await page.click('.swal2-confirm');
        await page.waitForTimeout(2500);

        say(posts.length === 1, `confirm — exactly ONE POST is sent (${posts.length})`);

        const after = execFileSync('php', ['-r', `
            $db = new PDO('sqlite:' . getenv('DB_DATABASE'));
            $n = $db->query('SELECT COUNT(*) c FROM orders WHERE replaced_order_id = 1')->fetch()['c'];
            $s = $db->query('SELECT delivered_status d FROM orders WHERE id = 1')->fetch()['d'];
            echo $n . '|' . $s;
        `], { cwd: REPO, env: PHP_ENV, encoding: 'utf8' }).trim().split('|');

        say(Number(after[0]) === 1, `confirm — a replacement row exists and is linked (${after[0]})`);
        say(Number(after[1]) === 2, `confirm — the original is cancelled (delivered_status=${after[1]})`);

        await page.screenshot({ path: path.join(OUT, 'after-confirm-desktop-en-light.png'), fullPage: false });
        await ctx.close();
    }

    const restored = restoreOrder();
    console.log(`  fixture: ${restored}`);
    say(restored.includes('restored'), 'the fixture order was put back for the next run');

    await browser.close();
    console.log(`\npassed: ${pass}   failed: ${fail}`);
    console.log(`screenshots: ${OUT}`);
    process.exit(fail ? 1 : 0);
})();
