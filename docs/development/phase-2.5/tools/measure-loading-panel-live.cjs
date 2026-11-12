const { chromium } = require('playwright');
const { execFileSync } = require('child_process'); const path=require('path');
const REPO='/home/user/syriastore98';
const TOOL=path.join(REPO,'docs/development/phase-2/tools/set-admin-preview-state.php');
const setState=(t,d,l)=>execFileSync('php',[TOOL,'1',t,d,l,'merchant@example.test'],{cwd:path.dirname(TOOL),
  env:{...process.env,DB_CONNECTION:'sqlite',DB_DATABASE:path.join(REPO,'database/p2dev.sqlite'),CACHE_STORE:'file'},stdio:['ignore','pipe','pipe']});
(async()=>{const b=await chromium.launch();
for (const t of ['light','dark']) { setState(t,'rtl','ar');
  const ctx=await b.newContext({viewport:{width:1440,height:900}}); const p=await ctx.newPage();
  await p.goto('http://127.0.0.1:8899/login',{waitUntil:'domcontentloaded'});
  await p.fill('input[name="email"]','merchant@example.test'); await p.fill('input[name="password"]','password');
  await Promise.all([p.waitForLoadState('domcontentloaded'),p.click('button[type="submit"],input[type="submit"]')]);
  await p.goto('http://127.0.0.1:8899/product',{waitUntil:'domcontentloaded'}); await p.waitForTimeout(2000);
  const r = await p.evaluate(()=>{
    const el=document.querySelector('.dataTables_processing');
    if(!el) return {error:'absent'};
    // force it visible so the computed style is the one a merchant sees mid-draw
    el.style.display='block';
    const cs=getComputedStyle(el); const rect=el.getBoundingClientRect();
    const eff=(n)=>{let x=n;while(x){const bg=getComputedStyle(x).backgroundColor;
      if(bg&&!/rgba\(0, 0, 0, 0\)|transparent/.test(bg))return bg;x=x.parentElement;}return 'rgb(255,255,255)';};
    return {text:el.innerText.trim().slice(0,40), bg:cs.backgroundColor, effBg:eff(el), color:cs.color,
            pos:cs.position, w:Math.round(rect.width), h:Math.round(rect.height),
            border:cs.border, radius:cs.borderRadius, shadow:cs.boxShadow, z:cs.zIndex, opacity:cs.opacity};
  });
  console.log(`\n### ${t}`); console.log('  ', JSON.stringify(r,null,1).replace(/\n/g,'\n  '));
  await ctx.close(); }
await b.close();})();
