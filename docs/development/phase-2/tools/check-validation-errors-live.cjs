'use strict';
/* P2-03-4, last item: "أخطاء التحقق" -- does a merchant SEE a validation error, on the right
   field, in both languages? Drives the real edit page against the real validator. */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE='http://127.0.0.1:8899', REPO='/home/user/syriastore98';
const CASES=[
  /* `pid` matters: the `variant_product == 1` branch of update()'s rule set OMITS the price
     rules on purpose, because a variant product's prices live on `product_variants` and the
     parent `price` column is only written inside `if ($request->variant_product == 0)`
     (ProductController.php:1643). Driving a price rule against product 1 measured the absence
     of a rule that is absent BY DESIGN -- so the price cases run against a simple product. */
  {pid:1, id:'name-empty',       set:{name:''},                     field:'name',
   why:"'name' => 'required' -- in BOTH rule sets"},
  {pid:1, id:'weight-negative',  set:{product_weight:'-2'},         field:'product_weight',
   why:"F70 custom message, variant branch"},
  {pid:2, id:'name-empty-simple',set:{name:''},                     field:'name',
   why:"'name' => 'required', simple product"},
  {pid:2, id:'cost-gt-price',    set:{price:'10',cost_price:'99'},  field:'cost_price',
   why:"'cost_price' => '...|lte:price' -- simple products only"},
  {pid:2, id:'price-negative',   set:{price:'-5'},                  field:'price',
   why:"'price' => 'required|numeric|min:0'"},
  {pid:2, id:'sale-gte-price',   set:{price:'10',sale_price:'20'},  field:'sale_price',
   why:"'sale_price' => '...|lt:price'"},
  {pid:2, id:'weight-negative-s',set:{product_weight:'-2'},         field:'product_weight',
   why:"F70 custom message, simple branch"},
];

let pass=0, fail=0;
const ck=(ok,label,extra)=>{ok?pass++:fail++; console.log('  '+(ok?'PASS':'FAIL')+'  '+label+(extra?'  :: '+extra:''));};

(async()=>{
  const b=await chromium.launch();
  for (const lang of ['en','ar']) {
    console.log('\n================ lang=' + lang + ' ================');
    const ctx=await b.newContext({viewport:{width:1440,height:1000}});
    const p=await ctx.newPage();
    const perr=[]; p.on('pageerror',e=>perr.push(String(e.message).slice(0,120)));
    await p.goto(BASE+'/login',{waitUntil:'domcontentloaded'});
    await p.fill('input[name="email"]','merchant@example.test');
    await p.fill('input[name="password"]','password');
    await Promise.all([p.waitForLoadState('domcontentloaded'),p.click('button[type="submit"]')]);
    await p.goto(BASE+'/change-language/'+lang,{waitUntil:'domcontentloaded'}).catch(()=>{});

    for (const c of CASES) {
      await p.goto(BASE+'/product/'+c.pid+'/edit',{waitUntil:'networkidle'});
      await p.waitForTimeout(2500);
      let seen=null;
      await p.route(u=>/\/product\/\d+$/.test(u.pathname||''), async route=>{
        if(route.request().method()!=='POST') return route.continue();
        const r=await route.fetch(); const t=await r.text();
        try{ seen=JSON.parse(t); }catch(e){ seen={raw:t.slice(0,200)}; }
        await route.fulfill({response:r, body:t});
      });
      const applied = await p.evaluate(s=>{
        const out=[];
        for (const [k,v] of Object.entries(s)) {
          const el=document.querySelector('[name="'+k+'"]');
          if(!el){out.push(k+':MISSING');continue;}
          el.value=v; el.dispatchEvent(new Event('input',{bubbles:true}));
          el.dispatchEvent(new Event('change',{bubbles:true}));
          out.push(k+'='+el.value);
        }
        return out;
      }, c.set);
      await p.evaluate(()=>window.scrollTo(0,document.body.scrollHeight));
      await p.waitForTimeout(150);
      await p.click('.ss-actionbar [data-ss-click-proxy]');
      await p.waitForTimeout(3500);

      const ui = await p.evaluate(fieldName=>{
        const el=document.querySelector('[name="'+fieldName+'"]');
        const toast=document.querySelector('.toast-message, .toast-error, #toast-container');
        /* field-level: DS marks the control and renders a message near it */
        let near='';
        if(el){
          const wrap = el.closest('.form-group,.ss-field,.col-md-6,.mb-3') || el.parentElement;
          const m = wrap && wrap.querySelector('.invalid-feedback,.ss-field__error,[data-ss-field-error]');
          near = m ? (m.textContent||'').trim() : '';
        }
        return {
          toast: toast ? (toast.textContent||'').trim().slice(0,160) : '',
          invalidClass: el ? (el.classList.contains('is-invalid')||el.getAttribute('aria-invalid')==='true') : null,
          fieldMsg: near,
          dsHasApply: !!(window.StoreSyriaDS && typeof window.StoreSyriaDS.applyFormErrors==='function'),
        };
      }, c.field);

      const label = lang+'/'+c.id;
      ck(seen && seen.flag==='error', label+' server refuses the save', 'applied=['+applied.join(',')+'] flag='+(seen&&seen.flag));
      ck(!!(seen && seen.errors && seen.errors[c.field]), label+' errors[] names the field',
         'keys='+JSON.stringify(Object.keys((seen&&seen.errors)||{})));
      ck(!!ui.dsHasApply, label+' StoreSyriaDS.applyFormErrors is present');
      ck(ui.invalidClass===true, label+' the control is marked invalid');
      ck(ui.fieldMsg.length>0, label+' a message is rendered AT the field', JSON.stringify(ui.fieldMsg).slice(0,110));
      if (lang==='ar') {
        ck(/[؀-ۿ]/.test(ui.fieldMsg||ui.toast||''), label+' the message is in Arabic',
           JSON.stringify((ui.fieldMsg||ui.toast||'')).slice(0,110));
      }
      ck(!/:\w+/.test(ui.fieldMsg||''), label+' no leaked :placeholder', JSON.stringify(ui.fieldMsg).slice(0,110));
      await p.unroute(u=>/\/product\/\d+$/.test(u.pathname||''));
    }
    if (perr.length) ck(false,lang+' no page errors', perr.slice(0,2).join(' | '));
    else ck(true, lang+' no page errors');
    await ctx.close();
  }
  await b.close();
  console.log('\n  ===== ' + pass + ' pass, ' + fail + ' fail =====');
  process.exit(fail?1:0);
})().catch(e=>{console.error('ERR '+e.message);process.exit(2);});
