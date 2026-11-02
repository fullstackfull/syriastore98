'use strict';
/**
 * P2-03-4 / F75 — the upload hints in both directions, both themes and at phone width.
 *
 * NO REAL CUSTOMER DATA. The tenant is `database/seed-p2dev.php`.
 *
 * WHY THE PLACEHOLDER CASE IS HERE. The hints interpolate `:size` and `:total` through
 * `__($key, [...])`. The first version of the markup wrote `{{ __('Up to :size per file') }}:
 * {{ $value }}` -- a translation call with no replacement array -- so the page would have read
 * "Up to :size per file: 2 MB" with the placeholder showing. That is invisible to a test that only
 * asks whether the hint exists, so it is asserted directly.
 *
 * THE CONTRAST HALF IS NOT HERE: `measure-contrast-live.cjs` owns it, and reads both hints in all
 * four configurations (56 measured, 0 below AA). This file covers what that one cannot -- the
 * translated text, the inherited direction, and the absence of horizontal overflow at 390px.
 *
 * usage: node check-upload-hint-bilingual-live.cjs
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const BASE='http://127.0.0.1:8899';
const set=(t,d)=>execFileSync('php',['docs/development/phase-2/tools/set-preview-state.php'],
  {env:Object.assign({},process.env,{PREVIEW_THEME:t,PREVIEW_DIR:d}),cwd:'/home/user/syriastore98'});
let cases=0; const fails=[];
const check=(l,c,d)=>{cases++; console.log((c?'  ok    ':'  FAIL  ')+l+(d?'  ['+d+']':'')); if(!c) fails.push(l+(d?'  ['+d+']':''));};
(async()=>{
  const b=await chromium.launch();
  try {
    for (const [dir,lang,theme] of [['rtl','ar','dark'],['rtl','ar','light'],['ltr','en','dark']]) {
      set(theme,dir);
      for (const w of [1440, 390]) {
        const ctx=await b.newContext({viewport:{width:w,height:900}});
        const p=await ctx.newPage();
        await p.goto(BASE+'/login',{waitUntil:'domcontentloaded'});
        await p.fill('input[name="email"]','merchant@example.test');
        await p.fill('input[name="password"]','password');
        await Promise.all([p.waitForLoadState('domcontentloaded'),p.click('button[type="submit"]')]);
        await p.goto(BASE+'/change-language/'+lang,{waitUntil:'domcontentloaded'}).catch(()=>{});
        await p.goto(BASE+'/product/create',{waitUntil:'networkidle'});
        await p.waitForTimeout(1500);
        const r=await p.evaluate(()=>{
          const g=document.querySelector('#ss-gallery-upload-hint');
          const c=document.querySelector('#ss-cover-upload-hint');
          const t=(e)=>e?e.textContent.trim().replace(/\s+/g,' '):null;
          return {
            dir: document.documentElement.getAttribute('dir') || getComputedStyle(document.body).direction,
            gallery: t(g), cover: t(c),
            galleryDir: g?getComputedStyle(g).direction:null,
            overflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            placeholderLeak: /:size|:total|:max/.test((t(g)||'')+(t(c)||'')),
          };
        });
        const tag = dir+'/'+lang+'/'+theme+' @'+w;
        console.log('    '+tag+'  dir='+r.dir+'  overflowX='+r.overflowX);
        console.log('       gallery: '+JSON.stringify((r.gallery||'').slice(0,96)));
        check(tag+': the hint is rendered', !!r.gallery && r.gallery.length > 10, String(r.gallery));
        check(tag+': no untranslated placeholder leaked', r.placeholderLeak === false,
              'gallery='+String(r.gallery).slice(0,80));
        check(tag+': no horizontal page overflow', r.overflowX <= 1, 'overflowX='+r.overflowX);
        if (lang === 'ar') {
          check(tag+': the hint is in Arabic', /[؀-ۿ]/.test(r.gallery||''), String(r.gallery).slice(0,70));
          check(tag+': the hint inherits RTL', r.galleryDir === 'rtl', String(r.galleryDir));
        }
        await ctx.close();
      }
    }
  } finally { await b.close(); set('light','ltr'); try{ execFileSync('bash',['-c','curl -s -o /dev/null http://127.0.0.1:8899/change-language/en']); }catch(e){} }
  console.log('');
  if (fails.length) { console.log('FAIL '+fails.length+' of '+cases); fails.forEach(f=>console.log('   - '+f)); process.exit(1); }
  console.log('PASS '+cases+'/'+cases);
})().catch(e=>{console.error('ERR '+e.message);process.exit(2);});
