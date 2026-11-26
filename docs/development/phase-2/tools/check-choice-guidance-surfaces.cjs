/**
 * GAP-060 / P02-I4 -- stage 2 of 2: measure the four configurations in a real browser.
 *
 * USAGE   php docs/development/phase-2/tools/render-choice-guidance-surfaces.php [outDir]
 *         node docs/development/phase-2/tools/check-choice-guidance-surfaces.cjs [outDir]
 *
 * PRECONDITIONS: playwright and a Chromium. `CHROME_PATH` overrides the executable; without it
 * Playwright's own browser is used. Exits non-zero on any failure, and NEITHER THE 4.5:1 FLOOR
 * NOR THE SURFACE LIST MAY BE RELAXED TO MAKE A RUN PASS.
 *
 * WHAT IS MEASURED OFF RENDERED PIXELS RATHER THAN OFF SOURCE:
 *   - the WCAG contrast of every instruction and every group legend this row adds, against the
 *     COMPOSITED background (an ancestor chain is walked until an opaque colour is found, because
 *     a `.modal-body` and a `.card-body` are both transparent and the colour that actually paints
 *     comes from further up);
 *   - the same for the OPTION LABELS inside each group, which is where this project previously
 *     measured 1.00:1 white-on-white in dark mode;
 *   - the direction each element really resolves to, and whether the instruction's INLINE-START
 *     edge lines up with its control's. That is the RTL check that cannot be faked: a sentence
 *     that stays left-aligned under `dir=rtl` has its left edges matching, not its right ones.
 *   - `aria-describedby` resolving to an element with text, in the DOM the browser built.
 */
const { chromium } = require('playwright');
const os = require('os');
const path = require('path');

/* The pages stage 1 wrote. Same default, so the pair works with no arguments. */
const DIR = process.argv[2] || path.join(os.tmpdir(), 'gap060-choice-guidance');
const COMBOS = ['en-light', 'en-dark', 'ar-light', 'ar-dark'];
const FLOOR = 4.5;

const probe = () => {
  const lum = (c) => {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
  };
  const parse = (s) => {
    const m = /rgba?\(([^)]+)\)/.exec(s || '');
    if (!m) return null;
    const p = m[1].split(/[,\s/]+/).filter(Boolean).map(Number);
    return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1];
  };
  const over = (fg, bg) => [0, 1, 2].map((i) => fg[3] * fg[i] + (1 - fg[3]) * bg[i]);
  const backdrop = (el) => {
    let acc = null;
    let node = el;
    const stack = [];
    while (node) { stack.push(node); node = node.parentElement; }
    for (const n of stack) {
      const c = parse(getComputedStyle(n).backgroundColor);
      if (c && c[3] > 0) { stack.length = stack.indexOf(n) + 1; break; }
    }
    // Walk outward until an opaque layer; composite inward.
    for (let i = stack.length - 1; i >= 0; i--) {
      const c = parse(getComputedStyle(stack[i]).backgroundColor);
      if (!c || c[3] === 0) continue;
      acc = acc === null ? (c[3] === 1 ? [c[0], c[1], c[2]] : over(c, [255, 255, 255])) : over(c, acc);
    }
    return acc || [255, 255, 255];
  };
  const ratio = (a, b) => {
    const l1 = lum(a), l2 = lum(b);
    return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
  };

  const rows = [];
  const push = (kind, el) => {
    const cs = getComputedStyle(el);
    const box = el.getBoundingClientRect();
    if (box.width === 0 || box.height === 0) return;
    const fg = parse(cs.color);
    const bg = backdrop(el);
    rows.push({
      kind,
      case: (el.closest('[data-case]') || {}).dataset?.case || '?',
      id: el.id || '',
      text: (el.textContent || '').trim().slice(0, 48),
      color: cs.color,
      bg: 'rgb(' + bg.map((v) => Math.round(v)).join(', ') + ')',
      ratio: Math.round(ratio([fg[0], fg[1], fg[2]], bg) * 100) / 100,
      fontPx: Math.round(parseFloat(cs.fontSize) * 10) / 10,
      weight: cs.fontWeight,
      direction: cs.direction,
      textAlign: cs.textAlign,
      left: Math.round(box.left), right: Math.round(box.right),
    });
  };

  document.querySelectorAll('.ss-field__hint').forEach((el) => push('hint', el));
  document.querySelectorAll('.ss-choicegroup__legend').forEach((el) => push('legend', el));
  document.querySelectorAll('.ss-choicegroup .form-check-label').forEach((el) => push('option', el));

  // aria-describedby resolves, in the DOM the browser built.
  const dangling = [];
  document.querySelectorAll('[aria-describedby]').forEach((el) => {
    el.getAttribute('aria-describedby').split(/\s+/).filter(Boolean).forEach((id) => {
      const target = document.getElementById(id);
      if (!target || !(target.textContent || '').trim()) dangling.push({ id, on: el.tagName.toLowerCase() });
    });
  });

  // Every hint is referenced by something.
  const orphans = [];
  document.querySelectorAll('.ss-field__hint[id]').forEach((el) => {
    if (!document.querySelector('[aria-describedby~="' + el.id + '"]')) orphans.push(el.id);
  });

  /*
   * RTL, MEASURED ON THE INSTRUCTION'S OWN CONTAINING BLOCK AND NOT ON ITS CONTROL.
   *
   * The first version compared the instruction's inline-start edge with the edge of the control
   * that describes it, and the refund screen failed it by 173px -- correctly. That checkbox is an
   * INLINE control sitting after its caption, so its own left edge is 173px into the column while
   * the instruction, a block `<p>`, starts at the column's content edge. Nothing is wrong with
   * that layout; the assertion was asking the wrong question of it.
   *
   * The right question is whether the sentence starts where its container's text starts, which is
   * what flips under `dir=rtl` and is what a stuck-LTR paragraph gets wrong. The control's own
   * direction is still asserted separately.
   */
  const align = [];
  document.querySelectorAll('.ss-field__hint[id]').forEach((el) => {
    const owner = document.querySelector('[aria-describedby~="' + el.id + '"]');
    const box = el.getBoundingClientRect();
    const host = el.parentElement.getBoundingClientRect();
    const ps = getComputedStyle(el.parentElement);
    if (box.width === 0) return;
    align.push({
      id: el.id,
      rtl: getComputedStyle(el).direction === 'rtl',
      ownerRtl: owner ? getComputedStyle(owner).direction === 'rtl' : null,
      dLeft: Math.round(box.left - (host.left + parseFloat(ps.paddingLeft) + parseFloat(ps.borderLeftWidth))),
      dRight: Math.round((host.right - parseFloat(ps.paddingRight) - parseFloat(ps.borderRightWidth)) - box.right),
    });
  });

  return {
    rows, dangling, orphans, align,
    overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    bodyBg: getComputedStyle(document.body).backgroundColor,
    htmlDir: document.documentElement.getAttribute('dir'),
    theme: document.documentElement.getAttribute('data-ss-theme'),
    sheet: (document.getElementById('main-style-link') || {}).getAttribute?.('href') || '',
  };
};

(async () => {
  const browser = await chromium.launch(Object.assign(
    { args: ['--no-sandbox', '--allow-file-access-from-files'] },
    process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}
  ));

  let failures = 0, measured = 0;

  for (const width of [1280, 375]) {
    for (const combo of COMBOS) {
      const page = await browser.newPage({ viewport: { width, height: 1000 } });
      await page.goto('file://' + DIR + '/page-' + combo + '.html', { waitUntil: 'load' });
      await page.waitForTimeout(150);

      const r = await page.evaluate(probe);

      const wantDark = combo.endsWith('dark');
      const wantRtl = combo.startsWith('ar');

      /* PREMISE, per page, refused rather than skipped: the admin's dark mode is a stylesheet
         swap, so a run that did not really load the dark sheet produces a light page that reads
         as a pass. */
      const premise = [];
      if (wantDark !== (r.theme === 'dark')) premise.push('data-ss-theme=' + r.theme);
      if (wantRtl !== (r.htmlDir === 'rtl')) premise.push('dir=' + r.htmlDir);
      if (wantDark !== /dark/.test(r.sheet)) premise.push('sheet=' + r.sheet);
      if (wantRtl !== /rtl/.test(r.sheet)) premise.push('sheet-dir=' + r.sheet);

      console.log('\n=== ' + combo + ' @' + width + 'px ===  body ' + r.bodyBg
        + '  sheet ' + r.sheet.split('/').pop() + '  elements ' + r.rows.length);

      if (premise.length) { console.log('  PREMISE FAILED: ' + premise.join(', ')); failures++; continue; }

      for (const row of r.rows) {
        measured++;
        const large = row.fontPx >= 24 || (row.fontPx >= 18.66 && Number(row.weight) >= 700);
        const floor = large ? 3.0 : FLOOR;
        const ok = row.ratio >= floor;
        if (!ok) failures++;
        if (!ok || width === 1280) {
          console.log('  ' + (ok ? 'ok  ' : 'FAIL') + ' ' + row.ratio.toFixed(2) + ':1  '
            + row.kind.padEnd(6) + ' ' + (row.case + '').padEnd(30)
            + ' ' + row.color + ' on ' + row.bg + '  ' + row.fontPx + 'px/' + row.weight
            + '  ' + row.direction + '/' + row.textAlign + '  "' + row.text + '"');
        }
      }

      if (r.dangling.length) { console.log('  FAIL dangling aria-describedby: ' + JSON.stringify(r.dangling)); failures++; }
      if (r.orphans.length) { console.log('  FAIL orphan instruction: ' + r.orphans.join(', ')); failures++; }
      if (r.overflow > 0) { console.log('  FAIL horizontal overflow: ' + r.overflow + 'px'); failures++; }

      let misaligned = 0;
      for (const a of r.align) {
        if (a.rtl !== wantRtl) { console.log('  FAIL direction on ' + a.id + ': rtl=' + a.rtl); failures++; continue; }
        if (a.ownerRtl !== null && a.ownerRtl !== wantRtl) {
          console.log('  FAIL the control described by ' + a.id + ' resolves direction rtl=' + a.ownerRtl);
          failures++; continue;
        }
        const d = wantRtl ? a.dRight : a.dLeft;
        if (Math.abs(d) > 2) {
          console.log('  FAIL inline-start edge of ' + a.id + ' is ' + d + 'px off its container (dLeft '
            + a.dLeft + ', dRight ' + a.dRight + ')');
          failures++; misaligned++;
        }
      }
      console.log('  aria-describedby resolved: ' + (r.align.length) + '  mis-aligned: ' + misaligned
        + '  overflow: ' + r.overflow + 'px');

      await page.close();
    }
  }

  await browser.close();
  console.log('\n' + (failures === 0 ? 'PASS' : 'FAIL') + ': ' + measured + ' contrast checks across 4 configurations x 2 widths, ' + failures + ' failed');
  process.exit(failures === 0 ? 0 : 1);
})();
