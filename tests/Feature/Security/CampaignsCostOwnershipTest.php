<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-SEC-86 — THE CAMPAIGN COST TIERS WERE DELETABLE, EDITABLE AND STEALABLE BY ID.
 *
 * `CampaignsController::campaignsCost()` (vendored, not edited) has two unscoped operations:
 *
 *   CostSetting::whereIn('id', $deletedIds)->delete();        // no ownership predicate
 *
 *   $costSetting['created_by'] = $creatorId;                  // <- set BEFORE the update
 *   $existing = CostSetting::find($costSetting['id']);
 *   if ($existing) { $existing->update($costSetting); }       // no ownership predicate
 *
 * The second is worse than an unscoped edit: because `created_by` is assigned before the update,
 * the caller does not merely rewrite another principal's row, they TAKE OWNERSHIP of it.
 *
 * Both id sources are in the BODY, not the URI -- `deleted_ids` is a comma-separated list and the
 * update ids are `store_group[*][id]` -- which is why the fix is a bespoke guard and not
 * `store-row:`, a rule that reads a route parameter.
 *
 * AND NOT `operator` EITHER, which was the first thing that came to mind because the sibling
 * `campaigns-system-setup` uses it. `cost_setting` is `min`, `max`, `price`, `created_by` and
 * `timestamps` -- NO `store_id` -- and `CostSetting` is absent from `config/tenancy.php`
 * `scoped_models` and declares only `HasFactory, Cachable`. So these are PER-PRINCIPAL pricing
 * tiers keyed on `created_by` alone, and an operator-only rule would take every merchant's own
 * tiers away from them.
 */
final class CampaignsCostOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = 'campaigns/campaigns-cost';

    private function enableCampaigns(): void
    {
        AddOnManager::create([
            'module' => 'Campaigns',
            'name' => 'Campaigns',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'campaigns',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['Campaigns']);

        foreach (glob(base_path('packages/workdo/Campaigns/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        $this->assertTrue(Schema::hasTable('cost_setting'),
            'PREMISE: the module table is absent, so every assertion below would pass for the '
            . 'wrong reason.');
    }

    /** `PlanModuleCheck:Campaigns` resolves against the OWNER's `user_active_modules` row, memoised in a scoped binding. */
    private function entitle(User $owner): void
    {
        DB::table('user_active_modules')->insert([
            'user_id' => $owner->id,
            'module' => 'Campaigns',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->app->forgetInstance('lynomia.activated_modules.owner.' . $owner->id);

        $this->assertContains('Campaigns', ActivatedModule($owner->id),
            'PREMISE: without the entitlement PlanModuleCheck refuses every request below and each '
            . 'assertion would pass without reaching the handler.');
    }

    private function costRow(int $createdBy, float $price): int
    {
        return (int) DB::table('cost_setting')->insertGetId([
            'min' => 1, 'max' => 10, 'price' => $price,
            'created_by' => $createdBy,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Raw, so the answer is the table's. */
    private function row(int $id): ?object
    {
        return DB::table('cost_setting')->where('id', $id)->first();
    }

    private function arrange(): array
    {
        $this->enableCampaigns();

        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB] = $this->makeTenant();

        $merchantA->current_store = $storeA->id;
        $merchantA->save();
        $merchantA = User::find($merchantA->id);

        $this->entitle($merchantA);

        return [$merchantA, User::find($merchantB->id)];
    }

    public function test_a_merchant_cannot_delete_another_principals_cost_tier(): void
    {
        [$a, $b] = $this->arrange();

        $theirs = $this->costRow((int) $b->id, 99.0);
        $mine = $this->costRow((int) $a->id, 11.0);

        $this->actingAs($a)->post(self::URI, ['deleted_ids' => (string) $theirs]);

        $this->assertNotNull($this->row($theirs),
            "A merchant deleted ANOTHER principal's cost tier by id. The handler's delete is "
            . 'CostSetting::whereIn(id, $deletedIds)->delete() with no ownership predicate.');

        // POSITIVE CONTROL: the same request against their OWN row must still work.
        $this->actingAs($a)->post(self::URI, ['deleted_ids' => (string) $mine]);

        $this->assertNull($this->row($mine),
            'A merchant can no longer delete their OWN cost tier, so the refusal above proves '
            . 'nothing about ownership.');
    }

    public function test_a_merchant_cannot_rewrite_or_steal_another_principals_cost_tier(): void
    {
        [$a, $b] = $this->arrange();

        $theirs = $this->costRow((int) $b->id, 99.0);

        $this->actingAs($a)->post(self::URI, [
            'store_group' => [['id' => (string) $theirs, 'min' => 1, 'max' => 10, 'price' => 1]],
        ]);

        $row = $this->row($theirs);

        $this->assertSame(99.0, (float) $row->price,
            "A merchant rewrote ANOTHER principal's cost tier by id.");

        $this->assertSame((int) $b->id, (int) $row->created_by,
            "A merchant TOOK OWNERSHIP of another principal's cost tier: the handler assigns "
            . '`created_by` from the caller BEFORE the update, so an unscoped update is a '
            . 'transfer and not merely an edit.');
    }

    /**
     * THE NON-CANONICAL ID, which is the bypass that got past the first version of the sibling
     * guard. The handler filters `deleted_ids` through `filter_var(FILTER_VALIDATE_INT)`, so that
     * path is safe, but the UPDATE path passes `store_group[*][id]` to `find()` RAW.
     * `config/database.php:55` sets `'strict' => false`, so on production MySQL `find('7abc')`
     * compares a string to an integer column and matches row 7.
     *
     * The guard's answer is to REFUSE anything it cannot parse canonically rather than try to
     * reproduce the engine's coercion. MEASURABLE HERE ONLY AS THE REFUSAL: SQLite keeps `'7abc'`
     * as text so it matches nothing, which is exactly why the bypass in the sibling guard was
     * "visible only on MySQL" -- so this case pins the refusal, and the MySQL coercion itself
     * belongs in a `tests/Support/Security/bin/` harness.
     */
    public function test_an_id_that_is_not_canonically_a_number_is_refused_rather_than_dropped(): void
    {
        [$a, $b] = $this->arrange();

        $theirs = $this->costRow((int) $b->id, 99.0);
        $mine = $this->costRow((int) $a->id, 11.0);

        $this->actingAs($a)->post(self::URI, [
            'store_group' => [
                ['id' => $theirs . 'abc', 'min' => 1, 'max' => 10, 'price' => 1],
                ['id' => (string) $mine, 'min' => 2, 'max' => 20, 'price' => 2],
            ],
        ]);

        $this->assertSame(11.0, (float) $this->row($mine)->price,
            'A request carrying an id the guard cannot parse was allowed through for its OTHER, '
            . 'legitimate rows. The whole request must be refused, because on MySQL the '
            . 'unparseable id reaches a real row.');

        $this->assertSame(99.0, (float) $this->row($theirs)->price);
    }

    public function test_a_phantom_id_is_refused_rather_than_silently_accepted(): void
    {
        [$a] = $this->arrange();

        $mine = $this->costRow((int) $a->id, 11.0);

        $this->actingAs($a)->post(self::URI, [
            'store_group' => [
                ['id' => '99999', 'min' => 1, 'max' => 10, 'price' => 1],
                ['id' => (string) $mine, 'min' => 2, 'max' => 20, 'price' => 2],
            ],
        ]);

        $this->assertSame(11.0, (float) $this->row($mine)->price,
            'An id naming no row let the rest of the request through. A row that does not exist '
            . 'is not a row the caller owns.');
    }

    /** POSITIVE CONTROL for the whole guard: creating a NEW tier carries no id and must still work. */
    public function test_a_merchant_can_still_create_a_new_cost_tier(): void
    {
        [$a] = $this->arrange();

        $this->actingAs($a)->post(self::URI, [
            'store_group' => [['min' => 5, 'max' => 50, 'price' => 7.5]],
        ]);

        $this->assertTrue(
            DB::table('cost_setting')->where('created_by', $a->id)->where('price', 7.5)->exists(),
            'The guard refuses a legitimate create, which carries no id at all. That would make '
            . 'every refusal above meaningless.');
    }

    /**
     * THE GUARD IS REALLY ON THE ROUTE, which nothing else pins.
     *
     * `RouteCensusGateTest` checks that a `guarded:` RULE names a guard the route carries, but this
     * route's rule is `auth` -- deliberately, because `administrativeRule()` reads only the
     * `ADMINISTRATIVE` table and `ModuleAdministrativeRouteGuard`'s `match` has no `guarded:` arm,
     * so a `guarded:` rule there falls to `default => false` and denies every non-operator. A first
     * version of this change did that and broke a merchant's own create and delete.
     *
     * So the guard's presence is pinned here instead: if it is dropped from the `web` group or the
     * route is renamed out of `TARGETS`, every refusal above would silently become a pass.
     */
    public function test_the_route_really_carries_the_ownership_guard(): void
    {
        $this->enableCampaigns();

        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === self::URI && in_array('POST', $r->methods(), true));

        $this->assertNotNull($route, 'PREMISE: the route is not registered, so this proves nothing.');

        // `gatherMiddleware()` returns group NAMES ('web'); the router expands them.
        $this->assertContains(\App\Http\Middleware\CampaignsCostOwnershipGuard::class,
            app('router')->gatherRouteMiddleware($route),
            'The cost-setting route no longer carries CampaignsCostOwnershipGuard, so every '
            . 'ownership refusal in this file would pass without a control behind it.');

        $this->assertSame([self::URI], \App\Http\Middleware\CampaignsCostOwnershipGuard::TARGETS,
            'The guard no longer names this route in TARGETS, so it short-circuits on it.');
    }

    /**
     * THE PLATFORM OPERATOR IS NOT SUBJECT TO THIS, which is the same exemption every rule in
     * `ModuleAdministrativeRouteGuard` grants and the reason the guard checks `isOperator()`
     * first. Campaigns is a paid placement the operator prices and approves, so reaching any
     * tenant's tiers is what that role is for. Without this case, removing the exemption would
     * pass every other assertion in this file.
     */
    public function test_the_platform_operator_is_not_bound_by_the_ownership_check(): void
    {
        $this->enableCampaigns();

        [$merchant] = $this->makeTenant();
        $theirs = $this->costRow((int) $merchant->id, 99.0);

        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)->post(self::URI, [
            'store_group' => [['id' => (string) $theirs, 'min' => 1, 'max' => 10, 'price' => 42.0]],
        ]);

        $this->assertSame(42.0, (float) $this->row($theirs)->price,
            'The platform operator is being held to the ownership check. Every other rule in the '
            . 'module guard exempts them, and pricing a placement is what the role is for.');
    }

    /**
     * A PRINCIPAL ROOTED AT THE OPERATOR GETS NOTHING, NOT THE OPERATOR'S TIERS.
     *
     * ADDED AFTER A SURVIVING MUTATION. Replacing `merchantTenantRoot()` with the raw
     * `creatorId()` passed every other case in this file, because every merchant here is type
     * `admin`, where the two agree exactly. They diverge for a principal whose root is the
     * PLATFORM OPERATOR -- staff the operator created, or, as here, a merchant who changed their
     * own `users.type`, which the product supports and which P1-SEC-83 fixed in the role-grant
     * ceiling for the same reason. For such a principal `creatorId()` is the operator's id, so the
     * weaker version would scope them TO THE OPERATOR'S tiers and let them rewrite the platform's
     * own placement prices.
     */
    public function test_a_merchant_who_demoted_their_own_type_cannot_reach_the_operators_tiers(): void
    {
        $this->enableCampaigns();

        // The operator AT ID 1, because that is the `created_by` every merchant carries.
        $operator = $this->makePlatformAdmin(['id' => 1]);
        $operatorTier = $this->costRow(1, 99.0);

        [$merchant, $store] = $this->makeTenant();
        $merchant->current_store = $store->id;
        $merchant->save();
        $this->entitle(User::find($merchant->id));

        /*
         * AND THE OPERATOR TOO, which a surviving mutation forced. `PlanModuleCheck` resolves the
         * entitlement against the EMPLOYER's `user_active_modules` -- `activated_modules_for_owner($user->created_by)`
         * for any `type` that is not literally 'admin' -- so once this merchant is demoted the gate
         * stops reading THEIR row and reads owner 1's. Without this the request is refused by the
         * plan gate at stack position 30, AFTER this guard at 23, so the assertion below passed
         * whatever the guard decided and the mutation swapping `merchantTenantRoot()` for
         * `creatorId()` survived. The same trap CLAUDE.md records, met twice in one session.
         */
        $this->entitle(User::find(1));

        $merchant->type = 'Shop Manager';
        $merchant->save();
        (new User)->flushCache();

        $demoted = User::find($merchant->id);

        $this->assertSame(1, (int) $demoted->creatorId(),
            'PREMISE: the demotion must move creatorId() onto the operator, or this fixture does '
            . 'not reproduce the condition the mutation exposed.');

        $this->actingAs($demoted)->post(self::URI, [
            'store_group' => [['id' => (string) $operatorTier, 'min' => 1, 'max' => 10, 'price' => 1.0]],
        ]);

        $this->assertSame(99.0, (float) $this->row($operatorTier)->price,
            "A principal rooted at the operator rewrote the OPERATOR's own placement price. "
            . 'creatorId() answers with the operator for such a caller; merchantTenantRoot() '
            . 'answers null and the request is refused.');
    }

    /**
     * AN ANONYMOUS CALLER, AND AN HONEST NOTE ABOUT WHERE THE REFUSAL COMES FROM.
     *
     * A mutation removing the guard's own `if (! $user)` limb SURVIVED, and this case records why
     * rather than dressing the limb up as covered.
     *
     * MY FIRST VERSION OF THIS CASE ASSERTED THE WRONG REASON. It claimed the route carries `auth`
     * and that `Authenticate` runs first. It does not: the census reports `authenticated: false`
     * for this route, because the Campaigns group is `['web']` plus `PlanModuleCheck:Campaigns`
     * and nothing else. What actually refuses an anonymous caller is
     * `ModuleAdministrativeRouteGuard` acting on this route's `auth` MANIFEST RULE -- it denies a
     * null `auth('web')` user -- and it sits at stack position 21, ahead of
     * `CampaignsCostOwnershipGuard` at 23. The premise below pins that ordering, which is the
     * real mechanism, and the assertion pins the outcome.
     */
    public function test_an_anonymous_caller_is_refused_before_the_guard_is_reached(): void
    {
        $this->enableCampaigns();

        [$merchant] = $this->makeTenant();
        $theirs = $this->costRow((int) $merchant->id, 99.0);

        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === self::URI && in_array('POST', $r->methods(), true));

        $stack = app('router')->gatherRouteMiddleware($route);
        $administrative = array_search(\App\Http\Middleware\ModuleAdministrativeRouteGuard::class, $stack, true);
        $ownership = array_search(\App\Http\Middleware\CampaignsCostOwnershipGuard::class, $stack, true);

        $this->assertIsInt($administrative, 'PREMISE: the module guard is not on this route.');
        $this->assertIsInt($ownership, 'PREMISE: the ownership guard is not on this route.');

        $this->assertLessThan($ownership, $administrative,
            'PREMISE: the module guard no longer runs BEFORE the ownership guard, so the `auth` '
            . 'manifest rule is no longer what refuses an anonymous caller first, and the '
            . 'ownership guard\'s own anonymous limb now needs a case of its own.');

        $this->post(self::URI, ['store_group' => [['id' => (string) $theirs, 'price' => 1.0]]]);

        $this->assertSame(99.0, (float) $this->row($theirs)->price);
    }
}
