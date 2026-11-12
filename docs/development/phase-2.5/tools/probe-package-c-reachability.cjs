/**
 * P2.5-PKG-C — WHICH OF THE ORDER AND CUSTOMER SURFACES THIS MERCHANT CAN ACTUALLY REACH.
 *
 * Same discipline as probe-package-b-reachability.cjs, and for the same measured reasons:
 * Laratrust resolves at runtime, `permissions_as_gates` is false so `@can()` is always false, and
 * operator status is a STRING COMPARISON on `users.type` rather than a role. Static analysis
 * cannot answer "does this screen render for this merchant".
 *
 * IT REPORTS THE LANDING PATH, NOT JUST THE STATUS, because these handlers refuse with
 * `redirect()->back()`. A refused screen answers 200 and lands somewhere else, and a probe that
 * records only the status reports it as reachable.
 *
 * IT RESETS TO /dashboard BETWEEN REQUESTS. `back()` sends the browser to the REFERRER, so without
 * the reset every refused surface appears to "redirect" to whichever surface was probed just
 * before it. Package B's first run duly reported `/flash-sale -> /coupon` and six more like it;
 * the target was never meaningful, it was the previous row.
 *
 * TWO IDS HERE ARE NOT WHAT THEIR ROUTE SEGMENT CLAIMS, and asking with the wrong one is exactly
 * how Package B reported two held permissions as refusals:
 *
 *   /abandon-carts-show/{cartId}   takes a CUSTOMER id, not a cart id. `CartController` does
 *                                  `Cart::where('customer_id', $cartId)`, and answers
 *                                  "Data not found." for a real CART id.
 *   /wishlist/{wishlist}           DOES take a wishlist row id -- confirmed by reading
 *                                  `wishlist/product.blade.php`, which links
 *                                  `route('wishlist.show', $wishlist->id)`. Its sibling
 *                                  `wishlist.destroy` is linked with `$wishlist->customer_id`
 *                                  instead, so the two cannot be driven with one id.
 *
 * NOTHING HERE IS A VERDICT ON DESIGN. It is the access-check that has to come first.
 *
 * usage: node docs/development/phase-2.5/tools/probe-package-c-reachability.cjs
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPO = '/home/user/syriastore98';
const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const BASE = arg('base', 'http://127.0.0.1:8899');
const OUT = arg('out', path.join(REPO, 'docs/development/phase-2.5/tools/package-c-reachability.json'));
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

/** Scoped to the child only — serve-dev.sh records that exporting these destroyed the fixture twice. */
const DEV_ENV = Object.assign({}, process.env, {
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: path.join(REPO, 'database/p2dev.sqlite'),
  CACHE_STORE: 'file',
  SESSION_DRIVER: 'file',
});

/**
 * The Package C surfaces. The four already REDESIGNED AND VERIFIED are included as CONTROLS: if
 * they come back refused, the probe's session or the fixture is wrong, not the screen.
 */
const SURFACES = [
  // --- ORDERS
  ['Order list (CONTROL, verified)', '/order'],
  ['Order board (Kanban)', '/order-kanban'],
  ['Order grid (card view)', '/order-grid'],
  ['Order detail (CONTROL, verified)', '/order-view/{orderEnc}'],
  ['Order quick preview (panel)', '/order-preview/{order}'],
  ['Order AJAX modal (eye/Show)', '/orders/order_view/{orderEnc}'],
  // ENCRYPTED, like every other order surface. This route answered 403 until F-C-02 removed the
  // unused bound `Order $order` parameter; with the binding gone it decrypts its segment exactly
  // as its sibling `orders/order_view/{id}` does, so a plain integer now takes the
  // DecryptException path and redirects. Sixth id correction in this census.
  ['Order show (resource)', '/order/{orderEnc}'],
  ['Create order', '/order/create'],
  ['Refund request list', '/refund-request'],
  ['Refund detail (CONTROL, verified)', '/refund-request/{refundEnc}'],
  // NAVIGATES AWAY BY DESIGN, and reading that as a refusal was this census's fifth wrong
  // reading. `order/receipt.blade.php:294` calls `window.print()` and `:299`
  // `window.history.back()` -- a printable document that prints itself and returns. The response
  // is 200 with the receipt in it; the browser then leaves. A classifier keyed on "where did we
  // land" called that REFUSED (back to dashboard), identically to a permission denial.
  ['Printable receipt', '/order-receipt/{orderEnc}', { navigatesAway: true }],
  ['Printable shipping label', '/shippinglabel/pdf/{orderEnc}'],
  // --- CUSTOMERS
  ['Customer list (CONTROL, verified)', '/customer'],
  ['Customer grid (card view)', '/customer-grid'],
  ['Customer filter field metadata', '/customer-filter'],
  ['Customer filter result fragment', '/customer-filter-data'],
  ['Create customer', '/customer/create'],
  ['Customer orders (CONTROL, verified)', '/customer/{customer}'],
  ['Customer timeline (CONTROL, verified)', '/customer-timeline/{customer}'],
  ['Edit customer (unimplemented)', '/customer/{customer}/edit'],
  // --- ABANDONED CARTS
  ['Abandoned carts list', '/abandon-carts-handled'],
  ['Abandoned-cart products modal', '/abandon-carts-show/{cartCustomer}'],
  // --- ABANDONED WISHLISTS
  ['Abandoned wishlists list', '/wishlist'],
  ['Abandoned-wishlist products modal', '/wishlist/{wishlist}'],
  ['Wishlist create (unimplemented)', '/wishlist/create'],
  ['Wishlist edit (unimplemented)', '/wishlist/{wishlist}/edit'],
];

/**
 * FIVE OF THESE ROUTES TAKE AN ENCRYPTED ID, NOT AN INTEGER, and that is the single biggest
 * correction this probe needed. `order_view`, `order_receipt`, `shippinglabel`,
 * `OrderController::show` and `OrderRefundController::show` all begin with `Crypt::decrypt($id)`,
 * and the list screens link them with `Crypt::encrypt($order->id)`.
 *
 * MEASURED with a plain integer: three answered 200 and landed back on /dashboard -- a caught
 * `DecryptException` redirecting with "Something was wrong." -- and two answered HTTP 500 with an
 * UNCAUGHT one. Two of the three redirects are surfaces the coverage matrix records as already
 * REDESIGNED AND VERIFIED, so the obvious reading was that something had broken them. Nothing had.
 * The probe was asking with a token the application cannot decrypt, which is the same class of
 * mistake as Package B's two wrong-URL false refusals and as the seeder-probe order id above.
 *
 * CLAUDE.md records that `APP_KEY` is in git history and unrotated, so this encryption is an
 * ENCODING rather than a control -- an encrypted id in a URL is forgeable. That is a recorded
 * security finding and not this probe's business; what matters here is only that it is the URL
 * FORMAT the screens require.
 *
 * Generated by booting the application so the real APP_KEY is used; a hand-built token would not
 * decrypt.
 */
function encryptedIds(plainIds) {
  const wanted = JSON.stringify(plainIds);
  const php = `
    require '${REPO}/vendor/autoload.php';
    $app = require_once '${REPO}/bootstrap/app.php';
    $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
    $out = [];
    foreach (json_decode(${JSON.stringify(wanted)}, true) as $k => $v) {
        $out[$k] = Illuminate\\Support\\Facades\\Crypt::encrypt($v);
    }
    echo json_encode($out);
  `;
  return JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8', env: DEV_ENV }));
}

/** Real ids from the fixture. A hard-coded id that does not exist answers 404 and reads as refused. */
function fixtureIds() {
  const php = `
    $pdo = new PDO('sqlite:' . ${JSON.stringify(path.join(REPO, 'database/p2dev.sqlite'))});
    $one = function (string $q) use ($pdo) {
        try { $r = $pdo->query($q)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
        catch (Throwable $e) { return null; }
    };
    echo json_encode(array_filter([
        // NOT \`order by id desc\`. That picked a SEEDER PROBE order -- the minimal rows
        // seed-dev-interaction-fixtures.php writes to fill a Kanban column past its 50-card cap --
        // whose \`product_json\` is the empty array \`[]\`. Three order-id-driven surfaces then
        // refused, two of them surfaces the matrix records as already verified, and the obvious
        // reading was that something had broken them. The id was simply not a representative
        // order. Same class of mistake as Package B's two wrong-URL false refusals: the probe was
        // asking a question the screen could not answer.
        'order'    => $one("select id from orders where product_json is not null"
                           . " and product_json not in ('[]', '') order by id limit 1"),
        // A refund whose ORDER still exists: \`--remove\` deletes probe orders, and a refund left
        // pointing at a deleted order is not a surface either.
        'refund'   => $one('select r.id from order_refunds r join orders o on o.id = r.order_id'
                           . ' order by r.id limit 1'),
        // The ORDER behind that refund, because show() decrypts to an order id.
        'refundOrder' => $one('select r.order_id from order_refunds r join orders o on o.id = r.order_id'
                           . ' order by r.id limit 1'),
        'customer' => $one('select id from customers order by id limit 1'),
        // A CUSTOMER id that really has cart rows -- the route segment is misnamed {cartId}.
        'cartCustomer' => $one('select customer_id from carts group by customer_id order by count(*) desc limit 1'),
        // A WISHLIST ROW id, which is what wishlist/product.blade.php links with.
        'wishlist' => $one('select id from wishlists order by id limit 1'),
    ], fn ($v) => $v !== null));
  `;
  return JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8', env: DEV_ENV }));
}

(async () => {
  const ids = fixtureIds();

  // The encrypted forms, keyed with an `Enc` suffix so a route template says which it needs.
  // `refundEnc` encrypts an ORDER id, not a refund id: OrderRefundController::show() decrypts to
  // an order and then looks the refund up by `order_id`. Reading that off the controller rather
  // than trusting the route's `{refund_request}` segment name is the whole point.
  const enc = encryptedIds({ orderEnc: ids.order, refundEnc: ids.refundOrder });
  Object.assign(ids, enc);
  console.log('fixture ids: ' + JSON.stringify(
    Object.fromEntries(Object.entries(ids).map(([k, v]) =>
      [k, String(v).length > 24 ? String(v).slice(0, 12) + '...(encrypted)' : v]))) + '\n');

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
  for (const [name, template, opts] of SURFACES) {
    const missing = [...template.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).filter((k) => !ids[k]);
    if (missing.length) {
      rows.push({ surface: name, route: template, status: null, landed: null,
                  shape: 'NO FIXTURE', note: 'no row for: ' + missing.join(', ') });
      console.log(`  ${name.padEnd(38)} NO FIXTURE (${missing.join(', ')})`);
      continue;
    }
    const url = template.replace(/\{(\w+)\}/g, (_, k) => ids[k]);

    await page.goto(BASE + '/dashboard', { waitUntil: 'domcontentloaded' }).catch(() => null);
    await page.waitForTimeout(120);

    let status = null;
    let gotoFailed = false;
    const resp = await page.goto(BASE + url, { waitUntil: 'domcontentloaded' })
      .catch(() => { gotoFailed = true; return null; });
    if (resp) status = resp.status();
    await page.waitForTimeout(450);
    const landed = gotoFailed ? 'NAVIGATION FAILED' : new URL(page.url()).pathname;

    const probe = await page.evaluate(() => {
      const hasShell = !!document.querySelector('.dash-sidebar') || !!document.querySelector('[data-ss-admin]');
      const text = (document.body ? document.body.innerText : '') || '';
      return {
        hasShell,
        bodyKids: document.body ? document.body.children.length : 0,
        title: (document.title || '').trim().slice(0, 40),
        // "Data not found." is this family's OTHER refusal, distinct from a permission refusal and
        // meaning the id was wrong -- which is the mistake the header above records.
        dataNotFound: /Data not found/i.test(text),
        rows: document.querySelectorAll('table tbody tr').length,
        chars: text.trim().length,
      };
    });

    const redirected = landed !== url;
    // A page that prints itself and calls history.back() is NOT a refusal. The response status is
    // what carries the verdict for one of those; where it navigates away we say so explicitly
    // instead of inferring a denial from the landing path.
    const kind = (opts && opts.navigatesAway && status === 200) ? 'PAGE (prints, then returns)'
      : gotoFailed ? 'NAV FAILED'
      : status >= 500 ? 'HTTP ' + status
      : landed === '/dashboard' && url !== '/dashboard' ? 'REFUSED (back to dashboard)'
      : probe.dataNotFound ? 'DATA NOT FOUND (wrong id?)'
      : redirected ? 'REDIRECTED'
      : status >= 400 ? 'HTTP ' + status
      : probe.hasShell ? 'PAGE'
      : 'FRAGMENT';

    rows.push({ surface: name, route: url, status, landed, shape: kind,
                title: probe.title, bodyKids: probe.bodyKids, tableRows: probe.rows,
                chars: probe.chars });
    console.log(`  ${name.padEnd(38)} ${String(status).padEnd(4)} ${kind.padEnd(26)}`
      + (redirected ? '-> ' + landed : '')
      + (kind === 'FRAGMENT' ? `  (${probe.bodyKids} body children, ${probe.chars} chars)` : '')
      + (probe.rows ? `  [${probe.rows} table rows]` : ''));
  }

  await browser.close();
  fs.writeFileSync(OUT, JSON.stringify({ ids, rows }, null, 2));

  const tally = rows.reduce((a, r) => { a[r.shape] = (a[r.shape] || 0) + 1; return a; }, {});
  console.log('\n' + Object.entries(tally).map(([k, v]) => `${k}=${v}`).join('  '));

  const badControls = rows.filter((r) => r.surface.includes('CONTROL') && !r.shape.startsWith('PAGE'));
  if (badControls.length) {
    console.log('\nCONTROLS DID NOT RENDER -- the session or fixture is wrong, not the screens:');
    for (const c of badControls) { console.log('  ' + c.surface + ' -> ' + c.shape); }
  } else {
    console.log('all 6 controls rendered as PAGE, so the session and fixture are sound');
  }
  console.log('wrote ' + OUT);
})();
