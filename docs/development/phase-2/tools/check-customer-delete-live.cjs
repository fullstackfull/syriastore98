'use strict';
/* P2-05-2. The delete control was converted from an inline `onsubmit` confirm to `.show_confirm`.
   That only works if custom.js's delegated handler AND SweetAlert are on this page, and if the
   click really submits the form. A styling change that silently breaks delete would be worse than
   the inconsistency it fixes, so this drives it rather than reading it. */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8899';
let pass = 0, fail = 0;
const ck = (ok, m, extra) => { ok ? pass++ : fail++; console.log('  ' + (ok ? 'PASS' : 'FAIL') + '  ' + m + (extra ? '  :: ' + extra : '')); };

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
  const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(String(e.message).slice(0, 120)));
  let nativeDialog = false;
  p.on('dialog', async d => { nativeDialog = true; await d.dismiss(); });
  /* THE ROW COUNT IS NOT A RELIABLE SIGNAL HERE and asserting on it cost a false failure:
     `tbody tr` on this page is 5 while there are only 2 customer rows, because DataTables emits
     rows that are not data rows. The submitted REQUEST is the thing being tested, so that is what
     is measured. */
  const writes = [];
  p.on('request', r => { if (r.method() !== 'GET') writes.push({ m: r.method(), u: r.url() }); });
  p.on('response', r => {
    if (r.request().method() !== 'GET') writes.push({ status: r.status(), u: r.url() });
  });

  await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await p.fill('input[name="email"]', 'merchant@example.test');
  await p.fill('input[name="password"]', 'password');
  await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
  await p.goto(BASE + '/customer', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1500);

  const env = await p.evaluate(() => {
    const btns = [...document.querySelectorAll('.show_confirm')];
    return {
      swal: typeof window.Swal,
      jq: typeof window.jQuery,
      buttons: btns.length,
      insideForm: btns.length > 0 && btns.every(el => !!el.closest('form')),
      methodSpoof: btns.length > 0 && btns.every(el => {
        const m = el.closest('form') && el.closest('form').querySelector('input[name="_method"]');
        return m && m.value.toUpperCase() === 'DELETE';
      }),
      hasCsrf: btns.length > 0 && btns.every(el =>
        !!(el.closest('form') && el.closest('form').querySelector('input[name="_token"]'))),
      inlineOnsubmit: document.querySelectorAll('form[onsubmit]').length,
      named: btns.length > 0 && btns.every(el => (el.getAttribute('aria-label') || '').trim().length > 0),
      rows: document.querySelectorAll('tbody tr').length,
    };
  });
  ck(env.swal === 'function' || env.swal === 'object', 'SweetAlert (Swal) is loaded on /customer', 'typeof=' + env.swal);
  ck(env.jq === 'function', 'jQuery is loaded, which custom.js delegates through');
  ck(env.buttons > 0, 'the delete buttons carry .show_confirm', 'count=' + env.buttons);
  ck(env.insideForm, 'every delete button is inside its form');
  ck(env.methodSpoof, 'every delete form spoofs DELETE');
  ck(env.hasCsrf, 'every delete form carries a CSRF token');
  ck(env.inlineOnsubmit === 0, 'no inline onsubmit confirm remains', 'count=' + env.inlineOnsubmit);
  ck(env.named, 'every delete button has an accessible name');

  await p.click('.show_confirm');
  await p.waitForTimeout(1200);
  const dlg = await p.evaluate(() => {
    const c = document.querySelector('.swal2-container, .swal2-popup');
    return { shown: !!c, text: c ? (c.textContent || '').trim().slice(0, 90) : '' };
  });
  ck(dlg.shown, 'clicking delete opens the project SweetAlert dialog', JSON.stringify(dlg.text));
  ck(!nativeDialog, 'it is NOT a raw browser confirm()');

  /* A REAL Playwright click. `el.click()` inside page.evaluate did NOT drive SweetAlert's own
     handler, and that is what made the first version of this check report a working delete as
     broken. */
  writes.length = 0;
  await p.click('.swal2-confirm');
  await p.waitForTimeout(4000);

  const posted = writes.find(w => w.m === 'POST' && /\/customer\/\d+$/.test(w.u));
  const answered = writes.find(w => w.status && /\/customer\/\d+$/.test(w.u));
  ck(!!posted, 'confirming the dialog POSTs the delete to the customer route',
     JSON.stringify(writes.slice(0, 4)));
  ck(!!answered && answered.status < 400,
     'the delete is accepted, not refused',
     answered ? ('status=' + answered.status) : 'no response seen');

  ck(errs.length === 0, 'no page errors', errs.slice(0, 2).join(' | '));
  await ctx.close(); await b.close();
  console.log('\n  ===== ' + pass + ' pass, ' + fail + ' fail =====');
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('ERR ' + e.message); process.exit(2); });
