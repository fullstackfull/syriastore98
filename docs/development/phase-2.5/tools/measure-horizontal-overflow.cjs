const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const REPO='/home/user/syriastore98', TOOL=REPO+'/docs/development/phase-2/tools/set-admin-preview-state.php';
const env={...process.env, DB_CONNECTION:'sqlite', DB_DATABASE:REPO+'/database/p2dev.sqlite', CACHE_STORE:'file'};
(async () => {
  const b = await chromium.launch({ args:['--no-sandbox'] });
  for (const [theme,dir,lang] of [['light','rtl','ar'],['light','ltr','en']]) {
    execFileSync('php',[TOOL,'1',theme,dir,lang,'merchant@example.test'],{env,stdio:['ignore','pipe','pipe']});
    const p = await (await b.newContext({viewport:{width:1440,height:900}})).newPage();
    await p.goto('http://127.0.0.1:8899/login',{waitUntil:'domcontentloaded'});
    await p.fill('input[name="email"]','merchant@example.test'); await p.fill('input[name="password"]','password');
    await Promise.all([p.waitForNavigation({waitUntil:'networkidle'}), p.click('button[type="submit"]')]);
    for (const path of ['/order-kanban','/dashboard','/product-list','/order-grid']) {
      await p.goto('http://127.0.0.1:8899'+path,{waitUntil:'networkidle'});
      const r = await p.evaluate(() => {
        const de = document.documentElement, vw = de.clientWidth;
        const out = { vw, scrollW: de.scrollWidth, bodyScrollW: document.body.scrollWidth, scrollX: window.scrollX, offenders: [] };
        if (de.scrollWidth <= vw + 1) return out;
        for (const el of document.querySelectorAll('body *')) {
          const r = el.getBoundingClientRect();
          if (r.width === 0 || r.height === 0) continue;
          const right = r.right + window.scrollX, left = r.left + window.scrollX;
          const over = document.dir === 'rtl' ? -Math.min(0, left) : Math.max(0, right - vw);
          if (over > 2) {
            const cs = getComputedStyle(el);
            out.offenders.push({ tag: el.tagName.toLowerCase(), cls: (el.className||'').toString().slice(0,48),
              w: Math.round(r.width), over: Math.round(over), ov: cs.overflowX, ws: cs.whiteSpace, minW: cs.minWidth });
          }
        }
        out.offenders.sort((a,b)=>b.over-a.over);
        out.offenders = out.offenders.slice(0,8);
        return out;
      });
      const bad = r.scrollW > r.vw + 1;
      console.log(`${lang}/${dir} ${path.padEnd(14)} viewport=${r.vw} scrollWidth=${r.scrollW} ${bad ? 'HORIZONTAL OVERFLOW +' + (r.scrollW-r.vw) + 'px' : 'fits'}`);
      r.offenders.forEach(o => console.log(`      +${String(o.over).padStart(4)}px  <${o.tag} class="${o.cls}"> w=${o.w} overflow-x=${o.ov} min-width=${o.minW}`));
    }
    await p.close();
  }
  await b.close();
})();
