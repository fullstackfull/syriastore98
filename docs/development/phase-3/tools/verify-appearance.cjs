/**
 * PHASE 03 M2-A -- THE SHOPPER APPEARANCE CONTROL, DRIVEN IN A REAL BROWSER.
 *
 * Forty checks over the behaviours the M2 directive requires, none of which a PHPUnit test can
 * reach: localStorage, `prefers-color-scheme`, a live OS change, keyboard activation, and the one
 * that matters most -- that two stores on ONE ORIGIN hold independent preferences.
 *
 * The storage-blocked case REDEFINES `window.localStorage` to THROW, which is what a private
 * window or blocked site data actually does; a stub returning null would not exercise the catch.
 *
 *   docs/development/phase-2.5/tools/serve-dev.sh      # 127.0.0.1:8899, in another shell
 *   node docs/development/phase-3/tools/verify-appearance.cjs
 *
 * Exits non-zero on any failure. Last run: 40 passed, 0 failed.
 */
const { chromium } = require('playwright');
const B = 'http://127.0.0.1:8899';
const STORES = [['damascus-textiles','stylique',1],['p3fixt-homs-tech','techzonix',5],['p3fixt-latakia-green','greentic',6]];
let pass = 0, fail = 0;
const ok  = (n, c, d='') => { c ? pass++ : fail++; console.log(`  ${c ? 'PASS' : 'FAIL'}  ${n}${d ? '  -- ' + d : ''}`); };

const state = (p) => p.evaluate(() => ({
  theme:  document.documentElement.getAttribute('data-ss-theme'),
  source: document.documentElement.getAttribute('data-ss-theme-source'),
  key:    document.documentElement.getAttribute('data-ss-appearance-key'),
  btnState: document.querySelector('[data-ss-appearance-toggle]')?.getAttribute('data-state'),
  label:  document.querySelector('[data-ss-appearance-toggle]')?.getAttribute('aria-label'),
  buttons: document.querySelectorAll('[data-ss-appearance-toggle]').length,
  bg:     getComputedStyle(document.documentElement).getPropertyValue('--ss-bg').trim(),
  persisted: document.querySelector('[data-ss-appearance-toggle]')?.getAttribute('data-ss-persisted'),
}));

(async () => {
  const b = await chromium.launch();

  console.log('\n=== 1. exactly one control, and it is a real button ===');
  for (const [slug, theme] of STORES) {
    const ctx = await b.newContext({ viewport:{width:1440,height:900} });
    const p = await ctx.newPage();
    await p.goto(`${B}/${slug}/home`, {waitUntil:'domcontentloaded'});
    const s = await state(p);
    const tag = await p.evaluate(() => document.querySelector('[data-ss-appearance-toggle]')?.tagName);
    ok(`${theme}: one control`, s.buttons === 1, `found ${s.buttons}`);
    ok(`${theme}: it is a <button>`, tag === 'BUTTON', `got ${tag}`);
    await ctx.close();
  }

  console.log('\n=== 2. device-preference fallback (no stored choice) ===');
  for (const scheme of ['light','dark']) {
    const ctx = await b.newContext({ viewport:{width:1440,height:900}, colorScheme: scheme });
    const p = await ctx.newPage();
    await p.goto(`${B}/damascus-textiles/home`, {waitUntil:'domcontentloaded'});
    const s = await state(p);
    const want = scheme === 'dark' ? '#1b1f25' : '#f6f7f8';
    ok(`os=${scheme}: state is auto`, s.theme === 'auto' && s.source === 'system', `${s.theme}/${s.source}`);
    ok(`os=${scheme}: --ss-bg follows device`, s.bg === want, `${s.bg} wanted ${want}`);
    await ctx.close();
  }

  console.log('\n=== 3. cycle auto -> light -> dark -> auto, no reload ===');
  {
    const ctx = await b.newContext({ viewport:{width:1440,height:900}, colorScheme:'light' });
    const p = await ctx.newPage();
    await p.goto(`${B}/damascus-textiles/home`, {waitUntil:'domcontentloaded'});
    for (const want of ['light','dark','auto']) {
      await p.click('[data-ss-appearance-toggle]');
      const s = await state(p);
      ok(`cycled to ${want}`, s.theme === want && s.btnState === want, `${s.theme}/${s.btnState}`);
      if (want === 'dark') ok('dark applies --ss-bg', s.bg === '#1b1f25', s.bg);
      ok(`  persisted flag set`, s.persisted === 'yes', String(s.persisted));
    }
    await ctx.close();
  }

  console.log('\n=== 4. persistence across refresh AND navigation ===');
  {
    const ctx = await b.newContext({ viewport:{width:1440,height:900}, colorScheme:'light' });
    const p = await ctx.newPage();
    await p.goto(`${B}/damascus-textiles/home`, {waitUntil:'domcontentloaded'});
    await p.click('[data-ss-appearance-toggle]');          // light
    await p.click('[data-ss-appearance-toggle]');          // dark
    await p.reload({waitUntil:'domcontentloaded'});
    let s = await state(p);
    ok('dark survives refresh', s.theme === 'dark' && s.source === 'shopper', `${s.theme}/${s.source}`);
    await p.goto(`${B}/damascus-textiles/product-list`, {waitUntil:'domcontentloaded'});
    s = await state(p);
    ok('dark survives navigation', s.theme === 'dark', s.theme);
    await p.goto(`${B}/damascus-textiles/cart`, {waitUntil:'domcontentloaded'});
    s = await state(p);
    ok('dark survives a third route', s.theme === 'dark', s.theme);
    await ctx.close();
  }

  console.log('\n=== 5. TENANCY: two stores hold DIFFERENT preferences, one origin ===');
  {
    const ctx = await b.newContext({ viewport:{width:1440,height:900}, colorScheme:'light' });
    const p = await ctx.newPage();
    await p.goto(`${B}/damascus-textiles/home`, {waitUntil:'domcontentloaded'});
    await p.click('[data-ss-appearance-toggle]');
    await p.click('[data-ss-appearance-toggle]');          // store 1 -> dark
    let s1 = await state(p);
    await p.goto(`${B}/p3fixt-homs-tech/home`, {waitUntil:'domcontentloaded'});
    let s5 = await state(p);
    ok('store 1 is dark', s1.theme === 'dark', s1.theme);
    ok('store 5 is UNAFFECTED (auto)', s5.theme === 'auto', s5.theme);
    ok('keys differ', s1.key !== s5.key, `${s1.key} vs ${s5.key}`);
    await p.click('[data-ss-appearance-toggle]');          // store 5 -> light
    s5 = await state(p);
    await p.goto(`${B}/damascus-textiles/home`, {waitUntil:'domcontentloaded'});
    s1 = await state(p);
    ok('store 5 is light', s5.theme === 'light', s5.theme);
    ok('store 1 STILL dark after store 5 changed', s1.theme === 'dark', s1.theme);
    await ctx.close();
  }

  console.log('\n=== 6. storage blocked: control still works, says it could not remember ===');
  {
    const ctx = await b.newContext({ viewport:{width:1440,height:900}, colorScheme:'light' });
    const p = await ctx.newPage();
    await p.addInitScript(() => {
      // Exactly how a private window / blocked site data behaves: it THROWS.
      Object.defineProperty(window, 'localStorage', {
        get() { throw new DOMException('blocked', 'SecurityError'); }
      });
    });
    await p.goto(`${B}/damascus-textiles/home`, {waitUntil:'domcontentloaded'});
    let s = await state(p);
    ok('page still renders with an appearance', s.theme === 'auto', s.theme);
    ok('control still present', s.buttons === 1, String(s.buttons));
    await p.click('[data-ss-appearance-toggle]');
    s = await state(p);
    ok('toggling still re-themes', s.theme === 'light', s.theme);
    ok('and reports it was NOT persisted', s.persisted === 'no', String(s.persisted));
    await ctx.close();
  }

  console.log('\n=== 7. keyboard: reachable and operable ===');
  {
    const ctx = await b.newContext({ viewport:{width:1440,height:900}, colorScheme:'light' });
    const p = await ctx.newPage();
    await p.goto(`${B}/damascus-textiles/home`, {waitUntil:'domcontentloaded'});
    await p.focus('[data-ss-appearance-toggle]');
    const focused = await p.evaluate(() => document.activeElement.hasAttribute('data-ss-appearance-toggle'));
    ok('focusable', focused);
    await p.keyboard.press('Enter');
    let s = await state(p);
    ok('Enter activates', s.theme === 'light', s.theme);
    await p.keyboard.press(' ');
    s = await state(p);
    ok('Space activates', s.theme === 'dark', s.theme);
    const ring = await p.evaluate(() => {
      const el = document.querySelector('[data-ss-appearance-toggle]');
      el.focus();
      return getComputedStyle(el).outlineStyle;
    });
    ok('has a focus outline declared', ring !== 'none', ring);
    await ctx.close();
  }

  console.log('\n=== 8. the accessible name carries the state, in the page language ===');
  for (const [slug, theme] of STORES) {
    const ctx = await b.newContext({ viewport:{width:1440,height:900} });
    const p = await ctx.newPage();
    await p.goto(`${B}/${slug}/home`, {waitUntil:'domcontentloaded'});
    const s = await state(p);
    const lang = await p.evaluate(() => document.documentElement.lang);
    const hasLatin = /[A-Za-z]/.test(s.label || '');
    ok(`${theme} (lang=${lang}): name names the state`, (s.label||'').length > 10, s.label);
    if (lang === 'ar') ok(`${theme}: NO English in the Arabic label`, !hasLatin, s.label);
    await ctx.close();
  }

  console.log('\n=== 9. auto FOLLOWS a live device change ===');
  {
    const ctx = await b.newContext({ viewport:{width:1440,height:900}, colorScheme:'light' });
    const p = await ctx.newPage();
    await p.goto(`${B}/damascus-textiles/home`, {waitUntil:'domcontentloaded'});
    let s = await state(p);
    ok('starts auto/light', s.theme === 'auto' && s.bg === '#f6f7f8', `${s.theme} ${s.bg}`);
    await p.emulateMedia({ colorScheme: 'dark' });
    s = await state(p);
    ok('auto re-themes WITHOUT a reload', s.bg === '#1b1f25', s.bg);
    ok('and stays in auto', s.theme === 'auto', s.theme);
    await ctx.close();
  }

  console.log(`\n================ ${pass} passed, ${fail} failed ================`);
  await b.close();
  process.exit(fail ? 1 : 0);
})();
