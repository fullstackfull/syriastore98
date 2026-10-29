/**
 * KEYBOARD TRAVERSAL OF A MERCHANT SCREEN, measured by pressing Tab.
 *
 * WRITTEN FOR D-25, WHICH REQUIRES KEYBOARD VERIFICATION AT EVERY STEP -- and it found a defect
 * before the conversion it was built to verify had even begun.
 *
 * WHAT IT REPORTS, per theme/direction: how many tabbable controls the page has, where the shared
 * header's begin in DOM order, and then the ZONE of every one of 80 Tab stops -- sidebar, header,
 * main, palette. A zone census is the measurement that matters: "focus is somewhere" is easy to
 * satisfy and tells you nothing, while "77 of 80 stops are inside a dialog" names the defect.
 *
 * MEASURED, /dashboard, 2026-10-29:
 *
 *   before F177   SIDE 3, PALETTE 77, HEADER 0    -- the palette opened on `focusin` from the
 *                                                    sidebar search and `cmdOpen()` moved focus
 *                                                    into it, so Tab never advanced past it
 *   after F177    SIDE 17, HEADER 7, MAIN 55, BODY 1
 *
 * IT ALSO RECORDS THE FOCUS RING on each header stop, since that is the other half of keyboard
 * access. The ring is already DS-driven -- `solid 2px rgb(8, 145, 127)`, the teal focus token, via
 * `:focus-visible` -- and that is worth knowing before "fixing" it: a PROGRAMMATIC `element.focus()`
 * does NOT set `:focus-visible`, so an earlier probe that focused by script measured
 * `outlineStyle: none` and very nearly recorded a missing focus ring that keyboard users do see.
 *
 * usage: node docs/development/phase-2/tools/check-keyboard-traversal-live.cjs
 */
'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const REPO='/home/user/syriastore98';
const ENV=Object.assign({},process.env,{DB_CONNECTION:'sqlite',DB_DATABASE:path.join(REPO,'database','p2dev.sqlite'),CACHE_STORE:'file',SESSION_DRIVER:'file'});
(async()=>{
  execFileSync('php',[path.join(REPO,'docs/development/phase-2/tools/set-admin-preview-state.php'),'1','light','ltr','en','merchant@example.test'],{cwd:REPO,env:ENV,stdio:'pipe'});
  const b=await chromium.launch({args:['--no-sandbox']});
  const c=await b.newContext({viewport:{width:1400,height:900}});
  await c.route('**/merchant-notifications/feed*',r=>r.abort());
  const p=await c.newPage();
  await p.goto('http://127.0.0.1:8899/login',{waitUntil:'domcontentloaded'});
  await p.fill('input[name=email]','merchant@example.test'); await p.fill('input[name=password]','password');
  await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}).catch(()=>{}),p.click('button[type=submit]')]);
  await p.goto('http://127.0.0.1:8899/dashboard',{waitUntil:'domcontentloaded'});
  await p.waitForTimeout(1600);

  const facts = await p.evaluate(() => {
    const headers = Array.from(document.querySelectorAll('header'));
    const vis = e => !!(e.offsetWidth || e.offsetHeight || e.getClientRects().length);
    const tabbable = e => {
      if (!vis(e)) return false;
      const ti = e.getAttribute('tabindex');
      if (ti !== null && parseInt(ti, 10) < 0) return false;
      if (e.disabled) return false;
      if (e.closest('[hidden]')) return false;
      if (e.tagName === 'A' && !e.hasAttribute('href')) return false;
      return true;
    };
    const all = Array.from(document.querySelectorAll('a[href],button,input,select,textarea,[tabindex]'));
    const h = document.querySelector('header.dash-header');
    const headerTabbable = Array.from(h.querySelectorAll('a[href],button,input,select,[tabindex]')).filter(tabbable);
    return {
      headerCount: headers.length,
      headerClasses: headers.map(x => x.className),
      pageTabbable: all.filter(tabbable).length,
      headerTabbable: headerTabbable.length,
      headerTabbableNames: headerTabbable.map(e => (e.textContent||'').trim().slice(0,20) || e.getAttribute('aria-label') || e.tagName),
      headerDomIndex: all.findIndex(e => h.contains(e)),
    };
  });
  console.log('facts:', JSON.stringify(facts, null, 1));

  await p.evaluate(() => { const f = document.querySelector('a[href],button'); if (f) f.blur(); document.body.setAttribute('tabindex','-1'); document.body.focus(); });
  const inHeader = [];
  const seqAll = [];
  for (let i = 0; i < 80; i++) {
    await p.keyboard.press('Tab');
    const got = await p.evaluate(() => {
      const el = document.activeElement;
      const h = document.querySelector('header.dash-header');
      if (!el || !h || !h.contains(el)) return null;
      const cs = getComputedStyle(el);
      return { name: (el.textContent||'').trim().slice(0,22) || el.getAttribute('aria-label') || el.tagName,
               outline: `${cs.outlineStyle} ${cs.outlineWidth} ${cs.outlineColor}`,
               shadow: cs.boxShadow === 'none' ? 'none' : cs.boxShadow.slice(0,40) };
    });
    if (got) inHeader.push([i+1, got]);
    const where = await p.evaluate(() => {
      const pal = document.querySelector('#ss-command');
      window.__palState = pal ? (getComputedStyle(pal).display + '/hidden=' + pal.hasAttribute('hidden')) : 'none';
      const el = document.activeElement;
      if (!el || el === document.body) return 'BODY';
      const h = document.querySelector('header.dash-header');
      const zone = h && h.contains(el) ? 'HEADER' :
                   (el.closest('.dash-sidebar,nav,aside') ? 'SIDE' :
                   (el.closest('#ss-command') ? 'PALETTE' : 'MAIN'));
      return zone + ':' + el.tagName + (el.id ? '#'+el.id : '') + ' "' + ((el.textContent||'').trim().slice(0,16)) + '" pal=' + window.__palState;
    });
    seqAll.push((i+1) + ' ' + where);
  }
  console.log('\nheader nodes reached by Tab within 80 presses:', inHeader.length);
  inHeader.slice(0,8).forEach(([i,g]) => console.log(' ', i, JSON.stringify(g)));
  console.log('\nzone counts:', JSON.stringify(seqAll.reduce((a,s)=>{const z=s.split(' ')[1].split(':')[0];a[z]=(a[z]||0)+1;return a;},{})));
  console.log('first 14:'); seqAll.slice(0,14).forEach(s=>console.log('  ',s));
  await b.close();
})();
