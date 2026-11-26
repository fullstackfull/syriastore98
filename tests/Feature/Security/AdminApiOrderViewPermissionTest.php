<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\OrderBillingDetail;
use App\Models\OrderNote;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-142 / P4-F106 — `GET api/admin/vieworder` HAD NO PERMISSION CHECK AT ALL.
 * =============================================================================================
 *
 * The route the shipped Flutter app opens an order with. `resolveOwnedStore()` settles TENANCY
 * correctly — a foreign store id resolves nothing — and the order lookup is scoped by
 * `store_id`. What was missing is the other axis entirely: **any** authenticated member of the
 * store could read the whole order, its billing details and every internal order note, holding no
 * order permission of any kind.
 *
 * That is PII and it is the merchant's own employee-level access control, not a cross-tenant
 * boundary. The register has carried it as P4-F106 since Phase 04 with the next action "gate the
 * remaining routes with the operator-first predicate already in `MerchantPermissions::allowsAny`",
 * which is what this does.
 *
 * ---------------------------------------------------------------------------------------------
 * WHY THE GATE IS SHAPED THE WAY IT IS, since three principals must keep working.
 *
 *   THE PLATFORM OPERATOR. `isAbleTo()` alone REFUSES them — there is no super-admin bypass in
 *   this codebase and operator status is a string on `users.type`. `MerchantPermissions` answers
 *   `WILDCARD` for the operator precisely so a gate built on it does not lock out the one
 *   principal who may do everything, and `resolveOwnedStore()` already admits them to any store.
 *
 *   THE STORE'S OWN OWNER. `callerIsStoreOwner()` is a separate limb and not redundant: a merchant
 *   does not implicitly hold the order permissions, as `AdminApiOrderDeletePermissionTest` records,
 *   and the known-outcome gate once caught a gate in this family refusing the store's own owner.
 *
 *   STAFF WHO HOLD AN ORDER PERMISSION. Both `Show Order` and `Manage Order` admit.
 *
 * ---------------------------------------------------------------------------------------------
 * THE NAMES ARE THE SINGULAR ONES, AND THE API IS DELIBERATELY WIDER THAN THE WEB HERE.
 *
 * `Utility::addNewData()` seeds two order families: the SINGULAR `Manage Order` / `Show Order` /
 * `Delete Order` in the merchant block (beside Coupon, Newsletter, Marketing) and the PLURAL
 * `Manage Orders` / `Show Orders` / `Delete Orders` in the platform block (beside Manage
 * Dashboard, Manage Plan). The merchant-side names are the singular ones — `DeleteOrder`'s shipped
 * gate uses `Delete Order` and the web order screen uses `Manage Order` — so a gate naming the
 * plural set would admit nobody a merchant can actually grant.
 *
 * TWO CLAIMS OF MINE THE INDEPENDENT REVIEW CORRECTED, kept here because a wrong REASON invites a
 * future reader to delete the gate:
 *
 *   I wrote that a gate naming the PLURAL family "would admit nobody a merchant can actually
 *   grant". False — both families become real `Permission` rows and `RoleController::create` lists
 *   `Permission::all()`, so a merchant can tick either spelling. The true and stronger fact is that
 *   **no gate in the codebase reads the plural names**: they occur nowhere in `app/` outside the two
 *   seeding arrays, while every order surface checks the singular ones.
 *
 *   I wrote that admitting both names is "deliberately WIDER than the web", the web's single-order
 *   view gating on `Show Order` alone. True of the web and misleading as a risk: this route's own
 *   API siblings — the order LIST (`DashboardController:1639`) and `Top_orders` (`:7920`) — already
 *   admit on EITHER name, and the list is the only way the shipped app reaches a detail view. So
 *   `Manage Order` admits nobody who cannot already read that order's `final_price`,
 *   `payment_status` and customer name in the list row. **Not an over-admission on any reachable
 *   path**, which is a better justification than the one I gave.
 *
 * ONE ASYMMETRY THE REVIEW SURFACED, recorded not resolved: the sibling list refuses through
 * `refuseWithoutPermission()`, which has NO owner limb — so a merchant who edited their own role to
 * drop both names could open an order here and not list it. The owner limb stays, because refusing
 * a store's own owner is the regression this family has already produced once. Normalising the two
 * belongs with GAP-142's remaining eight rows.
 */
class AdminApiOrderViewPermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Distinctive strings, so a disclosure assertion cannot pass on a coincidence. */
    private const BUYER_EMAIL = 'pii-buyer@example.test';

    private const INTERNAL_NOTE = 'INTERNAL-NOTE-CUSTOMER-IS-A-CHARGEBACK-RISK';

    /** @return array{0:User,1:Store,2:Order} */
    private function tenantWithAnOrder(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        $order = $this->makeOrder($store, [
            'delivered_status' => 0,
            'payment_status' => 'Unpaid',
        ]);

        /* THE PII TABLES ASSERTED FIRST, or a missing table makes every disclosure assertion below
           answer about something that is not there. */
        foreach (['order_billing_details', 'order_notes'] as $table) {
            $this->assertTrue(Schema::hasTable($table),
                $table . ' does not exist in the test schema, so the assertions about it are vacuous');
        }

        (new OrderBillingDetail())->forceFill([
            'order_id' => $order->id,
            'product_order_id' => $order->product_order_id,
            'first_name' => 'Buyer',
            'last_name' => 'Person',
            'email' => self::BUYER_EMAIL,
        ])->save();

        (new OrderNote())->forceFill([
            'order_id' => $order->id,
            'store_id' => $store->id,
            'notes' => self::INTERNAL_NOTE,
            'note_type' => 'private',
            'status' => 1,
        ])->save();

        return [User::find($merchant->id), $store, $order];
    }

    private function staffIn(User $owner, Store $store, ?string $permission = null): User
    {
        $staff = $this->makeStaff($owner);
        $staff->current_store = $store->id;
        $staff->save();

        if ($permission !== null) {
            $this->grantPermission(User::find($staff->id), $permission);
        }

        return User::find($staff->id);
    }

    private function openOrder(User $user, Store $store, Order $order)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        )->getJson('/api/admin/vieworder?store_id=' . $store->id . '&order_id=' . $order->id);
    }

    // ═════════════════════════════════════════════════════════════════════════════ THE DEFECT

    /** A staff member holding NO order permission read the order, its billing PII and its notes. */
    public function test_a_staff_member_without_an_order_permission_cannot_read_an_order(): void
    {
        [$merchant, $store, $order] = $this->tenantWithAnOrder();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->openOrder($staff, $store, $order);

        $this->assertSame(403, $response->status(),
            'a staff member holding no order permission read a whole order through the admin API. '
            . 'resolveOwnedStore() settles tenancy and settles nothing about WHO in the store may '
            . 'read it.');

        /*
         * AND THE PII IS ABSENT FROM THE BODY, asserted separately from the status. A refusal that
         * still serialised the order would be a 403 with the disclosure intact, and a status-only
         * assertion could not tell the two apart.
         */
        $response->assertDontSee(self::BUYER_EMAIL, false);
        $response->assertDontSee(self::INTERNAL_NOTE, false);
    }

    /** 403, NEVER 401: the shipped Flutter app force-clears its session on any 401. */
    public function test_the_refusal_is_403_so_the_shipped_app_does_not_log_the_merchant_out(): void
    {
        [$merchant, $store, $order] = $this->tenantWithAnOrder();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->openOrder($staff, $store, $order);

        /* THIS FIRST ASSERTION IS DECORATIVE AND THE REVIEW SAID SO: it passes against a 200 as
           well as a 403, so it cannot fail if the gate is removed. It is kept because it names the
           hazard -- a 401 here force-clears the shipped app's session -- and the line below is what
           actually carries the case. A 403 on this route can ONLY come from
           `orderPermissionDenied()`, because every other refusal in the handler is
           `$this->error()` at HTTP 200, so `assertSame(403)` is specific to the control rather than
           merely "not 200". */
        $this->assertNotSame(401, $response->status(),
            'a 401 here logs the merchant out of the whole app, which is a user-visible regression '
            . 'in a different product -- ApiResponser::error() also answers HTTP 200 by default, so '
            . 'the refusal must be an explicit response()->json(..., 403)');
        $this->assertSame(403, $response->status());
    }

    // ═══════════════════════════════════════════════════════════════════════════ THE CONTROLS

    /** The store's own owner still reads their own order, holding no order permission row. */
    public function test_the_store_owner_still_reads_their_own_order(): void
    {
        [$merchant, $store, $order] = $this->tenantWithAnOrder();

        $response = $this->openOrder($merchant, $store, $order);

        $this->assertSame(200, $response->status(),
            'the gate refused the store\'s OWN owner, which is the exact regression the '
            . 'known-outcome gate caught once already in this family');
    }

    /** Staff who hold `Show Order` still read it — otherwise the gate names a permission nobody has. */
    public function test_staff_holding_show_order_still_read_it(): void
    {
        [$merchant, $store, $order] = $this->tenantWithAnOrder();
        $staff = $this->staffIn($merchant, $store, 'Show Order');

        $response = $this->openOrder($staff, $store, $order);

        $this->assertSame(200, $response->status(),
            'a staff member who DOES hold `Show Order` was refused, so the gate names a permission '
            . 'that does not admit anyone');
        $response->assertSee(self::BUYER_EMAIL, false);
    }

    /** And `Manage Order`, the broader grant the web order LIST uses. */
    public function test_staff_holding_manage_order_still_read_it(): void
    {
        [$merchant, $store, $order] = $this->tenantWithAnOrder();
        $staff = $this->staffIn($merchant, $store, 'Manage Order');

        $this->assertSame(200, $this->openOrder($staff, $store, $order)->status(),
            'staff holding `Manage Order` were refused. That is DEFENSIBLE -- the web single-order '
            . 'view gates on `Show Order` alone -- but it refuses staff who can open an order in the '
            . 'shipped app today, so it is a product decision and not one to take by analogy here.');
    }

    /** The platform operator, whom `isAbleTo()` alone refuses, still reads it. */
    public function test_the_platform_operator_still_reads_it(): void
    {
        [, $store, $order] = $this->tenantWithAnOrder();

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $this->assertSame(200, $this->openOrder($operator, $store, $order)->status(),
            'the platform operator was refused. `isAbleTo()` has no super-admin bypass, which is '
            . 'why the gate goes through MerchantPermissions -- it answers WILDCARD for the operator.');
    }

    // ══════════════════════════════════════════════════════════════════ TENANCY IS UNCHANGED

    /**
     * The pre-existing tenancy control must still hold, and must not be MISTAKEN for the new one.
     * A member of another tenant holding `Show Order` is refused by `resolveOwnedStore()` long
     * before any permission question — so this case proves the two axes are independent rather
     * than that either works.
     */
    public function test_a_member_of_another_tenant_is_still_refused_even_holding_the_permission(): void
    {
        [, $store, $order] = $this->tenantWithAnOrder();

        $outsiderMerchant = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsiderMerchant);
        $outsider = $this->staffIn($outsiderMerchant, $outsiderStore, 'Show Order');

        $response = $this->openOrder($outsider, $store, $order);

        /*
         * ASSERTED ON THE ENVELOPE, NOT ON THE STATUS, and the first version of this case got it
         * wrong: it asserted `assertNotSame(200, ...)` and FAILED against working code, because
         * `ApiResponser::error()` answers **HTTP 200** with `{status: 0}` by default
         * (`app/Traits/ApiResponser.php:54`). The tenancy refusal was working perfectly; the
         * assertion was reading the wrong field. That is the same trap in reverse as a 500
         * satisfying a "not 200" assertion, and on this API the status code is nearly always 200.
         *
         * It also settles the shape the NEW gate must use: an explicit `response()->json(..., 403)`
         * like `DeleteOrder`'s, because `$this->error()` would refuse with a 200 that no client
         * distinguishes from success.
         */
        $this->assertSame(0, $response->json('status'),
            'a member of another tenant was answered with a success envelope for this store\'s order '
            . 'while holding Show Order in their OWN store');
        $response->assertDontSee(self::BUYER_EMAIL, false);
        $response->assertDontSee(self::INTERNAL_NOTE, false);
    }
}
