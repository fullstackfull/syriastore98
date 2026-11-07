/**
 * Find every colour on a rendered merchant screen that is NOT part of the Store Syria identity.
 *
 * Reading screenshots by eye found two greens and missed the rest. This walks every element in the
 * live page, reads the COMPUTED colour, background-color, border colours, outline and fill, and
 * reports each distinct chromatic value with where it came from — so "the brand is applied" is a
 * list of exceptions rather than an impression.
 *
 * WHAT COUNTS AS ON-BRAND. The logo's own gradient spans hue 189.4-218.9 degrees (measured from
 * the artwork, see App\Support\Branding\StoreSyriaBrand). A colour is reported when it is
 * chromatic -- HSV saturation above 0.18 and value above 0.12, which excludes the greys and the
 * near-blacks -- and its hue falls outside that band, UNLESS it is one of the semantic status
 * hues the owner's decision requires to stay distinct: success green ~145, warning amber ~40,
 * danger red ~3, info indigo ~258. Those are listed separately rather than hidden, because a
 * status hue appearing where no status is shown is itself a defect.
 *
 * usage: node find-offbrand-colours.cjs --paths /dashboard,/category [--lang ar --theme dark]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const BASE = arg('base', 'http://127.0.0.1:8899');
const REPO = '/home/user/syriastore98';
const PATHS = arg('paths', '/dashboard').split(',').map((s) => s.trim()).filter(Boolean);
const LANG = arg('lang', 'en'), DIR = LANG === 'ar' ? 'rtl' : 'ltr', THEME = arg('theme', 'light');
const STATE = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

const BRAND = { lo: 189.4, hi: 218.9 };

/*
 * The design system's NEUTRAL ramp, by value. These are greys -- blue-biased to the brand's hue
 * since 2026-11-03 -- and a near-black like #1a1d23 still measures saturation 0.26, so a pure
 * saturation floor cannot separate "a grey that leans blue" from "a colour". Listing them is
 * exact where a threshold is a guess, and it keeps the report about COLOURS.
 */
const NEUTRALS = new Set([
  '#fbfbfc', '#f6f7f8', '#eeeff1', '#dddfe3', '#cdd0d5', '#888c93', '#6b6f76', '#5b5f66',
  '#43474d', '#2b2e34', '#1a1d23', '#0b121f',
  '#1b1f25', '#262b33', '#1f232a', '#2d323b', '#e8eaed', '#a7abb2', '#9599a0', '#363b44',
  '#7d828a', '#21252c', '#c3c6cc',
]);
const STATUS = [
  { name: 'success green', hue: 145, tol: 18 },
  { name: 'warning amber', hue: 40, tol: 18 },
  { name: 'danger red', hue: 3, tol: 14 },
  { name: 'danger red (wrap)', hue: 357, tol: 14 },
  { name: 'info indigo', hue: 258, tol: 18 },
];

(async () => {
  execFileSync('php', [STATE, '1', THEME, DIR, LANG, 'merchant@example.test'], {
    cwd: path.dirname(STATE),
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(REPO, 'database/p2dev.sqlite'), CACHE_STORE: 'file' },
    stdio: ['ignore', 'pipe', 'pipe'],
  });

  const browser = await chromium.launch();
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 })).newPage();
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', 'merchant@example.test');
  await page.fill('input[name="password"]', 'password');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);

  let offbrand = 0;
  for (const p of PATHS) {
    await page.goto(BASE + p, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1200);

    const found = await page.evaluate(({ BRAND, STATUS, NEUTRALS }) => {
      const parse = (v) => {
        const m = /rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?\)/.exec(v || '');
        if (!m) return null;
        const a = m[4] === undefined ? 1 : parseFloat(m[4]);
        if (a < 0.25) return null;
        return [+m[1], +m[2], +m[3]];
      };
      const hsv = ([r, g, b]) => {
        r /= 255; g /= 255; b /= 255;
        const mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn;
        let h = 0;
        if (d) {
          if (mx === r) h = ((g - b) / d) % 6; else if (mx === g) h = (b - r) / d + 2; else h = (r - g) / d + 4;
          h *= 60; if (h < 0) h += 360;
        }
        return { h, s: mx ? d / mx : 0, v: mx };
      };
      const hex = ([r, g, b]) => '#' + [r, g, b].map((x) => Math.round(x).toString(16).padStart(2, '0')).join('');
      const label = (el) => {
        const id = el.id ? '#' + el.id : '';
        const cls = (el.className && typeof el.className === 'string')
          ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.') : '';
        return (el.tagName.toLowerCase() + id + cls).slice(0, 72);
      };
      const out = {};
      for (const el of document.querySelectorAll('*')) {
        const r = el.getBoundingClientRect();
        if (!r.width || !r.height) continue;
        const cs = getComputedStyle(el);
        for (const prop of ['color', 'backgroundColor', 'borderTopColor', 'borderInlineStartColor',
          'borderInlineEndColor', 'borderBottomColor', 'outlineColor', 'fill']) {
          const rgb = parse(cs[prop]); if (!rgb) continue;
          const { h, s, v } = hsv(rgb);
          if (s < 0.18 || v < 0.12) continue;
          if (NEUTRALS.includes(hex(rgb))) continue;
          const inBrand = h >= BRAND.lo && h <= BRAND.hi;
          const status = STATUS.find((st) => Math.abs(h - st.hue) <= st.tol);
          if (inBrand) continue;
          const key = hex(rgb) + '|' + (status ? status.name : 'OFF-BRAND');
          (out[key] = out[key] || { hue: h, n: 0, where: [], status: status ? status.name : null });
          out[key].n++;
          if (out[key].where.length < 3) out[key].where.push(label(el) + ' {' + prop + '}');
        }
      }
      return out;
    }, { BRAND, STATUS, NEUTRALS: [...NEUTRALS] });

    console.log(`\n=== ${p}  (${LANG}/${DIR}/${THEME}) ===`);
    const rows = Object.entries(found).sort((a, b) => b[1].n - a[1].n);
    const bad = rows.filter(([, v]) => !v.status);
    if (!bad.length) console.log('   no off-brand chromatic colour');
    for (const [key, v] of bad) {
      offbrand++;
      console.log(`   OFF-BRAND ${key.split('|')[0]}  hue ${v.hue.toFixed(1).padStart(5)}  x${String(v.n).padEnd(4)} ${v.where.join(' , ')}`);
    }
    const st = rows.filter(([, v]) => v.status);
    if (st.length) {
      console.log('   -- semantic status hues (expected, listed for review):');
      for (const [key, v] of st) console.log(`      ${key.split('|')[0]}  hue ${v.hue.toFixed(1).padStart(5)}  x${String(v.n).padEnd(4)} ${v.status}  ${v.where[0]}`);
    }
  }

  await browser.close();
  console.log(`\n${offbrand} off-brand colour(s) across ${PATHS.length} screen(s)`);
  process.exit(offbrand ? 1 : 0);
})();
