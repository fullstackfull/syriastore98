/**
 * LIGHT SURFACES THAT SURVIVE DARK MODE, measured on the rendered page.
 *
 * A class-name grep cannot answer this. `bg-white` appears 40 times in resources/views and most of
 * those are Tailwind/Breeze components that never load (CLAUDE.md: `@vite` appears in ZERO views)
 * or storefront markup belonging to Phase 03 -- while a light surface can equally arrive from a
 * theme rule with no telling class at all. So this reads what the browser actually paints.
 *
 * Reports every element that, with the merchant admin in DARK mode, computes an OPAQUE background
 * whose relative luminance is above the threshold and whose painted area is large enough to read
 * as a SURFACE rather than a chip, a dot or an avatar. Area is the filter that keeps a white pill
 * on a coloured badge -- which is correct -- out of a list of panels that are wrong.
 *
 * usage: node docs/development/phase-2.5/tools/census-light-surfaces-in-dark.cjs [--routes a,b] [--min-area 2000]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const REPO = '/home/user/syriastore98';
const TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');
const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const MIN_AREA = Number(arg('min-area', 2000));
const ROUTES = (arg('routes', '') || [
  'dashboard', 'finance', 'finance/withdrawals', 'order', 'order-kanban', 'product', 'customer',
  'category', 'coupon', 'setting', 'app-setting', 'roles', 'user-list', 'pos', 'theme-customize',
  'reports', 'stock-reports', 'shipping', 'taxes', 'tag', 'testimonial', 'blog', 'pages', 'menus',
  'newsletter', 'contacts', 'faqs', 'delivery-syria', 'deliveryboy', 'currency', 'profile',
].join(',')).split(',').map(s => s.trim()).filter(Boolean);

execFileSync('php', [TOOL, '1', 'dark', 'ltr', 'en', 'merchant@example.test'], {
  cwd: path.dirname(TOOL),
  env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(REPO, 'database/p2dev.sqlite'), CACHE_STORE: 'file' },
  stdio: ['ignore', 'pipe', 'pipe'],
});

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
  const p = await ctx.newPage();
  await p.goto('http://127.0.0.1:8899/login');
  await p.fill('input[name="email"]', 'merchant@example.test');
  await p.fill('input[name="password"]', 'password');
  await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"], input[type="submit"]')]);

  let total = 0;
  for (const r of ROUTES) {
    let status = 0;
    try {
      const resp = await p.goto('http://127.0.0.1:8899/' + r, { waitUntil: 'domcontentloaded', timeout: 30000 });
      status = resp ? resp.status() : 0;
    } catch (e) { console.log(`  ${r.padEnd(22)} ERR ${String(e.message).slice(0, 40)}`); continue; }
    if (status !== 200) { console.log(`  ${r.padEnd(22)} HTTP ${status}`); continue; }
    await p.waitForTimeout(1400);

    const found = await p.evaluate((minArea) => {
      const theme = document.documentElement.getAttribute('data-ss-theme')
        || document.body.getAttribute('data-ss-theme') || '?';
      const out = [];
      for (const el of document.querySelectorAll('body *')) {
        const cs = getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden') continue;
        const m = (cs.backgroundColor || '').match(/[\d.]+/g);
        if (!m || m.length < 3) continue;
        const alpha = m.length >= 4 ? parseFloat(m[3]) : 1;
        if (alpha < 0.95) continue;
        const lum = (0.2126 * +m[0] + 0.7152 * +m[1] + 0.0722 * +m[2]) / 255;
        if (lum < 0.72) continue;
        const q = el.getBoundingClientRect();
        const area = q.width * q.height;
        if (area < minArea) continue;
        /* a light surface INSIDE another light surface is one finding, not two */
        if (out.some((o) => o.node.contains(el))) continue;
        out.push({
          node: el,
          tag: el.tagName.toLowerCase(),
          cls: String(el.className).trim().split(/\s+/).slice(0, 3).join('.').slice(0, 44),
          bg: cs.backgroundColor,
          lum: +lum.toFixed(2),
          area: Math.round(area),
          w: Math.round(q.width), h: Math.round(q.height),
        });
      }
      return { theme, items: out.map(({ node, ...rest }) => rest) };
    }, MIN_AREA);

    if (found.theme !== 'dark') { console.log(`  ${r.padEnd(22)} THEME NOT DARK (${found.theme}) -- reading skipped`); continue; }
    if (!found.items.length) { console.log(`  ${r.padEnd(22)} clean`); continue; }
    total += found.items.length;
    console.log(`  ${r.padEnd(22)} ${found.items.length} light surface(s)`);
    for (const x of found.items) {
      console.log(`      ${x.tag}.${x.cls.padEnd(44)} bg=${x.bg.padEnd(18)} lum=${x.lum} ${x.w}x${x.h}`);
    }
  }
  console.log(`\n  light surfaces in dark mode (area >= ${MIN_AREA}px2): ${total}`);
  await b.close();
})();
