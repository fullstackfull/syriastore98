/**
 * P2.5-R-02 — drive the customer list's Apply button in the real browser.
 *
 * The repair is a <form method="GET">, and the only honest evidence that a form works is a click
 * that narrows a real list. This signs in, reads the unfiltered row count, fills the filter, clicks
 * Apply, and checks four things that a half-working fix would fail separately:
 *
 *   1. a navigation happened and the url carries field_name/selected_name/text_field
 *   2. the row count went DOWN (not to zero, and not unchanged)
 *   3. the row that remains is the one that was asked for
 *   4. the filter controls on the reloaded page show the filter that is in force
 *
 * ...and then that Reset returns the full list, because a filter you cannot leave is its own
 * defect. Run in both directions: a GET form in an RTL document is the case that breaks.
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8899';
const REPO = '/home/user/syriastore98';
const STATE = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');
const MATCH = process.env.MATCH || 'Omar';
const SHOTS = process.env.SHOTS || '/tmp/p25-customer-filter';

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

const rowCount = (page) => page.locator('#customer-table tbody tr').count();

(async () => {
  const browser = await chromium.launch();
  let failures = 0;
  const say = (ok, msg) => { if (!ok) failures++; console.log(`   ${ok ? 'PASS' : 'FAIL'}  ${msg}`); };

  for (const [lang, dir, theme] of [['en', 'ltr', 'light'], ['ar', 'rtl', 'dark']]) {
    setState(theme, dir, lang);
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    console.log(`\n=== ${lang}/${dir}/${theme} ===`);

    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', 'merchant@example.test');
    await page.fill('input[name="password"]', 'password');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);

    await page.goto(BASE + '/customer', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);

    // PREMISE — the direction really is what we asked for, and the table really drew.
    const realDir = await page.evaluate(() => document.documentElement.getAttribute('dir'));
    say((realDir === 'rtl') === (dir === 'rtl'), `premise: document dir is ${realDir}`);
    const before = await rowCount(page);
    say(before > 1, `premise: the unfiltered list drew ${before} rows`);

    await page.selectOption('#customer_field', 'Name');
    await page.waitForTimeout(900);
    await page.selectOption('#select-container select', 'Includes');
    await page.fill('#text-field input', MATCH);

    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.click('#applyfilter'),
    ]);
    await page.waitForTimeout(1800);

    const url = new URL(page.url());
    say(url.searchParams.get('field_name') === 'Name'
      && url.searchParams.get('selected_name') === 'Includes'
      && url.searchParams.get('text_field') === MATCH,
      `the url carries the filter: ${url.search || '(none)'}`);

    const after = await rowCount(page);
    say(after > 0 && after < before, `rows ${before} -> ${after}`);

    const text = (await page.locator('#customer-table tbody').innerText().catch(() => '')) || '';
    say(text.includes(MATCH), `the remaining row is the one asked for (contains "${MATCH}")`);

    // The controls show the filter that is in force.
    say((await page.locator('#customer_field').inputValue()) === 'Name', 'the field select shows Name');
    say((await page.locator('#select-container select').inputValue().catch(() => '')) === 'Includes',
      'the operator select was restored to Includes');
    say((await page.locator('#text-field input').inputValue().catch(() => '')) === MATCH,
      `the value input was restored to "${MATCH}"`);

    await page.screenshot({ path: `${SHOTS}-${lang}-${theme}-filtered.png` }).catch(() => {});

    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#clearfilter')]);
    await page.waitForTimeout(1500);
    say((await rowCount(page)) === before, `Reset restored the list to ${before} rows`);

    await ctx.close();
  }

  await browser.close();
  console.log(`\n${failures === 0 ? 'ALL CHECKS PASSED' : failures + ' CHECK(S) FAILED'}`);
  process.exit(failures ? 1 : 0);
})();
