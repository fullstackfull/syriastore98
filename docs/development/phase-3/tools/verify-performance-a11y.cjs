/**
 * PHASE 03 M2-E — CORE WEB VITALS AND AN ACCESSIBILITY RULE PASS.
 *
 * M1 measured neither and said so. This establishes the first honest baseline, and the honesty is
 * mostly in what it refuses to claim.
 *
 * WHAT THESE NUMBERS ARE NOT:
 *
 *   - NOT PRODUCTION FIGURES. Everything runs against `php artisan serve` on 127.0.0.1: no network
 *     latency, no TLS handshake, no CDN, no HTTP/2, no opcache, a SQLite fixture instead of MySQL,
 *     and `tailwind.js` compiling at runtime in the browser. Production will differ in both
 *     directions and the gap is not estimable from here. Treat them as a REGRESSION BASELINE for
 *     comparing M2 against M3, not as a user-experience claim.
 *
 *   - INP IS NOT MEASURED. Interaction to Next Paint is a FIELD metric over a real person's
 *     interactions; there is no lab equivalent. What is reported instead is `clickLatency` -- the
 *     time from a scripted click on the appearance toggle to the next paint. It is a useful
 *     regression number and it is NOT INP. Reporting it as INP would be a fabrication.
 *
 *   - LCP IN A HEADLESS BROWSER is measured without a real viewport paint pipeline. It is
 *     comparable run-to-run on this machine; it is not comparable to a field LCP.
 *
 * WHAT THEY ARE: LCP and CLS from the browser's own PerformanceObserver, and axe-core 4.10.2 run
 * with the `wcag2a`, `wcag2aa`, `wcag21a` and `wcag21aa` rule tags. axe finds a well-defined subset
 * of WCAG problems -- roughly a third by most estimates -- so ZERO VIOLATIONS IS NOT AN
 * ACCESSIBILITY PASS. It means no violation of the rules axe can automate.
 *
 * REQUIRES axe-core, which is NOT a committed dependency:
 *     npm install --no-save axe-core@4.10.2
 *     docs/development/phase-2.5/tools/serve-dev.sh      # 127.0.0.1:8899, another shell
 *     node docs/development/phase-3/tools/verify-performance-a11y.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const B = 'http://127.0.0.1:8899';
const AXE = path.join(process.cwd(), 'node_modules/axe-core/axe.min.js');

const STORES = [
  ['damascus-textiles', 'stylique'],
  ['aleppo-soap', 'stylique'],
  ['p3fixt-homs-tech', 'techzonix'],
  ['p3fixt-latakia-green', 'greentic'],
  ['p3fixt-no-theme', 'stylique (fallback)'],
];
const ROUTES = ['/home', '/product-list', '/cart', '/login'];
const LOCALES = ['en', 'ar'];
const APPEARANCES = ['light', 'dark'];

if (!fs.existsSync(AXE)) {
  console.error('axe-core is not installed. Run: npm install --no-save axe-core@4.10.2');
  process.exit(2);
}
const axeSource = fs.readFileSync(AXE, 'utf8');

/** LCP and CLS, collected by the browser's own observers before the measurement is read. */
const VITALS_INIT = () => {
  window.__v = { lcp: 0, cls: 0, shifts: 0 };
  try {
    new PerformanceObserver((l) => {
      for (const e of l.getEntries()) { window.__v.lcp = Math.max(window.__v.lcp, e.startTime); }
    }).observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver((l) => {
      for (const e of l.getEntries()) {
        // Only shifts WITHOUT recent input count toward CLS, per the metric's definition.
        if (!e.hadRecentInput) { window.__v.cls += e.value; window.__v.shifts++; }
      }
    }).observe({ type: 'layout-shift', buffered: true });
  } catch (e) { window.__v.error = String(e); }
};

(async () => {
  const browser = await chromium.launch();
  const rows = [];

  for (const [slug, theme] of STORES) {
    for (const appearance of APPEARANCES) {
      for (const locale of LOCALES) {
        const ctx = await browser.newContext({
          viewport: { width: 1440, height: 900 },
          colorScheme: appearance === 'dark' ? 'dark' : 'light',
        });
        const page = await ctx.newPage();
        await page.addInitScript(VITALS_INIT);

        // Put the store into the requested locale and appearance the way a shopper does.
        await page.goto(`${B}/${slug}/home`, { waitUntil: 'domcontentloaded' }).catch(() => {});
        const key = await page.evaluate(() => document.documentElement.getAttribute('data-ss-appearance-key'));
        if (key) await page.evaluate(([k, v]) => { try { localStorage.setItem(k, v); } catch (e) {} }, [key, appearance]);
        await page.goto(`${B}/change-language-store/${locale}`, { waitUntil: 'domcontentloaded' }).catch(() => {});

        for (const route of ROUTES) {
          await page.goto(`${B}/${slug}${route}`, { waitUntil: 'load' }).catch(() => {});
          // Let late images and the runtime Tailwind build settle, or LCP reads early and CLS low.
          await page.waitForTimeout(1500);

          const v = await page.evaluate(() => window.__v).catch(() => ({}));

          // M2-E ORDERING FIX, AND IT MATTERED.
          //
          // The click below was measured BEFORE axe ran. The appearance control cycles
          // light -> dark -> auto, so the click left the page in a DIFFERENT appearance from the
          // one this row is labelled with, and every violation was then attributed to the wrong
          // state. It read as 59 color-contrast nodes across 19 rows -- including greentic light
          // /home at 19 nodes, where a direct sweep of that exact combination found none.
          //
          // axe now runs on the appearance the row names. The click is last, because it is the
          // only step that deliberately changes state.
          await page.evaluate(axeSource);
          const axeResult = await page.evaluate(async () => {
            const r = await window.axe.run(document, {
              runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
              resultTypes: ['violations'],
            });
            return r.violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.length }));
          }).catch((e) => [{ id: 'AXE_FAILED', impact: String(e).slice(0, 60), nodes: 0 }]);

          // NOT INP: the latency of ONE scripted click to the next paint. LAST, because it
          // changes the appearance and would otherwise poison the axe run above.
          let clickLatency = null;
          const hasToggle = await page.locator('[data-ss-appearance-toggle]').count();
          if (hasToggle) {
            clickLatency = await page.evaluate(async () => {
              const el = document.querySelector('[data-ss-appearance-toggle]');
              const t0 = performance.now();
              el.click();
              await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
              return Math.round(performance.now() - t0);
            }).catch(() => null);
          }

          rows.push({
            store: slug, theme, locale, appearance, route,
            lcpMs: Math.round(v.lcp || 0), cls: Number((v.cls || 0).toFixed(4)),
            shifts: v.shifts || 0, clickLatencyMs: clickLatency,
            violations: axeResult,
          });
          process.stdout.write('.');
        }
        await ctx.close();
      }
    }
  }
  console.log('\n');

  // ---- report ---------------------------------------------------------------------------------
  const num = (a) => a.filter((x) => typeof x === 'number' && !Number.isNaN(x));
  const p75 = (a) => { const s = num(a).sort((x, y) => x - y); return s.length ? s[Math.floor(s.length * 0.75)] : null; };
  const max = (a) => { const s = num(a); return s.length ? Math.max(...s) : null; };

  console.log('  CORE WEB VITALS -- LAB, on 127.0.0.1, NOT production figures');
  console.log(`  ${'theme'.padEnd(20)} ${'appear'.padEnd(7)} ${'rows'.padEnd(5)} ${'LCP p75'.padStart(8)} ${'LCP max'.padStart(8)} ${'CLS p75'.padStart(8)} ${'CLS max'.padStart(8)}  click p75`);
  const byKey = {};
  for (const r of rows) {
    const k = r.theme + '|' + r.appearance;
    (byKey[k] = byKey[k] || []).push(r);
  }
  for (const k of Object.keys(byKey).sort()) {
    const g = byKey[k]; const [theme, app] = k.split('|');
    console.log(`  ${theme.padEnd(20)} ${app.padEnd(7)} ${String(g.length).padEnd(5)} ` +
      `${String(p75(g.map((r) => r.lcpMs))).padStart(8)} ${String(max(g.map((r) => r.lcpMs))).padStart(8)} ` +
      `${String(p75(g.map((r) => r.cls))).padStart(8)} ${String(max(g.map((r) => r.cls))).padStart(8)}  ` +
      `${String(p75(g.map((r) => r.clickLatencyMs)))}`);
  }

  const good = (lcp, cls) => lcp <= 2500 && cls <= 0.1;
  const bad = rows.filter((r) => !good(r.lcpMs, r.cls));
  console.log(`\n  rows outside the "good" thresholds (LCP <= 2500ms, CLS <= 0.1): ${bad.length} of ${rows.length}`);
  bad.slice(0, 10).forEach((r) => console.log(`     ${r.theme} ${r.locale} ${r.appearance} ${r.route}  LCP ${r.lcpMs}ms  CLS ${r.cls}`));

  console.log('\n  AXE-CORE 4.10.2, tags wcag2a/wcag2aa/wcag21a/wcag21aa');
  const vio = {};
  for (const r of rows) {
    for (const v of r.violations) {
      const k = `${v.id} (${v.impact})`;
      vio[k] = vio[k] || { rows: 0, nodes: 0, where: new Set() };
      vio[k].rows++; vio[k].nodes += v.nodes;
      vio[k].where.add(`${r.theme}/${r.appearance}`);
    }
  }
  const keys = Object.keys(vio).sort((a, b) => vio[b].nodes - vio[a].nodes);
  if (!keys.length) {
    console.log('    no violations of the rules axe can automate.');
  } else {
    console.log(`  ${'rule (impact)'.padEnd(46)} ${'rows'.padStart(5)} ${'nodes'.padStart(6)}  where`);
    for (const k of keys) {
      const v = vio[k];
      console.log(`    ${k.padEnd(46)} ${String(v.rows).padStart(5)} ${String(v.nodes).padStart(6)}  ${[...v.where].slice(0, 4).join(' ')}`);
    }
  }
  console.log(`\n  ZERO VIOLATIONS IS NOT AN ACCESSIBILITY PASS: axe automates roughly a third of`);
  console.log(`  WCAG. It means no violation of the rules it can check, on ${rows.length} page loads.`);

  fs.writeFileSync('/tmp/perf-a11y.json', JSON.stringify({ rows }, null, 1));
  console.log('\n  raw rows: /tmp/perf-a11y.json');
  await browser.close();
})();
