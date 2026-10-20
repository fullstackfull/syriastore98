<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-INV-22 / #70 ITEM 5 -- a merchant can point their own lottery at another store's product.
 *
 * THE DEFECT, REPRODUCED BY EXECUTION before the guard was written:
 *
 *   POST /lotteryproduct as a merchant holding `Edit Lottery Setting`, two repeater rows
 *     -> 302 'Lottery Setting successfully updated.'
 *        lottery_settings id=1 store_id=1 lottery_products=2 (OWN PRODUCT,    store 1) price=50
 *        lottery_settings id=2 store_id=1 lottery_products=1 (VICTIM PRODUCT, store 2) price=7
 *
 * `store():107` scopes the existing rows by `getCurrentStore()` and `:114` writes that same
 * `store_id`, so the row lands in the ATTACKER'S OWN tenant. That is why no `store_id` predicate
 * anywhere notices: the row is not misplaced, its REFERENCE is. `lottery_products` names a product
 * the caller does not own, and both `ticket_price` and the draw key on that lottery.
 *
 * WHY IT IS FINANCIAL, asserted below rather than argued: `lottery_form_data:535` resolves the
 * product with `Product::find($request->product_id)` -- no store predicate, and `Product` is in no
 * `scoped_models` list, so it reaches every tenant's catalogue -- and `:537-539` then matches the
 * setting on `(store_id, lottery_products)`. With the forged row present that pair matches, so the
 * attacker's storefront sells tickets for the victim's product at the attacker's price.
 *
 * THE FIX: `App\Http\Middleware\LotterySettingProductOwnershipGuard`, appended to the `web` group,
 * keyed on `POST lotteryproduct`. It uses the shared `ComparesRowOwnership` set predicate rather
 * than a fourth hand-written comparison -- two of the defects in the first two such guards were
 * found by review and not by test, which is why the predicate lives in one place.
 *
 * THREE THINGS THIS SUITE PINS THAT ARE NOT THE HEADLINE:
 *
 *   THE OPERATOR IS **NOT** PASSED THROUGH, AND THE EARLIER TEXT HERE SAID THE OPPOSITE. This
 *   paragraph used to read "THE OPERATOR IS PASSED THROUGH ON PURPOSE ... the guard defers and the
 *   handler's own refusal stands", which described the FIRST version of the guard -- the one
 *   removal check R8 withdrew. The shipped guard has no `isPlatformOperator` call, and the two
 *   operator tests below assert that the ownership rule binds the operator too. Six independent
 *   verification agents each flagged this contradiction unprompted; a reader following the old text
 *   would have re-added the pass-through and re-opened the gap R8 found. Why the departure is right
 *   is in the guard's own docblock.
 *
 *   AN EMPTY REPEATER IS ALLOWED THROUGH -- BUT IT IS NOT THE MERCHANT'S CLEAR-ALL, which is what
 *   the earlier text here claimed. MEASURED: `[]` has no urlencoded representation
 *   (`http_build_query(['lottery_repeater_basic' => []]) === ''`) and the jquery.repeater form
 *   cannot send it; deleting every row submits only `_token`, i.e. NO key, which this guard refuses
 *   as 'no settings'. So the UI clear-all has never worked -- without this guard the same request
 *   is `foreach (null)` at `LotteryPrizesController:95` and a 500, measured with only the
 *   registration line removed. The `[]` pass-through is reachable only by a hand-crafted JSON or
 *   ParameterBag caller; it is kept because refusing it closes nothing while breaking the one
 *   caller shape that works. `test_an_empty_repeater_still_clears_...` below therefore exercises a
 *   transport no browser produces, and says so.
 *
 *   THE RESOURCE'S OTHER VERBS WERE READ, NOT ASSUMED. `update():165` and `destroy():175` are both
 *   `//`. Asserted, because an exemption scoped to the methods somebody happened to look at is the
 *   fourth distinct way a census here has missed what it claimed.
 *
 * REMOVAL CHECKS, AS MEASURED (scripted; each control deleted or weakened ALONE, the suite re-run,
 * the file restored byte-for-byte and verified identical). 9 of 10 isolate:
 *
 *   R1 the appendToGroup registration ......... 8 fail
 *   R2 the ownership predicate ................ 4 fail
 *   R3 the TARGETS method+uri key ............. 8 fail
 *   R4 the nested subkey extraction ........... 8 fail   <- taking the row instead of its
 *                                                           lottery_products value
 *   R5 the non-array refusal .................. 1 fail
 *   R6 refuse-not-skip an unreadable value .... 2 fail
 *   R8 RE-ADDING the operator pass-through .... 1 fail   <- the control here is its ABSENCE; the
 *                                                           first version of this guard had the
 *                                                           pass-through
 *   R9 the clear-all pass-through ............. 1 fail
 *   R10 the per-reason deny() messages ........ 4 fail
 *
 *   R7 the permission check ................... GREEN -- SUBSUMED BY THE HANDLER: store():92 makes
 *                                               the same check, so no test can tell the two apart.
 *                                               Kept as defence in depth and for consistency with
 *                                               the sibling guards: if upstream ever drops :92's
 *                                               check, this one still holds.
 *
 * TWO OF THESE CHANGED AFTER AN ADVERSARIAL REVIEW, AND THE CHANGE IS THE POINT. R6 USED TO BE
 * GREEN and was labelled subsumed -- correctly as far as it went: the limb only SKIPPED a value it
 * could not read, and an all-skipped post reached the trait's empty-set refusal anyway. What the
 * labelling missed is that the skip was invisible ONLY when every row was unreadable; with one
 * valid row alongside, the unreadable row was written unexamined. The limb now refuses, and R6
 * bites. R10 is new with the per-reason messages, which R6 also depended on: while every reason
 * produced the identical string, deleting a reason-specific limb changed nothing observable. A
 * subsumption label is a claim about today's controls, not a permanent property.
 */
class LotterySettingProductOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'Edit Lottery Setting';

    private function enableModule(): void
    {
        AddOnManager::create([
            'module' => 'LotteryPrizes',
            'name' => 'Lottery Prizes',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'lottery-prizes',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['LotteryPrizes']);

        foreach (glob(base_path('packages/workdo/LotteryPrizes/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        $this->assertTrue(Schema::hasTable('lottery_settings'), 'lottery_settings was not created.');
    }

    /**
     * The plan entitlement, without which `PlanModuleCheck` refuses with 'Permission denied ' --
     * note the trailing space, which is how it is told apart from the handler's own
     * 'Permission denied.' -- before the handler or this guard is reached.
     */
    private function entitle(User $owner): void
    {
        DB::table('user_active_modules')->insert([
            'user_id' => $owner->id,
            'module' => 'LotteryPrizes',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{0: User, 1: Store, 2: int} merchant, store, their own product id */
    private function merchantWithProduct(string $label, float $price = 10.0): array
    {
        [$owner, $store] = $this->makeTenant();
        $this->entitle($owner);

        $productId = (int) DB::table('products')->insertGetId([
            'name' => $label,
            'slug' => 'product-' . $store->id,
            'store_id' => $store->id,
            'created_by' => $owner->id,
            'price' => $price,
            'track_stock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$owner, $store, $productId];
    }

    private function repeaterRow(mixed $productId, float $ticketPrice = 7.0): array
    {
        return [
            'min_ticket' => 1,
            'max_ticket_per_user' => 10,
            'total_ticket' => 100,
            'number_of_winner' => 1,
            'lottery_start_date' => '2026-01-01',
            'lottery_end_date' => '2026-12-31',
            'lottery_start_time' => '00:00',
            'lottery_end_time' => '23:59',
            'lottery_products' => $productId,
            'ticket_price' => $ticketPrice,
            'refund_ticket' => [],
        ];
    }

    private function settingsFor(int $storeId): array
    {
        return DB::table('lottery_settings')->where('store_id', $storeId)
            ->orderBy('id')->pluck('lottery_products')->map('strval')->all();
    }

    /* ---------------------------------------------------------------------------------------- *
     *  PREMISES -- each guards a way this suite could be green while proving nothing
     * ---------------------------------------------------------------------------------------- */

    /**
     * Without this every "no row was written" assertion below is satisfied by the route not
     * existing, by PlanModuleCheck refusing, or by the handler 500ing.
     */
    public function test_premise_the_route_exists_and_a_legitimate_post_writes_a_row(): void
    {
        $this->enableModule();

        $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->getName() === 'lotterysetting.store');
        $this->assertNotNull($route, 'lotterysetting.store is not registered.');
        $this->assertSame('lotteryproduct', $route->uri(),
            'The guard is keyed on `POST lotteryproduct`; the uri changed.');
        $this->assertContains('POST', $route->methods());

        [$merchant, $store, $own] = $this->merchantWithProduct('OWN');
        $this->grantPermission($merchant, self::PERMISSION);
        $merchant->refresh();
        Cache::flush();

        $response = $this->actingAs($merchant)->from('/x')
            ->post('/lotteryproduct', ['lottery_repeater_basic' => [$this->repeaterRow($own, 50.0)]]);

        $response->assertSessionHas('success');
        $this->assertSame([(string) $own], $this->settingsFor($store->id),
            'A merchant must still be able to create a lottery on their own product.');
    }

    /**
     * THE OPERATOR LOCKOUT IS THE HANDLER'S, NOT THIS GUARD'S. Measured, because a reader will
     * otherwise assume the super admin bypasses `isAbleTo`.
     */
    public function test_premise_the_handler_itself_refuses_the_platform_operator(): void
    {
        $this->enableModule();

        $operator = $this->makePlatformAdmin();

        $this->assertSame('super admin', $operator->type);
        $this->assertFalse($operator->isAbleTo(self::PERMISSION),
            'A super admin now passes isAbleTo, so #70 item 3 is fixed and the guard should stop '
            . 'deferring to the handler for the operator.');
    }

    /** The other verbs of the resource are stubs. Read, not assumed. */
    public function test_premise_the_resources_other_verbs_are_still_empty_stubs(): void
    {
        $source = file(base_path(
            'packages/workdo/LotteryPrizes/src/app/Http/Controllers/LotteryPrizesController.php'
        ));

        foreach ([165 => 'update', 175 => 'destroy'] as $line => $method) {
            $this->assertStringContainsString("public function {$method}(", $source[$line - 1],
                "{$method}() is no longer at line {$line}.");
            // The signature is on $line, `{` on $line + 1, the `//` body on $line + 2.
            $this->assertSame('{', trim($source[$line]), "{$method}()'s brace moved.");
            $this->assertSame('//', trim($source[$line + 1]),
                "{$method}() is no longer an empty stub. The guard's TARGETS map must grow to cover "
                . 'it, because it would write the same caller-supplied lottery_products.');
        }
    }

    /* ---------------------------------------------------------------------------------------- *
     *  THE DEFECT
     * ---------------------------------------------------------------------------------------- */

    /** THE HEADLINE. The exact request that reproduced the defect, now refused. */
    public function test_a_merchant_cannot_point_a_lottery_at_another_stores_product(): void
    {
        $this->enableModule();

        [$attacker, $storeA, $ownProduct] = $this->merchantWithProduct('OWN', 10.0);
        [, $storeB, $victimProduct] = $this->merchantWithProduct('VICTIM', 500.0);

        $this->grantPermission($attacker, self::PERMISSION);
        $attacker->refresh();
        Cache::flush();

        $response = $this->actingAs($attacker)->from('/x')->post('/lotteryproduct', [
            'lottery_repeater_basic' => [
                $this->repeaterRow($ownProduct, 50.0),
                $this->repeaterRow($victimProduct, 7.0),
            ],
        ]);

        $response->assertSessionHas('error', 'Permission denied.');

        // THE WHOLE CALL IS REFUSED, not filtered. The handler re-reads the raw input itself, so a
        // guard that authorised a subset would let it act on the whole of it.
        $this->assertSame([], $this->settingsFor($storeA->id),
            'The own-product row must not be written either -- the rule is ALL or nothing.');
        $this->assertSame([], $this->settingsFor($storeB->id));
        $this->assertSame(0, (int) DB::table('lottery_settings')->count());
    }

    /**
     * THE FINANCIAL CHAIN, asserted rather than argued: with the forged row present, the attacker's
     * own storefront resolves a lottery for the victim's product. This is what makes item 5 a money
     * defect and not a cosmetic one.
     *
     * The forged row is inserted DIRECTLY here, bypassing the guard, because the point is what the
     * row enables downstream -- the guard is what stops it existing, and that is the test above.
     */
    public function test_the_forged_row_is_what_makes_the_attackers_storefront_sell_the_victims_product(): void
    {
        $this->enableModule();

        [$attacker, $storeA] = $this->merchantWithProduct('OWN', 10.0);
        [, $storeB, $victimProduct] = $this->merchantWithProduct('VICTIM', 500.0);

        DB::table('lottery_settings')->insert([
            'store_id' => $storeA->id,
            'lottery_products' => $victimProduct,
            'ticket_price' => 7,
            'created_by' => $attacker->id,
            'min_ticket' => 1,
            'max_ticket_per_user' => 10,
            'total_ticket' => 100,
            'number_of_winner' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // This is lottery_form_data:535-539, verbatim in shape: an unscoped Product::find on
        // caller-supplied input, then the setting matched on (store_id, lottery_products).
        $product = \App\Models\Product::find($victimProduct);

        $this->assertNotNull($product, 'Product carries no tenant scope, so a foreign id resolves.');
        $this->assertSame($storeB->id, (int) $product->store_id);

        $resolved = DB::table('lottery_settings')
            ->where('store_id', $storeA->id)
            ->where('lottery_products', $product->id)
            ->first();

        $this->assertNotNull($resolved,
            "The attacker's storefront resolves a lottery for the victim's product. If this is "
            . 'null the chain is broken and item 5 is less severe than filed -- re-measure.');
        $this->assertSame(7.0, (float) $resolved->ticket_price,
            "...at the attacker's own ticket price, on a product priced 500.");
    }

    /**
     * A COERCING VALUE MUST NOT SLIP PAST. `lottery_products` is a STRING column and
     * `lottery_form_data:538` compares it to an int, so the database coerces -- `'<id>abc'` resolves
     * to that product. The guard therefore passes values to the predicate RAW, letting the same
     * coercion decide, rather than filtering on `usableId` and waving the value through as "not an
     * id". That divergence is what made ModuleOrderMutationGuard worthless before it was fixed.
     *
     * NOTE ON ENGINE: SQLite's `whereIn('id', ['2abc'])` does NOT coerce, so this test passes here
     * for a weaker reason than it would on MySQL -- limb 2 refuses because `'2abc'` is not among
     * the ids the caller owns. Either way the request is refused; the MySQL behaviour is the one
     * the trait's own verify-module-order-mutation-id-coercion-on-mysql.php script measured.
     */
    public function test_a_coercing_product_id_is_refused_too(): void
    {
        $this->enableModule();

        [$attacker, $storeA] = $this->merchantWithProduct('OWN', 10.0);
        [, , $victimProduct] = $this->merchantWithProduct('VICTIM', 500.0);

        $this->grantPermission($attacker, self::PERMISSION);
        $attacker->refresh();
        Cache::flush();

        $response = $this->actingAs($attacker)->from('/x')->post('/lotteryproduct', [
            'lottery_repeater_basic' => [$this->repeaterRow($victimProduct . 'abc', 7.0)],
        ]);

        $response->assertSessionHas('error', 'Permission denied.');
        $this->assertSame([], $this->settingsFor($storeA->id));
    }

    /** A product that exists in no store at all is refused: limb 2 requires every value accounted for. */
    public function test_a_nonexistent_product_is_refused(): void
    {
        $this->enableModule();

        [$attacker, $storeA] = $this->merchantWithProduct('OWN', 10.0);
        $this->grantPermission($attacker, self::PERMISSION);
        $attacker->refresh();
        Cache::flush();

        $response = $this->actingAs($attacker)->from('/x')->post('/lotteryproduct', [
            'lottery_repeater_basic' => [$this->repeaterRow(999999, 7.0)],
        ]);

        $response->assertSessionHas('error', 'Permission denied.');
        $this->assertSame([], $this->settingsFor($storeA->id));
    }

    /* ---------------------------------------------------------------------------------------- *
     *  THE OPERATOR
     *
     *  The two sibling guards pass a platform operator straight through, because THEIR handlers let
     *  the operator in and refusing would be the lockout CLAUDE.md warns about. This handler does
     *  not (see the premise test above), so a pass-through here would change nothing observable
     *  today and leave a gap for the moment #70 item 3 is fixed upstream. The pass-through was in
     *  the first version of this guard and removal check R8 moved no test, which is what a control
     *  that buys nothing looks like. It was removed, and these two tests are why R8 now bites.
     *
     *  MEASURED, and this is what makes the gap live rather than hypothetical: a `super admin`
     *  returns FALSE from a bare `isAbleTo`, but GRANTED A LARATRUST ROLE CARRYING THE PERMISSION
     *  IT RETURNS TRUE. So the operator principal that would have slipped through the pass-through
     *  is constructible today, not only after an upstream change.
     * ---------------------------------------------------------------------------------------- */

    public function test_an_operator_holding_the_permission_still_cannot_name_a_foreign_product(): void
    {
        $this->enableModule();

        [, $storeA] = $this->merchantWithProduct('MERCHANT A', 10.0);
        [, , $foreignProduct] = $this->merchantWithProduct('MERCHANT B', 500.0);

        $operator = $this->makePlatformAdmin();
        $operator->current_store = $storeA->id;
        $operator->save();
        $this->entitle($operator);
        $this->grantPermission($operator, self::PERMISSION);
        $operator->refresh();
        Cache::flush();

        $this->assertTrue($operator->isAbleTo(self::PERMISSION),
            'The operator must actually hold the permission, or this test measures item 3\'s '
            . 'lockout instead of the guard.');

        $response = $this->actingAs($operator)->from('/x')->post('/lotteryproduct', [
            'lottery_repeater_basic' => [$this->repeaterRow($foreignProduct, 7.0)],
        ]);

        $response->assertSessionHas('error', 'Permission denied.');
        $this->assertSame(0, (int) DB::table('lottery_settings')->count(),
            'The operator is not exempt from the ownership rule. If this row exists, the operator '
            . 'pass-through is back and the gap R8 found is open again.');
    }

    public function test_an_operator_holding_the_permission_can_set_up_a_lottery_in_the_store_it_acts_in(): void
    {
        $this->enableModule();

        [, $storeA, $productA] = $this->merchantWithProduct('MERCHANT A', 10.0);

        $operator = $this->makePlatformAdmin();
        $operator->current_store = $storeA->id;
        $operator->save();
        $this->entitle($operator);
        $this->grantPermission($operator, self::PERMISSION);
        $operator->refresh();
        Cache::flush();

        $response = $this->actingAs($operator)->from('/x')->post('/lotteryproduct', [
            'lottery_repeater_basic' => [$this->repeaterRow($productA, 5.0)],
        ]);

        $response->assertSessionHas('success');
        $this->assertSame([(string) $productA], $this->settingsFor($storeA->id),
            'Refusing the operator a product of the store they are acting in would be a lockout.');
    }

    /* ---------------------------------------------------------------------------------------- *
     *  PRESERVING WHAT WORKED
     * ---------------------------------------------------------------------------------------- */

    /** Several of the caller's own products in one post. */
    public function test_a_merchant_can_still_create_several_lotteries_on_their_own_products(): void
    {
        $this->enableModule();

        [$merchant, $store, $first] = $this->merchantWithProduct('FIRST', 10.0);
        $second = (int) DB::table('products')->insertGetId([
            'name' => 'SECOND', 'slug' => 'second-' . $store->id, 'store_id' => $store->id,
            'created_by' => $merchant->id, 'price' => 20, 'track_stock' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->grantPermission($merchant, self::PERMISSION);
        $merchant->refresh();
        Cache::flush();

        $response = $this->actingAs($merchant)->from('/x')->post('/lotteryproduct', [
            'lottery_repeater_basic' => [$this->repeaterRow($first, 5.0), $this->repeaterRow($second, 9.0)],
        ]);

        $response->assertSessionHas('success');
        $this->assertSame([(string) $first, (string) $second], $this->settingsFor($store->id));
    }

    /**
     * THE CLEAR-ALL CASE, which the guard deliberately allows. This is the regression
     * `LotteryRefundOwnershipGuard` shipped and withdrew, so it has its own test.
     */
    public function test_an_empty_repeater_still_clears_the_merchants_own_lotteries(): void
    {
        $this->enableModule();

        [$merchant, $store, $own] = $this->merchantWithProduct('OWN', 10.0);
        $this->grantPermission($merchant, self::PERMISSION);
        $merchant->refresh();
        Cache::flush();

        $this->actingAs($merchant)->from('/x')->post('/lotteryproduct',
            ['lottery_repeater_basic' => [$this->repeaterRow($own, 5.0)]]);
        $this->assertSame([(string) $own], $this->settingsFor($store->id));

        $response = $this->actingAs($merchant)->from('/x')
            ->post('/lotteryproduct', ['lottery_repeater_basic' => []]);

        $response->assertSessionMissing('error');
        $this->assertSame([], $this->settingsFor($store->id),
            'An empty repeater must still delete the merchant\'s own rows. Refusing it would take '
            . 'a working feature away to close nothing.');
    }

    /**
     * ANOTHER MERCHANT'S ROWS ARE UNTOUCHED BY A CLEAR-ALL. `:107` scopes the existing rows by
     * getCurrentStore(), so this is the handler's own behaviour -- asserted because the guard's
     * decision to allow `[]` through rests on it.
     */
    public function test_a_clear_all_cannot_delete_another_stores_lotteries(): void
    {
        $this->enableModule();

        [$attacker] = $this->merchantWithProduct('OWN', 10.0);
        [$victim, $storeB, $victimProduct] = $this->merchantWithProduct('VICTIM', 500.0);

        DB::table('lottery_settings')->insert([
            'store_id' => $storeB->id, 'lottery_products' => $victimProduct, 'ticket_price' => 99,
            'created_by' => $victim->id, 'min_ticket' => 1, 'max_ticket_per_user' => 1,
            'total_ticket' => 1, 'number_of_winner' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->grantPermission($attacker, self::PERMISSION);
        $attacker->refresh();
        Cache::flush();

        $this->actingAs($attacker)->from('/x')
            ->post('/lotteryproduct', ['lottery_repeater_basic' => []]);

        $this->assertSame([(string) $victimProduct], $this->settingsFor($storeB->id),
            "A clear-all must not reach another store's rows.");
    }

    /* ---------------------------------------------------------------------------------------- *
     *  THE GUARD'S OWN SHAPE
     * ---------------------------------------------------------------------------------------- */

    /**
     * INERT ON THE SAME URI UNDER A DIFFERENT METHOD. `TARGETS` is keyed on `METHOD . ' ' . uri`, so
     * `GET lotteryproduct` -- the settings screen -- must not be inspected.
     *
     * THIS TEST USED `get()` AND COULD NOT FAIL FOR THE REASON ITS NAME GAVE. `deny()` aborts 403
     * ONLY when `expectsJson()`, and a plain `get()` is not a JSON request, so a guard refusal on
     * that route would have been a 302 and `assertNotSame(403, ...)` would have stayed green under
     * a uri-only key -- verified by actually re-keying the guard and re-running it. `getJson()`
     * makes `expectsJson()` true, so a refusal IS the 403 this asserts against. Measured: 302 today,
     * 403 under a uri-only key.
     *
     * (Its docblock also used to promise "a sibling module's resource store", which it never
     * exercised -- the body requests this same module's resource. The suite has no cross-module
     * route test; the claim was removed rather than left advertising coverage that does not exist.)
     */
    public function test_the_guard_is_inert_on_the_same_uri_under_a_different_method(): void
    {
        $this->enableModule();

        [$merchant, $store] = $this->merchantWithProduct('OWN', 10.0);
        $this->grantPermission($merchant, self::PERMISSION);
        $merchant->refresh();
        Cache::flush();

        $response = $this->actingAs($merchant)->getJson('/lotteryproduct');

        $this->assertNotSame(403, $response->getStatusCode(),
            'A 403 on this route means the guard ran on a GET. It is keyed on METHOD + uri, so a '
            . "refusal here would be deny()'s JSON limb -- which is exactly what this detects.");
        $this->assertSame([], $this->settingsFor($store->id));
    }

    /** A non-array repeater is refused rather than 500ing in the handler's foreach. */
    public function test_a_non_array_repeater_is_refused_instead_of_crashing(): void
    {
        $this->enableModule();

        [$merchant, $store] = $this->merchantWithProduct('OWN', 10.0);
        $this->grantPermission($merchant, self::PERMISSION);
        $merchant->refresh();
        Cache::flush();

        foreach ([[], ['lottery_repeater_basic' => 'not-an-array']] as $payload) {
            $response = $this->actingAs($merchant)->from('/x')->post('/lotteryproduct', $payload);

            // NOT 'Permission denied.' -- this caller has the permission. The empty payload is what
            // the shipped form sends when every row is deleted, and telling a merchant they lack
            // permission for a missing field was a live defect the per-reason messages fixed.
            $response->assertSessionHas('error', 'Add at least one lottery setting.');
            $this->assertNotSame(500, $response->getStatusCode(),
                'store():95 does foreach() on this value, so a non-array is a 500 without the '
                . 'guard. Refusing is strictly better.');
        }

        $this->assertSame([], $this->settingsFor($store->id));
    }

    /** A repeater with rows but no usable product id is refused. */
    public function test_a_repeater_row_with_no_product_is_refused(): void
    {
        $this->enableModule();

        [$merchant, $store] = $this->merchantWithProduct('OWN', 10.0);
        $this->grantPermission($merchant, self::PERMISSION);
        $merchant->refresh();
        Cache::flush();

        $response = $this->actingAs($merchant)->from('/x')->post('/lotteryproduct', [
            'lottery_repeater_basic' => [$this->repeaterRow('', 7.0)],
        ]);

        // A MISSING FIELD IS NOT A PERMISSION FAILURE, and this is the live path: the
        // `lottery_products` select is the ONLY control in the repeater row without `required` and
        // renders `<option value="">` selected, so a merchant who forgets the product reaches here
        // by ordinary mistake. `ConvertEmptyStringsToNull` is global, so the value arrives as null.
        $response->assertSessionHas('error', 'Select a product for each lottery setting.');
        $this->assertSame([], $this->settingsFor($store->id));
    }

    /**
     * THE WIDENED LIMB. A value the guard cannot read is REFUSED, not skipped -- and the case that
     * forced the change is reachable from the shipped form: fill one row, leave a second row's
     * product blank.
     *
     * MEASURED against the first form of the loop, which skipped such a value: the predicate was
     * satisfied by row one alone and the handler wrote a NULL-product lottery the guard had never
     * examined. A NULL `lottery_products` is invisible to every `where('lottery_products', ...)`
     * predicate, so that row can never sell a ticket -- a silent junk write reported as success.
     */
    public function test_a_second_row_with_a_blank_product_refuses_the_whole_post(): void
    {
        $this->enableModule();

        [$merchant, $store, $own] = $this->merchantWithProduct('OWN', 10.0);
        $this->grantPermission($merchant, self::PERMISSION);
        $merchant->refresh();
        Cache::flush();

        $response = $this->actingAs($merchant)->from('/x')->post('/lotteryproduct', [
            'lottery_repeater_basic' => [
                $this->repeaterRow($own, 5.0),      // valid
                $this->repeaterRow('', 7.0),        // blank select -> null
            ],
        ]);

        $response->assertSessionHas('error', 'Select a product for each lottery setting.');
        $this->assertSame([], $this->settingsFor($store->id),
            'The VALID row must not be written either -- the rule is ALL or nothing, and a skip '
            . 'would have let the blank row through on the valid row\'s authority.');
    }

    /**
     * The same widening covers a value that is not a scalar at all. Measured against the first form
     * of the loop: `lottery_repeater_basic[1][lottery_products][]=<id>` was skipped, row one was
     * written, and the handler then 500'd binding an array into the insert.
     */
    public function test_an_array_valued_product_refuses_the_whole_post(): void
    {
        $this->enableModule();

        [$merchant, $store, $own] = $this->merchantWithProduct('OWN', 10.0);
        $this->grantPermission($merchant, self::PERMISSION);
        $merchant->refresh();
        Cache::flush();

        $response = $this->actingAs($merchant)->from('/x')->post('/lotteryproduct', [
            'lottery_repeater_basic' => [
                $this->repeaterRow($own, 5.0),
                $this->repeaterRow([$own], 7.0),
            ],
        ]);

        $response->assertSessionHas('error', 'Select a product for each lottery setting.');
        $this->assertNotSame(500, $response->getStatusCode(),
            'Without the refusal this was a 500 AFTER the first row was written.');
        $this->assertSame([], $this->settingsFor($store->id));
    }

    /** A merchant without the permission is refused, and no row is written. */
    public function test_a_merchant_without_the_permission_writes_nothing(): void
    {
        $this->enableModule();

        [$merchant, $store, $own] = $this->merchantWithProduct('OWN', 10.0);
        Cache::flush();

        $this->assertFalse($merchant->isAbleTo(self::PERMISSION));

        $response = $this->actingAs($merchant)->from('/x')->post('/lotteryproduct', [
            'lottery_repeater_basic' => [$this->repeaterRow($own, 5.0)],
        ]);

        $response->assertSessionHas('error');
        $this->assertSame([], $this->settingsFor($store->id));
    }
    /* ---------------------------------------------------------------------------------------- *
     *  A VENDORED DEFECT THIS SUITE'S OWN FIXTURE WAS HIDING
     * ---------------------------------------------------------------------------------------- */

    /**
     * TICKING "REFUND TICKET OPTION" IS A 500, FOR EVERY MERCHANT, ON A LEGITIMATE OWN-PRODUCT SAVE.
     *
     * `index.blade.php:46` and `:107` are `<input type="checkbox" name="refund_ticket">` inside a
     * `[data-repeater-item]`, so `public/js/repeater.js` renames them to
     * `lottery_repeater_basic[i][refund_ticket]` at runtime and a TICKED checkbox posts the SCALAR
     * `'on'`. `store():115` then does `implode(',', $lotterySettingData['refund_ticket'] ?? [])`,
     * and `implode()` on a string is a TypeError in PHP 8 -- measured:
     * "implode(): Argument #2 ($array) must be of type ?array, string given".
     *
     * MEASURED END TO END through the real route, with a product the merchant owns:
     *   refund_ticket = 'on' (ticked)               -> HTTP 500, no row written
     *   refund_ticket = []   (this suite's fixture) -> HTTP 302, row written
     *
     * WHY IT IS PINNED HERE. `repeaterRow()` passes `'refund_ticket' => []`, which is what an
     * UNTICKED checkbox effectively produces (the key is absent and `?? []` covers it). So every
     * other test in this file drives the one shape that works, and the shape a merchant produces by
     * USING the feature was never exercised. That is a fixture-shaped blind spot, and it was found
     * by an adversarial review of this suite rather than by the suite.
     *
     * NOT THIS GUARD'S TO FIX: it is inside `packages/workdo/`, it is reached on a legitimate
     * request the guard correctly allows, and no ownership predicate bears on it. A host-side
     * FormRequest or middleware normalising `refund_ticket` to an array would be the only legal
     * repair. Raised against #70; pinned so that when it is fixed this test goes red deliberately.
     */
    public function test_pins_a_vendored_defect_ticking_refund_ticket_is_a_500(): void
    {
        $this->enableModule();

        [$merchant, $store, $own] = $this->merchantWithProduct('OWN', 10.0);
        $this->grantPermission($merchant, self::PERMISSION);
        $merchant->refresh();
        Cache::flush();

        $this->assertStringContainsString('name="refund_ticket"', file_get_contents(base_path(
            'packages/workdo/LotteryPrizes/src/resources/views/admin/lottery/index.blade.php'
        )), 'The checkbox is no longer a bare name renamed by the repeater; re-measure.');

        $ticked = $this->repeaterRow($own, 5.0);
        $ticked['refund_ticket'] = 'on';

        $response = $this->actingAs($merchant)->from('/x')
            ->post('/lotteryproduct', ['lottery_repeater_basic' => [$ticked]]);

        $this->assertSame(500, $response->getStatusCode(),
            'PINNING A VENDORED DEFECT: implode() on the scalar "on" at store():115. When this '
            . 'stops being a 500 the upstream fix landed -- update this test, do not relax it.');
        $this->assertSame([], $this->settingsFor($store->id));

        // THE CONTROL: the only difference is the checkbox value.
        $response = $this->actingAs($merchant)->from('/x')
            ->post('/lotteryproduct', ['lottery_repeater_basic' => [$this->repeaterRow($own, 5.0)]]);

        $response->assertSessionHas('success');
        $this->assertSame([(string) $own], $this->settingsFor($store->id),
            'The same request with the checkbox unticked must succeed, or the 500 above is not '
            . 'attributable to refund_ticket.');
    }
}
