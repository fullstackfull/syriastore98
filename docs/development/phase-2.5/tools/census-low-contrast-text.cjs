/**
 * A CENSUS OF EVERY LOW-CONTRAST TEXT COLOUR, grouped by the colour itself.
 *
 * audit-merchant-screens.cjs prints the first five findings per screen, which is right for a
 * sweep and wrong for planning a fix: it hides how many distinct COLOURS are involved and how far
 * each one reaches. The dark sheet emits TWO different muted greys -- rgb(108,117,125), which is
 * Bootstrap's default leaking through, and rgb(128,129,145), which is the dark theme's own -- and
 * a fix aimed at one of them would leave the other, while the five-line output looked addressed.
 *
 * Groups by (colour, composited background, theme) and reports every class that paints it, so the
 * override set is chosen from the census rather than from the first few lines of a sweep.
 *
 * usage: node census-low-contrast-text.cjs --paths /a,/b --themes light,dark
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const REPO = '/home/user/syriastore98';
const TOOL = REPO + '/docs/development/phase-2/tools/set-admin-preview-state.php';
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: REPO + '/database/p2dev.sqlite', CACHE_STORE: 'file' };
const BASE = arg('base', 'http://127.0.0.1:8899');
const PATHS = arg('paths', '/dashboard').split(',').map(s => s.trim()).filter(Boolean);
const THEMES = arg('themes', 'light,dark').split(',').map(s => s.trim());

const SCAN = () => {
  const parse = s => (s.match(/[\d.]+/g) || []).map(Number);
  const paintedBg = (el) => {
    const L = [];
    for (let e = el; e; e = e.parentElement) {
      const c = parse(getComputedStyle(e).backgroundColor); if (c.length < 3) continue;
      const a = c[3] === undefined ? 1 : c[3]; if (a <= 0) continue;
      L.push([c[0], c[1], c[2], a]); if (a >= 1) break;
    }
    if (!L.length) return [255, 255, 255];
    let out = L[L.length - 1][3] >= 1 ? L[L.length - 1].slice(0, 3) : [255, 255, 255];
    for (let i = L.length - 1; i >= 0; i--) {
      const [r, g, b, a] = L[i];
      if (a >= 1) { out = [r, g, b]; continue; }
      out = [r * a + out[0] * (1 - a), g * a + out[1] * (1 - a), b * a + out[2] * (1 - a)];
    }
    return out.map(Math.round);
  };
  const lum = c => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]); };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };

  const out = [];
  for (const el of document.querySelectorAll('*')) {
    if (![...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())) continue;
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) continue;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none' || parseFloat(cs.opacity) <= 0.05) continue;
    const fg = parse(cs.color).slice(0, 3); if (fg.length < 3) continue;
    const bg = paintedBg(el);
    const size = parseFloat(cs.fontSize);
    const floor = (size >= 24 || (parseInt(cs.fontWeight, 10) >= 700 && size >= 18.66)) ? 3 : 4.5;
    const n = ratio(fg, bg);
    if (n >= floor) continue;
    out.push({
      fg: `rgb(${fg.join(',')})`, bg: `rgb(${bg.join(',')})`, ratio: Number(n.toFixed(2)), floor,
      cls: (el.className || '').toString().trim().slice(0, 40) || el.tagName.toLowerCase(),
      text: el.textContent.trim().replace(/\s+/g, ' ').slice(0, 24),
    });
  }
  return out;
};

(async () => {
  const b = await chromium.launch({ args: ['--no-sandbox'] });
  const census = new Map();
  for (const theme of THEMES) {
    execFileSync('php', [TOOL, '1', theme, 'ltr', 'en', 'merchant@example.test'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
    const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
    const p = await ctx.newPage();
    await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await p.fill('input[name="email"]', 'merchant@example.test');
    await p.fill('input[name="password"]', 'password');
    await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
    for (const path of PATHS) {
      const resp = await p.goto(BASE + path, { waitUntil: 'domcontentloaded' }).catch(() => null);
      if (!resp || resp.status() !== 200) { console.log(`  ${path} ${theme}: HTTP ${resp ? resp.status() : 'ERR'}`); continue; }
      await p.waitForTimeout(900);
      for (const h of await p.evaluate(SCAN)) {
        const key = `${theme}|${h.fg}|${h.bg}`;
        if (!census.has(key)) census.set(key, { theme, fg: h.fg, bg: h.bg, ratio: h.ratio, floor: h.floor, n: 0, classes: new Set(), samples: new Set(), screens: new Set() });
        const row = census.get(key);
        row.n++; row.classes.add(h.cls); row.screens.add(path);
        if (row.samples.size < 4) row.samples.add(h.text);
      }
    }
    await ctx.close();
  }
  execFileSync('php', [TOOL, '1', 'light', 'ltr', 'en', 'merchant@example.test'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
  await b.close();

  const rows = [...census.values()].sort((a, b2) => a.theme.localeCompare(b2.theme) || b2.n - a.n);
  let total = 0;
  for (const r of rows) {
    total += r.n;
    console.log(`\n${r.theme.toUpperCase().padEnd(5)} ${r.fg} on ${r.bg}  ${r.ratio}:1 (needs ${r.floor})  x${r.n} on ${r.screens.size} screens`);
    console.log(`      screens: ${[...r.screens].join(' ')}`);
    console.log(`      classes: ${[...r.classes].slice(0, 7).join(' | ')}`);
    console.log(`      e.g.   : ${[...r.samples].map(s => '"' + s + '"').join(' ')}`);
  }
  console.log(`\n${total} failing text nodes in ${rows.length} distinct (colour, background, theme) groups`);
})();
