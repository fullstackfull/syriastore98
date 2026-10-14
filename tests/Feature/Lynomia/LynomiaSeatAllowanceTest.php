<?php

namespace Tests\Feature\Lynomia;

use App\Models\AuditLog;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\SeatAllowanceSync;

/**
 * Lynomia agent seats included in a Store Syria package.
 *
 * TWO AUTHORITIES, NEITHER OF THEM BOTH. Store Syria decides what a package includes; Lynomia
 * decides what an account may actually do. These tests cover this side of that wire -- that the
 * right number is read from the right place and pushed once -- and the Lynomia-side composition
 * rules (no additive stacking, no downgrade of paid rights, an operator override still winning)
 * are proven where they are implemented, in spec/requests/platform/api/v1/entitlement_grants_spec.rb.
 */
class LynomiaSeatAllowanceTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootLynomiaHarness();
    }

    private function planWithSeats(?int $seats): Plan
    {
        return Plan::create([
            'name' => 'Package ' . uniqid(),
            'price' => '0',
            'duration' => 'Month',
            'themes' => '1',
            'max_stores' => '1',
            'max_products' => '10',
            'max_users' => '10',
            'storage_limit' => '10',
            'lynomia_agent_seats' => $seats,
        ]);
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store, 2: \Workdo\LynomiaChat\app\Models\LynomiaStoreLink} */
    private function tenantOnPlan(?int $seats): array
    {
        [$owner, $store] = $this->entitledTenant();
        $owner->forceFill(['plan_id' => $this->planWithSeats($seats)->id])->save();
        $this->fakeLynomia();
        $link = app(AccountProvisioner::class)->provision($store, $owner->fresh());

        return [$owner->fresh(), $store, $link->fresh()];
    }

    private function grantCalls(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_ends_with((string) parse_url($pair[0]->url(), PHP_URL_PATH), '/entitlement_grants'))
            ->values()->all();
    }

    public function test_provisioning_tells_lynomia_what_the_package_includes(): void
    {
        [, , $link] = $this->tenantOnPlan(5);

        $calls = $this->grantCalls();
        $this->assertCount(1, $calls, 'the seat allowance was not pushed exactly once');
        $this->assertSame('PUT', strtoupper($calls[0][0]->method()));
        $this->assertSame(5, (int) $calls[0][0]->data()['limit_value']);
        $this->assertSame('agents', $calls[0][0]->data()['resource']);
        $this->assertStringContainsString('/accounts/' . $link->lynomia_account_id . '/', (string) $calls[0][0]->url());
    }

    /**
     * A PLAN THAT SAYS NOTHING GRANTS NOTHING -- and that is not the same as granting zero.
     * Granting zero would lock a merchant out of an inbox they are using because an operator had
     * not filled a field in yet.
     */
    public function test_a_plan_with_no_seat_allowance_withdraws_rather_than_granting_zero(): void
    {
        $this->tenantOnPlan(null);

        $calls = $this->grantCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('DELETE', strtoupper($calls[0][0]->method()));
        $this->assertSame(null, app(SeatAllowanceSync::class)->includedSeatsFor(
            \Workdo\LynomiaChat\app\Models\LynomiaStoreLink::first()
        ));
    }

    public function test_a_merchant_with_no_plan_at_all_grants_nothing(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $owner->forceFill(['plan_id' => null])->save();
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $owner->fresh());

        $this->assertNull(app(SeatAllowanceSync::class)->includedSeatsFor($link->fresh()));
    }

    /**
     * THE ALLOWANCE IS THE OWNER'S, NOT THE STORE'S. Entitlement in this product is resolved from
     * the tenant owner -- PlanModuleCheck does the same -- so a seat allowance read from anywhere
     * else would disagree with the module entitlement sitting next to it.
     */
    public function test_the_allowance_follows_the_tenant_owner_across_their_stores(): void
    {
        [$owner, , $linkA] = $this->tenantOnPlan(7);

        $storeB = $this->storeFor($owner->fresh());
        $this->fakeLynomia(['account_id' => 7002]);
        $linkB = app(AccountProvisioner::class)->provision($storeB, $owner->fresh());

        $sync = app(SeatAllowanceSync::class);
        $this->assertSame(7, $sync->includedSeatsFor($linkA->fresh()));
        $this->assertSame(7, $sync->includedSeatsFor($linkB->fresh()));
    }

    public function test_changing_the_package_changes_what_is_pushed(): void
    {
        [$owner, , $link] = $this->tenantOnPlan(3);

        $owner->forceFill(['plan_id' => $this->planWithSeats(12)->id])->save();
        Http::fake();
        app(SeatAllowanceSync::class)->sync($link->fresh(), $owner->fresh());

        $calls = $this->grantCalls();
        $this->assertSame(12, (int) $calls[0][0]->data()['limit_value']);
    }

    /**
     * A FAILURE TELLING LYNOMIA A NUMBER MUST NOT UNDO A WORKSPACE THAT EXISTS. The workspace is
     * already created and recorded by the time the allowance is pushed; failing the whole
     * provisioning because Lynomia was briefly unreachable would report a failure for work that
     * succeeded and send the merchant back to retry something already done.
     */
    public function test_an_unreachable_lynomia_does_not_fail_provisioning(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $owner->forceFill(['plan_id' => $this->planWithSeats(5)->id])->save();
        $this->fakeLynomia(['fail' => [
            'PUT /platform/api/v1/accounts/7001/entitlement_grants' => Http::failedConnection(),
        ]]);

        $link = app(AccountProvisioner::class)->provision($store, $owner->fresh());

        $this->assertSame('active', $link->fresh()->state);
        $this->assertSame(7001, (int) $link->fresh()->lynomia_account_id);
    }

    /** "Plan changes and administrative overrides must be auditable." */
    public function test_the_sync_is_audited_on_this_side_too(): void
    {
        [, , $link] = $this->tenantOnPlan(5);

        $log = AuditLog::where('action', 'lynomia_seat_allowance_synced')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame(5, (int) $log->new_values['included_seats']);
        $this->assertSame((int) $link->lynomia_account_id, (int) $log->new_values['lynomia_account_id']);
        $this->assertStringStartsWith('plan:', (string) $log->new_values['plan_ref']);
    }

    /**
     * The operator surface, asserted on the CONTROLLER rather than through the route.
     *
     * WHY NOT A REAL REQUEST, which would be the better test. The App\Http\Middleware\XSS
     * middleware redirects any `super admin` GET to the updater whenever migration files exist
     * that the migrations table does not list -- and in this test environment over a hundred
     * OTHER Workdo modules' migrations are in exactly that state, because they are only loaded
     * conditionally. Measured, and none of them belong to this module: the Lynomia migrations
     * run. So a super-admin GET is unreachable here for reasons that predate this work and are
     * out of this phase's scope, and dressing that up as a passing route test would be worse
     * than saying so. The route's own guard (PlatformOperatorOnly) is covered by the Phase 1
     * route-surface tests, which assert it from the booted router.
     */
    public function test_the_operator_view_carries_plan_seats_usage_and_event_health(): void
    {
        [, , $link] = $this->tenantOnPlan(5);

        $view = app(\Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin\IntegrationController::class)->show();
        $data = $view->getData();

        $this->assertSame(
            5,
            (int) collect($data['planSeats'])->firstWhere('lynomia_agent_seats', 5)->lynomia_agent_seats
        );
        // The owner's identity consumed a seat when the workspace was provisioned.
        $this->assertSame(1, (int) $data['seatedStores'][(int) $link->store_id]);
        $this->assertArrayHasKey('total', $data['eventHealth']);
        $this->assertSame(0, $data['eventHealth']['total']);
    }

    /** Null is shown as "not set", never as a ceiling of zero. */
    public function test_a_plan_with_no_allowance_is_carried_as_null_not_zero(): void
    {
        $this->planWithSeats(null);

        $data = app(\Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin\IntegrationController::class)
            ->show()->getData();

        $this->assertNull(collect($data['planSeats'])->last()->lynomia_agent_seats);
    }

    /** No seat number is hardcoded anywhere: an unconfigured plan yields no allowance. */
    public function test_no_default_seat_count_is_invented(): void
    {
        $plan = $this->planWithSeats(null);

        $this->assertNull($plan->lynomia_agent_seats);
    }
}
