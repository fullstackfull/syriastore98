/**
 * P2.5 — what a merchant list screen does at PHONE width.
 *
 * The existing measure-table-overflow.cjs reads the desktop viewport. This reads the one the
 * directive's responsive dimension is actually about, and it separates two things that are
 * usually conflated:
 *
 *   PAGE overflow    the document itself is wider than the viewport, so the whole layout slides
 *                    sideways and the sidebar, header and content all drift. Always a defect.
 *   TABLE overflow   the table is wider than its own scroll container, so the table scrolls
 *                    inside a fixed-width page. That is the intended Bootstrap behaviour and is
 *                    NOT a defect by itself -- but it is only acceptable if the first column
 *                    carries enough to identify the row, and if the row's actions are reachable.
 *
 * It also measures what a person can actually DO there: how much of the table is visible without
 * scrolling, and whether the row actions meet WCAG 2.2 Target Size (Minimum) of 24x24 CSS px.
 *
 * Both directions, both themes, every run -- see measure-timeline-pills.cjs for why there is no
 * flag to narrow it.
 *
 * usage:  OUT=/tmp/phone node docs/development/phase-2.5/tools/measure-tables-at-phone-width.cjs
 */
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPO = '/home/user/syriastore98';
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const OUT = process.env.OUT || '';
const EMAIL = process.env.EMAIL || 'merchant@example.test';
const PASSWORD = process.env.PASSWORD || 'password';
const STORE = process.env.STORE || '1';
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

/** 375x667 is the narrowest width the design system's breakpoints claim to serve. */
const PHONE = devices['iPhone SE'] || { viewport: { width: 375, height: 667 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 };

const TARGET_MIN = 24; // WCAG 2.2 SC 2.5.8, CSS px

const COMBOS = [
  { lang: 'en', dir: 'ltr', theme: 'light' },
  { lang: 'en', dir: 'ltr', theme: 'dark' },
  { lang: 'ar', dir: 'rtl', theme: 'light' },
  { lang: 'ar', dir: 'rtl', theme: 'dark' },
];

const ROUTES = ['/product', '/order', '/customer', '/coupon'];

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

const READ = `
  const doc = document.documentElement;
  const table = document.querySelector('table.dataTable, table.ss-table, table');
  const scroller = table && table.closest('.table-responsive, .dataTable-container, .ss-dt-card-body, .dataTables_wrapper');
  if (!table) return { noTable: true, pageOverflow: doc.scrollWidth - doc.clientWidth };

  const tb = table.getBoundingClientRect();
  const sc = scroller ? scroller.getBoundingClientRect() : null;

  // how many header cells are fully inside the visible scroller
  const heads = Array.from(table.querySelectorAll('thead th'));
  const visibleHeads = sc ? heads.filter((h) => {
    const r = h.getBoundingClientRect();
    return r.left >= sc.left - 1 && r.right <= sc.right + 1 && r.width > 0;
  }).length : 0;

  // the row-action controls, which are what a person has to hit on a phone
  const actions = Array.from(table.querySelectorAll('tbody a, tbody button, tbody input[type="checkbox"]'));
  const small = actions.filter((a) => {
    const r = a.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && (r.width < ${TARGET_MIN} || r.height < ${TARGET_MIN});
  }).length;

  return {
    noTable: false,
    pageOverflow: doc.scrollWidth - doc.clientWidth,
    tableW: Math.round(tb.width),
    scrollerW: sc ? Math.round(sc.width) : null,
    scrollerName: scroller ? String(scroller.className).trim().split(/\\s+/)[0] : null,
    tableScrolls: sc ? Math.round(tb.width) > Math.round(sc.width) + 1 : null,
    heads: heads.length,
    visibleHeads,
    firstHead: heads.length ? (heads[0].textContent || '').trim().slice(0, 18) : null,
    actions: actions.length,
    actionsBelowTarget: small,
  };
`;

(async () => {
  if (OUT) fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  const rows = [];

  for (const combo of COMBOS) {
    setState(combo.theme, combo.dir, combo.lang);
    const ctx = await browser.newContext({ ...PHONE });
    const page = await ctx.newPage();

    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"], input[type="submit"]')]);

    const key = `${combo.lang}_${combo.dir}_${combo.theme}`;

    for (const route of ROUTES) {
      const r = await page.goto(BASE + route, { waitUntil: 'domcontentloaded' }).catch(() => null);
      await page.waitForTimeout(1400);   // the DataTable populates by AJAX

      const premise = [];
      if (!r || r.status() !== 200) premise.push('status=' + (r ? r.status() : 0));
      if (page.url().replace(BASE, '').split('?')[0] !== route) premise.push('redirected to ' + page.url().replace(BASE, ''));

      const st = await page.evaluate('(() => {' + READ + '})()');
      if (st.noTable) premise.push('no table on the page');

      if (OUT) {
        await page.screenshot({ path: path.join(OUT, `${route.replace(/\W+/g, '') || 'root'}__${key}.png`), fullPage: false }).catch(() => {});
      }

      rows.push({ combo: key, route, premise, ...st });
    }

    await ctx.close();
  }

  await browser.close();

  console.log(`\nPhone ${PHONE.viewport.width}x${PHONE.viewport.height}. PAGE overflow is always a defect; TABLE scroll is not.`);
  console.log('\ncombo            route       page-ovf  table/scroller   scrolls  cols vis/all  first col        actions <24px');
  console.log('-'.repeat(118));

  let pageOverflows = 0, tinyTargets = 0, measured = 0;
  for (const x of rows) {
    if (x.premise && x.premise.length) {
      console.log(`${x.combo.padEnd(16)} ${x.route.padEnd(11)} PREMISE-FAIL: ${x.premise.join('; ')}`);
      continue;
    }
    measured++;
    if (x.pageOverflow > 0) pageOverflows++;
    if (x.actionsBelowTarget > 0) tinyTargets++;
    console.log(
      `${x.combo.padEnd(16)} ${x.route.padEnd(11)} ${String(x.pageOverflow).padEnd(9)} `
      + `${(x.tableW + '/' + x.scrollerW).padEnd(16)} ${String(x.tableScrolls).padEnd(8)} `
      + `${(x.visibleHeads + '/' + x.heads).padEnd(12)} ${String(x.firstHead).padEnd(16)} `
      + `${x.actionsBelowTarget}/${x.actions}`
      + (x.pageOverflow > 0 ? '   <-- PAGE OVERFLOW' : ''));
  }
  console.log(`\n${measured} readings. ${pageOverflows} with PAGE overflow, ${tinyTargets} with a row action under ${TARGET_MIN}px.`);
  if (OUT) console.log(`-> ${OUT}`);
})();
