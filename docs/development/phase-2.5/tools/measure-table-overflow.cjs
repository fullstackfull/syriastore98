const { chromium } = require('playwright');
const { execFileSync } = require('child_process'); const path=require('path');
const BASE='http://127.0.0.1:8899', REPO='/home/user/syriastore98';
const TOOL=path.join(REPO,'docs/development/phase-2/tools/set-admin-preview-state.php');
const setState=(t,d,l)=>execFileSync('php',[TOOL,'1',t,d,l,'merchant@example.test'],{cwd:path.dirname(TOOL),
  env:{...process.env,DB_CONNECTION:'sqlite',DB_DATABASE:path.join(REPO,'database/p2dev.sqlite'),CACHE_STORE:'file'},stdio:['ignore','pipe','pipe']});
(async()=>{
  const b=await chromium.launch();
  for (const [dir,lang] of [['ltr','en'],['rtl','ar']]) {
    setState('light',dir,lang);
    const ctx=await b.newContext({viewport:{width:1440,height:900},deviceScaleFactor:1});
    const p=await ctx.newPage();
    await p.goto(BASE+'/login',{waitUntil:'domcontentloaded'});
    await p.fill('input[name="email"]','merchant@example.test'); await p.fill('input[name="password"]','password');
    await Promise.all([p.waitForLoadState('networkidle'),p.click('button[type="submit"],input[type="submit"]')]);
    console.log(`\n--- ${lang}/${dir} @1440 ---`);
    for (const route of ['/product','/order','/customer']) {
      await p.goto(BASE+route,{waitUntil:'networkidle'}); await p.waitForTimeout(900);
      const r=await p.evaluate(()=>{
        const t=document.querySelector('table.dataTable');
        const sp=t&&t.closest('.table-responsive,.dataTable-container');
        if(!t||!sp) return null;
        const pin=document.querySelector('table.dataTable thead th.ss-col-pinned');
        const pr=pin&&pin.getBoundingClientRect();
        // which header does the pinned column's inner edge fall inside?
        let covered=null;
        if(pr){ for(const th of document.querySelectorAll('table.dataTable thead th:not(.ss-col-pinned)')){
            const r=th.getBoundingClientRect(); const edge = getComputedStyle(document.documentElement).direction==='rtl'?pr.right:pr.left;
            if(r.left < edge && r.right > edge){ covered={text:th.innerText.trim().slice(0,20), by: Math.round(getComputedStyle(document.documentElement).direction==='rtl'? (edge-r.left) : (r.right-edge))}; } } }
        return { tableW: Math.round(t.getBoundingClientRect().width), portW: Math.round(sp.clientWidth),
                 scrollW: t.scrollWidth, overflow: Math.round(t.getBoundingClientRect().width - sp.clientWidth),
                 canScroll: sp.scrollWidth > sp.clientWidth + 1, cols: document.querySelectorAll('table.dataTable thead th').length, covered };
      });
      if(!r){ console.log(`  ${route}: no table`); continue; }
      console.log(`  ${route.padEnd(10)} table ${r.tableW} in port ${r.portW}  overflow ${r.overflow>0?'+'+r.overflow:r.overflow}  cols=${r.cols}  scrolls=${r.canScroll}` +
                  (r.covered?`  pinned covers ${JSON.stringify(r.covered.text)} by ${r.covered.by}px`:'  nothing covered'));
    }
    await ctx.close();
  }
  await b.close();
})();
