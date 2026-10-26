'use strict';
/* Does a variant price edit persist if the merchant FIRST re-selects the attribute configuration
   (the only path on which `for_variation_*` and `options_datas` are posted)? And do the existing
   variants survive that rebuild? */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE='http://127.0.0.1:8899';
const REPO='/home/user/syriastore98';
function db(expr){
  const code='require "vendor/autoload.php"; $a = require "bootstrap/app.php"; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo json_encode('+expr+');';
  return JSON.parse(execFileSync('php',['-r',code],{cwd:REPO,env:Object.assign({},process.env,{
    DB_CONNECTION:'sqlite', DB_DATABASE:path.join(REPO,'database','p2dev.sqlite'), CACHE_STORE:'file'}),encoding:'utf8'}));
}
(async()=>{
  execFileSync('php',['artisan','tinker','--execute',"require base_path('database/seed-p2dev.php');"],
    {cwd:REPO,env:Object.assign({},process.env,{DB_CONNECTION:'sqlite',DB_DATABASE:path.join(REPO,'database','p2dev.sqlite'),CACHE_STORE:'file'}),stdio:'pipe'});

  const before = db('DB::table("product_variants")->where("product_id",1)->orderBy("id")->get(["variant","variation_price","stock","sku"])');
  console.log('  BEFORE: ' + before.length + ' variants, prices=' + JSON.stringify(before.map(v=>v.variation_price)));

  const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1440,height:1100}});
  const p=await ctx.newPage();
  const errs=[]; p.on('pageerror',e=>errs.push(String(e.message).slice(0,140)));
  let posted=null;
  await p.route(u=>/\/product\/\d+$/.test(u.pathname||''), async route=>{
    const req=route.request();
    if(req.method()!=='POST') return route.continue();
    const body=req.postData()||'';
    posted = {
      has_for_variation: /name="for_variation_\d+"/.test(body),
      has_options_datas: /name="options_datas/.test(body),
      variant_price_parts: [...body.matchAll(/name="(product_variation_price_[^"]+)"\r?\n\r?\n([^\r\n]*)/g)].map(m=>m[1]+'='+m[2]),
    };
    const resp=await route.fetch(); const t=await resp.text();
    posted.response=t.slice(0,200);
    await route.fulfill({response:resp, body:t});
  });
  await p.goto(BASE+'/login',{waitUntil:'domcontentloaded'});
  await p.fill('input[name="email"]','merchant@example.test');
  await p.fill('input[name="password"]','password');
  await Promise.all([p.waitForLoadState('domcontentloaded'),p.click('button[type="submit"]')]);
  await p.goto(BASE+'/product/1/edit',{waitUntil:'networkidle'});
  await p.waitForTimeout(3000);

  console.log('\n  STEP 1 touch the attribute picker (loads each attribute\'s terms)');
  await p.evaluate(()=>{const s=document.querySelector('#attribute_id');
    if(window.jQuery) jQuery(s).trigger('change'); else s.dispatchEvent(new Event('change',{bubbles:true}));});
  await p.waitForTimeout(3500);

  console.log('  STEP 2 tick "use for variation" and select every term');
  const picked = await p.evaluate(()=>{
    const out={checks:[],terms:[]};
    document.querySelectorAll('[class*="enable_variation_"]').forEach(c=>{
      if(!c.checked){c.checked=true; if(window.jQuery) jQuery(c).trigger('change');}
      out.checks.push((c.className.match(/enable_variation_\S+/)||[])[0]+'='+c.checked);
    });
    document.querySelectorAll('select[id^="attribute_options_"], select.attribute').forEach(s=>{
      Array.from(s.options).forEach(o=>{o.selected=true;});
      out.terms.push(...Array.from(s.selectedOptions).map(o=>o.value));
      if(window.jQuery) jQuery(s).trigger('change');
    });
    /* for_variation_* -- does ticking the box set it? */
    out.for_variation = Array.from(document.querySelectorAll('[name^="for_variation_"]'))
      .map(e=>e.getAttribute('name')+'='+e.value+(e.type==='checkbox'?('/checked='+e.checked):''));
    return out;
  });
  console.log('      checks: ' + JSON.stringify(picked.checks));
  console.log('      terms:  ' + JSON.stringify(picked.terms));
  console.log('      for_variation fields: ' + JSON.stringify(picked.for_variation));
  await p.waitForTimeout(4000);

  console.log('  STEP 3 edit a variant price on the regenerated panels');
  const edited = await p.evaluate(()=>{
    const f=document.querySelector('[name^="product_variation_price_"]');
    if(!f) return {err:'no variant price field after regeneration'};
    f.value='77.25'; f.dispatchEvent(new Event('input',{bubbles:true})); f.dispatchEvent(new Event('change',{bubbles:true}));
    return {name:f.getAttribute('name'), value:f.value,
            panels: document.querySelectorAll('[class*="remove_option_"]').length};
  });
  console.log('      ' + JSON.stringify(edited));

  console.log('  STEP 4 save');
  await p.evaluate(()=>window.scrollTo(0,document.body.scrollHeight));
  await p.waitForTimeout(200);
  await p.click('.ss-actionbar [data-ss-click-proxy]');
  await p.waitForTimeout(8000);

  console.log('\n  POSTED: for_variation_* present=' + (posted&&posted.has_for_variation)
    + '  options_datas present=' + (posted&&posted.has_options_datas));
  console.log('  POSTED variant prices: ' + JSON.stringify((posted&&posted.variant_price_parts)||[]).slice(0,200));
  console.log('  server said: ' + JSON.stringify(posted&&posted.response));

  const after = db('DB::table("product_variants")->where("product_id",1)->orderBy("id")->get(["variant","variation_price","stock","sku"])');
  console.log('\n  AFTER: ' + after.length + ' variants');
  after.forEach(v=>console.log('      ' + v.variant + '  price=' + JSON.stringify(v.variation_price) + '  stock=' + v.stock));
  console.log('\n  variants lost: ' + (before.length - after.length));
  console.log('  pageErrors: ' + errs.length + (errs.length? ' :: '+errs.slice(0,2).join(' | '):''));
  await ctx.close(); await b.close();
})().catch(e=>{console.error('ERR '+e.message);process.exit(2);});
