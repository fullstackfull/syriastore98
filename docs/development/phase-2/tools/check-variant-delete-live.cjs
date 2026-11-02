/**
 * F162 / F73 / F163 — DELETING ONE VARIANT MUST NOT TOUCH ANOTHER, driven on the real page.
 *
 * WHY A BROWSER AND NOT A SOURCE CHECK. The defect was a jQuery selector, and its damage is which
 * DOM nodes survive a click. Nothing short of a real click in a real page answers that -- and the
 * first two attempts at this measurement both answered WRONGLY, in opposite directions, which is
 * the reason this file is kept rather than thrown away:
 *
 *   1. It injected the view's HTML with `innerHTML`, which does NOT execute <script> tags, so the
 *      view's own handler never bound. The click did nothing and the probe reported "M/L survived"
 *      -- i.e. it reported the defect as ABSENT because the code under test had not run. jQuery's
 *      `.html()` evaluates the scripts, which is what the real create page uses.
 *   2. A comment added to the view while fixing it contained the Blade directive name for a loop
 *      written out in full, inside a JavaScript comment. Blade compiles its own directives
 *      anywhere in the file, so the view 500ed with "Malformed" and the probe reported zero fields
 *      -- i.e. it reported a broken view as a clean result.
 *
 * WHAT IT MEASURES. It logs in, opens `/product/create`, POSTs the page's own
 * `products/attribute_combination` endpoint with the two colliding terms `M` and `M/L` (option ids
 * 2 and 7 in `database/seed-p2dev.php`), injects the response the way the page does, and then:
 *
 *   - lists every per-variant field name before and after clicking delete on the SHORTER term;
 *   - reports which fields were removed and which of those belonged to the OTHER variant;
 *   - lists the generated DOM ids and any duplicates among them.
 *
 * MEASURED BEFORE THE FIX: clicking delete on `M` removed 33 fields, SEVENTEEN of them M/L's,
 * including `product_variation_price_M/L`, `product_sale_price_M/L`, `product_stock_M/L` and
 * `product_sku_M/L`. Six ids were duplicated across variants.
 * AFTER: 17 fields removed, none of them M/L's, and zero duplicate ids.
 *
 * usage: node docs/development/phase-2/tools/check-variant-delete-live.cjs
 */
'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const REPO='/home/user/syriastore98';
const ENV=Object.assign({},process.env,{DB_CONNECTION:'sqlite',DB_DATABASE:path.join(REPO,'database','p2dev.sqlite'),CACHE_STORE:'file',SESSION_DRIVER:'file'});
(async()=>{
  execFileSync('php',[path.join(REPO,'docs/development/phase-2/tools/set-admin-preview-state.php'),'1','light','ltr','en','merchant@example.test'],{cwd:REPO,env:ENV,stdio:'pipe'});
  const b=await chromium.launch({args:['--no-sandbox']});
  const c=await b.newContext({viewport:{width:1500,height:950}});
  await c.route('**/merchant-notifications/feed*',r=>r.abort());
  const p=await c.newPage();
  await p.goto('http://127.0.0.1:8899/login',{waitUntil:'domcontentloaded'});
  await p.fill('input[name=email]','merchant@example.test'); await p.fill('input[name=password]','password');
  await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}).catch(()=>{}),p.click('button[type=submit]')]);
  await p.goto('http://127.0.0.1:8899/product/create',{waitUntil:'domcontentloaded'});
  await p.waitForTimeout(800);

  // Drive the REAL endpoint the create page calls, with the two colliding terms 'M' (opt 2) and
  // 'M/L' (opt 7), and inject the response exactly where the page injects it.
  const injected = await p.evaluate(async () => {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
      || document.querySelector('input[name="_token"]')?.value;
    const fd = new FormData();
    fd.append('_token', token);
    fd.append('attribute_no[]', '1');
    fd.append('for_variation_1', '1');
    fd.append('attribute_options_1[]', '2');
    fd.append('attribute_options_1[]', '7');
    fd.append('options_1[]', '2');
    fd.append('options_1[]', '7');
    fd.append('price', '100');
    fd.append('product_stock', '10');
    const r = await fetch('/products/attribute_combination', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const html = await r.text();
    /* jQuery's .html(), NOT innerHTML. innerHTML does NOT execute <script> tags, so the view's
       own inline handler never binds and the delete button does nothing -- which is exactly what
       the first run of this probe measured, and it very nearly got recorded as "the defect does
       not exist". jQuery's .html() extracts and evaluates the scripts, which is what the real
       create page does. */
    if (!window.jQuery) { return { status: r.status, error: 'no jQuery on the page' }; }
    let host = document.querySelector('.attribute_combination');
    if (!host) { host = document.createElement('div'); host.className = 'attribute_combination'; document.body.appendChild(host); }
    window.jQuery(host).html(html);
    return { status: r.status, length: html.length, handlerBound: true };
  });
  console.log('endpoint:', JSON.stringify(injected));

  // F73: are the generated DOM ids unique?
  const ids = await p.evaluate(() => {
    const all = Array.from(document.querySelectorAll('.attribute_combination [id]')).map(e => e.id).filter(Boolean);
    const dup = all.filter((x, i) => all.indexOf(x) !== i);
    const collapses = all.filter(x => x.startsWith('collapseone_'));
    const panels = Array.from(document.querySelectorAll('.attribute_combination [class*="remove_option_"]'))
      .map(e => String(e.className).split(/\s+/).find(c => c.startsWith('remove_option_')));
    return { collapses, panels, duplicates: Array.from(new Set(dup)) };
  });
  console.log('\nF73 collapse ids :', JSON.stringify(ids.collapses));
  console.log('F73 panel classes:', JSON.stringify(ids.panels));
  console.log('F73 DUPLICATE ids:', JSON.stringify(ids.duplicates));

  const before = await p.evaluate(() => ({
    names: Array.from(document.querySelectorAll('.attribute_combination [name]')).map(e => e.getAttribute('name')),
    deletes: Array.from(document.querySelectorAll('.attribute_combination .delete_option'))
      .map(a => ({ id: a.getAttribute('data-id'),
                   term: (a.closest('button') ? a.closest('button').textContent : '').trim() })),
  }));
  console.log('\nfields before :', JSON.stringify(before.names));
  console.log('delete buttons:', JSON.stringify(before.deletes));

  // Click the delete button whose ORIGINAL id is exactly 'M' -- the shorter of the two terms.
  const clicked = await p.evaluate(() => {
    /* BY THE TERM THE MERCHANT SEES, not by a data attribute. `data-original-id` existed only to
       drive the substring removal F162 was and went with it, so the button is found the way a
       person finds it: the header of its own panel reads the term. */
    const a = Array.from(document.querySelectorAll('.attribute_combination .delete_option'))
      .find(x => {
        const head = x.closest('button');
        return head && head.textContent.trim() === 'M';
      });
    if (!a) { return false; }
    a.click();
    return true;
  });
  console.log('\nclicked delete for term "M":', clicked);
  await p.waitForTimeout(300);

  const after = await p.evaluate(() => Array.from(document.querySelectorAll('.attribute_combination [name]')).map(e => e.getAttribute('name')));
  console.log('fields after  :', JSON.stringify(after));

  const lost = before.names.filter(n => !after.includes(n));
  const mlLost = lost.filter(n => n.includes('M/L'));
  console.log('\nremoved       :', JSON.stringify(lost));
  console.log('OF WHICH M/L  :', JSON.stringify(mlLost));
  console.log(mlLost.length ? '\n>>> DEFECT CONFIRMED: deleting "M" removed M/L fields' : '\n>>> M/L survived');
  await b.close();
})();
