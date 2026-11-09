/**
 * P2.5-R-06 / R-07 — drive the customer GRID's numeric filters in the real browser.
 * =================================================================================================
 *
 * These were WRONG-ANSWER faults, so the only honest evidence is a before/after where the answer
 * visibly changes. Each case below is chosen so that the correct set and the buggy set are
 * DIFFERENT SIZES, which is what makes the screenshot evidence rather than decoration.
 *
 * Against `database/seed-p2dev.php` plus `seed-dev-customer-filter-fixtures.php`:
 *
 *     Layla Haddad   55 orders   550.00 spend   AOV  10.00
 *     Omar Khalil     2 orders   300.00 spend   AOV 150.00
 *     Nour Saleh      0 orders     0.00 spend   AOV  none
 *
 *   fault 1  AOV Equal 150              correct {Omar}          = 1    buggy {Layla,Omar}  = 2
 *            the `=` assignment matched every customer with ANY computable average
 *   fault 2  No. of Orders Less Than 3  correct {Omar,Nour}      = 2    buggy all three     = 3
 *            the duplicated branch testing `>` pulled Layla (55) into a LESS-THAN filter
 *   fault 3  No. of Orders More Than 2  correct {Layla}          = 1    buggy none          = 0
 *            there was no 'More Than' branch, so whereIn('id', []) matched nothing
 *   fault 4  Total Spend Equal 0        correct {Nour}           = 1    buggy all three     = 3
 *            empty("0") is TRUE in PHP, so the threshold was discarded and no filter ran
 *
 * A card count alone would not be enough -- 2 cards could be the right 2 or the wrong 2 -- so each
 * case also asserts WHICH customers remain, by name.
 *
 * usage:
 *   node verify-customer-grid-filter-live.cjs                 assert + capture into SHOTS
 *   SHOTS=/path EXPECT=before node ...                        capture only, no assertions
 *                                                             (for photographing the reverted tree)
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const REPO = '/home/user/syriastore98';
const STATE = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');
const SHOTS = process.env.SHOTS || path.join(REPO, 'docs/development/phase-2.5/evidence/r06-customer-filter/after');
const ASSERT = (process.env.EXPECT || 'after') !== 'before';

/*
 * SURFACE=grid (default) or SURFACE=list. The same four cases, driven against both screens,
 * because P2.5-R-06 repaired the filter in BOTH copies and the two had drifted apart.
 *
 * THE "BEFORE" ANSWERS DIFFER BY SURFACE, and that difference is the point:
 *   list   the inline filter ran and gave a WRONG SET per fault (2, 3, 0 and 3 rows)
 *   grid   customer_grid_view() read no parameters at all, so EVERY filter returned ALL THREE
 *          cards -- the filter did not merely answer wrongly, it did nothing
 */
const SURFACE = process.env.SURFACE === 'list' ? 'list' : 'grid';
const PATHNAME = SURFACE === 'list' ? '/customer' : '/customer-grid';
/*
 * `:not(:has(td[colspan]))` EXCLUDES THE EMPTY-STATE ROW, and that exclusion is load-bearing.
 * `buildDataTable()` gives this table a bespoke localised empty state, which yajra renders as ONE
 * <tr> carrying a single spanning cell. Counting raw <tr> would therefore score "no customers
 * match" as ONE result -- measured: fault 3's pre-fix run reported "1 item(s): No customers
 * yetCustomers appear here once someone registers...". A filter that returns nothing must count 0,
 * or the most important before/after difference in this set is invisible.
 */
const ITEM = SURFACE === 'list' ? '#customer-table tbody tr:not(:has(td[colspan]))' : '.user-card';
const TITLE = SURFACE === 'list' ? '#customer-table tbody tr a[href*="customer-timeline"]' : '.user-card .ss-card__title';

const CASES = [
  { key: 'aov-equal-150',      field: 'AOV',            op: 'Equal',     value: '150', expect: ['Omar'],         buggy: 2, fault: 1 },
  { key: 'orders-less-than-3', field: 'No. of Orders',  op: 'Less Than', value: '3',   expect: ['Omar', 'Nour'], buggy: 3, fault: 2 },
  { key: 'orders-more-than-2', field: 'No. of Orders',  op: 'More Than', value: '2',   expect: ['Layla'],        buggy: 0, fault: 3 },
  { key: 'spend-equal-0',      field: 'Total Spend',    op: 'Equal',     value: '0',   expect: ['Nour'],         buggy: 3, fault: 4 },
];

const COMBOS = [
  ['en', 'ltr', 'light'], ['en', 'ltr', 'dark'],
  ['ar', 'rtl', 'light'], ['ar', 'rtl', 'dark'],
];

function setState(theme, dir, lang) {
  execFileSync('php', [STATE, '1', theme, dir, lang, 'merchant@example.test'], {
    cwd: path.dirname(STATE),
    env: { ...process.env,
      DB_CONNECTION: 'sqlite',
      DB_DATABASE: path.join(REPO, 'database/p2dev.sqlite'),
      CACHE_STORE: 'file' },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

const cards = (page) => page.locator(ITEM).count();
const names = async (page) =>
  (await page.locator(TITLE).allTextContents()).map((s) => s.trim());

(async () => {
  fs.mkdirSync(SHOTS, { recursive: true });
  const browser = await chromium.launch();
  let failures = 0;
  const say = (ok, msg) => { if (!ok) failures++; console.log(`   ${ok ? 'PASS' : 'FAIL'}  ${msg}`); };

  for (const [lang, dir, theme] of COMBOS) {
    setState(theme, dir, lang);
    const combo = `${lang}_${dir}_${theme}`;
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();
    console.log(`\n=== ${combo} ===`);

    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', 'merchant@example.test');
    await page.fill('input[name="password"]', 'password');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);

    // PREMISE — the grid really drew, in the direction and theme we asked for.
    await page.goto(BASE + PATHNAME, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1600);   // the list draws through yajra's ajax

    const realDir = await page.evaluate(() => document.documentElement.getAttribute('dir'));
    say((realDir === 'rtl') === (dir === 'rtl'), `premise: document dir is ${realDir}`);

    const realTheme = await page.evaluate(() =>
      document.documentElement.getAttribute('data-ss-theme'));
    say(theme === 'dark' ? realTheme === 'dark' : realTheme !== 'dark',
      `premise: data-ss-theme is ${JSON.stringify(realTheme)}`);

    const unfiltered = await cards(page);
    say(unfiltered === 3, `premise: the unfiltered ${SURFACE} drew ${unfiltered} item(s) (expected 3)`);
    await page.screenshot({ path: path.join(SHOTS, `${SURFACE}-unfiltered_${combo}.png`), fullPage: true });

    for (const c of CASES) {
      // Driven through the URL, which is what the GET form produces -- and the fact that the
      // address carries the filter is itself half the repair (it is bookmarkable now).
      const url = `${BASE}${PATHNAME}?field_name=${encodeURIComponent(c.field)}`
        + `&selected_name=${encodeURIComponent(c.op)}&text_field=${encodeURIComponent(c.value)}`;
      await page.goto(url, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(SURFACE === 'list' ? 1600 : 700);

      const n = await cards(page);
      const shown = await names(page);
      const label = `${c.field} ${c.op} ${c.value}`;

      await page.screenshot({
        path: path.join(SHOTS, `${SURFACE}-${c.key}_${combo}.png`), fullPage: true });

      if (!ASSERT) {
        console.log(`   CAPTURED  fault ${c.fault}  ${label} -> ${n} item(s): ${shown.join(', ') || '(none)'}`);
        continue;
      }

      say(n === c.expect.length,
        `fault ${c.fault}: "${label}" -> ${n} item(s), expected ${c.expect.length} `
        + `(on the list the fault returned ${c.buggy}; on the grid, all 3)`);

      /*
       * A COUNT IS NOT ENOUGH: two cards could be the right two or the wrong two. So the GRID,
       * whose card title is a single clean element, is asserted by name here.
       *
       * THE LIST IS NOT, DELIBERATELY. Its identity cell is built by string concatenation with a
       * nested <a> and a <br> (CustomerDataTable:65-69 escapes each value individually rather
       * than the finished cell, precisely because of that markup), so a text scrape of it is not
       * a reliable identity. The list's name-level assertions are exact in
       * CustomerNumericFilterOperatorsTest, which compares the full first_name SET for every
       * operator. Here the list contributes its COUNT and its screenshot.
       */
      if (SURFACE === 'grid') {
        for (const want of c.expect) {
          say(shown.some((s) => s.includes(want)), `fault ${c.fault}: "${label}" kept ${want}`);
        }
        const extra = shown.filter((s) => !c.expect.some((w) => s.includes(w)));
        say(extra.length === 0,
          `fault ${c.fault}: "${label}" returned nobody it should not`
          + (extra.length ? ` (got: ${extra.join(', ')})` : ''));
      } else {
        say(shown.length === n,
          `the ${n} row(s) each carry a customer link (scraped ${shown.length})`);
      }

      // The filter in force is visible on the reloaded page -- the other half of a GET filter.
      const selectedField = await page.locator('#customer_field').inputValue();
      say(selectedField === c.field, `the field select shows ${JSON.stringify(selectedField)}`);
    }

    // Reset leaves the filter. A filter you cannot leave is its own defect.
    await page.goto(`${BASE}${PATHNAME}?field_name=${encodeURIComponent('Total Spend')}`
      + '&selected_name=Equal&text_field=0', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(500);
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#clearfilter')]);
    await page.waitForTimeout(700);
    const afterReset = await cards(page);
    if (ASSERT) {
      say(afterReset === 3, `Reset restored the ${SURFACE} to ${afterReset} item(s)`);
    } else {
      console.log(`   CAPTURED  reset -> ${afterReset} item(s)`);
    }

    await ctx.close();
  }

  await browser.close();
  console.log(`\ncaptures written to ${SHOTS}`);
  if (!ASSERT) { console.log('\nCAPTURE-ONLY RUN — no assertions were made.'); process.exit(0); }
  console.log(failures ? `\n${failures} CHECK(S) FAILED` : '\nALL CHECKS PASSED');
  process.exit(failures ? 1 : 0);
})();
