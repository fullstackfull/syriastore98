/**
 * PACKAGE A — WHAT THE NAVIGATION SHELL AND THE DASHBOARD ACTUALLY MEASURE.
 *
 * The owner's directive is that premium is not a contrast ratio: layout, information hierarchy,
 * iconography, spacing and responsiveness are the work. So this measures those, and it measures
 * exactly the rules `ui-ux-pro-max`'s ux-guidelines.csv returned for the query
 * "admin sidebar navigation hierarchy dashboard KPI" -- rather than a checklist of my own invention:
 *
 *   Navigation / Sticky Navigation (Medium)  "Fixed nav should not obscure content. Add padding-top
 *                                             to body equal to nav height."  -> overlap, in px
 *   Accessibility / Heading Hierarchy (Med)  "Use sequential heading levels h1-h6."  -> the real
 *                                             sequence, and every skip in it
 *   Accessibility / Keyboard Navigation (Hi) "visible focus on every operable control"  -> counted
 *   Typography / Font Size Scale (Medium)    "Use consistent modular scale"  -> the distinct sizes
 *                                             actually painted, against the DS scale
 *   Navigation / Breadcrumbs (Low)           "Use for sites with 3+ levels of depth. DON'T use for
 *                                             flat single-level sites."  -> depth of the crumb
 *
 * Plus the two the directive names that no guideline covers: the icon inventory (families, sizes
 * and stroke widths, which must converge) and what is above the fold at desktop and phone.
 *
 * usage: node docs/development/phase-2.5/tools/audit-shell-and-dashboard.cjs [--theme dark] [--dir rtl]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const REPO = '/home/user/syriastore98';
const TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');
const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const THEME = arg('theme', 'light');
const DIR = arg('dir', 'ltr');
const LANG = DIR === 'rtl' ? 'ar' : 'en';
const ROUTE = arg('route', '/dashboard');

execFileSync('php', [TOOL, '1', THEME, DIR, LANG, 'merchant@example.test'], {
  cwd: path.dirname(TOOL),
  env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(REPO, 'database/p2dev.sqlite'), CACHE_STORE: 'file' },
  stdio: ['ignore', 'pipe', 'pipe'],
});

const PROBE = () => {
  const out = {};
  const rect = (el) => { const q = el.getBoundingClientRect(); return { x: Math.round(q.x), y: Math.round(q.y), w: Math.round(q.width), h: Math.round(q.height) }; };

  // ---- sticky nav overlap: does the fixed header sit on top of the first content?
  const header = document.querySelector('.dash-header, header.dash-header, .pc-header');
  const content = document.querySelector('.dash-content');
  out.header = header ? rect(header) : null;
  out.headerPosition = header ? getComputedStyle(header).position : null;
  out.content = content ? rect(content) : null;
  if (header && content) {
    const h = header.getBoundingClientRect();
    const c = content.getBoundingClientRect();
    out.navOverlapPx = Math.max(0, Math.round(h.bottom - c.top));
  }

  // ---- heading hierarchy, in document order, with the skips named
  const heads = [...document.querySelectorAll('h1,h2,h3,h4,h5,h6')]
    .filter((h) => { const cs = getComputedStyle(h); const q = h.getBoundingClientRect();
      return cs.display !== 'none' && cs.visibility !== 'hidden' && q.width > 1 && q.height > 1; });
  out.headingSequence = heads.map((h) => +h.tagName.slice(1));
  out.headingTexts = heads.slice(0, 10).map((h) => h.tagName.toLowerCase() + ' "' + (h.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 34) + '"');
  out.headingSkips = [];
  for (let i = 1; i < out.headingSequence.length; i++) {
    const d = out.headingSequence[i] - out.headingSequence[i - 1];
    if (d > 1) { out.headingSkips.push(`h${out.headingSequence[i - 1]} -> h${out.headingSequence[i]}`); }
  }

  // ---- the type scale actually painted, and how many distinct sizes there are
  const sizes = {};
  for (const el of document.querySelectorAll('.dash-content *, .dash-header *, .dash-sidebar *')) {
    if (!el.firstChild || el.firstChild.nodeType !== 3) { continue; }
    const t = (el.firstChild.nodeValue || '').trim();
    if (t.length < 2) { continue; }
    const cs = getComputedStyle(el);
    const q = el.getBoundingClientRect();
    if (q.width < 2 || cs.display === 'none') { continue; }
    const key = Math.round(parseFloat(cs.fontSize) * 100) / 100;
    sizes[key] = (sizes[key] || 0) + 1;
  }
  out.fontSizes = Object.entries(sizes).sort((a, b) => b[0] - a[0]).map(([px, n]) => `${px}px x${n}`);
  out.distinctFontSizes = Object.keys(sizes).length;

  // ---- the sidebar: geometry, item rhythm, active affordance
  const side = document.querySelector('.dash-sidebar, .pc-sidebar, nav.dash-sidebar');
  out.sidebar = side ? rect(side) : null;
  if (side) {
    const items = [...side.querySelectorAll('a')].filter((a) => a.getBoundingClientRect().height > 4);
    out.sidebarLinkCount = items.length;
    const hs = items.map((a) => Math.round(a.getBoundingClientRect().height));
    out.sidebarItemHeights = [...new Set(hs)].sort((a, b) => a - b);
    // THE ACTIVE LINK, NOT THE FIRST `.active` IN THE SIDEBAR. `side.querySelector('.active')`
    // returned `div.navbar-content.active` -- a scroll container -- so every reading this tool
    // ever printed under "sidebar active" described the wrong element and reported the active
    // state as having no background, no weight and no marker. Measured by hand against the real
    // `a.dash-link` it is white on #335da8 at 700. A tool that answers a different question than
    // the one printed beside it is worse than no tool.
    const describe = (el) => el ? {
      sel: el.tagName.toLowerCase() + '.' + String(el.className).trim().split(/\s+/).slice(0, 2).join('.'),
      bg: getComputedStyle(el).backgroundColor, color: getComputedStyle(el).color,
      weight: getComputedStyle(el).fontWeight,
      borderInlineStart: getComputedStyle(el).borderInlineStartWidth + ' ' + getComputedStyle(el).borderInlineStartColor,
    } : null;
    out.sidebarActiveTop = describe(side.querySelector('.dash-navbar > li.dash-item.active > a.dash-link'));
    out.sidebarActiveSub = describe(side.querySelector('.dash-submenu > li.dash-item.active > a.dash-link'));
    out.sidebarActiveSibling = describe(side.querySelector('.dash-submenu > li.dash-item:not(.active) > a.dash-link'));
    // `.ss-navgroup` IS THE CLASS. The four this line used to look for -- .pc-caption,
    // .ss-nav-caption, li.pc-caption, .nav-caption -- exist nowhere in this application, so the
    // count printed 0 on a sidebar carrying five of them. Guessed selectors, never checked
    // against a rendered page.
    out.sidebarGroupHeadings = [...side.querySelectorAll('.ss-navgroup')].length;
  }

  // ---- the icon inventory: families, sizes, stroke widths
  const fams = {};
  const iconSizes = {};
  for (const el of document.querySelectorAll('i, svg, .ti, .fa, .feather, [class*="ti-"], [class*="fa-"]')) {
    const q = el.getBoundingClientRect();
    if (q.width < 4 || q.height < 4) { continue; }
    const cls = String(el.className && el.className.baseVal !== undefined ? el.className.baseVal : el.className || '');
    let fam = 'other';
    if (/\bti\b|\bti-/.test(cls)) { fam = 'tabler (ti)'; }
    else if (/\bfa\b|\bfa-|fas|far|fab/.test(cls)) { fam = 'fontawesome'; }
    else if (/feather/.test(cls)) { fam = 'feather'; }
    else if (/material/.test(cls)) { fam = 'material'; }
    else if (el.tagName.toLowerCase() === 'svg') { fam = 'inline svg'; }
    fams[fam] = (fams[fam] || 0) + 1;
    const cs = getComputedStyle(el);
    const key = el.tagName.toLowerCase() === 'svg'
      ? Math.round(q.width) + 'x' + Math.round(q.height)
      : Math.round(parseFloat(cs.fontSize)) + 'px font';
    iconSizes[key] = (iconSizes[key] || 0) + 1;
  }
  out.iconFamilies = fams;
  out.iconSizes = Object.entries(iconSizes).sort((a, b) => b[1] - a[1]).slice(0, 10).map(([k, n]) => `${k} x${n}`);

  // ---- breadcrumb depth (the guideline says do not use it on a flat hierarchy)
  const crumb = document.querySelector('.ss-breadcrumb, nav[aria-label*="readcrumb"], .breadcrumb');
  out.breadcrumbDepth = crumb ? crumb.querySelectorAll('li, a').length : 0;

  // ---- what is above the fold: the first element carrying a real figure or data row
  const vh = window.innerHeight;
  const candidates = [['.ss-stat', 'KPI cell'], ['.ss-card__body', 'card body'], ['tbody tr', 'table row'], ['.card-body', 'card body']];
  out.aboveFold = [];
  for (const [sel, what] of candidates) {
    const seen = [...(content || document).querySelectorAll(sel)].filter((e) => {
      const q = e.getBoundingClientRect();
      return q.height > 8 && q.top < vh;
    });
    if (seen.length) { out.aboveFold.push(`${what}: ${seen.length} within ${vh}px`); }
  }
  const firstStat = (content || document).querySelector('.ss-stat, .ss-card__body, .card-body');
  out.firstDataY = firstStat ? Math.round(firstStat.getBoundingClientRect().top + window.scrollY) : null;

  // ---- focus visibility count: operable controls that show NO focus ring
  out.pageHeight = document.documentElement.scrollHeight;
  return out;
};

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
  const p = await ctx.newPage();
  await p.goto('http://127.0.0.1:8899/login', { waitUntil: 'domcontentloaded' });
  await p.fill('input[name="email"]', 'merchant@example.test');
  await p.fill('input[name="password"]', 'password');
  await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"], input[type="submit"]')]);

  for (const [w, h, label] of [[1440, 900, 'desktop 1440'], [390, 844, 'phone 390']]) {
    await p.setViewportSize({ width: w, height: h });
    await p.goto('http://127.0.0.1:8899' + ROUTE, { waitUntil: 'load' });
    await p.waitForTimeout(2200);
    const r = await p.evaluate(PROBE);
    console.log(`\n################ ${ROUTE}  ${THEME}/${DIR}  ${label} ################`);
    console.log('  header            ' + JSON.stringify(r.header) + '  position=' + r.headerPosition);
    console.log('  content           ' + JSON.stringify(r.content));
    console.log('  NAV OVERLAP       ' + r.navOverlapPx + 'px   (guideline: fixed nav must not obscure content)');
    console.log('  sidebar           ' + JSON.stringify(r.sidebar) + '  links=' + r.sidebarLinkCount
      + '  item heights=' + JSON.stringify(r.sidebarItemHeights) + '  group headings=' + r.sidebarGroupHeadings);
    console.log('  active top-level  ' + JSON.stringify(r.sidebarActiveTop));
    console.log('  active sub-item   ' + JSON.stringify(r.sidebarActiveSub));
    console.log('  an inactive peer  ' + JSON.stringify(r.sidebarActiveSibling));
    console.log('  HEADING SEQUENCE  ' + JSON.stringify(r.headingSequence));
    console.log('  heading skips     ' + (r.headingSkips.length ? r.headingSkips.join(', ') : 'none'));
    r.headingTexts.forEach((t) => console.log('      ' + t));
    console.log('  DISTINCT SIZES    ' + r.distinctFontSizes + '   ' + r.fontSizes.join('  '));
    console.log('  icon families     ' + JSON.stringify(r.iconFamilies));
    console.log('  icon sizes        ' + r.iconSizes.join('  '));
    console.log('  breadcrumb depth  ' + r.breadcrumbDepth);
    console.log('  above the fold    ' + (r.aboveFold.join(' | ') || 'nothing'));
    console.log('  first data y      ' + r.firstDataY + 'px    page height ' + r.pageHeight + 'px');
  }
  await b.close();
})();
