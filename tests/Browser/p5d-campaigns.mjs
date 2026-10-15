/**
 * Browser verification of native campaign management (Phase 5D).
 *
 *   tests/Browser/run.sh p5d-campaigns
 *
 * WHAT IT PROVES AGAINST A RUNNING PAGE:
 *
 *   - the count shown BEFORE sending is the count the send will reach, and the page says
 *     plainly that everyone who cannot be messaged is already excluded. A merchant who reads
 *     a larger number and then sees a smaller one in the results has been misled by their
 *     own dashboard.
 *   - STOPPING IS NOT DELETING. A running campaign offers Stop, and stopping leaves the
 *     results reachable -- which is the whole reason it is not a delete.
 *   - a finished campaign cannot be edited or stopped, and a cancelled one says why it was.
 *   - the results name the REASON somebody was not reached, not just a count.
 *   - Arabic renders right to left and does not overflow at phone width.
 */
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '/opt/node-tools/node_modules/playwright/index.js')
    .then((m) => m.default || m);

const BASE = process.env.STUDIO_BASE || 'http://127.0.0.1:8899';
let fails = 0;
const check = (n, ok, d = '') => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? '  — ' + d : ''}`); if (!ok) fails++; };

const CAMPAIGNS = {
  campaigns: [
    { id: 51, display_id: 3, title: 'Spring sale', message: 'Hello', campaign_status: 'active',
      scheduled_at: '2026-04-01T09:00:00Z', audience: [{ type: 'Audience', id: 12 }], blocked_reason: null },
    { id: 52, display_id: 4, title: 'Half sent', message: 'Hi', campaign_status: 'processing',
      scheduled_at: null, audience: [{ type: 'Audience', id: 12 }], blocked_reason: null },
    { id: 53, display_id: 5, title: 'Stopped one', message: 'Hi', campaign_status: 'cancelled',
      scheduled_at: null, audience: [], blocked_reason: 'wrong audience' },
    { id: 54, display_id: 6, title: 'Done', message: 'Hi', campaign_status: 'completed',
      scheduled_at: null, audience: [], blocked_reason: null },
  ],
};

const AUDIENCES = { audiences: [{ id: 12, name: 'Repeat buyers', shared: true, payload: [] }] };

const RESULTS = {
  metrics: { audience: 180, status_counts: { sent: 40, delivered: 38, read: 20, failed: 2, skipped: 140 } },
  skip_reasons: [
    { reason: 'No marketing consent on record', count: 120 },
    { reason: 'Customer opted out', count: 15 },
    { reason: 'Contact is blocked', count: 5 },
  ],
  recipients: [
    { contact_name: 'Lina Haddad', status: 'sent', reason: null },
    { contact_name: 'Omar Nasri', status: 'skipped', reason: 'No marketing consent on record' },
  ],
  per_page: 25,
};

const browser = await chromium.launch();
const errors = [];
let posted = [];

async function open(page) {
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  await page.route('**/audiences/api', (r) => r.fulfill({ status: 200,
    contentType: 'application/json', body: JSON.stringify(AUDIENCES) }));
  await page.route('**/campaigns/api/preview', (r) => r.fulfill({ status: 200,
    contentType: 'application/json', body: JSON.stringify({ count: 40 }) }));
  await page.route('**/campaigns/api/*/results', (r) => r.fulfill({ status: 200,
    contentType: 'application/json', body: JSON.stringify(RESULTS) }));
  await page.route('**/campaigns/api/*/cancel', (r) => {
    posted.push({ method: r.request().method(), url: r.request().url() });

    return r.fulfill({ status: 200, contentType: 'application/json', body: '{"campaign":{}}' });
  });
  await page.route('**/campaigns/api', (r) => {
    if (r.request().method() === 'POST') {
      posted.push(Object.fromEntries(new URLSearchParams(r.request().postData() || '')));

      return r.fulfill({ status: 200, contentType: 'application/json', body: '{"campaign":{}}' });
    }

    return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(CAMPAIGNS) });
  });
}

// ======================================================= the list, English
{
  const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/campaigns-en.html`);
  await page.waitForSelector('.lync-camp');

  const cards = page.locator('.lync-camp');
  check('every campaign is listed', (await cards.count()) === 4, `${await cards.count()}`);

  const scheduled = (await cards.nth(0).textContent()).replace(/\s+/g, ' ');
  check('a scheduled campaign says when it is due',
    scheduled.includes('Scheduled') && scheduled.includes('due'), scheduled.slice(0, 90));

  const running = await cards.nth(1).locator('button').allTextContents();
  check('a running campaign can be stopped but not edited',
    running.includes('Stop') && !running.includes('Edit'), running.join(' | '));

  const cancelled = (await cards.nth(2).textContent()).replace(/\s+/g, ' ');
  check('a cancelled campaign says so and keeps the reason',
    cancelled.includes('Cancelled') && cancelled.includes('wrong audience'), cancelled.slice(0, 110));

  const finished = await cards.nth(3).locator('button').allTextContents();
  check('a finished campaign cannot be edited or stopped',
    !finished.includes('Edit') && !finished.includes('Stop') && finished.includes('Results'),
    finished.join(' | '));

  // STOPPING IS NOT DELETING. The results stay reachable afterwards, which is the point.
  check('a cancelled campaign still offers its results',
    (await cards.nth(2).locator('button').allTextContents()).includes('Results'));

  await context.close();
}

// ======================================================= the preview
{
  const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/campaigns-en.html`);
  await page.waitForSelector('.lync-camp');

  await page.locator('#lyncp-new').click();
  await page.waitForSelector('#lyncp-modal.show');
  await page.waitForFunction(() => /\d/.test(document.querySelector('#lyncp-preview').textContent));

  const preview = (await page.locator('#lyncp-preview').textContent()).replace(/\s+/g, ' ').trim();
  // THE NUMBER, AND WHAT IT MEANS.
  check('the count before sending is shown', preview.includes('40'), preview);
  check('and the page says the unreachable are already excluded',
    preview.includes('already excluded'), preview);

  // Guards before anything is sent.
  await page.locator('#lyncp-save').click();
  await page.waitForTimeout(100);
  check('a campaign with no name is refused before the request',
    (await page.locator('#lyncp-modal-errors').textContent()).includes('Give the campaign a name')
    && posted.length === 0, (await page.locator('#lyncp-modal-errors').textContent()).trim());

  await page.locator('#lyncp-title').fill('Spring sale');
  await page.locator('#lyncp-message').fill('Hello there.');
  await page.locator('#lyncp-save').click();
  await page.waitForTimeout(150);

  check('a complete campaign is sent with its audience', posted.length === 1
    && posted[0].title === 'Spring sale'
    && posted[0]['audience[0][type]'] === 'Audience', JSON.stringify(posted[0] || {}).slice(0, 140));

  await context.close();
}

// ======================================================= the results
{
  posted = [];
  const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/campaigns-en.html`);
  await page.waitForSelector('.lync-camp');

  await page.locator('.lync-camp').nth(0).locator('button', { hasText: 'Results' }).click();
  await page.waitForSelector('#lyncp-results.show');

  const metrics = (await page.locator('#lyncp-metrics').textContent()).replace(/\s+/g, ' ');
  check('the audience size and the outcome counts are shown',
    metrics.includes('180') && metrics.includes('40') && metrics.includes('140'), metrics.trim().slice(0, 100));

  // P5F: WHY, not just how many. The counters alone read as a failure.
  const skips = (await page.locator('#lyncp-skips').textContent()).replace(/\s+/g, ' ');
  check('the unsent are broken down by reason',
    skips.includes('120') && skips.includes('No marketing consent on record'), skips.trim().slice(0, 120));
  check('and the biggest reason is read first',
    skips.indexOf('120') < skips.indexOf('15'), skips.trim().slice(0, 120));

  const rows = (await page.locator('#lyncp-recipients').textContent()).replace(/\s+/g, ' ');
  // THE HALF A COUNT CANNOT TELL.
  check('a recipient who was not reached carries the reason',
    rows.includes('Omar Nasri') && rows.includes('No marketing consent on record'), rows.slice(0, 140));

  await context.close();
}

// ======================================================= Arabic at phone width
{
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await context.newPage();
  await open(page);
  await page.goto(`${BASE}/campaigns-ar.html`);
  await page.waitForSelector('.lync-camp');

  const dir = await page.evaluate(() => document.documentElement.getAttribute('dir'));
  check('[ar] the page is right to left', dir === 'rtl', String(dir));

  const list = (await page.locator('#lyncp-list').textContent()).replace(/\s+/g, ' ');
  check('[ar] the statuses are translated, not left in English',
    /[؀-ۿ]/.test(list) && !list.includes('Scheduled'), list.slice(0, 90));

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check('[ar] no horizontal overflow at phone width', overflow <= 1, `${overflow}px`);

  await context.close();
}

check('no page errors', errors.length === 0, errors.join(' | '));
await browser.close();
console.log(fails === 0 ? '\nALL CHECKS PASSED' : `\n${fails} CHECK(S) FAILED`);
process.exit(fails === 0 ? 0 : 1);
