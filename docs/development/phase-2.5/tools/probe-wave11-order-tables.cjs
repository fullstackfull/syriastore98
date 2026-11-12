/**
 * P2.5-WAVE-11 PROBE — the three merchant order tables, driven in a real browser.
 *
 * Unlike render-matrix.cjs (which walks a list of plain GET paths), two of these three surfaces
 * cannot be reached by a bare URL:
 *   - order/view and order/refund_details take a `Crypt::decrypt($id)` id, so the caller passes
 *     the encrypted id of the fixture order as --enc (see probe-wave11.sh, which computes it with
 *     the same APP_KEY the dev server loads from .env).
 *   - order/order_show is an AJAX quick-view MODAL with no standalone URL. The probe opens it the
 *     way a merchant does: navigate the order grid, click the eye action
 *     (a[data-ajax-popup="true"][data-url*="order_view"]), wait for #commanModel to populate.
 *
 * Every shot carries a premise (signed in, document dir matches, theme sheet matches, the surface
 * actually rendered). A shot whose premise fails is reported PREMISE-FAIL and counts as not clean,
 * never as evidence -- the same discipline render-matrix uses.
 *
 * usage: node probe-wave11-order-tables.cjs --enc <urlencoded-encrypted-order-id> --out <dir>
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };

const BASE = arg('base', 'http://127.0.0.1:8899');
const OUT = arg('out', '/tmp/wave11');
const ENC = arg('enc', '');
const EMAIL = arg('email', 'merchant@example.test');
const PASSWORD = arg('password', 'password');
const STORE = arg('store', '1');
const REPO = arg('repo', '/home/user/syriastore98');
const WIDTH = parseInt(arg('width', '1440'), 10);
const HEIGHT = parseInt(arg('height', '900'), 10);
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

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

(async () => {
  if (!ENC) { console.error('--enc is required (urlencoded encrypted order id)'); process.exit(2); }
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

    const combokey = `${combo.lang}_${combo.dir}_${combo.theme}`;
    const shot = async (name, extra) => {
      await page.waitForTimeout(450);
      const st = await page.evaluate(() => ({
        dir: document.documentElement.getAttribute('dir'),
        sheet: (document.getElementById('main-style-link') || {}).href || '',
      })).catch(() => ({}));
      const premise = [];
      if (!signedIn) premise.push('not signed in');
      if ((st.dir === 'rtl') !== (combo.dir === 'rtl')) premise.push('dir=' + st.dir);
      if (/style-dark\.css/.test(st.sheet || '') !== (combo.theme === 'dark')) premise.push('sheet=' + (st.sheet || '').split('/').pop());
      if (extra) premise.push(...extra);
      await page.screenshot({ path: path.join(OUT, `${name}__${combokey}.png`), fullPage: false }).catch(() => {});
      rows.push({ shot: name, combo: combokey, premise, console: consoleErrors.slice(0, 2) });
      consoleErrors.length = 0;
    };

    // 1. ORDER DETAIL — order/view.blade.php line-items table
    let r = await page.goto(BASE + '/order-view/' + ENC, { waitUntil: 'domcontentloaded' }).catch(() => null);
    await shot('order-view', r && r.status() === 200 ? [] : ['status=' + (r ? r.status() : 0)]);

    // 2. REFUND DETAIL — order/refund_details.blade.php refunded line-items table
    r = await page.goto(BASE + '/refund-request/' + ENC, { waitUntil: 'domcontentloaded' }).catch(() => null);
    await shot('refund-detail', r && r.status() === 200 ? [] : ['status=' + (r ? r.status() : 0)]);

    // 3. QUICK-VIEW MODAL — order/order_show.blade.php, opened by the grid eye action
    r = await page.goto(BASE + '/order-grid', { waitUntil: 'domcontentloaded' }).catch(() => null);
    await page.waitForTimeout(500);
    const modalPremise = [];
    if (!r || r.status() !== 200) modalPremise.push('grid status=' + (r ? r.status() : 0));
    const eye = await page.$('a[data-ajax-popup="true"][data-url*="order_view"]');
    if (!eye) modalPremise.push('no eye action on grid');
    else {
      await eye.click().catch(() => {});
      await page.waitForSelector('#commanModel.show', { timeout: 6000 }).catch(() => modalPremise.push('modal did not open'));
      await page.waitForTimeout(700);
      const hasTable = await page.$('#commanModel table');
      if (!hasTable) modalPremise.push('modal has no table');
    }
    await shot('quick-view-modal', modalPremise);

    await ctx.close();
  }

  await browser.close();
  fs.writeFileSync(path.join(OUT, 'probe-wave11.json'), JSON.stringify(rows, null, 1));

  let bad = 0;
  console.log('\nshot               combo            result');
  console.log('-'.repeat(84));
  for (const r of rows) {
    const flag = r.premise.length ? ('PREMISE-FAIL: ' + r.premise.join('; ')) : 'ok';
    if (r.premise.length) bad++;
    console.log(`${r.shot.padEnd(18)} ${r.combo.padEnd(16)} ${flag}${r.console.length ? '  | console: ' + r.console.join(' / ') : ''}`);
  }
  console.log(`\n${rows.length} shots, ${bad} not clean. -> ${OUT}`);
  process.exit(bad ? 1 : 0);
})();
