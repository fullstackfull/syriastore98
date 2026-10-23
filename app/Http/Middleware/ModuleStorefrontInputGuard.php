<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-SEC-04 follow-up (found by the review of the route census) -- the storefront comment and review handlers act on identity and tenant
 * columns taken straight from the request.
 * ==========================================================================================================================
 *
 * The same shape the AuctionBidIdentityGuard (P1-INV-19) derives instead of refusing: authenticate a principal, then write whatever the body
 * says. packages/workdo/ is vendored, so the control is a host route guard keyed on ROUTE NAME, deriving the columns from the session:
 *
 *   spamprevent.store         POST {storeSlug}/spamprevent  (SpamPrevent: a shopper's comment on a product)
 *       - an anonymous visitor posted a comment as ANY customer id (customer_id and created_by were the body's) with raw HTML in it, and the
 *         merchant's reply modal renders refreshed comments with `${comment.comment}` UNESCAPED (reply.blade.php:302-312): stored script in the
 *         merchant admin origin. Now: a signed-in SHOPPER OF THE SLUG'S STORE only (the widget exists only for one), `customer_id` is the
 *         session's -- RESOLVED to the slug store's own row for that shopper, not merely compared, so the journey the storefront offers
 *         a shopper registered at two stores survives -- a `product_id` must belong to that store, and the comment is stripped of markup.
 *         The store and product checks are P1-SEC-34; they close a cross-tenant WRITE whose downstream exposure is latent rather than
 *         live (the two screens that read these columns were measured and neither crashes nor leaks).
 *   fetch.comments            GET spam-fetch-prevent  (the thread read, used by the shopper's widget AND the merchant's modal)
 *       - read any customer's thread in any store by id, anonymously. Now: a shopper reads their own thread; a merchant-side user reads a thread
 *         of a customer of THEIR current store (the platform operator may read any); anonymous is refused.
 *   spamprevent.admin.store   POST spam-prevent  (the merchant's reply)  -- the customer must belong to the caller's CURRENT store (the store the
 *       module writes the reply into; the operator is not exempt); comment stripped.
 *   spamprevent.edit / spamprevent.destroy   GET spamprevent/{id}/edit, DELETE spamprevent/{id}  ({id} is a CUSTOMER id)
 *       - signed-in, plan-entitled users of ANY tenant could read or delete any customer's whole thread. Now: the customer must belong to the
 *         caller's current store (operator exempt).
 *   product_review.store      POST {storeSlug}/product-review  (ProductReview)
 *       - an anonymous visitor planted a review into ANY store (store_id from the body) with a status of their choosing, and the title and
 *         description went in raw although the merchant's review table prints `description` UNESCAPED through the DataTable's rawColumns
 *         (ProductReviewDataTable:59) -- anonymous stored script in the merchant admin origin. Now: `store_id` is the slug's store,
 *         `status` is the form's own value (1), a `product_id` must belong to that store, and title/description are HTML-ENCODED
 *         (P1-SEC-34; encoded rather than stripped -- strip_tags truncated ordinary reviews at the first '<', see review()).
 *
 * Every refusal is a 403 JSON / redirect, never a 401. Who the operator is: `users.type` 'super admin', tested first.
 */
class ModuleStorefrontInputGuard
{
    /** The route NAMES this guard acts on (RouteCensusGateTest reads it to prove a manifest `guarded:ModuleStorefrontInputGuard` key is really covered). */
    public const ROUTE_NAMES = ['spamprevent.store', 'fetch.comments', 'spamprevent.admin.store', 'spamprevent.edit', 'spamprevent.destroy', 'product_review.store', 'skip.cart.empty'];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $name = $route?->getName();

        if (! in_array($name, self::ROUTE_NAMES, true)) {
            return $next($request);
        }

        return match ($name) {
            'spamprevent.store' => $this->shopperComment($request, $next, $route->parameter('storeSlug')),
            'fetch.comments' => $this->threadRead($request, $next),
            'spamprevent.admin.store' => $this->merchantReply($request, $next),
            'spamprevent.edit', 'spamprevent.destroy' => $this->threadOfOwnCustomer($request, $next, $route->parameter('spamprevent')),
            'product_review.store' => $this->review($request, $next, $route->parameter('storeSlug')),
            'skip.cart.empty' => $this->emptyOwnCart($request, $next),
        };
    }

    /**
     * A SHOPPER MAY EMPTY THEIR OWN BASKET AND NOBODY ELSE'S.  P1-SEC-22 (f).
     *
     * `Workdo\SkipCart`'s `emptyCart` runs
     *
     *     if (\Auth::guard('customers')->check()) { Cart::where('customer_id', $request->customer_id)->delete(); }
     *
     * -- the id from the REQUEST, with the guard check proving only that SOMEBODY is signed in. The task
     * row recorded this as "behind `auth` ... can delete any customer's basket IN THEIR STORES", and both
     * halves are measurably wrong, in the direction that matters:
     *
     *   * THE ROUTE CARRIES NO `auth`. `Route::post('/skip-empty-cart', ...)` sits in a
     *     `Route::middleware(['web'])` group with no `auth` and no `PlanModuleCheck`
     *     (SkipCart/src/routes/web.php:20-26), so the customers-guard check is the ONLY gate.
     *   * AND IT IS NOT CONTAINED BY TENANCY. `Cart` is allowlisted in config/tenancy.php, but
     *     `StoreScope::resolveTenant()` deliberately does not consult the `customers` guard (it would
     *     re-enter the Customer provider), and the uri carries no `{storeSlug}` for the storefront branch
     *     to resolve. So the request is MODE_UNRESOLVED, `null_tenant => 'open'` adds NO predicate, and
     *     the delete reaches EVERY store.
     *
     * So any shopper signed in at any store could empty any customer's basket anywhere on the platform,
     * and `customer_id = ''` -- which MySQL reads as 0 -- would wipe every GUEST basket on the platform.
     *
     * The endpoint's own purpose is "empty my basket", so the fix is the same one this guard applies to
     * every other module storefront write: derive the identity from the principal and refuse without one.
     * A guest has no `customer_id` at all and takes the module's own cookie branch, which is why the
     * absence of a principal is a refusal here rather than a pass-through -- the module only reaches the
     * delete when it believes a customer is signed in.
     */
    private function emptyOwnCart(Request $request, Closure $next): Response
    {
        $customer = auth('customers')->user();

        if (! $customer) {
            // No customers-guard principal: the module would take its cookie branch anyway, and letting
            // the request through with a posted customer_id is the hole. Refuse.
            return $this->deny($request);
        }

        $request->merge(['customer_id' => (string) $customer->id]);

        return $next($request);
    }

    private function shopperComment(Request $request, Closure $next, mixed $slug): Response
    {
        $customer = auth('customers')->user();
        $store = is_string($slug) ? DB::table('stores')->where('slug', $slug)->first(['id']) : null;

        if (! $customer || ! $store) {
            return $this->deny($request);
        }

        // P1-SEC-34, as CORRECTED by its own review. The row must be the SLUG STORE'S OWN customer: `customers` is one session guard on
        // one host, so a shopper signed in at store A reaches store B's `{storeSlug}/spamprevent` carrying A's session, and deriving
        // `customer_id` from that session -- which is what closed the impersonation -- still files an A customer under store B.
        //
        // BUT REFUSING THAT OUTRIGHT DENIES A JOURNEY THE PLATFORM ITSELF OFFERS, which the first version of this fix did. Customer
        // e-mail uniqueness is per (email, store_id) -- the register validator only rejects an address already present IN THAT STORE
        // (CustomerLoginController:880-888) -- so one person legitimately holds a row at A and at B. The platform's own "is this shopper
        // signed in here" helper, `Utility::CustomerAuthCheck()`, answers BY E-MAIL INSIDE THE SLUG STORE (Utility:1054), so on store B it
        // says yes while `auth('customers')->user()` is still A's row; SpamPrevent's ViewComposer renders the widget on that basis alone
        // (ViewComposer:25-33 asks only that a customer is signed in and that B enables the feature); and the widget's send AJAX has a
        // `success` callback and NO `error` one (theme/icon_view.blade.php:363-393), so a 403 showed the shopper nothing at all -- no
        // toast, textarea not cleared, comment gone.
        //
        // So RESOLVE rather than refuse, using the platform's own definition: the slug store's own row for this e-mail. The write is still
        // always a customer OF THE STORE BEING WRITTEN -- which is the whole point of the check -- and the journey survives.
        $customerId = (int) $customer->store_id === (int) $store->id
            ? (int) $customer->id
            : $this->sameShopperInStore($customer, (int) $store->id);

        if ($customerId === null) {
            return $this->deny($request);
        }

        if (! $this->productOfStoreOrAbsent($request, (int) $store->id)) {
            return $this->deny($request);
        }

        $request->merge(['customer_id' => (string) $customerId, 'comment' => $this->plain($request->input('comment'))]);

        return $next($request);
    }

    /**
     * The slug store's OWN customer row for the signed-in shopper, matched exactly as `Utility::CustomerAuthCheck()` matches when it tells
     * the storefront this shopper is signed in here: same `email`, `store_id` = the slug store. Returns null when the store has no such
     * row, which is the case that still has to be refused -- there is then no customer of that store to attribute the comment to.
     *
     * Deliberately NOT a fallback to the session id: that is the cross-tenant write this guard exists to stop.
     */
    private function sameShopperInStore(object $customer, int $storeId): ?int
    {
        $email = $customer->email ?? null;

        if (! is_string($email) || $email === '' || $storeId <= 0) {
            return null;
        }

        $id = DB::table('customers')->where('store_id', $storeId)->where('email', $email)->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function threadRead(Request $request, Closure $next): Response
    {
        $customer = auth('customers')->user();

        if ($customer) {
            // the shopper reads their own thread, in the store the widget names (it must exist)
            $store = DB::table('stores')->where('id', $this->id($request->input('store_id')))->first(['id']);

            if (! $store) {
                return $this->deny($request);
            }

            $request->merge(['customer_id' => (string) $customer->id, 'store_id' => (string) $store->id]);

            return $next($request);
        }

        $user = auth('web')->user();

        if (! $user) {
            return $this->deny($request);
        }

        $customerId = $this->id($request->input('customer_id'));
        $storeId = $this->isOperator($user) ? $this->id($request->input('store_id')) : (int) getCurrentStore($user->id);

        if ($customerId === null || ! $storeId || ! $this->customerBelongsTo($customerId, $storeId)) {
            return $this->deny($request);
        }

        $request->merge(['customer_id' => (string) $customerId, 'store_id' => (string) $storeId]);

        return $next($request);
    }

    private function merchantReply(Request $request, Closure $next): Response
    {
        $user = auth('web')->user();

        if (! $user) {
            return $this->deny($request);
        }

        // NO operator exemption: the module stores the reply in the CALLER'S current store (Store::find(getCurrentStore())), so for the operator
        // (whose current store resolves to the default 1) an exemption would write a store-1 row for another store's customer.
        if (! $this->customerBelongsTo($this->id($request->input('customer_id')), (int) getCurrentStore($user->id))) {
            return $this->deny($request);
        }

        $request->merge(['comment' => $this->plain($request->input('comment'))]);

        return $next($request);
    }

    private function threadOfOwnCustomer(Request $request, Closure $next, mixed $customerId): Response
    {
        $user = auth('web')->user();

        if (! $user) {
            return $this->deny($request);
        }

        if (! $this->isOperator($user) && ! $this->customerBelongsTo($this->id($customerId), (int) getCurrentStore($user->id))) {
            return $this->deny($request);
        }

        return $next($request);
    }

    private function review(Request $request, Closure $next, mixed $slug): Response
    {
        $store = is_string($slug) ? DB::table('stores')->where('slug', $slug)->first(['id']) : null;

        if (! $store) {
            return $this->deny($request);
        }

        if (! $this->productOfStoreOrAbsent($request, (int) $store->id)) {
            return $this->deny($request);
        }

        // `store_id` is the slug's; `status` is the value the module's own form posts (1 = shown), not the visitor's choice
        $merge = ['store_id' => (string) $store->id, 'status' => '1'];

        // P1-SEC-34. This route is ANONYMOUS and the merchant's review table prints `description` through the DataTable's
        // rawColumns(['...','description','...']) -- i.e. UNESCAPED into the admin origin (ProductReviewDataTable:59). The module is
        // vendored, so the value is made safe at the door. `title` gets the same treatment because the same row is re-rendered by the
        // merchant's edit form. Only keys the caller actually SENT are rewritten, so an absent `title` stays absent and the module still
        // writes NULL for it rather than ''.
        //
        // ENCODED, NOT STRIPPED, and that is a correction to this task's first version. `strip_tags()` enters tag state on ANY '<' not
        // followed by whitespace and discards everything to the next '>' or end of string, so it silently truncated ordinary reviews --
        // measured on PHP 8.3.6: "Arrived in <2 days, great seller" became "Arrived in", and "<3" became '' and was then refused by the
        // module's own `description` => `required` rule for text containing no markup at all. The shopper saw the success message either
        // way. Encoding loses nothing and still executes nothing, because `description`'s only two consumers both decode exactly once:
        // the rawColumns cell is written with innerHTML, and the merchant's edit form renders through konekt/html's
        // `e($value, false)` (FormBuilder:573, double_encode OFF), so '&lt;2' stays '&lt;2' in the source and shows as '<2'.
        //
        // NOT applied to the comment path's plain(): `product_comments.comment` also has SERVER-escaped `{{ }}` sinks
        // (SpamPrevent reply.blade.php:169,175 and theme/icon_view.blade.php:286,292), which would then display a literal '&lt;'.
        foreach (['title', 'description'] as $field) {
            if ($request->exists($field)) {
                $merge[$field] = $this->encoded($request->input($field));
            }
        }

        $request->merge($merge);

        return $next($request);
    }

    /**
     * `product_id` is optional on BOTH forms (SpamPrevent requires only `comment`, ProductReview only `description`), so absence is
     * allowed -- but a value that is present must name a product of $storeId. Why that matters: `Product` carries NO global scope at
     * all (config/tenancy.php `scoped_models`), so an unchecked `product_id` lets a storefront write attach ANY tenant's product to a
     * row in this one, and nothing further downstream re-asks the ownership question.
     *
     * MEASURED, so the comment does not outlive the code it cites: the disclosure this looked like on
     * SpamPreventController::index does NOT happen there. index() builds `$result` (which is where its unscoped `Product::find()`
     * lives, derefed with no null check) but `spam-prevent::admin.index` renders only `<x-datatable>` -- `$result` is dead, and the
     * DataTable's own `product_id` renderer is both null-safe and htmlspecialchars-escaped. Probed with a real foreign product: its
     * name does not reach the page. The check is the tenant boundary, not a fix for a live leak on that screen.
     *
     * Normalises the value it accepts so the module stores the digits that were checked.
     */
    private function productOfStoreOrAbsent(Request $request, int $storeId): bool
    {
        $productId = $request->input('product_id');

        if ($productId === null || $productId === '') {
            return true;
        }

        $productId = $this->id($productId);

        if ($productId === null || ! DB::table('products')->where('id', $productId)->where('store_id', $storeId)->exists()) {
            return false;
        }

        $request->merge(['product_id' => (string) $productId]);

        return true;
    }

    private function customerBelongsTo(?int $customerId, int $storeId): bool
    {
        return $customerId !== null && $storeId > 0
            && (int) DB::table('customers')->where('id', $customerId)->value('store_id') === $storeId;
    }

    private function isOperator(User $user): bool
    {
        return strtolower(trim((string) $user->type)) === 'super admin';
    }

    private function id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return (is_string($value) && ctype_digit($value) && strlen($value) <= 18 && (int) $value > 0) ? (int) $value : null;
    }

    /** The comment as plain text: the modules print it unescaped in places, so markup is removed at the door. */
    private function plain(mixed $value): string
    {
        return is_string($value) ? trim(strip_tags($value)) : '';
    }

    /**
     * The review's text made inert for an HTML sink WITHOUT losing any of it -- see review()'s note for why encoding rather than
     * stripping, and for the two consumers that decode it exactly once. A non-string (e.g. `description[]=<script>`) becomes '', which the
     * module's own `required` rule then refuses, rather than being cast and stored.
     */
    private function encoded(mixed $value): string
    {
        return is_string($value) ? htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8') : '';
    }

    private function deny(Request $request): Response
    {
        $message = __('Permission denied');

        return $request->expectsJson() || $request->isMethod('post') || $request->ajax()
            ? response()->json(['status' => false, 'message' => $message], 403)
            : redirect()->back()->with('error', $message);
    }
}
