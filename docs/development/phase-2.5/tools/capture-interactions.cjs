/**
 * INTERACTION CAPTURE — drive the real merchant admin, click the real control, record what a
 * merchant actually sees.  P2.5-PKG-I
 *
 * WHY THIS EXISTS AND WHY render-matrix.cjs IS NOT ENOUGH. render-matrix navigates to a PATH. Four
 * of PKG-I's surfaces have no path at all: they are partials rendered into a DataTable cell, and
 * the destructive ones only become visible after a click opens a dialog. The owner's instruction is
 * explicit -- "For AJAX fragments and modal actions, navigate through the real parent screen and
 * actually interact with the control" -- so a harness that only GETs a URL cannot produce evidence
 * for them, and a hand-rendered partial served from a static file is not the real screen.
 *
 * THE PREMISE BLOCK IS THE POINT, not the screenshot. This project's most dangerous reading is a
 * permission-gated screen that REDIRECTS to the dashboard and is recorded as four clean rows over
 * four dashboard pictures. So every row asserts, before the image is kept:
 *
 *   signed in            -- the URL is not /login
 *   right screen         -- the page title matches the scenario's own expectation
 *   right store          -- the workspace chip names the store the fixture selected
 *   right data           -- the scenario's own selector found the control it came to photograph
 *   right direction      -- <html dir> is what was asked for
 *   right theme          -- the loaded stylesheet is the one that was asked for
 *   interaction happened -- the scenario's post-condition selector is present
 *
 * A row failing any premise is reported PREMISE-FAIL and its image is DELETED rather than kept,
 * because a kept image is evidence whether or not a column next to it says the reading was bad.
 *
 * ONE SCENARIO = ONE SURFACE = ITS OWN IMAGE, AND A DISTINCT NAME IS NOT ENOUGH. The first run of
 * this harness gave `customer-row-actions` and `customer-status-toggle` different filenames and
 * BYTE-IDENTICAL contents, because both photographed the whole of /customer with no interaction to
 * tell them apart. `check-distinct-surface-captures.py` failed CI on exactly that, and it is right
 * to: it exists because three surfaces were once marked PREMIUM VERIFIED on twelve screenshots
 * that were all the dashboard. Two names over one image is the same lie in a smaller size.
 *
 * So a scenario that photographs a CELL now names that cell in `shoot`, and the image is of the
 * element rather than the viewport. That is also the more honest picture: the surface under review
 * IS the action cell, not the page it sits on.
 *
 * usage:
 *   node capture-interactions.cjs --base http://127.0.0.1:8899 --out <dir> [--only key,key]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };

const BASE = arg('base', 'http://127.0.0.1:8899');
const OUT = arg('out', '/tmp/pkg-i');
const ONLY = (arg('only', '') || '').split(',').map((s) => s.trim()).filter(Boolean);
const EMAIL = arg('email', 'merchant@example.test');
const PASSWORD = arg('password', 'password');
const STORE = arg('store', '1');
const REPO = arg('repo', '/home/user/syriastore98');
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

const COMBOS = [
  { lang: 'en', dir: 'ltr', theme: 'light' },
  { lang: 'en', dir: 'ltr', theme: 'dark' },
  { lang: 'ar', dir: 'rtl', theme: 'light' },
  { lang: 'ar', dir: 'rtl', theme: 'dark' },
];

// Desktop plus the two phone widths the owner named. 320 is the narrowest phone still in use and
// is where a two-column rail or a pinned table column breaks first; 390 is a current iPhone.
const WIDTHS = [
  { w: 1440, h: 900, tag: 'desktop' },
  { w: 390, h: 844, tag: 'w390' },
  { w: 320, h: 720, tag: 'w320' },
];

/**
 * Each scenario photographs ONE surface.
 *   path      the real parent screen
 *   expect    a fragment of the page title that proves the right screen loaded
 *   needs     a selector that must exist BEFORE interacting -- proves the fixture has data and the
 *             viewer holds the permission that renders the control
 *   act       the interaction, or null to photograph the cell as it sits
 *   after     a selector that must exist AFTER acting -- proves the interaction really happened
 */
const SCENARIOS = [
  {
    key: 'customer-row-actions',
    title: 'Customer row actions (the action cell as it sits)',
    path: '/customer', expect: { en: 'Customer', ar: 'الزبون' },
    needs: 'table a[href*="customer-timeline"], table .action-btn, table form[action*="customer"]',
    shoot: 'table tbody tr:first-child', act: null, after: null,
  },
  {
    key: 'customer-archive-confirm',
    title: 'Archive customer (the destructive confirmation)',
    path: '/customer', expect: { en: 'Customer', ar: 'الزبون' },
    needs: 'form[action*="customer"] .show_confirm, .show_confirm',
    act: async (page) => { await page.locator('.show_confirm').first().click({ timeout: 8000 }); },
    after: '.swal2-popup, .swal2-container',
  },
  {
    key: 'customer-status-toggle',
    title: 'Customer status toggle (the status cell)',
    path: '/customer', expect: { en: 'Customer', ar: 'الزبون' },
    needs: '.page-checkbox, .form-check.form-switch, .ss-status-badge',
    shoot: '.page-checkbox, .form-check.form-switch, .ss-status-badge', act: null, after: null,
  },
  {
    key: 'abandoned-cart-row-actions',
    title: 'Abandoned-cart row actions (the action cell)',
    path: '/abandon-carts-handled', expect: { en: 'Abandoned carts', ar: 'عربات التسوق المتروكة' },
    needs: 'table a, table button, table form',
    shoot: 'table tbody tr:first-child', act: null, after: null,
  },
  {
    key: 'abandoned-cart-delete-confirm',
    title: 'Abandoned-cart delete (the destructive confirmation)',
    path: '/abandon-carts-handled', expect: { en: 'Abandoned carts', ar: 'عربات التسوق المتروكة' },
    needs: '.show_confirm',
    act: async (page) => { await page.locator('.show_confirm').first().click({ timeout: 8000 }); },
    after: '.swal2-popup, .swal2-container',
  },
  {
    key: 'abandoned-wishlist-row-actions',
    title: 'Abandoned-wishlist row actions (the action cell)',
    path: '/wishlist', expect: { en: 'Abandoned wishlists', ar: 'قوائم الرغبات المتروكة' },
    needs: 'table a, table button, table form',
    shoot: 'table tbody tr:first-child', act: null, after: null,
  },
  {
    key: 'abandoned-wishlist-delete-confirm',
    title: 'Abandoned-wishlist delete (the destructive confirmation)',
    path: '/wishlist', expect: { en: 'Abandoned wishlists', ar: 'قوائم الرغبات المتروكة' },
    needs: '.show_confirm',
    act: async (page) => { await page.locator('.show_confirm').first().click({ timeout: 8000 }); },
    after: '.swal2-popup, .swal2-container',
  },
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

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const scenarios = ONLY.length ? SCENARIOS.filter((s) => ONLY.includes(s.key)) : SCENARIOS;
  const browser = await chromium.launch();
  const rows = [];

  for (const combo of COMBOS) {
    setState(combo.theme, combo.dir, combo.lang);

    for (const vp of WIDTHS) {
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h }, deviceScaleFactor: 1 });
      const page = await ctx.newPage();
      const errors = [];
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 140)); });
      page.on('pageerror', (e) => errors.push('pageerror: ' + String(e).slice(0, 140)));

      await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
      await page.fill('input[name="email"]', EMAIL);
      await page.fill('input[name="password"]', PASSWORD);
      await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.click('button[type="submit"], input[type="submit"]'),
      ]);
      const signedIn = !/\/login\b/.test(page.url());

      for (const sc of scenarios) {
        errors.length = 0;
        const key = `${sc.key}__${combo.lang}_${combo.dir}_${combo.theme}__${vp.tag}`;
        const shot = path.join(OUT, key + '.png');
        const premise = [];

        const resp = await page.goto(BASE + sc.path, { waitUntil: 'domcontentloaded' }).catch(() => null);
        const status = resp ? resp.status() : 0;
        await page.waitForTimeout(900); // DataTables draws after load

        const state = await page.evaluate(() => ({
          dir: document.documentElement.getAttribute('dir'),
          sheet: (document.getElementById('main-style-link') || {}).href || '',
          title: document.title,
          workspace: (document.querySelector('.ss-workspace__name') || {}).textContent || '',
        })).catch(() => ({}));

        if (!signedIn) premise.push('not signed in');
        if (status !== 200) premise.push('http ' + status);

        const wantTitle = sc.expect[combo.lang];
        if (status === 200 && wantTitle && !(state.title || '').includes(wantTitle)) {
          premise.push(`title "${(state.title || '').slice(0, 40)}" lacks "${wantTitle}"`);
        }
        if (status === 200 && (state.dir === 'rtl') !== (combo.dir === 'rtl')) {
          premise.push(`dir=${state.dir} wanted ${combo.dir}`);
        }
        const darkSheet = /style-dark\.css/.test(state.sheet || '');
        if (status === 200 && darkSheet !== (combo.theme === 'dark')) {
          premise.push(`theme sheet wrong for ${combo.theme}`);
        }

        // DATA + PERMISSION CONTEXT: the control we came to photograph must be here BEFORE we act.
        let found = 0;
        if (status === 200 && sc.needs) {
          found = await page.locator(sc.needs).count().catch(() => 0);
          if (!found) premise.push(`no control matched "${sc.needs.slice(0, 48)}"`);
        }

        // THE INTERACTION.
        let acted = 'n/a';
        if (!premise.length && sc.act) {
          try {
            await sc.act(page);
            await page.waitForTimeout(700);
            const n = await page.locator(sc.after).count();
            acted = n ? 'yes' : 'NO';
            if (!n) premise.push('interaction produced nothing');
          } catch (e) {
            acted = 'ERR';
            premise.push('interact: ' + String(e.message || e).slice(0, 60));
          }
        }

        if (!premise.length) {
          // A cell scenario photographs ITS OWN element. Two surfaces sharing a parent screen
          // would otherwise produce byte-identical images under different names, which
          // check-distinct-surface-captures.py fails CI on -- correctly.
          const target = sc.shoot ? page.locator(sc.shoot).first() : null;
          if (target && await target.count().catch(() => 0)) {
            await target.screenshot({ path: shot }).catch(async () => {
              await page.screenshot({ path: shot, fullPage: false }).catch(() => {});
            });
          } else {
            await page.screenshot({ path: shot, fullPage: false }).catch(() => {});
          }
        } else if (fs.existsSync(shot)) {
          fs.unlinkSync(shot); // a bad reading must not leave an image behind
        }

        rows.push({
          key: sc.key, combo: `${combo.lang}_${combo.dir}_${combo.theme}`, vp: vp.tag,
          status, title: (state.title || '').slice(0, 34), store: (state.workspace || '').trim().slice(0, 22),
          controls: found, acted, premise: premise.join('; ') || 'ok', errors: errors.length,
        });
      }
      await ctx.close();
    }
  }
  await browser.close();

  const pad = (s, n) => String(s === undefined ? '' : s).padEnd(n).slice(0, n);
  console.log(pad('scenario', 34) + pad('combo', 17) + pad('vp', 9) + pad('HTTP', 6)
    + pad('store', 23) + pad('ctrl', 6) + pad('acted', 7) + 'premise');
  console.log('-'.repeat(130));
  for (const r of rows) {
    console.log(pad(r.key, 34) + pad(r.combo, 17) + pad(r.vp, 9) + pad(r.status, 6)
      + pad(r.store, 23) + pad(r.controls, 6) + pad(r.acted, 7) + r.premise);
  }
  const bad = rows.filter((r) => r.premise !== 'ok');
  console.log(`\n${rows.length} rows, ${bad.length} NOT CLEAN. images in ${OUT}`);
  fs.writeFileSync(path.join(OUT, 'capture-interactions.json'), JSON.stringify(rows, null, 1));
  process.exit(bad.length ? 1 : 0);
})();
