/**
 * PHASE 03 M2-E — THE FULL CROSS-STORE ACCESSIBILITY MATRIX.
 *
 *   node docs/development/phase-3/tools/verify-a11y-matrix.cjs [--rule color-contrast] [--detail]
 *
 * WHY THIS EXISTS RATHER THAN REUSING THE OTHER TWO SWEEPS. Each of the earlier ones left a hole,
 * and both holes produced a wrong number that looked right:
 *
 *   1. `auto` WAS NEVER SWEPT, and `auto` is the DEFAULT. A shopper who has never touched the
 *      control is on `auto`, which resolves through `prefers-color-scheme`. A rule written only for
 *      `[data-ss-theme="dark"]` therefore misses most visitors -- the exact mistake the stylique
 *      page-background fix made on its first draft. Driving only the explicit states tests the
 *      state fewest people are in. Here `auto` is driven twice, under an OS that says light and an
 *      OS that says dark, and the rendered appearance is READ BACK and asserted against what was
 *      asked for, so a toggle that silently fails to apply cannot pass as a clean sweep.
 *
 *   2. ARABIC WAS NEVER SWEPT. The storefront ships ar-RTL and en-LTR. RTL changes which logical
 *      side a control sits on and therefore what overlaps what; and a label hard-coded in English
 *      is only visible as a defect when the page is Arabic.
 *
 * A THIRD HOLE, in a tool rather than its coverage, is worth recording because it is the reason
 * this file reads the appearance back. `verify-performance-a11y.cjs` clicked the appearance toggle
 * to time a paint and then ran axe. The control cycles light -> dark -> auto, so every violation
 * was attributed to the state BEFORE the click rather than the one axe actually saw. It reported
 * 59 color-contrast nodes over 19 rows -- greentic light /home at 19 nodes among them -- where a
 * direct sweep of those same combinations found none. Fixed there; guarded against here.
 *
 * WHAT A PASS MEANS, EXACTLY. No violation of the rules axe can automate, which is roughly a third
 * of WCAG. It is not an accessibility pass. Keyboard order, focus management, meaningful alt text
 * and whether a label describes its control still need a person.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const AXE_PATH = '/home/user/syriastore98/node_modules/axe-core/axe.min.js';
const B = 'http://127.0.0.1:8899';

/** Every fixture store, with the theme it resolves to. `p3fixt-no-theme` has an EMPTY theme_id. */
const STORES = [
  ['damascus-textiles',    'stylique'],
  ['aleppo-soap',          'stylique-2'],
  ['p3fixt-homs-tech',     'techzonix'],
  ['p3fixt-latakia-green', 'greentic'],
  ['p3fixt-no-theme',      'fallback'],
];
const LOCALES = ['en', 'ar'];
const ROUTES  = ['/home', '/product-list', '/cart', '/login'];

/**
 * The appearance states a shopper can be in. `stored` is what goes in localStorage; `os` is what
 * the device reports. `auto` appears twice because its whole point is that the OS decides.
 */
const STATES = [
  { name: 'light',      stored: 'light', os: 'light', expect: 'light' },
  { name: 'dark',       stored: 'dark',  os: 'light', expect: 'dark'  },
  { name: 'auto/os-lt', stored: null,    os: 'light', expect: 'auto'  },
  { name: 'auto/os-dk', stored: null,    os: 'dark',  expect: 'auto'  },
];

const ONLY_RULE = (() => {
  const i = process.argv.indexOf('--rule');
  return i > -1 ? process.argv[i + 1] : null;
})();
const DETAIL = process.argv.includes('--detail');

(async () => {
  if (!fs.existsSync(AXE_PATH)) {
    console.error(`axe-core is not installed at ${AXE_PATH}.\n`
      + '  npm install --no-save axe-core@4.10.2');
    process.exit(2);
  }
  const AXE = fs.readFileSync(AXE_PATH, 'utf8');

  const browser = await chromium.launch();
  const tally   = {};      // rule -> {nodes, rows, where:Set}
  const detail  = [];
  const mismatched = [];   // the appearance did not apply -- the sweep would be meaningless
  let loads = 0, failedLoads = 0;

  for (const [slug, theme] of STORES) {
    for (const locale of LOCALES) {
      for (const st of STATES) {
        const ctx = await browser.newContext({
          viewport: { width: 1440, height: 900 },
          colorScheme: st.os,
        });
        const page = await ctx.newPage();

        // One visit to learn the per-store storage key, then set the stored preference the way
        // the control does. The key is store-scoped on purpose: one store's choice must not
        // reach another's.
        await page.goto(`${B}/${slug}/home`, { waitUntil: 'domcontentloaded' }).catch(() => {});
        const key = await page.evaluate(
          () => document.documentElement.getAttribute('data-ss-appearance-key')).catch(() => null);

        if (key) {
          await page.evaluate(([k, v]) => {
            try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) {}
          }, [key, st.stored]).catch(() => {});
        }

        await page.goto(`${B}/change-language-store/${locale}`, { waitUntil: 'domcontentloaded' })
          .catch(() => {});

        for (const route of ROUTES) {
          const res = await page.goto(`${B}/${slug}${route}`, { waitUntil: 'load', timeout: 30000 })
            .catch(() => null);

          if (!res || res.status() >= 400) {
            failedLoads++;
            console.log(`  !! ${theme}/${locale}/${st.name}${route}: `
              + (res ? `HTTP ${res.status()}` : 'navigation failed'));
            continue;
          }
          loads++;

          // Let the runtime Tailwind build and late images settle.
          await page.waitForTimeout(600);

          // READ THE APPEARANCE BACK. A sweep of a state that never applied proves nothing.
          const applied = await page.evaluate(() => ({
            attr: document.documentElement.getAttribute('data-ss-theme'),
            lang: document.documentElement.getAttribute('lang'),
            dir:  document.documentElement.getAttribute('dir'),
            bg:   getComputedStyle(document.body).backgroundColor,
          }));

          if (applied.attr !== st.expect) {
            mismatched.push(`${theme}/${locale}/${st.name}${route}: asked for `
              + `${st.expect}, page says ${applied.attr}`);
          }
          if (locale === 'ar' && applied.dir !== 'rtl') {
            mismatched.push(`${theme}/${locale}${route}: Arabic page is dir=${applied.dir}`);
          }

          await page.addScriptTag({ content: AXE });
          const out = await page.evaluate(async (rule) => {
            const opts = rule
              ? { runOnly: [rule] }
              : { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } };
            opts.resultTypes = ['violations'];
            const r = await window.axe.run(document, opts);
            return r.violations.map((v) => ({
              id: v.id, impact: v.impact, n: v.nodes.length,
              nodes: v.nodes.slice(0, 4).map((n) => ({
                target: n.target.join(' ').slice(0, 110),
                html: n.html.slice(0, 110).replace(/\s+/g, ' '),
                data: n.any && n.any[0] ? n.any[0].data : null,
              })),
            }));
          }, ONLY_RULE).catch((e) => [{ id: 'AXE_FAILED', impact: String(e).slice(0, 80), n: 0, nodes: [] }]);

          for (const v of out) {
            tally[v.id] = tally[v.id] || { nodes: 0, rows: 0, impact: v.impact, where: new Set() };
            tally[v.id].nodes += v.n;
            tally[v.id].rows  += 1;
            tally[v.id].where.add(`${theme}/${locale}/${st.name}`);
            if (DETAIL) {
              for (const n of v.nodes) {
                detail.push(`    ${v.id} ${theme}/${locale}/${st.name}${route}  ${n.html}`
                  + (n.data ? `  [${n.data.contrastRatio}:1 ${n.data.fgColor} on ${n.data.bgColor}]` : ''));
              }
            }
          }
        }
        await ctx.close();
      }
    }
  }
  await browser.close();

  const combos = STORES.length * LOCALES.length * STATES.length;
  console.log(`\n  ${loads} page load(s) over ${combos} store/locale/appearance combinations`
    + `${failedLoads ? `, ${failedLoads} FAILED TO LOAD` : ''}`);
  console.log(`  ${STORES.length} stores x ${LOCALES.length} locales x ${STATES.length} appearance `
    + `states x ${ROUTES.length} routes`);

  if (mismatched.length) {
    console.log(`\n  !! ${mismatched.length} load(s) did not render the appearance or direction `
      + 'asked for. Every number below is about the WRONG state and must not be reported as a pass:');
    mismatched.slice(0, 12).forEach((m) => console.log(`     ${m}`));
  } else {
    console.log('  every load rendered the appearance and text direction it was asked for');
  }

  console.log(`\n  AXE-CORE${ONLY_RULE ? `, rule ${ONLY_RULE}` : ', tags wcag2a/wcag2aa/wcag21a/wcag21aa'}`);
  const ids = Object.keys(tally);
  if (!ids.length) {
    console.log('    no violations of the rules axe can automate.');
  } else {
    console.log(`    ${'rule (impact)'.padEnd(34)} ${'rows'.padStart(5)} ${'nodes'.padStart(6)}  where`);
    for (const id of ids.sort((a, b) => tally[b].nodes - tally[a].nodes)) {
      const t = tally[id];
      console.log(`    ${(id + ' (' + t.impact + ')').padEnd(34)} ${String(t.rows).padStart(5)} `
        + `${String(t.nodes).padStart(6)}  ${[...t.where].slice(0, 4).join(' ')}`
        + (t.where.size > 4 ? ` +${t.where.size - 4}` : ''));
    }
  }
  if (DETAIL && detail.length) {
    console.log('\n  nodes:');
    detail.slice(0, 60).forEach((d) => console.log(d));
  }

  console.log('\n  ZERO VIOLATIONS IS NOT AN ACCESSIBILITY PASS: axe automates roughly a third of');
  console.log('  WCAG. Keyboard order, focus management, whether alt text is meaningful and');
  console.log('  whether a label describes its control all still need a person.');

  // A sweep that could not load its pages, or rendered the wrong state, is not evidence.
  process.exit(failedLoads || mismatched.length || ids.length ? 1 : 0);
})();
