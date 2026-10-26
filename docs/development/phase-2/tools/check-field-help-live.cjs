#!/usr/bin/env node
'use strict';
/**
 * P2-06-10 — contextual field help, as the BROWSER resolves it.
 *
 * NO REAL CUSTOMER DATA. The tenant is `database/seed-p2dev.php`.
 *
 * WHAT THE FEATURE TEST CANNOT SEE. `ContextualFieldHelpTest` asserts the association in both
 * directions against the rendered HTML, which is the half that catches an orphan hint or a
 * dangling reference. It cannot tell whether the browser actually RESOLVES the description -- an
 * `aria-describedby` pointing at an element that is `display:none`, or inside a closed
 * `<details>`, or duplicated by a second element with the same id, all parse as correct markup and
 * reach assistive technology differently. So this reads the text the browser would announce, by
 * resolving the id chain itself, and checks it in both languages.
 *
 * THE WEIGHT FIELD IS THE INTERESTING ONE: it lives inside the P2-03-8 collapsed `<details>`, so
 * its help is in the accessibility tree while being off-screen until the merchant opens the
 * section. That is correct -- `aria-describedby` does not require visibility -- and it is the case
 * a naive "is it visible" check would wrongly fail.
 *
 * usage: node check-field-help-live.cjs [baseUrl]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const FIELDS = ['slug', 'sale_price', 'cost_price', 'product_weight', 'low_stock_threshold'];

let cases = 0; const failures = [];
const check = (label, cond, detail) => {
    cases++;
    if (cond) { console.log('  ok    ' + label); }
    else { failures.push(label + (detail ? '  [' + detail + ']' : '')); console.log('  FAIL  ' + label + (detail ? '  [' + detail + ']' : '')); }
};
const REPO = path.join(__dirname, '..', '..', '..', '..');
const phpEnv = Object.assign({}, process.env, {
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'),
    CACHE_STORE: 'file',
});
const setState = (theme, dir) => execFileSync('php',
    [path.join(__dirname, 'set-preview-state.php'), '1', theme, dir],
    { cwd: REPO, env: phpEnv, stdio: 'pipe' });

/* `low_stock_threshold` lives inside `@if ($stock_management == 'on')`, so without this the field
   is not on the page and its help cannot be checked. Turned on here rather than in the fixture,
   because enabling it there would change the editor's RENDERED FIELD SET -- which
   `inventory-product-fields.cjs` diffs as its preservation gate, and a baseline that drifts for an
   unrelated reason is worse than a harness that states its own precondition. Restored on exit. */
const setStockManagement = (value) => execFileSync('php',
    [path.join(__dirname, 'set-stock-management.php'), '1', value],
    { cwd: REPO, env: phpEnv, stdio: 'pipe' });

(async () => {
    const browser = await chromium.launch();
    setStockManagement('on');
    try {
        for (const [lang, dir, theme] of [['en', 'ltr', 'light'], ['ar', 'rtl', 'dark']]) {
            setState(theme, dir);
            const ctx = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
            const p = await ctx.newPage();
            const errs = [];
            p.on('pageerror', (e) => errs.push(String(e.message).slice(0, 140)));

            await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
            await p.fill('input[name="email"]', 'merchant@example.test');
            await p.fill('input[name="password"]', 'password');
            await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
            await p.goto(BASE + '/change-language/' + lang, { waitUntil: 'domcontentloaded' }).catch(() => {});
            await p.goto(BASE + '/product/create', { waitUntil: 'networkidle' });
            await p.waitForTimeout(1800);

            const tag = lang + '/' + dir + '/' + theme;
            const resolved = await p.evaluate((fields) => {
                const out = {};
                for (const f of fields) {
                    const control = document.querySelector('[name="' + f + '"]');
                    if (!control) { out[f] = { missing: true }; continue; }
                    const ref = control.getAttribute('aria-describedby') || '';
                    /* Resolve the id chain the way the accessibility tree does: every id in the
                       space-separated list, in order, joined. */
                    const parts = ref.split(/\s+/).filter(Boolean).map((id) => {
                        const all = document.querySelectorAll('[id="' + id + '"]');
                        return { id: id, count: all.length, text: all[0] ? all[0].textContent.trim() : null };
                    });
                    out[f] = {
                        ref: ref,
                        parts: parts,
                        description: parts.map((x) => x.text || '').join(' ').trim(),
                        duplicateIds: parts.filter((x) => x.count > 1).map((x) => x.id),
                        unresolved: parts.filter((x) => x.count === 0).map((x) => x.id),
                    };
                }
                return out;
            }, FIELDS);

            for (const f of FIELDS) {
                const r = resolved[f];
                check(tag + ' ' + f + ': the control is on the page', !r.missing, JSON.stringify(r));
                if (r.missing) { continue; }
                check(tag + ' ' + f + ': it references a description', r.ref.length > 0, JSON.stringify(r.ref));
                check(tag + ' ' + f + ': every referenced id resolves',
                      r.unresolved.length === 0, 'unresolved: ' + JSON.stringify(r.unresolved));
                /* A DUPLICATE ID IS A REAL BUG that reads as fine: the browser takes the first
                   match, so the control can be described by a different element than intended. */
                check(tag + ' ' + f + ': no duplicate id behind the reference',
                      r.duplicateIds.length === 0, 'duplicated: ' + JSON.stringify(r.duplicateIds));
                check(tag + ' ' + f + ': the description says something',
                      r.description.length > 15, JSON.stringify(r.description.slice(0, 70)));
                if (lang === 'ar') {
                    check(tag + ' ' + f + ': the description is Arabic',
                          /[؀-ۿ]/.test(r.description), JSON.stringify(r.description.slice(0, 60)));
                }
            }
            if (lang === 'en') {
                console.log('        descriptions: ' + JSON.stringify(
                    FIELDS.map((f) => f + '=' + String((resolved[f] || {}).description || '').slice(0, 44))));
            }
            check(tag + ': no page errors', errs.length === 0, errs.join(' | '));
            await ctx.close();
        }
    } finally {
        await browser.close();
        setStockManagement('off');
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
