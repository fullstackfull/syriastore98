/**
 * P2.5-PKG-A-10 — THE DASHBOARD'S COMPONENTS, DRIVEN AS COMPONENTS.
 *
 * The same principle as verify-shell-components.cjs, applied to the rows the coverage matrix keeps
 * for the dashboard's own widgets rather than for the page: 'Dashboard overview region (KPI
 * surface)', 'x-ss.stat / x-ss.stats' and 'x-ss.attention'. Each is inside the dashboard capture
 * already; "in frame" is not "verified", so each is targeted, cropped on its own in all four
 * combinations, and measured.
 *
 * IT REPORTS WHAT IS ABSENT RATHER THAN SKIPPING IT. `x-ss.progress` and `x-ss.setup-checklist`
 * render ZERO times on this page -- measured, not assumed -- so this tool says so and their rows
 * stay PENDING. A harness that silently measures nothing and reports no failure is the shape of
 * mistake this phase keeps finding; see M-PKG-A-09c, where "no failed request" was trivially true
 * of a drive that made none.
 *
 * THE MEASUREMENT RULES ARE THE ONES THIS PHASE PAID FOR:
 *   - descend to the elements that OWN text nodes; a wrapper's inherited colour is not the text's
 *     (three false readings, the worst of them a footer reported at 1.20:1 that is really 6.14:1)
 *   - composite every background layer up to the first opaque one, and the text's own alpha over it
 *   - `matches() || querySelector()`, never querySelector alone -- `.ss-workspace` IS the button,
 *     and searching inside it for one reported NO TRIGGER
 *   - assert the premise: the combination asked for is the combination that rendered
 *   - settle before reading, because a value read mid-transition is neither the old nor the new one
 *
 * usage: node docs/development/phase-2.5/tools/verify-dashboard-components.cjs [--out DIR]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPO = '/home/user/syriastore98';
const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const BASE = arg('base', 'http://127.0.0.1:8899');
const OUT = arg('out', path.join(REPO, 'docs/development/phase-2.5/evidence/pkg-a-10/after'));
const SETTLE = Number(arg('settle', 1600));
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

/** Scoped to the child process only. serve-dev.sh records, with dates, that exporting these and
 *  running the suite in the same shell destroyed the dev fixture twice. */
const DEV_ENV = Object.assign({}, process.env, {
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: path.join(REPO, 'database/p2dev.sqlite'),
  CACHE_STORE: 'file',
  SESSION_DRIVER: 'file',
});

const COMBOS = [
  { lang: 'en', dir: 'ltr', theme: 'light' },
  { lang: 'en', dir: 'ltr', theme: 'dark' },
  { lang: 'ar', dir: 'rtl', theme: 'light' },
  { lang: 'ar', dir: 'rtl', theme: 'dark' },
];

let checks = 0, fails = 0;
const notes = [];
function ok(cond, label) {
  checks++;
  if (!cond) { fails++; console.log('  FAIL  ' + label); } else { console.log('  ok    ' + label); }
}
function note(s) { notes.push(s); console.log('  ..    ' + s); }

const INSTRUMENT = () => {
  const P = (c) => { const m = /^rgba?\(([^)]+)\)/.exec(c || ''); if (!m) return null;
    const n = m[1].split(',').map((s) => parseFloat(s.trim()));
    return { r: n[0], g: n[1], b: n[2], a: n.length > 3 ? n[3] : 1 }; };
  const over = (f, b) => ({ r: f.r * f.a + b.r * (1 - f.a), g: f.g * f.a + b.g * (1 - f.a),
                            b: f.b * f.a + b.b * (1 - f.a), a: 1 });
  const lum = (c) => { const ch = [c.r, c.g, c.b].map((v) => { v /= 255;
    return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * ch[0] + 0.7152 * ch[1] + 0.0722 * ch[2]; };
  const ratio = (a, b) => { const L1 = lum(a), L2 = lum(b);
    return (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05); };

  function bgStack(el) {
    const layers = []; let n = el, imageBacked = false;
    while (n && n.nodeType === 1) {
      const cs = getComputedStyle(n);
      if (cs.backgroundImage && cs.backgroundImage !== 'none') imageBacked = true;
      const c = P(cs.backgroundColor);
      if (c && c.a > 0) { layers.push(c); if (c.a >= 1) break; }
      n = n.parentElement;
    }
    if (!layers.length) return { colour: { r: 255, g: 255, b: 255, a: 1 }, imageBacked };
    let out = layers[layers.length - 1];
    if (out.a < 1) out = over(out, { r: 255, g: 255, b: 255, a: 1 });
    for (let i = layers.length - 2; i >= 0; i--) out = over(layers[i], out);
    return { colour: out, imageBacked };
  }

  function textLeaves(root) {
    const out = [];
    const walk = (el) => {
      const own = [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim().length);
      if (own) out.push(el);
      for (const c of el.children) walk(c);
    };
    if (root) walk(root);
    return out;
  }

  function measureText(el) {
    const cs = getComputedStyle(el);
    const fg = P(cs.color); if (!fg) return null;
    const { colour: bg, imageBacked } = bgStack(el);
    const composited = fg.a < 1 ? over(fg, bg) : fg;
    const r = el.getBoundingClientRect();
    if (!r.width || !r.height || cs.visibility === 'hidden' || cs.display === 'none') return null;
    /* VISUALLY HIDDEN TEXT HAS NO CONTRAST REQUIREMENT, AND MEASURING IT PRODUCES A FALSE
       DEFECT. The chart's `<caption class="ss-visually-hidden">` is clipped to 1x1 and exists
       only for the table's accessible name; it measured 3.03:1 in dark and failed a check about
       something no sighted reader can see. A box of 1px or less in either dimension is the
       standard clip idiom, so it is EXCLUDED and counted rather than reported. Screen readers
       do not read colour. */
    if (r.width <= 1 || r.height <= 1) return { hidden: true };
    return {
      text: el.textContent.trim().replace(/\s+/g, ' ').slice(0, 36),
      tag: el.tagName.toLowerCase() + (typeof el.className === 'string' && el.className.trim()
        ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : ''),
      px: parseFloat(cs.fontSize), weight: cs.fontWeight,
      ratio: Math.round(ratio(composited, bg) * 100) / 100,
      imageBacked,
    };
  }

  const out = {
    dir: document.documentElement.getAttribute('dir'),
    theme: document.documentElement.getAttribute('data-ss-theme'),
    admin: document.body.hasAttribute('data-ss-admin'),
    counts: {},
    blocks: {},
  };

  for (const [name, sel] of [
    ['kpi', '.ss-stats, .ss-stat'],          // the KPI surface, however it is composed
    ['stat', '.ss-stat'],
    ['attention', '.ss-attention'],
    ['progress', '.ss-progress'],
    ['checklist', '.ss-setup'],
    ['chart', '.ss-chart-block'],
  ]) {
    const els = [...document.querySelectorAll(sel)];
    out.counts[name] = els.length;
    if (els.length) out.blocks[name] = textLeaves(els[0]).map(measureText).filter(Boolean);
  }

  // the stat figure must outrank its own label, or the surface reads as a list of words
  const stat = document.querySelector('.ss-stat');
  if (stat) {
    const leaves = textLeaves(stat).map((el) => ({
      text: el.textContent.trim().replace(/\s+/g, ' ').slice(0, 24),
      px: parseFloat(getComputedStyle(el).fontSize),
      weight: Number(getComputedStyle(el).fontWeight),
    }));
    out.statScale = leaves.sort((a, b) => b.px - a.px);
  }

  out.chartLabels = [...document.querySelectorAll('.ss-chart__label')].map((e) => e.textContent.trim());
  out.overflow = document.documentElement.scrollWidth - document.documentElement.clientWidth;
  return out;
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', 'merchant@example.test');
  await page.fill('input[name="password"]', 'password');
  await Promise.all([page.waitForLoadState('domcontentloaded'),
                     page.click('button[type="submit"], input[type="submit"]')]);

  for (const combo of COMBOS) {
    const tag = `${combo.lang}_${combo.dir}_${combo.theme}`;
    console.log(`\n=== ${combo.lang}/${combo.dir} ${combo.theme} ===`);
    const stateOut = execFileSync('php',
      [STATE_TOOL, '1', combo.theme, combo.dir, combo.lang, 'merchant@example.test'],
      { cwd: path.dirname(STATE_TOOL), encoding: 'utf8', env: DEV_ENV });
    if (!/\S/.test(stateOut)) throw new Error('state tool printed nothing -- it did not run');

    await page.goto(BASE + '/dashboard', { waitUntil: 'load' });
    await page.waitForTimeout(SETTLE);

    const m = await page.evaluate(INSTRUMENT);

    ok(m.dir === combo.dir, `${tag} premise: dir is ${combo.dir} (got ${m.dir})`);
    ok(combo.theme === 'dark' ? m.theme === 'dark' : m.theme === null,
       `${tag} premise: theme attribute matches (${m.theme})`);
    ok(m.admin === true, `${tag} premise: data-ss-admin present`);
    ok(m.overflow <= 0, `${tag} no horizontal overflow at 1440 (${m.overflow}px)`);

    // WHAT RENDERS, STATED AS A NUMBER. A row cannot be claimed from a component that is absent.
    note(`${tag} rendered: ` + Object.entries(m.counts).map(([k, v]) => `${k}=${v}`).join(' '));
    ok(m.counts.stat > 0, `${tag} the KPI surface really rendered (${m.counts.stat} stats)`);
    ok(m.counts.attention > 0, `${tag} the attention panel really rendered (${m.counts.attention})`);
    if (!m.counts.progress) note(`${tag} x-ss.progress renders ZERO times here -- its row stays PENDING`);
    if (!m.counts.checklist) note(`${tag} x-ss.setup-checklist renders ZERO times here -- its row stays PENDING`);

    for (const [name, blk] of Object.entries(m.blocks)) {
      const visuallyHidden = blk.filter((t) => t.hidden).length;
      const measurable = blk.filter((t) => !t.hidden && !t.imageBacked);
      const skipped = blk.length - measurable.length - visuallyHidden;
      const worst = measurable.reduce((a, t) => (a && a.ratio <= t.ratio ? a : t), null);
      if (!worst) { note(`${tag} ${name}: no measurable text node`); continue; }
      const large = worst.px >= 24 || (worst.px >= 18.66 && Number(worst.weight) >= 700);
      const floor = large ? 3 : 4.5;
      ok(worst.ratio >= floor,
         `${tag} ${name} worst text ${worst.ratio}:1 >= ${floor} on ${worst.tag} "${worst.text}" `
         + `(${worst.px}px/${worst.weight})` + (skipped ? ` [${skipped} image-backed excluded]` : '')
         + (visuallyHidden ? ` [${visuallyHidden} visually-hidden excluded]` : ''));
    }

    // THE AXIS SPEAKS THE PAGE'S LANGUAGE.
    // `date('d-M')` is locale-blind, so the Arabic dashboard drew `31-Oct` beneath its bars while
    // its own caption read `الطلبات يومياً`. The second site LOOKED handled -- `__(date('d-M'))` --
    // but ar.json holds no DD-Mon key, so the translator returned the key unchanged and that call
    // had never translated anything.
    const ARABIC = /[\u0600-\u06FF]/;
    ok(m.chartLabels.length > 0, `${tag} the chart axis has labels to read (${m.chartLabels.length})`);
    if (m.chartLabels.length) {
      const sample = m.chartLabels.slice(0, 3).join(', ');
      if (combo.lang === 'ar') {
        ok(m.chartLabels.every((l) => ARABIC.test(l)),
           `${tag} every axis label is in Arabic (${sample})`);
      } else {
        ok(m.chartLabels.every((l) => !ARABIC.test(l)),
           `${tag} the English axis stays in English (${sample})`);
      }
      // and the hyphen form is the defect's fingerprint, in either language
      ok(!m.chartLabels.some((l) => /^\d{1,2}-[A-Za-z]{3}$/.test(l)),
         `${tag} no label is the locale-blind d-M form (${sample})`);
    }

    // HIERARCHY, NOT JUST CONTRAST. A KPI whose label is as loud as its figure is a list of words.
    if (m.statScale && m.statScale.length >= 2) {
      const [biggest, second] = m.statScale;
      ok(biggest.px > second.px,
         `${tag} the stat figure outranks its label (${biggest.px}px "${biggest.text}" `
         + `vs ${second.px}px "${second.text}")`);
      ok(biggest.weight >= second.weight,
         `${tag} the stat figure is no lighter than its label (${biggest.weight} vs ${second.weight})`);
    } else {
      note(`${tag} the stat has fewer than two text nodes to rank`);
    }

    for (const [name, sel] of [['kpi', '.ss-stats, .ss-stat'], ['stat', '.ss-stat'],
                               ['attention', '.ss-attention'], ['chart', '.ss-chart-block']]) {
      const el = await page.$(sel);
      if (!el) { note(`${tag} crop ${name}: nothing matches ${sel}`); continue; }
      const file = path.join(OUT, `${name}__${tag}.png`);
      await el.screenshot({ path: file });
      if (!fs.existsSync(file)) throw new Error('crop not written: ' + file);
    }
  }

  await browser.close();
  console.log(`\n${checks} checks, ${fails} failed`);
  if (notes.length) console.log(notes.length + ' notes');
  process.exit(fails ? 1 : 0);
})();
