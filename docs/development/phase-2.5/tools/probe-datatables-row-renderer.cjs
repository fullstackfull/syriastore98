/*
 * P2.5-ORD-3 -- see probe-datatables-row-renderer.html for the question and the measured answer.
 * Loads that page off the filesystem with the repository's own jQuery and DataTables builds and
 * prints the rendered cells, the inputs' attributes and any console error.
 */
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage();
  const msgs = [];
  p.on('console', m => msgs.push(m.type() + ': ' + m.text()));
  p.on('pageerror', e => msgs.push('pageerror: ' + e.message));
  await p.goto('file://' + __dirname + '/probe-datatables-row-renderer.html');
  await p.waitForTimeout(800);
  const r = await p.evaluate(() => window.__result);
  console.log(JSON.stringify(r, null, 2));
  console.log('CONSOLE:', JSON.stringify(msgs, null, 2));
  await b.close();
})();
