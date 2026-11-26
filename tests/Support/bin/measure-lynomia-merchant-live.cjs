/**
 * GAP-179 -- THE LynomiaChat MERCHANT SURFACES, MEASURED IN THE FOUR REAL CONFIGURATIONS.
 * =================================================================================================
 *
 * WHY A FOURTH HARNESS AND NOT A FLAG ON AN EXISTING ONE. Two already measure this kind of thing
 * and neither can answer this question:
 *
 *   measure-superadmin-premium-live.cjs   does all four combinations, signs in as the OPERATOR.
 *                                         Every LynomiaChat merchant route carries
 *                                         `PlanModuleCheck` and `EnsureStoreOwnsLink`, so the
 *                                         operator is refused and photographs the dashboard.
 *   phase-2.5/tools/census-low-contrast-text.cjs
 *                                         signs in as the MERCHANT but hardcodes `'ltr', 'en'`
 *                                         (:88, :109), so it is a TWO-configuration harness
 *                                         wearing a four-configuration name. A defect that fails
 *                                         only in Arabic, or only in RTL, is invisible to it.
 *
 * The contrast walk below is the operator harness's, which is in turn Phase 2.5's
 * (`census-low-contrast-text.cjs`), reused in substance so a number measured here is the same KIND
 * of number as one measured there: a composited background, a WCAG 2.1 AA floor of 4.5:1 for body
 * text and 3:1 for large text (>=24px, or >=18.66px at weight >=700), and a text-node walk rather
 * than an element walk, because an element's `color` is not what is painted where it has no text.
 *
 * WHAT IT ADDS, and each addition is here because the thing it guards has really gone wrong:
 *
 *  1. IT SIGNS IN AS THE MERCHANT AND ASSERTS THE PAGE IT ASKED FOR. Every gate on these routes
 *     answers `redirect()->route('dashboard')` (`EnsureStoreOwnsLink::refuse`), which is HTTP 200
 *     with a fully rendered page -- the DASHBOARD. A status-code check records a pass and measures
 *     the wrong screen. The final URL is compared with the requested one and a mismatch is a
 *     failure, not a pass. This is the same trap `grant-dev-operator-role.php` records on the
 *     operator side, on a different door.
 *
 *  2. IT ASSERTS THE THEME AND THE DIRECTION PREMISE PER PAGE AND REFUSES TO MEASURE WITHOUT THEM.
 *     The admin theme is a SETTING (`cust_darklayout`), not `prefers-color-scheme`, so
 *     Playwright's `colorScheme` emulation changes nothing and produces two identical "light"
 *     captures. And direction follows the USER'S LANGUAGE, not the store's `SITE_RTL`
 *     (`layouts/app.blade.php` overrides it whenever `auth()->user()->language` is `ar` or `he`),
 *     so a harness that flips only `SITE_RTL` shoots an RTL page in all four "configurations" --
 *     measured in this repository as two byte-identical PNGs.
 *
 *  3. IT COUNTS THE PHP DEBUGBAR AS A HARNESS FAULT RATHER THAN A FINDING. With `APP_DEBUG=true`
 *     the debugbar injects `span.phpdebugbar-badge` at 3.89:1, and the operator harness reported
 *     EIGHT such findings on one surface before `APP_DEBUG` was turned off -- development
 *     furniture filed as product defects. Anything inside `.phpdebugbar` is excluded here, and
 *     the count of exclusions is printed so the reader knows the run was made with debug on.
 *
 * NEITHER THE THRESHOLD NOR THE SURFACE LIST MAY BE RELAXED TO MAKE A RUN PASS.
 *
 * PRECONDITIONS, all of which fail loudly rather than silently:
 *   - a dev server serving database/p2dev.sqlite with CACHE_STORE=file and SESSION_DRIVER=file
 *     (docs/development/phase-2.5/tools/serve-dev.sh, or the same variables by hand)
 *   - `merchant@example.test` present with `current_store` set (database/seed-p2dev.php)
 *   - that merchant holding `Manage Lynomia Automation` and `Manage Lynomia Consent`, the module
 *     in its plan's `modules`, and a `lynomia_store_links` row for its store
 *     (tests/Support/bin/grant-dev-lynomia-merchant.php -- WITHOUT IT EVERY SURFACE REDIRECTS TO
 *     THE DASHBOARD AND A STATUS-CODE HARNESS REPORTS A PASS)
 *
 * usage:
 *   node tests/Support/bin/measure-lynomia-merchant-live.cjs \
 *        [--base http://127.0.0.1:8899] [--combos light-en,dark-en,light-ar,dark-ar]
 *        [--surfaces /lynomia/automation/commerce] [--shots <dir>]
 *
 * exit 0 only when every premise held and no contrast floor was breached.
 */
'use strict';

const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPO = path.resolve(__dirname, '../../..');
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');
const MERCHANT = 'merchant@example.test';
const MERCHANT_PASSWORD = 'password';

const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };

const BASE = arg('base', 'http://127.0.0.1:8899');
const SHOTS = arg('shots', null);

/*
 * GAP-113 requires a capture to assert "the landed path AND the `h1`", so both are options rather
 * than prose. `--expect-h1` is a comma-separated list, positionally matched to `--surfaces`; a
 * surface whose entry is `-` is only required to HAVE a heading, not a particular one (an Arabic
 * page's heading is not the English one, so a single literal cannot serve all four combinations).
 * `--forbid-h1` is the other half and the one that catches the real failure: the earlier evidence
 * is four pictures of the dashboard, so "Dashboard" must never be the heading of a theme screen.
 */
const EXPECT_H1 = (arg('expect-h1', null) || '').split(',').map(s => s.trim());
const FORBID_H1 = (arg('forbid-h1', null) || '').split(',').map(s => s.trim()).filter(Boolean);

/*
 * THE SURFACE LIST IS THE MODULE'S OWN MERCHANT VIEWS, derived from the route table rather than
 * chosen by eye: every `GET` under the `lynomia` prefix that renders a `lynomia-chat::merchant.*`
 * view. `/lynomia/automation/commerce` is first because it is the one with the measured defect --
 * two `nav-link` tab labels the host paints with `!important`, the same shape already repaired on
 * `superadmin/integration.blade.php`.
 */
const DEFAULT_SURFACES = [
  ['/lynomia/automation/commerce', 'merchant/commerce-automation.blade.php'],
  ['/lynomia', 'merchant/overview.blade.php'],
  ['/lynomia/templates', 'merchant/templates.blade.php'],
  ['/lynomia/campaigns', 'merchant/campaigns.blade.php'],
];

const surfaceFilter = arg('surfaces', null);
const SURFACES = surfaceFilter
  ? surfaceFilter.split(',').map(s => s.trim()).filter(Boolean).map(uri => {
      const known = DEFAULT_SURFACES.find(([u]) => u === uri);
      return { uri, view: known ? known[1] : '(not in the default list)' };
    })
  : DEFAULT_SURFACES.map(([uri, view]) => ({ uri, view }));

const COMBOS = arg('combos', 'light-en,dark-en,light-ar,dark-ar')
  .split(',').map(s => s.trim()).filter(Boolean)
  .map(name => {
    const [theme, lang] = name.split('-');
    return { name, theme, lang, dir: lang === 'ar' || lang === 'he' ? 'rtl' : 'ltr' };
  });

/* MEASURED, not chosen: the dark host sheet sets this on <body>. Asserting it is what separates a
   dark capture from a light one wearing a dark label. */
const DARK_BODY_BG = 'rgb(34, 36, 44)';

const phpEnv = {
  ...process.env,
  APP_ENV: 'local',
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: path.join(REPO, 'database/p2dev.sqlite'),
  CACHE_STORE: 'file',
  SESSION_DRIVER: 'file',
};

function setState(combo) {
  return execFileSync('php', [STATE_TOOL, '1', combo.theme, combo.dir, combo.lang, MERCHANT],
    { env: phpEnv, encoding: 'utf8' }).trim();
}

const SCAN = () => {
  const parse = s => (s.match(/[\d.]+/g) || []).map(Number);

  const paintedBg = (el) => {
    const L = [];
    for (let e = el; e; e = e.parentElement) {
      const c = parse(getComputedStyle(e).backgroundColor);
      if (c.length < 3) continue;
      const a = c[3] === undefined ? 1 : c[3];
      if (a <= 0) continue;
      L.push([c[0], c[1], c[2], a]);
      if (a >= 1) break;
    }
    if (!L.length) return [255, 255, 255];
    let out = L[L.length - 1][3] >= 1 ? L[L.length - 1].slice(0, 3) : [255, 255, 255];
    for (let i = L.length - 1; i >= 0; i--) {
      const [r, g, b, a] = L[i];
      if (a >= 1) { out = [r, g, b]; continue; }
      out = [r * a + out[0] * (1 - a), g * a + out[1] * (1 - a), b * a + out[2] * (1 - a)];
    }
    return out.map(Math.round);
  };

  const lum = c => {
    const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
  };
  const ratio = (a, b) => {
    const x = lum(a), y = lum(b);
    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
  };

  const clippedAway = cs => cs.clip === 'rect(0px, 0px, 0px, 0px)'
    || /inset\(\s*50%/.test(cs.clipPath || '')
    || (cs.clipPath || '') === 'circle(0px)';

  const signature = (el) => {
    const cls = (el.className || '').toString().trim().replace(/\s+/g, '.');
    return el.tagName.toLowerCase() + (cls ? '.' + cls.slice(0, 70) : '');
  };

  const visible = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return false;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none' || parseFloat(cs.opacity) <= 0.05) return false;
    if (clippedAway(cs)) return false;
    if (el.closest('.visually-hidden, .sr-only, .ss-visually-hidden')) return false;
    return true;
  };

  const findings = [];
  let debugbarSkipped = 0;
  let scanned = 0;

  for (const el of document.querySelectorAll('*')) {
    /* THE PHP DEBUGBAR IS NOT THE PRODUCT. See note 3 in this file's header. */
    if (el.closest('.phpdebugbar, #phpdebugbar')) { debugbarSkipped++; continue; }

    const ownText = [...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim());
    if (!ownText || !visible(el)) continue;

    const cs = getComputedStyle(el);
    const fg = parse(cs.color).slice(0, 3);
    if (fg.length !== 3) continue;

    scanned++;
    const bg = paintedBg(el);
    const size = parseFloat(cs.fontSize);
    const floor = (size >= 24 || (parseInt(cs.fontWeight, 10) >= 700 && size >= 18.66)) ? 3 : 4.5;
    const n = ratio(fg, bg);
    if (n < floor) {
      findings.push({
        fg: `rgb(${fg.join(',')})`, bg: `rgb(${bg.join(',')})`,
        ratio: Number(n.toFixed(2)), floor,
        sig: signature(el),
        sample: el.textContent.trim().replace(/\s+/g, ' ').slice(0, 44),
      });
    }
  }

  const body = getComputedStyle(document.body);
  /* GAP-113. THE `h1` IS PART OF THE PREMISE, not of the report. Four
     `_theme_customize__*.png` already exist in
     docs/development/phase-2.5/evidence/web-blocked-2026-11-10/after/ and every one of them is a
     picture of the DASHBOARD: the row carries `h1` "Dashboard" against a request for
     /theme-customize. A landed-URL check alone does not catch that when the refusal is
     `redirect()->back()` and the previous page IS the one asked for, so the heading is read too. */
  const h1 = document.querySelector('h1, .h1, h2.h3');
  return {
    premise: {
      theme: document.documentElement.getAttribute('data-ss-theme'),
      bodyBg: body.backgroundColor,
      dir: document.documentElement.getAttribute('dir') || document.body.getAttribute('dir'),
      lang: document.documentElement.getAttribute('lang'),
      h1: h1 ? h1.textContent.trim().replace(/\s+/g, ' ').slice(0, 60) : null,
    },
    scanned,
    debugbarSkipped,
    findings,
  };
};

(async () => {
  if (!fs.existsSync(phpEnv.DB_DATABASE)) {
    console.error(`fixture missing: ${phpEnv.DB_DATABASE}`);
    process.exit(2);
  }
  if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });

  const browser = await chromium.launch();
  let premiseFailures = 0;
  let totalFindings = 0;
  const groups = new Map();

  for (const combo of COMBOS) {
    console.log(`\n=== ${combo.name}  (theme=${combo.theme} dir=${combo.dir} lang=${combo.lang}) ===`);
    console.log('    state: ' + setState(combo));

    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 });
    const page = await context.newPage();

    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', MERCHANT);
    await page.fill('input[name="password"]', MERCHANT_PASSWORD);
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.click('button[type="submit"], input[type="submit"]'),
    ]);

    for (const surface of SURFACES) {
      const want = `${BASE}${surface.uri}`;
      let res;
      try {
        res = await page.goto(want, { waitUntil: 'domcontentloaded' });
      } catch (e) {
        console.log(`  ${surface.uri.padEnd(36)} NAVIGATION FAILED: ${e.message}`);
        premiseFailures++;
        continue;
      }
      await page.waitForTimeout(260);

      const landed = page.url().split('?')[0].replace(/\/$/, '');
      const asked = want.replace(/\/$/, '');
      const status = res ? res.status() : 0;

      if (landed !== asked) {
        console.log(`  ${surface.uri.padEnd(36)} PREMISE FAIL: asked ${asked}, landed ${landed} (HTTP ${status}) `
          + '-- a gate answered redirect()->route(dashboard) and this run would have measured that page');
        premiseFailures++;
        continue;
      }

      const out = await page.evaluate(SCAN);

      const wantTheme = combo.theme === 'dark';
      const themeOk = wantTheme
        ? (out.premise.theme === 'dark' && out.premise.bodyBg === DARK_BODY_BG)
        : (out.premise.theme !== 'dark' && out.premise.bodyBg !== DARK_BODY_BG);
      const dirOk = (out.premise.dir || 'ltr') === combo.dir;

      if (!themeOk || !dirOk) {
        console.log(`  ${surface.uri.padEnd(36)} PREMISE FAIL: `
          + `data-ss-theme=[${out.premise.theme}] bodyBg=${out.premise.bodyBg} dir=[${out.premise.dir}] `
          + `lang=[${out.premise.lang}] -- asked for ${combo.theme}/${combo.dir}`);
        premiseFailures++;
        continue;
      }

      const idx = SURFACES.indexOf(surface);
      const wantH1 = EXPECT_H1[idx] && EXPECT_H1[idx] !== '-' ? EXPECT_H1[idx] : null;
      const h1 = out.premise.h1;

      if (h1 === null) {
        console.log(`  ${surface.uri.padEnd(36)} PREMISE FAIL: the page has no heading at all, so `
          + 'this capture cannot be shown to be of the screen it names');
        premiseFailures++;
        continue;
      }
      if (wantH1 && !h1.includes(wantH1)) {
        console.log(`  ${surface.uri.padEnd(36)} PREMISE FAIL: h1 is "${h1}", expected to contain `
          + `"${wantH1}"`);
        premiseFailures++;
        continue;
      }
      if (FORBID_H1.some(bad => h1.includes(bad))) {
        console.log(`  ${surface.uri.padEnd(36)} PREMISE FAIL: h1 is "${h1}" -- this is the screen `
          + 'the refusal lands on, not the one requested. See GAP-113.');
        premiseFailures++;
        continue;
      }

      console.log(`  ${surface.uri.padEnd(36)} theme=${combo.theme} dir=${combo.dir} `
        + `bg=${out.premise.bodyBg} h1="${h1}" | text nodes scanned=${out.scanned} `
        + `| fail=${out.findings.length}`
        + (out.debugbarSkipped ? ` | debugbar elements excluded=${out.debugbarSkipped}` : ''));

      for (const f of out.findings) {
        console.log(`        ${f.ratio}:1 (needs ${f.floor})  ${f.fg} on ${f.bg}  ${f.sig}  "${f.sample}"`);
        const key = `${combo.theme}|${f.fg}|${f.bg}|${f.sig}`;
        groups.set(key, (groups.get(key) || 0) + 1);
      }
      totalFindings += out.findings.length;

      if (SHOTS) {
        const name = surface.uri.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '');
        await page.screenshot({ path: path.join(SHOTS, `${name}.${combo.name}.png`), fullPage: true });
      }
    }

    await context.close();
  }

  await browser.close();

  console.log('\n================ GROUPED ================');
  for (const [key, n] of [...groups.entries()].sort((a, b) => b[1] - a[1])) {
    const [theme, fg, bg, sig] = key.split('|');
    console.log(`  ${theme.toUpperCase().padEnd(6)} ${fg} on ${bg}  x${n}  ${sig}`);
  }

  console.log('\n================ RESULT ================');
  console.log(`surfaces                ${SURFACES.length} (${SURFACES.map(s => s.uri).join(', ')})`);
  console.log(`combinations            ${COMBOS.length} (${COMBOS.map(c => c.name).join(', ')})`);
  console.log(`premise failures        ${premiseFailures}`);
  console.log(`contrast findings       ${totalFindings} in ${groups.size} groups`);
  console.log(premiseFailures === 0 && totalFindings === 0 ? 'PASS' : `FAIL (${premiseFailures} premise, ${totalFindings} contrast)`);
  process.exit(premiseFailures === 0 && totalFindings === 0 ? 0 : 1);
})();
