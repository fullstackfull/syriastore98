#!/usr/bin/env node
'use strict';
/**
 * P2-03-9 — the stock indicator on the real product list, in both languages.
 *
 * NO REAL CUSTOMER DATA. The tenant is `database/seed-p2dev.php`.
 *
 * WHAT THE FEATURE TEST CANNOT SEE. `ProductStockIndicatorTest` drives the AJAX leg and compares
 * the badge against the filter row by row, which is the half that matters for correctness. It
 * cannot tell whether the badge is legible, whether the `title` survives into the rendered table
 * (yajra writes the cell as raw HTML, and a DataTables renderer that escaped it would leave the
 * rule invisible while the test still passed), or whether the Arabic reads.
 *
 * usage: node check-stock-indicator-live.cjs [baseUrl]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const EMAIL = 'merchant@example.test', PASSWORD = 'password';

let cases = 0; const failures = [];
const check = (label, cond, detail) => {
    cases++;
    if (cond) { console.log('  ok    ' + label); }
    else { failures.push(label + (detail ? '  [' + detail + ']' : '')); console.log('  FAIL  ' + label + (detail ? '  [' + detail + ']' : '')); }
};
const setState = (theme, dir) => execFileSync('php',
    ['docs/development/phase-2/tools/set-preview-state.php'],
    { env: Object.assign({}, process.env, { PREVIEW_THEME: theme, PREVIEW_DIR: dir }),
      cwd: '/home/user/syriastore98' });

(async () => {
    const browser = await chromium.launch();
    try {
        for (const [lang, dir, theme] of [['en', 'ltr', 'light'], ['ar', 'rtl', 'dark']]) {
            setState(theme, dir);
            const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
            const p = await ctx.newPage();
            const errs = [];
            p.on('pageerror', (e) => errs.push(String(e.message).slice(0, 140)));

            await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
            await p.fill('input[name="email"]', EMAIL);
            await p.fill('input[name="password"]', PASSWORD);
            await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
            await p.goto(BASE + '/change-language/' + lang, { waitUntil: 'domcontentloaded' }).catch(() => {});
            /* DRIVE EACH STOCK FILTER, rather than reading whichever rows page 1 happens to
               hold. The first version read the unfiltered list and found only untracked rows --
               every badge said "In stock" with the not-tracked rule, so the Low stock case
               measured nothing and reported a product defect that was a sampling artefact.
               Driving the filter also makes this a LIVE check of the P2-03-9 agreement: the
               rows `?ss_stock=low` returns must be the rows whose badge says Low stock. */
            const expectedFor = { out: 'danger', low: 'warning', in: 'success' };
            for (const state of ['out', 'low', 'in']) {
                await p.goto(BASE + '/product?ss_stock=' + state, { waitUntil: 'networkidle' });
                await p.waitForTimeout(2600);
                const rows = await p.evaluate(() => Array.from(
                    document.querySelectorAll('#product-table .ss-badge')).map((b) => ({
                        text: b.textContent.trim().replace(/\s+/g, ' '),
                        title: b.getAttribute('title') || '',
                        tone: (b.className.match(/ss-badge--\w+/) || ['neutral'])[0],
                    })));
                const tagS = lang + '/' + dir + '/' + theme + ' ?ss_stock=' + state;
                console.log('    ' + tagS + ': ' + JSON.stringify(
                    Array.from(new Set(rows.map((r) => r.text + '/' + r.tone)))));
                check(tagS + ': the filter returned rows', rows.length > 0, 'got ' + rows.length);
                check(tagS + ': EVERY returned row wears the matching badge',
                      rows.length > 0 && rows.every((r) => r.tone === 'ss-badge--' + expectedFor[state]),
                      JSON.stringify(Array.from(new Set(rows.map((r) => r.tone)))));
                check(tagS + ': and each states its rule with a number',
                      rows.length > 0 && rows.every((r) => /\d/.test(r.title)),
                      JSON.stringify(rows.slice(0, 2).map((r) => r.title)));
            }

            await p.goto(BASE + '/product', { waitUntil: 'networkidle' });
            await p.waitForTimeout(3000);

            const badges = await p.evaluate(() => {
                const vis = (e) => !!(e && (e.checkVisibility ? e.checkVisibility({ contentVisibilityAuto: true }) : e.offsetParent !== null));
                return Array.from(document.querySelectorAll('#product-table .ss-badge')).map((b) => ({
                    text: b.textContent.trim().replace(/\s+/g, ' '),
                    title: b.getAttribute('title') || '',
                    tone: (b.className.match(/ss-badge--\w+/) || ['neutral'])[0],
                    visible: vis(b),
                    colour: getComputedStyle(b).color,
                }));
            });
            const tag = lang + '/' + dir + '/' + theme;
            const words = Array.from(new Set(badges.map((b) => b.text)));
            console.log('    ' + tag + ' badges: ' + JSON.stringify(words));

            check(tag + ': badges render on the list', badges.length > 0, 'found ' + badges.length);
            check(tag + ': all badges are visible', badges.every((b) => b.visible));
            /* THE DECISIVE ONE: the title must survive yajra's raw-column rendering. */
            const withTitle = badges.filter((b) => b.title.length > 0);
            check(tag + ': the rule survives into the rendered cell',
                  withTitle.length === badges.length,
                  withTitle.length + ' of ' + badges.length + ' carry a title');
            /* The UNFILTERED page's own property: every badge explains itself, whether the
               product is tracked or not. A number is not required here -- an untracked row
               correctly has no level to quote, and demanding one is what made the first version
               of this harness report a defect that did not exist. */
            check(tag + ': every badge on the unfiltered page states a rule',
                  badges.every((b) => b.title.trim().length > 8),
                  JSON.stringify(badges.slice(0, 3).map((b) => b.text + '=' + b.title)));
            if (lang === 'ar') {
                check(tag + ': the badge text is Arabic',
                      badges.some((b) => /[؀-ۿ]/.test(b.text)), JSON.stringify(words));
                check(tag + ': the rule is Arabic too',
                      withTitle.some((b) => /[؀-ۿ]/.test(b.title)),
                      JSON.stringify(withTitle.slice(0, 2).map((b) => b.title)));
                check(tag + ': no untranslated placeholder leaked',
                      !withTitle.some((b) => /:count|:threshold/.test(b.title)),
                      JSON.stringify(withTitle.slice(0, 2).map((b) => b.title)));
            }
            check(tag + ': no page errors', errs.length === 0, errs.join(' | '));
            await ctx.close();
        }
    } finally {
        await browser.close();
        setState('light', 'ltr');
        try { execFileSync('bash', ['-c', 'curl -s -o /dev/null ' + BASE + '/change-language/en']); } catch (e) { /* best effort */ }
    }
    console.log('');
    if (failures.length) {
        console.log('FAIL ' + failures.length + ' of ' + cases);
        failures.forEach((f) => console.log('   - ' + f));
        process.exit(1);
    }
    console.log('PASS ' + cases + '/' + cases);
})().catch((e) => { console.error('HARNESS ERROR: ' + e.message); process.exit(2); });
