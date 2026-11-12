const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path=require('path');
const REPO='/home/user/syriastore98';
const STATE=path.join(REPO,'docs/development/phase-2/tools/set-admin-preview-state.php');
(async()=>{
  execFileSync('php',[STATE,'1','light','ltr','en','merchant@example.test'],{cwd:path.dirname(STATE),
    env:{...process.env,DB_CONNECTION:'sqlite',DB_DATABASE:path.join(REPO,'database/p2dev.sqlite'),CACHE_STORE:'file'},stdio:['ignore','pipe','pipe']});
  const b=await chromium.launch(); const p=await (await b.newContext({viewport:{width:1440,height:900}})).newPage();
  const posts=[]; p.on('request', r=>{ if(r.method()==='POST') posts.push(r.method()+' '+r.url()); });
  const resp=[]; p.on('response', r=>{ if(r.request().method()==='POST') resp.push(r.status()+' '+r.url()); });
  await p.goto('http://127.0.0.1:8899/login',{waitUntil:'domcontentloaded'});
  await p.fill('input[name="email"]','merchant@example.test'); await p.fill('input[name="password"]','password');
  await Promise.all([p.waitForLoadState('domcontentloaded'),p.click('button[type="submit"]')]);
  const r = await p.goto('http://127.0.0.1:8899/order-kanban',{waitUntil:'domcontentloaded'});
  await p.waitForTimeout(1200);
  console.log('   HTTP', r.status());
  const cards = await p.locator('.ss-board__card').count();
  const forms = await p.locator('form.ss-board__card-move').count();
  const selects = await p.locator('select[name="delivered"]').count();
  const buttons = await p.locator('form.ss-board__card-move button[type="submit"]').count();
  console.log(`   cards=${cards} move-forms=${forms} selects=${selects} buttons=${buttons}`);
  if (!forms) { console.log('   !! no Move form rendered at all'); 
    const perm = await p.evaluate(()=> (document.body.innerText||'').slice(0,160));
    console.log('   page starts:', perm.replace(/\s+/g,' ')); await b.close(); process.exit(0); }
  // state before
  const colOf = async () => await p.evaluate(()=>{
    const out={};
    document.querySelectorAll('.ss-board__col').forEach(c=>{
      const h=(c.querySelector('h3,h4,.ss-board__col-title,header')||{}).textContent||'?';
      out[h.trim().replace(/\s+/g,' ').slice(0,28)] = c.querySelectorAll('.ss-board__card').length;
    });
    return out;});
  const before = await colOf();
  console.log('   before:', JSON.stringify(before));
  const first = p.locator('form.ss-board__card-move').first();
  const ref = await first.locator('select').getAttribute('id');
  const opts = await first.locator('select option').allTextContents();
  console.log('   first form:', ref, '| options:', JSON.stringify(opts));
  await first.locator('select').selectOption({ index: 1 });
  const chosen = await first.locator('select').inputValue();
  console.log('   chose value:', chosen);
  await Promise.all([p.waitForLoadState('domcontentloaded').catch(()=>{}), first.locator('button[type="submit"]').click()]);
  await p.waitForTimeout(2000);
  console.log('   POSTs:', posts.join(' | ') || '(none)');
  console.log('   responses:', resp.join(' | ') || '(none)');
  console.log('   url now:', p.url());
  const flash = await p.evaluate(()=>{
    const el=document.querySelector('.alert, .toast, #toast-container, .ss-alert');
    return el? el.textContent.replace(/\s+/g,' ').trim().slice(0,160) : null;});
  console.log('   flash:', flash);
  await p.goto('http://127.0.0.1:8899/order-kanban',{waitUntil:'domcontentloaded'});
  await p.waitForTimeout(1000);
  console.log('   after :', JSON.stringify(await colOf()));
  await b.close(); process.exit(0);
})();
