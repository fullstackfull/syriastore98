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
      if (!signedIn) premise.push('not signed in');
      const wantRtl = combo.dir === 'rtl';
      if (status === 200 && (state.htmlDir === 'rtl') !== wantRtl) premise.push(`dir=${state.htmlDir} wanted ${combo.dir}`);
      const darkSheet = /style-dark\.css/.test(state.mainStyle || '');
      if (status === 200 && darkSheet !== (combo.theme === 'dark')) premise.push(`sheet=${(state.mainStyle||'').split('/').pop()} wanted ${combo.theme}`);

      const key = `${p.replace(/[^a-z0-9]+/gi, '_')}__${combo.lang}_${combo.dir}_${combo.theme}`;
      if (SHOT && status === 200) {
        await page.screenshot({ path: path.join(OUT, key + '.png'), fullPage: false }).catch(() => {});
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
        premiseFail: premise,
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
