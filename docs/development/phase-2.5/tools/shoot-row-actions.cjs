/**
 * CAPTURE AND MEASURE THE ROW-ACTION CELL, on its own, for a whole family of list screens.
 *
 * WHY THIS IS NOT A PAGE CAPTURE. Sixteen matrix rows name the ACTION CELL inside a DataTable row
 * -- the edit / delete / show controls a merchant clicks -- not the list page that contains it.
 * The page already has its own row and its own evidence, and `build-coverage-matrix.py` refuses
 * two surfaces claiming the same capture set, so re-using the list screenshot here would be
 * rejected and should be. This tool screenshots the CELL'S OWN BOUNDING BOX, which is a distinct
 * image of a distinct surface.
 *
 * WHY IT MEASURES RATHER THAN ONLY PHOTOGRAPHS. These cells are the densest concentration of
 * icon-only controls in the application: a row of 24px ghost buttons whose entire accessible name
 * is an `aria-label`, sitting in a pinned column. That is precisely the shape that photographs
 * perfectly and fails in use, so the camera is the weaker half of this tool.
 *
 *   nameless   a control whose only identification is its icon. The partials DO carry aria-label
 *              (`resources/views/coupon/action.blade.php`), so this is a ratchet: it proves the
 *              labels are really there and really rendered, in Arabic as well as English.
 *   target     WCAG 2.5.8 Target Size (Minimum), 24x24 CSS px. Ghost icon buttons in a dense
 *              table are the usual failure, and a screenshot cannot show it.
 *   contrast   every control and text node against its composited background. `ss-btn--ghost`
 *              has no fill, so the icon sits on the row stripe and the ratio changes with it.
 *   latin      Latin-script words in the Arabic UI, which in an action cell means an untranslated
 *              tooltip or aria-label -- invisible in a screenshot by definition.
 *
 * PREMISES, because a cell with nothing in it photographs as a tidy empty cell:
 *   - the landed path is the requested path (a `redirect()->back()` refusal keeps the previous
 *     page's title; see PHASE-2.5-DEV-FIXTURE-PERMISSIONS.md)
 *   - the table has at least one row that is not the empty-state placeholder
 *   - the cell holds at least one control
 *
 * The third premise is not pedantry. `@permission('Edit Coupon')` wraps every button in these
 * partials, so a fixture holding `Manage Coupon` but not `Edit Coupon` renders
 * `<span class="d-flex gap-1 justify-content-end"> </span>` -- an empty cell, HTTP 200, no error.
 * Measured on /coupon, /faqs and /tag before the 36 action permissions were granted.
 *
 * usage:
 *   node shoot-row-actions.cjs --out <dir>
 *   node shoot-row-actions.cjs --out <dir> --only coupon,tag
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
const OUT = arg('out', '/tmp/row-actions');
const ONLY = arg('only', '').split(',').map(s => s.trim()).filter(Boolean);
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

/**
 * The surfaces, each a matrix row. `slug` names the capture; `route` is the list page the cell
 * lives on. Kept here rather than derived, so adding a surface is one line and a surface that
 * disappears from the application fails loudly instead of quietly not being captured.
 */
const SURFACES = [
  { slug: 'coupon-row-actions', route: '/coupon' },
  { slug: 'faq-row-actions', route: '/faqs' },
  { slug: 'tag-row-actions', route: '/tag' },
  { slug: 'testimonial-row-actions', route: '/testimonial' },
  { slug: 'product-question-row-actions', route: '/product-question' },
  { slug: 'category-row-actions', route: '/category' },
  { slug: 'product-label-row-actions', route: '/product-label' },
  { slug: 'brand-row-actions', route: '/product-brand' },
  { slug: 'customer-row-actions', route: '/customer' },
  { slug: 'order-row-actions', route: '/order' },
  { slug: 'product-row-actions', route: '/product' },

  // ---- P2.5-P1 (2026-11-10) ----------------------------------------------------------------
  // The attribute list's Action column. No fixture or permission work: product_attributes
  // already holds rows in this fixture, and the cell is in the first row on load. The harness
  // clips the whole row, so the attribute name ('Size' / 'Colour') identifies the image and it
  // cannot collide with the sibling two-icon cells.
  { slug: 'attribute-row-actions', route: '/product-attributes' },
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

/** Words allowed to stay Latin inside an action cell in the Arabic UI. */
const LATIN_OK = new Set(['id', 'pdf', 'csv', 'url', 'sku', 'qr', 'n', 'a', 'cod']);

/** Everything measured about the cell, in one evaluate so every figure sees the same paint. */
const MEASURE = (args) => {
  const notEmpty = (r) => !r.querySelector('.ss-dt-empty');
  const rows = [...document.querySelectorAll('table tbody tr')].filter(notEmpty);
  if (!rows.length) return { ok: false, why: 'no data rows (empty-state placeholder only)' };

  const tds = [...rows[0].querySelectorAll('td')];
  if (!tds.length) return { ok: false, why: 'first row has no cells' };
  const cell = tds[tds.length - 1];

  const controls = [...cell.querySelectorAll('a, button')];
  if (!controls.length) {
    return { ok: false, why: 'action cell holds no controls (a @permission gate, not a defect)' };
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

  const findings = [];
  const described = [];

  for (const el of controls) {
    const r = el.getBoundingClientRect();
    const name = (el.getAttribute('aria-label') || '').trim()
      || (el.getAttribute('title') || '').trim()
      || (el.textContent || '').trim();

    described.push({ tag: el.tagName.toLowerCase(), name: name.slice(0, 40), w: Math.round(r.width), h: Math.round(r.height) });

    if (!name) {
      findings.push({ kind: 'nameless', detail: el.tagName.toLowerCase() + '.' + (el.className || '').toString().trim().slice(0, 34) });
    }

    // WCAG 2.5.8 Target Size (Minimum): 24x24 CSS px.
    if (r.width > 0 && (r.width < 24 || r.height < 24)) {
      findings.push({ kind: 'target', detail: Math.round(r.width) + 'x' + Math.round(r.height) + 'px, needs 24x24  "' + name.slice(0, 26) + '"' });
    }

    // The ICON is what is seen, so measure its colour, not the transparent button's.
    const icon = el.querySelector('i, svg') || el;
    const cs = getComputedStyle(icon);
    const fg = parse(cs.color).slice(0, 3);
    if (fg.length >= 3) {
      const n = Number(ratio(fg, paintedBg(icon)).toFixed(2));
      // A control's glyph is a non-text element: WCAG 1.4.11 sets 3:1.
      if (n < 3) {
        findings.push({ kind: 'contrast', detail: n + ':1 needs 3 (icon)  "' + name.slice(0, 26) + '"' });
      }
    }

    if (args.lang === 'ar' && name) {
      /*
       * A RECORD'S OWN NAME IS NOT AN UNTRANSLATED STRING.
       *
       * These labels are built as `__('Edit :thing', ['thing' => $record->name])`, so a correct
       * Arabic label still contains Latin text whenever the merchant's own data is Latin:
       * `تعديل التصنيف Scarves & Shawls`, `حذف الزبون Walk-in Guest`,
       * `تعديل الطلب P25CUSTFILTER-2-2`. Including the record's name is the POINT -- it is what
       * tells a screen-reader user which row's Edit button they are on -- so flagging it would
       * report the accessible-name feature as a defect and the obvious "fix" would be to strip it.
       *
       * The discriminator is the row itself: a Latin word that also appears in the row's own data
       * cells is the merchant's data. A Latin word in the label that appears NOWHERE in the row is
       * a string the translator did not resolve, which is the thing worth finding.
       */
      // Case-insensitively: the customer fixture is stored as `Walk-In Guest` in the cell and
      // `Walk-in Guest` in the label, and a case-sensitive compare reported that as three
      // untranslated strings. The words are the same words; only the capital I differs.
      const rowText = (rows[0].innerText || '').toLowerCase();
      const words = (name.match(/[A-Za-z][A-Za-z'.-]+/g) || [])
        .filter(w => !args.latinOk.includes(w.toLowerCase().replace(/[^a-z]/g, '')))
        .filter(w => !rowText.includes(w.toLowerCase()));
      if (words.length) {
        findings.push({ kind: 'latin', detail: '"' + words.join(' ') + '" in the accessible name "' + name.slice(0, 34) + '"' });
      }
    }
  }

  /*
   * THE CAPTURE IS THE WHOLE ROW, not the action cell alone, and that is a correctness decision
   * rather than a framing preference.
   *
   * Cropped to the cell, these captures were IDENTICAL ACROSS SURFACES: brand, category,
   * product-label and tag each render two ghost icon buttons of the same size and colour with no
   * text, so all four produced the same bytes in all four combinations (44 captures, 30 distinct;
   * faq and testimonial collided too). `check-distinct-surface-captures.py` would have refused
   * them, and it would have been right -- an image of two anonymous pencil-and-bin icons is not
   * evidence about `Tag row actions` specifically, it is evidence that SOME row has two buttons.
   *
   * Including the row means the capture carries the record that identifies it -- the tag's name,
   * the category's name -- so each image is about its own surface. It stays distinct from the list
   * page's own capture, which is a full-viewport screenshot of a different thing.
   */
  const rr = rows[0].getBoundingClientRect();
  const cr = cell.getBoundingClientRect();
  const top = Math.min(rr.y, cr.y);
  const bottom = Math.max(rr.y + rr.height, cr.y + cr.height);
  return {
    ok: true,
    controls: described,
    findings,
    box: {
      x: Math.max(0, Math.min(rr.x, cr.x) - 8),
      y: Math.max(0, top - 8),
      width: Math.max(rr.width, cr.width) + 16,
      height: (bottom - top) + 16,
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

      await page.goto(BASE + s.route, { waitUntil: 'domcontentloaded' }).catch(() => {});

      // A `redirect()->back()` refusal keeps the previous page's legitimate title, so the landed
      // path is the only reliable premise.
      const landed = (() => { try { return new URL(page.url()).pathname; } catch (e) { return page.url(); } })();
      if (landed.replace(/\/+$/, '') !== s.route.replace(/\/+$/, '')) {
        all.push({ slug: s.slug, combo: label, kind: 'premise', detail: 'landed on ' + landed + ', asked for ' + s.route });
        continue;
      }

      // DataTables fills the tbody over XHR; wait for a row that is not the empty-state.
      await page.waitForFunction(() => {
        const rows = [...document.querySelectorAll('table tbody tr')];
        return rows.length > 0 && rows.some(r => !r.querySelector('.ss-dt-empty') && r.querySelectorAll('td').length > 1);
      }, null, { timeout: 15000 }).catch(() => {});

      const m = await page.evaluate(MEASURE, { lang: combo.lang, latinOk: [...LATIN_OK] })
        .catch(e => ({ ok: false, why: String(e).slice(0, 120) }));

      if (!m.ok) {
        all.push({ slug: s.slug, combo: label, kind: 'premise', detail: m.why });
        continue;
      }

      /*
       * A FAILED SCREENSHOT IS REPORTED, NOT SWALLOWED. This was `.catch(() => {})` with the tally
       * running regardless, so a clip Playwright refuses left the summary claiming a capture that
       * is not on disk. MEASURED in shoot-fragments.cjs, whose variant-panel surfaces reported
       * `combos=4` across several runs while `find` returned zero files: the container sat ~2,025px
       * down a long form and a viewport clip cannot reach it. The matrix generator never believed
       * those claims -- its gate is the filesystem -- but the console summary is what a person
       * reads, and it was wrong.
       */
      const shotError = await page.screenshot({
        path: path.join(OUT, s.slug + '__' + combo.lang + '_' + combo.dir + '_' + combo.theme + '.png'),
        clip: m.box,
      }).then(() => null).catch((e) => String(e.message || e).split('\n')[0].slice(0, 140));

      if (shotError) {
        all.push({ slug: s.slug, combo: label, kind: 'premise',
          detail: 'the screenshot was refused, so there is no evidence for this combination: ' + shotError });
        continue;
      }

      for (const f of m.findings) all.push({ slug: s.slug, combo: label, kind: f.kind, detail: f.detail });

      if (!perSurface.has(s.slug)) perSurface.set(s.slug, { combos: 0, controls: 0, names: new Set() });
      const t = perSurface.get(s.slug);
      t.combos += 1;
      t.controls = m.controls.length;
      for (const c of m.controls) t.names.add(c.name);
    }

    await ctx.close();
  }

  await browser.close();

  console.log('\nSURFACES CAPTURED: ' + perSurface.size + ' of ' + (ONLY.length ? '(filtered)' : SURFACES.length));
  for (const [slug, t] of [...perSurface.entries()].sort()) {
    console.log('  ' + (t.combos === 4 ? ' ' : '!') + ' ' + slug.padEnd(30) + ' combos=' + t.combos + '  controls=' + t.controls);
  }

  const byKind = {};
  for (const f of all) byKind[f.kind] = (byKind[f.kind] || 0) + 1;
  console.log('\nFINDINGS: ' + all.length + '  ' + JSON.stringify(byKind));

  const groups = {};
  for (const f of all) {
    const key = f.kind + ' | ' + f.detail;
    (groups[key] = groups[key] || []).push(f.slug + '/' + f.combo);
  }
  for (const [key, where] of Object.entries(groups).sort((a, b) => b[1].length - a[1].length)) {
    console.log('\n' + key);
    console.log('   x' + where.length + '  ' + where.slice(0, 5).join(', ') + (where.length > 5 ? ', ...' : ''));
  }

  fs.writeFileSync(path.join(OUT, 'measurements.json'),
    JSON.stringify({ findings: all, surfaces: [...perSurface.entries()].map(([k, v]) => [k, { ...v, names: [...v.names] }]) }, null, 2));
})();
