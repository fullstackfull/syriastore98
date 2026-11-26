/**
 * GAP-179 / P06-06 -- THE SUPER-ADMIN SURFACE, MEASURED IN THE FOUR REAL CONFIGURATIONS.
 * =================================================================================================
 *
 * Phase 2.5 established the bar for merchant surfaces and the instruments for proving it. This is
 * the same instrument pointed at the PLATFORM OPERATOR's screens, which Phase 2.5 was explicitly
 * scoped away from ("Premium Merchant Experience ... premium UI/UX across merchant web and
 * Flutter"). It reuses Phase 2.5's two foundations rather than inventing a second standard:
 *
 *   the state tool        docs/development/phase-2/tools/set-admin-preview-state.php
 *   the contrast walk     the compositing + WCAG algorithm from
 *                         docs/development/phase-2.5/tools/census-low-contrast-text.cjs
 *
 * WHAT IT ADDS, and each addition is here because the thing it guards has really gone wrong:
 *
 *  1. IT SIGNS IN AS THE OPERATOR, NOT THE MERCHANT. Every Phase 2.5 live harness passes
 *     `merchant@example.test`. The operator's chrome is a different code path --
 *     `layouts/app.blade.php:2-7` takes `getAdminAllSetting()` for type `admin` and
 *     `getSuperAdminAllSetting()` for everyone else -- so a merchant run says nothing about it.
 *
 *  2. IT ASSERTS THE DARK PREMISE PER PAGE AND REFUSES TO MEASURE WITHOUT IT.
 *     THE ADMIN THEME IS A SETTING, NOT `prefers-color-scheme`. It is the `cust_darklayout`
 *     setting choosing `style-dark.css`, so Playwright's `colorScheme` emulation changes nothing
 *     and produces two identical "light" captures -- already recorded as a failure mode in this
 *     repository, twice, in two directions. A premise that is merely hoped for is how four
 *     byte-identical light/dark pairs were once filed as evidence. Here a dark pass must observe
 *     `data-ss-theme="dark"`, the dark host sheet in `#main-style-link`, AND a body background of
 *     exactly rgb(34,36,44); a light pass must observe none of those. A mismatch is a FAILURE with
 *     its own line, never a skip.
 *
 *  3. IT CATCHES `redirect()->back()`. A screen the principal cannot open answers HTTP 200 with a
 *     fully rendered page -- the PREVIOUS screen. Measured on this fixture: six operator screens
 *     in a row each photographed their predecessor while reporting 200. So the final URL is
 *     compared with the requested one and a mismatch is a failure, not a pass.
 *
 *  4. IT REPORTS DISTINCT CAPTURES. Two combinations that render byte-identically are evidence of
 *     a broken harness, not of a page that looks the same -- the direction at least must differ.
 *     Each capture's SHA-256 is printed so a reader can see the four differ.
 *
 * NEITHER THE THRESHOLD NOR THE SURFACE LIST MAY BE RELAXED TO MAKE A RUN PASS. The floors are
 * WCAG 2.1 AA: 4.5:1 for body text, 3:1 for large text (>=24px, or >=18.66px at weight >=700).
 *
 * PRECONDITIONS, all of which fail loudly rather than silently:
 *   - a dev server on 127.0.0.1:8899 serving database/p2dev.sqlite with CACHE_STORE=file
 *     (docs/development/phase-2.5/tools/serve-dev.sh, or the same four variables by hand)
 *   - `operator@example.test` present, type `super admin` (database/seed-p2dev.php)
 *   - that operator holding production's `super admin` role
 *     (tests/Support/bin/grant-dev-operator-role.php -- WITHOUT IT MOST SCREENS ANSWER
 *     `redirect()->back()` AND THE RUN MEASURES THE PREVIOUS PAGE)
 *
 * usage:
 *   node tests/Support/bin/measure-superadmin-premium-live.cjs \
 *        [--out <dir>] [--combos light-en,dark-en,light-ar,dark-ar] [--surfaces /a,/b] [--shots]
 *
 * exit 0 only when every premise held and no contrast floor was breached.
 */
'use strict';

const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const REPO = path.resolve(__dirname, '../../..');
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');
const OPERATOR = 'operator@example.test';
const OPERATOR_PASSWORD = 'password';

const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const has = (k) => argv.includes('--' + k);

const BASE = arg('base', 'http://127.0.0.1:8899');

/*
 * `--skip-state` EXISTS ONLY TO PROVE THE PREMISE ASSERTION BITES, and it is the one flag that
 * makes this harness lie on purpose. With it the theme/direction state is NOT written, so the
 * run still CLAIMS the combination it was asked for while the server serves whatever it was
 * already serving. Every premise must then fail.
 *
 * Without such a switch the premise assertion is unfalsifiable from the outside: a harness whose
 * dark pass silently measured the light theme is exactly the failure already recorded twice in
 * this repository, and "I added an assertion" is not evidence that the assertion can fail.
 *
 *   php docs/development/phase-2/tools/set-admin-preview-state.php 1 light ltr en operator@example.test
 *   node tests/Support/bin/measure-superadmin-premium-live.cjs --combos dark-en --skip-state
 *     -> expect: premise failures == the number of surfaces, contrast findings == 0 (nothing is
 *        measured, because a failed premise refuses to measure)
 */
const SKIP_STATE = has('skip-state');
const OUT = arg('out', null);
const SHOTS = has('shots') || !!OUT;

/*
 * THE SURFACE LIST IS DERIVED FROM THE ROUTE TABLE, NOT CHOSEN BY EYE, and it is split because
 * the two tiers are different kinds of thing and carry different ownership.
 *
 * TIER A -- OPERATOR-EXCLUSIVE. Every one carries `App\Http\Middleware\PlatformOperatorOnly`, so
 * no merchant can reach it and Phase 2.5 cannot have covered it. Taken from
 * `php artisan route:list --json`, filtered on that middleware, GET only, page-rendering only
 * (the export and action endpoints are excluded: there is nothing to look at).
 *
 * TIER B -- SHARED CHROME, OPERATOR CONTENT. Reached through the same `layouts.app` as a
 * merchant's screens, but what they SHOW the operator is the platform's own data: every tenant's
 * stores, the subscription plans, the platform's own settings. Identified by the views that branch
 * on `type == 'super admin'` plus the screens production's `super admin` role is granted.
 */
const TIER_A = [
  ['/platform/revenue', 'resources/views/superadmin/platform_revenue.blade.php'],
  ['/platform/resources', 'resources/views/superadmin/platform_resources.blade.php'],
  ['/platform/analytics', 'resources/views/platform/analytics.blade.php'],
  ['/platform/documentation', 'resources/views/platform/docs/'],
  ['/platform/site-content', 'resources/views/platform/content/index.blade.php'],
  ['/platform/site-content/home', 'resources/views/platform/content/edit.blade.php'],
  ['/plan-coupon', 'resources/views/plan-coupon/index.blade.php'],
  /* GAP-179 slice 3. `plan-coupon/show.blade.php` is a `PlatformOperatorOnly` GET that renders a
     page, so it belongs on this list on the same grounds as every other Tier A entry, and it was
     never on it -- it needs a `plan_coupons` row to resolve, and the fixture had none, which is
     how a reachable surface reads as absent. `plan_coupons.id = 1` is the fixture's only coupon. */
  ['/plan-coupon/1', 'resources/views/plan-coupon/show.blade.php'],
  ['/super-admin/lynomia-integration', 'packages/workdo/LynomiaChat (first-party)'],
];

const TIER_B = [
  ['/dashboard', 'resources/views/superadmin/dashboard.blade.php'],
  ['/plan', 'resources/views/plans/index.blade.php'],
  ['/plan/create', 'resources/views/plans/create.blade.php'],
  /* GAP-179 slice 2. `plans/edit.blade.php` was NOT in slice 1's list and that was an omission,
     not a judgement: comment-stripped, it carries the SAME five offending elements as
     `plans/create.blade.php` (four `.text-danger` notes and one `.input-group-text`) and differs
     from it only in indentation and quote style. Fixing one of a byte-similar pair and measuring
     only that one is how a defect survives its own repair, so the edit form is a surface now.
     The id is the dev fixture's only plan (`plans.id = 1`, "P2 Dev Plan"). */
  ['/plan/1/edit', 'resources/views/plans/edit.blade.php'],
  ['/plan-request', 'resources/views/plan_request/index.blade.php'],
  ['/stores', 'resources/views/store/index.blade.php'],
  ['/stores/create', 'resources/views/store/create.blade.php'],
  ['/users', 'resources/views/users/index.blade.php'],
  ['/setting', 'resources/views/setting/index.blade.php'],
  ['/countries', 'resources/views/country/index.blade.php'],
  ['/currency', 'resources/views/currency/index.blade.php'],
  ['/modules/list', 'resources/views/module/index.blade.php'],
  ['/modules/add', 'resources/views/module/add.blade.php'],
  ['/profile', 'resources/views/users/profile.blade.php'],
];

/*
 * TIER C -- MODAL FRAGMENTS, AND THEY CANNOT BE MEASURED BY NAVIGATING TO THEM.
 *
 * MEASURED: a direct GET of `/users/create`, `/plan-coupon/create` or `/stores/user/create`
 * answers HTTP 200 with a form and NO DOCUMENT CHROME AT ALL -- `data-ss-theme` null, body
 * background `rgba(0,0,0,0)`, `#main-style-link` absent, `lang` and `dir` null. They are
 * `@extends`-less partials that `data-ajax-popup="true"` loads into a Bootstrap modal, so a
 * standalone navigation renders them against the browser's defaults: black on white, in both
 * "themes", in both directions.
 *
 * ON THE FIRST BASELINE RUN THAT PRODUCED TWELVE PREMISE FAILURES -- three surfaces x four
 * combinations -- AND THAT IS THE CORRECT OUTCOME FOR A NAVIGATION. It is the wrong QUESTION.
 * The fragment's real contrast is whatever it measures inside the dialog on its parent screen,
 * so it is opened there: click the trigger, wait for the dialog, scan. The parent screen supplies
 * the theme, so the premise is asserted on the parent before the dialog opens.
 */
const TIER_C = [
  ['/users#create', 'resources/views/users/create.blade.php', '/users', 'a[data-ajax-popup="true"][data-url*="users/create"]'],
  ['/plan-coupon#create', 'resources/views/plan-coupon/create.blade.php', '/plan-coupon', '[data-url*="plan-coupon/create"]'],
  /* GAP-179 slice 3, AND IT IS THE SLICE-2 FINDING A THIRD TIME.
     `plan-coupon/edit.blade.php` is `create.blade.php` with `Form::model` instead of `Form::open`
     and `Update` instead of `Create`. Comment-stripped, the two differ in six lines. It was NOT a
     surface, for two compounding reasons: the Tier C list was written from the three
     `data-ajax-popup` triggers that are visible on an EMPTY grid, and the edit trigger lives in
     `plan-coupon/action.blade.php`, which only renders once a coupon ROW exists -- and
     `seed-p2dev.php` created none. So the screen was unmeasurable, not clean, and it carried BOTH
     the 2.58:1 `btn-badge btn-primary` that slice 1 existed to close and the 3.07:1
     `btn-outline-primary` that slice 1 recorded on its twin. Adding it RAISED the measured
     baseline before anything was repaired, which is the only honest way for it to appear. */
  ['/plan-coupon#edit', 'resources/views/plan-coupon/edit.blade.php', '/plan-coupon', '[data-url*="plan-coupon/1/edit"]'],
  ['/stores#user-create', 'resources/views/store/user-create.blade.php', '/stores', '[data-url*="stores/user/create"]'],
];

const ALL = [...TIER_A.map(([u, v]) => ({ uri: u, view: v, tier: 'A' })),
             ...TIER_B.map(([u, v]) => ({ uri: u, view: v, tier: 'B' })),
             ...TIER_C.map(([u, v, parent, trigger]) => ({ uri: u, view: v, tier: 'C', parent, trigger }))];

const surfaceFilter = arg('surfaces', null);
const SURFACES = surfaceFilter
  ? ALL.filter(s => surfaceFilter.split(',').map(x => x.trim()).includes(s.uri))
  : ALL;

const COMBOS = arg('combos', 'light-en,dark-en,light-ar,dark-ar')
  .split(',').map(s => s.trim()).filter(Boolean)
  .map(name => {
    const [theme, lang] = name.split('-');
    return { name, theme, lang, dir: lang === 'ar' || lang === 'he' ? 'rtl' : 'ltr' };
  });

/* MEASURED, not chosen: the dark host sheet sets this on <body> and every operator screen that
   really renders dark reports it. Asserting it is what separates a dark capture from a light one
   wearing a dark label. */
const DARK_BODY_BG = 'rgb(34, 36, 44)';

const phpEnv = {
  ...process.env,
  APP_ENV: 'local',
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: path.join(REPO, 'database/p2dev.sqlite'),
  CACHE_STORE: 'file',
};

function setState(combo) {
  if (SKIP_STATE) {
    return '(--skip-state: NOTHING WAS WRITTEN; this run is deliberately mislabelled)';
  }

  return execFileSync('php', [STATE_TOOL, '1', combo.theme, combo.dir, combo.lang, OPERATOR],
    { env: phpEnv, encoding: 'utf8' }).trim();
}

/* ------------------------------------------------------------------------------------------------
 * The in-page walk. The compositing and WCAG parts are Phase 2.5's
 * (docs/development/phase-2.5/tools/census-low-contrast-text.cjs), reused verbatim in substance so
 * that a number measured here is the same KIND of number as one measured there. What is added is
 * the control census: the defect this slice exists for is a BUTTON, and a button whose label is an
 * icon carries no text node at all, so a text-node walk alone cannot see it.
 * ---------------------------------------------------------------------------------------------- */
const SCAN = (rootSelector) => {
  /* A Tier C fragment is scanned INSIDE ITS DIALOG, not across the whole document: the parent
     screen is still behind the backdrop, and scanning it too would file the parent's findings
     against the fragment and count every one of them twice. */
  const root = rootSelector ? document.querySelector(rootSelector) : document;
  if (!root) return { premise: { missingRoot: rootSelector }, findings: [], controls: [], counts: {} };
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

  /* Clipped-away text is not on the screen, and a size check alone does not find it: a container
     can override the 1px while `clip: rect(0,0,0,0)` still paints nothing. Phase 2.5's note. */
  const clippedAway = cs => cs.clip === 'rect(0px, 0px, 0px, 0px)'
    || /inset\(\s*50%/.test(cs.clipPath || '')
    || (cs.clipPath || '') === 'circle(0px)';

  const signature = (el) => {
    const cls = (el.className || '').toString().trim().replace(/\s+/g, '.');
    return el.tagName.toLowerCase() + (cls ? '.' + cls.slice(0, 60) : '');
  };

  const visible = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return false;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none' || parseFloat(cs.opacity) <= 0.05) return false;
    if (clippedAway(cs)) return false;
    if (el.closest('.visually-hidden, .sr-only, .ss-visually-hidden')) return false;

    /* THE DEBUGBAR IS NOT IN THE PRODUCT, AND IT MEASURES AS A FINDING.
       CLAUDE.md records it: with APP_DEBUG on, a contrast harness reads
       `span.phpdebugbar-badge` at 3.89:1 and files FOUR findings per theme that no operator can
       see -- eight per surface across the two themes. The control is APP_DEBUG=false, and every
       run recorded for this slice used it (`.env` APP_DEBUG=false plus DEBUGBAR_ENABLED=false,
       and `grep -c phpdebugbar` over a served page is 0). This exclusion is the SECOND line of
       defence, so that a run taken on a debug-on instance cannot quietly inflate the inventory.
       BY ANCESTOR, not by the element's own class: the badge is a descendant of the bar, and the
       bar's own panels carry neither class. Proved a no-op on this instance by re-running the
       complete 25-surface baseline with and without it: 24 findings in 2 groups, both times. */
    if (el.closest('.phpdebugbar, #phpdebugbar, .phpdebugbar-openhandler')) return false;

    return true;
  };

  const findings = [];
  const controls = [];
  const icons = [];

  for (const el of root.querySelectorAll('*')) {
    const cs = getComputedStyle(el);

    /* A BUTTON WITH A BARE TEXT LABEL QUALIFIES FOR BOTH WALKS, AND COUNTING IT TWICE INFLATES
       EVERY TOTAL BELOW IT. `<a class="btn btn-primary">Add Coupon</a>` has its own text node, so
       the text walk takes it, and it is interactive with a label, so the control walk takes it
       again -- the same pixel reported as two findings. The control walk is the better reading of
       a control (it is the one that survives an icon-only label), so the text walk yields it. */
    const isControlElement = (/^(button|a|input)$/i.test(el.tagName) || el.getAttribute('role') === 'button')
      && /(^|\s)(btn|ss-btn)(\s|-|$)/.test((el.className || '').toString());

    /* --- the text walk --- */
    const ownText = [...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim());
    if (ownText && !isControlElement && visible(el)) {
      const fg = parse(cs.color).slice(0, 3);
      if (fg.length === 3) {
        const bg = paintedBg(el);
        const size = parseFloat(cs.fontSize);
        const floor = (size >= 24 || (parseInt(cs.fontWeight, 10) >= 700 && size >= 18.66)) ? 3 : 4.5;
        const n = ratio(fg, bg);
        if (n < floor) {
          findings.push({
            kind: 'text',
            fg: `rgb(${fg.join(',')})`, bg: `rgb(${bg.join(',')})`,
            ratio: Number(n.toFixed(2)), floor,
            sig: signature(el),
            sample: el.textContent.trim().replace(/\s+/g, ' ').slice(0, 40),
          });
        }
      }
    }

    /* --- the control census ---
       A filled control's LABEL contrast is what the operator actually reads, and the text walk
       above can miss it: a `<button>` whose words live in a child element has no text node of its
       own, so the walk steps over the control and measures the child against the child's own
       background instead of against the fill.

       THE FILTER IS DELIBERATELY NARROW, AND IT WAS WIDER ON THE FIRST RUN. Matching anything
       whose class contains `btn` reported 141 "failing controls" across six surfaces in one
       combination -- and every one of them was either a CONTAINER (`div.btn-group.card-option`,
       `div.dataTable-botton`, the stores screen's `all-button-box` wrapper: `\bbtn\b` matches
       inside `btn-group` because the hyphen is a word boundary) or an ICON-ONLY control
       (`button.btn.p-0`), whose inherited `color` paints nothing because the glyph is a child
       with its own colour. Neither is a text-contrast finding. An icon's legibility is a
       different requirement (3:1, non-text) and is not what this slice measures, so counting it
       here would have inflated the inventory it is supposed to establish.

       So a control is: a real interactive ELEMENT (button / a / input / role=button), carrying a
       button class, WITH its own rendered text. `value` counts, because that is where an
       `<input type=submit>` keeps its label. */
    if (isControlElement && visible(el)) {
      const label = (
        el.tagName.toLowerCase() === 'input'
          ? (el.value || '')
          : (el.innerText || '')
      ).trim().replace(/\s+/g, ' ');

      const fg = parse(cs.color).slice(0, 3);

      if (label && fg.length === 3) {
        const bg = paintedBg(el);
        const size = parseFloat(cs.fontSize);
        const floor = (size >= 24 || (parseInt(cs.fontWeight, 10) >= 700 && size >= 18.66)) ? 3 : 4.5;
        const n = ratio(fg, bg);
        controls.push({
          sig: signature(el),
          fg: `rgb(${fg.join(',')})`, bg: `rgb(${bg.join(',')})`,
          ratio: Number(n.toFixed(2)), floor,
          label: label.slice(0, 40),
          pass: n >= floor,
        });
      }

      /* --- ICON-ONLY CONTROLS, AND THEY ARE THE COMMONEST SHAPE ON THIS SURFACE ---
         A control whose whole content is `<i class="ti ti-plus">` has no text node and no
         innerText, so BOTH walks above step over it -- and on the operator's screens that is
         what most primary actions are: Add Coupon, Add Currency, Sub Domain, List View, Add New
         User are all a glyph in a filled pill. The glyph inherits the button's `color`, so it is
         painted in exactly the ink the two walks above would have measured, against exactly the
         same fill. Omitting it would have let the named defect hide on the screens where it is
         most visible.
         THE FLOOR IS 3:1, NOT 4.5:1 -- WCAG 2.1 1.4.11 non-text contrast, which is the right
         requirement for a glyph and is a WEAKER test than the one applied to labels. It is not a
         relaxation chosen to make a number pass: 2.58:1 fails it too. */
      if (!label && fg.length === 3) {
        const glyph = el.querySelector('i[class], svg, span[class*="icon"]');
        if (glyph) {
          const gcs = getComputedStyle(glyph);
          const gfg = parse(gcs.color).slice(0, 3);
          const use = gfg.length === 3 ? gfg : fg;
          const bg = paintedBg(el);
          const n = ratio(use, bg);
          icons.push({
            sig: signature(el),
            fg: `rgb(${use.join(',')})`, bg: `rgb(${bg.join(',')})`,
            ratio: Number(n.toFixed(2)), floor: 3,
            label: (el.getAttribute('aria-label') || el.getAttribute('title')
              || el.getAttribute('data-bs-original-title') || '(unnamed)').trim().slice(0, 40),
            pass: n >= 3,
          });
        }
      }
    }
  }

  const cs = getComputedStyle(document.body);
  const mainStyle = document.getElementById('main-style-link');

  return {
    premise: {
      dataSsTheme: document.documentElement.getAttribute('data-ss-theme'),
      htmlDir: document.documentElement.getAttribute('dir'),
      htmlLang: document.documentElement.getAttribute('lang'),
      bodyBg: cs.backgroundColor,
      mainStyleHref: mainStyle ? (mainStyle.getAttribute('href') || '') : null,
    },
    findings,
    controls,
    icons,
    counts: {
      btnPrimary: root.querySelectorAll('.btn-primary').length,
      ssBtnPrimary: root.querySelectorAll('.ss-btn--primary').length,
      ssBtn: root.querySelectorAll('.ss-btn').length,
    },
  };
};

/* ---------------------------------------------------------------------------------------------- */

(async () => {
  if (OUT) fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const rows = [];
  const premiseFailures = [];
  const reachFailures = [];
  const shots = [];
  const offOriginTallies = [];

  for (const combo of COMBOS) {
    const written = setState(combo);
    console.log(`\n=== ${combo.name}  (theme=${combo.theme} dir=${combo.dir} lang=${combo.lang}) ===`);
    console.log(`    state: ${written}`);

    /* A FRESH CONTEXT PER COMBINATION. `users.language` drives the admin layout's direction, and a
       session that logged in before the write keeps the old chrome until something reloads it;
       logging in again after the write removes that whole class of doubt. */
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });

    /* NO EGRESS. Everything this harness measures is served by the dev instance; a request to any
       other host is either a font/CDN reference that would make the run depend on the network, or
       a telemetry call. Both are refused here rather than relied on to fail.
       MEASURED on this instance before this block existed: zero off-origin requests on all 25
       surfaces, which is why adding it is a no-op on the numbers -- `blockedOffOrigin` below
       prints the count so a future reader can see whether that is still true rather than assume
       it. If it ever becomes non-zero, the aborted resource may change font metrics and therefore
       the large-text floor, and the baseline must be retaken. */
    const blockedOffOrigin = new Map();
    const baseOrigin = new URL(BASE).origin;
    await ctx.route('**/*', (route) => {
      let origin = null;
      try { origin = new URL(route.request().url()).origin; } catch (e) { origin = null; }
      if (origin && origin !== baseOrigin) {
        blockedOffOrigin.set(origin, (blockedOffOrigin.get(origin) || 0) + 1);
        return route.abort();
      }
      return route.continue();
    });
    offOriginTallies.push([combo.name, blockedOffOrigin]);

    const page = await ctx.newPage();
    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', OPERATOR);
    await page.fill('input[name="password"]', OPERATOR_PASSWORD);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null),
      page.click('button[type="submit"]'),
    ]);

    for (const surface of SURFACES) {
      const want = surface.uri;
      const navTo = surface.tier === 'C' ? surface.parent : want;
      let resp = null;
      try {
        resp = await page.goto(BASE + navTo, { waitUntil: 'domcontentloaded', timeout: 30000 });
      } catch (e) {
        reachFailures.push(`${combo.name} ${want}: navigation threw -- ${e.message.slice(0, 80)}`);
        continue;
      }
      const status = resp ? resp.status() : 0;
      const finalPath = new URL(page.url()).pathname;

      if (status !== 200) {
        reachFailures.push(`${combo.name} ${want}: HTTP ${status}`);
        console.log(`  ${want.padEnd(36)} HTTP ${status}  -- NOT MEASURED`);
        continue;
      }
      if (finalPath !== navTo) {
        /* redirect()->back(): 200, a whole page, and it is the PREVIOUS screen. */
        reachFailures.push(`${combo.name} ${want}: HTTP 200 but final URL ${finalPath} `
          + `-- redirect()->back(); the page measured is not the page requested`);
        console.log(`  ${want.padEnd(36)} 200 -> ${finalPath}  -- NOT MEASURED (back())`);
        continue;
      }

      await page.waitForTimeout(900);

      /* A Tier C fragment is opened where it really renders. The dialog must actually appear and
         actually carry the fragment's fields -- an empty `.modal-body` is the vacuous pass this
         repository has recorded four separate times, so its emptiness is a failure. */
      if (surface.tier === 'C') {
        const trigger = await page.$(surface.trigger);
        if (!trigger) {
          reachFailures.push(`${combo.name} ${want}: no trigger matching ${surface.trigger} on ${navTo}`);
          console.log(`  ${want.padEnd(36)} NO TRIGGER on ${navTo}  -- NOT MEASURED`);
          continue;
        }
        await trigger.click();
        const ok = await page.waitForFunction(() => {
          const m = document.querySelector('.modal.show .modal-body, .modal.show .modal-content');
          return !!m && (m.innerText || '').trim().length > 10 && m.querySelectorAll('input,select,textarea,button').length > 0;
        }, null, { timeout: 15000 }).then(() => true).catch(() => false);
        if (!ok) {
          reachFailures.push(`${combo.name} ${want}: dialog did not open with content from ${surface.trigger}`);
          console.log(`  ${want.padEnd(36)} DIALOG EMPTY/ABSENT  -- NOT MEASURED`);
          continue;
        }
        await page.waitForTimeout(500);
      }

      const r = await page.evaluate(SCAN, surface.tier === 'C' ? '.modal.show .modal-content' : null);

      /* --- the premise, asserted, never assumed --- */
      const wantDark = combo.theme === 'dark';
      const p = r.premise;
      const problems = [];
      if (wantDark) {
        if (p.dataSsTheme !== 'dark') problems.push(`data-ss-theme=${JSON.stringify(p.dataSsTheme)} expected "dark"`);
        if (p.bodyBg !== DARK_BODY_BG) problems.push(`body background ${p.bodyBg} expected ${DARK_BODY_BG}`);
        if (!/style-dark\.css/.test(p.mainStyleHref || '')) problems.push(`main stylesheet ${p.mainStyleHref} is not a dark sheet`);
      } else {
        if (p.dataSsTheme === 'dark') problems.push('data-ss-theme="dark" on a LIGHT pass');
        if (p.bodyBg === DARK_BODY_BG) problems.push(`body background ${p.bodyBg} is the DARK value on a LIGHT pass`);
        if (/style-dark\.css/.test(p.mainStyleHref || '')) problems.push(`main stylesheet ${p.mainStyleHref} is a dark sheet on a LIGHT pass`);
      }
      if ((p.htmlDir || 'ltr') !== combo.dir) problems.push(`dir=${JSON.stringify(p.htmlDir)} expected ${combo.dir}`);
      if (p.htmlLang !== combo.lang) problems.push(`lang=${JSON.stringify(p.htmlLang)} expected ${combo.lang}`);

      if (problems.length) {
        premiseFailures.push(`${combo.name} ${want}: ${problems.join('; ')}`);
        console.log(`  ${want.padEnd(36)} PREMISE FAILED -- ${problems.join('; ')}`);
        continue;
      }

      const failingControls = r.controls.filter(c => !c.pass);
      const failingIcons = (r.icons || []).filter(c => !c.pass);
      rows.push({ combo: combo.name, surface, premise: p, ...r, failingControls, failingIcons });

      console.log(`  ${want.padEnd(36)} theme=${p.dataSsTheme || 'light'} bg=${p.bodyBg} dir=${p.htmlDir} `
        + `| text-fail=${r.findings.length} control-fail=${failingControls.length}/${r.controls.length} `
        + `icon-fail=${failingIcons.length}/${(r.icons || []).length} `
        + `| .btn-primary=${r.counts.btnPrimary} .ss-btn--primary=${r.counts.ssBtnPrimary}`);

      for (const f of r.findings.slice(0, 6)) {
        console.log(`        TEXT  ${f.ratio}:1 (needs ${f.floor})  ${f.fg} on ${f.bg}  ${f.sig}  "${f.sample}"`);
      }
      if (r.findings.length > 6) console.log(`        ... and ${r.findings.length - 6} more text findings`);
      for (const c of failingControls.slice(0, 6)) {
        console.log(`        CTRL  ${c.ratio}:1 (needs ${c.floor})  ${c.fg} on ${c.bg}  ${c.sig}  "${c.label}"`);
      }
      if (failingControls.length > 6) console.log(`        ... and ${failingControls.length - 6} more control findings`);
      for (const c of failingIcons.slice(0, 6)) {
        console.log(`        ICON  ${c.ratio}:1 (needs ${c.floor})  ${c.fg} on ${c.bg}  ${c.sig}  "${c.label}"`);
      }
      if (failingIcons.length > 6) console.log(`        ... and ${failingIcons.length - 6} more icon findings`);

      if (SHOTS && OUT) {
        const safe = want.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '') || 'root';
        const file = path.join(OUT, `${safe}__${combo.name}.png`);
        await page.screenshot({ path: file, fullPage: true });
        const sha = crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex').slice(0, 16);
        shots.push({ surface: want, combo: combo.name, file, sha });
      }
    }

    await ctx.close();
  }

  /* leave the fixture light/en, as every Phase 2.5 harness does, so the next run starts level */
  setState({ theme: 'light', dir: 'ltr', lang: 'en' });
  await browser.close();

  /* ---------------- the census ---------------- */
  console.log('\n\n================ GROUPED CENSUS (colour, background, theme) ================');
  const census = new Map();
  for (const row of rows) {
    const theme = row.combo.startsWith('dark') ? 'dark' : 'light';
    for (const f of [...row.findings,
                     ...row.failingControls.map(c => ({ ...c, kind: 'control', sample: c.label })),
                     ...row.failingIcons.map(c => ({ ...c, kind: 'icon', sample: c.label }))]) {
      const key = `${theme}|${f.kind || 'text'}|${f.fg}|${f.bg}`;
      if (!census.has(key)) census.set(key, { theme, kind: f.kind || 'text', fg: f.fg, bg: f.bg, ratio: f.ratio, floor: f.floor, n: 0, sigs: new Set(), surfaces: new Set(), samples: new Set() });
      const c = census.get(key);
      c.n++; c.sigs.add(f.sig); c.surfaces.add(row.surface.uri);
      if (c.samples.size < 4 && f.sample) c.samples.add(f.sample);
    }
  }
  const groups = [...census.values()].sort((a, b) => b.n - a.n);
  for (const g of groups) {
    console.log(`\n${g.theme.toUpperCase().padEnd(5)} ${g.kind.toUpperCase().padEnd(7)} ${g.fg} on ${g.bg}  ${g.ratio}:1 (needs ${g.floor})  x${g.n} on ${g.surfaces.size} surfaces`);
    console.log(`      surfaces: ${[...g.surfaces].join(' ')}`);
    console.log(`      selectors: ${[...g.sigs].slice(0, 6).join(' | ')}`);
    if (g.samples.size) console.log(`      e.g.: ${[...g.samples].map(s => '"' + s + '"').join(' ')}`);
  }

  /* ---------------- per-surface summary ---------------- */
  console.log('\n\n================ PER-SURFACE SUMMARY ================');
  const bySurface = new Map();
  for (const row of rows) {
    if (!bySurface.has(row.surface.uri)) bySurface.set(row.surface.uri, { tier: row.surface.tier, view: row.surface.view, combos: {} });
    bySurface.get(row.surface.uri).combos[row.combo] = {
      text: row.findings.length, ctrl: row.failingControls.length, controls: row.controls.length,
      icon: row.failingIcons.length, icons: row.icons.length,
      btnPrimary: row.counts.btnPrimary, ssBtnPrimary: row.counts.ssBtnPrimary,
    };
  }
  const header = ['surface (t=text c=control-label i=icon)', ...COMBOS.map(c => c.name)];
  console.log(header.map((h, i) => i === 0 ? h.padEnd(36) : h.padEnd(15)).join(''));
  let grand = 0;
  for (const [uri, info] of bySurface) {
    const cells = COMBOS.map(c => {
      const v = info.combos[c.name];
      if (!v) return 'n/m'.padEnd(15);
      grand += v.text + v.ctrl + v.icon;
      return `${v.text}t/${v.ctrl}c/${v.icon}i`.padEnd(15);
    });
    console.log(`${(info.tier + ' ' + uri).padEnd(36)}${cells.join('')}`);
  }

  if (shots.length) {
    console.log('\n================ CAPTURES (sha256 prefix -- four must differ) ================');
    for (const s of shots) console.log(`  ${s.sha}  ${s.combo.padEnd(10)} ${s.surface}`);
    const byS = new Map();
    for (const s of shots) { if (!byS.has(s.surface)) byS.set(s.surface, new Set()); byS.get(s.surface).add(s.sha); }
    for (const [uri, set] of byS) {
      if (set.size < Math.min(COMBOS.length, shots.filter(s => s.surface === uri).length)) {
        console.log(`  !! ${uri}: only ${set.size} distinct captures -- identical renders are a harness fault, not a finding`);
      }
    }
  }

  console.log('\n================ RESULT ================');
  console.log(`surfaces requested      ${SURFACES.length}`);
  console.log(`combinations            ${COMBOS.length} (${COMBOS.map(c => c.name).join(', ')})`);
  console.log(`measured (surface x combo) ${rows.length} of ${SURFACES.length * COMBOS.length}`);
  console.log(`premise failures        ${premiseFailures.length}`);
  premiseFailures.forEach(f => console.log(`   ! ${f}`));
  console.log(`unreachable             ${reachFailures.length}`);
  reachFailures.forEach(f => console.log(`   ! ${f}`));
  console.log(`contrast findings       ${grand} in ${groups.length} groups`);

  const offOriginTotal = offOriginTallies.reduce((n, [, m]) => n + [...m.values()].reduce((a, b) => a + b, 0), 0);
  console.log(`off-origin requests aborted ${offOriginTotal}`);
  if (offOriginTotal) {
    for (const [combo, m] of offOriginTallies) {
      for (const [origin, n] of m) console.log(`   ! ${combo} ${origin} x${n} -- ABORTED; a measurement that depended on it is not safe`);
    }
  }

  const bad = premiseFailures.length + grand;
  console.log(bad === 0 ? '\nPASS' : `\nFAIL (${premiseFailures.length} premise, ${grand} contrast)`);
  process.exit(bad === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
