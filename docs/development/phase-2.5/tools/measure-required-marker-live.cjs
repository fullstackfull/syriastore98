/**
 * P2.5-WAVE-18 — read the required marker OFF THE RUNNING PAGE, in all four combinations.
 *
 * The ratchet (Tests\Unit\Ui\RequiredFieldMarkerTest) asserts the MARKUP. This asserts what a
 * merchant actually sees, which is a different question in three ways that matter here:
 *
 *   1. `.ss-label__required` uses `margin-inline-start`, a LOGICAL property. It is only correct in
 *      Arabic if the page's writing direction really is rtl -- so the side the asterisk lands on
 *      is measured, not assumed.
 *   2. Its colour is `var(--ss-danger)`, a SEMANTIC token, which re-themes. The asterisk has to
 *      clear AA against the modal's ground in BOTH themes, and the modal is white in both (see
 *      ModalFooterButtonContrastTest) -- so the dark arm is where this could fail.
 *   3. The marker must sit beside its own label, not wrap to a line of its own.
 *
 * usage:  OUT=/tmp/req node docs/development/phase-2.5/tools/measure-required-marker-live.cjs
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPO = '/home/user/syriastore98';
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const OUT = process.env.OUT || '/tmp/required-marker';
const EMAIL = process.env.EMAIL || 'merchant@example.test';
const PASSWORD = process.env.PASSWORD || 'password';
const STORE = process.env.STORE || '1';
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

const FLOOR = 4.5;

const COMBOS = [
  { lang: 'en', dir: 'ltr', theme: 'light' },
  { lang: 'en', dir: 'ltr', theme: 'dark' },
  { lang: 'ar', dir: 'rtl', theme: 'light' },
  { lang: 'ar', dir: 'rtl', theme: 'dark' },
];

const MODALS = [
  { route: '/tag', needle: '/tag/create', label: 'tag create' },
  { route: '/roles', needle: 'roles/create', label: 'role create' },
  { route: '/coupon', needle: '/coupon/create', label: 'coupon create' },
];

function lum([r, g, b]) {
  const c = (v) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4); };
  return 0.2126 * c(r) + 0.7152 * c(g) + 0.0722 * c(b);
}
function ratio(a, b) { const l1 = lum(a), l2 = lum(b); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); }
const parse = (v) => { const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(v || ''); return m ? [+m[1], +m[2], +m[3]] : null; };

function setState(theme, dir, lang) {
  execFileSync('php', [STATE_TOOL, STORE, theme, dir, lang, EMAIL], {
    cwd: path.dirname(STATE_TOOL),
    env: {
      ...process.env,
      DB_CONNECTION: process.env.DB_CONNECTION || 'sqlite',
      DB_DATABASE: process.env.DB_DATABASE || path.join(REPO, 'database/p2dev.sqlite'),
      CACHE_STORE: process.env.CACHE_STORE || 'file',
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

const READ = `
  const blend = (fg, a, bg) => fg.map((v, i) => Math.round(a * v + (1 - a) * bg[i]));
  const rgba = (s) => { const m = /rgba?\\((\\d+),\\s*(\\d+),\\s*(\\d+)(?:,\\s*([\\d.]+))?/.exec(s || '');
                        return m ? { c: [+m[1], +m[2], +m[3]], a: m[4] === undefined ? 1 : parseFloat(m[4]) } : null; };
  const effective = (el) => {
    const layers = []; let n = el;
    while (n) { const p = rgba(getComputedStyle(n).backgroundColor); if (p && p.a > 0.001) layers.push(p); n = n.parentElement; }
    if (!layers.length) return [255, 255, 255];
    let out = layers[layers.length - 1].c;
    for (let i = layers.length - 2; i >= 0; i--) out = blend(layers[i].c, layers[i].a, out);
    return out;
  };
  const marks = Array.from(document.querySelectorAll('#commanModel .ss-label__required'));
  const requiredControls = document.querySelectorAll('#commanModel [required]').length;
  return {
    requiredControls,
    marks: marks.map((el) => {
      const cs = getComputedStyle(el);
      const r = el.getBoundingClientRect();
      // the label this marker belongs to is its immediately preceding sibling
      const prev = el.previousElementSibling;
      const pr = prev ? prev.getBoundingClientRect() : null;
      return {
        colour: cs.color, bg: effective(el),
        hidden: el.getAttribute('aria-hidden'),
        text: (el.textContent || '').trim(),
        prevTag: prev ? prev.tagName.toLowerCase() : null,
        // POSITIVE means the marker is to the RIGHT of its label's right edge (correct for ltr);
        // NEGATIVE means it is to the LEFT of its label's left edge (correct for rtl).
        afterLabel: pr ? Math.round(r.left - pr.right) : null,
        beforeLabel: pr ? Math.round(pr.left - r.right) : null,
        sameLine: pr ? Math.abs(Math.round(r.top - pr.top)) <= 4 : null,
      };
    }),
  };
`;

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  let bad = 0, measured = 0;

  console.log('combo            modal          marks  colour / ground                 ratio  side      same-line  aria-hidden');
  console.log('-'.repeat(118));

  for (const combo of COMBOS) {
    setState(combo.theme, combo.dir, combo.lang);
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();

    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"], input[type="submit"]')]);

    const key = `${combo.lang}_${combo.dir}_${combo.theme}`;

    for (const m of MODALS) {
      await page.goto(BASE + m.route, { waitUntil: 'domcontentloaded' }).catch(() => {});
      await page.waitForTimeout(600);

      const dir = await page.getAttribute('html', 'dir');
      const candidates = await page.$$(`[data-ajax-popup="true"][data-url*="${m.needle}"]`);
      let trigger = null;
      for (const c of candidates) { if (await c.isVisible().catch(() => false)) { trigger = c; break; } }
      if (!trigger) { bad++; console.log(`${key.padEnd(16)} ${m.label.padEnd(14)} PREMISE-FAIL: no visible trigger`); continue; }

      await trigger.click().catch(() => {});
      await page.waitForSelector('#commanModel.show', { timeout: 7000 }).catch(() => {});
      await page.waitForTimeout(900);

      const st = await page.evaluate('(() => {' + READ + '})()');
      await page.screenshot({ path: path.join(OUT, `${m.label.replace(/\W+/g, '-')}__${key}.png`) }).catch(() => {});

      if (!st.marks.length) {
        bad++;
        console.log(`${key.padEnd(16)} ${m.label.padEnd(14)} PREMISE-FAIL: no .ss-label__required rendered (${st.requiredControls} required controls present)`);
      }

      for (const mk of st.marks) {
        measured++;
        const cr = ratio(parse(mk.colour), mk.bg);
        // the logical property has to resolve to the correct PHYSICAL side for this direction
        const sideOk = dir === 'rtl' ? (mk.beforeLabel !== null && mk.beforeLabel >= 0)
                                     : (mk.afterLabel !== null && mk.afterLabel >= 0);
        const ok = cr >= FLOOR && sideOk && mk.sameLine !== false && mk.hidden === 'true' && mk.text === '*';
        if (!ok) bad++;
        console.log(
          `${key.padEnd(16)} ${m.label.padEnd(14)} ${String(st.marks.length).padEnd(6)} `
          + `${(mk.colour + ' / rgb(' + mk.bg.join(', ') + ')').padEnd(31)} `
          + `${cr.toFixed(2).padEnd(6)} ${(sideOk ? 'ok' : 'WRONG').padEnd(9)} `
          + `${String(mk.sameLine).padEnd(10)} ${mk.hidden}${ok ? '' : '   <-- FAIL'}`);
      }

      await page.click('#commanModel .btn-close, #commanModel [data-bs-dismiss="modal"]').catch(() => {});
      await page.waitForTimeout(250);
    }

    await ctx.close();
  }

  await browser.close();
  console.log(`\n${measured} markers measured, ${bad} problem(s). -> ${OUT}`);
  process.exit(bad ? 1 : 0);
})();
