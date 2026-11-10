/**
 * MEASURE EVERY SETTINGS PANEL, in four combinations, inside the panel only.
 *
 * `render-matrix.cjs --click-each` proves a panel LOADED and photographs it. That is necessary and
 * it is not sufficient: a screenshot is evidence a surface exists, not evidence it is good. Eighty-
 * four clean-looking pictures is exactly the state in which this phase has twice promoted a row and
 * then found 1.00:1 white-on-white or an English word in the Arabic UI sitting in the image all
 * along, because nobody measured the pixels behind it.
 *
 * So this tool answers the four questions a capture cannot, SCOPED TO THE PANEL rather than the
 * page -- the shell around it is already verified and its findings would drown the panel's own:
 *
 *   contrast   every text node against its composited background, WCAG 1.4.3 floors
 *   overflow   does the panel, or anything in it, extend past the panel's own box
 *   latin      Latin-script words left in the Arabic UI, minus a declared technical allowlist
 *   nameless   form controls with no accessible name, which is most of what a settings tab IS
 *
 * The contrast maths (alpha flattening up the ancestor chain, the 3:1 large-text floor, the
 * clipped-away check that stops it reporting eight deliberate screen-reader labels) is taken from
 * census-low-contrast-text.cjs unchanged, because it is already proven against this application.
 *
 * usage:
 *   node measure-settings-tabs.cjs                        # all tabs, 4 combinations
 *   node measure-settings-tabs.cjs --only brand,system    # by data-tab stem
 *   node measure-settings-tabs.cjs --json out.json
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };

const REPO = '/home/user/syriastore98';
const BASE = arg('base', 'http://127.0.0.1:8899');
const EMAIL = arg('email', 'merchant@example.test');
const PASSWORD = arg('password', 'password');
const STORE = arg('store', '1');
const TABS = '#settings-pills button[data-tab], .nav-pills button[data-tab], button[data-tab]';
const PANEL = arg('panel', '#pills-tabContent');
const ONLY = arg('only', '').split(',').map(s => s.trim()).filter(Boolean);
const JSON_OUT = arg('json', '');

const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

/**
 * THE CANONICAL PAYMENT BRANDS, READ FROM THE APPLICATION rather than copied into this file.
 *
 * `App\\Payments\\PaymentTypeLabel::BRANDS` is the first-party map that decides how a gateway's
 * name is spelled, and P2.5-SET-03 made the Arabic settings tab follow it by REMOVING the
 * mistranslations (`Stripe` was `الشريط`, "the ribbon"). The consequence for this tool is that
 * fifty-two gateway names are now deliberately Latin on the Arabic page -- so a hand-written
 * allowlist here would have to be edited in lockstep with that map, and the first time somebody
 * forgot, this tool would report the CORRECT behaviour as a defect and somebody would "fix" it by
 * translating a brand again.
 *
 * Reading the constant means the measurement and the application cannot disagree. If the file or
 * the constant ever moves, this throws rather than silently falling back to an empty list, because
 * an empty brand list makes every gateway name a finding and the output stops being readable.
 */
function canonicalBrandWords() {
  const src = fs.readFileSync(path.join(REPO, 'app/Payments/PaymentTypeLabel.php'), 'utf8');
  const open = src.indexOf('private const BRANDS = [');
  if (open < 0) throw new Error('PaymentTypeLabel::BRANDS not found -- the brand allowlist would be empty');
  const block = src.slice(open, src.indexOf('];', open));
  const words = new Set();
  for (const m of block.matchAll(/=>\s*'([^']+)'/g)) {
    // A multi-word brand ("Mercado Pago", "Paiement Pro") is allowed word by word, because the
    // page-side scan matches words, not phrases.
    for (const w of m[1].split(/[^A-Za-z]+/)) if (w) words.add(w.toLowerCase());
  }
  if (words.size < 20) throw new Error('only ' + words.size + ' brands parsed -- the constant shape changed');
  return [...words];
}

const COMBOS = [
  { lang: 'en', dir: 'ltr', theme: 'light' },
  { lang: 'en', dir: 'ltr', theme: 'dark' },
  { lang: 'ar', dir: 'rtl', theme: 'light' },
  { lang: 'ar', dir: 'rtl', theme: 'dark' },
];

function setState(theme, dir, lang) {
  execFileSync('php', [STATE_TOOL, STORE, theme, dir, lang, EMAIL], {
    cwd: path.dirname(STATE_TOOL),
    env: {
      ...process.env,
      DB_CONNECTION: process.env.DB_CONNECTION || 'sqlite',
      DB_DATABASE: process.env.DB_DATABASE || path.join(REPO, 'database/p2dev.sqlite'),
      CACHE_STORE: process.env.CACHE_STORE || 'file',
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

/**
 * WORDS THAT ARE ALLOWED TO BE LATIN IN THE ARABIC UI, and why each one is.
 *
 * This list is the whole credibility of the `latin` measurement: too short and it reports proper
 * nouns as untranslated strings, too long and it hides real ones. Every entry is here because it
 * is a PRODUCT NAME, a PROTOCOL, a UNIT, or a value the merchant typed -- never because leaving it
 * in English was easier.
 */
const LATIN_OK = [
  // Third-party products and services. These are proper nouns; translating "Shopify" would be
  // wrong, and the Arabic UI of every comparable product leaves them Latin.
  'shopify', 'woocommerce', 'woocom', 'whatsapp', 'twilio', 'lynomia', 'chatgpt', 'openai',
  'recaptcha', 'google', 'facebook', 'meta', 'tiktok', 'snapchat', 'instagram', 'linkedin',
  'twitter', 'pinterest', 'youtube', 'telegram', 'stripe', 'paypal', 'razorpay', 'paystack',
  'flutterwave', 'mollie', 'mercado', 'skrill', 'coingate', 'paymentwall', 'iyzipay', 'sslcommerz',
  'khalti', 'toyyibpay', 'paytab', 'benefit', 'cashfree', 'aamarpay', 'phonepe', 'paytm', 'payfast',
  'authorize', 'nepalste', 'xendit', 'midtrans', 'yoco', 'tap', 'ozow', 'myfatoorah', 'cinetpay',
  'fedapay', 'paiementpro', 'nowpayments', 'thawani', 'gemini', 'claude', 'anthropic',
  // Gateway names the first run reported, each checked against the gateway list in
  // SettingController before being allowed: all three are the product's own trading name, and
  // `AuthorizeNet` is additionally one word in the config, not the phrase "authorize net".
  'paymera', 'authorizenet', 'nmi',
  // `Business` only ever appears as the tail of "WhatsApp Business API", which is the product's
  // registered name. Allowed as a word rather than by phrase because the surrounding Arabic
  // already translates the rest: `واجهة برمجة تطبيقات WhatsApp Business`.
  'business',
  // `SID` is Twilio's own term for the credential, printed deliberately beside its Arabic gloss
  // (`معرّف Twilio (SID)`) so the merchant can match it to the Twilio console. Translating it
  // would make the field impossible to find.
  'sid',
  // The Shopify store domain suffix a merchant types their subdomain in front of.
  'myshopify',
  // Protocols, formats, standards and technical units. Not translatable terms.
  'api', 'url', 'uri', 'http', 'https', 'json', 'xml', 'csv', 'html', 'css', 'js', 'sdk', 'otp',
  'sms', 'smtp', 'imap', 'pop3', 'ssl', 'tls', 'tcp', 'dns', 'cdn', 'crm', 'erp', 'seo', 'sku',
  'id', 'ids', 'uuid', 'jwt', 'oauth', 'webhook', 'webhooks', 'pwa', 'rtl', 'ltr', 'utf', 'png',
  'jpg', 'jpeg', 'svg', 'webp', 'gif', 'ico', 'pdf', 'mb', 'kb', 'gb', 'px', 'ms', 'utc', 'gmt',
  'ai', 'qr', 'pos', 'cod', 'vat', 'iban', 'swift', 'bic',
  // Currency and locale codes the merchant picks, which are codes, not words.
  'syp', 'usd', 'eur', 'sar', 'aed', 'try', 'egp', 'jod', 'kwd', 'bhd', 'qar', 'omr', 'lbp',
  'iqd',  // the thirteenth seeded code, missed on the first pass and reported as a finding
  'en', 'ar', 'fr', 'de', 'es', 'tr', 'ru',
  // Value placeholders and fixtures the merchant's own data supplies.
  'store', 'syria', 'storesyria', 'example', 'test', 'com', 'net', 'org', 'www', 'localhost',
  'amina', 'haddad', 'demo', 'admin', 'n', 'a',
];

/**
 * Everything measured inside the panel, in one page-side pass.
 *
 * It runs as one `evaluate` rather than four, so every measurement sees the SAME paint. Four
 * separate evaluates can straddle a re-layout and report a contrast figure for a box that an
 * overflow figure was taken before.
 */
const MEASURE = (args) => {
  const panel = document.querySelector(args.panel);
  if (!panel) return { ok: false, why: 'panel ' + args.panel + ' not found' };

  const parse = s => (s.match(/[\d.]+/g) || []).map(Number);

  // --- contrast, lifted from census-low-contrast-text.cjs ------------------------------------
  const paintedBg = (el) => {
    const L = [];
    for (let e = el; e; e = e.parentElement) {
      const c = parse(getComputedStyle(e).backgroundColor); if (c.length < 3) continue;
      const a = c[3] === undefined ? 1 : c[3]; if (a <= 0) continue;
      L.push([c[0], c[1], c[2], a]); if (a >= 1) break;
    }
    if (!L.length) return [255, 255, 255];
    let out = L[L.length - 1][3] >= 1 ? L[L.length - 1].slice(0, 3) : [255, 255, 255];
    for (let i = L.length - 1; i >= 0; i--) {
      const [r, g, b, a] = L[i];
      if (a >= 1) { out = [r, g, b]; continue; }
      out = [r * a + out[0] * (1 - a), g * a + out[1] * (1 - a), b * a + out[2] * (1 - a)];
    }
    return out.map(Math.round);
  };
  const lum = c => {
    const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
  };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const clippedAway = (cs) => cs.clip === 'rect(0px, 0px, 0px, 0px)'
    || /inset\(\s*50%/.test(cs.clipPath || '')
    || (cs.clipPath || '') === 'circle(0px)';

  const hidden = (el) => {
    for (let e = el; e; e = e.parentElement) {
      const cs = getComputedStyle(e);
      if (cs.display === 'none' || cs.visibility === 'hidden') return true;
      if (parseFloat(cs.opacity) <= 0.05) return true;
    }
    return false;
  };

  const contrast = [];
  let worst = { ratio: 99, floor: 4.5, text: '', cls: '' };
  for (const el of panel.querySelectorAll('*')) {
    if (![...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())) continue;
    if (el.closest('.visually-hidden, .sr-only, .ss-visually-hidden')) continue;
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) continue;
    const cs = getComputedStyle(el);
    if (hidden(el) || clippedAway(cs)) continue;
    const fg = parse(cs.color).slice(0, 3); if (fg.length < 3) continue;
    const bg = paintedBg(el);
    const size = parseFloat(cs.fontSize);
    const floor = (size >= 24 || (parseInt(cs.fontWeight, 10) >= 700 && size >= 18.66)) ? 3 : 4.5;
    const n = Number(ratio(fg, bg).toFixed(2));
    const cls = el.tagName.toLowerCase()
      + ((el.className || '').toString().trim() ? '.' + (el.className || '').toString().trim().slice(0, 30).replace(/ +/g, '.') : '');
    const text = el.textContent.trim().replace(/\s+/g, ' ').slice(0, 30);
    if (n - floor < worst.ratio - worst.floor) worst = { ratio: n, floor, text, cls };
    if (n >= floor) continue;
    contrast.push({ ratio: n, floor, fg: 'rgb(' + fg.join(',') + ')', bg: 'rgb(' + bg.join(',') + ')', cls, text });
  }

  // --- overflow ------------------------------------------------------------------------------
  // The panel's own box is the reference. A child wider than it is the defect a screenshot hides,
  // because the screenshot is cropped to the viewport and the overflow is off-frame.
  const pr = panel.getBoundingClientRect();
  const overflow = [];
  if (panel.scrollWidth > panel.clientWidth + 2) {
    overflow.push({ cls: 'panel', by: panel.scrollWidth - panel.clientWidth });
  }
  for (const el of panel.querySelectorAll('*')) {
    if (hidden(el)) continue;
    const r = el.getBoundingClientRect();
    if (r.width < 2) continue;
    const over = Math.round(Math.max(r.right - pr.right, pr.left - r.left));
    if (over > 4) {
      overflow.push({
        cls: el.tagName.toLowerCase() + ((el.className || '').toString().trim() ? '.' + (el.className || '').toString().trim().slice(0, 30).replace(/ +/g, '.') : ''),
        by: over,
      });
    }
  }

  // --- Latin-script leakage in the Arabic UI --------------------------------------------------
  const latin = [];
  if (args.lang === 'ar') {
    const ok = new Set(args.latinOk);
    const walker = document.createTreeWalker(panel, NodeFilter.SHOW_TEXT);
    const seen = new Set();
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      const raw = (n.textContent || '').trim();
      if (!raw) continue;
      const el = n.parentElement;
      if (!el || hidden(el)) continue;
      if (el.closest('.visually-hidden, .sr-only, .ss-visually-hidden, code, pre, script, style')) continue;
      // A value the merchant typed is their data, not a translation gap.
      if (el.closest('input, textarea, select, option')) continue;
      // Latin runs of two letters or more. A single letter is an initial or a unit.
      const words = raw.match(/[A-Za-z][A-Za-z'.-]+/g) || [];
      // A dotted token is allowed when EVERY part of it is: `myshopify.com` is `myshopify`
      // plus `com`, both declared. Collapsing it to `myshopifycom` matched nothing and reported a
      // domain suffix as an untranslated string.
      const allowed = (w) => w.toLowerCase().split(/[^a-z]+/).filter(Boolean).every(part => ok.has(part));
      const bad = words.filter(w => !allowed(w));
      if (!bad.length) continue;
      const key = bad.join(' ').slice(0, 40);
      if (seen.has(key)) continue;
      seen.add(key);
      latin.push({ words: bad.slice(0, 6).join(' '), context: raw.replace(/\s+/g, ' ').slice(0, 50) });
    }
  }

  // --- form controls with no accessible name --------------------------------------------------
  // A settings tab is a form. An input whose only identification is its visual position is
  // unusable with a screen reader, and this is the surface family where that matters most.
  const nameless = [];
  for (const el of panel.querySelectorAll('input, select, textarea, button, a[role="button"]')) {
    if (hidden(el)) continue;
    const type = (el.getAttribute('type') || '').toLowerCase();
    if (type === 'hidden') continue;
    const id = el.id;
    const named = (el.getAttribute('aria-label') || '').trim()
      || (el.getAttribute('aria-labelledby') || '').trim()
      || (el.getAttribute('title') || '').trim()
      || (el.getAttribute('placeholder') || '').trim()
      || (el.tagName === 'BUTTON' || el.tagName === 'A' ? (el.textContent || '').trim() : '')
      || (id ? ((document.querySelector('label[for="' + CSS.escape(id) + '"]') || {}).textContent || '').trim() : '')
      || ((el.closest('label') || {}).textContent || '').trim()
      // An icon-only button is named by its icon's own label if it carries one.
      || ((el.querySelector('[aria-label]') || {}).getAttribute ? (el.querySelector('[aria-label]').getAttribute('aria-label') || '').trim() : '');
    if (named) continue;
    nameless.push({
      cls: el.tagName.toLowerCase() + (type ? '[' + type + ']' : '')
        + ((el.getAttribute('name') || '') ? '[name=' + el.getAttribute('name') + ']' : ''),
    });
  }

  return {
    ok: true,
    chars: (panel.innerText || '').trim().length,
    contrast, overflow, latin, nameless,
    worst,
    dir: getComputedStyle(panel).direction,
  };
};

const BRAND_WORDS = canonicalBrandWords();

(async () => {
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const findings = [];
  const perTab = new Map();

  for (const combo of COMBOS) {
    setState(combo.theme, combo.dir, combo.lang);
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();

    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.click('button[type="submit"], input[type="submit"]'),
    ]);
    if (/\/login\b/.test(page.url())) {
      console.log('PREMISE FAILED: not signed in as ' + EMAIL);
      process.exitCode = 1;
      await browser.close();
      return;
    }

    await page.goto(BASE + '/setting', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(500);

    const names = await page.$$eval(TABS, els => els.map(e => e.getAttribute('data-tab') || ''));
    const want = names.filter(n => n && (!ONLY.length || ONLY.some(o => n.includes(o))));

    for (const name of want) {
      // Re-query every time: the click swaps the panel and stale handles stop resolving.
      const handles = await page.$$(TABS);
      const target = handles[names.indexOf(name)];
      if (!target) continue;

      await target.click({ timeout: 5000 }).catch(() => {});
      await page.waitForFunction(
        (a) => {
          const pane = document.querySelector(a.sel);
          if (!pane) return false;
          if (pane.querySelector('.ss-skeleton')) return false;
          return (pane.innerText || '').trim().length >= a.min;
        },
        { sel: PANEL, min: 20 },
        { timeout: 15000 },
      ).catch(() => {});

      const m = await page.evaluate(MEASURE, { panel: PANEL, lang: combo.lang, latinOk: LATIN_OK.concat(BRAND_WORDS) })
        .catch(e => ({ ok: false, why: String(e).slice(0, 120) }));

      const combolabel = combo.lang + '-' + combo.dir + '-' + combo.theme;
      if (!m.ok) {
        findings.push({ tab: name, combo: combolabel, kind: 'premise', detail: m.why });
        continue;
      }
      if (m.chars < 20) {
        findings.push({ tab: name, combo: combolabel, kind: 'premise', detail: 'panel empty (' + m.chars + ' chars)' });
        continue;
      }
      const expectDir = combo.dir;
      if (m.dir !== expectDir) {
        findings.push({ tab: name, combo: combolabel, kind: 'direction', detail: 'panel direction ' + m.dir + ', expected ' + expectDir });
      }
      for (const c of m.contrast) findings.push({ tab: name, combo: combolabel, kind: 'contrast', detail: c.ratio + ':1 needs ' + c.floor + '  ' + c.fg + ' on ' + c.bg + '  ' + c.cls + '  "' + c.text + '"' });
      for (const o of m.overflow) findings.push({ tab: name, combo: combolabel, kind: 'overflow', detail: o.by + 'px past the panel  ' + o.cls });
      for (const l of m.latin) findings.push({ tab: name, combo: combolabel, kind: 'latin', detail: '"' + l.words + '"  in  "' + l.context + '"' });
      for (const nm of m.nameless) findings.push({ tab: name, combo: combolabel, kind: 'nameless', detail: nm.cls });

      const k = name;
      if (!perTab.has(k)) perTab.set(k, { combos: 0, worst: 99, worstWhere: '' });
      const t = perTab.get(k);
      t.combos += 1;
      if (m.worst.ratio < t.worst) { t.worst = m.worst.ratio; t.worstWhere = combolabel + ' ' + m.worst.cls; }
    }

    await ctx.close();
  }

  await browser.close();

  const byKind = {};
  for (const f of findings) byKind[f.kind] = (byKind[f.kind] || 0) + 1;

  console.log('\nTABS MEASURED: ' + perTab.size + '  (each should be 4 combinations)');
  for (const [name, t] of [...perTab.entries()].sort()) {
    const flag = t.combos === 4 ? ' ' : '!';
    console.log('  ' + flag + ' ' + name.padEnd(26) + ' combos=' + t.combos + '  worst text ' + t.worst + ':1  (' + t.worstWhere + ')');
  }

  console.log('\nFINDINGS: ' + findings.length + '  ' + JSON.stringify(byKind));
  const groups = {};
  for (const f of findings) {
    const key = f.kind + ' | ' + f.detail;
    if (!groups[key]) groups[key] = [];
    groups[key].push(f.tab + '/' + f.combo);
  }
  for (const [key, where] of Object.entries(groups).sort((a, b) => b[1].length - a[1].length)) {
    console.log('\n' + key);
    console.log('   x' + where.length + '  ' + where.slice(0, 6).join(', ') + (where.length > 6 ? ', ...' : ''));
  }

  if (JSON_OUT) {
    fs.writeFileSync(JSON_OUT, JSON.stringify({ findings, perTab: [...perTab.entries()] }, null, 2));
    console.log('\nwrote ' + JSON_OUT);
  }
})();
