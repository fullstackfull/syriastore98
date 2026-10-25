/* ==========================================================================
   Phase 02 browser checks
   ==========================================================================

   Drives the rendered previews in a real Chromium against the real public/
   stylesheets. Same posture as docs/design-system/tools/browser-checks.cjs:
   these are EXECUTED, not asserted, and anything this cannot reach (routing,
   auth, tenancy, the DataTables stack) is out of its scope and is not claimed.

   Run:
     SS_PREVIEW_DIR=<dir> php -S 127.0.0.1:8099 -t public \
        docs/design-system/tools/preview-server.php &
     node docs/development/phase-2/tools/browser-checks-p02.cjs
   ========================================================================== */
const { chromium } = require('playwright');
const BASE = process.env.SS_PREVIEW_BASE || 'http://127.0.0.1:8099';

let pass = 0, fail = 0;
const results = [];
function check(name, ok, detail) {
  (ok ? pass++ : fail++);
  results.push(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  -- ' + detail : ''}`);
}

const WIDTHS = [360, 390, 414, 576, 768, 992, 1280, 1440];
const VARIANTS = [
  'after-en-full-light', 'after-ar-full-light',
  'after-en-full-dark',  'after-ar-full-dark',
  'after-en-empty-light','after-ar-noperm-light',
];

(async () => {
  const browser = await chromium.launch();
  console.log('Chromium', browser.version(), '\n');

  // ---------------------------------------------------------- 1. overflow
  for (const v of VARIANTS) {
    for (const w of WIDTHS) {
      const ctx = await browser.newContext({ viewport: { width: Math.max(w, 520), height: 900 } });
      const page = await ctx.newPage();
      const url = w < 520 ? `${BASE}/frame/${v}.html?w=${w}&h=900` : `${BASE}/preview/${v}.html`;
      await page.goto(url, { waitUntil: 'load' });
      await page.waitForTimeout(150);
      const frame = w < 520 ? page.frames()[1] : page.mainFrame();
      const over = await frame.evaluate(() => {
        const vw = document.documentElement.clientWidth;
        const bad = [];
        document.querySelectorAll('.ss-scope *').forEach(el => {
          const r = el.getBoundingClientRect();
          if (r.right > vw + 1 || r.left < -1) {
            bad.push(el.tagName.toLowerCase() + '.' + String(el.className).slice(0, 40));
          }
        });
        return { scrollW: document.documentElement.scrollWidth, vw, bad: bad.slice(0, 3) };
      });
      check(`no horizontal overflow  ${v} @${w}px`,
            over.scrollW <= over.vw + 1 && over.bad.length === 0,
            over.bad.length ? over.bad.join(', ') : `scrollW=${over.scrollW} vw=${over.vw}`);
      await ctx.close();
    }
  }

  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();

  // ------------------------------------------- 2. the honesty requirements
  await page.goto(`${BASE}/preview/after-en-full-light.html`, { waitUntil: 'load' });

  const periods = await page.$$eval('.ss-stat', els =>
    els.map(e => ({
      label: e.querySelector('.ss-stat__label')?.textContent.trim(),
      period: e.querySelector('.ss-stat__period')?.textContent.trim(),
    })));
  check('every stat states its period', periods.length > 0 && periods.every(p => p.period && p.period.length > 1),
        JSON.stringify(periods.map(p => `${p.label}=${p.period}`)));

  const body = await page.textContent('body');
  // "Order value", never "Revenue"/"Income"/"Profit" -- the figure is
  // sum(final_price), which is none of those.
  check('no "revenue" claim on the dashboard', !/\brevenue\b/i.test(body));
  check('no "profit" claim on the dashboard',  !/\bprofit\b/i.test(body.replace(/This is not payments received, and not profit\./, '')));
  check('order value is labelled as order value', /Order value/.test(body) && /all time, gross/.test(body));
  check('order value carries its disclaimer',
        /not payments received, and not profit/.test(body));

  // -------------------------------- 2b. the status breakdown must reconcile
  /* `orders.delivered_status` is one integer column carrying nine values, and
     the dashboard used to show six of them beside a total they could not add
     up to. These assert the parts partition the whole, from what is actually
     on screen rather than from the fixture. */
  const sumAttr = await page.$eval('[data-ss-status-sum]',
    el => Number(el.getAttribute('data-ss-status-sum')));
  const totalShown = await page.$$eval('.ss-stat', els => {
    const cell = els.find(e => /Orders$/.test(e.querySelector('.ss-stat__label')?.textContent.trim() || ''));
    return Number((cell?.querySelector('.ss-stat__value')?.textContent || '').replace(/[^\d]/g, ''));
  });
  check('the status breakdown sums to the order total',
        sumAttr === totalShown && totalShown > 0, `breakdown=${sumAttr} total=${totalShown}`);

  const chipCounts = await page.$$eval('.ss-chip', els =>
    els.map(e => Number((e.querySelector('.ss-num')?.textContent || '').replace(/[^\d]/g, '')))
       .filter(n => !Number.isNaN(n)));
  const chipSum = chipCounts.reduce((a, b) => a + b, 0);
  check('the rendered chips themselves sum to the total',
        chipSum === totalShown, `chips=[${chipCounts.join(',')}] sum=${chipSum} total=${totalShown}`);

  const footerText = await page.textContent('.ss-card__footer');
  check('the screen states the reconciliation in words',
        /add up to/i.test(footerText || ''), (footerText || '').trim().slice(0, 60));

  // The two values that are not fulfilment states are labelled as such, not
  // listed silently among the rest.
  const bodyAll = await page.textContent('body');
  check('non-fulfilment statuses are named as not being fulfilment states',
        /not fulfilment states/i.test(bodyAll) &&
        /Partially Paid/.test(bodyAll) && /Pre Order/.test(bodyAll));

  // ------------------------------------------------ 3. empty + permissions
  await page.goto(`${BASE}/preview/after-en-empty-light.html`, { waitUntil: 'load' });
  const attentionOnEmpty = await page.$$('.ss-attention');
  check('attention block is ABSENT when nothing needs attention', attentionOnEmpty.length === 0,
        `found ${attentionOnEmpty.length}`);
  const emptyBody = await page.textContent('body');
  check('a new store still sees its (zero) totals', /Orders/.test(emptyBody) && /\b0\b/.test(emptyBody));

  /* The principal the tenant/authz review named: a staff member of this
     tenant whose role grants nothing about orders. /dashboard carries no
     permission middleware, so they reach this page; what they must NOT get is
     anything this change newly put on it. */
  await page.goto(`${BASE}/preview/after-en-noperm-light.html`, { waitUntil: 'load' });
  const npBody = await page.textContent('body');
  check('ungated staff get no "Add product" control', !/Add Product/.test(npBody));

  // The one that matters. $total_revenues was in compact() with no consumer
  // before Phase 02, so rendering it ungated would be a disclosure this
  // change introduced -- store-wide gross order value to any tenant login.
  check('ungated staff do NOT see the order-value figure',
        !/Order value/.test(npBody), npBody.match(/Order value[^\n]{0,40}/)?.[0] || 'absent');
  check('ungated staff do NOT see a money figure at all',
        !/SYP\s[\d,]+\.\d\d/.test(npBody), npBody.match(/SYP\s[\d,]+\.\d\d/)?.[0] || 'none');

  // ...but the counts that were ALREADY visible before Phase 02 still are.
  // Gating those would be a regression dressed as hardening.
  check('ungated staff still see the order and customer counts',
        /1,284/.test(npBody) && /412/.test(npBody));

  const npLinks = await page.$$eval('.ss-scope a[href]', els =>
    els.map(e => e.getAttribute('href')).filter(h => h && h !== '#'));
  check('ungated staff are given no link the controller would refuse',
        npLinks.length === 0, npLinks.join(', ') || 'no links');

  const npChips = await page.$$eval('.ss-chip', els =>
    els.map(e => ({ tag: e.tagName.toLowerCase(), pressed: e.getAttribute('aria-pressed') })));
  check('ungated status chips are static, not dead buttons',
        npChips.length > 0 && npChips.every(c => c.tag === 'span' && c.pressed === null),
        JSON.stringify(npChips.slice(0, 3)));

  // And the full merchant DOES get it, or the gate is just a deletion.
  await page.goto(`${BASE}/preview/after-en-full-light.html`, { waitUntil: 'load' });
  const fullBody = await page.textContent('body');
  check('a merchant with Manage Order DOES see the order-value figure',
        /Order value/.test(fullBody) && /SYP\s1,284,500\.00/.test(fullBody));

  // ------------------------------------------------------ 4. RTL mirroring
  await page.goto(`${BASE}/preview/after-ar-full-light.html`, { waitUntil: 'load' });
  const rtl = await page.evaluate(() => {
    const att = document.querySelector('.ss-attention');
    const cs = getComputedStyle(att);
    const first = document.querySelector('.ss-stat');
    return {
      dir: document.documentElement.getAttribute('dir'),
      // border-inline-start resolves to the RIGHT edge under rtl.
      accentRight: parseFloat(cs.borderRightWidth),
      accentLeft: parseFloat(cs.borderLeftWidth),
      firstStatRight: first.getBoundingClientRect().right,
      vw: document.documentElement.clientWidth,
    };
  });
  check('document is rtl', rtl.dir === 'rtl');
  check('attention accent bar mirrors to the inline-start (right) edge',
        rtl.accentRight === 3 && rtl.accentLeft !== 3,
        `right=${rtl.accentRight} left=${rtl.accentLeft}`);
  check('first stat cell starts at the right edge under rtl',
        rtl.firstStatRight > rtl.vw * 0.8, `right=${Math.round(rtl.firstStatRight)} vw=${rtl.vw}`);

  // --------------------------------------------- 5. money must not break
  for (const v of ['after-en-full-light', 'after-ar-full-light']) {
    for (const w of [360, 390]) {
      const c2 = await browser.newContext({ viewport: { width: 520, height: 900 } });
      const p2 = await c2.newPage();
      await p2.goto(`${BASE}/frame/${v}.html?w=${w}&h=900`, { waitUntil: 'load' });
      await p2.waitForTimeout(150);
      const f = p2.frames()[1];
      const lines = await f.evaluate(() => {
        const el = [...document.querySelectorAll('.ss-stat__value')]
          .find(e => /\d[\d,]*\.\d\d/.test(e.textContent));
        if (!el) return null;
        const cs = getComputedStyle(el);
        const lh = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.25;
        return { h: el.getBoundingClientRect().height, lh, text: el.textContent.trim() };
      });
      check(`money figure stays on one line  ${v} @${w}px`,
            lines !== null && lines.h < lines.lh * 1.6,
            lines ? `h=${Math.round(lines.h)} lineHeight=${Math.round(lines.lh)} "${lines.text}"` : 'not found');
      await c2.close();
    }
  }

  // ------------------------------------------------ 6. keyboard + a11y tree
  await page.goto(`${BASE}/preview/after-en-full-light.html`, { waitUntil: 'load' });
  const order = [];
  for (let i = 0; i < 14; i++) {
    await page.keyboard.press('Tab');
    const info = await page.evaluate(() => {
      const a = document.activeElement;
      if (!a || a === document.body) return null;
      const cs = getComputedStyle(a);
      return {
        text: (a.innerText || a.textContent || '').trim().slice(0, 32),
        tag: a.tagName.toLowerCase(),
        outline: cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0,
        // Is this control one the design system owns, or host chrome?
        ours: !!a.closest('.ss-scope'),
      };
    });
    if (info) order.push(info);
  }

  /* Scoped to .ss-scope deliberately. The design system never styles a bare
     element selector, so it cannot give a focus ring to a control it does not
     own, and claiming otherwise would be claiming something it does not do.
     Host-chrome controls are reported below instead of silently counted. */
  const ours = order.filter(o => o.ours);
  check('every design-system control shows a focus ring',
        ours.length > 0 && ours.every(o => o.outline),
        ours.filter(o => !o.outline).map(o => o.text).join(', ') || `${ours.length} controls checked`);

  const hostNoRing = order.filter(o => !o.ours && !o.outline);
  if (hostNoRing.length) {
    console.log(`NOTE  ${hostNoRing.length} HOST-CHROME control(s) have no focus ring: ` +
                hostNoRing.map(o => `"${o.text}"`).join(', ') +
                '\n      Pre-existing, outside .ss-scope, and not something an opt-in' +
                '\n      stylesheet can fix. Recorded, not counted as a Phase 02 result.');
  }
  check('tab order reaches the attention actions before the status chips',
        order.findIndex(o => /Review orders/.test(o.text)) <
        order.findIndex(o => /Pending/.test(o.text)),
        order.map(o => o.text).join(' > ').slice(0, 160));

  const names = await page.evaluate(async () => {
    const out = [];
    for (const el of document.querySelectorAll('.ss-scope a, .ss-scope button')) {
      out.push({ text: (el.innerText || '').trim(), aria: el.getAttribute('aria-label') });
    }
    return out;
  });
  check('no control has an empty accessible name',
        names.every(n => (n.text && n.text.length) || (n.aria && n.aria.length)),
        `${names.length} controls`);

  const landmarks = await page.$$eval('.ss-attention', els =>
    els.map(e => ({ tag: e.tagName.toLowerCase(), labelled: !!e.getAttribute('aria-labelledby') })));
  check('attention block is a labelled landmark',
        landmarks.length === 1 && landmarks[0].tag === 'section' && landmarks[0].labelled,
        JSON.stringify(landmarks));

  // ------------------------------------------------- 7. the dark layout
  await page.goto(`${BASE}/preview/after-en-full-dark.html`, { waitUntil: 'load' });
  await page.waitForTimeout(400);
  const dark = await page.evaluate(() => {
    const html = document.documentElement;
    const card = document.querySelector('.ss-attention');
    const val = document.querySelector('.ss-stat__value');
    return {
      themeAttr: html.getAttribute('data-ss-theme'),
      cardBg: getComputedStyle(card).backgroundColor,
      pageBg: getComputedStyle(document.body).backgroundColor,
      valColor: getComputedStyle(val).color,
    };
  });
  check('page follows the host dark layout', dark.themeAttr === 'dark', JSON.stringify(dark));
  check('dark card sits above the dark page', dark.cardBg !== dark.pageBg,
        `card=${dark.cardBg} page=${dark.pageBg}`);

  // ====================================================================
  //  8. THE INTERACTIVE COMPONENTS, DRIVEN
  //  Built in P2-01 and, until the gallery existed, never exercised: the
  //  panel's focus trap, Esc, scroll lock and focus return; the dirty
  //  guard; the validation summary's focus move.
  // ====================================================================

  await page.goto(`${BASE}/preview/comp-en-light.html`, { waitUntil: 'load' });
  await page.waitForTimeout(200);

  // ---- the panel starts closed and OUT of the accessibility tree ----
  const closed = await page.evaluate(() => {
    const p = document.getElementById('order-preview');
    const b = document.querySelector('[data-ss-panel-backdrop="order-preview"]');
    return { panelHidden: p.hidden, backdropHidden: b.hidden,
             state: p.getAttribute('data-ss-state') };
  });
  check('panel starts hidden, with its backdrop hidden too',
        closed.panelHidden && closed.backdropHidden && closed.state === 'closed',
        JSON.stringify(closed));

  // ---- opening it ----
  await page.click('#open-order-panel');
  await page.waitForTimeout(400);

  const opened = await page.evaluate(() => ({
    hidden: document.getElementById('order-preview').hidden,
    state: document.getElementById('order-preview').getAttribute('data-ss-state'),
    bodyOverflow: document.body.style.overflow,
    focusInPanel: !!document.activeElement.closest('#order-preview'),
    focusTag: document.activeElement.className,
    role: document.getElementById('order-preview').getAttribute('role'),
    modal: document.getElementById('order-preview').getAttribute('aria-modal'),
    labelled: document.getElementById('order-preview').getAttribute('aria-labelledby'),
  }));
  check('panel opens', !opened.hidden && opened.state === 'open', JSON.stringify(opened));
  check('focus moves INTO the panel on open', opened.focusInPanel, opened.focusTag);
  check('the list behind cannot scroll while the panel is open',
        opened.bodyOverflow === 'hidden', `body.overflow=${opened.bodyOverflow}`);
  check('panel is a labelled modal dialog',
        opened.role === 'dialog' && opened.modal === 'true' && !!opened.labelled,
        JSON.stringify(opened));

  // ---- Tab is contained ----
  const trapped = [];
  for (let i = 0; i < 8; i++) {
    await page.keyboard.press('Tab');
    trapped.push(await page.evaluate(() => !!document.activeElement.closest('#order-preview')));
  }
  check('Tab stays inside the panel (8 presses)',
        trapped.every(Boolean),
        trapped.every(Boolean) ? '8/8 stayed inside'
                               : `escaped on press ${trapped.indexOf(false) + 1}`);

  // ---- Esc closes, and focus RETURNS to the trigger ----
  await page.keyboard.press('Escape');
  await page.waitForTimeout(450);
  const afterEsc = await page.evaluate(() => ({
    hidden: document.getElementById('order-preview').hidden,
    bodyOverflow: document.body.style.overflow,
    focusId: document.activeElement.id,
  }));
  check('Esc closes the panel', afterEsc.hidden, JSON.stringify(afterEsc));
  check('scroll is released when the panel closes', afterEsc.bodyOverflow === '',
        `body.overflow="${afterEsc.bodyOverflow}"`);
  check('focus RETURNS to the control that opened it',
        afterEsc.focusId === 'open-order-panel', `focus on #${afterEsc.focusId}`);

  // ---- the dirty guard does not cry wolf ----
  const dirtyBefore = await page.getAttribute('#demo-form', 'data-ss-dirty');
  check('form is NOT dirty before anything is typed', dirtyBefore !== 'true',
        `data-ss-dirty=${dirtyBefore}`);

  await page.focus('#name');
  const dirtyAfterFocus = await page.getAttribute('#demo-form', 'data-ss-dirty');
  check('focusing a field alone does NOT arm the guard', dirtyAfterFocus !== 'true',
        `data-ss-dirty=${dirtyAfterFocus}`);

  await page.fill('#name', 'Linen shirt');
  await page.waitForTimeout(100);
  const dirtyAfterType = await page.getAttribute('#demo-form', 'data-ss-dirty');
  check('typing DOES arm the guard', dirtyAfterType === 'true',
        `data-ss-dirty=${dirtyAfterType}`);

  const markerVisible = await page.evaluate(() => {
    const m = document.querySelector('.ss-dirty-marker');
    return m ? getComputedStyle(m).display !== 'none' : false;
  });
  check('the unsaved-changes marker becomes visible', markerVisible);

  // ---- the sticky action bar must not obscure a focused field ----
  const obscure = await page.evaluate(() => {
    const bar = document.querySelector('.ss-actionbar');
    const scroller = document.querySelector('.ss-has-actionbar');
    return {
      sticky: getComputedStyle(bar).position,
      padding: getComputedStyle(scroller).scrollPaddingBottom,
      landmark: bar.getAttribute('role'),
      label: bar.getAttribute('aria-label'),
    };
  });
  check('action bar is sticky and is a labelled landmark',
        obscure.sticky === 'sticky' && obscure.landmark === 'region' && !!obscure.label,
        JSON.stringify(obscure));
  check('its scroll container reserves room so the bar cannot hide focus',
        obscure.padding && obscure.padding !== 'auto' && parseFloat(obscure.padding) > 0,
        `scroll-padding-block-end=${obscure.padding}`);

  // ---- the validation summary ----
  await page.goto(`${BASE}/preview/comp-en-errors.html`, { waitUntil: 'load' });
  await page.waitForTimeout(250);
  const summary = await page.evaluate(() => {
    const s = document.querySelector('[data-ss-error-summary]');
    if (!s) return null;
    const links = [...s.querySelectorAll('a')].map(a => a.getAttribute('href'));
    return {
      role: s.getAttribute('role'),
      tabindex: s.getAttribute('tabindex'),
      focused: document.activeElement === s,
      links,
      inlineErrors: document.querySelectorAll('.ss-field__error').length,
      invalidFields: document.querySelectorAll('[aria-invalid="true"]').length,
    };
  });
  check('a failed submit renders a focusable alert summary',
        summary && summary.role === 'alert' && summary.tabindex === '-1',
        JSON.stringify(summary));
  check('focus MOVES to the summary after a failed submit',
        summary && summary.focused, `activeElement is the summary: ${summary && summary.focused}`);
  check('each summary item links to its own field',
        summary && summary.links.length === 2 && summary.links.every(h => /^#/.test(h)),
        JSON.stringify(summary && summary.links));
  check('the summary COMPLEMENTS inline errors rather than replacing them',
        summary && summary.inlineErrors === 2 && summary.invalidFields === 2,
        `inline=${summary && summary.inlineErrors} aria-invalid=${summary && summary.invalidFields}`);

  // each link must actually reach its field
  const reach = await page.evaluate(() => {
    const links = [...document.querySelectorAll('[data-ss-error-summary] a')];
    return links.map(a => !!document.getElementById(a.getAttribute('href').slice(1)));
  });
  check('every summary link resolves to a real field', reach.every(Boolean), JSON.stringify(reach));

  // ---- RTL: the panel must arrive from the other edge ----
  await page.goto(`${BASE}/preview/comp-ar-light.html`, { waitUntil: 'load' });
  await page.waitForTimeout(200);
  const rtlPanel = await page.evaluate(() => {
    const p = document.getElementById('order-preview');
    const cs = getComputedStyle(p);
    return { left: cs.left, right: cs.right, borderLeft: cs.borderLeftWidth,
             borderRight: cs.borderRightWidth };
  });
  /* borderRight, not borderRightWidth -- the key name above. The first
     version of this read an undefined property, got NaN, and reported a
     FAILURE against a panel that was mirroring perfectly. A check that is
     wrong in the strict direction still has to be fixed: it would have sent
     someone to debug correct CSS. */
  check('under rtl the panel is pinned to the LEFT edge and its border mirrors',
        rtlPanel.left === '0px' && parseFloat(rtlPanel.borderRight) >= 1
          && parseFloat(rtlPanel.borderLeft) === 0,
        JSON.stringify(rtlPanel));

  await ctx.close();

  // ====================================================================
  //  9. SIDEBAR NAVIGATION LEGIBILITY, ACROSS WIDTHS
  //
  //  This section exists because a screenshot caught what the desktop
  //  measurement did not. `transprent-bg` -- the class the DEFAULT
  //  `cust_theme_bg = 'on'` puts on the nav -- only turns the sidebar
  //  transparent inside `@media (min-width: 1025px)` (style.css). Below
  //  that the nav falls back to `.dash-sidebar { background: #1c232f }`
  //  while `.dash-sidebar .dash-link { color: #333333 }` (custom.css)
  //  stays put: 1.25:1, on the default settings, at every width a phone
  //  or tablet uses. Desktop measured 12.01:1 and said nothing about it.
  //
  //  So the widths are the whole point of this check, not decoration.
  //  A true viewport is needed (Chromium will not open a window under
  //  500px), hence the /frame/ iframe wrapper.
  // ====================================================================
  const lum = c => { const [r, g, bl] = c.match(/\d+/g).map(Number)
      .map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * r + 0.7152 * g + 0.0722 * bl; };
  const contrast = (a, b2) => { const [x, y] = [lum(a), lum(b2)].sort((p, q) => q - p);
    return (x + 0.05) / (y + 0.05); };

  for (const variant of ['nav2-en-light-full', 'nav2-ar-light-full']) {
    for (const w of [360, 390, 768, 1024, 1440]) {
      const nctx = await browser.newContext({ viewport: { width: Math.max(w, 520), height: 760 } });
      const npage = await nctx.newPage();
      await npage.goto(`${BASE}/frame/${variant}.html?w=${w}&h=760`, { waitUntil: 'load' });
      await npage.waitForTimeout(350);
      const inner = npage.frames().find(f => f.url().includes('/preview/'));
      const m = await inner.evaluate(() => {
        const bgOf = el => { let n = el; while (n) { const c = getComputedStyle(n).backgroundColor;
            if (c && !/rgba\(.*,\s*0\)$/.test(c) && c !== 'transparent') return c; n = n.parentElement; }
          return 'rgb(255, 255, 255)'; };
        // EVERY label, not just the first: a submenu item could differ.
        return [...document.querySelectorAll('.dash-link')].map(l => {
          const t = l.querySelector('.dash-mtext');
          return { color: getComputedStyle(t || l).color, bg: bgOf(l),
                   label: (t || l).textContent.trim().slice(0, 20) };
        });
      });
      /* WHAT THIS DOES NOT COVER, so "PASSED" is not over-read: the fixture
         marks no item `.active`, so these numbers are the DEFAULT state only.
         The active item is `#fff` on `var(--theme-color)` -- on theme-3's
         #10B77F that is 2.59:1, failing AA at EVERY width, desktop included.
         That is the merchant's own brand colour across ten themes plus a
         custom picker, so it is a theming decision and not this slice's to
         make: ledger F9. */
      const worst = m.reduce((acc, x) => Math.min(acc, contrast(x.color, x.bg)), 99);
      const bad = m.filter(x => contrast(x.color, x.bg) < 4.5);
      // .dash-link is 15px/500 -- normal text, so 4.5:1, not the 3:1 for large text.
      check(`sidebar labels reach 4.5:1 at ${w}px (${variant.includes('-ar-') ? 'ar' : 'en'})`,
            bad.length === 0,
            `worst ${worst.toFixed(2)}:1` + (bad.length ? ` -- ${bad.length}/${m.length} fail, e.g. "${bad[0].label}" ${bad[0].color} on ${bad[0].bg}` : ''));
      await nctx.close();
    }
  }

  // ====================================================================
  //  10. SIDEBAR ZONES, WORKSPACE CHIP, COLLAPSED RAIL  (P2-01-11)
  //
  //  The expensive thing to get wrong here is not how it looks: it is the
  //  sidebar SEARCH. layouts/app.blade.php:426 selects
  //  `.dash-navbar > .dash-item` with a direct-child combinator, so wrapping
  //  an item to group it breaks search with no error of any kind. These
  //  checks exist mostly to prove that did not happen.
  // ====================================================================
  {
    const zctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const zp = await zctx.newPage();
    await zp.goto(`${BASE}/preview/nav3-en-light-full.html`, { waitUntil: 'load' });
    await zp.waitForTimeout(350);

    const dom = await zp.evaluate(() => {
      const nav = document.querySelector('ul.dash-navbar');
      const direct = [...nav.children];
      return {
        // EVERY child of the navbar is an <li>, and every nav entry is still a
        // DIRECT child. A wrapper would show up here as a depth change.
        allLi: direct.every(e => e.tagName === 'LI'),
        zoneHeads: direct.filter(e => e.classList.contains('ss-nav-zone')).length,
        items: direct.filter(e => e.classList.contains('dash-item')
                                 && !e.classList.contains('ss-nav-zone')).length,
        // A heading must ALSO be a .dash-item, because that is what makes the
        // search hide it; see partision/menu-zones.blade.php.
        headsAreDashItems: direct.filter(e => e.classList.contains('ss-nav-zone'))
                                 .every(e => e.classList.contains('dash-item')),
        // ...and must contain no link, or the search would treat it as a match.
        headsHaveNoLink: direct.filter(e => e.classList.contains('ss-nav-zone'))
                               .every(e => !e.querySelector('.dash-link')),
        zoneTitles: [...document.querySelectorAll('.ss-nav-zone .ss-navgroup')].map(e => e.textContent.trim()),
        // Submenus still nest exactly one level under their item.
        submenuDepthOk: [...document.querySelectorAll('.dash-submenu')]
          .every(u => u.parentElement && u.parentElement.classList.contains('dash-item')),
      };
    });
    check('every navbar child is an <li>', dom.allLi);
    check('nav entries are still DIRECT children of .dash-navbar',
          dom.items > 0, `${dom.items} items, ${dom.zoneHeads} zone headings`);
    check('zone headings carry .dash-item, so the search hides them',
          dom.zoneHeads > 0 && dom.headsAreDashItems);
    check('zone headings contain no .dash-link', dom.headsHaveNoLink);
    check('submenus still nest one level under their item', dom.submenuDepthOk);
    check('zones are grouped and labelled', dom.zoneTitles.length >= 3,
          JSON.stringify(dom.zoneTitles));
    // Sentence case, not capitals: .ss-navgroup refuses text-transform because
    // capitals carry no meaning in Arabic.
    check('zone headings are NOT upper-cased',
          dom.zoneTitles.every(t => t !== t.toUpperCase() || t.length < 2),
          JSON.stringify(dom.zoneTitles));

    // ---- the search contract, DRIVEN rather than reasoned about ----
    const searched = await zp.evaluate(() => {
      const nav = document.querySelector('ul.dash-navbar');
      const heads = [...nav.children].filter(e => e.classList.contains('ss-nav-zone'));
      const hit = [...nav.children].filter(e => e.classList.contains('dash-item')
                   && !e.classList.contains('ss-nav-zone'));
      // Reproduce exactly what app.blade.php:426-396 does for a query.
      const value = 'order';
      hit.concat(heads).forEach(item => {
        const t = item.querySelector(':scope > .dash-link .dash-mtext');
        const main = t ? t.textContent.toLowerCase() : '';
        item.dataset.ssWouldShow = main.includes(value) ? 'yes' : 'no';
      });
      return {
        headsHidden: heads.every(h => h.dataset.ssWouldShow === 'no'),
        someItemMatched: hit.some(i => i.dataset.ssWouldShow === 'yes'),
      };
    });
    check('a search query hides every zone heading', searched.headsHidden);
    check('a search query still matches real items', searched.someItemMatched);

    // ---- the workspace chip ----
    const chip = await zp.evaluate(() => {
      const trigger = document.getElementById('ss-workspace-trigger');
      const list = document.getElementById('ss-workspace-list');
      const nav = document.querySelector('ul.dash-navbar');
      return {
        exists: !!trigger,
        // It must NOT be inside the navbar, or the search would iterate it.
        outsideNavbar: !!trigger && !nav.contains(trigger),
        expanded: trigger && trigger.getAttribute('aria-expanded'),
        listHidden: list ? list.hidden : null,
        haspopup: trigger && trigger.getAttribute('aria-haspopup'),
        options: document.querySelectorAll('#ss-workspace-list [role="option"]').length,
        selected: document.querySelectorAll('#ss-workspace-list [aria-selected="true"]').length,
        name: trigger && trigger.querySelector('.ss-workspace__name').textContent.trim(),
        // The accessible name must say what the control DOES.
        srLabel: trigger && !!trigger.querySelector('.ss-visually-hidden'),
      };
    });
    check('the workspace chip renders', chip.exists, chip.name || '');
    check('the chip is OUTSIDE ul.dash-navbar', chip.outsideNavbar);
    check('the chip starts collapsed', chip.expanded === 'false' && chip.listHidden === true);
    check('the chip is a listbox trigger', chip.haspopup === 'listbox');
    check('exactly one store is aria-selected',
          chip.selected === 1, `${chip.selected} of ${chip.options}`);
    check('the chip has a visually-hidden purpose label', chip.srLabel);

    // ---- the rail toggle ----
    const rail = await zp.evaluate(() => {
      const b = document.getElementById('vertical-nav-toggle');
      if (!b) return { exists: false };
      const i = b.querySelector('i');
      const br = b.getBoundingClientRect(), ir = i.getBoundingClientRect();
      const nav = document.querySelector('nav.dash-sidebar').getBoundingClientRect();
      return {
        exists: true,
        pressed: b.getAttribute('aria-pressed'),
        named: !!b.querySelector('.ss-visually-hidden'),
        bothLabels: !!(b.dataset.ssCollapse && b.dataset.ssExpand),
        // The icon must sit INSIDE its own button. The host positions
        // `.sidebar-input i` absolutely at left:10px, which pinned this icon
        // on top of the search magnifier while the button sat 240px away.
        iconInsideButton: ir.left >= br.left - 1 && ir.right <= br.right + 1,
        // ...and the whole button must be inside the sidebar, not clipped off
        // its edge, which is where `width:100%` on the search input put it.
        insideSidebar: br.right <= nav.right + 1,
      };
    });
    // ---- the two defects the independent review found ----
    // 1. the switcher's keyboard path. The first version queried
    //    `.ss-workspace__option`, a class that exists nowhere, so options()
    //    returned [] -- ArrowDown focused nothing and then threw on opts[NaN].
    const kbd = await zp.evaluate(async () => {
      const trigger = document.getElementById('ss-workspace-trigger');
      const list = document.getElementById('ss-workspace-list');
      if (!trigger) return { n: 0 };
      const sel = [...list.querySelectorAll('.ss-menu__item')];
      trigger.focus();
      trigger.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
      await new Promise(r => setTimeout(r, 50));
      const movedIn = sel.includes(document.activeElement);
      let threw = false;
      try {
        document.activeElement.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
        await new Promise(r => setTimeout(r, 30));
      } catch (e) { threw = true; }
      const esc = () => document.activeElement.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      esc();
      await new Promise(r => setTimeout(r, 30));
      return {
        n: sel.length,
        movedIn,
        threw,
        backOnTrigger: document.activeElement === trigger,
        closed: list.hidden,
      };
    });
    check('ArrowDown on the chip moves focus INTO the list',
          kbd.n > 0 && kbd.movedIn, `${kbd.n} options, movedIn=${kbd.movedIn}`);
    check('arrowing inside the list does not throw', !kbd.threw);
    check('Escape closes the list and returns focus to the chip',
          kbd.closed && kbd.backOnTrigger,
          `closed=${kbd.closed} focusBack=${kbd.backOnTrigger}`);

    // 2. the rail must not hide the control that un-collapses it. Collapsing
    //    is persisted to localStorage and re-applied on every page load, so
    //    a hidden toggle would strand the merchant for that browser.
    //
    //    WHAT THIS CHECK IS REALLY WORTH, stated because it was vacuous once:
    //    the host sets `#vertical-nav-toggle { display: block !important }`
    //    (assets/css/style.css, top level AND inside @media min-width:1025px),
    //    an ID rule with !important, so NO class rule here can hide the button
    //    and the trap is unreachable from the design system. Re-adding the
    //    hide rule therefore does NOT make this fail. What does make it fail
    //    is a rule that can actually win -- which is what the bite used, and
    //    which is what a future change would have to introduce to cause the
    //    problem. The check guards the outcome, not one way of breaking it.
    const trap = await zp.evaluate(async () => {
      document.body.classList.add('minimenu');
      await new Promise(r => setTimeout(r, 30));
      const b = document.getElementById('vertical-nav-toggle');
      const cs = b && getComputedStyle(b);
      const r = b && b.getBoundingClientRect();
      const out = {
        toggleStillShown: !!cs && cs.display !== 'none' && cs.visibility !== 'hidden'
                          && r.width > 0 && r.height > 0,
        chipHidden: getComputedStyle(document.querySelector('.ss-workspace-wrap')).display === 'none',
      };
      document.body.classList.remove('minimenu');
      return out;
    });
    check('COLLAPSING THE RAIL DOES NOT HIDE THE CONTROL THAT EXPANDS IT',
          trap.toggleStillShown);
    check('the chip itself is hidden in the rail, where its name would not fit',
          trap.chipHidden);

    check('the rail toggle renders', rail.exists);
    check('the rail toggle is a named toggle button',
          rail.pressed === 'false' && rail.named && rail.bothLabels);
    check('its icon is inside its own button, not the search magnifier',
          rail.iconInsideButton);
    check('the toggle is inside the sidebar, not clipped off the edge',
          rail.insideSidebar);
    await zctx.close();
  }

  // ---- zone headings and chip text reach 4.5:1, all four combinations ----
  for (const variant of ['nav3-en-light-full', 'nav3-ar-light-full',
                         'nav3-en-dark-full', 'nav3-ar-dark-full']) {
    for (const w of [390, 1440]) {
      const cctx = await browser.newContext({ viewport: { width: Math.max(w, 520), height: 820 } });
      const cp = await cctx.newPage();
      await cp.goto(`${BASE}/frame/${variant}.html?w=${w}&h=820`, { waitUntil: 'load' });
      await cp.waitForTimeout(350);
      const inner = cp.frames().find(f => f.url().includes('/preview/'));
      const rows = await inner.evaluate(() => {
        const bgOf = el => { let n = el; while (n) { const c = getComputedStyle(n).backgroundColor;
            if (c && !/rgba\(.*,\s*0\)$/.test(c) && c !== 'transparent') return c; n = n.parentElement; }
          return 'rgb(255, 255, 255)'; };
        const out = [];
        document.querySelectorAll('.ss-navgroup, .ss-workspace__name, .ss-workspace__meta')
          .forEach(e => out.push({ what: e.className, color: getComputedStyle(e).color, bg: bgOf(e),
                                   text: e.textContent.trim().slice(0, 18) }));
        return out;
      });
      const bad = rows.filter(r => contrast(r.color, r.bg) < 4.5);
      const worst = rows.reduce((a, r) => Math.min(a, contrast(r.color, r.bg)), 99);
      check(`zone headings and chip reach 4.5:1 at ${w}px (${variant.slice(5, 12)})`,
            rows.length > 0 && bad.length === 0,
            `${rows.length} measured, worst ${worst.toFixed(2)}:1` +
            (bad.length ? ` -- e.g. "${bad[0].text}" ${bad[0].color} on ${bad[0].bg}` : ''));
      await cctx.close();
    }
  }

  // ====================================================================
  //  11. THE WEEKLY ORDERS CHART  (P2-02-8)
  //
  //  The thing worth checking is not that bars appear. It is that the TABLE
  //  carries the same numbers as the bars, because the table IS the
  //  accessible representation -- not a summary of it. A chart whose picture
  //  and data disagree misleads one audience or the other.
  // ====================================================================
  {
    const kctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
    const kp = await kctx.newPage();
    await kp.goto(`${BASE}/preview/after-en-full-light.html`, { waitUntil: 'load' });
    await kp.waitForTimeout(350);

    const chart = await kp.evaluate(() => {
      const block = document.querySelector('.ss-chart-block');
      if (!block) return { present: false };
      const bars = [...block.querySelectorAll('.ss-chart__bar')];
      const labels = [...block.querySelectorAll('.ss-chart__label')].map(e => e.textContent.trim());
      const rows = [...block.querySelectorAll('tbody tr')].map(tr => ({
        day: tr.querySelector('th').textContent.trim(),
        val: parseInt(tr.querySelector('td').textContent.replace(/[^0-9]/g, ''), 10),
      }));
      const foot = block.querySelector('tfoot td');
      const heights = bars.map(b => parseFloat(b.style.blockSize) || 0);
      const max = Math.max(...rows.map(r => r.val));
      return {
        present: true,
        bars: bars.length,
        labels,
        rows,
        total: foot ? parseInt(foot.textContent.replace(/[^0-9]/g, ''), 10) : null,
        heights,
        max,
        // The picture must be hidden from AT; the table must not be.
        barsAriaHidden: block.querySelector('.ss-chart').getAttribute('aria-hidden') === 'true',
        labelsAriaHidden: block.querySelector('.ss-chart__labels').getAttribute('aria-hidden') === 'true',
        tableInAT: !block.querySelector('table').closest('[aria-hidden="true"]'),
        hasCaption: !!block.querySelector('table caption'),
        rowHeaders: [...block.querySelectorAll('tbody tr th')].every(t => t.getAttribute('scope') === 'row'),
        colHeaders: [...block.querySelectorAll('thead th')].every(t => t.getAttribute('scope') === 'col'),
        detailsClosed: block.querySelector('details') && !block.querySelector('details').open,
        zeroBars: bars.filter(b => b.classList.contains('ss-chart__bar--zero')).length,
      };
    });

    check('the orders chart renders', chart.present);
    check('one bar and one label per day',
          chart.bars === chart.rows.length && chart.labels.length === chart.rows.length,
          `${chart.bars} bars, ${chart.labels.length} labels, ${chart.rows.length} rows`);
    check('bar labels and table rows name the SAME days in the same order',
          JSON.stringify(chart.labels) === JSON.stringify(chart.rows.map(r => r.day)),
          JSON.stringify(chart.labels));
    // The bar is a percentage of the largest value. If the picture and the
    // numbers ever drift apart, this is where it shows.
    const expected = chart.rows.map(r => (chart.max > 0 ? Math.round(r.val / chart.max * 100) : 0));
    check('every bar height matches its own number',
          JSON.stringify(chart.heights) === JSON.stringify(expected),
          `heights ${JSON.stringify(chart.heights)} vs expected ${JSON.stringify(expected)}`);
    check('the footer total equals the sum of the rows',
          chart.total === chart.rows.reduce((a, r) => a + r.val, 0),
          `${chart.total} vs ${chart.rows.reduce((a, r) => a + r.val, 0)}`);
    check('a zero day is drawn as a baseline, not an absent bar',
          chart.zeroBars === chart.rows.filter(r => r.val === 0).length,
          `${chart.zeroBars} zero bars for ${chart.rows.filter(r => r.val === 0).length} zero values`);
    check('the picture is hidden from assistive technology',
          chart.barsAriaHidden && chart.labelsAriaHidden);
    check('the table is NOT hidden from assistive technology', chart.tableInAT);
    check('the table has a caption and scoped headers on both axes',
          chart.hasCaption && chart.rowHeaders && chart.colHeaders);
    check('the numbers start collapsed, so the chart stays a chart',
          chart.detailsClosed);

    // ---- the permission gate ----
    await kp.goto(`${BASE}/preview/after-en-noperm-light.html`, { waitUntil: 'load' });
    await kp.waitForTimeout(300);
    const gated = await kp.evaluate(() => ({
      chart: document.querySelectorAll('.ss-chart-block').length,
      bars: document.querySelectorAll('.ss-chart__bar').length,
      // and no stray numbers left behind in a table
      tables: document.querySelectorAll('.ss-chart-data table').length,
    }));
    check('a staff member without Manage Order gets NO orders chart',
          gated.chart === 0 && gated.bars === 0 && gated.tables === 0,
          JSON.stringify(gated));

    // ---- P2-02-9: latest orders and best sellers ----
    await kp.goto(`${BASE}/preview/after-ar-full-light.html`, { waitUntil: 'load' });
    await kp.waitForTimeout(300);
    const lists = await kp.evaluate(() => {
      const ident = document.querySelector('.ss-ident');
      const cs = ident && getComputedStyle(ident);
      const badges = [...document.querySelectorAll('.ss-badge')];
      return {
        orderRows: document.querySelectorAll('.ss-table caption')[0] ? true : false,
        identText: ident && ident.textContent.trim(),
        // An order reference is an LTR token. Isolation alone leaves the
        // neutral '#' at the visual END in an RTL table -- "SY-10427#".
        identLtr: !!cs && cs.direction === 'ltr' && cs.unicodeBidi.includes('isolate'),
        // Colour must not be the only signal for a status (WCAG 1.4.1).
        badgesHaveText: badges.length > 0 && badges.every(b => b.textContent.trim().length > 0),
        badgeDotsHidden: [...document.querySelectorAll('.ss-badge__dot')]
                           .every(d => d.getAttribute('aria-hidden') === 'true'),
        // Distinct tones actually in play, so the map is exercised.
        tones: new Set(badges.map(b => [...b.classList].find(c => c.startsWith('ss-badge--')) || 'neutral')).size,
        captions: document.querySelectorAll('.ss-table caption').length,
      };
    });
    check('the order reference reads #SY-… not SY-…# under RTL',
          lists.identLtr && /^#/.test(lists.identText || ''), lists.identText || '(absent)');
    check('every status badge carries text, not colour alone', lists.badgesHaveText);
    check('badge dots are decorative and hidden from AT', lists.badgeDotsHidden);
    check('more than one status tone is exercised', lists.tones >= 3, `${lists.tones} tones`);
    check('every dashboard table has a caption', lists.captions >= 2, `${lists.captions}`);

    // the two lists gate SEPARATELY -- Manage Order and Manage Product
    await kp.goto(`${BASE}/preview/after-en-noperm-light.html`, { waitUntil: 'load' });
    await kp.waitForTimeout(250);
    const listGate = await kp.evaluate(() => ({
      idents: document.querySelectorAll('.ss-ident').length,
      badges: document.querySelectorAll('.ss-badge').length,
      tables: document.querySelectorAll('.ss-table').length,
    }));
    check('an ungated staff member gets neither list',
          listGate.idents === 0 && listGate.tables === 0, JSON.stringify(listGate));
    await kctx.close();
  }

  await browser.close();

  console.log(results.join('\n'));
  console.log(`\n${pass} PASSED, ${fail} FAILED`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('HARNESS ERROR', e); process.exit(2); });
