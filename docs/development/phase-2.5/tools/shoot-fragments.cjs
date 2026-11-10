/**
 * CAPTURE AND MEASURE AN HTML FRAGMENT, in the container its parent page injects it into.
 *
 * WHY THESE CANNOT BE DRIVEN AS ROUTES. `/order-preview/{id}`, `/product-preview/{id}`,
 * `/orders/order_view/{id}` and `/user-info/{id}` each answer HTTP 200 with a body that has no
 * `<html dir>` and no theme stylesheet, because they are not pages -- the parent page's
 * JavaScript fetches them and writes them into a side panel or a modal. Driven as routes,
 * `render-matrix.cjs` correctly fails its direction and theme premises on them, and a capture
 * taken anyway is a screenshot of unstyled markup on a white page. That is not what any merchant
 * sees, so it is not evidence about the surface.
 *
 * TWO CONTAINERS, ONE MECHANISM. The design system's panel (`data-ss-panel-open` +
 * `data-ss-panel-url`, loaded by `public/js/store-syria-ds.js:273`) and the Bootstrap modal
 * (`data-ajax-popup`, loaded by `public/js/custom.js:138`) differ only in which element ends up
 * holding the markup, so one harness takes the container as configuration.
 *
 * WHAT IS MEASURED, scoped to the container rather than the page, because the shell around it is
 * already verified and its findings would drown the fragment's own:
 *
 *   contrast   every text node against its composited background, WCAG 1.4.3 floors
 *   overflow   anything past the container's own box -- off-frame in a screenshot by definition
 *   latin      Latin-script words in the Arabic UI, minus merchant data already on the page
 *   nameless   controls with no accessible name
 *
 * PREMISES, because an empty panel photographs as a tidy empty panel:
 *   - the landed path is the requested parent path (a `redirect()->back()` refusal keeps the
 *     previous page's legitimate title -- see PHASE-2.5-DEV-FIXTURE-PERMISSIONS.md)
 *   - a visible trigger exists outside the shell's Quick Add menu
 *   - the container is visible AND holds real text with no loading placeholder left
 *
 * usage:
 *   node shoot-fragments.cjs --out <dir>
 *   node shoot-fragments.cjs --out <dir> --only order-preview
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
const OUT = arg('out', '/tmp/fragments');
const ONLY = arg('only', '').split(',').map(s => s.trim()).filter(Boolean);
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

/**
 * Each entry is one matrix row.
 *
 *   route      the PARENT page, which is what gets navigated to
 *   trigger    the control that loads the fragment
 *   container  where the markup lands, and what gets captured
 */
const SURFACES = [
  {
    slug: 'order-preview-panel',
    route: '/order',
    trigger: '[data-ss-panel-url*="order-preview"]',
    container: '#ss-order-preview-panel',
  },
  {
    slug: 'product-preview-panel',
    route: '/product',
    trigger: '[data-ss-panel-url*="product-preview"]',
    container: '#ss-product-preview-panel',
  },
  {
    slug: 'order-view-modal',
    route: '/order',
    trigger: 'button[data-url*="order_view"], a[data-url*="order_view"]',
    container: '#commanModel',
  },
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

const LATIN_OK = ['id', 'url', 'sku', 'csv', 'pdf', 'png', 'jpg', 'qr', 'api', 'cod', 'vat',
  'n', 'a', 'mb', 'kb', 'utc', 'sar', 'usd', 'syp'];

const MEASURE = (args) => {
  const root = document.querySelector(args.container);
  if (!root) return { ok: false, why: 'container ' + args.container + ' is not in the DOM' };

  const cs0 = getComputedStyle(root);
  if (cs0.display === 'none' || cs0.visibility === 'hidden') {
    return { ok: false, why: 'container is present but not displayed' };
  }

  const text = (root.innerText || '').trim();
  // The DS panel writes `<p class="ss-panel__loading">` while the fetch is in flight, and the
  // modal writes a spinner. Either means the fragment has not arrived.
  if (root.querySelector('.ss-panel__loading, .ss-skeleton')) {
    return { ok: false, why: 'container still shows its loading placeholder' };
  }
  if (text.length < 20) {
    return { ok: false, why: 'container holds ' + text.length + ' chars of text (fragment never arrived)' };
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
  const clippedAway = (cs) => cs.clip === 'rect(0px, 0px, 0px, 0px)'
    || /inset\(\s*50%/.test(cs.clipPath || '') || (cs.clipPath || '') === 'circle(0px)';
  const hidden = (el) => {
    for (let e = el; e && e !== document.body; e = e.parentElement) {
      const cs = getComputedStyle(e);
      if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) <= 0.05) return true;
    }
    return false;
  };

  const findings = [];

  for (const el of root.querySelectorAll('*')) {
    if (![...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())) continue;
    if (el.closest('.visually-hidden, .sr-only, .ss-visually-hidden')) continue;
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) continue;
    const cs = getComputedStyle(el);
    if (hidden(el) || clippedAway(cs)) continue;
    const fg = parse(cs.color).slice(0, 3); if (fg.length < 3) continue;
    const size = parseFloat(cs.fontSize);
    const floor = (size >= 24 || (parseInt(cs.fontWeight, 10) >= 700 && size >= 18.66)) ? 3 : 4.5;
    const n = Number(ratio(fg, paintedBg(el)).toFixed(2));
    if (n >= floor) continue;
    findings.push({
      kind: 'contrast',
      detail: n + ':1 needs ' + floor + '  ' + el.tagName.toLowerCase()
        + '.' + (el.className || '').toString().trim().slice(0, 26).replace(/ +/g, '.')
        + '  "' + el.textContent.trim().replace(/\s+/g, ' ').slice(0, 26) + '"',
    });
  }

  const rr = root.getBoundingClientRect();
  for (const el of root.querySelectorAll('*')) {
    if (hidden(el)) continue;
    const r = el.getBoundingClientRect();
    if (r.width < 2) continue;
    const over = Math.round(Math.max(r.right - rr.right, rr.left - r.left));
    if (over > 4) {
      findings.push({
        kind: 'overflow',
        detail: over + 'px past the container  ' + el.tagName.toLowerCase()
          + '.' + (el.className || '').toString().trim().slice(0, 26).replace(/ +/g, '.'),
      });
    }
  }

  for (const el of root.querySelectorAll('a, button, input, select, textarea')) {
    if (hidden(el)) continue;
    const type = (el.getAttribute('type') || '').toLowerCase();
    if (type === 'hidden') continue;
    const valueIsName = ['submit', 'button', 'reset'].includes(type);
    const id = el.id;
    const named = (el.getAttribute('aria-label') || '').trim()
      || (el.getAttribute('aria-labelledby') || '').trim()
      || (el.getAttribute('title') || '').trim()
      || (el.getAttribute('placeholder') || '').trim()
      || (valueIsName ? (el.getAttribute('value') || '').trim() : '')
      || (['A', 'BUTTON'].includes(el.tagName) ? (el.textContent || '').trim() : '')
      || (id ? ((document.querySelector('label[for="' + CSS.escape(id) + '"]') || {}).textContent || '').trim() : '')
      || ((el.closest('label') || {}).textContent || '').trim();
    if (named) continue;
    findings.push({
      kind: 'nameless',
      detail: el.tagName.toLowerCase() + (type ? '[' + type + ']' : '')
        + (el.getAttribute('name') ? '[name=' + el.getAttribute('name') + ']' : ''),
    });
  }

  if (args.lang === 'ar') {
    const ok = new Set(args.latinOk);
    // Merchant data already on the parent page is not an untranslated string -- the rule
    // shoot-row-actions.cjs and shoot-modals.cjs both needed, for the same reason.
    const pageText = (document.body.innerText || '').toLowerCase();
    const seen = new Set();
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      const raw = (n.textContent || '').trim();
      if (!raw) continue;
      const el = n.parentElement;
      if (!el || hidden(el)) continue;
      if (el.closest('.visually-hidden, .sr-only, .ss-visually-hidden, code, pre, script, style, option')) continue;
      if (el.closest('input, textarea, select, .choices__item, .select2-selection')) continue;
      const bad = (raw.match(/[A-Za-z][A-Za-z'.-]+/g) || [])
        .filter(w => !w.toLowerCase().split(/[^a-z]+/).filter(Boolean).every(part => ok.has(part)))
        // A word that also appears on the parent page is the merchant's own data.
        .filter(w => pageText.split(w.toLowerCase()).length - 1 <= 1);
      if (!bad.length) continue;
      const key = bad.join(' ').slice(0, 36);
      if (seen.has(key)) continue;
      seen.add(key);
      findings.push({ kind: 'latin', detail: '"' + bad.slice(0, 5).join(' ') + '" in "' + raw.replace(/\s+/g, ' ').slice(0, 44) + '"' });
    }
  }

  return {
    ok: true,
    chars: text.length,
    findings,
    box: {
      x: Math.max(0, rr.x - 8), y: Math.max(0, rr.y - 8),
      width: Math.min(rr.width + 16, window.innerWidth),
      height: Math.min(rr.height + 16, window.innerHeight),
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
      const note = (kind, detail) => all.push({ slug: s.slug, combo: label, kind, detail });

      await page.goto(BASE + s.route, { waitUntil: 'domcontentloaded' }).catch(() => {});

      const landed = (() => { try { return new URL(page.url()).pathname; } catch (e) { return page.url(); } })();
      if (landed.replace(/\/+$/, '') !== s.route.replace(/\/+$/, '')) {
        note('premise', 'landed on ' + landed + ', asked for ' + s.route);
        continue;
      }

      // The list is a DataTable: wait for a real row before looking for a row trigger.
      await page.waitForFunction(() => {
        const rows = [...document.querySelectorAll('table tbody tr')];
        return rows.length > 0 && rows.some(r => !r.querySelector('.ss-dt-empty') && r.querySelectorAll('td').length > 1);
      }, null, { timeout: 15000 }).catch(() => {});

      const handles = await page.$$(s.trigger);
      let trigger = null;
      for (const h of handles) {
        const usable = await h.evaluate(
          (e) => !!e.offsetParent && !e.closest('.dropdown-menu, nav, header, .ss-topbar'),
        ).catch(() => false);
        if (usable) { trigger = h; break; }
      }

      if (!trigger) {
        note('premise', 'no visible trigger outside a menu matching ' + s.trigger
          + ' (' + handles.length + ' matched)');
        continue;
      }

      await trigger.click({ timeout: 5000 }).catch(() => {});

      await page.waitForFunction((a) => {
        const el = document.querySelector(a.container);
        if (!el) return false;
        const cs = getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden') return false;
        if (el.querySelector('.ss-panel__loading, .ss-skeleton')) return false;
        if (parseFloat(cs.opacity) < 0.99) return false;
        return (el.innerText || '').trim().length >= 20;
      }, { container: s.container }, { timeout: 15000 }).catch(() => {});

      // One frame after any transition, so the measured box is the painted box.
      await page.waitForTimeout(200);

      const m = await page.evaluate(MEASURE, { container: s.container, lang: combo.lang, latinOk: LATIN_OK })
        .catch(e => ({ ok: false, why: String(e).slice(0, 120) }));

      if (!m.ok) { note('premise', m.why); continue; }

      await page.screenshot({
        path: path.join(OUT, s.slug + '__' + combo.lang + '_' + combo.dir + '_' + combo.theme + '.png'),
        clip: m.box,
      }).catch(() => {});

      for (const f of m.findings) note(f.kind, f.detail);

      if (!perSurface.has(s.slug)) perSurface.set(s.slug, { combos: 0, chars: 0 });
      const t = perSurface.get(s.slug);
      t.combos += 1;
      t.chars = m.chars;
    }

    await ctx.close();
  }

  await browser.close();

  console.log('\nFRAGMENTS CAPTURED: ' + perSurface.size + ' of ' + (ONLY.length ? '(filtered)' : SURFACES.length));
  for (const [slug, t] of [...perSurface.entries()].sort()) {
    console.log('  ' + (t.combos === 4 ? ' ' : '!') + ' ' + slug.padEnd(26) + ' combos=' + t.combos + '  chars=' + t.chars);
  }

  const byKind = {};
  for (const f of all) byKind[f.kind] = (byKind[f.kind] || 0) + 1;
  console.log('\nFINDINGS: ' + all.length + '  ' + JSON.stringify(byKind));

  const groups = {};
  for (const f of all) { const k = f.kind + ' | ' + f.detail; (groups[k] = groups[k] || []).push(f.slug + '/' + f.combo); }
  for (const [k, where] of Object.entries(groups).sort((a, b) => b[1].length - a[1].length)) {
    console.log('\n' + k);
    console.log('   x' + where.length + '  ' + where.slice(0, 5).join(', ') + (where.length > 5 ? ', ...' : ''));
  }

  fs.writeFileSync(path.join(OUT, 'measurements.json'), JSON.stringify({ findings: all, surfaces: [...perSurface.entries()] }, null, 2));
})();
