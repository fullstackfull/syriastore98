/**
 * NAV-004 — THE FOUR NEWLY-NAVIGABLE OPERATOR SCREENS AT REAL VIEWPORT WIDTHS.
 * =================================================================================================
 *
 * `measure-superadmin-premium-live.cjs` measures contrast at one width (1440x900). This measures
 * what that one cannot: whether the navigation that NAV-004 added can be REACHED at a phone width,
 * and whether the four screens fit the viewport they are given.
 *
 * WHY THESE TWO THINGS AND NOT A SCREENSHOT SWEEP.
 *
 *  1. A SIDEBAR ENTRY THAT CANNOT BE OPENED ON A PHONE IS NOT NAVIGATION. The merchant shell
 *     collapses `.dash-sidebar` below a breakpoint and reveals it with a toggle, so "the entry is
 *     in the rendered sidebar" — which `OperatorPlatformNavigationTest` asserts over the HTML — is
 *     a claim about the DOM and not about reachability. This opens the sidebar the way a person
 *     does, expands the group, and requires the four links to be really on screen: a box with
 *     width and height, inside the viewport, and `elementFromPoint` at its own centre landing on
 *     the link or a descendant of it. That last check is the one that catches a link under a
 *     backdrop, which is invisible to every geometric test.
 *
 *  2. HORIZONTAL OVERFLOW IS MEASURED ON THE DOCUMENT, not on the component. Phase 2.5 recorded
 *     the reason: a component can scroll correctly while an absolutely-positioned descendant
 *     escapes it and widens the page — 57 visually-hidden labels did exactly that on the order
 *     board, +295px. `documentElement.scrollWidth - clientWidth` is the figure a person feels as
 *     "the page slides sideways".
 *
 * A WIDE TABLE IS NOT A DEFECT IF IT IS WRAPPED. Three of these four screens are tables, and a
 * table that is wider than a phone is correct as long as the page does not slide: the wrapper
 * scrolls instead. So the overflow figure is reported for the DOCUMENT, and each table's own
 * `overflow-x` is reported beside it, because a wide table with `visible` overflow is the defect
 * and a wide table inside an `auto`/`scroll` wrapper is the fix.
 *
 * APP_DEBUG MUST BE OFF for the same reason the contrast harness says so: the debug bar is a fixed
 * element with its own width and it is not in the product. This script asserts it rather than
 * assuming it — a page containing `phpdebugbar` fails the run, because every width figure taken
 * with the bar present is a figure about a different page.
 *
 * NO EGRESS: every off-origin request is aborted and the count printed, exactly as the contrast
 * harness does, so a run cannot depend on a CDN being reachable.
 *
 * usage:
 *   node tests/Support/bin/measure-operator-nav-responsive.cjs \
 *        [--base http://127.0.0.1:8899] [--widths 390,768,1024,1440] [--out <dir>]
 *
 * Preconditions are the contrast harness's: a dev server on `--base` serving
 * `database/p2dev.sqlite` with `CACHE_STORE=file`, `operator@example.test` present and holding
 * production's `super admin` role (tests/Support/bin/grant-dev-operator-role.php).
 *
 * exit 0 only when every premise held, no page overflowed and every link was reachable.
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
const PASSWORD = 'password';

const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };

const BASE = arg('base', 'http://127.0.0.1:8899');
const OUT = arg('out', null);

/* REAL DEVICE WIDTHS, NOT BREAKPOINT VALUES. 390 is an iPhone 14/15 and the narrowest width the
   design system claims; 768 is the portrait tablet where the shell switches; 1024 is a landscape
   tablet and the narrowest common laptop; 1440 is the width every other harness here uses, so a
   figure taken at it is comparable with theirs. */
const WIDTHS = arg('widths', '390,768,1024,1440').split(',').map(n => parseInt(n.trim(), 10));

/*
 * The four screens NAV-004 made navigable.
 *
 * THE LINK IS FOUND BY ITS `href`, NOT BY ITS LABEL, and the first version of this harness did it
 * the other way and reported LINK ABSENT FROM SIDEBAR for all sixteen Arabic cells -- because the
 * label it was looking for was the English one. A label is the thing under test here; the href is
 * the thing that identifies the link. Looking a control up by the text whose correctness you are
 * measuring is a harness that cannot pass in one of the two languages it claims to cover.
 */
const SCREENS = ['/platform/verification', '/platform/approvals', '/platform/incidents',
  '/platform/operations'];

const COMBOS = [
  { name: 'light-en', theme: 'light', lang: 'en', dir: 'ltr' },
  { name: 'light-ar', theme: 'light', lang: 'ar', dir: 'rtl' },
  { name: 'dark-en', theme: 'dark', lang: 'en', dir: 'ltr' },
  { name: 'dark-ar', theme: 'dark', lang: 'ar', dir: 'rtl' },
];

const phpEnv = {
  ...process.env,
  APP_ENV: 'local',
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: path.join(REPO, 'database/p2dev.sqlite'),
  CACHE_STORE: 'file',
};

/* ------------------------------------------------------------------------------------------------
 * In-page measurements
 * ---------------------------------------------------------------------------------------------- */

const PAGE_FACTS = () => {
  const de = document.documentElement;

  /* A table that is wider than its wrapper is fine if the wrapper scrolls. Report both. */
  const tables = [...document.querySelectorAll('table')].map(t => {
    let scroller = null;

    for (let e = t.parentElement; e; e = e.parentElement) {
      const ox = getComputedStyle(e).overflowX;

      if (ox === 'auto' || ox === 'scroll') { scroller = { cls: (e.className || '').toString().slice(0, 40), overflowX: ox }; break; }
      if (e === document.body) break;
    }

    return {
      cls: (t.className || '').toString().slice(0, 40),
      width: Math.round(t.getBoundingClientRect().width),
      scroller,
    };
  });

  return {
    debugBar: document.querySelectorAll('.phpdebugbar, #phpdebugbar').length,
    overflow: de.scrollWidth - de.clientWidth,
    clientWidth: de.clientWidth,
    dir: de.getAttribute('dir'),
    lang: de.getAttribute('lang'),
    theme: de.getAttribute('data-ss-theme'),
    bodyBg: getComputedStyle(document.body).backgroundColor,
    tables,
  };
};

/* Is the sidebar's own link to `$uri` really on screen and really hittable? */
const LINK_REACHABLE = (uri) => {
  const links = [...document.querySelectorAll('.dash-sidebar a.dash-link')];
  const link = links.find(a => {
    const href = a.getAttribute('href') || '';
    try { return new URL(href, location.href).pathname === uri; } catch (e) { return false; }
  });

  if (!link) {
    return { found: false, hrefs: links.map(a => a.getAttribute('href')).slice(0, 40) };
  }

  const r = link.getBoundingClientRect();
  const cx = r.left + r.width / 2;
  const cy = r.top + r.height / 2;
  const hit = document.elementFromPoint(cx, cy);

  /* The group's own `<li>`, and whether the theme considers it open. `dash-trigger` is the class
     `public/assets/js/dash.js` adds and `li.dash-trigger > .dash-submenu` is what the stylesheet
     reveals, so this is the open state as the theme defines it rather than as a height implies. */
  const li = link.closest('li.dash-item');
  const group = li ? li.parentElement && li.parentElement.closest('li.dash-item') : null;

  return {
    found: true,
    label: (link.textContent || '').trim().slice(0, 40),
    box: [Math.round(r.width), Math.round(r.height)],
    inViewport: r.width > 1 && r.height > 1
      && r.top >= -1 && r.left >= -1
      && r.bottom <= window.innerHeight + 1 && r.right <= window.innerWidth + 1,
    /* THE CHECK A GEOMETRIC TEST CANNOT MAKE: what is actually painted at the link's centre. A
       collapsed submenu that clips rather than hides keeps its children's boxes, so a width and a
       height are not evidence that anything is on the screen. */
    hitIsTheLink: !!hit && (hit === link || link.contains(hit) || hit.contains(link)),
    hit: hit ? hit.tagName.toLowerCase() + ((hit.className || '').toString().trim()
      ? '.' + (hit.className || '').toString().trim().split(/\s+/).slice(0, 2).join('.') : '') : null,
    groupOpen: !!group && group.classList.contains('dash-trigger'),
    href: link.getAttribute('href'),
  };
};

/* Click the group header that owns the link to `$uri`, the way a person does. */
const OPEN_GROUP = (uri) => {
  const link = [...document.querySelectorAll('.dash-sidebar a.dash-link')].find(a => {
    const href = a.getAttribute('href') || '';
    try { return new URL(href, location.href).pathname === uri; } catch (e) { return false; }
  });

  if (!link) return false;

  const li = link.closest('li.dash-item');
  const group = li ? li.parentElement && li.parentElement.closest('li.dash-item') : null;
  const header = group ? group.querySelector(':scope > a.dash-link') : null;

  if (!header) return false;

  header.click();

  return true;
};

/* ------------------------------------------------------------------------------------------------ */

(async () => {
  if (OUT) fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const failures = [];
  const premiseFailures = [];
  const rows = [];
  const shots = [];
  let offOrigin = 0;

  for (const combo of COMBOS) {
    const state = execFileSync('php', [STATE_TOOL, '1', combo.theme, combo.dir, combo.lang, OPERATOR],
      { env: phpEnv, encoding: 'utf8' }).trim();

    console.log(`\n=== ${combo.name} ===\n    state: ${state}`);

    for (const width of WIDTHS) {
      /*
       * A FRESH CONTEXT PER WIDTH, AND IT IS NOT TIDINESS. The shell decides whether the sidebar is
       * collapsed from the width AT LOAD, in an inline script, and a `setViewportSize` on a loaded
       * page does not re-run it -- so a resized page is a desktop shell wearing a phone's
       * dimensions. Measured: the toggle does not appear until the page is loaded at the width.
       *
       * `deviceScaleFactor: 2` ONLY FOR THE CAPTURES, and in its own context further down: the
       * contrast harness measures at 1 and a figure taken at a different scale is not comparable
       * with its baseline.
       */
      const ctx = await browser.newContext({ viewport: { width, height: 900 } });
      const baseOrigin = new URL(BASE).origin;

      await ctx.route('**/*', (route) => {
        let origin = null;
        try { origin = new URL(route.request().url()).origin; } catch (e) { origin = null; }
        if (origin && origin !== baseOrigin) { offOrigin++; return route.abort(); }
        return route.continue();
      });

      const page = await ctx.newPage();
      await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
      await page.fill('input[name="email"]', OPERATOR);
      await page.fill('input[name="password"]', PASSWORD);
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null),
        page.click('button[type="submit"]'),
      ]);

      for (const uri of SCREENS) {
        const resp = await page.goto(BASE + uri, { waitUntil: 'domcontentloaded' }).catch(() => null);
        const status = resp ? resp.status() : 0;
        const landed = new URL(page.url()).pathname;

        if (status !== 200 || landed !== uri) {
          premiseFailures.push(`${combo.name} ${width}px ${uri}: HTTP ${status} landed ${landed}`);
          console.log(`  ${(uri + ' @' + width).padEnd(40)} HTTP ${status} -> ${landed}  NOT MEASURED`);
          continue;
        }

        await page.waitForTimeout(500);

        const facts = await page.evaluate(PAGE_FACTS);

        /* THE PREMISE, ASSERTED. A width figure taken with the debug bar present is a figure
           about a page the product does not serve. */
        if (facts.debugBar) {
          premiseFailures.push(`${combo.name} ${width}px ${uri}: ${facts.debugBar} phpdebugbar `
            + 'element(s) on the page -- APP_DEBUG is ON and every figure here is about a '
            + 'different page');
          console.log(`  ${(uri + ' @' + width).padEnd(40)} DEBUG BAR PRESENT -- NOT MEASURED`);
          continue;
        }

        if ((facts.dir || 'ltr') !== combo.dir || facts.lang !== combo.lang) {
          premiseFailures.push(`${combo.name} ${width}px ${uri}: dir=${facts.dir} lang=${facts.lang}`);
          console.log(`  ${(uri + ' @' + width).padEnd(40)} WRONG CHROME -- NOT MEASURED`);
          continue;
        }

        if ((combo.theme === 'dark') !== (facts.theme === 'dark')) {
          premiseFailures.push(`${combo.name} ${width}px ${uri}: data-ss-theme=${facts.theme}`);
          console.log(`  ${(uri + ' @' + width).padEnd(40)} WRONG THEME -- NOT MEASURED`);
          continue;
        }

        /*
         * --- open the navigation the way a person does, ONE STEP AT A TIME ---
         *
         * AND THE STEPS ARE SEPARATE BECAUSE COMBINING THEM CLOSED THE GROUP. The first version
         * clicked the shell's toggle AND the group header in the same branch, and reported LINK
         * COVERED BY a.dash-link at every width below 1440. The cause was the second click: the
         * theme auto-expands the group that contains the ACTIVE route, so the group was already
         * open once the sidebar appeared, and `dash.js`'s handler TOGGLES -- the header click shut
         * it again. A collapsed `.dash-submenu` clips rather than hides, so its children keep
         * their boxes and only the hit test noticed.
         */
        const toggle = await page.$('#mobile-collapse, .mobile-menu, [class*="mob-toggler"]');
        const steps = [];

        let reach = await page.evaluate(LINK_REACHABLE, uri);

        if ((!reach.found || !reach.inViewport || !reach.hitIsTheLink) && toggle) {
          await toggle.click().catch(() => null);
          await page.waitForTimeout(500);
          steps.push('sidebar toggled');
          reach = await page.evaluate(LINK_REACHABLE, uri);
        }

        if (reach.found && (!reach.inViewport || !reach.hitIsTheLink)) {
          const clicked = await page.evaluate(OPEN_GROUP, uri);
          await page.waitForTimeout(500);
          if (clicked) steps.push('group header clicked');
          reach = await page.evaluate(LINK_REACHABLE, uri);
        }

        /* A link below the fold is not unreachable -- a sidebar scrolls. Bring it into view the
           way a person's scroll would and look again, so the finding is "cannot be reached" and
           not "is not currently in the 900px window". */
        if (reach.found && !reach.inViewport) {
          await page.evaluate((u) => {
            const a = [...document.querySelectorAll('.dash-sidebar a.dash-link')].find(x => {
              try { return new URL(x.getAttribute('href') || '', location.href).pathname === u; }
              catch (e) { return false; }
            });
            if (a) a.scrollIntoView({ block: 'center' });
          }, uri);
          await page.waitForTimeout(400);
          steps.push('scrolled into view');
          reach = await page.evaluate(LINK_REACHABLE, uri);
        }

        const opened = steps.length ? steps.join(' + ') : 'already open';

        const wideTables = facts.tables.filter(t => t.width > facts.clientWidth + 1);
        const unwrapped = wideTables.filter(t => !t.scroller);

        const flags = [];
        if (facts.overflow > 1) flags.push(`DOC OVERFLOW +${facts.overflow}px`);
        if (!reach.found) flags.push('LINK ABSENT FROM SIDEBAR');
        else if (!reach.inViewport) flags.push(`LINK OFF SCREEN ${reach.box.join('x')}`);
        else if (!reach.hitIsTheLink) flags.push(`LINK COVERED BY ${reach.hit}`);
        if (unwrapped.length) flags.push(`${unwrapped.length} WIDE TABLE(S) WITH NO SCROLLER`);

        if (flags.length) failures.push(`${combo.name} ${width}px ${uri}: ${flags.join('; ')}`);

        rows.push({ combo: combo.name, width, uri, overflow: facts.overflow,
          reachable: reach.found && reach.inViewport && reach.hitIsTheLink,
          tables: facts.tables.length, wide: wideTables.length, unwrapped: unwrapped.length });

        console.log(`  ${(uri + ' @' + width).padEnd(40)} overflow=${String(facts.overflow).padStart(4)}px  `
          + `link=${reach.found ? (reach.inViewport ? (reach.hitIsTheLink ? 'reachable' : 'covered') : 'off-screen') : 'absent'} `
          + `(${opened})  tables=${facts.tables.length} wide=${wideTables.length} unwrapped=${unwrapped.length}`
          + (flags.length ? '   !! ' + flags.join('; ') : ''));
      }

      await ctx.close();
    }
  }

  /* --- captures, in their own contexts at deviceScaleFactor 2 --- */
  if (OUT) {
    for (const combo of COMBOS) {
      execFileSync('php', [STATE_TOOL, '1', combo.theme, combo.dir, combo.lang, OPERATOR],
        { env: phpEnv, encoding: 'utf8' });

      for (const width of [390, 1440]) {
        const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 2 });
        const baseOrigin = new URL(BASE).origin;
        await ctx.route('**/*', (route) => {
          let origin = null;
          try { origin = new URL(route.request().url()).origin; } catch (e) { origin = null; }
          if (origin && origin !== baseOrigin) { offOrigin++; return route.abort(); }
          return route.continue();
        });

        const page = await ctx.newPage();
        await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
        await page.fill('input[name="email"]', OPERATOR);
        await page.fill('input[name="password"]', PASSWORD);
        await Promise.all([
          page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null),
          page.click('button[type="submit"]'),
        ]);

        await page.goto(BASE + '/platform/operations', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(600);

        /* OPEN THE SIDEBAR, THEN OPEN THE GROUP ONLY IF IT IS SHUT -- the same trap as the
           measurement path above, and it bit the captures after it was fixed there: the theme
           auto-expands the group containing the active route, so an unconditional header click
           photographs the group CLOSED. The first set of captures taken from this script shows
           exactly that, which is why the check is here and not only in the measurement. */
        const toggle = await page.$('#mobile-collapse, .mobile-menu, [class*="mob-toggler"]');
        if (toggle && width < 1024) { await toggle.click().catch(() => null); await page.waitForTimeout(500); }

        if (!(await page.evaluate(LINK_REACHABLE, '/platform/operations')).groupOpen) {
          await page.evaluate(OPEN_GROUP, '/platform/operations');
          await page.waitForTimeout(500);
        }

        const file = path.join(OUT, `sidebar-group__${combo.name}__${width}.png`);
        await page.screenshot({ path: file });
        const sha = crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex').slice(0, 16);
        shots.push({ file: path.basename(file), sha });

        await ctx.close();
      }
    }
  }

  execFileSync('php', [STATE_TOOL, '1', 'light', 'ltr', 'en', OPERATOR], { env: phpEnv, encoding: 'utf8' });
  await browser.close();

  console.log('\n\n================ OVERFLOW BY WIDTH ================');
  console.log('screen'.padEnd(26) + WIDTHS.map(w => (w + 'px').padEnd(10)).join('') + '  (max over all four combinations)');
  for (const uri of SCREENS) {
    const cells = WIDTHS.map(w => {
      const xs = rows.filter(r => r.uri === uri && r.width === w);
      return (xs.length ? `+${Math.max(...xs.map(r => r.overflow))}px` : 'n/m').padEnd(10);
    });
    console.log(uri.padEnd(26) + cells.join(''));
  }

  console.log('\n================ SIDEBAR REACHABILITY ================');
  for (const w of WIDTHS) {
    const at = rows.filter(r => r.width === w);
    const ok = at.filter(r => r.reachable).length;
    console.log(`  ${String(w).padStart(5)}px   ${ok} of ${at.length} (screen x combination) reached their own sidebar link`);
  }

  if (shots.length) {
    console.log('\n================ CAPTURES (deviceScaleFactor 2) ================');
    for (const s of shots) console.log(`  ${s.sha}  ${s.file}`);
    const distinct = new Set(shots.map(s => s.sha)).size;
    console.log(`  ${distinct} distinct of ${shots.length} -- identical captures are a harness fault, not a finding`);
  }

  console.log('\n================ RESULT ================');
  console.log(`combinations            ${COMBOS.length}`);
  console.log(`widths                  ${WIDTHS.join(', ')}`);
  console.log(`measured                ${rows.length} of ${COMBOS.length * WIDTHS.length * SCREENS.length}`);
  console.log(`premise failures        ${premiseFailures.length}`);
  premiseFailures.forEach(f => console.log(`   ! ${f}`));
  console.log(`findings                ${failures.length}`);
  failures.forEach(f => console.log(`   ! ${f}`));
  console.log(`off-origin aborted      ${offOrigin}`);

  const bad = premiseFailures.length + failures.length;
  console.log(bad === 0 ? '\nPASS' : `\nFAIL (${premiseFailures.length} premise, ${failures.length} findings)`);
  process.exit(bad === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
