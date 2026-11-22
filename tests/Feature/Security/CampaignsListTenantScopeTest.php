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
 * `App\DataTables\SafeAllCampaignsData` is bound over the module's class in `AppServiceProvider`,
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

        // `module_is_active()` reads `user_active_modules.module` for a non-operator, one row per
        // module name. Keyed on the USER, so staff need their own row.
        DB::table('user_active_modules')->insert([
            'user_id' => $user->id, 'module' => 'Campaigns',
            'created_at' => now(), 'updated_at' => now(),
        ]);
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
    private function rowsServedTo(User $user): array
    {
        $response = $this->actingAs($user)->get(self::URI . '?' . http_build_query([
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
    private function textServedTo(User $user): string
    {
        return json_encode($this->rowsServedTo($user), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
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

        $this->assertGuest('web', 'PREMISE: a user is signed in, so this is not the no-principal case.');

        $guard = new \App\DataTables\TenantScopedCampaignsData();

        $sql = $guard->query(new \Workdo\Campaigns\app\Models\Campaigns(), new \Illuminate\Http\Request())->toSql();

        $this->assertStringContainsString('"created_by" = ?', $sql,
            'With no authenticated principal the campaigns list is built with NO tenant predicate, '
            . 'so it would serve every tenant\'s campaigns to whatever reached it.');
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
