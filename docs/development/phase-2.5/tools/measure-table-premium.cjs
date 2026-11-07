const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE='http://127.0.0.1:8899', REPO='/home/user/syriastore98';
const TOOL=path.join(REPO,'docs/development/phase-2/tools/set-admin-preview-state.php');
const setState=(t,d,l)=>execFileSync('php',[TOOL,'1',t,d,l,'merchant@example.test'],{cwd:path.dirname(TOOL),
  env:{...process.env,DB_CONNECTION:'sqlite',DB_DATABASE:path.join(REPO,'database/p2dev.sqlite'),CACHE_STORE:'file'},stdio:['ignore','pipe','pipe']});
const lum=c=>{const [r,g,b]=c.map(v=>{v/=255;return v<=0.03928?v/12.92:Math.pow((v+0.055)/1.055,2.4)});return 0.2126*r+0.7152*g+0.0722*b};
const ratio=(a,b)=>{const [x,y]=[lum(a),lum(b)].sort((p,q)=>q-p);return (x+0.05)/(y+0.05)};
const parse=s=>(s.match(/\d+(\.\d+)?/g)||[0,0,0]).slice(0,3).map(Number);
(async()=>{
  const b=await chromium.launch();
  for (const [theme,dir,lang] of [['light','ltr','en'],['dark','ltr','en']]) {
    setState(theme,dir,lang);
    const ctx=await b.newContext({viewport:{width:1440,height:900},deviceScaleFactor:1});
    const p=await ctx.newPage();
    await p.goto(BASE+'/login',{waitUntil:'domcontentloaded'});
    await p.fill('input[name="email"]','merchant@example.test'); await p.fill('input[name="password"]','password');
    await Promise.all([p.waitForLoadState('networkidle'),p.click('button[type="submit"],input[type="submit"]')]);
    await p.goto(BASE+'/product',{waitUntil:'networkidle'}); await p.waitForTimeout(1200);
    const r=await p.evaluate(()=>{
      const eff=el=>{ // walk up for the first non-transparent background
        let n=el; while(n){const bg=getComputedStyle(n).backgroundColor;
          if(bg&&!/rgba\(0, 0, 0, 0\)|transparent/.test(bg)) return bg; n=n.parentElement;} return 'rgb(255,255,255)';};
      const cell=document.querySelector('table.dataTable tbody tr td:nth-child(2)');
      const ap=[...document.querySelectorAll('button,a')].find(e=>/Apply filters|تطبيق/.test(e.innerText));
      const th=document.querySelector('table.dataTable thead th:nth-child(2)');
      const dsVar=n=>getComputedStyle(document.documentElement).getPropertyValue(n).trim();
      return {
        cellColor:getComputedStyle(cell).color, cellBg:eff(cell), cellWeight:getComputedStyle(cell).fontWeight,
        thColor:getComputedStyle(th).color, thBg:eff(th), thTransform:getComputedStyle(th).textTransform,
        apply: ap?{bg:getComputedStyle(ap).backgroundColor,color:getComputedStyle(ap).color,cls:ap.className}:null,
        tokens:{primary:dsVar('--ss-btn-primary-bg')||dsVar('--ss-primary')||dsVar('--ss-brand'),
                brand:dsVar('--ss-brand'), cyan:dsVar('--ss-cyan-500'), fg:dsVar('--ss-fg'), fgSoft:dsVar('--ss-fg-soft')},
        colvis:(()=>{const e=document.querySelector('.ss-colvis');return e?{bg:getComputedStyle(e).backgroundColor,color:getComputedStyle(e).color}:null})(),
      };
    });
    console.log(`\n================ ${theme.toUpperCase()} ================`);
    console.log(`  table body cell  colour=${r.cellColor}  on ${r.cellBg}  weight=${r.cellWeight}`);
    console.log(`                   CONTRAST = ${ratio(parse(r.cellColor),parse(r.cellBg)).toFixed(2)}:1   ${ratio(parse(r.cellColor),parse(r.cellBg))>=4.5?'AA pass':'*** AA FAIL (body text needs 4.5) ***'}`);
    console.log(`  header th        colour=${r.thColor}  on ${r.thBg}  transform=${r.thTransform}`);
    console.log(`                   CONTRAST = ${ratio(parse(r.thColor),parse(r.thBg)).toFixed(2)}:1`);
    console.log(`  Apply (primary)  bg=${r.apply&&r.apply.bg}  text=${r.apply&&r.apply.color}  cls=${r.apply&&r.apply.cls}`);
    console.log(`  .ss-colvis       bg=${r.colvis&&r.colvis.bg}  text=${r.colvis&&r.colvis.color}`);
    console.log(`  tokens           ${JSON.stringify(r.tokens)}`);
    await ctx.close();
  }
  await b.close();
})();
