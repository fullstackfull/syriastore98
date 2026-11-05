/**
 * P2.5-WAVE-15 PROBE — the order status menu, OPENED, so its glyphs can be seen.
 *
 * The icons this wave fixed live inside a closed Bootstrap dropdown, so a page screenshot shows
 * none of them. This opens it the way a merchant does (click `button.order_status_btn`) and waits
 * for `.dropdown-menu.show` before capturing.
 *
 * usage: node probe-wave15-order-status-menu.cjs --enc <urlencoded-encrypted-order-id> --out <dir>
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const BASE = arg('base', 'http://127.0.0.1:8899');
const OUT = arg('out', '/tmp/wave15');
const ENC = arg('enc', '');
const EMAIL = 'merchant@example.test', PASSWORD = 'password', STORE = '1';
const REPO = '/home/user/syriastore98';
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');
const COMBOS = [
  { lang: 'en', dir: 'ltr', theme: 'light' }, { lang: 'en', dir: 'ltr', theme: 'dark' },
  { lang: 'ar', dir: 'rtl', theme: 'light' }, { lang: 'ar', dir: 'rtl', theme: 'dark' },
];
function setState(theme, dir, lang) {
  execFileSync('php', [STATE_TOOL, STORE, theme, dir, lang, EMAIL], {
    cwd: path.dirname(STATE_TOOL),
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(REPO, 'database/p2dev.sqlite'), CACHE_STORE: 'file' },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}
(async () => {
  if (!ENC) { console.error('--enc required'); process.exit(2); }
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  const rows = [];
  for (const combo of COMBOS) {
    setState(combo.theme, combo.dir, combo.lang);
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"], input[type="submit"]')]);
    const signedIn = !/\/login\b/.test(page.url());
    const premise = []; if (!signedIn) premise.push('not signed in');
    const r = await page.goto(BASE + '/order-view/' + ENC, { waitUntil: 'domcontentloaded' }).catch(() => null);
    if (!r || r.status() !== 200) premise.push('status=' + (r ? r.status() : 0));
    await page.waitForTimeout(500);
    const btn = await page.$('button.order_status_btn');
    if (!btn) premise.push('status toggle not found');
    else {
      await btn.click().catch(() => {});
      await page.waitForSelector('.dropdown-menu.show', { timeout: 5000 }).catch(() => premise.push('menu did not open'));
      await page.waitForTimeout(500);
      const n = await page.$$eval('.dropdown-menu.show i.ti', els => els.length).catch(() => 0);
      if (n < 4) premise.push('only ' + n + ' glyphs visible in the open menu');
    }
    const st = await page.evaluate(() => ({ dir: document.documentElement.getAttribute('dir'), sheet: (document.getElementById('main-style-link') || {}).href || '' })).catch(() => ({}));
    if ((st.dir === 'rtl') !== (combo.dir === 'rtl')) premise.push('dir=' + st.dir);
    if (/style-dark\.css/.test(st.sheet || '') !== (combo.theme === 'dark')) premise.push('sheet');
    const key = `order-status-menu__${combo.lang}_${combo.dir}_${combo.theme}`;
    // CLIP TO THE MENU ITSELF. A viewport shot showed the dropdown clipped by the card it opens
    // inside, so the glyphs this wave changed were not visible -- a screenshot that does not show
    // the thing it is evidence for is not evidence.
    const menu = await page.$('.dropdown-menu.show');
    if (menu) await menu.screenshot({ path: path.join(OUT, key + '.png') }).catch(() => {});
    else await page.screenshot({ path: path.join(OUT, key + '.png'), fullPage: false }).catch(() => {});
    rows.push({ shot: key, premise });
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'probe-wave15.json'), JSON.stringify(rows, null, 1));
  let bad = 0;
  for (const r of rows) { const f = r.premise.length ? 'PREMISE-FAIL: ' + r.premise.join('; ') : 'ok'; if (r.premise.length) bad++; console.log(`${r.shot.padEnd(42)} ${f}`); }
  console.log(`\n${rows.length} shots, ${bad} not clean. -> ${OUT}`);
  process.exit(bad ? 1 : 0);
})();
