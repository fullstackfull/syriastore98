/**
 * CAPTURE AND MEASURE AN HTML FRAGMENT, in the container its parent page injects it into.
 *
 * WHY THESE CANNOT BE DRIVEN AS ROUTES. `/order-preview/{id}`, `/product-preview/{id}`,
 * `/orders/order_view/{id}` and `/user-info/{id}` each answer HTTP 200 with a body that has no
 * `<html dir>` and no theme stylesheet, because they are not pages -- the parent page's
 * JavaScript fetches them and writes them into a side panel or a modal. Driven as routes,
 * `render-matrix.cjs` correctly fails its direction and theme premises on them, and a capture
 * taken anyway is a screenshot of unstyled markup on a white page. That is not what any merchant
 * sees, so it is not evidence about the surface.
 *
 * TWO CONTAINERS, ONE MECHANISM. The design system's panel (`data-ss-panel-open` +
 * `data-ss-panel-url`, loaded by `public/js/store-syria-ds.js:273`) and the Bootstrap modal
 * (`data-ajax-popup`, loaded by `public/js/custom.js:138`) differ only in which element ends up
 * holding the markup, so one harness takes the container as configuration.
 *
 * WHAT IS MEASURED, scoped to the container rather than the page, because the shell around it is
 * already verified and its findings would drown the fragment's own:
 *
 *   contrast   every text node against its composited background, WCAG 1.4.3 floors
 *   overflow   anything past the container's own box -- off-frame in a screenshot by definition
 *   latin      Latin-script words in the Arabic UI, minus merchant data already on the page
 *   nameless   controls with no accessible name
 *
 * PREMISES, because an empty panel photographs as a tidy empty panel:
 *   - the landed path is the requested parent path (a `redirect()->back()` refusal keeps the
 *     previous page's legitimate title -- see PHASE-2.5-DEV-FIXTURE-PERMISSIONS.md)
 *   - a visible trigger exists outside the shell's Quick Add menu
 *   - the container is visible AND holds real text with no loading placeholder left
 *
 * usage:
 *   node shoot-fragments.cjs --out <dir>
 *   node shoot-fragments.cjs --out <dir> --only order-preview
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };

const REPO = '/home/user/syriastore98';
const BASE = arg('base', 'http://127.0.0.1:8899');
const EMAIL = arg('email', 'merchant@example.test');
const PASSWORD = arg('password', 'password');
const STORE = arg('store', '1');
const OUT = arg('out', '/tmp/fragments');
const ONLY = arg('only', '').split(',').map(s => s.trim()).filter(Boolean);
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

/**
 * Each entry is one matrix row.
 *
 *   route      the PARENT page, which is what gets navigated to
 *   trigger    the control that loads the fragment
 *   container  where the markup lands, and what gets captured
 */
const SURFACES = [
  {
    slug: 'order-preview-panel',
    route: '/order',
    trigger: '[data-ss-panel-url*="order-preview"]',
    container: '#ss-order-preview-panel',
  },
  {
    slug: 'product-preview-panel',
    route: '/product',
    trigger: '[data-ss-panel-url*="product-preview"]',
    container: '#ss-product-preview-panel',
  },
  {
    slug: 'order-view-modal',
    route: '/order',
    trigger: 'button[data-url*="order_view"], a[data-url*="order_view"]',
    container: '#commanModel',
  },

  // ---- P2.5-WEB-SWEEP follow-up: the fragments the 2026-11-10 route sweep identified ---------
  //
  // Each of these scored EXACTLY 1/4 when driven as a URL, always `en-ltr-light`, which is the
  // fingerprint of a document fragment rather than a partial failure: the response carries no
  // `<html>` element, so `dir` reads null (null !== 'rtl' fails both Arabic rows) and no dark
  // stylesheet is linked (fails both dark rows), leaving the one combination that asks for
  // neither to pass by coincidence. They are captured here, in place, inside the page that
  // actually asks for them.
  //
  // THE CHART BODIES LOAD THEMSELVES. `fetchData(...)` runs on document-ready
  // (sale_by_category.blade.php:159, sale_by_brand.blade.php:175) and replaces `.chart_data`.
  // There is no trigger to click and no table to wait for.
  {
    slug: 'category-sales-body',
    route: '/category-order-sale-reports',
    container: '.chart_data',
    waitForRows: false,
  },
  {
    slug: 'brand-sales-body',
    route: '/Brand-order-sale-reports',
    container: '.chart_data',
    waitForRows: false,
  },

  // THE STOCK ROW PAYLOADS are three DataTables inside one 3-tab shell
  // (reports/stock_report.blade.php:160-235). Each tab's table fetches its own route
  // (stockReport.lowStock / .outOfStock / .mostStocked at :265, :325, :388), so the fragment is
  // the table body and the trigger is the pill that reveals it. Low-stock is the default tab;
  // its pill is clicked anyway so all three are driven the same way.
  {
    slug: 'stock-low-rows',
    route: '/stock-reports',
    trigger: 'a[data-bs-target="#pills-low-stock"]',
    container: '#low-stock-table',
  },
  {
    slug: 'stock-out-rows',
    route: '/stock-reports',
    trigger: 'a[data-bs-target="#pills-out-of-stock"]',
    container: '#out-of-stock-table',
  },
  {
    slug: 'stock-most-rows',
    route: '/stock-reports',
    trigger: 'a[data-bs-target="#pills-most-stocked"]',
    container: '#most-stocked-table',
  },

  // ---- P2.5-P1 (2026-11-10): components that render on load, clipped to their own box -------
  //
  // Each of these is a COMPONENT row in the inventory, not a page, so a capture of the host
  // screen would be evidence for the host and not for the component. The harness clips the
  // container's own bounding box, which is what makes these claimable separately.

  // The DataTable component itself. `/product` is the richest host (11 columns) and carries the
  // toolbar, the column chooser, the density control, the sticky trailing action column and the
  // info/pagination line -- all of which are the component, not the page.
  { slug: 'x-datatable', route: '/product', container: '.card.ss-dt-card' },

  // The product list's filter bar, server-rendered on load.
  {
    slug: 'product-filter-bar',
    route: '/product',
    container: 'form[data-ss-product-filters]',
    waitForRows: false,
  },
  // ...and the same bar with filters APPLIED, which is a materially different picture: the
  // filter-summary receipt renders at the top, the selects carry their values, and the Clear
  // control appears. One state proves only that state.
  {
    slug: 'product-filter-bar-applied',
    route: '/product?ss_status=1&ss_stock=low&ss_apply=1',
    container: 'form[data-ss-product-filters]',
    waitForRows: false,
  },

  // The order filter bar, and its saved-views strip. The query string is load-bearing twice:
  // it makes the current-filter set non-empty, so the save box and Clear link render, and it
  // makes the filter-summary receipt render inside the bar.
  {
    slug: 'order-filter-bar',
    route: '/order?ss_status=1&ss_apply=1',
    container: 'form[data-ss-order-filters]',
    waitForRows: false,
  },
  {
    slug: 'order-saved-views',
    route: '/order?ss_status=1&ss_apply=1',
    container: '.ss-filterbar__saved',
    waitForRows: false,
  },

  // The customer list's status cell -- a switch plus a badge. Dark-mode switch contrast is the
  // known risk on this shape (the bottom-sheet switches measured 1.09:1 for exactly this reason),
  // so it is clipped as its own surface rather than read off the row capture.
  {
    slug: 'customer-status-toggle',
    route: '/customer',
    // Resolved by what the cell CONTAINS AND ITS STATE, not by its position.
    //
    // Two earlier selectors failed for two different reasons and both were informative.
    // `td:nth-last-child(2)` picked a neighbouring column -- 5 characters of text and no
    // control. Adding `tr:first-child` then found nothing at all, because the first row in this
    // fixture is a GUEST: customer/status.blade.php renders the switch only when the viewer has
    // `Status Customer` AND `regiester_date` is not null, and a guest gets a read-only badge
    // instead. The fixture holds 4 customers, 3 registered (one of them status=0) and 1 guest.
    //
    // So the three arms are selected by state, which is also the only way to be sure which
    // picture is which: a switch has no text, so ON and OFF are the same string.
    container: '#customer-table td:has(.page-checkbox:checked)',
  },
  {
    slug: 'customer-status-toggle-off',
    route: '/customer',
    container: '#customer-table td:has(.page-checkbox:not(:checked))',
  },
  {
    slug: 'customer-status-guest',
    route: '/customer',
    container: '#customer-table td:has(.ss-badge__dot)',
    // "Guest" is five characters and a badge is a <span>, not a control, so this arm meets
    // neither the 20-character floor nor the control test. The floor is declared here rather
    // than widened globally: a surface that knows it is a word-long badge can say so, and every
    // other surface keeps the stricter default.
    minText: 3,
  },

  // The variant delete confirmation. The saved-variant panel fetches itself on document-ready
  // (product/edit.blade.php:857), so the trigger exists without any fixture work. The dialog is
  // captured OPEN and never confirmed -- confirming would really delete the variant.
  {
    slug: 'variant-delete-dialog',
    route: '/product/1/edit',
    trigger: 'a.delete-option-comment',
    container: '#ss-variant-delete-dialog',
    waitForRows: false,
  },
];

const COMBOS = [
  { lang: 'en', dir: 'ltr', theme: 'light' },
  { lang: 'en', dir: 'ltr', theme: 'dark' },
  { lang: 'ar', dir: 'rtl', theme: 'light' },
  { lang: 'ar', dir: 'rtl', theme: 'dark' },
];

function setState(theme, dir, lang) {
  execFileSync('php', [STATE_TOOL, STORE, theme, dir, lang, EMAIL], {
    cwd: path.dirname(STATE_TOOL),
    env: {
      ...process.env,
      DB_CONNECTION: process.env.DB_CONNECTION || 'sqlite',
      DB_DATABASE: process.env.DB_DATABASE || path.join(REPO, 'database/p2dev.sqlite'),
      CACHE_STORE: process.env.CACHE_STORE || 'file',
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

const LATIN_OK = ['id', 'url', 'sku', 'csv', 'pdf', 'png', 'jpg', 'qr', 'api', 'cod', 'vat',
  'n', 'a', 'mb', 'kb', 'utc', 'sar', 'usd', 'syp'];

const MEASURE = (args) => {
  const root = document.querySelector(args.container);
  if (!root) return { ok: false, why: 'container ' + args.container + ' is not in the DOM' };

  const cs0 = getComputedStyle(root);
  if (cs0.display === 'none' || cs0.visibility === 'hidden') {
    return { ok: false, why: 'container is present but not displayed' };
  }

  const text = (root.innerText || '').trim();
  // The DS panel writes `<p class="ss-panel__loading">` while the fetch is in flight, and the
  // modal writes a spinner. Either means the fragment has not arrived.
  if (root.querySelector('.ss-panel__loading, .ss-skeleton')) {
    return { ok: false, why: 'container still shows its loading placeholder' };
  }
  // ARRIVED = 20 characters of text OR a visible control. The same widening as the arrival wait
  // above, and for the same reason: a status cell is a switch and a badge, so counting its
  // characters measures nothing about whether it rendered. A container that is genuinely empty
  // still has neither, and still fails here.
  const minText = typeof args.minText === 'number' ? args.minText : 20;

  if (text.length < minText) {
    const controls = [...root.querySelectorAll(
      'input, button, select, textarea, a[href], [role="switch"]',
    )].filter((c) => {
      const ccs = getComputedStyle(c);
      return ccs.display !== 'none' && ccs.visibility !== 'hidden' && !!c.offsetParent;
    });

    if (controls.length === 0) {
      return {
        ok: false,
        why: 'container holds ' + text.length + ' chars of text (floor ' + minText
          + ') and no visible control (fragment never arrived)',
      };
    }
  }

  const parse = s => (s.match(/[\d.]+/g) || []).map(Number);
  const paintedBg = (el) => {
    const L = [];
    for (let e = el; e; e = e.parentElement) {
      const c = parse(getComputedStyle(e).backgroundColor); if (c.length < 3) continue;
      const a = c[3] === undefined ? 1 : c[3]; if (a <= 0) continue;
      L.push([c[0], c[1], c[2], a]); if (a >= 1) break;
    }
    if (!L.length) return [255, 255, 255];
    let out = L[L.length - 1][3] >= 1 ? L[L.length - 1].slice(0, 3) : [255, 255, 255];
    for (let i = L.length - 1; i >= 0; i--) {
      const [r, g, b, a] = L[i];
      if (a >= 1) { out = [r, g, b]; continue; }
      out = [r * a + out[0] * (1 - a), g * a + out[1] * (1 - a), b * a + out[2] * (1 - a)];
    }
    return out.map(Math.round);
  };
  const lum = c => {
    const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
  };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const clippedAway = (cs) => cs.clip === 'rect(0px, 0px, 0px, 0px)'
    || /inset\(\s*50%/.test(cs.clipPath || '') || (cs.clipPath || '') === 'circle(0px)';
  const hidden = (el) => {
    for (let e = el; e && e !== document.body; e = e.parentElement) {
      const cs = getComputedStyle(e);
      if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) <= 0.05) return true;
    }
    return false;
  };

  const findings = [];

  for (const el of root.querySelectorAll('*')) {
    if (![...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())) continue;
    if (el.closest('.visually-hidden, .sr-only, .ss-visually-hidden')) continue;
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) continue;
    const cs = getComputedStyle(el);
    if (hidden(el) || clippedAway(cs)) continue;
    const fg = parse(cs.color).slice(0, 3); if (fg.length < 3) continue;
    const size = parseFloat(cs.fontSize);
    const floor = (size >= 24 || (parseInt(cs.fontWeight, 10) >= 700 && size >= 18.66)) ? 3 : 4.5;
    const n = Number(ratio(fg, paintedBg(el)).toFixed(2));
    if (n >= floor) continue;
    findings.push({
      kind: 'contrast',
      detail: n + ':1 needs ' + floor + '  ' + el.tagName.toLowerCase()
        + '.' + (el.className || '').toString().trim().slice(0, 26).replace(/ +/g, '.')
        + '  "' + el.textContent.trim().replace(/\s+/g, ' ').slice(0, 26) + '"',
    });
  }

  const rr = root.getBoundingClientRect();
  for (const el of root.querySelectorAll('*')) {
    if (hidden(el)) continue;
    const r = el.getBoundingClientRect();
    if (r.width < 2) continue;
    const over = Math.round(Math.max(r.right - rr.right, rr.left - r.left));

    // CONTENT THAT SCROLLS IS NOT CONTENT THAT IS LOST, and this check must tell them apart.
    //
    // The DataTable component is a horizontally scrollable card with a pinned trailing Action
    // column: the rows are deliberately wider than the viewport and slide UNDER the pinned
    // column, which is the design, not a defect. Measured on `/product` (11 columns): every
    // `tr` reported 43px past the container in LTR and 38px in RTL, 56 findings from one
    // surface, all of them correct arithmetic about an intended layout.
    //
    // This is a NARROWING of what counts as a finding, not a relaxation of the floor: the
    // question the check exists to answer is "is this off-frame and unreachable", and in a
    // scrollable box it is reachable. A container that does NOT scroll in that axis still
    // reports at 4px exactly as before. Left unfixed, 56 false findings per run is how a real
    // one stops being read.
    const scrolls = (() => {
      for (let n = el; n && n !== document.documentElement; n = n.parentElement) {
        const cs = getComputedStyle(n);
        const ox = cs.overflowX;
        if ((ox === 'auto' || ox === 'scroll') && n.scrollWidth > n.clientWidth + 4) return true;
        if (n === root) break;
      }
      return false;
    })();

    if (over > 4 && !scrolls) {
      findings.push({
        kind: 'overflow',
        detail: over + 'px past the container  ' + el.tagName.toLowerCase()
          + '.' + (el.className || '').toString().trim().slice(0, 26).replace(/ +/g, '.'),
      });
    }
  }

  for (const el of root.querySelectorAll('a, button, input, select, textarea')) {
    if (hidden(el)) continue;
    const type = (el.getAttribute('type') || '').toLowerCase();
    if (type === 'hidden') continue;
    const valueIsName = ['submit', 'button', 'reset'].includes(type);
    const id = el.id;
    const named = (el.getAttribute('aria-label') || '').trim()
      || (el.getAttribute('aria-labelledby') || '').trim()
      || (el.getAttribute('title') || '').trim()
      || (el.getAttribute('placeholder') || '').trim()
      || (valueIsName ? (el.getAttribute('value') || '').trim() : '')
      || (['A', 'BUTTON'].includes(el.tagName) ? (el.textContent || '').trim() : '')
      || (id ? ((document.querySelector('label[for="' + CSS.escape(id) + '"]') || {}).textContent || '').trim() : '')
      || ((el.closest('label') || {}).textContent || '').trim();
    if (named) continue;
    findings.push({
      kind: 'nameless',
      detail: el.tagName.toLowerCase() + (type ? '[' + type + ']' : '')
        + (el.getAttribute('name') ? '[name=' + el.getAttribute('name') + ']' : ''),
    });
  }

  if (args.lang === 'ar') {
    const ok = new Set(args.latinOk);
    // Merchant data already on the parent page is not an untranslated string -- the rule
    // shoot-row-actions.cjs and shoot-modals.cjs both needed, for the same reason.
    const pageText = (document.body.innerText || '').toLowerCase();
    const seen = new Set();
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      const raw = (n.textContent || '').trim();
      if (!raw) continue;
      const el = n.parentElement;
      if (!el || hidden(el)) continue;
      if (el.closest('.visually-hidden, .sr-only, .ss-visually-hidden, code, pre, script, style, option')) continue;
      if (el.closest('input, textarea, select, .choices__item, .select2-selection')) continue;
      const bad = (raw.match(/[A-Za-z][A-Za-z'.-]+/g) || [])
        .filter(w => !w.toLowerCase().split(/[^a-z]+/).filter(Boolean).every(part => ok.has(part)))
        // A word that also appears on the parent page is the merchant's own data.
        .filter(w => pageText.split(w.toLowerCase()).length - 1 <= 1);
      if (!bad.length) continue;
      const key = bad.join(' ').slice(0, 36);
      if (seen.has(key)) continue;
      seen.add(key);
      findings.push({ kind: 'latin', detail: '"' + bad.slice(0, 5).join(' ') + '" in "' + raw.replace(/\s+/g, ' ').slice(0, 44) + '"' });
    }
  }

  return {
    ok: true,
    chars: text.length,
    findings,
    box: {
      x: Math.max(0, rr.x - 8), y: Math.max(0, rr.y - 8),
      width: Math.min(rr.width + 16, window.innerWidth),
      height: Math.min(rr.height + 16, window.innerHeight),
    },
  };
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const all = [];
  const perSurface = new Map();

  for (const combo of COMBOS) {
    setState(combo.theme, combo.dir, combo.lang);
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();

    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.click('button[type="submit"], input[type="submit"]'),
    ]);
    if (/\/login\b/.test(page.url())) {
      console.log('PREMISE FAILED: not signed in as ' + EMAIL);
      process.exitCode = 1;
      await browser.close();
      return;
    }

    for (const s of SURFACES) {
      if (ONLY.length && !ONLY.some(o => s.slug.includes(o))) continue;
      const label = combo.lang + '-' + combo.dir + '-' + combo.theme;
      const note = (kind, detail) => all.push({ slug: s.slug, combo: label, kind, detail });

      await page.goto(BASE + s.route, { waitUntil: 'domcontentloaded' }).catch(() => {});

      const landed = (() => { try { return new URL(page.url()).pathname; } catch (e) { return page.url(); } })();

      // COMPARE PATHS, NOT THE WHOLE ROUTE STRING. Several surfaces need a query string to exist
      // at all -- the order and product filter bars only render their summary receipt, their
      // Clear control and their save box when a filter is actually applied. `page.url()` gives a
      // pathname with no query, so comparing it against a route that carries one reported
      // "landed on /order, asked for /order?ss_status=1&ss_apply=1" for a page that had loaded
      // perfectly. The premise this check exists for is "did a redirect take us somewhere else",
      // and that is a question about the path.
      const wantPath = s.route.split('?')[0].split('#')[0];

      if (landed.replace(/\/+$/, '') !== wantPath.replace(/\/+$/, '')) {
        note('premise', 'landed on ' + landed + ', asked for ' + wantPath);
        continue;
      }

      // The list is a DataTable: wait for a real row before looking for a row trigger.
      // SKIPPED for a surface that declares `waitForRows: false`. The chart bodies live on pages
      // that have no table at all, and this wait is a 15-SECOND timeout there -- harmless, but it
      // doubles the run, and a swallowed timeout reads like a working wait to the next person.
      if (s.waitForRows !== false) {
        await page.waitForFunction(() => {
          const rows = [...document.querySelectorAll('table tbody tr')];
          return rows.length > 0 && rows.some(r => !r.querySelector('.ss-dt-empty') && r.querySelectorAll('td').length > 1);
        }, null, { timeout: 15000 }).catch(() => {});
      }

      // A TRIGGER IS OPTIONAL, because not every fragment waits to be asked for.
      //
      // `reports/sale_by_category.blade.php:159` and `sale_by_brand.blade.php:175` both call
      // `fetchData(...)` inside `$(document).ready`, so the chart body is fetched and injected
      // into `.chart_data` without anybody clicking anything. Requiring a trigger reported those
      // two as "no visible trigger", which is true and says nothing about the fragment. A surface
      // with no `trigger` goes straight to the container wait.
      if (s.trigger) {
        // WAIT FOR THE TRIGGER, because some of them are injected rather than served.
        //
        // The saved-variant panel fetches itself on document-ready
        // (product/edit.blade.php:857) and injects attribute_combinations_data into
        // `.attribute_combination`, so `a.delete-option-comment` does not exist in the served
        // HTML. Searching immediately reported "0 matched", which reads as "this page has no
        // such control" when the truth was "not yet". A surface with `waitForRows: false` skips
        // the row wait, so there was nothing else slowing the harness down either.
        await page.waitForSelector(s.trigger, { timeout: 15000, state: 'attached' })
          .catch(() => {});

        const handles = await page.$$(s.trigger);
        let trigger = null;
        for (const h of handles) {
          const usable = await h.evaluate(
            (e) => !!e.offsetParent && !e.closest('.dropdown-menu, nav, header, .ss-topbar'),
          ).catch(() => false);
          if (usable) { trigger = h; break; }
        }

        if (!trigger) {
          note('premise', 'no visible trigger outside a menu matching ' + s.trigger
            + ' (' + handles.length + ' matched)');
          continue;
        }

        await trigger.click({ timeout: 5000 }).catch(() => {});
      }

      await page.waitForFunction((a) => {
        const el = document.querySelector(a.container);
        if (!el) return false;
        const cs = getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden') return false;
        if (el.querySelector('.ss-panel__loading, .ss-skeleton')) return false;
        if (parseFloat(cs.opacity) < 0.99) return false;

        // ARRIVED = 20 characters of text OR a visible control.
        //
        // The customer list's status cell is a switch and a badge: it reported "container holds
        // 5 chars of text (fragment never arrived)" while being fully rendered, because a toggle
        // has no text to count. Judging a control-only container by its character count is the
        // same mistake the modal harness made when it defined "loaded" as 20 characters and so
        // rejected every small form.
        //
        // This is a widening of what counts as ARRIVED, not of what counts as correct: a
        // container that is empty still has neither text nor a control and still fails.
        const floor = typeof a.minText === 'number' ? a.minText : 20;

        if ((el.innerText || '').trim().length >= floor) return true;

        return [...el.querySelectorAll('input, button, select, textarea, a[href], [role="switch"]')]
          .some((c) => {
            const ccs = getComputedStyle(c);
            return ccs.display !== 'none' && ccs.visibility !== 'hidden' && !!c.offsetParent;
          });
      }, { container: s.container, minText: s.minText }, { timeout: 15000 }).catch(() => {});

      // One frame after any transition, so the measured box is the painted box.
      await page.waitForTimeout(200);

      const m = await page.evaluate(MEASURE, {
        container: s.container,
        lang: combo.lang,
        latinOk: LATIN_OK,
        minText: s.minText,
      })
        .catch(e => ({ ok: false, why: String(e).slice(0, 120) }));

      if (!m.ok) { note('premise', m.why); continue; }

      await page.screenshot({
        path: path.join(OUT, s.slug + '__' + combo.lang + '_' + combo.dir + '_' + combo.theme + '.png'),
        clip: m.box,
      }).catch(() => {});

      for (const f of m.findings) note(f.kind, f.detail);

      if (!perSurface.has(s.slug)) perSurface.set(s.slug, { combos: 0, chars: 0 });
      const t = perSurface.get(s.slug);
      t.combos += 1;
      t.chars = m.chars;
    }

    await ctx.close();
  }

  await browser.close();

  console.log('\nFRAGMENTS CAPTURED: ' + perSurface.size + ' of ' + (ONLY.length ? '(filtered)' : SURFACES.length));
  for (const [slug, t] of [...perSurface.entries()].sort()) {
    console.log('  ' + (t.combos === 4 ? ' ' : '!') + ' ' + slug.padEnd(26) + ' combos=' + t.combos + '  chars=' + t.chars);
  }

  const byKind = {};
  for (const f of all) byKind[f.kind] = (byKind[f.kind] || 0) + 1;
  console.log('\nFINDINGS: ' + all.length + '  ' + JSON.stringify(byKind));

  const groups = {};
  for (const f of all) { const k = f.kind + ' | ' + f.detail; (groups[k] = groups[k] || []).push(f.slug + '/' + f.combo); }
  for (const [k, where] of Object.entries(groups).sort((a, b) => b[1].length - a[1].length)) {
    console.log('\n' + k);
    console.log('   x' + where.length + '  ' + where.slice(0, 5).join(', ') + (where.length > 5 ? ', ...' : ''));
  }

  fs.writeFileSync(path.join(OUT, 'measurements.json'), JSON.stringify({ findings: all, surfaces: [...perSurface.entries()] }, null, 2));
})();
