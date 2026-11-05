/** Every Bootstrap button variant's COMPOSITED contrast, injected into the real admin page. */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const REPO='/home/user/syriastore98', TOOL=REPO+'/docs/development/phase-2/tools/set-admin-preview-state.php';
const env={...process.env, DB_CONNECTION:'sqlite', DB_DATABASE:REPO+'/database/p2dev.sqlite', CACHE_STORE:'file'};
const lum=c=>{const f=v=>{v/=255;return v<=0.03928?v/12.92:Math.pow((v+0.055)/1.055,2.4)};return 0.2126*f(c[0])+0.7152*f(c[1])+0.0722*f(c[2])};
const ratio=(a,b)=>{const x=lum(a),y=lum(b);return (Math.max(x,y)+0.05)/(Math.min(x,y)+0.05)};
const PATH = process.argv[2] || '/finance';
(async()=>{const b=await chromium.launch({args:['--no-sandbox']});
for (const theme of ['light','dark']) {
  execFileSync('php',[TOOL,'1',theme,'ltr','en','merchant@example.test'],{env,stdio:['ignore','pipe','pipe']});
  const p=await(await b.newContext({viewport:{width:1440,height:900}})).newPage();
  await p.goto('http://127.0.0.1:8899/login',{waitUntil:'domcontentloaded'});
  await p.fill('input[name=email]','merchant@example.test'); await p.fill('input[name=password]','password');
  await Promise.all([p.waitForNavigation({waitUntil:'networkidle'}),p.click('button[type=submit]')]);
  await p.goto('http://127.0.0.1:8899'+PATH,{waitUntil:'networkidle'});
  const r=await p.evaluate(()=>{
    const parse=s=>(s.match(/[\d.]+/g)||[]).map(Number);
    const painted=el=>{const L=[];for(let e=el;e;e=e.parentElement){const c=parse(getComputedStyle(e).backgroundColor);if(c.length<3)continue;const a=c[3]===undefined?1:c[3];if(a<=0)continue;L.push([c[0],c[1],c[2],a]);if(a>=1)break;}
      if(!L.length)return[255,255,255];let out=L[L.length-1][3]>=1?[L[L.length-1][0],L[L.length-1][1],L[L.length-1][2]]:[255,255,255];
      for(let i=L.length-1;i>=0;i--){const[r,g,bl,a]=L[i];if(a>=1){out=[r,g,bl];continue;}out=[r*a+out[0]*(1-a),g*a+out[1]*(1-a),bl*a+out[2]*(1-a)];}return out.map(Math.round);};
    const host=document.querySelector('.dash-content')||document.body;
    const box=document.createElement('div'); host.appendChild(box); const out=[];
    for(const v of ['primary','secondary','success','danger','warning','info','light','dark']){
      const el=document.createElement('button'); el.className='btn btn-'+v; el.textContent='Sample'; box.appendChild(el);
      const cs=getComputedStyle(el);
      out.push({v, color:parse(cs.color).slice(0,3), bg:painted(el)});
    }
    box.remove(); return out;});
  console.log(`--- ${theme}`);
  let worst=99;
  r.forEach(x=>{const n=ratio(x.color,x.bg); worst=Math.min(worst,n);
    console.log(`  ${x.v.padEnd(10)} rgb(${x.color.join(',')}) on rgb(${x.bg.join(',')})  ${n.toFixed(2)}:1  ${n<3?'UNREADABLE':n<4.5?'under AA':'ok'}`);});
  console.log(`  worst: ${worst.toFixed(2)}:1`);
  await p.close();
}
execFileSync('php',[TOOL,'1','light','ltr','en','merchant@example.test'],{env,stdio:['ignore','pipe','pipe']});
await b.close()})();
