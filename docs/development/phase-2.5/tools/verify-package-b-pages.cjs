/**
 * P2.5-PKG-B — THE REACHABLE CATALOGUE PAGES, DRIVEN IN ALL FOUR COMBINATIONS.
 *
 * `probe-package-b-reachability.cjs` established which of the 40 Package B surfaces this merchant
 * can actually open: 9 pages, 13 modal FRAGMENTS, 8 refused by permissions, 6 with no fixture rows.
 * This drives the 9 pages. The fragments are deliberately NOT driven here -- a modal route returns
 * bare markup with no shell, so navigating to one measures an unstyled orphan and invents contrast
 * defects that no merchant can see. They are opened from their list in a separate pass.
 *
 * WHAT IT MEASURES, and why each one is here rather than a general "looks fine":
 *
 *   premises         the combination asked for is the combination that rendered, and the page is
 *                    the page asked for. A permission refusal is `redirect()->back()`, so a probe
 *                    that does not check the landing path records the DASHBOARD as the screen --
 *                    the single most dangerous reading this project can take, because it looks
 *                    like evidence. Measured today: 8 of the 40 surfaces do exactly that.
 *   contrast         on the elements that OWN text nodes, composited through every background
 *                    layer, with visually-hidden and image-backed nodes excluded rather than given
 *                    a number that was never measured.
 *   firstRowY        the document y of the first real content. WAVE-22 found merchant screens
 *                    whose first data sat at 1358-2131px; a premium screen answers the merchant's
 *                    question above the fold.
 *   overflow         at 1440 and at 390. A catalogue screen that scrolls sideways on a phone is
 *                    the `horizontal-scroll` defect.
 *   emptyState       whether a list with no rows says anything. "No data found" where a merchant
 *                    expects guidance is a wasted screen.
 *   iconFamilies     Tabler vs feather vs emoji on one screen. The directive forbids emoji as
 *                    interface icons and this project has already converged two families once.
 *
 * usage: node docs/development/phase-2.5/tools/verify-package-b-pages.cjs [--out DIR]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPO = '/home/user/syriastore98';
const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const BASE = arg('base', 'http://127.0.0.1:8899');
const OUT = arg('out', path.join(REPO, 'docs/development/phase-2.5/evidence/pkg-b/after'));
const SETTLE = Number(arg('settle', 1600));
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

/** Scoped to the child only: serve-dev.sh records that exporting these destroyed the fixture twice. */
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
 * THE 16 THAT probe-package-b-reachability.cjs MEASURES AS PAGE -- was 9.
 *
 * Seven were unreachable until P2.5-PKG-B closed the fixture gaps: tag, flash-sale,
 * product-question, testimonial and faqs were refused because the dev merchant held no
 * `Manage *` permission (granted with grant-dev-screen-permissions.php); coupon-detail had no
 * coupon row at all (seeded by seed-dev-interaction-fixtures.php); and attribute-option was
 * neither -- the probe was asking for `/attribute-option` with no id, where the route is `{id?}`
 * and the id is an ATTRIBUTE id, so `ownedAttribute(null)` failed closed and returned denied().
 *
 * IDS COME FROM THE PROBE'S OWN JSON, not from a literal in this file. The seeded rows' ids move
 * every time the fixture is removed and re-seeded -- the coupon went from 2 to 3 within one
 * session, because sqlite's AUTOINCREMENT does not reuse a deleted id -- so a hardcoded
 * `/coupon/3` reads as a 404 and then as a defect the next time anyone runs the seed tool.
 */
const REACH = JSON.parse(fs.readFileSync(
  path.join(__dirname, 'package-b-reachability.json'), 'utf8'));
const ID = REACH.ids || {};

const PAGES = [
  ['category', '/category'],
  ['brand', '/product-brand'],
  ['attribute', '/product-attributes'],
  ['attribute-option', '/attribute-option/' + (ID.attribute ?? 1)],
  ['tag', '/tag'],
  ['label', '/product-label'],
  ['coupon', '/coupon'],
  ['coupon-detail', '/coupon/' + (ID.coupon ?? 1)],
  ['flash-sale', '/flash-sale'],
  ['product-question', '/product-question'],
  ['testimonial', '/testimonial'],
  ['faqs', '/faqs'],
  ['product-list', '/product'],
  ['product-create', '/product/create'],
  ['product-edit', '/product/' + (ID.product ?? 1) + '/edit'],
  ['product-show', '/product/' + (ID.product ?? 1)],
];

let checks = 0, fails = 0;
const notes = [];
const rows = [];
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

  const leaves = [];
  (function walk(el) {
    const own = [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim().length);
    if (own) leaves.push(el);
    for (const c of el.children) walk(c);
  })(document.body);

  let worst = null, hidden = 0, imaged = 0, measured = 0;
  for (const el of leaves) {
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none') continue;
    const r = el.getBoundingClientRect();
    if (!r.width || !r.height) continue;
    /* VISUALLY HIDDEN -- AND THE BOX IS NOT THE TEST. `.ss-visually-hidden` declares
       `width:1px; height:1px`, but MEASURED on /category the pagination's "Previous"/"Next"
       spans report 35x35: something overrides their size. What actually hides them is
       `clip: rect(0px, 0px, 0px, 0px)`, which clips to nothing whatever the box says. Testing
       the box alone reported those spans at 1.33:1 and would have had me "fix" the contrast of
       text no sighted reader can see. Screen readers do not read colour. */
    const clipped = /rect\(0px,\s*0px,\s*0px,\s*0px\)/.test(cs.clip)
      || /inset\(\s*50%\s*\)/.test(cs.clipPath)
      || (r.width <= 1 || r.height <= 1);
    if (clipped) { hidden++; continue; }
    const fg = P(cs.color); if (!fg) continue;
    const { colour: bg, imageBacked } = bgStack(el);
    if (imageBacked) { imaged++; continue; }                      // refuse to guess a painted colour
    const composited = fg.a < 1 ? over(fg, bg) : fg;
    const px = parseFloat(cs.fontSize);
    const large = px >= 24 || (px >= 18.66 && Number(cs.fontWeight) >= 700);
    const value = Math.round(ratio(composited, bg) * 100) / 100;
    const floor = large ? 3 : 4.5;
    measured++;
    if (!worst || value - floor < worst.value - worst.floor) {
      worst = { value, floor, px, weight: cs.fontWeight,
                text: el.textContent.trim().replace(/\s+/g, ' ').slice(0, 34),
                tag: el.tagName.toLowerCase() + (typeof el.className === 'string' && el.className.trim()
                  ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '') };
    }
  }

  // the first element carrying REAL content, and which kind won
  let first = null;
  for (const [kind, sel] of [['row', 'tbody tr'], ['card', '.ss-card__body, .card-body'],
                             ['field', 'input, select, textarea'], ['item', 'li']]) {
    const el = document.querySelector(sel);
    if (!el) continue;
    const r = el.getBoundingClientRect();
    if (!r.height) continue;
    const y = Math.round(r.top + window.scrollY);
    if (!first || y < first.y) first = { kind, y };
  }

  const icons = {
    tabler: document.querySelectorAll('i.ti, [class*=" ti-"], [class^="ti-"]').length,
    feather: document.querySelectorAll('[data-feather]').length,
  };
  /* EMOJI USED AS AN INTERFACE ICON, which the directive forbids outright -- but the first
     version of this check reported 4 to 14 per page and every one was FALSE. It used the range
     \u2600-\u27BF, which is Miscellaneous Symbols and Dingbats: that range contains the
     typographic marks a UI legitimately uses. MEASURED, the only thing it ever matched was
     `\u2606 WHITE STAR` in a rating control.
     `\p{Emoji_Presentation}` is the right test -- it is true only for characters that render as
     emoji by default. Verified in this runtime: star, check, chevron and warning-sign are all
     false; rocket and glowing-star are true. */
  const emojiRe = /\p{Emoji_Presentation}/u;
  const emoji = [...document.querySelectorAll('button, a, th, td, label, span')]
    .filter((e) => emojiRe.test(e.textContent || '')).length;

  return {
    dir: document.documentElement.getAttribute('dir'),
    theme: document.documentElement.getAttribute('data-ss-theme'),
    admin: document.body.hasAttribute('data-ss-admin'),
    path: location.pathname,
    title: (document.querySelector('h1') || {}).textContent?.trim().slice(0, 40) || null,
    worst, hidden, imaged, measured,
    first,
    overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    icons, emoji,
    emptyState: document.querySelectorAll('.ss-empty').length,
    rowCount: document.querySelectorAll('tbody tr').length,
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

    for (const [name, route] of PAGES) {
      // RESET: a refusal is redirect()->back(), so without this a denied page reports the
      // PREVIOUS page as its own. Measured: that is how 8 surfaces first read as "redirects".
      await page.goto(BASE + '/dashboard', { waitUntil: 'domcontentloaded' });
      await page.goto(BASE + route, { waitUntil: 'load' });
      await page.waitForTimeout(SETTLE);

      const m = await page.evaluate(INSTRUMENT);

      if (m.path !== route) { note(`${tag} ${name}: REFUSED -> ${m.path}, not measured`); continue; }
      ok(m.dir === combo.dir, `${tag} ${name} premise: dir ${m.dir}`);
      ok(combo.theme === 'dark' ? m.theme === 'dark' : m.theme === null,
         `${tag} ${name} premise: theme ${m.theme}`);
      ok(m.measured > 20, `${tag} ${name} measured ${m.measured} text nodes (not an empty page)`);
      ok(m.overflow <= 0, `${tag} ${name} no sideways scroll at 1440 (${m.overflow}px)`);
      ok(m.emoji === 0, `${tag} ${name} no emoji used as an interface icon (${m.emoji})`);
      ok(m.icons.feather === 0,
         `${tag} ${name} one icon family: ${m.icons.tabler} tabler, ${m.icons.feather} feather`);
      if (m.worst) {
        ok(m.worst.value >= m.worst.floor,
           `${tag} ${name} worst text ${m.worst.value}:1 >= ${m.worst.floor} on ${m.worst.tag} `
           + `"${m.worst.text}" (${m.worst.px}px/${m.worst.weight})`
           + (m.imaged ? ` [${m.imaged} image-backed excluded]` : '')
           + (m.hidden ? ` [${m.hidden} visually-hidden excluded]` : ''));
      }
      rows.push({ combo: tag, name, route, title: m.title, worst: m.worst, first: m.first,
                  rowCount: m.rowCount, emptyState: m.emptyState, icons: m.icons });
      note(`${tag} ${name}: h1=${JSON.stringify(m.title)} rows=${m.rowCount} `
         + `first=${m.first ? m.first.kind + '@' + m.first.y + 'px' : 'none'} empty=${m.emptyState}`);

      const file = path.join(OUT, `${name}__${tag}.png`);
      await page.screenshot({ path: file, fullPage: false });
      if (!fs.existsSync(file)) throw new Error('capture not written: ' + file);
    }
  }

  await browser.close();
  fs.writeFileSync(path.join(OUT, '..', 'measurements.json'), JSON.stringify(rows, null, 2));
  console.log(`\n${checks} checks, ${fails} failed`);
  if (notes.length) console.log(notes.length + ' notes');
  process.exit(fails ? 1 : 0);
})();
