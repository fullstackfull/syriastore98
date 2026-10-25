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

  await browser.close();

  console.log(results.join('\n'));
  console.log(`\n${pass} PASSED, ${fail} FAILED`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('HARNESS ERROR', e); process.exit(2); });
