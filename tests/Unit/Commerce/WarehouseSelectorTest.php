<?php

namespace Tests\Unit\Commerce;

use App\Models\Role;
use App\Models\User;
use App\Services\WarehouseInventory;
use App\Support\Commerce\WarehouseSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `INV-007` — AUTOMATIC FULFILMENT-WAREHOUSE SELECTION, WITH AN AUTHORISED OVERRIDE.
 * =================================================================================================
 *
 * The approved decision, verbatim from `docs/development/master-plan/DECISION-ID-REGISTER.md`:
 *
 *     INV-007  اختيار مستودع التنفيذ تلقائياً مع تعديل مصرح به.
 *              Automatic fulfilment-warehouse selection, with an authorised override
 *
 * Two clauses, and they fail in opposite directions, so both are driven here. A selector with no
 * override is not `INV-007`; an override anyone may send is not «مصرح به» / *authorised*.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT "SELECTION" MEANS HERE, AND WHAT IT IS NOT.
 *
 * The answer is a **plan**: which of this store's NAMED warehouses give up how many units. The
 * default warehouse never appears in a plan line, because its figure is the derived remainder
 * (see {@see \App\Services\WarehouseInventory}) — units sourced from the default need no write at
 * all, and `$quantity − Σ(plan units)` is the default's implicit contribution.
 *
 * **THIS IS NOT `INV-008`.** «توزيع التنفيذ على عدة مستودعات وشحنات جزئية.» / *split fulfilment
 * across warehouses, and partial shipments* needs a `shipments` entity, which does not exist
 * (measured across all host and vendored migrations), and a partial shipment changes what
 * `orders.delivered_status` means — the subject of `P04-P4-03`'s open owner question `H4`. A plan
 * with two lines here is BOOKKEEPING about where units came from; it produces no shipment, no
 * second delivery state and no change to any order's lifecycle.
 *
 * -------------------------------------------------------------------------------------------------
 * THE SELECTION RULE, AND WHY A SINGLE SOURCE IS PREFERRED OVER A SPLIT.
 *
 * Active named warehouses in `priority` order, then by id for determinism. If any one of them can
 * cover the whole quantity, that one is chosen alone; otherwise the plan fills greedily. Preferring
 * a single source is what a merchant picking stock actually wants, and it makes the common case
 * exactly one allocation write.
 *
 * An INACTIVE warehouse is skipped and KEEPS its allocation, so taking a location out of service
 * does not silently move its units into the default's remainder.
 *
 * -------------------------------------------------------------------------------------------------
 * THE OVERRIDE, AND THE TWO WAYS IT IS REFUSED.
 *
 * A refusal is reported with its REASON, not merely as "the automatic plan". CLAUDE.md §3: a
 * refusal asserted without its reason is satisfied by any refusal — and here the two refusals mean
 * opposite things to an operator reading a log. A warehouse the store does not own is an attempt to
 * reach another tenant; an unauthorised principal is a permissions question about one's own store.
 *
 * Authorisation is `MerchantPermissions::allows()`, **reused and not copied**. That class is
 * operator-first (`isAbleTo()` alone REFUSES the platform operator — CLAUDE.md §1) and carries the
 * `instanceof User` guard whose absence once turned 112 storefront tests into 500s, because a
 * `Customer` or `DeliveryBoy` is not a Laratrust subject and both reach handlers through
 * `custom.auth`. Writing the check again here would have reproduced both defects.
 */
class WarehouseSelectorTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_A = 7;
    private const STORE_B = 9;

    /** PREMISE: the permission the override is gated on has a row, so the gate is not a permanent refusal. */
    public function test_the_premise_that_the_override_permission_is_a_real_permission(): void
    {
        $this->assertContains(
            WarehouseSelector::OVERRIDE_PERMISSION,
            $this->seededPermissionNames(),
            'A permission no row exists for can be held by no role, so gating on it would be a '
            . 'permanent refusal for every merchant (the tenancy skill, §1). The override must be '
            . 'gated on a name Utility::addNewData() really seeds.'
        );
    }

    /** A store with no warehouses plans nothing, and that is the whole platform as it stands. */
    public function test_a_store_with_no_warehouses_plans_nothing(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);

        $plan = WarehouseSelector::planFor(self::STORE_A, $product, 0, 3);

        $this->assertSame([], $plan['lines']);
        $this->assertSame(WarehouseSelector::NONE, $plan['basis']);
        $this->assertNull($plan['reason']);
    }

    /** A store with only a default warehouse plans nothing: the remainder sources it, with no write. */
    public function test_a_store_with_only_a_default_warehouse_plans_nothing(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);

        $plan = WarehouseSelector::planFor(self::STORE_A, $product, 0, 3);

        $this->assertSame(
            [],
            $plan['lines'],
            'the default never appears in a plan line — its figure is derived, so sourcing from it '
            . 'requires no allocation write at all'
        );
        $this->assertSame(WarehouseSelector::NONE, $plan['basis']);
    }

    /** `INV-007`: the highest-priority active warehouse that can cover the line is chosen alone. */
    public function test_the_highest_priority_warehouse_that_can_cover_the_line_is_chosen_alone(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1, 'priority' => 0]);
        $near = $this->makeWarehouse(self::STORE_A, 'Near', ['priority' => 1]);
        $far = $this->makeWarehouse(self::STORE_A, 'Far', ['priority' => 2]);

        WarehouseInventory::allocate(self::STORE_A, $near, $product, 0, 10);
        WarehouseInventory::allocate(self::STORE_A, $far, $product, 0, 10);

        $plan = WarehouseSelector::planFor(self::STORE_A, $product, 0, 4);

        $this->assertSame([['warehouse_id' => $near, 'units' => 4]], $plan['lines']);
        $this->assertSame(WarehouseSelector::AUTOMATIC, $plan['basis']);
    }

    /**
     * Priority, not insertion order, decides. The lower-priority warehouse is created FIRST on
     * purpose: a selector that happened to order by id would pass this by accident, so the two
     * orderings are made to disagree.
     */
    public function test_priority_and_not_insertion_order_decides(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1, 'priority' => 0]);
        $createdFirst = $this->makeWarehouse(self::STORE_A, 'Created first', ['priority' => 9]);
        $createdSecond = $this->makeWarehouse(self::STORE_A, 'Created second', ['priority' => 1]);

        WarehouseInventory::allocate(self::STORE_A, $createdFirst, $product, 0, 10);
        WarehouseInventory::allocate(self::STORE_A, $createdSecond, $product, 0, 10);

        $plan = WarehouseSelector::planFor(self::STORE_A, $product, 0, 4);

        $this->assertSame(
            [['warehouse_id' => $createdSecond, 'units' => 4]],
            $plan['lines'],
            'priority 1 beats priority 9 even though the priority-9 row has the lower id'
        );
    }

    /** A warehouse that cannot cover the whole line is passed over for one that can. */
    public function test_a_warehouse_that_cannot_cover_the_line_is_passed_over(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $small = $this->makeWarehouse(self::STORE_A, 'Small', ['priority' => 1]);
        $big = $this->makeWarehouse(self::STORE_A, 'Big', ['priority' => 2]);

        WarehouseInventory::allocate(self::STORE_A, $small, $product, 0, 2);
        WarehouseInventory::allocate(self::STORE_A, $big, $product, 0, 20);

        $plan = WarehouseSelector::planFor(self::STORE_A, $product, 0, 5);

        $this->assertSame(
            [['warehouse_id' => $big, 'units' => 5]],
            $plan['lines'],
            'a single source is preferred over a split, so the higher-priority warehouse holding '
            . 'too few units is skipped rather than part-filled'
        );
    }

    /** When no one warehouse can cover the line, the plan fills greedily in priority order. */
    public function test_when_no_single_warehouse_covers_the_line_the_plan_fills_greedily(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $one = $this->makeWarehouse(self::STORE_A, 'One', ['priority' => 1]);
        $two = $this->makeWarehouse(self::STORE_A, 'Two', ['priority' => 2]);

        WarehouseInventory::allocate(self::STORE_A, $one, $product, 0, 3);
        WarehouseInventory::allocate(self::STORE_A, $two, $product, 0, 3);

        $plan = WarehouseSelector::planFor(self::STORE_A, $product, 0, 5);

        $this->assertSame(
            [
                ['warehouse_id' => $one, 'units' => 3],
                ['warehouse_id' => $two, 'units' => 2],
            ],
            $plan['lines']
        );
        $this->assertSame(WarehouseSelector::AUTOMATIC, $plan['basis']);
    }

    /** A plan never promises more than the named warehouses hold; the rest is the default's. */
    public function test_a_plan_never_promises_more_than_the_named_warehouses_hold(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $one = $this->makeWarehouse(self::STORE_A, 'One', ['priority' => 1]);

        WarehouseInventory::allocate(self::STORE_A, $one, $product, 0, 2);

        $plan = WarehouseSelector::planFor(self::STORE_A, $product, 0, 9);

        $this->assertSame([['warehouse_id' => $one, 'units' => 2]], $plan['lines']);
        $this->assertSame(
            2,
            array_sum(array_column($plan['lines'], 'units')),
            'the other 7 come from the default remainder, which needs no plan line'
        );
    }

    /** An INACTIVE warehouse is skipped and keeps its allocation. */
    public function test_an_inactive_warehouse_is_skipped_and_keeps_its_units(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $closed = $this->makeWarehouse(self::STORE_A, 'Closed', ['priority' => 1, 'is_active' => 0]);
        $open = $this->makeWarehouse(self::STORE_A, 'Open', ['priority' => 2]);

        WarehouseInventory::allocate(self::STORE_A, $closed, $product, 0, 10);
        WarehouseInventory::allocate(self::STORE_A, $open, $product, 0, 10);

        $plan = WarehouseSelector::planFor(self::STORE_A, $product, 0, 4);

        $this->assertSame([['warehouse_id' => $open, 'units' => 4]], $plan['lines']);
        $this->assertSame(
            10,
            WarehouseInventory::allocationsFor(self::STORE_A, $product)[$closed],
            'the inactive warehouse keeps its units rather than losing them to the remainder'
        );
    }

    /** `INV-007` second clause: an AUTHORISED principal may override the automatic choice. */
    public function test_an_authorised_principal_may_override_the_automatic_choice(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $automatic = $this->makeWarehouse(self::STORE_A, 'Automatic', ['priority' => 1]);
        $chosen = $this->makeWarehouse(self::STORE_A, 'Chosen', ['priority' => 5]);

        WarehouseInventory::allocate(self::STORE_A, $automatic, $product, 0, 10);
        WarehouseInventory::allocate(self::STORE_A, $chosen, $product, 0, 10);

        /* PREMISE: without the override, the automatic choice really is the other warehouse. */
        $this->assertSame(
            [['warehouse_id' => $automatic, 'units' => 4]],
            WarehouseSelector::planFor(self::STORE_A, $product, 0, 4)['lines']
        );

        $plan = WarehouseSelector::planFor(
            self::STORE_A,
            $product,
            0,
            4,
            $this->merchantWithOverridePermission(self::STORE_A),
            $chosen
        );

        $this->assertSame([['warehouse_id' => $chosen, 'units' => 4]], $plan['lines']);
        $this->assertSame(WarehouseSelector::OVERRIDDEN, $plan['basis']);
        $this->assertNull($plan['reason']);
    }

    /** The platform operator may override — `isAbleTo()` alone would REFUSE them (CLAUDE.md §1). */
    public function test_the_platform_operator_may_override(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $automatic = $this->makeWarehouse(self::STORE_A, 'Automatic', ['priority' => 1]);
        $chosen = $this->makeWarehouse(self::STORE_A, 'Chosen', ['priority' => 5]);

        WarehouseInventory::allocate(self::STORE_A, $automatic, $product, 0, 10);
        WarehouseInventory::allocate(self::STORE_A, $chosen, $product, 0, 10);

        $operator = User::create([
            'name' => 'Operator',
            'email' => 'operator-warehouse@example.com',
            'password' => 'x',
            'type' => 'super admin',
        ]);

        $plan = WarehouseSelector::planFor(self::STORE_A, $product, 0, 4, $operator, $chosen);

        $this->assertSame([['warehouse_id' => $chosen, 'units' => 4]], $plan['lines']);
        $this->assertSame(WarehouseSelector::OVERRIDDEN, $plan['basis']);
    }

    /**
     * «مصرح به» / AUTHORISED: a principal without the permission is refused, by its own reason, and
     * the automatic plan stands.
     */
    public function test_an_unauthorised_principal_is_refused_and_the_automatic_plan_stands(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $automatic = $this->makeWarehouse(self::STORE_A, 'Automatic', ['priority' => 1]);
        $chosen = $this->makeWarehouse(self::STORE_A, 'Chosen', ['priority' => 5]);

        WarehouseInventory::allocate(self::STORE_A, $automatic, $product, 0, 10);
        WarehouseInventory::allocate(self::STORE_A, $chosen, $product, 0, 10);

        $plan = WarehouseSelector::planFor(
            self::STORE_A,
            $product,
            0,
            4,
            $this->merchantWithoutPermissions(),
            $chosen
        );

        $this->assertSame(
            WarehouseSelector::OVERRIDE_REFUSED,
            $plan['basis'],
            'the basis must say an override was refused, not merely report the automatic plan — '
            . 'otherwise a dropped authorisation check is indistinguishable from no override'
        );
        $this->assertSame(WarehouseSelector::REASON_NOT_PERMITTED, $plan['reason']);
        $this->assertSame([['warehouse_id' => $automatic, 'units' => 4]], $plan['lines']);
    }

    /**
     * THE GUARD-CLASS TRAP, IN THE DIRECTION THAT MATTERS HERE: a `Customer` or a `DeliveryBoy`
     * handed in as the overriding principal is REFUSED, and does not raise.
     *
     * Added by the authorization self-review against `.claude/agents/tenant-authz-reviewer.md` §1.
     * **`isAbleTo()` ON A `Customer` IS A FATAL** — neither `Customer` nor `DeliveryBoy` is a
     * Laratrust subject, and CLAUDE.md records an earlier guard that tested the *guard* instead of
     * the *class* and turned 112 storefront tests into 500s. Both classes reach handlers through
     * `custom.auth`, which logs a token's owner into whichever guard matches its tokenable class,
     * so neither is a hypothetical caller.
     *
     * `MerchantPermissions::names()`'s `instanceof User` limb is what makes this safe, and this
     * case drives it rather than trusting that it is still there. Without it the refusal below
     * would be an uncaught `Error`, not a refusal.
     */
    public function test_a_customer_or_a_delivery_boy_cannot_override_and_does_not_raise(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $automatic = $this->makeWarehouse(self::STORE_A, 'Automatic', ['priority' => 1]);
        $chosen = $this->makeWarehouse(self::STORE_A, 'Chosen', ['priority' => 5]);

        WarehouseInventory::allocate(self::STORE_A, $automatic, $product, 0, 10);
        WarehouseInventory::allocate(self::STORE_A, $chosen, $product, 0, 10);

        $customer = new \App\Models\Customer([
            'name' => 'Shopper',
            'email' => 'shopper-warehouse@example.com',
        ]);
        $customer->store_id = self::STORE_A;

        $driver = new \App\Models\DeliveryBoy([
            'name' => 'Driver',
            'email' => 'driver-warehouse@example.com',
        ]);
        $driver->store_id = self::STORE_A;

        foreach (['customer' => $customer, 'delivery boy' => $driver] as $what => $principal) {
            $plan = WarehouseSelector::planFor(self::STORE_A, $product, 0, 4, $principal, $chosen);

            $this->assertSame(
                WarehouseSelector::OVERRIDE_REFUSED,
                $plan['basis'],
                "a $what must not override the fulfilment warehouse"
            );
            $this->assertSame(WarehouseSelector::REASON_NOT_PERMITTED, $plan['reason']);
            $this->assertSame(
                [['warehouse_id' => $automatic, 'units' => 4]],
                $plan['lines'],
                'and the automatic plan stands'
            );
        }

        /* THE CONTROL: the same override from a principal who IS permitted is honoured, so the
           refusals above are about the class and not about the override being broken. */
        $this->assertSame(
            WarehouseSelector::OVERRIDDEN,
            WarehouseSelector::planFor(
                self::STORE_A,
                $product,
                0,
                4,
                $this->merchantWithOverridePermission(self::STORE_A),
                $chosen
            )['basis']
        );
    }

    /** A NULL principal with an override is refused: an override needs somebody to authorise. */
    public function test_an_override_with_no_principal_is_refused(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $chosen = $this->makeWarehouse(self::STORE_A, 'Chosen', ['priority' => 5]);

        WarehouseInventory::allocate(self::STORE_A, $chosen, $product, 0, 10);

        $plan = WarehouseSelector::planFor(self::STORE_A, $product, 0, 4, null, $chosen);

        $this->assertSame(WarehouseSelector::OVERRIDE_REFUSED, $plan['basis']);
        $this->assertSame(WarehouseSelector::REASON_NOT_PERMITTED, $plan['reason']);
    }

    /**
     * TENANCY. A warehouse belonging to ANOTHER store is refused with its own reason, even for a
     * principal who holds every permission in their own store — and it is refused BEFORE the
     * permission is asked, because no permission in store A authorises reaching store B.
     */
    public function test_another_stores_warehouse_is_refused_even_for_an_authorised_principal(): void
    {
        $productA = $this->makeProduct(self::STORE_A, 100);
        $productB = $this->makeProduct(self::STORE_B, 100);

        $this->makeWarehouse(self::STORE_A, 'A home', ['is_default' => 1]);
        $automaticA = $this->makeWarehouse(self::STORE_A, 'A depot', ['priority' => 1]);
        $depotB = $this->makeWarehouse(self::STORE_B, 'B depot', ['priority' => 1]);

        WarehouseInventory::allocate(self::STORE_A, $automaticA, $productA, 0, 10);
        WarehouseInventory::allocate(self::STORE_B, $depotB, $productB, 0, 10);

        $plan = WarehouseSelector::planFor(
            self::STORE_A,
            $productA,
            0,
            4,
            $this->merchantWithOverridePermission(self::STORE_A),
            $depotB
        );

        $this->assertSame(WarehouseSelector::OVERRIDE_REFUSED, $plan['basis']);
        $this->assertSame(
            WarehouseSelector::REASON_FOREIGN_WAREHOUSE,
            $plan['reason'],
            'the two refusals mean opposite things and must be distinguishable: a foreign '
            . 'warehouse is an attempt to reach another tenant, a missing permission is not'
        );
        $this->assertSame([['warehouse_id' => $automaticA, 'units' => 4]], $plan['lines']);
    }

    /** EVEN THE OPERATOR cannot override to another store's warehouse: the store owns the choice. */
    public function test_even_the_operator_cannot_override_to_another_stores_warehouse(): void
    {
        $productA = $this->makeProduct(self::STORE_A, 100);
        $this->makeWarehouse(self::STORE_A, 'A home', ['is_default' => 1]);
        $depotB = $this->makeWarehouse(self::STORE_B, 'B depot', ['priority' => 1]);

        $operator = User::create([
            'name' => 'Operator',
            'email' => 'operator-warehouse-2@example.com',
            'password' => 'x',
            'type' => 'super admin',
        ]);

        $plan = WarehouseSelector::planFor(self::STORE_A, $productA, 0, 4, $operator, $depotB);

        $this->assertSame(WarehouseSelector::OVERRIDE_REFUSED, $plan['basis']);
        $this->assertSame(WarehouseSelector::REASON_FOREIGN_WAREHOUSE, $plan['reason']);
    }

    /** An override naming an INACTIVE warehouse of one's own store is refused by its own reason. */
    public function test_an_override_to_an_inactive_warehouse_is_refused(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $closed = $this->makeWarehouse(self::STORE_A, 'Closed', ['priority' => 1, 'is_active' => 0]);

        WarehouseInventory::allocate(self::STORE_A, $closed, $product, 0, 10);

        $plan = WarehouseSelector::planFor(
            self::STORE_A,
            $product,
            0,
            4,
            $this->merchantWithOverridePermission(self::STORE_A),
            $closed
        );

        $this->assertSame(WarehouseSelector::OVERRIDE_REFUSED, $plan['basis']);
        $this->assertSame(WarehouseSelector::REASON_INACTIVE_WAREHOUSE, $plan['reason']);
    }

    /** An override to the store's DEFAULT warehouse is honoured and plans nothing — by design. */
    public function test_an_override_to_the_default_warehouse_plans_nothing(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $home = $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot', ['priority' => 1]);

        WarehouseInventory::allocate(self::STORE_A, $depot, $product, 0, 10);

        $plan = WarehouseSelector::planFor(
            self::STORE_A,
            $product,
            0,
            4,
            $this->merchantWithOverridePermission(self::STORE_A),
            $home
        );

        $this->assertSame(WarehouseSelector::OVERRIDDEN, $plan['basis']);
        $this->assertSame(
            [],
            $plan['lines'],
            'the default holds the remainder, so sourcing from it is the absence of an allocation '
            . 'write and not a line of zero units'
        );
    }

    /**
     * AN HONOURED OVERRIDE IS STILL CLAMPED at what the chosen warehouse holds.
     *
     * Added after mutation planning found nothing asserted it. An unclamped override would promise
     * more units than a depot has, and `sourceFrom()`'s own clamp would then silently take fewer
     * than the plan said — the invariant would hold but the plan would be a lie, and a caller
     * reading the plan to print a picking list would send somebody to the wrong building.
     */
    public function test_an_honoured_override_is_clamped_at_what_that_warehouse_holds(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $chosen = $this->makeWarehouse(self::STORE_A, 'Chosen', ['priority' => 1]);

        WarehouseInventory::allocate(self::STORE_A, $chosen, $product, 0, 2);

        $plan = WarehouseSelector::planFor(
            self::STORE_A,
            $product,
            0,
            9,
            $this->merchantWithOverridePermission(self::STORE_A),
            $chosen
        );

        $this->assertSame(WarehouseSelector::OVERRIDDEN, $plan['basis']);
        $this->assertSame(
            [['warehouse_id' => $chosen, 'units' => 2]],
            $plan['lines'],
            'clamped at the 2 it holds; the other 7 come from the default remainder'
        );
    }

    /** A zero or negative quantity plans nothing. */
    public function test_a_non_positive_quantity_plans_nothing(): void
    {
        $product = $this->makeProduct(self::STORE_A, 100);
        $this->makeWarehouse(self::STORE_A, 'Home', ['is_default' => 1]);
        $depot = $this->makeWarehouse(self::STORE_A, 'Depot', ['priority' => 1]);

        WarehouseInventory::allocate(self::STORE_A, $depot, $product, 0, 10);

        foreach ([0, -1, -99] as $quantity) {
            $this->assertSame(
                [],
                WarehouseSelector::planFor(self::STORE_A, $product, 0, $quantity)['lines'],
                "quantity $quantity must plan nothing"
            );
        }
    }

    // ---------------------------------------------------------------------------------------- //

    private function seededPermissionNames(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Models/Utility.php');

        preg_match_all('/"([^"]+)"/', $source, $matches);

        return $matches[1];
    }

    private function merchantWithOverridePermission(int $storeId): User
    {
        $user = User::create([
            'name' => 'Merchant',
            'email' => 'merchant-warehouse-' . $storeId . '@example.com',
            'password' => 'x',
            'type' => 'admin',
        ]);

        $role = Role::create([
            'name' => 'warehouse-role-' . $storeId,
            'guard_name' => 'web',
            'created_by' => $user->id,
            'store_id' => $storeId,
        ]);

        $permissionId = DB::table('permissions')->insertGetId([
            'name' => WarehouseSelector::OVERRIDE_PERMISSION,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permission_role')->insert([
            'permission_id' => $permissionId,
            'role_id' => $role->id,
        ]);

        DB::table('role_user')->insert([
            'role_id' => $role->id,
            'user_id' => $user->id,
            'user_type' => User::class,
        ]);

        return $user->fresh();
    }

    private function merchantWithoutPermissions(): User
    {
        return User::create([
            'name' => 'Powerless',
            'email' => 'powerless-warehouse@example.com',
            'password' => 'x',
            'type' => 'admin',
        ]);
    }

    private function makeWarehouse(int $storeId, string $name, array $attributes = []): int
    {
        return WarehouseInventory::createWarehouse($storeId, array_merge(['name' => $name], $attributes));
    }

    private function makeProduct(int $storeId, int $stock): int
    {
        return (int) DB::table('products')->insertGetId([
            'name' => 'Selector fixture',
            'track_stock' => 1,
            'stock_order_status' => 'not_allow',
            'product_stock' => $stock,
            'low_stock_threshold' => 0,
            'price' => 0,
            'sale_price' => 0,
            'variant_product' => 0,
            'store_id' => $storeId,
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
