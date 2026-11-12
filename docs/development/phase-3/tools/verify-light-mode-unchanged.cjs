/**
 * PHASE 03 M2 — DID A THEME'S LIGHT MODE CHANGE?
 *
 * Renders a store twice -- once as the working tree has it, once with the COMMITTED versions of
 * BOTH the theme stylesheet AND the shared appearance layer served in their place -- and reports
 * every computed-property difference, grouped by before -> after pair.
 *
 * BOTH FILES MATTER, and getting that wrong produced a misleading number. The first version
 * swapped only the theme stylesheet, leaving the NEW shared layer in place, so changes caused by
 * the shared layer were attributed to the theme. A regression check that reverts half the change
 * measures the wrong thing.
 *
 *   node docs/development/phase-3/tools/verify-light-mode-unchanged.cjs techzonix p3fixt-homs-tech
 *
 * It needs the committed files extracted first:
 *   git show HEAD:themes/<theme>/assets/css/main-style.css > <scratch>/<tz|sty>-head.css
 *   git show HEAD:public/css/storefront-appearance.css      > <scratch>/layer-head.css
 */
const { chromium } = require('playwright');
const fs = require('fs');
const S = '/tmp/claude-0/-home-user/c22d1090-9e12-5711-882b-d4cba4c5ce19/scratchpad';
const B = 'http://127.0.0.1:8899';
const THEME = process.argv[2];            // stylique | techzonix | greentic
const SLUG  = process.argv[3];

// M2-E FIX. This was `THEME === 'techzonix' ? 'tz' : 'sty'`, written when only two themes had been
// tokenised. Passing `greentic` therefore injected STYLIQUE's committed stylesheet into greentic's
// page, and the run reported coral-to-green "regressions" plus button geometry changes that no
// greentic edit could have caused. A verifier that silently compares against the wrong baseline is
// worse than no verifier: it produces a confident number about nothing. Now one file per theme,
// and an unknown theme fails loudly instead of defaulting.
const HEAD_FILE = `${S}/${THEME}-head.css`;
if (!THEME || !SLUG || !fs.existsSync(HEAD_FILE)) {
  console.error(`usage: verify-light-mode-unchanged.cjs <theme> <slug>
  and extract the baseline first:
    git show HEAD:themes/${THEME || '<theme>'}/assets/css/main-style.css > ${HEAD_FILE}
    git show HEAD:public/css/storefront-appearance.css > ${S}/layer-head.css`);
  process.exit(2);
}
const HEAD  = fs.readFileSync(HEAD_FILE, 'utf8');
const LAYER = fs.readFileSync(`${S}/layer-head.css`, 'utf8');
const PROPS = ['color','backgroundColor','borderColor','borderWidth','fontSize','fontWeight','padding','margin','display','borderRadius','boxShadow'];
const SAMPLE = () => { const o={}; const sel=['body','header','footer','h1','h2','h3','a','p','input','label','nav','img','ul','li','button:not([data-ss-appearance-toggle])','.card','.input','.form-input','.container','form','span','div','.text-primary'];
  for(const s of sel){[...document.querySelectorAll(s)].slice(0,8).forEach((el,i)=>{const cs=getComputedStyle(el);
    o[`${s}#${i}`]=['color','backgroundColor','borderColor','borderWidth','fontSize','fontWeight','padding','margin','display','borderRadius','boxShadow'].map(p=>cs[p]);});} return o; };
(async()=>{
  const b=await chromium.launch(); const tally={}, pairs={};
  for(const route of ['/home','/product-list','/collections','/cart','/contact-us','/login']){
    const c1=await b.newContext({viewport:{width:1440,height:900},colorScheme:'light'});
    const p1=await c1.newPage();
    await p1.goto(B+'/'+SLUG+route,{waitUntil:'domcontentloaded'}); await p1.waitForTimeout(220);
    const after=await p1.evaluate(SAMPLE); await c1.close();

    // THE TRUE BEFORE: the COMMITTED theme stylesheet AND the COMMITTED shared layer.
    const c2=await b.newContext({viewport:{width:1440,height:900},colorScheme:'light'});
    const p2=await c2.newPage();
    await p2.route(`**/themes/${THEME}/assets/css/main-style.css`, r=>r.fulfill({status:200,contentType:'text/css',body:HEAD}));
    await p2.route('**/css/storefront-appearance.css', r=>r.fulfill({status:200,contentType:'text/css',body:LAYER}));
    await p2.goto(B+'/'+SLUG+route,{waitUntil:'domcontentloaded'}); await p2.waitForTimeout(220);
    const before=await p2.evaluate(SAMPLE); await c2.close();

    for(const k of new Set([...Object.keys(after),...Object.keys(before)])){
      const a=after[k]||[], bb=before[k]||[];
      for(let i=0;i<PROPS.length;i++){ if(a[i]!==bb[i]){
        tally[PROPS[i]]=(tally[PROPS[i]]||0)+1;
        const key=PROPS[i]+': '+bb[i]+' -> '+a[i]; pairs[key]=(pairs[key]||0)+1; } }
    }
  }
  console.log(JSON.stringify({tally,pairs},null,1)); await b.close();
})();
