/* Capture the customer timeline in all four combinations at three widths. */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const BASE = 'http://127.0.0.1:8899';
const OUT = arg('out', '/tmp/timeline');
const LABEL = arg('label', 'before');
const CUST = arg('customer', '1');
const REPO = '/home/user/syriastore98';
const STATE = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

const COMBOS = [
  { lang: 'en', dir: 'ltr', theme: 'light' }, { lang: 'en', dir: 'ltr', theme: 'dark' },
  { lang: 'ar', dir: 'rtl', theme: 'light' }, { lang: 'ar', dir: 'rtl', theme: 'dark' },
];
const WIDTHS = [{ w: 1440, h: 1000, tag: 'desktop' }, { w: 390, h: 844, tag: 'w390' }, { w: 320, h: 720, tag: 'w320' }];

function setState(theme, dir, lang) {
  execFileSync('php', [STATE, '1', theme, dir, lang, 'merchant@example.test'], {
    cwd: path.dirname(STATE),
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(REPO, 'database/p2dev.sqlite'), CACHE_STORE: 'file' },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  const rows = [];
  for (const c of COMBOS) {
    setState(c.theme, c.dir, c.lang);
    for (const v of WIDTHS) {
      const ctx = await browser.newContext({ viewport: { width: v.w, height: v.h }, deviceScaleFactor: 1 });
      const page = await ctx.newPage();
      await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
      await page.fill('input[name="email"]', 'merchant@example.test');
      await page.fill('input[name="password"]', 'password');
      await page.click('button[type="submit"]');
      await page.waitForLoadState('domcontentloaded'); await page.waitForTimeout(1200);
      await page.goto(BASE + '/customer-timeline/' + CUST, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2200);
      const info = await page.evaluate(() => ({
        url: location.pathname, title: document.title,
        dir: document.documentElement.getAttribute('dir') || 'ltr',
        theme: document.documentElement.getAttribute('data-ss-theme') || 'light',
        arms: document.querySelectorAll('.ss-timeline__item, .timeline-item, [class*="timeline"] li').length,
        docH: document.documentElement.scrollHeight,
        overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      }));
      const id = `${LABEL}__timeline__${c.lang}-${c.theme}-${v.tag}`;
      const bad = /\/login/.test(info.url) || info.dir !== c.dir || info.theme !== c.theme || /dashboard|لوحة/i.test(info.title);
      if (bad) { console.log(`[PREMISE-FAIL] ${id}  ${JSON.stringify(info)}`); continue; }
      await page.screenshot({ path: path.join(OUT, id + '.png'), fullPage: true });
      rows.push({ id, ...info });
      console.log(`[ok] ${id}  docH=${info.docH}px  overflowX=${info.overflowX}  title="${info.title}"`);
      await ctx.close();
    }
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, LABEL + '-rows.json'), JSON.stringify(rows, null, 2));
})();
