const { chromium } = require('playwright');
(async()=>{
  const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1440,height:900},deviceScaleFactor:1});
  const p=await ctx.newPage();
  await p.goto('http://127.0.0.1:8899/login',{waitUntil:'domcontentloaded'});
  await p.fill('input[name="email"]','merchant@example.test'); await p.fill('input[name="password"]','password');
  await Promise.all([p.waitForLoadState('domcontentloaded'),p.click('button[type="submit"],input[type="submit"]')]);
  for (const route of ['/dashboard','/product','/order']) {
    await p.goto('http://127.0.0.1:8899'+route,{waitUntil:'domcontentloaded'}); await p.waitForTimeout(2000);
    const r = await p.evaluate(()=>[...document.querySelectorAll('h1,h2,h3,h4')]
      .filter(e=>e.offsetParent!==null && e.innerText.trim())
      .slice(0,12).map(el=>{const cs=getComputedStyle(el);
        return {tag:el.tagName, cls:el.className.slice(0,46), size:cs.fontSize, weight:cs.fontWeight,
                text:el.innerText.trim().slice(0,26)};}));
    console.log('\n### '+route);
    for (const h of r) console.log(`  <${h.tag}> ${h.size.padEnd(6)} w${h.weight}  ${JSON.stringify(h.cls).padEnd(40)} ${JSON.stringify(h.text)}`);
  }
  await b.close();
})();
