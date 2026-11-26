<?php

namespace App\Support\Money;

use App\Models\Utility;
use Illuminate\Support\Facades\DB;

/**
 * P5-R14 — WHICH CURRENCY AN ORDER IS STAMPED WITH, DECIDED IN ONE PLACE.
 * =================================================================================================
 *
 * `orders.currency_code` and `orders.currency_symbol` are a SNAPSHOT: what the store traded in
 * when the order was placed. Every per-currency money figure groups by the code, and
 * `MetricResult::total()` refuses a result that holds more than one — so two writers disagreeing
 * about one store's currency does not produce a slightly wrong total, it produces two totals or
 * none.
 *
 * They disagreed. The model's `creating` hook, `OrderController` and the admin API read the
 * store's `defult_currancy` — the CODE. `Api\ApiController::place_order()` and `::ordersave()`
 * read `CURRENCY`, which `Utility::Setting()` ships as `'$'`: the SYMBOL. Measured 2026-11-22, a
 * store trading in Syrian pounds had its storefront-API orders recorded as `$`
 * (`OrderCurrencyCodeAgreesAcrossWritersTest`). The symbol column had the mirror-image fault: those
 * two read a setting named `currency_symbol`, which nothing writes.
 *
 * THIS IS THE MODEL HOOK'S OWN CHAIN, MOVED HERE UNCHANGED — the same settings, in the same
 * order, with the same operators — so that the hook's behaviour does not move by one character
 * and the two outliers come to agree with it rather than with a third rule.
 *
 * `??` AND NOT `?:`, DELIBERATELY KEPT. A setting row that exists and holds an empty string is
 * returned as the empty string, exactly as the hook returned it. Treating that as "unset" would be
 * a more defensible rule and is a DIFFERENT change: it would alter what three already-agreeing
 * writers record, in the one direction this fix exists to prevent — writers drifting apart.
 *
 * NOT CLEANED HERE, because it already is: `Utility::GetValueByName()` passes every currency
 * setting through `CurrencySymbol::cleanSetting()` where it is read (P1-SEC-80).
 *
 * WHAT THIS DOES NOT DO: touch an order that is already written. Rewriting a recorded currency is a
 * change to historical financial data, and that is the owner's decision (`D-N34`).
 */
final class OrderCurrencyStamp
{
    /** The product-wide fallbacks the model hook has always used. */
    public const DEFAULT_CODE = 'USD';

    public const DEFAULT_SYMBOL = '$';

    /**
     * The code to STAMP on a new order of this store.
     *
     * UNCHANGED, AND DELIBERATELY NOT ROUTED THROUGH `configuredCodeFor()` BELOW. The review of
     * ANA-TOP part 3 measured why: `Utility::GetValueByName($name, $storeId)` falls through on a
     * miss to `Utility::Setting()`, which IGNORES its store-id argument whenever `Auth::check()`
     * is true and reads `getCurrentStore()` instead (`Utility.php:139-149`). For the three writers
     * that call this with their OWN store that is harmless -- the fallback resolves the same
     * store. Changing it would change which currency future orders are stamped with, which is a
     * business rule and a change to recorded financial data, so it is recorded as a finding
     * rather than altered here, with its reachability, as `GAP-230` in
     * `docs/development/MASTER-PLAN-GAP-REGISTER.md`.
     *
     * @param int|string|null $storeId null lets `GetValueByName()` resolve the request's store, as the hook did
     */
    public static function codeFor($storeId): string
    {
        return (string) (Utility::GetValueByName('defult_currancy', $storeId)
            ?? Utility::GetValueByName('currency', $storeId)
            ?? self::DEFAULT_CODE);
    }

    /**
     * The code a NAMED store is configured to trade in, or null when nothing is -- READ WITH AN
     * EXPLICIT TENANT PREDICATE, which is the whole point of this method existing.
     *
     * WHY IT IS RAW AND NOT `GetValueByName()`. This repository has already measured the damage
     * and already chose this answer once: `DocumentDirection::ownRow()` reads a named store's
     * `settings` row with a raw scoped query, and the census note at
     * `RawQueryTenancyInventoryTest` records the reason verbatim -- `GetValueByName` "falls
     * through on a miss to `Utility::Setting()`, which IGNORES its store id argument whenever
     * Auth::check() is true and reads the VIEWER's store, so an order document took the reader's
     * logo and direction for any store with no row of its own". The first draft of this method
     * used `GetValueByName` and so would have taken the VIEWER's currency for any reported store
     * with no row of its own -- the identical defect, on a money label. Latent rather than live,
     * because the one caller happens to pass `getCurrentStore()`; written correctly anyway,
     * because "safe only while nobody adds a second caller" is not a property worth shipping.
     *
     * THE CHAIN IS `codeFor()`'s CHAIN: `defult_currancy`, then `currency`. That second key is
     * the defect part 3 set out to fix -- the previous reader looked at only the first, so a store
     * configured under the fallback was called unconfigured and its ranking was denominated in
     * whichever currency happened to be busiest.
     *
     * NEWEST ROW, NOT OLDEST. `settings` has no unique index and really does carry duplicate
     * `(name, store_id)` pairs (`Setting::putForStore()` documents the population P1-PLT-07
     * created). Every other reader takes the newest, because `pluck('value','name')` lets a later
     * key overwrite an earlier one; a bare `->value('value')` scans ascending and takes the
     * OLDEST. Part 1's query did exactly that, so on a duplicated store this label could disagree
     * with every other screen. `orderByDesc('id')` makes it agree.
     *
     * REFUSES A STORE ID OF 0 OR LESS, and that early return is BELT-AND-BRACES rather than the
     * control -- a mutation deleting it survives, measured. The control is the predicate: with an
     * explicit `where('store_id', 0)` no row matches and the answer is null anyway. The guard
     * exists because the first draft of this method used `GetValueByName`, where `empty(0)` is
     * true and 0 resolved the session's store or ran with no predicate at all, and because
     * `OperatorStoreSelection::NO_STORE` is literally `0` -- so a future caller WILL pass it, and
     * an explicit refusal is clearer to read than a predicate that happens to match nothing.
     * `OrderCurrencyStampConfiguredReaderTest` pins the BEHAVIOUR (null for 0 and for a negative),
     * which is what a caller depends on; it cannot pin the guard, and says so.
     */
    public static function configuredCodeFor(int $storeId): ?string
    {
        if ($storeId <= 0) {
            return null;
        }

        foreach (['defult_currancy', 'currency'] as $name) {
            $value = DB::table('settings')
                ->where('store_id', $storeId)
                ->where('name', $name)
                ->orderByDesc('id')
                ->value('value');

            if ($value !== null) {
                /* The sanitisation `GetValueByName()` applies at the point of read (P1-SEC-80).
                   Both keys are in `CurrencySymbol::SETTING_NAMES`, so this is the same cleaning
                   the helper would have done. */
                return (string) CurrencySymbol::cleanSetting($name, $value);
            }
        }

        return null;
    }

    /**
     * UNCHANGED, AND IT MUST STAY SO -- the FIVE order writers stamp `orders.currency_symbol` from
     * here: this class's own model hook (`Order.php:182`), `Api\ApiController::place_order` and
     * `::ordersave`, `Api\DashboardController::createOrderViaApi` and `OrderController`'s manual
     * order. A stamped currency is recorded financial data and rewriting it is the owner's decision
     * (`D-N34`), so a reader that needed a tenant predicate got its OWN method below rather than a
     * correction here. It carries `GAP-230`'s fall-through exactly as `codeFor()` does, for the
     * same reason and with the same reachability.
     *
     * @param int|string|null $storeId
     */
    public static function symbolFor($storeId): string
    {
        return (string) (Utility::GetValueByName('defult_currancy_symbol', $storeId)
            ?? Utility::GetValueByName('currency_symbol', $storeId)
            ?? self::DEFAULT_SYMBOL);
    }

    /**
     * The symbol a NAMED store is configured to price in, or null when none is -- READ WITH AN
     * EXPLICIT TENANT PREDICATE.  GAP-278 / `P01-B2`.
     *
     * `configuredCodeFor()`'s TWIN, deliberately, down to the guard and the ordering: same shape,
     * same reasons, different pair of setting names. The two must not drift, because they answer
     * the two halves of one question about one store.
     *
     * WHAT IT FIXES, MEASURED BOTH WAYS BEFORE IT EXISTED. With no symbol row of its own, a store's
     * `get-tax-data` and `get-shipping-data` reported the **NEIGHBOUR MERCHANT'S** symbol to a
     * viewer holding a panel session for that neighbour: `Utility::GetValueByName($name, $storeId)`
     * reads the named store's rows but falls through on a MISS to `Utility::Setting($storeId)`,
     * which IGNORES its store-id argument whenever `Auth::check()` is true and reads
     * `getCurrentStore()` -- the VIEWER's store (`Utility.php:138-150`). Storefront routes sit in
     * the `web` group with a session cookie path of `/`, so a merchant with a live panel login is a
     * populated web-guard principal on every storefront request. Severity is a MISLABELLED PRICE
     * for one viewer rather than a disclosure -- a store's symbol is printed on every price of its
     * public storefront -- but a money label that answers for whoever is reading it is wrong in a
     * way that compounds, and this is the third surface in this repository to need the same remedy
     * (`DocumentDirection::ownRow()` was the first, `configuredCodeFor()` the second).
     *
     * THE CHAIN IS `symbolFor()`'s CHAIN: `defult_currancy_symbol`, then `currency_symbol`. The
     * first is what the merchant's own settings screen writes; nothing shipped writes the second,
     * which is why it is the fallback and not the primary -- but it is walked, because dropping a
     * link would call a store configured under it unconfigured.
     *
     * NEWEST ROW, NOT OLDEST. `settings` has no unique index and really does carry duplicate
     * `(name, store_id)` pairs (`Setting::putForStore()` documents the population P1-PLT-07
     * created). Every other reader takes the newest, because `pluck('value','name')` lets a later
     * key overwrite an earlier one; a bare `->value('value')` scans ascending and takes the OLDEST,
     * so without `orderByDesc('id')` one store's prices would carry two different symbols.
     *
     * CLEANED HERE, which `configuredCodeFor()`'s note also records but which matters more for a
     * symbol. `Utility::GetValueByName()` passes every currency setting through
     * `CurrencySymbol::cleanSetting()` at the point of read (P1-SEC-80 round 4), and bypassing the
     * helper bypasses that: the currency tab stored whatever stood before the first `-` of its
     * posted value, so a `Manage Setting` holder stored MARKUP, and the four
     * `*_currency_format_with_sym` helpers prefix the stored symbol onto amounts that print raw.
     * Both names are in `CurrencySymbol::SETTING_NAMES`, so this is the SAME cleaning the helper
     * would have applied and not a second rule.
     *
     * REFUSES A STORE ID OF 0 OR LESS, and that early return is BELT-AND-BRACES rather than the
     * control -- the control is the predicate, and with `where('store_id', 0)` no row matches and
     * the answer is null anyway. It exists because `empty(0)` is true in the helper this replaces,
     * and because `OperatorStoreSelection::NO_STORE` is literally `0`, so a future caller WILL pass
     * it. `Gap278ConfiguredSymbolReaderTest` pins the BEHAVIOUR (null for 0 and for a negative),
     * which is what a caller depends on; the guard itself is not separately observable and that
     * file says so.
     *
     * AN EMPTY STRING IS RETURNED, NOT TREATED AS UNSET -- `!== null`, matching
     * `configuredCodeFor()` and `symbolFor()`'s `??`. Each caller states its own default where that
     * default is applied, which is `DocumentDirection::ownRow()`'s rule too.
     */
    public static function configuredSymbolFor(int $storeId): ?string
    {
        if ($storeId <= 0) {
            return null;
        }

        foreach (['defult_currancy_symbol', 'currency_symbol'] as $name) {
            $value = DB::table('settings')
                ->where('store_id', $storeId)
                ->where('name', $name)
                ->orderByDesc('id')
                ->value('value');

            if ($value !== null) {
                return (string) CurrencySymbol::cleanSetting($name, $value);
            }
        }

        return null;
    }
}
