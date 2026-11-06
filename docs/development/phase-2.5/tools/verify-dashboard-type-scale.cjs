/**
 * P2.5-PKG-A-03 — the dashboard's type scale, measured on the running page.
 *
 * FOUR PASSES: {light, dark} x {ltr, rtl}, at 1440. Every size is read from an element that
 * RENDERS ITS OWN TEXT -- an element whose only text is in its children reports a font-size
 * nothing on screen uses, and counting those inflates the scale with sizes no merchant sees.
 *
 * Contrast is composited against each element's own background, found by walking up to the
 * first non-transparent ancestor.
 *
 * Run with the dev server on 127.0.0.1:8899 and never with DB_* exported into the shell.
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const REPO = path.resolve(__dirname, '../../../..');
const TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');
const DB = path.join(REPO, 'database/p2dev.sqlite');
const BASE = 'http://127.0.0.1:8899';

/** Measured after the convergence: 28 / 24 / 18 / 16 / 14 / 13 / 12. */
const MAX_DISTINCT_SIZES = 7;
const AA_TEXT = 4.5;

function px(v) {
  const m = /rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?\)/.exec(String(v));
  return m ? [+m[1], +m[2], +m[3], m[4] === undefined ? 1 : +m[4]] : null;
}
function lum(c) {
  const f = c.map((x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); });
  return 0.2126 * f[0] + 0.7152 * f[1] + 0.0722 * f[2];
}
function ratio(fg, bg) {
  const f = px(fg); const b = px(bg);
  if (!f || !b) { return null; }
  const c = f[3] < 1 ? [0, 1, 2].map((i) => f[i] * f[3] + b[i] * (1 - f[3])) : f.slice(0, 3);
  const l1 = lum(c); const l2 = lum(b.slice(0, 3));
  return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
}

let checks = 0; let fails = 0;
function ck(name, ok, detail) {
  checks++;
  if (!ok) { fails++; console.log('  FAIL  ' + name.padEnd(44) + detail); }
  else { console.log('  ok    ' + name.padEnd(44) + detail); }
}

(async () => {
  for (const [theme, dir, lang] of [['light', 'ltr', 'en'], ['light', 'rtl', 'ar'], ['dark', 'ltr', 'en'], ['dark', 'rtl', 'ar']]) {
    execFileSync('php', [TOOL, '1', theme, dir, lang, 'merchant@example.test'], {
      cwd: path.dirname(TOOL),
      env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: DB, CACHE_STORE: 'file' },
      stdio: ['ignore', 'pipe', 'pipe'],
    });

    const browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await ctx.newPage();
    // KNOWN-ABSENT ASSETS, NAMED WITH THEIR REASON rather than the check being dropped.
    // Neither is caused by this slice and neither is a product defect this phase owns:
    //   /themes/…    the theme preview image lives at the REPOSITORY ROOT in `themes/`, and
    //                there is no `public/themes`, so the URL cannot resolve. That is a
    //                packaging question, recorded, not a type-scale one.
    //   /uploads/1/p2dev/…  product images named by THIS session's dev seed, which never
    //                shipped files for them. A fixture gap; `database/p2dev.sqlite` is
    //                gitignored and none of it enters the repository.
    // Anything else 404ing on this page reddens the run, which is the point of keeping it.
    const KNOWN_ABSENT = [/\/themes\/[a-z]+\/theme_img\//, /\/uploads\/1\/p2dev\//];
    const errs = [];
    const bad404 = [];
    page.on('response', (r) => {
      if (r.status() < 400) { return; }
      const u = r.url().replace(BASE, '');
      if (!KNOWN_ABSENT.some((re) => re.test(u))) { bad404.push(r.status() + ' ' + u); }
    });
    page.on('console', (m) => {
      if (m.type() !== 'error') { return; }
      const t = m.text();
      // the console reports a failed asset without naming it, so the response hook above is
      // what identifies them; drop the generic line and keep anything else.
      if (/Failed to load resource/.test(t)) { return; }
      errs.push(t.slice(0, 80));
    });

    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', 'merchant@example.test');
    await page.fill('input[name="password"]', 'password');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"], input[type="submit"]')]);
    await page.goto(BASE + '/dashboard', { waitUntil: 'load' });
    await page.waitForTimeout(3200);

    const o = await page.evaluate(() => {
      const root = document.querySelector('.dash-content') || document.body;
      const ownBg = (el) => {
        let n = el;
        while (n && n !== document.documentElement) {
          const bg = getComputedStyle(n).backgroundColor;
          if (bg && !/rgba\(0, 0, 0, 0\)|transparent/.test(bg)) { return bg; }
          n = n.parentElement;
        }
        return 'rgb(255, 255, 255)';
      };
      const sizes = {};
      for (const el of root.querySelectorAll('*')) {
        const own = [...el.childNodes].filter((n) => n.nodeType === 3 && n.textContent.trim().length > 1);
        if (!own.length) { continue; }
        const r = el.getBoundingClientRect();
        if (r.width < 2 || r.height < 2) { continue; }
        const cs = getComputedStyle(el);
        if (cs.visibility === 'hidden' || cs.display === 'none') { continue; }
        sizes[cs.fontSize] = (sizes[cs.fontSize] || 0) + 1;
      }
      const sample = (sel) => {
        const e = root.querySelector(sel);
        if (!e) { return null; }
        const cs = getComputedStyle(e);
        return { size: cs.fontSize, weight: cs.fontWeight, color: cs.color, bg: ownBg(e),
          text: (e.textContent || '').trim().slice(0, 30) };
      };
      const storage = [...root.querySelectorAll('h2')].find((h) => h.textContent.includes('Storage') || h.textContent.includes('تخزين'));
      return {
        sizes,
        cardTitles: root.querySelectorAll('.ss-card__title').length,
        leftoverH4: root.querySelectorAll('h2.h4, h3.h4, .h4').length,
        meta: sample('.ss-card__meta'),
        rankName: sample('.ss-rank__name'),
        rankValue: sample('.ss-rank__value'),
        storageHeadingText: storage ? storage.textContent.trim() : null,
        storageHasSmall: storage ? !!storage.querySelector('small') : null,
      };
    });

    console.log('\n##### /dashboard  ' + theme + '/' + dir);

    const distinct = Object.keys(o.sizes);
    ck('distinct rendered font sizes <= ' + MAX_DISTINCT_SIZES, distinct.length <= MAX_DISTINCT_SIZES,
      distinct.length + ': ' + distinct.sort((a, b) => parseFloat(b) - parseFloat(a)).join(' '));

    ck('card titles are on the component', o.cardTitles >= 5, o.cardTitles + ' .ss-card__title');
    ck('no .h4 left on the page', o.leftoverH4 === 0, o.leftoverH4 + ' elements still carry .h4');

    for (const [label, el, floor] of [['card meta', o.meta, AA_TEXT], ['rank name', o.rankName, AA_TEXT], ['rank value', o.rankValue, AA_TEXT]]) {
      if (!el) { ck(label + ' present', false, 'ABSENT'); continue; }
      const r = ratio(el.color, el.bg);
      ck(label + ' >= AA', r >= floor, el.size + '/' + el.weight + '  ' + el.color + ' on ' + el.bg + ' = ' + r.toFixed(2) + ':1');
    }

    if (o.rankName && o.rankValue) {
      ck('the ranked value outsizes its label',
        parseFloat(o.rankValue.size) > parseFloat(o.rankName.size),
        'name ' + o.rankName.size + ' vs value ' + o.rankValue.size);
    }

    // The note left the heading, so the heading's accessible name is the title alone.
    ck('storage heading carries no note', o.storageHasSmall === false && !/[()]/.test(o.storageHeadingText || ''),
      JSON.stringify(o.storageHeadingText));

    ck('no unexpected 404s', bad404.length === 0, bad404.join(' | ') || 'none beyond the two known-absent fixtures');
    ck('no console errors', errs.length === 0, errs.join(' | ') || 'none');

    await browser.close();
  }

  console.log('\n================ ' + checks + ' checks, ' + fails + ' failed ================');
  process.exit(fails ? 1 : 0);
})();
