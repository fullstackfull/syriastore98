const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1440,height:1000}});
const p=await ctx.newPage();
await p.goto('http://127.0.0.1:8899/login',{waitUntil:'domcontentloaded'});
await p.fill('input[name="email"]','merchant@example.test'); await p.fill('input[name="password"]','password');
await Promise.all([p.waitForLoadState('domcontentloaded'),p.click('button[type="submit"],input[type="submit"]')]);
for (const route of ['/finance','/finance/withdrawals']) {
  await p.goto('http://127.0.0.1:8899'+route,{waitUntil:'domcontentloaded'}); await p.waitForTimeout(2200);
  const r = await p.evaluate(()=>{
    const cells=[...document.querySelectorAll('table td')].filter(td=>/[\d,]+\.\d{2}/.test(td.innerText));
    return cells.slice(0,10).map(td=>{
      const span=td.querySelector('.ss-money')||(td.classList.contains('ss-money')?td:null);
      const target=span||td; const cs=getComputedStyle(target);
      return {text:td.innerText.trim().slice(0,18), hasMoney:!!span,
              numeric:cs.fontVariantNumeric, align:getComputedStyle(td).textAlign};
    });
  });
  console.log('\n### '+route);
  for (const c of r) console.log(`  ${c.text.padEnd(18)} ss-money=${String(c.hasMoney).padEnd(5)} numeric=${c.numeric.padEnd(13)} align=${c.align}`);
}
await b.close();})();
