const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path=require('path');
const REPO='/home/user/syriastore98';
const STATE=path.join(REPO,'docs/development/phase-2/tools/set-admin-preview-state.php');
function lum([r,g,b]){const c=v=>{const s=v/255;return s<=0.03928?s/12.92:Math.pow((s+0.055)/1.055,2.4)};return .2126*c(r)+.7152*c(g)+.0722*c(b)}
function ratio(a,b){const l1=lum(a),l2=lum(b),hi=Math.max(l1,l2),lo=Math.min(l1,l2);return (hi+.05)/(lo+.05)}
const parse=v=>{const m=/rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(v||'');return m?[+m[1],+m[2],+m[3]]:null};
(async()=>{
  for (const theme of ['dark','light']) {
    execFileSync('php',[STATE,'1',theme,'ltr','en','merchant@example.test'],{cwd:path.dirname(STATE),
      env:{...process.env,DB_CONNECTION:'sqlite',DB_DATABASE:path.join(REPO,'database/p2dev.sqlite'),CACHE_STORE:'file'},stdio:['ignore','pipe','pipe']});
    const b=await chromium.launch(); const p=await (await b.newContext({viewport:{width:1440,height:1000}})).newPage();
    await p.goto('http://127.0.0.1:8899/login',{waitUntil:'domcontentloaded'});
    await p.fill('input[name="email"]','merchant@example.test'); await p.fill('input[name="password"]','password');
    await Promise.all([p.waitForLoadState('domcontentloaded'),p.click('button[type="submit"]')]);
    const r = await p.goto('http://127.0.0.1:8899/order-grid',{waitUntil:'domcontentloaded'});
    await p.waitForTimeout(1200);
    console.log(`\n=== /order-grid  ${theme}  HTTP ${r.status()} ===`);
    const rows = await p.$$eval('.text-dark', els => els.slice(0,6).map(el=>{
      const cs=getComputedStyle(el);
      let bgEl=el, bg='rgba(0, 0, 0, 0)';
      while (bgEl && /rgba\(0, 0, 0, 0\)|transparent/.test(bg)) { bg=getComputedStyle(bgEl).backgroundColor; bgEl=bgEl.parentElement; }
      return {txt:(el.innerText||'').replace(/\s+/g,' ').trim().slice(0,34), fg:cs.color, bg};
    }));
    if (!rows.length) console.log('   no .text-dark elements found');
    for (const row of rows) {
      const fg=parse(row.fg), bg=parse(row.bg);
      const v = (fg&&bg)? ratio(fg,bg).toFixed(2)+':1' : 'n/a';
      const flag = (fg&&bg&&ratio(fg,bg)<4.5)?'  <-- BELOW 4.5:1':'';
      console.log(`   ${String(v).padStart(7)}  fg ${row.fg.padEnd(20)} on ${row.bg.padEnd(20)} "${row.txt}"${flag}`);
    }
    await p.screenshot({path:`/tmp/claude-0/-home-user/c1fead93-e010-5c9c-961e-62abcd74611a/scratchpad/p25/order-grid-${theme}.png`});
    await b.close();
  }
  process.exit(0);
})();
