#!/usr/bin/env node
'use strict';
/**
 * CAN A KEYBOARD USER SEE WHERE THEY ARE? Answered by comparing PIXELS, not computed styles.
 *
 * WHY THIS REPLACES census-focus-visibility.cjs. That tool read computed styles and was wrong five
 * times, and its own docblock records why: faults 2, 4 and 5 were all one fault wearing different
 * clothes -- every computed-style read races a CSS transition. On input#sideBarsearch the same
 * element reported `box-shadow: rgba(0,0,0,0) 0px 0px 0px 0px` at t=0 and
 * `rgb(51,93,168) 0px 0px 0px 3.2px` at t=260ms. Same focus, opposite verdict. It also missed
 * indicators that are not rings at all: a button whose whole fill inverts is unmistakably focused
 * and has no outline to find.
 *
 * A pixel diff has neither problem. It sees whatever the browser paints, by whatever mechanism --
 * outline, shadow, border, fill, a pseudo-element, an ancestor's `:focus-within`, an SVG swap --
 * and it is taken after the transition has finished.
 *
 * AND IT WOULD HAVE CAUGHT A REAL REGRESSION. P2.5-WAVE-31 added a `.btn-light` rule at (0,3,0)
 * that cancelled the host's `.btn:focus-visible` at (0,2,0). Those links' only focus indicator is
 * a whole-button fill-and-ink inversion, because that same host rule sets `outline: 0`; after the
 * wave, focusing one changed nothing but a 1px border. CI was green, the style-reading census
 * reported nothing, and it took a 27-agent adversarial review to find it. This tool is the cheap
 * way to find the next one.
 *
 * METHOD, per tab stop:
 *   1. Tab to it from the document, so `:focus-visible` is genuinely active.
 *   2. Wait for the transition to finish, then screenshot the control's box plus a margin.
 *   3. Blur it, wait again, screenshot the identical region.
 *   4. Decode both in the browser on a canvas and compare every pixel.
 *
 * THE CRITERION IS AREA, NOT "DID ANYTHING CHANGE", and that distinction was itself established
 * by a counterfactual rather than assumed. The first version flagged a stop when fewer than a set
 * PERCENTAGE of clip pixels moved -- and run against the `.btn-light` regression this tool exists
 * to catch, it printed output identical to the fixed state. The regression is not "nothing
 * changes": the 1px border still re-tints, which is plenty of changed pixels. What collapsed was
 * the SIZE of the indicator, from a whole-button fill inversion to a hairline.
 *
 * So the test is WCAG 2.2 SC 2.4.11's own: the focus indicator must be at least as large as a
 * 2 CSS px perimeter of the control. A 1px border change is exactly half of that and fails; a
 * fill inversion is many times it and passes. That is a published threshold rather than a number
 * fitted to the one case in front of me.
 *
 * NO IMAGE LIBRARY IS REQUIRED. pngjs, pixelmatch, sharp and jimp are all absent here; the
 * comparison runs inside a blank page that draws both PNGs to a canvas and reads them back.
 *
 * STATUS: ONE VERIFIED TRUE POSITIVE, ONE VERIFIED FALSE NEGATIVE. ITS FAILURE LIST IS NOT YET
 * A SET OF FINDINGS AND MUST NOT BE QUOTED AS ONE.
 *
 *   TRUE POSITIVE, and the reason this tool exists. Run against the P2.5-WAVE-31 `.btn-light`
 *   regression and against the P2.5-WAVE-32 fix, over 39 tab stops on /finance/withdrawals:
 *
 *       regressed   a.btn.btn-sm.btn-light 137x31 -- 348px changed, needs 672px   FLAGGED
 *       fixed       (absent from the list)                                        PASSES
 *
 *   348px is almost exactly a ONE-pixel perimeter of that button: the indicator had collapsed to
 *   half the size SC 2.4.11 asks for. A 27-agent adversarial review found that regression; this
 *   run finds it in a minute. That is the whole argument for the tool.
 *
 *   FALSE NEGATIVE, verified by hand. On /dashboard it reports `summary 1103x36 -- 0px changed`.
 *   A focused/unfocused screenshot pair of that same control (evidence/focus-2026-11-06/
 *   summary-{focused,UNfocused}.png) shows an unmistakable 2px teal ring appearing on focus, and
 *   driving the same tab walk confirms the states really do differ: `outline: solid 2px
 *   rgb(104,203,225)` focused, `none` after blur. So the page is right and the COMPARISON is
 *   wrong, and I have not isolated why. Until that is explained, a zero in this tool's output
 *   means "look at it", not "it fails".
 *
 * WHAT THAT MEANS IN PRACTICE. Use it as a DIFFERENTIAL -- run it before and after a change and
 * compare the two lists, which is how the true positive above was established and what catches
 * the regression class it was built for. Do not publish its absolute counts.
 *
 * usage: node docs/development/phase-2.5/tools/census-focus-pixels.cjs
 *          [--routes a,b] [--theme dark] [--dir rtl] [--stops 30] [--perimeter-px 2]
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
const STOPS = Number(arg('stops', 30));
/* SC 2.4.11 wants an indicator at least as large as a 2 CSS px perimeter of the control. The
   multiplier is exposed so a reader can see what the threshold IS rather than inferring it. */
const PERIMETER_PX = Number(arg('perimeter-px', 2));
const SETTLE = Number(arg('settle', 320));             /* ms; the longest transition here is 0.15s */

const ROUTES = (arg('routes', '') || [
  'dashboard', 'product', 'order', 'finance', 'finance/withdrawals', 'setting',
].join(',')).split(',').map((s) => s.trim()).filter(Boolean);

execFileSync('php', [TOOL, '1', THEME, DIR, LANG, 'merchant@example.test'], {
  cwd: path.dirname(TOOL),
  env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(REPO, 'database/p2dev.sqlite'), CACHE_STORE: 'file' },
  stdio: ['ignore', 'pipe', 'pipe'],
});

/* Decode two PNGs and count differing pixels. Runs in a blank page so the measured page is never
   disturbed, and so no image library is needed on the host. */
/* A REAL FUNCTION, NOT A STRING. Playwright evaluates a string argument as an EXPRESSION and
   ignores the parameter that follows it, so the first draft returned a function object instead of
   a measurement and the caller read `undefined.error`. */
async function diffInPage([a, b]) {
  const load = (b64) => new Promise((res, rej) => {
    const im = new Image();
    im.onload = () => res(im);
    im.onerror = rej;
    im.src = 'data:image/png;base64,' + b64;
  });
  const [ia, ib] = await Promise.all([load(a), load(b)]);
  if (ia.width !== ib.width || ia.height !== ib.height) { return { error: 'size mismatch' }; }
  const w = ia.width, h = ia.height;
  const draw = (im) => {
    const c = document.createElement('canvas');
    c.width = w; c.height = h;
    const g = c.getContext('2d', { willReadFrequently: true });
    g.drawImage(im, 0, 0);
    return g.getImageData(0, 0, w, h).data;
  };
  const da = draw(ia), db = draw(ib);
  let changed = 0, maxDelta = 0;
  for (let i = 0; i < da.length; i += 4) {
    const d = Math.max(Math.abs(da[i] - db[i]), Math.abs(da[i + 1] - db[i + 1]), Math.abs(da[i + 2] - db[i + 2]));
    if (d > 8) { changed++; }
    if (d > maxDelta) { maxDelta = d; }
  }
  return { w, h, changed, percent: +(100 * changed / (w * h)).toFixed(2), maxDelta };
}

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
  const p = await ctx.newPage();
  const judge = await ctx.newPage();          /* the scratch page that does the decoding */
  await judge.goto('about:blank');

  await p.goto('http://127.0.0.1:8899/login');
  await p.fill('input[name="email"]', 'merchant@example.test');
  await p.fill('input[name="password"]', 'password');
  await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"], input[type="submit"]')]);

  let tAll = 0, tBare = 0;
  const bare = [];

  for (const r of ROUTES) {
    let status = 0;
    try {
      const resp = await p.goto('http://127.0.0.1:8899/' + r, { waitUntil: 'domcontentloaded', timeout: 30000 });
      status = resp ? resp.status() : 0;
    } catch (e) { console.log(`  ${r.padEnd(20)} ERR`); continue; }
    if (status !== 200) { console.log(`  ${r.padEnd(20)} HTTP ${status}`); continue; }
    await p.waitForTimeout(1500);

    let stops = 0, routeBare = 0;

    for (let i = 1; i <= STOPS; i++) {
      /* walk the real tab order from the document each time, so :focus-visible is genuine */
      await p.evaluate(() => { document.body.setAttribute('tabindex', '-1'); document.body.focus(); });
      for (let k = 0; k < i; k++) { await p.keyboard.press('Tab'); }

      const info = await p.evaluate(() => {
        const el = document.activeElement;
        if (!el || el === document.body || el === document.documentElement) { return null; }
        /* INSTANT, and the rect read AFTER it. `scroll-behavior: smooth` is set on this admin, so
           a rect read straight after a default scrollIntoView is the PRE-scroll one: the clip then
           lands outside the viewport and the screenshot throws. The same transition trap that
           defeated three drafts of the style-reading census, in a third costume. */
        el.scrollIntoView({ block: 'center', behavior: 'instant' });
        const q = el.getBoundingClientRect();
        if (q.width < 4 || q.height < 4) { return null; }
        const pad = 8;
        const vw = document.documentElement.clientWidth;
        const vh = document.documentElement.clientHeight;
        const x = Math.max(0, Math.round(q.left - pad));
        const y = Math.max(0, Math.round(q.top - pad));
        if (x >= vw || y >= vh) { return null; }   /* still off-screen: nothing to compare */
        var already = el.hasAttribute('data-ss-focus-seen');
        el.setAttribute('data-ss-focus-seen', '1');
        return {
          alreadySeen: already,
          name: el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).trim().split(/\\s+/)[0] : ''),
          w: Math.round(q.width),
          h: Math.round(q.height),
          clip: {
            x: x,
            y: y,
            width: Math.max(1, Math.min(Math.round(q.width + pad * 2), 900, vw - x)),
            height: Math.max(1, Math.min(Math.round(q.height + pad * 2), 400, vh - y)),
          },
        };
      });
      if (!info) { continue; }
      stops++;
      /* DEDUPE BY ELEMENT, NOT BY NAME-AND-INDEX. The first draft keyed on `name#tabIndex`, so a
         control the walk reaches at two positions -- or two controls sharing a class, which the
         sidebar and the mobile header both produce -- was counted twice and the summary line
         doubled. The element stamps itself on first visit. */
      if (info.alreadySeen) { continue; }

      await p.waitForTimeout(SETTLE);
      const focused = (await p.screenshot({ clip: info.clip })).toString('base64');

      await p.evaluate(() => { if (document.activeElement && document.activeElement.blur) { document.activeElement.blur(); } });
      await p.waitForTimeout(SETTLE);
      const resting = (await p.screenshot({ clip: info.clip })).toString('base64');

      const d = await judge.evaluate(diffInPage, [focused, resting]);
      if (d.error) { continue; }

      /* the area a conformant indicator must at least cover: a PERIMETER_PX ring round the control */
      const needed = Math.round(PERIMETER_PX * 2 * (info.w + info.h));

      if (d.changed < needed) {
        routeBare++;
        bare.push(`${r}:${info.name} ${info.w}x${info.h} -- ${d.changed}px changed, `
          + `needs ${needed}px for a ${PERIMETER_PX}px perimeter (max channel delta ${d.maxDelta})`);
      }
    }

    tAll += stops; tBare += routeBare;
    console.log(`  ${r.padEnd(20)} ${String(stops).padStart(3)} tab stops   indicator smaller than a ${PERIMETER_PX}px perimeter: ${routeBare}${routeBare ? '' : '   clean'}`);
  }

  if (bare.length) {
    console.log('\n  controls whose focus indicator is smaller than SC 2.4.11 asks for:');
    for (const x of bare.slice(0, 20)) { console.log(`     ${x}`); }
  }
  console.log(`\n  ${THEME}/${LANG}: ${tBare} of ${tAll} tab stops have an indicator smaller than a `
    + `${PERIMETER_PX}px perimeter of their own control (WCAG 2.2 SC 2.4.11)`);
  await b.close();
  process.exit(tBare === 0 ? 0 : 1);
})();
