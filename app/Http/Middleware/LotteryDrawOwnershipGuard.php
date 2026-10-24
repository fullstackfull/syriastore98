<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ComparesRowOwnership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-INV-18 / #64. `POST lotteryresult` DRAWS ANOTHER TENANT'S TICKET CODES, FORGING PAID HOST
 * ORDERS AND PLANTING ROWS INSIDE THE VICTIM'S TENANT.
 * =============================================================================================
 *
 * The third guard in this family. `ModuleOrderMutationGuard` closed six anonymous module
 * mutations; `ModuleResourceOwnershipGuard` closed four cross-tenant writes and two reads behind
 * `Route::resource`. This one closes a `store()` — the method both of those censuses were blind
 * to, and the reason they were blind is recorded below because it is the instructive part.
 *
 * THE HANDLER. `packages/workdo/LotteryPrizes/src/app/Http/Controllers/LotteryResultController.php`
 * `store():66-193`, reachable as `POST lotteryresult` from `Route::resource('lotteryresult', …)`
 * at that module's `routes/web.php:21`, inside `['auth','web','PlanModuleCheck:LotteryPrizes']`.
 * **No permission check** — only `index():32` checks `Manage Lottery Winner`.
 *
 *   :78  $selectedCodes = explode(',', $request->input('selected_codes'));
 *   :86  PurchaseTicket::with(...)->whereIn('lottery_code', $selectedCodes)->get();   // NO STORE
 *   :91  $store = Store::find($user->current_store);                                 // the CALLER'S
 *
 * So the target set is attacker-named and the destination store is the attacker's own. Then, per
 * ticket:
 *
 *   :125-142  a HOST App\Models\Order with product_id = THE VICTIM'S product, final_price = the
 *             victim's ticket_price, payment_status => 'Paid', store_id => the ATTACKER'S store.
 *             `payment_status` is exactly what OrderObserver::updated reacts to.
 *   :148      LotteryCustomer::insert() with 'store_id' => $ticket->store_id -- rows written INTO
 *             THE VICTIM'S TENANT, and `::insert()` bypasses model events and every global scope.
 *
 * That second one is the unusual direction: not reading another tenant's data but PLANTING rows in
 * it, where the victim's own `where('store_id', …)` listings will show them as their own.
 *
 * REPRODUCED BY EXECUTION. A merchant in store A, holding `Manage Lottery Winner` and the plan
 * entitlement, drew store B's code and produced an `orders` row with `store_id: 1`,
 * `payment_status: "Paid"`, `final_price: 777` and a `product_json` whose embedded `store_id` is
 * 2 — the victim's product inside the attacker's order — plus a `lottery_customers` row with
 * `store_id: 2`.
 *
 * ===========================================================================================
 *  THE PREDICATE IS A SET, NOT A ROW, WHICH IS WHY THIS IS NOT AN ENTRY IN EITHER SIBLING.
 * ===========================================================================================
 *
 * Both siblings answer "which ROW does this request name?" — from a body key, or from a route
 * parameter. This request names no row: it carries a comma-separated list of ticket CODES, and the
 * handler resolves them with `whereIn`. So the trait grew `valuesAllInCallerStore()`, and the rule
 * is ALL: every code must resolve to a `purchase_tickets` row in the caller's store, or the whole
 * call is refused.
 *
 * REFUSING THE WHOLE CALL RATHER THAN FILTERING THE LIST IS THE LOAD-BEARING CHOICE. The handler
 * re-reads `selected_codes` itself, so a guard that narrowed the list would authorise a subset
 * while the handler acted on the whole of it — the same divergence as authorising one row while
 * the handler mutates another, which `ModuleOrderMutationGuard` shipped once and had to fix.
 *
 * WHAT THAT CLOSES BEYOND THE HEADLINE, AND WHAT IT DOES NOT.
 *
 *   CLOSED: a code matching NO ticket is refused here, so `:151`'s use of `$lottery_setting`
 *           after the `:95` loop -- an undefined variable -- is no longer reachable from this
 *           route. (A separate 500 on the SUCCESS path, at `:157`, is NOT closable from here: see
 *           the id-resolution block.)
 *   CLOSED: the cross-tenant half of the replay guard. `:79` resolves
 *           `LotteryCustomer::whereIn('lottery_code', …)` with no store predicate and returns an
 *           error NAMING the codes, which is an existence oracle over every tenant's code space.
 *           It is now reachable only with codes the caller owns.
 *   CLOSED, AND THIS IS THE SERIOUS HALF OF THE SHARED-CODE CASE. If two tenants hold a
 *           collation-equal code and the other has NOT drawn it, the handler's unscoped `whereIn`
 *           at `:87` returns BOTH rows and the loop writes a forged 'Paid' order for the victim's
 *           product plus a `lottery_customers` row in the victim's tenant -- byte for byte the
 *           reproduced defect. The set predicate's FIRST limb refuses it: it runs the handler's
 *           own query with no store filter and denies if ANY matched row is foreign. Found twice
 *           independently -- by thinking the predicate through, and by the adversarial review,
 *           whose recommended fix is the one already shipped.
 *   NOT CLOSED: the benign half of the same case. If the other tenant HAS drawn their
 *           collation-equal code, this guard allows the request -- there is no foreign
 *           `purchase_tickets` row, only a foreign `lottery_customers` row -- and the handler's
 *           own global replay guard at `:79` refuses it. A cross-tenant denial of service over
 *           the code space, closable only inside vendored code.
 *           BOTH halves are unreachable rather than merely unlikely, and that is measured: the
 *           ONLY writer of `purchase_tickets` in the repository is LotteryPrizesController.php:600,
 *           and the code comes from `generateRandomCode(..., 8)` over 62 symbols via `random_int`
 *           (:599, :691-698). No principal can choose, seed or influence their own code, so this
 *           is hardening and calling it live would be an overclaim.
 *   CLOSED TRANSITIVELY, stated so a reader need not re-derive it: the `$fail_ticket` block at
 *           `:151-190` has no store predicate of its own, but it keys on
 *           `lottery_id = $lottery_setting->id`, and the only writer of `purchase_tickets`
 *           (LotteryPrizesController.php:595-617) resolves the setting
 *           `where('store_id', $store->id)` and writes that same `store_id` in the SAME statement.
 *           So `lottery_id` and `store_id` are coupled at creation, and once this guard has
 *           established that every named code is the caller's, that block is same-tenant too.
 *   NOT CLOSED: `:144`/`:185`'s `sleep(1)` per ticket. The trait caps the list this guard will
 *           consider at 500 values, which bounds what the guard itself costs, but the handler's
 *           own sleep is inside vendored code.
 *
 * ===========================================================================================
 *  WHY NEITHER SIBLING'S CENSUS COULD HAVE FOUND THIS, which is my own mistake.
 * ===========================================================================================
 *
 * P1-INV-17's stated scope was "every `update()` and `destroy()` body behind the two modules' six
 * `Route::resource` declarations" — `store()` was never in it, and that write-up's "What is NOT
 * closed" named only `show()`. Worse, `ModuleOrderMutationGuardTest`'s census carried
 * `'lotteryresult/{lotteryresult}' => 'stub: update():221 and destroy():231 are both //'` —
 * true of those two methods and FALSELY REASSURING ABOUT THE RESOURCE.
 *
 * That is the fourth distinct way a census in this repository has failed to cover what it claimed:
 * keyed on uri words (order documents), on `Route::post` lines (module mutations), on the uri
 * prefix (resource mutations), and now AN EXEMPTION SCOPED TO THE METHODS SOMEBODY HAPPENED TO
 * LOOK AT. All three lottery entries now state which methods were read and which were not, and
 * `LotteryResultOwnershipTest` carries a census over `store()` itself.
 */
class LotteryDrawOwnershipGuard
{
    use ComparesRowOwnership;

    /**
     * 'METHOD uri' => [the body key, the table and column its values must resolve in, permission].
     *
     * Keyed on method+uri like the resource guard, so `GET lotteryresult` (the listing) and the
     * rest of the resource are untouched.
     */
    private const TARGETS = [
        'POST lotteryresult' => [
            'key' => 'selected_codes',
            'table' => 'purchase_tickets',
            'column' => 'lottery_code',
            // What the module's own index():32 checks, and what its PermissionTableSeeder creates
            // (packages/workdo/LotteryPrizes/src/database/seeders/PermissionTableSeeder.php:23).
            // Existence-conditional, as in the trait -- see its docblock for why a hard gate on a
            // missing name is the lockout FinancePermission records.
            'permission' => 'Manage Lottery Winner',
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
         * App\Models\Customer and isAbleTo() would be a fatal on a model with no such method.
         *
         * The null limb is belt-and-braces here: the route is in the module's own `auth` group, and
         * `Authenticate` runs ahead of the `web` group's appended middleware. It is kept because
         * "the group has auth today" is a property of a vendored file CI forbids this repository
         * from editing and upstream is free to change -- which is exactly how
         * ModuleOrderMutationGuard's six routes came to be anonymous.
         */
        $user = auth('web')->user();

        if ($user === null) {
            return $this->deny($request, 'anonymous');
        }

        if ($this->isPlatformOperator($user)) {
            return $next($request);
        }

        if ($this->permissionIsEnforceable($target['permission'])
            && ! $user->isAbleTo($target['permission'])) {
            return $this->deny($request, 'permission');
        }

        /*
         * THE SAME KEY THE HANDLER READS, AND SPLIT THE SAME WAY. `:78` is
         * `explode(',', $request->input('selected_codes'))`, so this is that expression -- a guard
         * that split on a different separator would authorise a different set from the one the
         * handler acts on.
         *
         * ON WHITESPACE, AND ON A CLAIM OF MINE THAT MEASUREMENT REFUTED. An earlier version of
         * this docblock argued that trimming in the set predicate "can only ever be STRICTER"; the
         * adversarial review inverted that and proposed refusing any untrimmed value, on the
         * grounds that the guard would accept `"\nCODE"` as `CODE` while the handler's `whereIn`
         * matched nothing and then 500'd on an undefined `$lottery_setting`.
         *
         * BOTH READINGS WERE WRONG, and the measurement is why: `TrimStrings` is in the GLOBAL
         * middleware stack, so the whole field value is already trimmed before either this guard or
         * the handler reads it. Driven through the real stack, `"\nWHITESPACE-1"` and
         * `"WHITESPACE-1"` behave IDENTICALLY -- both reach the handler and both 500 at `:157` for
         * an unrelated reason (see below). There was no guard/handler divergence to close, so the
         * rule the review proposed was removed again rather than kept with a refuted justification
         * -- and it had a cost: it turned an empty selection into a 403 instead of the 302 the
         * deny() note describes.
         *
         * `TrimStrings` normalises the OUTER value only, so an inner element can still carry
         * whitespace: `'MINE, THEIRS'` explodes to `['MINE', ' THEIRS']`, and the trait trims each
         * while the handler does not. That divergence runs in the SAFE direction -- the guard
         * considers a value the handler will ignore -- and the dangerous case is covered by the
         * first limb regardless, which asks about the ROWS the handler matches rather than the
         * values it was given.
         *
         * THE 500 AT `:157` IS REAL AND IS NOT THIS GUARD'S. `$FailTemplate['id']` dereferences a
         * null `EmailTemplate` lookup whenever the caller has no 'Lottery Fail' template, so a
         * LEGITIMATE own-store draw 500s after writing its rows. Found because a positive test of
         * mine was passing through it. Raised separately; this guard cannot close an availability
         * bug on the success path.
         */
        $raw = $request->input($target['key']);

        if (! is_scalar($raw) || trim((string) $raw) === '') {
            return $this->deny($request, 'no codes');
        }

        $codes = explode(',', (string) $raw);

        /*
         * "DID YOU SEND ANYTHING USABLE" IS THIS GUARD'S QUESTION, NOT THE PREDICATE'S, and
         * separating them is what makes the softened `no codes` reason reachable. `' , , '`
         * survives the blank check above -- `TrimStrings` normalises the outer value to `', ,'`,
         * which is not empty -- and then explodes to nothing usable. Left to the predicate that
         * came back as `code outside store`, i.e. a 403, which is the regression the deny() note
         * describes: a mis-click costing the merchant their screen.
         */
        $usable = array_values(array_filter(
            array_map(static fn ($code) => trim((string) $code), $codes),
            static fn ($code) => $code !== ''
        ));

        if ($usable === []) {
            return $this->deny($request, 'no codes');
        }

        if (! $this->noMatchedRowOutsideCallerStore($target['table'], $target['column'], $codes, $user)) {
            return $this->deny($request, 'code outside store');
        }

        return $next($request);
    }

    /**
     * Refuse.
     *
     * `$reason` is logged, never returned. The response must not say whether a code exists,
     * belongs to another tenant, or was never a code -- otherwise the guard replaces the handler's
     * own cross-tenant existence oracle with one of its own.
     */
    private function deny(Request $request, string $reason): Response
    {
        $actorId = optional(auth('web')->user())->id;

        Log::warning('lottery draw refused', [
            'reason' => $reason,
            'method' => $request->method(),
            'uri' => $request->route() ? $request->route()->uri() : null,
            'user_id' => $actorId,
            'current_store' => $actorId === null ? null : getCurrentStore($actorId),
        ]);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['status' => 'error', 'message' => __('Forbidden.')], 403);
        }

        /*
         * "YOU SENT NOTHING" IS NOT A SECURITY REFUSAL, AND A 403 ERROR PAGE FOR IT WAS A
         * REGRESSION I INTRODUCED. The draw screen posts a plain form whose `selected_codes` is a
         * hidden field initialised empty, with NO client-side guard against submitting before a
         * seat is picked (resources/views/admin/lottery-result/index.blade.php:151, :166-168,
         * :207). Before this guard, a mis-click hit the handler's own `required` validator and
         * came back as a 302 with a toast; after it, the caller got a 403 error page and lost the
         * screen. Found by the adversarial review, which also noted that
         * `test_an_empty_code_list_denies` drove `postJson` for every payload, so the web path was
         * untested.
         *
         * ONLY THIS REASON is softened, and only for a non-JSON request. It discloses nothing
         * about any tenant's rows -- it is a statement about the caller's own empty input -- so the
         * uniform-refusal property the two oracle tests pin is untouched. Every other reason still
         * abort(403)s, and this does NOT fall through to the handler: `' , , '` would otherwise
         * reach `:152` and 500.
         */
        if ($reason === 'no codes') {
            return redirect()->back()
                ->with('error', __('Please select at least one winning code.'));
        }

        abort(403);
    }
}
