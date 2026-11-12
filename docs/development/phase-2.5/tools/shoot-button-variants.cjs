/**
 * BEFORE/AFTER screenshots for the soft-button contrast override (P2.5-DESIGN-E).
 *
 * Phase 2.5's directive requires real before/after images, and a CSS diff is not one. This signs
 * in as a real merchant, loads a real admin screen so the host cascade and the real page
 * background apply, and photographs two things per theme:
 *
 *   <label>-page-<theme>.png      the real screen, unmodified -- so the button in its real context
 *   <label>-strip-<theme>.png     a specimen strip of ALL EIGHT variants injected into that page
 *
 * THE STRIP EXISTS BECAUSE A SCREEN ONLY SHOWS THE VARIANTS IT HAPPENS TO USE. /finance renders
 * four of the eight, and measuring only those is what made me report this as a dark-mode problem
 * when light is the worse theme. The strip is injected into `.dash-content`, the same host the
 * measurement tool uses, so both instruments see the same cascade.
 *
 * It photographs; it does not judge. measure-button-contrast.cjs computes the ratios.
 *
 * usage:  node shoot-button-variants.cjs --out <dir> --label after [--path /finance]
 *         (run once with the override block present, once with it removed)
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const REPO = '/home/user/syriastore98';
const TOOL = REPO + '/docs/development/phase-2/tools/set-admin-preview-state.php';
const BASE = arg('base', 'http://127.0.0.1:8899');
const OUT = arg('out', '/tmp/btn-shots');
const LABEL = arg('label', 'after');
const PAGE_PATH = arg('path', '/finance');
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: REPO + '/database/p2dev.sqlite', CACHE_STORE: 'file' };

const VARIANTS = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const b = await chromium.launch({ args: ['--no-sandbox'] });
  const notes = [];

  for (const theme of ['light', 'dark']) {
    execFileSync('php', [TOOL, '1', theme, 'ltr', 'en', 'merchant@example.test'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
    const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
    const p = await ctx.newPage();
    await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await p.fill('input[name=email]', 'merchant@example.test');
    await p.fill('input[name=password]', 'password');
    await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit]')]);
    const resp = await p.goto(BASE + PAGE_PATH, { waitUntil: 'networkidle' });

    // PREMISE -- a shot taken on the wrong theme, or on a login redirect, is not evidence. Two
    // earlier rounds in this project produced "dark" screenshots that were light because the
    // state write went to a different sqlite file; the premise is checked, not assumed.
    const premise = await p.evaluate(() => ({
      url: location.pathname,
      sheet: ((document.getElementById('main-style-link') || {}).href || '').split('/').pop(),
      ssTheme: document.documentElement.getAttribute('data-ss-theme'),
    }));
    const wantDark = theme === 'dark';
    const gotDark = /style-dark\.css/.test(premise.sheet);
    if (resp.status() !== 200 || /\/login/.test(premise.url) || gotDark !== wantDark) {
      notes.push(`PREMISE-FAIL ${theme}: HTTP ${resp.status()} url=${premise.url} sheet=${premise.sheet} ss-theme=${premise.ssTheme}`);
      await ctx.close();
      continue;
    }
    notes.push(`ok ${theme}: HTTP ${resp.status()} sheet=${premise.sheet} ss-theme=${premise.ssTheme}`);

    await p.screenshot({ path: path.join(OUT, `${LABEL}-page-${theme}.png`), fullPage: false });

    // The strip is injected with NO styling of its own beyond layout, so each chip is exactly what
    // the cascade paints. Labels are plain text in the page's own colour.
    await p.evaluate((vs) => {
      const host = document.querySelector('.dash-content') || document.body;
      const box = document.createElement('div');
      box.id = 'ss-btn-specimen';
      box.style.cssText = 'display:flex;flex-wrap:wrap;gap:14px;padding:22px;margin:18px 0;align-items:flex-end';
      for (const v of vs) {
        const cell = document.createElement('div');
        cell.style.cssText = 'display:flex;flex-direction:column;gap:6px;align-items:flex-start';
        const lab = document.createElement('small');
        lab.textContent = '.btn-' + v;
        lab.style.cssText = 'font:11px/1 ui-monospace,monospace;opacity:.7';
        const btn = document.createElement('button');
        btn.className = 'btn btn-' + v;
        btn.textContent = 'Withdraw';
        cell.appendChild(lab); cell.appendChild(btn); box.appendChild(cell);
      }
      host.insertBefore(box, host.firstChild);
      box.scrollIntoView({ block: 'center' });
    }, VARIANTS);
    await p.waitForTimeout(250);
    await p.locator('#ss-btn-specimen').screenshot({ path: path.join(OUT, `${LABEL}-strip-${theme}.png`) });
    await ctx.close();
  }

  execFileSync('php', [TOOL, '1', 'light', 'ltr', 'en', 'merchant@example.test'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
  await b.close();
  notes.forEach((n) => console.log(n));
  const failed = notes.filter((n) => n.startsWith('PREMISE-FAIL')).length;
  console.log(`${LABEL}: ${notes.length - failed}/2 themes shot into ${OUT}`);
  process.exit(failed ? 1 : 0);
})();
