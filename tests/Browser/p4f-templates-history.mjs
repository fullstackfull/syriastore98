/**
 * Browser verification of templates and execution history (Phase 4F).
 *
 *   php tests/Browser/render-studio-pages.php <dir> && serve <dir> && node tests/Browser/p4f-templates-history.mjs
 *
 * What it proves against a running page: a template the workspace cannot use is listed with what
 * it needs rather than hidden, using one creates a flow AND saves its graph before opening the
 * builder, and the history screen reports outcomes and the reason runs stopped in words rather
 * than engine codes.
 */
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '/opt/node-tools/node_modules/playwright/index.js')
    .then((m) => m.default || m);

const BASE = process.env.STUDIO_BASE || 'http://127.0.0.1:8899';
let fails = 0;
const check = (n, ok, d = '') => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? '  — ' + d : ''}`); if (!ok) fails++; };

const STARTERS = [
  { id: 'welcome_menu', nodes: 5, available: true, missing: [],
    graph: { nodes: [{ id: 'start', type: 'start', position: { x: 0, y: 0 }, data: {} },
                     { id: 'menu', type: 'buttons', position: { x: 0, y: 180 }, data: { text: 'Hello!', options: [{ id: 'a', title: 'A' }] } }],
             edges: [{ id: 'e', source: 'start', sourceHandle: 'next', target: 'menu' }] } },
  { id: 'order_status', nodes: 6, available: false, missing: ['lynomia_commerce'],
    graph: { nodes: [], edges: [] } },
];

const HISTORY = {
  insights: { since: '2026-01-05T00:00:00Z', total: 7, live: 1,
    by_status: { completed: 2, handed_off: 1, failed: 3, waiting: 1 },
    by_failure: { step_limit: 2, send_limit: 1 }, versions: { 1: 7 } },
  sessions: [
    { id: 1, status: 'failed', current_node_id: 'menu', steps_count: 12, failure_code: 'step_limit',
      end_reason: null, version: 1, created_at: '2026-02-01T10:00:00Z', finished_at: null },
    { id: 2, status: 'handed_off', current_node_id: 'menu', steps_count: 3, failure_code: null,
      end_reason: 'no_choice', version: 1, created_at: '2026-02-01T09:00:00Z', finished_at: null },
  ],
};

const browser = await chromium.launch();
const errors = [];
let created = [], drafted = [];

// ======================================================= the template gallery
{
  const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await context.newPage();
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });

  await page.route('**/automation/api/flows', (r) => {
    if (r.request().method() === 'POST') {
      created.push(JSON.parse(r.request().postData() || '{}'));
      return r.fulfill({ status: 200, contentType: 'application/json',
        body: JSON.stringify({ flow: { id: 91, name: 'Welcome and menu', state: 'draft', live_sessions: 0,
                                       published: null, draft: null, inboxes: [] } }) });
    }
    return r.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ flows: [], usage: { used: 0, limit: 10 } }) });
  });
  await page.route('**/automation/api/starters', (r) => r.fulfill({ status: 200, contentType: 'application/json',
    body: JSON.stringify({ starters: STARTERS }) }));
  await page.route('**/automation/api/flows/91/draft', (r) => {
    drafted.push(JSON.parse(r.request().postData() || '{}'));
    return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' });
  });
  // The navigation at the end of the happy path.
  await page.route('**/automation/91/builder', (r) => r.fulfill({ status: 200, contentType: 'text/html', body: '<p>builder</p>' }));

  await page.goto(`${BASE}/automation-en.html`);
  await page.waitForSelector('#lyn-studio-templates:not([disabled])');

  await page.locator('#lyn-studio-templates').click();
  await page.waitForSelector('#lyn-template-modal.show');
  await page.waitForTimeout(250);

  const rows = await page.locator('#lyn-template-list > div').count();
  check('every template is listed, usable or not', rows === 2, `${rows} rows`);

  const blocked = await page.locator('#lyn-template-list > div').nth(1);
  check('a template this workspace cannot use says what it needs',
    (await blocked.textContent()).includes('Needs store automation'), (await blocked.textContent()).trim().slice(0, 80));
  check('and offers no button that would fail', (await blocked.locator('button').count()) === 0);

  const usable = page.locator('#lyn-template-list > div').nth(0);
  check('a usable template describes itself in words, not node types',
    (await usable.textContent()).includes('Greets the customer'), (await usable.textContent()).trim().slice(0, 70));

  await usable.locator('button').click();
  await page.waitForTimeout(400);

  check('using a template creates a flow', created.length === 1 && !!created[0].name, JSON.stringify(created[0]));
  // The whole point: the template's GRAPH is saved, not just an empty flow with a nice name.
  check('and saves the template graph as its first draft',
    drafted.length === 1 && drafted[0].lock_version === 0 && drafted[0].graph.nodes.length === 2,
    JSON.stringify(drafted[0] && { lock: drafted[0].lock_version, nodes: drafted[0].graph.nodes.length }));

  await context.close();
}

// ======================================================= the history screen
{
  const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await context.newPage();
  page.on('pageerror', (e) => errors.push(e.message));

  await page.route('**/automation/api/references', (r) => r.fulfill({ status: 200, contentType: 'application/json',
    body: '{"agents":[],"teams":[],"labels":[],"inboxes":[],"contact_attributes":[],"conversation_attributes":[],"vocabulary":{"conditions":[],"condition_patterns":[]}}' }));
  await page.route('**/automation/api/flows/42/builder', (r) => r.fulfill({ status: 200, contentType: 'application/json',
    body: JSON.stringify({
      flow: { id: 42, name: 'Welcome', state: 'published', live_sessions: 1, published: null,
              draft: { lock_version: 0 }, inboxes: [], channel_eligibility: [], errors: [] },
      graph: { nodes: [{ id: 'start', type: 'start', position: { x: 40, y: 40 }, data: {} }], edges: [] },
      catalogue: { node_types: { start: { outputs: ['next'], data: [] } }, node_fields: { start: [] },
                   store_scopes: [], capabilities: {}, variables: [], templates: [] },
    }) }));
  await page.route('**/automation/api/flows/42/history', (r) => r.fulfill({ status: 200, contentType: 'application/json',
    body: JSON.stringify(HISTORY) }));

  await page.goto(`${BASE}/builder-en.html`);
  await page.waitForSelector('.lynb-node');
  await page.locator('#lynb-history').click();
  await page.waitForSelector('#lynb-history-modal.show');
  await page.waitForTimeout(250);

  const counts = await page.locator('#lynb-history-counts').textContent();
  check('the outcome counts are shown', counts.includes('7') && counts.includes('Finished'), counts.replace(/\s+/g, ' ').slice(0, 90));

  const failures = await page.locator('#lynb-history-failures').textContent();
  check('why runs stopped is a sentence, not an engine code',
    failures.includes('check for a loop') && !failures.includes('step_limit'), failures.replace(/\s+/g, ' ').slice(0, 90));

  const rows = await page.locator('#lynb-history-rows tr').count();
  check('the recent runs are listed', rows === 2, `${rows} rows`);

  const firstRow = await page.locator('#lynb-history-rows tr').first().textContent();
  check('a run says what stopped it in the same words',
    firstRow.includes('Stopped on a problem') && firstRow.includes('check for a loop'),
    firstRow.replace(/\s+/g, ' ').trim());

  const secondRow = await page.locator('#lynb-history-rows tr').nth(1).textContent();
  check('a handover says it was deliberate', secondRow.includes('did not choose'), secondRow.replace(/\s+/g, ' ').trim());

  await page.screenshot({ path: (process.env.SHOT_DIR || '/tmp') + '/shot-history-en.png', fullPage: true });
  await context.close();
}

// ======================================================= Arabic on a phone
{
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true });
  const page = await context.newPage();
  page.on('pageerror', (e) => errors.push('ar: ' + e.message));

  await page.route('**/automation/api/flows', (r) => r.fulfill({ status: 200, contentType: 'application/json',
    body: JSON.stringify({ flows: [], usage: { used: 0, limit: null } }) }));
  await page.route('**/automation/api/starters', (r) => r.fulfill({ status: 200, contentType: 'application/json',
    body: JSON.stringify({ starters: STARTERS }) }));

  await page.goto(`${BASE}/automation-ar.html`);
  await page.waitForSelector('#lyn-studio-templates:not([disabled])');
  await page.locator('#lyn-studio-templates').click();
  await page.waitForSelector('#lyn-template-modal.show');
  await page.waitForTimeout(250);

  const arabic = await page.locator('#lyn-template-list').textContent();
  check('the template gallery is in Arabic', /[؀-ۿ]/.test(arabic), arabic.replace(/\s+/g, ' ').slice(0, 60));
  check('and says what the blocked one needs, in Arabic', arabic.includes('تتطلب'));

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check('no horizontal page scroll on a phone', overflow <= 1, `${overflow}px`);

  await page.screenshot({ path: (process.env.SHOT_DIR || '/tmp') + '/shot-templates-mobile-ar.png', fullPage: true });
  await context.close();
}

await browser.close();
check('no uncaught JavaScript error', errors.length === 0, errors.slice(0, 3).join(' || '));
console.log(`\n${fails ? 'FAILURES: ' + fails : 'all checks passed'}`);
process.exit(fails ? 1 : 0);
