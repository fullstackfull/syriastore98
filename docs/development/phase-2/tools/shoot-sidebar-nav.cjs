/* Screenshot run for the sidebar navigation (P2-01-10, P2-01-11).
 *
 * Kept in the repo rather than a scratch file so the evidence under
 * screenshots/p2-01-sidebar-nav/ can be regenerated exactly. Needs the preview
 * server running with the nav3-* previews rendered; see PHASE-02-TEST-EVIDENCE.md
 * §4bis for the commands.
 *
 * z01 is the ZONES-DISABLED rendering, not a "before": the workspace chip and
 * the rail toggle are in it too, because those are not part of the grouping and
 * the kill switch in config/merchant-navigation.php only turns off the zones.
 * Labelling it "before" would have claimed a comparison it does not make.
 */
const { chromium } = require('playwright');
const path = require('path');
const OUT = '/home/user/syriastore98/docs/development/phase-2/screenshots/p2-01-sidebar-nav';
const shots = [
  ['nav3-en-light-flat', 'z01-zones-disabled-en-light.png', 1440, 900],
  ['nav3-en-light-full', 'z02-after-zones-en-light.png', 1440, 900],
  ['nav3-ar-light-full', 'z03-after-zones-ar-light.png', 1440, 900],
  ['nav3-en-dark-full',  'z04-after-zones-en-dark.png',  1440, 900],
  ['nav3-ar-dark-full',  'z05-after-zones-ar-dark.png',  1440, 900],
  ['nav3-ar-light-full', 'z06-after-zones-ar-mobile.png', 390, 820],
  ['nav3-en-light-noperm','z07-after-zones-en-minimal.png',1440, 900],
];
(async () => {
  const b = await chromium.launch();
  for (const [name, out, w, h] of shots) {
    const url = w < 500 ? `http://127.0.0.1:8099/frame/${name}.html?w=${w}&h=${h}`
                        : `http://127.0.0.1:8099/preview/${name}.html`;
    const ctx = await b.newContext({ viewport: { width: Math.max(w, 520), height: h } });
    const p = await ctx.newPage();
    await p.goto(url, { waitUntil: 'load' });
    await p.waitForTimeout(500);
    const t = w < 500 ? p.locator('iframe') : p.locator('nav.dash-sidebar');
    await t.screenshot({ path: path.join(OUT, out) });
    console.log('  ', out);
    await ctx.close();
  }
  await b.close();
})();
