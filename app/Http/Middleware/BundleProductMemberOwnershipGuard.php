<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ComparesRowOwnership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * MASTER PLAN `PRD-005` (GAP-184) — A BUNDLE'S MEMBER PRODUCT IDS COME STRAIGHT OFF THE REQUEST,
 * AND THE STOREFRONT RENDERS WHATEVER THEY NAME.
 * ================================================================================================
 *
 * Plan text: `docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md:258`, 04-A —
 * «باقات ثابتة ومرنة ومنتجات مكملة.» / *fixed and flexible bundles, and complementary products*.
 *
 * `ModuleResourceOwnershipGuard`'s ten `bundleproduct` entries answer the ROW question — whose
 * bundle may I edit. This guard answers the REFERENCE question, which a rule keyed on a route
 * parameter cannot: `Workdo\BundleProduct\app\Http\Controllers\BundleProductController` writes
 *
 *     $bundle_product->product_id      = $request->product_id;
 *     $bundle_product->bundle_products = json_encode($request->bundle_products);
 *
 * in BOTH `store()` and `update()`, with no check that either names a product of the caller's
 * store. `store_id` is set to `getCurrentStore()`, so the row lands in the caller's OWN tenant and
 * no `store_id` predicate anywhere notices. Exactly the shape of P1-INV-22's `lottery_products`,
 * which needed `LotterySettingProductOwnershipGuard`.
 *
 * ------------------------------------------------------------------------------------------------
 * IT IS NOT COSMETIC, BECAUSE THE READ IS UNSCOPED TOO.
 *
 * `Workdo\BundleProduct\app\Providers\ViewComposer::boot()` composes the storefront product-detail
 * page with
 *
 *     $bundle_products_data = Product::whereIn('id', $bundle_product_ids)->get();
 *
 * — no store predicate. `Product` is absent from `config/tenancy.php`'s `scoped_models`, so
 * `StoreScope::isActiveFor()` short-circuits and it adds no predicate for ANY principal, and
 * `null_tenant => 'open'` means an unresolved tenant adds nothing either. The result is pushed into
 * the `bundleproductslider` stack and each row rendered through `<x-product-card>`, which prints
 * the product's name, image and price. A forged member id therefore puts another tenant's catalogue
 * entries on a PUBLIC storefront page.
 *
 * ------------------------------------------------------------------------------------------------
 * WHAT THIS GUARD DOES NOT CLOSE, STATED SO IT IS NOT READ AS CLOSED.
 *
 * This is the WRITE side only. The unscoped read above is untouched, so a `bundle_products` row
 * written before this guard existed would still render another tenant's products. The host-side
 * vehicle for that half is the vendor view override the tenancy skill documents —
 * `resources/views/vendor/bundle-product/pages/bundle_product_slider.blade.php` resolves BEFORE the
 * module's own view — with the verbatim-copy-plus-equality-pin discipline that goes with it. It is
 * recorded as the remaining step rather than left implicit.
 *
 * ------------------------------------------------------------------------------------------------
 * SIX TARGETS, AND EACH PART OF THAT NUMBER WAS READ RATHER THAN ASSUMED.
 *
 * The module declares the resource TWICE (`Route::resource('bundleproduct', …)->names(…)` and
 * `Route::resource('bundle-product', …)`), so `store()` answers at two uris and `update()` at two
 * uris under both PUT and PATCH. Measured with `tests/Support/Security/bin/route-census.php`, every
 * module loaded through the production loader. `show()`, `edit()`, `index()`, `create()` and
 * `destroy()` write nothing from the request and are deliberately not targets — the ROW guard
 * covers those.
 *
 * ------------------------------------------------------------------------------------------------
 * AN ABSENT OR EMPTY VALUE PASSES THROUGH, AND THAT IS DELIBERATE.
 *
 * Both keys are `required` in the handler's own validator, so an empty submit already gets *"The
 * product id field is required"* — a message that tells the merchant which field is wrong. A guard
 * that refused first would replace it with a permission error, which is the live, shipped-UI defect
 * `LotterySettingProductOwnershipGuard`'s docblock records ("a merchant who filled the ticket
 * numbers and forgot the product was told they lacked permission"). The guard asks the OWNERSHIP
 * question only and is silent about everything else.
 *
 * A NON-SCALAR value is a different matter and IS refused: `$bundle_product->product_id = [..]`
 * against an `unsignedBigInteger` column is a 500, and an array member would be `json_encode`d into
 * the stored list as junk. Neither is a product the caller owns, so the ownership answer and the
 * safe answer agree.
 *
 * ------------------------------------------------------------------------------------------------
 * NO OPERATOR PASS-THROUGH, and that is a measured departure from the sibling ROW guards.
 *
 * `ModuleResourceOwnershipGuard` waves the platform operator through, and is right to: its handlers
 * admit the operator, so refusing there would be a lockout. THESE handlers do not. `store()` is
 * `auth()->user() && auth()->user()->isAbleTo('Create Bundle Product')` and `update()` the same with
 * `Edit Bundle Product`, both with no operator limb — and Laratrust has no super-admin bypass, so a
 * `super admin` holds no module permission and is refused by the handler two statements later. A
 * pass-through would therefore change nothing observable today while creating a latent gap the
 * moment upstream adds an operator limb. That is `LotterySettingProductOwnershipGuard`'s argument,
 * and its removal check R8 measured the same thing: a control that buys nothing.
 *
 * The permission limb is existence-conditional through the trait, because a hard gate on a name no
 * `permissions` row exists for is a permanent 403 — the lockout `FinancePermission` records.
 */
class BundleProductMemberOwnershipGuard
{
    use ComparesRowOwnership;

    /**
     * 'METHOD uri' => the permission the handler itself checks.
     *
     * `BundleProductMemberOwnershipTest::test_every_target_is_a_real_registered_route` asserts each
     * key against the live route table, so a rename upstream cannot leave this guard silently inert
     * — the vacuity `ModuleResourceOwnershipGuard` exists with its own version of.
     *
     * @var array<string, string>
     */
    private const TARGETS = [
        'POST bundleproduct' => 'Create Bundle Product',
        'POST bundle-product' => 'Create Bundle Product',
        'PUT bundleproduct/{bundleproduct}' => 'Edit Bundle Product',
        'PATCH bundleproduct/{bundleproduct}' => 'Edit Bundle Product',
        'PUT bundle-product/{bundle_product}' => 'Edit Bundle Product',
        'PATCH bundle-product/{bundle_product}' => 'Edit Bundle Product',
    ];

    /** The parent product id, and the member list. Both are `required` in the handler's validator. */
    private const SCALAR_KEY = 'product_id';

    private const LIST_KEY = 'bundle_products';

    public function handle(Request $request, Closure $next): Response
    {
        // FIRST STATEMENTS, so this guard is inert on every other route in the application.
        $route = $request->route();

        if ($route === null) {
            return $next($request);
        }

        $permission = self::TARGETS[$request->method() . ' ' . $route->uri()] ?? null;

        if ($permission === null) {
            return $next($request);
        }

        /*
         * EXPLICIT GUARD NAME, NEVER auth()->user(). `CustomAuth` logs a token's owner into
         * whichever of the three guards matches its tokenable class, and `actingAs($c, 'customers')`
         * sets it in tests, so `auth()->user()` can return an `App\Models\Customer` — on which
         * `isAbleTo()` is a fatal. The null limb is kept although these routes are in the module's
         * own `auth` group today, because that is a property of a vendored file CI forbids editing
         * and upstream is free to change.
         */
        $user = auth('web')->user();

        if ($user === null) {
            return $this->refuse($request, 'anonymous');
        }

        if ($this->permissionIsEnforceable($permission) && ! $user->isAbleTo($permission)) {
            return $this->refuse($request, 'permission');
        }

        $ids = [];
        $parent = $request->input(self::SCALAR_KEY);

        if ($parent !== null && $parent !== '') {
            if (! is_scalar($parent)) {
                return $this->refuse($request, 'unusable parent');
            }

            $ids[] = $parent;
        }

        $members = $request->input(self::LIST_KEY);

        if ($members !== null && $members !== '' && $members !== []) {
            if (! is_array($members)) {
                /* The shipped form posts `bundle_products[]`, so a non-array here is not the form.
                   `json_encode` of a scalar would store `"7"` where the reader expects an array,
                   which `json_decode` then hands to `whereIn()` as a bare string. */
                return $this->refuse($request, 'unusable members');
            }

            foreach ($members as $member) {
                if (! is_scalar($member) || trim((string) $member) === '') {
                    return $this->refuse($request, 'unusable members');
                }

                $ids[] = $member;
            }
        }

        /*
         * NOTHING NAMED: pass through to the handler's own validator, which says WHICH field is
         * missing. See the docblock -- answering "permission denied" to an empty form is a real
         * shipped-UI defect in this family.
         */
        if ($ids === []) {
            return $next($request);
        }

        /*
         * ASK ABOUT THE ROWS, NOT THE VALUES. The shared predicate runs the question the handler
         * never asked -- "is every one of these a `products` row of the store I am acting in" --
         * and fails closed on a missing row, a NULL store and an unreadable table. Verifying that
         * the requested VALUES look owned is a different question, which MySQL's case- and
         * accent-insensitive default collation separates from this one.
         */
        if (! $this->noMatchedRowOutsideCallerStore('products', 'id', $ids, $user)) {
            return $this->refuse($request, 'product outside store');
        }

        return $next($request);
    }

    /**
     * Refuse, saying WHICH question failed.
     *
     * A reason asserted without its cause is one of the ways a test passes for the wrong reason
     * (the platform-upload policy logs one per refusal for exactly this), and collapsing every
     * reason into 'Permission denied.' is the shipped-UI defect the docblock records.
     *
     * A 403 for a JSON caller; for the web form a redirect back with an error, which is the shape
     * the handler's own refusals use — a bare 403 on a merchant's screen costs them the page for
     * what may be a mis-click, and `LotteryDrawOwnershipGuard` had to soften exactly that.
     */
    private function refuse(Request $request, string $reason): Response
    {
        Log::warning('bundle product member write refused', [
            'reason' => $reason,
            'method' => $request->method(),
            'uri' => $request->route() ? $request->route()->uri() : null,
            'user_id' => optional(auth('web')->user())->id,
        ]);

        $message = __(match ($reason) {
            'unusable parent' => 'Select a product for the bundle.',
            'unusable members' => 'Select the products this bundle contains.',
            'product outside store' => 'A bundle may only contain products from this store.',
            default => 'Permission denied.',
        });

        if ($request->expectsJson()) {
            abort(403, $message);
        }

        return redirect()->back()->with('error', $message);
    }
}
