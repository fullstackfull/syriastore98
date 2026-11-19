// P1-SEC-75 -- the real-browser differential for App\Support\Html\RichText. Run by verify-rich-text-in-chromium.php; not part of the test suite (CI has no browser step).
//
// WHAT IT DOES. For each {in, out} of the corpus file (the hostile inputs of Tests\Support\Security\RichTextCorpus and what RichText::clean() made of them) it loads the RAW input and then the
// SANITIZED output into a fresh page of headless Chromium and records: did a dialog open, did the page navigate, were elements or attributes created that are not on the allowlist, was
// anything requested, and does the sanitized output survive an innerHTML round trip unchanged (mutation XSS). The raw input is the negative control: if it did nothing, the corpus would
// prove nothing about the sanitizer.
//
// IT NEVER CONTACTS A HOST. The page is built with setContent (about:blank), and EVERY request the page makes is aborted before it leaves the browser; the only thing recorded is the URL it
// tried. No navigation to any address is ever issued by this script. Preconditions: node and the `playwright` package with its Chromium (`require('playwright')` resolves).
//
// usage: node rich-text-browser-run.cjs corpus.json     exit 0 only if the sanitized side did nothing at all.
// Loads each {in, out} of corpus.json into real Chromium (local setContent, every network request refused) and records, for the RAW input and for the SANITIZED output:
// did a script run, which elements/attributes exist, what would be requested, and does the sanitized output survive an innerHTML round trip unchanged (mutation XSS).
const { chromium } = require('playwright');
const fs = require('fs');

const ALLOWED = {
  p:['dir','style'], div:['dir','style'], span:['dir','style'], br:[], hr:[], b:[], strong:[], i:[], em:[], u:[], s:[], strike:[], del:[], ins:[], sub:[], sup:[], small:[], mark:[],
  ul:['dir'], ol:['dir','start'], li:['dir'], blockquote:['dir'], pre:[], code:[], h1:['dir','style'], h2:['dir','style'], h3:['dir','style'], h4:['dir','style'], h5:['dir','style'], h6:['dir','style'],
  table:['dir'], thead:[], tbody:[], tfoot:[], tr:[], th:['colspan','rowspan','style'], td:['colspan','rowspan','style'],
  a:['href','title','rel','target'], img:['src','alt','title','width','height'],
};

(async () => {
  const cases = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const context = await browser.newContext();

  const inspectPage = (page) => page.evaluate((ALLOWED) => {
    const o = document.getElementById('o');
    const bad = [];
    for (const el of o.querySelectorAll('*')) {
      const t = el.localName;
      if (!(t in ALLOWED) || el.namespaceURI !== 'http://www.w3.org/1999/xhtml') { bad.push('element:' + el.namespaceURI + ' ' + t); continue; }
      for (const a of el.attributes) {
        if (!ALLOWED[t].includes(a.name)) bad.push('attr:' + t + '@' + a.name);
        if (a.name === 'href' && !/^(https?:|mailto:|tel:)/i.test(a.value)) bad.push('href:' + a.value.slice(0, 40));
        if (a.name === 'src' && !/^data:image\/(png|jpeg|jpg|gif|webp);base64,[A-Za-z0-9+\/]+=*$/.test(a.value)) bad.push('src:' + a.value.slice(0, 40));
        if (a.name === 'style' && /url\s*\(|expression|javascript|@import|[<>]/i.test(a.value)) bad.push('style:' + a.value.slice(0, 40));
      }
    }
    // mutation XSS: re-parse what the browser serialised, in the same kind of container, and compare
    const first = o.innerHTML;
    const t = document.createElement('div'); t.innerHTML = first;
    const second = t.innerHTML;
    return { ran: window.__ran, bad, stable: first === second, active: o.querySelectorAll('script,iframe,object,embed,svg,math,form,input,style,link,meta,base,template,noscript').length };
  }, ALLOWED);

  // one fresh page per input: an image's `onerror` or an iframe's load fires AFTER setContent resolves, and must not be charged to the next input
  async function observe(html) {
    const page = await context.newPage();
    const seen = { requests: [], dialogs: 0, navigated: false };
    await page.route('**/*', (route) => { const u = route.request().url(); if (!u.startsWith('about:') && !u.startsWith('data:')) seen.requests.push(u); route.abort(); });
    page.on('dialog', async (d) => { seen.dialogs++; try { await d.dismiss(); } catch (e) {} });
    page.on('framenavigated', (f) => { if (f === page.mainFrame() && f.url() !== 'about:blank') seen.navigated = true; });
    await page.addInitScript(() => { window.__ran = 0; const hit = () => { window.__ran++; }; window.alert = hit; window.confirm = hit; window.prompt = hit; window.print = hit; });
    let r;
    try {
      await page.setContent('<!doctype html><html><body><div id="o">' + html + '</div></body></html>', { waitUntil: 'load' });
      await page.waitForTimeout(40);
      r = await inspectPage(page);
    } catch (e) {
      r = { ran: 1, bad: ['navigated away or crashed: ' + String(e.message).slice(0, 60)], stable: true, active: 1 };
    }
    await page.close();
    return { r, seen };
  }

  const stats = { total: cases.length, raw: { ran: 0, active: 0, requests: 0, dialogs: 0 }, out: { ran: 0, bad: 0, active: 0, unstable: 0, requests: 0, dialogs: 0, navigated: 0 } };
  const fails = [];
  let next = 0;
  async function worker() {
    for (;;) {
      const i = next++;
      if (i >= cases.length) return;
      const c = cases[i];
      const raw = await observe(c.in);
      if (raw.r.ran > 0) stats.raw.ran++;
      if (raw.r.active > 0) stats.raw.active++;
      if (raw.seen.requests.length > 0) stats.raw.requests++;
      if (raw.seen.dialogs > 0) stats.raw.dialogs++;
      const out = await observe(c.out);
      const o = stats.out;
      if (out.r.ran > 0) o.ran++;
      if (out.r.active > 0) o.active++;
      if (out.r.bad.length) { o.bad++; if (fails.length < 15) fails.push({ i, why: out.r.bad.slice(0, 3), in: c.in.slice(0, 120), out: c.out.slice(0, 160) }); }
      if (!out.r.stable) { o.unstable++; if (fails.length < 15) fails.push({ i, why: ['unstable under innerHTML round trip'], in: c.in.slice(0, 120), out: c.out.slice(0, 160) }); }
      if (out.seen.requests.length) { o.requests++; if (fails.length < 15) fails.push({ i, why: ['requested', out.seen.requests.slice(0, 2)], in: c.in.slice(0, 120), out: c.out.slice(0, 160) }); }
      if (out.seen.dialogs) o.dialogs++;
      if (out.seen.navigated) o.navigated++;
    }
  }
  await Promise.all(Array.from({ length: 6 }, worker));
  await browser.close();
  console.log(JSON.stringify(stats));
  for (const f of fails.slice(0, 15)) console.log('FAIL', JSON.stringify(f));
  process.exit(stats.out.ran || stats.out.bad || stats.out.active || stats.out.unstable || stats.out.requests || stats.out.dialogs || stats.out.navigated ? 1 : 0);
})();
