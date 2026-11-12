/**
 * P2.5 FINAL VERIFICATION — the money tones and the staff-card accessible names, NATIVELY.
 * =================================================================================================
 *
 * WHAT THIS ADDS OVER THE TWO TOOLS IT SUPERSEDES, and why both were weaker:
 *
 *   measure-finance-money-contrast-live.cjs  substituted the theme by INTERCEPTING the request for
 *                                            #main-style-link, and INJECTED the ledger markup into
 *                                            the real <tbody> because `store_wallet_transactions`
 *                                            and `store_order_financials` held no usable rows. Real
 *                                            cascade, real surfaces -- but not the real page, and
 *                                            not the app's own theme selection.
 *   the staff-card measurement               read Chromium's accessible-name tree over CDP, but
 *                                            only in Arabic: the English names were never measured
 *                                            because writing the shared preference state was
 *                                            forbidden while sibling chains were running.
 *
 * Both limitations were consequences of running concurrently. Nothing is concurrent now, so this
 * drives the REAL preference state with set-admin-preview-state.php and measures what the
 * application itself renders, in all four locale/theme combinations, with no interception and no
 * injected markup. The rows come from seed-dev-interaction-fixtures.php, which now seeds one credit
 * and one debit wallet transaction and gives two of the probe orders' financial rows a profit and a
 * loss, so every one of the six repaired money figures has something real to paint.
 *
 * PREMISES ARE ASSERTED, NOT ASSUMED, because a harness that measures the light theme twice reports
 * confident numbers about nothing -- which has happened twice in this project. Each combination
 * checks the direction the layout really emitted and the sheet it really requested against what was
 * asked for, and a combination failing either is reported as PREMISE-FAIL and not as a reading.
 *
 * usage:  docs/development/phase-2.5/tools/serve-dev.sh      (in another shell, left running)
 *         DB_CONNECTION=sqlite DB_DATABASE=<repo>/database/p2dev.sqlite CACHE_STORE=file \
 *           php docs/development/phase-2.5/tools/seed-dev-interaction-fixtures.php
 *         OUT=<dir> node docs/development/phase-2.5/tools/verify-money-and-staff-live.cjs
 *
 * Asserted in the suite by Tests\Unit\Ui\FinanceMoneyFigureContrastTest and
 * Tests\Unit\Ui\StaffCardAccessibleNamesTest; this is the live half neither can carry.
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPO = '/home/user/syriastore98';
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const OUT = process.env.OUT || '/tmp/p25-final';
const EMAIL = 'merchant@example.test';
const PASSWORD = 'password';
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');
fs.mkdirSync(OUT, { recursive: true });

const COMBOS = [
  { key: 'en-light', lang: 'en', dir: 'ltr', theme: 'light', sheet: 'style.css' },
  { key: 'en-dark',  lang: 'en', dir: 'ltr', theme: 'dark',  sheet: 'style-dark.css' },
  { key: 'ar-light', lang: 'ar', dir: 'rtl', theme: 'light', sheet: 'style-rtl.css' },
  { key: 'ar-dark',  lang: 'ar', dir: 'rtl', theme: 'dark',  sheet: 'rtl-style-dark.css' },
];

const lum = ([r, g, b]) => {
  const c = (v) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4); };
  return 0.2126 * c(r) + 0.7152 * c(g) + 0.0722 * c(b);
};
const ratio = (a, b) => { const l1 = lum(a), l2 = lum(b); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); };
const parse = (v) => { const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(v || ''); return m ? [+m[1], +m[2], +m[3]] : null; };

function setState(c) {
  execFileSync('php', [STATE_TOOL, '1', c.theme, c.dir, c.lang, EMAIL], {
    cwd: path.dirname(STATE_TOOL),
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(REPO, 'database/p2dev.sqlite'), CACHE_STORE: 'file' },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

// Every `.ss-money` the page really rendered, with the effective background behind it.
const MONEY_PROBE = `(() => {
  const eff = (el) => { let n = el, bg = getComputedStyle(el).backgroundColor;
    while (n && /rgba\\(0, 0, 0, 0\\)|transparent/.test(bg)) { n = n.parentElement; if (!n) break; bg = getComputedStyle(n).backgroundColor; }
    return bg; };
  return [...document.querySelectorAll('.ss-money')].map((el) => ({
    cls: el.className.replace(/\\s+/g, ' ').trim(),
    text: (el.textContent || '').trim().slice(0, 14),
    fg: getComputedStyle(el).color,
    bg: eff(el),
    inHeading: !!el.closest('h1,h2,h3,h4,h5,h6'),
  }));
})()`;

(async () => {
  const browser = await chromium.launch();
  let bad = 0;

  for (const c of COMBOS) {
    setState(c);
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', (e) => errs.push(String(e).slice(0, 140)));

    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);

    // ---- PREMISES, on the finance screen.
    const finResp = await page.goto(BASE + '/finance', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(400);
    const st = await page.evaluate(() => ({
      dir: document.documentElement.getAttribute('dir'),
      theme: document.documentElement.getAttribute('data-ss-theme'),
      sheet: (document.getElementById('main-style-link') || {}).href || '',
    }));
    const sheetName = (st.sheet || '').split('/').pop().split('?')[0];
    const premise = [];
    if (st.dir !== c.dir) premise.push(`dir=${st.dir} wanted ${c.dir}`);
    if (sheetName !== c.sheet) premise.push(`sheet=${sheetName} wanted ${c.sheet}`);

    console.log(`\n================ ${c.key}  (${c.lang}/${c.dir}/${c.theme})  /finance HTTP ${finResp.status()} ================`);
    console.log(`  dir=${st.dir}  data-ss-theme=${st.theme}  #main-style-link=${sheetName}` +
                (premise.length ? `   *** PREMISE-FAIL: ${premise.join('; ')}` : '   premises ok'));
    if (premise.length) { bad++; await ctx.close(); continue; }

    const money = await page.evaluate(MONEY_PROBE);
    if (!money.length) { console.log('  NO .ss-money ELEMENTS RENDERED -- nothing measured'); bad++; }
    for (const m of money) {
      const fg = parse(m.fg), bg = parse(m.bg);
      const v = (fg && bg) ? ratio(fg, bg) : null;
      const verdict = v === null ? '' : (v >= 4.5 ? 'AA pass' : (v >= 3 ? '<< below 4.5:1' : '<<< below 3:1'));
      if (v !== null && v < 4.5) bad++;
      console.log(`   ${(v === null ? 'n/a' : v.toFixed(2) + ':1').padStart(8)}  ${String(m.cls).padEnd(34)} ` +
                  `${String(m.text).padEnd(12)} fg ${String(m.fg).padEnd(20)} on ${String(m.bg).padEnd(20)} ${verdict}` +
                  (m.inHeading ? '  [inside a heading]' : ''));
    }
    await page.screenshot({ path: path.join(OUT, `finance-${c.key}.png`), fullPage: false });

    // ---- THE STAFF CARD'S ACCESSIBLE NAMES, from Chromium's own tree over CDP.
    const usersResp = await page.goto(BASE + '/users', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(400);
    const cdp = await ctx.newCDPSession(page);
    await cdp.send('DOM.enable');
    await cdp.send('Accessibility.enable');
    const { nodes } = await cdp.send('Accessibility.getFullAXTree');
    const named = (n) => (n.name && n.name.value) || '';

    // THE NODES ARE SELECTED BY CSS, NOT BY ROLE, and the first draft of this got it wrong in a
    // way worth recording. Filtering the full tree for `role === 'image'` returns every image on
    // the chrome too -- the header logo carries a deliberate `alt=""` -- so it reported five
    // nameless images and looked like the avatar repair had failed, while a per-node query proved
    // all three avatars named `from=attribute:alt`. Ask about the element you repaired.
    const axFor = async (selector) => {
      const { root } = await cdp.send('DOM.getDocument', { depth: -1, pierce: true });
      const { nodeIds } = await cdp.send('DOM.querySelectorAll', { nodeId: root.nodeId, selector });
      const out = [];
      for (const nodeId of nodeIds) {
        const r = await cdp.send('Accessibility.getPartialAXTree', { nodeId, fetchRelatives: false });
        for (const n of r.nodes) { if (!n.ignored) out.push(n); }
      }
      return out;
    };

    const kebabs = await axFor('.card-option > button.dropdown-toggle');
    const imgs = await axFor('img.img-user');
    const links = nodes.filter((n) => n.role && n.role.value === 'link' && /Profile photo|الصورة الشخصية/.test(named(n)));
    const heads = nodes.filter((n) => n.role && n.role.value === 'heading');

    console.log(`  /users HTTP ${usersResp.status()}`);
    const show = (label, list) => {
      if (!list.length) { console.log(`   ${label}: NONE FOUND`); bad++; return; }
      for (const n of list.slice(0, 5)) {
        const nm = named(n);
        const src = (n.name && n.name.sources || []).find((s) => s.value && s.value.value) || {};
        const codepoints = [...nm].map((ch) => ch.codePointAt(0)).filter((cp) => cp >= 0xe000 && cp <= 0xf8ff);
        if (codepoints.length) bad++;
        console.log(`   ${label}: "${nm}" len=${[...nm].length} from=${src.type || '-'}${src.attribute ? ':' + src.attribute : ''}` +
                    (codepoints.length ? `  *** PRIVATE-USE GLYPH IN NAME: ${codepoints.map((x) => 'U+' + x.toString(16).toUpperCase()).join(',')}` : ''));
      }
    };
    show('kebab ', kebabs);
    show('avatar', imgs);
    show('av-lnk', links);
    const levels = heads.map((n) => {
      const lv = (n.properties || []).find((p) => p.name === 'level');
      return `h${lv ? lv.value.value : '?'}`;
    });
    console.log(`   heading outline: ${levels.join(' ') || '(none)'}`);
    for (let i = 1; i < levels.length; i++) {
      const a = +levels[i - 1].slice(1), b = +levels[i].slice(1);
      if (b > a + 1) { console.log(`   *** OUTLINE SKIP: ${levels[i - 1]} -> ${levels[i]}`); bad++; }
    }
    await page.screenshot({ path: path.join(OUT, `users-${c.key}.png`), fullPage: false });
    if (errs.length) { console.log('   pageerrors:', errs.slice(0, 2)); }
    await ctx.close();
  }

  await browser.close();
  console.log(`\n${bad} problem(s). screenshots in ${OUT}`);
  process.exit(bad ? 1 : 0);
})();
