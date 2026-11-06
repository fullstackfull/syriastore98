#!/usr/bin/env node
'use strict';
/**
 * CAN A KEYBOARD USER SEE WHERE THEY ARE? Measured by pressing Tab, on the rendered page.
 *
 * WHY A SOURCE TEST IS NOT ENOUGH, and this project has the scar. FocusIndicatorRestorationTest
 * asserts that focus DECLARATIONS exist in the stylesheets. A declaration that exists and is
 * overridden passes that test and fails the merchant. The same gap produced P2.5-DESIGN-J:
 * `var(--ss-focus)` was defined nowhere, so every ring on that control was a hard-coded fallback
 * blue at 2.77:1 -- found by reading, not by the test that was meant to cover it.
 *
 * TWO THINGS THIS TOOL GOT WRONG IN ITS FIRST DRAFT, both recorded because both would have
 * produced a confident and false report:
 *
 *   1. IT CALLED `el.focus()`. Programmatic focus matches `:focus`, and matches `:focus-visible`
 *      only where the browser's heuristics say so -- reliably for text fields, NOT for buttons
 *      and links. This codebase draws its rings with `:focus-visible` (store-syria-ds.css), so
 *      half the controls were measured against a selector that was never active. The tool now
 *      presses Tab, which is what a keyboard user does and what `:focus-visible` is for.
 *
 *   2. IT TREATED "THE COMPUTED STRING CHANGED" AS "AN INDICATOR APPEARED". Measured on
 *      `/finance`: `outline: none 3px rgb(41,50,64)` became `outline: none 0px rgb(41,50,64)`,
 *      and `box-shadow: none` became `rgba(0,0,0,0) 0px 0px 0px 0px`. Both states paint nothing.
 *      The draft counted that as an indicator and then reported its contrast as 1.00:1, which
 *      reads as "a faint ring" when the truth is "no ring". An indicator now has to be PAINTED:
 *      a non-`none` outline with a width, or a shadow with a non-zero alpha AND a non-zero size.
 *
 * WHAT IS REPORTED, per Tab stop:
 *
 *   none        Nothing a viewer can see changes. SC 2.4.7 fails outright.
 *   low         An indicator is painted but is under 3:1 against what it sits on. SC 1.4.11.
 *   obscured    The focused control is covered at its own centre. SC 2.4.11 (AA).
 *
 * Contrast is COMPOSITED up the ancestor chain: a non-composited reading turned a real 16.15:1
 * into 1.24:1 earlier in this phase.
 *
 * usage: node docs/development/phase-2.5/tools/census-focus-visibility.cjs [--routes a,b] [--theme dark] [--stops 40]
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
const STOPS = Number(arg('stops', 45));

const ROUTES = (arg('routes', '') || [
  'dashboard', 'product', 'order', 'customer', 'finance', 'setting', 'roles',
].join(',')).split(',').map((s) => s.trim()).filter(Boolean);

execFileSync('php', [TOOL, '1', THEME, DIR, LANG, 'merchant@example.test'], {
  cwd: path.dirname(TOOL),
  env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(REPO, 'database/p2dev.sqlite'), CACHE_STORE: 'file' },
  stdio: ['ignore', 'pipe', 'pipe'],
});

const PROBE = `(() => {
  const px = (s) => { const q = (s || '').match(/[-\\d.]+/g); const c = (s || '').match(/rgba?\\(([^)]+)\\)/);
    if (!c) { return null; }
    const n = c[1].split(',').map((x) => parseFloat(x));
    return { r: n[0], g: n[1], b: n[2], a: n.length >= 4 ? n[3] : 1 }; };
  const over = (f, bg) => ({ r: f.r * f.a + bg.r * (1 - f.a), g: f.g * f.a + bg.g * (1 - f.a), b: f.b * f.a + bg.b * (1 - f.a), a: 1 });
  const lin = (x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); };
  const L = (c) => 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b);
  const ratio = (a, bg) => (Math.max(L(a), L(bg)) + 0.05) / (Math.min(L(a), L(bg)) + 0.05);
  const bgOf = (el) => { const st = []; let n = el.parentElement;
    while (n && n !== document.documentElement) { const c = px(getComputedStyle(n).backgroundColor); if (c && c.a > 0) { st.push(c); } n = n.parentElement; }
    let acc = px(getComputedStyle(document.documentElement).backgroundColor) || { r: 255, g: 255, b: 255, a: 1 };
    acc = { ...acc, a: 1 };
    for (let i = st.length - 1; i >= 0; i--) { acc = over(st[i], acc); }
    return acc; };

  const el = document.activeElement;
  if (!el || el === document.body || el === document.documentElement) { return null; }
  const cs = getComputedStyle(el);
  const name = el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).trim().split(/\\s+/)[0] : '');

  /* A PAINTED indicator, on the control OR ON A WRAPPER THAT HUGS IT. The third draft of this
     tool reported 27 controls with "no indicator" and a SCREENSHOT refuted it: the focused search
     field wears a thick blue ring while its own outline computes none/0px and its own box-shadow
     is transparent. The ring is drawn on an ancestor through :focus-within, which a probe reading
     only document.activeElement cannot see. Ancestors are therefore walked -- but only two
     levels, and only while the ancestor box stays within 8px of the control box, because every
     card on the page has a permanent shadow and a card is not a focus ring. */
  const painted = (node) => {
    const s = getComputedStyle(node);
    if (s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0) { return px(s.outlineColor); }
    if (s.boxShadow && s.boxShadow !== 'none') {
      const c = px(s.boxShadow);
      const nums = (s.boxShadow.split(')').pop() || '').match(/[-\\d.]+/g) || [];
      if (c && c.a > 0.05 && nums.some((n) => Math.abs(parseFloat(n)) > 0)) { return c; }
    }
    return null;
  };

  let ink = painted(el);
  let inkOn = 'self';
  if (!ink) {
    const own = el.getBoundingClientRect();
    let n = el.parentElement;
    for (let d = 0; d < 2 && n && n !== document.body; d++, n = n.parentElement) {
      const q = n.getBoundingClientRect();
      const hugs = Math.abs(q.left - own.left) <= 8 && Math.abs(q.right - own.right) <= 8
        && Math.abs(q.top - own.top) <= 8 && Math.abs(q.bottom - own.bottom) <= 8;
      if (!hugs) { break; }
      const c = painted(n);
      if (c) { ink = c; inkOn = n.tagName.toLowerCase() + (n.className ? '.' + String(n.className).trim().split(/\\s+/)[0] : ''); break; }
    }
  }

  const q = el.getBoundingClientRect();
  el.scrollIntoView({ block: 'center' });
  const q2 = el.getBoundingClientRect();
  const hit = document.elementFromPoint(Math.round(q2.left + q2.width / 2), Math.round(q2.top + q2.height / 2));
  const obscured = !!(hit && hit !== el && !el.contains(hit) && !hit.contains(el));

  if (!ink) { return { name, verdict: 'none', obscured, hit: obscured && hit ? hit.tagName.toLowerCase() : null }; }
  const bg = bgOf(el);
  const r = ratio(over(ink, bg), bg);
  return { name, verdict: r < 3 ? 'low' : 'ok', ratio: +r.toFixed(2), inkOn, obscured, hit: obscured && hit ? hit.tagName.toLowerCase() : null };
})()`;

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
  const p = await ctx.newPage();
  await p.goto('http://127.0.0.1:8899/login');
  await p.fill('input[name="email"]', 'merchant@example.test');
  await p.fill('input[name="password"]', 'password');
  await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"], input[type="submit"]')]);

  let tNone = 0, tLow = 0, tObsc = 0, tAll = 0;

  for (const r of ROUTES) {
    let status = 0;
    try {
      const resp = await p.goto('http://127.0.0.1:8899/' + r, { waitUntil: 'domcontentloaded', timeout: 30000 });
      status = resp ? resp.status() : 0;
    } catch (e) { console.log(`  ${r.padEnd(14)} ERR`); continue; }
    if (status !== 200) { console.log(`  ${r.padEnd(14)} HTTP ${status}`); continue; }
    await p.waitForTimeout(1400);

    // start from the document, then walk the real tab order
    await p.evaluate(() => { document.body.setAttribute('tabindex', '-1'); document.body.focus(); });

    const none = [], low = [], obscured = [];
    const seen = new Set();
    let stops = 0;

    for (let i = 0; i < STOPS; i++) {
      await p.keyboard.press('Tab');
      const v = await p.evaluate(PROBE);
      if (!v) { continue; }
      stops++;
      const key = v.name + '#' + i;
      if (seen.has(key)) { continue; }
      seen.add(key);
      if (v.verdict === 'none') { none.push(v.name); }
      if (v.verdict === 'low') { low.push(v.name + '@' + v.ratio); }
      if (v.obscured) { obscured.push(v.name + '<' + v.hit); }
    }

    tAll += stops; tNone += none.length; tLow += low.length; tObsc += obscured.length;
    console.log(`  ${r.padEnd(14)} ${String(stops).padStart(3)} tab stops   no-indicator=${none.length}  below-3:1=${low.length}  obscured=${obscured.length}`
      + ((none.length || low.length || obscured.length) ? '' : '  clean'));
    if (none.length) { console.log(`      no indicator: ${[...new Set(none)].slice(0, 6).join(' ')}`); }
    if (low.length) { console.log(`      below 3:1   : ${[...new Set(low)].slice(0, 6).join(' ')}`); }
    if (obscured.length) { console.log(`      obscured    : ${[...new Set(obscured)].slice(0, 6).join(' ')}`); }
  }

  console.log(`\n  ${THEME}/${LANG}: of ${tAll} tab stops -- ${tNone} paint NO indicator, ${tLow} paint one below 3:1, ${tObsc} are obscured when focused`);
  await b.close();
})();
