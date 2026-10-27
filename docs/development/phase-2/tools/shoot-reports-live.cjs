#!/usr/bin/env node
/**
 * P2-05-6 -- the report screens, shot and measured in all four configurations.
 * ====================================================================================
 *
 * The slice's claims are about what a merchant SEES, so they are checked in a browser rather
 * than inferred from markup. Nine premise classes, every one of which has caught a real defect
 * somewhere in this phase:
 *
 *   1. the page renders at all (no 500, no uncaught page error);
 *   2. every figure carries a NON-EMPTY period beside it -- PRD-001, the point of the slice;
 *   3. every chart ships a table with a <caption> and `scope` on both axes;
 *   4. each period toolbar has EXACTLY ONE aria-pressed="true";
 *   5. each tablist has EXACTLY ONE aria-selected="true" (the stock rail said three);
 *   6. no <label for> points at an id that is not in the page (matching is CASE-SENSITIVE);
 *   7. no duplicate ids, except the host chrome's own, declared by name below;
 *   8. AA on the stat label, value and period, composited over the effective background --
 *      this phase has twice found ratios that only appear once the alpha layers are composited;
 *   9. no horizontal page scroll at 390px.
 *
 * WHAT IT CANNOT COVER, STATED RATHER THAN IMPLIED. The preview instance is SQLite and
 * `ReportController` uses MySQL-only `MONTH()`, `YEAR()`, `DATE()` and `SUBSTRING_INDEX()` 36
 * times, so four of the hub's five windows and the whole of the Top Sales and sale-by bodies
 * answer 500 here exactly as they do in the suite. This shoots what SQLite can serve. There is
 * no MySQL in this container (no mysqld, no client), so the MySQL path remains UNVERIFIED and
 * is not claimed.
 *
 * usage: node shoot-reports-live.cjs
 * Preconditions: the p2dev instance is serving on 127.0.0.1:8899, seeded by
 * `database/seed-p2dev.php` (invented data only). Theme and direction are SERVER-side, so this
 * flips them through `set-preview-state.php` and restores light/RTL on the way out.
 */

'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8899';
const REPO = '/home/user/syriastore98';
const OUT = REPO + '/docs/development/phase-2/evidence/p2-05-6';

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


/* Duplicate ids the HOST CHROME ships, named so a NEW duplicate still fails. */
const HOST_CHROME_DUPLICATE_IDS = ['modelCommanModelLabel'];

/*
 * DIRECTION FOLLOWS THE USER'S LANGUAGE, NOT THE STORE SETTING.
 *
 * `layouts/app.blade.php:48-50` overrides `SITE_RTL` to 'on' whenever `auth()->user()->language`
 * is `ar` or `he`. The p2dev merchant's language is `ar`, so a harness that flips only the
 * store's `SITE_RTL` shoots an RTL page in all four "configurations". MEASURED on the first run
 * of this file: the `ltr` and `rtl` PNGs were byte-identical, and the page served
 * `rtl-style-dark.css` with `SITE_RTL` written as `off`.
 *
 * That is correct product behaviour, so the harness is what was wrong. The language is driven
 * through the application's OWN route — `/change-language/{lang}`, which four of the other
 * harnesses in this directory already use — rather than by writing the column, so one
 * mechanism covers it and nothing here has to know how the preference is stored.
 *
 * SEVENTEEN OTHER SHOOTERS IN THIS DIRECTORY DO NOT DO THIS. They call `set-preview-state.php`
 * and nothing else, so for this Arabic fixture user their "ltr" and "rtl" runs are the same
 * page twice. That is a claim about THEIR committed evidence, not about this file, and it is
 * recorded in the ledger rather than fixed here.
 */
async function setLang(page, l) {
    await page.goto(BASE + '/change-language/' + l, { waitUntil: 'domcontentloaded' });
}

function st(theme, dir) {
    execFileSync('php', [REPO + '/docs/development/phase-2/tools/set-preview-state.php', '1', theme, dir],
        { cwd: REPO, env: Object.assign({}, process.env, {
            DB_CONNECTION: 'sqlite', DB_DATABASE: REPO + '/database/p2dev.sqlite', CACHE_STORE: 'file',
        }), stdio: 'pipe' });
}

async function login(p) {
    await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await p.fill('input[name="email"]', 'merchant@example.test');
    await p.fill('input[name="password"]', 'password');
    await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
    if (p.url().includes('/login')) { throw new Error('login failed'); }
}

/*
 * EVERY SCREEN DECLARES WHAT IT MUST RENDER, and the harness fails when it does not.
 *
 * The first run of this file reported 160/160 with `figs 0/0` on every row: nothing had
 * rendered and every check passed vacuously, which is the failure mode this project keeps
 * recording. The hub was the reason -- its default window is `year`, and `year` is one of the
 * four that need MySQL's `MONTH()`, so the AJAX body answered 500 and the page stayed empty
 * while the harness happily measured nothing.
 *
 * So the hub is driven the way a merchant would drive it on this instance: click Last 7 days,
 * which is the one window SQLite can serve. And `minFigures` / `minTables` turn "nothing
 * rendered" from a pass into a failure.
 */
const SCREENS = [
    { key: 'hub', path: '/reports', wait: '.ss-toolbar',
      click: '.chart-data[value="seven-day"]', then: '.ss-stat__value',
      minFigures: 4, minTables: 2, minToolbars: 1 },
    { key: 'status', path: '/Status-reports', wait: '.ss-chart-data',
      minFigures: 0, minTables: 1, minToolbars: 0 },
    { key: 'country', path: '/order-country-reports', wait: '.ss-chart-data',
      minFigures: 0, minTables: 1, minToolbars: 0 },
    { key: 'stock', path: '/stock-reports', wait: '.ss-tabrail',
      minFigures: 0, minTables: 0, minToolbars: 0, minTablists: 1 },
];

const CONFIGS = [
    { theme: 'light', dir: 'ltr', lang: 'en' },
    { theme: 'dark', dir: 'ltr', lang: 'en' },
    { theme: 'light', dir: 'rtl', lang: 'ar' },
    { theme: 'dark', dir: 'rtl', lang: 'ar' },
];

async function audit(page) {
    await page.addScriptTag({ content: CONTRAST_FN.replace(/^const CONTRAST_FN = `|`;$/g, '') });

    return page.evaluate((hostDupes) => {
        const out = { figures: 0, figuresWithPeriod: 0, tables: 0, tablesOk: 0,
                      toolbars: [], tablists: [], dangling: [], dupes: [], worst: null };

        document.querySelectorAll('.ss-stat').forEach((s) => {
            out.figures++;
            const p = s.querySelector('.ss-stat__period');
            if (p && p.textContent.trim() !== '') { out.figuresWithPeriod++; }
        });

        document.querySelectorAll('.ss-chart-data table').forEach((t) => {
            out.tables++;
            const cap = t.querySelector('caption');
            const col = t.querySelector('th[scope="col"]');
            const row = t.querySelector('th[scope="row"]');
            if (cap && cap.textContent.trim() !== '' && col && row) { out.tablesOk++; }
        });

        document.querySelectorAll('.ss-toolbar[role="group"]').forEach((g) => {
            out.toolbars.push(g.querySelectorAll('[aria-pressed="true"]').length);
        });
        document.querySelectorAll('[role="tablist"]').forEach((t) => {
            out.tablists.push(t.querySelectorAll('[role="tab"][aria-selected="true"]').length);
        });

        document.querySelectorAll('label[for]').forEach((l) => {
            if (!document.getElementById(l.getAttribute('for'))) { out.dangling.push(l.getAttribute('for')); }
        });

        const seen = {};
        document.querySelectorAll('[id]').forEach((e) => {
            const id = e.id;
            if (!id) { return; }
            seen[id] = (seen[id] || 0) + 1;
        });
        Object.keys(seen).forEach((id) => {
            if (seen[id] > 1 && hostDupes.indexOf(id) === -1) { out.dupes.push(id + ' x' + seen[id]); }
        });

        /* AA on every piece of stat text, composited. 4.5:1 for body text. */
        document.querySelectorAll('.ss-stat__label, .ss-stat__value, .ss-stat__period, .ss-field__hint')
            .forEach((el) => {
                if (!el.textContent.trim()) { return; }
                const r = window.__ssContrast(el);
                if (out.worst === null || r < out.worst.ratio) {
                    out.worst = { ratio: r, text: el.textContent.trim().slice(0, 34), cls: el.className };
                }
            });

        out.overflow = document.documentElement.scrollWidth > document.documentElement.clientWidth + 1;

        return out;
    }, hostDupesArg());
}

function hostDupesArg() { return HOST_CHROME_DUPLICATE_IDS; }

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch();
    let pass = 0, fail = 0;
    const rows = [];
    const shots = {};

    for (const cfg of CONFIGS) {
        st(cfg.theme, cfg.dir);
        const ctx = await browser.newContext({
            viewport: { width: 1440, height: 1000 },
            locale: cfg.lang === 'ar' ? 'ar' : 'en-GB',
        });
        const page = await ctx.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(String(e).slice(0, 120)));

        await login(page);
        await setLang(page, cfg.lang);

        for (const s of SCREENS) {
            const tag = s.key + '-' + cfg.theme + '-' + cfg.dir;
            errors.length = 0;
            const resp = await page.goto(BASE + s.path, { waitUntil: 'domcontentloaded' });
            const status = resp ? resp.status() : 0;

            let rendered = true;
            try { await page.waitForSelector(s.wait, { timeout: 15000 }); } catch (e) { rendered = false; }

            if (rendered && s.click) {
                await page.click(s.click);
                try { await page.waitForSelector(s.then, { timeout: 15000 }); } catch (e) { rendered = false; }
            }
            await page.waitForTimeout(500);

            const a = await audit(page);
            const checks = [
                ['renders 200', status === 200],
                ['the screen rendered', rendered],
                ['enough figures', a.figures >= (s.minFigures || 0)],
                ['enough chart tables', a.tables >= (s.minTables || 0)],
                ['enough toolbars', a.toolbars.length >= (s.minToolbars || 0)],
                ['enough tablists', a.tablists.length >= (s.minTablists || 0)],
                ['no page error', errors.length === 0],
                ['figures carry a period', a.figures === 0 || a.figures === a.figuresWithPeriod],
                ['chart tables captioned+scoped', a.tables === a.tablesOk],
                ['one pressed per toolbar', a.toolbars.every((n) => n === 1)],
                ['one selected per tablist', a.tablists.every((n) => n === 1)],
                ['no dangling for', a.dangling.length === 0],
                ['no duplicate id', a.dupes.length === 0],
                ['AA on stat text', a.worst === null || a.worst.ratio >= 4.5],
            ];

            checks.forEach(([name, ok]) => {
                if (ok) { pass++; } else {
                    fail++;
                    console.log('FAIL  ' + tag + '  ' + name + '  ' + JSON.stringify(a));
                }
            });

            rows.push([tag, status, a.figures + '/' + a.figuresWithPeriod, a.tables + '/' + a.tablesOk,
                       JSON.stringify(a.toolbars), JSON.stringify(a.tablists),
                       a.worst ? a.worst.ratio : '-', a.dupes.length, a.dangling.length].join('  '));

            const shot = OUT + '/' + tag + '.png';
            await page.screenshot({ path: shot, fullPage: true });
            shots[s.key] = shots[s.key] || {};
            shots[s.key][cfg.theme + '-' + cfg.dir] =
                require('crypto').createHash('md5').update(fs.readFileSync(shot)).digest('hex');

            /* 390px: no horizontal page scroll. */
            await page.setViewportSize({ width: 390, height: 844 });
            await page.waitForTimeout(250);
            const narrow = await page.evaluate(() =>
                document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
            if (narrow) { fail++; console.log('FAIL  ' + tag + '  horizontal overflow at 390px'); }
            else { pass++; }
            await page.screenshot({ path: OUT + '/' + tag + '-390.png', fullPage: true });
            await page.setViewportSize({ width: 1440, height: 1000 });
        }

        await ctx.close();
    }

    await browser.close();
    st('light', 'rtl');

    /*
     * A FOUR-CONFIGURATION CLAIM MUST PROVE THE FOUR ARE DIFFERENT. The first run of this file
     * produced four PNGs per screen of which two pairs were byte-identical, and reported
     * 160/160. Hashing them is the cheapest possible guard against repeating that.
     */
    Object.keys(shots).forEach((key) => {
        const hashes = shots[key];
        const uniq = new Set(Object.values(hashes));
        if (uniq.size === Object.keys(hashes).length) { pass++; return; }
        fail++;
        console.log('FAIL  ' + key + '  only ' + uniq.size + ' of ' + Object.keys(hashes).length
            + ' configurations are actually different: ' + JSON.stringify(hashes));
    });

    console.log('');
    console.log('tag                         http  figs  tables  toolbars  tablists  worstAA  dupes  dangling');
    rows.forEach((r) => console.log(r));
    console.log('');
    console.log(pass + ' passed, ' + fail + ' failed');
    process.exit(fail === 0 ? 0 : 1);
})();
