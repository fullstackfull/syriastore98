const { chromium } = require('playwright');
const BASE='http://127.0.0.1:8899';
(async()=>{
  const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1440,height:900}});
  const p=await ctx.newPage();
  const xhr=[]; p.on('request', r=>{ if(/customer-filter-data/.test(r.url())) xhr.push(r.url()); });
  const errs=[]; p.on('pageerror', e=>errs.push(String(e).slice(0,140)));
  await p.goto(BASE+'/login',{waitUntil:'domcontentloaded'});
  await p.fill('input[name="email"]','merchant@example.test');
  await p.fill('input[name="password"]','password');
  await Promise.all([p.waitForLoadState('domcontentloaded'),p.click('button[type="submit"]')]);

  for (const [name, url, btn] of [['index','/customer','#applyfilter'],['grid','/customer-grid','#apply-button']]) {
    xhr.length=0; errs.length=0;
    const r = await p.goto(BASE+url,{waitUntil:'domcontentloaded'});
    await p.waitForTimeout(1200);
    console.log(`\n=== ${name}  ${url}  HTTP ${r.status()} ===`);
    // does the button exist, and does the handler id exist?
    for (const sel of ['#applyfilter','#apply-button','#service-filter-data','#customer_field','#loader']) {
      console.log(`   ${sel.padEnd(22)} count=${await p.locator(sel).count()}`);
    }
    const before = await p.evaluate(()=> (document.body.innerText||'').replace(/\s+/g,' ').slice(0,400));
    const rowsBefore = await p.locator('table tbody tr, .ss-card, .card.customer-card').count();
    // drive the filter: choose "Name", then Includes, then a value
    const hasField = await p.locator('#customer_field').count();
    if (hasField) {
      await p.selectOption('#customer_field','Name').catch(e=>console.log('   selectOption failed:', String(e).slice(0,100)));
      await p.waitForTimeout(600);
      const selCount = await p.locator('#select-container select').count();
      const txtCount = await p.locator('#text-field input').count();
      console.log(`   after choosing Name: operator selects=${selCount} text inputs=${txtCount}`);
      if (selCount) await p.selectOption('#select-container select','Includes').catch(()=>{});
      if (txtCount) await p.fill('#text-field input','zz-no-such-customer-zz').catch(()=>{});
      await p.waitForTimeout(300);
    }
    const bcount = await p.locator(btn).count();
    if (bcount) { await p.click(btn,{force:true}).catch(e=>console.log('   click failed', String(e).slice(0,80))); }
    else console.log(`   !! button ${btn} not present`);
    await p.waitForTimeout(1500);
    const after = await p.evaluate(()=> (document.body.innerText||'').replace(/\s+/g,' ').slice(0,400));
    const rowsAfter = await p.locator('table tbody tr, .ss-card, .card.customer-card').count();
    console.log(`   XHR to customer-filter-data: ${xhr.length}`);
    console.log(`   rows before=${rowsBefore} after=${rowsAfter}   page text changed: ${before!==after}`);
    if (errs.length) console.log('   pageerrors:', errs.join(' | '));
  }
  await b.close(); process.exit(0);
})();
