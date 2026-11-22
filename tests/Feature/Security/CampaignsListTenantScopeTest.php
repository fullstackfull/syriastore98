<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Security\EnablesWorkdoModules;

/**
 * P1-SEC-69 — THE CAMPAIGNS LIST SERVES EVERY TENANT'S ROWS TO A MERCHANT'S STAFF, AND WRITES TO THEM.
 * =============================================================================================
 *
 * `packages/workdo/Campaigns` (vendored, not edited) builds its list in
 * `DataTables/AllCampaignsData::query()`, whose tenant predicate is
 *
 *     if (Auth::user()->type == 'admin') { $query->where('created_by', Auth::user()->creatorId()); }
 *
 * `users.type` is a MERCHANT OWNER's type in this product. A staff member's `type` is the name of
 * the role they hold, so for every staff member the condition is false and NO predicate is applied
 * at all — and `campaigns` is not in `config/tenancy.php`'s `scoped_models`, so nothing else adds
 * one. The row this guard exists to hide is served, with the other tenant's store name, product
 * name and the name of the user who created it.
 *
 * AND IT IS NOT ONLY A READ. The same method ends with
 *
 *     $query->get()->each(function ($campaign) { if (expired) { $campaign->status = 2; $campaign->save(); } });
 *
 * so opening the list WRITES to every row the query returned — which, for staff, is every tenant's.
 * A listing that mutates other tenants' rows is the worst half of this, because the victim sees a
 * status change with no actor.
 *
 * THE CONTROL IS HOST-SIDE, because the module is vendored and must not be edited:
 * `App\DataTables\TenantScopedCampaignsData` is bound over the module's class in `AppServiceProvider`,
 * which is the class the module's own controller injects. That is the mechanism P1-SEC-75 already
 * uses for the Helpdesk lists.
 *
 * EVERY CASE DRIVES THE REAL ROUTE with the module loaded by the production loader — no module
 * loads in a test run otherwise, so a test that "passes" here without it would have reached
 * nothing. `enable()` asserts that premise, and a positive control shows the owner still sees
 * their own campaign, so a guard that simply emptied the list could not pass.
 */
final class CampaignsListTenantScopeTest extends SecurityTestCase
{
    use EnablesWorkdoModules;
    use RefreshDatabase;

    private const URI = '/campaigns/campaigns';

    private function enable(): void
    {
        $failed = $this->enableWorkdoModules(['Campaigns']);

        $this->assertNotEmpty(
            array_filter(Route::getRoutes()->getRoutes(), fn ($r) => str_starts_with(ltrim($r->getActionName(), '\\'), 'Workdo\\Campaigns\\')),
            'PREMISE: Campaigns is not loaded, so this test would reach nothing. Failed migrations: ' . implode('; ', $failed)
        );
    }

    /** A campaign owned by one tenant, named so a leak is recognisable in the response. */
    private function campaignFor(User $owner, $store, string $name, string $endsAt = '2099-01-01'): int
    {
        $product = \App\Models\Product::forceCreate([
            'name' => $name . ' product', 'slug' => \Illuminate\Support\Str::slug($name) . '-' . $this->uniqueFixtureRef(),
            'price' => 10, 'sale_price' => 10, 'product_stock' => 1, 'track_stock' => 1, 'variant_product' => 0,
            'store_id' => $store->id, 'created_by' => $owner->id,
        ]);

        return (int) DB::table('campaigns')->insertGetId([
            'name' => $name, 'user' => $owner->id, 'store' => $store->id, 'product' => $product->id,
            'total_days' => 7, 'start_date' => '2026-01-01', 'end_date' => $endsAt,
            'payment_method' => 'bank_transfer', 'order_id' => 'C-' . $this->uniqueFixtureRef(),
            'status' => 0, 'approval' => 1, 'created_by' => $owner->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The plan gate is on the route, so the caller's plan must carry the module. */
    private function entitle(User $user): void
    {
        $planId = DB::table('plans')->insertGetId([
            'name' => 'campaigns-plan-' . $this->uniqueFixtureRef(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'modules' => 'Campaigns', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user->plan_id = $planId;
        $user->save();

        // `module_is_active()` reads `user_active_modules.module` for a non-operator: one row per
        // module name, and the row the gate reads is named in the block below, which is NOT
        // always this user's. A row for the caller is kept because a tenant OWNER's own row is
        // the one their gate reads.
        $this->activateCampaignsFor((int) $user->id);

        /*
         * AND THE GATE ALSO READS THE EMPLOYER'S ROWS, WHICH IS WHY A ROW ON THE CALLER IS NOT
         * ENOUGH. `PlanModuleCheck` ends at `ActivatedModule()`, which for any principal whose
         * `type` is not the literal 'admin' resolves `activated_modules_for_owner($user->created_by)`
         * -- the EMPLOYER's activations, not the caller's (`app/Helper/helper.php`, the
         * `$user->type != 'admin'` branch). Measured 2026-11-22: without this the two
         * operator-rooted cases below were refused `403 {"msg":"Permission denied"}` by the plan
         * gate and the list was never built, so they were measuring the gate and not the guard.
         *
         * It is also the production shape of the exposure: a staff member of the operator, or a
         * merchant who has demoted themselves, reaches this screen exactly while the principal
         * their `created_by` names has Campaigns activated. For an operator-rooted principal that
         * is the OPERATOR -- who activates modules platform-wide -- so the door is open in the
         * ordinary configuration rather than in a contrived one.
         */
        if (strtolower(trim((string) $user->type)) !== 'admin' && ! empty($user->created_by)) {
            $this->activateCampaignsFor((int) $user->created_by);
        }
    }

    /**
     * One activation row, and the memo that answers from it dropped.
     *
     * `activated_modules_for_owner()` memoises per owner in the CONTAINER, which in a test run
     * lives for the whole test method -- so a row inserted after anything has already asked that
     * owner's question would not be seen. Dropping the resolved instance leaves the binding
     * registered and makes the next call re-read the table.
     */
    private function activateCampaignsFor(int $userId): void
    {
        if (! DB::table('user_active_modules')->where('user_id', $userId)->where('module', 'Campaigns')->exists()) {
            DB::table('user_active_modules')->insert([
                'user_id' => $userId, 'module' => 'Campaigns',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        app()->forgetInstance("lynomia.activated_modules.owner.{$userId}");
    }

    /** @return array{0: User, 1: User, 2: int, 3: int} owner, their staff, own campaign id, the other tenant's */
    private function twoTenants(): array
    {
        $this->enable();

        [$owner, $store] = $this->makeTenant();
        [$rival, $rivalStore] = $this->makeTenant();

        $staff = $this->makeStaff($owner, ['current_store' => $store->id]);

        foreach ([$owner, $staff] as $user) {
            $this->grantPermission($user, 'Manage Campaigns');
            $this->entitle($user);
        }

        $mine = $this->campaignFor($owner, $store, 'My Own Campaign');
        $theirs = $this->campaignFor($rival, $rivalStore, 'RIVAL SECRET CAMPAIGN');

        return [$owner->fresh(), User::find($staff->id), $mine, $theirs];
    }

    /**
     * The rows the list SERVES, decoded.
     *
     * `Accept: application/json` as well as `X-Requested-With`, and the difference is not
     * cosmetic: without it yajra renders the page SHELL, whose filter dropdowns contain the
     * caller's own product names. The first version of this file sent only `X-Requested-With`,
     * asserted on a substring of that shell, and five of its six cases passed against the
     * UNFIXED code -- the owner's dropdown happened to contain the campaign's product name, and
     * the rival's did not. Measured before trusting either answer.
     *
     * @return list<array<string, mixed>> the `data` array of the draw
     */
    private function rowsServedTo(User $user, array $filters = []): array
    {
        $response = $this->actingAs($user)->get(self::URI . '?' . http_build_query($filters + [
            'draw' => 1, 'start' => 0, 'length' => 100,
        ]), ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);

        $this->assertSame(200, $response->getStatusCode(),
            'PREMISE: the campaigns list did not answer for this principal, so nothing below is '
            . 'measuring what it serves. Body: ' . substr(strip_tags((string) $response->getContent()), 0, 300));

        $payload = json_decode((string) $response->getContent(), true);

        $this->assertIsArray($payload['data'] ?? null,
            'PREMISE: the response is not a DataTables draw, so this is reading the page shell '
            . 'rather than the rows the list serves.');

        return $payload['data'];
    }

    /** Every piece of text in the rows served, so a leak is caught whichever column carries it. */
    private function textServedTo(User $user, array $filters = []): string
    {
        return json_encode($this->rowsServedTo($user, $filters), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    public function test_a_merchant_owner_sees_their_own_campaign(): void
    {
        [$owner, , ,] = $this->twoTenants();

        $this->assertStringContainsString('My Own Campaign', $this->textServedTo($owner),
            'POSITIVE CONTROL: the owner cannot see their own campaign, so a guard that empties the list would pass every refusal below.');
    }

    public function test_a_merchant_owner_is_not_served_another_tenants_campaign(): void
    {
        [$owner, , ,] = $this->twoTenants();

        $this->assertStringNotContainsString('RIVAL SECRET CAMPAIGN', $this->textServedTo($owner),
            "Another tenant's campaign reached a merchant owner.");
    }

    public function test_staff_see_their_own_tenants_campaign(): void
    {
        [, $staff, ,] = $this->twoTenants();

        $this->assertStringContainsString('My Own Campaign', $this->textServedTo($staff),
            'POSITIVE CONTROL: staff cannot see their own tenant\'s campaign, so the refusal below proves nothing.');
    }

    public function test_staff_are_not_served_another_tenants_campaign(): void
    {
        [, $staff, ,] = $this->twoTenants();

        $this->assertStringNotContainsString('RIVAL SECRET CAMPAIGN', $this->textServedTo($staff),
            "Another tenant's campaign reached a merchant's staff member. The vendored list applies "
            . "its tenant predicate only when `users.type` is the literal 'admin', which is a "
            . "merchant OWNER's type; a staff member's type is the name of their role.");
    }

    /** THE COUNT THE LIST REPORTS IS THE CALLER'S OWN, which a substring assertion cannot see. */
    public function test_staff_are_served_exactly_their_own_tenants_campaigns(): void
    {
        [, $staff, ,] = $this->twoTenants();

        $this->assertCount(1, $this->rowsServedTo($staff),
            'The list served a merchant\'s staff member a different number of campaigns than their '
            . 'own tenant has. Measured before the fix: 2 of 2, both tenants\'.');
    }

    /**
     * THE PRINCIPAL WHOSE `creatorId()` IS THE OPERATOR'S, which is the case the first version of
     * this guard got WRONG — and it got it wrong in a way the vendored line did not have.
     *
     * A merchant can change their own `type` to a role name through the product; CLAUDE.md records
     * that reading the resulting `created_by = 1` as a tenant was once an account takeover. For
     * such a principal `creatorId()` returns the OPERATOR's id.
     *
     *   the vendored line (`type == 'admin'`)  ->  no predicate at all: EVERY tenant's campaigns
     *   my first fix (`creatorId()`)           ->  the OPERATOR's campaigns
     *   this                                   ->  nothing, until their own type is a tenant root
     *
     * So the first fix narrowed the exposure and did not close it. The predicate now comes from
     * `UserPolicy::merchantTenantRoot()`, which returns `creatorId()` only when it names a
     * type-`admin` merchant and null otherwise — the helper the P1-SEC-66/67 family built for
     * exactly this — and a null fails closed. Staff the OPERATOR created are the same shape and
     * are covered by the same rule.
     *
     * @dataProvider principalsRootedAtTheOperator
     */
    public function test_a_principal_whose_tenant_root_is_the_operator_is_served_nothing(string $kind): void
    {
        $this->enable();

        $operator = $this->makePlatformAdmin();

        [$rival, $rivalStore] = $this->makeTenant();
        $this->campaignFor($rival, $rivalStore, 'RIVAL SECRET CAMPAIGN');
        $this->campaignFor($operator, $rivalStore, 'THE OPERATORS OWN CAMPAIGN');

        [$merchant, $store] = $this->makeTenant();
        $mine = $this->campaignFor($merchant, $store, 'My Own Campaign');

        if ($kind === 'staff the operator created') {
            $principal = $this->makeStaff($operator, ['current_store' => $store->id]);
        } else {
            // Through Eloquent: `User` is Cachable, so a raw write would leave the old type served.
            $merchant->type = 'manager';
            $merchant->save();
            $principal = $merchant->fresh();
        }

        $this->grantPermission($principal, 'Manage Campaigns');
        $this->entitle($principal);

        $this->assertSame((int) $operator->id, (int) $principal->fresh()->creatorId(),
            "PREMISE: this principal's `creatorId()` is not the operator's id, so the case is an "
            . 'ordinary merchant under another name and measures nothing.');

        $served = $this->textServedTo(User::find($principal->id));

        $this->assertStringNotContainsString('RIVAL SECRET CAMPAIGN', $served,
            "A {$kind} was served another tenant's campaign.");
        $this->assertStringNotContainsString('THE OPERATORS OWN CAMPAIGN', $served,
            "A {$kind} was served the PLATFORM OPERATOR's own campaign — which is what scoping to "
            . '`creatorId()` alone does for a principal whose root is the operator.');
        $this->assertStringNotContainsString('My Own Campaign', $served,
            "A {$kind} was served a campaign. Their tenant root is not a merchant, so the guard "
            . 'has no tenant to scope to and must serve nothing.');
    }

    /**
     * THE TENANT PREDICATE MUST BIND WITH *AND*, WHICH ONLY A FILTERED REQUEST CAN SHOW.
     *
     * The list's filter bar sends `user`, `store` and `product` as plain ids, and the vendored
     * `query()` turns each into an equality with no ownership check — so a request may name
     * ANOTHER TENANT'S store, user or product id, and nothing but the tenant predicate stands
     * between that id and the row.
     *
     * WRITTEN BECAUSE A MUTATION SURVIVED. Changing `where('created_by', …)` to
     * `orWhere('created_by', …)` passed all twelve earlier cases: none of them sent a filter, so
     * `created_by = ?` was the query's only condition and Laravel renders the first boolean as the
     * leading one either way. With a filter present the same mutation compiles to
     * `store = <rival> OR created_by = <me>` and serves the rival's row. The three fields are
     * driven separately because the predicate binds once per request, not once per field.
     *
     * @dataProvider filterBarFieldsNamingAnotherTenant
     */
    public function test_a_filter_naming_another_tenants_row_serves_nothing(string $field): void
    {
        $this->enable();

        [$owner, $store] = $this->makeTenant();
        [$rival, $rivalStore] = $this->makeTenant();

        $staff = $this->makeStaff($owner, ['current_store' => $store->id]);

        foreach ([$owner, $staff] as $user) {
            $this->grantPermission($user, 'Manage Campaigns');
            $this->entitle($user);
        }

        $this->campaignFor($owner, $store, 'My Own Campaign');
        $theirs = $this->campaignFor($rival, $rivalStore, 'RIVAL SECRET CAMPAIGN');

        $rivalRow = DB::table('campaigns')->where('id', $theirs)->first(['user', 'store', 'product']);

        $this->assertNotEmpty($rivalRow->{$field},
            "PREMISE: the rival campaign has no `{$field}`, so filtering on it measures nothing.");

        $served = $this->rowsServedTo(User::find($staff->id), [$field => $rivalRow->{$field}]);

        $this->assertStringNotContainsString(
            'RIVAL SECRET CAMPAIGN',
            json_encode($served, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            "Filtering the list by another tenant's `{$field}` served that tenant's campaign: the "
            . 'tenant predicate is not binding with AND.'
        );

        $this->assertCount(0, $served,
            "Filtering by another tenant's `{$field}` served rows. The caller's own tenant has no "
            . 'campaign with that value, so the only rows this can be serving are somebody else\'s.');
    }

    /** @return array<string, array{0: string}> */
    public static function filterBarFieldsNamingAnotherTenant(): array
    {
        return ['user' => ['user'], 'store' => ['store'], 'product' => ['product']];
    }

    /**
     * AND A FILTERED REQUEST STILL SERVES THE CALLER'S OWN ROW — the predicate and the filter
     * compose rather than cancelling each other out.
     *
     * ITS FIRST DOCBLOCK CLAIMED MORE THAN IT HOLDS, and the claim was measured false. It said this
     * case proves the refusals above are not passing on a broken filter. They are not, but this is
     * not what shows it: dropping the vendored filter loop entirely leaves THIS case passing (the
     * caller's own campaign is served either way) and is caught by the refusal case's own
     * `assertCount(0)`, which then sees the caller's unfiltered row. Measured both ways 2026-11-22.
     */
    public function test_a_filter_naming_the_callers_own_store_still_serves_their_campaign(): void
    {
        $this->enable();

        [$owner, $store] = $this->makeTenant();
        [$rival, $rivalStore] = $this->makeTenant();

        $staff = $this->makeStaff($owner, ['current_store' => $store->id]);

        foreach ([$owner, $staff] as $user) {
            $this->grantPermission($user, 'Manage Campaigns');
            $this->entitle($user);
        }

        $this->campaignFor($owner, $store, 'My Own Campaign');
        $this->campaignFor($rival, $rivalStore, 'RIVAL SECRET CAMPAIGN');

        $served = $this->textServedTo(User::find($staff->id), ['store' => $store->id]);

        $this->assertStringContainsString('My Own Campaign', $served,
            'POSITIVE CONTROL: filtering by the caller\'s own store served nothing, so the refusals '
            . 'above would pass on a filter that matches no row at all.');
        $this->assertStringNotContainsString('RIVAL SECRET CAMPAIGN', $served);
    }

    /**
     * THE OPERATOR STILL SEES EVERY TENANT'S CAMPAIGNS, which is the shipped intent and not a leak.
     *
     * The vendored `index()` gives a `super admin` a platform-wide store, product and user filter
     * list where every other principal gets `created_by = creatorId()` lists, and campaigns carry
     * an `approval` column the operator decides -- so the cross-tenant view is the feature. The
     * guard therefore adds NO predicate for the operator, and this is the case that holds that
     * branch: without it, scoping everybody would pass every refusal in this file.
     *
     * No permission grant and no plan row: the module's `index()` checks neither (`!$user` is its
     * only refusal) and `PlanModuleCheck` returns early for `type == 'super admin'`.
     */
    public function test_the_operator_is_served_every_tenants_campaign(): void
    {
        $this->enable();

        $operator = $this->makePlatformAdmin();

        [$oneMerchant, $oneStore] = $this->makeTenant();
        [$another, $anotherStore] = $this->makeTenant();

        $this->campaignFor($oneMerchant, $oneStore, 'My Own Campaign');
        $this->campaignFor($another, $anotherStore, 'RIVAL SECRET CAMPAIGN');

        $served = $this->textServedTo(User::find($operator->id));

        $this->assertStringContainsString('My Own Campaign', $served,
            'The platform operator was not served a tenant\'s campaign. The operator approves '
            . 'campaigns, so scoping them out breaks the approval workflow the module ships.');
        $this->assertStringContainsString('RIVAL SECRET CAMPAIGN', $served,
            'The platform operator was served one tenant\'s campaigns but not another\'s.');
        $this->assertCount(2, $this->rowsServedTo(User::find($operator->id)),
            'The operator\'s list is not the whole platform\'s.');
    }

    /** @return array<string, array{0: string}> */
    public static function principalsRootedAtTheOperator(): array
    {
        return [
            'staff the operator created' => ['staff the operator created'],
            'a merchant who demoted themselves' => ['a merchant who demoted themselves'],
        ];
    }

    /**
     * NO SESSION, NO ROWS — AND THE ROUTE DOES NOT REQUIRE ONE.
     *
     * Measured 2026-11-22: the module's routes carry `web` and `PlanModuleCheck:Campaigns` and NO
     * `auth`. What refuses an anonymous request is the vendored controller's own
     * `if (! $user) return redirect()->back()`, which is a line in code this project does not edit.
     * So this asserts the OUTCOME rather than the mechanism: whatever refuses, no campaign is
     * served to a request with no principal.
     */
    public function test_an_anonymous_request_is_served_no_campaign(): void
    {
        $this->twoTenants();

        $response = $this->get(self::URI . '?draw=1&start=0&length=100', [
            'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json',
        ]);

        $this->assertStringNotContainsString('RIVAL SECRET CAMPAIGN', (string) $response->getContent(),
            'A request with no session was served a campaign.');
        $this->assertStringNotContainsString('My Own Campaign', (string) $response->getContent(),
            'A request with no session was served a campaign.');
    }

    /**
     * THE FAIL-CLOSED BRANCH, ASKED OF THE GUARD DIRECTLY — because the route cannot ask it.
     *
     * The vendored controller refuses an anonymous request before the list is built, so through
     * HTTP the guard's no-principal branch is unreachable and a mutation removing it SURVIVES the
     * case above (measured). That branch is defence in depth behind a check in code this project
     * does not control: if upstream drops it, this is what holds. Asserted on the compiled SQL,
     * which is the only place the difference between "scoped to nobody" and "not scoped" shows.
     */
    public function test_with_no_principal_the_guard_scopes_to_nothing_rather_than_to_everything(): void
    {
        $this->enable();

        // assertGuest() takes only a guard name -- a second argument is silently discarded, which
        // is how this premise read as asserted while carrying no message at all (review, 2026-11-22).
        $this->assertFalse(auth('web')->check(),
            'PREMISE: a user is signed in, so this is not the no-principal case.');

        $guard = new \App\DataTables\TenantScopedCampaignsData();

        $sql = $guard->query(new \Workdo\Campaigns\app\Models\Campaigns(), new \Illuminate\Http\Request())->toSql();

        $this->assertStringContainsString('"created_by" = ?', $sql,
            'With no authenticated principal the campaigns list is built with NO tenant predicate, '
            . 'so it would serve every tenant\'s campaigns to whatever reached it.');

        $this->assertSame([0], $guard->query(new \Workdo\Campaigns\app\Models\Campaigns(), new \Illuminate\Http\Request())->getBindings(),
            'The no-principal predicate is bound to something other than "no tenant", so it may '
            . 'match real rows.');
    }

    /**
     * THE LIST WRITES. The vendored `query()` ends by saving a status onto every expired row it
     * read, so opening the page as staff mutated other tenants' rows. Asserted on the table.
     */
    public function test_opening_the_list_does_not_change_another_tenants_campaign(): void
    {
        [, $staff, , $theirs] = $this->twoTenants();

        // An EXPIRED campaign of the other tenant, which is what the vendored loop rewrites.
        $expired = DB::table('campaigns')->where('id', $theirs)->update(['end_date' => '2020-01-01']);
        $this->assertSame(1, $expired, 'PREMISE: the rival campaign was not made expired.');

        $before = DB::table('campaigns')->where('id', $theirs)->value('status');
        $this->assertSame(0, (int) $before, 'PREMISE: the rival campaign is not in the status this test watches.');

        $this->rowsServedTo($staff);

        $this->assertSame(0, (int) DB::table('campaigns')->where('id', $theirs)->value('status'),
            "Opening the campaigns list changed ANOTHER TENANT'S campaign. The vendored query saves "
            . 'a status onto every expired row it returned, so an unscoped read is also an '
            . 'unscoped write.');
    }

    /** And the expiry still works for the caller's own rows, which is the behaviour being preserved. */
    public function test_opening_the_list_still_expires_the_callers_own_campaign(): void
    {
        [$owner, , $mine,] = $this->twoTenants();

        DB::table('campaigns')->where('id', $mine)->update(['end_date' => '2020-01-01']);

        $this->rowsServedTo($owner);

        $this->assertSame(2, (int) DB::table('campaigns')->where('id', $mine)->value('status'),
            'The list no longer marks the caller\'s own expired campaign as expired, so the guard '
            . 'removed behaviour rather than narrowing it.');
    }
}
