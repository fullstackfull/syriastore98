<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ComparesRowOwnership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-INV-06 (#32). TWO VENDORED MODULE CART WRITERS PLANT A CART ROW FROM UNVALIDATED INPUT.
 * ==========================================================================================
 *
 * `BoostSalesController::product_cartlist` and `FrequentlyProductController::product_cartlist` are
 * the "frequently bought together" / "boost sales" add-to-cart endpoints. They are STRUCTURALLY
 * IDENTICAL -- the same method name, the same two branches, the same bugs -- which is why one
 * guard covers both rather than two guards covering one each.
 *
 *   POST {storeSlug}/boost-sales-cart        BoostSales/src/routes/web.php:23
 *   POST {storeSlug}/frequently_product_cart FrequentlyProduct/src/routes/web.php:26
 *
 * Both sit in a `['web']`-only group: no `Authenticate`, no `EnsureCustomerAuthenticated`, no
 * `PlanModuleCheck`. Anonymous the moment the owning module is enabled, which is the same shape as
 * P1-INV-14's six routes and P1-INV-20's lottery writer.
 *
 * WHAT THEY DO WITH THE INPUT. Both read four caller-supplied keys -- `product_ids`,
 * `enable_product`, `variant_id`, `qty` -- resolve the store from the URL slug, and then:
 *
 *   guest branch     foreach ($enable_product as $product_id)
 *                        if (in_array($product_id, $product_ids))
 *                            $product = Product::find($product_id);            // NO store predicate
 *                            Cart::create([... 'price' => floatval($product->final_price) * 1,
 *                                           'store_id' => $store->id]);
 *   customer branch  the same, with $product_id = substr($str, strpos($str,'_') + 1)
 *                    and 'price' => floatval($product->final_price) * floatval($request->qty)
 *
 * `Product` carries no `StoreScope` AND is absent from `config/tenancy.php`'s `scoped_models`, so
 * `Product::find()` adds no predicate for ANY principal -- not a customer, not a merchant, not the
 * operator. (This is the identifier the `customer_id` sweep missed in S19 and it is the same miss
 * here: nothing about these two handlers mentions a customer at all in the guest branch.) So:
 *
 *   1. CROSS-TENANT. A posted `product_ids`/`enable_product` naming store B's product writes a
 *      `carts` row with `store_id` = the slug's store and `product_id` = B's product, priced from
 *      B's catalogue. The row then flows into checkout like any other.
 *   2. A SERVER ERROR RATHER THAN A REFUSAL, in two shapes, and BOTH matter for TESTING as much
 *      as for users -- a test asserting "no cart row was written" passes against a handler that
 *      500s for everyone, which is the first of the four vacuous-pass traps CLAUDE.md names:
 *        - an id matching NO product leaves `$product` null and the guest branch has no
 *          `if (!$product)` at all, so `$product->final_price` is an "attempt to read property on
 *          null" that Laravel's handler raises as an ErrorException. MEASURED: 500, no row. (An
 *          earlier draft of this docblock said it wrote a row at price zero, reasoning from
 *          `floatval(null) === 0.0`; running it says otherwise, and the measurement wins.)
 *        - `enable_product` present with `product_ids` absent is `in_array($x, null)`, a
 *          TypeError on PHP 8. MEASURED: 500.
 *   3. NEGATIVE AND FRACTIONAL QUANTITY. The customer branch multiplies by
 *      `floatval($request->qty)` with no validation, so `qty=-3` writes a NEGATIVE price -- a
 *      credit against the rest of the basket. `qty` is never validated on either route.
 *
 * The ledger recorded reader-side pre-flights as what contains this today. They are not a
 * control: they run on the CART page, after the row exists, and they are a different module's
 * code that upstream is free to change.
 *
 * WHY A `web`-GROUP MIDDLEWARE. `.github/scripts/guard-no-workdo-changes.sh` refuses any diff
 * touching `packages/workdo/`, and these are vendored controllers. Re-declaring the two routes in
 * `routes/web.php` cannot work either: module route files are required from `app->booted()`
 * (`PackageServiceProvider:35`), i.e. AFTER `routes/web.php`, and `RouteCollection` keys on
 * method+domain+uri, so the module's declaration would win the slot back and the middleware would
 * vanish silently. `appendToGroup('web', ...)` survives `route:cache` because the cache stores
 * middleware NAMES unexpanded. Same mechanism as the five sibling guards.
 *
 * WHY THE WHOLE REQUEST IS REFUSED RATHER THAN THE LIST FILTERED. The handler re-reads the raw
 * input itself, so a guard that narrowed `product_ids` would authorise a subset while the handler
 * acted on the whole of it. `ComparesRowOwnership` records the same reasoning for its own callers.
 *
 * =========================================================================================
 *  WHY VALIDATING `product_ids` IS SUFFICIENT, AND WHY `enable_product` IS NOT VALIDATED
 *  AGAINST THE CATALOGUE.
 * =========================================================================================
 *
 * Every row either handler writes is for a `$product_id` that passed
 * `in_array($product_id, $product_ids)` -- a LOOSE comparison. So if every member of `product_ids`
 * is a product of the slug's store, the written row's product is too, PROVIDED loose equality
 * cannot make two different rows agree. Checked case by case on PHP 8, whose string-to-int
 * comparison rules differ from PHP 7's and are what makes this hold:
 *
 *   member '5', posted '5abc'  ->  '5abc' == '5' is FALSE on PHP 8 (both treated as strings)
 *   member '5', posted ' 5'    ->  equal; MySQL resolves ' 5' to row 5. Same row.
 *   member '5', posted '05'    ->  equal; resolves to row 5. Same row.
 *   member '5.0' or '5e0'      ->  these never reach the comparison: limb 2 of
 *                                  noMatchedRowOutsideStore() plucks back '5' and
 *                                  array_diff(['5.0'], ['5']) is non-empty, so the request is
 *                                  refused before the handler runs.
 *
 * Validating `enable_product` against the catalogue as well would be WRONG, not merely redundant,
 * and the reason is worth recording because the first draft of this guard did it. The customer
 * branch computes `substr($str, strpos($str, '_') + 1)`; when the value holds no underscore
 * `strpos` returns `false`, `false + 1` is `1`, and the transform silently DROPS THE FIRST
 * CHARACTER -- so a legitimate `enable_product` of `'12'` reads as `'2'`. Requiring `'2'` to be a
 * product of the caller's store would refuse an ordinary BoostSales request whenever some other
 * tenant happened to own product 2. The `in_array` gate already contains that reading, because
 * `'2'` can only be written if `'2'` is itself in the validated `product_ids`.
 *
 * `variant_id` and `attribute_id` ARE checked, because they are a second unscoped read:
 * `ProductVariant::find($request->variant_id)` and
 * `ProductVariant::where('id', $request->attribute_id)` both load a row with no store predicate,
 * and the customer branch prices the line from `$ProductVariant->final_price`. `product_variants`
 * does carry `store_id` (migration :30), so the same predicate answers it.
 *
 * =========================================================================================
 *  THREE THINGS THIS GUARD DOES NOT CLOSE, named so they are not mistaken for closed. All
 *  three were found by the independent review of this guard and are OUTSIDE P1-INV-06, which
 *  is about a cart row referencing another tenant's product.
 * =========================================================================================
 *
 *   1. VARIANT/PRODUCT PAIRING, intra-tenant. This guard checks that `attribute_id` belongs to
 *      the slug's STORE, not that it belongs to the product being carted.
 *      FrequentlyProductController:151-153 pairs `attribute_id` with `$request->product_id` -- a
 *      key this guard never reads -- while the cart row's `product_id` comes from
 *      `enable_product`. So a shopper at store A can price an expensive A product from a cheap A
 *      product's variant. Same store throughout: a price forgery against the merchant, not a
 *      tenancy breach. Closing it means teaching the guard the handler's pairing, which is a
 *      different question from ownership.
 *   2. THE SIBLING `*-price` ROUTES. `{storeSlug}/boost-sales-price` and
 *      `{storeSlug}/frequently_product_price` are anonymous in the same two route files and do
 *      `Product::find($request->product_id)` with no store predicate -- a cross-tenant catalogue
 *      READ. The catalogue is public, so this discloses a price a shopper could read from the
 *      other storefront anyway.
 *   3. `qty` ABSENT, on the customer branch. Allowed here, and the handler then computes
 *      `price = final_price * floatval(null) = 0`. It does not write a zero-priced line only
 *      because it also assigns `qty = null` into a NOT NULL column and 500s. LATENT: it becomes
 *      a live free-item defect the moment `carts.qty` gains a default or nullability. Refusing
 *      an absent `qty` here would break the guest branch, which legitimately never sends it.
 *
 * KEYED ON THE ACTION NAME, LOWERCASED. Not on the uri: both uris are module-owned strings this
 * project cannot edit, and `ModuleOrderMutationGuard`'s census-by-uri is the first of the four
 * census failures the project skill records. Lowercased because the two vendored namespaces
 * disagree with themselves about case -- `Workdo\BoostSales\app\...` and
 * `Workdo\FrequentlyProduct\app\...` both spell the segment `app` in lower case, and PHP resolves
 * namespaces case-insensitively while `getActionName()` returns the string verbatim from the
 * route file's `use` statement. A case-sensitive key would be one upstream re-capitalisation away
 * from inert. `ModuleCartWriteGuardTest` asserts the live routes really do resolve to these keys,
 * so a rename cannot make the guard pass vacuously.
 */
class ModuleCartWriteGuard
{
    use ComparesRowOwnership;

    /**
     * `strtolower(getActionName())` => the request keys this handler reads.
     *
     * Both entries are identical today, and the map is per-action so that an upstream divergence
     * is a one-line change here rather than a rewrite.
     *
     * AN EARLIER VERSION OF THIS COMMENT CLAIMED THE TWO BODIES "differ only in their success
     * strings" AND CALLED THAT A MEASUREMENT. IT WAS NOT, AND IT IS FALSE. BoostSales'
     * `product_cartlist` mis-nests its braces: `:63`'s `if (! auth('customers')->user()) {` has no
     * `else`, and `:108`'s `} else {` closes the extra `if ($enable_product) {` wrapper at `:66`
     * that FrequentlyProduct does not have. MEASURED: a signed-in customer posting to
     * `boost-sales-cart` gets an EMPTY HTTP 200 and no row, while the same request to
     * `frequently_product_cart` reaches the customer branch and answers its own validation
     * message. So BoostSales has NO REACHABLE CUSTOMER BRANCH -- no reachable `qty` multiplier and
     * no reachable `attribute_id` read -- and its cross-tenant write is live on the GUEST branch
     * only.
     *
     * The guard is unaffected: it runs ahead of both and the keys it reads are read by both. What
     * changes is what a reader should believe about the handlers, which is why the correction is
     * here rather than dropped.
     */
    private const TARGETS = [
        'workdo\boostsales\app\http\controllers\boostsalescontroller@product_cartlist' => true,
        'workdo\frequentlyproduct\app\http\controllers\frequentlyproductcontroller@product_cartlist' => true,
    ];

    /** The caller-supplied keys that must resolve inside the slug's store. */
    private const PRODUCT_KEY = 'product_ids';
    private const VARIANT_KEYS = ['variant_id', 'attribute_id'];

    /** Bounded so the guard cannot be made the expensive part of the request. */
    private const MAX_ITEMS = 500;

    public function handle(Request $request, Closure $next): Response
    {
        // FIRST STATEMENTS, so this guard is inert on every other route in the application.
        $route = $request->route();

        if ($route === null) {
            return $next($request);
        }

        if (! isset(self::TARGETS[strtolower((string) $route->getActionName())])) {
            return $next($request);
        }

        /*
         * `all()`, NOT `input()`, AND THIS IS A BYPASS THAT WAS MEASURED RATHER THAN REASONED
         * ABOUT. Both handlers read `$request->enable_product`, which goes through
         * `Request::__get` to `all()` -- and `all()` is
         * `array_replace_recursive($this->input(), $this->allFiles())`. A FILE is in `all()` and
         * is NOT in `input()`. So a multipart POST carrying a file field named
         * `enable_product[0]` and no text field of that name gave this guard `null`, took the
         * empty-selection early return below, and ran NO check at all -- not the product check,
         * not the store check, not the quantity check -- while the handler saw a truthy array.
         *
         * MEASURED: that request answers 200 and writes no row today, because the handler then
         * uses the UploadedFile object as a product id and `Product::find(<object>)` finds
         * nothing. So it is a bypassed control rather than a live cross-tenant write -- but a
         * control that is skipped is not a control, and the next change to either handler decides
         * whether it becomes one.
         *
         * `ComparesRowOwnership`'s own docblock names this class of error: "which request key does
         * the handler read" is the question a wrong answer to turns a guard into a thing that
         * authorises one row while the handler mutates another. Here the guard was reading a
         * different SOURCE, not merely a different key.
         */
        $body = $request->all();
        $chosen = $body['enable_product'] ?? null;

        /*
         * NOTHING CHOSEN IS NOT A REFUSAL, and turning a handler's own validation message into a
         * 403 would be a visible regression for an ordinary mis-click -- the live UI defect
         * LotterySettingProductOwnershipGuard had to be corrected for.
         *
         * MEASURED, and NOT what an earlier version of this comment claimed. FrequentlyProduct
         * does answer 'Choose items to buy together.'. BoostSales answers an EMPTY HTTP 200,
         * because the brace mis-nesting described on TARGETS above makes its own `:106` message
         * dead code. Passing through is still the right call for both -- a 403 would be wrong for
         * one and no better than an empty 200 for the other -- but the reason is one handler's
         * message and one handler's silence, not two messages.
         */
        if (empty($chosen)) {
            return $next($request);
        }

        $storeId = getRouteStoreId();

        if (empty($storeId)) {
            // An unknown or missing slug. The handlers do `getStore($slug)->id` with no null
            // guard, so today this writes a row with a NULL store_id -- invisible to every
            // store_id predicate in the application. Refused rather than left to the warning.
            return $this->deny('store');
        }

        $products = $body[self::PRODUCT_KEY] ?? null;

        /*
         * `in_array($x, null)` is a TypeError on PHP 8, so a non-array here is a 500 rather than a
         * refusal. Refusing it is not merely tidier: a regression test asserting "no cart row was
         * written" would otherwise pass against the unfixed handler, because a 500 writes no row.
         */
        if (! is_array($products) || $products === [] || count($products) > self::MAX_ITEMS) {
            return $this->deny('products');
        }

        /*
         * EVERY MEMBER MUST BE A STRING OR AN INT, and this is the limb that closes a LIVE BYPASS
         * found by the independent review of this guard and then REPRODUCED:
         *
         *   POST /{A-slug}/frequently_product_cart   (JSON)
         *   {"product_ids":[true],"enable_product":["<B's product id>"],"variant_id":0}
         *     -> 200, carts store_id=A product_id=<B's> price=<B's catalogue price>
         *
         * The two sides disagreed about what `true` MEANS. The shared predicate stringifies each
         * member -- `(string) true` is `'1'` -- so this guard asked the database about product 1,
         * which the slug's own store owns, and allowed the request. The handler's gate is
         * `in_array($product_id, $product_ids)`, LOOSE, and on PHP 8 a bool on either side forces
         * a bool comparison: `"42" == true` is TRUE because `(bool) "42"` is true. So EVERY
         * truthy id passed, and the guard's whole "validating product_ids is sufficient" argument
         * -- which holds for strings and ints, and which I worked through case by case above --
         * collapsed on the one type it had not considered.
         *
         * ONLY A JSON CALLER CAN DO THIS: form-encoded input is always strings, so `true` has no
         * representation there. That is why the first measurement, which posted a form, showed
         * the guard working.
         *
         * IT ALSO CLOSES A SECOND, QUIETER HOLE in the same statement. The shared predicate maps a
         * non-scalar member -- a nested array, null, false -- to `''` and then FILTERS IT OUT, so
         * `product_ids[]=5` plus `product_ids[1][]=999` was validated as `['5']` while the handler
         * compared against a member this guard never saw. Dropping a value you cannot read is the
         * same mistake LotterySettingProductOwnershipGuard had to correct: refuse, never skip.
         *
         * The shipped form posts bare integer ids as strings, so no real caller is refused.
         */
        foreach ($products as $value) {
            if (! is_string($value) && ! is_int($value)) {
                return $this->deny('products');
            }
        }

        if (! $this->noMatchedRowOutsideStore('products', 'id', $products, $storeId)) {
            return $this->deny('products');
        }

        foreach (self::VARIANT_KEYS as $key) {
            $variant = $request->input($key);

            // Absent, empty or the explicit "no variant" zero -- which is what both handlers test
            // for (`empty($request->variant_id) || $request->variant_id == 0`) before taking the
            // non-variant branch. Nothing is loaded, so there is nothing to own.
            if ($variant === null || $variant === '' || (is_scalar($variant) && (string) $variant === '0')) {
                continue;
            }

            if (! is_scalar($variant)
                || ! $this->noMatchedRowOutsideStore('product_variants', 'id', [$variant], $storeId)) {
                return $this->deny('variant');
            }
        }

        /*
         * QUANTITY. The customer branch computes `floatval($product->final_price) * floatval($qty)`
         * with no validation, so a negative quantity writes a NEGATIVE line price -- a credit
         * against the rest of the basket -- and a fractional one writes a fraction of a unit.
         * FILTER_VALIDATE_INT, not is_numeric: is_numeric accepts '1.9' and '1e0'.
         *
         * Absent is allowed: the guest branch hardcodes qty 1 and never reads the key, and the
         * shipped form does not always send it.
         */
        $qty = $request->input('qty');

        if ($qty !== null && $qty !== '') {
            $parsed = is_scalar($qty) ? filter_var((string) $qty, FILTER_VALIDATE_INT) : false;

            if ($parsed === false || $parsed < 1) {
                return $this->deny('qty');
            }
        }

        return $next($request);
    }

    /**
     * Refuse.
     *
     * JSON, with HTTP 403 and the handlers' own `{status, message, data}` envelope, because both
     * routes are AJAX-only: every caller is `$.ajax` from the product page and the success paths
     * return `Utility::success()` / `Utility::error()` arrays. A redirect would be rendered into
     * the page by the calling script.
     *
     * `status: 0` matches `Utility::error()`, so a client that reads the envelope rather than the
     * HTTP code behaves exactly as it does for the handlers' own refusals. The CODE is 403 and not
     * `Utility::error()`'s default 200: this is an authorization refusal and must be legible as
     * one in the access log. It is not 401 -- repo B's `lib/network/network.dart` maps any 401
     * onto a forced logout, and although no Flutter client calls these two routes today, 403 is
     * what every other guard in this application answers.
     *
     * DISTINCT MESSAGES, because three of these four are ordinary input mistakes and telling a
     * shopper "Permission denied." for a missing quantity is the live UI defect
     * LotterySettingProductOwnershipGuard was corrected for. None of them names a store, an id or
     * whether a product exists, so the response cannot be used to probe another tenant's
     * catalogue: a foreign product and a nonexistent one answer identically.
     */
    private const MESSAGES = [
        'store' => 'This store could not be found.',
        'products' => 'These products are not available in this store.',
        'variant' => 'This product option is not available in this store.',
        'qty' => 'Enter a whole quantity of one or more.',
    ];

    private function deny(string $reason): Response
    {
        return response()->json([
            'status' => 0,
            'message' => __(self::MESSAGES[$reason]),
            'data' => [],
        ], 403);
    }
}
