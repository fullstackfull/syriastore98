/**
 * EVERY ELEMENT PAINTING THE MERCHANT'S BRAND COLOUR AS TEXT, and what it measures.
 *
 * `--ss-brand-ontext` (layouts/app.blade.php) darkens the merchant's colour until it clears AA
 * -- against a HARD-CODED LIGHT PAGE, `[249,250,251]`. For a merchant whose colour is already
 * dark the loop exits on the first test and returns the brand colour unchanged, which is correct
 * on white and is the failure on a dark page. The previous package measured dark as compliant and
 * said so in custom.css, but it measured the BRIGHT GREEN preset (#10B77F, 5.34:1). Store Syria's
 * own blue (#335da8) is 2.22:1 on the same surface.
 *
 * So this does not look for a colour I nominated: it reads `--theme-color` off the live body and
 * finds every element whose computed text colour IS that colour, then measures each against its
 * own composited background. A site that passes is reported too -- the question is which sites
 * fail, not how many I can list.
 *
 * usage: node find-brand-text-failures.cjs --paths /dashboard,/finance --themes dark
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
const THEMES = arg('themes', 'dark').split(',').map(s => s.trim());

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

  // The brand is read from the page, not nominated here.
  const raw = getComputedStyle(document.body).getPropertyValue('--theme-color').trim();
  const hex = raw.replace('#', '');
  const brand = /^[0-9a-f]{6}$/i.test(hex)
    ? [parseInt(hex.slice(0, 2), 16), parseInt(hex.slice(2, 4), 16), parseInt(hex.slice(4, 6), 16)]
    : parse(raw).slice(0, 3);

  const visible = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return false;
    const cs = getComputedStyle(el);
    return cs.visibility !== 'hidden' && cs.display !== 'none' && parseFloat(cs.opacity) > 0.05;
  };

  const out = [];
  for (const el of document.querySelectorAll('*')) {
    const own = [...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim());
    if (!own || !visible(el)) continue;
    const c = parse(getComputedStyle(el).color).slice(0, 3);
    if (c.length < 3) continue;
    // "is the brand colour" with a tolerance, because a composited or rounded value may be off by one
    if (Math.abs(c[0] - brand[0]) + Math.abs(c[1] - brand[1]) + Math.abs(c[2] - brand[2]) > 6) continue;
    const bg = paintedBg(el);
    const size = parseFloat(getComputedStyle(el).fontSize);
    const bold = parseInt(getComputedStyle(el).fontWeight, 10) >= 700;
    const floor = (size >= 24 || (bold && size >= 18.66)) ? 3 : 4.5;
    out.push({
      cls: (el.className || '').toString().trim().slice(0, 46) || el.tagName.toLowerCase(),
      text: el.textContent.trim().replace(/\s+/g, ' ').slice(0, 26),
      bg: `rgb(${bg.join(',')})`, ratio: Number(ratio(c, bg).toFixed(2)), floor,
    });
  }
  return { brand: `rgb(${brand.join(',')})`, hits: out };
};

(async () => {
  const b = await chromium.launch({ args: ['--no-sandbox'] });
  for (const theme of THEMES) {
    execFileSync('php', [TOOL, '1', theme, 'ltr', 'en', 'merchant@example.test'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
    const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
    const p = await ctx.newPage();
    await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await p.fill('input[name="email"]', 'merchant@example.test');
    await p.fill('input[name="password"]', 'password');
    await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
    let totalFail = 0, totalHit = 0;
    for (const path of PATHS) {
      const resp = await p.goto(BASE + path, { waitUntil: 'domcontentloaded' }).catch(() => null);
      if (!resp || resp.status() !== 200) { console.log(`${path} ${theme}  HTTP ${resp ? resp.status() : 'ERR'}`); continue; }
      await p.waitForTimeout(900);
      const r = await p.evaluate(SCAN);
      const fail = r.hits.filter(h => h.ratio < h.floor);
      totalFail += fail.length; totalHit += r.hits.length;
      console.log(`\n${path}  ${theme}   brand ${r.brand}   ${r.hits.length} sites painting it, ${fail.length} FAIL`);
      const seen = new Set();
      for (const h of fail) {
        const k = h.cls + '|' + h.ratio; if (seen.has(k)) continue; seen.add(k);
        console.log(`    ${String(h.ratio).padStart(5)}:1 (needs ${h.floor})  on ${h.bg.padEnd(16)} .${h.cls}   "${h.text}"`);
      }
    }
    console.log(`\n== ${theme}: ${totalFail} of ${totalHit} brand-as-text sites fail AA`);
    await ctx.close();
  }
  execFileSync('php', [TOOL, '1', 'light', 'ltr', 'en', 'merchant@example.test'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
  await b.close();
})();
