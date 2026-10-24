<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-INV-18 / #64 -- REPRODUCTION FIRST. `POST lotteryresult` forges PAID host orders from another
 * tenant's ticket codes, and plants rows INSIDE the victim's tenant.
 *
 * Written against UNFIXED code and expected to FAIL.
 *
 * THE REMOVAL CHECKS, AS MEASURED (scripted, each control deleted alone, suite re-run, file
 * restored byte-for-byte). TWO OF THESE TESTS EXIST BECAUSE A CHECK FOUND THEM MISSING:
 *
 *   R1  the bootstrap/app.php registration ......... 8 of 14 fail
 *   R2  the ALL rule (filter instead of refuse) ....  1 fail
 *   R3  the store predicate in the set query .......  5 fail
 *   R4  the empty-set refusal ......................  1 fail
 *   R5  the permission limb ........................  1 fail
 *   R6  operator-first ordering ....................  1 fail
 *   R8  method+uri keying ..........................  1 fail
 *   R9  the inertness early return .................  1 fail
 *   R10 the uniform refusal body ...................  1 fail -- only after
 *                                                     test_a_permission_refusal_and_an_ownership_refusal...
 *                                                     was ADDED; the oracle test compares two
 *                                                     denials that share a reason, so it could not
 *                                                     see the body disclosing one
 *   R11 the permission-row existence check .........  1 fail
 *   R7  the no-codes refusal ....................... GREEN -- SUBSUMED
 *   R12 the empty-store refusal in the set predicate GREEN -- SUBSUMED
 *
 * BOTH GREENS ARE SUBSUMPTIONS, LABELLED RATHER THAN COUNTED. Removing R7 leaves
 * `explode(',', '')` producing `['']`, which the trait filters to the empty set, which R4 then
 * refuses. Removing R12 leaves `->where('store_id', null)`, which matches no row in SQL, so
 * `array_diff` is non-empty and the ALL rule refuses. Both explicit checks are kept: each names
 * its case in the log instead of mislabelling it, and neither depends on the other's behaviour
 * surviving a rewrite.
 *
 * All assertions read the raw row via DB::table(): `App\Models\Order` and `App\Models\Product` are
 * both Cachable, and an Eloquent read in a test can serve a mutated in-memory instance -- that
 * produced a measured false reproduction during P1-INV-04.
 */
class LotteryResultOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

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

        // RefreshDatabase runs database/migrations only, and
        // SecurityTestCase::satisfyUpdaterGate() has already recorded every module migration name
        // as run -- so `artisan migrate` would create nothing and report success. The migration
        // objects are invoked directly; each `up()` is upstream-guarded by `if (! hasTable)`.
        foreach (glob(base_path('packages/workdo/LotteryPrizes/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        foreach (['purchase_tickets', 'lottery_settings', 'lottery_customers'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Module table `{$table}` was not created.");
        }
    }

    /**
     * The plan entitlement, without which `PlanModuleCheck` refuses before the handler runs.
     *
     * Entitlement is a `user_active_modules` row keyed on the OWNER
     * (`activated_modules_for_owner`, app/Helper/helper.php:1360-1384), memoised as a container
     * `scoped()` binding -- so it has to exist before the request that reads it.
     */
    private function entitle(\App\Models\User $owner): void
    {
        DB::table('user_active_modules')->insert([
            'user_id' => $owner->id,
            'module' => 'LotteryPrizes',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertContains('LotteryPrizes', ActivatedModule($owner->id),
            'The entitlement fixture did not take, so PlanModuleCheck would refuse every request '
            . 'below and each assertion would pass without reaching the handler.');
    }

    /**
     * THE ATTACKER'S OWN EMAIL-TEMPLATE ROWS, WITHOUT WHICH NOTHING IS WRITTEN AT ALL.
     *
     * THIS IS WHY THE FIRST VERSION OF THIS FILE "PASSED" AGAINST UNFIXED CODE. Both the host
     * `Order` write and the `LotteryCustomer` row sit inside `if ($UserEmailTemplate)` at
     * LotteryResultController.php:103 -- so with no such row the loop runs (the `sleep(1)` per
     * ticket is visible in the test duration) and writes nothing. Two headline cases reported
     * green while the defect was untouched.
     *
     * Both rows are the CALLER'S OWN: an `email_templates` row named 'Lottery Win' with
     * `created_by = $user->id`, and a `user_email_templates` row joining it to the same user. So
     * this is not a barrier an attacker has to defeat -- it is their own notification setup, which
     * a merchant using the lottery feature would have. It is a precondition, and it is stated
     * rather than assumed away.
     */
    private function giveCallerTheLotteryWinTemplate(\App\Models\User $user): void
    {
        $templateId = (int) DB::table('email_templates')->insertGetId([
            'name' => 'Lottery Win',
            'from' => 'lottery',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_email_templates')->insert([
            'template_id' => $templateId,
            'user_id' => $user->id,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * AND THE LANGUAGE ROW, which is the SECOND thing that made this file report green
         * against unfixed code. `LotteryPrizesController::sendOwnerEmailTemplate:739` does
         * `$content->from = $template->from` on the result of an `EmailTemplateLang` lookup with
         * NO null guard, so without this row the request is an uncaught
         * `Error: Attempt to assign property "from" on null` -- HTTP 500, before any write.
         *
         * That is a FIXTURE gap, not a shipped protection: a real install has these rows from the
         * email-template seeding, and a legitimate own-store draw would hit the same crash without
         * them. `content` is left EMPTY on purpose -- `:740`'s `if (!empty($content->content))`
         * then skips the actual mail send, so these tests never attempt outbound mail while still
         * reaching the writes that follow it.
         */
        DB::table('email_template_langs')->insert([
            'parent_id' => $templateId,
            'language' => 'en',
            'subject' => 'Lottery Win',
            'content' => '',
            'is_default' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * AND A 'Lottery Fail' TEMPLATE, because without one EVERY SUCCESSFUL DRAW 500s.
         * `LotteryResultController:156-157` does
         * `UserEmailTemplate::where('template_id', $FailTemplate['id'])` on a null
         * `EmailTemplate::where('name','Lottery Fail')->where('created_by', $user->id)->first()`
         * -- an array offset on null, so an `ErrorException` AFTER the order and
         * `lottery_customers` rows have been written.
         *
         * This was found the hard way: the positive test below asserted only
         * `assertNotSame(403, ...)` plus the row, so it was PASSING THROUGH AN HTTP 500 -- exactly
         * the trap CLAUDE.md names, in my own test. The positive test now asserts the status is
         * not a 500 as well, which is what makes this fixture row necessary rather than optional.
         *
         * The row is a real-install artefact, not a workaround: a merchant using the lottery
         * feature has both templates. The underlying null dereference is a pre-existing
         * availability bug in vendored code and is raised separately.
         */
        $failId = (int) DB::table('email_templates')->insertGetId([
            'name' => 'Lottery Fail',
            'from' => 'lottery',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_email_templates')->insert([
            'template_id' => $failId,
            'user_id' => $user->id,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('email_template_langs')->insert([
            'parent_id' => $failId,
            'language' => 'en',
            'subject' => 'Lottery Fail',
            'content' => '',
            'is_default' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(Store $store): int
    {
        return (int) DB::table('products')->insertGetId([
            'name' => 'VICTIM-LOTTERY-PRODUCT',
            'slug' => 'victim-lottery-' . uniqid(),
            'store_id' => $store->id,
            'price' => 4242,
            'track_stock' => 0,
            'created_by' => (int) $store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A lottery setting and one purchased ticket, both owned by $store. */
    private function ticket(Store $store, string $code): array
    {
        $productId = $this->product($store);

        $lotteryId = (int) DB::table('lottery_settings')->insertGetId([
            'lottery_products' => $productId,
            'ticket_price' => 777,
            'number_of_winner' => 1,
            'created_by' => (int) $store->created_by,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketId = (int) DB::table('purchase_tickets')->insertGetId([
            'lottery_id' => $lotteryId,
            'lottery_code' => $code,
            'customer_id' => 9001,
            'product_id' => $productId,
            'amount' => 777,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['product' => $productId, 'lottery' => $lotteryId, 'ticket' => $ticketId];
    }

    /**
     * THE HEADLINE: another tenant's ticket code produces a forged PAID host order in the
     * attacker's store, carrying the victim's product id and ticket price.
     */
    public function test_a_merchant_cannot_draw_another_stores_ticket_code(): void
    {
        $this->enableModule();

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Lottery Winner');
        $this->entitle($merchantA);
        $this->giveCallerTheLotteryWinTemplate($merchantA);

        $victim = $this->ticket($storeB, 'VICTIM-CODE-0001');

        $ordersBefore = DB::table('orders')->count();

        /*
         * NOTE ON THE 302 + "already been used" THIS MAY ANSWER WITH. The handler writes its
         * `LotteryCustomer` row and its host `Order` BEFORE a later line in the same request
         * throws, so on a retry the replay guard at :79 sees the row it just wrote. The response
         * is therefore not the measurement -- THE ROWS ARE. Both assertions below read the raw
         * tables.
         */
        $response = $this->actingAs($merchantA->fresh())
            ->post('/lotteryresult', ['selected_codes' => 'VICTIM-CODE-0001']);

        $forged = DB::table('orders')
            ->where('lottery_code', 'VICTIM-CODE-0001')
            ->get();

        // SELF-SUFFICIENCY: without this, both row assertions would be satisfied by the route
        // simply NOT EXISTING -- the module-prefix version of the failure mode CLAUDE.md records.
        // The positive test in this class protects it indirectly; one line makes it direct.
        $this->assertNotSame(404, $response->status(),
            'POST /lotteryresult does not exist, so the row assertion below proves nothing.');

        $this->assertSame($ordersBefore, DB::table('orders')->count(),
            'A host `orders` row was created from another tenant\'s ticket code. Rows: '
            . $forged->toJson() . ' (status ' . $response->status() . ')');

        $this->assertCount(0, $forged,
            'A forged PAID order exists carrying another store\'s product. payment_status=Paid is '
            . 'what OrderObserver::updated reacts to.');
    }

    /**
     * THE INVERSE DIRECTION, which is the unusual half: the handler writes
     * `LotteryCustomer` rows with `store_id => $ticket->store_id` -- i.e. INTO the victim's
     * tenant -- through `::insert()`, which bypasses model events and every global scope.
     */
    public function test_a_merchant_cannot_plant_lottery_customer_rows_in_another_tenant(): void
    {
        $this->enableModule();

        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Lottery Winner');
        $this->entitle($merchantA);
        $this->giveCallerTheLotteryWinTemplate($merchantA);

        $this->ticket($storeB, 'VICTIM-CODE-0002');

        $response = $this->actingAs($merchantA->fresh())
            ->post('/lotteryresult', ['selected_codes' => 'VICTIM-CODE-0002']);

        $this->assertNotSame(404, $response->status(),
            'POST /lotteryresult does not exist, so the row assertion below proves nothing.');

        $planted = DB::table('lottery_customers')->where('store_id', $storeB->id)->get();

        $this->assertCount(0, $planted,
            'Rows were planted INSIDE store ' . $storeB->id . "'s tenant by a caller from another "
            . 'store, via LotteryCustomer::insert() -- which bypasses model events and every '
            . 'global scope. Rows: ' . $planted->toJson());
    }

    /**
     * THE CROSS-TENANT EXISTENCE ORACLE IS CLOSED, which is the half of the replay guard a
     * host-side control can reach.
     *
     * `:79` resolves `LotteryCustomer::whereIn('lottery_code', …)` with no store predicate and
     * returns an error that NAMES the codes it matched. Pre-fix that was an oracle over every
     * tenant's code space: probe a code, and the message tells you whether some tenant has drawn
     * it. The guard refuses a code the caller does not own BEFORE the handler runs, so the probe
     * returns the same uniform refusal as every other denial and names nothing.
     */
    public function test_a_code_the_caller_does_not_own_cannot_be_used_as_an_existence_oracle(): void
    {
        $this->enableModule();

        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Lottery Winner');
        $this->entitle($merchantA);

        $this->ticket($storeB, 'VICTIM-CODE-0003');

        // A code another tenant OWNS AND HAS DRAWN -- the state that made the handler speak.
        DB::table('lottery_customers')->insert([
            'lottery_id' => 0,
            'ticket_id' => 0,
            'customer_id' => 1,
            'lottery_code' => 'VICTIM-CODE-0003',
            'store_id' => $storeB->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $drawn = $this->actingAs($merchantA->fresh())
            ->postJson('/lotteryresult', ['selected_codes' => 'VICTIM-CODE-0003']);
        $absent = $this->actingAs($merchantA->fresh())
            ->postJson('/lotteryresult', ['selected_codes' => 'NEVER-EXISTED-404']);

        $this->assertSame(403, $drawn->status());
        $this->assertSame(403, $absent->status());
        $this->assertStringNotContainsString('VICTIM-CODE-0003', (string) $drawn->getContent(),
            'The refusal names the probed code, which is the oracle this guard exists to close.');
        $this->assertSame(
            (string) $drawn->getContent(),
            (string) $absent->getContent(),
            'A drawn foreign code and a code that never existed answer differently, so the guard '
            . 'is itself an existence oracle over every tenant\'s code space.'
        );
    }

    /**
     * A KNOWN LIMITATION, PINNED RATHER THAN HIDDEN -- and this test asserts the CURRENT
     * behaviour, not a control.
     *
     * If two tenants happen to own the SAME code string and one has drawn it, the other is still
     * refused — by the handler's own global replay guard at `:79`, not by this guard. The guard
     * ALLOWS the request, because the caller does own the code; the handler then refuses it. A
     * cross-tenant denial of service over the code space, and closing it needs a change inside
     * vendored code this repository may not edit.
     *
     * The test exists so that the limitation is measured rather than asserted, and so that an
     * upstream fix shows up here as a FAILURE prompting this docblock to be re-read. Tracked on
     * #64.
     */
    public function test_KNOWN_LIMITATION_an_identical_code_string_in_another_tenant_still_blocks_the_draw(): void
    {
        $this->enableModule();

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Lottery Winner');
        $this->entitle($merchantA);
        $this->giveCallerTheLotteryWinTemplate($merchantA);

        // Store A OWNS this code and has not drawn it.
        $this->ticket($storeA, 'SHARED-CODE-9');

        // Store B has recorded a winner under the same code STRING.
        DB::table('lottery_customers')->insert([
            'lottery_id' => 0,
            'ticket_id' => 0,
            'customer_id' => 1,
            'lottery_code' => 'SHARED-CODE-9',
            'store_id' => $storeB->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($merchantA->fresh())
            ->post('/lotteryresult', ['selected_codes' => 'SHARED-CODE-9']);

        // The guard let it through -- the caller owns the code -- and no order was written.
        $this->assertNotSame(403, $response->status(),
            'The guard refused a code the caller DOES own. That is not the limitation this test '
            . 'pins; it is a regression in the guard.');
        $this->assertSame(0, DB::table('orders')->where('lottery_code', 'SHARED-CODE-9')->count(),
            'The handler drew a code another tenant had already drawn.');
        $this->assertStringContainsString('already been used', (string) session('error'),
            'The handler no longer refuses on another tenant\'s identical code string. If upstream '
            . 'fixed the global replay guard at LotteryResultController:79, this limitation is '
            . 'gone -- delete this test and update #64.');
    }

    /** A code matching no ticket must not 500 on an undefined variable. */
    public function test_a_code_matching_no_ticket_does_not_crash(): void
    {
        $this->enableModule();

        [$merchantA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Lottery Winner');
        $this->entitle($merchantA);

        $response = $this->actingAs($merchantA->fresh())
            ->post('/lotteryresult', ['selected_codes' => 'NO-SUCH-CODE-12345']);

        $this->assertNotSame(500, $response->status(),
            '`$lottery_setting` is used after the foreach at :151, so a code matching no ticket '
            . 'dereferences an undefined variable. Body: '
            . substr(strip_tags((string) $response->getContent()), 0, 300));
    }

    // =========================================================================================
    //  THE SET RULE, THE POSITIVE, AND THE LIMBS
    // =========================================================================================

    /**
     * A MIXED LIST REFUSES THE WHOLE CALL. This is the load-bearing case for the set predicate.
     *
     * The handler re-reads `selected_codes` itself, so a guard that FILTERED the list to the codes
     * the caller owns would authorise a subset while the handler acted on the whole of it -- the
     * same divergence as authorising one row while the handler mutates another, which
     * ModuleOrderMutationGuard shipped once and had to fix. ALL or nothing.
     *
     * The caller's own code is in the list and is genuinely theirs, so every other limb passes.
     * Only the ALL rule refuses this.
     */
    public function test_a_list_mixing_an_owned_code_with_a_foreign_one_refuses_the_whole_call(): void
    {
        $this->enableModule();

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Lottery Winner');
        $this->entitle($merchantA);
        $this->giveCallerTheLotteryWinTemplate($merchantA);

        $this->ticket($storeA, 'MINE-0001');
        $this->ticket($storeB, 'THEIRS-0001');

        $response = $this->actingAs($merchantA->fresh())
            ->post('/lotteryresult', ['selected_codes' => 'MINE-0001,THEIRS-0001']);

        $this->assertSame(403, $response->status(),
            'A list mixing an owned code with a foreign one was allowed. The handler acts on the '
            . 'WHOLE list, so partial authorisation is no authorisation.');
        $this->assertSame(0, DB::table('orders')->count(),
            'The caller\'s OWN code was drawn out of a refused call, so the guard filtered where '
            . 'it should have refused.');
    }

    /** THE POSITIVE: an own-store draw still works end to end. */
    public function test_a_merchant_can_still_draw_their_own_ticket_code(): void
    {
        $this->enableModule();

        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Lottery Winner');
        $this->entitle($merchantA);
        $this->giveCallerTheLotteryWinTemplate($merchantA);

        $own = $this->ticket($storeA, 'MY-OWN-CODE-1');

        $response = $this->actingAs($merchantA->fresh())
            ->post('/lotteryresult', ['selected_codes' => 'MY-OWN-CODE-1']);

        $order = DB::table('orders')->where('lottery_code', 'MY-OWN-CODE-1')->first();

        $this->assertNotNull($order,
            'The guard refused the owner their own draw. Status ' . $response->status()
            . ', error: ' . var_export(session('error'), true));
        $this->assertSame((int) $storeA->id, (int) $order->store_id);
        $this->assertSame(1, DB::table('lottery_customers')
            ->where('lottery_code', 'MY-OWN-CODE-1')->where('store_id', $storeA->id)->count());
        $this->assertNotSame(403, $response->status());

        /*
         * AND NOT A 500 EITHER. This assertion is here because its absence hid a real bug: the
         * test asserted only `assertNotSame(403)` plus the row, and 500 != 403, so it passed while
         * every successful draw was crashing at `:157` AFTER writing its rows. A positive test
         * that tolerates a 500 is the trap CLAUDE.md names, and this file had it.
         */
        $this->assertNotSame(500, $response->status(),
            'The draw wrote its rows and then crashed. Body: '
            . substr(strip_tags((string) $response->getContent()), 0, 200));
    }

    /**
     * A MERCHANT HOLDING NO LOTTERY PERMISSION CANNOT DRAW THEIR OWN CODE.
     *
     * Deliberately the caller's OWN code, so ownership cannot be the refuser. This is also where
     * the guard is STRICTER than the handler: `store()` checks no permission at all, so before
     * this guard an entitled merchant's staff could run draws with no permission. A guard that
     * dropped the check because the handler had none would be a regression.
     */
    public function test_a_merchant_without_the_lottery_permission_cannot_draw_their_own_code(): void
    {
        $this->enableModule();

        \App\Models\Permission::firstOrCreate(
            ['name' => 'Manage Lottery Winner'],
            ['display_name' => 'Manage Lottery Winner']
        );

        [$merchantA, $storeA] = $this->makeTenant();
        // Deliberately NO grantPermission call.
        $this->entitle($merchantA);
        $this->giveCallerTheLotteryWinTemplate($merchantA);

        $this->ticket($storeA, 'MY-OWN-CODE-2');

        $response = $this->actingAs($merchantA->fresh())
            ->post('/lotteryresult', ['selected_codes' => 'MY-OWN-CODE-2']);

        $this->assertSame(403, $response->status());
        $this->assertSame(0, DB::table('orders')->count(),
            'A merchant holding no lottery permission ran a draw.');
    }

    /**
     * WITH NO PERMISSION ROW THE LIMB STOPS ENFORCING -- AND OWNERSHIP STILL REFUSES.
     *
     * The lockout guard, asserted in both directions as on both siblings.
     */
    public function test_with_no_permission_row_the_limb_degrades_to_ownership_and_not_to_open(): void
    {
        $this->enableModule();

        $this->assertFalse(
            DB::table('permissions')->where('name', 'Manage Lottery Winner')->exists(),
            'The permission row exists in this fixture, so this test no longer exercises the '
            . 'no-row branch.'
        );

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->entitle($merchantA);
        $this->giveCallerTheLotteryWinTemplate($merchantA);

        $this->ticket($storeA, 'OWN-NOPERM-1');
        $this->ticket($storeB, 'FOREIGN-NOPERM-1');

        $allowed = $this->actingAs($merchantA->fresh())
            ->post('/lotteryresult', ['selected_codes' => 'OWN-NOPERM-1']);
        $refused = $this->actingAs($merchantA->fresh())
            ->postJson('/lotteryresult', ['selected_codes' => 'FOREIGN-NOPERM-1']);

        $this->assertNotSame(403, $allowed->status(),
            'With no permission row the limb must not fire, or an install whose module was enabled '
            . 'before its seeder ran is locked out of its own draws forever.');
        $this->assertSame(403, $refused->status(),
            'The no-row branch skipped the OWNERSHIP limb too. That would be a bypass, not a '
            . 'lockout guard.');
    }

    /** THE PLATFORM OPERATOR is not refused, even where no role holds the permission. */
    public function test_the_platform_operator_is_not_refused(): void
    {
        $this->enableModule();

        \App\Models\Permission::firstOrCreate(
            ['name' => 'Manage Lottery Winner'],
            ['display_name' => 'Manage Lottery Winner']
        );

        $operator = $this->makePlatformAdmin();
        [, $storeB] = $this->makeTenant();
        $operator->current_store = $storeB->id;
        $operator->save();
        $this->giveCallerTheLotteryWinTemplate($operator);

        $this->ticket($storeB, 'OPERATOR-CODE-1');

        $response = $this->actingAs($operator->fresh())
            ->post('/lotteryresult', ['selected_codes' => 'OPERATOR-CODE-1']);

        $this->assertNotSame(403, $response->status(),
            'The platform operator was refused. isAbleTo() is false for them -- there is no '
            . 'super-admin bypass in Laratrust -- so the operator limb MUST precede the permission '
            . 'limb or this is the lockout FinancePermission records.');
    }

    /**
     * EVERY REFUSAL LOOKS THE SAME, WHATEVER REFUSED IT.
     *
     * A removal check added this test: disclosing `$reason` in the body left the suite green,
     * because the oracle test above compares two denials that happen to share a reason
     * (`code outside store`). This compares denials with DIFFERENT reasons -- one refused by the
     * permission limb, one by the ownership limb -- which is what actually pins the uniform body.
     *
     * It matters because the two carry different information: "you lack the permission" is about
     * the caller, while "that code is not yours" is about a row in someone else's tenant. A body
     * that distinguished them would let a caller holding the permission separate "no such code"
     * from "another tenant's code", which is the oracle this guard exists to close.
     */
    public function test_a_permission_refusal_and_an_ownership_refusal_are_indistinguishable(): void
    {
        $this->enableModule();

        \App\Models\Permission::firstOrCreate(
            ['name' => 'Manage Lottery Winner'],
            ['display_name' => 'Manage Lottery Winner']
        );

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->entitle($merchantA);

        $this->ticket($storeA, 'OWN-UNIFORM-1');
        $this->ticket($storeB, 'FOREIGN-UNIFORM-1');

        // No permission yet: refused by the permission limb on a code the caller OWNS.
        $permissionDenial = $this->actingAs($merchantA->fresh())
            ->postJson('/lotteryresult', ['selected_codes' => 'OWN-UNIFORM-1']);

        // Now grant it, and ask for a code the caller does NOT own: refused by the ownership limb.
        $this->grantPermission($merchantA, 'Manage Lottery Winner');

        // THE GRANT MUST HAVE TAKEN, or both denials carry reason `permission`, the bodies match
        // trivially, and this test silently stops pinning the uniform body -- the exact vacuity it
        // was added to fix. `User` is Cachable and Laratrust caches role lookups, so this is not a
        // hypothetical.
        $this->assertTrue($merchantA->fresh()->isAbleTo('Manage Lottery Winner'),
            'The mid-test permission grant did not take, so the second request would be refused '
            . 'by the permission limb too and the comparison below would be vacuous.');

        $ownershipDenial = $this->actingAs($merchantA->fresh())
            ->postJson('/lotteryresult', ['selected_codes' => 'FOREIGN-UNIFORM-1']);

        $this->assertSame(403, $permissionDenial->status());
        $this->assertSame(403, $ownershipDenial->status());
        $this->assertSame(
            (string) $permissionDenial->getContent(),
            (string) $ownershipDenial->getContent(),
            'A permission refusal and an ownership refusal answer differently, so the response '
            . 'discloses WHY it refused -- which separates "not yours" from "no such code" for a '
            . 'caller who holds the permission.'
        );
    }

    /** An empty or missing `selected_codes` denies rather than reaching the handler. */
    public function test_an_empty_code_list_denies(): void
    {
        $this->enableModule();

        [$merchantA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Lottery Winner');
        $this->entitle($merchantA);

        foreach ([['selected_codes' => ''], ['selected_codes' => ' , , '], []] as $payload) {
            $response = $this->actingAs($merchantA->fresh())->postJson('/lotteryresult', $payload);

            $this->assertSame(403, $response->status(),
                'An empty code list reached the handler: ' . json_encode($payload)
                . '. Nothing to authorise means nothing to allow.');
        }
    }

    /** THE GUARD IS INERT EVERYWHERE ELSE, including on the resource's own GET listing. */
    public function test_the_guard_is_inert_on_a_non_target_method_and_route(): void
    {
        $this->enableModule();

        [$merchantA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Lottery Winner');
        $this->entitle($merchantA);

        $listing = $this->actingAs($merchantA->fresh())->get('/lotteryresult');
        $this->assertNotSame(403, $listing->status(),
            'The guard refused GET on the uri whose POST it targets. TARGETS is keyed on '
            . 'METHOD+uri so the listing is untouched.');

        $dashboard = $this->actingAs($merchantA->fresh())->get('/dashboard');
        $this->assertSame(200, $dashboard->status(),
            'The guard is refusing an ordinary admin page. It is appended to the whole `web` '
            . 'group, so it MUST return early for any method+uri absent from TARGETS.');
    }

    /**
     * A CENSUS OVER `store()` ITSELF -- the method both earlier censuses were blind to.
     *
     * SCOPED TO THESE TWO MODULES, AND THE NAME NOW SAYS SO. The first version was called
     * `test_every_module_resource_store_method_is_guarded_or_reasoned` while globbing only
     * AuctionProduct and LotteryPrizes -- six resources out of 60+ across ~45 module route files.
     * The adversarial review caught the mismatch: a census whose NAME overclaims is the same
     * defect as an exemption that overclaims, which is what it was written to stop. The
     * platform-wide sweep is #63's open criterion, not this test's.
     *
     * Two further limits of the scan, stated rather than discovered later: it matches
     * `Route::resource(` only -- not `Route::apiResource` and not `Route::resources([...])` -- and
     * a widened version would need the sibling's TWO globs, because at least one module declares
     * resources in a capital-`Routes` directory that a lowercase glob skips on Linux.
     *
     * P1-INV-17's scope was `update()` and `destroy()` only, and
     * `ModuleOrderMutationGuardTest`'s exemption for this very resource read "stub: update():221
     * and destroy():231 are both `//`" -- true of those two methods and FALSELY REASSURING ABOUT
     * THE RESOURCE. This scan answers the question that was never asked: for each
     * `Route::resource` in the two modules, does its controller define a `store()` with a body,
     * and is that body accounted for?
     */
    public function test_every_resource_store_in_the_two_guarded_modules_is_reasoned(): void
    {
        $files = array_merge(
            glob(base_path('packages/workdo/AuctionProduct/src/routes/*.php')) ?: [],
            glob(base_path('packages/workdo/LotteryPrizes/src/routes/*.php')) ?: []
        );

        $this->assertNotEmpty($files);

        // Reasoned entries. Each says what `store()` DOES, read rather than inferred, and whether
        // it is a stub. An entry that names only the methods somebody looked at is the mistake
        // this test exists to stop repeating.
        $reasoned = [
            'auctionproduct' => 'AuctionProductController::store() sets $Product->store_id = getCurrentStore() (:376, :587) and keys every secondary write on $product->id -- no caller-named foreign row. Verified during P1-INV-17.',
            'auctionsetting' => 'AuctionProductSettingController::store():65 writes store_id = getCurrentStore(); its auction_product id list is intersected with the setting\'s own store by Console/Auction.php:67-70.',
            'auctionorder' => 'AuctionOrdersController has no store() with a body (the resource registers it; the method is absent/stub).',
            // PRECISE ABOUT WHICH IDENTIFIER WAS CHECKED, because the first version of this entry
            // said only "IS store-scoped" -- true of store_id and silent about the one that
            // matters, which is how #64 stayed invisible.
            'lotteryproduct' => 'LotteryPrizesController::store():107-124 writes store_id = getCurrentStore() (:114) and filters on it (:107) -- so the SETTING is store-scoped. Its `lottery_products` value (:117, :119) is a HOST Product id taken from request input with NO ownership check, and Product carries no effective scope, so a merchant can point their own setting at another tenant\'s product, which then rides into product_json on the draw (:132). Not this guard\'s surface; raised separately.',
            'lotteryresult' => 'GUARDED by App\\Http\\Middleware\\LotteryDrawOwnershipGuard -- this is P1-INV-18 (#64).',
            'lotteryorder' => 'LotteryOrderController has no store() with a body.',
        ];

        $found = [];

        foreach ($files as $file) {
            foreach (preg_split('/\R/', (string) file_get_contents($file)) as $line) {
                if (preg_match('/^\s*(\/\/|\*|#|\/\*)/', $line) === 1) {
                    continue;
                }

                if (preg_match('/Route::resource\s*\(\s*[\'"]([^\'"]+)[\'"]/', $line, $m) === 1) {
                    $found[ltrim($m[1], '/')] = true;
                }
            }
        }

        $this->assertNotEmpty($found, 'The scan found no Route::resource declarations at all.');

        $unreasoned = array_values(array_diff(array_keys($found), array_keys($reasoned)));

        $this->assertSame([], $unreasoned,
            "A Route::resource in AuctionProduct or LotteryPrizes has no reasoned entry for its "
            . "store() method (this census covers THOSE TWO MODULES ONLY -- the platform-wide "
            . "sweep is #63):\n  " . implode("\n  ", $unreasoned)
            . "\n\nRead the store() body and add an entry saying what it does -- an entry naming "
            . 'only update() and destroy() is how #64 stayed invisible.');

        // THE ENTRIES MUST BE REACHABLE: an entry for a resource the scan no longer produces is
        // documentation pretending to be a control.
        foreach (array_keys($reasoned) as $resource) {
            $this->assertArrayHasKey($resource, $found,
                "The reasoned entry for `{$resource}` is dead: the scan no longer produces it.");
        }

        // And the guard really does target the one that needed it.
        $targets = array_keys(
            (new \ReflectionClass(\App\Http\Middleware\LotteryDrawOwnershipGuard::class))
                ->getConstant('TARGETS')
        );
        $this->assertSame(['POST lotteryresult'], $targets);
    }

    // =========================================================================================
    //  THE THREE DEFECTS THE SECOND ADVERSARIAL REVIEW FOUND IN THIS WORK
    // =========================================================================================

    /**
     * TWO TENANTS HOLDING THE SAME CODE STRING: the caller's own code does not license the
     * handler's unscoped `whereIn` to reach the other tenant's row.
     *
     * THIS IS THE SERIOUS HALF OF THE SHARED-CODE CASE, and it had no coverage until the review
     * pointed that out. The guard proves "every named code has a row in my store"; the handler acts
     * on "every row in ANY store whose lottery_code matches". Those sets are equal only while no
     * other tenant holds a matching code. When one does and has NOT drawn it, the handler's loop
     * writes a forged 'Paid' order for the victim's product and a `lottery_customers` row in the
     * victim's tenant -- byte for byte the reproduced defect, through a code the caller genuinely
     * owns.
     *
     * The predicate's FIRST limb is what refuses this: it runs the handler's own query with no
     * store filter and denies if any matched row is foreign. Identical code strings exercise it on
     * SQLite; the collation-equal case (`ABC-1` vs `abc-1`) is MySQL-only and is proven in
     * tests/Support/Security/bin/verify-lottery-code-collation-on-mysql.php.
     *
     * Unreachable in practice -- `lottery_code` is `random_int` over 62^8 and nothing lets a
     * principal choose it -- so this is hardening, and the test says so rather than implying a
     * live exploit.
     */
    public function test_an_identical_code_string_in_another_tenant_is_not_drawn_through_the_callers_own(): void
    {
        $this->enableModule();

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Lottery Winner');
        $this->entitle($merchantA);
        $this->giveCallerTheLotteryWinTemplate($merchantA);

        // BOTH stores hold a ticket under the same code string, and NEITHER has drawn it.
        $this->ticket($storeA, 'DUPE-CODE-1');
        $this->ticket($storeB, 'DUPE-CODE-1');

        $response = $this->actingAs($merchantA->fresh())
            ->post('/lotteryresult', ['selected_codes' => 'DUPE-CODE-1']);

        $this->assertSame(403, $response->status(),
            'The guard allowed a code the caller owns while another tenant holds the same string, '
            . "so the handler's unscoped whereIn reaches both rows. Status "
            . $response->status() . '.');
        $this->assertSame(0, DB::table('orders')->count(),
            'A forged order was written. With two matching rows the handler draws BOTH, so the '
            . "victim's product lands in an order in the caller's store.");
        $this->assertSame(0, DB::table('lottery_customers')->count(),
            "A lottery_customers row was planted, in one tenant or the other.");
    }

    /**
     * WHITESPACE IN THE POSTED VALUE CHANGES NOTHING, AND THAT IS THE MEASURED ANSWER TO A CLAIM
     * I MADE AND A REVIEW SHARPENED -- BOTH WRONG.
     *
     * I first argued that the trait's trimming "can only ever be STRICTER" than the handler. The
     * adversarial review inverted that and proposed refusing any untrimmed value, reasoning that
     * the guard would accept `"\nCODE"` as `CODE` while the handler matched nothing and 500'd.
     *
     * Driven through the real middleware stack, BOTH readings are wrong: `TrimStrings` is GLOBAL,
     * so the field value is already trimmed before either the guard or the handler sees it. This
     * test is the measurement -- the whitespace and clean forms must behave the SAME -- and it is
     * why the rule the review proposed was removed again rather than kept on a refuted
     * justification. Keeping it had a cost: it turned an empty selection into a 403.
     *
     * `TrimStrings` normalises the OUTER value only, so an inner element can still carry
     * whitespace (`'A, B'` explodes to `['A', ' B']`). That divergence runs in the safe direction
     * -- the guard considers a value the handler ignores -- and the dangerous case is covered by
     * the predicate's first limb regardless, which asks about the ROWS the handler matches.
     */
    public function test_whitespace_in_the_posted_value_behaves_the_same_as_without(): void
    {
        $this->enableModule();

        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Lottery Winner');
        $this->entitle($merchantA);
        $this->giveCallerTheLotteryWinTemplate($merchantA);

        $this->ticket($storeA, 'WHITESPACE-1');

        $clean = $this->actingAs($merchantA->fresh())
            ->postJson('/lotteryresult', ['selected_codes' => 'WHITESPACE-1']);
        $cleanDrew = DB::table('orders')->where('lottery_code', 'WHITESPACE-1')->exists();

        DB::table('orders')->delete();
        DB::table('lottery_customers')->delete();

        $padded = $this->actingAs($merchantA->fresh())
            ->postJson('/lotteryresult', ['selected_codes' => "\n WHITESPACE-1 \t"]);
        $paddedDrew = DB::table('orders')->where('lottery_code', 'WHITESPACE-1')->exists();

        $this->assertSame($clean->status(), $padded->status(),
            'The padded and clean forms answered differently (' . $clean->status() . ' vs '
            . $padded->status() . '), so something between the request and the handler is NOT '
            . 'normalising them identically -- re-read the guard docblock on TrimStrings.');
        $this->assertSame($cleanDrew, $paddedDrew,
            'The padded form drew a different set from the clean form.');
        $this->assertTrue($cleanDrew,
            'Neither form drew the caller\'s own ticket, so this test is comparing two refusals '
            . 'and proves nothing about normalisation.');
    }

    /**
     * AN EMPTY SELECTION COMES BACK AS A 302 WITH A MESSAGE, NOT A 403 ERROR PAGE.
     *
     * A UX REGRESSION I INTRODUCED, found by the review. The draw screen posts a plain form whose
     * `selected_codes` is a hidden field initialised empty, with no client-side guard against
     * submitting before a seat is picked. Before the guard, a mis-click hit the handler's own
     * `required` validator and came back as a 302 with a toast. After it, the caller got a 403
     * error page and lost the screen.
     *
     * `test_an_empty_code_list_denies` could not catch this because it drives `postJson` for every
     * payload, and the JSON branch still answers 403 -- correctly, since an API caller should get
     * a status and not a redirect. This test drives the WEB path, which is the one a merchant uses.
     *
     * "You sent nothing" discloses nothing about any tenant's rows, so softening only this reason
     * leaves the uniform-refusal property the two oracle tests pin untouched.
     */
    public function test_an_empty_selection_on_the_web_path_redirects_rather_than_403ing(): void
    {
        $this->enableModule();

        [$merchantA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Lottery Winner');
        $this->entitle($merchantA);

        foreach (['', ' , , '] as $payload) {
            $response = $this->actingAs($merchantA->fresh())
                ->from('/lotteryresult')
                ->post('/lotteryresult', ['selected_codes' => $payload]);

            $this->assertSame(302, $response->status(),
                'An empty selection answered ' . $response->status() . ' on the web path for '
                . json_encode($payload) . '. A mis-click must not cost the merchant the screen.');
            $this->assertNotNull(session('error'),
                'The redirect carries no message, so the merchant is sent back with no idea why.');
        }

        // The JSON branch still answers a status, which is what an API caller needs.
        $json = $this->actingAs($merchantA->fresh())
            ->postJson('/lotteryresult', ['selected_codes' => '']);
        $this->assertSame(403, $json->status(),
            'The JSON branch should still answer 403 rather than a redirect.');

        // And nothing reached the handler on either path.
        $this->assertSame(0, DB::table('orders')->count());
    }
}
