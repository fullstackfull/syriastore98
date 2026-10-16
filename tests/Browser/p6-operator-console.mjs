/**
 * Browser verification of the Phase 6 operator console (P6B lifecycle, P6A/P6C health, P6E notices).
 *
 *   tests/Browser/run.sh p6-operator-console
 *
 * The first non-merchant page in this harness, and it is here because Phase 6 put four of its
 * workstreams on one Super Admin screen whose correctness is a property of the rendered DOM
 * rather than of any controller:
 *
 *   - WHICH CONTROLS EXIST FOR WHICH STATE. Every transition is a separate POST form rendered
 *     by a Blade branch. A branch that leaks -- Decommission beside a live installation, Restore
 *     beside a terminal one -- is an operator ending a working merchant's integration by
 *     clicking the control the page offered them. Nothing server-side catches a control that
 *     should not have been drawn, because the request it sends is a legitimate one.
 *   - THAT NONE OF THEM IS A LINK. A GET that suspends is a transition that happens when a
 *     crawler, a prefetch or a pasted URL touches it.
 *   - THAT DECOMMISSION ASKS FIRST, and that refusing it does nothing. It is the one transition
 *     with no way back.
 *   - THAT AN UNREACHABLE LYNOMIA READS AS UNREACHABLE. Decision 4 of the Phase 6 approval
 *     requires verified data, not fabricated health states: a failed fetch must print the
 *     failure, never a status word, and the rest of the page -- which is read from local tables
 *     precisely for this case -- must still be there.
 *   - THAT THE ARABIC PAGE IS ARABIC. The remote-health column's own two labels shipped
 *     untranslated and rendered in English beside Arabic headings; this is where that was found.
 */
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '/opt/node-tools/node_modules/playwright/index.js')
    .then((m) => m.default || m);

const BASE = process.env.STUDIO_BASE || 'http://127.0.0.1:8899';
let fails = 0;
const check = (n, ok, d = '') => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? '  — ' + d : ''}`); if (!ok) fails++; };

// The fixture's rows, in the order render-studio-pages.php builds them.
const ROWS = [
  { store: 11, state: 'active' },
  { store: 12, state: 'suspended' },
  { store: 13, state: 'revoked' },
  { store: 14, state: 'pending_verification' },
  { store: 15, state: 'decommissioned' },
];

// Every lifecycle route the page can post to, and the one state each belongs beside.
const CONTROLS = ['approve', 'suspend', 'resume', 'revoke', 'restore', 'decommission', 'reauthenticate'];
const ALLOWED = {
  active: ['suspend', 'revoke'],
  suspended: ['resume', 'reauthenticate', 'decommission'],
  revoked: ['restore'],
  pending_verification: ['approve'],
  decommissioned: [],
};

const browser = await chromium.launch();
const errors = [];

const watch = (page) => {
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
};

// The forms a row offers, named by the route each posts to.
const controlsFor = (page, index) => page.evaluate((i) => {
  const row = document.querySelectorAll('#lynomia-accounts tbody tr')[i];
  return [...row.querySelectorAll('form')].map((f) => ({
    action: f.getAttribute('action') || '',
    method: (f.getAttribute('method') || 'get').toLowerCase(),
    csrf: !!f.querySelector('input[name="_token"]'),
    required: [...f.querySelectorAll('[required]')].map((e) => e.getAttribute('name')),
    confirms: !!f.getAttribute('onsubmit'),
    valid: f.checkValidity(),
  }));
}, index);

// ====================================================== lifecycle controls, English
{
  const context = await browser.newContext({ viewport: { width: 1500, height: 1000 } });
  const page = await context.newPage();
  watch(page);
  await page.goto(`${BASE}/superadmin-integration-en.html`);
  await page.locator('a[href="#lynomia-accounts"]').click();
  await page.waitForSelector('#lynomia-accounts tbody tr');

  const rows = page.locator('#lynomia-accounts tbody tr');
  check('every connected installation is listed', (await rows.count()) === ROWS.length, `${await rows.count()}`);

  for (const [i, row] of ROWS.entries()) {
    const forms = await controlsFor(page, i);
    const offered = CONTROLS.filter((c) => forms.some((f) => f.action.endsWith(`/${c}`)));
    const expected = ALLOWED[row.state];

    check(`[${row.state}] offers exactly ${expected.length ? expected.join(' + ') : 'no control'}`,
      offered.length === expected.length && expected.every((c) => offered.includes(c)),
      `offered: ${offered.join(', ') || 'none'}`);

    // A transition reachable by GET happens to anything that follows links.
    check(`[${row.state}] every control posts and carries a CSRF token`,
      forms.every((f) => f.method === 'post' && f.csrf),
      forms.map((f) => `${f.method}${f.csrf ? '+csrf' : '-csrf'}`).join(' '));
  }

  // THE TERMINAL STATE. "Ended", not a disabled button and not a Restore that would 500.
  const ended = (await rows.nth(4).textContent()).replace(/\s+/g, ' ');
  check('a decommissioned installation reads as ended rather than as restorable',
    ended.includes('Ended') && !ended.includes('Restore'), ended.slice(-80));

  // A reason the next operator can read, demanded by the browser rather than by the server.
  for (const [i, state, control] of [[0, 'active', 'suspend'], [1, 'suspended', 'resume'], [1, 'suspended', 'decommission']]) {
    const form = (await controlsFor(page, i)).find((f) => f.action.includes(`/${control}`));
    check(`[${state}] ${control} will not submit without a reason`,
      form.required.includes('reason') && form.valid === false,
      `required: ${form.required.join(',')} valid: ${form.valid}`);
  }

  // Approving a claim needs the code the merchant was shown, not just a click.
  const approve = (await controlsFor(page, 3)).find((f) => f.action.includes('/approve'));
  check('[pending_verification] approval needs the merchant\'s code',
    approve.required.includes('verification_code') && approve.valid === false);

  await context.close();
}

// ====================================================== decommission asks first
{
  const context = await browser.newContext({ viewport: { width: 1500, height: 1000 } });
  const page = await context.newPage();
  watch(page);

  let asked = null;
  page.on('dialog', async (d) => { asked = d.message(); await d.dismiss(); });

  await page.goto(`${BASE}/superadmin-integration-en.html`);
  const before = page.url();
  await page.locator('a[href="#lynomia-accounts"]').click();
  await page.waitForSelector('#lynomia-accounts tbody tr');

  const form = page.locator('#lynomia-accounts tbody tr').nth(1).locator('form[action*="/decommission"]');
  await form.locator('input[name="reason"]').fill('merchant ended the contract');
  await form.locator('button').click();
  await page.waitForTimeout(300);

  check('decommissioning asks before it happens', asked !== null, String(asked).slice(0, 70));
  check('and says it cannot be undone',
    (asked || '').includes('cannot be undone'), (asked || '').slice(0, 90));
  check('refusing the question changes nothing', page.url() === before, page.url());

  await context.close();
}

// ====================================================== the far side, reached and unreachable
for (const [name, fulfil, expect] of [
  ['reachable', (r) => r.fulfill({ status: 200, contentType: 'application/json',
    body: JSON.stringify({ status: 'critical', signals: { open: 3 }, installations: [{}, {}] }) }),
    { says: 'Critical', notSays: 'Could not reach' }],
  ['unreachable', (r) => r.fulfill({ status: 502, contentType: 'application/json', body: JSON.stringify({}) }),
    { says: 'Could not reach Lynomia', notSays: 'Healthy' }],
  ['refused', (r) => r.abort(),
    { says: 'Could not reach Lynomia', notSays: 'Healthy' }],
]) {
  const context = await browser.newContext({ viewport: { width: 1500, height: 1000 } });
  const page = await context.newPage();
  page.on('pageerror', (e) => errors.push(e.message));
  await page.route('**/remote-health', fulfil);
  await page.goto(`${BASE}/superadmin-integration-en.html`);
  await page.locator('a[href="#lynomia-accounts"]').click();
  await page.waitForSelector('#lynomia-accounts tbody tr');

  const cell = page.locator('#lynomia-accounts tbody tr').nth(0).locator('.lyn-health-out');
  check(`[${name}] nothing is claimed about Lynomia before an operator asks`,
    (await cell.textContent()).trim() === '');

  await page.locator('#lynomia-accounts tbody tr').nth(0).locator('.lyn-health-check').click();
  await page.waitForFunction(() => {
    const out = document.querySelectorAll('#lynomia-accounts tbody tr')[0].querySelector('.lyn-health-out');
    return out.textContent.trim() !== '' && !out.textContent.includes('Checking');
  }, null, { timeout: 5000 });

  const text = (await cell.textContent()).replace(/\s+/g, ' ').trim();
  check(`[${name}] the cell reports what actually happened`, text.includes(expect.says), text);
  check(`[${name}] and never invents a health state`, !text.includes(expect.notSays), text);

  if (name === 'reachable') {
    check('[reachable] Lynomia\'s own counts are shown, not recomputed',
      text.includes('2') && text.includes('3'), text);
  } else {
    // The reason the whole page is built from local tables.
    check(`[${name}] the rest of the page is still there`,
      (await page.locator('#lynomia-accounts tbody tr').count()) === ROWS.length);
  }

  await context.close();
}

// ====================================================== merchant notices (P6E)
{
  const context = await browser.newContext({ viewport: { width: 1500, height: 1000 } });
  const page = await context.newPage();
  watch(page);
  await page.goto(`${BASE}/superadmin-integration-en.html`);
  await page.locator('a[href="#lynomia-notices"]').click();
  await page.waitForSelector('#lynomia-notices tbody tr');

  const pane = (await page.locator('#lynomia-notices').textContent()).replace(/\s+/g, ' ');
  check('the sent, undelivered and unresolved counts are all stated',
    pane.includes('48') && pane.includes('3') && pane.includes('1'), pane.slice(0, 120));

  // A notice whose answer was lost is the one an operator has to decide about.
  check('a notice with no known outcome raises a warning rather than counting as sent',
    (await page.locator('#lynomia-notices .alert-warning').count()) === 1
      && pane.includes('nobody knows whether they arrived'));

  const rows = page.locator('#lynomia-notices tbody tr');
  check('every recent notice is listed', (await rows.count()) === 3, `${await rows.count()}`);

  const failed = (await rows.nth(1).textContent()).replace(/\s+/g, ' ');
  check('an undelivered notice says so and names why',
    failed.includes('failed') && failed.includes('placeholder_phone'), failed);

  const lost = (await rows.nth(2).textContent()).replace(/\s+/g, ' ');
  check('a notice with no answer is not shown as sent',
    lost.includes('indeterminate') && !lost.includes('sent'), lost);

  // Counts, never people: this tab reports other tenants' staff.
  await page.locator('a[href="#lynomia-automation"]').click();
  const automation = (await page.locator('#lynomia-automation').textContent()).replace(/\s+/g, ' ');
  check('the access tab says it reports counts and not people',
    automation.includes('never the people'), automation.slice(0, 100));

  await context.close();
}

// ====================================================== Arabic, at phone width
{
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await context.newPage();
  watch(page);
  await page.route('**/remote-health', (r) => r.fulfill({ status: 200, contentType: 'application/json',
    body: JSON.stringify({ status: 'critical', signals: { open: 3 }, installations: [{}, {}] }) }));
  await page.goto(`${BASE}/superadmin-integration-ar.html`);

  check('[ar] the page is right to left',
    (await page.evaluate(() => document.documentElement.getAttribute('dir'))) === 'rtl');

  await page.locator('a[href="#lynomia-accounts"]').click();
  await page.waitForSelector('#lynomia-accounts tbody tr');

  const accounts = (await page.locator('#lynomia-accounts').textContent()).replace(/\s+/g, ' ');
  // THE REGRESSION THIS FILE WAS WRITTEN AFTER. Every control and every column heading the
  // Phase 6 work added, named individually, because a whole-pane Arabic test passes while two
  // labels sit in English among them.
  for (const english of ['Suspend', 'Resume', 'Decommission', 'Re-authenticate', 'Restore', 'Ended', 'Check', 'In Lynomia']) {
    check(`[ar] "${english}" is translated`, !accounts.includes(english),
      accounts.length > 0 ? '' : 'empty pane');
  }
  check('[ar] and the pane is genuinely Arabic', /[؀-ۿ]/.test(accounts));

  // The far side's verdict, said in the operator's language.
  await page.locator('#lynomia-accounts tbody tr').nth(0).locator('.lyn-health-check').click();
  await page.waitForFunction(() => {
    const out = document.querySelectorAll('#lynomia-accounts tbody tr')[0].querySelector('.lyn-health-out');
    return out.textContent.trim() !== '' && !/[A-Za-z]/.test(out.textContent) || out.textContent.includes('—');
  }, null, { timeout: 5000 });
  const health = (await page.locator('#lynomia-accounts tbody tr').nth(0).locator('.lyn-health-out').textContent()).trim();
  check('[ar] the health verdict is Arabic, not Lynomia\'s English state word',
    /[؀-ۿ]/.test(health) && !/critical/i.test(health), health);

  await page.locator('a[href="#lynomia-notices"]').click();
  const notices = (await page.locator('#lynomia-notices').textContent()).replace(/\s+/g, ' ');
  check('[ar] the notices tab is translated',
    /[؀-ۿ]/.test(notices) && !notices.includes('Notices sent'), notices.slice(0, 80));

  const overflow = await page.evaluate(() =>
    document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check('[ar] the page does not overflow sideways at phone width', overflow <= 1, `${overflow}px`);

  await context.close();
}

check('no page errors', errors.length === 0, errors.join(' | '));
await browser.close();
console.log(fails === 0 ? '\nALL CHECKS PASSED' : `\n${fails} CHECK(S) FAILED`);
process.exit(fails === 0 ? 0 : 1);
