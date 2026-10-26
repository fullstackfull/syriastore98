'use strict';
/**
 * F75 follow-up -- the refusal message embeds a MERCHANT-CONTROLLED FILENAME into HTML.
 *
 * NO REAL CUSTOMER DATA. The tenant is `database/seed-p2dev.php`.
 *
 * WHY THIS IS A CONTROL AND NOT DECORATION. The F75 warning is built as markup
 * (`<br> <span class="text-danger">...`) and rendered by `notifier.show()`, which treats it as
 * markup. The filename inside it comes from `getClientOriginalName()` -- whatever the client sent,
 * and `Dropzone.addFile()` with a constructed `File` accepts any name, so it is fully
 * merchant-controlled and reachable. `e()` is what stands between the two, so it is measured with
 * a filename that would execute if it were interpolated raw.
 *
 * Self-XSS by a merchant against their own editor is low severity; the reason to pin it is that the
 * same message shape is the obvious thing to reuse somewhere a second principal reads it.
 *
 * usage: node check-rejected-filename-escaping-live.cjs
 */
/* original note: the refusal message embeds a MERCHANT-CONTROLLED FILENAME into HTML.
   `getClientOriginalName()` is whatever the client sent, and `notifier.show()` renders the
   message as markup, so the `e()` escaping is a real control and not decoration. Measured with a
   filename that would execute if it were interpolated raw. */
const { chromium } = require('playwright');
const BASE='http://127.0.0.1:8899';
const PNG='iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAQklEQVR4nO3OMQEAIAzAsMH/9ygDGrhJ+ts9JjMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzO7cQF7mQGJ3wZ0sQAAAABJRU5ErkJggg==';
(async()=>{
  const b=await chromium.launch();
  const ctx=await b.newContext({viewport:{width:1440,height:1000}});
  const p=await ctx.newPage();
  const errs=[]; p.on('pageerror',e=>errs.push(String(e.message).slice(0,120)));
  let fired=false;
  await p.exposeFunction('__xssFired', () => { fired = true; });
  await p.addInitScript(() => { window.__probeMarker = true; });
  let body=null;
  await p.route(u=>/\/product$/.test(u.pathname||''), async r=>{
    if (r.request().method()!=='POST') return r.continue();
    const resp=await r.fetch(); body=await resp.text(); await r.fulfill({response:resp, body});
  });
  await p.goto(BASE+'/login',{waitUntil:'domcontentloaded'});
  await p.fill('input[name="email"]','merchant@example.test');
  await p.fill('input[name="password"]','password');
  await Promise.all([p.waitForLoadState('domcontentloaded'),p.click('button[type="submit"]')]);
  await p.goto(BASE+'/product/create',{waitUntil:'networkidle'});
  await p.waitForTimeout(1600);
  const name='XSS Probe '+Date.now().toString().slice(-6);
  await p.fill('input[name="name"]',name);
  await p.fill('input[name="price"]','30');
  await p.fill('input[name="cost_price"]','10');
  await p.evaluate(()=>{const e=document.querySelector('#category_id');const w=e&&e.closest('.choices');if(w)w.setAttribute('data-t','1');});
  const inner=await p.$('[data-t="1"] .choices__inner');
  if(inner){await inner.click().catch(()=>{});await p.waitForTimeout(400);
    for(const it of await p.$$('[data-t="1"] .choices__list--dropdown .choices__item--selectable')){
      const t=(await it.textContent()||'').trim(); const v=await it.getAttribute('data-value');
      if(!t||/^select\b/i.test(t)||!v)continue; await it.click().catch(()=>{}); break;}}
  await p.evaluate(()=>{const s=document.querySelector('#tag_id');
    if(s&&s.options.length){s.options[0].selected=true; if(window.jQuery&&jQuery(s).data('select2'))jQuery(s).trigger('change');}
    const c=document.querySelector('#status'); if(c&&!c.checked)c.click();});
  await p.evaluate(b64=>{const bin=atob(b64);const by=new Uint8Array(bin.length);
    for(let i=0;i<bin.length;i++)by[i]=bin.charCodeAt(i);
    const dt=new DataTransfer(); dt.items.add(new File([by],'cover.png',{type:'image/png'}));
    const el=document.querySelector('#cover_image'); el.files=dt.files;
    el.dispatchEvent(new Event('change',{bubbles:true}));},PNG);
  /* A filename that is also a payload, on a file the server will refuse (text named .png), so
     the refusal path -- the one that embeds the name -- is the one exercised. */
  const HOSTILE = '"><img src=x onerror=window.__xssFired()>.png';
  await p.evaluate(async([b64,hostile])=>{
    const el=document.querySelector('[data-toggle="dropzone1"]');
    const bin=atob(b64);const by=new Uint8Array(bin.length);
    for(let i=0;i<bin.length;i++)by[i]=bin.charCodeAt(i);
    el.dropzone.addFile(new File([by],'good.png',{type:'image/png'}));
    el.dropzone.addFile(new File(['not an image'],hostile,{type:'image/png'}));
    await new Promise(r=>setTimeout(r,1200));
  },[PNG,HOSTILE]);
  await p.evaluate(()=>window.scrollTo(0,document.body.scrollHeight));
  await p.waitForTimeout(200);
  await p.click('.ss-actionbar [data-ss-click-proxy]');
  await p.waitForURL(/\/product(\?|$)/,{timeout:30000}).catch(()=>{});
  await p.waitForTimeout(4000);
  const st=await p.evaluate(()=>({
    toast: Array.from(document.querySelectorAll('.notifier,.notifier-container'))
      .map(e=>e.textContent.trim().replace(/\s+/g,' ')).join(' | ').slice(0,260),
    injectedImg: document.querySelectorAll('.notifier img[src="x"], .notifier-container img[src="x"]').length,
  }));
  console.log('  server msg: ' + JSON.stringify(String((JSON.parse(body||'{}')).msg||'').slice(0,240)));
  console.log('  toast text: ' + JSON.stringify(st.toast));
  console.log('  injected <img src=x> in the toast : ' + st.injectedImg);
  console.log('  onerror handler fired            : ' + fired);
  console.log('  pageErrors: ' + JSON.stringify(errs));
  console.log(st.injectedImg === 0 && fired === false
    ? '  VERDICT: the filename is ESCAPED -- no element injected, no handler ran.'
    : '  VERDICT: *** THE FILENAME IS NOT ESCAPED ***');
  await ctx.close(); await b.close();
  process.exit(st.injectedImg === 0 && fired === false ? 0 : 1);
})().catch(e=>{console.error('ERR '+e.message);process.exit(2);});
