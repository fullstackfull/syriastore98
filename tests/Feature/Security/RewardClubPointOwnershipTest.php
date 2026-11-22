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
 * P1-SEC-89 — A CUSTOMER'S LOYALTY POINT HISTORY WAS READABLE ACROSS TENANTS BY CUSTOMER ID.
 *
 * One of the 75 P1-SEC-76 leads, verified rather than swept: of that list, 23 are now covered by
 * manifest rules and 52 remain, 23 of them with no permission check at all. This is the one whose
 * subject is customer financial data.
 *
 * `RewardClubPointController` (vendored, not edited):
 *
 *   clubPointHistory($customerId)
 *       RewardClubPoint::with('orderData')->where('customer_id', $customerId)->get()
 *       Customer::find($customerId)
 *       -- no permission check and no tenant predicate on the points query.
 *
 *   show($id)
 *       RewardClubPoint::find($id) then explode(',', $row->product_id)
 *       -- no check at all; and a null row makes `->product_id` a 500.
 *
 * `App\Models\Customer` IS in `config/tenancy.php` `scoped_models`, so `Customer::find()` returns
 * NULL for another tenant's customer -- which is why this is easy to miss: the page renders with
 * no customer. But `RewardClubPoint` is NOT among the eleven scoped models and declares only
 * `HasFactory, Cachable`, so the POINTS query answers across tenants: points, used_points,
 * reward_price, order_id and product_id for somebody else's shopper.
 *
 * `reward_club_points` carries `store_id`, and `customers` carries `store_id`, so both doors are
 * reachable by the existing `store-row:` rule -- but NOT by the same one, and that distinction is
 * the point of fixing them separately. The history route's parameter is a CUSTOMER id, so the row
 * whose ownership decides the request is the customer's; using
 * `store-row:reward_club_points:customerId` would compare a customer id against reward-row ids
 * and admit or refuse the wrong rows.
 *
 * The module's other row verbs are INERT and are classified anyway: `destroy()` and `update()`
 * have empty bodies (`//`) and `edit()` returns a static view, ignoring its id.
 */
final class RewardClubPointOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function enableModule(): void
    {
        AddOnManager::create([
            'module' => 'RewardClubPoint', 'name' => 'Reward Club Point',
            'monthly_price' => 0, 'yearly_price' => 0,
            'is_enable' => 1, 'package_name' => 'reward-club-point',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['RewardClubPoint']);

        foreach (glob(base_path('packages/workdo/RewardClubPoint/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        $this->assertTrue(Schema::hasTable('reward_club_points'),
            'PREMISE: the module table is absent, so every assertion below would pass for the '
            . 'wrong reason.');
    }

    private function entitle(User $owner): void
    {
        DB::table('user_active_modules')->insert([
            'user_id' => $owner->id, 'module' => 'RewardClubPoint',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->app->forgetInstance('lynomia.activated_modules.owner.' . $owner->id);

        $this->assertContains('RewardClubPoint', ActivatedModule($owner->id),
            'PREMISE: without the entitlement PlanModuleCheck refuses every request below.');
    }

    private function customer(Store $store, string $name): int
    {
        // `customers` has first_name/last_name, not `name` -- measured from the migration after a
        // fixture insert failed on it.
        return (int) DB::table('customers')->insertGetId([
            'first_name' => $name,
            'last_name' => 'Shopper',
            'email' => strtolower($name) . '@example.test',
            'password' => bcrypt('secret'),
            'store_id' => $store->id,
            'created_by' => (int) $store->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function points(Store $store, int $customerId, float $points, string $productIds): int
    {
        $id = (int) DB::table('reward_club_points')->insertGetId([
            'customer_id' => $customerId,
            'points' => $points,
            'used_points' => 0,
            'reward_price' => $points * 2,
            'product_id' => $productIds,
            'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if (class_exists(\Workdo\RewardClubPoint\app\Models\RewardClubPoint::class)) {
            (new \Workdo\RewardClubPoint\app\Models\RewardClubPoint)->flushCache();
        }

        return $id;
    }

    private function arrange(): array
    {
        $this->enableModule();

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $merchantA->current_store = $storeA->id;
        $merchantA->save();
        $merchantA = User::find($merchantA->id);

        $this->entitle($merchantA);

        return [User::find($merchantA->id), $storeA, $storeB];
    }

    public function test_a_merchant_cannot_read_another_tenants_customer_point_history(): void
    {
        [$a, $storeA, $storeB] = $this->arrange();

        $theirCustomer = $this->customer($storeB, 'Rival');
        $this->points($storeB, $theirCustomer, 777.0, '901,902');

        $response = $this->actingAs($a)->get('customer-club-point-history/' . $theirCustomer);

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('777', $body,
            "Another tenant's customer's loyalty point history was rendered. `RewardClubPoint` is "
            . 'not a scoped model and the query carries no store predicate; `Customer::find()` IS '
            . 'scoped, so the page simply renders with no customer beside the figures.');

        // POSITIVE CONTROL: the merchant's OWN customer must still be readable.
        $myCustomer = $this->customer($storeA, 'Mine');
        $this->points($storeA, $myCustomer, 123.0, '1,2');

        $own = (string) $this->actingAs($a)->get('customer-club-point-history/' . $myCustomer)->getContent();

        $this->assertStringContainsString('123', $own,
            'A merchant can no longer read their OWN customer\'s point history, so the refusal '
            . 'above says nothing about ownership.');
    }

    public function test_a_merchant_cannot_read_another_tenants_point_transaction(): void
    {
        [$a, , $storeB] = $this->arrange();

        $theirCustomer = $this->customer($storeB, 'Rival');
        $row = $this->points($storeB, $theirCustomer, 555.0, '9901,9902');

        $body = (string) $this->actingAs($a)->get('rewardclubpoint/' . $row)->getContent();

        $this->assertStringNotContainsString('9901', $body,
            "Another tenant's point transaction was rendered by id. `show()` is "
            . '`RewardClubPoint::find($id)` with no check of any kind.');
    }

    /**
     * THE HISTORY RULE MUST JUDGE THE CUSTOMER ROW, NOT A REWARD ROW OF THE SAME ID.
     *
     * ADDED AFTER A SURVIVING MUTATION, and it is the case the manifest comment claims to be the
     * reason for naming `customers` there. Pointing the rule at `reward_club_points` instead
     * passed every other case in this file, because in those fixtures the rival customer's id and
     * the rival reward row's id happen to be the same number, so the wrong table refused by
     * coincidence.
     *
     * Here the ids are deliberately made to DISAGREE about ownership: the reward row whose id
     * equals the rival customer's id belongs to the CALLER. So a rule reading
     * `reward_club_points` by that id finds its own row, admits the request, and the handler then
     * renders the rival's points. A rule reading `customers` finds the rival's customer and
     * refuses. Only the correct rule passes this case.
     */
    public function test_the_history_rule_judges_the_customer_row_and_not_a_reward_row(): void
    {
        [$a, $storeA, $storeB] = $this->arrange();

        /*
         * The ids are arranged so that the CUSTOMER row and the REWARD row at the same id
         * DISAGREE about ownership. `customer_id` has a real foreign key -- SQLite enforces it, as
         * a phantom id proved -- so every reward row below names a customer that exists.
         *
         *   customers:            1 = the caller's,   2 = the rival's
         *   reward_club_points:   1, 2 = the CALLER's,  3 = the rival's (the 888 figure)
         *
         * So reward row 2 is the caller's while customer 2 is the rival's.
         */
        $myCustomer = $this->customer($storeA, 'Mine');
        $rival = $this->customer($storeB, 'Rival');

        $this->points($storeA, $myCustomer, 11.0, '1');
        $mineRow = $this->points($storeA, $myCustomer, 12.0, '2');
        $this->points($storeB, $rival, 888.0, '7001');

        $this->assertSame($rival, $mineRow,
            'PREMISE: the rival customer id and a reward row id owned by the CALLER must be the '
            . 'same number, or this case cannot tell the correct table from the wrong one.');

        $this->assertSame((int) $storeA->id,
            (int) DB::table('reward_club_points')->where('id', $mineRow)->value('store_id'),
            'PREMISE: the reward row at that id belongs to the CALLER, so a rule reading '
            . '`reward_club_points` would ADMIT the request.');

        $this->assertSame((int) $storeB->id,
            (int) DB::table('customers')->where('id', $rival)->value('store_id'),
            'PREMISE: the customer at that id belongs to the other tenant, so a rule reading '
            . '`customers` must REFUSE it.');

        $body = (string) $this->actingAs($a)->get('customer-club-point-history/' . $rival)->getContent();

        $this->assertStringNotContainsString('888', $body,
            'The history rule is judging a REWARD row of the same id instead of the customer row, '
            . 'so it admitted a request it should have refused. The parameter is a customer id; '
            . 'the rule must name `customers`.');
    }
}
