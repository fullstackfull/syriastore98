#!/usr/bin/env node
'use strict';
/**
 * P2.5-A-01 / A-02 — the white modal surface and every form control, in the REAL dark admin.
 *
 * NO REAL CUSTOMER DATA. The tenant is `database/seed-p2dev.php`; every value is invented.
 *
 * WHAT THIS MEASURES AND WHY A STATIC HARNESS WAS NOT ENOUGH. The cascade harness in this same
 * directory loads the four real stylesheets against hand-written markup, which settles the CSS
 * question. It cannot settle whether the markup the application actually emits carries the
 * classes those rules key on, whether the server sends the DARK stylesheet at all, or whether a
 * module's own sheet lands on top. Those are the three ways a measured CSS fix has been wrong in
 * this repository before, so this drives the running application instead.
 *
 * THE DARK LAYOUT IS CHOSEN SERVER-SIDE. Setting `data-ss-theme` from the client produces a
 * light-stylesheet screenshot labelled dark -- recorded in PHASE-02-HANDOFF §4 as eight such
 * readings. `set-admin-preview-state.php` writes the `cust_darklayout` setting the layout reads,
 * and this harness ASSERTS which stylesheet the server actually sent before it measures anything.
 *
 * usage: node docs/development/phase-2.5/tools/check-dark-form-surfaces-live.cjs [baseUrl] [storeId]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const STORE = process.argv[3] || '1';
const EMAIL = 'merchant@example.test', PASSWORD = 'password';
const ROOT = path.resolve(__dirname, '../../../..');
const STATE = path.resolve(__dirname, '../../phase-2/tools/set-admin-preview-state.php');

let cases = 0; const failures = [];
const check = (label, cond, detail) => {
    cases++;
    if (cond) console.log('  ok    ' + label);
    else { failures.push(label + (detail ? '  [' + detail + ']' : '')); console.log('  FAIL  ' + label + (detail ? '  [' + detail + ']' : '')); }
};

const CONTRAST_PROBE = () => {
    const srgb = (c) => (c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
    const parse = (s) => {
        const m = String(s).match(/rgba?\(([^)]+)\)/); if (!m) return null;
        const p = m[1].split(',').map((x) => parseFloat(x.trim()));
        return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
    };
    const lum = (c) => 0.2126 * srgb(c.r / 255) + 0.7152 * srgb(c.g / 255) + 0.0722 * srgb(c.b / 255);
    const over = (f, b) => ({ r: f.r * f.a + b.r * (1 - f.a), g: f.g * f.a + b.g * (1 - f.a), b: f.b * f.a + b.b * (1 - f.a), a: 1 });
    const effBg = (el) => {
        const stack = []; let n = el;
        while (n && n.nodeType === 1) {
            const c = parse(getComputedStyle(n).backgroundColor);
            if (c && c.a > 0) { stack.push(c); if (c.a === 1) break; }
            n = n.parentElement;
        }
        let out = { r: 255, g: 255, b: 255, a: 1 };
        for (let i = stack.length - 1; i >= 0; i--) out = over(stack[i], out);
        return out;
    };
    const hex = (c) => '#' + [c.r, c.g, c.b].map((v) => Math.round(v).toString(16).padStart(2, '0')).join('');
    window.__ssProbe = (el, which) => {
        const cs = getComputedStyle(el, which || null);
        const bg = effBg(el);
        const fg = over(parse(cs.color), bg);
        const l1 = lum(fg), l2 = lum(bg);
        return { fg: hex(fg), bg: hex(bg), ratio: Math.round(((Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05)) * 100) / 100 };
    };
};

async function login(p) {
    await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await p.fill('input[name="email"]', EMAIL);
    await p.fill('input[name="password"]', PASSWORD);
    await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
}

async function run(browser, dir, lang, tag) {
    console.log(`\n--- dark / ${dir.toUpperCase()} / ${lang} ---`);
    // THE STATE SCRIPT MUST SEE THE SAME DATABASE AND CACHE AS THE SERVER. Run without these it
    // bootstraps against `.env`'s defaults, writes `cust_darklayout` into a different sqlite file,
    // reports success, and every reading below is of the LIGHT theme. The premise assertions
    // immediately after this line are what caught it; they are not decoration.
    const ENV = Object.assign({}, process.env, {
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: path.join(ROOT, 'database/p2dev.sqlite'),
        CACHE_STORE: 'file',
        SESSION_DRIVER: 'file',
    });
    execFileSync('php', [STATE, STORE, 'dark', dir, lang, EMAIL], { cwd: ROOT, stdio: 'pipe', env: ENV });

    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const p = await ctx.newPage();
    await p.addInitScript(CONTRAST_PROBE);
    await login(p);

    await p.goto(BASE + '/category', { waitUntil: 'domcontentloaded' });
    await p.waitForTimeout(700);

    // PREMISE 1 — the server really sent a dark stylesheet. Everything below is meaningless
    // without it, and this is the exact reading that was wrong eight times in Phase 02.
    const sheet = await p.getAttribute('#main-style-link', 'href');
    check('server sent a DARK layout stylesheet', /style-dark\.css/.test(String(sheet)), String(sheet));
    const theme = await p.getAttribute('html', 'data-ss-theme');
    check('html carries data-ss-theme="dark"', theme === 'dark', String(theme));
    // app.blade.php:68 emits dir="ltr" or dir="rtl" from a ternary with no empty branch. The
    // design-system README claimed LTR emitted dir="" and that [dir="ltr"] could never match;
    // this assertion is what measured that, and the README was corrected rather than this line.
    const htmlDir = await p.getAttribute('html', 'dir');
    check(`html dir is "${dir}"`, htmlDir === dir, JSON.stringify(htmlDir));

    // Open the REAL create-category modal the list's own button opens. Scoped by its data-url:
    // the page also carries eight HIDDEN `data-ajax-popup` items inside collapsed dropdowns, and
    // the first match is one of them ("Create Tax"), which is a click on nothing.
    await p.click('[data-ajax-popup="true"][data-url$="/category/create"]:not(.dropdown-item):visible');
    await p.waitForSelector('.modal-content', { state: 'visible', timeout: 10000 });
    await p.waitForTimeout(600);

    // PREMISE 2 — the surface really is white, which is the whole reason these rules exist.
    const modalBg = await p.$eval('.modal-content', (el) => getComputedStyle(el).backgroundColor);
    check('.modal-content is WHITE in dark mode (the premise for A-01)',
        /rgba?\(255,\s*255,\s*255/.test(modalBg), modalBg);

    const probes = await p.evaluate(() => {
        const out = [];
        const add = (sel, label, pseudo) => {
            const el = document.querySelector(sel);
            if (!el) { out.push({ label, missing: true }); return; }
            out.push(Object.assign({ label }, window.__ssProbe(el, pseudo)));
        };
        add('.modal-content .modal-title, .modal-content h5', 'modal title');
        add('.modal-content label, .modal-content .ss-label', 'modal first label');
        add('.modal-content input[type="text"], .modal-content .form-control, .modal-content .ss-input', 'modal first text field');
        // The parent-category select carries `select2`, a JS hook that REPLACES the element, so
        // there is no plain <select> on this form and asserting one would fail forever. The
        // select2-rendered control is covered by the "ALL fields" sweep below.
        add('.modal-content select', 'modal select (incl. select2 source element)');
        // every label on the form, so one passing label cannot stand in for the rest
        const labels = [...document.querySelectorAll('.modal-content label, .modal-content .ss-label')];
        out.push({ label: `ALL ${labels.length} modal labels`, all: labels.map((l) => window.__ssProbe(l)) });
        const fields = [...document.querySelectorAll('.modal-content input:not([type=hidden]):not([type=checkbox]), .modal-content select, .modal-content textarea, .modal-content .select2-selection__rendered')];
        out.push({ label: `ALL ${fields.length} modal fields`, all: fields.map((f) => window.__ssProbe(f)) });
        return out;
    });

    for (const r of probes) {
        if (r.missing) { check(r.label + ' present', false, 'element not found'); continue; }
        if (r.all) {
            const bad = r.all.filter((x) => x.ratio < 4.5);
            check(`${r.label}: every one clears 4.5:1`, bad.length === 0,
                bad.length ? bad.map((b) => `${b.ratio}:1 ${b.fg} on ${b.bg}`).join('; ') : `min ${Math.min(...r.all.map((x) => x.ratio))}:1`);
            continue;
        }
        check(`${r.label} clears 4.5:1`, r.ratio >= 4.5, `${r.ratio}:1  ${r.fg} on ${r.bg}`);
    }

    const shot = path.resolve(__dirname, `../evidence/p2-5-a-01/modal-dark-${tag}.png`);
    await p.screenshot({ path: shot });
    console.log('  screenshot: ' + shot);
    await ctx.close();
}

(async () => {
    const browser = await chromium.launch();
    try {
        await run(browser, 'ltr', 'en', 'ltr-en');
        await run(browser, 'rtl', 'ar', 'rtl-ar');
    } finally { await browser.close(); }
    console.log(`\n${cases} checks, ${failures.length} failed`);
    if (failures.length) { failures.forEach((f) => console.log('  - ' + f)); process.exit(1); }
    process.exit(0);
})();
