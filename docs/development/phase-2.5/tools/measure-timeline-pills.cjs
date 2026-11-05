/**
 * P2.5-WAVE-16 — measure the REAL rendered contrast of every customer-timeline pill.
 *
 * WHY THIS TOOL IS COMMITTED, AND WHY IT HAS NO `--dir` FLAG
 * =================================================================================================
 * Wave 16b was measured with a throwaway script in /tmp that drove `ltr` only. The fix was applied
 * to public/css/custom.css, the measurement went green, and the wave was reported done. It was a
 * HALF-FIX: public/css/rtl-custom.css is a byte-identical SIBLING sheet, loaded for every Arabic
 * merchant, and it still carried all seven failing colours. Commit 32ced221 therefore reached
 * about half the merchants and was reported as if it reached all of them.
 *
 * So this tool takes no direction argument. It walks all four combinations every run and exits
 * non-zero if ANY of them fails, which makes the mistake above unrepresentable rather than merely
 * discouraged. It is committed for the same reason: a tool in /tmp cannot be re-run by the next
 * person, so its coverage cannot be audited.
 *
 * WHAT IS MEASURED: the pill's own computed background against its own computed colour, read off
 * the live page -- not the hex in the stylesheet. That matters because .tl-btn variants inherit
 * their text colour, and because the pills sit on `.custom-timeline-inner`, whose dark arm this
 * same wave added; a pill measured against the wrong ground reads as passing.
 *
 * PRECONDITIONS, stated rather than assumed (the tool checks each and says so):
 *   - the dev server is up:        docs/development/phase-2.5/tools/serve-dev.sh
 *   - activity_logs has rows:      php database/seed-p2dev.php   (7 rows, one per log_type)
 *     Without them `count($logs)` is 0, the pills do not render, and a zero-element reading looks
 *     exactly like a pass. That happened once in this wave and was withdrawn.
 *   - the fixture merchant can open the screen: grant-dev-screen-permissions.php ... "Show Customer"
 *
 * usage:  OUT=/tmp/pills node docs/development/phase-2.5/tools/measure-timeline-pills.cjs
 *
 * Asserted in the suite by Tests\Unit\Ui\TimelinePillContrastTest (the hex floor) and
 * Tests\Unit\Ui\DarkTimelineSurfaceContrastTest (both sheets carry it).
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPO = '/home/user/syriastore98';
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const OUT = process.env.OUT || '/tmp/timeline-pills';
const EMAIL = process.env.EMAIL || 'merchant@example.test';
const PASSWORD = process.env.PASSWORD || 'password';
const STORE = process.env.STORE || '1';
const CUSTOMER = process.env.CUSTOMER || '1';
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

// WCAG 2.2: normal text 4.5:1, large text (>=18.66px bold or >=24px) 3:1. The pills are 10-12px,
// so 4.5 applies to every one of them -- large-text relief is NOT available here.
const FLOOR = 4.5;

// All four. Not configurable -- see the docblock.
const COMBOS = [
  { lang: 'en', dir: 'ltr', theme: 'light' },
  { lang: 'en', dir: 'ltr', theme: 'dark' },
  { lang: 'ar', dir: 'rtl', theme: 'light' },
  { lang: 'ar', dir: 'rtl', theme: 'dark' },
];

function lum([r, g, b]) {
  const c = (v) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4); };
  return 0.2126 * c(r) + 0.7152 * c(g) + 0.0722 * c(b);
}
function ratio(a, b) { const l1 = lum(a), l2 = lum(b); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); }

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

// Read every pill on the page with its EFFECTIVE background (walking up past transparent ancestors,
// which is what the eye actually sees and what a naive getComputedStyle read gets wrong).
const READ = `
  const eff = (el) => {
    let n = el;
    while (n) {
      const bg = getComputedStyle(n).backgroundColor;
      const m = /rgba?\\((\\d+),\\s*(\\d+),\\s*(\\d+)(?:,\\s*([\\d.]+))?/.exec(bg || '');
      if (m && (m[4] === undefined || parseFloat(m[4]) > 0.01)) return bg;
      n = n.parentElement;
    }
    return getComputedStyle(document.body).backgroundColor;
  };
  // THE REAL PILL SET, read from the view rather than guessed. customer/timeline.blade.php emits
  // 'tl-btn licence-btn' for EVERY log type (the other three .tl-btn variants belong to other
  // screens and never render here), and distinguishes the seven entry kinds with a modifier on
  // '.end-date'. A first pass of this tool measured '.end-date' alone, caught the first modifier
  // it found, and reported 1 of 7 pills as if it were the set -- the same shape of miss as the
  // ltr-only run it was written to prevent.  (No backticks here: this comment is inside a
  // template literal.)
  const SITES = [
    ['.tl-btn.licence-btn',          'pill .tl-btn'],
    ['.end-date.register-timeline',     'pill register'],
    ['.end-date.last-login-timeline',   'pill last-login'],
    ['.end-date.wishlist-timeline',     'pill add-wishlist'],
    ['.end-date.dlt-wishlist-timeline', 'pill del-wishlist'],
    ['.end-date.add-cart-timeline',     'pill add-cart'],
    ['.end-date.dlt-cart-timeline',     'pill del-cart'],
    ['.end-date.order-timeline',        'pill place-order'],
    ['.tl-box .time-wrapper .time',  'timestamp .time'],
  ];
  const out = [];
  for (const [sel, label] of SITES) {
    const els = Array.from(document.querySelectorAll('.custom-timeline-inner ' + sel));
    if (!els.length) { out.push({ label, sel, missing: true }); continue; }
    const el = els[0];
    const cs = getComputedStyle(el);
    out.push({
      label, sel, missing: false, count: els.length,
      fg: cs.color, bg: eff(el),
      size: parseFloat(cs.fontSize), weight: cs.fontWeight,
      text: (el.textContent || '').trim().slice(0, 28),
    });
  }
  return {
    sites: out,
    container: eff(document.querySelector('.custom-timeline-inner') || document.body),
    box: eff(document.querySelector('.tl-box') || document.body),
    body: getComputedStyle(document.body).backgroundColor,
    logRows: document.querySelectorAll('.custom-timeline-inner .tl-box').length,
    sheet: (document.getElementById('main-style-link') || {}).href || '',
    htmlDir: document.documentElement.getAttribute('dir'),
  };
`;

const parse = (v) => { const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(v || ''); return m ? [+m[1], +m[2], +m[3]] : null; };

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  const rows = [];
  let premiseFailures = 0;

  for (const combo of COMBOS) {
    setState(combo.theme, combo.dir, combo.lang);
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();

    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.click('button[type="submit"], input[type="submit"]'),
    ]);
    const signedIn = !/\/login\b/.test(page.url());

    const r = await page.goto(BASE + '/customer-timeline/' + CUSTOMER, { waitUntil: 'domcontentloaded' }).catch(() => null);
    await page.waitForTimeout(600);
    const st = await page.evaluate('(() => {' + READ + '})()');

    const key = `${combo.lang}_${combo.dir}_${combo.theme}`;
    const sheetName = (st.sheet || '').split('/').pop();

    // PREMISES. A reading taken when any of these is false is not evidence.
    const premise = [];
    if (!signedIn) premise.push('not signed in');
    if (!r || r.status() !== 200) premise.push('status=' + (r ? r.status() : 0));
    if ((st.htmlDir === 'rtl') !== (combo.dir === 'rtl')) premise.push('dir=' + st.htmlDir);
    if (/dark/.test(sheetName) !== (combo.theme === 'dark')) premise.push('sheet=' + sheetName);
    // the one that caught the false pass: no rows means no pills, which is not a pass
    if (!st.logRows) premise.push('activity_logs empty -- 0 timeline rows rendered, nothing to measure');
    if (premise.length) premiseFailures++;

    await page.screenshot({ path: path.join(OUT, `timeline-pills__${key}.png`), fullPage: false }).catch(() => {});

    for (const s of st.sites) {
      if (s.missing) {
        rows.push({ combo: key, label: s.label, missing: true, premise });
        continue;
      }
      const fg = parse(s.fg), bg = parse(s.bg);
      const cr = fg && bg ? ratio(fg, bg) : 0;
      rows.push({
        combo: key, label: s.label, missing: false, count: s.count,
        fg: s.fg, bg: s.bg, size: s.size, weight: s.weight, text: s.text,
        ratio: +cr.toFixed(2), pass: cr >= FLOOR, premise,
      });
    }
    rows.push({ combo: key, label: '(ground) .custom-timeline-inner', ground: true, bg: st.container, box: st.box, body: st.body, premise });

    await ctx.close();
  }

  await browser.close();
  fs.writeFileSync(path.join(OUT, 'timeline-pills.json'), JSON.stringify(rows, null, 1));

  let fails = 0, measured = 0, missing = 0;
  console.log('\ncombo                 site                       fg / bg                          size  ratio   AA');
  console.log('-'.repeat(112));
  for (const row of rows) {
    if (row.ground) {
      console.log(`${row.combo.padEnd(21)} ${row.label.padEnd(26)} inner=${(row.bg || '').padEnd(20)} box=${row.box || ''}  body=${row.body || ''}`);
      continue;
    }
    if (row.missing) {
      missing++;
      console.log(`${row.combo.padEnd(21)} ${row.label.padEnd(26)} NOT RENDERED (selector found nothing)`);
      continue;
    }
    measured++;
    if (!row.pass) fails++;
    const pair = `${row.fg} / ${row.bg}`;
    console.log(`${row.combo.padEnd(21)} ${row.label.padEnd(26)} ${pair.padEnd(32)} ${String(row.size).padEnd(5)} ${String(row.ratio).padEnd(6)} ${row.pass ? 'pass' : 'FAIL'}`);
  }

  console.log(`\n${measured} sites measured across ${COMBOS.length} combinations, ${fails} below ${FLOOR}:1, ${missing} not rendered.`);
  if (premiseFailures) console.log(`PREMISE FAILED in ${premiseFailures} combination(s) -- the readings above are NOT evidence.`);
  console.log(`-> ${OUT}`);
  process.exit(fails || missing || premiseFailures ? 1 : 0);
})();
