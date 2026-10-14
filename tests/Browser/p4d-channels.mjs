/**
 * Browser verification of the channel panel (Phase 4D).
 *
 *   php tests/Browser/render-studio-pages.php <dir> && serve <dir> && node tests/Browser/p4d-channels.mjs
 *
 * Found and now pins two defects: a channel that cannot run an automation must be LISTED and
 * said to be unable rather than hidden, and Bootstrap swallows a modal shown while another is
 * still hiding -- which made Connect on a taken channel close the panel and do nothing.
 */
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '/opt/node-tools/node_modules/playwright/index.js')
    .then((m) => m.default || m);

const BASE = process.env.STUDIO_BASE || 'http://127.0.0.1:8899';
let fails = 0;
const check = (n, ok, d = '') => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? '  — ' + d : ''}`); if (!ok) fails++; };

const CHANNELS = [
  { id: 9, name: 'WhatsApp', channel_type: 'Channel::Whatsapp', runs_flows: true,
    flow: { id: 42, name: 'Welcome menu', state: 'published' }, occupied_by_other: false },
  { id: 12, name: 'Live chat', channel_type: 'Channel::WebWidget', runs_flows: false, flow: null, occupied_by_other: false },
  { id: 13, name: 'Second WhatsApp', channel_type: 'Channel::Whatsapp', runs_flows: true,
    flow: { id: 77, name: 'Order updates', state: 'published' }, occupied_by_other: false },
  { id: 14, name: 'Spare WhatsApp', channel_type: 'Channel::Whatsapp', runs_flows: true, flow: null, occupied_by_other: false },
];

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
let connects = [], disconnects = [];

await page.route('**/automation/api/references', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: '{"agents":[],"teams":[],"labels":[],"inboxes":[],"contact_attributes":[],"conversation_attributes":[],"vocabulary":{"conditions":[],"condition_patterns":[]}}' }));
await page.route('**/automation/api/flows/42/builder', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
  flow: { id: 42, name: 'Welcome menu', state: 'draft', live_sessions: 0, published: null,
          draft: { lock_version: 1, version: 1, status: 'draft', published_at: null, updated_at: null },
          inboxes: [], channel_eligibility: [], errors: [] },
  graph: { nodes: [{ id: 'start', type: 'start', position: { x: 60, y: 40 }, data: {} }], edges: [] },
  catalogue: { node_types: { start: { outputs: ['next'], data: [] } }, node_fields: { start: [] }, store_scopes: [], capabilities: {}, variables: [], templates: [] },
}) }));
await page.route('**/automation/api/channels', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ channels: CHANNELS }) }));
await page.route('**/automation/api/flows/42/channels', (r) => { connects.push(JSON.parse(r.request().postData() || '{}')); return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); });
await page.route('**/automation/api/channels/*', (r) => { disconnects.push(r.request().url()); return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); });

await page.goto(`${BASE}/builder-en.html`);
await page.waitForSelector('.lynb-node');
await page.locator('#lynb-channels').click();
await page.waitForSelector('#lynb-channels-modal.show');
await page.waitForTimeout(250);

const rows = await page.locator('#lynb-channels-list > div').count();
check('every channel is listed, including the ones that cannot run a flow', rows === 4, `${rows} rows`);

const cannot = await page.locator('#lynb-channels-list > div').nth(1).textContent();
check('a channel that cannot run automations says so', cannot.includes('cannot run automations'), cannot.trim());

const cannotButtons = await page.locator('#lynb-channels-list > div').nth(1).locator('button').count();
check('no connect button is offered where it would not work', cannotButtons === 0);

const connectedRow = await page.locator('#lynb-channels-list > div').nth(0);
check('the channel running this automation offers Disconnect',
  (await connectedRow.locator('button').textContent()) === 'Disconnect');

const busyRow = page.locator('#lynb-channels-list > div').nth(2);
check('a channel running a different automation names it',
  (await busyRow.textContent()).includes('Order updates'));

// Connecting to a free channel goes straight through.
await page.locator('#lynb-channels-list > div').nth(3).locator('button').click();
await page.waitForTimeout(250);
check('connecting a free channel sends the inbox id',
  connects.length === 1 && connects[0].inbox_id === 14, JSON.stringify(connects));

// Connecting to a taken channel asks first, and names what would be turned off.
await busyRow.locator('button').click();
await page.waitForSelector('#lynb-confirm-modal.show');
const confirmBody = await page.locator('#lynb-confirm-body').textContent();
check('replacing another automation is confirmed, and names it', confirmBody.includes('Order updates'), confirmBody.trim());
// CONFIRMING IS DELIBERATELY NOT SYNCHRONOUS. The handler waits for `hidden.bs.modal` before it acts,
// so that reopening the Channels panel cannot race the confirm dialog's own teardown -- which would
// otherwise reset the scrollbar and drop `modal-open` from <body> underneath the panel that just
// opened. So these wait for the dialog to actually be gone rather than for a fixed number of
// milliseconds, which is both the real condition and not a number that goes stale on a slower box.
await page.locator('#lynb-confirm-ok').click();
await page.waitForSelector('#lynb-confirm-modal', { state: 'hidden' });
await page.waitForTimeout(200);
check('confirming the replacement connects', connects.length === 2 && connects[1].inbox_id === 13);

// AND THE PANEL SURVIVES THE DIALOG IT WAS REOPENED BEHIND. Bootstrap tears a modal's backdrop down
// well after hide() returns, and that teardown drops `modal-open` from <body> and resets the
// scrollbar. Reopening the panel before it finished meant the page behind the open panel became
// scrollable and the layout shifted, a few hundred milliseconds after the merchant clicked.
await page.waitForSelector('#lynb-channels-modal.show');
await page.waitForTimeout(600);
const bodyState = await page.evaluate(() => ({
  open: document.body.classList.contains('modal-open'),
  panel: document.getElementById('lynb-channels-modal').classList.contains('show'),
}));
check('the reopened panel is not torn down by the dialog it replaced',
  bodyState.open && bodyState.panel, JSON.stringify(bodyState));

// Disconnecting asks too.
await page.locator('#lynb-channels-list > div').nth(0).locator('button').click();
await page.waitForSelector('#lynb-confirm-modal.show');
check('disconnecting is confirmed', (await page.locator('#lynb-confirm-body').textContent()).includes('stop being answered'));
await page.locator('#lynb-confirm-ok').click();
await page.waitForSelector('#lynb-confirm-modal', { state: 'hidden' });
await page.waitForTimeout(200);
check('confirming the disconnect clears the channel',
  disconnects.length === 1 && disconnects[0].endsWith('/channels/9'), disconnects.join(','));

await page.screenshot({ path: (process.env.SHOT_DIR || '/tmp') + '/shot-channels-en.png' });
await context.close();

// Arabic
const arContext = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true });
const arPage = await arContext.newPage();
arPage.on('pageerror', (e) => errors.push('ar: ' + e.message));
await arPage.route('**/automation/api/references', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: '{"agents":[],"teams":[],"labels":[],"inboxes":[],"contact_attributes":[],"conversation_attributes":[],"vocabulary":{"conditions":[],"condition_patterns":[]}}' }));
await arPage.route('**/automation/api/flows/42/builder', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
  flow: { id: 42, name: 'ترحيب', state: 'draft', live_sessions: 0, published: null,
          draft: { lock_version: 1 }, inboxes: [], channel_eligibility: [], errors: [] },
  graph: { nodes: [{ id: 'start', type: 'start', position: { x: 60, y: 40 }, data: {} }], edges: [] },
  catalogue: { node_types: { start: { outputs: ['next'], data: [] } }, node_fields: { start: [] }, store_scopes: [], capabilities: {}, variables: [], templates: [] },
}) }));
await arPage.route('**/automation/api/channels', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ channels: CHANNELS }) }));
await arPage.goto(`${BASE}/builder-ar.html`);
await arPage.waitForSelector('.lynb-node');
await arPage.locator('#lynb-channels').click();
await arPage.waitForSelector('#lynb-channels-modal.show');
await arPage.waitForTimeout(250);
const arText = await arPage.locator('#lynb-channels-list').textContent();
check('the channel panel is in Arabic', /[؀-ۿ]/.test(arText), arText.slice(0, 60).replace(/\s+/g, ' '));
const arOverflow = await arPage.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
check('the channel panel does not overflow a phone', arOverflow <= 1, `${arOverflow}px`);
await arPage.screenshot({ path: (process.env.SHOT_DIR || '/tmp') + '/shot-channels-mobile-ar.png', fullPage: true });
await arContext.close();

await browser.close();
check('no uncaught JavaScript error', errors.length === 0, errors.slice(0, 3).join(' || '));
console.log(`\n${fails ? 'FAILURES: ' + fails : 'all checks passed'}`);
process.exit(fails ? 1 : 0);
