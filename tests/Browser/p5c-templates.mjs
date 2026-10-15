/**
 * Browser verification of native message template management (Phase 5C).
 *
 *   tests/Browser/run.sh p5c-templates
 *
 * WHAT IT PROVES AGAINST A RUNNING PAGE:
 *
 *   - ONLY THE ACTIONS THE SERVER ALLOWS ARE OFFERED. Meta's transition rules are intricate
 *     and they change; a page that kept its own copy would eventually offer an action that
 *     costs the merchant a review cycle. An approved template offers Duplicate and not
 *     Submit; a draft offers Submit and not Duplicate-only.
 *   - Meta's verdict is shown verbatim, with the AGE of the status beside it. "Approved"
 *     with no age could be from a minute ago or a week ago.
 *   - the validation problems are shown before a submission is spent, and the page does not
 *     claim an empty list means approval.
 *   - a template Meta no longer has is called out rather than lingering as approved.
 *   - the identity fields are locked on an edit, because at Meta they are the template's
 *     identity and changing them is a refusal the merchant cannot act on.
 *   - the category note says which consent rule the choice implies, before the campaign
 *     reaches nobody and the merchant has to work out why.
 *   - Arabic renders right to left and does not overflow at phone width.
 */
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '/opt/node-tools/node_modules/playwright/index.js')
    .then((m) => m.default || m);

const BASE = process.env.STUDIO_BASE || 'http://127.0.0.1:8899';
let fails = 0;
const check = (n, ok, d = '') => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? '  — ' + d : ''}`); if (!ok) fails++; };

const TEMPLATES = {
  templates: [
    { id: 31, name: 'order_shipped', language: 'ar', category: 'UTILITY',
      components: [{ type: 'BODY', text: 'Your order is on its way.' }],
      state: 'remote', meta_status: 'APPROVED', quality_score: 'GREEN',
      last_seen_at: 1760000000, missing_at_meta: false, inboxes: [{ id: 7, name: 'WhatsApp' }],
      allowed_actions: ['duplicate'], validation_problems: [] },
    { id: 32, name: 'spring_offer', language: 'ar', category: 'MARKETING',
      components: [{ type: 'BODY', text: 'Hello {{1}}, spring offers are here.' }],
      state: 'draft', meta_status: null, last_seen_at: null, missing_at_meta: false,
      inboxes: [{ id: 7, name: 'WhatsApp' }],
      allowed_actions: ['edit', 'submit', 'destroy'],
      validation_problems: ['The body must not end with a variable.'] },
    { id: 33, name: 'old_promo', language: 'ar', category: 'MARKETING',
      components: [{ type: 'BODY', text: 'Gone.' }],
      state: 'remote', meta_status: 'REJECTED', rejected_reason: 'INVALID_FORMAT',
      rejection_info: 'The body has a trailing variable.',
      last_seen_at: 1760000000, missing_at_meta: true, inboxes: [{ id: 7, name: 'WhatsApp' }],
      allowed_actions: ['edit', 'submit', 'destroy'], validation_problems: [] },
  ],
  waba_contexts: { '1234': { inboxes: [{ id: 7, name: 'WhatsApp' }] } },
};

const browser = await chromium.launch();
const errors = [];
let sent = [];

async function open(page) {
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  await page.route('**/templates/api', (r) => {
    if (r.request().method() === 'POST') {
      sent.push(Object.fromEntries(new URLSearchParams(r.request().postData() || '')));

      return r.fulfill({ status: 200, contentType: 'application/json', body: '{"template":{}}' });
    }

    return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(TEMPLATES) });
  });
  await page.route('**/templates/api/**', (r) => {
    sent.push({ method: r.request().method(), url: r.request().url() });

    return r.fulfill({ status: 200, contentType: 'application/json', body: '{"template":{}}' });
  });
}

// ======================================================= the list, English
{
  const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/templates-en.html`);
  await page.waitForSelector('.lynt-tpl');

  const cards = page.locator('.lynt-tpl');
  check('every template is listed', (await cards.count()) === 3, `${await cards.count()}`);

  const approved = (await cards.nth(0).textContent()).replace(/\s+/g, ' ');
  check('an approved template says so in words, not a code', approved.includes('Approved'), approved.slice(0, 80));
  check('and says when the status was last checked',
    approved.includes('status last checked') && /20\d\d/.test(approved), approved.slice(0, 160));

  // THE PROPERTY THE WHOLE PAGE RESTS ON.
  const approvedButtons = await cards.nth(0).locator('button').allTextContents();
  check('an approved template offers only what the server allows',
    approvedButtons.length === 1 && approvedButtons[0] === 'Duplicate', approvedButtons.join(' | '));

  const draftButtons = await cards.nth(1).locator('button').allTextContents();
  check('a draft offers the draft actions and not the others',
    draftButtons.includes('Send for review') && draftButtons.includes('Edit')
    && !draftButtons.includes('Duplicate'), draftButtons.join(' | '));

  const draft = (await cards.nth(1).textContent()).replace(/\s+/g, ' ');
  check('a validation problem is shown before a review is spent',
    draft.includes("WhatsApp's published rules say this will be refused")
    && draft.includes('must not end with a variable'), draft.slice(0, 200));
  check('a draft that WhatsApp has never seen says so',
    draft.includes('Draft') && draft.includes('not checked yet'), draft.slice(0, 120));

  const rejected = (await cards.nth(2).textContent()).replace(/\s+/g, ' ');
  check("Meta's refusal reason is shown verbatim",
    rejected.includes('INVALID_FORMAT') && rejected.includes('trailing variable'), rejected.slice(0, 180));
  check('a template WhatsApp no longer has is called out, not left reading approved',
    rejected.includes('no longer has this template'), rejected.slice(0, 220));

  await context.close();
}

// ======================================================= the editor
{
  const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/templates-en.html`);
  await page.waitForSelector('.lynt-tpl');

  await page.locator('#lynt-new').click();
  await page.waitForSelector('#lynt-modal.show');

  check('a new template can set its identity fields',
    !(await page.locator('#lynt-name').isDisabled()) && !(await page.locator('#lynt-category').isDisabled()));

  // The consent consequence of the category, said before a campaign reaches nobody.
  check('choosing Marketing says it needs consent',
    (await page.locator('#lynt-category-note').textContent()).includes('agreed to receive offers'),
    (await page.locator('#lynt-category-note').textContent()).trim());

  await page.locator('#lynt-category').selectOption('UTILITY');
  await page.waitForTimeout(80);
  check('choosing Order updates says it does not',
    (await page.locator('#lynt-category-note').textContent()).includes('asked you to stop'),
    (await page.locator('#lynt-category-note').textContent()).trim());

  // The empty-body guard, before anything is sent.
  await page.locator('#lynt-name').fill('spring_offer');
  await page.locator('#lynt-save').click();
  await page.waitForTimeout(100);
  check('an empty message is refused before the request',
    (await page.locator('#lynt-modal-errors').textContent()).includes('Write the message')
    && sent.length === 0, (await page.locator('#lynt-modal-errors').textContent()).trim());

  await page.locator('#lynt-body').fill('Hello there.');
  await page.locator('#lynt-save').click();
  await page.waitForTimeout(150);
  check('a complete draft is sent with its body', sent.length === 1
    && sent[0]['components[0][text]'] === 'Hello there.', JSON.stringify(sent[0] || {}).slice(0, 140));

  // The save already closed it -- clicking the X now would be clicking a hidden button.
  await page.waitForSelector('#lynt-modal.show', { state: 'hidden' });
  await page.waitForTimeout(250);

  // THE IDENTITY FIELDS ARE LOCKED ON AN EDIT. At Meta they are the template's identity, and
  // offering them would invite a refusal the merchant cannot act on.
  await page.locator('.lynt-tpl').nth(1).locator('button', { hasText: 'Edit' }).click();
  await page.waitForSelector('#lynt-modal.show');
  check('editing locks the name, language and category',
    (await page.locator('#lynt-name').isDisabled())
    && (await page.locator('#lynt-language').isDisabled())
    && (await page.locator('#lynt-category').isDisabled()));
  check('and still shows what they are, so the merchant can read them',
    (await page.locator('#lynt-name').inputValue()) === 'spring_offer');

  await context.close();
}

// ======================================================= Arabic at phone width
{
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/templates-ar.html`);
  await page.waitForSelector('.lynt-tpl');

  const dir = await page.evaluate(() => document.documentElement.getAttribute('dir'));
  check('[ar] the page is right to left', dir === 'rtl', String(dir));

  const list = (await page.locator('#lynt-list').textContent()).replace(/\s+/g, ' ');
  check('[ar] the statuses are translated, not left in English',
    /[؀-ۿ]/.test(list) && !list.includes('Approved'), list.slice(0, 90));

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check('[ar] no horizontal overflow at phone width', overflow <= 1, `${overflow}px`);

  await context.close();
}

check('no page errors', errors.length === 0, errors.join(' | '));
await browser.close();
console.log(fails === 0 ? '\nALL CHECKS PASSED' : `\n${fails} CHECK(S) FAILED`);
process.exit(fails === 0 ? 0 : 1);
