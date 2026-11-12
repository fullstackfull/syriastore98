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

/**
 * A PLACEHOLDER IS NOT AN ACCESSIBLE NAME, and this harness used to accept one.
 *
 * It sat in the name chain from the day the file was written, so every surface this project has
 * claimed was checked against a rule that is too lenient -- a placeholder disappears the moment a
 * merchant types, assistive technology treats it inconsistently, and neither WCAG nor the ARIA
 * practices accept it as a label.
 *
 * TIGHTENING IT WAS MEASURED BEFORE IT WAS DEFAULTED. Run across all 24 modals the harness knows,
 * the strict rule found exactly ONE field relying on a placeholder:
 * `input[name=coupon_code]` on coupon-edit, in all four combinations -- the same create-was-fixed,
 * edit-was-not shape as the flash-sale selects. With that one labelled the strict rule reports
 * zero, so it is now the DEFAULT rather than an opt-in, and `SS_STRICT_NAMES=0` restores the old
 * leniency for anyone who needs to compare against an older run.
 */
const STRICT_NAMES = process.env.SS_STRICT_NAMES !== '0';
/**
 * A TRIGGER IS IDENTIFIED BY WHERE IT POINTS, NOT BY ITS TAG.
 *
 * The selectors here were `a[data-ajax-popup="true"]` for create and
 * `button[data-ajax-popup="true"]` for edit, on the reasoning that "a create trigger is an `<a>`
 * in the page header and an edit trigger is a `<button>` on a record". That held for the surfaces
 * it was written against and is NOT a property of this application:
 *
 *     users/action.blade.php:43    edit   <a href="#!" data-url=".../users/{id}/edit">
 *     users/action.blade.php:68    reset  <a href="#"  data-url=".../reset-password/{enc}">
 *     faq/action.blade.php:3       edit   <button      data-url=".../faqs/{id}/edit">
 *
 * So `/users` reported "no visible edit trigger (0 matched)" while its edit control was sitting
 * in the DOM as an anchor -- the surface read as missing when only the selector was.
 *
 * `data-url` is the honest discriminator: it is what the AJAX handler fetches, so it says what
 * the modal will BE regardless of which element the markup chose. Both kinds are then filtered to
 * the visible one outside a menu, which is what separates a page's own control from the shell's
 * Quick Add items.
 */
const ANY_TRIGGER = '[data-ajax-popup="true"]';

/** A create trigger's URL ends at `/create`; an edit trigger's carries a record and ends at `/edit`. */
const TRIGGER_URL = {
  create: '/create(\\?|$)',
  edit: '/edit(\\?|$)',
};

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
  /* ---- P2.5 closure wave (2026-11-10). Each of these is a PENDING inventory row, and each
     needed something the harness could not do before: a trigger identified by its URL rather than
     its tag, a row menu opened, or a URL pattern of its own -- the reset-password control points
     at /reset-password/{encrypted id} and the attribute-option controls at
     /add-attribute-option/{id} and /edit-attribute-option/{id}, none of which the create/edit
     default reaches. Naming the pattern per surface is honest; widening the default would start
     matching other pages' controls. ---- */
  { slug: 'users-resetpassword', route: '/users', kind: 'edit', urlPattern: '/store-reset-password/' },
  { slug: 'webhook-edit', route: '/setting', tab: 'webhook_setting', kind: 'edit' },
  { slug: 'pixel-edit', route: '/setting', tab: 'pixel_field_setting', kind: 'edit' },
  // The attribute-option controls are NOT on /product-attributes -- measured, the only triggers
  // there are the attribute's own create and edit. They live on the OPTION screen for one
  // attribute, `attribute-option/{id}` (ProductAttributeOptionController::show), which the
  // matrix records as its own pilot screen.
  { slug: 'attribute-option-create', route: '/attribute-option/1', kind: 'create', urlPattern: '/add-attribute-option' },
  { slug: 'attribute-option-edit', route: '/attribute-option/1', kind: 'edit', urlPattern: '/edit-attribute-option' },

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

  // ---- P2.5-WEB-MODALS-2 (2026-11-10): the modals the route sweep left PENDING -------------
  // `/product-attributes/create` and `/create-language` both answered 200 with no <html> in the
  // sweep -- the 1/4 fingerprint of a modal fragment -- so they are driven from their list page
  // here rather than navigated to.
  { slug: 'attribute-create', route: '/product-attributes', kind: 'create' },
  { slug: 'attribute-edit', route: '/product-attributes', kind: 'edit' },
  /* The list is `/languages`, not `/manage-language` -- the latter is the TRANSLATION editor
     (LanguageController::manageLanguage, routes/web.php:1391) and carries no create control at
     all, which is why this surface reported "0 candidates" every run while its matrix row was
     already claimed. Its trigger also points at `create-language`, not `/create`, so it names its
     own pattern. */
  /* `language-create` IS DELIBERATELY NOT HERE. It was in this list, and taking it out is a
     scope correction, not a capture that was skipped.
       - The language list is `manage-language/{lang?}/{module?}` (routes/web.php:1391); the
         surface was pointed at '/languages', which is not a route, so the run reported no
         trigger. Finding that is what prompted reading the permission.
       - `manageLanguage()` is gated on `isAbleTo('Manage Language')`, and 'Manage Language' is in
         `config/superadminaccess.php:8` -- the operator denylist that `RolePolicy::isGrantableBy()`
         enforces on the WRITE path. So no merchant role in production can hold it, and the screen
         is an OPERATOR surface: Phase 06, not Phase 02 Merchant Experience.
       - The dev fixture's merchant holds it anyway. MEASURED across all 101 permissions that
         fixture user carries, the overlap with the denylist is exactly ONE name, this one, so no
         other captured surface is mis-scoped by it. Recorded in
         PHASE-2.5-DEV-FIXTURE-PERMISSIONS.md.
     The `/manage-language` HTTP 500 found on the way is still fixed, and
     tests/Feature/Merchant/OptionalRouteParametersTest.php keeps it fixed -- an out-of-scope
     screen is not a reason to leave a 500 on a registered route. */
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

    /* TEXT PUSHED OFF-SCREEN IS NOT TEXT, and measuring its colour is measuring nothing.
       ---------------------------------------------------------------------------------------
       MEASURED: `button.choices__button "Remove item"` was reported at 1.37:1 in both dark
       combinations of the flash-sale edit modal, every run. It is not a defect. Choices.js
       renders that control as a background-image SVG and hides its label with
       `.choices__button { text-indent: -9999px }` (public/assets/css/style.css:21960) -- so
       `color` belongs to a string no one can see, and the real control is an image this
       measurement cannot reach at all.

       The older `.visually-hidden` / `.sr-only` filter above does not catch it, because the
       library hides the text with an indent rather than with a utility class. A library that
       does the same thing with `font-size: 0` is caught here too.

       WHAT THIS DELIBERATELY DOES NOT DO: claim the control is fine. An icon-only control is
       still SC 1.4.11 at 3:1, and that is a question about the IMAGE. It is answered from the
       captured pixels instead -- see evidence/web-closure-2026-11-10/README.md -- because a
       computed-style reader cannot answer it and should not pretend to. */
    const indent = parseFloat(cs.textIndent);
    if ((Number.isFinite(indent) && indent <= -999) || parseFloat(cs.fontSize) < 1) continue;

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

    /* A PLACEHOLDER IS COUNTED, AND IT SHOULD NOT BE -- MEASURED SEPARATELY RATHER THAN
       SILENTLY CHANGED.
       -------------------------------------------------------------------------------------
       A placeholder disappears the moment a merchant types, assistive technology treats it
       inconsistently, and neither WCAG nor the ARIA practices accept it as a label. It has
       been in this chain since the harness was written, so every surface this project has
       already claimed was checked against a rule that is too lenient.

       Tightening it silently would redden previously-claimed rows with no record of why, so
       the strict answer is computed ALONGSIDE the lenient one and reported as its own kind.
       `SS_STRICT_NAMES=1` promotes it to a failure; by default it is a count, which is what
       the closure report quotes. */
    const realName = (el.getAttribute('aria-label') || '').trim()
      || (el.getAttribute('aria-labelledby') || '').trim()
      || (el.getAttribute('title') || '').trim()
      || (valueIsName ? (el.getAttribute('value') || '').trim() : '')
      || (id ? ((modal.querySelector('label[for="' + CSS.escape(id) + '"]')
        || document.querySelector('label[for="' + CSS.escape(id) + '"]') || {}).textContent || '').trim() : '')
      || ((el.closest('label') || {}).textContent || '').trim();

    const placeholder = (el.getAttribute('placeholder') || '').trim();

    if (!realName && placeholder) {
      findings.push({
        kind: args.strictNames ? "unlabelled" : "placeholder-only",
        detail: el.tagName.toLowerCase()
          + (el.getAttribute('name') ? '[name=' + el.getAttribute('name') + ']' : '[no name]')
          + '  placeholder="' + placeholder.slice(0, 24) + '"',
      });
      continue;
    }

    if (realName) continue;
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
    const seen = new Set();
    const walker = document.createTreeWalker(modal, NodeFilter.SHOW_TEXT);
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      const raw = (n.textContent || '').trim();
      if (!raw) continue;
      const el = n.parentElement;
      if (!el || hidden(el)) continue;
      if (el.closest('.visually-hidden, .sr-only, .ss-visually-hidden, code, pre, script, style, option')) continue;
      if (el.closest('input, textarea, select')) continue;
      /*
       * A SELECTED-VALUE CHIP IS MERCHANT DATA, NOT UI COPY.
       *
       * Choices.js renders each selected option as a `<div class="choices__item">` -- NOT an
       * `<option>` -- so skipping form controls does not reach it, and the testimonial edit
       * modal reported its own category `Textiles` as an untranslated string. It is one of four
       * categories in the fixture (`Textiles`, `Soaps`, `Homeware`, `Scarves & Shawls`), all
       * named by the merchant.
       *
       * This is the same mistake shoot-row-actions.cjs made with
       * `تعديل التصنيف Scarves & Shawls`, and it has the same cost if left: the "fix" a reader
       * would reach for is to stop showing the record's name, which makes the surface worse.
       *
       * An earlier version of this file computed a `pageData` string for exactly this purpose
       * and then never used it -- dead code that looked like the check was handled.
       */
      if (el.closest('.choices__item, .select2-selection, .select2-selection__choice')) continue;
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

      const sel = ANY_TRIGGER;
      const wantUrl = s.urlPattern || TRIGGER_URL[s.kind];

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
      const findTrigger = async () => {
        const handles = await page.$$(sel);
        for (const h of handles) {
          const usable = await h.evaluate(
            (e, pattern) => {
              if (!e.offsetParent) return false;
              if (e.closest('nav, header, .ss-topbar')) return false;
              // The URL is what decides create vs edit. A trigger with no data-url cannot be
              // either, and is skipped rather than guessed at.
              const url = e.getAttribute('data-url') || '';
              return new RegExp(pattern).test(url);
            },
            wantUrl,
          ).catch(() => false);
          if (usable) return h;
        }
        return null;
      };

      let trigger = await findTrigger();

      /*
       * A ROW MENU IS NOT THE SHELL'S MENU, and refusing both lost a real surface.
       *
       * The filter above used to exclude `.dropdown-menu` outright, to skip the header's Quick Add
       * items. But `/users` is a CARD GRID whose per-card actions live in their own kebab menu
       * (`users/index.blade.php:106-122`), so its edit and reset-password controls sit inside a
       * `.dropdown-menu` too -- and the run reported "no visible edit trigger" for a control that
       * was there all along. Measured on /users: 11 triggers matched, every one rejected.
       *
       * The shell's menus are excluded by LOCATION (`nav, header, .ss-topbar`), which is what
       * actually distinguishes them. A row menu is then OPENED, which is what a merchant does, and
       * only its contents become clickable -- so this reproduces the journey rather than reaching
       * past it into hidden markup.
       */
      if (!trigger) {
        const toggles = await page.$$('[data-bs-toggle="dropdown"], [data-toggle="dropdown"]');

        for (const t of toggles) {
          const outsideShell = await t.evaluate(
            (e) => !!e.offsetParent && !e.closest('nav, header, .ss-topbar'),
          ).catch(() => false);

          if (!outsideShell) continue;

          await t.click({ timeout: 4000 }).catch(() => {});
          await page.waitForTimeout(400);

          trigger = await findTrigger();
          if (trigger) break;

          // Leave no menu open behind us: an open menu would shadow the next page's own control.
          await page.keyboard.press('Escape').catch(() => {});
        }
      }

      if (!trigger) {
        // What the page DID offer, so the finding names the gap instead of only asserting one.
        const offered = await page.$$eval(sel, (nodes) => nodes.map(
          (e) => (e.getAttribute('data-url') || '(no data-url)').replace(location.origin, '')
            + (e.offsetParent ? '' : ' [hidden]'),
        )).catch(() => []);

        note('premise', 'no ' + s.kind + ' trigger whose data-url matches ' + wantUrl
          + ' — ' + offered.length + ' candidate(s): ' + offered.slice(0, 6).join(', '));
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

      const m = await page.evaluate(MEASURE, { modal: MODAL, lang: combo.lang, latinOk: LATIN_OK, strictNames: STRICT_NAMES })
        .catch(e => ({ ok: false, why: String(e).slice(0, 120) }));

      if (!m.ok) { note('premise', m.why); continue; }

      /*
       * A FAILED SCREENSHOT IS REPORTED, NOT SWALLOWED. This was `.catch(() => {})` with the tally
       * running regardless, so a clip Playwright refuses left the summary claiming a capture that
       * is not on disk. MEASURED in shoot-fragments.cjs, whose variant-panel surfaces reported
       * `combos=4` across several runs while `find` returned zero files: the container sat ~2,025px
       * down a long form and a viewport clip cannot reach it. The matrix generator never believed
       * those claims -- its gate is the filesystem -- but the console summary is what a person
       * reads, and it was wrong.
       */
      let shotError = null;

      await page.screenshot({
        path: path.join(OUT, s.slug + '__' + combo.lang + '_' + combo.dir + '_' + combo.theme + '.png'),
        clip: m.box,
      }).then(() => null).catch((e) => { shotError = String(e.message || e).split('\n')[0].slice(0, 140); });

      if (shotError) {
        note('premise', 'the screenshot was refused, so there is no evidence for this combination: '
          + shotError);
        continue;
      }

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
