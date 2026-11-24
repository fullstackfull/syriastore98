const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');

const DIR = process.argv[2];
const combos = fs.readdirSync(DIR).filter(d => fs.statSync(path.join(DIR, d)).isDirectory());

const lum = ([r,g,b]) => {
  const f = c => { c/=255; return c <= 0.03928 ? c/12.92 : Math.pow((c+0.055)/1.055, 2.4); };
  return 0.2126*f(r) + 0.7152*f(g) + 0.0722*f(b);
};
const ratio = (a,b) => { const [x,y]=[lum(a),lum(b)]; const hi=Math.max(x,y), lo=Math.min(x,y); return (hi+0.05)/(lo+0.05); };
const parse = s => (s.match(/[\d.]+/g)||[]).slice(0,4).map(Number);

(async () => {
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  for (const combo of combos) {
    const html = fs.readdirSync(path.join(DIR, combo)).find(f => f.endsWith('.html'));
    if (!html) continue;
    const page = await browser.newPage({ viewport: { width: 1280, height: 1000 }, deviceScaleFactor: 1 });
    await page.goto('file://' + path.join(DIR, combo, html));
    await page.waitForTimeout(500);

    const out = await page.evaluate(() => {
      const parseRgb = s => (s.match(/[\d.]+/g)||[]).slice(0,4).map(Number);
      // composite up to the first opaque ancestor -- a transparent parent otherwise
      // makes every probe compare against black, which this repo has measured as a false reading
      const opaqueBg = el => {
        let n = el;
        while (n) {
          const c = parseRgb(getComputedStyle(n).backgroundColor);
          if (c.length >= 3 && (c[3] === undefined || c[3] > 0.99)) return [c[0],c[1],c[2]];
          n = n.parentElement;
        }
        const b = parseRgb(getComputedStyle(document.body).backgroundColor);
        return b.length >= 3 ? [b[0],b[1],b[2]] : [255,255,255];
      };
      const hints = [...document.querySelectorAll('.ss-stat__hint')];
      return {
        count: hints.length,
        dir: document.documentElement.getAttribute('dir') || getComputedStyle(document.body).direction,
        samples: hints.map(h => {
          const cs = getComputedStyle(h);
          const r = h.getBoundingClientRect();
          const tile = h.closest('.ss-stat');
          const value = tile && tile.querySelector('.ss-stat__value');
          return {
            fg: parseRgb(cs.color).slice(0,3),
            bg: opaqueBg(h),
            fontPx: parseFloat(cs.fontSize),
            visible: r.width > 1 && r.height > 1,
            w: Math.round(r.width), h: Math.round(r.height),
            clip: cs.clip,
            position: cs.position,
            textAlign: cs.textAlign,
            marginInlineStart: cs.marginInlineStart,
            describedBy: value ? value.getAttribute('aria-describedby') : null,
            idMatches: !!(value && value.getAttribute('aria-describedby') === h.id),
            tileH: tile ? Math.round(tile.getBoundingClientRect().height) : null,
          };
        }),
        tileHeights: [...document.querySelectorAll('.ss-stats')].map(g =>
          [...g.querySelectorAll('.ss-stat')].map(t => Math.round(t.getBoundingClientRect().height))),
      };
    });

    console.log('='.repeat(78));
    console.log(`${combo}   dir=${out.dir}   hints=${out.count}`);
    out.samples.forEach((s, i) => {
      const cr = ratio(s.fg, s.bg);
      console.log(`  hint ${i+1}: ${s.fontPx}px  ${s.w}x${s.h}px  visible=${s.visible}  clip=${s.clip}  pos=${s.position}`);
      console.log(`           fg=rgb(${s.fg}) bg=rgb(${s.bg})  contrast=${cr.toFixed(2)}:1  ${cr>=4.5?'PASS AA':(cr>=3?'AA large only':'FAIL')}`);
      console.log(`           aria-describedby matches id: ${s.idMatches}  (${s.describedBy})`);
    });
    console.log(`  stat-grid tile heights: ${JSON.stringify(out.tileHeights)}`);
    await page.close();
  }
  await browser.close();
})();
