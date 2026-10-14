/**
 * Browser verification of commerce automation and consent (Phase 4E).
 *
 *   php tests/Browser/render-studio-pages.php <dir> && serve <dir> && node tests/Browser/p4e-commerce-automation.mjs
 *
 * What it proves against a running page: a trigger that cannot fire for this store is shown and
 * REFUSED with its reason rather than hidden or silently offered; only the actions the server
 * describes are offerable, and on a commerce trigger only the ones it accepts; a consent
 * decision cannot be recorded without a note; and the consent tab is absent -- not merely
 * disabled -- for staff who may not use it.
 */
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '/opt/node-tools/node_modules/playwright/index.js')
    .then((m) => m.default || m);

const BASE = process.env.STUDIO_BASE || 'http://127.0.0.1:8899';
let fails = 0;
const check = (n, ok, d = '') => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? '  — ' + d : ''}`); if (!ok) fails++; };

const VOCABULARY = {
  events: [
    { name: 'conversation_created', available: true },
    { name: 'commerce_order_shipped', available: true },
    { name: 'commerce_cart_abandoned', available: false, reason: 'recovery_unavailable' },
  ],
  actions: [
    { name: 'send_message', commerce_safe: false, fields: [{ key: 'message', kind: 'text', max: 10000 }] },
    { name: 'add_label', commerce_safe: true, fields: [{ key: 'labels', kind: 'labels', required: true }] },
    { name: 'change_priority', commerce_safe: true,
      fields: [{ key: 'priority', kind: 'select', required: true, wrapped: true, values: ['low', 'high', 'urgent'] }] },
  ],
  conditions: [
    { key: 'commerce_order_status', scope: 'commerce', data_type: 'text', operators: ['equal_to'],
      values: ['shipped', 'delivered'], available: true },
    { key: 'status', scope: 'conversation', data_type: 'text', operators: ['equal_to', 'is_present'],
      values: ['open'], available: true },
  ],
  condition_patterns: [],
};

const RULES = [{
  id: 7, name: 'Tell them when it ships', description: null, event_name: 'commerce_order_shipped', active: true,
  conditions: [{ attribute_key: 'commerce_order_status', filter_operator: 'equal_to', values: ['shipped'] }],
  actions: [{ action_name: 'add_label', action_params: ['vip'] }], created_at: '2026-02-01T10:00:00Z',
}];

const CONSENT = {
  contact_id: 55, contact_name: 'Rasha',
  purposes: {
    transactional: { state: null, source: null, decided_at: null, note: null, refusal: null },
    promotional: { state: null, source: null, decided_at: null, note: null, refusal: 'promotional_consent_missing' },
  },
};

const browser = await chromium.launch();
const errors = [];
let posted = [], recorded = [];

async function open(page) {
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  await page.route('**/automation/api/rules', (r) => {
    if (r.request().method() === 'POST') { posted.push(JSON.parse(r.request().postData() || '{}')); return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); }
    return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ rules: RULES, vocabulary: VOCABULARY }) });
  });
  await page.route('**/automation/api/consents', (r) => r.fulfill({ status: 200, contentType: 'application/json',
    body: JSON.stringify({ decisions: [{ contact_id: 55, contact_name: 'Rasha', purpose: 'promotional', state: 'granted', source: 'checkout', decided_at: '2026-02-01T10:00:00Z' }] }) }));
  await page.route('**/automation/api/consents/contacts**', (r) => r.fulfill({ status: 200, contentType: 'application/json',
    body: JSON.stringify({ contacts: [{ id: 55, name: 'Rasha', identifier: '+963900000055' }] }) }));
  await page.route('**/automation/api/consents/55', (r) => {
    if (r.request().method() === 'PUT') { recorded.push(JSON.parse(r.request().postData() || '{}')); return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ consent: CONSENT }) }); }
    return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ consent: CONSENT }) });
  });
}

// ======================================================= rules, English
{
  const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/commerce-en.html`);
  await page.waitForSelector('.lync-rule');

  check('the rule list renders the stored rule', (await page.locator('.lync-rule').count()) === 1);
  const summary = await page.locator('.lync-rule').textContent();
  check('a rule reads as a sentence, not as raw keys',
    summary.includes('An order is shipped') && summary.includes('Add a label'), summary.replace(/\s+/g, ' ').slice(0, 90));

  await page.locator('#lync-new').click();
  await page.waitForSelector('#lync-rule-modal.show');

  // The trigger that cannot fire is present and refused, with the reason.
  const cart = page.locator('#lync-rule-event option[value="commerce_cart_abandoned"]');
  check('a trigger that cannot fire here is shown, not hidden', (await cart.count()) === 1);
  check('and it is disabled rather than offered', await cart.isDisabled());
  check('and it says why', (await cart.textContent()).includes('Abandoned baskets are not collected'),
    (await cart.textContent()).trim());

  await page.locator('#lync-rule-event').selectOption('commerce_order_shipped');
  await page.waitForTimeout(80);
  await page.locator('#lync-rule-actions button').last().click();   // Add action
  await page.waitForTimeout(80);

  const offered = await page.locator('#lync-rule-actions select').first().locator('option').allTextContents();
  check('a commerce trigger offers no free-form message action',
    !offered.some((o) => o.includes('message')) && offered.some((o) => o.includes('Add a label')), offered.join(' | '));

  await page.locator('#lync-rule-event').selectOption('conversation_created');
  await page.waitForTimeout(80);
  await page.locator('#lync-rule-actions button').last().click();
  await page.waitForTimeout(80);
  const conversational = await page.locator('#lync-rule-actions select').first().locator('option').count();
  check('a conversation trigger offers more actions than a commerce one', conversational === 3, `${conversational}`);

  // AN ACTION THE NEW TRIGGER FORBIDS IS DROPPED, NOT LEFT IN THE DRAFT. Before this, switching
  // back to a commerce trigger kept `send_message` in the rule while the select showed something
  // else -- the form said one thing and submitted another.
  await page.locator('#lync-rule-actions select').first().selectOption('send_message');
  await page.waitForTimeout(80);
  await page.locator('#lync-rule-event').selectOption('commerce_order_shipped');
  await page.waitForTimeout(120);
  const remaining = await page.locator('#lync-rule-actions select').allTextContents();
  check('switching to a commerce trigger drops an action it forbids',
    !remaining.join(' ').includes('message'), remaining.join(' | '));
  check('and says which action went',
    (await page.locator('#lync-rule-errors').textContent()).includes('was removed'),
    (await page.locator('#lync-rule-errors').textContent()).trim());

  await page.locator('#lync-rule-event').selectOption('conversation_created');
  await page.waitForTimeout(80);
  await page.locator('#lync-rule-actions button').last().click();
  await page.waitForTimeout(80);

  // An action the server did not describe is never in the list at all.
  check('an undescribed action is absent everywhere',
    !(await page.locator('#lync-rule-actions').textContent()).includes('send_attachment'));

  // A rule needs a name and at least one action.
  await page.locator('#lync-rule-name').fill('');
  await page.locator('#lync-rule-save').click();
  await page.waitForTimeout(80);
  check('a nameless rule is refused in the form', !(await page.locator('#lync-rule-errors').getAttribute('class')).includes('d-none'));

  await page.locator('#lync-rule-name').fill('Shipped note');
  await page.locator('#lync-rule-save').click();
  await page.waitForTimeout(250);
  check('a complete rule is sent with its trigger and action',
    posted.length === 1 && posted[0].event_name === 'conversation_created' && posted[0].actions.length >= 1,
    JSON.stringify(posted[0] && { e: posted[0].event_name, a: posted[0].actions.length }));

  await page.screenshot({ path: (process.env.SHOT_DIR || '/tmp') + '/shot-commerce-en.png', fullPage: true });
  await context.close();
}

// ======================================================= consent
{
  const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/commerce-en.html`);
  await page.waitForSelector('.lync-rule');

  await page.locator('#lync-tab-consent').click();
  await page.waitForTimeout(150);
  check('the recent-decisions log is filled', (await page.locator('#lync-consent-log tr').count()) === 1);

  await page.locator('#lync-consent-search').fill('ras');
  await page.locator('#lync-consent-go').click();
  await page.waitForTimeout(200);
  await page.locator('#lync-consent-results button').first().click();
  await page.waitForTimeout(200);

  const detail = await page.locator('#lync-consent-detail').textContent();
  check('a shopper shows both classes with what would happen now',
    detail.includes('Order updates') && detail.includes('Offers')
      && detail.includes('Will be sent') && detail.includes('Will not be sent'),
    detail.replace(/\s+/g, ' ').slice(0, 110));
  check('and it says why an offer would not be sent',
    detail.includes('have not agreed to offers'));

  // One persistent handler: a prompt that is refused is followed by an alert, and two `once`
  // handlers race each other for which dialog they catch.
  let nextNote = null;
  page.on('dialog', (d) => (d.type() === 'prompt' && nextNote !== null ? d.accept(nextNote) : d.dismiss()));

  // The note is not optional: dismissing the prompt records nothing.
  nextNote = null;
  await page.locator('#lync-consent-detail button').first().click();
  await page.waitForTimeout(250);
  check('cancelling the note records nothing', recorded.length === 0, JSON.stringify(recorded));

  // An empty note is refused with a message rather than sent.
  nextNote = '   ';
  await page.locator('#lync-consent-detail button').first().click();
  await page.waitForTimeout(300);
  check('an empty note is refused', recorded.length === 0, JSON.stringify(recorded));

  nextNote = 'Agreed on the phone, 12 Feb';
  await page.locator('#lync-consent-detail button').first().click();
  await page.waitForTimeout(300);
  check('a note travels with the decision',
    recorded.length === 1 && recorded[0].note === 'Agreed on the phone, 12 Feb' && recorded[0].state === 'granted',
    JSON.stringify(recorded[0]));

  await page.screenshot({ path: (process.env.SHOT_DIR || '/tmp') + '/shot-consent-en.png', fullPage: true });
  await context.close();
}

// ======================================================= the tab is absent, not disabled
{
  const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/commerce-noconsent-en.html`);
  await page.waitForSelector('.lync-rule');

  check('staff who may not record consent get no consent tab at all',
    (await page.locator('#lync-tab-consent').count()) === 0);
  check('and no consent pane is rendered for them either',
    (await page.locator('#lync-pane-consent').count()) === 0);
  await context.close();
}

// ======================================================= Arabic on a phone
{
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/commerce-ar.html`);
  await page.waitForSelector('.lync-rule');

  check('the page is right to left in Arabic', (await page.getAttribute('html', 'dir')) === 'rtl');
  const body = await page.locator('.lync-rule').textContent();
  check('a rule reads in Arabic', /[؀-ۿ]/.test(body), body.replace(/\s+/g, ' ').slice(0, 60));

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check('no horizontal page scroll on a 390px phone', overflow <= 1, `${overflow}px`);

  await page.locator('#lync-new').click();
  await page.waitForSelector('#lync-rule-modal.show');
  const arCart = await page.locator('#lync-rule-event option[value="commerce_cart_abandoned"]').textContent();
  check('the unavailable trigger explains itself in Arabic', /[؀-ۿ]/.test(arCart), arCart.trim());

  await page.screenshot({ path: (process.env.SHOT_DIR || '/tmp') + '/shot-commerce-mobile-ar.png', fullPage: true });
  await context.close();
}

await browser.close();
check('no uncaught JavaScript error', errors.length === 0, errors.slice(0, 3).join(' || '));
console.log(`\n${fails ? 'FAILURES: ' + fails : 'all checks passed'}`);
process.exit(fails ? 1 : 0);
