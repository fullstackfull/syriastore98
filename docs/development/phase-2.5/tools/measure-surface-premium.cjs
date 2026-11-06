/**
 * THE PREMIUM CHECKLIST, MEASURED ON A SURFACE THAT HAS NEVER BEEN RENDERED.
 *
 * The generated coverage matrix (build-coverage-matrix.py) reports 14 of 295 surfaces REDESIGNED
 * AND VERIFIED and 271 PENDING, and its own header states why: the Wave-1 table rules reach ~100
 * screens and three of them have been looked at. "A shared component improvement does not equal
 * individual screen acceptance" is the owner's instruction, so a surface earns its row only when
 * the running application was driven and MEASURED, not when a sheet that it inherits changed.
 *
 * render-matrix.cjs already drives the four combinations and asserts the premises (signed in, the
 * direction the layout really emitted, the dark sheet the partial really linked). This adds the
 * readings that decide whether what came back is actually good:
 *
 *   overflow1440 / overflow320   documentElement.scrollWidth - clientWidth. A merchant surface
 *                                that scrolls sideways on a phone is the `horizontal-scroll`
 *                                defect; at 1440 it is a broken layout.
 *   firstRow320                  the document y of the first element that carries real content --
 *                                a tbody row, a card body, a form or a list item, whichever is
 *                                highest, and the tool NAMES which one won. This is the Wave-22
 *                                metric generalised: on a create/edit screen the analogue of
 *                                "first data row" is "first field".
 *   lightSurfaces                dark combinations only: opaque backgrounds above the luminance
 *                                threshold with enough painted area to read as a panel. Same
 *                                instrument as census-light-surfaces-in-dark.cjs.
 *   minContrast                  the lowest text ratio on the page, WITH ALPHA COMPOSITING.
 *
 * TWO THINGS THIS PROJECT HAS ALREADY GOT WRONG, AND HOW THEY ARE HANDLED HERE.
 *
 * 1. COMPUTED STYLES RACE CSS TRANSITIONS. Five drafts of the focus census were wrong for this
 *    single reason -- a value read while a transition is still running is neither the old one nor
 *    the new one. So every reading here is taken after a settle delay, not on domcontentloaded.
 *
 * 2. A CONTRAST RATIO AGAINST `transparent` IS A FICTION. Walking up for the first ancestor with
 *    *any* backgroundColor gives the wrong answer whenever a semi-transparent layer sits between.
 *    bgStack() therefore collects every layer up to the first opaque one and composites them
 *    bottom-up, and the text colour's own alpha is composited over the result.
 *
 * WHERE IT REFUSES TO GUESS. If the chain from a text node to its first opaque background crosses
 * an element with a background-image (a gradient, a sprite), the painted colour is not in the
 * computed style at all. Those nodes are counted in `imageBacked` and EXCLUDED from minContrast
 * rather than reported with a number that was never measured.
 *
 * usage: node docs/development/phase-2.5/tools/measure-surface-premium.cjs --paths /a,/b [--combos all]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPO = '/home/user/syriastore98';
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');
const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };

const BASE = arg('base', 'http://127.0.0.1:8899');
const EMAIL = arg('email', 'merchant@example.test');
const PASSWORD = arg('password', 'password');
const OUT = arg('out', '');
const SETTLE = Number(arg('settle', 1500));
const MIN_AREA = Number(arg('min-area', 2000));
const PATHS = arg('paths', '/dashboard').split(',').map((s) => s.trim()).filter(Boolean);

const ALL_COMBOS = [
  { lang: 'en', dir: 'ltr', theme: 'light' },
  { lang: 'en', dir: 'ltr', theme: 'dark' },
  { lang: 'ar', dir: 'rtl', theme: 'light' },
  { lang: 'ar', dir: 'rtl', theme: 'dark' },
];
const COMBOS = arg('combos', 'all') === 'all' ? ALL_COMBOS : ALL_COMBOS.filter((c) => arg('combos', '').includes(c.theme));

function setState(theme, dir, lang) {
  execFileSync('php', [STATE_TOOL, '1', theme, dir, lang, EMAIL], {
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

const PAGE_PROBE = (minArea) => {
  const parse = (s) => {
    const q = String(s || '').match(/[\d.]+/g);
    if (!q || q.length < 3) { return null; }
    return { r: +q[0], g: +q[1], b: +q[2], a: q.length > 3 ? +q[3] : 1 };
  };
  const over = (top, bottom) => ({
    r: top.r * top.a + bottom.r * (1 - top.a),
    g: top.g * top.a + bottom.g * (1 - top.a),
    b: top.b * top.a + bottom.b * (1 - top.a),
    a: 1,
  });
  const lin = (x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); };
  const L = (c) => 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b);
  const ratio = (a, b) => (Math.max(L(a), L(b)) + 0.05) / (Math.min(L(a), L(b)) + 0.05);

  // Every layer up to the first OPAQUE one, composited bottom-up. Reports whether the chain
  // crossed a background-image, because then the painted colour is not in the computed style.
  const bgStack = (el) => {
    const layers = [];
    let crossedImage = false;
    let n = el;
    while (n && n.nodeType === 1) {
      const cs = getComputedStyle(n);
      if (cs.backgroundImage && cs.backgroundImage !== 'none') { crossedImage = true; }
      const c = parse(cs.backgroundColor);
      if (c && c.a > 0) { layers.push(c); if (c.a >= 0.999) { break; } }
      n = n.parentElement;
    }
    if (!layers.length || layers[layers.length - 1].a < 0.999) {
      layers.push({ r: 255, g: 255, b: 255, a: 1 });
    }
    let out = layers.pop();
    while (layers.length) { out = over(layers.pop(), out); }
    return { colour: out, crossedImage };
  };

  const de = document.documentElement;
  const out = {
    overflow: de.scrollWidth - de.clientWidth,
    viewport: de.clientWidth,
    lightSurfaces: [],
    imageBacked: 0,
    textNodes: 0,
    minContrast: null,
    minContrastAt: null,
    firstRow: null,
    firstRowWhat: null,
  };

  // ---- light surfaces (meaningful only in the dark theme; the caller decides)
  for (const el of document.querySelectorAll('*')) {
    const cs = getComputedStyle(el);
    const c = parse(cs.backgroundColor);
    if (!c || c.a < 0.999) { continue; }
    // 0.72 IS NOT A ROUND NUMBER AND MUST NOT BE LOOSENED. It is the cut
    // census-light-surfaces-in-dark.cjs settled on in P2.5-WAVE-31, chosen to exclude the brand
    // primary button, which measures 0.647 and is SUPPOSED to be light on the dark page -- that
    // is what a primary action looks like. A first draft of this tool used 0.5 and reported 22
    // such buttons across 13 screens as dark-mode defects, contradicting Wave 31's measured zero.
    // The buttons were right and the threshold was wrong.
    const lum = (0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b) / 255;
    if (lum < 0.72) { continue; }
    const q = el.getBoundingClientRect();
    if (q.width * q.height < minArea) { continue; }
    out.lightSurfaces.push({
      sel: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string'
        ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : ''),
      lum: +lum.toFixed(3),
      area: Math.round(q.width * q.height),
    });
    if (out.lightSurfaces.length >= 12) { break; }
  }

  // A SCREEN-READER-ONLY NODE HAS NO CONTRAST. `clip`/`clip-path` do not shrink the box, so a
  // `width < 2` test does NOT catch `.ss-visually-hidden` -- its rect is whatever the text needs.
  // A first draft of this tool therefore reported `span.ss-visually-hidden "Previous"` at 1.33:1
  // as the worst contrast on THIRTEEN screens. It is the pagination's assistive label, deliberately
  // unpainted, and measuring it says nothing about what a sighted merchant sees.
  const isVisuallyHidden = (el) => {
    let n = el;
    while (n && n.nodeType === 1 && n !== document.body) {
      const cs = getComputedStyle(n);
      if (/inset\(50%\)/.test(cs.clipPath || '')) { return true; }
      if (/rect\(0px(,| ) *0px/.test(cs.clip || '')) { return true; }
      const q = n.getBoundingClientRect();
      if (cs.position === 'absolute' && q.width <= 1 && q.height <= 1) { return true; }
      n = n.parentElement;
    }
    return false;
  };

  // ---- lowest text contrast, alpha-composited
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  let t;
  while ((t = walker.nextNode())) {
    const s = (t.nodeValue || '').trim();
    if (s.length < 2) { continue; }
    const el = t.parentElement;
    if (!el) { continue; }
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none' || +cs.opacity === 0) { continue; }
    const q = el.getBoundingClientRect();
    if (q.width < 2 || q.height < 2) { continue; }
    if (isVisuallyHidden(el)) { continue; }
    const fg = parse(cs.color);
    if (!fg) { continue; }
    const { colour: bg, crossedImage } = bgStack(el);
    if (crossedImage) { out.imageBacked++; continue; }
    const ink = fg.a >= 0.999 ? fg : over(fg, bg);
    const r = ratio(ink, bg);
    out.textNodes++;
    if (out.minContrast === null || r < out.minContrast) {
      out.minContrast = r;
      out.minContrastAt = (el.tagName.toLowerCase()
        + (el.className && typeof el.className === 'string'
          ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : ''))
        + ' "' + s.slice(0, 28) + '"';
    }
  }
  if (out.minContrast !== null) { out.minContrast = +out.minContrast.toFixed(2); }

  // ---- the first element that carries real content, and WHICH kind won
  const CANDIDATES = [
    ['tbody tr', 'table row'],
    ['.ss-card__body', 'card body'],
    ['.card-body', 'card body'],
    ['form', 'form'],
    ['.list-group-item', 'list item'],
  ];
  const main = document.querySelector('.dash-content') || document.body;
  for (const [sel, what] of CANDIDATES) {
    for (const el of main.querySelectorAll(sel)) {
      const q = el.getBoundingClientRect();
      if (q.width < 20 || q.height < 10) { continue; }
      const y = Math.round(q.top + window.scrollY);
      if (out.firstRow === null || y < out.firstRow) { out.firstRow = y; out.firstRowWhat = what; }
      break;
    }
  }
  return out;
};

(async () => {
  const browser = await chromium.launch();
  const rows = [];

  for (const combo of COMBOS) {
    setState(combo.theme, combo.dir, combo.lang);
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') { errors.push(m.text().slice(0, 120)); } });
    page.on('pageerror', (e) => errors.push('pageerror: ' + String(e).slice(0, 120)));

    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"], input[type="submit"]')]);
    const signedIn = !/\/login\b/.test(page.url());

    for (const p of PATHS) {
      errors.length = 0;
      const row = { path: p, ...combo, signedIn };
      const resp = await page.goto(BASE + p, { waitUntil: 'domcontentloaded' }).catch(() => null);
      row.status = resp ? resp.status() : 0;
      if (row.status !== 200) { rows.push(row); continue; }
      await page.waitForTimeout(SETTLE);

      const premise = await page.evaluate(() => ({
        dir: document.documentElement.getAttribute('dir'),
        sheet: ((document.getElementById('main-style-link') || {}).href || '').split('/').pop(),
      }));
      row.docDir = premise.dir;
      row.sheet = premise.sheet;
      // A modal endpoint answers 200 with a FRAGMENT: no dir, no body classes, no linked sheet.
      // Calling that a verified page would be the lie the matrix exists to prevent.
      row.fragment = !premise.dir && !premise.sheet;
      if (row.fragment) { rows.push(row); continue; }
      if (!signedIn) { row.premiseFail = 'not signed in'; }
      else if ((premise.dir === 'rtl') !== (combo.dir === 'rtl')) { row.premiseFail = `dir=${premise.dir} wanted ${combo.dir}`; }
      else if (/style-dark\.css/.test(premise.sheet || '') !== (combo.theme === 'dark')) { row.premiseFail = `sheet=${premise.sheet} wanted ${combo.theme}`; }
      if (row.premiseFail) { rows.push(row); continue; }

      const at1440 = await page.evaluate(PAGE_PROBE, MIN_AREA);
      row.overflow1440 = at1440.overflow;
      row.minContrast = at1440.minContrast;
      row.minContrastAt = at1440.minContrastAt;
      row.textNodes = at1440.textNodes;
      row.imageBacked = at1440.imageBacked;
      row.lightSurfaces = combo.theme === 'dark' ? at1440.lightSurfaces : [];

      await page.setViewportSize({ width: 320, height: 568 });
      await page.waitForTimeout(600);
      const at320 = await page.evaluate(PAGE_PROBE, MIN_AREA);
      row.overflow320 = at320.overflow;
      row.firstRow320 = at320.firstRow;
      row.firstRowWhat = at320.firstRowWhat;
      await page.setViewportSize({ width: 1440, height: 900 });

      row.errors = errors.slice(0, 2);
      rows.push(row);
    }
    await ctx.close();
  }
  await browser.close();

  if (OUT) { fs.writeFileSync(OUT, JSON.stringify(rows, null, 1)); }

  let defects = 0;
  console.log(`\n### premium checklist  ${PATHS.length} paths x ${COMBOS.length} combos\n`);
  console.log('  path                            combo         ovf1440 ovf320 first320          minC   light err');
  for (const r of rows) {
    const tag = `${r.lang}/${r.dir}/${r.theme}`;
    if (r.status !== 200) { console.log(`  ${r.path.padEnd(31)} ${tag.padEnd(13)} HTTP ${r.status}`); continue; }
    if (r.fragment) { console.log(`  ${r.path.padEnd(31)} ${tag.padEnd(13)} FRAGMENT (modal endpoint -- must be click-driven)`); continue; }
    if (r.premiseFail) { console.log(`  ${r.path.padEnd(31)} ${tag.padEnd(13)} PREMISE-FAIL ${r.premiseFail}`); continue; }
    const bad = r.overflow1440 > 0 || r.overflow320 > 0 || (r.minContrast !== null && r.minContrast < 4.5) || r.lightSurfaces.length > 0;
    if (bad) { defects++; }
    console.log(`  ${r.path.padEnd(31)} ${tag.padEnd(13)} `
      + `${String(r.overflow1440).padEnd(7)} ${String(r.overflow320).padEnd(6)} `
      + `${String(r.firstRow320 === null ? '-' : r.firstRow320 + 'px ' + r.firstRowWhat).padEnd(17)} `
      + `${String(r.minContrast === null ? '-' : r.minContrast).padEnd(6)} `
      + `${String(r.lightSurfaces.length).padEnd(5)} ${r.errors && r.errors.length ? 'Y' : ''}${bad ? '  <-- DEFECT' : ''}`);
  }
  console.log(`\n  surface/combo readings with at least one defect: ${defects}`);
  process.exit(0);
})();
