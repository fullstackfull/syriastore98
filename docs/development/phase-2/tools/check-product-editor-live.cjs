'use strict';
/**
 * P2-03-3 -- the product editor's save affordances, driven in a real browser.
 *
 * The case that matters is the last one: the sticky button must really SAVE, through the
 * existing handler, because the whole point of proxying rather than re-implementing is that
 * there is one submit path. A geometry assertion would not have proved that.
 */
const { chromium } = require('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const failures = []; let cases = 0;
const check = (l, c, d) => { cases++; if (c) console.log('  ok    ' + l);
  else { failures.push(l); console.error('  FAIL  ' + l + (d === undefined ? '' : '  [' + d + ']')); } };

async function login(p) {
  await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await p.fill('input[name="email"]', 'merchant@example.test');
  await p.fill('input[name="password"]', 'password');
  await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
}

(async () => {
  const browser = await chromium.launch();
  try {
    for (const w of [1440, 390]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: 900 } });
      const p = await ctx.newPage();
      const errs = []; p.on('pageerror', e => errs.push(e.message));
      await login(p);
      await p.goto(BASE + '/product/create', { waitUntil: 'networkidle' });
      await p.waitForTimeout(1200);
      const tag = w + 'px';

      check(tag + ': no page error on the editor', errs.length === 0, errs.join(' | '));

      const bar = await p.locator('.ss-actionbar').count();
      check(tag + ': the action bar is rendered', bar === 1, 'count=' + bar);

      const inForm = await p.evaluate(() =>
        !!document.querySelector('#choice_form .ss-actionbar'));
      check(tag + ': it is INSIDE the form, so the dirty marker selector reaches it', inForm);

      const offset = await p.evaluate(() =>
        document.getElementById('choice_form').classList.contains('ss-has-actionbar'));
      check(tag + ': the form carries the scroll offset (focus not obscured)', offset);

      /* The marker must be armed by real input, not by focus. */
      let marker = await p.evaluate(() => {
        const m = document.querySelector('.ss-dirty-marker');
        return { shown: m ? getComputedStyle(m).display !== 'none' : null,
                 dirty: document.getElementById('choice_form').getAttribute('data-ss-dirty') };
      });
      check(tag + ': the unsaved marker is hidden before any input', marker.shown === false, JSON.stringify(marker));

      await p.fill('input[name="name"]', 'Density Probe ' + w);
      await p.waitForTimeout(300);
      marker = await p.evaluate(() => {
        const m = document.querySelector('.ss-dirty-marker');
        return { shown: m ? getComputedStyle(m).display !== 'none' : null,
                 dirty: document.getElementById('choice_form').getAttribute('data-ss-dirty') };
      });
      check(tag + ': typing arms the unsaved-changes marker', marker.shown === true && marker.dirty === 'true',
        JSON.stringify(marker));

      /* THE REASON THE BAR EXISTS: reachable with the form scrolled to the bottom. */
      await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
      await p.waitForTimeout(400);
      const reach = await p.evaluate(() => {
        const header = document.getElementById('submit-all');
        const sticky = document.querySelector('.ss-actionbar [data-ss-click-proxy]');
        const hr = header ? header.getBoundingClientRect() : null;
        const sr = sticky ? sticky.getBoundingClientRect() : null;
        return {
          headerY: hr ? Math.round(hr.top) : null,
          headerVisible: hr ? (hr.top >= 0 && hr.bottom <= innerHeight) : null,
          stickyVisible: sr ? (sr.top >= 0 && sr.bottom <= innerHeight && sr.width > 0) : null,
          onTop: sr ? ((document.elementFromPoint(Math.round(sr.left + sr.width / 2),
                                                  Math.round(sr.top + sr.height / 2)) || {})
                        .closest ? !!(document.elementFromPoint(Math.round(sr.left + sr.width / 2),
                                                  Math.round(sr.top + sr.height / 2))).closest('.ss-actionbar') : false) : null,
        };
      });
      check(tag + ': the header Save really is off-screen at the bottom (the defect)',
        reach.headerVisible === false, 'headerY=' + reach.headerY);
      check(tag + ': the sticky Save IS visible there', reach.stickyVisible === true, JSON.stringify(reach));
      check(tag + ': and nothing covers it', reach.onTop === true, JSON.stringify(reach));
      await ctx.close();
    }

    /* ---- the one that proves the proxy: a real save through the existing handler ---- */
    {
      const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
      const p = await ctx.newPage();
      await login(p);
      await p.goto(BASE + '/product', { waitUntil: 'networkidle' });
      await p.waitForSelector('#product-table tbody tr', { timeout: 20000 });
      const before = await p.evaluate(() => {
        const t = document.querySelector('#product-table_info');
        return t ? t.textContent.trim() : null;
      });

      await p.goto(BASE + '/product/create', { waitUntil: 'networkidle' });
      await p.waitForTimeout(1200);
      const unique = 'Sticky Save Probe ' + Date.now().toString().slice(-6);
      await p.fill('input[name="name"]', unique);
      await p.fill('input[name="price"]', '12');
      await p.fill('input[name="cost_price"]', '5');
      // category + tag are required by the markup
      await p.selectOption('select[name="category_id"]', { index: 1 }).catch(() => {});
      await p.waitForTimeout(400);

      await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
      await p.waitForTimeout(300);
      await p.click('.ss-actionbar [data-ss-click-proxy]');
      await p.waitForTimeout(3500);

      await p.goto(BASE + '/product', { waitUntil: 'networkidle' });
      await p.waitForSelector('#product-table tbody tr', { timeout: 20000 });
      await p.fill('#product-table_filter input, input[type="search"]', unique).catch(() => {});
      await p.waitForTimeout(1500);
      const found = await p.evaluate((u) => document.body.innerText.indexOf(u) !== -1, unique);
      check('THE STICKY BUTTON REALLY SAVES, through the existing #submit-all handler',
        found === true, 'product "' + unique + '" not found in the list after save (before: ' + before + ')');
      await ctx.close();
    }
  } finally { await browser.close(); }

  console.log('');
  if (failures.length) { console.error(failures.length + ' of ' + cases + ' cases FAILED');
    failures.forEach(f => console.error('  - ' + f)); process.exit(1); }
  console.log(cases + ' of ' + cases + ' cases passed');
})().catch(e => { console.error(e); process.exit(1); });
