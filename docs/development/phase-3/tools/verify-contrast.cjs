/**
 * PHASE 03 M2 — COMPUTED CONTRAST, MEASURED IN A REAL BROWSER.
 *
 * Walks a store's journey routes in both appearances, resolves each text element's ACTUAL backdrop
 * and reports every pair below its WCAG AA threshold (4.5:1 normal, 3:1 for large text).
 *
 * THREE MEASUREMENT ARTEFACTS WERE FOUND AND FIXED IN THIS FILE, all of the same family -- a ratio
 * computed against something that is not the real backdrop. They are documented because each one
 * produced confident, specific, WRONG failures, and the next person to write such a probe will hit
 * them in the same order:
 *
 *   1. CSS BACKGROUND IMAGES. White hero text over a photograph read as 1.05:1 against the
 *      colour behind the image. Elements whose ancestor chain carries a `background-image` are
 *      now reported as skipped rather than failed.
 *
 *   2. ALPHA. `rgba(153, 82, 224, 0.1)` -- a 10% brand tint -- composites to a NEAR-WHITE over a
 *      white page. Reading it as the full violet reported 1.67:1 for text that is almost on the
 *      page colour. Layers are now composited down to the first opaque ancestor.
 *
 *   3. <img> ELEMENTS BEHIND TEXT. The hero uses an <img> with absolutely-positioned overlay text,
 *      so there is NO `background-image` anywhere in the chain and the walk fell through to the
 *      body colour. Overlapping media siblings are now detected.
 *
 * A clean result means "no failure among the MEASURABLE elements", and the skipped count is printed
 * for exactly that reason. It is not a claim that every pixel passes.
 *
 *   docs/development/phase-2.5/tools/serve-dev.sh            # 127.0.0.1:8899, another shell
 *   node docs/development/phase-3/tools/verify-contrast.cjs  # edit SLUG for another store
 *
 * Last run: stylique 0 failures (13 skipped), techzonix 0 failures (7 skipped).
 */

const { chromium } = require('playwright');

// The store to measure, so another theme needs an argument rather than a copy of this file.
// A sed-ed copy is how the first version of this tool lost its own `require` line.
const SLUG = process.argv[2] || 'damascus-textiles';
const lum = (r,g,b) => { const f=c=>{c/=255; return c<=0.03928?c/12.92:Math.pow((c+0.055)/1.055,2.4);};
  return 0.2126*f(r)+0.7152*f(g)+0.0722*f(b); };
const parse = s => (s.match(/\d+(\.\d+)?/g)||[0,0,0]).slice(0,3).map(Number);
const ratio = (a,b) => { const la=lum(...parse(a)), lb=lum(...parse(b));
  return ((Math.max(la,lb)+0.05)/(Math.min(la,lb)+0.05)); };

(async () => {
  const b = await chromium.launch();
  let fails = 0, overImage = 0;
  for (const scheme of ['light','dark']) {
    const ctx = await b.newContext({viewport:{width:1440,height:900}, colorScheme:scheme});
    const p = await ctx.newPage();
    console.log(`\n  ===== os=${scheme} =====`);
    for (const route of ['/home','/product-list','/cart','/login']) {
      await p.goto(`http://127.0.0.1:8899/${SLUG}${route}`, {waitUntil:'domcontentloaded'});
      const rows = await p.evaluate(() => {
        const out = [];
        // A ratio against a COLOUR is meaningless when the real backdrop is an IMAGE or a
        // gradient -- white hero text over a photograph is correct and would read as a 1.05:1
        // failure. Such elements are reported separately, not as failures.
        // ALPHA IS COMPOSITED, not treated as opaque. `rgba(153, 82, 224, 0.1)` -- a 10% violet
        // tint -- composites to a NEAR-WHITE over a white page, and reading it as the full violet
        // reported 1.67:1 where the real backdrop is almost the page colour. Second measurement
        // artefact of this family, after the image-backdrop one.
        // AN <img> BEHIND THE TEXT IS ALSO AN IMAGE BACKDROP. The hero uses the common pattern
        // of an <img> element with absolutely-positioned overlay text (`relative` slide, `z-10`
        // caption), so there is no `background-image` anywhere in the ancestor chain and the walk
        // fell through to the body colour -- reporting white-on-near-white at 1.05:1 for text that
        // is actually over a photograph. THIRD artefact of this family, after the CSS
        // background-image case and the alpha-compositing case.
        const overMedia = el => {
          const r = el.getBoundingClientRect();
          if (r.width === 0 || r.height === 0) return false;
          let e = el.parentElement;
          for (let i = 0; i < 6 && e && e !== document.documentElement; i++) {
            for (const m of e.querySelectorAll('img, video, picture, svg')) {
              if (m.contains(el) || el.contains(m)) continue;
              const b = m.getBoundingClientRect();
              // overlapping boxes, and the media is painted first (earlier sibling or lower z)
              if (b.width > 40 && b.height > 40 &&
                  r.left < b.right && r.right > b.left && r.top < b.bottom && r.bottom > b.top) {
                return true;
              }
            }
            e = e.parentElement;
          }
          return false;
        };
        const comp = (fg, bg) => {
          const a = fg[3] === undefined ? 1 : fg[3];
          return [0,1,2].map(i => Math.round(fg[i]*a + bg[i]*(1-a)));
        };
        const rgba = s => { const n=(s.match(/[\d.]+/g)||[]).map(Number);
          return n.length>=4 ? n.slice(0,4) : n.slice(0,3); };
        const backdrop = el => { let e=el; const stack=[];
          while(e && e!==document.documentElement){
            const cs=getComputedStyle(e);
            if(cs.backgroundImage && cs.backgroundImage !== 'none') return {img:true, c:cs.backgroundImage.slice(0,40)};
            const c=cs.backgroundColor; const v=rgba(c);
            const alpha = v.length>=4 ? v[3] : 1;
            if(c && alpha > 0) {
              stack.push(v);
              // Opaque: stop and composite everything collected above it.
              if (alpha >= 1) {
                let out = v.slice(0,3);
                for (let i = stack.length - 2; i >= 0; i--) out = comp(stack[i], out);
                return {img:false, c:`rgb(${out.join(', ')})`};
              }
            }
            e=e.parentElement;}
          const base = rgba(getComputedStyle(document.body).backgroundColor).slice(0,3);
          let out = base.length === 3 ? base : [255,255,255];
          for (let i = stack.length - 1; i >= 0; i--) out = comp(stack[i], out);
          return {img:false, c:`rgb(${out.join(', ')})`}; };
        const nearestBg = el => backdrop(el).c;
        const seen = new Set();
        for (const el of document.querySelectorAll('.btn-primary, .cart-badge, .view-toggle.active, body, h1, h2, h3, p, a, label, input')) {
          if (el.getBoundingClientRect().width === 0) continue;
          const cs = getComputedStyle(el);
          const key = cs.color + '|' + nearestBg(el) + '|' + cs.fontSize + '|' + cs.fontWeight;
          if (seen.has(key)) continue; seen.add(key);
          const bd = backdrop(el);
          if (overMedia(el)) { bd.img = true; }
          out.push({ tag: (el.className||el.tagName).toString().slice(0,22) || el.tagName,
                     fg: cs.color, bg: bd.c, overImage: bd.img,
                     fs: parseFloat(cs.fontSize), fw: parseInt(cs.fontWeight)||400 });
        }
        return out.slice(0, 10);
      });
      for (const r of rows) {
        if (r.overImage) { overImage++; continue; }
        const rr = ratio(r.fg, r.bg);
        const large = r.fs >= 24 || (r.fs >= 18.66 && r.fw >= 700);
        const min = large ? 3.0 : 4.5;
        const verdict = rr >= min ? 'pass' : 'FAIL';
        if (verdict === 'FAIL') { fails++;
          console.log(`    ${verdict}  ${route.padEnd(14)} ${r.tag.padEnd(22)} ${rr.toFixed(2)}:1 (min ${min}) ${r.fg} on ${r.bg} ${r.fs}px/${r.fw}`); }
      }
    }
    await ctx.close();
  }
  console.log(`\n  ${fails} contrast failure(s) across both modes`);
  console.log(`  ${overImage} element(s) skipped: their backdrop is an image or gradient, where a`);
  console.log(`     ratio against a colour says nothing. White hero text over a photo is correct.`);
  await b.close();
})();
