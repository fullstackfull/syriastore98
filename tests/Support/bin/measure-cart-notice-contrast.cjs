#!/usr/bin/env node
/**
 * MEASURE THE CART STALE-LINE NOTICE'S REAL CONTRAST, IN A REAL BROWSER.
 * =====================================================================
 *
 * WHY A BROWSER AND NOT THE STYLESHEET. The authored token pair is easy to read off
 * `store-syria-ds.css` and that is NOT the question. This project's recorded lesson is that the
 * obvious repair is frequently INERT -- three separate times a tone class changed the measured
 * ratio not at all, because a host rule carried `!important` on a lower-specificity selector. So
 * what is measured here is `getComputedStyle` on the rendered element, with the real markup and
 * the real stylesheets, and the ratio is computed from those COMPUTED values.
 *
 * WHAT IT COVERS. Six combinations, which is a superset of the four asked for:
 *
 *     light                  x  LTR / RTL
 *     dark via attribute     x  LTR / RTL     [data-ss-theme="dark"]
 *     dark via OS preference x  LTR / RTL     prefers-color-scheme: dark, NO attribute
 *
 * The third pair is the one that matters most and is easiest to forget: `auto` is the DEFAULT
 * state, so a shopper who has never touched the appearance control is on it, and
 * `StorefrontDarkModeLayerTest` exists because a rule written only for the attribute misses most
 * visitors.
 *
 * PRECONDITIONS, stated rather than assumed:
 *   - `playwright` resolvable (preinstalled in this environment).
 *   - `php` on PATH with this application's `vendor/` installed; the companion renderer boots the
 *     framework to produce the fragments from the REAL Blade partial through the application's own
 *     view factory. No database is needed.
 *
 * RUN IT WITH:  node tests/Support/bin/measure-cart-notice-contrast.cjs
 *
 * It is a harness, not the live storefront page: it links the two stylesheets the storefront links
 * and nothing else. That isolates the question "do these classes paint legibly in all six states"
 * from "does the cart route work", which the Feature suite answers separately. Stated so the
 * result is not over-read.
 *
 * FLOOR: WCAG 2.1 AA 1.4.3, 4.5:1 for body text. Exit 0 only when every combination clears it.
 *
 * ---------------------------------------------------------------------------------------------
 * WHAT THIS SCRIPT DOES NOT PROVE, stated because the obvious reading of a green run is too
 * generous and because it is the half that needed a different control.
 *
 * It proves the DS component's COMPUTED colours clear AA in every state, and that the accent edge
 * mirrors under `dir="rtl"`. It does NOT prove the panel follows the page's theme. Measured: with
 * the component replaced by the obvious `bg-amber-50 text-amber-900` repair, this script fails --
 * but only because its own selector `.ss-alert--warning` has gone, which is a tautology. Had the
 * selector still matched, an amber panel that stays LIGHT on a dark page would have passed the
 * floor comfortably, because amber-on-amber is perfectly legible against its own background. It
 * is merely wrong.
 *
 * That gap is covered by a different control:
 * `CartStaleLineNoticeRendersTest::test_the_notice_uses_no_colour_utility_the_dark_layer_cannot_reach`,
 * which reads the mapped-utility set out of `storefront-appearance.css` and refuses any colour
 * class outside it. Neither control is sufficient alone, which is why both exist.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');

const ROOT = path.resolve(__dirname, '../../..');
const FLOOR = 4.5;

/*
 * ONE COMMAND, AND THE FRAGMENTS ARE RENDERED BY THE APPLICATION RATHER THAN PASTED HERE.
 * A harness holding its own copy of the markup keeps passing after the real view changes, which
 * is the commonest way a UI measurement quietly stops measuring its subject.
 */
const FRAGMENTS = fs.mkdtempSync(path.join(os.tmpdir(), 'cart-notice-'));
execFileSync('php', [path.join(__dirname, 'render-cart-notice-fragments.php'), FRAGMENTS], {
  stdio: 'inherit',
});

function channel(v) {
  const c = v / 255;
  return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}

function luminance([r, g, b]) {
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

function ratio(fg, bg) {
  const a = luminance(fg);
  const b = luminance(bg);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

function rgb(s) {
  const m = String(s).match(/-?[\d.]+/g);
  if (!m || m.length < 3) throw new Error('unparseable colour: ' + s);
  return [Number(m[0]), Number(m[1]), Number(m[2])];
}

/*
 * THE HARNESS IS WRITTEN TO A FILE AND NAVIGATED TO, NOT PASSED TO setContent().
 *
 * MEASURED THE HARD WAY: the first version called `page.setContent()` with `file://` stylesheet
 * links. The document's base URL is then `about:blank`, Chromium does not resolve `file://`
 * subresources from it, and NEITHER STYLESHEET LOADED -- so every one of the six combinations
 * reported plain black on plain white at 21.00:1 and the run looked like a comfortable pass. A
 * measurement that silently stops measuring reads exactly like a good result, which is the whole
 * reason this script exists rather than reading the token pair off the stylesheet. The premise
 * check below now makes that state FAIL instead.
 */
function harness(fragment, dir, dest) {
  const html = `<!doctype html>
<html lang="${dir === 'rtl' ? 'ar' : 'en'}" dir="${dir}">
<head><meta charset="utf-8">
<link rel="stylesheet" href="${ROOT}/public/css/store-syria-ds.css">
<link rel="stylesheet" href="${ROOT}/public/css/storefront-appearance.css">
</head>
<body><main style="padding:24px">${fragment}</main></body></html>`;
  fs.writeFileSync(dest, html);
  return 'file://' + dest;
}

(async () => {
  const fragments = {
    en: fs.readFileSync(path.join(FRAGMENTS, 'notice-en.html'), 'utf8'),
    ar: fs.readFileSync(path.join(FRAGMENTS, 'notice-ar.html'), 'utf8'),
  };

  const combos = [
    { name: 'light            LTR/en', locale: 'en', dir: 'ltr', scheme: 'light', attr: null },
    { name: 'light            RTL/ar', locale: 'ar', dir: 'rtl', scheme: 'light', attr: null },
    { name: 'auto  OS-light   LTR/en', locale: 'en', dir: 'ltr', scheme: 'light', attr: 'auto' },
    { name: 'dark  attribute  LTR/en', locale: 'en', dir: 'ltr', scheme: 'light', attr: 'dark' },
    { name: 'dark  attribute  RTL/ar', locale: 'ar', dir: 'rtl', scheme: 'light', attr: 'dark' },
    /*
     * `attr: 'auto'` IS NOT A CONVENIENCE -- IT IS WHAT THE REAL PAGE DOES, and getting it wrong
     * produced a false reading in this very script. Both dark arms are two-armed:
     * `[data-ss-theme="dark"]`, and `@media (prefers-color-scheme: dark) { [data-ss-theme="auto"] }`
     * (`store-syria-ds.css:618-619`, `storefront-appearance.css:172-175`). The `auto` arm is
     * therefore ATTRIBUTE-GATED, and the attribute is set by SCRIPT on load --
     * `front_end/partials/appearance.blade.php:68` writes `auto` whenever the shopper has stored
     * no preference, which is the DEFAULT state and so most visitors.
     *
     * With no script and no attribute, this harness measured the LIGHT pair under
     * `prefers-color-scheme: dark` and reported it as a comfortable 8.83:1 pass. That is not a
     * defect in the component -- it is this harness failing to emulate the page -- but it reads
     * identically to one, so the emulation is explicit here.
     */
    { name: 'dark  OS-pref    LTR/en', locale: 'en', dir: 'ltr', scheme: 'dark', attr: 'auto' },
    { name: 'dark  OS-pref    RTL/ar', locale: 'ar', dir: 'rtl', scheme: 'dark', attr: 'auto' },
  ];

  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const failures = [];
  const rows = [];

  for (const combo of combos) {
    const ctx = await browser.newContext({
      colorScheme: combo.scheme,
      deviceScaleFactor: 1,
      viewport: { width: 900, height: 400 },
    });
    const p = await ctx.newPage();
    const dest = path.join(FRAGMENTS, `harness-${combo.locale}-${combo.dir}.html`);
    await p.goto(harness(fragments[combo.locale], combo.dir, dest));
    if (combo.attr) {
      await p.evaluate((v) => document.documentElement.setAttribute('data-ss-theme', v), combo.attr);
    }

    const measured = await p.evaluate(() => {
      const box = document.querySelector('.ss-alert--warning');
      if (!box) return null;
      const body = box.querySelector('.ss-alert__body');
      const bs = getComputedStyle(box);
      const ts = getComputedStyle(body);
      // The painted backdrop: walk out until something is not transparent.
      let el = box;
      let bg = bs.backgroundColor;
      while (el && (bg === 'rgba(0, 0, 0, 0)' || bg === 'transparent')) {
        el = el.parentElement;
        bg = el ? getComputedStyle(el).backgroundColor : 'rgb(255, 255, 255)';
      }
      return {
        fg: ts.color,
        bg,
        // PREMISE: proof the design system actually applied. `.ss-alert` sets a 3px
        // border-inline-start and a non-default padding; if the stylesheet did not load, both are
        // 0px and the colours are the browser's defaults.
        applied: bs.borderInlineStartWidth === '3px' && bs.paddingTop !== '0px',
        inlineStart: bs.borderInlineStartWidth,
        borderLeft: bs.borderLeftWidth,
        borderRight: bs.borderRightWidth,
        text: body ? body.textContent.trim().slice(0, 40) : '',
      };
    });

    await ctx.close();

    if (!measured) {
      failures.push(`${combo.name}: the notice element was not in the page at all`);
      continue;
    }

    if (!measured.applied) {
      failures.push(`${combo.name}: PREMISE FAILED -- .ss-alert did not apply (border-inline-start `
        + `${measured.inlineStart}), so the stylesheets did not load and any ratio below is the `
        + `browser's defaults rather than this component's`);
      continue;
    }

    const r = ratio(rgb(measured.fg), rgb(measured.bg));
    const mirrored = combo.dir === 'rtl'
      ? measured.borderRight !== '0px'
      : measured.borderLeft !== '0px';

    rows.push({ name: combo.name, fg: measured.fg, bg: measured.bg, r, mirrored, text: measured.text });

    if (r < FLOOR) {
      failures.push(`${combo.name}: ${r.toFixed(2)}:1 is below the ${FLOOR}:1 floor (${measured.fg} on ${measured.bg})`);
    }
    if (!mirrored) {
      failures.push(`${combo.name}: the accent edge did not land on the reading-start side, so border-inline-start is not mirroring`);
    }
  }

  await browser.close();

  console.log('\n  combination                fg                      bg                      ratio   accent edge');
  console.log('  ' + '-'.repeat(100));
  for (const row of rows) {
    console.log(`  ${row.name}   ${row.fg.padEnd(23)} ${row.bg.padEnd(23)} ${row.r.toFixed(2).padStart(5)}:1  ${row.mirrored ? 'reading-start' : 'WRONG SIDE'}`);
  }
  console.log(`\n  floor ${FLOOR}:1 (WCAG 2.1 AA 1.4.3, body text)`);

  if (failures.length) {
    console.log('\nFAIL');
    for (const f of failures) console.log('  - ' + f);
    process.exit(1);
  }
  console.log('\nPASS  ' + rows.length + ' combinations, 0 below the floor');
})().catch((e) => { console.error(e); process.exit(1); });
