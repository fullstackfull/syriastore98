const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1440,height:900}});
const p=await ctx.newPage();
await p.goto('http://127.0.0.1:8899/login',{waitUntil:'domcontentloaded'});
await p.fill('input[name="email"]','merchant@example.test'); await p.fill('input[name="password"]','password');
await Promise.all([p.waitForLoadState('domcontentloaded'),p.click('button[type="submit"],input[type="submit"]')]);
for (const route of ['/coupon','/product']) {
  await p.goto('http://127.0.0.1:8899'+route,{waitUntil:'domcontentloaded'}); await p.waitForTimeout(2500);
  const r = await p.evaluate(()=>{
    const dt=jQuery('table.dataTable').DataTable(); const L=dt.settings()[0].oLanguage||{};
    const isAr=s=>/[؀-ۿ]/.test(s||'');
    const fam={sInfo:L.sInfo,sInfoEmpty:L.sInfoEmpty,sInfoFiltered:L.sInfoFiltered,
               sInfoPostFix:L.sInfoPostFix,sLoadingRecords:L.sLoadingRecords};
    const out={};
    for(const [k,v] of Object.entries(fam)) out[k]={value:(v||'(unset)').slice(0,46), arabic:isAr(v)};
    out._rows = dt.page.info().recordsTotal;
    const el=[...document.querySelectorAll('*')].find(e=>e.children.length===0 && /_START_|Showing|تظهر/.test(e.innerText));
    out._rendered = el ? el.innerText.trim().slice(0,46) : '(not found)';
    return out;
  });
  console.log('\n### '+route+'   rows='+r._rows);
  for (const [k,v] of Object.entries(r)) { if(k.startsWith('_'))continue;
    console.log(`  ${k.padEnd(16)} arabic=${String(v.arabic).padEnd(5)} ${v.value}`); }
  console.log('  RENDERED:      ', r._rendered);
}
await b.close();})();
