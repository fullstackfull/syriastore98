/**
 * OPEN EVERY AJAX MODAL, CAPTURE IT, AND MEASURE WHAT A SCREENSHOT CANNOT SHOW.
 *
 * ~30 matrix rows are create/edit modals. None is reachable by URL: `public/js/custom.js:138`
 * binds `[data-ajax-popup="true"]`, fetches the form over XHR, drops it into
 * `#commanModel .modal-body` (the misspelling is the application's) and shows the dialog. So a
 * navigation captures the list page; only a click captures the modal.
 *
 * TRIGGERS, and why the discriminator is the element type. A create modal is opened by an `<a>` in
 * the page header (`coupon/index.blade.php:41`), an edit modal by a `<button>` inside a table row
 * (`coupon/action.blade.php`). Both carry the same `data-ajax-popup` attribute, so the tag plus
 * its position is what tells them apart -- which is stable, because the two live in different
 * partials and neither is going to become the other.
 *
 * MEASURED, not only photographed. A modal is a FORM, so the things that break in one are the
 * things a picture cannot show:
 *
 *   labelled   every input reachable by a name -- a `for`, an `aria-label`, a wrapping label or a
 *              placeholder. An unlabelled field in a create form is unusable with a screen reader
 *              and looks perfect in a screenshot.
 *   contrast   every text node against its composited background, WCAG 1.4.3 floors. Modals are a
 *              known weak spot here: `.modal-content` measured `rgb(255,255,255)` under
 *              `data-ss-theme="dark"` at one point, which is how a dark-mode modal ends up white.
 *   overflow   anything extending past the dialog's own box -- off-frame in a screenshot by
 *              definition, and the usual symptom of a wide form at a narrow breakpoint.
 *   latin      Latin-script words in the Arabic UI, minus merchant data already on the page.
 *
 * PREMISES, because a modal that never opened leaves the list page on screen and that photographs
 * as a perfectly good screenshot of the wrong surface:
 *   - the landed path is the requested path (a `redirect()->back()` refusal keeps the previous
 *     page's legitimate title -- see PHASE-2.5-DEV-FIXTURE-PERMISSIONS.md)
 *   - a trigger of the requested kind exists on the page
 *   - `#commanModel` is visible AND its body holds real content, not the spinner
 *
 * usage:
 *   node shoot-modals.cjs --out <dir>
 *   node shoot-modals.cjs --out <dir> --only coupon
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
const OUT = arg('out', '/tmp/modals');
const ONLY = arg('only', '').split(',').map(s => s.trim()).filter(Boolean);
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

const MODAL = '#commanModel';
const CREATE_TRIGGER = 'a[data-ajax-popup="true"]';
/**
 * AN EDIT TRIGGER IS A BUTTON, WHEREVER THE LIST PUTS IT.
 *
 * This was `table tbody button[data-ajax-popup="true"]`, which assumed every list is a table.
 * `/users` is a CARD GRID -- the matrix even records it as "Users / staff list (card grid)" --
 * so its edit button sits in a card, the selector matched nothing, and the run reported
 * "no visible edit trigger" as though the surface were missing.
 *
 * The create/edit distinction does not need the table: a create trigger is an `<a>` in the page
 * header and an edit trigger is a `<button>` on a record. Both are then filtered to the visible
 * one outside a menu, which is what actually separates them from the shell's Quick Add items.
 */
const EDIT_TRIGGER = 'button[data-ajax-popup="true"]';

/** Each entry is one matrix row. `kind` picks the trigger. */
const SURFACES = [
  { slug: 'coupon-create', route: '/coupon', kind: 'create' },
  { slug: 'coupon-edit', route: '/coupon', kind: 'edit' },
  { slug: 'category-create', route: '/category', kind: 'create' },
  { slug: 'category-edit', route: '/category', kind: 'edit' },
  { slug: 'faq-create', route: '/faqs', kind: 'create' },
  { slug: 'faq-edit', route: '/faqs', kind: 'edit' },
  { slug: 'tag-create', route: '/tag', kind: 'create' },
  { slug: 'tag-edit', route: '/tag', kind: 'edit' },
  { slug: 'testimonial-create', route: '/testimonial', kind: 'create' },
  { slug: 'testimonial-edit', route: '/testimonial', kind: 'edit' },
  { slug: 'brand-create', route: '/product-brand', kind: 'create' },
  { slug: 'brand-edit', route: '/product-brand', kind: 'edit' },
  { slug: 'product-label-create', route: '/product-label', kind: 'create' },
  { slug: 'product-label-edit', route: '/product-label', kind: 'edit' },
  { slug: 'users-create', route: '/users', kind: 'create' },
  { slug: 'users-edit', route: '/users', kind: 'edit' },
  { slug: 'flash-sale-create', route: '/flash-sale', kind: 'create' },
  { slug: 'flash-sale-edit', route: '/flash-sale', kind: 'edit' },
  // INSIDE A SETTINGS TAB, not on a list page. `/pixel-setting` and `/webhook-setting` are not
  // pages a merchant navigates to -- they are panels of the settings hub, loaded over XHR when
  // their pill is clicked (see measure-settings-tabs.cjs). So the route is `/setting` and `tab`
  // names the pill to click first; the create trigger is then found inside the loaded panel.
  // Driven as standalone routes they reported "no visible create trigger", which was true and
  // which said nothing about the modal.
  { slug: 'pixel-create', route: '/setting', tab: 'pixel_field_setting', kind: 'create' },
  { slug: 'webhook-create', route: '/setting', tab: 'webhook_setting', kind: 'create' },
  { slug: 'product-question-edit', route: '/product-question', kind: 'edit' },
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

const LATIN_OK = ['id', 'url', 'sku', 'csv', 'png', 'jpg', 'jpeg', 'svg', 'webp', 'gif', 'mb', 'kb',
  'px', 'api', 'seo', 'html', 'css', 'js', 'qr', 'pdf', 'utc', 'n', 'a', 'cod', 'pwa', 'otp', 'sms'];

const MEASURE = (args) => {
  const modal = document.querySelector(args.modal);
  if (!modal) return { ok: false, why: 'no ' + args.modal + ' in the DOM' };

  const dialog = modal.querySelector('.modal-dialog') || modal;
  const body = modal.querySelector('.modal-body');
  const text = ((body || modal).innerText || '').trim();

  /*
   * "LOADED" IS A FORM WITH FIELDS, NOT A CHARACTER COUNT.
   *
   * The first version required 20 characters of innerText and rejected every small modal as
   * "never loaded". The tag create modal is one labelled field and two buttons -- NINE characters
   * of innerText -- and it was fully present: a `<form>` posting to /tag with a CSRF token. The
   * threshold was measuring the modal's SIZE and calling it loading.
   *
   * A form with at least one visible field is what "the panel arrived" actually means here, and
   * it is true of the smallest modal in this group and of the thirty-field coupon one alike.
   */
  const scope = body || modal;
  const form = scope.querySelector('form');
  const fieldCount = form
    ? [...form.querySelectorAll('input, select, textarea')].filter((el) => {
        const t = (el.getAttribute('type') || '').toLowerCase();
        return t !== 'hidden';
      }).length
    : 0;

  if (!form) {
    return { ok: false, why: 'modal holds no <form> (' + text.length + ' chars of text) — it never loaded' };
  }
  if (fieldCount === 0) {
    return { ok: false, why: 'modal form has no visible field — it loaded an error or an empty body' };
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

  for (const el of modal.querySelectorAll('*')) {
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

  // Every field a merchant types into must be reachable by a name.
  for (const el of modal.querySelectorAll('input, select, textarea')) {
    if (hidden(el)) continue;
    if ((el.getAttribute('type') || '').toLowerCase() === 'hidden') continue;
    const id = el.id;
    // For a submit/button/reset input the `value` attribute IS the accessible name -- that is
    // what the browser exposes and what a screen reader announces. Without this the file widget's
    // own `تحديث` / `إلغاء` buttons were reported as two unlabelled fields per modal.
    const type = (el.getAttribute('type') || '').toLowerCase();
    const valueIsName = ['submit', 'button', 'reset'].includes(type);

    const named = (el.getAttribute('aria-label') || '').trim()
      || (el.getAttribute('aria-labelledby') || '').trim()
      || (el.getAttribute('title') || '').trim()
      || (el.getAttribute('placeholder') || '').trim()
      || (valueIsName ? (el.getAttribute('value') || '').trim() : '')
      || (id ? ((modal.querySelector('label[for="' + CSS.escape(id) + '"]')
        || document.querySelector('label[for="' + CSS.escape(id) + '"]') || {}).textContent || '').trim() : '')
      || ((el.closest('label') || {}).textContent || '').trim();
    if (named) continue;
    findings.push({
      kind: 'unlabelled',
      detail: el.tagName.toLowerCase() + (el.getAttribute('name') ? '[name=' + el.getAttribute('name') + ']' : '[no name]'),
    });
  }

  const dr = dialog.getBoundingClientRect();
  for (const el of dialog.querySelectorAll('*')) {
    if (hidden(el)) continue;
    const r = el.getBoundingClientRect();
    if (r.width < 2) continue;
    const over = Math.round(Math.max(r.right - dr.right, dr.left - r.left));
    if (over > 4) {
      findings.push({
        kind: 'overflow',
        detail: over + 'px past the dialog  ' + el.tagName.toLowerCase()
          + '.' + (el.className || '').toString().trim().slice(0, 26).replace(/ +/g, '.'),
      });
    }
  }

  if (args.lang === 'ar') {
    const ok = new Set(args.latinOk);
    // Merchant data already on the page is not an untranslated string -- the same rule
    // shoot-row-actions.cjs needed, and for the same reason.
    const pageData = (document.body.innerText || '').toLowerCase();
    const seen = new Set();
    const walker = document.createTreeWalker(modal, NodeFilter.SHOW_TEXT);
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      const raw = (n.textContent || '').trim();
      if (!raw) continue;
      const el = n.parentElement;
      if (!el || hidden(el)) continue;
      if (el.closest('.visually-hidden, .sr-only, .ss-visually-hidden, code, pre, script, style, option')) continue;
      if (el.closest('input, textarea, select')) continue;
      const bad = (raw.match(/[A-Za-z][A-Za-z'.-]+/g) || [])
        .filter(w => !w.toLowerCase().split(/[^a-z]+/).filter(Boolean).every(part => ok.has(part)));
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
    fields: fieldCount,
    findings,
    box: {
      x: Math.max(0, dr.x - 10), y: Math.max(0, dr.y - 10),
      width: Math.min(dr.width + 20, window.innerWidth), height: Math.min(dr.height + 20, window.innerHeight),
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

      await page.waitForTimeout(1500);

      // A SETTINGS-TAB SURFACE needs its panel loaded first. Same wait as
      // measure-settings-tabs.cjs: real text with no `.ss-skeleton` left, because the panel
      // arrives over XHR and `networkidle` plus a settle was measured to be a race.
      if (s.tab) {
        const pill = (await page.$$('button[data-tab="' + s.tab + '"]'))[0];
        if (!pill) {
          note('premise', 'no settings pill named ' + s.tab);
          continue;
        }
        await pill.click({ timeout: 5000 }).catch(() => {});
        await page.waitForFunction(
          (a) => {
            const pane = document.querySelector(a.sel);
            if (!pane) return false;
            if (pane.querySelector('.ss-skeleton')) return false;
            return (pane.innerText || '').trim().length >= a.min;
          },
          { sel: '#pills-tabContent', min: 20 },
          { timeout: 15000 },
        ).catch(() => {});
      }

      const sel = s.kind === 'create' ? CREATE_TRIGGER : EDIT_TRIGGER;

      /*
       * THE PAGE'S OWN CREATE BUTTON, not the shell's Quick Add menu.
       *
       * `a[data-ajax-popup="true"]` matches FOUR elements on /tag, and the first three are the
       * header's Quick Add dropdown -- `/taxes/create`, `/category/create`, `/coupon/create` --
       * which sit in the DOM on every page and stay hidden until the menu opens. Clicking the
       * first match therefore timed out after 30s against an invisible element, and the run
       * recorded "modal body holds 0 chars (never loaded)" for every create surface. Measured
       * on /tag:
       *
       *     /taxes/create     visible=false  inMenu=true
       *     /category/create  visible=false  inMenu=true
       *     /coupon/create    visible=false  inMenu=true
       *     /tag/create       visible=true   inMenu=false   <- the page's own
       *
       * Visible AND outside a menu identifies it on every page in this group, and both halves
       * earn their place: the page button is the only visible one today, and the menu check
       * stops an opened dropdown from stealing the match later.
       */
      const handles = await page.$$(sel);
      let trigger = null;
      for (const h of handles) {
        const usable = await h.evaluate(
          (e) => !!e.offsetParent && !e.closest('.dropdown-menu, nav, header, .ss-topbar'),
        ).catch(() => false);
        if (usable) { trigger = h; break; }
      }

      if (!trigger) {
        note('premise', 'no visible ' + s.kind + ' trigger outside a menu matching ' + sel
          + ' (' + handles.length + ' matched, all hidden or in the Quick Add menu)');
        continue;
      }

      await trigger.click({ timeout: 5000 }).catch(() => {});

      /*
       * WAIT FOR THE MODAL TO BE LOADED **AND** TO HAVE FINISHED ANIMATING.
       *
       * Three separate conditions, and the first version only had one of them:
       *
       *   .show        Bootstrap adds it the instant the dialog is told to open
       *   body text    the form arrives separately, over XHR
       *   settled      `.modal.fade` transitions opacity 0 -> 1 while `.modal-dialog`
       *                transitions `translate(0, -50px)` -> none, over ~150ms
       *
       * Capturing on `.show` alone photographed the modal MID-FADE: the first run produced a
       * washed-out dialog with the page's top navigation bleeding into frame, because the clip
       * box was measured while the dialog was still sliding down from the top of the viewport.
       * It photographs as a real modal, just a dim and badly-cropped one -- and a dim capture is
       * worse than a failed one, because every contrast figure measured on that paint is wrong.
       */
      await page.waitForFunction((m) => {
        const el = document.querySelector(m);
        if (!el || !el.classList.contains('show')) return false;
        const b = el.querySelector('.modal-body');
        // Same definition as the premise below: a form with a visible field. Waiting on a
        // character count made every small modal wait the full 15s and then be rejected.
        if (!b || !b.querySelector('form')) return false;
        const dialog = el.querySelector('.modal-dialog') || el;
        const cs = getComputedStyle(el);
        const ds = getComputedStyle(dialog);
        // `none` or an identity matrix both mean the slide has finished.
        const settledTransform = ds.transform === 'none' || /matrix\(1, 0, 0, 1, 0, 0\)/.test(ds.transform);
        return parseFloat(cs.opacity) >= 0.99 && settledTransform;
      }, MODAL, { timeout: 15000 }).catch(() => {});

      // One frame after the transition ends, so the measured box is the painted box.
      await page.waitForTimeout(150);

      const m = await page.evaluate(MEASURE, { modal: MODAL, lang: combo.lang, latinOk: LATIN_OK })
        .catch(e => ({ ok: false, why: String(e).slice(0, 120) }));

      if (!m.ok) { note('premise', m.why); continue; }

      await page.screenshot({
        path: path.join(OUT, s.slug + '__' + combo.lang + '_' + combo.dir + '_' + combo.theme + '.png'),
        clip: m.box,
      }).catch(() => {});

      for (const f of m.findings) note(f.kind, f.detail);

      if (!perSurface.has(s.slug)) perSurface.set(s.slug, { combos: 0, fields: 0 });
      const t = perSurface.get(s.slug);
      t.combos += 1;
      t.fields = m.fields;
    }

    await ctx.close();
  }

  await browser.close();

  console.log('\nMODALS CAPTURED: ' + perSurface.size + ' of ' + (ONLY.length ? '(filtered)' : SURFACES.length));
  for (const [slug, t] of [...perSurface.entries()].sort()) {
    console.log('  ' + (t.combos === 4 ? ' ' : '!') + ' ' + slug.padEnd(26) + ' combos=' + t.combos + '  fields=' + t.fields);
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
