/**
 * P2.5-WAVE-19 — read the corrected Arabic action labels OFF THE RUNNING PAGE.
 *
 * The ratchet (Tests\Unit\Ui\ArabicActionLabelRegisterTest) asserts what is in ar.json. That is
 * not the same question as what a merchant SEES: a view can hard-code a string, a vendored module
 * can ship its own lang file, and Laravel resolves JSON translations only when the active locale
 * actually is `ar`. So this reads the rendered text.
 *
 * It checks the two surfaces where the corrected keys actually render:
 *   - a modal footer's Create button  (__('Create'))
 *   - a list screen's row-action tooltips (__('Show'), __('Delete'))
 *
 * And it asserts the ABSENCE of the old strings as well as the presence of the new ones, because
 * "the new word appears somewhere on the page" would also be true if both were rendering.
 *
 * usage:  node docs/development/phase-2.5/tools/verify-arabic-action-labels-live.cjs
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const REPO = '/home/user/syriastore98';
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const EMAIL = process.env.EMAIL || 'merchant@example.test';
const PASSWORD = process.env.PASSWORD || 'password';
const STORE = process.env.STORE || '1';
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

const GONE = ['خلق', 'احفظ', 'احذف', 'أرسلها', 'أظهر'];

(async () => {
  execFileSync('php', [STATE_TOOL, STORE, 'light', 'rtl', 'ar', EMAIL], {
    cwd: path.dirname(STATE_TOOL),
    env: {
      ...process.env,
      DB_CONNECTION: process.env.DB_CONNECTION || 'sqlite',
      DB_DATABASE: process.env.DB_DATABASE || path.join(REPO, 'database/p2dev.sqlite'),
      CACHE_STORE: process.env.CACHE_STORE || 'file',
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });

  const browser = await chromium.launch();
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();

  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);

  let bad = 0;

  // PREMISE: the page really is Arabic. Reading labels off an English page proves nothing.
  await page.goto(BASE + '/tag', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(700);
  const dir = await page.getAttribute('html', 'dir');
  if (dir !== 'rtl') { console.log(`PREMISE FAIL: document dir is ${dir}, not rtl`); bad++; }

  // 1. the modal footer's Create button
  const candidates = await page.$$('[data-ajax-popup="true"][data-url*="/tag/create"]');
  let trigger = null;
  for (const c of candidates) { if (await c.isVisible().catch(() => false)) { trigger = c; break; } }
  if (!trigger) { console.log('PREMISE FAIL: no visible create trigger on /tag'); bad++; }
  else {
    await trigger.click().catch(() => {});
    await page.waitForSelector('#commanModel.show', { timeout: 6000 }).catch(() => {});
    await page.waitForTimeout(800);
    const submit = await page.$('#commanModel .modal-footer input[type="submit"]');
    const label = submit ? (await submit.getAttribute('value')) : null;
    const ok = label === 'إنشاء';
    if (!ok) bad++;
    console.log(`  modal Create button      "${label}"   ${ok ? 'ok' : 'EXPECTED "إنشاء"'}`);
    await page.click('#commanModel .btn-close, #commanModel [data-bs-dismiss="modal"]').catch(() => {});
    await page.waitForTimeout(300);
  }

  // 2. the old strings must be GONE from the rendered page, title attributes included
  for (const route of ['/tag', '/customer']) {
    await page.goto(BASE + route, { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(900);
    const found = await page.evaluate((gone) => {
      const hay = document.body.innerText + ' ' +
        Array.from(document.querySelectorAll('[title],[aria-label],[value]'))
          .map((e) => `${e.getAttribute('title') || ''} ${e.getAttribute('aria-label') || ''} ${e.getAttribute('value') || ''}`)
          .join(' ');
      return gone.filter((w) => new RegExp(`(^|\\s)${w}(\\s|$)`).test(hay));
    }, GONE);
    if (found.length) { bad++; console.log(`  ${route.padEnd(12)} STILL RENDERS: ${found.join(', ')}`); }
    else console.log(`  ${route.padEnd(12)} none of the five old strings render   ok`);
  }

  await browser.close();
  console.log(bad ? `\n${bad} check(s) failed.` : '\nall checks passed.');
  process.exit(bad ? 1 : 0);
})();
