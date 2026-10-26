#!/usr/bin/env node
/**
 * P2-03-8 SAFETY NET — rendered-DOM field inventory for the product editor.
 *
 * Owner condition 1 (2026-10-26) requires that reorganising the product editor into
 * basic/advanced sections preserve every field, and be verified on
 * "الأسماء والقيم ومسارات الإرسال والتحقق" — names, values, submit paths and validation,
 * NOT just field counts. Condition 2 adds that hiding a field must not prevent
 * submission or lose its value.
 *
 * A source grep cannot answer that. It misses controls injected by JS (variant rows,
 * attribute rows, Dropzone's hidden inputs) and it cannot see `disabled`, which is the
 * actual regression this slice risks: a control inside a collapsed section that gets
 * disabled is silently dropped from the POST body by the browser, with no error anywhere.
 *
 * So this reads the real rendered page and emits a stable JSON inventory:
 *   - every form control's name, tag, type, value, disabled/required flags
 *   - the owning form's method + action (the submit path)
 *   - whether the control is rendered-visible, and which ancestor hides it
 *   - the client-side validation attributes
 *
 * CAPTURE BOTH SNAPSHOTS IN THE SAME FIXTURE STATE. The diff compares VALUES, so anything
 * that writes to the fixture between the two captures shows up as a failure that is not a
 * regression -- running `check-advanced-fields-live.cjs` in between, which saves a product on
 * purpose, reported `tax_status "taxable" -> "shipping_only"` for exactly that reason. The
 * honest sequence is to take the baseline against the committed views and the after-snapshot
 * against the working tree back to back, with no save in between:
 *
 *     cp resources/views/product/{create,edit}.blade.php /tmp/mine/
 *     git checkout HEAD -- resources/views/product/create.blade.php resources/views/product/edit.blade.php
 *     php artisan view:clear && node inventory-product-fields.cjs --out base.json
 *     cp /tmp/mine/*.blade.php resources/views/product/
 *     php artisan view:clear && node inventory-product-fields.cjs --out after.json
 *     node inventory-product-fields.cjs --diff base.json after.json
 *
 * Usage:
 *   node inventory-product-fields.cjs [baseUrl] --out <file.json>
 *   node inventory-product-fields.cjs --diff <before.json> <after.json>
 *
 * The --diff mode is the gate: it fails on a lost name, a changed value, a newly
 * disabled control, a changed submit path, or a dropped validation attribute.
 */
const BASE_DEFAULT = 'http://127.0.0.1:8899';

/* ---------- diff mode: no browser needed ---------- */
if (process.argv[2] === '--diff') {
  const fs = require('fs');
  const before = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
  const after = JSON.parse(fs.readFileSync(process.argv[4], 'utf8'));
  let bad = 0, cases = 0;
  const fail = (m) => { bad++; console.log('  FAIL  ' + m); };
  const ok = (m) => console.log('  ok    ' + m);

  for (const page of Object.keys(before.pages)) {
    const b = before.pages[page], a = after.pages[page];
    cases++;
    if (!a) { fail(page + ': missing from the after-inventory entirely'); continue; }

    // submit path
    cases++;
    if (b.form.action !== a.form.action || b.form.method !== a.form.method) {
      fail(`${page}: submit path changed  ${b.form.method} ${b.form.action}  ->  ${a.form.method} ${a.form.action}`);
    } else ok(`${page}: submit path unchanged (${a.form.method} ${a.form.action})`);

    const bByName = new Map(), aByName = new Map();
    for (const f of b.fields) { if (!bByName.has(f.name)) bByName.set(f.name, []); bByName.get(f.name).push(f); }
    for (const f of a.fields) { if (!aByName.has(f.name)) aByName.set(f.name, []); aByName.get(f.name).push(f); }

    // 1. no name may disappear
    for (const name of bByName.keys()) {
      cases++;
      if (!aByName.has(name)) fail(`${page}: field name LOST  "${name}"`);
    }
    if (![...bByName.keys()].some((n) => !aByName.has(n))) ok(`${page}: all ${bByName.size} field names still present`);

    // 2. arity per name (a dropped variant row shows up here)
    for (const [name, list] of bByName) {
      if (!aByName.has(name)) continue;
      cases++;
      const after_ = aByName.get(name);
      if (list.length !== after_.length) {
        fail(`${page}: "${name}" count ${list.length} -> ${after_.length}`);
      }
    }

    // 3. values, disabled, validation
    //
    // Two names carry a value that is SUPPOSED to differ between any two page
    // loads, so comparing them would make the gate fail on every run and be
    // switched off within a day: `_token` is the CSRF token, minted per
    // session, and `ss_last_seen_at` is the concurrent-edit timestamp, which
    // is the current time by definition. Their PRESENCE is still asserted --
    // by the name and arity checks above, and by the inForm check below, so
    // dropping either one still fails the gate. Only the value is exempt.
    const VOLATILE = new Set(['_token', 'ss_last_seen_at']);
    for (const [name, list] of bByName) {
      const after_ = aByName.get(name);
      if (!after_) continue;
      for (let i = 0; i < Math.min(list.length, after_.length); i++) {
        const bf = list[i], af = after_[i];
        if (!VOLATILE.has(name)) {
          cases++;
          if (bf.value !== af.value) fail(`${page}: "${name}"[${i}] VALUE changed  ${JSON.stringify(bf.value)} -> ${JSON.stringify(af.value)}`);
        } else {
          cases++;
          // A volatile field must still HAVE a value. An empty CSRF token or
          // an empty ss_last_seen_at is a real defect, just not a diff.
          if (bf.value !== '' && af.value === '') fail(`${page}: "${name}"[${i}] volatile field lost its value entirely`);
        }
        cases++;
        if (!bf.disabled && af.disabled) fail(`${page}: "${name}"[${i}] became DISABLED — the browser will not submit it`);
        cases++;
        if (JSON.stringify(bf.validation) !== JSON.stringify(af.validation)) {
          fail(`${page}: "${name}"[${i}] validation changed  ${JSON.stringify(bf.validation)} -> ${JSON.stringify(af.validation)}`);
        }
        cases++;
        if (bf.inForm && !af.inForm) fail(`${page}: "${name}"[${i}] left the form element — it will not be submitted`);
      }
    }

    // 4. report additions as information, not failure
    for (const name of aByName.keys()) {
      if (!bByName.has(name)) console.log('  note  ' + page + ': field ADDED "' + name + '"');
    }
  }

  console.log('');
  console.log(bad === 0 ? `PASS ${cases}/${cases}` : `FAIL ${bad} of ${cases}`);
  process.exit(bad === 0 ? 0 : 1);
}

/* ---------- capture mode ---------- */
const { chromium } = require('playwright');
const BASE = (process.argv[2] && !process.argv[2].startsWith('--')) ? process.argv[2] : BASE_DEFAULT;
const outIdx = process.argv.indexOf('--out');
const OUT = outIdx > -1 ? process.argv[outIdx + 1] : null;

async function login(p) {
  await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await p.fill('input[name="email"]', 'merchant@example.test');
  await p.fill('input[name="password"]', 'password');
  await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
}

// Runs in the page. Walks every control, and for each one records what the
// BROWSER would actually submit, plus why it might not.
const COLLECT = () => {
  const forms = Array.from(document.querySelectorAll('form'));
  // the product form is the one carrying the editor's submit button
  let form = document.querySelector('form:has(#submit-all)') ||
             forms.find((f) => f.querySelector('#submit-all')) ||
             forms.sort((a, b) => b.elements.length - a.elements.length)[0];
  const hiddenBy = (el) => {
    let n = el, chain = [];
    while (n && n !== document.documentElement) {
      const cs = getComputedStyle(n);
      if (cs.display === 'none' || cs.visibility === 'hidden' || n.hidden) {
        chain.push((n.tagName || '').toLowerCase() + (n.id ? '#' + n.id : '') +
          (n.className && typeof n.className === 'string' ? '.' + n.className.trim().split(/\s+/).slice(0, 3).join('.') : ''));
      }
      n = n.parentElement;
    }
    return chain;
  };
  const valueOf = (el) => {
    const t = (el.tagName || '').toLowerCase();
    if (t === 'select') {
      const sel = Array.from(el.selectedOptions || []).map((o) => o.value);
      return el.multiple ? sel.join(',') : (sel[0] !== undefined ? sel[0] : '');
    }
    if (el.type === 'checkbox' || el.type === 'radio') return el.checked ? (el.value || 'on') : '';
    if (el.type === 'file') return '';           // never read file inputs
    if (el.type === 'password') return '<redacted>';
    return el.value === undefined ? '' : String(el.value);
  };
  const controls = Array.from(document.querySelectorAll('input,select,textarea,button[name]'));
  const fields = controls
    .filter((el) => el.name)                     // an unnamed control is never submitted
    .map((el) => {
      const t = (el.tagName || '').toLowerCase();
      const hid = hiddenBy(el);
      return {
        name: el.name,
        tag: t,
        type: t === 'input' ? (el.type || 'text') : t,
        value: valueOf(el),
        disabled: !!el.disabled,
        readonly: !!el.readOnly,
        multiple: !!el.multiple,
        inForm: !!(form && form.contains(el)),
        visible: hid.length === 0,
        hiddenBy: hid,
        // what the client would enforce
        validation: {
          required: !!el.required,
          min: el.getAttribute('min'),
          max: el.getAttribute('max'),
          step: el.getAttribute('step'),
          minlength: el.getAttribute('minlength'),
          maxlength: el.getAttribute('maxlength'),
          pattern: el.getAttribute('pattern'),
        },
        // select2 / Choices.js take the native select over; record both
        enhancedBy: el.classList.contains('choices__input') ? 'choices'
          : (el.classList.contains('select2-hidden-accessible') ? 'select2'
          : (el.parentElement && el.parentElement.classList.contains('choices') ? 'choices' : null)),
      };
    });
  return {
    form: form ? { method: (form.getAttribute('method') || 'get').toUpperCase(), action: form.getAttribute('action') || '', id: form.id || null } : null,
    submitButton: !!document.querySelector('#submit-all'),
    actionbarProxy: !!document.querySelector('.ss-actionbar [data-ss-click-proxy]'),
    dirtyTrack: !!document.querySelector('[data-ss-dirty-track]'),
    lastSeenAt: !!document.querySelector('input[name="ss_last_seen_at"]'),
    fields,
  };
};

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const p = await ctx.newPage();
  const pageErrors = [], failedReq = [];
  p.on('pageerror', (e) => pageErrors.push(String(e.message).slice(0, 200)));
  p.on('requestfailed', (r) => { if (/\.(js|css)(\?|$)/.test(r.url())) failedReq.push(r.url()); });

  await login(p);

  const out = { base: BASE, pages: {} };

  // the edit page needs a real product id
  await p.goto(BASE + '/product', { waitUntil: 'networkidle' });
  const editHref = await p.evaluate(() => {
    const a = Array.from(document.querySelectorAll('a[href*="/product/"]'))
      .map((x) => x.getAttribute('href'))
      .filter((h) => h && /\/product\/\d+\/edit$/.test(h));
    return a[0] || null;
  });

  const targets = [['create', BASE + '/product/create']];
  if (editHref) targets.push(['edit', editHref.startsWith('http') ? editHref : BASE + editHref]);

  for (const [label, url] of targets) {
    await p.goto(url, { waitUntil: 'networkidle' });
    await p.waitForTimeout(1200);               // let Choices/select2/Dropzone settle
    const snap = await p.evaluate(COLLECT);
    snap.url = url.replace(BASE, '');
    out.pages[label] = snap;
    console.log(`[${label}] ${snap.fields.length} named controls, form ${snap.form && snap.form.method} ${snap.form && snap.form.action}`);
    console.log(`         submitButton=${snap.submitButton} actionbarProxy=${snap.actionbarProxy} dirtyTrack=${snap.dirtyTrack} lastSeenAt=${snap.lastSeenAt}`);
    const dis = snap.fields.filter((f) => f.disabled).map((f) => f.name);
    const outside = snap.fields.filter((f) => !f.inForm).map((f) => f.name);
    const hidden = snap.fields.filter((f) => !f.visible).length;
    console.log(`         disabled=[${dis.join(',') || 'none'}] outsideForm=[${outside.join(',') || 'none'}] hidden=${hidden}`);
  }

  out.pageErrors = pageErrors;
  out.failedAssetRequests = failedReq;
  console.log(`pageErrors=${pageErrors.length} failedAssetRequests=${failedReq.length}`);
  if (pageErrors.length) pageErrors.forEach((e) => console.log('   pageerror: ' + e));
  if (failedReq.length) failedReq.forEach((e) => console.log('   failed: ' + e));

  if (OUT) { require('fs').writeFileSync(OUT, JSON.stringify(out, null, 2)); console.log('wrote ' + OUT); }
  await browser.close();
})().catch((e) => { console.error('HARNESS ERROR: ' + e.message); process.exit(2); });
