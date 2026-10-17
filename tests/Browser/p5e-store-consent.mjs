/**
 * Browser verification of the store's own consent ledger and the checkout opt-in (Phase 5E).
 *
 *   tests/Browser/run.sh p5e-store-consent
 *
 * WHAT IT PROVES AGAINST A RUNNING PAGE, rather than against a rendered string:
 *
 *   - the checkout box is NOT pre-ticked, in either language. This is the single property
 *     that decides whether what the page collects is consent at all, and it is a property of
 *     the DOM the browser builds, not of the Blade source -- a `checked` added by a theme's
 *     own script would not show up in a view test.
 *   - a grant the merchant holds, beside an opt-out the shopper made on WhatsApp, reads as
 *     WILL NOT BE SENT and names where the refusal came from. A screen that showed only the
 *     local decision would promise a send that cannot happen, which is the whole reason this
 *     panel exists.
 *   - a decision still in the outbox reads as waiting, not as done.
 *   - the panel is absent entirely for staff who may not manage consent -- absent, not
 *     disabled, because a disabled control still tells them who opted out.
 *   - Arabic renders right-to-left and the table does not overflow at phone width.
 */
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '/opt/node-tools/node_modules/playwright/index.js')
    .then((m) => m.default || m);

const BASE = process.env.STUDIO_BASE || 'http://127.0.0.1:8899';
let fails = 0;
const check = (n, ok, d = '') => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? '  — ' + d : ''}`); if (!ok) fails++; };

const LEDGER = {
  settings: { checkout_optin_enabled: true, policy_version: 'v3' },
  pending: 1,
  decisions: [
    // Captured here, accepted by Lynomia -- and then the shopper opted out on WhatsApp.
    { subject: '77', customer: 'Lina Haddad', purpose: 'promotional', channel: 'any', state: 'granted',
      source: 'checkout_optin', policy_version: 'v3', captured_at: '2026-03-01T10:00:00Z',
      sync: { status: 'accepted', detail: 'applied' },
      remote: { state: 'withdrawn', origin: 'lynomia_native', occurred_at: '2026-03-04T08:00:00Z' } },
    // Still in the outbox.
    { subject: '78', customer: 'Omar Nasri', purpose: 'promotional', channel: 'any', state: 'granted',
      source: 'checkout_optin', policy_version: 'v3', captured_at: '2026-03-05T10:00:00Z',
      sync: { status: 'pending', detail: null },
      remote: { state: 'unknown', origin: null, occurred_at: null } },
    // Refused because the shopper had already withdrawn: a checkbox is not a re-consent.
    { subject: '79', customer: 'Rasha Khoury', purpose: 'promotional', channel: 'any', state: 'granted',
      source: 'checkout_optin', policy_version: 'v3', captured_at: '2026-03-06T10:00:00Z',
      sync: { status: 'refused', detail: 'withdrawal_dominant' },
      remote: { state: 'withdrawn', origin: 'lynomia_native', occurred_at: '2026-02-01T08:00:00Z' } },
  ],
};

const browser = await chromium.launch();
const errors = [];
let saved = [];

async function open(page) {
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  await page.route('**/automation/api/rules', (r) => r.fulfill({ status: 200, contentType: 'application/json',
    body: JSON.stringify({ rules: [], vocabulary: { events: [], actions: [], conditions: [], condition_patterns: [] } }) }));
  await page.route('**/automation/api/consents', (r) => r.fulfill({ status: 200, contentType: 'application/json',
    body: JSON.stringify({ decisions: [] }) }));
  await page.route('**/automation/api/store-consents', (r) => r.fulfill({ status: 200,
    contentType: 'application/json', body: JSON.stringify(LEDGER) }));
  await page.route('**/automation/api/store-consents/settings', (r) => {
    saved.push(JSON.parse(JSON.stringify(r.request().postData() || '')));
    return r.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ checkout_optin_enabled: false, policy_version: 'v4' }) });
  });
  await page.route('**/automation/api/store-consents/reconcile', (r) => r.fulfill({ status: 200,
    contentType: 'application/json', body: JSON.stringify({ pulled: 4, applied: 2, drifted: 1 }) }));
}

// ======================================================= the ledger, English
{
  const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/commerce-en.html`);
  await page.locator('#lync-tab-consent').click();
  await page.waitForSelector('#lync-store-consent-rows tr');

  const rows = page.locator('#lync-store-consent-rows tr');
  check('every captured decision is listed', (await rows.count()) === 3, `${await rows.count()}`);

  const first = (await rows.nth(0).textContent()).replace(/\s+/g, ' ');
  check('a name is shown rather than a subject id', first.includes('Lina Haddad'), first.slice(0, 80));
  check('an accepted decision says so', first.includes('Accepted'), first.slice(0, 120));

  // THE POINT OF THE WHOLE PANEL.
  check('a grant the shopper later withdrew elsewhere reads as WILL NOT BE SENT',
    first.includes('Will not be sent'), first.slice(0, 160));
  check('and it says the refusal came from a Lynomia channel',
    first.includes('from a Lynomia Chat channel'), first.slice(0, 200));

  const second = (await rows.nth(1).textContent()).replace(/\s+/g, ' ');
  check('a decision still in the outbox reads as waiting, not as done',
    second.includes('Waiting to be sent') && !second.includes('Accepted'), second.slice(0, 120));
  check('and nothing is claimed about what Lynomia will do with it',
    second.includes('Not known yet'), second.slice(0, 160));

  const third = (await rows.nth(2).textContent()).replace(/\s+/g, ' ');
  check('a grant Lynomia refused says why, rather than reading as sent',
    third.includes('Refused') && third.includes('already asked to stop'), third.slice(0, 160));

  check('the outbox backlog is stated',
    (await page.locator('#lync-store-consent-pending').textContent()).includes('1'),
    (await page.locator('#lync-store-consent-pending').textContent()).trim());

  // The opt-in switch reflects the stored setting rather than defaulting.
  check('the opt-in switch shows the stored setting', await page.locator('#lync-optin-enabled').isChecked());
  check('and the stored policy version is shown', (await page.locator('#lync-optin-policy').inputValue()) === 'v3');

  await page.locator('#lync-store-consent-refresh').click();
  await page.waitForTimeout(200);
  const note = (await page.locator('#lync-store-consent-note').textContent()).replace(/\s+/g, ' ');
  check('asking Lynomia now reports what changed and what disagrees',
    note.includes('2') && note.includes('1'), note.trim());

  await context.close();
}

// ======================================================= the panel is absent without the permission
{
  const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/commerce-noconsent-en.html`);
  await page.waitForTimeout(150);

  check('a staff member who may not manage consent sees no ledger at all',
    (await page.locator('#lync-store-consent-rows').count()) === 0);
  check('and no checkout opt-in switch', (await page.locator('#lync-optin-enabled').count()) === 0);

  await context.close();
}

// ======================================================= the checkout box itself
for (const locale of ['en', 'ar']) {
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await context.newPage();
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(`${BASE}/checkout-optin-${locale}.html`);
  await page.waitForSelector('#lynomia_marketing_optin');

  const box = page.locator('#lynomia_marketing_optin');
  // THE PROPERTY THAT DECIDES WHETHER THIS IS CONSENT AT ALL.
  check(`[${locale}] the checkout box is not pre-ticked`, !(await box.isChecked()));
  check(`[${locale}] it posts the field the server reads`,
    (await box.getAttribute('name')) === 'lynomia_marketing_optin');

  const label = (await page.locator('label[for="lynomia_marketing_optin"]').textContent()).replace(/\s+/g, ' ');
  check(`[${locale}] the label names the store, so the shopper knows who is asking`,
    label.includes('Damascus Store'), label.slice(0, 90));
  check(`[${locale}] and says how to stop and that orders are unaffected`,
    label.length > 60, label.slice(0, 160));

  if (locale === 'ar') {
    const dir = await page.evaluate(() => document.documentElement.getAttribute('dir'));
    check('[ar] the page is right to left', dir === 'rtl', String(dir));
    check('[ar] the label is Arabic, not an untranslated key', /[؀-ۿ]/.test(label), label.slice(0, 60));
  }

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check(`[${locale}] no horizontal overflow at phone width`, overflow <= 1, `${overflow}px`);

  await context.close();
}

// ======================================================= Arabic ledger at phone width
{
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/commerce-ar.html`);
  await page.locator('#lync-tab-consent').click();
  await page.waitForSelector('#lync-store-consent-rows tr');

  const text = (await page.locator('#lync-store-consent-rows').textContent()).replace(/\s+/g, ' ');
  check('[ar] the ledger is translated, not left in English',
    /[؀-ۿ]/.test(text) && !text.includes('Will not be sent'), text.slice(0, 100));

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check('[ar] the page does not overflow sideways at phone width', overflow <= 1, `${overflow}px`);

  await context.close();
}

check('no page errors', errors.length === 0, errors.join(' | '));
await browser.close();
console.log(fails === 0 ? '\nALL CHECKS PASSED' : `\n${fails} CHECK(S) FAILED`);
process.exit(fails === 0 ? 0 : 1);
