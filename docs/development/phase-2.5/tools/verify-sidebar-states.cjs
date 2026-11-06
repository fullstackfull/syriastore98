/**
 * P2.5-PKG-A-02 — the sidebar's states, measured on the running application.
 *
 * EIGHT PASSES: {light, dark} x {ltr, rtl} x {desktop 1440, phone 390}. The phone passes open
 * the drawer the way a merchant does, by clicking `#mobile-collapse`, and wait 3s for the
 * `transition: all` to settle -- a reading taken mid-transition is not a reading, and a first
 * pass at 900ms reported a colour the page never rests at.
 *
 * EVERY CONTRAST IS AGAINST THE ELEMENT'S OWN BACKGROUND, found by walking up to the first
 * non-transparent ancestor, with alpha composited. Measuring the active item against the
 * SIDEBAR rather than against its own blue fill reported 1.00:1 for white-on-blue and sent this
 * slice chasing a defect that did not exist; the screenshot is what corrected it.
 *
 * Run it with the dev server on 127.0.0.1:8899 and NEVER with DB_* exported into the shell.
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const REPO = path.resolve(__dirname, '../../../..');   // .../docs/development/phase-2.5/tools -> repo root
const TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');
const DB = path.join(REPO, 'database/p2dev.sqlite');
const BASE = 'http://127.0.0.1:8899';

const AA_TEXT = 4.5;      // WCAG SC 1.4.3
const NON_TEXT = 3.0;     // WCAG SC 1.4.11
const MARKER_PX = 3;

function px(v) {
  const m = /rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?\)/.exec(String(v));
  return m ? [+m[1], +m[2], +m[3], m[4] === undefined ? 1 : +m[4]] : null;
}
function over(fg, bg) { const a = fg[3]; return [0, 1, 2].map((i) => fg[i] * a + bg[i] * (1 - a)); }
function lum(c) {
  const f = c.map((x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); });
  return 0.2126 * f[0] + 0.7152 * f[1] + 0.0722 * f[2];
}
function ratio(fgStr, bgStr) {
  const f = px(fgStr); const b = px(bgStr);
  if (!f || !b) { return null; }
  const c = f[3] < 1 ? over(f, b.slice(0, 3)) : f.slice(0, 3);
  const l1 = lum(c); const l2 = lum(b.slice(0, 3));
  return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
}

let checks = 0; let fails = 0;
function ck(name, ok, detail) {
  checks++;
  if (!ok) { fails++; console.log('  FAIL  ' + name.padEnd(42) + detail); }
  else { console.log('  ok    ' + name.padEnd(42) + detail); }
}

const COMBOS = [];
for (const [theme, dir, lang] of [['light', 'ltr', 'en'], ['light', 'rtl', 'ar'], ['dark', 'ltr', 'en'], ['dark', 'rtl', 'ar']]) {
  COMBOS.push({ theme, dir, lang, w: 1440, h: 1000, device: 'desktop' });
  COMBOS.push({ theme, dir, lang, w: 390, h: 844, device: 'phone' });
}

(async () => {
  const only = process.env.ONLY_COMBO || '';
  for (const c of COMBOS) {
    const tag = c.theme + '/' + c.dir + '/' + c.device;
    if (only && tag !== only) { continue; }

    execFileSync('php', [TOOL, '1', c.theme, c.dir, c.lang, 'merchant@example.test'], {
      cwd: path.dirname(TOOL),
      env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: DB, CACHE_STORE: 'file' },
      stdio: ['ignore', 'pipe', 'pipe'],
    });

    const browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: c.w, height: c.h } });
    const page = await ctx.newPage();
    const errs = [];
    page.on('console', (m) => { if (m.type() === 'error') { errs.push(m.text().slice(0, 90)); } });

    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', 'merchant@example.test');
    await page.fill('input[name="password"]', 'password');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"], input[type="submit"]')]);
    await page.goto(BASE + '/product', { waitUntil: 'load' });
    await page.waitForTimeout(1800);

    let drawerOpened = null;
    if (c.device === 'phone') {
      drawerOpened = await page.evaluate(() => {
        const t = document.querySelector('#mobile-collapse');
        if (!t) { return null; }
        t.click();
        return t.id;
      });
      await page.waitForTimeout(3000);
    }

    const o = await page.evaluate(() => {
      const sb = document.querySelector('.dash-sidebar');
      const ownBg = (el) => {
        let n = el;
        while (n && n !== document.documentElement) {
          const bg = getComputedStyle(n).backgroundColor;
          if (bg && !/rgba\(0, 0, 0, 0\)|transparent/.test(bg)) { return bg; }
          n = n.parentElement;
        }
        return 'rgb(255, 255, 255)';
      };
      // THE ELEMENT THAT RENDERS THE WORDS, not the anchor around them. A top-level item wraps
      // its label in `<span class="dash-mtext">` and a sub-item does not (menu-tree.blade.php),
      // and a stylesheet painted that span DIRECTLY -- so reading the anchor reported 16.88:1
      // while the merchant saw grey text at 1.61:1. The screenshot caught what this function
      // used to miss, which is why it now reads the span when there is one.
      const grab = (sel) => {
        const anchor = sb.querySelector(sel);
        if (!anchor) { return null; }
        const e = anchor.querySelector('.dash-mtext') || anchor;
        const cs = getComputedStyle(e);
        const acs = getComputedStyle(anchor);
        const rg = document.createRange(); rg.selectNodeContents(e);
        const tr = rg.getBoundingClientRect();
        const icon = anchor.querySelector('i.ti');
        return {
          color: cs.color, bg: ownBg(e), weight: cs.fontWeight,
          iconColor: icon ? getComputedStyle(icon).color : null,
          // the marker and padding live on the ANCHOR, whichever element carries the words
          borderStartW: parseFloat(acs.borderInlineStartWidth),
          borderStartColor: acs.borderInlineStartColor,
          padStart: acs.paddingInlineStart,
          textLeft: +tr.left.toFixed(1), textRight: +tr.right.toFixed(1),
          text: (e.textContent || '').trim().slice(0, 18),
        };
      };
      const arrows = [...sb.querySelectorAll('span.dash-arrow')];
      const glyphs = arrows.map((a) => {
        const i = a.querySelector('i.ti');
        if (!i) { return null; }
        const cs = getComputedStyle(i); const r = i.getBoundingClientRect();
        return { fs: cs.fontSize, w: +r.width.toFixed(1), h: +r.height.toFixed(1),
          cp: [...getComputedStyle(i, '::before').content].map((ch) => ch.codePointAt(0).toString(16)).join(' ') };
      }).filter(Boolean);
      const openArrow = sb.querySelector('li.dash-hasmenu.dash-trigger > a.dash-link > span.dash-arrow');
      const top = sb.querySelector('.dash-navbar > li.dash-item.active > a.dash-link');

      return {
        sbBg: getComputedStyle(sb).backgroundColor,
        activeTop: grab('.dash-navbar > li.dash-item.active > a.dash-link'),
        inactiveTop: grab('.dash-navbar > li.dash-item:not(.active) > a.dash-link'),
        activeSub: grab('.dash-submenu > li.dash-item.active > a.dash-link'),
        // THE SIBLING OF THE ACTIVE ITEM, not the first inactive sub-item in the sidebar.
        // A sidebar-wide `querySelector` returns whichever submenu comes first in the document
        // -- Dashboard's, not Catalogue's -- and comparing the active label's position against
        // an item in a different, collapsed menu reported a 1390px "shift" that is simply two
        // different places on the page.
        inactiveSub: (() => {
          const a = sb.querySelector('.dash-submenu > li.dash-item.active > a.dash-link');
          if (!a) { return null; }
          const peerAnchor = a.closest('ul.dash-submenu').querySelector(':scope > li.dash-item:not(.active) > a.dash-link');
          if (!peerAnchor) { return null; }
          const peer = peerAnchor.querySelector('.dash-mtext') || peerAnchor;
          const cs = getComputedStyle(peer);
          const rg = document.createRange(); rg.selectNodeContents(peer);
          const tr = rg.getBoundingClientRect();
          const pacs = getComputedStyle(peerAnchor);
          return { color: cs.color, bg: ownBg(peer), weight: cs.fontWeight,
            iconColor: peerAnchor.querySelector('i.ti') ? getComputedStyle(peerAnchor.querySelector('i.ti')).color : null,
            borderStartW: parseFloat(pacs.borderInlineStartWidth), borderStartColor: pacs.borderInlineStartColor,
            padStart: pacs.paddingInlineStart, textLeft: +tr.left.toFixed(1), textRight: +tr.right.toFixed(1),
            text: (peer.textContent || '').trim().slice(0, 18) };
        })(),
        caption: grab('h3.ss-navgroup'),
        search: (() => {
          const f = sb.querySelector('.sidebar-input .form-control');
          if (!f) { return null; }
          const cs = getComputedStyle(f); const ps = getComputedStyle(f, '::placeholder');
          return { color: cs.color, bg: ownBg(f), placeholder: ps.color, placeholderOpacity: ps.opacity };
        })(),
        captionCount: sb.querySelectorAll('h3.ss-navgroup').length,
        topShadow: top ? getComputedStyle(top).boxShadow : null,
        glyphs, arrowCount: arrows.length,
        featherSvg: sb.querySelectorAll('.dash-arrow svg.feather').length,
        openArrowTransform: openArrow ? getComputedStyle(openArrow).transform : null,
        deadModal: !!document.getElementById('notification-modal'),
        pcsettings: document.querySelectorAll('[id^="pcsetting"]').length,
      };
    });

    console.log('\n##### ' + tag + '   sidebar ' + o.sbBg + (drawerOpened ? '  (drawer opened via #' + drawerOpened + ')' : ''));

    // --- every piece of sidebar text clears AA against ITS OWN background
    for (const [label, el] of [['active top-level', o.activeTop], ['inactive top-level', o.inactiveTop],
      ['active sub-item', o.activeSub], ['inactive sub-item', o.inactiveSub], ['zone caption', o.caption]]) {
      if (!el) { ck(label + ' present', false, 'ABSENT'); continue; }
      const r = ratio(el.color, el.bg);
      ck(label + ' >= AA', r >= AA_TEXT, el.color + ' on ' + el.bg + ' = ' + r.toFixed(2) + ':1   "' + el.text + '"');
    }

    // --- the nav icons travel with their labels; a legible word beside an invisible icon is
    //     still a broken row, and the stylesheet that greyed the labels greyed these too.
    for (const [label, el] of [['top-level icon', o.inactiveTop], ['active top icon', o.activeTop]]) {
      if (!el || !el.iconColor) { continue; }
      const r = ratio(el.iconColor, el.bg);
      ck(label + ' >= 3:1', r >= NON_TEXT, el.iconColor + ' on ' + el.bg + ' = ' + r.toFixed(2) + ':1');
    }

    // --- the sidebar's search field, which the same block painted for a dark drawer
    if (o.search) {
      const rt = ratio(o.search.color, o.search.bg);
      ck('search field text >= AA', rt >= AA_TEXT, o.search.color + ' on ' + o.search.bg + ' = ' + rt.toFixed(2) + ':1');
      const rp = ratio(o.search.placeholder, o.search.bg);
      ck('search placeholder >= AA', rp >= AA_TEXT, o.search.placeholder + ' = ' + rp.toFixed(2) + ':1 (opacity ' + o.search.placeholderOpacity + ')');
    } else {
      ck('search field present', false, 'ABSENT');
    }

    // --- the active state reads: a shape, a weight step, and a colour that is not the sibling's
    ck('marker bar is ' + MARKER_PX + 'px', o.activeSub.borderStartW === MARKER_PX,
      o.activeSub.borderStartW + 'px ' + o.activeSub.borderStartColor);
    ck('marker >= 3:1 on the sidebar', ratio(o.activeSub.borderStartColor, o.sbBg) >= NON_TEXT,
      ratio(o.activeSub.borderStartColor, o.sbBg).toFixed(2) + ':1');
    ck('weight steps up when active', +o.activeSub.weight > +o.inactiveSub.weight,
      o.inactiveSub.weight + ' -> ' + o.activeSub.weight);
    ck('active colour differs from inactive', o.activeSub.color !== o.inactiveSub.color,
      o.activeSub.color + ' vs ' + o.inactiveSub.color);

    // --- the marker is carved out of the padding, so the label does not move between states
    const shift = c.dir === 'rtl'
      ? Math.abs(o.inactiveSub.textRight - o.activeSub.textRight)
      : Math.abs(o.activeSub.textLeft - o.inactiveSub.textLeft);
    ck('label does not move', shift <= 1.0, shift.toFixed(1) + 'px  (pad ' + o.activeSub.padStart + ' vs ' + o.inactiveSub.padStart + ')');

    // --- the caption labels the items; it must not out-shout them
    ck('caption does not out-rank its items',
      ratio(o.caption.color, o.caption.bg) <= ratio(o.inactiveSub.color, o.inactiveSub.bg) + 0.01,
      ratio(o.caption.color, o.caption.bg).toFixed(2) + ':1 vs items ' + ratio(o.inactiveSub.color, o.inactiveSub.bg).toFixed(2) + ':1');
    ck('all five zone captions render', o.captionCount === 5, o.captionCount + ' captions');

    // --- the WorkDo demo theme's magenta glow under the active nav item. The test is SATURATION:
    //     the design system's ink shadow is rgba(11,31,28,.06), a 20-point channel spread that
    //     composites grey; the demo glow is rgba(146,44,136,.3), a 102-point spread.
    const shadowCols = [...String(o.topShadow).matchAll(/rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)/g)].map((m) => [+m[1], +m[2], +m[3]]);
    const spreads = shadowCols.map((x) => Math.max(...x) - Math.min(...x));
    ck('nav shadow is ink, not demo magenta',
      shadowCols.length > 0 && !/146,\s*44,\s*136/.test(String(o.topShadow)) && spreads.every((v) => v <= 25),
      'channel spreads [' + spreads.join(', ') + ']');

    // --- iconography
    ck('no feather svg left in the arrows', o.featherSvg === 0, 'feather svg count ' + o.featherSvg);
    ck('every arrow carries a tabler glyph', o.glyphs.length === o.arrowCount && o.arrowCount > 0,
      o.glyphs.length + '/' + o.arrowCount);
    ck('glyph resolves to a real codepoint', o.glyphs.every((g) => /ea61/.test(g.cp)),
      (o.glyphs[0] || {}).cp);
    // ONLY THE GLYPHS THAT HAVE A BOX. One of the six sits inside a COLLAPSED `ul.dash-submenu`
    // whose `display` is none, so it measures 0x0 -- correct, not broken, and measured rather
    // than assumed. And the arrow on the OPEN menu is rotated 90deg, which swaps its width and
    // height, so the bounds are checked on both axes instead of on height alone.
    const visibleGlyphs = o.glyphs.filter((g) => g.w > 0 && g.h > 0);
    const badGlyphs = visibleGlyphs.filter((g) => !(g.fs === '16px'
      && g.w >= 15.4 && g.w <= 16.8 && g.h >= 15.4 && g.h <= 16.8));
    ck('glyph box matches the svg it replaced', badGlyphs.length === 0 && visibleGlyphs.length >= 4,
      visibleGlyphs.length + ' visible of ' + o.glyphs.length
      + (badGlyphs.length ? ', off: ' + JSON.stringify(badGlyphs.slice(0, 3)) : ', all 16x16'));
    ck('an open menu still rotates its arrow', o.openArrowTransform && o.openArrowTransform !== 'none',
      String(o.openArrowTransform).slice(0, 34));

    // --- the dead settings modal
    ck('dead settings modal is gone', o.deadModal === false && o.pcsettings === 0,
      '#notification-modal=' + o.deadModal + '  pcsetting inputs=' + o.pcsettings);
    ck('no console errors', errs.length === 0, errs.join(' | ') || 'none');

    await browser.close();
  }

  console.log('\n================ ' + checks + ' checks, ' + fails + ' failed ================');
  process.exit(fails ? 1 : 0);
})();
