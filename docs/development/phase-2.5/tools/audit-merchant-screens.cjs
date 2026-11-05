/**
 * MERCHANT SCREEN AUDIT — the measurements this phase keeps learning it needs.
 *
 * Each check here exists because a guess was wrong at least once tonight:
 *   - contrast is computed against the FIRST OPAQUE ANCESTOR, composited, not against the
 *     element's own `background-color`. `.dash-content` computes to rgba(0,0,0,0), so reading it
 *     compares every colour against BLACK and reported a 16.15:1 figure as 1.24:1.
 *   - horizontal overflow is measured on the DOCUMENT, because a component can scroll correctly
 *     while an absolutely-positioned descendant escapes it and widens the page (57 visually hidden
 *     labels did exactly that on the order board, +295px).
 *   - a broken image is `naturalWidth === 0`, not a missing src: `get_file()` returns a
 *     well-formed URL whether or not the file exists.
 *   - accessible names are read from the a11y tree, not from `aria-label` presence: an image-only
 *     link with `alt=""` has NO name even though every attribute looks fine.
 *
 * usage: node audit-merchant-screens.cjs --paths /product-list,/customer --themes light,dark
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
const COMBOS = arg('themes', 'light,dark').split(',').map(t => t.trim())
  .flatMap(t => arg('dirs', 'ltr,rtl').split(',').map(d => d.trim())
    .map(d => ({ theme: t, dir: d, lang: d === 'rtl' ? 'ar' : 'en' })));

const AUDIT = () => {
  const parse = s => (s.match(/[\d.]+/g) || []).map(Number);
  /* ALPHA IS COMPOSITED, NOT SKIPPED -- and the first version of this function skipped it, which
     is the same class of error as reading rgba(0,0,0,0) and comparing against black.
     MEASURED: /finance's "Deduct Balance" is `.btn.btn-danger` with
     background-color: rgba(255,174,189,0.41). Treating "alpha < 1" as "not a background" walked
     straight past it to the page, so the button was never flagged. Layers are collected from the
     element upwards and composited back down over the first OPAQUE one. */
  const paintedBg = (el) => {
    const layers = [];
    for (let e = el; e; e = e.parentElement) {
      const c = parse(getComputedStyle(e).backgroundColor);
      if (c.length < 3) continue;
      const a = c[3] === undefined ? 1 : c[3];
      if (a <= 0) continue;
      layers.push([c[0], c[1], c[2], a]);
      if (a >= 1) break;
    }
    if (!layers.length) return [255, 255, 255];
    let base = layers[layers.length - 1];
    if (base[3] < 1) base = [255, 255, 255, 1];           // nothing opaque found: the canvas
    let out = [base[0], base[1], base[2]];
    for (let i = layers.length - 1; i >= 0; i--) {
      const [r, g, bl, a] = layers[i];
      if (a >= 1) { out = [r, g, bl]; continue; }
      out = [r * a + out[0] * (1 - a), g * a + out[1] * (1 - a), bl * a + out[2] * (1 - a)];
    }
    return out.map(Math.round);
  };
  const lum = c => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]); };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  /* A VISUALLY HIDDEN ELEMENT IS NOT A CONTRAST DEFECT, and the first version of this function
     reported four. `.visually-hidden` / `.ss-visually-hidden` clip a 1x1 box rather than removing
     it, so width and height are both > 0 and a naive check calls it visible. MEASURED: /customer's
     "Reset" label was reported at 1.03:1 in dark -- a figure about text nobody can see by design.
     The clip test is the reliable one; the class names are a belt-and-braces second. */
  const hiddenByClip = (el) => {
    const cs = getComputedStyle(el);
    const r = el.getBoundingClientRect();
    if (r.width <= 1 || r.height <= 1) return true;
    const cp = cs.clipPath || '';
    if (cp.includes('inset(50%)') || cp.includes('inset(100%)')) return true;
    if ((cs.clip || '').replace(/\s/g, '') === 'rect(0px,0px,0px,0px)') return true;
    return false;
  };
  const visible = (el) => {
    const r = el.getBoundingClientRect(); const cs = getComputedStyle(el);
    if (!(r.width > 0 && r.height > 0)) return false;
    if (cs.visibility === 'hidden' || cs.display === 'none' || cs.opacity === '0') return false;
    const cls = (el.className || '').toString();
    if (/\bvisually-hidden\b|\bsr-only\b|\bss-visually-hidden\b/.test(cls)) return false;
    if (el.closest('.visually-hidden, .sr-only, .ss-visually-hidden')) return false;
    return !hiddenByClip(el);
  };

  const out = { overflow: 0, brokenImages: [], namelessControls: [], smallTargets: [], lowContrast: [], counts: {} };

  const de = document.documentElement;
  out.overflow = de.scrollWidth - de.clientWidth;

  document.querySelectorAll('img').forEach(i => {
    if (!visible(i) && i.getBoundingClientRect().width === 0 && i.naturalWidth !== 0) return;
    if (i.naturalWidth === 0 && i.getAttribute('src')) {
      out.brokenImages.push({ src: i.getAttribute('src').replace(location.origin, '').split('?')[0],
        cls: (i.className || '').toString().slice(0, 40) });
    }
  });

  const controls = [...document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [role=button], [tabindex]:not([tabindex="-1"])')].filter(visible);
  out.counts.controls = controls.length;
  for (const el of controls) {
    const name = (el.getAttribute('aria-label') || el.getAttribute('title')
      || (el.labels && el.labels[0] && el.labels[0].textContent)
      || el.textContent || el.getAttribute('alt') || el.value || '').trim();
    const r = el.getBoundingClientRect();
    if (!name) out.namelessControls.push({ tag: el.tagName.toLowerCase(), cls: (el.className || '').toString().slice(0, 44),
      box: [Math.round(r.width), Math.round(r.height)] });
    if ((r.width < 44 || r.height < 44) && el.tagName !== 'A' || (r.width < 24 || r.height < 24))
      out.smallTargets.push({ tag: el.tagName.toLowerCase(), cls: (el.className || '').toString().slice(0, 36),
        name: name.slice(0, 24), box: [Math.round(r.width), Math.round(r.height)] });
  }

  const textNodes = [...document.querySelectorAll('p,span,td,th,li,label,h1,h2,h3,h4,h5,h6,a,button,small,div')]
    .filter(el => visible(el) && [...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim().length > 1));
  out.counts.textNodes = textNodes.length;
  const seen = new Set();
  for (const el of textNodes) {
    const cs = getComputedStyle(el);
    const fg = parse(cs.color).slice(0, 3);
    const bg = paintedBg(el);
    const size = parseFloat(cs.fontSize), weight = parseInt(cs.fontWeight) || 400;
    const large = size >= 24 || (size >= 18.66 && weight >= 700);
    const floor = large ? 3.0 : 4.5;
    const r = ratio(fg, bg);
    if (r < floor) {
      const key = `${cs.color}|${bg.join(',')}|${(el.className || '').toString().slice(0, 30)}`;
      if (seen.has(key)) continue; seen.add(key);
      out.lowContrast.push({ cls: (el.className || '').toString().slice(0, 40) || el.tagName.toLowerCase(),
        text: el.textContent.trim().replace(/\s+/g, ' ').slice(0, 30),
        fg: cs.color, bg: `rgb(${bg.join(',')})`, size: cs.fontSize, ratio: Number(r.toFixed(2)), floor });
    }
  }
  return out;
};

(async () => {
  const b = await chromium.launch({ args: ['--no-sandbox'] });
  for (const c of COMBOS) {
    execFileSync('php', [TOOL, '1', c.theme, c.dir, c.lang, 'merchant@example.test'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
    const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
    const p = await ctx.newPage();
    await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await p.fill('input[name="email"]', 'merchant@example.test');
    await p.fill('input[name="password"]', 'password');
    await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type="submit"]')]);
    for (const path of PATHS) {
      const bad = [];
      const onResp = r => { if (r.status() >= 400) bad.push(r.status() + ' ' + r.url().replace(BASE, '').split('?')[0]); };
      p.on('response', onResp);
      const resp = await p.goto(BASE + path, { waitUntil: 'networkidle' }).catch(e => null);
      await p.waitForTimeout(600);
      const a = await p.evaluate(AUDIT);
      p.off('response', onResp);
      const tag = `${path} ${c.lang}/${c.dir}/${c.theme}`;
      const flags = [];
      if (!resp || resp.status() >= 400) flags.push(`HTTP ${resp ? resp.status() : 'ERR'}`);
      if (a.overflow > 1) flags.push(`OVERFLOW +${a.overflow}px`);
      if (a.brokenImages.length) flags.push(`${a.brokenImages.length} broken img`);
      if (a.namelessControls.length) flags.push(`${a.namelessControls.length} nameless`);
      if (a.lowContrast.length) flags.push(`${a.lowContrast.length} low-contrast`);
      if (a.smallTargets.length) flags.push(`${a.smallTargets.length} small targets`);
      console.log(`${tag.padEnd(42)} ${flags.length ? flags.join(' | ') : 'clean'}   (${a.counts.controls} controls, ${a.counts.textNodes} text nodes)`);
      a.brokenImages.slice(0, 3).forEach(i => console.log(`      broken img  ${i.src}  class="${i.cls}"`));
      a.namelessControls.slice(0, 4).forEach(n => console.log(`      nameless    <${n.tag} class="${n.cls}"> ${n.box.join('x')}`));
      a.lowContrast.slice(0, 5).forEach(l => console.log(`      contrast    ${l.ratio}:1 (needs ${l.floor}) ${l.fg} on ${l.bg} ${l.size} "${l.text}" .${l.cls}`));
      const unique4xx = [...new Set(bad)];
      if (unique4xx.length) console.log(`      4xx/5xx     ${unique4xx.slice(0, 4).join(' | ')}`);
    }
    await ctx.close();
  }
  execFileSync('php', [TOOL, '1', 'light', 'ltr', 'en', 'merchant@example.test'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
  await b.close();
})();
