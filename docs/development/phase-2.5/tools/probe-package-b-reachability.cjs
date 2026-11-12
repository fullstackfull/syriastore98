/**
 * P2.5-PKG-B — WHICH OF THE 40 CATALOGUE/PRODUCT SURFACES THIS MERCHANT CAN ACTUALLY REACH.
 *
 * Static analysis cannot answer this. Laratrust resolves at runtime, `permissions_as_gates` is
 * false so `@can()` is always false, and the operator is a STRING COMPARISON on users.type rather
 * than a role — so the only honest way to know whether a screen renders is to ask for it while
 * signed in as the merchant the rest of this phase measures.
 *
 * IT REPORTS THE LANDING PATH, NOT JUST THE STATUS. Every previous catalogue wave was slowed by
 * the same thing: a route answers 200 and the status looks fine, but the response is the DASHBOARD
 * because a middleware redirected. `/taxes` and `/pages` both do exactly that for this merchant —
 * measured, 200 each, both landing on /dashboard. A probe that records only the status reports
 * those as reachable and then a later harness "verifies" a screen it never loaded.
 *
 * IT ALSO RECORDS WHAT CAME BACK, because a modal route is not a page: several of these return an
 * HTML FRAGMENT that the list screen injects. A fragment has no <html> and no layout, so a harness
 * that drives it as a page measures an unstyled orphan and reports false contrast defects. The
 * `shape` column says which it is, so the later harness knows to open the modal from its list
 * instead of navigating to the route.
 *
 * NOTHING HERE IS A VERDICT ON DESIGN. It is the access-check that has to come first.
 *
 * usage: node docs/development/phase-2.5/tools/probe-package-b-reachability.cjs
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPO = '/home/user/syriastore98';
const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const BASE = arg('base', 'http://127.0.0.1:8899');
const OUT = arg('out', path.join(REPO, 'docs/development/phase-2.5/tools/package-b-reachability.json'));
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

/** Scoped to the child only — serve-dev.sh records that exporting these destroyed the fixture twice. */
const DEV_ENV = Object.assign({}, process.env, {
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: path.join(REPO, 'database/p2dev.sqlite'),
  CACHE_STORE: 'file',
  SESSION_DRIVER: 'file',
});

/**
 * The 40 Package B surfaces, as the coverage matrix names them. `{id}` placeholders are filled
 * from the fixture at run time rather than guessed — a hard-coded id that does not exist answers
 * 404 and would be misread as "not reachable".
 */
const SURFACES = [
  ['Category list', '/category'],
  ['Category create (modal)', '/category/create'],
  ['Category edit (modal)', '/category/{category}/edit'],
  ['Brand list', '/product-brand'],
  ['Brand create (modal)', '/product-brand/create'],
  ['Brand edit (modal)', '/product-brand/{brand}/edit'],
  ['Attribute list', '/product-attributes'],
  ['Attribute create (modal)', '/product-attributes/create'],
  ['Attribute edit (modal)', '/product-attributes/{attribute}/edit'],
  // BOTH OF THESE CARRIED THE WRONG URL AND READ AS "REFUSED" FOR IT.
  // routes/web.php:984 and :986 are `add-attribute-option/{id?}` and `attribute-option/{id?}`,
  // and the id is an ATTRIBUTE id (the method signature's bound ProductAttributeOption is
  // misleading -- the controller says so itself). Requested WITHOUT an id, `show()` calls
  // `ownedAttribute(null)`, which fails closed and returns `denied()` -- correct behaviour, and
  // not a permission refusal. So the probe reported two surfaces as permission-gated when the
  // permissions were held and active; it was asking for a URL that is not a surface. MEASURED
  // after the fix: /attribute-option/1 renders "Attribute-Option" and /add-attribute-option/1
  // returns the modal fragment.
  ['Attribute options (DS pilot)', '/attribute-option/{attribute}'],
  ['Attribute option create (modal)', '/add-attribute-option/{attribute}'],
  ['Tag list', '/tag'],
  ['Tag create (modal)', '/tag/create'],
  ['Tag edit (modal)', '/tag/{tag}/edit'],
  ['Product label list', '/product-label'],
  ['Product label create (modal)', '/product-label/create'],
  ['Product label edit (modal)', '/product-label/{label}/edit'],
  ['Coupon list', '/coupon'],
  ['Coupon create (modal)', '/coupon/create'],
  ['Coupon detail', '/coupon/{coupon}'],
  ['Coupon edit (modal)', '/coupon/{coupon}/edit'],
  ['Flash sale list', '/flash-sale'],
  ['Flash sale create (modal)', '/flash-sale/create'],
  ['Flash sale edit (modal)', '/flash-sale/{flash}/edit'],
  ['Product question list', '/product-question'],
  ['Product question reply (modal)', '/product-question/{question}/edit'],
  ['Testimonial list', '/testimonial'],
  ['Testimonial create (modal)', '/testimonial/create'],
  ['Testimonial edit (modal)', '/testimonial/{testimonial}/edit'],
  ['FAQ list', '/faqs'],
  ['FAQ create (modal)', '/faqs/create'],
  ['FAQ edit (modal, repeater)', '/faqs/{faq}/edit'],
  ['Product list', '/product'],
  ['Product editor — create', '/product/create'],
  ['Product editor — edit', '/product/{product}/edit'],
  ['Product detail (read-only)', '/product/{product}'],
  ['Product quick-preview panel', '/product-preview/{product}'],
];

/** Pull real ids out of the fixture so `{placeholder}` routes are asked for with something real. */
function fixtureIds() {
  const sql = [
    "select 'category', id from categories limit 1",
    "select 'brand', id from product_brands limit 1",
    "select 'attribute', id from product_attributes limit 1",
    "select 'tag', id from tags limit 1",
    "select 'label', id from product_labels limit 1",
    "select 'coupon', id from coupons limit 1",
    "select 'flash', id from flash_sales limit 1",
    "select 'question', id from product_questions limit 1",
    "select 'testimonial', id from testimonials limit 1",
    "select 'faq', id from faqs limit 1",
    "select 'product', id from products limit 1",
  ].join(' union all ');

  const php = `
    $pdo = new PDO('sqlite:' . ${JSON.stringify(path.join(REPO, 'database/p2dev.sqlite'))});
    $out = [];
    foreach (${JSON.stringify(sql.split(' union all '))} as $q) {
        try { $r = $pdo->query($q)->fetch(PDO::FETCH_NUM); if ($r) { $out[$r[0]] = $r[1]; } }
        catch (Throwable $e) { /* table absent in this fixture: reported as a missing id */ }
    }
    echo json_encode($out);
  `;
  return JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8', env: DEV_ENV }));
}

(async () => {
  const ids = fixtureIds();
  console.log('fixture ids: ' + JSON.stringify(ids) + '\n');

  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', 'merchant@example.test');
  await page.fill('input[name="password"]', 'password');
  await Promise.all([page.waitForLoadState('domcontentloaded'),
                     page.click('button[type="submit"], input[type="submit"]')]);

  execFileSync('php', [STATE_TOOL, '1', 'light', 'ltr', 'en', 'merchant@example.test'],
               { cwd: path.dirname(STATE_TOOL), env: DEV_ENV, encoding: 'utf8' });

  const rows = [];
  for (const [name, template] of SURFACES) {
    const missing = [...template.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).filter((k) => !ids[k]);
    if (missing.length) {
      rows.push({ surface: name, route: template, status: null, landed: null,
                  shape: 'NO FIXTURE', note: 'no row for: ' + missing.join(', ') });
      console.log(`  ${name.padEnd(34)} NO FIXTURE (${missing.join(', ')})`);
      continue;
    }
    const url = template.replace(/\{(\w+)\}/g, (_, k) => ids[k]);

    // RESET TO A KNOWN PAGE FIRST, or a denial is unreadable. These routes refuse with
    // `back()`, which sends the browser to the REFERRER -- so without this, every denied surface
    // "redirects" to whichever surface was probed just before it, and the first version of this
    // tool duly reported `/flash-sale -> /coupon`, `/tag -> /add-attribute-option` and five more
    // like them. The target was never meaningful; it was my own previous row. Landing on
    // /dashboard now means exactly one thing: refused and sent back.
    await page.goto(BASE + '/dashboard', { waitUntil: 'domcontentloaded' }).catch(() => null);
    await page.waitForTimeout(150);

    let status = null;
    let gotoFailed = false;
    const resp = await page.goto(BASE + url, { waitUntil: 'domcontentloaded' })
      .catch(() => { gotoFailed = true; return null; });
    if (resp) status = resp.status();
    await page.waitForTimeout(500);
    const landed = gotoFailed ? 'NAVIGATION FAILED' : new URL(page.url()).pathname;

    // A FRAGMENT IS NOT A PAGE. Several modal routes return bare markup the list injects; driving
    // one as a page measures an unstyled orphan and invents contrast defects.
    const shape = await page.evaluate(() => {
      const hasShell = !!document.querySelector('.dash-sidebar') || !!document.querySelector('[data-ss-admin]');
      const bodyKids = document.body ? document.body.children.length : 0;
      const title = (document.title || '').trim().slice(0, 40);
      return { hasShell, bodyKids, title, h1: (document.querySelector('h1') || {}).textContent?.trim().slice(0, 30) || null };
    });

    const redirected = landed !== url;
    const kind = gotoFailed ? 'NAV FAILED'
      : landed === '/dashboard' && url !== '/dashboard' ? 'REFUSED (back to dashboard)'
      : redirected ? 'REDIRECTED'
      : status >= 400 ? 'HTTP ' + status
      : shape.hasShell ? 'PAGE'
      : 'FRAGMENT';

    rows.push({ surface: name, route: url, status, landed, shape: kind,
                title: shape.title, h1: shape.h1, bodyKids: shape.bodyKids });
    console.log(`  ${name.padEnd(34)} ${String(status).padEnd(4)} ${kind.padEnd(11)}`
      + (redirected ? '-> ' + landed : '') + (kind === 'FRAGMENT' ? `  (${shape.bodyKids} body children, no shell)` : ''));
  }

  await browser.close();
  fs.writeFileSync(OUT, JSON.stringify({ ids, rows }, null, 2));

  const tally = rows.reduce((a, r) => { a[r.shape] = (a[r.shape] || 0) + 1; return a; }, {});
  console.log('\n' + Object.entries(tally).map(([k, v]) => `${k}=${v}`).join('  '));
  console.log('wrote ' + OUT);
})();
