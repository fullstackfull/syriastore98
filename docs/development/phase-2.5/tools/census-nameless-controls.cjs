/**
 * EVERY CONTROL A SCREEN READER WOULD ANNOUNCE AS NOTHING, grouped by COMPONENT.
 *
 * audit-merchant-screens.cjs counts these per screen; this groups them by (tag, class) across
 * screens, so the output names the component to fix rather than listing one row per table row --
 * a delete button in a ten-row table is one defect, not ten.
 *
 * THE NAME IS COMPUTED THE WAY THE AUDIT TOOL COMPUTES IT, and that is a correction to a first
 * draft of this file. That draft asked Playwright's `accessibility.snapshot({root})` for each
 * control's name and reported 196 unnamed controls across five screens -- including
 * `<button class="btn btn-primary">Request Withdrawal</button>`, whose name is its own text. The
 * instrument was wrong, not the pages. The DOM computation below is an approximation of the
 * accessible-name algorithm, and it is the same one the audit tool uses, so the two agree.
 *
 * It looks one level further than the audit tool does: an icon-only control usually wraps an
 * `<img>` or an `<svg>`, so a descendant `alt` or `<title>` is a real name and its ABSENCE is the
 * defect. `<i class="ti ti-eye">` is an icon FONT and carries no name at all.
 *
 * usage: node census-nameless-controls.cjs --paths /customer,/order [--dir rtl]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const REPO = '/home/user/syriastore98';
const TOOL = REPO + '/docs/development/phase-2/tools/set-admin-preview-state.php';
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: REPO + '/database/p2dev.sqlite', CACHE_STORE: 'file' };
const BASE = arg('base', 'http://127.0.0.1:8899');
const PATHS = arg('paths', '/customer').split(',').map(s => s.trim()).filter(Boolean);
const DIR = arg('dir', 'ltr');

const SCAN = () => {
  const visible = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return false;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none') return false;
    return !el.closest('.visually-hidden, .sr-only, .ss-visually-hidden');
  };
  const name = (el) => {
    const own = (el.getAttribute('aria-label') || el.getAttribute('title')
      || (el.labels && el.labels[0] && el.labels[0].textContent)
      || el.textContent || el.getAttribute('alt') || el.value || '').trim();
    if (own) return own;
    const img = el.querySelector('img[alt]');
    if (img && img.getAttribute('alt').trim()) return img.getAttribute('alt').trim();
    const t = el.querySelector('svg > title');
    if (t && t.textContent.trim()) return t.textContent.trim();
    // PLACEHOLDER IS A NAME, and leaving it out overstated this census by three. The
    // accessible-name algorithm uses `placeholder` as a last resort for a text input, so
    // `<input type="search" placeholder="Search...">` IS announced. It is still a finding under
    // `ui-ux-pro-max` `input-labels` ("visible label per input, not placeholder-only"), so it is
    // reported in its own bucket rather than counted as nameless.
    if (el.tagName === 'INPUT' && (el.getAttribute('placeholder') || '').trim()) {
      return '\u0000placeholder:' + el.getAttribute('placeholder').trim();
    }
    const labelled = el.getAttribute('aria-labelledby');
    if (labelled) {
      const ref = document.getElementById(labelled.split(/\s+/)[0]);
      if (ref && ref.textContent.trim()) return ref.textContent.trim();
    }
    return '';
  };
  const out = [];
  const sel = 'a[href], button, input:not([type=hidden]), select, textarea, [role=button], [tabindex]:not([tabindex="-1"])';
  for (const el of document.querySelectorAll(sel)) {
    if (!visible(el)) continue;
    const n = name(el);
    if (n && !n.startsWith('\u0000placeholder:')) continue;
    const r = el.getBoundingClientRect();
    out.push({
      tag: el.tagName.toLowerCase(),
      cls: (el.className || '').toString().trim().replace(/\s+/g, '.').slice(0, 44) || '(none)',
      box: [Math.round(r.width), Math.round(r.height)],
      html: el.outerHTML.slice(0, 110).replace(/\s+/g, ' '),
      placeholderOnly: n.startsWith('\u0000placeholder:') ? n.slice(13) : null,
    });
  }
  return out;
};

(async () => {
  const b = await chromium.launch({ args: ['--no-sandbox'] });
  execFileSync('php', [TOOL, '1', 'light', DIR, DIR === 'rtl' ? 'ar' : 'en', 'merchant@example.test'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 1000 } });
  const p = await ctx.newPage();
  await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await p.fill('input[name="email"]', 'merchant@example.test');
  await p.fill('input[name="password"]', 'password');
  await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);

  const census = new Map();
  let total = 0;
  for (const path of PATHS) {
    const r = await p.goto(BASE + path, { waitUntil: 'domcontentloaded' }).catch(() => null);
    if (!r || r.status() !== 200) { console.log(`${path}: HTTP ${r ? r.status() : 'ERR'}`); continue; }
    await p.waitForTimeout(1100);
    for (const h of await p.evaluate(SCAN)) {
      total++;
      const key = `${h.tag}.${h.cls}`;
      if (!census.has(key)) census.set(key, { ...h, screens: new Set(), n: 0 });
      census.get(key).n++; census.get(key).screens.add(path);
    }
  }
  await ctx.close();
  execFileSync('php', [TOOL, '1', 'light', 'ltr', 'en', 'merchant@example.test'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
  await b.close();

  const all = [...census.values()];
  const rows = all.filter(r => !r.placeholderOnly).sort((a, c) => c.n - a.n);
  const ph = all.filter(r => r.placeholderOnly);
  for (const r of rows) {
    console.log(`\nx${String(r.n).padEnd(3)} ${String(r.box[0] + 'x' + r.box[1]).padEnd(8)} <${r.tag}> .${r.cls}`);
    console.log(`        ${[...r.screens].join(' ')}`);
    console.log(`        ${r.html}`);
  }
  const nameless = rows.reduce((a, r) => a + r.n, 0);
  console.log(`\n${nameless} UNNAMED controls in ${rows.length} distinct components`);
  if (ph.length) {
    console.log(`\nplaceholder-only (named, but no visible label -- ui-ux-pro-max \`input-labels\`):`);
    ph.forEach(r => console.log(`  x${r.n}  <${r.tag}> .${r.cls}   placeholder "${r.placeholderOnly}"   ${[...r.screens].join(' ')}`));
  }
})();
