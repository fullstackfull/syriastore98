/**
 * F164 / F165 / F73 — THE VARIANT EDIT SCREEN, driven on the real page.
 *
 * WHY A BROWSER. Two of these defects are only visible once the markup is in a document: whether
 * a `<label for>` activates the control beside it or one further up the page, and whether
 * `getElementById` resolves inside the panel that asked.
 *
 * THE THREE RENDERERS, because the id defects existed in all three and fixing one hid nothing:
 *   - `attribute_combinations.blade.php`      — create, POST products/attribute_combination
 *   - `attribute_combinations_edit.blade.php` — PUT products/attribute_combination/edit
 *   - `attribute_combinations_data.blade.php` — the panel the EDIT PAGE injects from
 *                                               $(document).ready, so it is what a merchant sees
 *                                               first; it was the last to be checked and still
 *                                               carried every defect.
 *
 * READ THIS BEFORE DRIVING EITHER COMBINATION ENDPOINT — IT COST A WRONG FIX.
 * ==========================================================================
 * The two endpoints take the SAME parameter name with DIFFERENT vocabularies:
 *
 *   - POST products/attribute_combination (create) expects `attribute_options_N[]` to carry
 *     option **IDS**. Its page builds `<option value="<id>">` in JavaScript from
 *     `attribute_option()`, which returns `pluck('terms', 'id')`.
 *   - PUT products/attribute_combination/edit expects option **TERMS**. Its page's `<option>`
 *     carries NO `value` attribute (edit.blade.php:632-636), so the browser submits the option's
 *     TEXT, and that text is `pluck('terms')` (edit.blade.php:552).
 *
 * An earlier version of this file POSTED NUMERIC IDS to the edit endpoint and measured
 * `options_datas[] = "2-4"` with price 0 and stock 0 against a stored `M-Natural` holding 43 and
 * 6. The numbers were real; the conclusion — that the edit handler keyed variants by id — was
 * wrong, because the probe was sending the other page's shape. The handler was right and the
 * "fix" built on that reading emptied the combination table for every real request, which
 * `MerchantProductOwnershipTest`'s positive control caught. This file now drives each endpoint in
 * ITS OWN shape, and `VariantCombinationVocabularyTest` pins both so the asymmetry cannot be
 * tidied away by inspection.
 *
 * WHAT IT MEASURES, and what it measured before the fix (dev product 1 `Damask Cotton Scarf`):
 *
 *   the variant key        the edit endpoint, sent TERMS, must key panels by the stored
 *                          `variant` value — `$product->ProductVariant($str)` is
 *                          `where('variant', $str)` exactly, so a key that misses shows the
 *                          merchant 0 for a variant holding real money and offers no
 *                          server-side delete.
 *   F164 duplicate ids     BEFORE 7 ids emitted once per variant: payment-gateways, COD,
 *                                 Shipping, enable_manage_stock, download-product,
 *                                 downloadable_product, down_products.   AFTER 0.
 *   F164 accessible name   BEFORE every panel's `aria-labelledby="COD"` resolved to the FIRST
 *                                 variant's header — the panel whose own term was `M/L` was
 *                                 announced as `M`.                       AFTER each owns its own.
 *   F164 the file label    BEFORE both variants' labels activated the PRODUCT-level
 *                                 `downloadable_product` input (edit.blade.php:470), first in
 *                                 document order.                         AFTER each its own.
 *   F164 the preview       BEFORE `getElementById('down_products')` returned the first match, so
 *                                 a file chosen on the second variant wrote the first variant's
 *                                 <img>; and in the data renderer it matched NOTHING, so `.src`
 *                                 on null threw.                          AFTER each its own.
 *
 * usage: node docs/development/phase-2/tools/check-variant-edit-keys-live.cjs
 */
'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const REPO = '/home/user/syriastore98';
const ENV = Object.assign({}, process.env, {
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'),
  CACHE_STORE: 'file',
  SESSION_DRIVER: 'file',
});

(async () => {
  execFileSync('php', [path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php'),
    '1', 'light', 'ltr', 'en', 'merchant@example.test'], { cwd: REPO, env: ENV, stdio: 'pipe' });

  const b = await chromium.launch({ args: ['--no-sandbox'] });
  const c = await b.newContext({ viewport: { width: 1500, height: 950 } });
  await c.route('**/merchant-notifications/feed*', r => r.abort());
  const p = await c.newPage();
  p.on('pageerror', e => console.log('PAGEERROR:', String(e).slice(0, 200)));

  await p.goto('http://127.0.0.1:8899/login', { waitUntil: 'domcontentloaded' });
  await p.fill('input[name=email]', 'merchant@example.test');
  await p.fill('input[name=password]', 'password');
  await Promise.all([p.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    p.click('button[type=submit]')]);
  await p.goto('http://127.0.0.1:8899/product/1/edit', { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(900);

  /* ------------------------------------------------------------------ the KEY, two attributes */
  const key = await p.evaluate(async () => {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const body = new URLSearchParams();
    body.append('_token', token);
    body.append('id', '1'); body.append('sku', 'Damask_Cotton_Scarf'); body.append('price', '100');
    body.append('attribute_no[]', '1'); body.append('attribute_no[]', '2');
    body.append('for_variation_1', '1'); body.append('for_variation_2', '1');
    /* TERMS, because this is the EDIT endpoint. Sending the ids `2` and `4` here is what produced
       this file's earlier wrong measurement. */
    body.append('attribute_options_1[]', 'M');
    body.append('attribute_options_2[]', 'Natural');
    body.append('options_1[]', 'M'); body.append('options_2[]', 'Natural');
    const r = await fetch('/products/attribute_combination/edit', {
      method: 'PUT', body: body.toString(),
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' } });
    const html = await r.text();
    /* jQuery's .html(), NOT innerHTML: innerHTML does not execute <script> tags, so the view's own
       handlers never bind and a probe using it reports defects as absent. */
    let host = document.querySelector('.attribute_combination');
    if (!host) { host = document.createElement('div'); host.className = 'attribute_combination'; document.body.appendChild(host); }
    window.jQuery(host).html(html);

    const pan = host.querySelector('.attribute_options_datas');
    const valueOf = re => {
      const el = Array.from(pan.querySelectorAll('[name]')).find(e => re.test(e.getAttribute('name')));
      return el ? { name: el.getAttribute('name'), value: el.value } : null;
    };
    const hidden = pan.querySelector('input[name="options_datas[]"]');
    return {
      status: r.status,
      variantKey: hidden ? hidden.value : null,
      sale: valueOf(/^product_sale_price_/),
      stock: valueOf(/^product_stock_/),
      sku: valueOf(/^product_sku_/),
      hasServerSideDeleteRoute: !!pan.querySelector('.delete-comment'),
    };
  });
  console.log('EDIT renderer, sent TERMS — the variant key and its money:');
  console.log(' ', JSON.stringify(key));

  /* ------------------------------------------- the IDS, two colliding terms in the same renderer */
  const ids = await p.evaluate(async () => {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const body = new URLSearchParams();
    body.append('_token', token);
    body.append('id', '1'); body.append('sku', 'Damask_Cotton_Scarf'); body.append('price', '100');
    body.append('attribute_no[]', '1'); body.append('for_variation_1', '1');
    /* The two terms whose sanitised ids collide without the F73 index suffix. */
    body.append('attribute_options_1[]', 'M');
    body.append('attribute_options_1[]', 'M/L');
    body.append('options_1[]', 'M'); body.append('options_1[]', 'M/L');
    const r = await fetch('/products/attribute_combination/edit', {
      method: 'PUT', body: body.toString(),
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' } });
    const host = document.querySelector('.attribute_combination');
    window.jQuery(host).html(await r.text());

    const panels = Array.from(host.querySelectorAll('.attribute_options_datas'));
    const termOf = el => {
      const btn = el.querySelector('.accordion-button');
      return btn ? btn.textContent.trim().split('\n')[0].trim() : '?';
    };
    const all = Array.from(host.querySelectorAll('[id]')).map(e => e.id).filter(Boolean);

    return {
      terms: panels.map(termOf),
      duplicates: Array.from(new Set(all.filter((x, i) => all.indexOf(x) !== i))),
      aria: panels.map(pan => {
        const lbl = pan.querySelector('.accordion-collapse')?.getAttribute('aria-labelledby');
        const t = lbl ? document.getElementById(lbl) : null;
        return { term: termOf(pan), labelledBy: lbl, ownsIt: !!(t && pan.contains(t)),
                 announcedAs: t ? t.textContent.trim().split('\n')[0].trim() : null };
      }),
      fileLabel: panels.map(pan => {
        const block = pan.querySelector('.download-product');
        const lab = block?.querySelector('label[for]');
        const input = block?.querySelector('input[type=file]');
        const t = lab ? document.getElementById(lab.getAttribute('for')) : null;
        return { term: termOf(pan), for: lab?.getAttribute('for'),
                 activatesOwnInput: !!(t && input && t === input),
                 activatesProductLevelField: !!(t && !host.contains(t)),
                 activatedName: t?.getAttribute('name') };
      }),
      preview: panels.map(pan => {
        /* SCOPED TO `.download-product`. D-26 added a SECOND file input to every panel -- the
           variant image -- and it renders BEFORE this one, so a bare
           `querySelector('input[type=file]')` started returning the variant input, which carries
           no `onchange`, and this probe reported every preview target as null. The measurement
           was of the wrong element, not of a regression. */
        const input = pan.querySelector('.download-product input[type=file]');
        const m = (input?.getAttribute('onchange') || '').match(/getElementById\('([^']+)'\)/);
        const t = m ? document.getElementById(m[1]) : null;
        return { term: termOf(pan), targets: m ? m[1] : null, ownsIt: !!(t && pan.contains(t)) };
      }),
    };
  });
  console.log('\nEDIT renderer, the ids:');
  console.log('  terms        :', JSON.stringify(ids.terms));
  console.log('  DUPLICATE ids:', JSON.stringify(ids.duplicates));
  ids.aria.forEach(a => console.log('  aria   ', JSON.stringify(a)));
  ids.fileLabel.forEach(a => console.log('  label  ', JSON.stringify(a)));
  ids.preview.forEach(a => console.log('  preview', JSON.stringify(a)));

  /* ------------------------------- and the renderer the page injects on ready, with real variants */
  const dataView = await p.evaluate(async () => {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const body = new URLSearchParams();
    body.append('_token', token); body.append('id', '1');
    const r = await fetch('/products/attribute_combination_data/edit', {
      method: 'POST', body: body.toString(),
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' } });
    const host = document.createElement('div'); host.id = 'dataview'; document.body.appendChild(host);
    window.jQuery(host).html(await r.text());

    const all = Array.from(host.querySelectorAll('[id]')).map(e => e.id).filter(Boolean);
    const panels = Array.from(host.querySelectorAll('.attribute_option_data'));
    const owns = (pan, id) => { const t = id ? document.getElementById(id) : null; return !!(t && pan.contains(t)); };

    return {
      status: r.status,
      panels: panels.length,
      duplicates: Array.from(new Set(all.filter((x, i) => all.indexOf(x) !== i))),
      ariaAllOwn: panels.length > 0 && panels.every(pan =>
        owns(pan, pan.querySelector('.accordion-collapse')?.getAttribute('aria-labelledby'))),
      previewAllOwn: panels.length > 0 && panels.every(pan => {
        const m = (pan.querySelector('.download-product input[type=file]')?.getAttribute('onchange') || '')
          .match(/getElementById\('([^']+)'\)/);
        return owns(pan, m && m[1]);
      }),
      previewTargets: panels.map(pan => {
        const m = (pan.querySelector('.download-product input[type=file]')?.getAttribute('onchange') || '')
          .match(/getElementById\('([^']+)'\)/);
        return m ? m[1] : null;
      }),
    };
  });
  console.log('\nDATA renderer (injected by the edit page on ready), 8 stored variants:');
  console.log(' ', JSON.stringify(dataView));

  await b.close();
})();
