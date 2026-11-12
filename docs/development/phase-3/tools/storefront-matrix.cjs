/**
 * STOREFRONT MATRIX — drive the real CUSTOMER storefront and record what each page answers.
 * =================================================================================================
 *
 * The Phase 2.5 equivalent is `render-matrix.cjs`, and it cannot do this job. Measured on the
 * merged main before this file existed:
 *
 *     128 .cjs harnesses in the repository
 *       6 of them mention a customer
 *       0 of them drive {storeSlug}/login
 *
 * All six navigate to the MERCHANT `/login` on the `web` guard. The storefront sign-in is a
 * different route, a different controller and a different guard -- `routes/customer.php:106-109`,
 * `Customer\Auth\CustomerLoginController`, guard `customers`. So "capture the storefront as a
 * customer" was not something the repository could do, and every storefront claim before this
 * tool rested on a merchant session or on nothing.
 *
 * FOUR THINGS THIS HARNESS DOES DIFFERENTLY, each because the storefront is not the admin.
 *
 * 1. THE LOCALE IS A COOKIE, NOT A DATABASE ROW. `ThemeLanguage::handle()` reads
 *    `Cookie::get('LANGUAGE')` and falls back to the authenticated user, then to the cached
 *    `admin_details` row. It reads NONE of `settings.cust_darklayout`, `settings.SITE_RTL` or
 *    `users.language` -- which is exactly what `render-matrix.cjs` writes. Pointing the Phase 2.5
 *    state tool at a storefront page would have changed nothing and reported success, which is the
 *    shape of false reading this project keeps recording. So this tool sets the cookie.
 *
 * 2. THE SUBMIT BUTTON IS NOT THE FIRST SUBMIT BUTTON. The storefront header carries a search form
 *    whose `<button type="submit">` appears BEFORE the login form's. A `button[type=submit]`
 *    selector therefore submits the search, lands back on the login page, and a harness that only
 *    checked HTTP status would photograph the login form and call it a storefront. Measured: it
 *    did exactly that on the first run. The selector is scoped to `form.login-form`.
 *
 * 3. A MISSING `dir` IS A FINDING, NOT A DEFAULT. The THEME's layout
 *    (`themes/<t>/views/front_end/layouts/app.blade.php:2`) sets
 *    `dir="{{ ($currantLang == 'ar' || ...) ? 'rtl' : 'ltr' }}"`. The BASE layout
 *    (`resources/views/front_end/layouts/app.blade.php:2`) sets no `dir` at all and, in Arabic,
 *    links `themes/{$currentTheme}/assets/css/rtl-main-style.css` -- which collapses to
 *    `themes//assets/...` when `$currentTheme` is empty. A store whose `stores.theme_id` is empty
 *    skips `Theme::set()` and gets that base layout, so Arabic renders left-to-right with a
 *    stylesheet path that exists in no theme. `dirAbsent` and `brokenThemeAsset` below name both.
 *
 * 4. THE PAGE MUST BE THIS STORE'S. A storefront route takes the store from the URL, so a capture
 *    can silently be of another tenant's shop, or of the merchant admin, and still be a 200.
 *    Every row records the store the page actually resolved to.
 *
 * WHAT IT REFUSES TO CALL EVIDENCE. A row is `premise` ok only if the page is not the login form
 * when a customer was signed in, not the merchant admin, not a redirect to a different store, and
 * not blank. Those are the four ways a storefront capture lies, and three of them answer 200.
 *
 * usage:
 *   node storefront-matrix.cjs --store damascus-textiles --out <dir>
 *   node storefront-matrix.cjs --store aleppo-soap --guest --paths /home,/product-list
 *   node storefront-matrix.cjs --store damascus-textiles --negative      (cross-tenant checks)
 *
 *   --store      the storefront slug to drive                  (required)
 *   --customer   customer email; omit to use the fixture default for that store
 *   --guest      do not sign in at all
 *   --paths      comma-separated, each relative to the store   (default: the journey below)
 *   --locales    default en,ar
 *   --viewports  default desktop,tablet,mobile
 *   --shot 0     measure without writing screenshots
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const flag = (k) => argv.includes('--' + k);

const BASE = arg('base', 'http://127.0.0.1:8899');
const STORE = arg('store', '');
const OUT = arg('out', '/tmp/storefront-matrix');
const GUEST = flag('guest');
const NEGATIVE = flag('negative');
const SHOT = arg('shot', '1') !== '0';
const PASSWORD = arg('password', 'storefront-dev-password');

/** The fixture's known sign-in per store, from seed-storefront-fixture.php. */
const FIXTURE_CUSTOMER = {
  'damascus-textiles': 'layla.haddad@example.test',
  'aleppo-soap': 'p3-shopper@aleppo-soap.test',
};
const CUSTOMER = arg('customer', FIXTURE_CUSTOMER[STORE] || '');

/**
 * The customer journey, in the order a shopper walks it. Every one of these is a real route in
 * `routes/customer.php`; none is invented for the capture.
 */
const DEFAULT_PATHS = [
  '/home', '/product-list', '/collections', '/cart', '/wishlist', '/track-order', '/contact-us',
];
const PATHS = arg('paths', DEFAULT_PATHS.join(',')).split(',').map((s) => s.trim()).filter(Boolean);

const LOCALES = arg('locales', 'en,ar').split(',').map((s) => s.trim());
const VIEWPORTS = {
  desktop: { width: 1440, height: 900 },
  tablet: { width: 834, height: 1112 },
  mobile: { width: 390, height: 844 },
};
const WANTED_VIEWPORTS = arg('viewports', 'desktop,tablet,mobile')
  .split(',').map((s) => s.trim()).filter((v) => VIEWPORTS[v]);

if (!STORE) {
  console.error('--store is required: the storefront slug to drive.');
  process.exit(2);
}

/** What the page tells us about itself. Everything here is read from the rendered document. */
const PROBE = () => {
  const html = document.documentElement;
  const sheets = [...document.querySelectorAll('link[rel="stylesheet"]')].map((l) => l.getAttribute('href') || '');
  const bodyText = (document.body ? document.body.innerText : '') || '';
  return {
    url: location.pathname + location.search,
    dir: html.getAttribute('dir'),
    lang: html.getAttribute('lang'),
    // The <html lang> the server rendered, so a direction failure can be attributed: a page with
    // lang="ar" and dir="ltr" is a PRODUCT defect; lang="en" when Arabic was asked for is a
    // HARNESS failure to switch the locale at all.
    htmlLang: html.getAttribute('lang'),
    title: document.title,
    // The merchant admin's shell. If either is present, this is not a storefront page.
    adminChrome: !!document.querySelector('.dash-sidebar, .dash-header, #useradd-sidenav'),
    // The storefront login form, scoped the same way the sign-in is.
    loginForm: !!document.querySelector('form.login-form input[name="password"]'),
    // `themes//assets/...` -- the empty-theme_id collapse.
    brokenThemeAsset: sheets.filter((h) => /\/themes\/\/|\/themes\/assets\//.test(h)),
    themeSheets: sheets.filter((h) => h.includes('/themes/')).length,
    textLength: bodyText.trim().length,
    headings: [...document.querySelectorAll('h1,h2')].slice(0, 3).map((e) => e.innerText.trim().slice(0, 48)),
    overflow: document.documentElement.scrollWidth > window.innerWidth + 1,
    images: (() => {
      const i = [...document.querySelectorAll('img')];
      return { total: i.length, broken: i.filter((x) => x.complete && x.naturalWidth === 0).length };
    })(),
  };
};

async function signIn(page, slug, email) {
  await page.goto(`${BASE}/${slug}/login`, { waitUntil: 'domcontentloaded' });
  const hasForm = await page.$('form.login-form input[name="password"]');
  if (!hasForm) return { ok: false, why: 'no login form on ' + slug + '/login' };
  await page.fill('form.login-form input[name="email"]', email);
  await page.fill('form.login-form input[name="password"]', PASSWORD);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    // SCOPED. The header search form's submit button comes first in the document.
    page.click('form.login-form button[type="submit"]'),
  ]).catch(() => {});
  await page.waitForTimeout(700);
  const still = await page.$('form.login-form input[name="password"]');
  return still ? { ok: false, why: 'credentials refused, or the submit reached another form' }
                : { ok: true, landed: page.url().replace(BASE, '') };
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  const rows = [];

  for (const locale of LOCALES) {
    const expectDir = locale === 'ar' ? 'rtl' : 'ltr';

    for (const vp of WANTED_VIEWPORTS) {
      const ctx = await browser.newContext({ viewport: VIEWPORTS[vp], deviceScaleFactor: 1 });

      const page = await ctx.newPage();

      /*
       * THE LOCALE CONTROL, and the FIRST version of it reported a defect that was mine.
       *
       * `ThemeLanguage::handle()` and `ViewServiceProvider:50` both read `Cookie::get('LANGUAGE')`,
       * so setting that cookie directly looks like the obvious control. It is not: Laravel 11
       * encrypts cookies in the `web` group by default and this application adds no `except` list,
       * so a raw cookie written by the browser fails to decrypt and is DISCARDED. The locale then
       * falls back to the store default, every Arabic row renders `dir="ltr"`, and the harness
       * reports that the storefront has no RTL. Measured: it did exactly that, on seven of seven
       * Arabic rows, before this was traced.
       *
       * The switch a real shopper uses is the header's language dropdown, which links to
       * `change.languagestore` (`themes/<t>/.../header.blade.php:188`). That handler sets the
       * cookie AND the session through the framework, so both are encrypted and both are read
       * back. Driving the application's own control is also the more faithful measurement.
       */
      await page.goto(`${BASE}/${STORE}/home`, { waitUntil: 'domcontentloaded' }).catch(() => {});
      await page.goto(`${BASE}/change-language-store/${locale}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
      await page.waitForTimeout(250);
      const consoleErrors = [];
      page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 140)); });
      page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + String(e).slice(0, 140)));

      let auth = { ok: true, landed: '(guest)' };
      if (!GUEST) {
        auth = await signIn(page, STORE, CUSTOMER);
      }

      for (const p of PATHS) {
        consoleErrors.length = 0;
        const target = `${BASE}/${STORE}${p}`;
        const resp = await page.goto(target, { waitUntil: 'domcontentloaded' }).catch(() => null);
        await page.waitForTimeout(500);
        const d = await page.evaluate(PROBE).catch(() => ({}));

        const problems = [];
        const status = resp ? resp.status() : 0;
        if (status === 0 || status >= 400) problems.push('http ' + status);
        if (!GUEST && auth.ok && d.loginForm) problems.push('LOGIN FORM: signed in, but this is the login page');
        if (d.adminChrome) problems.push('MERCHANT ADMIN: this is not a storefront page');
        if (d.url && !d.url.startsWith('/' + STORE)) problems.push('LEFT THE STORE: landed on ' + d.url);
        if ((d.textLength || 0) < 80) problems.push('BLANK: ' + (d.textLength || 0) + ' chars of text');
        // ATTRIBUTE THE FAILURE. If the server did not even render this locale, the direction is
        // not the finding -- the locale switch is -- and saying so stops a harness defect being
        // filed as a product defect, which this tool has already done once.
        const localeTook = (d.lang || '').toLowerCase().startsWith(locale);
        if (!localeTook) problems.push(`LOCALE DID NOT TAKE: html lang=${d.lang}, asked for ${locale}`);
        else if (d.dir == null) problems.push('NO dir ATTRIBUTE (base layout, not the theme layout)');
        else if (d.dir !== expectDir) problems.push(`dir=${d.dir}, expected ${expectDir}`);
        if ((d.brokenThemeAsset || []).length) problems.push('BROKEN THEME ASSET PATH: ' + d.brokenThemeAsset[0]);

        rows.push({
          store: STORE, path: p, locale, viewport: vp, http: status,
          dir: d.dir, lang: d.lang, title: (d.title || '').slice(0, 42),
          themeSheets: d.themeSheets, text: d.textLength,
          images: d.images, overflow: d.overflow, headings: d.headings,
          consoleErrors: consoleErrors.slice(0, 2),
          premise: problems.length === 0 ? 'ok' : 'PREMISE-FAIL',
          problems,
        });

        if (SHOT) {
          const name = `${STORE}${p.replace(/[^a-z0-9]+/gi, '_')}__${locale}_${vp}.png`;
          await page.screenshot({ path: path.join(OUT, name), fullPage: false }).catch(() => {});
        }
      }
      await ctx.close();
    }
  }

  /* --------------------------------------------------------------------------------------- */
  /* NEGATIVE CHECKS — a storefront route takes its tenant from the URL, so these are the      */
  /* questions that matter and none of them is answered by a 200.                              */
  /* --------------------------------------------------------------------------------------- */
  const negatives = [];
  if (NEGATIVE) {
    const ctx = await browser.newContext({ viewport: VIEWPORTS.desktop });
    await ctx.addCookies([{ name: 'LANGUAGE', value: 'en', url: BASE }]);
    const page = await ctx.newPage();

    const signedIn = await signIn(page, STORE, CUSTOMER);

    const other = Object.keys(FIXTURE_CUSTOMER).find((s) => s !== STORE);
    const cases = [
      ['a store slug that does not exist', `${BASE}/no-such-store-p3/home`],
      ['the OTHER tenant\'s storefront, while signed in here', `${BASE}/${other}/home`],
      ['the OTHER tenant\'s cart, while signed in here', `${BASE}/${other}/cart`],
      ['the merchant admin, while signed in as a customer', `${BASE}/dashboard`],
    ];

    for (const [what, url] of cases) {
      const r = await page.goto(url, { waitUntil: 'domcontentloaded' }).catch(() => null);
      await page.waitForTimeout(400);
      const d = await page.evaluate(PROBE).catch(() => ({}));
      negatives.push({
        what, url: url.replace(BASE, ''), http: r ? r.status() : 0,
        landedOn: d.url, title: (d.title || '').slice(0, 42),
        adminChrome: !!d.adminChrome, loginForm: !!d.loginForm,
      });
    }
    await ctx.close();
    console.log('\nNEGATIVE CHECKS (signed in to ' + STORE + ': ' + signedIn.ok + ')');
    for (const n of negatives) {
      console.log(`  ${n.what}\n      ${n.url}  ->  http ${n.http}  landed ${n.landedOn}  admin=${n.adminChrome} login=${n.loginForm}  "${n.title}"`);
    }
  }

  await browser.close();

  /* --------------------------------------------------------------------------------------- */
  const pad = (s, n) => String(s === undefined || s === null ? '-' : s).padEnd(n);
  console.log('\nstore     path            loc vp       HTTP dir  thm img(brk) txt   premise');
  console.log('-'.repeat(108));
  for (const r of rows) {
    console.log(
      pad(r.store.slice(0, 9), 10) + pad(r.path.slice(0, 15), 16) + pad(r.locale, 4) +
      pad(r.viewport, 9) + pad(r.http, 5) + pad(r.dir, 5) + pad(r.themeSheets, 4) +
      pad(`${r.images ? r.images.total : '-'}(${r.images ? r.images.broken : '-'})`, 8) +
      pad(r.text, 6) + (r.premise === 'ok' ? 'ok' : 'PREMISE-FAIL  ' + r.problems.join(' | '))
    );
  }

  const bad = rows.filter((r) => r.premise !== 'ok').length;
  console.log(`\n${rows.length} rows, ${bad} premise-fail.`);
  fs.writeFileSync(path.join(OUT, 'storefront-matrix.json'),
    JSON.stringify({ store: STORE, guest: GUEST, customer: GUEST ? null : CUSTOMER, rows, negatives }, null, 1));
  console.log(`json + screenshots in ${OUT}`);
  console.log('A 200 IS NOT EVIDENCE. Read the premise column before quoting any of this.');
})();
