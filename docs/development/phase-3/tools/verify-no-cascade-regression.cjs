/**
 * PHASE 03 M2-A -- DID LOADING THE DESIGN SYSTEM CHANGE THE EXISTING STOREFRONT?
 *
 * The M2 directive says to "verify load order and CSS cascade effects rather than assuming
 * isolation", and CLAUDE.md's confinement claim is exactly the kind of assurance that should be
 * measured. This renders each theme twice -- once normally, once with the `store-syria-ds.css`
 * request ABORTED, which is precisely the pre-M2-A state -- and compares the computed colour,
 * background, border, type, spacing, display and radius of a broad sample of THEME-owned elements.
 *
 * A difference means the sheet reached theme markup and the confinement claim is false for it.
 *
 *   node docs/development/phase-3/tools/verify-no-cascade-regression.cjs
 *
 * Last run: 1,134 comparisons across 3 themes x 6 routes, 0 differences.
 */
const { chromium } = require('playwright');
const B = 'http://127.0.0.1:8899';
const STORES = [['damascus-textiles','stylique'],['p3fixt-homs-tech','techzonix'],['p3fixt-latakia-green','greentic']];
const ROUTES = ['/home','/product-list','/collections','/cart','/contact-us','/login'];

// A broad sample of THEME-owned elements. If the design system is confined, every one of these
// must compute identically with the sheet loaded and with it blocked.
const SAMPLE = () => {
  const out = {};
  const sel = ['body','header','footer','h1','h2','h3','a','p','button:not([data-ss-appearance-toggle])',
               'input','label','.container','nav','img','ul','li','span','div.card','form'];
  for (const s of sel) {
    const els = [...document.querySelectorAll(s)].slice(0, 6);
    els.forEach((el, i) => {
      const cs = getComputedStyle(el);
      out[`${s}#${i}`] = [cs.color, cs.backgroundColor, cs.borderColor, cs.fontSize,
                          cs.fontWeight, cs.padding, cs.margin, cs.display, cs.borderRadius].join('|');
    });
  }
  return out;
};

(async () => {
  const b = await chromium.launch();
  let checked = 0, diffs = 0;
  const examples = [];

  for (const [slug, theme] of STORES) {
    for (const route of ROUTES) {
      // WITH the design system
      const c1 = await b.newContext({ viewport:{width:1440,height:900}, colorScheme:'light' });
      const p1 = await c1.newPage();
      await p1.goto(`${B}/${slug}${route}`, {waitUntil:'domcontentloaded'});
      await p1.waitForTimeout(250);
      const withDs = await p1.evaluate(SAMPLE);
      await c1.close();

      // WITHOUT it -- the sheet request is aborted, which is exactly "the state before M2-A"
      const c2 = await b.newContext({ viewport:{width:1440,height:900}, colorScheme:'light' });
      const p2 = await c2.newPage();
      await p2.route('**/css/store-syria-ds.css', r => r.abort());
      await p2.goto(`${B}/${slug}${route}`, {waitUntil:'domcontentloaded'});
      await p2.waitForTimeout(250);
      const withoutDs = await p2.evaluate(SAMPLE);
      await c2.close();

      const keys = new Set([...Object.keys(withDs), ...Object.keys(withoutDs)]);
      let routeDiffs = 0;
      for (const k of keys) {
        checked++;
        if (withDs[k] !== withoutDs[k]) {
          routeDiffs++; diffs++;
          if (examples.length < 12) examples.push(`${theme}${route} ${k}\n        with: ${withDs[k]}\n     without: ${withoutDs[k]}`);
        }
      }
      console.log(`  ${theme.padEnd(10)} ${route.padEnd(14)} ${String(keys.size).padStart(3)} properties, ${routeDiffs} differ`);
    }
  }

  console.log(`\n  ${checked} computed-style comparisons, ${diffs} differences`);
  if (examples.length) { console.log('\n  DIFFERENCES:'); examples.forEach(e => console.log('     ' + e)); }
  await b.close();
})();
