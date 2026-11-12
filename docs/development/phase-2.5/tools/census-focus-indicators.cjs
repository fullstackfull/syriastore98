const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1440, height: 900 } });
  await p.goto('http://127.0.0.1:8899/login');
  await p.fill('input[name="email"]', 'merchant@example.test');
  await p.fill('input[name="password"]', 'password');
  await Promise.all([p.waitForNavigation({timeout:30000}).catch(()=>{}), p.click('button[type="submit"]')]);

  for (const path of ['/dashboard', '/order']) {
    await p.goto('http://127.0.0.1:8899' + path, { waitUntil: 'networkidle' });
    const res = await p.evaluate(async () => {
      const focusables = Array.from(document.querySelectorAll(
        'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])'))
        .filter(e => e.offsetParent !== null);
      const seen = {};
      for (const el of focusables.slice(0, 220)) {
        el.focus();
        if (!el.matches(':focus-visible')) continue;
        const cs = getComputedStyle(el);
        const hasOutline = cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0;
        const hasShadow = cs.boxShadow && cs.boxShadow !== 'none';
        const key = (el.className.toString().split(/\s+/).filter(Boolean).slice(0,2).join('.') || el.tagName.toLowerCase());
        seen[key] = seen[key] || { n: 0, withRing: 0, sample: '' };
        seen[key].n++;
        if (hasOutline || hasShadow) seen[key].withRing++;
        else seen[key].sample = (el.innerText || el.getAttribute('aria-label') || el.tagName).trim().slice(0, 28);
      }
      return seen;
    });
    const noRing = Object.entries(res).filter(([, v]) => v.withRing === 0);
    const ring = Object.entries(res).filter(([, v]) => v.withRing > 0);
    console.log('\n=== ' + path + ' ===');
    console.log('classes WITH a focus indicator   :', ring.length, ring.slice(0,6).map(([k,v]) => k+'('+v.n+')').join(' '));
    console.log('classes WITHOUT any indicator    :', noRing.length,
      'totalling', noRing.reduce((a,[,v]) => a+v.n, 0), 'controls');
    noRing.slice(0, 10).forEach(([k, v]) => console.log('   -', k, 'x' + v.n, v.sample ? '| e.g. "'+v.sample+'"' : ''));
  }
  await b.close();
})();
