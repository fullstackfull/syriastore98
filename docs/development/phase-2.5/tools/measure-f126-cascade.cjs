/**
 * P2.5-A-01 / A-02 — the REAL CSS cascade for every <label>- and field-bearing surface in the
 * dark admin, measured against hand-written markup rather than the running application.
 *
 * WHAT THIS IS FOR, AND WHAT IT CANNOT TELL YOU. It loads the four stylesheets the admin layout
 * names, in the layout's own dark order, so a CSS question can be settled in a second without a
 * database, a login or a seeded tenant. It is NOT sufficient evidence on its own: the running
 * application loads TWENTY-FIVE stylesheets, six of them plus three inline <style> blocks AFTER
 * `store-syria-ds.css`, and one of those is what makes a modal title white on white. This
 * harness measured that same title at 3.84:1 and the live one measured 1.00:1. Use this to
 * iterate; use `check-dark-form-surfaces-live.cjs` to decide.
 *
 * usage: node docs/development/phase-2.5/tools/measure-f126-cascade.cjs [fixture.html] [tag]
 *
 * Loads the host's four stylesheets in layouts/app.blade.php's exact dark-LTR order plus the
 * design system at its @stack('css') position, against real host markup, in real Chromium.
 * Reports the computed foreground, the EFFECTIVE background (walking ancestors past
 * `transparent`, as a browser does) and the WCAG 2.1 ratio for each.
 */
const { chromium } = require('playwright');
const path = require('path');

const HARNESS = 'file://' + path.resolve(__dirname, process.argv[2] || 'f126-cascade-fixture.html');
const TAG = (process.argv[3] || 'ltr');

const TARGETS = [
  ['t-title',     'MODAL  .modal-title (white modal)',                4.5],
  ['t-formlabel', 'MODAL  .form-label  (white modal)',                4.5],
  ['t-barelabel', 'MODAL  bare <label> (white modal)',                4.5],
  ['t-sslabel',   'MODAL  .ss-label    (white modal)',                4.5],
  ['t-p',         'MODAL  <p>          (white modal)',                4.5],
  ['t-small',     'MODAL  .form-text   (white modal)',                4.5],
  ['t-option',    'MODAL  <option>     (white modal)',                4.5],
  ['e-formlabel', 'ESCAPED MODAL .form-label  (data-ss-theme=light)', 4.5],
  ['e-sslabel',   'ESCAPED MODAL .ss-label    (data-ss-theme=light)', 4.5],
  ['e-p',         'ESCAPED MODAL <p>          (data-ss-theme=light)', 4.5],
  ['c-navtext',   'CHROME sidebar nav text',                          4.5],
  ['c-dtlength',  'CHROME DataTables length label',                   4.5],
  ['c-dtfilter',  'CHROME DataTables filter label',                   4.5],
  ['c-dtinfo',    'CHROME DataTables info',                           4.5],
  ['c-ddlabel',   'CHROME dataTable-dropdown label',                  4.5],
  ['c-crumb',     'CHROME breadcrumb link',                           4.5],
  ['d-cardlabel', 'DARK CARD .form-label',                            4.5],
  ['d-cardp',     'DARK CARD <p>',                                    4.5],
  ['d-pagelabel', 'DARK PAGE .form-label',                            4.5],
  ['i1',          'MODAL  .form-control text (white modal)',          4.5],
  ['i2',          'MODAL  select text        (white modal)',          4.5],
  ['i3',          'MODAL  .ss-input text     (white modal)',          4.5],
  ['i4',          'ESCAPED MODAL .form-control text',                 4.5],
  ['i6',          'DARK CARD .form-control text',                     4.5],
  ['i7',          'DARK PAGE .form-control text',                     4.5],
  ['sidebar-search', 'CHROME sidebar search input text',              4.5],
];

(async () => {
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
  await page.goto(HARNESS, { waitUntil: 'load' });
  await page.waitForTimeout(400);

  const rows = await page.evaluate((ids) => {
    const srgb = (c) => (c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
    const parse = (s) => {
      const m = String(s).match(/rgba?\(([^)]+)\)/);
      if (!m) return null;
      const p = m[1].split(',').map((x) => parseFloat(x.trim()));
      return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
    };
    const lum = (c) => 0.2126 * srgb(c.r / 255) + 0.7152 * srgb(c.g / 255) + 0.0722 * srgb(c.b / 255);
    const over = (fg, bg) => ({            // WCAG alpha compositing
      r: fg.r * fg.a + bg.r * (1 - fg.a),
      g: fg.g * fg.a + bg.g * (1 - fg.a),
      b: fg.b * fg.a + bg.b * (1 - fg.a), a: 1,
    });
    // The effective background: walk up until an opaque colour is found, compositing
    // any translucent layers on the way, exactly as the compositor does.
    const effBg = (el) => {
      let stack = [], n = el;
      while (n && n.nodeType === 1) {
        const c = parse(getComputedStyle(n).backgroundColor);
        if (c && c.a > 0) { stack.push(c); if (c.a === 1) break; }
        n = n.parentElement;
      }
      let out = { r: 255, g: 255, b: 255, a: 1 };
      for (let i = stack.length - 1; i >= 0; i--) out = over(stack[i], out);
      return out;
    };
    return ids.map(([id, label, need]) => {
      const el = document.getElementById(id);
      if (!el) return { id, label, need, missing: true };
      const cs = getComputedStyle(el);
      const fgRaw = parse(cs.color);
      const bg = effBg(el);
      const fg = over(fgRaw, bg);
      const l1 = lum(fg), l2 = lum(bg);
      const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
      const hex = (c) => '#' + [c.r, c.g, c.b].map((v) => Math.round(v).toString(16).padStart(2, '0')).join('');
      return { id, label, need, fg: hex(fg), bg: hex(bg), ratio: Math.round(ratio * 100) / 100 };
    });
  }, TARGETS);

  let fails = 0;
  console.log(`F126 — REAL CASCADE, Chromium, dark ${TAG.toUpperCase()}, host stylesheet order + design system\n`);
  console.log('  ratio   need  fg        bg        surface');
  console.log('  ' + '-'.repeat(86));
  for (const r of rows) {
    if (r.missing) { console.log(`  MISSING ELEMENT: ${r.id}`); fails++; continue; }
    const ok = r.ratio >= r.need;
    if (!ok) fails++;
    console.log(`  ${ok ? 'PASS' : 'FAIL'} ${String(r.ratio).padStart(6)}:1  ${r.need}  ${r.fg}   ${r.bg}   ${r.label}`);
  }
  console.log(`\n  ${rows.length} surfaces measured, ${fails} below AA`);
  await page.screenshot({ path: path.resolve(__dirname, '../evidence/p2-5-a-01/cascade-' + TAG + '.png'), fullPage: true });
  console.log('  screenshot: evidence/p2-5-a-01/cascade-' + TAG + '.png');
  await browser.close();
  process.exit(0);
})();
