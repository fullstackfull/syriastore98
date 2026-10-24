<?php

namespace App\Http\Middleware;

use App\Models\Product;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-INV-19 / #65 -- `POST /{storeSlug}/product-bid` writes ALL FOUR of its columns from request
 * input, so any customer can forge a bid attributed to another customer on another store's product.
 *
 * `AuctionProductController::addProductbidamount:1600-1603`:
 *
 *     $product_bid->product_id  = $request->product_id  ?? null;
 *     $product_bid->bid_amount  = $request->bid_amount  ?? null;
 *     $product_bid->customer_id = $request->customer_id ?? null;
 *     $product_bid->store_id    = $request->store_id;
 *     $product_bid->save();
 *
 * The authenticated principal is used only for a PRESENCE test at `:1582` and then discarded.
 * `$storeSlug` is used only in the failure-branch redirect. `$product` at `:1585` is assigned and
 * never read. This is the pattern CLAUDE.md names as the most productive in this codebase --
 * authenticate a principal, then act entirely on request input -- in its purest form.
 *
 * MEASURED, not read. Attacker = customer 1 in store 1; victim = customer 2 in store 2:
 *
 *   POST /store-a/product-bid
 *        product_id=<store B's product>  bid_amount=999999
 *        customer_id=<the victim>        store_id=<the attacker's choice>
 *   -> HTTP 302 "Your Bid place successfully."
 *      ROW  product_id=2  customer_id=2  store_id=1  bid_amount=999999
 *      and store B's displayed maximum went from 150 to 999999
 *
 * `AuctionProductBid` is a `Workdo\*` model, absent from `config/tenancy.php`'s `scoped_models`,
 * so `StoreScope::isActiveFor()` short-circuits and it adds no predicate for anyone. The route's
 * `gatherMiddleware()` is literally `["web"]`.
 *
 * WHAT THE MODULE'S OWN FORM POSTS -- read before deciding, because the last guard in this family
 * got this exact question wrong and refused a flow the module offers.
 * `resources/views/pages/place_bid.blade.php:312-314`:
 *
 *     product_id  = $product->id
 *     customer_id = $customer->id          <- the signed-in customer
 *     store_id    = $product->store_id     <- the PRODUCT's store
 *
 * So both forged columns are values the server already holds, and the fix is to derive them rather
 * than refuse anything. `store_id` is the product's store, NOT the slug's and NOT the customer's.
 *
 * SO THIS GUARD DOES NOT RESTRICT WHICH STORE'S PRODUCT A CUSTOMER MAY BID ON, and that is
 * deliberate. `customers` is one session guard, not one per store, and storefronts are public, so a
 * customer registered on store A genuinely can open store B's auction page and bid -- the form
 * renders `$product->store_id` precisely so that the bid lands in the product's store. A
 * slug-versus-store check here would add nothing (the attacker can already see the product) and
 * would refuse a real flow, which is what P1-INV-20's refund guard shipped and had to withdraw.
 *
 * CONFIRMED AGAINST THE MODULE RATHER THAN ASSUMED, which matters because the issue's own
 * acceptance criterion said the opposite. Every lever that could restrict it was looked for and
 * none restricts the POST: the route middleware is `['web']` only; `bidingForm:1566` resolves the
 * product by slug with NO store predicate, so store A's modal will render store B's product;
 * `enable_auction_product` gates only menus, the three admin screens and the storefront slider,
 * never this POST; no view compares the customer's store to the product's; and the winner path is
 * WRITTEN for a foreign bidder -- `Console/Auction.php:78-85` resolves the store from the PRODUCT
 * and the delivery address from the customer with no store predicate at all.
 *
 * ONE QUALIFICATION, because the premise depends on configuration and not only on the module. The
 * page flow holds under the SHIPPED tenancy settings: `StoreScope::resolveTenant()` deliberately
 * does not consult the `customers` guard, so a customer-only session resolves no tenant,
 * `storefront_tenant` is `additive` and `null_tenant` is `open`, so the `Customer` retrieval adds
 * no predicate. Under `TENANCY_STOREFRONT_TENANT=slug_only` or `TENANCY_NULL_TENANT=closed`, a
 * store-A customer on store B's slug would not resolve at all and this guard's sign-in limb would
 * refuse them -- as would the module's own `:1582`. A configuration consequence, not a defect, and
 * stated so the premise is not read as unconditional.
 *
 * THREE THINGS RECORDED RATHER THAN FIXED, each because the fix would be out of proportion:
 *
 *   - THE RULE IS ADVISORY UNDER CONCURRENCY. Two simultaneous bids can both read the same
 *     maximum, both pass, and both land; `Console/Auction.php:74` then resolves the tie to the
 *     lower id. No lock, no unique constraint. The consequence is a deterministic tie-break at the
 *     same price, not an authorization bypass -- so nothing here should be built into an invariant,
 *     but a transaction around a bid board would be the wrong trade.
 *   - NOTHING CHECKS THAT THE PRODUCT IS AN AUCTION PRODUCT, or that an auction window is open.
 *     `products.product_type` and membership of an active `AuctionProductSetting` are consulted
 *     only by the winner picker (`Console/Auction.php:48,66-70`) and the slider, so a bid can be
 *     placed on a non-auction product or outside the window. Those rows are never read by the
 *     picker, which filters on both, so this is LATENT; closing it would mean reimplementing
 *     `productAuctionTime()`'s window logic in host middleware.
 *   - `XSS::handle:51-60` does `$request->merge($request->all())`, which would copy `UploadedFile`
 *     objects INTO the input bag. `xss` is a route-level alias and is not on the `web` group, so it
 *     cannot run before this guard today. If it were ever added to this module's routes, the
 *     file-bag check below would need re-examining.
 *
 * WHY REWRITING INPUT IS THE RIGHT VEHICLE. `packages/workdo/` is vendored and must not be edited,
 * and no Policy, FormRequest or query scope sits between `:1582` and `:1600`. Middleware can
 * nonetheless make the handler read the right value: `$request->merge()` replaces the input bag,
 * and `$request->customer_id` resolves through `Request::__get` -> `all()`, which reads that bag.
 * So the handler keeps its own code and gets server-derived values.
 *
 * `merge()` IS NOT ENOUGH ON ITS OWN, and the reason is specific: `Request::__get` reads `all()`
 * BEFORE route parameters, and `all()` is `array_replace_recursive($this->input(), $this->allFiles())`
 * -- so a FILE part named like a derived key outranks the merged value. Each derived key is
 * therefore checked against `allFiles()` and the request refused if the key is present, rather
 * than silently rewritten. `hasFile()` is NOT the right predicate for that and using it was a live
 * hole in the first version of this guard; the reasoning is at the check itself.
 */
class AuctionBidIdentityGuard
{
    private const TARGET_ACTION =
        'Workdo\AuctionProduct\app\Http\Controllers\AuctionProductController@addProductbidamount';

    /**
     * The keys this guard derives. A file part with any of these names defeats `merge()`, so their
     * presence as files is a refusal rather than something to work around.
     */
    private const DERIVED = ['customer_id', 'store_id', 'product_id', 'bid_amount'];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if (! $route || ltrim((string) $route->getActionName(), '\\') !== ltrim(self::TARGET_ACTION, '\\')) {
            return $next($request);
        }

        // IDENTITY, NOT PRESENCE. The handler's own `:1582` check already refuses a null principal
        // (measured: a merchant `web` session and a delivery-boy session are both redirected,
        // because `customers` is a separate session guard). This limb is kept anyway so the guard
        // does not depend on a vendored line staying where it is, and so the derivation below has
        // a principal it can trust.
        $customer = auth('customers')->user();

        if (! $customer) {
            return $this->deny($request, __('Please sign in to place a bid.'));
        }

        // `array_key_exists` ON `allFiles()`, NOT `hasFile()`. The first version used `hasFile()`
        // and that was a LIVE hole in this guard, found by adversarial review and then measured --
        // with the review's own predicted trigger turning out to be the wrong one.
        //
        // `hasFile()` is `isValidFile()`: `$file instanceof SplFileInfo && $file->getPath() !== ''`
        // (InteractsWithInput.php:212-236). An errored upload fails BOTH limbs while still
        // occupying its key in the file bag -- Symfony stores `null` for `UPLOAD_ERR_NO_FILE`
        // (FileBag.php:72-73) and an `UploadedFile` with an empty `tmp_name` for the size errors.
        // `all()` is `array_replace_recursive($this->input(), $this->allFiles())`
        // (InteractsWithInput.php:88), and `array_replace_recursive` overlays those values as
        // happily as any other. So the key wins over the merge while `hasFile()` reports nothing.
        //
        // MEASURED through the real HTTP stack, with the posted store deliberately different from
        // the product's so the two could be told apart:
        //
        //   no file part              -> store_id = 2   (derived, correct)
        //   UPLOAD_ERR_NO_FILE        -> store_id = 2   (derived; does NOT reproduce here)
        //   UPLOAD_ERR_INI_SIZE       -> store_id = ''  <- THE MERGE WAS DEFEATED
        //
        // The review predicted `UPLOAD_ERR_NO_FILE` as the trigger and it is the one that does not
        // survive the test harness's request rebuild, though it does defeat the merge on a bare
        // `Request::create`. The size errors are the live path. Both are closed by asking the
        // question that matches the mechanism: is the key in the array `all()` overlays?
        //
        // `array_key_exists` also covers the nested and array-of-files shapes, which `hasFile()`
        // only reports when at least one member is valid.
        $files = $request->allFiles();

        foreach (self::DERIVED as $key) {
            if (array_key_exists($key, $files)) {
                return $this->deny($request, __('Your bid could not be placed.'));
            }
        }

        $productId = $this->usableId($request->input('product_id'));

        if ($productId === null) {
            return $this->deny($request, __('That auction product could not be found.'));
        }

        // `Product` carries NO effective scope -- it is absent from `scoped_models`, which is the
        // defect CLAUDE.md records as having produced a live cross-tenant stock drain. That is
        // exactly why this read is safe to use HERE: the guard WANTS the product whatever store it
        // belongs to, because bidding across stores is legitimate. What it must not do is let the
        // caller name the store; the store comes from the row.
        $product = DB::table('products')->where('id', $productId)->first(['id', 'store_id', 'sale_price']);

        if ($product === null) {
            return $this->deny($request, __('That auction product could not be found.'));
        }

        $productStore = $this->usableId($product->store_id);

        if ($productStore === null) {
            // A product with a NULL or unusable store_id is invisible to every store_id predicate
            // in the application. A bid on it would be unreachable by its own merchant.
            return $this->deny($request, __('That auction product could not be found.'));
        }

        $amount = $this->usableAmount($request->input('bid_amount'));

        if ($amount === null) {
            return $this->deny($request, __('Please enter a valid bid amount.'));
        }

        // THE RULE THE MODULE ONLY EVER ENFORCED IN THE BROWSER. `place_bid.blade.php` sets an
        // HTML `min` and a JS handler that recolours the input border without blocking submit, and
        // the controller's validator asks only `required` -- no `numeric`, no `gt`, no comparison.
        // The maximum is read here the same way the handler's own display reads it
        // (`:1568`, `:1635`: keyed on product_id alone), so the guard and the page agree.
        $currentMax = DB::table('auction_product_bids')
            ->where('product_id', $productId)
            ->max('bid_amount');

        // THE FLOOR FALLS BACK TO sale_price, AND THE FIRST VERSION OF THIS GUARD DID NOT -- a LIVE
        // money gap found by adversarial review and then measured: on a product with
        // `sale_price = 100` and no bids yet, a bid of **0.01** was accepted and stored.
        // `Console/Auction.php:73-109` then picks it as the winning bid and writes an
        // `AuctionProductOrder` at that price, so the item goes for a cent if nobody outbids it
        // before the auction closes.
        //
        // The expression is the module's own, copied rather than invented.
        // `AuctionProductController:1568-1571`:
        //
        //     $maxBidAmount = AuctionProductBid::where('product_id', $product->id)->max('bid_amount');
        //     $maxprice     = $product->sale_price;
        //     $bid_amount   = $maxBidAmount ? $maxBidAmount : $maxprice;
        //
        // and `$bid_amount` is what the page renders as "Current Highest Bid" (`:276`) and as the
        // input's `min` (`:292`). So enforcing anything lower would refuse nothing the page offers
        // and accept what the page tells the customer is too low.
        //
        // `?:` NOT `??`, deliberately: `:1571` uses a FALSY test, so a stored maximum of 0 falls
        // back to `sale_price` there too. Matching it keeps the guard and the page in agreement
        // rather than merely close.
        $floor = $currentMax ?: ($product->sale_price ?? null);

        if ($floor !== null && $amount <= (float) $floor) {
            return $this->deny(
                $request,
                __('Your bid must be higher than the current highest bid.')
            );
        }

        // DERIVE, and do it last so nothing above can be influenced by the rewritten values.
        //
        // `product_id` IS MERGED TOO, and the first version of this guard did not merge it -- which
        // left a real gap found by thinking the removal checks through rather than by a test. The
        // handler writes the RAW request value (`:1600`), not anything the guard validated, into an
        // `unsignedBigInteger` column. So the guard would validate the row that `filter_var` says
        // the value denotes while the handler wrote whatever MySQL's implicit coercion made of the
        // original string. Writing back the parsed int removes the divergence instead of relying on
        // the two agreeing.
        $request->merge([
            'customer_id' => $customer->id,
            'store_id' => $productStore,
            'product_id' => $productId,
            'bid_amount' => $amount,
        ]);

        return $next($request);
    }

    /**
     * STRICT, and `is_numeric` is not good enough -- the same lesson `ComparesRowOwnership` records
     * at its own expense. MySQL's implicit coercion in `WHERE id = ?` accepts `'5abc'` (row 5) and
     * rejects `'5.9'`, while `is_numeric` does the opposite. A guard and its handler disagreeing
     * about which row a value denotes is what makes a guard worthless.
     */
    private function usableId(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return null;
        }

        $id = filter_var((string) $value, FILTER_VALIDATE_INT);

        return ($id === false || $id <= 0) ? null : $id;
    }

    /**
     * A money amount, refused rather than coerced.
     *
     * `filter_var` with FILTER_VALIDATE_FLOAT rejects `'999,999'`, `'1e400'` (INF), `''` and any
     * non-scalar, and accepts `'250'` and `'250.50'`. Zero and negatives are refused: a bid must be
     * a positive amount, and `auction_product_bids.bid_amount` feeds `max()` on a page that shows
     * it as money.
     *
     * NO UPPER BOUND IS NEEDED, and that was settled by MEASUREMENT rather than left open. An
     * adversarial review raised the possibility that `$table->float('bid_amount')` had compiled to
     * `double(8,2)` -- max 999999.99 -- in which case a larger value would pass this check and
     * MySQL would silently CLAMP it under `strict => false`, leaving the guard having validated one
     * figure while the row held another. That is true of Laravel <= 10's
     * `float($column, $total = 8, $places = 2)`. It is NOT true here: running the module's own
     * migration against MySQL 8.0.46 on Laravel 11.47.0 produces
     *
     *     `bid_amount` double NOT NULL DEFAULT '0'
     *
     * with no precision cap -- 1000000 stored as 1000000, 1234567.891 stored exactly. So
     * `is_finite()` already agrees with the column and an upper bound would refuse valid bids.
     * If this project is ever pointed at a database migrated by an older Laravel, re-check the DDL
     * before trusting this note.
     *
     * The locale-comma refusal is a behaviour IMPROVEMENT worth naming: before this guard,
     * `'250,50'` reached a float column and coerced to `250`, so the customer silently bid 250
     * instead of 250.50.
     */
    private function usableAmount(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return null;
        }

        $amount = filter_var((string) $value, FILTER_VALIDATE_FLOAT);

        if ($amount === false || ! is_finite($amount) || $amount <= 0) {
            return null;
        }

        return $amount;
    }

    /**
     * A STOREFRONT REFUSAL THE CUSTOMER CAN RECOVER FROM.
     *
     * `redirect()->back()`, matching the handler's own success and failure paths, which both
     * `redirect()->back()` with a flash message. A 403 error page here would lose the auction the
     * customer was looking at -- the regression P1-INV-18's guard shipped and had to soften.
     *
     * The three ownership-adjacent refusals share one body, so the response cannot be used to probe
     * which product ids exist. The sign-in and bad-amount cases carry their own text: neither
     * reveals anything about another tenant's rows, and both are ordinary user mistakes.
     */
    private function deny(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['status' => 'error', 'message' => $message], 403);
        }

        return redirect()->back()->with('error', $message);
    }
}
