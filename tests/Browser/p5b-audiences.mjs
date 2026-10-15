/**
 * Browser verification of native audience management (Phase 5B).
 *
 *   tests/Browser/run.sh p5b-audiences
 *
 * WHAT IT PROVES AGAINST A RUNNING PAGE:
 *
 *   - BOTH COUNTS ARE SHOWN, and the difference between them is legible. "40 customers of
 *     this store" beside "180 across all your stores" is the single most useful thing this
 *     page can tell a merchant with more than one store; the scoped number alone just looks
 *     disappointing, and the unscoped one alone promises people a campaign cannot reach.
 *   - the condition vocabulary comes from the SERVER -- a currency the merchant has never
 *     taken an order in is not offered, and one they have is.
 *   - a presence condition offers no value box, because a value typed there is discarded.
 *   - changing the field resets the operator and the value rather than leaving a combination
 *     the engine will refuse.
 *   - the page never renders a contact: an audience is a count here, not a list.
 *   - a refusal from Lynomia is shown as its sentence, not swallowed.
 *   - Arabic renders right to left and does not overflow at phone width.
 */
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '/opt/node-tools/node_modules/playwright/index.js')
    .then((m) => m.default || m);

const BASE = process.env.STUDIO_BASE || 'http://127.0.0.1:8899';
let fails = 0;
const check = (n, ok, d = '') => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? '  — ' + d : ''}`); if (!ok) fails++; };

const AUDIENCES = [
  { id: 12, name: 'Repeat buyers', shared: true,
    payload: [{ attribute_key: 'commerce_orders_count', filter_operator: 'is_greater_than', values: ['3'] }] },
  { id: 13, name: 'Big spenders', shared: true,
    payload: [{ attribute_key: 'commerce_spend_syp', filter_operator: 'is_greater_than', values: ['500000'] }] },
];

const REFERENCES = {
  stores: [{ id: 55, name: 'Damascus', provider: 'storesyria', external_store_id: '4242' }],
  currencies: ['SYP', 'USD'],
  unread_contacts: 4,
  this_store: 55,
};

const browser = await chromium.launch();
const errors = [];
let saved = [];
let refuse = null;

async function open(page) {
  page.on('pageerror', (e) => errors.push(e.message));
  // A scripted refusal makes the browser log a 403, which is the behaviour under test rather
  // than a defect -- so the expected one is not counted. Anything else still is.
  page.on('console', (m) => {
    if (m.type() !== 'error') { return; }
    if (refuse && m.text().includes(String(refuse.status))) { return; }
    errors.push('console: ' + m.text());
  });
  await page.route('**/audiences/api/references', (r) => r.fulfill({ status: 200,
    contentType: 'application/json', body: JSON.stringify(REFERENCES) }));
  await page.route('**/audiences/api/preview', (r) => r.fulfill({ status: 200,
    contentType: 'application/json', body: JSON.stringify({ count: 40, count_all_stores: 180 }) }));
  await page.route('**/audiences/api', (r) => {
    if (r.request().method() === 'POST') {
      if (refuse) { return r.fulfill({ status: refuse.status, contentType: 'application/json', body: JSON.stringify({ error: refuse.error }) }); }
      // Form-encoded, not JSON: jQuery's $.ajax posts a plain object that way and so does
      // every other write in this dashboard. Parsed as the server parses it.
      saved.push(Object.fromEntries(new URLSearchParams(r.request().postData() || '')));

      return r.fulfill({ status: 200, contentType: 'application/json', body: '{"audience":{}}' });
    }

    return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ audiences: AUDIENCES }) });
  });
}

// ======================================================= the list, English
{
  const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/audiences-en.html`);
  await page.waitForSelector('.lyna-aud');

  check('every audience is listed', (await page.locator('.lyna-aud').count()) === 2);

  const first = (await page.locator('.lyna-aud').first().textContent()).replace(/\s+/g, ' ');
  check('an audience reads as a sentence, not as raw keys',
    first.includes('Number of orders') && first.includes('is more than') && first.includes('3'), first.slice(0, 100));

  await page.waitForFunction(() => {
    const cell = document.querySelector('[data-count-for]');

    return cell && /\d/.test(cell.textContent);
  });
  const counts = (await page.locator('.lyna-count').first().textContent()).replace(/\s+/g, ' ');
  // THE POINT OF THE PAGE.
  check('the count this store will actually reach is shown',
    counts.includes('40') && counts.includes('customers of this store'), counts.trim());
  check('and the account-wide count beside it, so the gap is visible',
    counts.includes('180') && counts.includes('across all your stores'), counts.trim());

  // An audience is a count here, never a list of people.
  const rendered = await page.locator('#lyna-list').textContent();
  check('no customer is named in the rendered list -- an audience is a count here, not a list',
    !/@/.test(rendered) && !/\+9\d{6}/.test(rendered), rendered.slice(0, 80));

  await context.close();
}

// ======================================================= the editor
{
  const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/audiences-en.html`);
  await page.waitForSelector('.lyna-aud');

  await page.locator('#lyna-new').click();
  await page.waitForSelector('#lyna-modal.show');

  check('the unread-contacts warning is shown, so a small number is explainable',
    (await page.locator('#lyna-unread').textContent()).includes('4'),
    (await page.locator('#lyna-unread').textContent()).trim());

  await page.locator('#lyna-add-condition').click();
  await page.waitForTimeout(80);

  const fields = await page.locator('#lyna-conditions select').first().locator('option').allTextContents();
  check('the currencies the merchant actually trades in are offered',
    fields.some((f) => f.includes('SYP')) && fields.some((f) => f.includes('USD')), fields.join(' | '));
  check('and a currency they have never taken an order in is not',
    !fields.some((f) => f.includes('EUR')), fields.join(' | '));

  // A presence check takes no value; offering a box invites one that is discarded.
  await page.locator('#lyna-conditions select').first().selectOption('commerce_store');
  await page.waitForTimeout(80);
  await page.locator('#lyna-conditions select').nth(1).selectOption('is_present');
  await page.waitForTimeout(80);
  check('a presence condition offers no value box',
    (await page.locator('#lyna-conditions input').count()) === 0
    && (await page.locator('#lyna-conditions').textContent()).includes('no value needed'));

  // Changing the field must reset the operator, or the form submits a pair the engine refuses.
  await page.locator('#lyna-conditions select').first().selectOption('commerce_orders_count');
  await page.waitForTimeout(80);
  const operators = await page.locator('#lyna-conditions select').nth(1).locator('option').allTextContents();
  check('changing the field resets the operators to the ones that field takes',
    !operators.some((o) => o.includes('is set')), operators.join(' | '));

  // The empty-value guard, before anything is sent. The name is filled first so that this
  // is the value guard firing rather than the name guard.
  await page.locator('#lyna-name').fill('Repeat buyers');
  await page.locator('#lyna-save').click();
  await page.waitForTimeout(100);
  const guard = (await page.locator('#lyna-modal-errors').textContent()).trim();
  check('a condition with no value is refused before the request',
    guard.includes('Fill in every condition') && saved.length === 0, guard);

  await page.locator('#lyna-conditions input').first().fill('3');
  await page.locator('#lyna-save').click();
  await page.waitForTimeout(150);

  check('a complete audience is sent with its conditions', saved.length === 1
    && saved[0]['payload[0][attribute_key]'] === 'commerce_orders_count'
    && saved[0]['payload[0][values][]'] === '3', JSON.stringify(saved[0] || {}).slice(0, 160));

  await context.close();
}

// ======================================================= a refusal is shown, not swallowed
{
  refuse = { status: 403, error: 'Only the store owner can manage audiences.' };
  const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/audiences-en.html`);
  await page.waitForSelector('.lyna-aud');

  await page.locator('#lyna-new').click();
  await page.waitForSelector('#lyna-modal.show');
  await page.locator('#lyna-name').fill('Theirs');
  await page.locator('#lyna-add-condition').click();
  await page.waitForTimeout(80);
  // The first field offered is a store, which renders a select; switch to a numeric one so
  // there is a text input to fill.
  await page.locator('#lyna-conditions select').first().selectOption('commerce_orders_count');
  await page.waitForTimeout(80);
  await page.locator('#lyna-conditions input').first().fill('3');
  await page.locator('#lyna-save').click();
  await page.waitForTimeout(150);

  const shown = (await page.locator('#lyna-modal-errors').textContent()).trim();
  check('a refusal from Lynomia is shown as its own sentence',
    shown.includes('store owner'), shown);
  check('and the dialog stays open so the work is not lost',
    await page.locator('#lyna-modal').evaluate((el) => el.classList.contains('show')));

  refuse = null;
  await context.close();
}

// ======================================================= Arabic at phone width
{
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/audiences-ar.html`);
  await page.waitForSelector('.lyna-aud');
  await page.waitForFunction(() => {
    const cell = document.querySelector('[data-count-for]');

    return cell && /\d/.test(cell.textContent);
  });

  const dir = await page.evaluate(() => document.documentElement.getAttribute('dir'));
  check('[ar] the page is right to left', dir === 'rtl', String(dir));

  const text = (await page.locator('#lyna-list').textContent()).replace(/\s+/g, ' ');
  check('[ar] the list is translated, not left in English',
    /[؀-ۿ]/.test(text) && !text.includes('customers of this store'), text.slice(0, 100));

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check('[ar] no horizontal overflow at phone width', overflow <= 1, `${overflow}px`);

  await context.close();
}

check('no page errors', errors.length === 0, errors.join(' | '));
await browser.close();
console.log(fails === 0 ? '\nALL CHECKS PASSED' : `\n${fails} CHECK(S) FAILED`);
process.exit(fails === 0 ? 0 : 1);
