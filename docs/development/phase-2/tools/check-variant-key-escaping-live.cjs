#!/usr/bin/env node
'use strict';
/**
 * F72, FOURTH SITE — the variant "remove" handler the data view ships but has no button for.
 *
 * NO REAL CUSTOMER DATA. The tenant is `database/seed-p2dev.php`; every value is invented.
 *
 * WHY THIS NEEDED ITS OWN CASE. The three toggle sites were bitten already. This one is
 * different in kind: `attribute_combinations_data.blade.php` writes
 * `remove_option_{{ $str }}` onto every accordion item and ships a document-delegated
 * `.delete_option` handler -- but renders NO `.delete_option` button, so the file looks
 * self-consistently dead. It is not. `edit.blade.php:741` calls `attribute_option_data()` on
 * `$(document).ready()`, so this view loads on every edit page load and its handler is bound to
 * `document`; replacing `.attribute_combination`'s innerHTML later does not unbind it. The button
 * that fires it comes from the SIBLING view: `attribute_combinations_edit.blade.php:48-49`
 * renders `.delete_option` with `data-id="{{ $str }}"` -- the RAW key -- for any combination with
 * no saved variant row, which is exactly a combination the merchant has generated and not yet
 * saved. So both handlers run on one click, and the unescaped one throws.
 *
 * The fixture is put into that state honestly: the `M/L-Natural` variant ROW is deleted while its
 * attribute term stays, which is what a merchant sees after generating a combination they have not
 * saved.
 *
 * usage: node check-variant-key-escaping-live.cjs [baseUrl]
 */
const { chromium } = require('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8899';
let cases = 0; const failures = [];
const check = (label, cond, detail) => {
    cases++;
    const line = (cond ? '  ok    ' : '  FAIL  ') + label + (detail ? '  [' + detail + ']' : '');
    if (!cond) failures.push(label + (detail ? '  [' + detail + ']' : ''));
    console.log(line);
};
(async () => {
    const b = await chromium.launch();
    try {
        const ctx = await b.newContext({ viewport: { width: 1440, height: 1100 } });
        const p = await ctx.newPage();
        const errs = [];
        p.on('pageerror', (e) => errs.push(String(e.message).slice(0, 160)));

        await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
        await p.fill('input[name="email"]', 'merchant@example.test');
        await p.fill('input[name="password"]', 'password');
        await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
        await p.goto(BASE + '/product/1/edit', { waitUntil: 'networkidle' });
        await p.waitForTimeout(3000);

        /* PREMISE 1: the data view really did load on ready, so its handler is registered. */
        check('the data view loaded on page ready (its handler is live)',
              await p.evaluate(() => document.querySelectorAll('[class*="remove_option_"]').length > 0),
              'remove_option_ items=' + await p.evaluate(() => document.querySelectorAll('[class*="remove_option_"]').length));

        /* THE SIBLING VIEW, REACHED THE WAY A MERCHANT REACHES IT. The edit view renders only
           from the POSTED option set, and on page load the per-attribute option selects are
           EMPTY -- so `update_attribute()` on its own returns just its script tail and no
           combinations. The merchant's own path is: touch the attribute picker (the page binds
           `change` on `#attribute_id` to load each attribute's terms), tick `enable_variation`,
           choose terms, and the option-set change regenerates. That is what this drives. */
        await p.evaluate(() => {
            const s = document.querySelector('#attribute_id');
            if (window.jQuery) { jQuery(s).trigger('change'); }
            else { s.dispatchEvent(new Event('change', { bubbles: true })); }
        });
        await p.waitForTimeout(3000);
        const terms = await p.evaluate(() => {
            const out = [];
            document.querySelectorAll('[class*="enable_variation_"]').forEach((c) => {
                if (!c.checked) { c.checked = true; if (window.jQuery) { jQuery(c).trigger('change'); } }
            });
            document.querySelectorAll('select[id^="attribute_options_"], select.attribute').forEach((s) => {
                Array.from(s.options).forEach((o) => { o.selected = true; });
                out.push(...Array.from(s.selectedOptions).map((o) => o.value));
                if (window.jQuery) { jQuery(s).trigger('change'); }
            });
            return out;
        });
        console.log('        terms chosen: ' + JSON.stringify(terms));
        check('a term with a CSS-special character is really in play',
              terms.some((t) => /[^\w-]/.test(t)), JSON.stringify(terms));
        await p.waitForTimeout(3000);
        await p.evaluate(() => window.update_attribute && window.update_attribute());
        await p.waitForTimeout(4000);

        const buttons = await p.evaluate(() => Array.from(document.querySelectorAll('.delete_option'))
            .map((e) => e.getAttribute('data-id')));
        console.log('        .delete_option keys: ' + JSON.stringify(buttons));

        /* PREMISE 2: a real button carrying a CSS-special key is on the page. If this fails the
           case below measures nothing, so it is asserted rather than assumed. */
        const special = buttons.filter((k) => k && /[^\w-]/.test(k));
        check('a REAL .delete_option button carries a CSS-special key', special.length > 0,
              JSON.stringify(buttons));
        if (!special.length) {
            console.log('\n  premise failed, so the click cannot be measured');
            console.log('FAIL ' + failures.length + ' of ' + cases);
            failures.forEach((f) => console.log('   - ' + f));
            await ctx.close(); await b.close(); process.exit(1);
        }
        const key = special[0];

        /* THE SYMPTOM A MERCHANT ACTUALLY MEETS, measured before the delete case touches the DOM.
           `data-bs-target` is read by Bootstrap's own collapse plugin and handed to
           `document.querySelector()`, so a raw `#collapseone_M/L` throws INSIDE Bootstrap and the
           panel never expands -- the variant's price, SKU and stock are unreachable. This cannot
           be fixed by escaping at a call site, because the call site is Bootstrap's. */
        errs.length = 0;
        const panel = await p.evaluate((k) => {
            const btn = Array.from(document.querySelectorAll('[data-bs-toggle="collapse"]'))
                .find((e) => (e.getAttribute('data-bs-target') || '').includes('collapseone_'));
            const target = btn ? btn.getAttribute('data-bs-target') : null;
            return { target: target, found: !!btn };
        }, key);
        console.log('        first collapse target: ' + JSON.stringify(panel.target));
        check('the collapse target is a VALID selector', await p.evaluate((t) => {
            try { document.querySelector(t); return true; } catch (e) { return false; }
        }, panel.target), String(panel.target));

        const opened = await p.evaluate(async (k) => {
            const esc = (v) => (CSS.escape ? CSS.escape(v) : v);
            const item = document.querySelector('.remove_option_' + esc(k));
            if (!item) { return { err: 'no accordion item for ' + k }; }
            const btn = item.querySelector('[data-bs-toggle="collapse"]');
            if (!btn) { return { err: 'no collapse toggle inside it' }; }
            const tgt = btn.getAttribute('data-bs-target');
            btn.click();
            await new Promise((r) => setTimeout(r, 900));
            let el = null;
            try { el = document.querySelector(tgt); } catch (e) { return { threw: String(e.message).slice(0, 120), tgt: tgt }; }
            return { tgt: tgt, shown: !!(el && el.classList.contains('show')) };
        }, key);
        console.log('        open attempt: ' + JSON.stringify(opened));
        check('THE MERCHANT CAN OPEN the special-key variant\'s panel',
              opened.shown === true, JSON.stringify(opened));
        check('opening it throws nothing', errs.length === 0, errs.join(' | '));

        errs.length = 0;
        const before = await p.evaluate((k) => ({
            item: document.querySelectorAll('.remove_option_' + (CSS.escape ? CSS.escape(k) : k)).length,
        }), key);

        errs.length = 0;
        await p.evaluate((k) => {
            const btn = Array.from(document.querySelectorAll('.delete_option'))
                .find((e) => e.getAttribute('data-id') === k);
            btn.click();
        }, key);
        await p.waitForTimeout(900);

        const after = await p.evaluate((k) => ({
            item: document.querySelectorAll('.remove_option_' + (CSS.escape ? CSS.escape(k) : k)).length,
        }), key);

        check('clicking it throws NOTHING', errs.length === 0, errs.join(' | '));
        check('and the combination is really removed from the page',
              before.item > 0 && after.item === 0,
              'before=' + before.item + ' after=' + after.item + ' key=' + JSON.stringify(key));

        await ctx.close();
    } finally { await b.close(); }
    console.log('');
    if (failures.length) {
        console.log('FAIL ' + failures.length + ' of ' + cases);
        failures.forEach((f) => console.log('   - ' + f));
        process.exit(1);
    }
    console.log('PASS ' + cases + '/' + cases);
})().catch((e) => { console.error('HARNESS ERROR: ' + e.message); process.exit(2); });
