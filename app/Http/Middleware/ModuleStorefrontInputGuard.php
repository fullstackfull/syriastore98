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
 *         session's, a `product_id` must belong to that store, and the comment is stripped of markup. The store and product checks are
 *         P1-SEC-34, and they close a cross-tenant WRITE whose downstream exposure is latent rather than live -- the two screens that
 *         read these columns were measured and neither crashes nor leaks (see shopperComment and productOfStoreOrAbsent).
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
 *         `status` is the form's own value (1), a `product_id` must belong to that store, and title/description are stripped (P1-SEC-34).
 *
 * Every refusal is a 403 JSON / redirect, never a 401. Who the operator is: `users.type` 'super admin', tested first.
 */
class ModuleStorefrontInputGuard
{
    /** The route NAMES this guard acts on (RouteCensusGateTest reads it to prove a manifest `guarded:ModuleStorefrontInputGuard` key is really covered). */
    public const ROUTE_NAMES = ['spamprevent.store', 'fetch.comments', 'spamprevent.admin.store', 'spamprevent.edit', 'spamprevent.destroy', 'product_review.store'];

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
        };
    }

    private function shopperComment(Request $request, Closure $next, mixed $slug): Response
    {
        $customer = auth('customers')->user();
        $store = is_string($slug) ? DB::table('stores')->where('slug', $slug)->first(['id']) : null;

        // P1-SEC-34. The shopper must be the SLUG STORE'S OWN. `customers` is a session guard on one host, so a shopper signed in at
        // store A reaches store B's `{storeSlug}/spamprevent` carrying A's session: deriving `customer_id` from that session (which is
        // what closed the impersonation) still files an A customer under store B, i.e. a cross-tenant WRITE into store B's
        // `product_comments`. What that row then does downstream was MEASURED, not assumed, and it is less than it looks:
        // SpamPreventController::index does deref `Customer::find($customerId)->first_name` and `Customer` IS scoped (a foreign
        // customer resolves to null, confirmed), but in PHP 8.3 a property read on null is a WARNING, not an Error -- the screen
        // returned 200, including under withoutExceptionHandling(). So the exposure is LATENT: it needs a consumer that reads these
        // columns without a tenant check, and the ones that exist today do not. The boundary itself is the control.
        if (! $customer || ! $store || (int) $customer->store_id !== (int) $store->id) {
            return $this->deny($request);
        }

        if (! $this->productOfStoreOrAbsent($request, (int) $store->id)) {
            return $this->deny($request);
        }

        $request->merge(['customer_id' => (string) $customer->id, 'comment' => $this->plain($request->input('comment'))]);

        return $next($request);
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
        // vendored, so the markup comes off at the door, exactly as it does on the comment path. `title` gets the same treatment
        // because the same row is re-rendered by other screens. Only keys the caller actually SENT are rewritten, so an absent
        // `title` stays absent and the module still writes NULL for it rather than ''.
        foreach (['title', 'description'] as $field) {
            if ($request->exists($field)) {
                $merge[$field] = $this->plain($request->input($field));
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

    private function deny(Request $request): Response
    {
        $message = __('Permission denied');

        return $request->expectsJson() || $request->isMethod('post') || $request->ajax()
            ? response()->json(['status' => false, 'message' => $message], 403)
            : redirect()->back()->with('error', $message);
    }
}
