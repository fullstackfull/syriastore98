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
 * access it did not find: a caller the handler would refuse is still refused by the handler. Its
 * single question is the one `store()` never asks -- and the precise form of that question matters:
 * NOT "is every product you named one of yours?" but "does every product you named belong to the
 * store you are ACTING IN", i.e. `getCurrentStore()`. Those differ for a multi-store merchant, and
 * the narrower one is correct, because it is the store `:114` writes and the pair `:537-539`
 * matches. MEASURED: a merchant who owns stores A and B, acting in A, is refused when they name
 * their own store-B product. That is a real behaviour limit, not a bug, and it is written down here
 * so nobody widens the predicate to ownership and breaks the match with the handler.
 *
 * NO OPERATOR PASS-THROUGH, AND THAT IS A DELIBERATE DEPARTURE FROM THE THREE SIBLING GUARDS THAT
 * HAVE ONE. `ModuleOrderMutationGuard`, `ModuleResourceOwnershipGuard` and
 * `LotteryDrawOwnershipGuard` each `return $next($request)` for a platform operator, and they are
 * right to: THEIR handlers let the operator in, so refusing there would be the lockout CLAUDE.md
 * warns about. (`LotteryRefundOwnershipGuard` has none either -- it authenticates on the
 * `customers` guard and never asks the question. So the honest count is three of five, not "both of
 * two", which an earlier version of this docblock claimed.) THIS handler does not let the operator
 * in. Measured: `store():92` is `auth()->user()->isAbleTo('Edit Lottery Setting')` with no
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
 *   A ROW WHOSE `lottery_products` IS NOT A USABLE SCALAR IS REFUSED -- and this limb was widened
 *       after measurement, because its first form SKIPPED such a row instead.
 *
 *       TWO CORRECTIONS TO WHAT AN EARLIER VERSION OF THIS DOCBLOCK SAID. It claimed the handler
 *       "would write rows whose `lottery_products` is `''`": it is NULL, not `''`, because
 *       `ConvertEmptyStringsToNull` is in Laravel's GLOBAL middleware stack, so a blank select
 *       arrives as null. And it claimed "the shipped form always supplies a product": it does not.
 *       `Form::select('lottery_products', ..., ['placeholder' => 'Select Product'])`
 *       (`index.blade.php:50`, `:111`) emits `<option value="">`, and that select is the ONLY
 *       control in the repeater row WITHOUT `required`. A newly added row therefore posts a blank
 *       product and the browser does not block submit.
 *
 *       THAT MADE THE SKIP UI-REACHABLE, which is why it is now a refusal. Fill one row, leave a
 *       second row's product blank, press Update: `is_scalar(null)` is false, so the first form of
 *       this loop skipped row two entirely, the predicate was satisfied by row one alone, and the
 *       handler wrote a NULL-product lottery the guard had never examined. A NULL
 *       `lottery_products` is invisible to every `where('lottery_products', ...)` predicate, so
 *       that row can never sell a ticket -- a silent junk write, reported to the merchant as
 *       success. The same skip also swallowed an array-valued `lottery_products`
 *       (`lottery_repeater_basic[1][lottery_products][]=<id>`), measured as HTTP 500 AFTER row one
 *       was already written. Neither case reaches another tenant, but "every value must be an owned
 *       product" is the contract this guard states, and silently exempting the values it cannot
 *       read was not that contract.
 *
 * AND ONE DELIBERATE NON-TIGHTENING: AN EMPTY ARRAY IS ALLOWED THROUGH -- but NOT for the reason an
 * earlier version of this docblock gave. It said `[]` is "how a merchant CLEARS their lotteries".
 * THAT IS FALSE, and the measurement is unambiguous: `[]` has no urlencoded representation at all
 * (`http_build_query(['lottery_repeater_basic' => []]) === ''`), and the shipped form cannot send
 * it. `index.blade.php:20` is a jquery.repeater; its delete buttons (`:76`, `:137`) remove the whole
 * `[data-repeater-item]`, every `lottery_repeater_basic[i][...]` name lives inside one and is
 * renamed at runtime by `public/js/repeater.js`, `initEmpty: false` (`:168`) and no undeletable
 * first row. A zero-row Update therefore submits only `_token` -- NO `lottery_repeater_basic` key --
 * which THIS GUARD REFUSES at the `! is_array()` limb (302, nothing written).
 *
 * SO THE UI CLEAR-ALL HAS NEVER WORKED, and this guard did not break it. Measured with ONLY this
 * guard's registration removed: the same missing-key request is `foreach (null)` at
 * `LotteryPrizesController:95` -> ErrorException "foreach() argument must be of type array|object,
 * null given" -> HTTP 500. The guard turned a vendored 500 into a clean refusal. Making clear-all
 * actually work needs `?? []` at `:93`, which is upstream's, and must not be smuggled in here: that
 * would newly enable a destructive bulk delete that has never been reachable.
 *
 * The `[]` pass-through is therefore reachable only by a hand-crafted JSON or ParameterBag caller.
 * Both were driven and both reach `store()` and delete only the caller's own rows, which is safe
 * because `:107` already scoped them to `getCurrentStore()`. It is kept because refusing it closes
 * nothing while breaking the one caller shape that works -- not because the UI depends on it.
 *
 * THIS GUARD READS `input()`; THE HANDLER'S WRITE LOOP READS `all()`. Recorded because it is a real
 * divergence and because a reader checking this guard against the handler will find it. `store():93`
 * reads `$request->input('lottery_repeater_basic')` (the min/max loop) but `:112` iterates
 * `$request['lottery_repeater_basic']`, and ArrayAccess goes `offsetGet` -> `__get` ->
 * `Arr::get($this->all(), ...)`, where `all()` is
 * `array_replace_recursive($this->input(), $this->allFiles())` -- FILES OUTRANK INPUT, the trap
 * CLAUDE.md records and `AuctionBidIdentityGuard` was fixed for. So a multipart POST can add a
 * repeater row, or override a subkey, that this guard never inspected.
 *
 * IT IS BOUNDED AND NOT A BYPASS, and the bound is the reason no code changed for it: `allFiles()`
 * yields only `UploadedFile` objects, or nothing where the upload errored out of the bag. An
 * `UploadedFile` is not scalar, so the widened limb above now REFUSES such a row outright rather
 * than skipping it; and even before that, its string form is a PHP-assigned temp path that denotes
 * no product. No multipart construction was found that names another tenant's product while this
 * guard allows the request. No legitimate caller is multipart either: `index.blade.php:15`'s
 * `Form::model([...])` passes that array as the MODEL argument, not the options, so the rendered
 * form carries no `enctype` and posts urlencoded.
 *
 * THE STORE THIS GUARD COMPARES AGAINST IS `getCurrentStore($user->id)`, so the guard is exactly as
 * strong as `users.current_store` -- a column in `User::$fillable`. That premise is the HANDLER'S
 * too (`:107` scopes by it and `:114` writes it), so it is shared rather than introduced here, and
 * a sweep of every write to that column found no path by which a merchant or staff principal points
 * it at a store they do not own (`StoreController::changeStore` constrains the lookup to
 * `created_by = creatorId()` for non-operators and aborts otherwise, covered by
 * `tests/Feature/Security/TenantSwitchTest.php`). If one is ever found, this guard is satisfied by
 * it and the attacker writes a lottery INTO the victim's store -- and the widest part of that
 * exposure is the empty-array path above, where the handler's delete loop runs with the product
 * predicate never consulted.
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
     * Keyed on method+uri, as `ModuleResourceOwnershipGuard` and `LotteryDrawOwnershipGuard` are,
     * so `GET lotteryproduct` (the settings screen) and the rest of the resource are untouched.
     * (The other two guards key differently -- `ModuleOrderMutationGuard` on the uri alone and
     * `LotteryRefundOwnershipGuard` on `getActionName()` -- so "like the sibling guards" would be
     * wrong; an earlier version of this comment said it.) Method+uri is the right key HERE because
     * one action is reached by exactly one method+uri and the resource deliberately puts four other
     * verbs on the same uri: if upstream ever fills `update()`, this guard will NOT silently begin
     * inspecting a PUT body it was never checked against. The stub test below is what makes that
     * safe.
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

            // REFUSED, NOT SKIPPED -- see the docblock. The first form of this loop skipped a value
            // it could not read, which made a blank select (null, via ConvertEmptyStringsToNull) or
            // an array-valued `lottery_products` an unexamined write whenever ANOTHER row in the
            // same post was valid. Both are reachable from the shipped form.
            if (! is_scalar($value) || trim((string) $value) === '') {
                return $this->deny($request, 'no product');
            }

            $values[] = $value;
        }

        if ($values === []) {
            // Unreachable while $rows is non-empty, because the loop above refuses rather than
            // skips. Kept as a floor: it is what answers a future change that makes the loop
            // permissive again.
            return $this->deny($request, 'no product');
        }

        if (! $this->noMatchedRowOutsideCallerStore($target['table'], $target['column'], $values, $user)) {
            return $this->deny($request, 'product outside store');
        }

        return $next($request);
    }

    /**
     * The message for each refusal reason.
     *
     * TWO OF THESE ARE NOT AUTHORIZATION FAILURES AND MUST NOT SAY THEY ARE. An earlier version of
     * this guard answered every reason with 'Permission denied.', and that produced a LIVE,
     * SHIPPED-UI defect: the `lottery_products` select is the only control in the repeater row
     * without `required` and renders `<option value="">` selected, so a merchant who fills the
     * ticket numbers and forgets the product -- an ordinary mistake -- was told they lacked
     * permission, with no indication which field was wrong. The same applied to a submit carrying
     * no settings at all. The reason was already a parameter of this method and was simply
     * discarded.
     *
     * This also makes removal check R6 load-bearing: while every reason produced the same string,
     * deleting the no-usable-product limb changed nothing observable.
     */
    private const MESSAGES = [
        'no product' => 'Select a product for each lottery setting.',
        'no settings' => 'Add at least one lottery setting.',
    ];

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
        $message = __(self::MESSAGES[$reason] ?? 'Permission denied.');

        if ($request->expectsJson()) {
            abort(403, $message);
        }

        return redirect()->back()->with('error', $message);
    }
}
