/**
 * P2.5 finance-money-figure-contrast -- measure the REAL rendered colour at /finance.
 *
 * METHOD, STATED HONESTLY BECAUSE IT IS NOT ALL NATIVE:
 *
 *  - The dev server is shared with other chains and the brief forbids editing its fixture, so
 *    `cust_darklayout` is NOT flipped. Whatever theme the server currently serves is measured
 *    NATIVELY; the other theme is produced by INTERCEPTING the request for #main-style-link and
 *    answering it with the bytes of the sibling sheet, then setting/clearing `data-ss-theme` the
 *    way the layout's own inline script would. That is an exact reproduction: the only difference
 *    between the layout's light and dark branches is which of the four sheets #main-style-link
 *    points at -- custom.css/rtl-custom.css and store-syria-ds.css are loaded in BOTH.
 *
 *  - `store_wallet_transactions` is EMPTY in database/p2dev.sqlite (checked: zero rows), so the
 *    Credit/Debit columns have no live rows to measure. The KPI at index.blade.php:155 does
 *    render and is measured as served. For the four table sites the exact rendered markup is
 *    injected into the real <tbody> of the real page, so the cascade, the surfaces and the loaded
 *    stylesheets are all the real ones -- and the pre-fix markup is injected beside it as the
 *    negative control, so before and after are measured in the same page.
 *
 * usage:  docs/development/phase-2.5/tools/serve-dev.sh   (in another shell, left running)
 *         OUT=/tmp/shots node docs/development/phase-2.5/tools/measure-finance-money-contrast-live.cjs
 *
 * Asserted in the suite by Tests\Unit\Ui\FinanceMoneyFigureContrastTest.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const OUT = process.env.OUT || '/tmp/finance-money-contrast';
const REPO = '/home/user/syriastore98';
fs.mkdirSync(OUT, { recursive: true });

function lum([r,g,b]){const c=v=>{const s=v/255;return s<=0.03928?s/12.92:Math.pow((s+0.055)/1.055,2.4)};return .2126*c(r)+.7152*c(g)+.0722*c(b)}
function ratio(a,b){const l1=lum(a),l2=lum(b);return (Math.max(l1,l2)+.05)/(Math.min(l1,l2)+.05)}
const parse=v=>{const m=/rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(v||'');return m?[+m[1],+m[2],+m[3]]:null};

// sheet substitution: which file answers for which requested path
const SHEETS = {
  'light-rtl': 'public/assets/css/style-rtl.css',
  'dark-rtl':  'public/assets/css/rtl-style-dark.css',
  'light-ltr': 'public/assets/css/style.css',
  'dark-ltr':  'public/assets/css/style-dark.css',
};

const INJECT = `
  const tb = document.querySelector('#transactions table tbody') || document.querySelector('table tbody');
  if (!tb) return 'no tbody';
  const mk = (cls, text, label) => '<tr data-probe="1"><td colspan="3">' + label + '</td>' +
    '<td class="text-end"><span class="' + cls + '" data-probe-cell="' + label + '">' + text + '</span></td></tr>';
  tb.insertAdjacentHTML('afterbegin',
      mk('text-success fw-bold', '+1,234.00', 'BEFORE credit .text-success')
    + mk('text-danger fw-bold',  '-1,234.00', 'BEFORE debit .text-danger')
    + mk('ss-money ss-money--credit fw-bold', '+1,234.00', 'AFTER credit .ss-money--credit')
    + mk('ss-money ss-money--debit fw-bold',  '-1,234.00', 'AFTER debit .ss-money--debit'));
  return 'ok';
`;

const READ = `
  const out = [];
  const eff = (el) => { let n = el, bg = getComputedStyle(el).backgroundColor;
    while (n && /rgba\\(0, 0, 0, 0\\)|transparent/.test(bg)) { n = n.parentElement; if (!n) break; bg = getComputedStyle(n).backgroundColor; }
    return bg; };
  document.querySelectorAll('[data-probe-cell]').forEach(el => {
    out.push({ label: el.getAttribute('data-probe-cell'), fg: getComputedStyle(el).color, bg: eff(el) });
  });
  // the live KPI at index.blade.php:155
  document.querySelectorAll('h4 .ss-money, h4.ss-money, h4.text-success, h4.text-danger').forEach(el => {
    out.push({ label: 'LIVE KPI Potential Profit [' + el.className + ']  "' + (el.innerText||'').trim() + '"',
               fg: getComputedStyle(el).color, bg: eff(el) });
  });
  return out;
`;

(async () => {
  const browser = await chromium.launch();

  for (const combo of ['native', 'light-rtl', 'dark-rtl', 'light-ltr', 'dark-ltr']) {
    const ctx = await browser.newContext({ viewport: { width: 1500, height: 1050 } });
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', e => errs.push(String(e).slice(0, 140)));

    if (combo !== 'native') {
      const body = fs.readFileSync(path.join(REPO, SHEETS[combo]));
      await page.route('**/assets/css/*.css', r => r.fulfill({ status: 200, contentType: 'text/css', body }));
    }

    await page.goto('http://127.0.0.1:8899/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', 'merchant@example.test');
    await page.fill('input[name="password"]', 'password');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);

    const resp = await page.goto('http://127.0.0.1:8899/finance', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(700);

    if (combo !== 'native') {
      const dark = combo.startsWith('dark');
      const ltr = combo.endsWith('ltr');
      await page.evaluate(([dark, ltr]) => {
        document.documentElement.setAttribute('data-ss-theme', dark ? 'dark' : 'light');
        if (ltr) { document.documentElement.setAttribute('dir', 'ltr'); document.body.style.direction = 'ltr'; }
      }, [dark, ltr]);
      await page.waitForTimeout(400);
    }

    await page.evaluate(() => { const t = document.querySelector('#transactions-tab'); if (t) t.click(); });
    await page.waitForTimeout(400);
    const injected = await page.evaluate(new Function(INJECT));
    await page.waitForTimeout(300);
    const rows = await page.evaluate(new Function(READ));

    const attr = await page.evaluate(() => document.documentElement.getAttribute('data-ss-theme'));
    const dir = await page.evaluate(() => getComputedStyle(document.body).direction);
    const href = await page.evaluate(() => document.getElementById('main-style-link').getAttribute('href'));

    console.log(`\n================ ${combo}  HTTP ${resp.status()}  data-ss-theme=${attr}  dir=${dir} ================`);
    console.log(`  #main-style-link requested: ${href.replace('http://127.0.0.1:8899','')}` +
                (combo === 'native' ? '  (served as-is)' : `  -> answered with ${SHEETS[combo]}`));
    console.log(`  probe rows injected: ${injected}`);
    for (const r of rows) {
      const fg = parse(r.fg), bg = parse(r.bg);
      const v = (fg && bg) ? ratio(fg, bg) : null;
      const verdict = v === null ? '' : (v >= 4.5 ? '  AA pass' : (v >= 3 ? '  << below 4.5:1' : '  <<< below 3:1'));
      console.log(`   ${(v === null ? 'n/a' : v.toFixed(2) + ':1').padStart(8)}  fg ${String(r.fg).padEnd(20)} on ${String(r.bg).padEnd(20)}  ${r.label}${verdict}`);
    }
    if (errs.length) console.log('  pageerrors:', errs.slice(0, 3));
    await page.screenshot({ path: `${OUT}/finance-${combo}.png` });
    await ctx.close();
  }
  await browser.close();
})();
