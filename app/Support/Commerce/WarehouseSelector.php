<?php

namespace App\Support\Commerce;

use App\Services\MerchantPermissions;
use App\Services\WarehouseInventory;

/**
 * `INV-007` — AUTOMATIC FULFILMENT-WAREHOUSE SELECTION, WITH AN AUTHORISED OVERRIDE.
 *
 * The approved decision, verbatim from `docs/development/master-plan/DECISION-ID-REGISTER.md`
 * §04-B (المخزون والمستودعات):
 *
 *     INV-007  اختيار مستودع التنفيذ تلقائياً مع تعديل مصرح به.
 *              Automatic fulfilment-warehouse selection, with an authorised override
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE ANSWER IS A PLAN, AND THE DEFAULT WAREHOUSE IS NEVER IN IT
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * {@see planFor()} answers which of a store's **named** warehouses give up how many units. The
 * default never appears, because its figure is the derived remainder
 * (see {@see \App\Services\WarehouseInventory}): sourcing from the default is the ABSENCE of an
 * allocation write, not a write of zero. `$quantity − Σ(plan units)` is its implicit contribution.
 *
 * That is the property that makes the order-path integration additive. A store with no allocations
 * gets an empty plan, the caller writes nothing, and its checkout is byte-for-byte what it is today.
 *
 * **THIS IS NOT `INV-008`.** «توزيع التنفيذ على عدة مستودعات وشحنات جزئية.» / *split fulfilment
 * across warehouses, and partial shipments* needs a `shipments` entity — which does not exist
 * anywhere in the platform, measured across every host and vendored migration — and a partial
 * shipment changes what `orders.delivered_status` means, which is the subject of `P04-P4-03`'s
 * still-open owner question `H4`. A two-line plan here is BOOKKEEPING about where units came from.
 * It produces no shipment, no second delivery state and no change to any order's lifecycle, and it
 * must not be read as `INV-008` having been delivered.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE SELECTION RULE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Active named warehouses in `priority` order, then by id so the answer is deterministic. If any
 * ONE of them can cover the whole quantity, that one is chosen alone; otherwise the plan fills
 * greedily down the list. Preferring a single source is what a merchant picking stock wants, and it
 * makes the common case exactly one allocation write.
 *
 * An INACTIVE warehouse is skipped and KEEPS its allocation, so taking a location out of service
 * does not silently move its units into the default's remainder.
 *
 * **NOTHING HERE DECIDES WHETHER A SALE MAY PROCEED.** `ProductStockService`'s conditional
 * `UPDATE ... WHERE product_stock >= ?` is still the only oversell control, and it still names
 * `products.product_stock`. An empty plan means "no allocation to adjust", never "refuse". A
 * selector that could refuse would change oversell behaviour, which is a stock business rule and
 * CLAUDE.md §2 reserves it to the owner.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE OVERRIDE: THREE REFUSALS, EACH WITH ITS OWN REASON
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * A refusal carries its REASON rather than quietly returning the automatic plan. CLAUDE.md §3: a
 * refusal asserted without its reason is satisfied by any refusal — and these three mean different
 * things to whoever reads them. A foreign warehouse is an attempt to reach another tenant; a
 * missing permission is a question about one's own store; an inactive warehouse is neither.
 *
 * **ORDER IS LOAD-BEARING: OWNERSHIP IS ASKED BEFORE PERMISSION.** No permission held in store A
 * authorises reaching store B's warehouse, so asking the permission first would make an
 * authorised merchant's cross-tenant attempt answer `REASON_NOT_PERMITTED` — a true-sounding
 * refusal that misreports what happened, and one that would read as success the day somebody
 * widened the permission. The operator is admitted to the permission check and still refused a
 * foreign warehouse, which is the shape the tenancy skill records for an ownership rule: the
 * operator is admitted *before* an ownership rule is asked, so the shape check comes first.
 *
 * Authorisation is {@see MerchantPermissions::allows()}, **reused rather than copied**. That class
 * is operator-first — `isAbleTo()` ALONE REFUSES THE PLATFORM OPERATOR (CLAUDE.md §1) — and
 * carries the `instanceof User` guard whose absence once turned 112 storefront tests into 500s,
 * because a `Customer` and a `DeliveryBoy` are not Laratrust subjects and both reach handlers
 * through `custom.auth`. Writing the check again here would have reproduced both defects.
 *
 * `Manage Orders` is the permission, and it is one `Utility::addNewData()` really seeds — asserted
 * as a premise by `WarehouseSelectorTest`, because a permission no row exists for can be held by no
 * role and gating on one is a permanent refusal.
 */
final class WarehouseSelector
{
    /** The permission an override needs. Seeded by `Utility::addNewData()`. */
    public const OVERRIDE_PERMISSION = 'Manage Orders';

    /** The automatic choice stood. */
    public const AUTOMATIC = 'automatic';

    /** A caller's choice was honoured. */
    public const OVERRIDDEN = 'overridden';

    /** A caller's choice was refused; `reason` says which rule, and the automatic plan stands. */
    public const OVERRIDE_REFUSED = 'override_refused';

    /** This store has no named warehouse holding units of this line. The default sources it. */
    public const NONE = 'none';

    public const REASON_NOT_PERMITTED = 'not_permitted';
    public const REASON_FOREIGN_WAREHOUSE = 'foreign_warehouse';
    public const REASON_INACTIVE_WAREHOUSE = 'inactive_warehouse';

    /**
     * Which of this store's named warehouses should give up how many units.
     *
     * @param  int       $storeId    derived from an authenticated principal or from the product
     *                               row itself — NEVER from request input (CLAUDE.md §1: a tenant
     *                               predicate built from caller-supplied input is not a tenant
     *                               check, and has produced four separate defects here).
     * @param  mixed     $user       the principal asking for an override, or null.
     * @param  int|null  $overrideId the warehouse they asked for, or null for the automatic choice.
     * @return array{lines: list<array{warehouse_id:int, units:int}>, basis: string, reason: ?string}
     */
    public static function planFor(
        int $storeId,
        int $productId,
        int $variantId = WarehouseInventory::NO_VARIANT,
        int $quantity = 0,
        $user = null,
        ?int $overrideId = null
    ): array {
        $automatic = self::automaticLines($storeId, $productId, $variantId, $quantity);

        if ($overrideId === null) {
            return [
                'lines' => $automatic,
                'basis' => $automatic === [] ? self::NONE : self::AUTOMATIC,
                'reason' => null,
            ];
        }

        $refusal = self::refusalForOverride($storeId, $overrideId, $user);

        if ($refusal !== null) {
            return [
                'lines' => $automatic,
                'basis' => self::OVERRIDE_REFUSED,
                'reason' => $refusal,
            ];
        }

        return [
            'lines' => self::overrideLines($storeId, $overrideId, $productId, $variantId, $quantity),
            'basis' => self::OVERRIDDEN,
            'reason' => null,
        ];
    }

    /**
     * Why this override may not be honoured, or null when it may.
     *
     * Ownership first — see the class docblock. The operator reaches the permission limb and is
     * still refused a warehouse their store does not own.
     */
    private static function refusalForOverride(int $storeId, int $overrideId, $user): ?string
    {
        if (! WarehouseInventory::ownsWarehouse($storeId, $overrideId)) {
            return self::REASON_FOREIGN_WAREHOUSE;
        }

        if (! MerchantPermissions::allows($user, self::OVERRIDE_PERMISSION, $storeId)) {
            return self::REASON_NOT_PERMITTED;
        }

        $warehouse = self::warehouse($storeId, $overrideId);

        if ($warehouse === null || ! $warehouse['is_active']) {
            return self::REASON_INACTIVE_WAREHOUSE;
        }

        return null;
    }

    /**
     * The automatic plan: a single source when one can cover the line, else a greedy fill.
     *
     * @return list<array{warehouse_id:int, units:int}>
     */
    private static function automaticLines(
        int $storeId,
        int $productId,
        int $variantId,
        int $quantity
    ): array {
        if ($quantity <= 0) {
            return [];
        }

        $candidates = self::candidates($storeId, $productId, $variantId);

        if ($candidates === []) {
            return [];
        }

        /* A SINGLE SOURCE IS PREFERRED. The candidates are already in priority order, so the first
           that covers the whole line is the highest-priority one that can. */
        foreach ($candidates as $warehouseId => $held) {
            if ($held >= $quantity) {
                return [['warehouse_id' => $warehouseId, 'units' => $quantity]];
            }
        }

        $lines = [];
        $remaining = $quantity;

        foreach ($candidates as $warehouseId => $held) {
            $take = min($remaining, $held);

            if ($take <= 0) {
                continue;
            }

            $lines[] = ['warehouse_id' => $warehouseId, 'units' => $take];
            $remaining -= $take;

            if ($remaining <= 0) {
                break;
            }
        }

        /* $remaining > 0 here is NOT a shortfall and NOT a refusal: those units come from the
           default's remainder, which needs no plan line. See the class docblock. */
        return $lines;
    }

    /**
     * The plan for an honoured override: that warehouse gives what it holds, up to the quantity.
     *
     * The DEFAULT warehouse yields an EMPTY plan rather than a line of zero, because its figure is
     * derived — "source from the default" means "write no allocation".
     *
     * @return list<array{warehouse_id:int, units:int}>
     */
    private static function overrideLines(
        int $storeId,
        int $overrideId,
        int $productId,
        int $variantId,
        int $quantity
    ): array {
        if ($quantity <= 0) {
            return [];
        }

        $warehouse = self::warehouse($storeId, $overrideId);

        if ($warehouse === null || $warehouse['is_default']) {
            return [];
        }

        $held = WarehouseInventory::allocationsFor($storeId, $productId, $variantId)[$overrideId] ?? 0;
        $take = min($quantity, WarehouseInventory::availableUnits($held));

        return $take > 0 ? [['warehouse_id' => $overrideId, 'units' => $take]] : [];
    }

    /**
     * The store's ACTIVE, NON-DEFAULT warehouses that hold units of this line, in priority order.
     *
     * @return array<int,int> warehouse id => units held, ordered
     */
    private static function candidates(int $storeId, int $productId, int $variantId): array
    {
        $allocations = WarehouseInventory::allocationsFor($storeId, $productId, $variantId);

        if ($allocations === []) {
            return [];
        }

        $candidates = [];

        /* `warehousesFor()` is already ordered by priority then id, which is where the determinism
           comes from. Rebuilding that order here would be a second definition of it. */
        foreach (WarehouseInventory::warehousesFor($storeId) as $warehouse) {
            if ($warehouse['is_default'] || ! $warehouse['is_active']) {
                continue;
            }

            $held = WarehouseInventory::availableUnits($allocations[$warehouse['id']] ?? 0);

            if ($held > 0) {
                $candidates[$warehouse['id']] = $held;
            }
        }

        return $candidates;
    }

    /** One of this store's warehouses, or null. The store predicate is in `warehousesFor()`. */
    private static function warehouse(int $storeId, int $warehouseId): ?array
    {
        foreach (WarehouseInventory::warehousesFor($storeId) as $warehouse) {
            if ($warehouse['id'] === $warehouseId) {
                return $warehouse;
            }
        }

        return null;
    }
}
