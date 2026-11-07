/**
 * THE PINNED ACTIONS COLUMN, AND WHETHER IT IS PINNED TO ANYTHING.
 *
 * `.ss-col-pinned` is `position: sticky; inset-inline-end: 0`, and the design-system comment
 * beside it says the table "scrolls under both". Sticky resolves against the nearest SCROLLPORT,
 * so if the card has no horizontal scroll container there is none to resolve against: the cell
 * pins to the viewport instead and is dragged INLINE-START of its own natural position by exactly
 * the table's overflow, landing on the column beside it with an opaque background.
 *
 * Reports, per screen and direction:
 *   - the table's overflow beyond its scroll container
 *   - that container's computed overflow-x (the question: is there a scrollport at all)
 *   - how far the pinned cell has moved from its natural position
 *   - how many pixels of the NEIGHBOUR'S TEXT it covers -- the user-visible symptom
 *
 * usage: node measure-pinned-column-overlap.cjs --paths /customer,/order --dirs ltr,rtl
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
const DIRS = arg('dirs', 'ltr,rtl').split(',').map(s => s.trim());
const THEMES = arg('themes', 'light').split(',').map(s => s.trim());
const WIDTH = parseInt(arg('width', '1440'), 10);

const SCAN = () => {
  const rect = el => el.getBoundingClientRect();
  const textRect = (el) => {
    const tn = [...el.childNodes].find(n => n.nodeType === 3 && n.textContent.trim());
    if (!tn) return null;
    const r = document.createRange(); r.selectNodeContents(tn);
    const b = r.getBoundingClientRect();
    return b.width ? b : null;
  };
  const out = [];
  for (const table of document.querySelectorAll('table')) {
    const pinned = table.querySelector('thead th.ss-col-pinned');
    if (!pinned) continue;
    // the nearest ancestor that could scroll horizontally
    let port = table.parentElement, portInfo = null;
    while (port && port !== document.documentElement) {
      const ox = getComputedStyle(port).overflowX;
      if (ox === 'auto' || ox === 'scroll' || ox === 'hidden') { portInfo = { el: port.className.toString().slice(0, 30), overflowX: ox }; break; }
      port = port.parentElement;
    }
    const holder = table.parentElement;
    const hs = getComputedStyle(holder);
    const ths = [...table.querySelectorAll('thead th')];
    const i = ths.indexOf(pinned);
    const neighbour = i > 0 ? ths[i - 1] : null;
    const pr = rect(pinned), nr = neighbour ? rect(neighbour) : null;
    const nt = neighbour ? textRect(neighbour) : null;
    const dir = getComputedStyle(document.documentElement).direction;
    // natural position = where the cell would sit with no sticky offset: flush after its neighbour
    const movedBy = nr ? (dir === 'rtl' ? Math.round(pr.right - nr.left) : Math.round(nr.right - pr.left)) : null;
    const coversText = nt ? (dir === 'rtl' ? Math.round(pr.right - nt.left) : Math.round(nt.right - pr.left)) : null;
    out.push({
      dir,
      holder: holder.className.toString().slice(0, 34),
      holderOverflowX: hs.overflowX,
      scrollport: portInfo,
      tableWidth: Math.round(table.getBoundingClientRect().width),
      holderWidth: Math.round(holder.getBoundingClientRect().width),
      overflow: Math.round(table.getBoundingClientRect().width - holder.getBoundingClientRect().width),
      pinnedHeader: pinned.textContent.trim().slice(0, 14),
      neighbour: neighbour ? neighbour.textContent.trim().slice(0, 14) : null,
      pinnedMovedOverNeighbourBy: movedBy > 0 ? movedBy : 0,
      coversNeighbourTextBy: coversText > 0 ? coversText : 0,
    });
  }
  return out;
};

(async () => {
  const b = await chromium.launch({ args: ['--no-sandbox'] });
  let bad = 0;
  for (const theme of THEMES) for (const dir of DIRS) {
    execFileSync('php', [TOOL, '1', theme, dir, dir === 'rtl' ? 'ar' : 'en', 'merchant@example.test'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
    const ctx = await b.newContext({ viewport: { width: WIDTH, height: 1000 } });
    const p = await ctx.newPage();
    await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await p.fill('input[name="email"]', 'merchant@example.test');
    await p.fill('input[name="password"]', 'password');
    await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
    for (const path of PATHS) {
      const r = await p.goto(BASE + path, { waitUntil: 'domcontentloaded' }).catch(() => null);
      if (!r || r.status() !== 200) { console.log(`${path} ${dir}/${theme}  HTTP ${r ? r.status() : 'ERR'}`); continue; }
      await p.waitForTimeout(1200);
      const rows = await p.evaluate(SCAN);
      if (!rows.length) { console.log(`${path.padEnd(16)} ${dir}/${theme}  no pinned column`); continue; }
      for (const x of rows) {
        const sym = x.coversNeighbourTextBy > 0 ? `COVERS "${x.neighbour}" TEXT BY ${x.coversNeighbourTextBy}px` :
                    x.pinnedMovedOverNeighbourBy > 0 ? `overlaps "${x.neighbour}" by ${x.pinnedMovedOverNeighbourBy}px (text clear)` : 'clear';
        if (x.coversNeighbourTextBy > 0) bad++;
        console.log(`${path.padEnd(16)} ${dir}/${theme}  table ${x.tableWidth} in ${x.holderWidth} (over ${x.overflow}px), holder overflow-x: ${x.holderOverflowX}, scrollport: ${x.scrollport ? x.scrollport.overflowX + ' @ .' + x.scrollport.el : 'NONE'}`);
        console.log(`${' '.repeat(16)}  pinned "${x.pinnedHeader}" -> ${sym}`);
      }
    }
    await ctx.close();
  }
  execFileSync('php', [TOOL, '1', 'light', 'ltr', 'en', 'merchant@example.test'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
  await b.close();
  console.log(`\n${bad} table(s) where the pinned column covers its neighbour's text`);
  process.exit(bad ? 1 : 0);
})();
