'use strict';
/**
 * P2-03-10 -- row density on the merchant lists, driven in a real browser.
 *
 * ACCEPTANCE, taken from the ledger row and checked item by item:
 *   a merchant can choose a comfortable or compact row height;
 *   the choice persists per table the way the column set and page length already do;
 *   the compact row still clears the 24px touch target the row actions need;
 *   both densities are checked in all four LTR/RTL x light/dark configurations.
 *
 * usage: node check-row-density-live.cjs [baseUrl] [storeId]
 */
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const STORE = process.argv[3] || '1';
const REPO = '/home/user/syriastore98';
const EMAIL = 'merchant@example.test', PASSWORD = 'password';

const failures = [];
let cases = 0;
function check(label, cond, detail) {
  cases += 1;
  if (cond) { console.log('  ok    ' + label); }
  else { failures.push(label); console.error('  FAIL  ' + label + (detail === undefined ? '' : '  [' + detail + ']')); }
}

function setServerState(theme, dir) {
  execFileSync('php', [path.join(REPO, 'docs/development/phase-2/tools/set-preview-state.php'), STORE, theme, dir], {
    cwd: REPO,
    env: Object.assign({}, process.env, {
      DB_CONNECTION: 'sqlite',
      DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'),
      CACHE_STORE: 'file',
    }),
    stdio: 'pipe',
  });
}

async function login(page) {
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
}

async function openList(page, p, id) {
  await page.goto(BASE + p, { waitUntil: 'networkidle' });
  await page.waitForSelector('#' + id + ' tbody tr', { timeout: 20000 });
  await page.waitForTimeout(500);
}

const metrics = (id) => `(() => {
  const t = document.getElementById('${id}');
  const tr = t.querySelector('tbody tr');
  const host = document.querySelector('[data-ss-density]');
  const btn = document.querySelector('.ss-density');
  const pinned = tr.querySelector('td.ss-col-pinned') || tr.querySelector('td:last-child');
  const act = pinned ? pinned.querySelector('a') : null;
  const grp = pinned ? pinned.querySelector('span.d-flex, div.d-flex') : null;
  const td = tr.querySelector('td:not(.ss-col-locked)') || tr.querySelector('td');
  const cs = getComputedStyle(td);
  const bcs = btn ? getComputedStyle(btn) : null;
  return {
    rowH: Math.round(tr.getBoundingClientRect().height),
    padTop: cs.paddingTop, padBottom: cs.paddingBottom,
    density: host ? host.getAttribute('data-ss-density') : null,
    pressed: btn ? btn.getAttribute('aria-pressed') : null,
    aria: btn ? btn.getAttribute('aria-label') : null,
    btnShadow: bcs ? bcs.boxShadow : null,
    actionH: act ? Math.round(act.getBoundingClientRect().height) : null,
    actionW: act ? Math.round(act.getBoundingClientRect().width) : null,
    groupH: grp ? Math.round(grp.getBoundingClientRect().height) : null,
    rows: t.querySelectorAll('tbody tr').length,
  };
})()`;

async function run() {
  const browser = await chromium.launch();
  try {
    /* ---- Cases 1-9: the control, and that the choice is really stored ---- */
    setServerState('light', 'ltr');
    {
      const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
      const page = await ctx.newPage();
      await login(page);
      await page.goto(BASE + '/change-language/en', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await openList(page, '/product', 'product-table');

      const btn = await page.locator('.dt-buttons .ss-density').count();
      check('the density toggle is rendered', btn === 1, 'count=' + btn);

      let m = await page.evaluate(metrics('product-table'));
      check('it opens comfortable (the attribute is present and says so)', m.density === 'comfortable', 'density=' + m.density);
      check('comfortable measures the 55px baseline', m.rowH === 55, 'rowH=' + m.rowH);
      check('the toggle is announced as un-pressed', m.pressed === 'false', 'aria-pressed=' + m.pressed);
      check('the toggle has an accessible name', !!m.aria && m.aria.trim() !== '', 'aria-label=' + JSON.stringify(m.aria));

      await page.click('.dt-buttons .ss-density');
      await page.waitForTimeout(400);
      m = await page.evaluate(metrics('product-table'));
      check('clicking it switches to compact', m.density === 'compact', 'density=' + m.density);
      check('compact really changes the measured row height (41px: 32 content + 2x4 padding + 1px border)', m.rowH < 55 && m.rowH === 41, 'rowH=' + m.rowH);
      check('compact cuts the block padding to 4px', m.padTop === '4px' && m.padBottom === '4px', m.padTop + '/' + m.padBottom);
      check('the toggle is now announced as pressed', m.pressed === 'true', 'aria-pressed=' + m.pressed);
      check('the pressed state is drawn WITHOUT colour as its only signal (an inset ring)',
        !!m.btnShadow && m.btnShadow !== 'none' && m.btnShadow.indexOf('inset') !== -1, 'box-shadow=' + m.btnShadow);

      /* THE ACCEPTANCE'S OWN NUMBER. */
      check('the row actions still clear a 24px touch target at the compact height',
        m.actionH >= 24 && m.actionW >= 24, 'action=' + m.actionW + 'x' + m.actionH);
      check('the action GROUP is not clipped by the shorter row', m.groupH <= m.rowH, 'group=' + m.groupH + ' row=' + m.rowH);

      /* and it must still be clickable, not merely large enough */
      const target = await page.evaluate(() => {
        const td = document.querySelector('#product-table tbody tr td.ss-col-pinned') ||
                   document.querySelector('#product-table tbody tr td:last-child');
        const a = td && td.querySelector('a[href*="/edit"]');
        if (!a) return null;
        const r = a.getBoundingClientRect();
        return { x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2) };
      });
      if (!target) { check('Edit is clickable at the compact height', false, 'no Edit anchor'); }
      else {
        let landed = null;
        try { await page.mouse.click(target.x, target.y); await page.waitForURL(/\/product\/\d+\/edit/, { timeout: 6000 }); landed = page.url(); } catch (e) { landed = null; }
        check('Edit is clickable at the compact height', landed !== null, 'clicked (' + target.x + ',' + target.y + ')');
      }

      /* PERSISTENCE -- the half the acceptance names explicitly. */
      await openList(page, '/product', 'product-table');
      m = await page.evaluate(metrics('product-table'));
      check('the choice SURVIVES A RELOAD', m.density === 'compact', 'density=' + m.density);
      check('and the row is still 41px after the reload', m.rowH === 41, 'rowH=' + m.rowH);

      const stored = await page.evaluate(() => {
        for (let i = 0; i < localStorage.length; i += 1) {
          const k = localStorage.key(i);
          if (k && k.indexOf('DataTables_') === 0) {
            try { const v = JSON.parse(localStorage.getItem(k)); if (v && v.ssDensity) return { k: k, d: v.ssDensity }; } catch (e) {}
          }
        }
        return null;
      });
      check('it is stored in the SAME DataTables state as the column set and page length',
        !!stored && stored.d === 'compact', 'stored=' + JSON.stringify(stored));

      /* PER TABLE, not global -- the acceptance says "per table". */
      await openList(page, '/order', 'order-table');
      const mo = await page.evaluate(metrics('order-table'));
      check('a DIFFERENT table is unaffected and opens comfortable', mo.density === 'comfortable', 'order density=' + mo.density);
      check('and that table still measures 55px', mo.rowH === 55, 'order rowH=' + mo.rowH);

      /* back to comfortable, and that must persist too */
      await openList(page, '/product', 'product-table');
      await page.click('.dt-buttons .ss-density');
      await page.waitForTimeout(400);
      await openList(page, '/product', 'product-table');
      m = await page.evaluate(metrics('product-table'));
      check('switching BACK to comfortable persists as well', m.density === 'comfortable' && m.rowH === 55,
        'density=' + m.density + ' rowH=' + m.rowH);
      await ctx.close();
    }

    /* ---- Cases: both densities in all four configurations ---- */
    for (const dir of ['ltr', 'rtl']) {
      for (const theme of ['light', 'dark']) {
        const config = dir + '/' + theme;
        setServerState(theme, dir);
        const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const page = await ctx.newPage();
        await login(page);
        await page.goto(BASE + '/change-language/' + (dir === 'rtl' ? 'ar' : 'en'), { waitUntil: 'domcontentloaded' }).catch(() => {});
        await openList(page, '/product', 'product-table');

        let m = await page.evaluate(metrics('product-table'));
        check(config + ': comfortable is 55px', m.rowH === 55, 'rowH=' + m.rowH);
        check(config + ': the toggle carries a translated accessible name',
          !!m.aria && (dir === 'rtl' ? /[؀-ۿ]/.test(m.aria) : !/[؀-ۿ]/.test(m.aria)),
          'aria-label=' + JSON.stringify(m.aria));

        await page.click('.dt-buttons .ss-density');
        await page.waitForTimeout(400);
        m = await page.evaluate(metrics('product-table'));
        check(config + ': compact is 41px', m.rowH === 41, 'rowH=' + m.rowH);
        check(config + ': the actions still clear 24px', m.actionH >= 24 && m.actionW >= 24, 'action=' + m.actionW + 'x' + m.actionH);
        await ctx.close();
      }
    }
  } finally {
    setServerState('light', 'rtl');
    await browser.close();
  }

  console.log('');
  if (failures.length) {
    console.error(failures.length + ' of ' + cases + ' cases FAILED');
    failures.forEach((f) => console.error('  - ' + f));
    process.exit(1);
  }
  console.log(cases + ' of ' + cases + ' cases passed');
}

run().catch((e) => { console.error(e); process.exit(1); });
