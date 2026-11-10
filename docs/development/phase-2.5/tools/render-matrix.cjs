/**
 * RENDER MATRIX — drive the real merchant admin and record what each screen actually answers.
 *
 * Phase 2.5's directive is explicit that a CSS or widget edit is not evidence: "Do not declare a
 * screen completed solely because its CSS or widget code changed. Render the actual application."
 * This is the Laravel half of that. It signs in as a real merchant, walks a list of paths in all
 * four combinations (en/ltr and ar/rtl x light and dark), and reports for each one the HTTP status,
 * the document direction the layout really emitted, the theme attribute the design-system partial
 * really set, the page title, and any console error — then writes a screenshot.
 *
 * THE PREMISE CHECKS ARE THE POINT. A harness that signs in wrongly, or that measures the light
 * theme twice because the state write went to a different database, reports confident numbers about
 * nothing; that has happened twice in this project. So every row asserts the state it asked for was
 * the state it got, and a row whose premise fails is reported as PREMISE-FAIL, never as a reading.
 *
 * usage:
 *   node render-matrix.cjs --base http://127.0.0.1:8899 --out <dir> --paths /product-list,/product
 *   (state is set by ../../phase-2/tools/set-admin-preview-state.php, which this calls between rows)
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };

const BASE = arg('base', 'http://127.0.0.1:8899');
const OUT = arg('out', '/tmp/render-matrix');
const PATHS = arg('paths', '/dashboard').split(',').map((s) => s.trim()).filter(Boolean);
const EMAIL = arg('email', 'merchant@example.test');
const PASSWORD = arg('password', 'password');
const STORE = arg('store', '1');
const REPO = arg('repo', '/home/user/syriastore98');
const WIDTH = parseInt(arg('width', '1440'), 10);
const HEIGHT = parseInt(arg('height', '900'), 10);
const SHOT = arg('shot', '1') !== '0';

/**
 * TAB INTERACTION, for panels that are not reachable by URL.
 *
 * `--click-each '<selector>'` turns one path into one capture PER MATCHING ELEMENT: the harness
 * clicks each in turn, waits for the panel it loads, and names the capture after the element's
 * `data-tab` (falling back to its index).
 *
 * The settings hub is why this exists. Its panels are Bootstrap pills that POST to `setting.form`
 * and swap the panel over XHR, and the active one is remembered in the SESSION
 * (`setting/index.blade.php:29`), not in the URL -- so no amount of navigating reaches tab two,
 * and a plain capture of `/setting` is the same picture eleven times.
 */
const CLICK_EACH = arg('click-each', '');

/**
 * Where the clicked panel's content lands, so "did this tab load anything?" can be measured.
 *
 * The default covers the two shapes in this application: a Bootstrap pane that gains `.active`,
 * and a single container the XHR result is written straight into. The settings hub is the second
 * kind -- `getSettingForm()` does `$('#pills-tabContent').html(result.data.content)`
 * (`setting/index.blade.php:398`) -- so there is no `.tab-pane.active` to find, which is why the
 * first run of this flag reported all twenty-one panels empty while the screenshots were fine.
 */
const PANEL = arg('panel', '.tab-pane.active, .tab-content .active, #pills-tabContent');

const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

const COMBOS = [
  { lang: 'en', dir: 'ltr', theme: 'light' },
  { lang: 'en', dir: 'ltr', theme: 'dark' },
  { lang: 'ar', dir: 'rtl', theme: 'light' },
  { lang: 'ar', dir: 'rtl', theme: 'dark' },
];

function setState(theme, dir, lang) {
  // The state tool writes through Eloquent (the models are Cachable), and it MUST run with the
  // same DB/cache env as the server or it writes to a different sqlite file and reports success.
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
  const browser = await chromium.launch();
  const rows = [];

  for (const combo of COMBOS) {
    setState(combo.theme, combo.dir, combo.lang);

    const ctx = await browser.newContext({ viewport: { width: WIDTH, height: HEIGHT }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const consoleErrors = [];
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 160)); });
    page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + String(e).slice(0, 160)));

    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"], input[type="submit"]')]);

    const signedIn = !/\/login\b/.test(page.url());

    for (const p of PATHS) {
      consoleErrors.length = 0;
      const resp = await page.goto(BASE + p, { waitUntil: 'domcontentloaded' }).catch(() => null);
      const status = resp ? resp.status() : 0;
      await page.waitForTimeout(450);

      const state = await page.evaluate(() => ({
        htmlDir: document.documentElement.getAttribute('dir'),
        ssTheme: document.documentElement.getAttribute('data-ss-theme'),
        mainStyle: (document.getElementById('main-style-link') || {}).href || '',
        title: document.title,
        h1: (document.querySelector('h1, .page-header h5, .ss-page-header__title') || {}).textContent || '',
        bodyText: (document.body.innerText || '').slice(0, 200),
      })).catch(() => ({}));

      // PREMISES — a row that fails one of these is not a reading.
      const premise = [];
      const premiseLate = [];
      if (!signedIn) premise.push('not signed in');

      /*
       * DID WE LAND ON THE PAGE WE ASKED FOR?
       *
       * This premise exists because the one that was supposed to catch a refusal does not catch
       * the common case. The documented rule was "a capture titled `Store Syria - Dashboard` is a
       * refusal redirect" -- true, but only for handlers that redirect to the dashboard. The
       * WorkDo controllers refuse with `redirect()->back()`, which lands on the REFERRER, so the
       * browser stays on whatever page the harness visited last and the capture carries that
       * page's perfectly legitimate title.
       *
       * MEASURED: driving /coupon, /tag, /faqs in that order produced three pages all titled
       * `Store Syria - Coupon`, because the merchant fixture holds `Manage Coupon` but not
       * `Manage Tag` or `Manage Faqs`. Every existing premise passed -- signed in, direction
       * right, theme sheet right, HTTP 200 -- and the title rule saw nothing wrong. Three
       * screenshots of the coupon page would have become evidence for three different surfaces,
       * and the distinct-capture guard could not have caught it either, since a `back()` refusal
       * from a different referrer yields a different image each time.
       *
       * Comparing the landed path with the requested one catches every redirect-based refusal
       * regardless of where it points, which is why it is phrased as an equality rather than as a
       * list of known-bad destinations.
       */
      const landed = (() => { try { return new URL(page.url()).pathname; } catch (e) { return page.url(); } })();
      const wantPath = p.split('?')[0].replace(/\/+$/, '') || '/';
      const gotPath = landed.replace(/\/+$/, '') || '/';
      if (status === 200 && gotPath !== wantPath) {
        premise.push(`landed on ${gotPath}, asked for ${wantPath} (redirect — likely a permission refusal)`);
      }

      const wantRtl = combo.dir === 'rtl';
      if (status === 200 && (state.htmlDir === 'rtl') !== wantRtl) premise.push(`dir=${state.htmlDir} wanted ${combo.dir}`);
      const darkSheet = /style-dark\.css/.test(state.mainStyle || '');
      if (status === 200 && darkSheet !== (combo.theme === 'dark')) premise.push(`sheet=${(state.mainStyle||'').split('/').pop()} wanted ${combo.theme}`);

      const key = `${p.replace(/[^a-z0-9]+/gi, '_')}__${combo.lang}_${combo.dir}_${combo.theme}`;
      if (SHOT && status === 200 && !CLICK_EACH) {
        await page.screenshot({ path: path.join(OUT, key + '.png'), fullPage: false }).catch(() => {});
      }

      // ONE CAPTURE PER TAB, each one clicked and waited for.
      const tabsSeen = [];
      if (CLICK_EACH && status === 200) {
        const found = await page.$$(CLICK_EACH);

        for (let i = 0; i < found.length; i++) {
          // Re-query each time: clicking swaps the panel, and a handle taken before a DOM change
          // goes stale. Re-querying is what makes each click land on a live element.
          const fresh = await page.$$(CLICK_EACH);
          const el = fresh[i];
          if (!el) continue;

          const name = (await el.getAttribute('data-tab')) || ('tab' + i);

          await el.click({ timeout: 5000 }).catch(() => {});

          // WAIT FOR THE PANEL TO HAVE CONTENT, do not wait a fixed time and hope.
          //
          // `networkidle` plus a 400ms settle was not enough and, worse, it failed
          // INCONSISTENTLY: six panels reported empty in one combination and three in the next,
          // with a different set each run. A varying result is the signature of a race, and a
          // race here produces a screenshot of a skeleton that photographs as a clean screen.
          //
          // `beforeSend` fills the panel with three `.ss-skeleton` lines, so "has content" is not
          // simply "is not empty": the wait is for real text with no skeleton left in the panel.
          await page.waitForFunction(
            (args) => {
              const pane = document.querySelector(args.sel);
              if (!pane) return false;
              if (pane.querySelector('.ss-skeleton')) return false;
              return (pane.innerText || '').trim().length >= args.min;
            },
            { sel: PANEL, min: 20 },
            { timeout: 15000 },
          ).catch(() => {});

          const panelChars = await page.evaluate((sel) => {
            const pane = document.querySelector(sel);
            return pane ? (pane.innerText || '').trim().length : 0;
          }, PANEL).catch(() => 0);

          tabsSeen.push({ name: name, chars: panelChars });

          if (SHOT) {
            const safePath = p.replace(/[^a-z0-9]+/gi, '_');
            const tabKey = safePath + '_' + name + '__' + combo.lang + '_' + combo.dir + '_' + combo.theme;
            await page.screenshot({ path: path.join(OUT, tabKey + '.png'), fullPage: false })
              .catch(() => {});
          }
        }

        // A TAB THAT LOADED NOTHING IS NOT A CAPTURED TAB. An empty panel photographs as a clean
        // screen, which is the failure mode this whole harness exists to refuse.
        const empty = tabsSeen.filter(function (t) { return t.chars < 20; })
                              .map(function (t) { return t.name; });
        if (empty.length) premiseLate.push('empty panel: ' + empty.join(', '));
        if (tabsSeen.length === 0) premiseLate.push('no elements matched ' + CLICK_EACH);
      }

      // REQUESTED AND OBSERVED ARE SEPARATE FIELDS. This read `{...combo, dir: state.htmlDir}`,
      // where the spread supplies combo.dir and the next key OVERWRITES it with the observed
      // direction -- so the table's `dir` and `docdir` columns printed the same value while
      // appearing to compare what was asked for against what came back. The premise check above
      // was unaffected (it reads combo.dir directly), but the human-readable evidence was not
      // showing what it claimed to show.
      rows.push({
        path: p, lang: combo.lang, theme: combo.theme, status,
        wantDir: combo.dir,
        dir: state.htmlDir, ssTheme: state.ssTheme,
        sheet: (state.mainStyle || '').split('/').pop(),
        title: (state.title || '').trim().slice(0, 48),
        h1: (state.h1 || '').trim().replace(/\s+/g, ' ').slice(0, 48),
        consoleErrors: consoleErrors.slice(0, 3),
        premiseFail: premise.concat(premiseLate),
        tabs: tabsSeen.length
          ? tabsSeen.map(function (t) { return t.name + ':' + t.chars; }).join(' ')
          : undefined,
      });
    }
    await ctx.close();
  }

  await browser.close();

  // THE EVIDENCE IS WRITTEN BEFORE IT IS FORMATTED. This file used to be written after the print
  // loop, so a formatting error destroyed a completed run: 72 screenshots survived and the JSON
  // did not, because `r.dir.padEnd()` threw on a page that exposes no dir attribute. A tool whose
  // only job is to record what happened must not be able to lose the record while printing it.
  fs.writeFileSync(path.join(OUT, 'render-matrix.json'), JSON.stringify(rows, null, 1));

  let bad = 0;
  console.log('\npath                 lang want obs  theme  HTTP  ss-theme  premise  title');
  console.log('-'.repeat(108));
  for (const r of rows) {
    const flag = r.premiseFail.length ? 'PREMISE-FAIL' : (r.status === 200 ? 'ok' : 'HTTP ' + r.status);
    if (r.premiseFail.length || r.status !== 200) bad++;
    // Every cell goes through pad(), which stringifies first: a null `dir` is a READING (the page
    // exposed no dir attribute), not a reason to lose the whole report.
    const pad = (v, n) => String(v === null || v === undefined ? '-' : v).padEnd(n);
    console.log(
      `${pad(r.path, 20)} ${pad(r.lang, 4)} ${pad(r.wantDir, 4)} ${pad(r.dir, 4)} ${pad(r.theme, 6)} ` +
      `${pad(r.status, 5)} ${pad(r.ssTheme, 9)} ${pad(flag, 12)} ${r.title}`
    );
    if (r.premiseFail.length) console.log(`${' '.repeat(22)}-> ${r.premiseFail.join('; ')}`);
    if (r.consoleErrors.length) console.log(`${' '.repeat(22)}console: ${r.consoleErrors.join(' | ')}`);
  }
  console.log(`\n${rows.length} rows, ${bad} not clean. screenshots+json in ${OUT}`);
  process.exit(bad ? 1 : 0);
})();
