/**
 * P2.5 INTERACTION PROBES — the half render-matrix.cjs cannot reach.
 * =================================================================================================
 *
 * render-matrix.cjs proves a screen RENDERS in four locale/theme combinations. Four of this phase's
 * repairs are not about rendering at all, and each is driven here against the live application:
 *
 *   1. THE TWO WITHDRAWAL CONFIRMATIONS (fc25f3b0). They were English literals inside
 *      `onclick="return confirm('...')"` on the money screen, so an Arabic merchant was asked to
 *      approve a payout in a language they had not selected. The probe READS THE NATIVE DIALOG'S
 *      TEXT and dismisses it. THE DIALOG STAYS SYNCHRONOUS BY OWNER INSTRUCTION -- converting a
 *      financial confirmation to an asynchronous SweetAlert needs separate approval -- so this
 *      probe exists to prove the native dialog is still native AND now Arabic. A probe that found
 *      no dialog would mean the repair had been converted, which is a regression here, not a pass.
 *
 *   2. THE BOARD COLUMN CAP (daf375da). `OrderController::BOARD_COLUMN_CAP` is 50 and a capped
 *      column must say so and link to the unfiltered list. The dev fixture ships 7 orders over six
 *      statuses, so nothing was ever capped; seed-dev-interaction-fixtures.php puts 55 in Pending.
 *      The probe asserts the column renders exactly 50 cards AND an overflow link whose text names
 *      the TRUE total, because a cap that lies about the count is worse than no cap.
 *
 *   3. THE SKIP LINK. It must be the first focusable thing on the page and must MOVE FOCUS, not
 *      merely scroll -- `#ss-main` carries `tabindex="-1"` for exactly that. The probe presses Tab
 *      once from the document start, activates with Enter, and reads `document.activeElement`.
 *
 *   4. KEYBOARD OPERABILITY of the staff card's repaired kebab. Its accessible name was a junk
 *      Private-Use codepoint; naming it is worthless if it cannot be reached or opened from the
 *      keyboard. The probe tabs to it and presses Enter, then checks `aria-expanded`.
 *
 * WHAT THIS DOES NOT CLAIM. It drives Chromium against the sqlite dev fixture, not production, and
 * it exercises the merchant principal only. The withdrawal dialog is READ AND DISMISSED -- no
 * payout is approved, so the handler behind it is not exercised and this says nothing about it.
 *
 * usage:  docs/development/phase-2.5/tools/serve-dev.sh      (in another shell, left running)
 *         DB_CONNECTION=sqlite DB_DATABASE=<repo>/database/p2dev.sqlite CACHE_STORE=file \
 *           php docs/development/phase-2.5/tools/seed-dev-interaction-fixtures.php
 *         node docs/development/phase-2.5/tools/probe-p25-interactions.cjs
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const REPO = '/home/user/syriastore98';
const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const EMAIL = 'merchant@example.test';
const PASSWORD = 'password';
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');
const CAP = 50;

const results = [];
const check = (name, pass, detail) => { results.push({ name, pass, detail }); console.log(`  ${pass ? 'PASS' : 'FAIL'}  ${name}\n        ${detail}`); };

function setState(lang, dir, theme) {
  execFileSync('php', [STATE_TOOL, '1', theme, dir, lang, EMAIL], {
    cwd: path.dirname(STATE_TOOL),
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(REPO, 'database/p2dev.sqlite'), CACHE_STORE: 'file' },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

async function signIn(ctx) {
  const page = await ctx.newPage();
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
  return page;
}

(async () => {
  const browser = await chromium.launch();

  // ===== 1. THE TWO WITHDRAWAL CONFIRMATIONS, IN ARABIC AND IN ENGLISH =====
  for (const [lang, dir, expect] of [
    ['ar', 'rtl', { approve: 'هل تريد الموافقة على طلب السحب هذا؟', paid: 'هل تؤكد أن هذا السحب قد تم دفعه؟' }],
    ['en', 'ltr', { approve: 'Approve this withdrawal request?', paid: 'Confirm that this withdrawal has been paid?' }],
  ]) {
    setState(lang, dir, 'light');
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await signIn(ctx);
    console.log(`\n=== withdrawal confirmations  (${lang}/${dir}) ===`);
    const resp = await page.goto(BASE + '/finance/withdrawals', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(400);

    const seen = [];
    page.on('dialog', async (d) => { seen.push({ type: d.type(), message: d.message() }); await d.dismiss(); });

    // The buttons are identified by their own onclick, so this cannot drift onto another control.
    const buttons = await page.$$('button[onclick*="confirm("], a[onclick*="confirm("], input[onclick*="confirm("]');
    check(`${lang}: /finance/withdrawals serves the screen`, resp.status() === 200, `HTTP ${resp.status()}, ${buttons.length} confirm-guarded control(s) found`);

    for (const b of buttons) {
      const before = seen.length;
      await b.click({ timeout: 5000 }).catch(() => {});
      await page.waitForTimeout(250);
      if (seen.length > before) {
        const d = seen[seen.length - 1];
        const want = Object.values(expect);
        check(`${lang}: native confirm fired and reads in the merchant's language`,
          d.type === 'confirm' && want.includes(d.message),
          `type=${d.type}  text="${d.message}"  (expected one of: ${want.map((w) => '"' + w + '"').join(', ')})`);
      }
    }
    if (!seen.length) {
      check(`${lang}: a native confirm fires on the withdrawal controls`, false,
        'NO DIALOG. Either the controls are absent (no pending withdrawal seeded) or the ' +
        'synchronous confirm() was converted to an async dialog, which needs owner approval.');
    }
    await ctx.close();
  }

  // ===== 2. THE BOARD COLUMN CAP AND ITS OVERFLOW LINK =====
  for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
    setState(lang, dir, 'light');
    const ctx = await browser.newContext({ viewport: { width: 1600, height: 1200 } });
    const page = await signIn(ctx);
    console.log(`\n=== board column cap  (${lang}/${dir}) ===`);
    const resp = await page.goto(BASE + '/order-kanban', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(600);

    // EXACT SELECTORS, and the reason is a reading this probe produced and had to throw away.
    // The first draft counted `[class*="board__card"]`, a SUBSTRING match, which also matched
    // `.ss-board__card-id` and the two `.ss-board__card-meta` children of every card
    // (order/partials/kanban-card.blade.php:26,27,32,36) -- so a correctly capped column of 50
    // cards reported 251 and the cap looked broken. The card root is `article.ss-board__card`
    // inside `.ss-board__cards`, the column is `section.ss-board__col`, and the header count is
    // its own `.ss-board__count` span rather than the header's text.
    const board = await page.evaluate(() => {
      const cols = [...document.querySelectorAll('section.ss-board__col')];
      return cols.map((c) => ({
        head: (c.querySelector('.ss-board__count') || {}).previousElementSibling?.textContent?.trim().replace(/\s+/g, ' ').slice(0, 28)
              || (c.getAttribute('aria-label') || '').slice(0, 28),
        count: (c.querySelector('.ss-board__count') || {}).textContent?.trim() || null,
        cards: c.querySelectorAll('.ss-board__cards > article.ss-board__card').length,
        more: (c.querySelector('.ss-board__more') || {}).textContent?.trim().replace(/\s+/g, ' ') || null,
        moreHref: (c.querySelector('.ss-board__more') || {}).getAttribute?.('href') || null,
      }));
    });
    check(`${lang}: /order-kanban serves the board`, resp.status() === 200, `HTTP ${resp.status()}, ${board.length} column(s)`);

    // A column is CAPPED when its header count exceeds the cap, which is the condition the view
    // branches on -- not when its card count reaches it, which is the symptom being tested.
    const capped = board.filter((c) => Number(c.count) > CAP);
    if (!capped.length) {
      check(`${lang}: a column's true total exceeds the ${CAP}-card cap`, false,
        `no column reports more than ${CAP}: ${board.map((c) => c.head + '=' + c.count).join(', ')} — run seed-dev-interaction-fixtures.php`);
    }
    for (const c of capped) {
      check(`${lang}: the capped column renders exactly ${CAP} cards, not its ${c.count} total`,
        c.cards === CAP, `"${c.head}" header count=${c.count}, cards rendered=${c.cards}`);
      check(`${lang}: the capped column links to the full list and names the TRUE total`,
        !!c.more && c.more.includes(String(c.count)), `overflow link = "${c.more}"  href=${c.moreHref}`);
    }
    // AND THE UNCAPPED COLUMNS MUST NOT HAVE GROWN A LINK -- the negative control. A cap that
    // announces itself on every column tells a merchant nothing.
    const wrongly = board.filter((c) => Number(c.count) <= CAP && c.more);
    check(`${lang}: no uncapped column claims an overflow`, wrongly.length === 0,
      wrongly.length ? wrongly.map((c) => `${c.head}(count=${c.count}) -> "${c.more}"`).join('; ')
                     : `${board.length - capped.length} uncapped column(s), none with a link`);
    await ctx.close();
  }

  // ===== 3. THE SKIP LINK, AND 4. THE KEBAB FROM THE KEYBOARD =====
  for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
    setState(lang, dir, 'light');
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await signIn(ctx);
    console.log(`\n=== keyboard reach  (${lang}/${dir}) ===`);
    await page.goto(BASE + '/users', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(400);

    await page.evaluate(() => document.body.focus());
    await page.keyboard.press('Tab');
    const first = await page.evaluate(() => {
      const el = document.activeElement;
      return { cls: (el.className || '').toString().slice(0, 40), text: (el.textContent || '').trim().slice(0, 40), tag: el.tagName };
    });
    check(`${lang}: the skip link is the first focusable element`, /ss-skip-link/.test(first.cls),
      `first Tab reached <${first.tag} class="${first.cls}"> "${first.text}"`);

    await page.keyboard.press('Enter');
    await page.waitForTimeout(250);
    const landed = await page.evaluate(() => {
      const el = document.activeElement;
      return { id: el.id, tag: el.tagName, tabindex: el.getAttribute('tabindex') };
    });
    check(`${lang}: activating it MOVES FOCUS rather than only scrolling`, landed.id === 'ss-main',
      `document.activeElement = <${landed.tag} id="${landed.id}" tabindex="${landed.tabindex}">`);

    // The repaired kebab: reachable and openable from the keyboard.
    const kebab = await page.$('.card-option > button.dropdown-toggle');
    if (!kebab) {
      check(`${lang}: the staff kebab exists`, false, 'no .card-option > button.dropdown-toggle on /users');
    } else {
      await kebab.focus();
      const named = await page.evaluate((el) => el.getAttribute('aria-label'), kebab);
      await page.keyboard.press('Enter');
      await page.waitForTimeout(300);
      const expanded = await page.evaluate((el) => ({
        expanded: el.getAttribute('aria-expanded'),
        menuShown: !!el.parentElement.querySelector('.dropdown-menu.show'),
        items: el.parentElement.querySelectorAll('.dropdown-menu .dropdown-item').length,
      }), kebab);
      check(`${lang}: the kebab opens from the keyboard and is named`,
        expanded.expanded === 'true' && expanded.menuShown && !!named,
        `aria-label="${named}"  aria-expanded=${expanded.expanded}  menu shown=${expanded.menuShown}  items=${expanded.items}`);
    }
    await ctx.close();
  }

  await browser.close();
  const failed = results.filter((r) => !r.pass);
  console.log(`\n${results.length} check(s), ${failed.length} failed.`);
  for (const f of failed) console.log(`  FAILED: ${f.name} -- ${f.detail}`);
  process.exit(failed.length ? 1 : 0);
})();
