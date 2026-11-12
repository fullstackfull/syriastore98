/**
 * WHICH MERCHANT TABLES DOES P2.5-WAVE-28 ACTUALLY REACH?
 *
 * Wave 28's card layout is scoped to `.ss-dt-card-body table.dataTable`, deliberately -- unscoped
 * it would blockify every table in the 70 vendored WorkDo modules. The consequence is that a
 * merchant table outside that wrapper, or one that is not a DataTable at all, still shows the
 * pre-wave layout at phone width. census-table-wrapper.py answers the SOURCE question (which Blade
 * files carry the wrapper). This answers the RENDERED one, which is the only one a merchant feels.
 *
 * Per table it reports:
 *   stacked    the first body cell computes `display: table` and carries `data-ss-label`
 *   hScroll    the table is wider than its nearest scrollport, i.e. columns are off-screen
 *   cols/seen  declared columns, and how many have any painted width inside the scrollport
 *
 * A table that is NOT stacked and does NOT scroll is fine: it already fits. Only
 * `not stacked AND hScroll` is a defect, and the exit code counts those.
 *
 * usage: node docs/development/phase-2.5/tools/census-phone-tables.cjs [--routes a,b] [--theme light] [--dir ltr]
 */
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const REPO = '/home/user/syriastore98';
const TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');
const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const THEME = arg('theme', 'light');
const DIR = arg('dir', 'ltr');
const LANG = DIR === 'rtl' ? 'ar' : 'en';

const ROUTES = (arg('routes', '') || [
  'dashboard', 'product', 'product-list', 'product-brand', 'product-categories', 'product-label',
  'product-attributes', 'product-question', 'category', 'coupon', 'customer', 'customer-grid',
  'order', 'order/all', 'order-grid', 'order-kanban', 'finance', 'finance/unreconciled-captures',
  'finance/withdrawals', 'stock-reports', 'stock-report', 'reports', 'testimonial', 'tag', 'taxes',
  'taxes-method', 'shipping', 'shipping-method', 'shipping-zone', 'flash-sale', 'newsletter',
  'contacts', 'faqs', 'blog', 'blog-category', 'pages', 'menus', 'support_ticket', 'refund-request',
  'wishlist', 'deliveryboy', 'deliveryboy-list', 'delivery-syria', 'abandon-carts-handled',
  'currency', 'currency-rates', 'city', 'state', 'countries', 'roles', 'users', 'user-list',
  'notifications', 'merchant-notifications', 'otp-integration', 'webhook', 'setting', 'app-setting',
  'pos', 'mobile-screen-content', 'themean-alytic', 'product-order-reports', 'category-order-reports',
  'brand-order-reports', 'order-reports', 'order-country-reports', 'order-downloadable-reports',
  'Status-reports', 'top-all-reports', 'stores', 'store/list', 'plan', 'profile',
].join(',')).split(',').map(s => s.trim()).filter(Boolean);

execFileSync('php', [TOOL, '1', THEME, DIR, LANG, 'merchant@example.test'], {
  cwd: path.dirname(TOOL),
  env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(REPO, 'database/p2dev.sqlite'), CACHE_STORE: 'file' },
  stdio: ['ignore', 'pipe', 'pipe'],
});

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ ...devices['iPhone SE'] });
  const p = await ctx.newPage();
  await p.goto('http://127.0.0.1:8899/login');
  await p.fill('input[name="email"]', 'merchant@example.test');
  await p.fill('input[name="password"]', 'password');
  await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"], input[type="submit"]')]);

  const rows = [];
  for (const r of ROUTES) {
    let status = 0;
    try {
      const resp = await p.goto('http://127.0.0.1:8899/' + r, { waitUntil: 'domcontentloaded', timeout: 30000 });
      status = resp ? resp.status() : 0;
    } catch (e) { rows.push({ route: r, status: 'ERR', note: String(e.message).slice(0, 48) }); continue; }
    await p.waitForTimeout(1500);

    const m = await p.evaluate(() => {
      const de = document.documentElement;
      const scrollport = (el) => {
        let a = el.parentElement;
        while (a && a !== document.body) {
          const cs = getComputedStyle(a);
          if (/auto|scroll/.test(cs.overflowX)) return a;
          a = a.parentElement;
        }
        return null;
      };
      const out = [];
      for (const t of document.querySelectorAll('table')) {
        const rect = t.getBoundingClientRect();
        if (!rect.width || !rect.height) continue;
        const body = t.querySelector('tbody tr');
        const cell = body ? body.querySelector('td') : null;
        const stacked = !!(cell && getComputedStyle(cell).display === 'table' && cell.hasAttribute('data-ss-label'));
        const port = scrollport(t);
        const portW = port ? port.clientWidth : de.clientWidth;
        const hScroll = Math.round(rect.width) > portW + 1;
        const heads = t.querySelectorAll('thead th');
        let seen = 0;
        if (body) for (const c of body.children) {
          const q = c.getBoundingClientRect();
          if (q.width > 0 && q.left < portW && q.right > 0) seen++;
        }
        out.push({
          cls: String(t.className).trim().split(/\s+/).slice(0, 2).join('.') || '(none)',
          inCard: !!t.closest('.ss-dt-card-body'),
          isDT: t.classList.contains('dataTable'),
          stacked, hScroll,
          w: Math.round(rect.width), portW,
          cols: heads.length || (body ? body.children.length : 0),
          seen, hasRows: !!body,
        });
      }
      return { pageOverflow: de.scrollWidth - de.clientWidth, tables: out };
    });
    rows.push({ route: r, status, ...m });
  }
  await b.close();

  let defects = 0;
  console.log(`\n### phone table census  320x568  ${THEME}/${DIR}/${LANG}\n`);
  for (const r of rows) {
    if (r.status !== 200) { console.log(`  ${String(r.route).padEnd(34)} HTTP ${r.status}${r.note ? ' ' + r.note : ''}`); continue; }
    if (!r.tables || !r.tables.length) { console.log(`  ${r.route.padEnd(34)} 200  no visible table`); continue; }
    for (const t of r.tables) {
      const bad = !t.stacked && t.hScroll;
      if (bad) defects++;
      console.log(`  ${r.route.padEnd(34)} ${bad ? 'DEFECT' : t.stacked ? 'stacked' : 'fits   '} `
        + `cls=${t.cls.padEnd(26)} card=${t.inCard ? 'y' : 'n'} dt=${t.isDT ? 'y' : 'n'} `
        + `w=${String(t.w).padEnd(5)} port=${String(t.portW).padEnd(4)} cols=${t.cols} seen=${t.seen}`
        + `${r.pageOverflow ? ' pageOverflow=' + r.pageOverflow : ''}`);
    }
  }
  console.log(`\n  tables that are neither stacked nor fitting: ${defects}`);
  process.exit(0);
})();
