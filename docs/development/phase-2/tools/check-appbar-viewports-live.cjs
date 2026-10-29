/**
 * D-25 — THE SHARED HEADER IN EVERY CONFIGURATION THE OWNER'S CONDITIONS NAME.
 *
 * Two viewports x four theme/direction combinations = eight measurements, which is the matrix
 * D-25 requires at every step: Arabic and English, RTL and LTR, dark and light, phone and
 * desktop, with contrast computed rather than eyeballed.
 *
 * WHAT IT MEASURES, and the choice of subject is the point: the colour a NEW, UNSTYLED element
 * inherits from the header, against the effective background behind it. Every visible node in
 * this header already has a rule of its own -- F158 patched them one at a time -- so a census of
 * visible nodes reports 0 below AA and tells you nothing about the header's own inheritance,
 * which was `rgb(82, 91, 105)` in BOTH themes and 2.07:1 on the dark surface. The probe appends a
 * bare <span>, reads what it inherits, removes it, and walks up the ancestors for the first
 * non-transparent background so the ratio is against what is actually behind the text.
 *
 * It also records the header's height, the count of visible controls, whether the document
 * overflows horizontally, and page errors -- so a colour change that costs layout shows up here
 * rather than in a screenshot somebody has to remember to look at.
 *
 * MEASURED after D-25 Step 1:
 *
 *   desktop 1400x900   light 5.94:1   dark 6.55:1    h=70-71   7 controls   no overflow
 *   phone    390x844   light 5.94:1   dark 7.11:1    h=119     8 controls   no overflow
 *
 * The phone's eighth control is the hamburger, which the desktop layout hides.
 *
 * usage: node docs/development/phase-2/tools/check-appbar-viewports-live.cjs
 */
'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const REPO='/home/user/syriastore98';
const ENV=Object.assign({},process.env,{DB_CONNECTION:'sqlite',DB_DATABASE:path.join(REPO,'database','p2dev.sqlite'),CACHE_STORE:'file',SESSION_DRIVER:'file'});
const srgb = c => { c/=255; return c <= 0.03928 ? c/12.92 : Math.pow((c+0.055)/1.055, 2.4); };
const lum = ([r,g,b]) => 0.2126*srgb(r)+0.7152*srgb(g)+0.0722*srgb(b);
const ratio = (a,b) => { const [x,y]=[lum(a),lum(b)].sort((p,q)=>q-p); return (x+0.05)/(y+0.05); };
const rgb = s => (s.match(/\d+/g)||[0,0,0]).slice(0,3).map(Number);
(async()=>{
 for (const [w,h,label] of [[1400,900,'desktop'],[390,844,'phone']]) {
  for (const [theme,dir,lang] of [['light','ltr','en'],['dark','ltr','en'],['light','rtl','ar'],['dark','rtl','ar']]) {
    execFileSync('php',[path.join(REPO,'docs/development/phase-2/tools/set-admin-preview-state.php'),'1',theme,dir,lang,'merchant@example.test'],{cwd:REPO,env:ENV,stdio:'pipe'});
    const b=await chromium.launch({args:['--no-sandbox']});
    const c=await b.newContext({viewport:{width:w,height:h}});
    await c.route('**/merchant-notifications/feed*',r=>r.abort());
    const p=await c.newPage();
    const errs=[]; p.on('pageerror',e=>errs.push(String(e).slice(0,90)));
    await p.goto('http://127.0.0.1:8899/login',{waitUntil:'domcontentloaded'});
    await p.fill('input[name=email]','merchant@example.test'); await p.fill('input[name=password]','password');
    await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}).catch(()=>{}),p.click('button[type=submit]')]);
    await p.goto('http://127.0.0.1:8899/dashboard',{waitUntil:'domcontentloaded'});
    await p.waitForTimeout(1400);
    const r = await p.evaluate(() => {
      const hd = document.querySelector('header.dash-header');
      const cs = getComputedStyle(hd);
      const probe = document.createElement('span'); probe.textContent='x'; hd.appendChild(probe);
      const inherited = getComputedStyle(probe).color; probe.remove();
      // the effective background behind the header text
      let bgEl = hd, bg = cs.backgroundColor;
      while (bgEl && (bg === 'rgba(0, 0, 0, 0)' || bg === 'transparent')) { bgEl = bgEl.parentElement; if (!bgEl) break; bg = getComputedStyle(bgEl).backgroundColor; }
      return { hasAppbarClass: hd.classList.contains('ss-appbar'),
               inherited, bg, height: Math.round(hd.getBoundingClientRect().height),
               overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth,
               visibleControls: Array.from(hd.querySelectorAll('a[href],button')).filter(e=>e.offsetWidth||e.offsetHeight).length };
    });
    const cr = ratio(rgb(r.inherited), rgb(r.bg));
    console.log(`[${label} ${w}x${h}] ${theme}/${dir}/${lang}  appbar=${r.hasAppbarClass} inherited=${r.inherited} on ${r.bg} => ${cr.toFixed(2)}:1 ${cr>=4.5?'AA':'FAIL'}  h=${r.height} controls=${r.visibleControls} overflowX=${r.overflowX} errs=${errs.length}`);
    await b.close();
  }
 }
})();
