const { chromium } = require('playwright');
const OUT = process.argv[2];
const fs = require('fs');
(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const b = await chromium.launch();
  for (const lang of ['en', 'ar']) {
    const p = await b.newPage({ viewport: { width: 1440, height: 900 } });
    await p.goto('http://127.0.0.1:8899/login');
    await p.fill('input[name="email"]', 'merchant@example.test');
    await p.fill('input[name="password"]', 'password');
    await Promise.all([p.waitForNavigation({timeout:30000}).catch(()=>{}), p.click('button[type="submit"]')]);
    await p.goto('http://127.0.0.1:8899/change-language/' + lang, { waitUntil: 'networkidle' }).catch(()=>{});
    await p.goto('http://127.0.0.1:8899/dashboard', { waitUntil: 'networkidle' });
    // KEYBOARD PATH: focus without a click, so the command palette stays closed.
    await p.evaluate(() => {
      const i = document.querySelector('#sideBarsearch');
      i.focus(); i.value = 'zzzzzz';
      i.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true, key: 'z' }));
    });
    await p.waitForTimeout(400);
    const sidebar = await p.$('.dash-sidebar');
    if (sidebar) await sidebar.screenshot({ path: OUT + '/sidebar-no-match_' + lang + '.png' });
    const state = await p.evaluate(() => {
      const el = document.querySelector('#ss-sidebar-search-empty');
      const items = Array.from(document.querySelectorAll('.dash-navbar > .dash-item'));
      const cmd = document.querySelector('#ss-command');
      return { messagePresent: !!el, messageShown: el ? !el.hasAttribute('hidden') : false,
               itemsVisible: items.filter(e => e.offsetParent !== null).length, itemsTotal: items.length,
               paletteOpen: cmd ? !cmd.hidden : null };
    });
    console.log('[' + lang + ']', JSON.stringify(state));
    await p.close();
  }
  await b.close();
})();
