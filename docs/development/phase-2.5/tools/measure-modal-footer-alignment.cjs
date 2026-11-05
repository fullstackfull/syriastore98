/**
 * P2.5 — measure where a modal's footer divider sits relative to the fields above it.
 *
 * THE QUESTION THIS ANSWERS, and why it needs measuring rather than deciding.
 *
 * 56 merchant modal views put `.modal-footer` INSIDE the form's `.row`, so it becomes a flex child
 * of a container carrying Bootstrap's -12px gutter margins while the fields above it sit in
 * `col-*` children that get that 12px back as padding. The footer has no `col-*` class, so it does
 * not — and its top border therefore runs WIDER than the field it sits under.
 *
 * A first reading recorded 96.8% of the modal-body width with an 8px inset, against 92.0% and 20px
 * for a footer outside the row. But "% of the body" is the wrong frame: what a person sees is
 * whether the divider lines up with the FIELD, and the field has its own inset. So this measures
 * the field's own left and right edges and reports the DELTA, which is the number a fix has to
 * drive to zero.
 *
 * It reports, for each modal and each combination:
 *   fieldL / fieldR    the first `.form-control`'s content-box edges, relative to the modal body
 *   footL  / footR     the `.modal-footer`'s edges, same frame
 *   deltaStart/End     footer minus field, in LOGICAL terms (start/end, so RTL reads the same way)
 *
 * A negative delta means the divider overhangs the field on that side.
 *
 * NO `--dir` FLAG: all four combinations, every run. See measure-timeline-pills.cjs for why.
 *
 * usage:  node docs/development/phase-2.5/tools/measure-modal-footer-alignment.cjs
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const REPO = '/home/user/syriastore98';
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const EMAIL = process.env.EMAIL || 'merchant@example.test';
const PASSWORD = process.env.PASSWORD || 'password';
const STORE = process.env.STORE || '1';
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');
// Set OUT to also capture the modal. The numbers are the evidence; the shots are what a person
// looks at, and the directive asks for both.
const OUT = process.env.OUT || '';

const COMBOS = [
  { lang: 'en', dir: 'ltr', theme: 'light' },
  { lang: 'en', dir: 'ltr', theme: 'dark' },
  { lang: 'ar', dir: 'rtl', theme: 'light' },
  { lang: 'ar', dir: 'rtl', theme: 'dark' },
];

/** One modal whose footer is OUTSIDE the row, as the control, and three that are inside it. */
const MODALS = [
  { route: '/faqs', needle: '/faqs/create', label: 'faq create', inRow: false },
  { route: '/tag', needle: '/tag/create', label: 'tag create', inRow: true },
  { route: '/testimonial', needle: '/testimonial/create', label: 'testimonial create', inRow: true },
  { route: '/coupon', needle: '/coupon/create', label: 'coupon create', inRow: true },
];

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
  const body = document.querySelector('#commanModel .modal-body');
  const foot = document.querySelector('#commanModel .modal-footer');
  const field = document.querySelector('#commanModel .form-control, #commanModel select, #commanModel textarea');
  if (!body || !foot || !field) {
    return { missing: [!body && 'modal-body', !foot && 'modal-footer', !field && 'form-control'].filter(Boolean) };
  }
  const bb = body.getBoundingClientRect();
  const fb = foot.getBoundingClientRect();
  const xb = field.getBoundingClientRect();
  const rtl = document.documentElement.getAttribute('dir') === 'rtl';
  // logical edges: in rtl the inline START is the RIGHT edge
  const startOf = (r) => rtl ? (bb.right - r.right) : (r.left - bb.left);
  const endOf   = (r) => rtl ? (r.left - bb.left) : (bb.right - r.right);
  return {
    bodyW: Math.round(bb.width),
    fieldStart: Math.round(startOf(xb)), fieldEnd: Math.round(endOf(xb)),
    footStart: Math.round(startOf(fb)), footEnd: Math.round(endOf(fb)),
    footParent: String(foot.parentElement.className).trim().slice(0, 24),
    parentDisplay: getComputedStyle(foot.parentElement).display,
    borderTop: getComputedStyle(foot).borderTopWidth,
    rtl,
  };
`;

(async () => {
  if (OUT) fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  const rows = [];

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

      // the shell's quick-create dropdown sorts earlier and is hidden; take the first VISIBLE one
      const candidates = await page.$$(`[data-ajax-popup="true"][data-url*="${m.needle}"]`);
      let trigger = null;
      for (const c of candidates) { if (await c.isVisible().catch(() => false)) { trigger = c; break; } }
      if (!trigger) { rows.push({ combo: key, modal: m.label, premise: 'no visible trigger' }); continue; }

      await trigger.click().catch(() => {});
      await page.waitForSelector('#commanModel.show', { timeout: 7000 }).catch(() => {});
      await page.waitForTimeout(800);

      const st = await page.evaluate('(() => {' + READ + '})()');
      if (st.missing) { rows.push({ combo: key, modal: m.label, premise: 'missing ' + st.missing.join(', ') }); }
      else rows.push({ combo: key, modal: m.label, inRow: m.inRow, ...st });

      if (OUT) {
        const box = await page.$('#commanModel .modal-dialog');
        await (box || page).screenshot({
          path: path.join(OUT, `${m.label.replace(/\W+/g, '-')}__${key}.png`),
        }).catch(() => {});
      }

      await page.click('#commanModel .btn-close, #commanModel [data-bs-dismiss="modal"]').catch(() => {});
      await page.waitForTimeout(250);
    }

    await ctx.close();
  }

  await browser.close();

  console.log('\ncombo            modal                in .row  parent        field S/E    footer S/E   DELTA S/E');
  console.log('-'.repeat(108));
  let misaligned = 0;
  for (const r of rows) {
    if (r.premise) { console.log(`${r.combo.padEnd(16)} ${r.modal.padEnd(20)} PREMISE-FAIL: ${r.premise}`); continue; }
    const dS = r.footStart - r.fieldStart;
    const dE = r.footEnd - r.fieldEnd;
    if (dS !== 0 || dE !== 0) misaligned++;
    console.log(
      `${r.combo.padEnd(16)} ${r.modal.padEnd(20)} ${String(r.inRow).padEnd(8)} `
      + `${(r.footParent + ' (' + r.parentDisplay + ')').padEnd(13)} `
      + `${(r.fieldStart + '/' + r.fieldEnd).padEnd(12)} ${(r.footStart + '/' + r.footEnd).padEnd(12)} `
      + `${(dS >= 0 ? '+' : '') + dS}/${(dE >= 0 ? '+' : '') + dE}`
      + (dS === 0 && dE === 0 ? '   aligned' : '   <-- overhangs'));
  }
  console.log(`\n${rows.filter((r) => !r.premise).length} readings, ${misaligned} where the divider does not line up with the field.`);
  console.log('A fix has to drive DELTA S and DELTA E to 0 in all four combinations.');
})();
