// P1-SEC-75 -- the real-browser differential for App\Support\Html\RichText. Run by verify-rich-text-in-chromium.php; not part of the test suite (CI has no browser step).
//
// WHAT IT DOES. For each {in, out} of the corpus file (the hostile inputs of Tests\Support\Security\RichTextCorpus and what RichText::clean() made of them) it loads the RAW input and then the
// SANITIZED output into a fresh page of headless Chromium and records: did a dialog open, did the page navigate, did it open a popup, were elements or attributes created that are not on the
// allowlist, was anything requested, and does the sanitized output survive an innerHTML round trip unchanged (mutation XSS). The raw input is the negative control: if it did nothing, the
// corpus would prove nothing about the sanitizer.
//
// IT NEVER CONTACTS A HOST. The page is built with setContent (about:blank), and EVERY request of EVERY page of the browser context -- a popup and a worker included, which a route on the
// page alone does not reach (a reviewer executed that) -- is aborted before it leaves the browser; the only thing recorded is the URL it tried. No navigation to any address is ever issued
// by this script. Preconditions: node and the `playwright` package with its Chromium (`require('playwright')` resolves).
//
// THE PAGE AROUND THE OUTPUT. The sanitizer writes libxml's HTML4 tree, and a browser's HTML5 tree builder repairs some nestings differently (`<li><div><li>x</li></div></li>`: the inner `li` pops
// the open `div`, and the output's own `</div>` then closes an element of the HOST page). So the output is printed into the shape of the real page -- `<div id=page><div|p id=o>OUT</..>
// <span id=sentinel>` in standards mode, in a `p` too, and in quirks mode -- and the run fails if the sentinel is no longer the child of #page, or #page no longer the child of <body>
// (`leak`). An independent review found this class with exactly that check; the first version of this harness had none, and said the output could not matter.
//
// WHAT DETECTS WHAT. A dialog is seen through Playwright's `dialog` event (alert, confirm, prompt, beforeunload); a request through the abort route; a navigation or a popup through the page's
// events; everything else a script could build through the DOM checks (`bad`: an element or an attribute that is not on the allowlist; `active`: a script, frame, form, svg ...; `unstable`: the
// serialisation changes when it is parsed again). There is NO "did a script run" counter: a script that neither opens a dialog nor builds anything off the lists nor requests anything is not
// observable from here, and the first version's `ran` field (which the init script never reached) said it was.
//
// usage: node rich-text-browser-run.cjs corpus.json     exit 0 only if the sanitized side did nothing at all.
//        node rich-text-browser-run.cjs --self-test     exit 0 only if every known-bad output below is flagged and the clean control is not -- the detectors can FAIL.
const { chromium } = require('playwright');
const fs = require('fs');

const ALLOWED = {
  p:['dir','style'], div:['dir','style'], span:['dir','style'], br:[], hr:[], b:[], strong:[], i:[], em:[], u:[], s:[], strike:[], del:[], ins:[], sub:[], sup:[], small:[], mark:[],
  ul:['dir'], ol:['dir','start'], li:['dir'], blockquote:['dir'], pre:[], code:[], h1:['dir','style'], h2:['dir','style'], h3:['dir','style'], h4:['dir','style'], h5:['dir','style'], h6:['dir','style'],
  table:['dir'], thead:[], tbody:[], tfoot:[], tr:[], th:['colspan','rowspan','style'], td:['colspan','rowspan','style'],
  a:['href','title','rel','target'], img:['src','alt','title','width','height'],
};

// outputs the sanitizer must never produce, one per detector, and one that is fine
const SELF_TEST = [
  { name: 'a script that opens a dialog', html: '<script>alert(1)</script>', flagged: true },
  { name: 'an image with a handler', html: '<img src="x" onerror="alert(1)">', flagged: true },
  { name: 'an iframe with a script URL', html: '<iframe src="javascript:alert(1)"></iframe>', flagged: true },
  { name: 'a link to a script URL', html: '<a href="javascript:alert(1)">x</a>', flagged: true },
  { name: 'a picture from another host', html: '<img src="https://picture.example/x.png">', flagged: true },
  { name: 'a stylesheet link', html: '<link rel="stylesheet" href="https://style.example/x.css">', flagged: true },
  { name: 'a meta refresh', html: '<meta http-equiv="refresh" content="0;url=https://refresh.example/">', flagged: true },
  { name: 'a script that navigates', html: '<script>location="https://navigate.example/"</script>', flagged: true },
  { name: 'a script that opens a popup', html: '<script>window.open("https://popup.example/")</script>', flagged: true },
  { name: 'an element that is not on the list', html: '<form action="https://form.example/"><input autofocus onfocus="alert(1)"></form>', flagged: true },
  { name: 'an attribute that is not on the list', html: '<p class="x" id="y">z</p>', flagged: true },
  { name: 'a list item in a div in a list item (the structure leak)', html: '<li><div><li>x</li></div></li>', flagged: true, why: 'leak' },
  { name: 'a table in a div in a table (the structure leak)', html: '<table><div><table>x</table></div></table>', flagged: true, why: 'leak' },
  { name: 'a serialisation that changes when it is parsed again', html: '<math><mtext><table><mglyph><style><!--</style><img title="--&gt;&lt;img src=1 onerror=alert(1)&gt;"></table></mtext></math>', flagged: true },
  { name: 'a clean paragraph with a link and a picture (the control)', html: '<p dir="rtl" style="color: red">ok <b>bold</b> <a href="https://link.example/" rel="noopener noreferrer nofollow" target="_blank">l</a><img src="data:image/png;base64,iVBORw0KGgo=" alt="a"></p>', flagged: false },
];

(async () => {
  const selfTest = process.argv[2] === '--self-test';
  const cases = selfTest ? [] : JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const context = await browser.newContext();

  // the safety net: whatever a page that has no route of its own asks for (a popup, a worker) is refused too, and counted
  const stray = [];
  await context.route('**/*', (route) => { const u = route.request().url(); if (!u.startsWith('about:') && !u.startsWith('data:')) stray.push(u); route.abort(); });

  const inspectPage = (page) => page.evaluate((ALLOWED) => {
    const o = document.getElementById('o');
    const pageDiv = document.getElementById('page');
    const sentinel = document.getElementById('sentinel');
    // the whole output, wherever the browser put it: in a <p> context a block closes the paragraph and the rest lands beside it, inside #page
    const bad = [];
    for (const el of pageDiv.querySelectorAll('*')) {
      if (el === o || el === sentinel) continue;
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
    const leak = !(sentinel && sentinel.parentElement === pageDiv && pageDiv.parentElement === document.body && document.body.parentElement === document.documentElement);
    return { bad, leak, stable: first === second, active: pageDiv.querySelectorAll('script,iframe,object,embed,svg,math,form,input,style,link,meta,base,template,noscript').length };
  }, ALLOWED);

  // one fresh page per input: an image's `onerror` or an iframe's load fires AFTER setContent resolves, and must not be charged to the next input
  const wrapper = {
    div: (html) => '<!doctype html><html><body><div id="page"><div id="o">' + html + '</div><span id="sentinel">s</span></div></body></html>',
    p: (html) => '<!doctype html><html><body><div id="page"><p id="o">' + html + '</p><span id="sentinel">s</span></div></body></html>',
    quirks: (html) => '<html><body><div id="page"><p id="o">' + html + '</p><span id="sentinel">s</span></div></body></html>',
  };

  async function observe(html, ctx = 'div') {
    const page = await context.newPage();
    const seen = { requests: [], dialogs: 0, popups: 0, navigated: false };
    await page.route('**/*', (route) => { const u = route.request().url(); if (!u.startsWith('about:') && !u.startsWith('data:')) seen.requests.push(u); route.abort(); });
    page.on('dialog', async (d) => { seen.dialogs++; try { await d.dismiss(); } catch (e) {} });
    page.on('popup', async (p) => { seen.popups++; try { await p.close(); } catch (e) {} });
    page.on('framenavigated', (f) => { if (f === page.mainFrame() && f.url() !== 'about:blank') seen.navigated = true; });
    let r;
    try {
      await page.setContent(wrapper[ctx](html), { waitUntil: 'load' });
      await page.waitForTimeout(40);
      r = await inspectPage(page);
    } catch (e) {
      r = { bad: ['navigated away or crashed: ' + String(e.message).slice(0, 60)], leak: false, stable: true, active: 1 };
    }
    await page.close();
    return { r, seen };
  }

  // what did this page do (empty: nothing)
  const whatItDid = (obs) => {
    const why = [];
    if (obs.r.bad.length > 0) why.push('bad');
    if (obs.r.leak) why.push('leak');
    if (!obs.r.stable) why.push('unstable');
    if (obs.r.active > 0) why.push('active');
    if (obs.seen.requests.length > 0) why.push('request');
    if (obs.seen.dialogs > 0) why.push('dialog');
    if (obs.seen.popups > 0) why.push('popup');
    if (obs.seen.navigated) why.push('navigation');
    return why;
  };
  const didSomething = (obs) => whatItDid(obs).length > 0;

  if (selfTest) {
    let wrong = 0;
    for (const t of SELF_TEST) {
      // a structure case is judged in the contexts the page really has; the others in the plain one
      const contexts = t.why === 'leak' ? ['div', 'p', 'quirks'] : ['div'];
      const seenWhy = new Set();
      for (const c of contexts) { for (const w of whatItDid(await observe(t.html, c))) seenWhy.add(w); }
      const flagged = seenWhy.size > 0;
      const ok = flagged === t.flagged && (!t.why || seenWhy.has(t.why));
      if (!ok) wrong++;
      console.log((ok ? 'ok   ' : 'WRONG') + ' ' + (t.flagged ? 'flagged ' : 'clean   ') + t.name + (ok ? '' : '  <- the detectors said ' + (flagged ? 'FLAGGED ' + [...seenWhy].join(',') : 'CLEAN')));
    }
    await browser.close();
    console.log(wrong === 0 ? 'self-test: every known-bad output is flagged and the control is clean' : 'self-test: ' + wrong + ' wrong -- the detectors cannot be trusted');
    process.exit(wrong === 0 ? 0 : 1);
  }

  const stats = { total: cases.length, raw: { active: 0, requests: 0, dialogs: 0, popups: 0 }, out: { bad: 0, leak: 0, active: 0, unstable: 0, requests: 0, dialogs: 0, popups: 0, navigated: 0 } };
  const fails = [];
  let next = 0;
  async function worker() {
    for (;;) {
      const i = next++;
      if (i >= cases.length) return;
      const c = cases[i];
      const raw = await observe(c.in);
      if (raw.r.active > 0) stats.raw.active++;
      if (raw.seen.requests.length > 0) stats.raw.requests++;
      if (raw.seen.dialogs > 0) stats.raw.dialogs++;
      if (raw.seen.popups > 0) stats.raw.popups++;
      for (const ctx of ['div', 'p', 'quirks']) {
        const out = await observe(c.out, ctx);
        const o = stats.out;
        if (out.r.active > 0) o.active++;
        if (out.r.bad.length) { o.bad++; if (fails.length < 15) fails.push({ i, ctx, why: out.r.bad.slice(0, 3), in: c.in.slice(0, 120), out: c.out.slice(0, 160) }); }
        if (out.r.leak) { o.leak++; if (fails.length < 15) fails.push({ i, ctx, why: ['the element after the output is no longer where it was (structure leak)'], in: c.in.slice(0, 120), out: c.out.slice(0, 160) }); }
        if (ctx === 'div' && !out.r.stable) { o.unstable++; if (fails.length < 15) fails.push({ i, ctx, why: ['unstable under innerHTML round trip'], in: c.in.slice(0, 120), out: c.out.slice(0, 160) }); }
        if (out.seen.requests.length) { o.requests++; if (fails.length < 15) fails.push({ i, ctx, why: ['requested', out.seen.requests.slice(0, 2)], in: c.in.slice(0, 120), out: c.out.slice(0, 160) }); }
        if (out.seen.dialogs) o.dialogs++;
        if (out.seen.popups) o.popups++;
        if (out.seen.navigated) o.navigated++;
      }
    }
  }
  await Promise.all(Array.from({ length: 6 }, worker));
  await browser.close();
  stats.stray = stray.length;   // requests of a page that had no route of its own (a popup): refused by the context's, and counted here
  console.log(JSON.stringify(stats));
  for (const f of fails.slice(0, 15)) console.log('FAIL', JSON.stringify(f));
  const o = stats.out;
  process.exit(o.bad || o.leak || o.active || o.unstable || o.requests || o.dialogs || o.popups || o.navigated ? 1 : 0);
})();
