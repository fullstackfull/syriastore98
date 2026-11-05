/**
 * P2.5-WAVE-12 PROBE — the role-permission modals, driven in a real browser.
 *
 * roles/create.blade.php and roles/edit.blade.php are the entire role-permission UI, rendered as
 * xl AJAX modals (no standalone URL). The probe opens each the way a merchant does:
 *   - Create: click the static "Create Role" button on /roles (data-url .../roles/create).
 *   - Edit:   wait for the roles DataTable to populate, click the first row's edit action
 *             (data-url .../roles/{id}/edit), both into #commanModel.
 *
 * Every shot carries a premise (signed in, dir, theme sheet, the modal actually populated a table).
 *
 * usage: node probe-wave12-roles.cjs --out <dir>
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };

const BASE = arg('base', 'http://127.0.0.1:8899');
const OUT = arg('out', '/tmp/wave12');
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

    const openModalAndShoot = async (clickSelector, name, waitForGrid) => {
      const r = await page.goto(BASE + '/roles', { waitUntil: 'domcontentloaded' }).catch(() => null);
      const pre = [];
      if (!r || r.status() !== 200) pre.push('roles status=' + (r ? r.status() : 0));
      await page.waitForTimeout(600);
      if (waitForGrid) {
        // the roles DataTable populates its rows (and their edit actions) via AJAX
        await page.waitForSelector(clickSelector, { timeout: 7000 }).catch(() => pre.push('no row action (grid did not populate)'));
      }
      const el = await page.$(clickSelector);
      if (!el) { pre.push('trigger not found: ' + clickSelector); await shot(name, pre); return; }
      await el.click().catch(() => {});
      await page.waitForSelector('#commanModel.show', { timeout: 7000 }).catch(() => pre.push('modal did not open'));
      await page.waitForTimeout(800);
      const hasTable = await page.$('#commanModel table.ss-table, #commanModel table');
      if (!hasTable) pre.push('modal has no table');
      await shot(name, pre);
      // close the modal before the next navigation
      await page.click('#commanModel .btn-close, #commanModel [data-bs-dismiss="modal"]').catch(() => {});
      await page.waitForTimeout(300);
    };

    // 1. CREATE ROLE modal (static button on /roles)
    await openModalAndShoot('[data-ajax-popup="true"][data-url*="roles/create"]', 'role-create-modal', false);

    // 2. EDIT ROLE modal (edit action inside the roles DataTable)
    await openModalAndShoot('[data-ajax-popup="true"][data-url*="/edit"]', 'role-edit-modal', true);

    await ctx.close();
  }

  await browser.close();
  fs.writeFileSync(path.join(OUT, 'probe-wave12.json'), JSON.stringify(rows, null, 1));

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
