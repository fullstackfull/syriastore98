// Playwright is a tool on the machine, not a dependency of this repository: there is no working
// JS build here and adding one for a test harness would be a worse trade than resolving it.
// Override with PLAYWRIGHT_MODULE when it lives somewhere else.
// A DYNAMIC IMPORT because the specifier is configurable: Playwright is a tool on the machine,
// not a dependency of this repository -- there is no working JS build here and adding one for a
// test harness would be a worse trade than resolving it at run time.
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '/opt/node-tools/node_modules/playwright/index.js')
    .then((module) => module.default || module);
import { writeFileSync } from 'node:fs';

const BASE = process.env.STUDIO_BASE || 'http://127.0.0.1:8899';
const results = [];
let failures = 0;

function check(name, ok, detail = '') {
  results.push({ name, ok, detail });
  if (!ok) failures++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
}

// ---------------------------------------------------------------- fixtures
const FLOW = {
  id: 42, name: 'Welcome menu', description: 'First reply on WhatsApp', state: 'draft',
  live_sessions: 0,
  published: null,
  draft: { version: 4, status: 'draft', published_at: null, updated_at: '2026-02-02T10:00:00Z', lock_version: 5 },
  inboxes: [{ id: 9, name: 'WhatsApp', channel_type: 'Channel::Whatsapp' }],
  channel_eligibility: [{ id: 9, name: 'WhatsApp', channel_type: 'Channel::Whatsapp', runs_flows: true, unsupported_nodes: ['send_template'] }],
  errors: [{ code: 'unconnected_output', node_id: 'menu', edge_id: null, detail: 'track' }],
};

const CATALOGUE = {
  node_types: {
    start: { outputs: ['next'], data: ['keywords', 'conditions'] },
    send_message: { outputs: ['next'], data: ['text'] },
    question: { outputs: ['reply', 'invalid', 'timeout'], optional: ['invalid', 'timeout'], wait: true,
                data: ['text', 'reply_type', 'keywords', 'store_as', 'max_attempts', 'retry_text', 'timeout_minutes'] },
    buttons: { outputs: 'options', extra: ['other', 'timeout'], optional: ['other', 'timeout'], wait: true,
               data: ['text', 'options', 'timeout_minutes'] },
    condition: { outputs: ['true', 'false'], data: ['conditions'] },
    add_label: { outputs: ['next'], data: ['labels'] },
    delay: { outputs: ['next'], wait: true, data: ['seconds'] },
    end: { outputs: [], data: ['resolve'] },
  },
  node_fields: {
    start: [{ key: 'keywords', kind: 'words', max_items: 20, max_length: 64 },
            { key: 'conditions', kind: 'conditions', max_items: 10, scope: 'any' }],
    send_message: [{ key: 'text', kind: 'text', required: true, max: 4096, variables: 'all' }],
    question: [
      { key: 'text', kind: 'text', required: true, max: 4096, variables: 'all' },
      { key: 'reply_type', kind: 'select', values: ['any', 'number', 'email', 'phone', 'keywords'], default: 'any' },
      { key: 'keywords', kind: 'words', max_items: 20, max_length: 64, required_when: { key: 'reply_type', value: 'keywords' } },
      { key: 'store_as', kind: 'store_target' },
      { key: 'max_attempts', kind: 'number', min: 1, max: 5, default: 3 },
      { key: 'retry_text', kind: 'text', max: 4096, variables: 'all' },
      { key: 'timeout_minutes', kind: 'number', min: 1, max: 1440 },
    ],
    buttons: [
      { key: 'text', kind: 'text', required: true, max: 1024, variables: 'all' },
      { key: 'options', kind: 'options', required: true, max_items: 3, title_max: 20, description_max: 0 },
      { key: 'timeout_minutes', kind: 'number', min: 1, max: 1440 },
    ],
    condition: [{ key: 'conditions', kind: 'conditions', required: true, max_items: 10, scope: 'any' }],
    add_label: [{ key: 'labels', kind: 'labels', required: true, max_items: 10 }],
    delay: [{ key: 'seconds', kind: 'number', required: true, min: 1, max: 86400 }],
    end: [{ key: 'resolve', kind: 'boolean' }],
  },
  store_scopes: ['contact', 'conversation', 'context'],
  capabilities: { text: { body: 4096 }, buttons: { max: 3, title: 20, body: 1024 } },
  variables: ['contact.name', 'contact.email', 'flow.reply'],
  templates: [],
};

const GRAPH = {
  nodes: [
    { id: 'start', type: 'start', position: { x: 140, y: 40 }, data: {} },
    { id: 'menu', type: 'buttons', position: { x: 140, y: 220 },
      data: { text: 'How can we help?', options: [{ id: 'track', title: 'Track order' }, { id: 'care', title: 'Care' }] } },
    { id: 'bye', type: 'end', position: { x: 140, y: 420 }, data: {} },
  ],
  edges: [{ id: 'e1', source: 'start', sourceHandle: 'next', target: 'menu' }],
};

const REFERENCES = {
  agents: [{ id: 1, name: 'Lina' }, { id: 2, name: 'Omar' }],
  teams: [{ id: 4, name: 'Support' }],
  labels: [{ id: 3, name: 'vip' }, { id: 5, name: 'urgent' }],
  inboxes: [{ id: 9, name: 'WhatsApp' }],
  contact_attributes: [{ key: 'loyalty_tier', name: 'Loyalty tier', type: 'list', values: ['gold', 'silver'] }],
  conversation_attributes: [{ key: 'ticket_ref', name: 'Ticket reference', type: 'text', values: [] }],
  vocabulary: {
    conditions: [
      { key: 'status', scope: 'conversation', data_type: 'text', operators: ['equal_to', 'not_equal_to'], values: ['open', 'resolved'], available: true },
      { key: 'assignee_id', scope: 'conversation', data_type: 'text', operators: ['equal_to', 'is_present'], options_from: 'agents', available: true },
      { key: 'commerce_order_status', scope: 'commerce', data_type: 'text', operators: ['equal_to'], values: ['delivered'], available: false },
    ],
    condition_patterns: [],
  },
};

let saved = [];      // every PUT body the page sent
let conflictNext = false;

async function stub(page) {
  saved = [];
  conflictNext = false;

  await page.route('**/automation/api/references', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(REFERENCES) }));

  await page.route('**/automation/api/flows/42/builder', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ flow: FLOW, graph: GRAPH, catalogue: CATALOGUE }) }));

  await page.route('**/automation/api/flows/42/draft', (route) => {
    const body = JSON.parse(route.request().postData() || '{}');
    saved.push(body);
    if (conflictNext) {
      conflictNext = false;
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        ok: false,
        conflict: { graph: { nodes: [{ id: 'start', type: 'start', position: { x: 300, y: 60 }, data: {} }], edges: [] },
                    lock_version: 11, updated_at: '2026-02-03T08:00:00Z' },
      }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
      ok: true, flow: { ...FLOW, draft: { ...FLOW.draft, lock_version: body.lock_version + 1 } },
    }) });
  });

  await page.route('**/automation/api/flows/42/publish', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
      ok: false, errors: [{ code: 'unconnected_output', node_id: 'menu', detail: 'care' }],
    }) }));

  await page.route('**/automation/api/flows/42/simulate', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
      transcript: [{ from: 'customer', text: 'hi', items: [] },
                   { from: 'bot', text: 'How can we help?', items: [{ value: 'lfb:menu:track', title: 'Track order' }] }],
      session: { status: 'active', current_node_id: 'menu', end_reason: null, failure_code: null },
      trace: [{ event: 'flow.node.executed', node_id: 'menu', node_type: 'buttons', output: null }],
    }) }));
}

// ---------------------------------------------------------------- run
const browser = await chromium.launch();
const errors = [];

async function openBuilder(locale, viewport) {
  const context = await browser.newContext({ viewport, hasTouch: viewport.width < 800 });
  const page = await context.newPage();
  page.on('pageerror', (e) => errors.push(`${locale}/${viewport.width}: ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(`${locale}/${viewport.width} console: ${m.text()}`); });
  await stub(page);
  await page.goto(`${BASE}/builder-${locale}.html`);
  await page.waitForSelector('.lynb-node', { timeout: 8000 });
  return { context, page };
}

// ======================================================= 1. desktop, English
{
  const { context, page } = await openBuilder('en', { width: 1440, height: 900 });

  const nodeCount = await page.locator('.lynb-node').count();
  check('canvas draws every node of the loaded graph', nodeCount === 3, `${nodeCount} nodes`);

  const edgeCount = await page.locator('path.lynb-edge').count();
  check('canvas draws the edges', edgeCount === 1, `${edgeCount} edges`);

  // A Buttons node's handles come from its own options plus the type's extras: track, care, other, timeout.
  const handles = await page.locator('.lynb-node[data-node="menu"] circle[data-output]').count();
  check('a Buttons node grows one handle per choice plus its extras', handles === 4, `${handles} handles`);

  const errored = await page.locator('.lynb-node[data-node="menu"].has-error').count();
  check('a node with a validation error is marked on the canvas', errored === 1);

  const problems = await page.locator('#lynb-problems-list button').count();
  check('the problem list is shown and is clickable', problems === 1, `${problems} problems`);

  // --- palette adds a node
  const before = await page.locator('.lynb-node').count();
  await page.getByRole('button', { name: 'Ask a question', exact: true }).click();
  await page.waitForTimeout(80);
  const after = await page.locator('.lynb-node').count();
  check('clicking a palette step adds it to the canvas', after === before + 1, `${before} -> ${after}`);

  // --- the inspector is built from the served field schema
  const labels = await page.locator('#lynb-inspector-body label.form-label').allTextContents();
  check('the configuration form is built from the served fields',
    labels.some((l) => l.includes('Message')) && labels.some((l) => l.includes('Expected answer'))
      && labels.some((l) => l.includes('How many tries')),
    labels.join(' | '));

  // keywords is hidden until the answer type asks for it -- the server's own required_when
  const keywordsBefore = await page.locator('#lynb-f-keywords').count();
  await page.locator('#lynb-f-reply_type').selectOption('keywords');
  await page.waitForTimeout(60);
  const requiredMark = await page.locator('label[for="lynb-f-keywords"] .text-danger').count();
  check('a conditionally required field becomes required when its trigger is chosen',
    keywordsBefore === 1 && requiredMark === 1);

  const maxAttempts = await page.locator('#lynb-f-max_attempts');
  check('a number field carries the server limits', await maxAttempts.getAttribute('min') === '1'
    && await maxAttempts.getAttribute('max') === '5');

  // --- typing updates the node summary on the canvas
  await page.locator('#lynb-f-text').fill('What is your order number?');
  await page.waitForTimeout(60);
  const summary = await page.locator('.lynb-node.is-selected .lynb-summary').textContent();
  check('typing in the form updates the node on the canvas', summary.startsWith('What is your order'), summary);

  // --- the variable picker inserts a token the server allows
  await page.locator('#lynb-f-text').focus();
  await page.locator('#lynb-inspector-body select.form-select-sm').first().selectOption('contact.name');
  await page.waitForTimeout(60);
  const withVariable = await page.locator('#lynb-f-text').inputValue();
  check('the variable picker inserts an allow-listed token', withVariable.includes('{{ contact.name }}'), withVariable);

  // --- honest channel limits: the inspector warns where a step cannot run
  await page.locator('#lynb-fit').click();

  // --- connect two nodes by dragging from an output handle
  const edgesBefore = await page.locator('path.lynb-edge').count();
  const handle = page.locator('.lynb-node[data-node="menu"] circle[data-output="care"]');
  const target = page.locator('.lynb-node[data-node="bye"]');
  const h = await handle.boundingBox();
  const t = await target.boundingBox();
  await page.mouse.move(h.x + h.width / 2, h.y + h.height / 2);
  await page.mouse.down();
  await page.mouse.move(t.x + t.width / 2, t.y + t.height / 2, { steps: 12 });
  await page.mouse.up();
  await page.waitForTimeout(80);
  const edgesAfter = await page.locator('path.lynb-edge').count();
  check('dragging from an output to a step connects them', edgesAfter === edgesBefore + 1, `${edgesBefore} -> ${edgesAfter}`);
  check('an unsaved change is announced', await page.locator('#lynb-dirty').isVisible());

  // --- dragging a node moves it
  const node = page.locator('.lynb-node[data-node="bye"]');
  const beforeT = await node.getAttribute('transform');
  const b = await node.boundingBox();
  await page.mouse.move(b.x + 40, b.y + 20);
  await page.mouse.down();
  await page.mouse.move(b.x + 180, b.y + 120, { steps: 10 });
  await page.mouse.up();
  await page.waitForTimeout(80);
  const afterT = await page.locator('.lynb-node[data-node="bye"]').getAttribute('transform');
  check('a node can be dragged to a new place', beforeT !== afterT, `${beforeT} -> ${afterT}`);

  // --- keyboard: focus a node, nudge it, delete it
  await page.locator('.lynb-node[data-node="bye"]').focus();
  const beforeKey = await page.locator('.lynb-node[data-node="bye"]').getAttribute('transform');
  await page.keyboard.press('ArrowRight');
  await page.waitForTimeout(60);
  const afterKey = await page.locator('.lynb-node[data-node="bye"]').getAttribute('transform');
  check('arrow keys nudge the focused step', beforeKey !== afterKey, `${beforeKey} -> ${afterKey}`);

  await page.keyboard.press('Enter');
  await page.waitForTimeout(80);
  check('Enter opens the focused step for configuration',
    await page.locator('#lynb-inspector-body').isVisible());

  await page.locator('.lynb-node[data-node="bye"]').focus();
  await page.keyboard.press('Delete');
  await page.waitForTimeout(80);
  check('Delete removes the focused step', await page.locator('.lynb-node[data-node="bye"]').count() === 0);

  // --- the start step cannot be deleted
  await page.locator('.lynb-node[data-node="start"]').focus();
  await page.keyboard.press('Delete');
  await page.waitForTimeout(80);
  check('the start step is refused rather than removed',
    await page.locator('.lynb-node[data-node="start"]').count() === 1
    && (await page.locator('#lynb-alert').textContent()).includes('start step'));

  // --- saving sends the graph and the lock it was loaded with
  await page.locator('#lynb-save').click();
  await page.waitForTimeout(200);
  check('saving sends the graph and the lock_version it loaded',
    saved.length === 1 && saved[0].lock_version === 5 && Array.isArray(saved[0].graph.nodes),
    JSON.stringify({ sent: saved.length, lock: saved[0]?.lock_version, nodes: saved[0]?.graph?.nodes?.length }));
  check('a saved draft is reported and the dirty mark clears',
    !(await page.locator('#lynb-dirty').isVisible())
    && (await page.locator('#lynb-notice').textContent()).includes('Draft saved'));

  // --- the next save uses the token the server returned
  await page.locator('.lynb-node[data-node="menu"]').focus();
  await page.keyboard.press('ArrowRight');
  await page.locator('#lynb-save').click();
  await page.waitForTimeout(200);
  check('the next save carries the token the server handed back',
    saved.length === 2 && saved[1].lock_version === 6, `lock=${saved[1]?.lock_version}`);

  // --- the conflict path
  conflictNext = true;
  await page.locator('.lynb-node[data-node="menu"]').focus();
  await page.keyboard.press('ArrowRight');
  await page.locator('#lynb-save').click();
  await page.waitForSelector('#lynb-conflict-modal.show', { timeout: 4000 });
  check('a lost race opens the conflict dialog rather than losing the work', true);
  const nodesDuringConflict = await page.locator('.lynb-node').count();
  check('the merchant work is still on screen while they choose', nodesDuringConflict >= 3, `${nodesDuringConflict} nodes`);

  await page.locator('#lynb-conflict-mine').click();
  await page.waitForTimeout(250);
  check('"keep mine" re-saves against the token the server returned',
    saved.length === 4 && saved[3].lock_version === 11, `lock=${saved[3]?.lock_version}`);

  // --- publish surfaces per-node validation
  await page.locator('#lynb-publish').click();
  await page.waitForTimeout(250);
  const alertText = await page.locator('#lynb-alert').textContent();
  check('a publish that fails validation says so and marks the step',
    alertText.includes('cannot be published') && await page.locator('.lynb-node[data-node="menu"].has-error').count() === 1,
    alertText.trim());

  // --- test mode
  await page.locator('#lynb-test').click();
  await page.waitForSelector('#lynb-test-modal.show');
  await page.locator('#lynb-test-input').fill('hi');
  await page.locator('#lynb-test-form button[type="submit"]').click();
  await page.waitForTimeout(300);
  const bubbles = await page.locator('#lynb-test-log .lynb-bubble').allTextContents();
  check('Test Mode shows the transcript', bubbles.length === 2 && bubbles[1].includes('How can we help'), bubbles.join(' | '));
  const choices = await page.locator('#lynb-test-choices button').allTextContents();
  check('Test Mode offers the choices the flow sent', choices.includes('Track order'), choices.join(','));

  // --- AN OPTION ID IS AN OUTPUT HANDLE, so a duplicate is not cosmetic: the second choice with a
  // repeated id draws its handle over the first one's position and can never be routed anywhere,
  // with nothing on screen to say why. Minting ids by list length produced exactly that as soon as a
  // merchant deleted an earlier choice, which is the sequence below.
  await page.locator('#lynb-test-modal [data-bs-dismiss="modal"]').first().click();
  await page.waitForTimeout(500);              // the backdrop intercepts clicks until it is torn down
  await page.locator('.lynb-node[data-node="menu"]').click();
  await page.waitForTimeout(80);

  const optionIds = () => page.locator('#lynb-inspector-body .border.rounded span.text-muted').allTextContents();
  const addChoice = async () => {
    await page.getByRole('button', { name: 'Add choice', exact: true }).click();
    await page.waitForTimeout(40);
  };
  const removeChoice = async (index) => {
    await page.locator('#lynb-inspector-body .border.rounded button[aria-label]').nth(index).click();
    await page.waitForTimeout(40);
  };

  await removeChoice(0);                       // drop the two fixture ids so the minter starts clean
  await removeChoice(0);
  await addChoice(); await addChoice(); await addChoice();
  check('choices are minted in order from empty', (await optionIds()).join(',') === 'o1,o2,o3', (await optionIds()).join(','));

  await removeChoice(0);                       // o1 goes; the list is now o2,o3 and two long
  await addChoice();
  const minted = await optionIds();
  check('a choice added after a deletion does not reuse a live id',
    new Set(minted).size === minted.length && minted.includes('o4'), minted.join(','));

  const outputs = await page.locator('.lynb-node[data-node="menu"] circle[data-output]')
    .evaluateAll((nodes) => nodes.map((n) => n.getAttribute('data-output')));
  check('and every output handle on the canvas is reachable on its own',
    new Set(outputs).size === outputs.length, outputs.join(','));

  await page.screenshot({ path: (process.env.SHOT_DIR || '/tmp') + '/shot-builder-en.png', fullPage: false });
  await context.close();
}

// ======================================================= 2. Arabic, RTL
{
  const { context, page } = await openBuilder('ar', { width: 1440, height: 900 });

  check('the page is right to left in Arabic', await page.getAttribute('html', 'dir') === 'rtl');

  const palette = await page.locator('#lynb-palette-list button').allTextContents();
  check('the palette is in Arabic', palette.some((t) => /[؀-ۿ]/.test(t)), palette.slice(0, 3).join(' | '));

  // The palette sits at the start of the row, which in RTL is the RIGHT of the viewport.
  const paletteBox = await page.locator('#lynb-palette').boundingBox();
  const stageBox = await page.locator('#lynb-stage').boundingBox();
  check('the panels follow the reading direction in RTL',
    paletteBox.x > stageBox.x, `palette x=${Math.round(paletteBox.x)} stage x=${Math.round(stageBox.x)}`);

  // The canvas itself is NOT mirrored: a flow runs top to bottom, so the same graph reads the
  // same way in both languages and no stored coordinate is rewritten.
  const start = await page.locator('.lynb-node[data-node="start"]').getAttribute('transform');
  check('the canvas coordinates are untouched by direction', start === 'translate(140,40)', start);

  await page.getByRole('button', { name: /طرح سؤال/ }).click();
  await page.waitForTimeout(80);
  const arLabels = await page.locator('#lynb-inspector-body label.form-label').allTextContents();
  check('the configuration form is in Arabic', arLabels.some((l) => /[؀-ۿ]/.test(l)), arLabels.join(' | '));

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check('no horizontal overflow in Arabic at desktop width', overflow <= 1, `${overflow}px`);

  await page.screenshot({ path: (process.env.SHOT_DIR || '/tmp') + '/shot-builder-ar.png' });
  await context.close();
}

// ======================================================= 3. mobile
{
  const { context, page } = await openBuilder('ar', { width: 390, height: 844 });

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check('no horizontal page scroll on a 390px phone', overflow <= 1, `${overflow}px`);

  const paletteBox = await page.locator('#lynb-palette').boundingBox();
  const stageBox = await page.locator('#lynb-stage').boundingBox();
  check('the panels stack above the canvas on a phone', paletteBox.y + paletteBox.height <= stageBox.y + 2,
    `palette bottom=${Math.round(paletteBox.y + paletteBox.height)} stage top=${Math.round(stageBox.y)}`);

  // Touch: a tap selects a step and opens its settings.
  await page.locator('.lynb-node[data-node="menu"]').tap();
  await page.waitForTimeout(100);
  check('a tap selects a step on a touch screen', await page.locator('#lynb-inspector-body').isVisible());

  const buttons = await page.locator('#lynb-save, #lynb-publish, #lynb-test').all();
  const sizes = await Promise.all(buttons.map(async (b) => { const r = await b.boundingBox(); return Math.round(r.height); }));
  check('the toolbar buttons are big enough to tap', sizes.every((h) => h >= 28), sizes.join(','));

  await page.screenshot({ path: (process.env.SHOT_DIR || '/tmp') + '/shot-builder-mobile-ar.png', fullPage: true });
  await context.close();
}

// ======================================================= 4. the Studio list
{
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  page.on('pageerror', (e) => errors.push(`studio: ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(`studio console: ${m.text()}`); });

  await page.route('**/automation/api/flows', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({
      flows: [
        { ...FLOW, state: 'published', published: { version: 3, status: 'published', published_at: '2026-02-01T10:00:00Z', updated_at: null, lock_version: 0 } },
        { id: 43, name: 'مسار مُتوقف', description: null, state: 'disabled', live_sessions: 0, published: null, draft: null, inboxes: [] },
      ],
      usage: { used: 2, limit: 2 },
    }),
  }));

  await page.goto(`${BASE}/automation-ar.html`);
  await page.waitForSelector('.lyn-studio-card');

  const cards = await page.locator('.lyn-studio-card').count();
  check('the Studio lists the account flows', cards === 2, `${cards} cards`);

  const states = await page.locator('.lyn-state').allTextContents();
  check('a live flow and a disabled one read differently', states.length === 2 && states[0] !== states[1], states.join(' / '));

  check('the ceiling disables the new button rather than letting it fail',
    await page.locator('#lyn-studio-new').isDisabled());
  check('the ceiling is explained', await page.locator('#lyn-usage-warning').isVisible());

  const meterWidth = await page.locator('#lyn-usage-meter > span').evaluate((n) => n.style.width);
  check('the usage meter reflects the allowance', meterWidth === '100%', meterWidth);

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check('no horizontal overflow on the Studio in Arabic', overflow <= 1, `${overflow}px`);

  await page.screenshot({ path: (process.env.SHOT_DIR || '/tmp') + '/shot-studio-ar.png', fullPage: true });
  await context.close();
}

// ================================== 5. Test Mode when the draft behind it cannot be saved
//
// Test Mode runs the SAVED draft, so it saves first. When that save is refused, the only place the
// builder said so was the page's own alert strip -- which sits behind the test modal's backdrop. The
// merchant was left with a dialog frozen on an ellipsis and the explanation hidden underneath it.
{
  const { context, page } = await openBuilder('en', { width: 1440, height: 900 });

  await page.unroute('**/automation/api/flows/42/draft');
  await page.route('**/automation/api/flows/42/draft', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ ok: false, errors: [{ code: 'unconnected_output', node_id: 'menu', detail: 'care' }] }),
  }));

  await page.getByRole('button', { name: 'Ask a question', exact: true }).click();   // make it dirty
  await page.waitForTimeout(80);
  await page.locator('#lynb-test').click();
  await page.waitForSelector('#lynb-test-modal.show');
  await page.locator('#lynb-test-form input').fill('hi');
  await page.locator('#lynb-test-form button[type="submit"]').click();
  await page.waitForTimeout(500);

  const status = (await page.locator('#lynb-test-status').textContent()).trim();
  check('a refused pre-save is explained inside the dialog, not behind it',
    status.length > 1 && status !== '…' && status.includes('could not be saved'), status);

  const alertHidden = await page.locator('#lynb-alert').evaluate((n) => {
    const box = n.getBoundingClientRect();
    return document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2) !== n;
  }).catch(() => true);
  check('and the page alert really is unreachable behind the backdrop', alertHidden);

  await context.close();
}

await browser.close();

check('no uncaught JavaScript error on any page', errors.length === 0, errors.slice(0, 5).join(' || '));

writeFileSync((process.env.SHOT_DIR || '/tmp') + '/results.json', JSON.stringify(results, null, 2));
console.log(`\n${results.length - failures}/${results.length} checks passed`);
process.exit(failures ? 1 : 0);
