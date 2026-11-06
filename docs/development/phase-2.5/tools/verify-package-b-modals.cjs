/**
 * P2.5-PKG-B — THE CATALOGUE MODALS, OPENED THE WAY A MERCHANT OPENS THEM.
 *
 * `probe-package-b-reachability.cjs` found 13 of the 40 Package B surfaces answer 200 with a bare
 * HTML FRAGMENT: no `<html>`, no layout, no shell. Those are the create/edit modals, and the route
 * is not how anyone reaches them -- `public/js/custom.js:35` binds
 * `a[data-ajax-popup="true"]` and injects the fragment into a Bootstrap modal on the list page.
 *
 * SO NAVIGATING TO THE ROUTE MEASURES AN ORPHAN. A fragment rendered on its own inherits no theme
 * attribute, no `data-ss-admin`, none of the design system's tokens -- every colour falls back to
 * the browser default and a contrast sweep invents a page of defects that no merchant can see. This
 * harness clicks the trigger instead, so the modal is measured inside the page that owns it.
 *
 * THE PREMISE IS THE MODAL ITSELF. A click that silently fails leaves the list page on screen, and
 * a harness that then measures "the surface" is measuring the list for the second time and
 * reporting it as a modal. So every row asserts a modal is really open and really carries fields
 * before any reading is taken, and a row whose premise fails is reported, never scored.
 *
 * MEASUREMENTS, same instrument as the page sweep: contrast on the elements that OWN text nodes,
 * composited through every background layer, with clipped (visually-hidden) and image-backed nodes
 * excluded rather than guessed at; plus the two structural defects this project has already found
 * in merchant modals -- a `.modal-footer` nested inside a `.row`, whose divider then misaligns with
 * the fields above (56 modals, P2.5), and a dialog with no accessible name.
 *
 * usage: node docs/development/phase-2.5/tools/verify-package-b-modals.cjs [--out DIR]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPO = '/home/user/syriastore98';
const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const BASE = arg('base', 'http://127.0.0.1:8899');
const OUT = arg('out', path.join(REPO, 'docs/development/phase-2.5/evidence/pkg-b-modals/after'));
const SETTLE = Number(arg('settle', 1400));
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

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

/**
 * list page -> the modal, identified by THE PATH ITS TRIGGER FETCHES rather than by a bare
 * `a[data-ajax-popup="true"]`.
 *
 * THE FIRST VERSION USED THE BARE SELECTOR AND NEVER OPENED A SINGLE MODAL. It matched FOUR
 * elements on /category, and the first was a hidden "Create Tax" item inside the header's Quick
 * Add dropdown -- `class="dropdown-item text-wrap" data-url=".../taxes/create"`. Playwright waited
 * for it to become visible, timed out, and the click never happened. All twenty rows then reported
 * PREMISE FAILED, which reads like twenty broken modals and was twenty broken clicks. The premise
 * check is the only reason that was visible rather than filed as a finding.
 *
 * Matching on `data-url` ties the trigger to the surface the row claims to measure, and the
 * visibility filter at the call site keeps a hidden duplicate in a collapsed menu from winning
 * over the control a merchant actually uses.
 */
const MODALS = [
  ['category-create', '/category', '/category/create'],
  ['brand-create', '/product-brand', '/product-brand/create'],
  ['attribute-create', '/product-attributes', '/product-attributes/create'],
  ['label-create', '/product-label', '/product-label/create'],
  ['coupon-create', '/coupon', '/coupon/create'],
];

let checks = 0, fails = 0;
const notes = [];
function ok(cond, label) {
  checks++;
  if (!cond) { fails++; console.log('  FAIL  ' + label); } else { console.log('  ok    ' + label); }
}
function note(s) { notes.push(s); console.log('  ..    ' + s); }

const MEASURE = () => {
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

  const dlg = document.querySelector('.modal.show .modal-content') || document.querySelector('.modal.show');
  if (!dlg) return { open: false };

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

  const leaves = [];
  (function walk(el) {
    const own = [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim().length);
    if (own) leaves.push(el);
    for (const c of el.children) walk(c);
  })(dlg);

  let worst = null, hidden = 0, imaged = 0, measured = 0;
  for (const el of leaves) {
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none') continue;
    const r = el.getBoundingClientRect();
    if (!r.width || !r.height) continue;
    // the clip is authoritative, not the box -- see the page sweep's note
    if (/rect\(0px,\s*0px,\s*0px,\s*0px\)/.test(cs.clip) || /inset\(\s*50%\s*\)/.test(cs.clipPath)
        || r.width <= 1 || r.height <= 1) { hidden++; continue; }
    const fg = P(cs.color); if (!fg) continue;
    const { colour: bg, imageBacked } = bgStack(el);
    if (imageBacked) { imaged++; continue; }
    const composited = fg.a < 1 ? over(fg, bg) : fg;
    const px = parseFloat(cs.fontSize);
    const large = px >= 24 || (px >= 18.66 && Number(cs.fontWeight) >= 700);
    const floor = large ? 3 : 4.5;
    const value = Math.round(ratio(composited, bg) * 100) / 100;
    measured++;
    if (!worst || value - floor < worst.value - worst.floor) {
      worst = { value, floor, px, weight: cs.fontWeight,
                text: el.textContent.trim().replace(/\s+/g, ' ').slice(0, 30),
                tag: el.tagName.toLowerCase() + (typeof el.className === 'string' && el.className.trim()
                  ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '') };
    }
  }

  const modal = dlg.closest('.modal') || dlg;
  return {
    open: true,
    fields: dlg.querySelectorAll('input, select, textarea').length,
    title: (dlg.querySelector('.modal-title') || {}).textContent?.trim().slice(0, 34) || null,
    // a dialog needs an accessible name, and the host sets aria-labelledby on the .modal
    ariaLabelledby: modal.getAttribute('aria-labelledby'),
    ariaLabel: modal.getAttribute('aria-label'),
    role: modal.getAttribute('role'),
    // RECORDED, NOT ASSERTED. `.modal-footer` nested in a `.row` is still the markup in 81
    // views, and that is DELIBERATE: P2.5-WAVE-20 measured the real defect (the divider ran 12px
    // wider than the field above it, because Bootstrap gives `.row > *` the 12px gutter while the
    // footer keeps its own 16px, and `border-top` is drawn on the padding box) and corrected the
    // BOX in custom.css / rtl-custom.css rather than restructuring 81 templates. Alignment is
    // owned by `tests/Unit/Ui/ModalFooterDividerAlignmentTest` and measured by
    // `measure-modal-footer-alignment.cjs`.
    //
    // An earlier revision of this harness ASSERTED !footerInRow and reported both opened modals as
    // defective in all four combinations. That was a false positive: it tested a markup shape
    // whose visual consequence another slice had already measured and removed. Reported here for
    // context only -- duplicating an authoritative measurement with a weaker proxy is how a
    // harness ends up contradicting a correct fix.
    footerInRow: !!dlg.querySelector('.row .modal-footer'),
    hasFooter: !!dlg.querySelector('.modal-footer'),
    worst, hidden, imaged, measured,
  };
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

    for (const [name, listRoute, fetchPath] of MODALS) {
      await page.goto(BASE + '/dashboard', { waitUntil: 'domcontentloaded' });
      const resp = await page.goto(BASE + listRoute, { waitUntil: 'load' }).catch(() => null);
      if (!resp || new URL(page.url()).pathname !== listRoute) {
        note(`${tag} ${name}: list ${listRoute} not reachable, skipped`); continue;
      }
      await page.waitForTimeout(SETTLE);

      // THE VISIBLE trigger whose data-url fetches this surface. A hidden duplicate in the
      // header's Quick Add dropdown is not the control a merchant uses, and clicking it is what
      // the first version of this harness spent twenty rows failing to do.
      const candidates = await page.$$('a[data-ajax-popup="true"][data-url*="' + fetchPath + '"]');
      let t = null;
      for (const c of candidates) {
        if (await c.isVisible().catch(() => false)) { t = c; break; }
      }
      if (!t) {
        note(`${tag} ${name}: no VISIBLE trigger fetching ${fetchPath} `
           + `(${candidates.length} matched, all hidden or none)`);
        continue;
      }
      await t.click().catch((e) => note(`${tag} ${name}: click threw ${e.message.slice(0, 60)}`));
      // the fragment arrives by ajax, so wait for the dialog rather than a fixed delay.
      // The host injects into `#commanModel` -- its spelling, not a typo here.
      await page.waitForSelector('#commanModel.show, .modal.show', { timeout: 8000 }).catch(() => {});
      await page.waitForTimeout(600);

      const m = await page.evaluate(MEASURE);

      // PREMISE FIRST. A click that silently failed leaves the LIST on screen; measuring that and
      // calling it a modal is the shape of false evidence this project keeps finding.
      if (!m.open) { note(`${tag} ${name}: PREMISE FAILED -- no .modal.show, not measured`); continue; }
      ok(m.fields > 0, `${tag} ${name} modal really opened with fields (${m.fields}) "${m.title}"`);
      // A PREMISE CHECK ON THE INSTRUMENT, not a defect test -- and the threshold is now justified
      // rather than picked. `measured` counts text nodes that survived the contrast filter, which
      // is NOT the number of form fields: a field whose label is `Form::label('')` contributes no
      // text node at all, and P2.5-WAVE-26 found 127 of those. So a low count means "this harness
      // measured little here", which invalidates the contrast verdict below it, and says nothing
      // about whether a field is missing. An earlier note of mine read a count of 3 as "only 3
      // text nodes for 4 fields"; that conflated the two and was wrong.
      //
      // Floor of 2: every modal in this family renders at least a title and one button label, so
      // fewer than 2 means the dialog did not really open and the run should not be trusted.
      ok(m.measured >= 2,
         `${tag} ${name} PREMISE: measured ${m.measured} text nodes, so the contrast verdict `
         + 'below is meaningful (a low count invalidates it; it is not a missing-field signal)');
      note(`${tag} ${name} .modal-footer nested in .row: ${m.footerInRow} `
         + '(expected true -- alignment is owned by ModalFooterDividerAlignmentTest, see above)');
      ok(!!(m.ariaLabelledby || m.ariaLabel),
         `${tag} ${name} dialog has an accessible name (labelledby=${m.ariaLabelledby} label=${m.ariaLabel})`);
      if (m.worst) {
        ok(m.worst.value >= m.worst.floor,
           `${tag} ${name} worst text ${m.worst.value}:1 >= ${m.worst.floor} on ${m.worst.tag} `
           + `"${m.worst.text}" (${m.worst.px}px/${m.worst.weight})`
           + (m.imaged ? ` [${m.imaged} image-backed excluded]` : '')
           + (m.hidden ? ` [${m.hidden} clipped excluded]` : ''));
      }

      const el = await page.$('.modal.show .modal-content');
      if (el) {
        const f = path.join(OUT, `${name}__${tag}.png`);
        await el.screenshot({ path: f });
        if (!fs.existsSync(f)) throw new Error('capture not written: ' + f);
      }
      await page.keyboard.press('Escape').catch(() => {});
      await page.waitForTimeout(300);
    }
  }

  await browser.close();
  console.log(`\n${checks} checks, ${fails} failed`);
  if (notes.length) console.log(notes.length + ' notes');
  process.exit(fails ? 1 : 0);
})();
