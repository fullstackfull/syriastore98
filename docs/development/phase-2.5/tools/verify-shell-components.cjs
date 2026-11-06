/**
 * P2.5-PKG-A-09 — THE SHELL'S REMAINING COMPONENTS, DRIVEN AS COMPONENTS.
 *
 * The coverage matrix kept 'Workspace chip', 'Breadcrumb component', 'Page-header component' and
 * 'Footer + script bundle' PENDING on a stated principle: each is inside page captures already,
 * and "unchanged and in frame" is not the same fact as verified. This tool answers the question
 * those rows actually ask -- it targets each component, crops it on its own, and measures it.
 *
 * FIVE MEASUREMENT ERRORS THIS PHASE HAS ALREADY MADE, AND WHAT IS DONE ABOUT EACH:
 *
 * 1. MEASURING A WRAPPER INSTEAD OF THE TEXT NODE. Three false readings came from reading an
 *    inherited colour off a container that paints no text (the footer reported 1.20:1 that way;
 *    the real figure from `span.text-muted` is 6.14:1). `textLeaves()` therefore descends to the
 *    elements that OWN text nodes and measures only those.
 *
 * 2. SEARCHING INSIDE THE ELEMENT THAT IS ITSELF THE TARGET. `.ss-workspace` IS the button, so
 *    `chip.querySelector('button')` searched inside itself and reported NO TRIGGER. Every probe
 *    here uses `matches() || querySelector()`, never `querySelector()` alone.
 *
 * 3. COMPARING AGAINST THE SIDEBAR'S EDGE IN RTL. The sidebar's right edge IS the viewport's in
 *    RTL, so an "is it covered" test against one edge flagged all seven header controls. Overlap
 *    is tested against the BAND the sidebar occupies, in both directions.
 *
 * 4. A RATIO AGAINST `transparent` IS A FICTION. bgStack() composites every layer up to the first
 *    opaque one, bottom-up, and composites the text colour's own alpha over the result.
 *
 * 5. READING A VALUE WHILE A TRANSITION RUNS. Every reading is taken after a settle delay.
 *
 * WHAT IT REFUSES TO GUESS: a text node whose background chain crosses a background-image is
 * counted in `imageBacked` and EXCLUDED from the contrast verdict rather than given a number
 * that was never measured.
 *
 * usage: node docs/development/phase-2.5/tools/verify-shell-components.cjs [--out DIR]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPO = '/home/user/syriastore98';
const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const BASE = arg('base', 'http://127.0.0.1:8899');
// THE MATRIX RESOLVES EVIDENCE BY PATH, AND IT REFUSED THE FIRST ATTEMPT. build-coverage-matrix.py
// looks for `evidence/<wave>/after/<prefix><combo>.png` with the combos spelled
// `en_ltr_light` / `en_ltr_dark` / `ar_rtl_light` / `ar_rtl_dark`, and marks a claimed row BLOCKED
// when any of the four is absent. This harness first wrote `evidence/pkg-a-09/chip__en_light.png`
// -- right pictures, wrong place and wrong spelling -- and all three claimed rows came back
// BLOCKED rather than verified. That gate is the point: a claim whose captures are not where it
// says they are is not evidence.
const OUT = arg('out', path.join(REPO, 'docs/development/phase-2.5/evidence/pkg-a-09/after'));
const SETTLE = Number(arg('settle', 1200));
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

/**
 * THE DEV ENVIRONMENT, PASSED TO THE CHILD ONLY -- NEVER EXPORTED INTO THIS PROCESS.
 *
 * `serve-dev.sh` documents three variables the dev fixture needs: without DB_DATABASE the tool
 * opens `database/database.sqlite`, which does not exist; a CACHE_STORE that disagrees with the
 * server's makes it write a row the server never reads back; and the fixture has no `sessions`
 * table, so SESSION_DRIVER=database answers 500 on every route including /login.
 *
 * The harness supplies them here instead of trusting how it was launched, because a harness that
 * reads a DIFFERENT DATABASE THAN THE SERVER reports confident numbers about nothing -- and the
 * first run of this file did exactly that.
 *
 * THEY ARE SCOPED TO THIS ONE CHILD PROCESS ON PURPOSE. `serve-dev.sh` records, with dates, that
 * EXPORTING them and then running the suite in the same shell destroyed this fixture twice: the
 * suite migrates a clean schema over it, every row goes, and the file is gitignored so
 * `git status` says nothing. tests/bootstrap.php now aborts the run before any test loads, but a
 * guard is a floor, not a licence -- so nothing here touches this process's own environment.
 */
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

// ---- the in-page instrument -------------------------------------------------------------------
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

  // every background layer from the node up to the first OPAQUE one, composited bottom-up
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

  // the elements that OWN text nodes -- not their wrappers. Error (1).
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
    return {
      text: el.textContent.trim().replace(/\s+/g, ' ').slice(0, 40),
      tag: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string'
        ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : ''),
      px: parseFloat(cs.fontSize), weight: cs.fontWeight,
      ratio: Math.round(ratio(composited, bg) * 100) / 100,
      imageBacked,
    };
  }

  // `matches() || querySelector()` -- never querySelector alone. Error (2).
  const pick = (root, sel) => (root ? (root.matches(sel) ? root : root.querySelector(sel)) : null);

  const out = { dir: document.documentElement.getAttribute('dir'),
                theme: document.documentElement.getAttribute('data-ss-theme'),
                admin: document.body.hasAttribute('data-ss-admin') };

  // --- workspace chip ---
  const chip = document.querySelector('.ss-workspace');
  if (chip) {
    const trigger = pick(chip, 'button,[role="button"]');
    const list = document.getElementById('ss-workspace-list');
    const r = (trigger || chip).getBoundingClientRect();
    out.chip = {
      triggerTag: trigger ? trigger.tagName.toLowerCase() : null,
      haspopup: trigger && trigger.getAttribute('aria-haspopup'),
      expanded: trigger && trigger.getAttribute('aria-expanded'),
      controls: trigger && trigger.getAttribute('aria-controls'),
      target: [Math.round(r.width), Math.round(r.height)],
      listRole: list && list.getAttribute('role'),
      options: list ? list.querySelectorAll('[role="option"]').length : 0,
      text: textLeaves(chip).map(measureText).filter(Boolean),
    };
  }

  // --- breadcrumb ---
  const crumb = document.querySelector('.ss-breadcrumb');
  if (crumb) {
    const nav = crumb.closest('nav');
    const items = [...crumb.querySelectorAll('.ss-breadcrumb__item')];
    const sep = items.length > 1 ? getComputedStyle(items[1], '::before') : null;
    out.crumb = {
      navLabel: nav && nav.getAttribute('aria-label'),
      listTag: crumb.tagName.toLowerCase(),
      items: items.length,
      currents: crumb.querySelectorAll('[aria-current="page"]').length,
      currentIsLink: items.some((i) => i.getAttribute('aria-current') === 'page' && !!i.querySelector('a')),
      sepContent: sep ? sep.content : null,
      sepTransform: sep ? sep.transform : null,
      text: textLeaves(crumb).map(measureText).filter(Boolean),
    };
  }

  // --- page header ---
  const ph = document.querySelector('.ss-page-header, .page-header');
  if (ph) {
    const h1 = ph.querySelector('h1,h2,h3');
    out.pageHeader = {
      root: ph.className.trim().split(/\s+/).slice(0, 3).join('.'),
      headingTag: h1 ? h1.tagName.toLowerCase() : null,
      headingPx: h1 ? parseFloat(getComputedStyle(h1).fontSize) : null,
      subtitle: !!ph.querySelector('.ss-page-header__subtitle'),
      text: textLeaves(ph).map(measureText).filter(Boolean),
    };
  }

  // --- footer bundle ---
  const foot = document.querySelector('footer, .dash-footer');
  if (foot) out.footer = { tag: foot.tagName.toLowerCase(), text: textLeaves(foot).map(measureText).filter(Boolean) };

  // --- skip link (the design-system opt-in partial's one visible control) ---
  const skip = document.querySelector('.ss-skip-link, a[href="#ss-main"]');
  if (skip) out.skip = { href: skip.getAttribute('href'), target: !!document.querySelector('#ss-main'),
                         text: skip.textContent.trim().slice(0, 40) };

  // --- the nav disclosure arrow, per state. A font glyph never mirrors itself,
  //     so the ONLY thing that can point it is the transform. ---
  out.arrows = [];
  for (const li of document.querySelectorAll('.dash-navbar > li.dash-item')) {
    const a = li.querySelector(':scope > a .dash-arrow');
    if (!a) continue;
    out.arrows.push({
      label: ((li.querySelector(':scope > a .dash-mtext') || {}).textContent || '').trim().slice(0, 14),
      expanded: li.classList.contains('dash-trigger'),
      transform: getComputedStyle(a).transform,
    });
  }

  // --- the sub-item marker. It was a frozen CSS spinner; it is now a dot. ---
  out.markers = [];
  for (const li of document.querySelectorAll('.dash-hasmenu.dash-trigger > .dash-submenu > li.dash-item')) {
    const cs = getComputedStyle(li, '::before');
    out.markers.push({
      text: (li.textContent || '').trim().slice(0, 12),
      active: li.classList.contains('active'),
      borderWidth: cs.borderTopWidth + '/' + cs.borderRightWidth + '/'
                 + cs.borderBottomWidth + '/' + cs.borderLeftWidth,
      bg: cs.backgroundColor,
      transform: cs.transform,
    });
  }

  // --- sidebar band, for the overlap test. Error (3): a band, not an edge. ---
  const sb = document.querySelector('.dash-sidebar');
  if (sb) { const r = sb.getBoundingClientRect(); out.sidebarBand = [Math.round(r.left), Math.round(r.right)]; }
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
    const tag = `${combo.lang}_${combo.dir}_${combo.theme}`;  // the matrix's spelling, exactly
    console.log(`\n=== ${combo.lang}/${combo.dir} ${combo.theme} ===`);
    // POSITIONAL ARGS, AND THE cwd THE SERVER USES. The first draft of this file called the tool
    // with named flags (`--lang en --theme light`); it takes
    // `<storeId> <light|dark> <ltr|rtl> <lang> <email>` positionally, so it received `--lang` as
    // the store id and wrote nonsense, while `stdio: 'pipe'` swallowed the complaint. All four
    // combinations then measured the SAME ar/dark page and 20 contrast checks passed against a
    // combination that never rendered. Only the premise checks caught it. The tool's own docblock
    // records eight earlier false "dark" runs from the same shape of mistake.
    // cwd must be the tool's own directory, or it loads a different .env and writes to a
    // different sqlite file than the server reads -- and reports success.
    const stateOut = execFileSync('php',
      [STATE_TOOL, '1', combo.theme, combo.dir, combo.lang, 'merchant@example.test'],
      { cwd: path.dirname(STATE_TOOL), encoding: 'utf8', env: DEV_ENV });
    if (!/\S/.test(stateOut)) throw new Error('state tool printed nothing -- it did not run');
    // /product carries the breadcrumb, the page header, the chip and the footer in one view.
    await page.goto(BASE + '/product', { waitUntil: 'load' });
    await page.waitForTimeout(SETTLE);

    const m = await page.evaluate(INSTRUMENT);

    // PREMISE: the combination asked for is the combination that rendered. A harness that
    // measured light twice has happened twice in this project.
    ok(m.dir === combo.dir, `${tag} premise: dir is ${combo.dir} (got ${m.dir})`);
    ok(combo.theme === 'dark' ? m.theme === 'dark' : m.theme === null,
       `${tag} premise: theme attribute matches (${m.theme})`);
    ok(m.admin === true, `${tag} premise: data-ss-admin present`);
    ok(m.overflow <= 0, `${tag} no horizontal overflow at 1440 (${m.overflow}px)`);

    // --- chip ---
    ok(m.chip && m.chip.triggerTag === 'button', `${tag} chip trigger is a <button> (${m.chip && m.chip.triggerTag})`);
    ok(m.chip && m.chip.haspopup === 'listbox', `${tag} chip aria-haspopup=listbox`);
    ok(m.chip && m.chip.controls && m.chip.listRole === 'listbox', `${tag} chip controls a role=listbox`);
    ok(m.chip && m.chip.target[0] >= 44 && m.chip.target[1] >= 44,
       `${tag} chip target ${m.chip && m.chip.target.join('x')} >= 44x44`);

    // --- breadcrumb ---
    ok(m.crumb && m.crumb.listTag === 'ol', `${tag} breadcrumb is an ordered list (${m.crumb && m.crumb.listTag})`);
    ok(m.crumb && !!m.crumb.navLabel, `${tag} breadcrumb nav is labelled (${m.crumb && m.crumb.navLabel})`);
    ok(m.crumb && m.crumb.currents === 1, `${tag} exactly one aria-current=page (${m.crumb && m.crumb.currents})`);
    ok(m.crumb && m.crumb.currentIsLink === false, `${tag} the current item is not a link`);
    if (m.crumb && combo.dir === 'rtl') {
      ok(/scale|matrix/.test(m.crumb.sepTransform || ''),
         `${tag} separator mirrors by transform in RTL (${m.crumb.sepTransform})`);
    }
    // THE ARABIC REGISTER. `البيت` is a physical house; the navigational root is `الرئيسية`.
    if (combo.lang === 'ar' && m.crumb) {
      const home = m.crumb.text.map((t) => t.text).join(' | ');
      ok(!/البيت/.test(home), `${tag} breadcrumb root is not the word for a house (${home})`);
    }

    // --- contrast, on the elements that own the text ---
    for (const [name, blk] of [['chip', m.chip], ['breadcrumb', m.crumb], ['pageHeader', m.pageHeader], ['footer', m.footer]]) {
      if (!blk || !blk.text) { note(`${tag} ${name}: not rendered on this view`); continue; }
      const measurable = blk.text.filter((t) => !t.imageBacked);
      const skipped = blk.text.length - measurable.length;
      const worst = measurable.reduce((a, t) => (a && a.ratio <= t.ratio ? a : t), null);
      if (!worst) { note(`${tag} ${name}: no measurable text node`); continue; }
      const large = worst.px >= 24 || (worst.px >= 18.66 && Number(worst.weight) >= 700);
      const floor = large ? 3 : 4.5;
      ok(worst.ratio >= floor,
         `${tag} ${name} worst text ${worst.ratio}:1 >= ${floor} on ${worst.tag} "${worst.text}" (${worst.px}px/${worst.weight})`
         + (skipped ? ` [${skipped} image-backed excluded]` : ''));
    }

    // --- the disclosure arrow points the right way in this direction ---
    // Expected, as rendered: collapsed follows the reading direction, expanded points DOWN.
    // chevron-right is the base glyph, so the matrix says which way it ended up.
    //   rotate(0)    = matrix(1, 0, 0, 1, ...)    -> right
    //   rotate(180)  = matrix(-1, 0, 0, -1, ...)  -> left
    //   rotate(90)   = matrix(0, 1, -1, 0, ...)   -> down
    //   rotate(-90)  = matrix(0, -1, 1, 0, ...)   -> UP, which is the defect
    const DOWN = /matrix\(0,\s*1,\s*-1,\s*0/;
    const RIGHT = /^none$|matrix\(1,\s*0,\s*0,\s*1/;
    const LEFT = /matrix\(-1,\s*0,\s*0,\s*-1/;
    ok(m.arrows.length > 0, `${tag} the nav has disclosure arrows to measure (${m.arrows.length})`);
    for (const a of m.arrows) {
      if (a.expanded) {
        ok(DOWN.test(a.transform),
           `${tag} expanded "${a.label}" points DOWN (${a.transform})`);
      } else {
        ok(combo.dir === 'rtl' ? LEFT.test(a.transform) : RIGHT.test(a.transform),
           `${tag} collapsed "${a.label}" points along the reading direction (${a.transform})`);
      }
    }

    // --- the sub-item marker is a deliberate dot, not a stalled spinner ---
    // The spinner read came from an 8x8 ring with ONE side in the accent colour. Any border
    // width at all brings it back, and a flat background in both states means the active child
    // is no longer marked the same as its siblings.
    ok(m.markers.length > 0, `${tag} the open submenu has markers to measure (${m.markers.length})`);
    const activeMarkers = m.markers.filter((x) => x.active);
    ok(activeMarkers.length === 1, `${tag} exactly one active child marker (${activeMarkers.length})`);
    for (const x of m.markers) {
      ok(/^0px\/0px\/0px\/0px$/.test(x.borderWidth),
         `${tag} marker "${x.text}" paints no ring (${x.borderWidth})`);
      ok(/rgba?\([^)]*\)/.test(x.bg) && !/rgba\([^)]*,\s*0\)$/.test(x.bg),
         `${tag} marker "${x.text}" is a filled dot (${x.bg})`);
    }
    // the active dot must differ from its siblings in BOTH colour and size, in both themes --
    // the defect was that the dark sheet's !important made all five identical.
    if (activeMarkers.length === 1) {
      const inactive = m.markers.filter((x) => !x.active);
      ok(inactive.every((x) => x.bg !== activeMarkers[0].bg),
         `${tag} the active dot's colour differs from every sibling (${activeMarkers[0].bg})`);
      ok(inactive.every((x) => x.transform !== activeMarkers[0].transform),
         `${tag} the active dot's size differs from every sibling (${activeMarkers[0].transform})`);
    }

    // --- skip link ---
    ok(m.skip && m.skip.target === true, `${tag} skip link points at an element that exists`);

    // --- crops. Each component on its own, which is what the matrix rows ask for. ---
    for (const [name, sel] of [['chip', '.ss-workspace'], ['breadcrumb', '.ss-breadcrumb'],
                               ['pageheader', '.ss-page-header, .page-header'], ['footer', 'footer, .dash-footer']]) {
      const el = await page.$(sel);
      if (!el) { note(`${tag} crop ${name}: no element for ${sel}`); continue; }
      const file = path.join(OUT, `${name}__${tag}.png`);
      await el.screenshot({ path: file }).catch(() => note(`${tag} crop ${name}: screenshot failed`));
      if (!fs.existsSync(file)) throw new Error(`crop not written: ${file}`);
    }
    // the chip OPEN, which is the state the row is really about
    await page.click('#ss-workspace-trigger').catch(() => {});
    await page.waitForTimeout(400);
    const open = await page.$('.ss-workspace, .ss-workspace__list');
    if (open) {
      const wrap = await page.$('.dash-sidebar') || open;
      const f = path.join(OUT, `chip-open__${tag}.png`);
      await wrap.screenshot({ path: f });
      if (!fs.existsSync(f)) throw new Error('chip-open crop not written');
    }
    await page.keyboard.press('Escape').catch(() => {});
  }

  await browser.close();
  console.log(`\n${checks} checks, ${fails} failed`);
  if (notes.length) console.log(notes.length + ' notes');
  process.exit(fails ? 1 : 0);
})();
