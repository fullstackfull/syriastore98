/**
 * P2.5-P1 — the status switch's BOUNDARY, read off the running page in all four combinations.
 * =================================================================================================
 *
 * WHY A SEPARATE MEASUREMENT, when `shoot-fragments.cjs` already photographs this cell.
 *
 * A 1px border is a few hundredths of the pixels in a switch, so a sampler that reports the
 * DOMINANT colour of the control never sees it. That is not a hypothetical: after this defect was
 * fixed, the pixel sampler still reported the checked track at 2.22:1, because the fill had not
 * changed -- only the border had. A border has to be read from the computed style, not from the
 * picture.
 *
 * WHAT FAILED, measured on /customer before the repair:
 *
 *   light, unchecked   fill #FFFFFF on a white cell = 1.00:1, leaving only the border,
 *                      rgba(0,0,0,.25) flattened to #BFBFBF = 1.84:1     <- below 3:1
 *   dark,  checked     fill #335DA8 on cell #292A33 = 2.22:1             <- below 3:1
 *
 * THE OBVIOUS FIX WAS MEASURED AND REJECTED. Re-colouring the checked fill to the dark brand
 * (--ss-cyan-500, 6.81:1 on the cell) fixes the boundary and wrecks the STATE difference: cyan
 * against the white unchecked track is 2.09:1, worse than the 6.42:1 the two states have today.
 * Fill carries state, border carries boundary; only the border was changed.
 *
 * WHAT THIS ASSERTS, per state per combination:
 *   boundary = max(contrast(fill, cell), contrast(flattened border, cell)) >= 3.0
 *   states   = contrast(checked fill, unchecked fill) >= 3.0
 *
 * The second line is the one that stops a "fix" from passing by making both states invisible in
 * the same way.
 *
 * usage:  node docs/development/phase-2.5/tools/measure-switch-boundary-live.cjs
 *         (needs serve-dev.sh on 127.0.0.1:8899)
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const REPO = '/home/user/syriastore98';
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const EMAIL = process.env.EMAIL || 'merchant@example.test';
const PASSWORD = process.env.PASSWORD || 'password';
const STORE = process.env.STORE || '1';
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

/** WCAG 2.2 SC 1.4.11 Non-text Contrast. */
const FLOOR = 3.0;

const COMBOS = [
  { lang: 'en', dir: 'ltr', theme: 'light' },
  { lang: 'en', dir: 'ltr', theme: 'dark' },
  { lang: 'ar', dir: 'rtl', theme: 'light' },
  { lang: 'ar', dir: 'rtl', theme: 'dark' },
];

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

const chan = (v) => {
  const s = v / 255;
  return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
};
const lum = ([r, g, b]) => 0.2126 * chan(r) + 0.7152 * chan(g) + 0.0722 * chan(b);
const contrast = (a, b) => {
  const la = lum(a);
  const lb = lum(b);
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
};
const parse = (s) => {
  const n = (String(s).match(/[\d.]+/g) || []).map(Number);
  return { rgb: [n[0] || 0, n[1] || 0, n[2] || 0], a: n.length > 3 ? n[3] : 1 };
};
const flatten = (fg, a, bg) => fg.map((c, i) => Math.round(c * a + bg[i] * (1 - a)));

(async () => {
  let problems = 0;
  let measured = 0;

  console.log('');
  console.log('combination      state       fill             border(flat)     boundary  states');
  console.log('-'.repeat(92));

  for (const c of COMBOS) {
    setState(c.theme, c.dir, c.lang);

    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle').catch(() => {});

    await page.goto(BASE + '/customer');
    await page.waitForSelector('.page-checkbox', { timeout: 15000 }).catch(() => {});

    // THE CELL'S PAINTED GROUND, not the <td>'s own background -- the row and the card beneath it
    // are what actually paint, and a transparent td would otherwise read as black.
    const rows = await page.evaluate(() => {
      const painted = (el) => {
        for (let n = el; n; n = n.parentElement) {
          const bg = getComputedStyle(n).backgroundColor;
          const m = (bg.match(/[\d.]+/g) || []).map(Number);
          if (m.length >= 3 && (m.length < 4 || m[3] > 0.95)) return bg;
        }
        return 'rgb(255,255,255)';
      };

      const out = [];
      for (const el of document.querySelectorAll('.page-checkbox')) {
        const cs = getComputedStyle(el);
        out.push({
          checked: el.checked,
          bg: cs.backgroundColor,
          border: cs.borderColor,
          cell: painted(el.closest('td') || el),
        });
      }
      return out;
    });

    await browser.close();

    if (rows.length === 0) {
      console.log(`${(c.lang + '_' + c.dir + '_' + c.theme).padEnd(16)} NO SWITCH FOUND  <-- FAIL`);
      problems++;
      continue;
    }

    const fills = {};

    for (const state of [true, false]) {
      const r = rows.find((x) => x.checked === state);
      if (!r) continue;

      const cell = parse(r.cell).rgb;
      const fill = parse(r.bg);
      const border = parse(r.border);

      const fillC = contrast(fill.rgb, cell);
      const borderFlat = flatten(border.rgb, border.a, cell);
      const borderC = contrast(borderFlat, cell);
      const boundary = Math.max(fillC, borderC);

      fills[state ? 'on' : 'off'] = fill.rgb;
      measured++;

      const ok = boundary >= FLOOR;
      if (!ok) problems++;

      const hex = (x) => '#' + x.map((v) => Math.round(v).toString(16).padStart(2, '0')).join('').toUpperCase();

      console.log(
        `${(c.lang + '_' + c.dir + '_' + c.theme).padEnd(16)} `
        + `${(state ? 'checked' : 'unchecked').padEnd(11)} `
        + `${hex(fill.rgb)} ${fillC.toFixed(2).padStart(5)}  `
        + `${hex(borderFlat)} ${borderC.toFixed(2).padStart(5)}  `
        + `${boundary.toFixed(2).padStart(6)}${ok ? '  ok' : '  <-- FAIL'}`,
      );
    }

    // THE PROPERTY A NAIVE FIX WOULD BREAK: the two states must still look different.
    if (fills.on && fills.off) {
      const states = contrast(fills.on, fills.off);
      const ok = states >= FLOOR;
      if (!ok) problems++;
      console.log(
        `${''.padEnd(16)} ${'states apart'.padEnd(11)} `
        + `${''.padEnd(37)}${states.toFixed(2).padStart(6)}${ok ? '  ok' : '  <-- FAIL'}`,
      );
    }
  }

  console.log('');
  console.log(`${measured} switch state(s) measured, ${problems} problem(s). Floor ${FLOOR}:1.`);
  process.exit(problems === 0 ? 0 : 1);
})();
