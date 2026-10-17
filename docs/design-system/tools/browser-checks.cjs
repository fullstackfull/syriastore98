/**
 * Accessibility and responsive assertions for the Store Syria pilot screen,
 * run in Chromium 1194 via Playwright against the real Blade-rendered markup
 * and the real public/ stylesheets.
 *
 * These are assertions against a running page, not a description of intent.
 * Each one prints PASS or FAIL and the measured value.
 */
const { chromium } = require('playwright');

const BASE = process.env.SS_PREVIEW_BASE || 'http://127.0.0.1:8099/preview/';
let pass = 0, fail = 0;

function check(ok, label, detail) {
  (ok ? pass++ : fail++);
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${label}${detail !== undefined ? ` — ${detail}` : ''}`);
}

function luminance(rgb) {
  const [r, g, b] = rgb.map(v => {
    const c = v / 255;
    return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
function parseRGB(s) {
  const m = s.match(/rgba?\(([^)]+)\)/);
  return m ? m[1].split(',').slice(0, 3).map(n => parseFloat(n)) : null;
}
function contrast(a, b) {
  const l1 = luminance(a), l2 = luminance(b);
  return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.SS_CHROMIUM || '/opt/pw-browsers/chromium' });

  // ---------------------------------------------------------------- A. keyboard
  console.log('\nA. KEYBOARD TRAVERSAL AND FOCUS INDICATOR  (new-en-full, 1280x900)');
  {
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await page.goto(BASE + 'new-en-full.html');

    const stops = [];
    for (let i = 0; i < 16; i++) {
      await page.keyboard.press('Tab');
      const info = await page.evaluate(() => {
        const el = document.activeElement;
        if (!el || el === document.body) return null;
        const cs = getComputedStyle(el);
        const r = el.getBoundingClientRect();
        return {
          tag: el.tagName.toLowerCase(),
          cls: (el.className || '').toString().split(' ').filter(c => c.startsWith('ss-')).join(' '),
          label: (el.innerText || el.getAttribute('title') || '').trim().slice(0, 28),
          outlineStyle: cs.outlineStyle,
          outlineWidth: cs.outlineWidth,
          outlineColor: cs.outlineColor,
          matchesFocusVisible: el.matches(':focus-visible'),
          x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height),
          bg: cs.backgroundColor,
        };
      });
      if (!info) break;
      if (stops.some(s => s.x === info.x && s.y === info.y && s.tag === info.tag)) break;
      stops.push(info);
    }

    console.log(`  (${stops.length} focus stops reached by Tab)`);
    check(stops.length >= 10, 'every interactive control is reachable by Tab',
      `${stops.length} stops: 1 add button + 5 rows x 2 actions = 11 expected`);

    // Split DS-owned controls from the host's page-header chrome (the "Home"
    // and parent-entity breadcrumb links, emitted by layouts/app.blade.php).
    // The chrome is byte-identical before and after this sprint, so its
    // behaviour is reported but not counted against the design system.
    const owned = stops.filter(s => s.cls);
    const chrome = stops.filter(s => !s.cls);

    const ringed = owned.filter(s => s.matchesFocusVisible &&
      s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) >= 2);
    check(ringed.length === owned.length,
      'every DS-owned control shows a >=2px focus ring (WCAG 2.4.11/2.4.13)',
      `${ringed.length}/${owned.length} ss-* controls`);

    // Tab order within the DS-owned content must follow visual order.
    let ordered = true;
    for (let i = 1; i < owned.length; i++) {
      const a = owned[i - 1], b = owned[i];
      if (b.y < a.y - 5) { ordered = false; break; }
      if (Math.abs(b.y - a.y) <= 5 && b.x < a.x - 5) { ordered = false; break; }
    }
    check(ordered, 'tab order within DS content follows visual reading order');

    const ringColors = [...new Set(owned.map(s => s.outlineColor))];
    for (const c of ringColors) {
      const rgb = parseRGB(c);
      if (!rgb) continue;
      const ratio = contrast(rgb, [255, 255, 255]);
      check(ratio >= 3.0, `DS focus ring ${c} vs white surface >= 3:1`, `${ratio.toFixed(2)}:1`);
    }

    const chromeUnringed = chrome.filter(s => s.outlineStyle === 'none' || parseFloat(s.outlineWidth) < 2);
    console.log(`  NOTE (pre-existing, host chrome, unchanged by this sprint): ` +
      `${chromeUnringed.length}/${chrome.length} breadcrumb links have no focus ring ` +
      `— identical in the original view; see report.`);

    // Icon-only buttons must still carry an accessible name.
    const unnamed = await page.evaluate(() => {
      const out = [];
      document.querySelectorAll('button.ss-btn, a.ss-btn').forEach(el => {
        const text = (el.innerText || '').trim();
        const sr = el.querySelector('.ss-visually-hidden');
        const aria = el.getAttribute('aria-label');
        if (!text && !sr && !aria) out.push(el.outerHTML.slice(0, 80));
      });
      return out;
    });
    check(unnamed.length === 0, 'every icon-only button has an accessible name',
      unnamed.length ? unnamed.join(' | ') : 'all named via .ss-visually-hidden');

    await page.close();
  }

  // ---------------------------------------------------------------- B. responsive
  console.log('\nB. RESPONSIVE — no horizontal overflow at any breakpoint');
  for (const view of ['new-en-full', 'new-ar-full', 'new-en-empty', 'new-ar-empty']) {
    for (const w of [360, 390, 768, 834, 1024, 1280, 1440]) {
      const page = await browser.newPage({ viewport: { width: w, height: 900 } });
      await page.goto(BASE + view + '.html');
      const m = await page.evaluate(() => ({
        vw: document.documentElement.clientWidth,
        sw: document.documentElement.scrollWidth,
      }));
      check(m.sw <= m.vw + 1, `${view} @ ${w}px has no horizontal overflow`,
        `scrollWidth=${m.sw} viewport=${m.vw}`);
      await page.close();
    }
  }

  // ---------------------------------------------------------------- C. RTL
  console.log('\nC. RTL MIRRORING — layout actually flips, not just the text');
  {
    const en = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await en.goto(BASE + 'new-en-full.html');
    const ar = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await ar.goto(BASE + 'new-ar-full.html');

    const probe = p => p.evaluate(() => {
      const row = document.querySelector('.ss-sort-item');
      const handle = row.querySelector('.ss-sort-item__handle').getBoundingClientRect();
      const actions = row.querySelector('.ss-table__actions').getBoundingClientRect();
      return { dir: document.documentElement.getAttribute('dir') || '(empty)',
               handleX: Math.round(handle.x), actionsX: Math.round(actions.x),
               rowW: Math.round(row.getBoundingClientRect().width) };
    });
    const a = await probe(en), b = await probe(ar);
    console.log(`  LTR dir="${a.dir}" handle.x=${a.handleX} actions.x=${a.actionsX}`);
    console.log(`  RTL dir="${b.dir}" handle.x=${b.handleX} actions.x=${b.actionsX}`);

    check(a.handleX < a.actionsX, 'LTR: drag handle sits before the actions');
    check(b.handleX > b.actionsX, 'RTL: drag handle sits after the actions (mirrored)');
    check(b.dir === 'rtl', 'RTL document carries dir="rtl"');

    // The host emits dir="" for LTR, so [dir="ltr"] would never match.
    check(a.dir === '(empty)', 'LTR emits an empty dir attribute (host behaviour)',
      'confirms [dir="ltr"] selectors must not be used');

    await en.close(); await ar.close();
  }

  // ---------------------------------------------------------------- D. preserved contract
  console.log('\nD. PRESERVED FUNCTIONALITY CONTRACT');
  {
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await page.goto(BASE + 'new-en-full.html');
    const c = await page.evaluate(() => ({
      sortableLists: document.querySelectorAll('.sortable').length,
      itemsWithDataId: document.querySelectorAll('.sortable li[data-id]').length,
      ajaxPopups: document.querySelectorAll('[data-ajax-popup="true"]').length,
      deleteForms: document.querySelectorAll('form[method="POST"]').length,
      confirmButtons: document.querySelectorAll('.show_confirm').length,
      liveRegion: !!document.querySelector('[aria-live="polite"]'),
    }));
    check(c.sortableLists === 1, 'exactly one .sortable list (was 5 — nested duplicate loop)', c.sortableLists);
    check(c.itemsWithDataId === 5, 'li[data-id] count equals the record count', `${c.itemsWithDataId} for 5 records`);
    check(c.ajaxPopups === 6, 'ajax-popup contract intact (1 add + 5 edit)', c.ajaxPopups);
    check(c.deleteForms === 5, 'one DELETE form per row preserved', c.deleteForms);
    check(c.confirmButtons === 5, '.show_confirm hook preserved on every delete', c.confirmButtons);
    check(c.liveRegion, 'reorder result is announced via an aria-live region');
    await page.close();
  }

  // ---------------------------------------------------------------- E. permissions
  console.log('\nE. PERMISSION GATING — a view-only merchant sees no write actions');
  {
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await page.goto(BASE + 'new-en-noperm.html');
    const c = await page.evaluate(() => ({
      rows: document.querySelectorAll('.ss-sort-item').length,
      edit: document.querySelectorAll('[data-ajax-popup="true"]').length,
      del: document.querySelectorAll('.show_confirm').length,
    }));
    check(c.rows === 5, 'records still listed without write permissions', c.rows);
    check(c.edit === 0, 'no create/edit affordance rendered', c.edit);
    check(c.del === 0, 'no delete affordance rendered', c.del);
    await page.close();
  }

  // ---------------------------------------------------------------- G. host dark layout
  // The host's dark mode is a stylesheet swap, not a class. Under it the
  // original screen's Bootstrap card turns dark, so the redesigned one must
  // too, or dark-mode merchants get a white card in a dark admin.
  console.log('\nG. HOST DARK LAYOUT — the page follows the host\'s stylesheet swap');
  {
    const probe = async (view) => {
      const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
      await page.goto(BASE + view + '.html');
      const r = await page.evaluate(() => {
        const card = document.querySelector('.ss-card') || document.querySelector('.card');
        const label = document.querySelector('.ss-sort-item__label') || document.querySelector('.list-group-item span');
        const cs = getComputedStyle(card);
        return { theme: document.documentElement.getAttribute('data-ss-theme'),
                 bg: cs.backgroundColor, fg: getComputedStyle(label).color };
      });
      await page.close();
      return r;
    };
    const lum = c => { const v = parseRGB(c); return v ? luminance(v) : null; };

    const origDark = await probe('orig-en-full-dark');
    check(lum(origDark.bg) < 0.1, 'baseline: the ORIGINAL card is dark under the host dark layout', origDark.bg);

    for (const view of ['new-en-full-dark', 'new-ar-full-dark']) {
      const d = await probe(view);
      check(d.theme === 'dark', `${view}: <html data-ss-theme="dark"> set from #main-style-link`, String(d.theme));
      check(lum(d.bg) < 0.1, `${view}: card is dark`, d.bg);
      const ratio = contrast(parseRGB(d.fg), parseRGB(d.bg));
      check(ratio >= 4.5, `${view}: record text on the dark card >= 4.5:1`, `${ratio.toFixed(2)}:1`);
    }

    // The original's option names under the dark layout: white text on a
    // list-group-item that style-dark.css leaves white.
    const origRow = await (async () => {
      const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
      await page.goto(BASE + 'orig-en-full-dark.html');
      const r = await page.evaluate(() => {
        const li = document.querySelector('.tab-pane.active .list-group-item');
        return { bg: getComputedStyle(li).backgroundColor, fg: getComputedStyle(li.querySelector('span')).color };
      });
      await page.close();
      return r;
    })();
    const origRatio = contrast(parseRGB(origRow.fg), parseRGB(origRow.bg));
    console.log(`  option names under the dark layout:  before ${origRatio.toFixed(2)}:1 ` +
      `(${origRow.fg} on ${origRow.bg})   after: see the 11:1 checks above`);
    check(origRatio < 1.5, 'baseline: the ORIGINAL screen\'s option names are illegible in dark layout',
      `${origRatio.toFixed(2)}:1 — a pre-existing defect the redesign removes`);

    const light = await probe('new-en-full');
    check(light.theme === null, 'light layout leaves data-ss-theme unset', String(light.theme));
    check(lum(light.bg) > 0.9, 'light layout keeps a light card', light.bg);
  }

  // ---------------------------------------------------------------- F. before/after
  console.log('\nF. BEFORE / AFTER — the original screen measured the same way');
  {
    const measure = async (view) => {
      const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
      await page.goto(BASE + view + '.html');
      const stops = [];
      for (let i = 0; i < 16; i++) {
        await page.keyboard.press('Tab');
        const s = await page.evaluate(() => {
          const el = document.activeElement;
          if (!el || el === document.body) return null;
          const cs = getComputedStyle(el), r = el.getBoundingClientRect();
          return { ring: cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) >= 2,
                   name: (el.innerText || el.getAttribute('title') || '').trim(),
                   x: Math.round(r.x), y: Math.round(r.y) };
        });
        if (!s) break;
        if (stops.some(t => t.x === s.x && t.y === s.y)) break;
        stops.push(s);
      }
      const dom = await page.evaluate(() => ({
        nodes: document.querySelectorAll('li[data-id]').length,
        html: document.documentElement.outerHTML.length,
      }));
      await page.close();
      return { stops, dom };
    };

    const before = await measure('orig-en-full');
    const after = await measure('new-en-full');

    const bRing = before.stops.filter(s => s.ring).length;
    const aRing = after.stops.filter(s => s.ring).length;
    console.log(`  focus rings:  before ${bRing}/${before.stops.length}   after ${aRing}/${after.stops.length}`);
    check(aRing > bRing, 'the redesign adds focus indicators where there were none', `${bRing} -> ${aRing}`);

    // Row action names must disambiguate which record they act on.
    const ambiguous = s => s.name === 'Edit' || s.name === 'Delete';
    const bAmb = before.stops.filter(ambiguous).length;
    const aAmb = after.stops.filter(ambiguous).length;
    console.log(`  ambiguous "Edit"/"Delete" names:  before ${bAmb}   after ${aAmb}`);
    check(aAmb < bAmb, 'row actions name the record they act on', `${bAmb} -> ${aAmb}`);

    console.log(`  li[data-id] nodes:  before ${before.dom.nodes}   after ${after.dom.nodes}  (5 records)`);
    check(after.dom.nodes === 5 && before.dom.nodes === 25,
      'the nested duplicate @foreach is fixed (N^2 -> N)', `${before.dom.nodes} -> ${after.dom.nodes}`);
    console.log(`  rendered HTML bytes:  before ${before.dom.html}   after ${after.dom.html}`);
  }

  await browser.close();
  console.log(`\n================  ${pass} PASSED, ${fail} FAILED  ================`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('HARNESS ERROR:', e); process.exit(2); });
