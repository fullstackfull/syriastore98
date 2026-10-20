<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ComparesRowOwnership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-INV-22 / #70 ITEM 5 -- `LotteryPrizesController::store()` writes `lottery_settings` rows whose
 * `lottery_products` is a caller-supplied product id, with no check that the product belongs to the
 * caller's own store.
 *
 * REPRODUCED BY EXECUTION, not read. A merchant holding `Edit Lottery Setting`, entitled to the
 * module, posting two repeater rows -- one naming their own product, one naming another store's:
 *
 *   POST /lotteryproduct  -> 302 'Lottery Setting successfully updated.'
 *     lottery_settings id=1 store_id=1 lottery_products=2 (OWN PRODUCT,    store 1) price=50
 *     lottery_settings id=2 store_id=1 lottery_products=1 (VICTIM PRODUCT, store 2) price=7
 *
 * `store():107` scopes the EXISTING rows by `getCurrentStore()` and `:114` writes that same
 * `store_id`, so the row lands in the attacker's own tenant -- which is exactly why no `store_id`
 * predicate anywhere notices. The cross-tenant part is the REFERENCE: `lottery_products` points at
 * a product the caller does not own, and both the price and the draw key on that lottery.
 *
 * WHY THIS IS FINANCIAL AND NOT COSMETIC. `lottery_form_data:535` resolves the product with
 * `Product::find($request->product_id)` -- no store predicate, and `Product` is in no
 * `scoped_models` list, so it reaches every tenant's catalogue -- and `:537-539` then resolves the
 * setting `where('store_id', $store->id)->where('lottery_products', $product->id)`. With the forged
 * row present that pair MATCHES, so the attacker's own storefront sells tickets for the victim's
 * product at the attacker's own `ticket_price`. The draw then awards the victim's product.
 *
 * WHAT THIS GUARD IS AND IS NOT. It is a REFUSAL, not an authorization check. It never grants
 * access it did not find: a caller the handler would refuse is still refused by the handler, and
 * the operator is passed through for the reason below. Its single question is the one `store()`
 * never asks -- "is every product you named one of yours?".
 *
 * NO OPERATOR PASS-THROUGH, AND THAT IS A DELIBERATE DEPARTURE FROM THE TWO SIBLING GUARDS. They
 * both `return $next($request)` for a platform operator, and they are right to: THEIR handlers let
 * the operator in, so refusing here would be the lockout CLAUDE.md warns about. THIS handler does
 * not. Measured: `store():92` is `auth()->user()->isAbleTo('Edit Lottery Setting')` with no
 * operator limb, and this codebase has no super-admin bypass, so a `super admin` returns FALSE and
 * the handler refuses the platform operator outright. That is #70 item 3 -- a vendored fail-closed
 * defect this guard cannot fix and must not paper over.
 *
 * So a pass-through here would change nothing observable today (the handler refuses two statements
 * later, with the same message) while creating a LATENT GAP: the moment upstream adds the operator
 * limb that item 3 asks for, an operator would reach `store()` with NO ownership check at all,
 * able to write a lottery in `getCurrentStore()` against any tenant's product. That gap was found
 * by this guard's own removal check R8 -- deleting the pass-through moved no test, which is what a
 * control that buys nothing looks like. It was removed rather than kept with a note.
 *
 * The operator is therefore treated like any other principal: they must hold the permission, and
 * every product they name must belong to the store they are acting in. If a future upstream fix
 * means the operator legitimately administers a store, that is exactly the right rule for them
 * too.
 *
 * ONLY `POST lotteryproduct` IS TARGETED, and the resource's other verbs were READ, not assumed:
 * `update():165` and `destroy():175` are both `//` -- empty stubs. That is stated explicitly
 * because an exemption scoped to the methods somebody happened to look at is the fourth distinct
 * way a census in this repository has missed what it claimed (see LotteryDrawOwnershipGuard's
 * closing note). If upstream ever fills either stub, this TARGETS map must grow with it, and
 * LotterySettingProductOwnershipTest asserts both are still stubs so that change goes red here.
 *
 * TWO DELIBERATE TIGHTENINGS, both on paths that fail today rather than succeed:
 *
 *   A NON-ARRAY `lottery_repeater_basic` is refused. Today `store():95` does
 *       `foreach ($lotterySettings as ...)` on it, so a missing key is `foreach (null)` -- a
 *       warning that Laravel's handler (error_reporting() === -1) turns into an ErrorException, i.e.
 *       a 500. A clean refusal is strictly better and loses no working behaviour.
 *
 *   A REPEATER WITH ROWS BUT NO USABLE PRODUCT ID is refused. The handler would write rows whose
 *       `lottery_products` is `''` into the caller's own store -- junk, not cross-tenant, but the
 *       shipped form always supplies a product and a lottery with no product is not a thing the
 *       merchant can use.
 *
 * AND ONE DELIBERATE NON-TIGHTENING, which is the regression the refund guard shipped and had to
 * withdraw: AN EMPTY ARRAY IS ALLOWED THROUGH. `[]` is how a merchant CLEARS their lotteries --
 * `:127-131` deletes every existing row not named in the post, and `:107` scoped those rows to the
 * caller's own store, so a clear-all touches nothing but the caller's own data. Refusing it would
 * take a working feature away to close nothing.
 *
 * THE VALUES ARE PASSED TO THE PREDICATE RAW, deliberately. The trait's first limb runs
 * `whereIn('id', $values)` against `products`, which is MySQL's own coercion -- the same coercion
 * `lottery_form_data:538`'s `where('lottery_products', $product->id)` will later perform on the
 * stored string. So `'2abc'` is caught as a reference to product 2 rather than waved through as
 * "not a usable id", which is the divergence that made ModuleOrderMutationGuard worthless before it
 * was fixed.
 */
class LotterySettingProductOwnershipGuard
{
    use ComparesRowOwnership;

    /**
     * 'METHOD uri' => the body key, the nested key inside each repeater row, the table and column
     * its values must resolve in, and the permission the handler itself checks.
     *
     * Keyed on method+uri like the two sibling guards, so `GET lotteryproduct` (the settings
     * screen) and the rest of the resource are untouched.
     */
    private const TARGETS = [
        'POST lotteryproduct' => [
            'key' => 'lottery_repeater_basic',
            'subkey' => 'lottery_products',
            'table' => 'products',
            'column' => 'id',
            // What store():92 checks, and what the module's own PermissionTableSeeder creates.
            // Existence-conditional via permissionIsEnforceable(), as in the trait -- a hard gate
            // on a name that is not seeded is the lockout FinancePermission records.
            'permission' => 'Edit Lottery Setting',
        ],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // FIRST STATEMENTS, so this guard is inert on every other route in the application.
        $route = $request->route();

        if ($route === null) {
            return $next($request);
        }

        $target = self::TARGETS[$request->method() . ' ' . $route->uri()] ?? null;

        if ($target === null) {
            return $next($request);
        }

        /*
         * EXPLICIT GUARD NAME, NEVER auth()->user(). The default guard is mutable -- CustomAuth
         * logs a token's owner into whichever of the three guards matches its tokenable class, and
         * actingAs($customer, 'customers') sets it in tests -- so auth()->user() can return an
         * App\Models\Customer, on which isAbleTo() is a fatal.
         *
         * The null limb is belt-and-braces: this route is in the module's own `auth` group today.
         * It is kept because that is a property of a vendored file CI forbids editing and upstream
         * is free to change -- which is exactly how ModuleOrderMutationGuard's six routes came to
         * be anonymous.
         */
        $user = auth('web')->user();

        if ($user === null) {
            return $this->deny($request, 'anonymous');
        }

        if ($this->permissionIsEnforceable($target['permission'])
            && ! $user->isAbleTo($target['permission'])) {
            return $this->deny($request, 'permission');
        }

        $rows = $request->input($target['key']);

        if (! is_array($rows)) {
            return $this->deny($request, 'no settings');
        }

        // The clear-all case. Allowed -- see the docblock.
        if ($rows === []) {
            return $next($request);
        }

        $values = [];

        foreach ($rows as $row) {
            $value = is_array($row) ? ($row[$target['subkey']] ?? null) : null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                $values[] = $value;
            }
        }

        if ($values === []) {
            return $this->deny($request, 'no product');
        }

        if (! $this->noMatchedRowOutsideCallerStore($target['table'], $target['column'], $values, $user)) {
            return $this->deny($request, 'product outside store');
        }

        return $next($request);
    }

    /**
     * Refuse.
     *
     * A 403 for a JSON caller; for the web form a redirect back with an error, which is the shape
     * the handler's own refusal at `:135` uses. The sibling guards learned this the hard way: a
     * bare 403 on a merchant's settings screen costs them the page for what may be a mis-click,
     * and `LotteryDrawOwnershipGuard` had to soften exactly that.
     */
    private function deny(Request $request, string $reason): Response
    {
        if ($request->expectsJson()) {
            abort(403, __('Permission denied.'));
        }

        return redirect()->back()->with('error', __('Permission denied.'));
    }
}
