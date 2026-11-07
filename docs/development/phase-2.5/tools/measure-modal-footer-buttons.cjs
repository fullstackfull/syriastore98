/**
 * P2.5-WAVE-17a — measure every button in an AJAX modal's footer, on the real page.
 *
 * WHY THIS SURFACE NEEDS ITS OWN MEASUREMENT
 * =================================================================================================
 * Two earlier slices are each correct in their own scope and collide here.
 *
 *   P2.5-A-01/A-02 decided the modal STAYS WHITE in dark mode. That was not an oversight: the
 *   vendored style-dark.css keeps `--bs-modal-bg: #ffffff` (:6234) and the slice chose to make the
 *   ink legible on that white rather than re-theme a surface 70 modules share.
 *   DarkModalSurfaceContrastTest pins it (`MODAL_BG = [255,255,255]`).
 *
 *   P2.5-DESIGN-E gave `.btn-secondary` a dark-page ink -- `[data-ss-theme="dark"] .btn-secondary
 *   { color: var(--ss-fg-muted) }`, store-syria-ds.css:1821 -- measured against the dark PAGE,
 *   where the host's 25%-grey chip composites dark and muted grey clears it.
 *
 * The attribute that scopes the second rule sits on <html>, so it reaches INSIDE the white modal,
 * where the same chip composites to rgb(218,220,222) and `--ss-fg-muted` (#a7abb2) lands at
 * 1.68:1 -- against 12.28:1 for the very same button on the light page. The dark theme makes this
 * control WORSE than no theming at all, which is the same shape as the WAVE-16c timestamp.
 *
 * A-02's own docblock already stated the rule this breaks: "the surface has to be named, not the
 * element", and "match the token layer to the SURFACE, not to the component".
 *
 * WHAT IS MEASURED: each footer button's own computed colour against its EFFECTIVE composited
 * background, read off the live page. The chip is a 25% tint, so the stylesheet hex is NOT the
 * rendered colour and arithmetic on the sheet alone would answer the wrong question.
 *
 * NO `--dir` FLAG, for the reason given in measure-timeline-pills.cjs: a single-direction run is
 * how a half-fix shipped in WAVE-16b.
 *
 * usage:  OUT=/tmp/modal-btns node docs/development/phase-2.5/tools/measure-modal-footer-buttons.cjs
 *
 * Asserted in the suite by Tests\Unit\Ui\ModalFooterButtonContrastTest.
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPO = '/home/user/syriastore98';
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const OUT = process.env.OUT || '/tmp/modal-footer-buttons';
const EMAIL = process.env.EMAIL || 'merchant@example.test';
const PASSWORD = process.env.PASSWORD || 'password';
const STORE = process.env.STORE || '1';
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

const FLOOR = 4.5; // the labels are 14px regular -- large-text relief does not apply

const COMBOS = [
  { lang: 'en', dir: 'ltr', theme: 'light' },
  { lang: 'en', dir: 'ltr', theme: 'dark' },
  { lang: 'ar', dir: 'rtl', theme: 'light' },
  { lang: 'ar', dir: 'rtl', theme: 'dark' },
];

/** Reachable modals whose footers differ, so the result is not one modal generalised to 68. */
const MODALS = [
  { route: '/tag', needle: '/tag/create', label: 'tag create' },
  { route: '/faqs', needle: '/faqs/create', label: 'faq create' },
  { route: '/testimonial', needle: '/testimonial/create', label: 'testimonial create' },
  { route: '/product-label', needle: '/product-label/create', label: 'product-label create' },
  { route: '/coupon', needle: '/coupon/create', label: 'coupon create' },
];

function lum([r, g, b]) {
  const c = (v) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4); };
  return 0.2126 * c(r) + 0.7152 * c(g) + 0.0722 * c(b);
}
function ratio(a, b) { const l1 = lum(a), l2 = lum(b); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); }
const parse = (v) => { const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(v || ''); return m ? [+m[1], +m[2], +m[3]] : null; };

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

const READ = `
  // The chip is a 25% tint, so compositing matters: walk up past anything translucent and blend.
  const blend = (fg, a, bg) => fg.map((v, i) => Math.round(a * v + (1 - a) * bg[i]));
  const rgba = (s) => { const m = /rgba?\\((\\d+),\\s*(\\d+),\\s*(\\d+)(?:,\\s*([\\d.]+))?/.exec(s || '');
                        return m ? { c: [+m[1], +m[2], +m[3]], a: m[4] === undefined ? 1 : parseFloat(m[4]) } : null; };
  const effective = (el) => {
    const layers = [];
    let n = el;
    while (n) { const p = rgba(getComputedStyle(n).backgroundColor); if (p && p.a > 0.001) layers.push(p); n = n.parentElement; }
    if (!layers.length) return [255, 255, 255];
    let out = layers[layers.length - 1].c;
    for (let i = layers.length - 2; i >= 0; i--) out = blend(layers[i].c, layers[i].a, out);
    return out;
  };
  const foot = document.querySelector('#commanModel .modal-footer');
  if (!foot) return { noFooter: true };
  const out = [];
  foot.querySelectorAll('button, input[type="submit"], input[type="button"], a.btn, a[class*="ss-btn"]').forEach((el) => {
    const cs = getComputedStyle(el);
    const variant = (String(el.className).match(/btn-(secondary|primary|danger|success|warning|info|dark|light)\\b/) || [])[0]
                 || (String(el.className).match(/ss-btn--[a-z]+/) || [])[0] || 'other';
    out.push({
      variant,
      label: (el.value || el.textContent || '').trim().slice(0, 20),
      fg: cs.color, bgRaw: cs.backgroundColor, bg: effective(el),
      size: parseFloat(cs.fontSize), weight: cs.fontWeight,
      opacity: cs.opacity, disabled: el.disabled === true || el.hasAttribute('disabled'),
    });
  });
  return { buttons: out, modalBg: effective(document.querySelector('#commanModel .modal-content')) };
`;

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  const rows = [];
  let premiseFailures = 0;

  for (const combo of COMBOS) {
    setState(combo.theme, combo.dir, combo.lang);
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();

    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"], input[type="submit"]')]);
    const signedIn = !/\/login\b/.test(page.url());

    const key = `${combo.lang}_${combo.dir}_${combo.theme}`;

    for (const m of MODALS) {
      const premise = [];
      if (!signedIn) premise.push('not signed in');

      const r = await page.goto(BASE + m.route, { waitUntil: 'domcontentloaded' }).catch(() => null);
      if (!r || r.status() !== 200) premise.push('route status=' + (r ? r.status() : 0));
      if (page.url().replace(BASE, '') !== m.route) premise.push('redirected to ' + page.url().replace(BASE, ''));
      await page.waitForTimeout(500);

      const st0 = await page.evaluate(() => ({
        dir: document.documentElement.getAttribute('dir'),
        sheet: ((document.getElementById('main-style-link') || {}).href || '').split('/').pop(),
      })).catch(() => ({}));
      if ((st0.dir === 'rtl') !== (combo.dir === 'rtl')) premise.push('dir=' + st0.dir);
      if (/dark/.test(st0.sheet || '') !== (combo.theme === 'dark')) premise.push('sheet=' + st0.sheet);

      // THE FIRST MATCH IS NOT ALWAYS THE RIGHT ONE. The merchant shell carries a quick-create
      // dropdown whose items are `[data-ajax-popup]` anchors for taxes, category and coupon, and
      // they sit EARLIER in the DOM than the screen's own primary button. On /coupon the needle
      // matched that hidden menu item, Playwright waited 30s for it to become clickable, and the
      // modal never opened -- a whole surface dropped out of the run. Take the first VISIBLE one.
      const candidates = await page.$$(`[data-ajax-popup="true"][data-url*="${m.needle}"]`);
      let trigger = null;
      for (const c of candidates) {
        if (await c.isVisible().catch(() => false)) { trigger = c; break; }
      }
      if (!trigger && candidates.length) premise.push(`${candidates.length} trigger(s) matched, none visible`);
      if (!trigger) {
        premise.push('trigger not found');
        premiseFailures++;
        rows.push({ combo: key, modal: m.label, premise, buttons: [] });
        continue;
      }
      await trigger.click().catch(() => {});
      await page.waitForSelector('#commanModel.show', { timeout: 6000 }).catch(() => premise.push('modal did not open'));
      await page.waitForTimeout(800);

      const st = await page.evaluate('(() => {' + READ + '})()');
      if (st.noFooter) premise.push('modal has no footer');
      if (!st.buttons || !st.buttons.length) premise.push('footer has no buttons');
      if (premise.length) premiseFailures++;

      // A PREMISE FAILURE MUST LEAVE A ROW. The first version only pushed rows per BUTTON, so a
      // modal that opened with no footer incremented the counter, printed a console line -- and
      // vanished from modal-footer-buttons.json entirely. The console scrolls away; the JSON is
      // the artifact somebody reads later, and an artifact that omits its own failures reads as
      // full coverage. Measured: the coupon modal was missing from a whole run this way.
      if (premise.length) {
        rows.push({ combo: key, modal: m.label, premise, buttons: 0 });
      }

      await page.screenshot({ path: path.join(OUT, `${m.label.replace(/\W+/g, '-')}__${key}.png`) }).catch(() => {});

      for (const bn of (st.buttons || [])) {
        const fg = parse(bn.fg);
        const cr = fg && bn.bg ? ratio(fg, bn.bg) : 0;
        rows.push({
          combo: key, modal: m.label, variant: bn.variant, label: bn.label,
          fg: bn.fg, bg: `rgb(${bn.bg.join(', ')})`, bgRaw: bn.bgRaw,
          size: bn.size, weight: bn.weight, disabled: bn.disabled,
          ratio: +cr.toFixed(2), pass: cr >= FLOOR, premise,
        });
      }

      await page.click('#commanModel .btn-close, #commanModel [data-bs-dismiss="modal"]').catch(() => {});
      await page.waitForTimeout(250);
    }

    await ctx.close();
  }

  await browser.close();
  fs.writeFileSync(path.join(OUT, 'modal-footer-buttons.json'), JSON.stringify(rows, null, 1));

  let fails = 0, measured = 0;
  console.log('\ncombo            modal                 variant         label        fg / composited bg              ratio  AA');
  console.log('-'.repeat(124));
  for (const row of rows) {
    if (!row.variant) {
      console.log(`${row.combo.padEnd(16)} ${row.modal.padEnd(21)} PREMISE-FAIL: ${row.premise.join('; ')}`);
      continue;
    }
    measured++;
    if (!row.pass) fails++;
    const pair = `${row.fg} / ${row.bg}`;
    console.log(`${row.combo.padEnd(16)} ${row.modal.padEnd(21)} ${row.variant.padEnd(15)} ${row.label.padEnd(12)} ${pair.padEnd(31)} ${String(row.ratio).padEnd(6)} ${row.pass ? 'pass' : 'FAIL'}`);
  }
  console.log(`\n${measured} buttons measured across ${COMBOS.length} combinations x ${MODALS.length} modals, ${fails} below ${FLOOR}:1.`);
  if (premiseFailures) console.log(`PREMISE FAILED ${premiseFailures} time(s) -- those readings are NOT evidence.`);
  console.log(`-> ${OUT}`);
  process.exit(fails || premiseFailures ? 1 : 0);
})();
